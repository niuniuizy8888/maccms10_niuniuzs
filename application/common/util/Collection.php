<?php
namespace app\common\util;

class Collection {

    protected static $url,$config;

    /**
     * 采集内容
     * @param string $url 采集地址
     * @param array $config 配置参数
     * @param integer $page 分页采集模式
     */
    public static function get_content($url, $config, $page = 0) {
        set_time_limit(300);
        static $oldurl = array();
        $page = intval($page) ? intval($page) : 0;
        if ($html = self::get_html($url, $config)) {

            if (empty($page)) {
                // 获取标题
                if ($config['title_rule']) {
                    if(strpos($config['title_rule'],'[内容]')===false){
                        $data['title'] = $config['title_rule'];
                    }
                    else{
                        $title_rule = self::replace_sg($config['title_rule']);
                        $data['title'] = self::replace_item(self::cut_html($html, $title_rule[0], $title_rule[1]), $config['title_html_rule']);
                    }
                }

                // 获取分类
                if ($config['type_rule']) {
                    if(strpos($config['type_rule'],'[内容]')===false){
                        $data['type'] = $config['type_rule'];
                    }
                    else{
                        $type_rule =  self::replace_sg($config['type_rule']);
                        $data['type'] = self::replace_item(self::cut_html($html, $type_rule[0], $type_rule[1]), $config['type_html_rule']);
                    }
                }

                if (empty($data['time'])) $data['time'] = time();

                // 对自定义数据进行采集
                $config['customize_config']  = json_decode($config['customize_config'],true);

                if($config['customize_config']){
                    foreach ($config['customize_config'] as $k=>$v) {
                        if (empty($v['rule'])) continue;
                        if(strpos($v['rule'],'[内容]')===false){
                            $data[$v['en_name']] = $v['rule'];
                        }
                        else{
                            $rule =  self::replace_sg($v['rule']);
                            $data[$v['en_name']] = self::replace_item(self::cut_html($html, $rule[0], $rule[1]), $v['html_rule']);
                        }
                    }
                }
            }

            // 获取内容
            if ($config['content_rule']) {
                if(strpos($config['content_rule'],'[内容]')===false){
                    $data['content'] = $config['content_rule'];
                }
                else{
                    $content_rule =  self::replace_sg($config['content_rule']);
                    $data['content'] = self::replace_item(self::cut_html($html, $content_rule[0], $content_rule[1]), $config['content_html_rule']);
                }

            }


            // 处理分页
            if (in_array($page, array(0,2)) && !empty($config['content_page_start']) && !empty($config['content_page_end'])) {
                $oldurl[] = $url;
                $tmp[] = $data['content'];
                $page_html = self::cut_html($html, $config['content_page_start'], $config['content_page_end']);
                // 上下页模式
                if ($config['content_page_rule'] == 2 && in_array($page, array(0,2)) && $page_html) {
                    preg_match_all('/<a [^>]*href=[\'"]?([^>\'" ]*)[\'"]?[^>]*>([^<\/]*)<\/a>/i', $page_html, $out);
                    if (!empty($out[1]) && !empty($out[2])) {
                        foreach ($out[2] as $k=>$v) {
                            if (strpos($v, $config['content_nextpage']) === false) continue;
                            if ($out[1][$k] == '#') continue;
                            $out[1][$k] = self::url_check($out[1][$k], $url, $config);
                            if (in_array($out[1][$k], $oldurl)) continue;
                            $oldurl[] = $out[1][$k];
                            $results = self::get_content($out[1][$k], $config, 2);
                            if (!in_array($results['content'], $tmp)) $tmp[] = $results['content'];
                        }
                    }
                }

                // 全部罗列模式
                if ($config['content_page_rule'] == 1 && $page == 0 && $page_html) {
                    preg_match_all('/<a [^>]*href=[\'"]?([^>\'" ]*)[\'"]?/i', $page_html, $out);
                    if (is_array($out[1]) && !empty($out[1])) {

                        $out = array_unique($out[1]);
                        foreach ($out as $k=>$v) {
                            if ($out[1][$k] == '#') continue;
                            $v = self::url_check($v, $url, $config);
                            $results = self::get_content($v, $config, 1);
                            if (!in_array($results['content'], $tmp) && $results['content']!="") $tmp[] = $results['content'];
                        }
                    }

                }
                $data['content'] = $config['content_page'] == 1 ? implode('[page]', $tmp) : implode('', $tmp);
            }

            if ($page == 0) {
                self::$url = $url;
                self::$config = $config;
                $data['content'] = preg_replace_callback('/<img[^>]*src=[\'"]?([^>\'"\s]*)[\'"]?[^>]*>/i', array('collection','download_img_callback'), $data['content']);
                // 下载内容中的图片到本地
                if (empty($page) && !empty($data['content']) && $config['down_attachment'] == 1) {

                }
            }
            return $data;
        }
    }

