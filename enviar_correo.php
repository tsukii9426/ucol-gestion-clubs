<?php
/**
 * enviar_correo.php
 *
 * Funciones de envío de correo del sistema de Clubes Estudiantiles.
 *
 * Cada función acepta un parámetro opcional $smtp con las credenciales
 * del plantel { correo, contrasena_app }.  Si no se proporcionan (o están
 * vacías) se usan las credenciales globales de config.php.
 *
 * Jerarquía de envío:
 *   1. MAIL_DEMO = true  → sólo escribe en logs/correos_demo.log
 *   2. PHPMailer          → si existe phpmailer/src/PHPMailer.php
 *   3. mail() nativo      → fallback de último recurso
 */

require_once __DIR__ . '/config.php';

// ════════════════════════════════════════════════════════════════
//  _cargarPHPMailer() — Configura PHPMailer con credenciales
//  $smtp = ['correo' => '...', 'contrasena_app' => '...']
//  Devuelve false si PHPMailer no está instalado.
// ════════════════════════════════════════════════════════════════
// Escribe en logs/mail_errores.log para debug
function _logMailError(string $linea): void {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/mail_errores.log', $linea . "\n", FILE_APPEND);
}

// Verifica que una credencial de plantel sea real (no placeholder ni vacía)
function _credencialValida(string $val): bool {
    if (empty($val)) return false;
    $placeholders = ['xxxxxxxxxxxx', 'placeholder', 'xxxxxxxxxx', 'xxxx xxxx xxxx xxxx'];
    foreach ($placeholders as $p) {
        if (stripos($val, $p) !== false) return false;
    }
    // Una contraseña de app de Gmail tiene al menos 16 caracteres (sin espacios)
    return strlen(str_replace(' ', '', $val)) >= 16;
}

function _cargarPHPMailer(array $smtp = [])
{
    $src = __DIR__ . '/phpmailer/src/PHPMailer.php';
    if (!file_exists($src)) {
        _logMailError(date('[Y-m-d H:i:s]') . ' PHPMailer no encontrado en phpmailer/src/PHPMailer.php');
        return false;
    }

    require_once $src;
    require_once __DIR__ . '/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/phpmailer/src/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    // Usar credenciales del plantel SOLO si son reales (no placeholders)
    $plantelValido = (
        !empty($smtp['correo']) &&
        !empty($smtp['contrasena_app']) &&
        _credencialValida($smtp['contrasena_app']) &&
        filter_var($smtp['correo'], FILTER_VALIDATE_EMAIL)
    );

    $user = $plantelValido ? $smtp['correo']         : MAIL_USER;
    $pass = $plantelValido ? $smtp['contrasena_app'] : MAIL_PASS;
    $from = $user ?: MAIL_FROM;

    _logMailError(date('[Y-m-d H:i:s]')
        . ' Enviando desde: ' . $user
        . ' | Credenciales: ' . ($plantelValido ? 'plantel' : 'globales (config.php)')
    );

    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom($from, MAIL_FROM_NAME);

    return $mail;
}

// ════════════════════════════════════════════════════════════════
//  _logDemo() — Escribe una entrada en logs/correos_demo.log
// ════════════════════════════════════════════════════════════════
function _logDemo(string $linea): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/correos_demo.log', $linea, FILE_APPEND);
}

