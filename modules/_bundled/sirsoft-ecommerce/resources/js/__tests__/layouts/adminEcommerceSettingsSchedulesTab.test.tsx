/**
 * @file adminEcommerceSettingsSchedulesTab.test.tsx
 * e2e:allow 화면 축(설정 > 스케줄 탭 토글 왕복)은 라이브 매트릭스 M20 이 덮는다 — 이 파일은 레이아웃 구조·문구 단언이다.
 * @description 쇼핑몰 설정 > 스케줄 탭(7.2.0, #17 D15) — 이 모듈이 선언한 예약 작업 목록·켜고 끄기·스케줄 관리 링크
 *
 * 계약:
 *  - 목록은 코어 목록 API 를 소유 필터로 재사용한다(`/api/admin/schedules?extension_type=module&extension_identifier=sirsoft-ecommerce`) —
 *    확장 전용 엔드포인트를 새로 두지 않는다(계획서 §6 「확장 설정 탭은 코어 API 를 재사용」).
 *  - 켜고 끄기는 코어 `PATCH /api/admin/schedules/bulk-status` 에 그 줄 하나만 담아 보낸다.
 *  - 코어 스케줄 조회 권한이 없으면(403) 탭 내용 대신 이유를 보인다.
 *  - 「스케줄 관리에서 변경」 링크는 같은 소유 쿼리를 싣고(스케줄 화면이 그대로 필터로 읽는다), 설정 화면 공통 안내 상자(alert-info) 안에 놓인다.
 *  - 코어 스케줄 수정 권한이 없으면(목록 응답 abilities.can_update=false) 읽기 전용 안내를 보인다.
 *
 * 시나리오 매니페스트: settings-schedules-tab (tests/scenarios — test_files 귀속)
 */
import { describe, it, expect } from 'vitest';
import settingsLayout from '../../../layouts/admin/admin_ecommerce_settings.json';
import tabSchedules from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_schedules.json';
import ko from '../../../lang/partial/ko/admin/settings.json';
import en from '../../../lang/partial/en/admin/settings.json';
import koRoot from '../../../lang/ko.json';
import enRoot from '../../../lang/en.json';

/** 객체 트리의 모든 노드를 모읍니다. */
function nodes(value: any): any[] {
    if (!value || typeof value !== 'object') return [];
    if (Array.isArray(value)) return value.flatMap(nodes);
    return [value, ...Object.values(value).flatMap(nodes)];
}

/** `{{ 식 }}` 하나를 주어진 범위로 평가합니다(테스트 전용). */
function evalExpr(expr: string, scope: Record<string, any>): any {
    const body = expr.replace(/^\{\{\s*/, '').replace(/\s*\}\}$/, '');
    // eslint-disable-next-line no-new-func
    return new Function(...Object.keys(scope), `return (${body});`)(...Object.values(scope));
}

const ds = (settingsLayout as any).data_sources.find((d: any) => d.id === 'ecommerceSchedules');