    /**
     * 转换图片地址为绝对路径，为下载做准备。
     * @param array $out 图片地址
     */
    protected static function download_img_callback($matches) {
        return self::download_img($matches[0], $matches[1]);
    }
    protected static function download_img($old, $out) {
        if (!empty($old) && !empty($out) && strpos($out, '://') === false) {
            return str_replace($out, self::url_check($out, self::$url, self::$config), $old);
        } else {
            return $old;
        }
    }

    /**
     * 得到需要采集的网页列表页
     * @param array $config 配置参数
     * @param integer $num 返回数
     */
    public static function url_list(&$config, $num = '') {
        $url = array();

        switch ($config['sourcetype']) {
            case '1'://序列化
                $num = empty($num) ? $config['pagesize_end'] : $num;
                if($num<$config['pagesize_start']) $num=$config['pagesize_start'];
                $p=0;
                for ($i = $config['pagesize_start']; $i <= $num; $i = $i + $config['par_num']) {
                    $url[$p] = str_replace('(*)', $i, $config['urlpage']);
                    $p++;
                }
                break;
            case '2'://多网址
                $url = explode("\r\n", $config['urlpage']);
                break;
            case '3'://单一网址
            case '4'://RSS
                $url[] = $config['urlpage'];
                break;
        }
        return $url;
    }

    /**
     * 获取文章网址
     * @param string $url 采集地址
     * @param array $config 配置
     */
    public static function get_url_lists($url, &$config) {
        if ($html = self::get_html($url, $config)) {
            if ($config['sourcetype'] == 4) { //RSS
                // 修 原实现调用 PHPCMS 移植残留
                // pc_base::load_sys_class('xml') / pc_base::load_config / array_iconv
                // 三者在全库均无定义（已 grep 确认），采集节点选 RSS(sourcetype=4) 必然
                // Fatal error: Class "pc_base" not found → HTTP 500。
                // 改为纯 PHP 实现（SimpleXML + 编码转换），返回结构保持不变。
                $data = self::parse_rss_list($html, isset($config['sourcecharset']) ? $config['sourcecharset'] : '');
            } else {
                $html = self::cut_html($html, $config['url_start'], $config['url_end']);

                $html = str_replace(array("\r", "\n"), '', $html);
                $html = str_replace(array("</a>", "</A>"), "</a>\n", $html);

                preg_match_all('/<a ([^>]*)>([^\/a>].*)<\/a>/i', $html, $out);
                // $out[1] = array_unique($out[1])
                // $out[2] = array_unique($out[2])

                $data = array();
                foreach ($out[1] as $k=>$v) {
                    if (preg_match('/href=[\'"]?([^\'" ]*)[\'"]?/i', $v, $match_out)) {
                        if ($config['url_contain']) {
                            if (strpos($match_out[1], $config['url_contain']) === false) {
                                continue;
                            }
                        }

                        if ($config['url_except']) {
                            if (strpos($match_out[1], $config['url_except']) !== false) {
                                continue;
                            }
                        }
                        $url2 = $match_out[1];
                        $url2 = self::url_check($url2, $url, $config);

                        $data[$k]['url'] = $url2;
                        $data[$k]['title'] = strip_tags($out[2][$k]);
                    } else {
                        continue;
                    }
                }

            }
            return $data;
        } else {
            return false;
        }
    }

