/**
 * 쇼핑몰 환경설정 — 개인정보 보유기간 탭 계약 테스트 (이슈 #636 S5)
 *
 * @description
 * 종결 주문의 개인정보를 보유기간이 지나면 익명화·삭제하는 탭이다. 되돌릴 수 없는 처리라
 * 기본 꺼짐이고 하한이 전자상거래법 5년이다. 화면 바인딩 키가 저장 카테고리·기본값·서버 검증·
 * 저장 payload 와 일치하는지, 방식별 안내(익명화 — 조회 불가 / 삭제 — 리뷰·영수증 조회 소실)가
 * 그 방식을 골랐을 때만 보이는지, 대상 건수 뱃지가 미리보기 API 를 읽는지를 고정한다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { actionsOf, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import privacyTab from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_privacy_retention.json';
import settingsLayout from '../../../layouts/admin/admin_ecommerce_settings.json';
import targetsModal from '../../../layouts/admin/partials/admin_ecommerce_settings/_modal_privacy_targets.json';

/**
 * 모듈 루트(module.json 기준)를 위로 훑어 찾는다.
 *
 * @returns 모듈 루트 절대경로
 */
function moduleRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'module.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('module.json 을 가진 모듈 루트를 찾지 못했습니다.');
}

/**
 * 레이아웃 트리에서 조건을 만족하는 첫 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 노드 판정 함수
 * @returns 찾은 노드 또는 null
 */
function findNode(node: any, predicate: (n: any) => boolean): any {
    if (!node || typeof node !== 'object') return null;
    if (predicate(node)) return node;

    const children = Array.isArray(node) ? node : [...(node.components ?? []), ...(node.children ?? [])];
    for (const child of children) {
        const found = findNode(child, predicate);
        if (found) return found;
    }

    return null;
}

const tab = findNode(privacyTab, (n) => n.id === 'tab_content_privacy_retention');

