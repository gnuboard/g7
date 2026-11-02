'use strict';
/**
 * 웹 설치 마법사 Step 5 — 「…에 필요」 배지(D9)·사전 검증 실패 화면 버튼(D7)·실패 제목(작업 키) 단위 시험 (#153).
 *
 * 실행: node --test tests/installer/installer-step5-required-by.test.cjs
 *
 * installer.js 는 브라우저 전역 IIFE 라 모듈로 불러올 수 없다. installer-integrations-autoselect.test.cjs 와 같이
 * 필요한 함수 선언만 소스에서 잘라 내 가짜 DOM 과 함께 vm 컨텍스트에서 돌린다.
 *
 * 잠그는 것:
 * - INSTALLER_PULLED_BY 에 있는 `카테고리:식별자` 의 *_install 행에만 배지 1개, 문구는 task_required_by(:names)
 * - pulled_by 가 비면 배지 0개(의존이 없는 기존 구성은 화면이 그대로)
 * - message_key=dependency_precheck_failed 면 「Step 4 로 돌아가기」 표시·재시도는 btn-secondary, 다른 실패면 숨김·재시도 btn-primary
 * - state 의 failed_task(`module_install:sirsoft-page`)가 실패 제목에 번역 키 그대로 새지 않는다
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '../..');
const SOURCE = fs.readFileSync(path.join(ROOT, 'public/install/assets/js/installer.js'), 'utf8');

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
    // 기본값 인자(`= null`)가 있어도 본문 `{` 는 시그니처의 `)` 뒤 첫 `{`
    const open = SOURCE.indexOf('{', SOURCE.indexOf(') {', begin));
    return SOURCE.slice(begin, matchBrace(SOURCE, open) + 1);
}

const FUNCTIONS = [
    'lang', 'escapeHtml', 'getTranslationKey', 'getTaskName', 'getStoredExtensionName', 'getTaskDisplayName',
    'getTaskDomId', 'getGroupLabel', 'getTaskRequiredByLabel', 'splitTaskKey', 'renderTaskList', 'showError',
];

/** classList 흉내 */
function classList(initial) {
    const set = new Set(initial);
    return {
        add: (...c) => c.forEach((x) => set.add(x)),
        remove: (...c) => c.forEach((x) => set.delete(x)),
        toggle: (c, force) => { const on = force === undefined ? !set.has(c) : !!force; if (on) set.add(c); else set.delete(c); return on; },
        contains: (c) => set.has(c),
        toString: () => Array.from(set).join(' '),
    };
}

/** 가짜 요소 */
function el(classes = []) {
    return { innerHTML: '', textContent: '', style: {}, classList: classList(classes), dataset: {}, setAttribute() {}, querySelector: () => null };
}

/**
 * 잘라 낸 함수를 가짜 DOM 과 함께 실행하는 샌드박스를 만든다.
 *
 * @param {Object} pulledBy window.INSTALLER_PULLED_BY
 */
function sandbox(pulledBy) {
    const title = el();
    const elements = {
        'task-list': el(),
        'failure-section': Object.assign(el(['result-section', 'hidden']), { querySelector: (sel) => (sel === '.result-title' ? title : null) }),
        'failure-message': el(),
        'back-to-extensions-btn': el(['btn', 'btn-primary', 'hidden']),
        'retry-installation-btn': el(['btn', 'btn-primary']),
        'installer-title': el(),
        'install-warning': el(),
        'install-toggle-icon': el(),
        'installation-card-header': el(),
        'installation-card-body': el(),
    };
    const ctx = {
        window: {
            INSTALLER_LANG: {
                task_required_by: ':names에 필요',
                task_plugin_install: '플러그인 설치',
                task_plugin_activate: '플러그인 활성화',
                task_module_install: '모듈 설치',
                task_module_activate: '모듈 활성화',
                task_group_plugins: '플러그인',
                task_group_modules: '모듈',
                installation_failed: '설치 실패',
                view_error_details: '오류 상세 보기',
            },
            INSTALLER_EXTENSION_NAMES: { 'sirsoft-page': { ko: '페이지', en: 'Page' }, 'sirsoft-ckeditor5': { ko: 'CKEditor 5', en: 'CKEditor 5' } },
            INSTALLER_PULLED_BY: pulledBy,
            INSTALLER_TASK_GROUPS: [
                { id: 'modules', labelKey: 'task_group_modules', tasks: [[{ id: 'module_install', target: 'sirsoft-page' }, { id: 'module_activate', target: 'sirsoft-page' }]] },
                { id: 'plugins', labelKey: 'task_group_plugins', tasks: [[{ id: 'plugin_install', target: 'sirsoft-ckeditor5' }, { id: 'plugin_activate', target: 'sirsoft-ckeditor5' }]] },
            ],
            installerLocale: { get: () => 'ko' },
        },
        document: {
            getElementById: (id) => elements[id] || null,
            querySelectorAll: () => [],
            createElement: () => ({ set textContent(v) { this._t = String(v); }, get innerHTML() { return this._t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); } }),
        },
        setTimeout: () => 0,
        // showError 가 부르는 화면 전환 함수는 이 시험의 관심 밖이다
        hideInstallationStartSection() {}, showInstallationProgressSection() {}, hideAbortButton() {}, setTaskStatus() {}, scrollResultIntoView() {},
        installationCompleted: false,
        lastRollbackFailure: null,
        INSTALLATION_TASKS: [],
    };
    vm.createContext(ctx);
    vm.runInContext('var installationCompleted = false; var lastRollbackFailure = null;\n' + FUNCTIONS.map(extractFunction).join('\n'), ctx);
    return { ctx, elements, title };
}

