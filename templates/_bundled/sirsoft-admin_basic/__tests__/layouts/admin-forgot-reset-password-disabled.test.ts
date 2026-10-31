/**
 * @file admin-forgot-reset-password-disabled.test.ts
 * @description 관리자 비밀번호 찾기·재설정의 「회사 계정으로 로그인」 안내 회귀 테스트 (sirsoft-admin_basic, 7.2.0 A5t)
 *
 * 회사 계정 로그인이 강제된 관리자(422 `auth.password_auth_disabled`)는 재설정 메일을 받을 수 없다. 칸 오류가 아니라 일반 안내로 보이고,
 * 서버가 준 수단 주소가 있으면 그리로 가는 버튼(`forgotAlternate*`·`resetAlternate*`)을 단다.
 * 재설정 화면은 링크 검증(422)에서 같은 사유가 오면 「링크 무효」가 아니라 같은 안내로 보인다. 재설정 화면의 안내는 일반 오류 상자와
 * 나뉜 막힘 안내 상자(`reset_blocked_notice`, 문구 `resetBlockedMessage`)다 — 일반 오류(`resetError`)는 화면 init 이 비우는데 링크 검증
 * 처리기는 init 보다 먼저 돌기 때문이다(렌더 회귀는 admin-reset-password-blocked-render.test.tsx).
 * 안내 데이터는 오류 문맥의 `error.errors.*` 로만 읽는다(`error.data` 는 바인딩 경로가 아니다).
 *
 * @since 1.2.0
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import forgotLayout from '../../layouts/admin_forgot_password.json';
import resetLayout from '../../layouts/admin_reset_password.json';

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
 * 실패 갈래를 차례로 적용해 `_local` 결과를 만듭니다 (`if` 가 참인 setState 만, 값은 계산해서).
 *
 * @param onError 실패 갈래 액션
 * @param error 오류 문맥
 * @returns 적용 뒤 `_local`
 */
function applyFailure(onError: Json[], error: Json): Json {
  // 운영의 실패 갈래·데이터소스 오류 처리기 문맥에는 `$locale` 이 없다 — 엔진이 늘 주는 `$localized` 만 싣는다
  const scope = { error, $localized: (value: any) => (value && typeof value === 'object' ? (value.ko ?? '') : (value ?? '')) };
  const local: Json = {};
  for (const action of onError) {
    if (action.handler !== 'setState') continue;
    if (action.if !== undefined && !evaluate(action.if, scope)) continue;
    for (const [key, value] of Object.entries(action.params ?? {})) {
      if (key === 'target') continue;
      local[key] = typeof value === 'string' && value.startsWith('{{') ? evaluate(value, scope) : value;
    }
  }
  return local;
}

const DISABLED = {
  message: '회사 계정으로 로그인하는 계정입니다.',
  errors: { code: 'auth.password_auth_disabled', redirect_url: '/plugins/sirsoft-sso/login/corp', method_label: { ko: '사내 계정', en: 'Corp' } },
};
const FIELD = { message: '입력값을 확인해 주세요.', errors: { email: ['이메일 형식이 아닙니다.'] } };

const surfaces = [
  {
    name: 'forgot',
    layout: forgotLayout as Json,
    form: 'forgot_form',
    target: '/api/auth/forgot-password',
    message: 'forgot_error_message',
    button: 'forgot_alternate_button',
    keys: { error: 'forgotError', errors: 'forgotErrors', url: 'forgotAlternateUrl', label: 'forgotAlternateLabel' },
  },
  {
    name: 'reset',
    layout: resetLayout as Json,
    form: 'reset_form',
    target: '/api/auth/reset-password',
    message: 'reset_blocked_notice',
    button: 'reset_alternate_button',
    keys: { error: 'resetBlockedMessage', errors: 'resetErrors', url: 'resetAlternateUrl', label: 'resetAlternateLabel' },
  },
];

