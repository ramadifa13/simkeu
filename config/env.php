<?php
/** Memuat .env sederhana tanpa menimpa environment yang sudah diatur hosting. */
if (!defined('SIMKEU_ENV_LOADED')) {
    define('SIMKEU_ENV_LOADED', true);
    $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (is_file($envFile) && is_readable($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) throw new RuntimeException('File .env tidak dapat dibaca.');
        foreach ($lines as $lineNumber => $line) {
            $line=trim($line);
            if ($line==='' || $line[0]==='#' || $line[0]===';') continue;
            $separator=strpos($line,'=');
            if ($separator===false) throw new RuntimeException('Format .env tidak valid pada baris '.($lineNumber+1).'.');
            $name=trim(substr($line,0,$separator));$value=trim(substr($line,$separator+1));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $name) || getenv($name) !== false) continue;
            if (strlen($value)>=2 && (($value[0]==='"' && substr($value,-1)==='"') || ($value[0]==="'" && substr($value,-1)==="'"))) {
                $value=substr($value,1,-1);
            }
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}
