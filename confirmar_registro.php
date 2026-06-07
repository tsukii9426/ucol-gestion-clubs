<?php
session_start();
require_once __DIR__ . '/db.php';

// ── Limpiar tokens expirados (housekeeping rápido) ────────────────
try {
    getDB()->exec("DELETE FROM tokens_pendientes WHERE expira_en < NOW() AND usado = 0");
} catch (Exception $e) { /* Ignorar */ }

// ── Obtener y validar el token ────────────────────────────────────
$token = trim($_GET['token'] ?? '');

$estado  = 'error';      // 'ok' | 'ya_usado' | 'expirado' | 'error'
$club    = null;
$alumno  = null;
$mensaje = 'El enlace no es válido o ha expirado.';

if ($token !== '' && strlen($token) === 64 && ctype_xdigit($token)) {
    try {
        $pdo  = getDB();

        // Traer el token con información del club
        $stmt = $pdo->prepare("
            SELECT t.*,
                   c.nombre        AS club_nombre,
                   c.descripcion   AS club_descripcion,
                   c.limite        AS club_limite,
                   pl.nombre       AS plantel_nombre,
                   GROUP_CONCAT(DISTINCT h.dia ORDER BY h.id SEPARATOR ' / ') AS dias,
                   MIN(h.hora_inicio) AS hora_inicio,
                   MAX(h.hora_fin)    AS hora_fin,
                   COUNT(DISTINCT ic.numero_cuenta) AS inscritos
            FROM tokens_pendientes t
            JOIN clubes c      ON c.id  = t.id_club
            JOIN planteles pl  ON pl.id = c.id_plantel
            LEFT JOIN horarios h          ON h.id_club    = c.id
            LEFT JOIN inscripciones_club ic ON ic.id_club  = c.id
            WHERE t.token = ?
            GROUP BY t.id
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            $estado  = 'error';
            $mensaje = 'El enlace de confirmación no es válido.';

        } elseif ($row['usado']) {
            $estado  = 'ya_usado';
            $mensaje = 'Este enlace ya fue utilizado anteriormente. Tu registro está confirmado.';
            $club    = $row;
            $alumno  = $row;

        } elseif (strtotime($row['expira_en']) < time()) {
            $estado  = 'expirado';
            $mensaje = 'El enlace expiró. Vuelve al sistema y solicita el registro de nuevo.';

        } else {
            // ── Token válido: registrar al alumno ─────────────────
            $cupo_libre = ((int)$row['club_limite'] - (int)$row['inscritos']) > 0;

            if (!$cupo_libre) {
                $estado  = 'error';
                $mensaje = 'Lo sentimos, el club "' . htmlspecialchars($row['club_nombre'])
                         . '" ya no tiene cupo disponible.';
            } else {
                // 1. Insertar o actualizar al estudiante (sin tocar id_club)
                $upsert = $pdo->prepare("
                    INSERT INTO estudiantes (cuenta, nombre_completo, correo, id_plantel)
                    VALUES (:cuenta, :nombre, :correo, :id_plantel)
                    ON DUPLICATE KEY UPDATE
                        nombre_completo = VALUES(nombre_completo),
                        correo          = VALUES(correo),
                        id_plantel      = VALUES(id_plantel)
                ");
                $upsert->execute([
                    ':cuenta'     => (int)$row['numero_cuenta'],
                    ':nombre'     => $row['nombre_completo'],
                    ':correo'     => $row['correo'],
                    ':id_plantel' => $row['id_plantel'] ? (int)$row['id_plantel'] : null,
                ]);

                // 2. Registrar la inscripción al club (ignora si ya existe)
                $pdo->prepare("
                    INSERT IGNORE INTO inscripciones_club (numero_cuenta, id_club)
                    VALUES (?, ?)
                ")->execute([(int)$row['numero_cuenta'], (int)$row['id_club']]);

                // 3. Marcar el token como usado
                $pdo->prepare("UPDATE tokens_pendientes SET usado = 1 WHERE token = ?")
                    ->execute([$token]);

                // 4. Refrescar sesión si el mismo alumno está logueado
                if (!empty($_SESSION['numero_cuenta'])
                    && $_SESSION['numero_cuenta'] == $row['numero_cuenta']) {
                    unset($_SESSION['ya_inscrito']); // obsoleto — dashboard re-consulta BD
                }

                $estado  = 'ok';
                $mensaje = '¡Registro confirmado! Bienvenido al club.';
                $club    = $row;
                $alumno  = $row;
            }
        }

    } catch (Exception $e) {
        $estado  = 'error';
        $mensaje = 'Error interno al procesar tu confirmación. Intenta de nuevo más tarde.';
        error_log('confirmar_registro.php error: ' . $e->getMessage());
    }
}

// ── Helpers de presentación ───────────────────────────────────────
$hi = isset($club['hora_inicio']) ? substr($club['hora_inicio'], 0, 5) : '';
$hf = isset($club['hora_fin'])    ? substr($club['hora_fin'],    0, 5) : '';
$horario = ($hi && $hf) ? "$hi – $hf" : '—';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmación de Registro — Clubes Estudiantiles</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:      #1b2d54;
            --navy-l:    #243567;
            --accent:    #4a7fd4;
            --success:   #2e9e6e;
            --error:     #d94f4f;
            --warning:   #d47a20;
            --white:     #ffffff;
            --gray-50:   #f7f8fc;
            --gray-100:  #eef0f6;
            --gray-200:  #e0e4f0;
            --gray-500:  #7a8099;
            --gray-700:  #3d4260;
            --text:      #1e2340;
            --radius:    14px;
            --shadow-lg: 0 12px 48px rgba(27,45,84,.18);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--gray-50);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: var(--text);
        }

        header {
            background: var(--navy);
            height: 64px;
            display: flex;
            align-items: center;
            padding: 0 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.25);
        }
        .hb { display: flex; align-items: center; gap: .75rem; }
        .hb-logo { width:40px; height:40px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-family:'Outfit',sans-serif; font-weight:700; font-size:.75rem; color:var(--navy); }
        .hb-name { font-family:'Outfit',sans-serif; font-size:1.05rem; font-weight:600; color:#fff; }
        .hb-sub  { font-size:.7rem; color:rgba(255,255,255,.5); text-transform:uppercase; letter-spacing:.8px; }

        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1rem;
        }

        .card {
            width: 100%;
            max-width: 480px;
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: fadeUp .35s ease both;
        }
        @keyframes fadeUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }

        .card-top {
            padding: 2rem 2rem 1.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .card-top::after {
            content: '';
            position: absolute;
            right: -30px; bottom: -50px;
            width: 160px; height: 160px;
            border-radius: 50%;
            background: rgba(255,255,255,.07);
        }

        .card-top.ok      { background: linear-gradient(135deg, #1a6644 0%, #27835a 100%); }
        .card-top.error   { background: linear-gradient(135deg, #8b2020 0%, #a33535 100%); }
        .card-top.ya_usado{ background: linear-gradient(135deg, var(--navy) 0%, var(--navy-l) 100%); }
        .card-top.expirado{ background: linear-gradient(135deg, #7a4f10 0%, #9a6520 100%); }

        .icon-circle {
            width: 64px; height: 64px;
            border-radius: 50%;
            background: rgba(255,255,255,.18);
            border: 2px solid rgba(255,255,255,.3);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.1rem;
            position: relative; z-index: 1;
        }
        .icon-circle svg { color: #fff; }

        .card-top h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.35rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.3;
            position: relative; z-index: 1;
        }
        .card-top p {
            margin-top: .4rem;
            font-size: .85rem;
            color: rgba(255,255,255,.75);
            position: relative; z-index: 1;
        }

        .card-body { padding: 1.75rem 2rem 2rem; }

        /* Ficha del club */
        .club-ficha {
            background: var(--gray-50);
            border: 1px solid var(--gray-100);
            border-radius: 10px;
            padding: 1rem 1.2rem;
            margin-bottom: 1.5rem;
        }
        .ficha-label {
            font-size: .67rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gray-500);
            margin-bottom: .75rem;
        }
        .ficha-row {
            display: flex;
            align-items: flex-start;
            gap: .5rem;
            padding: .4rem 0;
            border-bottom: 1px solid var(--gray-100);
            font-size: .84rem;
        }
        .ficha-row:last-child { border-bottom: none; padding-bottom: 0; }
        .ficha-row .lbl { color: var(--gray-500); font-weight: 500; min-width: 80px; }
        .ficha-row .val { font-family: 'Outfit', sans-serif; font-weight: 600; color: var(--text); }

        /* Alumno badge */
        .alumno-badge {
            display: flex;
            align-items: center;
            gap: .75rem;
            background: #f0f6ff;
            border: 1px solid #c8deff;
            border-radius: 10px;
            padding: .85rem 1rem;
            margin-bottom: 1.5rem;
        }
        .av {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: var(--accent);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-weight: 700; font-size: .9rem; color: #fff;
            flex-shrink: 0;
        }
        .av-info { font-size: .83rem; color: #2a4a80; }
        .av-info strong { display: block; font-family: 'Outfit', sans-serif; font-size: .9rem; margin-bottom: .1rem; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            width: 100%;
            height: 48px;
            border: none;
            border-radius: 9px;
            font-family: 'Outfit', sans-serif;
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all .2s;
        }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: #3568bf; box-shadow: 0 6px 20px rgba(74,127,212,.3); transform: translateY(-1px); }
        .btn-secondary { background: var(--gray-100); color: var(--gray-700); margin-top: .6rem; }
        .btn-secondary:hover { background: var(--gray-200); }

        footer {
            text-align: center;
            padding: 1.25rem;
            font-size: .72rem;
            color: var(--gray-500);
        }

        @media (max-width: 500px) {
            .card-body { padding: 1.4rem 1.25rem 1.5rem; }
        }
    </style>
</head>
<body>

<header>
    <div class="hb">
        <div class="hb-logo">UdeC</div>
        <div>
            <div class="hb-name">Clubes Estudiantiles</div>
            <div class="hb-sub">Bachillerato 23 &nbsp;·&nbsp; Universidad de Colima</div>
        </div>
    </div>
</header>

<main>
<div class="card">

    <?php if ($estado === 'ok'): ?>

    <div class="card-top ok">
        <div class="icon-circle">
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
        </div>
        <h1>¡Registro confirmado!</h1>
        <p>Tu inscripción al club ha sido completada exitosamente</p>
    </div>

    <div class="card-body">

        <!-- Datos del alumno -->
        <?php
            $iniciales = '';
            foreach (array_slice(explode(' ', $alumno['nombre_completo']), 0, 2) as $p) {
                $iniciales .= mb_substr($p, 0, 1);
            }
        ?>
        <div class="alumno-badge">
            <div class="av"><?= htmlspecialchars($iniciales) ?></div>
            <div class="av-info">
                <strong><?= htmlspecialchars($alumno['nombre_completo']) ?></strong>
                No. de cuenta: <?= htmlspecialchars($alumno['numero_cuenta']) ?>
                &nbsp;·&nbsp;
                <?= htmlspecialchars($alumno['correo']) ?>
            </div>
        </div>

        <!-- Detalle del club -->
        <div class="club-ficha">
            <div class="ficha-label">Club inscrito</div>
            <div class="ficha-row">
                <span class="lbl">Club</span>
                <span class="val"><?= htmlspecialchars($club['club_nombre']) ?></span>
            </div>
            <div class="ficha-row">
                <span class="lbl">Plantel</span>
                <span class="val"><?= htmlspecialchars($club['plantel_nombre']) ?></span>
            </div>
            <?php if ($club['dias']): ?>
            <div class="ficha-row">
                <span class="lbl">Días</span>
                <span class="val"><?= htmlspecialchars($club['dias']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($horario !== '—'): ?>
            <div class="ficha-row">
                <span class="lbl">Horario</span>
                <span class="val"><?= htmlspecialchars($horario) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <a href="dashboard_alumno.php" class="btn btn-primary">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
            </svg>
            Ir a mi dashboard
        </a>
    </div>

    <?php elseif ($estado === 'ya_usado'): ?>

    <div class="card-top ya_usado">
        <div class="icon-circle">
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
        </div>
        <h1>Ya confirmado</h1>
        <p>Este enlace ya fue utilizado</p>
    </div>

    <div class="card-body">
        <p style="font-size:.88rem;color:var(--gray-700);margin-bottom:1.4rem;line-height:1.6;">
            Tu inscripción al club <strong><?= htmlspecialchars($club['club_nombre'] ?? '') ?></strong>
            ya estaba confirmada. No es necesario hacer nada más.
        </p>
        <a href="dashboard_alumno.php" class="btn btn-primary">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
            </svg>
            Ir a mi dashboard
        </a>
        <a href="login.php" class="btn btn-secondary">Inicio de sesión</a>
    </div>

    <?php elseif ($estado === 'expirado'): ?>

    <div class="card-top expirado">
        <div class="icon-circle">
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
        </div>
        <h1>Enlace expirado</h1>
        <p>El tiempo de confirmación de 24 horas venció</p>
    </div>

    <div class="card-body">
        <p style="font-size:.88rem;color:var(--gray-700);margin-bottom:1.4rem;line-height:1.6;">
            <?= htmlspecialchars($mensaje) ?>
        </p>
        <a href="dashboard_alumno.php" class="btn btn-primary">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                <polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
            </svg>
            Volver y solicitar de nuevo
        </a>
    </div>

    <?php else: /* error */ ?>

    <div class="card-top error">
        <div class="icon-circle">
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
        </div>
        <h1>Enlace no válido</h1>
        <p>No pudimos procesar tu confirmación</p>
    </div>

    <div class="card-body">
        <p style="font-size:.88rem;color:var(--gray-700);margin-bottom:1.4rem;line-height:1.6;">
            <?= htmlspecialchars($mensaje) ?>
        </p>
        <a href="login.php" class="btn btn-primary">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                <polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
            </svg>
            Volver al inicio
        </a>
    </div>

    <?php endif; ?>

</div>
</main>

<footer>
    © <?= date('Y') ?> Universidad de Colima — Bachillerato 23 &nbsp;|&nbsp; Sistema de Clubes Estudiantiles
</footer>

</body>
</html>
