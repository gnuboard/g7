// e2e:allow 게시판 설정 폼의 「작성자 표시 이름」 구획을 실물 조각과 실물 관리자 Select 로 그려 방식 전환, 종류 선택 노출, 안내 문구, 저장 본문을 잰다 — 브라우저 축은 #490 화면 실측이 덮는다
/**
 * @file admin-board-form-display-name.test.tsx
 * @description 게시판 설정 폼 「글 설정」 탭 — 작성자 표시 이름 (#490)
 *
 * 실제 조각 JSON(`_tab_post.json`)의 `display_name_field` 를 엔진 렌더러(createLayoutTest)로 그린다.
 * - 방식은 셋(사이트 기본값 따름, 특정 종류 고정, 회원이 고른 값)이고 「고정」일 때만 종류 선택이 보인다
 * - 「따름」이면 지금 적용되는 값을 알려 준다(물려받는 방식이 「회원이 고른 값」이면 그 문구)
 * - 닉네임이 필요한 종류를 고정하면 닉네임 잠금 안내가 보인다
 * - 방식을 「고정」이 아닌 것으로 바꾸면 종류가 null 로 돌아간다
 * - 값은 `form.display_name_setting` 한 객체로 저장 본문(`{{_local.form}}`)에 실리고 「변경됨」 표식이 켜진다
 *
 * @scenario-manifest tests/scenarios/admin-board-form-member-fields.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { Select } from '../../../../../../../templates/_bundled/sirsoft-admin_basic/src/components/basic/Select';

import tabPost from '../../../layouts/admin/partials/admin_board_form/_tab_post.json';
import boardForm from '../../../layouts/admin/admin_board_form.json';
import koForm from '../../../lang/partial/ko/admin/form.json';
import enForm from '../../../lang/partial/en/admin/form.json';

type Node = Record<string, any>;

/**
 * 트리에서 id 가 같은 노드를 찾는다(반응형 분기 안까지 훑는다).
 *
 * @param node 시작 노드
 * @param id 찾을 id
 * @returns 찾은 노드 또는 null
 */
function findNode(node: unknown, id: string): Node | null {
    if (Array.isArray(node)) {
        for (const child of node) {
            const found = findNode(child, id);
            if (found) return found;
        }

        return null;
    }
    if (!node || typeof node !== 'object') return null;
    if ((node as Node).id === id) return node as Node;
    for (const value of Object.values(node as Node)) {
        const found = findNode(value, id);
        if (found) return found;
    }

    return null;
}

const displayNameField = findNode(tabPost, 'display_name_field')!;
const dn = (koForm as any).display_name;

/** 서버가 주는 종류 목록(고정할 수 있는 종류 전부) */
const KINDS = [
    { value: 'name', label: '이름', requires_nickname: false },
    { value: 'nickname', label: '닉네임', requires_nickname: true },
    { value: 'masked_name', label: '가린 이름', requires_nickname: false },
    { value: 'name_nickname', label: '이름(닉네임)', requires_nickname: true },
];

const TestIcon: React.FC<{ name?: string }> = ({ name }) => <i data-icon={name} />;

let registry: MockComponentRegistry;
let utils: ReturnType<typeof createLayoutTest> | null = null;

beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Select', Select as any);
    registry.register('basic', 'Icon', TestIcon as any);
});

afterEach(() => {
    utils?.cleanup();
    utils = null;
    document.body.innerHTML = '';
});

/**
 * 표시 이름 구획을 주어진 폼 값으로 그린다.
 *
 * @param form 폼 값 (`display_name_setting`, `display_name_options`)
 * @param readOnly 읽기 전용 운영자 여부
 * @returns 그린 상자
 */
async function draw(form: Record<string, any>, readOnly = false): Promise<HTMLElement> {
    utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test/admin-board-form-display-name', components: [displayNameField] } as any,
        {
            componentRegistry: registry as any,
            locale: 'ko',
            translations: { 'sirsoft-board': { admin: { form: koForm } } },
            initialState: { _local: { form, hasChanges: false } },
            initialData: { _computed: { isReadOnly: readOnly } },
        },
    );
    const { container } = await utils.render();

    return container;
}

