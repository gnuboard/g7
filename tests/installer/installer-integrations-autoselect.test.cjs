'use strict';
/**
 * 웹 설치 마법사 Step 4 — 선택 연동(integrations) 자동 체크 단위 시험.
 *
 * 실행: node --test tests/installer/installer-integrations-autoselect.test.cjs
 *
 * installer.js 는 브라우저 전역 IIFE 라 모듈로 불러올 수 없다. 그래서 시험에 필요한 함수 선언만
 * 소스에서 잘라 내(함수 단위 로드) 가짜 DOM 과 함께 vm 컨텍스트에서 돌린다. 잘라 내는 것은 실제
 * installer.js 의 함수 본문이므로 소스가 바뀌면 시험도 그 소스를 그대로 검사한다.
 *
 * 잠그는 것 (계획 §3.6 · D8):
 * - 사용자가 직접 고른 확장의 연동 대상(모듈·플러그인)만 한 번 자동 체크, 템플릿 대상은 안내만
 * - 자동 체크된 확장의 연동은 따라가지 않음(1단계), 그 확장의 dependencies 는 기존 경로로 따라옴
 * - 자동 체크 항목을 해제하면 기억해 다시 체크하지 않음, 「다음」·해제 잠금에 관여하지 않음
 * - owner 해제 시 그 owner 가 자동 체크한 것 중 다른 근거 없는 것만 해제
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '../..');
const INSTALLER_JS = path.join(ROOT, 'public/install/assets/js/installer.js');
const SOURCE = fs.readFileSync(INSTALLER_JS, 'utf8');
// 설치 화면은 installer.js 앞에 확장 선택 해석기(window.G7ExtensionResolver)를 싣는다 — 의존 자동 선택이 이를 부른다.
// 루트 package.json 이 ESM 이라 require 로는 내보내기가 비므로, 브라우저처럼 샌드박스 안에서 실행해 window 에 붙인다.
const RESOLVER_SOURCE = fs.readFileSync(path.join(ROOT, 'public/install/assets/js/installer-extension-resolver.js'), 'utf8');

// ---------------------------------------------------------------------------
// 소스에서 함수 잘라 내기
// ---------------------------------------------------------------------------

/**
 * start 위치의 `{` 와 짝인 `}` 의 위치를 찾는다(문자열·템플릿 리터럴·주석 안의 괄호는 건너뜀).
 */
function matchBrace(src, start) {
    let depth = 0;
    for (let i = start; i < src.length; i++) {
        const ch = src[i];
        const next = src[i + 1];
        if (ch === '/' && next === '/') { i = src.indexOf('\n', i); if (i === -1) break; continue; }
        if (ch === '/' && next === '*') { i = src.indexOf('*/', i + 2) + 1; continue; }
        if (ch === '\'' || ch === '"' || ch === '`') {
            for (i = i + 1; i < src.length; i++) {
                if (src[i] === '\\') { i++; continue; }
                if (src[i] === ch) break;
            }
            continue;
        }
        if (ch === '{') depth++;
        if (ch === '}') { depth--; if (depth === 0) return i; }
    }
    throw new Error('짝이 맞는 } 를 찾지 못함 @' + start);
}

/** `function name(...) { ... }` 선언 전체를 잘라 낸다. */
function extractFunction(name) {
    const re = new RegExp('\\n[ \\t]*function ' + name + '\\s*\\(');
    const m = re.exec(SOURCE);
    if (!m) throw new Error('installer.js 에 function ' + name + ' 이 없음');
    const begin = m.index + 1;
    const open = SOURCE.indexOf('{', SOURCE.indexOf(')', begin));
    return SOURCE.slice(begin, matchBrace(SOURCE, open) + 1);
}

/** `window.toggleExtension = function(...) { ... };` 를 잘라 낸다. */
function extractWindowFunction(name) {
    const marker = 'window.' + name + ' = function';
    const begin = SOURCE.indexOf(marker);
    if (begin === -1) throw new Error('installer.js 에 ' + marker + ' 이 없음');
    const open = SOURCE.indexOf('{', begin);
    return SOURCE.slice(begin, matchBrace(SOURCE, open) + 1) + ';';
}

