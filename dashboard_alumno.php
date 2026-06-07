<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/enviar_correo.php';
require_once __DIR__ . '/sincronizar_estados.php';

// ── Cerrar sesión ────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// ── Proteger ruta ────────────────────────────────────────────────
if (empty($_SESSION['numero_cuenta'])) {
    header('Location: login.php');
    exit;
}

sincronizarEstadosClubes();

// ── Datos del alumno (desde sesión) ─────────────────────────────
$alumno = [
    'nombre'     => $_SESSION['nombre']        ?? 'ALUMNO',
    'cuenta'     => $_SESSION['numero_cuenta'] ?? '',
    'correo'     => $_SESSION['correo']        ?? '',
    'plantel'    => $_SESSION['plantel']       ?? '',
    'id_plantel' => $_SESSION['id_plantel']    ?? null,
    'ciclo'      => 'FEB-2026/AGO-2026',
    'carrera'    => '',
    'semestre'   => '',
    'grupo'      => '',
    'curp'       => '',
];

// ── Cargar clubs desde BD ────────────────────────────────────────
$clubs    = [];
$db_error = false;

try {
    $pdo = getDB();

    // IDs de clubs en los que ya está inscrito este alumno
    $ya_inscrito_ids = [];
    $chk_ids = $pdo->prepare(
        "SELECT id_club FROM inscripciones_club WHERE numero_cuenta = ?"
    );
    $chk_ids->execute([(int)$alumno['cuenta']]);
    $ya_inscrito_ids = array_column($chk_ids->fetchAll(), 'id_club');

    // Clubs del plantel disponibles (excluye los ya inscritos)
    $placeholders = !empty($ya_inscrito_ids)
        ? 'AND c.id NOT IN (' . implode(',', array_fill(0, count($ya_inscrito_ids), '?')) . ')'
        : '';

    $sql = "
        SELECT
            c.id,
            c.nombre,
            c.descripcion,
            c.estado,
            c.limite,
            GROUP_CONCAT(DISTINCT h.dia ORDER BY h.id SEPARATOR ' / ') AS dias,
            MIN(h.hora_inicio) AS hora_inicio,
            MAX(h.hora_fin)    AS hora_fin,
            COUNT(DISTINCT ic.numero_cuenta) AS inscritos,
            COALESCE(
                CONCAT(enc.nombres, ' ', enc.apellido_paterno),
                'Sin asignar'
            ) AS encargado
        FROM clubes c
        LEFT JOIN planteles pl           ON pl.id      = c.id_plantel
        LEFT JOIN horarios h             ON h.id_club  = c.id
        LEFT JOIN inscripciones_club ic  ON ic.id_club = c.id
        LEFT JOIN personas enc           ON enc.id     = c.id_encargado
        WHERE pl.nombre = ?
          AND c.estado IN ('apertura', 'iniciado')
          AND (
              c.estado = 'iniciado'
              OR c.fecha_limite_registro IS NULL
              OR c.fecha_limite_registro >= CURDATE()
          )
          $placeholders
        GROUP BY c.id
        ORDER BY c.estado = 'apertura' DESC, c.nombre
    ";

    $stmt   = $pdo->prepare($sql);
    $params = array_merge([$alumno['plantel']], $ya_inscrito_ids);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $hi = substr($r['hora_inicio'] ?? '00:00:00', 0, 5);
        $hf = substr($r['hora_fin']   ?? '00:00:00', 0, 5);
        $clubs[] = [
            'id'         => $r['id'],
            'nombre'     => $r['nombre'],
            'estado'     => $r['estado'],
            'dias'       => $r['dias'] ?? '—',
            'horario'    => $hi . ' – ' . $hf,
            'cupo_total' => (int)$r['limite'],
            'inscritos'  => (int)$r['inscritos'],
            'encargado'  => $r['encargado'],
        ];
    }
} catch (Exception $e) {
    $db_error = true;
    error_log('dashboard_alumno clubs query: ' . $e->getMessage());
}

