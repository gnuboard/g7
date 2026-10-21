// e2e:allow 이탈 확인 창 「취소하고 이동」 단추의 실제 액션을 실물 ActionDispatcher 로 실행해 선택·주소를 잰다 — 브라우저 축은 2026-10-18 재실측(새로고침 뒤 고른 항목이 열리는지)이 덮는다
/**
 * @file adminEditingConfirmMoveRun.test.ts
 * @description 목록-편집 패널 화면의 이탈 확인 창 — 「취소하고 이동」 뒤 주소도 고른 항목을 가리킨다 (7.1.1 출시 전 계열)
 *
 * 공통정보 창은 대기 항목을 선택으로 옮기기만 하고 주소(`?id=`)를 바꾸지 않아, 새로고침하면 이전 항목이 열렸다
 * (2026-10-18 실측). 같은 계열의 상품정보고시·브랜드·분류 창까지 네 창 모두, 단추의 실제 액션을 엔진에 태워
 * ① 전역 선택이 대기 항목으로 옮겨지고 ② 주소의 `id` 가 그 항목·`mode=view` 이며 ③ 목록에 걸어 둔 검색어는 남는지 본다.
 * 정적 JSON 단언만으로는 실행 효과를 못 잡은 전례가 있어(통화 추가 줄, 2026-10-18) 실행으로 잠근다.
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

const PARTIALS = path.resolve(__dirname, '../../../layouts/admin/partials');

type Node = Record<string, any>;

/** 조건에 맞는 노드를 모두 모은다. */
function findAll(node: unknown, match: (n: Node) => boolean, acc: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((child) => findAll(child, match, acc));

        return acc;
    }
    if (!node || typeof node !== 'object') return acc;
    if (match(node as Node)) acc.push(node as Node);
    Object.values(node as Node).forEach((value) => findAll(value, match, acc));

    return acc;
}

const CASES: Array<{ label: string; file: string; route: string; pendingId: string; pendingItem: string; selectedId: string }> = [
    { label: '공통정보', file: 'admin_ecommerce_product_common_info_index/_modal_editing_confirm.json', route: '/admin/ecommerce/common-info', pendingId: 'pendingItemId', pendingItem: 'pendingItem', selectedId: 'selectedId' },
    { label: '상품정보고시', file: 'admin_ecommerce_product_notice_index/_modal_editing_confirm.json', route: '/admin/ecommerce/product-notices', pendingId: 'pendingTemplateId', pendingItem: 'pendingTemplate', selectedId: 'selectedTemplateId' },
    { label: '브랜드', file: 'admin_ecommerce_brand_index/_modal_editing_confirm.json', route: '/admin/ecommerce/brands', pendingId: 'pendingBrandId', pendingItem: 'pendingBrand', selectedId: 'selectedBrandId' },
    { label: '분류', file: 'admin_ecommerce_category_index/_modal_editing_confirm.json', route: '/admin/ecommerce/categories', pendingId: 'pendingCategoryId', pendingItem: 'pendingCategory', selectedId: 'selectedCategoryId' },
];

describe('이탈 확인 창 「취소하고 이동」 실행 — 선택과 주소가 고른 항목으로', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    // @scenario surface=common_info_list
    // @effects leave_confirm_updates_url_to_pending_item
    // @scenario surface=notice_list
    // @effects leave_confirm_updates_url_to_pending_item
    // @scenario surface=brand_list
    // @effects leave_confirm_updates_url_to_pending_item
    // @scenario surface=category_list
    // @effects leave_confirm_updates_url_to_pending_item
    it.each(CASES)('$label 창', async ({ file, route, pendingId, pendingItem, selectedId }) => {
        const modal = JSON.parse(fs.readFileSync(path.join(PARTIALS, file), 'utf8'));
        const moveButton = findAll(modal, (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes(pendingId))[0];
        expect(moveButton, '「취소하고 이동」 단추').toBeTruthy();

        window.history.replaceState({}, '', `${route}?id=1&mode=edit&search=kw`);

        const global = { panelMode: 'edit', [selectedId]: 1, [pendingId]: 2, [pendingItem]: { id: 2, name: { ko: '둘째' } } };
        const utils = createLayoutTest({
            version: '1.0.0',
            layout_name: 'test/editing-confirm-move',
            data_sources: [],
            components: [{ type: 'basic', name: 'Div', props: { 'data-testid': 'probe' } }],
        } as never, {
            componentRegistry: createMockComponentRegistryWithBasics() as never,
            locale: 'ko',
            initialState: { _global: { ...global }, _local: { form: { name: { ko: '고치던 값' } } } },
        } as never);
        await utils.render();
        (window as any).G7Core.state.get = () => ({ ...utils.getState()._global });

        const click = (moveButton.actions as Node[]).find((a) => a.type === 'click');
        await utils.triggerAction(click as never);

        const after = utils.getState()._global;
        expect(after[selectedId], '선택이 대기 항목으로').toBe(2);
        expect(after.panelMode).toBe('view');
        expect(after[pendingId] ?? null, '대기 자리는 비운다').toBeNull();

        const q = new URLSearchParams(window.location.search);
        expect(window.location.pathname).toBe(route);
        expect(q.get('id'), '새로고침해도 고른 항목이 열리도록 주소의 id').toBe('2');
        expect(q.get('mode')).toBe('view');
        expect(q.get('search'), '목록에 걸어 둔 검색어는 남는다').toBe('kw');
        utils.cleanup();
    });
});
