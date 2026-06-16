<?php
session_start();
require_once __DIR__ . '/db.php';
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
];

// Iniciales del nombre para el avatar
$partes   = explode(' ', $alumno['nombre']);
$iniciales = '';
foreach (array_slice($partes, 0, 2) as $p) {
    $iniciales .= mb_substr($p, 0, 1);
}

// ── Cargar todos los clubs en los que el alumno ha estado inscrito ──
$mis_clubs = [];
$dias_map  = ['Domingo'=>0,'Lunes'=>1,'Martes'=>2,'Miércoles'=>3,
               'Jueves'=>4,'Viernes'=>5,'Sábado'=>6];
$meses_es  = ['','Ene','Feb','Mar','Abr','May','Jun',
               'Jul','Ago','Sep','Oct','Nov','Dic'];

$estado_info = [
    'apertura'   => ['label' => 'En apertura', 'cls' => 'apertura'],
    'iniciado'   => ['label' => 'En curso',     'cls' => 'iniciado'],
    'finalizado' => ['label' => 'Finalizado',   'cls' => 'finalizado'],
    'cancelado'  => ['label' => 'Cancelado',    'cls' => 'cancelado'],
];

try {
    $pdo = getDB();

    // Clubs inscritos vía inscripciones_club (historial completo, todos los estados)
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de clubs — Bachillerato 23</title>
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

        /* ── CLUB CARD ──────────────────────────── */
        .ic-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); overflow:hidden; margin-bottom:1.5rem; animation:fadeUp .35s ease both; }
        .ic-top { background:linear-gradient(135deg,var(--navy),var(--navy-light)); padding:1.4rem 2rem; display:flex; align-items:flex-start; gap:1.1rem; position:relative; overflow:hidden; }
        .ic-top::after { content:''; position:absolute; right:-20px; bottom:-40px; width:150px; height:150px; border-radius:50%; background:rgba(255,255,255,.05); }
        .ic-badge { display:inline-flex; align-items:center; gap:.35rem; background:rgba(46,158,110,.3); border:1px solid rgba(46,158,110,.5); border-radius:20px; padding:.22rem .75rem; font-size:.72rem; color:#a8f0cc; font-family:'Outfit',sans-serif; font-weight:600; margin-bottom:.45rem; }
        .ic-badge.apertura   { background:rgba(74,127,212,.3);  border-color:rgba(74,127,212,.5);  color:#cfe0ff; }
        .ic-badge.finalizado { background:rgba(122,128,153,.3); border-color:rgba(122,128,153,.5); color:#dfe2ec; }
        .ic-badge.cancelado  { background:rgba(217,79,79,.3);   border-color:rgba(217,79,79,.5);   color:#ffd6d6; }
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
            .ic-body { grid-template-columns:1fr; }
            .two-col-ins { grid-template-columns:1fr; }
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
        <a href="dashboard_alumno.php">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span>Inicio</span>
        </a>
        <a href="historial_clubs.php" class="active">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span>Historial</span>
        </a>
        <a href="registro_clubs.php">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Registrarme</span>
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

    <div class="sec-lbl">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Historial de clubs <span style="font-weight:400;color:var(--gray-500)">(<?= count($mis_clubs) ?>)</span>
    </div>

    <?php if (empty($mis_clubs)): ?>
    <div style="text-align:center;padding:3rem 1rem;color:var(--gray-500);font-size:.88rem">
        <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto .75rem;opacity:.4"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Aún no te has inscrito a ningún club.
    </div>
    <?php else: foreach ($mis_clubs as $mc):
        $info = $estado_info[$mc['estado']] ?? ['label' => ucfirst($mc['estado']), 'cls' => '']; ?>
    <div class="ic-card">
        <div class="ic-top">
            <div class="ic-icon">
                <svg width="22" height="22" fill="none" stroke="white" stroke-width="1.8" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="ic-ti">
                <div class="ic-badge <?= $info['cls'] ?>">
                    <?php if ($mc['estado'] === 'iniciado'): ?>
                        <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                    <?php elseif ($mc['estado'] === 'apertura'): ?>
                        <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <?php elseif ($mc['estado'] === 'cancelado'): ?>
                        <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    <?php else: ?>
                        🏁
                    <?php endif; ?>
                    <?= htmlspecialchars($info['label']) ?>
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
                <div class="val"><?= htmlspecialchars($info['label']) ?></div>
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
            <?php if ($mc['estado'] === 'finalizado'): ?>
            <div style="text-align:center;padding:1rem 0 .5rem">
                <div style="font-size:2rem;line-height:1;margin-bottom:.5rem">🏁</div>
                <p style="font-size:.85rem;font-weight:600;color:var(--gray-700);margin:0">Club finalizado</p>
                <p style="font-size:.78rem;color:var(--gray-500);margin:.25rem 0 0">Este club ha concluido sus actividades.</p>
            </div>
            <?php elseif ($mc['estado'] === 'cancelado'): ?>
            <div style="text-align:center;padding:1rem 0 .5rem">
                <div style="font-size:2rem;line-height:1;margin-bottom:.5rem">🚫</div>
                <p style="font-size:.85rem;font-weight:600;color:var(--gray-700);margin:0">Club cancelado</p>
                <p style="font-size:.78rem;color:var(--gray-500);margin:.25rem 0 0">Este club ya no tiene actividades programadas.</p>
            </div>
            <?php elseif (empty($mc['proximas'])): ?>
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
    <?php endforeach; endif; ?>

</div>

<footer>© <?= date('Y') ?> Universidad de Colima — Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>

</body>
</html>
