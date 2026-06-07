<?php
/**
 * reset_contrasena.php
 * Paso 2 del flujo de recuperación: el alumno llega desde el enlace del correo,
 * valida el token y establece una nueva contraseña.
 *
 * GET  ?token=<64-hex>  → muestra el formulario (o error si inválido/expirado)
 * POST token + contrasena + confirmar → actualiza la contraseña y redirige
 */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

$token     = trim($_GET['token'] ?? $_POST['token'] ?? '');
$msg_err   = '';
$token_ok  = false;   // el token es válido y podemos mostrar el form
$row_token = null;    // fila de tokens_recuperacion

// ─── Validación básica del token ────────────────────────────────────────────
if (!$token || !ctype_xdigit($token) || strlen($token) !== 64) {
    $msg_err = 'El enlace es inválido o está incompleto.';
} else {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT id, cuenta, correo, expira_en
             FROM tokens_recuperacion
             WHERE token = ? AND usado = 0 AND expira_en > NOW()
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $row_token = $stmt->fetch();

        if (!$row_token) {
            // Token no existe, ya fue usado, o expiró
            $msg_err = 'El enlace de recuperación ha expirado o ya fue utilizado.';
        } else {
            $token_ok = true;
        }
    } catch (Exception $e) {
        error_log('reset_contrasena token check: ' . $e->getMessage());
        $msg_err = 'Error interno. Intenta de nuevo más tarde.';
    }
}

