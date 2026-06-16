<?php
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['encargado_id'])) { header('Location: login_encargado.php'); exit; }
if (isset($_GET['logout'])) { session_destroy(); header('Location: login_encargado.php'); exit; }

$enc_id  = (int)$_SESSION['encargado_id'];
$enc = [
    'id'      => $enc_id,
    'nombre'  => $_SESSION['encargado_nombre']  ?? 'Encargado',
    'tipo'    => $_SESSION['encargado_tipo']    ?? '',
    'plantel' => $_SESSION['encargado_plantel'] ?? '',
];
$partes_enc = explode(' ', $enc['nombre']);
$iniciales_enc = '';
foreach (array_slice($partes_enc, 0, 2) as $p) $iniciales_enc .= mb_substr($p, 0, 1);

// ── Cargar datos del club desde la URL ─────────────────────────
$club_id = (int)($_GET['club'] ?? 0);

// Si no viene un club válido, redirigir a mis clubs
if (!$club_id) {
    header('Location: mis_clubes.php');
    exit;
}

$club     = null;
$horarios = [];
$inscritos_count = 0;

try {
    $pdo = getDB();

    // Club completo — permite acceso al encargado principal O a un auxiliar
    $stmt = $pdo->prepare("
        SELECT c.id, c.nombre, c.descripcion, c.estado, c.fecha_inicio, c.fecha_fin,
               c.limite, c.semestre, c.autorizado,
               COUNT(DISTINCT ic_cnt.numero_cuenta) AS inscritos,
               pl.nombre AS plantel_nombre,
               CASE WHEN c.id_encargado = ? THEN 0 ELSE 1 END AS es_auxiliar
        FROM clubes c
        LEFT JOIN inscripciones_club ic_cnt ON ic_cnt.id_club = c.id
        LEFT JOIN planteles pl  ON pl.id = c.id_plantel
        LEFT JOIN auxiliares_club ac ON ac.id_club = c.id AND ac.id_persona = ?
        WHERE c.id = ?
          AND (c.id_encargado = ? OR ac.id_persona IS NOT NULL)
        GROUP BY c.id
    ");
    $stmt->execute([$enc_id, $enc_id, $club_id, $enc_id]);
    $club = $stmt->fetch();

    if (!$club) {
        header('Location: mis_clubes.php');
        exit;
    }

    $es_auxiliar = (bool)$club['es_auxiliar'];

    if (in_array($club['estado'], ['borrador', 'apertura'])) {
        // Los auxiliares no pueden editar; el principal sí
        header($es_auxiliar
            ? 'Location: mis_clubes.php'
            : 'Location: editar_club.php?id=' . $club_id
        );
        exit;
    }

    $inscritos_count = (int)$club['inscritos'];

    // Horarios del club
    $h_stmt = $pdo->prepare("
        SELECT dia, TIME_FORMAT(hora_inicio,'%H:%i') AS ini,
               TIME_FORMAT(hora_fin,'%H:%i') AS fin
        FROM horarios WHERE id_club = ?
        ORDER BY FIELD(dia,'Lunes','Martes','Miércoles','Jueves','Viernes','Sábado')
    ");
    $h_stmt->execute([$club_id]);
    $horarios = $h_stmt->fetchAll();

} catch (Exception $e) {
    error_log('asistencias.php BD: ' . $e->getMessage());
    header('Location: mis_clubes.php');
    exit;
}

// Badge y color del estado
$estado_labels = [
    'borrador'  => 'Borrador',
    'apertura'  => 'Apertura',
    'iniciado'  => 'Iniciado',
    'finalizado'=> 'Finalizado',
    'cancelado' => 'Cancelado',
];
$estado_actual  = $club['estado'] ?? 'borrador';
$hoy_dia_nombre = ['1'=>'Lunes','2'=>'Martes','3'=>'Miércoles','4'=>'Jueves','5'=>'Viernes','6'=>'Sábado','7'=>'Domingo'][date('N')] ?? '';
$horario_hoy    = null;
foreach ($horarios as $h) {
    if ($h['dia'] === $hoy_dia_nombre) { $horario_hoy = $h; break; }
}
$mostrar_qr = ($estado_actual === 'iniciado') && ($horario_hoy !== null);

// ── Alumnos inscritos + conteo de asistencias ────────────────────
$alumnos_lista  = [];
$log_hoy        = [];
$total_sesiones = 0;

try {
    $astmt = $pdo->prepare("
        SELECT e.id, e.cuenta, e.nombre_completo, e.correo,
               COUNT(a.id)                                                  AS total_asistencias,
               MAX(a.fecha)                                                 AS ultima_fecha,
               SUM(CASE WHEN a.fecha = CURDATE() THEN 1 ELSE 0 END)        AS asistio_hoy,
               MAX(CASE WHEN a.fecha = CURDATE() THEN a.hora_entrada END)   AS entrada_hoy,
               MAX(CASE WHEN a.fecha = CURDATE() THEN a.hora_salida  END)   AS salida_hoy
        FROM inscripciones_club ic
        JOIN estudiantes e ON e.cuenta = ic.numero_cuenta
        LEFT JOIN asistencias_club a ON a.id_estudiante = e.id AND a.id_club = :cid
        WHERE ic.id_club = :cid2
        GROUP BY e.id
        ORDER BY e.nombre_completo
    ");
    $astmt->execute([':cid' => $club_id, ':cid2' => $club_id]);
    $alumnos_lista = $astmt->fetchAll();

    // Días con al menos una sesión registrada
    $ds = $pdo->prepare("SELECT COUNT(DISTINCT fecha) AS n FROM asistencias_club WHERE id_club = ?");
    $ds->execute([$club_id]);
    $total_sesiones = (int)($ds->fetch()['n'] ?? 0);

    // Log de hoy para el sidebar
    $ls = $pdo->prepare("
        SELECT e.nombre_completo, e.cuenta, a.hora_entrada, a.hora_salida
        FROM asistencias_club a
        JOIN estudiantes e ON e.id = a.id_estudiante
        WHERE a.id_club = ? AND a.fecha = CURDATE()
        ORDER BY COALESCE(a.hora_salida, a.hora_entrada) DESC
        LIMIT 12
    ");
    $ls->execute([$club_id]);
    $log_hoy = $ls->fetchAll();

} catch (Exception $e) {
    error_log('asistencias carga: ' . $e->getMessage());
}

// ── Auxiliares del club (solo el encargado principal los gestiona) ───────────
$aux_msg_ok  = '';
$aux_msg_err = '';
$auxiliares  = [];

if (!$es_auxiliar) {

    // POST: agregar auxiliar
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_auxiliar'])) {
        $num_aux = (int)trim($_POST['num_trabajador_aux'] ?? '');
        if (!$num_aux) {
            $aux_msg_err = 'Ingresa un número de trabajador válido.';
        } elseif ($num_aux === $enc_id) {
            $aux_msg_err = 'No puedes agregarte a ti mismo como auxiliar.';
        } else {
            try {
                // Verificar que existe en personas + encargados del mismo plantel
                $chkAux = $pdo->prepare("
                    SELECT p.id, p.nombres, p.apellido_paterno
                    FROM personas p
                    JOIN encargados en2 ON en2.id_persona = p.id
                      AND en2.id_plantel = (SELECT id_plantel FROM clubes WHERE id = ?)
                    WHERE p.id = ?
                ");
                $chkAux->execute([$club_id, $num_aux]);
                $persona_aux = $chkAux->fetch();

                if (!$persona_aux) {
                    $aux_msg_err = 'No se encontró un encargado con ese número en este plantel.';
                } else {
                    // Verificar que no sea el encargado principal del club
                    $esEnc = $pdo->prepare("SELECT id FROM clubes WHERE id = ? AND id_encargado = ?");
                    $esEnc->execute([$club_id, $num_aux]);
                    if ($esEnc->fetch()) {
                        $aux_msg_err = 'Esa persona ya es el encargado principal de este club.';
                    } else {
                        $pdo->prepare("
                            INSERT IGNORE INTO auxiliares_club (id_club, id_persona, agregado_por)
                            VALUES (?, ?, ?)
                        ")->execute([$club_id, $num_aux, $enc_id]);
                        $nom = htmlspecialchars($persona_aux['nombres'] . ' ' . $persona_aux['apellido_paterno']);
                        $aux_msg_ok = "Auxiliar <strong>$nom</strong> (N.° $num_aux) agregado correctamente.";
                    }
                }
            } catch (Exception $e) {
                $aux_msg_err = 'Error al agregar. Verifica que la tabla auxiliares_club exista.';
            }
        }
    }

    // POST: eliminar auxiliar
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_auxiliar'])) {
        $aux_row_id = (int)($_POST['aux_id'] ?? 0);
        if ($aux_row_id) {
            try {
                $pdo->prepare("DELETE FROM auxiliares_club WHERE id = ? AND id_club = ?")
                    ->execute([$aux_row_id, $club_id]);
                $aux_msg_ok = 'Auxiliar eliminado del club.';
            } catch (Exception $e) {
                $aux_msg_err = 'Error al eliminar auxiliar.';
            }
        }
    }

    // Cargar lista actual de auxiliares
    try {
        $ax_stmt = $pdo->prepare("
            SELECT ac.id, ac.id_persona, ac.creado_en,
                   p.nombres, p.apellido_paterno, p.apellido_materno, p.correo
            FROM auxiliares_club ac
            JOIN personas p ON p.id = ac.id_persona
            WHERE ac.id_club = ?
            ORDER BY ac.creado_en
        ");
        $ax_stmt->execute([$club_id]);
        $auxiliares = $ax_stmt->fetchAll();
    } catch (Exception $e) { /* tabla puede no existir aún */ }
}

// ── Historial de un alumno vía AJAX ─────────────────────────────
if (isset($_GET['historial']) && ctype_digit($_GET['historial'])) {
    header('Content-Type: application/json');
    try {
        $hs = $pdo->prepare("
            SELECT a.fecha, a.hora_entrada, a.hora_salida, a.estado
            FROM asistencias_club a
            WHERE a.id_club = ? AND a.id_estudiante = ?
            ORDER BY a.fecha DESC
        ");
        $hs->execute([$club_id, (int)$_GET['historial']]);
        echo json_encode($hs->fetchAll());
    } catch (Exception $e) { echo '[]'; }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asistencias — <?= htmlspecialchars($club['nombre']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:       #1b2d54;
            --navy-light: #243567;
            --accent:     #4a7fd4;
            --accent-h:   #3568bf;
            --success:    #2e9e6e;
            --warning:    #d47a20;
            --white:      #ffffff;
            --gray-50:    #f7f8fc;
            --gray-100:   #eef0f6;
            --gray-200:   #e0e4f0;
            --gray-300:   #c5cad8;
            --gray-500:   #7a8099;
            --gray-700:   #3d4260;
            --text:       #1e2340;
            --error:      #d94f4f;
            --radius:     14px;
            --radius-sm:  8px;
            --shadow:     0 4px 20px rgba(27,45,84,.09);
            --shadow-lg:  0 10px 36px rgba(27,45,84,.14);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "DM Sans", sans-serif; background: var(--gray-50); min-height: 100vh; color: var(--text); }

        /* HEADER */
        header { background: var(--navy); height: 64px; display: flex; align-items: center; justify-content: space-between; padding: 0 2rem; box-shadow: 0 2px 10px rgba(0,0,0,.25); position: sticky; top: 0; z-index: 50; }
        .hb { display: flex; align-items: center; gap: .75rem; }
        .hb-logo { width:40px; height:40px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-family:"Outfit",sans-serif; font-weight:700; font-size:.75rem; color:var(--navy); }
        .hb-name { font-family:"Outfit",sans-serif; font-size:1.05rem; font-weight:600; color:#fff; }
        .hb-sub  { font-size:.7rem; color:rgba(255,255,255,.5); text-transform:uppercase; letter-spacing:.8px; }
        nav { display:flex; align-items:center; gap:.2rem; }
        nav a { color:rgba(255,255,255,.75); text-decoration:none; font-size:.82rem; font-weight:500; padding:.4rem .75rem; border-radius:var(--radius-sm); display:flex; align-items:center; gap:.35rem; transition:all .2s; }
        nav a:hover, nav a.active { color:#fff; background:rgba(255,255,255,.12); }
        .nav-out { color:rgba(255,255,255,.65)!important; border:1px solid rgba(255,255,255,.2)!important; margin-left:.5rem; }
        .nav-out:hover { background:rgba(255,255,255,.1)!important; }

        /* SUBHEADER */
        .subhdr { background:var(--navy-light); padding:.6rem 2rem; display:flex; align-items:center; gap:.75rem; }
        .sub-av { width:34px; height:34px; border-radius:50%; background:var(--accent); display:flex; align-items:center; justify-content:center; font-family:"Outfit",sans-serif; font-weight:700; font-size:.75rem; color:#fff; }
        .sub-name { font-family:"Outfit",sans-serif; font-weight:600; font-size:.9rem; color:#fff; }
        .sub-det  { font-size:.72rem; color:rgba(255,255,255,.6); }

        /* PAGE */
        .page { max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }

        /* BREADCRUMB */
        .breadcrumb { display:flex; align-items:center; gap:.4rem; font-size:.78rem; color:var(--gray-500); margin-bottom:1.5rem; flex-wrap:wrap; }
        .breadcrumb a { color:var(--accent); text-decoration:none; } .breadcrumb a:hover { text-decoration:underline; }
        .breadcrumb svg { color:var(--gray-300); }

        /* CLUB INFO CARD */
        .club-info-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); overflow:hidden; margin-bottom:1.75rem; animation:fadeUp .3s ease both; }
        .club-info-bar  { height:5px; background:linear-gradient(90deg,var(--accent),#7aa8e8); }
        .club-info-body { padding:1.25rem 1.75rem; display:flex; align-items:center; justify-content:space-between; gap:1.5rem; flex-wrap:wrap; }
        .club-info-left { display:flex; align-items:center; gap:1rem; }
        .club-avatar { width:50px; height:50px; border-radius:12px; background:linear-gradient(135deg,var(--accent),#7aa8e8); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .club-nombre { font-family:"Outfit",sans-serif; font-size:1.15rem; font-weight:700; color:var(--navy); }
        .club-meta { display:flex; align-items:center; gap:.75rem; margin-top:.3rem; flex-wrap:wrap; }
        .club-chip { display:inline-flex; align-items:center; gap:.3rem; font-size:.73rem; color:var(--gray-500); }
        .club-chip svg { color:var(--gray-300); }
        .status-activo, .status-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .65rem; border-radius:20px; font-size:.7rem; font-weight:600; font-family:"Outfit",sans-serif; }
        .status-activo     { background:#e8f7f0; color:#1d6344; }
        .status-activo .dot{ width:6px; height:6px; border-radius:50%; background:var(--success); }
        .status-badge .dot { width:6px; height:6px; border-radius:50%; background:currentColor; }
        .status-borrador   { background:#f0f0ff; color:#5a4fcf; }
        .status-apertura   { background:#e8f4ff; color:#2a5ea8; }
        .status-finalizado { background:#f0f0f6; color:var(--gray-500); }
        .status-cancelado  { background:#fff0f0; color:#8b2020; }

        .fill-blue  { background:var(--accent); }
        .fill-green { background:var(--success); }
        .fill-orange{ background:var(--warning); }
        .fill-red   { background:var(--error); }

        /* MAIN LAYOUT */
        .main-layout { display:grid; grid-template-columns:1fr 280px; gap:1.5rem; align-items:start; }

        /* SECCIÓN DE TABLA */
        .section-label { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:var(--gray-500); margin-bottom:.75rem; display:flex; align-items:center; gap:.4rem; }
        .section-label::after { content:""; flex:1; height:1px; background:var(--gray-200); }

        .buscador-wrap { position:relative; margin-bottom:1.1rem; }
        .buscador-wrap svg { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); pointer-events:none; color:var(--gray-400); }
        .buscador-input { width:100%; box-sizing:border-box; padding:.5rem .9rem .5rem 2.25rem; border:1.5px solid var(--gray-200); border-radius:8px; font-family:"Outfit",sans-serif; font-size:.83rem; color:var(--text); background:var(--white); transition:border-color .2s,box-shadow .2s; outline:none; }
        .buscador-input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(74,127,212,.12); }
        .buscador-input::placeholder { color:var(--gray-400); }

        /* ATTENDANCE TABLE CARD */
        .table-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); overflow:hidden; animation:fadeUp .35s .1s ease both; }
        .table-card-header { padding:1rem 1.4rem; border-bottom:1px solid var(--gray-100); display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
        .table-card-header h3 { font-family:"Outfit",sans-serif; font-size:.95rem; font-weight:700; display:flex; align-items:center; gap:.5rem; }
        .header-actions { display:flex; align-items:center; gap:.6rem; }

        .btn-export { height:34px; padding:0 .9rem; background:var(--gray-50); border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.78rem; font-weight:600; color:var(--gray-700); cursor:pointer; display:flex; align-items:center; gap:.35rem; transition:all .2s; text-decoration:none; }
        .btn-export:hover { background:var(--gray-100); border-color:var(--gray-300); }

        .btn-qr { height:34px; padding:0 1rem; background:var(--accent); border:none; border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.78rem; font-weight:600; color:#fff; cursor:pointer; display:flex; align-items:center; gap:.35rem; transition:all .2s; box-shadow:0 2px 8px rgba(74,127,212,.3); }
        .btn-qr:hover { background:var(--accent-h); transform:translateY(-1px); }

        /* SESSION SELECTOR */
        .session-bar { padding:.65rem 1.4rem; background:var(--gray-50); border-bottom:1px solid var(--gray-100); display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
        .session-label { font-size:.73rem; font-weight:600; color:var(--gray-500); text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }
        .session-chip { padding:.25rem .75rem; border-radius:20px; font-family:"Outfit",sans-serif; font-size:.75rem; font-weight:600; cursor:pointer; border:1.5px solid var(--gray-200); background:var(--white); color:var(--gray-700); transition:all .2s; white-space:nowrap; }
        .session-chip:hover { border-color:var(--accent); color:var(--accent); }
        .session-chip.active { background:var(--navy); color:#fff; border-color:var(--navy); }
        .session-date { font-size:.72rem; color:var(--gray-500); margin-left:auto; white-space:nowrap; }

        /* TABLE */
        .tbl-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        thead tr { background:var(--gray-50); border-bottom:1.5px solid var(--gray-200); }
        thead th { padding:.7rem 1rem; text-align:left; font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:var(--gray-500); white-space:nowrap; }
        thead th:last-child { text-align:center; }
        tbody tr { border-bottom:1px solid var(--gray-100); transition:background .15s; }
        tbody tr:last-child { border-bottom:none; }
        tbody tr:hover { background:#f5f7fd; }
        tbody td { padding:.8rem 1rem; font-size:.84rem; vertical-align:middle; }

        .td-num { font-family:"Outfit",sans-serif; font-size:.75rem; font-weight:600; color:var(--gray-500); }
        .td-cuenta { font-family:"Outfit",sans-serif; font-size:.78rem; font-weight:600; color:var(--gray-500); background:var(--gray-50); border:1px solid var(--gray-200); border-radius:20px; padding:.1rem .55rem; white-space:nowrap; display:inline-block; }
        .td-nombre { font-weight:600; color:var(--text); }
        .td-correo { font-size:.76rem; color:var(--gray-500); }

        /* Asistencia toggle */
        .asist-toggle { display:flex; align-items:center; justify-content:center; gap:.4rem; }
        .toggle-btn { width:34px; height:34px; border-radius:var(--radius-sm); border:1.5px solid var(--gray-200); background:var(--white); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .2s; }
        .toggle-btn:hover { border-color:var(--accent); }
        .toggle-btn.presente { background:#e8f7f0; border-color:#a5dfca; color:var(--success); }
        .toggle-btn.ausente  { background:#fff5f5; border-color:#fbd5d5; color:var(--error); }
        .toggle-btn.sin-reg  { background:var(--gray-50); border-color:var(--gray-200); color:var(--gray-300); }

        /* Porcentaje badge */
        .pct-badge { display:inline-flex; align-items:center; padding:.15rem .55rem; border-radius:20px; font-family:"Outfit",sans-serif; font-size:.72rem; font-weight:700; }
        .pct-alto  { background:#e8f7f0; color:#1d6344; }
        .pct-medio { background:#fff3e0; color:#7a4f10; }
        .pct-bajo  { background:#fff5f5; color:#8b2020; }

        /* SIDEBAR */
        .sidebar { display:flex; flex-direction:column; gap:1.25rem; }

        .side-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; animation:fadeUp .4s ease both; }
        .side-card:nth-child(2){animation-delay:.1s} .side-card:nth-child(3){animation-delay:.2s}
        .side-card-top { padding:.9rem 1.2rem; border-bottom:1px solid var(--gray-100); }
        .side-card-top h4 { font-family:"Outfit",sans-serif; font-size:.85rem; font-weight:700; display:flex; align-items:center; gap:.4rem; }
        .side-card-body { padding:1rem 1.2rem; }

        /* Horarios list */
        .horario-item { display:flex; align-items:center; gap:.65rem; padding:.55rem 0; border-bottom:1px solid var(--gray-100); }
        .horario-item:last-child { border-bottom:none; }
        .horario-icon { width:32px; height:32px; border-radius:8px; background:var(--gray-50); border:1px solid var(--gray-100); display:flex; align-items:center; justify-content:center; color:var(--accent); flex-shrink:0; }
        .horario-dia  { font-family:"Outfit",sans-serif; font-size:.82rem; font-weight:700; color:var(--text); }
        .horario-hora { font-size:.75rem; color:var(--gray-500); }

        /* Progress ring (donut chart simple) */
        .donut-wrap { display:flex; flex-direction:column; align-items:center; padding:.5rem 0 .75rem; }
        .donut { position:relative; width:90px; height:90px; margin-bottom:.65rem; }
        .donut svg { transform:rotate(-90deg); }
        .donut-bg   { fill:none; stroke:var(--gray-100); stroke-width:8; }
        .donut-fill { fill:none; stroke:var(--success); stroke-width:8; stroke-linecap:round; transition:stroke-dashoffset .5s ease; }
        .donut-text { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
        .donut-pct  { font-family:"Outfit",sans-serif; font-size:1.2rem; font-weight:700; color:var(--text); }
        .donut-sub  { font-size:.62rem; color:var(--gray-500); margin-top:-2px; }
        .donut-label { font-size:.75rem; color:var(--gray-500); }

        /* Donut color por porcentaje */
        .donut-fill.alto  { stroke:var(--success); }
        .donut-fill.medio { stroke:var(--warning); }
        .donut-fill.bajo  { stroke:var(--error); }

        /* Mini bar resumen */
        .resumen-row { display:flex; align-items:center; justify-content:space-between; padding:.4rem 0; font-size:.78rem; border-bottom:1px solid var(--gray-100); }
        .resumen-row:last-child { border-bottom:none; }
        .resumen-label { color:var(--gray-700); display:flex; align-items:center; gap:.4rem; }
        .resumen-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        .dot-green { background:var(--success); } .dot-orange { background:var(--warning); } .dot-red { background:var(--error); }
        .resumen-val { font-family:"Outfit",sans-serif; font-weight:700; color:var(--text); }

        /* MODAL QR */
        .modal-ov { display:none; position:fixed; inset:0; background:rgba(17,30,58,.55); backdrop-filter:blur(4px); z-index:100; align-items:center; justify-content:center; padding:1rem; }
        .modal-ov.open { display:flex; }
        .modal { background:var(--white); border-radius:var(--radius); box-shadow:0 24px 64px rgba(0,0,0,.22); width:100%; max-width:440px; overflow:hidden; animation:modalIn .25s ease both; }
        @keyframes modalIn { from{opacity:0;transform:scale(.94) translateY(10px)} to{opacity:1;transform:scale(1) translateY(0)} }
        .modal-top { background:linear-gradient(135deg,var(--navy),var(--navy-light)); padding:1.4rem 1.75rem; display:flex; align-items:flex-start; justify-content:space-between; }
        .modal-top h3 { font-family:"Outfit",sans-serif; font-weight:700; font-size:1rem; color:#fff; }
        .modal-top p  { font-size:.78rem; color:rgba(255,255,255,.6); margin-top:.2rem; }
        .modal-close { background:none; border:none; color:rgba(255,255,255,.6); cursor:pointer; padding:2px; transition:color .2s; }
        .modal-close:hover { color:#fff; }
        .modal-bd { padding:1.5rem 1.75rem; }

        /* QR scan area */
        .qr-area { border:2px dashed var(--gray-200); border-radius:var(--radius); padding:2.5rem 1rem; text-align:center; margin-bottom:1.25rem; background:var(--gray-50); cursor:pointer; transition:all .2s; }
        .qr-area:hover { border-color:var(--accent); background:#f0f5ff; }
        .qr-icon { width:56px; height:56px; border-radius:14px; background:var(--gray-100); display:flex; align-items:center; justify-content:center; margin:0 auto .85rem; color:var(--gray-300); }
        .qr-area h4 { font-family:"Outfit",sans-serif; font-size:.92rem; font-weight:700; color:var(--text); margin-bottom:.3rem; }
        .qr-area p  { font-size:.78rem; color:var(--gray-500); line-height:1.5; }
        .qr-divider { display:flex; align-items:center; gap:.75rem; margin:.85rem 0; color:var(--gray-300); font-size:.75rem; }
        .qr-divider::before, .qr-divider::after { content:""; flex:1; height:1px; background:var(--gray-100); }

        .manual-input-wrap { position:relative; margin-bottom:1.25rem; }
        .manual-input-wrap svg { position:absolute; left:.85rem; top:50%; transform:translateY(-50%); color:var(--gray-300); pointer-events:none; }
        .manual-input { width:100%; height:46px; padding:0 1rem 0 2.6rem; border:1.5px solid var(--gray-100); border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:1rem; font-weight:600; letter-spacing:.06em; color:var(--text); background:var(--gray-50); outline:none; transition:all .2s; }
        .manual-input:focus { border-color:var(--accent); background:var(--white); box-shadow:0 0 0 3px rgba(74,127,212,.12); }
        .manual-input::placeholder { font-size:.85rem; font-weight:400; letter-spacing:0; color:var(--gray-300); }

        .modal-info { background:#f0f6ff; border:1px solid #c8deff; border-radius:var(--radius-sm); padding:.7rem .9rem; font-size:.78rem; color:#2a4a80; display:flex; gap:.45rem; align-items:flex-start; margin-bottom:1.25rem; line-height:1.5; }
        .modal-btns { display:flex; gap:.75rem; }
        .modal-btns button { flex:1; height:44px; border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.88rem; font-weight:600; cursor:pointer; border:none; transition:all .2s; display:flex; align-items:center; justify-content:center; gap:.4rem; }
        .btn-cancelar-m { background:var(--gray-100); color:var(--gray-700); } .btn-cancelar-m:hover{background:var(--gray-200)}
        .btn-registrar  { background:var(--success); color:#fff; box-shadow:0 3px 10px rgba(46,158,110,.25); } .btn-registrar:hover{background:#248a5e; transform:translateY(-1px);}

        @keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        footer { text-align:center; padding:1.5rem; font-size:.72rem; color:var(--gray-500); }

        @media (max-width:860px) {
            .main-layout { grid-template-columns:1fr; }
            .stats-row { grid-template-columns:1fr 1fr; }
            .page { padding:1.25rem 1rem 3rem; }
            header { padding:0 1rem; }
            nav a span { display:none; }
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
        <a href="dashboard_encargado.php">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span>Inicio</span>
        </a>
        <a href="registrar_club.php">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Nuevo club</span>
        </a>
        <a href="mis_clubes.php">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span>Mis clubs</span>
        </a>
        <a href="login_encargado.php" class="nav-out">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Salir
        </a>
    </nav>
</header>

<div class="subhdr">
    <div class="sub-av"><?= htmlspecialchars($iniciales_enc) ?></div>
    <div>
        <div class="sub-name"><?= htmlspecialchars($enc['nombre']) ?></div>
        <div class="sub-det"><?= htmlspecialchars($enc['tipo']) ?> &middot; <?= htmlspecialchars($enc['plantel']) ?></div>
    </div>
</div>

<div class="page">

    <!-- BREADCRUMB -->
    <div class="breadcrumb">
        <a href="mis_clubes.php">Mis clubs</a>
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        Asistencias
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        <strong style="color:var(--text)"><?= htmlspecialchars($club['nombre']) ?></strong>
    </div>

    <!-- CLUB INFO -->
    <div class="club-info-card">
        <div class="club-info-bar"></div>
        <div class="club-info-body">
            <div class="club-info-left">
                <div class="club-avatar">
                    <svg width="22" height="22" fill="none" stroke="white" stroke-width="1.8" viewBox="0 0 24 24"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                </div>
                <div>
                    <div class="club-nombre"><?= htmlspecialchars($club['nombre']) ?></div>
                    <div class="club-meta">
                        <?php
                        $badge_clases = [
                            'borrador'  => 'status-badge status-borrador',
                            'apertura'  => 'status-badge status-apertura',
                            'iniciado'  => 'status-activo',
                            'finalizado'=> 'status-badge status-finalizado',
                            'cancelado' => 'status-badge status-cancelado',
                        ];
                        $bc = $badge_clases[$estado_actual] ?? 'status-activo';
                        ?>
                        <span class="<?= $bc ?>"><span class="dot"></span><?= htmlspecialchars($estado_labels[$estado_actual] ?? ucfirst($estado_actual)) ?></span>
                        <span class="club-chip">
                            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                            # <?= str_pad($club['id'], 3, '0', STR_PAD_LEFT) ?>
                        </span>
                        <span class="club-chip">
                            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                            <?= $inscritos_count ?> / <?= (int)$club['limite'] ?> inscritos
                        </span>
                        <span class="club-chip">
                            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <?= date('d M Y', strtotime($club['fecha_inicio'])) ?> &ndash; <?= date('d M Y', strtotime($club['fecha_fin'])) ?>
                        </span>
                        <?php foreach ($horarios as $h): ?>
                        <span class="club-chip">
                            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <?= htmlspecialchars($h['dia']) ?> <?= $h['ini'] ?>–<?= $h['fin'] ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <a href="mis_clubes.php" style="display:inline-flex;align-items:center;gap:.4rem;color:var(--gray-500);font-size:.8rem;text-decoration:none;padding:.4rem .8rem;border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);transition:all .2s;white-space:nowrap;" onmouseover="this.style.background='var(--gray-50)'" onmouseout="this.style.background='transparent'">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Volver a clubs
            </a>
        </div>
    </div>

    <!-- MAIN LAYOUT -->
    <div class="main-layout">

        <!-- TABLA -->
        <div>
            <div class="section-label">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                Registro de asistencias
            </div>

            <!-- BUSCADOR -->
            <div class="buscador-wrap">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input id="buscador-alumnos" class="buscador-input" type="text" placeholder="Buscar por nombre o número de cuenta…" oninput="filtrarAlumnos(this.value)">
            </div>

            <?php
            $pct_total = count($alumnos_lista) > 0 && $total_sesiones > 0
                ? round(array_sum(array_column($alumnos_lista,'total_asistencias')) / (count($alumnos_lista)*$total_sesiones) * 100)
                : 0;
            $asistieron_hoy = count(array_filter($alumnos_lista, fn($a) => $a['asistio_hoy'] > 0));
            ?>

            <div class="table-card">
                <div class="table-card-header">
                    <h3>
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Alumnos inscritos
                        <span style="background:var(--gray-100);border-radius:20px;padding:.1rem .6rem;font-size:.7rem;font-weight:600;color:var(--gray-500)"><?= count($alumnos_lista) ?></span>
                    </h3>
                    <div class="header-actions">
                        <?php if ($mostrar_qr): ?>
                        <button class="btn-qr" onclick="abrirModalQR()">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            Registrar asistencia QR
                        </button>
                        <?php else: ?>
                        <span style="font-size:.76rem;color:var(--gray-500);display:flex;align-items:center;gap:.3rem;padding:.4rem .75rem;background:var(--gray-50);border-radius:6px;border:1px solid var(--gray-100)">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            QR disponible cuando el club esté Iniciado
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- INFO del día actual -->
                <?php if ($horario_hoy): ?>
                <div class="session-bar" style="background:#edfaf4;border-bottom:1px solid #a5dfca">
                    <span class="session-label" style="color:#1d6344">📅 Hoy — <?= htmlspecialchars($hoy_dia_nombre) ?></span>
                    <span class="session-chip active"><?= $horario_hoy['ini'] ?> – <?= $horario_hoy['fin'] ?></span>
                    <span style="margin-left:auto;font-size:.78rem;color:#1d6344;font-weight:600">
                        <?= $asistieron_hoy ?> / <?= count($alumnos_lista) ?> presentes
                    </span>
                </div>
                <?php endif; ?>

                <!-- Totales generales -->
                <div style="display:flex;gap:1.5rem;padding:.85rem 1.5rem;border-bottom:1px solid var(--gray-100);font-size:.8rem;color:var(--gray-500);flex-wrap:wrap">
                    <span><strong style="color:var(--navy);font-size:1rem;font-family:'Outfit',sans-serif"><?= $total_sesiones ?></strong> sesiones registradas</span>
                    <span><strong style="color:var(--accent);font-size:1rem;font-family:'Outfit',sans-serif"><?= count($alumnos_lista) ?></strong> alumnos inscritos</span>
                    <span><strong style="color:var(--success);font-size:1rem;font-family:'Outfit',sans-serif"><?= $pct_total ?>%</strong> asistencia promedio</span>
                </div>

                <div class="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>No. cuenta</th>
                                <th>Nombre del alumno</th>
                                <th style="text-align:center">Asistencias</th>
                                <th style="text-align:center">Hoy</th>
                                <th style="text-align:center">Historial</th>
                            </tr>
                        </thead>
                        <tbody id="tabla-alumnos">
                        <?php if (empty($alumnos_lista)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center;padding:2rem;color:var(--gray-500)">
                                    No hay alumnos inscritos en este club todavía.
                                </td>
                            </tr>
                        <?php else: foreach ($alumnos_lista as $idx => $al):
                            $pct_al = ($total_sesiones > 0)
                                ? round(((int)$al['total_asistencias'] / $total_sesiones) * 100)
                                : 0;
                            $pct_clase = $pct_al >= 80 ? 'pct-alto' : ($pct_al >= 60 ? 'pct-medio' : 'pct-bajo');
                        ?>
                            <tr>
                                <td class="td-num"><?= $idx + 1 ?></td>
                                <td><span class="td-cuenta"><?= htmlspecialchars($al['cuenta']) ?></span></td>
                                <td class="td-nombre"><?= htmlspecialchars($al['nombre_completo']) ?></td>
                                <td style="text-align:center">
                                    <?php if ($total_sesiones > 0): ?>
                                    <span class="pct-badge <?= $pct_clase ?>"><?= $pct_al ?>%</span>
                                    <span style="font-size:.72rem;color:var(--gray-500);display:block;margin-top:.1rem">
                                        <?= (int)$al['total_asistencias'] ?>/<?= $total_sesiones ?>
                                    </span>
                                    <?php else: ?>
                                    <span style="font-size:.75rem;color:var(--gray-300)">Sin registros</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center">
                                    <?php if ($al['asistio_hoy']): ?>
                                    <div style="font-size:.74rem;color:var(--success);font-weight:600;line-height:1.4">
                                        ✓ <?= $al['entrada_hoy'] ? substr($al['entrada_hoy'],0,5) : '—' ?>
                                        <?= $al['salida_hoy'] ? '→ ' . substr($al['salida_hoy'],0,5) : '' ?>
                                    </div>
                                    <?php else: ?>
                                    <span style="font-size:.74rem;color:var(--gray-300)">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center">
                                    <button onclick="verHistorial(<?= $al['id'] ?>, '<?= htmlspecialchars(addslashes($al['nombre_completo'])) ?>', '<?= $al['cuenta'] ?>')"
                                        style="background:none;border:1.5px solid var(--gray-200);border-radius:6px;padding:.3rem .7rem;font-size:.74rem;font-family:'Outfit',sans-serif;font-weight:600;color:var(--gray-700);cursor:pointer;transition:all .2s"
                                        onmouseover="this.style.borderColor='var(--accent)';this.style.color='var(--accent)'"
                                        onmouseout="this.style.borderColor='var(--gray-200)';this.style.color='var(--gray-700)'">
                                        📋 Ver
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- SIDEBAR -->
        <div class="sidebar">

            <!-- DONUT -->
            <div class="side-card">
                <div class="side-card-top">
                    <h4>
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                        Asistencia global
                    </h4>
                </div>
                <div class="side-card-body">
                    <div class="donut-wrap">
                        <div class="donut">
                            <?php
                            $alta   = count(array_filter($alumnos_lista, fn($a) => $total_sesiones>0 && ($a['total_asistencias']/$total_sesiones)*100 >= 80));
                            $media  = count(array_filter($alumnos_lista, fn($a) => $total_sesiones>0 && ($p=($a['total_asistencias']/$total_sesiones)*100) >= 60 && $p < 80));
                            $baja   = count(array_filter($alumnos_lista, fn($a) => $total_sesiones>0 && ($a['total_asistencias']/$total_sesiones)*100 < 60));
                            $donut_class  = $pct_total >= 80 ? 'alto' : ($pct_total >= 60 ? 'medio' : 'bajo');
                            $donut_offset = round(226.2 * (1 - $pct_total / 100));
                            ?>
                        <svg width="90" height="90" viewBox="0 0 90 90">
                                <circle class="donut-bg" cx="45" cy="45" r="36"/>
                                <circle class="donut-fill <?= $donut_class ?>" cx="45" cy="45" r="36"
                                    stroke-dasharray="226.2"
                                    stroke-dashoffset="<?= $donut_offset ?>"/>
                            </svg>
                            <div class="donut-text">
                                <span class="donut-pct"><?= $pct_total ?>%</span>
                                <span class="donut-sub">asistencia</span>
                            </div>
                        </div>
                        <span class="donut-label">Promedio general del club</span>
                    </div>
                    <div class="resumen-row">
                        <span class="resumen-label"><span class="resumen-dot dot-green"></span>Alta (&ge;80%)</span>
                        <span class="resumen-val"><?= $alta ?> <?= $alta===1?'alumno':'alumnos' ?></span>
                    </div>
                    <div class="resumen-row">
                        <span class="resumen-label"><span class="resumen-dot dot-orange"></span>Media (60&ndash;79%)</span>
                        <span class="resumen-val"><?= $media ?> <?= $media===1?'alumno':'alumnos' ?></span>
                    </div>
                    <div class="resumen-row">
                        <span class="resumen-label"><span class="resumen-dot dot-red"></span>Baja (&lt;60%)</span>
                        <span class="resumen-val"><?= $baja ?> <?= $baja===1?'alumno':'alumnos' ?></span>
                    </div>
                </div>
            </div>

            <!-- LOG DE HOY -->
            <div class="side-card">
                <div class="side-card-top">
                    <h4>
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        Registros de hoy
                    </h4>
                    <span style="font-size:.7rem;color:var(--gray-500)"><?= date('d M Y') ?></span>
                </div>
                <div class="side-card-body" style="padding:0">
                    <?php if (empty($log_hoy)): ?>
                    <p style="font-size:.82rem;color:var(--gray-500);padding:.75rem 1rem">Sin escaneos hoy.</p>
                    <?php else: foreach ($log_hoy as $reg): ?>
                    <div style="display:flex;align-items:center;gap:.65rem;padding:.6rem 1rem;border-bottom:1px solid var(--gray-50)">
                        <div style="width:32px;height:32px;border-radius:50%;background:<?= $reg['hora_salida'] ? '#edfaf4' : '#e8f4ff' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.75rem;font-weight:700;color:<?= $reg['hora_salida'] ? '#1d6344' : '#2a5ea8' ?>">
                            <?= mb_substr($reg['nombre_completo'], 0, 1) ?>
                        </div>
                        <div style="flex:1;min-width:0">
                            <div style="font-size:.79rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($reg['nombre_completo']) ?></div>
                            <div style="font-size:.7rem;color:var(--gray-500)">
                                <?= $reg['hora_entrada'] ? '▶ ' . substr($reg['hora_entrada'],0,5) : '' ?>
                                <?= $reg['hora_salida']  ? ' · ⏹ ' . substr($reg['hora_salida'],0,5) : ' · sin salida' ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- AUXILIARES — solo visible para el encargado principal -->
            <?php if (!$es_auxiliar): ?>
            <div class="side-card">
                <div class="side-card-top" style="align-items:flex-start">
                    <h4 style="padding-top:.05rem">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Encargados auxiliares
                    </h4>
                </div>
                <div class="side-card-body" style="padding:.9rem 1.2rem">

                    <?php if ($aux_msg_ok): ?>
                    <div style="background:#edfaf4;border:1px solid #a5dfca;border-left:3px solid #2e9e6e;border-radius:6px;padding:.6rem .85rem;font-size:.78rem;color:#1a5e3f;margin-bottom:.85rem;line-height:1.5">
                        <?= $aux_msg_ok ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($aux_msg_err): ?>
                    <div style="background:#fff5f5;border:1px solid #fbd5d5;border-left:3px solid #d94f4f;border-radius:6px;padding:.6rem .85rem;font-size:.78rem;color:#8b2020;margin-bottom:.85rem">
                        <?= htmlspecialchars($aux_msg_err) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($auxiliares): ?>
                    <div style="margin-bottom:.85rem;display:flex;flex-direction:column;gap:0">
                        <?php foreach ($auxiliares as $ax): ?>
                        <div style="display:flex;align-items:center;gap:.55rem;padding:.55rem 0;border-bottom:1px solid var(--gray-100)">
                            <div style="width:30px;height:30px;border-radius:50%;background:#e8f4ff;display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-size:.72rem;font-weight:700;color:var(--accent);flex-shrink:0">
                                <?= mb_strtoupper(mb_substr($ax['nombres'], 0, 1)) ?>
                            </div>
                            <div style="flex:1;min-width:0">
                                <div style="font-size:.8rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                    <?= htmlspecialchars($ax['nombres'] . ' ' . $ax['apellido_paterno']) ?>
                                </div>
                                <div style="font-size:.7rem;color:var(--gray-500)">N.° <?= $ax['id_persona'] ?></div>
                            </div>
                            <form method="POST" style="flex-shrink:0;margin:0">
                                <input type="hidden" name="eliminar_auxiliar" value="1">
                                <input type="hidden" name="aux_id" value="<?= $ax['id'] ?>">
                                <button type="submit"
                                    onclick="return confirm('¿Quitar a <?= htmlspecialchars(addslashes($ax['nombres'] . ' ' . $ax['apellido_paterno'])) ?> como auxiliar?')"
                                    title="Quitar auxiliar"
                                    style="width:28px;height:28px;background:#fff5f5;border:1.5px solid #fbd5d5;border-radius:6px;cursor:pointer;color:var(--error);display:flex;align-items:center;justify-content:center;transition:all .2s;padding:0"
                                    onmouseover="this.style.background='#ffe8e8'" onmouseout="this.style.background='#fff5f5'">
                                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p style="font-size:.8rem;color:var(--gray-500);margin-bottom:.85rem;display:flex;align-items:center;gap:.4rem">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Sin auxiliares asignados.
                    </p>
                    <?php endif; ?>

                    <!-- Formulario para agregar -->
                    <form method="POST" style="display:flex;gap:.4rem;align-items:stretch">
                        <input type="hidden" name="agregar_auxiliar" value="1">
                        <input type="text" name="num_trabajador_aux"
                            placeholder="N.° trabajador" inputmode="numeric" maxlength="10"
                            oninput="this.value=this.value.replace(/\D/g,'')"
                            style="flex:1;height:34px;padding:0 .7rem;border:1.5px solid var(--gray-100);border-radius:6px;font-family:'DM Sans',sans-serif;font-size:.82rem;color:var(--text);background:var(--gray-50);outline:none;transition:all .2s"
                            onfocus="this.style.borderColor='var(--accent)'"
                            onblur="this.style.borderColor='var(--gray-100)'">
                        <button type="submit"
                            style="height:34px;padding:0 .75rem;background:var(--accent);color:#fff;border:none;border-radius:6px;font-family:'Outfit',sans-serif;font-size:.78rem;font-weight:600;cursor:pointer;white-space:nowrap;transition:background .2s;flex-shrink:0"
                            onmouseover="this.style.background='var(--accent-h)'" onmouseout="this.style.background='var(--accent)'">
                            + Agregar
                        </button>
                    </form>
                    <p style="font-size:.7rem;color:var(--gray-500);margin-top:.4rem;line-height:1.45">
                        Los auxiliares pueden ver el club y tomar asistencia QR.
                    </p>

                </div>
            </div>
            <?php endif; ?>

            <!-- HORARIOS -->
            <div class="side-card">
                <div class="side-card-top">
                    <h4>
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Horarios del club
                    </h4>
                </div>
                <div class="side-card-body">
                    <?php if (empty($horarios)): ?>
                    <p style="font-size:.82rem;color:var(--gray-500)">Sin horarios registrados.</p>
                    <?php else: foreach ($horarios as $h): ?>
                    <div class="horario-item">
                        <div class="horario-icon"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                        <div>
                            <div class="horario-dia"><?= htmlspecialchars($h['dia']) ?></div>
                            <div class="horario-hora"><?= $h['ini'] ?> &ndash; <?= $h['fin'] ?></div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- PR&Oacute;XIMA SESI&Oacute;N -->
            <?php
            $proxima = null;
            if ($estado_actual !== 'finalizado' && !empty($horarios) && !empty($club['fecha_fin'])) {
                $dia_a_iso = ['Lunes'=>1,'Martes'=>2,'Miércoles'=>3,'Jueves'=>4,'Viernes'=>5,'Sábado'=>6,'Domingo'=>7];
                $fecha_fin_dt = new DateTime($club['fecha_fin']);
                $hoy_dt = new DateTime('today');
                for ($i = 0; $i <= 13; $i++) {
                    $check = (clone $hoy_dt)->modify("+$i days");
                    if ($check > $fecha_fin_dt) break;
                    $check_iso = (int)$check->format('N');
                    foreach ($horarios as $h) {
                        if (($dia_a_iso[$h['dia']] ?? null) === $check_iso) {
                            $proxima = ['fecha' => $check, 'dia' => $h['dia'], 'ini' => $h['ini'], 'fin' => $h['fin']];
                            break 2;
                        }
                    }
                }
            }
            $meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
            ?>
            <div class="side-card">
                <div class="side-card-top">
                    <h4>
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Pr&oacute;xima sesi&oacute;n
                    </h4>
                </div>
                <div class="side-card-body">
                    <?php if ($estado_actual === 'finalizado'): ?>
                    <div style="text-align:center;padding:1rem 0 .5rem">
                        <div style="font-size:2rem;line-height:1;margin-bottom:.5rem">🏁</div>
                        <p style="font-size:.85rem;font-weight:600;color:var(--gray-700);margin:0">Club finalizado</p>
                        <p style="font-size:.78rem;color:var(--gray-500);margin:.25rem 0 0">Este club ha concluido sus actividades.</p>
                    </div>
                    <?php elseif ($proxima): ?>
                    <div style="text-align:center;padding:.5rem 0 .75rem">
                        <div style="font-family:'Outfit',sans-serif;font-size:2rem;font-weight:700;color:var(--navy);line-height:1"><?= $proxima['fecha']->format('j') ?></div>
                        <div style="font-size:.8rem;color:var(--gray-500);margin-top:.2rem"><?= $meses_es[(int)$proxima['fecha']->format('n')] ?> <?= $proxima['fecha']->format('Y') ?> &mdash; <?= htmlspecialchars($proxima['dia']) ?></div>
                        <div style="display:inline-flex;align-items:center;gap:.3rem;background:var(--gray-50);border:1px solid var(--gray-200);border-radius:20px;padding:.25rem .75rem;font-size:.75rem;color:var(--gray-700);margin-top:.6rem;font-family:'Outfit',sans-serif;font-weight:600">
                            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <?= htmlspecialchars($proxima['ini']) ?> &ndash; <?= htmlspecialchars($proxima['fin']) ?>
                        </div>
                    </div>
                    <?php if ($mostrar_qr): ?>
                    <button class="btn-qr" style="width:100%;justify-content:center;margin-top:.25rem" onclick="abrirModalQR()">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        Pasar lista con QR
                    </button>
                    <?php endif; ?>
                    <?php else: ?>
                    <p style="font-size:.82rem;color:var(--gray-500);text-align:center;padding:.75rem 0">Sin sesiones pendientes.</p>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /sidebar -->
    </div><!-- /main-layout -->

</div><!-- /page -->

<!-- ═══════════════════════════════════ MODAL QR ══════════════════════════════════ -->
<div class="modal-ov" id="modal-qr">
    <div class="modal" style="max-width:460px">
        <div class="modal-top" style="position:relative">
            <div>
                <h3>Registrar asistencia QR</h3>
                <p><?= date('d M Y') ?> &nbsp;·&nbsp; <?= htmlspecialchars($club['nombre']) ?></p>
            </div>
            <!-- Botón cerrar mejorado -->
            <button onclick="cerrarModalQR()" style="
                position:absolute; top:1rem; right:1.25rem;
                width:32px; height:32px;
                background:rgba(255,255,255,.15);
                border:1.5px solid rgba(255,255,255,.3);
                border-radius:50%;
                color:#fff; cursor:pointer;
                display:flex; align-items:center; justify-content:center;
                transition:all .2s; padding:0; line-height:0"
                onmouseover="this.style.background='rgba(255,255,255,.3)'"
                onmouseout="this.style.background='rgba(255,255,255,.15)'">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-bd">

            <!-- Solo cámara QR (sin tabs) -->
            <div id="panel-camara">
                <div id="reader-qr" style="border-radius:12px;overflow:hidden;border:2px solid var(--accent);background:#000;min-height:220px"></div>
                <p style="font-size:.75rem;color:var(--gray-500);text-align:center;margin-top:.6rem;line-height:1.5">
                    Apunta la cámara al <strong>QR de la credencial SICEUC</strong> del alumno.<br>
                    Se registra automáticamente al leer el código.
                </p>
            </div>

            <!-- Resultado del escaneo -->
            <div id="resultado-scan" style="display:none;margin-top:.85rem;padding:.8rem 1rem;border-radius:8px;font-size:.85rem;font-weight:600;text-align:center;transition:all .3s"></div>

            <!-- Log de la sesión actual -->
            <div id="log-sesion" style="margin-top:.85rem">
                <p style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-500);margin-bottom:.4rem">Últimos registros</p>
                <div id="log-items" style="display:flex;flex-direction:column;gap:.3rem">
                    <p style="font-size:.78rem;color:var(--gray-300)">Sin registros aún...</p>
                </div>
            </div>

            <div style="margin-top:1.1rem;display:flex;justify-content:center">
                <button onclick="cerrarModalQR()" style="
                    height:40px; padding:0 2rem;
                    background:var(--gray-100); color:var(--gray-700);
                    border:1.5px solid var(--gray-200); border-radius:20px;
                    font-family:'Outfit',sans-serif; font-size:.85rem; font-weight:600;
                    cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:.4rem"
                    onmouseover="this.style.background='var(--gray-200)'"
                    onmouseout="this.style.background='var(--gray-100)'">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════ MODAL HISTORIAL ══════════════════════════════ -->
<div class="modal-ov" id="modal-historial">
    <div class="modal" style="max-width:560px">
        <div class="modal-top">
            <div>
                <h3 id="hist-nombre">Historial de asistencia</h3>
                <p id="hist-sub">Registro completo del alumno en este club</p>
            </div>
            <button class="modal-close" onclick="cerrarHistorial()">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-bd" style="padding:0">
            <div id="hist-loading" style="padding:2rem;text-align:center;color:var(--gray-500)">Cargando...</div>
            <div id="hist-tabla" style="display:none">
                <table style="width:100%;border-collapse:collapse;font-size:.83rem">
                    <thead>
                        <tr style="background:var(--gray-50);border-bottom:1.5px solid var(--gray-200)">
                            <th style="padding:.65rem 1rem;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--gray-500)">Fecha</th>
                            <th style="padding:.65rem 1rem;text-align:center;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--gray-500)">Entrada</th>
                            <th style="padding:.65rem 1rem;text-align:center;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--gray-500)">Salida</th>
                            <th style="padding:.65rem 1rem;text-align:center;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--gray-500)">Estado</th>
                        </tr>
                    </thead>
                    <tbody id="hist-tbody"></tbody>
                </table>
                <div id="hist-empty" style="display:none;padding:2rem;text-align:center;color:var(--gray-500);font-size:.85rem">
                    Sin registros de asistencia aún.
                </div>
            </div>
        </div>
        <div style="padding:1rem 1.5rem;border-top:1px solid var(--gray-100);text-align:right">
            <button class="btn-cancelar-m" onclick="cerrarHistorial()">Cerrar</button>
        </div>
    </div>
</div>

<footer>&copy; <?= date('Y') ?> Universidad de Colima &mdash; Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>

<!-- html5-qrcode para lectura de cámara -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script>
const CLUB_ID   = <?= $club_id ?>;
let qrScanner   = null;
let qrActivo    = false;
let logItems    = [];

// switchTab ya no se usa (solo hay cámara)

// ── Cámara QR ────────────────────────────────────────────────────
function iniciarCamara() {
    if (qrActivo) return;
    if (!qrScanner) qrScanner = new Html5Qrcode('reader-qr');
    qrScanner.start(
        { facingMode: 'environment' },
        { fps: 15, qrbox: { width: 220, height: 220 } },
        (decoded) => {
            qrScanner.stop().then(() => {
                qrActivo = false;
                procesarCodigo(decoded.trim());
                setTimeout(iniciarCamara, 2500);
            });
        }
    ).then(() => { qrActivo = true; })
     .catch(() => {
        document.getElementById('reader-qr').innerHTML =
            '<p style="color:#d94f4f;padding:1.5rem;text-align:center;font-size:.83rem">⚠️ No se pudo acceder a la cámara.<br>Usa el modo Manual.</p>';
    });
}

function detenerCamara() {
    if (qrScanner && qrActivo) {
        qrScanner.stop().catch(() => {});
        qrActivo = false;
    }
}

// ── Procesar código (cámara o manual) ───────────────────────────
function procesarCodigo(codigo) {
    if (!codigo) return;

    const resultDiv = document.getElementById('resultado-scan');
    resultDiv.style.display = 'block';
    resultDiv.style.background = '#eef0f6';
    resultDiv.style.color = 'var(--gray-700)';
    resultDiv.textContent = '🔍 Buscando: ' + codigo + '...';

    const fd = new FormData();
    fd.append('codigo',  codigo);
    fd.append('id_club', CLUB_ID);

    fetch('procesar_asistencia.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const esOk = data.status === 'registrado';
            resultDiv.style.background = esOk
                ? (data.tipo === 'ENTRADA' ? '#edfaf4' : '#e8f4ff')
                : '#fff5f5';
            resultDiv.style.color = esOk
                ? (data.tipo === 'ENTRADA' ? '#1a5e3f' : '#2a5ea8')
                : '#8b2020';
            resultDiv.style.border = '1px solid ' + (esOk ? (data.tipo==='ENTRADA'?'#a5dfca':'#bdd8ff') : '#fbd5d5');
            resultDiv.textContent = data.message;

            if (esOk) {
                agregarLog(data.nombre, data.tipo, data.hora);
                // Recargar fila del alumno en la tabla
                recargarTabla();
            }
            // Borrar mensaje tras 3s
            setTimeout(() => { resultDiv.style.display = 'none'; }, 3500);
        })
        .catch(() => {
            resultDiv.style.background = '#fff5f5';
            resultDiv.style.color = '#8b2020';
            resultDiv.textContent = '⚠️ Error de conexión. Intenta de nuevo.';
        });
}

// ── Log de registros en el modal ─────────────────────────────────
function agregarLog(nombre, tipo, hora) {
    logItems.unshift({ nombre, tipo, hora });
    if (logItems.length > 5) logItems.pop();
    renderLog();
}

function renderLog() {
    const cont = document.getElementById('log-items');
    if (!logItems.length) {
        cont.innerHTML = '<p style="font-size:.78rem;color:var(--gray-300)">Sin registros aún...</p>';
        return;
    }
    cont.innerHTML = logItems.map(item => `
        <div style="display:flex;align-items:center;gap:.5rem;padding:.35rem .5rem;background:${item.tipo==='ENTRADA'?'#edfaf4':'#e8f4ff'};border-radius:6px;font-size:.78rem">
            <span style="font-weight:700;color:${item.tipo==='ENTRADA'?'#1d6344':'#2a5ea8'}">${item.tipo==='ENTRADA'?'▶':'⏹'} ${item.hora}</span>
            <span style="color:var(--gray-700);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${item.nombre}</span>
        </div>`).join('');
}

// ── Recargar la tabla principal de alumnos ───────────────────────
function recargarTabla() {
    setTimeout(() => location.reload(), 3000);
}

// ── Buscador de alumnos ──────────────────────────────────────────
function filtrarAlumnos(q) {
    const term = q.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('#tabla-alumnos tr').forEach(tr => {
        const nombre = tr.querySelector('.td-nombre')?.textContent.toLowerCase() ?? '';
        const cuenta = tr.querySelector('.td-cuenta')?.textContent.toLowerCase() ?? '';
        const match = !term || nombre.includes(term) || cuenta.includes(term);
        tr.style.display = match ? '' : 'none';
        if (match) {
            const numCell = tr.querySelector('.td-num');
            if (numCell) numCell.textContent = ++visible;
        }
    });
}

// ── Abrir / cerrar modal QR ──────────────────────────────────────
function abrirModalQR() {
    document.getElementById('modal-qr').classList.add('open');
    document.body.style.overflow = 'hidden';
    logItems = [];
    renderLog();
    document.getElementById('resultado-scan').style.display = 'none';
    iniciarCamara();  // arranca directo sin pasar por tabs
}

function cerrarModalQR() {
    detenerCamara();
    document.getElementById('modal-qr').classList.remove('open');
    document.body.style.overflow = '';
}

document.getElementById('modal-qr').addEventListener('click', e => {
    if (e.target === document.getElementById('modal-qr')) cerrarModalQR();
});

// ── Historial de alumno ──────────────────────────────────────────
function verHistorial(estId, nombre, cuenta) {
    document.getElementById('hist-nombre').textContent = nombre;
    document.getElementById('hist-sub').textContent = 'Cuenta #' + cuenta + ' · ' + <?= json_encode($club['nombre']) ?>;
    document.getElementById('modal-historial').classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('hist-loading').style.display = '';
    document.getElementById('hist-tabla').style.display   = 'none';

    fetch('asistencias.php?club=<?= $club_id ?>&historial=' + estId)
        .then(r => r.json())
        .then(rows => {
            document.getElementById('hist-loading').style.display = 'none';
            document.getElementById('hist-tabla').style.display   = '';
            const tbody = document.getElementById('hist-tbody');
            if (!rows.length) {
                document.getElementById('hist-empty').style.display = '';
                tbody.innerHTML = '';
                return;
            }
            document.getElementById('hist-empty').style.display = 'none';
            tbody.innerHTML = rows.map((r, i) => {
                const colorFila = i % 2 === 0 ? '#fff' : '#f7f8fc';
                const estadoColor = { asistio: '#2e9e6e', falta: '#d94f4f', tarde: '#d47a20' };
                const estadoLabel = { asistio: '✓ Asistió', falta: '✗ Falta', tarde: '⚡ Tarde' };
                const est = r.estado || 'asistio';
                return `<tr style="background:${colorFila};border-bottom:1px solid #eef0f6">
                    <td style="padding:.65rem 1rem;font-family:'Outfit',sans-serif;font-size:.84rem;font-weight:600">${r.fecha}</td>
                    <td style="padding:.65rem 1rem;text-align:center;font-size:.82rem;color:var(--success);font-weight:600">
                        ${r.hora_entrada ? r.hora_entrada.substring(0,5) : '—'}
                    </td>
                    <td style="padding:.65rem 1rem;text-align:center;font-size:.82rem;color:var(--accent);font-weight:600">
                        ${r.hora_salida ? r.hora_salida.substring(0,5) : '—'}
                    </td>
                    <td style="padding:.65rem 1rem;text-align:center">
                        <span style="font-size:.72rem;font-weight:700;color:${estadoColor[est]};background:${estadoColor[est]}18;padding:.15rem .6rem;border-radius:20px">
                            ${estadoLabel[est] || est}
                        </span>
                    </td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            document.getElementById('hist-loading').textContent = 'Error al cargar el historial.';
        });
}

function cerrarHistorial() {
    document.getElementById('modal-historial').classList.remove('open');
    document.body.style.overflow = '';
}

document.getElementById('modal-historial').addEventListener('click', e => {
    if (e.target === document.getElementById('modal-historial')) cerrarHistorial();
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { cerrarModalQR(); cerrarHistorial(); }
});
</script>
</body>
</html>