<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/enviar_correo.php';

// ── Limpiar tokens expirados ──────────────────────────────────────
try {
    getDB()->exec(
        "UPDATE solicitudes_encargado SET estado = 'rechazado'
         WHERE estado = 'pendiente' AND expira_en < NOW()"
    );
} catch (Exception $e) { /* Ignorar */ }

// ── Leer parámetros ───────────────────────────────────────────────
$token  = trim($_GET['token']  ?? '');
$accion = trim($_GET['accion'] ?? '');   // 'aprobar' | 'rechazar'

$estado  = 'error';   // ok_aprobado | ok_rechazado | ya_procesado | expirado | error
$sol     = null;
$mensaje = 'El enlace no es válido.';

if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)
    || !in_array($accion, ['aprobar', 'rechazar'], true)) {
    $estado  = 'error';
    $mensaje = 'El enlace no es válido o faltan parámetros.';
} else {
    try {
        $pdo  = getDB();

        // Obtener solicitud con datos del plantel
        $stmt = $pdo->prepare("
            SELECT s.*, p.nombre AS plantel_nombre, p.correo AS correo_plantel
            FROM solicitudes_encargado s
            JOIN planteles p ON p.id = s.id_plantel
            WHERE s.token = ?
        ");
        $stmt->execute([$token]);
        $sol = $stmt->fetch();

        if (!$sol) {
            $estado  = 'error';
            $mensaje = 'El enlace de solicitud no existe o ya fue eliminado.';

        } elseif ($sol['estado'] !== 'pendiente') {
            $estado  = 'ya_procesado';
            $mensaje = $sol['estado'] === 'aprobado'
                ? 'Esta solicitud ya fue <strong>aprobada</strong> anteriormente.'
                : 'Esta solicitud ya fue <strong>rechazada</strong> anteriormente.';

        } elseif (strtotime($sol['expira_en']) < time()) {
            $estado  = 'expirado';
            $mensaje = 'El enlace venció. El solicitante debe enviar una nueva solicitud.';

        } elseif ($accion === 'aprobar') {
            // ══ APROBAR ══
            $pdo->beginTransaction();
            $esAlumno = ($sol['tipo'] === 'Estudiante');

            $hash_pass = $sol['contrasena'] ?? null;  // ya viene hasheado desde solicitud

            if ($esAlumno) {
                // ── Alumno: guardar en `personas` (con contraseña) ──
                $nombre_completo_est = $sol['apellido_paterno'] . ' '
                                     . $sol['apellido_materno'] . ' '
                                     . $sol['nombres'];
                // También registramos en personas para tener un registro central
                $pdo->prepare("
                    INSERT INTO personas
                        (id, tipo, nombres, apellido_paterno, apellido_materno, correo, telefono, contrasena, id_plantel)
                    VALUES (?, 'Estudiante', ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        nombres          = VALUES(nombres),
                        apellido_paterno = VALUES(apellido_paterno),
                        apellido_materno = VALUES(apellido_materno),
                        correo           = VALUES(correo),
                        telefono         = VALUES(telefono),
                        contrasena       = VALUES(contrasena),
                        id_plantel       = VALUES(id_plantel)
                ")->execute([
                    (int)$sol['numero_trabajador'],
                    $sol['nombres'],
                    $sol['apellido_paterno'],
                    $sol['apellido_materno'],
                    $sol['correo'],
                    $sol['telefono'] ?: null,
                    $hash_pass,
                    $sol['id_plantel'] ? (int)$sol['id_plantel'] : null,
                ]);
                // Y en estudiantes para las inscripciones a clubs
                $pdo->prepare("
                    INSERT INTO estudiantes (cuenta, nombre_completo, correo, id_plantel)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        nombre_completo = VALUES(nombre_completo),
                        correo          = VALUES(correo),
                        id_plantel      = VALUES(id_plantel)
                ")->execute([
                    (int)$sol['numero_trabajador'],
                    $nombre_completo_est,
                    $sol['correo'],
                    $sol['id_plantel'] ? (int)$sol['id_plantel'] : null,
                ]);

            } else {
                // ── Docente / Administrativo: guardar en `personas` + `encargados` ──
                $pdo->prepare("
                    INSERT INTO personas
                        (id, tipo, nombres, apellido_paterno, apellido_materno,
                         correo, telefono, contrasena, id_plantel)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        tipo             = VALUES(tipo),
                        nombres          = VALUES(nombres),
                        apellido_paterno = VALUES(apellido_paterno),
                        apellido_materno = VALUES(apellido_materno),
                        correo           = VALUES(correo),
                        telefono         = VALUES(telefono),
                        contrasena       = VALUES(contrasena),
                        id_plantel       = VALUES(id_plantel)
                ")->execute([
                    (int)$sol['numero_trabajador'],
                    $sol['tipo'],
                    $sol['nombres'],
                    $sol['apellido_paterno'],
                    $sol['apellido_materno'],
                    $sol['correo'],
                    $sol['telefono'] ?: null,
                    $hash_pass,
                    $sol['id_plantel'] ? (int)$sol['id_plantel'] : null,
                ]);

                $pdo->prepare("
                    INSERT IGNORE INTO encargados (id_persona, id_plantel)
                    VALUES (?, ?)
                ")->execute([
                    (int)$sol['numero_trabajador'],
                    (int)$sol['id_plantel'],
                ]);
            }

            // Marcar solicitud como aprobada
            $pdo->prepare(
                "UPDATE solicitudes_encargado SET estado = 'aprobado' WHERE token = ?"
            )->execute([$token]);

            $pdo->commit();

            // Notificar al solicitante por correo (opcional)
            $nombre_completo = $sol['apellido_paterno'] . ' ' . $sol['apellido_materno'] . ' ' . $sol['nombres'];
            _notificarSolicitante($sol['correo'], $nombre_completo, $sol['plantel_nombre'], 'aprobado');

            $estado  = 'ok_aprobado';
            $mensaje = 'La solicitud fue aprobada correctamente.';

        } else {
            // ══ RECHAZAR ══
            $pdo->prepare(
                "UPDATE solicitudes_encargado SET estado = 'rechazado' WHERE token = ?"
            )->execute([$token]);

            // Notificar al solicitante por correo (opcional)
            $nombre_completo = $sol['apellido_paterno'] . ' ' . $sol['apellido_materno'] . ' ' . $sol['nombres'];
            _notificarSolicitante($sol['correo'], $nombre_completo, $sol['plantel_nombre'], 'rechazado');

            $estado  = 'ok_rechazado';
            $mensaje = 'La solicitud fue rechazada.';
        }

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $estado  = 'error';
        $mensaje = 'Ocurrió un error interno. Intenta de nuevo o contacta al administrador.';
        error_log('aprobar_encargado.php error: ' . $e->getMessage());
    }
}

