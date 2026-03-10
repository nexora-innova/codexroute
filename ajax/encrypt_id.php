<?php
$AJAX_INCLUDE = 1;

if (!defined('GLPI_KEEP_CSRF_TOKEN')) {
    define('GLPI_KEEP_CSRF_TOKEN', true);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

define('GLPI_ROOT', '../../..');
include(GLPI_ROOT . '/inc/includes.php');

while (ob_get_level()) {
    ob_end_clean();
}

Session::checkLoginUser();

$plugin = new Plugin();
if (!$plugin->isInstalled('codexroute') || !$plugin->isActivated('codexroute')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Plugin no está activado']);
    exit;
}

if (!isset($_POST['id']) || $_POST['id'] === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID faltante']);
    exit;
}

$id = $_POST['id'];
$id_str = (string)$id;
$id_length = strlen($id_str);

// VALIDACIÓN 1: Rechazar IDs demasiado largos (probablemente ya encriptados)
if ($id_length > 100) {
    error_log(sprintf(
        '[CodexRoute] encrypt_id.php: Rejecting too long ID (%d chars)',
        $id_length
    ));
    echo json_encode([
        'success' => true,
        'encrypted_id' => $id_str,
        'original_id' => $id_str,
        'already_encrypted' => true,
        'warning' => 'ID too long, assuming encrypted'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_numeric($id)) {
    if ($id_length > 50 && preg_match('/^[A-Za-z0-9_-]+$/', $id_str)) {
        echo json_encode([
            'success' => true,
            'encrypted_id' => $id_str,
            'original_id' => $id_str,
            'already_encrypted' => true
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($id_length >= 16 && $id_length <= 100 && preg_match('/^[A-Za-z0-9_-]+$/', $id_str)) {
        echo json_encode([
            'success' => true,
            'encrypted_id' => $id_str,
            'original_id' => $id_str,
            'already_encrypted' => true
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID con formato inválido']);
    exit;
}

// VALIDACIÓN 3: ID numérico válido
$id = (int)$id;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID debe ser mayor a 0']);
    exit;
}

// Proceder a encriptar
if (!class_exists('GlpiPlugin\Codexroute\IDEncryption')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Clase IDEncryption no disponible']);
    exit;
}

try {
    $encrypted_id = \GlpiPlugin\Codexroute\IDEncryption::encrypt($id);
    
    // VALIDACIÓN 4: Verificar que el resultado sea válido
    if (empty($encrypted_id)) {
        throw new Exception('Resultado de encriptación vacío');
    }
    
    if (strlen($encrypted_id) > 100) {
        throw new Exception('Resultado de encriptación demasiado largo: ' . strlen($encrypted_id) . ' chars');
    }
    
    // Si el resultado es igual al input, algo salió mal
    if ($encrypted_id === (string)$id) {
        throw new Exception('La encriptación no modificó el ID');
    }
    
    $response = json_encode([
        'success' => true,
        'encrypted_id' => $encrypted_id,
        'original_id' => $id
    ], JSON_UNESCAPED_UNICODE);
    
    echo $response;
    exit;
    
} catch (Exception $e) {
    error_log(sprintf(
        '[CodexRoute] encrypt_id.php ERROR: %s (ID: %s, User: %s)',
        $e->getMessage(),
        substr((string)$id, 0, 50),
        Session::getLoginUserID() ?? 'unknown'
    ));
    
    http_response_code(500);
    $error_response = json_encode([
        'success' => false,
        'message' => 'Error al encriptar: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
    echo $error_response;
    exit;
}