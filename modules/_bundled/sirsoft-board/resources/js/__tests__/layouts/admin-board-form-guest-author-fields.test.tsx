// e2e:allow 게시판 설정 폼의 「비회원 작성자 항목」 표를 실물 조각으로 그려 스위치 잠금, 값 변경, 출처 배지, 사용 불가 행, 저장 본문을 잰다 — 브라우저 축은 화면 실측이 덮는다
/**
 * @file admin-board-form-guest-author-fields.test.tsx
 * @description 게시판 설정 폼 「글 설정」 탭 — 비회원 작성자 항목 표
 *
 * 실제 조각 JSON(`_tab_post.json`)의 `guest_author_fields_field` 를 엔진 렌더러(createLayoutTest)로 그린다.
 * - 고정 행(이름, 비밀번호)은 네 칸이 모두 켜진 채 잠겨 있다
 * - 스위치는 `form.guest_author_fields[항목 키].{post|comment}.{enabled|required}` 를 불리언으로 바꾼다
 * - 「사용」을 끄면 같은 표면의 「필수」도 꺼지고 잠긴다
 * - 기여한 쪽이 잠근 칸은 누를 수 없고 이유가 보인다
 * - 다른 확장이 기여한 항목은 그 확장 이름이, 추가 항목은 「추가」가, 그 밖은 「게시판」이 출처로 보인다
 * - 유형을 제공한 확장이 꺼진 항목은 스위치 없이 「사용 불가」 한 칸으로 남는다
 * - 표 위 「항목 추가」가 추가 창을 열고, 운영자가 만든 행(`is_custom`)에만 지우기 단추가 있다
 * - 추가 창이 알린 새 항목은 꺼진 행으로 표에 더해지고, 지우기에 성공하면 행과 값이 빠진다
 * - 「게시판 기본 설정」의 같은 표는 `form.basic_defaults` 아래 값을 쓴다(새 게시판이 물려받는 기본값)
 * - 관리자 글 상세는 비회원 작성자가 낸 항목 값을 응답에 실린 만큼 보인다
 * - 좁은 화면 카드는 표와 같은 값, 같은 규칙을 쓴다
 *
 * @scenario-manifest tests/scenarios/admin-board-form-member-fields.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { fireEvent, waitFor } from '@testing-library/react';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import tabPost from '../../../layouts/admin/partials/admin_board_form/_tab_post.json';
import boardForm from '../../../layouts/admin/admin_board_form.json';
import addModal from '../../../layouts/admin/partials/admin_board_form/_guest_author_field_add_modal.json';
import settingsPost from '../../../layouts/admin/partials/admin_board_settings/_tab_board_settings_post.json';
import postCard from '../../../layouts/admin/partials/admin_board_post_detail/_post_card_content.json';
import commentNode from '../../../layouts/admin/partials/admin_board_post_detail/_comment.json';
import koSettings from '../../../lang/partial/ko/admin/settings.json';
import enSettings from '../../../lang/partial/en/admin/settings.json';
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

/**
 * 조건에 맞는 노드를 모두 모은다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @param acc 결과 누적
 * @returns 찾은 노드 목록
 */
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

const section = findNode(tabPost, 'guest_author_fields_field')!;
const list = findNode(section, 'guest_author_fields_list')!;
const gaf = (koForm as any).guest_author_fields;

const locked = { enabled: true, required: true, enabled_locked: true, required_locked: true };
const free = (enabled: boolean, required: boolean) => ({ enabled, required, enabled_locked: false, required_locked: false, lock_reason: null });

/** 서버가 주는 표의 행 — 고정 2행 + 모듈 항목 + 기여 항목(필수 잠금) + 추가 항목 + 꺼진 확장의 항목 */
const ROWS = [
    { key: 'author_name', label: '이름', type: 'text', fixed: true, available: true, source_type: 'module', source_label: null, post: locked, comment: locked },
    { key: 'password', label: '비밀번호', type: 'password', fixed: true, available: true, source_type: 'module', source_label: null, post: locked, comment: locked },
    { key: 'email', label: '이메일', type: 'email', fixed: false, available: true, source_type: 'module', source_label: null, post: free(true, false), comment: free(false, false) },
    {
        key: 'company', label: '회사명', type: 'text', fixed: false, available: true, source_type: 'plugin', source_label: '견적 플러그인',
        post: { enabled: true, required: true, enabled_locked: false, required_locked: true, lock_reason: '견적 플러그인이 필수로 고정했습니다.' },
        comment: { enabled: false, required: false, enabled_locked: true, required_locked: true, lock_reason: '견적 플러그인이 필수로 고정했습니다.' },
    },
    { id: 77, is_custom: true, key: 'memo', label: '메모', type: 'text', fixed: false, available: true, source_type: 'custom', source_label: null, post: free(false, false), comment: free(false, false) },
    {
        key: 'tax', label: '세금계산서', type: 'tax_invoice', fixed: false, available: false, source_type: 'plugin', source_label: '세무 플러그인',
        post: { enabled: true, required: false, enabled_locked: true, required_locked: true, lock_reason: null },
        comment: { enabled: false, required: false, enabled_locked: true, required_locked: true, lock_reason: null },
    },
];

