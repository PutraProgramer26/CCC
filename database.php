<?php
declare(strict_types=1);

/**
 * Create a PDO connection to the CCC MySQL database.
 *
 * The application defaults to its hosting database. For local XAMPP, use
 * host 127.0.0.1, port 3306, user root, and an empty password.
 * Override these values with DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD.
 */
function getDatabaseConnection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '3306';
    $database = getenv('DB_NAME') ?: 'u170828859_CCC';
    $username = getenv('DB_USER') ?: 'u170828859_JastiPravita';
    $password = getenv('DB_PASSWORD');
    $password = $password === false ? 'Programer260705' : $password;

    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('DB_PORT harus berupa nomor port yang valid.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        (int) $port,
        $database
    );

    try {
        $connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (PDOException $exception) {
        error_log('CCC database connection failed: ' . $exception->getMessage());
        if ((int) ($exception->errorInfo[1] ?? 0) === 1226) {
            throw new RuntimeException(
                'Kuota koneksi per jam akun database CCC telah tercapai. Tunggu hingga kuota hosting direset atau hubungi dukungan hosting untuk menaikkan max_connections_per_hour. Hindari memuat ulang halaman berulang kali.',
                0,
                $exception
            );
        }

        throw new RuntimeException(
            'Koneksi database CCC gagal: ' . $exception->getMessage()
            . ' Pastikan server MySQL pada host aktif, database CCC tersedia, dan konfigurasi DB_HOST/DB_PORT/DB_USER/DB_PASSWORD sesuai.',
            0,
            $exception
        );
    }

    return $connection;
}

$pdo = getDatabaseConnection();

