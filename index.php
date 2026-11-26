<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jayapura');
session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

const APP_NAME = 'CRITICAL CONTROL CHECKLIST';
const MAX_IMAGE_SIZE = 4_194_304;
const MAX_SOP_FILE_SIZE = 4_194_304;
const MAX_SOP_THUMBNAIL_SIZE = 2_097_152;
const DEFAULT_CCC_APP_URL = 'https://jastik3.site';

$privateDirectoryLevel = basename(__DIR__) === 'public_html' ? 1 : 2;
$privateDir = dirname(__DIR__, $privateDirectoryLevel) . DIRECTORY_SEPARATOR . 'ccc-private';
$uploadDir = $privateDir . DIRECTORY_SEPARATOR . 'uploads';
$sopUploadDir = $uploadDir . DIRECTORY_SEPARATOR . 'sop';
$uploadDirectories = array_values(array_unique([
    $uploadDir,
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'ccc-private' . DIRECTORY_SEPARATOR . 'uploads',
]));
$dataFile = $privateDir . DIRECTORY_SEPARATOR . 'data.json';

foreach ([$privateDir, $uploadDir, $sopUploadDir] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        http_response_code(500);
        exit('Folder penyimpanan aplikasi tidak dapat dibuat. Periksa izin folder XAMPP.');
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function checklistStatusLabel(string $status): string
{
    return match ($status) {
        'Menunggu approval' => 'Pending Verified',
        'Disetujui' => 'Verified',
        default => $status,
    };
}

function checklistPostedText(mixed $value): string
{
    return is_string($value) ? trim($value) : '';
}

function verifierStartQuestionNumbers(array $questions): array
{
    $questionNumbers = [];
    foreach ($questions as $index => $question) {
        if (($question['type'] ?? 'yes_no') !== 'supervisor') {
            $questionNumbers[] = (int) $index + 1;
        }
    }

    return $questionNumbers;
}

function validateVerifierStartQuestion(array $questions, mixed $value): int
{
    $startQuestion = filter_var($value, FILTER_VALIDATE_INT);
    $validStartQuestions = verifierStartQuestionNumbers($questions);
    if ($validStartQuestions === []) {
        $validStartQuestions = [1];
    }
    if ($startQuestion === false || !in_array($startQuestion, $validStartQuestions, true)) {
        throw new RuntimeException('Pertanyaan awal verifikator harus berada dalam rentang pertanyaan checklist.');
    }

    return $startQuestion;
}

function buildVerifierAnswers(array $answers, array $post, int $startQuestion = 1): array
{
    $verifierChoices = $post['verifier_choice'] ?? null;
    $verificationAnswers = $post['verifier_verification_answer'] ?? [];
    $followupAnswers = $post['verifier_followup_answer'] ?? [];
    $followupChoiceAnswers = $post['verifier_followup_choice_answer'] ?? [];
    if (!is_array($verifierChoices)
        || !is_array($verificationAnswers)
        || !is_array($followupAnswers)
        || !is_array($followupChoiceAnswers)) {
        throw new RuntimeException('Jawaban verifikator tidak valid. Muat ulang halaman dan coba lagi.');
    }

    $verifiedAnswers = [];
    foreach ($answers as $answerIndex => $respondentAnswer) {
        $type = (string) ($respondentAnswer['type'] ?? 'yes_no');
        if ($type === 'supervisor') {
            continue;
        }
        $questionNumber = (int) $answerIndex + 1;
        if ($questionNumber < $startQuestion) {
            continue;
        }
        $verifierChoice = checklistPostedText($verifierChoices[$answerIndex] ?? null);
        $options = $respondentAnswer['options'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }
        if (in_array($type, ['yes_no', 'verification'], true)) {
            if (!in_array($verifierChoice, ['Ya', 'Tidak'], true)) {
                throw new RuntimeException('Verifikator wajib menjawab setiap pertanyaan Ya/Tidak.');
            }
        } elseif ($type === 'select') {
            if ($options !== []
                ? !in_array($verifierChoice, $options, true)
                : ($verifierChoice === '' || strlen($verifierChoice) > 500)) {
                throw new RuntimeException('Pilih jawaban yang valid untuk setiap pertanyaan verifikator.');
            }
        } elseif ($type === 'text') {
            if ($verifierChoice === '' || (function_exists('mb_strlen') ? mb_strlen($verifierChoice, 'UTF-8') : strlen($verifierChoice)) > 500) {
                throw new RuntimeException('Jawaban teks verifikator wajib diisi dan maksimal 500 karakter.');
            }
        } else {
            throw new RuntimeException('Tipe pertanyaan verifikator tidak dikenali.');
        }

        $verifiedAnswer = [
            'question' => (string) ($respondentAnswer['verifierQuestion'] ?? $respondentAnswer['question'] ?? ''),
            'respondentQuestion' => (string) ($respondentAnswer['question'] ?? ''),
            'respondentChoice' => (string) ($respondentAnswer['choice'] ?? ''),
            'type' => $type,
            'choice' => $verifierChoice,
            'followup' => (string) ($respondentAnswer['followup'] ?? ''),
            'followupAnswer' => '',
            'followupChoiceAnswers' => [],
            'verificationAnswer' => '',
        ];
        if ($type === 'verification' && $verifierChoice === 'Tidak') {
            $verificationAnswer = checklistPostedText($verificationAnswers[$answerIndex] ?? null);
            if ($options !== []
                ? !in_array($verificationAnswer, $options, true)
                : ($verificationAnswer === '' || strlen($verificationAnswer) > 500)) {
                throw new RuntimeException('Verifikator wajib mengisi alasan untuk jawaban Tidak.');
            }
            $verifiedAnswer['verificationAnswer'] = $verificationAnswer;
        }
        if ($type === 'yes_no'
            && (($respondentAnswer['choice'] ?? '') === 'Ya' || $verifierChoice === 'Ya')) {
            $questions = $respondentAnswer['followupChoices'] ?? [];
            if (is_array($questions) && $questions !== []) {
                $submittedFollowupChoices = $followupChoiceAnswers[$answerIndex] ?? [];
                if (!is_array($submittedFollowupChoices)) {
                    throw new RuntimeException('Jawaban pertanyaan lanjutan verifikator tidak valid.');
                }
                foreach ($questions as $followupIndex => $followupQuestion) {
                    if (!is_array($followupQuestion)) {
                        throw new RuntimeException('Pertanyaan lanjutan verifikator tidak valid.');
                    }
                    $followupChoice = checklistPostedText($submittedFollowupChoices[$followupIndex] ?? null);
                    $followupOptions = $followupQuestion['options'] ?? [];
                    if (!is_array($followupOptions)) {
                        $followupOptions = [];
                    }
                    if ($followupOptions !== []
                        ? !in_array($followupChoice, $followupOptions, true)
                        : ($followupChoice === '' || strlen($followupChoice) > 500)) {
                        throw new RuntimeException('Verifikator wajib menjawab setiap pertanyaan lanjutan.');
                    }
                    $verifiedAnswer['followupChoiceAnswers'][] = [
                        'question' => (string) ($followupQuestion['verifierText'] ?? $followupQuestion['text'] ?? ''),
                        'respondentQuestion' => (string) ($followupQuestion['text'] ?? ''),
                        'choice' => $followupChoice,
                    ];
                }
            } elseif ((string) ($respondentAnswer['followup'] ?? '') !== '') {
                $followupAnswer = checklistPostedText($followupAnswers[$answerIndex] ?? null);
                if ($followupAnswer === '' || (function_exists('mb_strlen') ? mb_strlen($followupAnswer, 'UTF-8') : strlen($followupAnswer)) > 500) {
                    throw new RuntimeException('Jawaban pertanyaan lanjutan verifikator wajib diisi dan maksimal 500 karakter.');
                }
                $verifiedAnswer['followupAnswer'] = $followupAnswer;
            }
        }
        $verifiedAnswers[] = $verifiedAnswer;
    }

    return $verifiedAnswers;
}

function renderVerifierForm(array $submission, string $csrf): string
{
    $html = '<form class="verifier-form verifier-modal-form" method="post">'
        . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
        . '<input type="hidden" name="action" value="approve">'
        . '<input type="hidden" name="submission_id" value="' . h((string) $submission['id']) . '">';
    $questionCount = 0;
    $startQuestion = max(1, (int) ($submission['verifierStartQuestion'] ?? 1));
    foreach (($submission['answers'] ?? []) as $index => $answer) {
        if (!is_array($answer) || ($answer['type'] ?? 'yes_no') === 'supervisor') {
            continue;
        }
        $questionCount++;
        $questionNumber = (int) $index + 1;
        $answerIndex = (int) $index;
        $type = (string) ($answer['type'] ?? 'yes_no');
        $options = is_array($answer['options'] ?? null) ? $answer['options'] : [];
        $verifierQuestion = (string) ($answer['verifierQuestion'] ?? $answer['question'] ?? '');
        $html .= '<section class="question-builder" style="background:var(--paper);margin:12px 0">'
            . '<div class="field-label">Pertanyaan responden</div><p style="margin:5px 0 10px">' . h((string) ($answer['question'] ?? '')) . '</p>'
            . '<div class="field-label">Jawaban responden</div><p style="margin:5px 0 14px;white-space:pre-wrap;overflow-wrap:anywhere">' . h((string) ($answer['choice'] ?? '')) . '</p>';
        if (($answer['type'] ?? '') === 'verification' && ($answer['choice'] ?? '') === 'Tidak') {
            $html .= '<p class="muted" style="margin:5px 0 4px">' . h((string) ($answer['verificationMessage'] ?? ''))
                . '</p><p class="muted" style="margin:5px 0 14px">Pilihan responden: '
                . h((string) ($answer['verificationAnswer'] ?? '')) . '</p>';
        }
        if (($answer['type'] ?? 'yes_no') === 'yes_no' && ($answer['choice'] ?? '') === 'Ya') {
            if ((string) ($answer['followup'] ?? '') !== '') {
                $html .= '<p class="muted" style="margin:5px 0 14px">' . h((string) $answer['followup'])
                    . ': ' . h((string) ($answer['followupAnswer'] ?? '')) . '</p>';
            }
            foreach (($answer['followupChoiceAnswers'] ?? []) as $followupAnswer) {
                if (is_array($followupAnswer)) {
                    $html .= '<p class="muted" style="margin:5px 0 14px">' . h((string) ($followupAnswer['question'] ?? ''))
                        . ': ' . h((string) ($followupAnswer['choice'] ?? '')) . '</p>';
                }
            }
        }
        if ($questionNumber < $startQuestion) {
            $html .= '<p class="help">Jawaban ulang tidak diwajibkan sebelum batas mulai pertanyaan verifikator.</p></section>';
            continue;
        }
        $html .= '<div class="field"><label for="verifier-choice-' . $answerIndex . '">'
            . h($verifierQuestion) . ' <span style="color:#b42318">*</span></label>';
        if (in_array($type, ['yes_no', 'verification'], true)) {
            $html .= '<div class="answer-choice"><label class="choice"><input class="verifier-choice" data-index="' . $answerIndex
                . '" type="radio" name="verifier_choice[' . $answerIndex . ']" value="Ya" required> Ya</label>'
                . '<label class="choice"><input class="verifier-choice" data-index="' . $answerIndex
                . '" type="radio" name="verifier_choice[' . $answerIndex . ']" value="Tidak" required> Tidak</label></div>';
        } elseif ($type === 'select' && $options !== []) {
            $html .= '<select class="verifier-choice" data-index="' . $answerIndex . '" id="verifier-choice-' . $answerIndex
                . '" name="verifier_choice[' . $answerIndex . ']" required><option value="">Pilih jawaban</option>';
            foreach ($options as $option) {
                $html .= '<option value="' . h((string) $option) . '">' . h((string) $option) . '</option>';
            }
            $html .= '</select>';
        } elseif ($type === 'text') {
            $html .= '<textarea class="verifier-choice" data-index="' . $answerIndex . '" id="verifier-choice-' . $answerIndex
                . '" name="verifier_choice[' . $answerIndex . ']" maxlength="500" required></textarea>';
        } else {
            $html .= '<input class="verifier-choice" data-index="' . $answerIndex . '" id="verifier-choice-' . $answerIndex
                . '" name="verifier_choice[' . $answerIndex . ']" type="text" maxlength="500" required>'
                . '<span class="help">Jawaban dicatat sebagai teks karena opsi checklist ini tidak tersedia pada data lama.</span>';
        }
        $html .= '</div>';

        if ($type === 'verification') {
            $html .= '<div class="field verifier-conditional" data-index="' . $answerIndex . '" data-show-choice="Tidak" hidden>'
                . '<label>Alasan / tindak lanjut verifikator <span style="color:#b42318">*</span></label>';
            if ($options !== []) {
                $html .= '<select name="verifier_verification_answer[' . $answerIndex . ']" disabled required><option value="">Pilih salah satu opsi</option>';
                foreach ($options as $option) {
                    $html .= '<option value="' . h((string) $option) . '">' . h((string) $option) . '</option>';
                }
                $html .= '</select>';
            } else {
                $html .= '<input name="verifier_verification_answer[' . $answerIndex . ']" type="text" maxlength="500" disabled required>';
            }
            $html .= '</div>';
        }

        if ($type === 'yes_no') {
            $followupQuestions = $answer['followupChoices'] ?? [];
            if (!is_array($followupQuestions) || $followupQuestions === []) {
                $followupQuestions = [];
                foreach (($answer['followupChoiceAnswers'] ?? []) as $legacyFollowup) {
                    if (is_array($legacyFollowup)) {
                        $followupQuestions[] = ['text' => (string) ($legacyFollowup['question'] ?? ''), 'verifierText' => (string) ($legacyFollowup['question'] ?? ''), 'options' => []];
                    }
                }
            }
            if ($followupQuestions !== []) {
                $html .= '<div class="verifier-conditional" data-index="' . $answerIndex
                    . '" data-show-choice="Ya" data-show-respondent-choice="' . h((string) ($answer['choice'] ?? ''))
                    . '" hidden>';
                foreach ($followupQuestions as $followupIndex => $followupQuestion) {
                    if (!is_array($followupQuestion)) {
                        continue;
                    }
                    $followupOptions = is_array($followupQuestion['options'] ?? null) ? $followupQuestion['options'] : [];
                    $html .= '<div class="field"><label>' . h((string) ($followupQuestion['verifierText'] ?? $followupQuestion['text'] ?? ''))
                        . ' <span style="color:#b42318">*</span></label>';
                    if ($followupOptions !== []) {
                        $html .= '<select name="verifier_followup_choice_answer[' . $answerIndex . '][' . (int) $followupIndex
                            . ']" disabled required><option value="">Pilih jawaban</option>';
                        foreach ($followupOptions as $option) {
                            $html .= '<option value="' . h((string) $option) . '">' . h((string) $option) . '</option>';
                        }
                        $html .= '</select>';
                    } else {
                        $html .= '<input name="verifier_followup_choice_answer[' . $answerIndex . '][' . (int) $followupIndex
                            . ']" type="text" maxlength="500" disabled required>';
                    }
                    $html .= '</div>';
                }
                $html .= '</div>';
            } elseif ((string) ($answer['followup'] ?? '') !== '') {
                $html .= '<div class="field verifier-conditional" data-index="' . $answerIndex
                    . '" data-show-choice="Ya" data-show-respondent-choice="' . h((string) ($answer['choice'] ?? ''))
                    . '" hidden>'
                    . '<label>' . h((string) $answer['followup']) . ' <span style="color:#b42318">*</span></label>'
                    . '<textarea name="verifier_followup_answer[' . $answerIndex . ']" maxlength="500" disabled required></textarea></div>';
            }
        }
        $html .= '</section>';
    }

    if ($questionCount === 0) {
        return $html . '<p class="muted">Tidak ada pertanyaan checklist responden yang perlu diulang.</p>'
            . '<button class="btn btn-primary" type="submit">✓ Simpan verifikasi</button></form>';
    }

    return $html . '<button class="btn btn-primary" type="submit">✓ Simpan verifikasi</button></form>';
}

function checklistVerifierAnswerLines(array $submission): array
{
    $lines = [];
    foreach (($submission['verifierAnswers'] ?? []) as $answer) {
        if (!is_array($answer)) {
            continue;
        }
        $lines[] = (string) ($answer['question'] ?? '') . ': ' . (string) ($answer['choice'] ?? '');
        if ((string) ($answer['verificationAnswer'] ?? '') !== '') {
            $lines[] = '   Alasan: ' . (string) $answer['verificationAnswer'];
        }
        if ((string) ($answer['followupAnswer'] ?? '') !== '') {
            $lines[] = '   ' . (string) ($answer['followup'] ?? 'Tindak lanjut')
                . ': ' . (string) $answer['followupAnswer'];
        }
        foreach (($answer['followupChoiceAnswers'] ?? []) as $followupAnswer) {
            if (is_array($followupAnswer)) {
                $lines[] = '   ' . (string) ($followupAnswer['question'] ?? '')
                    . ': ' . (string) ($followupAnswer['choice'] ?? '');
            }
        }
    }

    return $lines;
}

function checklistAnswerLines(array $submission): array
{
    $lines = [];
    foreach (($submission['answers'] ?? []) as $index => $answer) {
        if (!is_array($answer)) {
            continue;
        }

        $question = (string) ($answer['question'] ?? '');
        $choice = (string) ($answer['choice'] ?? '');
        $lines[] = ((int) $index + 1) . '. ' . $question . ': ' . $choice;

        if (($answer['type'] ?? '') === 'verification' && $choice === 'Tidak') {
            $lines[] = '   Peringatan: ' . (string) ($answer['verificationMessage'] ?? '');
            $lines[] = '   Pilihan verifikasi: ' . (string) ($answer['verificationAnswer'] ?? '');
        }
        if (($answer['type'] ?? 'yes_no') === 'yes_no' && $choice === 'Ya') {
            if ((string) ($answer['followup'] ?? '') !== '') {
                $lines[] = '   ' . (string) $answer['followup'] . ': ' . (string) ($answer['followupAnswer'] ?? '');
            }
            foreach (($answer['followupChoiceAnswers'] ?? []) as $followupAnswer) {
                if (is_array($followupAnswer)) {
                    $lines[] = '   ' . (string) ($followupAnswer['question'] ?? '')
                        . ': ' . (string) ($followupAnswer['choice'] ?? '');
                }
            }
        }
    }

    return $lines;
}

function checklistPdfText(string $text): string
{
    if (!function_exists('iconv')) {
        throw new RuntimeException('Ekstensi PHP iconv diperlukan untuk membuat PDF riwayat checklist.');
    }
    $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
    if ($converted === false) {
        throw new RuntimeException('Teks riwayat checklist tidak dapat dikonversi untuk PDF.');
    }
    $converted = preg_replace('/[\x00-\x1F\x7F]/', ' ', $converted);
    if ($converted === null) {
        throw new RuntimeException('Teks riwayat checklist tidak dapat diproses untuk PDF.');
    }

    return $converted;
}

function escapeChecklistPdfLiteral(string $text): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function checklistPdfString(string $text): string
{
    return escapeChecklistPdfLiteral(checklistPdfText($text));
}

function checklistXmlEscape(string $text): string
{
    $valid = preg_replace(
        '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
        '',
        $text
    );
    if ($valid === null) {
        throw new RuntimeException('Teks riwayat checklist tidak dapat diproses untuk Excel.');
    }

    return htmlspecialchars($valid, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function createChecklistExcel(array $submissions): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ekstensi PHP zip diperlukan untuk membuat file Excel.');
    }

    $columns = [
        'ID Checklist',
        'Checklist',
        'Responden',
        'Email responden',
        'Waktu dikirim (WIT)',
        'Supervisor',
        'Status verifikasi',
        'Diverifikasi oleh',
        'Waktu diverifikasi (WIT)',
        'Jawaban checklist',
        'Jawaban Supervisor/Verifikator',
    ];
    $rows = [$columns];
    foreach ($submissions as $submission) {
        $rows[] = [
            (string) $submission['id'],
            (string) $submission['formTitle'],
            (string) $submission['respondent'],
            (string) $submission['respondentEmail'],
            date('d-m-Y H:i', strtotime((string) $submission['submittedAt'])) . ' WIT',
            (string) $submission['supervisorName'],
            checklistStatusLabel((string) $submission['status']),
            (string) $submission['approvedBy'],
            $submission['approvedAt'] !== ''
                ? date('d-m-Y H:i', strtotime((string) $submission['approvedAt'])) . ' WIT'
                : '',
            implode("\n", checklistAnswerLines($submission)),
            implode("\n", checklistVerifierAnswerLines($submission)),
        ];
    }
    if ($submissions === []) {
        $rows[] = ['Belum ada riwayat checklist.'];
    }

    $columnLetters = range('A', 'K');
    $sheetRows = '';
    foreach ($rows as $rowIndex => $row) {
        $rowNumber = $rowIndex + 1;
        $sheetRows .= '<row r="' . $rowNumber . '">';
        foreach ($row as $columnIndex => $value) {
            $cellReference = $columnLetters[$columnIndex] . $rowNumber;
            $style = $rowIndex === 0 ? '1' : '2';
            $sheetRows .= '<c r="' . $cellReference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
                . checklistXmlEscape($value) . '</t></is></c>';
        }
        $sheetRows .= '</row>';
    }
    $lastRow = count($rows);
    $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="A1:K' . $lastRow . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
        . '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/>'
        . '<cols><col min="1" max="1" width="22" customWidth="1"/>'
        . '<col min="2" max="4" width="24" customWidth="1"/>'
        . '<col min="5" max="9" width="22" customWidth="1"/>'
        . '<col min="10" max="11" width="60" customWidth="1"/></cols>'
        . '<sheetData>' . $sheetRows . '</sheetData><autoFilter ref="A1:K' . $lastRow . '"/>'
        . '</worksheet>';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Riwayat Checklist" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/>'
            . '<bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1">'
            . '<alignment vertical="top" wrapText="1"/></xf></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => $worksheet,
    ];

    $temporaryFile = tempnam(sys_get_temp_dir(), 'ccc-xlsx-');
    if ($temporaryFile === false) {
        throw new RuntimeException('File sementara untuk ekspor Excel tidak dapat dibuat.');
    }

    $archive = null;
    try {
        $archive = new ZipArchive();
        if ($archive->open($temporaryFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Arsip Excel tidak dapat dibuat.');
        }
        foreach ($files as $path => $contents) {
            if (!$archive->addFromString($path, $contents)) {
                throw new RuntimeException('Komponen file Excel gagal dibuat: ' . $path);
            }
        }
        if (!$archive->close()) {
            $archive = null;
            throw new RuntimeException('Arsip Excel gagal diselesaikan.');
        }
        $archive = null;
        $excel = file_get_contents($temporaryFile);
        if ($excel === false) {
            throw new RuntimeException('File Excel hasil ekspor tidak dapat dibaca.');
        }

        return $excel;
    } finally {
        if ($archive instanceof ZipArchive) {
            $archive->close();
        }
        if (is_file($temporaryFile) && !unlink($temporaryFile)) {
            throw new RuntimeException('File sementara ekspor Excel tidak dapat dibersihkan.');
        }
    }
}

function createChecklistPdf(array $submissions): string
{
    $generatedAt = date('d-m-Y H:i') . ' WIT';
    $lines = [];
    foreach ($submissions as $submission) {
        $lines[] = 'Checklist: ' . (string) $submission['formTitle'];
        $lines[] = 'ID: ' . (string) $submission['id']
            . ' | Responden: ' . (string) $submission['respondent']
            . ' | Email: ' . (string) $submission['respondentEmail'];
        $lines[] = 'Dikirim: ' . date('d-m-Y H:i', strtotime((string) $submission['submittedAt'])) . ' WIT'
            . ' | Supervisor: ' . (string) $submission['supervisorName'];
        $lines[] = 'Status verifikasi: ' . checklistStatusLabel((string) $submission['status'])
            . ($submission['approvedBy'] !== '' ? ' oleh ' . (string) $submission['approvedBy'] : '')
            . ($submission['approvedAt'] !== ''
                ? ' pada ' . date('d-m-Y H:i', strtotime((string) $submission['approvedAt'])) . ' WIT'
                : '');
        $answerLines = checklistAnswerLines($submission);
        array_push($lines, ...($answerLines !== [] ? $answerLines : ['Jawaban checklist tidak tersedia.']));
        $verifierAnswerLines = checklistVerifierAnswerLines($submission);
        if ($verifierAnswerLines !== []) {
            $lines[] = 'Jawaban Supervisor/Verifikator:';
            array_push($lines, ...$verifierAnswerLines);
        }
        $lines[] = '';
    }
    if ($lines === []) {
        $lines[] = 'Belum ada riwayat checklist.';
    }

    $wrappedLines = [];
    foreach ($lines as $line) {
        $convertedLine = checklistPdfText($line);
        foreach (explode("\n", wordwrap($convertedLine, 92, "\n", true)) as $wrappedLine) {
            $wrappedLines[] = escapeChecklistPdfLiteral($wrappedLine);
        }
    }
    $bodyPages = array_chunk($wrappedLines, 51);
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    ];
    $pageReferences = [];
    foreach ($bodyPages as $pageIndex => $pageLines) {
        $pageObjectNumber = 4 + ($pageIndex * 2);
        $contentObjectNumber = $pageObjectNumber + 1;
        $pageReferences[] = $pageObjectNumber . ' 0 R';
        $pageText = [
            checklistPdfString('CRITICAL CONTROL CHECKLIST - RIWAYAT'),
            checklistPdfString('Diekspor ' . $generatedAt . ' | Halaman ' . ($pageIndex + 1) . ' dari ' . count($bodyPages)),
            '',
            ...$pageLines,
        ];
        $content = "BT\n/F1 9 Tf\n50 790 Td\n14 TL\n";
        foreach ($pageText as $line) {
            $content .= '(' . $line . ") Tj\nT*\n";
        }
        $content .= "ET";
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
            . '/Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentObjectNumber . ' 0 R >>';
        $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }
    $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $pageReferences)
        . '] /Count ' . count($pageReferences) . ' >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1)
        . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

    return $pdf;
}

function canApproveSubmission(?array $user, array $submission): bool
{
    if ($user === null || !($user['isActive'] ?? true)) {
        return false;
    }
    if (($user['role'] ?? '') === 'Administrator') {
        return true;
    }
    if (($submission['supervisor'] ?? null) === ($user['username'] ?? null)) {
        return true;
    }
    return in_array($user['role'] ?? '', ['PJO', 'HSE'], true)
        && strcasecmp(trim((string) ($submission['supervisorEmail'] ?? '')), trim((string) ($user['email'] ?? ''))) === 0;
}

function canManageSop(?array $user): bool
{
    return $user !== null
        && ($user['isActive'] ?? true)
        && in_array($user['role'] ?? '', ['Administrator', 'HSE'], true);
}

