/**
 * @file shop-list-context-round-trip.test.ts
 * @description 상품 목록 컨텍스트 왕복 시 URL 목록 상태 보존 회귀 (#75)
 *
 * 결함: 상품 목록(`/shop/products`)에서 상품을 열면 `preserveListQuery` 덕에 page·정렬·
 *   검색어가 상세 URL 까지 따라오지만, 상세 화면의 탭(상세정보/리뷰/문의)을 한 번 누르는
 *   순간 `replaceUrl` 이 쿼리를 `tab=…` 하나로 통째 대체해 전부 사라졌다. 그 뒤 '상품 목록'
 *   버튼은 이미 비워진 쿼리를 병합하므로 1페이지로 되돌아간다.
 *
 * 이 액션은 `params.path` 가 없다 — "현재 주소를 유지한 채 쿼리만 바꾼다" 는 형태라
 * 목적지 경로 문자열이 존재하지 않고, 그래서 경로 기반 검사망을 그대로 통과했다.
 *
 * @scenario leg=enter|tab_switch|back
 * @effects navigation_preserves_list_query
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach } from 'vitest';
import fs from 'fs';
import path from 'path';

import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

const LAYOUTS_ROOT = path.resolve(__dirname, '../../../layouts');

/** 상품 목록 클러스터에 속하는 레이아웃 파일 */
const CLUSTER_FILES = [
    'shop/show.json',
    'shop/index.json',
    'partials/shop/list/_product_grid.json',
    'partials/shop/detail/_purchase_card.json',
];

type NavAction = {
    handler: string;
    comment?: string;
    params: { path?: string; mergeQuery?: unknown; query?: unknown };
};

/**
 * JSON 트리에서 navigate/replaceUrl 액션을 모두 수집합니다.
 *
 * @param node 순회 시작 노드
 * @returns 액션 목록
 */
function collectNavActions(node: unknown): NavAction[] {
    const out: NavAction[] = [];
    const walk = (n: unknown): void => {
        if (Array.isArray(n)) {
            n.forEach(walk);
            return;
        }
        if (!n || typeof n !== 'object') return;
        const obj = n as Record<string, unknown>;
        if (
            (obj.handler === 'navigate' || obj.handler === 'replaceUrl') &&
            obj.params &&
            typeof obj.params === 'object'
        ) {
            out.push(obj as unknown as NavAction);
        }
        Object.values(obj).forEach(walk);
    };
    walk(node);
    return out;
}

/**
 * 레이아웃 파일을 읽어 파싱합니다.
 *
 * @param rel 레이아웃 루트 기준 상대 경로
 * @returns 파싱된 JSON
 */
function readLayout(rel: string): unknown {
    return JSON.parse(fs.readFileSync(path.join(LAYOUTS_ROOT, rel), 'utf8'));
}

/**
 * 실 JSON 액션을 현재 URL 상태에서 실행합니다.
 *
 * @param action 실 레이아웃 JSON 에서 뽑은 navigate/replaceUrl 액션
 * @returns navigate 목적지 경로 (replaceUrl 이면 undefined)
 */
async function runAction(action: NavAction): Promise<string | undefined> {
    const utils = createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'nav_action_probe',
            data_sources: [],
            components: [{ type: 'basic', name: 'Div', props: { 'data-testid': 'probe' } }],
        } as never,
        { componentRegistry: createMockComponentRegistryWithBasics() as never, locale: 'ko' }
    );
    await utils.render();
    await utils.triggerAction({ type: 'click', ...action } as never);
    const dest = utils.getNavigationHistory()[0];
    utils.cleanup();
    return dest;
}

describe('#75 목적지가 자기 자신인 액션도 목록 상태를 유지한다', () => {
    it.each(CLUSTER_FILES)('%s 의 path 없는 navigate/replaceUrl 은 mergeQuery: true', (rel) => {
        const offenders = collectNavActions(readLayout(rel))
            .filter((a) => {
                if (typeof a.comment === 'string' && a.comment.includes('audit:allow')) return false;
                if (typeof a.params.path === 'string' && a.params.path !== '') return false;
                return a.params.mergeQuery !== true;
            })
            .map((a) => `${a.handler} query=${JSON.stringify(a.params.query)}`);

        expect(
            offenders,
            `현재 URL 의 목록 상태를 통째로 덮어쓰는 액션: \n  ${offenders.join('\n  ')}`
        ).toEqual([]);
    });
});

describe('#75 상품 상세 탭 전환이 목록 상태를 지우지 않는다', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    /** 목록에서 상세로 넘어온 직후의 URL — `preserveListQuery` 가 실어 온 상태 */
    const DETAIL_URL = '/shop/products/12?page=2&sort=price_asc&keyword=신발&category=3';

    it('탭을 눌러도 page·정렬·검색어·분류가 URL 에 남는다', async () => {
        window.history.replaceState({}, '', DETAIL_URL);

        const action = collectNavActions(readLayout('shop/show.json')).find(
            (a) =>
                a.handler === 'replaceUrl' &&
                a.params.query !== null &&
                typeof a.params.query === 'object' &&
                'tab' in (a.params.query as Record<string, unknown>)
        );
        expect(action, '상세 탭 전환 replaceUrl 을 찾지 못했습니다.').toBeTruthy();

        await runAction(action as NavAction);
        const q = new URLSearchParams(window.location.search);

        expect(q.get('page')).toBe('2');
        expect(q.get('sort')).toBe('price_asc');
        expect(q.get('keyword')).toBe('신발');
        expect(q.get('category')).toBe('3');
        expect(q.get('tab')).toBeTruthy();
    });

    it("탭을 누른 뒤 '상품 목록' 으로 돌아가도 보던 페이지가 복원된다", async () => {
        window.history.replaceState({}, '', DETAIL_URL);

        const tabAction = collectNavActions(readLayout('shop/show.json')).find(
            (a) =>
                a.handler === 'replaceUrl' &&
                a.params.query !== null &&
                typeof a.params.query === 'object' &&
                'tab' in (a.params.query as Record<string, unknown>)
        );
        await runAction(tabAction as NavAction);

        const backAction = collectNavActions(readLayout('shop/show.json')).find((a) =>
            String(a.params.path ?? '').endsWith('/products')
        );
        expect(backAction, "상세의 '상품 목록' navigate 를 찾지 못했습니다.").toBeTruthy();

        const dest = (await runAction(backAction as NavAction)) as string;
        const q = new URLSearchParams(dest.includes('?') ? dest.slice(dest.indexOf('?')) : '');

        expect(q.get('page')).toBe('2');
        expect(q.get('sort')).toBe('price_asc');
        expect(q.get('keyword')).toBe('신발');
        expect(q.get('category')).toBe('3');
    });
});
