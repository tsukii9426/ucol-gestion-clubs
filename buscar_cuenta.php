<?php
/**
 * buscar_cuenta.php
 * Endpoint AJAX — verifica si un número de cuenta ya existe en estudiantes.
 * Devuelve JSON con los datos pre-cargables o indicación de cuenta nueva.
 */
session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$cuenta = trim($_POST['cuenta'] ?? '');

if (!$cuenta || !ctype_digit($cuenta)) {
    echo json_encode(['status' => 'error', 'message' => 'Número de cuenta inválido']);
    exit;
}

try {
    $stmt = getDB()->prepare(
        'SELECT e.nombre_completo, e.correo, e.id_plantel, p.nombre AS plantel_nombre
         FROM estudiantes e
         LEFT JOIN planteles p ON p.id = e.id_plantel
         WHERE e.cuenta = ?
         LIMIT 1'
    );
    $stmt->execute([(int)$cuenta]);
    $row = $stmt->fetch();

    if ($row) {
        echo json_encode([
            'status'         => 'found',
            'nombre'         => $row['nombre_completo'] ?? '',
            'correo'         => $row['correo']          ?? '',
            'id_plantel'     => (int)($row['id_plantel']    ?? 0),
            'plantel_nombre' => $row['plantel_nombre']  ?? '',
        ]);
    } else {
        echo json_encode(['status' => 'not_found']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión. Intenta de nuevo.']);
}