const FUNCTIONS = [
    'getLocalizedText', 'findExtensionById', 'findIntegrationTarget', 'buildIntegrationsLineHtml',
    'satisfiesVersionConstraint', 'computeMissingDependencies', 'autoSelectMissingDependencies',
    'validateExtensionSelection', 'computeDependents', 'applyAutoSelectedUI', 'removeAutoSelectedUI',
    'clearAutoSelectedByOwner', 'isIntegrationAutoSelected', 'forgetIntegrationAutoSelection',
    'autoSelectIntegrations', 'findSelectionBasis', 'clearIntegrationAutoSelectedByOwner',
    'applyIntegrationAutoUI', 'removeIntegrationAutoUI',
];

// ---------------------------------------------------------------------------
// 가짜 DOM (installer.js 가 Step 4 에서 쓰는 만큼만)
// ---------------------------------------------------------------------------

class FakeElement {
    constructor(tag) {
        this.tagName = (tag || 'div').toUpperCase();
        this.children = [];
        this.parent = null;
        this.dataset = {};
        this.innerHTML = '';
        this.textContent = '';
        this.title = '';
        this.disabled = false;
        this._classes = new Set();
        const self = this;
        this.classList = {
            add: (...c) => c.forEach((x) => self._classes.add(x)),
            remove: (...c) => c.forEach((x) => self._classes.delete(x)),
            contains: (c) => self._classes.has(c),
            toggle: (c, on) => { const v = on === undefined ? !self._classes.has(c) : on; v ? self._classes.add(c) : self._classes.delete(c); return v; },
        };
    }
    set className(v) { this._classes = new Set(String(v).split(/\s+/).filter(Boolean)); }
    get className() { return Array.from(this._classes).join(' '); }
    appendChild(child) { child.parent = this; this.children.push(child); return child; }
    remove() { if (this.parent) { this.parent.children = this.parent.children.filter((c) => c !== this); this.parent = null; } }
    _all() { return this.children.reduce((acc, c) => acc.concat([c], c._all()), []); }
    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
    querySelectorAll(sel) {
        if (!sel.startsWith('.')) return [];
        const cls = sel.slice(1);
        return this._all().filter((el) => el._classes.has(cls));
    }
}

function createDom() {
    const cards = [];
    const byId = {};
    ['submit-btn', 'dependency-warning', 'dependency-warning-list'].forEach((id) => { byId[id] = new FakeElement('div'); });
    byId['dependency-warning'].classList.add('hidden');
    const body = new FakeElement('body');

    const parseCardSelector = (sel) => {
        if (!sel.startsWith('.extension-card')) return null;
        const attrs = {};
        sel.replace(/\[data-(type|identifier)="([^"]*)"\]/g, (_, k, v) => { attrs[k] = v; return ''; });
        return attrs;
    };
    const document = {
        body,
        getElementById: (id) => byId[id] || null,
        createElement: (tag) => new FakeElement(tag),
        querySelectorAll(sel) {
            const attrs = parseCardSelector(sel);
            if (attrs) {
                return cards.filter((c) => (!attrs.type || c.dataset.type === attrs.type) &&
                    (!attrs.identifier || c.dataset.identifier === attrs.identifier));
            }
            if (sel.startsWith('.')) return body.querySelectorAll(sel);
            return [];
        },
        querySelector(sel) { return this.querySelectorAll(sel)[0] || null; },
    };
    const addCard = (type, identifier) => {
        const card = new FakeElement('div');
        card.className = 'extension-card';
        card.dataset.type = type;
        card.dataset.identifier = identifier;
        const info = new FakeElement('div'); info.className = 'extension-card-info';
        const select = new FakeElement('div'); select.className = 'extension-card-select';
        card.appendChild(info); card.appendChild(select);
        cards.push(card);
        return card;
    };
    return { document, addCard, byId, body };
}

// ---------------------------------------------------------------------------
// 픽스처 — 번들 sirsoft-basic 과 같은 모양(연동 4개) + 1단계·의존 확인용 확장
// ---------------------------------------------------------------------------

