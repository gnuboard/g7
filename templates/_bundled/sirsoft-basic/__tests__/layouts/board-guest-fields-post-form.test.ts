/**
 * @file board-guest-fields-post-form.test.ts
 * @description 게시판 글쓰기 폼이 비회원 칸을 게시판 응답의 guest_fields 대로 그리고 그 값을 저장 요청에 싣는지
 *
 * 종전 글쓰기 폼은 비회원의 이름과 비밀번호 두 칸만 그렸고 필수 별표가 레이아웃에 박혀 있었다.
 * 이제 글쓰기 메타(form_meta)의 `board.guest_fields` 를 읽는다: `fixed` 가 이름과 비밀번호의 필수 여부를,
 * `post` 가 게시판이 켠 비회원 항목(이메일, 추가 항목)의 항목 계약을 준다. 그 값이 없는 응답(옛 서버)과
 * 항목이 없는 게시판에서는 화면과 저장 본문이 종전과 같아야 하므로 그 경우를 여기서 잠근다.
 * 식은 실제 앱과 같은 식 엔진으로 계산한다(`new Function` 은 엔진이 못 푸는 식도 통과시킨다).
 *
 * @scenario-manifest tests/scenarios/board-guest-fields-post-form.yaml
 */

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import formLayout from '../../layouts/board/form.json';
import postForm from '../../layouts/partials/board/form/_post_form.json';
import guestFieldsPartial from '../../layouts/partials/board/_guest_fields.json';
import { expandSemanticTokens } from './support/semanticClasses';

type Node = Record<string, any>;

const engine = new DataBindingEngine();

/** 식({{ }})을 식 엔진으로 계산한다 */
function evaluate(expression: string, context: Record<string, unknown>): any {
  expect(expression.startsWith('{{') && expression.endsWith('}}')).toBe(true);
  return engine.evaluateExpression(expression.slice(2, -2), context);
}

/** 자식 노드(children)만 따라 내려가며 모은다 */
function collect(input: Node | Node[] | undefined, out: Node[] = []): Node[] {
  if (!input) return out;
  for (const node of Array.isArray(input) ? input : [input]) {
    out.push(node);
    collect(node.children, out);
  }
  return out;
}

/** 객체 나무 전체(액션 포함)에서 조건에 맞는 첫 노드를 찾는다 */
function findDeep(node: any, predicate: (n: any) => boolean): any {
  if (node == null || typeof node !== 'object') return undefined;
  if (!Array.isArray(node) && predicate(node)) return node;
  for (const value of Object.values(node)) {
    const found = findDeep(value, predicate);
    if (found !== undefined) return found;
  }
  return undefined;
}

const formNodes = collect(postForm as Node);
const partialNodes = collect(guestFieldsPartial as Node);
const byId = (nodes: Node[], id: string) => nodes.find((node) => node.id === id)!;

const guest = { currentUser: [] };
const member = { currentUser: { uuid: 'u-1', name: '홍길동', display_name: '길동이' } };

const emailField = { key: 'email', name: 'guest_fields.email', type: 'email', renderer: 'builtin', label: '이메일', placeholder: 'name@example.com', help: '답변 알림을 받을 주소입니다.', required: true, options: [], constraints: {} };
const sourceField = { key: 'source', name: 'guest_fields.source', type: 'choice', renderer: 'builtin', label: '알게 된 경로', required: false, options: [{ value: 'search', label: '검색' }, { value: 'friend', label: '지인' }], constraints: {}, default: 'search' };
const companyField = { key: 'company', name: 'guest_fields.company', type: 'text', renderer: 'builtin', label: '소속', required: false, options: [], constraints: {} };
const periodField = { key: 'period', name: 'guest_fields.period', type: 'date_range', renderer: 'builtin', label: '이용 기간', required: false };

const fixedRequired = { author_name: { required: true }, password: { required: true } };