describe('쇼핑몰 개인정보 보유기간 탭 — 계약', () => {
    /**
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_retention_settings_tab_binds_saved_keys
     */
    it('탭이 등록되고 자기 탭일 때만 보인다', () => {
        const raw = JSON.stringify(settingsLayout);

        expect(tab).not.toBeNull();
        expect(tab.if).toContain("=== 'privacy_retention'");
        expect(raw).toContain('"id":"privacy_retention"');
    });

    /**
     * @scenario order_state=confirmed, toggle=on, mode=anonymize
     *
     * @effects order_retention_settings_tab_binds_saved_keys
     */
    it('토글·보유기간·방식이 privacy_retention 카테고리 키를 바인딩하고 하한을 한계값에서 읽는다', () => {
        const toggle = findNode(tab, (n) => n.name === 'Toggle');
        const years = findNode(tab, (n) => n.name === 'Input' && n.props?.name === 'privacy_retention.retention_years');
        const mode = findNode(tab, (n) => n.name === 'RadioGroup');

        expect(toggle.props.name).toBe('privacy_retention.enabled');
        expect(years.props.min).toContain('privacy_retention_years_min');
        expect(years.props.min).toContain('?? 5');
        expect(mode.props.name).toBe('privacy_retention.mode');
        expect(mode.props.options.map((o: any) => o.value)).toEqual(['anonymize', 'delete']);
    });

    /**
     * @scenario order_state=confirmed, toggle=on, mode=delete
     *
     * @effects order_retention_settings_warn_per_mode
     */
    it('방식별 안내는 그 방식을 골랐을 때만 보이고 삭제 경고는 적색이다', () => {
        const anonymize = findNode(tab, (n) => n.id === 'privacy_retention_anonymize_notice');
        const warning = findNode(tab, (n) => n.id === 'privacy_retention_delete_warning');
        const fields = findNode(tab, (n) => n.id === 'privacy_retention_fields');

        expect(fields.if).toContain('privacy_retention?.enabled');
        expect(anonymize.if).toContain("=== 'anonymize'");
        expect(warning.if).toContain("=== 'delete'");
        expect(warning.props.className).toContain('text-error-soft');
    });

    /**
     * @scenario order_state=confirmed, toggle=on, mode=anonymize
     *
     * @effects order_retention_preview_api_counts_while_off
     */
    it('대상 건수 뱃지가 미리보기 데이터소스를 읽고, 그 데이터소스가 탭 한정·대기·재조회된다', () => {
        const raw = JSON.stringify(tab);
        const layoutRaw = JSON.stringify(settingsLayout);
        const dataSource = (settingsLayout as any).data_sources.find((d: any) => d.id === 'ecommercePrivacyRetentionPreview');

        expect(raw).toContain('ecommercePrivacyRetentionPreview?.data?.orders');
        expect(dataSource.endpoint).toBe('/api/modules/sirsoft-ecommerce/admin/settings/privacy-retention-preview');
        expect(dataSource.if).toContain("=== 'privacy_retention'");
        expect(layoutRaw).toContain('"dataSourceId":"ecommercePrivacyRetentionPreview"');
    });

    /**
     * @scenario order_state=confirmed, toggle=on, mode=delete
     *
     * @effects order_retention_settings_are_saved
     */
    it('기본값·서버 검증·카테고리 화이트리스트가 같은 키를 본다', () => {
        const defaults = JSON.parse(fs.readFileSync(path.join(moduleRoot(), 'config/settings/defaults.json'), 'utf-8'));
        const request = fs.readFileSync(
            path.join(moduleRoot(), 'src/Http/Requests/Admin/StoreEcommerceSettingsRequest.php'),
            'utf-8',
        );
        const validCategories = request.match(/\$validCategories = \[[^\]]*\]/)?.[0] ?? '';
        const ecommerceConfig = fs.readFileSync(path.join(moduleRoot(), 'config/ecommerce.php'), 'utf-8');

        expect(defaults._meta.categories).toContain('privacy_retention');
        expect(defaults.defaults.privacy_retention).toEqual({ enabled: false, retention_years: 5, mode: 'anonymize' });
        expect(validCategories).toContain("'privacy_retention'");
        expect(request).toContain("'privacy_retention.mode' => ['sometimes', 'required', 'string', 'in:anonymize,delete']");
        expect(request).toContain('limits.privacy_retention_years_min');
        expect(ecommerceConfig).toContain("'privacy_retention_years_min' => 5");
    });

    /**
     * @scenario order_state=confirmed, toggle=on, mode=anonymize
     *
     * @effects order_retention_settings_tab_binds_saved_keys
     */
    it('편집기 샘플이 미리보기 데이터소스를 가진다', () => {
        const spec = fs.readFileSync(path.join(moduleRoot(), 'editor-spec.json'), 'utf-8');

        // 샘플이 없으면 편집기 캔버스에서만 뱃지가 빈 채로 보이고 오류는 남지 않는다.
        expect(spec).toContain('"ecommercePrivacyRetentionPreview"');
    });
});

/**
 * 레이아웃 트리에서 조건을 만족하는 노드를 조상 경로와 함께 모두 모은다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 노드 판정 함수
 * @param ancestors 지금까지의 조상 노드
 * @returns [노드, 조상 목록] 쌍 목록
 */
function collectWithAncestors(node: any, predicate: (n: any) => boolean, ancestors: any[] = []): Array<[any, any[]]> {
    if (!node || typeof node !== 'object') return [];
    const found: Array<[any, any[]]> = predicate(node) ? [[node, ancestors]] : [];
    const children = Array.isArray(node) ? node : [...(node.components ?? []), ...(node.children ?? [])];
    for (const child of children) {
        found.push(...collectWithAncestors(child, predicate, Array.isArray(node) ? ancestors : [...ancestors, node]));
    }

    return found;
}

/**
 * 점 경로로 중첩 객체 값을 읽는다.
 *
 * @param source 원본 객체
 * @param dotted 점으로 이은 경로
 * @returns 값 또는 undefined
 */
