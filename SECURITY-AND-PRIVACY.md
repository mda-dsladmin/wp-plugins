# Beacon Analytics — Security and Privacy Review Document

Written for security reviewers and compliance staff. Everything here can be
verified in the source; file names are given so a reviewer can check each
claim. Plain-language summaries come first, mechanics after.

Version: 1.17.0. Roughly 7,000 lines of PHP/JS, no third-party libraries,
no build step. Runs on WordPress 6.0+ with PHP 7.0 through 8.4. The only
outside service is the plugin update check (GitHub, see §10), which carries
no analytics data.

Security review: version 1.17.0 went through three rounds of independent
adversarial review plus a final verification pass (public collector,
privacy scrubbing, admin screens, scanner, reports, updater, release
pipeline). Every finding in the code was fixed, and each fix has an
automated regression test (217 checks) that passes on WordPress 6.0 /
PHP 7.0 and WordPress 7.1 / PHP 8.4. Section 11 lists what remains.

---

## 1. One-paragraph summary

Beacon is first-party analytics. The visitor's browser talks only to the
WordPress site it is already on. The server scrubs each event before
storing it: no IP, no query string, no city, no input values, no durable
identifier. All reporting is inside wp-admin, restricted to administrators.
The known regulatory failure this replaces is hospital sites sending
IP-plus-health-page data to Google and Meta; Beacon has no third party in
the path at all.