// ════════════════════════════════════════════════════════════════
//  enviarCorreoConfirmacion()
//  Confirmación de inscripción a club → al correo del alumno.
//
//  $datos:   correo, nombre_alumno, numero_cuenta, nombre_club,
//            dias_club, horario_club, plantel, encargado,
//            ciclo_escolar, link_confirmacion
//  $smtp:    ['correo' => ..., 'contrasena_app' => ...]  (plantel emisor)
// ════════════════════════════════════════════════════════════════
function enviarCorreoConfirmacion(array $datos, array $smtp = []): bool
{
    $plantilla = file_get_contents(__DIR__ . '/plantilla_correo_confirmacion.html');
    if ($plantilla === false) return false;

    $html = strtr($plantilla, [
        '{{NOMBRE_ALUMNO}}'    => htmlspecialchars($datos['nombre_alumno']),
        '{{CICLO_ESCOLAR}}'    => htmlspecialchars($datos['ciclo_escolar']),
        '{{PLANTEL}}'          => htmlspecialchars($datos['plantel']),
        '{{ENCARGADO}}'        => htmlspecialchars($datos['encargado']),
        '{{NOMBRE_CLUB}}'      => htmlspecialchars($datos['nombre_club']),
        '{{DIAS_CLUB}}'        => htmlspecialchars($datos['dias_club']),
        '{{HORARIO_CLUB}}'     => htmlspecialchars($datos['horario_club']),
        '{{NUMERO_CUENTA}}'    => htmlspecialchars($datos['numero_cuenta']),
        '{{CORREO_ALUMNO}}'    => htmlspecialchars($datos['correo']),
        '{{LINK_CONFIRMACION}}'=> $datos['link_confirmacion'],
        '{{AÑO}}'              => date('Y'),
    ]);

    // ── Modo demo ─────────────────────────────────────────────
    if (MAIL_DEMO) {
        $emisor = !empty($smtp['correo']) ? $smtp['correo'] : MAIL_FROM;
        _logDemo(
            date('[Y-m-d H:i:s]')
            . " [CONFIRMACION] Desde: $emisor"
            . " | Para: {$datos['correo']}"
            . " | Club: {$datos['nombre_club']}"
            . "\n  Enlace: {$datos['link_confirmacion']}\n\n"
        );
        return true;
    }

    // ── PHPMailer ─────────────────────────────────────────────
    $mail = _cargarPHPMailer($smtp);
    if ($mail) {
        try {
            $mail->addAddress($datos['correo'], $datos['nombre_alumno']);
            $mail->isHTML(true);
            $mail->Subject = '=?UTF-8?B?' . base64_encode('Confirmación de registro — ' . $datos['nombre_club']) . '?=';
            $mail->Body    = $html;
            $mail->AltBody = 'Confirma tu registro en: ' . $datos['link_confirmacion'];
            $mail->send();
            _logMailError(date('[Y-m-d H:i:s]') . ' ✅ Correo ENVIADO a: ' . $datos['correo']);
            return true;
        } catch (Exception $e) {
            $err = $mail->ErrorInfo;
            _logMailError(date('[Y-m-d H:i:s]') . ' ❌ ERROR PHPMailer confirmacion: ' . $err);
            error_log('PHPMailer confirmacion: ' . $err);
            return false;
        }
    }

    // ── Fallback: mail() nativo ──────────────────────────────
    $from    = !empty($smtp['correo']) ? $smtp['correo'] : MAIL_FROM;
    $asunto  = '=?UTF-8?B?' . base64_encode('Confirmación de registro — ' . $datos['nombre_club']) . '?=';
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . MAIL_FROM_NAME . ' <' . $from . '>',
        'X-Mailer: PHP/' . phpversion(),
    ]);
    return mail($datos['correo'], $asunto, $html, $headers);
}


