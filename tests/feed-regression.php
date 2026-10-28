<?php
// Pure feed URL/XML checks. No site credentials, network, or persistent data.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/application/common.php';
$n = 0;
function check($ok, $msg) { global $n; if (!$ok) { throw new RuntimeException($msg); } $n++; }
$GLOBALS['config']['site']['site_url'] = 'example.test';
$GLOBALS['http_type'] = 'http://';
check(mac_feed_url('/index.php/vod/detail?id=1&x=2') === 'http://example.test/index.php/vod/detail?id=1&amp;x=2', 'HTTP absolute link or XML query escaping');
$GLOBALS['http_type'] = 'https://';
check(mac_feed_url('/cms/detail/1') === 'https://example.test/cms/detail/1', 'HTTPS or subdirectory link');
$GLOBALS['config']['site']['site_url'] = 'https://example.test:8443/cms/';
check(mac_feed_url('/cms/detail/1') === 'https://example.test:8443/cms/detail/1', 'Explicit scheme/port or duplicated subdirectory');
check(mac_feed_url('https://media.example.test/a?x=1&y=2') === 'https://media.example.test/a?x=1&amp;y=2', 'Already absolute link');
check(mac_feed_url('//media.example.test/a') === 'https://media.example.test/a', 'Protocol-relative link');
$GLOBALS['config']['site']['site_url'] = '';
$_SERVER['SERVER_NAME'] = 'localhost'; $_SERVER['SERVER_PORT'] = 8080;
check(mac_feed_url('/') === 'https://localhost:8080/', 'Unconfigured origin fallback');
$raw = 'A & B <title> "quote" ]]> 中文';
$xml = simplexml_load_string('<root>' . mac_xml_escape($raw) . '</root>');
check($xml !== false && (string)$xml === $raw, 'XML text round trip');
check(mac_xml_escape("a\x01b") === 'ab', 'Illegal XML control character');
echo "PASS: $n feed checks\n";
