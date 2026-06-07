<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sincronizar_estados.php';

// ── Proteger ruta ────────────────────────────────────────────────
if (empty($_SESSION['encargado_id'])) {
    header('Location: login_encargado.php');
    exit;
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login_encargado.php');
    exit;
}

sincronizarEstadosClubes();

// ── Datos del encargado ──────────────────────────────────────────
$enc = [
    'id'      => $_SESSION['encargado_id'],
    'nombre'  => $_SESSION['encargado_nombre']    ?? 'Encargado',
    'tipo'    => $_SESSION['encargado_tipo']      ?? '',
    'plantel' => $_SESSION['encargado_plantel']   ?? '',
    'correo'  => $_SESSION['encargado_correo']    ?? '',
];
$partes   = explode(' ', $enc['nombre']);
$iniciales = '';
foreach (array_slice($partes, 0, 2) as $p) $iniciales .= mb_substr($p, 0, 1);
$hora    = (int)date('H');
$saludo  = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$hoy_str = strftime('%A, %d de %B de %Y') ?: date('d/m/Y');
$today   = date('Y-m-d');
$hoy_dia = ['1'=>'Lunes','2'=>'Martes','3'=>'Miércoles','4'=>'Jueves','5'=>'Viernes','6'=>'Sábado','7'=>'Domingo'][date('N')] ?? '';

// ── Consultas a BD ───────────────────────────────────────────────
$clubs   = [];
$sesiones = [];
$stats   = ['borrador'=>0,'autorizado'=>0,'apertura'=>0,'iniciado'=>0,'finalizado'=>0,'cancelado'=>0];