function loadData(string $file): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'database.php';
    global $pdo;
    $pdo = getDatabaseConnection();
    ensureCccSchema($pdo);

    if (!hasCccMeta($pdo, 'initial_data_loaded')) {
        if ((int) $pdo->query("SELECT GET_LOCK('ccc_initial_data', 10)")->fetchColumn() !== 1) {
            throw new RuntimeException('Inisialisasi database CCC sedang berlangsung. Muat ulang halaman beberapa saat lagi.');
        }
        try {
            if (!hasCccMeta($pdo, 'initial_data_loaded')) {
                $data = fetchCccData($pdo);
                if ($data['users'] === [] && $data['forms'] === [] && $data['submissions'] === []) {
                    if (is_file($file)) {
                        $json = file_get_contents($file);
                        $legacyData = $json === false ? null : json_decode($json, true);
                        if (!is_array($legacyData) || !isset($legacyData['users'], $legacyData['forms'], $legacyData['submissions'])) {
                            throw new RuntimeException('Data lama tidak dapat dimigrasikan karena formatnya tidak valid.');
                        }
                        $data = $legacyData;
                    } else {
                        $users = [];
                        foreach ([
                            ['admin', 'Admin CCC', 'Administrator', 'admin@ccc.local', 'Admin123!'],
                            ['supervisor', 'Supervisor CCC', 'Supervisor', 'supervisor@ccc.local', 'Supervisor123!'],
                            ['responden', 'User Responden', 'Respondent', 'responden@ccc.local', 'User123!'],
                        ] as [$username, $name, $role, $email, $password]) {
                            $users[$username] = [
                                'username' => $username,
                                'name' => $name,
                                'role' => $role,
                                'email' => $email,
                                'password' => password_hash($password, PASSWORD_DEFAULT),
                                'profileImage' => '',
                                'theme' => 'light',
                            ];
                        }
                        $data = [
                            'users' => $users,
                            'forms' => [[
                                'id' => bin2hex(random_bytes(6)),
                                'title' => 'Pemeriksaan area kerja',
                                'description' => 'Gunakan checklist ini untuk memastikan area kerja dalam kondisi aman.',
                                'questions' => [
                                    ['text' => 'Apakah area kerja bebas dari tumpahan atau hambatan?', 'image' => '', 'followup' => 'Jelaskan tindakan yang diperlukan untuk mengamankan area.', 'followupImage' => ''],
                                    ['text' => 'Apakah alat pelindung diri tersedia dan digunakan dengan benar?', 'image' => '', 'followup' => 'Sebutkan alat pelindung diri yang belum tersedia atau belum digunakan.', 'followupImage' => ''],
                                    ['text' => 'Apakah kondisi mesin dan peralatan aman untuk digunakan?', 'image' => '', 'followup' => 'Jelaskan kondisi tidak aman dan tindakan yang sudah dilakukan.', 'followupImage' => ''],
                                ],
                                'createdAt' => date(DATE_ATOM),
                            ]],
                            'submissions' => [],
                        ];
                    }
                    persistCccData($pdo, $data, ['users' => [], 'forms' => [], 'submissions' => []]);
                }
                setCccMeta($pdo, 'initial_data_loaded', date(DATE_ATOM));
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('ccc_initial_data')");
        }
    }

    $data = fetchCccData($pdo);
    if (!isset($data['users'], $data['forms'], $data['submissions'])) {
        throw new RuntimeException('Struktur data database CCC tidak valid.');
    }
    $GLOBALS['cccDataSnapshot'] = $data;
    return $data;
}

function saveData(string $file, array $data): void
{
    global $pdo;
    $previousData = $GLOBALS['cccDataSnapshot'] ?? ['users' => [], 'forms' => [], 'submissions' => []];
    persistCccData($pdo, $data, $previousData);
    $GLOBALS['cccDataSnapshot'] = $data;
}

function fetchSopFiles(PDO $connection): array
{
    return $connection->query(
        'SELECT id, title, description, original_name, stored_name, mime_type, file_size, thumbnail_name, created_at, updated_at
         FROM ccc_sop_files ORDER BY updated_at DESC, id DESC'
    )->fetchAll();
}

function fetchSopFile(PDO $connection, string $id): ?array
{
    $statement = $connection->prepare(
        'SELECT id, title, description, original_name, stored_name, mime_type, file_size, thumbnail_name, created_at, updated_at
         FROM ccc_sop_files WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    return is_array($record) ? $record : null;
}

function redirectTo(string $query = ''): never
{
    header('Location: index.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function historyPageUrl(int $pageNumber, int $pageSize): string
{
    return 'index.php?' . http_build_query([
        'page' => 'my-submissions',
        'per_page' => $pageSize,
        'history_page' => $pageNumber,
    ]);
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function requireCsrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid atau telah kedaluwarsa. Muat ulang halaman dan coba lagi.');
    }
}

function storeImage(array $upload, string $directory): string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Gambar gagal diunggah. Coba pilih file lain.');
    }
    if (($upload['size'] ?? 0) > MAX_IMAGE_SIZE || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        throw new RuntimeException('Ukuran gambar maksimal 4 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Format gambar harus JPG, PNG, atau WEBP.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Gambar tidak dapat disimpan. Periksa izin folder uploads.');
    }
    return $filename;
}

function storeSopDocument(array $upload, string $directory): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
            ? 'Pilih file SOP yang akan diunggah.'
            : 'File SOP gagal diunggah. Pastikan ukuran maksimal 4 MB.');
    }
    $temporaryPath = $upload['tmp_name'] ?? '';
    $size = is_string($temporaryPath) && is_file($temporaryPath) ? filesize($temporaryPath) : false;
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath) || $size === false || $size < 1 || $size > MAX_SOP_FILE_SIZE) {
        throw new RuntimeException('Ukuran file SOP harus lebih dari 0 dan maksimal 4 MB.');
    }

    $originalName = basename(str_replace('\\', '/', (string) ($upload['name'] ?? '')));
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedMimeTypes = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];
    if (!isset($allowedMimeTypes[$extension])) {
        throw new RuntimeException('Format file SOP harus PDF, DOC, DOCX, XLS, atau XLSX.');
    }
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    if (!is_string($mimeType) || !in_array($mimeType, $allowedMimeTypes[$extension], true)) {
        throw new RuntimeException('Isi file tidak sesuai dengan ekstensi PDF, Word, atau Excel yang dipilih.');
    }
    if ($originalName === '' || strlen($originalName) > 255 || !preg_match('//u', $originalName)) {
        throw new RuntimeException('Nama file SOP tidak valid atau terlalu panjang.');
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($temporaryPath, $directory . DIRECTORY_SEPARATOR . $storedName)) {
        throw new RuntimeException('File SOP tidak dapat disimpan. Periksa izin folder penyimpanan.');
    }

    return [
        'originalName' => $originalName,
        'storedName' => $storedName,
        'mimeType' => $mimeType,
        'size' => (int) $size,
    ];
}

function storeSopThumbnail(array $upload, string $directory): string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Thumbnail gagal diunggah. Pastikan ukuran maksimal 2 MB.');
    }
    $temporaryPath = $upload['tmp_name'] ?? '';
    $size = is_string($temporaryPath) && is_file($temporaryPath) ? filesize($temporaryPath) : false;
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath) || $size === false || $size < 1 || $size > MAX_SOP_THUMBNAIL_SIZE) {
        throw new RuntimeException('Ukuran thumbnail harus lebih dari 0 dan maksimal 2 MB.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $imageInfo = getimagesize($temporaryPath);
    if (!is_string($mimeType) || !isset($extensions[$mimeType]) || $imageInfo === false || $imageInfo['mime'] !== $mimeType) {
        throw new RuntimeException('Thumbnail harus berupa gambar JPG, PNG, atau WEBP yang valid.');
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($temporaryPath, $directory . DIRECTORY_SEPARATOR . $storedName)) {
        throw new RuntimeException('Thumbnail tidak dapat disimpan. Periksa izin folder penyimpanan.');
    }

    return $storedName;
}

function sopAssetPath(string $filename, string $directory): ?string
{
    if (!preg_match('/^[a-f0-9]{32}\.(pdf|doc|docx|xls|xlsx|jpg|png|webp)$/', $filename)) {
        return null;
    }
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    return is_file($path) ? $path : null;
}

function removeSopAssets(array $record, string $directory): bool
{
    $removed = true;
    foreach (['stored_name', 'thumbnail_name'] as $field) {
        $filename = (string) ($record[$field] ?? '');
        if ($filename === '') {
            continue;
        }
        $path = sopAssetPath($filename, $directory);
        if ($path !== null && !unlink($path)) {
            error_log('CCC SOP asset cleanup failed for ' . $filename . '.');
            $removed = false;
        }
    }

    return $removed;
}

function getUploadedFile(string $field, int $index): array
{
    $files = $_FILES[$field] ?? null;
    if (!is_array($files) || !isset($files['error'][$index])) {
        return ['error' => UPLOAD_ERR_NO_FILE];
    }

    return [
        'name' => $files['name'][$index] ?? '',
        'type' => $files['type'][$index] ?? '',
        'tmp_name' => $files['tmp_name'][$index] ?? '',
        'error' => $files['error'][$index],
        'size' => $files['size'][$index] ?? 0,
    ];
}

function getSingleUploadedFile(string $field): array
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || !isset($file['error'])) {
        return ['error' => UPLOAD_ERR_NO_FILE];
    }

    return $file;
}

function buildQuestionsFromPost(string $uploadDir, array $existingQuestions = []): array
{
    $texts = $_POST['question_text'] ?? [];
    $verifierTexts = $_POST['verifier_question_text'] ?? [];
    $types = $_POST['question_type'] ?? [];
    $optionTexts = $_POST['question_options'] ?? [];
    $originalIndices = $_POST['question_original_index'] ?? [];
    if (!is_array($texts) || count($texts) === 0 || !is_array($types)) {
        throw new RuntimeException('Form harus memiliki minimal satu pertanyaan.');
    }
    if (!is_array($verifierTexts)) {
        throw new RuntimeException('Daftar pertanyaan verifikator tidak valid.');
    }

    $questions = [];
    $supervisorQuestionCount = 0;
    foreach ($texts as $index => $questionText) {
        $questionText = trim((string) $questionText);
        $type = (string) ($types[$index] ?? 'yes_no');
        if ($questionText === '' || !in_array($type, ['yes_no', 'verification', 'select', 'text', 'supervisor'], true)) {
            throw new RuntimeException('Pertanyaan tidak valid. Periksa kembali tipe dan isi pertanyaan.');
        }
        $verifierText = trim((string) ($verifierTexts[$index] ?? $questionText));
        if ($verifierText === '' || (function_exists('mb_strlen') ? mb_strlen($verifierText, 'UTF-8') : strlen($verifierText)) > 500) {
            throw new RuntimeException('Pertanyaan verifikator wajib diisi dan maksimal 500 karakter.');
        }
        if ($type === 'supervisor' && ++$supervisorQuestionCount > 1) {
            throw new RuntimeException('Setiap form hanya dapat memiliki satu pilihan supervisor untuk tujuan approval.');
        }

        $options = [];
        if (in_array($type, ['select', 'verification'], true)) {
            $rawOptions = (string) ($optionTexts[$index] ?? '');
            $options = array_values(array_unique(array_filter(
                array_map('trim', preg_split('/\R/u', $rawOptions) ?: []),
                static fn(string $option): bool => $option !== ''
            )));
            if (count($options) < 1 || count($options) > 20) {
                throw new RuntimeException($type === 'verification'
                    ? 'Pilihan verifikasi harus memiliki minimal 1 dan maksimal 20 opsi yang berbeda.'
                    : 'Pertanyaan pilihan harus memiliki minimal 1 dan maksimal 20 opsi yang berbeda.');
            }
            if (array_filter($options, static fn(string $option): bool => strlen($option) > 120)) {
                throw new RuntimeException('Setiap opsi jawaban maksimal 120 karakter.');
            }
        }

        $verificationMessage = '';
        if ($type === 'verification') {
            $verificationMessages = $_POST['verification_message'] ?? [];
            $verificationMessage = trim((string) ($verificationMessages[$index] ?? ''));
            if ($verificationMessage === '' || (function_exists('mb_strlen') ? mb_strlen($verificationMessage, 'UTF-8') : strlen($verificationMessage)) > 500) {
                throw new RuntimeException('Pesan peringatan verifikasi wajib diisi dan maksimal 500 karakter.');
            }
        }

        $followupChoices = [];
        if ($type === 'yes_no') {
            $followupChoiceTexts = $_POST['followup_choice_text'][$index] ?? [];
            $verifierFollowupChoiceTexts = $_POST['verifier_followup_choice_text'][$index] ?? $followupChoiceTexts;
            $followupChoiceOptions = $_POST['followup_choice_options'][$index] ?? [];
            if (!is_array($followupChoiceTexts)
                || !is_array($verifierFollowupChoiceTexts)
                || !is_array($followupChoiceOptions)
                || count($followupChoiceTexts) !== count($followupChoiceOptions)
                || count($followupChoiceTexts) !== count($verifierFollowupChoiceTexts)) {
                throw new RuntimeException('Pertanyaan lanjutan pilihan tidak valid.');
            }
            foreach ($followupChoiceTexts as $followupIndex => $followupChoiceText) {
                $followupChoiceText = trim((string) $followupChoiceText);
                $verifierFollowupChoiceText = trim((string) ($verifierFollowupChoiceTexts[$followupIndex] ?? $followupChoiceText));
                $rawOptions = (string) ($followupChoiceOptions[$followupIndex] ?? '');
                $choiceOptions = array_values(array_unique(array_filter(
                    array_map('trim', preg_split('/\R/u', $rawOptions) ?: []),
                    static fn(string $option): bool => $option !== ''
                )));
                if ($followupChoiceText === '') {
                    throw new RuntimeException('Teks setiap pertanyaan lanjutan pilihan wajib diisi.');
                }
                if ($verifierFollowupChoiceText === ''
                    || (function_exists('mb_strlen') ? mb_strlen($verifierFollowupChoiceText, 'UTF-8') : strlen($verifierFollowupChoiceText)) > 500) {
                    throw new RuntimeException('Pertanyaan lanjutan verifikator wajib diisi dan maksimal 500 karakter.');
                }
                if (count($choiceOptions) < 1 || count($choiceOptions) > 20) {
                    throw new RuntimeException('Setiap pertanyaan lanjutan pilihan harus memiliki 1 sampai 20 opsi.');
                }
                if (array_filter($choiceOptions, static fn(string $option): bool => strlen($option) > 120)) {
                    throw new RuntimeException('Setiap opsi pertanyaan lanjutan maksimal 120 karakter.');
                }
                $followupChoices[] = [
                    'text' => $followupChoiceText,
                    'verifierText' => $verifierFollowupChoiceText,
                    'options' => $choiceOptions,
                ];
            }
            if ($followupChoices === []) {
                throw new RuntimeException('Setiap pertanyaan Ya/Tidak harus memiliki minimal satu pertanyaan lanjutan pilihan.');
            }
        }

        $originalIndex = filter_var($originalIndices[$index] ?? -1, FILTER_VALIDATE_INT);
        $originalQuestion = $originalIndex !== false && $originalIndex >= 0
            ? ($existingQuestions[$originalIndex] ?? [])
            : [];
        $mainImage = storeImage(getUploadedFile('question_image', (int) $index), $uploadDir);
        $followupImage = storeImage(getUploadedFile('followup_image', (int) $index), $uploadDir);

        $questions[] = [
            'type' => $type,
            'text' => $questionText,
            'verifierText' => $verifierText,
            'image' => $mainImage !== '' ? $mainImage : ($originalQuestion['image'] ?? ''),
            'options' => $options,
            'verificationMessage' => $verificationMessage,
            'followup' => '',
            'followupChoices' => $followupChoices,
            'followupImage' => $type === 'yes_no'
                ? ($followupImage !== '' ? $followupImage : ($originalQuestion['followupImage'] ?? ''))
                : '',
        ];
    }

    return $questions;
}

function mediaUrl(string $filename): string
{
    return 'index.php?media=' . rawurlencode($filename);
}