// ════════════════════════════════════════════════════════════════
//  enviarCorreoAprobacion()
//  Solicitud de encargado → al correo del plantel para aprobar/rechazar.
//
//  $datos:   correo_plantel, nombre_plantel, tipo, num_trabajador,
//            nombre_completo, correo_solicitante, telefono,
//            link_aprobar, link_rechazar
//  $smtp:    ['correo' => ..., 'contrasena_app' => ...]  (plantel emisor)
// ════════════════════════════════════════════════════════════════
function enviarCorreoAprobacion(array $datos, array $smtp = []): bool
{
    $plantilla = file_get_contents(__DIR__ . '/plantilla_correo_aprobacion.html');
    if ($plantilla === false) return false;

    $html = strtr($plantilla, [
        '{{NOMBRE_PLANTEL}}'     => htmlspecialchars($datos['nombre_plantel']),
        '{{TIPO}}'               => htmlspecialchars($datos['tipo']),
        '{{NOMBRE_COMPLETO}}'    => htmlspecialchars($datos['nombre_completo']),
        '{{NUM_TRABAJADOR}}'     => htmlspecialchars($datos['num_trabajador']),
        '{{CORREO_SOLICITANTE}}' => htmlspecialchars($datos['correo_solicitante']),
        '{{TELEFONO}}'           => htmlspecialchars($datos['telefono']),
        '{{CORREO_PLANTEL}}'     => htmlspecialchars($datos['correo_plantel']),
        '{{LINK_APROBAR}}'       => $datos['link_aprobar'],
        '{{LINK_RECHAZAR}}'      => $datos['link_rechazar'],
        '{{AÑO}}'                => date('Y'),
    ]);

    // ── Modo demo ─────────────────────────────────────────────
    if (MAIL_DEMO) {
        $emisor = !empty($smtp['correo']) ? $smtp['correo'] : MAIL_FROM;
        _logDemo(
            date('[Y-m-d H:i:s]')
            . " [APROBACION] Desde: $emisor"
            . " | Para plantel: {$datos['correo_plantel']}"
            . " | Solicitante: {$datos['nombre_completo']}"
            . "\n  Aprobar : {$datos['link_aprobar']}"
            . "\n  Rechazar: {$datos['link_rechazar']}\n\n"
        );
        return true;
    }

    // ── PHPMailer ─────────────────────────────────────────────
    $mail = _cargarPHPMailer($smtp);
    if ($mail) {
        try {
            $mail->addAddress($datos['correo_plantel'], 'Administrador ' . $datos['nombre_plantel']);
            $mail->isHTML(true);
            $mail->Subject = '=?UTF-8?B?' . base64_encode('Solicitud de encargado — ' . $datos['nombre_plantel']) . '?=';
            $mail->Body    = $html;
            $mail->AltBody = "Aprobar: {$datos['link_aprobar']}\nRechazar: {$datos['link_rechazar']}";
            $mail->send();
            _logMailError(date('[Y-m-d H:i:s]') . ' ✅ Correo aprobacion ENVIADO a: ' . $datos['correo_plantel']);
            return true;
        } catch (Exception $e) {
            $err = $mail->ErrorInfo;
            _logMailError(date('[Y-m-d H:i:s]') . ' ❌ ERROR PHPMailer aprobacion: ' . $err);
            error_log('PHPMailer aprobacion: ' . $err);
            return false;
        }
    }

    // ── Fallback: mail() nativo ──────────────────────────────
    $from    = !empty($smtp['correo']) ? $smtp['correo'] : MAIL_FROM;
    $asunto  = '=?UTF-8?B?' . base64_encode('Solicitud de encargado — ' . $datos['nombre_plantel']) . '?=';
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . MAIL_FROM_NAME . ' <' . $from . '>',
        'X-Mailer: PHP/' . phpversion(),
    ]);
    return mail($datos['correo_plantel'], $asunto, $html, $headers);
}


// ════════════════════════════════════════════════════════════════
//  enviarCorreoRecuperacion()
//  Recuperación de contraseña → al correo del alumno.
//
//  $datos:   correo, numero_cuenta, link_reset
//  $smtp:    ['correo' => ..., 'contrasena_app' => ...]  (plantel del alumno)
// ════════════════════════════════════════════════════════════════
function enviarCorreoRecuperacion(array $datos, array $smtp = []): bool
{
    $plantilla = file_get_contents(__DIR__ . '/plantilla_correo_recuperacion.html');
    if ($plantilla === false) return false;

    $html = strtr($plantilla, [
        '{{NUMERO_CUENTA}}' => htmlspecialchars($datos['numero_cuenta']),
        '{{CORREO_ALUMNO}}' => htmlspecialchars($datos['correo']),
        '{{LINK_RESET}}'    => $datos['link_reset'],
        '{{AÑO}}'           => date('Y'),
    ]);

    // ── Modo demo ─────────────────────────────────────────────
    if (MAIL_DEMO) {
        $emisor = !empty($smtp['correo']) ? $smtp['correo'] : MAIL_FROM;
        _logDemo(
            date('[Y-m-d H:i:s]')
            . " [RECUPERACION] Desde: $emisor"
            . " | Para: {$datos['correo']}"
            . " | Cuenta: {$datos['numero_cuenta']}"
            . "\n  Enlace: {$datos['link_reset']}\n\n"
        );
        return true;
    }

    // ── PHPMailer ─────────────────────────────────────────────
    $mail = _cargarPHPMailer($smtp);
    if ($mail) {
        try {
            $mail->addAddress($datos['correo']);
            $mail->isHTML(true);
            $mail->Subject = '=?UTF-8?B?' . base64_encode('Recuperación de contraseña — Clubes Bachillerato 23') . '?=';
            $mail->Body    = $html;
            $mail->AltBody = "Hola,\n\nRecibimos una solicitud para restablecer tu contraseña.\n"
                           . "Haz clic en el siguiente enlace (válido por 1 hora):\n\n{$datos['link_reset']}\n\n"
                           . "Si no solicitaste esto, ignora este correo.";
            $mail->send();
            _logMailError(date('[Y-m-d H:i:s]') . ' ✅ Recuperación alumno ENVIADA a: ' . $datos['correo']);
            return true;
        } catch (Exception $e) {
            _logMailError(date('[Y-m-d H:i:s]') . ' ❌ ERROR PHPMailer recuperacion: ' . $mail->ErrorInfo);
            error_log('PHPMailer recuperacion: ' . $mail->ErrorInfo);
            return false;
        }
    }

    // ── Fallback: mail() nativo ──────────────────────────────
    $from    = !empty($smtp['correo']) ? $smtp['correo'] : MAIL_FROM;
    $asunto  = '=?UTF-8?B?' . base64_encode('Recuperación de contraseña — Clubes Bachillerato 23') . '?=';
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . MAIL_FROM_NAME . ' <' . $from . '>',
        'X-Mailer: PHP/' . phpversion(),
    ]);
    return mail($datos['correo'], $asunto, $html, $headers);
}