// ── Solicitud de registro a un club ─────────────────────────────
$msg_ok  = '';
$msg_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_club'])) {

    $club_id  = (int)($_POST['club_id'] ?? 0);
    $club_sel = null;
    // Buscar también en la lista completa (no solo en disponibles, por seguridad)
    foreach ($clubs as $c) {
        if ((int)$c['id'] === $club_id) { $club_sel = $c; break; }
    }

    // Verificar si ya está inscrito en ESTE club específico
    $ya_en_este = false;
    try {
        $chkE = getDB()->prepare(
            "SELECT 1 FROM inscripciones_club WHERE numero_cuenta = ? AND id_club = ? LIMIT 1"
        );
        $chkE->execute([(int)$alumno['cuenta'], $club_id]);
        $ya_en_este = (bool)$chkE->fetchColumn();
    } catch (Exception $e) {}

    if ($ya_en_este) {
        $msg_err = 'Ya estás inscrito en ese club.';

    } else {

        if (!$club_sel) {
            $msg_err = 'Club no encontrado.';

        } elseif ($club_sel['inscritos'] >= $club_sel['cupo_total']) {
            $msg_err = 'El club "' . htmlspecialchars($club_sel['nombre']) . '" ya no tiene cupo.';

        } else {
            // Generar token único (32 bytes hex = 64 caracteres)
            $token     = bin2hex(random_bytes(32));
            $expira_en = date('Y-m-d H:i:s', strtotime('+24 hours'));

            // Guardar token en BD
            $token_guardado = false;
            try {
                $pdo  = getDB();

                // Eliminar tokens anteriores no usados de este alumno para este club
                $pdo->prepare(
                    'DELETE FROM tokens_pendientes WHERE numero_cuenta = ? AND id_club = ? AND usado = 0'
                )->execute([(int)$alumno['cuenta'], $club_id]);

                $ins = $pdo->prepare("
                    INSERT INTO tokens_pendientes
                        (token, numero_cuenta, nombre_completo, correo, plantel_nombre, id_plantel, id_club, expira_en)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $token,
                    (int)$alumno['cuenta'],
                    $alumno['nombre'],
                    $alumno['correo'],
                    $alumno['plantel'],
                    $alumno['id_plantel'],
                    $club_id,
                    $expira_en,
                ]);
                $token_guardado = true;

            } catch (Exception $e) {
                // Si BD no disponible intentamos enviar de todas formas (MAIL_DEMO)
                $token_guardado = MAIL_DEMO;
            }

            if ($token_guardado) {
                $link = BASE_URL . '/confirmar_registro.php?token=' . $token;

                // ── Obtener credenciales SMTP del plantel del club ──
                $smtp_plantel = [];
                try {
                    $sp = getDB()->prepare(
                        'SELECT pl.correo, pl.contrasena_app
                         FROM clubes c
                         JOIN planteles pl ON pl.id = c.id_plantel
                         WHERE c.id = ?'
                    );
                    $sp->execute([$club_id]);
                    $smtp_plantel = $sp->fetch() ?: [];
                } catch (Exception $e) { /* Usar credenciales globales */ }

                $enviado = enviarCorreoConfirmacion([
                    'correo'            => $alumno['correo'],
                    'nombre_alumno'     => $alumno['nombre'],
                    'numero_cuenta'     => $alumno['cuenta'],
                    'nombre_club'       => $club_sel['nombre'],
                    'dias_club'         => $club_sel['dias'],
                    'horario_club'      => $club_sel['horario'],
                    'plantel'           => $alumno['plantel'],
                    'encargado'         => $club_sel['encargado'],
                    'ciclo_escolar'     => $alumno['ciclo'],
                    'link_confirmacion' => $link,
                ], $smtp_plantel);

                if ($enviado) {
                    $destino = htmlspecialchars($alumno['correo']);
                    if (MAIL_DEMO) {
                        $msg_ok = 'Modo demo activo. El enlace de confirmación se guardó en <code>logs/correos_demo.log</code>.<br>'
                            . 'Enlace directo: <a href="' . htmlspecialchars($link) . '" target="_blank">'
                            . htmlspecialchars($link) . '</a>';
                    } else {
                        $msg_ok = 'Correo de confirmación enviado a <strong>' . $destino . '</strong>.'
                            . ' Revisa tu bandeja y haz clic en el enlace para completar tu registro.';
                    }
                } else {
                    // Leer el último error del log para mostrar diagnóstico
                    $logFile = __DIR__ . '/logs/mail_errores.log';
                    $ultimoError = '';
                    if (file_exists($logFile)) {
                        $lineas = array_filter(explode("\n", file_get_contents($logFile)));
                        $lineas = array_reverse(array_values($lineas));
                        foreach ($lineas as $l) {
                            if (strpos($l, '❌') !== false) { $ultimoError = $l; break; }
                        }
                    }
                    $msg_err = 'No se pudo enviar el correo de confirmación.'
                        . ($ultimoError ? ' <br><small style="opacity:.75">Detalle: ' . htmlspecialchars($ultimoError) . '</small>' : '')
                        . ' <br>Revisa <code>logs/mail_errores.log</code> para más información.';
                }
            } else {
                $msg_err = 'Error interno al procesar tu solicitud. Intenta de nuevo.';
            }
        }
    }
}

// Iniciales del nombre para el avatar
$partes   = explode(' ', $alumno['nombre']);
$iniciales = '';
foreach (array_slice($partes, 0, 2) as $p) {
    $iniciales .= mb_substr($p, 0, 1);
}

// ── Cargar todos los clubs en los que está inscrito el alumno ────────
$mis_clubs = [];
$dias_map  = ['Domingo'=>0,'Lunes'=>1,'Martes'=>2,'Miércoles'=>3,
               'Jueves'=>4,'Viernes'=>5,'Sábado'=>6];
$meses_es  = ['','Ene','Feb','Mar','Abr','May','Jun',
               'Jul','Ago','Sep','Oct','Nov','Dic'];

