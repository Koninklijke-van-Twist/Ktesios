<?php

declare(strict_types=1);

/**
 * Procedurele gebruikersavatars, dezelfde aanpak als Ponos:
 * vaste PNG uit het e-mailadres, kleur uit een hash, spiegelpatroon.
 * Bestanden horen onder web/data/user_avatars/ en blijven lokaal (gitignored).
 */

function ktesios_user_avatar_dir(): string
{
    if (isset($GLOBALS['ktesios_user_avatar_dir']) && is_string($GLOBALS['ktesios_user_avatar_dir']) && $GLOBALS['ktesios_user_avatar_dir'] !== '') {
        return $GLOBALS['ktesios_user_avatar_dir'];
    }
    return dirname(__DIR__) . '/data/user_avatars';
}

function ktesios_user_avatar_filename(string $email): string
{
    $email = strtolower(trim(str_replace("\0", '', $email)));
    $safe = preg_replace('/[^a-z0-9._-]/', '_', $email);
    if (!is_string($safe) || $safe === '') {
        return '';
    }
    $safe = str_replace('..', '_', $safe);
    $safe = trim($safe, '._-');
    if ($safe === '' || strlen($safe) > 180) {
        if ($safe === '') {
            return '';
        }
        $safe = substr($safe, 0, 180);
        $safe = trim($safe, '._-');
        if ($safe === '') {
            return '';
        }
    }
    return $safe . '.png';
}

function ktesios_user_avatar_path(string $email): string
{
    $filename = ktesios_user_avatar_filename($email);
    if ($filename === '') {
        return '';
    }
    return rtrim(ktesios_user_avatar_dir(), "/\\") . DIRECTORY_SEPARATOR . $filename;
}

/**
 * Het pad blijft binnen de avatar-map en bevat geen directory-segmenten.
 */
function ktesios_user_avatar_path_is_contained(string $path): bool
{
    if ($path === '') {
        return false;
    }
    $dir = rtrim(ktesios_user_avatar_dir(), "/\\");
    $prefix = $dir . DIRECTORY_SEPARATOR;
    if (strpos($path, $prefix) !== 0) {
        return false;
    }
    $rest = substr($path, strlen($prefix));
    if ($rest === '' || $rest === '.' || $rest === '..') {
        return false;
    }
    if (strpos($rest, '/') !== false || strpos($rest, '\\') !== false || strpos($rest, '..') !== false) {
        return false;
    }
    if (substr($rest, -4) !== '.png') {
        return false;
    }
    return true;
}

function ktesios_avatar_file_is_safe(string $path): bool
{
    if (!ktesios_user_avatar_path_is_contained($path) || !is_file($path)) {
        return false;
    }
    $dir = realpath(ktesios_user_avatar_dir());
    $file = realpath($path);
    if ($dir === false || $file === false) {
        return false;
    }
    $prefix = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strpos($file, $prefix) === 0 && substr($file, -4) === '.png';
}

function ktesios_hash_text_for_color(string $text): int
{
    $hash = 0;
    $length = strlen($text);
    for ($index = 0; $index < $length; $index++) {
        $hash = (int) (ord($text[$index]) + (($hash << 5) - $hash));
        $hash &= 0x7FFFFFFF;
    }
    return $hash;
}

/**
 * @return array{border: string, dark: string, light: string, chipBackground: string, cardBackground: string, chipTextColor: string}
 */
function ktesios_color_from_text(string $text): array
{
    $normalized = strtolower(trim($text));
    if ($normalized === '') {
        return [
            'border' => '#cbd5e1',
            'dark' => '#64748b',
            'light' => '#94a3b8',
            'chipBackground' => '#e2e8f0',
            'cardBackground' => '#ffffff',
            'chipTextColor' => '#334155',
        ];
    }

    $hash = ktesios_hash_text_for_color($normalized);
    $hue = abs($hash) % 360;
    $saturation = 72 + (abs($hash >> 8) % 14);
    $lightness = 56 + (abs($hash >> 16) % 10);
    $borderLightness = max($lightness - 6, 48);
    $darkLightness = max($lightness - 18, 32);
    $chipTextColor = $lightness >= 58 ? '#1e293b' : '#ffffff';

    return [
        'border' => "hsl({$hue}, {$saturation}%, {$borderLightness}%)",
        'dark' => "hsl({$hue}, {$saturation}%, {$darkLightness}%)",
        'light' => "hsl({$hue}, {$saturation}%, {$lightness}%)",
        'chipBackground' => "hsl({$hue}, {$saturation}%, {$lightness}%)",
        'cardBackground' => 'hsl(' . $hue . ', ' . min($saturation, 48) . '%, 96%)',
        'chipTextColor' => $chipTextColor,
    ];
}

/**
 * @return array{0: float, 1: float, 2: float}|null
 */