/** 글쓰기 메타를 받은 화면의 문맥 */
function screen(guestFields: unknown, _global: Record<string, unknown> = guest, _local: Record<string, unknown> = {}, route: Record<string, unknown> = { slug: 'free' }) {
  return {
    form_meta: { data: { board: { name: '자유게시판', ...(guestFields === undefined ? {} : { guest_fields: guestFields }) } } },
    form_data: { data: {} },
    route,
    query: {},
    _global,
    _local,
  };
}

const fixedBox = byId(formNodes, 'board_guest_fixed_fields');
const groupHost = formNodes.find((node) => node.iteration?.item_var === 'boardGuestFieldGroup')!;
const saveCall = findDeep(postForm, (n) => n.handler === 'apiCall' && typeof n.target === 'string' && n.target.includes("'/posts'"));

describe('게시판 글쓰기 폼 — 비회원 칸', () => {
  describe('항목이 없는 게시판 (화면은 종전과 같다)', () => {
    /**
     * @scenario case=no_fields
     * @effects no_fields_draws_only_name_and_password
     */
    it('guest_fields 가 없거나 post 가 비어 있으면 이름과 비밀번호 칸만 그리고 항목 묶음은 하나도 그리지 않는다', () => {
      for (const guestFields of [undefined, { fixed: fixedRequired, post: [], comment: [] }]) {
        const ctx = screen(guestFields);
        expect(!!evaluate(fixedBox.if, ctx)).toBe(true);
        expect(evaluate(groupHost.iteration.source, ctx)).toEqual([]);
      }
      // 고정 칸은 이름과 비밀번호 둘뿐이고 폼 자동 바인딩의 이름이 종전과 같다
      const inputs = collect(fixedBox).filter((node) => node.name === 'Input');
      expect(inputs.map((node) => [node.id, node.props.name, node.props.type])).toEqual([
        ['board_guest_author_name', 'author_name', 'text'],
        ['board_guest_password', 'password', 'password'],
      ]);
      for (const input of inputs) {
        expect(input.props.value).toBeUndefined();
        expect(actionsOf(input)).toEqual([]);
      }
      // 항목 묶음은 고정 칸 바로 아래에 있다
      const siblings = (postForm as Node).children as Node[];
      expect(siblings.indexOf(groupHost)).toBe(siblings.indexOf(fixedBox) + 1);
    });

    /**
     * @scenario case=no_fields
     * @effects fixed_fields_default_to_required_without_setting
     */
    it('필수 설정이 응답에 없으면(옛 서버) 이름과 비밀번호를 종전처럼 필수로 그린다', () => {
      const ctx = screen(undefined);
      for (const key of ['author_name', 'password']) {
        const star = byId(formNodes, `board_guest_${key}_required`);
        const input = byId(formNodes, `board_guest_${key}`);
        expect(star.text).toBe('*');
        expect(evaluate(star.if, ctx)).toBe(true);
        expect(evaluate(input.props.required, ctx)).toBe(true);
        expect(evaluate(input.props['aria-required'], ctx)).toBe(true);
      }
      // 메타를 받기 전에도 식이 풀린다
      expect(evaluate(byId(formNodes, 'board_guest_author_name_required').if, { form_meta: undefined })).toBe(true);
    });
  });

  describe('이름과 비밀번호의 필수 표시', () => {
    /**
     * @scenario case=fixed_required
     * @effects fixed_fields_required_mark_follows_setting
     */
    it('별표와 required 와 aria-required 는 guest_fields.fixed 의 required 를 칸마다 따른다', () => {
      const ctx = screen({ fixed: { author_name: { required: true }, password: { required: false } }, post: [], comment: [] });
      const shown = (key: string) => ({
        star: evaluate(byId(formNodes, `board_guest_${key}_required`).if, ctx),
        required: evaluate(byId(formNodes, `board_guest_${key}`).props.required, ctx),
        ariaRequired: evaluate(byId(formNodes, `board_guest_${key}`).props['aria-required'], ctx),
      });
      expect(shown('author_name')).toEqual({ star: true, required: true, ariaRequired: true });
      expect(shown('password')).toEqual({ star: false, required: false, ariaRequired: false });
      // 별표는 다크 짝이 있고, 칸의 접근 가능한 이름은 그대로다
      expect(byId(formNodes, 'board_guest_author_name_required').props.className).toContain('dark:text-red-400');
      expect(byId(formNodes, 'board_guest_author_name').props['aria-label']).toBe('$t:board.guest_author_name');
      expect(byId(formNodes, 'board_guest_password').props['aria-label']).toBe('$t:board.guest_password');
    });
  });

  describe('게시판이 켠 비회원 항목', () => {
    const root = guestFieldsPartial as Node;
    const row = (root.children as Node[])[0];
    const input = partialNodes.find((node) => node.name === 'MemberFieldInput')!;

    /**
     * @scenario case=fields_drawn
     * @effects post_fields_passed_as_one_group_under_fixed_fields
     */
    it('guest_fields.post 의 항목을 묶음 하나로 공용 조각에 넘기고, 묶음과 칸과 오류 줄의 id 는 자리 이름과 항목 키를 따른다', () => {
      const ctx = screen({ fixed: fixedRequired, post: [emailField, sourceField], comment: [companyField] }, guest, { isSaving: true });
      expect(evaluate(groupHost.iteration.source, ctx)).toEqual([{ key: 'post', formKey: 'form', fields: [emailField, sourceField], errors: {}, disabled: true }]);
      expect(JSON.stringify(groupHost)).toContain('partials/board/_guest_fields');

      expect(root.id).toBe('board_guest_fields_{{boardGuestFieldGroup.key}}');
      expect(root.props.role).toBe('group');
      expect(root.props['aria-label']).toBe('$t:board.guest_fields.group_label');
      expect(row.id).toBe('board_guest_field_{{boardGuestFieldGroup.key}}_{{field.key}}');
      expect(row.iteration.item_var).toBe('field');
      expect(evaluate(row.iteration.source, { boardGuestFieldGroup: { fields: [emailField] } })).toEqual([emailField]);
      expect(evaluate(row.iteration.source, { boardGuestFieldGroup: {} })).toEqual([]);
      expect(byId(partialNodes, 'board_guest_field_error_{{boardGuestFieldGroup.key}}_{{field.key}}').name).toBe('Span');
    });

    /**
     * @scenario case=fields_drawn
     * @effects field_values_bind_under_form_guest_fields
     */
    it('값은 폼 자동 바인딩으로 글쓰기 폼 상태(_local.form) 아래 항목의 폼 경로(guest_fields.{키})에 담긴다', () => {
      // 조각 자신은 폼 상태를 정하지 않고(부르는 쪽을 따른다), 글쓰기 화면의 폼 상태 이름은 form 이다
      expect(partialNodes.every((node) => node.dataKey === undefined)).toBe(true);
      expect(groupHost.dataKey).toBeUndefined();
      expect(findDeep((formLayout as Node).slots ?? formLayout, (n) => n.dataKey === 'form')).toBeDefined();
      expect(input.type).toBe('composite');
      expect(input.props.name).toBe('{{field.name}}');
      expect(input.props.field).toBe('{{field}}');
      expect(input.props.value).toBeUndefined();
      expect(actionsOf(input)).toEqual([]);
      expect(input.props.errorId).toBe('board_guest_field_error_{{boardGuestFieldGroup.key}}_{{field.key}}');
      expect(evaluate(input.props.disabled, { boardGuestFieldGroup: { disabled: true } })).toBe(true);
      expect(evaluate(input.props.disabled, { boardGuestFieldGroup: {} })).toBe(false);
      // 입력칸 모양은 부르는 쪽이 주고, 주지 않으면 글쓰기 폼의 칸 모양이다
      expect(evaluate(input.props.inputClassName, { boardGuestFieldGroup: {} })).toContain('dark:bg-gray-800');
      expect(evaluate(input.props.inputClassName, { boardGuestFieldGroup: { inputClassName: 'w-full text-sm' } })).toBe('w-full text-sm');
    });

    /**
     * @scenario case=fields_drawn
     * @effects field_label_star_and_help_follow_contract
     */
    it('라벨, 필수 별표, 도움말은 항목 계약을 따른다', () => {
      const label = (row.children as Node[]).find((node) => node.name === 'Label')!;
      const star = (label.children as Node[]).find((node) => node.text === '*')!;
      const name = (label.children as Node[]).find((node) => node.text === '{{field.label}}')!;
      expect(name).toBeDefined();
      expect(evaluate(star.if, { field: emailField })).toBe(true);
      expect(evaluate(star.if, { field: sourceField })).toBe(false);
      expect(star.props.className).toContain('dark:text-red-400');
      expect(label.props.className).toContain('break-words');
      expect(evaluate(label.if, { field: emailField })).toBe(true);
      expect(evaluate(label.if, { field: { type: 'boolean', constraints: {} } })).toBe(false);
      expect(evaluate(label.if, { field: { type: 'boolean', constraints: { inline_label: '소식을 받겠습니다' } } })).toBe(true);

      const help = (row.children as Node[]).find((node) => node.name === 'P' && node.text === '{{field.help}}')!;
      expect(evaluate(help.if, { field: emailField })).toBe('답변 알림을 받을 주소입니다.');
      expect(!!evaluate(help.if, { field: sourceField })).toBe(false);
      expect(help.props.className).toContain('break-words');
    });

    /**
     * @scenario case=fields_drawn
     * @effects field_renderer_branches_match_member_fields
     */
    it('유형 갈래는 회원 항목과 같다: 주소는 부분별 폼 경로의 세 칸, 확장 유형은 확장점, 그 밖은 항목 입력 컴포넌트', () => {
      const addressBranch = (row.children as Node[]).find((node) => node.name === 'Div' && String(node.if ?? '').includes("'address'"))!;
      const typeSlot = partialNodes.find((node) => node.type === 'extension_point' && node.name === 'member_field_input')!;
      const shown = (field: Record<string, unknown>) => ({
        input: evaluate(input.if, { field }),
        address: evaluate(addressBranch.if, { field }),
        slot: evaluate(typeSlot.if, { field }),
      });
      expect(shown({ renderer: 'builtin' })).toEqual({ input: true, address: false, slot: false });
      expect(shown({ renderer: 'address' })).toEqual({ input: false, address: true, slot: false });
      expect(shown({ renderer: 'extension' })).toEqual({ input: false, address: false, slot: true });

      const parts = collect(addressBranch).filter((node) => node.name === 'Input');
      expect(parts.map((node) => node.props.name)).toEqual(['{{field.parts?.zipcode}}', '{{field.parts?.address}}', '{{field.parts?.address_detail}}']);
      const search = partialNodes.find((node) => node.type === 'extension_point' && node.name === 'address_search_slot')!;
      const addressField = { parts: { zipcode: 'guest_fields.addr.zipcode', address: 'guest_fields.addr.address' } };
      expect(evaluate(search.props.targetFields.zipcode, { field: addressField, boardGuestFieldGroup: { formKey: 'commentGuestForm' } })).toBe('commentGuestForm.guest_fields.addr.zipcode');
      expect(evaluate(search.props.targetFields.address, { field: addressField, boardGuestFieldGroup: {} })).toBe('form.guest_fields.addr.address');
      expect(Object.keys(typeSlot.props).sort()).toEqual(['disabled', 'error', 'field', 'formKey']);
    });

    /**
     * @scenario case=fields_drawn
     * @effects guest_fields_have_dark_pairs
     */
    it('글자색, 테두리, 배경 클래스에 다크 짝이 있다', () => {
      for (const node of [...partialNodes, ...collect(fixedBox)]) {
        const className = String(node.props?.className ?? '');
        if (/\btext-(gray|red)-\d+/.test(className)) expect(className).toMatch(/dark:text-/);
        if (/\bbg-white\b/.test(className)) expect(className).toMatch(/dark:bg-/);
        if (/\bborder-gray-\d+/.test(className)) expect(className).toMatch(/dark:border-/);
      }
    });
  });

  describe('저장 요청 본문', () => {
    const body = (ctx: Record<string, unknown>) => evaluate(saveCall.params.body, ctx);
    const typed = { title: '제목', content: '내용', author_name: '손님', password: 'pw-1234' };

    /**
     * @scenario case=request_body
     * @effects body_unchanged_without_fields
     */
    it('항목이 없으면 본문이 종전과 같다 (폼 값과 첨부 임시 키뿐이고 guest_fields 키가 없다)', () => {
      for (const guestFields of [undefined, { fixed: fixedRequired, post: [], comment: [companyField] }]) {
        const sent = body(screen(guestFields, guest, { form: typed, tempKey: 'tk-1' }));
        expect(sent).toEqual({ ...typed, temp_key: 'tk-1' });
        expect(Object.keys(sent)).not.toContain('guest_fields');
      }
    });

    /**
     * @scenario case=request_body
     * @effects body_carries_guest_fields_by_key
     */
    it('입력값을 guest_fields 에 키별로 싣는다: 그려진 항목만, 입력하지 않은 칸은 기본값, 빈 값은 뺀다', () => {
      const guestFields = { fixed: fixedRequired, post: [emailField, sourceField, companyField, periodField], comment: [] };
      const period = { start: '2026-11-01', end: '2026-11-30' };
      const sent = body(screen(guestFields, guest, { form: { ...typed, guest_fields: { email: 'guest@example.com', company: '', period, removed_field: '꺼진 항목의 값' } }, tempKey: 'tk-1' }));
      expect(sent.guest_fields).toEqual({ email: 'guest@example.com', source: 'search', period });
      expect(sent.author_name).toBe('손님');
      expect(sent.password).toBe('pw-1234');
      expect(sent.temp_key).toBe('tk-1');
      // 고른 값이 기본값보다 우선한다
      const picked = body(screen(guestFields, guest, { form: { ...typed, guest_fields: { source: 'friend' } } }));
      expect(picked.guest_fields).toEqual({ source: 'friend' });
      // 아무것도 입력하지 않았으면 빈 묶음이 가고, 필수 판정은 서버가 한다
      const untouched = body(screen({ fixed: fixedRequired, post: [emailField], comment: [] }, guest, { form: typed }));
      expect(untouched.guest_fields).toEqual({});
    });

    /**
     * @scenario case=request_body
     * @effects body_omits_guest_fields_for_member_and_edit
     */
    it('로그인한 회원의 글과 수정 요청에는 guest_fields 를 만들어 싣지 않는다', () => {
      const guestFields = { fixed: fixedRequired, post: [emailField, sourceField], comment: [] };
      const asMember = body(screen(guestFields, member, { form: { title: '제목', content: '내용' }, tempKey: 'tk-1' }));
      expect(asMember).toEqual({ title: '제목', content: '내용', temp_key: 'tk-1' });
      const editing = body(screen(guestFields, guest, { form: typed, tempKey: 'tk-1' }, { slug: 'free', id: '12' }));
      expect(Object.keys(editing)).not.toContain('guest_fields');
    });
  });

  describe('검증 오류 (422)', () => {
    const error = byId(partialNodes, 'board_guest_field_error_{{boardGuestFieldGroup.key}}_{{field.key}}');
    const input = partialNodes.find((node) => node.name === 'MemberFieldInput')!;
    const guestFields = { fixed: fixedRequired, post: [emailField, periodField], comment: [] };

    /**
     * @scenario case=validation_errors
     * @effects save_failure_errors_reach_the_group
     */
    it('저장이 실패하면 서버의 오류 묶음을 _local.errors 에 담고, 그 묶음이 항목 묶음으로 넘어간다', () => {
      const store = findDeep(saveCall.onError, (n) => n.handler === 'setState' && 'errors' in (n.params ?? {}));
      const errors = { 'guest_fields.email': ['이메일을 입력해 주세요.'] };
      expect(evaluate(store.params.errors, { error: { errors } })).toEqual(errors);
      expect(evaluate(groupHost.iteration.source, screen(guestFields, guest, { errors }))[0].errors).toEqual(errors);
      // 오류가 글자 한 줄로 온 경우(전체 오류 상자가 그린다)에는 칸 오류로 풀지 않는다
      expect(evaluate(groupHost.iteration.source, screen(guestFields, guest, { errors: '저장하지 못했습니다.' }))[0].errors).toEqual({});
      expect(evaluate(groupHost.iteration.source, screen(guestFields, guest, { errors: null }))[0].errors).toEqual({});
    });

    /**
     * @scenario case=validation_errors
     * @effects field_error_drawn_under_its_field
     */
    it('오류 키 guest_fields.{키} 의 문구를 그 칸 아래에 그리고 입력 컴포넌트에도 넘긴다', () => {
      const errors = { 'guest_fields.email': ['이메일을 입력해 주세요.'], title: ['제목을 입력해 주세요.'] };
      const failed = { field: emailField, boardGuestFieldGroup: { key: 'post', errors } };
      expect(evaluate(error.if, failed)).toBe(true);
      expect(evaluate(error.text, failed)).toBe('이메일을 입력해 주세요.');
      expect(evaluate(input.props.error, failed)).toBe('이메일을 입력해 주세요.');
      // 다른 칸의 오류는 이 칸에 그리지 않는다
      const other = { field: sourceField, boardGuestFieldGroup: { key: 'post', errors } };
      expect(evaluate(error.if, other)).toBe(false);
      expect(evaluate(input.props.error, other)).toBe('');
      expect(evaluate(error.if, { field: emailField, boardGuestFieldGroup: { key: 'post', errors: {} } })).toBe(false);
      // #399 R-1 — 인라인 묶음이 의미 클래스 `form-error` 로 바뀌었다. 템플릿 CSS 정의까지 펼쳐 같은 색·다크 짝을 확인한다
      expect(error.props.className.split(/\s+/)).toContain('form-error');
      expect(expandSemanticTokens(error.props.className)).toEqual(expect.arrayContaining(['text-red-600', 'dark:text-red-400', 'text-xs']));
    });

    /**
     * @scenario case=validation_errors
     * @effects field_error_collects_sub_paths
     */
    it('묶음 유형(기간 등)은 하위 경로의 오류도 그 칸 아래에 모은다', () => {
      const failed = { field: periodField, boardGuestFieldGroup: { key: 'post', errors: { 'guest_fields.period.end': ['끝 날짜를 확인해 주세요.'] } } };
      expect(evaluate(error.if, failed)).toBe(true);
      expect(evaluate(error.text, failed)).toBe('끝 날짜를 확인해 주세요.');
      expect(evaluate(input.props.error, failed)).toBe('끝 날짜를 확인해 주세요.');
      // 이름이 앞부분만 같은 다른 항목(guest_fields.period_note)의 오류는 섞이지 않는다
      const lookalike = { field: periodField, boardGuestFieldGroup: { key: 'post', errors: { 'guest_fields.period_note': ['너무 깁니다.'] } } };
      expect(evaluate(error.if, lookalike)).toBe(false);
    });
  });

  describe('로그인한 회원과 수정 화면', () => {
    /**
     * @scenario case=member
     * @effects member_and_edit_have_no_guest_fields
     */
    it('회원에게는 이름과 비밀번호 칸도 항목 묶음도 그리지 않고, 비회원이라도 수정 화면에는 그리지 않는다', () => {
      const guestFields = { fixed: fixedRequired, post: [emailField], comment: [] };
      const asMember = screen(guestFields, member);
      expect(!!evaluate(fixedBox.if, asMember)).toBe(false);
      expect(evaluate(groupHost.iteration.source, asMember)).toEqual([]);
      const editing = screen(guestFields, guest, {}, { slug: 'free', id: '12' });
      expect(!!evaluate(fixedBox.if, editing)).toBe(false);
      expect(evaluate(groupHost.iteration.source, editing)).toEqual([]);
      // 메타를 받기 전에도 그리지 않는다
      const before = { form_meta: undefined, route: { slug: 'free' }, _global: guest, _local: {} };
      expect(!!evaluate(fixedBox.if, before)).toBe(false);
      expect(evaluate(groupHost.iteration.source, before)).toEqual([]);
    });
  });
});
