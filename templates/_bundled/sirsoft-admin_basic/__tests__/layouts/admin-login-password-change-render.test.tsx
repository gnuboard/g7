/**
 * @file admin-login-password-change-render.test.tsx
 * @description 관리자 로그인 비밀번호 변경 단계 **렌더링·흐름** 회귀 테스트 (#736, sirsoft-admin_basic)
 *
 * 형제 파일 `admin-login-password-change-step.test.tsx` 는 레이아웃 JSON 구조를 단언한다. 이 파일은
 * 레이아웃을 실제로 렌더하고, 레이아웃이 선언한 로그인 액션을 **그대로 실행**해 코어(AuthManager → 핸들러
 * 반환값 → onSuccess 매핑)를 통과시킨 뒤 상태·화면·토스트·이동을 본다. 상태를 손으로 주입한 화면만 보면
 * 엔진이 응답 키를 버리는 결함(휴면 해제 안내 — D20, 트러블슈팅 backend 사례 51)을 잡지 못한다.
 *
 * @vitest-environment jsdom
 * @since engine-v1.77.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { asArray, actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import adminLogin from '../../layouts/admin_login.json';

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

const TestDiv: React.FC<Common & { role?: string }> = ({ className, children, role }) => (
  <div className={className} role={role}>{children}</div>
);
const TestSpan: React.FC<Common> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<Common & { role?: string }> = ({ className, children, text, role }) => (
  <p className={className} role={role}>{children || text}</p>
);
const TestH1: React.FC<Common> = ({ className, children, text }) => <h1 className={className}>{children || text}</h1>;
const TestLabel: React.FC<Common & { htmlFor?: string }> = ({ className, children, text, htmlFor }) => (
  <label className={className} htmlFor={htmlFor}>{children || text}</label>
);
const TestForm: React.FC<Common> = ({ className, children }) => <form className={className}>{children}</form>;
const TestButton: React.FC<Common & { type?: string; disabled?: boolean }> = ({ type, className, disabled, children, text }) => (
  <button type={type as 'button' | 'submit'} className={className} disabled={disabled}>{children || text}</button>
);
const TestInput: React.FC<{ id?: string; type?: string; name?: string; value?: string; placeholder?: string; className?: string }> = ({
  id, type, name, value, placeholder, className,
}) => <input id={id} type={type} name={name} defaultValue={value} placeholder={placeholder} className={className} />;
const TestSelect: React.FC<{ className?: string }> = ({ className }) => <select className={className} />;
const TestImg: React.FC<{ className?: string; src?: string; alt?: string }> = ({ className, src, alt }) => (
  <img className={className} src={src} alt={alt} />
);
const TestIcon: React.FC<{ className?: string }> = ({ className }) => <i className={className} />;
const TestToast: React.FC = () => <div data-testid="toast-host" />;
const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Form: { component: TestForm, metadata: { name: 'Form', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
    Img: { component: TestImg, metadata: { name: 'Img', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Toast: { component: TestToast, metadata: { name: 'Toast', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const layout = { ...(adminLogin as any), init_actions: [] };

/** 제출 시퀀스에서 핸들러 이름으로 액션을 찾는다(레이아웃이 실제로 선언한 것). */
function submitAction(handler: string): any {
  const form = (adminLogin as any).components[1].children[0].children[1];
  const action = actionsOf(asArray(form.actions)[0]).find((a: any) => a.handler === handler);
  expect(action, `${handler} 액션이 제출 시퀀스에서 사라졌습니다`).toBeDefined();
  return action;
}

const CHANGE_RESPONSE = {
  password_change_required: true,
  challenge_id: '7a1f2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d',
  reason: 'expired',
  can_postpone: false,
  postpone_days: null,
  expired_at: '2026-09-25T00:00:00+09:00',
  expires_at: '2026-10-03T10:10:00+09:00',
};

const CHANGE_LOCAL = {
  isLoggingIn: false,
  loginError: null,
  loginErrors: null,
  loginForm: { email: '', password: '' },
  twoFactor: null,
  passwordChange: {
    required: true,
    challenge_id: CHANGE_RESPONSE.challenge_id,
    reason: 'expired',
    can_postpone: false,
    postpone_days: null,
    expired_at: CHANGE_RESPONSE.expired_at,
    password: '',
    password_confirmation: '',
    error: null,
    errors: null,
  },
};