function fixture(overrides = {}) {
    const mod = (id, extra = {}) => Object.assign({ identifier: id, name: { ko: id, en: id }, version: '1.0.0', dependencies_detailed: [], integrations_detailed: [] }, extra);
    const data = {
        admin_templates: [mod('sirsoft-admin_basic')],
        user_templates: [
            mod('sirsoft-basic', {
                name: { ko: '기본 템플릿', en: 'Basic' },
                integrations_detailed: [
                    { type: 'modules', id: 'sirsoft-board', version: '>=1.0.0' },
                    { type: 'modules', id: 'sirsoft-ecommerce', version: '*' },
                    { type: 'modules', id: 'sirsoft-page', version: '*' },
                    { type: 'plugins', id: 'sirsoft-daum_postcode', version: '*' },
                    { type: 'templates', id: 'sirsoft-admin_basic', version: '*' },
                    { type: 'modules', id: 'vendor-not_bundled', version: '*' },
                ],
            }),
            mod('vendor-shop_tpl', {
                integrations_detailed: [{ type: 'plugins', id: 'sirsoft-pay_test', version: '*' }],
            }),
            mod('vendor-board_tpl', {
                integrations_detailed: [{ type: 'modules', id: 'sirsoft-board', version: '*' }],
            }),
        ],
        modules: [
            mod('sirsoft-board'),
            mod('sirsoft-ecommerce', { integrations_detailed: [{ type: 'modules', id: 'sirsoft-board', version: '*' }] }),
            mod('sirsoft-page'),
        ],
        plugins: [
            mod('sirsoft-daum_postcode'),
            mod('sirsoft-message_bizppurio', {
                name: { ko: '비즈뿌리오', en: 'Bizppurio' },
                integrations_detailed: [
                    { type: 'modules', id: 'sirsoft-board', version: '*' },
                    { type: 'modules', id: 'sirsoft-ecommerce', version: '*' },
                ],
            }),
            mod('sirsoft-pay_test', {
                dependencies_detailed: [{ type: 'modules', identifier: 'sirsoft-ecommerce', version: '*' }],
            }),
        ],
        language_packs: [],
    };
    for (const [cat, list] of Object.entries(overrides)) {
        list.forEach((patch) => {
            const item = data[cat].find((e) => e.identifier === patch.identifier);
            Object.assign(item, patch);
        });
    }
    return data;
}

/**
 * installer.js 의 Step 4 함수들을 픽스처와 함께 띄운다.
 */
function boot(data = fixture()) {
    const dom = createDom();
    ['admin_templates', 'user_templates', 'modules', 'plugins'].forEach((cat) => {
        data[cat].forEach((item) => dom.addCard(cat, item.identifier));
    });

    const toasts = [];
    const context = {
        console,
        document: dom.document,
        window: { installerLocale: { get: () => 'ko' }, EXTENSION_LABELS: {} },
        __data: data,
        __toasts: toasts,
    };
    vm.createContext(context);
    vm.runInContext(RESOLVER_SOURCE, context, { filename: 'installer-extension-resolver.js' });

    const prelude = `
        let extensionsData = __data;
        let selectedExtensions = { admin_templates: ['sirsoft-admin_basic'], user_templates: [], modules: [], plugins: [], language_packs: [] };
        const autoSelectedSet = new Set();
        const autoSelectedByIntegration = {};
        const userDeselectedIntegrations = new Set();
        const INTEGRATION_SELECTABLE_TYPES = ['modules', 'plugins'];
        function lang(key) { return key; }
        function escapeHtml(text) { return String(text); }
        function updateSelectionSummary() {}
        function applyLanguagePackCascadeState() {}
        function updateBulkToggleStates() {}
        function showDependencyLockToast(identifier, names) { __toasts.push({ identifier, names }); }
        function validateAdminTemplate() { return validateExtensionSelection(); }
    `;
    const body = FUNCTIONS.map(extractFunction).join('\n\n') + '\n\n' + extractWindowFunction('toggleExtension');
    const api = `
        globalThis.api = {
            toggle: (type, id) => window.toggleExtension(type, id, type === 'admin_templates' || type === 'user_templates'),
            selected: () => selectedExtensions,
            isSelected: (type, id) => selectedExtensions[type].indexOf(id) !== -1,
            byIntegration: () => autoSelectedByIntegration,
            userDeselected: () => userDeselectedIntegrations,
            autoDeps: () => autoSelectedSet,
            computeDependents, validateExtensionSelection, buildIntegrationsLineHtml,
        };
    `;
    vm.runInContext(prelude + body + api, context, { filename: 'installer.js(extract)' });

    const card = (type, id) => dom.document.querySelector('.extension-card[data-type="' + type + '"][data-identifier="' + id + '"]');
    const badgeOwner = (type, id) => {
        const badge = card(type, id).querySelector('.integration-auto-badge');
        if (!badge) return null;
        const m = /integration-auto-badge-owner">\(([^)]*)\)/.exec(badge.innerHTML);
        return m ? m[1] : '';
    };
    return Object.assign(context.api, { dom, card, badgeOwner, toasts, data });
}

