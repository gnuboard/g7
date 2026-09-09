/**
 * @file admin-login-two-factor-step.test.tsx
 * @description 관리자 로그인 2단계 인증 단계 구조 회귀 테스트 (sirsoft-admin_basic)
 *
 * 2단계 인증이 켜진 사이트에서는 관리자 로그인도 challenge 를 받는다. 관리자가 들어갈 수
 * 없으면 설정을 되돌릴 수단까지 사라지므로 사용자 화면보다 파급이 크다(공개 #133).
 *
 * 사용자 템플릿(sirsoft-basic)의 `login-two-factor-step.test.tsx` 와 평행한 검증이며,
 * 네임스페이스만 `_local` 로 다르다 — 이 화면은 독립 레이아웃이라 전역 상태를 쓰지 않는다.
 *
 * @since engine-v1.65.0
 */

import { describe, it, expect } from 'vitest';

import adminLogin from '../../layouts/admin_login.json';

type Action = {
  event?: string;
  type?: string;
  handler?: string;
  target?: string;
  if?: string;
  params?: Record<string, any>;
  actions?: Action[];
  onSuccess?: Action[];
  onError?: Action[];
};

type Node = {
  id?: string;
  name?: string;
  if?: string;
  props?: Record<string, any>;
  children?: Node[] | string;
  text?: string;
  events?: Record<string, unknown>;
  actions?: Action[];
};

function flatten(node: Node | Node[] | undefined): Node[] {
  if (!node) return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  return [node, ...children];
}

function flattenActions(actions: Action[] | undefined): Action[] {
  if (!actions) return [];
  return actions.flatMap((a) => [
    a,
    ...flattenActions(a.actions),
    ...flattenActions(a.onSuccess),
    ...flattenActions(a.onError),
  ]);
}

const nodes = flatten((adminLogin as any).components as Node[]);
const form = nodes.find((n) => n.id === 'login_form_component');
const formActions = flattenActions(form?.actions);

