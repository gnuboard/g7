// e2e:allow 실제 관리자 템플릿 Select 를 엔진 렌더러로 그려 툴바 칸이 목록형(단추 + 목록 상자)이고 고른 값이 폼에 담기는지 잰다 — 브라우저 축은 출시 전 화면 재실측(환경설정 › CKEditor 5 › 툴바)이 덮는다
/**
 * @file pluginSettingsSelectDropdownMode.test.tsx
 * @description CKEditor 5 환경설정의 「툴바」 칸이 브라우저 기본 선택상자가 아니라 관리자 템플릿의 목록형 드롭다운으로 그려진다
 *
 * 관리자 템플릿 Select 는 `props.options` 가 있으면 단추(`aria-haspopup=listbox`) + 목록 상자로, 없으면 브라우저 기본
 * `<select>` 로 그린다. 툴바 칸은 `<select>` 바로 아래 `<option>` 을 적어 기본 선택상자였다(같은 화면의 「공개 파일 저장소」
 * 칸은 이미 목록형). 실물 Select 를 엔진 렌더러로 그려 목록형인지, 고른 값이 폼 자동 바인딩으로 `form.toolbar` 에 담기는지,
 * 읽기 전용이면 잠기는지를 본다.
 *
 * @scenario-manifest tests/scenarios/plugin-settings-select-dropdown-mode.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { createLayoutTest, createMockComponentRegistryWithBasics } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { Select } from '../../../../../../../templates/_bundled/sirsoft-admin_basic/src/components/basic/Select';
import settingsLayout from '../../../layouts/admin/plugin_settings.json';

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

const toolbarSelect = find(settingsLayout, (n) => n.name === 'Select' && n.props?.name === 'toolbar');

let utils: ReturnType<typeof createLayoutTest> | null = null;

afterEach(() => {
    utils?.cleanup();
    utils = null;
    document.body.innerHTML = '';
});

/**
 * 툴바 칸을 폼(`data-key="form"`) 안에 실물 Select 로 그린다.
 *
 * @param form 폼 값
 * @param readOnly 읽기 전용 여부
 * @returns 목록형 단추
 */
async function drawToolbar(form: Record<string, any>, readOnly = false): Promise<HTMLButtonElement> {
    const registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Select', Select as any);
    utils = createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'test/ckeditor5-settings-toolbar',
            components: [{ id: 'settings_form', type: 'basic', name: 'Div', dataKey: 'form', children: [toolbarSelect] }],
        } as any,
        { componentRegistry: registry as any, locale: 'ko', initialState: { _local: { form } }, initialData: { _computed: { isPluginReadOnly: readOnly } } },
    );
    const { container } = await utils.render();
    expect(container.querySelector('select'), '브라우저 기본 선택상자').toBeNull();
    const triggers = container.querySelectorAll<HTMLButtonElement>('button[aria-haspopup="listbox"]');
    expect(triggers).toHaveLength(1);

    return triggers[0];
}

describe('CKEditor 5 환경설정 — 툴바 칸 목록형 드롭다운', () => {
    // @scenario screen=ckeditor5_toolbar
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action
    it('툴바 칸은 목록형이고 저장된 값을 단추에 보이며, 고르면 form.toolbar 에 담긴다', async () => {
        expect(toolbarSelect, '툴바 Select').toBeTruthy();
        expect(Array.isArray(toolbarSelect!.props?.options), 'props.options').toBe(true);
        expect(toolbarSelect!.props.options.map((o: Node) => o.value)).toEqual(['minimal', 'standard', 'full']);

        const trigger = await drawToolbar({ toolbar: 'standard' });
        expect(trigger.textContent).toContain('toolbar.standard');

        fireEvent.click(trigger);
        const full = await waitFor(() => {
            const hit = screen.getAllByRole('option').find((o) => /toolbar\.full$/.test(o.textContent ?? ''));
            expect(hit).toBeTruthy();

            return hit!;
        });
        fireEvent.click(full);
        await waitFor(() => expect(trigger.textContent).toContain('toolbar.full'));
        // 자동 바인딩은 운영에서 G7Core.state.setLocal({ 'form.toolbar': 값 }) 으로 쓴다 — 시험 도구는 점 경로 키를 그대로 담는다
        const local = utils!.getState()._local;
        expect(local['form.toolbar'] ?? local.form?.toolbar).toBe('full');
    });

    // @scenario screen=ckeditor5_toolbar
    // @effects select_renders_listbox_dropdown
    it('읽기 전용(설정 수정 권한 없음)이면 툴바 칸 단추가 잠긴다', async () => {
        const trigger = await drawToolbar({ toolbar: 'minimal' }, true);
        expect(trigger.disabled).toBe(true);
    });
});
