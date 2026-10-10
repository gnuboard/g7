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
import runModalLayout from '../../../layouts/admin/partials/admin_ecommerce_settings/_modal_privacy_run.json';

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
        // 아이콘과 문구는 감싸는 줄이 세로로 쌓는다 — 아이콘 클래스의 mx-auto·mb 는 Font Awesome 의 display(inline-block)에
        // 무시되어 아이콘과 「대상이 없습니다」가 한 줄에 겹쳐 붙었다(2026-09-26 실측).
        expect(String(empty.props?.className ?? '')).toMatch(/\bflex\b/);
        expect(String(empty.props?.className ?? '')).toMatch(/\bflex-col\b/);
        const emptyIcon = (empty.children ?? []).find((c: any) => c.name === 'Icon');
        expect(emptyIcon, 'empty icon').toBeTruthy();
        expect(String(emptyIcon.props?.className ?? '')).not.toMatch(/\b(mx-auto|mb-\d+|block)\b/);
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


/**
 * 직권 실행 블록 전용 — 레이아웃 트리에서 id 로 노드를 찾는다(props 의 id 는 무시).
 *
 * @param node 탐색 시작 노드
 * @param id 노드 id
 * @returns 노드 또는 null
 */
function manualRunNode(node: any, id: string): any {
    if (!node || typeof node !== 'object') return null;
    if (!Array.isArray(node) && node.id === id && (node.type || node.name)) return node;
    for (const child of Object.values(node)) {
        const found = manualRunNode(child, id);
        if (found) return found;
    }

    return null;
}

/**
 * 직권 실행 블록 전용 — 이 확장의 다국어 파일에서 점 경로 값을 읽는다.
 *
 * @param locale 로케일
 * @param key 확장 접두사를 뺀 점 경로
 * @returns 값 또는 undefined
 */
function manualRunLang(locale: string, key: string): any {
    let current = path.dirname(fileURLToPath(import.meta.url));
    for (let depth = 0; depth < 10 && !fs.existsSync(path.join(current, 'module.json')); depth++) {
        current = path.dirname(current);
    }
    const lang = JSON.parse(fs.readFileSync(path.join(current, `resources/lang/partial/${locale}/admin/settings.json`), 'utf-8'));

    return key.split('.').reduce((acc: any, part: string) => (acc == null ? undefined : acc[part]), lang);
}

