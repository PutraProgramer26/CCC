<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

const APP_NAME = 'CRITICAL CONTROL CHECKLIST';
const MAX_IMAGE_SIZE = 4_194_304;

$privateDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'ccc-private';
$uploadDir = $privateDir . DIRECTORY_SEPARATOR . 'uploads';
$dataFile = $privateDir . DIRECTORY_SEPARATOR . 'data.json';

foreach ([$privateDir, $uploadDir] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        http_response_code(500);
        exit('Folder penyimpanan aplikasi tidak dapat dibuat. Periksa izin folder XAMPP.');
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

function redirectTo(string $query = ''): never
{
    header('Location: index.php' . ($query !== '' ? '?' . $query : ''));
    exit;
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
    $types = $_POST['question_type'] ?? [];
    $optionTexts = $_POST['question_options'] ?? [];
    $originalIndices = $_POST['question_original_index'] ?? [];
    if (!is_array($texts) || count($texts) === 0 || !is_array($types)) {
        throw new RuntimeException('Form harus memiliki minimal satu pertanyaan.');
    }

    $questions = [];
    $supervisorQuestionCount = 0;
    foreach ($texts as $index => $questionText) {
        $questionText = trim((string) $questionText);
        $type = (string) ($types[$index] ?? 'yes_no');
        if ($questionText === '' || !in_array($type, ['yes_no', 'verification', 'select', 'text', 'supervisor'], true)) {
            throw new RuntimeException('Pertanyaan tidak valid. Periksa kembali tipe dan isi pertanyaan.');
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
            $followupChoiceOptions = $_POST['followup_choice_options'][$index] ?? [];
            if (!is_array($followupChoiceTexts) || !is_array($followupChoiceOptions) || count($followupChoiceTexts) !== count($followupChoiceOptions)) {
                throw new RuntimeException('Pertanyaan lanjutan pilihan tidak valid.');
            }
            foreach ($followupChoiceTexts as $followupIndex => $followupChoiceText) {
                $followupChoiceText = trim((string) $followupChoiceText);
                $rawOptions = (string) ($followupChoiceOptions[$followupIndex] ?? '');
                $choiceOptions = array_values(array_unique(array_filter(
                    array_map('trim', preg_split('/\R/u', $rawOptions) ?: []),
                    static fn(string $option): bool => $option !== ''
                )));
                if ($followupChoiceText === '') {
                    throw new RuntimeException('Teks setiap pertanyaan lanjutan pilihan wajib diisi.');
                }
                if (count($choiceOptions) < 1 || count($choiceOptions) > 20) {
                    throw new RuntimeException('Setiap pertanyaan lanjutan pilihan harus memiliki 1 sampai 20 opsi.');
                }
                if (array_filter($choiceOptions, static fn(string $option): bool => strlen($option) > 120)) {
                    throw new RuntimeException('Setiap opsi pertanyaan lanjutan maksimal 120 karakter.');
                }
                $followupChoices[] = [
                    'text' => $followupChoiceText,
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

try {
    $data = loadData($dataFile);
} catch (Throwable $exception) {
    http_response_code(500);
    exit(h($exception->getMessage()));
}

if (isset($_GET['media'])) {
    $filename = (string) $_GET['media'];
    if (!isset($_SESSION['user']) || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) {
        http_response_code(404);
        exit;
    }
    $path = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
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

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user']) && ($_GET['page'] ?? '') === 'fill') {
    $requestedFormId = (string) ($_GET['form'] ?? '');
    foreach ($data['forms'] as $form) {
        if ($form['id'] === $requestedFormId) {
            $_SESSION['return_to'] = 'page=fill&form=' . rawurlencode($requestedFormId);
            break;
        }
    }
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
            $returnTo = $_SESSION['return_to'] ?? '';
            unset($_SESSION['return_to']);
            session_regenerate_id(true);
            $_SESSION['user'] = $username;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            setFlash('success', 'Selamat datang, ' . $user['name'] . '.');
            redirectTo($user['role'] === 'Respondent' && preg_match('/^page=fill&form=[a-f0-9]{12}$/', $returnTo) ? $returnTo : '');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            setFlash('success', 'Anda telah keluar dengan aman.');
            redirectTo();
        }

        if (!isset($_SESSION['user']) || !isset($data['users'][$_SESSION['user']])) {
            throw new RuntimeException('Silakan masuk terlebih dahulu.');
        }
        $currentUser = $data['users'][$_SESSION['user']];

        if ($action === 'create_form' && $currentUser['role'] === 'Administrator') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('Judul dan minimal satu pertanyaan utama wajib diisi.');
            }
            if (strlen($title) > 120 || strlen($description) > 240) {
                throw new RuntimeException('Judul maksimal 120 karakter dan deskripsi maksimal 240 karakter.');
            }
            $questions = buildQuestionsFromPost($uploadDir);
            $logo = storeImage(getSingleUploadedFile('company_logo'), $uploadDir);
            $data['forms'][] = [
                'id' => bin2hex(random_bytes(6)),
                'title' => $title,
                'description' => $description,
                'logo' => $logo,
                'questions' => $questions,
                'createdAt' => date(DATE_ATOM),
            ];
            saveData($dataFile, $data);
            setFlash('success', 'Form checklist berhasil dibuat.');
            redirectTo('page=forms');
        }

        if ($action === 'update_form' && $currentUser['role'] === 'Administrator') {
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
            $data['forms'][$formIndex]['questions'] = buildQuestionsFromPost(
                $uploadDir,
                $data['forms'][$formIndex]['questions']
            );
            saveData($dataFile, $data);
            setFlash('success', 'Perubahan form berhasil disimpan. Riwayat checklist sebelumnya tetap tersimpan.');
            redirectTo('page=forms');
        }

        if ($action === 'delete_form' && $currentUser['role'] === 'Administrator') {
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

        if ($action === 'create_user' && $currentUser['role'] === 'Administrator') {
            $username = strtolower(trim((string) ($_POST['new_username'] ?? '')));
            $name = trim((string) ($_POST['new_name'] ?? ''));
            $email = trim((string) ($_POST['new_email'] ?? ''));
            $role = (string) ($_POST['new_role'] ?? '');
            $password = (string) ($_POST['new_password'] ?? '');
            if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username) || isset($data['users'][$username])) {
                throw new RuntimeException('Username harus 3–30 karakter (huruf, angka, titik, garis bawah, atau tanda hubung) dan belum digunakan.');
            }
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['Supervisor', 'Respondent'], true) || strlen($password) < 8) {
                throw new RuntimeException('Nama, email valid, role, dan kata sandi minimal 8 karakter wajib diisi.');
            }
            $data['users'][$username] = [
                'username' => $username,
                'name' => $name,
                'role' => $role,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
            ];
            saveData($dataFile, $data);
            setFlash('success', 'Akun ' . $role . ' berhasil ditambahkan.');
            redirectTo('page=users');
        }

        if ($action === 'submit_response' && $currentUser['role'] === 'Respondent') {
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
            $email = trim((string) ($_POST['respondent_email'] ?? ''));
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
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$supervisor || $supervisor['role'] !== 'Supervisor') {
                throw new RuntimeException('Periksa form, email responden, dan supervisor yang dipilih.');
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
                        throw new RuntimeException('Pilih supervisor yang tersedia untuk menerima permintaan approval.');
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
                    'choice' => $choice,
                    'followup' => $type === 'yes_no' && $choice === 'Ya' && $followupChoiceAnswers === [] ? ($question['followup'] ?? '') : '',
                    'followupAnswer' => $legacyFollowupAnswer,
                    'followupChoiceAnswers' => $followupChoiceAnswers,
                    'verificationMessage' => $type === 'verification' ? ($question['verificationMessage'] ?? '') : '',
                    'verificationAnswer' => $verificationAnswer,
                ];
            }
            $submission = [
                'id' => bin2hex(random_bytes(8)),
                'formId' => $form['id'],
                'formTitle' => $form['title'],
                'respondent' => $currentUser['name'],
                'respondentEmail' => $email,
                'supervisor' => $supervisorUsername,
                'supervisorName' => $supervisor['name'],
                'supervisorEmail' => $supervisor['email'],
                'answers' => $answers,
                'submittedAt' => date(DATE_ATOM),
                'status' => 'Menunggu approval',
                'approvedBy' => '',
                'approvedAt' => '',
                'emailStatus' => 'Belum dikirim',
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
            redirectTo('page=my-submissions');
        }

        if ($action === 'approve' && in_array($currentUser['role'], ['Administrator', 'Supervisor'], true)) {
            $submissionId = (string) ($_POST['submission_id'] ?? '');
            foreach ($data['submissions'] as &$submission) {
                if ($submission['id'] !== $submissionId) {
                    continue;
                }
                if ($currentUser['role'] === 'Supervisor' && $submission['supervisor'] !== $currentUser['username']) {
                    throw new RuntimeException('Supervisor hanya dapat menyetujui checklist yang ditujukan kepadanya.');
                }
                if ($submission['status'] !== 'Menunggu approval') {
                    throw new RuntimeException('Checklist ini sudah diproses sebelumnya.');
                }
                $submission['status'] = 'Disetujui';
                $submission['approvedBy'] = $currentUser['name'];
                $submission['approvedAt'] = date(DATE_ATOM);
                break;
            }
            unset($submission);
            if (!array_filter($data['submissions'], static fn(array $item): bool => $item['id'] === $submissionId)) {
                throw new RuntimeException('Data checklist tidak ditemukan.');
            }
            saveData($dataFile, $data);
            setFlash('success', 'Checklist berhasil disetujui.');
            redirectTo('page=dashboard');
        }

        throw new RuntimeException('Aksi tidak tersedia atau Anda tidak memiliki izin.');
    } catch (Throwable $exception) {
        setFlash('error', $exception->getMessage());
        redirectTo();
    }
}

