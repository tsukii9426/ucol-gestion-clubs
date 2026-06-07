-- ════════════════════════════════════════════════════════
--  MIGRACIÓN: soporte para múltiples clubs por alumno
--  Ejecutar UNA SOLA VEZ en phpMyAdmin
-- ════════════════════════════════════════════════════════

-- 1. Crear tabla de inscripciones
CREATE TABLE IF NOT EXISTS inscripciones_club (
    id                INT          AUTO_INCREMENT PRIMARY KEY,
    numero_cuenta     INT          NOT NULL,
    id_club           INT          NOT NULL,
    fecha_inscripcion DATETIME     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cuenta_club (numero_cuenta, id_club),
    KEY idx_cuenta    (numero_cuenta),
    KEY idx_club      (id_club)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Migrar inscripciones ya existentes en estudiantes.id_club
--    (copiar los registros anteriores a la nueva tabla)
INSERT IGNORE INTO inscripciones_club (numero_cuenta, id_club)
SELECT cuenta, id_club
FROM estudiantes
WHERE id_club IS NOT NULL;
