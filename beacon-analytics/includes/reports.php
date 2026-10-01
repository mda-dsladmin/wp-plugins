<?php
declare(strict_types=1);

/**
 * Emailed reports.
 *
 * On the schedule chosen in settings (weekly up to yearly, several at once),
 * Beacon emails a report to the site admin — or to the addresses entered in
 * settings — with the full data attached as a spreadsheet or PDF. The email
 * body carries a short summary; the attachment carries every table.
 *
 * Everything is generated on this server with no outside service and no
 * libraries: the .xlsx and .pdf writers below build the files byte by byte.
 * The report holds the same de-identified aggregates as the dashboard —
 * no visitor hashes, no session IDs, no IPs — so the email adds no PHI
 * exposure beyond what the dashboard already shows. Email is still plain
 * transport: keep recipients to work addresses.
 *
 * Timing model: a 'beacon_email_tick' cron fires twice a day. For each
 * interval the admin selected (1w, 2w, 3w, 1m, 3m, 6m, 1y) we remember when
 * that interval's report last went out; once a full period has passed, the
 * next one is sent covering exactly that period. The first report arrives
 * one full period after the interval is switched on, so it always covers a
 * complete window.
 */

if (!defined('ABSPATH')) {
    exit;
}

const BEACON_REPORT_LAST = 'beacon_report_last_sent';

/** The selectable schedules: key => [days, label]. */
function beacon_report_intervals(): array
{
    return [
        '1w' => [7,   __('Every week', 'beacon-analytics')],
        '2w' => [14,  __('Every 2 weeks', 'beacon-analytics')],
        '3w' => [21,  __('Every 3 weeks', 'beacon-analytics')],
        '1m' => [30,  __('Every month', 'beacon-analytics')],
        '3m' => [91,  __('Every 3 months', 'beacon-analytics')],
        '6m' => [182, __('Every 6 months', 'beacon-analytics')],
        '1y' => [365, __('Every year', 'beacon-analytics')],
    ];
}

/**
 * Who gets the report. The addresses from settings, or the site admin email
 * when the field is blank. Every address is validated again here — settings
 * sanitizing already did, but this list goes straight into wp_mail().
 */
function beacon_report_recipients(): array
{
    $raw = (string) beacon_settings()['report_emails'];
    $out = [];
    foreach (explode(',', $raw) as $e) {
        $e = trim($e);
        if ($e !== '' && is_email($e)) {
            $out[] = $e;
        }
    }
    $out = array_values(array_unique($out));
    if (!$out) {
        $admin = (string) get_option('admin_email');
        if (is_email($admin)) {
            $out[] = $admin;
        }
    }
    return array_slice($out, 0, 10);
}

/**
 * Assemble every table for the report as plain arrays, one structure the
 * CSV, XLSX, and PDF writers all consume:
 *   [ ['title' => ..., 'headers' => [...], 'rows' => [[...], ...]], ... ]
 */
