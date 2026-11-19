/**
 * @file sso-consent-modal-fields.test.ts
 * @description SSO 첫 로그인 동의 창(사용자 템플릿 판본)이 코어 가입 화면과 같은 회원 항목을 받고, 원본 조각의 동작을 그대로 갖는지
 *
 * extensions/sirsoft-sso/auth-login-sso-modals 는 SSO 플러그인의 같은 조각을 통째로 대체한다(템플릿 오버라이드).
 * 달라진 것은 동의 창이 약관과 함께 가입 항목 계약(/api/auth/register/fields)대로 칸을 그리고 받은 정의 버전(fields_version)을
 * 동의 완료 요청에 싣는 것뿐이다. 회사 계정이 관리하는 칸은 서버가 읽기 전용(locked_reason = sso)으로 준다.
 * 그 밖(오류 토스트, 만료 안내, 기존 회원 연결 확인 창, 로그인 인계)은 원본과 같아야 한다.
 *
 * @scenario-manifest tests/scenarios/sso-consent-modal-fields.yaml
 */

import { describe, it, expect } from 'vitest';

import override from '../../extensions/sirsoft-sso/auth-login-sso-modals.json';
import original from '../../../../../plugins/_bundled/sirsoft-sso/resources/extensions/auth-login-sso-modals.json';
import pluginManifest from '../../../../../plugins/_bundled/sirsoft-sso/plugin.json';
import { byId, collectFragment, evaluate, evaluateBoth, sampleFields, withoutComments, type Node } from './support/authModalOverrides';

const fragment = override as Node;
const source = original as Node;
const nodes = collectFragment(fragment);
const sourceNodes = collectFragment(source);

const FIELDS_URL = '/api/auth/register/fields?pending_source=sirsoft-sso&pending_token={{query.sso_pending}}';
const PENDING_URL = '/api/plugins/sirsoft-sso/pending/{{query.sso_pending}}';

const initActions = fragment.init_actions as Node[];
const pendingCall = initActions.find((action) => action.handler === 'apiCall' && action.target === PENDING_URL)!;
const pendingSuccess = pendingCall.onSuccess as Node[];
const fieldsCall = pendingSuccess.find((action) => action.handler === 'apiCall')!;

const submit = byId(nodes, 'sso_consent_submit');
const submitSequence = (submit.actions as Node[])[0].actions as Node[];
const completeCall = submitSequence.find((action) => action.handler === 'apiCall')!;
const completeFail = completeCall.onError as Node[];

/** 창의 전역 상태 표본 — 항목을 받은 상태. 닉네임은 회사 계정이 관리한다(읽기 전용) */
function readyState(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    ssoFieldsState: 'ready',
    ssoFields: sampleFields({ nickname: { default: '김직원', readonly: true, locked_reason: 'sso' } }),
    ssoFieldsVersion: 'v-490',
    ssoFieldForm: { name: '김직원', nickname: '김직원', custom_fields: { job: '개발자' } },
    ssoForm: { agree_terms: true, agree_privacy: true },
    ssoPending: { idp: { key: 'corp', label: '회사 계정' }, name: '김직원', email: 'k***@corp.test' },
    ...overrides,
  };
}

describe('SSO 동의 창 판본 — 대체 대상과 버전 제약', () => {
  /**
   * @scenario part=envelope
   * @effects override_targets_same_layout_and_priority_as_original
   */
  it('원본과 같은 대상(auth/login)과 우선순위를 갖는다', () => {
    expect(fragment.target_layout).toBe('auth/login');
    expect(fragment.target_layout).toBe(source.target_layout);
    expect(fragment.priority).toBe(source.priority);
    expect(fragment.extension_point).toBeUndefined();
  });

  /**
   * @scenario part=envelope
   * @effects version_constraint_matches_bundled_plugin_version
   */
  it('버전 제약은 지금 번들된 플러그인 버전을 받아들이고 다음 마이너 판부터는 원본으로 되돌린다', () => {
    const match = /^~(\d+)\.(\d+)\.(\d+)$/.exec(fragment.version_constraint as string);
    expect(match).not.toBeNull();
    const [major, minor, patch] = (pluginManifest as Node).version.split('.').map(Number);
    expect([major, minor]).toEqual([Number(match![1]), Number(match![2])]);
    expect(patch).toBeGreaterThanOrEqual(Number(match![3]));
  });
});

