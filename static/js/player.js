/**
 * player.js —— MacCMS 播放器运行时（老 API + 新机制）
 * ===============================================================
 * 本文件是**兼容层**：`window.MacPlayer` 及其全部方法与属性保持与老版本一致
 * 老主题 / 第三方插件里形如 `MacPlayer.GetUrl(sid,nid)`、`MacPlayer.PlayUrl` 的调用照旧可用。
 *
 * 与老版本唯一的差异在 `Play`：
 * 老版本 = `document.write('<script src="static/player/'+from+'.js">')`
 * 由每个 from 各自的播放器 js（共 19~27 个）自己去解析渲染
 * 新版本 = 直接注入**统一播放器** `/static/player/player.html`（DPlayer + hls
 * 可播 m3u8/mp4/flv）的 iframe，容器结构（.MacPlayer / #playleft / #buffer /
 * #install）与内联样式全部保留，所以老主题的 CSS 与插件不受影响。
 *
 * 为什么不再加载 `static/player/{from}.js`：
 * ① 新版随包只提供统一播放器，那些按 from 拆分的 js 已不再分发，加载必然 404
 * ② 老实现是**投毒载荷的载体** —— 原 `Play` 会用 createElement("script") 加载一段
 * base64 隐藏的 "//union.maccms.la/html/top10.js"（域名已被投毒组织控制）
 * 该段代码连同整个 base64 工具链已在的整改中移除，不应再被引入。
 *
 * 如果确实要用站点自带的 per-from 播放器 js（例如自己放回了 static/player/xxx.js）
 * 在加载本文件**之前**设置 `window.MAC_PLAYER_LEGACY_JS = true` 即可恢复老行为。
 *
 * 依赖：jQuery（$）、全局 MacPlayerConfig（static/js/playerconfig.js）
 * 页面内联的 player_aaaa 播放数据。
 */