    /**
     * 解析 RSS / Atom 列表页，返回 [['url' => .., 'title' => ..], ...]
     *
     * 纯 PHP XML 解析（替换已失效的 PHPCMS xml 类）：
     * · 用 SimpleXML 解析，失败时返回空数组而不是致命错误 —— 调用方 Cj.php:163 会
     * `count($url)`，PHP 8 下 count(false) 直接抛 TypeError
     * · 安全：LIBXML_NONET 禁止解析期发起网络请求；libxml >= 2.9 默认不加载外部实体
     * · 编码：XML 声明里若标了 GBK/GB2312 等，先把整串转成 UTF-8 再解析
     * 声明缺失时回退用采集配置的 sourcecharset 再试一次
     * · 兼容 RSS 2.0（channel/item）、RSS 1.0/RDF（item）、Atom（默认命名空间 entry）。
     *
     * @param string $html 远端返回的 XML 文本
     * @param string $sourceCharset 采集节点配置的源编码（可为空）
     * @return array
     */
    public static function parse_rss_list($html, $sourceCharset = '') {
        $data = array();
        if (!is_string($html) || $html === '') { return $data; }

        $prev = libxml_use_internal_errors(true);
        $xml  = self::rss_load_xml($html);
        // 声明缺失/不可信时，回退用采集配置的源编码整串转换后再解析
        if ($xml === false && is_string($sourceCharset) && $sourceCharset !== ''
            && !in_array(strtoupper($sourceCharset), array('UTF-8', 'UTF8'), true)) {
            $conv = self::rss_convert_encoding($html, 'UTF-8', $sourceCharset);
            if (is_string($conv) && $conv !== '' && $conv !== $html) { $xml = self::rss_load_xml($conv); }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($xml === false) { return $data; }   // 降级：可读的空结果，不是致命错误

        $sets = array();
        if (isset($xml->channel->item)) { $sets[] = $xml->channel->item; }   // RSS 2.0
        if (isset($xml->item))         { $sets[] = $xml->item; }            // RSS 1.0 / RDF
        $ns = $xml->getNamespaces(true);
        if (isset($ns[''])) {                                                // Atom（默认命名空间）
            $atom = $xml->children($ns['']);
            if (isset($atom->entry)) { $sets[] = $atom->entry; }
        }

        foreach ($sets as $set) {
            foreach ($set as $item) {
                $data[] = array(
                    'url'   => self::rss_item_link($item),
                    'title' => isset($item->title) ? trim((string)$item->title) : '',
                );
            }
            if (!empty($data)) { break; }   // 命中一种结构即止，避免同一份 feed 被计两次
        }

        // 等价于原 array_iconv 的递归数组转换：仅当站点目标编码不是 UTF-8 时才需要。
        // MacCMS v10 为 UTF-8（extra/maccms.php 无 charset 键、库 charset=utf8），故通常为 no-op。
        $siteCharset = '';
        if (function_exists('config')) {
            $maccms = config('maccms');
            if (is_array($maccms) && !empty($maccms['app']['charset'])) {
                $siteCharset = (string)$maccms['app']['charset'];
            }
        }
        if ($siteCharset !== '' && !in_array(strtoupper($siteCharset), array('UTF-8', 'UTF8'), true)) {
            $data = self::rss_array_convert($data, $siteCharset, 'UTF-8');
        }

        return $data;
    }

    /**
     * 把 XML 文本按声明编码归一到 UTF-8 并解析；失败返回 false。
     */
    protected static function rss_load_xml($html) {
        return @simplexml_load_string(
            self::rss_normalize_utf8($html),
            'SimpleXMLElement',
            LIBXML_NOCDATA | LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
        );
    }

    /**
     * 读取 XML 声明/HTML meta 里的编码；无声明返回 ''。
     */
    protected static function rss_detect_encoding($html) {
        if (preg_match('/<\?xml[^>]*encoding\s*=\s*["\']([A-Za-z0-9._-]+)["\']/i', $html, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/<meta[^>]*charset\s*=\s*["\']?([A-Za-z0-9._-]+)/i', $html, $m)) {
            return strtoupper($m[1]);
        }
        return '';
    }

    /**
     * 若声明了非 UTF-8 编码，则整串转 UTF-8 并把声明改写成 UTF-8。
     */
    protected static function rss_normalize_utf8($html) {
        $enc = self::rss_detect_encoding($html);
        if ($enc === '' || in_array($enc, array('UTF-8', 'UTF8'), true)) { return $html; }
        $conv = self::rss_convert_encoding($html, 'UTF-8', $enc);
        if (!is_string($conv) || $conv === '') { return $html; }
        return preg_replace('/(<\?xml[^>]*encoding\s*=\s*["\'])[A-Za-z0-9._-]+(["\'])/i', '${1}UTF-8${2}', $conv, 1);
    }

    /**
     * 编码转换：优先 mb_convert_encoding，其次 iconv；都不可用/失败返回 false。
     */
    protected static function rss_convert_encoding($str, $to, $from) {
        if (!is_string($str) || $str === '') { return $str; }
        $to   = strtoupper(trim((string)$to));
        $from = strtoupper(trim((string)$from));
        if ($to === '' || $from === '' || $to === $from) { return $str; }
        if (function_exists('mb_convert_encoding')) {
            $r = @mb_convert_encoding($str, $to, $from);
            if (is_string($r) && $r !== '') { return $r; }
        }
        if (function_exists('iconv')) {
            $r = @iconv($from, $to . '//IGNORE', $str);
            if (is_string($r) && $r !== '') { return $r; }
        }
        return false;
    }

    /**
     * array_iconv 的等价递归实现（原函数在本库中不存在）：对数组做深度编码转换。
     */
    protected static function rss_array_convert($data, $to, $from) {
        if (is_array($data)) {
            $out = array();
            foreach ($data as $k => $v) { $out[$k] = self::rss_array_convert($v, $to, $from); }
            return $out;
        }
        if (is_string($data)) {
            $r = self::rss_convert_encoding($data, $to, $from);
            return is_string($r) ? $r : $data;
        }
        return $data;
    }

    /**
     * 取 RSS/Atom 条目的链接：RSS 为 <link>text</link>，Atom 为 <link href=".."/>。
     * 注意：命名空间（Atom 默认 xmlns）下的属性必须走 ->attributes
     * `$node['href']` 在带命名空间的元素上取不到（实测返回 false）。
     */
    protected static function rss_item_link($item) {
        if (!isset($item->link)) { return ''; }
        $fallback = '';
        foreach ($item->link as $l) {
            $attr = $l->attributes();
            if (isset($attr['href'])) {                                  // Atom: <link href=".."/>
                $rel  = isset($attr['rel']) ? strtolower(trim((string)$attr['rel'])) : '';
                $href = trim((string)$attr['href']);
                if ($rel === '' || $rel === 'alternate') { return $href; }
                if ($fallback === '') { $fallback = $href; }
            } else {                                                     // RSS: <link>text</link>
                $txt = trim((string)$l);
                if ($txt !== '') { return $txt; }
            }
        }
        return $fallback;
    }

    /**
     * 获取远程HTML
     * @param string $url 获取地址
     * @param array $config 配置
     */
    protected static function get_html($url, &$config) {
        // 安全：采集抓取禁跟随重定向 —— 采集地址由后台配置
        // 且源头可能是第三方资源站；允许 302 会让"初始校验为公网"的地址被弹到内网/云元数据。
        if (!empty($url) && $html = mac_curl_get($url, array(), '', true)) {
            if ('UTF-8' != $config['sourcecharset'] && $config['sourcetype'] != 4) {
                $html = iconv($config['sourcecharset'], 'UTF-8//TRANSLIT//IGNORE', $html);
            }
            return $html;
        } else {
            return false;
        }
    }

    /**
     *
     * HTML切取
     * @param string $html 要进入切取的HTML代码
     * @param string $start 开始
     * @param string $end 结束
     */
    protected static function cut_html($html, $start, $end) {
        if (empty($html)) return false;
        $html = str_replace(array("\r", "\n"), "", $html);
        $start = str_replace(array("\r", "\n"), "", $start);
        $end = str_replace(array("\r", "\n"), "", $end);
        $html = explode(trim($start), $html);
        if(is_array($html)) $html = explode(trim($end), $html[1]);
        return trim($html[0]);
    }

    /**
     * 过滤代码
     * @param string $html HTML代码
     * @param array $config 过滤配置
     */
    protected static function replace_item($html, $config) {
        if (empty($config)) return $html;
        $config = explode("\n", $config);
        $patterns = $replace = array();
        $p = 0;
        foreach ($config as $k=>$v) {
            if (empty($v)) continue;
            $c = explode('[|]', $v);
            $patterns[$k] = '/'.str_replace('/', '\/', $c[0]).'/i';
            $replace[$k] = $c[1];
            $p = 1;
        }
        return $p ? @preg_replace($patterns, $replace, $html) : false;
    }

    /**
     * 替换采集内容
     * @param $html 采集规则
     */
    protected static function replace_sg($html) {
        $list = explode('[内容]', $html);
        if (is_array($list)) foreach ($list as $k=>$v) {
            $list[$k] = str_replace(array("\r", "\n"), '', trim($v));
        }
        return $list;
    }

    /**
     * URL地址检查
     * @param string $url 需要检查的URL
     * @param string $baseurl 基本URL
     * @param array $config 配置信息
     */
    protected static function url_check($url, $baseurl, $config) {
        $urlinfo = parse_url($baseurl);

        $baseurl = $urlinfo['scheme'].'://'.$urlinfo['host'].(substr($urlinfo['path'], -1, 1) === '/' ? substr($urlinfo['path'], 0, -1) : str_replace('\\', '/', dirname($urlinfo['path']))).'/';
        if (strpos($url, '://') === false) {
            if ($url[0] == '/') {
                $url = $urlinfo['scheme'].'://'.$urlinfo['host'].$url;
            } else {
                if ($config['page_base']) {
                    $url = $config['page_base'].$url;
                } else {
                    $url = $baseurl.$url;
                }
            }
        }
        return $url;
    }
}