describe('SSO 동의 창 판본 — 회원 항목 받기', () => {
  /**
   * @scenario part=init
   * @effects init_resets_field_state_to_loading
   */
  it('동의 대기로 들어오면 항목 상태를 비우고 「받는 중」으로 둔다', () => {
    const reset = initActions.find((action) => action.handler === 'setState' && action.if === '{{!!query.sso_pending}}')!;
    expect(reset.params).toMatchObject({
      target: 'global',
      ssoFields: null,
      ssoFieldsVersion: null,
      ssoFieldForm: null,
      ssoFieldsState: 'loading',
      ssoErrors: null,
    });
  });

  /**
   * @scenario part=init
   * @effects fields_fetched_from_core_signup_api_with_pending_token
   */
  it('동의 대기를 받은 뒤 코어 가입 화면과 같은 API 에서 항목을 받는다 (대기 토큰과 출처를 넘긴다)', () => {
    expect(fieldsCall.target).toBe(FIELDS_URL);
    expect(fieldsCall.params.method).toBe('GET');
    expect(fieldsCall.auth_mode).toBe('optional');
    // 모달 열기는 원본과 같이 항목 조회보다 먼저다.
    expect(pendingSuccess.map((action) => action.handler)).toEqual(['setState', 'openModal', 'apiCall']);
  });

  /**
   * @scenario part=init
   * @effects idp_defaults_seed_text_fields_only
   */
  it('회사 계정이 준 초기값 가운데 글 칸만 폼 값으로 옮긴다 — 읽기 전용 칸의 값도 이렇게 보인다', () => {
    const params = (fieldsCall.onSuccess as Node).params;
    expect(params.merge).toBe('shallow');
    const fields = sampleFields({
      name: { default: '김직원' },
      nickname: { default: '회사닉', readonly: true, locked_reason: 'sso' },
      marketing: { default: true },
    });
    expect(evaluateBoth(params.ssoFieldForm, { response: { data: { fields, fields_version: 'v' } } })).toEqual({ name: '김직원', nickname: '회사닉' });
    expect(evaluateBoth(params.ssoFieldForm, { response: {} })).toEqual({});

    expect(evaluateBoth(params.ssoFieldsState, { response: { data: { fields, fields_version: 'v' } } })).toBe('ready');
    expect(evaluateBoth(params.ssoFieldsState, { response: { data: { fields } } })).toBe('failed');
    expect((fieldsCall.onError as Node).params).toEqual({ target: 'global', ssoFieldsState: 'failed' });
  });
});

