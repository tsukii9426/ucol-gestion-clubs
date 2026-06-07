<?php
/**
 * recuperar_contrasena.php
 * Paso 1 del flujo de recuperación: el alumno ingresa su número de cuenta
 * y correo. Si coinciden, se genera un token y se envía el enlace de reset.
 *
 * Requiere la tabla tokens_recuperacion:
 * ─────────────────────────────────────────────────────────────────────────
 *  CREATE TABLE IF NOT EXISTS tokens_recuperacion (
 *      id         INT AUTO_INCREMENT PRIMARY KEY,
 *      token      VARCHAR(64)  NOT NULL,
 *      cuenta     BIGINT       NOT NULL,
 *      correo     VARCHAR(255) NOT NULL,
 *      expira_en  DATETIME     NOT NULL,
 *      usado      TINYINT(1)   NOT NULL DEFAULT 0,
 *      creado_en  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
 *      UNIQUE KEY uq_token (token),
 *      INDEX idx_cuenta (cuenta)
 *  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 * ─────────────────────────────────────────────────────────────────────────
 */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/enviar_correo.php';

$msg_ok  = '';
$msg_err = '';
$post    = ['cuenta' => '', 'correo' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cuenta = trim($_POST['cuenta'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $post   = ['cuenta' => $cuenta, 'correo' => $correo];

    if (!$cuenta || !ctype_digit($cuenta)) {
        $msg_err = 'Ingresa un número de cuenta válido.';
    } elseif (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $msg_err = 'Ingresa un correo electrónico válido.';
    } else {
        try {
            $pdo = getDB();

            // Verificar que la cuenta + correo coincidan
            $stmt = $pdo->prepare(
                'SELECT cuenta, correo, id_plantel FROM estudiantes
                 WHERE cuenta = ? AND LOWER(correo) = LOWER(?) LIMIT 1'
            );
            $stmt->execute([(int)$cuenta, $correo]);
            $alumno = $stmt->fetch();

            if (!$alumno) {
                // Mensaje genérico para no revelar si la cuenta existe
                $msg_err = 'No encontramos una cuenta con esos datos. '
                         . 'Verifica tu número de cuenta y correo.';
            } else {
                // Obtener SMTP del plantel del alumno
                $smtp = [];
                if (!empty($alumno['id_plantel'])) {
                    $sp = $pdo->prepare('SELECT correo, contrasena_app FROM planteles WHERE id = ? LIMIT 1');
                    $sp->execute([$alumno['id_plantel']]);
                    $p = $sp->fetch();
                    if ($p) $smtp = ['correo' => $p['correo'], 'contrasena_app' => $p['contrasena_app']];
                }

                // Eliminar tokens anteriores no usados de esta cuenta
                $pdo->prepare(
                    'DELETE FROM tokens_recuperacion WHERE cuenta = ? AND usado = 0'
                )->execute([(int)$cuenta]);

                // Generar nuevo token
                $token    = bin2hex(random_bytes(32));   // 64 hex chars
                $expira   = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $pdo->prepare(
                    'INSERT INTO tokens_recuperacion (token, cuenta, correo, expira_en)
                     VALUES (?, ?, ?, ?)'
                )->execute([$token, (int)$cuenta, $correo, $expira]);

                $link = BASE_URL . '/reset_contrasena.php?token=' . $token;

                // Enviar correo usando SMTP del plantel
                $enviado = enviarCorreoRecuperacion([
                    'correo'        => $correo,
                    'numero_cuenta' => $cuenta,
                    'link_reset'    => $link,
                ], $smtp);

                if ($enviado) {
                    $msg_ok = 'Te enviamos un enlace de recuperación a <strong>'
                            . htmlspecialchars($correo) . '</strong>. '
                            . 'Revisa tu bandeja (y carpeta de spam). El enlace es válido por <strong>1 hora</strong>.';
                    $post = ['cuenta' => '', 'correo' => ''];
                } else {
                    $msg_err = 'No se pudo enviar el correo. Revisa '
                             . '<code>logs/mail_errores.log</code> o intenta más tarde.';
                }
            }
        } catch (Exception $e) {
            error_log('recuperar_contrasena error: ' . $e->getMessage());
            $msg_err = 'Error interno. Intenta de nuevo más tarde.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar contraseña — Bachillerato 23</title>
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
        input[type="text"],input[type="email"]{width:100%;height:46px;padding:0 1rem 0 2.6rem;border:1.5px solid var(--gray-100);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.92rem;color:var(--text);background:var(--gray-50);transition:border-color .2s,box-shadow .2s,background .2s;outline:none;appearance:none}
        input:focus{border-color:var(--accent);background:var(--white);box-shadow:0 0 0 3px rgba(74,127,212,.12)}
        .hint{font-size:.74rem;color:var(--gray-500);margin-top:.35rem}
        .btn{width:100%;height:48px;background:var(--accent);color:#fff;border:none;border-radius:var(--radius-sm);font-family:'Outfit',sans-serif;font-size:.95rem;font-weight:600;cursor:pointer;transition:background .2s,transform .15s,box-shadow .2s;margin-top:.5rem;display:flex;align-items:center;justify-content:center;gap:.5rem}
        .btn:hover{background:var(--accent-h);box-shadow:0 6px 20px rgba(74,127,212,.3);transform:translateY(-1px)}
        .btn:active{transform:translateY(0)}
        hr.div{border:none;border-top:1px solid var(--gray-100);margin:1.4rem 0 1.2rem}
        .back{text-align:center;font-size:.8rem;color:var(--gray-500)}
        .back a{color:var(--accent);font-weight:500;text-decoration:none}
        .back a:hover{text-decoration:underline}
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
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </div>
                <h1>Recuperar contraseña</h1>
                <p>Te enviaremos un enlace para restablecerla</p>
            </div>
            <div class="card-body">

                <?php if ($msg_err): ?>
                <div class="alert alert-err">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span><?= htmlspecialchars($msg_err) ?></span>
                </div>
                <?php endif; ?>

                <?php if ($msg_ok): ?>
                <div class="alert alert-ok">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <div><?= $msg_ok ?></div>
                </div>
                <?php endif; ?>

                <?php if (!$msg_ok): ?>
                <form method="POST" action="">

                    <div class="fg">
                        <label for="cuenta">Número de cuenta</label>
                        <div class="iw">
                            <svg class="fi" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                            <input type="text" id="cuenta" name="cuenta"
                                placeholder="Ej. 20231113"
                                value="<?= htmlspecialchars($post['cuenta']) ?>"
                                maxlength="10" inputmode="numeric"
                                oninput="this.value=this.value.replace(/\D/g,'')"
                                required>
                        </div>
                    </div>

                    <div class="fg">
                        <label for="correo">Correo electrónico registrado</label>
                        <div class="iw">
                            <svg class="fi" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            <input type="email" id="correo" name="correo"
                                placeholder="Ej. juan.perez@ucol.edu.mx"
                                value="<?= htmlspecialchars($post['correo']) ?>"
                                required>
                        </div>
                        <p class="hint">Debe coincidir con el correo registrado en tu cuenta</p>
                    </div>

                    <button type="submit" class="btn">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Enviar enlace de recuperación
                    </button>

                </form>
                <?php endif; ?>

                <hr class="div">
                <p class="back">
                    <a href="login.php">← Volver al inicio de sesión</a>
                </p>
            </div>
        </div>
    </div>
</main>
<footer>© <?= date('Y') ?> Universidad de Colima — Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>
</body>
</html>