describe('sirsoft-admin_basic 관리자 로그인 2단계 인증 단계', () => {
  /**
   * @scenario step=code, response=challenge, action=submit
   *
   * @effects code_step_rendered_on_challenge, credential_step_hidden_on_challenge
   */
  it('1단계 입력과 2단계 블록의 조건이 상보적이다', () => {
    const stepOne = nodes.filter((n) => n.if === '{{!_local.twoFactor?.required}}');
    const stepTwo = nodes.filter((n) => n.if === '{{_local.twoFactor?.required}}');

    expect(stepOne.length).toBeGreaterThanOrEqual(3);
    expect(stepTwo.length).toBe(1);
    expect(stepTwo[0]?.id).toBe('login_two_factor_step');
  });

  /**
   * @scenario step=code, response=challenge, action=submit
   *
   * @effects code_input_is_controlled_without_events_wrapper
   */
  it('인증번호 입력이 controlled 이고 events 래퍼를 쓰지 않는다', () => {
    const codeInput = nodes.find((n) => n.id === 'login_two_factor_input');

    expect(codeInput?.props?.value).toBe("{{_local.twoFactor?.code ?? ''}}");
    expect(codeInput?.props?.autoComplete).toBe('one-time-code');
    expect(codeInput?.props?.inputMode).toBe('numeric');
    expect(codeInput?.events).toBeUndefined();

    const onChange = codeInput?.actions?.find((a) => a.event === 'onChange');
    expect(onChange?.handler).toBe('setState');
    expect(onChange?.params?.target).toBe('local');
    expect(onChange?.params?.['twoFactor.code']).toBe('{{$event.target.value}}');
  });

  /**
   * @scenario step=code, response=ok, action=enter_key
   *
   * @effects login_and_verify_are_mutually_exclusive, admin_required_message_rendered_in_code_step
   */
  it('login 과 loginTwoFactor 가 상호배타 조건을 갖고 admin 을 대상으로 한다', () => {
    const login = formActions.find((a) => a.handler === 'login');
    const verify = formActions.find((a) => a.handler === 'loginTwoFactor');

    expect(login?.if).toBe('{{!_local.twoFactor?.required}}');
    expect(verify?.if).toBe('{{_local.twoFactor?.required}}');
    expect(login?.target).toBe('admin');
    expect(verify?.target).toBe('admin');
    expect(verify?.params?.body?.challenge_id).toBe('{{_local.twoFactor?.challenge_id}}');
    expect(verify?.params?.body?.code).toBe('{{_local.twoFactor?.code}}');
  });

  /**
   * @scenario step=credentials, response=challenge, action=submit
   *
   * @effects no_raw_typeerror_text
   */
  it('login 의 성공 후속 액션이 challenge 응답에서는 실행되지 않는다', () => {
    const login = formActions.find((a) => a.handler === 'login');
    const onSuccess = login?.onSuccess ?? [];

    expect(onSuccess.length).toBeGreaterThan(0);
    for (const action of onSuccess) {
      expect(action.if, `후속 액션 ${action.handler} 에 조건이 없습니다`).toBeDefined();
    }

    const challengeBranch = onSuccess.find((a) => a.if === '{{response.two_factor_required}}');
    expect(challengeBranch?.params?.target).toBe('local');
    expect(challengeBranch?.params?.twoFactor?.challenge_id).toBe('{{response.challenge_id}}');
    expect(JSON.stringify(challengeBranch?.params)).not.toContain('_local.twoFactor');
  });

  /**
   * @scenario step=code, response=ok, action=resend
   *
   * @effects resend_clears_code_and_replaces_challenge
   */
  it('재발송 성공 시 새 challenge 로 교체하고 입력값을 비운다', () => {
    const resendButton = nodes.find((n) => n.id === 'login_two_factor_resend');
    const resendAction = flattenActions(resendButton?.actions).find(
      (a) => a.handler === 'loginTwoFactorResend'
    );

    expect(resendAction?.target).toBe('admin');
    expect(resendAction?.params?.body?.challenge_id).toBe('{{_local.twoFactor?.challenge_id}}');

    const success = resendAction?.onSuccess?.[0];
    expect(success?.params?.twoFactor?.challenge_id).toBe('{{response.challenge_id}}');
    expect(success?.params?.twoFactor?.code).toBe('');
    expect(success?.params?.twoFactor?.resent).toBe(true);
  });

  /**
   * @scenario step=credentials, response=ok, action=restart
   *
   * @effects init_actions_reset_two_factor_state, restart_resets_to_credential_step
   */
  it('화면 진입 시 2단계 상태를 초기화한다', () => {
    const init = (adminLogin as any).init_actions as Action[];
    const reset = init.find(
      (a) => a.handler === 'setState' && a.params?.target === 'local' && 'twoFactor' in (a.params ?? {})
    );

    expect(reset).toBeDefined();
    expect(reset?.params?.twoFactor).toBeNull();
  });

  /**
   * @scenario step=credentials, response=423, action=submit
   *
   * @effects locked_until_rendered
   */
  it('계정 잠금 해제 시각을 렌더하는 지점이 있다', () => {
    const lockedUntil = nodes.find((n) => n.id === 'login_error_locked_until');
    const permanent = nodes.find((n) => n.id === 'login_error_locked_permanent');

    expect(lockedUntil?.text).toContain('$t:auth.login.locked_until');
    expect(permanent?.text).toBe('$t:auth.login.locked_permanent');
  });

  /**
   * @scenario step=credentials, response=401, action=submit
   *
   * @effects toast_host_mounted_on_standalone_layout
   */
  it('Toast 호스트가 마운트되어 있다', () => {
    // 독립 레이아웃이라 베이스가 호스트를 주입하지 않는다 — 없으면 안내가 조용히 사라진다.
    expect(nodes.some((n) => n.name === 'Toast')).toBe(true);
  });
});
