<?php
/**
 * procesar_asistencia.php
 * Endpoint JSON — registra entrada o salida de un alumno vía QR.
 *
 * POST params:
 *   codigo   → número de cuenta del alumno (string numérico)
 *   id_club  → ID del club activo
 */
session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

// ── Validar sesión de encargado ──────────────────────────────────
if (empty($_SESSION['encargado_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Sesión no válida']);
    exit;
}

$enc_id  = (int)$_SESSION['encargado_id'];
$codigo  = trim($_POST['codigo']  ?? '');
$id_club = (int)($_POST['id_club'] ?? 0);

if (!$codigo || !$id_club) {
    echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
    exit;
}

date_default_timezone_set('America/Mexico_City');
$fecha = date('Y-m-d');
$hora  = date('H:i:s');

try {
    $pdo = getDB();

    // ── Verificar que el club pertenece al encargado (o es auxiliar) y está iniciado ──
    $chk = $pdo->prepare(
        "SELECT c.id FROM clubes c
         WHERE c.id = ? AND c.estado = 'iniciado'
           AND (c.id_encargado = ? OR EXISTS (
               SELECT 1 FROM auxiliares_club ac
               WHERE ac.id_club = c.id AND ac.id_persona = ?
           ))"
    );
    $chk->execute([$id_club, $enc_id, $enc_id]);
    if (!$chk->fetch()) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Club no válido o no está en estado Iniciado'
        ]);
        exit;
    }

    // ── Verificar día de sesión y ventana horaria (±1 h) ───────────
    $dias_es = ['1'=>'Lunes','2'=>'Martes','3'=>'Miércoles','4'=>'Jueves','5'=>'Viernes','6'=>'Sábado','7'=>'Domingo'];
    $hoy_dia = $dias_es[date('N')] ?? '';
    $chkDia  = $pdo->prepare("SELECT hora_inicio, hora_fin FROM horarios WHERE id_club = ? AND dia = ?");
    $chkDia->execute([$id_club, $hoy_dia]);
    $horarios_hoy = $chkDia->fetchAll();

    if (empty($horarios_hoy)) {
        echo json_encode([
            'status'  => 'error',
            'message' => "⚠ Hoy ($hoy_dia) no hay sesión programada para este club",
        ]);
        exit;
    }

    $ahora_ts  = strtotime($hora);
    $en_rango  = false;
    $rangos_legibles = [];
    foreach ($horarios_hoy as $h) {
        $ini_ts = strtotime($h['hora_inicio']) - 3600;
        $fin_ts = strtotime($h['hora_fin'])    + 3600;
        $rangos_legibles[] = date('H:i', $ini_ts) . '–' . date('H:i', $fin_ts);
        if ($ahora_ts >= $ini_ts && $ahora_ts <= $fin_ts) {
            $en_rango = true;
        }
    }

    if (!$en_rango) {
        echo json_encode([
            'status'  => 'error',
            'message' => '⏰ Fuera de horario. Registro permitido: ' . implode(', ', $rangos_legibles),
        ]);
        exit;
    }

    // ── Buscar alumno inscrito en este club ──────────────────────────
    $stmt = $pdo->prepare(
        "SELECT e.id, e.nombre_completo, e.cuenta
         FROM estudiantes e
         JOIN inscripciones_club ic ON ic.numero_cuenta = e.cuenta AND ic.id_club = ?
         WHERE e.cuenta = ?"
    );
    $stmt->execute([$id_club, (int)$codigo]);
    $alumno = $stmt->fetch();

    if (!$alumno) {
        // Buscar si el alumno existe pero no está inscrito en este club
        $existe = $pdo->prepare("SELECT id, nombre_completo FROM estudiantes WHERE cuenta = ?");
        $existe->execute([(int)$codigo]);
        $al2 = $existe->fetch();
        if ($al2) {
            echo json_encode([
                'status'  => 'error',
                'message' => htmlspecialchars($al2['nombre_completo']) . ' no está inscrito en este club'
            ]);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => "Cuenta #$codigo no encontrada en el sistema"
            ]);
        }
        exit;
    }

    $nombre = $alumno['nombre_completo'];

    // ── Consultar registro de asistencia del día ─────────────────────
    $chkAsis = $pdo->prepare(
        "SELECT id, hora_entrada, hora_salida
         FROM asistencias_club
         WHERE id_club = ? AND id_estudiante = ? AND fecha = ?"
    );
    $chkAsis->execute([$id_club, $alumno['id'], $fecha]);
    $asis = $chkAsis->fetch();

    if (!$asis) {
        // ── Sin registro del día → ENTRADA ───────────────────────
        $pdo->prepare(
            "INSERT INTO asistencias_club (id_club, id_estudiante, fecha, hora_entrada, estado)
             VALUES (?, ?, ?, ?, 'asistio')"
        )->execute([$id_club, $alumno['id'], $fecha, $hora]);

        echo json_encode([
            'status'  => 'registrado',
            'tipo'    => 'ENTRADA',
            'message' => "✅ Entrada: $nombre",
            'hora'    => substr($hora, 0, 5),
            'nombre'  => $nombre,
        ]);

    } elseif ($asis['hora_entrada'] && $asis['hora_salida']) {
        // ── Ya tiene entrada y salida → actualizar como nueva SALIDA ──
        $pdo->prepare(
            "UPDATE asistencias_club SET hora_salida = ? WHERE id = ?"
        )->execute([$hora, $asis['id']]);

        echo json_encode([
            'status'  => 'registrado',
            'tipo'    => 'SALIDA',
            'message' => "🔄 Salida actualizada: $nombre",
            'hora'    => substr($hora, 0, 5),
            'nombre'  => $nombre,
        ]);

    } else {
        // ── Tiene entrada pero no salida → SALIDA ────────────────
        $pdo->prepare(
            "UPDATE asistencias_club SET hora_salida = ? WHERE id = ?"
        )->execute([$hora, $asis['id']]);

        echo json_encode([
            'status'  => 'registrado',
            'tipo'    => 'SALIDA',
            'message' => "👋 Salida: $nombre",
            'hora'    => substr($hora, 0, 5),
            'nombre'  => $nombre,
        ]);
    }

} catch (Exception $e) {
    $msg = $e->getMessage();
    error_log('procesar_asistencia error: ' . $msg);

    // Detectar si la tabla no existe
    if (stripos($msg, "doesn't exist") !== false || stripos($msg, 'no existe') !== false) {
        echo json_encode([
            'status'  => 'error',
            'message' => '⚠️ La tabla asistencias_club no existe. Ejecuta el SQL en phpMyAdmin.'
        ]);
    } else {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Error: ' . $msg
        ]);
    }
}