try {
    $pdo = getDB();

    // Clubs donde el encargado es principal O auxiliar
    $stmt = $pdo->prepare("
        SELECT c.id, c.nombre, c.semestre, c.limite, c.fecha_inicio, c.fecha_fin,
               c.estado, c.autorizado,
               COUNT(DISTINCT e.id) AS inscritos,
               GROUP_CONCAT(DISTINCT h.dia ORDER BY FIELD(h.dia,'Lunes','Martes','Miércoles','Jueves','Viernes','Sábado') SEPARATOR ' / ') AS dias,
               TIME_FORMAT(MIN(h.hora_inicio),'%H:%i') AS hora_ini,
               TIME_FORMAT(MAX(h.hora_fin),'%H:%i')    AS hora_fin,
               CASE WHEN c.id_encargado = :id2 THEN 0 ELSE 1 END AS es_auxiliar
        FROM clubes c
        LEFT JOIN estudiantes e ON e.id_club = c.id
        LEFT JOIN horarios h    ON h.id_club = c.id
        LEFT JOIN auxiliares_club ac ON ac.id_club = c.id AND ac.id_persona = :id3
        WHERE (c.id_encargado = :id OR ac.id_persona IS NOT NULL)
        GROUP BY c.id
        ORDER BY c.nombre
    ");
    $stmt->execute([':id' => (int)$enc['id'], ':id2' => (int)$enc['id'], ':id3' => (int)$enc['id']]);
    $clubs = $stmt->fetchAll();

    foreach ($clubs as $c) {
        $est = $c['estado'] ?? 'borrador';
        if ($est === 'borrador' && ($c['autorizado'] ?? 'no') === 'si') {
            $stats['autorizado']++;
        } elseif (isset($stats[$est])) {
            $stats[$est]++;
        }
    }

    // Próximas sesiones (clubs donde es principal o auxiliar)
    $stmt2 = $pdo->prepare("
        SELECT h.dia, TIME_FORMAT(h.hora_inicio,'%H:%i') AS ini, TIME_FORMAT(h.hora_fin,'%H:%i') AS fin,
               c.id AS club_id, c.nombre AS club_nombre
        FROM horarios h
        JOIN clubes c ON c.id = h.id_club
        LEFT JOIN auxiliares_club ac ON ac.id_club = c.id AND ac.id_persona = :id2
        WHERE (c.id_encargado = :id OR ac.id_persona IS NOT NULL)
          AND c.estado IN ('apertura','iniciado') AND c.fecha_fin >= CURDATE()
        ORDER BY FIELD(h.dia,'Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'), h.hora_inicio
        LIMIT 5
    ");
    $stmt2->execute([':id' => (int)$enc['id'], ':id2' => (int)$enc['id']]);
    $sesiones = $stmt2->fetchAll();

} catch (Exception $e) {
    error_log('dashboard_encargado BD: ' . $e->getMessage());
}

$bar_colors = ['cb-blue','cb-green','cb-orange','cb-navy','cb-accent'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard &mdash; Clubes B23</title>
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

        /* HERO SUBHEADER */
        .hero {
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
            padding: 2rem 2rem 3.5rem;
            position: relative; overflow: hidden;
        }
        .hero::before {
            content:""; position:absolute; right:-60px; top:-60px;
            width:300px; height:300px; border-radius:50%;
            background:rgba(255,255,255,.04);
        }
        .hero::after {
            content:""; position:absolute; right:80px; bottom:-80px;
            width:240px; height:240px; border-radius:50%;
            background:rgba(255,255,255,.03);
        }
        .hero-inner { max-width:1060px; margin:0 auto; position:relative; z-index:1; display:flex; align-items:center; justify-content:space-between; gap:1.5rem; flex-wrap:wrap; }
        .hero-left { display:flex; align-items:center; gap:1.1rem; }
        .hero-avatar {
            width:58px; height:58px; border-radius:50%;
            background: var(--accent);
            border:3px solid rgba(255,255,255,.3);
            display:flex; align-items:center; justify-content:center;
            font-family:"Outfit",sans-serif; font-weight:700; font-size:1.3rem; color:#fff;
            flex-shrink:0;
        }
        .hero-greeting { font-size:.82rem; color:rgba(255,255,255,.6); margin-bottom:.25rem; }
        .hero-name { font-family:"Outfit",sans-serif; font-size:1.3rem; font-weight:700; color:#fff; line-height:1.2; }
        .hero-role { display:inline-flex; align-items:center; gap:.35rem; margin-top:.4rem; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); border-radius:20px; padding:.2rem .75rem; font-size:.72rem; color:rgba(255,255,255,.85); font-family:"Outfit",sans-serif; font-weight:500; }
        .hero-right { text-align:right; }
        .hero-date { font-size:.8rem; color:rgba(255,255,255,.55); }
        .hero-ciclo { font-family:"Outfit",sans-serif; font-size:.9rem; font-weight:600; color:rgba(255,255,255,.85); margin-top:.2rem; }

        /* PULL-UP CARDS */
        .page { max-width:1060px; margin:-2.2rem auto 0; padding: 0 1.5rem 4rem; position:relative; z-index:2; }

        /* STAT CARDS */
        .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.75rem; }
        .stat-card {
            background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg);
            padding:1.25rem 1.4rem; display:flex; flex-direction:column; gap:.6rem;
            animation:fadeUp .3s ease both; transition: box-shadow .2s, transform .2s;
        }
        .stat-card:hover { box-shadow:var(--shadow-lg); transform:translateY(-2px); }
        .stat-card:nth-child(2){animation-delay:.06s} .stat-card:nth-child(3){animation-delay:.12s} .stat-card:nth-child(4){animation-delay:.18s}
        .stat-top { display:flex; align-items:center; justify-content:space-between; }
        .stat-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .si-blue  { background:#e8f0fd; color:var(--accent); }
        .si-green { background:#e8f7f0; color:var(--success); }
        .si-orange{ background:#fff3e0; color:var(--warning); }
        .si-navy  { background:#eef0f6; color:var(--navy); }
        .stat-trend { font-size:.72rem; font-family:"Outfit",sans-serif; font-weight:600; padding:.15rem .5rem; border-radius:20px; }
        .trend-up   { background:#e8f7f0; color:var(--success); }
        .trend-warn { background:#fff3e0; color:var(--warning); }
        .trend-neu  { background:var(--gray-100); color:var(--gray-500); }
        .stat-num { font-family:"Outfit",sans-serif; font-size:1.8rem; font-weight:700; color:var(--text); line-height:1; }
        .stat-lbl { font-size:.76rem; color:var(--gray-500); }
        .stat-bar { height:3px; background:var(--gray-100); border-radius:3px; overflow:hidden; margin-top:.2rem; }
        .stat-fill { height:100%; border-radius:3px; }
        .fill-blue  {background:var(--accent)} .fill-green{background:var(--success)} .fill-orange{background:var(--warning)} .fill-navy{background:var(--navy)}

        /* LAYOUT */
        .layout { display:grid; grid-template-columns:1fr 320px; gap:1.5rem; align-items:start; }

        /* SECTION LABEL */
        .sec-lbl { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:var(--gray-500); margin-bottom:.85rem; display:flex; align-items:center; gap:.4rem; }
        .sec-lbl::after { content:""; flex:1; height:1px; background:var(--gray-200); }

        /* ACCIONES RAPIDAS */
        .quick-actions { display:grid; grid-template-columns:1fr 1fr; gap:.85rem; margin-bottom:1.75rem; }
        .qa-card {
            background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow);
            padding:1.25rem 1.4rem; text-decoration:none; display:flex; align-items:center; gap:1rem;
            transition: box-shadow .2s, transform .2s; border:1.5px solid transparent;
            animation:fadeUp .35s ease both;
        }
        .qa-card:nth-child(2){animation-delay:.08s} .qa-card:nth-child(3){animation-delay:.16s} .qa-card:nth-child(4){animation-delay:.24s}
        .qa-card:hover { box-shadow:var(--shadow-lg); transform:translateY(-2px); border-color:var(--gray-200); }
        .qa-icon { width:44px; height:44px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .qa-blue   { background:#e8f0fd; color:var(--accent); }
        .qa-green  { background:#e8f7f0; color:var(--success); }
        .qa-orange { background:#fff3e0; color:var(--warning); }
        .qa-navy   { background:#eef0f6; color:var(--navy); }
        .qa-title { font-family:"Outfit",sans-serif; font-size:.88rem; font-weight:700; color:var(--text); }
        .qa-desc  { font-size:.74rem; color:var(--gray-500); margin-top:.15rem; }
        .qa-arrow { margin-left:auto; color:var(--gray-300); flex-shrink:0; transition:color .2s, transform .2s; }
        .qa-card:hover .qa-arrow { color:var(--accent); transform:translateX(3px); }

        /* MIS CLUBS MINI */
        .clubs-list { display:flex; flex-direction:column; gap:.75rem; }
        .club-mini {
            background:var(--white); border-radius:var(--radius-sm); box-shadow:var(--shadow);
            overflow:hidden; display:flex; align-items:stretch;
            transition: box-shadow .2s, transform .2s; text-decoration:none;
            animation:fadeUp .4s ease both;
        }
        .club-mini:nth-child(2){animation-delay:.1s} .club-mini:nth-child(3){animation-delay:.2s} .club-mini:nth-child(4){animation-delay:.3s}
        .club-mini:hover { box-shadow:var(--shadow-lg); transform:translateY(-1px); }
        .club-mini-bar { width:4px; flex-shrink:0; }
        .cb-blue  {background:var(--accent)} .cb-green{background:var(--success)} .cb-orange{background:var(--warning)} .cb-navy{background:var(--navy)}
        .club-mini-body { padding:.9rem 1.1rem; flex:1; display:flex; align-items:center; gap:1rem; }
        .club-mini-info { flex:1; }
        .club-mini-nombre { font-family:"Outfit",sans-serif; font-size:.88rem; font-weight:700; color:var(--text); }
        .club-mini-meta { display:flex; align-items:center; gap:.6rem; margin-top:.25rem; flex-wrap:wrap; }
        .club-mini-chip { font-size:.7rem; color:var(--gray-500); display:inline-flex; align-items:center; gap:.25rem; }
        .club-mini-chip svg { color:var(--gray-300); }
        .status-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
        .sd-green{background:var(--success)} .sd-orange{background:var(--warning)} .sd-gray{background:var(--gray-300)}
        .cupo-mini { text-align:right; flex-shrink:0; }
        .cupo-mini-num { font-family:"Outfit",sans-serif; font-size:.85rem; font-weight:700; }
        .cupo-mini-lbl { font-size:.68rem; color:var(--gray-500); }
        .cupo-mini-bar { width:60px; height:4px; background:var(--gray-100); border-radius:4px; overflow:hidden; margin-top:.3rem; }
        .cupo-mini-fill { height:100%; border-radius:4px; }

        .btn-ver-todos { display:flex; align-items:center; justify-content:center; gap:.4rem; height:38px; margin-top:.85rem; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); background:var(--white); color:var(--gray-700); font-family:"Outfit",sans-serif; font-size:.8rem; font-weight:600; cursor:pointer; text-decoration:none; transition:all .2s; }
        .btn-ver-todos:hover { background:var(--gray-50); border-color:var(--accent); color:var(--accent); }

        /* SIDEBAR */
        .sidebar { display:flex; flex-direction:column; gap:1.25rem; }
        .side-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; animation:fadeUp .4s ease both; }
        .side-card:nth-child(2){animation-delay:.1s} .side-card:nth-child(3){animation-delay:.2s}
        .side-card-top { padding:.9rem 1.25rem; border-bottom:1px solid var(--gray-100); display:flex; align-items:center; justify-content:space-between; }
        .side-card-top h4 { font-family:"Outfit",sans-serif; font-size:.88rem; font-weight:700; display:flex; align-items:center; gap:.4rem; }
        .side-card-top a { font-size:.74rem; color:var(--accent); text-decoration:none; } .side-card-top a:hover{text-decoration:underline}
        .side-card-body { padding:1rem 1.25rem; }

        /* PROXIMAS SESIONES */
        .sesion-item { display:flex; align-items:center; gap:.85rem; padding:.6rem 0; border-bottom:1px solid var(--gray-100); }
        .sesion-item:last-child { border-bottom:none; }
        .sesion-dia-box {
            width:42px; flex-shrink:0; text-align:center;
            background:var(--gray-50); border:1px solid var(--gray-200);
            border-radius:var(--radius-sm); padding:.3rem .2rem;
        }
        .sesion-dia-num  { font-family:"Outfit",sans-serif; font-size:1.1rem; font-weight:700; color:var(--navy); line-height:1; }
        .sesion-dia-name { font-size:.6rem; text-transform:uppercase; color:var(--gray-500); letter-spacing:.5px; }
        .sesion-info { flex:1; }
        .sesion-club { font-family:"Outfit",sans-serif; font-size:.82rem; font-weight:600; color:var(--text); }
        .sesion-hora { font-size:.73rem; color:var(--gray-500); margin-top:.15rem; display:flex; align-items:center; gap:.3rem; }
        .sesion-hora svg { color:var(--gray-300); }
        .sesion-btn { width:30px; height:30px; border-radius:var(--radius-sm); background:var(--gray-50); border:1px solid var(--gray-200); cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--gray-500); flex-shrink:0; transition:all .2s; text-decoration:none; }
        .sesion-btn:hover { background:#e8f0fd; border-color:var(--accent); color:var(--accent); }

        /* HOY resaltado */
        .sesion-item.hoy .sesion-dia-box { background:#e8f0fd; border-color:var(--accent); }
        .sesion-item.hoy .sesion-dia-num  { color:var(--accent); }
        .sesion-item.hoy .sesion-dia-name { color:var(--accent); }
        .sesion-item.hoy .sesion-btn { background:var(--accent); border-color:var(--accent); color:#fff; }
        .sesion-item.hoy .sesion-btn:hover { background:var(--accent-h); }

        /* ACTIVIDAD RECIENTE */
        .act-item { display:flex; align-items:flex-start; gap:.75rem; padding:.6rem 0; border-bottom:1px solid var(--gray-100); }
        .act-item:last-child { border-bottom:none; }
        .act-icon { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:1px; }
        .ai-green { background:#e8f7f0; color:var(--success); }
        .ai-blue  { background:#e8f0fd; color:var(--accent); }
        .ai-orange{ background:#fff3e0; color:var(--warning); }
        .act-text { flex:1; }
        .act-desc { font-size:.8rem; color:var(--text); line-height:1.4; }
        .act-desc strong { font-weight:600; }
        .act-time { font-size:.7rem; color:var(--gray-400); margin-top:.2rem; }

        /* PERFIL CARD */
        .profile-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; margin-bottom:1.75rem; animation:fadeUp .25s ease both; }
        .profile-top { background:linear-gradient(135deg,var(--navy),var(--navy-light)); padding:1.25rem 1.5rem; display:flex; align-items:center; gap:1rem; position:relative; overflow:hidden; }
        .profile-top::after { content:""; position:absolute; right:-20px; bottom:-40px; width:130px; height:130px; border-radius:50%; background:rgba(255,255,255,.05); }
        .profile-av { width:48px; height:48px; border-radius:50%; background:var(--accent); border:2.5px solid rgba(255,255,255,.3); display:flex; align-items:center; justify-content:center; font-family:"Outfit",sans-serif; font-weight:700; font-size:1rem; color:#fff; flex-shrink:0; }
        .profile-name { font-family:"Outfit",sans-serif; font-size:.95rem; font-weight:700; color:#fff; position:relative; z-index:1; }
        .profile-sub  { font-size:.73rem; color:rgba(255,255,255,.6); margin-top:.15rem; position:relative; z-index:1; }
        .profile-body { padding:1rem 1.5rem; display:flex; flex-wrap:wrap; gap:.75rem 1.5rem; }
        .profile-item { display:flex; flex-direction:column; }
        .profile-item-lbl { font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--gray-500); margin-bottom:.2rem; }
        .profile-item-val { font-family:"Outfit",sans-serif; font-size:.85rem; font-weight:600; color:var(--text); }

        @keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        footer { text-align:center; padding:1.5rem; font-size:.72rem; color:var(--gray-500); }

        @media (max-width:860px) {
            .layout { grid-template-columns:1fr; }
            .stats { grid-template-columns:1fr 1fr; }
            .quick-actions { grid-template-columns:1fr 1fr; }
            .page { padding:0 1rem 3rem; }
            header { padding:0 1rem; }
            nav a span { display:none; }
            .hero { padding:1.5rem 1rem 3rem; }
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
        <a href="dashboard_encargado.php" class="active">
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
        <a href="?logout=1" class="nav-out">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Salir
        </a>
    </nav>
</header>

<!-- HERO -->
<div class="hero">
    <div class="hero-inner">
        <div class="hero-left">
            <div class="hero-avatar"><?= htmlspecialchars($iniciales) ?></div>
            <div>
                <div class="hero-greeting"><?= htmlspecialchars($saludo) ?>, de vuelta</div>
                <div class="hero-name"><?= htmlspecialchars($partes[0] ?? '') ?><?php if (!empty($partes[1])): ?><br><?= htmlspecialchars($partes[1]) ?><?php endif; ?></div>
                <div class="hero-role">
                    <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?= htmlspecialchars($enc['tipo']) ?> &nbsp;&middot;&nbsp; <?= htmlspecialchars($enc['plantel']) ?>
                </div>
            </div>
        </div>
        <div class="hero-right">
            <?php $meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']; ?>
            <div class="hero-date"><?= date('d') ?> de <?= $meses_es[(int)date('n')] ?> de <?= date('Y') ?></div>
            <div class="hero-ciclo">Ciclo <?= date('Y') ?></div>
        </div>
    </div>
</div>

<div class="page">

    <!-- STAT CARDS — 6 estados del club -->
    <div class="stats" style="grid-template-columns:repeat(6,1fr);gap:.75rem">
        <?php
        $stat_cfg = [
            ['key'=>'borrador',   'lbl'=>'Borradores',  'color'=>'#5a4fcf','bg'=>'#f0f0ff','icon'=>'✏️'],
            ['key'=>'autorizado', 'lbl'=>'Autorizados', 'color'=>'#4a7fd4','bg'=>'#e8f4ff','icon'=>'✅'],
            ['key'=>'apertura',   'lbl'=>'Publicados',  'color'=>'#2e9e6e','bg'=>'#edfaf4','icon'=>'📬'],
            ['key'=>'iniciado',   'lbl'=>'Iniciados',   'color'=>'#1b2d54','bg'=>'#eef0f6','icon'=>'▶️'],
            ['key'=>'finalizado', 'lbl'=>'Finalizados', 'color'=>'#7a8099','bg'=>'#f7f8fc','icon'=>'🏁'],
            ['key'=>'cancelado',  'lbl'=>'Cancelados',  'color'=>'#d94f4f','bg'=>'#fff0f0','icon'=>'❌'],
        ];
        foreach ($stat_cfg as $sc): ?>
        <div class="stat-card" style="padding:.9rem 1rem">
            <div style="font-size:1.3rem;margin-bottom:.3rem"><?= $sc['icon'] ?></div>
            <div class="stat-num" style="font-size:1.6rem;color:<?= $sc['color'] ?>"><?= $stats[$sc['key']] ?></div>
            <div class="stat-lbl" style="font-size:.7rem"><?= $sc['lbl'] ?></div>
            <div class="stat-bar" style="margin-top:.5rem">
                <div class="stat-fill" style="background:<?= $sc['color'] ?>;width:<?= $stats[$sc['key']] > 0 ? '100' : '0' ?>%;opacity:.4"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="layout">
    <!-- COLUMNA PRINCIPAL -->
    <div>

        <!-- ACCIONES RAPIDAS -->
        <div class="sec-lbl">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            Acciones r&aacute;pidas
        </div>
        <div class="quick-actions">
            <a href="registrar_club.php" class="qa-card">
                <div class="qa-icon qa-blue">
                    <svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <div>
                    <div class="qa-title">Registrar club</div>
                    <div class="qa-desc">Crear nuevo club para este ciclo</div>
                </div>
                <svg class="qa-arrow" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <a href="mis_clubes.php" class="qa-card">
                <div class="qa-icon qa-navy">
                    <svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div>
                    <div class="qa-title">Ver mis clubs</div>
                    <div class="qa-desc">Administrar clubs activos</div>
                </div>
                <svg class="qa-arrow" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <a href="asistencias.php" class="qa-card">
                <div class="qa-icon qa-green">
                    <svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="6" y="6" width="1" height="1" fill="currentColor"/><rect x="17" y="6" width="1" height="1" fill="currentColor"/><rect x="6" y="17" width="1" height="1" fill="currentColor"/></svg>
                </div>
                <div>
                    <div class="qa-title">Pasar lista QR</div>
                    <div class="qa-desc">Registrar asistencia con c&oacute;digo QR</div>
                </div>
                <svg class="qa-arrow" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <a href="asistencias.php" class="qa-card">
                <div class="qa-icon qa-orange">
                    <svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                </div>
                <div>
                    <div class="qa-title">Ver reportes</div>
                    <div class="qa-desc">Estad&iacute;sticas de asistencia</div>
                </div>
                <svg class="qa-arrow" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        </div>

        <!-- MIS CLUBS -->
        <div class="sec-lbl">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Mis clubs este ciclo
        </div>
        <div class="clubs-list">
        <?php if (empty($clubs)): ?>
            <div style="padding:1.5rem;text-align:center;color:var(--gray-500);font-size:.85rem;">
                Aún no tienes clubs registrados.
                <a href="registrar_club.php" style="color:var(--accent);font-weight:600">Registrar mi primer club →</a>
            </div>
        <?php else: foreach ($clubs as $i => $c):
            $ins = (int)$c['inscritos'];
            $lim = (int)$c['limite'];
            $pct = $lim > 0 ? round($ins/$lim*100) : 0;
            $lleno = $ins >= $lim;
            $activo = $c['fecha_inicio'] <= $today && $c['fecha_fin'] >= $today;
            $sd_class = $lleno ? 'sd-orange' : ($activo ? 'sd-green' : 'sd-gray');
            $num_color = $lleno ? 'var(--error)' : ($pct >= 70 ? 'var(--warning)' : 'var(--success)');
            $fill_class = $lleno ? 'fill-blue" style="width:100%;background:var(--error)' : ($pct >= 70 ? 'fill-orange" style="width:'.$pct.'%' : 'fill-green" style="width:'.$pct.'%');
            $bar = $bar_colors[$i % count($bar_colors)];
        ?>
            <a href="asistencias.php?club=<?= $c['id'] ?>" class="club-mini">
                <div class="club-mini-bar <?= $bar ?>"></div>
                <div class="club-mini-body">
                    <div style="display:flex;align-items:center;gap:.5rem;flex-shrink:0">
                        <span class="status-dot <?= $sd_class ?>"></span>
                    </div>
                    <div class="club-mini-info">
                        <div class="club-mini-nombre"><?= htmlspecialchars($c['nombre']) ?></div>
                        <div class="club-mini-meta">
                            <?php if ($c['dias']): ?>
                            <span class="club-mini-chip">
                                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <?= htmlspecialchars($c['dias']) ?> &nbsp;<?= $c['hora_ini'] ?>–<?= $c['hora_fin'] ?>
                            </span>
                            <?php endif; ?>
                            <span class="club-mini-chip">
                                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/></svg>
                                Sem. <?= ucfirst($c['semestre']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="cupo-mini">
                        <div class="cupo-mini-num" style="color:<?= $num_color ?>"><?= $ins ?>/<?= $lim ?></div>
                        <div class="cupo-mini-lbl">inscritos</div>
                        <div class="cupo-mini-bar"><div class="cupo-mini-fill <?= $fill_class ?>"></div></div>
                    </div>
                </div>
            </a>
        <?php endforeach; endif; ?>
        </div>

        <a href="mis_clubes.php" class="btn-ver-todos">
            Ver todos mis clubs
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>

    </div><!-- /col -->

    <!-- SIDEBAR -->
    <div class="sidebar">

        <!-- PERFIL -->
        <div class="profile-card">
            <div class="profile-top">
                <div class="profile-av"><?= htmlspecialchars($iniciales) ?></div>
                <div>
                    <div class="profile-name"><?= htmlspecialchars($enc['nombre']) ?></div>
                    <div class="profile-sub"><?= htmlspecialchars($enc['tipo']) ?> &middot; <?= htmlspecialchars($enc['plantel']) ?></div>
                </div>
            </div>
            <div class="profile-body">
                <div class="profile-item">
                    <span class="profile-item-lbl">No. Trabajador</span>
                    <span class="profile-item-val"><?= htmlspecialchars($enc['id']) ?></span>
                </div>
                <div class="profile-item">
                    <span class="profile-item-lbl">Plantel</span>
                    <span class="profile-item-val"><?= htmlspecialchars($enc['plantel']) ?></span>
                </div>
                <div class="profile-item">
                    <span class="profile-item-lbl">Tipo</span>
                    <span class="profile-item-val"><?= htmlspecialchars($enc['tipo']) ?></span>
                </div>
                <?php if ($enc['correo']): ?>
                <div class="profile-item">
                    <span class="profile-item-lbl">Correo</span>
                    <span class="profile-item-val" style="font-size:.78rem"><?= htmlspecialchars($enc['correo']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- PROXIMAS SESIONES -->
        <div class="side-card">
            <div class="side-card-top">
                <h4>
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Horarios semanales
                </h4>
            </div>
            <div class="side-card-body">
            <?php if (empty($sesiones)): ?>
                <p style="font-size:.82rem;color:var(--gray-500);padding:.5rem 0">Sin horarios registrados.</p>
            <?php else: foreach ($sesiones as $s):
                $esHoy = ($s['dia'] === $hoy_dia);
                $diaCorto = mb_substr($s['dia'], 0, 3);
            ?>
                <div class="sesion-item <?= $esHoy ? 'hoy' : '' ?>">
                    <div class="sesion-dia-box">
                        <div class="sesion-dia-num"><?= $diaCorto ?></div>
                        <div class="sesion-dia-name"><?= $esHoy ? 'Hoy' : $diaCorto ?></div>
                    </div>
                    <div class="sesion-info">
                        <div class="sesion-club"><?= htmlspecialchars($s['club_nombre']) ?></div>
                        <div class="sesion-hora">
                            <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <?= $s['ini'] ?> &ndash; <?= $s['fin'] ?> &middot; <?= htmlspecialchars($s['dia']) ?>
                        </div>
                    </div>
                    <a href="asistencias.php?club=<?= $s['club_id'] ?>" class="sesion-btn" title="Ver asistencias">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                </div>
            <?php endforeach; endif; ?>
            </div>
        </div>

    </div><!-- /sidebar -->
    </div><!-- /layout -->

</div><!-- /page -->

<footer>&copy; <?= date('Y') ?> Universidad de Colima &mdash; Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>

</body>
</html>