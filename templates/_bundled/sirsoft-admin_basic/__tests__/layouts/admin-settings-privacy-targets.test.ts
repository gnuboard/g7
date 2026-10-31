/**
 * 환경설정 개인정보 탭 — 조치 대상 건수 상시 노출과 대상 목록 창 계약 테스트 (이슈 #636 §1-E)
 *
 * @description
 * 탈퇴 잔여정보 파기와 휴면 네 단계의 대상 건수는 기능이 꺼져 있어도 보이고, 옆 「대상 보기」 버튼이
 * 같은 명단 창 하나를 목록 경로만 바꿔 연다. 명단 창은 스크롤 끝에서 다음 쪽을 이어 붙인다.
 * 버튼 모집단은 레이아웃에서 파생한다 — 버튼이 늘거나 줄면 개수 단언이 먼저 red 가 된다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { actionsOf, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import privacyTab from '../../layouts/partials/admin_settings/_tab_privacy.json';
import settingsLayout from '../../layouts/admin_settings.json';
import targetsModal from '../../layouts/partials/admin_settings/_modal_privacy_targets.json';

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
 * template.json 을 가진 확장 루트를 위로 훑어 찾는다.
 *
 * @returns 확장 루트 절대경로
 */
function templateRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'template.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('template.json 을 가진 루트를 찾지 못했습니다.');
}

/**
 * 로케일별 다국어 묶음을 읽는다.
 *
 * @param locale 로케일
 * @returns 다국어 JSON
 */
function adminLang(locale: string): any {
    return JSON.parse(fs.readFileSync(path.join(templateRoot(), `lang/partial/${locale}/admin.json`), 'utf-8'));
}

/** 휴면 네 단계 — 탭은 단계마다 한 행을 반복 하나로 그린다(2026-09-26 대상 행 재배치). */
const DORMANT_STAGES = ['pre_notice', 'convert', 'withdraw_notice', 'withdraw'];

/**
 * 「대상 보기」 버튼이 가리키는 대상 수 — 반복 안의 버튼은 반복 원본의 항목 수만큼 센다.
 *
 * @param buttons collectWithAncestors 결과
 * @returns 화면에 그려지는 대상 수
 */
function renderedTargetCount(buttons: Array<[any, any[]]>): number {
    return buttons.reduce((sum, [button, ancestors]) => {
        const loop = [button, ...ancestors].find((n: any) => n.iteration?.item_var === 'stage');
        if (!loop) return sum + 1;
        expect(loop.iteration.source, button.id).toBe(`{{${JSON.stringify(DORMANT_STAGES).replace(/"/g, "'").replace(/,/g, ', ')}}}`);

        return sum + DORMANT_STAGES.length;
    }, 0);
}