describe('개인정보 조치 직권 실행 — 「지금 실행」 (#636 후속)', () => {
    const buttons: Array<[string, string]> = [['run_privacy_retention', 'retention:sirsoft-ecommerce.order']];
    const runModal: any = runModalLayout;

    /**
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_manual_run_button_opens_the_confirm_modal
     */
    it('「지금 실행」은 실행 권한으로만 그리고, 저장 전 변경·진행 중 작업이면 막으며, 확인 창을 연 뒤 건수를 다시 센다', () => {
        for (const [id, key] of buttons) {
            const button = manualRunNode(privacyTab, id);
            expect(button, id).toBeTruthy();
            expect(button.if, id).toContain(`['${key}']?.can_run`);
            expect(button.props.disabled, id).toContain('_local.hasChanges');
            expect(button.props.disabled, id).toContain('?.run?.is_active');

            const click = actionsOf(button).find((a: any) => a.type === 'click');
            const steps = actionsOf(click);
            expect(steps.map((s: any) => s.handler), id).toEqual(['setState', 'openModal', 'apiCall']);
            expect(steps[0].params.privacyRun, id).toContain(`key: '${key}'`);
            expect(steps[1].target).toBe('privacy_run_modal');
            expect(steps[2].target).toBe(`/api/admin/privacy/manual-runs/${key}/preview`);
        }
    });

    /**
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_manual_run_button_opens_the_confirm_modal
     */
    it('대상마다 한 행에 건수·대상 보기·지금 실행·결과 줄이 함께 있고, 실행은 채운 강조색을 쓰지 않는다', () => {
        // 2026-09-26 결정 — 코어 개인정보 탭과 같은 「대상 행」 양식. 실행 버튼이 자기 대상과 떨어지면 짝을 눈으로 맞춰야 한다.
        const rows: Array<[string, string, string, string]> = [
            ['privacy_retention_target_row', 'view_privacy_retention_targets', 'run_privacy_retention', 'privacy_retention_manual_run_status'],
        ];
        const hasBadge = (node: any): boolean =>
            !!node && typeof node === 'object'
            && (String(node.props?.className ?? '').includes('badge') || Object.values(node).some((child: any) => hasBadge(child)));

        for (const [rowId, viewId, runId, statusId] of rows) {
            const row = manualRunNode(privacyTab, rowId);
            expect(row, rowId).toBeTruthy();
            for (const id of [viewId, runId, statusId]) {
                expect(manualRunNode(row, id), `${rowId} > ${id}`).toBeTruthy();
            }
            expect(hasBadge(row), rowId).toBe(true);
            expect(manualRunNode(row, runId).props.className, runId).not.toMatch(/btn-(warning|primary|danger)\b/);
        }
    });

    /**
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_manual_run_button_opens_the_confirm_modal
     */
    it('상태 소스는 이 화면 대상만 조회하고, 진행 중이면 폴링·끝나면 응답값으로만 건수를 다시 받는다', () => {
        const dataSource = (settingsLayout as any).data_sources.find((d: any) => d.id === 'privacyManualRuns');
        expect(dataSource.endpoint).toBe('/api/admin/privacy/manual-runs');
        expect(dataSource.params.targets.split(',')).toEqual(buttons.map(([, key]) => key));

        const handlers = callbacksOf(dataSource, 'onSuccess');
        expect(handlers.find((h: any) => h.handler === 'startInterval').if).toContain('has_active === true');
        expect(handlers.find((h: any) => h.handler === 'stopInterval').if).toContain('has_active !== true');
        const refetch = handlers.find((h: any) => h.handler === 'refetchDataSource');
        expect(refetch.params.dataSourceId).toBe('ecommercePrivacyRetentionPreview');
        expect(refetch.if).toContain('finished_since === true');
        for (const handler of handlers) {
            expect(String(handler.if ?? ''), handler.handler).not.toContain('_global');
        }
    });

    /**
     * @scenario order_state=confirmed, toggle=off, mode=anonymize
     *
     * @effects order_manual_run_button_opens_the_confirm_modal
     */
    it('확인 창은 코어 직권 실행 API 로 접수하고 이 확장의 문구(ko·en)만 쓴다', () => {
        const execute = manualRunNode(runModal, 'privacy_run_execute');
        // 더블클릭 이중 접수 방지 — disabled 의 submitting 은 다시 그려진 뒤에야 걸린다(#736 H 실측: 게시판 확인 창 연타 2건 접수).
        expect(execute.props?.lockRepeatClick).toBe(true);
        const call = actionsOf(actionsOf(execute).find((a: any) => a.type === 'click')).find((a: any) => a.handler === 'apiCall');
        expect(call.params.method).toBe('POST');
        expect(call.target).toBe('/api/admin/privacy/manual-runs/{{_global.privacyRun?.key}}');
        expect(callbacksOf(call, 'onSuccess').filter((a: any) => a.handler === 'refetchDataSource').map((a: any) => a.params.dataSourceId))
            .toEqual(['privacyManualRuns', 'ecommercePrivacyRetentionPreview']);
        expect(JSON.stringify(settingsLayout)).toContain('_modal_privacy_run.json');

        const source = JSON.stringify(runModal) + JSON.stringify(privacyTab);
        expect(source).not.toContain('admin.settings.privacy.manual_run.');
        expect(source).not.toContain('skip_waiting_checkbox');
        const keys = new Set([...source.matchAll(/sirsoft-ecommerce\.admin\.settings\.privacy_retention\.manual_run\.([a-z_]+)/g)].map((m) => m[1]));
        if (keys.delete('modes')) {
            ['anonymize', 'delete', 'erase_ip'].forEach((mode) => keys.add(`modes.${mode}`));
        }
        expect(keys.size).toBeGreaterThan(10);
        for (const locale of ['ko', 'en']) {
            for (const key of keys) {
                expect(typeof manualRunLang(locale, `privacy_retention.manual_run.${key}`), `${locale}:${key}`).toBe('string');
            }
        }
    });
});
