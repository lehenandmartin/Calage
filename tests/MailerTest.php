<?php
declare(strict_types=1);

use Calage\Mailer;

/** Starts the fake SMTP server; returns [Mailer, file of received messages, stop function]. */
function fake_smtp(array $overrides = []): array
{
    $port = random_int(20000, 40000);
    $out = temp_dir() . '/mails.txt';
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/fake-smtp.php', (string) $port, $out],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    for ($i = 0; $i < 100 && !is_file("$out.ready"); $i++) {
        usleep(20_000);
    }
    $mailer = new Mailer($overrides + [
        'host' => '127.0.0.1',
        'port' => $port,
        'encryption' => 'none',
        'username' => 'agence@example.com',
        'password' => 'motdepasse-secret',
        'from_email' => 'newsletters@example.com',
        'from_name' => 'Après la pub',
    ]);
    $stop = function () use ($process): void {
        proc_terminate($process);
        proc_close($process);
    };
    return [$mailer, $out, $stop];
}

/** @return list<string> messages received by the fake server */
function received(string $out): array
{
    return is_file($out) ? array_values(array_filter(explode("\n=====\n", file_get_contents($out)), 'trim')) : [];
}

test('mail: free input of recipients', function (): void {
    [$valid, $invalid] = Mailer::parseAddresses("a@example.com, B@Exemple.fr;\n<c@example.com>  a@example.com pas-une-adresse");
    check_same(['a@example.com', 'B@Exemple.fr', 'c@example.com'], $valid);
    check_same(['pas-une-adresse'], $invalid);
});

test('mail: one message per recipient, subject and HTML of the version', function (): void {
    [$mailer, $out, $stop] = fake_smtp();
    try {
        $html = '<!doctype html><html><body><img src="https://example.com/i/abc.png"><p>Été</p></body></html>';
        $results = $mailer->send(['a@example.com', 'b@example.com'], 'Rentrée : les nouveautés', $html);
    } finally {
        $stop();
    }
    check_same(['a@example.com' => null, 'b@example.com' => null], $results);
    $mails = received($out);
    check_same(2, count($mails));
    check(str_starts_with($mails[0], 'RCPT: a@example.com'), 'first recipient alone');
    check(str_starts_with($mails[1], 'RCPT: b@example.com'), 'second recipient alone');
    check(!str_contains($mails[1], 'a@example.com'), 'addresses cannot see one another');
    check(str_contains($mails[0], 'Subject: =?utf-8?'), 'subject encoded in UTF-8');
    check(str_contains(quoted_printable_decode($mails[0]), 'https://example.com/i/abc.png'), 'hosted images');
    check(str_contains($mails[0], 'From: =?utf-8?Q?Apr=C3=A8s_la_pub?= <newsletters@example.com>'), 'sender');
    check(!str_contains($mails[0], 'X-Mailer'), 'no X-Mailer header');
});

test('mail: a refused recipient does not stop the others', function (): void {
    [$mailer, $out, $stop] = fake_smtp();
    try {
        $results = $mailer->send(['refuse@example.com', 'ok@example.com'], 'Objet', '<p>x</p>');
    } finally {
        $stop();
    }
    check($results['refuse@example.com'] !== null, 'error for the refused address');
    check_same(null, $results['ok@example.com']);
    check_same(1, count(received($out)));
});

test('mail: SMTP dialogue without username or password', function (): void {
    [$mailer, $out, $stop] = fake_smtp();
    $log = [];
    try {
        $mailer->send(['a@example.com'], 'Objet', '<p>x</p>', $log);
    } finally {
        $stop();
    }
    $text = implode("\n", $log);
    check(str_contains($text, 'AUTH LOGIN'), 'the dialogue is captured');
    foreach (['agence@example.com', 'motdepasse-secret', base64_encode('agence@example.com'), base64_encode('motdepasse-secret')] as $secret) {
        check(!str_contains($text, $secret), "secret visible dans le journal : $secret");
    }
});

test('mail: Windows-1252 newsletter sent in UTF-8', function (): void {
    [$mailer, $out, $stop] = fake_smtp();
    try {
        $mailer->send(['a@example.com'], 'Objet', "<p>\xE9t\xE9</p>");
    } finally {
        $stop();
    }
    check(str_contains(quoted_printable_decode(received($out)[0]), '<p>été</p>'));
});

test('mail: unreachable server → error message, no exception', function (): void {
    $mailer = new Mailer(['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'from_email' => 'x@example.com']);
    $results = $mailer->send(['a@example.com'], 'Objet', '<p>x</p>');
    check(is_string($results['a@example.com']) && $results['a@example.com'] !== '');
});
