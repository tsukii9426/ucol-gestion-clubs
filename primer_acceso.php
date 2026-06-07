<?php
session_start();

// Proteger: solo entra si viene del login con primer_acceso = true
if (empty($_SESSION['numero_cuenta']) || empty($_SESSION['primer_acceso'])) {
    header('Location: login.php');
    exit;
}

$numero_cuenta = $_SESSION['numero_cuenta'];
$paso  = $_SESSION['paso_registro'] ?? 1;
$error = '';
$exito = '';

// ── PASO 1: Cambiar contraseña temporal ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {

    if ($_POST['accion'] === 'cambiar_password') {
        $pass_nueva    = $_POST['pass_nueva']    ?? '';
        $pass_confirma = $_POST['pass_confirma'] ?? '';

        if (strlen($pass_nueva) < 8) {
            $error = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif ($pass_nueva !== $pass_confirma) {
            $error = 'Las contraseñas no coinciden.';
        } elseif (!preg_match('/[0-9]/', $pass_nueva)) {
            $error = 'La contraseña debe contener al menos un número.';
        } else {
            // TODO: guardar hash en BD → password_hash($pass_nueva, PASSWORD_DEFAULT)
            $_SESSION['paso_registro'] = 2;
            $paso = 2;
        }
    }

    elseif ($_POST['accion'] === 'guardar_datos') {
        $nombre    = trim($_POST['nombre']    ?? '');
        $plantel   = trim($_POST['plantel']   ?? '');
        $carrera   = trim($_POST['carrera']   ?? '');
        $semestre  = trim($_POST['semestre']  ?? '');
        $grupo     = trim($_POST['grupo']     ?? '');
        $curp      = strtoupper(trim($_POST['curp'] ?? ''));

        if (!$nombre || !$plantel || !$carrera || !$semestre || !$grupo) {
            $error = 'Por favor completa todos los campos obligatorios.';
        } else {
            // TODO: insertar en tabla alumnos de la BD
            $_SESSION['alumno'] = [
                'nombre'   => $nombre,
                'plantel'  => $plantel,
                'carrera'  => $carrera,
                'semestre' => $semestre,
                'grupo'    => $grupo,
                'curp'     => $curp,
                'cuenta'   => $numero_cuenta,
            ];
            $_SESSION['primer_acceso']  = false;
            $_SESSION['paso_registro']  = null;
            header('Location: dashboard_alumno.php');
            exit;
        }
    }
}