function beacon_report_sections(string $range, array $content): array
{
    global $wpdb;
    // stats.php normally loads only in wp-admin; cron runs outside it.
    require_once BEACON_DIR . 'includes/stats.php';

    $sections = [];
    $days     = beacon_range_to_days($range);

    $sections[] = [
        'title'   => __('About this report', 'beacon-analytics'),
        'headers' => [],
        'rows'    => [
            [__('Site', 'beacon-analytics'), home_url('/')],
            [__('Period', 'beacon-analytics'), sprintf(/* translators: %d: days */ __('Last %d days', 'beacon-analytics'), $days)],
            [__('Generated', 'beacon-analytics'), wp_date('Y-m-d H:i')],
        ],
    ];

    if (in_array('analytics', $content, true)) {
        $d = beacon_get_summary($range, 200); // deep tables: nothing falls below a top-10 cut
        $sections[] = ['title' => __('Totals', 'beacon-analytics'),
            'headers' => [__('Pageviews', 'beacon-analytics'), __('Unique visitors', 'beacon-analytics'), __('Sessions', 'beacon-analytics')],
            'rows' => [[(int) $d['totals']['pageviews'], (int) $d['totals']['visitors'], (int) $d['totals']['sessions']]]];
        $sections[] = ['title' => __('Pageviews by day', 'beacon-analytics'),
            'headers' => [__('Day', 'beacon-analytics'), __('Views', 'beacon-analytics')],
            'rows' => array_map(fn($r) => [$r['day'], (int) $r['views']], $d['series'])];
        $simple = [
            [__('Top pages', 'beacon-analytics'),    __('Path', 'beacon-analytics'),    __('Views', 'beacon-analytics'),    $d['top_pages']],
            [__('Entry pages', 'beacon-analytics'),  __('Path', 'beacon-analytics'),    __('Sessions', 'beacon-analytics'), $d['entries']],
            [__('Exit pages', 'beacon-analytics'),   __('Path', 'beacon-analytics'),    __('Sessions', 'beacon-analytics'), $d['exits']],
            [__('Referrers', 'beacon-analytics'),    __('Source', 'beacon-analytics'),  __('Views', 'beacon-analytics'),    $d['referrers']],
            [__('Devices', 'beacon-analytics'),      __('Device', 'beacon-analytics'),  __('Views', 'beacon-analytics'),    $d['devices']],
            [__('Browsers', 'beacon-analytics'),     __('Browser', 'beacon-analytics'), __('Views', 'beacon-analytics'),    $d['browsers']],
            [__('Operating systems', 'beacon-analytics'), __('OS', 'beacon-analytics'), __('Views', 'beacon-analytics'),    $d['oses']],
            [__('Screen sizes', 'beacon-analytics'), __('Size', 'beacon-analytics'),    __('Views', 'beacon-analytics'),    $d['screens']],
            [__('Exit URLs (outbound clicks)', 'beacon-analytics'), __('Destination', 'beacon-analytics'), __('Clicks', 'beacon-analytics'), $d['exit_urls']],
        ];
        foreach ($simple as [$title, $h1, $h2, $rows]) {
            $sections[] = ['title' => $title, 'headers' => [$h1, $h2],
                'rows' => array_map('array_values', array_map(fn($r) => array_slice($r, 0, 2), $rows))];
        }
        if ($d['countries']) {
            $sections[] = ['title' => __('Countries', 'beacon-analytics'),
                'headers' => [__('Country', 'beacon-analytics'), __('Views', 'beacon-analytics')],
                'rows' => array_map('array_values', $d['countries'])];
            $sections[] = ['title' => __('US states', 'beacon-analytics'),
                'headers' => [__('State', 'beacon-analytics'), __('Views', 'beacon-analytics')],
                'rows' => array_map('array_values', $d['regions'])];
        }
        if ($d['avg_load'] > 0) {
            $sections[] = ['title' => __('Performance', 'beacon-analytics'),
                'headers' => [__('Average page load (ms)', 'beacon-analytics')],
                'rows' => [[(int) $d['avg_load']]]];
        }
        $t = beacon_table();
        $survey = $wpdb->get_results($wpdb->prepare(
            "SELECT event_label, COUNT(*) AS responses
             FROM {$t}
             WHERE event_name = 'survey_response' AND event_label IS NOT NULL AND created_at >= %s
             GROUP BY event_label ORDER BY event_label", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)
        ), ARRAY_A);
        if ($survey) {
            $sections[] = ['title' => __('Survey responses', 'beacon-analytics'),
                'headers' => [__('Question → Answer', 'beacon-analytics'), __('Responses', 'beacon-analytics')],
                'rows' => array_map('array_values', $survey)];
        }
    }

    if (in_array('funnels', $content, true)) {
        $funnels = beacon_eval_funnels(beacon_parse_funnels((string) beacon_settings()['funnels']), $range);
        foreach ($funnels as $f) {
            $rows = [];
            foreach ($f['steps'] as $i => $step) {
                $rows[] = [($step['type'] === 'event' ? 'event: ' : '') . $step['value'], (int) $f['counts'][$i]];
            }
            $sections[] = ['title' => sprintf(/* translators: %s: funnel name */ __('Funnel: %s', 'beacon-analytics'), $f['name']),
                'headers' => [__('Step', 'beacon-analytics'), __('Sessions reaching it', 'beacon-analytics')],
                'rows' => $rows];
        }
    }

    if (in_array('scan', $content, true) && function_exists('beacon_findings_table')) {
        $ft = beacon_findings_table();
        $counts = $wpdb->get_results(
            "SELECT severity, COUNT(*) AS n FROM {$ft} WHERE status = 'open'
             GROUP BY severity ORDER BY FIELD(severity,'critical','serious','moderate','minor')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );
        $sections[] = ['title' => __('Site scan: open findings by severity', 'beacon-analytics'),
            'headers' => [__('Severity', 'beacon-analytics'), __('Open findings', 'beacon-analytics')],
            'rows' => $counts ? array_map('array_values', $counts) : [[__('none', 'beacon-analytics'), 0]]];
        $rows = $wpdb->get_results(
            "SELECT severity, category, path, message FROM {$ft} WHERE status = 'open'
             ORDER BY FIELD(severity,'critical','serious','moderate','minor'), id DESC LIMIT 300", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );
        if ($rows) {
            $sections[] = ['title' => __('Site scan: open findings (up to 300)', 'beacon-analytics'),
                'headers' => [__('Severity', 'beacon-analytics'), __('Category', 'beacon-analytics'), __('Page', 'beacon-analytics'), __('Issue', 'beacon-analytics')],
                'rows' => array_map('array_values', $rows)];
        }
    }

    if (in_array('journeys', $content, true)) {
        foreach (beacon_report_journey_sections($range) as $js) {
            $sections[] = $js;
        }
    }

    return $sections;
}

/**
 * Journeys for exports: a sessions table, and the ordered steps of the
 * most recent sessions. Sessions are identified by a short prefix of the
 * anonymous session code so the two tables can be matched up. Bounded so
 * a busy site cannot produce an unreadable file.
 */
function beacon_report_journey_sections(string $range): array
{
    require_once BEACON_DIR . 'includes/stats.php';

    $study_on = beacon_settings()['study_field'] !== '';
    $sessions = beacon_get_sessions($range, '', 1, 200);
    $out      = [];

    $head = [__('Started', 'beacon-analytics'), __('Session', 'beacon-analytics')];
    if ($study_on) {
        $head[] = __('Study code', 'beacon-analytics');
    }
    array_push($head, __('Pages', 'beacon-analytics'), __('Events', 'beacon-analytics'),
        __('Length (s)', 'beacon-analytics'), __('Entry page', 'beacon-analytics'));

    $rows = [];
    foreach ($sessions as $s) {
        $row = [
            get_date_from_gmt((string) $s['started'], 'Y-m-d H:i'),
            substr((string) $s['session_id'], 0, 6),
        ];
        if ($study_on) {
            $row[] = (string) ($s['study_code'] ?? '');
        }
        array_push($row, (int) $s['pageviews'], (int) $s['events'],
            max(0, strtotime((string) $s['ended']) - strtotime((string) $s['started'])),
            (string) $s['entry_path']);
        $rows[] = $row;
    }
    $out[] = ['title' => __('Journeys: sessions (most recent 200)', 'beacon-analytics'),
        'headers' => $head, 'rows' => $rows];

    // Step-by-step detail for the most recent sessions.
    $ids   = array_column(array_slice($sessions, 0, 50), 'session_id');
    $steps = beacon_get_steps_for_sessions($ids);

    // Time on page per pageview: gap to the next pageview IN THE SAME
    // session. The last page of a session has no next, so it stays blank —
    // that time is unknowable, not zero.
    $durs = [];
    foreach ($steps as $i => $st) {
        if ($st['event_type'] !== 'pageview') {
            continue;
        }
        for ($j = $i + 1, $cnt = count($steps); $j < $cnt; $j++) {
            if ($steps[$j]['session_id'] !== $st['session_id']) {
                break; // steps are ordered by session, then time
            }
            if ($steps[$j]['event_type'] === 'pageview') {
                $durs[$i] = max(0, strtotime((string) $steps[$j]['created_at'])
                    - strtotime((string) $st['created_at']));
                break;
            }
        }
    }

    $rows = [];
    $n    = [];
    foreach ($steps as $i => $st) {
        $sid = (string) $st['session_id'];
        $n[$sid] = ($n[$sid] ?? 0) + 1;
        if ($st['event_type'] === 'pageview') {
            $what = (string) $st['path'];
        } else {
            $what = (string) ($st['event_name'] ?? $st['event_type']);
            if (!empty($st['event_label'])) {
                $what .= ' — ' . $st['event_label'];
            }
        }
        $rows[] = [substr($sid, 0, 6), $n[$sid],
            get_date_from_gmt((string) $st['created_at'], 'H:i:s'),
            $st['event_type'] === 'pageview' ? __('page', 'beacon-analytics') : (string) $st['event_type'],
            isset($durs[$i]) ? $durs[$i] : '',
            $what];
    }
    $out[] = ['title' => __('Journeys: steps (most recent 50 sessions)', 'beacon-analytics'),
        'headers' => [__('Session', 'beacon-analytics'), __('#', 'beacon-analytics'),
            __('Time', 'beacon-analytics'), __('Type', 'beacon-analytics'),
            __('On page (s)', 'beacon-analytics'), __('Step', 'beacon-analytics')],
        'rows' => $rows];

    return $out;
}

// ---------------------------------------------------------------- CSV ----

/**
 * Spreadsheet formula injection guard (same rule as the dashboard export):
 * a visitor can plant a path or label starting with = + - @ via the public
 * collector; prefix with ' so Excel shows it as text instead of running it.
 */
function beacon_report_csv_cell(string $v): string
{
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
        return "'" . $v;
    }
    return $v;
}