function readPath(source: any, dotted: string): any {
    return dotted.split('.').reduce((acc, key) => (acc == null ? undefined : acc[key]), source);
}

/**
 * 로케일별 다국어 묶음을 읽는다.
 *
 * @param locale 로케일
 * @returns 다국어 JSON
 */
function settingsLang(locale: string): any {
    return JSON.parse(fs.readFileSync(path.join(moduleRoot(), `resources/lang/partial/${locale}/admin/settings.json`), 'utf-8'));
}

describe('개인정보 조치 대상 — 건수 상시 노출 + 대상 목록 창 (#636 §1-E)', () => {
    const viewButtons = collectWithAncestors(tab, (n) => /^view_.*targets$/.test(n.id ?? ''));

    /**
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_retention_targets_match_the_population
     */
    it('「대상 보기」 버튼이 있고, 권한으로만 게이트되며(0건이어도 보인다) 기능 토글 안에 갇히지 않는다', () => {
        // 모집단은 레이아웃에서 파생한다 — 버튼이 하나도 없으면 이 단언 자체가 공허해진다.
        expect(viewButtons.length).toBe(1);

        for (const [button, ancestors] of viewButtons) {
            expect(button.if, button.id).toContain('can_view_targets');
            // 대상이 0건이어도 버튼은 보인다 — 0건인 까닭을 창에서 확인할 수 있어야 한다.
            expect(button.if, button.id).not.toMatch(/> 0/);
            // 켜기 전에 규모와 명단을 보는 자리다 — 조상에 기능 켜짐 조건이 걸리면 꺼진 상태에서 사라진다.
            for (const ancestor of ancestors) {
                expect(String(ancestor.if ?? ''), `${button.id} 조상 ${ancestor.id ?? ancestor.name}`).not.toMatch(/enabled/);
            }
        }
    });

    /**
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_retention_targets_match_the_population
     */
    it('버튼은 목록 상태를 첫 쪽으로 되돌리고 → 제목 → 명단 재조회 → 창 열기 순서로 동작한다', () => {
        for (const [button] of viewButtons) {
            const click = actionsOf(button).find((a: any) => a.type === 'click');
            const steps = actionsOf(click).map((a: any) => a.handler);

            expect(steps, button.id).toEqual(['setState', 'setState', 'refetchDataSource', 'openModal']);
            expect(actionsOf(click)[0].params.privacyTargets, button.id).toContain('currentPage: 1');
            expect(actionsOf(click)[0].params.privacyTargets, button.id).toContain('hasMore: true');
            expect(actionsOf(click)[1].params['privacyTargets.title'], button.id).toMatch(/^\$t:/);
            expect(actionsOf(click)[2].params.dataSourceId).toBe('privacyTargets');
            expect(actionsOf(click)[3].target).toBe('privacy_targets_modal');
        }
    });

    /**
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_retention_targets_match_the_population
     */
    it('명단 데이터소스는 스스로 조회하지 않고 한 쪽 20건을 받는다', () => {
        const dataSource = (settingsLayout as any).data_sources.find((d: any) => d.id === 'privacyTargets');

        expect(dataSource).toBeTruthy();
        expect(dataSource.endpoint.startsWith('/api/admin/privacy/')).toBe(true);
        expect(dataSource.auto_fetch).toBe(false);
        expect(dataSource.params.per_page).toBe(20);
        expect(JSON.stringify(settingsLayout)).toContain('_modal_privacy_targets.json');
    });

    /**
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_retention_targets_match_the_population
     */
    it('창은 스크롤 끝에서 다음 쪽을 이어 붙이고, 마지막 쪽·진행 중에는 부르지 않는다', () => {
        const [[list]] = collectWithAncestors(targetsModal, (n) => n.id === 'privacy_targets_list');
        const scroll = actionsOf(list).find((a: any) => a.type === 'scroll');
        const sw = actionsOf(scroll).find((a: any) => a.handler === 'switch');
        const steps = actionsOf(sw.cases.true);
        const api = steps.find((a: any) => a.handler === 'apiCall');

        expect(sw.params.value).toContain('has_more_pages === true');
        expect(sw.params.value).toContain('!_global.privacyTargets?.isLoadingMore');
        expect(api.target.startsWith('/api/admin/privacy/')).toBe(true);
        expect(api.params.query.page).toContain('currentPage');
        expect(callbacksOf(api, 'onSuccess')[0]).toMatchObject({ handler: 'appendDataSource', params: { dataSourceId: 'privacyTargets', dataPath: 'data.data' } });
        expect(JSON.stringify(callbacksOf(api, 'onSuccess'))).toContain('has_more_pages');
        // 실패해도 진행 중 표시를 끈다 — 남으면 다시는 다음 쪽을 부르지 않는다.
        expect(JSON.stringify(callbacksOf(api, 'onError'))).toContain('privacyTargets.isLoadingMore');
    });

    /**
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_retention_targets_match_the_population
     */
    it('창은 명단 행을 반복하고 상세를 새 탭으로 열며, 비면 빈 상태를 보인다', () => {
        const raw = JSON.stringify(targetsModal);
        const [[row]] = collectWithAncestors(targetsModal, (n) => n.iteration?.source?.includes('privacyTargets?.data?.data'));
        const [[empty]] = collectWithAncestors(targetsModal, (n) => n.id === 'privacy_targets_empty');

        expect(row.iteration.item_var).toBe('target');
        expect(raw).toContain('"target":"_blank"');
        expect(empty.if).toContain('length === 0');
    });

    /**
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_retention_targets_match_the_population
     */
    it('버튼·창이 쓰는 대상 문구가 ko/en 모두에 있고 편집기 샘플이 명단 데이터소스를 가진다', () => {
        const raw = JSON.stringify(tab) + JSON.stringify(targetsModal);
        const keys = [...new Set([...raw.matchAll(/\$t:(sirsoft-ecommerce\.admin\.settings\.privacy_retention\.targets\.[a-z0-9_.]+)/g)].map((m) => m[1]))];

        expect(keys.length).toBeGreaterThan(3);
        for (const locale of ['ko', 'en']) {
            const bundle = settingsLang(locale);
            for (const key of keys) {
                expect(typeof readPath(bundle, key.replace(/^sirsoft-ecommerce\.admin\.settings\./, '')), `${locale}:${key}`).toBe('string');
            }
        }

        const spec = fs.readFileSync(path.join(moduleRoot(), 'editor-spec.json'), 'utf-8');
        expect(spec).toContain('"privacyTargets"');
    });
});

