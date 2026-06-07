<?php
/**
 * sincronizar_estados.php
 *
 * Ejecuta las transiciones automáticas de estado de clubs según las fechas
 * definidas en la tabla `clubes`. Corre una vez por request; el MySQL EVENT
 * ev_transicion_estados_clubes es la fuente primaria (diaria a las 00:05),
 * esta función actúa como red de seguridad en cada visita a los dashboards.
 *
 * Transiciones:
 *   apertura  → iniciado  : cuando vence fecha_limite_registro (o fecha_inicio
 *                            si no hay límite de registro)
 *   iniciado  → finalizado : cuando pasa fecha_fin
 */

function sincronizarEstadosClubes(): void
{
    static $ejecutado = false;
    if ($ejecutado) return;
    $ejecutado = true;

    try {
        $pdo = getDB();

        // apertura → iniciado
        $pdo->exec("
            UPDATE clubes
            SET estado = 'iniciado'
            WHERE estado = 'apertura'
              AND autorizado = 'si'
              AND (
                  (fecha_limite_registro IS NOT NULL AND fecha_limite_registro < CURDATE())
                  OR (fecha_limite_registro IS NULL  AND fecha_inicio <= CURDATE())
              )
        ");

        // iniciado → finalizado
        $pdo->exec("
            UPDATE clubes
            SET estado = 'finalizado'
            WHERE estado = 'iniciado'
              AND fecha_fin < CURDATE()
        ");

    } catch (Exception $e) {
        error_log('sincronizarEstadosClubes error: ' . $e->getMessage());
    }
}