function beacon_report_write_csv(array $sections, string $path): bool
{
    $out = fopen($path, 'w');
    if (!$out) {
        return false;
    }
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    foreach ($sections as $s) {
        fputcsv($out, [beacon_report_csv_cell((string) $s['title'])], ',', '"', '');
        if ($s['headers']) {
            fputcsv($out, array_map('beacon_report_csv_cell', array_map('strval', $s['headers'])), ',', '"', '');
        }
        foreach ($s['rows'] as $r) {
            fputcsv($out, array_map('beacon_report_csv_cell', array_map('strval', array_values($r))), ',', '"', '');
        }
        fputcsv($out, [], ',', '"', '');
    }
    return fclose($out);
}

// --------------------------------------------------------------- XLSX ----

/** 0-based column index -> spreadsheet letters (0=A, 25=Z, 26=AA). */
function beacon_xlsx_col(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1 - $m, 26);
    }
    return $s;
}

function beacon_xlsx_esc(string $v): string
{
    // Strip control characters XML forbids, then escape.
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** One worksheet's XML from a section. */
function beacon_xlsx_sheet(array $section): string
{
    $rows = [];
    if ($section['headers']) {
        $rows[] = ['cells' => $section['headers'], 'style' => 1];
    }
    foreach ($section['rows'] as $r) {
        $rows[] = ['cells' => array_values($r), 'style' => 0];
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         . '<cols><col min="1" max="1" width="52" customWidth="1"/><col min="2" max="8" width="20" customWidth="1"/></cols>'
         . '<sheetData>';
    foreach ($rows as $ri => $row) {
        $xml .= '<row r="' . ($ri + 1) . '">';
        foreach ($row['cells'] as $ci => $cell) {
            $ref = beacon_xlsx_col($ci) . ($ri + 1);
            // Real numbers become numeric cells; anything stringy (paths,
            // "007", dates) stays text. Inline strings can never run as
            // formulas, so no injection guard is needed here.
            if (is_int($cell) || is_float($cell)) {
                $xml .= '<c r="' . $ref . '"><v>' . $cell . '</v></c>';
            } else {
                $v = (string) $cell;
                if (preg_match('/^-?(?!0\d)\d{1,12}(\.\d+)?$/', $v)) {
                    $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr" s="' . $row['style'] . '"><is><t xml:space="preserve">'
                          . beacon_xlsx_esc($v) . '</t></is></c>';
                }
            }
        }
        $xml .= '</row>';
    }
    return $xml . '</sheetData></worksheet>';
}

function beacon_report_write_xlsx(array $sections, string $path): bool
{
    if (!class_exists('ZipArchive')) {
        return false; // caller falls back to CSV
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    // Sheet names: Excel limit 31 chars, no []:*?/\ and must be unique.
    $names = [];
    foreach ($sections as $i => $s) {
        $n = preg_replace('/[\[\]:*?\/\\\\]/', '', (string) $s['title']);
        $n = trim(mb_substr($n !== '' ? $n : 'Sheet', 0, 28));
        $base = $n;
        $k = 2;
        while (in_array($n, $names, true)) {
            $n = $base . ' ' . $k++;
        }
        $names[$i] = $n;
    }

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $wbr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sections as $i => $s) {
        $n = $i + 1;
        $ct  .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb  .= '<sheet name="' . beacon_xlsx_esc($names[$i]) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $wbr .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', beacon_xlsx_sheet($s));
    }
    $styleId = count($sections) + 1;
    $ct  .= '</Types>';
    $wb  .= '</sheets></workbook>';
    $wbr .= '<Relationship Id="rId' . $styleId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbr);
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFDA291C"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="2"><xf xfId="0"/><xf xfId="0" fontId="1" applyFont="1"/></cellXfs>'
        . '</styleSheet>');
    return $zip->close();
}

