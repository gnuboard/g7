/**
 * @file social-signup-modal-fields.test.ts
 * @description 소셜 가입 완료 창(사용자 템플릿 판본)이 코어 가입 화면과 같은 회원 항목을 받고, 원본 창의 동작을 그대로 갖는지
 *
 * extensions/sirsoft-social_login/auth_register_social_modals 는 소셜 로그인 플러그인의 같은 조각을 통째로 대체한다(템플릿 오버라이드).
 * 달라진 것은 가입 완료 창이 이름, 닉네임 두 칸 대신 가입 항목 계약(/api/auth/register/fields)대로 칸을 그리고
 * 받은 정의 버전(fields_version)을 가입 요청에 싣는 것뿐이다. 그 밖(만료 안내, 이메일 인증번호, 약관 동의, 이미 가입된 이메일 창,
 * 로그인 인계)은 원본과 같아야 한다 — 파일 단위 대체라 한 줄이라도 빠지면 그 동작이 사라진다.
 *
 * @scenario-manifest tests/scenarios/social-signup-modal-fields.yaml
 */

import { describe, it, expect } from 'vitest';

import override from '../../extensions/sirsoft-social_login/auth_register_social_modals.json';
import original from '../../../../../plugins/_bundled/sirsoft-social_login/resources/extensions/auth_register_social_modals.json';
import pluginManifest from '../../../../../plugins/_bundled/sirsoft-social_login/plugin.json';
import { byId, collectFragment, evaluate, evaluateBoth, sampleFields, withoutComments, type Node } from './support/authModalOverrides';

const fragment = override as Node;
const source = original as Node;
const nodes = collectFragment(fragment);
const sourceNodes = collectFragment(source);

const FIELDS_URL = '/api/auth/register/fields?pending_source=sirsoft-social_login&pending_token={{query.social_pending}}';

/** 가입 대기 조회 액션(진입 때 한 번) */
const pendingCall = (fragment.init_actions as Node[]).find((action) => action.handler === 'apiCall')!;
const pendingSuccess = pendingCall.onSuccess as Node[];
const fieldsCall = pendingSuccess.find((action) => action.handler === 'apiCall')!;

/** 가입 단추의 가입 요청 액션 */
const submit = byId(nodes, 'social_signup_submit');
const submitSequence = (submit.actions as Node[])[0].actions as Node[];
const completeCall = submitSequence.find((action) => action.handler === 'apiCall')!;
const completeFail = completeCall.onError as Node[];

/** 창의 전역 상태 표본 — 항목을 받은 상태 */
function readyState(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    socialLoginFieldsState: 'ready',
    socialLoginFields: sampleFields(),
    socialLoginFieldsVersion: 'v-490',
    socialLoginFieldForm: { name: '홍길동', nickname: '길동', custom_fields: { job: '개발자' }, agree_marketing_consent: true },
    socialLoginForm: { name: '옛 이름 칸', nickname: '옛 닉네임 칸', email: 'typed@example.com', agree_terms: true, agree_privacy: true },
    socialLoginSignup: { email: { mode: 'locked', value: 'kakao@example.com' }, show_profile_fields: true },
    ...overrides,
  };
}

describe('소셜 가입 창 판본 — 대체 대상과 버전 제약', () => {
  /**
   * @scenario part=envelope
   * @effects override_targets_same_layout_and_priority_as_original
   */
  it('원본과 같은 대상(auth/register)과 우선순위를 갖는다', () => {
    expect(fragment.target_layout).toBe('auth/register');
    expect(fragment.target_layout).toBe(source.target_layout);
    expect(fragment.priority).toBe(source.priority);
    expect(fragment.extension_point).toBeUndefined();
  });

  /**
   * @scenario part=envelope
   * @effects version_constraint_matches_bundled_plugin_version
   */
  it('버전 제약은 지금 번들된 플러그인 버전을 받아들이고 다음 마이너 판부터는 원본으로 되돌린다', () => {
    const constraint = fragment.version_constraint as string;
    const match = /^~(\d+)\.(\d+)\.(\d+)$/.exec(constraint);
    expect(match).not.toBeNull();
    const [major, minor, patch] = (pluginManifest as Node).version.split('.').map(Number);
    // ~X.Y.Z 는 X.Y.Z 이상, X.(Y+1).0 미만이다.
    expect([major, minor]).toEqual([Number(match![1]), Number(match![2])]);
    expect(patch).toBeGreaterThanOrEqual(Number(match![3]));
  });
});