const ADMIN = { id: 1, uuid: 'admin-uuid', name: '관리자', email: 'admin@example.com' };

/** 코어 `toast` 핸들러가 쌓는 전역 토스트의 문구들 — 하네스의 getToasts() 는 커스텀 핸들러만 잡는다. */
function toastMessages(t: ReturnType<typeof createLayoutTest>): string[] {
  return ((t.getState()._global?.toasts ?? []) as Array<{ message?: string }>).map((toast) => String(toast.message ?? ''));
}

describe('sirsoft-admin_basic 관리자 로그인 — 비밀번호 변경 단계 (#736)', () => {
  beforeEach(() => {
    setupRegistry();
    apiPost.mockReset();
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects change_step_entered_without_token, credential_step_hidden_on_change_step
   */
  it('로그인 응답이 변경 단계면 이동하지 않고 3단계 상태만 선다', async () => {
    apiPost.mockResolvedValue({ success: true, data: CHANGE_RESPONSE });
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry() });
    await t.render();

    await t.triggerAction({ ...submitAction('login'), if: undefined, params: { body: { email: 'admin@example.com', password: 'pw' } } });

    const change = t.getState()._local?.passwordChange;
    expect(change?.required).toBe(true);
    expect(change?.challenge_id).toBe(CHANGE_RESPONSE.challenge_id);
    expect(change?.reason).toBe('expired');
    expect(t.getState()._local?.isLoggingIn).toBe(false);
    // 「2단계 인증이 아니면 성공」으로 판정하면 여기서 대시보드로 이동한다 — 토큰이 없으므로 다시 로그인으로 튕긴다.
    expect(t.getNavigationHistory()).toEqual([]);
    expect(toastMessages(t).some((m) => m.includes('login_success'))).toBe(false);

    t.cleanup();
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=password_change_required
   * @effects change_step_entered_without_token, credential_step_hidden_on_change_step
   */
  it('변경 단계 화면에는 새 비밀번호 두 칸만 있고 자격증명·인증번호 칸은 없다', async () => {
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry(), initialState: { _local: CHANGE_LOCAL } });
    await t.render();

    expect(document.querySelector('input[name="password_change_new"]')).not.toBeNull();
    expect(document.querySelector('input[name="password_change_confirm"]')).not.toBeNull();
    expect(document.querySelector('input[name="email"]')).toBeNull();
    expect(document.querySelector('input[name="password"]')).toBeNull();
    expect(document.querySelector('input[name="two_factor_code"]')).toBeNull();

    t.cleanup();
  });

  /**
   * @scenario reason=expired, can_postpone=true, response=password_change_required
   * @effects postpone_button_only_when_allowed
   */
  it.each([
    ['만료·변경해야 로그인', { reason: 'expired', can_postpone: false }, false],
    ['만료·다음에 변경 허용', { reason: 'expired', can_postpone: true, postpone_days: 30 }, true],
    ['관리자 지정', { reason: 'admin_assigned', can_postpone: false, expired_at: null }, false],
  ])('%s — 「다음에 변경」 버튼은 연기 허용일 때만 보인다', async (_label, override, visible) => {
    const t = createLayoutTest(layout, {
      componentRegistry: setupRegistry(),
      initialState: { _local: { ...CHANGE_LOCAL, passwordChange: { ...CHANGE_LOCAL.passwordChange, ...override } } },
    });
    await t.render();

    const text = document.body.textContent ?? '';
    expect(text.includes('auth.login.password_change.postpone')).toBe(visible);
    expect(text.includes('auth.login.password_change.reason_admin_assigned')).toBe(override.reason === 'admin_assigned');

    t.cleanup();
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=ok, dormancy=restored
   * @effects change_completion_logs_in_with_dormancy_notice
   */
  it('새 비밀번호로 완료하면 단계를 비우고 원래 화면으로 이동하며 휴면 해제 안내를 띄운다', async () => {
    apiPost.mockResolvedValue({ success: true, data: { token: 'new-token', user: ADMIN, dormancy_restored: true } });
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry(), initialState: { _local: CHANGE_LOCAL } });
    await t.render();

    await t.triggerAction({
      ...submitAction('loginPasswordChange'),
      if: undefined,
      params: { body: { challenge_id: CHANGE_RESPONSE.challenge_id, password: 'NewPassw0rd!', password_confirmation: 'NewPassw0rd!' } },
    });

    expect(apiPost.mock.calls[0][0]).toBe('/auth/admin/login/password-change');
    expect(t.getState()._local?.passwordChange).toBeNull();
    expect(t.getNavigationHistory().length).toBe(1);
    const messages = toastMessages(t);
    expect(messages.some((m) => m.includes('password_change.changed_toast'))).toBe(true);
    expect(messages.some((m) => m.includes('dormancy_restored'))).toBe(true);

    t.cleanup();
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=challenge_invalid
   * @effects invalid_challenge_returns_to_credentials
   */
  it('식별자 만료(422 challenge_id)면 단계를 비우고 처음부터 다시 로그인하라고 안내한다', async () => {
    apiPost.mockRejectedValue(Object.assign(new Error('Request failed'), {
      response: { status: 422, data: { message: '처음부터 다시 로그인해 주세요.', errors: { challenge_id: ['만료'] } } },
    }));
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry(), initialState: { _local: CHANGE_LOCAL } });
    await t.render();

    await t.triggerAction({
      ...submitAction('loginPasswordChange'),
      if: undefined,
      params: { body: { challenge_id: 'x', password: 'a', password_confirmation: 'a' } },
    });

    expect(t.getState()._local?.passwordChange).toBeNull();
    expect(String(t.getState()._local?.loginError ?? '')).toContain('password_change.restart_required');
    expect(t.getNavigationHistory()).toEqual([]);

    t.cleanup();
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=422_password
   * @effects field_errors_rendered_under_inputs
   */
  it('규칙 위반·재사용(422 password)은 단계에 머물며 칸 아래에 문구를 싣는다', async () => {
    apiPost.mockRejectedValue(Object.assign(new Error('Request failed'), {
      response: { status: 422, data: { message: '최근에 사용한 비밀번호입니다.', errors: { password: ['최근에 사용한 비밀번호입니다.'] } } },
    }));
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry(), initialState: { _local: CHANGE_LOCAL } });
    await t.render();

    await t.triggerAction({
      ...submitAction('loginPasswordChange'),
      if: undefined,
      params: { body: { challenge_id: CHANGE_RESPONSE.challenge_id, password: 'Old', password_confirmation: 'Old' } },
    });

    // 하네스의 setState 는 얕게 합치므로 `passwordChange.*` 점 경로 갱신 뒤 다른 키는 여기서 보이지 않는다(실제 렌더러는 깊게 합친다 —
    // 2단계 인증의 `twoFactor.error` 와 같은 방식). 단계에 머무는지는 아래 이동 없음으로 본다.
    const change = t.getState()._local?.passwordChange;
    expect(change?.errors?.password).toEqual(['최근에 사용한 비밀번호입니다.']);
    expect(t.getNavigationHistory()).toEqual([]);
    // 칸 오류가 있으면 상단 오류 상자에 같은 문구를 중복으로 싣지 않는다.
    expect(change?.error ?? null).toBeNull();

    t.cleanup();
  });

  /**
   * 휴면 해제 안내(D20) — 수정 전 엔진은 `login` 핸들러가 `dormancy_restored` 를 버려 이 토스트가 한 번도 뜨지 않았다.
   *
   * @scenario reason=none, response=ok, dormancy=restored
   * @effects dormancy_notice_reaches_layout_on_plain_login
   */
  it('[case:backend-51] 변경 단계 없는 로그인도 휴면이 풀렸으면 안내 토스트를 띄운다', async () => {
    apiPost.mockResolvedValue({ success: true, data: { token: 't', user: ADMIN, dormancy_restored: true } });
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry() });
    await t.render();

    await t.triggerAction({ ...submitAction('login'), if: undefined, params: { body: { email: 'admin@example.com', password: 'pw' } } });

    expect(toastMessages(t).some((m) => m.includes('dormancy_restored'))).toBe(true);
    expect(t.getNavigationHistory().length).toBe(1);

    t.cleanup();
  });
});
