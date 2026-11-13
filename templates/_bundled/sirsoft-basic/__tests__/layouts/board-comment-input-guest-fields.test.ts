/**
 * @file board-comment-input-guest-fields.test.ts
 * @description 게시판 댓글과 답글 입력칸이 비회원 칸을 글 응답의 guest_fields 대로 그리고 그 값을 등록 요청에 싣는지
 *
 * 종전 댓글 입력칸의 비회원 이름과 비밀번호 칸에는 필수 표시도 접근 가능한 이름도 없었다(제출해야 필수임을 알았다).
 * 이제 글 상세 응답(post)의 `board.guest_fields` 를 읽는다: `fixed` 가 두 칸의 필수 여부를, `comment` 가 게시판이 켠
 * 비회원 항목(이메일, 추가 항목)의 항목 계약을 준다. 답글 입력칸(댓글 한 개 조각 안)도 같은 규칙을 따르되
 * 새 댓글 입력칸과 함께 열릴 수 있어 상태와 DOM id 를 나눈다.
 * 식은 실제 앱과 같은 식 엔진으로 계산한다(`new Function` 은 엔진이 못 푸는 식도 통과시킨다).
 *
 * @scenario-manifest tests/scenarios/board-comment-input-guest-fields.yaml
 */

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { actionsOf, initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import showLayout from '../../layouts/board/show.json';
import commentInput from '../../layouts/partials/board/show/_comment_input.json';
import commentItem from '../../layouts/partials/board/show/_comment_item.json';

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

/** 객체 나무 전체(액션 포함)에서 조건에 맞는 노드를 모두 모은다 */
function findAllDeep(node: any, predicate: (n: any) => boolean, out: any[] = []): any[] {
  if (node == null || typeof node !== 'object') return out;
  if (!Array.isArray(node) && predicate(node)) out.push(node);
  for (const value of Object.values(node)) findAllDeep(value, predicate, out);
  return out;
}

const guest = { currentUser: [] };
const member = { currentUser: { uuid: 'u-1', name: '홍길동', display_name: '길동이' } };

const emailField = { key: 'email', name: 'guest_fields.email', type: 'email', renderer: 'builtin', label: '이메일', required: true, options: [], constraints: {} };
const sourceField = { key: 'source', name: 'guest_fields.source', type: 'choice', renderer: 'builtin', label: '알게 된 경로', required: false, options: [{ value: 'search', label: '검색' }], constraints: {}, default: 'search' };
const companyField = { key: 'company', name: 'guest_fields.company', type: 'text', renderer: 'builtin', label: '소속', required: false, options: [], constraints: {} };

const fixedRequired = { author_name: { required: true }, password: { required: true } };

/** 글 상세 응답을 받은 화면의 문맥 (로그인한 회원의 응답에는 guest_fields 가 null 이다) */
function screen(guestFields: unknown, _global: Record<string, unknown> = guest, _local: Record<string, unknown> = {}) {
  return {
    post: { data: { id: 7, board: { slug: 'free', guest_fields: guestFields } } },
    route: { slug: 'free', id: '7' },
    _global,
    _local,
  };
}

interface Surface {
  label: string;
  /** DOM id 접두 */
  prefix: string;
  /** 조각의 노드 */
  nodes: Node[];
  /** 등록 요청 */
  call: Node;
  /** 항목 묶음의 자리 이름 */
  groupKey: string;
  /** 항목 값이 담기는 폼 상태 */
  formKey: string;
  /** 검증 오류가 담기는 상태 */
  errorsKey: string;
  /** 이름, 비밀번호 값이 담기는 상태 */
  nameKey: string;
  passwordKey: string;
}

const isCommentPost = (n: any) => n.handler === 'apiCall' && n.params?.method === 'POST' && typeof n.target === 'string' && n.target.endsWith('/comments');

const SURFACES: Surface[] = [
  {
    label: '새 댓글 입력칸',
    prefix: 'board_comment_guest',
    nodes: collect(commentInput as Node),
    call: findAllDeep(commentInput, isCommentPost)[0],
    groupKey: 'comment',
    formKey: 'commentGuestForm',
    errorsKey: 'commentErrors',
    nameKey: 'guest_comment_author_name',
    passwordKey: 'guest_comment_password',
  },
  {
    label: '답글 입력칸',
    prefix: 'board_reply_guest',
    nodes: collect(commentItem as Node),
    call: findAllDeep(commentItem, isCommentPost)[0],
    groupKey: 'reply',
    formKey: 'replyGuestForm',
    errorsKey: 'replyErrors',
    nameKey: 'guest_reply_author_name',
    passwordKey: 'guest_reply_password',
  },
];

const byId = (nodes: Node[], id: string) => nodes.find((node) => node.id === id)!;

describe('게시판 댓글과 답글 입력칸 — 비회원 칸', () => {
  /**
   * @scenario case=reply
   * @effects comment_and_reply_each_have_one_submit_request
   */
  it('두 조각에 댓글 등록 요청이 하나씩 있다 (대조 기준)', () => {
    expect(findAllDeep(commentInput, isCommentPost)).toHaveLength(1);
    expect(findAllDeep(commentItem, isCommentPost)).toHaveLength(1);
  });

  describe.each(SURFACES)('$label', (surface) => {
    const fixedBox = byId(surface.nodes, `${surface.prefix}_fixed_fields`);
    const fieldsBox = byId(surface.nodes, `${surface.prefix}_fields`);
    const groupHost = collect(fieldsBox).find((node) => node.iteration?.item_var === 'boardGuestFieldGroup')!;
    const input = (key: string) => byId(surface.nodes, `${surface.prefix}_${key}`);
    const star = (key: string) => byId(surface.nodes, `${surface.prefix}_${key}_required`);

    /**
     * @scenario case=fixed_marks
     * @effects name_and_password_have_star_label_and_required
     */
    it('이름과 비밀번호 칸에 화면에 보이는 별표, 접근 가능한 이름, required 와 aria-required 가 있다 (설정이 없으면 필수)', () => {
      for (const guestFields of [undefined, null, { fixed: fixedRequired, post: [], comment: [] }]) {
        const ctx = screen(guestFields);
        for (const key of ['author_name', 'password']) {
          expect(star(key).text).toBe('*');
          expect(evaluate(star(key).if, ctx)).toBe(true);
          expect(evaluate(input(key).props.required, ctx)).toBe(true);
          expect(evaluate(input(key).props['aria-required'], ctx)).toBe(true);
        }
      }
      expect(input('author_name').props['aria-label']).toBe('$t:board.guest_author_name');
      expect(input('password').props['aria-label']).toBe('$t:board.guest_password');
      expect(input('author_name').props.type).toBe('text');
      expect(input('password').props.type).toBe('password');
      for (const key of ['author_name', 'password']) {
        // 별표는 장식이라 보조 기술에는 aria-required 로만 알린다. 다크 짝이 있고 칸에 눌려 줄지 않는다.
        expect(star(key).props['aria-hidden']).toBe('true');
        expect(star(key).props.className).toContain('text-red-600');
        expect(star(key).props.className).toContain('dark:text-red-400');
        expect(star(key).props.className).toContain('shrink-0');
      }
    });

    /**
     * @scenario case=fixed_marks
     * @effects fixed_marks_follow_setting
     */
    it('별표와 required 와 aria-required 는 guest_fields.fixed 의 required 를 칸마다 따른다', () => {
      const ctx = screen({ fixed: { author_name: { required: false }, password: { required: true } }, post: [], comment: [] });
      expect(evaluate(star('author_name').if, ctx)).toBe(false);
      expect(evaluate(input('author_name').props.required, ctx)).toBe(false);
      expect(evaluate(input('author_name').props['aria-required'], ctx)).toBe(false);
      expect(evaluate(star('password').if, ctx)).toBe(true);
      expect(evaluate(input('password').props.required, ctx)).toBe(true);
      expect(evaluate(input('password').props['aria-required'], ctx)).toBe(true);
    });

    /**
     * @scenario case=two_columns
     * @effects name_and_password_stay_two_columns_on_narrow_screens
     */
    it('이름과 비밀번호는 좁은 화면에서도 두 칸으로 나란히 놓인다', () => {
      expect(fixedBox.props.className).toContain('grid grid-cols-2 gap-3');
      expect(fixedBox.responsive).toBeUndefined();
      // 두 칸이 격자 폭 안에서 줄어들 수 있어야 좁은 화면에서 넘치지 않는다
      const cells = fixedBox.children as Node[];
      expect(cells).toHaveLength(2);
      for (const cell of cells) expect(cell.props.className).toContain('min-w-0');
      for (const key of ['author_name', 'password']) {
        expect(engine.resolveBindings(input(key).props.className, screen(undefined))).toContain('min-w-0 flex-1');
      }
      // 값을 담는 방식은 종전과 같다(입력할 때 손으로 담는다)
      expect(input('author_name').props.value).toBe(`{{_local.${surface.nameKey} ?? ''}}`);
      expect(input('password').props.value).toBe(`{{_local.${surface.passwordKey} ?? ''}}`);
      expect(actionsOf(input('author_name')).map((action) => [action.type, action.handler, Object.keys(action.params)])).toEqual([['input', 'setState', ['target', surface.nameKey]]]);
      expect(actionsOf(input('password')).map((action) => [action.type, action.handler, Object.keys(action.params)])).toEqual([['input', 'setState', ['target', surface.passwordKey]]]);
    });

    /**
     * @scenario case=fields_drawn
     * @effects comment_fields_drawn_below_name_and_password
     */
    it('guest_fields.comment 의 항목을 이름과 비밀번호 줄 아래에 묶음 하나로 그리고, 항목이 없으면 묶음 자체를 그리지 않는다', () => {
      const withFields = screen({ fixed: fixedRequired, post: [companyField], comment: [emailField, sourceField] }, guest, { commentSubmitting: true });
      expect(evaluate(fieldsBox.if, withFields)).toBe(true);
      const groups = evaluate(groupHost.iteration.source, withFields);
      expect(groups).toHaveLength(1);
      expect(groups[0].key).toBe(surface.groupKey);
      expect(groups[0].formKey).toBe(surface.formKey);
      expect(groups[0].fields).toEqual([emailField, sourceField]);
      expect(groups[0].errors).toEqual({});
      expect(groups[0].disabled).toBe(true);
      expect(groups[0].inputClassName).toContain('text-sm');
      expect(groups[0].inputClassName).toMatch(/dark:bg-gray-\d+/);
      expect(JSON.stringify(groupHost)).toContain('partials/board/_guest_fields');

      for (const guestFields of [undefined, null, { fixed: fixedRequired, post: [companyField], comment: [] }]) {
        expect(!!evaluate(fieldsBox.if, screen(guestFields))).toBe(false);
      }
      // 이름과 비밀번호 줄 바로 다음 형제다
      const parent = surface.nodes.find((node) => (node.children ?? []).includes(fixedBox))!;
      expect((parent.children as Node[]).indexOf(fieldsBox)).toBe((parent.children as Node[]).indexOf(fixedBox) + 1);
    });

    /**
     * @scenario case=fields_drawn
     * @effects comment_field_values_bind_under_own_form_state
     */
    it('항목 값은 폼 자동 바인딩으로 이 입력칸의 폼 상태 아래 guest_fields.{키} 에 담기고, 그 상태의 초깃값이 화면에 있다', () => {
      expect(fieldsBox.dataKey).toBe(surface.formKey);
      expect(initStateOf(showLayout as Node)[surface.formKey]).toEqual({ guest_fields: {} });
      // 새 댓글과 답글은 폼 상태를 나눠 쓴다
      expect(SURFACES.map((item) => item.formKey)).toEqual(['commentGuestForm', 'replyGuestForm']);
    });

    /**
     * @scenario case=request_body
     * @effects comment_body_carries_guest_fields_by_key
     */
    it('등록 본문에 guest_fields 를 키별로 싣는다: 그려진 항목만, 입력하지 않은 칸은 기본값, 빈 값은 뺀다', () => {
      const body = surface.call.params.body as Record<string, string>;
      expect(body.author_name).toBe(`{{_local.${surface.nameKey}}}`);
      expect(body.password).toBe(`{{_local.${surface.passwordKey}}}`);
      const guestFields = { fixed: fixedRequired, post: [], comment: [emailField, sourceField, companyField] };
      const typed = screen(guestFields, guest, { [surface.formKey]: { guest_fields: { email: 'guest@example.com', company: '', removed_field: '꺼진 항목의 값' } } });
      expect(evaluate(body.guest_fields, typed)).toEqual({ email: 'guest@example.com', source: 'search' });
      // 다른 입력칸의 폼 상태는 읽지 않는다
      const otherForm = SURFACES.find((item) => item !== surface)!.formKey;
      expect(evaluate(body.guest_fields, screen(guestFields, guest, { [otherForm]: { guest_fields: { email: 'other@example.com' } } }))).toEqual({ source: 'search' });
    });

    /**
     * @scenario case=request_body
     * @effects comment_body_omits_guest_fields_without_fields_or_for_member
     */
    it('항목이 없는 게시판과 로그인한 회원의 요청에는 guest_fields 를 싣지 않는다', () => {
      const body = surface.call.params.body as Record<string, string>;
      expect(evaluate(body.guest_fields, screen({ fixed: fixedRequired, post: [], comment: [] }))).toBeUndefined();
      expect(evaluate(body.guest_fields, screen(undefined))).toBeUndefined();
      expect(evaluate(body.guest_fields, screen(null, member, { [surface.formKey]: { guest_fields: { email: 'left@example.com' } } }))).toBeUndefined();
      // 회원인데 응답에 항목이 남아 있어도(로그인 직후 다시 받기 전) 싣지 않는다
      expect(evaluate(body.guest_fields, screen({ fixed: fixedRequired, post: [], comment: [emailField] }, member))).toBeUndefined();
    });

    /**
     * @scenario case=reset_after_submit
     * @effects submit_success_clears_guest_inputs
     */
    it('등록에 성공하면 이름, 비밀번호, 항목 값, 오류를 비운다 (항목 값은 얕은 병합으로 통째로 갈아 끼운다)', () => {
      const onSuccess = [].concat(surface.call.onSuccess) as Node[];
      const clear = onSuccess.find((action) => action.handler === 'setState' && surface.nameKey in action.params)!;
      expect(clear.params[surface.nameKey]).toBe('');
      expect(clear.params[surface.passwordKey]).toBe('');
      expect(clear.params[surface.errorsKey]).toBeNull();
      expect(clear.params.commentSubmitting).toBe(false);

      const resetForm = onSuccess.find((action) => action.handler === 'setState' && surface.formKey in action.params)!;
      expect(resetForm.params.target).toBe('local');
      // 기본(깊은) 병합은 빈 객체로 덮어도 이전 키가 남는다
      expect(resetForm.params.merge).toBe('shallow');
      expect(evaluate(resetForm.params[surface.formKey], {})).toEqual({ guest_fields: {} });
      // 비운 뒤에 글을 다시 받는다
      expect(onSuccess.map((action) => action.handler)).toEqual(['setState', 'setState', 'refetchDataSource', 'toast']);
    });

    /**
     * @scenario case=validation_errors
     * @effects comment_failure_errors_drawn_under_fields
     */
    it('등록이 실패하면 서버의 오류 묶음을 담아 이름, 비밀번호 칸 아래와 항목 묶음에 보이고, 다시 보낼 때 비운다', () => {
      const onError = [].concat(surface.call.onError) as Node[];
      const store = onError.find((action) => action.handler === 'setState' && surface.errorsKey in action.params)!;
      const errors = { 'guest_fields.email': ['이메일을 입력해 주세요.'], author_name: ['이름을 입력해 주세요.'], password: ['비밀번호를 입력해 주세요.'] };
      expect(evaluate(store.params[surface.errorsKey], { error: { errors } })).toEqual(errors);
      expect(evaluate(store.params[surface.errorsKey], { error: { message: '권한이 없습니다.' } })).toBeNull();
      expect(store.params.commentSubmitting).toBe(false);
      // 알림창은 종전대로 뜬다
      expect(onError.some((action) => action.handler === 'toast' && action.params.type === 'error')).toBe(true);

      const failed = screen({ fixed: fixedRequired, post: [], comment: [emailField] }, guest, { [surface.errorsKey]: errors });
      expect(evaluate(groupHost.iteration.source, failed)[0].errors).toEqual(errors);
      for (const key of ['author_name', 'password']) {
        const line = byId(surface.nodes, `${surface.prefix}_${key}_error`);
        expect(evaluate(line.if, failed)).toBe(true);
        expect(evaluate(line.text, failed)).toBe(errors[key as 'author_name' | 'password'][0]);
        expect(line.props.className).toContain('dark:text-red-400');
        expect(evaluate(input(key).props['aria-invalid'], failed)).toBe(true);
        expect(engine.resolveBindings(input(key).props.className, failed)).toContain('border-red-500 dark:border-red-500');
        // 오류가 없으면 줄도 빨간 테두리도 없다
        const clean = screen(undefined);
        expect(evaluate(line.if, clean)).toBe(false);
        expect(evaluate(input(key).props['aria-invalid'], clean)).toBe(false);
        expect(engine.resolveBindings(input(key).props.className, clean)).not.toContain('border-red-500');
        expect(engine.resolveBindings(input(key).props.className, clean)).toMatch(/dark:border-(gray|blue)-600/);
      }

      // 등록 단추를 누르면 요청 전에 지난 오류를 비운다
      const sequence = findAllDeep(surface.nodes, (n) => n.handler === 'sequence' && findAllDeep(n, isCommentPost).length > 0)[0];
      const first = (sequence.actions ?? sequence.params?.actions)[0];
      expect(first.handler).toBe('setState');
      expect(first.params[surface.errorsKey]).toBeNull();
      expect(first.params.commentSubmitting).toBe(true);
    });

    /**
     * @scenario case=member
     * @effects member_has_no_guest_inputs
     */
    it('로그인한 회원에게는 이름, 비밀번호 칸도 항목 묶음도 그리지 않는다', () => {
      const ctx = screen({ fixed: fixedRequired, post: [], comment: [emailField] }, member);
      expect(!!evaluate(fixedBox.if, ctx)).toBe(false);
      expect(!!evaluate(fieldsBox.if, ctx)).toBe(false);
      expect(!!evaluate(fixedBox.if, screen(null, guest))).toBe(true);
    });
  });

  /**
   * @scenario case=reply
   * @effects reply_and_comment_inputs_do_not_share_ids_or_state
   */
  it('새 댓글과 답글 입력칸은 함께 열릴 수 있어 DOM id, 값 상태, 오류 상태가 겹치지 않는다', () => {
    const idsOf = (surface: Surface) => surface.nodes.map((node) => node.id).filter((id) => typeof id === 'string' && id.startsWith('board_'));
    const [comment, reply] = SURFACES;
    expect(idsOf(comment).filter((id) => idsOf(reply).includes(id))).toEqual([]);
    expect(idsOf(comment)).toHaveLength(new Set(idsOf(comment)).size);
    expect(idsOf(reply)).toHaveLength(new Set(idsOf(reply)).size);
    for (const key of ['groupKey', 'formKey', 'errorsKey', 'nameKey', 'passwordKey'] as const) {
      expect(comment[key]).not.toBe(reply[key]);
    }
    // 답글은 부모 댓글 번호를 함께 보낸다
    expect(reply.call.params.body.parent_id).toBe('{{_local.replyToCommentId}}');
    expect(comment.call.params.body.parent_id).toBeUndefined();
  });
});
