<?php

namespace GlpiPlugin\Codexroute;

use CommonGLPI;
use Plugin;

class Menu extends CommonGLPI {
    
    public static $rightname = 'config';
    
    public static function getTypeName($nb = 0) {
        return __('CodexRoute', 'codexroute');
    }
    
    public static function getMenuName() {
        return __('CodexRoute', 'codexroute');
    }
    
    public static function getIcon() {
        return 'ti ti-shield-lock';
    }
    
    public static function getMenuContent() {
        // Verificar que el plugin esté instalado y activado antes de mostrar el menú
        $plugin = new Plugin();
        if (!$plugin->isInstalled('codexroute') || !$plugin->isActivated('codexroute')) {
            // Si el plugin no está instalado o activado, no devolver menú
            return false;
        }
        
        $menu = [
            'title' => self::getMenuName(),
            'page'  => Plugin::getWebDir('codexroute') . '/front/config.form.php',
            'icon'  => self::getIcon(),
        ];
        
        return $menu;
    }
}

