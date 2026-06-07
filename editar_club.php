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
$partes   = explode(' ', $enc['nombre']);
$iniciales = '';
foreach (array_slice($partes, 0, 2) as $p) $iniciales .= mb_substr($p, 0, 1);

// ── ID del club a editar ─────────────────────────────────────────
$club_id = (int)($_GET['id'] ?? 0);
if (!$club_id) { header('Location: mis_clubes.php'); exit; }

// ── Cargar club desde BD (verificar que pertenece al encargado) ──
$club     = null;
$horarios = [];
$inscritos = 0;

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("
        SELECT c.id, c.nombre, c.descripcion, c.fecha_inicio, c.fecha_fin,
               c.fecha_limite_registro, c.limite, c.anio, c.semestre,
               c.estado, c.autorizado,
               COUNT(e.id) AS inscritos
        FROM clubes c
        LEFT JOIN estudiantes e ON e.id_club = c.id
        WHERE c.id = :cid AND c.id_encargado = :eid
        GROUP BY c.id
    ");
    $stmt->execute([':cid' => $club_id, ':eid' => $enc_id]);
    $club = $stmt->fetch();

    if (!$club) { header('Location: mis_clubes.php'); exit; }
    $inscritos = (int)$club['inscritos'];

    $h_stmt = $pdo->prepare("SELECT * FROM horarios WHERE id_club = ? ORDER BY FIELD(dia,'Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'), hora_inicio");
    $h_stmt->execute([$club_id]);
    $horarios = $h_stmt->fetchAll();

} catch (Exception $e) {
    header('Location: mis_clubes.php?error=bd');
    exit;
}

// ── Transición de estado ─────────────────────────────────────────
$transiciones_validas = [
    'apertura'   => 'borrador',    // publicar
    'iniciado'   => ['borrador','apertura'], // iniciar
    'finalizado' => 'iniciado',    // finalizar
    'cancelado'  => ['borrador','apertura','iniciado'], // cancelar
];
if (isset($_POST['cambiar_estado'])) {
    $nuevo_estado  = $_POST['cambiar_estado'] ?? '';
    $estados_enum  = ['borrador','apertura','iniciado','finalizado','cancelado'];
    if (in_array($nuevo_estado, $estados_enum, true)) {
        try {
            $pdo->prepare("UPDATE clubes SET estado = ? WHERE id = ? AND id_encargado = ?")
                ->execute([$nuevo_estado, $club_id, $enc_id]);
            header("Location: editar_club.php?id=$club_id&estado_ok=$nuevo_estado");
            exit;
        } catch (Exception $e) {
            $errores[] = 'No se pudo cambiar el estado. Intenta de nuevo.';
        }
    }
}

// ── ¿El club es editable? ────────────────────────────────────────
// Solo editable si está en borrador Y no ha sido autorizado aún
$es_editable = ($club['estado'] === 'borrador' && ($club['autorizado'] ?? 'no') === 'no');

