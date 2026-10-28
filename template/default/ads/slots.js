/*
 * 模板广告位 —— 后台「模板 → 广告管理」里编辑本文件 slots.js 即可，保存后立即生效。
 * 每个广告位三种写法（留空 '' 表示该位置不显示任何内容）：
 *   1) 图片：{ img: '/upload/ad/banner.jpg', href: 'https://example.com/', alt: '说明', newtab: 1 }
 *   2) HTML：'<a href="https://example.com/"><img src="/upload/ad/a.jpg" alt=""></a>'
 *   3) 外部广告脚本：{ js: 'https://ad.example.com/code.js' }
 * 播放器前贴片 player_pre 可加 sec: 5（倒计时秒数）。
 */
var NN_ADS = {
  header_banner: '',  /* 顶部横幅（导航下方，全站） */
  home_mid: '',       /* 首页：最新更新与分类区块之间 */
  home_mid2: '',      /* 首页：分类区块之后 */
  list_top: '',       /* 列表/筛选页：网格上方 */
  list_bottom: '',    /* 列表/筛选页：分页上方 */
  detail_side: '',    /* 详情页：右侧栏顶部 */
  detail_intro: '',   /* 详情页：剧情简介下方 */
  player_top: '',     /* 播放页：播放器上方 */
  player_bottom: '',  /* 播放页：播放器下方 */
  player_pre: '',     /* 播放页：前贴片（覆盖在播放器上，倒计时后关闭） */
  player_pause: '',   /* 播放页：暂停广告（同源播放器暂停时显示） */
  search_top: '',     /* 搜索结果页：结果上方 */
  art_detail: '',     /* 文章详情：正文下方 */
  user_center: '',    /* 会员中心：内容区顶部 */
  footer: '',         /* 页脚上方（全站） */
  mobile_sticky: ''   /* 手机端底部悬浮（可关闭） */
};
