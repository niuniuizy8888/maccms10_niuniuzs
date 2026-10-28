/* 牛牛影视主题交互（无依赖） */
(function () {
  'use strict';
  var d = document, root = d.documentElement;
  function $$(s, c) { return Array.prototype.slice.call((c || d).querySelectorAll(s)); }

  /* 主题切换：默认深色，保存在 localStorage */
  function initTheme() {
    var b = d.getElementById('nn-theme'); if (!b) return;
    var sync = function () { b.setAttribute('aria-pressed', root.getAttribute('data-theme') === 'light' ? 'true' : 'false'); };
    sync();
    b.addEventListener('click', function () {
      var t = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      root.setAttribute('data-theme', t);
      try { localStorage.setItem('nn-theme', t); } catch (e) {}
      sync();
    });
  }

  /* 海报懒加载过渡 + 骨架屏 */
  function initImgs() {
    $$('.nn-poster img').forEach(function (img) {
      var box = img.parentNode;
      var done = function () { img.classList.remove('nn-lazy'); box.classList.add('loaded'); };
      var fail = function () { img.style.visibility = 'hidden'; box.classList.add('loaded', 'nn-noimg'); };
      if (img.complete && img.naturalWidth) return done();
      if (img.complete) return fail();
      img.classList.add('nn-lazy');
      img.addEventListener('load', done); img.addEventListener('error', fail);
    });
  }

  /* 首页轮播 */
  function initHero() {
    var h = d.querySelector('.nn-hero'); if (!h) return;
    var s = $$('.nn-slide', h), dots = $$('.nn-dots button', h), i = 0, t;
    s.forEach(function (x) { if (!x.classList.contains('active')) x.inert = true; });
    if (s.length < 2) return;
    function go(n) {
      s[i].classList.remove('active'); s[i].setAttribute('aria-hidden', 'true'); s[i].inert = true; dots[i] && dots[i].classList.remove('active');
      i = (n + s.length) % s.length;
      s[i].classList.add('active'); s[i].removeAttribute('aria-hidden'); s[i].inert = false; dots[i] && dots[i].classList.add('active');
    }
    function play() { stop(); if (!matchMedia('(prefers-reduced-motion: reduce)').matches) t = setInterval(function () { go(i + 1); }, 5500); }
    function stop() { clearInterval(t); }
    dots.forEach(function (b, n) { b.addEventListener('click', function () { go(n); play(); }); });
    var p = h.querySelector('.nn-prev'), nx = h.querySelector('.nn-next');
    p && p.addEventListener('click', function () { go(i - 1); play(); });
    nx && nx.addEventListener('click', function () { go(i + 1); play(); });
    h.addEventListener('mouseenter', stop); h.addEventListener('mouseleave', play);
    h.addEventListener('focusin', stop);
    var x0 = null;
    h.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    h.addEventListener('touchend', function (e) { if (x0 === null) return; var dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) { go(dx < 0 ? i + 1 : i - 1); play(); } x0 = null; });
    play();
  }

  /* 选项卡（播放源） */
  function initTabs() {
    $$('[role=tablist]').forEach(function (list) {
      var tabs = $$('[role=tab]', list);
      tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
          tabs.forEach(function (o) {
            var on = o === tab; o.setAttribute('aria-selected', on ? 'true' : 'false'); o.tabIndex = on ? 0 : -1;
            var p = d.getElementById(o.getAttribute('aria-controls')); if (p) p.hidden = !on;
          });
        });
        tab.addEventListener('keydown', function (e) {
          var k = tabs.indexOf(tab), n = e.key === 'ArrowRight' ? k + 1 : e.key === 'ArrowLeft' ? k - 1 : null;
          if (n === null) return; n = (n + tabs.length) % tabs.length; tabs[n].focus(); tabs[n].click(); e.preventDefault();
        });
      });
    });
  }

  /* 简介展开/收起 */
  function initIntro() {
    $$('.nn-intro').forEach(function (el) {
      var btn = el.nextElementSibling; if (!btn || !btn.classList.contains('nn-toggle')) return;
      if (el.scrollHeight <= el.clientHeight + 4) { el.classList.add('open'); btn.hidden = true; return; }
      btn.addEventListener('click', function () {
        var o = el.classList.toggle('open'); btn.textContent = o ? '收起' : '展开全部'; btn.setAttribute('aria-expanded', o ? 'true' : 'false');
      });
    });
  }

  /* 当前剧集滚动到可视区 */
  function initEpScroll() {
    var a = d.querySelector('.nn-play-side .nn-ep.active'); if (a && a.parentNode.scrollTo) a.parentNode.scrollTop = a.offsetTop - a.parentNode.offsetTop - 60;
  }

  /* 底部导航高亮 */
  function initTabbar() {
    var p = location.pathname + location.search, best = null, len = 0;
    $$('.nn-tabbar a').forEach(function (a) {
      var u = a.getAttribute('href'); if (!u) return;
      if (p === u || (u.length > 1 && p.indexOf(u.replace(/\.html$/, '')) === 0 && u.length > len)) { best = a; len = u.length; }
    });
    if (!best && (p === '/' || p === '/index.php')) best = d.querySelector('.nn-tabbar a');
    if (best) { best.classList.add('active'); best.setAttribute('aria-current', 'page'); }
    if (p === '/' || p === '/index.php' || p === '/index.php/index/index.html') $$('.nn-home').forEach(function (a) { a.classList.add('active'); a.setAttribute('aria-current', 'page'); });
  }

  function initBackTop() {
    var b = d.querySelector('.back-top'); if (!b) return;
    addEventListener('scroll', function () { b.classList.toggle('show', scrollY > 400); }, { passive: true });
    b.addEventListener('click', function () { scrollTo({ top: 0, behavior: 'smooth' }); });
  }

  /* ---------- 表单 ---------- */
  function flash(el, msg, type) {
    if (!el) return;
    el.textContent = msg || ''; el.className = 'alert alert-' + (type || 'info') + (msg ? '' : ' d-none'); el.setAttribute('role', 'alert');
  }
  function refreshCaptcha(img) { var b = img.getAttribute('data-base') || img.src.split('?')[0]; img.src = b + '?t=' + Date.now(); }
  function initCaptcha() {
    $$('.captcha-img').forEach(function (img) {
      img.setAttribute('data-base', img.src.split('?')[0]); img.setAttribute('role', 'button'); img.tabIndex = 0;
      img.addEventListener('click', function () { refreshCaptcha(img); });
      img.addEventListener('keydown', function (e) { if (e.key === 'Enter') refreshCaptcha(img); });
    });
  }
  function submitJson(form, url, onOk) {
    var btn = form.querySelector('[type=submit]'); if (btn) btn.disabled = true;
    var x = new XMLHttpRequest(); x.open('POST', url, true);
    x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded'); x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    x.onreadystatechange = function () {
      if (x.readyState !== 4) return; if (btn) btn.disabled = false;
      var r = null; try { r = JSON.parse(x.responseText); } catch (e) {}
      var al = form.querySelector('.alert') || form.parentNode.querySelector('.alert');
      if (x.status === 200 && r && r.code === 1) { onOk ? onOk(r, al) : (location.href = location.pathname); }
      else { flash(al, (r && r.msg) || '提交失败，请稍后重试', 'danger'); var c = form.querySelector('.captcha-img'); c && refreshCaptcha(c); }
    };
    x.send(new URLSearchParams(new FormData(form)).toString());
  }
  function bindForm(id, check, ok) {
    var f = d.getElementById(id); if (!f) return;
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var al = f.querySelector('.alert') || f.parentNode.querySelector('.alert');
      var m = check && check(f); if (m) return flash(al, m, 'danger');
      submitJson(f, f.getAttribute('action'), ok || function () { location.href = f.getAttribute('data-redirect') || '/'; });
    });
  }
  function initForms() {
    bindForm('login-form');
    bindForm('reg-form', function (f) { var a = f.user_pwd, b = f.user_pwd2; return a && b && a.value !== b.value ? '两次输入的密码不一致' : ''; });
    var msgOk = function (r, al) { flash(al, r.msg || '提交成功，审核后显示', 'success'); setTimeout(function () { location.reload(); }, 1200); };
    bindForm('gbook-form', function (f) { return f.gbook_content.value.trim() ? '' : '请填写留言内容'; }, msgOk);
    bindForm('comment-form', function (f) { return f.comment_content.value.trim() ? '' : '请填写评论内容'; }, msgOk);
    var lo = d.getElementById('logout-link');
    lo && lo.addEventListener('click', function (e) { e.preventDefault(); fetch(lo.href, { credentials: 'same-origin' }).catch(function () {}).finally(function () { location.href = location.pathname; }); });
    var fav = d.getElementById('fav-btn');
    fav && fav.addEventListener('click', function () {
      fetch(fav.getAttribute('data-url'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (r) { if (r && r.code === 1) { fav.classList.add('active'); fav.lastChild.textContent = '已收藏'; } else if (fav.getAttribute('data-login')) location.href = fav.getAttribute('data-login'); })
        .catch(function () { if (fav.getAttribute('data-login')) location.href = fav.getAttribute('data-login'); });
    });
  }


  /* ---------- v3：通用 AJAX 表单 / 发送验证码 / 会员中心 / 互动 / 广告 ---------- */
  function toast(msg) {
    var t = d.querySelector('.nn-toast'); if (!t) { t = d.createElement('div'); t.className = 'nn-toast'; t.setAttribute('role', 'status'); d.body.appendChild(t); }
    t.textContent = msg; t.classList.add('show'); clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('show'); }, 2200);
  }
  function getJson(url, cb) {
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); }).then(cb).catch(function () { cb(null); });
  }
  function initAjaxForms() {
    $$('form[data-ajax]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var al = f.querySelector('.alert');
        var a = f.querySelector('[name=user_pwd1]'), b = f.querySelector('[name=user_pwd2]');
        if (a && b && a.value !== b.value) return flash(al, '两次输入的新密码不一致', 'danger');
        submitJson(f, f.getAttribute('action') || location.href, function (r) {
          var done = f.getAttribute('data-done') || 'reload';
          flash(al, r.msg || '操作成功', 'success');
          if (done === 'reload') setTimeout(function () { location.reload(); }, 900);
          else if (done === 'next' && r.url) setTimeout(function () { location.href = r.url; }, 900);
          else if (done.charAt(0) === '/' || done.indexOf('http') === 0) setTimeout(function () { location.href = done; }, 900);
          else if (done === 'pay' && r.data && r.data.order_code) location.href = f.getAttribute('data-pay') + '?order_code=' + encodeURIComponent(r.data.order_code);
        });
      });
    });
    /* 发送邮件/短信验证码 */
    $$('[data-sendcode]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var f = btn.form, to = f.querySelector('[name=to]'), ac = f.querySelector('[name=ac]:checked') || f.querySelector('[name=ac]');
        var al = f.querySelector('.alert');
        if (!to || !to.value.trim()) return flash(al, '请先填写邮箱或手机号', 'danger');
        btn.disabled = true;
        var x = new XMLHttpRequest(); x.open('POST', btn.getAttribute('data-sendcode'), true);
        x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded'); x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        x.onreadystatechange = function () {
          if (x.readyState !== 4) return; var r = null; try { r = JSON.parse(x.responseText); } catch (e) {}
          if (r && r.code === 1) {
            flash(al, r.msg || '验证码已发送', 'success'); var n = 60, t0 = btn.textContent;
            var iv = setInterval(function () { btn.textContent = n + ' 秒后重发'; if (--n < 0) { clearInterval(iv); btn.textContent = t0; btn.disabled = false; } }, 1000);
          } else { btn.disabled = false; flash(al, (r && r.msg) || '发送失败', 'danger'); }
        };
        x.send('ac=' + encodeURIComponent(ac ? ac.value : 'email') + '&to=' + encodeURIComponent(to.value.trim()) + '&type=' + encodeURIComponent(btn.getAttribute('data-type') || ''));
      });
    });
    /* 密码访问（详情/播放/下载/文章） */
    $$('form[data-pwd]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        e.preventDefault(); var al = f.querySelector('.alert');
        var q = 'mid=' + f.getAttribute('data-mid') + '&id=' + f.getAttribute('data-id') + '&type=' + f.getAttribute('data-type') + '&pwd=' + encodeURIComponent(f.pwd.value);
        getJson(f.getAttribute('data-pwd') + '?' + q, function (r) {
          if (r && r.code === 1) { flash(al, '验证通过，正在进入…', 'success'); setTimeout(function () { location.reload(); }, 600); }
          else flash(al, (r && r.msg) || '密码错误', 'danger');
        });
      });
    });
    /* 积分购买确认 */
    $$('[data-buy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var al = d.querySelector('.alert'); btn.disabled = true;
        getJson(btn.getAttribute('data-buy'), function (r) {
          btn.disabled = false;
          if (r && r.code === 1) { flash(al, r.msg || '购买成功', 'success'); setTimeout(function () { location.reload(); }, 800); }
          else flash(al, (r && r.msg) || '购买失败', 'danger');
        });
      });
    });
  }
  function initUside() {
    var p = location.pathname, best = null, len = 0;
    $$('.nn-uside nav a').forEach(function (a) { var u = a.getAttribute('href').split('?')[0].replace(/\.html$/, ''); if (u && p.indexOf(u) === 0 && u.length > len) { best = a; len = u.length; } });
    if (best) { best.classList.add('active'); best.setAttribute('aria-current', 'page'); }
  }
  function initWidgets() {
    $$('.nn-widgets').forEach(function (w) {
      var base = w.getAttribute('data-api'), q = 'mid=' + w.getAttribute('data-mid') + '&id=' + w.getAttribute('data-id');
      var hv = w.querySelector('[data-hits]');
      if (hv) getJson(base + 'hits?' + q + '&type=update', function (r) { if (r && r.code === 1 && r.data) hv.textContent = r.data.hits; });
      $$('.nn-digg', w).forEach(function (b) {
        b.addEventListener('click', function () {
          getJson(base + 'digg?' + q + '&type=' + b.getAttribute('data-type'), function (r) {
            if (r && r.code === 1 && r.data) { $$('.nn-digg', w).forEach(function (o) { var v = o.querySelector('span'); v.textContent = r.data[o.getAttribute('data-type')]; }); b.classList.add('done'); toast('感谢反馈'); }
            else toast((r && r.msg) || '操作失败');
          });
        });
      });
      $$('.nn-stars button', w).forEach(function (b) {
        b.addEventListener('click', function () {
          getJson(base + 'score?' + q + '&score=' + b.getAttribute('data-score'), function (r) {
            if (r && r.code === 1 && r.data) { var v = w.querySelector('.nn-stars-v'); if (v) v.textContent = r.data.score; toast('评分成功'); }
            else toast((r && r.msg) || '评分失败');
          });
        });
      });
    });
  }
  /* 广告位：内容来自 ads/slots.js 中的 NN_ADS 配置，留空则不渲染 */
  function adHtml(v) {
    if (!v) return '';
    if (typeof v === 'string') return v;
    if (v.img) {
      var img = '<img src="' + v.img + '" alt="' + (v.alt || '广告') + '" loading="lazy">';
      return v.href ? '<a href="' + v.href + '" rel="nofollow noopener sponsored"' + (v.newtab ? ' tar' + 'get="_blank"' : '') + '>' + img + '</a>' : img;
    }
    return v.html || '';
  }
  function fillAd(el, v) {
    var h = adHtml(v), js = v && typeof v === 'object' && v.js;
    if (!h && !js) return false;
    el.innerHTML = h;
    $$('script', el).forEach(function (o) { var n = d.createElement('script'); for (var i = 0; i < o.attributes.length; i++) n.setAttribute(o.attributes[i].name, o.attributes[i].value); n.text = o.text; o.parentNode.replaceChild(n, o); });
    if (js) { var s = d.createElement('script'); s.src = js; s.async = true; el.appendChild(s); }
    el.hidden = false; return true;
  }
  function initAds() {
    var A = window.NN_ADS || {};
    $$('.nn-ad[data-slot]').forEach(function (el) {
      var slot = el.getAttribute('data-slot');
      if (slot === 'player_pre' || slot === 'player_pause') return;
      if (!fillAd(el, A[slot])) return;
      if (slot === 'mobile_sticky') {
        var c = d.createElement('button'); c.type = 'button'; c.className = 'nn-ad-close'; c.setAttribute('aria-label', '关闭广告'); c.textContent = '×';
        c.addEventListener('click', function () { el.hidden = true; }); el.appendChild(c);
      }
    });
    var player = d.querySelector('.nn-player');
    if (!player) return;
    var pre = A.player_pre, ov;
    if (adHtml(pre) || (pre && pre.js)) {
      ov = d.createElement('div'); ov.className = 'nn-ad-overlay';
      var box = d.createElement('div'); box.className = 'nn-ad'; ov.appendChild(box); fillAd(box, pre);
      var sk = d.createElement('button'); sk.type = 'button'; sk.className = 'nn-ad-skip'; ov.appendChild(sk);
      var n = parseInt((pre && pre.sec) || 5, 10);
      var tick = function () { sk.textContent = n > 0 ? '广告 ' + n + ' 秒' : '关闭广告 ×'; sk.disabled = n > 0; };
      tick(); var iv = setInterval(function () { n--; tick(); if (n < 0) { clearInterval(iv); ov.hidden = true; } }, 1000);
      sk.addEventListener('click', function () { clearInterval(iv); ov.hidden = true; });
      player.appendChild(ov);
    }
    var pz = A.player_pause;
    if (adHtml(pz) || (pz && pz.js)) {
      var po = d.createElement('div'); po.className = 'nn-ad-overlay'; po.hidden = true;
      var pb = d.createElement('div'); pb.className = 'nn-ad'; po.appendChild(pb); fillAd(pb, pz);
      var pc = d.createElement('button'); pc.type = 'button'; pc.className = 'nn-ad-skip'; pc.textContent = '关闭 ×'; po.appendChild(pc);
      pc.addEventListener('click', function () { po.hidden = true; });
      player.appendChild(po);
      var hook = function (v) { if (v._nn) return; v._nn = 1; v.addEventListener('pause', function () { if (!v.ended) po.hidden = false; }); v.addEventListener('play', function () { po.hidden = true; }); };
      var scan = function () {
        $$('video', player).forEach(hook);
        $$('iframe', player).forEach(function (f) { try { var doc = f.contentDocument; if (doc) { $$('video', doc).forEach(hook); $$('iframe', doc).forEach(function (g) { try { $$('video', g.contentDocument).forEach(hook); } catch (e) {} }); } } catch (e) {} });
      };
      setInterval(scan, 1500); scan();
    }
  }
  /* 试看：到时遮罩 */
  function initTrysee() {
    var t = d.querySelector('[data-trysee]'); if (!t) return;
    var m = parseInt(t.getAttribute('data-trysee'), 10) || 0, end = d.querySelector('.nn-trysee-end');
    if (m > 0 && end) setTimeout(function () { end.hidden = false; $$('iframe,video').forEach(function (v) { try { v.pause && v.pause(); } catch (e) {} v.style.visibility = 'hidden'; }); }, m * 60000);
  }

  function boot() { initTheme(); initImgs(); initHero(); initTabs(); initIntro(); initEpScroll(); initTabbar(); initBackTop(); initCaptcha(); initForms(); initAjaxForms(); initUside(); initWidgets(); initAds(); initTrysee(); }
  d.readyState === 'loading' ? d.addEventListener('DOMContentLoaded', boot) : boot();
})();
