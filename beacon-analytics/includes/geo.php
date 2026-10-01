<?php
declare(strict_types=1);

/**
 * Local IP-to-location lookup. Privacy contract:
 *
 *  - The database is a FILE on this server (DB-IP Lite, CC BY 4.0). Lookups
 *    never leave the machine; no visitor data is ever sent to a geo service.
 *  - Only country and state/region are ever returned. City and postal data
 *    exist in the database but are deliberately never read out — the HIPAA
 *    Safe Harbor rule (45 CFR 164.514(b)) allows no geography smaller than
 *    a state, so Beacon does not store any.
 *  - The IP is used for the lookup in memory and discarded, same as hashing.
 *
 * The reader below is a minimal, dependency-free implementation of the
 * MaxMind DB (.mmdb) binary format, which DB-IP also publishes in.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Where the database file lives (inside WP uploads, outside the plugin). */
function beacon_geo_db_path(): string
{
    $up = wp_get_upload_dir();
    return trailingslashit($up['basedir']) . 'beacon-geo/dbip-lite.mmdb';
}

function beacon_geo_available(): bool
{
    return is_readable(beacon_geo_db_path());
}

/**
 * Country ISO code + state/region name for an IP, or [null, null].
 *
 * @return array{0:?string,1:?string} [country, region]
 */
function beacon_geo_lookup(string $ip): array
{
    if (!beacon_geo_available()) {
        return [null, null];
    }
    try {
        $data = beacon_mmdb_lookup(beacon_geo_db_path(), $ip);
    } catch (Throwable $e) {
        return [null, null]; // a corrupt file must never break the collector
    }
    if (!is_array($data)) {
        return [null, null];
    }

    $country = null;
    if (isset($data['country']['iso_code'])) {
        $country = strtoupper(substr((string) $data['country']['iso_code'], 0, 2));
    }
    // State / province: ISO code if present, else the English name.
    // NOTHING smaller than this is read — no city, no postal code.
    $region = null;
    if (!empty($data['subdivisions'][0])) {
        $sub    = $data['subdivisions'][0];
        $region = $sub['names']['en'] ?? $sub['iso_code'] ?? null;
        $region = $region !== null ? mb_substr((string) $region, 0, 64) : null;
    }
    return [$country, $region];
}

/* =========================================================================
 * Database download: the admin clicks a button, the SERVER fetches the free
 * DB-IP Lite file (one request, carries no visitor data — like a plugin
 * update) and stores it in uploads. CC BY 4.0: the dashboard shows the
 * required "IP geolocation by DB-IP" credit whenever geo is active.
 * ========================================================================= */

add_action('admin_post_beacon_geo_download', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'beacon-analytics'));
    }
    check_admin_referer('beacon_geo_download');

    $ok = beacon_geo_download_db();

    wp_safe_redirect(add_query_arg(
        ['page' => 'beacon-settings', 'beacon_msg' => $ok ? 'geo_ok' : 'geo_fail'],
        admin_url('admin.php')
    ));
    exit;
});

