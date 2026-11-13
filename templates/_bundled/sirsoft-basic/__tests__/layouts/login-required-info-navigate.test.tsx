/**
 * @file login-required-info-navigate.test.tsx
 * @description 로그인을 마친 응답에 비어 있는 필수 항목(pending_required_fields)이 실려 오면 추가 정보 입력 화면으로 보내는지
 *
 * 로그인이 끝나는 지점은 넷이다: 로그인 화면의 명명 액션 `applyLoginResult`(비밀번호 로그인, 로그인 인계, 회사 계정 동의 창과 연결 창이 부른다)와
 * 그 액션을 거치지 않는 폼 조각의 세 곳(2단계 인증 완료, 비밀번호 변경 완료, 다음에 변경). 네 곳이 같은 식으로 목적지를 정한다 —
 * 남은 항목이 있으면 원래 가려던 곳을 redirect 로 실어 `/mypage/required-info`, 없으면 원래 가려던 곳(없으면 홈).
 *
 * @vitest-environment jsdom
 * @scenario-manifest tests/scenarios/login-required-info-navigate.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

import loginForm from '../../layouts/partials/auth/_login_form.json';
import loginPage from '../../layouts/auth/login.json';
import ssoModals from '../../extensions/sirsoft-sso/auth-login-sso-modals.json';
import socialModals from '../../extensions/sirsoft-social_login/auth_register_social_modals.json';

const apiPost = vi.fn();

vi.mock('@core/api/ApiClient', async () => {
  const actual = await vi.importActual<typeof import('@core/api/ApiClient')>('@core/api/ApiClient');
  const stub = {
    post: (...args: unknown[]) => apiPost(...args),
    get: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    getToken: vi.fn(() => null),
    setToken: vi.fn(),
    removeToken: vi.fn(),
    setLocale: vi.fn(),
  };
  return { ...actual, getApiClient: () => stub, createApiClient: () => stub };
});

type Action = Record<string, any>;
type Common = { className?: string; children?: React.ReactNode; text?: string };

const Div: React.FC<Common> = ({ className, children }) => <div className={className}>{children}</div>;
const Span: React.FC<Common> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const P: React.FC<Common> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const Label: React.FC<Common> = ({ className, children, text }) => <label className={className}>{children || text}</label>;
const Form: React.FC<Common> = ({ className, children }) => <form className={className}>{children}</form>;
const Button: React.FC<Common & { type?: string; disabled?: boolean }> = ({ type, className, disabled, children, text }) => (
  <button type={type as 'button' | 'submit'} className={className} disabled={disabled}>{children || text}</button>
);
const Input: React.FC<{ type?: string; name?: string; value?: string; disabled?: boolean }> = ({ type, name, value, disabled }) => (
  <input type={type} name={name} defaultValue={value} disabled={disabled} />
);
const PasswordInput: React.FC<{ name?: string; disabled?: boolean }> = ({ name, disabled }) => <input type="password" name={name} disabled={disabled} />;
const Fragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

/**
 * 시험용 컴포넌트 레지스트리를 만든다.
 *
 * @returns 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: Div, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: Span, metadata: { name: 'Span', type: 'basic' } },
    P: { component: P, metadata: { name: 'P', type: 'basic' } },
    Label: { component: Label, metadata: { name: 'Label', type: 'basic' } },
    Form: { component: Form, metadata: { name: 'Form', type: 'basic' } },
    Button: { component: Button, metadata: { name: 'Button', type: 'basic' } },
    Input: { component: Input, metadata: { name: 'Input', type: 'basic' } },
    PasswordInput: { component: PasswordInput, metadata: { name: 'PasswordInput', type: 'basic' } },
    Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

/** 값 안의 모든 액션(자식 노드, 시퀀스, 성공과 실패 처리, 조건 갈래까지)을 모은다 */
function allActions(value: unknown, out: Action[] = []): Action[] {
  if (Array.isArray(value)) {
    value.forEach((item) => allActions(item, out));
    return out;
  }
  if (!value || typeof value !== 'object') return out;
  const node = value as Action;
  if (typeof node.handler === 'string' || typeof node.actionRef === 'string') out.push(node);
  Object.values(node).forEach((child) => allActions(child, out));
  return out;
}

/** 식({{ }})을 실제 엔진의 평가기로 푼다 */
function engineEval(expression: string, context: Record<string, unknown>): any {
  const body = expression.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  return new DataBindingEngine().evaluateExpression(body, context as any, { skipCache: true });
}

