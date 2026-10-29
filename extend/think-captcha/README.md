# 内置验证码组件

本目录随程序分发 `topthink/think-captcha` 1.0.8 的源码、字体和背景图片，适用于 ThinkPHP 5.0；采用 Apache-2.0 许可，原始版权和许可见 `LICENSE` 及源码文件头。

主程序的自动加载器优先加载本目录，即使没有 `vendor` 或 Composer 自动加载文件也能使用。请完整上传本目录，不能只上传 PHP 文件。绘图需要 PHP GD、FreeType 和 mbstring。

验证码配置位于 `application/extra/captcha.php`，生成入口位于 `application/index/controller/Verify.php`，一次性校验由 `mac_captcha_check()` 统一处理。不要关闭后台验证码来掩盖图片或会话故障。

本地兼容调整：绘图时使用局部字体路径，避免重复调用同一实例时拼接出错误路径；模板图片辅助函数使用程序已有的验证码入口，不依赖自定义路由。
