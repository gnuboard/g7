// e2e:allow 설치 마법사는 미설치 사이트에서만 열려 설치된 사이트를 대상으로 하는 Playwright 스위트 밖이다 — Step 4 첫 진입·되돌아오기 화면은 빈 사본 브라우저 실측으로 확인한다
/**
 * 설치 마법사 Step 4 — 첫 진입 기본 선택과 「되돌아오기」 선택 복원이 서로를 덮지 않는지 잠근다.
 *
 * 회귀: Step 4 화면은 첫 방문에도 상태 파일 기본값(빈 배열 5개)을 `window.INSTALLER_SELECTED_EXTENSIONS` 로 넘긴다.
 * 복원이 그 빈 값을 「저장된 선택」으로 받아 첫 관리자 템플릿 기본 선택을 빈 배열로 덮어,
 * 카드는 선택됨으로 보이는데 「다음」이 꺼지고 요약의 관리자 템플릿 수가 0 이 되었다.
 *
 * installer.js 는 브라우저 전역 스크립트라 설치 화면과 같은 방식(전역 window 에 등록)으로 평가하고,
 * 노출된 `window.loadExtensions()` 를 그대로 불러 실제 카드 렌더 → 복원 → 검증 순서를 잰다.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import fs from 'fs';
import path from 'path';

const root = path.resolve(__dirname, '../../../..');
const RESOLVER_SOURCE = fs.readFileSync(path.join(root, 'public/install/assets/js/installer-extension-resolver.js'), 'utf8');
const INSTALLER_SOURCE = fs.readFileSync(path.join(root, 'public/install/assets/js/installer.js'), 'utf8');

const ADMIN_REQUIRED = '관리자 템플릿은 최소 1개 이상 선택해야 합니다.';

/** 확장 스캔 응답 한 항목 */
function ext(identifier: string, extra: Record<string, unknown> = {}) {
    return { identifier, name: { ko: identifier, en: identifier }, version: '1.0.0', dependencies_detailed: [], integrations_detailed: [], ...extra };
}

/** scan-extensions.php?action=get 의 data — 관리자 템플릿 2개(두 번째를 고른 복원 확인용) */
function scanData() {
    return {
        admin_templates: [ext('sirsoft-admin_basic'), ext('vendor-admin_alt')],
        user_templates: [ext('sirsoft-basic')],
        modules: [ext('sirsoft-board'), ext('sirsoft-page')],
        plugins: [ext('sirsoft-ckeditor5')],
        language_packs: [
            ext('g7-core-ja', { scope: 'core', target_identifier: '', locale: 'ja', name: 'Japanese' }),
            ext('g7-module-sirsoft-board-ja', { scope: 'module', target_identifier: 'sirsoft-board', locale: 'ja', name: 'Japanese (board)' }),
        ],
    };
}

/** Step 4 뷰(4-extension-selection.php)가 그리는 요소 중 확장 선택 스크립트가 쓰는 것 */
function mountStep4Markup() {
    document.body.innerHTML = `
        <div id="extensions-loading"></div>
        <div id="extensions-error" class="hidden"><p id="extensions-error-message"></p></div>
        <form id="extension-form" class="hidden">
            <input type="checkbox" id="bulk-toggle-all">
            <div id="admin-templates-list"></div>
            <div id="user-templates-list"></div><div id="user-templates-empty" class="hidden"></div>
            <div id="modules-list"></div><div id="modules-empty" class="hidden"></div>
            <div id="plugins-list"></div><div id="plugins-empty" class="hidden"></div>
            <div id="language-packs-locale-groups"></div><div id="language-packs-empty" class="hidden"></div>
            <span id="summary-admin-templates">0</span>
            <span id="summary-user-templates">0</span>
            <span id="summary-modules">0</span>
            <span id="summary-plugins">0</span>
            <span id="summary-language-packs">0</span>
            <div id="dependency-warning" class="hidden"><ul id="dependency-warning-list"></ul></div>
            <button type="submit" id="submit-btn" disabled>다음</button>
        </form>`;
}

/**
 * Step 4 를 띄우고 확장 목록을 불러온다.
 *
 * @param saved Step 4 화면이 넘기는 window.INSTALLER_SELECTED_EXTENSIONS (undefined 면 넘기지 않음)
 */
async function bootStep4(saved: unknown) {
    mountStep4Markup();
    const w = window as any;
    w.INSTALLER_BASE_URL = '/install';
    w.EXTENSION_LABELS = { admin_template_required: ADMIN_REQUIRED };
    if (saved === undefined) {
        delete w.INSTALLER_SELECTED_EXTENSIONS;
    } else {
        w.INSTALLER_SELECTED_EXTENSIONS = saved;
    }
    w.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ success: true, data: scanData() }) }));

    new Function(RESOLVER_SOURCE)();
    new Function(INSTALLER_SOURCE)();
    await w.loadExtensions();
}