const BASIC_TARGETS = [['modules', 'sirsoft-board'], ['modules', 'sirsoft-ecommerce'], ['modules', 'sirsoft-page'], ['plugins', 'sirsoft-daum_postcode']];

// ---------------------------------------------------------------------------
// 시험
// ---------------------------------------------------------------------------

/**
 * @scenario surface=step4_selection, owner_type=template
 *
 * @effects step4_integration_autoselect_on_user_pick, step4_integration_never_blocks_next_or_deselect
 */
test('템플릿을 고르면 연동 모듈·플러그인 4개가 자동 체크되고 「연동」 배지가 붙는다', () => {
    const t = boot();
    t.toggle('user_templates', 'sirsoft-basic');

    for (const [type, id] of BASIC_TARGETS) {
        assert.ok(t.isSelected(type, id), id + ' 선택');
        assert.ok(t.card(type, id).classList.contains('selected'));
        assert.ok(t.card(type, id).classList.contains('integration-auto-selected'), id + ' 연동 표시');
        assert.equal(t.badgeOwner(type, id), '기본 템플릿');
        assert.equal(t.card(type, id).querySelector('.dep-auto-badge'), null, '필수 의존성 배지와 구분');
    }
    assert.deepEqual([...t.byIntegration()['sirsoft-basic']].sort(), BASIC_TARGETS.map((x) => x[1]).sort());
    assert.equal(t.dom.byId['dependency-warning'].classList.contains('hidden'), true, '경고 상자 0');
    assert.equal(t.dom.byId['submit-btn'].disabled, false);
});

/**
 * @scenario surface=step4_selection, owner_type=template
 *
 * @effects step4_template_targets_are_notice_only
 */
test('템플릿 대상 연동과 번들에 없는 대상은 자동 체크하지 않는다', () => {
    const t = boot();
    t.toggle('user_templates', 'sirsoft-basic');

    assert.deepEqual([...t.selected().admin_templates], ['sirsoft-admin_basic']);
    assert.equal(t.selected().modules.indexOf('vendor-not_bundled'), -1);
    assert.equal(t.card('admin_templates', 'sirsoft-admin_basic').classList.contains('integration-auto-selected'), false);
});

/**
 * @scenario surface=step4_selection, owner_type=template
 *
 * @effects step4_integration_never_blocks_next_or_deselect
 */
test('연동 자동 체크 항목은 해제할 수 있고 「다음」을 막지 않는다', () => {
    const t = boot();
    t.toggle('user_templates', 'sirsoft-basic');
    t.toggle('modules', 'sirsoft-ecommerce');

    assert.equal(t.isSelected('modules', 'sirsoft-ecommerce'), false);
    assert.equal(t.toasts.length, 0, '해제 잠금 토스트 없음');
    assert.equal(t.card('modules', 'sirsoft-ecommerce').classList.contains('integration-auto-selected'), false);
    assert.equal(t.computeDependents('sirsoft-ecommerce').length, 0, '연동은 의존자로 치지 않음');
    assert.equal(t.dom.byId['submit-btn'].disabled, false);
    assert.equal(t.dom.byId['dependency-warning'].classList.contains('hidden'), true);
});

/**
 * @scenario surface=step4_selection, owner_type=template
 *
 * @effects step4_user_deselect_is_remembered
 */
test('해제한 연동 항목은 기억해 owner 를 다시 골라도 체크하지 않는다', () => {
    const t = boot();
    t.toggle('user_templates', 'sirsoft-basic');
    t.toggle('modules', 'sirsoft-ecommerce');
    t.toggle('user_templates', 'sirsoft-basic'); // 해제
    t.toggle('user_templates', 'sirsoft-basic'); // 다시 선택

    assert.ok(t.userDeselected().has('sirsoft-ecommerce'));
    assert.equal(t.isSelected('modules', 'sirsoft-ecommerce'), false);
    for (const id of ['sirsoft-board', 'sirsoft-page']) assert.ok(t.isSelected('modules', id), id);
    assert.ok(t.isSelected('plugins', 'sirsoft-daum_postcode'));
});

