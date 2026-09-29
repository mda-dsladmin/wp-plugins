/*
 * Beacon admin JS: the theme toggle (light by default; the choice persists
 * in localStorage), two confirm() guards on destructive buttons, the print
 * button, and the scan progress poller. Everything else on these screens is
 * server-rendered and works with JavaScript off.
 */
(function () {
  'use strict';

  var app = document.getElementById('beacon-app');
  var btn = document.getElementById('beacon-theme');
  if (!app || !btn) return;

  // While a scan runs, poll the progress endpoint and update the live
  // status line IN PLACE (no reload, so focus, scroll, and screen-reader
  // position all survive). One reload happens when the scan finishes, to
  // render the new findings.
  if (app.hasAttribute('data-scan-running')) {
    var progressUrl = app.getAttribute('data-progress-url');
    var progressEl = document.getElementById('beacon-scan-progress');
    var poll = setInterval(function () {
      fetch(progressUrl, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.success) return;
          if (d.data.running) {
            if (progressEl) {
              progressEl.textContent =
                'Scanning… ' + d.data.scanned + ' of ' + d.data.total + ' pages done.';
            }
          } else {
            clearInterval(poll);
            window.location.reload();
          }
        })
        .catch(function () {});
    }, 10000);
  }

  // Light is the default no matter the OS setting; only the saved toggle
  // choice switches to dark.
  function isDark() {
    return app.getAttribute('data-theme') === 'dark';
  }

  function sync() {
    btn.setAttribute('aria-pressed', String(isDark()));
  }

  var saved = null;
  try { saved = window.localStorage.getItem('beaconTheme'); } catch (_) {}
  if (saved === 'dark' || saved === 'light') {
    app.setAttribute('data-theme', saved);
  }
  sync();

  // Clearing scan results erases the fix queue and its open/fixed/ignored
  // history — worth one confirmation. Without JS the form still submits.
  var clearForm = document.getElementById('beacon-clear-form');
  if (clearForm) {
    clearForm.addEventListener('submit', function (e) {
      if (!window.confirm('Clear all scan results? This erases the fix queue, its fixed/ignored history, and scan runs. Analytics data is not affected.')) {
        e.preventDefault();
      }
    });
  }

  var clearAnalytics = document.getElementById('beacon-clear-analytics-form');
  if (clearAnalytics) {
    clearAnalytics.addEventListener('submit', function (e) {
      if (!window.confirm('Delete ALL analytics data? Every pageview, visitor count, and event is erased permanently. This cannot be undone.')) {
        e.preventDefault();
      }
    });
  }

  // Print report: the print stylesheet turns the dashboard into a clean
  // one-pager; "Save as PDF" in the print dialog is the manager handoff.
  var printBtn = document.getElementById('beacon-print');
  if (printBtn) {
    printBtn.addEventListener('click', function () { window.print(); });
  }

  btn.addEventListener('click', function () {
    var next = isDark() ? 'light' : 'dark';
    app.setAttribute('data-theme', next);
    try { window.localStorage.setItem('beaconTheme', next); } catch (_) {}
    sync();
  });
})();