describe('SSO 동의 창 판본 — 회원 항목 그리기', () => {
  /**
   * @scenario part=fields
   * @effects fields_region_binds_to_global_field_form_when_ready
   */
  it('항목 영역은 ready 일 때만 그리고 전역 폼 ssoFieldForm 에 자동 바인딩한다', () => {
    const region = byId(nodes, 'sso_consent_member_fields');
    expect(region.dataKey).toBe('_global.ssoFieldForm');
    expect(evaluateBoth(region.if, { _global: readyState() })).toBe(true);
    expect(evaluateBoth(region.if, { _global: readyState({ ssoFieldsState: 'loading' }) })).toBe(false);
    expect(evaluateBoth(region.if, { _global: readyState({ ssoFieldsState: 'failed' }) })).toBe(false);

    const wrapper = (region.children as Node[])[0];
    const memberFieldGroup = evaluateBoth(wrapper.iteration.source, { _global: readyState() })[0];
    expect(memberFieldGroup.key).toBe('signup');
    const row = ((wrapper.children as Node[])[0].children as Node[])[0];
    expect(row.id).toBe('sso_field_{{field.key}}');
    expect(evaluateBoth(row.iteration.source, { memberFieldGroup }).map((field: Node) => field.key)).toEqual(['name', 'nickname', 'language', 'job', 'photo']);
  });

  /**
   * @scenario part=locked
   * @effects idp_managed_field_shows_company_notice_and_readonly_contract
   */
  it('회사 계정이 관리하는 칸은 항목 계약 그대로 입력 부품에 넘어가고(읽기 전용) 「회사 계정에서 관리」 안내가 붙는다', () => {
    const region = byId(nodes, 'sso_consent_member_fields');
    const row = (((region.children as Node[])[0].children as Node[])[0].children as Node[])[0];
    const input = (row.children as Node[]).find((child) => child.name === 'MemberFieldInput')!;
    // 읽기 전용 판정은 입력 부품이 field.readonly 로 한다 — 계약을 통째로 넘긴다.
    expect(input.props.field).toBe('{{field}}');

    const notice = (row.children as Node[]).find((child) => child.id === 'sso_mf_locked_sso_{{field.key}}')!;
    expect(notice.text).toBe('$t:mypage.profile.locked_by_company');
    const locked = { key: 'nickname', readonly: true, locked_reason: 'sso' };
    expect(evaluateBoth(notice.if, { field: locked })).toBe(true);
    expect(evaluateBoth(notice.if, { field: { key: 'name', readonly: false, locked_reason: null } })).toBe(false);
    // 사유별 안내는 한 번에 하나만 보인다
    const notices = (row.children as Node[]).filter((child) => typeof child.id === 'string' && child.id.startsWith('sso_mf_locked_'));
    expect(notices.filter((child) => evaluate(child.if, { field: locked }) === true)).toHaveLength(1);
  });

  /**
   * @scenario part=agreements
   * @effects agreements_group_draws_only_agreement_fields_below_consents
   */
  it('약관 묶음 항목은 약관, 방침 동의 줄 아래 따로 그린다 (그 항목이 있을 때만)', () => {
    const region = byId(nodes, 'sso_consent_member_agreements');
    expect(region.dataKey).toBe('_global.ssoFieldForm');
    expect(evaluateBoth(region.if, { _global: readyState() })).toBe(true);
    expect(evaluateBoth(region.if, { _global: readyState({ ssoFields: sampleFields().filter((field) => field.key !== 'marketing') }) })).toBe(false);

    const wrapper = (region.children as Node[])[0];
    const memberFieldGroup = evaluateBoth(wrapper.iteration.source, { _global: readyState() })[0];
    expect(memberFieldGroup).toMatchObject({ key: 'agreements', includeAgreements: true });
    const row = ((wrapper.children as Node[])[0].children as Node[])[0];
    expect(evaluateBoth(row.iteration.source, { memberFieldGroup }).map((field: Node) => field.key)).toEqual(['marketing']);

    const form = byId(nodes, 'sso_consent_form');
    const order = (form.children as Node[]).map((child) => child.id ?? child.name);
    expect(order.indexOf('sso_consent_member_fields')).toBeLessThan(order.indexOf('sso_consent_member_agreements'));
    expect(order.indexOf('sso_consent_member_agreements')).toBeLessThan(order.indexOf('sso_consent_error'));
    expect(order.indexOf('sso_consent_error')).toBeLessThan(order.indexOf('sso_consent_submit'));
  });

  /**
   * @scenario part=fields
   * @effects field_errors_show_under_each_field
   */
  it('칸별 검증 오류는 그 칸 아래에 붙는다', () => {
    const region = byId(nodes, 'sso_consent_member_fields');
    const row = (((region.children as Node[])[0].children as Node[])[0].children as Node[])[0];
    const error = (row.children as Node[]).find((child) => child.id === 'sso_mf_error_{{field.key}}')!;
    const input = (row.children as Node[]).find((child) => child.name === 'MemberFieldInput')!;
    expect(input.props.errorId).toBe('sso_mf_error_{{field.key}}');

    const job = sampleFields().find((field) => field.key === 'job')!;
    const _global = { ssoErrors: { 'custom_fields.job': ['직업 필드는 필수입니다.'] } };
    expect(evaluateBoth(error.if, { field: job, _global })).toBe(true);
    expect(evaluateBoth(error.text, { field: job, _global })).toBe('직업 필드는 필수입니다.');
    expect(evaluateBoth(error.if, { field: sampleFields()[0], _global })).toBe(false);
    expect(evaluateBoth(input.props.disabled, { _global: { ssoBusy: true } })).toBe(true);
  });

  /**
   * @scenario part=fallback
   * @effects loading_line_and_name_summary_follow_field_state
   */
  it('받는 동안은 안내 줄을 보이고, 이름 요약 줄은 항목을 그릴 때만 숨긴다 (이름이 항목 칸으로 보이므로)', () => {
    const loading = byId(nodes, 'sso_consent_fields_loading');
    expect(evaluateBoth(loading.if, { _global: { ssoFieldsState: 'loading' } })).toBe(true);
    expect(evaluateBoth(loading.if, { _global: readyState() })).toBe(false);

    const nameRows = nodes.filter((node) => typeof node.if === 'string' && node.if.includes('_global.ssoPending?.name'));
    expect(nameRows).toHaveLength(2);
    for (const row of nameRows) {
      expect(evaluateBoth(row.if, { _global: readyState() })).toBe(false);
      expect(evaluateBoth(row.if, { _global: readyState({ ssoFieldsState: 'failed' }) })).toBe(true);
      expect(evaluateBoth(row.if, { _global: readyState({ ssoFieldsState: 'loading' }) })).toBe(true);
    }
    // 이메일 요약 줄은 원본 그대로다(이메일은 회원 항목이 아니다)
    const emailRows = (list: Node[]) => list.filter((node) => node.if === '{{!!_global.ssoPending?.email}}');
    expect(emailRows(nodes)).toEqual(emailRows(sourceNodes));
    expect(emailRows(nodes)).toHaveLength(2);
  });

  /**
   * @scenario part=summary
   * @effects summary_values_wrap_in_narrow_modal
   */
  it('요약 줄(이름, 이메일)의 값 칸은 좁은 창에서 줄을 바꾼다 — 끊을 곳 없는 긴 이메일이 창 본문을 가로로 밀지 않는다 (원본 조각도 같다)', () => {
    for (const list of [nodes, sourceNodes]) {
      // 격자(3칸)의 값 칸 — 칸 안에서 줄을 바꾸려면 격자 칸의 최소 폭(내용 폭)을 풀고 글자 단위로 끊는다
      const values = list.filter(
        (node) => typeof node.if === 'string' && /_global\.ssoPending\?\.(name|email)/.test(node.if) && String(node.props?.className ?? '').includes('sso:col-span-2'),
      );
      expect(values).toHaveLength(2);
      for (const value of values) {
        expect(String(value.props.className).split(' ')).toEqual(expect.arrayContaining(['sso:min-w-0', 'sso:break-all']));
      }
    }
  });
});