describe('소셜 가입 창 판본 — 회원 항목 받기', () => {
  /**
   * @scenario part=init
   * @effects init_resets_field_state_to_loading
   */
  it('진입 때 항목 상태를 비우고 「받는 중」으로 둔다', () => {
    const reset = (fragment.init_actions as Node[])[0];
    expect(reset.handler).toBe('setState');
    expect(reset.params).toMatchObject({
      target: 'global',
      socialLoginFields: null,
      socialLoginFieldsVersion: null,
      socialLoginFieldForm: null,
      socialLoginFieldsState: 'loading',
      socialLoginErrors: null,
    });
  });

  /**
   * @scenario part=init
   * @effects fields_fetched_from_core_signup_api_with_pending_token
   */
  it('가입 대기를 받은 뒤 코어 가입 화면과 같은 API 에서 항목을 받는다 (대기 토큰과 출처를 넘긴다)', () => {
    expect(fieldsCall.target).toBe(FIELDS_URL);
    expect(fieldsCall.params.method).toBe('GET');
    expect(fieldsCall.auth_mode).toBe('optional');
    // 모달 열기는 원본과 같이 항목 조회보다 먼저다(조회를 기다리지 않는다).
    expect(pendingSuccess.map((action) => action.handler)).toEqual(['setState', 'openModal', 'openModal', 'apiCall']);
  });

  /**
   * @scenario part=init
   * @effects provider_defaults_seed_text_fields_only
   */
  it('받은 항목의 초기값 가운데 글 칸만 폼 값으로 옮긴다 (고르는 칸, 받지 않는 칸, 묶음 경로는 옮기지 않는다)', () => {
    const params = (fieldsCall.onSuccess as Node).params;
    expect(params.target).toBe('global');
    expect(params.merge).toBe('shallow');
    expect(params.socialLoginFields).toBe('{{response.data?.fields ?? []}}');
    expect(params.socialLoginFieldsVersion).toBe('{{response.data?.fields_version ?? null}}');

    const fields = sampleFields({
      name: { default: '라이언' },
      nickname: { default: '라이언' },
      job: { default: '기본 직업' },
      photo: { default: 'x' },
      marketing: { default: true },
    });
    const response = { data: { fields, fields_version: 'v-490' } };
    expect(evaluateBoth(params.socialLoginFieldForm, { response })).toEqual({ name: '라이언', nickname: '라이언' });
    expect(evaluateBoth(params.socialLoginFieldForm, { response: { data: { fields: sampleFields() } } })).toEqual({});
    expect(evaluateBoth(params.socialLoginFieldForm, { response: {} })).toEqual({});
    // 국가, 시간대처럼 고르는 위젯을 쓰는 글 칸도 입력 부품이 default 를 직접 보여 주므로 옮기지 않는다.
    const widget = sampleFields({ name: { default: 'KR', constraints: { widget: 'country' } } });
    expect(evaluateBoth(params.socialLoginFieldForm, { response: { data: { fields: widget } } })).toEqual({});
  });

  /**
   * @scenario part=init
   * @effects field_state_becomes_ready_or_failed
   */
  it('정의 버전까지 받았으면 ready, 받지 못했으면 failed 다', () => {
    const state = (fieldsCall.onSuccess as Node).params.socialLoginFieldsState;
    expect(evaluateBoth(state, { response: { data: { fields: [], fields_version: 'v-490' } } })).toBe('ready');
    expect(evaluateBoth(state, { response: { data: { fields: [] } } })).toBe('failed');
    expect(evaluateBoth(state, { response: {} })).toBe('failed');
    expect((fieldsCall.onError as Node).params).toEqual({ target: 'global', socialLoginFieldsState: 'failed' });
  });
});