/** 하네스에 없는 템플릿 커스텀 핸들러를 뺀다 — 성공 분기가 중간에 끊기지 않게 */
const withoutCustomHandlers = (actions: unknown): Action[] => asArray<Action>(actions).filter((action) => action?.handler !== 'clearGuestOrderToken');

const namedActions = (loginPage as any).named_actions;
const applyLoginResult = asArray<Action>(namedActions.applyLoginResult.actions);
const finalNavigate = applyLoginResult.find((action) => action.handler === 'navigate') as Action;
const NAVIGATE_PATH = finalNavigate.params.path as string;

const formActions = allActions(loginForm);
const loginAction = formActions.find((action) => action.handler === 'login') as Action;
const twoFactorAction = formActions.find((action) => action.handler === 'loginTwoFactor') as Action;
const passwordChangeAction = formActions.find((action) => action.handler === 'loginPasswordChange') as Action;
const postponeAction = formActions.find((action) => action.handler === 'loginPasswordChangePostpone') as Action;

const handoffSequence = asArray<Action>((loginPage as any).init_actions).find((action) => action.handler === 'sequence' && asArray<Action>(action.actions).some((item) => item.handler === 'loginHandoff')) as Action;
const handoffAction = asArray<Action>(handoffSequence.actions).find((action) => action.handler === 'loginHandoff') as Action;

const ssoHandoffs = allActions(ssoModals).filter((action) => action.handler === 'loginHandoff');

const layout = {
  version: '1.0.0',
  layout_name: 'auth/login',
  named_actions: {
    ...namedActions,
    applyLoginResult: { ...namedActions.applyLoginResult, actions: withoutCustomHandlers(applyLoginResult) },
  },
  components: [loginForm as any],
};

const member = { uuid: 'u-1', name: '회원', email: 'm@example.test' };
const pendingLogin = { success: true, data: { token: 'tok', user: member, pending_required_fields: ['nickname', 'job'] } };
const completeLogin = { success: true, data: { token: 'tok', user: member, pending_required_fields: [] } };

/**
 * 액션 하나를 본문을 글자 값으로 바꿔 실행한다 (하네스는 주소 쿼리와 폼 값을 액션 문맥에 싣지 않는다).
 *
 * @param action 실행할 액션
 * @param body 요청 본문
 * @returns 이동 이력
 */
async function run(action: Action, body: Record<string, unknown>): Promise<string[]> {
  const t = createLayoutTest(layout, { componentRegistry: setupRegistry() });
  await t.render();
  await t.triggerAction({
    ...action,
    if: undefined,
    params: { ...(action.params ?? {}), body },
    onSuccess: withoutCustomHandlers(action.onSuccess).filter((item) => item.handler !== 'replaceUrl'),
  } as any);
  const history = [...t.getNavigationHistory()];
  expect(t.getState()._global?.currentUser?.uuid).toBe('u-1');
  t.cleanup();
  return history;
}

