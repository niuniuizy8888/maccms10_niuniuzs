<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

// 后台登录验证码：降低识别难度（纯大写+数字 4 位、无噪点/干扰线），
// 与登录页 input 的 uppercase 展示一致，避免大小写混排导致反复重试。
return [
    'codeSet'  => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ',
    'length'   => 4,
    'fontSize' => 18,
    'secure'   => false,
    'useNoise' => false,
    'useCurve' => false,
    'expire'   => 600,
];
