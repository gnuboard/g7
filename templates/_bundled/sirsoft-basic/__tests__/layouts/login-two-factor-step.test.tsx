/**
 * @file login-two-factor-step.test.tsx
 * @description 로그인 2단계 인증 단계 구조 회귀 테스트 (sirsoft-basic)
 *
 * 2단계 인증이 켜진 사이트에서 서버는 로그인에 **두 가지 형태의 200** 을 돌려준다.
 * 화면이 한 형태만 가정하면 인증번호 요구 응답에서 영문 오류가 노출되고 로그인이
 * 불가능해진다(공개 #133).
 *
 * 검증 대상:
 * 1. 1단계·2단계 블록의 `if` 가 상보적이다 (동시 노출 금지)
 * 2. 인증번호 입력이 controlled — `value` + `onChange` 쌍, `events:{}` 래퍼 없음
 * 3. `login` 과 `loginTwoFactor` 가 상호배타 `if` 를 갖는다
 * 4. 재발송 onSuccess 가 새 challenge 로 교체하고 입력값을 비운다
 * 5. `login` 의 성공 후속 액션이 challenge 응답에서는 실행되지 않는다
 * 6. 로그인 화면 진입 시 2단계 상태가 초기화된다
 *
 * @since engine-v1.65.0
 */

import { describe, it, expect } from 'vitest';

import loginForm from '../../layouts/partials/auth/_login_form.json';
import loginPage from '../../layouts/auth/login.json';

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

/** 트리를 평탄화한다. */
function flatten(node: Node | Node[] | undefined): Node[] {
  if (!node) return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  return [node, ...children];
}

/** 시퀀스를 평탄화해 모든 액션을 모은다. */
function flattenActions(actions: Action[] | undefined): Action[] {
  if (!actions) return [];
  return actions.flatMap((a) => [
    a,
    ...flattenActions(a.actions),
    ...flattenActions(a.onSuccess),
    ...flattenActions(a.onError),
  ]);
}

const nodes = flatten(loginForm as unknown as Node);
const allActions = flattenActions((loginForm as unknown as Node).actions);