$username = $_SESSION['user'] ?? '';
$user = $data['users'][$username] ?? null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$page = (string) ($_GET['page'] ?? 'dashboard');
$supervisors = array_filter($data['users'], static fn(array $item): bool => $item['role'] === 'Supervisor');
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
    return $submission['respondent'] === $user['name'];
}));
usort($visibleSubmissions, static fn(array $a, array $b): int => strcmp($b['submittedAt'], $a['submittedAt']));
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
$csrf = h($_SESSION['csrf']);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111827">
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
        .password-control{position:relative}.password-control input{padding-right:48px}.password-visibility{position:absolute;right:7px;top:50%;transform:translateY(-50%);display:grid;place-items:center;width:34px;height:34px;border:0;background:transparent;color:var(--muted);padding:7px;border-radius:6px}.password-visibility:hover{background:var(--mint);color:var(--accent)}.password-visibility:focus-visible{outline:2px solid var(--accent);outline-offset:2px}.password-visibility svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.password-visibility [hidden]{display:none}
        @media(max-width:900px){.shell:not(.sidebar-hidden)::before{content:"";position:fixed;inset:0;background:rgba(17,24,39,.42);z-index:2}.sidebar{width:254px;padding:20px 18px;transform:translateX(-100%)}.shell:not(.sidebar-hidden) .sidebar{transform:translateX(0)}.shell.sidebar-hidden .sidebar{transform:translateX(-100%)}.main,.shell.sidebar-hidden .main{margin-left:0}.topbar{position:sticky;z-index:5;padding:0 20px}.topbar-toggle,.shell:not(.sidebar-hidden) .topbar-toggle{visibility:visible}.content{width:100%;padding:26px 20px 44px}.table-wrap{max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}.panel-head{flex-wrap:wrap;align-items:flex-start}.panel-head>.btn{flex:none}.login-page{grid-template-columns:1fr}.login-aside{min-height:245px;padding:24px}.login-copy{margin:37px 0 12px}.login-copy h1{font-size:32px}.login-footer{display:none}.login-form-side{padding:34px 23px}}
        @media(max-width:600px){body{font-size:14px}.topbar{min-height:58px;height:auto;padding:9px 13px;gap:8px}.topbar-left{gap:8px}.topbar-toggle{padding:8px 10px}.crumb{overflow:hidden;text-overflow:ellipsis}.top-actions{gap:7px}.content{padding:22px 13px 36px}.page-heading{gap:13px;margin-bottom:20px}.page-heading h1{font-size:22px}.page-heading>.btn{max-width:100%;white-space:normal}.cards{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.stat-card{padding:12px}.stat-top{gap:6px}.stat-number{font-size:22px}.panel{padding:14px;border-radius:11px}.form-grid{grid-template-columns:minmax(0,1fr);gap:0}.field input,.field textarea,.field select{min-height:44px;font-size:16px}.field textarea{min-height:88px}.btn{min-height:40px}.btn-small{min-height:38px}.question-card{padding:13px}.question-title{gap:9px}.answer-choice{flex-wrap:wrap;gap:8px}.choice{min-height:42px;padding:9px 12px}.question-image,.preview-dialog .question-image{max-width:100%;margin-left:0}.panel-head{gap:10px}.panel-head>div{min-width:0}.panel-head>.btn{white-space:nowrap}.qr-area{width:100%;align-items:flex-start;flex-direction:column}.qr-area img{max-width:min(100%,180px);height:auto}.form-link{max-width:100%;overflow-wrap:anywhere}.preview-dialog,.verification-dialog{width:calc(100% - 20px);max-height:calc(100% - 20px);padding:16px}.preview-dialog-head{gap:10px}.preview-dialog-head h2{font-size:18px}.login-page{min-height:100vh;min-height:100dvh}.login-form-side{padding:28px 20px}.login-aside{min-height:220px}.password-visibility{width:40px;height:40px}.password-control input{padding-right:52px}}
        @media(max-width:380px){.cards{grid-template-columns:minmax(0,1fr)}.stat-card{padding:14px}.top-actions .muted{display:none}.content{padding-right:11px;padding-left:11px}.topbar{padding-right:10px;padding-left:10px}.page-heading h1{font-size:20px}.panel{padding:12px}}
        @media(max-width:760px){.sidebar{width:254px;padding:20px 18px;transform:translateX(0)}.brand{padding:4px 10px 28px}.brand-name,.nav-link span:not(.nav-icon),.nav-label,.profile-text{display:initial}.brand-name{display:block}.nav-link{justify-content:flex-start;padding:11px 12px}.nav-icon{font-size:initial}.profile{justify-content:flex-start;padding:17px 7px 0}.main{margin-left:0}.shell.sidebar-hidden .main{margin-left:0}.shell:not(.sidebar-hidden) .topbar-toggle{visibility:hidden}.shell.sidebar-hidden .topbar-toggle{visibility:visible}.topbar{padding:0 15px;z-index:5}.content{padding:24px 15px 40px}}
    </style>
</head>
<body>
<?php if (!$user): ?>
    <main class="login-page">
        <section class="login-aside">
            <div class="brand"><div class="brand-mark">C</div><div class="brand-name">CRITICAL CONTROL<span>CHECKLIST SYSTEM</span></div></div>
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
    $role = $user['role'];
    $pageTitles = ['dashboard' => 'Dashboard', 'forms' => 'Form checklist', 'edit-form' => 'Edit form', 'users' => 'Pengguna', 'my-submissions' => 'Riwayat pengisian', 'fill' => 'Isi checklist'];
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
    $navItems[] = ['my-submissions', '◷', $role === 'Respondent' ? 'Riwayat saya' : 'Riwayat checklist'];
    $pendingCount = count(array_filter($visibleSubmissions, static fn(array $submission): bool => $submission['status'] === 'Menunggu approval'));
?>
    <div class="shell" id="app-shell">
        <aside class="sidebar" id="app-sidebar">
            <div class="sidebar-brand-row"><a class="brand" href="index.php"><div class="brand-mark">C</div><div class="brand-name">CRITICAL CONTROL<span>CHECKLIST SYSTEM</span></div></a><button type="button" class="sidebar-toggle sidebar-toggle-side" aria-label="Sembunyikan sidebar" title="Sembunyikan sidebar" aria-controls="app-sidebar" aria-expanded="true">‹</button></div>
            <div class="nav-label">Workspace</div>
            <?php foreach ($navItems as [$navPage, $icon, $label]): ?>
                <a class="nav-link <?= ($page === $navPage || ($page === 'fill' && $navPage === 'dashboard')) ? 'active' : '' ?>" href="index.php<?= $navPage === 'dashboard' && $role === 'Respondent' ? '' : '?page=' . h($navPage) ?>"><span class="nav-icon"><?= h($icon) ?></span><span><?= h($label) ?></span></a>
            <?php endforeach; ?>
            <div class="profile"><div class="profile-user"><div class="avatar"><?= h(strtoupper(substr($user['name'], 0, 1))) ?></div><div class="profile-text"><strong><?= h($user['name']) ?></strong><small><?= h($role) ?></small></div></div>
                <form method="post" class="logout-form"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="logout"><button title="Log out" class="btn btn-small logout-button" type="submit"><span aria-hidden="true">↗</span> Log out</button></form>
            </div>
        </aside>
        <main class="main">
            <header class="topbar"><div class="topbar-left"><button type="button" class="btn btn-light sidebar-toggle topbar-toggle" aria-label="Tampilkan sidebar" title="Tampilkan / sembunyikan sidebar" aria-controls="app-sidebar" aria-expanded="true">☰</button><div class="crumb">CCC <span style="padding:0 7px;color:#aeb8c4">/</span> <b><?= h($pageTitle) ?></b></div></div><div class="top-actions"><span class="badge"><?= h($role) ?></span><span class="muted" style="font-size:11px"><?= date('d M Y') ?></span></div></header>
            <div class="content">
                <?php if ($flash): ?><div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

                <?php if ($page === 'fill' && $selectedForm && $role === 'Respondent'): ?>
                    <div class="fill-container">
                        <div style="margin-bottom:16px"><a class="muted" href="index.php" style="font-size:11px">← Kembali ke daftar form</a></div>
                        <div class="form-intro">
                            <?php if (($selectedForm['logo'] ?? '') !== ''): ?><img class="company-logo" src="<?= h(mediaUrl($selectedForm['logo'])) ?>" alt="Logo perusahaan"><?php endif; ?>
                            <div class="eyebrow">Critical control checklist</div><h1><?= h($selectedForm['title']) ?></h1><p><?= h($selectedForm['description']) ?></p>
                        </div>
                        <form method="post" class="panel" style="padding:19px" onsubmit="return validateChecklist(this)">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="submit_response"><input type="hidden" name="form_id" value="<?= h($selectedForm['id']) ?>">
                            <div class="form-grid">
                                <div class="field"><label for="respondent_email">Email responden <span style="color:#b42318">*</span></label><input type="email" id="respondent_email" name="respondent_email" required autocomplete="email" maxlength="254" placeholder="nama@perusahaan.com"><span class="help">Wajib diisi. Email ini akan disimpan bersama hasil checklist dan ditampilkan di dashboard.</span></div>
                                <?php if (!$formHasSupervisorQuestion): ?><div class="field"><label for="supervisor">Kirim hasil kepada supervisor <span style="color:#b42318">*</span></label><select id="supervisor" name="supervisor" required><option value="">Pilih supervisor</option><?php foreach ($supervisors as $supervisor): ?><option value="<?= h($supervisor['username']) ?>"><?= h($supervisor['name']) ?> · <?= h($supervisor['email']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                            </div>
                            <?php foreach ($selectedForm['questions'] as $index => $question): ?>
                                <?php $questionType = $question['type'] ?? 'yes_no'; ?>
                                <section class="question-card" data-question data-type="<?= h($questionType) ?>">
                                    <div class="question-title"><span class="question-number"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><div><h3><?= h($question['text']) ?></h3><p><?= $questionType === 'select' ? 'Pilih satu opsi jawaban.' : ($questionType === 'text' ? 'Isi jawaban teks.' : ($questionType === 'supervisor' ? 'Pilih supervisor yang akan menerima hasil checklist untuk approval.' : ($questionType === 'verification' ? 'Pilih Ya atau Tidak. Jawaban Tidak memerlukan konfirmasi.' : 'Pilih Ya atau Tidak untuk melanjutkan.'))) ?></p></div></div>
                                    <?php if ($question['image'] !== ''): ?><img class="question-image" src="<?= h(mediaUrl($question['image'])) ?>" alt="Gambar pendukung pertanyaan <?= $index + 1 ?>"><?php endif; ?>
                                    <?php if ($questionType === 'select'): ?>
                                        <div class="field" style="margin:15px 0 0 41px"><label for="answer-<?= $index ?>">Pilih satu jawaban</label><select class="answer-select" id="answer-<?= $index ?>" name="answer[<?= $index ?>]" required><option value="">Pilih jawaban</option><?php foreach (($question['options'] ?? []) as $option): ?><option value="<?= h($option) ?>"><?= h($option) ?></option><?php endforeach; ?></select></div>
                                    <?php elseif ($questionType === 'text'): ?>
                                        <div class="field" style="margin:15px 0 0 41px"><label for="answer-<?= $index ?>">Jawaban teks <span style="color:#b42318">*</span></label><input class="answer-text" id="answer-<?= $index ?>" name="answer[<?= $index ?>]" type="text" maxlength="500" required placeholder="Contoh: tuliskan nama lengkap Anda"></div>
                                    <?php elseif ($questionType === 'supervisor'): ?>
                                        <div class="field" style="margin:15px 0 0 41px"><label for="answer-<?= $index ?>">Email supervisor tujuan approval <span style="color:#b42318">*</span></label><select class="answer-select" id="answer-<?= $index ?>" name="answer[<?= $index ?>]" required><option value="">Pilih supervisor</option><?php foreach ($supervisors as $supervisor): ?><option value="<?= h($supervisor['username']) ?>"><?= h($supervisor['name']) ?> · <?= h($supervisor['email']) ?></option><?php endforeach; ?></select></div>
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
                            <div class="alert warning" style="margin-top:15px;margin-bottom:14px">Pada pertanyaan Ya/Tidak biasa, jawaban <strong>Ya</strong> membuka pertanyaan lanjutan pilihan. Pada <strong>Verifikasi Ya/Tidak</strong>, jawaban <strong>Tidak</strong> membuka peringatan dan mewajibkan pilihan tindak lanjut, sedangkan jawaban Ya dapat langsung dikirim.</div>
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
                    <?php if (!$visibleSubmissions): ?><div class="empty">Belum ada checklist yang dikirim.</div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Checklist</th><th>Waktu dikirim</th><th>Supervisor</th><th>Status</th></tr></thead><tbody><?php foreach (array_slice($visibleSubmissions, 0, 5) as $submission): ?><tr><td><strong><?= h($submission['formTitle']) ?></strong></td><td><?= h(date('d M Y, H:i', strtotime($submission['submittedAt']))) ?> WIB</td><td><?= h($submission['supervisorName']) ?></td><td><span class="badge <?= $submission['status'] === 'Disetujui' ? 'approved' : 'pending' ?>"><?= h($submission['status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
                <?php elseif ($page === 'edit-form' && $role === 'Administrator' && $editingForm): ?>
                    <div class="page-heading"><div><div class="eyebrow">Questionnaire management</div><h1>Edit form checklist</h1><p>Perbarui judul, deskripsi, pertanyaan, opsi, dan gambar pendukung.</p></div><a class="btn btn-light" href="index.php?page=forms">← Kembali ke form</a></div>
                    <div class="panel"><div class="panel-head"><div><h2 class="panel-title">Edit <?= h($editingForm['title']) ?></h2><div class="panel-subtitle">Riwayat checklist yang sudah dikirim tidak berubah saat form diedit.</div></div></div>
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="update_form"><input type="hidden" name="form_id" value="<?= h($editingForm['id']) ?>">
                            <div class="form-grid"><div class="field"><label>Judul form</label><input name="title" maxlength="120" required value="<?= h($editingForm['title']) ?>"></div><div class="field"><label>Deskripsi</label><input name="description" maxlength="240" value="<?= h($editingForm['description']) ?>"></div></div>
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
                            $formUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME']) . '/index.php?page=fill&form=' . rawurlencode($form['id']);
                        ?><div class="panel" style="box-shadow:none;background:#fbfcfe;margin:12px 0;padding:16px"><div class="panel-head" style="margin:0 0 13px"><div><h3 class="panel-title"><?= h($form['title']) ?></h3><div class="panel-subtitle"><?= count($form['questions']) ?> pertanyaan · Dibuat <?= h(date('d M Y', strtotime($form['createdAt']))) ?></div></div><div style="display:flex;gap:7px;flex-wrap:wrap"><a class="btn btn-light btn-small" href="index.php?page=edit-form&amp;id=<?= h($form['id']) ?>">Edit</a><a class="btn btn-light btn-small" href="index.php?page=fill&amp;form=<?= h($form['id']) ?>" target="_blank" rel="noopener">Pratinjau</a><form method="post" onsubmit="return confirm('Hapus form ini dari daftar? Riwayat checklist yang sudah dikirim tetap tersimpan.')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_form"><input type="hidden" name="form_id" value="<?= h($form['id']) ?>"><button class="btn btn-danger btn-small" type="submit">Hapus</button></form></div></div><div class="qr-area"><img loading="lazy" src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&amp;data=<?= rawurlencode($formUrl) ?>" alt="QR code <?= h($form['title']) ?>"><div><div class="field-label">QR Code untuk dibagikan</div><p class="help">Pindai untuk membuka form. Responden harus masuk sebagai akun Responden.</p><div class="form-link"><?= h($formUrl) ?></div><a class="btn btn-light btn-small" style="margin-top:10px" href="https://api.qrserver.com/v1/create-qr-code/?size=600x600&amp;data=<?= rawurlencode($formUrl) ?>" target="_blank" rel="noopener">Buka / simpan QR</a></div></div></div><?php endforeach; ?>
                    </div>
                <?php elseif ($page === 'users' && $role === 'Administrator'): ?>
                    <div class="page-heading"><div><div class="eyebrow">Access management</div><h1>Pengguna</h1><p>Kelola akun responden dan supervisor yang menerima notifikasi checklist.</p></div></div>
                    <div class="panel"><div class="panel-head"><div><h2 class="panel-title">Tambah pengguna</h2><div class="panel-subtitle">Akun supervisor perlu alamat email aktif untuk menerima notifikasi.</div></div></div><form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="create_user"><div class="form-grid"><div class="field"><label>Nama lengkap</label><input name="new_name" required maxlength="100"></div><div class="field"><label>Username</label><input name="new_username" required minlength="3" maxlength="30" pattern="[a-zA-Z0-9._-]+"></div><div class="field"><label>Email</label><input name="new_email" type="email" required></div><div class="field"><label>Role</label><select name="new_role"><option value="Supervisor">Supervisor</option><option value="Respondent">Respondent</option></select></div><div class="field"><label>Kata sandi awal (min. 8 karakter)</label><input name="new_password" type="password" required minlength="8" autocomplete="new-password"></div></div><button class="btn btn-primary" type="submit">＋ Buat akun</button></form></div>
                    <div class="panel"><div class="panel-head"><div><h2 class="panel-title">Daftar akun</h2><div class="panel-subtitle"><?= count($data['users']) ?> pengguna terdaftar</div></div></div><div class="table-wrap"><table><thead><tr><th>Pengguna</th><th>Username</th><th>Email</th><th>Role</th></tr></thead><tbody><?php foreach ($data['users'] as $account): ?><tr><td><div class="user-row"><div class="person-dot"><?= h(strtoupper(substr($account['name'], 0, 1))) ?></div><strong><?= h($account['name']) ?></strong></div></td><td><?= h($account['username']) ?></td><td><?= h($account['email']) ?></td><td><span class="badge"><?= h($account['role']) ?></span></td></tr><?php endforeach; ?></tbody></table></div></div>
                <?php elseif (($page === 'my-submissions' || $page === 'dashboard') && $role !== 'Respondent'): ?>
                    <?php
                        $allCount = count($visibleSubmissions);
                        $approvedCount = count(array_filter($visibleSubmissions, static fn(array $item): bool => $item['status'] === 'Disetujui'));
                        $emailFailures = count(array_filter($visibleSubmissions, static fn(array $item): bool => !str_starts_with($item['emailStatus'], 'Terkirim')));
                        $supervisorCount = count($supervisors);
                    ?>
                    <div class="page-heading"><div><div class="eyebrow"><?= $role === 'Administrator' ? 'Overview · All operations' : 'Review · Assigned checklists' ?></div><h1><?= $page === 'my-submissions' ? 'Riwayat checklist' : 'Dashboard' ?></h1><p><?= $role === 'Administrator' ? 'Pantau jawaban responden, status persetujuan, dan notifikasi email.' : 'Tinjau dan setujui checklist yang ditujukan kepada Anda.' ?></p></div><?php if ($role === 'Administrator'): ?><a class="btn btn-primary" href="index.php?page=forms">＋ Kelola form</a><?php endif; ?></div>
                    <section class="cards">
                        <article class="stat-card"><div class="stat-top"><span>Total checklist</span><span class="stat-icon">▤</span></div><div class="stat-number"><?= $allCount ?></div><div class="stat-foot">Seluruh pengisian yang tercatat</div></article>
                        <article class="stat-card"><div class="stat-top"><span>Menunggu approval</span><span class="stat-icon" style="background:#fff3dc;color:#a76b00">◷</span></div><div class="stat-number"><?= $pendingCount ?></div><div class="stat-foot">Perlu ditinjau supervisor</div></article>
                        <article class="stat-card"><div class="stat-top"><span>Disetujui</span><span class="stat-icon" style="background:#e5f5ee;color:#17734c">✓</span></div><div class="stat-number"><?= $approvedCount ?></div><div class="stat-foot">Checklist selesai disetujui</div></article>
                        <article class="stat-card"><div class="stat-top"><span><?= $role === 'Administrator' ? 'Supervisor aktif' : 'Notifikasi email' ?></span><span class="stat-icon" style="background:#edf1fa;color:#4c5f9a"><?= $role === 'Administrator' ? '♙' : '✉' ?></span></div><div class="stat-number"><?= $role === 'Administrator' ? $supervisorCount : $emailFailures ?></div><div class="stat-foot"><?= $role === 'Administrator' ? 'Akun penerima checklist' : 'Status email gagal / belum dikirim' ?></div></article>
                    </section>
                    <section class="panel"><div class="panel-head"><div><h2 class="panel-title">Hasil pengisian terbaru</h2><div class="panel-subtitle">Waktu ditampilkan menurut zona waktu WIB (Asia/Jakarta).</div></div><a class="btn btn-light btn-small" href="index.php?page=my-submissions">Lihat semua</a></div>
                        <?php if (!$visibleSubmissions): ?><div class="empty"><strong>Belum ada hasil pengisian</strong>Hasil checklist akan muncul di sini setelah responden mengirim form.</div><?php else: ?>
                        <div class="table-wrap"><table><thead><tr><th>Checklist / responden</th><th>Email responden</th><th>Waktu dikirim</th><th>Status email</th><th>Status approval</th><th>Aksi</th></tr></thead><tbody>
                            <?php foreach (($page === 'my-submissions' ? $visibleSubmissions : array_slice($visibleSubmissions, 0, 12)) as $submission): ?>
                                <tr><td><div class="person"><div class="person-dot"><?= h(strtoupper(substr($submission['respondent'], 0, 1))) ?></div><div><strong><?= h($submission['formTitle']) ?></strong><div class="muted" style="font-size:10px"><?= h($submission['respondent']) ?></div></div></div></td><td><?= h($submission['respondentEmail']) ?></td><td><?= h(date('d M Y, H:i', strtotime($submission['submittedAt']))) ?> WIB</td><td><span class="badge <?= str_starts_with($submission['emailStatus'], 'Terkirim') ? 'approved' : 'failed' ?>"><?= h($submission['emailStatus']) ?></span></td><td><span class="badge <?= $submission['status'] === 'Disetujui' ? 'approved' : 'pending' ?>"><?= h($submission['status']) ?></span><?php if ($submission['status'] === 'Disetujui'): ?><div class="muted" style="font-size:9px;margin-top:4px">oleh <?= h($submission['approvedBy']) ?></div><?php endif; ?></td><td><?php if ($submission['status'] === 'Menunggu approval'): ?><form method="post" onsubmit="return confirm('Setujui checklist ini?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="submission_id" value="<?= h($submission['id']) ?>"><button class="btn btn-primary btn-small" type="submit">✓ Setujui</button></form><?php else: ?><span class="muted">Selesai</span><?php endif; ?></td></tr>
                                <tr><td colspan="6"><details><summary style="cursor:pointer;color:#617080;font-size:10px">Lihat jawaban (<?= count($submission['answers']) ?>)</summary><div class="submission-detail"><?php foreach ($submission['answers'] as $index => $answer): ?><p><strong><?= $index + 1 ?>. <?= h($answer['question']) ?></strong><br>Jawaban: <?php if (($answer['type'] ?? 'yes_no') === 'text'): ?><span style="white-space:pre-wrap;overflow-wrap:anywhere"><?= h($answer['choice']) ?></span><?php else: ?><span class="badge <?= $answer['choice'] === 'Ya' ? 'approved' : '' ?>"><?= h($answer['choice']) ?></span><?php endif; ?><?php if (($answer['type'] ?? '') === 'verification' && $answer['choice'] === 'Tidak'): ?><br><span class="muted"><?= h($answer['verificationMessage'] ?? 'Peringatan') ?></span><br>Pilihan: <span class="badge"><?= h($answer['verificationAnswer'] ?? '') ?></span><?php endif; ?><?php if (($answer['type'] ?? 'yes_no') === 'yes_no' && $answer['choice'] === 'Ya'): ?><?php if (($answer['followup'] ?? '') !== ''): ?><br><?= h($answer['followup']) ?>: <?= h($answer['followupAnswer'] ?? '') ?><?php endif; ?><?php foreach ($answer['followupChoiceAnswers'] ?? [] as $followupChoiceAnswer): ?><br><?= h($followupChoiceAnswer['question']) ?>: <span class="badge"><?= h($followupChoiceAnswer['choice']) ?></span><?php endforeach; ?><?php endif; ?></p><?php endforeach; ?><p class="muted">Supervisor: <?= h($submission['supervisorName']) ?><?= $submission['approvedAt'] !== '' ? ' · Disetujui ' . h(date('d M Y, H:i', strtotime($submission['approvedAt']))) . ' WIB' : '' ?></p></div></details></td></tr>
                            <?php endforeach; ?>
                        </tbody></table></div><?php endif; ?>
                    </section>
                <?php else: ?>
                    <div class="empty"><strong>Halaman tidak ditemukan</strong>Halaman ini tidak tersedia untuk akun Anda.</div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        (() => {
            const editingQuestions = <?= json_encode($editingForm['questions'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const list = document.getElementById('question-list');
            const addYesNoButton = document.getElementById('add-question');
            const addSelectButton = document.getElementById('add-select-question');
            const addTextButton = document.getElementById('add-text-question');
            const addSupervisorButton = document.getElementById('add-supervisor-question');
            const addVerificationButton = document.getElementById('add-verification-question');
            if (list && addYesNoButton && addSelectButton && addTextButton && addSupervisorButton && addVerificationButton) {
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
                    block.innerHTML = `<div class="question-builder-head"><strong>Pertanyaan ${String(index + 1).padStart(2, '0')} · ${typeLabel}</strong><button class="btn btn-danger btn-small remove-question" type="button">Hapus</button></div><input type="hidden" name="question_type[]" value="${type}"><input type="hidden" name="question_original_index[]" value="${originalIndex}"><div class="field"><label>${questionLabel}</label><textarea name="question_text[]" maxlength="500" required placeholder="${questionExample}"></textarea></div>${isSupervisor ? '<span class="help">Opsi supervisor diambil dari akun ber-role Supervisor. Hasil form dan permintaan approval dikirim ke alamat email yang dipilih.</span>' : ''}<div class="field"><label>Gambar pendukung (opsional)</label><input type="file" name="question_image[]" accept="image/jpeg,image/png,image/webp"><span class="help">JPG, PNG, WEBP · Maksimal 4 MB.</span></div>${responseFields}`;
                    block.querySelector('.remove-question').addEventListener('click', () => {
                        block.remove();
                        [...list.children].forEach((item, position) => {
                            const itemType = item.querySelector('input[name="question_type[]"]').value;
                            const label = itemType === 'select' ? 'Pilihan tunggal' : (itemType === 'text' ? 'Isian teks' : (itemType === 'supervisor' ? 'Email supervisor approval' : (itemType === 'verification' ? 'Verifikasi Ya/Tidak' : 'Ya/Tidak + pilihan lanjutan')));
                            item.querySelector('.question-builder-head strong').textContent = `Pertanyaan ${String(position + 1).padStart(2, '0')} · ${label}`;
                            item.querySelectorAll('[data-followup-kind]').forEach((field) => {
                                field.name = `followup_choice_${field.dataset.followupKind}[${position}][]`;
                            });
                        });
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
                        followupBlock.innerHTML = `<div class="question-builder-head"><strong>Pertanyaan pilihan ${String(followupIndex + 1).padStart(2, '0')}</strong><button class="btn btn-danger btn-small remove-followup-choice" type="button">Hapus</button></div><div class="field"><label>Teks pertanyaan lanjutan pilihan</label><input type="text" data-followup-kind="choice_text" name="followup_choice_text[${questionIndex}][]" maxlength="500" required placeholder="Contoh: Jenis tindakan yang diperlukan?"></div><div class="field" style="margin-bottom:0"><label>Opsi jawaban (satu opsi per baris)</label><textarea data-followup-kind="choice_options" name="followup_choice_options[${questionIndex}][]" maxlength="2500" required placeholder="Contoh:&#10;Perbaiki segera&#10;Beri tanda peringatan"></textarea><span class="help">Minimal 1, maksimal 20 opsi; masing-masing maksimal 120 karakter.</span></div>`;
                        followupBlock.querySelector('.remove-followup-choice').addEventListener('click', () => {
                            followupBlock.remove();
                            [...followupChoiceList.children].forEach((item, position) => {
                                item.querySelector('.question-builder-head strong').textContent = `Pertanyaan pilihan ${String(position + 1).padStart(2, '0')}`;
                            });
                        });
                        if (followupQuestion) {
                            followupBlock.querySelector('[data-followup-kind="choice_text"]').value = followupQuestion.text || '';
                            followupBlock.querySelector('[data-followup-kind="choice_options"]').value = (followupQuestion.options || []).join('\n');
                        }
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
                };
                addYesNoButton.addEventListener('click', () => addQuestion('yes_no'));
                addVerificationButton.addEventListener('click', () => addQuestion('verification'));
                addSelectButton.addEventListener('click', () => addQuestion('select'));
                addTextButton.addEventListener('click', () => addQuestion('text'));
                addSupervisorButton.addEventListener('click', () => addQuestion('supervisor'));
                if (editingQuestions.length > 0) {
                    editingQuestions.forEach((question, originalIndex) => {
                        addQuestion(question.type || 'yes_no', question, originalIndex);
                    });
                } else {
                    addQuestion('yes_no');
                }
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