/** 폼이 그대로 다시 보내는 값 — 고정 항목은 싣지 않는다 */
const FIELDS = {
    email: { post: { enabled: true, required: false }, comment: { enabled: false, required: false } },
    company: { post: { enabled: true, required: true }, comment: { enabled: false, required: false } },
    memo: { post: { enabled: false, required: false }, comment: { enabled: false, required: false } },
    tax: { post: { enabled: true, required: false }, comment: { enabled: false, required: false } },
};

/** 스위치 대역 — 누르면 관리자 Toggle 처럼 onChange 만 알린다(값은 바깥 상태가 정한다) */
const TestToggle: React.FC<{ id?: string; checked?: boolean; disabled?: boolean; ariaLabel?: string; onChange?: (e: unknown) => void }> = ({
    id,
    checked,
    disabled,
    ariaLabel,
    onChange,
}) => (
    <button
        type="button"
        role="switch"
        id={id}
        aria-checked={checked === true}
        data-checked-type={typeof checked}
        aria-label={ariaLabel}
        disabled={disabled === true}
        onClick={() => onChange?.({ target: { checked: !checked } })}
    />
);
const TestIcon: React.FC<{ name?: string }> = ({ name }) => <i data-icon={name} />;

let registry: MockComponentRegistry;
let utils: ReturnType<typeof createLayoutTest> | null = null;

beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
    registry.register('composite', 'Toggle', TestToggle as any);
    registry.register('basic', 'Icon', TestIcon as any);
});

afterEach(() => {
    utils?.cleanup();
    utils = null;
    document.body.innerHTML = '';
});

/**
 * 노드를 주어진 폼 값으로 그린다.
 *
 * @param components 그릴 노드
 * @param options 폼 값 덮어쓰기, 읽기 전용 여부, 오류
 * @returns 그린 상자
 */
async function draw(
    components: Node[],
    options: { form?: Record<string, any>; readOnly?: boolean; errors?: Record<string, string[]> } = {},
): Promise<HTMLElement> {
    utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test/admin-board-form-guest-author-fields', components } as any,
        {
            componentRegistry: registry as any,
            locale: 'ko',
            translations: { 'sirsoft-board': { admin: { form: koForm } }, common: { row_cell: '{{row}} {{column}}' } },
            initialState: {
                _local: {
                    form: { guest_author_field_rows: ROWS, guest_author_fields: FIELDS, ...(options.form ?? {}) },
                    hasChanges: false,
                    ...(options.errors ? { errors: options.errors } : {}),
                },
            },
            initialData: { _computed: { isReadOnly: options.readOnly === true } },
        },
    );
    const { container } = await utils.render();
    await waitFor(() => expect(document.getElementById('guest_field_email_post_enabled') ?? document.getElementById('guest_field_email_post_enabled_m')).toBeTruthy());

    return container;
}

/**
 * 스위치 하나를 찾는다.
 *
 * @param key 항목 키
 * @param surface 표면 (post | comment)
 * @param prop 속성 (enabled | required)
 * @param suffix 좁은 화면 카드면 `_m`
 * @returns 스위치 단추
 */
function sw(key: string, surface: 'post' | 'comment', prop: 'enabled' | 'required', suffix = ''): HTMLButtonElement {
    const el = document.getElementById(`guest_field_${key}_${surface}_${prop}${suffix}`) as HTMLButtonElement | null;
    expect(el, `스위치 ${key}.${surface}.${prop}${suffix}`).toBeTruthy();

    return el!;
}

/**
 * 지금 상태의 비회원 작성자 항목 설정을 읽는다(시험 도구가 점 경로 키를 그대로 담는 경우까지 함께 읽는다).
 *
 * @returns 항목 설정
 */
function fieldsNow(): any {
    const local = utils!.getState()._local;

    return local['form.guest_author_fields'] ?? local.form?.guest_author_fields;
}

/**
 * 값이 모두 불리언인지 본다(서버는 문자열 값을 받지 않는다).
 *
 * @param fields 항목 설정
 */
