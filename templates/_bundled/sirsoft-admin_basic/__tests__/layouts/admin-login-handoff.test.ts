/**
 * @file admin-login-handoff.test.ts
 * @description 관리자 로그인 인계 교환과 확장점 회귀 테스트 (sirsoft-admin_basic, 7.2.0 A1t ①②)
 *
 * - 외부 인증이 본인 확인을 마친 관리자를 `/admin/login?login_handoff={코드}` 로 보내면 `<init>` 이 `_local` 을 초기화한 **뒤**
 *   `loginHandoff`(target admin)로 코드를 교환한다(초기화가 교환 결과를 덮지 않게).
 * - 성공 분기는 이름 붙은 액션 `applyAdminLoginResult` 하나를 폼 로그인과 함께 쓴다(복제 금지 — 함정 3).
 *   2단계 인증·비밀번호 변경 응답이면 단계 상태만 심고 이동하지 않는다.
 * - 실패하면 안내 문구를 싣고 주소에서 한 번만 통하는 코드를 지운다.
 * - 확장이 다른 로그인 수단을 붙이는 확장점 `admin_login_extensions` 가 로그인 폼 바로 아래 있다.
 *
 * 조건식은 실제 값으로 평가해 갈래를 모두 밟는다.
 *
 * @since 1.2.0
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import adminLogin from '../../layouts/admin_login.json';

type Json = Record<string, any>;

/**
 * 트리를 평탄화합니다 (자식·슬롯·모달 포함).
 *
 * @param node 노드
 * @returns 노드 목록
 */
function flatten(node: any): Json[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  const slots = node.slots ? Object.values(node.slots).flatMap(flatten) : [];
  const modals = Array.isArray(node.modals) ? node.modals.flatMap(flatten) : [];
  return [node, ...children, ...slots, ...modals];
}

/**
 * 액션을 평탄화합니다 — 원소 하나짜리 배열은 객체로 접혀 굽히므로(D67) asArray 로 정규화한다.
 *
 * @param actions 액션(들)
 * @returns 액션 목록
 */
function flattenActions(actions: any): Json[] {
  if (!actions) return [];
  return asArray<Json>(actions).flatMap((a) => [
    a,
    ...flattenActions(a.actions),
    ...flattenActions(a.onSuccess),
    ...flattenActions(a.onError),
  ]);
}

/**
 * `{{식}}` 을 주어진 값으로 계산합니다.
 *
 * @param expr 식 문자열
 * @param scope 계산에 쓸 값
 * @returns 계산 결과
 */
