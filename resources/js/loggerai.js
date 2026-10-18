/*! LoggerAI browser sessions v0.1.0 · https://loggerai.uk
 * Records what happens on the page (pages, clicks, requests, errors) and links
 * it to the server requests it causes. Never records what people type.
 * Configured by the <script> tag's data-* attributes (the Laravel package
 * writes them): data-key (public key), data-endpoint, data-trace,
 * data-trace-hosts, data-report-button, data-release.
 */
(function () {
  "use strict";
  if (window.__loggerai) return;
  var script = document.currentScript || document.querySelector('script[src*="loggerai.js"]');
  if (!script) return;
  var cfg = script.dataset || {};
  var KEY = cfg.key;
  var ENDPOINT = cfg.endpoint;
  if (!KEY || !ENDPOINT || KEY.indexOf("pk_") !== 0) return;
  window.__loggerai = { version: "0.1.0" };

  var MASK = "[REDACTED]";
  var MAX_EVENTS = 300;
  var FLUSH_MS = 5000;
  var traceHosts = (cfg.traceHosts || "").split(",").map(function (h) { return h.trim().toLowerCase(); }).filter(Boolean);

  // ---- ids ------------------------------------------------------------------
  function hex(n) {
    var a = new Uint8Array(n);
    (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ("0" + b.toString(16)).slice(-2); }).join("");
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var h = hex(16);
    return h.slice(0, 8) + "-" + h.slice(8, 12) + "-4" + h.slice(13, 16) + "-a" + h.slice(17, 20) + "-" + h.slice(20, 32);
  }
  var store = {
    get: function (k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} },
  };
  var sessionId = store.get("loggerai_session") || uuid();
  var startedAt = Number(store.get("loggerai_started")) || Date.now() / 1000;
  store.set("loggerai_session", sessionId);
  store.set("loggerai_started", String(startedAt));

  // ---- redaction (same rules as the server) -------------------------------------
  var WORDS = { password: 1, passwd: 1, pwd: 1, pass: 1, passphrase: 1, secret: 1, token: 1, otp: 1, pin: 1, cvv: 1, cvc: 1, ssn: 1, cookie: 1,
    authorization: 1, auth: 1, signature: 1, sig: 1, credential: 1, credentials: 1, bvn: 1, nin: 1 };
  var JOINED = ["apikey", "accesskey", "privatekey", "secretkey", "cardnumber", "clientsecret", "sessionid", "authtoken", "accesstoken",
    "refreshtoken", "idtoken", "csrftoken", "xsrf", "setcookie"];
  var URL_EXTRA = { key: 1, code: 1, access_token: 1, id_token: 1 };
  function sensitive(key) {
    if (typeof key !== "string" || !key) return false;
    var words = key.split(/[^A-Za-z0-9]+|(?=[A-Z][a-z])/).filter(Boolean).map(function (w) { return w.toLowerCase(); });
    for (var i = 0; i < words.length; i++) if (WORDS[words[i]]) return true;
    var joined = words.join("");
    for (var j = 0; j < JOINED.length; j++) if (joined.indexOf(JOINED[j]) >= 0) return true;
    return false;
  }
  function scrub(s) {
    return String(s)
      .replace(/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/g, "[REDACTED_JWT]")
      .replace(/\b(bearer|basic|token)\s+[A-Za-z0-9\-._~+/]{12,}=*/gi, function (_, k) { return k + " " + MASK; });
  }
  function cleanUrl(u) {
    try {
      var url = new URL(u, location.href);
      url.username = "";
      url.password = "";
      url.searchParams.forEach(function (v, k) {
        if (v && (sensitive(k) || URL_EXTRA[k.toLowerCase()])) url.searchParams.set(k, MASK);
      });
      return scrub(url.toString()).slice(0, 2000);
    } catch (e) {
      return scrub(u).slice(0, 2000);
    }
  }
  function text(s, n) {
    return scrub(String(s == null ? "" : s)).replace(/\s+/g, " ").trim().slice(0, n || 200);
  }

  // ---- the queue ---------------------------------------------------------------
  var queue = [];
  var sent = false;  // the session record goes with the first batch, then with every later one (to move ended_at)
  function push(type, payload) {
    if (queue.length >= MAX_EVENTS) queue.shift();
    queue.push({ session_id: sessionId, ts: Date.now() / 1000, type: type, payload: payload || {} });
  }
  function ua() {
    var u = navigator.userAgent, b = "Browser", o = "Other";
    var m = u.match(/(Edg|OPR|Chrome|Firefox|Version)\/(\d+)/);
    if (m) b = ({ Edg: "Edge", OPR: "Opera", Version: "Safari" }[m[1]] || m[1]) + " " + m[2];
    if (/iPhone|iPad|iPod/.test(u)) o = "iOS"; else if (/Android/.test(u)) o = "Android"; else if (/Mac OS X/.test(u)) o = "macOS";
    else if (/Windows/.test(u)) o = "Windows"; else if (/Linux/.test(u)) o = "Linux";
    return { browser: b, os: o, device: /Mobi|Android|iPhone/.test(u) ? "mobile" : "desktop" };
  }
  var device = ua();
  function flush(final) {
    if (!queue.length && sent) return;
    var events = queue.splice(0, queue.length);
    var body = JSON.stringify({
      sdk: { name: "loggerai-browser", version: "0.1.0" },
      release: cfg.release || null,
      sessions: [{ id: sessionId, started_at: startedAt, ended_at: Date.now() / 1000, page: cleanUrl(location.href), browser: device.browser,
        os: device.os, device: device.device, screen: [screen.width, screen.height] }],
      events: events,
    });
    sent = true;
    var url = ENDPOINT + (ENDPOINT.indexOf("?") >= 0 ? "&" : "?") + "key=" + encodeURIComponent(KEY);
    // text/plain needs no CORS preflight, and a beacon still goes out when the tab closes.
    if (navigator.sendBeacon && body.length < 60000 && navigator.sendBeacon(url, new Blob([body], { type: "text/plain" }))) return;
    try {
      origFetch.call(window, url, { method: "POST", body: body, headers: { "Content-Type": "text/plain" }, keepalive: !!final && body.length < 60000 });
    } catch (e) {}
  }

  // ---- network --------------------------------------------------------------------
  var origFetch = window.fetch;
  function traced(u) {
    try {
      var url = new URL(u, location.href);
      return url.origin === location.origin || traceHosts.indexOf(url.host.toLowerCase()) >= 0;
    } catch (e) {
      return false;
    }
  }
  function isOurs(u) {
    return String(u).indexOf(ENDPOINT) === 0;
  }
  function netEvent(method, u, status, started, trace, err) {
    var p = { method: (method || "GET").toUpperCase(), url: cleanUrl(u), status: status || 0, duration_ms: Math.round(performance.now() - started) };
    if (trace) p.trace_id = trace;
    if (err) p.error = text(err, 300);
    push("network_request", p);
  }
  if (origFetch) {
    window.fetch = function (input, init) {
      var u = typeof input === "string" ? input : input && input.url;
      if (!u || isOurs(u)) return origFetch.apply(this, arguments);
      var method = (init && init.method) || (input && input.method) || "GET";
      var started = performance.now();
      var trace = null;
      if (traced(u)) {
        trace = hex(16);
        var headers = new Headers((init && init.headers) || (input && input.headers) || undefined);
        headers.set("traceparent", "00-" + trace + "-" + hex(8) + "-01");
        headers.set("x-loggerai-session", sessionId);
        init = Object.assign({}, init || {}, { headers: headers });
        if (typeof input !== "string" && input instanceof Request) input = new Request(input, init);
      }
      return origFetch.call(this, input, init).then(
        function (res) { netEvent(method, u, res.status, started, trace); return res; },
        function (e) { netEvent(method, u, 0, started, trace, e && e.message); throw e; }
      );
    };
  }
  var XO = XMLHttpRequest.prototype.open, XS = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, u) {
    this.__la = { method: method, url: String(u) };
    return XO.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    var x = this, info = x.__la;
    if (info && !isOurs(info.url)) {
      info.started = performance.now();
      if (traced(info.url)) {
        info.trace = hex(16);
        try {
          x.setRequestHeader("traceparent", "00-" + info.trace + "-" + hex(8) + "-01");
          x.setRequestHeader("x-loggerai-session", sessionId);
        } catch (e) {}
      }
      x.addEventListener("loadend", function () { netEvent(info.method, info.url, x.status, info.started, info.trace); });
    }
    return XS.apply(this, arguments);
  };

  // ---- pages, clicks, forms ------------------------------------------------------------
  function pageView(kind) {
    push(kind, { url: cleanUrl(location.href), title: text(document.title, 200), referrer: kind === "page_view" && document.referrer ? cleanUrl(document.referrer) : undefined,
      trace_id: kind === "page_view" && cfg.trace ? cfg.trace : undefined });
  }
  pageView("page_view");
  ["pushState", "replaceState"].forEach(function (fn) {
    var orig = history[fn];
    history[fn] = function () {
      var before = location.href;
      var r = orig.apply(this, arguments);
      if (location.href !== before) pageView("navigation");
      return r;
    };
  });
  window.addEventListener("popstate", function () { pageView("navigation"); });

  function describeEl(el) {
    if (!el || !el.tagName) return "";
    var s = el.tagName.toLowerCase();
    if (el.id) s += "#" + el.id;
    else if (el.classList && el.classList.length) s += "." + Array.prototype.slice.call(el.classList, 0, 2).join(".");
    return s.slice(0, 120);
  }
  document.addEventListener("click", function (e) {
    var el = e.target && e.target.closest ? e.target.closest("a,button,[role=button],input[type=submit],input[type=button],label,summary") : null;
    if (!el || el.closest("[data-loggerai-ignore]")) return;
    var label = el.tagName === "INPUT" ? el.value : el.getAttribute("aria-label") || el.textContent;
    push("click", { target: describeEl(el), text: text(label, 80), href: el.href ? cleanUrl(el.href) : undefined });
  }, true);
  document.addEventListener("submit", function (e) {
    var f = e.target;
    push("form_submit", { target: describeEl(f), action: f.action ? cleanUrl(f.action) : undefined, method: (f.method || "get").toUpperCase(), fields: f.elements ? f.elements.length : 0 });
  }, true);

  // ---- errors and console --------------------------------------------------------------------
  window.addEventListener("error", function (e) {
    if (!e.message) return;
    push("javascript_error", { message: text(e.message, 500), source: e.filename ? cleanUrl(e.filename) + ":" + e.lineno + ":" + e.colno : undefined,
      stack: e.error && e.error.stack ? text(e.error.stack, 2000) : undefined });
  });
  window.addEventListener("unhandledrejection", function (e) {
    var r = e.reason;
    push("unhandled_promise", { message: text(r && r.message ? r.message : r, 500), stack: r && r.stack ? text(r.stack, 2000) : undefined });
  });
  ["error", "warn"].forEach(function (level) {
    var orig = console[level];
    console[level] = function () {
      try {
        var msg = Array.prototype.map.call(arguments, function (a) {
          if (a instanceof Error) return a.message;
          if (typeof a === "object") { try { return JSON.stringify(a); } catch (e) { return String(a); } }
          return String(a);
        }).join(" ");
        push(level === "error" ? "console_error" : "console_warning", { message: text(msg, 1000) });
      } catch (e) {}
      return orig.apply(this, arguments);
    };
  });

  // ---- report a problem -----------------------------------------------------------------------------
  function report(note) {
    push("custom_event", { name: "issue_reported", note: text(note, 2000), url: cleanUrl(location.href) });
    flush(true);
  }
  window.LoggerAI = { report: report, session: sessionId, track: function (name, data) { push("custom_event", { name: text(name, 80), data: data }); } };
  if (cfg.reportButton === "true") {
    var btn = document.createElement("button");
    btn.type = "button";
    btn.setAttribute("data-loggerai-ignore", "");
    btn.textContent = "Report a problem";
    btn.style.cssText = "position:fixed;right:16px;bottom:16px;z-index:2147483646;padding:10px 14px;border-radius:999px;border:0;background:#0b7350;color:#fff;font:600 13px system-ui,sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.2);cursor:pointer";
    btn.onclick = function () {
      var note = window.prompt("What went wrong? We'll send this with what just happened on the page so the team can fix it.");
      if (note && note.trim()) {
        report(note);
        btn.textContent = "Thanks, sent ✓";
        setTimeout(function () { btn.textContent = "Report a problem"; }, 4000);
      }
    };
    (document.body ? Promise.resolve() : new Promise(function (r) { document.addEventListener("DOMContentLoaded", r); })).then(function () { document.body.appendChild(btn); });
  }

  setInterval(flush, FLUSH_MS);
  document.addEventListener("visibilitychange", function () { if (document.visibilityState === "hidden") flush(true); });
  window.addEventListener("pagehide", function () { flush(true); });
})();