describe('로그인 뒤 추가 정보 입력 유도', () => {
  beforeEach(() => {
    setupRegistry();
    apiPost.mockReset();
  });

  describe('목적지 식', () => {
    /**
     * @scenario case=destination_expression
     * @effects pending_member_goes_to_required_info_with_original_destination
     */
    it('남은 항목이 있으면 원래 가려던 곳을 redirect 로 실어 추가 정보 입력 화면으로, 없으면 원래 가려던 곳(없으면 홈)으로 간다', () => {
      const target = (response: Record<string, unknown>, query: Record<string, unknown>) => engineEval(NAVIGATE_PATH, { response, query });

      expect(target({ pending_required_fields: ['job'] }, {})).toBe('/mypage/required-info');
      expect(target({ pending_required_fields: ['job'] }, { redirect: '/board/free?page=2' })).toBe('/mypage/required-info?redirect=%2Fboard%2Ffree%3Fpage%3D2');
      // 원래 가려던 곳이 이미 추가 정보 입력 화면이면(그 화면에서 로그인이 풀린 경우) 겹쳐 싣지 않고 그대로 보낸다 — 안쪽 redirect 를 잃지 않는다
      expect(target({ pending_required_fields: ['job'] }, { redirect: '/mypage/required-info?redirect=%2Fboard%2Ffree' })).toBe('/mypage/required-info?redirect=%2Fboard%2Ffree');

      expect(target({ pending_required_fields: [] }, {})).toBe('/');
      expect(target({ pending_required_fields: [] }, { redirect: '/mypage/orders' })).toBe('/mypage/orders');
      // 키가 실리지 않은 응답(옛 서버)은 종전 그대로다
      expect(target({}, { redirect: '/mypage/orders' })).toBe('/mypage/orders');
      expect(target({}, {})).toBe('/');

      expect(finalNavigate.params.fallbackPath).toBe('/');
      expect(finalNavigate.if).toBe('{{!response.two_factor_required && !response.password_change_required}}');
    });

    /**
     * @scenario case=destination_expression
     * @effects four_completion_points_share_one_expression
     */
    it('로그인이 끝나는 네 지점이 같은 식을 쓴다(applyLoginResult 와 폼 조각의 세 곳)', () => {
      const navigates = [twoFactorAction, passwordChangeAction, postponeAction].map((action) => withoutCustomHandlers(action.onSuccess).find((item) => item.handler === 'navigate') as Action);
      for (const navigate of navigates) {
        expect(navigate).toBeDefined();
        expect(navigate.params.path).toBe(NAVIGATE_PATH);
        expect(navigate.params.fallbackPath).toBe('/');
      }
      // 로그인 화면과 폼 조각에서 로그인 뒤 이동은 이 넷뿐이다 — 판정을 건너뛰는 이동이 없다
      const loginNavigates = [...allActions((loginPage as any).named_actions), ...allActions((loginPage as any).init_actions), ...formActions].filter(
        (action) => action.handler === 'navigate' && typeof action.params?.path === 'string' && action.params.path.includes('query.redirect'),
      );
      expect(loginNavigates).toHaveLength(4);
      expect(new Set(loginNavigates.map((action) => action.params.path))).toEqual(new Set([NAVIGATE_PATH]));
    });
  });

  describe('applyLoginResult 를 거치는 세 진입', () => {
    /**
     * @scenario case=password_login
     * @effects password_login_is_guided_through_apply_login_result
     */
    it('비밀번호 로그인 — 성공 분기가 applyLoginResult 이고 남은 항목이 있으면 추가 정보 입력 화면으로 간다', async () => {
      expect(asArray<Action>(loginAction.onSuccess).map((action) => action.actionRef)).toEqual(['applyLoginResult']);

      apiPost.mockResolvedValue(pendingLogin);
      expect(await run(loginAction, { email: 'm@example.test', password: 'secret-1234' })).toEqual(['/mypage/required-info']);
      expect(apiPost.mock.calls[0][0]).toBe('/auth/login');
    });

    /**
     * @scenario case=login_handoff
     * @effects login_handoff_is_guided_through_apply_login_result
     */
    it('로그인 인계(소셜 로그인 등) — 성공 분기가 applyLoginResult 이고 남은 항목이 있으면 추가 정보 입력 화면으로 간다', async () => {
      expect(asArray<Action>(handoffAction.onSuccess).some((action) => action.actionRef === 'applyLoginResult')).toBe(true);
      expect(asArray<Action>(handoffAction.onSuccess).some((action) => action.handler === 'navigate')).toBe(false);

      apiPost.mockResolvedValue(pendingLogin);
      expect(await run(handoffAction, { code: 'HANDOFF-CODE' })).toEqual(['/mypage/required-info']);
      expect(apiPost.mock.calls[0][0]).toBe('/auth/login/handoff');
    });

    /**
     * @scenario case=sso_modals
     * @effects sso_consent_and_link_modals_are_guided_through_apply_login_result
     */
    it('회사 계정 동의 창과 연결 창 — 두 창의 인계 교환이 모두 applyLoginResult 로 넘기고 스스로 이동하지 않는다', async () => {
      expect(ssoHandoffs).toHaveLength(2);
      for (const handoff of ssoHandoffs) {
        expect(handoff.target).toBe('user');
        expect(asArray<Action>(handoff.onSuccess).some((action) => action.actionRef === 'applyLoginResult')).toBe(true);
        expect(allActions(handoff.onSuccess).some((action) => action.handler === 'navigate' || action.handler === 'redirect')).toBe(false);

        apiPost.mockReset();
        apiPost.mockResolvedValue(pendingLogin);
        expect(await run(handoff, { code: 'SSO-HANDOFF' })).toEqual(['/mypage/required-info']);
      }
      // 창 조각 어디에도 로그인 뒤 목적지로 바로 가는 이동이 없다
      expect(allActions(ssoModals).some((action) => action.handler === 'navigate' && String(action.params?.path ?? '').includes('query.redirect'))).toBe(false);
    });

    /**
     * @scenario case=social_signup_modals
     * @effects social_signup_modals_return_through_login_handoff
     */
    it('소셜 가입 완료 창 — 로그인으로 이어지는 이동은 모두 로그인 인계 주소(또는 로그인 화면)라 같은 분기를 탄다', () => {
      const navigates = allActions(socialModals).filter((action) => action.handler === 'navigate');
      expect(navigates.length).toBeGreaterThan(0);
      for (const navigate of navigates) {
        const target = String(navigate.params?.path ?? '');
        expect(target === '/login' || target.includes('handoff_url'), target).toBe(true);
        expect(target).not.toContain('query.redirect');
      }
      expect(allActions(socialModals).some((action) => action.handler === 'loginHandoff' || action.handler === 'login')).toBe(false);
    });
  });

  describe('applyLoginResult 를 거치지 않는 세 곳', () => {
    /**
     * @scenario case=two_factor_complete
     * @effects two_factor_completion_is_guided
     */
    it('2단계 인증 완료 — 남은 항목이 있으면 추가 정보 입력 화면으로 간다', async () => {
      expect(allActions(twoFactorAction.onSuccess).some((action) => action.actionRef === 'applyLoginResult')).toBe(false);

      apiPost.mockResolvedValue(pendingLogin);
      expect(await run(twoFactorAction, { challenge_id: 'c-1', code: '123456' })).toEqual(['/mypage/required-info']);
      expect(apiPost.mock.calls[0][0]).toBe('/auth/login/two-factor');
    });

    /**
     * @scenario case=password_change_complete
     * @effects password_change_completion_is_guided
     */
    it('비밀번호 변경 완료 — 남은 항목이 있으면 추가 정보 입력 화면으로 간다', async () => {
      apiPost.mockResolvedValue(pendingLogin);
      expect(await run(passwordChangeAction, { challenge_id: 'c-2', password: 'New-pass-1234', password_confirmation: 'New-pass-1234' })).toEqual(['/mypage/required-info']);
      expect(apiPost.mock.calls[0][0]).toBe('/auth/login/password-change');
    });

    /**
     * @scenario case=password_change_postponed
     * @effects password_change_postpone_is_guided
     */
    it('다음에 변경 — 남은 항목이 있으면 추가 정보 입력 화면으로 간다', async () => {
      apiPost.mockResolvedValue({ success: true, data: { ...pendingLogin.data, postponed_until: '2026-12-01T00:00:00+09:00' } });
      expect(await run(postponeAction, { challenge_id: 'c-3' })).toEqual(['/mypage/required-info']);
      expect(apiPost.mock.calls[0][0]).toBe('/auth/login/password-change/postpone');
    });
  });

  describe('남은 항목이 없는 회원', () => {
    /**
     * @scenario case=complete_member
     * @effects complete_member_keeps_original_destination
     */
    it('네 지점 모두 종전처럼 원래 가려던 곳(여기서는 홈)으로 간다', async () => {
      const cases: Array<[Action, Record<string, unknown>]> = [
        [loginAction, { email: 'm@example.test', password: 'secret-1234' }],
        [twoFactorAction, { challenge_id: 'c-1', code: '123456' }],
        [passwordChangeAction, { challenge_id: 'c-2', password: 'New-pass-1234', password_confirmation: 'New-pass-1234' }],
        [postponeAction, { challenge_id: 'c-3' }],
      ];
      for (const [action, body] of cases) {
        apiPost.mockReset();
        apiPost.mockResolvedValue(completeLogin);
        expect(await run(action, body), action.handler).toEqual(['/']);
      }
    });
  });

  describe('플러그인 원본 조각', () => {
    const pluginSso = path.resolve(__dirname, '../../../../../plugins/_bundled/sirsoft-sso/resources/extensions/auth-login-sso-modals.json');
    const hasPlugin = fs.existsSync(pluginSso);

    /**
     * @scenario case=sso_modals
     * @effects sso_plugin_original_fragment_also_uses_apply_login_result
     */
    it.skipIf(!hasPlugin)('회사 계정 플러그인의 원본 창 조각도 인계 교환을 applyLoginResult 로 넘긴다', () => {
      const original = JSON.parse(fs.readFileSync(pluginSso, 'utf-8'));
      const handoffs = allActions(original).filter((action) => action.handler === 'loginHandoff');
      expect(handoffs).toHaveLength(2);
      for (const handoff of handoffs) {
        expect(asArray<Action>(handoff.onSuccess).some((action) => action.actionRef === 'applyLoginResult')).toBe(true);
        expect(allActions(handoff.onSuccess).some((action) => action.handler === 'navigate')).toBe(false);
      }
    });
  });
});
