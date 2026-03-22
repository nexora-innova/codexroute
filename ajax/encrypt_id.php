<?php
$AJAX_INCLUDE = 1;

if (!defined('GLPI_KEEP_CSRF_TOKEN')) {
    define('GLPI_KEEP_CSRF_TOKEN', true);
}

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

include GLPI_ROOT . '/inc/includes.php';

while (ob_get_level()) {
    ob_end_clean();
}

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('codexroute') || !$plugin->isActivated('codexroute')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => __('Plugin is not installed or activated.', 'codexroute')]);
    exit;
}

$_config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
$_config_file = $_config_dir . '/codexroute/encryption_config.php';
if (file_exists($_config_file)) {
    include_once($_config_file);
}

if (!defined('CODEXROUTE_ENCRYPTION_ENABLED') || !CODEXROUTE_ENCRYPTION_ENABLED) {
    echo json_encode([
        'success'            => false,
        'encryption_enabled' => false,
        'message'            => __('Encryption is disabled in the plugin configuration.', 'codexroute'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_POST['id']) || $_POST['id'] === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => __('Missing ID parameter.', 'codexroute')]);
    exit;
}

$id = $_POST['id'];
$id_str = (string)$id;
$id_length = strlen($id_str);

if ($id_length > 100) {
    echo json_encode([
        'success'          => true,
        'encrypted_id'     => $id_str,
        'original_id'      => $id_str,
        'already_encrypted'=> true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_numeric($id)) {
    if ($id_length > 50 && preg_match('/^[A-Za-z0-9_-]+$/', $id_str)) {
        echo json_encode([
            'success'          => true,
            'encrypted_id'     => $id_str,
            'original_id'      => $id_str,
            'already_encrypted'=> true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($id_length >= 16 && $id_length <= 100 && preg_match('/^[A-Za-z0-9_-]+$/', $id_str)) {
        echo json_encode([
            'success'          => true,
            'encrypted_id'     => $id_str,
            'original_id'      => $id_str,
            'already_encrypted'=> true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => __('Invalid ID format.', 'codexroute')]);
    exit;
}

$id = (int)$id;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => __('ID must be greater than zero.', 'codexroute')]);
    exit;
}

if (!class_exists('GlpiPlugin\Codexroute\IDEncryption')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => __('IDEncryption class is not available.', 'codexroute')]);
    exit;
}

try {
    $encrypted_id = \GlpiPlugin\Codexroute\IDEncryption::encrypt($id);

    if (empty($encrypted_id)) {
        throw new Exception('Encryption result is empty');
    }
    if (strlen($encrypted_id) > 100) {
        throw new Exception('Encrypted ID is too long: ' . strlen($encrypted_id) . ' chars');
    }
    if ($encrypted_id === (string)$id) {
        throw new Exception('Encryption did not modify the ID');
    }

    echo json_encode([
        'success'      => true,
        'encrypted_id' => $encrypted_id,
        'original_id'  => $id,
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    error_log(sprintf(
        '[CodexRoute] encrypt_id.php ERROR: %s (ID: %s, User: %s)',
        $e->getMessage(),
        substr((string)$id, 0, 50),
        Session::getLoginUserID() ?? 'unknown'
    ));
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => sprintf(__('Encryption error: %s', 'codexroute'), $e->getMessage()),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}