// ---------------------------------------------------------------- PDF ----

/** UTF-8 -> WinAnsi bytes for the PDF core fonts, with escaping. */
function beacon_pdf_text(string $v): string
{
    $v = (string) @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $v);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $v);
}

/**
 * A deliberately small PDF writer: US Letter pages of text lines in
 * Helvetica, with the MD Anderson red on headings. No images, no library.
 */
function beacon_report_write_pdf(array $sections, string $path, string $title): bool
{
    $W = 612.0; $H = 792.0; $MARGIN = 54.0; $CW = $W - 2 * $MARGIN;

    // ---- Layout pass: turn sections into per-page draw commands. ----
    $pages = [];
    $ops   = '';
    $y     = $H - $MARGIN;

    $newpage = function () use (&$pages, &$ops, &$y, $H, $MARGIN): void {
        if ($ops !== '') {
            $pages[] = $ops;
        }
        $ops = '';
        $y   = $H - $MARGIN;
    };
    // font: 1 = regular, 2 = bold; color: k = black, r = brand red
    $line = function (array $cells, array $xw, float $size, int $font, string $color) use (&$ops, &$y, $MARGIN, $newpage): void {
        if ($y < $MARGIN + $size) {
            $newpage();
        }
        $rgb = $color === 'r' ? '0.855 0.161 0.110' : '0.133 0.133 0.133';
        foreach ($cells as $i => $cell) {
            [$x, $w] = $xw[$i];
            $max  = max(3, (int) floor($w / ($size * 0.52))); // approx Helvetica fit
            $text = (string) $cell;
            if (mb_strlen($text) > $max) {
                $text = mb_substr($text, 0, $max - 1) . '…';
            }
            $ops .= sprintf(
                "BT /F%d %.1f Tf %s rg 1 0 0 1 %.1f %.1f Tm (%s) Tj ET\n",
                $font, $size, $rgb, $MARGIN + $x, $y - $size, beacon_pdf_text($text)
            );
        }
        $y -= $size * 1.45;
    };
    $columns = function (int $n) use ($CW): array {
        if ($n <= 1) {
            return [[0.0, $CW]];
        }
        if ($n === 2) {
            // Paths/questions left, counts right.
            return [[0.0, $CW * 0.62 - 8], [$CW * 0.62, $CW * 0.38 - 8]];
        }
        // 3+ columns: generous first and last (labels and messages), short
        // middles (severity, category, counts).
        $first = $CW * 0.28;
        $last  = $CW * 0.34;
        $mid   = ($CW - $first - $last) / ($n - 2);
        $xw    = [[0.0, $first - 8]];
        for ($i = 1; $i < $n - 1; $i++) {
            $xw[] = [$first + ($i - 1) * $mid, $mid - 8];
        }
        $xw[] = [$CW - $last, $last - 8];
        return $xw;
    };

    $line([$title], $columns(1), 16.0, 2, 'r');
    $y -= 4;
    foreach ($sections as $s) {
        if ($y < $MARGIN + 60) {
            $newpage(); // keep a heading with at least a couple of rows
        }
        $y -= 6;
        $line([(string) $s['title']], $columns(1), 11.5, 2, 'r');
        $n = max(count($s['headers']), max(array_map('count', $s['rows'] ?: [[0]])));
        $xw = $columns(max(1, $n));
        if ($s['headers']) {
            $line(array_map('strval', $s['headers']), $xw, 8.5, 2, 'k');
        }
        foreach ($s['rows'] as $r) {
            $line(array_map('strval', array_values($r)), $xw, 8.5, 1, 'k');
        }
    }
    $newpage(); // flush the last page

    // ---- Object pass: catalog, pages, fonts, then page+stream pairs. ----
    $objs = [];
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    $next = 5; // 3, 4 are the fonts
    $pageObjs = [];
    foreach ($pages as $stream) {
        $pageId    = $next++;
        $contentId = $next++;
        $kids[]    = $pageId . ' 0 R';
        $pageObjs[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $W . ' ' . $H . '] '
            . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $pageObjs[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
    }
    $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $objs   += $pageObjs;
    ksort($objs);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objs as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $max  = (int) max(array_keys($objs));
    $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $max; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    }
    $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

    return file_put_contents($path, $pdf) !== false;
}

// --------------------------------------------------------------- Send ----

/**
 * Build and email one report covering the last $days days.
 * Returns true when wp_mail accepted it.
 */
function beacon_send_report(int $days, string $reason): bool
{
    $o       = beacon_settings();
    $content = array_values(array_intersect(explode(',', (string) $o['report_content']), ['analytics', 'scan', 'funnels', 'journeys']));
    if (!$content) {
        $content = ['analytics'];
    }
    $to = beacon_report_recipients();
    if (!$to) {
        return false;
    }

    $range    = min(365, max(1, $days)) . 'd';
    $sections = beacon_report_sections($range, $content);

    // Attachment in the chosen format. XLSX needs ZipArchive; fall back to
    // CSV (which Excel opens fine) if the host lacks it.
    $format = (string) $o['report_format'];
    $stamp  = wp_date('Y-m-d');
    $base   = trailingslashit(get_temp_dir()) . 'beacon-report-' . $stamp . '-' . wp_generate_password(8, false);
    $ok     = false;
    $file   = '';
    if ($format === 'pdf') {
        $file = $base . '.pdf';
        $ok   = beacon_report_write_pdf($sections, $file, sprintf('Beacon Analytics — %s', wp_parse_url(home_url(), PHP_URL_HOST) ?: 'report'));
    } elseif ($format === 'xlsx') {
        $file = $base . '.xlsx';
        $ok   = beacon_report_write_xlsx($sections, $file);
    }
    if (!$ok) { // csv chosen, or the fancier format failed
        $file = $base . '.csv';
        $ok   = beacon_report_write_csv($sections, $file);
    }
    if (!$ok) {
        return false;
    }

    // Short summary for the email body; the attachment has everything.
    $host   = wp_parse_url(home_url(), PHP_URL_HOST) ?: home_url();
    $tot    = ['pageviews' => null, 'visitors' => null, 'sessions' => null];
    foreach ($sections as $s) {
        if (count($s['headers']) === 3 && count($s['rows']) === 1 && is_int($s['rows'][0][0] ?? null)) {
            [$tot['pageviews'], $tot['visitors'], $tot['sessions']] = $s['rows'][0];
            break;
        }
    }
    $subject = sprintf('[Beacon] %s — %s report (%d days)', $host, $reason, $days);
    $body  = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;color:#222">';
    $body .= '<h2 style="color:#DA291C;border-bottom:3px solid #DA291C;padding-bottom:8px">Beacon Analytics</h2>';
    $body .= '<p style="margin:4px 0">' . esc_html($host) . ' &middot; ' . esc_html(sprintf('last %d days', $days)) . '</p>';
    if ($tot['pageviews'] !== null) {
        $body .= '<p style="font-size:15px;margin:14px 0"><strong>' . number_format_i18n($tot['pageviews']) . '</strong> pageviews &middot; <strong>'
               . number_format_i18n($tot['visitors']) . '</strong> visitors &middot; <strong>'
               . number_format_i18n($tot['sessions']) . '</strong> sessions</p>';
    }
    $body .= '<p>The full report is attached (' . esc_html(strtoupper(pathinfo($file, PATHINFO_EXTENSION))) . '). '
           . 'It holds the same de-identified totals as the dashboard &mdash; no personal data.</p>';
    $body .= '<p style="color:#767676;font-size:12px">Sent automatically by the Beacon Analytics plugin on '
           . esc_html($host) . '. Change schedule, content, or recipients in Beacon &rarr; Settings.</p></div>';

    $sent = wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8'], [$file]);
    @unlink($file);
    return (bool) $sent;
}

// The scheduler: twice a day, send whichever selected intervals are due.
add_action('beacon_email_tick', function (): void {
    $o        = beacon_settings();
    $selected = array_intersect(explode(',', (string) $o['report_intervals']), array_keys(beacon_report_intervals()));
    if (!$selected) {
        return;
    }
    $last  = get_option(BEACON_REPORT_LAST, []);
    $last  = is_array($last) ? $last : [];
    $now   = time();
    $dirty = false;
    foreach ($selected as $key) {
        [$days, $label] = beacon_report_intervals()[$key];
        if (empty($last[$key])) {
            // Just switched on: start the clock so the first report covers
            // one full period instead of a partial one.
            $last[$key] = $now;
            $dirty = true;
            continue;
        }
        // 6h slack so a tick landing slightly early doesn't push the report
        // a whole extra half-day late every cycle.
        if ($now - (int) $last[$key] >= $days * DAY_IN_SECONDS - 6 * HOUR_IN_SECONDS) {
            if (beacon_send_report($days, $label)) {
                $last[$key] = $now;
                $dirty = true;
            }
        }
    }
    if ($dirty) {
        update_option(BEACON_REPORT_LAST, $last, false);
    }
});

// "Send a test report now" button on the settings screen.
add_action('admin_post_beacon_send_test_report', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'beacon-analytics'));
    }
    check_admin_referer('beacon_send_test_report');

    $o        = beacon_settings();
    $selected = array_intersect(explode(',', (string) $o['report_intervals']), array_keys(beacon_report_intervals()));
    $days     = 7;
    foreach ($selected as $key) { // shortest selected interval, else 7 days
        $days = beacon_report_intervals()[$key][0];
        break;
    }
    $ok = beacon_send_report($days, __('test', 'beacon-analytics'));

    wp_safe_redirect(add_query_arg(
        ['page' => 'beacon-settings', 'beacon_msg' => $ok ? 'report_sent' : 'report_fail'],
        admin_url('admin.php')
    ));
    exit;
});
