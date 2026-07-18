(function () {
  'use strict';

  if (typeof VT === 'undefined' || !VT.ajaxurl || !VT.nonce) return;

  var state = {
    sessionId:  null,
    pageviewId: null,
    nonce:      VT.nonce,
    ajaxurl:    VT.ajaxurl,
    loadedAt:   Date.now(),
  };

  // ── URL param helpers ─────────────────────────────────────────────────────

  function getParam(name) {
    var match = new RegExp('[?&]' + name + '=([^&#]*)').exec(window.location.search);
    return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
  }

  // ── Class filtering ───────────────────────────────────────────────────────
  // Strips layout/utility classes (Tailwind, Elementor, etc.) so only
  // meaningful semantic class names get stored in the events table.

  var SKIP_PREFIXES = [
    'elementor-', 'eael-', 'fl-', 'vc_', 'wpb_', 'fusion-', 'et_',
    'divi-', 'gdlr-', 'ppress-', 'uagb-', 'e-',
    'text-', 'bg-', 'px-', 'py-', 'pt-', 'pb-', 'pl-', 'pr-',
    'mx-', 'my-', 'mt-', 'mb-', 'ml-', 'mr-', 'p-', 'm-',
    'w-', 'h-', 'min-', 'max-', 'flex-', 'grid-', 'gap-', 'col-', 'row-',
    'border-', 'rounded-', 'shadow-', 'font-', 'leading-', 'tracking-',
    'opacity-', 'z-', 'top-', 'left-', 'right-', 'bottom-',
    'hover:', 'focus:', 'active:', 'sm:', 'md:', 'lg:', 'xl:', '2xl:', 'dark:',
    'group-', 'peer-', 'transition-', 'duration-', 'ease-', 'animate-',
  ];

  var SKIP_EXACT = [
    'active','open','show','hide','visible','hidden','disabled','current',
    'selected','is-active','is-open','flex','block','inline','grid',
    'relative','absolute','sticky','fixed','sr-only','clearfix',
  ];

  function filterClasses(raw) {
    if (!raw || typeof raw !== 'string') return '';
    var kept = [], seen = {}, parts = raw.split(/\s+/);
    for (var i = 0; i < parts.length; i++) {
      var cls = parts[i];
      if (!cls || cls.length < 2 || cls.length > 50 || seen[cls]) continue;
      if (SKIP_EXACT.indexOf(cls) !== -1) continue;
      var skip = false;
      for (var j = 0; j < SKIP_PREFIXES.length; j++) {
        if (cls.indexOf(SKIP_PREFIXES[j]) === 0) { skip = true; break; }
      }
      if (skip) continue;
      seen[cls] = true;
      kept.push(cls);
      if (kept.length >= 4) break;
    }
    return kept.join(' ');
  }

  function getClasses(el) {
    var raw = el.className || '';
    if (typeof raw === 'object') raw = raw.toString();
    var result = filterClasses(raw);
    if (!result && el.parentElement) {
      var pRaw = el.parentElement.className || '';
      if (typeof pRaw === 'object') pRaw = pRaw.toString();
      result = filterClasses(pRaw);
    }
    return result;
  }

  // ── Section detection ─────────────────────────────────────────────────────
  // Identifies the container a clicked element lives in, so clicks can be
  // told apart by where on the page they happened (not just id/class/text,
  // which page builders often repeat across sections). Cost is bounded by
  // DOM depth (ancestor walk), not by how many elements share the section —
  // a section with hundreds of children is just as cheap as one with a few.

  function getSectionLabel(el) {
    var container = el.closest('section')
      || el.closest('[role="region"], [role="main"]')
      || el.closest('[id]');

    if (!container) return '';
    if (container.id) return container.id;

    var heading = container.querySelector('h1, h2, h3, h4');
    if (heading) {
      var text = (heading.innerText || '').trim().replace(/\s+/g, ' ');
      if (text) return text.substring(0, 60);
    }

    return '';
  }

  // ── Pageview ──────────────────────────────────────────────────────────────

  function logPageview() {
    // Read URL params directly from the current URL as ground truth
    var tag         = getParam('tt')           || (VT.tag          || '');
    var utm_source  = getParam('utm_source')   || (VT.utms && VT.utms.source   || '');
    var utm_medium  = getParam('utm_medium')   || (VT.utms && VT.utms.medium   || '');
    var utm_campaign= getParam('utm_campaign') || (VT.utms && VT.utms.campaign || '');
    var utm_term    = getParam('utm_term')     || (VT.utms && VT.utms.term     || '');
    var utm_content = getParam('utm_content')  || (VT.utms && VT.utms.content  || '');

    fetch(state.ajaxurl + '?action=vt_pageview', {
      method:      'POST',
      headers:     { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        nonce:        state.nonce,
        url:          window.location.href,
        title:        document.title,
        referrer:     document.referrer || '',
        tag:          tag,
        utm_source:   utm_source,
        utm_medium:   utm_medium,
        utm_campaign: utm_campaign,
        utm_term:     utm_term,
        utm_content:  utm_content,
      }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.success && data.data) {
        state.sessionId  = data.data.session_id;
        state.pageviewId = data.data.pageview_id;
      }
    })
    .catch(function () {});
  }

  // ── Click tracking ────────────────────────────────────────────────────────

  document.addEventListener('click', function (e) {
    if (!state.sessionId || !state.pageviewId) return;

    var el = e.target.closest('a, button, input[type="submit"], [data-vp-label]');
    if (!el) return;

    var text = (el.innerText || el.value || el.getAttribute('aria-label') || '').trim();
    text = text.replace(/\s+/g, ' ').substring(0, 120);

    var payload = JSON.stringify({
      nonce:       state.nonce,
      session_id:  state.sessionId,
      pageview_id: state.pageviewId,
      type:        'click',
      elTag:       (el.tagName || '').toLowerCase(),
      text:        text,
      elId:        el.id || '',
      classes:     getClasses(el),
      href:        el.href || '',
      label:       (el.dataset && el.dataset.vpLabel) ? el.dataset.vpLabel : '',
      section:     getSectionLabel(el),
    });

    // sendBeacon is fire-and-forget, perfect for clicks (especially before navigation)
    if (navigator.sendBeacon) {
      navigator.sendBeacon(
        state.ajaxurl + '?action=vt_event',
        new Blob([payload], { type: 'application/json' })
      );
    } else {
      fetch(state.ajaxurl + '?action=vt_event', {
        method:    'POST',
        headers:   { 'Content-Type': 'application/json' },
        body:      payload,
        keepalive: true,
      }).catch(function () {});
    }
  }, true);

  // ── Time on page ──────────────────────────────────────────────────────────
  // Sent once, right as the page is being left, via sendBeacon (the
  // purpose-built API for "fire this off, don't block unload, don't need
  // the page to stay alive"). Real browsers reliably fire 'pagehide' on
  // navigation/tab-close; simple scripted bots issuing raw HTTP requests
  // typically never do — so a session with no recorded durations across
  // multiple pageviews is itself a useful (not conclusive) signal.

  function sendDuration() {
    if (!state.pageviewId || !navigator.sendBeacon) return;

    var duration = Math.round((Date.now() - state.loadedAt) / 1000);
    if (duration <= 0) return;

    var payload = JSON.stringify({
      nonce:       state.nonce,
      pageview_id: state.pageviewId,
      duration:    duration,
    });

    navigator.sendBeacon(
      state.ajaxurl + '?action=vt_duration',
      new Blob([payload], { type: 'application/json' })
    );
  }

  window.addEventListener('pagehide', sendDuration);

  // ── Boot ──────────────────────────────────────────────────────────────────

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', logPageview);
  } else {
    logPageview();
  }

})();