/**
 * @scenario surface=step4_selection, owner_type=plugin
 *
 * @effects step4_user_deselect_is_remembered, step4_integration_autoselect_on_user_pick
 */
test('다른 owner(플러그인)를 골라도 사용자가 해제한 항목은 체크하지 않는다', () => {
    const t = boot();
    t.toggle('user_templates', 'sirsoft-basic');
    t.toggle('modules', 'sirsoft-ecommerce');
    t.toggle('user_templates', 'sirsoft-basic');
    assert.equal(t.isSelected('modules', 'sirsoft-board'), false, 'owner 해제로 게시판도 풀림');

    t.toggle('plugins', 'sirsoft-message_bizppurio');

    assert.ok(t.isSelected('modules', 'sirsoft-board'), '게시판 자동 체크');
    assert.equal(t.badgeOwner('modules', 'sirsoft-board'), '비즈뿌리오');
    assert.equal(t.isSelected('modules', 'sirsoft-ecommerce'), false, '해제 기억 유지');
});

/**
 * @scenario surface=step4_selection, owner_type=plugin
 *
 * @effects step4_owner_deselect_releases_only_unbased
 */
test('owner 를 해제하면 다른 근거가 없는 자동 체크 항목만 풀린다', () => {
    const t = boot();
    t.toggle('user_templates', 'sirsoft-basic');
    t.toggle('plugins', 'sirsoft-message_bizppurio'); // 게시판·쇼핑몰은 이미 선택됨 → 근거만 추가
    t.toggle('user_templates', 'sirsoft-basic'); // 해제

    assert.equal(t.isSelected('modules', 'sirsoft-page'), false);
    assert.equal(t.isSelected('plugins', 'sirsoft-daum_postcode'), false);
    assert.ok(t.isSelected('modules', 'sirsoft-board'), '비즈뿌리오가 연동으로 선언 → 유지');
    assert.ok(t.isSelected('modules', 'sirsoft-ecommerce'));
    assert.equal(t.badgeOwner('modules', 'sirsoft-board'), '비즈뿌리오', '배지 owner 를 근거로 옮김');

    t.toggle('plugins', 'sirsoft-message_bizppurio'); // 해제
    assert.equal(t.isSelected('modules', 'sirsoft-board'), false);
    assert.equal(t.isSelected('modules', 'sirsoft-ecommerce'), false);
    assert.equal(t.card('modules', 'sirsoft-board').classList.contains('integration-auto-selected'), false);
});

/**
 * @scenario surface=step4_selection, owner_type=module
 *
 * @effects step4_owner_deselect_releases_only_unbased
 */
test('의존 근거로 남은 항목이 붙잡던 연동 항목은 그 항목을 해제할 때 함께 풀린다', () => {
    const t = boot();
    t.toggle('user_templates', 'sirsoft-basic');
    t.toggle('plugins', 'sirsoft-pay_test'); // 쇼핑몰을 의존
    t.toggle('user_templates', 'sirsoft-basic'); // 해제

    assert.ok(t.isSelected('modules', 'sirsoft-ecommerce'), '결제 플러그인이 의존 → 유지');
    assert.equal(t.card('modules', 'sirsoft-ecommerce').classList.contains('integration-auto-selected'), false, '연동 표시는 뗌');
    assert.ok(t.isSelected('modules', 'sirsoft-board'), '남은 쇼핑몰이 연동으로 선언 → 유지');
    assert.equal(t.badgeOwner('modules', 'sirsoft-board'), 'sirsoft-ecommerce');
    assert.equal(t.isSelected('modules', 'sirsoft-page'), false);

    t.toggle('plugins', 'sirsoft-pay_test');
    t.toggle('modules', 'sirsoft-ecommerce');
    assert.equal(t.isSelected('modules', 'sirsoft-board'), false, '근거였던 쇼핑몰 해제로 게시판도 풀림');
});

