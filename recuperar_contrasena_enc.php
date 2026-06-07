<?php
/**
 * recuperar_contrasena_enc.php
 * Paso 1 del flujo de recuperación para encargados (personal docente/administrativo).
 * Verifica número de trabajador + correo registrado y envía el enlace de reset.
 *
 * Requiere la tabla tokens_recuperacion_enc:
 * ─────────────────────────────────────────────────────────────────────────
 *  CREATE TABLE IF NOT EXISTS tokens_recuperacion_enc (
 *      id         INT AUTO_INCREMENT PRIMARY KEY,
 *      token      VARCHAR(64)  NOT NULL,
 *      id_persona INT          NOT NULL,
 *      correo     VARCHAR(255) NOT NULL,
 *      expira_en  DATETIME     NOT NULL,
 *      usado      TINYINT(1)   NOT NULL DEFAULT 0,
 *      creado_en  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
 *      UNIQUE KEY uq_token (token),
 *      INDEX idx_persona (id_persona)
 *  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 * ─────────────────────────────────────────────────────────────────────────
 */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/enviar_correo.php';

$msg_ok  = '';
$msg_err = '';
$post    = ['numero_trabajador' => '', 'correo' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $num_trabajador = trim($_POST['numero_trabajador'] ?? '');
    $correo         = trim($_POST['correo']            ?? '');
    $post           = ['numero_trabajador' => $num_trabajador, 'correo' => $correo];

    if (!$num_trabajador || !ctype_digit($num_trabajador)) {
        $msg_err = 'Ingresa un número de trabajador válido.';
    } elseif (!$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $msg_err = 'Ingresa un correo electrónico válido.';
    } else {
        try {
            $pdo = getDB();

            // Verificar que sea encargado registrado y que el correo coincida
            $stmt = $pdo->prepare(
                'SELECT p.id, p.correo, p.id_plantel
                 FROM personas p
                 JOIN encargados e ON e.id_persona = p.id
                 WHERE p.id = ? AND LOWER(p.correo) = LOWER(?)
                 LIMIT 1'
            );
            $stmt->execute([(int)$num_trabajador, $correo]);
            $persona = $stmt->fetch();

            if (!$persona) {
                // Mensaje genérico para no revelar si el número existe
                $msg_err = 'No encontramos una cuenta con esos datos. '
                         . 'Verifica tu número de trabajador y correo.';
            } else {
                // Obtener SMTP del plantel del encargado
                $smtp = [];
                if (!empty($persona['id_plantel'])) {
                    $sp = $pdo->prepare('SELECT correo, contrasena_app FROM planteles WHERE id = ? LIMIT 1');
                    $sp->execute([$persona['id_plantel']]);
                    $p = $sp->fetch();
                    if ($p) $smtp = ['correo' => $p['correo'], 'contrasena_app' => $p['contrasena_app']];
                }

                // Eliminar tokens anteriores no usados de esta persona
                $pdo->prepare(
                    'DELETE FROM tokens_recuperacion_enc WHERE id_persona = ? AND usado = 0'
                )->execute([(int)$persona['id']]);

                // Generar nuevo token
                $token  = bin2hex(random_bytes(32));   // 64 hex chars
                $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $pdo->prepare(
                    'INSERT INTO tokens_recuperacion_enc (token, id_persona, correo, expira_en)
                     VALUES (?, ?, ?, ?)'
                )->execute([$token, (int)$persona['id'], $persona['correo'], $expira]);

                $link = BASE_URL . '/reset_contrasena_enc.php?token=' . $token;

                $enviado = enviarCorreoRecuperacionEnc([
                    'correo'         => $persona['correo'],
                    'num_trabajador' => $num_trabajador,
                    'link_reset'     => $link,
                ], $smtp);

                if ($enviado) {
                    $msg_ok = 'Te enviamos un enlace de recuperación a <strong>'
                            . htmlspecialchars($persona['correo']) . '</strong>. '
                            . 'Revisa tu bandeja (y carpeta de spam). El enlace es válido por <strong>1 hora</strong>.';
                    $post = ['numero_trabajador' => '', 'correo' => ''];
                } else {
                    $msg_err = 'No se pudo enviar el correo. Revisa '
                             . '<code>logs/mail_errores.log</code> o intenta más tarde.';
                }
            }
        } catch (Exception $e) {
            error_log('recuperar_contrasena_enc error: ' . $e->getMessage());
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
    <title>Recuperar contraseña — Personal B23</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:#1b2d54; --navy-light:#243567; --accent:#4a7fd4; --accent-h:#3568bf;
            --success:#2e9e6e; --white:#fff; --gray-50:#f7f8fc; --gray-100:#eef0f6;
            --gray-300:#c5cad8; --gray-500:#7a8099; --gray-700:#3d4260;
            --text:#1e2340; --error:#d94f4f; --radius:14px; --radius-sm:8px;
            --shadow-lg:0 16px 48px rgba(27,45,84,.18);
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;flex-direction:column;
             background-color:#f0f3fa;
             background-image:radial-gradient(circle at 15% 25%,rgba(74,127,212,.08) 0%,transparent 50%),
                              radial-gradient(circle at 85% 75%,rgba(27,45,84,.06) 0%,transparent 50%)}
        header{background:var(--navy);height:60px;display:flex;align-items:center;padding:0 2rem;gap:.75rem;box-shadow:0 2px 10px rgba(0,0,0,.2)}
        .hb-logo{width:38px;height:38px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;font-family:'Outfit',sans-serif;font-weight:700;font-size:.74rem;color:var(--navy);flex-shrink:0}
        .hb-name{font-family:'Outfit',sans-serif;font-size:1rem;font-weight:600;color:#fff}
        .hb-sub{font-size:.68rem;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.8px}
        main{flex:1;display:flex;align-items:center;justify-content:center;padding:2.5rem 1rem}
        .wrap{width:100%;max-width:420px}
        .card{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow-lg);overflow:hidden;animation:fadeUp .4s ease both}
        .card-top{background:linear-gradient(135deg,var(--navy),var(--navy-light));padding:2rem;position:relative;overflow:hidden}
        .card-top::before{content:'';position:absolute;right:-35px;top:-35px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.06)}
        .card-top::after{content:'';position:absolute;right:30px;bottom:-55px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.04)}
        .card-top-inner{position:relative;z-index:1}
        .role-badge{display:inline-flex;align-items:center;gap:.4rem;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:20px;padding:.25rem .85rem;font-size:.72rem;font-weight:600;font-family:'Outfit',sans-serif;color:rgba(255,255,255,.9);text-transform:uppercase;letter-spacing:.8px;margin-bottom:1.1rem}
        .card-icon{width:50px;height:50px;background:rgba(255,255,255,.12);border:1.5px solid rgba(255,255,255,.2);border-radius:13px;display:flex;align-items:center;justify-content:center;margin-bottom:1rem}
        .card-top h1{font-family:'Outfit',sans-serif;font-size:1.25rem;font-weight:700;color:#fff;margin-bottom:.35rem}
        .card-top p{font-size:.82rem;color:rgba(255,255,255,.6)}
        .card-body{padding:1.75rem 2rem 2rem}
        .alert{border-radius:var(--radius-sm);padding:.75rem 1rem;font-size:.82rem;display:flex;align-items:flex-start;gap:.5rem;margin-bottom:1.25rem;line-height:1.5}
        .alert svg{flex-shrink:0;margin-top:1px}
        .alert-err{background:#fff5f5;border:1px solid #fbd5d5;border-left:3px solid var(--error);color:#8b2020}
        .alert-ok{background:#edfaf4;border:1px solid #a5dfca;border-left:3px solid var(--success);color:#1a5e3f}
        label{display:block;font-size:.73rem;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--gray-700);margin-bottom:.45rem}
        .iw{position:relative}
        .iw .icon{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:var(--gray-300);pointer-events:none;transition:color .2s}
        .iw:focus-within .icon{color:var(--accent)}
        input[type="text"],input[type="email"]{width:100%;height:54px;padding:0 1rem 0 2.75rem;border:1.5px solid var(--gray-100);border-radius:var(--radius-sm);font-family:'Outfit',sans-serif;font-size:1.1rem;font-weight:600;letter-spacing:.05em;color:var(--text);background:var(--gray-50);transition:border-color .2s,box-shadow .2s,background .2s;outline:none;appearance:none}
        input[type="text"]::placeholder,input[type="email"]::placeholder{font-size:.9rem;font-weight:400;letter-spacing:0;color:var(--gray-300)}
        input:focus{border-color:var(--accent);background:var(--white);box-shadow:0 0 0 3px rgba(74,127,212,.12)}
        .field-gap{margin-top:1.1rem}
        .hint{font-size:.73rem;color:var(--gray-500);margin-top:.4rem;display:flex;align-items:center;gap:.3rem}
        .btn-submit{width:100%;height:50px;margin-top:1.4rem;background:var(--accent);color:#fff;border:none;border-radius:var(--radius-sm);font-family:'Outfit',sans-serif;font-size:.97rem;font-weight:700;letter-spacing:.3px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.5rem;transition:background .2s,transform .15s,box-shadow .2s;box-shadow:0 4px 14px rgba(74,127,212,.25)}
        .btn-submit:hover{background:var(--accent-h);box-shadow:0 6px 22px rgba(74,127,212,.35);transform:translateY(-1px)}
        .btn-submit:active{transform:translateY(0)}
        .sep{display:flex;align-items:center;gap:.75rem;margin:1.4rem 0 .5rem;color:var(--gray-300);font-size:.72rem}
        .sep::before,.sep::after{content:'';flex:1;height:1px;background:var(--gray-100)}
        .alt-link{display:flex;align-items:center;justify-content:center;gap:.4rem;font-size:.8rem;color:var(--gray-500);text-decoration:none;padding:.5rem;border-radius:var(--radius-sm);transition:color .2s,background .2s}
        .alt-link:hover{color:var(--accent);background:var(--gray-50)}
        footer{text-align:center;padding:1.25rem;font-size:.72rem;color:var(--gray-500)}
        @keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
        @media(max-width:460px){.card-body{padding:1.5rem}.card-top{padding:1.5rem}}
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
<div class="wrap">
<div class="card">
    <div class="card-top">
        <div class="card-top-inner">
            <div class="role-badge">
                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                Personal docente / administrativo
            </div>
            <div class="card-icon">
                <svg width="22" height="22" fill="none" stroke="white" stroke-width="1.8" viewBox="0 0 24 24">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </div>
            <h1>Recuperar contraseña</h1>
            <p>Te enviaremos un enlace para restablecerla</p>
        </div>
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

            <label for="numero_trabajador">Número de trabajador</label>
            <div class="iw">
                <svg class="icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M7 15h2M11 15h6"/>
                </svg>
                <input type="text" id="numero_trabajador" name="numero_trabajador"
                    placeholder="Ej. 12345" inputmode="numeric" maxlength="10"
                    value="<?= htmlspecialchars($post['numero_trabajador']) ?>"
                    oninput="this.value=this.value.replace(/\D/g,'')"
                    autofocus required>
            </div>
            <p class="hint">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Tu ID numérico de acceso al sistema
            </p>

            <div class="field-gap">
                <label for="correo">Correo electrónico registrado</label>
                <div class="iw">
                    <svg class="icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>
                    </svg>
                    <input type="email" id="correo" name="correo"
                        placeholder="Ej. nombre.apellido@ucol.mx"
                        value="<?= htmlspecialchars($post['correo']) ?>"
                        required>
                </div>
                <p class="hint">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    Debe coincidir con el correo registrado en tu perfil
                </p>
            </div>

            <button type="submit" class="btn-submit">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>
                </svg>
                Enviar enlace de recuperación
            </button>

        </form>
        <?php endif; ?>

        <div class="sep">o</div>

        <a href="login_encargado.php" class="alt-link">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
            </svg>
            Volver al inicio de sesión
        </a>

    </div>
</div>
</div>
</main>

<footer>© <?= date('Y') ?> Universidad de Colima &nbsp;·&nbsp; Bachillerato 23 &nbsp;|&nbsp; Sistema de Clubes Estudiantiles</footer>
</body>
</html>
