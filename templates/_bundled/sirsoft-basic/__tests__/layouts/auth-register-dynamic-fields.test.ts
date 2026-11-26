/**
 * @file auth-register-dynamic-fields.test.ts
 * @description 가입 화면이 운영자 설정(가입 항목 계약)대로 칸을 그리고 정의 버전을 실어 보내는지
 *
 * 종전 가입 화면은 이름, 닉네임, 연락처, 언어 칸과 오류 분류용 필드 이름 배열을 레이아웃에 박아 두었다.
 * 이제 그 칸은 공용 항목 조각이 서버가 준 계약(registerFields)으로 그리고, 위쪽 오류 상자는
 * 「어느 칸에도 속하지 않는 오류」만 계산식으로 가른다.
 *
 * @scenario-manifest tests/scenarios/auth-register-dynamic-fields.yaml
 */

import { describe, it, expect } from 'vitest';
import { evaluateComputedDefinitions } from '@core/template-engine/helpers/ComputedEvaluator';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

import registerLayout from '../../layouts/auth/register.json';
import registerForm from '../../layouts/partials/auth/_register_form.json';
import { expandSemanticTokens } from './support/semanticClasses';

type Node = Record<string, any>;

/**
 * 레이아웃의 계산값 전부를 엔진의 평가기로 계산한다.
 *
 * 식 엔진은 자바스크립트 전체를 받지 않는다(함수 본문의 `var` 선언, `if` 문 등). `new Function` 으로 계산하는 검증은
 * 엔진이 못 푸는 식도 통과시키므로, 계산값은 실제 앱과 같은 평가기로도 풀어 본다. 풀지 못한 식이 있으면 실패다.
 */
function engineComputed(definitions: Record<string, string>, context: Record<string, unknown>): Record<string, any> {
  const failures: string[] = [];
  const values = evaluateComputedDefinitions(definitions, context, new DataBindingEngine(), {
    previous: {},
    onError: 'undefined',
    failureLog: (name: string, error: unknown) => failures.push(`${name}: ${String(error)}`),
  } as any);
  expect(failures).toEqual([]);
  return values;
}


function collect(input: Node | Node[] | undefined, out: Node[] = []): Node[] {
  if (!input) return out;
  for (const node of Array.isArray(input) ? input : [input]) {
    out.push(node);
    collect(node.children, out);
  }
  return out;
}

/** 표현식({{ }})을 주어진 변수로 계산한다 */
function evaluate(expression: string, scope: Record<string, unknown>): any {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), 'return (' + body + ');')(...Object.values(scope));
}

const layout = registerLayout as Node;
const form = registerForm as Node;
const formNodes = collect(form);
const dataSource = (layout.data_sources as Node[]).find((source) => source.id === 'registerFields')!;