function beacon_geo_download_db(): bool
{
    require_once ABSPATH . 'wp-admin/includes/file.php';

    // ~60 MB download + decompress can outlive a shared host's 30s cap.
    @set_time_limit(0);

    $dest = beacon_geo_db_path();
    $dir  = dirname($dest);
    if (!wp_mkdir_p($dir)) {
        return false;
    }
    // Keep the folder unlisted, and (on Apache) block direct fetches.
    if (!file_exists($dir . '/index.php')) {
        file_put_contents($dir . '/index.php', "<?php // silence\n");
    }
    if (!file_exists($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }

    // Monthly file name; fall back one month around release day.
    foreach ([gmdate('Y-m'), gmdate('Y-m', strtotime('first day of last month'))] as $ym) {
        $url = "https://download.db-ip.com/free/dbip-city-lite-{$ym}.mmdb.gz";
        $tmp = download_url($url, 300);
        if (is_wp_error($tmp)) {
            continue;
        }
        // Stream-decompress the ~60 MB gzip without loading it into memory.
        $in  = gzopen($tmp, 'rb');
        $out = fopen($dest . '.part', 'wb');
        if (!$in || !$out) {
            @unlink($tmp);
            return false;
        }
        while (!gzeof($in)) {
            $chunk = gzread($in, 1024 * 512);
            if ($chunk === false || $chunk === '') {
                // Corrupt/non-gzip body (CDN error page as 200): bail cleanly.
                gzclose($in);
                fclose($out);
                @unlink($tmp);
                @unlink($dest . '.part');
                return false;
            }
            fwrite($out, (string) $chunk);
        }
        gzclose($in);
        fclose($out);
        @unlink($tmp);

        // Sanity check before swapping in: must parse as an mmdb.
        try {
            $fh = fopen($dest . '.part', 'rb');
            beacon_mmdb_metadata($fh, $dest . '.part');
            fclose($fh);
        } catch (Throwable $e) {
            @unlink($dest . '.part');
            return false;
        }
        rename($dest . '.part', $dest);
        return true;
    }
    return false;
}

/* =========================================================================
 * Minimal .mmdb reader (MaxMind DB spec 2.0). Read-only, fseek-based, so a
 * 100 MB+ database costs almost no memory per lookup.
 * ========================================================================= */

/**
 * Look one IP up in an .mmdb file. Returns the decoded record array or null.
 */
function beacon_mmdb_lookup(string $file, string $ip)
{
    $bin = inet_pton($ip);
    if ($bin === false) {
        return null;
    }

    $fh = fopen($file, 'rb');
    if (!$fh) {
        return null;
    }

    try {
        $meta = beacon_mmdb_metadata($fh, $file);
        $node_count  = (int) $meta['node_count'];
        $record_bits = (int) $meta['record_size'];
        $node_bytes  = intdiv($record_bits * 2, 8);
        $tree_size   = $node_count * $node_bytes;
        $data_start  = $tree_size + 16; // 16-byte separator after the tree

        // IPv4 inside an IPv6 tree: walk 96 zero bits first.
        $bits = [];
        if (strlen($bin) === 4 && (int) $meta['ip_version'] === 6) {
            $bits = array_fill(0, 96, 0);
        }
        foreach (str_split($bin) as $byte) {
            $b = ord($byte);
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($b >> $i) & 1;
            }
        }

        $node = 0;
        foreach ($bits as $bit) {
            if ($node >= $node_count) {
                break;
            }
            fseek($fh, $node * $node_bytes);
            $raw = fread($fh, $node_bytes);
            $node = beacon_mmdb_record($raw, $record_bits, $bit);
        }

        if ($node === $node_count) {
            return null; // no data for this IP
        }
        if ($node > $node_count) {
            $offset = $node - $node_count - 16 + $data_start;
            [$value] = beacon_mmdb_decode($fh, $offset, $data_start);
            return $value;
        }
        return null;
    } finally {
        fclose($fh);
    }
}

/** One record (left or right) out of a raw node. */
function beacon_mmdb_record(string $raw, int $record_bits, int $side): int
{
    $b = array_map('ord', str_split($raw));
    if ($record_bits === 24) {
        return $side === 0
            ? ($b[0] << 16) | ($b[1] << 8) | $b[2]
            : ($b[3] << 16) | ($b[4] << 8) | $b[5];
    }
    if ($record_bits === 28) {
        return $side === 0
            ? (($b[3] & 0xF0) << 20) | ($b[0] << 16) | ($b[1] << 8) | $b[2]
            : (($b[3] & 0x0F) << 24) | ($b[4] << 16) | ($b[5] << 8) | $b[6];
    }
    // 32-bit records
    return $side === 0
        ? ($b[0] << 24) | ($b[1] << 16) | ($b[2] << 8) | $b[3]
        : ($b[4] << 24) | ($b[5] << 16) | ($b[6] << 8) | $b[7];
}

