/**
 * @file login-handoff.test.tsx
 * @description 로그인 인계 진입·확장점 회귀 테스트 (sirsoft-basic,)
 *
 * 외부 인증(소셜 로그인 등)이 본인 확인을 마친 회원을 `/login?login_handoff={코드}` 로 보내면, 로그인 화면이 진입 시
 * `loginHandoff` 액션으로 코드를 교환한다. 응답 분기는 비밀번호 로그인(`login`)과 **같은 명명 액션**(`applyLoginResult`)이
 * 맡아 2단계 인증·비밀번호 변경·로그인 완료가 똑같이 처리된다. 실패하면 로그인 칸 위에 안내가 뜨고 주소에서 코드가 지워진다.
 *
 * 로그인·가입 화면에는 다른 로그인 수단을 확장이 붙이는 확장점(`auth_login_extensions`·`auth_register_extensions`)이 있다.
 *
 * @vitest-environment jsdom
 * @since 1.4.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import loginForm from '../../layouts/partials/auth/_login_form.json';
import loginPage from '../../layouts/auth/login.json';
import registerPage from '../../layouts/auth/register.json';

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

type Common = { className?: string; children?: React.ReactNode; text?: string };

const Div: React.FC<Common & { role?: string }> = ({ className, children, role }) => <div className={className} role={role}>{children}</div>;
const Span: React.FC<Common> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const P: React.FC<Common & { role?: string }> = ({ className, children, text, role }) => <p className={className} role={role}>{children || text}</p>;
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
 * 시험용 컴포넌트 레지스트리를 만듭니다.
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

/**
 * 트리를 평탄화합니다.
 *
 * @param node 노드
 * @returns 노드 목록
 */
function flatten(node: any): any[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  const slots = node.slots ? Object.values(node.slots).flatMap(flatten) : [];
  return [node, ...children, ...slots];
}

const namedActions = (loginPage as any).named_actions;

/** 로그인 화면 진입 시 인계 시퀀스 */
// 7.2.0 자동 이동 액션(redirect)도 `if` 에 `!query?.login_handoff`(우회 쿼리)를 품는다 — 조건 문자열이 아니라 `loginHandoff` 를 담은 시퀀스로 찾는다.
const handoffSequence = asArray<any>((loginPage as any).init_actions).find((a) => a.handler === 'sequence' && asArray<any>(a.actions).some((x) => x.handler === 'loginHandoff'));
const handoffAction = asArray<any>(handoffSequence?.actions).find((a) => a.handler === 'loginHandoff');

/** 하네스에 없는 템플릿 커스텀 핸들러를 뺀 명명 액션 — 성공 분기가 중간에 끊기지 않게 */
const harnessNamedActions = {
  ...namedActions,
  applyLoginResult: {
    ...namedActions.applyLoginResult,
    actions: (namedActions.applyLoginResult.actions as any[]).filter((a) => a?.handler !== 'clearGuestOrderToken'),
  },
};

const layout = {
  version: '1.0.0',
  layout_name: 'auth/login',
  named_actions: harnessNamedActions,
  components: [loginForm as any],
};

/**
 * 인계 액션을 코드 리터럴로 실행합니다 (하네스는 주소 쿼리를 액션 문맥에 싣지 않는다).
 *
 * @param t 레이아웃 시험 하네스
 * @returns 완료 약속
 */
async function exchange(t: ReturnType<typeof createLayoutTest>): Promise<void> {
  await t.triggerAction({ ...handoffAction, params: { ...(handoffAction.params ?? {}), body: { code: 'HANDOFF-CODE' } } });
}

