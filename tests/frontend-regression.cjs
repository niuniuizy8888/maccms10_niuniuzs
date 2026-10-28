const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
let checks=0, rendered='';const cookie={};
const chain={length:1,hover(){return this},show(){return this},hide(){return this},after(s){rendered=s;return this},height(){return 10},position(){return {left:0,top:0}},css(){return this},attr(){return undefined},html(){return this}};
function $(arg){if(typeof arg==='function')return;return chain;}
const context={console,$,navigator:{userAgent:''},document:{URL:'https://example.test/watch/1',title:'test',cookie:''},setTimeout(){},clearTimeout(){},location:{href:'https://example.test/watch/1'}};context.window=context;
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../static/js/home.js'),'utf8'),context);
const h=context.MAC.History;context.MAC.Cookie.Get=k=>cookie[k];context.MAC.Cookie.Set=(k,v)=>cookie[k]=v;
function check(v,m){assert.ok(v,m);checks++;}
h.Init();check(rendered.includes('暂无浏览记录'),'Empty history initialization failed');
h.Set('First','https://example.test/watch/1','');check(h._parse(cookie.mac_history).length===1,'First history write failed');
h.Set('Second','https://example.test/watch/2','');check(h._parse(cookie.mac_history).length===2,'Existing history loop failed');
h.Set('Second','https://example.test/watch/2','');check(h._parse(cookie.mac_history).length===2,'Repeated history duplicated');
h.Init();check(rendered.includes('First')&&rendered.includes('Second'),'History rendering failed');
for(const key of ['html','jsonstr','$i','$type'])check(!Object.hasOwn(context,key),'History leaked global '+key);
console.log('PASS: '+checks+' frontend compatibility checks');
