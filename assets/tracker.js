/*
 * Beacon tracker — self-hosted, privacy-first. Small, dependency-free, no
 * cookies. The WordPress plugin prints it with data-site and data-endpoint
 * already filled in, so the browser only ever talks to your own collector.
 *
 * It sends only non-identifying page data. No IP is sent by JS (the server
 * sees it, hashes it for the daily visitor count, and discards it). The page
 * URL is sent with its query string so the server can keep the few keys the
 * admin allow-listed; the server drops everything else before storing. The
 * page title comes from the server (data-title), so search and error pages
 * never send the visitor's search terms.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) return;
  var site = script.getAttribute('data-site');
  var endpoint = script.getAttribute('data-endpoint');
  if (!site || !endpoint) return;

  // Per-tab session id (memory only, cleared when the tab closes). Not a
  // cookie, not stored, not durable across tabs.
  var sid = Math.random().toString(36).slice(2) + Date.now().toString(36);

  /*
   * Device context, deliberately COARSE so it can never fingerprint:
   *  - screen width is bucketed client-side (exact pixels never leave)
   *  - browser version is the MAJOR number only
   *  - OS version is the major number only, and only where browsers still
   *    expose it (mobile; desktop browsers hide it on purpose)
   */
  var sb = '';
  var w = window.screen ? window.screen.width : 0;
  if (w > 0) {
    sb = w < 480 ? 'mini' : w < 768 ? 'phone' : w < 1024 ? 'tablet' : w < 1440 ? 'laptop' : 'large';
  }

  var bv = 0, ov = '';
  try {
    var uad = navigator.userAgentData;
    if (uad && uad.brands) {
      for (var bi = 0; bi < uad.brands.length; bi++) {
        var b = uad.brands[bi];
        if (b.brand && !/not.a.brand/i.test(b.brand) && b.brand !== 'Chromium') {
          bv = parseInt(b.version, 10) || 0;
          break;
        }
      }
      if (!bv && uad.brands.length) bv = parseInt(uad.brands[0].version, 10) || 0;
      // High-entropy platform version, majors only. Windows maps 13+ -> 11.
      if (uad.getHighEntropyValues) {
        uad.getHighEntropyValues(['platformVersion']).then(function (h) {
          var major = parseInt(String(h.platformVersion || '').split('.')[0], 10);
          if (!isNaN(major)) {
            if (uad.platform === 'Windows') ov = major >= 13 ? '11' : (major > 0 ? '10' : '');
            else if (major > 0) ov = String(major);
          }
        }).catch(function () {});
      }
    } else {
      // UA-string fallbacks (Safari/Firefox): major versions only.
      var ua = navigator.userAgent;
      var m = ua.match(/(?:Version|Firefox|Chrome|CriOS|FxiOS|Edg)\/(\d+)/);
      if (m) bv = parseInt(m[1], 10) || 0;
      var os = ua.match(/(?:iPhone OS|CPU OS) (\d+)/) || ua.match(/Android (\d+)/);
      if (os) ov = os[1];
    }
  } catch (_) {}

  function send(payload) {
    payload.site = site;
    payload.sid = sid;
    var body = JSON.stringify(payload);
    // Sent as a plain string (text/plain) — a CORS-safelisted type, so an
    // external collector works without a preflight. The server parses the
    // body as JSON either way. sendBeacon survives page unload.
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(endpoint, body)) return;
    } catch (_) {}
    fetch(endpoint, { method: 'POST', body: body, keepalive: true })
      .catch(function () {});
  }

  // Path plus query. On search pages the server prints a generic path
  // (data-path) so the visitor's search words are never sent at all. The
  // collector strips the whole query except the keys the admin allow-listed.
  function pagePath() {
    var dp = script.getAttribute('data-path');
    return dp ? dp : location.pathname + location.search;
  }

  function pageview() {
    var nav = performance.getEntriesByType('navigation')[0];
    send({
      type: 'pageview',
      url: pagePath(),
      // Server-chosen title (generic on search and 404 pages); the server
      // checks it again before storing.
      title: script.hasAttribute('data-title') ? script.getAttribute('data-title') : document.title,
      ref: document.referrer || '',
      sb: sb,
      bv: bv,
      ov: ov,
      load: nav ? Math.round(nav.duration) : 0
    });
  }

  /*
   * Element label for click/hover events. Page content only, never input:
   * form fields are refused outright (their text is the visitor's answer),
   * and anything that looks like an email or phone number is dropped. The
   * server scrubs again before storing.
   */
  function labelFor(el) {
    if (!el || el.nodeType !== 1) return '';
    var tag = el.tagName.toLowerCase();
    // Button-style inputs are named by their value; every other form field
    // is refused (its text is the visitor's input, not page content).
    if (tag === 'input') {
      var t = (el.type || '').toLowerCase();
      if (t === 'submit' || t === 'button' || t === 'reset') {
        return String(el.value || '').replace(/\s+/g, ' ').trim().slice(0, 140);
      }
      return '';
    }
    if (tag === 'select' || tag === 'textarea' || tag === 'label') return '';
    var s = el.getAttribute('aria-label') || el.getAttribute('title') ||
            el.getAttribute('alt') || el.textContent || '';
    s = String(s).replace(/\s+/g, ' ').trim();
    // Image-only link or button: borrow the inner image's alt or title.
    if (!s && el.querySelector) {
      var img = el.querySelector('img[alt], img[title]');
      if (img) s = (img.getAttribute('alt') || img.getAttribute('title') || '').trim();
    }
    // Icon link with no name at all: fall back to where it points (path only).
    if (!s && tag === 'a' && el.href) {
      try { s = new URL(el.href).pathname; } catch (_) {}
    }
    s = String(s).replace(/\s+/g, ' ').trim().slice(0, 140);
    if (/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i.test(s)) return '';
    if (/(\+?\d[\d\s().-]{7,}\d)/.test(s)) return '';
    return s;
  }

  // Public API for custom events: window.beacon('signup_click', 'optional label')
  window.beacon = function (name, label) {
    var p = { type: 'event', name: String(name).slice(0, 120), url: pagePath() };
    if (label) p.label = String(label).slice(0, 140);
    send(p);
  };

  // Count outbound link clicks (host differs from ours). The label carries
  // the link's text/title/alt so the journey shows WHICH link was clicked.
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    try {
      var u = new URL(a.href);
      if (u.host && u.host !== location.host) {
        send({ type: 'outbound', name: u.host, label: labelFor(a),
               url: pagePath() });
      }
    } catch (_) {}
  }, true);

  // ---- Study code (OPT-IN via settings; window.BeaconStudyField) ----
  // The documented exception to "never read form fields": when the admin
  // names ONE input id in settings, the value the visitor SUBMITS in that
  // exact field is sent as this session's study_code. Nothing is read while
  // typing, no other field is ever readable, and the value must look like a
  // code (letters/digits/-/_ up to 32 chars) or it is dropped. This makes
  // the session identifiable to whoever holds the study's ID list — the
  // settings screen states the consent + hosting requirements.
  if (typeof window.BeaconStudyField === 'string' && window.BeaconStudyField) {
    var studySent = false;
    document.addEventListener('submit', function (e) {
      try {
        if (studySent) return;
        var f = e.target;
        var el = (f && f.querySelector) ? f.querySelector('#' + (window.CSS && CSS.escape ? CSS.escape(window.BeaconStudyField) : window.BeaconStudyField)) : null;
        if (!el) return; // the named field is not in this form
        var v = String(el.value || '').trim();
        if (!/^[A-Za-z0-9_-]{1,32}$/.test(v)) return; // not code-shaped: refuse
        studySent = true;
        send({ type: 'event', name: 'study_code', label: v,
               url: pagePath() });
      } catch (_) {}
    }, true);
  }

  // ---- Tag Manager: turn rules into first-party events ----
  // A rule can ONLY call window.beacon(event). It cannot load a URL, inject
  // HTML, fetch a script, or run arbitrary code. That is the whole guardrail.
  // If you are tempted to add a "custom script" or "url" rule type, don't —
  // that is exactly what makes GTM leak data.
  // Click/hover tag rules only count REAL interactions: the pointer must be
  // on (or inside) an interactive element. A misclick on empty page space
  // never becomes an event, and an event with no label is not sent at all.
  var INTERACTIVE = 'a,button,input,select,textarea,[role="button"],summary';

  function wireRules(rules) {
    if (!Array.isArray(rules)) return;
    rules.forEach(function (r) {
      if (!r || !r.event) return;
      var ev = String(r.event).slice(0, 120);
      if (r.trigger === 'click' && r.selector) {
        document.addEventListener('click', function (e) {
          // try/catch: a typo'd CSS selector must not throw on every click
          try {
            var hit = e.target.closest && e.target.closest(r.selector);
            if (!hit) return;
            // Inside a survey wrapper the change-based capture owns FORM
            // CONTROLS — a click rule must not double-fire on an answer. But
            // buttons and links in the form (Calculate, Reset) still count.
            if (typeof window.BeaconSurvey === 'string' && window.BeaconSurvey
                && e.target.closest(window.BeaconSurvey)
                && e.target.closest('input,select,textarea,label')) return;
            var control = e.target.closest(INTERACTIVE);
            if (!control) return; // dead-space misclick: not an event
            var lbl = labelFor(hit) || labelFor(control);
            if (!lbl) return; // no label, no event — keeps journeys readable
            window.beacon(ev, lbl);
          } catch (_) {}
        }, true);
      } else if (r.trigger === 'hover' && r.selector) {
        // Fire after the pointer rests 300ms on a match; once per element
        // per page load, so a busy mouse doesn't flood the collector.
        (function () {
          var timers = new WeakMap();
          var sent = new WeakSet();
          document.addEventListener('pointerover', function (e) {
            try {
              var el = e.target.closest && e.target.closest(r.selector);
              if (!el || sent.has(el) || timers.has(el)) return;
              if (!e.target.closest(INTERACTIVE)) return; // real controls only
              var lbl = labelFor(el) || labelFor(e.target.closest(INTERACTIVE));
              if (!lbl) return; // no label, no event
              timers.set(el, setTimeout(function () {
                timers.delete(el);
                sent.add(el);
                window.beacon(ev, lbl);
              }, 300));
            } catch (_) {}
          }, true);
          document.addEventListener('pointerout', function (e) {
            try {
              var el = e.target.closest && e.target.closest(r.selector);
              if (!el) return;
              var t = timers.get(el);
              if (t) { clearTimeout(t); timers.delete(el); }
            } catch (_) {}
          }, true);
        })();
      } else if (r.trigger === 'iframeclick' && r.selector) {
        // Clicks inside a cross-origin iframe (an embedded video player)
        // never reach this page — the browser walls them off. But it does
        // tell us when focus moves INTO a frame: this window fires blur and
        // document.activeElement becomes the iframe. That focus jump is, in
        // practice, "the visitor clicked the embed." Fires once per iframe
        // per page load. The label is the frame's title/aria-label (its
        // accessible name) or the video host — never anything from inside
        // the player, which this page cannot see anyway.
        (function () {
          var sent = new WeakSet();
          window.addEventListener('blur', function () {
            try {
              var el = document.activeElement;
              if (!el || el.tagName !== 'IFRAME' || sent.has(el)) return;
              if (!(el.matches && el.matches(r.selector))) return;
              var lbl = String(el.getAttribute('title') || el.getAttribute('aria-label') || '')
                .replace(/\s+/g, ' ').trim();
              if (!lbl && el.src) {
                try { lbl = new URL(el.src, location.href).host; } catch (_) {}
              }
              lbl = String(lbl || '').slice(0, 140);
              if (/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i.test(lbl)) lbl = '';
              if (!lbl) return; // no label, no event — same rule as click/hover
              sent.add(el);
              window.beacon(ev, lbl);
            } catch (_) {}
          });
        })();
      } else if (r.trigger === 'submit' && r.selector) {
        document.addEventListener('submit', function (e) {
          try {
            if (e.target.matches && e.target.matches(r.selector)) window.beacon(ev);
          } catch (_) {}
        }, true);
      } else if (r.trigger === 'scroll') {
        var depth = Math.min(100, Math.max(1, parseInt(r.depth, 10) || 50));
        var fired = false;
        window.addEventListener('scroll', function () {
          if (fired) return;
          var h = document.documentElement.scrollHeight;
          var pct = h ? ((window.scrollY + window.innerHeight) / h) * 100 : 0;
          if (pct >= depth) { fired = true; window.beacon(ev); }
        }, { passive: true });
      } else if (r.trigger === 'timer') {
        var secs = Math.min(3600, Math.max(1, parseInt(r.seconds, 10) || 30));
        setTimeout(function () { window.beacon(ev); }, secs * 1000);
      } else if (r.trigger === 'pageview') {
        if (!r.path || location.pathname.indexOf(r.path) === 0) window.beacon(ev);
      }
    });
  }

  // Rules are printed inline by the plugin as window.BeaconTags — no extra
  // network request needed.
  if (Array.isArray(window.BeaconTags)) wireRules(window.BeaconTags);

  // ---- Survey capture (OPT-IN via settings; window.BeaconSurvey) ----
  // The one deliberate exception to "never read form input": when the admin
  // sets a survey selector, a chosen answer is recorded together with its
  // question. Handles three shapes: radio scales (question wrapper holds a
  // .question and a sibling .responses box), compare checkboxes, and
  // dropdown selects. First-party, anonymous-session only, and off unless
  // the setting is filled in. Free-text inputs are NEVER read.
  if (typeof window.BeaconSurvey === 'string' && window.BeaconSurvey) {
    document.addEventListener('change', function (e) {
      try {
        var el = e.target;
        if (!el || !el.matches) return;
        var isRadio = el.matches('input[type="radio"]');
        var isCheck = el.matches('input[type="checkbox"]');
        var isSelect = el.matches('select');
        // Number inputs are handled on focusout below (one report per field
        // when the visitor moves on) — never here, where spinner arrows
        // would fire once per step (1, 2, 3...).
        if (!isRadio && !isCheck && !isSelect) return;
        var wrap = el.closest(window.BeaconSurvey);
        if (!wrap) return;

        var clean = function (s) { return String(s == null ? '' : s).replace(/\s+/g, ' ').trim(); };
        var ownLabel = function (input) {
          if (input.id) {
            var esc = window.CSS && CSS.escape ? CSS.escape(input.id) : input.id;
            var lab = document.querySelector('label[for="' + esc + '"]');
            if (lab) return clean(lab.textContent);
          }
          var pl = input.closest && input.closest('label');
          return pl ? clean(pl.textContent) : '';
        };

        var q = '', ans = '';
        if (isRadio) {
          // Find the question text CLOSEST to this radio, not just the first
          // heading in the wrapper — a wrapper can be a whole multi-question
          // form. Climb from the radio toward the wrapper; at each level take
          // the nearest legend/.question/heading/label that is not the
          // radio's own answer label.
          var node = el.parentElement;
          while (node && !q) {
            var cands = node.querySelectorAll('legend, .question, .questionText, h1, h2, h3, h4, h5, h6, p, label');
            for (var ci = 0; ci < cands.length; ci++) {
              var c = cands[ci];
              if (c.contains(el)) continue;               // wraps the radio itself
              if (c.htmlFor && c.htmlFor !== '') {
                var target = document.getElementById(c.htmlFor);
                if (target && target !== el && target.type === 'radio' && target.name === el.name) continue; // a sibling answer's label
                if (target === el) continue;
              }
              var txt = clean(c.textContent);
              if (txt) { q = txt; break; }
            }
            if (node === wrap) break;
            node = node.parentElement;
          }
          if (!q) q = clean(el.name);
          ans = ownLabel(el) || clean(el.value);
        } else if (isCheck) {
          // Compare-style checkbox: its own label IS the subject.
          q = ownLabel(el) || clean(el.name) || clean(el.id);
          ans = el.checked ? 'selected' : 'removed';
        } else if (isSelect) {
          // Dropdown: label[for], else name/id; answer is the chosen option.
          q = ownLabel(el) || clean(el.name) || clean(el.id) || 'selection';
          var opt = el.selectedIndex >= 0 ? el.options[el.selectedIndex] : null;
          ans = opt ? clean(opt.text) : '';
          if (!ans || /^--/.test(ans)) return; // ignore the "--Select--" reset
        }
        if (!q && !ans) return;

        var label = (q.slice(0, 90) + ' → ' + ans.slice(0, 45)).slice(0, 140);
        // Same PII refusals as element labels.
        if (/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i.test(label)) return;
        if (/(\+?\d[\d\s().-]{7,}\d)/.test(label)) return;
        window.beacon('survey_response', label);
      } catch (_) {}
    }, true);

    // Number fields report on FOCUSOUT — one event per field, with the
    // final value, only after the visitor moves on. Spinner steps and
    // half-typed values never report. STRICT: the field's id must be
    // listed in the Number overrides setting (window.BeaconNumRules).
    // Age-like fields ALWAYS cap at "90+" per HIPAA Safe Harbor.
    var numLastSent = new WeakMap();
    document.addEventListener('focusout', function (e) {
      try {
        var el = e.target;
        if (!el || !el.matches || !el.matches('input[type="number"]')) return;
        var wrap = el.closest(window.BeaconSurvey);
        if (!wrap) return;
        var rules = window.BeaconNumRules;
        if (!Array.isArray(rules) || !rules.length) return;

        var clean2 = function (s) { return String(s == null ? '' : s).replace(/\s+/g, ' ').trim(); };
        var idL = clean2(el.id).toLowerCase();
        var rule = null;
        for (var ri = 0; ri < rules.length; ri++) {
          var m = String(rules[ri].match || '').toLowerCase();
          if (m && idL === m) { rule = rules[ri]; break; }
        }
        if (!rule) return; // id not listed: never captured

        var v = parseFloat(el.value);
        if (isNaN(v) || v < 0) return;
        if (numLastSent.get(el) === el.value) return; // unchanged since last report

        var q = '';
        if (el.id) {
          var esc = window.CSS && CSS.escape ? CSS.escape(el.id) : el.id;
          var lab = document.querySelector('label[for="' + esc + '"]');
          if (lab) q = clean2(lab.textContent);
        }
        if (!q) q = clean2(el.name) || idL;

        var ans;
        // Same age rule the server enforces (it caps again regardless).
        // Field names count too: "patient_age" and "birthYear" become words.
        var words = (q + ' ' + idL).replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[_\-.\/]+/g, ' ');
        var isAge = /(^|[^a-z\u00C0-\u024F])(age|ages|how old|years? old|dob|date of birth|birth ?year|born|edad|cu[a\u00E1]ntos a[n\u00F1]os|a[n\u00F1]os|nacimiento|naci[o\u00F3]?)(?![a-z\u00C0-\u024F])/i.test(words);
        if (isAge && v >= 90) {
          ans = '90+'; // non-negotiable Safe Harbor cap
        } else if (rule.width > 0) {
          var lo = Math.floor(v / rule.width) * rule.width;
          ans = lo + '–' + (lo + rule.width - 1);
        } else {
          ans = String(v);
        }

        var label = (q.slice(0, 90) + ' → ' + String(ans).slice(0, 45)).slice(0, 140);
        if (/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i.test(label)) return;
        numLastSent.set(el, el.value);
        window.beacon('survey_response', label);
      } catch (_) {}
    }, true);
  }

  // First view. The setTimeout lets the load event finish first, so the
  // navigation timing duration is final instead of ~0.
  if (document.readyState === 'complete') pageview();
  else window.addEventListener('load', function () { setTimeout(pageview, 0); }, { once: true });
})();
