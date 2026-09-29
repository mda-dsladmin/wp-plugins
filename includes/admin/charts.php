<?php
declare(strict_types=1);

/**
 * Hand-rolled SVG charts: line, pie, and the US state map. No chart library,
 * no external requests. Colors come from CSS custom properties so light and
 * dark mode both use their own validated palette (defined in admin.css;
 * both modes pass the color-vision and contrast checks).
 *
 * Accessibility: every chart is aria-hidden decoration layered over real
 * text — the pie legend carries names AND counts, the line chart has a
 * screen-reader summary, and the map sits next to the US states table.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/us-map-paths.php';

/**
 * Line chart for the daily series. 2px line, visible point markers with
 * native <title> tooltips.
 *
 * @param array<int,array{day:string,views:int|string}> $series
 */
function beacon_svg_line(array $series): string
{
    $n = count($series);
    if ($n < 2) {
        return '<p class="beacon-muted">' . esc_html__('Not enough days to draw a line yet.', 'beacon-analytics') . '</p>';
    }
    $w = 720;
    $h = 170;
    $pad_l = 8;
    $pad_b = 22;
    $max = 1;
    foreach ($series as $r) {
        $max = max($max, (int) $r['views']);
    }

    $pts = [];
    foreach ($series as $i => $r) {
        $x = $pad_l + ($i / ($n - 1)) * ($w - $pad_l * 2);
        $y = ($h - $pad_b) - ((int) $r['views'] / $max) * ($h - $pad_b - 8);
        $pts[] = [round($x, 1), round($y, 1), (string) $r['day'], (int) $r['views']];
    }
    $poly = implode(' ', array_map(static function ($p) { return $p[0] . ',' . $p[1]; }, $pts));

    // Uniform scaling (no preserveAspectRatio=none) so tick text never
    // stretches; the viewBox is wide, so it fills the card naturally.
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="beacon-linechart" aria-hidden="true">';
    // recessive baseline
    $svg .= '<line x1="' . $pad_l . '" y1="' . ($h - $pad_b) . '" x2="' . ($w - $pad_l) . '" y2="' . ($h - $pad_b) . '" class="beacon-axis"/>';
    $svg .= '<polyline points="' . esc_attr($poly) . '" fill="none" class="beacon-line"/>';
    $label_every = max(1, (int) ceil($n / 12));
    foreach ($pts as $i => list($x, $y, $day, $views)) {
        $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="4" class="beacon-dot">'
              . '<title>' . esc_html($day . ': ' . number_format_i18n($views) . ' views') . '</title></circle>';
        if ($i % $label_every === 0) {
            $svg .= '<text x="' . $x . '" y="' . ($h - 6) . '" text-anchor="middle" class="beacon-tick">'
                  . esc_html(substr($day, 5)) . '</text>';
        }
    }
    $svg .= '</svg>';
    return $svg;
}

/**
 * Pie (donut) with a legend that carries name + count, so the color is
 * never the only cue. Slices beyond the palette fold into "Other" (gray).
 *
 * @param array<int,array> $rows label/value rows
 */
