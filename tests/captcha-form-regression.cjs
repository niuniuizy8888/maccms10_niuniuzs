// Execute the real theme script with a small DOM and XHR harness; no browser or network.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
for (const code of [1002, 1]) {
  let reloads = 0, request;
  const handlers = {}, button = {disabled:false};
  const alert = {setAttribute(){}};
  const image = {src:'https://example.test/index.php/verify/index.html',getAttribute(){return null},setAttribute(){},addEventListener(){}};
  const form = {
    addEventListener(event, fn){handlers[event]=fn},
    getAttribute(name){return name === 'action' ? '/index.php/ajax/verify_check.html' : null},
    querySelector(selector){return selector === '[type=submit]' ? button : selector === '.alert' ? alert : selector === '.captcha-img' ? image : null},
  };
  const context = {
    window:{},
    document:{readyState:'complete',documentElement:{},getElementById(id){return id==='verify-form'?form:null},querySelector(){return null},querySelectorAll(){return []}},
    location:{pathname:'/index.php/vod/search',reload(){reloads++}},
    URLSearchParams,
    FormData:class { *[Symbol.iterator](){yield ['type','search'];yield ['verify','ABCD']} },
    XMLHttpRequest:class {
      constructor(){request=this} open(method,url){this.method=method;this.url=url} setRequestHeader(){}
      send(body){this.body=body;this.status=200;this.responseText=JSON.stringify({code,msg:'result'});this.readyState=4;this.onreadystatechange()}
    },
  };
  vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../template/default/js/nn.js'),'utf8'),context);
  check(typeof handlers.submit==='function','Search verification form was not bound');
  let prevented=false;handlers.submit({preventDefault(){prevented=true}});
  check(prevented && request.method==='POST' && request.url.endsWith('ajax/verify_check.html'),'Verification submitted to wrong endpoint');
  check(request.body==='type=search&verify=ABCD','Verification fields lost');
  check(!button.disabled,'Submit button did not recover');
  check(reloads===(code===1?1:0),'Verification reload handling failed');
  if(code!==1)check(image.src.includes('?t=') && alert.textContent==='result','Failure did not refresh image and show error');
}
console.log('PASS: '+checks+' search captcha form checks');