describe('sirsoft-basic 로그인 인계·확장점', () => {
  beforeEach(() => {
    setupRegistry();
    apiPost.mockReset();
  });

  /**
   * @scenario surface=login|register
   * @effects auth_extension_points_present
   */
  it('로그인 화면과 가입 화면에 확장점이 있다', () => {
    const loginNodes = flatten(loginPage as any);
    const registerNodes = flatten(registerPage as any);

    expect(loginNodes.some((n) => n.type === 'extension_point' && n.name === 'auth_login_extensions')).toBe(true);
    expect(registerNodes.some((n) => n.type === 'extension_point' && n.name === 'auth_register_extensions')).toBe(true);
  });

  /**
   * @scenario query=login_handoff
   * @effects handoff_init_dispatches_login_handoff, handoff_shares_login_result_branch
   */
  it('주소에 login_handoff 가 있을 때만 진입 시 인계를 교환하고, 성공 분기는 login 과 같은 명명 액션을 부른다', () => {
    expect(handoffSequence?.if).toBe('{{!!query?.login_handoff}}');
    expect(handoffAction?.target).toBe('user');
    expect(handoffAction?.params?.body?.code).toBe('{{query.login_handoff}}');

    const success = asArray<any>(handoffAction?.onSuccess);
    expect(success.some((a) => a.actionRef === 'applyLoginResult')).toBe(true);

    // 비밀번호 로그인도 같은 명명 액션을 부른다 — 두 경로가 갈라지지 않는다.
    const submit = asArray<any>((loginForm as any).actions)[0];
    const login = asArray<any>(submit?.actions).find((a) => a.handler === 'login');
    expect(asArray<any>(login?.onSuccess)[0]?.actionRef).toBe('applyLoginResult');
  });

  /**
   * 로그인된 사람을 홈으로 되돌리는 조각(`_redirect_if_logged_in`)은 `_local.isLoginAction` 이 서 있으면 건너뛴다.
   * 비밀번호 로그인은 제출 시 이 표지를 세운다. 인계도 교환 **전에** 세워야 한다 — 빠지면 `currentUser` 가 서는 순간
   * 그 조각이 홈으로 보내 `redirect`(예: `/mypage/change-password`)를 잃는다(Chrome MCP 실측 2026-10-19).
   *
   * @scenario query=login_handoff, redirect=present
   * @effects handoff_keeps_redirect_target
   */
  it('인계 교환 전에 로그인 진행 표지를 세워 복귀 주소를 잃지 않는다', () => {
    const steps = asArray<any>(handoffSequence?.actions);
    const markIndex = steps.findIndex((a) => a.handler === 'setState' && a.params?.target === 'local' && a.params?.isLoginAction === true);
    const handoffIndex = steps.findIndex((a) => a.handler === 'loginHandoff');

    expect(markIndex, '인계 시퀀스가 _local.isLoginAction 을 세우지 않는다').toBeGreaterThanOrEqual(0);
    expect(markIndex).toBeLessThan(handoffIndex);
  });

  /**
   * @scenario response=two_factor_required
   * @effects handoff_two_factor_step_rendered
   */
  it('인계 응답이 2단계 인증이면 인증번호 단계로 바뀐다', async () => {
    apiPost.mockResolvedValue({
      success: true,
      data: { two_factor_required: true, challenge_id: 'c-1', provider_id: 'g7:core.mail', expires_at: '2026-10-19T10:00:00+09:00' },
    });
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry() });
    await t.render();

    await exchange(t);

    expect(apiPost.mock.calls[0][0]).toBe('/auth/login/handoff');
    expect(apiPost.mock.calls[0][1]).toEqual({ code: 'HANDOFF-CODE' });
    expect(t.getState()._global?.twoFactor?.required).toBe(true);
    expect(t.getState()._global?.twoFactor?.challenge_id).toBe('c-1');
    expect(t.getNavigationHistory()).toEqual([]);

    await t.rerender();
    expect(document.querySelector('input[name="two_factor_code"]')).not.toBeNull();
    expect(document.querySelector('input[name="email"]')).toBeNull();

    t.cleanup();
  });

  /**
   * @scenario response=authenticated
   * @effects handoff_completes_login
   */
  it('인계 응답이 로그인 완료면 현재 사용자를 세우고 이동한다', async () => {
    apiPost.mockResolvedValue({ success: true, data: { token: 'tok', user: { uuid: 'u-1', name: '회원', email: 'm@example.test' } } });
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry() });
    await t.render();

    await exchange(t);

    expect(t.getState()._global?.currentUser?.uuid).toBe('u-1');
    expect(t.getNavigationHistory().length).toBe(1);

    t.cleanup();
  });

  /**
   * @scenario response=invalid_code
   * @effects handoff_failure_shows_error
   */
  it('코드가 무효(422)면 로그인 칸 위에 서버 안내를 싣고 1단계에 머문다', async () => {
    apiPost.mockRejectedValue(Object.assign(new Error('Request failed'), {
      response: { status: 422, data: { message: '로그인 정보가 만료되었거나 올바르지 않습니다.', errors: { code: ['로그인 정보가 만료되었거나 올바르지 않습니다.'] } } },
    }));
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry() });
    await t.render();

    await exchange(t);

    expect(String(t.getState()._global?.loginError ?? '')).toContain('만료되었거나');
    expect(t.getState()._global?.isLoggingIn).toBe(false);
    expect(t.getState()._global?.twoFactor ?? null).toBeNull();

    t.cleanup();
  });
});
