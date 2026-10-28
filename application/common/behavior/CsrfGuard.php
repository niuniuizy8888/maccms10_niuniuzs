<?php
namespace app\common\behavior;

use think\Session;

/**
 * 后台 CSRF 全局守卫（安全）
 * ------------------------------------------------------------------
 * 背景：整改前 143 个后台视图中仅 28 个带 __token__，且大量状态改变端点是 GET
 * （列表页的 data-href="{:url('xxx/del?ids=..')}" 由 j-tr-del 以 $.get 触发）
 * 会话 cookie 又无 SameSite，跨站即可触发删除/改状态/导库等操作。
 * 策略（分层，避免一次性全网强制 token 打断后台）：
 * 1) POST/PUT/DELETE/PATCH：一律校验 __token__（与 Session 中令牌做 hash_equals 比对）。
 * **不消费令牌** —— 控制器自身可能还会调用 Loader::validate('Token')，消费会导致二次校验失败。
 * 2) JS/编辑器驱动、无法携带表单令牌的控制器（collect/make/cj/addon/upload/type）：退化为同源 Referer 校验。
 * 3) GET 状态改变动作（del/field/state/clear/import/export/optimize/repair/uninstall/upgrade/local/downloaded）：
 * 同源 Referer 校验（会话 cookie 已有 SameSite=Lax，跨站子资源请求已不带 cookie）。
 * 4) 纯浏览请求放行。
 * 应急开关：application/extra/maccms.php 的 app.csrf_guard = '0' 可整体关闭本守卫。
 */
class CsrfGuard
{
    /** JS/编辑器驱动，无法携带表单令牌的控制器（改为同源校验） */
    private $controllerExempt = ['collect', 'make', 'cj', 'addon', 'upload', 'type'];

    /** 动作级豁免（登录入口无会话令牌可用；登出为非破坏性） */
    private $actionExempt = ['login', 'logout'];

    /** GET 触发的状态改变动作（需同源 Referer） */
    private $getStateActions = ['del', 'field', 'state', 'clear', 'import', 'export', 'optimize', 'repair',
                                'uninstall', 'upgrade', 'local', 'downloaded'];

    /**
     * 只读/无持久副作用的动作：豁免 POST 令牌校验（否则会打断后台的列名下拉、连通性测试等）。
     * 影响面：columns=读列名；checkcache=读缓存状态；test_cache/test_email=连通性测试（一次性发信副作用）
     * configlang=切换管理员界面语言。均不改变业务数据。
     */
     // 说明：'configlang' 已从豁免中移除（独立验证提示）——它会写 extra/maccms.php 的 app.lang
     // 属"写配置"而非只读；对应视图 index/index.html 的 $.ajax 已携带 __token__，故改为正常校验。
    private $actionReadOnlyExempt = ['columns', 'checkcache', 'test_cache', 'test_email', 'select'];

    public function run(&$params)
    {
        if (!defined('ENTRANCE') || ENTRANCE != 'admin') {
            return;
        }
        $app = isset($GLOBALS['config']['app']) && is_array($GLOBALS['config']['app']) ? $GLOBALS['config']['app'] : array();
        if (isset($app['csrf_guard']) && (string)$app['csrf_guard'] === '0') {
            return;   // 应急开关
        }

        $req = request();
        $cl = strtolower((string)$req->controller());
        $ac = strtolower((string)$req->action());

        if (in_array($ac, $this->actionExempt, true) || in_array($ac, $this->actionReadOnlyExempt, true)) {
            return;
        }

        $isWrite = $req->isPost() || $req->isPut() || $req->isDelete() || $req->isPatch();
        $isGetState = !$isWrite && in_array($ac, $this->getStateActions, true);

        if (!$isWrite && !$isGetState) {
            return;   // 纯浏览
        }

        if ($isWrite && !in_array($cl, $this->controllerExempt, true)) {
            if ($this->checkToken($req)) {
                return;
            }
            $this->deny($req, 'CSRF 令牌校验失败，请刷新页面后重试');
        }

        // 豁免控制器，或 GET 状态改变：退化为同源来源校验
        if ($this->sameOrigin()) {
            return;
        }
        $this->deny($req, '请求来源校验失败（疑似跨站请求）');
    }

    /**
     * 校验 __token__：与 Session 中的令牌严格比对，但不消费（留给控制器自身的 Token 验证器）。
     */
    private function checkToken($req)
    {
        $token = $req->param('__token__');
        $sessionToken = Session::get('__token__');
        if (!is_string($token) || $token === '') {
            return false;
        }
        // 1) 标准令牌（表单用；会被控制器的 Token 验证器消费）
        if (is_string($sessionToken) && $sessionToken !== '' && hash_equals($sessionToken, $token)) {
            return true;
        }
        // 2) 会话级稳定令牌（后台 JS 用；不被消费，避免一次表单提交后异步操作集体失效）
        if (function_exists('mac_csrf_token') && hash_equals(mac_csrf_token(), $token)) {
            return true;
        }
        return false;
    }

    /**
     * 同源判断：Referer 主机必须与当前 Host 一致（后台不会被第三方页面正常引用）。
     * 注意：HTTP_HOST 在非标准端口下形如 1.2.3.4:8522，而 parse_url(HOST) 不含端口
     * 必须去掉端口再比，否则会误报「请求来源校验失败（疑似跨站请求）」。
     */
    private function sameOrigin()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
        if ($host === '') {
            return false;
        }
        // 去掉端口；IPv6 形如 [::1]:8522
        if ($host[0] === '[') {
            $end = strpos($host, ']');
            $host = ($end !== false) ? substr($host, 1, $end - 1) : $host;
        } else {
            $host = preg_replace('/:\d+$/', '', $host);
        }
        $referer = isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : '';
        if ($referer === '') {
            return false;
        }
        $refHost = parse_url($referer, PHP_URL_HOST);
        if (!is_string($refHost) || $refHost === '') {
            return false;
        }
        return strcasecmp($refHost, $host) === 0;
    }

    private function deny($req, $msg)
    {
        if ($req->isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('code' => 0, 'msg' => $msg), JSON_UNESCAPED_UNICODE);
            exit;
        }
        // abort 抛的是 HttpException，经全局异常页渲染；叠加 show_error_msg=false 后
        // 管理员只看到"页面错误"，无法判断是 CSRF 拦截。此处直接输出可读原因（仍为 403）。
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><html lang="zh-cn"><head><meta charset="utf-8"><title>403</title></head><body>'
            . '<h3>403 Forbidden</h3><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="color:#888">如为误报，请联系管理员检查 application/extra/maccms.php 的 app.csrf_guard 配置。</p>'
            . '</body></html>';
        exit;
    }
}