function beacon_svg_pie(array $rows, string $label_key, string $value_key): string
{
    $slices = [];
    $other  = 0;
    foreach ($rows as $i => $r) {
        $v = (int) ($r[$value_key] ?? 0);
        if ($v <= 0) {
            continue;
        }
        if (count($slices) < 5) {
            $slices[] = ['label' => (string) ($r[$label_key] ?? ''), 'v' => $v];
        } else {
            $other += $v;
        }
    }
    if ($other > 0) {
        $slices[] = ['label' => __('Other', 'beacon-analytics'), 'v' => $other, 'other' => true];
    }
    $total = array_sum(array_column($slices, 'v'));
    if ($total < 1) {
        return '<p class="beacon-muted">' . esc_html__('No data yet.', 'beacon-analytics') . '</p>';
    }

    $cx = 60; $cy = 60; $r = 48;
    $angle = -M_PI / 2; // start at 12 o'clock
    $svg = '<svg viewBox="0 0 120 120" class="beacon-pie" aria-hidden="true">';
    foreach ($slices as $i => $s) {
        $frac = $s['v'] / $total;
        $a2   = $angle + $frac * 2 * M_PI;
        $x1 = $cx + $r * cos($angle); $y1 = $cy + $r * sin($angle);
        $x2 = $cx + $r * cos($a2);    $y2 = $cy + $r * sin($a2);
        $large = ($frac > 0.5) ? 1 : 0;
        $class = !empty($s['other']) ? 'beacon-cat-other' : 'beacon-cat-' . ($i + 1);
        if ($frac >= 0.999) { // single slice: a full circle, arcs can't draw it
            $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" class="beacon-slice ' . esc_attr($class) . '">'
                  . '<title>' . esc_html($s['label'] . ': ' . number_format_i18n($s['v'])) . '</title></circle>';
        } else {
            $svg .= '<path d="M' . $cx . ',' . $cy . ' L' . round($x1, 2) . ',' . round($y1, 2)
                  . ' A' . $r . ',' . $r . ' 0 ' . $large . ' 1 ' . round($x2, 2) . ',' . round($y2, 2)
                  . ' Z" class="beacon-slice ' . esc_attr($class) . '">'
                  . '<title>' . esc_html($s['label'] . ': ' . number_format_i18n($s['v'])) . '</title></path>';
        }
        $angle = $a2;
    }
    // donut hole keeps it readable at small sizes
    $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="22" class="beacon-pie-hole"/></svg>';

    // Legend: swatch + name + count + percent. This is the readable record.
    $legend = '<ul class="beacon-legend">';
    foreach ($slices as $i => $s) {
        $class = !empty($s['other']) ? 'beacon-cat-other' : 'beacon-cat-' . ($i + 1);
        $legend .= '<li><span class="beacon-swatch ' . esc_attr($class) . '" aria-hidden="true"></span>'
                 . '<span class="beacon-legend-label">' . esc_html($s['label']) . '</span>'
                 . '<span class="beacon-legend-num">' . esc_html(number_format_i18n($s['v']))
                 . ' (' . esc_html((string) round($s['v'] / $total * 100)) . '%)</span></li>';
    }
    $legend .= '</ul>';

    return '<div class="beacon-pie-wrap">' . $svg . $legend . '</div>';
}

/**
 * US state choropleth: one red hue, light to dark by pageviews (sequential
 * color for magnitude). States with no data stay neutral. Each state has a
 * native <title> tooltip; the US states table next to it is the readable
 * record.
 *
 * @param array<int,array{region:string,views:int|string}> $regions
 */
function beacon_svg_us_map(array $regions): string
{
    $views = [];
    $max   = 1;
    foreach ($regions as $r) {
        $v = (int) $r['views'];
        $views[(string) $r['region']] = $v;
        $max = max($max, $v);
    }

    $svg = '<svg viewBox="192 9 1028 746" class="beacon-usmap" aria-hidden="true">';
    foreach (beacon_us_state_paths() as $state => $path) {
        $v = $views[$state] ?? 0;
        if ($v > 0) {
            // 4 sequential steps of the brand red, light -> dark by share.
            $step = (int) ceil(($v / $max) * 4);
            $cls  = 'beacon-heat-' . min(4, max(1, $step));
        } else {
            $cls = 'beacon-heat-0';
        }
        $svg .= '<path d="' . $path . '" class="beacon-state ' . esc_attr($cls) . '">'
              . '<title>' . esc_html($state . ': ' . number_format_i18n($v) . ' views') . '</title></path>';
    }
    $svg .= '</svg>';

    // Legend for the scale, text included.
    $svg .= '<p class="beacon-map-legend">'
          . '<span class="beacon-swatch beacon-heat-0" aria-hidden="true"></span> ' . esc_html__('No visits', 'beacon-analytics') . ' '
          . '<span class="beacon-swatch beacon-heat-1" aria-hidden="true"></span> ' . esc_html__('Fewer', 'beacon-analytics') . ' '
          . '<span class="beacon-swatch beacon-heat-4" aria-hidden="true"></span> ' . esc_html__('Most', 'beacon-analytics')
          . '</p>';
    return $svg;
}
