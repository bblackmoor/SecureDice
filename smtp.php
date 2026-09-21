<?php

declare(strict_types=1);

require_once __DIR__ . '/email.php';

function smtp_configuration(): array
{
    $host = trim((string) getenv('SECUREDICE_SMTP_HOST'));
    $port = filter_var(getenv('SECUREDICE_SMTP_PORT') ?: '587', FILTER_VALIDATE_INT);
    $encryption = strtolower(trim((string) (getenv('SECUREDICE_SMTP_ENCRYPTION') ?: 'starttls')));
    $username = trim((string) getenv('SECUREDICE_SMTP_USERNAME'));
    $password = (string) getenv('SECUREDICE_SMTP_PASSWORD');
    $fromAddress = trim((string) getenv('SECUREDICE_SMTP_FROM_ADDRESS'));
    $fromName = trim((string) (getenv('SECUREDICE_SMTP_FROM_NAME') ?: 'Secure Dice'));
    $timeout = filter_var(getenv('SECUREDICE_SMTP_TIMEOUT') ?: '15', FILTER_VALIDATE_INT);

    if ($host === '' || $port === false || $port < 1 || $port > 65535) {
        throw new EmailConfigurationException('SMTP host and port are not configured correctly.');
    }

    if (!in_array($encryption, ['starttls', 'smtps', 'none'], true)) {
        throw new EmailConfigurationException('SMTP encryption must be starttls, smtps, or none.');
    }

    if ($encryption === 'none' && !in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
        throw new EmailConfigurationException('Unencrypted SMTP is permitted only for a local relay.');
    }

    if (($username === '') !== ($password === '')) {
        throw new EmailConfigurationException('SMTP username and password must be configured together.');
    }

    if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
        throw new EmailConfigurationException('The SMTP sender address is invalid.');
    }

    if ($timeout === false || $timeout < 1 || $timeout > 120) {
        throw new EmailConfigurationException('SMTP timeout must be between 1 and 120 seconds.');
    }

    return compact(
        'host', 'port', 'encryption', 'username', 'password',
        'fromAddress', 'fromName', 'timeout'
    );
}

function send_smtp_message(array $message): string
{
    $autoload = __DIR__ . '/vendor/autoload.php';

    if (!is_file($autoload)) {
        throw new EmailConfigurationException('Composer dependencies are not installed.');
    }

    require_once $autoload;
    $config = smtp_configuration();
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->Port = $config['port'];
        $mail->Timeout = $config['timeout'];
        $mail->SMTPAuth = $config['username'] !== '';
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->CharSet = 'UTF-8';

        if ($config['encryption'] === 'starttls') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($config['encryption'] === 'smtps') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($config['fromAddress'], $config['fromName']);
        $mail->addAddress((string) $message['to']);
        $mail->Subject = (string) $message['subject'];
        $mail->Body = nl2br(h((string) $message['text']));
        $mail->AltBody = (string) $message['text'];
        $mail->isHTML(true);
        $mail->send();

        return (string) $mail->getLastMessageID();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        $details = (string) $mail->ErrorInfo;
        $transient = preg_match('/\b5\d{2}\b/', $details) !== 1;
        throw new EmailTransportException('SMTP delivery failed.', $transient);
    }
}
