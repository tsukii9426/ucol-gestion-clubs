<?php
session_start();
require_once __DIR__ . '/db.php';

if (!empty($_SESSION['plantel_id'])) {
    header('Location: dashboard_plantel.php');
    exit;
}

$error    = '';
$post_usr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario    = trim($_POST['usuario']    ?? '');
    $contrasena = $_POST['contrasena']      ?? '';
    $post_usr   = $usuario;

    if (!$usuario || !$contrasena) {
        $error = 'Ingresa tu usuario y contraseña.';
    } else {
        try {
            $stmt = getDB()->prepare(
                'SELECT id, nombre, usuario, correo, contrasena_cuenta
                 FROM planteles WHERE usuario = ? LIMIT 1'
            );
            $stmt->execute([$usuario]);
            $pl = $stmt->fetch();

            if (!$pl || !$pl['contrasena_cuenta']) {
                $error = 'Usuario no encontrado o sin contraseña configurada.';
            } elseif (!password_verify($contrasena, $pl['contrasena_cuenta'])) {
                $error = 'Contraseña incorrecta.';
            } else {
                session_regenerate_id(true);
                $_SESSION['plantel_id']      = $pl['id'];
                $_SESSION['plantel_nombre']  = $pl['nombre'];
                $_SESSION['plantel_usuario'] = $pl['usuario'];
                $_SESSION['plantel_correo']  = $pl['correo'];
                header('Location: dashboard_plantel.php');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Error de conexión. Intenta de nuevo.';
            error_log('login_plantel: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Plantel — Clubes Estudiantiles</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:    #1b2d54;
            --navy-l:  #243567;
            --accent:  #4a7fd4;
            --accent-h:#3568bf;
            --white:   #ffffff;
            --gray-50: #f7f8fc;
            --gray-100:#eef0f6;
            --gray-300:#c5cad8;
            --gray-500:#7a8099;
            --gray-700:#3d4260;
            --text:    #1e2340;
            --error:   #d94f4f;
            --radius:  14px;
            --radius-sm:8px;
            --shadow:  0 16px 48px rgba(27,45,84,.18);
        }
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        body {
            font-family:'DM Sans',sans-serif;
            min-height:100vh;
            display:flex; flex-direction:column;
            background:#f0f3fa;
            background-image:
                radial-gradient(circle at 20% 30%, rgba(74,127,212,.09) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(27,45,84,.07) 0%, transparent 50%);
        }
        header {
            background:var(--navy); height:60px;
            display:flex; align-items:center; padding:0 2rem; gap:.75rem;
            box-shadow:0 2px 10px rgba(0,0,0,.2);
        }
        .hb-logo { width:38px; height:38px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-family:'Outfit',sans-serif; font-weight:700; font-size:.74rem; color:var(--navy); }
        .hb-name { font-family:'Outfit',sans-serif; font-size:1rem; font-weight:600; color:#fff; }
        .hb-sub  { font-size:.68rem; color:rgba(255,255,255,.5); text-transform:uppercase; letter-spacing:.8px; }

        main { flex:1; display:flex; align-items:center; justify-content:center; padding:2.5rem 1rem; }
        .wrap { width:100%; max-width:400px; }

        .card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; animation:fadeUp .4s ease both; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }

        .card-top {
            background:linear-gradient(135deg, var(--navy) 0%, var(--navy-l) 100%);
            padding:2rem 2rem 1.75rem;
            position:relative; overflow:hidden;
        }
        .card-top::after  { content:''; position:absolute; right:-30px; bottom:-50px; width:170px; height:170px; border-radius:50%; background:rgba(255,255,255,.05); }
        .card-top::before { content:''; position:absolute; right:50px; top:-60px; width:200px; height:200px; border-radius:50%; background:rgba(255,255,255,.04); }

        .top-icon { width:52px; height:52px; background:rgba(255,255,255,.15); border:1.5px solid rgba(255,255,255,.25); border-radius:14px; display:flex; align-items:center; justify-content:center; margin-bottom:1rem; position:relative; z-index:1; }
        .top-icon svg { color:#fff; }
        .card-top h1 { font-family:'Outfit',sans-serif; font-size:1.3rem; font-weight:700; color:#fff; position:relative; z-index:1; line-height:1.3; }
        .card-top p  { margin-top:.35rem; font-size:.82rem; color:rgba(255,255,255,.65); position:relative; z-index:1; }

        .role-chip {
            display:inline-flex; align-items:center; gap:.4rem;
            background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25);
            border-radius:20px; padding:.25rem .8rem;
            font-size:.7rem; font-weight:600; font-family:'Outfit',sans-serif;
            color:rgba(255,255,255,.9); text-transform:uppercase; letter-spacing:.8px;
            margin-bottom:.9rem; position:relative; z-index:1;
        }

        .card-body { padding:1.75rem 2rem 2rem; }

        .alert-err {
            background:#fff5f5; border:1px solid #fbd5d5;
            border-left:3px solid var(--error);
            border-radius:var(--radius-sm);
            padding:.75rem 1rem;
            font-size:.82rem; color:#8b2020;
            margin-bottom:1.25rem;
            display:flex; align-items:flex-start; gap:.5rem; line-height:1.5;
        }

        .form-group { margin-bottom:1.1rem; }
        label { display:block; font-size:.73rem; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:var(--gray-700); margin-bottom:.4rem; }

        .iw { position:relative; }
        .iw .icon { position:absolute; left:.85rem; top:50%; transform:translateY(-50%); color:var(--gray-300); pointer-events:none; transition:color .2s; }
        .iw:focus-within .icon { color:var(--accent); }

        input[type=text], input[type=password] {
            width:100%; height:50px;
            padding:0 2.8rem 0 2.75rem;
            border:1.5px solid var(--gray-100);
            border-radius:var(--radius-sm);
            font-family:'Outfit',sans-serif; font-size:1.1rem; font-weight:600;
            letter-spacing:.05em; color:var(--text);
            background:var(--gray-50); outline:none;
            transition:border-color .2s, box-shadow .2s, background .2s;
            appearance:none;
        }
        input::placeholder { font-size:.9rem; font-weight:400; letter-spacing:0; color:var(--gray-300); }
        input:focus { border-color:var(--accent); background:var(--white); box-shadow:0 0 0 3px rgba(74,127,212,.12); }

        .eye-btn { position:absolute; right:.75rem; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:var(--gray-500); padding:.2rem; line-height:0; }

        .btn {
            width:100%; height:50px; margin-top:.5rem;
            background:var(--accent); color:#fff;
            border:none; border-radius:var(--radius-sm);
            font-family:'Outfit',sans-serif; font-size:.97rem; font-weight:700;
            cursor:pointer; letter-spacing:.3px;
            display:flex; align-items:center; justify-content:center; gap:.5rem;
            transition:background .2s, transform .15s, box-shadow .2s;
            box-shadow:0 4px 14px rgba(74,127,212,.25);
        }
        .btn:hover { background:var(--accent-h); box-shadow:0 6px 22px rgba(74,127,212,.35); transform:translateY(-1px); }
        .btn:active { transform:translateY(0); }

        .sep { display:flex; align-items:center; gap:.75rem; margin:1.4rem 0 1rem; color:var(--gray-300); font-size:.72rem; }
        .sep::before, .sep::after { content:''; flex:1; height:1px; background:var(--gray-100); }

        .alt-links { display:flex; flex-direction:column; gap:.3rem; }
        .alt-link { display:flex; align-items:center; justify-content:center; gap:.4rem; font-size:.8rem; color:var(--gray-500); text-decoration:none; padding:.45rem; border-radius:var(--radius-sm); transition:all .2s; }
        .alt-link:hover { color:var(--accent); background:var(--gray-50); }

        footer { text-align:center; padding:1.25rem; font-size:.72rem; color:var(--gray-500); }
    </style>
</head>
<body>

<header>
    <div class="hb-logo">UdeC</div>
    <div>
        <div class="hb-name">Clubes Estudiantiles</div>
        <div class="hb-sub">Bachillerato · Universidad de Colima</div>
    </div>
</header>

<main>
<div class="wrap">
<div class="card">

    <div class="card-top">
        <div class="role-chip">
            <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            Administración de Plantel
        </div>
        <div class="top-icon">
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
        </div>
        <h1>Acceso al panel<br>del plantel</h1>
        <p>Gestiona solicitudes de registro desde aquí</p>
    </div>

    <div class="card-body">

        <?php if ($error): ?>
        <div class="alert-err">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="">

            <div class="form-group">
                <label for="usuario">Usuario del plantel</label>
                <div class="iw">
                    <svg class="icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <input type="text" id="usuario" name="usuario"
                        placeholder="Ej. bach23"
                        value="<?= htmlspecialchars($post_usr) ?>"
                        autocomplete="username"
                        autofocus required>
                </div>
            </div>

            <div class="form-group">
                <label for="contrasena">Contraseña</label>
                <div class="iw">
                    <svg class="icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input type="password" id="contrasena" name="contrasena"
                        placeholder="Tu contraseña de plantel"
                        autocomplete="current-password" required>
                    <button type="button" class="eye-btn" onclick="toggleEye()">
                        <svg id="eye-svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                Entrar al panel
            </button>

        </form>

        <div class="sep">o</div>

        <div class="alt-links">
            <a href="login_encargado.php" class="alt-link">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Soy encargado — ir a mi sesión
            </a>
            <a href="login.php" class="alt-link">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
                Soy alumno — portal de alumnos
            </a>
        </div>

    </div>
</div>
</div>
</main>

<footer>© <?= date('Y') ?> Universidad de Colima · Sistema de Clubes Estudiantiles</footer>

<script>
function toggleEye() {
    var inp = document.getElementById('contrasena');
    var svg = document.getElementById('eye-svg');
    if (inp.type === 'password') {
        inp.type = 'text';
        svg.innerHTML = '<line x1="2" y1="2" x2="22" y2="22"/><path d="M6.71 6.71C3.15 8.99 1 12 1 12s4 8 11 8c2.27 0 4.36-.73 6.11-1.95"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 11 7 11 7a21.77 21.77 0 0 1-1.7 2.47"/><path d="M9.88 9.88A3 3 0 0 0 12 15a3 3 0 0 0 2.12-5.12"/>';
    } else {
        inp.type = 'password';
        svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
}
</script>
</body>
</html>
