<?php

declare(strict_types=1);

namespace App;

final class Mailer
{
    private string $fromEmail;
    private string $fromName;

    public function __construct(string $fromEmail, string $fromName)
    {
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
    }

    public function send(string $toEmail, string $subject, string $body): array
    {
        $this->appendToOutbox($toEmail, $subject, $body);
        return ['sent' => true, 'channel' => 'log'];
    }

    private function appendToOutbox(string $toEmail, string $subject, string $body): void
    {
        $dir = __DIR__ . '/../storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/mail_outbox.log';
        $line = json_encode([
            'at' => date('c'),
            'to' => $toEmail,
            'subject' => $subject,
            'body' => $body,
        ], JSON_UNESCAPED_UNICODE);
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
    }
}
