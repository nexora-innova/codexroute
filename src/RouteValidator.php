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
     * Obtiene una lista de rutas problemáticas conocidas
     * Estas son rutas que pueden necesitar ser permitidas manualmente
     * ya que no siguen el patrón estándar de validación
     * 
     * @return array Array de rutas problemáticas con información sobre archivo, ruta, parámetros y descripción
     */
    public static function getProblematicRoutes(): array {
        return [
            [
                'file'        => 'item_disk.form.php',
                'path'        => '/front/item_disk.form.php',
                'params'      => ['id'],
                'description' => 'Formulario de discos de items'
            ],
            [
                'file'        => 'setup.templates.php',
                'path'        => '/front/setup.templates.php',
                'params'      => ['itemtype', 'add'],
                'description' => 'Gestión de plantillas (templates)'
            ]
        ];
    }
}
