-- ════════════════════════════════════════════════════════════
--  Esquema completo — Gestión Clubes Bach. 23
--  Se ejecuta automáticamente al levantar el contenedor MySQL
--  por primera vez (docker-entrypoint-initdb.d)
-- ════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET time_zone = 'America/Mexico_City';

-- ── Planteles ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS planteles (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(150) NOT NULL,
    clave       VARCHAR(20)  NOT NULL UNIQUE,
    creado_en   DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Encargados ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS encargados (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nombre_completo VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    id_plantel      INT          NOT NULL,
    activo          TINYINT(1)   DEFAULT 1,
    creado_en       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_plantel) REFERENCES planteles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tokens de recuperación ───────────────────────────────────
CREATE TABLE IF NOT EXISTS tokens_recuperacion_enc (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    id_encargado INT NOT NULL,
    token       VARCHAR(64) NOT NULL UNIQUE,
    expira_en   DATETIME    NOT NULL,
    usado       TINYINT(1)  DEFAULT 0,
    creado_en   DATETIME    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_encargado) REFERENCES encargados(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Clubes ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS clubes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nombre          VARCHAR(150) NOT NULL,
    descripcion     TEXT,
    cupo_maximo     INT          DEFAULT 30,
    id_encargado    INT          NOT NULL,
    id_plantel      INT          NOT NULL,
    estado          ENUM('pendiente','activo','iniciado','cerrado') DEFAULT 'pendiente',
    imagen          VARCHAR(255),
    creado_en       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_encargado) REFERENCES encargados(id),
    FOREIGN KEY (id_plantel)   REFERENCES planteles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Horarios ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS horarios (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    id_club     INT         NOT NULL,
    dia         VARCHAR(20) NOT NULL,
    hora_inicio TIME        NOT NULL,
    hora_fin    TIME        NOT NULL,
    FOREIGN KEY (id_club) REFERENCES clubes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Estudiantes ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS estudiantes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nombre_completo VARCHAR(150) NOT NULL,
    cuenta          INT          NOT NULL UNIQUE,
    plantel         VARCHAR(20),
    id_club         INT,
    grado           VARCHAR(10),
    grupo           VARCHAR(10),
    password_hash   VARCHAR(255),
    creado_en       DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Inscripciones (multi-club) ───────────────────────────────
CREATE TABLE IF NOT EXISTS inscripciones_club (
    id                INT      AUTO_INCREMENT PRIMARY KEY,
    numero_cuenta     INT      NOT NULL,
    id_club           INT      NOT NULL,
    fecha_inscripcion DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cuenta_club (numero_cuenta, id_club),
    KEY idx_cuenta (numero_cuenta),
    KEY idx_club   (id_club)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Auxiliares de club ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS auxiliares_club (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    id_club     INT NOT NULL,
    id_persona  INT NOT NULL,
    FOREIGN KEY (id_club) REFERENCES clubes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Asistencias ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS asistencias_club (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_club         INT         NOT NULL,
    id_estudiante   INT         NOT NULL,
    fecha           DATE        NOT NULL,
    hora_entrada    TIME,
    hora_salida     TIME,
    estado          ENUM('asistio','falta','justificado') DEFAULT 'asistio',
    UNIQUE KEY uq_asis (id_club, id_estudiante, fecha),
    FOREIGN KEY (id_club)       REFERENCES clubes(id),
    FOREIGN KEY (id_estudiante) REFERENCES estudiantes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Solicitudes de encargado ─────────────────────────────────
CREATE TABLE IF NOT EXISTS solicitudes_encargado (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nombre_completo VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NOT NULL,
    id_plantel      INT          NOT NULL,
    token_acceso    VARCHAR(64),
    estado          ENUM('pendiente','aprobado','rechazado') DEFAULT 'pendiente',
    creado_en       DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_plantel) REFERENCES planteles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
