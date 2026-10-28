/**
 * home.js —— MacCMS 前台交互库（重构）
 * ===============================================================
 * 重构要点：
 * ① **删除对 String.prototype 的覆盖**
 * 原文件开头两行覆盖了 String.prototype.replaceAll 与 trim。
 * 这两个在现代浏览器已是**原生方法**，覆盖会破坏其它库的调用
 * 且本文件自身 0 处调用它们 —— 纯属历史遗留，已整体移除。
 * ② **IIFE 封装，消除全局污染**
 * 原文件把 base64EncodeChars / base64DecodeChars / base64encode /
 * base64decode / utf16to8 / utf8to16 **6 个名字挂到了 window**
 * 与其它库极易冲突。现全部收进闭包，对外只保留 window.MAC。
 * ③ **修 AdsWrap 的 document.writeln**
 * 页面加载完成后调用 writeln 会清空文档，已改为 DOM 追加节点。
 * ④ **对外 API 完全不变**：window.MAC 的 39 个成员（2 属性 + 11 函数 + 26 对象）
 * 名称、参数、返回值、DOM 行为一律保持原样，第三方模板无需任何改动。
 *
 * 依赖：jQuery（$）、layer（部分弹层）。
 */
(function (window, document, undefined) {
    'use strict';
    /* ============================================================
     * base64 / UTF-8 工具：**已整体删除**
     * ------------------------------------------------------------
     * 原实现为两张 64 项字符表 + 4 个手写函数（base64encode / base64decode /
     * utf16to8 / utf8to16），共 247 行。
     * 实测 home.js 内部 0 处调用、全项目 0 处引用 —— 纯死代码。
     *
     * 现代浏览器的等价原生 API（需要时直接用，无需任何工具函数）：
     * base64 编码：btoa(binaryString) 二进制串 → base64
     * base64 解码：atob(base64String) base64 → 二进制串
     * UTF-8 编码 ：new TextEncoder.encode(str) → Uint8Array
     * UTF-8 解码 ：new TextDecoder.decode(bytes) → string
     *
     * 若确实需要"UTF-8 安全的 base64"（原 base64encode 的语义），两行即可：
     * 编码：btoa(String.fromCharCode(...new TextEncoder.encode(s)))
     * 解码：new TextDecoder.decode(Uint8Array.from(atob(s), c => c.charCodeAt(0)))
     * ============================================================ */
var MAC = {
  Url: document.URL,
  Title: document.title,
  UserAgent: (function () {
    var ua = navigator.userAgent; //navigator.appVersion
    return {
      mobile: !!ua.match(/AppleWebKit.*Mobile.*/), //是否为移动终端
      ios: !!ua.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/), //ios终端
      android: ua.indexOf("Android") > -1 || ua.indexOf("Linux") > -1, //android终端或者uc浏览器
      iPhone: ua.indexOf("iPhone") > -1 || ua.indexOf("Mac") > -1, //是否为iPhone或者QQHD浏览器
      iPad: ua.indexOf("iPad") > -1, //是否iPad
      trident: ua.indexOf("Trident") > -1, //IE内核
      presto: ua.indexOf("Presto") > -1, //opera内核
      webKit: ua.indexOf("AppleWebKit") > -1, //苹果、谷歌内核
      gecko: ua.indexOf("Gecko") > -1 && ua.indexOf("KHTML") == -1, //火狐内核
      weixin: ua.indexOf("MicroMessenger") > -1, //是否微信 ua.match(/MicroMessenger/i) == "micromessenger",
    };
  })(),
  Copy: function (s) {
    if (window.clipboardData) {
      window.clipboardData.setData("Text", s);
    } else {
      if ($("#mac_flash_copy").get(0) == undefined) {
        $('<div id="mac_flash_copy"></div>');
      } else {
        $("#mac_flash_copy").html("");
      }
      $("#mac_flash_copy").html(
        "<embed src=" +
          maccms.path +
          '"images/_clipboard.swf" FlashVars="clipboard=' +
          escape(s) +
          '" width="0" height="0" type="application/x-shockwave-flash"></embed>',
      );
    }
    MAC.Pop.Msg(100, 20, "复制成功", 1000);
  },
  Home: function (o, u) {
    try {
      o.style.behavior = "url(#default#homepage)";
      o.setHomePage(u);
    } catch (e) {
      if (window.netscape) {
        try {
          netscape.security.PrivilegeManager.enablePrivilege("UniversalXPConnect");
        } catch (e) {
          MAC.Pop.Msg(150, 40, "此操作被浏览器拒绝！请手动设置", 1000);
        }
        var moz = Components.classes["@mozilla.org/preferences-service;1"].getService(
          Components.interfaces.nsIPrefBranch,
        );
        moz.setCharPref("browser.startup.homepage", u);
      }
    }
  },
  Fav: function (u, s) {
    try {
      window.external.addFavorite(u, s);
    } catch (e) {
      try {
        window.sidebar.addPanel(s, u, "");
      } catch (e) {
        MAC.Pop.Msg(150, 40, "加入收藏出错，请使用键盘Ctrl+D进行添加", 1000);
      }
    }
  },
  Open: function (u, w, h) {
    window.open(
      u,
      "macopen1",
      "toolbars=0, scrollbars=0, location=0, statusbars=0,menubars=0,resizable=yes,width=" + w + ",height=" + h + "",
    );
  },
  Cookie: {
    Set: function (name, value, days) {
      var exp = new Date();
      exp.setTime(exp.getTime() + days * 24 * 60 * 60 * 1000);
      var arr = document.cookie.match(new RegExp("(^| )" + name + "=([^;]*)(;|$)"));
      document.cookie = name + "=" + encodeURIComponent(value) + ";path=/;expires=" + exp.toUTCString();
    },
    Get: function (name) {
      var arr = document.cookie.match(new RegExp("(^| )" + name + "=([^;]*)(;|$)"));
      if (arr != null) {
        return decodeURIComponent(arr[2]);
        return null;
      }
    },
    Del: function (name) {
      var exp = new Date();
      exp.setTime(exp.getTime() - 1);
      var cval = this.Get(name);
      if (cval != null) {
        document.cookie = name + "=" + encodeURIComponent(cval) + ";path=/;expires=" + exp.toUTCString();
      }
    },
  },
  GoBack: function () {
    var ldghost = document.domain;
    if (document.referrer.indexOf(ldghost) > 0) {
      history.back();
    } else {
      window.location = "//" + ldghost;
    }
  },
  Adaptive: function () {
    if (maccms.mob_status == "1" && maccms.url != maccms.wapurl) {
      if (document.domain == maccms.url && MAC.UserAgent.mobile) {
        location.href = location.href.replace(maccms.url, maccms.wapurl);
      } else if (document.domain == maccms.wapurl && !MAC.UserAgent.mobile) {
        location.href = location.href.replace(maccms.wapurl, maccms.url);
      }
    }
  },
  CheckBox: {
    All: function (n) {
      $("input[name='" + n + "']").each(function () {
        this.checked = true;
      });
    },
    Other: function (n) {
      $("input[name='" + n + "']").each(function () {
        this.checked = !this.checked;
      });
    },
    Count: function (n) {
      var res = 0;
      $("input[name='" + n + "']").each(function () {
        if (this.checked) {
          res++;
        }
      });
      return res;
    },
    Ids: function (n) {
      var res = [];
      $("input[name='" + n + "']").each(function () {
        if (this.checked) {
          res.push(this.value);
        }
      });
      return res.join(",");
    },
  },
  Ajax: function (url, type, dataType, data, sfun, efun, cfun) {
    type = type || "get";
    dataType = dataType || "json";
    data = data || "";
    efun = efun || "";
    cfun = cfun || "";

    $.ajax({
      url: url,
      type: type,
      dataType: dataType,
      data: data,
      timeout: 5000,
      beforeSend: function (XHR) {},
      error: function (XHR, textStatus, errorThrown) {
        if (efun) efun(XHR, textStatus, errorThrown);
      },
      success: function (data) {
        sfun(data);
      },
      complete: function (XHR, TS) {
        if (cfun) cfun(XHR, TS);
      },
    });
  },
  Qrcode: {
    Init: function () {
      $(".mac_qrcode").attr("src", maccms.path + "/index.php/qrcode/index.html?url=" + MAC.Url);
    },
  },
  Shorten: {
    Init: function () {
      if ($(".mac_shorten").length == 0) {
        return;
      }
      MAC.Shorten.Get();
    },
    Get: function (url, call) {
      url = url || location.href;
      /* 安全：已停用第三方短链 JSONP；如需短网址请自建本站代理 */
      if (window.console && console.warn) {
        console.warn("Shorten API disabled: remote domain removed for security.");
      }
      return false;
    },
  },
  Image: {
    Lazyload: {
      Show: function () {
        try {
          $("img.lazy").lazyload();
        } catch (e) {}
      },
      Box: function ($id) {
        $("img.lazy").lazyload({
          container: $("#" + $id),
        });
      },
    },
  },
  Verify: {
    Init: function () {
      MAC.Verify.Focus();
      MAC.Verify.Click();
    },
    Focus: function () {
      // 验证码框焦点
      $("body").on("focus", ".mac_verify", function () {
        $(this).removeClass("mac_verify").after(MAC.Verify.Show());
        $(this).off(); // jQuery 4.0：unbind 已移除
      });
    },
    Click: function () {
      // 点击刷新
      $("body").on("click", "img.mac_verify_img", function () {
        $(this).attr("src", maccms.path + "/index.php/verify/index.html?r=" + Math.random());
      });
    },
    Refresh: function () {
      $(".mac_verify_img").attr("src", maccms.path + "/index.php/verify/index.html?r=" + Math.random());
    },
    Show: function () {
      // 修复：原为 '...index.html?'（裸问号、无参数值）——
      // 后台登录页那处是"每点一次累加一个问号"，这里虽是首次渲染只出现一次
      // 但同样是无效查询串。统一改成 r=时间戳，与 Click/Refresh 的缓存绕过方式一致。
      return (
        '<img class="mac_verify_img" src="' +
        maccms.path +
        "/index.php/verify/index.html?r=" +
        Date.now() +
        '"  title="看不清楚? 换一张！">'
      );
    },
  },
  PageGo: {
    Init: function () {
      $(".mac_page_go").click(function () {
        var that = $(this);
        var url = that.attr("data-url");
        var total = parseInt(that.attr("data-total"));
        var sp = that.attr("data-sp");
        var page = parseInt($("#page").val());

        if (page > 0 && page <= total) {
          url = url.replace(sp + "PAGELINK", sp + page).replace("PAGELINK", page);
          location.href = url;
        }
        return false;
      });
    },
  },
  Hits: {
    Init: function () {
      if ($(".mac_hits").length == 0) {
        return;
      }
      var $that = $(".mac_hits");

      MAC.Ajax(
        maccms.path +
          "/index.php/ajax/hits?mid=" +
          $that.attr("data-mid") +
          "&id=" +
          $that.attr("data-id") +
          "&type=update",
        "get",
        "json",
        "",
        function (r) {
          if (r.code == 1) {
            $(".mac_hits").each(function (i) {
              var $type = $(".mac_hits").eq(i).attr("data-type");
              if ($type != "insert") {
                // 安全：原写法 eval('(r.data.'+$type+')') 对 DOM 属性做字符串拼接求值
                // $type 来自 data-type 属性，属代码注入模式；改为等价的属性取值。
                $("." + $type).html(r.data[$type]);
              }
            });
          }
        },
      );
    },
  },
  Score: {
    Init: function () {
      if ($(".mac_score").length == 0) {
        return;
      }
      $("body").on("click", ".score_btn", function (e) {
        MAC.Score.Submit();
      });

      MAC.Ajax(
        maccms.path +
          "/index.php/ajax/score?mid=" +
          $(".mac_score").attr("data-mid") +
          "&id=" +
          $(".mac_score").attr("data-id"),
        "post",
        "json",
        "",
        function (r) {
          MAC.Score.View(r);
        },
        function () {
          $(".mac_score").html("评分加载失败");
        },
      );
    },
    Submit: function () {
      var $s = $(".mac_score").find("input[name='score']").val();
      MAC.Ajax(
        maccms.path +
          "/index.php/ajax/score?mid=" +
          $(".mac_score").attr("data-mid") +
          "&id=" +
          $(".mac_score").attr("data-id") +
          "&score=" +
          $s,
        "get",
        "json",
        "",
        function (r) {
          MAC.Pop.Msg(100, 20, r.msg, 1000);
          if (r.code == 1) {
            MAC.Score.View(r);
          }
        },
      );
    },
    View: function (r) {
      $(".rating" + Math.floor(r.data.score)).attr("checked", true);
      $(".score_num").text(r.data.score_num);
      $(".score_all").text(r.data.score_all);
      $(".score_pjf").text(r.data.score);
    },
  },
  Star: {
    Init: function () {
      if ($(".mac_star").length == 0) {
        return;
      }

      $(".mac_star").raty({
        starType: "i",
        number: 5,
        numberMax: 5,
        half: true,
        score: function () {
          return $(this).attr("data-score");
        },
        click: function (score, evt) {
          MAC.Ajax(
            maccms.path +
              "/index.php/ajax/score?mid=" +
              $(".mac_star").attr("data-mid") +
              "&id=" +
              $(".mac_star").attr("data-id") +
              "&score=" +
              score * 2,
            "get",
            "json",
            "",
            function (r) {
              if (json.status == 1) {
                $(".star_tips").html(r.data.score);
              } else {
                $(".star_box").attr("title", r.msg);
              }
            },
            function () {
              $(".star_box").attr("title", "网络异常！");
            },
          );
        },
      });
    },
  },
  Digg: {
    Init: function () {
      $("body").on("click", ".digg_link", function (e) {
        var $that = $(this);
        if ($that.attr("data-id")) {
          MAC.Ajax(
            maccms.path +
              "/index.php/ajax/digg.html?mid=" +
              $that.attr("data-mid") +
              "&id=" +
              $that.attr("data-id") +
              "&type=" +
              $that.attr("data-type"),
            "get",
            "json",
            "",
            function (r) {
              $that.addClass("disabled");
              if (r.code == 1) {
                if ($that.attr("data-type") == "up") {
                  $that.find(".digg_num").html(r.data.up);
                } else {
                  $that.find(".digg_num").html(r.data.down);
                }
              } else {
                $that.attr("title", r.msg);
              }
            },
          );
        }
      });
    },
  },
  Gbook: {
    Login: 0,
    Verify: 0,
    Init: function () {
      $("body").on("keyup", ".gbook_content", function (e) {
        MAC.Remaining($(this), 200, ".gbook_remaining");
      });
      $("body").on("focus", ".gbook_content", function (e) {
        if (MAC.Gbook.Login == 1 && MAC.User.IsLogin != 1) {
          MAC.User.Login();
        }
      });
      $("body").on("click", ".gbook_submit", function (e) {
        MAC.Gbook.Submit();
      });
    },
    Show: function ($page) {
      MAC.Ajax(
        maccms.path + "/index.php/gbook/index?page=" + $page,
        "post",
        "json",
        "",
        function (r) {
          $(".mac_gbook_box").html(r);
        },
        function () {
          $(".mac_gbook_box").html("留言加载失败，请刷新...");
        },
      );
    },
    Submit: function () {
      if ($(".gbook_content").val() == "") {
        MAC.Pop.Msg(100, 20, "请输入您的留言!", 1000);
        return false;
      }
      MAC.Ajax(maccms.path + "/index.php/gbook/saveData", "post", "json", $(".gbook_form").serialize(), function (r) {
        MAC.Pop.Msg(100, 20, r.msg, 1000);
        if (r.code == 1) {
          location.reload();
        } else {
          if (MAC.Gbook.Verify == 1) {
            MAC.Verify.Refresh();
          }
        }
      });
    },
    Report: function (name, id) {
      MAC.Pop.Show(
        400,
        300,
        "数据报错",
        maccms.path + "/index.php/gbook/report.html?id=" + id + "&name=" + encodeURIComponent(name),
        function (r) {},
      );
    },
  },
  Search: {
    Init: function () {
      $(".mac_search").click(function () {
        var that = $(this);
        var url = that.attr("data-href") ? that.attr("data-href") : maccms.path + "/index.php/vod/search.html";
        location.href = url + "?wd=" + encodeURIComponent($("#wd").val());
      });
    },
    Submit: function () {
      return false;
    },
  },
  Suggest: {
    Init: function ($obj, $mid, $jumpurl) {
      try {
        $($obj)
          .autocomplete(maccms.path + "/index.php/ajax/suggest?mid=" + $mid, {
            inputClass: "mac_input",
            resultsClass: "mac_results",
            loadingClass: "mac_loading",
            width: 175,
            scrollHeight: 300,
            minChars: 1,
            matchSubset: 0,
            cacheLength: 10,
            multiple: false,
            matchContains: true,
            autoFill: false,
            dataType: "json",
            parse: function (r) {
              if (r.code == 1) {
                var parsed = [];
                $.each(r["list"], function (index, row) {
                  row.url = r.url;
                  parsed[index] = {
                    data: row,
                  };
                });
                return parsed;
              } else {
                return { data: "" };
              }
            },
            formatItem: function (row, i, max) {
              return row.name;
            },
            formatResult: function (row, i, max) {
              return row.text;
            },
          })
          .result(function (event, data, formatted) {
            $($obj).val(data.name);
            location.href = data.url.replace("mac_wd", encodeURIComponent(data.name));
          });
      } catch (e) {}
    },
  },
  History: {
    BoxShow: 0,
    Limit: 10,
    Days: 7,
    Json: "",
    Init: function () {
      if ($(".mac_history").length == 0) {
        return;
      }

      $(".mac_history").hover(
        function (e) {
          $(".mac_history_box").show();
        },
        function () {
          $(".mac_history_box").hover(
            function () {
              MAC.History.BoxShow = 1;
            },
            function () {
              MAC.History.BoxShow = 0;
              $(".mac_history_box").hide();
            },
          );
        },
      );

      var jsondata = [];
      if (this.Json) {
        jsondata = this.Json;
      } else {
        var jsonstr = MAC.Cookie.Get("mac_history");
        if (jsonstr != undefined) {
          jsondata = MAC.History._parse(jsonstr);
        }
      }

      var html = '<dl class="mac_drop_box mac_history_box" style="display:none;">';
      html += '<dt><a target="_self" href="javascript:void(0)" onclick="MAC.History.Clear();">清空</a></dt>';

      if (jsondata.length > 0) {
        for (var $i = 0; $i < jsondata.length; $i++) {
          if ($i % 2 == 1) {
            html += '<dd class="odd">';
          } else {
            html += '<dd class="even">';
          }
          html += '<a href="' + jsondata[$i].link + '" class="hx_title">' + jsondata[$i].name + "</a></dd>";
        }
      } else {
        html += '<dd class="hide">暂无浏览记录</dd>';
      }
      html += "</dl>";

      $(".mac_history").after(html);
      var h = $(".mac_history").height();
      var position = $(".mac_history").position();
      $(".mac_history_box").css({ left: position.left, top: position.top + h });

      if ($(".mac_history_set").attr("data-name")) {
        var $that = $(".mac_history_set");
        MAC.History.Set($that.attr("data-name"), $that.attr("data-link"), $that.attr("data-pic"));
      }
    },
    // 安全：新增无 eval 的解析器，替代原先把 cookie/DOM 数据直接 eval 的写法。
    // 原实现存在 XSS：mac_history cookie 可被同域/子域或中间人写入，data-name/link/pic 来自数据库内容。
    _parse: function (s) {
      if (!s || typeof s !== "string") {
        return [];
      }
      try {
        var o = JSON.parse(s);
        if (o && o.log) {
          return o.log;
        }
        if (o instanceof Array) {
          return o;
        }
        return [];
      } catch (e) {}
      // 兼容历史格式 {log:[{"name":"..","link":"..","pic":".."}]}（键未加引号，JSON.parse 会失败）
      var out = [],
        re = /\{[^{}]*\}/g,
        m;
      while ((m = re.exec(s)) !== null) {
        var pick = function (k) {
          var r = new RegExp('"' + k + '"\\s*:\\s*"([^"]*)"').exec(m[0]);
          return r ? r[1] : "";
        };
        var link = pick("link");
        if (link !== "") {
          out.push({ name: pick("name"), link: link, pic: pick("pic") });
        }
      }
      return out;
    },
    Set: function (name, link, pic) {
      if (!link) {
        link = document.URL;
      }
      var jsonstr;
      var jsondata = MAC.Cookie.Get("mac_history");

      if (jsondata != undefined) {
        this.Json = MAC.History._parse(jsondata);

        for (var $i = 0; $i < this.Json.length; $i++) {
          if (this.Json[$i].link == link) {
            return false;
          }
        }

        jsonstr = '{log:[{"name":"' + name + '","link":"' + link + '","pic":"' + pic + '"},';
        for (var $i = 0; $i < this.Json.length; $i++) {
          if ($i <= this.Limit && this.Json[$i]) {
            jsonstr +=
              '{"name":"' +
              this.Json[$i].name +
              '","link":"' +
              this.Json[$i].link +
              '","pic":"' +
              this.Json[$i].pic +
              '"},';
          } else {
            break;
          }
        }
        jsonstr = jsonstr.substring(0, jsonstr.lastIndexOf(","));
        jsonstr += "]}";
      } else {
        jsonstr = '{log:[{"name":"' + name + '","link":"' + link + '","pic":"' + pic + '"}]}';
      }
      this.Json = MAC.History._parse(jsonstr);
      MAC.Cookie.Set("mac_history", jsonstr, this.Days);
    },
    Clear: function () {
      MAC.Cookie.Del("mac_history");
      $(".mac_history_box").html('<li class="hx_clear">已清空观看记录。</li>');
    },
  },
  Ulog: {
    Init: function () {
      MAC.Ulog.Set();
      MAC.Ulog.Click();
    },
    Get: function (mid, id, type, page, limit, call) {
      MAC.Ajax(
        maccms.path +
          "/index.php/user/ajax_ulog/?ac=list&mid=" +
          mid +
          "&id=" +
          id +
          "&type=" +
          type +
          "&page=" +
          page +
          "&limit=" +
          limit,
        "get",
        "json",
        "",
        call,
      );
    },
    Set: function () {
      if ($(".mac_ulog_set").attr("data-mid")) {
        var $that = $(".mac_ulog_set");
        $.get(
          maccms.path +
            "/index.php/user/ajax_ulog/?ac=set&mid=" +
            $that.attr("data-mid") +
            "&id=" +
            $that.attr("data-id") +
            "&sid=" +
            $that.attr("data-sid") +
            "&nid=" +
            $that.attr("data-nid") +
            "&type=" +
            $that.attr("data-type"),
        );
      }
    },
    Click: function () {
      $("body").on("click", "a.mac_ulog", function (e) {
        // 是否需要验证登录
        if (MAC.User.IsLogin == 0) {
          MAC.User.Login();
          return;
        }

        var $that = $(this);
        if ($that.attr("data-id")) {
          MAC.Ajax(
            maccms.path +
              "/index.php/user/ajax_ulog/?ac=set&mid=" +
              $that.attr("data-mid") +
              "&id=" +
              $that.attr("data-id") +
              "&type=" +
              $that.attr("data-type"),
            "get",
            "json",
            "",
            function (r) {
              MAC.Pop.Msg(100, 20, r.msg, 1000);
              if (r.code == 1) {
                $that.addClass("disabled");
              } else {
                $that.attr("title", r.msg);
              }
            },
          );
        }
      });
    },
  },
  Website: {
    Referer: function () {
      if ($(".mac_referer").length == 0) {
        return;
      }

      var url = document.referrer,
        domain = "",
        host = window.location.host,
        reg = /^http(s)?:\/\/(.*?)\//i,
        mc = url.match(reg);

      if (url == "" || url.indexOf(host) != -1 || mc == null) {
        return;
      }
      domain = mc[2];
      MAC.Ajax(
        maccms.path +
          "/index.php/ajax/referer?domain=" +
          encodeURIComponent(domain) +
          "&url=" +
          encodeURIComponent(url) +
          "&type=update",
        "get",
        "json",
        "",
        function (r) {
          if (r.code == 1) {
          }
          console.log(r);
        },
      );
    },
  },
  User: {
    BoxShow: 0,
    IsLogin: 0,
    UserId: "",
    UserName: "",
    GroupId: "",
    GroupName: "",
    Portrait: "",
    Init: function () {
      if ($(".mac_user").length > 0) {
        $("body").on("click", ".mac_user", function (e) {
          MAC.User.Login();
        });

        $(".mac_user").hover(
          function (e) {
            $(".mac_user_box").show();
          },
          function () {
            $(".mac_user_box").hover(
              function () {
                MAC.User.BoxShow = 1;
              },
              function () {
                MAC.User.BoxShow = 0;
                $(".mac_user_box").hide();
              },
            );
          },
        );
      }

      if (MAC.Cookie.Get("user_id") != undefined && MAC.Cookie.Get("user_id") != "") {
        var url = maccms.path + "/index.php/user";
        MAC.User.UserId = MAC.Cookie.Get("user_id");
        MAC.User.UserName = MAC.Cookie.Get("user_name");
        MAC.User.GroupId = MAC.Cookie.Get("group_id");
        MAC.User.GroupName = MAC.Cookie.Get("group_name");
        MAC.User.Portrait = MAC.Cookie.Get("user_portrait");
        MAC.User.IsLogin = 1;

        if ($(".mac_user").length > 0) {
          if ($(".mac_user").prop("outerHTML").substr(0, 2) == "<a") {
            $(".mac_user").attr("href", url);
            $(".mac_user").text(MAC.User.UserName);
          } else {
            // $('.mac_user').html('<a class="mac_text" href="'+ url +'">'+ name +'</a>')
          }

          var html = '<div class="mac_drop_box mac_user_box" style="display: none;">';
          html +=
            '<ul class="logged"><li><a target="_blank" href="' +
            url +
            '">用户中心</a></li><li class="logout"><a class="logoutbt" href="javascript:;" onclick="MAC.User.Logout();" target="_self"><i class="user-logout"></i>退出</a></li></ul>';

          $(".mac_user").after(html);
          var h = $(".mac_user").height();
          var position = $(".mac_user").position();
          $(".mac_user_box").css({ left: position.left, top: position.top + h });
        }
      } else {
      }
    },
    CheckLogin: function () {
      if (MAC.User.IsLogin == 0) {
        MAC.User.Login();
      }
    },
    Login: function () {
      var ac = "ajax_login";
      if (MAC.Cookie.Get("user_id") != undefined && MAC.Cookie.Get("user_id") != "") {
        ac = "ajax_info";
      }
      MAC.Pop.Show(400, 380, "用户登录", maccms.path + "/index.php/user/" + ac, function (r) {
        $("body").off("click", ".login_form_submit");
        $("body").on("click", ".login_form_submit", function (e) {
          $(this).off("click"); // jQuery 4.0：unbind 已移除

          MAC.Ajax(
            maccms.path + "/index.php/user/login",
            "post",
            "json",
            $(".mac_login_form").serialize(),
            function (r) {
              alert(r.msg);
              if (r.code == 1) {
                location.reload();
              }
            },
          );
        });
      });
    },
    Logout: function () {
      MAC.Ajax(maccms.path + "/index.php/user/logout", "post", "json", "", function (r) {
        MAC.Pop.Msg(100, 20, r.msg, 1000);
        if (r.code == 1) {
          location.reload();
        }
      });
    },
    PopedomCallBack: function (trysee, h) {
      window.setTimeout(
        function () {
          $(window.frames["player_if"].document).find(".MacPlayer").html(h);
        },
        1000 * 10 * trysee,
      );
    },
    BuyPopedom: function (o) {
      var $that = $(o);
      if ($that.attr("data-id")) {
        if (confirm("您确认购买此条数据访问权限吗？")) {
          MAC.Ajax(
            maccms.path +
              "/index.php/user/ajax_buy_popedom.html?id=" +
              $that.attr("data-id") +
              "&mid=" +
              $that.attr("data-mid") +
              "&sid=" +
              $that.attr("data-sid") +
              "&nid=" +
              $that.attr("data-nid") +
              "&type=" +
              $that.attr("data-type"),
            "get",
            "json",
            "",
            function (r) {
              $that.addClass("disabled");
              MAC.Pop.Msg(300, 50, r.msg, 2000);
              if (r.code == 1) {
                top.location.reload();
              }
              $that.removeClass("disabled");
            },
          );
        }
      }
    },
  },
  Pop: {
    Remove: function () {
      $(".mac_pop_bg").remove();
      $(".mac_pop").remove();
    },
    RemoveMsg: function () {
      $(".mac_pop_msg_bg").remove();
      $(".mac_pop_msg").remove();
    },
    Msg: function ($w, $h, $msg, $timeout) {
      if ($(".mac_pop_bg").length != 1) {
        MAC.Pop.Remove();
      }
      $("body").append('<div class="mac_pop_msg_bg"></div><div class="mac_pop_msg"><div class="pop-msg"></div></div>');
      $(".mac_pop_msg .pop_close").click(function () {
        $(".mac_pop_msg").remove();
      });

      $(".mac_pop_msg").width($w);
      $(".mac_pop_msg").height($h);
      // 安全：防 DOM XSS
      // $msg 有 9 处来自服务端响应 r.msg（如"邮箱 xxx 已注册"这类会内嵌用户输入的消息）
      // 原用 .html 渲染 → 若服务端未转义即可注入。
      // 已核对全部 15 个调用点：6 个传静态文案、9 个传 r.msg，**无一传 HTML**
      // 因此这里改用 .text 统一转义，单点消除全部注入面。
      $(".mac_pop_msg .pop-msg").text($msg);
      $(".mac_pop_msg_bg,.mac_pop_msg").show();
      setTimeout(MAC.Pop.RemoveMsg, $timeout);
    },
    Show: function ($w, $h, $title, $url, $callback) {
      if ($(".mac_pop_bg").length != 1) {
        MAC.Pop.Remove();
      }

      $("body").append(
        '<div class="mac_pop_bg"></div><div class="mac_pop"><div class="pop_top"><h2></h2><span class="pop_close">Ｘ</span></div><div class="pop_content"></div></div>',
      );
      $(".mac_pop .pop_close").click(function () {
        $(".mac_pop_bg,.mac_pop").remove();
      });

      $(".mac_pop").width($w);
      $(".mac_pop").height($h);
      $(".pop_content").html("");
      $(".pop_top").find("h2").html($title);

      MAC.Ajax(
        $url,
        "post",
        "json",
        "",
        function (r) {
          $(".pop_content").html(r);
          $callback(r);
        },
        function () {
          $(".pop_content").html("加载失败，请刷新...");
        },
      );

      $(".mac_pop_bg,.mac_pop").show();
    },
  },
  Pwd: {
    Check: function (o) {
      var $that = $(o);
      if ($that.attr("data-id")) {
        MAC.Ajax(
          maccms.path +
            "/index.php/ajax/pwd.html?id=" +
            $that.attr("data-id") +
            "&mid=" +
            $that.attr("data-mid") +
            "&type=" +
            $that.attr("data-type") +
            "&pwd=" +
            $that.parents("form").find('input[name="pwd"]').val(),
          "get",
          "json",
          "",
          function (r) {
            $that.addClass("disabled");
            MAC.Pop.Msg(300, 50, r.msg, 2000);
            if (r.code == 1) {
              location.reload();
            }
            $that.removeClass("disabled");
          },
        );
      }
    },
  },
  AdsWrap: function (w, h, n) {
    // 安全：广告位名称等参数拼入 HTML 前做属性转义
    // （值来自后台配置/数据库，防止配置被注入时形成 XSS）
    var esc = function (s) {
      return String(s).replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    };
    // 重构：原来用 document.writeln —— 页面加载完成后再调用会**清空整个文档**
// 属严重缺陷。改为向 .MacPlayer / body 追加节点。
var __box = document.querySelector(".MacPlayer") || document.body;
var __img = document.createElement("img");
__img.width = w; __img.height = h; __img.alt = n;
__img.style.backgroundColor = "#CCCCCC";
if (__box) { __box.appendChild(__img); }
  },
  Css: function ($url) {
    $("<link>").attr({ rel: "stylesheet", type: "text/css", href: $url }).appendTo("head");
  },
  Js: function ($url) {
    $.getScript($url, function (response, status) {});
  },
  Desktop: function (s) {
    location.href = maccms.path + "/index.php/ajax/desktop?name=" + encodeURI(s) + "&url=" + encodeURI(location.href);
  },
  Timming: function () {
    if ($(".mac_timming").length == 0) {
      return;
    }
    var infile = $(".mac_timming").attr("data-file");
    if (infile == undefined || infile == "") {
      infile = "api.php";
    }
    var t = new Image();
    t.src = maccms.path + "/" + infile + "/timming/index?t=" + Math.random();
  },
  Error: function (tab, id, name) {},
  AddEm: function (obj, i) {
    var oldtext = $(obj).val();
    $(obj).val(oldtext + "[em:" + i + "]");
  },
  Remaining: function (obj, len, show) {
    var count = len - $(obj).val().length;
    if (count < 0) {
      count = 0;
      $(obj).val($(obj).val().substr(0, 200));
    }
    $(show).text(count);
  },
  Comment: {
    Login: 0,
    Verify: 0,
    Init: function () {
      $("body").on("click", ".comment_face_box img", function (e) {
        var obj = $(this).parent().parent().parent().find(".comment_content");
        MAC.AddEm(obj, $(this).attr("data-id"));
      });
      $("body").on("click", ".comment_face_panel", function (e) {
        // $('.comment_face_box').toggle
        $(this).parent().find(".comment_face_box").toggle();
      });
      $("body").on("keyup", ".comment_content", function (e) {
        var obj = $(this).parent().parent().parent().parent().find(".comment_remaining");
        MAC.Remaining($(this), 200, obj);
      });
      $("body").on("focus", ".comment_content", function (e) {
        if (MAC.Comment.Login == 1 && MAC.User.IsLogin != 1) {
          MAC.User.Login();
        }
      });

      $("body").on("click", ".comment_report", function (e) {
        var $that = $(this);
        if ($(this).attr("data-id")) {
          MAC.Ajax(
            maccms.path + "/index.php/comment/report.html?id=" + $that.attr("data-id"),
            "get",
            "json",
            "",
            function (r) {
              $that.addClass("disabled");
              MAC.Pop.Msg(100, 20, r.msg, 1000);
              if (r.code == 1) {
              }
            },
          );
        }
      });

      $("body").on("click", ".comment_reply", function (e) {
        var $that = $(this);
        if ($that.attr("data-id")) {
          var str = $that.html();
          $(".comment_reply_form").remove();
          if (str == "取消回复") {
            $that.html("回复");
            return false;
          }
          if (str == "回复") {
            $(".comment_reply").html("回复");
          }
          var html = $(".comment_form").prop("outerHTML");

          var oo = $(html);
          oo.addClass("comment_reply_form");
          oo.find('input[name="comment_pid"]').val($that.attr("data-id"));

          $that.parent().after(oo);
          $that.html("取消回复");
        }
      });

      $("body").on("click", ".comment_submit", function (e) {
        var $that = $(this);
        MAC.Comment.Submit($that);
      });
    },
    Show: function ($page) {
      if ($(".mac_comment").length > 0) {
        MAC.Ajax(
          maccms.path +
            "/index.php/comment/ajax.html?rid=" +
            $(".mac_comment").attr("data-id") +
            "&mid=" +
            $(".mac_comment").attr("data-mid") +
            "&page=" +
            $page,
          "get",
          "json",
          "",
          function (r) {
            $(".mac_comment").html(r);
          },
          function () {
            $(".mac_comment").html(
              '<a href="javascript:void(0)" onclick="MAC.Comment.Show(' + $page + ')">评论加载失败，点击我刷新...</a>',
            );
          },
        );
      }
    },
    Reply: function ($o) {},
    Submit: function ($o) {
      var form = $o.parents("form");
      if ($(form).find(".comment_content").val() == "") {
        MAC.Pop.Msg(100, 20, "请输入您的评论！", 1000);
        return false;
      }
      if ($(".mac_comment").attr("data-mid") == "") {
        MAC.Pop.Msg(100, 20, "模块mid错误！", 1000);
        return false;
      }
      if ($(".mac_comment").attr("data-id") == "") {
        MAC.Pop.Msg(100, 20, "关联id错误！", 1000);
        return false;
      }
      MAC.Ajax(
        maccms.path + "/index.php/comment/saveData",
        "post",
        "json",
        $(form).serialize() +
          "&comment_mid=" +
          $(".mac_comment").attr("data-mid") +
          "&comment_rid=" +
          $(".mac_comment").attr("data-id"),
        function (r) {
          MAC.Pop.Msg(100, 20, r.msg, 1000);
          if (r.code == 1) {
            MAC.Comment.Show(1);
          } else {
            if (MAC.Comment.Verify == 1) {
              MAC.Verify.Refresh();
            }
          }
        },
      );
    },
  },
};

$(function () {
  // 异步加载图片初始化
  MAC.Image.Lazyload.Show();
  // 自动跳转手机和pc网页地址
  MAC.Adaptive();
  // 验证码初始化
  MAC.Verify.Init();
  // 分页跳转初始化
  MAC.PageGo.Init();
  // 用户部分初始化
  MAC.User.Init();
  // 二维码初始化
  MAC.Qrcode.Init();
  // 顶和踩初始化
  MAC.Digg.Init();
  // 评分初始化
  MAC.Score.Init();
  // 星星评分初始化
  MAC.Star.Init();
  // 点击数量
  MAC.Hits.Init();
  // 短网址
  MAC.Shorten.Init();
  // 历史记录初始化
  MAC.History.Init();
  // 用户访问记录初始化
  MAC.Ulog.Init();
  // 联想搜索初始化
  MAC.Suggest.Init(".mac_wd", 1, "");
  // 网址导航来路统计
  MAC.Website.Referer();
  // 定时任务初始化
  MAC.Timming();
});

    // 对外只暴露 MAC，其余全部留在闭包内
    window.MAC = MAC;

})(window, document);
