<?php

namespace GlpiPlugin\Codexroute;

use GLPIKey;
use Session;

class IDEncryption
{

    private static $key = null;
    private static $encryption_method = null;
    private static $cache = [];
    private static $cache_timestamps = [];
    private static $rate_limit_attempts = [];
    private static $blocked_ips = [];
    private static $failed_attempts = [];
    private static $suspicious_patterns = [];

    private const CACHE_MAX_SIZE = 100;
    private const CACHE_TTL = 300;
    private const SIMPLE_MAX_RANGE = 1000;
    private const SIMPLE_TIMEOUT = 1.0;
    private const RATE_LIMIT_MAX_ATTEMPTS = 10;
    private const RATE_LIMIT_WINDOW = 60;
    private const BLOCK_IP_AFTER_FAILED_ATTEMPTS = 20;
    private const BLOCK_IP_DURATION = 3600;
    private const MIN_ENCRYPTED_LENGTH = 16;
    private const MAX_ENCRYPTED_LENGTH = 200;
    private const NORMALIZED_RESPONSE_TIME = 0.05;
    private const SECURITY_LOG_ENABLED = true;

    private static function detectEncryptionMethod(): string
    {
        if (self::$encryption_method !== null) {
            return self::$encryption_method;
        }

        if (extension_loaded('sodium') && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            self::$encryption_method = 'sodium';
        } elseif (extension_loaded('openssl') && function_exists('openssl_encrypt')) {
            self::$encryption_method = 'openssl';
        } else {
            self::$encryption_method = 'simple';
        }

        return self::$encryption_method;
    }

    private static function getKey(): string
    {
        if (self::$key === null) {
            $glpikey = new GLPIKey();
            $base_key = $glpikey->get();

            if ($base_key === null) {
                $base_key = self::generateFallbackKey();
            }

            $method = self::detectEncryptionMethod();
            $glpi_root = defined('GLPI_ROOT') ? GLPI_ROOT : __DIR__ . '/../../../..';
            if ($method === 'sodium') {
                self::$key = $base_key;
            } else {
                self::$key = hash('sha256', $base_key . '|CODEXROUTE_SALT|' . $glpi_root, true);
            }
        }

        return self::$key;
    }

    private static function generateFallbackKey(): string
    {
        $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
        $key_file = $config_dir . '/codexroute/encryption.key';

        if (file_exists($key_file)) {
            return file_get_contents($key_file);
        }

        if (extension_loaded('sodium')) {
            $key = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        } else {
            $key = random_bytes(32);
        }

        $key_dir = dirname($key_file);
        if (!is_dir($key_dir)) {
            @mkdir($key_dir, 0755, true);
        }

        @file_put_contents($key_file, $key);
        @chmod($key_file, 0600);

        return $key;
    }