// ════════════════════════════════════════════════════════════════
//  enviarCorreoNuevoClub()
//  Notificación al plantel cuando un encargado crea un club en borrador.
//
//  $datos:   correo_plantel, nombre_plantel, nombre_encargado, num_trabajador,
//            nombre_club, descripcion, semestre, fecha_inicio, fecha_fin,
//            horarios_html, limite, link_dashboard
//  $smtp:    ['correo' => ..., 'contrasena_app' => ...]  (plantel emisor)
// ════════════════════════════════════════════════════════════════
function enviarCorreoNuevoClub(array $datos, array $smtp = []): bool
{
    $plantilla = file_get_contents(__DIR__ . '/plantilla_correo_nuevo_club.html');
    if ($plantilla === false) return false;

    $html = strtr($plantilla, [
        '{{NOMBRE_PLANTEL}}'   => htmlspecialchars($datos['nombre_plantel']),
        '{{NOMBRE_ENCARGADO}}' => htmlspecialchars($datos['nombre_encargado']),
        '{{NUM_TRABAJADOR}}'   => htmlspecialchars($datos['num_trabajador']),
        '{{NOMBRE_CLUB}}'      => htmlspecialchars($datos['nombre_club']),
        '{{DESCRIPCION}}'      => htmlspecialchars($datos['descripcion']),
        '{{SEMESTRE}}'         => htmlspecialchars($datos['semestre']),
        '{{FECHA_INICIO}}'     => htmlspecialchars($datos['fecha_inicio']),
        '{{FECHA_FIN}}'        => htmlspecialchars($datos['fecha_fin']),
        '{{HORARIOS_HTML}}'    => $datos['horarios_html'],   // ya escapado con <br>
        '{{LIMITE}}'           => htmlspecialchars($datos['limite']),
        '{{CORREO_PLANTEL}}'   => htmlspecialchars($datos['correo_plantel']),
        '{{LINK_DASHBOARD}}'   => $datos['link_dashboard'],
        '{{AÑO}}'              => date('Y'),
    ]);

    // ── Modo demo ─────────────────────────────────────────────
    if (MAIL_DEMO) {
        $emisor = !empty($smtp['correo']) ? $smtp['correo'] : MAIL_FROM;
        _logDemo(
            date('[Y-m-d H:i:s]')
            . " [NUEVO_CLUB] Desde: $emisor"
            . " | Para plantel: {$datos['correo_plantel']}"
            . " | Club: {$datos['nombre_club']}"
            . " | Encargado: {$datos['nombre_encargado']} (#{$datos['num_trabajador']})"
            . "\n  Dashboard: {$datos['link_dashboard']}\n\n"
        );
        return true;
    }

    // ── PHPMailer ─────────────────────────────────────────────
    $mail = _cargarPHPMailer($smtp);
    if ($mail) {
        try {
            $mail->addAddress($datos['correo_plantel'], 'Administrador ' . $datos['nombre_plantel']);
            $mail->isHTML(true);
            $mail->Subject = '=?UTF-8?B?' . base64_encode('Nuevo club en borrador — ' . $datos['nombre_club']) . '?=';
            $mail->Body    = $html;
            $mail->AltBody = "El encargado {$datos['nombre_encargado']} registró el club '{$datos['nombre_club']}' como borrador.\n"
                           . "Revísalo en: {$datos['link_dashboard']}";
            $mail->send();
            _logMailError(date('[Y-m-d H:i:s]') . ' ✅ Notif. nuevo club ENVIADA a: ' . $datos['correo_plantel']);
            return true;
        } catch (Exception $e) {
            _logMailError(date('[Y-m-d H:i:s]') . ' ❌ ERROR PHPMailer nuevo_club: ' . $mail->ErrorInfo);
            error_log('PHPMailer nuevo_club: ' . $mail->ErrorInfo);
            return false;
        }
    }

    // ── Fallback: mail() nativo ──────────────────────────────
    $from   = !empty($smtp['correo']) ? $smtp['correo'] : MAIL_FROM;
    $asunto = '=?UTF-8?B?' . base64_encode('Nuevo club en borrador — ' . $datos['nombre_club']) . '?=';
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . MAIL_FROM_NAME . ' <' . $from . '>',
        'X-Mailer: PHP/' . phpversion(),
    ]);
    return mail($datos['correo_plantel'], $asunto, $html, $headers);
}