describe('보유기간 탭 — 대상 보기 줄의 좁은 화면 줄바꿈 (#636 §8.5 T8, 브라우저 실측 결함)', () => {
    /**
     * 390px 실측에서 제목·건수 배지·버튼이 한 줄에 끼여 배지가 「대상 24 / 건」으로 쪼개졌다.
     * 버튼을 담은 줄은 넘치면 다음 줄로 흘러야 한다 — 부모 줄에 wrap 이 없으면 red.
     *
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_retention_target_button_wraps_on_narrow_viewports
     */
    it('「대상 주문 보기」 버튼의 부모 줄은 줄바꿈되는 flex 이고, 간격은 gap 으로 준다', () => {
        const viewButtons = collectWithAncestors(privacyTab, (n) => /^view_.*targets$/.test(n.id ?? ''));
        expect(viewButtons.length).toBe(1);

        for (const [button, ancestors] of viewButtons) {
            const row = ancestors[ancestors.length - 1];
            const rowClass = String(row?.props?.className ?? '');
            expect(rowClass, `${button.id} 부모 줄`).toMatch(/flex-wrap/);
            expect(rowClass, `${button.id} 부모 줄`).toMatch(/\bgap-/);
            expect(String(button.props?.className ?? ''), button.id).not.toMatch(/\bml-/);
        }
    });
});