/**
 * 폼 값을 만든다.
 *
 * @param setting 게시판의 표시 이름 설정
 * @param inherited 「따름」일 때 적용되는 값
 * @returns 폼 값
 */
function formOf(
    setting: { mode: string; kind: string | null },
    inherited: { mode: string; kind: string | null; kind_label: string | null } = { mode: 'fixed', kind: 'nickname', kind_label: '닉네임' },
): Record<string, any> {
    return { display_name_setting: setting, display_name_options: { kinds: KINDS, inherited } };
}

/**
 * 목록형 선택칸의 단추를 찾는다.
 *
 * @param id 선택칸 DOM id
 * @returns 단추 (없으면 null)
 */
function trigger(id: string): HTMLButtonElement | null {
    const el = document.getElementById(id);
    if (!el) return null;
    if (el.matches('button[aria-haspopup="listbox"]')) return el as HTMLButtonElement;

    return (el.querySelector('button[aria-haspopup="listbox"]') ?? el.closest('div')?.querySelector('button[aria-haspopup="listbox"]')) as HTMLButtonElement | null;
}

/**
 * 목록형 단추의 접근 가능한 이름을 읽는다(관리자 Select 는 숨은 이름 글자와 고른 값을 aria-labelledby 로 잇는다).
 *
 * @param button 목록형 단추
 * @returns 접근 가능한 이름
 */
