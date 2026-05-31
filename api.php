<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once 'config.php';
require_once 'functions.php';

session_start();

// Проверка статуса авторизации (для главной страницы)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'status') {
    session_start();
    $response = [
        'authenticated' => isset($_SESSION['user_id']),
        'login' => $_SESSION['login'] ?? null,
    ];
    echo json_encode($response);
    exit;
}

// ------------------------------------------------
// Обработка обычной отправки формы (фолбек без JS)
// ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' 
    && (empty($_SERVER['CONTENT_TYPE']) || strpos($_SERVER['CONTENT_TYPE'], 'application/x-www-form-urlencoded') !== false || strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false)) {
    
    $data = [
        'name'      => trim($_POST['name'] ?? ''),
        'phone'     => trim($_POST['phone'] ?? ''),
        'email'     => trim($_POST['email'] ?? ''),
        'birth_date'=> trim($_POST['birth_date'] ?? ''),
        'gender'    => $_POST['gender'] ?? '',
        'languages' => $_POST['languages'] ?? [],  // из select multiple
        'biography' => trim($_POST['biography'] ?? ''),
        'contract'  => isset($_POST['contract']) ? 1 : 0
    ];

    $errors = validateFormData($data['name'], $data['phone'], $data['email'], $data['birth_date'], $data['gender'], $data['languages'], $data['biography'], $data['contract']);

    if (!empty($errors)) {
        // Выводим HTML с ошибками
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(422);
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Ошибки в форме</title></head><body>';
        echo '<h1>Пожалуйста, исправьте ошибки</h1><ul>';
        foreach ($errors as $field => $msg) {
            echo '<li>' . htmlspecialchars($msg) . '</li>';
        }
        echo '</ul><p><a href="javascript:history.back()">Вернуться к форме</a></p>';
        echo '</body></html>';
        exit;
    }

    try {
        $creds = saveNewApplication($data['name'], $data['phone'], $data['email'], $data['birth_date'], $data['gender'], $data['languages'], $data['biography'], $data['contract']);
        $profileUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://')
                    . $_SERVER['HTTP_HOST']
                    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')
                    . '/profile.php';

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Данные сохранены</title></head><body>';
        echo '<h1>Заявка успешно отправлена</h1>';
        echo '<p><strong>Логин:</strong> ' . htmlspecialchars($creds['login']) . '</p>';
        echo '<p><strong>Пароль:</strong> ' . htmlspecialchars($creds['pass']) . '</p>';
        echo '<p>Сохраните их для редактирования анкеты.</p>';
        echo '<p><a href="' . htmlspecialchars($profileUrl) . '">Перейти к редактированию анкеты</a></p>';
        echo '</body></html>';
        exit;
    } catch (PDOException $e) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Ошибка</title></head><body>';
        echo '<h1>Ошибка сервера</h1><p>Не удалось сохранить данные. Попробуйте позже.</p>';
        echo '</body></html>';
        exit;
    }
}

$input = file_get_contents('php://input');
$data = null;
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $data = json_decode($input, true);
} elseif (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'xml') !== false) {
    $xml = simplexml_load_string($input);
    $data = $xml ? json_decode(json_encode($xml), true) : null;
}
if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request format (JSON or XML expected)']);
    exit;
}

// Проверяем, не запрос ли на обновление от авторизованного пользователя
$isUpdate = ($data['action'] ?? '') === 'update';
if ($isUpdate) {
    // Пользователь должен быть авторизован
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Требуется авторизация']);
        exit;
    }
    // Можно также проверить, что в данных передан user_id, совпадающий с сессией,
    // но для простоты берём идентификатор из сессии
    $userId = $_SESSION['user_id'];
    
    // Валидация (такая же)
    $errors = validateFormData(
        trim($data['name'] ?? $data['fio'] ?? ''),
        trim($data['phone'] ?? $data['tel'] ?? ''),
        trim($data['email'] ?? ''),
        trim($data['birth_date'] ?? ''),
        $data['gender'] ?? '',
        $data['languages'] ?? [],
        trim($data['message'] ?? $data['comment'] ?? $data['biography'] ?? ''),
        isset($data['contract']) ? (int)$data['contract'] : 0
    );
    
    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['errors' => $errors]);
        exit;
    }
    
    try {
        updateApplication(
            $userId,
            trim($data['name'] ?? $data['fio'] ?? ''),
            trim($data['phone'] ?? $data['tel'] ?? ''),
            trim($data['email'] ?? ''),
            trim($data['birth_date'] ?? ''),
            $data['gender'] ?? '',
            $data['languages'] ?? [],
            trim($data['message'] ?? $data['comment'] ?? $data['biography'] ?? ''),
            isset($data['contract']) ? (int)$data['contract'] : 0
        );
        echo json_encode(['success' => true, 'message' => 'Данные обновлены']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка БД']);
    }
    exit;
}

$name = trim($data['name'] ?? $data['fio'] ?? '');
$phone = trim($data['phone'] ?? $data['tel'] ?? '');
$email = trim($data['email'] ?? '');
$birth_date = trim($data['birth_date'] ?? '');
$gender = $data['gender'] ?? '';
$languages = $data['languages'] ?? [];
$biography = trim($data['message'] ?? $data['comment'] ?? $data['biography'] ?? '');
$contract = isset($data['contract']) ? (int)$data['contract'] : 0;

// Валидация обязательных полей
if (!$name || !$phone || !$email || !$birth_date || !$gender || empty($languages)) {
    http_response_code(400);
    echo json_encode(['error' => 'Обязательные поля: name, phone, email, birth_date, gender, languages']);
    exit;
}

// Проверка корректности данных
$errors = validateFormData($name, $phone, $email, $birth_date, $gender, $languages, $biography, $contract);
if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['errors' => $errors]);
    exit;
}

try {
    $creds = saveNewApplication($name, $phone, $email, $birth_date, $gender, $languages, $biography, $contract);
    $profileUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://')
                . $_SERVER['HTTP_HOST']
                . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')
                . '/profile.php';
    echo json_encode([
        'success' => true,
        'login' => $creds['login'],
        'password' => $creds['pass'],
        'profile_url' => $profileUrl
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка БД']);
}