// ── Notificación al solicitante ───────────────────────────────────
function _notificarSolicitante(
    string $correo,
    string $nombre,
    string $plantel,
    string $resultado
): void {
    if (MAIL_DEMO) {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);
        $linea = date('[Y-m-d H:i:s]')
            . " [NOTIF] Solicitante: $correo | Resultado: $resultado | Plantel: $plantel\n\n";
        file_put_contents($logDir . '/correos_demo.log', $linea, FILE_APPEND);
        return;
    }

    $icono   = $resultado === 'aprobado' ? '✅' : '❌';
    $texto   = $resultado === 'aprobado'
        ? "Tu solicitud para unirte como encargado en <strong>$plantel</strong> fue <strong style='color:#2e9e6e'>aprobada</strong>."
        : "Tu solicitud para unirte como encargado en <strong>$plantel</strong> fue <strong style='color:#d94f4f'>rechazada</strong>. Puedes contactar a la administración del plantel.";

    $html = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;background:#f0f3fa;padding:32px'>
        <div style='max-width:500px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 4px 20px rgba(0,0,0,.1)'>
            <div style='text-align:center;font-size:36px;margin-bottom:16px'>$icono</div>
            <h2 style='font-family:Arial;color:#1b2d54;text-align:center;margin:0 0 12px'>
                Solicitud " . ucfirst($resultado) . "
            </h2>
            <p style='color:#3d4260;font-size:15px;line-height:1.6;margin:0 0 16px'>
                Hola, <strong>" . htmlspecialchars($nombre) . "</strong>:
            </p>
            <p style='color:#3d4260;font-size:14px;line-height:1.6;margin:0 0 24px'>
                $texto
            </p>
            <p style='color:#7a8099;font-size:12px;text-align:center;margin:0'>
                © " . date('Y') . " Universidad de Colima · Sistema de Clubes Estudiantiles
            </p>
        </div>
    </body></html>";

    $asunto  = '=?UTF-8?B?' . base64_encode("Solicitud de encargado — $resultado") . '?=';
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
             . 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . ">\r\n";
    @mail($correo, $asunto, $html, $headers);
}