try {
    $pdo = getDB();

    // Clubs inscritos vía inscripciones_club
    $stmt = $pdo->prepare("
        SELECT c.id, c.nombre, c.descripcion, c.fecha_inicio, c.fecha_fin,
               c.limite, c.estado,
               COUNT(DISTINCT ic2.numero_cuenta) AS inscritos,
               COALESCE(CONCAT(p.nombres,' ',p.apellido_paterno),'Sin asignar') AS encargado
        FROM inscripciones_club ic
        JOIN clubes c         ON c.id       = ic.id_club
        LEFT JOIN inscripciones_club ic2 ON ic2.id_club = c.id
        LEFT JOIN personas p  ON p.id       = c.id_encargado
        WHERE ic.numero_cuenta = ?
        GROUP BY c.id
        ORDER BY c.nombre
    ");
    $stmt->execute([(int)$alumno['cuenta']]);
    $mis_clubs = $stmt->fetchAll();

    foreach ($mis_clubs as &$mc) {
        // Horarios
        $h = $pdo->prepare("
            SELECT dia, TIME_FORMAT(hora_inicio,'%H:%i') AS ini,
                   TIME_FORMAT(hora_fin,'%H:%i')         AS fin
            FROM horarios WHERE id_club = ?
            ORDER BY FIELD(dia,'Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'), hora_inicio
        ");
        $h->execute([$mc['id']]);
        $mc['horarios'] = $h->fetchAll();

        // Asistencias del alumno en este club
        try {
            $a = $pdo->prepare("
                SELECT ac.fecha,
                       TIME_FORMAT(ac.hora_entrada,'%H:%i') AS hora_entrada,
                       TIME_FORMAT(ac.hora_salida, '%H:%i') AS hora_salida,
                       ac.estado
                FROM asistencias_club ac
                JOIN estudiantes est ON est.id = ac.id_estudiante
                WHERE ac.id_club = ? AND est.cuenta = ?
                ORDER BY ac.fecha DESC
                LIMIT 10
            ");
            $a->execute([$mc['id'], (int)$alumno['cuenta']]);
            $mc['asistencias'] = $a->fetchAll();
        } catch (Exception $e) { $mc['asistencias'] = []; }

        // Próximas sesiones (máx. 4)
        $proximas = [];
        if (!empty($mc['horarios']) && !empty($mc['fecha_fin'])) {
            $fin_club = new DateTime($mc['fecha_fin']);
            $iter     = new DateTime('today');
            for ($d = 0; $d < 90 && count($proximas) < 4; $d++) {
                $dow = (int)$iter->format('w');
                foreach ($mc['horarios'] as $hor) {
                    if (isset($dias_map[$hor['dia']]) && $dias_map[$hor['dia']] === $dow
                        && $iter <= $fin_club) {
                        $proximas[] = [
                            'fecha' => clone $iter,
                            'dia'   => $hor['dia'],
                            'ini'   => $hor['ini'],
                            'fin'   => $hor['fin'],
                            'mes'   => $meses_es[(int)$iter->format('n')],
                        ];
                    }
                }
                $iter->modify('+1 day');
            }
        }
        $mc['proximas'] = $proximas;
    }
    unset($mc);

} catch (Exception $e) { /* silently fail */ }

// Actualizar sesión (para compatibilidad)
$_SESSION['ya_inscrito'] = !empty($mis_clubs);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver Clubes — Bachillerato 23</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:        #1b2d54;
            --navy-light:  #243567;
            --accent:      #4a7fd4;
            --accent-h:    #3568bf;
            --success:     #2e9e6e;
            --white:       #ffffff;
            --gray-50:     #f7f8fc;
            --gray-100:    #eef0f6;
            --gray-200:    #e0e4f0;
            --gray-300:    #c5cad8;
            --gray-500:    #7a8099;
            --gray-700:    #3d4260;
            --text:        #1e2340;
            --error:       #d94f4f;
            --radius:      14px;
            --radius-sm:   8px;
            --shadow:      0 4px 20px rgba(27,45,84,.09);
            --shadow-lg:   0 10px 36px rgba(27,45,84,.14);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--gray-50); min-height: 100vh; color: var(--text); }

        /* HEADER */
        header {
            background: var(--navy); height: 64px;
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 2rem; box-shadow: 0 2px 10px rgba(0,0,0,.25);
            position: sticky; top: 0; z-index: 50;
        }
        .hb { display: flex; align-items: center; gap: .75rem; }
        .hb-logo { width:40px; height:40px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-family:'Outfit',sans-serif; font-weight:700; font-size:.75rem; color:var(--navy); }
        .hb-name { font-family:'Outfit',sans-serif; font-size:1.05rem; font-weight:600; color:#fff; }
        .hb-sub  { font-size:.7rem; color:rgba(255,255,255,.5); text-transform:uppercase; letter-spacing:.8px; }
        nav { display:flex; align-items:center; gap:.2rem; }
        nav a { color:rgba(255,255,255,.75); text-decoration:none; font-size:.82rem; font-weight:500; padding:.4rem .75rem; border-radius:var(--radius-sm); display:flex; align-items:center; gap:.35rem; transition:all .2s; }
        nav a:hover, nav a.active { color:#fff; background:rgba(255,255,255,.12); }
        .nav-out { color:rgba(255,255,255,.65)!important; background:none!important; border:1px solid rgba(255,255,255,.2)!important; margin-left:.5rem; }
        .nav-out:hover { background:rgba(255,255,255,.1)!important; border-color:rgba(255,255,255,.35)!important; }

        /* SUBHEADER */
        .subhdr { background:var(--navy-light); padding:.6rem 2rem; display:flex; align-items:center; gap:.75rem; }
        .sub-av { width:34px; height:34px; border-radius:50%; background:var(--accent); display:flex; align-items:center; justify-content:center; font-family:'Outfit',sans-serif; font-weight:700; font-size:.75rem; color:#fff; }
        .sub-name { font-family:'Outfit',sans-serif; font-weight:600; font-size:.9rem; color:#fff; }
        .sub-det  { font-size:.72rem; color:rgba(255,255,255,.6); }

        /* PAGE */
        .page { max-width:1060px; margin:0 auto; padding:2rem 1.5rem 3.5rem; }

        /* SECTION LABEL */
        .sec-lbl { font-size:.68rem; font-weight:600; letter-spacing:1px; text-transform:uppercase; color:var(--gray-500); display:flex; align-items:center; gap:.4rem; margin-bottom:.85rem; }
        .sec-lbl::after { content:''; flex:1; height:1px; background:var(--gray-200); }

        /* FICHA */
        .ficha { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); overflow:hidden; margin-bottom:2rem; animation:fadeUp .35s ease both; }
        .ficha-top { background:linear-gradient(135deg,var(--navy) 0%,var(--navy-light) 100%); padding:1.5rem 2rem; display:flex; align-items:center; gap:1.25rem; position:relative; overflow:hidden; }
        .ficha-top::after { content:''; position:absolute; right:-20px; bottom:-50px; width:170px; height:170px; border-radius:50%; background:rgba(255,255,255,.05); }
        .ficha-av { width:68px; height:68px; border-radius:50%; background:var(--accent); border:3px solid rgba(255,255,255,.3); display:flex; align-items:center; justify-content:center; font-family:'Outfit',sans-serif; font-weight:700; font-size:1.4rem; color:#fff; flex-shrink:0; position:relative; z-index:1; }
        .ficha-ti { position:relative; z-index:1; }
        .ficha-ti h2 { font-family:'Outfit',sans-serif; font-weight:700; font-size:1.1rem; color:#fff; line-height:1.3; }
        .badge-cta { display:inline-flex; align-items:center; gap:.35rem; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25); border-radius:20px; padding:.22rem .75rem; font-size:.74rem; color:rgba(255,255,255,.9); font-family:'Outfit',sans-serif; font-weight:500; margin-top:.45rem; }
        .ficha-bd { padding:1.4rem 2rem 1.3rem; display:grid; grid-template-columns:repeat(3,1fr); gap:1rem 1.5rem; }
        .fi-lbl { font-size:.67rem; font-weight:600; text-transform:uppercase; letter-spacing:.7px; color:var(--gray-500); display:flex; align-items:center; gap:.3rem; margin-bottom:.25rem; }
        .fi-val { font-family:'Outfit',sans-serif; font-size:.88rem; font-weight:600; }
        .fi-val.sm { font-size:.78rem; font-weight:500; }
        .ficha-ft { padding:0 2rem 1.25rem; display:flex; gap:.6rem; flex-wrap:wrap; }
        .chip { display:inline-flex; align-items:center; gap:.3rem; background:var(--gray-50); border:1px solid var(--gray-200); border-radius:20px; padding:.28rem .75rem; font-size:.74rem; color:var(--gray-700); font-weight:500; }

        /* ALERTS */
        .alert { border-radius:var(--radius-sm); padding:.9rem 1.1rem; font-size:.83rem; display:flex; align-items:flex-start; gap:.6rem; margin-bottom:1.25rem; animation:fadeUp .3s ease both; line-height:1.5; }
        .alert-ok  { background:#edfaf4; border:1px solid #a5dfca; border-left:3px solid var(--success); color:#1a5e3f; }
        .alert-err { background:#fff5f5; border:1px solid #fbd5d5; border-left:3px solid var(--error);   color:#8b2020; }
        .alert svg { flex-shrink:0; margin-top:2px; }

        /* CLUBS CARD */
        .clubs-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); overflow:hidden; animation:fadeUp .4s .1s ease both; }
        .clubs-hdr { padding:1.1rem 1.5rem; border-bottom:1px solid var(--gray-100); display:flex; align-items:center; justify-content:space-between; }
        .clubs-hdr h3 { font-family:'Outfit',sans-serif; font-size:1rem; font-weight:700; display:flex; align-items:center; gap:.5rem; }
        .cnt-badge { background:var(--gray-100); border-radius:20px; padding:.15rem .65rem; font-size:.72rem; font-weight:600; color:var(--gray-700); }

        /* TABLE */
        .tbl-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        thead tr { background:var(--gray-50); border-bottom:1.5px solid var(--gray-200); }
        thead th { padding:.75rem 1.1rem; text-align:left; font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:var(--gray-500); white-space:nowrap; }
        thead th:last-child { text-align:center; }
        tbody tr { border-bottom:1px solid var(--gray-100); transition:background .15s; }
        tbody tr:last-child { border-bottom:none; }
        tbody tr:hover { background:#f5f7fd; }
        tbody td { padding:.95rem 1.1rem; font-size:.85rem; vertical-align:middle; }
        tbody td:last-child { text-align:center; }

        .td-id     { font-family:'Outfit',sans-serif; font-size:.74rem; font-weight:600; color:var(--gray-500); white-space:nowrap; }
        .td-nombre { font-family:'Outfit',sans-serif; font-weight:600; font-size:.9rem; }
        .td-dias   { font-size:.8rem; color:var(--gray-700); white-space:nowrap; }
        .td-hora   { font-family:'Outfit',sans-serif; font-size:.82rem; font-weight:500; white-space:nowrap; color:var(--gray-700); }

        /* Cupo */
        .cupo-wrap { display:flex; align-items:center; gap:.5rem; }
        .cupo-bar  { flex:1; min-width:55px; height:5px; background:var(--gray-100); border-radius:5px; overflow:hidden; }
        .cupo-fill { height:100%; border-radius:5px; }
        .cupo-fill.libre { background:var(--success); }
        .cupo-fill.medio { background:#e09a30; }
        .cupo-fill.lleno { background:var(--error); }
        .cupo-txt  { font-size:.74rem; font-weight:600; white-space:nowrap; }
        .cupo-txt.libre { color:var(--success); }
        .cupo-txt.medio { color:#c07a10; }
        .cupo-txt.lleno { color:var(--error); }

        /* Botones tabla */
        .btn-reg { display:inline-flex; align-items:center; gap:.35rem; padding:.42rem .9rem; background:var(--accent); color:#fff; border:none; border-radius:var(--radius-sm); font-family:'Outfit',sans-serif; font-size:.78rem; font-weight:600; cursor:pointer; white-space:nowrap; transition:all .2s; }
        .btn-reg:hover { background:var(--accent-h); box-shadow:0 4px 14px rgba(74,127,212,.3); transform:translateY(-1px); }
        .btn-reg:active { transform:translateY(0); }
        .btn-dis { display:inline-flex; align-items:center; gap:.35rem; padding:.42rem .9rem; background:var(--gray-100); color:var(--gray-500); border:none; border-radius:var(--radius-sm); font-family:'Outfit',sans-serif; font-size:.78rem; font-weight:600; cursor:not-allowed; white-space:nowrap; }

        /* MODAL */
        .modal-ov { display:none; position:fixed; inset:0; background:rgba(17,30,58,.55); backdrop-filter:blur(3px); z-index:100; align-items:center; justify-content:center; padding:1rem; }
        .modal-ov.open { display:flex; }
        .modal { background:var(--white); border-radius:var(--radius); box-shadow:0 24px 64px rgba(0,0,0,.25); width:100%; max-width:420px; overflow:hidden; animation:modalIn .25s ease both; }
        @keyframes modalIn { from{opacity:0;transform:scale(.94) translateY(10px)} to{opacity:1;transform:scale(1) translateY(0)} }
        .modal-top { background:linear-gradient(135deg,var(--navy),var(--navy-light)); padding:1.5rem 1.75rem; }
        .modal-top h3 { font-family:'Outfit',sans-serif; font-weight:700; font-size:1.05rem; color:#fff; }
        .modal-top p  { font-size:.8rem; color:rgba(255,255,255,.65); margin-top:.25rem; }
        .modal-bd { padding:1.5rem 1.75rem; }
        .modal-det { background:var(--gray-50); border:1px solid var(--gray-100); border-radius:var(--radius-sm); padding:1rem 1.1rem; margin-bottom:1.1rem; }
        .mdr { display:flex; align-items:flex-start; gap:.5rem; font-size:.82rem; padding:.3rem 0; border-bottom:1px solid var(--gray-100); }
        .mdr:last-child { border-bottom:none; padding-bottom:0; }
        .mdr-lbl { color:var(--gray-500); min-width:70px; font-weight:500; }
        .mdr-val { color:var(--text); font-weight:600; font-family:'Outfit',sans-serif; font-size:.82rem; }
        .modal-info { background:#f0f6ff; border:1px solid #c8deff; border-radius:var(--radius-sm); padding:.75rem 1rem; font-size:.79rem; color:#2a4a80; display:flex; gap:.5rem; align-items:flex-start; margin-bottom:1.25rem; line-height:1.5; }
        .modal-btns { display:flex; gap:.75rem; }
        .modal-btns button { flex:1; height:44px; border-radius:var(--radius-sm); font-family:'Outfit',sans-serif; font-size:.88rem; font-weight:600; cursor:pointer; border:none; transition:all .2s; display:flex; align-items:center; justify-content:center; gap:.4rem; }
        .btn-confirm  { background:var(--accent); color:#fff; }
        .btn-confirm:hover { background:var(--accent-h); box-shadow:0 5px 16px rgba(74,127,212,.3); }
        .btn-cancel-m { background:var(--gray-100); color:var(--gray-700); }
        .btn-cancel-m:hover { background:var(--gray-200); }

        /* ── VISTA INSCRITO ──────────────────────────── */
        .ic-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); overflow:hidden; margin-bottom:1.5rem; animation:fadeUp .35s ease both; }
        .ic-top { background:linear-gradient(135deg,var(--navy),var(--navy-light)); padding:1.4rem 2rem; display:flex; align-items:flex-start; gap:1.1rem; position:relative; overflow:hidden; }
        .ic-top::after { content:''; position:absolute; right:-20px; bottom:-40px; width:150px; height:150px; border-radius:50%; background:rgba(255,255,255,.05); }
        .ic-badge { display:inline-flex; align-items:center; gap:.35rem; background:rgba(46,158,110,.3); border:1px solid rgba(46,158,110,.5); border-radius:20px; padding:.22rem .75rem; font-size:.72rem; color:#a8f0cc; font-family:'Outfit',sans-serif; font-weight:600; margin-bottom:.45rem; }
        .ic-icon { width:50px; height:50px; border-radius:12px; background:rgba(255,255,255,.12); border:1.5px solid rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; flex-shrink:0; position:relative; z-index:1; }
        .ic-ti { position:relative; z-index:1; flex:1; }
        .ic-ti h2 { font-family:'Outfit',sans-serif; font-weight:700; font-size:1.1rem; color:#fff; line-height:1.3; margin-bottom:.2rem; }
        .ic-ti p  { font-size:.8rem; color:rgba(255,255,255,.62); line-height:1.5; }
        .ic-body { padding:1.2rem 2rem 1.4rem; display:grid; grid-template-columns:1fr 1fr; gap:.7rem 1.5rem; }
        .ic-field .lbl { font-size:.66rem; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:var(--gray-500); margin-bottom:.18rem; display:flex; align-items:center; gap:.3rem; }
        .ic-field .val { font-size:.87rem; font-weight:600; color:var(--text); font-family:'Outfit',sans-serif; }
        .ic-chip { display:inline-flex; align-items:center; gap:.3rem; background:var(--gray-50); border:1px solid var(--gray-200); border-radius:6px; padding:.25rem .6rem; font-size:.75rem; color:var(--gray-700); margin:.15rem .15rem 0 0; }
        .ic-chip svg { color:var(--accent); }

        /* Próximas sesiones + asistencias */
        .two-col-ins { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
        .ic-col { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; animation:fadeUp .4s .1s ease both; }
        .ic-col-hdr { padding:.85rem 1.25rem; border-bottom:1px solid var(--gray-100); display:flex; align-items:center; gap:.5rem; font-family:'Outfit',sans-serif; font-size:.88rem; font-weight:700; color:var(--navy); }
        .ic-empty { padding:2.5rem 1rem; text-align:center; color:var(--gray-500); font-size:.82rem; }

        .sr { padding:.7rem 1.25rem; border-bottom:1px solid var(--gray-100); display:flex; align-items:center; gap:.85rem; transition:background .15s; }
        .sr:last-child { border-bottom:none; }
        .sr:hover { background:var(--gray-50); }
        .sr-cal { width:44px; height:44px; border-radius:10px; background:var(--accent); color:#fff; display:flex; flex-direction:column; align-items:center; justify-content:center; flex-shrink:0; font-family:'Outfit',sans-serif; }
        .sr-cal .sr-d { font-size:1.15rem; font-weight:700; line-height:1; }
        .sr-cal .sr-m { font-size:.58rem; text-transform:uppercase; letter-spacing:.4px; opacity:.85; }
        .sr-txt .sr-dia { font-family:'Outfit',sans-serif; font-size:.84rem; font-weight:600; color:var(--text); }
        .sr-txt .sr-hr  { font-size:.76rem; color:var(--gray-500); margin-top:.08rem; }
        .sr.hoy { background:#edfaf4; }
        .sr.hoy .sr-cal { background:var(--success); }

        .ar { padding:.6rem 1.25rem; border-bottom:1px solid var(--gray-100); display:flex; align-items:center; gap:.7rem; }
        .ar:last-child { border-bottom:none; }
        .ar-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
        .ar-dot.p { background:var(--success); }
        .ar-dot.a { background:var(--error); }
        .ar-dot.t { background:#d47a20; }
        .ar-fecha { font-size:.82rem; color:var(--text); font-weight:500; flex:1; }
        .ar-est { font-size:.73rem; font-weight:700; font-family:'Outfit',sans-serif; }
        .ar-est.p { color:var(--success); }
        .ar-est.a { color:var(--error); }
        .ar-est.t { color:#d47a20; }

        @keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        footer { text-align:center; padding:1.5rem; font-size:.72rem; color:var(--gray-500); }

        @media (max-width:700px) {
            .page { padding:1.25rem 1rem 3rem; }
            header { padding:0 1rem; }
            nav a span { display:none; }
            .ficha-bd { grid-template-columns:1fr 1fr; }
        }
    </style>
</head>
<body>

<header>
    <div class="hb">
        <div class="hb-logo">UdeC</div>
        <div>
            <div class="hb-name">Clubes Estudiantiles</div>
            <div class="hb-sub">Bachillerato 23</div>
        </div>
    </div>
    <nav>
        <a href="dashboard_alumno.php" class="active">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span>Inicio</span>
        </a>
        <a href="?logout=1" class="nav-out">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Salir
        </a>
    </nav>
</header>

<div class="subhdr">
    <div class="sub-av"><?= htmlspecialchars($iniciales) ?></div>
    <div>
        <div class="sub-name"><?= htmlspecialchars($alumno['nombre']) ?></div>
        <div class="sub-det"><?= htmlspecialchars($alumno['plantel']) ?> &nbsp;·&nbsp; Núm. cuenta: <?= htmlspecialchars($alumno['cuenta']) ?></div>
    </div>
</div>

<div class="page">

    <?php if ($msg_ok): ?>
    <div class="alert alert-ok">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <div><?= $msg_ok ?></div>
    </div>
    <?php endif; ?>
    <?php if ($msg_err): ?>
    <div class="alert alert-err">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div><?= htmlspecialchars($msg_err) ?></div>
    </div>
    <?php endif; ?>

    <!-- FICHA -->
    <div class="sec-lbl">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Ficha del alumno
    </div>
    <div class="ficha">
        <div class="ficha-top">
            <div class="ficha-av"><?= htmlspecialchars($iniciales) ?></div>
            <div class="ficha-ti">
                <h2><?= htmlspecialchars($alumno['nombre']) ?></h2>
                <div class="badge-cta">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                    Número de cuenta: &nbsp;<strong><?= htmlspecialchars($alumno['cuenta']) ?></strong>
                </div>
            </div>
        </div>
        <div class="ficha-bd" style="grid-template-columns:1fr 1fr">
            <div>
                <div class="fi-lbl"><svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>Número de cuenta</div>
                <div class="fi-val"><?= htmlspecialchars($alumno['cuenta']) ?></div>
            </div>
            <div>
                <div class="fi-lbl"><svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>Plantel</div>
                <div class="fi-val"><?= htmlspecialchars($alumno['plantel']) ?></div>
            </div>
            <div>
                <div class="fi-lbl"><svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>Correo</div>
                <div class="fi-val sm"><?= htmlspecialchars($alumno['correo']) ?></div>
            </div>
            <div>
                <div class="fi-lbl"><svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Estado</div>
                <div class="fi-val sm">
                    <?php $n = count($mis_clubs); if ($n > 0): ?>
                    <span style="color:var(--success);font-weight:600">✓ Inscrito en <?= $n ?> club<?= $n > 1 ? 's' : '' ?></span>
                    <?php else: ?>
                    <span style="color:var(--gray-500)">Sin club asignado</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($mis_clubs)): ?>

    <!-- ── MIS CLUBS (puede haber varios) ────────── -->
    <div class="sec-lbl">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        Mis clubs <span style="font-weight:400;color:var(--gray-500)">(<?= count($mis_clubs) ?>)</span>
    </div>

    <?php foreach ($mis_clubs as $mc): ?>
    <div class="ic-card" style="margin-bottom:1.25rem">
        <div class="ic-top">
            <div class="ic-icon">
                <svg width="22" height="22" fill="none" stroke="white" stroke-width="1.8" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="ic-ti">
                <div class="ic-badge">
                    <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                    Inscrito
                </div>
                <h2><?= htmlspecialchars($mc['nombre']) ?></h2>
                <p><?= htmlspecialchars($mc['descripcion']) ?></p>
            </div>
        </div>
        <div class="ic-body">
            <div class="ic-field">
                <div class="lbl"><svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Encargado</div>
                <div class="val"><?= htmlspecialchars($mc['encargado'] ?? '—') ?></div>
            </div>
            <div class="ic-field">
                <div class="lbl"><svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Estado</div>
                <div class="val"><?= ucfirst($mc['estado']) ?></div>
            </div>
            <div class="ic-field">
                <div class="lbl"><svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Periodo</div>
                <div class="val"><?= date('d M Y', strtotime($mc['fecha_inicio'])) ?> — <?= date('d M Y', strtotime($mc['fecha_fin'])) ?></div>
            </div>
            <div class="ic-field">
                <div class="lbl"><svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Cupo</div>
                <div class="val"><?= $mc['inscritos'] ?> / <?= $mc['limite'] ?> inscritos</div>
            </div>
            <?php if (!empty($mc['horarios'])): ?>
            <div class="ic-field" style="grid-column:1/-1">
                <div class="lbl"><svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Horarios</div>
                <div>
                    <?php foreach ($mc['horarios'] as $hor): ?>
                    <span class="ic-chip">
                        <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?= htmlspecialchars($hor['dia']) ?> &nbsp;<?= $hor['ini'] ?> – <?= $hor['fin'] ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Próximas sesiones + Asistencias del club -->
    <div class="two-col-ins" style="margin-bottom:2rem">
        <div class="ic-col">
            <div class="ic-col-hdr">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Próximas sesiones
            </div>
            <?php if (empty($mc['proximas'])): ?>
            <div class="ic-empty">No hay sesiones próximas.</div>
            <?php else: foreach ($mc['proximas'] as $ps):
                $esHoy = $ps['fecha']->format('Y-m-d') === date('Y-m-d'); ?>
            <div class="sr <?= $esHoy ? 'hoy' : '' ?>">
                <div class="sr-cal">
                    <span class="sr-d"><?= $ps['fecha']->format('d') ?></span>
                    <span class="sr-m"><?= $ps['mes'] ?></span>
                </div>
                <div class="sr-txt">
                    <div class="sr-dia"><?= htmlspecialchars($ps['dia']) ?>
                        <?php if ($esHoy): ?><span style="color:var(--success);font-size:.7rem;font-weight:700"> · Hoy</span><?php endif; ?>
                    </div>
                    <div class="sr-hr"><?= $ps['ini'] ?> – <?= $ps['fin'] ?></div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <div class="ic-col">
            <div class="ic-col-hdr">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Mis asistencias
            </div>
            <?php if (empty($mc['asistencias'])): ?>
            <div class="ic-empty">Sin registros aún.</div>
            <?php else: foreach ($mc['asistencias'] as $ar):
                $est    = $ar['estado'] ?? 'asistio';
                $dotCls = ($est === 'asistio') ? 'p' : (($est === 'tarde') ? 't' : 'a');
                $estLbl = ['asistio'=>'Presente','falta'=>'Ausente','tarde'=>'Tarde'][$est] ?? ucfirst($est);
                $horaStr = $ar['hora_entrada']
                    ? $ar['hora_entrada'] . ($ar['hora_salida'] ? ' – '.$ar['hora_salida'] : '') : ''; ?>
            <div class="ar">
                <span class="ar-dot <?= $dotCls ?>"></span>
                <span class="ar-fecha"><?= date('d M Y', strtotime($ar['fecha'])) ?>
                    <?php if ($horaStr): ?><span style="font-size:.72rem;color:var(--gray-500);font-weight:400;margin-left:.3rem"><?= htmlspecialchars($horaStr) ?></span><?php endif; ?>
                </span>
                <span class="ar-est <?= $dotCls ?>"><?= $estLbl ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>

    <!-- ── CLUBS DISPONIBLES (siempre visible si hay alguno) ──── -->
    <?php if (!empty($clubs)): ?>
    <div class="sec-lbl">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Clubes del semestre
    </div>

    <div class="clubs-card">
        <div class="clubs-hdr">
            <h3>
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Clubs del semestre <?= htmlspecialchars($alumno['ciclo']) ?>
            </h3>
            <span class="cnt-badge"><?= count($clubs) ?> clubs</span>
        </div>
        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID Club</th>
                        <th>Nombre del club</th>
                        <th>Días</th>
                        <th>Horario</th>
                        <th>Cupo disponible</th>
                        <th>Registro</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($clubs as $c):
                    $disp      = $c['cupo_total'] - $c['inscritos'];
                    $pct       = $c['cupo_total'] > 0 ? round(($c['inscritos'] / $c['cupo_total']) * 100) : 0;
                    $lleno     = $disp <= 0;
                    $en_curso  = $c['estado'] === 'iniciado';
                    $nivel     = $pct >= 100 ? 'lleno' : ($pct >= 70 ? 'medio' : 'libre');
                    $jNombre   = htmlspecialchars(addslashes($c['nombre']));
                    $jDias     = htmlspecialchars(addslashes($c['dias']));
                    $jHora     = htmlspecialchars(addslashes($c['horario']));
                ?>
                <tr>
                    <td class="td-id"><?= htmlspecialchars($c['id']) ?></td>
                    <td class="td-nombre">
                        <?= htmlspecialchars($c['nombre']) ?>
                        <?php if ($en_curso): ?>
                        <span style="display:inline-block;margin-left:.4rem;background:#edfaf4;border:1px solid #a5dfca;color:#1a6e47;border-radius:20px;padding:.1rem .55rem;font-size:.68rem;font-weight:700;vertical-align:middle;">En curso</span>
                        <?php endif; ?>
                    </td>
                    <td class="td-dias"><?= htmlspecialchars($c['dias']) ?></td>
                    <td class="td-hora"><?= htmlspecialchars($c['horario']) ?></td>
                    <td>
                        <div class="cupo-wrap">
                            <div class="cupo-bar"><div class="cupo-fill <?= $nivel ?>" style="width:<?= $pct ?>%"></div></div>
                            <span class="cupo-txt <?= $nivel ?>"><?= $lleno ? 'Sin cupo' : $disp.' / '.$c['cupo_total'] ?></span>
                        </div>
                    </td>
                    <td>
                        <?php if ($en_curso): ?>
                        <span class="btn-dis">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            Registro cerrado
                        </span>
                        <?php elseif ($lleno): ?>
                        <span class="btn-dis">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                            Sin cupo
                        </span>
                        <?php else: ?>
                        <button class="btn-reg" onclick="abrirModal('<?= htmlspecialchars($c['id']) ?>','<?= $jNombre ?>','<?= $jDias ?>','<?= $jHora ?>',<?= $disp ?>,<?= $c['cupo_total'] ?>)">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Registrarme
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:3rem 1rem;color:var(--gray-500);font-size:.88rem">
        <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto .75rem;opacity:.4"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        <?php if ($db_error): ?>
            Error al consultar los clubes. Intenta recargar la página.
        <?php elseif (!empty($mis_clubs)): ?>
            Ya estás inscrito en todos los clubs disponibles del semestre.
        <?php else: ?>
            No hay clubs disponibles para registrarse en este momento.
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<!-- MODAL -->
<div class="modal-ov" id="modal">
    <div class="modal">
        <div class="modal-top">
            <h3>Confirmar registro al club</h3>
            <p>Se enviará un correo de confirmación a tu correo institucional</p>
        </div>
        <div class="modal-bd">
            <div class="modal-det">
                <div class="mdr"><span class="mdr-lbl">Club:</span>   <span class="mdr-val" id="m-nombre">—</span></div>
                <div class="mdr"><span class="mdr-lbl">ID:</span>     <span class="mdr-val" id="m-id">—</span></div>
                <div class="mdr"><span class="mdr-lbl">Días:</span>   <span class="mdr-val" id="m-dias">—</span></div>
                <div class="mdr"><span class="mdr-lbl">Horario:</span><span class="mdr-val" id="m-hora">—</span></div>
                <div class="mdr"><span class="mdr-lbl">Cupo:</span>   <span class="mdr-val" id="m-cupo">—</span></div>
            </div>
            <div class="modal-info">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <span>Se enviará un enlace de confirmación a <strong><?= htmlspecialchars($alumno['correo']) ?></strong>. Debes hacer clic en él para completar tu registro.</span>
            </div>
            <form method="POST" id="form-reg">
                <input type="hidden" name="registrar_club" value="1">
                <input type="hidden" name="club_id" id="input-cid">
                <div class="modal-btns">
                    <button type="button" class="btn-cancel-m" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn-confirm">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Enviar confirmación
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<footer>© <?= date('Y') ?> Universidad de Colima — Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>

<script>
function abrirModal(id, nombre, dias, hora, disp, total) {
    document.getElementById('m-id').textContent     = id;
    document.getElementById('m-nombre').textContent = nombre;
    document.getElementById('m-dias').textContent   = dias;
    document.getElementById('m-hora').textContent   = hora;
    document.getElementById('m-cupo').textContent   = disp + ' lugares disponibles de ' + total;
    document.getElementById('input-cid').value      = id;
    document.getElementById('modal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function cerrarModal() {
    document.getElementById('modal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('modal').addEventListener('click', e => { if (e.target === document.getElementById('modal')) cerrarModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });
</script>
</body>
</html>
