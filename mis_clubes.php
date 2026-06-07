<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sincronizar_estados.php';

if (empty($_SESSION['encargado_id'])) { header('Location: login_encargado.php'); exit; }
if (isset($_GET['logout'])) { session_destroy(); header('Location: login_encargado.php'); exit; }

sincronizarEstadosClubes();

$enc = [
    'id'      => $_SESSION['encargado_id'],
    'nombre'  => $_SESSION['encargado_nombre']  ?? 'Encargado',
    'tipo'    => $_SESSION['encargado_tipo']    ?? '',
    'plantel' => $_SESSION['encargado_plantel'] ?? '',
];
$partes   = explode(' ', $enc['nombre']);
$iniciales = '';
foreach (array_slice($partes, 0, 2) as $p) $iniciales .= mb_substr($p, 0, 1);
$today = date('Y-m-d');

// ── Cambio de estado desde la tarjeta ───────────────────────────
$msg_estado_ok  = '';
$msg_estado_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estado_club'])) {
    $cid_post    = (int)($_POST['club_id']       ?? 0);
    $nuevo_post  = trim($_POST['nuevo_estado']   ?? '');
    $estados_ok  = ['apertura','iniciado','finalizado','cancelado','borrador'];
    if ($cid_post && in_array($nuevo_post, $estados_ok, true)) {
        try {
            // Verificar que el club pertenece a este encargado y cumple la regla
            $chk = getDB()->prepare(
                "SELECT id, estado, autorizado FROM clubes WHERE id = ? AND id_encargado = ?"
            );
            $chk->execute([$cid_post, (int)$enc['id']]);
            $club_chk = $chk->fetch();

            $error_trans = null;
            if (!$club_chk) {
                $error_trans = 'Club no encontrado.';
            } elseif ($nuevo_post === 'apertura' && $club_chk['autorizado'] !== 'si') {
                $error_trans = 'El club debe estar autorizado por el plantel antes de publicarse.';
            } elseif ($nuevo_post === 'finalizado' && $club_chk['estado'] !== 'iniciado') {
                $error_trans = 'Solo se puede finalizar un club que esté en estado Iniciado.';
            } elseif ($nuevo_post === 'cancelado' && $club_chk['estado'] === 'iniciado') {
                $error_trans = 'No se puede cancelar un club que ya está Iniciado.';
            }

            if ($error_trans) {
                $msg_estado_err = $error_trans;
            } else {
                $revocar = ($nuevo_post === 'borrador' && $club_chk['estado'] === 'cancelado');
                getDB()->prepare(
                    "UPDATE clubes SET estado = ?" . ($revocar ? ", autorizado = 'no', restaurado = 1" : "") . " WHERE id = ? AND id_encargado = ?"
                )->execute([$nuevo_post, $cid_post, (int)$enc['id']]);
                $msg_estado_ok = 'Estado actualizado a <strong>' . ucfirst($nuevo_post) . '</strong>.'
                    . ($revocar ? ' La autorización del plantel fue revocada; el plantel deberá volver a autorizarlo.' : '');
            }
        } catch (Exception $e) {
            $msg_estado_err = 'Error al cambiar el estado.';
        }
    }
}

// ── Consultas ────────────────────────────────────────────────────
$clubs       = [];
$horarios_map = [];
$stats = ['total'=>0,'activos'=>0,'alumnos'=>0,'cupos'=>0];

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT c.id, c.nombre, c.descripcion, c.semestre, c.limite,
               c.fecha_inicio, c.fecha_fin, c.fecha_limite_registro, c.estado, c.autorizado,
               COUNT(DISTINCT ic.numero_cuenta) AS inscritos,
               CASE WHEN c.id_encargado = :id2 THEN 0 ELSE 1 END AS es_auxiliar
        FROM clubes c
        LEFT JOIN inscripciones_club ic ON ic.id_club = c.id
        LEFT JOIN auxiliares_club ac ON ac.id_club = c.id AND ac.id_persona = :id3
        WHERE (c.id_encargado = :id OR ac.id_persona IS NOT NULL)
        GROUP BY c.id
        ORDER BY FIELD(c.estado,'apertura','iniciado','borrador','finalizado','cancelado'),
                 c.fecha_inicio DESC, c.nombre
    ");
    $stmt->execute([':id' => (int)$enc['id'], ':id2' => (int)$enc['id'], ':id3' => (int)$enc['id']]);
    $clubs = $stmt->fetchAll();

    // Horarios de todos los clubs en una sola consulta
    if ($clubs) {
        $ids = implode(',', array_column($clubs,'id'));
        $h_rows = $pdo->query("
            SELECT id_club, dia, TIME_FORMAT(hora_inicio,'%H:%i') AS ini, TIME_FORMAT(hora_fin,'%H:%i') AS fin
            FROM horarios WHERE id_club IN ($ids)
            ORDER BY FIELD(dia,'Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'), hora_inicio
        ")->fetchAll();
        foreach ($h_rows as $h) $horarios_map[$h['id_club']][] = $h;
    }

    // Stats
    foreach ($clubs as $c) {
        $stats['total']++;
        if (in_array($c['estado'], ['apertura','iniciado'])) $stats['activos']++;
        $stats['alumnos'] += (int)$c['inscritos'];
        $stats['cupos']   += max(0, (int)$c['limite'] - (int)$c['inscritos']);
    }
} catch (Exception $e) {
    error_log('mis_clubes BD: ' . $e->getMessage());
}

