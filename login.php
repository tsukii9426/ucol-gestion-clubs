<?php
session_start();
require_once __DIR__ . '/db.php';

// Redirigir si ya tiene sesión activa
if (!empty($_SESSION['numero_cuenta'])) {
    header('Location: dashboard_alumno.php');
    exit;
}

// Cargar planteles para el select
$planteles_login = [];
try {
    $planteles_login = getDB()
        ->query('SELECT id, nombre FROM planteles ORDER BY nombre')
        ->fetchAll();
} catch (Exception $e) { /* fallback vacío */ }

$error    = '';
$flash_ok = '';

// Mensaje de éxito proveniente de reset_contrasena.php
if (!empty($_SESSION['flash_ok'])) {
    $flash_ok = $_SESSION['flash_ok'];
    unset($_SESSION['flash_ok']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numero_cuenta      = trim($_POST['numero_cuenta']      ?? '');
    $nombre             = trim($_POST['nombre']             ?? '');
    $correo             = trim($_POST['correo']             ?? '');
    $id_plantel         = (int)($_POST['id_plantel']        ?? 0);
    $contrasena         = $_POST['contrasena']              ?? '';
    $confirmar          = $_POST['confirmar_contrasena']    ?? '';
    $es_nuevo           = ($_POST['es_nuevo'] ?? '0') === '1';

    // Nombre del plantel seleccionado
    $plantel_nombre = '';
    foreach ($planteles_login as $pl) {
        if ((int)$pl['id'] === $id_plantel) { $plantel_nombre = $pl['nombre']; break; }
    }

    // ── Validaciones comunes ─────────────────────────────
    if (!$numero_cuenta || !ctype_digit($numero_cuenta)) {
        $error = 'El número de cuenta solo debe contener dígitos.';
    } elseif (!$nombre) {
        $error = 'El nombre completo es obligatorio.';
    } elseif (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingresa un correo electrónico válido.';
    } elseif (!$id_plantel) {
        $error = 'Selecciona tu plantel.';
    } elseif (!$contrasena) {
        $error = 'La contraseña es obligatoria.';
    } elseif ($es_nuevo && $contrasena !== $confirmar) {
        $error = 'Las contraseñas no coinciden. Vuelve a escribirlas.';
    } elseif ($es_nuevo && strlen($contrasena) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } else {
        try {
            $pdo = getDB();

            $stmt = $pdo->prepare(
                'SELECT e.id, e.nombre_completo, e.correo, e.contrasena,
                        e.id_plantel, e.id_club, p.nombre AS plantel_nombre
                 FROM estudiantes e
                 LEFT JOIN planteles p ON p.id = e.id_plantel
                 WHERE e.cuenta = ?'
            );
            $stmt->execute([(int)$numero_cuenta]);
            $existente = $stmt->fetch();

            if ($existente) {
                // ── Alumno existente: verificar contraseña ───────────
                if ($existente['contrasena'] && !password_verify($contrasena, $existente['contrasena'])) {
                    $error = 'Contraseña incorrecta. Inténtalo de nuevo.';
                } else {
                    $nuevo_hash = $existente['contrasena']
                        ? $existente['contrasena']
                        : password_hash($contrasena, PASSWORD_DEFAULT);

                    $nombre_upper = mb_strtoupper($nombre);
                    $pdo->prepare(
                        'UPDATE estudiantes
                         SET nombre_completo = ?, correo = ?, id_plantel = ?, contrasena = ?
                         WHERE cuenta = ?'
                    )->execute([
                        $nombre_upper, $correo,
                        $id_plantel ?: $existente['id_plantel'],
                        $nuevo_hash,
                        (int)$numero_cuenta,
                    ]);

                    $chkIns = $pdo->prepare(
                        'SELECT COUNT(*) FROM inscripciones_club WHERE numero_cuenta = ?'
                    );
                    $chkIns->execute([(int)$numero_cuenta]);

                    session_regenerate_id(true);
                    $_SESSION['numero_cuenta'] = $numero_cuenta;
                    $_SESSION['nombre']        = $nombre_upper;
                    $_SESSION['correo']        = $correo;
                    $_SESSION['plantel']       = $plantel_nombre ?: ($existente['plantel_nombre'] ?? '');
                    $_SESSION['id_plantel']    = $id_plantel ?: $existente['id_plantel'];
                    $_SESSION['ya_inscrito']   = (int)$chkIns->fetchColumn() > 0;
                    header('Location: dashboard_alumno.php');
                    exit;
                }
            } else {
                // ── Alumno nuevo: crear registro ─────────────────────
                $hash         = password_hash($contrasena, PASSWORD_DEFAULT);
                $nombre_upper = mb_strtoupper($nombre);
                $pdo->prepare(
                    'INSERT INTO estudiantes (cuenta, nombre_completo, correo, contrasena, id_plantel)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       nombre_completo = VALUES(nombre_completo),
                       correo          = VALUES(correo),
                       contrasena      = VALUES(contrasena),
                       id_plantel      = VALUES(id_plantel)'
                )->execute([(int)$numero_cuenta, $nombre_upper, $correo, $hash, $id_plantel ?: null]);

                session_regenerate_id(true);
                $_SESSION['numero_cuenta'] = $numero_cuenta;
                $_SESSION['nombre']        = $nombre_upper;
                $_SESSION['correo']        = $correo;
                $_SESSION['plantel']       = $plantel_nombre;
                $_SESSION['id_plantel']    = $id_plantel ?: null;
                $_SESSION['ya_inscrito']   = false;
                header('Location: dashboard_alumno.php');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Error de conexión. Intenta de nuevo.';
            error_log('login alumno error: ' . $e->getMessage());
        }
    }
}

// Planteles como JSON para JS
$planteles_json = json_encode(array_map(
    fn($p) => ['id' => (int)$p['id'], 'nombre' => $p['nombre']],
    $planteles_login
));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Alumnos — Bachillerato 23</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:        #1b2d54;
            --navy-light:  #243567;
            --navy-deep:   #111e3a;
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

        /* ── HEADER ─────────────────── */
        header {
            background: var(--navy);
            padding: 0 2rem;
            height: 64px;
            display: flex;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,.25);
        }
        .hb { display: flex; align-items: center; gap: .75rem; }
        .hb-logo { width:40px; height:40px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-family:'Outfit',sans-serif; font-weight:700; font-size:.75rem; color:var(--navy); }
        .hb-name { font-family:'Outfit',sans-serif; font-size:1.05rem; font-weight:600; color:#fff; }
        .hb-sub  { font-size:.7rem; color:rgba(255,255,255,.5); text-transform:uppercase; letter-spacing:.8px; }

        /* ── MAIN ───────────────────── */
        main { flex:1; display:flex; align-items:center; justify-content:center; padding:2.5rem 1rem; }

        .login-wrapper { width:100%; max-width:420px; }

        .login-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); overflow:hidden; }

        .card-top {
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
            padding: 2rem 2rem 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .card-top::after  { content:''; position:absolute; right:-30px; bottom:-40px; width:140px; height:140px; border-radius:50%; background:rgba(255,255,255,.06); }
        .card-top::before { content:''; position:absolute; right:40px;  bottom:-60px; width:200px; height:200px; border-radius:50%; background:rgba(255,255,255,.04); }

        .card-top-icon { width:52px; height:52px; background:rgba(255,255,255,.15); border:1.5px solid rgba(255,255,255,.25); border-radius:14px; display:flex; align-items:center; justify-content:center; margin-bottom:1rem; }
        .card-top-icon svg { color:#fff; }
        .card-top h1  { font-family:'Outfit',sans-serif; font-size:1.3rem; font-weight:700; color:#fff; line-height:1.3; }
        .card-top p   { margin-top:.35rem; font-size:.82rem; color:rgba(255,255,255,.65); font-weight:300; }

        .card-body { padding: 1.75rem 2rem 2rem; }

        /* ── ALERTA ─────────────────── */
        .alert { border-radius:var(--radius-sm); padding:.75rem 1rem; font-size:.82rem; display:flex; align-items:flex-start; gap:.5rem; margin-bottom:1.25rem; line-height:1.5; }
        .alert svg { flex-shrink:0; margin-top:1px; }
        .alert-err  { background:#fff5f5; border:1px solid #fbd5d5; border-left:3px solid var(--error);   color:#a33333; }
        .alert-info { background:#f0f6ff; border:1px solid #c8deff; border-left:3px solid var(--accent); color:#2a4a80; }
        .alert-ok   { background:#edfaf4; border:1px solid #a5dfca; border-left:3px solid var(--success); color:#1a5e3f; }

        /* ── FORM ───────────────────── */
        .form-group { margin-bottom:1.1rem; }

        label {
            display:block; font-size:.78rem; font-weight:500;
            color:var(--gray-700); margin-bottom:.45rem;
            letter-spacing:.3px; text-transform:uppercase;
        }
        .req { color:var(--error); }

        .input-wrap { position:relative; }
        .field-icon { position:absolute; left:.85rem; top:50%; transform:translateY(-50%); color:var(--gray-300); pointer-events:none; transition:color .2s; z-index:1; }
        .input-wrap:focus-within .field-icon { color:var(--accent); }

        input[type="text"],
        input[type="password"],
        input[type="email"],
        select {
            width:100%; height:46px;
            padding:0 2.8rem 0 2.6rem;
            border:1.5px solid var(--gray-100);
            border-radius:var(--radius-sm);
            font-family:'DM Sans',sans-serif; font-size:.92rem; color:var(--text);
            background:var(--gray-50);
            transition:border-color .2s, box-shadow .2s, background .2s;
            outline:none; appearance:none; -webkit-appearance:none;
        }
        input:focus, select:focus {
            border-color:var(--accent);
            background:var(--white);
            box-shadow:0 0 0 3px rgba(74,127,212,.12);
        }
        input:disabled, select:disabled {
            opacity:.7;
            cursor:not-allowed;
            background:var(--gray-100);
        }
        .select-wrap::after { content:''; position:absolute; right:.9rem; top:50%; transform:translateY(-50%); width:0; height:0; border-left:5px solid transparent; border-right:5px solid transparent; border-top:6px solid var(--gray-300); pointer-events:none; transition:border-color .2s; }
        .select-wrap:focus-within::after { border-top-color:var(--accent); }

        .btn-eye { position:absolute; right:.75rem; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:var(--gray-300); padding:.2rem; line-height:0; transition:color .2s; }
        .btn-eye:hover { color:var(--accent); }

        .form-hint { font-size:.74rem; color:var(--gray-500); margin-top:.35rem; }

        /* ── BOTÓN PRINCIPAL ────────── */
        .btn-primary {
            width:100%; height:48px;
            background:var(--accent); color:var(--white);
            border:none; border-radius:var(--radius-sm);
            font-family:'Outfit',sans-serif; font-size:.95rem; font-weight:600;
            cursor:pointer;
            transition:background .2s, transform .15s, box-shadow .2s;
            margin-top:.5rem;
            display:flex; align-items:center; justify-content:center; gap:.5rem;
        }
        .btn-primary:hover  { background:var(--accent-h); box-shadow:0 6px 20px rgba(74,127,212,.3); transform:translateY(-1px); }
        .btn-primary:active { transform:translateY(0); }
        .btn-primary:disabled { opacity:.65; cursor:not-allowed; transform:none; box-shadow:none; }

        /* ── SPINNER ────────────────── */
        .spinner { width:16px; height:16px; border:2.5px solid rgba(255,255,255,.4); border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite; }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* ── SECCIÓN COLAPSABLE ─────── */
        .fields-block { overflow:hidden; transition:max-height .4s cubic-bezier(.4,0,.2,1), opacity .3s ease; max-height:0; opacity:0; }
        .fields-block.open { max-height:1200px; opacity:1; }

        /* ── CHIP "datos cargados" ───── */
        .loaded-chip {
            display:flex; align-items:center; gap:.5rem;
            background:#edfaf4; border:1px solid #a5dfca; border-radius:var(--radius-sm);
            padding:.55rem .9rem; font-size:.8rem; color:#1a5e3f;
            margin-bottom:1rem;
        }
        .loaded-chip button {
            margin-left:auto; background:none; border:none;
            font-size:.75rem; color:var(--accent); cursor:pointer;
            font-family:'Outfit',sans-serif; font-weight:600; padding:0;
        }
        .loaded-chip button:hover { text-decoration:underline; }

        /* ── RECUPERAR CONTRASEÑA ───── */
        .recovery-link {
            display:block; text-align:center;
            margin-top:1rem; font-size:.8rem; color:var(--gray-500);
        }
        .recovery-link a { color:var(--accent); font-weight:500; text-decoration:none; }
        .recovery-link a:hover { text-decoration:underline; }

        /* ── DIVIDER ────────────────── */
        hr.div { border:none; border-top:1px solid var(--gray-100); margin:1.4rem 0 1.2rem; }

        .help-text { text-align:center; font-size:.8rem; color:var(--gray-500); }
        .help-text a { color:var(--accent); font-weight:500; text-decoration:none; }
        .help-text a:hover { text-decoration:underline; }

        /* ── FOOTER ─────────────────── */
        footer { text-align:center; padding:1.25rem; font-size:.72rem; color:var(--gray-500); }

        @keyframes fadeDown { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
        .anim-fade { animation:fadeDown .3s ease both; }
    </style>
</head>
<body>

<header>
    <div class="hb">
        <div class="hb-logo">UdeC</div>
        <div>
            <div class="hb-name">Clubes Estudiantiles</div>
            <div class="hb-sub">Bachillerato 23 · Universidad de Colima</div>
        </div>
    </div>
</header>

<main>
    <div class="login-wrapper">
        <div class="login-card">

            <div class="card-top">
                <div class="card-top-icon">
                    <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <h1>Portal del Alumno</h1>
                <p>Gestión de Clubes &amp; Asistencias</p>
            </div>

            <div class="card-body">

                <?php if ($flash_ok): ?>
                <div class="alert alert-ok anim-fade">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <span><?= htmlspecialchars($flash_ok) ?></span>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-err anim-fade" id="server-error">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="form-login" novalidate>
                    <input type="hidden" name="es_nuevo" id="es_nuevo" value="0">

                    <!-- ── PASO 1: NÚMERO DE CUENTA ────────── -->
                    <div class="form-group" id="group-cuenta">
                        <label for="numero_cuenta">Número de cuenta <span class="req">*</span></label>
                        <div class="input-wrap">
                            <svg class="field-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                            <input type="text" id="numero_cuenta" name="numero_cuenta"
                                placeholder="Ej. 20231113"
                                maxlength="10" inputmode="numeric"
                                value="<?= htmlspecialchars($_POST['numero_cuenta'] ?? '') ?>"
                                autocomplete="username" required>
                        </div>
                        <p class="form-hint">Tu número de cuenta de la Universidad de Colima</p>
                    </div>

                    <!-- Botón "Continuar" — visible solo en fase 1 -->
                    <div id="btn-buscar-wrap">
                        <button type="button" class="btn-primary" id="btn-buscar" onclick="buscarCuenta()">
                            <svg id="buscar-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                            <span id="buscar-label">Continuar</span>
                        </button>
                    </div>

                    <!-- ── PASO 2: CAMPOS DINÁMICOS ────────── -->
                    <div class="fields-block" id="fields-block">

                        <!-- Chip de datos cargados (alumno existente) -->
                        <div id="loaded-chip" class="loaded-chip" style="display:none">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            <span>Datos cargados · <span style="font-weight:400">puedes editarlos si necesitas corregirlos</span></span>
                        </div>

                        <!-- Aviso cuenta nueva -->
                        <div id="nuevo-chip" class="alert alert-info" style="display:none">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span>Primera vez en el sistema. Completa tus datos y crea una contraseña.</span>
                        </div>

                        <!-- Nombre -->
                        <div class="form-group">
                            <label for="nombre">Nombre completo <span class="req">*</span></label>
                            <div class="input-wrap">
                                <svg class="field-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <input type="text" id="nombre" name="nombre"
                                    placeholder="Ej. Juan Pérez García"
                                    value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>"
                                    autocomplete="name">
                            </div>
                        </div>

                        <!-- Correo -->
                        <div class="form-group">
                            <label for="correo">Correo electrónico <span class="req">*</span></label>
                            <div class="input-wrap">
                                <svg class="field-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                <input type="email" id="correo" name="correo"
                                    placeholder="Ej. juan.perez@ucol.edu.mx"
                                    value="<?= htmlspecialchars($_POST['correo'] ?? '') ?>"
                                    autocomplete="email">
                            </div>
                        </div>

                        <!-- Plantel -->
                        <div class="form-group">
                            <label for="id_plantel">Plantel <span class="req">*</span></label>
                            <div class="input-wrap select-wrap">
                                <svg class="field-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                                <select id="id_plantel" name="id_plantel">
                                    <option value="">Selecciona tu plantel…</option>
                                    <?php foreach ($planteles_login as $pl): ?>
                                    <option value="<?= $pl['id'] ?>"
                                        <?= ((int)($_POST['id_plantel'] ?? 0) === (int)$pl['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($pl['nombre']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Contraseña -->
                        <div class="form-group">
                            <label for="contrasena" id="lbl-pass">Contraseña <span class="req">*</span></label>
                            <div class="input-wrap">
                                <svg class="field-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <input type="password" id="contrasena" name="contrasena"
                                    placeholder="Tu contraseña de acceso"
                                    autocomplete="current-password">
                                <button type="button" class="btn-eye" tabindex="-1"
                                    onclick="togglePass('contrasena', this)">
                                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                            <p class="form-hint" id="hint-pass">Ingresa tu contraseña de acceso</p>
                        </div>

                        <!-- Confirmar contraseña — solo para cuenta nueva -->
                        <div class="form-group" id="group-confirmar" style="display:none">
                            <label for="confirmar_contrasena">Confirmar contraseña <span class="req">*</span></label>
                            <div class="input-wrap">
                                <svg class="field-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <input type="password" id="confirmar_contrasena" name="confirmar_contrasena"
                                    placeholder="Repite tu contraseña"
                                    autocomplete="new-password">
                                <button type="button" class="btn-eye" tabindex="-1"
                                    onclick="togglePass('confirmar_contrasena', this)">
                                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                            <p class="form-hint">Mínimo 6 caracteres</p>
                        </div>

                        <!-- Botón enviar -->
                        <button type="submit" class="btn-primary" id="btn-submit">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                            <span id="submit-label">Acceder</span>
                        </button>

                        <!-- Recuperar contraseña -->
                        <p class="recovery-link" id="recovery-wrap" style="display:none">
                            <a href="recuperar_contrasena.php">¿Olvidaste tu contraseña?</a>
                        </p>

                    </div><!-- /fields-block -->

                </form>

                <hr class="div">
                <p class="help-text">
                    ¿Eres encargado?
                    <a href="login_encargado.php">Ingresa aquí</a>
                </p>

            </div>
        </div>
    </div>
</main>

<footer>© <?= date('Y') ?> Universidad de Colima — Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>

<script>
const PLANTELES  = <?= $planteles_json ?>;
const SERVER_ERR = <?= $error ? 'true' : 'false' ?>;
const POST_CUENTA = <?= json_encode($_POST['numero_cuenta'] ?? '') ?>;

// Si hubo error del servidor después de submit, mostrar la fase 2 directamente
if (SERVER_ERR && POST_CUENTA) {
    // Restaurar la fase 2 con los datos posteados
    document.getElementById('btn-buscar-wrap').style.display = 'none';
    const block = document.getElementById('fields-block');
    block.style.maxHeight = '1200px';
    block.style.opacity   = '1';
    block.classList.add('open');
    const esNuevo = <?= json_encode($_POST['es_nuevo'] ?? '0') ?> === '1';
    if (!esNuevo) {
        document.getElementById('recovery-wrap').style.display = 'block';
    } else {
        document.getElementById('group-confirmar').style.display = 'block';
        document.getElementById('nuevo-chip').style.display = 'flex';
        document.getElementById('lbl-pass').innerHTML = 'Contraseña nueva <span class="req">*</span>';
    }
}

// ── Detectar Enter en el campo de cuenta ────────────────────────
document.getElementById('numero_cuenta').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); buscarCuenta(); }
});

// ── Buscar cuenta (fase 1 → fase 2) ─────────────────────────────
function buscarCuenta() {
    const cuenta = document.getElementById('numero_cuenta').value.trim();

    if (!cuenta || !/^\d+$/.test(cuenta)) {
        mostrarAlerta('Ingresa un número de cuenta válido (solo dígitos).', 'err');
        document.getElementById('numero_cuenta').focus();
        return;
    }

    // Estado de carga
    setBuscarLoading(true);
    limpiarAlerta();

    fetch('buscar_cuenta.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'cuenta=' + encodeURIComponent(cuenta)
    })
    .then(r => r.json())
    .then(data => {
        setBuscarLoading(false);

        if (data.status === 'found') {
            mostrarFase2Existente(data);
        } else if (data.status === 'not_found') {
            mostrarFase2Nuevo();
        } else {
            mostrarAlerta(data.message || 'Error al verificar la cuenta.', 'err');
        }
    })
    .catch(() => {
        setBuscarLoading(false);
        mostrarAlerta('Error de conexión. Intenta de nuevo.', 'err');
    });
}

// ── Fase 2: alumno existente ─────────────────────────────────────
function mostrarFase2Existente(data) {
    document.getElementById('es_nuevo').value = '0';

    // Pre-llenar campos — editables para que el alumno pueda corregirlos
    setField('nombre',      data.nombre,     false);
    setField('correo',      data.correo,     false);
    setSelect('id_plantel', data.id_plantel, false);

    // UI
    document.getElementById('loaded-chip').style.display   = 'flex';
    document.getElementById('nuevo-chip').style.display    = 'none';
    document.getElementById('group-confirmar').style.display = 'none';
    document.getElementById('recovery-wrap').style.display  = 'block';
    document.getElementById('lbl-pass').innerHTML = 'Contraseña <span class="req">*</span>';
    document.getElementById('hint-pass').textContent = 'Ingresa tu contraseña de acceso';

    abrirBloque();
    document.getElementById('contrasena').focus();
}

// ── Fase 2: cuenta nueva ─────────────────────────────────────────
function mostrarFase2Nuevo() {
    document.getElementById('es_nuevo').value = '1';

    // Habilitar todos los campos vacíos
    setField('nombre',  '', false);
    setField('correo',  '', false);
    setSelect('id_plantel', 0, false);

    // UI
    document.getElementById('loaded-chip').style.display   = 'none';
    document.getElementById('nuevo-chip').style.display    = 'flex';
    document.getElementById('group-confirmar').style.display = 'block';
    document.getElementById('recovery-wrap').style.display  = 'none';
    document.getElementById('lbl-pass').innerHTML = 'Contraseña nueva <span class="req">*</span>';
    document.getElementById('hint-pass').textContent = 'Crea una contraseña (mínimo 6 caracteres)';

    abrirBloque();
    document.getElementById('nombre').focus();
}


// ── Helpers ──────────────────────────────────────────────────────
function abrirBloque() {
    document.getElementById('btn-buscar-wrap').style.display = 'none';
    const b = document.getElementById('fields-block');
    b.classList.add('open');
}

function setField(id, val, disabled) {
    const el = document.getElementById(id);
    el.value    = val;
    el.disabled = disabled;
}

function setSelect(id, val, disabled) {
    const sel = document.getElementById(id);
    sel.value    = val;
    sel.disabled = disabled;
}

function setBuscarLoading(on) {
    const btn = document.getElementById('btn-buscar');
    btn.disabled = on;
    document.getElementById('buscar-icon').outerHTML = on
        ? '<div class="spinner" id="buscar-icon"></div>'
        : '<svg id="buscar-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>';
    document.getElementById('buscar-label').textContent = on ? 'Verificando…' : 'Continuar';
}

function mostrarAlerta(msg, tipo) {
    limpiarAlerta();
    const cls  = tipo === 'err' ? 'alert-err' : 'alert-ok';
    const icon = tipo === 'err'
        ? '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
        : '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
    const div  = document.createElement('div');
    div.id     = 'js-alert';
    div.className = `alert ${cls} anim-fade`;
    div.innerHTML = icon + '<span>' + msg + '</span>';
    document.getElementById('group-cuenta').before(div);
}

function limpiarAlerta() {
    const a = document.getElementById('js-alert');
    if (a) a.remove();
    const se = document.getElementById('server-error');
    if (se) se.remove();
}

function togglePass(id, btn) {
    const inp = document.getElementById(id);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    btn.style.color = inp.type === 'text' ? 'var(--accent)' : 'var(--gray-300)';
}

// Solo dígitos en número de cuenta
document.getElementById('numero_cuenta').addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '');
    limpiarAlerta();
});
</script>
</body>
</html>
