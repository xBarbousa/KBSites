/* KB panels — swap #app content instead of full page reloads.
   Feels instant and keeps state light.

   Translation-safe: when a page translator (Google/Edge/Yandex) is active, partial
   innerHTML swaps make the translator re-run on every click, which flickers the layout
   (text grows/shrinks as each fragment is re-translated). In that case we fall back to a
   full navigation, so the browser loads and translates the WHOLE panel once per page —
   no flicker. Without a translator, we keep the snappy in-place swap.

   Progressive: if anything fails, it falls back to a normal navigation. */
(function () {
  var app = document.getElementById('app');
  if (!app || !window.history || !window.fetch || !window.DOMParser) return;

  // Is a browser page-translator currently active? (Then swaps flicker — use full nav.)
  function isTranslated() {
    try {
      var h = document.documentElement;
      if (h.classList.contains('translated-ltr') || h.classList.contains('translated-rtl')) return true;
      if (document.querySelector('.goog-te-combo, #goog-gt-tt, .skiptranslate, font[_msttexthash], ya-tr-span, [_msthash]')) return true;
      if (h.getAttribute('_msthash')) return true; // Edge/Bing translator marks <html>
    } catch (e) {}
    return false;
  }

  function swap(html, url) {
    try {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var na = doc.getElementById('app');
      if (!na) { window.location.href = url; return; }
      app.innerHTML = na.innerHTML;
      app.classList.remove('kb-swap'); void app.offsetWidth; app.classList.add('kb-swap'); // replay the fade-in
      if (doc.title) document.title = doc.title;
      window.scrollTo(0, 0);
      history.pushState(null, '', url);
      // let dynamic widgets (e.g. the live chat) re-initialise on the freshly-swapped DOM
      try { document.dispatchEvent(new Event('kb:swapped')); } catch (e) {}
    } catch (e) { window.location.href = url; }
  }

  // top-level section of a URL ("account", "ticket", "" for the homepage); null if it's another origin
  function section(u) {
    try {
      var x = new URL(u, window.location.href);
      if (x.origin !== window.location.origin) return null;
      return x.pathname.split('/')[1] || '';
    } catch (e) { return null; }
  }

  function go(url, opts) {
    var isGet = !opts || !opts.method || String(opts.method).toUpperCase() === 'GET';
    fetch(url, opts || { credentials: 'same-origin' })
      .then(function (r) { return r.text().then(function (t) { return { t: t, u: r.url || url }; }); })
      .then(function (o) {
        // Ended up outside this section (e.g. a redirect to "/" after login)? Don't trap it in #app:
        // do a real navigation. A GET is re-requested as-is so the browser keeps any #fragment the
        // redirect carries; a POST already ran, so just go where it landed.
        var here = section(window.location.href), there = section(o.u);
        if (there === null || there !== here) { window.location.href = isGet ? url : o.u; return; }
        swap(o.t, o.u);
      })
      .catch(function () { window.location.href = url; });
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a') : null;
    if (!a) return;
    var href = a.getAttribute('href');
    if (!href) return;
    if (a.target === '_blank' || a.hasAttribute('download')) return;
    if (/^(https?:|mailto:|tel:|#)/i.test(href)) return; // external / anchor -> normal nav
    if (/logout|seen_all/i.test(href)) return;           // let these fully navigate
    if (isTranslated()) return;                          // translating: full nav (no flicker)
    // only swap within the same top-level section (e.g. /account/*), else full nav
    var base = (location.pathname.split('/')[1] || '');
    var dest = (a.pathname.split('/')[1] || '');
    if (dest !== base) return;
    e.preventDefault();
    go(a.href);
  });

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || String(f.getAttribute('method') || '').toLowerCase() !== 'post') return;
    if (e.defaultPrevented) return; // an inline onsubmit (e.g. a confirm() the user cancelled) already stopped it
    if (isTranslated()) return;     // translating: let the form submit normally (full nav, no flicker)
    e.preventDefault();
    // NB: use getAttribute('action') — f.action is shadowed by a field named "action".
    var act = f.getAttribute('action');
    go(act || window.location.href, { method: 'POST', body: new FormData(f), credentials: 'same-origin' });
  });

  window.addEventListener('popstate', function () {
    if (isTranslated()) { window.location.reload(); return; }
    go(window.location.href);
  });
})();
