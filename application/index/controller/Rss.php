<?php
namespace app\index\controller;

class Rss extends Base
{
    public function index()
    {
        return $this->feed('rss/index');
    }

    public function baidu()
    {
        return $this->feed('rss/baidu', true);
    }

    public function google()
    {
        return $this->feed('rss/google', true);
    }

    public function so()
    {
        return $this->feed('rss/so', true);
    }

    public function sogou()
    {
        return $this->feed('rss/sogou', true);
    }

    public function bing()
    {
        return $this->feed('rss/bing', true);
    }

    public function sm()
    {
        return $this->feed('rss/sm', true);
    }

}
