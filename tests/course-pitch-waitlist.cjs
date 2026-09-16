// Run: node --test tests/course-pitch-waitlist.cjs
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { runInNewContext } = require('node:vm');

const source = readFileSync('plugin/studiahub-lms-connector/assets/js/coursepitch-waitlist.js', 'utf8');

function element(name, document) {
    const handlers = {};
    const classes = new Set();
    return {
        name,
        hidden: false,
        disabled: false,
        value: '',
        checked: false,
        textContent: '',
        className: '',
        handlers,
        classList: {
            add: value => classes.add(value),
            remove: value => classes.delete(value),
            toggle: (value, enabled) => enabled ? classes.add(value) : classes.delete(value),
            contains: value => classes.has(value),
        },
        addEventListener: (type, handler) => { handlers[type] = handler; },
        setAttribute(key, value) { this[key] = value; },
        focus() { document.activeElement = this; this.focused = true; },
    };
}

function fixture(fetchImpl, options = {}) {
    const document = { readyState: 'complete', activeElement: null };
    const opener = element('opener', document);
    const close = element('close', document);
    const fullName = element('fullName', document); fullName.value = 'Ada Lovelace';
    const email = element('email', document); email.value = 'ada@example.com';
    const consent = element('consent', document); consent.checked = true;
    const website = element('website', document);
    const submit = element('submit', document);
    const status = element('status', document);
    const turnstileContainer = options.siteKey ? element('turnstile', document) : null;
    const controls = [fullName, email, consent, website, submit];
    const fields = element('fields', document);
    fields.querySelectorAll = () => controls;
    const form = element('form', document);
    form.elements = { fullName, email, consent, website };
    form.checkValidity = () => true;
    form.reportValidity = () => { form.reported = true; };
    form.reset = () => { form.resetCalled = true; };
    const modal = element('modal', document);
    modal.hidden = true;
    modal.closest = () => ({ querySelector: () => opener });
    modal.getAttribute = key => ({
        'data-endpoint': 'https://shop.test/wp-json/studiahub/v1/course-waitlist/42',
        'data-consent-version': 'v1',
        'data-turnstile-site-key': options.siteKey,
    })[key];
    modal.querySelector = selector => ({
        '[data-slc-waitlist-close]': close,
        '[data-slc-waitlist-form]': form,
        '[data-slc-waitlist-fields]': fields,
        '[data-slc-waitlist-submit]': submit,
        '[data-slc-waitlist-status]': status,
        '[data-slc-waitlist-turnstile]': turnstileContainer,
    })[selector] || null;
    modal.querySelectorAll = () => [close, fullName, email, consent, submit];
    document.body = element('body', document);
    document.querySelectorAll = () => [modal];
    document.addEventListener = () => {};

    const fetchCalls = [];
    const window = {
        setTimeout: callback => callback(),
        fetch: (...args) => { fetchCalls.push(args); return fetchImpl(...args); },
    };
    if (options.turnstile) window.turnstile = options.turnstile;
    runInNewContext(source, { window, document, JSON, Array, Promise });
    return { document, opener, close, fullName, email, consent, website, submit, status, form, modal, turnstileContainer, fetchCalls };
}

function event(extra = {}) {
    return { preventDefault() { this.prevented = true; }, ...extra };
}

async function settle() {
    await new Promise(resolve => setImmediate(resolve));
    await new Promise(resolve => setImmediate(resolve));
}

test('modal opens, traps focus, closes with Escape and restores focus', () => {
    const state = fixture(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ ok: true }) }));
    state.document.activeElement = state.opener;
    state.opener.handlers.click();
    assert.equal(state.modal.hidden, false);
    assert.equal(state.document.activeElement, state.fullName);
    assert.equal(state.document.body.classList.contains('slc-waitlist-open'), true);

    state.document.activeElement = state.submit;
    const tab = event({ key: 'Tab', shiftKey: false });
    state.modal.handlers.keydown(tab);
    assert.equal(tab.prevented, true);
    assert.equal(state.document.activeElement, state.close);

    const escape = event({ key: 'Escape' });
    state.modal.handlers.keydown(escape);
    assert.equal(state.modal.hidden, true);
    assert.equal(state.document.activeElement, state.opener);
    assert.equal(state.document.body.classList.contains('slc-waitlist-open'), false);
});