describe('SSO 동의 창 판본 — 동의 완료 요청', () => {
  /**
   * @scenario part=submit
   * @effects submit_waits_for_fields
   */
  it('항목을 받는 동안에는 단추를 잠근다 (원본의 잠금 조건은 그대로)', () => {
    const disabled = submit.props.disabled as string;
    expect(evaluateBoth(disabled, { _global: readyState() })).toBe(false);
    expect(evaluateBoth(disabled, { _global: readyState({ ssoFieldsState: 'loading' }) })).toBe(true);
    expect(evaluateBoth(disabled, { _global: readyState({ ssoBusy: true }) })).toBe(true);
    expect(evaluateBoth(disabled, { _global: readyState({ ssoForm: { agree_terms: true, agree_privacy: false } }) })).toBe(true);
  });

  /**
   * @scenario part=submit
   * @effects body_carries_original_keys_field_values_and_fields_version
   */
  it('본문은 「약관 동의 + 회원 항목 값 + fields_version」이다', () => {
    expect(completeCall.target).toBe('/api/plugins/sirsoft-sso/pending/{{query.sso_pending}}/complete');
    expect(completeCall.params.method).toBe('POST');
    expect(evaluateBoth(completeCall.params.body, { _global: readyState() })).toEqual({
      name: '김직원',
      nickname: '김직원',
      custom_fields: { job: '개발자' },
      agree_terms: true,
      agree_privacy: true,
      fields_version: 'v-490',
    });
  });

  /**
   * @scenario part=submit
   * @effects body_without_fields_is_the_original_body
   */
  it('항목을 받지 못한 창은 원본과 같은 본문(약관 둘)을 보낸다', () => {
    const failed = readyState({ ssoFieldsState: 'failed', ssoFields: null, ssoFieldsVersion: null });
    const body = evaluateBoth(completeCall.params.body, { _global: failed });
    expect(body).toEqual({ agree_terms: true, agree_privacy: true });

    const originalSubmit = byId(sourceNodes, 'sso_consent_submit');
    const originalCall = ((originalSubmit.actions as Node[])[0].actions as Node[]).find((action) => action.handler === 'apiCall')!;
    expect(Object.keys(body).sort()).toEqual(Object.keys(originalCall.params.body).sort());
    expect(completeCall.target).toBe(originalCall.target);
  });

  /**
   * @scenario part=submit
   * @effects validation_errors_are_stored_per_field
   */
  it('422 면 칸별 오류와 응답 문구를 담는다', () => {
    const params = completeFail[0].params;
    const error = { message: '직업 필드는 필수입니다.', errors: { 'custom_fields.job': ['직업 필드는 필수입니다.'] } };
    const $t = (key: string) => `t(${key})`;
    expect(params.ssoBusy).toBe(false);
    expect(evaluateBoth(params.ssoErrors, { error })).toEqual(error.errors);
    expect(evaluate(params.ssoError, { error, $t })).toBe('직업 필드는 필수입니다.');
    expect(submitSequence[0].params).toMatchObject({ ssoBusy: true, ssoError: null, ssoErrors: null });
  });

  /**
   * @scenario part=submit
   * @effects fields_changed_refetches_fields_and_keeps_input
   */
  it('409(fields_changed)면 안내만 띄우고 항목과 정의 버전을 다시 받는다 (입력한 값은 그대로)', () => {
    const params = completeFail[0].params;
    const error = { message: '가입 항목이 바뀌었습니다', error_code: 'fields_changed' };
    const $t = (key: string) => `t(${key})`;
    expect(evaluateBoth(params.ssoErrors, { error })).toBeNull();
    expect(evaluate(params.ssoError, { error, $t })).toBe('t(member.fields.signup_fields_changed)');

    const refetch = completeFail[1];
    expect(refetch.target).toBe(FIELDS_URL);
    expect(evaluateBoth(refetch.if, { error })).toBe(true);
    expect(evaluateBoth(refetch.if, { error: { message: 'x' } })).toBe(false);
    expect(Object.keys((refetch.onSuccess as Node).params).sort()).toEqual(['merge', 'ssoFields', 'ssoFieldsVersion', 'target']);
  });
});

