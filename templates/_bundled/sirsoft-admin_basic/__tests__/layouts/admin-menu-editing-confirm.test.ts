// e2e:allow 메뉴 관리 목록 선택·이탈 확인 창 단추의 실제 액션을 실물 ActionDispatcher 로 실행해 선택·대기 자리·주소를 잰다 — 브라우저 축은 7.1.1 출시 전 재실측이 덮는다
/**
 * @file admin-menu-editing-confirm.test.ts
 * @description 메뉴 관리 — 편집·등록 중 다른 메뉴를 고르면 곧바로 넘어가지 않고 이탈 확인 창을 연다 (7.1.1 출시 전)
 *
 * 메뉴 관리는 왼쪽 목록·오른쪽 편집 판 화면인데, 편집 중에 목록의 다른 메뉴를 누르면 확인 없이 보기 판으로 바뀌어
 * 고치던 값이 사라졌다(이커머스 브랜드·분류 목록이 이미 지키는 규칙이 이 화면에만 없었다). 굽기 산출(JSON)의
 * 목록 선택(onSelect)과 새 확인 창 단추를 엔진으로 실행해
 *   ① 편집·등록 중 선택 → 대기 자리(_global.pendingMenu*)에 두고 확인 창을 열며 선택은 그대로
 *   ② 편집 중 아님 → 바로 선택
 *   ③ 「변경사항 버리기」 → 대기 메뉴를 선택으로 옮기고 보기 판·「고친 것 없음」·주소 menu/mode, 대기 비움
 *   ④ 「계속 편집하기」 → 대기만 비우고 편집 판 유지
 *   ⑤ 화면에 확인 창 등록·문구 키 ko·en·ja
 * 를 잰다.
 *
 * @scenario-manifest tests/scenarios/admin-menu-editing-confirm.yaml
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

const LAYOUTS = path.resolve(__dirname, '../../layouts');
const LANG = path.resolve(__dirname, '../../lang/partial');
const JA = path.resolve(__dirname, '../../../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json');

type Node = Record<string, any>;

function read(rel: string): Node {
    return JSON.parse(fs.readFileSync(path.join(LAYOUTS, rel), 'utf8'));
}

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

/** 컴포넌트 콜백 인자(`$args[0]`)를 전역 탐침 값으로 바꿔 끼운다 — 시험 도구의 triggerAction 은 이벤트만 넘긴다 */
function withProbeArg(action: Node): Node {
    return JSON.parse(JSON.stringify(action).split('$args[0]').join('_global.__probeArg'));
}

const PICKED = { id: 2, slug: 'second-menu', name: { ko: '둘째 메뉴' } };

function setup(global: Record<string, any>) {
    const utils = createLayoutTest({
        version: '1.0.0',
        layout_name: 'test/admin-menu-editing-confirm',
        data_sources: [],
        components: [{ type: 'basic', name: 'Div', props: { 'data-testid': 'probe' } }],
    } as never, {
        componentRegistry: createMockComponentRegistryWithBasics() as never,
        locale: 'ko',
        initialState: { _global: { ...global }, _local: {} },
    } as never);

    return utils;
}

