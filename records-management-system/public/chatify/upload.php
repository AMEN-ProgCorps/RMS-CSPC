<?php
session_start();
require_once __DIR__ . '/db.php';
ensureStorageSchema();
migrateLegacyDataFromJson();
header('Content-Type: application/json');

// Validate session from session.json (consistent with rest of app)
function isValidSession() {
    // 1. PHP session still alive — no file I/O needed
    if (isset($_SESSION['ghostlan_admin']) && $_SESSION['ghostlan_admin'] === true) {
        return true;
    }

    // 2. PHP session gone — check remember_token cookie or session_id against session.json
    $session_id     = session_id();
    $remember_token = isset($_COOKIE['remember_token']) ? $_COOKIE['remember_token'] : null;
    $session = findSessionRecord($session_id, $remember_token);

    if ($session) {
        $_SESSION['ghostlan_admin'] = true;
        $_SESSION['name']           = $session['name']     ?? '';
        $_SESSION['username']       = $session['username'] ?? '';
        return true;
    }
    return false;
}

if (!isValidSession()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Release session lock immediately — upload.php can take a long time for large files.
// Without this, send.php and load.php are queued behind this session lock the entire
// time the upload is running, causing chat send/poll delays.
session_write_close();

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

// Load existing chat logs from the database
$chatLogs = loadChatMessagesFromDb();

$response = ['success' => false, 'uploaded' => [], 'errors' => []];

if (!empty($_FILES['files']['name'][0])) {
    $total = count($_FILES['files']['name']);
    $uploader = isset($_POST['uploader']) && trim($_POST['uploader']) !== '' 
                ? trim($_POST['uploader']) 
                : (isset($_SESSION['name']) ? $_SESSION['name'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown'));

    for ($i = 0; $i < $total; $i++) {
        $tmpName = $_FILES['files']['tmp_name'][$i];
        $name = basename($_FILES['files']['name'][$i]);
        $error = $_FILES['files']['error'][$i];

        if ($error === UPLOAD_ERR_OK && is_uploaded_file($tmpName)) {
            $target = $uploadDir . $name;

            // Prevent overwrite
            if (file_exists($target)) {
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $base = pathinfo($name, PATHINFO_FILENAME);
                $name = $base . '_' . time() . ($ext ? ".{$ext}" : '');
                $target = $uploadDir . $name;
            }

            if (move_uploaded_file($tmpName, $target)) {
                $response['uploaded'][] = $name;

                // Log upload as a chat message in the database
                $dt = new DateTime();
                $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                $newMessage = [
                    'id' => 'msg_' . uniqid(),
                    'sender' => $uploader,
                    'message' => $name,
                    'timestamp' => $dt->format('Y-m-d H:i:s.u'),
                    'type' => 'upload'
                ];
                saveChatMessageToDb($newMessage);
                $chatLogs[] = $newMessage;

            } else {
                $response['errors'][] = "Failed to move $name.";
            }
        } else {
            $response['errors'][] = "Error uploading $name (error code $error).";
        }
    }

    // Save chat logs (uploads are recorded there)
    // Trim to 100 messages max — remove oldest first, delete upload files too
    if (count($chatLogs) > 100) {
        $trimmed = array_slice($chatLogs, 0, count($chatLogs) - 100);
        foreach ($trimmed as $old) {
            if (($old['type'] ?? '') === 'upload') {
                $filePath = $uploadDir . $old['message'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
        }
        $chatLogs = array_slice($chatLogs, -100);
    }
    trimChatMessagesToDb(100, $uploadDir);

    $response['success'] = count($response['uploaded']) > 0;
} else {
    $response['errors'][] = 'No files uploaded.';
}

echo json_encode($response);
?>