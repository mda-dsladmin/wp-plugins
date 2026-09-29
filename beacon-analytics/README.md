# Beacon Analytics

Self-hosted analytics and site-quality scanning that live entirely inside
this WordPress site. Built to replace Google Analytics and Google Tag
Manager on healthcare sites where visitor data must never reach a third
party.

One sentence for leadership: **Beacon shows us how our site is used and
whether our pages are healthy, and the data never leaves our own server.**

For the security team, read `docs/SECURITY-AND-PRIVACY.md` after this file.
It covers data flows, what is stored, what is never stored, and the HIPAA
reasoning in detail.

---

## Install

1. In wp-admin go to **Plugins → Add New → Upload Plugin**.
2. Upload the zip and activate it.
3. Go to **Beacon → Settings**, check **Enable tracking**, save.

On activation the plugin creates its own database tables, a site key, and a
secret hashing salt. Nothing is sent anywhere. Visits appear under
**Beacon → Dashboard** within a minute of the first tracked pageview.

Updating: upload the new zip over the old plugin. Database changes apply
automatically on the next page load. Data and settings are kept.

## The four screens

**Dashboard** - pageviews, unique visitors, sessions, pageviews in the last
30 minutes, a daily chart, top pages, entry pages, exit pages, referrers,
countries and US states (if the location database is installed), devices,
browsers and operating systems with major versions, screen-size buckets,
and custom events. Date ranges from 24 hours to 90 days. A chart
style switcher renders the daily series as bars or a line, and the
breakdown cards (devices, browsers, countries) as pies with a labeled
legend. With location data installed, a US map shades each state by
pageviews (darker red = more traffic; state level only, matching the
privacy rules). All charts are self-drawn SVG using a colorblind-checked
palette in both light and dark mode; no chart service is ever called.
Buttons: Export CSV, Print report (use "Save as PDF" in the print dialog
to make the manager-ready PDF), and a light/dark toggle.

**Journeys** - recent anonymous sessions. Click one to see its steps in
order: pages viewed, events fired, with timestamps. This is one visit by one
anonymous session. It is not possible to look up a person or follow a
visitor across days; the data model prevents it (see the security doc).
Funnels you define in Settings show here too, with how many sessions
complete each step in order.

**Site Scan** - crawls your own published pages on demand or weekly and
reports problems in six categories: privacy leaks (anything on a page that
calls a domain you don't own), broken links, SEO, accessibility (a static
subset, not a full audit), content quality, and your own policy rules.
Findings land in a fix queue ranked by severity and then by how much
traffic the page gets. Each finding is tracked across scans: new, still
open, fixed, or reopened. Buttons: Scan now, Export CSV, Clear scan results.

**Settings** - everything below.

## Settings reference

**Enable tracking** - master switch. Off means the plugin prints nothing on
the front end.

**Do not count logged-in users** - on by default so your own team doesn't
inflate the numbers.

**Collector URL** - leave blank and events are collected and stored by this
site (the default and the recommended setup). Enter a URL only if a
separately hosted Beacon collector should receive events instead; this site
then stores nothing.

**Site key** - generated automatically. The collector only accepts events
carrying this key.

**Tags** - the first-party tag manager. One rule per line:

    trigger | value | event_name

    click    | .et_pb_blurb_content | blurb_click
    hover    | .cta                 | cta_hover
    submit   | form#appt            | appt_form_submit
    scroll   | 50                   | scroll_50
    timer    | 30                   | engaged_30s
    pageview | /research            | viewed_research

