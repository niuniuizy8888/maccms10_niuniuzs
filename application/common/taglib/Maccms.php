<?php
namespace app\common\taglib;
use think\template\TagLib;
use think\Db;

class Maccms extends Taglib {

	protected $tags = [
	    'link'=> ['attr'=>'order,by,type,not,start,num,cachetime'],
        'area'=> ['attr'=>'order,start,num'],
        'lang'=> ['attr'=>'order,start,num'],
        'year'=> ['attr'=>'order,start,num'],
        'class'=> ['attr'=>'order,start,num'],
        'version'=> ['attr'=>'order,start,num'],
        'state'=> ['attr'=>'order,start,num'],
        'letter'=> ['attr'=>'order,start,num'],
        'type' => ['attr' =>'order,by,start,num,id,ids,not,parent,flag,mid,format,cachetime'],
        'comment'=>['attr' =>'order,by,start,num,paging,pageurl,id,pid,rid,mid,uid,half'],
        'gbook'=>['attr' =>'order,by,start,num,paging,pageurl,rid,uid,half'],
        'role'=>['attr' =>'order,by,start,num,paging,pageurl,id,ids,not,rid,actor,name,level,letter,half,timeadd,timehits,time,cachetime'],
        'actor'=>['attr' =>'order,by,start,num,paging,pageurl,id,ids,not,area,sex,name,level,letter,type,typenot,starsign,blood,half,timeadd,timehits,time,cachetime'],
        'topic' => ['attr' =>'order,by,start,num,id,ids,not,paging,pageurl,class,tag,half,timeadd,timehits,time,cachetime'],
        'art' => ['attr' =>'order,by,start,num,id,ids,not,paging,pageurl,type,typenot,class,tag,level,letter,half,rel,timeadd,timehits,time,hitsmonth,hitsweek,hitsday,hits,cachetime'],
        'vod' => ['attr' =>'order,by,start,num,id,ids,not,paging,pageurl,type,typenot,class,tag,area,lang,year,level,letter,half,rel,version,state,tv,weekday,timeadd,timehits,time,hitsmonth,hitsweek,hitsday,hits,isend,cachetime,actor,director,plot,name,wd'],
        'website'=>['attr' =>'order,by,start,num,paging,pageurl,id,ids,not,area,lang,name,level,letter,type,typenot,half,timeadd,timehits,time,cachetime'],
        'foreach' => ['attr'=>'name,id,key'],
        'for' => ['attr'=>'start,end,comparison,step,name'],
    ];

    protected function normalizeTag($name, $tag, $defaults = [])
    {
        $attrs = isset($this->tags[$name]['attr']) ? explode(',', $this->tags[$name]['attr']) : [];
        // PHP8-COMPAT-SHIM (local comparison environment only):
        // PHP 8 throws TypeError on abs(''), while PHP 7 treated the '' default as 0.
        // Real MacCMS themes omit numeric attrs (start/half/rid/...), so default the
        // ones that reach abs() to '0'. Behavior is identical to PHP 7; remove this
        // block once the old site runs under PHP 7.4.
        $numericAttrs = ['start', 'num', 'half', 'rid', 'uid', 'pid', 'mid'];
        foreach ($attrs as $attr) {
            $attr = trim($attr);
            if ($attr !== '' && !array_key_exists($attr, $tag)) {
                $tag[$attr] = in_array($attr, $numericAttrs, true) ? '0' : '';
            }
        }
        foreach ($defaults as $key => $value) {
            if (!isset($tag[$key]) || $tag[$key] === '') {
                $tag[$key] = $value;
            }
        }
        return $tag;
    }

    public function tagFor($tag,$content)
    {
        $tag = $this->normalizeTag('for', $tag);
        if(empty($tag['start'])){
            $tag['start'] = 1;
        }
        if(empty($tag['end'])){
            $tag['end'] = 5;
        }
        if(empty($tag['comparison'])){
            $tag['comparison'] = 'elt';
        }
        if(empty($tag['step'])){
            $tag['step'] = 1;
        }
        if(empty($tag['name'])){
            $tag['name'] = 'i';
        }

        $parse='';
        $parse .= '{for start="'.$tag['start'].'" end="'.$tag['end'].'" comparison="'.$tag['comparison'].'" step="'.$tag['step'].'" name="'.$tag['name'].'"}';
        $parse .= $content;
        $parse .= '{/for}';

        return $parse;
    }

    public function tagForeach($tag,$content)
    {
        $tag = $this->normalizeTag('foreach', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }
        // foreach标签强化
        // https://github.com/magicblack/maccms10/issues/984
        $parse_addon = '';
        if(!empty($tag['offset'])){
            $parse_addon .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse_addon .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse_addon .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse_addon .= ' empty="'.$tag['empty'].'"';
        }
        $parse='';
        $parse .= '{foreach name="'.$tag['name'].'" id="'.$tag['id'].'" key="'.$tag['key'].'"' . $parse_addon . '}';
        $parse .= $content;
        $parse .= '{/foreach}';
        
        return $parse;
    }

    public function tagArea($tag,$content)
    {
        $tag = $this->normalizeTag('area', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->areaData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagLang($tag,$content)
    {
        $tag = $this->normalizeTag('lang', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->langData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagClass($tag,$content)
    {
        $tag = $this->normalizeTag('class', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->classData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagYear($tag,$content)
    {
        $tag = $this->normalizeTag('year', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->YearData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagVersion($tag,$content)
    {
        $tag = $this->normalizeTag('version', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->versionData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagState($tag,$content)
    {
        $tag = $this->normalizeTag('state', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->stateData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagLetter($tag,$content)
    {
        $tag = $this->normalizeTag('letter', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->letterData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagLink($tag,$content)
    {
        $tag = $this->normalizeTag('link', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Link")->listCacheData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagType($tag,$content)
    {
        $tag = $this->normalizeTag('type', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Type")->listCacheData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagComment($tag,$content)
    {
        $tag = $this->normalizeTag('comment', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Comment")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagGbook($tag,$content)
    {
        $tag = $this->normalizeTag('gbook', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Gbook")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagTopic($tag,$content)
    {
        $tag = $this->normalizeTag('topic', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Topic")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagActor($tag,$content)
    {
        $tag = $this->normalizeTag('actor', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Actor")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagRole($tag,$content)
    {
        $tag = $this->normalizeTag('role', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Role")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagArt($tag,$content)
    {
        $tag = $this->normalizeTag('art', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Art")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagVod($tag,$content)
    {
        $tag = $this->normalizeTag('vod', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Vod")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagWebsite($tag,$content)
    {
        $tag = $this->normalizeTag('website', $tag, ['id' => 'vo', 'key' => 'key']);
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Website")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }
}