// ════════════════════════════════════════════════════════════════
//  enviarCorreoRecuperacionEnc()
//  Recuperación de contraseña → al correo del encargado.
//
//  $datos:   correo, num_trabajador, link_reset
//  $smtp:    ['correo' => ..., 'contrasena_app' => ...]  (plantel del encargado)
// ════════════════════════════════════════════════════════════════
function enviarCorreoRecuperacionEnc(array $datos, array $smtp = []): bool
{
    $plantilla = file_get_contents(__DIR__ . '/plantilla_correo_recuperacion_enc.html');
    if ($plantilla === false) return false;

    $html = strtr($plantilla, [
        '{{NUM_TRABAJADOR}}' => htmlspecialchars($datos['num_trabajador']),
        '{{CORREO_ENC}}'     => htmlspecialchars($datos['correo']),
        '{{LINK_RESET}}'     => $datos['link_reset'],
        '{{AÑO}}'            => date('Y'),
    ]);

    // ── Modo demo ─────────────────────────────────────────────
    if (MAIL_DEMO) {
        $emisor = !empty($smtp['correo']) ? $smtp['correo'] : MAIL_FROM;
        _logDemo(
            date('[Y-m-d H:i:s]')
            . " [RECUPERACION-ENC] Desde: $emisor"
            . " | Para: {$datos['correo']}"
            . " | Trabajador: {$datos['num_trabajador']}"
            . "\n  Enlace: {$datos['link_reset']}\n\n"
        );
        return true;
    }

    // ── PHPMailer ─────────────────────────────────────────────
    $mail = _cargarPHPMailer($smtp);
    if ($mail) {
        try {
            $mail->addAddress($datos['correo']);
            $mail->isHTML(true);
            $mail->Subject = '=?UTF-8?B?' . base64_encode('Recuperación de contraseña — Personal Clubes B23') . '?=';
            $mail->Body    = $html;
            $mail->AltBody = "Hola,\n\nRecibimos una solicitud para restablecer la contraseña del trabajador N.° {$datos['num_trabajador']}.\n"
                           . "Haz clic en el siguiente enlace (válido por 1 hora):\n\n{$datos['link_reset']}\n\n"
                           . "Si no solicitaste esto, ignora este correo.";
            $mail->send();
            _logMailError(date('[Y-m-d H:i:s]') . ' ✅ Recuperación encargado ENVIADA a: ' . $datos['correo']);
            return true;
        } catch (Exception $e) {
            _logMailError(date('[Y-m-d H:i:s]') . ' ❌ ERROR PHPMailer recuperacion-enc: ' . $mail->ErrorInfo);
            error_log('PHPMailer recuperacion-enc: ' . $mail->ErrorInfo);
            return false;
        }
    }

    // ── Fallback: mail() nativo ──────────────────────────────
    $from    = !empty($smtp['correo']) ? $smtp['correo'] : MAIL_FROM;
    $asunto  = '=?UTF-8?B?' . base64_encode('Recuperación de contraseña — Personal Clubes B23') . '?=';
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . MAIL_FROM_NAME . ' <' . $from . '>',
        'X-Mailer: PHP/' . phpversion(),
    ]);
    return mail($datos['correo'], $asunto, $html, $headers);
}