function expectAllBoolean(fields: Record<string, any>): void {
    for (const [key, entry] of Object.entries(fields)) {
        for (const surface of ['post', 'comment']) {
            for (const prop of ['enabled', 'required']) {
                expect(typeof entry?.[surface]?.[prop], `${key}.${surface}.${prop}`).toBe('boolean');
            }
        }
    }
}

describe('게시판 설정 폼 — 비회원 작성자 항목 표', () => {
    // @scenario case=guest_fields_fixed_rows
    // @effects guest_fields_fixed_rows_locked_on
    it('표에 여섯 열과 서버가 준 행이 모두 나오고, 고정 행(이름, 비밀번호)은 네 칸이 켜진 채 잠겨 있다', async () => {
        await draw([section]);

        const table = document.getElementById('board_guest_author_fields_table')!;
        expect(table).toBeTruthy();
        expect(Array.from(table.querySelectorAll('th')).map((th) => th.textContent)).toEqual([
            gaf.columns.field, gaf.columns.source, gaf.columns.post_enabled, gaf.columns.post_required, gaf.columns.comment_enabled, gaf.columns.comment_required, gaf.columns.actions,
        ]);
        expect(table.querySelectorAll('tbody tr')).toHaveLength(ROWS.length);
        expect(document.body.textContent).not.toContain('sirsoft-board.admin.form.guest_author_fields');

        for (const key of ['author_name', 'password']) {
            for (const surface of ['post', 'comment'] as const) {
                for (const prop of ['enabled', 'required'] as const) {
                    const toggle = sw(key, surface, prop);
                    expect(toggle.getAttribute('aria-checked'), `${key}.${surface}.${prop}`).toBe('true');
                    expect(toggle.disabled, `${key}.${surface}.${prop}`).toBe(true);
                }
            }
            expect(document.getElementById(`guest_field_row_${key}`)!.textContent).toContain(gaf.fixed_hint);
        }
    });

    // @scenario case=guest_fields_toggle_boolean
    // @effects guest_fields_toggle_writes_boolean, guest_fields_change_marks_form_dirty
    it('스위치를 누르면 그 칸만 불리언으로 바뀌고 「변경됨」 표식이 켜진다', async () => {
        await draw([section]);
        expect(sw('email', 'comment', 'enabled').getAttribute('aria-checked')).toBe('false');
        expect(sw('email', 'comment', 'enabled').dataset.checkedType).toBe('boolean');

        fireEvent.click(sw('email', 'comment', 'enabled'));

        await waitFor(() => expect(fieldsNow().email).toEqual({ post: { enabled: true, required: false }, comment: { enabled: true, required: false } }));
        expect(utils!.getState()._local.hasChanges).toBe(true);
        await waitFor(() => expect(sw('email', 'comment', 'enabled').getAttribute('aria-checked')).toBe('true'));
        // 「사용」이 켜지면 같은 표면의 「필수」를 누를 수 있다
        expect(sw('email', 'comment', 'required').disabled).toBe(false);

        fireEvent.click(sw('email', 'comment', 'required'));

        await waitFor(() => expect(fieldsNow().email.comment).toEqual({ enabled: true, required: true }));
        // 다른 항목은 그대로다
        expect(fieldsNow().company).toEqual(FIELDS.company);
        expect(fieldsNow().memo).toEqual(FIELDS.memo);
        expectAllBoolean(fieldsNow());
    });

    // @scenario case=guest_fields_enabled_off_clears_required
    // @effects guest_fields_enabled_off_turns_required_off
    it('「사용」을 끄면 같은 표면의 「필수」도 꺼지고 잠긴다(다른 표면은 그대로)', async () => {
        await draw([section], {
            form: { guest_author_fields: { ...FIELDS, email: { post: { enabled: true, required: true }, comment: { enabled: true, required: true } } } },
        });
        expect(sw('email', 'post', 'required').getAttribute('aria-checked')).toBe('true');
        expect(sw('email', 'post', 'required').disabled).toBe(false);

        fireEvent.click(sw('email', 'post', 'enabled'));

        await waitFor(() => expect(fieldsNow().email).toEqual({ post: { enabled: false, required: false }, comment: { enabled: true, required: true } }));
        await waitFor(() => expect(sw('email', 'post', 'required').getAttribute('aria-checked')).toBe('false'));
        expect(sw('email', 'post', 'required').disabled).toBe(true);
        expect(sw('email', 'comment', 'required').getAttribute('aria-checked')).toBe('true');
        expect(sw('email', 'comment', 'required').disabled).toBe(false);
    });

    // @scenario case=guest_fields_enabled_off_clears_required
    // @effects guest_fields_enabled_off_turns_required_off
    it('사용이 꺼진 칸은 저장된 값에 필수가 남아 있어도 꺼진 것으로 그린다', async () => {
        // 폼 값이 없는 항목은 서버가 준 행 값을 읽는다
        await draw([section], { form: { guest_author_fields: { memo: { post: { enabled: false, required: true }, comment: { enabled: false, required: false } } } } });

        expect(sw('memo', 'post', 'required').getAttribute('aria-checked')).toBe('false');
        expect(sw('memo', 'post', 'required').disabled).toBe(true);
        expect(sw('email', 'post', 'enabled').getAttribute('aria-checked')).toBe('true');

        // 폼 값이 빈 배열(PHP 의 빈 객체)이어도 스위치가 객체로 다시 쓴다
        utils!.cleanup();
        document.body.innerHTML = '';
        await draw([section], { form: { guest_author_fields: [] } });
        fireEvent.click(sw('memo', 'post', 'enabled'));
        await waitFor(() => expect(fieldsNow()?.memo).toEqual({ post: { enabled: true, required: false }, comment: { enabled: false, required: false } }));
        expect(Array.isArray(fieldsNow())).toBe(false);
    });

    // @scenario case=guest_fields_locked_cells
    // @effects guest_fields_locked_cells_disabled_with_reason
    it('기여한 쪽이 잠근 칸은 누를 수 없고 이유가 행에 보인다', async () => {
        await draw([section]);

        expect(sw('company', 'post', 'enabled').disabled).toBe(false);
        expect(sw('company', 'post', 'required').disabled).toBe(true);
        expect(sw('company', 'post', 'required').getAttribute('aria-checked')).toBe('true');
        expect(sw('company', 'comment', 'enabled').disabled).toBe(true);
        expect(sw('company', 'comment', 'required').disabled).toBe(true);
        expect(document.getElementById('guest_field_lock_reason_company')!.textContent).toBe('견적 플러그인이 필수로 고정했습니다.');
        // 잠금 이유가 없는 행에는 보조 글이 없다
        expect(document.getElementById('guest_field_lock_reason_email')).toBeNull();

        // 잠긴 스위치는 눌러도 값이 바뀌지 않는다
        fireEvent.click(sw('company', 'post', 'required'));
        expect(fieldsNow().company).toEqual(FIELDS.company);
        expect(utils!.getState()._local.hasChanges).toBe(false);
    });

    // @scenario case=guest_fields_source_badge
    // @effects guest_fields_source_badge_by_origin
    it('출처 열은 기여 확장 이름, 「추가」, 「게시판」으로 갈린다', async () => {
        await draw([section]);

        expect(document.getElementById('guest_field_source_company')!.textContent).toBe('견적 플러그인');
        expect(document.getElementById('guest_field_source_memo')!.textContent).toBe(gaf.source.custom);
        expect(document.getElementById('guest_field_source_email')!.textContent).toBe(gaf.source.board);
        expect(document.getElementById('guest_field_source_author_name')!.textContent).toBe(gaf.source.board);
        expect(document.getElementById('guest_field_source_tax')!.textContent).toBe('세무 플러그인');
    });

    // @scenario case=guest_fields_unavailable_row
    // @effects guest_fields_unavailable_row_has_no_switch
    it('유형을 제공한 확장이 꺼진 항목은 스위치 없이 「사용 불가」 한 칸으로 남는다', async () => {
        await draw([section]);

        const row = document.getElementById('guest_field_row_tax')!;
        expect(row).toBeTruthy();
        expect(row.querySelectorAll('[role="switch"]')).toHaveLength(0);
        const cell = document.getElementById('guest_field_unavailable_tax')!;
        expect(cell.getAttribute('colspan')).toBe('4');
        expect(cell.textContent).toContain(gaf.unavailable);
        expect(cell.textContent).toContain(gaf.unavailable_hint);
        // 쓸 수 있는 행에는 「사용 불가」 칸이 없고 스위치가 넷이다
        expect(document.getElementById('guest_field_unavailable_email')).toBeNull();
        expect(document.getElementById('guest_field_row_email')!.querySelectorAll('[role="switch"]')).toHaveLength(4);
    });

    // @scenario case=guest_fields_add_delete
    // @effects guest_fields_add_button_opens_modal, guest_fields_delete_only_custom_rows
    it('표 위 「항목 추가」가 추가 창을 열고, 운영자가 만든 행에만 지우기 단추가 있다', async () => {
        const container = await draw([section]);

        const add = document.getElementById('guest_author_field_add_button');
        expect(add, '항목 추가 단추').toBeTruthy();
        const addActions = JSON.stringify(actionsOf(findNode(section, 'guest_author_field_add_button')!));
        expect(addActions).toContain('guest_author_field_add_modal');
        expect(addActions).toContain('guestAuthorFieldAdd');

        // 지우기 단추는 운영자 항목(memo)에만. 모듈 항목(email), 기여 항목(company), 고정 항목에는 없다
        expect(document.getElementById('guest_field_delete_memo')).toBeTruthy();
        for (const key of ['author_name', 'password', 'email', 'company', 'tax']) {
            expect(document.getElementById(`guest_field_delete_${key}`), key).toBeNull();
        }
        // 지우기는 확인을 받고 코어 항목 API 로 그 항목 ID 를 지운다
        const del = findNode(section, 'guest_field_delete_{{row.key}}')!;
        const seq = actionsOf(del)[0];
        expect(seq.handler).toBe('sequence');
        expect(String(seq.confirm)).toContain('guest_author_fields.delete_confirm');
        expect(JSON.stringify(seq)).toContain("'/api/admin/field-sets/sirsoft-board.guest_author/fields/' + row.id");
        expect(JSON.stringify(seq)).toContain('"method":"DELETE"');
        expect(container.querySelectorAll('a')).toHaveLength(0);
    });

    // @scenario case=guest_fields_add_delete
    // @effects guest_fields_delete_success_removes_row_and_value
    it('지우기에 성공하면 그 행과 값만 표에서 빠진다', () => {
        const del = findNode(section, 'guest_field_delete_{{row.key}}')!;
        const success = JSON.parse(JSON.stringify(actionsOf(del)[0])).actions[0].onSuccess;
        const setState = (Array.isArray(success) ? success : [success]).find((a: any) => a.handler === 'setState');
        const ctx = { _local: { form: { guest_author_field_rows: ROWS, guest_author_fields: FIELDS } }, row: ROWS[4] };
        const rows = dataBindingEngine.evaluateExpression(setState.params['form.guest_author_field_rows'].replace(/^\{\{|\}\}$/g, ''), ctx) as any[];
        const values = dataBindingEngine.evaluateExpression(setState.params['form.guest_author_fields'].replace(/^\{\{|\}\}$/g, ''), ctx) as Record<string, any>;
        expect(rows.map((r) => r.key)).toEqual(['author_name', 'password', 'email', 'company', 'tax']);
        expect(Object.keys(values).sort()).toEqual(['company', 'email', 'tax']);
    });

    // @scenario case=guest_fields_add_delete
    // @effects guest_fields_added_field_appended_off
    it('추가 창이 알린 새 항목은 네 칸이 꺼진 운영자 행으로 표 끝에 더해진다', () => {
        const listener = findAll(section, (n) => n.event === 'sirsoft-board:guest-author-field-added' || n.listen === 'sirsoft-board:guest-author-field-added')[0]
            ?? findAll(section, (n) => JSON.stringify(n).includes('guest-author-field-added') && Array.isArray(n.actions)).map((n) => n.actions.find((a: any) => JSON.stringify(a).includes('guest-author-field-added')))[0];
        expect(listener, '추가 이벤트 받기').toBeTruthy();
        const setState = JSON.stringify(listener).includes('"handler":"setState"')
            ? (listener.handler === 'setState' ? listener : findAll(listener, (n) => n.handler === 'setState')[0])
            : null;
        expect(setState).toBeTruthy();
        const field = { id: 91, key: 'visit_route', label: '방문 경로', name: { ko: '방문 경로' }, type: 'text', source_type: 'custom' };
        const ctx = { _local: { form: { guest_author_field_rows: ROWS, guest_author_fields: FIELDS } }, _eventData: { field } };
        const rows = dataBindingEngine.evaluateExpression(setState.params['form.guest_author_field_rows'].replace(/^\{\{|\}\}$/g, ''), ctx) as any[];
        const values = dataBindingEngine.evaluateExpression(setState.params['form.guest_author_fields'].replace(/^\{\{|\}\}$/g, ''), ctx) as Record<string, any>;
        const added = rows[rows.length - 1];
        expect(added).toMatchObject({ id: 91, is_custom: true, key: 'visit_route', label: '방문 경로', fixed: false, available: true, source_type: 'custom' });
        expect(added.post).toMatchObject({ enabled: false, required: false, enabled_locked: false });
        expect(values.visit_route).toEqual({ post: { enabled: false, required: false }, comment: { enabled: false, required: false } });
        expectAllBoolean(values);
    });

    // @scenario case=guest_fields_add_delete
    // @effects guest_fields_add_modal_posts_off_field
    it('추가 창은 꺼진 항목을 코어 항목 API 로 만들고, 선택지 줄을 「값=이름」으로 풀어 보낸다', () => {
        const save = findNode(addModal, 'gaf_add_save')!;
        const api = findAll(save, (n) => n.handler === 'apiCall')[0];
        expect(api.target ?? api.params?.target).toBe('/api/admin/field-sets/sirsoft-board.guest_author/fields');
        const body = api.params?.body ?? api.body;
        expect(body.enabled).toBe(false);
        expect(body.required).toBe(false);
        const ctx = { _global: { appConfig: { siteLocale: 'ko' }, guestAuthorFieldAdd: { key: 'region', type: 'choice', options_text: 'seoul=서울\n\n busan \n' } } };
        const options = dataBindingEngine.evaluateExpression(String(body.options).replace(/^\{\{|\}\}$/g, ''), ctx);
        expect(options).toEqual([{ value: 'seoul', label: { ko: '서울' } }, { value: 'busan', label: { ko: 'busan' } }]);
        const noOptions = dataBindingEngine.evaluateExpression(String(body.options).replace(/^\{\{|\}\}$/g, ''), { _global: { guestAuthorFieldAdd: { type: 'text' } } });
        expect(noOptions).toBeUndefined();
        // 성공하면 창을 닫고 표가 있는 화면에 새 항목을 알린다
        expect(JSON.stringify(api)).toContain('sirsoft-board:guest-author-field-added');
        // 파일, 이미지 유형은 고를 수 없다
        const types = findAll(findNode(addModal, 'gaf_add_type_select')!, (n) => n.name === 'Option' || n.type === 'option' || n.value !== undefined)
            .map((n) => n.props?.value ?? n.value).filter(Boolean);
        expect(types).not.toContain('file');
        expect(types).not.toContain('image');
    });

    // @scenario case=guest_fields_read_only
    // @effects guest_fields_locked_for_read_only
    it('수정 권한이 없는 운영자에게는 추가 단추와 지우기 단추가 없다', async () => {
        await draw([section], { readOnly: true });

        expect(document.getElementById('guest_author_field_add_button')).toBeNull();
        expect(document.getElementById('guest_field_delete_memo')).toBeNull();
    });

    // @scenario case=guest_fields_accessible_names
    // @effects guest_fields_switch_reads_row_and_column
    it('스위치는 행 이름과 열 이름으로 읽힌다', async () => {
        await draw([section]);

        expect(sw('email', 'post', 'enabled').getAttribute('aria-label')).toBe(`이메일 ${gaf.columns.post_enabled}`);
        expect(sw('email', 'comment', 'required').getAttribute('aria-label')).toBe(`이메일 ${gaf.columns.comment_required}`);
        expect(sw('password', 'post', 'required').getAttribute('aria-label')).toBe(`비밀번호 ${gaf.columns.post_required}`);
    });

    // @scenario case=guest_fields_accessible_names
    // @effects guest_fields_delete_button_has_accessible_name
    it('아이콘만 있는 지우기 단추는 「{항목} 항목 삭제」로 읽힌다(표와 좁은 화면 카드 모두)', async () => {
        await draw([section]);

        // basic Button 은 받은 속성을 그대로 DOM 에 넘긴다 — 카멜 `ariaLabel` 은 비표준 `arialabel` 이 되어 이름이 없다
        const del = document.getElementById('guest_field_delete_memo')!;
        expect(del.getAttribute('aria-label')).toBe('메모 항목 삭제');
        expect(del.hasAttribute('arialabel')).toBe(false);

        // 좁은 화면 카드의 지우기 단추도 같은 이름 속성을 쓴다
        for (const id of ['guest_field_delete_{{row.key}}', 'guest_field_card_delete_{{row.key}}']) {
            const props = (findNode(section, id) as any)?.props ?? {};
            expect(props['aria-label'], id).toBe('$t:sirsoft-board.admin.form.guest_author_fields.delete|label={{row.label}}');
            expect(props.ariaLabel, id).toBeUndefined();
        }
    });

    // @scenario case=guest_fields_read_only
    // @effects guest_fields_locked_for_read_only
    it('수정 권한이 없는 운영자에게는 모든 스위치가 잠긴다', async () => {
        const container = await draw([section], { readOnly: true });

        const switches = Array.from(container.querySelectorAll<HTMLButtonElement>('[role="switch"]'));
        expect(switches.length).toBe(5 * 4);
        expect(switches.filter((s) => !s.disabled)).toHaveLength(0);
    });

    // @scenario case=guest_fields_validation_error
    // @effects guest_fields_error_shows_under_table
    it('서버 검증 오류는 표 아래에 보인다', async () => {
        await draw([section], { errors: { guest_author_fields: ['항목 설정 값이 올바르지 않습니다.'] } });

        expect(document.getElementById('guest_author_fields_error')!.textContent).toBe('항목 설정 값이 올바르지 않습니다.');
    });

    // @scenario case=guest_fields_mobile_cards
    // @effects guest_fields_cards_share_values_and_rules
    it('좁은 화면에서는 표 대신 항목별 카드로 그리고, 카드의 스위치가 같은 값을 같은 규칙으로 바꾼다', async () => {
        const portable = list.responsive?.portable;
        expect(portable?.children?.[0]?.id).toBe('board_guest_author_fields_cards');
        // 넓은 화면 자식(표)과 좁은 화면 자식(카드)이 같은 원본, 같은 판정식, 같은 쓰기식을 쓴다
        const table = findNode(list.children, 'board_guest_author_fields_table')!;
        const rowIter = findNode(table, 'guest_field_row_{{row.key}}')!.iteration.source;
        const cardIter = findNode(portable, 'guest_field_card_{{row.key}}')!.iteration.source;
        expect(cardIter).toBe(rowIter);
        const tableToggle = findNode(table, 'guest_field_{{row.key}}_{{cell.s}}_{{cell.p}}')!;
        const cardToggle = findNode(portable, 'guest_field_{{row.key}}_{{cell.s}}_{{cell.p}}_m')!;
        expect(cardToggle.props.checked).toBe(tableToggle.props.checked);
        expect(cardToggle.props.disabled).toBe(tableToggle.props.disabled);
        expect(cardToggle.props.ariaLabel).toBe(tableToggle.props.ariaLabel);
        expect(actionsOf(cardToggle)[0].params).toEqual(actionsOf(tableToggle)[0].params);

        // 카드 묶음만 따로 그려 실제로 눌러 본다
        await draw([portable.children[0]]);
        expect(document.getElementById('board_guest_author_fields_table')).toBeNull();
        expect(document.getElementById('guest_field_card_tax')!.querySelectorAll('[role="switch"]')).toHaveLength(0);
        expect(document.getElementById('guest_field_card_tax')!.textContent).toContain(gaf.unavailable);
        expect(document.getElementById('guest_field_card_company')!.textContent).toContain('견적 플러그인');
        expect(sw('password', 'comment', 'required', '_m').disabled).toBe(true);

        fireEvent.click(sw('email', 'post', 'enabled', '_m'));

        await waitFor(() => expect(fieldsNow().email).toEqual({ post: { enabled: false, required: false }, comment: { enabled: false, required: false } }));
        expect(utils!.getState()._local.hasChanges).toBe(true);
    });

    // @scenario case=guest_fields_save_body
    // @effects guest_author_fields_rides_save_body
    it('저장 요청은 폼을 통째로 보내므로 바꾼 항목 설정이 불리언으로 본문에 실린다', async () => {
        await draw([section]);
        fireEvent.click(sw('memo', 'post', 'enabled'));
        await waitFor(() => expect(fieldsNow().memo.post.enabled).toBe(true));

        const save = findNode(boardForm, 'footer_save_button')!;
        expect(JSON.stringify(save.actions), '저장 요청 본문').toContain('"body":"{{_local.form}}"');

        const local = utils!.getState()._local;
        const form = { ...(local.form ?? {}), ...(local['form.guest_author_fields'] ? { guest_author_fields: local['form.guest_author_fields'] } : {}) };
        const body = dataBindingEngine.evaluateExpression('_local.form', { _local: { form } }) as Record<string, any>;
        expect(body.guest_author_fields.memo).toEqual({ post: { enabled: true, required: false }, comment: { enabled: false, required: false } });
        expect(Object.keys(body.guest_author_fields).sort()).toEqual(['company', 'email', 'memo', 'tax']);
        expectAllBoolean(body.guest_author_fields);
        // JSON 으로 나갈 때도 불리언이다("on" 같은 문자열이 아니다)
        expect(JSON.stringify(body.guest_author_fields.memo.post)).toBe('{"enabled":true,"required":false}');
    });

    // @scenario case=guest_fields_module_defaults
    // @effects module_defaults_table_writes_basic_defaults
    it('「게시판 기본 설정」의 표는 같은 모양이고 스위치가 basic_defaults 아래 값을 바꾼다', async () => {
        expect(findNode(settingsPost, 'section_guest_author_defaults'), '기본값 카드').toBeTruthy();
        const card = findNode(settingsPost, 'default_guest_author_fields_field')!;
        utils = createLayoutTest(
            { version: '1.0.0', layout_name: 'test/board-settings-guest-defaults', components: [card] } as any,
            {
                componentRegistry: registry as any,
                locale: 'ko',
                translations: { 'sirsoft-board': { admin: { form: koForm, settings: koSettings } }, common: { row_cell: '{{row}} {{column}}' } },
                initialState: { _local: { form: { basic_defaults: { guest_author_field_rows: ROWS, guest_author_fields: FIELDS } }, hasChanges: false } },
                initialData: { _computed: { isReadOnly: false } },
            },
        );
        await utils.render();
        await waitFor(() => expect(document.getElementById('default_guest_field_memo_post_enabled')).toBeTruthy());
        expect(document.getElementById('default_board_guest_author_fields_table')).toBeTruthy();
        // 기본값 표에는 추가, 지우기 단추가 없다(항목은 게시판 수정 화면에서 만든다)
        expect(document.getElementById('guest_author_field_add_button')).toBeNull();

        fireEvent.click(document.getElementById('default_guest_field_memo_post_enabled')!);
        await waitFor(() => {
            const local = utils!.getState()._local;
            const values = local['form.basic_defaults.guest_author_fields'] ?? local.form?.basic_defaults?.guest_author_fields;
            expect(values.memo.post.enabled).toBe(true);
        });
        const local = utils!.getState()._local;
        const values = local['form.basic_defaults.guest_author_fields'] ?? local.form?.basic_defaults?.guest_author_fields;
        expectAllBoolean(values);
        expect(local['form.guest_author_fields'] ?? local.form?.guest_author_fields, '게시판 값이 아니라 기본값만 바뀐다').toBeUndefined();
    });

    // @scenario case=guest_fields_admin_detail
    // @effects admin_detail_shows_guest_values
    it('관리자 글 상세와 댓글은 응답에 실린 비회원 항목 값을 「이름: 값」으로 보인다', async () => {
        const values = [{ key: 'email', label: '이메일', value: 'guest@example.test', display_value: 'guest@example.test' }];
        const holder = findNode(postCard, 'post_guest_field_values')!;
        utils = createLayoutTest(
            { version: '1.0.0', layout_name: 'test/admin-post-guest-values', components: [holder] } as any,
            { componentRegistry: registry as any, locale: 'ko', translations: {}, initialData: { post: { data: { guest_field_values: values } } } },
        );
        await utils.render();
        await waitFor(() => expect(document.getElementById('post_guest_field_email')).toBeTruthy());
        expect(document.getElementById('post_guest_field_email')!.textContent).toContain('이메일: guest@example.test');
        utils.cleanup();

        // 값이 없으면(권한 없음은 null) 구획을 그리지 않는다
        utils = createLayoutTest(
            { version: '1.0.0', layout_name: 'test/admin-post-guest-values-empty', components: [holder] } as any,
            { componentRegistry: registry as any, locale: 'ko', translations: {}, initialData: { post: { data: { guest_field_values: null } } } },
        );
        await utils.render();
        expect(document.getElementById('post_guest_field_values')).toBeNull();

        const commentSpan = findAll(commentNode, (n) => typeof n.id === 'string' && n.id.startsWith('comment_guest_field_'))[0];
        expect(commentSpan, '댓글의 비회원 값 줄').toBeTruthy();
        expect(JSON.stringify(commentSpan)).toContain('comment?.guest_field_values');
    });

    // @scenario case=guest_fields_i18n
    // @effects guest_fields_keys_exist_in_ko_and_en
    it('구획이 쓰는 문구 키가 ko 와 en 에 모두 있다', () => {
        const keys = (JSON.stringify([section, addModal]).match(/sirsoft-board\.admin\.form\.guest_author_fields\.[a-z_.]+/g) ?? []).map((k) =>
            k.replace('sirsoft-board.admin.form.', ''),
        );
        expect(new Set(keys).size).toBeGreaterThanOrEqual(13);
        for (const key of new Set(keys)) {
            for (const [locale, lang] of [['ko', koForm], ['en', enForm]] as const) {
                const value = key.split('.').reduce((acc: any, part) => acc?.[part], lang as any);
                expect(typeof value === 'string' && value !== '', `${locale}: ${key}`).toBe(true);
            }
        }
        const settingsKeys = (JSON.stringify(findNode(settingsPost, 'section_guest_author_defaults')).match(/sirsoft-board\.admin\.settings\.[a-z_.]+/g) ?? []).map((k) =>
            k.replace('sirsoft-board.admin.settings.', ''),
        );
        expect(settingsKeys.length).toBeGreaterThanOrEqual(3);
        for (const key of new Set(settingsKeys)) {
            for (const [locale, lang] of [['ko', koSettings], ['en', enSettings]] as const) {
                const value = key.split('.').reduce((acc: any, part) => acc?.[part], lang as any);
                expect(typeof value === 'string' && value !== '', `${locale}: ${key}`).toBe(true);
            }
        }
    });
});
