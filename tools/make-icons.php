<?php
/**
 * make-icons.php — generate the PWA icon set.
 *
 * Run once:   php tools/make-icons.php
 * Or hit it in a browser during setup, then delete this file from production.
 *
 * Writes PNGs with no GD, no ImageMagick and no zlib extension: a PNG's IDAT
 * is a zlib stream, and a zlib stream is allowed to use *stored* (uncompressed)
 * deflate blocks. So we emit the raw scanlines wrapped in stored blocks and
 * append an Adler-32. The files are larger than a compressed encoder would
 * produce — a few hundred KB at 512px — which is irrelevant for eight icons
 * that get cached once.
 *
 * The mark: a teal crosshair on the void background, matching the header logo.
 */

$OUT = __DIR__.'/../icons';
if (!is_dir($OUT)) mkdir($OUT, 0775, true);

// ── palette ──────────────────────────────────────────────────────────────────
$VOID   = [0x05, 0x09, 0x14];
$DEEP   = [0x07, 0x18, 0x22];
$TEAL   = [0x00, 0xE5, 0xCC];
$STEEL  = [0x3D, 0x8D, 0xB4];

/** Linear blend of two RGB triples. */
function mix($a, $b, $t) {
    $t = max(0, min(1, $t));
    return [
        (int)round($a[0] + ($b[0] - $a[0]) * $t),
        (int)round($a[1] + ($b[1] - $a[1]) * $t),
        (int)round($a[2] + ($b[2] - $a[2]) * $t),
    ];
}

/**
 * Render one icon into an RGBA pixel buffer.
 *
 * @param int  $size
 * @param bool $maskable  full-bleed square (no rounded corners), mark pulled
 *                        into the inner 80% safe zone
 */
function render($size, $maskable = false) {
    global $VOID, $DEEP, $TEAL, $STEEL;

    $px = [];
    $c  = ($size - 1) / 2.0;
    $radius = $size * 0.22;                       // corner radius for the "any" icon
    $scale  = $maskable ? 0.62 : 0.78;            // mark size relative to the tile
    $ring   = $size * 0.30 * $scale;              // crosshair ring radius
    $lw     = max(1.4, $size * 0.055 * $scale);   // stroke width

    for ($y = 0; $y < $size; $y++) {
        $row = '';
        for ($x = 0; $x < $size; $x++) {

            // background: soft diagonal wash from void to deep
            $t   = (($x / $size) * 0.5 + ($y / $size) * 0.5);
            $rgb = mix($VOID, $DEEP, $t * 0.9);
            $a   = 255;

            // rounded-corner mask for the non-maskable icon
            if (!$maskable) {
                $dx = max(0, abs($x - $c) - ($c - $radius));
                $dy = max(0, abs($y - $c) - ($c - $radius));
                $d  = sqrt($dx * $dx + $dy * $dy);
                if ($d > $radius)       { $a = 0; }
                elseif ($d > $radius - 1.5) { $a = (int)round(255 * ($radius - $d) / 1.5); }
            }

            if ($a > 0) {
                $dx = $x - $c; $dy = $y - $c;
                $dist = sqrt($dx * $dx + $dy * $dy);

                // ambient glow behind the mark
                $glow = max(0, 1 - $dist / ($ring * 2.1));
                if ($glow > 0) $rgb = mix($rgb, $TEAL, $glow * 0.16);

                // the ring
                $dr = abs($dist - $ring);
                if ($dr < $lw / 2) {
                    $edge = min(1, ($lw / 2 - $dr) / 1.2);
                    $col  = mix($TEAL, $STEEL, ($y / $size) * 0.55);
                    $rgb  = mix($rgb, $col, $edge);
                }

                // four crosshair ticks, breaking the ring at the axes
                $tick_out = $ring * 1.42;
                $tick_in  = $ring * 0.42;
                $onX = (abs($dy) < $lw / 2) && (abs($dx) > $tick_in) && (abs($dx) < $tick_out);
                $onY = (abs($dx) < $lw / 2) && (abs($dy) > $tick_in) && (abs($dy) < $tick_out);
                if ($onX || $onY) {
                    $off  = $onX ? abs($dy) : abs($dx);
                    $edge = min(1, ($lw / 2 - $off) / 1.2);
                    $rgb  = mix($rgb, $TEAL, $edge);
                }

                // centre dot
                if ($dist < $lw * 0.85) {
                    $edge = min(1, ($lw * 0.85 - $dist) / 1.2);
                    $rgb  = mix($rgb, $TEAL, $edge);
                }
            }

            $row .= chr($rgb[0]).chr($rgb[1]).chr($rgb[2]).chr($a);
        }
        $px[] = $row;
    }
    return $px;
}