// ── Guardar cambios ──────────────────────────────────────────────
$errores = [];
$msg_ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    if (!$es_editable) {
        $errores[] = 'Este club no se puede editar en su estado actual.';
    }
    $nombre                = trim($_POST['nombre']               ?? '');
    $descripcion           = trim($_POST['descripcion']          ?? '');
    $fecha_inicio          = trim($_POST['fecha_inicio']         ?? '');
    $fecha_fin             = trim($_POST['fecha_fin']            ?? '');
    $fecha_limite_registro = trim($_POST['fecha_limite_registro']?? '');
    $limite                = (int)($_POST['limite']             ?? 0);
    $anio                  = (int)($_POST['anio']               ?? date('Y'));
    $semestre              = trim($_POST['semestre']            ?? '');
    $dias      = $_POST['dia']         ?? [];
    $h_inicios = $_POST['hora_inicio'] ?? [];
    $h_fines   = $_POST['hora_fin']    ?? [];

    if (!$nombre || mb_strlen($nombre) > 50)     $errores[] = 'Nombre requerido (máx. 50 caracteres).';
    if (!$descripcion || mb_strlen($descripcion) > 150) $errores[] = 'Descripción requerida (máx. 150 caracteres).';
    if (!$fecha_inicio) $errores[] = 'Fecha de inicio requerida.';
    if (!$fecha_fin)    $errores[] = 'Fecha de fin requerida.';
    if ($fecha_inicio && $fecha_fin && $fecha_fin <= $fecha_inicio) $errores[] = 'La fecha de fin debe ser posterior a la de inicio.';
    if ($fecha_limite_registro) {
        if ($fecha_limite_registro < $fecha_inicio) $errores[] = 'La fecha límite de registro no puede ser anterior al inicio.';
        if ($fecha_limite_registro > $fecha_fin)    $errores[] = 'La fecha límite de registro no puede ser posterior al fin.';
    }
    if ($limite < 5 || $limite > 127) $errores[] = 'El límite debe estar entre 5 y 127.';
    if (!in_array($semestre, ['par', 'impar'])) $errores[] = 'Selecciona si el club es para semestre par o impar.';
    if (empty($dias)) $errores[] = 'Agrega al menos un horario.';
    foreach ($dias as $i => $dia) {
        if (!$dia || !($h_inicios[$i] ?? '') || !($h_fines[$i] ?? '')) {
            $errores[] = 'Completa día y horas del horario ' . ($i+1) . '.';
        } elseif (($h_fines[$i] ?? '') <= ($h_inicios[$i] ?? '')) {
            $errores[] = 'Hora de fin debe ser posterior a la de inicio (horario '.($i+1).').';
        }
    }

    if (empty($errores)) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE clubes SET nombre=?,descripcion=?,fecha_inicio=?,fecha_fin=?,
                           fecha_limite_registro=?,limite=?,anio=?,semestre=?
                           WHERE id=? AND id_encargado=?")
                ->execute([$nombre,$descripcion,$fecha_inicio,$fecha_fin,
                           $fecha_limite_registro ?: null,$limite,$anio,$semestre,
                           $club_id,$enc_id]);
            $pdo->prepare("DELETE FROM horarios WHERE id_club=?")->execute([$club_id]);
            $ins_h = $pdo->prepare("INSERT INTO horarios (dia,hora_inicio,hora_fin,id_club) VALUES (?,?,?,?)");
            foreach ($dias as $i => $dia) {
                $ins_h->execute([$dia, $h_inicios[$i], $h_fines[$i], $club_id]);
            }
            $pdo->commit();

            // Recargar datos actualizados
            $stmt->execute([':cid' => $club_id, ':eid' => $enc_id]);
            $club = $stmt->fetch();
            $h_stmt->execute([$club_id]);
            $horarios = $h_stmt->fetchAll();
            $msg_ok = 'Los cambios del club <strong>' . htmlspecialchars($nombre) . '</strong> se guardaron correctamente.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errores[] = 'Error al guardar. Intenta de nuevo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Club &mdash; Bachillerato 23</title>
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
            --radius:     12px;
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
        .sub-badge { margin-left:auto; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25); border-radius:20px; padding:.2rem .75rem; font-size:.72rem; color:rgba(255,255,255,.85); font-family:"Outfit",sans-serif; display:flex; align-items:center; gap:.35rem; }

        /* PAGE */
        .page { max-width:860px; margin:0 auto; padding:2rem 1.5rem 4rem; }

        /* BREADCRUMB */
        .breadcrumb { display:flex; align-items:center; gap:.4rem; font-size:.78rem; color:var(--gray-500); margin-bottom:1.5rem; flex-wrap:wrap; }
        .breadcrumb a { color:var(--accent); text-decoration:none; } .breadcrumb a:hover { text-decoration:underline; }
        .breadcrumb svg { color:var(--gray-300); }

        /* PAGE TITLE */
        .page-title { display:flex; align-items:center; gap:.75rem; margin-bottom:1.75rem; }
        .page-title-icon { width:42px; height:42px; border-radius:10px; background:var(--warning); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .page-title h1 { font-family:"Outfit",sans-serif; font-size:1.4rem; font-weight:700; color:var(--navy); }
        .page-title p  { font-size:.83rem; color:var(--gray-500); margin-top:.15rem; }

        /* CLUB ID BADGE */
        .club-id-bar { display:flex; align-items:center; justify-content:space-between; background:var(--white); border-radius:var(--radius-sm); box-shadow:var(--shadow); padding:.85rem 1.25rem; margin-bottom:1.5rem; flex-wrap:wrap; gap:.75rem; }
        .club-id-left { display:flex; align-items:center; gap:.75rem; }
        .club-id-avatar { width:38px; height:38px; border-radius:9px; background:linear-gradient(135deg,var(--accent),#7aa8e8); display:flex; align-items:center; justify-content:center; }
        .club-id-nombre { font-family:"Outfit",sans-serif; font-size:.95rem; font-weight:700; color:var(--text); }
        .club-id-chip { display:inline-flex; align-items:center; gap:.3rem; background:var(--gray-50); border:1px solid var(--gray-200); border-radius:20px; padding:.2rem .65rem; font-size:.72rem; color:var(--gray-500); font-family:"Outfit",sans-serif; font-weight:600; margin-top:.2rem; }
        .status-badge-sm { display:inline-flex; align-items:center; gap:.3rem; padding:.22rem .7rem; border-radius:20px; font-size:.7rem; font-weight:600; font-family:"Outfit",sans-serif; }
        .sb-activo  { background:#e8f7f0; color:#1d6344; }
        .sb-activo .dot { width:6px; height:6px; border-radius:50%; background:var(--success); }

        /* CARD */
        .card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); overflow:hidden; margin-bottom:1.5rem; }
        .card-top { background:linear-gradient(135deg,var(--navy) 0%,var(--navy-light) 100%); padding:1.25rem 1.75rem; position:relative; overflow:hidden; }
        .card-top::after { content:""; position:absolute; right:-20px; bottom:-40px; width:140px; height:140px; border-radius:50%; background:rgba(255,255,255,.05); }
        .card-top h2 { font-family:"Outfit",sans-serif; font-size:1rem; font-weight:700; color:#fff; display:flex; align-items:center; gap:.5rem; position:relative; z-index:1; }
        .card-top p  { font-size:.78rem; color:rgba(255,255,255,.6); margin-top:.2rem; position:relative; z-index:1; }
        .card-body { padding:1.5rem 1.75rem 1.75rem; }

        /* FORM */
        .form-grid       { display:grid; grid-template-columns:1fr 1fr;     gap:1rem 1.25rem; }
        .form-grid.cols3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem 1.25rem; }
        .full { grid-column:1/-1; }
        .fg { }

        label { display:block; font-size:.73rem; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:var(--gray-700); margin-bottom:.4rem; }
        label .req { color:var(--error); margin-left:2px; }

        .iw { position:relative; }
        .iw .icon { position:absolute; left:.8rem; top:50%; transform:translateY(-50%); color:var(--gray-300); pointer-events:none; transition:color .2s; }
        .iw:focus-within .icon { color:var(--accent); }

        input[type="text"], input[type="date"], input[type="time"], input[type="number"], select, textarea {
            width:100%; height:44px; padding:0 .9rem 0 2.5rem;
            border:1.5px solid var(--gray-100); border-radius:var(--radius-sm);
            font-family:"DM Sans",sans-serif; font-size:.9rem; color:var(--text);
            background:var(--gray-50);
            transition:border-color .2s, box-shadow .2s, background .2s;
            outline:none; appearance:none; -webkit-appearance:none;
        }
        textarea { height:auto; padding:.7rem .9rem .7rem 2.5rem; resize:vertical; min-height:82px; line-height:1.5; }
        input:focus, select:focus, textarea:focus { border-color:var(--accent); background:var(--white); box-shadow:0 0 0 3px rgba(74,127,212,.12); }

        .sw::after { content:""; position:absolute; right:.85rem; top:50%; transform:translateY(-50%); width:0; height:0; border-left:5px solid transparent; border-right:5px solid transparent; border-top:6px solid var(--gray-300); pointer-events:none; transition:border-color .2s; }
        .sw:focus-within::after { border-top-color:var(--accent); }

        .hint { font-size:.72rem; color:var(--gray-500); margin-top:.3rem; }
        .char-counter { font-size:.72rem; color:var(--gray-500); margin-top:.3rem; text-align:right; }
        .char-counter.near { color:var(--warning); } .char-counter.over { color:var(--error); font-weight:600; }

        /* HORARIOS */
        .horarios-list { display:flex; flex-direction:column; gap:.85rem; }
        .horario-row {
            background:var(--gray-50); border:1.5px solid var(--gray-100);
            border-radius:var(--radius-sm); padding:1.1rem 1.1rem .9rem;
            display:grid; grid-template-columns:1.4fr 1fr 1fr auto;
            gap:.75rem; align-items:end; position:relative;
            animation:fadeUp .25s ease both;
        }
        .horario-row:hover { border-color:var(--gray-200); }
        .horario-num { position:absolute; top:-10px; left:12px; background:var(--accent); color:#fff; border-radius:20px; padding:.1rem .6rem; font-size:.68rem; font-weight:700; font-family:"Outfit",sans-serif; }

        /* Tag "existente" vs "nuevo" */
        .horario-tag { position:absolute; top:-10px; right:12px; border-radius:20px; padding:.1rem .55rem; font-size:.65rem; font-weight:700; font-family:"Outfit",sans-serif; }
        .tag-exist { background:#e8f7f0; color:var(--success); border:1px solid #a5dfca; }
        .tag-nuevo { background:#e8f0fd; color:var(--accent); border:1px solid #c8deff; }

        .btn-remove { width:38px; height:38px; background:none; border:1.5px solid #fbd5d5; border-radius:var(--radius-sm); color:var(--error); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .2s; align-self:flex-end; }
        .btn-remove:hover { background:#fff5f5; border-color:var(--error); }
        .btn-remove:disabled { opacity:.3; cursor:not-allowed; }

        .btn-add-horario { display:flex; align-items:center; gap:.5rem; padding:.65rem 1.1rem; background:none; border:1.5px dashed var(--accent); border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.82rem; font-weight:600; color:var(--accent); cursor:pointer; width:100%; justify-content:center; transition:all .2s; margin-top:.6rem; }
        .btn-add-horario:hover { background:#f0f5ff; border-style:solid; }

        /* GESTIÓN DE ESTADO */
        .estado-zone { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; margin-bottom:1.5rem; }
        .estado-zone-top { padding:1rem 1.75rem; display:flex; align-items:center; gap:.75rem; border-bottom:1px solid var(--gray-100); }
        .estado-zone-top h3 { font-family:"Outfit",sans-serif; font-size:.92rem; font-weight:700; flex:1; }
        .estado-zone-body { padding:1.25rem 1.75rem; }
        .estado-desc { font-size:.82rem; color:var(--gray-700); line-height:1.6; margin-bottom:1.1rem; }

        /* Badges de estado */
        .badge-estado { display:inline-flex; align-items:center; gap:.35rem; padding:.28rem .85rem; border-radius:20px; font-family:"Outfit",sans-serif; font-size:.75rem; font-weight:700; white-space:nowrap; }
        .badge-borrador  { background:#f0f0ff; color:#5a4fcf; border:1px solid #c8c0ff; }
        .badge-apertura  { background:#e8f4ff; color:var(--accent); border:1px solid #bdd8ff; }
        .badge-iniciado  { background:#edfaf4; color:var(--success); border:1px solid #a5dfca; }
        .badge-finalizado{ background:#eef0f6; color:var(--gray-700); border:1px solid var(--gray-300); }
        .badge-cancelado { background:#fff0f0; color:var(--error); border:1px solid #fbd5d5; }

        /* Botones de transición */
        .transicion-grid { display:flex; gap:.6rem; flex-wrap:wrap; }
        .btn-transicion { height:38px; padding:0 1.1rem; border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.8rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:.35rem; transition:all .2s; border:1.5px solid; white-space:nowrap; }
        .btn-apertura  { background:#e8f4ff; color:var(--accent); border-color:#bdd8ff; }
        .btn-apertura:hover  { background:var(--accent); color:#fff; }
        .btn-iniciado  { background:#edfaf4; color:var(--success); border-color:#a5dfca; }
        .btn-iniciado:hover  { background:var(--success); color:#fff; }
        .btn-finalizado{ background:#eef0f6; color:var(--gray-700); border-color:var(--gray-300); }
        .btn-finalizado:hover{ background:var(--gray-700); color:#fff; }
        .btn-cancelado { background:#fff0f0; color:var(--error); border-color:#fbd5d5; }
        .btn-cancelado:hover { background:var(--error); color:#fff; }

        /* FORM FOOTER */
        .form-footer { display:flex; align-items:center; justify-content:flex-end; gap:.75rem; margin-top:1.75rem; padding-top:1.25rem; border-top:1px solid var(--gray-100); }
        .btn-cancel { height:46px; padding:0 1.4rem; background:none; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.9rem; font-weight:600; color:var(--gray-700); cursor:pointer; text-decoration:none; display:flex; align-items:center; gap:.4rem; transition:all .2s; }
        .btn-cancel:hover { background:var(--gray-50); border-color:var(--gray-300); }
        .btn-submit { height:46px; padding:0 1.8rem; background:var(--warning); color:#fff; border:none; border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.9rem; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:.5rem; transition:all .2s; box-shadow:0 3px 12px rgba(212,122,32,.25); }
        .btn-submit:hover { background:#bf6a10; box-shadow:0 6px 20px rgba(212,122,32,.35); transform:translateY(-1px); }
        .btn-submit:active { transform:translateY(0); }

        /* MODAL ELIMINAR */
        .modal-ov { display:none; position:fixed; inset:0; background:rgba(17,30,58,.55); backdrop-filter:blur(4px); z-index:100; align-items:center; justify-content:center; padding:1rem; }
        .modal-ov.open { display:flex; }
        .modal { background:var(--white); border-radius:var(--radius); box-shadow:0 24px 64px rgba(0,0,0,.22); width:100%; max-width:400px; overflow:hidden; animation:modalIn .25s ease both; }
        @keyframes modalIn { from{opacity:0;transform:scale(.94) translateY(10px)} to{opacity:1;transform:scale(1) translateY(0)} }
        .modal-top { background:linear-gradient(135deg,#c03030,var(--error)); padding:1.4rem 1.75rem; }
        .modal-top h3 { font-family:"Outfit",sans-serif; font-weight:700; font-size:1rem; color:#fff; }
        .modal-top p  { font-size:.78rem; color:rgba(255,255,255,.7); margin-top:.2rem; }
        .modal-bd { padding:1.4rem 1.75rem; }
        .modal-club-name { font-family:"Outfit",sans-serif; font-weight:700; font-size:.95rem; color:var(--text); background:var(--gray-50); border:1px solid var(--gray-200); border-radius:var(--radius-sm); padding:.65rem 1rem; margin-bottom:1rem; }
        .modal-warning { background:#fff5f5; border:1px solid #fbd5d5; border-left:3px solid var(--error); border-radius:var(--radius-sm); padding:.75rem 1rem; font-size:.8rem; color:#8b2020; margin-bottom:1.25rem; display:flex; gap:.5rem; align-items:flex-start; line-height:1.5; }
        .modal-btns { display:flex; gap:.75rem; }
        .modal-btns button { flex:1; height:42px; border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.88rem; font-weight:600; cursor:pointer; border:none; transition:all .2s; display:flex; align-items:center; justify-content:center; gap:.4rem; }
        .btn-cancelar { background:var(--gray-100); color:var(--gray-700); } .btn-cancelar:hover{background:var(--gray-200)}
        .btn-del { background:var(--error); color:#fff; } .btn-del:hover{background:#c03030}

        @keyframes fadeUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
        footer { text-align:center; padding:1.5rem; font-size:.72rem; color:var(--gray-500); }

        @media (max-width:640px) {
            .form-grid, .form-grid.cols3 { grid-template-columns:1fr; }
            .full { grid-column:1; }
            .horario-row { grid-template-columns:1fr 1fr; }
            .horario-row .fg:first-child { grid-column:1/-1; }
            header { padding:0 1rem; }
            nav a span { display:none; }
            .page { padding:1.25rem 1rem 3rem; }
        }
    </style>
</head>
<body>

<!-- HEADER -->
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
        <a href="mis_clubes.php" class="active">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span>Mis clubs</span>
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
        <div class="sub-name"><?= htmlspecialchars($enc['nombre']) ?></div>
        <div class="sub-det"><?= htmlspecialchars($enc['tipo']) ?> &nbsp;&middot;&nbsp; <?= htmlspecialchars($enc['plantel']) ?></div>
    </div>
    <div class="sub-badge">
        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        <?= htmlspecialchars($enc['plantel']) ?>
    </div>
</div>

<div class="page">

    <!-- BREADCRUMB -->
    <div class="breadcrumb">
        <a href="mis_clubes.php">Mis clubs</a>
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        <strong style="color:var(--text)">Editar club</strong>
    </div>

    <!-- TITLE -->
    <div class="page-title">
        <div class="page-title-icon">
            <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </div>
        <div>
            <h1>Editar club</h1>
            <p>Modifica la informaci&oacute;n, horarios y configuraci&oacute;n del club</p>
        </div>
    </div>

    <!-- ALERTAS -->
    <?php if ($msg_ok): ?>
    <div style="background:#edfaf4;border:1px solid #a5dfca;border-left:3px solid #2e9e6e;border-radius:10px;padding:.9rem 1.1rem;font-size:.85rem;color:#1a5e3f;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.6rem;">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <div><?= $msg_ok ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($errores)): ?>
    <div style="background:#fff5f5;border:1px solid #fbd5d5;border-left:3px solid #d94f4f;border-radius:10px;padding:.9rem 1.1rem;font-size:.84rem;color:#8b2020;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.6rem;">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <ul style="padding-left:1rem;margin:0">
            <?php foreach ($errores as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- CLUB ID BAR -->
    <?php
    $today2   = date('Y-m-d');
    $estadoC  = ($club['fecha_inicio'] <= $today2 && $club['fecha_fin'] >= $today2) ? 'activo' : (($club['fecha_inicio'] > $today2) ? 'proximo' : 'cerrado');
    $badgeMap = ['activo'=>'sb-activo','proximo'=>'sb-proximo','cerrado'=>'sb-cerrado'];
    $lblMap   = ['activo'=>'Activo','proximo'=>'Próximo','cerrado'=>'Cerrado'];
    ?>
    <div class="club-id-bar">
        <div class="club-id-left">
            <div class="club-id-avatar">
                <svg width="18" height="18" fill="none" stroke="white" stroke-width="2" viewBox="0 0 24 24"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
            </div>
            <div>
                <div class="club-id-nombre"><?= htmlspecialchars($club['nombre']) ?></div>
                <div style="display:flex;align-items:center;gap:.5rem;margin-top:.25rem;flex-wrap:wrap">
                    <span class="club-id-chip">
                        <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                        # <?= str_pad($club['id'], 3, '0', STR_PAD_LEFT) ?>
                    </span>
                    <span class="status-badge-sm <?= $badgeMap[$estadoC] ?>"><span class="dot"></span><?= $lblMap[$estadoC] ?></span>
                </div>
            </div>
        </div>
        <div style="font-size:.75rem;color:var(--gray-500);text-align:right">
            <div>Inicio: <strong><?= date('d M Y', strtotime($club['fecha_inicio'])) ?></strong></div>
            <div><?= $inscritos ?> alumnos inscritos</div>
        </div>
    </div>

    <?php if (!$es_editable): ?>
    <div style="background:#fff8ee;border:1px solid #f5d8a0;border-left:3px solid var(--warning);border-radius:10px;padding:.85rem 1.25rem;margin-bottom:1.25rem;font-size:.84rem;color:#7a4f10;display:flex;align-items:center;gap:.6rem">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <?php if ($club['estado'] === 'borrador' && ($club['autorizado'] ?? 'no') === 'si'): ?>
            Este club fue <strong>autorizado por el plantel</strong> y ya no puede modificarse. Puedes publicarlo cuando estés listo.
        <?php else: ?>
            Solo se pueden editar clubs en estado <strong>Borrador</strong> sin autorizar. En otros estados, aquí puedes ver los detalles y gestionar el estado.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" id="form-editar" novalidate <?= !$es_editable ? 'onsubmit="return false"' : '' ?>>
        <?php if (!$es_editable): ?>
        <style>
            /* Modo solo lectura */
            #form-editar input, #form-editar textarea, #form-editar select {
                pointer-events: none; background: var(--gray-50) !important;
                color: var(--gray-500) !important; cursor: default;
            }
            #form-editar .btn-add-horario, #form-editar .btn-remove { display: none; }
        </style>
        <?php endif; ?>

        <!-- CARD 1: INFO GENERAL -->
        <div class="card">
            <div class="card-top">
                <h2>
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Informaci&oacute;n general
                </h2>
                <p>Nombre y descripci&oacute;n que ven los alumnos al inscribirse</p>
            </div>
            <div class="card-body">
                <div class="form-grid">

                    <div class="fg full">
                        <label for="nombre">Nombre del club <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <input type="text" id="nombre" name="nombre"
                                   value="<?= htmlspecialchars($club['nombre']) ?>"
                                   maxlength="50"
                                   oninput="contador(this,'cnt-nombre',50)"
                                   required>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-top:.3rem">
                            <span class="hint">M&aacute;ximo 50 caracteres</span>
                            <span class="char-counter" id="cnt-nombre">23/50</span>
                        </div>
                    </div>

                    <div class="fg full">
                        <label for="descripcion">Descripci&oacute;n <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="top:1.1rem;transform:none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            <textarea id="descripcion" name="descripcion"
                                      maxlength="150"
                                      oninput="contador(this,'cnt-desc',150)"
                                      required><?= htmlspecialchars($club['descripcion']) ?></textarea>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-top:.3rem">
                            <span class="hint">M&aacute;ximo 150 caracteres</span>
                            <span class="char-counter" id="cnt-desc">107/150</span>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- CARD 2: PERIODO Y CAPACIDAD -->
        <div class="card">
            <div class="card-top">
                <h2>
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Periodo y capacidad
                </h2>
                <p>Fechas del ciclo y n&uacute;mero m&aacute;ximo de integrantes</p>
            </div>
            <div class="card-body">
                <div class="form-grid cols3">

                    <div class="fg">
                        <label for="fecha_inicio">Fecha de inicio <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <input type="date" id="fecha_inicio" name="fecha_inicio"
                                   value="<?= htmlspecialchars($club['fecha_inicio']) ?>"
                                   onchange="validarFechas()" required>
                        </div>
                    </div>

                    <div class="fg">
                        <label for="fecha_limite_registro">Fecha límite de registro</label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8" y2="14"/></svg>
                            <input type="date" id="fecha_limite_registro" name="fecha_limite_registro"
                                   value="<?= htmlspecialchars($club['fecha_limite_registro'] ?? '') ?>">
                        </div>
                        <p class="hint">Último día en que los alumnos pueden inscribirse (requerido para estado Apertura)</p>
                    </div>

                    <div class="fg">
                        <label for="fecha_fin">Fecha de fin <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <input type="date" id="fecha_fin" name="fecha_fin"
                                   value="<?= htmlspecialchars($club['fecha_fin']) ?>"
                                   onchange="validarFechas()" required>
                        </div>
                        <p class="hint" id="hint-fechas"></p>
                    </div>

                    <div class="fg">
                        <label for="limite">L&iacute;mite de integrantes <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <input type="number" id="limite" name="limite"
                                   value="<?= $club['limite'] ?>" min="5" max="127" required>
                        </div>
                        <p class="hint">Actualmente: <strong><?= $inscritos ?> inscritos</strong></p>
                    </div>

                    <div class="fg">
                        <label for="anio">A&ntilde;o <span class="req">*</span></label>
                        <div class="iw sw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <select id="anio" name="anio" required>
                                <?php foreach ([2025,2026,2027,2028] as $y): ?>
                                <option value="<?= $y ?>" <?= $club['anio'] == $y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="fg">
                        <label for="semestre">Semestres <span class="req">*</span></label>
                        <div class="iw sw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/></svg>
                            <select id="semestre" name="semestre" required>
                                <option value="">Selecciona…</option>
                                <option value="par"   <?= $club['semestre'] === 'par'   ? 'selected' : '' ?>>Semestres pares (2°, 4°, 6°)</option>
                                <option value="impar" <?= $club['semestre'] === 'impar' ? 'selected' : '' ?>>Semestres impares (1°, 3°, 5°)</option>
                            </select>
                        </div>
                        <p class="hint">Ciclo al que va dirigido el club</p>
                    </div>

                </div>
            </div>
        </div>

        <!-- CARD 3: HORARIOS -->
        <div class="card">
            <div class="card-top">
                <h2>
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Horarios de sesi&oacute;n
                </h2>
                <p>Los horarios guardados se muestran en verde; los nuevos en azul</p>
            </div>
            <div class="card-body">

                <div class="horarios-list" id="horarios-list">
                <?php
                $dias_opt = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
                $h_list   = !empty($horarios) ? $horarios : [['dia'=>'','hora_inicio'=>'','hora_fin'=>'']];
                foreach ($h_list as $hi => $h):
                    $ini_val = substr($h['hora_inicio'] ?? '', 0, 5);
                    $fin_val = substr($h['hora_fin']    ?? '', 0, 5);
                ?>
                    <div class="horario-row" id="hr-<?= $hi ?>">
                        <span class="horario-num"><?= $hi+1 ?></span>
                        <?php if (!empty($horarios)): ?>
                        <span class="horario-tag tag-exist">Guardado</span>
                        <?php endif; ?>
                        <div class="fg">
                            <label>Día <span class="req">*</span></label>
                            <div class="iw sw">
                                <svg class="icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <select name="dia[]" required>
                                    <option value="">Selecciona…</option>
                                    <?php foreach ($dias_opt as $d): ?>
                                    <option <?= ($h['dia'] ?? '') === $d ? 'selected' : '' ?>><?= $d ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="fg">
                            <label>Hora inicio <span class="req">*</span></label>
                            <div class="iw">
                                <svg class="icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <input type="time" name="hora_inicio[]" value="<?= $ini_val ?>" required>
                            </div>
                        </div>
                        <div class="fg">
                            <label>Hora fin <span class="req">*</span></label>
                            <div class="iw">
                                <svg class="icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <input type="time" name="hora_fin[]" value="<?= $fin_val ?>" required>
                            </div>
                        </div>
                        <button type="button" class="btn-remove"
                            <?= $hi === 0 ? 'disabled title="Agrega otro horario para poder eliminar este"' : 'onclick="removeHorario(\'hr-'.$hi.'\')"' ?>>
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                        </button>
                    </div>
                <?php endforeach; ?>
                </div>

                <button type="button" class="btn-add-horario" onclick="addHorario()">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Agregar otro horario
                </button>

            </div>
        </div>

        <!-- FOOTER -->
        <div class="form-footer">
            <a href="mis_clubes.php" class="btn-cancel">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Cancelar
            </a>
            <?php if ($es_editable): ?>
            <button type="submit" name="guardar" class="btn-submit">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                Guardar cambios
            </button>
            <?php endif; ?>
        </div>

    </form>

    <!-- GESTIÓN DE ESTADO DEL CLUB -->
    <?php
    $estado_actual = $club['estado'] ?? 'borrador';
    $labels = [
        'borrador'  => ['txt'=>'Borrador',  'icon'=>'✏️',  'cls'=>'badge-borrador',  'desc'=>'Solo visible para ti. Edítalo libremente antes de publicarlo.'],
        'apertura'  => ['txt'=>'Apertura',  'icon'=>'📬',  'cls'=>'badge-apertura',  'desc'=>'Visible para alumnos. Pueden inscribirse hasta la fecha límite de registro.'],
        'iniciado'  => ['txt'=>'Iniciado',  'icon'=>'▶️',  'cls'=>'badge-iniciado',  'desc'=>'El club está en curso. Ya no se aceptan nuevas inscripciones.'],
        'finalizado'=> ['txt'=>'Finalizado','icon'=>'✅', 'cls'=>'badge-finalizado', 'desc'=>'El club ha concluido. Los datos históricos se conservan.'],
        'cancelado' => ['txt'=>'Cancelado', 'icon'=>'❌', 'cls'=>'badge-cancelado',  'desc'=>'Club cancelado. No aparece en el portal de alumnos.'],
    ];
    $lbl = $labels[$estado_actual];

    // Transiciones permitidas (Borrador→Apertura solo si autorizado='si')
    $es_autorizado = ($club['autorizado'] ?? 'no') === 'si';
    $siguiente = [
        'borrador'  => array_filter([
                        $es_autorizado
                            ? ['estado'=>'apertura',  'lbl'=>'Publicar para inscripciones', 'cls'=>'btn-apertura']
                            : null,
                        ['estado'=>'cancelado', 'lbl'=>'Cancelar club', 'cls'=>'btn-cancelado'],
                       ]),
        'apertura'  => [['estado'=>'iniciado',  'lbl'=>'Marcar como Iniciado', 'cls'=>'btn-iniciado'],
                        ['estado'=>'cancelado', 'lbl'=>'Cancelar club', 'cls'=>'btn-cancelado']],
        'iniciado'  => [['estado'=>'finalizado','lbl'=>'Marcar como Finalizado', 'cls'=>'btn-finalizado'],
                        ['estado'=>'cancelado', 'lbl'=>'Cancelar club', 'cls'=>'btn-cancelado']],
        'finalizado'=> [],
        'cancelado' => [['estado'=>'borrador',  'lbl'=>'Restaurar como Borrador', 'cls'=>'btn-apertura']],
    ];
    ?>
    <?php if (isset($_GET['estado_ok'])): ?>
    <div style="background:#edfaf4;border:1px solid #a5dfca;border-left:3px solid var(--success);border-radius:var(--radius);padding:.9rem 1.25rem;margin-bottom:1.25rem;font-size:.84rem;color:#1a5e3f;display:flex;align-items:center;gap:.6rem">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Estado actualizado a <strong><?= htmlspecialchars($labels[$_GET['estado_ok']]['txt'] ?? $_GET['estado_ok']) ?></strong> correctamente.
    </div>
    <?php endif; ?>

    <div class="estado-zone">
        <div class="estado-zone-top">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <h3>Estado del club</h3>
            <span class="badge-estado <?= $lbl['cls'] ?>"><?= $lbl['icon'] ?> <?= $lbl['txt'] ?></span>
            <?php if ($estado_actual === 'borrador'): ?>
                <?php if ($es_autorizado): ?>
                <span class="badge-estado" style="background:#edfaf4;color:#1a5e3f;border:1px solid #a5dfca">✅ Autorizado por plantel</span>
                <?php else: ?>
                <span class="badge-estado" style="background:#fff8ee;color:#7a4f10;border:1px solid #f5d8a0">⏳ Pendiente de autorización</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="estado-zone-body">
            <p class="estado-desc"><?= $lbl['desc'] ?></p>

            <?php if ($estado_actual === 'borrador' && !$es_autorizado): ?>
            <div style="background:#fff8ee;border:1px solid #f5d8a0;border-left:3px solid var(--warning);border-radius:8px;padding:.75rem 1rem;font-size:.82rem;color:#7a4f10;margin-bottom:1rem;line-height:1.6">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:.35rem"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Este club aún <strong>no ha sido autorizado</strong> por el administrador del plantel.
                Para poder publicarlo debes solicitar la revisión al responsable de
                <strong><?= htmlspecialchars($enc['plantel']) ?></strong>.
                Una vez autorizado aparecerá el botón para publicarlo.
            </div>
            <?php endif; ?>

            <?php if (!empty($siguiente[$estado_actual])): ?>
            <p style="font-size:.78rem;font-weight:600;color:var(--gray-500);text-transform:uppercase;letter-spacing:.5px;margin-bottom:.6rem">Cambiar estado:</p>
            <div class="transicion-grid">
                <?php foreach ($siguiente[$estado_actual] as $t): ?>
                <form method="POST" action="" style="display:inline"
                    onsubmit="return confirm('¿Cambiar estado a «<?= htmlspecialchars($labels[$t['estado']]['txt']) ?>»?')">
                    <input type="hidden" name="cambiar_estado" value="<?= $t['estado'] ?>">
                    <button type="submit" class="btn-transicion <?= $t['cls'] ?>">
                        <?= $labels[$t['estado']]['icon'] ?> <?= htmlspecialchars($t['lbl']) ?>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="font-size:.82rem;color:var(--gray-500)">No hay transiciones disponibles desde este estado.</p>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /page -->

<footer>&copy; <?= date('Y') ?> Universidad de Colima &mdash; Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>

<script>
// Contador de caracteres
function contador(el, id, max) {
    const n = el.value.length;
    const s = document.getElementById(id);
    s.textContent = n + "/" + max;
    s.className   = "char-counter" + (n >= max ? " over" : n >= max * .85 ? " near" : "");
}

// Validacion fechas
function validarFechas() {
    const fi = document.getElementById("fecha_inicio").value;
    const ff = document.getElementById("fecha_fin").value;
    const h  = document.getElementById("hint-fechas");
    if (!fi || !ff) return;
    const dias = Math.round((new Date(ff) - new Date(fi)) / 86400000);
    if (dias <= 0) {
        h.textContent = "⚠ La fecha fin debe ser posterior a la de inicio";
        h.style.color = "var(--error)";
    } else {
        h.textContent = "✓ Duración: " + dias + " días";
        h.style.color = "var(--success)";
    }
}

// Horarios dinamicos
let count = 2;

function addHorario() {
    const idx  = count++;
    const list = document.getElementById("horarios-list");
    const div  = document.createElement("div");
    div.className = "horario-row";
    div.id = "hr-" + idx;
    div.innerHTML = \`
        <span class="horario-num">\${idx + 1}</span>
        <span class="horario-tag tag-nuevo">Nuevo</span>
        <div class="fg">
            <label>D&iacute;a <span class="req">*</span></label>
            <div class="iw sw">
                <svg class="icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <select name="dia[]" required>
                    <option value="">Selecciona...</option>
                    <option>Lunes</option><option>Martes</option><option>Mi&eacute;rcoles</option>
                    <option>Jueves</option><option>Viernes</option><option>S&aacute;bado</option>
                </select>
            </div>
        </div>
        <div class="fg">
            <label>Hora inicio <span class="req">*</span></label>
            <div class="iw">
                <svg class="icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <input type="time" name="hora_inicio[]" required>
            </div>
        </div>
        <div class="fg">
            <label>Hora fin <span class="req">*</span></label>
            <div class="iw">
                <svg class="icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <input type="time" name="hora_fin[]" required>
            </div>
        </div>
        <button type="button" class="btn-remove" onclick="removeHorario('hr-\${idx}')" title="Eliminar horario">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
        </button>\`;
    list.appendChild(div);
    actualizarBotones();
}

function removeHorario(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.transition = "opacity .2s, transform .2s";
    el.style.opacity    = "0";
    el.style.transform  = "translateY(-4px)";
    setTimeout(() => { el.remove(); actualizarBotones(); renumerar(); }, 200);
}

function actualizarBotones() {
    const rows = document.querySelectorAll(".horario-row");
    rows.forEach(row => {
        const btn = row.querySelector(".btn-remove");
        if (!btn) return;
        btn.disabled      = rows.length === 1;
        btn.title         = rows.length === 1 ? "Necesitas al menos un horario" : "Eliminar horario";
    });
}

function renumerar() {
    document.querySelectorAll(".horario-num").forEach((el, i) => el.textContent = i + 1);
}

// Modal
function abrirModal() {
    document.getElementById("modal-eliminar").classList.add("open");
    document.body.style.overflow = "hidden";
}
function cerrarModal() {
    document.getElementById("modal-eliminar").classList.remove("open");
    document.body.style.overflow = "";
}
document.getElementById("modal-eliminar").addEventListener("click", e => {
    if (e.target === document.getElementById("modal-eliminar")) cerrarModal();
});
document.addEventListener("keydown", e => { if (e.key === "Escape") cerrarModal(); });
</script>

</body>
</html>