function findUploadedImagePath(string $filename, array $directories): ?string
{
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) {
        return null;
    }

    foreach ($directories as $directory) {
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function removeUploadedImage(string $filename, array $directories): bool
{
    if ($filename === '') {
        return true;
    }
    $path = findUploadedImagePath($filename, $directories);
    if ($path === null || unlink($path)) {
        return true;
    }

    error_log('CCC uploaded image cleanup failed for ' . $filename . '.');
    return false;
}

function cccApplicationBaseUrl(): string
{
    $configuredUrl = getenv('CCC_APP_URL');
    if ($configuredUrl !== false && trim($configuredUrl) !== '') {
        $configuredUrl = rtrim(trim($configuredUrl), '/');
        $validatedUrl = filter_var($configuredUrl, FILTER_VALIDATE_URL);
        $scheme = $validatedUrl === false ? '' : strtolower((string) parse_url($validatedUrl, PHP_URL_SCHEME));
        if ($validatedUrl === false || !in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('CCC_APP_URL harus berupa alamat dasar aplikasi dengan protokol HTTP atau HTTPS.');
        }

        return $configuredUrl;
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
    $hostName = strtolower((string) parse_url('http://' . $host, PHP_URL_HOST));
    if (in_array($hostName, ['localhost', '127.0.0.1', '::1'], true)) {
        $lanAddress = gethostbyname(gethostname());
        if (filter_var($lanAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && !str_starts_with($lanAddress, '127.')) {
            $host = preg_replace('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $lanAddress . '$2', $host) ?? $host;
        }

        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        return $scheme . '://' . $host . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    }

    return DEFAULT_CCC_APP_URL;
}

try {
    $data = loadData($dataFile);
} catch (Throwable $exception) {
    http_response_code(500);
    exit(h($exception->getMessage()));
}

if (isset($_GET['sop_file'])) {
    $sopId = (string) $_GET['sop_file'];
    $record = preg_match('/^[a-f0-9]{32}$/', $sopId) ? fetchSopFile($pdo, $sopId) : null;
    $path = $record !== null ? sopAssetPath((string) $record['stored_name'], $sopUploadDir) : null;
    if ($record === null || $path === null) {
        http_response_code(404);
        exit('File SOP tidak ditemukan.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!is_string($mimeType) || $mimeType !== $record['mime_type']) {
        http_response_code(415);
        exit('File SOP tidak dapat disajikan karena formatnya tidak valid.');
    }
    $downloadName = basename(str_replace('\\', '/', (string) $record['original_name']));
    $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
    if (!is_string($asciiName) || $asciiName === '') {
        $asciiName = 'sop-file';
    }
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    readfile($path);
    exit;
}

if (isset($_GET['sop_thumbnail'])) {
    $sopId = (string) $_GET['sop_thumbnail'];
    $record = preg_match('/^[a-f0-9]{32}$/', $sopId) ? fetchSopFile($pdo, $sopId) : null;
    $path = $record !== null && $record['thumbnail_name'] !== ''
        ? sopAssetPath((string) $record['thumbnail_name'], $sopUploadDir)
        : null;
    if ($record === null || $path === null) {
        http_response_code(404);
        exit;
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        http_response_code(415);
        exit;
    }
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    readfile($path);
    exit;
}

if (isset($_GET['media'])) {
    $filename = (string) $_GET['media'];
    $mediaUser = $data['users'][$_SESSION['user'] ?? ''] ?? null;
    $hasActiveSession = $mediaUser !== null && ($mediaUser['isActive'] ?? true);
    $isPublicFormMedia = false;
    if (!$hasActiveSession) {
        foreach ($data['forms'] as $form) {
            if (($form['logo'] ?? '') === $filename) {
                $isPublicFormMedia = true;
                break;
            }
            foreach ($form['questions'] as $question) {
                if (($question['image'] ?? '') === $filename || ($question['followupImage'] ?? '') === $filename) {
                    $isPublicFormMedia = true;
                    break 2;
                }
            }
        }
    }
    if ((!$hasActiveSession && !$isPublicFormMedia) || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) {
        http_response_code(404);
        exit;
    }
    $path = findUploadedImagePath($filename, $uploadDirectories);
    if ($path === null) {
        http_response_code(404);
        exit;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        http_response_code(415);
        exit;
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

if (isset($_GET['qr_code'])) {
    $currentAccount = $data['users'][$_SESSION['user'] ?? ''] ?? null;
    if (!$currentAccount || !($currentAccount['isActive'] ?? true) || $currentAccount['role'] !== 'Administrator') {
        http_response_code(403);
        exit('Akses tidak diizinkan.');
    }

    $formId = (string) $_GET['qr_code'];
    if (!preg_match('/^[a-f0-9]{12}$/', $formId)) {
        http_response_code(404);
        exit('QR Code tidak ditemukan.');
    }
    $form = null;
    foreach ($data['forms'] as $candidate) {
        if ($candidate['id'] === $formId) {
            $form = $candidate;
            break;
        }
    }
    if ($form === null) {
        http_response_code(404);
        exit('Form tidak ditemukan.');
    }
    $logoPath = null;
    $logoFilename = (string) ($form['logo'] ?? '');
    if ($logoFilename !== '') {
        $logoPath = findUploadedImagePath($logoFilename, $uploadDirectories);
        if ($logoPath === null) {
            http_response_code(404);
            exit('Logo form tidak ditemukan. Unggah ulang logo pada form sebelum membuat QR Code.');
        }
    }
    if (!function_exists('curl_init')) {
        http_response_code(503);
        exit('Fitur QR Code tidak tersedia karena ekstensi PHP cURL belum aktif.');
    }

    $formUrl = cccApplicationBaseUrl() . '/index.php?page=fill&form=' . rawurlencode($form['id']);
    $qrServiceUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&format=png&ecc=H&qzone=4&data=' . rawurlencode($formUrl);
    $curl = curl_init($qrServiceUrl);
    if ($curl === false) {
        http_response_code(503);
        exit('QR Code tidak dapat dibuat saat ini.');
    }
    $configured = curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $qrImage = $configured ? curl_exec($curl) : false;
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $mimeType = is_string($qrImage) ? (new finfo(FILEINFO_MIME_TYPE))->buffer($qrImage) : false;
    if (!is_string($qrImage) || $httpStatus !== 200 || $mimeType !== 'image/png') {
        error_log('CCC QR generation failed: HTTP ' . $httpStatus . '.');
        http_response_code(502);
        exit('QR Code gagal dibuat. Silakan coba lagi.');
    }

    if ($logoPath !== null) {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            http_response_code(503);
            exit('Penambahan logo QR memerlukan ekstensi PHP GD.');
        }

        $qrCanvas = imagecreatefromstring($qrImage);
        $logoBytes = file_get_contents($logoPath);
        $logoCanvas = is_string($logoBytes) ? imagecreatefromstring($logoBytes) : false;
        if ($qrCanvas === false || $logoCanvas === false) {
            if ($qrCanvas !== false) {
                imagedestroy($qrCanvas);
            }
            if ($logoCanvas !== false) {
                imagedestroy($logoCanvas);
            }
            http_response_code(422);
            exit('Logo form tidak dapat diproses untuk QR Code. Unggah ulang logo dalam format JPG, PNG, atau WEBP.');
        }

        $qrWidth = imagesx($qrCanvas);
        $qrHeight = imagesy($qrCanvas);
        $badgeSize = (int) round(min($qrWidth, $qrHeight) * 0.22);
        $padding = (int) round($badgeSize * 0.1);
        $logoLimit = $badgeSize - (2 * $padding);
        $scale = min($logoLimit / imagesx($logoCanvas), $logoLimit / imagesy($logoCanvas));
        $logoWidth = max(1, (int) round(imagesx($logoCanvas) * $scale));
        $logoHeight = max(1, (int) round(imagesy($logoCanvas) * $scale));
        $badgeX = (int) floor(($qrWidth - $badgeSize) / 2);
        $badgeY = (int) floor(($qrHeight - $badgeSize) / 2);
        $logoX = (int) floor(($qrWidth - $logoWidth) / 2);
        $logoY = (int) floor(($qrHeight - $logoHeight) / 2);
        imagealphablending($qrCanvas, true);
        $borderColor = imagecolorallocate($qrCanvas, 220, 229, 234);
        $backgroundColor = imagecolorallocate($qrCanvas, 255, 255, 255);
        imagefilledrectangle($qrCanvas, $badgeX - 3, $badgeY - 3, $badgeX + $badgeSize + 2, $badgeY + $badgeSize + 2, $borderColor);
        imagefilledrectangle($qrCanvas, $badgeX, $badgeY, $badgeX + $badgeSize - 1, $badgeY + $badgeSize - 1, $backgroundColor);
        imagecopyresampled(
            $qrCanvas,
            $logoCanvas,
            $logoX,
            $logoY,
            0,
            0,
            $logoWidth,
            $logoHeight,
            imagesx($logoCanvas),
            imagesy($logoCanvas)
        );
        ob_start();
        $pngCreated = imagepng($qrCanvas);
        $compositedQr = ob_get_clean();
        imagedestroy($qrCanvas);
        imagedestroy($logoCanvas);
        if (!$pngCreated || !is_string($compositedQr)) {
            http_response_code(500);
            exit('QR Code dengan logo gagal dibuat.');
        }
        $qrImage = $compositedQr;
    }

    $downloadQr = ($_GET['download'] ?? '') === '1';
    header('Content-Type: image/png');
    header('Content-Disposition: ' . ($downloadQr ? 'attachment' : 'inline') . '; filename="ccc-form-' . $formId . '.png"');
    header('Content-Length: ' . strlen($qrImage));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $qrImage;
    exit;
}

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'login') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $user = $data['users'][$username] ?? null;
            if (!$user || !password_verify($password, $user['password'])) {
                throw new RuntimeException('Nama pengguna atau kata sandi tidak sesuai.');
            }
            if (!($user['isActive'] ?? true)) {
                throw new RuntimeException('Akun ini sedang dinonaktifkan. Hubungi administrator.');
            }
            session_regenerate_id(true);
            $_SESSION['user'] = $username;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            setFlash('success', 'Selamat datang, ' . $user['name'] . '.');
            $pendingApprovalSubmissionId = $_SESSION['pending_approval_submission'] ?? '';
            unset($_SESSION['pending_approval_submission']);
            if (is_string($pendingApprovalSubmissionId) && preg_match('/^[a-f0-9]{16}$/', $pendingApprovalSubmissionId)) {
                redirectTo('page=approve&submission_id=' . rawurlencode($pendingApprovalSubmissionId));
            }
            redirectTo();
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            setFlash('success', 'Anda telah keluar dengan aman.');
            redirectTo();
        }

        $currentUser = $data['users'][$_SESSION['user'] ?? ''] ?? null;
        if ($currentUser && !($currentUser['isActive'] ?? true)) {
            $currentUser = null;
        }
        if (!$currentUser && $action !== 'submit_response') {
            throw new RuntimeException('Silakan masuk terlebih dahulu.');
        }

        if ($action === 'set_theme' && $currentUser) {
            $theme = (string) ($_POST['theme'] ?? '');
            if (!in_array($theme, ['light', 'dark'], true)) {
                throw new RuntimeException('Tema yang dipilih tidak valid.');
            }

            $data['users'][$currentUser['username']]['theme'] = $theme;
            saveData($dataFile, $data);
            setFlash('success', 'Tema ' . ($theme === 'dark' ? 'Dark' : 'Light') . ' berhasil diterapkan.');

            $returnPage = (string) ($_POST['return_page'] ?? 'dashboard');
            $allowedReturnPages = ['dashboard', 'public-forms', 'forms', 'edit-form', 'users', 'sop', 'account', 'change-password', 'my-submissions', 'fill'];
            if (!in_array($returnPage, $allowedReturnPages, true)) {
                $returnPage = 'dashboard';
            }
            $returnQuery = ['page' => $returnPage];
            $returnForm = (string) ($_POST['return_form'] ?? '');
            if ($returnPage === 'fill' && preg_match('/^[a-f0-9]{12}$/', $returnForm)) {
                $returnQuery['form'] = $returnForm;
            }
            $returnId = (string) ($_POST['return_id'] ?? '');
            if ($returnPage === 'edit-form' && preg_match('/^[a-f0-9]{12}$/', $returnId)) {
                $returnQuery['id'] = $returnId;
            }
            redirectTo(http_build_query($returnQuery));
        }

        if ($action === 'update_profile_photo' && $currentUser) {
            $oldProfileImage = (string) ($currentUser['profileImage'] ?? '');
            $newProfileImage = storeImage(getSingleUploadedFile('profile_image'), $uploadDir);
            $removeProfileImage = ($_POST['remove_profile_image'] ?? '') === '1';
            if ($newProfileImage === '' && (!$removeProfileImage || $oldProfileImage === '')) {
                throw new RuntimeException('Pilih foto profil JPG, PNG, atau WEBP maksimal 4 MB.');
            }

            $data['users'][$currentUser['username']]['profileImage'] = $newProfileImage !== ''
                ? $newProfileImage
                : '';
            try {
                saveData($dataFile, $data);
            } catch (Throwable $exception) {
                removeUploadedImage($newProfileImage, $uploadDirectories);
                error_log('CCC profile image update failed: ' . $exception->getMessage());
                throw new RuntimeException('Foto profil gagal disimpan.', 0, $exception);
            }

            $removedOldImage = $newProfileImage === '' || removeUploadedImage($oldProfileImage, $uploadDirectories);
            setFlash($removedOldImage ? 'success' : 'warning', $removedOldImage
                ? 'Foto profil berhasil diperbarui.'
                : 'Foto profil berhasil diperbarui, tetapi foto lama tidak dapat dibersihkan.');
            redirectTo('page=account');
        }

        if ($action === 'change_password' && $currentUser) {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
            if (!password_verify($currentPassword, $currentUser['password'])) {
                throw new RuntimeException('Kata sandi saat ini tidak sesuai.');
            }
            if (strlen($newPassword) < 8) {
                throw new RuntimeException('Kata sandi baru harus minimal 8 karakter.');
            }
            if (!hash_equals($newPassword, $confirmPassword)) {
                throw new RuntimeException('Konfirmasi kata sandi baru tidak sama.');
            }
            if (password_verify($newPassword, $currentUser['password'])) {
                throw new RuntimeException('Kata sandi baru harus berbeda dari kata sandi saat ini.');
            }

            $data['users'][$currentUser['username']]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            saveData($dataFile, $data);
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            setFlash('success', 'Kata sandi berhasil diganti.');
            redirectTo('page=account');
        }

        if ($action === 'create_sop' && canManageSop($currentUser)) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $titleLength = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
            $descriptionLength = function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description);
            if ($title === '' || $titleLength > 120 || $descriptionLength > 500) {
                throw new RuntimeException('Judul SOP wajib diisi (maksimal 120 karakter) dan deskripsi maksimal 500 karakter.');
            }

            $document = storeSopDocument(getSingleUploadedFile('sop_file'), $sopUploadDir);
            try {
                $thumbnailName = storeSopThumbnail(getSingleUploadedFile('sop_thumbnail'), $sopUploadDir);
            } catch (Throwable $exception) {
                removeSopAssets(['stored_name' => $document['storedName']], $sopUploadDir);
                throw $exception;
            }

            $sopId = bin2hex(random_bytes(16));
            $createdAt = date(DATE_ATOM);
            try {
                $statement = $pdo->prepare(
                    'INSERT INTO ccc_sop_files (id, title, description, original_name, stored_name, mime_type, file_size, thumbnail_name, created_at, updated_at)
                     VALUES (:id, :title, :description, :original_name, :stored_name, :mime_type, :file_size, :thumbnail_name, :created_at, :updated_at)'
                );
                $statement->execute([
                    'id' => $sopId,
                    'title' => $title,
                    'description' => $description,
                    'original_name' => $document['originalName'],
                    'stored_name' => $document['storedName'],
                    'mime_type' => $document['mimeType'],
                    'file_size' => $document['size'],
                    'thumbnail_name' => $thumbnailName,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            } catch (Throwable $exception) {
                removeSopAssets([
                    'stored_name' => $document['storedName'],
                    'thumbnail_name' => $thumbnailName,
                ], $sopUploadDir);
                error_log('CCC SOP creation failed: ' . $exception->getMessage());
                throw new RuntimeException('Arsip SOP gagal disimpan ke database.', 0, $exception);
            }

            setFlash('success', 'File SOP berhasil ditambahkan.');
            redirectTo('page=sop');
        }

        if ($action === 'update_sop' && canManageSop($currentUser)) {
            $sopId = (string) ($_POST['sop_id'] ?? '');
            $record = preg_match('/^[a-f0-9]{32}$/', $sopId) ? fetchSopFile($pdo, $sopId) : null;
            if ($record === null) {
                throw new RuntimeException('Arsip SOP yang akan diedit tidak ditemukan.');
            }

            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $titleLength = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
            $descriptionLength = function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description);
            if ($title === '' || $titleLength > 120 || $descriptionLength > 500) {
                throw new RuntimeException('Judul SOP wajib diisi (maksimal 120 karakter) dan deskripsi maksimal 500 karakter.');
            }

            $documentUpload = getSingleUploadedFile('sop_file');
            $newDocument = ($documentUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
                ? null
                : storeSopDocument($documentUpload, $sopUploadDir);
            try {
                $newThumbnail = storeSopThumbnail(getSingleUploadedFile('sop_thumbnail'), $sopUploadDir);
            } catch (Throwable $exception) {
                if ($newDocument !== null) {
                    removeSopAssets(['stored_name' => $newDocument['storedName']], $sopUploadDir);
                }
                throw $exception;
            }

            $removeThumbnail = ($_POST['remove_sop_thumbnail'] ?? '') === '1';
            $storedName = $newDocument['storedName'] ?? $record['stored_name'];
            $originalName = $newDocument['originalName'] ?? $record['original_name'];
            $mimeType = $newDocument['mimeType'] ?? $record['mime_type'];
            $fileSize = $newDocument['size'] ?? (int) $record['file_size'];
            $thumbnailName = $newThumbnail !== ''
                ? $newThumbnail
                : ($removeThumbnail ? '' : $record['thumbnail_name']);
            try {
                $statement = $pdo->prepare(
                    'UPDATE ccc_sop_files
                     SET title = :title, description = :description, original_name = :original_name, stored_name = :stored_name,
                         mime_type = :mime_type, file_size = :file_size, thumbnail_name = :thumbnail_name, updated_at = :updated_at
                     WHERE id = :id'
                );
                $statement->execute([
                    'title' => $title,
                    'description' => $description,
                    'original_name' => $originalName,
                    'stored_name' => $storedName,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize,
                    'thumbnail_name' => $thumbnailName,
                    'updated_at' => date(DATE_ATOM),
                    'id' => $sopId,
                ]);
            } catch (Throwable $exception) {
                removeSopAssets([
                    'stored_name' => $newDocument['storedName'] ?? '',
                    'thumbnail_name' => $newThumbnail,
                ], $sopUploadDir);
                error_log('CCC SOP update failed: ' . $exception->getMessage());
                throw new RuntimeException('Perubahan arsip SOP gagal disimpan ke database.', 0, $exception);
            }

            $removedOldAssets = removeSopAssets([
                'stored_name' => $newDocument !== null ? $record['stored_name'] : '',
                'thumbnail_name' => $newThumbnail !== '' || $removeThumbnail ? $record['thumbnail_name'] : '',
            ], $sopUploadDir);
            setFlash($removedOldAssets ? 'success' : 'warning', $removedOldAssets
                ? 'Arsip SOP berhasil diperbarui.'
                : 'Arsip SOP berhasil diperbarui, tetapi file lama tidak dapat dibersihkan. Hubungi administrator.');
            redirectTo('page=sop');
        }

        if ($action === 'delete_sop' && canManageSop($currentUser)) {
            $sopId = (string) ($_POST['sop_id'] ?? '');
            $record = preg_match('/^[a-f0-9]{32}$/', $sopId) ? fetchSopFile($pdo, $sopId) : null;
            if ($record === null) {
                throw new RuntimeException('Arsip SOP yang akan dihapus tidak ditemukan.');
            }
            $statement = $pdo->prepare('DELETE FROM ccc_sop_files WHERE id = :id');
            $statement->execute(['id' => $sopId]);
            $removedAssets = removeSopAssets($record, $sopUploadDir);
            setFlash($removedAssets ? 'success' : 'warning', $removedAssets
                ? 'Arsip SOP berhasil dihapus.'
                : 'Arsip SOP dihapus dari daftar, tetapi file fisiknya tidak dapat dibersihkan. Hubungi administrator.');
            redirectTo('page=sop');
        }

        if ($action === 'create_form' && $currentUser && $currentUser['role'] === 'Administrator') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('Judul dan minimal satu pertanyaan utama wajib diisi.');
            }
            if (strlen($title) > 120 || strlen($description) > 240) {
                throw new RuntimeException('Judul maksimal 120 karakter dan deskripsi maksimal 240 karakter.');
            }
            $questions = buildQuestionsFromPost($uploadDir);
            $verifierStartQuestion = validateVerifierStartQuestion(
                $questions,
                $_POST['verifier_start_question'] ?? 1
            );
            $logo = storeImage(getSingleUploadedFile('company_logo'), $uploadDir);
            $data['forms'][] = [
                'id' => bin2hex(random_bytes(6)),
                'title' => $title,
                'description' => $description,
                'logo' => $logo,
                'questions' => $questions,
                'verifierStartQuestion' => $verifierStartQuestion,
                'createdAt' => date(DATE_ATOM),
            ];
            saveData($dataFile, $data);
            setFlash('success', 'Form checklist berhasil dibuat.');
            redirectTo('page=forms');
        }

        if ($action === 'update_form' && $currentUser && $currentUser['role'] === 'Administrator') {
            $formId = (string) ($_POST['form_id'] ?? '');
            $formIndex = null;
            foreach ($data['forms'] as $index => $form) {
                if ($form['id'] === $formId) {
                    $formIndex = $index;
                    break;
                }
            }
            if ($formIndex === null) {
                throw new RuntimeException('Form yang akan diedit tidak ditemukan.');
            }
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($title === '' || strlen($title) > 120 || strlen($description) > 240) {
                throw new RuntimeException('Judul wajib diisi (maksimal 120 karakter) dan deskripsi maksimal 240 karakter.');
            }
            $newLogo = storeImage(getSingleUploadedFile('company_logo'), $uploadDir);
            $removeLogo = ($_POST['remove_company_logo'] ?? '') === '1';
            $data['forms'][$formIndex]['title'] = $title;
            $data['forms'][$formIndex]['description'] = $description;
            $data['forms'][$formIndex]['logo'] = $newLogo !== ''
                ? $newLogo
                : ($removeLogo ? '' : ($data['forms'][$formIndex]['logo'] ?? ''));
            $questions = buildQuestionsFromPost(
                $uploadDir,
                $data['forms'][$formIndex]['questions']
            );
            $data['forms'][$formIndex]['questions'] = $questions;
            $data['forms'][$formIndex]['verifierStartQuestion'] = validateVerifierStartQuestion(
                $questions,
                $_POST['verifier_start_question'] ?? 1
            );
            saveData($dataFile, $data);
            setFlash('success', 'Perubahan form berhasil disimpan. Riwayat checklist sebelumnya tetap tersimpan.');
            redirectTo('page=forms');
        }

        if ($action === 'delete_form' && $currentUser && $currentUser['role'] === 'Administrator') {
            $formId = (string) ($_POST['form_id'] ?? '');
            $formIndex = null;
            foreach ($data['forms'] as $index => $form) {
                if ($form['id'] === $formId) {
                    $formIndex = $index;
                    break;
                }
            }
            if ($formIndex === null) {
                throw new RuntimeException('Form yang akan dihapus tidak ditemukan.');
            }
            array_splice($data['forms'], $formIndex, 1);
            saveData($dataFile, $data);
            setFlash('success', 'Form berhasil dihapus. Riwayat checklist dan approval yang sudah masuk tetap tersimpan.');
            redirectTo('page=forms');
        }

        if ($action === 'create_user' && $currentUser && $currentUser['role'] === 'Administrator') {
            $username = strtolower(trim((string) ($_POST['new_username'] ?? '')));
            $name = trim((string) ($_POST['new_name'] ?? ''));
            $email = trim((string) ($_POST['new_email'] ?? ''));
            $role = (string) ($_POST['new_role'] ?? '');
            $password = (string) ($_POST['new_password'] ?? '');
            if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username) || isset($data['users'][$username])) {
                throw new RuntimeException('Username harus 3–30 karakter (huruf, angka, titik, garis bawah, atau tanda hubung) dan belum digunakan.');
            }
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['Supervisor', 'PJO', 'HSE', 'Respondent'], true) || strlen($password) < 8) {
                throw new RuntimeException('Nama, email valid, role, dan kata sandi minimal 8 karakter wajib diisi.');
            }
            $data['users'][$username] = [
                'username' => $username,
                'name' => $name,
                'role' => $role,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'isActive' => true,
                'profileImage' => '',
                'theme' => 'light',
            ];
            saveData($dataFile, $data);
            setFlash('success', 'Akun ' . $role . ' berhasil ditambahkan.');
            redirectTo('page=users');
        }

        if ($action === 'update_user' && $currentUser && $currentUser['role'] === 'Administrator') {
            $username = (string) ($_POST['username'] ?? '');
            $account = $data['users'][$username] ?? null;
            if ($account === null) {
                throw new RuntimeException('Akun yang akan diubah tidak ditemukan.');
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role = $account['role'] === 'Administrator'
                ? 'Administrator'
                : (string) ($_POST['role'] ?? '');
            $allowedRoles = $account['role'] === 'Administrator'
                ? ['Administrator']
                : ['Supervisor', 'PJO', 'HSE', 'Respondent'];
            if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 100
                || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || !in_array($role, $allowedRoles, true)
                || ($password !== '' && strlen($password) < 8)) {
                throw new RuntimeException('Nama, email, role, atau kata sandi baru tidak valid. Kata sandi harus minimal 8 karakter.');
            }

            $newProfileImage = storeImage(getSingleUploadedFile('profile_image'), $uploadDir);
            $oldProfileImage = (string) ($account['profileImage'] ?? '');
            $removeProfileImage = ($_POST['remove_profile_image'] ?? '') === '1';
            $account['name'] = $name;
            $account['email'] = $email;
            $account['role'] = $role;
            $account['profileImage'] = $newProfileImage !== ''
                ? $newProfileImage
                : ($removeProfileImage ? '' : $oldProfileImage);
            if ($password !== '') {
                $account['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $data['users'][$username] = $account;
            try {
                saveData($dataFile, $data);
            } catch (Throwable $exception) {
                removeUploadedImage($newProfileImage, $uploadDirectories);
                error_log('CCC administrator account update failed: ' . $exception->getMessage());
                throw new RuntimeException('Perubahan akun gagal disimpan.', 0, $exception);
            }

            $profileImageChanged = $newProfileImage !== '' || ($removeProfileImage && $oldProfileImage !== '');
            $removedOldImage = !$profileImageChanged || removeUploadedImage($oldProfileImage, $uploadDirectories);
            $message = 'Akun ' . $username . ' berhasil diperbarui'
                . ($password !== '' ? ' dan kata sandinya direset.' : '.');
            if (!$removedOldImage) {
                $message .= ' Foto profil lama tidak dapat dibersihkan.';
            }
            setFlash($removedOldImage ? 'success' : 'warning', $message);
            redirectTo('page=users');
        }

        if ($action === 'toggle_user_status' && $currentUser && $currentUser['role'] === 'Administrator') {
            $username = (string) ($_POST['username'] ?? '');
            $account = $data['users'][$username] ?? null;
            if ($account === null) {
                throw new RuntimeException('Akun yang akan diubah tidak ditemukan.');
            }
            if ($username === $currentUser['username']) {
                throw new RuntimeException('Akun administrator yang sedang digunakan tidak dapat dinonaktifkan.');
            }
            if ($account['role'] === 'Administrator' && ($account['isActive'] ?? true)) {
                $activeAdministratorCount = count(array_filter(
                    $data['users'],
                    static fn(array $item): bool => $item['role'] === 'Administrator' && ($item['isActive'] ?? true)
                ));
                if ($activeAdministratorCount <= 1) {
                    throw new RuntimeException('Administrator aktif terakhir tidak dapat dinonaktifkan.');
                }
            }

            $account['isActive'] = !($account['isActive'] ?? true);
            $data['users'][$username] = $account;
            saveData($dataFile, $data);
            setFlash('success', 'Akun ' . $username . ($account['isActive'] ? ' berhasil diaktifkan kembali.' : ' berhasil dinonaktifkan.'));
            redirectTo('page=users');
        }

        if ($action === 'delete_user' && $currentUser && $currentUser['role'] === 'Administrator') {
            $username = (string) ($_POST['username'] ?? '');
            $account = $data['users'][$username] ?? null;
            if ($account === null) {
                throw new RuntimeException('Akun yang akan dihapus tidak ditemukan.');
            }
            if ($username === $currentUser['username']) {
                throw new RuntimeException('Akun yang sedang digunakan tidak dapat dihapus.');
            }
            if ($account['role'] === 'Administrator') {
                $administratorCount = count(array_filter(
                    $data['users'],
                    static fn(array $item): bool => $item['role'] === 'Administrator'
                ));
                if ($administratorCount <= 1) {
                    throw new RuntimeException('Administrator terakhir tidak dapat dihapus.');
                }
                if (($account['isActive'] ?? true)) {
                    $activeAdministratorCount = count(array_filter(
                        $data['users'],
                        static fn(array $item): bool => $item['role'] === 'Administrator' && ($item['isActive'] ?? true)
                    ));
                    if ($activeAdministratorCount <= 1) {
                        throw new RuntimeException('Administrator aktif terakhir tidak dapat dihapus.');
                    }
                }
            }
            foreach ($data['submissions'] as $submission) {
                if ($submission['supervisor'] === $username && $submission['status'] === 'Menunggu approval') {
                    throw new RuntimeException('Akun tidak dapat dihapus karena masih memiliki checklist yang menunggu approval.');
                }
            }

            unset($data['users'][$username]);
            saveData($dataFile, $data);
            setFlash('success', 'Akun ' . $username . ' berhasil dihapus. Riwayat checklist tetap tersimpan.');
            redirectTo('page=users');
        }

        if ($action === 'submit_response') {
            $formId = (string) ($_POST['form_id'] ?? '');
            $form = null;
            foreach ($data['forms'] as $candidate) {
                if ($candidate['id'] === $formId) {
                    $form = $candidate;
                    break;
                }
            }
            if (!$form) {
                throw new RuntimeException('Form checklist tidak ditemukan.');
            }
            $respondentName = $currentUser && $currentUser['role'] === 'Respondent'
                ? $currentUser['name']
                : trim((string) ($_POST['respondent_name'] ?? ''));
            $email = trim((string) ($_POST['respondent_email'] ?? ''));
            if ($respondentName === '' || (function_exists('mb_strlen') ? mb_strlen($respondentName, 'UTF-8') : strlen($respondentName)) > 100) {
                throw new RuntimeException('Nama responden wajib diisi dan maksimal 100 karakter.');
            }
            $supervisorQuestionIndex = null;
            foreach ($form['questions'] as $index => $question) {
                if (($question['type'] ?? 'yes_no') !== 'supervisor') {
                    continue;
                }
                if ($supervisorQuestionIndex !== null) {
                    throw new RuntimeException('Form memiliki lebih dari satu pilihan supervisor. Hubungi administrator.');
                }
                $supervisorQuestionIndex = $index;
            }
            $supervisorUsername = $supervisorQuestionIndex !== null
                ? (string) ($_POST['answer'][$supervisorQuestionIndex] ?? '')
                : (string) ($_POST['supervisor'] ?? '');
            $supervisor = $data['users'][$supervisorUsername] ?? null;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$supervisor || !in_array($supervisor['role'], ['Supervisor', 'PJO', 'HSE'], true) || !($supervisor['isActive'] ?? true)) {
                throw new RuntimeException('Periksa form, email responden, dan Supervisor/PJO/HSE yang dipilih.');
            }
            $answers = [];
            foreach ($form['questions'] as $index => $question) {
                $type = $question['type'] ?? 'yes_no';
                $choice = trim((string) ($_POST['answer'][$index] ?? ''));
                if (in_array($type, ['yes_no', 'verification'], true) && !in_array($choice, ['Ya', 'Tidak'], true)) {
                    throw new RuntimeException('Jawab Ya atau Tidak untuk setiap pertanyaan utama.');
                }
                if ($type === 'select' && !in_array($choice, $question['options'] ?? [], true)) {
                    throw new RuntimeException('Pilih salah satu opsi yang tersedia untuk setiap pertanyaan pilihan.');
                }
                if ($type === 'text' && ($choice === '' || (function_exists('mb_strlen') ? mb_strlen($choice, 'UTF-8') : strlen($choice)) > 500)) {
                    throw new RuntimeException('Jawaban teks wajib diisi dan maksimal 500 karakter.');
                }
                if ($type === 'supervisor') {
                    if ($choice !== $supervisorUsername) {
                        throw new RuntimeException('Pilih Supervisor/PJO/HSE yang tersedia untuk menerima permintaan approval.');
                    }
                    $choice = $supervisor['name'] . ' (' . $supervisor['email'] . ')';
                }
                $followupChoiceAnswers = [];
                $legacyFollowupAnswer = '';
                $verificationAnswer = '';
                if ($type === 'verification' && $choice === 'Tidak') {
                    $verificationAnswer = trim((string) ($_POST['verification_answer'][$index] ?? ''));
                    if (!in_array($verificationAnswer, $question['options'] ?? [], true)) {
                        throw new RuntimeException('Pilih salah satu opsi verifikasi yang tersedia ketika jawaban Tidak.');
                    }
                }
                if ($type === 'yes_no' && $choice === 'Ya') {
                    if (!empty($question['followupChoices'])) {
                        $submittedFollowupChoices = $_POST['followup_choice_answer'][$index] ?? [];
                        if (!is_array($submittedFollowupChoices)) {
                            throw new RuntimeException('Jawaban pertanyaan lanjutan pilihan tidak valid.');
                        }
                        foreach ($question['followupChoices'] as $followupIndex => $followupChoice) {
                            $followupChoiceAnswer = trim((string) ($submittedFollowupChoices[$followupIndex] ?? ''));
                            if (!in_array($followupChoiceAnswer, $followupChoice['options'] ?? [], true)) {
                                throw new RuntimeException('Jawab setiap pertanyaan lanjutan pilihan yang muncul setelah memilih Ya.');
                            }
                            $followupChoiceAnswers[] = [
                                'question' => $followupChoice['text'],
                                'choice' => $followupChoiceAnswer,
                            ];
                        }
                    } elseif (trim((string) ($question['followup'] ?? '')) !== '') {
                        $legacyFollowupAnswer = trim((string) ($_POST['followup_answer'][$index] ?? ''));
                        if ($legacyFollowupAnswer === '') {
                            throw new RuntimeException('Pertanyaan lanjutan wajib dijawab ketika jawaban utama adalah Ya.');
                        }
                    } else {
                        throw new RuntimeException('Pertanyaan Ya/Tidak ini belum memiliki pertanyaan lanjutan pilihan. Hubungi administrator.');
                    }
                }
                $answers[] = [
                    'type' => $type,
                    'question' => $question['text'],
                    'verifierQuestion' => $question['verifierText'] ?? $question['text'],
                    'choice' => $choice,
                    'options' => in_array($type, ['select', 'verification'], true) ? ($question['options'] ?? []) : [],
                    'followup' => $type === 'yes_no' && $choice === 'Ya' && $followupChoiceAnswers === [] ? ($question['followup'] ?? '') : '',
                    'followupAnswer' => $legacyFollowupAnswer,
                    'followupChoiceAnswers' => $followupChoiceAnswers,
                    'followupChoices' => $type === 'yes_no' ? ($question['followupChoices'] ?? []) : [],
                    'verificationMessage' => $type === 'verification' ? ($question['verificationMessage'] ?? '') : '',
                    'verificationAnswer' => $verificationAnswer,
                ];
            }
            $submission = [
                'id' => bin2hex(random_bytes(8)),
                'formId' => $form['id'],
                'formTitle' => $form['title'],
                'verifierStartQuestion' => validateVerifierStartQuestion(
                    $form['questions'],
                    $form['verifierStartQuestion'] ?? 1
                ),
                'respondent' => $respondentName,
                'respondentEmail' => $email,
                'supervisor' => $supervisorUsername,
                'supervisorName' => $supervisor['name'],
                'supervisorEmail' => $supervisor['email'],
                'answers' => $answers,
                'verifierAnswers' => [],
                'submittedAt' => date(DATE_ATOM),
                'status' => 'Menunggu approval',
                'approvedBy' => '',
                'approvedAt' => '',
                'emailStatus' => 'Belum dikirim',
                'approvalEmailStatus' => '',
            ];
            $data['submissions'][] = $submission;
            $mailSent = false;
            $mailError = '';
            try {
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'send_approval_email.php';
                sendCccApprovalEmail($submission);
                $mailSent = true;
            } catch (Throwable $exception) {
                $mailError = $exception->getMessage();
                error_log('CCC approval email failed: ' . $mailError);
            }
            $emailStatus = $mailSent ? 'Terkirim' : 'Gagal — ' . $mailError;
            $data['submissions'][array_key_last($data['submissions'])]['emailStatus'] = function_exists('mb_substr')
                ? mb_substr($emailStatus, 0, 160, 'UTF-8')
                : substr($emailStatus, 0, 160);
            saveData($dataFile, $data);
            setFlash($mailSent ? 'success' : 'warning', $mailSent
                ? 'Form berhasil dikirim dan notifikasi email telah diteruskan ke supervisor.'
                : 'Form tersimpan, tetapi email gagal dikirim: ' . $mailError);
            redirectTo($currentUser && $currentUser['role'] === 'Respondent' ? 'page=my-submissions' : 'page=submitted');
        }

        if ($action === 'resend_email' && $currentUser && $currentUser['role'] === 'Administrator') {
            $submissionId = (string) ($_POST['submission_id'] ?? '');
            $submissionIndex = null;
            foreach ($data['submissions'] as $index => $submission) {
                if ($submission['id'] === $submissionId) {
                    $submissionIndex = $index;
                    break;
                }
            }
            if ($submissionIndex === null) {
                throw new RuntimeException('Data checklist untuk pengiriman ulang tidak ditemukan.');
            }
            if (str_starts_with($data['submissions'][$submissionIndex]['emailStatus'], 'Terkirim')) {
                throw new RuntimeException('Notifikasi untuk checklist ini sudah berhasil dikirim.');
            }

            try {
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'send_approval_email.php';
                sendCccApprovalEmail($data['submissions'][$submissionIndex]);
            } catch (Throwable $exception) {
                $emailStatus = 'Gagal — ' . $exception->getMessage();
                $data['submissions'][$submissionIndex]['emailStatus'] = function_exists('mb_substr')
                    ? mb_substr($emailStatus, 0, 160, 'UTF-8')
                    : substr($emailStatus, 0, 160);
                saveData($dataFile, $data);
                throw new RuntimeException('Pengiriman ulang email gagal: ' . $exception->getMessage(), 0, $exception);
            }

            $data['submissions'][$submissionIndex]['emailStatus'] = 'Terkirim';
            saveData($dataFile, $data);
            setFlash('success', 'Notifikasi email berhasil diteruskan ke supervisor.');
            redirectTo('page=my-submissions');
        }

        if ($action === 'approve' && $currentUser) {
            $submissionId = (string) ($_POST['submission_id'] ?? '');
            $submissionIndex = null;
            $sendApprovalEmail = false;
            foreach ($data['submissions'] as $index => &$submission) {
                if ($submission['id'] !== $submissionId) {
                    continue;
                }
                if (!canApproveSubmission($currentUser, $submission)) {
                    throw new RuntimeException('Checklist ini hanya dapat diverifikasi administrator, akun yang ditugaskan sebagai supervisor, atau PJO/HSE dengan email yang sama dengan tujuan supervisor.');
                }
                if ($submission['status'] !== 'Menunggu approval') {
                    throw new RuntimeException('Checklist ini sudah diproses sebelumnya.');
                }
                $sendApprovalEmail = in_array($currentUser['role'], ['Supervisor', 'PJO', 'HSE'], true)
                    || $submission['supervisor'] === $currentUser['username'];
                $submission['verifierAnswers'] = buildVerifierAnswers(
                    $submission['answers'],
                    $_POST,
                    (int) ($submission['verifierStartQuestion'] ?? 1)
                );
                $submission['status'] = 'Disetujui';
                $submission['approvedBy'] = $currentUser['name'];
                $submission['approvedAt'] = date(DATE_ATOM);
                $submission['approvalEmailStatus'] = $sendApprovalEmail ? 'Menunggu pengiriman' : '';
                $submissionIndex = $index;
                break;
            }
            unset($submission);
            if ($submissionIndex === null) {
                throw new RuntimeException('Data checklist tidak ditemukan.');
            }
            saveData($dataFile, $data);
            if ($sendApprovalEmail) {
                $emailError = '';
                try {
                    require_once __DIR__ . DIRECTORY_SEPARATOR . 'send_approval_email.php';
                    sendCccApprovedEmail($data['submissions'][$submissionIndex]);
                    $data['submissions'][$submissionIndex]['approvalEmailStatus'] = 'Terkirim';
                } catch (Throwable $exception) {
                    $emailStatus = 'Gagal — ' . $exception->getMessage();
                    $data['submissions'][$submissionIndex]['approvalEmailStatus'] = function_exists('mb_substr')
                        ? mb_substr($emailStatus, 0, 160, 'UTF-8')
                        : substr($emailStatus, 0, 160);
                    $emailError = $exception->getMessage();
                    error_log('CCC respondent approval email failed: ' . $exception->getMessage());
                }
                saveData($dataFile, $data);
                setFlash($emailError === '' ? 'success' : 'warning', $emailError === ''
                    ? 'Checklist berhasil diverifikasi dan pemberitahuan email telah dikirim ke responden.'
                    : 'Checklist berhasil diverifikasi, tetapi email pemberitahuan ke responden gagal dikirim: ' . $emailError);
            } else {
                setFlash('success', 'Checklist berhasil diverifikasi.');
            }
            redirectTo($currentUser['role'] === 'Respondent' ? 'page=my-submissions' : 'page=dashboard');
        }

        throw new RuntimeException('Aksi tidak tersedia atau Anda tidak memiliki izin.');
    } catch (Throwable $exception) {
        setFlash('error', $exception->getMessage());
        $failedPublicFormId = (string) ($_POST['form_id'] ?? '');
        $failedAccount = $data['users'][$_SESSION['user'] ?? ''] ?? null;
        if ($failedAccount && !($failedAccount['isActive'] ?? true)) {
            $failedAccount = null;
        }
        if ($action === 'submit_response' && (!$failedAccount || $failedAccount['role'] !== 'Respondent') && preg_match('/^[a-f0-9]{12}$/', $failedPublicFormId)) {
            redirectTo('page=fill&form=' . rawurlencode($failedPublicFormId));
        }
        if (in_array($action, ['create_sop', 'update_sop', 'delete_sop'], true)) {
            redirectTo('page=sop');
        }
        if ($action === 'update_profile_photo') {
            redirectTo('page=account');
        }
        if ($action === 'change_password') {
            redirectTo('page=change-password');
        }
        if ($action === 'update_user') {
            redirectTo('page=users');
        }
        redirectTo();
    }
}

