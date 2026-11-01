// e2e:allow 실제 관리자 템플릿 Select 를 엔진 렌더러로 그려 메뉴 편집 패널의 상위 메뉴·사용자 정의 메뉴 칸이 목록형(단추 + 목록 상자)인지, 고른 값이 폼에 닿는지 잰다 — 브라우저 축은 출시 전 화면 재실측(메뉴 관리 › 새 메뉴)이 덮는다
/**
 * @file admin-menu-form-select-dropdown.test.tsx
 * @description 관리자 메뉴 관리 편집 패널의 「상위 메뉴」·「사용자 정의 메뉴」 칸이 브라우저 기본 선택상자가 아니라 목록형 드롭다운으로 그려진다
 *
 * Select 는 `props.options` 가 있으면 단추(`aria-haspopup=listbox`) + 목록 상자로, 없으면 브라우저 기본 `<select>` 로 그린다.
 * 두 칸은 반복(`data-each`)으로 만든 `<option>`·`<optgroup>` 자식이라 기본 선택상자였다. 사용자 정의 메뉴는 묶음(모듈 이름)을
 * `<optgroup>` 으로, 함께 채울 주소를 `<option data-url>` 로 실었고 change 동작이 `$event.target.selectedOptions[0].dataset.url`
 * 을 읽었다 — 목록형에는 그 자리가 없어, 모듈 이름은 항목 설명(`description`)으로, 주소는 고른 키로 응답에서 찾는다.
 *
 * 엔진 렌더러(createLayoutTest)는 저장소 루트의 React 를, 이 템플릿의 부품은 템플릿 폴더의 React 사본을 써서 그대로는 훅이
 * 돌지 않는다. 이 파일에서만 템플릿의 react·react-dom 을 루트 사본으로 돌려 실물 Select 를 엔진으로 그린다(판은 같다 — 19.2.0).
 *
 * @scenario-manifest tests/scenarios/admin-menu-form-select-dropdown.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('react', async () => await vi.importActual<any>('../../../../../node_modules/react/index.js'));
vi.mock('react/jsx-runtime', async () => await vi.importActual<any>('../../../../../node_modules/react/jsx-runtime.js'));
vi.mock('react/jsx-dev-runtime', async () => await vi.importActual<any>('../../../../../node_modules/react/jsx-dev-runtime.js'));
vi.mock('react-dom', async () => await vi.importActual<any>('../../../../../node_modules/react-dom/index.js'));

import { fireEvent, screen, waitFor } from '@testing-library/react';
import { createLayoutTest, createMockComponentRegistryWithBasics } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { Select } from '../../src/components/basic/Select';
import panelForm from '../../layouts/partials/admin_menu_list/_panel_form.json';

type Node = Record<string, any>;

/**
 * 조건에 맞는 첫 노드를 찾는다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @returns 찾은 노드(없으면 undefined)
 */
function find(node: unknown, match: (n: Node) => boolean): Node | undefined {
    if (Array.isArray(node)) {
        for (const child of node) {
            const hit = find(child, match);
            if (hit) return hit;
        }

        return undefined;
    }
    if (!node || typeof node !== 'object') return undefined;
    if (match(node as Node)) return node as Node;
    for (const value of Object.values(node as Node)) {
        const hit = find(value, match);
        if (hit) return hit;
    }

    return undefined;
}

const parentSelect = find(panelForm, (n) => n.id === 'form_parent_select');
const customMenuSelect = find(panelForm, (n) => n.id === 'form_custom_menu_select');

const MENUS = { data: { data: [{ id: 1, name: { ko: '대시보드' } }, { id: 2, name: { ko: '쇼핑' } }] } };
const MODULES = {
    data: {
        custom_menus: {
            'sirsoft-ecommerce': { module_name: '이커머스', menus: [{ key: 'ecommerce.shop', name: '쇼핑몰', url: '/shop' }, { key: 'ecommerce.nourl', name: '주소 없음' }] },
            'sirsoft-board': { module_name: '게시판', menus: [] },
        },
    },
};

let utils: ReturnType<typeof createLayoutTest> | null = null;

afterEach(() => {
    utils?.cleanup();
    utils = null;
    document.body.innerHTML = '';
});

/**
 * 칸을 실물 Select 로 그린다.
 *
 * @param select Select 노드
 * @param global 전역 상태
 * @returns 목록형 단추
 */
