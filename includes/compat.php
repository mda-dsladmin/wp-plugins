<?php
/**
 * PHP 7.0 compatibility helpers.
 *
 * Beacon runs on PHP 7.0 through 8.x. These cover the two spots where the
 * built-in functions behave differently across that range. str_contains(),
 * str_starts_with() and str_ends_with() need no helper: WordPress 5.9+ ships
 * its own copies for PHP 7.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One CSV line, identical to PHP 7.4+'s fputcsv($h, $fields, ',', '"', '').
 *
 * The empty escape character (plain RFC 4180 quoting, no backslash escapes)
 * is not accepted before PHP 7.4, so the line is built here instead: a field
 * is quoted when it holds a comma, quote, space, tab, CR or LF, and quotes
 * inside it are doubled.
 *
 * @param array $fields Cell values (cast to string).
 * @return string The line, ending in "\n".
 */
function beacon_csv_line(array $fields)
{
    $out = [];
    foreach ($fields as $field) {
        $field = (string) $field;
        if (strpbrk($field, ",\" \t\r\n") !== false) {
            $field = '"' . str_replace('"', '""', $field) . '"';
        }
        $out[] = $field;
    }
    return implode(',', $out) . "\n";
}

/**
 * Write one CSV line to an open handle (see beacon_csv_line()).
 *
 * @param resource $handle Open, writable stream.
 * @param array    $fields Cell values.
 */
function beacon_fputcsv($handle, array $fields)
{
    fwrite($handle, beacon_csv_line($fields));
}

/**
 * Read a big-endian IEEE 754 number from raw bytes.
 *
 * unpack('E'/'G') only exists from PHP 7.0.15 / 7.1.1, so decode the native
 * 'd'/'f' format, reversing the bytes first on little-endian machines.
 *
 * @param string $bytes 8 bytes for a double, 4 for a float.
 * @return float
 */
function beacon_unpack_be_float($bytes)
{
    static $little = null;
    if ($little === null) {
        $little = pack('S', 1) === "\x01\x00";
    }
    $bytes = (string) $bytes;
    $format = strlen($bytes) === 8 ? 'd' : 'f';
    if ($little) {
        $bytes = strrev($bytes);
    }
    $parts = unpack($format, $bytes);
    return is_array($parts) ? (float) $parts[1] : 0.0;
}
