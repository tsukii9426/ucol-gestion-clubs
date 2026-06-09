<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/enviar_correo.php';
require_once __DIR__ . '/sincronizar_estados.php';

// ── Proteger ruta ────────────────────────────────────────────────
if (empty($_SESSION['plantel_id'])) {
    header('Location: b23-srvc-coord.php');
    exit;
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: b23-srvc-coord.php');
    exit;
}

sincronizarEstadosClubes();

$plantel_id     = (int)$_SESSION['plantel_id'];
$plantel_nombre = $_SESSION['plantel_nombre'] ?? '';
$plantel_correo = $_SESSION['plantel_correo'] ?? '';

// ── Acción: Aprobar o Rechazar ───────────────────────────────────
$msg_ok  = '';
$msg_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'], $_POST['sol_id'])) {
    $sol_id = (int)$_POST['sol_id'];
    $accion = $_POST['accion']; // 'aprobar' | 'rechazar'

    if (!in_array($accion, ['aprobar', 'rechazar'], true)) {
        $msg_err = 'Acción no válida.';
    } else {
        try {
            $pdo = getDB();

            // Verificar que la solicitud pertenece a este plantel y está pendiente
            $stmt = $pdo->prepare(
                "SELECT * FROM solicitudes_encargado
                 WHERE id = ? AND id_plantel = ? AND estado = 'pendiente'"
            );
            $stmt->execute([$sol_id, $plantel_id]);
            $sol = $stmt->fetch();

            if (!$sol) {
                $msg_err = 'Solicitud no encontrada o ya fue procesada.';
            } elseif ($accion === 'aprobar') {
                $pdo->beginTransaction();
                $esAlumno  = ($sol['tipo'] === 'Estudiante');
                $hash_pass = $sol['contrasena'] ?? null;

                if ($esAlumno) {
                    $nc = $sol['apellido_paterno'].' '.$sol['apellido_materno'].' '.$sol['nombres'];
                    $pdo->prepare("
                        INSERT INTO personas (id,tipo,nombres,apellido_paterno,apellido_materno,correo,telefono,contrasena,id_plantel)
                        VALUES (?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE tipo=VALUES(tipo),nombres=VALUES(nombres),
                        apellido_paterno=VALUES(apellido_paterno),apellido_materno=VALUES(apellido_materno),
                        correo=VALUES(correo),telefono=VALUES(telefono),contrasena=VALUES(contrasena),id_plantel=VALUES(id_plantel)
                    ")->execute([(int)$sol['numero_trabajador'],'Estudiante',$sol['nombres'],$sol['apellido_paterno'],$sol['apellido_materno'],$sol['correo'],$sol['telefono']?:null,$hash_pass,(int)$plantel_id]);

                    $pdo->prepare("
                        INSERT INTO estudiantes (cuenta,nombre_completo,correo,id_plantel)
                        VALUES (?,?,?,?)
                        ON DUPLICATE KEY UPDATE nombre_completo=VALUES(nombre_completo),correo=VALUES(correo),id_plantel=VALUES(id_plantel)
                    ")->execute([(int)$sol['numero_trabajador'],$nc,$sol['correo'],(int)$plantel_id]);
                } else {
                    $pdo->prepare("
                        INSERT INTO personas (id,tipo,nombres,apellido_paterno,apellido_materno,correo,telefono,contrasena,id_plantel)
                        VALUES (?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE tipo=VALUES(tipo),nombres=VALUES(nombres),
                        apellido_paterno=VALUES(apellido_paterno),apellido_materno=VALUES(apellido_materno),
                        correo=VALUES(correo),telefono=VALUES(telefono),contrasena=VALUES(contrasena),id_plantel=VALUES(id_plantel)
                    ")->execute([(int)$sol['numero_trabajador'],$sol['tipo'],$sol['nombres'],$sol['apellido_paterno'],$sol['apellido_materno'],$sol['correo'],$sol['telefono']?:null,$hash_pass,(int)$plantel_id]);

                    $pdo->prepare("INSERT IGNORE INTO encargados (id_persona,id_plantel) VALUES (?,?)")
                        ->execute([(int)$sol['numero_trabajador'],(int)$plantel_id]);
                }

                $pdo->prepare("UPDATE solicitudes_encargado SET estado='aprobado' WHERE id=?")
                    ->execute([$sol_id]);
                $pdo->commit();

                $msg_ok = 'Solicitud de <strong>' . htmlspecialchars($sol['nombres'].' '.$sol['apellido_paterno'])
                        . '</strong> aprobada y guardada en el sistema.';

                // Notificar al solicitante
                _notificarResultado($sol['correo'], $sol['nombres'].' '.$sol['apellido_paterno'], $plantel_nombre, 'aprobado');

            } else { // rechazar
                $pdo->prepare("UPDATE solicitudes_encargado SET estado='rechazado' WHERE id=?")
                    ->execute([$sol_id]);

                $msg_ok = 'Solicitud de <strong>' . htmlspecialchars($sol['nombres'].' '.$sol['apellido_paterno'])
                        . '</strong> rechazada.';

                _notificarResultado($sol['correo'], $sol['nombres'].' '.$sol['apellido_paterno'], $plantel_nombre, 'rechazado');
            }

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $msg_err = 'Error al procesar la solicitud. Intenta de nuevo.';
            error_log('dashboard_plantel accion: ' . $e->getMessage());
        }
    }
}

// ── Notificación por correo al solicitante ───────────────────────
function _notificarResultado(string $correo, string $nombre, string $plantel, string $resultado): void {
    $icono = $resultado === 'aprobado' ? '✅' : '❌';
    $texto = $resultado === 'aprobado'
        ? "Tu solicitud de registro en <strong>$plantel</strong> fue <strong style='color:#2e9e6e'>aprobada</strong>. Ya puedes iniciar sesión."
        : "Tu solicitud de registro en <strong>$plantel</strong> fue <strong style='color:#d94f4f'>rechazada</strong>. Contacta a la administración del plantel.";

    $html = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;background:#f0f3fa;padding:32px'>
        <div style='max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 4px 20px rgba(0,0,0,.1)'>
            <div style='text-align:center;font-size:36px;margin-bottom:16px'>$icono</div>
            <h2 style='font-family:Arial;color:#1b2d54;text-align:center;margin:0 0 12px'>Solicitud " . ucfirst($resultado) . "</h2>
            <p style='color:#3d4260;font-size:15px;line-height:1.6;margin:0 0 12px'>Hola, <strong>" . htmlspecialchars($nombre) . ":</strong></p>
            <p style='color:#3d4260;font-size:14px;line-height:1.6;margin:0 0 24px'>$texto</p>
            <p style='color:#7a8099;font-size:12px;text-align:center;margin:0'>© " . date('Y') . " Universidad de Colima · Sistema de Clubes Estudiantiles</p>
        </div></body></html>";

    $asunto  = '=?UTF-8?B?' . base64_encode("Tu solicitud fue $resultado") . '?=';
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    @mail($correo, $asunto, $html, $headers);
}

// ── Acción: Autorizar club ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autorizar_club'])) {
    $club_id_aut = (int)($_POST['club_id'] ?? 0);
    $accion_aut  = $_POST['autorizar_club']; // 'si' | 'no'
    if ($club_id_aut && in_array($accion_aut, ['si','no'], true)) {
        try {
            getDB()->prepare(
                "UPDATE clubes SET autorizado = ?, restaurado = 0 WHERE id = ? AND id_plantel = ? AND estado = 'borrador'"
            )->execute([$accion_aut, $club_id_aut, $plantel_id]);
            $accion_txt = $accion_aut === 'si' ? 'autorizado' : 'denegado';
            $msg_ok = "Club <strong>#$club_id_aut</strong> $accion_txt correctamente.";
        } catch (Exception $e) {
            $msg_err = 'No se pudo actualizar la autorización. Intenta de nuevo.';
        }
    }
}

// ── Toggle activo/inactivo de un encargado ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_encargado'])) {
    $enc_num     = (int)$_POST['enc_numero'];
    $nuevo_activo= (int)$_POST['nuevo_activo']; // 0 o 1
    if ($enc_num && in_array($nuevo_activo, [0, 1], true)) {
        try {
            getDB()->prepare(
                "UPDATE encargados SET activo = ? WHERE id_persona = ? AND id_plantel = ?"
            )->execute([$nuevo_activo, $enc_num, $plantel_id]);
            $msg_ok = 'Encargado ' . ($nuevo_activo ? '<strong>activado</strong>' : '<strong>desactivado</strong>') . ' correctamente.';
        } catch (Exception $e) {
            $msg_err = 'Error al cambiar el estado del encargado.';
        }
    }
}

// ── Cargar solicitudes del plantel ───────────────────────────────
$filtro = $_GET['filtro'] ?? 'pendiente';
if (!in_array($filtro, ['pendiente','aprobado','rechazado','todos'])) $filtro = 'pendiente';

$solicitudes = [];
$conteos     = ['pendiente'=>0,'aprobado'=>0,'rechazado'=>0];
$clubs_borrador = [];   // clubs esperando autorización

try {
    $pdo = getDB();

    // Conteos solicitudes encargado
    $rows = $pdo->prepare(
        "SELECT estado, COUNT(*) AS n FROM solicitudes_encargado WHERE id_plantel = ? GROUP BY estado"
    );
    $rows->execute([$plantel_id]);
    foreach ($rows->fetchAll() as $r) $conteos[$r['estado']] = (int)$r['n'];

    // Solicitudes filtradas — incluye estado activo del encargado (si fue aprobado)
    $where = $filtro === 'todos' ? '' : "AND se.estado = '$filtro'";
    $stmt  = $pdo->prepare("
        SELECT se.*,
               DATE_FORMAT(se.creado_en, '%d/%m/%Y %H:%i') AS fecha_fmt,
               COALESCE(enc.activo, 0)                      AS enc_activo
        FROM solicitudes_encargado se
        LEFT JOIN encargados enc
               ON enc.id_persona = se.numero_trabajador
              AND enc.id_plantel = se.id_plantel
        WHERE se.id_plantel = ? $where
        ORDER BY se.creado_en DESC
    ");
    $stmt->execute([$plantel_id]);
    $solicitudes = $stmt->fetchAll();

    // Contar "activos" reales (aprobados Y activos en encargados)
    $conteos['activos'] = 0;
    try {
        $ca = $pdo->prepare("SELECT COUNT(*) FROM encargados WHERE id_plantel = ? AND activo = 1");
        $ca->execute([$plantel_id]);
        $conteos['activos'] = (int)$ca->fetchColumn();
    } catch (Exception $e) {}

    // TODOS los clubs del plantel con su estado y encargado
    $stmt_clubs = $pdo->prepare("
        SELECT c.id, c.nombre, c.descripcion, c.semestre, c.limite,
               c.fecha_inicio, c.fecha_fin, c.fecha_limite_registro,
               c.estado, c.autorizado, c.restaurado,
               COUNT(DISTINCT ic.numero_cuenta) AS inscritos,
               CONCAT(p.nombres,' ',p.apellido_paterno) AS encargado_nombre
        FROM clubes c
        LEFT JOIN inscripciones_club ic ON ic.id_club = c.id
        LEFT JOIN personas p            ON p.id = c.id_encargado
        WHERE c.id_plantel = ?
        GROUP BY c.id
        ORDER BY FIELD(c.estado,'borrador','apertura','iniciado','finalizado','cancelado'),
                 c.autorizado ASC, c.id DESC
    ");
    $stmt_clubs->execute([$plantel_id]);
    $todos_clubs = $stmt_clubs->fetchAll();
    $clubs_borrador = array_filter($todos_clubs, fn($c) => $c['estado'] === 'borrador');

    // Horarios de todos los clubs del plantel
    $horarios_plantel = [];
    if (!empty($todos_clubs)) {
        $ids_pl = implode(',', array_map('intval', array_column($todos_clubs, 'id')));
        $h_rows = $pdo->query("
            SELECT id_club, dia,
                   TIME_FORMAT(hora_inicio,'%H:%i') AS ini,
                   TIME_FORMAT(hora_fin,'%H:%i')    AS fin
            FROM horarios WHERE id_club IN ($ids_pl)
            ORDER BY FIELD(dia,'Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'), hora_inicio
        ")->fetchAll();
        foreach ($h_rows as $h) $horarios_plantel[$h['id_club']][] = $h;
    }

    // Conteos de clubs por estado
    $conteos_clubs = ['borrador'=>0,'apertura'=>0,'iniciado'=>0,'finalizado'=>0,'cancelado'=>0,'pendiente_auth'=>0];
    foreach ($todos_clubs as $cl) {
        $est = $cl['estado'] ?? 'borrador';
        if (isset($conteos_clubs[$est])) $conteos_clubs[$est]++;
        if ($est === 'borrador' && $cl['autorizado'] === 'no') $conteos_clubs['pendiente_auth']++;
    }

} catch (Exception $e) {
    $msg_err = 'Error al cargar los datos.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Plantel — <?= htmlspecialchars($plantel_nombre) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:    #1b2d54;
            --navy-l:  #243567;
            --accent:  #4a7fd4;
            --accent-h:#3568bf;
            --success: #2e9e6e;
            --error:   #d94f4f;
            --warning: #d47a20;
            --white:   #ffffff;
            --gray-50: #f7f8fc;
            --gray-100:#eef0f6;
            --gray-200:#e0e4f0;
            --gray-300:#c5cad8;
            --gray-500:#7a8099;
            --gray-700:#3d4260;
            --text:    #1e2340;
            --radius:  14px;
            --radius-sm:8px;
            --shadow:  0 4px 20px rgba(27,45,84,.09);
            --shadow-lg:0 10px 36px rgba(27,45,84,.14);
        }
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'DM Sans',sans-serif; background:var(--gray-50); min-height:100vh; color:var(--text); }

        /* HEADER */
        header { background:var(--navy); height:64px; display:flex; align-items:center; justify-content:space-between; padding:0 2rem; box-shadow:0 2px 10px rgba(0,0,0,.25); position:sticky; top:0; z-index:50; }
        .hb { display:flex; align-items:center; gap:.75rem; }
        .hb-logo { width:40px; height:40px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-family:'Outfit',sans-serif; font-weight:700; font-size:.75rem; color:var(--navy); }
        .hb-name { font-family:'Outfit',sans-serif; font-size:1.05rem; font-weight:600; color:#fff; }
        .hb-sub  { font-size:.7rem; color:rgba(255,255,255,.5); text-transform:uppercase; letter-spacing:.8px; }
        .nav-out { color:rgba(255,255,255,.75); text-decoration:none; font-size:.82rem; font-weight:500; padding:.4rem .9rem; border-radius:var(--radius-sm); border:1px solid rgba(255,255,255,.25); display:flex; align-items:center; gap:.35rem; transition:all .2s; }
        .nav-out:hover { background:rgba(255,255,255,.1); border-color:rgba(255,255,255,.4); color:#fff; }

        /* SUBHEADER */
        .subhdr { background:var(--navy-l); padding:.6rem 2rem; display:flex; align-items:center; gap:.75rem; }
        .sub-av { width:34px; height:34px; border-radius:50%; background:var(--accent); display:flex; align-items:center; justify-content:center; font-family:'Outfit',sans-serif; font-weight:700; font-size:.72rem; color:#fff; }
        .sub-name { font-family:'Outfit',sans-serif; font-weight:600; font-size:.9rem; color:#fff; }
        .sub-det  { font-size:.72rem; color:rgba(255,255,255,.6); }

        /* PAGE */
        .page { max-width:1280px; margin:0 auto; padding:2rem 1.5rem 4rem; }

        /* ALERTS */
        .alert { border-radius:var(--radius-sm); padding:.9rem 1.1rem; font-size:.84rem; margin-bottom:1.25rem; display:flex; align-items:flex-start; gap:.6rem; line-height:1.55; animation:fadeUp .3s ease both; }
        .alert svg { flex-shrink:0; margin-top:2px; }
        .alert-ok  { background:#edfaf4; border:1px solid #a5dfca; border-left:3px solid var(--success); color:#1a5e3f; }
        .alert-err { background:#fff5f5; border:1px solid #fbd5d5; border-left:3px solid var(--error);   color:#8b2020; }

        /* STATS */
        .stats { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:2rem; }
        .stat-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); padding:1.25rem 1.5rem; display:flex; align-items:center; gap:1rem; transition:transform .2s; }
        .stat-card:hover { transform:translateY(-2px); }
        .stat-card a { text-decoration:none; display:contents; }
        .si { width:44px; height:44px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .si-orange { background:#fff3e8; color:var(--warning); }
        .si-green  { background:#e8faf3; color:var(--success); }
        .si-red    { background:#fff0f0; color:var(--error); }
        .stat-num  { font-family:'Outfit',sans-serif; font-size:1.8rem; font-weight:700; line-height:1; }
        .stat-lbl  { font-size:.78rem; color:var(--gray-500); margin-top:.2rem; }

        /* FILTROS */
        .filtros { display:flex; gap:.5rem; margin-bottom:1.5rem; flex-wrap:wrap; align-items:center; }
        .filtros span { font-size:.78rem; color:var(--gray-500); font-weight:500; margin-right:.25rem; }
        .filtro-btn { padding:.4rem 1rem; border-radius:20px; border:1.5px solid var(--gray-200); background:var(--white); font-family:'Outfit',sans-serif; font-size:.8rem; font-weight:600; color:var(--gray-700); text-decoration:none; cursor:pointer; transition:all .2s; }
        .filtro-btn:hover { border-color:var(--accent); color:var(--accent); }
        .filtro-btn.active.p { background:#fff3e8; border-color:var(--warning); color:var(--warning); }
        .filtro-btn.active.a { background:#e8faf3; border-color:var(--success); color:var(--success); }
        .filtro-btn.active.r { background:#fff0f0; border-color:var(--error);   color:var(--error); }
        .filtro-btn.active.t { background:var(--gray-100); border-color:var(--gray-300); color:var(--gray-700); }

        /* CARDS DE SOLICITUDES */
        .sol-grid { display:flex; flex-direction:column; gap:1rem; }

        .sol-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; animation:fadeUp .3s ease both; }
        .sol-card-inner { display:grid; grid-template-columns:1fr auto; gap:1.25rem; padding:1.25rem 1.5rem; align-items:start; }

        .sol-header { display:flex; align-items:center; gap:.75rem; margin-bottom:.75rem; }
        .sol-avatar { width:44px; height:44px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-family:'Outfit',sans-serif; font-weight:700; font-size:.95rem; color:#fff; flex-shrink:0; }
        .av-orange { background:var(--warning); }
        .av-green  { background:var(--success); }
        .av-red    { background:#c03030; }
        .av-blue   { background:var(--accent); }

        .sol-nombre { font-family:'Outfit',sans-serif; font-weight:700; font-size:1rem; }
        .sol-fecha  { font-size:.74rem; color:var(--gray-500); margin-top:.1rem; }

        .badge { display:inline-flex; align-items:center; gap:.3rem; padding:.22rem .7rem; border-radius:20px; font-size:.72rem; font-weight:700; font-family:'Outfit',sans-serif; }
        .badge-p { background:#fff3e8; color:var(--warning); border:1px solid #f5d8a0; }
        .badge-a { background:#e8faf3; color:var(--success); border:1px solid #a5dfca; }
        .badge-r { background:#fff0f0; color:var(--error);   border:1px solid #fbd5d5; }
        .badge .dot { width:6px; height:6px; border-radius:50%; background:currentColor; }

        .sol-datos { display:grid; grid-template-columns:1fr 1fr; gap:.5rem .75rem; margin-top:.6rem; }
        .sol-dato { font-size:.82rem; }
        .sol-dato .lbl { font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:var(--gray-500); margin-bottom:.1rem; }
        .sol-dato .val { color:var(--text); font-weight:500; }

        /* Botones de acción */
        .sol-actions { display:flex; flex-direction:column; gap:.5rem; justify-content:center; }
        .btn-aprobar, .btn-rechazar, .btn-done {
            height:40px; padding:0 1.2rem;
            border:none; border-radius:var(--radius-sm);
            font-family:'Outfit',sans-serif; font-size:.82rem; font-weight:700;
            cursor:pointer; white-space:nowrap;
            display:flex; align-items:center; gap:.4rem;
            transition:all .2s;
        }
        .btn-aprobar  { background:var(--success); color:#fff; width:100%; }
        .btn-aprobar:hover  { background:#23825a; box-shadow:0 4px 14px rgba(46,158,110,.3); transform:translateY(-1px); }
        .btn-rechazar { background:#fff0f0; color:var(--error); border:1.5px solid #fbd5d5; width:100%; }
        .btn-rechazar:hover { background:#ffe4e4; border-color:var(--error); }
        .btn-done     { background:var(--gray-100); color:var(--gray-500); cursor:default; width:100%; }

        /* Empty state */
        .empty { text-align:center; padding:3.5rem 1rem; color:var(--gray-500); }
        .empty svg { margin:0 auto 1rem; display:block; opacity:.35; }
        .empty p { font-size:.92rem; font-weight:600; margin-bottom:.35rem; }
        .empty span { font-size:.82rem; }

        /* LAYOUT 2 COLUMNAS */
        .two-col { display:grid; grid-template-columns:1fr 380px; gap:1.5rem; align-items:start; }

        /* COLUMNA DE CLUBS */
        .col-clubs-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:.85rem; }
        .col-clubs-header h3 { font-family:'Outfit',sans-serif; font-size:.95rem; font-weight:700; color:var(--navy); display:flex; align-items:center; gap:.5rem; }
        .club-row { background:var(--white); border-radius:var(--radius-sm); box-shadow:var(--shadow); overflow:hidden; margin-bottom:.65rem; animation:fadeUp .3s ease both; }
        .club-row-bar { height:3px; }
        .club-row-body { padding:.85rem 1rem; display:grid; grid-template-columns:1fr auto; gap:.5rem .75rem; align-items:start; }
        .club-row-nombre { font-family:'Outfit',sans-serif; font-size:.9rem; font-weight:700; color:var(--navy); }
        .club-row-enc { font-size:.74rem; color:var(--gray-500); margin-top:.15rem; }
        .club-row-meta { display:flex; gap:.4rem; flex-wrap:wrap; margin-top:.5rem; align-items:center; }
        .club-chip-sm { font-size:.68rem; color:var(--gray-500); display:inline-flex; align-items:center; gap:.2rem; }
        .club-row-actions { display:flex; flex-direction:column; gap:.35rem; align-items:flex-end; }

        /* Badges estado club */
        .est-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.18rem .6rem; border-radius:20px; font-size:.68rem; font-weight:700; font-family:'Outfit',sans-serif; white-space:nowrap; }
        .est-borrador   { background:#f0f0ff; color:#5a4fcf; border:1px solid #c8c0ff; }
        .est-apertura   { background:#e8f4ff; color:#2a5ea8; border:1px solid #bdd8ff; }
        .est-iniciado   { background:#edfaf4; color:#1d6344; border:1px solid #a5dfca; }
        .est-finalizado { background:#f0f0f6; color:var(--gray-500); border:1px solid var(--gray-300); }
        .est-cancelado  { background:#fff0f0; color:#8b2020; border:1px solid #fbd5d5; }
        .est-dot { width:5px; height:5px; border-radius:50%; background:currentColor; }

        /* Botón autorizar */
        .btn-auth { height:30px; padding:0 .75rem; border-radius:6px; font-family:'Outfit',sans-serif; font-size:.74rem; font-weight:700; cursor:pointer; border:1.5px solid; display:inline-flex; align-items:center; gap:.3rem; transition:all .2s; white-space:nowrap; }
        .btn-auth-si { background:#edfaf4; color:#1d6344; border-color:#a5dfca; }
        .btn-auth-si:hover { background:#2e9e6e; color:#fff; border-color:#2e9e6e; }
        .btn-auth-no { background:#fff5f5; color:#8b2020; border-color:#fbd5d5; }
        .btn-auth-no:hover { background:#d94f4f; color:#fff; border-color:#d94f4f; }

        @keyframes fadeUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
        footer { text-align:center; padding:1.5rem; font-size:.72rem; color:var(--gray-500); }

        /* MODAL DETALLE CLUB */
        .cdm-ov { display:none; position:fixed; inset:0; background:rgba(17,30,58,.52); backdrop-filter:blur(4px); z-index:200; align-items:center; justify-content:center; padding:1rem; }
        .cdm-ov.open { display:flex; }
        .cdm-wrap { background:#fff; border-radius:14px; box-shadow:0 24px 64px rgba(0,0,0,.24); width:100%; max-width:520px; max-height:90vh; overflow-y:auto; animation:fadeUp .22s ease both; }
        .cdm-top { background:linear-gradient(135deg,var(--navy),var(--navy-l)); padding:1.35rem 1.75rem; display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; position:sticky; top:0; z-index:1; }
        .cdm-top h3 { font-family:'Outfit',sans-serif; font-weight:700; font-size:1.05rem; color:#fff; margin-bottom:.15rem; }
        .cdm-top p  { font-size:.78rem; color:rgba(255,255,255,.6); }
        .cdm-close { background:none; border:none; color:rgba(255,255,255,.55); cursor:pointer; padding:2px; flex-shrink:0; transition:color .2s; }
        .cdm-close:hover { color:#fff; }
        .cdm-body { padding:1.5rem 1.75rem; }
        .cdm-slbl { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--gray-500); margin-bottom:.55rem; display:flex; align-items:center; gap:.4rem; }
        .cdm-grid { display:grid; grid-template-columns:1fr 1fr; gap:.55rem .75rem; margin-bottom:1.1rem; }
        .cdm-field { background:var(--gray-50); border:1px solid var(--gray-200); border-radius:8px; padding:.55rem .75rem; }
        .cdm-field .lbl { font-size:.67rem; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:var(--gray-500); margin-bottom:.12rem; }
        .cdm-field .val { font-size:.88rem; font-weight:600; color:var(--text); font-family:'Outfit',sans-serif; }
        .cdm-desc { background:var(--gray-50); border:1px solid var(--gray-200); border-radius:8px; padding:.7rem .85rem; font-size:.85rem; color:var(--gray-700); line-height:1.55; margin-bottom:1.1rem; }
        .cdm-horario { display:flex; align-items:center; gap:.5rem; background:var(--gray-50); border:1px solid var(--gray-200); border-radius:6px; padding:.45rem .7rem; font-size:.8rem; color:var(--gray-700); margin-bottom:.35rem; }
        .cdm-horario svg { color:var(--accent); flex-shrink:0; }
        .btn-ver { height:28px; padding:0 .65rem; border-radius:6px; font-family:'Outfit',sans-serif; font-size:.71rem; font-weight:700; cursor:pointer; border:1.5px solid var(--accent); color:var(--accent); background:#f0f5ff; display:inline-flex; align-items:center; gap:.3rem; transition:all .2s; white-space:nowrap; }
        .btn-ver:hover { background:var(--accent); color:#fff; }

        @media (max-width:900px) {
            .two-col { grid-template-columns:1fr; }
        }
        @media (max-width:640px) {
            .stats { grid-template-columns:1fr 1fr; }
            .sol-card-inner { grid-template-columns:1fr; }
            .sol-actions { flex-direction:row; }
            .sol-datos { grid-template-columns:1fr; }
            header { padding:0 1rem; }
        }
    </style>
</head>
<body>

<header>
    <div class="hb">
        <div class="hb-logo">UdeC</div>
        <div>
            <div class="hb-name">Panel de Plantel</div>
            <div class="hb-sub">Gestión de solicitudes</div>
        </div>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center">
        <a href="estadisticas_plantel.php" class="nav-out">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Estadísticas
        </a>
        <a href="?logout=1" class="nav-out">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Cerrar sesión
        </a>
    </div>
</header>

<div class="subhdr">
    <div class="sub-av"><?= mb_substr($plantel_nombre, 0, 1) ?></div>
    <div>
        <div class="sub-name"><?= htmlspecialchars($plantel_nombre) ?></div>
        <div class="sub-det">Panel de administración &nbsp;·&nbsp; <?= htmlspecialchars($plantel_correo) ?></div>
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

    <!-- ── CLUBS PENDIENTES DE AUTORIZACIÓN ──────────────── -->
    <?php
    $clubs_sin_autorizar = array_filter($clubs_borrador, fn($c) => $c['autorizado'] === 'no');
    $clubs_autorizados   = array_filter($clubs_borrador, fn($c) => $c['autorizado'] === 'si');
    ?>
    <?php if (!empty($clubs_borrador)): ?>
    <div style="background:#fff;border-radius:14px;box-shadow:0 4px 20px rgba(27,45,84,.09);overflow:hidden;margin-bottom:1.75rem">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid #eef0f6;display:flex;align-items:center;gap:.6rem">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>
            <span style="font-family:'Outfit',sans-serif;font-size:.95rem;font-weight:700;color:#1b2d54;flex:1">
                Clubs en Borrador — Revisión de autorización
            </span>
            <?php if (count($clubs_sin_autorizar) > 0): ?>
            <span style="background:#fff8ee;color:#d47a20;border:1px solid #f5d8a0;border-radius:20px;padding:.2rem .75rem;font-size:.74rem;font-weight:700;font-family:'Outfit',sans-serif">
                ⏳ <?= count($clubs_sin_autorizar) ?> pendiente<?= count($clubs_sin_autorizar) > 1 ? 's' : '' ?>
            </span>
            <?php endif; ?>
        </div>

        <?php foreach ($clubs_borrador as $cb): ?>
        <?php
        $ya_autorizado = $cb['autorizado'] === 'si';
        $border_color  = $ya_autorizado ? '#a5dfca' : '#f5d8a0';
        $bg_color      = $ya_autorizado ? '#f0fbf6' : '#fffcf5';
        ?>
        <div style="padding:1.1rem 1.5rem;border-bottom:1px solid #eef0f6;display:grid;grid-template-columns:1fr auto;gap:1rem;align-items:center;background:<?= $bg_color ?>;border-left:4px solid <?= $border_color ?>">
            <div>
                <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.35rem;flex-wrap:wrap">
                    <span style="font-family:'Outfit',sans-serif;font-weight:700;font-size:.95rem;color:#1e2340">
                        <?= htmlspecialchars($cb['nombre']) ?>
                    </span>
                    <span style="font-size:.7rem;font-weight:700;font-family:'Outfit',sans-serif;padding:.18rem .6rem;border-radius:20px;<?= $ya_autorizado ? 'background:#edfaf4;color:#1a5e3f;border:1px solid #a5dfca' : 'background:#fff8ee;color:#7a4f10;border:1px solid #f5d8a0' ?>">
                        <?= $ya_autorizado ? '✅ Autorizado' : '⏳ Sin autorizar' ?>
                    </span>
                    <?php if (!empty($cb['restaurado'])): ?>
                    <span style="font-size:.7rem;font-weight:700;font-family:'Outfit',sans-serif;padding:.18rem .6rem;border-radius:20px;background:#fff0f0;color:#8b2020;border:1px solid #fbd5d5">
                        ↩ Restaurado
                    </span>
                    <?php endif; ?>
                </div>
                <div style="font-size:.8rem;color:#7a8099;line-height:1.5">
                    <strong>Encargado:</strong> <?= htmlspecialchars($cb['encargado_nombre'] ?? '—') ?>
                    &nbsp;·&nbsp;
                    <strong>Semestre:</strong> <?= ucfirst($cb['semestre']) ?>
                    &nbsp;·&nbsp;
                    <strong>Cupo:</strong> <?= $cb['limite'] ?> lugares
                    <?php if ($cb['fecha_inicio']): ?>
                    &nbsp;·&nbsp;
                    <strong>Inicio:</strong> <?= date('d M Y', strtotime($cb['fecha_inicio'])) ?>
                    <?php endif; ?>
                    <?php if ($cb['fecha_limite_registro']): ?>
                    &nbsp;·&nbsp;
                    <strong>Límite registro:</strong> <?= date('d M Y', strtotime($cb['fecha_limite_registro'])) ?>
                    <?php endif; ?>
                </div>
                <?php if ($cb['descripcion']): ?>
                <div style="font-size:.79rem;color:#3d4260;margin-top:.3rem;font-style:italic">
                    <?= htmlspecialchars(mb_substr($cb['descripcion'], 0, 120)) ?><?= mb_strlen($cb['descripcion']) > 120 ? '…' : '' ?>
                </div>
                <?php endif; ?>
            </div>
            <div style="display:flex;flex-direction:column;gap:.45rem;min-width:140px">
                <button type="button" class="btn-ver" style="width:100%;margin-bottom:.15rem" onclick="abrirDetalle(<?= $cb['id'] ?>)">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    Ver detalles
                </button>
                <?php if (!$ya_autorizado): ?>
                <form method="POST" action="" onsubmit="return confirm('¿Autorizar el club «<?= htmlspecialchars(addslashes($cb['nombre'])) ?>»?')">
                    <input type="hidden" name="club_id" value="<?= $cb['id'] ?>">
                    <input type="hidden" name="autorizar_club" value="si">
                    <button type="submit" style="width:100%;height:36px;background:#2e9e6e;color:#fff;border:none;border-radius:8px;font-family:'Outfit',sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.35rem;transition:all .2s">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                        Autorizar
                    </button>
                </form>
                <form method="POST" action="" onsubmit="return confirm('¿Denegar la autorización del club «<?= htmlspecialchars(addslashes($cb['nombre'])) ?>»?')">
                    <input type="hidden" name="club_id" value="<?= $cb['id'] ?>">
                    <input type="hidden" name="autorizar_club" value="no">
                    <button type="submit" style="width:100%;height:36px;background:#fff0f0;color:#d94f4f;border:1.5px solid #fbd5d5;border-radius:8px;font-family:'Outfit',sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.35rem;transition:all .2s">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        Denegar
                    </button>
                </form>
                <?php else: ?>
                <form method="POST" action="" onsubmit="return confirm('¿Revocar la autorización de este club?')">
                    <input type="hidden" name="club_id" value="<?= $cb['id'] ?>">
                    <input type="hidden" name="autorizar_club" value="no">
                    <button type="submit" style="width:100%;height:36px;background:#f7f8fc;color:#7a8099;border:1.5px solid #c5cad8;border-radius:8px;font-family:'Outfit',sans-serif;font-size:.79rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.35rem">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        Revocar autorización
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($clubs_sin_autorizar)): ?>
        <div style="padding:1rem 1.5rem;font-size:.83rem;color:#2e9e6e">
            ✅ Todos los clubs en borrador han sido revisados.
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats">
        <a href="?filtro=pendiente" style="text-decoration:none">
        <div class="stat-card">
            <div class="si si-orange">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <div class="stat-num" style="color:var(--warning)"><?= $conteos['pendiente'] ?></div>
                <div class="stat-lbl">Pendientes de revisión</div>
            </div>
        </div>
        </a>
        <a href="?filtro=aprobado" style="text-decoration:none">
        <div class="stat-card">
            <div class="si si-green">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div>
                <div class="stat-num" style="color:var(--success)"><?= $conteos['activos'] ?></div>
                <div class="stat-lbl">Activos</div>
            </div>
        </div>
        </a>
        <a href="?filtro=rechazado" style="text-decoration:none">
        <div class="stat-card">
            <div class="si si-red">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            <div>
                <div class="stat-num" style="color:var(--error)"><?= $conteos['rechazado'] ?></div>
                <div class="stat-lbl">Rechazados</div>
            </div>
        </div>
        </a>
    </div>

    <!-- FILTROS -->
    <div class="filtros">
        <span>Ver:</span>
        <a href="?filtro=pendiente"  class="filtro-btn p <?= $filtro==='pendiente'  ? 'active' : '' ?>">⏳ Pendientes (<?= $conteos['pendiente'] ?>)</a>
        <a href="?filtro=aprobado"   class="filtro-btn a <?= $filtro==='aprobado'   ? 'active' : '' ?>">✅ Activos (<?= $conteos['aprobado'] ?>)</a>
        <a href="?filtro=rechazado"  class="filtro-btn r <?= $filtro==='rechazado'  ? 'active' : '' ?>">❌ Rechazados (<?= $conteos['rechazado'] ?>)</a>
        <a href="?filtro=todos"      class="filtro-btn t <?= $filtro==='todos'       ? 'active' : '' ?>">Todos</a>
    </div>

    <!-- GRID 2 COLUMNAS: solicitudes | clubs -->
    <div class="two-col">
    <!-- ═══ COLUMNA 1: SOLICITUDES ═══ -->
    <div>
    <div class="sol-grid">
    <?php if (empty($solicitudes)): ?>
        <div class="empty">
            <svg width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <p>No hay solicitudes <?= $filtro !== 'todos' ? htmlspecialchars($filtro.'s') : '' ?></p>
            <span>Las nuevas solicitudes aparecerán aquí automáticamente</span>
        </div>
    <?php else: foreach ($solicitudes as $idx => $s):
        $iniciales = mb_substr($s['nombres'],0,1) . mb_substr($s['apellido_paterno'],0,1);
        $av_class  = $s['estado']==='pendiente' ? 'av-orange' : ($s['estado']==='aprobado' ? 'av-green' : 'av-red');
        $nombre_c  = $s['apellido_paterno'].' '.$s['apellido_materno'].' '.$s['nombres'];
    ?>
        <div class="sol-card" style="animation-delay:<?= $idx*0.05 ?>s">
            <div class="sol-card-inner">
                <div>
                    <div class="sol-header">
                        <div class="sol-avatar <?= $av_class ?>"><?= htmlspecialchars($iniciales) ?></div>
                        <div>
                            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                                <span class="sol-nombre"><?= htmlspecialchars($nombre_c) ?></span>
                                <?php
                                $bc = $s['estado']==='pendiente' ? 'badge-p' : ($s['estado']==='aprobado' ? 'badge-a' : 'badge-r');
                                $esActivo = ($s['estado']==='aprobado' && $s['enc_activo']);
                                $bl = [
                                    'pendiente' => 'Pendiente',
                                    'aprobado'  => $esActivo ? 'Activo' : 'Desactivado',
                                    'rechazado' => 'Rechazado',
                                ];
                                ?>
                                <span class="badge <?= $bc ?>"><span class="dot"></span><?= $bl[$s['estado']] ?></span>
                            </div>
                            <div class="sol-fecha">Enviada el <?= $s['fecha_fmt'] ?></div>
                        </div>
                    </div>

                    <div class="sol-datos">
                        <div class="sol-dato">
                            <div class="lbl">Tipo</div>
                            <div class="val"><?= htmlspecialchars($s['tipo']) ?></div>
                        </div>
                        <div class="sol-dato">
                            <div class="lbl"><?= $s['tipo']==='Estudiante' ? 'Núm. cuenta' : 'Núm. trabajador' ?></div>
                            <div class="val"><?= htmlspecialchars($s['numero_trabajador']) ?></div>
                        </div>
                        <div class="sol-dato">
                            <div class="lbl">Correo</div>
                            <div class="val"><?= htmlspecialchars($s['correo']) ?></div>
                        </div>
                        <?php if ($s['telefono']): ?>
                        <div class="sol-dato">
                            <div class="lbl">Teléfono</div>
                            <div class="val"><?= htmlspecialchars($s['telefono']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Botones de acción -->
                <div class="sol-actions">
                    <?php if ($s['estado'] === 'pendiente'): ?>
                    <!-- Pendiente: Aprobar y activar / Rechazar y desactivar -->
                    <form method="POST" action=""
                        onsubmit="return confirm('¿Aprobar y activar a <?= htmlspecialchars(addslashes($nombre_c)) ?>?')">
                        <input type="hidden" name="sol_id" value="<?= $s['id'] ?>">
                        <input type="hidden" name="accion"  value="aprobar">
                        <button type="submit" class="btn-aprobar" style="white-space:normal;text-align:center;height:auto;padding:.55rem .85rem;line-height:1.3;font-size:.78rem">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                            Aprobar y activar encargado
                        </button>
                    </form>
                    <form method="POST" action=""
                        onsubmit="return confirm('¿Rechazar a <?= htmlspecialchars(addslashes($nombre_c)) ?>?')">
                        <input type="hidden" name="sol_id" value="<?= $s['id'] ?>">
                        <input type="hidden" name="accion"  value="rechazar">
                        <button type="submit" class="btn-rechazar" style="white-space:normal;text-align:center;height:auto;padding:.55rem .85rem;line-height:1.3;font-size:.78rem">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            Rechazar y desactivar encargado
                        </button>
                    </form>

                    <?php elseif ($s['estado'] === 'aprobado'): ?>
                    <!-- Aprobado: Switch activo/inactivo -->
                    <?php $activo = (bool)$s['enc_activo']; ?>
                    <div style="display:flex;flex-direction:column;align-items:center;gap:.5rem">
                        <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-500)">Estado</span>
                        <form method="POST" action=""
                            onsubmit="return confirm('<?= $activo ? '¿Desactivar a ' : '¿Activar a ' ?><?= htmlspecialchars(addslashes($nombre_c)) ?>?')">
                            <input type="hidden" name="toggle_encargado" value="1">
                            <input type="hidden" name="enc_numero"  value="<?= (int)$s['numero_trabajador'] ?>">
                            <input type="hidden" name="nuevo_activo" value="<?= $activo ? 0 : 1 ?>">
                            <button type="submit" style="
                                display:flex;align-items:center;gap:.5rem;
                                height:38px;padding:0 1rem;border-radius:20px;
                                border:2px solid <?= $activo ? '#2e9e6e' : '#d94f4f' ?>;
                                background:<?= $activo ? '#edfaf4' : '#fff0f0' ?>;
                                color:<?= $activo ? '#1a5e3f' : '#8b2020' ?>;
                                font-family:'Outfit',sans-serif;font-size:.8rem;font-weight:700;
                                cursor:pointer;transition:all .2s;white-space:nowrap">
                                <!-- Switch pill -->
                                <span style="
                                    width:36px;height:20px;border-radius:10px;
                                    background:<?= $activo ? '#2e9e6e' : '#d94f4f' ?>;
                                    position:relative;display:inline-block;flex-shrink:0;
                                    transition:background .2s">
                                    <span style="
                                        position:absolute;top:3px;
                                        left:<?= $activo ? '18px' : '3px' ?>;
                                        width:14px;height:14px;border-radius:50%;
                                        background:#fff;transition:left .2s"></span>
                                </span>
                                <?= $activo ? 'Activo' : 'Inactivo' ?>
                            </button>
                        </form>
                        <span style="font-size:.68rem;color:var(--gray-500)">
                            <?= $activo ? 'Clic para desactivar' : 'Clic para reactivar' ?>
                        </span>
                    </div>

                    <?php else: ?>
                    <!-- Rechazado: solo etiqueta -->
                    <button class="btn-done" disabled>✕ Rechazado</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; endif; ?>
    </div><!-- /sol-grid -->
    </div><!-- /col solicitudes -->

    <!-- ═══ COLUMNA 2: CLUBS DEL PLANTEL ═══ -->
    <div>
        <div class="col-clubs-header">
            <h3>
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Clubs del plantel
                <span style="background:var(--gray-100);border-radius:20px;padding:.1rem .55rem;font-size:.7rem;font-weight:600;color:var(--gray-500)"><?= count($todos_clubs) ?></span>
            </h3>
            <?php if ($conteos_clubs['pendiente_auth'] > 0): ?>
            <span style="font-size:.72rem;font-weight:700;background:#fff8ee;color:#7a4f10;border:1px solid #f5d8a0;border-radius:20px;padding:.2rem .65rem">
                ⏳ <?= $conteos_clubs['pendiente_auth'] ?> por autorizar
            </span>
            <?php endif; ?>
        </div>

        <!-- Resumen por estado -->
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem">
            <?php
            $est_cfg = [
                'borrador'  => ['lbl'=>'Borrador',   'c'=>'#5a4fcf','bg'=>'#f0f0ff'],
                'apertura'  => ['lbl'=>'Apertura',   'c'=>'#2a5ea8','bg'=>'#e8f4ff'],
                'iniciado'  => ['lbl'=>'Iniciado',   'c'=>'#1d6344','bg'=>'#edfaf4'],
                'finalizado'=> ['lbl'=>'Finalizado', 'c'=>'#7a8099','bg'=>'#f0f0f6'],
                'cancelado' => ['lbl'=>'Cancelado',  'c'=>'#8b2020','bg'=>'#fff0f0'],
            ];
            foreach ($est_cfg as $ek => $ev): if ($conteos_clubs[$ek] > 0): ?>
            <span style="font-size:.7rem;font-weight:700;background:<?= $ev['bg'] ?>;color:<?= $ev['c'] ?>;border-radius:20px;padding:.18rem .6rem">
                <?= $ev['lbl'] ?>: <?= $conteos_clubs[$ek] ?>
            </span>
            <?php endif; endforeach; ?>
        </div>

        <?php if (empty($todos_clubs)): ?>
        <div style="text-align:center;padding:2rem 1rem;color:var(--gray-500);background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow)">
            <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto .75rem;opacity:.4"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            <p style="font-size:.85rem;font-weight:600">Sin clubs registrados</p>
        </div>
        <?php else: ?>

        <?php
        $bar_cols = ['#4a7fd4','#2e9e6e','#d47a20','#1b2d54','#7b5ea7'];
        foreach ($todos_clubs as $ci => $cl):
            $est  = $cl['estado'] ?? 'borrador';
            $auth = $cl['autorizado'] ?? 'no';
            $bar_c = $bar_cols[$ci % count($bar_cols)];
            $ins  = (int)$cl['inscritos'];
            $lim  = (int)$cl['limite'];
            $pct  = $lim > 0 ? round($ins/$lim*100) : 0;
        ?>
        <div class="club-row" style="animation-delay:<?= $ci*0.04 ?>s">
            <div class="club-row-bar" style="background:<?= $bar_c ?>"></div>
            <div class="club-row-body">
                <div>
                    <!-- Nombre + estado + autorización -->
                    <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin-bottom:.3rem">
                        <span class="club-row-nombre"><?= htmlspecialchars($cl['nombre']) ?></span>
                        <span class="est-badge est-<?= $est ?>">
                            <span class="est-dot"></span><?= ucfirst($est) ?>
                        </span>
                        <?php if ($est === 'borrador'): ?>
                        <span style="font-size:.67rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;
                            <?= $auth==='si'
                                ? 'background:#edfaf4;color:#1d6344;border:1px solid #a5dfca'
                                : 'background:#fff8ee;color:#7a4f10;border:1px solid #f5d8a0' ?>">
                            <?= $auth==='si' ? '✓ Autorizado' : '⏳ Sin autorizar' ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="club-row-enc">
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <?= htmlspecialchars($cl['encargado_nombre'] ?? 'Sin encargado') ?>
                    </div>

                    <div class="club-row-meta">
                        <span class="club-chip-sm">
                            <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <?= date('d M', strtotime($cl['fecha_inicio'])) ?> – <?= date('d M Y', strtotime($cl['fecha_fin'])) ?>
                        </span>
                        <span class="club-chip-sm">
                            <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                            <?= $ins ?>/<?= $lim ?>
                        </span>
                        <span class="club-chip-sm">
                            <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/></svg>
                            <?= ucfirst($cl['semestre']) ?>
                        </span>
                    </div>

                    <!-- Barra de cupo -->
                    <div style="height:3px;background:var(--gray-100);border-radius:3px;margin-top:.5rem;overflow:hidden">
                        <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct>=100?'#d94f4f':($pct>=70?'#d47a20':'#2e9e6e') ?>;border-radius:3px"></div>
                    </div>
                </div>

                <!-- Acciones del plantel sobre el club -->
                <div class="club-row-actions">
                    <button type="button" class="btn-ver" onclick="abrirDetalle(<?= $cl['id'] ?>)">
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        Ver info
                    </button>
                    <?php if (in_array($est, ['iniciado','finalizado','apertura'], true)): ?>
                    <a href="estadisticas_plantel.php?id_club=<?= $cl['id'] ?>&parcial=0"
                       class="btn-ver" style="justify-content:center">
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                        Estadísticas
                    </a>
                    <?php endif; ?>
                    <?php if ($est === 'borrador'): ?>
                        <?php if ($auth === 'no'): ?>
                        <form method="POST" action=""
                            onsubmit="return confirm('¿Autorizar «<?= htmlspecialchars(addslashes($cl['nombre'])) ?>»?')">
                            <input type="hidden" name="autorizar_club" value="si">
                            <input type="hidden" name="club_id" value="<?= $cl['id'] ?>">
                            <button type="submit" class="btn-auth btn-auth-si">
                                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                                Autorizar
                            </button>
                        </form>
                        <form method="POST" action=""
                            onsubmit="return confirm('¿Denegar autorización de «<?= htmlspecialchars(addslashes($cl['nombre'])) ?>»?')">
                            <input type="hidden" name="autorizar_club" value="no">
                            <input type="hidden" name="club_id" value="<?= $cl['id'] ?>">
                            <button type="submit" class="btn-auth btn-auth-no">
                                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                Denegar
                            </button>
                        </form>
                        <?php else: ?>
                        <form method="POST" action=""
                            onsubmit="return confirm('¿Revocar la autorización de «<?= htmlspecialchars(addslashes($cl['nombre'])) ?>»?')">
                            <input type="hidden" name="autorizar_club" value="no">
                            <input type="hidden" name="club_id" value="<?= $cl['id'] ?>">
                            <button type="submit" class="btn-auth btn-auth-no" style="font-size:.68rem">
                                Revocar
                            </button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                    <span style="font-size:.7rem;color:var(--gray-500);text-align:right;line-height:1.4">
                        <?= ucfirst($est) ?>
                    </span>
                    <?php endif; ?>
                </div>

            </div><!-- /club-row-body -->
        </div><!-- /club-row -->
        <?php endforeach; ?>
        <?php endif; ?>

    </div><!-- /col clubs -->
    </div><!-- /two-col -->

</div><!-- /page -->

<footer>© <?= date('Y') ?> Universidad de Colima · <?= htmlspecialchars($plantel_nombre) ?> · Sistema de Clubes Estudiantiles</footer>

<!-- MODAL DETALLE CLUB -->
<div class="cdm-ov" id="cdm-ov">
    <div class="cdm-wrap">
        <div class="cdm-top">
            <div>
                <h3 id="cdm-nombre">—</h3>
                <p id="cdm-enc">—</p>
            </div>
            <button class="cdm-close" onclick="cerrarDetalle()">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="cdm-body">

            <!-- Badges estado + autorización -->
            <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1.1rem" id="cdm-badges"></div>

            <!-- Descripción -->
            <div class="cdm-slbl">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                Descripción
            </div>
            <div class="cdm-desc" id="cdm-desc">—</div>

            <!-- Datos del club -->
            <div class="cdm-slbl">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Información
            </div>
            <div class="cdm-grid">
                <div class="cdm-field"><div class="lbl">Semestres</div><div class="val" id="cdm-sem">—</div></div>
                <div class="cdm-field"><div class="lbl">Cupo</div><div class="val" id="cdm-cupo">—</div></div>
                <div class="cdm-field"><div class="lbl">Fecha inicio</div><div class="val" id="cdm-fi">—</div></div>
                <div class="cdm-field"><div class="lbl">Fecha fin</div><div class="val" id="cdm-ff">—</div></div>
                <div class="cdm-field" id="cdm-flim-wrap"><div class="lbl">Límite registro</div><div class="val" id="cdm-flim">—</div></div>
            </div>

            <!-- Horarios -->
            <div class="cdm-slbl">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Horarios
            </div>
            <div id="cdm-horarios"></div>

        </div>
    </div>
</div>

<script>
const _clubes   = <?= json_encode(array_combine(array_column($todos_clubs, 'id'), array_values($todos_clubs))) ?>;
const _horarios = <?= json_encode($horarios_plantel) ?>;

const EST_STYLE = {
    borrador:   { bg:'#f0f0ff', c:'#5a4fcf' },
    apertura:   { bg:'#e8f4ff', c:'#2a5ea8' },
    iniciado:   { bg:'#edfaf4', c:'#1d6344' },
    finalizado: { bg:'#f0f0f6', c:'#7a8099' },
    cancelado:  { bg:'#fff0f0', c:'#8b2020' },
};

function abrirDetalle(id) {
    const c = _clubes[id];
    if (!c) return;

    document.getElementById('cdm-nombre').textContent = c.nombre;
    document.getElementById('cdm-enc').textContent    = 'Encargado: ' + (c.encargado_nombre || '—');

    // Badges
    const es  = EST_STYLE[c.estado] || { bg:'#f0f0f6', c:'#7a8099' };
    let badges = `<span style="padding:.25rem .8rem;border-radius:20px;font-size:.75rem;font-weight:700;font-family:'Outfit',sans-serif;background:${es.bg};color:${es.c};border:1px solid ${es.c}33">
                    ${c.estado.charAt(0).toUpperCase()+c.estado.slice(1)}</span>`;
    if (c.estado === 'borrador') {
        const ok = c.autorizado === 'si';
        badges += ok
            ? `<span style="padding:.25rem .8rem;border-radius:20px;font-size:.75rem;font-weight:700;font-family:'Outfit',sans-serif;background:#edfaf4;color:#1d6344;border:1px solid #a5dfca">✅ Autorizado</span>`
            : `<span style="padding:.25rem .8rem;border-radius:20px;font-size:.75rem;font-weight:700;font-family:'Outfit',sans-serif;background:#fff8ee;color:#7a4f10;border:1px solid #f5d8a0">⏳ Sin autorizar</span>`;
    }
    document.getElementById('cdm-badges').innerHTML = badges;

    document.getElementById('cdm-desc').textContent = c.descripcion || '—';
    document.getElementById('cdm-sem').textContent  = c.semestre ? c.semestre.charAt(0).toUpperCase()+c.semestre.slice(1) : '—';
    document.getElementById('cdm-cupo').textContent = c.inscritos + ' / ' + c.limite + ' inscritos';
    document.getElementById('cdm-fi').textContent   = c.fecha_inicio || '—';
    document.getElementById('cdm-ff').textContent   = c.fecha_fin    || '—';

    const flimWrap = document.getElementById('cdm-flim-wrap');
    if (c.fecha_limite_registro) {
        flimWrap.style.display = '';
        document.getElementById('cdm-flim').textContent = c.fecha_limite_registro;
    } else {
        flimWrap.style.display = 'none';
    }

    // Horarios
    const hrs = _horarios[id] || [];
    document.getElementById('cdm-horarios').innerHTML = hrs.length === 0
        ? '<p style="font-size:.8rem;color:var(--gray-500)">Sin horarios registrados</p>'
        : hrs.map(h => `
            <div class="cdm-horario">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <strong>${h.dia}</strong>&nbsp; ${h.ini} – ${h.fin}
            </div>`).join('');

    document.getElementById('cdm-ov').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function cerrarDetalle() {
    document.getElementById('cdm-ov').classList.remove('open');
    document.body.style.overflow = '';
}

document.getElementById('cdm-ov').addEventListener('click', e => {
    if (e.target === document.getElementById('cdm-ov')) cerrarDetalle();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarDetalle(); });
</script>

</body>
</html>