describe('쇼핑몰 설정 스케줄 탭', () => {
    /**
     * @scenario case=list
     * @effects extension_tab_reuses_core_list_api
     */
    it('목록은 코어 목록 API 를 이 모듈 소유 필터로 부르고 스케줄 탭에서만 부른다', () => {
        expect(ds.endpoint).toBe('/api/admin/schedules');
        expect(ds.method).toBe('GET');
        expect(ds.params).toMatchObject({ extension_type: 'module', extension_identifier: 'sirsoft-ecommerce' });
        expect(Number(ds.params.per_page)).toBeLessThanOrEqual(100);
        expect(evalExpr(ds.if, { query: { tab: 'schedules' }, _global: {} })).toBe(true);
        expect(evalExpr(ds.if, { query: { tab: 'seo' }, _global: {} })).toBe(false);
        // 확장 전용 스케줄 엔드포인트를 만들지 않는다
        const endpoints = nodes(settingsLayout).map((n) => n.endpoint ?? n.target).filter((e) => typeof e === 'string');
        expect(endpoints.some((e: string) => e.includes('/api/modules/sirsoft-ecommerce') && e.includes('schedule'))).toBe(false);
    });

    /**
     * @scenario case=permission
     * @effects extension_tab_forbidden_notice
     */
    it('조회 403 이면 표지를 남기고 탭은 목록 대신 권한 안내를 보인다', () => {
        expect(JSON.stringify(ds.errorHandling ?? ds.error_handling ?? ds)).toContain('ecommerceSchedulesLoadError');
        const notice = nodes(tabSchedules).find((n) => n.id === 'ecommerce_schedules_no_permission');
        const card = nodes(tabSchedules).find((n) => n.id === 'ecommerce_schedules_card');
        expect(evalExpr(notice.if, { _local: { ecommerceSchedulesLoadError: 'forbidden' } })).toBe(true);
        expect(evalExpr(card.if, { _local: { ecommerceSchedulesLoadError: 'forbidden' } })).toBe(false);
        expect(evalExpr(card.if, { _local: {} })).toBe(true);
    });

    /**
     * @scenario case=toggle
     * @effects extension_tab_toggle_uses_bulk_status
     */
    it('활성 토글은 코어 bulk-status 에 그 줄 하나만 보내고 성공·실패 모두 목록을 다시 받는다', () => {
        const toggle = nodes(tabSchedules).find((n) => n.name === 'Toggle');
        expect(evalExpr(toggle.props.disabled, { row: { abilities: { can_update: false } } })).toBe(true);
        expect(evalExpr(toggle.props.disabled, { row: { abilities: { can_update: true } } })).toBe(false);
        const call = nodes(toggle).find((n) => n.handler === 'apiCall');
        expect(call.target).toBe('/api/admin/schedules/bulk-status');
        expect(call.params.method).toBe('PATCH');
        expect(call.params.body.ids).toEqual(['{{row.id}}']);
        const refetches = nodes(call).filter((n) => n.handler === 'refetchDataSource').map((n) => n.params.dataSourceId);
        expect(refetches.filter((id: string) => id === 'ecommerceSchedules')).toHaveLength(2);
    });

    /**
     * @scenario case=link
     * @effects extension_tab_links_core_screen_with_owner_query
     */
    it('스케줄 관리 링크는 같은 소유 쿼리를 싣는다', () => {
        const link = nodes(tabSchedules).find((n) => n.id === 'ecommerce_schedules_manage_link');
        expect(link.name).toBe('A');
        expect(link.props.href).toBe('/admin/schedules?extension_type=module&extension_identifier=sirsoft-ecommerce');
    });

    /**
     * 「스케줄 관리에서 변경」 안내는 설정 화면 전체가 같은 모양이다 — 파란 안내 상자 + 정보 아이콘 + 한 문장 + 링크(#17 UI 정리 H-42).
     * @scenario case=link
     * @effects extension_tab_links_core_screen_with_owner_query
     */
    it('스케줄 관리 링크는 정보 아이콘이 있는 안내 상자 안에 「스케줄 관리에서 변경」으로 놓인다', () => {
        const notice = nodes(tabSchedules).find((n) => n.id === 'ecommerce_schedules_manage_notice');
        expect(notice).toBeDefined();
        expect(notice.props.className).toContain('alert-info');
        expect(nodes(notice).some((n) => n.name === 'Icon' && n.props?.name === 'circle-info')).toBe(true);
        expect(nodes(notice).some((n) => n.name === 'P' && String(n.text).includes('schedules.manage_notice'))).toBe(true);
        const link = nodes(notice).find((n) => n.id === 'ecommerce_schedules_manage_link');
        expect(link).toBeDefined();
        expect(link.text).toBe('$t:sirsoft-ecommerce.admin.settings.schedules.manage_link');
        expect((ko as any).schedules.manage_link).toBe('스케줄 관리에서 변경');
    });

    /**
     * 수정 권한이 없으면 스위치가 잠긴다 — 왜 잠겼는지 탭 머리에 읽기 전용 안내를 띄운다(본인인증 정책 탭과 같은 모양, H-41).
     * @scenario case=permission
     * @effects extension_tab_forbidden_notice
     */
    it('목록 응답이 수정 불가(can_update=false)면 자물쇠 아이콘의 읽기 전용 안내를 보이고, 응답 전·수정 가능이면 숨긴다', () => {
        const notice = nodes(tabSchedules).find((n) => n.id === 'ecommerce_schedules_read_only_notice');
        expect(notice).toBeDefined();
        expect(notice.props.className).toContain('alert-warning');
        expect(nodes(notice).some((n) => n.name === 'Icon' && n.props?.name === 'lock')).toBe(true);
        expect(JSON.stringify(notice)).toContain('$t:sirsoft-ecommerce.admin.settings.schedules.read_only_notice');
        const at = (abilities: any) => evalExpr(notice.if, { ecommerceSchedules: abilities === undefined ? undefined : { data: { data: [], abilities } } });
        expect(at({ can_update: false })).toBe(true);
        expect(at({ can_update: true })).toBe(false);
        expect(at(undefined)).toBe(false);
        expect(typeof (ko as any).schedules.read_only_notice).toBe('string');
        expect(typeof (en as any).schedules.read_only_notice).toBe('string');
    });

    /**
     * 카드 머리는 다른 설정 탭과 같다 — 제목 + `card-description` 설명 div, 탭 이름과 카드 제목은 같은 말(H-41).
     * @scenario case=i18n
     * @effects extension_tab_i18n_keys
     */
    it('카드 제목은 탭 이름과 같은 말이고 설명은 card-description div 다', () => {
        const card = nodes(tabSchedules).find((n) => n.id === 'ecommerce_schedules_card');
        const desc = nodes(card).find((n) => n.props?.className === 'card-description');
        expect(desc?.name).toBe('Div');
        expect(desc.text).toBe('$t:sirsoft-ecommerce.admin.settings.schedules.description');
        expect((ko as any).schedules.title).toBe((ko as any).tabs.schedules);
        expect((en as any).schedules.title).toBe((en as any).tabs.schedules);
    });

    /**
     * 용어는 스케줄 관리 화면과 같다(#17 UI 정리 용어 계약 — 미실행·최근 실행 결과·다음 실행·상태, 「~ 변경되었습니다」).
     * @scenario case=i18n
     * @effects extension_tab_i18n_keys
     */
    it('열 머리글·결과·토스트 문구가 스케줄 관리 화면 용어를 쓴다', () => {
        const s = (ko as any).schedules;
        expect(s.columns).toMatchObject({ name: '작업명', frequency: '실행 주기', next_run: '다음 실행', last_result: '최근 실행 결과', status: '상태' });
        expect(s.result.never).toBe('미실행');
        expect(s.toggle_success).toBe('스케줄 상태가 변경되었습니다.');
        expect(JSON.stringify(s)).not.toMatch(/실행 전|예약 작업/);
        expect((en as any).schedules.columns.status).toBe('Status');
    });

    /**
     * 결과 표지는 공용 배지 컴포넌트로 그린다(둥근 모양·다크 색이 화면마다 같다) — 날 span 에 색 클래스를 적지 않는다(H-11).
     * @scenario case=list
     * @effects extension_tab_reuses_core_list_api
     */
    it('최근 실행 결과와 조건 미충족 표지는 Badge 컴포넌트다', () => {
        const column = nodes(tabSchedules).find((n) => n.field === 'last_result');
        const badges = nodes(column).filter((n) => n.name === 'Badge');
        expect(badges).toHaveLength(4);
        expect(nodes(column).some((n) => n.name === 'Span' && /rounded/.test(String(n.props?.className)))).toBe(false);
        const unmet = nodes(tabSchedules).find((n) => n.name === 'Badge' && String(n.props?.text).includes('condition_unmet'));
        expect(unmet?.props.icon).toBe('triangle-exclamation');
    });

    /**
     * @scenario case=list
     * @effects extension_tab_reuses_core_list_api
     */
    it('탭 줄에 schedules 가 있고 본문이 그 조각을 포함하며, 탭 본문은 그 탭일 때만 그린다', () => {
        const tabNav = nodes(settingsLayout).find((n) => n.id === 'tab_navigation');
        const tab = tabNav.props.tabs.find((t: any) => t.id === 'schedules');
        expect(tab.label).toBe('$t:sirsoft-ecommerce.admin.settings.tabs.schedules');
        expect(JSON.stringify(settingsLayout)).toContain('_tab_schedules');
        const root = nodes(tabSchedules).find((n) => n.id === 'tab_content_schedules');
        expect(evalExpr(root.if, { _global: { activeEcommerceSettingsTab: 'schedules' }, query: {} })).toBe(true);
        expect(evalExpr(root.if, { _global: {}, query: { tab: 'seo' } })).toBe(false);
    });

    /**
     * @scenario case=i18n
     * @effects extension_tab_i18n_keys
     */
    it('조각·탭·데이터소스 라벨이 쓰는 번역 키가 ko·en 양쪽에 있다', () => {
        const text = JSON.stringify(tabSchedules) + JSON.stringify(settingsLayout);
        const keys = [...new Set([...text.matchAll(/\$t:sirsoft-ecommerce\.admin\.settings\.((?:schedules|tabs\.schedules)[A-Za-z0-9_.]*)/g)].map((m) => m[1].replace(/\.$/, '')))];
        expect(keys.length).toBeGreaterThan(10);
        const resolve = (dict: any, key: string) => key.split('.').reduce((o: any, k: string) => (o == null ? undefined : o[k]), dict);
        expect(keys.filter((k) => resolve(ko, k) === undefined || resolve(en, k) === undefined)).toEqual([]);
        expect((koRoot as any).editor.data_source.ecommerceSchedules).toBeTruthy();
        expect((enRoot as any).editor.data_source.ecommerceSchedules).toBeTruthy();
    });
});

describe('스케줄 탭 표 흐림 — 자기 데이터소스만 기다린다 (M1 D8)', () => {
    /**
     * @scenario case=toggle
     * @effects extension_tab_reuses_core_list_api
     */
    it('표의 blur_until_loaded 는 ecommerceSchedules 하나로 한정된다', () => {
        // `true` 는 화면의 모든 데이터소스가 올 때까지 흐린다 — 설정 화면은 탭별 데이터소스가 조건부라
        // 다른 탭 것이 undefined 로 남아 표가 흐림·클릭 불가로 굳었다(토글 불가).
        const grid = nodes(tabSchedules).find((n: any) => n.id === 'ecommerce_schedules_grid');
        expect(grid).toBeDefined();
        expect(grid.blur_until_loaded).not.toBe(true);
        expect(grid.blur_until_loaded?.enabled).toBe(true);
        const sources = [grid.blur_until_loaded?.data_sources].flat();
        expect(sources).toEqual(['ecommerceSchedules']);
        expect(String(grid.props?.data)).toContain('ecommerceSchedules');
    });
});
