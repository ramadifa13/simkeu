<?php
/**
 * Path absolut file logo koperasi di disk, atau '' bila tidak ada.
 * Cari di lokasi baru dulu (uploads/logo/), lalu fallback legacy.
 */
function pathLogoKoperasi($filename) {
    $name = basename(str_replace('\\', '/', (string) $filename));
    if ($name === '' || $name === '.' || $name === '..') {
        return '';
    }
    $root = dirname(__DIR__);
    foreach (['uploads/logo/', 'assets/uploads/logo/', 'admin/uploads/logo/', 'admin/assets/uploads/logo/'] as $dir) {
        $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir) . $name;
        if (is_file($file)) {
            return $file;
        }
    }
    return '';
}

/**
 * Gabung prefix URL dengan path relatif tanpa membuat leading slash
 * (penting untuk login/beranda di subdirectory XAMPP seperti /simkeu/).
 */
function gabungUrlAset($prefix, $relative) {
    $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
    $prefix = (string) $prefix;
    if ($prefix === '' || $prefix === './') {
        return $relative;
    }
    return rtrim($prefix, '/') . '/' . $relative;
}

/**
 * URL relatif dari halaman admin/teller/anggota ke file logo.
 * Menambah ?v=mtime agar browser tidak memakai cache logo lama.
 * $prefix: '../' dari admin/teller/anggota, '' dari root (login/index).
 */
function urlLogoKoperasi($filename, $prefix = '../') {
    $file = pathLogoKoperasi($filename);
    if ($file === '') {
        return '';
    }
    $root = dirname(__DIR__);
    $relative = ltrim(str_replace('\\', '/', substr($file, strlen($root))), '/');
    $v = @filemtime($file) ?: time();
    return gabungUrlAset($prefix, $relative) . '?v=' . $v;
}

/** Display the visible logo bounds without changing the uploaded source image. */
function brandLogo($filename, $prefix = '') {
    $file = pathLogoKoperasi($filename);
    if ($file === '') {
        return '<span class="brand-logo" aria-hidden="true"><i class="ti ti-moneybag"></i></span>';
    }
    $root = dirname(__DIR__);
    $relative = ltrim(str_replace('\\', '/', substr($file, strlen($root))), '/');
    static $bounds = [];
    if (!isset($bounds[$file])) {
        $bounds[$file] = [100, 100, 0, 0];
        $size = @getimagesize($file);
        if ($size && $size[0] <= 5000 && $size[1] <= 5000 && function_exists('imagecreatefrompng') && $size[2] === IMAGETYPE_PNG) {
            $im = @imagecreatefrompng($file);
            if ($im) {
                $w = $size[0];
                $h = $size[1];
                $x1 = $w;
                $y1 = $h;
                $x2 = 0;
                $y2 = 0;
                $step = max(1, (int) (max($w, $h) / 400));
                for ($y = 0; $y < $h; $y += $step) {
                    for ($x = 0; $x < $w; $x += $step) {
                        $c = imagecolorat($im, $x, $y);
                        $a = ($c >> 24) & 127;
                        $r = ($c >> 16) & 255;
                        $g = ($c >> 8) & 255;
                        $b = $c & 255;
                        if ($a < 110 && min($r, $g, $b) < 235) {
                            $x1 = min($x1, $x);
                            $x2 = max($x2, $x);
                            $y1 = min($y1, $y);
                            $y2 = max($y2, $y);
                        }
                    }
                }
                imagedestroy($im);
                if ($x2 > $x1 && $y2 > $y1) {
                    $side = max($x2 - $x1, $y2 - $y1) * 1.14;
                    $bounds[$file] = [$w / $side * 100, $h / $side * 100, 50 - (($x1 + $x2) / 2) / $side * 100, 50 - (($y1 + $y2) / 2) / $side * 100];
                }
            }
        }
    }
    [$width, $height, $left, $top] = $bounds[$file];
    $src = gabungUrlAset($prefix, $relative);
    $v = @filemtime($file) ?: time();
    return '<span class="brand-logo"><img src="' . htmlspecialchars($src . '?v=' . $v, ENT_QUOTES, 'UTF-8') . '" alt="Logo koperasi" style="width:' . $width . '% !important;height:' . $height . '% !important;left:' . $left . '%;top:' . $top . '%"></span>';
}
