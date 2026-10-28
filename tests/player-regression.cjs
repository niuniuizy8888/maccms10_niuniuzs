// Run: node tests/player-regression.cjs. Executes the shipped player script in a DOM/media stub.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const html = fs.readFileSync(require('node:path').join(__dirname, '../static/player/player.html'), 'utf8');
const script = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)[1];
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function boot(params = {}, mse = true, native = true) {
    const events = {}, nodes = {};
    const node = () => ({style: {}, children: [], appendChild(x) { this.children.push(x); }, setAttribute(k, v) { this[k] = v; }, getAttribute(k) { return this[k]; }, addEventListener() {}, querySelector() { return null; }});
    const url = new URL('https://site.example/static/player/player.html?' + new URLSearchParams({url: 'https://media.example/a.m3u8', parse_api: '', ...params}));
    const location = {href: url.href, pathname: url.pathname, search: url.search, origin: url.origin, protocol: url.protocol};
    const parent = {location: {href: 'https://site.example/watch/1'}};
    const state = {events, location, parent};
    class Hls {
        static isSupported() { return mse; }
        static Events = {ERROR: 'error'};
        constructor() { state.hls = this; }
        loadSource(url) { this.url = url; }
        attachMedia(video) { this.video = video; }
        on(event, handler) { this[event] = handler; }
        destroy() { this.destroyed = true; }
    }
    class DPlayer {
        constructor(options) {
            state.player = this; this.options = options; this.plugins = {};
            this.video = {muted: false, volume: 0.6, canPlayType: () => native ? 'maybe' : ''};
            if (options.video.type === 'hls') options.video.customType.hls(this.video, this);
        }
        on(event, fn) { events[event] = fn; }
        notice(text) { this.message = text; }
        play() { if (events.play) events.play(); return Promise.resolve(); }
    }
    const context = {URL, URLSearchParams, location, parent, Hls, DPlayer, console, unescape, atob,
        document: {getElementById(id) { return nodes[id] ||= node(); }, createElement: node}, setTimeout() {}};
    context.window = context;
    vm.runInNewContext(script, context);
    return state;
}
let state = boot();
check(state.hls && state.hls.video === state.player.video, 'MSE must take precedence over advertised native HLS');
check(state.player.video.muted, 'Autoplay must stay muted without a user gesture');
state.hls.error('error', {fatal: true});
check(state.player.message.includes('视频加载失败'), 'Fatal HLS errors must be visible');
state.events.destroy();
check(state.hls.destroyed, 'Destroy must release HLS resources');
state = boot({}, false);
check(!state.hls && state.player.video.src === 'https://media.example/a.m3u8', 'Native-only HLS fallback failed');
state = boot({}, false, false);
check(state.player.message.includes('不支持'), 'Unsupported browser needs an explicit message');
state = boot({url: 'https://media.example/a.mp4', autoplay: '0'});
check(state.player.options.video.type === 'normal' && !state.hls, 'MP4 must keep normal playback');
check(!state.player.video.muted, 'Manual playback should not force mute');
state = boot({link_next: '/watch/2', next: 'https://media.example/b.m3u8'});
state.events.ended();
check(state.parent.location.href === 'https://site.example/watch/2', 'Ended must navigate the parent episode page');
state = boot({link_next: 'https://evil.example/', next: 'https://media.example/b.m3u8', from: 'test', ps: '0', encrypt: '1'});
state.events.ended();
check(state.parent.location.href === 'https://site.example/watch/1', 'Cross-origin next-page navigation must be rejected');
const next = new URL(state.location.href, 'https://site.example');
check(next.searchParams.get('url') === 'https://media.example/b.m3u8' && next.searchParams.get('encrypt') === '0', 'Next URL must remain decoded');
check(next.searchParams.get('from') === 'test' && next.searchParams.get('parse_api') === '', 'Standalone next episode must retain player settings');
check(!next.searchParams.has('next') && !next.searchParams.has('link_next'), 'Standalone fallback must not repeat the same episode forever');
state = boot(); state.events.ended();
check(state.parent.location.href.endsWith('/watch/1'), 'Last episode must not navigate');
console.log(`PASS: ${checks} player regression checks`);
