<?php
/**
 * estadisticas_plantel.php
 * Vista de estadísticas de asistencia para el plantel.
 * Solo lectura — el plantel puede ver pero no modificar nada.
 */
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['plantel_id'])) {
    header('Location: login_plantel.php');
    exit;
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login_plantel.php');
    exit;
}

$plantel_id     = (int)$_SESSION['plantel_id'];
$plantel_nombre = $_SESSION['plantel_nombre'] ?? '';

date_default_timezone_set('America/Mexico_City');

$club_sel               = null;
$todos_clubs            = [];
$alumnos_stats          = [];
$sesiones_list          = [];
$asis_detalle           = [];
$total_sesiones_periodo = 0;
$parcial                = (int)($_GET['parcial'] ?? 0);
$club_id                = (int)($_GET['id_club'] ?? 0);
$rangos                 = [];
$fIni = $fFin           = null;

// Computed stats
$avg_global = 0;
$cnt_alta   = 0;
$cnt_media  = 0;
$cnt_baja   = 0;

try {
    $pdo = getDB();

    // ── Todos los clubs del plantel con stats generales ─────────────
    $stmt = $pdo->prepare("
        SELECT c.id, c.nombre, c.estado, c.fecha_inicio, c.fecha_fin, c.limite, c.semestre,
               IFNULL(CONCAT(p.nombres,' ',p.apellido_paterno),'Sin encargado') AS encargado_nombre,
               COUNT(DISTINCT ic.numero_cuenta) AS inscritos,
               COUNT(DISTINCT a.fecha) AS total_sesiones,
               SUM(CASE WHEN a.estado IN ('asistio','tarde') THEN 1 ELSE 0 END) AS total_presentes,
               COUNT(a.id)             AS total_registros
        FROM clubes c
        LEFT JOIN inscripciones_club ic ON ic.id_club  = c.id
        LEFT JOIN personas p            ON p.id        = c.id_encargado
        LEFT JOIN asistencias_club a ON a.id_club   = c.id
        WHERE c.id_plantel = ?
        GROUP BY c.id
        ORDER BY FIELD(c.estado,'iniciado','apertura','finalizado','borrador','cancelado'), c.id DESC
    ");
    $stmt->execute([$plantel_id]);
    $todos_clubs = $stmt->fetchAll();

    // ── Club seleccionado ───────────────────────────────────────────
    if ($club_id) {
        $chk = $pdo->prepare("
            SELECT c.*,
                   IFNULL(CONCAT(p.nombres,' ',p.apellido_paterno),'Sin encargado') AS encargado_nombre,
                   COUNT(DISTINCT ic.numero_cuenta) AS inscritos
            FROM clubes c
            LEFT JOIN personas           p  ON p.id       = c.id_encargado
            LEFT JOIN inscripciones_club ic ON ic.id_club = c.id
            WHERE c.id = ? AND c.id_plantel = ?
            GROUP BY c.id
        ");
        $chk->execute([$club_id, $plantel_id]);
        $club_sel = $chk->fetch();

        if ($club_sel) {
            $fi  = strtotime($club_sel['fecha_inicio']);
            $ff  = strtotime($club_sel['fecha_fin']);
            $dur = max(86400, $ff - $fi);
            $t   = intdiv($dur, 3);

            $rangos = [
                0 => [$club_sel['fecha_inicio'],            $club_sel['fecha_fin']],
                1 => [date('Y-m-d', $fi),                   date('Y-m-d', $fi + $t)],
                2 => [date('Y-m-d', $fi + $t  + 86400),    date('Y-m-d', $fi + 2 * $t)],
                3 => [date('Y-m-d', $fi + 2*$t + 86400),   $club_sel['fecha_fin']],
            ];
            if (!array_key_exists($parcial, $rangos)) $parcial = 0;
            [$fIni, $fFin] = $rangos[$parcial];

            // Sesiones únicas del período
            $s3 = $pdo->prepare("
                SELECT fecha,
                       COUNT(*) AS total_alumnos,
                       SUM(CASE WHEN estado IN ('asistio','tarde') THEN 1 ELSE 0 END) AS presentes,
                       SUM(CASE WHEN estado = 'falta' THEN 1 ELSE 0 END) AS faltas
                FROM asistencias_club
                WHERE id_club = ? AND fecha BETWEEN ? AND ?
                GROUP BY fecha ORDER BY fecha ASC
            ");
            $s3->execute([$club_id, $fIni, $fFin]);
            $sesiones_list          = $s3->fetchAll();
            $total_sesiones_periodo = count($sesiones_list);

            // Stats por alumno en el período
            $s2 = $pdo->prepare("
                SELECT e.id, e.nombre_completo, e.cuenta,
                       COUNT(CASE WHEN a.estado = 'asistio' THEN 1 END) AS asistencias,
                       COUNT(CASE WHEN a.estado = 'tarde'   THEN 1 END) AS tardes,
                       COUNT(CASE WHEN a.estado = 'falta'   THEN 1 END) AS faltas_cnt,
                       COUNT(a.id)                                        AS total_registros
                FROM inscripciones_club ic
                JOIN estudiantes e ON e.cuenta = ic.numero_cuenta
                LEFT JOIN asistencias_club a
                       ON a.id_estudiante = e.id
                      AND a.id_club = ?
                      AND a.fecha BETWEEN ? AND ?
                WHERE ic.id_club = ?
                GROUP BY e.id
                ORDER BY e.nombre_completo
            ");
            $s2->execute([$club_id, $fIni, $fFin, $club_id]);
            $alumnos_stats = $s2->fetchAll();

            // Detalle completo (para modal por alumno)
            $s4 = $pdo->prepare("
                SELECT id_estudiante, fecha, estado,
                       TIME_FORMAT(hora_entrada,'%H:%i') AS entrada,
                       TIME_FORMAT(hora_salida, '%H:%i') AS salida
                FROM asistencias_club
                WHERE id_club = ? AND fecha BETWEEN ? AND ?
                ORDER BY id_estudiante, fecha
            ");
            $s4->execute([$club_id, $fIni, $fFin]);
            foreach ($s4->fetchAll() as $row) {
                $asis_detalle[$row['id_estudiante']][$row['fecha']] = [
                    'estado'  => $row['estado'],
                    'entrada' => $row['entrada'],
                    'salida'  => $row['salida'],
                ];
            }

            // Estadísticas globales del período
            if (!empty($alumnos_stats) && $total_sesiones_periodo > 0) {
                $sum_pct = 0;
                foreach ($alumnos_stats as $al) {
                    $pres = (int)$al['asistencias'] + (int)$al['tardes'];
                    $pct  = round($pres / $total_sesiones_periodo * 100);
                    $sum_pct += $pct;
                    if ($pct >= 80)      $cnt_alta++;
                    elseif ($pct >= 60)  $cnt_media++;
                    else                 $cnt_baja++;
                }
                $avg_global = round($sum_pct / count($alumnos_stats));
            }
        }
    }

} catch (Exception $e) {
    error_log('estadisticas_plantel: ' . $e->getMessage());
    $todos_clubs = [];
}

// ── Helpers ─────────────────────────────────────────────────────────
function fmtDate(string $d): string {
    $m = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    [$y, $mo, $day] = explode('-', $d);
    return "$day {$m[(int)$mo]} $y";
}

function dayName(string $d): string {
    return ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'][date('w', strtotime($d))];
}

function pctStyle(int $pct, bool $noSes = false): array {
    if ($noSes) return ['c' => 'var(--gray-400)', 'bar' => 'var(--gray-300)', 'lbl' => '—', 'cls' => 'none'];
    if ($pct >= 80) return ['c' => '#1d6344',  'bar' => '#2e9e6e', 'lbl' => 'Alta',  'cls' => 'alta'];
    if ($pct >= 60) return ['c' => '#7a4f10',  'bar' => '#d47a20', 'lbl' => 'Media', 'cls' => 'media'];
    return             ['c' => '#8b2020',  'bar' => '#d94f4f', 'lbl' => 'Baja',  'cls' => 'baja'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estadísticas — <?= htmlspecialchars($plantel_nombre) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:    #1b2d54;
            --navy-l:  #243567;
            --accent:  #4a7fd4;
            --success: #2e9e6e;
            --error:   #d94f4f;
            --warning: #d47a20;
            --white:   #ffffff;
            --gray-50: #f7f8fc;
            --gray-100:#eef0f6;
            --gray-200:#e0e4f0;
            --gray-300:#c5cad8;
            --gray-400:#adb3c6;
            --gray-500:#7a8099;
            --gray-700:#3d4260;
            --text:    #1e2340;
            --radius:  14px;
            --radius-sm:8px;
            --shadow:  0 4px 20px rgba(27,45,84,.09);
            --shadow-lg:0 10px 36px rgba(27,45,84,.14);
        }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'DM Sans',sans-serif; background:var(--gray-50); color:var(--text);
               display:flex; flex-direction:column; height:100vh; overflow:hidden; }

        /* HEADER */
        header { background:var(--navy); height:60px; display:flex; align-items:center;
                 justify-content:space-between; padding:0 1.5rem;
                 box-shadow:0 2px 10px rgba(0,0,0,.25); flex-shrink:0; z-index:50; }
        .hb { display:flex; align-items:center; gap:.65rem; }
        .hb-logo { width:36px; height:36px; border-radius:50%; background:#fff; display:flex;
                   align-items:center; justify-content:center; font-family:'Outfit',sans-serif;
                   font-weight:700; font-size:.7rem; color:var(--navy); flex-shrink:0; }
        .hb-title { font-family:'Outfit',sans-serif; font-size:.98rem; font-weight:700; color:#fff; }
        .hb-sub   { font-size:.68rem; color:rgba(255,255,255,.5); margin-top:.05rem; }
        .hb-nav   { display:flex; gap:.45rem; align-items:center; }
        .nav-link { color:rgba(255,255,255,.75); text-decoration:none; font-size:.78rem; font-weight:500;
                    padding:.35rem .8rem; border-radius:var(--radius-sm); border:1px solid rgba(255,255,255,.2);
                    display:flex; align-items:center; gap:.3rem; transition:all .2s; white-space:nowrap; }
        .nav-link:hover { background:rgba(255,255,255,.1); color:#fff; }

        /* LAYOUT */
        .layout { display:flex; flex:1; overflow:hidden; }

        /* SIDEBAR */
        .sidebar { width:284px; flex-shrink:0; background:var(--white); border-right:1px solid var(--gray-200);
                   display:flex; flex-direction:column; overflow:hidden; }
        .sb-head { padding:1rem 1rem .65rem; border-bottom:1px solid var(--gray-100); }
        .sb-title { font-family:'Outfit',sans-serif; font-size:.78rem; font-weight:700; color:var(--navy);
                    text-transform:uppercase; letter-spacing:.5px; margin-bottom:.5rem;
                    display:flex; align-items:center; gap:.4rem; }
        .sb-search { width:100%; padding:.45rem .65rem; border:1.5px solid var(--gray-200);
                     border-radius:var(--radius-sm); font-family:'DM Sans',sans-serif; font-size:.8rem;
                     color:var(--text); outline:none; background:var(--gray-50); transition:border-color .2s; }
        .sb-search:focus { border-color:var(--accent); }
        .sb-clubs { overflow-y:auto; flex:1; padding:.4rem .5rem .5rem; }
        .sb-club { display:block; text-decoration:none; border-radius:var(--radius-sm); padding:.65rem .8rem;
                   margin-bottom:.3rem; color:var(--text); transition:all .18s;
                   border:1.5px solid transparent; background:var(--gray-50); }
        .sb-club:hover  { background:var(--gray-100); border-color:var(--gray-200); }
        .sb-club.active { background:#eef3fc; border-color:var(--accent); }
        .sb-name { font-family:'Outfit',sans-serif; font-size:.84rem; font-weight:700; color:var(--navy);
                   margin-bottom:.3rem; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sb-club.active .sb-name { color:var(--accent); }
        .sb-meta { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; margin-bottom:.35rem; }
        .sb-bar-wrap { height:4px; background:var(--gray-100); border-radius:4px; overflow:hidden; margin-bottom:.2rem; }
        .sb-bar  { height:100%; border-radius:4px; }
        .sb-pct  { font-size:.69rem; }

        /* Estado mini-badges */
        .ebadge { display:inline-flex; align-items:center; padding:.12rem .45rem; border-radius:20px;
                  font-size:.63rem; font-weight:700; font-family:'Outfit',sans-serif; white-space:nowrap; }
        .eb-borrador   { background:#f0f0ff; color:#5a4fcf; }
        .eb-apertura   { background:#e8f4ff; color:#2a5ea8; }
        .eb-iniciado   { background:#edfaf4; color:#1d6344; }
        .eb-finalizado { background:#f0f0f6; color:var(--gray-500); }
        .eb-cancelado  { background:#fff0f0; color:#8b2020; }

        /* MAIN */
        .main { flex:1; overflow-y:auto; padding:1.5rem 1.75rem 3rem; }

        /* Overview grid */
        .ov-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:1rem; }
        .ov-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow);
                   overflow:hidden; transition:transform .2s,box-shadow .2s; text-decoration:none;
                   color:var(--text); display:block; }
        .ov-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-lg); }
        .ov-bar  { height:4px; }
        .ov-body { padding:1rem 1.1rem .95rem; }
        .ov-nombre { font-family:'Outfit',sans-serif; font-size:.9rem; font-weight:700; color:var(--navy); margin-bottom:.2rem; }
        .ov-enc { font-size:.74rem; color:var(--gray-500); margin-bottom:.5rem; display:flex; align-items:center; gap:.25rem; }
        .ov-stats { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:.55rem; font-size:.74rem; color:var(--gray-700); }
        .ov-stat { display:flex; align-items:center; gap:.2rem; }
        .ov-prog-wrap { height:6px; background:var(--gray-100); border-radius:6px; overflow:hidden; margin-top:.35rem; }
        .ov-prog-fill { height:100%; border-radius:6px; }
        .ov-prog-lbl  { display:flex; justify-content:space-between; margin-top:.25rem; font-size:.68rem; color:var(--gray-500); }

        /* Club detail header */
        .detail-header { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow);
                         padding:1.25rem 1.5rem; margin-bottom:1.25rem; }
        .dh-top  { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
        .dh-nombre { font-family:'Outfit',sans-serif; font-size:1.2rem; font-weight:700; color:var(--navy); margin-bottom:.2rem; }
        .dh-enc  { font-size:.82rem; color:var(--gray-500); display:flex; align-items:center; gap:.35rem; margin-bottom:.6rem; }
        .dh-chips { display:flex; gap:.4rem; flex-wrap:wrap; }
        .chip { display:inline-flex; align-items:center; gap:.3rem; padding:.25rem .7rem; border-radius:20px;
                font-size:.73rem; font-weight:600; font-family:'Outfit',sans-serif;
                background:var(--gray-50); border:1px solid var(--gray-200); color:var(--gray-700); }

        /* Parcial tabs */
        .parcial-tabs { display:flex; gap:.35rem; flex-wrap:wrap; margin-bottom:1.25rem; }
        .parcial-tab { padding:.45rem 1rem; border-radius:20px; font-family:'Outfit',sans-serif;
                       font-size:.79rem; font-weight:600; text-decoration:none;
                       border:1.5px solid var(--gray-200); color:var(--gray-700); background:var(--white);
                       transition:all .2s; white-space:nowrap; display:flex; flex-direction:column; align-items:center; }
        .parcial-tab:hover  { border-color:var(--accent); color:var(--accent); }
        .parcial-tab.active { background:var(--accent); color:#fff; border-color:var(--accent);
                              box-shadow:0 3px 10px rgba(74,127,212,.3); }
        .pt-rango { font-size:.63rem; opacity:.72; margin-top:.08rem; }

        /* Mini stat cards */
        .mini-cards { display:grid; grid-template-columns:repeat(4,1fr); gap:.85rem; margin-bottom:1.25rem; }
        .mini-card  { background:var(--white); border-radius:var(--radius-sm); box-shadow:var(--shadow);
                      padding:1rem 1.1rem; border-top:3px solid; }
        .mc-num { font-family:'Outfit',sans-serif; font-size:1.75rem; font-weight:700; line-height:1; margin-bottom:.15rem; }
        .mc-lbl { font-size:.74rem; color:var(--gray-500); line-height:1.4; }

        /* Table card */
        .table-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow);
                      overflow:hidden; margin-bottom:1.25rem; }
        .tc-header { padding:.85rem 1.25rem; border-bottom:1px solid var(--gray-100);
                     display:flex; align-items:center; justify-content:space-between; gap:1rem; }
        .tc-title  { font-family:'Outfit',sans-serif; font-size:.88rem; font-weight:700; color:var(--navy);
                     display:flex; align-items:center; gap:.45rem; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        th { padding:.65rem 1rem; font-family:'Outfit',sans-serif; font-size:.7rem; font-weight:700;
             text-transform:uppercase; letter-spacing:.4px; color:var(--gray-500); text-align:left;
             background:var(--gray-50); border-bottom:2px solid var(--gray-100); white-space:nowrap; }
        td { padding:.7rem 1rem; font-size:.83rem; border-bottom:1px solid var(--gray-100); vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        tr:hover td { background:#fafbfd; }
        tr.tr-risk td { background:#fffaf8; }

        /* Attendance badge */
        .ab { display:inline-flex; align-items:center; padding:.18rem .6rem; border-radius:20px;
              font-size:.71rem; font-weight:700; font-family:'Outfit',sans-serif; }
        .ab-alta  { background:#e8faf4; color:#1d6344; }
        .ab-media { background:#fff8ee; color:#7a4f10; }
        .ab-baja  { background:#fff0f0; color:#8b2020; }
        .ab-none  { background:var(--gray-100); color:var(--gray-500); }

        /* Pct bar inside cell */
        .pct-cell    { display:flex; flex-direction:column; gap:.2rem; min-width:100px; }
        .pct-bar-wrap { height:4px; background:var(--gray-100); border-radius:4px; overflow:hidden; }
        .pct-bar     { height:100%; border-radius:4px; }
        .pct-num     { font-family:'Outfit',sans-serif; font-size:.75rem; font-weight:700; }

        /* Sessions list */
        .ses-list { display:flex; flex-direction:column; gap:.35rem; padding:1rem 1.25rem; }
        .ses-row  { display:grid; grid-template-columns:130px 1fr 60px 60px 50px;
                    gap:.5rem; align-items:center;
                    background:var(--gray-50); border-radius:var(--radius-sm); padding:.55rem .8rem; }
        .ses-fecha { font-family:'Outfit',sans-serif; font-weight:600; font-size:.81rem; color:var(--navy); }
        .ses-day   { font-size:.7rem; color:var(--gray-500); }
        .ses-num   { font-weight:600; text-align:center; font-size:.82rem; }
        .ses-bar   { height:5px; background:var(--gray-200); border-radius:5px; overflow:hidden; }
        .ses-bar-fill { height:100%; border-radius:5px; }

        /* Student detail modal */
        .modal-ov   { display:none; position:fixed; inset:0; background:rgba(17,30,58,.52);
                      backdrop-filter:blur(4px); z-index:200; align-items:center;
                      justify-content:center; padding:1rem; }
        .modal-ov.open { display:flex; }
        .modal-wrap { background:#fff; border-radius:14px; box-shadow:0 24px 64px rgba(0,0,0,.24);
                      width:100%; max-width:520px; max-height:88vh; overflow-y:auto;
                      animation:fadeUp .22s ease both; }
        .modal-top  { background:linear-gradient(135deg,var(--navy),var(--navy-l)); padding:1.25rem 1.5rem;
                      display:flex; align-items:flex-start; justify-content:space-between;
                      position:sticky; top:0; z-index:1; }
        .modal-top h3 { font-family:'Outfit',sans-serif; font-weight:700; font-size:1rem; color:#fff; margin-bottom:.12rem; }
        .modal-top p  { font-size:.75rem; color:rgba(255,255,255,.6); }
        .modal-close  { background:none; border:none; color:rgba(255,255,255,.55); cursor:pointer; padding:2px; transition:color .2s; flex-shrink:0; }
        .modal-close:hover { color:#fff; }
        .modal-body { padding:1.25rem 1.5rem; }

        /* Attendance rows in modal */
        .asis-grid { display:flex; flex-direction:column; gap:.3rem; }
        .asis-row  { display:grid; grid-template-columns:10px 1fr 1fr auto; gap:.5rem;
                     align-items:center; padding:.42rem .6rem; border-radius:6px; font-size:.8rem; }
        .asis-row.asistio { background:#e8faf4; }
        .asis-row.tarde   { background:#fff8ee; }
        .asis-row.falta   { background:#fff0f0; }
        .asis-row.sin-reg { background:var(--gray-50); color:var(--gray-500); }
        .asis-dot   { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        .asis-fecha { font-family:'Outfit',sans-serif; font-weight:600; font-size:.78rem; }
        .asis-hora  { font-size:.72rem; color:var(--gray-500); text-align:center; }
        .asis-lbl   { font-size:.7rem; font-weight:700; font-family:'Outfit',sans-serif; text-align:right; white-space:nowrap; }

        /* Btn */
        .btn-ver { height:26px; padding:0 .65rem; border-radius:6px; font-family:'Outfit',sans-serif;
                   font-size:.71rem; font-weight:700; cursor:pointer; border:1.5px solid var(--accent);
                   color:var(--accent); background:#f0f5ff; display:inline-flex; align-items:center;
                   gap:.25rem; transition:all .2s; white-space:nowrap; }
        .btn-ver:hover { background:var(--accent); color:#fff; }

        @keyframes fadeUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }

        @media (max-width:1100px) { .mini-cards { grid-template-columns:repeat(2,1fr); } }
        @media (max-width:880px) {
            .sidebar { width:230px; }
            .ses-row { grid-template-columns:110px 1fr 55px 55px; }
            .ses-row > *:last-child { display:none; }
        }
        @media (max-width:640px) {
            body { overflow:auto; height:auto; }
            .layout { flex-direction:column; overflow:visible; }
            .sidebar { width:100%; border-right:none; border-bottom:1px solid var(--gray-200); max-height:220px; }
            .main { overflow:visible; padding:1rem; }
        }
    </style>
</head>
<body>

<header>
    <div class="hb">
        <div class="hb-logo">UdeC</div>
        <div>
            <div class="hb-title">Estadísticas de Clubes</div>
            <div class="hb-sub"><?= htmlspecialchars($plantel_nombre) ?></div>
        </div>
    </div>
    <div class="hb-nav">
        <a href="dashboard_plantel.php" class="nav-link">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Panel
        </a>
        <a href="?logout=1" class="nav-link">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Salir
        </a>
    </div>
</header>

<div class="layout">

<!-- ══════════════ SIDEBAR ══════════════ -->
<aside class="sidebar">
    <div class="sb-head">
        <div class="sb-title">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Clubes (<?= count($todos_clubs) ?>)
        </div>
        <input type="text" id="sb-search" class="sb-search" placeholder="Buscar club…"
               oninput="filtrarSidebar(this.value)">
    </div>
    <div class="sb-clubs" id="sb-clubs">
        <?php foreach ($todos_clubs as $c):
            $s    = (int)$c['total_sesiones'];
            $ins  = (int)$c['inscritos'];
            $pres = (int)$c['total_presentes'];
            $avg  = ($ins > 0 && $s > 0) ? round($pres / ($ins * $s) * 100) : -1;
            $active = ($club_id === (int)$c['id']);
            $bar_c  = $avg >= 80 ? '#2e9e6e' : ($avg >= 60 ? '#d47a20' : '#d94f4f');
        ?>
        <a href="?id_club=<?= $c['id'] ?>&parcial=0"
           class="sb-club <?= $active ? 'active' : '' ?>"
           data-nombre="<?= htmlspecialchars(mb_strtolower($c['nombre'])) ?>">
            <div class="sb-name"><?= htmlspecialchars($c['nombre']) ?></div>
            <div class="sb-meta">
                <span class="ebadge eb-<?= $c['estado'] ?>"><?= ucfirst($c['estado']) ?></span>
                <span style="font-size:.69rem;color:var(--gray-500)"><?= $ins ?> inscrito<?= $ins !== 1 ? 's' : '' ?></span>
                <?php if ($s > 0): ?>
                <span style="font-size:.69rem;color:var(--gray-500)"><?= $s ?> sesión<?= $s !== 1 ? 'es' : '' ?></span>
                <?php endif; ?>
            </div>
            <?php if ($avg >= 0): ?>
            <div class="sb-bar-wrap"><div class="sb-bar" style="width:<?= $avg ?>%;background:<?= $bar_c ?>"></div></div>
            <div class="sb-pct" style="color:<?= $bar_c ?>"><?= $avg ?>% asistencia</div>
            <?php else: ?>
            <div class="sb-pct" style="color:var(--gray-400)">Sin sesiones aún</div>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if (empty($todos_clubs)): ?>
        <div style="text-align:center;padding:2rem .5rem;color:var(--gray-500);font-size:.8rem">
            Sin clubes registrados
        </div>
        <?php endif; ?>
    </div>
</aside>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main">

<?php if (!$club_sel): ?>
<!-- ── VISTA GENERAL ────────────────────────────────────────────── -->
<?php
$tot_clubs         = count($todos_clubs);
$tot_inscritos     = array_sum(array_column($todos_clubs, 'inscritos'));
$tot_presentes_all = array_sum(array_column($todos_clubs, 'total_presentes'));
$tot_registros_all = array_sum(array_column($todos_clubs, 'total_registros'));
$clubs_activos     = count(array_filter($todos_clubs, fn($c) => $c['estado'] === 'iniciado'));
// Avg across all clubs: presentes / (inscritos * sesiones)
$pairs = array_filter($todos_clubs, fn($c) => (int)$c['inscritos'] > 0 && (int)$c['total_sesiones'] > 0);
$avg_plantel = 0;
if (!empty($pairs)) {
    $sum = array_sum(array_map(fn($c) => round((int)$c['total_presentes'] / ((int)$c['inscritos'] * (int)$c['total_sesiones']) * 100), $pairs));
    $avg_plantel = round($sum / count($pairs));
}
$apc = $avg_plantel >= 80 ? 'var(--success)' : ($avg_plantel >= 60 ? 'var(--warning)' : 'var(--error)');
?>

<div style="margin-bottom:1.5rem">
    <h2 style="font-family:'Outfit',sans-serif;font-size:1.1rem;font-weight:700;color:var(--navy);margin-bottom:.2rem">
        Vista general · <?= htmlspecialchars($plantel_nombre) ?>
    </h2>
    <p style="font-size:.82rem;color:var(--gray-500)">
        Selecciona un club en la barra lateral para ver el desglose de asistencia.
    </p>
</div>

<!-- Summary stat cards -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.85rem;margin-bottom:1.5rem">
    <div style="background:var(--white);border-radius:var(--radius-sm);box-shadow:var(--shadow);padding:1rem 1.1rem;border-top:3px solid var(--accent)">
        <div style="font-family:'Outfit',sans-serif;font-size:1.75rem;font-weight:700;color:var(--accent);line-height:1;margin-bottom:.12rem"><?= $tot_clubs ?></div>
        <div style="font-size:.74rem;color:var(--gray-500)">Clubes registrados</div>
    </div>
    <div style="background:var(--white);border-radius:var(--radius-sm);box-shadow:var(--shadow);padding:1rem 1.1rem;border-top:3px solid var(--success)">
        <div style="font-family:'Outfit',sans-serif;font-size:1.75rem;font-weight:700;color:var(--success);line-height:1;margin-bottom:.12rem"><?= $clubs_activos ?></div>
        <div style="font-size:.74rem;color:var(--gray-500)">Clubes en curso</div>
    </div>
    <div style="background:var(--white);border-radius:var(--radius-sm);box-shadow:var(--shadow);padding:1rem 1.1rem;border-top:3px solid var(--warning)">
        <div style="font-family:'Outfit',sans-serif;font-size:1.75rem;font-weight:700;color:var(--warning);line-height:1;margin-bottom:.12rem"><?= $tot_inscritos ?></div>
        <div style="font-size:.74rem;color:var(--gray-500)">Alumnos inscritos</div>
    </div>
    <div style="background:var(--white);border-radius:var(--radius-sm);box-shadow:var(--shadow);padding:1rem 1.1rem;border-top:3px solid <?= empty($pairs) ? 'var(--gray-300)' : $apc ?>">
        <div style="font-family:'Outfit',sans-serif;font-size:1.75rem;font-weight:700;color:<?= empty($pairs) ? 'var(--gray-400)' : $apc ?>;line-height:1;margin-bottom:.12rem">
            <?= empty($pairs) ? '—' : $avg_plantel.'%' ?>
        </div>
        <div style="font-size:.74rem;color:var(--gray-500)">Asistencia promedio general</div>
    </div>
</div>

<?php if (empty($todos_clubs)): ?>
<div style="text-align:center;padding:4rem 1rem;color:var(--gray-500)">
    <svg width="60" height="60" fill="none" stroke="currentColor" stroke-width="1.3" viewBox="0 0 24 24" style="display:block;margin:0 auto 1rem;opacity:.3"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    <p style="font-weight:600;margin-bottom:.3rem">Sin clubes registrados</p>
    <span style="font-size:.82rem">Los clubes creados aparecerán aquí.</span>
</div>
<?php else: ?>

<div class="ov-grid">
<?php
$bar_cols = ['#4a7fd4','#2e9e6e','#d47a20','#1b2d54','#7b5ea7','#c03030','#2a8a8a','#a07840'];
foreach ($todos_clubs as $ci => $c):
    $s    = (int)$c['total_sesiones'];
    $ins  = (int)$c['inscritos'];
    $pres = (int)$c['total_presentes'];
    $avg  = ($ins > 0 && $s > 0) ? round($pres / ($ins * $s) * 100) : -1;
    $bc   = $bar_cols[$ci % count($bar_cols)];
    $fc   = $avg >= 80 ? '#2e9e6e' : ($avg >= 60 ? '#d47a20' : '#d94f4f');
?>
<a href="?id_club=<?= $c['id'] ?>&parcial=0" class="ov-card">
    <div class="ov-bar" style="background:<?= $bc ?>"></div>
    <div class="ov-body">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.4rem;margin-bottom:.25rem">
            <div class="ov-nombre"><?= htmlspecialchars($c['nombre']) ?></div>
            <span class="ebadge eb-<?= $c['estado'] ?>" style="flex-shrink:0;margin-top:.1rem"><?= ucfirst($c['estado']) ?></span>
        </div>
        <div class="ov-enc">
            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= htmlspecialchars($c['encargado_nombre']) ?>
        </div>
        <div class="ov-stats">
            <span class="ov-stat">
                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                <?= $ins ?>/<?= $c['limite'] ?> inscritos
            </span>
            <span class="ov-stat">
                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <?= $s ?> sesion<?= $s !== 1 ? 'es' : '' ?>
            </span>
        </div>
        <?php if ($avg >= 0): ?>
        <div class="ov-prog-wrap">
            <div class="ov-prog-fill" style="width:<?= $avg ?>%;background:<?= $fc ?>"></div>
        </div>
        <div class="ov-prog-lbl">
            <span style="color:<?= $fc ?>;font-weight:700"><?= $avg ?>% asistencia</span>
            <span><?= $avg >= 80 ? 'Alta' : ($avg >= 60 ? 'Media' : 'Baja') ?></span>
        </div>
        <?php else: ?>
        <div style="font-size:.71rem;color:var(--gray-400);margin-top:.4rem">Sin sesiones registradas aún</div>
        <?php endif; ?>
    </div>
</a>
<?php endforeach; ?>
</div><!-- /ov-grid -->
<?php endif; ?>

<?php else: // ── DETALLE DEL CLUB ──────────────────────────────────────── ?>

<!-- Header del club -->
<div class="detail-header">
    <div class="dh-top">
        <div>
            <div class="dh-nombre"><?= htmlspecialchars($club_sel['nombre']) ?></div>
            <div class="dh-enc">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?= htmlspecialchars($club_sel['encargado_nombre']) ?>
            </div>
            <div class="dh-chips">
                <span class="ebadge eb-<?= $club_sel['estado'] ?>" style="font-size:.74rem;padding:.25rem .7rem"><?= ucfirst($club_sel['estado']) ?></span>
                <span class="chip">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= fmtDate($club_sel['fecha_inicio']) ?> – <?= fmtDate($club_sel['fecha_fin']) ?>
                </span>
                <span class="chip">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    <?= $club_sel['inscritos'] ?>/<?= $club_sel['limite'] ?> inscritos
                </span>
                <span class="chip"><?= ucfirst($club_sel['semestre']) ?> semestre</span>
            </div>
        </div>
        <?php if ($club_sel['descripcion']): ?>
        <div style="max-width:260px;font-size:.8rem;color:var(--gray-700);line-height:1.55;font-style:italic;text-align:right">
            <?= htmlspecialchars($club_sel['descripcion']) ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Parcial tabs -->
<?php $tab_cfg = [
    [0,'📋','Completo'],
    [1,'📗','1er Parcial'],
    [2,'📘','2do Parcial'],
    [3,'📙','3er Parcial'],
]; ?>
<div class="parcial-tabs">
    <?php foreach ($tab_cfg as [$pi, $ico, $plbl]):
        $rng = $rangos[$pi] ?? null;
    ?>
    <a href="?id_club=<?= $club_id ?>&parcial=<?= $pi ?>"
       class="parcial-tab <?= $parcial === $pi ? 'active' : '' ?>">
        <?= $ico ?> <?= $plbl ?>
        <?php if ($rng): ?>
        <span class="pt-rango"><?= fmtDate($rng[0]) ?> – <?= fmtDate($rng[1]) ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Stat mini-cards -->
<?php
$noSes = ($total_sesiones_periodo === 0);
$pct_c = $noSes ? 'var(--gray-400)' : ($avg_global >= 80 ? 'var(--success)' : ($avg_global >= 60 ? 'var(--warning)' : 'var(--error)'));
?>
<div class="mini-cards">
    <div class="mini-card" style="border-color:var(--accent)">
        <div class="mc-num" style="color:var(--accent)"><?= $total_sesiones_periodo ?></div>
        <div class="mc-lbl">Sesiones con registro<?= $parcial > 0 ? ' en este parcial' : '' ?></div>
    </div>
    <div class="mini-card" style="border-color:<?= $pct_c ?>">
        <div class="mc-num" style="color:<?= $pct_c ?>"><?= $noSes ? '—' : $avg_global.'%' ?></div>
        <div class="mc-lbl">Asistencia promedio<?= $parcial > 0 ? ' en el parcial' : '' ?></div>
    </div>
    <div class="mini-card" style="border-color:var(--success)">
        <div class="mc-num" style="color:var(--success)"><?= $cnt_alta ?></div>
        <div class="mc-lbl">Con asistencia alta (≥ 80%)</div>
    </div>
    <div class="mini-card" style="border-color:var(--error)">
        <div class="mc-num" style="color:var(--error)"><?= $cnt_baja ?></div>
        <div class="mc-lbl">En riesgo (< 60%)</div>
    </div>
</div>

<!-- Tabla de alumnos -->
<div class="table-card">
    <div class="tc-header">
        <div class="tc-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            Alumnos inscritos
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <?php if ($cnt_alta > 0): ?>
            <span style="font-size:.73rem;font-weight:700;background:#e8faf4;color:#1d6344;border-radius:20px;padding:.18rem .65rem"><?= $cnt_alta ?> alta</span>
            <?php endif; ?>
            <?php if ($cnt_media > 0): ?>
            <span style="font-size:.73rem;font-weight:700;background:#fff8ee;color:#7a4f10;border-radius:20px;padding:.18rem .65rem"><?= $cnt_media ?> media</span>
            <?php endif; ?>
            <?php if ($cnt_baja > 0): ?>
            <span style="font-size:.73rem;font-weight:700;background:#fff0f0;color:#8b2020;border-radius:20px;padding:.18rem .65rem"><?= $cnt_baja ?> baja</span>
            <?php endif; ?>
            <?php if (!empty($alumnos_stats)): ?>
            <span style="font-size:.73rem;color:var(--gray-500)"><?= count($alumnos_stats) ?> alumnos</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($alumnos_stats)): ?>
    <div style="text-align:center;padding:2.5rem 1rem;color:var(--gray-500)">
        <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto .75rem;opacity:.35"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        <p style="font-size:.85rem;font-weight:600">Sin alumnos inscritos en este club</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nombre</th>
                    <th>Cuenta</th>
                    <th style="text-align:center">Asist.</th>
                    <th style="text-align:center">Tarde</th>
                    <th style="text-align:center">Faltas</th>
                    <th>% Asistencia</th>
                    <th style="text-align:center">Nivel</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($alumnos_stats as $idx => $al):
                $pres = (int)$al['asistencias'] + (int)$al['tardes'];
                $pct  = $noSes ? 0 : round($pres / $total_sesiones_periodo * 100);
                $ps   = pctStyle($pct, $noSes);
                $risk = (!$noSes && $pct < 60);
            ?>
            <tr <?= $risk ? 'class="tr-risk"' : '' ?>>
                <td style="color:var(--gray-400);font-size:.74rem"><?= $idx + 1 ?></td>
                <td style="font-weight:600"><?= htmlspecialchars($al['nombre_completo']) ?></td>
                <td style="font-family:'Outfit',sans-serif;font-size:.82rem;color:var(--gray-700)"><?= $al['cuenta'] ?></td>
                <td style="text-align:center;font-weight:600;color:#1d6344"><?= $al['asistencias'] ?></td>
                <td style="text-align:center;font-weight:600;color:#7a4f10"><?= $al['tardes'] ?></td>
                <td style="text-align:center;font-weight:600;color:#8b2020"><?= $al['faltas_cnt'] ?></td>
                <td>
                    <?php if (!$noSes): ?>
                    <div class="pct-cell">
                        <div class="pct-bar-wrap">
                            <div class="pct-bar" style="width:<?= $pct ?>%;background:<?= $ps['bar'] ?>"></div>
                        </div>
                        <span class="pct-num" style="color:<?= $ps['c'] ?>"><?= $pct ?>%</span>
                    </div>
                    <?php else: ?>
                    <span style="color:var(--gray-400);font-size:.8rem">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center">
                    <span class="ab ab-<?= $ps['cls'] ?>"><?= $ps['lbl'] ?></span>
                </td>
                <td>
                    <button class="btn-ver"
                        onclick="abrirDetalle(<?= $al['id'] ?>, <?= json_encode($al['nombre_completo']) ?>, '<?= (int)$al['cuenta'] ?>')">
                        <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        Ver
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; // alumnos_stats ?>
</div><!-- /table-card alumnos -->

<!-- Registro de sesiones -->
<?php if (!empty($sesiones_list)): ?>
<div class="table-card">
    <div class="tc-header" style="cursor:pointer;user-select:none" onclick="toggleSesiones()">
        <div class="tc-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
            Registro de sesiones (<?= $total_sesiones_periodo ?>)
        </div>
        <svg id="ses-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"
             viewBox="0 0 24 24" style="color:var(--gray-500);transition:transform .2s">
            <polyline points="6 9 12 15 18 9"/>
        </svg>
    </div>
    <div id="ses-body" style="display:none">
    <div class="ses-list">
        <?php
        $ins_cl = (int)$club_sel['inscritos'];
        foreach ($sesiones_list as $ses):
            $pres_s = (int)$ses['presentes'];
            $pct_s  = $ins_cl > 0 ? round($pres_s / $ins_cl * 100) : 0;
            $fc_s   = $pct_s >= 80 ? '#2e9e6e' : ($pct_s >= 60 ? '#d47a20' : '#d94f4f');
        ?>
        <div class="ses-row">
            <div>
                <div class="ses-fecha"><?= fmtDate($ses['fecha']) ?></div>
                <div class="ses-day"><?= dayName($ses['fecha']) ?></div>
            </div>
            <div>
                <div class="ses-bar">
                    <div class="ses-bar-fill" style="width:<?= $pct_s ?>%;background:<?= $fc_s ?>"></div>
                </div>
                <div style="font-size:.67rem;color:var(--gray-500);margin-top:.15rem"><?= $pct_s ?>% asistencia</div>
            </div>
            <div>
                <div class="ses-num" style="color:#1d6344"><?= $ses['presentes'] ?></div>
                <div style="font-size:.66rem;color:var(--gray-500)">pres.</div>
            </div>
            <div>
                <div class="ses-num" style="color:#8b2020"><?= $ses['faltas'] ?></div>
                <div style="font-size:.66rem;color:var(--gray-500)">faltas</div>
            </div>
            <div style="text-align:right;font-size:.71rem;color:var(--gray-500)"><?= $ses['total_alumnos'] ?> reg.</div>
        </div>
        <?php endforeach; ?>
    </div>
    </div>
</div>
<?php elseif ($club_sel): ?>
<div style="background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);padding:2rem;text-align:center;color:var(--gray-500)">
    <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto .75rem;opacity:.3"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    <p style="font-size:.85rem;font-weight:600;margin-bottom:.3rem">Sin sesiones registradas<?= $parcial > 0 ? ' en este parcial' : '' ?></p>
    <span style="font-size:.8rem">
        <?php if ($parcial > 0): ?>
        Prueba con la vista <a href="?id_club=<?= $club_id ?>&parcial=0" style="color:var(--accent)">Completo</a>
        o espera a que se registre asistencia QR.
        <?php else: ?>
        Cuando el encargado registre asistencia QR aparecerá aquí.
        <?php endif; ?>
    </span>
</div>
<?php endif; ?>

<?php endif; // club_sel ?>

</main>
</div><!-- /layout -->

<!-- MODAL DETALLE ALUMNO -->
<div class="modal-ov" id="modal-ov">
    <div class="modal-wrap">
        <div class="modal-top">
            <div>
                <h3 id="modal-nombre">—</h3>
                <p id="modal-cuenta">—</p>
            </div>
            <button class="modal-close" onclick="cerrarModal()">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <div id="modal-resumen" style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem"></div>
            <div style="font-family:'Outfit',sans-serif;font-size:.72rem;font-weight:700;text-transform:uppercase;
                        letter-spacing:.5px;color:var(--gray-500);margin-bottom:.55rem">
                Registro por sesión
            </div>
            <div class="asis-grid" id="modal-grid"></div>
            <div id="modal-empty" style="display:none;text-align:center;padding:1.5rem;color:var(--gray-500);font-size:.85rem">
                Sin registros en este período.
            </div>
        </div>
    </div>
</div>

<script>
const _sesiones    = <?= json_encode(array_column($sesiones_list ?? [], null, 'fecha')) ?>;
const _asisDetalle = <?= json_encode($asis_detalle) ?>;
const _totalSes    = <?= $total_sesiones_periodo ?>;
const _inscritos   = <?= (int)($club_sel['inscritos'] ?? 0) ?>;

// Sidebar filter
function filtrarSidebar(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#sb-clubs .sb-club').forEach(el => {
        el.style.display = (!q || el.dataset.nombre.includes(q)) ? '' : 'none';
    });
}

// Toggle sessions accordion
function toggleSesiones() {
    const body    = document.getElementById('ses-body');
    const chevron = document.getElementById('ses-chevron');
    if (!body) return;
    const open = body.style.display !== 'none';
    body.style.display    = open ? 'none' : '';
    chevron.style.transform = open ? '' : 'rotate(180deg)';
}

// Open student detail modal
function abrirDetalle(id, nombre, cuenta) {
    document.getElementById('modal-nombre').textContent = nombre;
    document.getElementById('modal-cuenta').textContent = 'Cuenta #' + cuenta;

    const detalle = _asisDetalle[id] || {};
    const sesKeys = Object.keys(_sesiones).sort();

    let asist = 0, tardes = 0, faltas = 0, sinReg = 0;
    sesKeys.forEach(f => {
        const d = detalle[f];
        if (!d)                    sinReg++;
        else if (d.estado === 'asistio') asist++;
        else if (d.estado === 'tarde')   tardes++;
        else if (d.estado === 'falta')   faltas++;
    });

    const pct  = _totalSes > 0 ? Math.round((asist + tardes) / _totalSes * 100) : 0;
    const pctC = pct >= 80 ? '#2e9e6e' : (pct >= 60 ? '#d47a20' : '#d94f4f');
    const pctLbl = pct >= 80 ? 'Alta' : (pct >= 60 ? 'Media' : 'Baja');

    const pill = (bg, border, c, txt) =>
        `<span style="background:${bg};color:${c};border:1px solid ${border};border-radius:20px;
                      padding:.2rem .7rem;font-size:.74rem;font-weight:700;font-family:'Outfit',sans-serif">
            ${txt}
        </span>`;

    document.getElementById('modal-resumen').innerHTML =
        pill('#e8faf4','#a5dfca','#1d6344', `✅ ${asist} asist.`) +
        pill('#fff8ee','#f5d8a0','#7a4f10', `⏰ ${tardes} tarde${tardes!==1?'s':''}`) +
        pill('#fff0f0','#fbd5d5','#8b2020', `❌ ${faltas} falta${faltas!==1?'s':''}`) +
        (sinReg > 0 ? pill('var(--gray-100)','var(--gray-300)','var(--gray-500)', `— ${sinReg} sin reg.`) : '') +
        (_totalSes > 0 ? pill(pctC+'22', pctC+'44', pctC, `${pct}% · ${pctLbl}`) : '');

    const grid    = document.getElementById('modal-grid');
    const emptyEl = document.getElementById('modal-empty');

    if (sesKeys.length === 0) {
        grid.innerHTML = '';
        emptyEl.style.display = '';
    } else {
        emptyEl.style.display = 'none';
        const dias = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];

        grid.innerHTML = sesKeys.map(f => {
            const d  = detalle[f];
            const dt = new Date(f + 'T12:00:00');
            const dayLbl  = dias[dt.getDay()];
            const parts   = f.split('-');
            const dateLbl = `${parts[2]}/${parts[1]}/${parts[0].slice(2)}`;

            if (!d) {
                return `<div class="asis-row sin-reg">
                    <span class="asis-dot" style="background:#c5cad8"></span>
                    <span class="asis-fecha">${dayLbl} ${dateLbl}</span>
                    <span class="asis-hora">—</span>
                    <span class="asis-lbl" style="color:var(--gray-500)">Sin registro</span>
                </div>`;
            }

            const cfg = {
                asistio: ['#2e9e6e', 'Asistió'],
                tarde:   ['#d47a20', 'Tarde'],
                falta:   ['#d94f4f', 'Falta'],
            };
            const [c, lbl] = cfg[d.estado] || ['#7a8099', '—'];
            const horario  = d.entrada ? (d.entrada + (d.salida ? ' – ' + d.salida : '')) : '';

            return `<div class="asis-row ${d.estado}">
                <span class="asis-dot" style="background:${c}"></span>
                <span class="asis-fecha">${dayLbl} ${dateLbl}</span>
                <span class="asis-hora">${horario}</span>
                <span class="asis-lbl" style="color:${c}">${lbl}</span>
            </div>`;
        }).join('');
    }

    document.getElementById('modal-ov').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function cerrarModal() {
    document.getElementById('modal-ov').classList.remove('open');
    document.body.style.overflow = '';
}

document.getElementById('modal-ov').addEventListener('click', e => {
    if (e.target === document.getElementById('modal-ov')) cerrarModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });
</script>

</body>
</html>