function ktesios_parse_hsl_color(string $hsl): ?array
{
    if (preg_match('/hsl\(\s*([\d.]+)\s*,\s*([\d.]+)%\s*,\s*([\d.]+)%\s*\)/', $hsl, $matches) !== 1) {
        return null;
    }
    return [
        (float) $matches[1],
        (float) $matches[2],
        (float) $matches[3],
    ];
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function ktesios_hsl_to_rgb(float $hue, float $saturation, float $lightness): array
{
    $saturation /= 100;
    $lightness /= 100;
    $chroma = (1 - abs((2 * $lightness) - 1)) * $saturation;
    $huePrime = fmod($hue, 360.0) / 60.0;
    $second = $chroma * (1 - abs(fmod($huePrime, 2) - 1));

    $red = 0.0;
    $green = 0.0;
    $blue = 0.0;
    if ($huePrime >= 0 && $huePrime < 1) {
        $red = $chroma;
        $green = $second;
    } elseif ($huePrime < 2) {
        $red = $second;
        $green = $chroma;
    } elseif ($huePrime < 3) {
        $green = $chroma;
        $blue = $second;
    } elseif ($huePrime < 4) {
        $green = $second;
        $blue = $chroma;
    } elseif ($huePrime < 5) {
        $red = $second;
        $blue = $chroma;
    } else {
        $red = $chroma;
        $blue = $second;
    }

    $match = $lightness - ($chroma / 2);
    return [
        (int) round(($red + $match) * 255),
        (int) round(($green + $match) * 255),
        (int) round(($blue + $match) * 255),
    ];
}

/**
 * @param array<string, mixed> $colors
 * @return array{0: int, 1: int, 2: int}
 */
function ktesios_color_dark_rgb(array $colors): array
{
    $hsl = ktesios_parse_hsl_color((string) ($colors['dark'] ?? ''));
    if ($hsl === null) {
        return [100, 116, 139];
    }
    return ktesios_hsl_to_rgb($hsl[0], $hsl[1], $hsl[2]);
}

function ktesios_user_avatar_seed(string $email): int
{
    return ktesios_hash_text_for_color(strtolower(trim($email)));
}

function ktesios_avatar_lattice_value(int $seed, int $x, int $y): float
{
    $n = $seed & 0x7FFFFFFF;
    $n ^= (int) (($x * 374761393) & 0x7FFFFFFF);
    $n ^= (int) (($y * 668265263) & 0x7FFFFFFF);
    $n &= 0x7FFFFFFF;
    $n ^= ($n >> 13);
    $n = (int) (($n * 1274126177) & 0x7FFFFFFF);
    $n ^= ($n >> 16);
    return ($n & 0x7FFFFFFF) / 2147483647;
}

function ktesios_avatar_smoothstep(float $value): float
{
    return $value * $value * (3 - (2 * $value));
}

function ktesios_avatar_smooth_noise(float $x, float $y, int $seed): float
{
    $x0 = (int) floor($x);
    $y0 = (int) floor($y);
    $fx = ktesios_avatar_smoothstep($x - $x0);
    $fy = ktesios_avatar_smoothstep($y - $y0);

    $n00 = ktesios_avatar_lattice_value($seed, $x0, $y0);
    $n10 = ktesios_avatar_lattice_value($seed, $x0 + 1, $y0);
    $n01 = ktesios_avatar_lattice_value($seed, $x0, $y0 + 1);
    $n11 = ktesios_avatar_lattice_value($seed, $x0 + 1, $y0 + 1);
    $nx0 = $n00 + (($n10 - $n00) * $fx);
    $nx1 = $n01 + (($n11 - $n01) * $fx);
    return $nx0 + (($nx1 - $nx0) * $fy);
}

function ktesios_avatar_fractal_noise(float $x, float $y, int $seed): float
{
    $value = 0.0;
    $amplitude = 1.0;
    $frequency = 1.0;
    $total = 0.0;

    for ($octave = 0; $octave < 4; $octave++) {
        $value += ktesios_avatar_smooth_noise($x * $frequency, $y * $frequency, $seed + ($octave * 1013)) * $amplitude;
        $total += $amplitude;
        $amplitude *= 0.5;
        $frequency *= 2.0;
    }

    return $total > 0 ? $value / $total : 0.0;
}

function ktesios_prepare_avatar_dir(): bool
{
    $dir = ktesios_user_avatar_dir();
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    return @mkdir($dir, 0775, true) && is_dir($dir);
}

function ktesios_generate_user_avatar_png(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    if (!ktesios_prepare_avatar_dir()) {
        return false;
    }

    $path = ktesios_user_avatar_path($email);
    if (!ktesios_user_avatar_path_is_contained($path)) {
        return false;
    }

    $seed = ktesios_user_avatar_seed($email);
    $colors = ktesios_color_from_text($email);
    $rgb = ktesios_color_dark_rgb($colors);
    $red = $rgb[0];
    $green = $rgb[1];
    $blue = $rgb[2];

    $image = imagecreatetruecolor(30, 30);
    if ($image === false) {
        return false;
    }

    imagealphablending($image, true);
    imagesavealpha($image, true);

    $white = imagecolorallocate($image, 255, 255, 255);
    $fill = imagecolorallocate($image, $red, $green, $blue);
    if ($white === false || $fill === false) {
        imagedestroy($image);
        return false;
    }
    imagefilledrectangle($image, 0, 0, 29, 29, $white);

    for ($y = 0; $y < 30; $y++) {
        for ($x = 0; $x < 15; $x++) {
            $noise = ktesios_avatar_fractal_noise($x / 4.5, $y / 7.5, $seed);
            if ($noise < 0.54) {
                continue;
            }
            imagesetpixel($image, $x, $y, $fill);
            imagesetpixel($image, 29 - $x, $y, $fill);
        }
    }

    $saved = imagepng($image, $path);
    imagedestroy($image);
    return $saved === true && is_file($path);
}

function ktesios_ensure_user_avatar(string $email): bool
{
    $path = ktesios_user_avatar_path($email);
    if ($path === '' || !ktesios_user_avatar_path_is_contained($path)) {
        return false;
    }
    if (is_file($path)) {
        return true;
    }
    return ktesios_generate_user_avatar_png($email);
}

function ktesios_user_avatar_url(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return '';
    }
    if (!ktesios_ensure_user_avatar($email)) {
        return '';
    }
    return 'user_avatar.php?email=' . rawurlencode($email);
}
