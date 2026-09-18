<?php

declare(strict_types=1);

namespace App\Services;

final class EmailService
{
    public static function isEnabled(): bool
    {
        return MailSettingsService::isEnabled();
    }

    public static function send(string $to, string $subject, string $body, string $eventId = ''): void
    {
        if (!self::isEnabled()) {
            throw new \RuntimeException('Почта отключена: настройте SMTP.');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Некорректный email получателя: ' . $to);
        }

        $settings = MailSettingsService::current();
        $host = (string) $settings['host'];
        $port = (int) $settings['port'];
        $encryption = strtolower((string) $settings['encryption']);
        if ($encryption === 'none' && in_array((string) config('app.env'), ['production', 'prod'], true)) {
            throw new \RuntimeException('SMTP без шифрования запрещён в production.');
        }
        $timeout = (int) $settings['timeout'];
        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'disable_compression' => true,
            ],
        ]);
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$socket) {
            throw new \RuntimeException('SMTP connect failed: ' . $errstr . ' (' . $errno . ')');
        }
        stream_set_timeout($socket, $timeout);

        try {
            self::expect($socket, [220]);
            self::command($socket, 'EHLO ' . self::hostName(), [250]);

            if ($encryption === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP STARTTLS failed');
                }
                self::command($socket, 'EHLO ' . self::hostName(), [250]);
            }

            $username = trim((string) $settings['username']);
            if ($username !== '') {
                self::command($socket, 'AUTH LOGIN', [334]);
                self::command($socket, base64_encode($username), [334]);
                self::command($socket, base64_encode((string) $settings['password']), [235]);
            }

            $fromEmail = (string) $settings['from_email'];
            $fromName = (string) $settings['from_name'];
            self::command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);
            fwrite($socket, self::message($fromEmail, $fromName, $to, $subject, $body) . "\r\n.\r\n");
            self::expect($socket, [250]);
            self::command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @param list<int> $expected
     */
    private static function command($socket, string $command, array $expected): string
    {
        fwrite($socket, $command . "\r\n");
        return self::expect($socket, $expected);
    }

    /**
     * @param resource $socket
     * @param list<int> $expected
     */
    private static function expect($socket, array $expected): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException('SMTP unexpected response: ' . trim($response));
        }

        return $response;
    }

    private static function message(string $fromEmail, string $fromName, string $to, string $subject, string $body): string
    {
        $encodedSubject = self::header($subject);
        $encodedFrom = self::header($fromName);
        $safeBody = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body)) ?? $body;
        $safeBody = str_replace("\n", "\r\n", $safeBody);

        return implode("\r\n", [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $encodedFrom . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $safeBody,
        ]);
    }

    private static function header(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function hostName(): string
    {
        $host = parse_url((string) config('app.url', ''), PHP_URL_HOST);
        return $host ?: 'locia.local';
    }
}
