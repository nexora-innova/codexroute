<?php

/**
 * CodexRoute - Script para extraer cadenas de traducción
 * 
 * Extrae todas las cadenas que usan __() del plugin y las agrega al archivo .pot
 * 
 * Uso: php tools/extract_locales.php
 */

define('GLPI_ROOT', dirname(__FILE__) . '/../../');
include(GLPI_ROOT . 'inc/includes.php');

$plugin_dir = dirname(__FILE__) . '/../';
$locales_dir = $plugin_dir . 'locales/';
$pot_file = $locales_dir . 'codexroute.pot';

if (!is_dir($locales_dir)) {
    mkdir($locales_dir, 0755, true);
}

$files_to_scan = [
    $plugin_dir . 'front/',
    $plugin_dir . 'ajax/',
    $plugin_dir . 'src/',
    $plugin_dir . 'templates/',
];

$strings = [];

foreach ($files_to_scan as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir)
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['php', 'twig'])) {
            $content = file_get_contents($file->getPathname());
            
            preg_match_all('/__\([\'"]([^\'"]+)[\'"],\s*[\'"]codexroute[\'"]\)/', $content, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $string) {
                    if (!empty(trim($string))) {
                        $strings[trim($string)] = true;
                    }
                }
            }
        }
    }
}

$pot_content = <<<POT
# CodexRoute Translation Template
# Copyright (C) 2025-2026 CodexRoute
# This file is distributed under the same license as the CodexRoute package.
msgid ""
msgstr ""
"Project-Id-Version: CodexRoute 1.0.0\\n"
"Report-Msgid-Bugs-To: \\n"
"POT-Creation-Date: " . date('Y-m-d H:i:s') . "+0000\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"Language: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"

POT;

foreach (array_keys($strings) as $string) {
    $escaped = addcslashes($string, '"\\');
    $pot_content .= "\nmsgid \"$escaped\"\n";
    $pot_content .= "msgstr \"\"\n";
}

file_put_contents($pot_file, $pot_content);

echo "Extracción completada. Se encontraron " . count($strings) . " cadenas.\n";
echo "Archivo generado: $pot_file\n";

