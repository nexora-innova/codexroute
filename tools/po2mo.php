<?php
/**
 * Compila archivos .po a .mo (formato gettext binario) sin depender de msgfmt.
 * Uso: php po2mo.php [ruta_locales]
 * Por defecto compila locales/ del directorio del plugin.
 */

$baseDir = isset($argv[1]) ? rtrim($argv[1], '/\\') : dirname(__DIR__) . '/locales';
if (!is_dir($baseDir)) {
    fwrite(STDERR, "Directorio no encontrado: $baseDir\n");
    exit(1);
}

function poParse($path) {
    $content = file_get_contents($path);
    $entries = [];
    $currentId = null;
    $currentStr = null;
    $inMsgstr = false;
    $buffer = '';
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        if (preg_match('/^msgid\s+"(.*)"\s*$/', $line, $m)) {
            if ($currentId !== null) {
                $entries[] = [$currentId, $currentStr];
            }
            $currentId = str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $m[1]);
            $currentStr = '';
            $inMsgstr = false;
        } elseif (preg_match('/^msgstr\s+"(.*)"\s*$/', $line, $m)) {
            $currentStr = str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $m[1]);
            $inMsgstr = true;
        } elseif ($inMsgstr && preg_match('/^"(.*)"\s*$/', trim($line), $m)) {
            $currentStr .= str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $m[1]);
        } elseif (!$inMsgstr && $currentId !== null && preg_match('/^"(.*)"\s*$/', trim($line), $m)) {
            $currentId .= str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $m[1]);
        }
    }
    if ($currentId !== null) {
        $entries[] = [$currentId, $currentStr];
    }
    return $entries;
}

function writeMo($path, $entries) {
    $moMagic = 0x950412de;
    $revision = 0;
    $n = count($entries);
    $origTable = [];
    $transTable = [];
    $origData = '';
    $transData = '';
    foreach ($entries as $e) {
        $origTable[] = [strlen($e[0]) + 1, strlen($origData)];
        $origData .= $e[0] . "\0";
        $transTable[] = [strlen($e[1]) + 1, strlen($transData)];
        $transData .= $e[1] . "\0";
    }
    $headerSize = 7 * 4;
    $tableSize = $n * 8;
    $origTableOffset = $headerSize;
    $transTableOffset = $headerSize + $tableSize;
    $origDataOffset = $headerSize + 2 * $tableSize;
    $transDataOffset = $origDataOffset + strlen($origData);
    $totalSize = $transDataOffset + strlen($transData);
    foreach ($origTable as $i => $_) {
        $origTable[$i][1] += $origDataOffset;
    }
    foreach ($transTable as $i => $_) {
        $transTable[$i][1] += $transDataOffset;
    }
    $out = pack('V', $moMagic);
    $out .= pack('V', $revision);
    $out .= pack('V', $n);
    $out .= pack('V', $origTableOffset);
    $out .= pack('V', $transTableOffset);
    $out .= pack('V', 0);
    $out .= pack('V', 0);
    foreach ($origTable as $row) {
        $out .= pack('V', $row[0]) . pack('V', $row[1]);
    }
    foreach ($transTable as $row) {
        $out .= pack('V', $row[0]) . pack('V', $row[1]);
    }
    $out .= $origData . $transData;
    return file_put_contents($path, $out) !== false;
}

foreach (['en_GB', 'es_ES'] as $lang) {
    $po = $baseDir . DIRECTORY_SEPARATOR . $lang . '.po';
    $mo = $baseDir . DIRECTORY_SEPARATOR . $lang . '.mo';
    if (!file_exists($po)) {
        fwrite(STDERR, "No existe: $po\n");
        continue;
    }
    $entries = poParse($po);
    if (writeMo($mo, $entries)) {
        echo "[OK] $lang.mo\n";
    } else {
        fwrite(STDERR, "[ERROR] $lang.mo\n");
        exit(1);
    }
}