(function (window, document, undefined) {
    'use strict';

    var killErrors = function (value) {
        return true;
    };
    window.onerror = null;
    window.onerror = killErrors;

    /* ============================================================
     * base64 / UTF-8 工具：**已整体删除**
     * ------------------------------------------------------------
     * 原实现为两张 64 项字符表 + 4 个手写函数（base64encode / base64decode /
     * utf16to8 / utf8to16），共约 246 行。删除理由：
     *
     * ① **安全**：base64 是不透明编码，极易被用来隐藏载荷。本文件历史上真实发生过：
     * 原实现用 document.createElement("script") 加载一段 base64 隐藏的
     * "//union.maccms.la/html/top10.js"，该域名已被投毒组织控制（见 Play 处注释）。
     * 删掉 base64 工具链 = 根除这类隐藏载荷的载体。
     *
     * ② **无必要**：浏览器原生已提供等价能力
     * base64：btoa / atob
     * UTF-8 ：new TextEncoder.encode / new TextDecoder.decode
     *
     * 唯一真实用途是 Init 中 encrypt=2 的地址解密，已用原生 atob 就地替换。
     * ============================================================ */

    /* 原生 base64 解码（UTF-8 安全），替代原 b64decodeUtf8 */
    function b64decodeUtf8(str) {
        var bin = atob(String(str));
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
        return new TextDecoder('utf-8').decode(bytes);
    }

    /* 站点根路径：优先页面上的 maccms.path（老主题会定义），否则从本脚本自身 src 反推。
       老实现直接用 `maccms.path`，在没定义该全局的主题里会 ReferenceError —— 这里兜底。 */
    var BASE = (function () {
        if (window.maccms && window.maccms.path) {
            return String(window.maccms.path).replace(/\/+$/, '');
        }
        var s = document.currentScript;
        if (!s || !s.src) {
            var all = document.getElementsByTagName('script');
            for (var i = all.length - 1; i >= 0; i--) {
                if (/\/player\.js(\?|$)/.test(all[i].src || '')) { s = all[i]; break; }
            }
        }
        if (s && s.src) { return String(s.src).replace(/\/static\/js\/player\.js.*$/, ''); }
        return '';
    })();

    function enc(v) { return encodeURIComponent(v == null ? '' : String(v)); }

    var MacPlayer = {
        GetDate: function (f, t) {
            if (!t) {
                t = new Date();
            }
            var a = ["日", "一", "二", "三", "四", "五", "六"];
            f = f.replace(/yyyy|YYYY/, t.getFullYear());
            f = f.replace(/yy|YY/, t.getYear() % 100 > 9 ? (t.getYear() % 100).toString() : "0" + (t.getYear() % 100));
            f = f.replace(/MM/, t.getMonth() > 9 ? t.getMonth().toString() : "0" + t.getMonth());
            f = f.replace(/M/g, t.getMonth());
            f = f.replace(/w|W/g, a[t.getDay()]);
            f = f.replace(/dd|DD/, t.getDate() > 9 ? t.getDate().toString() : "0" + t.getDate());
            f = f.replace(/d|D/g, t.getDate());
            f = f.replace(/hh|HH/, t.getHours() > 9 ? t.getHours().toString() : "0" + t.getHours());
            f = f.replace(/h|H/g, t.getHours());
            f = f.replace(/mm/, t.getMinutes() > 9 ? t.getMinutes().toString() : "0" + t.getMinutes());
            f = f.replace(/m/g, t.getMinutes());
            f = f.replace(/ss|SS/, t.getSeconds() > 9 ? t.getSeconds().toString() : "0" + t.getSeconds());
            f = f.replace(/s|S/g, t.getSeconds());
            return f;
        },
        GetUrl: function (s, n) {
            return String(this.Link || '').replace("{sid}", s).replace("{sid}", s).replace("{nid}", n).replace("{nid}", n);
        },
        Go: function (s, n) {
            var u = this.GetUrl(s, n);
            if (u) { location.href = u; }
        },
        /* 统一播放器的 iframe URL —— 与 app/common/controller/All.php 生成的 {$player_js} 保持一致 */
        PlayerUrl: function () {
            var q = 'url=' + enc(this.PlayUrl);
            if (this.PlayUrlNext) { q += '&next=' + enc(this.PlayUrlNext); }
            if (this.Pic) { q += '&pic=' + enc(this.Pic); }
            if (this.PlayFrom) { q += '&from=' + enc(this.PlayFrom); }
            q += '&id=' + (parseInt(this.Id, 10) || 0);
            q += '&sid=' + (parseInt(this.Sid, 10) || 0);
            q += '&nid=' + (parseInt(this.Nid, 10) || 0);
            if (this.PlayServer) { q += '&server=' + enc(this.PlayServer); }
            if (this.PlayNote) { q += '&note=' + enc(this.PlayNote); }
            if (this.Link) { q += '&link=' + enc(this.Link); }
            if (this.PlayLinkNext) { q += '&link_next=' + enc(this.PlayLinkNext); }
            if (this.PlayLinkPre) { q += '&link_pre=' + enc(this.PlayLinkPre); }
            q += '&flag=' + enc(this.Flag || 'play');
            return BASE + '/static/player/player.html?' + q;
        },
        Show: function () {
            /* 老主题可能自己给 MacPlayer.Html 赋值（老 {from}.js 就是这么做的）。
               Html 为空时什么都不做 —— 新机制已由本文件/父页面渲染，别把播放器清掉。 */
            if (!this.Html) { return; }
            var left = document.getElementById('playleft');
            if (left) { left.innerHTML = this.Html + ''; }
            setTimeout(function () {
                MacPlayer.AdsEnd();
            }, this.Second * 1000);
        },
        AdsStart: function () {
            if ($("#buffer").attr("src") != this.Buffer) {
                $("#buffer").attr("src", this.Buffer);
            }
            $("#buffer").show();
        },
        AdsEnd: function () {
            $("#buffer").hide();
        },
        Install: function () {
            this.Status = false;
            $("#install").show();
        },
        Play: function () {
            /* 新机制：把统一播放器的 iframe 注入老容器结构。
               若页面里已经由 {$player_js} 渲染了 #player_if，则直接返回，避免出现两个播放器。 */
            if (document.getElementById('player_if')) {
                this.offsetHeight = 0;
                this.offsetWidth = 0;
                return;
            }

            var iframeHtml = '<iframe id="player_if" name="player_if" class="MacPlayer" src="'
                + this.PlayerUrl() + '" style="z-index:9;width:100%;height:100%;border:0;"'
                + ' allowfullscreen="allowfullscreen" mozallowfullscreen="mozallowfullscreen"'
                + ' msallowfullscreen="msallowfullscreen" oallowfullscreen="oallowfullscreen"'
                + ' webkitallowfullscreen="webkitallowfullscreen" scrolling="no"></iframe>';

            /* 容器就位策略：老主题的 #playleft / .MacPlayer 优先；都没有才建一个，
               并插到"当前脚本所在位置"（等价于老实现 document.write 的插入点）。 */
            var host = document.getElementById('playleft');
            if (!host) {
                host = document.querySelector('.MacPlayer');
            }
            if (!host) {
                host = document.createElement('div');
                host.className = 'MacPlayer';
                host.id = 'playleft';
                host.style.cssText = 'width:' + (this.Width || '100%') + ';height:' + (this.Height || '100%') + ';';
                var cs = document.currentScript;
                if (cs && cs.parentNode) {
                    cs.parentNode.insertBefore(host, cs);
                } else if (document.body) {
                    document.body.appendChild(host);
                }
            }
            host.innerHTML = iframeHtml;
            try {
                this.offsetHeight = host.offsetHeight;
                this.offsetWidth = host.offsetWidth;
            } catch (e) {
                this.offsetHeight = 0;
                this.offsetWidth = 0;
            }

            /* 显式选择老机制时的兼容分支：站点自己放回了 static/player/{from}.js */
            if (window.MAC_PLAYER_LEGACY_JS) {
                var s = document.createElement('script');
                s.src = this.Path + this.PlayFrom + '.js';
                s.onerror = function () { /* 老文件不存在则维持上面的新机制分支 */ };
                (document.head || document.body || document.documentElement).appendChild(s);
            }
        },
        Down: function () {},
        Init: function () {
            var a = window.player_aaaa;
            /* player_aaaa 缺失 = 页面没有播放数据（例如被付费墙/试看拦截时 player_data 被清空），
               老实现在这里会直接抛错；这里安静返回，让页面其余部分正常工作。 */
            if (!a) {
                this.Status = false;
                return;
            }
            this.Status = true;
            this.Parse = "";
            if (a.encrypt == "1") {
                a.url = unescape(a.url);
                a.url_next = unescape(a.url_next);
            } else if (a.encrypt == "2") {
                a.url = unescape(b64decodeUtf8(a.url));
                a.url_next = unescape(b64decodeUtf8(a.url_next));
            }
            this.Agent = navigator.userAgent.toLowerCase();
            var cfg = window.MacPlayerConfig || {};
            this.Width = cfg.width || "100%";
            this.Height = cfg.height || "100%";
            if (
                this.Agent.indexOf("android") > 0 ||
                this.Agent.indexOf("mobile") > 0 ||
                this.Agent.indexOf("ipod") > 0 ||
                this.Agent.indexOf("ios") > 0 ||
                this.Agent.indexOf("iphone") > 0 ||
                this.Agent.indexOf("ipad") > 0
            ) {
                this.Width = cfg.widthmob || this.Width;
                this.Height = cfg.heightmob || this.Height;
            }
            if (String(this.Width).indexOf("px") == -1 && String(this.Width).indexOf("%") == -1) {
                this.Width = "100%";
            }
            if (String(this.Height).indexOf("px") == -1 && String(this.Height).indexOf("%") == -1) {
                this.Height = "100%";
            }
            this.Prestrain = cfg.prestrain;
            this.Buffer = cfg.buffer;
            this.Second = cfg.second;
            this.Flag = a.flag;
            this.Trysee = a.trysee;
            this.Points = a.points;
            this.Link = a.link ? decodeURIComponent(a.link) : '';
            /* 安全：播放器标识白名单（防采集写入导致的存储型 XSS） */
            this.PlayFrom = String(a.from == null ? "" : a.from).replace(/[^a-z0-9_]/gi, "");
            if (this.PlayFrom === "") {
                this.PlayFrom = "parse";
            }
            this.PlayNote = a.note;
            this.PlayServer = a.server == "no" ? "" : a.server;
            this.PlayUrl = a.url;
            this.PlayUrlNext = a.url_next;
            this.PlayLinkNext = a.link_next;
            this.PlayLinkPre = a.link_pre;
            this.Pic = a.pic;
            this.Id = a.id;
            this.Sid = a.sid;
            this.Nid = a.nid;
            var plist = cfg.player_list || {};
            if (cfg.server_list && cfg.server_list[this.PlayServer] != undefined) {
                this.PlayServer = cfg.server_list[this.PlayServer].des;
            }
            if (plist[this.PlayFrom] != undefined) {
                if (plist[this.PlayFrom].ps == "1") {
                    this.Parse =
                        plist[this.PlayFrom].parse == ""
                            ? cfg.parse
                            : plist[this.PlayFrom].parse;
                }
            }
            this.Path = BASE + "/static/player/";
            if (this.Flag == "down") {
                MacPlayer.Down();
            } else {
                MacPlayer.Play();
            }
        },
    };

    // 对外只暴露 MacPlayer（其余留在闭包内）
    window.MacPlayer = MacPlayer;

    // 与原文件一致：载入即初始化（新机制下 Play 检测到 #player_if 会安静跳过）
    MacPlayer.Init();
})(window, document);
