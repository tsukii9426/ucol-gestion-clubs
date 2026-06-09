<?php
/**
 * setup_plantel.php
 *
 * Utilidad para configurar el usuario y contraseña de acceso
 * de cada plantel al panel de administración.
 *
 * ⚠️ SOLO ACCESIBLE DESDE LOCALHOST.
 * Elimina o protege este archivo cuando el sistema esté en producción.
 */

// ── Seguridad: solo localhost ────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) {
    http_response_code(403);
    die('❌ Acceso denegado. Esta página solo es accesible desde localhost.');
}

require_once __DIR__ . '/db.php';

$msg_ok  = '';
$msg_err = '';
$planteles = [];

try {
    $planteles = getDB()
        ->query('SELECT id, nombre, usuario, correo, contrasena_app,
                        CASE WHEN contrasena_cuenta IS NOT NULL AND contrasena_cuenta != "" AND contrasena_cuenta != "$2y$12$placeholder" THEN 1 ELSE 0 END AS tiene_pass
                 FROM planteles ORDER BY nombre')
        ->fetchAll();
} catch (Exception $e) {
    $msg_err = 'Error de conexión: ' . $e->getMessage();
}

// ── Procesar formularios ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // — Establecer / cambiar contraseña de plantel —
    if ($accion === 'set_pass') {
        $id         = (int)($_POST['plantel_id'] ?? 0);
        $usuario    = trim($_POST['usuario']     ?? '');
        $nueva      = $_POST['nueva_pass']       ?? '';
        $confirmar  = $_POST['confirmar_pass']   ?? '';

        if (!$id || !$usuario) {
            $msg_err = 'Selecciona un plantel e ingresa un usuario.';
        } elseif (strlen($nueva) < 8) {
            $msg_err = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif ($nueva !== $confirmar) {
            $msg_err = 'Las contraseñas no coinciden.';
        } else {
            try {
                $hash = password_hash($nueva, PASSWORD_DEFAULT);
                getDB()->prepare(
                    'UPDATE planteles SET usuario = ?, contrasena_cuenta = ? WHERE id = ?'
                )->execute([$usuario, $hash, $id]);
                $msg_ok = "✅ Contraseña del plantel <strong>#$id</strong> actualizada correctamente. Usuario: <code>$usuario</code>";
                // Recargar planteles
                $planteles = getDB()
                    ->query('SELECT id, nombre, usuario, correo, contrasena_app,
                                    CASE WHEN contrasena_cuenta IS NOT NULL AND contrasena_cuenta != "" AND contrasena_cuenta != "$2y$12$placeholder" THEN 1 ELSE 0 END AS tiene_pass
                             FROM planteles ORDER BY nombre')
                    ->fetchAll();
            } catch (Exception $e) {
                $msg_err = 'Error al guardar: ' . $e->getMessage();
            }
        }
    }

    // — Configurar contraseña de app SMTP —
    if ($accion === 'set_smtp') {
        $id      = (int)($_POST['plantel_id'] ?? 0);
        $correo  = trim($_POST['correo_smtp'] ?? '');
        $app_pass= trim($_POST['app_pass']    ?? '');

        if (!$id || !$correo) {
            $msg_err = 'Selecciona un plantel e ingresa el correo.';
        } else {
            try {
                getDB()->prepare(
                    'UPDATE planteles SET correo = ?, contrasena_app = ? WHERE id = ?'
                )->execute([$correo, $app_pass ?: null, $id]);
                $msg_ok = "✅ SMTP del plantel <strong>#$id</strong> actualizado. Correo: <code>$correo</code>";
                $planteles = getDB()
                    ->query('SELECT id, nombre, usuario, correo, contrasena_app,
                                    CASE WHEN contrasena_cuenta IS NOT NULL AND contrasena_cuenta != "" AND contrasena_cuenta != "$2y$12$placeholder" THEN 1 ELSE 0 END AS tiene_pass
                             FROM planteles ORDER BY nombre')
                    ->fetchAll();
            } catch (Exception $e) {
                $msg_err = 'Error al guardar: ' . $e->getMessage();
            }
        }
    }

    // — Agregar nuevo plantel —
    if ($accion === 'nuevo_plantel') {
        $nombre  = trim($_POST['nombre']  ?? '');
        $usuario = trim($_POST['usuario'] ?? '');
        $correo  = trim($_POST['correo']  ?? '');
        $pass    = $_POST['pass']         ?? '';
        $confirmar = $_POST['confirmar']  ?? '';

        if (!$nombre || !$usuario || !$correo || !$pass) {
            $msg_err = 'Completa todos los campos para el nuevo plantel.';
        } elseif ($pass !== $confirmar) {
            $msg_err = 'Las contraseñas no coinciden.';
        } elseif (strlen($pass) < 8) {
            $msg_err = 'Contraseña mínima: 8 caracteres.';
        } else {
            try {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                getDB()->prepare(
                    'INSERT INTO planteles (nombre, usuario, contrasena_cuenta, correo) VALUES (?,?,?,?)'
                )->execute([$nombre, $usuario, $hash, $correo]);
                $msg_ok = "✅ Plantel <strong>" . htmlspecialchars($nombre) . "</strong> creado correctamente.";
                $planteles = getDB()
                    ->query('SELECT id, nombre, usuario, correo, contrasena_app,
                                    CASE WHEN contrasena_cuenta IS NOT NULL AND contrasena_cuenta != "" AND contrasena_cuenta != "$2y$12$placeholder" THEN 1 ELSE 0 END AS tiene_pass
                             FROM planteles ORDER BY nombre')
                    ->fetchAll();
            } catch (Exception $e) {
                $msg_err = 'Error: ' . $e->getMessage();
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
    <title>Setup de Planteles — Solo Localhost</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root { --navy:#1b2d54; --accent:#4a7fd4; --success:#2e9e6e; --error:#d94f4f; --warning:#d47a20; }
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'DM Sans',sans-serif; background:#f0f3fa; min-height:100vh; padding:2rem 1rem 4rem; }
        .wrap { max-width:860px; margin:0 auto; }

        .page-header { background:var(--navy); border-radius:14px; padding:1.5rem 2rem; margin-bottom:2rem; display:flex; align-items:center; gap:1rem; }
        .page-header h1 { font-family:'Outfit',sans-serif; font-size:1.3rem; font-weight:700; color:#fff; }
        .page-header p  { font-size:.82rem; color:rgba(255,255,255,.65); margin-top:.2rem; }
        .warn-badge { background:#d47a20; color:#fff; border-radius:20px; padding:.2rem .75rem; font-size:.7rem; font-weight:700; font-family:'Outfit',sans-serif; text-transform:uppercase; letter-spacing:.8px; }

        .alert { border-radius:10px; padding:.9rem 1.1rem; font-size:.84rem; margin-bottom:1.5rem; display:flex; gap:.6rem; align-items:flex-start; line-height:1.55; }
        .alert-ok  { background:#edfaf4; border:1px solid #a5dfca; border-left:3px solid var(--success); color:#1a5e3f; }
        .alert-err { background:#fff5f5; border:1px solid #fbd5d5; border-left:3px solid var(--error);   color:#8b2020; }

        .card { background:#fff; border-radius:14px; box-shadow:0 4px 20px rgba(27,45,84,.09); margin-bottom:1.5rem; overflow:hidden; }
        .card-hdr { padding:1rem 1.5rem; border-bottom:1px solid #eef0f6; display:flex; align-items:center; gap:.6rem; }
        .card-hdr h2 { font-family:'Outfit',sans-serif; font-size:.95rem; font-weight:700; }
        .card-body { padding:1.5rem; }

        /* Tabla de planteles */
        table { width:100%; border-collapse:collapse; font-size:.84rem; }
        thead tr { background:#f7f8fc; border-bottom:1.5px solid #e0e4f0; }
        thead th { padding:.7rem 1rem; text-align:left; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:#7a8099; }
        tbody tr { border-bottom:1px solid #eef0f6; }
        tbody tr:last-child { border-bottom:none; }
        tbody td { padding:.85rem 1rem; vertical-align:middle; }
        .chip-ok  { background:#e8faf3; color:var(--success); border:1px solid #a5dfca; border-radius:20px; padding:.18rem .6rem; font-size:.7rem; font-weight:700; }
        .chip-warn{ background:#fff3e8; color:var(--warning); border:1px solid #f5d8a0; border-radius:20px; padding:.18rem .6rem; font-size:.7rem; font-weight:700; }
        .plantel-id { font-family:'Outfit',sans-serif; font-size:.7rem; font-weight:700; color:#7a8099; }

        /* Formularios */
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:.85rem; }
        .fg { margin-bottom:.85rem; }
        label { display:block; font-size:.73rem; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:#3d4260; margin-bottom:.35rem; }
        select, input[type=text], input[type=password], input[type=email] {
            width:100%; height:42px; padding:0 .9rem;
            border:1.5px solid #eef0f6; border-radius:8px;
            font-family:'DM Sans',sans-serif; font-size:.9rem;
            background:#f7f8fc; outline:none; appearance:none;
            transition:border-color .2s, box-shadow .2s;
        }
        input:focus, select:focus { border-color:var(--accent); background:#fff; box-shadow:0 0 0 3px rgba(74,127,212,.12); }
        .btn { height:42px; padding:0 1.4rem; border:none; border-radius:8px; font-family:'Outfit',sans-serif; font-size:.88rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:.4rem; transition:all .2s; margin-top:.5rem; }
        .btn-primary { background:var(--accent); color:#fff; }
        .btn-primary:hover { background:#3568bf; box-shadow:0 4px 14px rgba(74,127,212,.3); }
        .btn-green   { background:var(--success); color:#fff; }
        .btn-green:hover { background:#23825a; }

        .section-sep { height:1px; background:#eef0f6; margin:1.5rem 0; }
        .hint { font-size:.73rem; color:#7a8099; margin-top:.3rem; }
        code { background:#e0e4f0; padding:.1rem .35rem; border-radius:4px; font-size:.82em; }

        @media(max-width:600px) { .form-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="wrap">

    <div class="page-header">
        <div style="flex:1">
            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.4rem">
                <h1>⚙️ Setup de Planteles</h1>
                <span class="warn-badge">Solo Localhost</span>
            </div>
            <p>Configura usuarios, contraseñas de acceso y credenciales SMTP de cada plantel. Elimina este archivo en producción.</p>
        </div>
        <a href="ucol-srvc-coord.php" style="color:rgba(255,255,255,.7);font-size:.8rem;text-decoration:none;border:1px solid rgba(255,255,255,.25);padding:.35rem .85rem;border-radius:8px">
            Ir al login →
        </a>
    </div>

    <?php if ($msg_ok): ?>
    <div class="alert alert-ok"><?= $msg_ok ?></div>
    <?php endif; ?>
    <?php if ($msg_err): ?>
    <div class="alert alert-err"><?= htmlspecialchars($msg_err) ?></div>
    <?php endif; ?>

    <!-- ── TABLA DE PLANTELES ─────────────────────── -->
    <div class="card">
        <div class="card-hdr">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            <h2>Planteles registrados</h2>
        </div>
        <div class="card-body" style="padding:0">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Plantel</th>
                        <th>Usuario</th>
                        <th>Correo SMTP</th>
                        <th>Contraseña acceso</th>
                        <th>Contraseña app SMTP</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($planteles as $p): ?>
                <tr>
                    <td><span class="plantel-id">#<?= $p['id'] ?></span></td>
                    <td><strong><?= htmlspecialchars($p['nombre']) ?></strong></td>
                    <td><code><?= htmlspecialchars($p['usuario'] ?? '—') ?></code></td>
                    <td><?= htmlspecialchars($p['correo']) ?></td>
                    <td>
                        <?php if ($p['tiene_pass']): ?>
                            <span class="chip-ok">✓ Configurada</span>
                        <?php else: ?>
                            <span class="chip-warn">⚠ Sin configurar</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['contrasena_app']): ?>
                            <span class="chip-ok">✓ Configurada</span>
                        <?php else: ?>
                            <span class="chip-warn">⚠ Vacía</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($planteles)): ?>
                <tr><td colspan="6" style="text-align:center;padding:1.5rem;color:#7a8099">No hay planteles registrados</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── SET CONTRASEÑA DE ACCESO ──────────────── -->
    <div class="card">
        <div class="card-hdr">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <h2>Establecer contraseña de acceso al panel</h2>
        </div>
        <div class="card-body">
            <p style="font-size:.83rem;color:#7a8099;margin-bottom:1.25rem">
                Esta contraseña se usa para que el administrador del plantel entre a <code>ucol-srvc-coord.php</code> y gestione las solicitudes.
            </p>
            <form method="POST" action="">
                <input type="hidden" name="accion" value="set_pass">
                <div class="form-grid">
                    <div class="fg">
                        <label>Plantel</label>
                        <select name="plantel_id" required>
                            <option value="">Selecciona un plantel…</option>
                            <?php foreach ($planteles as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Usuario de acceso</label>
                        <input type="text" name="usuario" placeholder="Ej. bach23" required>
                        <p class="hint">Con este usuario inicia sesión en el panel del plantel</p>
                    </div>
                    <div class="fg">
                        <label>Nueva contraseña</label>
                        <input type="password" name="nueva_pass" placeholder="Mín. 8 caracteres" required>
                    </div>
                    <div class="fg">
                        <label>Confirmar contraseña</label>
                        <input type="password" name="confirmar_pass" placeholder="Repite la contraseña" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                    Guardar contraseña
                </button>
            </form>
        </div>
    </div>

    <!-- ── SET SMTP ───────────────────────────────── -->
    <div class="card">
        <div class="card-hdr">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <h2>Configurar correo SMTP del plantel</h2>
        </div>
        <div class="card-body">
            <p style="font-size:.83rem;color:#7a8099;margin-bottom:1.25rem">
                El correo y contraseña de aplicación se usan para <strong>enviar correos desde la cuenta del plantel</strong> (confirmaciones e inscripciones).
            </p>
            <form method="POST" action="">
                <input type="hidden" name="accion" value="set_smtp">
                <div class="form-grid">
                    <div class="fg">
                        <label>Plantel</label>
                        <select name="plantel_id" required>
                            <option value="">Selecciona un plantel…</option>
                            <?php foreach ($planteles as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?> — <?= htmlspecialchars($p['correo']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Correo Gmail del plantel</label>
                        <input type="email" name="correo_smtp" placeholder="Ej. bach23@gmail.com" required>
                    </div>
                    <div class="fg" style="grid-column:1/-1">
                        <label>Contraseña de aplicación Gmail (16 caracteres)</label>
                        <input type="text" name="app_pass" placeholder="Ej. abcd efgh ijkl mnop" autocomplete="off">
                        <p class="hint">Genérala en: Google → Seguridad → Verificación en 2 pasos → Contraseñas de aplicaciones</p>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                    Guardar configuración SMTP
                </button>
            </form>
        </div>
    </div>

    <!-- ── NUEVO PLANTEL ──────────────────────────── -->
    <div class="card">
        <div class="card-hdr">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <h2>Agregar nuevo plantel</h2>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="accion" value="nuevo_plantel">
                <div class="form-grid">
                    <div class="fg">
                        <label>Nombre del plantel</label>
                        <input type="text" name="nombre" placeholder="Ej. BACHILLERATO 5" required>
                    </div>
                    <div class="fg">
                        <label>Usuario de acceso</label>
                        <input type="text" name="usuario" placeholder="Ej. bach05" required>
                    </div>
                    <div class="fg">
                        <label>Correo Gmail</label>
                        <input type="email" name="correo" placeholder="Ej. bach05@gmail.com" required>
                    </div>
                    <div class="fg">
                        <!-- espacio -->
                    </div>
                    <div class="fg">
                        <label>Contraseña de acceso</label>
                        <input type="password" name="pass" placeholder="Mín. 8 caracteres" required>
                    </div>
                    <div class="fg">
                        <label>Confirmar contraseña</label>
                        <input type="password" name="confirmar" placeholder="Repite la contraseña" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-green">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Crear plantel
                </button>
            </form>
        </div>
    </div>

    <div style="background:#fff3e8;border:1px solid #f5d8a0;border-radius:10px;padding:1rem 1.25rem;font-size:.82rem;color:#7a4f10;line-height:1.6">
        ⚠️ <strong>Recuerda:</strong> Este archivo (<code>setup_plantel.php</code>) solo debe usarse localmente.
        Cuando el sistema esté en producción, elimínalo o protégelo con autenticación adicional.
    </div>

</div>
</body>
</html>