/** The metadata map at the end of the file. */
function beacon_mmdb_metadata($fh, string $file): array
{
    static $cache = [];
    if (isset($cache[$file])) {
        return $cache[$file];
    }
    $marker = "\xAB\xCD\xEFMaxMind.com";
    $size   = filesize($file);
    $tail   = min($size, 128 * 1024); // metadata lives in the last 128 KB
    fseek($fh, $size - $tail);
    $chunk = fread($fh, $tail);
    $pos   = strrpos($chunk, $marker);
    if ($pos === false) {
        throw new RuntimeException('Not an mmdb file');
    }
    $meta_start = $size - $tail + $pos + strlen($marker);
    // Metadata pointers are relative to the metadata section itself.
    [$meta] = beacon_mmdb_decode($fh, $meta_start, $meta_start);
    if (!is_array($meta) || !isset($meta['node_count'], $meta['record_size'], $meta['ip_version'])) {
        throw new RuntimeException('Bad mmdb metadata');
    }
    $cache[$file] = $meta;
    return $meta;
}

/**
 * Decode one value at $offset. $base is the data-section start, which
 * pointers are relative to. Returns [value, offsetAfterValue].
 */
function beacon_mmdb_decode($fh, int $offset, int $base): array
{
    fseek($fh, $offset);
    $ctrl = ord(fread($fh, 1));
    $offset++;

    $type = $ctrl >> 5;

    if ($type === 1) { // pointer — size bits mean pointer width here
        $psize = (($ctrl >> 3) & 0x3) + 1;
        $bytes = fread($fh, $psize);
        $offset += $psize;
        $v = 0;
        foreach (str_split($bytes) as $c) {
            $v = ($v << 8) | ord($c);
        }
        $low = $ctrl & 0x7;
        $ptr = match ($psize) {
            1 => ($low << 8) | $v,
            2 => (($low << 16) | $v) + 2048,
            3 => (($low << 24) | $v) + 526336,
            4 => $v,
        };
        [$value] = beacon_mmdb_decode($fh, $base + $ptr, $base);
        return [$value, $offset];
    }

    if ($type === 0) { // extended type
        $type = ord(fread($fh, 1)) + 7;
        $offset++;
    }

    // payload size
    $size = $ctrl & 0x1F;
    if ($size === 29) {
        $size = 29 + ord(fread($fh, 1));
        $offset++;
    } elseif ($size === 30) {
        $b = fread($fh, 2);
        $size = 285 + ((ord($b[0]) << 8) | ord($b[1]));
        $offset += 2;
    } elseif ($size === 31) {
        $b = fread($fh, 3);
        $size = 65821 + ((ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]));
        $offset += 3;
    }

    switch ($type) {
        case 2: // UTF-8 string
        case 4: // bytes
            $v = $size ? fread($fh, $size) : '';
            return [$v, $offset + $size];

        case 3: // double
            $v = unpack('E', fread($fh, 8))[1];
            return [$v, $offset + 8];

        case 15: // float
            $v = unpack('G', fread($fh, 4))[1];
            return [$v, $offset + 4];

        case 5: // uint16
        case 6: // uint32
        case 9: // uint64
        case 10: // uint128 (returned as int/float best effort)
        case 8: // int32
            $v = 0;
            if ($size) {
                foreach (str_split(fread($fh, $size)) as $c) {
                    $v = ($v << 8) | ord($c);
                }
            }
            return [$v, $offset + $size];

        case 7: // map
            $map = [];
            for ($i = 0; $i < $size; $i++) {
                [$key, $offset] = beacon_mmdb_decode($fh, $offset, $base);
                [$val, $offset] = beacon_mmdb_decode($fh, $offset, $base);
                $map[(string) $key] = $val;
            }
            return [$map, $offset];

        case 11: // array
            $arr = [];
            for ($i = 0; $i < $size; $i++) {
                [$val, $offset] = beacon_mmdb_decode($fh, $offset, $base);
                $arr[] = $val;
            }
            return [$arr, $offset];

        case 14: // boolean (size IS the value)
            return [$size === 1, $offset];

        default: // container/end marker/unknown — skip
            return [null, $offset + $size];
    }
}
