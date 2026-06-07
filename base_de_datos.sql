CREATE DATABASE IF NOT EXISTS bachilleratos
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE bachilleratos;

-- ─────────────────────────────────────────────────────────────
--  PLANTELES
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS planteles (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre            VARCHAR(100) NOT NULL COMMENT 'Nombre del plantel',
    usuario           VARCHAR(50)  NOT NULL UNIQUE COMMENT 'Cuenta asignada',
    contrasena_cuenta VARCHAR(255) NOT NULL COMMENT 'Contraseña para iniciar sesión',
    correo            VARCHAR(100) NOT NULL UNIQUE COMMENT 'Correo del plantel (emisor de mails)',
    contrasena_app    VARCHAR(255) COMMENT 'Contraseña de aplicación SMTP'
);

-- ─────────────────────────────────────────────────────────────
--  PERSONAS  (docentes, administrativos y estudiantes)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS personas (
    id               INT UNSIGNED PRIMARY KEY COMMENT 'Número de trabajador o número de cuenta',
    tipo             ENUM('Administrativo', 'Docente', 'Estudiante') NOT NULL,
    nombres          VARCHAR(50)  NOT NULL,
    apellido_paterno VARCHAR(50)  NOT NULL,
    apellido_materno VARCHAR(50)  NOT NULL,
    correo           VARCHAR(100) NOT NULL,
    telefono         VARCHAR(10),
    contrasena       VARCHAR(255) COMMENT 'Hash bcrypt de la contraseña',
    id_plantel       INT UNSIGNED,
    FOREIGN KEY (id_plantel)
        REFERENCES planteles(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

-- ─────────────────────────────────────────────────────────────
--  ENCARGADOS  (docentes asignados a planteles)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS encargados (
    id_persona INT UNSIGNED NOT NULL,
    id_plantel INT UNSIGNED NOT NULL,
    activo     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=activo, 0=desactivado',
    PRIMARY KEY (id_persona, id_plantel),
    FOREIGN KEY (id_persona)
        REFERENCES personas(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (id_plantel)
        REFERENCES planteles(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- ─────────────────────────────────────────────────────────────
--  CLUBES
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS clubes (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(50)                          NOT NULL COMMENT 'Nombre del club',
    descripcion  VARCHAR(150)                         NOT NULL,
    fecha_inicio DATE                                 NOT NULL,
    fecha_fin    DATE                                 NOT NULL,
    limite       TINYINT UNSIGNED                     NOT NULL COMMENT 'Cupo máximo',
    anio         YEAR                                 NOT NULL,
    semestre     ENUM('par','impar')                  NOT NULL,
    estado                ENUM('borrador','apertura','iniciado','finalizado','cancelado')
                              NOT NULL DEFAULT 'borrador'
                              COMMENT 'borrador=editable, apertura=inscripciones abiertas, iniciado=en curso, finalizado=terminado, cancelado',
    autorizado            ENUM('si','no') NOT NULL DEFAULT 'no'
                              COMMENT 'El plantel debe autorizar el club antes de que pueda abrirse a inscripciones',
    restaurado            TINYINT(1)   NOT NULL DEFAULT 0
                              COMMENT '1 = restaurado desde cancelado, pendiente nueva autorización del plantel',
    fecha_limite_registro DATE     NULL COMMENT 'Fecha límite para inscribirse (solo en estado apertura)',
    id_plantel            INT UNSIGNED NOT NULL,
    id_encargado INT UNSIGNED,
    FOREIGN KEY (id_plantel)
        REFERENCES planteles(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (id_encargado)
        REFERENCES personas(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

-- ─────────────────────────────────────────────────────────────
--  HORARIOS  (días y horas de cada club)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS horarios (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dia         VARCHAR(20)  NOT NULL,
    hora_inicio TIME         NOT NULL,
    hora_fin    TIME         NOT NULL,
    id_club     INT UNSIGNED NOT NULL,
    FOREIGN KEY (id_club)
        REFERENCES clubes(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- ─────────────────────────────────────────────────────────────
--  ESTUDIANTES  (se llenan al confirmar el correo)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS estudiantes (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cuenta         INT(8)        NOT NULL UNIQUE COMMENT 'Número de cuenta',
    nombre_completo VARCHAR(100) NOT NULL,
    correo         VARCHAR(100)  NOT NULL UNIQUE,
    contrasena     VARCHAR(255)  COMMENT 'Hash bcrypt — se crea en primer login',
    id_club        INT UNSIGNED,
    id_plantel     INT UNSIGNED,
    FOREIGN KEY (id_club)
        REFERENCES clubes(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    FOREIGN KEY (id_plantel)
        REFERENCES planteles(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

-- ─────────────────────────────────────────────────────────────
--  INSCRIPCIONES DE CLUB  (soporte multi-club por alumno)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inscripciones_club (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero_cuenta     INT(8)       NOT NULL COMMENT 'Número de cuenta del alumno',
    id_club           INT UNSIGNED NOT NULL,
    fecha_inscripcion DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cuenta_club (numero_cuenta, id_club),
    KEY idx_cuenta (numero_cuenta),
    FOREIGN KEY (id_club)
        REFERENCES clubes(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
--  TOKENS PENDIENTES  (confirmaciones por correo)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tokens_pendientes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token           VARCHAR(64)   NOT NULL UNIQUE,
    numero_cuenta   INT(8)        NOT NULL,
    nombre_completo VARCHAR(100)  NOT NULL,
    correo          VARCHAR(100)  NOT NULL,
    plantel_nombre  VARCHAR(100)  NOT NULL,
    id_plantel      INT UNSIGNED,
    id_club         INT UNSIGNED  NOT NULL,
    creado_en       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_en       TIMESTAMP     NOT NULL,
    usado           TINYINT(1)    NOT NULL DEFAULT 0,
    FOREIGN KEY (id_plantel)
        REFERENCES planteles(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    FOREIGN KEY (id_club)
        REFERENCES clubes(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- ─────────────────────────────────────────────────────────────
--  SOLICITUDES DE ENCARGADO  (pendientes de aprobación por el plantel)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS solicitudes_encargado (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token             VARCHAR(64)                              NOT NULL UNIQUE,
    numero_trabajador INT UNSIGNED                             NOT NULL COMMENT 'ID / núm. de trabajador',
    tipo              ENUM('Administrativo','Docente','Estudiante') NOT NULL,
    nombres           VARCHAR(50)                             NOT NULL,
    apellido_paterno  VARCHAR(50)                             NOT NULL,
    apellido_materno  VARCHAR(50)                             NOT NULL,
    correo            VARCHAR(100)                            NOT NULL,
    telefono          VARCHAR(15),
    contrasena        VARCHAR(255)                            NOT NULL COMMENT 'Hash bcrypt de la contraseña',
    id_plantel        INT UNSIGNED                            NOT NULL,
    creado_en         TIMESTAMP                               NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_en         TIMESTAMP                               NOT NULL,
    estado            ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    FOREIGN KEY (id_plantel)
        REFERENCES planteles(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- ─────────────────────────────────────────────────────────────
--  ASISTENCIAS DE CLUB  (registro QR por sesión)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS asistencias_club (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_club         INT UNSIGNED  NOT NULL,
    id_estudiante   INT UNSIGNED  NOT NULL,
    fecha           DATE          NOT NULL,
    hora_entrada    TIME          NULL COMMENT 'Primera lectura del día',
    hora_salida     TIME          NULL COMMENT 'Segunda lectura del día',
    estado          ENUM('asistio','falta','tarde') NOT NULL DEFAULT 'asistio',
    creado_en       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE  KEY uq_asis (id_club, id_estudiante, fecha),
    FOREIGN KEY (id_club)
        REFERENCES clubes(id)      ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (id_estudiante)
        REFERENCES estudiantes(id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- ─────────────────────────────────────────────────────────────
--  PARCIALES y ASISTENCIAS (sistema anterior — conservado)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS parciales (
    id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL
);

CREATE TABLE IF NOT EXISTS asistencias (
    fecha_asistencia DATE         NOT NULL,
    id_parcial       INT UNSIGNED NOT NULL,
    id_horario       INT UNSIGNED NOT NULL,
    id_estudiante    INT UNSIGNED NOT NULL,
    hora_inicio      TIME         NOT NULL,
    hora_fin         TIME         NOT NULL,
    PRIMARY KEY (id_parcial, id_horario, id_estudiante),
    FOREIGN KEY (id_parcial)
        REFERENCES parciales(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (id_horario)
        REFERENCES horarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (id_estudiante)
        REFERENCES estudiantes(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ─────────────────────────────────────────────────────────────
--  AUXILIARES DE CLUB  (encargados que apoyan con asistencia)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auxiliares_club (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_club      INT UNSIGNED NOT NULL,
    id_persona   INT UNSIGNED NOT NULL COMMENT 'Debe existir en personas + encargados',
    agregado_por INT UNSIGNED NOT NULL COMMENT 'ID del encargado principal que lo agregó',
    creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aux (id_club, id_persona),
    FOREIGN KEY (id_club)      REFERENCES clubes(id)   ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (id_persona)   REFERENCES personas(id)  ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (agregado_por) REFERENCES personas(id)  ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═════════════════════════════════════════════════════════════
--  MIGRACIONES (ejecutar si la BD ya existía)
-- ═════════════════════════════════════════════════════════════
-- ALTER TABLE clubes
--   MODIFY estado ENUM('borrador','apertura','iniciado','finalizado','cancelado') NOT NULL DEFAULT 'borrador',
--   ADD COLUMN autorizado ENUM('si','no') NOT NULL DEFAULT 'no' AFTER estado,
--   ADD COLUMN fecha_limite_registro DATE NULL AFTER fecha_fin;
-- ALTER TABLE estudiantes ADD COLUMN contrasena VARCHAR(255) AFTER correo;
-- ALTER TABLE encargados  ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER id_plantel;
-- Para agregar asistencias_club si la BD ya existía:
-- (ya incluida en el CREATE TABLE IF NOT EXISTS de arriba)
-- ALTER TABLE personas ADD COLUMN contrasena VARCHAR(255) AFTER telefono;
-- ALTER TABLE solicitudes_encargado ADD COLUMN contrasena VARCHAR(255) NOT NULL AFTER telefono;
-- ALTER TABLE solicitudes_encargado MODIFY tipo ENUM('Administrativo','Docente','Estudiante') NOT NULL;