describe('소셜 가입 창 판본 — 회원 항목 그리기', () => {
  /**
   * @scenario part=fields
   * @effects fields_region_binds_to_global_field_form_when_ready
   */
  it('항목 영역은 ready 일 때만 그리고 전역 폼 socialLoginFieldForm 에 자동 바인딩한다', () => {
    const region = byId(nodes, 'social_signup_member_fields');
    expect(region.dataKey).toBe('_global.socialLoginFieldForm');
    expect(evaluateBoth(region.if, { _global: readyState() })).toBe(true);
    expect(evaluateBoth(region.if, { _global: readyState({ socialLoginFieldsState: 'loading' }) })).toBe(false);
    expect(evaluateBoth(region.if, { _global: readyState({ socialLoginFieldsState: 'failed' }) })).toBe(false);

    const wrapper = (region.children as Node[])[0];
    expect(wrapper.iteration.item_var).toBe('memberFieldGroup');
    const groups = evaluateBoth(wrapper.iteration.source, { _global: readyState() });
    expect(groups).toHaveLength(1);
    expect(groups[0].key).toBe('signup');
    expect(groups[0].fields.map((field: Node) => field.key)).toEqual(['name', 'nickname', 'language', 'job', 'marketing', 'photo']);
    expect(evaluateBoth(wrapper.iteration.source, { _global: {} })[0].fields).toEqual([]);
  });

  /**
   * @scenario part=fields
   * @effects main_group_draws_fields_except_agreements
   */
  it('항목 묶음은 약관 묶음 항목을 빼고 가입 화면과 같은 순서로 그린다 (파일 항목은 안내 줄만)', () => {
    const region = byId(nodes, 'social_signup_member_fields');
    const wrapper = (region.children as Node[])[0];
    const group = (wrapper.children as Node[])[0];
    const row = (group.children as Node[])[0];
    expect(row.id).toBe('social_field_{{field.key}}');

    const memberFieldGroup = evaluate(wrapper.iteration.source, { _global: readyState() })[0];
    const rows = evaluateBoth(row.iteration.source, { memberFieldGroup });
    expect(rows.map((field: Node) => field.key)).toEqual(['name', 'nickname', 'language', 'job', 'photo']);

    const deferred = (row.children as Node[]).find((child) => child.id === 'social_mf_deferred_{{field.key}}')!;
    expect(deferred.text).toBe('$t:member.fields.upload_after_signup');
    const input = (row.children as Node[]).find((child) => child.name === 'MemberFieldInput')!;
    const photo = rows.find((field: Node) => field.key === 'photo');
    expect(evaluateBoth(deferred.if, { field: photo })).toBe(true);
    expect(evaluateBoth(input.if, { field: photo })).toBe(false);
    expect(evaluateBoth(input.if, { field: rows[0] })).toBe(true);
  });

  /**
   * @scenario part=agreements
   * @effects agreements_group_draws_only_agreement_fields_below_consents
   */
  it('약관 묶음 항목은 약관, 방침 동의 줄 아래 따로 그린다 (그 항목이 있을 때만)', () => {
    const region = byId(nodes, 'social_signup_member_agreements');
    expect(region.dataKey).toBe('_global.socialLoginFieldForm');
    expect(evaluateBoth(region.if, { _global: readyState() })).toBe(true);
    expect(evaluateBoth(region.if, { _global: readyState({ socialLoginFields: sampleFields().filter((field) => field.key !== 'marketing') }) })).toBe(false);
    expect(evaluateBoth(region.if, { _global: readyState({ socialLoginFieldsState: 'failed' }) })).toBe(false);

    const wrapper = (region.children as Node[])[0];
    const memberFieldGroup = evaluateBoth(wrapper.iteration.source, { _global: readyState() })[0];
    expect(memberFieldGroup).toMatchObject({ key: 'agreements', includeAgreements: true });
    const row = ((wrapper.children as Node[])[0].children as Node[])[0];
    expect(evaluateBoth(row.iteration.source, { memberFieldGroup }).map((field: Node) => field.key)).toEqual(['marketing']);

    // 창 안의 순서: 항목 묶음 → (이메일) → 약관, 방침 동의 줄 → 약관 묶음 항목 → 오류 줄 → 가입 단추
    const form = byId(nodes, 'social_signup_form');
    const order = (form.children as Node[]).map((child) => child.id ?? child.name);
    expect(order.indexOf('social_signup_member_fields')).toBeLessThan(order.indexOf('social_signup_email_section'));
    expect(order.indexOf('social_signup_member_agreements')).toBeGreaterThan(order.indexOf('social_signup_email_section'));
    expect(order.indexOf('social_signup_member_agreements')).toBeLessThan(order.indexOf('social_signup_error'));
    expect(order.indexOf('social_signup_error')).toBeLessThan(order.indexOf('social_signup_submit'));
  });

  /**
   * @scenario part=fields
   * @effects field_errors_show_under_each_field
   */
  it('칸별 검증 오류는 그 칸 아래에 붙는다 (묶음 경로의 오류 포함)', () => {
    const region = byId(nodes, 'social_signup_member_fields');
    const row = (((region.children as Node[])[0].children as Node[])[0].children as Node[])[0];
    const error = (row.children as Node[]).find((child) => child.id === 'social_mf_error_{{field.key}}')!;
    const input = (row.children as Node[]).find((child) => child.name === 'MemberFieldInput')!;
    expect(input.props.errorId).toBe('social_mf_error_{{field.key}}');
    expect(input.props.name).toBe('{{field.name}}');

    const job = sampleFields().find((field) => field.key === 'job')!;
    const _global = { socialLoginErrors: { 'custom_fields.job': ['직업 필드는 필수입니다.'], nickname: ['이미 사용 중인 닉네임입니다.'] } };
    expect(evaluateBoth(error.if, { field: job, _global })).toBe(true);
    expect(evaluateBoth(error.text, { field: job, _global })).toBe('직업 필드는 필수입니다.');
    expect(evaluateBoth(input.props.error, { field: job, _global })).toBe('직업 필드는 필수입니다.');
    const name = sampleFields()[0];
    expect(evaluateBoth(error.if, { field: name, _global })).toBe(false);
    expect(evaluateBoth(error.if, { field: name, _global: {} })).toBe(false);
    // 제출 중에는 칸을 잠근다
    expect(evaluateBoth(input.props.disabled, { _global: { socialLoginBusy: true } })).toBe(true);
    expect(evaluateBoth(input.props.disabled, { _global: {} })).toBe(false);
  });

  /**
   * @scenario part=fallback
   * @effects loading_line_and_original_inputs_follow_field_state
   */
  it('받는 동안은 안내 줄만, 받지 못했으면 원본의 이름, 닉네임 칸을 그린다', () => {
    const loading = byId(nodes, 'social_signup_fields_loading');
    expect(evaluateBoth(loading.if, { _global: { socialLoginFieldsState: 'loading' } })).toBe(true);
    expect(evaluateBoth(loading.if, { _global: readyState() })).toBe(false);

    const fallback = byId(nodes, 'social_signup_profile_fallback');
    const shown = { socialLoginFieldsState: 'failed', socialLoginSignup: { show_profile_fields: true } };
    expect(evaluateBoth(fallback.if, { _global: shown })).toBe(true);
    expect(evaluateBoth(fallback.if, { _global: { ...shown, socialLoginFieldsState: 'ready' } })).toBe(false);
    expect(evaluateBoth(fallback.if, { _global: { ...shown, socialLoginFieldsState: 'loading' } })).toBe(false);
    expect(evaluateBoth(fallback.if, { _global: { socialLoginFieldsState: 'failed', socialLoginSignup: { show_profile_fields: false } } })).toBe(false);

    // 안의 두 칸은 원본 그대로다
    const originalBlock = sourceNodes.find((node) => (node.children as Node[] | undefined)?.some((child) => (child.children as Node[] | undefined)?.some((input) => input.props?.id === 'social_signup_name')))!;
    expect(originalBlock).toBeDefined();
    expect(withoutComments(fallback.children)).toEqual(withoutComments(originalBlock.children));
  });
});

