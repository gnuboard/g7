/**
 * @file admin-login-password-change-step.test.tsx
 * @description 관리자 로그인 비밀번호 변경 단계 구조 회귀 테스트 (#736, sirsoft-admin_basic)
 *
 * 비밀번호가 만료됐거나 관리자가 정해 준 비밀번호면 로그인·인증번호 확인 응답이 토큰 대신 변경 단계를 돌려준다.
 * 화면은 세 단계(자격증명 / 2단계 인증 / 비밀번호 변경)를 같은 카드에서 바꾸며, 블록 `if` 와 제출 액션 `if` 가
 * 상호배타여야 한다. 하나라도 겹치면 Enter 한 번에 두 요청이 나가거나(새 challenge 가 발급되어 흐름이 끊긴다),
 * 두 단계가 함께 보인다. 성공 분기는 `password_change_required` 를 먼저 봐야 한다 — 「2단계 인증이 아니면 성공」 으로
 * 판정하면 토큰 없는 응답을 성공으로 오판해 이동한다.
 *
 * @since engine-v1.77.0
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import adminLogin from '../../layouts/admin_login.json';

type Action = {
  handler?: string;
  target?: string;
  if?: string;
  params?: Record<string, any>;
  actions?: Action[];
  onSuccess?: Action[];
  onError?: Action[];
};

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

const STEP_ONE = '{{!_local.twoFactor?.required && !_local.passwordChange?.required}}';
const STEP_TWO = '{{_local.twoFactor?.required}}';
const STEP_THREE = '{{_local.passwordChange?.required}}';

const nodes = flatten((adminLogin as any).components as Node[]);
const form = nodes.find((n) => n.id === 'login_form_component');
const formActions = flattenActions(form?.actions);
const byHandler = (handler: string) => formActions.find((a) => a.handler === handler);

describe('sirsoft-admin_basic 관리자 로그인 — 비밀번호 변경 단계 구조', () => {
  /**
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects three_steps_are_mutually_exclusive
   */
  it('세 단계의 블록 조건이 상보적이다 — 변경 단계 블록은 하나, 1단계 칸은 두 단계 모두가 아닐 때만', () => {
    const one = nodes.filter((n) => n.if === STEP_ONE).map((n) => n.id);
    const three = nodes.filter((n) => n.if === STEP_THREE).map((n) => n.id);

    expect(one).toEqual(expect.arrayContaining(['login_email_field', 'login_password_field', 'login_forgot_password', 'login_submit_button']));
    expect(three).toEqual(['login_password_change_step']);
    expect(nodes.filter((n) => n.if === STEP_TWO).map((n) => n.id)).toEqual(['login_two_factor_step']);
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects three_steps_are_mutually_exclusive
   */
  it('제출 액션 셋이 상호배타 조건으로 admin 을 대상으로 한다', () => {
    expect(byHandler('login')?.if).toBe(STEP_ONE);
    expect(byHandler('loginTwoFactor')?.if).toBe(STEP_TWO);
    expect(byHandler('loginPasswordChange')?.if).toBe(STEP_THREE);
    for (const handler of ['login', 'loginTwoFactor', 'loginPasswordChange']) {
      expect(byHandler(handler)?.target, `${handler} 는 관리자 경로를 써야 한다`).toBe('admin');
    }
    const body = byHandler('loginPasswordChange')?.params?.body;
    expect(body?.challenge_id).toBe('{{_local.passwordChange?.challenge_id}}');
    expect(body?.password).toBe('{{_local.passwordChange?.password}}');
    expect(body?.password_confirmation).toBe('{{_local.passwordChange?.password_confirmation}}');
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects change_step_entered_without_token
   */
  it('login·loginTwoFactor 의 이동은 변경 단계 응답에서 실행되지 않는다', () => {
    for (const handler of ['login', 'loginTwoFactor']) {
      const success = flattenActions(byHandler(handler)?.onSuccess);
      const navigate = success.find((a) => a.handler === 'navigate');
      expect(navigate?.if, `${handler} 성공 이동이 변경 단계 응답을 걸러야 한다`).toContain('!response.password_change_required');
      const enter = success.find((a) => a.handler === 'setState' && a.if === '{{response.password_change_required}}');
      expect(enter?.params?.passwordChange?.required, `${handler} 가 변경 단계 상태를 심지 않는다`).toBe(true);
      expect(enter?.params?.passwordChange?.challenge_id).toBe('{{response.challenge_id}}');
    }
  });

  /**
   * @scenario reason=expired, can_postpone=true, response=password_change_required
   * @effects postpone_button_only_when_allowed
   */
  it('「다음에 변경」은 연기 허용일 때만 그리고 연기 핸들러를 admin 으로 부른다', () => {
    const button = nodes.find((n) => n.id === 'login_password_change_postpone');
    expect(button?.if).toBe('{{_local.passwordChange?.can_postpone}}');
    const postpone = flattenActions(button?.actions).find((a) => a.handler === 'loginPasswordChangePostpone');
    expect(postpone?.target).toBe('admin');
    expect(postpone?.params?.body?.challenge_id).toBe('{{_local.passwordChange?.challenge_id}}');
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=challenge_invalid
   * @effects invalid_challenge_returns_to_credentials
   */
  it('식별자 무효(422 challenge_id)면 단계를 비우고 1단계 오류 자리에 다시 로그인 안내를 싣는다', () => {
    const fail = flattenActions(byHandler('loginPasswordChange')?.onError);
    const restart = fail.find((a) => a.if === '{{error.errors?.challenge_id}}');
    expect(restart?.params?.passwordChange).toBeNull();
    expect(String(restart?.params?.loginError)).toContain('password_change.restart_required');
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects init_resets_change_step, restart_clears_both_steps
   */
  it('화면 진입·「처음부터」가 변경 단계 상태를 비운다', () => {
    const init = asArray<Action>((adminLogin as any).init_actions);
    const reset = init.find((a) => a.handler === 'setState' && 'passwordChange' in (a.params ?? {}));
    expect(reset?.params?.passwordChange).toBeNull();

    const restart = flattenActions(nodes.find((n) => n.id === 'login_password_change_restart')?.actions).find((a) => a.handler === 'setState');
    expect(restart?.params?.passwordChange).toBeNull();
    expect(restart?.params?.twoFactor).toBeNull();
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=ok, dormancy=restored
   * @effects dormancy_notice_reaches_layout_on_plain_login
   */
  it('최종 성공 지점마다 휴면 해제 안내가 있다(로그인·인증번호·변경·연기)', () => {
    const hasNotice = (actions: Action[] | undefined) =>
      flattenActions(actions).some((a) => a.handler === 'toast' && String(a.params?.message).includes('auth.dormancy_restored'));
    expect(hasNotice(byHandler('login')?.onSuccess)).toBe(true);
    expect(hasNotice(byHandler('loginTwoFactor')?.onSuccess)).toBe(true);
    expect(hasNotice(byHandler('loginPasswordChange')?.onSuccess)).toBe(true);
    const postpone = flattenActions(nodes.find((n) => n.id === 'login_password_change_postpone')?.actions).find((a) => a.handler === 'loginPasswordChangePostpone');
    expect(hasNotice(postpone?.onSuccess)).toBe(true);
  });

  /**
   * 상태가 값을 소유하는 칸(새 비밀번호·확인·인증번호)은 자동바인딩을 끈다. 이 폼은 `data-key="loginForm"` 이라 켜 두면
   * 자동바인딩 값이 명시 `value` 보다 앞서, 「처음부터」·식별자 만료 뒤 단계를 다시 밟을 때 칸에는 이전 입력이 보이고
   * 요청은 빈 값으로 나갔다(#736 S2 검증).
   *
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects state_owned_inputs_skip_auto_binding
   */
  it('새 비밀번호·확인·인증번호 칸은 자동바인딩을 끄고 상태 값만 쓴다', () => {
    for (const id of ['login_password_change_new_input', 'login_password_change_confirm_input', 'login_two_factor_input']) {
      const input = nodes.find((n) => n.id === id);
      expect(input, id).toBeDefined();
      expect(input?.props?.autoBinding, id).toBe(false);
    }
  });
});