describe('메뉴 관리 — 편집 중 다른 메뉴 선택 시 이탈 확인', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    // @scenario surface=menu_list
    // @effects editing_selection_opens_leave_confirm
    it('편집·등록 중이면 대기 자리에 두고 확인 창을 열며, 아니면 바로 고른다', async () => {
        const list = read('partials/admin_menu_list/_panel_menu_list.json');
        const onSelect = findAll(list, (n) => n.event === 'onSelect')[0];
        expect(onSelect, '목록 onSelect').toBeTruthy();
        // 확인 창을 여는 것은 편집 중 갈래뿐이다(시험 도구의 모달 스택은 엔진 내장 openModal 을 거치지 않아 구조로 잰다)
        const branch = findAll(onSelect, (n) => n.handler === 'switch')[0];
        expect(JSON.stringify(branch?.cases?.true)).toContain('"target":"menu_editing_confirm_modal"');
        expect(JSON.stringify(branch?.cases?.false)).not.toContain('menu_editing_confirm_modal');

        for (const mode of ['edit', 'create']) {
            const utils = setup({ panelMode: mode, selectedMenuId: 1, __probeArg: PICKED });
            await utils.render();
            (window as any).G7Core.state.get = () => ({ ...utils.getState()._global });
            await utils.triggerAction(withProbeArg(onSelect) as never);
            const g = utils.getState()._global;
            expect(g.pendingMenuId, `${mode}: 대기 자리`).toBe(2);
            expect(g.selectedMenuId, `${mode}: 선택은 그대로`).toBe(1);
            expect(g.panelMode).toBe(mode);
            utils.cleanup();
        }

        const utils = setup({ panelMode: 'view', selectedMenuId: 1, __probeArg: PICKED });
        utils.mockApi('menus', { response: PICKED });
        await utils.render();
        (window as any).G7Core.state.get = () => ({ ...utils.getState()._global });
        await utils.triggerAction(withProbeArg(onSelect) as never);
        const g = utils.getState()._global;
        expect(g.selectedMenuId, '보기 중이면 바로 선택').toBe(2);
        expect(g.pendingMenuId ?? null).toBeNull();
        utils.cleanup();
    });

    // @scenario surface=menu_list
    // @effects leave_confirm_moves_pending_selection
    it('「변경사항 버리기」 는 대기 메뉴로 옮기고 주소·변경 표시를 맞춘다, 「계속 편집하기」 는 대기만 비운다', async () => {
        const modal = read('partials/admin_menu_list/_modal_editing_confirm.json');
        const discard = findAll(modal, (n) => n.id === 'menu_editing_discard_button')[0];
        const keep = findAll(modal, (n) => n.id === 'menu_editing_continue_button')[0];
        expect(discard && keep).toBeTruthy();

        window.history.replaceState({}, '', '/admin/menus?menu=first-menu&mode=edit&search=kw');
        let utils = setup({ panelMode: 'edit', selectedMenuId: 1, hasChanges: true, pendingMenuId: 2, pendingMenu: PICKED });
        utils.mockApi('menus', { response: PICKED });
        await utils.render();
        (window as any).G7Core.state.get = () => ({ ...utils.getState()._global });
        await utils.triggerAction((discard.actions as Node[]).find((a) => a.type === 'click') as never);
        let g = utils.getState()._global;
        expect(g.selectedMenuId).toBe(2);
        expect(g.panelMode).toBe('view');
        expect(g.hasChanges).toBe(false);
        expect(g.pendingMenuId ?? null).toBeNull();
        const q = new URLSearchParams(window.location.search);
        expect(q.get('menu')).toBe('second-menu');
        expect(q.get('mode')).toBe('view');
        expect(q.get('search'), '목록 조건은 남는다').toBe('kw');
        utils.cleanup();

        utils = setup({ panelMode: 'edit', selectedMenuId: 1, hasChanges: true, pendingMenuId: 2, pendingMenu: PICKED });
        await utils.render();
        (window as any).G7Core.state.get = () => ({ ...utils.getState()._global });
        await utils.triggerAction((keep.actions as Node[]).find((a) => a.type === 'click') as never);
        g = utils.getState()._global;
        expect(g.pendingMenuId ?? null).toBeNull();
        expect(g.panelMode, '편집 판 유지').toBe('edit');
        expect(g.selectedMenuId).toBe(1);
        utils.cleanup();
    });

    // @scenario surface=menu_list
    // @effects menu_screen_registers_leave_confirm
    it('화면에 확인 창이 등록되고 문구 키가 ko·en·ja 에 있다', () => {
        const screen = read('admin_menu_list.json');
        expect(JSON.stringify(screen.modals ?? [])).toContain('_modal_editing_confirm');
        const keys = ['editing_title', 'editing_message', 'editing_confirm_message', 'editing_discard', 'editing_continue'];
        for (const file of [path.join(LANG, 'ko/admin.json'), path.join(LANG, 'en/admin.json'), JA]) {
            const modals = JSON.parse(fs.readFileSync(file, 'utf8')).menus.modals;
            for (const key of keys) expect(typeof modals[key], `${path.basename(path.dirname(file))} ${key}`).toBe('string');
        }
    });
});