describe('소셜 가입 창 판본 — 가입 요청', () => {
  /**
   * @scenario part=submit
   * @effects submit_waits_for_fields
   */
  it('항목을 받는 동안에는 가입 단추를 잠근다 (원본의 잠금 조건은 그대로)', () => {
    const disabled = submit.props.disabled as string;
    const open = readyState();
    expect(evaluateBoth(disabled, { _global: open })).toBe(false);
    expect(evaluateBoth(disabled, { _global: { ...open, socialLoginFieldsState: 'loading' } })).toBe(true);
    expect(evaluateBoth(disabled, { _global: { ...open, socialLoginBusy: true } })).toBe(true);
    expect(evaluateBoth(disabled, { _global: { ...open, socialLoginForm: { agree_terms: true, agree_privacy: false } } })).toBe(true);
    expect(evaluateBoth(disabled, { _global: { ...open, socialLoginSignup: { email: { mode: 'input' } }, socialLoginEmailVerified: false } })).toBe(true);
  });

  /**
   * @scenario part=submit
   * @effects body_carries_original_keys_field_values_and_fields_version
   */
  it('본문은 「원본의 키 + 회원 항목 값 + fields_version」이다', () => {
    expect(completeCall.target).toBe('/api/plugins/sirsoft-social_login/signup/complete');
    expect(completeCall.params.method).toBe('POST');

    const body = evaluateBoth(completeCall.params.body, { _global: readyState(), query: { social_pending: 'tok' } });
    expect(body).toEqual({
      name: '홍길동',
      nickname: '길동',
      custom_fields: { job: '개발자' },
      agree_marketing_consent: true,
      pending_token: 'tok',
      email: 'kakao@example.com',
      agree_terms: true,
      agree_privacy: true,
      fields_version: 'v-490',
    });

    // 이메일을 입력받는 단계면 입력한 이메일을 싣는다(원본과 같다)
    const inputMode = readyState({ socialLoginSignup: { email: { mode: 'input', value: null } } });
    expect(evaluateBoth(completeCall.params.body, { _global: inputMode, query: { social_pending: 'tok' } }).email).toBe('typed@example.com');
  });

  /**
   * @scenario part=submit
   * @effects body_without_fields_is_the_original_body
   */
  it('항목을 받지 못한 창은 원본과 같은 본문을 보낸다 (fields_version 없음)', () => {
    const failed = readyState({ socialLoginFieldsState: 'failed', socialLoginFields: null, socialLoginFieldsVersion: null });
    const body = evaluateBoth(completeCall.params.body, { _global: failed, query: { social_pending: 'tok' } });
    expect(body).toEqual({
      pending_token: 'tok',
      name: '옛 이름 칸',
      nickname: '옛 닉네임 칸',
      email: 'kakao@example.com',
      agree_terms: true,
      agree_privacy: true,
    });

    // 원본 본문의 키와 같다
    const originalSubmit = byId(sourceNodes, 'social_signup_submit');
    const originalCall = ((originalSubmit.actions as Node[])[0].actions as Node[]).find((action) => action.handler === 'apiCall')!;
    expect(Object.keys(body).sort()).toEqual(Object.keys(originalCall.params.body).sort());
    expect(completeCall.identity_target).toEqual(originalCall.identity_target);
    expect(completeCall.auth_mode).toBe(originalCall.auth_mode);
  });

  /**
   * @scenario part=submit
   * @effects validation_errors_are_stored_per_field
   */
  it('422 면 칸별 오류와 응답 문구를 담는다', () => {
    const params = completeFail[0].params;
    expect(params.target).toBe('global');
    expect(params.socialLoginBusy).toBe(false);
    const error = { message: '직업 필드는 필수입니다.', errors: { 'custom_fields.job': ['직업 필드는 필수입니다.'] } };
    const $t = (key: string) => `t(${key})`;
    expect(evaluateBoth(params.socialLoginErrors, { error })).toEqual(error.errors);
    expect(evaluate(params.socialLoginSignupError, { error, $t })).toBe('직업 필드는 필수입니다.');
    expect(evaluateBoth(params.socialLoginSignupExpired, { error })).toBe(false);
    // 가입 대기가 만료됐으면 원본처럼 만료 안내로 바꾼다
    expect(evaluateBoth(params.socialLoginSignupExpired, { error: { message: '만료', errors: { code: 'expired' } } })).toBe(true);
    // 제출할 때 앞선 칸 오류를 지운다
    expect(submitSequence[0].params).toMatchObject({ socialLoginBusy: true, socialLoginSignupError: null, socialLoginErrors: null });
  });

  /**
   * @scenario part=submit
   * @effects fields_changed_refetches_fields_and_keeps_input
   */
  it('409(fields_changed)면 안내만 띄우고 항목과 정의 버전을 다시 받는다 (입력한 값은 그대로)', () => {
    const params = completeFail[0].params;
    const error = { message: '가입 항목이 바뀌었습니다', error_code: 'fields_changed', errors: { fields_version: 'v-491' } };
    const $t = (key: string) => `t(${key})`;
    expect(evaluateBoth(params.socialLoginErrors, { error })).toBeNull();
    expect(evaluate(params.socialLoginSignupError, { error, $t })).toBe('t(member.fields.signup_fields_changed)');
    // 응답이 error.response 아래에 실려 와도 같다
    expect(evaluate(params.socialLoginSignupError, { error: { response: error }, $t })).toBe('t(member.fields.signup_fields_changed)');

    const refetch = completeFail[1];
    expect(refetch.handler).toBe('apiCall');
    expect(refetch.target).toBe(FIELDS_URL);
    expect(evaluateBoth(refetch.if, { error })).toBe(true);
    expect(evaluateBoth(refetch.if, { error: { message: 'x', errors: {} } })).toBe(false);
    const refreshed = (refetch.onSuccess as Node).params;
    expect(Object.keys(refreshed).sort()).toEqual(['merge', 'socialLoginFields', 'socialLoginFieldsVersion', 'target']);
    expect(refreshed.merge).toBe('shallow');
  });
});

