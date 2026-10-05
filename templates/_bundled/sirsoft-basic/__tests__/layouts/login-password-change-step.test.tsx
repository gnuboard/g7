/**
 * @file login-password-change-step.test.tsx
 * @description 사용자 로그인 폼 비밀번호 변경 단계 구조 회귀 테스트 (#736, sirsoft-basic)
 *
 * 관리자 화면(`admin-login-password-change-step.test.tsx`)과 평행한 검증이며 상태 네임스페이스만 `_global` 이다 —
 * 로그인 폼 조각이 바깥 화면과 상태를 나눠 쓰기 때문이다. 세 단계의 블록 `if`·제출 액션 `if` 가 상호배타여야 하고,
 * 성공 분기는 `password_change_required` 를 먼저 봐야 하며, 새 비밀번호 칸 규칙은 보안 환경설정 값에 바인딩한다.
 *
 * @since engine-v1.77.0
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import loginForm from '../../layouts/partials/auth/_login_form.json';
import login from '../../layouts/auth/login.json';

type Action = { handler?: string; target?: string; if?: string; params?: Record<string, any>; actions?: Action[]; onSuccess?: Action[]; onError?: Action[] };
type Node = { id?: string; name?: string; if?: string; props?: Record<string, any>; children?: Node[] | string; actions?: Action[] };

function flatten(node: Node | Node[] | undefined): Node[] {
  if (!node) return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  return [node, ...children];
}

function flattenActions(actions: Action[] | Action | undefined): Action[] {
  if (!actions) return [];
  return asArray<Action>(actions).flatMap((a) => [a, ...flattenActions(a.actions), ...flattenActions(a.onSuccess), ...flattenActions(a.onError)]);
}

const STEP_ONE = '{{!_global.twoFactor?.required && !_global.passwordChange?.required}}';
const STEP_TWO = '{{_global.twoFactor?.required}}';
const STEP_THREE = '{{_global.passwordChange?.required}}';

const nodes = flatten(loginForm as any);
const formActions = flattenActions((loginForm as any).actions);
const byHandler = (handler: string) => formActions.find((a) => a.handler === handler);

describe('sirsoft-basic 로그인 폼 — 비밀번호 변경 단계 구조', () => {
  /**
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects three_steps_are_mutually_exclusive
   */
  it('세 단계의 블록 조건이 상보적이다', () => {
    expect(nodes.filter((n) => n.if === STEP_ONE).length).toBe(3);
    expect(nodes.filter((n) => n.if === STEP_TWO).length).toBe(1);
    expect(nodes.filter((n) => n.if === STEP_THREE).length).toBe(1);
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects three_steps_are_mutually_exclusive
   */
  it('제출 액션 셋이 상호배타 조건으로 user 를 대상으로 한다', () => {
    expect(byHandler('login')?.if).toBe(STEP_ONE);
    expect(byHandler('loginTwoFactor')?.if).toBe(STEP_TWO);
    expect(byHandler('loginPasswordChange')?.if).toBe(STEP_THREE);
    for (const handler of ['login', 'loginTwoFactor', 'loginPasswordChange']) {
      expect(byHandler(handler)?.target).toBe('user');
    }
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects change_step_entered_without_token
   */
  it('login·loginTwoFactor 의 이동·현재 사용자 설정은 변경 단계 응답에서 실행되지 않는다', () => {
    for (const handler of ['login', 'loginTwoFactor']) {
      const success = flattenActions(byHandler(handler)?.onSuccess);
      expect(String(success.find((a) => a.handler === 'navigate')?.if)).toContain('!response.password_change_required');
      const setUser = success.find((a) => a.handler === 'setState' && 'currentUser' in (a.params ?? {}));
      expect(String(setUser?.if), handler + ' 가 변경 단계 응답에서 사용자를 세운다').toContain('!response.password_change_required');
    }
  });

  /**
   * @scenario reason=expired, can_postpone=true, response=password_change_required
   * @effects postpone_button_only_when_allowed
   */
  it('「다음에 변경」은 연기 허용일 때만 그리고 연기 핸들러를 user 로 부른다', () => {
    const button = nodes.find((n) => n.if === '{{_global.passwordChange?.can_postpone}}');
    const postpone = flattenActions(button?.actions).find((a) => a.handler === 'loginPasswordChangePostpone');
    expect(postpone?.target).toBe('user');
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects rules_hint_reflects_settings
   */
  it('새 비밀번호 칸 규칙은 보안 환경설정 값에 바인딩한다(8 하드코딩 없음)', () => {
    const input = nodes.find((n) => n.name === 'PasswordInput' && n.props?.name === 'password_change_new');
    expect(String(input?.props?.enableRules)).toContain('_global.settings?.security?.password_min_length');
    expect(String(input?.props?.enableRules)).toContain("'minTypes:2'");
    expect(JSON.stringify(loginForm)).not.toContain('minLength:8');
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects init_resets_change_step
   */
  it('로그인 화면 진입이 변경 단계 상태를 비운다', () => {
    const init = asArray<Action>((login as any).init_actions);
    const reset = init.find((a) => a.handler === 'setState' && 'passwordChange' in (a.params ?? {}));
    expect(reset?.params?.passwordChange).toBeNull();
    expect(reset?.params?.twoFactor).toBeNull();
  });

  /**
   * 비밀번호는 `_global` 에 두지 않는다(security.md 「_global 상태 민감 정보 금지」) — 단계 정보(식별자·사유)는 바깥 화면이 읽어
   * `_global` 이지만, 새 비밀번호·확인 값은 이 화면의 `_local.passwordChangeInput` 이 갖고 처음부터·식별자 만료 때 비운다.
   *
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects new_password_values_never_stored_in_global
   */
  it('새 비밀번호·확인 값은 _global 이 아니라 _local 에 두고, 처음부터·식별자 만료 때 비운다', () => {
    const json = JSON.stringify(loginForm);
    expect(json).not.toMatch(/passwordChange\??\.password(_confirmation)?\b/);
    expect(json).not.toMatch(/"name":"password(_confirmation)?","value":""/);

    const input = nodes.find((n) => n.name === 'PasswordInput' && n.props?.name === 'password_change_new');
    const confirm = nodes.find((n) => n.name === 'PasswordInput' && n.props?.name === 'password_change_confirm');
    expect(input?.props?.value).toBe("{{_local.passwordChangeInput?.password ?? ''}}");
    expect(confirm?.props?.value).toBe("{{_local.passwordChangeInput?.password_confirmation ?? ''}}");
    expect(confirm?.props?.confirmTarget).toBe("{{_local.passwordChangeInput?.password ?? ''}}");
    for (const [n, key] of [[input, 'passwordChangeInput.password'], [confirm, 'passwordChangeInput.password_confirmation']] as const) {
      const set = flattenActions(n?.actions).find((a) => a.handler === 'setState');
      expect(set?.params?.target).toBe('local');
      expect(set?.params?.[key]).toBe('{{$event.target.value}}');
    }

    const body = byHandler('loginPasswordChange')?.params?.body;
    expect(body?.password).toBe('{{_local.passwordChangeInput?.password}}');
    expect(body?.password_confirmation).toBe('{{_local.passwordChangeInput?.password_confirmation}}');

    const clears = [...formActions, ...nodes.flatMap((n) => flattenActions(n.actions))].filter(
      (a) => a.handler === 'setState' && a.params?.target === 'local' && a.params?.passwordChangeInput === null,
    );
    // 처음부터 1 + 식별자 만료(제출·연기) 2 + 단계 진입(1단계·2단계 응답) 2
    expect(clears.length).toBeGreaterThanOrEqual(5);
  });
});