function nameOf(button: HTMLElement): string {
    const own = button.getAttribute('aria-label');
    if (own) return own;

    return (button.getAttribute('aria-labelledby') ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .map((id) => document.getElementById(id)?.textContent ?? '')
        .join(' ');
}

/**
 * 단추를 눌러 글자가 맞는 항목을 고른다.
 *
 * @param button 목록형 단추
 * @param label 고를 항목 글자
 */
async function choose(button: HTMLElement, label: string): Promise<void> {
    fireEvent.click(button);
    const option = await waitFor(() => {
        const found = screen.getAllByRole('option').find((o) => (o.textContent ?? '').trim() === label);
        expect(found, `항목 ${label}`).toBeTruthy();

        return found!;
    });
    fireEvent.click(option);
}

/**
 * 지금 상태의 표시 이름 설정을 읽는다(시험 도구가 점 경로 키를 그대로 담는 경우까지 함께 읽는다).
 *
 * @returns 표시 이름 설정
 */
function settingNow(): any {
    const local = utils!.getState()._local;

    return local['form.display_name_setting'] ?? local.form?.display_name_setting;
}

describe('게시판 설정 폼 — 작성자 표시 이름 (#490)', () => {
    // @scenario case=display_name_inherit
    // @effects display_name_inherit_shows_applied_value
    it('「따름」이면 종류 선택이 없고 지금 적용되는 종류를 알려 준다', async () => {
        await draw(formOf({ mode: 'inherit', kind: null }));

        const mode = await waitFor(() => {
            const found = trigger('board_display_name_mode');
            expect(found).toBeTruthy();

            return found!;
        });
        expect(mode.textContent).toContain(dn.modes.inherit);
        expect(nameOf(mode)).toContain(dn.mode_label);
        expect(document.getElementById('board_display_name_kind')).toBeNull();
        expect(document.getElementById('display_name_inherited_hint')!.textContent).toBe(dn.inherited_hint.replace('{{value}}', '닉네임'));
        expect(document.getElementById('display_name_nickname_notice')).toBeNull();
        expect(document.getElementById('display_name_member_hint')).toBeNull();
        expect(document.body.textContent).not.toContain('sirsoft-board.admin.form.display_name');
    });

    // @scenario case=display_name_inherit
    // @effects display_name_inherit_shows_applied_value
    it('물려받는 방식이 「회원이 고른 값」이면 종류 이름 대신 그 문구로 알린다', async () => {
        await draw(formOf({ mode: 'inherit', kind: null }, { mode: 'member', kind: null, kind_label: null }));

        const hint = await waitFor(() => {
            const found = document.getElementById('display_name_inherited_hint');
            expect(found).toBeTruthy();

            return found!;
        });
        expect(hint.textContent).toBe(dn.inherited_hint.replace('{{value}}', dn.modes.member));
    });

    // @scenario case=display_name_fixed
    // @effects display_name_fixed_reveals_kind_select, display_name_change_marks_form_dirty
    it('「특정 종류 고정」으로 바꾸면 종류 선택이 나오고 물려받던 종류가 먼저 채워진다', async () => {
        await draw(formOf({ mode: 'inherit', kind: null }));
        const mode = await waitFor(() => trigger('board_display_name_mode')!);
        expect(mode).toBeTruthy();

        await choose(mode, dn.modes.fixed);

        await waitFor(() => expect(settingNow()).toEqual({ mode: 'fixed', kind: 'nickname' }));
        expect(utils!.getState()._local.hasChanges).toBe(true);
        const kind = await waitFor(() => {
            const found = trigger('board_display_name_kind');
            expect(found).toBeTruthy();

            return found!;
        });
        expect(kind.textContent).toContain('닉네임');
        expect(nameOf(kind)).toContain(dn.kind_label);
        expect(document.getElementById('display_name_inherited_hint')).toBeNull();
    });

    // @scenario case=display_name_fixed
    // @effects display_name_fixed_reveals_kind_select
    it('종류 선택에는 서버가 준 종류가 모두 나오고 고른 종류가 담긴다', async () => {
        await draw(formOf({ mode: 'fixed', kind: 'name' }));
        const kind = await waitFor(() => trigger('board_display_name_kind')!);
        expect(kind).toBeTruthy();
        expect(kind.textContent).toContain('이름');

        fireEvent.click(kind);
        const labels = await waitFor(() => {
            const options = screen.getAllByRole('option').map((o) => (o.textContent ?? '').trim());
            expect(options.length).toBe(KINDS.length);

            return options;
        });
        expect(labels).toEqual(KINDS.map((k) => k.label));
        fireEvent.click(screen.getAllByRole('option').find((o) => (o.textContent ?? '').trim() === '가린 이름')!);

        await waitFor(() => expect(settingNow()).toEqual({ mode: 'fixed', kind: 'masked_name' }));
        expect(utils!.getState()._local.hasChanges).toBe(true);
        expect(document.getElementById('display_name_nickname_notice')).toBeNull();
    });

    // @scenario case=display_name_requires_nickname
    // @effects display_name_nickname_kind_shows_lock_notice
    it('닉네임이 필요한 종류를 고정하면 닉네임 잠금 안내가 보이고, 필요 없는 종류면 보이지 않는다', async () => {
        await draw(formOf({ mode: 'fixed', kind: 'name' }));
        const kind = await waitFor(() => trigger('board_display_name_kind')!);
        expect(kind).toBeTruthy();
        expect(document.getElementById('display_name_nickname_notice')).toBeNull();

        await choose(kind, '이름(닉네임)');

        await waitFor(() => expect(settingNow()).toEqual({ mode: 'fixed', kind: 'name_nickname' }));
        const notice = await waitFor(() => {
            const found = document.getElementById('display_name_nickname_notice');
            expect(found).toBeTruthy();

            return found!;
        });
        expect(notice.textContent).toContain(dn.requires_nickname_notice);
    });

    // @scenario case=display_name_member
    // @effects display_name_non_fixed_clears_kind
    it('「회원이 고른 값」으로 바꾸면 종류가 null 로 돌아가고 종류 선택과 닉네임 안내가 사라진다', async () => {
        await draw(formOf({ mode: 'fixed', kind: 'name_nickname' }));
        const mode = await waitFor(() => trigger('board_display_name_mode')!);
        expect(mode).toBeTruthy();
        await waitFor(() => expect(document.getElementById('display_name_nickname_notice')).toBeTruthy());

        await choose(mode, dn.modes.member);

        await waitFor(() => expect(settingNow()).toEqual({ mode: 'member', kind: null }));
        await waitFor(() => expect(document.getElementById('board_display_name_kind')).toBeNull());
        expect(document.getElementById('display_name_nickname_notice')).toBeNull();
        expect(document.getElementById('display_name_member_hint')!.textContent).toBe(dn.member_hint);
    });

    // @scenario case=display_name_member
    // @effects display_name_non_fixed_clears_kind
    it('「고정」에서 「따름」으로 돌아가도 종류가 null 로 돌아간다', async () => {
        await draw(formOf({ mode: 'fixed', kind: 'masked_name' }));
        const mode = await waitFor(() => trigger('board_display_name_mode')!);
        expect(mode).toBeTruthy();

        await choose(mode, dn.modes.inherit);

        await waitFor(() => expect(settingNow()).toEqual({ mode: 'inherit', kind: null }));
        await waitFor(() => expect(document.getElementById('display_name_inherited_hint')).toBeTruthy());
    });

    // @scenario case=display_name_save_body
    // @effects display_name_setting_rides_save_body
    it('저장 요청은 폼을 통째로 보내므로 바꾼 표시 이름 설정이 본문에 실린다', async () => {
        await draw(formOf({ mode: 'inherit', kind: null }));
        const mode = await waitFor(() => trigger('board_display_name_mode')!);
        await choose(mode, dn.modes.member);
        await waitFor(() => expect(settingNow()).toEqual({ mode: 'member', kind: null }));

        const save = findNode(boardForm, 'footer_save_button')!;
        expect(JSON.stringify(save.actions), '저장 요청 본문').toContain('"body":"{{_local.form}}"');

        const local = utils!.getState()._local;
        const form = { ...(local.form ?? {}), ...(local['form.display_name_setting'] ? { display_name_setting: local['form.display_name_setting'] } : {}) };
        const body = dataBindingEngine.evaluateExpression('_local.form', { _local: { form } }) as Record<string, any>;
        expect(body.display_name_setting).toEqual({ mode: 'member', kind: null });
    });

    // @scenario case=display_name_validation_error
    // @effects display_name_errors_show_under_controls
    it('서버 검증 오류는 방식 칸과 종류 칸 아래에 보인다', async () => {
        utils = createLayoutTest(
            { version: '1.0.0', layout_name: 'test/admin-board-form-display-name-errors', components: [displayNameField] } as any,
            {
                componentRegistry: registry as any,
                locale: 'ko',
                translations: { 'sirsoft-board': { admin: { form: koForm } } },
                initialState: {
                    _local: {
                        form: formOf({ mode: 'fixed', kind: null }),
                        errors: { 'display_name_setting.mode': ['방식 오류'], 'display_name_setting.kind': ['종류를 골라 주세요.'] },
                    },
                },
                initialData: { _computed: { isReadOnly: false } },
            },
        );
        await utils.render();

        await waitFor(() => expect(document.getElementById('display_name_kind_error')).toBeTruthy());
        expect(document.getElementById('display_name_kind_error')!.textContent).toBe('종류를 골라 주세요.');
        expect(document.getElementById('display_name_mode_error')!.textContent).toBe('방식 오류');
        // 종류가 비어 있으면 자리 문구가 보인다
        expect(trigger('board_display_name_kind')!.textContent).toContain(dn.kind_placeholder);
    });

    // @scenario case=display_name_read_only
    // @effects display_name_locked_for_read_only
    it('수정 권한이 없는 운영자에게는 두 선택칸이 잠긴다', async () => {
        await draw(formOf({ mode: 'fixed', kind: 'name' }), true);

        const mode = await waitFor(() => trigger('board_display_name_mode')!);
        expect(mode.disabled).toBe(true);
        expect(trigger('board_display_name_kind')!.disabled).toBe(true);
    });

    // @scenario case=display_name_i18n
    // @effects display_name_keys_exist_in_ko_and_en
    it('구획이 쓰는 문구 키가 ko 와 en 에 모두 있다', () => {
        const keys = (JSON.stringify(displayNameField).match(/sirsoft-board\.admin\.form\.display_name\.[a-z_.]+/g) ?? []).map((k) =>
            k.replace('sirsoft-board.admin.form.', ''),
        );
        expect(new Set(keys).size).toBeGreaterThanOrEqual(10);
        for (const key of new Set(keys)) {
            for (const [locale, lang] of [['ko', koForm], ['en', enForm]] as const) {
                const value = key.split('.').reduce((acc: any, part) => acc?.[part], lang as any);
                expect(typeof value === 'string' && value !== '', `${locale}: ${key}`).toBe(true);
            }
        }
    });
});