describe('sirsoft-admin_basic 관리자 비밀번호 찾기·재설정 — 비밀번호 인증 막힘 안내 (A5t)', () => {
  /**
   * @scenario surface=forgot|reset, response=422_password_auth_disabled
   * @effects password_disabled_shows_notice_and_alternate_button
   */
  it.each(surfaces)('$name — 422 auth.password_auth_disabled 는 칸 오류가 아닌 안내 + 이동 버튼이다', (surface) => {
    const nodes = flatten(surface.layout.components);
    const form = nodes.find((n) => n.id === surface.form);
    const request = flattenActions(form?.actions).find((a) => a.handler === 'apiCall');
    expect(request?.target).toBe(surface.target);

    const local = applyFailure(asArray<Json>(request?.onError), DISABLED);
    expect(local[surface.keys.error]).toBe(DISABLED.message);
    expect(local[surface.keys.errors]).toBeNull();
    expect(local[surface.keys.url]).toBe('/plugins/sirsoft-sso/login/corp');
    expect(local[surface.keys.label]).toBe('사내 계정');

    const box = nodes.find((n) => n.id === surface.message);
    const button = nodes.find((n) => n.id === surface.button);
    expect(Boolean(evaluate(box?.if, { _local: local }))).toBe(true);
    expect(Boolean(evaluate(button?.if, { _local: local }))).toBe(true);
    expect(asArray<Json>(box?.children).map((c) => c.id)).toContain(surface.button);

    const go = asArray<Json>(button?.actions)[0];
    expect(go?.handler).toBe('redirect');
    expect(evaluate(go?.params?.url, { _local: local })).toBe('/plugins/sirsoft-sso/login/corp');

    const labels = asArray<Json>(button?.children);
    expect(labels.find((c) => Boolean(evaluate(c.if, { _local: local })))?.text).toBe(
      `$t:auth.login.external.login_with|method={{_local.${surface.keys.label}}}`,
    );
    expect(labels.find((c) => Boolean(evaluate(c.if, { _local: { ...local, [surface.keys.label]: null } })))?.text).toBe(
      '$t:auth.login.external.login_with_company',
    );
  });

  /**
   * @scenario surface=forgot|reset, response=422_field_error
   * @effects other_failures_keep_field_errors
   */
  it.each(surfaces)('$name — 다른 422 는 종전대로 칸 오류이고 이동 버튼이 없다', (surface) => {
    const nodes = flatten(surface.layout.components);
    const form = nodes.find((n) => n.id === surface.form);
    const request = flattenActions(form?.actions).find((a) => a.handler === 'apiCall');

    const local = applyFailure(asArray<Json>(request?.onError), FIELD);
    expect(local[surface.keys.errors]).toEqual(FIELD.errors);
    expect(local[surface.keys.url]).toBeUndefined();
    expect(Boolean(evaluate(nodes.find((n) => n.id === surface.button)?.if, { _local: local }))).toBe(false);

    // 다시 제출하면(찾기 화면) 지난 이동 버튼을 지운다
    if (surface.name === 'forgot') {
      const start = asArray<Json>(asArray<Json>(form?.actions)[0]?.actions)[0];
      expect(start?.params).toMatchObject({ forgotAlternateUrl: null, forgotAlternateLabel: null });
    }
  });

  /**
   * @scenario surface=reset, step=token_check, response=422_password_auth_disabled|422_invalid
   * @effects reset_token_check_password_disabled_not_invalid_link
   */
  it.each([
    ['password_auth_disabled', DISABLED, false],
    ['invalid_token', { message: '링크가 유효하지 않습니다.', errors: { token: ['invalid'] } }, true],
  ])('재설정 링크 검증 422 %s — 「링크 무효」=%s', (_kind, error, tokenError) => {
    const source = asArray<Json>(resetLayout.data_sources).find((s) => s.id === 'tokenValidation');
    const handler = source?.errorHandling?.['422'];
    const local = applyFailure([handler], error);

    expect(local.tokenError).toBe(tokenError);
    expect(local.resetBlockedMessage).toBe(tokenError ? null : DISABLED.message);
    expect(local.resetError).toBeUndefined();
    expect(local.resetAlternateUrl).toBe(tokenError ? null : '/plugins/sirsoft-sso/login/corp');
    expect(local.resetAlternateLabel).toBe(tokenError ? null : '사내 계정');

    const nodes = flatten(resetLayout.components);
    expect(Boolean(evaluate(nodes.find((n) => n.id === 'reset_token_error')?.if, { _local: local }))).toBe(tokenError);
    expect(Boolean(evaluate(nodes.find((n) => n.id === 'reset_blocked_notice')?.if, { _local: local }))).toBe(!tokenError);
  });

  /**
   * 비밀번호 인증이 막힌 계정이면 재설정 폼의 비밀번호 칸·제출 버튼을 숨기고 안내·이동 버튼만 남긴다 — 칸이 보이면 제출해도 같은 422 만
   * 되풀이된다(사용자 템플릿 재설정 화면의 passwordAuthBlocked 와 같은 동작).
   *
   * @scenario surface=reset, step=token_check|submit, response=422_password_auth_disabled|422_invalid
   * @effects reset_blocked_hides_password_fields
   */
  it('재설정 — 막힌 계정이면 비밀번호 칸·제출 버튼을 숨기고 안내만 남긴다(링크 검증·제출 실패 모두)', () => {
    const nodes = flatten(resetLayout.components);
    const hidden = ['reset_password_field', 'reset_password_confirm_field', 'reset_submit_button'];
    const visible = (local: Json) => hidden.map((id) => Boolean(evaluate(nodes.find((n) => n.id === id)?.if ?? 'true', { _local: local })));

    const source = asArray<Json>(resetLayout.data_sources).find((s) => s.id === 'tokenValidation');
    const blockedOnCheck = applyFailure([source?.errorHandling?.['422']], DISABLED);
    expect(blockedOnCheck.resetBlocked).toBe(true);
    expect(visible(blockedOnCheck)).toEqual([false, false, false]);
    expect(Boolean(evaluate(nodes.find((n) => n.id === 'reset_alternate_button')?.if, { _local: blockedOnCheck }))).toBe(true);

    const request = flattenActions(nodes.find((n) => n.id === 'reset_form')?.actions).find((a) => a.handler === 'apiCall');
    const blockedOnSubmit = applyFailure(asArray<Json>(request?.onError), DISABLED);
    expect(blockedOnSubmit.resetBlocked).toBe(true);
    expect(visible(blockedOnSubmit)).toEqual([false, false, false]);

    // 일반 칸 오류·정상 화면은 칸이 그대로다
    expect(visible(applyFailure(asArray<Json>(request?.onError), FIELD))).toEqual([true, true, true]);
    expect(visible({})).toEqual([true, true, true]);
  });

  /**
   * @scenario surface=forgot|reset
   * @effects guidance_reads_error_errors_only
   */
  it.each(surfaces)('$name — 안내 데이터는 error.errors 로만 읽는다', (surface) => {
    expect(JSON.stringify(surface.layout)).not.toMatch(/\berror\??\.data\b/);
  });
});