const card = (type: string, id: string) =>
    document.querySelector(`.extension-card[data-type="${type}"][data-identifier="${id}"]`) as HTMLElement;
const isShownSelected = (type: string, id: string) => {
    const c = card(type, id);
    const icon = c.querySelector('.extension-card-select i') as HTMLElement;
    const checked = icon.classList.contains('fa-check-circle') || icon.classList.contains('fa-check-square');
    expect(c.classList.contains('selected'), `${id} 카드 표시와 선택 아이콘이 일치`).toBe(checked);
    return checked;
};
const summary = () => ['admin-templates', 'user-templates', 'modules', 'plugins', 'language-packs']
    .map((k) => (document.getElementById('summary-' + k) as HTMLElement).textContent);
const submit = () => document.getElementById('submit-btn') as HTMLButtonElement;

const EMPTY_SAVED = { admin_templates: [], user_templates: [], modules: [], plugins: [], language_packs: [] };

describe('설치 마법사 Step 4 — 첫 진입 기본 선택과 선택 복원', () => {
    beforeEach(() => {
        try { localStorage.clear(); } catch { /* 저장소 없음 */ }
    });
    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    /**
     * @effects installer_step4_first_visit_keeps_default_admin_template
     */
    it.each([
        ['상태 파일 기본값(빈 배열 5개)', EMPTY_SAVED],
        ['빈 객체', {}],
        ['넘기지 않음', undefined],
    ])('첫 진입(%s)이면 첫 관리자 템플릿이 선택되고 「다음」이 켜진다', async (_label, saved) => {
        await bootStep4(saved === EMPTY_SAVED ? JSON.parse(JSON.stringify(EMPTY_SAVED)) : saved);

        expect(isShownSelected('admin_templates', 'sirsoft-admin_basic')).toBe(true);
        expect(isShownSelected('admin_templates', 'vendor-admin_alt')).toBe(false);
        expect(summary()).toEqual(['1', '0', '0', '0', '0']);
        expect(submit().disabled).toBe(false);
        expect(submit().title).toBe('');
    });

    /**
     * @effects installer_step4_restores_selection_after_back
     */
    it('저장된 선택이 있으면(「Step 4 로 돌아가기」) 그대로 다시 체크하고 카드 표시도 같다', async () => {
        await bootStep4({
            admin_templates: ['vendor-admin_alt'],
            user_templates: ['sirsoft-basic'],
            modules: ['sirsoft-board'],
            plugins: ['sirsoft-ckeditor5'],
            language_packs: ['g7-module-sirsoft-board-ja'],
        });

        expect(isShownSelected('admin_templates', 'vendor-admin_alt')).toBe(true);
        expect(isShownSelected('admin_templates', 'sirsoft-admin_basic'), '기본 선택 카드가 함께 선택돼 보이면 안 된다').toBe(false);
        expect(isShownSelected('user_templates', 'sirsoft-basic')).toBe(true);
        expect(isShownSelected('modules', 'sirsoft-board')).toBe(true);
        expect(isShownSelected('modules', 'sirsoft-page')).toBe(false);
        expect(isShownSelected('plugins', 'sirsoft-ckeditor5')).toBe(true);
        expect(isShownSelected('language_packs', 'g7-module-sirsoft-board-ja')).toBe(true);
        expect(isShownSelected('language_packs', 'g7-core-ja')).toBe(false);
        expect(summary()).toEqual(['1', '1', '1', '1', '1']);
        expect(submit().disabled).toBe(false);
    });

    /**
     * @effects installer_step4_restore_without_admin_template_falls_back_to_default
     */
    it('저장된 선택에 관리자 템플릿이 없거나 지금 목록에 없으면 첫 관리자 템플릿으로 채우고 나머지는 복원한다', async () => {
        await bootStep4({
            admin_templates: ['vendor-removed_admin'],
            user_templates: [],
            modules: ['sirsoft-page', 'vendor-removed_module'],
            plugins: [],
            language_packs: [],
        });

        expect(isShownSelected('admin_templates', 'sirsoft-admin_basic')).toBe(true);
        expect(isShownSelected('modules', 'sirsoft-page')).toBe(true);
        expect(summary()).toEqual(['1', '0', '1', '0', '0']);
        expect(submit().disabled).toBe(false);
    });
});