/**
 * @scenario surface=step4_selection, owner_type=template
 *
 * @effects step4_owner_deselect_releases_only_unbased
 */
test('함께 풀리는 후보끼리 서로 연동을 선언해도 서로를 붙잡지 않는다', () => {
    const t = boot(fixture({
        modules: [{ identifier: 'sirsoft-board', integrations_detailed: [{ type: 'modules', id: 'sirsoft-ecommerce', version: '*' }] }],
    }));
    t.toggle('user_templates', 'sirsoft-basic');
    t.toggle('user_templates', 'sirsoft-basic');

    for (const [type, id] of BASIC_TARGETS) assert.equal(t.isSelected(type, id), false, id);
});

/**
 * @scenario surface=step4_selection, owner_type=template
 *
 * @effects step4_owner_deselect_releases_only_unbased
 */
test('사용자가 먼저 직접 고른 항목은 owner 를 해제해도 남는다', () => {
    const t = boot();
    t.toggle('modules', 'sirsoft-page');
    t.toggle('user_templates', 'sirsoft-basic');

    assert.equal(t.card('modules', 'sirsoft-page').classList.contains('integration-auto-selected'), false);
    t.toggle('user_templates', 'sirsoft-basic');
    assert.ok(t.isSelected('modules', 'sirsoft-page'));
    assert.equal(t.isSelected('modules', 'sirsoft-board'), false);
});

/**
 * @scenario surface=step4_selection, owner_type=template
 *
 * @effects step4_one_level_only
 */
test('1단계만 — 자동 체크된 확장의 연동은 따라가지 않고 의존성은 따라온다', () => {
    const t = boot();
    t.toggle('user_templates', 'vendor-shop_tpl');

    assert.ok(t.isSelected('plugins', 'sirsoft-pay_test'), '연동 자동 체크');
    assert.ok(t.isSelected('modules', 'sirsoft-ecommerce'), '그 대상의 dependencies 는 기존 자동 선택');
    assert.ok(t.autoDeps().has('sirsoft-ecommerce'));
    assert.equal(t.isSelected('modules', 'sirsoft-board'), false, '쇼핑몰의 연동(게시판)은 따라가지 않음');
});

/**
 * @scenario surface=step4_selection, owner_type=module
 *
 * @effects step4_integration_autoselect_on_user_pick
 */
test('직접 고른 모듈의 연동은 자동 체크한다', () => {
    const t = boot();
    t.toggle('modules', 'sirsoft-ecommerce');

    assert.ok(t.isSelected('modules', 'sirsoft-board'));
    assert.equal(t.badgeOwner('modules', 'sirsoft-board'), 'sirsoft-ecommerce');
});

/**
 * @scenario surface=step4_selection, owner_type=template
 *
 * @effects step4_template_switch_moves_integrations
 */
test('템플릿을 바꾸면 이전 템플릿의 연동 자동 체크를 정리하고 새 템플릿 것을 체크한다', () => {
    const t = boot();
    t.toggle('user_templates', 'sirsoft-basic');
    t.toggle('user_templates', 'vendor-board_tpl');

    assert.deepEqual([...t.selected().user_templates], ['vendor-board_tpl']);
    assert.ok(t.isSelected('modules', 'sirsoft-board'));
    assert.equal(t.badgeOwner('modules', 'sirsoft-board'), 'vendor-board_tpl');
    assert.equal(t.isSelected('modules', 'sirsoft-ecommerce'), false);
    assert.equal(t.isSelected('modules', 'sirsoft-page'), false);
    assert.equal(t.isSelected('plugins', 'sirsoft-daum_postcode'), false);
    assert.equal(t.byIntegration()['sirsoft-basic'], undefined);
});

/**
 * @scenario surface=step4_selection, owner_type=plugin
 *
 * @effects step4_dependency_lock_unchanged
 */
test('필수 의존성 잠금은 그대로 — 의존 대상은 해제가 막힌다', () => {
    const t = boot();
    t.toggle('plugins', 'sirsoft-pay_test');
    t.toggle('modules', 'sirsoft-ecommerce');

    assert.ok(t.isSelected('modules', 'sirsoft-ecommerce'));
    assert.equal(t.toasts.length, 1);
});

/**
 * @scenario surface=step4_selection, owner_type=template
 *
 * @effects step4_integration_line_shows_version_mismatch, step4_template_targets_are_notice_only
 */
