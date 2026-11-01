'use strict';
/**
 * 설치 마법사 data-action 위임 (#158 strict CSP) — installer-boot.js
 *
 * 실행: node --test tests/installer/installer-delegation.test.cjs
 *
 * on* 속성 대신 `data-action="이름"` + `data-arg` + `data-event` 로 묶고, document 위임 리스너가 **명시적 액션 맵**
 * 으로만 부른다(맵에 없는 이름은 무시 — 임의 전역 함수 호출 통로를 만들지 않는다). 서버 값은 `#installer-data`
 * JSON 을 종전과 같은 이름의 전역(INSTALLER_*)으로 옮긴다. 가짜 DOM 과 함께 vm 컨텍스트에서 실제 파일을 돌린다.
 *
 * @scenario surface=installer, csp=none, failure=none
 * @effects installer_delegation_calls_only_mapped_actions, installer_boot_restores_globals
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '../..');
const BOOT = fs.readFileSync(path.join(ROOT, 'public/install/assets/js/installer-boot.js'), 'utf8');

/**
 * 가짜 요소 — closest·getAttribute·tagName 만.
 */
function el(tag, attrs = {}, parent = null) {
    return {
        tagName: tag.toUpperCase(),
        parent,
        style: {},
        getAttribute: name => (Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null),
        closest(selector) {
            const attr = selector.replace(/^\[|\]$/g, '');
            for (let node = this; node; node = node.parent) {
                if (node.getAttribute(attr) !== null) return node;
            }
            return null;
        },
    };
}

/**
 * installer-boot.js 를 실행하고 위임 리스너·전역을 돌려준다.
 *
 * @param {object} data `#installer-data` 내용
 * @returns {{win: object, fire: Function, calls: Array}} 창 대역·이벤트 발사기·호출 기록
 */
function boot(data) {
    const listeners = {};
    const calls = [];
    const elements = {
        'installer-data': { textContent: JSON.stringify(data) },
        banner: { style: {} },
    };
    const document = {
        documentElement: { setAttribute() {} },
        getElementById: id => elements[id] || null,
        addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
    };
    const win = {
        document,
        localStorage: { getItem: () => null },
        matchMedia: () => ({ matches: false }),
    };
    for (const name of ['goToStep', 'testDatabaseConnection', 'copyPermissionCommand', 'toggleErrorDetail', 'submitRecoveryToken', 'selectPhpBinary', 'evilGlobal']) {
        win[name] = (...args) => { calls.push([name, ...args]); return undefined; };
    }
    win.window = win;
    vm.runInNewContext(BOOT, { window: win, document, Object, JSON, String, parseInt });

    const fire = (type, target) => {
        let prevented = false;
        const event = { type, target, preventDefault: () => { prevented = true; } };
        for (const fn of listeners[type] || []) fn(event);
        return { prevented, event };
    };

    return { win, fire, calls, elements };
}

test('#installer-data 를 종전 이름의 전역으로 옮긴다', () => {
    const { win } = boot({ base_url: '/install', current_step: 3, csrf: 'tok', ownership_locked: false, lang: { next: '다음' }, state_locale: 'ko', blocked_db_accounts: ['root'] });

    assert.equal(win.INSTALLER_BASE_URL, '/install');
    assert.equal(win.CURRENT_STEP, 3);
    assert.equal(win.INSTALLER_CSRF, 'tok');
    assert.equal(win.INSTALLER_OWNERSHIP_LOCKED, false);
    assert.equal(win.INSTALLER_STATE_LOCALE, 'ko');
    assert.deepEqual([...win.INSTALLER_BLOCKED_DB_ACCOUNTS], ['root']);
    assert.equal(win.installerLang('next'), '다음');
    assert.equal(win.installerLang('missing_key'), 'missing_key');
    assert.equal(win.installerEscapeAttr('a"b<c'), 'a&quot;b&lt;c');
});

test('매핑된 액션은 종전 on* 와 같은 인자로 부르고, 맵에 없는 이름은 무시한다', () => {
    const { fire, calls } = boot({});

    fire('click', el('button', { 'data-action': 'goToStep', 'data-arg': '2' }));
    fire('click', el('button', { 'data-action': 'testDatabaseConnection', 'data-arg': 'read' }));
    const copyBtn = el('button', { 'data-action': 'copyPermissionCommand' });
    fire('click', copyBtn);
    const toggle = el('button', { 'data-action': 'toggleErrorDetail', 'data-arg': 'err-1' });
    fire('click', toggle);
    // 버튼 안쪽 아이콘을 눌러도 가장 가까운 data-action 으로 간다
    fire('click', el('i', {}, el('button', { 'data-action': 'goToStep', 'data-arg': '0' })));
    // 맵에 없는 전역 함수 이름 — 호출되지 않는다
    fire('click', el('button', { 'data-action': 'evilGlobal' }));

    assert.deepEqual(calls.map(c => c[0]), ['goToStep', 'testDatabaseConnection', 'copyPermissionCommand', 'toggleErrorDetail', 'goToStep']);
    assert.equal(calls[0][1], 2);
    assert.equal(calls[1][1], 'read');
    assert.equal(calls[2][1], copyBtn);
    assert.equal(calls[3][1], toggle);
    assert.equal(calls[3][2], 'err-1');
});

test('data-event 가 맞을 때만 부르고, submit 은 이벤트를 넘기며, href="#" 링크는 이동을 막는다', () => {
    const { fire, calls, elements } = boot({});

    const form = el('form', { 'data-action': 'submitRecoveryToken', 'data-event': 'submit' });
    fire('click', form); // click 으로는 부르지 않는다
    const { event } = fire('submit', form);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], 'submitRecoveryToken');
    assert.equal(calls[0][1], event);

    const link = el('a', { 'data-action': 'selectPhpBinary', 'data-arg': '/usr/bin/php8.3', href: '#' });
    const result = fire('click', link);
    assert.equal(result.prevented, true);
    assert.deepEqual(calls[1], ['selectPhpBinary', '/usr/bin/php8.3']);

    fire('click', el('button', { 'data-action': 'hideElement', 'data-arg': 'banner' }));
    assert.equal(elements.banner.style.display, 'none');
});

test('뷰·installer.js 의 data-action 이름은 전부 액션 맵에 있다 (누락 = 무반응 버튼)', () => {
    const { win } = boot({});
    const mapped = new Set(win.__installerActionNames);
    const files = [
        ...fs.readdirSync(path.join(ROOT, 'public/install/views')).filter(f => f.endsWith('.php')).map(f => path.join(ROOT, 'public/install/views', f)),
        path.join(ROOT, 'public/install/views/partials/recovery-token-notice.php'),
        ...fs.readdirSync(path.join(ROOT, 'public/install/assets/js')).filter(f => f.endsWith('.js')).map(f => path.join(ROOT, 'public/install/assets/js', f)),
    ];
    const missing = [];
    for (const file of files) {
        const text = fs.readFileSync(file, 'utf8');
        for (const m of text.matchAll(/data-action="([A-Za-z]+)"/g)) {
            if (!mapped.has(m[1])) missing.push(`${path.basename(file)}: ${m[1]}`);
        }
    }
    assert.deepEqual(missing, []);
});
