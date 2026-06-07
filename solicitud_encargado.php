<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/enviar_correo.php';

// ── Cargar planteles 100% configurados (usuario + contraseña + correo + app) ──
$planteles = [];
try {
    $planteles = getDB()->query("
        SELECT id, nombre, correo
        FROM planteles
        WHERE usuario            IS NOT NULL AND usuario            != ''
          AND contrasena_cuenta  IS NOT NULL AND contrasena_cuenta  != ''
                                             AND contrasena_cuenta  != '\$2y\$12\$placeholder'
          AND correo             IS NOT NULL AND correo             != ''
        ORDER BY nombre
    ")->fetchAll();
} catch (Exception $e) {
    // Sin BD: lista vacía — se mostrará aviso
}

// ── Procesar formulario ──────────────────────────────────────────
$errores  = [];
$msg_ok   = '';
$post     = [];   // valores a repintar si hay error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post = $_POST;

    // — Recoger y sanear campos —
    $tipo             = trim($_POST['tipo']             ?? '');
    $num_trabajador   = trim($_POST['num_trabajador']   ?? '');
    $nombres          = trim($_POST['nombres']          ?? '');
    $apellido_paterno = trim($_POST['apellido_paterno'] ?? '');
    $apellido_materno = trim($_POST['apellido_materno'] ?? '');
    $correo           = trim($_POST['correo']           ?? '');
    $telefono         = trim($_POST['telefono']         ?? '');
    $id_plantel       = (int)($_POST['id_plantel']      ?? 0);
    $contrasena       = $_POST['contrasena']            ?? '';
    $confirmar        = $_POST['confirmar_contrasena']  ?? '';

    // — Validaciones —
    if (!in_array($tipo, ['Administrativo', 'Docente', 'Estudiante'], true)) {
        $errores[] = 'Selecciona un tipo de usuario válido.';
    }
    $label_num = ($tipo === 'Estudiante') ? 'número de cuenta' : 'número de trabajador';
    if (!$num_trabajador || !ctype_digit($num_trabajador)) {
        $errores[] = 'El ' . $label_num . ' solo debe contener dígitos y es obligatorio.';
    }
    if (!$nombres) {
        $errores[] = 'Ingresa tu(s) nombre(s).';
    }
    if (!$apellido_paterno) {
        $errores[] = 'Ingresa el apellido paterno.';
    }
    if (!$apellido_materno) {
        $errores[] = 'Ingresa el apellido materno.';
    }
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo electrónico no tiene un formato válido.';
    }
    if ($telefono && (!ctype_digit($telefono) || strlen($telefono) > 15)) {
        $errores[] = 'El teléfono solo debe contener dígitos (máx. 15).';
    }
    if ($id_plantel <= 0) {
        $errores[] = 'Selecciona el plantel al que perteneces.';
    }
    // Contraseña
    if (strlen($contrasena) < 8) {
        $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif (!preg_match('/[0-9]/', $contrasena)) {
        $errores[] = 'La contraseña debe contener al menos un número.';
    } elseif ($contrasena !== $confirmar) {
        $errores[] = 'Las contraseñas no coinciden.';
    }

    // — Verificar que el plantel exista y obtener su correo —
    $plantel_row = null;
    if (empty($errores) && $id_plantel > 0) {
        try {
            $stmt = getDB()->prepare('SELECT id, nombre, correo, contrasena_app FROM planteles WHERE id = ?');
            $stmt->execute([$id_plantel]);
            $plantel_row = $stmt->fetch();
        } catch (Exception $e) { /* manejado abajo */ }

        if (!$plantel_row) {
            $errores[] = 'El plantel seleccionado no existe. Recarga la página e intenta de nuevo.';
        }
    }

    // — ¿Ya existe una solicitud pendiente para este número de trabajador? —
    if (empty($errores)) {
        try {
            $stmt = getDB()->prepare(
                'SELECT id FROM solicitudes_encargado
                 WHERE numero_trabajador = ? AND estado = "pendiente" AND expira_en > NOW()'
            );
            $stmt->execute([(int)$num_trabajador]);
            if ($stmt->fetch()) {
                $errores[] = 'Ya existe una solicitud pendiente para ese número de trabajador. '
                           . 'Revisa el correo del plantel o espera a que expire (24 h) para reenviarla.';
            }
        } catch (Exception $e) { /* Continuar */ }
    }

    // — Guardar y enviar —
    if (empty($errores)) {
        $token     = bin2hex(random_bytes(32));
        $expira_en = date('Y-m-d H:i:s', strtotime('+24 hours'));

        try {
            $pdo  = getDB();
            $hash = password_hash($contrasena, PASSWORD_DEFAULT);
            $ins  = $pdo->prepare("
                INSERT INTO solicitudes_encargado
                    (token, numero_trabajador, tipo, nombres, apellido_paterno,
                     apellido_materno, correo, telefono, contrasena, id_plantel, expira_en)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $token,
                (int)$num_trabajador,
                $tipo,
                mb_strtoupper($nombres),
                mb_strtoupper($apellido_paterno),
                mb_strtoupper($apellido_materno),
                $correo,
                $telefono ?: null,
                $hash,
                $id_plantel,
                $expira_en,
            ]);
        } catch (Exception $e) {
            if (MAIL_DEMO) {
                // En modo demo permitir continuar aunque la BD falle
                error_log('solicitud_encargado BD error: ' . $e->getMessage());
            } else {
                $errores[] = 'Error al guardar la solicitud. Intenta de nuevo.';
            }
        }

        if (empty($errores)) {
            $link_aprobar  = BASE_URL . '/aprobar_encargado.php?token=' . $token . '&accion=aprobar';
            $link_rechazar = BASE_URL . '/aprobar_encargado.php?token=' . $token . '&accion=rechazar';

            $nombre_completo = mb_strtoupper($apellido_paterno) . ' '
                             . mb_strtoupper($apellido_materno) . ' '
                             . mb_strtoupper($nombres);

            // Credenciales SMTP del plantel destinatario
            $smtp_plantel = [
                'correo'        => $plantel_row['correo']        ?? '',
                'contrasena_app'=> $plantel_row['contrasena_app'] ?? '',
            ];

            $enviado = enviarCorreoAprobacion([
                // Destinatario: correo del plantel
                'correo_plantel'     => $plantel_row['correo'],
                'nombre_plantel'     => $plantel_row['nombre'],
                // Datos del solicitante
                'tipo'               => $tipo === 'Estudiante' ? 'Alumno/Estudiante' : $tipo,
                'num_trabajador'     => ($tipo === 'Estudiante' ? 'Núm. cuenta: ' : 'Núm. trabajador: ') . $num_trabajador,
                'nombre_completo'    => $nombre_completo,
                'correo_solicitante' => $correo,
                'telefono'           => $telefono ?: '—',
                // Links
                'link_aprobar'       => $link_aprobar,
                'link_rechazar'      => $link_rechazar,
            ], $smtp_plantel);

            if ($enviado) {
                if (MAIL_DEMO) {
                    $msg_ok = 'Modo demo activo. La solicitud se guardó en '
                            . '<code>logs/correos_demo.log</code>.<br>'
                            . 'Links directos:<br>'
                            . '✅ <a href="' . htmlspecialchars($link_aprobar)  . '" target="_blank">Aprobar</a> &nbsp;|&nbsp;'
                            . '❌ <a href="' . htmlspecialchars($link_rechazar) . '" target="_blank">Rechazar</a>';
                } else {
                    $msg_ok = 'Tu solicitud fue enviada correctamente al plantel '
                            . '<strong>' . htmlspecialchars($plantel_row['nombre']) . '</strong>. '
                            . 'Recibirás respuesta una vez que sea revisada.';
                }
                $post = []; // limpiar formulario
            } else {
                $errores[] = 'No se pudo enviar el correo al plantel. Intenta de nuevo.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitud de Encargado — Clubes Estudiantiles</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:      #1b2d54;
            --navy-l:    #243567;
            --accent:    #4a7fd4;
            --accent-h:  #3568bf;
            --success:   #2e9e6e;
            --white:     #ffffff;
            --gray-50:   #f7f8fc;
            --gray-100:  #eef0f6;
            --gray-200:  #e0e4f0;
            --gray-300:  #c5cad8;
            --gray-500:  #7a8099;
            --gray-700:  #3d4260;
            --text:      #1e2340;
            --error:     #d94f4f;
            --radius:    14px;
            --radius-sm: 8px;
            --shadow-lg: 0 16px 48px rgba(27,45,84,.18);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: #f0f3fa;
            background-image:
                radial-gradient(circle at 15% 25%, rgba(74,127,212,.08) 0%, transparent 50%),
                radial-gradient(circle at 85% 75%, rgba(27,45,84,.06) 0%, transparent 50%);
        }

        /* ── HEADER ─────────────────────────────────── */
        header {
            background: var(--navy);
            height: 60px;
            display: flex;
            align-items: center;
            padding: 0 2rem;
            gap: .75rem;
            box-shadow: 0 2px 10px rgba(0,0,0,.2);
        }
        .hb-logo { width:38px; height:38px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-family:'Outfit',sans-serif; font-weight:700; font-size:.74rem; color:var(--navy); flex-shrink:0; }
        .hb-name { font-family:'Outfit',sans-serif; font-size:1rem; font-weight:600; color:#fff; }
        .hb-sub  { font-size:.68rem; color:rgba(255,255,255,.5); text-transform:uppercase; letter-spacing:.8px; }

        /* ── MAIN ───────────────────────────────────── */
        main { flex:1; display:flex; align-items:flex-start; justify-content:center; padding:2.5rem 1rem 4rem; }
        .wrap { width:100%; max-width:540px; }

        /* ── CARD ────────────────────────────────────── */
        .card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: fadeUp .4s ease both;
        }
        @keyframes fadeUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }

        .card-top {
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-l) 100%);
            padding: 2rem 2rem 1.75rem;
            position: relative;
            overflow: hidden;
        }
        .card-top::after  { content:''; position:absolute; right:-30px; bottom:-50px; width:180px; height:180px; border-radius:50%; background:rgba(255,255,255,.05); }
        .card-top::before { content:''; position:absolute; right:60px;  top:-60px;   width:220px; height:220px; border-radius:50%; background:rgba(255,255,255,.04); }

        .top-icon {
            width: 52px; height: 52px;
            background: rgba(255,255,255,.15);
            border: 1.5px solid rgba(255,255,255,.25);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            position: relative; z-index: 1;
        }
        .top-icon svg { color: #fff; }

        .card-top h1 { font-family:'Outfit',sans-serif; font-size:1.3rem; font-weight:700; color:#fff; position:relative; z-index:1; line-height:1.3; }
        .card-top p  { margin-top:.35rem; font-size:.83rem; color:rgba(255,255,255,.65); position:relative; z-index:1; }

        .card-body { padding: 1.75rem 2rem 2rem; }

        /* ── STEP BADGE ───────────────────────────── */
        .step-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 20px;
            padding: .3rem .8rem;
            font-size: .74rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 1.4rem;
        }
        .step-dot { width:6px; height:6px; border-radius:50%; background:var(--accent); }

        /* ── ALERTS ─────────────────────────────────── */
        .alert {
            border-radius: var(--radius-sm);
            padding: .9rem 1.1rem;
            font-size: .83rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: .6rem;
            line-height: 1.55;
            animation: fadeUp .3s ease both;
        }
        .alert svg { flex-shrink:0; margin-top:2px; }
        .alert-err { background:#fff5f5; border:1px solid #fbd5d5; border-left:3px solid var(--error);   color:#8b2020; }
        .alert-ok  { background:#edfaf4; border:1px solid #a5dfca; border-left:3px solid var(--success); color:#1a5e3f; }
        .alert a { color:inherit; }
        .alert code { background: rgba(0,0,0,.07); padding:.1rem .35rem; border-radius:4px; font-size:.82em; }

        /* ── TIPO SELECTOR (cards) ─────────────────── */
        .tipo-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.65rem; margin-bottom:1.3rem; }
        .tipo-card { display:flex; flex-direction:column; align-items:center; gap:.4rem; padding:.85rem .5rem; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); cursor:pointer; transition:all .2s; background:var(--gray-50); text-align:center; }
        .tipo-card:hover { border-color:var(--accent); background:#f0f5ff; }
        .tipo-card input[type=radio] { display:none; }
        .tipo-card.selected { border-color:var(--accent); background:#eef4ff; box-shadow:0 0 0 3px rgba(74,127,212,.12); }
        .tipo-card .tc-icon { width:38px; height:38px; border-radius:10px; background:var(--gray-100); display:flex; align-items:center; justify-content:center; transition:all .2s; flex-shrink:0; }
        .tipo-card.selected .tc-icon { background:var(--accent); }
        .tipo-card.selected .tc-icon svg { color:#fff; }
        .tipo-card .tc-icon svg { color:var(--gray-500); transition:color .2s; }
        .tipo-card .tc-lbl { font-family:'Outfit',sans-serif; font-size:.78rem; font-weight:600; color:var(--gray-700); line-height:1.2; }
        .tipo-card.selected .tc-lbl { color:var(--navy); }

        /* ── FORM ────────────────────────────────────── */
        .form-section { margin-bottom:1.4rem; }
        .section-title {
            font-size:.68rem; font-weight:700; text-transform:uppercase;
            letter-spacing:1px; color:var(--gray-500);
            display:flex; align-items:center; gap:.4rem;
            margin-bottom:.9rem;
            padding-bottom:.5rem;
            border-bottom:1px solid var(--gray-100);
        }

        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:.85rem; }
        .form-group { margin-bottom:.85rem; }
        label { display:block; font-size:.76rem; font-weight:600; color:var(--gray-700); margin-bottom:.4rem; letter-spacing:.3px; text-transform:uppercase; }
        label span.req { color:var(--error); }

        .input-wrap { position:relative; }
        .field-icon { position:absolute; left:.85rem; top:50%; transform:translateY(-50%); color:var(--gray-300); pointer-events:none; z-index:1; transition:color .2s; }
        .input-wrap:focus-within .field-icon { color:var(--accent); }

        input[type=text], input[type=email], input[type=tel],
        input[type=password], select {
            width:100%; height:46px; padding:0 1rem 0 2.6rem;
            border:1.5px solid var(--gray-100); border-radius:var(--radius-sm);
            font-family:'DM Sans',sans-serif; font-size:.92rem; color:var(--text);
            background:var(--gray-50); outline:none;
            transition:border-color .2s, box-shadow .2s, background .2s;
            appearance:none; -webkit-appearance:none;
        }
        /* Input con botón de ojo al final */
        input[type=password] { padding-right: 2.8rem; }
        input:focus, select:focus {
            border-color:var(--accent); background:var(--white);
            box-shadow:0 0 0 3px rgba(74,127,212,.12);
        }
        /* La flecha va en ::after, NO en el div contenedor */
        .select-arrow::after { content:''; position:absolute; right:.9rem; top:50%; transform:translateY(-50%); width:0; height:0; border-left:5px solid transparent; border-right:5px solid transparent; border-top:6px solid var(--gray-300); pointer-events:none; transition:border-color .2s; }
        .input-wrap:focus-within .select-arrow::after { border-top-color:var(--accent); }
        /* Padding derecho extra para que el texto no tape la flecha */
        .select-arrow select { padding-right:2.2rem; }

        .form-hint { font-size:.73rem; color:var(--gray-500); margin-top:.3rem; }

        /* ── INFO BOX ────────────────────────────────── */
        .info-box {
            background: #f0f6ff;
            border: 1px solid #c8deff;
            border-radius: var(--radius-sm);
            padding: .8rem 1rem;
            font-size: .8rem;
            color: #2a4a80;
            display: flex;
            gap: .6rem;
            align-items: flex-start;
            line-height: 1.55;
            margin-top: .5rem;
        }
        .info-box svg { flex-shrink:0; margin-top:2px; }

        /* ── FOOTER DEL FORM ─────────────────────────── */
        .form-footer {
            display: flex;
            gap: .75rem;
            margin-top: 1.6rem;
            padding-top: 1.4rem;
            border-top: 1px solid var(--gray-100);
        }
        .btn {
            flex: 1; height: 48px;
            border: none; border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: .92rem; font-weight: 600;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: .45rem;
            transition: all .2s;
        }
        .btn-primary { background:var(--accent); color:#fff; }
        .btn-primary:hover { background:var(--accent-h); box-shadow:0 6px 20px rgba(74,127,212,.3); transform:translateY(-1px); }
        .btn-primary:active { transform:translateY(0); }
        .btn-secondary { background:var(--gray-100); color:var(--gray-700); flex:0 0 auto; padding:0 1.4rem; }
        .btn-secondary:hover { background:var(--gray-200); }

        footer { text-align:center; padding:1.5rem; font-size:.72rem; color:var(--gray-500); }

        @media (max-width:520px) {
            .form-row { grid-template-columns:1fr; }
            .card-body { padding:1.4rem 1.25rem 1.5rem; }
            .form-footer { flex-direction:column-reverse; }
            .btn-secondary { width:100%; }
        }
    </style>
</head>
<body>

<header>
    <div class="hb-logo">UdeC</div>
    <div>
        <div class="hb-name">Clubes Estudiantiles</div>
        <div class="hb-sub">Bachillerato 23 &nbsp;·&nbsp; Universidad de Colima</div>
    </div>
</header>

<main>
<div class="wrap">
<div class="card">

    <div class="card-top">
        <div class="top-icon">
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
        </div>
        <h1>Solicitud de registro como encargado</h1>
        <p>Completa tus datos — el plantel revisará y aprobará tu solicitud</p>
    </div>

    <div class="card-body">

        <div class="step-badge">
            <div class="step-dot"></div>
            Paso 1 de 1 &nbsp;·&nbsp; Llena el formulario y envía tu solicitud
        </div>

        <?php if ($msg_ok): ?>
        <div class="alert alert-ok">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <div><?= $msg_ok ?></div>
        </div>
        <?php endif; ?>

        <?php if ($errores): ?>
        <div class="alert alert-err">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <div>
                <?php if (count($errores) === 1): ?>
                    <?= htmlspecialchars($errores[0]) ?>
                <?php else: ?>
                    <ul style="padding-left:1rem;margin:0">
                        <?php foreach ($errores as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$planteles): ?>
        <div class="alert alert-err" style="margin-bottom:1.25rem">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <div>No se pudo cargar la lista de planteles. Verifica la conexión con la base de datos.</div>
        </div>
        <?php endif; ?>

        <form method="POST" action="" id="form-solicitud" novalidate>

            <!-- ── TIPO DE USUARIO ───────────────────── -->
            <div class="form-section">
                <div class="section-title">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Tipo de usuario
                </div>

                <div class="tipo-grid">
                    <!-- 1. Administrativo -->
                    <label class="tipo-card <?= (($post['tipo'] ?? '') === 'Administrativo') ? 'selected' : '' ?>" id="card-admin">
                        <input type="radio" name="tipo" value="Administrativo"
                            <?= (($post['tipo'] ?? '') === 'Administrativo') ? 'checked' : '' ?>>
                        <div class="tc-icon">
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <rect x="2" y="7" width="20" height="14" rx="2"/>
                                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                            </svg>
                        </div>
                        <span class="tc-lbl">Administrativo</span>
                    </label>

                    <!-- 2. Docente -->
                    <label class="tipo-card <?= (($post['tipo'] ?? '') === 'Docente') ? 'selected' : '' ?>" id="card-docente">
                        <input type="radio" name="tipo" value="Docente"
                            <?= (($post['tipo'] ?? '') === 'Docente') ? 'checked' : '' ?>>
                        <div class="tc-icon">
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                                <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                            </svg>
                        </div>
                        <span class="tc-lbl">Personal<br>Docente</span>
                    </label>

                    <!-- 3. Alumno -->
                    <label class="tipo-card <?= (($post['tipo'] ?? '') === 'Estudiante') ? 'selected' : '' ?>" id="card-alumno">
                        <input type="radio" name="tipo" value="Estudiante"
                            <?= (($post['tipo'] ?? '') === 'Estudiante') ? 'checked' : '' ?>>
                        <div class="tc-icon">
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                        </div>
                        <span class="tc-lbl">Alumno</span>
                    </label>
                </div>
            </div>

            <!-- ── IDENTIFICACIÓN ────────────────────── -->
            <div class="form-section">
                <div class="section-title">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                    <span id="section-title-id">IDENTIFICACIÓN</span>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="num_trabajador" id="lbl-num">
                            <span id="lbl-num-txt">Núm. de trabajador</span> <span class="req">*</span>
                        </label>
                        <div class="input-wrap">
                            <svg class="field-icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>
                            </svg>
                            <input type="text" id="num_trabajador" name="num_trabajador"
                                placeholder="Ej. 10045"
                                maxlength="10"
                                value="<?= htmlspecialchars($post['num_trabajador'] ?? '') ?>"
                                inputmode="numeric" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="id_plantel">Plantel <span class="req">*</span></label>
                        <div class="input-wrap select-arrow">
                            <svg class="field-icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                            </svg>
                            <select id="id_plantel" name="id_plantel" required>
                                <option value="">Selecciona…</option>
                                <?php foreach ($planteles as $p): ?>
                                <option value="<?= $p['id'] ?>"
                                    <?= ((int)($post['id_plantel'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['nombre']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="correo">Correo electrónico <span class="req">*</span></label>
                    <div class="input-wrap">
                        <svg class="field-icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                            <polyline points="22,6 12,13 2,6"/>
                        </svg>
                        <input type="email" id="correo" name="correo"
                            placeholder="Ej. nombre.apellido@ucol.edu.mx"
                            value="<?= htmlspecialchars($post['correo'] ?? '') ?>"
                            autocomplete="email" required>
                    </div>
                    <p class="form-hint" style="display:flex;align-items:flex-start;gap:.35rem;margin-top:.4rem">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        A este correo se enviará la notificación con el resultado de tu solicitud (aprobada o rechazada).
                    </p>
                </div>

                <div class="form-group">
                    <label for="telefono">Teléfono <span style="font-weight:400;text-transform:none;letter-spacing:0">(opcional)</span></label>
                    <div class="input-wrap">
                        <svg class="field-icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.41 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.96a16 16 0 0 0 6.08 6.08l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.73 17z"/>
                        </svg>
                        <input type="tel" id="telefono" name="telefono"
                            placeholder="Ej. 3141234567"
                            maxlength="15"
                            value="<?= htmlspecialchars($post['telefono'] ?? '') ?>"
                            inputmode="numeric">
                    </div>
                </div>
            </div>

            <!-- ── CONTRASEÑA ─────────────────────────── -->
            <div class="form-section">
                <div class="section-title">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Contraseña de acceso
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="contrasena">Contraseña <span class="req">*</span></label>
                        <div class="input-wrap">
                            <svg class="field-icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" id="contrasena" name="contrasena"
                                placeholder="Mín. 8 caracteres"
                                autocomplete="new-password"
                                oninput="calcFuerza(this.value)"
                                required>
                            <button type="button" onclick="togglePass('contrasena',this)"
                                style="position:absolute;right:.65rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gray-300);padding:.2rem;line-height:0;z-index:2;transition:color .2s"
                                onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--gray-300)'">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        <!-- Barra de fortaleza -->
                        <div style="margin-top:.45rem">
                            <div style="height:4px;background:var(--gray-100);border-radius:4px;overflow:hidden">
                                <div id="fuerza-bar" style="height:100%;width:0%;border-radius:4px;transition:width .3s,background .3s"></div>
                            </div>
                            <span id="fuerza-lbl" style="font-size:.7rem;color:var(--gray-500);margin-top:.25rem;display:block;min-height:1rem"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirmar_contrasena">Confirmar contraseña <span class="req">*</span></label>
                        <div class="input-wrap">
                            <svg class="field-icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" id="confirmar_contrasena" name="confirmar_contrasena"
                                placeholder="Repite tu contraseña"
                                autocomplete="new-password"
                                oninput="checkMatch()"
                                required>
                            <button type="button" onclick="togglePass('confirmar_contrasena',this)"
                                style="position:absolute;right:.65rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gray-300);padding:.2rem;line-height:0;z-index:2;transition:color .2s"
                                onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--gray-300)'">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        <span id="match-lbl" style="font-size:.7rem;margin-top:.25rem;display:block;min-height:1rem"></span>
                    </div>
                </div>
                <p class="form-hint">Mínimo 8 caracteres, al menos 1 número. Úsala para iniciar sesión.</p>
            </div>

            <!-- ── NOMBRE COMPLETO ───────────────────── -->
            <div class="form-section">
                <div class="section-title">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Nombre completo
                </div>

                <!-- 1. Nombre(s) -->
                <div class="form-group">
                    <label for="nombres">Nombre(s) <span class="req">*</span></label>
                    <div class="input-wrap">
                        <svg class="field-icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" id="nombres" name="nombres"
                            placeholder="Ej. Roberto Carlos"
                            value="<?= htmlspecialchars($post['nombres'] ?? '') ?>"
                            autocomplete="given-name" required>
                    </div>
                </div>

                <div class="form-row">
                    <!-- 2. Apellido paterno -->
                    <div class="form-group">
                        <label for="apellido_paterno">Apellido paterno <span class="req">*</span></label>
                        <div class="input-wrap">
                            <svg class="field-icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <input type="text" id="apellido_paterno" name="apellido_paterno"
                                placeholder="Ej. Martínez"
                                value="<?= htmlspecialchars($post['apellido_paterno'] ?? '') ?>"
                                autocomplete="family-name" required>
                        </div>
                    </div>

                    <!-- 3. Apellido materno -->
                    <div class="form-group">
                        <label for="apellido_materno">Apellido materno <span class="req">*</span></label>
                        <div class="input-wrap">
                            <svg class="field-icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <input type="text" id="apellido_materno" name="apellido_materno"
                                placeholder="Ej. López"
                                value="<?= htmlspecialchars($post['apellido_materno'] ?? '') ?>"
                                required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── INFO (cambia según tipo) ─────────── -->
            <div class="info-box" id="info-box-personal" style="display:flex">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                <span>
                    Tu solicitud será enviada al correo del plantel seleccionado para su revisión.
                    El responsable del plantel aprobará o rechazará tu solicitud.
                    Recibirás una notificación a tu correo con el resultado.
                </span>
            </div>
            <div class="info-box" id="info-box-alumno" style="display:none;background:#f0fbf5;border-color:#a5dfca;color:#1a5e3f">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                <span>
                    Como alumno, tu solicitud de registro será revisada por el plantel que selecciones.
                    Al ser aprobada, quedarás registrado en el sistema y podrás inscribirte a los clubes disponibles.
                </span>
            </div>

            <!-- ── FOOTER ────────────────────────────── -->
            <div class="form-footer">
                <a href="login_encargado.php" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <line x1="22" y1="2" x2="11" y2="13"/>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                    Enviar solicitud
                </button>
            </div>

        </form>

    </div><!-- /card-body -->
</div><!-- /card -->
</div><!-- /wrap -->
</main>

<footer>
    © <?= date('Y') ?> Universidad de Colima — Bachillerato 23 &nbsp;|&nbsp; Sistema de Clubes Estudiantiles
</footer>

<script>
// ── Cambios dinámicos según tipo ─────────────────
function actualizarTipo(tipo) {
    const esAlumno = tipo === 'Estudiante';

    // Label del número de ID
    document.getElementById('lbl-num-txt').textContent =
        esAlumno ? 'Núm. de cuenta' : 'Núm. de trabajador';

    // Placeholder del input
    const inp = document.getElementById('num_trabajador');
    inp.placeholder = esAlumno ? 'Ej. 20231113' : 'Ej. 10045';
    inp.maxLength   = esAlumno ? 10 : 10;

    // Título de la sección
    document.getElementById('section-title-id').textContent =
        esAlumno ? 'DATOS DEL ALUMNO' : 'IDENTIFICACIÓN';

    // Info box
    document.getElementById('info-box-personal').style.display = esAlumno ? 'none' : 'flex';
    document.getElementById('info-box-alumno').style.display   = esAlumno ? 'flex' : 'none';

    // Badge del paso
    document.querySelector('.step-badge').lastChild.textContent =
        esAlumno
            ? ' Registro de alumno — Llena el formulario y envía tu solicitud'
            : ' Paso 1 de 1 — Llena el formulario y envía tu solicitud';
}

// Resaltar tarjeta seleccionada y disparar cambio de UI
document.querySelectorAll('.tipo-card input[type=radio]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.tipo-card').forEach(c => c.classList.remove('selected'));
        this.closest('.tipo-card').classList.add('selected');
        actualizarTipo(this.value);
    });
});

// Aplicar estado inicial si viene de POST con error
(function() {
    var checked = document.querySelector('.tipo-card input[type=radio]:checked');
    if (checked) actualizarTipo(checked.value);
})();

// Solo dígitos en número de ID y teléfono
['num_trabajador', 'telefono'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', function() {
        this.value = this.value.replace(/\D/g, '');
    });
});