$username = $_SESSION['user'] ?? '';
$user = $data['users'][$username] ?? null;
if ($user && !($user['isActive'] ?? true)) {
    $user = null;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$page = (string) ($_GET['page'] ?? ($user ? 'dashboard' : 'login'));
if ($page === 'approve') {
    $approvalSubmissionId = (string) ($_GET['submission_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}$/', $approvalSubmissionId)) {
        setFlash('error', 'Tautan checklist untuk approval tidak valid.');
        redirectTo($user ? 'page=my-submissions' : '');
    }
    if (!$user) {
        $_SESSION['pending_approval_submission'] = $approvalSubmissionId;
        $page = 'login';
        if (!$flash) {
            $flash = ['type' => 'warning', 'message' => 'Silakan login untuk meninjau checklist yang dipilih.'];
        }
    } else {
        $canViewApprovalSubmission = false;
        foreach ($data['submissions'] as $submission) {
            if ($submission['id'] === $approvalSubmissionId && canApproveSubmission($user, $submission)) {
                $canViewApprovalSubmission = true;
                break;
            }
        }
        if (!$canViewApprovalSubmission) {
            setFlash('error', 'Checklist tidak ditemukan atau Anda tidak memiliki akses untuk meninjaunya.');
            redirectTo('page=my-submissions');
        }
        if (is_array($flash) && isset($flash['type'], $flash['message'])) {
            setFlash((string) $flash['type'], (string) $flash['message']);
        }
        redirectTo(
            'page=my-submissions&submission_id=' . rawurlencode($approvalSubmissionId)
            . '#submission-' . rawurlencode($approvalSubmissionId)
        );
    }
}
if (!$user && $page === 'forms') {
    $page = 'public-forms';
}
$sopFiles = $page === 'sop' ? fetchSopFiles($pdo) : [];
$supervisors = array_filter(
    $data['users'],
    static fn(array $item): bool => in_array($item['role'], ['Supervisor', 'PJO', 'HSE'], true) && ($item['isActive'] ?? true)
);
$administratorCount = count(array_filter(
    $data['users'],
    static fn(array $item): bool => $item['role'] === 'Administrator'
));
$activeAdministratorCount = count(array_filter(
    $data['users'],
    static fn(array $item): bool => $item['role'] === 'Administrator' && ($item['isActive'] ?? true)
));
$pendingSupervisorUsernames = array_fill_keys(array_column(
    array_filter($data['submissions'], static fn(array $item): bool => $item['status'] === 'Menunggu approval'),
    'supervisor'
), true);
$visibleSubmissions = array_values(array_filter($data['submissions'], static function (array $submission) use ($user): bool {
    if (!$user) {
        return false;
    }
    if ($user['role'] === 'Administrator') {
        return true;
    }
    if ($user['role'] === 'Supervisor') {
        return $submission['supervisor'] === $user['username'];
    }
    if (in_array($user['role'], ['PJO', 'HSE'], true)) {
        return true;
    }
    return $submission['respondent'] === $user['name']
        || ($submission['supervisor'] === $user['username'] && $submission['status'] === 'Menunggu approval');
}));
usort($visibleSubmissions, static fn(array $a, array $b): int => strcmp($b['submittedAt'], $a['submittedAt']));
if (array_key_exists('export', $_GET)) {
    $exportFormat = $_GET['export'];
    if (!is_string($exportFormat) || !in_array($exportFormat, ['pdf', 'excel'], true)) {
        http_response_code(400);
        exit('Format ekspor tidak valid.');
    }
    if (!$user || ($user['role'] ?? '') !== 'Administrator') {
        http_response_code(403);
        exit('Ekspor riwayat checklist hanya tersedia untuk Administrator.');
    }

    $filenameDate = date('Ymd-His');
    header('Cache-Control: no-store, private');
    if ($exportFormat === 'pdf') {
        $pdf = createChecklistPdf($visibleSubmissions);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="ccc-riwayat-checklist-' . $filenameDate . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    $excel = createChecklistExcel($visibleSubmissions);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="ccc-riwayat-checklist-' . $filenameDate . '.xlsx"');
    header('Content-Length: ' . strlen($excel));
    echo $excel;
    exit;
}
$historyPageSizes = [10, 50, 75, 100, 150, 200];
$requestedPageSize = filter_var($_GET['per_page'] ?? 10, FILTER_VALIDATE_INT);
$historyPageSize = in_array($requestedPageSize, $historyPageSizes, true) ? $requestedPageSize : 10;
$requestedSubmissionId = (string) ($_GET['submission_id'] ?? '');
$requestedHistoryPage = $_GET['history_page'] ?? 1;
if (preg_match('/^[a-f0-9]{16}$/', $requestedSubmissionId)) {
    foreach ($visibleSubmissions as $index => $submission) {
        if ($submission['id'] === $requestedSubmissionId) {
            $requestedHistoryPage = (int) floor($index / $historyPageSize) + 1;
            break;
        }
    }
}
$historyTotal = count($visibleSubmissions);
$historyPageCount = max(1, (int) ceil($historyTotal / $historyPageSize));
$requestedHistoryPage = filter_var($requestedHistoryPage, FILTER_VALIDATE_INT);
$historyPage = is_int($requestedHistoryPage) && $requestedHistoryPage > 0
    ? min($requestedHistoryPage, $historyPageCount)
    : 1;
$historyOffset = ($historyPage - 1) * $historyPageSize;
$historyPageSubmissions = array_slice($visibleSubmissions, $historyOffset, $historyPageSize);
$historyFirstItem = $historyTotal > 0 ? $historyOffset + 1 : 0;
$historyLastItem = min($historyOffset + $historyPageSize, $historyTotal);
$selectedForm = null;
if ($page === 'fill') {
    foreach ($data['forms'] as $form) {
        if ($form['id'] === (string) ($_GET['form'] ?? '')) {
            $selectedForm = $form;
            break;
        }
    }
}
$editingForm = null;
if ($page === 'edit-form') {
    foreach ($data['forms'] as $form) {
        if ($form['id'] === (string) ($_GET['id'] ?? '')) {
            $editingForm = $form;
            break;
        }
    }
}
$formHasSupervisorQuestion = $selectedForm !== null && count(array_filter(
    $selectedForm['questions'],
    static fn(array $question): bool => ($question['type'] ?? 'yes_no') === 'supervisor'
)) > 0;
$publicFormsRequest = !$user && $page === 'public-forms';
$publicFormRequest = !$user && $page === 'fill';
$publicSopRequest = !$user && $page === 'sop';
$publicSubmissionComplete = $page === 'submitted';
$csrf = h($_SESSION['csrf']);
$theme = $user && ($user['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="<?= $theme === 'dark' ? '#0f1724' : '#111827' ?>">
    <title><?= h(APP_NAME) ?><?= $user ? ' — Dashboard' : '' ?></title>
    <style>
        :root{--ink:#17212f;--muted:#6b7787;--line:#e6eaf0;--paper:#fff;--canvas:#f4f6fa;--nav:#111827;--accent:#0f766e;--accent-dark:#0b5b55;--mint:#e7f5f2;--orange:#f59e0b;--red:#b42318;--shadow:0 12px 36px rgba(20,35,55,.07)}
        *{box-sizing:border-box}body{margin:0;background:var(--canvas);color:var(--ink);font:15px/1.55 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}button,input,textarea,select{font:inherit}button{cursor:pointer}a{color:inherit;text-decoration:none}.shell{min-height:100vh}.sidebar{position:fixed;inset:0 auto 0 0;width:254px;background:var(--nav);color:#e8edf5;padding:26px 18px;display:flex;flex-direction:column;z-index:3}.brand{display:flex;align-items:center;gap:12px;padding:4px 10px 28px}.brand-mark{width:38px;height:38px;background:#d7f0eb;color:#0b615a;border-radius:12px;display:grid;place-items:center;font-weight:900}.brand-name{font-size:13px;font-weight:800;line-height:1.25;letter-spacing:.055em}.brand-name span{display:block;color:#9daabd;font-size:10px;font-weight:600;letter-spacing:.14em;margin-top:4px}.nav-label{font-size:10px;text-transform:uppercase;letter-spacing:.14em;color:#8390a3;padding:16px 10px 9px}.nav-link{display:flex;align-items:center;gap:11px;padding:11px 12px;border-radius:9px;color:#c5cfdd;font-size:13px;margin:2px 0}.nav-link:hover,.nav-link.active{background:#263244;color:#fff}.nav-icon{width:18px;text-align:center;color:#a8b7c8}.profile{margin-top:auto;border-top:1px solid #293447;padding:17px 7px 0;display:flex;align-items:center;gap:10px}.avatar{width:36px;height:36px;border-radius:50%;background:#c9e8e2;color:#155a53;display:grid;place-items:center;font-weight:800}.profile-text{min-width:0;flex:1}.profile-text strong{display:block;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.profile-text small{color:#a7b3c3;font-size:11px}.main{margin-left:254px;min-height:100vh}.topbar{height:68px;background:rgba(255,255,255,.92);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 38px;position:sticky;top:0;z-index:2;backdrop-filter:blur(12px)}.crumb{color:var(--muted);font-size:12px}.crumb b{color:var(--ink);font-weight:650}.top-actions{display:flex;align-items:center;gap:16px}.content{max-width:1280px;padding:34px 38px 60px;margin:auto}.page-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:26px}.eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.13em;color:var(--accent);font-weight:750}.page-heading h1{font-size:28px;line-height:1.2;letter-spacing:-.03em;margin:7px 0 6px}.page-heading p{margin:0;color:var(--muted);font-size:13px}.btn{border:0;border-radius:9px;padding:10px 15px;font-size:12px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:.15s}.btn-primary{background:var(--accent);color:white}.btn-primary:hover{background:var(--accent-dark);transform:translateY(-1px)}.btn-light{border:1px solid var(--line);background:#fff;color:var(--ink)}.btn-danger{color:#a32b24;background:#fff0ef}.btn-small{padding:7px 10px;font-size:11px}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:23px}.stat-card,.panel,.form-card,.submission-card{background:var(--paper);border:1px solid var(--line);border-radius:13px;box-shadow:0 3px 13px rgba(18,34,52,.025)}.stat-card{padding:19px 20px}.stat-top{display:flex;justify-content:space-between;align-items:center;color:var(--muted);font-size:11px;font-weight:600}.stat-icon{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;background:var(--mint);color:var(--accent);font-size:15px}.stat-number{font-size:27px;font-weight:750;letter-spacing:-.04em;margin-top:13px}.stat-foot{color:var(--muted);font-size:10px;margin-top:2px}.panel{padding:21px;margin-bottom:19px}.panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:17px}.panel-title{margin:0;font-size:15px;font-weight:750}.panel-subtitle{color:var(--muted);font-size:11px;margin-top:3px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:680px}th{text-align:left;color:#8792a1;text-transform:uppercase;letter-spacing:.09em;font-size:9px;font-weight:700;border-bottom:1px solid var(--line);padding:10px 11px}td{padding:13px 11px;border-bottom:1px solid #eff1f5;font-size:12px;vertical-align:middle}tr:last-child td{border-bottom:0}.person{display:flex;align-items:center;gap:9px}.person-dot{width:29px;height:29px;border-radius:50%;background:#e5efee;color:#266c64;display:grid;place-items:center;font-size:10px;font-weight:800}.muted{color:var(--muted)}.badge{display:inline-flex;align-items:center;border-radius:20px;padding:4px 8px;font-size:10px;font-weight:700;background:#f0f2f5;color:#596575;white-space:nowrap}.badge.pending{background:#fff3dc;color:#9b6400}.badge.approved{background:#e5f5ee;color:#17734c}.badge.failed{background:#fff0ef;color:#a32b24}.empty{padding:30px 15px;text-align:center;color:var(--muted);font-size:12px}.empty strong{display:block;color:var(--ink);font-size:14px;margin-bottom:5px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}.field{display:flex;flex-direction:column;gap:6px;margin-bottom:15px}.field label,.field-label{font-size:11px;font-weight:700;color:#354254}.field input,.field textarea,.field select{width:100%;padding:10px 12px;border:1px solid #dfe4eb;border-radius:8px;background:#fff;color:var(--ink);outline:none;font-size:12px}.field input:focus,.field textarea:focus,.field select:focus{border-color:#65a79f;box-shadow:0 0 0 3px rgba(15,118,110,.1)}.field textarea{resize:vertical;min-height:75px}.help{font-size:10px;color:var(--muted);margin-top:-2px}.question-builder{border:1px solid var(--line);padding:16px;border-radius:10px;margin:12px 0;background:#fbfcfe}.question-builder-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}.question-builder-head strong{font-size:12px}.question-card{padding:17px;border:1px solid var(--line);border-radius:11px;margin:13px 0;background:#fff}.question-title{display:flex;align-items:flex-start;gap:12px}.question-number{flex:none;width:29px;height:29px;background:var(--mint);color:var(--accent);border-radius:8px;display:grid;place-items:center;font-size:11px;font-weight:800}.question-title h3{margin:1px 0 5px;font-size:13px}.question-title p{margin:0;color:var(--muted);font-size:11px}.question-image{max-width:100%;max-height:180px;border-radius:8px;margin:13px 0 0 41px;object-fit:cover}.answer-choice{display:flex;gap:10px;margin:16px 0 0 41px}.choice{position:relative;display:flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:8px;padding:9px 15px;font-size:12px;font-weight:700;cursor:pointer}.choice:has(input:checked){border-color:var(--accent);background:#f0faf8;color:#0b645c}.choice input{accent-color:var(--accent)}.followup-box{margin:13px 0 0 41px;padding:13px;background:#fbf6e9;border:1px solid #f4e3b2;border-radius:9px}.followup-box label{font-size:11px;font-weight:700}.followup-box textarea{width:100%;padding:9px;border:1px solid #e8dfc7;border-radius:7px;margin-top:7px;font-size:12px;min-height:70px}.alert{padding:12px 15px;border-radius:9px;margin-bottom:17px;font-size:12px}.alert.success{background:#e9f7ef;color:#17633e}.alert.error{background:#fff0ef;color:#9c2c24}.alert.warning{background:#fff4df;color:#885c06}.login-page{min-height:100vh;display:grid;grid-template-columns:1fr 1fr;background:#fff}.login-aside{background:linear-gradient(145deg,#102b36,#0d625c);color:white;padding:8vw;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden}.login-aside:after{content:"";position:absolute;width:480px;height:480px;border:1px solid rgba(255,255,255,.12);border-radius:50%;right:-200px;bottom:-170px;box-shadow:0 0 0 45px rgba(255,255,255,.035),0 0 0 90px rgba(255,255,255,.025)}.login-aside .brand{padding:0}.login-aside .brand-name span{color:#b4d7d2}.login-copy{position:relative;z-index:1;max-width:480px}.login-copy .eyebrow{color:#a5ddd4}.login-copy h1{font-size:clamp(34px,4vw,55px);line-height:1.08;letter-spacing:-.05em;margin:14px 0}.login-copy p{color:#c3d6d8;max-width:390px;font-size:14px}.login-footer{font-size:10px;color:#afc5c6}.login-form-side{display:grid;place-items:center;padding:35px}.login-box{width:min(390px,100%)}.login-box h2{font-size:26px;letter-spacing:-.03em;margin:0 0 5px}.login-box>p{color:var(--muted);font-size:12px;margin:0 0 25px}.demo-box{background:#f5f7fa;border-radius:9px;padding:12px 14px;margin-top:17px;color:#5a6675;font-size:10px;line-height:1.8}.demo-box strong{color:var(--ink)}.fill-container{max-width:780px;margin:0 auto}.form-intro{background:linear-gradient(135deg,#102d37,#0e7268);color:#fff;border-radius:14px;padding:26px;margin-bottom:17px}.form-intro .eyebrow{color:#abe4d9}.form-intro h1{font-size:24px;margin:8px 0}.form-intro p{color:#d1e4e0;font-size:12px;margin:0}.form-card{padding:20px;margin-bottom:14px}.form-card h2{font-size:15px;margin:0 0 6px}.form-card>p{color:var(--muted);font-size:11px;margin:0 0 14px}.submission-detail{margin-top:13px;border-top:1px solid var(--line);padding-top:12px}.submission-detail p{font-size:11px;margin:6px 0}.qr-area{display:flex;align-items:center;gap:16px}.qr-area img{width:112px;height:112px;border:1px solid var(--line);border-radius:9px;padding:5px;background:white}.form-link{font-size:10px;color:var(--muted);word-break:break-all}.user-row{display:flex;align-items:center;gap:11px}.mobile-menu{display:none}
        @media(max-width:1050px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.content{padding:28px 25px 48px}.topbar{padding:0 25px}}
        @media(max-width:760px){.sidebar{width:70px;padding:20px 9px}.brand{padding:0 7px 25px}.brand-name,.nav-link span:not(.nav-icon),.nav-label,.profile-text{display:none}.nav-link{justify-content:center;padding:12px 7px}.nav-icon{font-size:16px}.profile{justify-content:center;padding:15px 0 0}.main{margin-left:70px}.topbar{height:58px;padding:0 16px}.content{padding:24px 15px 40px}.page-heading h1{font-size:23px}.page-heading{align-items:flex-start;flex-direction:column}.cards{gap:10px}.stat-card{padding:14px}.stat-number{font-size:23px}.panel{padding:15px}.login-page{grid-template-columns:1fr}.login-aside{min-height:245px;padding:24px}.login-copy{margin:37px 0 12px}.login-copy h1{font-size:32px}.login-aside:after{width:280px;height:280px;right:-170px;bottom:-165px}.login-footer{display:none}.login-form-side{padding:34px 23px}.form-grid{grid-template-columns:1fr}.question-image{margin-left:0}.answer-choice,.followup-box{margin-left:0}.qr-area{align-items:flex-start;flex-direction:column}}
        @media(max-width:430px){.cards{grid-template-columns:1fr 1fr}.stat-top{font-size:10px}.stat-icon{width:28px;height:28px}.btn{padding:9px 11px}.top-actions{gap:7px}}
        .preview-dialog{width:min(760px,calc(100% - 24px));max-height:90vh;overflow:auto;border:1px solid var(--line);border-radius:16px;padding:22px;background:var(--canvas);color:var(--ink);box-shadow:var(--shadow)}.preview-dialog::backdrop{background:rgba(17,24,39,.58);backdrop-filter:blur(3px)}.preview-dialog-head{display:flex;justify-content:space-between;align-items:flex-start;gap:15px;margin-bottom:8px}.preview-dialog-head h2{margin:4px 0 0;font-size:21px}.preview-response{margin-top:18px;padding:17px;background:#fff;border:1px solid var(--line);border-radius:12px}.preview-response .question-card{margin:12px 0}.preview-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:16px}.preview-dialog .question-image{margin-left:41px}.preview-response .question-image{max-height:160px}.company-logo{display:block;width:auto;max-width:min(100%,240px);max-height:100px;object-fit:contain;object-position:left center;margin:0 0 16px}.logo-editor-preview{margin:8px 0;max-height:80px}.logo-remove{display:flex;align-items:center;gap:7px;font-size:11px;color:var(--muted)}.logo-remove input{width:auto}.preview-company-logo{max-width:180px;max-height:70px;margin:12px 0}@media(max-width:600px){.preview-dialog{padding:16px}.preview-dialog .question-image{margin-left:0}}
        .sidebar{transition:transform .2s ease}.shell.sidebar-hidden .sidebar{transform:translateX(-100%)}.shell.sidebar-hidden .main{margin-left:0}.sidebar-brand-row{display:flex;align-items:center;gap:5px}.sidebar-brand-row .brand{flex:1;min-width:0}.sidebar-toggle-side{color:#d7e0eb;background:#263244;border:1px solid #37465b;padding:6px 9px}.sidebar-toggle-side:hover{background:#37465b}.topbar{justify-content:flex-start;gap:16px}.topbar-left{display:flex;align-items:center;gap:12px;min-width:0}.sidebar-toggle{flex:none;padding:8px 11px}.crumb{white-space:nowrap}.top-actions{margin-left:auto}.profile{align-items:stretch;flex-direction:column;gap:12px}.profile-user{display:flex;align-items:center;gap:10px;min-width:0}.logout-form{width:100%}.logout-button{width:100%;background:#263244;color:#fff;justify-content:flex-start;padding:9px 11px}.logout-button:hover{background:#37465b}
        .verification-dialog{width:min(500px,calc(100% - 28px));border:1px solid var(--line);border-radius:14px;padding:22px;background:#fff;color:var(--ink);box-shadow:var(--shadow)}.verification-dialog::backdrop{background:rgba(17,24,39,.58);backdrop-filter:blur(3px)}.verification-dialog-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:16px}.verification-dialog-head>div{flex:1}.verification-dialog-head h2{font-size:17px;margin:0 0 5px}.verification-dialog-head p{font-size:12px;color:var(--muted);margin:0}.verification-icon{width:30px;height:30px;flex:none;border-radius:50%;display:grid;place-items:center;background:#fff3dc;color:#9b6400;font-weight:900}.verification-dialog .field{margin-bottom:13px}.verification-confirm:disabled{opacity:.5;cursor:not-allowed}
        .verifier-dialog{width:min(900px,calc(100% - 28px));max-height:90vh;overflow:auto;border:1px solid var(--line);border-radius:16px;padding:24px;background:var(--canvas);color:var(--ink);box-shadow:var(--shadow)}
        .verifier-dialog::backdrop{background:rgba(17,24,39,.62);backdrop-filter:blur(3px)}
        .verifier-dialog-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:16px}
        .verifier-dialog-head h2{font-size:22px;line-height:1.25;margin:5px 0}
        .verifier-dialog-head p{font-size:12px;color:var(--muted);margin:0}
        .verifier-required-note{display:flex;align-items:flex-start;gap:11px;padding:13px 15px;border:1px solid #f1dfb5;border-radius:10px;background:#fff8e9;color:#805600}
        .verifier-required-note>span{display:grid;place-items:center;flex:none;width:22px;height:22px;border-radius:50%;background:#ffedc2;font-weight:800}
        .verifier-required-note p{font-size:12px;margin:0}
        .verifier-required-note strong{color:#684700}
        .verifier-modal-form{margin-top:16px;border-top:1px solid var(--line);padding-top:8px}
        .verifier-modal-form>.btn{margin-top:10px}
        .verifier-dialog .question-builder{background:#fff}
        @media(max-width:600px){.verifier-dialog{width:calc(100% - 20px);max-height:calc(100% - 20px);padding:16px}.verifier-dialog-head h2{font-size:19px}}
        .password-control{position:relative}.password-control input{padding-right:48px}.password-visibility{position:absolute;right:7px;top:50%;transform:translateY(-50%);display:grid;place-items:center;width:34px;height:34px;border:0;background:transparent;color:var(--muted);padding:7px;border-radius:6px}.password-visibility:hover{background:var(--mint);color:var(--accent)}.password-visibility:focus-visible{outline:2px solid var(--accent);outline-offset:2px}.password-visibility svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.password-visibility [hidden]{display:none}
        .public-shell .main{margin-left:0}.public-shell .topbar{padding:0 24px}.public-shell .brand{padding:0}
        @media(max-width:900px){.shell:not(.sidebar-hidden)::before{content:"";position:fixed;inset:0;background:rgba(17,24,39,.42);z-index:2}.sidebar{width:254px;padding:20px 18px;transform:translateX(-100%)}.shell:not(.sidebar-hidden) .sidebar{transform:translateX(0)}.shell.sidebar-hidden .sidebar{transform:translateX(-100%)}.main,.shell.sidebar-hidden .main{margin-left:0}.topbar{position:sticky;z-index:5;padding:0 20px}.topbar-toggle,.shell:not(.sidebar-hidden) .topbar-toggle{visibility:visible}.content{width:100%;padding:26px 20px 44px}.table-wrap{max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}.panel-head{flex-wrap:wrap;align-items:flex-start}.panel-head>.btn{flex:none}.login-page{grid-template-columns:1fr}.login-aside{min-height:245px;padding:24px}.login-copy{margin:37px 0 12px}.login-copy h1{font-size:32px}.login-footer{display:none}.login-form-side{padding:34px 23px}}
        @media(max-width:600px){body{font-size:14px}.topbar{min-height:58px;height:auto;padding:9px 13px;gap:8px}.topbar-left{gap:8px}.topbar-toggle{padding:8px 10px}.crumb{overflow:hidden;text-overflow:ellipsis}.top-actions{gap:7px}.content{padding:22px 13px 36px}.page-heading{gap:13px;margin-bottom:20px}.page-heading h1{font-size:22px}.page-heading>.btn{max-width:100%;white-space:normal}.cards{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.stat-card{padding:12px}.stat-top{gap:6px}.stat-number{font-size:22px}.panel{padding:14px;border-radius:11px}.form-grid{grid-template-columns:minmax(0,1fr);gap:0}.field input,.field textarea,.field select{min-height:44px;font-size:16px}.field textarea{min-height:88px}.btn{min-height:40px}.btn-small{min-height:38px}.question-card{padding:13px}.question-title{gap:9px}.answer-choice{flex-wrap:wrap;gap:8px}.choice{min-height:42px;padding:9px 12px}.question-image,.preview-dialog .question-image{max-width:100%;margin-left:0}.panel-head{gap:10px}.panel-head>div{min-width:0}.panel-head>.btn{white-space:nowrap}.qr-area{width:100%;align-items:flex-start;flex-direction:column}.qr-area img{max-width:min(100%,180px);height:auto}.form-link{max-width:100%;overflow-wrap:anywhere}.preview-dialog,.verification-dialog{width:calc(100% - 20px);max-height:calc(100% - 20px);padding:16px}.preview-dialog-head{gap:10px}.preview-dialog-head h2{font-size:18px}.login-page{min-height:100vh;min-height:100dvh}.login-form-side{padding:28px 20px}.login-aside{min-height:220px}.password-visibility{width:40px;height:40px}.password-control input{padding-right:52px}}
        @media(max-width:380px){.cards{grid-template-columns:minmax(0,1fr)}.stat-card{padding:14px}.top-actions .muted{display:none}.content{padding-right:11px;padding-left:11px}.topbar{padding-right:10px;padding-left:10px}.page-heading h1{font-size:20px}.panel{padding:12px}}
        @media(max-width:760px){.sidebar{width:254px;padding:20px 18px;transform:translateX(0)}.brand{padding:4px 10px 28px}.brand-name,.nav-link span:not(.nav-icon),.nav-label,.profile-text{display:initial}.brand-name{display:block}.nav-link{justify-content:flex-start;padding:11px 12px}.nav-icon{font-size:initial}.profile{justify-content:flex-start;padding:17px 7px 0}.main{margin-left:0}.shell.sidebar-hidden .main{margin-left:0}.shell:not(.sidebar-hidden) .topbar-toggle{visibility:hidden}.shell.sidebar-hidden .topbar-toggle{visibility:visible}.topbar{padding:0 15px;z-index:5}.content{padding:24px 15px 40px}}
        .shell.public-shell::before{content:none}
        .login-aside{isolation:isolate}
        body::before{content:"";position:fixed;inset:0;z-index:0;pointer-events:none;background-image:url("assets/watermark.svg");background-repeat:no-repeat;background-position:center;background-size:min(72vw,520px);opacity:.04}
        body[data-authenticated="true"]::before{background-position:calc(50% + 127px) center}
        @media(max-width:900px){body[data-authenticated="true"]::before{background-position:center}}
        #app-shell,.login-page{position:relative;z-index:1}
        .login-page{background:transparent}
        .login-form-side{background:rgba(255,255,255,.92)}
        .safety-scene{position:relative;width:min(460px,100%);height:260px;margin:5px auto 0;display:grid;place-items:center;perspective:900px;isolation:isolate}
        .safety-scene::before{content:"";position:absolute;inset:8% 12%;border-radius:50%;background:radial-gradient(ellipse,rgba(95,234,205,.2),rgba(95,234,205,0) 68%);filter:blur(8px)}
        .safety-scene::after{content:"";position:absolute;left:13%;right:13%;bottom:18px;height:1px;background:linear-gradient(90deg,transparent,rgba(157,243,224,.45),transparent)}
        .safety-grid{position:absolute;left:15%;right:15%;bottom:18px;height:88px;opacity:.22;background-image:linear-gradient(rgba(196,255,242,.28) 1px,transparent 1px),linear-gradient(90deg,rgba(196,255,242,.28) 1px,transparent 1px);background-size:22px 22px;transform:perspective(220px) rotateX(62deg);mask-image:linear-gradient(to bottom,transparent,#000 40%,transparent)}
        .safety-orbit{position:absolute;border:1px solid rgba(181,255,239,.23);border-radius:50%;transform-style:preserve-3d;pointer-events:none}
        .safety-orbit::after{content:"";position:absolute;top:50%;left:0;width:9px;height:9px;border-radius:50%;background:#ffe17a;box-shadow:0 0 16px rgba(255,225,122,.8);transform:translate(-50%,-50%)}
        .safety-orbit-outer{width:340px;height:150px;transform:rotateX(68deg) rotateZ(-18deg);animation:safety-orbit-spin 16s linear infinite}
        .safety-orbit-inner{width:245px;height:112px;border-color:rgba(181,255,239,.14);transform:rotateX(70deg) rotateZ(52deg);animation:safety-orbit-spin-reverse 20s linear infinite}
        .safety-shield{position:relative;width:124px;height:146px;display:grid;place-items:center;transform-style:preserve-3d;animation:safety-shield-float 5s ease-in-out infinite}
        .safety-shield::before{content:"";position:absolute;inset:3px;clip-path:polygon(50% 0,94% 16%,89% 61%,76% 82%,50% 100%,24% 82%,11% 61%,6% 16%);background:linear-gradient(140deg,#71f1d7,#0a897e 72%);transform:translate3d(5px,8px,-13px);filter:drop-shadow(0 18px 16px rgba(0,0,0,.24))}
        .safety-shield svg{position:relative;z-index:1;width:100%;height:100%;overflow:visible;transform:translateZ(12px);filter:drop-shadow(0 12px 12px rgba(0,0,0,.2))}
        .safety-token{position:absolute;z-index:2;width:58px;height:58px;display:grid;place-items:center;border:1px solid rgba(226,255,247,.35);border-radius:18px;background:linear-gradient(145deg,rgba(255,255,255,.2),rgba(255,255,255,.06));box-shadow:0 16px 30px rgba(0,0,0,.2),inset 0 1px rgba(255,255,255,.2);backdrop-filter:blur(10px);transform-style:preserve-3d}
        .safety-token svg{width:31px;height:31px;fill:none;stroke:#eafff8;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
        .safety-token-helmet{left:12%;top:47px;animation:safety-token-left 5.5s ease-in-out infinite}
        .safety-token-warning{right:12%;top:56px;width:52px;height:52px;border-color:rgba(255,225,122,.55);animation:safety-token-right 4.7s ease-in-out infinite}
        .safety-token-warning svg{stroke:#ffe17a}
        .safety-label{position:absolute;z-index:2;right:12%;bottom:33px;display:flex;align-items:center;gap:7px;padding:8px 11px;border:1px solid rgba(176,255,236,.24);border-radius:999px;background:rgba(6,46,53,.62);color:#d8fff4;font-size:9px;font-weight:800;letter-spacing:.13em;box-shadow:0 9px 22px rgba(0,0,0,.16);animation:safety-label-float 4s ease-in-out infinite}
        .safety-label span{width:7px;height:7px;border-radius:50%;background:#8ff0c6;box-shadow:0 0 10px rgba(143,240,198,.85)}
        @keyframes safety-shield-float{0%,100%{transform:translateY(5px) rotateY(-12deg) rotateX(4deg)}50%{transform:translateY(-9px) rotateY(12deg) rotateX(-3deg)}}
        @keyframes safety-orbit-spin{to{transform:rotateX(68deg) rotateZ(342deg)}}
        @keyframes safety-orbit-spin-reverse{to{transform:rotateX(70deg) rotateZ(-308deg)}}
        @keyframes safety-token-left{0%,100%{transform:translate3d(-3px,3px,28px) rotateY(14deg) rotateZ(-8deg)}50%{transform:translate3d(4px,-10px,38px) rotateY(-8deg) rotateZ(3deg)}}
        @keyframes safety-token-right{0%,100%{transform:translate3d(2px,-2px,34px) rotateY(-13deg) rotateZ(7deg)}50%{transform:translate3d(-4px,9px,24px) rotateY(9deg) rotateZ(-4deg)}}
        @keyframes safety-label-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
        @media(max-width:760px){.login-aside{min-height:0;gap:12px}.safety-scene{height:178px;margin:0 auto}.safety-shield{width:92px;height:108px}.safety-orbit-outer{width:245px;height:112px}.safety-orbit-inner{width:180px;height:84px}.safety-token{width:48px;height:48px;border-radius:15px}.safety-token svg{width:27px;height:27px}.safety-token-helmet{left:13%;top:23px}.safety-token-warning{right:13%;top:30px;width:44px;height:44px}.safety-label{right:13%;bottom:14px;font-size:8px}.login-copy{margin:0 0 8px}}
        @media(prefers-reduced-motion:reduce){.safety-scene,.safety-scene::before,.safety-scene::after,.safety-scene *{animation:none!important;scroll-behavior:auto!important}.safety-shield{transform:rotateY(-8deg) rotateX(3deg)}.safety-token-helmet{transform:translateZ(28px) rotateY(10deg)}.safety-token-warning{transform:translateZ(28px) rotateY(-10deg)}}
        body[data-theme="dark"]{color-scheme:dark;--ink:#e7edf5;--muted:#a3afbf;--line:#334155;--paper:#182333;--canvas:#0d1522;--nav:#080e19;--accent:#48b8a9;--accent-dark:#2e968a;--mint:#193a38;--shadow:0 12px 36px rgba(0,0,0,.28)}
        body[data-theme="dark"]::before{opacity:.075;filter:brightness(1.8)}
        body[data-theme="dark"] .topbar{background:rgba(13,21,34,.94)}
        body[data-theme="dark"] .btn-light{background:#1b2737;color:var(--ink);border-color:var(--line)}
        body[data-theme="dark"] .btn-danger{background:#3a2225;color:#ffb4ad}
        body[data-theme="dark"] td{border-bottom-color:#263447}
        body[data-theme="dark"] th{color:#a3afbf}
        body[data-theme="dark"] .field label,body[data-theme="dark"] .field-label{color:#cbd5e1}
        body[data-theme="dark"] .field input,body[data-theme="dark"] .field textarea,body[data-theme="dark"] .field select,body[data-theme="dark"] .followup-box textarea{background:#101a28;color:var(--ink);border-color:#3a495e}
        body[data-theme="dark"] .question-builder,body[data-theme="dark"] .question-card,body[data-theme="dark"] .preview-response,body[data-theme="dark"] .verification-dialog{background:#182333;color:var(--ink);border-color:var(--line)}
        body[data-theme="dark"] .question-builder{background:#141f2e}
        body[data-theme="dark"] .choice:has(input:checked){background:#173936;color:#b8f1e7}
        body[data-theme="dark"] .followup-box{background:#30291b;border-color:#62522c}
        body[data-theme="dark"] .followup-box label{color:#f3dfaa}
        body[data-theme="dark"] .demo-box{background:#202c3b;color:#c0cad8}
        body[data-theme="dark"] .panel[style*="background:#fbfcfe"],body[data-theme="dark"] details>div[style*="background:#fbfcfe"]{background:var(--canvas)!important}
        body[data-theme="dark"] #history-page-size{background:#101a28!important}
        body[data-theme="dark"] .badge{background:#263447;color:#d4deea}
        body[data-theme="dark"] .badge.pending{background:#473719;color:#f4d18a}
        body[data-theme="dark"] .badge.approved{background:#183c30;color:#9be2bd}
        body[data-theme="dark"] .badge.failed{background:#482729;color:#ffb4ad}
        body[data-theme="dark"] .alert.success{background:#183c30;color:#a8e7c5}
        body[data-theme="dark"] .alert.error{background:#482729;color:#ffc0ba}
        body[data-theme="dark"] .alert.warning{background:#473719;color:#f4d18a}
        .theme-switcher{display:inline-flex;align-items:center;gap:2px;padding:3px;border:1px solid var(--line);border-radius:9px;background:var(--canvas)}
        .theme-switcher form{margin:0}
        .theme-option{border:0;border-radius:6px;padding:5px 8px;background:transparent;color:var(--muted);font-size:10px;font-weight:750;white-space:nowrap}
        .theme-option[aria-pressed="true"]{background:var(--paper);color:var(--ink);box-shadow:0 1px 4px rgba(20,35,55,.15)}
        .theme-option:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
        @media(max-width:600px){.top-actions{gap:6px;flex-wrap:wrap;justify-content:flex-end}.theme-option{padding:5px 6px;font-size:9px}}
    </style>
</head>
<body data-theme="<?= h($theme) ?>"<?= $user ? ' data-authenticated="true"' : '' ?>>
<?php if (!$user && !$publicFormsRequest && !$publicFormRequest && !$publicSopRequest && !$publicSubmissionComplete): ?>
    <main class="login-page">
        <section class="login-aside">
            <div class="brand"><div class="brand-mark">C</div><div class="brand-name">CRITICAL CONTROL<span>CHECKLIST SYSTEM</span></div></div>
            <div class="safety-scene" aria-hidden="true">
                <div class="safety-grid"></div>
                <div class="safety-orbit safety-orbit-outer"></div>
                <div class="safety-orbit safety-orbit-inner"></div>
                <div class="safety-token safety-token-helmet">
                    <svg viewBox="0 0 48 48"><path d="M8 28h32M12 28v-5a12 12 0 0 1 24 0v5M24 11v17M6 32h36v5H6z"></path></svg>
                </div>
                <div class="safety-token safety-token-warning">
                    <svg viewBox="0 0 48 48"><path d="M24 7 44 41H4L24 7Z"></path><path d="M24 18v10m0 7h.01"></path></svg>
                </div>
                <div class="safety-shield">
                    <svg viewBox="0 0 120 140" role="presentation">
                        <defs><linearGradient id="safety-shield-fill" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f4fffc"></stop><stop offset="1" stop-color="#a8f3dc"></stop></linearGradient></defs>
                        <path d="M60 6 108 24v35c0 34-18 58-48 75C30 117 12 93 12 59V24L60 6Z" fill="url(#safety-shield-fill)" stroke="#d7fff2" stroke-width="2"></path>
                        <path d="m38 69 15 15 31-34" fill="none" stroke="#087c70" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                </div>
                <div class="safety-label"><span></span>SAFETY FIRST</div>
            </div>
            <div class="login-copy"><div class="eyebrow">Operational safety </div><h1>Kontrol kritis, dimulai dari sini.</h1><p>Safety Starts with Me!</p></div>
            <div class="login-footer">© <?= date('Y') ?> PT Jasti Pravita</div>
        </section>
        <section class="login-form-side">
            <form class="login-box" method="post">
                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="login">
                <div class="eyebrow">Akses sistem</div><h2>Selamat datang kembali</h2><p>Masuk dengan akun yang telah diberikan oleh administrator.</p>
                <?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
                <div class="field"><label for="username">Nama pengguna</label><input id="username" name="username" required autocomplete="username" autofocus placeholder="Masukkan nama pengguna"></div>
                <div class="field"><label for="password">Kata sandi</label><div class="password-control"><input id="password" name="password" required type="password" autocomplete="current-password" placeholder="Masukkan kata sandi"><button class="password-visibility" type="button" aria-controls="password" aria-label="Tampilkan kata sandi" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg><svg viewBox="0 0 24 24" aria-hidden="true" hidden><path d="m3 3 18 18"></path><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path><path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15.7 15.7 0 0 1-3.1 3.9"></path><path d="M6.6 6.6C3.7 8.5 2 12 2 12s3.6 7 10 7a10.7 10.7 0 0 0 4.1-.8"></path></svg></button></div></div>
                <button class="btn btn-primary" style="width:100%;margin-top:5px" type="submit">Masuk ke aplikasi <span aria-hidden="true">→</span></button>
            </form>
        </section>
    </main>
    <script>
        (() => {
            const passwordInput = document.getElementById('password');
            const toggleButton = document.querySelector('.password-visibility');
            if (!passwordInput || !toggleButton) {
                return;
            }

            toggleButton.addEventListener('click', () => {
                const isVisible = passwordInput.type === 'text';
                passwordInput.type = isVisible ? 'password' : 'text';
                toggleButton.querySelectorAll('svg').forEach((icon, index) => {
                    icon.hidden = isVisible ? index === 1 : index === 0;
                });
                toggleButton.setAttribute('aria-label', isVisible ? 'Tampilkan kata sandi' : 'Sembunyikan kata sandi');
                toggleButton.setAttribute('aria-pressed', String(!isVisible));
                passwordInput.focus();
            });
        })();
    </script>
<?php else:
    $role = $user['role'] ?? 'Public';
    $pageTitles = ['dashboard' => 'Dashboard', 'public-forms' => 'Form checklist', 'forms' => 'Form checklist', 'edit-form' => 'Edit form', 'users' => 'Pengguna', 'sop' => 'Arsip SOP', 'account' => 'Detail Akun', 'change-password' => 'Ganti password', 'my-submissions' => 'Riwayat pengisian', 'fill' => 'Isi checklist'];
    $pageTitle = $pageTitles[$page] ?? 'Dashboard';
    $navItems = [];
    if ($role !== 'Respondent') {
        $navItems[] = ['dashboard', '◫', 'Dashboard'];
    }
    if ($role === 'Administrator') {
        $navItems[] = ['forms', '▤', 'Form checklist'];
        $navItems[] = ['users', '♙', 'Pengguna'];
    }
    if ($role === 'Respondent') {
        $navItems[] = ['dashboard', '▤', 'Isi checklist'];
    }
    $navItems[] = ['sop', '▣', 'Arsip SOP'];
    $navItems[] = ['account', '♙', 'Detail Akun'];
    $navItems[] = ['my-submissions', '◷', $role === 'Respondent' ? 'Riwayat saya' : 'Riwayat checklist'];
    $pendingCount = count(array_filter($visibleSubmissions, static fn(array $submission): bool => $submission['status'] === 'Menunggu approval'));
?>
    <div class="shell<?= $user ? '' : ' public-shell' ?>" id="app-shell">
        <?php if ($user): ?>
        <aside class="sidebar" id="app-sidebar">
            <div class="sidebar-brand-row"><a class="brand" href="index.php"><div class="brand-mark">C</div><div class="brand-name">CRITICAL CONTROL<span>CHECKLIST SYSTEM</span></div></a><button type="button" class="sidebar-toggle sidebar-toggle-side" aria-label="Sembunyikan sidebar" title="Sembunyikan sidebar" aria-controls="app-sidebar" aria-expanded="true">‹</button></div>
            <div class="nav-label">Workspace</div>
            <?php foreach ($navItems as [$navPage, $icon, $label]): ?>
                <a class="nav-link <?= ($page === $navPage || ($page === 'fill' && $navPage === 'dashboard')) ? 'active' : '' ?>" href="index.php<?= $navPage === 'dashboard' && $role === 'Respondent' ? '' : '?page=' . h($navPage) ?>"><span class="nav-icon"><?= h($icon) ?></span><span><?= h($label) ?></span></a>
            <?php endforeach; ?>
            <div class="profile"><div class="profile-user"><div class="avatar"><?php if (($user['profileImage'] ?? '') !== ''): ?><img src="<?= h(mediaUrl($user['profileImage'])) ?>" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:50%"><?php else: ?><?= h(strtoupper(substr($user['name'], 0, 1))) ?><?php endif; ?></div><div class="profile-text"><strong><?= h($user['name']) ?></strong><small><?= h($role) ?></small></div></div>
                <form method="post" class="logout-form"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="logout"><button title="Log out" class="btn btn-small logout-button" type="submit"><span aria-hidden="true">↗</span> Log out</button></form>
            </div>
        </aside>
        <?php endif; ?>
        <main class="main">
            <header class="topbar"><?php if ($user): ?><div class="topbar-left"><button type="button" class="btn btn-light sidebar-toggle topbar-toggle" aria-label="Tampilkan sidebar" title="Tampilkan / sembunyikan sidebar" aria-controls="app-sidebar" aria-expanded="true">☰</button><div class="crumb">CCC <span style="padding:0 7px;color:#aeb8c4">/</span> <b><?= h($pageTitle) ?></b></div></div><div class="top-actions"><div class="theme-switcher" role="group" aria-label="Pilih tema tampilan"><?php foreach (['light' => '☀ Light', 'dark' => '☾ Dark'] as $themeValue => $themeLabel): ?><form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="set_theme"><input type="hidden" name="theme" value="<?= h($themeValue) ?>"><input type="hidden" name="return_page" value="<?= h($page) ?>"><input type="hidden" name="return_form" value="<?= h((string) ($_GET['form'] ?? '')) ?>"><input type="hidden" name="return_id" value="<?= h((string) ($_GET['id'] ?? '')) ?>"><button class="theme-option" type="submit" aria-pressed="<?= $theme === $themeValue ? 'true' : 'false' ?>"><?= h($themeLabel) ?></button></form><?php endforeach; ?></div><span class="badge"><?= h($role) ?></span><span class="muted" style="font-size:11px"><?= date('d M Y') ?></span></div><?php else: ?><a class="brand" href="index.php"><div class="brand-mark">C</div><div class="brand-name">CRITICAL CONTROL<span>CHECKLIST SYSTEM</span></div></a><div style="display:flex;align-items:center;gap:12px;margin-left:auto"><a href="index.php?page=sop" style="font-size:12px;font-weight:700">Arsip SOP</a><a class="btn btn-light btn-small" href="index.php?page=login">Masuk</a></div><?php endif; ?></header>
            <div class="content">
                <?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

                <?php if ($page === 'submitted' && !$user): ?>
                    <section class="panel" style="max-width:680px;margin:50px auto;text-align:center;padding:36px 24px">
                        <div class="stat-icon" style="width:48px;height:48px;margin:0 auto 15px;font-size:22px">✓</div>
                        <div class="eyebrow">Checklist terkirim</div>
                        <h1 style="font-size:24px;margin:8px 0">Terima kasih</h1>
                        <p class="muted" style="margin:0">Hasil checklist Anda sudah direkam dan diteruskan ke supervisor untuk proses approval.</p>
                    </section>
                <?php elseif ($page === 'account'): ?>
                    <div class="page-heading"><div><div class="eyebrow">Profil pengguna</div><h1>Detail Akun</h1><p>Lihat informasi akun, unggah foto profil, atau perbarui password.</p></div></div>
                    <section class="panel" style="max-width:760px">
                        <div style="display:flex;align-items:center;gap:18px;margin-bottom:22px">
                            <div style="width:92px;height:92px;flex:none;border-radius:50%;overflow:hidden;background:var(--mint);color:var(--accent);display:grid;place-items:center;font-size:34px;font-weight:800">
                                <?php if (($user['profileImage'] ?? '') !== ''): ?><img src="<?= h(mediaUrl($user['profileImage'])) ?>" alt="Foto profil <?= h($user['name']) ?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?><?= h(strtoupper(substr($user['name'], 0, 1))) ?><?php endif; ?>
                            </div>
                            <div><h2 class="panel-title" style="font-size:20px"><?= h($user['name']) ?></h2><span class="badge" style="margin-top:8px"><?= h($role) ?></span></div>
                        </div>
                        <dl style="display:grid;grid-template-columns:130px minmax(0,1fr);gap:10px 14px;margin:0 0 24px;font-size:12px">
                            <dt class="muted">Username</dt><dd style="margin:0;overflow-wrap:anywhere"><?= h($user['username']) ?></dd>
                            <dt class="muted">Email</dt><dd style="margin:0;overflow-wrap:anywhere"><?= h($user['email']) ?></dd>
                            <dt class="muted">Role</dt><dd style="margin:0"><?= h($role) ?></dd>
                        </dl>
                        <form method="post" enctype="multipart/form-data" style="border-top:1px solid var(--line);padding-top:18px">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="update_profile_photo">
                            <div class="field"><label for="profile-image">Foto profil</label><input id="profile-image" name="profile_image" type="file" accept="image/jpeg,image/png,image/webp" <?= ($user['profileImage'] ?? '') === '' ? 'required' : '' ?>><span class="help">JPG, PNG, atau WEBP · Maksimal 4 MB.</span></div>
                            <?php if (($user['profileImage'] ?? '') !== ''): ?><label class="logo-remove"><input type="checkbox" name="remove_profile_image" value="1"> Hapus foto profil saat ini</label><?php endif; ?>
                            <button class="btn btn-primary btn-small" type="submit"><?= ($user['profileImage'] ?? '') !== '' ? 'Simpan foto profil' : 'Unggah foto profil' ?></button>
                        </form>
                        <div style="border-top:1px solid var(--line);padding-top:18px;margin-top:18px">
                            <a class="btn btn-light" href="index.php?page=change-password">Ganti password</a>
                            <?php if ($role === 'Administrator'): ?><p class="help" style="margin-top:12px">Untuk mereset password pengguna yang lupa, buka menu Pengguna lalu edit akun terkait.</p><?php endif; ?>
                        </div>
                    </section>
                <?php elseif ($page === 'change-password'): ?>
                    <div class="page-heading"><div><div class="eyebrow">Keamanan akun</div><h1>Ganti password</h1><p>Gunakan password saat ini untuk mengatur password baru akun Anda.</p></div></div>
                    <section class="panel" style="max-width:620px">
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="change_password">
                            <div class="field"><label for="current-password">Password saat ini</label><input id="current-password" name="current_password" type="password" autocomplete="current-password" required></div>
                            <div class="field"><label for="new-password">Password baru</label><input id="new-password" name="new_password" type="password" autocomplete="new-password" minlength="8" required><span class="help">Minimal 8 karakter.</span></div>
                            <div class="field"><label for="confirm-password">Konfirmasi password baru</label><input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required></div>
                            <button class="btn btn-primary" type="submit">Simpan password baru</button>
                            <a class="btn btn-light" href="index.php?page=account">Kembali ke Detail Akun</a>
                        </form>
                    </section>
                <?php elseif ($page === 'sop'): ?>
                    <div class="page-heading">
                        <div><div class="eyebrow">Arsip dokumen publik</div><h1>Arsip SOP &amp; Permit</h1><p>File dapat diunduh oleh siapa saja melalui tautan file.</p></div>
                    </div>
                    <?php if (canManageSop($user)): ?>
                        <section class="panel">
                            <div class="panel-head"><div><h2 class="panel-title">Tambah arsip</h2><div class="panel-subtitle">PDF, Word, atau Excel maksimal 4 MB. Thumbnail JPG, PNG, atau WEBP maksimal 2 MB.</div></div></div>
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="create_sop">
                                <div class="form-grid">
                                    <div class="field"><label for="sop-title">Nama SOP / Permit</label><input id="sop-title" name="title" maxlength="120" required></div>
                                    <div class="field"><label for="sop-file">File arsip</label><input id="sop-file" name="sop_file" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx" required><span class="help">PDF, DOC, DOCX, XLS, XLSX · Maksimal 4 MB.</span></div>
                                </div>
                                <div class="field"><label for="sop-description">Deskripsi (opsional)</label><textarea id="sop-description" name="description" maxlength="500"></textarea></div>
                                <div class="field"><label for="sop-thumbnail">Gambar thumbnail (opsional)</label><input id="sop-thumbnail" name="sop_thumbnail" type="file" accept="image/jpeg,image/png,image/webp"><span class="help">JPG, PNG, WEBP · Maksimal 2 MB.</span></div>
                                <button class="btn btn-primary" type="submit">＋ Simpan arsip</button>
                            </form>
                        </section>
                    <?php endif; ?>
                    <?php if (!$sopFiles): ?>
                        <div class="empty"><strong>Belum ada arsip SOP</strong><?php if (canManageSop($user)): ?>Tambahkan file pertama menggunakan formulir di atas.<?php else: ?>File SOP dan permit yang dibagikan akan tampil di sini.<?php endif; ?></div>
                    <?php else: ?>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px">
                            <?php foreach ($sopFiles as $sopFile):
                                $sopFileUrl = cccApplicationBaseUrl() . '/index.php?sop_file=' . rawurlencode($sopFile['id']);
                            ?>
                                <article class="panel" style="margin:0;display:flex;flex-direction:column">
                                    <?php if ($sopFile['thumbnail_name'] !== ''): ?>
                                        <img src="index.php?sop_thumbnail=<?= h($sopFile['id']) ?>" alt="Thumbnail <?= h($sopFile['title']) ?>" loading="lazy" style="width:100%;height:180px;object-fit:cover;border-radius:9px;margin-bottom:15px">
                                    <?php else: ?>
                                        <div aria-hidden="true" style="height:120px;border-radius:9px;background:var(--mint);color:var(--accent);display:grid;place-items:center;font-size:42px;margin-bottom:15px">▤</div>
                                    <?php endif; ?>
                                    <h2 class="panel-title"><?= h($sopFile['title']) ?></h2>
                                    <?php if ($sopFile['description'] !== ''): ?><p class="panel-subtitle" style="white-space:pre-wrap"><?= h($sopFile['description']) ?></p><?php endif; ?>
                                    <p class="muted" style="font-size:10px;margin:10px 0">File: <?= h($sopFile['original_name']) ?> · <?= number_format((int) $sopFile['file_size'] / 1024, 1, ',', '.') ?> KB</p>
                                    <a class="btn btn-primary" style="align-self:flex-start" href="index.php?sop_file=<?= h($sopFile['id']) ?>">Unduh file</a>
                                    <div class="field-label" style="margin-top:14px">Link unduhan publik</div>
                                    <a class="form-link" href="<?= h($sopFileUrl) ?>"><?= h($sopFileUrl) ?></a>
                                    <?php if (canManageSop($user)): ?>
                                        <details style="margin-top:15px;border-top:1px solid var(--line);padding-top:12px">
                                            <summary style="cursor:pointer;font-size:11px;font-weight:700">Edit arsip</summary>
                                            <form method="post" enctype="multipart/form-data" style="margin-top:13px">
                                                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="update_sop"><input type="hidden" name="sop_id" value="<?= h($sopFile['id']) ?>">
                                                <div class="field"><label>Nama SOP / Permit</label><input name="title" maxlength="120" required value="<?= h($sopFile['title']) ?>"></div>
                                                <div class="field"><label>Deskripsi</label><textarea name="description" maxlength="500"><?= h($sopFile['description']) ?></textarea></div>
                                                <div class="field"><label>Ganti file (opsional)</label><input name="sop_file" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx"><span class="help">File saat ini: <?= h($sopFile['original_name']) ?>. File pengganti maksimal 4 MB.</span></div>
                                                <div class="field"><label>Ganti thumbnail (opsional)</label><input name="sop_thumbnail" type="file" accept="image/jpeg,image/png,image/webp"><span class="help">JPG, PNG, WEBP · Maksimal 2 MB.</span></div>
                                                <?php if ($sopFile['thumbnail_name'] !== ''): ?><label class="logo-remove"><input type="checkbox" name="remove_sop_thumbnail" value="1"> Hapus thumbnail saat ini</label><?php endif; ?>
                                                <button class="btn btn-primary btn-small" type="submit">Simpan perubahan</button>
                                            </form>
                                            <form method="post" style="margin-top:10px" onsubmit="return confirm('Hapus arsip SOP ini secara permanen?')">
                                                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_sop"><input type="hidden" name="sop_id" value="<?= h($sopFile['id']) ?>">
                                                <button class="btn btn-danger btn-small" type="submit">Hapus arsip</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php elseif ($publicFormsRequest): ?>
                    <div class="fill-container">
                        <div class="page-heading"><div><div class="eyebrow">Pengisian terbuka</div><h1>Form checklist tersedia</h1><p>Pilih form yang ingin Anda isi. Login tidak diperlukan.</p></div></div>
                        <?php if (!$data['forms']): ?><div class="empty"><strong>Belum ada form tersedia</strong>Silakan kembali lagi nanti.</div><?php endif; ?>
                        <?php foreach ($data['forms'] as $form): ?>
                            <article class="panel">
                                <?php if (($form['logo'] ?? '') !== ''): ?><img class="company-logo" src="<?= h(mediaUrl($form['logo'])) ?>" alt="Logo perusahaan <?= h($form['title']) ?>"><?php endif; ?>
                                <h2 class="panel-title"><?= h($form['title']) ?></h2>
                                <?php if (($form['description'] ?? '') !== ''): ?><p class="panel-subtitle"><?= h($form['description']) ?></p><?php endif; ?>
                                <span class="badge" style="margin:10px 0 14px"><?= count($form['questions']) ?> pertanyaan</span>
                                <div><a class="btn btn-primary" href="index.php?page=fill&amp;form=<?= h($form['id']) ?>">Isi form →</a></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($page === 'fill' && $selectedForm): ?>
                    <div class="fill-container">
                        <?php if ($user): ?><div style="margin-bottom:16px"><a class="muted" href="index.php" style="font-size:11px">← Kembali ke daftar form</a></div><?php endif; ?>
                        <div class="form-intro">
                            <?php if (($selectedForm['logo'] ?? '') !== ''): ?><img class="company-logo" src="<?= h(mediaUrl($selectedForm['logo'])) ?>" alt="Logo perusahaan"><?php endif; ?>
                            <div class="eyebrow">Critical control checklist</div><h1><?= h($selectedForm['title']) ?></h1><p><?= h($selectedForm['description']) ?></p>
                        </div>
                        <form method="post" class="panel" style="padding:19px" onsubmit="return validateChecklist(this)">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="submit_response"><input type="hidden" name="form_id" value="<?= h($selectedForm['id']) ?>">
                            <div class="form-grid">
                                <?php if (!$user || $role !== 'Respondent'): ?><div class="field"><label for="respondent_name">Nama lengkap <span style="color:#b42318">*</span></label><input type="text" id="respondent_name" name="respondent_name" required autocomplete="name" maxlength="100" placeholder="Masukkan nama lengkap"></div><?php endif; ?>
                                <div class="field"><label for="respondent_email">Email responden <span style="color:#b42318">*</span></label><input type="email" id="respondent_email" name="respondent_email" required autocomplete="email" maxlength="254" placeholder="nama@perusahaan.com"><span class="help">Wajib diisi. Email ini akan disimpan bersama hasil checklist dan ditampilkan di dashboard.</span></div>
                                <?php if (!$formHasSupervisorQuestion): ?><div class="field"><label for="supervisor">Kirim hasil kepada Supervisor/PJO/HSE <span style="color:#b42318">*</span></label><select id="supervisor" name="supervisor" required><option value="">Pilih Supervisor/PJO/HSE</option><?php foreach ($supervisors as $supervisor): ?><option value="<?= h($supervisor['username']) ?>"><?= h($supervisor['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                            </div>
                            <?php foreach ($selectedForm['questions'] as $index => $question): ?>
                                <?php $questionType = $question['type'] ?? 'yes_no'; ?>
                                <section class="question-card" data-question data-type="<?= h($questionType) ?>">
                                    <div class="question-title"><span class="question-number"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><div><h3><?= h($question['text']) ?></h3><p><?= $questionType === 'select' ? 'Pilih satu opsi jawaban.' : ($questionType === 'text' ? 'Isi jawaban teks.' : ($questionType === 'supervisor' ? 'Pilih Supervisor/PJO/HSE yang akan menerima hasil checklist untuk approval.' : ($questionType === 'verification' ? 'Pilih Ya atau Tidak. Jawaban Tidak memerlukan konfirmasi.' : 'Pilih Ya atau Tidak untuk melanjutkan.'))) ?></p></div></div>
                                    <?php if ($question['image'] !== ''): ?><img class="question-image" src="<?= h(mediaUrl($question['image'])) ?>" alt="Gambar pendukung pertanyaan <?= $index + 1 ?>"><?php endif; ?>
                                    <?php if ($questionType === 'select'): ?>
                                        <div class="field" style="margin:15px 0 0 41px"><label for="answer-<?= $index ?>">Pilih satu jawaban</label><select class="answer-select" id="answer-<?= $index ?>" name="answer[<?= $index ?>]" required><option value="">Pilih jawaban</option><?php foreach (($question['options'] ?? []) as $option): ?><option value="<?= h($option) ?>"><?= h($option) ?></option><?php endforeach; ?></select></div>
                                    <?php elseif ($questionType === 'text'): ?>
                                        <div class="field" style="margin:15px 0 0 41px"><label for="answer-<?= $index ?>">Jawaban teks <span style="color:#b42318">*</span></label><input class="answer-text" id="answer-<?= $index ?>" name="answer[<?= $index ?>]" type="text" maxlength="500" required placeholder="Contoh: tuliskan nama lengkap Anda"></div>
                                    <?php elseif ($questionType === 'supervisor'): ?>
                                        <div class="field" style="margin:15px 0 0 41px"><label for="answer-<?= $index ?>">Supervisor/PJO/HSE tujuan approval <span style="color:#b42318">*</span></label><select class="answer-select" id="answer-<?= $index ?>" name="answer[<?= $index ?>]" required><option value="">Pilih Supervisor/PJO/HSE</option><?php foreach ($supervisors as $supervisor): ?><option value="<?= h($supervisor['username']) ?>"><?= h($supervisor['name']) ?></option><?php endforeach; ?></select></div>
                                    <?php elseif ($questionType === 'verification'): ?>
                                        <div class="answer-choice">
                                            <label class="choice"><input type="radio" name="answer[<?= $index ?>]" value="Ya" required> Ya</label>
                                            <label class="choice"><input type="radio" name="answer[<?= $index ?>]" value="Tidak" required> Tidak</label>
                                        </div>
                                        <dialog class="verification-dialog" aria-labelledby="verification-title-<?= $index ?>">
                                            <div class="verification-dialog-head"><span class="verification-icon" aria-hidden="true">!</span><div><h2 id="verification-title-<?= $index ?>">Peringatan verifikasi</h2><p><?= h($question['verificationMessage'] ?? '') ?></p></div><button class="btn btn-light btn-small" type="button" data-close-verification aria-label="Tutup peringatan">Tutup</button></div>
                                            <div class="field"><label for="verification-answer-<?= $index ?>">Pilih alasan / tindak lanjut <span style="color:#b42318">*</span></label><select class="verification-answer" id="verification-answer-<?= $index ?>" name="verification_answer[<?= $index ?>]" disabled><option value="">Pilih salah satu opsi</option><?php foreach (($question['options'] ?? []) as $option): ?><option value="<?= h($option) ?>"><?= h($option) ?></option><?php endforeach; ?></select></div>
                                            <button class="btn btn-primary verification-confirm" type="button" disabled>Konfirmasi pilihan</button>
                                        </dialog>
                                    <?php else: ?>
                                        <div class="answer-choice">
                                            <label class="choice"><input type="radio" name="answer[<?= $index ?>]" value="Ya" required> Ya</label>
                                            <label class="choice"><input type="radio" name="answer[<?= $index ?>]" value="Tidak" required> Tidak</label>
                                        </div>
                                        <div class="followup-box" hidden>
                                            <?php if (empty($question['followupChoices']) && ($question['followup'] ?? '') !== ''): ?>
                                                <label><?= h($question['followup']) ?><textarea name="followup_answer[<?= $index ?>]" placeholder="Pilih atau jelaskan tindak lanjut pada form lama"></textarea></label>
                                            <?php endif; ?>
                                            <?php if (($question['followupImage'] ?? '') !== ''): ?><img class="question-image" style="margin-left:0" src="<?= h(mediaUrl($question['followupImage'])) ?>" alt="Gambar pendukung pertanyaan lanjutan"><?php endif; ?>
                                            <?php foreach (($question['followupChoices'] ?? []) as $followupIndex => $followupChoice): ?>
                                                <div class="field" style="margin:12px 0 0"><label for="followup-choice-<?= $index ?>-<?= $followupIndex ?>"><?= h($followupChoice['text']) ?> <span style="color:#b42318">*</span></label><select class="followup-choice-select" id="followup-choice-<?= $index ?>-<?= $followupIndex ?>" name="followup_choice_answer[<?= $index ?>][<?= $followupIndex ?>]" required disabled><option value="">Pilih jawaban</option><?php foreach ($followupChoice['options'] as $option): ?><option value="<?= h($option) ?>"><?= h($option) ?></option><?php endforeach; ?></select></div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </section>
                            <?php endforeach; ?>
                            <button class="btn btn-primary" type="submit">Kirim checklist <span aria-hidden="true">→</span></button>
                        </form>
                    </div>
                <?php elseif ($page === 'fill' && !$selectedForm): ?>
                    <div class="empty"><strong>Form tidak ditemukan</strong>Form ini mungkin sudah dihapus atau tautannya tidak valid.</div>
                <?php elseif ($role === 'Respondent' && ($page === 'dashboard' || $page === 'forms')): ?>
                    <div class="page-heading"><div><div class="eyebrow">Ruang pengisian</div><h1>Checklist tersedia</h1><p>Pilih form, jawab setiap kontrol, lalu kirim ke supervisor untuk ditinjau.</p></div></div>
                    <?php if (!$data['forms']): ?><div class="empty"><strong>Belum ada checklist</strong>Administrator belum membuat form untuk diisi.</div><?php endif; ?>
                    <?php foreach ($data['forms'] as $form): ?><article class="panel" style="display:flex;justify-content:space-between;align-items:center;gap:18px"><div><h2 class="panel-title"><?= h($form['title']) ?></h2><p class="panel-subtitle"><?= h($form['description']) ?></p><span class="badge" style="margin-top:10px"><?= count($form['questions']) ?> pertanyaan</span></div><a class="btn btn-primary" href="index.php?page=fill&amp;form=<?= h($form['id']) ?>">Isi form →</a></article><?php endforeach; ?>
                    <div class="panel"><div class="panel-head"><div><h2 class="panel-title">Status pengisian terbaru</h2><div class="panel-subtitle">Pantau checklist yang telah Anda kirim.</div></div><a class="btn btn-light btn-small" href="index.php?page=my-submissions">Semua riwayat</a></div>
                    <?php if (!$visibleSubmissions): ?><div class="empty">Belum ada checklist yang dikirim.</div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Checklist</th><th>Waktu dikirim</th><th>Supervisor</th><th>Status verifikasi</th></tr></thead><tbody><?php foreach (array_slice($visibleSubmissions, 0, 5) as $submission): ?><tr><td><strong><?= h($submission['formTitle']) ?></strong></td><td><?= h(date('d M Y, H:i', strtotime($submission['submittedAt']))) ?> WIT</td><td><?= h($submission['supervisorName']) ?></td><td><span class="badge <?= $submission['status'] === 'Disetujui' ? 'approved' : 'pending' ?>"><?= h(checklistStatusLabel($submission['status'])) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
                <?php elseif ($role === 'Respondent' && $page === 'my-submissions'): ?>
                    <div class="page-heading"><div><div class="eyebrow">Riwayat pengisian</div><h1>Riwayat saya</h1><p>Semua checklist yang Anda kirim, dengan status verifikasi terbaru.</p></div><a class="btn btn-light" href="index.php?page=dashboard">← Kembali ke form</a></div>
                    <section class="panel">
                        <div class="panel-head" style="align-items:flex-start;flex-wrap:wrap">
                            <div><h2 class="panel-title">Riwayat checklist</h2><div class="panel-subtitle">Waktu ditampilkan menurut zona waktu WIT (Asia/Jayapura).</div></div>
                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap">
                                <form method="get" style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">
                                    <input type="hidden" name="page" value="my-submissions">
                                    <label for="history-page-size" style="font-size:11px;font-weight:600;color:var(--muted)">Tampilkan</label>
                                    <select id="history-page-size" name="per_page" style="border:1px solid var(--line);border-radius:8px;padding:7px 9px;background:#fff;color:var(--ink);font-size:11px">
                                        <?php foreach ($historyPageSizes as $pageSize): ?>
                                            <option value="<?= $pageSize ?>" <?= $pageSize === $historyPageSize ? 'selected' : '' ?>><?= $pageSize ?> data</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-light btn-small" type="submit">Terapkan</button>
                                </form>
                                <div style="display:flex;align-items:center;gap:8px;font-size:11px;white-space:nowrap">
                                    <span class="muted"><?= $historyFirstItem ?>–<?= $historyLastItem ?> dari <?= $historyTotal ?></span>
                                    <?php if ($historyPage > 1): ?><a class="btn btn-light btn-small" href="<?= h(historyPageUrl($historyPage - 1, $historyPageSize)) ?>" aria-label="Halaman sebelumnya">←</a><?php endif; ?>
                                    <span>Hal. <?= $historyPage ?>/<?= $historyPageCount ?></span>
                                    <?php if ($historyPage < $historyPageCount): ?><a class="btn btn-light btn-small" href="<?= h(historyPageUrl($historyPage + 1, $historyPageSize)) ?>" aria-label="Halaman berikutnya">→</a><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if (!$historyPageSubmissions): ?>
                            <div class="empty"><strong>Belum ada checklist</strong>Checklist yang Anda kirim akan tampil di sini.</div>
                        <?php else: ?>
                        <div class="table-wrap"><table><thead><tr><th>Checklist</th><th>Waktu dikirim</th><th>Supervisor</th><th>Status verifikasi</th></tr></thead><tbody>
                            <?php foreach ($historyPageSubmissions as $submission): ?>
                                <tr><td><strong><?= h($submission['formTitle']) ?></strong></td><td><?= h(date('d M Y, H:i', strtotime($submission['submittedAt']))) ?> WIT</td><td><?= h($submission['supervisorName']) ?></td><td><span class="badge <?= $submission['status'] === 'Disetujui' ? 'approved' : 'pending' ?>"><?= h(checklistStatusLabel($submission['status'])) ?></span></td></tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                        <?php endif; ?>
                    </section>
                <?php elseif ($page === 'edit-form' && $role === 'Administrator' && $editingForm): ?>
                    <div class="page-heading"><div><div class="eyebrow">Questionnaire management</div><h1>Edit form checklist</h1><p>Perbarui judul, deskripsi, pertanyaan, opsi, dan gambar pendukung.</p></div><a class="btn btn-light" href="index.php?page=forms">← Kembali ke form</a></div>
                    <div class="panel"><div class="panel-head"><div><h2 class="panel-title">Edit <?= h($editingForm['title']) ?></h2><div class="panel-subtitle">Riwayat checklist yang sudah dikirim tidak berubah saat form diedit.</div></div></div>
                        <?php
                            $editingVerifierStartQuestions = verifierStartQuestionNumbers($editingForm['questions']);
                            if ($editingVerifierStartQuestions === []) {
                                $editingVerifierStartQuestions = [1];
                            }
                            $editingVerifierStartQuestion = (int) ($editingForm['verifierStartQuestion'] ?? 1);
                            if (!in_array($editingVerifierStartQuestion, $editingVerifierStartQuestions, true)) {
                                $editingVerifierStartQuestion = $editingVerifierStartQuestions[0];
                            }
                        ?>
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="update_form"><input type="hidden" name="form_id" value="<?= h($editingForm['id']) ?>">
                            <div class="form-grid"><div class="field"><label>Judul form</label><input name="title" maxlength="120" required value="<?= h($editingForm['title']) ?>"></div><div class="field"><label>Deskripsi</label><input name="description" maxlength="240" value="<?= h($editingForm['description']) ?>"></div></div>
                            <div class="field"><label for="verifier-start-question">Mulai wajib jawab ulang dari pertanyaan</label><select id="verifier-start-question" name="verifier_start_question" data-initial-value="<?= $editingVerifierStartQuestion ?>" required><?php foreach ($editingVerifierStartQuestions as $startQuestion): ?><option value="<?= $startQuestion ?>" <?= $startQuestion === $editingVerifierStartQuestion ? 'selected' : '' ?>>Pertanyaan ke-<?= $startQuestion ?></option><?php endforeach; ?></select><span class="help">Jawaban ulang wajib diisi mulai nomor yang dipilih hingga akhir. Pertanyaan lanjutan tetap mengikuti jawaban responden dan jawaban verifikator.</span></div>
                            <div class="field"><label for="company-logo">Logo perusahaan (opsional)</label><input id="company-logo" type="file" name="company_logo" accept="image/jpeg,image/png,image/webp"><span class="help">Format JPG, PNG, atau WEBP · Maksimal 4 MB. Logo tampil di bagian atas form responden.</span>
                                <?php if (($editingForm['logo'] ?? '') !== ''): ?><img class="company-logo logo-editor-preview" src="<?= h(mediaUrl($editingForm['logo'])) ?>" alt="Logo perusahaan saat ini"><label class="logo-remove"><input type="checkbox" name="remove_company_logo" value="1"> Hapus logo saat ini</label><?php endif; ?>
                            </div>
                            <div id="question-list"></div>
                            <div style="display:flex;gap:9px;flex-wrap:wrap"><button class="btn btn-light" type="button" id="add-question">＋ Pertanyaan Ya/Tidak</button><button class="btn btn-light" type="button" id="add-verification-question">＋ Verifikasi Ya/Tidak</button><button class="btn btn-light" type="button" id="add-select-question">＋ Pertanyaan pilihan</button><button class="btn btn-light" type="button" id="add-text-question">＋ Isian teks</button><button class="btn btn-light" type="button" id="add-supervisor-question">＋ Pilihan email supervisor</button></div>
                            <div style="display:flex;gap:9px;margin-top:17px"><button class="btn btn-light" type="button" id="preview-form">Pratinjau / uji form</button><button class="btn btn-primary" type="submit">Simpan perubahan</button><a class="btn btn-light" href="index.php?page=forms">Batal</a></div>
                        </form>
                    </div>
                    <dialog class="preview-dialog" id="form-preview-dialog" aria-labelledby="preview-title">
                        <div class="preview-dialog-head"><div><div class="eyebrow">Pratinjau sebelum publikasi</div><h2 id="preview-title">Uji form</h2></div><button class="btn btn-light btn-small" type="button" data-close-preview>Tutup</button></div>
                        <img id="preview-company-logo" class="company-logo preview-company-logo" alt="Logo perusahaan" hidden>
                        <div id="preview-description" class="panel-subtitle"></div>
                        <form id="preview-response-form" class="preview-response">
                            <div class="field"><label for="preview-email">Email responden <span style="color:#b42318">*</span></label><input id="preview-email" type="email" required autocomplete="off" placeholder="nama@contoh.com"></div>
                            <div id="preview-questions"></div>
                            <div id="preview-message" class="alert warning" hidden>Mode pratinjau: pengujian tidak menyimpan jawaban dan tidak mengirim email.</div>
                            <div class="preview-actions"><button class="btn btn-primary" type="submit">Uji pengisian</button><button class="btn btn-light" type="button" data-close-preview>Tutup pratinjau</button></div>
                        </form>
                    </dialog>
                <?php elseif ($page === 'edit-form' && $role === 'Administrator'): ?>
                    <div class="empty"><strong>Form tidak ditemukan</strong>Form mungkin sudah dihapus. <a href="index.php?page=forms">Kembali ke daftar form</a></div>
                <?php elseif ($page === 'forms' && $role === 'Administrator'): ?>
                    <div class="page-heading"><div><div class="eyebrow">Questionnaire management</div><h1>Form checklist</h1><p>Buat pertanyaan Ya/Tidak, verifikasi Ya/Tidak, pilihan tunggal, isian teks, atau pilihan email supervisor untuk approval.</p></div><a class="btn btn-light" href="index.php">← Dashboard</a></div>
                    <div class="panel"><div class="panel-head"><div><h2 class="panel-title">Buat form baru</h2><div class="panel-subtitle">Tambahkan pertanyaan checklist, termasuk pilihan satu supervisor yang menerima permintaan approval. Setiap form maksimal memiliki satu pertanyaan supervisor.</div></div></div>
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="create_form">
                            <div class="form-grid"><div class="field"><label>Judul form</label><input name="title" maxlength="120" required placeholder="Contoh: Inspeksi keselamatan harian"></div><div class="field"><label>Deskripsi</label><input name="description" maxlength="240" placeholder="Petunjuk singkat untuk responden"></div></div>
                            <div class="field"><label for="verifier-start-question">Mulai wajib jawab ulang dari pertanyaan</label><select id="verifier-start-question" name="verifier_start_question" data-initial-value="1" required><option value="1">Pertanyaan ke-1</option></select><span class="help">Jawaban ulang wajib diisi mulai nomor yang dipilih hingga akhir. Pertanyaan lanjutan tetap mengikuti jawaban responden dan jawaban verifikator.</span></div>
                            <div class="field"><label for="company-logo">Logo perusahaan (opsional)</label><input id="company-logo" type="file" name="company_logo" accept="image/jpeg,image/png,image/webp"><span class="help">Format JPG, PNG, atau WEBP · Maksimal 4 MB. Logo akan tampil di bagian atas form responden.</span></div>
                            <div id="question-list"></div>
                            <div style="display:flex;gap:9px;flex-wrap:wrap"><button class="btn btn-light" type="button" id="add-question">＋ Pertanyaan Ya/Tidak</button><button class="btn btn-light" type="button" id="add-verification-question">＋ Verifikasi Ya/Tidak</button><button class="btn btn-light" type="button" id="add-select-question">＋ Pertanyaan pilihan</button><button class="btn btn-light" type="button" id="add-text-question">＋ Isian teks</button><button class="btn btn-light" type="button" id="add-supervisor-question">＋ Pilihan email supervisor</button></div>
                            <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:17px"><button class="btn btn-light" type="button" id="preview-form">Pratinjau / uji form</button><button class="btn btn-primary" type="submit">Simpan form</button></div>
                        </form>
                    </div>
                    <dialog class="preview-dialog" id="form-preview-dialog" aria-labelledby="preview-title">
                        <div class="preview-dialog-head"><div><div class="eyebrow">Pratinjau sebelum publikasi</div><h2 id="preview-title">Uji form</h2></div><button class="btn btn-light btn-small" type="button" data-close-preview>Tutup</button></div>
                        <img id="preview-company-logo" class="company-logo preview-company-logo" alt="Logo perusahaan" hidden>
                        <div id="preview-description" class="panel-subtitle"></div>
                        <form id="preview-response-form" class="preview-response">
                            <div class="field"><label for="preview-email">Email responden <span style="color:#b42318">*</span></label><input id="preview-email" type="email" required autocomplete="off" placeholder="nama@contoh.com"></div>
                            <div id="preview-questions"></div>
                            <div id="preview-message" class="alert warning" hidden>Mode pratinjau: pengujian tidak menyimpan jawaban dan tidak mengirim email.</div>
                            <div class="preview-actions"><button class="btn btn-primary" type="submit">Uji pengisian</button><button class="btn btn-light" type="button" data-close-preview>Tutup pratinjau</button></div>
                        </form>
                    </dialog>
                    <div class="panel"><div class="panel-head"><div><h2 class="panel-title">Form yang tersedia</h2><div class="panel-subtitle"><?= count($data['forms']) ?> form checklist</div></div></div>
                        <?php if (!$data['forms']): ?><div class="empty">Belum ada form.</div><?php endif; ?>
                        <?php foreach ($data['forms'] as $form):
                            $formUrl = cccApplicationBaseUrl() . '/index.php?page=fill&form=' . rawurlencode($form['id']);
                        ?><div class="panel" style="box-shadow:none;background:#fbfcfe;margin:12px 0;padding:16px"><div class="panel-head" style="margin:0 0 13px"><div><h3 class="panel-title"><?= h($form['title']) ?></h3><div class="panel-subtitle"><?= count($form['questions']) ?> pertanyaan · Dibuat <?= h(date('d M Y', strtotime($form['createdAt']))) ?></div></div><div style="display:flex;gap:7px;flex-wrap:wrap"><a class="btn btn-light btn-small" href="index.php?page=edit-form&amp;id=<?= h($form['id']) ?>">Edit</a><a class="btn btn-light btn-small" href="index.php?page=fill&amp;form=<?= h($form['id']) ?>" target="_blank" rel="noopener">Pratinjau</a><form method="post" onsubmit="return confirm('Hapus form ini dari daftar? Riwayat checklist yang sudah dikirim tetap tersimpan.')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_form"><input type="hidden" name="form_id" value="<?= h($form['id']) ?>"><button class="btn btn-danger btn-small" type="submit">Hapus</button></form></div></div><div class="qr-area"><img loading="lazy" src="index.php?qr_code=<?= h($form['id']) ?>" alt="QR code <?= h($form['title']) ?> dengan logo form di tengah"><div><div class="field-label">QR Code untuk dibagikan</div><p class="help">Pindai atau buka URL untuk mengisi form publik. Logo form disematkan di tengah QR Code. QR Code mengarah ke <?= h(DEFAULT_CCC_APP_URL) ?>. Ubah CCC_APP_URL hanya jika domain aplikasi berubah.</p><div class="form-link"><?= h($formUrl) ?></div><a class="btn btn-light btn-small" style="margin-top:10px" href="index.php?qr_code=<?= h($form['id']) ?>&amp;download=1" download="ccc-form-<?= h($form['id']) ?>.png">Download QR Code</a></div></div></div><?php endforeach; ?>
                    </div>
                <?php elseif ($page === 'users' && $role === 'Administrator'): ?>
                    <div class="page-heading"><div><div class="eyebrow">Access management</div><h1>Pengguna</h1><p>Kelola akun responden, Supervisor, PJO, dan HSE. Akun nonaktif tidak dapat login dan tidak muncul sebagai pilihan penerima checklist.</p></div></div>
                            <div class="panel"><div class="panel-head"><div><h2 class="panel-title">Tambah pengguna</h2><div class="panel-subtitle">Akun Supervisor/PJO/HSE perlu alamat email aktif untuk menerima notifikasi.</div></div></div><form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="create_user"><div class="form-grid"><div class="field"><label>Nama lengkap</label><input name="new_name" required maxlength="100"></div><div class="field"><label>Username</label><input name="new_username" required minlength="3" maxlength="30" pattern="[a-zA-Z0-9._-]+"></div><div class="field"><label>Email</label><input name="new_email" type="email" required></div><div class="field"><label>Role</label><select name="new_role"><option value="Supervisor">Supervisor</option><option value="PJO">PJO</option><option value="HSE">HSE</option><option value="Respondent">Respondent</option></select></div><div class="field"><label>Kata sandi awal (min. 8 karakter)</label><input name="new_password" type="password" required minlength="8" autocomplete="new-password"></div></div><button class="btn btn-primary" type="submit">＋ Buat akun</button></form></div>
                    <div class="panel"><div class="panel-head"><div><h2 class="panel-title">Daftar akun</h2><div class="panel-subtitle"><?= count($data['users']) ?> pengguna terdaftar</div></div></div><div class="table-wrap"><table><thead><tr><th>Pengguna</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
                        <?php foreach ($data['users'] as $account): ?>
                            <?php
                                $accountUsername = $account['username'];
                                $accountIsActive = $account['isActive'] ?? true;
                                $accountHasPendingApprovals = isset($pendingSupervisorUsernames[$accountUsername]);
                                $isLastAdministrator = $account['role'] === 'Administrator' && $administratorCount <= 1;
                                $cannotDeactivateLastAdministrator = $accountIsActive
                                    && $account['role'] === 'Administrator'
                                    && $activeAdministratorCount <= 1;
                                $canToggleAccount = $accountUsername !== $user['username'] && !$cannotDeactivateLastAdministrator;
                                $cannotDeleteLastActiveAdministrator = $cannotDeactivateLastAdministrator;
                                $canDeleteAccount = $accountUsername !== $user['username']
                                    && !$isLastAdministrator
                                    && !$cannotDeleteLastActiveAdministrator
                                    && !$accountHasPendingApprovals;
                                $roleIsLocked = $account['role'] === 'Administrator';
                            ?>
                            <tr>
                                <td><div class="user-row"><div class="person-dot" style="overflow:hidden"><?php if (($account['profileImage'] ?? '') !== ''): ?><img src="<?= h(mediaUrl($account['profileImage'])) ?>" alt="" style="width:29px;height:29px;object-fit:cover"><?php else: ?><?= h(strtoupper(substr($account['name'], 0, 1))) ?><?php endif; ?></div><strong><?= h($account['name']) ?></strong></div></td>
                                <td><?= h($accountUsername) ?></td>
                                <td><?= h($account['email']) ?></td>
                                <td><span class="badge"><?= h($account['role']) ?></span></td>
                                <td><span class="badge <?= $accountIsActive ? 'approved' : 'failed' ?>"><?= $accountIsActive ? 'Aktif' : 'Nonaktif' ?></span></td>
                                <td>
                                    <details>
                                        <summary class="btn btn-light btn-small">Detail akun / edit</summary>
                                        <div style="min-width:240px;padding:12px;border:1px solid var(--line);border-radius:8px;margin-top:8px;background:#fbfcfe">
                                            <strong style="font-size:12px">Informasi akun</strong>
                                            <dl style="display:grid;grid-template-columns:90px minmax(0,1fr);gap:6px 10px;margin:10px 0 0;font-size:11px">
                                                <dt class="muted">Nama lengkap</dt><dd style="margin:0;overflow-wrap:anywhere"><?= h($account['name']) ?></dd>
                                                <dt class="muted">Username</dt><dd style="margin:0;overflow-wrap:anywhere"><?= h($accountUsername) ?></dd>
                                                <dt class="muted">Email</dt><dd style="margin:0;overflow-wrap:anywhere"><?= h($account['email']) ?></dd>
                                                <dt class="muted">Role</dt><dd style="margin:0"><span class="badge"><?= h($account['role']) ?></span></dd>
                                            </dl>
                                            <p class="help" style="margin:10px 0 0">Kata sandi tidak ditampilkan demi keamanan.</p>
                                        </div>
                                        <form method="post" enctype="multipart/form-data" style="min-width:240px;padding:12px;border:1px solid var(--line);border-radius:8px;margin-top:8px">
                                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="update_user">
                                            <input type="hidden" name="username" value="<?= h($accountUsername) ?>">
                                            <div class="field"><label>Nama</label><input name="name" maxlength="100" required value="<?= h($account['name']) ?>"></div>
                                            <div class="field"><label>Email</label><input name="email" type="email" required value="<?= h($account['email']) ?>"></div>
                                            <div class="field"><label>Foto profil</label><input name="profile_image" type="file" accept="image/jpeg,image/png,image/webp"><span class="help">Ganti foto profil dengan JPG, PNG, atau WEBP maksimal 4 MB.</span></div>
                                            <?php if (($account['profileImage'] ?? '') !== ''): ?><label class="logo-remove"><input type="checkbox" name="remove_profile_image" value="1"> Hapus foto profil saat ini</label><?php endif; ?>
                                            <?php if ($roleIsLocked): ?>
                                                <input type="hidden" name="role" value="<?= h($account['role']) ?>">
                                                <div class="field"><label>Role</label><span class="badge"><?= h($account['role']) ?></span><span class="help">Role Administrator tidak dapat diubah.</span></div>
                                            <?php else: ?>
                                                <div class="field"><label>Role</label><select name="role" required><option value="Supervisor" <?= $account['role'] === 'Supervisor' ? 'selected' : '' ?>>Supervisor</option><option value="PJO" <?= $account['role'] === 'PJO' ? 'selected' : '' ?>>PJO</option><option value="HSE" <?= $account['role'] === 'HSE' ? 'selected' : '' ?>>HSE</option><option value="Respondent" <?= $account['role'] === 'Respondent' ? 'selected' : '' ?>>Respondent</option></select><?php if ($accountHasPendingApprovals): ?><span class="help">Role dapat diubah; checklist yang sudah ditugaskan tetap dapat diverifikasi oleh akun ini.</span><?php endif; ?></div>
                                            <?php endif; ?>
                                            <div class="field"><label>Reset kata sandi (opsional)</label><input name="password" type="password" minlength="8" autocomplete="new-password"><span class="help">Isi kata sandi baru minimal 8 karakter untuk mereset akun ini tanpa kata sandi lama. Kosongkan jika tidak ada perubahan.</span></div>
                                            <span class="help">Username tidak dapat diubah agar riwayat checklist tetap terhubung.</span>
                                            <button class="btn btn-primary btn-small" type="submit">Simpan perubahan</button>
                                        </form>
                                    </details>
                                    <?php if ($canToggleAccount): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('<?= $accountIsActive ? 'Nonaktifkan' : 'Aktifkan kembali' ?> akun ini?')">
                                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="toggle_user_status">
                                            <input type="hidden" name="username" value="<?= h($accountUsername) ?>">
                                            <button class="btn <?= $accountIsActive ? 'btn-danger' : 'btn-light' ?> btn-small" type="submit"><?= $accountIsActive ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                                        </form>
                                    <?php else: ?>
                                        <span class="help"><?= $accountUsername === $user['username'] ? 'Akun yang sedang digunakan' : 'Administrator aktif terakhir' ?></span>
                                    <?php endif; ?>
                                    <?php if ($canDeleteAccount): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Hapus akun ini? Riwayat checklist tetap tersimpan.')">
                                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="username" value="<?= h($accountUsername) ?>">
                                            <button class="btn btn-danger btn-small" type="submit">Hapus</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="help"><?= $accountUsername === $user['username'] ? 'Akun aktif' : ($isLastAdministrator ? 'Admin terakhir' : ($cannotDeleteLastActiveAdministrator ? 'Admin aktif terakhir' : 'Verifikasi tertunda')) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div></div>
                <?php elseif (($page === 'my-submissions' || $page === 'dashboard') && $role !== 'Respondent'): ?>
                    <?php
                        $allCount = count($visibleSubmissions);
                        $approvedCount = count(array_filter($visibleSubmissions, static fn(array $item): bool => $item['status'] === 'Disetujui'));
                        $emailFailures = count(array_filter($visibleSubmissions, static fn(array $item): bool => !str_starts_with($item['emailStatus'], 'Terkirim')));
                        $emailRetries = $role === 'Administrator'
                            ? array_values(array_filter($visibleSubmissions, static fn(array $item): bool => !str_starts_with($item['emailStatus'], 'Terkirim')))
                            : [];
                        $supervisorCount = count($supervisors);
                    ?>
                    <?php if ($emailRetries): ?>
                        <section class="panel">
                            <div class="panel-head"><div><h2 class="panel-title">Notifikasi email gagal</h2><div class="panel-subtitle">Kirim ulang setelah konfigurasi email diperbaiki.</div></div></div>
                            <?php foreach ($emailRetries as $failedEmail): ?>
                                <form method="post" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;border-top:1px solid var(--line);padding:12px 0">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="resend_email">
                                    <input type="hidden" name="submission_id" value="<?= h($failedEmail['id']) ?>">
                                    <span style="font-size:12px"><strong><?= h($failedEmail['formTitle']) ?></strong> · <?= h($failedEmail['supervisorEmail']) ?><br><span class="muted"><?= h($failedEmail['emailStatus']) ?></span></span>
                                    <button class="btn btn-light btn-small" type="submit">Kirim ulang</button>
                                </form>
                            <?php endforeach; ?>
                        </section>
                    <?php endif; ?>
                    <div class="page-heading"><div><div class="eyebrow"><?= $role === 'Administrator' ? 'Overview · All operations' : (in_array($role, ['PJO', 'HSE'], true) ? 'Review · All checklists' : 'Review · Assigned checklists') ?></div><h1><?= $page === 'my-submissions' ? 'Riwayat checklist' : 'Dashboard' ?></h1><p><?= $role === 'Administrator' ? 'Pantau jawaban responden, status verifikasi, dan notifikasi email.' : (in_array($role, ['PJO', 'HSE'], true) ? 'Lihat seluruh riwayat checklist; verifikasi hanya tersedia jika email tujuan Supervisor sama dengan email Anda.' : 'Verifikasi checklist yang ditujukan kepada Anda.') ?></p></div><?php if ($role === 'Administrator'): ?><a class="btn btn-primary" href="index.php?page=forms">＋ Kelola form</a><?php endif; ?></div>
                    <section class="cards">
                        <article class="stat-card"><div class="stat-top"><span>Total checklist</span><span class="stat-icon">▤</span></div><div class="stat-number"><?= $allCount ?></div><div class="stat-foot">Seluruh pengisian yang tercatat</div></article>
                        <article class="stat-card"><div class="stat-top"><span>Pending Verified</span><span class="stat-icon" style="background:#fff3dc;color:#a76b00">◷</span></div><div class="stat-number"><?= $pendingCount ?></div><div class="stat-foot">Perlu diverifikasi supervisor</div></article>
                        <article class="stat-card"><div class="stat-top"><span>Verified</span><span class="stat-icon" style="background:#e5f5ee;color:#17734c">✓</span></div><div class="stat-number"><?= $approvedCount ?></div><div class="stat-foot">Checklist selesai diverifikasi</div></article>
                        <article class="stat-card"><div class="stat-top"><span><?= $role === 'Administrator' ? 'Penerima aktif' : 'Notifikasi email' ?></span><span class="stat-icon" style="background:#edf1fa;color:#4c5f9a"><?= $role === 'Administrator' ? '♙' : '✉' ?></span></div><div class="stat-number"><?= $role === 'Administrator' ? $supervisorCount : $emailFailures ?></div><div class="stat-foot"><?= $role === 'Administrator' ? 'Akun Supervisor/PJO/HSE' : 'Status email gagal / belum dikirim' ?></div></article>
                    </section>
                    <section class="panel"><div class="panel-head" style="align-items:flex-start;flex-wrap:wrap"><div><h2 class="panel-title"><?= $page === 'my-submissions' ? 'Riwayat checklist' : 'Hasil pengisian terbaru' ?></h2><div class="panel-subtitle">Waktu ditampilkan menurut zona waktu WIT (Asia/Jayapura).</div></div>
                        <?php if ($page === 'my-submissions'): ?>
                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap">
                                <?php if ($role === 'Administrator'): ?>
                                    <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">
                                        <a class="btn btn-light btn-small" href="index.php?page=my-submissions&amp;export=excel">Ekspor Excel</a>
                                        <a class="btn btn-light btn-small" href="index.php?page=my-submissions&amp;export=pdf">Ekspor PDF</a>
                                    </div>
                                <?php endif; ?>
                                <form method="get" style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">
                                    <input type="hidden" name="page" value="my-submissions">
                                    <label for="history-page-size" style="font-size:11px;font-weight:600;color:var(--muted)">Tampilkan</label>
                                    <select id="history-page-size" name="per_page" style="border:1px solid var(--line);border-radius:8px;padding:7px 9px;background:#fff;color:var(--ink);font-size:11px">
                                        <?php foreach ($historyPageSizes as $pageSize): ?>
                                            <option value="<?= $pageSize ?>" <?= $pageSize === $historyPageSize ? 'selected' : '' ?>><?= $pageSize ?> data</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-light btn-small" type="submit">Terapkan</button>
                                </form>
                                <div style="display:flex;align-items:center;gap:8px;font-size:11px;white-space:nowrap">
                                    <span class="muted"><?= $historyFirstItem ?>–<?= $historyLastItem ?> dari <?= $historyTotal ?></span>
                                    <?php if ($historyPage > 1): ?><a class="btn btn-light btn-small" href="<?= h(historyPageUrl($historyPage - 1, $historyPageSize)) ?>" aria-label="Halaman sebelumnya">←</a><?php endif; ?>
                                    <span>Hal. <?= $historyPage ?>/<?= $historyPageCount ?></span>
                                    <?php if ($historyPage < $historyPageCount): ?><a class="btn btn-light btn-small" href="<?= h(historyPageUrl($historyPage + 1, $historyPageSize)) ?>" aria-label="Halaman berikutnya">→</a><?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <a class="btn btn-light btn-small" href="index.php?page=my-submissions">Lihat semua</a>
                        <?php endif; ?>
                    </div>
                        <?php if (!$visibleSubmissions): ?><div class="empty"><strong>Belum ada hasil pengisian</strong>Hasil checklist akan muncul di sini setelah responden mengirim form.</div><?php else: ?>
                        <div class="table-wrap"><table><thead><tr><th>Checklist / responden</th><th>Email responden</th><th>Waktu dikirim</th><th>Status email</th><th>Status verifikasi</th><th>Aksi</th></tr></thead><tbody>
                            <?php foreach (($page === 'my-submissions' ? $historyPageSubmissions : array_slice($visibleSubmissions, 0, 12)) as $submission): ?>
                                <tr<?= $page === 'my-submissions' && $submission['id'] === $requestedSubmissionId ? ' id="submission-' . h($submission['id']) . '"' : '' ?>>
                                    <td>
                                        <div class="person">
                                            <div class="person-dot"><?= h(strtoupper(substr($submission['respondent'], 0, 1))) ?></div>
                                            <div><strong><?= h($submission['formTitle']) ?></strong><div class="muted" style="font-size:10px"><?= h($submission['respondent']) ?></div></div>
                                        </div>
                                    </td>
                                    <td><?= h($submission['respondentEmail']) ?></td>
                                    <td><?= h(date('d M Y, H:i', strtotime($submission['submittedAt']))) ?> WIT</td>
                                    <td><span class="badge <?= str_starts_with($submission['emailStatus'], 'Terkirim') ? 'approved' : 'failed' ?>"><?= h($submission['emailStatus']) ?></span></td>
                                    <td>
                                        <span class="badge <?= $submission['status'] === 'Disetujui' ? 'approved' : 'pending' ?>"><?= h(checklistStatusLabel($submission['status'])) ?></span>
                                        <?php if ($submission['status'] === 'Disetujui'): ?><div class="muted" style="font-size:9px;margin-top:4px">Diverifikasi oleh <?= h($submission['approvedBy']) ?></div><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (canApproveSubmission($user, $submission) && $submission['status'] === 'Menunggu approval'): ?>
                                            <button class="btn btn-primary btn-small" type="button" data-open-verifier-dialog="verifier-dialog-<?= h($submission['id']) ?>" aria-controls="verifier-dialog-<?= h($submission['id']) ?>" aria-haspopup="dialog">✓ Verify Checklist</button>
                                        <?php else: ?>
                                            <span class="muted"><?= $submission['status'] === 'Menunggu approval' ? 'Tidak ada aksi' : 'Selesai' ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr><td colspan="6"><details <?= $page === 'my-submissions' && $submission['id'] === $requestedSubmissionId ? 'open' : '' ?>><summary style="cursor:pointer;color:#617080;font-size:10px">Lihat jawaban (<?= count($submission['answers']) ?>)</summary><div class="submission-detail"><?php foreach ($submission['answers'] as $index => $answer): ?><p><strong><?= $index + 1 ?>. <?= h($answer['question']) ?></strong><br>Jawaban: <?php if (($answer['type'] ?? 'yes_no') === 'text'): ?><span style="white-space:pre-wrap;overflow-wrap:anywhere"><?= h($answer['choice']) ?></span><?php else: ?><span class="badge <?= $answer['choice'] === 'Ya' ? 'approved' : '' ?>"><?= h($answer['choice']) ?></span><?php endif; ?><?php if (($answer['type'] ?? '') === 'verification' && $answer['choice'] === 'Tidak'): ?><br><span class="muted"><?= h($answer['verificationMessage'] ?? 'Peringatan') ?></span><br>Pilihan: <span class="badge"><?= h($answer['verificationAnswer'] ?? '') ?></span><?php endif; ?><?php if (($answer['type'] ?? 'yes_no') === 'yes_no' && $answer['choice'] === 'Ya'): ?><?php if (($answer['followup'] ?? '') !== ''): ?><br><?= h($answer['followup']) ?>: <?= h($answer['followupAnswer'] ?? '') ?><?php endif; ?><?php foreach ($answer['followupChoiceAnswers'] ?? [] as $followupChoiceAnswer): ?><br><?= h($followupChoiceAnswer['question']) ?>: <span class="badge"><?= h($followupChoiceAnswer['choice']) ?></span><?php endforeach; ?><?php endif; ?></p><?php endforeach; ?><p class="muted">Supervisor: <?= h($submission['supervisorName']) ?><?= $submission['approvedAt'] !== '' ? ' · Verified ' . h(date('d M Y, H:i', strtotime($submission['approvedAt']))) . ' WIT' : '' ?></p><?php if (($submission['approvalEmailStatus'] ?? '') !== ''): ?><p class="muted">Status email pemberitahuan ke responden: <span class="badge <?= str_starts_with($submission['approvalEmailStatus'], 'Terkirim') ? 'approved' : 'failed' ?>"><?= h($submission['approvalEmailStatus']) ?></span></p><?php endif; ?></div></details></td></tr>
                                <tr>
                                    <td colspan="6">
                                        <details <?= $page === 'my-submissions' && $submission['id'] === $requestedSubmissionId ? 'open' : '' ?>>
                                            <summary style="cursor:pointer;color:#617080;font-size:10px">Jawaban Supervisor/Verifikator</summary>
                                            <div class="submission-detail">
                                                <?php if ($submission['status'] === 'Menunggu approval' && canApproveSubmission($user, $submission)): ?>
                                                    <p class="muted">Pilih <strong>Verify Checklist</strong> pada kolom Aksi untuk mengisi jawaban wajib sebelum verifikasi.</p>
                                                <?php elseif (!empty($submission['verifierAnswers'])): ?>
                                                    <?php foreach ($submission['verifierAnswers'] as $verifierAnswer): ?>
                                                        <p>
                                                            <strong><?= h((string) ($verifierAnswer['question'] ?? '')) ?></strong><br>
                                                            Jawaban verifikator: <?= h((string) ($verifierAnswer['choice'] ?? '')) ?>
                                                            <?php if (($verifierAnswer['verificationAnswer'] ?? '') !== ''): ?><br>Alasan: <?= h((string) $verifierAnswer['verificationAnswer']) ?><?php endif; ?>
                                                            <?php if (($verifierAnswer['followupAnswer'] ?? '') !== ''): ?><br><?= h((string) ($verifierAnswer['followup'] ?? 'Tindak lanjut')) ?>: <?= h((string) $verifierAnswer['followupAnswer']) ?><?php endif; ?>
                                                            <?php foreach (($verifierAnswer['followupChoiceAnswers'] ?? []) as $followupAnswer): ?>
                                                                <?php if (is_array($followupAnswer)): ?><br><?= h((string) ($followupAnswer['question'] ?? '')) ?>: <?= h((string) ($followupAnswer['choice'] ?? '')) ?><?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </p>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="muted">Belum ada jawaban verifikator.</p>
                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody></table></div>
                        <?php foreach (($page === 'my-submissions' ? $historyPageSubmissions : array_slice($visibleSubmissions, 0, 12)) as $submission): ?>
                            <?php if (canApproveSubmission($user, $submission) && $submission['status'] === 'Menunggu approval'): ?>
                                <?php $verifierDialogId = 'verifier-dialog-' . $submission['id']; ?>
                                <dialog class="verifier-dialog" id="<?= h($verifierDialogId) ?>" aria-labelledby="<?= h($verifierDialogId) ?>-title">
                                    <div class="verifier-dialog-head">
                                        <div>
                                            <div class="eyebrow">Verifikasi checklist</div>
                                            <h2 id="<?= h($verifierDialogId) ?>-title"><?= h($submission['formTitle']) ?></h2>
                                            <p><?= h($submission['respondent']) ?> · <?= h(date('d M Y, H:i', strtotime($submission['submittedAt']))) ?> WIT</p>
                                        </div>
                                        <button class="btn btn-light btn-small" type="button" data-close-verifier-dialog aria-label="Tutup form verifikasi">Tutup</button>
                                    </div>
                                    <div class="verifier-required-note">
                                        <span aria-hidden="true">!</span>
                                        <p><strong>Jawaban Supervisor/Verifikator</strong><br>Wajib diisi sebelum checklist diverifikasi.</p>
                                    </div>
                                    <?= renderVerifierForm($submission, $csrf) ?>
                                </dialog>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </section>
                <?php else: ?>
                    <div class="empty"><strong>Halaman tidak ditemukan</strong>Halaman ini tidak tersedia untuk akun Anda.</div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        (() => {
            document.querySelectorAll('.verifier-form').forEach((form) => {
                const updateConditionalFields = (index) => {
                    const selectedRadio = form.querySelector(`.verifier-choice[data-index="${index}"]:checked`);
                    const firstControl = form.querySelector(`.verifier-choice[data-index="${index}"]`);
                    const choice = selectedRadio
                        ? selectedRadio.value
                        : (firstControl && firstControl.type !== 'radio' ? firstControl.value : '');
                    form.querySelectorAll(`.verifier-conditional[data-index="${index}"]`).forEach((container) => {
                        const isVisible = container.dataset.showChoice === choice
                            || container.dataset.showRespondentChoice === container.dataset.showChoice;
                        container.hidden = !isVisible;
                        container.querySelectorAll('input, select, textarea').forEach((input) => {
                            input.disabled = !isVisible;
                        });
                    });
                };
                const indices = new Set(
                    [...form.querySelectorAll('.verifier-choice')].map((input) => input.dataset.index)
                );
                indices.forEach((index) => updateConditionalFields(index));
                form.addEventListener('change', (event) => {
                    if (event.target.matches('.verifier-choice')) {
                        updateConditionalFields(event.target.dataset.index);
                    }
                });
            });

            document.querySelectorAll('[data-open-verifier-dialog]').forEach((button) => {
                button.addEventListener('click', () => {
                    const dialog = document.getElementById(button.dataset.openVerifierDialog);
                    if (dialog && typeof dialog.showModal === 'function') {
                        dialog.showModal();
                    }
                });
            });
            document.querySelectorAll('.verifier-dialog').forEach((dialog) => {
                dialog.querySelectorAll('[data-close-verifier-dialog]').forEach((button) => {
                    button.addEventListener('click', () => dialog.close());
                });
                dialog.addEventListener('click', (event) => {
                    if (event.target === dialog) {
                        dialog.close();
                    }
                });
            });

            const editingQuestions = <?= json_encode($editingForm['questions'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const list = document.getElementById('question-list');
            const addYesNoButton = document.getElementById('add-question');
            const addSelectButton = document.getElementById('add-select-question');
            const addTextButton = document.getElementById('add-text-question');
            const addSupervisorButton = document.getElementById('add-supervisor-question');
            const addVerificationButton = document.getElementById('add-verification-question');
            if (list && addYesNoButton && addSelectButton && addTextButton && addSupervisorButton && addVerificationButton) {
                const verifierStartSelect = document.getElementById('verifier-start-question');
                const updateVerifierStartOptions = () => {
                    if (!verifierStartSelect) {
                        return;
                    }
                    const startQuestions = [...list.querySelectorAll('input[name="question_type[]"]')]
                        .map((input, index) => input.value === 'supervisor' ? null : index + 1)
                        .filter((questionNumber) => questionNumber !== null);
                    if (startQuestions.length === 0) {
                        startQuestions.push(1);
                    }
                    const requestedQuestion = Math.max(
                        1,
                        Number(verifierStartSelect.value || verifierStartSelect.dataset.initialValue) || 1
                    );
                    const selectedQuestion = startQuestions.includes(requestedQuestion)
                        ? requestedQuestion
                        : (startQuestions.find((questionNumber) => questionNumber > requestedQuestion) || startQuestions[0]);
                    verifierStartSelect.replaceChildren();
                    startQuestions.forEach((questionNumber) => {
                        const option = document.createElement('option');
                        option.value = String(questionNumber);
                        option.textContent = `Pertanyaan ke-${questionNumber}`;
                        verifierStartSelect.appendChild(option);
                    });
                    verifierStartSelect.value = String(selectedQuestion);
                };
                const addQuestion = (type, initialQuestion = null, originalIndex = -1) => {
                    if (type === 'supervisor' && list.querySelector('input[name="question_type[]"][value="supervisor"]')) {
                        if (initialQuestion === null) {
                            alert('Setiap form hanya dapat memiliki satu pilihan supervisor.');
                            return;
                        }
                    }
                    const index = list.children.length;
                    const block = document.createElement('section');
                    block.className = 'question-builder';
                    const isSelect = type === 'select';
                    const isText = type === 'text';
                    const isSupervisor = type === 'supervisor';
                    const isVerification = type === 'verification';
                    const typeLabel = isSelect ? 'Pilihan tunggal' : (isText ? 'Isian teks' : (isSupervisor ? 'Email supervisor approval' : (isVerification ? 'Verifikasi Ya/Tidak' : 'Ya/Tidak + pilihan lanjutan')));
                    const questionLabel = isSelect ? 'Pertanyaan pilihan tunggal' : (isText ? 'Pertanyaan isian teks' : (isSupervisor ? 'Label pilihan supervisor' : (isVerification ? 'Pertanyaan verifikasi (Ya/Tidak)' : 'Pertanyaan utama (jawaban Ya/Tidak)')));
                    const questionExample = isSelect ? 'Contoh: Pilih kondisi area kerja saat ini.' : (isText ? 'Contoh: Tuliskan nama lengkap Anda.' : (isSupervisor ? 'Contoh: Pilih supervisor untuk menerima permintaan approval.' : (isVerification ? 'Contoh: Apakah seluruh kontrol keselamatan telah dilakukan?' : 'Contoh: Apakah pelindung mesin terpasang dengan baik?')));
                    const responseFields = isSelect
                        ? `<div class="field"><label>Opsi jawaban (satu opsi per baris, minimal 1)</label><textarea name="question_options[]" maxlength="2500" required placeholder="Contoh:&#10;Baik&#10;Perlu perhatian&#10;Tidak aman"></textarea><span class="help">Minimal 1, maksimal 20 opsi; masing-masing maksimal 120 karakter.</span></div><input type="hidden" name="verification_message[]" value=""><input type="hidden" name="followup_text[]" value=""><input type="file" name="followup_image[]" hidden>`
                        : (isVerification
                            ? `<div class="field"><label>Pesan peringatan saat jawaban Tidak</label><textarea name="verification_message[]" maxlength="500" required placeholder="Contoh: Pastikan alasan dan tindakan yang diperlukan dipilih sebelum mengirim."></textarea></div><div class="field"><label>Opsi verifikasi (satu opsi per baris, minimal 1)</label><textarea name="question_options[]" maxlength="2500" required placeholder="Contoh:&#10;Belum dilakukan&#10;Ada kendala&#10;Perlu tindak lanjut"></textarea><span class="help">Saat responden memilih Tidak, pesan dan daftar pilihan ini muncul dalam pop-up. Pilihan wajib diisi sebelum checklist dapat dikirim.</span></div><input type="hidden" name="followup_text[]" value=""><input type="file" name="followup_image[]" hidden>`
                        : (isText || isSupervisor
                            ? `<input type="hidden" name="question_options[]" value=""><input type="hidden" name="verification_message[]" value=""><input type="hidden" name="followup_text[]" value=""><input type="file" name="followup_image[]" hidden>`
                            : `<input type="hidden" name="question_options[]" value=""><input type="hidden" name="verification_message[]" value=""><input type="hidden" name="followup_text[]" value=""><div class="field" style="margin-bottom:0"><label>Gambar pendukung pertanyaan lanjutan (opsional)</label><input type="file" name="followup_image[]" accept="image/jpeg,image/png,image/webp"><span class="help">JPG, PNG, WEBP · Maksimal 4 MB.</span></div><div class="followup-choice-builder"><div class="question-builder-head" style="margin-top:14px"><strong>Pertanyaan lanjutan pilihan</strong><button class="btn btn-light btn-small add-followup-choice" type="button">＋ Tambah pertanyaan pilihan</button></div><div class="followup-choice-list"></div><span class="help">Minimal satu pertanyaan pilihan wajib dibuat. Semua pertanyaan pilihan ini hanya tampil jika responden menjawab Ya.</span></div>`));
                    block.innerHTML = `<div class="question-builder-head"><strong>Pertanyaan ${String(index + 1).padStart(2, '0')} · ${typeLabel}</strong><button class="btn btn-danger btn-small remove-question" type="button">Hapus</button></div><input type="hidden" name="question_type[]" value="${type}"><input type="hidden" name="question_original_index[]" value="${originalIndex}"><div class="field"><label>${questionLabel}</label><textarea name="question_text[]" maxlength="500" required placeholder="${questionExample}"></textarea></div>${isSupervisor ? '<span class="help">Opsi penerima diambil dari akun ber-role Supervisor, PJO, atau HSE. Nama akun tetap ditampilkan pada pilihan; hasil form dan permintaan approval dikirim ke alamat email yang dipilih.</span>' : `<div class="field"><label>Pertanyaan untuk Supervisor/Verifikator</label><textarea name="verifier_question_text[${index}]" maxlength="500" required placeholder="Secara default mengikuti pertanyaan responden"></textarea><span class="help">Pertanyaan ini ditampilkan kembali kepada supervisor bersama jawaban responden untuk dijawab sebelum checklist diverifikasi.</span></div>`}<div class="field"><label>Gambar pendukung (opsional)</label><input type="file" name="question_image[]" accept="image/jpeg,image/png,image/webp"><span class="help">JPG, PNG, WEBP · Maksimal 4 MB.</span></div>${responseFields}`;
                    block.querySelector('.remove-question').addEventListener('click', () => {
                        block.remove();
                        [...list.children].forEach((item, position) => {
                            const itemType = item.querySelector('input[name="question_type[]"]').value;
                            const label = itemType === 'select' ? 'Pilihan tunggal' : (itemType === 'text' ? 'Isian teks' : (itemType === 'supervisor' ? 'Email supervisor approval' : (itemType === 'verification' ? 'Verifikasi Ya/Tidak' : 'Ya/Tidak + pilihan lanjutan')));
                            item.querySelector('.question-builder-head strong').textContent = `Pertanyaan ${String(position + 1).padStart(2, '0')} · ${label}`;
                            const verifierQuestion = item.querySelector('textarea[name^="verifier_question_text["]');
                            if (verifierQuestion) {
                                verifierQuestion.name = `verifier_question_text[${position}]`;
                            }
                            item.querySelectorAll('[data-followup-kind]').forEach((field) => {
                                const fieldName = field.dataset.followupKind === 'verifier_choice_text'
                                    ? 'verifier_followup_choice_text'
                                    : `followup_${field.dataset.followupKind}`;
                                field.name = `${fieldName}[${position}][]`;
                            });
                        });
                        updateVerifierStartOptions();
                    });
                    list.appendChild(block);
                    const followupChoiceList = block.querySelector('.followup-choice-list');
                    const addFollowupChoice = (followupQuestion = null) => {
                        if (!followupChoiceList) {
                            return;
                        }
                        const followupIndex = followupChoiceList.children.length;
                        const followupBlock = document.createElement('div');
                        followupBlock.className = 'question-builder';
                        const questionIndex = [...list.children].indexOf(block);
                        followupBlock.innerHTML = `<div class="question-builder-head"><strong>Pertanyaan pilihan ${String(followupIndex + 1).padStart(2, '0')}</strong><button class="btn btn-danger btn-small remove-followup-choice" type="button">Hapus</button></div><div class="field"><label>Teks pertanyaan lanjutan pilihan</label><input type="text" data-followup-kind="choice_text" name="followup_choice_text[${questionIndex}][]" maxlength="500" required placeholder="Contoh: Jenis tindakan yang diperlukan?"></div><div class="field"><label>Pertanyaan lanjutan untuk Supervisor/Verifikator</label><input type="text" data-followup-kind="verifier_choice_text" name="verifier_followup_choice_text[${questionIndex}][]" maxlength="500" required placeholder="Secara default mengikuti pertanyaan responden"><span class="help">Pertanyaan ini ditampilkan kembali kepada supervisor bersama jawaban responden.</span></div><div class="field" style="margin-bottom:0"><label>Opsi jawaban (satu opsi per baris)</label><textarea data-followup-kind="choice_options" name="followup_choice_options[${questionIndex}][]" maxlength="2500" required placeholder="Contoh:&#10;Perbaiki segera&#10;Beri tanda peringatan"></textarea><span class="help">Minimal 1, maksimal 20 opsi; masing-masing maksimal 120 karakter.</span></div>`;
                        followupBlock.querySelector('.remove-followup-choice').addEventListener('click', () => {
                            followupBlock.remove();
                            [...followupChoiceList.children].forEach((item, position) => {
                                item.querySelector('.question-builder-head strong').textContent = `Pertanyaan pilihan ${String(position + 1).padStart(2, '0')}`;
                            });
                        });
                        if (followupQuestion) {
                            followupBlock.querySelector('[data-followup-kind="choice_text"]').value = followupQuestion.text || '';
                            followupBlock.querySelector('[data-followup-kind="verifier_choice_text"]').value = followupQuestion.verifierText || followupQuestion.text || '';
                            followupBlock.querySelector('[data-followup-kind="choice_options"]').value = (followupQuestion.options || []).join('\n');
                        }
                        const respondentFollowupQuestion = followupBlock.querySelector('[data-followup-kind="choice_text"]');
                        const verifierFollowupQuestion = followupBlock.querySelector('[data-followup-kind="verifier_choice_text"]');
                        if (verifierFollowupQuestion.value !== '' && verifierFollowupQuestion.value !== respondentFollowupQuestion.value) {
                            verifierFollowupQuestion.dataset.customized = 'true';
                        }
                        respondentFollowupQuestion.addEventListener('input', () => {
                            if (!verifierFollowupQuestion.dataset.customized) {
                                verifierFollowupQuestion.value = respondentFollowupQuestion.value;
                            }
                        });
                        verifierFollowupQuestion.addEventListener('input', () => {
                            verifierFollowupQuestion.dataset.customized = 'true';
                        });
                        followupChoiceList.appendChild(followupBlock);
                    };
                    const addFollowupButton = block.querySelector('.add-followup-choice');
                    if (addFollowupButton) {
                        addFollowupButton.addEventListener('click', () => {
                            addFollowupChoice();
                            const nextFollowup = followupChoiceList.lastElementChild;
                            nextFollowup.querySelector('[data-followup-kind="choice_options"]').required = true;
                        });
                    }
                    if (initialQuestion) {
                        block.querySelector('textarea[name="question_text[]"]').value = initialQuestion.text || '';
                        const verifierQuestion = block.querySelector('textarea[name^="verifier_question_text["]');
                        if (verifierQuestion) {
                            verifierQuestion.value = initialQuestion.verifierText || initialQuestion.text || '';
                        }
                        const options = block.querySelector('textarea[name="question_options[]"]');
                        if (options) {
                            options.value = (initialQuestion.options || []).join('\n');
                        }
                        const verificationMessage = block.querySelector('textarea[name="verification_message[]"]');
                        if (verificationMessage) {
                            verificationMessage.value = initialQuestion.verificationMessage || '';
                        }
                        const followup = block.querySelector('textarea[name="followup_text[]"]');
                        if (followup) {
                            followup.value = initialQuestion.followup || '';
                        }
                        if (type === 'yes_no') {
                            if ((initialQuestion.followupChoices || []).length > 0) {
                                initialQuestion.followupChoices.forEach((followupQuestion) => addFollowupChoice(followupQuestion));
                            } else {
                                addFollowupChoice(initialQuestion.followup ? {text: initialQuestion.followup, options: []} : null);
                            }
                        }
                        const image = initialQuestion.image || '';
                        const followupImage = initialQuestion.followupImage || '';
                        if (image || followupImage) {
                            const preview = document.createElement('div');
                            preview.className = 'help';
                            preview.style.margin = '8px 0';
                            preview.textContent = 'Gambar saat ini akan dipertahankan jika tidak memilih gambar baru.';
                            if (image) {
                                const mainPreview = document.createElement('img');
                                mainPreview.src = `index.php?media=${encodeURIComponent(image)}`;
                                mainPreview.alt = 'Gambar pendukung saat ini';
                                mainPreview.className = 'question-image';
                                mainPreview.style.marginLeft = '0';
                                preview.appendChild(mainPreview);
                            }
                            if (followupImage && type === 'yes_no') {
                                const followupPreview = document.createElement('img');
                                followupPreview.src = `index.php?media=${encodeURIComponent(followupImage)}`;
                                followupPreview.alt = 'Gambar pertanyaan lanjutan saat ini';
                                followupPreview.className = 'question-image';
                                followupPreview.style.marginLeft = '0';
                                preview.appendChild(followupPreview);
                            }
                            block.appendChild(preview);
                        }
                    } else if (type === 'yes_no') {
                        addFollowupChoice();
                    }
                    const verifierQuestion = block.querySelector('textarea[name^="verifier_question_text["]');
                    if (verifierQuestion) {
                        const respondentQuestion = block.querySelector('textarea[name="question_text[]"]');
                        if (verifierQuestion.value !== '' && verifierQuestion.value !== respondentQuestion.value) {
                            verifierQuestion.dataset.customized = 'true';
                        }
                        respondentQuestion.addEventListener('input', () => {
                            if (!verifierQuestion.dataset.customized) {
                                verifierQuestion.value = respondentQuestion.value;
                            }
                        });
                        verifierQuestion.addEventListener('input', () => {
                            verifierQuestion.dataset.customized = 'true';
                        });
                    }
                };
                addYesNoButton.addEventListener('click', () => {
                    addQuestion('yes_no');
                    updateVerifierStartOptions();
                });
                addVerificationButton.addEventListener('click', () => {
                    addQuestion('verification');
                    updateVerifierStartOptions();
                });
                addSelectButton.addEventListener('click', () => {
                    addQuestion('select');
                    updateVerifierStartOptions();
                });
                addTextButton.addEventListener('click', () => {
                    addQuestion('text');
                    updateVerifierStartOptions();
                });
                addSupervisorButton.addEventListener('click', () => {
                    addQuestion('supervisor');
                    updateVerifierStartOptions();
                });
                if (editingQuestions.length > 0) {
                    editingQuestions.forEach((question, originalIndex) => {
                        addQuestion(question.type || 'yes_no', question, originalIndex);
                    });
                } else {
                    addQuestion('yes_no');
                }
                updateVerifierStartOptions();
            }
            document.querySelectorAll('[data-question]').forEach((card) => {
                if (card.dataset.type === 'verification') {
                    const dialog = card.querySelector('.verification-dialog');
                    const select = dialog.querySelector('.verification-answer');
                    const confirmButton = dialog.querySelector('.verification-confirm');
                    const closeDialog = () => {
                        if (dialog.open) {
                            dialog.close();
                        }
                    };
                    const updateConfirmButton = () => {
                        confirmButton.disabled = select.value === '';
                    };
                    select.addEventListener('change', updateConfirmButton);
                    confirmButton.addEventListener('click', () => {
                        if (select.value !== '') {
                            dialog.close();
                        }
                    });
                    dialog.querySelectorAll('[data-close-verification]').forEach((button) => {
                        button.addEventListener('click', closeDialog);
                    });
                    card.querySelectorAll('input[type="radio"]').forEach((radio) => {
                        radio.addEventListener('change', () => {
                            const isNo = card.querySelector('input[type="radio"]:checked').value === 'Tidak';
                            select.disabled = !isNo;
                            if (!isNo) {
                                select.value = '';
                                updateConfirmButton();
                                closeDialog();
                                return;
                            }
                            updateConfirmButton();
                            if (!dialog.open) {
                                dialog.showModal();
                            }
                        });
                    });
                    return;
                }
                if (card.dataset.type !== 'yes_no') {
                    return;
                }
                const followup = card.querySelector('.followup-box');
                const textarea = followup.querySelector('textarea[name^="followup_answer"]');
                const followupChoiceSelects = followup.querySelectorAll('.followup-choice-select');
                card.querySelectorAll('input[type="radio"]').forEach((radio) => {
                    radio.addEventListener('change', () => {
                        const show = card.querySelector('input[type="radio"]:checked').value === 'Ya';
                        followup.hidden = !show;
                        if (textarea) {
                            textarea.required = show;
                        }
                        followupChoiceSelects.forEach((select) => {
                            select.disabled = !show;
                            select.required = show;
                        });
                    });
                });
            });
        })();
        (() => {
            const shell = document.getElementById('app-shell');
            const toggleButtons = [...document.querySelectorAll('.sidebar-toggle')];
            if (!shell || toggleButtons.length === 0) {
                return;
            }

            let isHidden = window.matchMedia('(max-width: 900px)').matches;
            const updateSidebar = () => {
                shell.classList.toggle('sidebar-hidden', isHidden);
                toggleButtons.forEach((button) => {
                    button.setAttribute('aria-expanded', String(!isHidden));
                    if (button.classList.contains('sidebar-toggle-side')) {
                        button.setAttribute('aria-label', isHidden ? 'Tampilkan sidebar' : 'Sembunyikan sidebar');
                        button.title = isHidden ? 'Tampilkan sidebar' : 'Sembunyikan sidebar';
                        button.textContent = isHidden ? '›' : '‹';
                    } else {
                        button.setAttribute('aria-label', isHidden ? 'Tampilkan sidebar' : 'Sembunyikan sidebar');
                        button.title = isHidden ? 'Tampilkan sidebar' : 'Sembunyikan sidebar';
                        button.textContent = isHidden ? '☰' : '×';
                    }
                });
            };
            toggleButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    isHidden = !isHidden;
                    updateSidebar();
                });
            });
            updateSidebar();
        })();
        (() => {
            const previewButton = document.getElementById('preview-form');
            const dialog = document.getElementById('form-preview-dialog');
            const builder = document.getElementById('question-list');
            const responseForm = document.getElementById('preview-response-form');
            if (!previewButton || !dialog || !builder || !responseForm) {
                return;
            }

            const previewEditingQuestions = <?= json_encode($editingForm['questions'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const previewEditingFormLogo = <?= json_encode($editingForm['logo'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const supervisors = <?= json_encode(array_values(array_map(
                static fn(array $supervisor): array => [
                    'username' => $supervisor['username'],
                    'name' => $supervisor['name'],
                    'email' => $supervisor['email'],
                ],
                $supervisors
            )), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const titleInput = document.querySelector('#question-list').closest('.panel').querySelector('input[name="title"]');
            const descriptionInput = document.querySelector('#question-list').closest('.panel').querySelector('input[name="description"]');
            const previewTitle = dialog.querySelector('#preview-title');
            const previewDescription = dialog.querySelector('#preview-description');
            const previewLogo = dialog.querySelector('#preview-company-logo');
            const companyLogoInput = document.querySelector('#company-logo');
            const questionsTarget = dialog.querySelector('#preview-questions');
            const previewMessage = dialog.querySelector('#preview-message');
            const imageUrls = [];

            const addOption = (select, value, label) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                select.appendChild(option);
            };
            const makeSelect = (name, labelText, values, placeholder) => {
                const field = document.createElement('div');
                field.className = 'field';
                const id = `preview-${name.replace(/[^a-z0-9_-]/gi, '-')}`;
                const label = document.createElement('label');
                label.htmlFor = id;
                label.textContent = labelText;
                const select = document.createElement('select');
                select.id = id;
                select.name = name;
                select.required = true;
                addOption(select, '', placeholder);
                values.forEach((value) => addOption(select, value, value));
                field.append(label, select);
                return {field, select};
            };
            const addImage = (container, fileInput, fallbackName) => {
                const originalIndex = Number(fileInput.closest('.question-builder').querySelector('[name="question_original_index[]"]').value);
                const savedImage = Number.isInteger(originalIndex) && originalIndex >= 0
                    ? (previewEditingQuestions[originalIndex]?.[fallbackName] || '')
                    : '';
                const file = fileInput.files[0];
                if (!file && !savedImage) {
                    return;
                }
                const image = document.createElement('img');
                image.className = 'question-image';
                image.alt = 'Gambar pendukung pertanyaan';
                if (file) {
                    image.src = URL.createObjectURL(file);
                    imageUrls.push(image.src);
                } else {
                    image.src = `index.php?media=${encodeURIComponent(savedImage)}`;
                }
                container.appendChild(image);
            };
            const makeQuestion = (questionBlock, index) => {
                const type = questionBlock.querySelector('[name="question_type[]"]').value;
                const title = questionBlock.querySelector('[name="question_text[]"]').value.trim();
                const card = document.createElement('section');
                card.className = 'question-card';
                card.dataset.previewType = type;
                const heading = document.createElement('div');
                heading.className = 'question-title';
                const number = document.createElement('span');
                number.className = 'question-number';
                number.textContent = String(index + 1).padStart(2, '0');
                const textWrap = document.createElement('div');
                const questionTitle = document.createElement('h3');
                questionTitle.textContent = title || `Pertanyaan ${index + 1}`;
                const hint = document.createElement('p');
                hint.textContent = type === 'yes_no' || type === 'verification' ? 'Pilih Ya atau Tidak.' : (type === 'text' ? 'Isi jawaban teks.' : 'Pilih satu opsi.');
                textWrap.append(questionTitle, hint);
                heading.append(number, textWrap);
                card.appendChild(heading);
                addImage(card, questionBlock.querySelector('[name="question_image[]"]'), 'image');

                if (type === 'text') {
                    const field = document.createElement('div');
                    field.className = 'field';
                    const label = document.createElement('label');
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.required = true;
                    input.maxLength = 500;
                    input.name = `preview-answer-${index}`;
                    label.htmlFor = input.name;
                    label.textContent = 'Jawaban teks';
                    input.id = input.name;
                    field.append(label, input);
                    card.appendChild(field);
                } else if (type === 'select' || type === 'supervisor') {
                    const optionsField = questionBlock.querySelector('[name="question_options[]"]');
                    const options = type === 'supervisor'
                        ? supervisors.map((supervisor) => `${supervisor.name} · ${supervisor.email}`)
                        : (optionsField?.value || '').split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
                    const result = makeSelect(`preview-answer-${index}`, type === 'supervisor' ? 'Pilih supervisor approval' : 'Pilih satu jawaban', options, type === 'supervisor' ? 'Pilih supervisor' : 'Pilih jawaban');
                    card.appendChild(result.field);
                } else if (type === 'verification') {
                    const choices = document.createElement('div');
                    choices.className = 'answer-choice';
                    const groupName = `preview-answer-${index}`;
                    const dialog = document.createElement('dialog');
                    dialog.className = 'verification-dialog';
                    const dialogHead = document.createElement('div');
                    dialogHead.className = 'verification-dialog-head';
                    const icon = document.createElement('span');
                    icon.className = 'verification-icon';
                    icon.textContent = '!';
                    const messageWrap = document.createElement('div');
                    const dialogTitle = document.createElement('h2');
                    dialogTitle.textContent = 'Peringatan verifikasi';
                    const message = document.createElement('p');
                    message.textContent = questionBlock.querySelector('[name="verification_message[]"]').value.trim();
                    messageWrap.append(dialogTitle, message);
                    const closeButton = document.createElement('button');
                    closeButton.type = 'button';
                    closeButton.className = 'btn btn-light btn-small';
                    closeButton.textContent = 'Tutup';
                    closeButton.addEventListener('click', () => dialog.close());
                    dialogHead.append(icon, messageWrap, closeButton);

                    const optionsText = questionBlock.querySelector('[name="question_options[]"]').value;
                    const options = optionsText.split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
                    const result = makeSelect(`preview-verification-${index}`, 'Pilih alasan / tindak lanjut', options, 'Pilih salah satu opsi');
                    result.select.classList.add('verification-answer');
                    result.select.disabled = true;
                    const confirmButton = document.createElement('button');
                    confirmButton.type = 'button';
                    confirmButton.className = 'btn btn-primary verification-confirm';
                    confirmButton.textContent = 'Konfirmasi pilihan';
                    confirmButton.disabled = true;
                    result.select.addEventListener('change', () => {
                        confirmButton.disabled = result.select.value === '';
                    });
                    confirmButton.addEventListener('click', () => {
                        if (result.select.value !== '') {
                            dialog.close();
                        }
                    });
                    dialog.append(dialogHead, result.field, confirmButton);
                    ['Ya', 'Tidak'].forEach((value) => {
                        const label = document.createElement('label');
                        label.className = 'choice';
                        const radio = document.createElement('input');
                        radio.type = 'radio';
                        radio.name = groupName;
                        radio.value = value;
                        radio.required = true;
                        radio.addEventListener('change', () => {
                            const isNo = radio.value === 'Tidak';
                            result.select.disabled = !isNo;
                            if (!isNo) {
                                result.select.value = '';
                                confirmButton.disabled = true;
                                if (dialog.open) {
                                    dialog.close();
                                }
                            } else if (!dialog.open) {
                                dialog.showModal();
                            }
                        });
                        label.append(radio, document.createTextNode(value));
                        choices.appendChild(label);
                    });
                    card.append(choices, dialog);
                } else {
                    const choices = document.createElement('div');
                    choices.className = 'answer-choice';
                    const groupName = `preview-answer-${index}`;
                    const followup = document.createElement('div');
                    followup.className = 'followup-box';
                    followup.hidden = true;
                    const followupImageInput = questionBlock.querySelector('[name="followup_image[]"]');
                    if (followupImageInput) {
                        addImage(followup, followupImageInput, 'followupImage');
                    }
                    const followupQuestions = questionBlock.querySelectorAll('.followup-choice-list > .question-builder');
                    followupQuestions.forEach((followupQuestion, followupIndex) => {
                        const followupText = followupQuestion.querySelector('[data-followup-kind="choice_text"]').value.trim();
                        const rawOptions = followupQuestion.querySelector('[data-followup-kind="choice_options"]').value;
                        const options = rawOptions.split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
                        const result = makeSelect(
                            `preview-followup-${index}-${followupIndex}`,
                            followupText || `Pertanyaan pilihan ${followupIndex + 1}`,
                            options,
                            'Pilih jawaban'
                        );
                        result.field.querySelector('select').disabled = true;
                        result.field.style.marginBottom = '0';
                        followup.appendChild(result.field);
                    });
                    ['Ya', 'Tidak'].forEach((value) => {
                        const label = document.createElement('label');
                        label.className = 'choice';
                        const radio = document.createElement('input');
                        radio.type = 'radio';
                        radio.name = groupName;
                        radio.value = value;
                        radio.required = true;
                        radio.addEventListener('change', () => {
                            const showFollowup = radio.value === 'Ya';
                            followup.hidden = !showFollowup;
                            followup.querySelectorAll('select').forEach((select) => {
                                select.disabled = !showFollowup;
                                select.required = showFollowup;
                            });
                        });
                        label.append(radio, document.createTextNode(value));
                        choices.appendChild(label);
                    });
                    card.append(choices, followup);
                }
                return card;
            };

            previewButton.addEventListener('click', () => {
                imageUrls.splice(0).forEach((url) => URL.revokeObjectURL(url));
                responseForm.reset();
                questionsTarget.replaceChildren();
                previewMessage.hidden = true;
                previewMessage.className = 'alert warning';
                previewTitle.textContent = titleInput.value.trim() || 'Uji form tanpa judul';
                previewDescription.textContent = descriptionInput.value.trim();
                const selectedLogo = companyLogoInput?.files[0];
                const removeExistingLogo = document.querySelector('[name="remove_company_logo"]')?.checked ?? false;
                if (selectedLogo) {
                    previewLogo.src = URL.createObjectURL(selectedLogo);
                    imageUrls.push(previewLogo.src);
                    previewLogo.hidden = false;
                } else if (previewEditingFormLogo && !removeExistingLogo) {
                    previewLogo.src = `index.php?media=${encodeURIComponent(previewEditingFormLogo)}`;
                    previewLogo.hidden = false;
                } else {
                    previewLogo.removeAttribute('src');
                    previewLogo.hidden = true;
                }

                const questionBlocks = [...builder.children];
                questionBlocks.forEach((questionBlock, index) => {
                    questionsTarget.appendChild(makeQuestion(questionBlock, index));
                });
                if (!questionBlocks.some((block) => block.querySelector('[name="question_type[]"]').value === 'supervisor')) {
                    const supervisorField = makeSelect(
                        'preview-supervisor',
                        'Kirim hasil kepada supervisor',
                        supervisors.map((supervisor) => `${supervisor.name} · ${supervisor.email}`),
                        'Pilih supervisor'
                    );
                    supervisorField.field.querySelector('select').required = true;
                    questionsTarget.prepend(supervisorField.field);
                }
                dialog.showModal();
            });

            dialog.querySelectorAll('[data-close-preview]').forEach((button) => {
                button.addEventListener('click', () => dialog.close());
            });
            dialog.addEventListener('close', () => {
                imageUrls.splice(0).forEach((url) => URL.revokeObjectURL(url));
            });
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
            responseForm.addEventListener('submit', (event) => {
                event.preventDefault();
                const missingVerification = [...questionsTarget.querySelectorAll('[data-preview-type="verification"]')].find((card) => {
                    const selected = card.querySelector('input[type="radio"]:checked');
                    return selected?.value === 'Tidak' && !card.querySelector('.verification-answer').value;
                });
                if (missingVerification) {
                    missingVerification.querySelector('.verification-dialog').showModal();
                    return;
                }
                if (!responseForm.reportValidity()) {
                    return;
                }
                previewMessage.textContent = 'Uji pratinjau berhasil. Tidak ada jawaban yang disimpan dan tidak ada email yang dikirim.';
                previewMessage.className = 'alert success';
                previewMessage.hidden = false;
            });
        })();
        function validateChecklist(form) {
            const missingVerification = [...form.querySelectorAll('[data-question][data-type="verification"]')].find((card) => {
                const selected = card.querySelector('input[type="radio"]:checked');
                return selected?.value === 'Tidak' && !card.querySelector('.verification-answer').value;
            });
            if (missingVerification) {
                const dialog = missingVerification.querySelector('.verification-dialog');
                if (!dialog.open) {
                    dialog.showModal();
                }
                return false;
            }

            const missing = [...form.querySelectorAll('[data-question]')].some((card) => {
                if (card.dataset.type === 'select' || card.dataset.type === 'supervisor') {
                    return !card.querySelector('select[name^="answer"]').value;
                }
                if (card.dataset.type === 'text') {
                    return !card.querySelector('input[name^="answer"]').value.trim();
                }
                const selected = card.querySelector('input[type="radio"]:checked');
                if (!selected) {
                    return true;
                }
                if (card.dataset.type === 'verification') {
                    return selected.value === 'Tidak' && !card.querySelector('.verification-answer').value;
                }
                if (selected.value !== 'Ya') {
                    return false;
                }
                const followupChoices = [...card.querySelectorAll('.followup-choice-select')];
                if (followupChoices.length > 0) {
                    return followupChoices.some((select) => !select.value);
                }
                const legacyFollowup = card.querySelector('textarea[name^="followup_answer"]');
                return !legacyFollowup || !legacyFollowup.value.trim();
            });
            if (missing) {
                alert('Lengkapi semua jawaban checklist dan pilihan verifikasi yang diwajibkan.');
                return false;
            }
            return true;
        }
    </script>
<?php endif; ?>
</body>
</html>