// ── PNG writer ───────────────────────────────────────────────────────────────

function adler32_raw($data) {
    $a = 1; $b = 0;
    $len = strlen($data);
    // chunked so the modulo stays cheap and we never overflow
    for ($i = 0; $i < $len;) {
        $n = min(5552, $len - $i);
        for ($j = 0; $j < $n; $j++, $i++) {
            $a += ord($data[$i]);
            $b += $a;
        }
        $a %= 65521; $b %= 65521;
    }
    return ($b << 16) | $a;
}

/**
 * Build the IDAT zlib stream.
 * Uses gzcompress when zlib is available (it usually is, even with no php.ini,
 * because it is compiled in statically) and falls back to stored blocks so the
 * script still works on a stripped build.
 */
function zlib_stream($raw) {
    if (function_exists('gzcompress')) return gzcompress($raw, 9);
    return zlib_stored($raw);
}

/** zlib stream using stored (uncompressed) deflate blocks. */
function zlib_stored($raw) {
    $out = "\x78\x01";                       // CMF/FLG, no compression preset
    $len = strlen($raw);
    $pos = 0;
    if ($len === 0) $out .= "\x01\x00\x00\xff\xff";
    while ($pos < $len) {
        $n     = min(65535, $len - $pos);
        $final = (($pos + $n) >= $len) ? 1 : 0;
        $out  .= chr($final);
        $out  .= pack('v', $n);
        $out  .= pack('v', (~$n) & 0xFFFF);
        $out  .= substr($raw, $pos, $n);
        $pos  += $n;
    }
    $out .= pack('N', adler32_raw($raw));
    return $out;
}

function png_chunk($type, $data) {
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

function write_png($path, array $rows, $size) {
    $raw = '';
    foreach ($rows as $r) $raw .= "\x00".$r;      // filter byte 0 per scanline

    $png  = "\x89PNG\r\n\x1a\n";
    $png .= png_chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 6, 0, 0, 0));
    $png .= png_chunk('IDAT', zlib_stream($raw));
    $png .= png_chunk('IEND', '');

    file_put_contents($path, $png);
    return strlen($png);
}

// ── run ──────────────────────────────────────────────────────────────────────

$plain = [32, 48, 72, 96, 144, 192, 512];
$made  = [];

foreach ($plain as $s) {
    $bytes = write_png("$OUT/icon-$s.png", render($s, false), $s);
    $made[] = "icon-$s.png (".round($bytes / 1024)." KB)";
}
foreach ([192, 512] as $s) {
    $bytes = write_png("$OUT/icon-maskable-$s.png", render($s, true), $s);
    $made[] = "icon-maskable-$s.png (".round($bytes / 1024)." KB)";
}

// favicon.png is just the 32
if (file_exists("$OUT/icon-32.png")) copy("$OUT/icon-32.png", "$OUT/favicon.png");
$made[] = 'favicon.png';

$nl = (PHP_SAPI === 'cli') ? "\n" : "<br>";
echo "PolitiClash icons written to /icons:$nl";
foreach ($made as $m) echo "  · $m$nl";