// ─── Procesamiento del formulario POST ──────────────────────────────────────
$msg_ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_ok) {
    $contrasena = $_POST['contrasena']  ?? '';
    $confirmar  = $_POST['confirmar']   ?? '';

    if (strlen($contrasena) < 6) {
        $msg_err  = 'La contraseña debe tener al menos 6 caracteres.';
        $token_ok = true;   // mantener form visible
    } elseif ($contrasena !== $confirmar) {
        $msg_err  = 'Las contraseñas no coinciden. Vuelve a escribirlas.';
        $token_ok = true;
    } else {
        try {
            $pdo  = getDB();
            $hash = password_hash($contrasena, PASSWORD_BCRYPT);

            // Actualizar contraseña del estudiante
            $pdo->prepare(
                'UPDATE estudiantes SET contrasena = ? WHERE cuenta = ?'
            )->execute([$hash, (int)$row_token['cuenta']]);

            // Marcar el token como usado
            $pdo->prepare(
                'UPDATE tokens_recuperacion SET usado = 1 WHERE token = ?'
            )->execute([$token]);

            // Redirigir al login con mensaje de éxito
            $_SESSION['flash_ok'] = '¡Contraseña actualizada correctamente! Ya puedes iniciar sesión.';
            header('Location: login.php');
            exit;
        } catch (Exception $e) {
            error_log('reset_contrasena update: ' . $e->getMessage());
            $msg_err  = 'Error interno al guardar la contraseña. Intenta de nuevo.';
            $token_ok = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva contraseña — Bachillerato 23</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:#1b2d54; --navy-light:#243567; --accent:#4a7fd4; --accent-h:#3568bf;
            --success:#2e9e6e; --white:#fff; --gray-50:#f7f8fc; --gray-100:#eef0f6;
            --gray-300:#c5cad8; --gray-500:#7a8099; --gray-700:#3d4260;
            --text:#1e2340; --error:#d94f4f; --radius:12px; --radius-sm:8px;
            --shadow-lg:0 12px 48px rgba(27,45,84,.18);
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'DM Sans',sans-serif;background:var(--gray-50);min-height:100vh;display:flex;flex-direction:column}
        header{background:var(--navy);padding:0 2rem;height:64px;display:flex;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.25)}
        .hb{display:flex;align-items:center;gap:.75rem}
        .hb-logo{width:40px;height:40px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-weight:700;font-size:.75rem;color:var(--navy)}
        .hb-name{font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:600;color:#fff}
        .hb-sub{font-size:.7rem;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.8px}
        main{flex:1;display:flex;align-items:center;justify-content:center;padding:2.5rem 1rem}
        .wrap{width:100%;max-width:400px}
        .card{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow-lg);overflow:hidden}
        .card-top{background:linear-gradient(135deg,var(--navy),var(--navy-light));padding:2rem;position:relative;overflow:hidden}
        .card-top::after{content:'';position:absolute;right:-30px;bottom:-40px;width:130px;height:130px;border-radius:50%;background:rgba(255,255,255,.06)}
        .ct-icon{width:48px;height:48px;background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.25);border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:.9rem}
        .ct-icon svg{color:#fff}
        .card-top h1{font-family:'Outfit',sans-serif;font-size:1.2rem;font-weight:700;color:#fff}
        .card-top p{margin-top:.3rem;font-size:.82rem;color:rgba(255,255,255,.6)}
        .card-body{padding:1.75rem 2rem 2rem}
        .alert{border-radius:var(--radius-sm);padding:.75rem 1rem;font-size:.82rem;display:flex;align-items:flex-start;gap:.5rem;margin-bottom:1.25rem;line-height:1.5}
        .alert svg{flex-shrink:0;margin-top:1px}
        .alert-err{background:#fff5f5;border:1px solid #fbd5d5;border-left:3px solid var(--error);color:#a33333}
        .alert-ok{background:#edfaf4;border:1px solid #a5dfca;border-left:3px solid var(--success);color:#1a5e3f}
        .fg{margin-bottom:1.1rem}
        label{display:block;font-size:.78rem;font-weight:500;color:var(--gray-700);margin-bottom:.45rem;letter-spacing:.3px;text-transform:uppercase}
        .iw{position:relative}
        .fi{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:var(--gray-300);pointer-events:none;transition:color .2s}
        .iw:focus-within .fi{color:var(--accent)}
        .pass-toggle{position:absolute;right:.85rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gray-300);padding:2px;display:flex;align-items:center;transition:color .2s}
        .pass-toggle:hover{color:var(--accent)}
        input[type="password"],input[type="text"].pass-field{width:100%;height:46px;padding:0 2.8rem 0 2.6rem;border:1.5px solid var(--gray-100);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.92rem;color:var(--text);background:var(--gray-50);transition:border-color .2s,box-shadow .2s,background .2s;outline:none;appearance:none}
        input[type="password"]:focus,input[type="text"].pass-field:focus{border-color:var(--accent);background:var(--white);box-shadow:0 0 0 3px rgba(74,127,212,.12)}
        .strength-bar{height:4px;border-radius:2px;background:var(--gray-100);margin-top:.4rem;overflow:hidden}
        .strength-fill{height:100%;width:0;border-radius:2px;transition:width .3s,background .3s}
        .hint{font-size:.74rem;color:var(--gray-500);margin-top:.35rem}
        .btn{width:100%;height:48px;background:var(--accent);color:#fff;border:none;border-radius:var(--radius-sm);font-family:'Outfit',sans-serif;font-size:.95rem;font-weight:600;cursor:pointer;transition:background .2s,transform .15s,box-shadow .2s;margin-top:.5rem;display:flex;align-items:center;justify-content:center;gap:.5rem}
        .btn:hover{background:var(--accent-h);box-shadow:0 6px 20px rgba(74,127,212,.3);transform:translateY(-1px)}
        .btn:active{transform:translateY(0)}
        hr.div{border:none;border-top:1px solid var(--gray-100);margin:1.4rem 0 1.2rem}
        .back{text-align:center;font-size:.8rem;color:var(--gray-500)}
        .back a{color:var(--accent);font-weight:500;text-decoration:none}
        .back a:hover{text-decoration:underline}
        /* Estado inválido / expirado */
        .expired-icon{width:64px;height:64px;background:#fff5f5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem}
        .expired-icon svg{color:var(--error)}
        .expired-msg{text-align:center}
        .expired-msg h2{font-family:'Outfit',sans-serif;font-size:1.05rem;font-weight:600;color:var(--text);margin-bottom:.5rem}
        .expired-msg p{font-size:.85rem;color:var(--gray-500);line-height:1.55}
        footer{text-align:center;padding:1.25rem;font-size:.72rem;color:var(--gray-500)}
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
    <div class="wrap">
        <div class="card">
            <div class="card-top">
                <div class="ct-icon">
                    <?php if ($token_ok): ?>
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    <?php else: ?>
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    <?php endif; ?>
                </div>
                <h1><?= $token_ok ? 'Nueva contraseña' : 'Enlace no válido' ?></h1>
                <p><?= $token_ok ? 'Elige una contraseña segura para tu cuenta' : 'Este enlace no se puede utilizar' ?></p>
            </div>

            <div class="card-body">

                <?php if ($msg_err && !$token_ok): ?>
                <!-- ─── Estado: token inválido / expirado ──────────────────── -->
                <div class="expired-msg">
                    <div class="expired-icon">
                        <svg width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                    </div>
                    <h2>Enlace inválido o expirado</h2>
                    <p><?= htmlspecialchars($msg_err) ?><br><br>
                       Los enlaces de recuperación son válidos por <strong>1 hora</strong>.<br>
                       Puedes solicitar uno nuevo.</p>
                </div>
                <hr class="div">
                <p class="back" style="margin-bottom:.5rem">
                    <a href="recuperar_contrasena.php">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:3px"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Solicitar nuevo enlace
                    </a>
                </p>
                <p class="back">
                    <a href="login.php">← Volver al inicio de sesión</a>
                </p>

                <?php elseif ($token_ok): ?>
                <!-- ─── Estado: token válido — mostrar formulario ─────────── -->

                <?php if ($msg_err): ?>
                <div class="alert alert-err">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span><?= htmlspecialchars($msg_err) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="reset_contrasena.php" id="frmReset" novalidate>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="fg">
                        <label for="contrasena">Nueva contraseña</label>
                        <div class="iw">
                            <svg class="fi" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <rect x="3" y="11" width="18" height="11" rx="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            <input type="password" id="contrasena" name="contrasena"
                                class="pass-field" placeholder="Mínimo 6 caracteres"
                                minlength="6" required autocomplete="new-password"
                                oninput="actualizarFuerza(this.value)">
                            <button type="button" class="pass-toggle"
                                onclick="togglePass('contrasena', this)"
                                aria-label="Mostrar contraseña">
                                <svg id="eye-contrasena" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        <div class="strength-bar"><div class="strength-fill" id="strFill"></div></div>
                        <p class="hint" id="strHint">Mínimo 6 caracteres</p>
                    </div>

                    <div class="fg">
                        <label for="confirmar">Confirmar contraseña</label>
                        <div class="iw">
                            <svg class="fi" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <rect x="3" y="11" width="18" height="11" rx="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            <input type="password" id="confirmar" name="confirmar"
                                class="pass-field" placeholder="Repite tu contraseña"
                                minlength="6" required autocomplete="new-password"
                                oninput="validarMatch()">
                            <button type="button" class="pass-toggle"
                                onclick="togglePass('confirmar', this)"
                                aria-label="Mostrar contraseña">
                                <svg id="eye-confirmar" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        <p class="hint" id="matchHint" style="color:var(--gray-500)"></p>
                    </div>

                    <button type="submit" class="btn" id="btnGuardar">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Guardar nueva contraseña
                    </button>
                </form>

                <hr class="div">
                <p class="back">
                    <a href="login.php">← Volver al inicio de sesión</a>
                </p>
                <?php endif; ?>

            </div><!-- /card-body -->
        </div><!-- /card -->
    </div>
</main>

<footer>© <?= date('Y') ?> Universidad de Colima — Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>

<script>
// ─── Indicador de fortaleza de contraseña ──────────────────────────────────
function actualizarFuerza(val) {
    const fill  = document.getElementById('strFill');
    const hint  = document.getElementById('strHint');
    if (!fill) return;

    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const configs = [
        { pct: 0,   color: 'var(--gray-100)', label: 'Mínimo 6 caracteres' },
        { pct: 20,  color: '#d94f4f',          label: 'Muy débil' },
        { pct: 40,  color: '#e87b3a',          label: 'Débil' },
        { pct: 60,  color: '#e8b63a',          label: 'Regular' },
        { pct: 80,  color: '#5cbb6e',          label: 'Buena' },
        { pct: 100, color: 'var(--success)',   label: 'Muy segura ✓' },
    ];
    const cfg = configs[score] || configs[0];
    fill.style.width      = cfg.pct + '%';
    fill.style.background = cfg.color;
    hint.textContent      = val.length === 0 ? 'Mínimo 6 caracteres' : cfg.label;
    hint.style.color      = val.length === 0 ? 'var(--gray-500)' : cfg.color;
}

// ─── Validación de coincidencia en tiempo real ──────────────────────────────
function validarMatch() {
    const p1   = document.getElementById('contrasena').value;
    const p2   = document.getElementById('confirmar').value;
    const hint = document.getElementById('matchHint');
    if (!hint || p2 === '') { hint.textContent = ''; return; }

    if (p1 === p2) {
        hint.textContent = '✓ Las contraseñas coinciden';
        hint.style.color = 'var(--success)';
    } else {
        hint.textContent = 'Las contraseñas no coinciden';
        hint.style.color = 'var(--error)';
    }
}

// ─── Toggle mostrar / ocultar contraseña ───────────────────────────────────
function togglePass(id, btn) {
    const inp = document.getElementById(id);
    const eye = document.getElementById('eye-' + id);
    if (!inp) return;

    const showing = inp.type === 'text';
    inp.type = showing ? 'password' : 'text';

    // Cambiar icono: ojo abierto ↔ ojo tachado
    eye.innerHTML = showing
        ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
        : '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>'
          + '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>'
          + '<line x1="1" y1="1" x2="23" y2="23"/>';
    btn.setAttribute('aria-label', showing ? 'Mostrar contraseña' : 'Ocultar contraseña');
}

// ─── Validación del form antes de enviar ───────────────────────────────────
document.getElementById('frmReset')?.addEventListener('submit', function(e) {
    const p1 = document.getElementById('contrasena').value;
    const p2 = document.getElementById('confirmar').value;

    if (p1.length < 6) {
        e.preventDefault();
        document.getElementById('contrasena').focus();
        return;
    }
    if (p1 !== p2) {
        e.preventDefault();
        document.getElementById('confirmar').focus();
        const hint = document.getElementById('matchHint');
        if (hint) { hint.textContent = 'Las contraseñas no coinciden'; hint.style.color = 'var(--error)'; }
    }
});
</script>
</body>
</html>
