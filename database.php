<?php
declare(strict_types=1);

/**
 * Create a PDO connection to the CCC MySQL database.
 *
 * XAMPP defaults: host 127.0.0.1, port 3306, user root, empty password.
 * Override these values with DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD.
 */
function getDatabaseConnection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('DB_HOST') ?: 'srv1865.hstgr.io';
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
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (PDOException $exception) {
        error_log('CCC database connection failed: ' . $exception->getMessage());
        throw new RuntimeException(
            'Koneksi database CCC gagal: ' . $exception->getMessage()
            . ' Pastikan MySQL XAMPP sudah berstatus Running / "ready for connections", database CCC tersedia, dan konfigurasi DB_HOST/DB_PORT/DB_USER/DB_PASSWORD sesuai.',
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
            INDEX idx_ccc_users_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS ccc_forms (
            id CHAR(12) NOT NULL PRIMARY KEY,
            title VARCHAR(120) NOT NULL,
            description VARCHAR(240) NOT NULL DEFAULT '',
            company_logo VARCHAR(40) NOT NULL DEFAULT '',
            questions_json LONGTEXT NOT NULL,
            created_at VARCHAR(35) NOT NULL,
            INDEX idx_ccc_forms_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $formLogoColumn = $connection->query("SHOW COLUMNS FROM ccc_forms LIKE 'company_logo'")->fetch();
    if (!$formLogoColumn) {
        $connection->exec("ALTER TABLE ccc_forms ADD COLUMN company_logo VARCHAR(40) NOT NULL DEFAULT '' AFTER description");
    }
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS ccc_submissions (
            id CHAR(16) NOT NULL PRIMARY KEY,
            form_id CHAR(12) NOT NULL,
            form_title VARCHAR(120) NOT NULL,
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
            INDEX idx_ccc_submissions_form_id (form_id),
            INDEX idx_ccc_submissions_supervisor_status (supervisor_username, approval_status),
            INDEX idx_ccc_submissions_submitted_at (submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS ccc_app_meta (
            meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
            meta_value VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function fetchCccData(PDO $connection): array
{
    $data = ['users' => [], 'forms' => [], 'submissions' => []];

    foreach ($connection->query('SELECT username, name, role, email, password_hash FROM ccc_users') as $row) {
        $data['users'][$row['username']] = [
            'username' => $row['username'],
            'name' => $row['name'],
            'role' => $row['role'],
            'email' => $row['email'],
            'password' => $row['password_hash'],
        ];
    }

    foreach ($connection->query('SELECT id, title, description, company_logo, questions_json, created_at FROM ccc_forms ORDER BY created_at, id') as $row) {
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
            'createdAt' => $row['created_at'],
        ];
    }

    foreach ($connection->query(
        'SELECT id, form_id, form_title, respondent, respondent_email, supervisor_username, supervisor_name, supervisor_email, answers_json, submitted_at, approval_status, approved_by, approved_at, email_status FROM ccc_submissions ORDER BY submitted_at, id'
    ) as $row) {
        $answers = json_decode($row['answers_json'], true);
        if (!is_array($answers)) {
            throw new RuntimeException('Struktur jawaban checklist di database CCC tidak valid.');
        }
        $data['submissions'][] = [
            'id' => $row['id'],
            'formId' => $row['form_id'],
            'formTitle' => $row['form_title'],
            'respondent' => $row['respondent'],
            'respondentEmail' => $row['respondent_email'],
            'supervisor' => $row['supervisor_username'],
            'supervisorName' => $row['supervisor_name'],
            'supervisorEmail' => $row['supervisor_email'],
            'answers' => $answers,
            'submittedAt' => $row['submitted_at'],
            'status' => $row['approval_status'],
            'approvedBy' => $row['approved_by'],
            'approvedAt' => $row['approved_at'],
            'emailStatus' => $row['email_status'],
        ];
    }

    return $data;
}

function persistCccData(PDO $connection, array $data, array $previousData): void
{
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    $userStatement = $connection->prepare(
        'INSERT INTO ccc_users (username, name, role, email, password_hash)
         VALUES (:username, :name, :role, :email, :password_hash)
         ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role), email = VALUES(email), password_hash = VALUES(password_hash)'
    );
    $deleteUserStatement = $connection->prepare('DELETE FROM ccc_users WHERE username = :username');
    $formStatement = $connection->prepare(
        'INSERT INTO ccc_forms (id, title, description, company_logo, questions_json, created_at)
         VALUES (:id, :title, :description, :company_logo, :questions_json, :created_at)
         ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), company_logo = VALUES(company_logo), questions_json = VALUES(questions_json), created_at = VALUES(created_at)'
    );
    $deleteFormStatement = $connection->prepare('DELETE FROM ccc_forms WHERE id = :id');
    $submissionStatement = $connection->prepare(
        'INSERT INTO ccc_submissions (id, form_id, form_title, respondent, respondent_email, supervisor_username, supervisor_name, supervisor_email, answers_json, submitted_at, approval_status, approved_by, approved_at, email_status)
         VALUES (:id, :form_id, :form_title, :respondent, :respondent_email, :supervisor_username, :supervisor_name, :supervisor_email, :answers_json, :submitted_at, :approval_status, :approved_by, :approved_at, :email_status)
         ON DUPLICATE KEY UPDATE form_id = VALUES(form_id), form_title = VALUES(form_title), respondent = VALUES(respondent), respondent_email = VALUES(respondent_email), supervisor_username = VALUES(supervisor_username), supervisor_name = VALUES(supervisor_name), supervisor_email = VALUES(supervisor_email), answers_json = VALUES(answers_json), submitted_at = VALUES(submitted_at), approval_status = VALUES(approval_status), approved_by = VALUES(approved_by), approved_at = VALUES(approved_at), email_status = VALUES(email_status)'
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
                'respondent' => $submission['respondent'],
                'respondent_email' => $submission['respondentEmail'],
                'supervisor_username' => $submission['supervisor'],
                'supervisor_name' => $submission['supervisorName'],
                'supervisor_email' => $submission['supervisorEmail'],
                'answers_json' => json_encode($submission['answers'], $jsonFlags),
                'submitted_at' => $submission['submittedAt'],
                'approval_status' => $submission['status'],
                'approved_by' => $submission['approvedBy'],
                'approved_at' => $submission['approvedAt'],
                'email_status' => $submission['emailStatus'],
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
