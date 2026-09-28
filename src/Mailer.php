<?php
declare(strict_types=1);

namespace Calage;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require_once APP_ROOT . '/lib/PHPMailer/src/Exception.php';
require_once APP_ROOT . '/lib/PHPMailer/src/PHPMailer.php';
require_once APP_ROOT . '/lib/PHPMailer/src/SMTP.php';

/**
 * Sending over SMTP (bundled PHPMailer), with the settings from config.php → smtp.
 * Each recipient gets their own message: addresses are never visible to one another.
 */
final class Mailer
{
    public const MAX_RECIPIENTS = 20;

    public function __construct(private array $smtp)
    {
    }

    public static function fromConfig(): self
    {
        return new self(App::config()['smtp'] ?? []);
    }

    public function isConfigured(): bool
    {
        return ($this->smtp['host'] ?? '') !== '' && filter_var($this->smtp['from_email'] ?? '', FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Sends an HTML to each recipient, separately, over a single SMTP connection.
     * @param list<string> $recipients
     * @param list<string>|null $log receives the SMTP dialogue (password hidden) when given
     * @return array<string, ?string> address → null when sent, otherwise the error message
     */
    public function send(array $recipients, string $subject, string $html, ?array &$log = null): array
    {
        // The message is sent in UTF-8: a Windows-1252 newsletter is converted.
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }

        $mail = $this->mailer($log);
        $mail->SMTPKeepAlive = true;
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $mail->html2text($html);

        $results = [];
        foreach ($recipients as $address) {
            try {
                $mail->clearAddresses();
                $mail->addAddress($address);
                $mail->send();
                $results[$address] = null;
            } catch (MailerException $e) {
                $results[$address] = $mail->ErrorInfo ?: $e->getMessage();
                $mail->getSMTPInstance()->reset();
            }
        }
        $mail->smtpClose();
        return $results;
    }

    /** Test email, in the interface language. Returns null when sent, otherwise the error message. */
    public function sendTest(string $to, ?array &$log = null): ?string
    {
        $html = '<!doctype html><html lang="' . e(Lang::current()) . '"><head><meta charset="utf-8"></head>'
            . '<body style="font-family: sans-serif"><p>' . e(__('This is a test email sent by Calage.')) . '</p>'
            . '<p>' . e(__('If you received it, the SMTP settings work.')) . '</p></body></html>';
        return $this->send([$to], __('Calage: test email'), $html, $log)[$to];
    }

    /**
     * Splits free input (commas, semicolons, spaces, line breaks).
     * @return array{0: list<string>, 1: list<string>} [valid addresses, invalid entries]
     */
    public static function parseAddresses(string $input): array
    {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', $input, -1, PREG_SPLIT_NO_EMPTY) as $item) {
            $item = trim($item, "<>\"'");
            if (filter_var($item, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[strtolower($item)] = $item;
            } else {
                $invalid[] = $item;
            }
        }
        return [array_values($valid), $invalid];
    }

    private function mailer(?array &$log): PHPMailer
    {
        $s = $this->smtp;
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) ($s['host'] ?? '');
        $mail->Port = (int) ($s['port'] ?? 587);
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->XMailer = ' '; // do not advertise PHPMailer in the headers

        switch ($s['encryption'] ?? 'tls') {
            case 'ssl':
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                break;
            case 'none':
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
                break;
            default:
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        if (($s['username'] ?? '') !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = (string) $s['username'];
            $mail->Password = (string) ($s['password'] ?? '');
        }

        $mail->setFrom((string) ($s['from_email'] ?? ''), (string) ($s['from_name'] ?? ''));

        if ($log !== null) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function (string $line) use (&$log): void {
                $log[] = self::redact(trim($line));
            };
        }
        return $mail;
    }

    /**
     * Hides what the client sends to the server, except commands without secrets:
     * the username and password are sent in base64 right after AUTH.
     * PHPMailer 7 already hides them; this filter stays in case another version does not.
     */
    private static function redact(string $line): string
    {
        if (!str_starts_with($line, 'CLIENT -> SERVER:')) {
            return $line;
        }
        $command = trim(substr($line, strlen('CLIENT -> SERVER:')));
        if (preg_match('/^(EHLO|HELO|STARTTLS|MAIL FROM|RCPT TO|DATA|QUIT|RSET|NOOP)\b/i', $command)) {
            return $line;
        }
        if (preg_match('/^AUTH\s+(\S+)/i', $command, $m)) {
            return 'CLIENT -> SERVER: AUTH ' . $m[1] . ' [hidden]';
        }
        return 'CLIENT -> SERVER: [hidden]';
    }
}
