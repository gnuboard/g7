// e2e:allow 화면 전환 이어 쓰기 판정(순수 함수)의 입력 모양 전수 — 실물 경로 전환은 TemplateApp.routeDataCarryOver.test.tsx, 브라우저 축은 7.1.1 출시 전 캠페인 재실측(목록 ↔ 목록 사이드바 이동·같은 목록 검색)이 덮는다
/**
 * @file dataContextCarryOver.test.ts
 * @description 화면 전환 때 앞 화면 데이터소스 값을 이어 쓸지 가리는 판정의 입력 모양 전수 (engine-v1.81.2)
 *
 * 판정이 받는 모양: 앞 화면 없음 · 같은 화면(경로·레이아웃 이름) · 다른 화면에서 정의 다름 / 같음(의존 없음) /
 * `route` 의존(파라미터 같음·다름) / `query` 의존(쿼리 같음·다름) / `_local` 의존 · 키 순서만 다른 같은 정의 ·
 * 앞 화면에 없는 id · 앞 화면 값이 `undefined`.
 */

import { describe, it, expect } from 'vitest';
import {
    buildProgressiveDataInit,
    isSameDataRequest,
    isSameScreen,
    type RenderedScreen,
} from '../dataContextCarryOver';

const menu = { id: 'menu', type: 'api', endpoint: '/api/admin/menus', loading_strategy: 'progressive' };
const listA = { id: 'items', endpoint: '/api/a/items/active', loading_strategy: 'background' };
const listB = { id: 'items', endpoint: '/api/b/items', loading_strategy: 'progressive' };
const settings = { id: 'settings', endpoint: '/api/admin/plugins/{{route.identifier}}/settings' };
const trend = { id: 'trend', endpoint: '/api/trend', params: { period: '{{query.period}}' } };
const detail = { id: 'detail', endpoint: '/api/detail/{{_local.selectedId}}', auto_fetch: false };

function screen(over: Partial<RenderedScreen> = {}): RenderedScreen {
    return {
        path: '/admin/a',
        layoutName: 'list_a',
        routeParams: {},
        query: {},
        sources: [menu, listA, settings, trend, detail],
        ...over,
    };
}

describe('[case:state-global-route-change-carries-other-screen-datasource] 화면 전환 이어 쓰기 판정 — 입력 모양 전수 (engine-v1.81.2)', () => {
    // @scenario case=decide_same_screen_identity
    // @effects same_screen_requires_path_and_layout
    it('같은 화면은 경로와 레이아웃 이름이 모두 같을 때뿐이다', () => {
        expect(isSameScreen(null, '/admin/a', 'list_a')).toBe(false);
        expect(isSameScreen(screen(), '/admin/a', 'list_a')).toBe(true);
        expect(isSameScreen(screen(), '/admin/b', 'list_a')).toBe(false);
        expect(isSameScreen(screen(), '/admin/a', 'list_b')).toBe(false);
    });

    // @scenario case=decide_no_previous_source
    // @effects missing_previous_source_not_carried
    it('앞 화면이 없거나 그 id 를 선언하지 않았으면 같은 요청이 아니다', () => {
        expect(isSameDataRequest(null, menu, {}, {})).toBe(false);
        expect(isSameDataRequest(screen({ sources: [listA] }), menu, {}, {})).toBe(false);
    });

    // @scenario case=decide_different_definition
    // @effects other_screen_same_id_not_carried
    it('정의가 다르면(같은 id 다른 목록) 같은 요청이 아니다', () => {
        expect(isSameDataRequest(screen(), listB, {}, {})).toBe(false);
    });

    // @scenario case=decide_same_definition_no_dependency
    // @effects base_source_carried
    it('정의가 같고 의존이 없으면 같은 요청이다 — 키 순서만 달라도 같다', () => {
        expect(isSameDataRequest(screen(), menu, { id: '9' }, { page: '3' })).toBe(true);
        const reordered = { loading_strategy: 'progressive', endpoint: '/api/admin/menus', type: 'api', id: 'menu' };
        expect(isSameDataRequest(screen(), reordered, {}, {})).toBe(true);
    });

    // @scenario case=decide_route_dependency
    // @effects plugin_settings_a_to_b_not_carried
    it('route 를 읽는 정의는 경로 파라미터가 같을 때만 같은 요청이다(플러그인 A → B 설정)', () => {
        const prev = screen({ routeParams: { identifier: 'vendor-a' } });
        expect(isSameDataRequest(prev, settings, { identifier: 'vendor-a' }, {})).toBe(true);
        expect(isSameDataRequest(prev, settings, { identifier: 'vendor-b' }, {})).toBe(false);
    });

    // @scenario case=decide_query_dependency
    // @effects query_dependent_source_carried_only_on_equal_query
    it('query 를 읽는 정의는 쿼리가 같을 때만 같은 요청이다 — 쿼리 키 순서는 보지 않는다', () => {
        const prev = screen({ query: { period: '7d', tab: 'x' } });
        expect(isSameDataRequest(prev, trend, {}, { tab: 'x', period: '7d' })).toBe(true);
        expect(isSameDataRequest(prev, trend, {}, { period: '30d', tab: 'x' })).toBe(false);
    });

    // @scenario case=decide_local_dependency
    // @effects local_dependent_source_not_carried
    it('_local 을 읽는 정의는 다른 화면으로 이어 쓰지 않는다', () => {
        expect(isSameDataRequest(screen(), detail, {}, {})).toBe(false);
    });

    // @scenario case=build_init_other_screen
    // @effects progressive_init_keys_every_source, base_source_carried, other_screen_same_id_not_carried
    it('초기값은 모든 id 의 키를 만들고, 이어 쓰지 않는 id 와 앞 값이 없는 id 는 undefined 다', () => {
        const prevContext = { menu: { data: ['m'] }, items: { data: [{ value: 1, label: 'x' }] }, settings: { data: { a: 1 } } };
        const { init, sameScreen } = buildProgressiveDataInit({
            previous: screen({ routeParams: { identifier: 'vendor-a' } }),
            previousContext: prevContext,
            sources: [menu, listB, settings, { id: 'fresh', endpoint: '/api/fresh' }],
            path: '/admin/plugins/vendor-b/settings',
            layoutName: 'plugin_settings',
            routeParams: { identifier: 'vendor-b' },
            query: {},
        });
        expect(sameScreen).toBe(false);
        expect(Object.keys(init).sort()).toEqual(['fresh', 'items', 'menu', 'settings']);
        expect(init.menu).toEqual({ data: ['m'] });
        expect(init.items).toBeUndefined();
        expect(init.settings).toBeUndefined();
        expect(init.fresh).toBeUndefined();
    });

    // @scenario case=build_init_same_screen
    // @effects same_screen_query_change_carried
    it('같은 화면(쿼리만 다름)이면 정의·의존과 무관하게 앞 값을 모두 이어 쓴다', () => {
        const prevContext = { items: { data: { data: [{ id: 1 }] } }, trend: { data: [1] } };
        const { init, sameScreen } = buildProgressiveDataInit({
            previous: screen({ path: '/admin/b', layoutName: 'list_b', query: { page: '1' }, sources: [listB, trend] }),
            previousContext: prevContext,
            sources: [listB, trend],
            path: '/admin/b',
            layoutName: 'list_b',
            routeParams: {},
            query: { page: '2' },
        });
        expect(sameScreen).toBe(true);
        expect(init.items).toEqual({ data: { data: [{ id: 1 }] } });
        expect(init.trend).toEqual({ data: [1] });
    });
});