describe('소셜 가입 창 판본 — 원본 창의 동작 유지', () => {
  /**
   * @scenario part=original
   * @effects existing_account_modal_is_identical_to_original
   */
  it('이미 가입된 이메일 창(기존 계정 연결)은 원본과 글자까지 같다', () => {
    const mine = byId(nodes, 'social_existing_account');
    const theirs = byId(sourceNodes, 'social_existing_account');
    expect(mine).toEqual(theirs);
    // 창 둘이 원본과 같은 순서로 있다
    expect((fragment.modals as Node[]).map((modal) => modal.id)).toEqual((source.modals as Node[]).map((modal) => modal.id));
  });

  /**
   * @scenario part=original
   * @effects expiry_notice_and_email_verification_are_identical_to_original
   */
  it('만료 안내, 대체 이메일 안내, 이메일 인증번호 구획은 원본과 같다', () => {
    for (const id of ['social_signup_expired', 'social_signup_placeholder_notice', 'social_signup_email_section']) {
      expect(byId(nodes, id)).toEqual(byId(sourceNodes, id));
    }
    // 가입 완료 창의 봉투(제목, 크기, 배경 클릭)와 폼 표시 조건도 같다
    const mine = byId(nodes, 'social_signup_complete');
    const theirs = byId(sourceNodes, 'social_signup_complete');
    expect(mine.props).toEqual(theirs.props);
    expect(byId(nodes, 'social_signup_form').if).toBe(byId(sourceNodes, 'social_signup_form').if);
    expect(byId(nodes, 'social_signup_error')).toEqual(byId(sourceNodes, 'social_signup_error'));
  });

  /**
   * @scenario part=original
   * @effects locked_email_line_wraps_in_narrow_modal
   */
  it('잠긴 이메일 줄은 좁은 창에서 줄을 바꾼다 — 끊을 곳 없는 긴 주소가 창 본문을 가로로 밀지 않는다 (원본과 같다)', () => {
    const lockedLine = (list: Node[]) => list.find((node) => node.if === "{{_global.socialLoginSignup?.email?.mode === 'locked'}}")!;
    for (const list of [nodes, sourceNodes]) {
      const line = lockedLine(list);
      expect(line).toBeDefined();
      expect(String(line.props.className).split(' ')).toEqual(expect.arrayContaining(['sl:min-w-0', 'sl:break-all']));
    }
    expect(lockedLine(nodes)).toEqual(lockedLine(sourceNodes));
  });

  /**
   * @scenario part=original
   * @effects consent_rows_are_identical_to_original
   */
  it('약관, 방침 동의 줄(전문 보기 포함)은 원본과 같다', () => {
    const consentBlock = (list: Node[]) =>
      list.find((node) => (node.children as Node[] | undefined)?.some((row) => (row.children as Node[] | undefined)?.some((child) => child.props?.name === 'social_agree_terms')))!;
    expect(consentBlock(nodes)).toEqual(consentBlock(sourceNodes));
  });

  /**
   * @scenario part=original
   * @effects login_handoff_after_signup_is_identical_to_original
   */
  it('가입 성공 뒤의 로그인 인계(창 닫기, 인계 주소로 이동)는 원본과 같다', () => {
    const originalSubmit = byId(sourceNodes, 'social_signup_submit');
    const originalCall = ((originalSubmit.actions as Node[])[0].actions as Node[]).find((action) => action.handler === 'apiCall')!;
    expect(completeCall.onSuccess).toEqual(originalCall.onSuccess);
    expect(submit.props.className).toBe(originalSubmit.props.className);
    expect(submit.text).toBe(originalSubmit.text);
  });

  /**
   * @scenario part=original
   * @effects init_actions_keep_original_pending_fetch_and_code_timer
   */
  it('진입 액션은 원본의 가입 대기 조회, 단계별 모달 열기, 인증번호 타이머를 그대로 갖는다', () => {
    const mine = fragment.init_actions as Node[];
    const theirs = source.init_actions as Node[];
    expect(mine.map((action) => action.handler)).toEqual(theirs.map((action) => action.handler));

    // 상태 초기화는 원본의 키를 모두 갖고 항목 상태만 더했다
    expect(mine[0].params).toMatchObject(theirs[0].params);
    expect(mine[0].if).toBe(theirs[0].if);

    const originalPending = theirs.find((action) => action.handler === 'apiCall')!;
    expect(pendingCall.target).toBe(originalPending.target);
    expect(pendingCall.if).toBe(originalPending.if);
    expect(pendingCall.onError).toEqual(originalPending.onError);
    expect(withoutComments(pendingSuccess.slice(0, 3))).toEqual(withoutComments(originalPending.onSuccess));

    const timer = (list: Node[]) => list.find((action) => action.handler === 'startInterval');
    expect(timer(mine)).toEqual(timer(theirs));
  });
});