async function draw(select: Node, global: Record<string, any>): Promise<HTMLButtonElement> {
    const registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Select', Select as any);
    utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test/admin-menu-form-select', components: [select] } as any,
        { componentRegistry: registry as any, locale: 'ko', initialState: { _global: global }, initialData: { menus: MENUS, modules: MODULES } },
    );
    const { container } = await utils.render();
    expect(container.querySelector('select'), '브라우저 기본 선택상자').toBeNull();
    const triggers = container.querySelectorAll<HTMLButtonElement>('button[aria-haspopup="listbox"]');
    expect(triggers).toHaveLength(1);

    return triggers[0];
}

/** 열린 목록의 항목 글자들 */
const optionTexts = (): string[] => screen.getAllByRole('option').map((o) => (o.textContent ?? '').trim());

describe('관리자 메뉴 편집 패널 — 선택칸 목록형 드롭다운', () => {
    // @scenario screen=menu_parent
    // @effects select_renders_listbox_dropdown, menu_parent_options_exclude_self
    it('상위 메뉴 칸은 목록형이고, 수정 중에는 자기 자신을 상위 메뉴 후보에서 뺀다', async () => {
        expect(parentSelect, '상위 메뉴 Select').toBeTruthy();
        expect(parentSelect!.props?.options, 'props.options').toBeDefined();

        const trigger = await draw(parentSelect!, { panelMode: 'edit', selectedMenuId: 2, formData: { parent_id: null } });
        fireEvent.click(trigger);
        await waitFor(() => expect(optionTexts().length).toBeGreaterThan(0));
        expect(optionTexts()).toEqual(['admin.menus.form.parent_top_level', '대시보드']);
    });

    // @scenario screen=menu_custom_menu
    // @effects select_renders_listbox_dropdown, custom_menu_option_shows_module_and_fills_url
    it('사용자 정의 메뉴 칸은 목록형이고, 항목 아래 모듈 이름이 보이며 고르면 키와 주소가 폼에 담긴다', async () => {
        expect(customMenuSelect, '사용자 정의 메뉴 Select').toBeTruthy();
        expect(customMenuSelect!.props?.options, 'props.options').toBeDefined();

        const trigger = await draw(customMenuSelect!, { panelMode: 'create', formData: { custom_menu_key: null, url: '/typed', slug: 'kept' } });
        fireEvent.click(trigger);
        const shop = await waitFor(() => {
            const hit = screen.getAllByRole('option').find((o) => (o.textContent ?? '').startsWith('쇼핑몰'));
            expect(hit).toBeTruthy();

            return hit!;
        });
        // 묶음(모듈 이름)은 항목 설명으로 보인다 — 메뉴가 없는 모듈(게시판)은 항목을 내지 않는다
        expect(shop.textContent).toContain('이커머스');
        expect(optionTexts().some((t) => t.includes('게시판'))).toBe(false);

        fireEvent.click(shop);
        await waitFor(() => expect(utils!.getState()._global.formData.custom_menu_key).toBe('ecommerce.shop'));
        expect(utils!.getState()._global.formData.url).toBe('/shop');
        expect(utils!.getState()._global.formData.slug).toBe('kept');
    });

    // @scenario screen=menu_custom_menu
    // @effects custom_menu_option_shows_module_and_fills_url
    it('주소가 없는 항목·「선택 안 함」 을 고르면 적어 둔 주소를 그대로 둔다', async () => {
        const trigger = await draw(customMenuSelect!, { panelMode: 'create', formData: { custom_menu_key: null, url: '/typed' } });
        fireEvent.click(trigger);
        const noUrl = await waitFor(() => {
            const hit = screen.getAllByRole('option').find((o) => (o.textContent ?? '').startsWith('주소 없음'));
            expect(hit).toBeTruthy();

            return hit!;
        });
        fireEvent.click(noUrl);
        await waitFor(() => expect(utils!.getState()._global.formData.custom_menu_key).toBe('ecommerce.nourl'));
        expect(utils!.getState()._global.formData.url).toBe('/typed');

        fireEvent.click(trigger);
        const none = await waitFor(() => {
            const hit = screen.getAllByRole('option').find((o) => (o.textContent ?? '').trim() === 'admin.menus.form.custom_menu_none');
            expect(hit).toBeTruthy();

            return hit!;
        });
        fireEvent.click(none);
        await waitFor(() => expect(utils!.getState()._global.formData.custom_menu_key).toBeNull());
        expect(utils!.getState()._global.formData.url).toBe('/typed');
    });
});