describe('가입 화면 — 운영자 설정을 따르는 항목', () => {
  describe('데이터소스 registerFields', () => {
    /**
     * @scenario part=data_source
     * @effects fields_fetched_from_public_api_without_blocking
     */
    it('공개 API 를 자동으로 부르고 blocking 이 아니다 (blocking 이면 init 이 받은 값을 지운다)', () => {
      expect(dataSource.endpoint.startsWith('/api/auth/register/fields')).toBe(true);
      expect(dataSource.method).toBe('GET');
      expect(dataSource.auto_fetch).toBe(true);
      expect(dataSource.auth_mode).toBe('optional');
      expect(dataSource.loading_strategy).not.toBe('blocking');
    });

    /**
     * @scenario part=data_source
     * @effects fields_not_fetched_when_registration_closed
     */
    it('가입이 닫힌 사이트에서는 부르지 않는다', () => {
      const open = (appConfig: any, settings: any) => evaluate(dataSource.if, { _global: { appConfig, settings } });
      expect(open({ auth: { registration_open: false } }, {})).toBe(false);
      expect(open({}, { security: { registration_enabled: false } })).toBe(false);
      expect(open({ auth: { registration_open: true } }, {})).toBe(true);
      expect(open({}, {})).toBe(true);
    });

    /**
     * @scenario part=data_source
     * @effects verification_token_is_forwarded
     */
    it('본인인증을 거쳐 왔으면 인증 토큰을 조회 주소에 싣는다', () => {
      const tail = dataSource.endpoint.replace('/api/auth/register/fields', '');
      expect(evaluate(tail, { query: {} })).toBe('');
      expect(evaluate(tail, { query: { verification_token: 'a b' } })).toBe('?verification_token=a%20b');
    });
  });

  describe('공용 항목 조각 연결', () => {
    /**
     * @scenario part=form
     * @effects form_includes_member_fields_partial
     */
    it('폼이 공용 항목 조각을 include 하고 문맥 computed 가 registerForm 을 가리킨다', () => {
      expect(formNodes.some((node) => node.partial === 'partials/member/_member_fields.json')).toBe(true);

      const ctx = evaluate(layout.computed.memberFieldCtx, {
        registerFields: { data: { fields: [{ key: 'name' }] } },
        _local: { errors: { name: ['필수'] }, isRegistering: true },
      });
      expect(ctx).toEqual({ fields: [{ key: 'name' }], formKey: 'registerForm', errors: { name: ['필수'] }, disabled: true });
      expect(evaluate(layout.computed.memberFieldCtx, { registerFields: undefined, _local: {} })).toEqual({ fields: [], formKey: 'registerForm', errors: {}, disabled: false });
    });

    /**
     * @scenario part=form
     * @effects signup_fields_passed_as_single_group_under_stable_root
     */
    it('받은 항목 전부를 묶음 하나로 조각에 넘기고, 감싸는 노드 id 는 반복 밖의 member_fields_root 다', () => {
      // 서버는 가입 화면 레이아웃에 이 id 가 글자 그대로 있는지로 운영자 수정본에 새 조각이 들어갔는지 가린다.
      const root = formNodes.find((node) => node.id === 'member_fields_root')!;
      expect(root).toBeDefined();
      expect(root.iteration).toBeUndefined();

      const loop = (root.children as Node[])[0];
      expect(loop.iteration?.item_var).toBe('memberFieldGroup');
      expect(loop.id).toContain('{{memberFieldGroup.key}}');
      expect((loop.children as Node[]).some((node) => node.partial === 'partials/member/_member_fields.json')).toBe(true);

      const fields = [{ key: 'name' }, { key: 'job' }];
      const groups = evaluate(loop.iteration.source, { registerFields: { data: { fields } } });
      expect(groups).toEqual([{ key: 'signup', fields }]);
      expect(evaluate(loop.iteration.source, { registerFields: undefined })).toEqual([{ key: 'signup', fields: [] }]);
    });

    /**
     * @scenario part=form
     * @effects form_has_no_hardcoded_member_inputs
     */
    it('폼에 이름, 닉네임, 휴대폰, 전화, 언어 칸이 박혀 있지 않다', () => {
      const names = formNodes.map((node) => node.props?.name).filter(Boolean);
      for (const key of ['name', 'nickname', 'mobile', 'phone', 'language']) {
        expect(names).not.toContain(key);
      }
      // 고정 칸은 그대로다
      for (const key of ['email', 'password', 'password_confirmation', 'agree_terms', 'agree_privacy']) {
        expect(names).toContain(key);
      }
    });

    /**
     * @scenario part=form
     * @effects extension_anchors_kept
     */
    it('확장 주입 앵커(register_extension_fields, register_privacy_agreement)는 그대로 있다', () => {
      const ids = formNodes.map((node) => node.id);
      expect(ids).toContain('register_extension_fields');
      expect(ids).toContain('register_privacy_agreement');
    });
  });

  describe('확장이 선언한 동의 항목', () => {
    /**
     * @scenario part=form
     * @effects extension_agreements_drawn_below_privacy_with_shared_partial
     */
    it('약관 묶음 항목은 개인정보 동의 아래 구획에서 공용 조각으로 그리고, 회원 항목 묶음에는 섞이지 않는다', () => {
      const block = formNodes.find((node) => node.id === 'register_extension_agreements')!;
      const fields = [
        { key: 'name', name: 'name', ui: { group: 'basic' } },
        { key: 'marketing_consent', name: 'marketing_consent', ui: { group: 'agreements' } },
        { key: 'email_subscription', name: 'email_subscription', ui: { group: 'agreements', parent: 'marketing_consent' } },
      ];
      expect(evaluate(block.if, { registerFields: { data: { fields } } })).toBe(true);
      expect(evaluate(block.if, { registerFields: { data: { fields: [fields[0]] } } })).toBe(false);
      expect(evaluate(block.if, { registerFields: undefined })).toBe(false);

      const loop = collect(block).find((node) => node.iteration?.item_var === 'memberFieldGroup')!;
      const groups = evaluate(loop.iteration.source, { registerFields: { data: { fields } } });
      expect(groups).toHaveLength(1);
      expect(groups[0].key).toBe('agreements');
      expect(groups[0].includeAgreements).toBe(true);
      expect(groups[0].fields.map((f: Node) => f.key)).toEqual(['marketing_consent', 'email_subscription']);
      expect(collect(loop).some((node) => node.partial === 'partials/member/_member_fields.g7.html' || JSON.stringify(node).includes('_member_fields'))).toBe(true);

      // 구획은 개인정보 동의(확장 앵커) 뒤에 선다
      const ids = formNodes.map((node) => node.id);
      expect(ids.indexOf('register_extension_agreements')).toBeGreaterThan(ids.indexOf('register_privacy_agreement'));

      // 회원 항목 묶음(signup)은 약관 묶음을 켜지 않는다 — 공용 조각이 그 묶음에서는 동의 항목을 건너뛴다
      const signup = formNodes.find((node) => node.id === 'member_fields_root')!;
      const signupLoop = collect(signup).find((node) => node.iteration?.item_var === 'memberFieldGroup')!;
      expect(evaluate(signupLoop.iteration.source, { registerFields: { data: { fields } } })[0].includeAgreements).toBeUndefined();
    });
  });

  describe('위쪽 오류 상자', () => {
    const orphan = (errors: Record<string, unknown>, fields: unknown[]) =>
      evaluate(layout.computed.registerOrphanErrors, { _local: { errors }, registerFields: { data: { fields } } });

    const fields = [
      { name: 'name' },
      { name: 'custom_fields.period' },
      { name: 'address', parts: { zipcode: 'zipcode', address: 'address', address_detail: 'address_detail' } },
    ];

    /**
     * @scenario part=orphan_errors
     * @effects field_errors_are_not_repeated_in_top_box
     */
    it('화면의 계산값은 실제 엔진의 평가기로도 풀리고, 칸 밖 오류만 위쪽 상자 목록이 된다', () => {
      const values = engineComputed(layout.computed, {
        _local: { errors: { name: ['이름'], email: ['이메일'], verification_token: ['인증이 만료되었습니다'] } },
        registerFields: { data: { fields } },
      });
      expect(values.registerOrphanErrors).toEqual(['인증이 만료되었습니다']);
    });

    /**
     * @scenario part=orphan_errors
     * @effects field_errors_are_not_repeated_in_top_box
     */
    it('고정 칸과 항목 칸(하위 경로, 주소의 부분 경로 포함)의 오류는 위쪽 상자에 넣지 않는다', () => {
      expect(orphan({
        email: ['이메일'],
        password: ['비밀번호'],
        agree_terms: ['약관'],
        name: ['이름'],
        'custom_fields.period.end': ['기간'],
        zipcode: ['우편번호'],
      }, fields)).toEqual([]);
    });

    /**
     * @scenario part=orphan_errors
     * @effects non_field_errors_listed_in_top_box
     */
    it('어느 칸에도 속하지 않는 오류(본인인증 등)만 문구 목록으로 낸다', () => {
      expect(orphan({ name: ['이름'], verification_token: ['본인인증이 만료되었습니다'], identity: '인증 필요' }, fields))
        .toEqual(['본인인증이 만료되었습니다', '인증 필요']);
      expect(orphan({}, [])).toEqual([]);
    });

    /**
     * — 빈 칸으로 제출한 422 에서 칸 아래 오류와 함께 위쪽 상자에 「이름은 필수입니다. (외 4개 오류)」 요약이 떴다.
     * 실패 처리가 칸 오류(errors)가 있어도 응답 message 를 늘 registerError 에 넣었기 때문이다.
     *
     * @scenario part=orphan_errors
     * @effects field_errors_are_not_repeated_in_top_box
     */
    it('칸 오류가 실린 실패 응답이면 위쪽 상자에 응답 요약 문구를 넣지 않고, 칸 오류가 없는 실패(본인 확인 필요 등)만 문구를 넣는다', () => {
      const submit = (form.actions as Node[]).find((action) => action.type === 'submit')!;
      const apiCall = (submit.actions as Node[]).find((action) => action.handler === 'apiCall')!;
      const failSet = (apiCall.onError as Node[]).find((action) => action.handler === 'setState' && String(action.if).includes("!== 'fields_changed'"))!;

      const withFieldErrors = { error: { response: { message: '이름은 필수입니다. (외 4개 오류)', errors: { name: ['이름은 필수입니다.'], email: ['이메일은 필수입니다.'] } } } };
      expect(evaluate(failSet.params.registerError, withFieldErrors) ?? null).toBeNull();

      const withoutFieldErrors = { error: { response: { message: '본인 확인이 필요합니다.' } } };
      expect(evaluate(failSet.params.registerError, withoutFieldErrors)).toBe('본인 확인이 필요합니다.');
      // 엔진이 응답을 풀어 둔 모양(error.errors, error.message)도 같다
      expect(evaluate(failSet.params.registerError, { error: { message: '요약', errors: { name: ['이름'] } } }) ?? null).toBeNull();
      expect(evaluate(failSet.params.registerError, { error: { message: '서버 오류', errors: {} } })).toBe('서버 오류');
    });

    /**
     * @scenario part=orphan_errors
     * @effects non_field_errors_listed_in_top_box
     */
    it('위쪽 상자는 요약 문구가 없어도 칸 밖 오류가 있으면 뜨고, 둘 다 없으면 뜨지 않는다', () => {
      const box = formNodes.find((node) => node.id === 'register_error_box')!;
      expect(box).toBeDefined();
      const shown = (registerError: unknown, orphans: string[]) => !!evaluate(box.if, { _local: { registerError }, $computed: { registerOrphanErrors: orphans } });
      expect(shown(null, ['인증이 만료되었습니다'])).toBe(true);
      expect(shown('본인 확인이 필요합니다.', [])).toBe(true);
      expect(shown(null, [])).toBe(false);

      const summary = collect(box).find((node) => JSON.stringify(node.text ?? '').includes('_local.registerError'))!;
      expect(summary).toBeDefined();
      expect(!!evaluate(summary.if, { _local: { registerError: null } })).toBe(false);
    });

    /**
     * 덧붙임 — 비밀번호 칸 오류만 컴포넌트가 text-sm 으로 그려 다른 칸(text-xs)보다 컸다.
     *
     * @scenario part=orphan_errors
     * @effects field_errors_share_one_text_size
     */
    it('고정 칸의 칸 아래 오류는 모두 같은 글자 크기(text-xs)로 그린다 — 비밀번호 입력 컴포넌트의 자체 오류 줄(text-sm)을 쓰지 않는다', () => {
      for (const key of ['email', 'password', 'password_confirmation', 'agree_terms', 'agree_privacy', 'display_name_kind']) {
        const line = formNodes.find((node) => typeof node.if === 'string' && node.if === `{{_local.errors?.${key}}}`);
        expect(line, `${key} 오류 줄`).toBeDefined();
        // 오류 줄 묶음이 의미 클래스 `form-error` 로 바뀐 줄은 템플릿 CSS 정의(block mt-1 text-xs …)까지 펼쳐 본다
        expect(expandSemanticTokens(line!.props?.className as string | undefined)).toContain('text-xs');
      }
      for (const input of formNodes.filter((node) => node.name === 'PasswordInput')) {
        expect(input.props?.error).toBeUndefined();
      }
    });

    /**
     * @scenario part=orphan_errors
     * @effects no_hardcoded_field_name_array
     */
    it('폼 조각에 필드 이름 배열이 박혀 있지 않고 상자가 computed 를 읽는다', () => {
      const raw = JSON.stringify(form);
      expect(raw).not.toMatch(/\['email'[^\]]*'agree_privacy'\]/);
      const list = formNodes.find((node) => node.id === 'register_orphan_errors');
      expect(list?.if).toBe('{{($computed.registerOrphanErrors ?? []).length > 0}}');
      expect(list?.children?.[0]?.iteration?.source).toBe('{{$computed.registerOrphanErrors ?? []}}');
    });
  });

  describe('제출', () => {
    const submit = (form.actions as Node[]).find((action) => action.type === 'submit')!;
    const apiCall = (submit.actions as Node[]).find((action) => action.handler === 'apiCall')!;

    /**
     * @scenario part=submit
     * @effects submit_sends_fields_version
     */
    it('받은 정의 버전을 가입 요청에 싣고, 받지 못했으면 싣지 않는다', () => {
      const body = (registerFields: unknown) => evaluate(apiCall.params.body, { _local: { registerForm: { email: 'a@b.c' } }, query: {}, registerFields });
      expect(body({ data: { fields_version: 'v1' } })).toEqual({ email: 'a@b.c', fields_version: 'v1' });
      expect(body(undefined)).toEqual({ email: 'a@b.c' });
    });

    /**
     * @scenario part=submit
     * @effects stale_form_refetches_fields_and_keeps_input
     */
    it('409 fields_changed 면 항목을 다시 받고 안내를 띄우되 입력값(registerForm)은 건드리지 않는다', () => {
      const onError = apiCall.onError as Node[];
      const stale = { error: { response: { error_code: 'fields_changed' } } };
      const invalid = { error: { response: { errors: { name: ['필수'] } } } };

      const ran = (scope: Record<string, unknown>) => onError.filter((action) => !action.if || evaluate(action.if, scope));

      const staleActions = ran(stale);
      expect(staleActions.map((action) => action.handler)).toEqual(['setState', 'refetchDataSource']);
      expect(staleActions[0].params.registerError).toBe('$t:member.fields.signup_fields_changed');
      expect(staleActions[0].params.registerForm).toBeUndefined();
      expect(staleActions[1].params.dataSourceId).toBe('registerFields');

      const invalidActions = ran(invalid);
      expect(invalidActions.map((action) => action.handler)).toEqual(['setState']);
      expect(invalidActions[0].params.errors).toContain('error.response?.errors');
    });
  });

  describe('표시 이름 종류 선택', () => {
    const byId = (id: string) => formNodes.find((node) => node.id === id)!;
    const options = [
      { value: 'name', label: '이름', preview: '홍길동', requires_nickname: false, is_default: true },
      { value: 'nickname', label: '닉네임', preview: '길동이', requires_nickname: true, is_default: false },
    ];
    const fieldsWith = (over: Node = {}) => ({
      data: {
        fields: [{ key: 'nickname', name: 'nickname' }],
        display_name: { can_choose: true, value: null, value_unavailable: false, default_kind: 'name', default_kind_label: '이름', options, preview_is_sample: true, ...over },
      },
    });
    const selectedOf = (registerFormState: Node, over: Node = {}) =>
      engineComputed({ displayNameSelected: layout.computed.displayNameSelected }, { _local: { registerForm: registerFormState }, registerFields: fieldsWith(over) }).displayNameSelected;

    /**
     * @scenario part=display_name
     * @effects register_display_name_select_drawn_only_when_choosable
     */
    it('T9-a: 선택 칸은 고를 수 있을 때만(can_choose) 회원 항목 아래에 그리고, 권한이 없으면 칸이 없다', () => {
      const field = byId('register_display_name_field');
      expect(evaluate(field.if, { registerFields: fieldsWith() })).toBe(true);
      expect(evaluate(field.if, { registerFields: fieldsWith({ can_choose: false, options: [] }) })).toBe(false);
      expect(evaluate(field.if, { registerFields: undefined })).toBe(false);
      expect(evaluate(field.if, { registerFields: { data: { fields: [] } } })).toBe(false);

      // 회원 항목 묶음 바로 뒤, 확장 주입 앵커 앞이다
      const ids = (form.children as Node[]).map((child) => child.id);
      expect(ids.indexOf('register_display_name_field')).toBe(ids.indexOf('member_fields_root') + 1);
      expect(ids.indexOf('register_extension_fields')).toBe(ids.indexOf('register_display_name_field') + 1);

      const select = byId('register_display_name_select');
      expect(select.name).toBe('Select');
      expect(select.props.name).toBe('display_name_kind');
      expect(select.props.autoBinding).toBe(false);
      expect(select.props['aria-label']).toBe('$t:member.display_name.label');
      expect(evaluate(select.props.value, { _local: { registerForm: {} } })).toBe('');
      expect(evaluate(select.props.value, { _local: { registerForm: { display_name_kind: 'nickname' } } })).toBe('nickname');
      const $t = (key: string, params: Node) => key + ':' + params.label;
      expect(evaluate(select.props.options, { registerFields: fieldsWith(), $t })).toEqual([
        { value: '', label: 'member.display_name.option_default:이름' },
        { value: 'name', label: '이름' },
        { value: 'nickname', label: '닉네임' },
      ]);
    });

    /**
     * @scenario part=display_name
     * @effects register_display_name_kind_sent_in_request_body
     */
    it('고른 종류는 폼 값 display_name_kind 로 담겨 가입 요청 본문에 실리고, 고르지 않으면 키가 없다', () => {
      const select = byId('register_display_name_select');
      const sequence = (select.actions as Node[]).find((action) => action.type === 'change')!;
      const steps = sequence.actions as Node[];
      expect(steps[0].handler).toBe('setState');
      expect(steps[0].params.target).toBe('local');
      expect(steps[0].params['registerForm.display_name_kind']).toBe('{{$event.target.value}}');

      const submit = (form.actions as Node[]).find((action) => action.type === 'submit')!;
      const apiCall = (submit.actions as Node[]).find((action) => action.handler === 'apiCall')!;
      const body = (registerFormState: Node) => evaluate(apiCall.params.body, { _local: { registerForm: registerFormState }, query: {}, registerFields: fieldsWith() });
      expect(body({ email: 'a@b.c', display_name_kind: 'nickname' })).toEqual({ email: 'a@b.c', display_name_kind: 'nickname' });
      expect(body({ email: 'a@b.c' })).toEqual({ email: 'a@b.c' });
    });

    /**
     * @scenario part=display_name
     * @effects register_display_name_preview_sample_then_recomputed
     */
    it('U15: 처음에는 표본 회원의 예시를 보이고, 종류를 바꾸면 입력해 둔 값으로 다시 계산한 미리보기로 바뀐다', () => {
      expect(selectedOf({}).value).toBe('name');
      expect(selectedOf({ display_name_kind: 'nickname' }).preview).toBe('길동이');
      expect(selectedOf({}, { can_choose: false, options: [] })).toBeNull();

      const preview = byId('register_display_name_preview');
      const $t = (key: string, params: Node) => key + ':' + params.name;
      const scope = (previews: unknown, registerFormState: Node) => ({
        _local: { displayNamePreviews: previews }, registerFields: fieldsWith(), $computed: { displayNameSelected: selectedOf(registerFormState) }, $t,
      });
      // 아직 다시 계산하지 않았으면 표본 예시 문구
      expect(evaluate(preview.text, scope(null, {}))).toBe('member.display_name.preview_sample:홍길동');
      // 종류를 바꿔 다시 계산한 뒤에는 입력값으로 계산한 글자
      expect(evaluate(preview.text, scope({ name: '김철수', nickname: '철수짱' }, { display_name_kind: 'nickname' }))).toBe('member.display_name.preview:철수짱');
      expect(evaluate(preview.if, scope(null, {}))).toBe(true);
      expect(evaluate(preview.if, { _local: {}, $computed: { displayNameSelected: null } })).toBe(false);

      // 종류를 바꿀 때만 한 번 묻는다 — 입력해 둔 이름, 닉네임, 이메일을 조회 인자로 싣는다
      const select = byId('register_display_name_select');
      const steps = ((select.actions as Node[]).find((action) => action.type === 'change')!.actions) as Node[];
      expect(steps[1].handler).toBe('apiCall');
      expect(steps[1].params.method).toBe('GET');
      expect(steps[1].target).toContain('/api/auth/register/fields?preview_name={{encodeURIComponent(_local.registerForm?.name ?? \'\')}}');
      expect(steps[1].target).toContain('&preview_nickname={{encodeURIComponent(_local.registerForm?.nickname ?? \'\')}}');
      expect(steps[1].target).toContain('&preview_email={{encodeURIComponent(_local.registerForm?.email ?? \'\')}}');
      const store = (Array.isArray(steps[1].onSuccess) ? steps[1].onSuccess : [steps[1].onSuccess])[0] as Node;
      expect(evaluate(store.params.displayNamePreviews, { response: { data: { display_name: { options: [{ value: 'nickname', preview: '철수짱' }] } } } })).toEqual({ nickname: '철수짱' });
      // 같은 화면으로 다시 들어오면 앞선 계산값을 지운다
      const reset = (layout.init_actions as Node[]).find((action) => action.handler === 'setState' && 'displayNamePreviews' in (action.params ?? {}))!;
      expect(reset.params.displayNamePreviews).toBeNull();
    });

    /**
     * @scenario part=display_name
     * @effects register_display_name_nickname_requirement_and_errors
     */
    it('닉네임이 필요한 종류면 알리고, 종류 오류는 선택 칸 아래에만 뜬다(위쪽 상자에 다시 나오지 않는다)', () => {
      const hint = byId('register_display_name_nickname_required');
      expect(hint.text).toBe('$t:member.display_name.requires_nickname');
      expect(evaluate(hint.if, { $computed: { displayNameSelected: selectedOf({ display_name_kind: 'nickname' }) } })).toBe(true);
      expect(evaluate(hint.if, { $computed: { displayNameSelected: selectedOf({}) } })).toBe(false);

      const error = byId('register_display_name_error');
      expect(evaluate(error.text, { _local: { errors: { display_name_kind: ['고를 수 없는 종류입니다'] } } })).toBe('고를 수 없는 종류입니다');
      // 서버가 준 닉네임 필수 오류(errors.nickname)는 닉네임 칸 아래에 뜨므로 위쪽 상자에 없다
      const values = engineComputed(layout.computed, {
        _local: { errors: { display_name_kind: ['고를 수 없는 종류입니다'], nickname: ['닉네임은 필수입니다'], verification_token: ['만료'] }, registerForm: {} },
        registerFields: fieldsWith(),
      });
      expect(values.registerOrphanErrors).toEqual(['만료']);
    });
  });

  describe('본인인증 채움', () => {
    const onSuccess = (Array.isArray(dataSource.onSuccess) ? dataSource.onSuccess : [dataSource.onSuccess]) as Node[];
    const fill = onSuccess.find((action) => action.handler === 'setState' && action.params?.registerForm !== undefined)!;
    const notices = onSuccess.filter((action) => action.handler === 'setState' && action.params?.registerError !== undefined);

    /** 식({{ }})을 실제 엔진의 평가기로 푼다 */
    const engineEval = (expression: string, context: Record<string, unknown>) =>
      new DataBindingEngine().evaluateExpression(expression.replace(/^\{\{/, '').replace(/\}\}$/, ''), context as any, { skipCache: true } as any);

    /** 데이터소스 성공 처리가 받는 문맥(응답만 실린다) */
    const success = (data: Record<string, unknown>) => ({ response: { data: { success: true, data }, sourceId: 'registerFields' } });

    /** 그 응답에서 실행되는 본인인증 채움 액션들 */
    const ran = (data: Record<string, unknown>) => [fill, ...notices].filter((action) => engineEval(action.if, success(data)) === true);

    const verifiedFields = [
      { key: 'name', name: 'name', type: 'text', default: '홍길동', readonly: true, locked_reason: 'identity' },
      { key: 'mobile', name: 'mobile', type: 'phone', default: '01012345678', readonly: false, locked_reason: null },
      { key: 'birth', name: 'custom_fields.birth', type: 'date', default: '1990-01-02', readonly: false, locked_reason: null },
      { key: 'gender', name: 'custom_fields.gender', type: 'choice', default: 'male', readonly: true, locked_reason: 'identity' },
      { key: 'nickname', name: 'nickname', type: 'text', default: null, readonly: false, locked_reason: null },
      { key: 'language', name: 'language', type: 'choice', default: 'ko', readonly: false, locked_reason: null },
    ];

    /**
     * @scenario part=identity_prefill
     * @effects verified_defaults_are_put_into_form_on_fetch_success
     */
    it('인증 값이 적용됐으면 채워진 항목(filled, locked)의 default 를 폼 경로대로 폼 값에 넣는다', () => {
      const data = { fields: verifiedFields, verified_claims: { status: 'applied', filled: ['name', 'mobile', 'birth'], locked: ['name', 'gender'] } };
      expect(ran(data)).toEqual([fill]);
      expect(fill.params.target).toBe('local');
      expect(engineEval(fill.params.registerForm, success(data))).toEqual({
        name: '홍길동',
        mobile: '01012345678',
        custom_fields: { birth: '1990-01-02', gender: 'male' },
      });
      // 응답 몸통이 한 겹 풀려 와도 같다
      expect(engineEval(fill.params.registerForm, { response: { data } })).toEqual({
        name: '홍길동',
        mobile: '01012345678',
        custom_fields: { birth: '1990-01-02', gender: 'male' },
      });
    });

    /**
     * @scenario part=identity_prefill
     * @effects only_verified_fields_are_prefilled
     */
    it('인증으로 채운 항목이 아니면(언어 기본값 등) 넣지 않고, 값이 없는 채움 항목도 넣지 않는다', () => {
      const data = {
        fields: [...verifiedFields, { key: 'memo', name: 'custom_fields.memo', type: 'text', default: null }],
        verified_claims: { status: 'applied', filled: ['name', 'memo'], locked: [] },
      };
      expect(engineEval(fill.params.registerForm, success(data))).toEqual({ name: '홍길동' });
      expect(engineEval(fill.params.registerForm, success({ fields: verifiedFields, verified_claims: { status: 'applied', filled: [], locked: [] } }))).toEqual({});
    });

    /**
     * @scenario part=identity_prefill
     * @effects no_prefill_without_applied_claims
     */
    it('인증 토큰 없이 왔거나 이 수단이 인증 값을 주지 않으면 폼을 건드리지 않는다', () => {
      expect(ran({ fields: verifiedFields, verified_claims: null })).toEqual([]);
      expect(ran({ fields: verifiedFields })).toEqual([]);
      expect(ran({ fields: verifiedFields, verified_claims: { status: 'no_claims', filled: [], locked: [] } })).toEqual([]);
    });

    /**
     * @scenario part=identity_prefill
     * @effects expired_or_invalid_token_shows_non_field_error_without_lock
     */
    it('T10-e: 토큰이 만료됐거나 알 수 없으면 채우지 않고, 칸에 속하지 않는 오류로 폼 위쪽 상자에 알린다', () => {
      const unlocked = verifiedFields.map((field) => ({ ...field, readonly: false, locked_reason: null, default: null }));

      const expired = ran({ fields: unlocked, verified_claims: { status: 'expired', filled: [], locked: [] } });
      expect(expired).toHaveLength(1);
      expect(expired[0].params).toEqual({ target: 'local', registerError: '$t:member.required_info.identity.expired' });

      const invalid = ran({ fields: unlocked, verified_claims: { status: 'invalid', filled: [], locked: [] } });
      expect(invalid).toHaveLength(1);
      expect(invalid[0].params).toEqual({ target: 'local', registerError: '$t:member.required_info.identity.invalid' });

      expect(notices).toHaveLength(2);
      // 폼 위쪽 상자는 registerError 가 있으면 뜬다 — 어느 칸 아래도 아니다
      const box = formNodes.find((node) => node.id === 'register_error_box');
      expect(box).toBeDefined();
      expect(evaluate(box!.if, { _local: { registerError: '$t:member.required_info.identity.expired' }, $computed: {} })).toBeTruthy();
      expect(JSON.stringify(box)).toContain('{{_local.registerError}}');
    });

    /**
     * @scenario part=identity_prefill
     * @effects prefill_conditions_read_response_only_and_source_is_not_blocking
     */
    it('채움 액션의 조건은 응답만 읽고(성공 처리의 조건은 전역, 로컬 상태를 읽지 못한다), 데이터소스는 blocking 이 아니다', () => {
      for (const action of [fill, ...notices]) {
        expect(action.if).toBeDefined();
        expect(action.if).not.toContain('_global');
        expect(action.if).not.toContain('_local');
        expect(action.if).not.toContain('query');
      }
      // blocking 이면 init 이 성공 처리 뒤에 돌아 채운 폼 값을 지운다
      expect(dataSource.loading_strategy).not.toBe('blocking');
    });
  });
});