// ── Helpers visuales ──────────────────────────────────────────────
$nombre_completo = $sol
    ? ($sol['apellido_paterno'] . ' ' . $sol['apellido_materno'] . ' ' . $sol['nombres'])
    : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revisión de Solicitud — Clubes Estudiantiles</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:    #1b2d54;
            --navy-l:  #243567;
            --accent:  #4a7fd4;
            --success: #2e9e6e;
            --error:   #d94f4f;
            --white:   #ffffff;
            --gray-50: #f7f8fc;
            --gray-100:#eef0f6;
            --gray-200:#e0e4f0;
            --gray-500:#7a8099;
            --gray-700:#3d4260;
            --text:    #1e2340;
            --radius:  14px;
            --shadow:  0 12px 48px rgba(27,45,84,.18);
        }
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'DM Sans',sans-serif; background:var(--gray-50); min-height:100vh; display:flex; flex-direction:column; color:var(--text); }

        header { background:var(--navy); height:64px; display:flex; align-items:center; padding:0 2rem; gap:.75rem; box-shadow:0 2px 8px rgba(0,0,0,.25); }
        .hb-logo { width:40px; height:40px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-family:'Outfit',sans-serif; font-weight:700; font-size:.75rem; color:var(--navy); }
        .hb-name { font-family:'Outfit',sans-serif; font-size:1.05rem; font-weight:600; color:#fff; }
        .hb-sub  { font-size:.7rem; color:rgba(255,255,255,.5); text-transform:uppercase; letter-spacing:.8px; }

        main { flex:1; display:flex; align-items:center; justify-content:center; padding:2.5rem 1rem; }

        .card { width:100%; max-width:500px; background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; animation:fadeUp .35s ease both; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }

        .card-top { padding:2rem 2rem 1.6rem; text-align:center; position:relative; overflow:hidden; }
        .card-top::after { content:''; position:absolute; right:-30px; bottom:-50px; width:160px; height:160px; border-radius:50%; background:rgba(255,255,255,.07); }

        .card-top.aprobado  { background: linear-gradient(135deg,#1a6644 0%,#27835a 100%); }
        .card-top.rechazado { background: linear-gradient(135deg,#8b2020 0%,#a33535 100%); }
        .card-top.ya        { background: linear-gradient(135deg,var(--navy) 0%,var(--navy-l) 100%); }
        .card-top.expirado  { background: linear-gradient(135deg,#7a4f10 0%,#9a6520 100%); }
        .card-top.error     { background: linear-gradient(135deg,#8b2020 0%,#a33535 100%); }

        .icon-circle { width:64px; height:64px; border-radius:50%; background:rgba(255,255,255,.18); border:2px solid rgba(255,255,255,.3); display:flex; align-items:center; justify-content:center; margin:0 auto 1.1rem; position:relative; z-index:1; }
        .icon-circle svg { color:#fff; }

        .card-top h1 { font-family:'Outfit',sans-serif; font-size:1.35rem; font-weight:700; color:#fff; line-height:1.3; position:relative; z-index:1; }
        .card-top p  { margin-top:.4rem; font-size:.85rem; color:rgba(255,255,255,.75); position:relative; z-index:1; }

        .card-body { padding:1.75rem 2rem 2rem; }

        /* Ficha persona */
        .ficha { background:var(--gray-50); border:1px solid var(--gray-100); border-radius:10px; padding:1rem 1.2rem; margin-bottom:1.5rem; }
        .ficha-lbl { font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:var(--gray-500); margin-bottom:.75rem; }
        .frow { display:flex; align-items:flex-start; gap:.5rem; padding:.4rem 0; border-bottom:1px solid var(--gray-100); font-size:.84rem; }
        .frow:last-child { border-bottom:none; }
        .frow .lbl { color:var(--gray-500); font-weight:500; min-width:110px; flex-shrink:0; }
        .frow .val { font-family:'Outfit',sans-serif; font-weight:600; }

        /* Badge persona */
        .persona-badge { display:flex; align-items:center; gap:.75rem; background:#f0f6ff; border:1px solid #c8deff; border-radius:10px; padding:.85rem 1rem; margin-bottom:1.5rem; }
        .av { width:42px; height:42px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-family:'Outfit',sans-serif; font-weight:700; font-size:.9rem; color:#fff; flex-shrink:0; }
        .av.verde  { background:var(--success); }
        .av.rojo   { background:var(--error);   }
        .av.navy   { background:var(--navy);     }
        .av-info { font-size:.83rem; color:#2a4a80; }
        .av-info strong { display:block; font-family:'Outfit',sans-serif; font-size:.9rem; margin-bottom:.1rem; color:#1b2d54; }

        .btn { display:inline-flex; align-items:center; justify-content:center; gap:.45rem; width:100%; height:48px; border:none; border-radius:9px; font-family:'Outfit',sans-serif; font-size:.95rem; font-weight:600; cursor:pointer; text-decoration:none; transition:all .2s; }
        .btn-primary   { background:var(--accent); color:#fff; }
        .btn-primary:hover { background:#3568bf; box-shadow:0 6px 20px rgba(74,127,212,.3); transform:translateY(-1px); }
        .btn-secondary { background:var(--gray-100); color:var(--gray-700); margin-top:.6rem; }
        .btn-secondary:hover { background:var(--gray-200); }

        footer { text-align:center; padding:1.25rem; font-size:.72rem; color:var(--gray-500); }
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
<div class="card">

<?php if ($estado === 'ok_aprobado'): ?>

    <div class="card-top aprobado">
        <div class="icon-circle">
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
        </div>
        <h1>¡Solicitud aprobada!</h1>
        <p>El encargado fue registrado en el sistema</p>
    </div>

    <div class="card-body">
        <div class="persona-badge">
            <div class="av verde">
                <?= mb_substr($sol['nombres'] ?? 'E', 0, 1) . mb_substr($sol['apellido_paterno'] ?? '', 0, 1) ?>
            </div>
            <div class="av-info">
                <strong><?= htmlspecialchars($nombre_completo) ?></strong>
                <?= htmlspecialchars($sol['tipo']) ?> &nbsp;·&nbsp; Núm. <?= htmlspecialchars($sol['numero_trabajador']) ?>
            </div>
        </div>

        <div class="ficha">
            <div class="ficha-lbl">Datos registrados</div>
            <div class="frow"><span class="lbl">Plantel</span>   <span class="val"><?= htmlspecialchars($sol['plantel_nombre']) ?></span></div>
            <div class="frow"><span class="lbl">Tipo</span>      <span class="val"><?= htmlspecialchars($sol['tipo']) ?></span></div>
            <div class="frow"><span class="lbl">Nombre</span>    <span class="val"><?= htmlspecialchars($nombre_completo) ?></span></div>
            <div class="frow"><span class="lbl">Núm. trabajador</span><span class="val"><?= htmlspecialchars($sol['numero_trabajador']) ?></span></div>
            <div class="frow"><span class="lbl">Correo</span>    <span class="val"><?= htmlspecialchars($sol['correo']) ?></span></div>
            <?php if ($sol['telefono']): ?>
            <div class="frow"><span class="lbl">Teléfono</span>  <span class="val"><?= htmlspecialchars($sol['telefono']) ?></span></div>
            <?php endif; ?>
        </div>

        <p style="font-size:.82rem;color:var(--gray-500);margin-bottom:1.25rem;line-height:1.55;">
            El solicitante recibió una notificación por correo confirmando su aprobación.
            Ya puede iniciar sesión en el sistema como encargado.
        </p>

        <a href="login_encargado.php" class="btn btn-primary">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                <polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
            </svg>
            Ir al inicio de sesión de encargados
        </a>
    </div>

<?php elseif ($estado === 'ok_rechazado'): ?>

    <div class="card-top rechazado">
        <div class="icon-circle">
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
        </div>
        <h1>Solicitud rechazada</h1>
        <p>El acceso fue denegado para este solicitante</p>
    </div>

    <div class="card-body">
        <div class="persona-badge">
            <div class="av rojo">
                <?= mb_substr($sol['nombres'] ?? 'E', 0, 1) . mb_substr($sol['apellido_paterno'] ?? '', 0, 1) ?>
            </div>
            <div class="av-info">
                <strong><?= htmlspecialchars($nombre_completo) ?></strong>
                <?= htmlspecialchars($sol['tipo']) ?> &nbsp;·&nbsp; Núm. <?= htmlspecialchars($sol['numero_trabajador']) ?>
            </div>
        </div>

        <p style="font-size:.86rem;color:var(--gray-700);margin-bottom:1.4rem;line-height:1.6;">
            La solicitud de <strong><?= htmlspecialchars($nombre_completo) ?></strong> para el plantel
            <strong><?= htmlspecialchars($sol['plantel_nombre']) ?></strong> fue rechazada.
            El solicitante recibió una notificación por correo.
        </p>

        <a href="login_encargado.php" class="btn btn-primary">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                <polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
            </svg>
            Ir al inicio de sesión
        </a>
    </div>

<?php elseif ($estado === 'ya_procesado'): ?>

    <div class="card-top ya">
        <div class="icon-circle">
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
        </div>
        <h1>Ya procesada</h1>
        <p>Esta solicitud fue atendida anteriormente</p>
    </div>

    <div class="card-body">
        <p style="font-size:.88rem;color:var(--gray-700);margin-bottom:1.4rem;line-height:1.6;">
            <?= $mensaje ?>
        </p>
        <a href="login_encargado.php" class="btn btn-primary">Ir al inicio de sesión</a>
    </div>

<?php elseif ($estado === 'expirado'): ?>

    <div class="card-top expirado">
        <div class="icon-circle">
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
        </div>
        <h1>Enlace expirado</h1>
        <p>El tiempo de vigencia de 24 horas venció</p>
    </div>

    <div class="card-body">
        <p style="font-size:.88rem;color:var(--gray-700);margin-bottom:1.4rem;line-height:1.6;">
            <?= htmlspecialchars($mensaje) ?> El solicitante puede volver a enviar su solicitud desde
            <a href="solicitud_encargado.php" style="color:var(--accent)">solicitud_encargado.php</a>.
        </p>
        <a href="login_encargado.php" class="btn btn-primary">Inicio de sesión</a>
    </div>

<?php else: /* error */ ?>

    <div class="card-top error">
        <div class="icon-circle">
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
        </div>
        <h1>Enlace no válido</h1>
        <p>No se pudo procesar la solicitud</p>
    </div>

    <div class="card-body">
        <p style="font-size:.88rem;color:var(--gray-700);margin-bottom:1.4rem;line-height:1.6;">
            <?= htmlspecialchars($mensaje) ?>
        </p>
        <a href="login_encargado.php" class="btn btn-primary">Ir al inicio</a>
    </div>

<?php endif; ?>

</div>
</main>

<footer>
    © <?= date('Y') ?> Universidad de Colima — Bachillerato 23 &nbsp;|&nbsp; Sistema de Clubes Estudiantiles
</footer>

</body>
</html>