$paso = $_SESSION['paso_registro'] ?? $paso;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar cuenta — Clubes B23</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:        #1b2d54;
            --navy-light:  #243567;
            --accent:      #4a7fd4;
            --accent-hover:#3568bf;
            --success:     #2e9e6e;
            --white:       #ffffff;
            --gray-50:     #f7f8fc;
            --gray-100:    #eef0f6;
            --gray-300:    #c5cad8;
            --gray-500:    #7a8099;
            --gray-700:    #3d4260;
            --text:        #1e2340;
            --error:       #d94f4f;
            --radius:      12px;
            --radius-sm:   8px;
            --shadow-lg:   0 12px 48px rgba(27,45,84,.18);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--gray-50);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* HEADER */
        header {
            background: var(--navy);
            padding: 0 2rem;
            height: 64px;
            display: flex;
            align-items: center;
            gap: .75rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.25);
        }
        .header-logo {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--white);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-weight: 700; font-size: .75rem; color: var(--navy);
            flex-shrink: 0;
        }
        .header-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem; font-weight: 600; color: var(--white);
        }
        .header-sub {
            font-size: .7rem; color: rgba(255,255,255,.55);
            font-weight: 300; letter-spacing: .8px; text-transform: uppercase;
        }

        /* MAIN */
        main {
            flex: 1;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2.5rem 1rem 3rem;
        }

        .wrapper { width: 100%; max-width: 520px; }

        /* STEPPER */
        .stepper {
            display: flex;
            align-items: center;
            margin-bottom: 1.75rem;
            gap: 0;
        }
        .step {
            display: flex;
            align-items: center;
            gap: .5rem;
            flex: 1;
        }
        .step-circle {
            width: 32px; height: 32px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-size: .78rem; font-weight: 700;
            flex-shrink: 0;
            transition: all .3s;
        }
        .step.active   .step-circle { background: var(--accent);  color: #fff; }
        .step.done     .step-circle { background: var(--success); color: #fff; }
        .step.inactive .step-circle { background: var(--gray-100); color: var(--gray-500); border: 1.5px solid var(--gray-300); }

        .step-label {
            font-size: .75rem; font-weight: 500;
            white-space: nowrap;
        }
        .step.active   .step-label { color: var(--accent); }
        .step.done     .step-label { color: var(--success); }
        .step.inactive .step-label { color: var(--gray-500); }

        .step-line {
            flex: 1; height: 2px;
            background: var(--gray-100);
            margin: 0 .5rem;
            border-radius: 2px;
            overflow: hidden;
        }
        .step-line.done { background: var(--success); }

        /* CARD */
        .card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }
        .card-top {
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
            padding: 1.75rem 2rem 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .card-top::after {
            content: '';
            position: absolute; right: -20px; bottom: -50px;
            width: 160px; height: 160px;
            border-radius: 50%; background: rgba(255,255,255,.05);
        }
        .card-top h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.2rem; font-weight: 700; color: #fff; line-height: 1.3;
        }
        .card-top p { font-size: .82rem; color: rgba(255,255,255,.65); margin-top: .3rem; }

        .card-top .badge-cuenta {
            display: inline-flex; align-items: center; gap: .35rem;
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 20px;
            padding: .25rem .75rem;
            font-size: .75rem; color: rgba(255,255,255,.9);
            font-family: 'Outfit', sans-serif; font-weight: 500;
            margin-bottom: .75rem;
        }

        .card-body { padding: 1.75rem 2rem 2rem; }

        /* ALERTS */
        .alert-error {
            background: #fff5f5; border: 1px solid #fbd5d5;
            border-left: 3px solid var(--error);
            border-radius: var(--radius-sm);
            padding: .7rem 1rem; font-size: .82rem; color: #a33333;
            margin-bottom: 1.25rem;
            display: flex; align-items: center; gap: .5rem;
        }
        .alert-info {
            background: #f0f6ff; border: 1px solid #c8deff;
            border-left: 3px solid var(--accent);
            border-radius: var(--radius-sm);
            padding: .7rem 1rem; font-size: .82rem; color: #2a4a80;
            margin-bottom: 1.25rem;
        }

        /* FORM */
        .form-row { display: flex; gap: 1rem; }
        .form-row .form-group { flex: 1; }

        .form-group { margin-bottom: 1.1rem; }
        label {
            display: block; font-size: .78rem; font-weight: 500;
            color: var(--gray-700); margin-bottom: .4rem;
            letter-spacing: .3px; text-transform: uppercase;
        }
        label .req { color: var(--error); margin-left: 2px; }

        .input-wrap { position: relative; }
        .input-wrap .icon {
            position: absolute; left: .85rem; top: 50%;
            transform: translateY(-50%);
            color: var(--gray-300); pointer-events: none;
            transition: color .2s;
        }

        input[type="text"],
        input[type="password"],
        select {
            width: 100%; height: 46px;
            padding: 0 1rem 0 2.6rem;
            border: 1.5px solid var(--gray-100);
            border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif;
            font-size: .92rem; color: var(--text);
            background: var(--gray-50);
            transition: border-color .2s, box-shadow .2s, background .2s;
            outline: none;
            appearance: none;
        }
        select { cursor: pointer; }

        input:focus, select:focus {
            border-color: var(--accent);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(74,127,212,.12);
        }

        .hint { font-size: .74rem; color: var(--gray-500); margin-top: .3rem; }

        /* Password strength */
        .strength-bar {
            height: 3px; border-radius: 3px;
            background: var(--gray-100);
            margin-top: .5rem;
            overflow: hidden;
        }
        .strength-fill {
            height: 100%; width: 0;
            transition: width .3s, background .3s;
            border-radius: 3px;
        }
        .strength-text { font-size: .72rem; color: var(--gray-500); margin-top: .25rem; }

        /* Toggle pass */
        .toggle-pass {
            position: absolute; right: .85rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: var(--gray-300); padding: 0;
            display: flex; align-items: center;
            transition: color .2s;
        }
        .toggle-pass:hover { color: var(--accent); }

        /* Section divider */
        .section-title {
            font-family: 'Outfit', sans-serif;
            font-size: .7rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 1px;
            color: var(--gray-500);
            display: flex; align-items: center; gap: .5rem;
            margin: 1.25rem 0 1rem;
        }
        .section-title::after {
            content: ''; flex: 1; height: 1px; background: var(--gray-100);
        }

        /* Button */
        .btn-primary {
            width: 100%; height: 48px;
            background: var(--accent); color: var(--white);
            border: none; border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: .95rem; font-weight: 600; letter-spacing: .3px;
            cursor: pointer;
            transition: background .2s, transform .15s, box-shadow .2s;
            margin-top: .75rem;
            display: flex; align-items: center; justify-content: center; gap: .5rem;
        }
        .btn-primary:hover {
            background: var(--accent-hover);
            box-shadow: 0 6px 20px rgba(74,127,212,.3);
            transform: translateY(-1px);
        }

        /* Select arrow */
        .select-wrap { position: relative; }
        .select-wrap::after {
            content: '';
            position: absolute; right: .9rem; top: 50%;
            transform: translateY(-50%);
            width: 0; height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 6px solid var(--gray-300);
            pointer-events: none;
        }

        footer {
            text-align: center; padding: 1.25rem;
            font-size: .72rem; color: var(--gray-500);
        }
    </style>
</head>
<body>

<header>
    <div class="header-logo">UdeC</div>
    <div>
        <div class="header-name">Clubes Estudiantiles</div>
        <div class="header-sub">Bachillerato 23 &nbsp;·&nbsp; Universidad de Colima</div>
    </div>
</header>

<main>
<div class="wrapper">

    <!-- Stepper -->
    <div class="stepper">
        <div class="step <?= $paso >= 1 ? ($paso > 1 ? 'done' : 'active') : 'inactive' ?>">
            <div class="step-circle">
                <?= $paso > 1 ? '✓' : '1' ?>
            </div>
            <span class="step-label">Contraseña</span>
        </div>
        <div class="step-line <?= $paso > 1 ? 'done' : '' ?>"></div>
        <div class="step <?= $paso >= 2 ? 'active' : 'inactive' ?>">
            <div class="step-circle">2</div>
            <span class="step-label">Mis datos</span>
        </div>
    </div>

    <div class="card">

        <?php if ($paso === 1): ?>
        <!-- ══ PASO 1: NUEVA CONTRASEÑA ══════════════════ -->
        <div class="card-top">
            <div class="badge-cuenta">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                Cuenta: <?= htmlspecialchars($numero_cuenta) ?>
            </div>
            <h2>Bienvenido/a, alumno/a</h2>
            <p>Primer paso: elige una contraseña segura para tu cuenta</p>
        </div>
        <div class="card-body">

            <?php if ($error): ?>
            <div class="alert-error">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <div class="alert-info">
                🔑 Tu contraseña temporal fue proporcionada por tu encargado de club. Ahora debes crear una contraseña personal.
            </div>

            <form method="POST">
                <input type="hidden" name="accion" value="cambiar_password">

                <div class="form-group">
                    <label for="pass_nueva">Nueva contraseña <span class="req">*</span></label>
                    <div class="input-wrap">
                        <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" id="pass_nueva" name="pass_nueva"
                               placeholder="Mínimo 8 caracteres"
                               oninput="checkStrength(this.value)" required>
                        <button type="button" class="toggle-pass" onclick="toggleInput('pass_nueva','eye1')">
                            <svg id="eye1" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
                    <p class="strength-text" id="strength-text">Ingresa tu contraseña</p>
                </div>

                <div class="form-group">
                    <label for="pass_confirma">Confirmar contraseña <span class="req">*</span></label>
                    <div class="input-wrap">
                        <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" id="pass_confirma" name="pass_confirma"
                               placeholder="Repite tu nueva contraseña" required>
                        <button type="button" class="toggle-pass" onclick="toggleInput('pass_confirma','eye2')">
                            <svg id="eye2" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <p class="hint">Debe contener al menos 1 número y 8 caracteres</p>
                </div>

                <button type="submit" class="btn-primary">
                    Continuar
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </form>
        </div>

        <?php else: ?>
        <!-- ══ PASO 2: DATOS DEL ALUMNO ══════════════════ -->
        <div class="card-top">
            <div class="badge-cuenta">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                Cuenta: <?= htmlspecialchars($numero_cuenta) ?>
            </div>
            <h2>Completa tu perfil</h2>
            <p>Esta información se mostrará en tu ficha de alumno y en los registros de asistencia</p>
        </div>
        <div class="card-body">

            <?php if ($error): ?>
            <div class="alert-error">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="accion" value="guardar_datos">

                <!-- Nombre completo -->
                <div class="form-group">
                    <label for="nombre">Nombre completo <span class="req">*</span></label>
                    <div class="input-wrap">
                        <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" id="nombre" name="nombre"
                               placeholder="Apellido Paterno Materno Nombre(s)"
                               value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>"
                               style="text-transform:uppercase" required>
                    </div>
                    <p class="hint">Escribe como aparece en tu CURP (apellidos primero)</p>
                </div>

                <!-- CURP -->
                <div class="form-group">
                    <label for="curp">CURP</label>
                    <div class="input-wrap">
                        <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                        <input type="text" id="curp" name="curp"
                               placeholder="XXXX000000XXXXXXX0"
                               maxlength="18"
                               value="<?= htmlspecialchars($_POST['curp'] ?? '') ?>"
                               style="text-transform:uppercase;letter-spacing:.05em">
                    </div>
                </div>

                <div class="section-title">Datos escolares</div>

                <!-- Plantel -->
                <div class="form-group">
                    <label for="plantel">Plantel <span class="req">*</span></label>
                    <div class="input-wrap select-wrap">
                        <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        <select id="plantel" name="plantel" required>
                            <option value="">Selecciona tu plantel…</option>
                            <option value="BACHILLERATO 1"  <?= (($_POST['plantel'] ?? '') === 'BACHILLERATO 1')  ? 'selected' : '' ?>>Bachillerato 1</option>
                            <option value="BACHILLERATO 3"  <?= (($_POST['plantel'] ?? '') === 'BACHILLERATO 3')  ? 'selected' : '' ?>>Bachillerato 3</option>
                            <option value="BACHILLERATO 4"  <?= (($_POST['plantel'] ?? '') === 'BACHILLERATO 4')  ? 'selected' : '' ?>>Bachillerato 4</option>
                            <option value="BACHILLERATO 5"  <?= (($_POST['plantel'] ?? '') === 'BACHILLERATO 5')  ? 'selected' : '' ?>>Bachillerato 5</option>
                            <option value="BACHILLERATO 6"  <?= (($_POST['plantel'] ?? '') === 'BACHILLERATO 6')  ? 'selected' : '' ?>>Bachillerato 6</option>
                            <option value="BACHILLERATO 7"  <?= (($_POST['plantel'] ?? '') === 'BACHILLERATO 7')  ? 'selected' : '' ?>>Bachillerato 7</option>
                            <option value="BACHILLERATO 10" <?= (($_POST['plantel'] ?? '') === 'BACHILLERATO 10') ? 'selected' : '' ?>>Bachillerato 10</option>
                            <option value="BACHILLERATO 23" <?= (($_POST['plantel'] ?? '') === 'BACHILLERATO 23') ? 'selected' : '' ?>>Bachillerato 23 (Manzanillo)</option>
                        </select>
                    </div>
                </div>

                <!-- Carrera -->
                <div class="form-group">
                    <label for="carrera">Carrera técnica <span class="req">*</span></label>
                    <div class="input-wrap select-wrap">
                        <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                        <select id="carrera" name="carrera" required>
                            <option value="">Selecciona tu carrera…</option>
                            <option value="BACHILLERATO TECNICO ANALISTA PROGRAMADOR"    <?= (($_POST['carrera'] ?? '') === 'BACHILLERATO TECNICO ANALISTA PROGRAMADOR')    ? 'selected' : '' ?>>Técnico Analista Programador</option>
                            <option value="BACHILLERATO TECNICO ADMINISTRACION"          <?= (($_POST['carrera'] ?? '') === 'BACHILLERATO TECNICO ADMINISTRACION')          ? 'selected' : '' ?>>Técnico en Administración</option>
                            <option value="BACHILLERATO TECNICO CONTABILIDAD"            <?= (($_POST['carrera'] ?? '') === 'BACHILLERATO TECNICO CONTABILIDAD')            ? 'selected' : '' ?>>Técnico en Contabilidad</option>
                            <option value="BACHILLERATO TECNICO TURISMO"                 <?= (($_POST['carrera'] ?? '') === 'BACHILLERATO TECNICO TURISMO')                 ? 'selected' : '' ?>>Técnico en Turismo</option>
                            <option value="BACHILLERATO TECNICO ENFERMERIA GENERAL"      <?= (($_POST['carrera'] ?? '') === 'BACHILLERATO TECNICO ENFERMERIA GENERAL')      ? 'selected' : '' ?>>Técnico en Enfermería General</option>
                            <option value="BACHILLERATO TECNICO ELECTRICIDAD INDUSTRIAL" <?= (($_POST['carrera'] ?? '') === 'BACHILLERATO TECNICO ELECTRICIDAD INDUSTRIAL') ? 'selected' : '' ?>>Técnico en Electricidad Industrial</option>
                        </select>
                    </div>
                </div>

                <!-- Semestre y Grupo -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="semestre">Semestre <span class="req">*</span></label>
                        <div class="input-wrap select-wrap">
                            <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <select id="semestre" name="semestre" required>
                                <option value="">Sem…</option>
                                <?php for ($i = 1; $i <= 6; $i++): ?>
                                <option value="<?= $i ?>° Semestre" <?= (($_POST['semestre'] ?? '') === "$i° Semestre") ? 'selected' : '' ?>><?= $i ?>°</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="grupo">Grupo <span class="req">*</span></label>
                        <div class="input-wrap select-wrap">
                            <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <select id="grupo" name="grupo" required>
                                <option value="">Grp…</option>
                                <?php foreach (['A','B','C','D','E','F'] as $g): ?>
                                <option value="<?= $g ?>" <?= (($_POST['grupo'] ?? '') === $g) ? 'selected' : '' ?>><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-primary">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                    Guardar y entrar al sistema
                </button>
            </form>
        </div>
        <?php endif; ?>

    </div><!-- /card -->
</div><!-- /wrapper -->
</main>

<footer>© <?= date('Y') ?> Universidad de Colima — Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>

<script>
// ─ Mostrar/ocultar contraseña ────────────────────────
function toggleInput(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    const show = inp.type === 'password';
    inp.type   = show ? 'text' : 'password';
    icon.innerHTML = show
        ? `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`
        : `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
}

// ─ Fortaleza de contraseña ───────────────────────────
function checkStrength(val) {
    const fill = document.getElementById('strength-fill');
    const text = document.getElementById('strength-text');
    let score = 0;
    if (val.length >= 8)               score++;
    if (/[A-Z]/.test(val))             score++;
    if (/[0-9]/.test(val))             score++;
    if (/[^A-Za-z0-9]/.test(val))      score++;

    const levels = [
        { pct:'0%',   color:'#e0e0e0', label:'Ingresa tu contraseña' },
        { pct:'25%',  color:'#e05252', label:'Muy débil' },
        { pct:'50%',  color:'#e09a30', label:'Débil' },
        { pct:'75%',  color:'#4a7fd4', label:'Buena' },
        { pct:'100%', color:'#2e9e6e', label:'Excelente' },
    ];
    const lvl = val.length === 0 ? levels[0] : levels[score] || levels[1];
    fill.style.width      = lvl.pct;
    fill.style.background = lvl.color;
    text.textContent      = lvl.label;
}
</script>
</body>
</html>