describe('sirsoft-basic 로그인 2단계 인증 단계', () => {
  /**
   * @scenario step=code, response=challenge, action=submit
   *
   * @effects code_step_rendered_on_challenge, credential_step_hidden_on_challenge
   */
  it('1단계 입력과 2단계 블록의 조건이 상보적이다', () => {
    // 두 블록이 같은 조건을 쓰면 인증번호 단계에서 이메일·비밀번호가 함께 보인다.
    const stepOne = nodes.filter((n) => n.if === '{{!_global.twoFactor?.required}}');
    const stepTwo = nodes.filter((n) => n.if === '{{_global.twoFactor?.required}}');

    expect(stepOne.length).toBeGreaterThanOrEqual(3);
    expect(stepTwo.length).toBe(1);
  });

  /**
   * @scenario step=code, response=challenge, action=submit
   *
   * @effects code_input_is_controlled_without_events_wrapper
   */
  it('인증번호 입력이 controlled 이고 events 래퍼를 쓰지 않는다', () => {
    const codeInput = nodes.find((n) => n.props?.name === 'two_factor_code');

    expect(codeInput).toBeDefined();
    expect(codeInput?.props?.value).toBe("{{_global.twoFactor?.code ?? ''}}");
    expect(codeInput?.props?.autoComplete).toBe('one-time-code');
    expect(codeInput?.props?.inputMode).toBe('numeric');
    // 값의 소유자가 상태여야 재발송 시 입력값을 비울 수 있다.
    expect(codeInput?.events).toBeUndefined();

    const onChange = codeInput?.actions?.find((a) => a.event === 'onChange');
    expect(onChange?.handler).toBe('setState');
    expect(onChange?.params?.['twoFactor.code']).toBe('{{$event.target.value}}');
  });

  /**
   * @scenario step=code, response=ok, action=enter_key
   *
   * @effects login_and_verify_are_mutually_exclusive
   */
  it('login 과 loginTwoFactor 가 상호배타 조건을 갖는다', () => {
    const login = allActions.find((a) => a.handler === 'login');
    const verify = allActions.find((a) => a.handler === 'loginTwoFactor');

    expect(login?.if).toBe('{{!_global.twoFactor?.required}}');
    expect(verify?.if).toBe('{{_global.twoFactor?.required}}');
    // 조건이 빠지면 인증번호 단계에서 Enter 를 누를 때 새 challenge 가 발급된다.
    expect(login?.target).toBe('user');
    expect(verify?.target).toBe('user');
  });

  /**
   * @scenario step=code, response=ok, action=submit
   *
   * @effects no_raw_typeerror_text
   */
  it('loginTwoFactor 가 challenge_id 와 code 를 함께 보낸다', () => {
    const verify = allActions.find((a) => a.handler === 'loginTwoFactor');

    expect(verify?.params?.body?.challenge_id).toBe('{{_global.twoFactor?.challenge_id}}');
    expect(verify?.params?.body?.code).toBe('{{_global.twoFactor?.code}}');
  });

  /**
   * @scenario step=credentials, response=challenge, action=submit
   *
   * @effects credential_step_hidden_on_challenge
   */
  it('login 의 성공 후속 액션이 challenge 응답에서는 실행되지 않는다', () => {
    const login = allActions.find((a) => a.handler === 'login');
    const onSuccess = login?.onSuccess ?? [];

    expect(onSuccess.length).toBeGreaterThan(0);
    for (const action of onSuccess) {
      // 조건이 없는 후속 액션이 하나라도 남으면 인증 전에 홈으로 이동하거나
      // 빈 사용자 정보가 전역 상태에 실린다.
      expect(action.if, `후속 액션 ${action.handler} 에 조건이 없습니다`).toBeDefined();
    }

    const challengeBranch = onSuccess.find((a) => a.if === '{{response.two_factor_required}}');
    expect(challengeBranch?.params?.twoFactor?.challenge_id).toBe('{{response.challenge_id}}');
    // 같은 시퀀스 안에서는 _global 이 아직 갱신 전이므로 응답 값만 읽어야 한다.
    expect(JSON.stringify(challengeBranch?.params)).not.toContain('_global.twoFactor');
  });

  /**
   * @scenario step=code, response=ok, action=resend
   *
   * @effects resend_clears_code_and_replaces_challenge
   */
  it('재발송 성공 시 새 challenge 로 교체하고 입력값을 비운다', () => {
    const resend = allActions.find((a) => a.handler === 'loginTwoFactorResend');
    expect(resend).toBeUndefined();

    // 재발송은 버튼 노드의 액션에 있다 — 폼 submit 시퀀스가 아니다.
    const resendButton = nodes.find((n) =>
      flattenActions(n.actions).some((a) => a.handler === 'loginTwoFactorResend')
    );
    const resendAction = flattenActions(resendButton?.actions).find(
      (a) => a.handler === 'loginTwoFactorResend'
    );

    expect(resendAction?.target).toBe('user');
    expect(resendAction?.params?.body?.challenge_id).toBe('{{_global.twoFactor?.challenge_id}}');

    const success = resendAction?.onSuccess?.[0];
    expect(success?.params?.twoFactor?.challenge_id).toBe('{{response.challenge_id}}');
    // 이전 코드가 남아 있으면 새 코드를 받았는데 옛 코드로 제출된다.
    expect(success?.params?.twoFactor?.code).toBe('');
    expect(success?.params?.twoFactor?.resent).toBe(true);
  });

  /**
   * @scenario step=credentials, response=ok, action=restart
   *
   * @effects init_actions_reset_two_factor_state, restart_resets_to_credential_step
   */
  it('로그인 화면 진입 시 2단계 상태를 초기화한다', () => {
    // 전역 상태라 화면을 떠나도 남는다 — 리셋이 없으면 다시 들어왔을 때 1단계가 보이지 않는다.
    const init = (loginPage as any).init_actions as Action[];
    const reset = init.find(
      (a) => a.handler === 'setState' && a.params?.target === 'global' && 'twoFactor' in (a.params ?? {})
    );

    expect(reset).toBeDefined();
    expect(reset?.params?.twoFactor).toBeNull();
    expect(reset?.if).toBeUndefined();
  });

  /**
   * @scenario step=credentials, response=423, action=submit
   *
   * @effects locked_until_rendered
   */
  it('계정 잠금 해제 시각을 렌더하는 지점이 있다', () => {
    const lockedUntil = nodes.find((n) => n.if === '{{_global.loginErrors?.locked_until}}');
    const permanent = nodes.find((n) => n.if === '{{_global.loginErrors?.permanent === true}}');

    expect(lockedUntil?.text).toContain('$t:auth.locked_until');
    expect(permanent?.text).toBe('$t:auth.locked_permanent');
  });
});
