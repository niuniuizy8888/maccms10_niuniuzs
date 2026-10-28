<?php
namespace app\admin\controller;

/**
 * 在线升级控制器 —— 已整体停用。
 * ------------------------------------------------------------------
 * 原版实现内置两条可被远端服务器控制的"系统持久化"通道：
 * 1) step1：按请求参数从官方升级域名下载 ZIP，并以"覆盖更新"模式
 * 解压覆盖整站文件
 * 2) one：拉取远端响应，若响应中包含一个固定标记串，则把响应体写入
 * 由请求参数任意指定的文件路径（任意文件写入后门，可落地 webshell
 * 并通过钩子注册实现每次页面渲染自动重感染）。该 action 在整站代码
 * 与后台视图中没有任何调用点，属纯隐蔽入口。
 *
 * 本文件已移除全部远程请求与文件写入逻辑，仅保留同名方法避免后台菜单
 * 因控制器缺失报错。如需升级：请从可信渠道手动获取安装包，人工比对后
 * 再覆盖，并将当前版本备份到 Web 根目录以外。
 */
class Update extends Base
{
    /**
     * 所有升级动作统一返回"已停用"，不再产生任何出站请求或文件写入。
     */
    private function _blocked()
    {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="zh-cn"><head><meta charset="utf-8">'
            . '<title>在线升级已停用</title></head><body>'
            . '<div style="max-width:760px;margin:40px auto;padding:24px;border:1px solid #e2e2e2;'
            . 'border-radius:6px;font:14px/1.9 -apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,sans-serif;color:#333">'
            . '<h2 style="margin:0 0 12px;font-size:18px">在线升级通道已停用</h2>'
            . '<p>原版 <code>Update</code> 控制器包含两条可被远程服务器控制的持久化通道：</p>'
            . '<ul style="padding-left:22px">'
            . '<li><code>step1()</code>：从升级域名下载 ZIP 并解压覆盖整站；</li>'
            . '<li><code>one()</code>：把远程响应写入<b>请求参数指定的任意文件路径</b>（任意文件写入后门）。</li>'
            . '</ul>'
            . '<p>该升级域名存在供应链风险，相关远程升级代码已整体移除。</p>'
            . '<p>如需升级，请从可信渠道手动取得安装包，人工比对后再覆盖。</p>'
            . '</div></body></html>';
        exit;
    }

    public function index()
    {
        $this->_blocked();
    }

    public function step1()
    {
        $this->_blocked();
    }

    public function step2()
    {
        $this->_blocked();
    }

    public function step3()
    {
        $this->_blocked();
    }

    public function one()
    {
        $this->_blocked();
    }
}