Triggers: `click` and `hover` and `submit` take a CSS selector, `scroll`
takes a percent, `timer` takes seconds, `pageview` takes a path prefix.
Hover fires after the pointer rests 300ms on a match, once per element per
page load. Click and hover events also record the element's visible label
(a button caption, a blurb title, an image's alt text). Labels are page
content, never visitor input: form fields are refused as label sources, and
anything shaped like an email or phone number is dropped. A rule cannot
contain a URL or a script; lines with `http` or `<` are thrown away. That
is the guardrail that makes this tag manager unable to leak data the way
GTM does. Developers can also fire events in code:
`window.beacon('event_name', 'optional label')`.

**Query keys to keep** - query strings are stripped from stored URLs by
default. List safe keys (like `sl, utm_source`) to keep just those, so a
redirect landing on `/?sl=breast` shows as its own page. Values are still
digit-masked.

**Do Not Track** - when a visitor sends DNT or GPC: count the visit with no
visitor hash (default), or store nothing at all.

**Keep raw events for (days)** - automatic daily deletion of events older
than this window. Default 90.

**Survey capture (opt-in)** - blank by default, meaning survey answers are
never recorded. Enter the CSS selector(s) of your survey or form wrappers,
comma-separated (like `#risk-form, .quiz-item`) and each answer is stored
as "Question → Answer" on the `survey_response` event, visible in journeys
and the events table. Radio scales, compare checkboxes, and dropdowns are
captured automatically. Number fields are STRICT by default: never
captured unless you list them in **Number field overrides**, one per line
as `#input-id | width`. The field is the number input's id with a leading
`#`; the width sets the range size (5 records "50–54", 10 records "50–59",
`none` records the exact number). Bucketing happens in the visitor's
browser, so with a width set the exact typed value never leaves their
device. Any field that reads as age always caps at "90+" regardless of
the override, per the HIPAA Safe Harbor rule. Free-text fields are never
read. This is the only place Beacon stores visitor input.

**Funnels** - one per line: `name | step > step > step`. A step is a path
prefix or `event:event_name`. Results show on the Journeys screen.

**Visitor locations** - optional. Click Download and the server fetches the
free DB-IP Lite database (a file, stored locally). Lookups then happen on
this server and only country and US state are stored. City is never stored,
matching the HIPAA Safe Harbor standard. No visitor data is ever sent to a
geolocation service. Refresh the file every few months. The dashboard
footer shows the required "IP Geolocation by DB-IP" credit.

**Site scan settings** - weekly schedule on/off, max pages per scan,
whether to check outbound links (server-side HEAD requests with no visitor
data; can be turned off entirely), extra first-party domains the privacy
check should not flag, the stale-page threshold, and policy rules
(`pattern | severity | message`, regex allowed in /slashes/, same no-URL
no-script guardrail).

**Delete analytics data** - a button that erases all analytics rows
immediately. **Delete on uninstall** - a checkbox controlling whether
everything is removed when the plugin is deleted.

## Exports

- **Analytics CSV** (Dashboard): every table for the chosen range in one
  file, for Excel or Sheets — including survey responses (when capture is
  on) and funnel results.
- **Findings CSV** (Site Scan): the full fix queue.
- **PDF**: Print report on the Dashboard, then "Save as PDF" in the print
  dialog. The print layout drops the admin chrome and buttons and always
  prints in light mode.

All exports contain the same aggregates the dashboard shows. No visitor
hashes, no session IDs, no IPs (none are stored to begin with).

## What Beacon deliberately does NOT do

These are design choices, not missing features.

- No cookies, no fingerprinting, no durable visitor IDs. A visitor cannot
  be followed across days.
- No precise device detail. Browser and OS versions are stored as MAJOR
  versions only ("Chrome 143", never a full build string) and screen width
  as one of five ranges (exact pixels never leave the visitor's browser).
  Precise combinations are how fingerprinting works; coarse ones are shared
  by thousands of people.
- No third-party requests from any visitor's browser, ever.
- No IP addresses stored. No query strings stored (unless allow-listed).
  No city-level location, no full referrer URLs, no form input values.
- The tag manager cannot load a pixel, a script, or a URL.
- The scanner never logs in, never renders JavaScript, and never stores a
  full page.

## Hosting notes

Scans and cleanups run through WP-Cron. Hosts that run a real cron job
against `wp-cron.php` (Flywheel does) finish a 200-page scan in about 15
minutes. Data lives in the site's own MySQL database in tables prefixed
`wp_beacon_`.

## Updates

The plugin updates itself like any other WordPress plugin, from GitHub
Releases in github.com/mda-dsladmin/wp-plugins (declared in the plugin
header's Update URI line, so wordpress.org is never asked). WordPress shows
the normal one-click update on the Plugins screen when a newer release
exists, and auto-updates work too. Checks are cached for 12 hours; "Check
for updates" under the plugin on the Plugins screen, or Dashboard > Updates
> "Check again", checks right away.

Downloads are accepted only from that repo's release downloads, only for
plain version numbers (1.2.3), and the API request never follows redirects.
No token is needed (the repo is public); on busy shared hosting, add
`define('MDA_WP_PLUGINS_GITHUB_TOKEN', 'github_pat_...');` to wp-config.php
to raise GitHub's rate limit. A failed check (rate limit, outage) keeps
the last known result, so a pending update does not disappear. Publishing
a release = bump the version, merge to main, then on GitHub run Actions >
Release plugin > Run workflow and pick Beacon (see the repo's README and
docs/WP-PLUGINS-GUIDE.md).

Sites running 1.16.0 or older need 1.17.0 installed once by hand (Plugins >
Add New > Upload Plugin, then "Replace current with uploaded"). From then on
they update from GitHub. To install updates without clicking, turn on
"Enable auto-updates" for Beacon on the Plugins screen. The server must be
able to reach api.github.com, github.com and
release-assets.githubusercontent.com over HTTPS.

## Email reports

Beacon can email a report on a schedule — weekly up to yearly, several
schedules at once (every 2 weeks AND every quarter, for example). Set it
up in Beacon > Settings > Email reports: recipients (blank = the site
admin email; comma-separate several addresses), what to include
(analytics, site scan findings, funnels), and the attachment format
(Excel, PDF, or CSV). The email body shows the key totals; the
attachment carries every table. Files are generated on this server with
no outside service. Reports hold the same de-identified aggregates as
the dashboard — no visitor hashes, session IDs, or IPs. A "Send test
report now" button on the settings screen checks the whole path
end to end.

## Study code capture (identifiable — opt in, off by default)

For consented research studies only. When a study asks participants to
enter an assigned ID (like C001) in a login form, the "Study code" setting
can name that ONE input field by id. The value the participant submits is
then recorded as a study_code event, and the Journeys screen shows which
code each session belongs to. Codes must be short plain codes (letters,
numbers, dash, underscore, max 32 characters); anything else is refused by
both the tracker and the collector, and no other form field becomes
readable.

This is the one feature that makes Beacon's data identifiable: a study ID
points to an enrolled person through the study's own ID list. Before
enabling it, the site owner must have documented participant authorization
(consent covering ID-linked web tracking) confirmed by their IRB or
privacy office, and hosting that is permitted to hold identifiable health
data (a BAA-covered server; use the Collector URL setting to send events
to one if this site's host does not qualify). With the setting blank —
the default — none of this code path runs and the plugin's fully
de-identified posture is unchanged.
