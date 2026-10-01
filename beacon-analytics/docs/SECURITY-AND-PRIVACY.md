# Beacon Analytics — Security and Privacy Review Document

Written for security reviewers and compliance staff. Everything here can be
verified in the source; file names are given so a reviewer can check each
claim. Plain-language summaries come first, mechanics after.

Version: 1.9.0. Roughly 4,000 lines of PHP/JS, no third-party libraries,
no build step, no external services.

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
| path | /brst | query string stripped; 5+ digit runs masked |
| title | Breast Pathway | page title, truncated |
| referrer_host | google.com | host only, never the full URL |
| visitor_day_hash | sha256(...) | rotates daily, see §4 |
| session_id | sha256(...) 32 chars | one visit, dies with the tab |
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

- **Visitor hash**: sha256 of (secret salt + today's local date + IP +
  user agent). The IP is used in memory and discarded
  (includes/collector.php, includes/privacy.php). Because the date is in
  the hash, the same visitor produces an unlinkable new value every day.
  It answers "how many unique visitors today" and nothing else. It cannot
  be reversed, and two days of data cannot be joined on it.
- **Session ID**: hash of a random per-tab value plus the visitor hash.
  Links the steps of one visit so journeys and funnels work. Not a cookie;
  it lives in tab memory and is gone when the tab closes.
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
   explicitly allow-lists (for shortlink params like `sl`); kept values
   are still digit-masked and truncated.
2. Any run of 5 or more digits anywhere in a path or kept value becomes
   `[redacted]` (an MRN-shaped backstop).
3. Referrers are reduced to hostname only.
4. Event labels (element captions from click/hover tags): the browser
   refuses to read labels from input, select, textarea, or label elements,
   drops email-shaped and phone-shaped strings, and the server repeats
   both checks plus digit masking before storing (defense in depth,
   tracker.js + privacy.php).
5. Everything is length-clamped and passed through WordPress sanitizers.
6. **Survey capture is the one deliberate exception**, and it is opt-in.
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
   least-preferred option. Fields whose label reads as age always cap at
   "90+" regardless of the override, per the Safe Harbor rule that ages
   over 89 must be aggregated (45 CFR 164.514(b)(2)). Free-text fields are
   still never read at all.

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
evidence that no page leaks to a third party. Outbound link checking sends
server-side HEAD requests carrying no visitor data and can be disabled.
The scanner never authenticates, so it can never see patient-facing pages.

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
- **Collector hardening**: 32-hex site key compared with hash_equals;
  per-IP per-minute rate limit (filterable client IP for proxied hosts);
  cross-site Origin headers rejected; strict allow-list validation of
  every field; responds 204 and never echoes input.
- **CSV exports**: cells starting with = + - @ are apostrophe-prefixed so
  a visitor-planted path or label cannot become an executing formula in
  Excel (includes/admin/export.php).
- **Secrets**: the hashing salt is 64 hex chars, generated on activation,
  never displayed, stored unautoloaded in wp_options.
- **Uninstall**: data is kept unless the admin opted into deletion;
  scheduled jobs are always cleared.

## 10. Complete list of outbound network calls

Requests the SERVER makes (never the visitor's browser):

1. Fetching this site's own pages during a scan (loopback).
2. HEAD/GET checks of outbound links during a scan, if enabled. No
   visitor data attached. Off by one checkbox.
3. The one-time/manual DB-IP database file download, admin-initiated.

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
- **web server logs**: independent of Beacon, the web server itself logs
  raw visitor IPs and requested URLs, as on every website. A full privacy
  review of the site should include host log retention.

## Study code capture (v1.16.0): the documented identifiability exception

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
- Strict shape gate at BOTH ends: the tracker sends, and the collector
  stores, only values matching `^[A-Za-z0-9_-]{1,32}$`. Emails, phone
  numbers, and free text cannot fit the pattern and are dropped.
- The label bypasses digit masking (an ID like C00123 must survive), which
  is safe precisely because of the shape gate.

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
