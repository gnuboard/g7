/**
 * 관리자 페이지 작성 화면 — 등록 권한이 없을 때 읽기 전용
 *
 * 목록의 [페이지 추가]는 등록 권한이 없으면 비활성인데, 주소로 `/admin/pages/create` 에 바로
 * 들어오면 입력 가능한 폼이 열렸다(7.1.0 전수 실측 영역 L, O-L6). 서버가 중복 확인·저장을 403 으로
 * 막아 데이터 영향은 없지만, 입력을 다 하고 나서야 거절을 받는다. 수정 화면처럼 안내 문구를 띄우고
 * 입력·저장·첨부를 잠그는지 고정한다.
 *
 * @scenario-manifest tests/scenarios/page-form-create-readonly.yaml
 */

import { describe, it, expect } from 'vitest';

import adminPageForm from '../../../layouts/admin/admin_page_form.json';
import koLang from '../../../lang/partial/ko/admin.json';
import enLang from '../../../lang/partial/en/admin.json';
import koRoot from '../../../lang/ko.json';
import enRoot from '../../../lang/en.json';

type AnyNode = Record<string, any>;

function findById(node: AnyNode | null, id: string): AnyNode | null {
    if (!node) return null;
    if (node.id === id) return node;
    for (const child of node.children ?? []) {
        const found = findById(child, id);
        if (found) return found;
    }
    for (const slotChildren of Object.values(node.slots ?? {})) {
        if (Array.isArray(slotChildren)) {
            for (const child of slotChildren) {
                const found = findById(child as AnyNode, id);
                if (found) return found;
            }
        }
    }
    return null;
}

/**
 * 레이아웃의 `{{ }}` 식을 주어진 문맥으로 평가한다(엔진과 같은 JS 식 의미).
 */
function evaluate(expr: string, ctx: Record<string, unknown>): unknown {
    const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
    const names = Object.keys(ctx);
    // eslint-disable-next-line no-new-func
    return new Function(...names, `return (${body});`)(...names.map((n) => ctx[n]));
}

const form = adminPageForm as AnyNode;
const isReadOnlyExpr: string = form.computed?.isReadOnly ?? '';

function readOnly(ctx: { route?: AnyNode; pageData?: AnyNode; createAbility?: AnyNode }): boolean {
    return Boolean(evaluate(isReadOnlyExpr, { route: {}, pageData: undefined, createAbility: undefined, ...ctx }));
}

describe('관리자 페이지 작성 화면 — 등록 권한 없을 때 읽기 전용 (O-L6)', () => {
    /**
     * @scenario mode=create,permission=denied
     * @effects create_form_read_only_without_create_permission
     */
    it('등록 모드에서 can_create 가 false 이면 읽기 전용이다', () => {
        expect(readOnly({ createAbility: { data: { abilities: { can_create: false } } } })).toBe(true);
    });

    /**
     * @scenario mode=create,permission=granted
     * @effects create_form_editable_with_create_permission
     */
    it('등록 모드에서 can_create 가 true 이면 입력할 수 있다', () => {
        expect(readOnly({ createAbility: { data: { abilities: { can_create: true } } } })).toBe(false);
    });

    /**
     * @scenario mode=edit,permission=denied
     * @effects edit_form_read_only_rule_unchanged
     */
    it('수정 모드에서 can_update 가 false 이면 등록 권한과 무관하게 읽기 전용이다', () => {
        const createAbility = { data: { abilities: { can_create: true } } };
        expect(readOnly({ route: { id: '13' }, pageData: { data: { abilities: { can_update: false } } }, createAbility })).toBe(true);
    });

    /**
     * @scenario mode=edit,permission=granted
     * @effects edit_form_read_only_rule_unchanged
     */
    it('수정 모드에서 can_update 가 true 이면 입력할 수 있다', () => {
        expect(readOnly({ route: { id: '13' }, pageData: { data: { abilities: { can_update: true } } } })).toBe(false);
    });

    /**
     * @scenario mode=create,permission=denied
     * @effects create_ability_fetched_only_in_create_mode
     */
    it('등록 권한 확인 데이터 소스는 등록 모드에서만 목록 API 를 1건만 받고, 화면 전환 대기에 포함된다', () => {
        const ds = (form.data_sources ?? []).find((d: AnyNode) => d.id === 'createAbility');
        expect(ds).toBeDefined();
        expect(ds.endpoint).toBe('/api/modules/sirsoft-page/admin/pages');
        expect(ds.method).toBe('GET');
        expect(ds.if).toBe('{{!route?.id}}');
        expect(ds.params?.per_page).toBe(1);
        expect(form.transition_overlay?.wait_for).toContain('createAbility');
    });

    /**
     * @scenario mode=create,permission=denied
     * @effects create_form_read_only_without_create_permission
     */
    it('읽기 전용 배너는 등록 모드에서 등록 권한 문구를, 수정 모드에서 수정 권한 문구를 쓴다', () => {
        const banner = findById(form, 'read_only_banner');
        expect(banner?.if).toContain('isReadOnly');
        const text = JSON.stringify(banner);
        expect(text).toContain('read_only_message_create');
        expect(text).toContain("'$t:sirsoft-page.admin.page.form.read_only_message'");
        expect((koLang as AnyNode).page.form.read_only_message_create).toBe('등록 권한이 없어 읽기 전용으로 표시됩니다.');
        expect((enLang as AnyNode).page.form.read_only_message_create).toMatch(/create permission/);
        expect((koRoot as AnyNode).editor.data_source.createAbility).toBeTruthy();
        expect((enRoot as AnyNode).editor.data_source.createAbility).toBeTruthy();
    });

    /**
     * 편집기는 처음 그려질 때의 읽기 전용 여부로 굳는다(초기화 때 한 번만 읽음). 등록 권한 확인 전에
     * 그리면 권한 있는 관리자에게도 잠긴 편집기가 남는다(재실측에서 발견, 수정 전 구조로는 실패).
     *
     * @scenario mode=create,permission=granted
     * @effects create_form_editable_with_create_permission
     */
    it('본문 편집기는 등록 모드에서 권한 확인이 끝난 뒤, 수정 모드에서 기존 값이 온 뒤에 그려진다', () => {
        const editor = findById(form, 'content_editor');
        const mounted = (ctx: Record<string, unknown>) =>
            Boolean(evaluate(editor?.if ?? '', { route: {}, _local: {}, createAbility: undefined, ...ctx }));
        expect(mounted({})).toBe(false);
        expect(mounted({ createAbility: { data: { abilities: { can_create: true } } } })).toBe(true);
        expect(mounted({ createAbility: null })).toBe(true);
        expect(mounted({ route: { id: '13' }, _local: { form: {} } })).toBe(false);
        expect(mounted({ route: { id: '13' }, _local: { form: { content: { ko: '' } } } })).toBe(true);
    });

    /**
     * @scenario mode=create,permission=denied
     * @effects create_form_read_only_without_create_permission
     */
    it('저장 단추, 중복 확인 단추는 숨고 업로더와 입력은 같은 판정으로 잠긴다', () => {
        expect(findById(form, 'footer_save_button')?.if).toContain('_computed.isReadOnly');
        expect(findById(form, 'slug_check_button')?.if).toContain('_computed.isReadOnly');
        expect(findById(form, 'slug_input')?.props?.disabled).toContain('_computed.isReadOnly');
        expect(findById(form, 'title_input')?.props?.disabled).toContain('_computed.isReadOnly');
        expect(findById(form, 'file_uploader')?.props?.disabled).toContain('_computed.isReadOnly');
    });
});