test('owner 카드 「연동」 줄 — 번들 버전 미달·템플릿 안내 표시', () => {
    const t = boot(fixture({
        modules: [{ identifier: 'sirsoft-board', version: '0.9.0' }],
    }));
    const html = t.buildIntegrationsLineHtml(t.data.user_templates.find((e) => e.identifier === 'sirsoft-basic'));

    assert.match(html, /class="extension-card-integrations"/);
    assert.match(html, /class="integration-label">integrations:/);
    assert.match(html, /class="integration-tag integration-version-mismatch" data-integration-type="modules" data-integration-id="sirsoft-board"/);
    assert.match(html, /integration_version_mismatch \(dep_version_required: >=1\.0\.0, dep_version_available: 0\.9\.0\)/);
    assert.match(html, /class="integration-tag integration-tag-template" data-integration-type="templates" data-integration-id="sirsoft-admin_basic"/);
    assert.match(html, /integration_template_hint/);
    assert.doesNotMatch(html, /data-integration-id="sirsoft-ecommerce"[^>]*integration-version-mismatch/);
    assert.equal(t.buildIntegrationsLineHtml({ integrations_detailed: [] }), '');
});

/**
 * @scenario surface=step4_selection, owner_type=module
 *
 * @effects step4_integration_never_blocks_next_or_deselect
 */
test('차단 경로(검증·잠금·사전 검증)에는 연동이 들어가지 않는다', () => {
    for (const name of ['computeDependents', 'computeMissingDependencies', 'validateExtensionSelection']) {
        assert.doesNotMatch(extractFunction(name), /integration/i, name);
    }
    const runner = fs.readFileSync(path.join(ROOT, 'public/install/includes/task-runner.php'), 'utf8');
    const start = runner.indexOf('function dependencyPrecheckSSE');
    const end = runner.indexOf("markTaskCompleted('dependency_precheck')", start);
    assert.ok(start !== -1 && end !== -1);
    assert.doesNotMatch(runner.slice(start, end), /integrations/);
});

/**
 * @scenario surface=step4_selection, owner_type=module
 *
 * @effects step4_integration_autoselect_on_user_pick, step4_one_level_only
 */
test('installer.js 가 연동 상태를 선언하고 toggleExtension 선택 분기에서만 자동 체크를 부른다', () => {
    assert.match(SOURCE, /const autoSelectedByIntegration = \{\};/);
    assert.match(SOURCE, /const userDeselectedIntegrations = new Set\(\);/);
    assert.match(SOURCE, /const INTEGRATION_SELECTABLE_TYPES = \['modules', 'plugins'\];/);
    const toggle = extractWindowFunction('toggleExtension');
    assert.match(toggle, /if \(becameSelected && !_isAutoSelect\) \{\s*autoSelectIntegrations\(type, identifier\);/);
    assert.equal((SOURCE.match(/autoSelectIntegrations\(/g) || []).length, 2, '정의 1 + 호출 1');
});

/**
 * @scenario surface=step4_selection, owner_type=template
 *
 * @effects step4_integration_line_shows_version_mismatch
 */
test('설치기 언어 파일(ko·en)과 Step 4 라벨에 연동 키가 있다', () => {
    const keys = ['integrations', 'integration_auto_badge_label', 'integration_version_mismatch', 'integration_template_hint', 'integration_selection_notice'];
    for (const locale of ['ko', 'en']) {
        const src = fs.readFileSync(path.join(ROOT, 'public/install/lang/' + locale + '.php'), 'utf8');
        for (const key of keys) assert.match(src, new RegExp("'" + key + "' => '"), locale + ':' + key);
    }
    const view = fs.readFileSync(path.join(ROOT, 'public/install/views/4-extension-selection.php'), 'utf8');
    // 라벨은 비실행 JSON 데이터 블록으로 넘긴다(#158 strict CSP — 인라인 window.EXTENSION_LABELS 대입이 아니다)
    for (const key of ['integrations', 'integration_auto_badge_label', 'integration_version_mismatch', 'integration_template_hint']) {
        assert.match(view, new RegExp("'" + key + "' => lang\\('" + key + "'\\)"), key);
    }
    assert.match(view, /lang\('integration_selection_notice'\)/);
});
