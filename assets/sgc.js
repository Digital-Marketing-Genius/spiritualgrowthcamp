/* School of Spiritual Growth: shared page behaviour (reveal on scroll, marquee, API helper). */
(function () {
  var RM = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Marquee of the five pillars
  var track = document.querySelector('.track');
  if (track) {
    var one = ['Discipleship', 'Prayer', 'Intimacy', 'Faith', 'Transformation']
      .map(function (w) { return '<span>' + w + '<i></i></span>'; }).join('');
    track.innerHTML = one + one + one + one;
  }

  // Reveal: only elements below the first screen start hidden
  if (!RM && 'IntersectionObserver' in window) {
    var els = [].slice.call(document.querySelectorAll('.rv'));
    els.forEach(function (el) { if (el.getBoundingClientRect().top > innerHeight * 0.9) el.classList.add('pre'); });
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.remove('pre'); io.unobserve(e.target); } });
    }, { rootMargin: '0px 0px -8% 0px' });
    els.forEach(function (el) { io.observe(el); });
  }

  // Next Bootcamp cohort: January 15, May 15, September 15
  window.SGC_nextCohort = function () {
    var dates = [[0, 15], [4, 15], [8, 15]];
    var now = new Date(); var y = now.getFullYear();
    var today = new Date(y, now.getMonth(), now.getDate());
    for (var i = 0; i < dates.length; i++) {
      var d = new Date(y, dates[i][0], dates[i][1]);
      if (today < d) return d;
    }
    return new Date(y + 1, 0, 15);
  };
  window.SGC_fmtDate = function (d) {
    return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
  };
  // Cohort number: 1 = January, 2 = May, 3 = September (matches sgc_cohort() in api/_lib.php)
  window.SGC_cohort = function () {
    var d = SGC_nextCohort(), n = [0, 4, 8].indexOf(d.getMonth()) + 1;
    var month = d.toLocaleDateString('en-US', { month: 'long' });
    return { number: n, date: d, label: 'Cohort ' + n + ', ' + month + ' ' + d.getFullYear(), short: 'Cohort ' + n + ' ' + d.getFullYear() };
  };
  document.querySelectorAll('[data-next-cohort]').forEach(function (el) {
    el.textContent = SGC_fmtDate(SGC_nextCohort());
  });
  document.querySelectorAll('[data-cohort]').forEach(function (el) {
    var c = SGC_cohort(); el.textContent = el.getAttribute('data-cohort') === 'short' ? c.short : c.label;
  });

  // POST JSON to our API and go to Stripe Checkout
  window.SGC_startCheckout = function (endpoint, payload, button, errorEl) {
    if (button) button.classList.add('busy');
    if (errorEl) { errorEl.hidden = true; errorEl.textContent = ''; }
    return fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, d: d }; });
    }).then(function (res) {
      if (res.ok && res.d.url) { window.location.href = res.d.url; return; }
      throw new Error(res.d.error || 'Something went wrong. Please try again.');
    }).catch(function (err) {
      if (button) button.classList.remove('busy');
      var msg = (err && err.message && err.message !== 'Failed to fetch') ? err.message : 'We could not reach the server. Check your connection and try again.';
      if (errorEl) { errorEl.textContent = msg; errorEl.hidden = false; errorEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    });
  };

  // "?canceled=1" banner after leaving Stripe Checkout
  if (/[?&]canceled=1/.test(location.search)) {
    var c = document.querySelector('[data-canceled]');
    if (c) c.hidden = false;
  }
})();
