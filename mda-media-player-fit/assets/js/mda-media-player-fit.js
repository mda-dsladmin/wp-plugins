/*!
 * MDA Media Player Fit 1.1.0
 * Keeps matched iframes at 100% of their container width in a fixed aspect
 * ratio by updating their width and height attributes (not CSS sizing; the
 * only style set is a max-width: 100% safety cap).
 */
(function () {
	'use strict';

	var cfg = window.mdaMediaPlayerFit || {};
	var SELECTOR = cfg.selector || 'iframe';
	var RATIO = (cfg.ratioH || 9) / (cfg.ratioW || 16);
	var FALLBACK_TITLE = cfg.fallbackTitle || '';
	var MDA_ENABLED = !!cfg.mdaPlayer;

	// Very old browsers keep the embed's own size.
	if (typeof window.ResizeObserver === 'undefined') {
		return;
	}

	/*
	 * MD Anderson media player (mediaplayer.mdanderson.org/video-compact/<id>/<N>):
	 * the last number in the URL sets the player HEIGHT (N x 9/16), and the width
	 * just fills the frame. Keep N in step with the frame height so the player is
	 * not cut off. Changing src reloads the player, so resizes wait until the
	 * resizing stops, and nothing reloads while a video is fullscreen.
	 */
	var MDA_PLAYER = /^(https:\/\/mediaplayer\.mdanderson\.org\/video-compact\/[^\/?#]+\/)(\d+)(.*)$/;
	var RESIZE_WAIT = 300;

	/*
	 * The player's own CSS sets min-height: 220px on the video, so below about
	 * 391px wide (phones) a true 16:9 frame is shorter than the player and the
	 * bottom (the controls) gets cut off. For this player, the frame is never
	 * shorter than 220px. The URL number also acts as a minimum width, so it is
	 * capped at the frame width.
	 */
	var MDA_MIN_HEIGHT = 220;

	function isMdaPlayer(iframe) {
		return MDA_ENABLED && MDA_PLAYER.test(iframe.getAttribute('src') || '');
	}

	var watched = new Set();
	var parents = new Map();      // iframe -> the parent being observed
	var widthHistory = new WeakMap(); // iframe -> [previous width, current width]
	var srcTimers = new WeakMap();

	function inFullscreen() {
		return !!(document.fullscreenElement || document.webkitFullscreenElement);
	}

	function syncMdaPlayer(iframe, width, height, immediate) {
		var n = Math.min(Math.round(height * 16 / 9), width);

		function apply() {
			var m = (iframe.getAttribute('src') || '').match(MDA_PLAYER);
			if (!m || m[2] === String(n) || inFullscreen()) {
				return;
			}
			iframe.setAttribute('src', m[1] + n + m[3]);
		}

		clearTimeout(srcTimers.get(iframe));
		if (immediate) {
			apply();
		} else {
			srcTimers.set(iframe, setTimeout(apply, RESIZE_WAIT));
		}
	}

	function sizeFrame(iframe, immediate) {
		var box = iframe.parentElement;
		if (!box) {
			return;
		}
		var cs = window.getComputedStyle(box);
		var inner = box.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
		var width = inner;

		// The width attribute sets the frame's inner width. Its own border and
		// padding (the browser default is a 2px border) come on top, so take
		// them off, or the frame pokes out of its box. In a box that sizes to
		// its content (table cell, grid or flex item) that overflow would also
		// widen the box, then the frame, again and again.
		var fs = window.getComputedStyle(iframe);
		if (fs.boxSizing !== 'border-box') {
			width -= (parseFloat(fs.borderLeftWidth) || 0) + (parseFloat(fs.borderRightWidth) || 0)
				+ (parseFloat(fs.paddingLeft) || 0) + (parseFloat(fs.paddingRight) || 0);
		}
		// Side margins come off too. Inline frames (the default): both sides,
		// as they are. Block frames: the browser reports the far-side margin
		// as whatever space is left over, so it can't be trusted; use the
		// near-side margin for both sides. But "auto" (centering) margins
		// must not come off at all: they shrink as the frame grows. To tell
		// them apart, shrink the frame to 0 for a moment (no repaint happens
		// in between) and see whether the margin moves.
		var rtl = fs.direction === 'rtl';
		if (fs.display === 'block') {
			var nearProp = rtl ? 'marginRight' : 'marginLeft';
			var near = parseFloat(fs[nearProp]) || 0;
			if (near > 0) {
				var saved = iframe.getAttribute('width');
				iframe.setAttribute('width', '0');
				var probe = parseFloat(window.getComputedStyle(iframe)[nearProp]) || 0;
				if (saved === null) {
					iframe.removeAttribute('width');
				} else {
					iframe.setAttribute('width', saved);
				}
				if (Math.abs(probe - near) < 1) {
					width -= 2 * near; // fixed margins
				}
			}
		} else {
			width -= Math.max(0, parseFloat(fs.marginLeft) || 0) + Math.max(0, parseFloat(fs.marginRight) || 0);
		}
		// A theme's fixed max-width (e.g. 500px) wins, so the height matches
		// the width the frame really gets.
		if (/px$/.test(fs.maxWidth)) {
			var cap = parseFloat(fs.maxWidth);
			if (fs.boxSizing === 'border-box') {
				cap -= (parseFloat(fs.borderLeftWidth) || 0) + (parseFloat(fs.borderRightWidth) || 0)
					+ (parseFloat(fs.paddingLeft) || 0) + (parseFloat(fs.paddingRight) || 0);
			}
			if (cap > 0 && cap < width) {
				width = cap;
			}
		}
		width = Math.floor(width);
		if (!(width > 0)) {
			return; // Hidden (closed tab or accordion). Sized when it shows.
		}

		// Scrollbar flip-flop guard: a taller frame can add a page scrollbar,
		// which narrows the parent, which shortens the frame, which removes the
		// scrollbar, and so on. If the width just flips back to where it was
		// (within 20px), settle on the narrower width, which fits either way.
		var hist = widthHistory.get(iframe);
		if (!immediate && hist && width === hist[0] && Math.abs(width - hist[1]) <= 20) {
			width = Math.min(width, hist[1]);
		}

		if (!hist || hist[1] !== width) {
			widthHistory.set(iframe, [hist ? hist[1] : width, width]);
		}

		if (iframe.getAttribute('width') !== String(width)) {
			// Runaway guard: some boxes size themselves to their content (table
			// cells, some grid and flex items). Measure the box right before
			// and right after our own change. Nothing else can run in between,
			// so any widening was caused by the frame: take it back off.
			var before = box.clientWidth;
			iframe.setAttribute('width', width);
			var grew = box.clientWidth - before;
			if (grew > 0 && width - grew > 0) {
				width -= grew;
				iframe.setAttribute('width', width);
				widthHistory.set(iframe, [width, width]);
			}
		}

		var mda = isMdaPlayer(iframe);
		var height = Math.round(width * RATIO);
		if (mda && height < MDA_MIN_HEIGHT) {
			height = MDA_MIN_HEIGHT;
		}
		if (iframe.getAttribute('height') !== String(height)) {
			iframe.setAttribute('height', height);
		}
		if (mda) {
			syncMdaPlayer(iframe, width, height, immediate);
		}
	}

	var ro = new ResizeObserver(function (entries) {
		entries.forEach(function (entry) {
			watched.forEach(function (iframe) {
				if (iframe.parentElement === entry.target) {
					sizeFrame(iframe, false);
				}
			});
		});
	});

	function addFallbackTitle(iframe) {
		if (FALLBACK_TITLE && !(iframe.getAttribute('title') || '').trim()) {
			iframe.setAttribute('title', FALLBACK_TITLE);
		}
	}

	function watch(iframe) {
		if (watched.has(iframe) || iframe.hasAttribute('data-mdampf-skip')) {
			return;
		}
		watched.add(iframe);
		addFallbackTitle(iframe);
		// Safety cap: a box that sizes itself to its content (a table cell)
		// can't report that it should shrink while the frame holds it open.
		// Letting the frame never exceed its box fixes that; a theme's own
		// max-width is left alone.
		if (!iframe.style.maxWidth) {
			iframe.style.maxWidth = '100%';
		}
		sizeFrame(iframe, true);
		if (iframe.parentElement) {
			parents.set(iframe, iframe.parentElement);
			ro.observe(iframe.parentElement);
		}
	}

	function scan() {
		var found;
		try {
			found = document.querySelectorAll(SELECTOR);
		} catch (err) {
			if (window.console) {
				window.console.warn('MDA Media Player Fit: invalid selector in settings:', SELECTOR);
			}
			return false;
		}
		for (var i = 0; i < found.length; i++) {
			if (found[i].tagName === 'IFRAME') {
				watch(found[i]);
			}
		}
		// Forget iframes that were removed from the page, and stop watching
		// their parents once no remaining iframe uses them.
		watched.forEach(function (iframe) {
			if (!document.documentElement.contains(iframe)) {
				var parent = parents.get(iframe);
				watched.delete(iframe);
				parents.delete(iframe);
				var stillUsed = false;
				parents.forEach(function (p) {
					if (p === parent) {
						stillUsed = true;
					}
				});
				if (parent && !stillUsed) {
					ro.unobserve(parent);
				}
			}
		});
		return true;
	}

	function init() {
		if (!scan()) {
			return;
		}
		// A real window resize is the user's doing: forget the flip-flop history.
		window.addEventListener('resize', function () {
			watched.forEach(function (iframe) {
				widthHistory.delete(iframe);
			});
		});
		// Catch iframes added after page load, at most once per frame.
		var queued = false;
		new MutationObserver(function () {
			if (queued) {
				return;
			}
			queued = true;
			window.requestAnimationFrame(function () {
				queued = false;
				scan();
			});
		}).observe(document.body, { childList: true, subtree: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