/** 렌더된 배지 목록 — [행 data-task-id, data-target, 배지 문구] */
function badges(html) {
    const rows = html.split('class="task-item ').slice(1);
    return rows
        .map((row) => {
            const id = /data-task-id="([^"]*)"/.exec(row)[1];
            const target = /data-target="([^"]*)"/.exec(row)[1];
            const badge = /<span class="task-required-by">([^<]*)<\/span>/.exec(row);
            return badge ? [id, target, badge[1]] : null;
        })
        .filter(Boolean);
}

/**
 * @effects installer_step5_required_by_badge_on_pulled_install_row
 */
test('앞당겨진 플러그인 설치 행에만 「페이지에 필요」 배지 1개', () => {
    const { ctx, elements } = sandbox({ 'plugins:sirsoft-ckeditor5': ['modules:sirsoft-page'] });
    vm.runInContext('renderTaskList()', ctx);

    assert.deepEqual(badges(elements['task-list'].innerHTML), [['plugin_install', 'sirsoft-ckeditor5', '페이지에 필요']]);
});

/**
 * @effects installer_step5_required_by_badge_absent_without_pull
 */
test('pulled_by 가 비면 배지 0개 — 의존이 없는 구성은 화면이 그대로', () => {
    const { ctx, elements } = sandbox({});
    vm.runInContext('renderTaskList()', ctx);

    assert.equal(badges(elements['task-list'].innerHTML).length, 0);
    assert.equal((elements['task-list'].innerHTML.match(/task-required-by/g) || []).length, 0);
});

/**
 * @effects installer_step5_required_by_lists_every_owner_name
 */
test('여러 확장이 요구하면 이름을 쉼표로 잇고, 이름이 없으면 식별자를 쓴다', () => {
    const { ctx, elements } = sandbox({ 'plugins:sirsoft-ckeditor5': ['modules:sirsoft-page', 'modules:acme-learning'] });
    vm.runInContext('renderTaskList()', ctx);

    assert.deepEqual(badges(elements['task-list'].innerHTML), [['plugin_install', 'sirsoft-ckeditor5', '페이지, acme-learning에 필요']]);
});

/**
 * @effects installer_step5_precheck_failure_shows_back_to_step4
 */
test('사전 검증 실패면 「Step 4 로 돌아가기」를 드러내고 재시도를 보조 버튼으로', () => {
    const { ctx, elements } = sandbox({});
    vm.runInContext("showError('의존성 사전 검증 실패', '확장 의존 관계가 순환합니다: modules:a → plugins:b → modules:a', 'dependency_precheck', 'dependency_precheck_failed')", ctx);

    assert.equal(elements['back-to-extensions-btn'].classList.contains('hidden'), false);
    assert.equal(elements['retry-installation-btn'].classList.contains('btn-secondary'), true);
    assert.equal(elements['retry-installation-btn'].classList.contains('btn-primary'), false);
    // 사유가 곧 해결 방법이라 상세를 펼쳐 둔다
    assert.match(elements['failure-message'].innerHTML, /aria-expanded="true"/);
    assert.match(elements['failure-message'].innerHTML, /class="error-detail-content show"/);
});

/**
 * @effects installer_step5_other_failure_keeps_retry_primary
 */
test('다른 실패면 「Step 4 로 돌아가기」는 숨기고 재시도가 주 버튼', () => {
    const { ctx, elements } = sandbox({});
    vm.runInContext("showError('모듈 설치에 실패했습니다', '세부', 'module_install', 'error_module_install_failed')", ctx);

    assert.equal(elements['back-to-extensions-btn'].classList.contains('hidden'), true);
    assert.equal(elements['retry-installation-btn'].classList.contains('btn-primary'), true);
    assert.equal(elements['retry-installation-btn'].classList.contains('btn-secondary'), false);
    assert.match(elements['failure-message'].innerHTML, /aria-expanded="false"/);
});

/**
 * @effects installer_step5_failure_title_has_no_raw_task_key
 */
test('state 의 failed_task(id:target)가 실패 제목에 번역 키 그대로 새지 않는다', () => {
    const { ctx, title } = sandbox({});
    vm.runInContext("showError('모듈 설치에 실패했습니다', null, 'module_install:sirsoft-page', 'error_module_install_failed')", ctx);

    assert.equal(title.textContent, '설치 실패 - 페이지 모듈 설치 (sirsoft-page)');
    assert.doesNotMatch(title.textContent, /task_module_install/);
});