describe('SSO 동의 창 판본 — 원본 조각의 동작 유지', () => {
  /**
   * @scenario part=original
   * @effects link_verify_modal_is_identical_to_original
   */
  it('기존 회원 연결 확인 창은 원본과 글자까지 같다', () => {
    expect(byId(nodes, 'sso_link_verify')).toEqual(byId(sourceNodes, 'sso_link_verify'));
    expect((fragment.modals as Node[]).map((modal) => modal.id)).toEqual((source.modals as Node[]).map((modal) => modal.id));
  });

  /**
   * @scenario part=original
   * @effects expiry_notice_and_consent_rows_are_identical_to_original
   */
  it('만료 안내, 약관과 방침 동의 줄, 오류 줄은 원본과 같다', () => {
    expect(byId(nodes, 'sso_consent_expired')).toEqual(byId(sourceNodes, 'sso_consent_expired'));
    expect(byId(nodes, 'sso_consent_error')).toEqual(byId(sourceNodes, 'sso_consent_error'));
    expect(byId(nodes, 'sso_consent').props).toEqual(byId(sourceNodes, 'sso_consent').props);
    expect(byId(nodes, 'sso_consent_form').if).toBe(byId(sourceNodes, 'sso_consent_form').if);

    const consentBlock = (list: Node[]) =>
      list.find((node) => (node.children as Node[] | undefined)?.some((row) => (row.children as Node[] | undefined)?.some((child) => child.props?.name === 'sso_agree_terms')))!;
    expect(consentBlock(nodes)).toBeDefined();
    expect(consentBlock(nodes)).toEqual(consentBlock(sourceNodes));
  });

  /**
   * @scenario part=original
   * @effects login_handoff_after_consent_is_identical_to_original
   */
  it('동의 완료 뒤의 로그인 인계(인계 코드로 loginHandoff, 로그인 응답 분기)는 원본과 같다', () => {
    const originalSubmit = byId(sourceNodes, 'sso_consent_submit');
    const originalCall = ((originalSubmit.actions as Node[])[0].actions as Node[]).find((action) => action.handler === 'apiCall')!;
    expect(completeCall.onSuccess).toEqual(originalCall.onSuccess);
    expect(JSON.stringify(completeCall.onSuccess)).toContain('loginHandoff');
    expect(JSON.stringify(completeCall.onSuccess)).toContain('applyLoginResult');
    expect(submit.props.className).toBe(originalSubmit.props.className);
    expect(submit.text).toBe(originalSubmit.text);
  });

  /**
   * @scenario part=original
   * @effects init_actions_keep_original_error_toast_and_link_fetch
   */
  it('진입 액션은 원본의 오류 토스트, 동의 대기 조회, 연결 확인 대기 조회를 그대로 갖는다', () => {
    const theirs = source.init_actions as Node[];
    expect(initActions.map((action) => action.handler)).toEqual(theirs.map((action) => action.handler));

    // 오류 토스트와 연결 확인 쪽 두 액션은 원본과 같다
    const untouched = (list: Node[]) => list.filter((action) => action.handler === 'toast' || String(action.if).includes('query.sso_link'));
    expect(untouched(initActions)).toEqual(untouched(theirs));
    expect(untouched(initActions)).toHaveLength(3);

    // 동의 대기 쪽은 원본의 키를 모두 갖고 항목 상태와 항목 조회만 더했다
    const originalReset = theirs.find((action) => action.handler === 'setState' && action.if === '{{!!query.sso_pending}}')!;
    const reset = initActions.find((action) => action.handler === 'setState' && action.if === '{{!!query.sso_pending}}')!;
    expect(reset.params).toMatchObject(originalReset.params);

    const originalPending = theirs.find((action) => action.handler === 'apiCall' && action.target === PENDING_URL)!;
    expect(pendingCall.if).toBe(originalPending.if);
    expect(pendingCall.onError).toEqual(originalPending.onError);
    expect(withoutComments(pendingSuccess.slice(0, 2))).toEqual(withoutComments(originalPending.onSuccess));
  });
});
