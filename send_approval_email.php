<?php
declare(strict_types=1);

function sendCccApprovalEmail(array $submission): void
{
    $homeDirectory = getenv('HOME');
    $mailConfigPaths = [];
    if ($homeDirectory !== false && trim($homeDirectory) !== '') {
        $mailConfigPaths[] = rtrim($homeDirectory, '\\/') . DIRECTORY_SEPARATOR . 'mail-API' . DIRECTORY_SEPARATOR . 'ccc-private' . DIRECTORY_SEPARATOR . 'mail-config.php';
    }
    $mailConfigPaths[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mail-API' . DIRECTORY_SEPARATOR . 'ccc-private' . DIRECTORY_SEPARATOR . 'mail-config.php';
    $mailConfigPaths[] = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'mail-API' . DIRECTORY_SEPARATOR . 'ccc-private' . DIRECTORY_SEPARATOR . 'mail-config.php';
    $mailConfigPaths[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'ccc-private' . DIRECTORY_SEPARATOR . 'mail-config.php';
    $mailConfig = [];
    $mailConfigLoaded = false;
    foreach (array_unique($mailConfigPaths) as $mailConfigPath) {
        if (!is_file($mailConfigPath)) {
            continue;
        }
        if (!is_readable($mailConfigPath)) {
            throw new RuntimeException('File mail-config.php ditemukan tetapi tidak dapat dibaca oleh PHP. Periksa izin akses file.');
        }
        $loadedConfig = require $mailConfigPath;
        if (!is_array($loadedConfig)) {
            throw new RuntimeException('Konfigurasi email privat CCC harus mengembalikan array.');
        }
        $mailConfig = $loadedConfig;
        $mailConfigLoaded = true;
        break;
    }
    if (!$mailConfigLoaded) {
        throw new RuntimeException('File mail-config.php tidak ditemukan pada lokasi konfigurasi privat CCC yang didukung.');
    }

    $accessToken = getenv('HOSTINGER_API_TOKEN');
    if ($accessToken === false || trim($accessToken) === '') {
        $accessToken = $mailConfig['HOSTINGER_API_TOKEN'] ?? '';
    }
    $mailboxResourceId = getenv('HOSTINGER_MAILBOX_RESOURCE_ID');
    if ($mailboxResourceId === false || trim($mailboxResourceId) === '') {
        $mailboxResourceId = $mailConfig['HOSTINGER_MAILBOX_RESOURCE_ID'] ?? '';
    }

    if (!is_string($accessToken) || trim($accessToken) === '') {
        throw new RuntimeException('mail-config.php berhasil dibaca, tetapi HOSTINGER_API_TOKEN tidak ada, kosong, atau bukan string.');
    }
    if (!is_string($mailboxResourceId) || trim($mailboxResourceId) === '') {
        throw new RuntimeException('mail-config.php berhasil dibaca, tetapi HOSTINGER_MAILBOX_RESOURCE_ID tidak ada, kosong, atau bukan string.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Ekstensi PHP cURL belum aktif.');
    }

    $recipient = (string) ($submission['supervisorEmail'] ?? '');
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Alamat email supervisor tidak valid.');
    }

    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $formTitle = (string) ($submission['formTitle'] ?? 'Checklist');
    $respondent = (string) ($submission['respondent'] ?? '');
    $respondentEmail = (string) ($submission['respondentEmail'] ?? '');
    $supervisorName = (string) ($submission['supervisorName'] ?? 'Supervisor');
    $submittedAt = new DateTimeImmutable((string) ($submission['submittedAt'] ?? 'now'));
    $submittedAt = $submittedAt->setTimezone(new DateTimeZone('Asia/Jayapura'));
    $submittedAtText = $submittedAt->format('d-m-Y H:i:s') . ' WIT';

    $text = "Hasil checklist baru menunggu approval.\n\n";
    $text .= "Form: {$formTitle}\nResponden: {$respondent}\nEmail responden: {$respondentEmail}\n";
    $text .= "Supervisor: {$supervisorName}\nWaktu pengisian: {$submittedAtText}\n\nJawaban:\n";
    $answerItems = '';

    foreach (($submission['answers'] ?? []) as $index => $answer) {
        if (!is_array($answer)) {
            continue;
        }

        $number = (int) $index + 1;
        $question = (string) ($answer['question'] ?? '');
        $choice = (string) ($answer['choice'] ?? '');
        $text .= "{$number}. {$question}\nJawaban: {$choice}\n";

        $details = [];
        if (($answer['type'] ?? '') === 'supervisor') {
            $details[] = 'Tujuan approval: ' . $choice;
        } elseif (($answer['type'] ?? '') === 'verification' && $choice === 'Tidak') {
            $details[] = 'Peringatan: ' . (string) ($answer['verificationMessage'] ?? '');
            $details[] = 'Pilihan verifikasi: ' . (string) ($answer['verificationAnswer'] ?? '');
        } elseif (($answer['type'] ?? '') === 'yes_no' && $choice === 'Ya') {
            if ((string) ($answer['followup'] ?? '') !== '') {
                $details[] = (string) $answer['followup'];
                $details[] = 'Jawaban lanjutan: ' . (string) ($answer['followupAnswer'] ?? '');
            }
            foreach (($answer['followupChoiceAnswers'] ?? []) as $followupAnswer) {
                if (is_array($followupAnswer)) {
                    $details[] = (string) ($followupAnswer['question'] ?? '') . ': ' . (string) ($followupAnswer['choice'] ?? '');
                }
            }
        }

        foreach ($details as $detail) {
            $text .= $detail . "\n";
        }
        $text .= "\n";

        $answerItems .= '<li style="margin:0 0 16px">'
            . '<strong>' . $escape($question) . '</strong><br>'
            . 'Jawaban: ' . $escape($choice);
        if ($details !== []) {
            $answerItems .= '<ul style="margin:8px 0 0;padding-left:20px">';
            foreach ($details as $detail) {
                $answerItems .= '<li>' . $escape($detail) . '</li>';
            }
            $answerItems .= '</ul>';
        }
        $answerItems .= '</li>';
    }

    $appUrl = getenv('CCC_APP_URL');
    $appLink = '';
    if ($appUrl !== false && trim($appUrl) !== '') {
        $validatedUrl = filter_var(trim($appUrl), FILTER_VALIDATE_URL);
        $scheme = $validatedUrl === false ? '' : strtolower((string) parse_url($validatedUrl, PHP_URL_SCHEME));
        if ($validatedUrl === false || !in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('CCC_APP_URL harus berupa URL aplikasi dengan protokol HTTP atau HTTPS.');
        }
        $approvalUrl = rtrim($validatedUrl, '/') . '/index.php?page=my-submissions';
        $appLink = '<p style="margin:24px 0"><a href="' . $escape($approvalUrl) . '" style="display:inline-block;padding:11px 16px;background:#0f766e;color:#fff;text-decoration:none;border-radius:8px">Buka CCC untuk approval</a></p>'
            . '<p style="margin:0 0 20px;color:#6b7787;font-size:12px">Login diperlukan. Hanya supervisor terpilih atau administrator yang dapat menyetujui checklist.</p>';
        $text .= "Buka CCC untuk memeriksa dan menyetujui checklist:\n{$approvalUrl}\n"
            . "Login diperlukan. Hanya supervisor terpilih atau administrator yang dapat menyetujui checklist.\n";
    }

    $html = '<!doctype html><html lang="id"><body style="margin:0;padding:24px;background:#f4f6fa;font:14px/1.6 Arial,sans-serif;color:#17212f">'
        . '<div style="max-width:680px;margin:0 auto;padding:24px;background:#fff;border:1px solid #e6eaf0;border-radius:12px">'
        . '<h1 style="margin:0 0 8px;font-size:21px;color:#0f766e">Checklist menunggu approval</h1>'
        . '<p style="margin:0 0 20px;color:#6b7787">Hasil pengisian baru telah dikirim kepada Anda untuk ditinjau.</p>'
        . '<table style="width:100%;border-collapse:collapse;margin-bottom:22px">'
        . '<tr><td style="padding:7px 0;color:#6b7787;width:155px">Form</td><td style="padding:7px 0"><strong>' . $escape($formTitle) . '</strong></td></tr>'
        . '<tr><td style="padding:7px 0;color:#6b7787">Responden</td><td style="padding:7px 0">' . $escape($respondent) . '</td></tr>'
        . '<tr><td style="padding:7px 0;color:#6b7787">Email responden</td><td style="padding:7px 0">' . $escape($respondentEmail) . '</td></tr>'
        . '<tr><td style="padding:7px 0;color:#6b7787">Supervisor</td><td style="padding:7px 0">' . $escape($supervisorName) . '</td></tr>'
        . '<tr><td style="padding:7px 0;color:#6b7787">Waktu pengisian</td><td style="padding:7px 0">' . $escape($submittedAtText) . '</td></tr>'
        . '</table><h2 style="margin:0 0 12px;font-size:16px">Jawaban checklist</h2>'
        . '<ol style="padding-left:22px">' . $answerItems . '</ol>' . $appLink
        . '<p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #e6eaf0;color:#6b7787;font-size:12px">Email otomatis dari CRITICAL CONTROL CHECKLIST.</p>'
        . '</div></body></html>';

    $payload = json_encode([
        'to' => [$recipient],
        'displayName' => 'CCC Checklist',
        'subject' => 'Permintaan approval checklist: ' . $formTitle,
        'text' => $text,
        'html' => $html,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $endpoint = 'https://api.mail.hostinger.com/api/v1/mailboxes/'
        . rawurlencode(trim($mailboxResourceId)) . '/send';
    $curl = curl_init($endpoint);
    if ($curl === false) {
        throw new RuntimeException('Koneksi ke Hostinger Mail API tidak dapat dimulai.');
    }

    try {
        $configured = curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . trim($accessToken),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if (!$configured) {
            throw new RuntimeException('Pengaturan koneksi Hostinger Mail API gagal.');
        }

        $response = curl_exec($curl);
        if ($response === false) {
            throw new RuntimeException('Koneksi Hostinger Mail API gagal: ' . curl_error($curl));
        }
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($statusCode < 200 || $statusCode >= 300) {
            $apiError = json_decode($response, true);
            $errorCode = is_array($apiError) && is_string($apiError['code'] ?? null)
                ? (string) $apiError['code']
                : '';
            $errorMessage = is_array($apiError) && is_string($apiError['error'] ?? null)
                ? trim((string) $apiError['error'])
                : '';
            if (function_exists('mb_substr')) {
                $errorMessage = mb_substr($errorMessage, 0, 120, 'UTF-8');
            } else {
                $errorMessage = substr($errorMessage, 0, 120);
            }
            throw new RuntimeException(
                'Hostinger Mail API menolak pengiriman (HTTP ' . $statusCode . ')'
                . ($errorCode !== '' ? ' ' . $errorCode : '')
                . ($errorMessage !== '' ? ': ' . $errorMessage : '')
                . '.'
            );
        }
    } finally {
        curl_close($curl);
    }
}