function ensureCccSchema(PDO $connection): void
{
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS ccc_users (
            username VARCHAR(30) NOT NULL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            role VARCHAR(20) NOT NULL,
            email VARCHAR(254) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            profile_image VARCHAR(40) NOT NULL DEFAULT '',
            INDEX idx_ccc_users_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $userRoleColumn = $connection->query("SHOW COLUMNS FROM ccc_users LIKE 'role'")->fetch();
    if (!$userRoleColumn) {
        throw new RuntimeException('Kolom role pada tabel pengguna CCC tidak ditemukan.');
    }
    $userRoleType = strtolower((string) ($userRoleColumn['Type'] ?? ''));
    if (!preg_match('/^varchar\((\d+)\)$/', $userRoleType, $userRoleTypeMatch)
        || (int) $userRoleTypeMatch[1] < 20) {
        $connection->exec('ALTER TABLE ccc_users MODIFY COLUMN role VARCHAR(20) NOT NULL');
    }
    $userActiveColumn = $connection->query("SHOW COLUMNS FROM ccc_users LIKE 'is_active'")->fetch();
    if (!$userActiveColumn) {
        $connection->exec("ALTER TABLE ccc_users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash");
    }
    $userProfileImageColumn = $connection->query("SHOW COLUMNS FROM ccc_users LIKE 'profile_image'")->fetch();
    if (!$userProfileImageColumn) {
        $connection->exec("ALTER TABLE ccc_users ADD COLUMN profile_image VARCHAR(40) NOT NULL DEFAULT '' AFTER is_active");
    }
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS ccc_forms (
            id CHAR(12) NOT NULL PRIMARY KEY,
            title VARCHAR(120) NOT NULL,
            description VARCHAR(240) NOT NULL DEFAULT '',
            company_logo VARCHAR(40) NOT NULL DEFAULT '',
            questions_json LONGTEXT NOT NULL,
            verifier_start_question INT UNSIGNED NOT NULL DEFAULT 1,
            created_at VARCHAR(35) NOT NULL,
            INDEX idx_ccc_forms_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $formLogoColumn = $connection->query("SHOW COLUMNS FROM ccc_forms LIKE 'company_logo'")->fetch();
    if (!$formLogoColumn) {
        $connection->exec("ALTER TABLE ccc_forms ADD COLUMN company_logo VARCHAR(40) NOT NULL DEFAULT '' AFTER description");
    }
    $formVerifierStartColumn = $connection->query("SHOW COLUMNS FROM ccc_forms LIKE 'verifier_start_question'")->fetch();
    if (!$formVerifierStartColumn) {
        $connection->exec("ALTER TABLE ccc_forms ADD COLUMN verifier_start_question INT UNSIGNED NOT NULL DEFAULT 1 AFTER questions_json");
    }
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS ccc_submissions (
            id CHAR(16) NOT NULL PRIMARY KEY,
            form_id CHAR(12) NOT NULL,
            form_title VARCHAR(120) NOT NULL,
            verifier_start_question INT UNSIGNED NULL DEFAULT NULL,
            respondent VARCHAR(100) NOT NULL,
            respondent_email VARCHAR(254) NOT NULL,
            supervisor_username VARCHAR(30) NOT NULL,
            supervisor_name VARCHAR(100) NOT NULL,
            supervisor_email VARCHAR(254) NOT NULL,
            answers_json LONGTEXT NOT NULL,
            submitted_at VARCHAR(35) NOT NULL,
            approval_status VARCHAR(40) NOT NULL,
            approved_by VARCHAR(100) NOT NULL DEFAULT '',
            approved_at VARCHAR(35) NOT NULL DEFAULT '',
            email_status VARCHAR(160) NOT NULL DEFAULT '',
            approval_email_status VARCHAR(160) NOT NULL DEFAULT '',
            verifier_answers_json LONGTEXT NULL,
            INDEX idx_ccc_submissions_form_id (form_id),
            INDEX idx_ccc_submissions_supervisor_status (supervisor_username, approval_status),
            INDEX idx_ccc_submissions_submitted_at (submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $submissionVerifierStartColumn = $connection->query("SHOW COLUMNS FROM ccc_submissions LIKE 'verifier_start_question'")->fetch();
    if (!$submissionVerifierStartColumn) {
        $connection->exec("ALTER TABLE ccc_submissions ADD COLUMN verifier_start_question INT UNSIGNED NULL DEFAULT NULL AFTER form_title");
    }
    $approvalEmailStatusColumn = $connection->query("SHOW COLUMNS FROM ccc_submissions LIKE 'approval_email_status'")->fetch();
    if (!$approvalEmailStatusColumn) {
        $connection->exec("ALTER TABLE ccc_submissions ADD COLUMN approval_email_status VARCHAR(160) NOT NULL DEFAULT '' AFTER email_status");
    }
    $verifierAnswersColumn = $connection->query("SHOW COLUMNS FROM ccc_submissions LIKE 'verifier_answers_json'")->fetch();
    if (!$verifierAnswersColumn) {
        $connection->exec("ALTER TABLE ccc_submissions ADD COLUMN verifier_answers_json LONGTEXT NULL AFTER answers_json");
    }
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS ccc_app_meta (
            meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
            meta_value VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS ccc_sop_files (
            id CHAR(32) NOT NULL PRIMARY KEY,
            title VARCHAR(120) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(40) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            thumbnail_name VARCHAR(40) NOT NULL DEFAULT '',
            created_at VARCHAR(35) NOT NULL,
            updated_at VARCHAR(35) NOT NULL,
            INDEX idx_ccc_sop_files_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function fetchCccData(PDO $connection): array
{
    $data = ['users' => [], 'forms' => [], 'submissions' => []];

    foreach ($connection->query('SELECT username, name, role, email, password_hash, is_active, profile_image FROM ccc_users') as $row) {
        $data['users'][$row['username']] = [
            'username' => $row['username'],
            'name' => $row['name'],
            'role' => $row['role'],
            'email' => $row['email'],
            'password' => $row['password_hash'],
            'isActive' => (bool) $row['is_active'],
            'profileImage' => $row['profile_image'],
        ];
    }

    foreach ($connection->query('SELECT id, title, description, company_logo, questions_json, verifier_start_question, created_at FROM ccc_forms ORDER BY created_at, id') as $row) {
        $questions = json_decode($row['questions_json'], true);
        if (!is_array($questions)) {
            throw new RuntimeException('Struktur pertanyaan di database CCC tidak valid.');
        }
        $data['forms'][] = [
            'id' => $row['id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'logo' => $row['company_logo'],
            'questions' => $questions,
            'verifierStartQuestion' => (int) $row['verifier_start_question'],
            'createdAt' => $row['created_at'],
        ];
    }
    $formVerifierStartQuestions = [];
    foreach ($data['forms'] as $form) {
        $formVerifierStartQuestions[$form['id']] = $form['verifierStartQuestion'];
    }

    foreach ($connection->query(
        'SELECT id, form_id, form_title, verifier_start_question, respondent, respondent_email, supervisor_username, supervisor_name, supervisor_email, answers_json, verifier_answers_json, submitted_at, approval_status, approved_by, approved_at, email_status, approval_email_status FROM ccc_submissions ORDER BY submitted_at, id'
    ) as $row) {
        $answers = json_decode($row['answers_json'], true);
        if (!is_array($answers)) {
            throw new RuntimeException('Struktur jawaban checklist di database CCC tidak valid.');
        }
        $verifierAnswers = $row['verifier_answers_json'] !== null
            ? json_decode($row['verifier_answers_json'], true)
            : [];
        if (!is_array($verifierAnswers)) {
            throw new RuntimeException('Struktur jawaban verifikator di database CCC tidak valid.');
        }
        $data['submissions'][] = [
            'id' => $row['id'],
            'formId' => $row['form_id'],
            'formTitle' => $row['form_title'],
            'verifierStartQuestion' => $row['verifier_start_question'] === null
                ? ($formVerifierStartQuestions[$row['form_id']] ?? 1)
                : (int) $row['verifier_start_question'],
            'respondent' => $row['respondent'],
            'respondentEmail' => $row['respondent_email'],
            'supervisor' => $row['supervisor_username'],
            'supervisorName' => $row['supervisor_name'],
            'supervisorEmail' => $row['supervisor_email'],
            'answers' => $answers,
            'verifierAnswers' => $verifierAnswers,
            'submittedAt' => $row['submitted_at'],
            'status' => $row['approval_status'],
            'approvedBy' => $row['approved_by'],
            'approvedAt' => $row['approved_at'],
            'emailStatus' => $row['email_status'],
            'approvalEmailStatus' => $row['approval_email_status'],
        ];
    }

    return $data;
}

function persistCccData(PDO $connection, array $data, array $previousData): void
{
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    $userStatement = $connection->prepare(
        'INSERT INTO ccc_users (username, name, role, email, password_hash, is_active, profile_image)
         VALUES (:username, :name, :role, :email, :password_hash, :is_active, :profile_image)
         ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role), email = VALUES(email), password_hash = VALUES(password_hash), is_active = VALUES(is_active), profile_image = VALUES(profile_image)'
    );
    $deleteUserStatement = $connection->prepare('DELETE FROM ccc_users WHERE username = :username');
    $formStatement = $connection->prepare(
        'INSERT INTO ccc_forms (id, title, description, company_logo, questions_json, verifier_start_question, created_at)
         VALUES (:id, :title, :description, :company_logo, :questions_json, :verifier_start_question, :created_at)
         ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), company_logo = VALUES(company_logo), questions_json = VALUES(questions_json), verifier_start_question = VALUES(verifier_start_question), created_at = VALUES(created_at)'
    );
    $deleteFormStatement = $connection->prepare('DELETE FROM ccc_forms WHERE id = :id');
    $submissionStatement = $connection->prepare(
        'INSERT INTO ccc_submissions (id, form_id, form_title, verifier_start_question, respondent, respondent_email, supervisor_username, supervisor_name, supervisor_email, answers_json, verifier_answers_json, submitted_at, approval_status, approved_by, approved_at, email_status, approval_email_status)
         VALUES (:id, :form_id, :form_title, :verifier_start_question, :respondent, :respondent_email, :supervisor_username, :supervisor_name, :supervisor_email, :answers_json, :verifier_answers_json, :submitted_at, :approval_status, :approved_by, :approved_at, :email_status, :approval_email_status)
         ON DUPLICATE KEY UPDATE form_id = VALUES(form_id), form_title = VALUES(form_title), verifier_start_question = VALUES(verifier_start_question), respondent = VALUES(respondent), respondent_email = VALUES(respondent_email), supervisor_username = VALUES(supervisor_username), supervisor_name = VALUES(supervisor_name), supervisor_email = VALUES(supervisor_email), answers_json = VALUES(answers_json), verifier_answers_json = VALUES(verifier_answers_json), submitted_at = VALUES(submitted_at), approval_status = VALUES(approval_status), approved_by = VALUES(approved_by), approved_at = VALUES(approved_at), email_status = VALUES(email_status), approval_email_status = VALUES(approval_email_status)'
    );

    $connection->beginTransaction();
    try {
        foreach ($previousData['users'] ?? [] as $username => $_user) {
            if (!array_key_exists($username, $data['users'])) {
                $deleteUserStatement->execute(['username' => $username]);
            }
        }
        foreach ($data['users'] as $username => $user) {
            if (($previousData['users'][$username] ?? null) === $user) {
                continue;
            }
            $userStatement->execute([
                'username' => $username,
                'name' => $user['name'],
                'role' => $user['role'],
                'email' => $user['email'],
                'password_hash' => $user['password'],
                'is_active' => isset($user['isActive']) ? (int) (bool) $user['isActive'] : 1,
                'profile_image' => $user['profileImage'] ?? '',
            ]);
        }

        $previousForms = [];
        foreach ($previousData['forms'] ?? [] as $form) {
            $previousForms[$form['id']] = $form;
        }
        $currentFormIds = array_fill_keys(array_column($data['forms'], 'id'), true);
        foreach ($previousForms as $formId => $_form) {
            if (!isset($currentFormIds[$formId])) {
                $deleteFormStatement->execute(['id' => $formId]);
            }
        }
        foreach ($data['forms'] as $form) {
            if (($previousForms[$form['id']] ?? null) === $form) {
                continue;
            }
            $formStatement->execute([
                'id' => $form['id'],
                'title' => $form['title'],
                'description' => $form['description'],
                'company_logo' => $form['logo'] ?? '',
                'questions_json' => json_encode($form['questions'], $jsonFlags),
                'verifier_start_question' => $form['verifierStartQuestion'] ?? 1,
                'created_at' => $form['createdAt'],
            ]);
        }

        $previousSubmissions = [];
        foreach ($previousData['submissions'] ?? [] as $submission) {
            $previousSubmissions[$submission['id']] = $submission;
        }
        foreach ($data['submissions'] as $submission) {
            if (($previousSubmissions[$submission['id']] ?? null) === $submission) {
                continue;
            }
            $submissionStatement->execute([
                'id' => $submission['id'],
                'form_id' => $submission['formId'],
                'form_title' => $submission['formTitle'],
                'verifier_start_question' => $submission['verifierStartQuestion'] ?? 1,
                'respondent' => $submission['respondent'],
                'respondent_email' => $submission['respondentEmail'],
                'supervisor_username' => $submission['supervisor'],
                'supervisor_name' => $submission['supervisorName'],
                'supervisor_email' => $submission['supervisorEmail'],
                'answers_json' => json_encode($submission['answers'], $jsonFlags),
                'verifier_answers_json' => json_encode($submission['verifierAnswers'] ?? [], $jsonFlags),
                'submitted_at' => $submission['submittedAt'],
                'approval_status' => $submission['status'],
                'approved_by' => $submission['approvedBy'],
                'approved_at' => $submission['approvedAt'],
                'email_status' => $submission['emailStatus'],
                'approval_email_status' => $submission['approvalEmailStatus'] ?? '',
            ]);
        }
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw new RuntimeException('Data CCC gagal disimpan ke database.', 0, $exception);
    }
}

function hasCccMeta(PDO $connection, string $key): bool
{
    $statement = $connection->prepare('SELECT 1 FROM ccc_app_meta WHERE meta_key = :meta_key');
    $statement->execute(['meta_key' => $key]);
    return $statement->fetchColumn() !== false;
}

function setCccMeta(PDO $connection, string $key, string $value): void
{
    $statement = $connection->prepare(
        'INSERT INTO ccc_app_meta (meta_key, meta_value) VALUES (:meta_key, :meta_value)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)'
    );
    $statement->execute(['meta_key' => $key, 'meta_value' => $value]);
}
