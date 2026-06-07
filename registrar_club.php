<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/enviar_correo.php';

// ── Proteger ruta ────────────────────────────────────────────────
if (empty($_SESSION['encargado_id'])) {
    header('Location: login_encargado.php');
    exit;
}

// ── Datos del encargado desde sesión ────────────────────────────
$enc = [
    'id'         => $_SESSION['encargado_id'],
    'nombre'     => $_SESSION['encargado_nombre']    ?? 'Encargado',
    'tipo'       => $_SESSION['encargado_tipo']      ?? '',
    'plantel'    => $_SESSION['encargado_plantel']   ?? '',
    'id_plantel' => $_SESSION['encargado_id_plantel'] ?? null,
];

// Iniciales para el avatar
$partes   = explode(' ', $enc['nombre']);
$iniciales = '';
foreach (array_slice($partes, 0, 2) as $p) $iniciales .= mb_substr($p, 0, 1);

// ── Logout ───────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login_encargado.php');
    exit;
}

// ── Procesar el formulario ───────────────────────────────────────
$errores = [];
$msg_ok  = '';
$post    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post = $_POST;

    $nombre               = trim($_POST['nombre']               ?? '');
    $descripcion          = trim($_POST['descripcion']          ?? '');
    $fecha_inicio         = trim($_POST['fecha_inicio']         ?? '');
    $fecha_fin            = trim($_POST['fecha_fin']            ?? '');
    $fecha_limite_registro= trim($_POST['fecha_limite_registro']?? '');
    $limite               = (int)($_POST['limite']             ?? 0);
    $anio                 = (int)($_POST['anio']               ?? date('Y'));
    $semestre             = trim($_POST['semestre']            ?? '');

    $dias         = $_POST['dia']         ?? [];
    $horas_inicio = $_POST['hora_inicio'] ?? [];
    $horas_fin    = $_POST['hora_fin']    ?? [];

    // — Validaciones —
    if (!$nombre || mb_strlen($nombre) > 50) {
        $errores[] = 'El nombre del club es obligatorio (máx. 50 caracteres).';
    }
    if (!$descripcion || mb_strlen($descripcion) > 150) {
        $errores[] = 'La descripción es obligatoria (máx. 150 caracteres).';
    }
    if (!$fecha_inicio) {
        $errores[] = 'La fecha de inicio es obligatoria.';
    }
    if (!$fecha_fin) {
        $errores[] = 'La fecha de fin es obligatoria.';
    }
    if ($fecha_inicio && $fecha_fin && $fecha_fin <= $fecha_inicio) {
        $errores[] = 'La fecha de fin debe ser posterior a la de inicio.';
    }
    if ($fecha_limite_registro) {
        if ($fecha_limite_registro < $fecha_inicio) {
            $errores[] = 'La fecha límite de registro no puede ser anterior a la de inicio.';
        }
        if ($fecha_limite_registro > $fecha_fin) {
            $errores[] = 'La fecha límite de registro no puede ser posterior a la de fin.';
        }
    }
    if ($limite < 5 || $limite > 127) {
        $errores[] = 'El límite de integrantes debe estar entre 5 y 127.';
    }
    if (!in_array($semestre, ['par', 'impar'])) {
        $errores[] = 'Selecciona si el club es para semestre par o impar.';
    }
    if (!$enc['id_plantel']) {
        $errores[] = 'No se pudo determinar tu plantel. Cierra sesión y vuelve a entrar.';
    }

    // — Validar horarios —
    if (empty($dias)) {
        $errores[] = 'Agrega al menos un horario.';
    } else {
        foreach ($dias as $i => $dia) {
            $hi = $horas_inicio[$i] ?? '';
            $hf = $horas_fin[$i]    ?? '';
            if (!$dia) {
                $errores[] = 'Selecciona el día en el horario ' . ($i + 1) . '.';
            }
            if (!$hi) {
                $errores[] = 'Falta la hora de inicio en el horario ' . ($i + 1) . '.';
            }
            if (!$hf) {
                $errores[] = 'Falta la hora de fin en el horario ' . ($i + 1) . '.';
            }
            if ($hi && $hf && $hf <= $hi) {
                $errores[] = 'La hora de fin debe ser posterior a la de inicio (horario ' . ($i + 1) . ').';
            }
        }
    }

    // — Guardar en BD —
    if (empty($errores)) {
        try {
            $pdo = getDB();
            $pdo->beginTransaction();

            // 1. Insertar club
            $insClub = $pdo->prepare("
                INSERT INTO clubes
                    (nombre, descripcion, fecha_inicio, fecha_fin,
                     fecha_limite_registro, limite, anio, semestre,
                     estado, id_plantel, id_encargado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'borrador', ?, ?)
            ");
            $insClub->execute([
                $nombre,
                $descripcion,
                $fecha_inicio,
                $fecha_fin,
                $fecha_limite_registro ?: null,
                $limite,
                $anio,
                $semestre,
                (int)$enc['id_plantel'],
                (int)$enc['id'],
            ]);
            $id_club = (int)$pdo->lastInsertId();

            // 2. Insertar horarios
            $insHorario = $pdo->prepare("
                INSERT INTO horarios (dia, hora_inicio, hora_fin, id_club)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($dias as $i => $dia) {
                $insHorario->execute([
                    $dia,
                    $horas_inicio[$i],
                    $horas_fin[$i],
                    $id_club,
                ]);
            }

            $pdo->commit();

            // Notificar al plantel por correo
            try {
                $spPlantel = $pdo->prepare('SELECT nombre, correo, contrasena_app FROM planteles WHERE id = ? LIMIT 1');
                $spPlantel->execute([(int)$enc['id_plantel']]);
                $plantel = $spPlantel->fetch();

                if ($plantel && !empty($plantel['correo'])) {
                    $meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
                    $fmt = fn(string $d) => (function() use ($d, $meses_es) {
                        [$y,$m,$day] = explode('-', $d);
                        return "$day de {$meses_es[(int)$m]} de $y";
                    })();

                    $horarios_lineas = [];
                    foreach ($dias as $i => $dia) {
                        $horarios_lineas[] = htmlspecialchars($dia) . ' ' . htmlspecialchars($horas_inicio[$i]) . '–' . htmlspecialchars($horas_fin[$i]);
                    }

                    $smtp_plantel = ['correo' => $plantel['correo'], 'contrasena_app' => $plantel['contrasena_app'] ?? ''];
                    enviarCorreoNuevoClub([
                        'correo_plantel'   => $plantel['correo'],
                        'nombre_plantel'   => $plantel['nombre'],
                        'nombre_encargado' => $enc['nombre'],
                        'num_trabajador'   => $enc['id'],
                        'nombre_club'      => $nombre,
                        'descripcion'      => $descripcion,
                        'semestre'         => ucfirst($semestre),
                        'fecha_inicio'     => $fmt($fecha_inicio),
                        'fecha_fin'        => $fmt($fecha_fin),
                        'horarios_html'    => implode('<br>', $horarios_lineas),
                        'limite'           => $limite,
                        'link_dashboard'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
                                             . '://' . $_SERVER['HTTP_HOST']
                                             . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')
                                             . '/dashboard_plantel.php',
                    ], $smtp_plantel);
                }
            } catch (Exception $email) {
                error_log('registrar_club notif email: ' . $email->getMessage());
            }

            $msg_ok = '¡Club <strong>' . htmlspecialchars($nombre) . '</strong> guardado como <strong>Borrador</strong> (ID #' . $id_club . '). '
                    . 'Edítalo cuando quieras y publícalo cuando esté listo para abrir inscripciones.';
            $post = []; // Limpiar el formulario

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errores[] = 'Error al guardar en la base de datos. Intenta de nuevo.';
            error_log('registrar_club error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Club — Bachillerato 23</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:       #1b2d54;
            --navy-light: #243567;
            --accent:     #4a7fd4;
            --accent-h:   #3568bf;
            --success:    #2e9e6e;
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
        body { font-family: 'DM Sans', sans-serif; background: var(--gray-50); min-height: 100vh; color: var(--text); }

        /* ── HEADER ──────────────────────────────────── */
        header {
            background: var(--navy); height: 64px;
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,.25);
            position: sticky; top: 0; z-index: 50;
        }
        .hb { display: flex; align-items: center; gap: .75rem; }
        .hb-logo { width: 40px; height: 40px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: .75rem; color: var(--navy); }
        .hb-name { font-family: 'Outfit', sans-serif; font-size: 1.05rem; font-weight: 600; color: #fff; }
        .hb-sub  { font-size: .7rem; color: rgba(255,255,255,.5); text-transform: uppercase; letter-spacing: .8px; }

        nav { display: flex; align-items: center; gap: .2rem; }
        nav a { color: rgba(255,255,255,.75); text-decoration: none; font-size: .82rem; font-weight: 500; padding: .4rem .75rem; border-radius: var(--radius-sm); display: flex; align-items: center; gap: .35rem; transition: all .2s; }
        nav a:hover, nav a.active { color: #fff; background: rgba(255,255,255,.12); }
        .nav-out { color: rgba(255,255,255,.65)!important; border: 1px solid rgba(255,255,255,.2)!important; margin-left: .5rem; }
        .nav-out:hover { background: rgba(255,255,255,.1)!important; }

        /* ── SUBHEADER ───────────────────────────────── */
        .subhdr { background: var(--navy-light); padding: .6rem 2rem; display: flex; align-items: center; gap: .75rem; }
        .sub-av { width: 34px; height: 34px; border-radius: 50%; background: var(--accent); display: flex; align-items: center; justify-content: center; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: .75rem; color: #fff; }
        .sub-name { font-family: 'Outfit', sans-serif; font-weight: 600; font-size: .9rem; color: #fff; }
        .sub-det  { font-size: .72rem; color: rgba(255,255,255,.6); }
        .sub-badge { margin-left: auto; background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.25); border-radius: 20px; padding: .2rem .75rem; font-size: .72rem; color: rgba(255,255,255,.85); font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: .35rem; }

        /* ── PAGE ────────────────────────────────────── */
        .page { max-width: 860px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }

        .page-title { display: flex; align-items: center; gap: .75rem; margin-bottom: 1.75rem; }
        .page-title-icon { width: 42px; height: 42px; border-radius: 10px; background: var(--accent); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .page-title h1 { font-family: 'Outfit', sans-serif; font-size: 1.4rem; font-weight: 700; color: var(--navy); }
        .page-title p  { font-size: .83rem; color: var(--gray-500); margin-top: .15rem; }

        /* ── CARD ────────────────────────────────────── */
        .card { background: var(--white); border-radius: var(--radius); box-shadow: var(--shadow-lg); overflow: hidden; margin-bottom: 1.5rem; }

        .card-top { background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%); padding: 1.25rem 1.75rem; position: relative; overflow: hidden; }
        .card-top::after { content: ''; position: absolute; right: -20px; bottom: -40px; width: 140px; height: 140px; border-radius: 50%; background: rgba(255,255,255,.05); }
        .card-top h2 { font-family: 'Outfit', sans-serif; font-size: 1rem; font-weight: 700; color: #fff; display: flex; align-items: center; gap: .5rem; position: relative; z-index: 1; }
        .card-top p  { font-size: .78rem; color: rgba(255,255,255,.6); margin-top: .2rem; position: relative; z-index: 1; }

        .card-body { padding: 1.5rem 1.75rem 1.75rem; }

        /* ── FORM GRID ───────────────────────────────── */
        .form-grid       { display: grid; grid-template-columns: 1fr 1fr;    gap: 1rem 1.25rem; }
        .form-grid.cols3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem 1.25rem; }
        .full { grid-column: 1 / -1; }

        .fg { /* field group */ }

        label {
            display: block; font-size: .73rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: .4px;
            color: var(--gray-700); margin-bottom: .4rem;
        }
        label .req { color: var(--error); margin-left: 2px; }

        .iw { position: relative; }
        .iw .icon { position: absolute; left: .8rem; top: 50%; transform: translateY(-50%); color: var(--gray-300); pointer-events: none; transition: color .2s; }
        .iw:focus-within .icon { color: var(--accent); }

        input[type="text"],
        input[type="date"],
        input[type="time"],
        input[type="number"],
        select,
        textarea {
            width: 100%; height: 44px;
            padding: 0 .9rem 0 2.5rem;
            border: 1.5px solid var(--gray-100);
            border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif;
            font-size: .9rem; color: var(--text);
            background: var(--gray-50);
            transition: border-color .2s, box-shadow .2s, background .2s;
            outline: none; appearance: none; -webkit-appearance: none;
        }
        textarea {
            height: auto; padding: .7rem .9rem .7rem 2.5rem;
            resize: vertical; min-height: 82px; line-height: 1.5;
        }
        input:focus, select:focus, textarea:focus {
            border-color: var(--accent);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(74,127,212,.12);
        }

        /* Flecha select */
        .sw::after { content: ''; position: absolute; right: .85rem; top: 50%; transform: translateY(-50%); width: 0; height: 0; border-left: 5px solid transparent; border-right: 5px solid transparent; border-top: 6px solid var(--gray-300); pointer-events: none; transition: border-color .2s; }
        .sw:focus-within::after { border-top-color: var(--accent); }

        .hint { font-size: .72rem; color: var(--gray-500); margin-top: .3rem; }
        .char-counter { font-size: .72rem; color: var(--gray-500); margin-top: .3rem; text-align: right; }
        .char-counter.near { color: #d47a20; }
        .char-counter.over { color: var(--error); font-weight: 600; }

        /* ── HORARIOS ────────────────────────────────── */
        .horarios-list { display: flex; flex-direction: column; gap: .85rem; }

        .horario-row {
            background: var(--gray-50);
            border: 1.5px solid var(--gray-100);
            border-radius: var(--radius-sm);
            padding: 1.1rem 1.1rem .9rem;
            display: grid;
            grid-template-columns: 1.4fr 1fr 1fr auto;
            gap: .75rem;
            align-items: end;
            position: relative;
            animation: fadeUp .25s ease both;
        }
        .horario-row:hover { border-color: var(--gray-200); }

        .horario-num {
            position: absolute; top: -10px; left: 12px;
            background: var(--accent); color: #fff;
            border-radius: 20px; padding: .1rem .6rem;
            font-size: .68rem; font-weight: 700; font-family: 'Outfit', sans-serif;
        }

        .btn-remove {
            width: 38px; height: 38px;
            background: none; border: 1.5px solid #fbd5d5;
            border-radius: var(--radius-sm);
            color: var(--error); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all .2s; align-self: flex-end;
        }
        .btn-remove:hover { background: #fff5f5; border-color: var(--error); }
        .btn-remove:disabled { opacity: .3; cursor: not-allowed; }

        .btn-add-horario {
            display: flex; align-items: center; gap: .5rem;
            padding: .65rem 1.1rem;
            background: none; border: 1.5px dashed var(--accent);
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif; font-size: .82rem; font-weight: 600;
            color: var(--accent); cursor: pointer;
            width: 100%; justify-content: center;
            transition: all .2s; margin-top: .6rem;
        }
        .btn-add-horario:hover { background: #f0f5ff; border-style: solid; }

        /* ── FOOTER DEL FORM ─────────────────────────── */
        .form-footer {
            display: flex; align-items: center; justify-content: flex-end; gap: .75rem;
            margin-top: 1.75rem; padding-top: 1.25rem;
            border-top: 1px solid var(--gray-100);
        }

        .btn-cancel {
            height: 46px; padding: 0 1.4rem;
            background: none; border: 1.5px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif; font-size: .9rem; font-weight: 600;
            color: var(--gray-700); cursor: pointer; text-decoration: none;
            display: flex; align-items: center; gap: .4rem;
            transition: all .2s;
        }
        .btn-cancel:hover { background: var(--gray-50); border-color: var(--gray-300); }

        .btn-submit {
            height: 46px; padding: 0 1.8rem;
            background: var(--accent); color: #fff;
            border: none; border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif; font-size: .9rem; font-weight: 700;
            cursor: pointer;
            display: flex; align-items: center; gap: .5rem;
            transition: all .2s;
            box-shadow: 0 3px 12px rgba(74,127,212,.25);
        }
        .btn-submit:hover { background: var(--accent-h); box-shadow: 0 6px 20px rgba(74,127,212,.35); transform: translateY(-1px); }
        .btn-submit:active { transform: translateY(0); }

        /* ── MISC ────────────────────────────────────── */
        @keyframes fadeUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        footer { text-align: center; padding: 1.5rem; font-size: .72rem; color: var(--gray-500); }

        @media (max-width: 640px) {
            .form-grid, .form-grid.cols3 { grid-template-columns: 1fr; }
            .full { grid-column: 1; }
            .horario-row { grid-template-columns: 1fr 1fr; }
            .horario-row .fg:first-child { grid-column: 1 / -1; }
            header { padding: 0 1rem; }
            nav a span { display: none; }
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
        <a href="registrar_club.php" class="active">
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

<!-- SUBHEADER -->
<div class="subhdr">
    <div class="sub-av"><?= htmlspecialchars($iniciales) ?></div>
    <div>
        <div class="sub-name"><?= htmlspecialchars($enc['nombre']) ?></div>
        <div class="sub-det"><?= htmlspecialchars($enc['tipo']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($enc['plantel']) ?></div>
    </div>
    <div class="sub-badge">
        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        <?= htmlspecialchars($enc['plantel']) ?>
    </div>
</div>

<div class="page">

    <!-- Título -->
    <div class="page-title">
        <div class="page-title-icon">
            <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        </div>
        <div>
            <h1>Registrar nuevo club</h1>
            <p>Completa la información del club y agrega los horarios de sesión</p>
        </div>
    </div>

    <?php if ($msg_ok): ?>
    <div style="background:#edfaf4;border:1px solid #a5dfca;border-left:3px solid #2e9e6e;border-radius:10px;padding:.9rem 1.1rem;font-size:.85rem;color:#1a5e3f;margin-bottom:1.5rem;display:flex;align-items:flex-start;gap:.6rem;line-height:1.55;animation:fadeUp .3s ease both">
        <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <div><?= $msg_ok ?> — <a href="mis_clubes.php" style="color:#1a5e3f;font-weight:600">Ver mis clubs →</a></div>
    </div>
    <?php endif; ?>

    <?php if ($errores): ?>
    <div style="background:#fff5f5;border:1px solid #fbd5d5;border-left:3px solid #d94f4f;border-radius:10px;padding:.9rem 1.1rem;font-size:.84rem;color:#8b2020;margin-bottom:1.5rem;display:flex;align-items:flex-start;gap:.6rem;line-height:1.55;animation:fadeUp .3s ease both">
        <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <ul style="padding-left:1rem;margin:0">
            <?php foreach ($errores as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" action="" id="form-club" novalidate>

        <!-- ── CARD 1: INFO GENERAL ──────────────────── -->
        <div class="card">
            <div class="card-top">
                <h2>
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Información general
                </h2>
                <p>Datos principales que verán los alumnos al inscribirse</p>
            </div>
            <div class="card-body">
                <div class="form-grid">

                    <div class="fg full">
                        <label for="nombre">Nombre del club <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <input type="text" id="nombre" name="nombre" placeholder="Ej. Club de Programación" maxlength="50" oninput="contador(this,'cnt-nombre',50)" value="<?= htmlspecialchars($post['nombre'] ?? '') ?>" required>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-top:.3rem">
                            <span class="hint">Máximo 50 caracteres</span>
                            <span class="char-counter" id="cnt-nombre">0/50</span>
                        </div>
                    </div>

                    <div class="fg full">
                        <label for="descripcion">Descripción <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="top:1.1rem;transform:none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            <textarea id="descripcion" name="descripcion" placeholder="Breve descripción, objetivos y actividades del club…" maxlength="150" oninput="contador(this,'cnt-desc',150)" required><?= htmlspecialchars($post['descripcion'] ?? '') ?></textarea>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-top:.3rem">
                            <span class="hint">Máximo 150 caracteres</span>
                            <span class="char-counter" id="cnt-desc">0/150</span>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── CARD 2: PERIODO Y CAPACIDAD ──────────── -->
        <div class="card">
            <div class="card-top">
                <h2>
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Periodo y capacidad
                </h2>
                <p>Duración del club en el ciclo escolar y número de lugares</p>
            </div>
            <div class="card-body">
                <div class="form-grid cols3">

                    <div class="fg">
                        <label for="fecha_inicio">Fecha de inicio <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <input type="date" id="fecha_inicio" name="fecha_inicio" onchange="validarFechas()" value="<?= htmlspecialchars($post['fecha_inicio'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="fg">
                        <label for="fecha_limite_registro">Fecha límite de registro</label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8" y2="14"/></svg>
                            <input type="date" id="fecha_limite_registro" name="fecha_limite_registro"
                                value="<?= htmlspecialchars($post['fecha_limite_registro'] ?? '') ?>">
                        </div>
                        <p class="hint">Último día en que los alumnos pueden inscribirse (estado Apertura)</p>
                    </div>

                    <div class="fg">
                        <label for="fecha_fin">Fecha de fin <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <input type="date" id="fecha_fin" name="fecha_fin" onchange="validarFechas()" value="<?= htmlspecialchars($post['fecha_fin'] ?? '') ?>" required>
                        </div>
                        <p class="hint" id="hint-fechas"></p>
                    </div>

                    <div class="fg">
                        <label for="limite">Límite de integrantes <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <input type="number" id="limite" name="limite" placeholder="Ej. 25" min="5" max="127" value="<?= htmlspecialchars($post['limite'] ?? '') ?>" required>
                        </div>
                        <p class="hint">Entre 5 y 127 estudiantes</p>
                    </div>

                    <div class="fg">
                        <label for="anio">Año <span class="req">*</span></label>
                        <div class="iw sw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <select id="anio" name="anio" required>
                                <option value="2026" selected>2026</option>
                                <option value="2027">2027</option>
                                <option value="2028">2028</option>
                            </select>
                        </div>
                    </div>

                    <div class="fg">
                        <label for="semestre">Semestres <span class="req">*</span></label>
                        <div class="iw sw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/></svg>
                            <select id="semestre" name="semestre" required>
                                <option value="">Selecciona…</option>
                                <option value="par"   <?= ($post['semestre'] ?? '') === 'par'   ? 'selected' : '' ?>>Semestres pares (2°, 4°, 6°)</option>
                                <option value="impar" <?= ($post['semestre'] ?? '') === 'impar' ? 'selected' : '' ?>>Semestres impares (1°, 3°, 5°)</option>
                            </select>
                        </div>
                        <p class="hint">Ciclo al que va dirigido el club</p>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── CARD 3: HORARIOS ──────────────────────── -->
        <div class="card">
            <div class="card-top">
                <h2>
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Horarios de sesión
                </h2>
                <p>Agrega los días y horas en que se reunirá el club — puedes tener varios</p>
            </div>
            <div class="card-body">

                <div class="horarios-list" id="horarios-list">

                    <!-- Horario 1 (siempre visible) -->
                    <div class="horario-row" id="hr-0">
                        <span class="horario-num">1</span>

                        <div class="fg">
                            <label>Día <span class="req">*</span></label>
                            <div class="iw sw">
                                <svg class="icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <select name="dia[]" required>
                                    <option value="">Selecciona…</option>
                                    <option>Lunes</option>
                                    <option>Martes</option>
                                    <option>Miércoles</option>
                                    <option>Jueves</option>
                                    <option>Viernes</option>
                                    <option>Sábado</option>
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

                        <button type="button" class="btn-remove" disabled title="Agrega otro horario para poder eliminar este">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4h6v2"/></svg>
                        </button>
                    </div>

                </div>

                <button type="button" class="btn-add-horario" onclick="addHorario()">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Agregar otro horario
                </button>

            </div>
        </div>

        <!-- FOOTER -->
        <div class="form-footer">
            <a href="dashboard_encargado.php" class="btn-cancel">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Cancelar
            </a>
            <button type="submit" class="btn-submit">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                Registrar club
            </button>
        </div>

    </form>

</div><!-- /page -->

<footer>© <?php echo date('Y'); ?> Universidad de Colima — Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>

<script>
// ── Contador de caracteres ────────────────────────
function contador(el, id, max) {
    const n = el.value.length;
    const s = document.getElementById(id);
    s.textContent = n + '/' + max;
    s.className   = 'char-counter' + (n >= max ? ' over' : n >= max * .85 ? ' near' : '');
}

// ── Validación visual de fechas ───────────────────
function validarFechas() {
    const fi = document.getElementById('fecha_inicio').value;
    const ff = document.getElementById('fecha_fin').value;
    const h  = document.getElementById('hint-fechas');
    if (!fi || !ff) return;
    const dias = Math.round((new Date(ff) - new Date(fi)) / 86400000);
    if (dias <= 0) {
        h.textContent = '⚠ La fecha fin debe ser posterior a la de inicio';
        h.style.color = 'var(--error)';
    } else {
        h.textContent = `✓ Duración: ${dias} días`;
        h.style.color = 'var(--success)';
    }
}

// ── Horarios dinámicos ────────────────────────────
let count = 1;

function addHorario() {
    const idx  = count++;
    const list = document.getElementById('horarios-list');
    const div  = document.createElement('div');
    div.className = 'horario-row';
    div.id = 'hr-' + idx;
    div.innerHTML = `
        <span class="horario-num">${idx + 1}</span>
        <div class="fg">
            <label>Día <span class="req">*</span></label>
            <div class="iw sw">
                <svg class="icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <select name="dia[]" required>
                    <option value="">Selecciona…</option>
                    <option>Lunes</option><option>Martes</option><option>Miércoles</option>
                    <option>Jueves</option><option>Viernes</option><option>Sábado</option>
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
        <button type="button" class="btn-remove" onclick="removeHorario('hr-${idx}')" title="Eliminar horario">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4h6v2"/></svg>
        </button>`;
    list.appendChild(div);
    actualizarBotones();
}

function removeHorario(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.transition = 'opacity .2s, transform .2s';
    el.style.opacity    = '0';
    el.style.transform  = 'translateY(-4px)';
    setTimeout(() => { el.remove(); actualizarBotones(); renumerar(); }, 200);
}

function actualizarBotones() {
    const rows = document.querySelectorAll('.horario-row');
    rows.forEach(row => {
        const btn = row.querySelector('.btn-remove');
        if (!btn) return;
        btn.disabled = rows.length === 1;
        btn.title    = rows.length === 1 ? 'Agrega otro horario para poder eliminar este' : 'Eliminar horario';
    });
}

function renumerar() {
    document.querySelectorAll('.horario-num').forEach((el, i) => el.textContent = i + 1);
}
</script>

</body>
</html>