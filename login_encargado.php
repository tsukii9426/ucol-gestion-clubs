<?php
session_start();
require_once __DIR__ . '/db.php';

// Si ya tiene sesión activa, redirigir directo al dashboard
if (!empty($_SESSION['encargado_id'])) {
    header('Location: dashboard_encargado.php');
    exit;
}

// ── Cargar planteles desde BD ────────────────────────────────────
$planteles = [];
try {
    $planteles = getDB()
        ->query('SELECT id, nombre FROM planteles ORDER BY nombre')
        ->fetchAll();
} catch (Exception $e) {
    // Sin BD: se mostrará el select vacío
}

$error     = '';
$flash_ok  = '';
$post_num  = '';
$post_pl   = 0;

// Mensaje de éxito proveniente de reset_contrasena_enc.php
if (!empty($_SESSION['flash_ok_enc'])) {
    $flash_ok = $_SESSION['flash_ok_enc'];
    unset($_SESSION['flash_ok_enc']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $num_trabajador = trim($_POST['numero_trabajador'] ?? '');
    $id_plantel     = (int)($_POST['id_plantel']       ?? 0);
    $contrasena     = $_POST['contrasena']              ?? '';
    $post_num       = $num_trabajador;
    $post_pl        = $id_plantel;

    if (!$num_trabajador || !$id_plantel) {
        $error = 'Completa todos los campos.';
    } elseif (!ctype_digit($num_trabajador)) {
        $error = 'El número de trabajador solo debe contener dígitos.';
    } elseif (!$contrasena) {
        $error = 'Ingresa tu contraseña.';
    } else {
        try {
            // Buscar la persona que sea encargado en el plantel seleccionado
            $stmt = getDB()->prepare("
                SELECT p.id, p.nombres, p.apellido_paterno, p.apellido_materno,
                       p.tipo, p.correo, p.contrasena,
                       pl.id AS id_plantel, pl.nombre AS plantel_nombre
                FROM personas p
                JOIN encargados e ON e.id_persona = p.id
                JOIN planteles pl ON pl.id = e.id_plantel AND pl.id = :plantel_id
                WHERE p.id = :id
                LIMIT 1
            ");
            $stmt->execute([
                ':id'         => (int)$num_trabajador,
                ':plantel_id' => $id_plantel,
            ]);
            $enc = $stmt->fetch();

            if (!$enc) {
                $error = 'No se encontró ningún encargado con esos datos en el plantel seleccionado. '
                       . 'Verifica o solicita tu registro.';
            } elseif (!$enc['contrasena'] || !password_verify($contrasena, $enc['contrasena'])) {
                $error = 'Contraseña incorrecta. Inténtalo de nuevo.';
            } else {
                // ✅ Autenticación correcta
                session_regenerate_id(true);
                $_SESSION['encargado_id']         = $enc['id'];
                $_SESSION['encargado_nombre']      = $enc['apellido_paterno'] . ' '
                                                   . $enc['apellido_materno'] . ' '
                                                   . $enc['nombres'];
                $_SESSION['encargado_tipo']        = $enc['tipo'];
                $_SESSION['encargado_correo']      = $enc['correo'];
                $_SESSION['encargado_plantel']     = $enc['plantel_nombre'];
                $_SESSION['encargado_id_plantel']  = $enc['id_plantel'];

                header('Location: dashboard_encargado.php');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Error de conexión con la base de datos. Intenta de nuevo.';
            error_log('login_encargado error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Personal — Clubes B23</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:       #1b2d54;
            --navy-light: #243567;
            --accent:     #4a7fd4;
            --accent-h:   #3568bf;
            --white:      #ffffff;
            --gray-50:    #f7f8fc;
            --gray-100:   #eef0f6;
            --gray-300:   #c5cad8;
            --gray-500:   #7a8099;
            --gray-700:   #3d4260;
            --text:       #1e2340;
            --error:      #d94f4f;
            --radius:     14px;
            --radius-sm:  8px;
            --shadow-lg:  0 16px 48px rgba(27,45,84,.18);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-color: #f0f3fa;
            background-image:
                radial-gradient(circle at 15% 25%, rgba(74,127,212,.08) 0%, transparent 50%),
                radial-gradient(circle at 85% 75%, rgba(27,45,84,.06) 0%, transparent 50%);
        }

        /* ── HEADER ──────────────────────────────────── */
        header {
            background: var(--navy);
            height: 60px;
            display: flex; align-items: center;
            padding: 0 2rem; gap: .75rem;
            box-shadow: 0 2px 10px rgba(0,0,0,.2);
        }
        .hb-logo {
            width: 38px; height: 38px; border-radius: 50%;
            background: var(--white);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Outfit', sans-serif; font-weight: 700;
            font-size: .74rem; color: var(--navy); flex-shrink: 0;
        }
        .hb-name { font-family: 'Outfit', sans-serif; font-size: 1rem; font-weight: 600; color: #fff; }
        .hb-sub  { font-size: .68rem; color: rgba(255,255,255,.5); text-transform: uppercase; letter-spacing: .8px; }

        /* ── MAIN ────────────────────────────────────── */
        main {
            flex: 1;
            display: flex; align-items: center; justify-content: center;
            padding: 2.5rem 1rem;
        }

        .login-wrap { width: 100%; max-width: 420px; }

        /* ── CARD ────────────────────────────────────── */
        .card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: fadeUp .4s ease both;
        }

        /* Header degradado */
        .card-top {
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
            padding: 2rem 2rem 1.75rem;
            position: relative; overflow: hidden;
        }
        .card-top::before {
            content: ''; position: absolute; right: -35px; top: -35px;
            width: 160px; height: 160px; border-radius: 50%;
            background: rgba(255,255,255,.06);
        }
        .card-top::after {
            content: ''; position: absolute; right: 30px; bottom: -55px;
            width: 200px; height: 200px; border-radius: 50%;
            background: rgba(255,255,255,.04);
        }
        .card-top-inner { position: relative; z-index: 1; }

        .role-badge {
            display: inline-flex; align-items: center; gap: .4rem;
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 20px; padding: .25rem .85rem;
            font-size: .72rem; font-weight: 600;
            font-family: 'Outfit', sans-serif;
            color: rgba(255,255,255,.9);
            text-transform: uppercase; letter-spacing: .8px;
            margin-bottom: 1.1rem;
        }

        .card-icon {
            width: 50px; height: 50px;
            background: rgba(255,255,255,.12);
            border: 1.5px solid rgba(255,255,255,.2);
            border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 1rem;
        }

        .card-top h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.35rem; font-weight: 700;
            color: var(--white); line-height: 1.3;
            margin-bottom: .35rem;
        }
        .card-top p { font-size: .82rem; color: rgba(255,255,255,.6); line-height: 1.5; }

        /* ── BODY ────────────────────────────────────── */
        .card-body { padding: 1.75rem 2rem 2rem; }

        /* ── FORM ────────────────────────────────────── */
        label {
            display: block;
            font-size: .73rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: .4px;
            color: var(--gray-700); margin-bottom: .45rem;
        }

        .iw { position: relative; }
        .iw .icon {
            position: absolute; left: .85rem; top: 50%;
            transform: translateY(-50%);
            color: var(--gray-300); pointer-events: none;
            transition: color .2s;
        }
        .iw:focus-within .icon { color: var(--accent); }

        /* Input grande tipo credencial */
        input[type="text"],
        input[type="password"],
        select {
            width: 100%; height: 54px;
            padding: 0 1rem 0 2.75rem;
            border: 1.5px solid var(--gray-100);
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: 1.3rem; font-weight: 600;
            letter-spacing: .1em;
            color: var(--text);
            background: var(--gray-50);
            transition: border-color .2s, box-shadow .2s, background .2s;
            outline: none;
            appearance: none; -webkit-appearance: none;
        }
        input[type="text"]::placeholder,
        input[type="password"]::placeholder {
            font-size: .9rem; font-weight: 400;
            letter-spacing: 0; color: var(--gray-300);
        }
        select { font-size: .95rem; font-weight: 500; letter-spacing: 0; cursor: pointer; }
        select option[value=""] { color: var(--gray-300); }
        input[type="text"]:focus,
        input[type="password"]:focus,
        select:focus {
            border-color: var(--accent);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(74,127,212,.12);
        }
        /* Flecha del select */
        .iw-select::after {
            content: '';
            position: absolute; right: .9rem; top: 50%;
            transform: translateY(-50%);
            width: 0; height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 6px solid var(--gray-300);
            pointer-events: none;
        }
        /* Separación entre campos */
        .field-gap { margin-top: 1.1rem; }

        .hint {
            font-size: .73rem; color: var(--gray-500);
            margin-top: .4rem;
            display: flex; align-items: center; gap: .3rem;
        }

        /* ── BOTÓN ───────────────────────────────────── */
        .btn-submit {
            width: 100%; height: 50px; margin-top: 1.4rem;
            background: var(--accent); color: var(--white);
            border: none; border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: .97rem; font-weight: 700; letter-spacing: .3px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: .5rem;
            transition: background .2s, transform .15s, box-shadow .2s;
            box-shadow: 0 4px 14px rgba(74,127,212,.25);
        }
        .btn-submit:hover {
            background: var(--accent-h);
            box-shadow: 0 6px 22px rgba(74,127,212,.35);
            transform: translateY(-1px);
        }
        .btn-submit:active { transform: translateY(0); }

        /* ── INFO BOX ────────────────────────────────── */
        .info-box {
            background: #f0f6ff; border: 1px solid #c8deff;
            border-radius: var(--radius-sm);
            padding: .85rem 1rem;
            font-size: .78rem; color: #2a4a80;
            display: flex; align-items: flex-start; gap: .5rem;
            margin-top: 1.25rem; line-height: 1.6;
        }
        .info-box svg { flex-shrink: 0; margin-top: 2px; }

        /* ── SEPARADOR Y LINK ────────────────────────── */
        .sep {
            display: flex; align-items: center; gap: .75rem;
            margin: 1.4rem 0 .5rem;
            color: var(--gray-300); font-size: .72rem;
        }
        .sep::before, .sep::after { content: ''; flex: 1; height: 1px; background: var(--gray-100); }

        .alt-link {
            display: flex; align-items: center; justify-content: center; gap: .4rem;
            font-size: .8rem; color: var(--gray-500); text-decoration: none;
            padding: .5rem; border-radius: var(--radius-sm);
            transition: color .2s, background .2s;
        }
        .alt-link:hover { color: var(--accent); background: var(--gray-50); }

        /* ── FOOTER ──────────────────────────────────── */
        footer {
            text-align: center; padding: 1.25rem;
            font-size: .72rem; color: var(--gray-500);
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 460px) {
            .card-body { padding: 1.5rem; }
            .card-top  { padding: 1.5rem; }
        }
    </style>
</head>
<body>

<header>
    <div class="hb-logo">UdeC</div>
    <div>
        <div class="hb-name">Clubes Estudiantiles</div>
        <div class="hb-sub">Bachillerato 23 · Universidad de Colima</div>
    </div>
</header>

<main>
<div class="login-wrap">
<div class="card">

    <!-- Encabezado -->
    <div class="card-top">
        <div class="card-top-inner">

            <div class="role-badge">
                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                Personal docente / administrativo
            </div>

            <div class="card-icon">
                <svg width="22" height="22" fill="none" stroke="white" stroke-width="1.8" viewBox="0 0 24 24">
                    <rect x="2" y="5" width="20" height="14" rx="2"/>
                    <path d="M2 10h20"/>
                    <path d="M7 15h2M11 15h6"/>
                </svg>
            </div>

            <h1>Ingresa tu número<br>de trabajador</h1>
            <p>Administra los clubs y horarios de tu plantel</p>

        </div>
    </div>

    <!-- Formulario -->
    <div class="card-body">

        <?php if ($flash_ok): ?>
        <div style="background:#edfaf4;border:1px solid #a5dfca;border-left:3px solid #2e9e6e;border-radius:8px;padding:.75rem 1rem;font-size:.82rem;color:#1a5e3f;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.5rem;line-height:1.5;">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= htmlspecialchars($flash_ok) ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div style="background:#fff5f5;border:1px solid #fbd5d5;border-left:3px solid #d94f4f;border-radius:8px;padding:.75rem 1rem;font-size:.82rem;color:#8b2020;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.5rem;line-height:1.5;">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" id="form-login">

            <!-- Plantel (dinámico desde BD) -->
            <label for="id_plantel">Plantel</label>
            <div class="iw iw-select">
                <svg class="icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
                <select id="id_plantel" name="id_plantel" required>
                    <option value="">Selecciona tu plantel…</option>
                    <?php if ($planteles): ?>
                        <?php foreach ($planteles as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $post_pl == $p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>No hay planteles registrados</option>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Número de trabajador -->
            <div class="field-gap">
                <label for="numero_trabajador">Número de trabajador</label>
                <div class="iw">
                    <svg class="icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="2" y="5" width="20" height="14" rx="2"/>
                        <path d="M2 10h20"/>
                        <path d="M7 15h2M11 15h6"/>
                    </svg>
                    <input
                        type="text"
                        id="numero_trabajador"
                        name="numero_trabajador"
                        placeholder="Ej. 12345"
                        inputmode="numeric"
                        maxlength="10"
                        autocomplete="off"
                        value="<?= htmlspecialchars($post_num) ?>"
                        autofocus
                        required>
                </div>
                <p class="hint">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    Este número es tu ID único de acceso al sistema
                </p>
            </div>

            <!-- Contraseña -->
            <div class="field-gap">
                <label for="contrasena">Contraseña</label>
                <div class="iw">
                    <svg class="icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    <input
                        type="password"
                        id="contrasena"
                        name="contrasena"
                        placeholder="Tu contraseña"
                        autocomplete="current-password"
                        required>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                    <polyline points="10 17 15 12 10 7"/>
                    <line x1="15" y1="12" x2="3" y2="12"/>
                </svg>
                Continuar
            </button>

            <div style="text-align:right;margin-top:.65rem">
                <a href="recuperar_contrasena_enc.php"
                   style="font-size:.78rem;color:var(--gray-500);text-decoration:none;transition:color .2s"
                   onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--gray-500)'">
                    ¿Olvidaste tu contraseña?
                </a>
            </div>

        </form>

        <div class="info-box">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            Solo el personal <strong>Administrativo</strong> y <strong>Docente</strong> registrado puede acceder. Si no puedes entrar, contacta al administrador de tu plantel.
        </div>

        <div class="sep">o</div>

        <a href="solicitud_encargado.php" class="alt-link">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            ¿No tienes acceso aún? Solicitar registro
        </a>

        <a href="login.php" class="alt-link" style="margin-top:.25rem">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12 19 5 12 12 5"/>
            </svg>
            Soy alumno — ir al portal de alumnos
        </a>

    </div>
</div>
</div>
</main>

<footer>
    © <?php echo date('Y'); ?> Universidad de Colima &nbsp;·&nbsp; Bachillerato 23 &nbsp;|&nbsp; Sistema de Clubes Estudiantiles
</footer>

<script>
// Solo permite números en el campo
document.getElementById('numero_trabajador').addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '');
});
</script>

</body>
</html>