test('submission sends only public form data to the WordPress proxy', async () => {
    const state = fixture(() => Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ ok: true, message: 'Registrado' }),
    }));
    state.form.handlers.submit(event());
    await settle();

    assert.equal(state.fetchCalls.length, 1);
    const [url, options] = state.fetchCalls[0];
    assert.equal(url, 'https://shop.test/wp-json/studiahub/v1/course-waitlist/42');
    assert.equal(options.method, 'POST');
    assert.deepEqual(JSON.parse(options.body), {
        fullName: 'Ada Lovelace', email: 'ada@example.com', consent: true,
        consentVersion: 'v1', website: '',
    });
    assert.equal(/authorization|bearer/i.test(JSON.stringify(options)), false);
    assert.equal(state.form.resetCalled, true);
    assert.equal(state.status.textContent, 'Registrado');
    assert.equal(state.status.classList.contains('slc-cpitch__waitlist-status--success'), true);
    assert.equal(state.submit.disabled, false);

    const tabFromStatus = event({ key: 'Tab', shiftKey: false });
    state.modal.handlers.keydown(tabFromStatus);
    assert.equal(tabFromStatus.prevented, true);
    assert.equal(state.document.activeElement, state.close);
});

test('Turnstile renders explicitly on open and sends a single-use token', async () => {
    const renderCalls = [];
    const resetCalls = [];
    const turnstile = {
        render(container, config) {
            renderCalls.push({ container, config });
            return 'widget-1';
        },
        reset(widgetId) { resetCalls.push(widgetId); },
    };
    const state = fixture(() => Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ ok: true, message: 'Registrado' }),
    }), { siteKey: 'public-site-key', turnstile });

    assert.equal(renderCalls.length, 0);
    state.opener.handlers.click();
    assert.equal(renderCalls.length, 1);
    assert.equal(renderCalls[0].container, state.turnstileContainer);
    assert.equal(renderCalls[0].config.sitekey, 'public-site-key');
    assert.equal(renderCalls[0].config.action, 'waitlist');
    assert.equal(renderCalls[0].config.appearance, 'interaction-only');
    assert.equal(renderCalls[0].config['response-field'], false);

    state.form.handlers.submit(event());
    assert.equal(state.fetchCalls.length, 0);
    assert.match(state.status.textContent, /Completá la verificación/);

    renderCalls[0].config.callback('opaque-single-use-token');
    state.form.handlers.submit(event());
    await settle();

    assert.equal(state.fetchCalls.length, 1);
    const payload = JSON.parse(state.fetchCalls[0][1].body);
    assert.equal(payload.turnstileToken, 'opaque-single-use-token');
    assert.deepEqual(resetCalls, ['widget-1']);
    assert.equal(state.turnstileContainer.value, '');
});

test('expired Turnstile token is cleared and widget is reset', () => {
    let config;
    const resetCalls = [];
    const state = fixture(() => Promise.reject(new Error('fetch must not run')), {
        siteKey: 'public-site-key',
        turnstile: {
            render(container, value) { config = value; return 'widget-2'; },
            reset(widgetId) { resetCalls.push(widgetId); },
        },
    });
    state.opener.handlers.click();
    config.callback('expired-token');
    config['expired-callback']();
    state.form.handlers.submit(event());

    assert.deepEqual(resetCalls, ['widget-2']);
    assert.equal(state.fetchCalls.length, 0);
    assert.match(state.status.textContent, /Completá la verificación/);
});

test('HTTP and transport failures stay errors and never render success', async () => {
    const http = fixture(() => Promise.resolve({
        ok: false,
        status: 409,
        json: () => Promise.resolve({ ok: false, message: 'Actualizá la página.' }),
    }));
    http.form.handlers.submit(event());
    await settle();
    assert.equal(http.form.resetCalled, undefined);
    assert.equal(http.status.textContent, 'Actualizá la página.');
    assert.equal(http.status.classList.contains('slc-cpitch__waitlist-status--error'), true);

    const network = fixture(() => Promise.reject(new Error('offline')));
    network.form.handlers.submit(event());
    await settle();
    assert.equal(network.form.resetCalled, undefined);
    assert.match(network.status.textContent, /Revisá tu conexión/);
    assert.equal(network.status.classList.contains('slc-cpitch__waitlist-status--success'), false);
});

test('closing during submit keeps focus on opener when fetch resolves', async () => {
    let resolveFetch;
    const state = fixture(() => new Promise(resolve => { resolveFetch = resolve; }));
    state.opener.handlers.click();
    state.form.handlers.submit(event());
    assert.equal(state.submit.disabled, true);

    state.close.handlers.click();
    assert.equal(state.modal.hidden, true);
    assert.equal(state.document.activeElement, state.opener);
    assert.equal(state.submit.disabled, false);

    resolveFetch({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ ok: true, message: 'Registrado tarde' }),
    });
    await settle();

    assert.equal(state.document.activeElement, state.opener);
    assert.equal(state.status.focused, undefined);
    assert.equal(state.status.textContent, '');
    assert.equal(state.form.resetCalled, undefined);
});

test('client implements all required public error states without secrets', () => {
    for (const status of [400, 401, 409, 413, 429]) {
        assert.match(source, new RegExp(`${status}:`));
    }
    assert.match(source, /response\.ok/);
    assert.match(source, /result\.data\.ok !== true/);
    assert.doesNotMatch(source, /Authorization|Bearer/);
    assert.doesNotMatch(source, /console\./);
});