describe('개인정보 조치 대상 — 건수 상시 노출 + 대상 목록 창 (#636 §1-E)', () => {
    const viewButtons = collectWithAncestors(privacyTab, (n) => /^view_.*targets$/.test(n.id ?? ''));

    /**
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_matches_the_preview_population
     */
    it('「대상 보기」 버튼이 있고, 권한으로만 게이트되며(0건이어도 보인다) 기능 토글 안에 갇히지 않는다', () => {
        // 모집단은 레이아웃에서 파생한다 — 버튼이 하나도 없으면 이 단언 자체가 공허해진다.
        // 탈퇴 잔여정보 1 + 휴면 단계 반복(4) = 5 대상.
        expect(renderedTargetCount(viewButtons)).toBe(5);

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
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_matches_the_preview_population
     */
    it('버튼은 목록 상태를 첫 쪽으로 되돌리고 → 제목 → 명단 재조회 → 창 열기 순서로 동작한다', () => {
        for (const [button] of viewButtons) {
            const click = actionsOf(button).find((a: any) => a.type === 'click');
            const steps = actionsOf(click).map((a: any) => a.handler);

            expect(steps, button.id).toEqual(['setState', 'setState', 'refetchDataSource', 'openModal']);
            expect(actionsOf(click)[0].params.privacyTargets, button.id).toContain('currentPage: 1');
            expect(actionsOf(click)[0].params.privacyTargets, button.id).toContain('hasMore: true');
            // 고정 대상은 `$t:` 리터럴, 단계 반복 안에서는 단계 이름으로 키를 만든다.
            expect(actionsOf(click)[1].params['privacyTargets.title'], button.id).toMatch(/^\$t:|^\{\{\$t\('admin\.settings\.privacy\.targets\.title\.' \+ stage\)\}\}$/);
            expect(actionsOf(click)[2].params.dataSourceId).toBe('privacyTargets');
            expect(actionsOf(click)[3].target).toBe('privacy_targets_modal');
        }
    });

    /**
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_matches_the_preview_population
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
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_matches_the_preview_population
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
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_matches_the_preview_population
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
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_list_matches_the_preview_population
     */
    it('버튼·창이 쓰는 대상 문구가 ko/en 모두에 있고 편집기 샘플이 명단 데이터소스를 가진다', () => {
        const raw = JSON.stringify(privacyTab) + JSON.stringify(targetsModal);
        const keys = [...new Set([...raw.matchAll(/\$t:(admin\.settings\.privacy\.targets\.[a-z0-9_.]+)/g)].map((m) => m[1]))];

        expect(keys.length).toBeGreaterThan(3);
        // 단계 반복이 식으로 만드는 키(`targets.rows.{stage}` 행 이름 · `targets.title.{stage}` 창 제목)도 모두 있어야 한다.
        const stageKeys = DORMANT_STAGES.flatMap((stage) => [`admin.settings.privacy.targets.rows.${stage}`, `admin.settings.privacy.targets.title.${stage}`]);
        expect(raw).toContain("$t('admin.settings.privacy.targets.rows.' + stage)");
        expect(raw).toContain("$t('admin.settings.privacy.targets.title.' + stage)");
        for (const locale of ['ko', 'en']) {
            const bundle = adminLang(locale);
            for (const key of [...keys, ...stageKeys]) {
                expect(typeof readPath(bundle, key.replace(/^admin\./, '')), `${locale}:${key}`).toBe('string');
            }
        }

        const spec = fs.readFileSync(path.join(templateRoot(), 'editor-spec/sampleData.json'), 'utf-8');
        expect(spec).toContain('"privacyTargets"');
    });
});

describe('개인정보 탭 — 저장 뒤 건수 재조회 (#636 §8.5 T11, 브라우저 실측 결함)', () => {
    /**
     * 저장 뒤 배지가 옛 숫자를 그대로 보이면 운영자는 방금 켠 기능이 처리할 규모를 잘못 읽는다 —
     * 저장한 기간으로 다시 센 숫자로 즉시 바뀌어야 한다.
     *
     * @scenario toggle=on, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects preview_badges_render_the_same_counts_the_api_returns
     */
    it('설정 저장 성공 콜백이 대상 건수(privacyPreview)를 다시 받는다', () => {
        // 액션은 sequence·switch 안에 중첩되므로 레이아웃 전체를 훑어 저장 호출을 모은다.
        const saves: any[] = [];
        const walk = (value: any): void => {
            if (Array.isArray(value)) { value.forEach(walk); return; }
            if (!value || typeof value !== 'object') return;
            if (value.handler === 'apiCall' && value.target === '/api/admin/settings' && value.params?.method === 'POST') saves.push(value);
            Object.values(value).forEach(walk);
        };
        walk(settingsLayout);

        expect(saves.length).toBe(1);
        const onSuccess = callbacksOf(saves[0], 'onSuccess');
        const refetch = onSuccess.find((a: any) => a.handler === 'refetchDataSource' && a.params?.dataSourceId === 'privacyPreview');
        expect(refetch).toBeDefined();
        // 저장 버튼은 모든 탭이 공유한다 — 다른 탭 저장마다 개인정보 건수를 세지 않도록 개인정보 탭에서만 다시 받는다.
        expect(String(refetch.if ?? '')).toContain("=== 'privacy'");
    });
});

describe('개인정보 탭 — 대상 보기 줄의 좁은 화면 줄바꿈 (#636 §8.5 T8, 브라우저 실측 결함)', () => {
    /**
     * 390px 실측에서 제목·건수 배지·버튼 둘이 한 줄에 끼여 버튼 글자가 세 줄로 쪼개졌다.
     * 버튼을 담은 줄은 넘치면 다음 줄로 흘러야 한다 — 부모 줄에 wrap 이 없으면 red.
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects target_buttons_wrap_on_narrow_viewports
     */
    it('「대상 보기」 버튼의 부모 줄은 줄바꿈되는 flex 이고, 간격은 gap 으로 준다', () => {
        const viewButtons = collectWithAncestors(privacyTab, (n) => /^view_.*targets$/.test(n.id ?? ''));
        expect(renderedTargetCount(viewButtons)).toBe(5);

        for (const [button, ancestors] of viewButtons) {
            const row = ancestors[ancestors.length - 1];
            const rowClass = String(row?.props?.className ?? '');
            expect(rowClass, `${button.id} 부모 줄`).toMatch(/flex-wrap/);
            expect(rowClass, `${button.id} 부모 줄`).toMatch(/\bgap-/);
            // 줄이 바뀌면 왼쪽 여백이 새 줄 첫머리에 남아 들여쓰기처럼 보인다 — 간격은 gap 이 맡는다.
            expect(String(button.props?.className ?? ''), button.id).not.toMatch(/\bml-/);
        }
    });
});
