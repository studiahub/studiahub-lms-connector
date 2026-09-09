// Run: node --test tests/course-pitch-sticky.cjs
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { runInNewContext } = require('node:vm');
const source = readFileSync('plugin/studiahub-lms-connector/includes/class-shortcode-coursepitch.php', 'utf8');
const script = source.match(/<script data-slc-proposal-sticky>([\s\S]*?)<\/script>/)?.[1];
function fixture({ headers = [], bars = [], height = 500, viewport = 1000, mobile = false } = {}) {
    const values = {};
    const events = {};
    const frames = [];
    let resize;
    const body = {};
    const column = {
        style: { setProperty: (key, value) => { values[key] = value; } },
        getBoundingClientRect: () => ({ left: 100, right: 600, height }),
    };
    const prepare = header => {
        header.addEventListener = (name, callback) => { header[name] = callback; };
        header.parentElement ||= body;
        header.querySelectorAll = () => header.children || [];
        header.getBoundingClientRect = () => ({ left: 0, right: 1200, ...header.rect });
        if (header.parentElement !== body) prepare(header.parentElement);
        (header.children || []).forEach(prepare);
    };
    headers.forEach(prepare);
    bars.forEach(prepare);
    let queries = 0;
    const window = { innerHeight: viewport,
        matchMedia: () => ({ matches: mobile }),
        addEventListener: (name, callback) => { events[name] = callback; },
    };
    runInNewContext(script || '', {
        window, document: { body, documentElement: {}, querySelectorAll: selector => {
            queries++;
            return selector.includes('longdesc-left') ? [column] : selector.includes('topbar') ? bars : headers;
        } },
        getComputedStyle: el => ({ position: 'fixed', visibility: 'visible', display: 'block', opacity: '1', ...el.css }),
        requestAnimationFrame: callback => { frames.push(callback); },
        ResizeObserver: class { constructor(callback) { resize = callback; } observe() {} },
    });
    const flush = () => { while (frames.length) frames.shift()(); };
    flush();
    return { values, window, events, flush, resize: () => { resize?.(); flush(); }, queries: () => queries };
}
const top = state => state.values['--slc-proposal-top'];
test('proposal uses a measured offset instead of a hardcoded top', () => {
    assert.ok(script, 'renderer includes isolated proposal controller');
    assert.equal(top(fixture()), '16px');
});
test('NUA stacked wrapper and WordPress toolbar do not double count', () => {
    assert.equal(top(fixture({ headers: [
        { rect: { top: 0, bottom: 32, height: 32 } },
        { rect: { top: 32, bottom: 188, height: 156 }, css: { position: 'sticky' } },
        { rect: { top: 62, bottom: 188, height: 126 } },
    ] })), '204px');
});
test('incognito, shrinking and hidden headers recompute without DOM rescans', () => {
    const header = { rect: { top: 0, bottom: 156, height: 156 }, css: { position: 'sticky' } };
    const state = fixture({ headers: [header] });
    assert.equal(top(state), '172px');
    header.rect.bottom = 80;
    state.resize();
    assert.equal(top(state), '96px');
    header.rect.bottom = 0;
    state.events.scroll(); state.flush();
    assert.equal(top(state), '16px');
    assert.equal(state.queries(), 3);
});
test('static, hidden, offscreen and floating non-top headers do not reserve space', () => {
    const headers = [
        { rect: { top: 0, bottom: 200, height: 200 }, css: { position: 'static' } },
        { rect: { top: 0, bottom: 200, height: 200 }, css: { visibility: 'hidden' } },
        { rect: { top: 800, bottom: 900, height: 100 } },
        { rect: { top: -100, bottom: 0, height: 100 } },
    ];
    assert.equal(top(fixture({ headers })), '16px');
});
test('short viewports and mobile keep the column in normal flow', () => {
    assert.equal(fixture({ viewport: 500 }).values['--slc-proposal-position'], 'static');
    assert.equal(fixture({ mobile: true }).values['--slc-proposal-position'], 'static');
    assert.equal(fixture().values['--slc-proposal-position'], 'sticky');
});

test('semantic header finds its sticky wrapper and Elementor sticky child', () => {
    const wrapper = { rect: { top: 0, bottom: 156, height: 156 }, css: { position: 'sticky' } };
    const header = { parentElement: wrapper, rect: { top: 30, bottom: 156, height: 126 }, css: { position: 'static' } };
    assert.equal(top(fixture({ headers: [header] })), '172px');
    const builder = { rect: { top: 0, bottom: 0, height: 0 }, css: { position: 'static' }, children: [
        { rect: { top: 0, bottom: 100, height: 100 } },
    ] };
    assert.equal(top(fixture({ headers: [builder] })), '116px');
});
test('header switching to fixed on scroll is measured; scroll callbacks are batched', () => {
    const header = { rect: { top: 0, bottom: 120, height: 120 }, css: { position: 'static' } };
    const state = fixture({ headers: [header] });
    assert.equal(top(state), '16px');
    header.css.position = 'fixed';
    state.events.scroll(); state.events.scroll(); state.flush();
    assert.equal(top(state), '136px');
    assert.equal(state.queries(), 3);
});
test('CSS and controller preserve no-media/mobile normal flow', () => {
    const css = readFileSync('plugin/studiahub-lms-connector/assets/css/coursepitch.css', 'utf8');
    assert.match(script, /:not\(\.slc-cpitch__longdesc-grid--no-media\)/);
    assert.match(css, /\.slc-cpitch__longdesc-grid--no-media \.slc-cpitch__longdesc-left\s*\{\s*position: static;/);
    assert.match(css, /@media \(max-width: 960px\)\s*\{[^@]*\.slc-cpitch__longdesc-left \{ position: static; \}/);
    assert.match(css, /position: var\(--slc-proposal-position, static\)/);
});

test('docked countdown or purchase bar reduces available viewport height', () => {
    const headers = [{ rect: { top: 0, bottom: 188, height: 188 } }];
    const bar = { rect: { top: 740, bottom: 800, height: 60 } };
    const state = fixture({ headers, bars: [bar], height: 550, viewport: 800 });
    assert.equal(top(state), '204px');
    assert.equal(state.values['--slc-proposal-position'], 'static');
    bar.rect.top = 800; bar.rect.bottom = 860;
    bar.transitionend?.(); state.flush();
    assert.equal(state.values['--slc-proposal-position'], 'sticky');
});
test('hidden or horizontally non-overlapping bottom bars reserve no space', () => {
    const headers = [{ rect: { top: 0, bottom: 188, height: 188 } }];
    for (const bar of [
        { rect: { top: 740, bottom: 800, height: 60 }, css: { display: 'none' } },
        { rect: { top: 740, bottom: 800, height: 60 }, css: { visibility: 'hidden' } },
        { rect: { top: 740, bottom: 800, height: 60 }, css: { opacity: '0' } },
        { rect: { top: 740, bottom: 800, height: 60, left: 700, right: 1200 } },
    ]) {
        const state = fixture({ headers, bars: [bar], height: 550, viewport: 800 });
        assert.equal(state.values['--slc-proposal-position'], 'sticky');
    }
});