// ── Barra de fortaleza de contraseña ─────────────
function calcFuerza(val) {
    var bar = document.getElementById('fuerza-bar');
    var lbl = document.getElementById('fuerza-lbl');
    if (!bar) return;
    var pts = 0;
    if (val.length >= 8)          pts++;
    if (val.length >= 12)         pts++;
    if (/[0-9]/.test(val))        pts++;
    if (/[a-z]/.test(val) && /[A-Z]/.test(val)) pts++;
    if (/[^a-zA-Z0-9]/.test(val)) pts++;
    var map = [
        {w:'0%',  c:'',                    t:''},
        {w:'25%', c:'#d94f4f',             t:'Muy débil'},
        {w:'50%', c:'#d47a20',             t:'Débil'},
        {w:'75%', c:'#d4b820',             t:'Buena'},
        {w:'90%', c:'#4a7fd4',             t:'Fuerte'},
        {w:'100%',c:'var(--success)',       t:'Excelente'},
    ];
    var m = map[Math.min(pts, 5)];
    bar.style.width      = m.w;
    bar.style.background = m.c;
    lbl.textContent      = m.t;
    lbl.style.color      = m.c;
    checkMatch();
}

// ── Verificar coincidencia ────────────────────────
function checkMatch() {
    var p1  = document.getElementById('contrasena').value;
    var p2  = document.getElementById('confirmar_contrasena').value;
    var lbl = document.getElementById('match-lbl');
    if (!p2) { lbl.textContent = ''; return; }
    if (p1 === p2) {
        lbl.textContent = '✓ Las contraseñas coinciden';
        lbl.style.color = 'var(--success)';
    } else {
        lbl.textContent = '✗ No coinciden';
        lbl.style.color = 'var(--error)';
    }
}

// ── Mostrar / ocultar contraseña ─────────────────
function togglePass(fieldId, btn) {
    var inp = document.getElementById(fieldId);
    var svg = btn.querySelector('svg');
    if (inp.type === 'password') {
        inp.type = 'text';
        svg.innerHTML = '<line x1="2" y1="2" x2="22" y2="22"/><path d="M6.71 6.71C3.15 8.99 1 12 1 12s4 8 11 8c2.27 0 4.36-.73 6.11-1.95"/><path d="M17.41 17.41C15.88 18.44 14.01 19 12 19"/><path d="M9.88 9.88A3 3 0 0 0 12 15a3 3 0 0 0 2.12-5.12"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 11 7 11 7a21.77 21.77 0 0 1-1.7 2.47"/>';
    } else {
        inp.type = 'password';
        svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
}
</script>

</body>
</html>