function evaluate(expr: string, scope: Json): any {
  const body = String(expr).replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${body});`)(...Object.values(scope));
}

/**
 * 시퀀스 안에서 이 응답에 실행되는 액션만 고릅니다 (`if` 가 없거나 참인 것).
 *
 * @param actions 액션 목록
 * @param scope 계산에 쓸 값
 * @returns 실행되는 액션
 */
function firing(actions: Json[], scope: Json): Json[] {
  return actions.filter((a) => a.if === undefined || Boolean(evaluate(a.if, scope)));
}

const layout = adminLogin as Json;
const nodes = flatten(layout.components);
const initActions = asArray<Json>(layout.init_actions);
// 자동 이동(redirect)의 if 에도 `query?.login_handoff` 가 들어 있으므로 시퀀스로 고른다
const handoffSequence = initActions.find((a) => a.handler === 'sequence' && typeof a.if === 'string' && a.if.includes('query?.login_handoff'));
const handoff = flattenActions(handoffSequence).find((a) => a.handler === 'loginHandoff');
const form = nodes.find((n) => n.id === 'login_form_component');
const login = flattenActions(form?.actions).find((a) => a.handler === 'login');
const shared = layout.named_actions?.applyAdminLoginResult;
const sharedSteps = asArray<Json>(shared?.actions);

describe('sirsoft-admin_basic 관리자 로그인 인계 (A1t)', () => {
  /**
   * @scenario entry=handoff_query, step=init
   * @effects handoff_exchanged_after_local_reset
   */
  it('init 이 _local 을 초기화한 뒤에 login_handoff 코드를 admin 대상으로 교환한다', () => {
    const resetIndex = initActions.findIndex((a) => a.handler === 'setState' && a.params?.loginForm !== undefined);
    const handoffIndex = initActions.indexOf(handoffSequence as Json);

    expect(resetIndex).toBeGreaterThanOrEqual(0);
    expect(handoffIndex).toBeGreaterThan(resetIndex);
    expect(evaluate(handoffSequence?.if, { query: { login_handoff: 'abc' } })).toBe(true);
    expect(evaluate(handoffSequence?.if, { query: {} })).toBe(false);

    expect(handoff?.target).toBe('admin');
    expect(handoff?.params?.body?.code).toBe('{{query.login_handoff}}');

    // 교환 직전 진행 표시를 켜고 지난 오류를 비운다
    const before = asArray<Json>(handoffSequence?.actions)[0];
    expect(before?.handler).toBe('setState');
    expect(before?.params).toMatchObject({ target: 'local', isLoggingIn: true, loginError: null, loginErrors: null });
  });

  /**
   * @scenario entry=handoff_query|password_form, step=success
   * @effects login_result_branch_shared_not_duplicated
   */
  it('인계 교환과 폼 로그인이 같은 이름 붙은 액션 applyAdminLoginResult 를 쓰고 분기를 복제하지 않는다', () => {
    expect(shared).toBeDefined();
    expect(sharedSteps.length).toBeGreaterThan(0);

    const loginSuccess = asArray<Json>(login?.onSuccess);
    expect(loginSuccess).toEqual([{ actionRef: 'applyAdminLoginResult' }]);
    expect(asArray<Json>(handoff?.onSuccess).filter((a) => a.actionRef)).toEqual([{ actionRef: 'applyAdminLoginResult' }]);

    // 2단계 인증 분기(`response.two_factor_required`)는 이름 붙은 액션 밖 어디에도 다시 적혀 있지 않다
    const outside = [
      ...flattenActions(nodes.flatMap((n) => asArray(n.actions))),
      ...flattenActions(initActions),
    ];
    expect(outside.filter((a) => a.if === '{{response.two_factor_required}}')).toHaveLength(0);
    expect(outside.filter((a) => a.handler === 'navigate' && a.if === '{{!response.two_factor_required && !response.password_change_required}}')).toHaveLength(0);
  });

  /**
   * @scenario entry=handoff_query, step=success, response=two_factor|password_change|token
   * @effects handoff_two_factor_and_password_change_steps
   */
  it.each([
    ['two_factor', { two_factor_required: true, challenge_id: 'c-1', provider_id: 'g7:core.mail', expires_at: '2026-10-22T10:00:00Z' }],
    ['password_change', { password_change_required: true, challenge_id: 'p-1', reason: 'expired', can_postpone: false }],
    ['token', { dormancy_restored: true }],
  ])('응답 %s — 단계 상태를 심거나 이동한다', (kind, response) => {
    const scope = { response, query: { login_handoff: 'abc', redirect: '/admin/users' } };
    const fired = firing(sharedSteps, scope);
    const navigate = fired.find((a) => a.handler === 'navigate');

    if (kind === 'two_factor') {
      const step = fired.find((a) => a.params?.twoFactor);
      expect(step?.params?.twoFactor).toMatchObject({ required: true, challenge_id: '{{response.challenge_id}}', code: '' });
      expect(navigate).toBeUndefined();
      expect(fired.some((a) => a.params?.passwordChange)).toBe(false);
    } else if (kind === 'password_change') {
      const step = fired.find((a) => a.params?.passwordChange);
      expect(step?.params?.passwordChange).toMatchObject({ required: true, challenge_id: '{{response.challenge_id}}', password: '' });
      expect(navigate).toBeUndefined();
      expect(fired.some((a) => a.params?.twoFactor)).toBe(false);
    } else {
      expect(navigate?.params).toEqual({ path: '{{query.redirect}}', fallbackPath: '/admin' });
      expect(fired.find((a) => a.handler === 'toast')?.params?.message).toBe('$t:auth.dormancy_restored');
    }
    // 어느 갈래든 진행 표시는 끈다
    expect(fired.some((a) => a.handler === 'setState' && a.params?.isLoggingIn === false)).toBe(true);

    // 단계가 남은 응답이면 주소에서 인계 코드를 먼저 지운다(새로고침해도 코드를 다시 쓰지 않게)
    const strip = asArray<Json>(handoff?.onSuccess).find((a) => a.handler === 'replaceUrl');
    expect(Boolean(evaluate(strip?.if, scope))).toBe(kind !== 'token');
    expect(strip?.params?.path).toBe('/admin/login');
    expect(Object.keys(strip?.params?.query ?? {})).toEqual(['redirect']);
  });

  /**
   * @scenario entry=handoff_query, step=failure
   * @effects handoff_failure_shows_notice_and_strips_code
   */
  it('교환이 실패하면 안내를 싣고 주소에서 코드를 지운다(가려던 곳은 유지)', () => {
    const onError = asArray<Json>(handoff?.onError);
    const notice = onError.find((a) => a.handler === 'setState');
    const strip = onError.find((a) => a.handler === 'replaceUrl');

    expect(notice?.params).toMatchObject({
      target: 'local',
      isLoggingIn: false,
      loginError: '{{error.message}}',
      loginErrors: '{{error.errors}}',
    });
    expect(strip?.if).toBeUndefined();
    expect(strip?.params?.path).toBe('/admin/login');
    expect(Object.keys(strip?.params?.query ?? {})).not.toContain('login_handoff');
    expect(evaluate(strip?.params?.query?.redirect, { query: { login_handoff: 'abc' } })).toBeUndefined();
    expect(evaluate(strip?.params?.query?.redirect, { query: { login_handoff: 'abc', redirect: '/admin/users' } })).toBe('/admin/users');

    // 실린 안내는 로그인 폼 위 오류 자리에 보인다
    const box = nodes.find((n) => n.id === 'login_error_message');
    expect(Boolean(evaluate(box?.if, { _local: { loginError: '인계 코드가 만료되었습니다.' } }))).toBe(true);
  });

  /**
   * @scenario surface=login_card
   * @effects admin_login_extension_point_below_form
   */
  it('확장점 admin_login_extensions 가 로그인 폼 바로 아래에 있다', () => {
    const slot = nodes.find((n) => n.id === 'admin_login_extensions_slot');
    expect(slot?.type).toBe('extension_point');
    expect(slot?.name).toBe('admin_login_extensions');

    const card = nodes.find((n) => n.id === 'login_card_container');
    const order = asArray<Json>(card?.children).map((c) => c.id);
    expect(order.indexOf('admin_login_extensions_slot')).toBe(order.indexOf('login_form_component') + 1);
  });
});