$bar_colors = ['bar-blue','bar-green','bar-orange','bar-navy'];

// Con los nuevos estados, el estado viene directamente de la BD
function clubEstado(string $estadoDB): string {
    return $estadoDB; // borrador | apertura | iniciado | finalizado | cancelado
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Clubs — Bachillerato 23</title>
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
        .sub-badge { margin-left:auto; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25); border-radius:20px; padding:.2rem .75rem; font-size:.72rem; color:rgba(255,255,255,.85); font-family:"Outfit",sans-serif; display:flex; align-items:center; gap:.35rem; }

        /* PAGE */
        .page { max-width:1060px; margin:0 auto; padding:2rem 1.5rem 4rem; }

        /* TOPBAR */
        .topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.75rem; flex-wrap:wrap; gap:1rem; }
        .topbar-left h1 { font-family:"Outfit",sans-serif; font-size:1.4rem; font-weight:700; color:var(--navy); }
        .topbar-left p  { font-size:.83rem; color:var(--gray-500); margin-top:.15rem; }
        .btn-nuevo { height:42px; padding:0 1.25rem; background:var(--accent); color:#fff; border:none; border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.85rem; font-weight:600; cursor:pointer; text-decoration:none; display:flex; align-items:center; gap:.4rem; transition:all .2s; white-space:nowrap; box-shadow:0 3px 12px rgba(74,127,212,.25); }
        .btn-nuevo:hover { background:var(--accent-h); transform:translateY(-1px); box-shadow:0 5px 16px rgba(74,127,212,.35); }

        /* STATS */
        .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.75rem; }
        .stat-card { background:var(--white); border-radius:var(--radius-sm); box-shadow:var(--shadow); padding:1.1rem 1.25rem; display:flex; align-items:center; gap:.9rem; animation:fadeUp .3s ease both; }
        .stat-card:nth-child(2){ animation-delay:.05s } .stat-card:nth-child(3){ animation-delay:.1s } .stat-card:nth-child(4){ animation-delay:.15s }
        .stat-icon { width:40px; height:40px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
        .si-blue{background:#e8f0fd;color:var(--accent)} .si-green{background:#e8f7f0;color:var(--success)} .si-orange{background:#fff3e0;color:var(--warning)} .si-navy{background:#eef0f6;color:var(--navy)}
        .stat-num { font-family:"Outfit",sans-serif; font-size:1.5rem; font-weight:700; color:var(--text); line-height:1; }
        .stat-lbl { font-size:.75rem; color:var(--gray-500); margin-top:.2rem; }

        /* FILTROS */
        .filters { display:flex; align-items:center; gap:.75rem; margin-bottom:1.25rem; flex-wrap:wrap; }
        .filter-label { font-size:.75rem; font-weight:600; color:var(--gray-500); text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }
        .filter-chip { padding:.3rem .85rem; border-radius:20px; font-family:"Outfit",sans-serif; font-size:.78rem; font-weight:600; cursor:pointer; border:1.5px solid var(--gray-200); background:var(--white); color:var(--gray-700); transition:all .2s; white-space:nowrap; }
        .filter-chip:hover { border-color:var(--accent); color:var(--accent); background:#f0f5ff; }
        .filter-chip.active { background:var(--accent); color:#fff; border-color:var(--accent); }
        .search-wrap { position:relative; margin-left:auto; }
        .search-wrap svg { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:var(--gray-300); pointer-events:none; }
        .search-wrap input { height:36px; width:220px; padding:0 .9rem 0 2.3rem; border:1.5px solid var(--gray-200); border-radius:20px; font-family:"DM Sans",sans-serif; font-size:.83rem; color:var(--text); background:var(--white); outline:none; transition:all .2s; }
        .search-wrap input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(74,127,212,.1); width:260px; }
        .search-wrap input::placeholder { color:var(--gray-300); }

        /* GRID — máximo 2 columnas, mínimo 420px para que se vea bien */
        .clubs-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(420px,1fr)); gap:1.25rem; }

        /* CLUB CARD */
        .club-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; transition:box-shadow .2s, transform .2s; animation:fadeUp .35s ease both; display:flex; flex-direction:column; }
        .club-card:hover { box-shadow:var(--shadow-lg); transform:translateY(-2px); }
        .club-card-bar { height:4px; }
        .bar-blue   { background:linear-gradient(90deg,var(--accent),#7aa8e8); }
        .bar-green  { background:linear-gradient(90deg,var(--success),#5dc99a); }
        .bar-orange{ background:linear-gradient(90deg,var(--warning),#f0a85a); }
        .bar-navy  { background:linear-gradient(90deg,var(--navy),#3a5494); }
        .bar-purple{ background:linear-gradient(90deg,#7b5ea7,#a98bd4); }

        .club-card-body { padding:1.25rem 1.4rem 1rem; flex:1; }
        .cc-header { display:flex; align-items:flex-start; justify-content:space-between; gap:.5rem; margin-bottom:.65rem; }
        .cc-id { font-family:"Outfit",sans-serif; font-size:.7rem; font-weight:600; color:var(--gray-500); background:var(--gray-50); border:1px solid var(--gray-200); border-radius:20px; padding:.15rem .6rem; white-space:nowrap; }
        .status-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .65rem; border-radius:20px; font-size:.7rem; font-weight:600; font-family:"Outfit",sans-serif; white-space:nowrap; }
        .status-badge .dot { width:6px; height:6px; border-radius:50%; }
        .status-borrador  { background:#f0f0ff; color:#5a4fcf; }   .status-borrador .dot  { background:#5a4fcf; }
        .status-apertura  { background:#e8f4ff; color:#2a5ea8; }   .status-apertura .dot  { background:var(--accent); }
        .status-iniciado  { background:#e8f7f0; color:#1d6344; }   .status-iniciado .dot  { background:var(--success); }
        .status-finalizado{ background:#f0f0f6; color:var(--gray-500); } .status-finalizado .dot { background:var(--gray-300); }
        .status-cancelado { background:#fff0f0; color:#8b2020; }   .status-cancelado .dot { background:var(--error); }

        .cc-nombre { font-family:"Outfit",sans-serif; font-size:1rem; font-weight:700; color:var(--text); margin-bottom:.3rem; line-height:1.3; }
        .cc-desc { font-size:.8rem; color:var(--gray-500); line-height:1.5; margin-bottom:.9rem; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }

        .cc-info { display:flex; flex-direction:column; gap:.4rem; margin-bottom:.9rem; }
        .cc-info-row { display:flex; align-items:center; gap:.5rem; font-size:.79rem; color:var(--gray-700); }
        .cc-info-row svg { color:var(--gray-300); flex-shrink:0; }
        .cc-info-row strong { color:var(--text); font-weight:600; }

        .horarios-chips { display:flex; flex-wrap:wrap; gap:.35rem; margin-bottom:.75rem; }
        .horario-chip { display:inline-flex; align-items:center; gap:.3rem; background:var(--gray-50); border:1px solid var(--gray-200); border-radius:6px; padding:.25rem .6rem; font-size:.72rem; color:var(--gray-700); font-weight:500; }
        .horario-chip svg { color:var(--accent); }

        .cupo-section { margin-bottom:.4rem; }
        .cupo-label { display:flex; justify-content:space-between; align-items:center; margin-bottom:.35rem; }
        .cupo-label span { font-size:.73rem; color:var(--gray-500); }
        .cupo-label strong { font-family:"Outfit",sans-serif; font-size:.78rem; font-weight:700; }
        .libre{color:var(--success)} .medio{color:var(--warning)} .lleno{color:var(--error)}
        .cupo-bar { height:6px; background:var(--gray-100); border-radius:6px; overflow:hidden; }
        .cupo-fill { height:100%; border-radius:6px; transition:width .4s; }
        .cupo-fill.libre{background:var(--success)} .cupo-fill.medio{background:var(--warning)} .cupo-fill.lleno{background:var(--error)}

        .club-card-footer { padding:.9rem 1.4rem; border-top:1px solid var(--gray-100); display:flex; flex-direction:column; gap:.5rem; }
        .footer-row { display:flex; gap:.5rem; }
        .btn-card { flex:1; height:36px; border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.78rem; font-weight:600; cursor:pointer; border:none; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:.35rem; transition:all .2s; }
        .btn-primary-c { background:var(--accent); color:#fff; box-shadow:0 2px 8px rgba(74,127,212,.25); } .btn-primary-c:hover{background:var(--accent-h); transform:translateY(-1px); box-shadow:0 4px 12px rgba(74,127,212,.35);}
        .btn-gray-c { background:var(--gray-100); color:var(--gray-700); border:1.5px solid var(--gray-200)!important; } .btn-gray-c:hover{background:var(--gray-200); color:var(--text);}
        .btn-danger-c  { background:#fff5f5; color:var(--error); border:1.5px solid #fbd5d5!important; flex:0; width:38px; } .btn-danger-c:hover{background:#ffe8e8}

        /* MODAL */
        .modal-ov { display:none; position:fixed; inset:0; background:rgba(17,30,58,.5); backdrop-filter:blur(3px); z-index:100; align-items:center; justify-content:center; padding:1rem; }
        .modal-ov.open { display:flex; }
        .modal { background:var(--white); border-radius:var(--radius); box-shadow:0 24px 64px rgba(0,0,0,.22); width:100%; max-width:400px; overflow:hidden; animation:modalIn .25s ease both; }
        @keyframes modalIn { from{opacity:0;transform:scale(.94) translateY(10px)} to{opacity:1;transform:scale(1) translateY(0)} }
        .modal-top { background:linear-gradient(135deg,var(--navy),var(--navy-light)); padding:1.4rem 1.75rem; }
        .modal-top h3 { font-family:"Outfit",sans-serif; font-weight:700; font-size:1rem; color:#fff; }
        .modal-top p  { font-size:.78rem; color:rgba(255,255,255,.6); margin-top:.2rem; }
        .modal-bd { padding:1.4rem 1.75rem; }
        .modal-club-name { font-family:"Outfit",sans-serif; font-weight:700; font-size:.95rem; color:var(--text); background:var(--gray-50); border:1px solid var(--gray-200); border-radius:var(--radius-sm); padding:.65rem 1rem; margin-bottom:1rem; }
        .modal-warning { background:#fff5f5; border:1px solid #fbd5d5; border-left:3px solid var(--error); border-radius:var(--radius-sm); padding:.75rem 1rem; font-size:.8rem; color:#8b2020; margin-bottom:1.25rem; display:flex; gap:.5rem; align-items:flex-start; line-height:1.5; }
        .modal-btns { display:flex; gap:.75rem; }
        .modal-btns button { flex:1; height:42px; border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.88rem; font-weight:600; cursor:pointer; border:none; transition:all .2s; display:flex; align-items:center; justify-content:center; gap:.4rem; }
        .btn-cancelar { background:var(--gray-100); color:var(--gray-700); } .btn-cancelar:hover{background:var(--gray-200)}
        .btn-eliminar { background:var(--error); color:#fff; } .btn-eliminar:hover{background:#c03030}

        @keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        footer { text-align:center; padding:1.5rem; font-size:.72rem; color:var(--gray-500); }

        @media (max-width:700px) {
            .clubs-grid { grid-template-columns:1fr; }
            .page { padding:1.25rem 1rem 3rem; }
            header { padding:0 1rem; }
            nav a span { display:none; }
        }

        /* MODAL QR — ESCANER */
        .qr-capture-input { position:absolute; opacity:0; width:1px; height:1px; pointer-events:none; }
        .qr-log-item { display:flex; align-items:center; justify-content:space-between; padding:.35rem .65rem; border-radius:6px; background:var(--gray-50); border:1px solid var(--gray-100); font-size:.78rem; }
        .qr-log-badge { font-size:.68rem; font-weight:700; padding:.15rem .5rem; border-radius:20px; }
        .qr-log-badge.entrada { background:#edfaf4; color:#1a5e3f; }
        .qr-log-badge.salida  { background:#e8f4ff; color:#2a5ea8; }
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

    <?php if (isset($_GET['cerrado'])): ?>
    <div style="background:#edfaf4;border:1px solid #a5dfca;border-left:3px solid #2e9e6e;border-radius:10px;padding:.85rem 1.1rem;font-size:.84rem;color:#1a5e3f;margin-bottom:1.25rem;display:flex;align-items:center;gap:.6rem">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Club marcado como <strong>cerrado</strong>. Los datos históricos se conservan.
    </div>
    <?php endif; ?>

    <div class="topbar">
        <div class="topbar-left">
            <h1>Mis clubs</h1>
            <p>Ciclo <?= date('Y') ?> &nbsp;&middot;&nbsp; <?= htmlspecialchars($enc['plantel']) ?></p>
        </div>
        <a href="registrar_club.php" class="btn-nuevo">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Registrar nuevo club
        </a>
    </div>

    <!-- FILTROS POR ESTADO (reemplazan las stat cards) -->
    <?php
    $conteos_estado = ['todos'=>count($clubs),'borrador'=>0,'apertura'=>0,'iniciado'=>0,'finalizado'=>0,'cancelado'=>0];
    foreach ($clubs as $cx) {
        $est = $cx['estado'] ?? 'borrador';
        if (isset($conteos_estado[$est])) $conteos_estado[$est]++;
    }
    $filtros_cfg = [
        'todos'     => ['lbl'=>'Todos',      'color'=>'#3d4260','bg'=>'#eef0f6'],
        'borrador'  => ['lbl'=>'Borrador',   'color'=>'#5a4fcf','bg'=>'#f0f0ff'],
        'apertura'  => ['lbl'=>'Apertura',   'color'=>'#4a7fd4','bg'=>'#e8f4ff'],
        'iniciado'  => ['lbl'=>'Iniciado',   'color'=>'#2e9e6e','bg'=>'#edfaf4'],
        'finalizado'=> ['lbl'=>'Finalizado', 'color'=>'#7a8099','bg'=>'#f7f8fc'],
        'cancelado' => ['lbl'=>'Cancelado',  'color'=>'#d94f4f','bg'=>'#fff0f0'],
    ];
    ?>
    <div class="filters" style="gap:.5rem;flex-wrap:wrap;margin-bottom:1.4rem">
        <?php foreach ($filtros_cfg as $fkey => $fcfg): ?>
        <button class="filter-chip <?= $fkey==='todos'?'active':'' ?>"
            onclick="filtrar(this,'<?= $fkey ?>')"
            style="display:inline-flex;align-items:center;gap:.4rem;padding:.38rem .9rem;border-radius:20px;
                   font-family:'Outfit',sans-serif;font-size:.8rem;font-weight:600;cursor:pointer;
                   border:1.5px solid <?= $fcfg['bg'] ?>;background:var(--white);color:var(--gray-700);
                   transition:all .2s"
            data-color="<?= $fcfg['color'] ?>"
            data-bg="<?= $fcfg['bg'] ?>">
            <?= $fcfg['lbl'] ?>
            <span style="background:<?= $fcfg['bg'] ?>;color:<?= $fcfg['color'] ?>;border-radius:10px;padding:0 .45rem;font-size:.7rem;font-weight:700">
                <?= $conteos_estado[$fkey] ?>
            </span>
        </button>
        <?php endforeach; ?>
        <div class="search-wrap" style="margin-left:auto">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" placeholder="Buscar club&hellip;" oninput="buscar(this.value)">
        </div>
    </div>

    <?php if ($msg_estado_ok): ?>
    <div style="background:#edfaf4;border:1px solid #a5dfca;border-left:3px solid #2e9e6e;border-radius:8px;padding:.8rem 1rem;font-size:.84rem;color:#1a5e3f;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?= $msg_estado_ok ?>
    </div>
    <?php endif; ?>
    <?php if ($msg_estado_err): ?>
    <div style="background:#fff5f5;border:1px solid #fbd5d5;border-left:3px solid #d94f4f;border-radius:8px;padding:.8rem 1rem;font-size:.84rem;color:#8b2020;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($msg_estado_err) ?>
    </div>
    <?php endif; ?>

    <!-- GRID -->
    <div class="clubs-grid" id="clubs-grid">

    <?php if (empty($clubs)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:3rem 1rem;color:var(--gray-500)">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 1rem;display:block;opacity:.4"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <p style="font-size:.95rem;font-weight:600;margin-bottom:.4rem">Aún no tienes clubs registrados</p>
            <a href="registrar_club.php" style="color:var(--accent);font-size:.85rem;font-weight:600">+ Registrar mi primer club</a>
        </div>
    <?php else: foreach ($clubs as $i => $c):
        $ins    = (int)$c['inscritos'];
        $lim    = (int)$c['limite'];
        $pct    = $lim > 0 ? round($ins/$lim*100) : 0;
        $estado = clubEstado($c['estado'] ?? 'borrador');
        $nombre_slug = mb_strtolower(preg_replace('/[^a-z0-9 ]/i','', $c['nombre']));
        $bar    = $bar_colors[$i % count($bar_colors)];
        $cupo_class = $pct >= 100 ? 'lleno' : ($pct >= 70 ? 'medio' : 'libre');
        $club_hs = $horarios_map[$c['id']] ?? [];
        $estado_labels = [
            'borrador'  => 'Borrador',
            'apertura'  => 'Apertura',
            'iniciado'  => 'Iniciado',
            'finalizado'=> 'Finalizado',
            'cancelado' => 'Cancelado',
        ];
    ?>
        <!-- CLUB <?= $c['id'] ?> -->
        <div class="club-card" data-estado="<?= $estado ?>" data-nombre="<?= htmlspecialchars($nombre_slug) ?>">
            <div class="club-card-bar <?= $bar ?>"></div>
            <div class="club-card-body">

                <!-- ENCABEZADO: ID + Badge + Autorización -->
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem;flex-wrap:wrap">
                    <span class="cc-id"># <?= str_pad($c['id'], 3, '0', STR_PAD_LEFT) ?></span>
                    <span class="status-badge status-<?= $estado ?>">
                        <span class="dot"></span>
                        <?= htmlspecialchars($estado_labels[$estado] ?? ucfirst($estado)) ?>
                    </span>
                    <?php if ($c['es_auxiliar']): ?>
                    <span style="background:#f0f5ff;border:1px solid #c8deff;border-radius:20px;padding:.18rem .65rem;font-size:.7rem;font-weight:600;color:#2a4a80;display:inline-flex;align-items:center;gap:.3rem">
                        <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Auxiliar
                    </span>
                    <?php endif; ?>
                    <?php if ($estado === 'borrador'): ?>
                    <span style="margin-left:auto;font-size:.7rem;font-weight:600;padding:.18rem .6rem;border-radius:20px;
                        <?= ($c['autorizado']??'no')==='si'
                            ? 'background:#edfaf4;color:#1d6344;border:1px solid #a5dfca'
                            : 'background:#fff8ee;color:#7a4f10;border:1px solid #f5d8a0' ?>">
                        <?= ($c['autorizado']??'no')==='si' ? '✓ Autorizado' : '⏳ Pendiente autorización' ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- NOMBRE + DESCRIPCIÓN -->
                <div class="cc-nombre"><?= htmlspecialchars($c['nombre']) ?></div>
                <div class="cc-desc"><?= htmlspecialchars($c['descripcion']) ?></div>

                <!-- HORARIOS -->
                <?php if ($club_hs): ?>
                <div class="horarios-chips" style="margin-bottom:.7rem">
                    <?php foreach ($club_hs as $h): ?>
                    <span class="horario-chip">
                        <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?= htmlspecialchars($h['dia']) ?> <?= $h['ini'] ?>–<?= $h['fin'] ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- FECHAS + SEMESTRE en una sola fila -->
                <div style="display:flex;align-items:center;gap:1rem;font-size:.78rem;color:var(--gray-500);margin-bottom:.85rem;flex-wrap:wrap">
                    <span style="display:flex;align-items:center;gap:.3rem">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <?= date('d M Y', strtotime($c['fecha_inicio'])) ?> — <?= date('d M Y', strtotime($c['fecha_fin'])) ?>
                    </span>
                    <span style="display:flex;align-items:center;gap:.3rem">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/></svg>
                        Sem. <?= ucfirst($c['semestre']) ?>
                    </span>
                    <?php if (!empty($c['fecha_limite_registro'])): ?>
                    <span style="display:flex;align-items:center;gap:.3rem;color:var(--warning)">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Límite: <?= date('d M Y', strtotime($c['fecha_limite_registro'])) ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- CUPO -->
                <div class="cupo-section">
                    <div class="cupo-label">
                        <span style="font-size:.75rem;color:var(--gray-500)">Cupo</span>
                        <strong class="<?= $cupo_class ?>" style="font-family:'Outfit',sans-serif;font-size:.82rem"><?= $ins ?> / <?= $lim ?> inscritos</strong>
                    </div>
                    <div class="cupo-bar"><div class="cupo-fill <?= $cupo_class ?>" style="width:<?= $pct ?>%"></div></div>
                </div>

            </div><!-- /club-card-body -->

            <!-- FOOTER: Navegación + Cambio de estado -->
            <div class="club-card-footer" style="padding:.85rem 1.4rem;border-top:1px solid var(--gray-100);display:flex;flex-direction:column;gap:.6rem">

                <?php if ($c['es_auxiliar']): ?>
                <!-- Auxiliar: solo puede ver asistencias y pasar lista QR -->
                <div style="display:flex;gap:.5rem">
                    <a href="asistencias.php?club=<?= $c['id'] ?>" class="btn-card btn-gray-c" style="flex:1">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>
                        Ver asistencias
                    </a>
                    <?php if ($estado === 'iniciado'): ?>
                    <button class="btn-card btn-primary-c" style="flex:1.5"
                        onclick="abrirQR('<?= htmlspecialchars(addslashes($c['nombre'])) ?>', <?= $c['id'] ?>)">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        Asistencia QR
                    </button>
                    <?php endif; ?>
                </div>

                <?php else: ?>
                <!-- Fila 1: botones de navegación (encargado principal) -->
                <div style="display:flex;gap:.5rem">
                    <a href="editar_club.php?id=<?= $c['id'] ?>" class="btn-card btn-gray-c" style="flex:1">
                        <?php if ($estado === 'borrador' && ($c['autorizado']??'no') === 'no'): ?>
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Editar
                        <?php else: ?>
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        Ver detalles
                        <?php endif; ?>
                    </a>
                    <?php if ($estado !== 'borrador' && $estado !== 'apertura'): ?>
                    <a href="asistencias.php?club=<?= $c['id'] ?>" class="btn-card btn-gray-c" style="flex:1">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>
                        Asistencias
                    </a>
                    <?php endif; ?>
                    <?php if ($estado === 'iniciado'): ?>
                    <button class="btn-card btn-primary-c" style="flex:1.5"
                        onclick="abrirQR('<?= htmlspecialchars(addslashes($c['nombre'])) ?>', <?= $c['id'] ?>)">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        Asistencia QR
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Fila 2: cambio de estado (solo si hay transiciones disponibles) -->
                <?php
                $siguiente_mc = [
                    'borrador'  => ($c['autorizado']??'no')==='si'
                                  ? [['v'=>'apertura',  'l'=>'Publicar',  'c'=>'#4a7fd4','icon'=>'📬']]
                                  : [],
                    'apertura'  => [['v'=>'iniciado',   'l'=>'Iniciar',   'c'=>'#2e9e6e','icon'=>'▶️'],
                                    ['v'=>'cancelado',  'l'=>'Cancelar',  'c'=>'#d94f4f','icon'=>'✕']],
                    'iniciado'  => [['v'=>'finalizado', 'l'=>'Finalizar', 'c'=>'#7a8099','icon'=>'🏁']],
                    'finalizado'=> [],
                    'cancelado' => [['v'=>'borrador',   'l'=>'Restaurar', 'c'=>'#5a4fcf','icon'=>'↩']],
                ];
                if (!empty($siguiente_mc[$estado])): ?>
                <form method="POST" action="" style="display:flex;gap:.4rem">
                    <input type="hidden" name="cambiar_estado_club" value="1">
                    <input type="hidden" name="club_id" value="<?= $c['id'] ?>">
                    <?php foreach ($siguiente_mc[$estado] as $t): ?>
                    <button type="submit" name="nuevo_estado" value="<?= $t['v'] ?>"
                        style="flex:1;height:34px;padding:0 .75rem;
                               border:1.5px solid <?= $t['c'] ?>22;
                               border-radius:6px;background:<?= $t['c'] ?>11;color:<?= $t['c'] ?>;
                               font-family:'Outfit',sans-serif;font-size:.78rem;font-weight:700;
                               cursor:pointer;transition:all .2s;display:flex;align-items:center;
                               justify-content:center;gap:.3rem;white-space:nowrap"
                        onmouseover="this.style.background='<?= $t['c'] ?>';this.style.color='#fff';this.style.borderColor='<?= $t['c'] ?>'"
                        onmouseout="this.style.background='<?= $t['c'] ?>11';this.style.color='<?= $t['c'] ?>';this.style.borderColor='<?= $t['c'] ?>22'"
                        onclick="return confirm('¿Cambiar estado a «<?= htmlspecialchars($t['l']) ?>»?')">
                        <?= $t['icon'] ?> <?= $t['l'] ?>
                    </button>
                    <?php endforeach; ?>
                </form>
                <?php endif; ?>
                <?php endif; /* fin if es_auxiliar */ ?>

            </div><!-- /club-card-footer -->
        </div>
    <?php endforeach; endif; ?>

    </div><!-- /clubs-grid -->
</div>

<!-- MODAL ELIMINAR -->
<div class="modal-ov" id="modal-eliminar">
    <div class="modal">
        <div class="modal-top">
            <h3>Eliminar club</h3>
            <p>Esta acci&oacute;n no se puede deshacer</p>
        </div>
        <div class="modal-bd">
            <div class="modal-club-name" id="modal-club-name">&mdash;</div>
            <div class="modal-warning">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Se eliminar&aacute;n tambi&eacute;n todos los horarios, inscripciones y asistencias vinculadas a este club.
            </div>
            <div class="modal-btns">
                <button class="btn-cancelar" onclick="cerrarModal()">Cancelar</button>
                <button class="btn-eliminar">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    Eliminar
                </button>
            </div>
        </div>
    </div>
</div>

<footer>&copy; <?= date('Y') ?> Universidad de Colima &mdash; Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>

<script>
function filtrar(btn, estado) {
    document.querySelectorAll(".filter-chip").forEach(c => c.classList.remove("active"));
    btn.classList.add("active");
    document.querySelectorAll(".club-card").forEach(card => {
        card.style.display = (estado === "todos" || card.dataset.estado === estado) ? "" : "none";
    });
}
function buscar(q) {
    const t = q.toLowerCase().trim();
    document.querySelectorAll(".club-card").forEach(card => {
        card.style.display = card.dataset.nombre.includes(t) ? "" : "none";
    });
}

// Modal eliminar
function abrirModal(nombre) {
    document.getElementById("modal-club-name").textContent = nombre;
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

// Modal QR
let qrBuffer = '';
let qrProcesando = false;
let qrAbierto = false;
let qrClubActual = null;
let qrBufferTimer = null;
let qrScanner = null;
let qrCamaraActiva = false;
let logItemsMC = [];

function renderLogMC() {
    const container = document.getElementById('qr-log-items');
    if (!logItemsMC.length) {
        container.innerHTML = '<p style="font-size:.78rem;color:var(--gray-300)">Sin registros aún...</p>';
        return;
    }
    container.innerHTML = logItemsMC.map(item =>
        `<div class="qr-log-item">
            <span style="font-weight:600;color:var(--text)">${item.nombre}</span>
            <div style="display:flex;align-items:center;gap:.4rem">
                <span class="qr-log-badge ${item.tipo.toLowerCase()}">${item.tipo}</span>
                <span style="color:var(--gray-500);font-size:.72rem">${item.hora}</span>
            </div>
        </div>`
    ).join('');
}

function agregarLogMC(nombre, tipo, hora) {
    logItemsMC.unshift({ nombre, tipo, hora });
    renderLogMC();
}

function abrirQR(nombreClub, clubId) {
    qrClubActual = clubId;
    const ahora = new Date();
    const dias  = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    const meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    const fechaStr = dias[ahora.getDay()] + ' ' + ahora.getDate() + ' ' + meses[ahora.getMonth()] + ' ' + ahora.getFullYear();
    document.getElementById("modal-qr-club").textContent = fechaStr + '  ·  ' + nombreClub;

    document.getElementById("modal-qr").classList.add("open");
    document.body.style.overflow = "hidden";
    qrBuffer = ''; qrProcesando = false; qrAbierto = true;
    logItemsMC = [];
    renderLogMC();
    resetarQR();
    // Esperamos a que el modal termine su animación CSS antes de activar la cámara
    setTimeout(() => {
        iniciarCamaraMC();
        document.getElementById("qr-capture").focus();
    }, 400);
}

function cerrarQR() {
    document.getElementById("modal-qr").classList.remove("open");
    document.body.style.overflow = "";
    qrAbierto = false; qrBuffer = ''; qrProcesando = false;
    clearTimeout(qrBufferTimer);
    if (qrScanner && qrCamaraActiva) {
        qrScanner.stop().catch(() => {});
        qrCamaraActiva = false;
    }
}

// ── Cámara html5-qrcode ──────────────────────────────────────────
function iniciarCamaraMC() {
    if (typeof Html5Qrcode === 'undefined') return;
    if (qrCamaraActiva) return;

    // Verificar que el elemento existe y tiene dimensiones
    const el = document.getElementById('qr-reader-mc');
    if (!el || el.offsetWidth === 0) {
        setTimeout(iniciarCamaraMC, 200); // reintentar si aún no tiene tamaño
        return;
    }

    // Limpiar el div antes de crear nuevo scanner (evita "already running" errors)
    el.innerHTML = '';
    qrScanner = new Html5Qrcode('qr-reader-mc');

    qrScanner.start(
        { facingMode: 'environment' },
        { fps: 15, qrbox: { width: 200, height: 200 }, aspectRatio: 1.0 },
        (decoded) => {
            if (qrProcesando) return;
            qrScanner.stop().then(() => {
                qrCamaraActiva = false;
                procesarEscaneo(decoded.trim());
                setTimeout(() => { if (qrAbierto) iniciarCamaraMC(); }, 2500);
            }).catch(() => {
                qrCamaraActiva = false;
                procesarEscaneo(decoded.trim());
            });
        }
    ).then(() => {
        qrCamaraActiva = true;
    }).catch((err) => {
        console.warn('Cámara no disponible:', err);
        // Mostrar mensaje en el visor si no hay cámara
        el.innerHTML = '<p style="color:rgba(255,255,255,.5);font-size:.8rem;text-align:center;padding:3rem 1rem">⚠️ Cámara no disponible.<br>Usa el lector USB.</p>';
    });
}

function resetarQR() {
    const res = document.getElementById("qr-resultado");
    if (res) res.style.display = "none";
}

function procesarEscaneo(cuenta) {
    if (qrProcesando) return;
    qrProcesando = true;

    const res = document.getElementById("qr-resultado");
    res.style.display = "block";
    res.style.background = "#eef0f6";
    res.style.color = "var(--gray-700)";
    res.style.border = "none";
    res.textContent = "🔍 Buscando: " + cuenta + "...";

    const fd = new FormData();
    fd.append('codigo',  cuenta);
    fd.append('id_club', qrClubActual);

    fetch('procesar_asistencia.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const esOk = data.status === 'registrado';
            res.style.background = esOk
                ? (data.tipo === 'ENTRADA' ? '#edfaf4' : '#e8f4ff')
                : '#fff5f5';
            res.style.color = esOk
                ? (data.tipo === 'ENTRADA' ? '#1a5e3f' : '#2a5ea8')
                : '#8b2020';
            res.style.border = '1px solid ' + (esOk
                ? (data.tipo === 'ENTRADA' ? '#a5dfca' : '#bdd8ff')
                : '#fbd5d5');
            res.textContent = data.message;

            if (esOk) {
                const ahora = new Date();
                const hora = String(ahora.getHours()).padStart(2,'0') + ':' + String(ahora.getMinutes()).padStart(2,'0');
                agregarLogMC(data.nombre || cuenta, data.tipo, hora);
            }

            setTimeout(() => {
                if (!qrAbierto) return;
                qrProcesando = false;
                qrBuffer = '';
                resetarQR();
                document.getElementById("qr-capture").focus();
                iniciarCamaraMC();
            }, 2500);
        })
        .catch(() => {
            res.style.background = '#fff5f5';
            res.style.color = '#8b2020';
            res.style.border = '1px solid #fbd5d5';
            res.textContent = '⚠️ Error de conexión. Intenta de nuevo.';
            setTimeout(() => {
                qrProcesando = false; qrBuffer = '';
                resetarQR(); iniciarCamaraMC();
            }, 2000);
        });
}

// Captura teclado del escáner USB (el escáner escribe el código y envía Enter)
document.getElementById("qr-capture").addEventListener("keydown", function(e) {
    if (!qrAbierto || qrProcesando) return;

    if (e.key === "Enter") {
        clearTimeout(qrBufferTimer);
        if (qrBuffer.trim().length > 0) procesarEscaneo(qrBuffer.trim());
        qrBuffer = '';
        e.preventDefault();
        return;
    }

    if (/^[\w\d]$/.test(e.key)) {
        qrBuffer += e.key;
        // Auto-procesar si el escáner no envía Enter (timeout de inactividad)
        clearTimeout(qrBufferTimer);
        qrBufferTimer = setTimeout(() => {
            if (qrBuffer.trim().length >= 5) procesarEscaneo(qrBuffer.trim());
            qrBuffer = '';
        }, 300);
    }
    e.preventDefault();
});

// Mantener foco en el input de captura mientras el modal está abierto
document.getElementById("modal-qr").addEventListener("click", e => {
    if (e.target === document.getElementById("modal-qr")) { cerrarQR(); return; }
    if (qrAbierto) document.getElementById("qr-capture").focus();
});

document.addEventListener("keydown", e => { if (e.key === "Escape") { cerrarModal(); cerrarQR(); } });
</script>

<!-- MODAL QR -->
<div class="modal-ov" id="modal-qr">
    <div class="modal" style="max-width:460px">
        <div class="modal-top" style="position:relative">
            <div>
                <h3>Registrar asistencia QR</h3>
                <p id="modal-qr-club">—</p>
            </div>
            <!-- Botón cerrar — círculo pill -->
            <button onclick="cerrarQR()" style="
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

            <!-- Visor QR — cámara activa -->
            <div id="qr-viewfinder" style="border-radius:12px;overflow:hidden;border:2px solid var(--accent);background:#000;min-height:260px;position:relative">
                <div id="qr-reader-mc" style="width:100%;height:100%;position:absolute;inset:0;z-index:1;overflow:hidden"></div>
            </div>

            <p style="font-size:.75rem;color:var(--gray-500);text-align:center;margin-top:.65rem;line-height:1.5">
                Apunta la cámara al <strong>QR de la credencial SICEUC</strong> del alumno.<br>
                Se registra automáticamente al leer el código.
            </p>

            <!-- Resultado del escaneo -->
            <div id="qr-resultado" style="display:none;margin-top:.85rem;padding:.8rem 1rem;border-radius:8px;font-size:.85rem;font-weight:600;text-align:center;transition:all .3s"></div>

            <!-- Log de la sesión actual -->
            <div id="qr-log-sesion" style="margin-top:.85rem">
                <p style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-500);margin-bottom:.4rem">Últimos registros</p>
                <div id="qr-log-items" style="display:flex;flex-direction:column;gap:.3rem">
                    <p style="font-size:.78rem;color:var(--gray-300)">Sin registros aún...</p>
                </div>
            </div>

            <!-- Input oculto para lector USB -->
            <input type="text" id="qr-capture" class="qr-capture-input" autocomplete="off" inputmode="numeric" tabindex="-1">

            <div style="margin-top:1.1rem;display:flex;justify-content:center">
                <button onclick="cerrarQR()" style="
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
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
</body>
</html>