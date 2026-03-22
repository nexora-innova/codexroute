<?php

namespace GlpiPlugin\Codexroute;

/**
 * RouteValidator - Clase simplificada para gestionar rutas problemáticas
 * 
 * Nota: Los métodos de validación manual (applyValidationToFile, removeValidationFromFile, etc.)
 * han sido eliminados ya que la validación ahora se hace globalmente a través de GlobalValidator.
 */
class RouteValidator {
    
    /**
     * Lista estática de rutas conocidas que suelen generar conflictos.
     */
    public static function getProblematicRoutes(): array {
        $static = [
            [
                'file'        => 'item_disk.form.php',
                'path'        => '/front/item_disk.form.php',
                'params'      => ['id'],
                'description' => 'Formulario de discos de items',
                'detected'    => false,
            ],
            [
                'file'        => 'setup.templates.php',
                'path'        => '/front/setup.templates.php',
                'params'      => ['itemtype', 'add'],
                'description' => 'Gestión de plantillas (templates)',
                'detected'    => false,
            ],
        ];

        $detected      = self::getDetectedBlockedRoutes();
        $existing_files = array_column($static, 'file');

        foreach ($detected as $det) {
            if (!in_array($det['file'], $existing_files, true)) {
                $static[] = $det;
            }
        }

        return $static;
    }

    /**
     * Lee las rutas detectadas dinámicamente desde el log generado por GlobalValidator.
     */
    public static function getDetectedBlockedRoutes(): array {
        $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
        $log_file   = $config_dir . '/codexroute/blocked_routes.json';

        if (!file_exists($log_file)) {
            return [];
        }

        $content = @file_get_contents($log_file);
        if (empty($content)) {
            return [];
        }

        $entries = json_decode($content, true);
        if (!is_array($entries)) {
            return [];
        }

        $routes = [];
        foreach ($entries as $entry) {
            if (empty($entry['file'])) {
                continue;
            }
            $routes[] = [
                'file'        => $entry['file'],
                'path'        => $entry['path'] ?? ('/' . $entry['file']),
                'params'      => ['id'],
                'description' => 'Detectada automáticamente (bloqueada con 403)',
                'detected'    => true,
                'timestamp'   => $entry['timestamp'] ?? 0,
            ];
        }

        usort($routes, static function (array $a, array $b): int {
            return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
        });

        return $routes;
    }

    /**
     * Elimina el log de rutas detectadas dinámicamente.
     */
    public static function clearDetectedBlockedRoutes(): bool {
        $config_dir = GLPI_CONFIG_DIR ?? (GLPI_ROOT . '/config');
        $log_file   = $config_dir . '/codexroute/blocked_routes.json';

        if (!file_exists($log_file)) {
            return true;
        }

        return @unlink($log_file) !== false;
    }
}