## 2. Data flow

    visitor's browser
        │  POST /wp-json/beacon/v1/collect   (same site, ~300 bytes JSON)
        ▼
    collector (includes/collector.php)
        │  validates site key, rate limits, scrubs (includes/privacy.php)
        ▼
    wp_beacon_events (site's own MySQL)
        ▲
        │  read-only, admins only
    wp-admin dashboards (includes/admin/*)

There is exactly one public write endpoint (the collector) and one public
read endpoint (none — reading requires a logged-in administrator). The
noscript fallback is a GET to the same collector that records a bare
pageview and returns a 1x1 gif.

If the optional Collector URL setting points at another Beacon install,
the browser posts there instead and this site stores nothing.

## 3. What one stored event contains

Table `wp_beacon_events` (created in beacon-analytics.php):

| Column | Example | Notes |
|---|---|---|
| event_type | pageview | pageview, event, or outbound |
| event_name | cta_click | custom events only |
| event_label | Schedule a Visit | element caption; scrubbed, see §5 |
| path | /brst | query string stripped; decoded, personal-data segments replaced, 5+ digit runs masked, re-encoded; search terms removed |
| title | Breast Pathway | chosen by the server (generic on search and 404 pages), then scrubbed like labels |
| referrer_host | google.com | host only, never the full URL |
| visitor_day_hash | sha256(...) | rotates daily, see §4 |
| session_id | sha256(...) 32 chars | one visit (per tab with JavaScript; the no-JS pixel has no tab id, so its session is the visitor-day) |
| country / region | US / Texas | optional, state max, see §6 |
| device_type | mobile | coarse bucket |
| browser / browser_ver | Chrome / 143 | family + MAJOR version only |
| os / os_ver | iOS / 18 | family + MAJOR version only, see below |
| screen_bucket | laptop | one of five ranges; exact pixels never sent |
| load_ms | 1240 | clamped integer |
| created_at | UTC datetime | deleted after the retention window |

**Fingerprint resistance (why versions are majors and screens are buckets):**
a full user-agent build string plus an exact screen resolution is unique
enough to track a person without cookies — that is how commercial
fingerprinting works. Beacon therefore stores only major versions (shared
by millions of users) and one of five screen ranges (bucketed in the
browser; the exact pixel width never leaves the visitor's device). Desktop
OS versions are additionally hidden by modern browsers themselves, so
desktop rows carry the OS family only. The stored combination is common to
thousands of visitors and cannot serve as an identifier.

**Never stored, anywhere, ever:** IP addresses, raw user agents beyond the
coarse buckets, query strings (unless a key is allow-listed by the admin),
full referrer URLs, cookies (none are set), names, emails, form input
values, city or postal location, or any identifier that survives past
midnight.

## 4. The identity model (the HIPAA core)

- **Visitor hash**: HMAC-SHA256 of (the IP's network + user agent). The IP
  is first cut to its network (IPv4 /24, IPv6 /48, beacon_ip_network() in
  includes/privacy.php), so the hashed value is never one person's
  address. The key is a secret salt replaced at the site's local midnight;
  yesterday's salt is overwritten, not kept (beacon_day_salt()). The IP is
  used in memory and discarded (includes/collector.php). Once the day ends
  the key is gone, so two days of data cannot be joined on the hash. A
  same-day database copy does hold that day's key; even then, guessing the
  hash back gives at most a network shared by many people, not an address.
  It answers "how many unique visitors today" and nothing else. Versions
  before 1.17.0 used one permanent salt; 1.17.0 deletes it on upgrade.
- **Session ID**: hash of a random per-tab value plus the visitor hash.
  Links the steps of one visit so journeys and funnels work. Not a cookie;
  it lives in tab memory and is gone when the tab closes. (The no-JavaScript
  pixel has no tab value, so its "session" is simply the visitor-day.)
- **Do Not Track / GPC**: honored. Default mode counts the visit with NO
  visitor hash and no session. Strict mode stores nothing at all.
- **Retention**: raw events are deleted daily after the configured window
  (default 90 days, includes/cron.php). Admins can also erase all
  analytics data or all scan results instantly from the UI.

Regulatory context, for the reviewer's file: OCR's 2022 online-tracking
guidance treated IP + health-page-visited as PHI on public pages; the
portion covering unauthenticated public pages was vacated by AHA v.
Becerra (N.D. Tex., June 2024). Beacon is built to pass the stricter,
vacated standard anyway, since the IP is never stored and identity never
persists. Beacon is intended for public pages. It should not be deployed
on authenticated patient portals without a separate review.

## 5. Scrubbing rules (includes/privacy.php)

Applied server-side on every event, in one auditable file:

1. Query strings are removed from paths. Exception: keys the admin
   explicitly allow-lists (for shortlink params like `sl`). Search, email,
   name, token and ad-click keys (`s`, `q`, `utm_term`, `email`, `gclid`,
   ...) can never be allow-listed. A kept value is cleaned first and then
   checked; it is dropped if it looks personal or is still percent-encoded
   (double encoding), otherwise digit-masked and truncated.
2. Paths are percent-decoded first (so `%31%32%33...` cannot hide digits),
   then any path segment that looks like personal data (an email in any
   written-out form; a phone, SSN, or long ID number; a calendar date in
   numeric or month-name form, English or Spanish) is replaced with
   `[redacted]`. Each segment is checked fully decoded (double-encoded
   text included). Numbers split across segments (`/123/45/6789/`,
   `/03/14/1952/`) are caught too. Before checking, look-alike characters
   are folded: full-width and "math" digits, digits from other scripts,
   Unicode dashes and spaces, and invisible characters. Search-result
   paths lose the terms, also under a language prefix (`/es/search/...`),
   and on search pages the browser is given a generic path so the terms
   are never sent at all. Anything outside plain URL characters is
   re-encoded, so a stored path never holds markup or quotes.
3. Any run of 5 or more digits (any script) left in a path, title, label,
   or kept value becomes `[redacted]` (an MRN-shaped backstop).
4. Referrers are reduced to a validated hostname only.
5. Page titles: the server chooses them (search pages send "Search
   results", 404 pages "Page not found", because WordPress puts the
   visitor's search terms in those titles), and the collector checks them
   again. Titles are the site's own headlines, so they get the looser
   check: an email, phone or SSN shape drops the title, but dates are
   allowed ("Walk: October 12, 2025"). Year ranges ("2024-2025") and
   grouped numbers ("1,250,000") are not mistaken for phone numbers.
6. Event labels (element captions from click/hover tags, and survey
   answers): the browser refuses to read labels from input, select,
   textarea, or label elements and drops email- and phone-shaped strings;
   the server repeats the full, strict personal-data check (dates
   included) plus digit masking before storing (defense in depth,
   tracker.js + privacy.php). If a check cannot run (a regex error), the
   text is treated as personal and dropped.
7. Event names must be short keys (letters, digits, `_ . : -`) that do not
   look personal and hold no 5+ digit run; anything else is dropped.
   Values that are not plain text (arrays, objects) are ignored outright.
   Request bodies over 8 KB are refused before they are read, every field
   is cut to 2 KB before any checks run, and everything is length-clamped
   and passed through WordPress sanitizers.
8. **Survey capture is the one deliberate exception**, and it is opt-in.
   With the "Survey capture" setting blank (the default), no visitor input
   is ever stored. If an admin fills in the selector, a chosen radio
   answer is stored as "Question → Answer" in the event label, subject to
   the same email/phone refusals and digit masking, first-party only,
   anonymous session only. Reviewers should treat this setting as the
   plugin's most sensitive switch: on a page asking medical questions, an
   answer can describe a health situation, and enabling it deserves
   compliance sign-off even though no identity is attached.
   Numeric fields are DOUBLY opt-in: never captured unless the admin lists
   the specific field in the Number-overrides setting with a chosen bucket
   width. With a width set, bucketing happens IN THE BROWSER ("50–59") and
   the exact typed value never leaves the visitor's device; an admin may
   set "none" for an exact value, which reviewers should treat as the
   least-preferred option. Safe Harbor rules (45 CFR 164.514(b)(2)) are
   enforced on the server for every survey answer (number field, dropdown,
   or radio), in English and Spanish, and also when the "question" is a
   field name like `patient_age`, `birthYear` or `Month *`: ages of 90 or
   more, and years implying age 90+, become "90+"; the month or day of any
   date (birth date, date of surgery, month of diagnosis) is dropped
   entirely, so only a year is ever kept. The browser applies the age cap
   too. Free-text fields are still never read at all. Survey answers appear in emailed
   reports only if an admin turns on "Include survey answers in emailed
   reports" (off by default).

### 5a. Survey capture: the HIPAA analysis when it is ON

For the compliance file. A HIPAA violation requires protected health
information plus an impermissible use or disclosure. With survey capture
enabled, both prongs still fail:

1. **Identifiability.** The stored record is "an anonymous session
   answered X to question Y." It carries no name, IP, cookie, account, or
   durable identifier; the session hash cannot be linked across days or
   reversed. Health information that cannot reasonably be tied to a
   person is not individually identifiable health information (45 CFR
   160.103). Note also AHA v. Becerra (N.D. Tex. 2024), which vacated
   OCR's position that even IP + health page visited is automatically
   PHI on unauthenticated public pages; Beacon stores less than the
   vacated standard covered.
2. **Use vs. disclosure.** Even under a hypothetical finding that the
   data were PHI, the Privacy Rule permits a covered entity to USE its
   own PHI internally for health care operations (45 CFR 164.506(c)),
   which includes evaluating and improving its services and web
   resources. Beacon's data never leaves the covered entity's server and
   is viewable only by site administrators. The enforcement actions
   against hospital web tracking involved DISCLOSURE to third-party ad
   platforms without authorization; no disclosure occurs here.

Two documented caveats: (a) if this data were ever shared OUTSIDE the
organization, formal Safe Harbor de-identification would additionally
require reducing timestamps to year only — an export-time concern, not an
internal-use concern; (b) institutional privacy policy may be stricter
than HIPAA, so enabling the switch should follow internal sign-off. This
section is an engineering analysis, not legal advice.

## 6. Location data (includes/geo.php)

Optional. The admin downloads the free DB-IP Lite database, a file stored
in the uploads folder. Lookups are local file reads (a purpose-built
~250-line .mmdb reader, no dependencies). Only country and state/region
are read out. City and postal data exist in the file but there is no code
path that stores them. This matches HIPAA Safe Harbor (45 CFR 164.514(b)),
which allows no geographic unit smaller than a state. The one-time
database download is a server fetch of a public file, like a plugin
update; it carries no visitor data. DNT visits skip geo entirely.

## 7. The tag manager guardrail (includes/tags.php)

Google Tag Manager leaks because a tag can be arbitrary JavaScript or a
third-party URL. Beacon's rule format is `trigger | value | event_name`.
There is no field for a URL or script, lines containing `http` or `<` are
discarded, event names are stripped to `[a-zA-Z0-9_]`, and the tracker can
only turn a rule into a `window.beacon()` call to your own collector. The
same guardrail applies to the scanner's policy rules and the funnels
setting. Reviewers should confirm no future change adds a "custom HTML"
rule type; that single change would reintroduce the GTM problem.

## 8. The site scanner (includes/scan/)

Fetches the site's own published pages (WordPress enumerates them; no
open-web crawling), as an anonymous visitor, over WP-Cron in time-boxed
batches. Per page it runs six check families and stores findings, never
pages. The only stored page content is a snippet of a flagged element,
which is value-stripped, digit-masked, and clamped to 200 chars
(beacon_scrub_snippet in includes/scan/checks.php). The privacy category
flags any element that would make a browser call a non-allow-listed
domain, plus known tracker signatures in inline scripts, so every scan is
evidence that no page leaks to a third party. The scanner never
authenticates, so it can never see patient-facing pages.

Request safety (server-side request forgery), includes/scan/checks.php:
every scanner request goes through one function (beacon_scan_request).

- Before each request, every address the host resolves to (IPv4 and IPv6)
  must be public. Loopback, private, carrier NAT, link-local and cloud
  metadata (169.254.169.254), reserved ranges, and IPv6 forms that tunnel
  to IPv4 (mapped, NAT64, 6to4, Teredo) are refused, whatever way the
  address is written. This does not rely on the WordPress version (older
  versions do not block link-local themselves).
- The connection is then pinned to the address that was checked, so a DNS
  answer that changes in between ("DNS rebinding") cannot redirect it.
  Without the curl transport, outside hosts are not requested at all.
- Redirects are followed by hand (at most 3) and every hop is checked the
  same way. Page fetches, and link checks while outside links are off,
  must stay on the site's own hosts on every hop.
- The site's own host is only allowed on its own ports (80, 443, or the
  home URL's port), never on others (e.g. :6379). Responses are capped at
  5 MB.

Links to unsafe addresses in page content are skipped, not reported, so the
scanner cannot be used to probe an internal network. Checking links to
OTHER sites is off by default; when an admin turns it on, it sends HEAD
requests carrying no visitor data. The stalled-scan watchdog runs only for
administrators or cron.

## 9. Application security controls

- **SQL**: every query uses $wpdb->prepare or $wpdb->insert; table names
  come only from $wpdb->prefix. No string-concatenated values.
- **XSS**: every admin-screen echo goes through esc_html / esc_attr /
  esc_url / esc_textarea. The front-end snippet uses wp_json_encode and
  esc_ functions. The tracker builds no DOM.
- **CSRF**: every state-changing action (settings save, scan start,
  finding status, clears, geo download, exports) is a nonce-checked POST
  or nonce-checked GET via check_admin_referer, behind
  current_user_can('manage_options').
- **Collector hardening**: 32-hex site key compared with hash_equals (the
  key is printed in every page, so it stops blind bots, not a determined
  sender; the controls below do the real work); Origin must be this site or
  a host the admin listed, and "null"/malformed origins are rejected; the
  no-JS pixel stores a pageview only when the Referer is this site (or an
  allowed sender), so other sites cannot pad the counts; bodies over 8 KB
  are refused; rate limits per IPv6 /48 (600 a minute), per IP (120 a
  minute; IPv6 counted per /64, IPv4-in-IPv6 as IPv4), per session (500
  events an hour) and site-wide (3000 a minute, filterable), checked in
  that order so one flooding address cannot use up everyone's allowance,
  kept in a small self-pruning table (also pruned during normal traffic)
  or the object cache rather than wp_options; allow-list validation and
  privacy scrubbing of every field; non-text values ignored; responds 204
  and never echoes input.
- **Report emails**: attachments are written with a random name and
  owner-only permissions from the first byte (not just after a chmod),
  and always deleted after sending (even on failure). Recipients must be valid email addresses (no header injection).
- **CSV exports**: cells starting with = + - @ are apostrophe-prefixed so
  a visitor-planted path or label cannot become an executing formula in
  Excel (includes/admin/export.php).
- **Secrets**: the daily hashing salt is 64 hex chars, replaced every day,
  never displayed, stored unautoloaded in wp_options.
- **Uninstall**: data is kept unless the admin opted into deletion. Either
  way, scheduled jobs are cleared, the salts are deleted (so kept hashes can
  never be linked or checked against an address again), the rate-limit
  table is dropped, and kept events are trimmed to the retention window
  one last time. On multisite this runs for every site in the network.
- **Inline scripts**: every value printed into a page script is JSON
  encoded with `<`, `>` and `&` escaped, so page or visitor text cannot
  end the script early.
- **Location database**: the decoder limits nesting and checks every size
  against the file, so a damaged file fails safely; the folder is blocked
  from direct download on Apache 2.2 and 2.4.

## 10. Complete list of outbound network calls

Requests the SERVER makes (never the visitor's browser):

1. Fetching this site's own pages during a scan (loopback).
2. HEAD/GET checks of outbound links during a scan, if enabled. No
   visitor data attached. Off by one checkbox.
3. The one-time/manual DB-IP database file download, admin-initiated.
4. Plugin update checks: during WordPress's normal update checks (about
   twice a day, or when an admin clicks "Check for updates"), a request to
   api.github.com for the release list of github.com/mda-dsladmin/wp-plugins,
   and, only when an update is installed, the release zip from
   github.com (served from GitHub's download host,
   release-assets.githubusercontent.com). No analytics or visitor data is sent. The optional read-only
   token (MDA_WP_PLUGINS_GITHUB_TOKEN) is only ever sent to api.github.com;
   the request does not follow redirects.

That is the entire list. The visitor's browser makes zero third-party
requests because of this plugin.

## 11. Residual risks a reviewer should note

- **Small-cell re-identification**: on a very low-traffic page, a single
  session's journey could in theory be matched to a known individual by an
  administrator viewing the dashboard. Mitigations: admins only, no
  identity stored, short retention. This is an internal access-control
  consideration, not a disclosure.
- **Hosting provider access**: the host (e.g. Flywheel) can read the
  server like any host can. Whether a BAA is needed for the hosting
  relationship is a question for counsel and is independent of this
  plugin. Note that WP Engine's acceptable use policy (Flywheel's parent)
  prohibits storing PHI on their platform; Beacon stores none, but any
  OTHER feature of the site that collects patient information would be
  the thing to review.
- **Admin trust**: an administrator can change settings, including the
  external Collector URL. Sending events to an external collector moves
  storage off this site; the field only accepts http(s) URLs and is
  visible in settings for audit.
- **Update supply chain**: sites install whatever release is published on
  github.com/mda-dsladmin/wp-plugins. Anyone with write access to that repo
  can publish a release, so repo access, tag protection, and 2FA are part of
  Beacon's security. See the plugins guide (docs/WP-PLUGINS-GUIDE.md at the top of the repo) for
  the required GitHub settings.
- **Pattern matching has limits**: the personal-data checks recognize the
  shapes of emails, phone and SSN numbers, dates and record numbers, in
  many disguises. They cannot recognize a bare name ("Jane Doe") typed
  into a URL, and no pattern list is ever complete. This is why the design
  does not depend on them alone: query strings are dropped by default,
  search terms never leave the browser, form fields are never read, and
  survey capture is off by default. The trade-off runs the other way too:
  a page path or button text that contains a date or phone number (an
  event page like `/events/2025-03-14-walk/`, a "Call 713-..." button) is
  stored as `[redacted]` or dropped.
- **Outbound proxy**: if the site sends its outbound traffic through an
  HTTP proxy (WP_PROXY_HOST), the proxy does its own DNS lookup, so the
  scanner's address pin applies to the proxy, not the destination. Keep
  outside-link checks off on such sites, or rely on the proxy's own rules.
- **web server logs**: independent of Beacon, the web server itself logs
  raw visitor IPs and requested URLs, as on every website. A full privacy
  review of the site should include host log retention.

## Study code capture (since 1.16.0): the documented identifiability exception

Everything above describes Beacon's default, fully de-identified posture.
Version 1.16.0 adds ONE deliberate, off-by-default exception for consented
research studies: the `study_field` setting names a single input id, and
the value a visitor submits in that exact field is stored as a
`study_code` event on their session, displayed in Journeys.

Controls on this path:

- Off by default. With the setting blank, the tracker never registers the
  listener and the code path does not exist on the page.
- One field only, by exact id, chosen by an administrator. No other form
  field becomes readable; the tracker's refusal to read form input is
  unchanged everywhere else.
- Read at submit time only, never while typing.
- Enforced on the server too: the collector accepts a study code only
  while study code capture is turned on in settings. With it off, any
  `study_code` event is dropped, whoever sends it.
- Strict shape gate at BOTH ends: the tracker sends, and the collector
  stores, only values matching `^[A-Za-z0-9_-]{1,32}$`. On top of that the
  collector rejects all-digit values of 7 or more digits (SSN-, phone-, or
  MRN-shaped) and anything the personal-data check flags. Emails and free
  text cannot fit the pattern at all.
- The label bypasses digit masking (an ID like C00123 must survive), which
  is safe because of the shape gate and those extra rejections.

Compliance consequence, stated plainly: a study ID maps to an enrolled
person through the study's roster, so enabling this setting makes stored
sessions identifiable health information rather than de-identified
analytics. It must only be enabled with (1) documented participant
authorization covering ID-linked web tracking, confirmed by the IRB or
privacy office, and (2) hosting permitted to hold such data under a BAA —
if the tracked site's own host does not qualify (Flywheel/WP Engine
prohibit it), the Collector URL setting must point at a Beacon install on
a host that does. Retention should be set to purge shortly after the
study ends. Exports, email reports, and Journeys contain the codes while
the data exists.