    private static function encryptWithSodium(string $id_str, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $encrypted = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $id_str,
            $nonce,
            $nonce,
            $key
        );
        return base64_encode($nonce . $encrypted);
    }

    private static function encryptWithOpenSSL(string $id_str, string $key): string
    {
        $cipher = 'AES-256-CBC';
        $iv_length = openssl_cipher_iv_length($cipher);
        if ($iv_length === false) {
            return $id_str;
        }

        $iv = openssl_random_pseudo_bytes($iv_length);
        $encrypted = openssl_encrypt($id_str, $cipher, $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            return $id_str;
        }

        return base64_encode($iv . $encrypted);
    }

    private static function encryptWithSimple(string $id_str, string $key): string
    {
        $id_int = (int)$id_str;
        $key_hash = hash('sha256', $key . '|' . $id_int, true);

        $mask1 = unpack('N', substr($key_hash, 0, 4))[1] ?? 0;
        $mask2 = unpack('N', substr($key_hash, 4, 4))[1] ?? 0;
        $mask3 = unpack('N', substr($key_hash, 8, 4))[1] ?? 0;

        $encrypted = $id_int;
        $encrypted = $encrypted ^ $mask1;
        $encrypted = $encrypted ^ $mask2;
        $encrypted = $encrypted ^ $mask3;

        $checksum = unpack('N', substr($key_hash, 12, 4))[1] ?? 0;

        return base64_encode(pack('N', $encrypted) . pack('N', $checksum) . substr($key_hash, 16, 8));
    }

    public static function encrypt($id): string
    {
        // Si es vacío o 0, devolver tal cual
        if (empty($id) || $id == 0) {
            return (string)$id;
        }

        $id_str = (string)$id;
        $id_length = strlen($id_str);

        // PRIMERA VERIFICACIÓN: Si es muy largo, probablemente ya está encriptado
        if ($id_length > 100) {
            if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                error_log(sprintf(
                    '[CodexRoute] WARN: ID too long (%d chars), assuming already encrypted',
                    $id_length
                ));
            }
            return $id_str;
        }

        if (!is_numeric($id_str) && $id_length > 50 && preg_match('/^[A-Za-z0-9_-]+$/', $id_str)) {
            if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                error_log(sprintf(
                    '[CodexRoute] INFO: ID already encrypted (length: %d), skipping to avoid double encryption',
                    $id_length
                ));
            }
            return $id_str;
        }

        if (!is_numeric($id_str) && $id_length >= 16 && $id_length <= 100 && preg_match('/^[A-Za-z0-9_-]+$/', $id_str)) {
            if (defined('CODEXROUTE_LOG_SECURITY') && CODEXROUTE_LOG_SECURITY) {
                error_log(sprintf(
                    '[CodexRoute] INFO: ID already encrypted (length: %d), skipping',
                    $id_length
                ));
            }
            return $id_str;
        }

        // TERCERA VERIFICACIÓN: Revisar caché antes de encriptar
        $cache_key = 'enc_' . $id;
        $cached = self::getFromCache($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        // Si llegamos aquí, el ID es numérico o no está encriptado → proceder a encriptar
        $key = self::getKey();
        $method = self::detectEncryptionMethod();

        $encrypted = '';
        switch ($method) {
            case 'sodium':
                $encrypted = self::encryptWithSodium($id_str, $key);
                break;
            case 'openssl':
                $encrypted = self::encryptWithOpenSSL($id_str, $key);
                break;
            case 'simple':
            default:
                $encrypted = self::encryptWithSimple($id_str, $key);
                break;
        }

        $result = rtrim(strtr($encrypted, '+/', '-_'), '=');

        // CUARTA VERIFICACIÓN: Validar que el resultado no sea demasiado largo
        if (strlen($result) > 100) {
            error_log(sprintf(
                '[CodexRoute] ERROR: Encrypted ID too long (%d chars), original: %s',
                strlen($result),
                substr($id_str, 0, 50)
            ));
            // En caso de error, devolver el ID original si es numérico
            if (is_numeric($id_str)) {
                return $id_str;
            }
        }

        self::addToCache($cache_key, $result);

        return $result;
    }

    private static function decryptWithSodium(string $encrypted_data, string $key)
    {
        $start_time = microtime(true);

        $nonce_length = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($encrypted_data) < $nonce_length) {
            self::normalizeResponseTime($start_time);
            return false;
        }

        $nonce = substr($encrypted_data, 0, $nonce_length);
        $ciphertext = substr($encrypted_data, $nonce_length);

        $decrypted = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $nonce,
            $nonce,
            $key
        );

        self::normalizeResponseTime($start_time);

        return $decrypted !== false ? $decrypted : false;
    }

    private static function decryptWithOpenSSL(string $encrypted_data, string $key)
    {
        $start_time = microtime(true);

        $cipher = 'AES-256-CBC';
        $iv_length = openssl_cipher_iv_length($cipher);
        if ($iv_length === false || strlen($encrypted_data) < $iv_length) {
            self::normalizeResponseTime($start_time);
            return false;
        }

        $iv = substr($encrypted_data, 0, $iv_length);
        $encrypted = substr($encrypted_data, $iv_length);

        $decrypted = openssl_decrypt($encrypted, $cipher, $key, OPENSSL_RAW_DATA, $iv);

        self::normalizeResponseTime($start_time);

        return $decrypted !== false ? $decrypted : false;
    }

    private static function checkRateLimit(string $identifier): bool
    {
        $now = time();
        $window_start = $now - self::RATE_LIMIT_WINDOW;

        if (!isset(self::$rate_limit_attempts[$identifier])) {
            self::$rate_limit_attempts[$identifier] = [];
        }

        $attempts = &self::$rate_limit_attempts[$identifier];

        $attempts = array_filter($attempts, function ($timestamp) use ($window_start) {
            return $timestamp > $window_start;
        });

        if (count($attempts) >= self::RATE_LIMIT_MAX_ATTEMPTS) {
            return false;
        }

        $attempts[] = $now;
        return true;
    }

    private static function decryptWithSimple(string $encrypted_data, string $key)
    {
        if (strlen($encrypted_data) < 16) {
            return false;
        }

        // Verificar si hay un usuario autenticado - si es así, saltar el rate limiting
        $user_id = class_exists('Session') ? (Session::getLoginUserID() ?? 0) : 0;
        $is_authenticated = $user_id > 0;

        // Solo aplicar rate limiting si no hay usuario autenticado
        // Los usuarios autenticados no deberían ser limitados
        if (!$is_authenticated) {
            $identifier = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if (!self::checkRateLimit($identifier)) {
                return false;
            }
        }

        $unpacked = unpack('Nenc/Nchecksum', substr($encrypted_data, 0, 8));
        if ($unpacked === false) {
            return false;
        }

        $encrypted = $unpacked['enc'];
        $stored_checksum = $unpacked['checksum'];
        $hash_part = substr($encrypted_data, 8, 8);

        $start_time = microtime(true);
        $timeout = self::SIMPLE_TIMEOUT;
        $max_range = defined('CODEXROUTE_SIMPLE_MAX_RANGE')
            ? CODEXROUTE_SIMPLE_MAX_RANGE
            : self::SIMPLE_MAX_RANGE;

        $max_id = 2147483647;
        $start_id = max(1, $max_id - $max_range);

        for ($i = $start_id; $i <= $max_id; $i++) {
            if ((microtime(true) - $start_time) > $timeout) {
                return false;
            }

            $test_hash = hash('sha256', $key . '|' . $i, true);

            if (substr($test_hash, 16, 8) !== $hash_part) {
                continue;
            }

            $mask1 = unpack('N', substr($test_hash, 0, 4))[1] ?? 0;
            $mask2 = unpack('N', substr($test_hash, 4, 4))[1] ?? 0;
            $mask3 = unpack('N', substr($test_hash, 8, 4))[1] ?? 0;

            $test_enc = $i;
            $test_enc = $test_enc ^ $mask1;
            $test_enc = $test_enc ^ $mask2;
            $test_enc = $test_enc ^ $mask3;

            if ($test_enc === $encrypted) {
                $expected_checksum = unpack('N', substr($test_hash, 12, 4))[1] ?? 0;
                if ($expected_checksum === $stored_checksum) {
                    $elapsed = microtime(true) - $start_time;
                    usleep((int)(($timeout - $elapsed) * 1000000));
                    return (string)$i;
                }
            }
        }

        $elapsed = microtime(true) - $start_time;
        usleep((int)(($timeout - $elapsed) * 1000000));

        return false;
    }

    private static function validateEncryptedFormat(string $encrypted_id): bool
    {
        $min_length = defined('CODEXROUTE_MIN_LENGTH')
            ? CODEXROUTE_MIN_LENGTH
            : self::MIN_ENCRYPTED_LENGTH;
        $max_length = defined('CODEXROUTE_MAX_LENGTH')
            ? CODEXROUTE_MAX_LENGTH
            : self::MAX_ENCRYPTED_LENGTH;

        if (strlen($encrypted_id) < $min_length || strlen($encrypted_id) > $max_length) {
            return false;
        }

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $encrypted_id)) {
            return false;
        }

        return true;
    }

    private static function getClientIdentifier(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $session_id = session_id() ?? '';

        if (!empty($session_id)) {
            return $ip . '|' . $session_id;
        }

        return $ip;
    }

    private static function isIpBlocked(string $ip): bool
    {
        $now = time();

        if (!isset(self::$blocked_ips[$ip])) {
            return false;
        }

        if (self::$blocked_ips[$ip] < $now) {
            unset(self::$blocked_ips[$ip]);
            return false;
        }

        return true;
    }

    private static function blockIp(string $ip, int $duration = null): void
    {
        $duration = $duration ?? (defined('CODEXROUTE_BLOCK_DURATION')
            ? CODEXROUTE_BLOCK_DURATION
            : self::BLOCK_IP_DURATION);

        self::$blocked_ips[$ip] = time() + $duration;

        self::logSecurityEvent('IP_BLOCKED', [
            'ip' => $ip,
            'duration' => $duration,
            'timestamp' => time()
        ]);
    }

    private static function recordFailedAttempt(string $identifier, string $encrypted_id): void
    {
        $now = time();
        $window_start = $now - 300;

        if (!isset(self::$failed_attempts[$identifier])) {
            self::$failed_attempts[$identifier] = [];
        }

        $attempts = &self::$failed_attempts[$identifier];
        $attempts = array_filter($attempts, function ($timestamp) use ($window_start) {
            return $timestamp > $window_start;
        });

        $attempts[] = $now;

        $max_attempts = defined('CODEXROUTE_BLOCK_AFTER_ATTEMPTS')
            ? CODEXROUTE_BLOCK_AFTER_ATTEMPTS
            : self::BLOCK_IP_AFTER_FAILED_ATTEMPTS;

        if (count($attempts) >= $max_attempts) {
            $ip = explode('|', $identifier)[0];
            self::blockIp($ip);
        }
    }

    private static function normalizeResponseTime(float $start_time): void
    {
        $elapsed = microtime(true) - $start_time;
        $normalized_time = defined('CODEXROUTE_NORMALIZED_TIME') && is_numeric(CODEXROUTE_NORMALIZED_TIME)
            ? (float)CODEXROUTE_NORMALIZED_TIME
            : self::NORMALIZED_RESPONSE_TIME;

        if ($elapsed < $normalized_time) {
            $sleep_time = ($normalized_time - $elapsed) * 1000000;
            if ($sleep_time > 0) {
                usleep((int)$sleep_time);
            }
        }
    }

    private static function logSecurityEvent(string $event, array $data = []): void
    {
        $log_enabled = defined('CODEXROUTE_LOG_SECURITY')
            ? CODEXROUTE_LOG_SECURITY
            : true;

        if (!$log_enabled) {
            return;
        }

        $user_id = class_exists('Session') ? (Session::getLoginUserID() ?? 0) : 0;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? 'unknown';

        $log_message = sprintf(
            '[CodexRoute] SECURITY_EVENT: %s | User: %s | IP: %s | URI: %s | Data: %s',
            $event,
            $user_id,
            $ip,
            $uri,
            json_encode($data)
        );

        error_log($log_message);
    }

    public static function decrypt($encrypted_id)
    {
        $start_time = microtime(true);

        if (empty($encrypted_id) || $encrypted_id == '0') {
            self::normalizeResponseTime($start_time);
            return (int)$encrypted_id;
        }

        if (!is_string($encrypted_id)) {
            self::normalizeResponseTime($start_time);
            return (int)$encrypted_id;
        }

        $max_length = defined('CODEXROUTE_MAX_LENGTH')
            ? CODEXROUTE_MAX_LENGTH
            : self::MAX_ENCRYPTED_LENGTH;

        if (strlen($encrypted_id) > $max_length) {
            $identifier = self::getClientIdentifier();
            self::recordFailedAttempt($identifier, substr($encrypted_id, 0, 50));
            self::normalizeResponseTime($start_time);
            return false;
        }

        $identifier = self::getClientIdentifier();
        $ip = explode('|', $identifier)[0];

        // Verificar si hay un usuario autenticado - si es así, ser más permisivo con el bloqueo de IP
        $user_id = class_exists('Session') ? (Session::getLoginUserID() ?? 0) : 0;
        $is_authenticated = $user_id > 0;

        // Solo verificar bloqueo de IP si no hay usuario autenticado
        // Los usuarios autenticados no deberían ser bloqueados por rate limiting
        if (!$is_authenticated && self::isIpBlocked($ip)) {
            self::logSecurityEvent('BLOCKED_IP_ATTEMPT', ['ip' => $ip]);
            self::normalizeResponseTime($start_time);
            return false;
        }

        $strict_mode = defined('CODEXROUTE_STRICT_MODE')
            ? CODEXROUTE_STRICT_MODE
            : false;

        if (preg_match('/^[0-9]+$/', $encrypted_id)) {
            if ($strict_mode) {
                self::logSecurityEvent('STRICT_MODE_VIOLATION', [
                    'id' => $encrypted_id,
                    'identifier' => $identifier
                ]);
                self::normalizeResponseTime($start_time);
                return false;
            }

            self::normalizeResponseTime($start_time);
            return (int)$encrypted_id;
        }

        if (!self::validateEncryptedFormat($encrypted_id)) {
            self::recordFailedAttempt($identifier, substr($encrypted_id, 0, 50));
            self::normalizeResponseTime($start_time);
            return false;
        }

        $cache_key = 'dec_' . $encrypted_id;
        $cached = self::getFromCache($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $encrypted_data = base64_decode(
                strtr($encrypted_id, '-_', '+/') . str_repeat('=', (4 - strlen($encrypted_id) % 4) % 4),
                true
            );

            if ($encrypted_data === false || empty($encrypted_data)) {
                self::recordFailedAttempt($identifier, $encrypted_id);
                return false;
            }

            $key = self::getKey();
            $method = self::detectEncryptionMethod();

            $decrypted = false;
            switch ($method) {
                case 'sodium':
                    $decrypted = self::decryptWithSodium($encrypted_data, $key);
                    break;
                case 'openssl':
                    $decrypted = self::decryptWithOpenSSL($encrypted_data, $key);
                    break;
                case 'simple':
                default:
                    $decrypted = self::decryptWithSimple($encrypted_data, $key);
                    break;
            }

            if ($decrypted === false) {
                self::recordFailedAttempt($identifier, substr($encrypted_id, 0, 50));
                self::normalizeResponseTime($start_time);
                return false;
            }

            if (is_numeric($decrypted)) {
                $result = (int)$decrypted;
            } else {
                $result = $decrypted;
            }

            self::addToCache($cache_key, $result);
            self::normalizeResponseTime($start_time);

            return $result;
        } catch (\Exception $e) {
            self::recordFailedAttempt($identifier, $encrypted_id);
            self::logSecurityEvent('DECRYPTION_EXCEPTION', [
                'error' => $e->getMessage(),
                'identifier' => $identifier
            ]);
            return false;
        }
    }

    public static function decryptParams(array $params, bool $deep = true): array
    {
        $decrypted = [];
        $id_patterns = [
            'id',
            'items_id',
            'tickets_id',
            'users_id',
            'entities_id',
            'locations_id',
            'states_id',
            'groups_id',
            'profiles_id'
        ];

        $excluded_keys = ['card_id', 'gridstack_id', 'cache_key', 'd_cache_key', 'c_cache_key'];

        foreach ($params as $key => $value) {
            if (in_array($key, $excluded_keys, true)) {
                $decrypted[$key] = $value;
                continue;
            }

            $should_decrypt = false;

            if ($key === 'id') {
                $should_decrypt = true;
            } elseif (preg_match('/_id$/', $key)) {
                $should_decrypt = true;
            } elseif (in_array($key, $id_patterns, true)) {
                $should_decrypt = true;
            }

            if ($should_decrypt) {
                if (is_array($value)) {
                    $decrypted[$key] = array_map([self::class, 'decrypt'], $value);
                } else {
                    if (self::looksLikeEncryptedId($value)) {
                        $decrypted_value = self::decrypt($value);
                        if ($decrypted_value !== false) {
                            $decrypted[$key] = $decrypted_value;
                        } else {
                            $decrypted[$key] = $value;
                        }
                    } else {
                        $decrypted[$key] = $value;
                    }
                }
            } elseif ($deep && is_array($value)) {
                $decrypted[$key] = self::decryptParams($value, $deep);
            } else {
                $decrypted[$key] = $value;
            }
        }

        return $decrypted;
    }

    private static function looksLikeEncryptedId($value): bool
    {
        if (!is_string($value) || empty($value)) {
            return false;
        }

        if (is_numeric($value)) {
            return false;
        }

        if (in_array(strtolower($value), ['true', 'false', 'null', ''])) {
            return false;
        }

        if (strlen($value) < 10) {
            return false;
        }

        return true;
    }

    private static function getFromCache(string $key)
    {
        if (!isset(self::$cache[$key])) {
            return null;
        }

        if (isset(self::$cache_timestamps[$key])) {
            $age = time() - self::$cache_timestamps[$key];
            $ttl = defined('CODEXROUTE_CACHE_TTL')
                ? CODEXROUTE_CACHE_TTL
                : self::CACHE_TTL;

            if ($age > $ttl) {
                unset(self::$cache[$key]);
                unset(self::$cache_timestamps[$key]);
                return null;
            }
        }

        return self::$cache[$key];
    }

    private static function addToCache(string $key, $value): void
    {
        $max_size = defined('CODEXROUTE_CACHE_SIZE')
            ? CODEXROUTE_CACHE_SIZE
            : self::CACHE_MAX_SIZE;

        if (count(self::$cache) >= $max_size) {
            self::evictOldestCacheEntry();
        }

        self::$cache[$key] = $value;
        self::$cache_timestamps[$key] = time();
    }

    private static function evictOldestCacheEntry(): void
    {
        if (empty(self::$cache_timestamps)) {
            array_shift(self::$cache);
            return;
        }

        asort(self::$cache_timestamps);
        $oldest_key = array_key_first(self::$cache_timestamps);

        if ($oldest_key !== null) {
            unset(self::$cache[$oldest_key]);
            unset(self::$cache_timestamps[$oldest_key]);
        }
    }

    public static function clearCache(): void
    {
        self::$cache = [];
        self::$cache_timestamps = [];
        self::$rate_limit_attempts = [];
        self::$failed_attempts = [];
        self::$suspicious_patterns = [];
    }

    public static function decryptAndValidate($encrypted_id, string $itemtype, int $right = READ)
    {
        $decrypted_id = self::decrypt($encrypted_id);

        if ($decrypted_id === false || !is_numeric($decrypted_id)) {
            return false;
        }

        $decrypted_id = (int)$decrypted_id;

        if (!class_exists($itemtype) || !is_subclass_of($itemtype, 'CommonDBTM')) {
            return false;
        }

        $item = new $itemtype();

        if (!$item->can($decrypted_id, $right)) {
            return false;
        }

        return $decrypted_id;
    }

    public static function validateAuthorization(int $decrypted_id, string $itemtype, int $right = READ): bool
    {
        if (!class_exists($itemtype) || !is_subclass_of($itemtype, 'CommonDBTM')) {
            return false;
        }

        $item = new $itemtype();
        return $item->can($decrypted_id, $right);
    }

    public static function getSecurityStats(): array
    {
        return [
            'blocked_ips' => count(self::$blocked_ips),
            'failed_attempts' => array_sum(array_map('count', self::$failed_attempts)),
            'rate_limit_attempts' => array_sum(array_map('count', self::$rate_limit_attempts))
        ];
    }
}
