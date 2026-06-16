<?php
/**
 * config.php — versión Docker
 * Lee las credenciales desde variables de entorno (definidas en .env / docker-compose.yml)
 * Este archivo sobreescribe el config.php de la carpeta gestion_clubs mediante un volume mount.
 */

define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_NAME', getenv('DB_NAME') ?: 'bachilleratos');
define('DB_USER', getenv('DB_USER') ?: 'clubs_user');
define('DB_PASS', getenv('DB_PASS') ?: '');

define('BASE_URL', rtrim(getenv('BASE_URL') ?: 'http://localhost:8080', '/'));

// Correo
define('MAIL_DEMO',  filter_var(getenv('MAIL_DEMO') ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('SMTP_HOST',  getenv('SMTP_HOST') ?: '');
define('SMTP_PORT',  (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER',  getenv('SMTP_USER') ?: '');
define('SMTP_PASS',  getenv('SMTP_PASS') ?: '');
define('SMTP_FROM',  getenv('SMTP_FROM') ?: '');
