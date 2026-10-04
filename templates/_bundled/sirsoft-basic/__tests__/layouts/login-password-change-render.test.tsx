/**
 * @file login-password-change-render.test.tsx
 * @description 사용자 로그인 비밀번호 변경 단계 **렌더링·흐름** 회귀 테스트 (#736, sirsoft-basic)
 *
 * 형제 파일 `login-password-change-step.test.tsx` 는 파셜 JSON 구조를 단언한다. 이 파일은 파셜을 실제로 렌더하고
 * 그 파셜이 선언한 로그인 액션을 그대로 실행해 코어(AuthManager → 핸들러 반환값 → onSuccess 매핑)를 통과시킨다.
 * 상태를 손으로 주입한 화면만 보면 엔진이 응답 키를 버리는 결함(휴면 해제 안내 — D20, 트러블슈팅 backend 사례 51)을
 * 잡지 못한다.
 *
 * @vitest-environment jsdom
 * @since engine-v1.77.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { asArray, actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import loginForm from '../../layouts/partials/auth/_login_form.json';

// 공개 #133 의 원인은 레이아웃이 아니라 **응답 형태를 하나로 가정한 코어**였다. 상태를 직접
// 주입해 그린 화면만 단언하면 그 경로를 한 번도 태우지 않으므로, 코어가 다시 challenge 응답에서
// 던지더라도 이 파일은 초록으로 남는다. API 를 모킹해 login 액션을 실제로 통과시킨다.
const apiPost = vi.fn();

vi.mock('@core/api/ApiClient', async () => {
  const actual = await vi.importActual<typeof import('@core/api/ApiClient')>(
    '@core/api/ApiClient'
  );
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

// ========== 테스트용 컴포넌트 ==========

type Common = {
  className?: string;
  children?: React.ReactNode;
  text?: string;
};

const TestDiv: React.FC<Common & { role?: string }> = ({ className, children, role }) => (
  <div className={className} role={role}>{children}</div>
);

const TestSpan: React.FC<Common> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestP: React.FC<Common & { role?: string }> = ({ className, children, text, role }) => (
  <p className={className} role={role}>{children || text}</p>
);

const TestLabel: React.FC<Common> = ({ className, children, text }) => (
  <label className={className}>{children || text}</label>
);

const TestForm: React.FC<Common & { onSubmit?: (e: React.FormEvent) => void }> = ({
  className,
  children,
  onSubmit,
}) => (
  <form className={className} onSubmit={onSubmit}>{children}</form>
);

const TestButton: React.FC<Common & { type?: string; disabled?: boolean }> = ({
  type,
  className,
  disabled,
  children,
  text,
}) => (
  <button type={type as 'button' | 'submit'} className={className} disabled={disabled}>
    {children || text}
  </button>
);

const TestInput: React.FC<{
  type?: string;
  name?: string;
  value?: string;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  inputMode?: string;
  autoComplete?: string;
  maxLength?: number;
}> = ({ type, name, value, placeholder, disabled, className, inputMode, autoComplete, maxLength }) => (
  <input
    type={type}
    name={name}
    defaultValue={value}
    placeholder={placeholder}
    disabled={disabled}
    className={className}
    inputMode={inputMode as any}
    autoComplete={autoComplete}
    maxLength={maxLength}
  />
);

const TestPasswordInput: React.FC<{ name?: string; disabled?: boolean; className?: string; error?: string }> = ({
  name,
  disabled,
  className,
  error,
}) => (
  <>
    <input type="password" name={name} disabled={disabled} className={className} />
    {error ? <p data-testid={'error-' + name}>{error}</p> : null}
  </>
);

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Form: { component: TestForm, metadata: { name: 'Form', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    PasswordInput: { component: TestPasswordInput, metadata: { name: 'PasswordInput', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/** 실제 파셜을 단독 레이아웃으로 감싼다 — 컴포넌트 트리는 그대로다. */
const layout = {
  version: '1.0.0',
  layout_name: 'auth/login',
  components: [loginForm as any],
};

/** 코어 `toast` 핸들러가 쌓는 전역 토스트 문구 */
function toastMessages(t: ReturnType<typeof createLayoutTest>): string[] {
  return ((t.getState()._global?.toasts ?? []) as Array<{ message?: string }>).map((toast) => String(toast.message ?? ''));
}

/**
 * 하네스에 없는 템플릿 커스텀 핸들러(`clearGuestOrderToken` — 비회원 주문조회 토큰 정리)를 성공 분기에서 뺀다.
 * 그 핸들러의 배선은 shop-guest-order-flow.test.tsx 가 잠그고, 여기서는 로그인 흐름만 본다(빼지 않으면 미등록 핸들러
 * 오류로 성공 분기가 중간에 끊긴다).
 */
function withoutGuestTokenCleanup(action: any): any {
  const strip = (list: any): any => asArray<any>(list)
    .filter((a) => a?.handler !== 'clearGuestOrderToken')
    .map((a) => ({ ...a, onSuccess: a.onSuccess ? strip(a.onSuccess) : a.onSuccess }));
  return { ...action, onSuccess: strip(action.onSuccess) };
}

/** 이 파셜이 실제로 선언한 제출 액션 */
function submitAction(handler: string): any {
  const action = actionsOf(asArray((loginForm as any).actions)[0]).find((a: any) => a.handler === handler);
  expect(action, handler + ' 액션이 제출 시퀀스에서 사라졌습니다').toBeDefined();
  return withoutGuestTokenCleanup(action);
}

const CHANGE_RESPONSE = {
  password_change_required: true,
  challenge_id: '7a1f2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d',
  reason: 'expired',
  can_postpone: true,
  postpone_days: 30,
  expired_at: '2026-09-25T00:00:00+09:00',
  expires_at: '2026-10-03T10:10:00+09:00',
};

const CHANGE_STATE = {
  _global: {
    twoFactor: null,
    passwordChange: {
      required: true,
      challenge_id: CHANGE_RESPONSE.challenge_id,
      reason: 'expired',
      can_postpone: true,
      postpone_days: 30,
      expired_at: CHANGE_RESPONSE.expired_at,
      password: '',
      password_confirmation: '',
      error: null,
      errors: null,
    },
    settings: { security: { password_min_length: 10, require_password_special_char: true, password_reuse_prevention_enabled: true, password_reuse_count: 3 } },
  },
};

const USER = { id: 2, uuid: 'user-uuid', name: '홍길동', email: 'hong@example.com' };

describe('sirsoft-basic 로그인 폼 — 비밀번호 변경 단계 (#736)', () => {
  beforeEach(() => {
    setupRegistry();
    apiPost.mockReset();
  });

  /**
   * @scenario reason=expired, can_postpone=true, response=password_change_required
   * @effects change_step_entered_without_token, credential_step_hidden_on_change_step
   */
  it('로그인 응답이 변경 단계면 이동하지 않고 변경 단계로 바뀐다', async () => {
    apiPost.mockResolvedValue({ success: true, data: CHANGE_RESPONSE });
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry() });
    await t.render();

    await t.triggerAction({ ...submitAction('login'), if: undefined, params: { body: { email: 'hong@example.com', password: 'pw' } } });

    const change = t.getState()._global?.passwordChange;
    expect(change?.required).toBe(true);
    expect(change?.can_postpone).toBe(true);
    expect(t.getState()._global?.currentUser ?? null).toBeNull();
    expect(t.getNavigationHistory()).toEqual([]);
    expect(toastMessages(t).some((m) => m.includes('login_success'))).toBe(false);

    await t.rerender();
    expect(document.querySelector('input[name="password_change_new"]')).not.toBeNull();
    expect(document.querySelector('input[name="email"]')).toBeNull();

    t.cleanup();
  });

  /**
   * @scenario reason=expired, can_postpone=true, response=password_change_required
   * @effects postpone_button_only_when_allowed
   */
  it.each([
    [true, true],
    [false, false],
  ])('can_postpone=%s 이면 「다음에 변경」 버튼 노출=%s', async (canPostpone, visible) => {
    const t = createLayoutTest(layout, {
      componentRegistry: setupRegistry(),
      initialState: { _global: { ...CHANGE_STATE._global, passwordChange: { ...CHANGE_STATE._global.passwordChange, can_postpone: canPostpone } } },
    });
    await t.render();
    expect((document.body.textContent ?? '').includes('auth.password_change.postpone')).toBe(visible);
    t.cleanup();
  });

  /**
   * @scenario reason=expired, can_postpone=false, response=ok, dormancy=restored
   * @effects change_completion_logs_in_with_dormancy_notice
   */
  it('새 비밀번호로 완료하면 현재 사용자를 세우고 이동하며 휴면 해제 안내를 띄운다', async () => {
    apiPost.mockResolvedValue({ success: true, data: { token: 'new-token', user: USER, dormancy_restored: true } });
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry(), initialState: CHANGE_STATE });
    await t.render();

    await t.triggerAction({
      ...submitAction('loginPasswordChange'),
      if: undefined,
      params: { body: { challenge_id: CHANGE_RESPONSE.challenge_id, password: 'NewPassw0rd!', password_confirmation: 'NewPassw0rd!' } },
    });

    expect(apiPost.mock.calls[0][0]).toBe('/auth/login/password-change');
    expect(t.getState()._global?.passwordChange).toBeNull();
    expect(t.getState()._global?.currentUser?.uuid).toBe('user-uuid');
    expect(t.getNavigationHistory().length).toBe(1);
    const messages = toastMessages(t);
    expect(messages.some((m) => m.includes('password_change.changed_toast'))).toBe(true);
    expect(messages.some((m) => m.includes('dormancy_restored'))).toBe(true);

    t.cleanup();
  });

  /**
   * @scenario reason=expired, can_postpone=true, response=challenge_invalid
   * @effects invalid_challenge_returns_to_credentials
   */
  it('식별자 만료(422 challenge_id)면 단계를 비우고 다시 로그인하라고 안내한다', async () => {
    apiPost.mockRejectedValue(Object.assign(new Error('Request failed'), {
      response: { status: 422, data: { message: '처음부터', errors: { challenge_id: ['만료'] } } },
    }));
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry(), initialState: CHANGE_STATE });
    await t.render();

    await t.triggerAction({ ...submitAction('loginPasswordChange'), if: undefined, params: { body: { challenge_id: 'x', password: 'a', password_confirmation: 'a' } } });

    expect(t.getState()._global?.passwordChange).toBeNull();
    expect(String(t.getState()._global?.loginError ?? '')).toContain('password_change.restart_required');

    t.cleanup();
  });

  /**
   * @scenario reason=expired, can_postpone=true, response=422_password
   * @effects field_errors_rendered_under_inputs
   */
  it('재사용(422 password)은 새 비밀번호 칸 아래에 싣는다', async () => {
    const t = createLayoutTest(layout, {
      componentRegistry: setupRegistry(),
      initialState: { _global: { ...CHANGE_STATE._global, passwordChange: { ...CHANGE_STATE._global.passwordChange, errors: { password: ['최근에 사용한 비밀번호입니다.'] } } } },
    });
    await t.render();

    expect(document.querySelector('[data-testid="error-password_change_new"]')?.textContent).toBe('최근에 사용한 비밀번호입니다.');

    t.cleanup();
  });

  /**
   * 휴면 해제 안내(D20) — 수정 전 엔진은 `login` 핸들러가 `dormancy_restored` 를 버려 이 토스트가 한 번도 뜨지 않았다.
   *
   * @scenario reason=none, response=ok, dormancy=restored
   * @effects dormancy_notice_reaches_layout_on_plain_login
   */
  it('[case:backend-51] 변경 단계 없는 로그인도 휴면이 풀렸으면 안내 토스트를 띄운다', async () => {
    apiPost.mockResolvedValue({ success: true, data: { token: 't', user: USER, dormancy_restored: true } });
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry() });
    await t.render();

    await t.triggerAction({ ...submitAction('login'), if: undefined, params: { body: { email: 'hong@example.com', password: 'pw' } } });

    expect(toastMessages(t).some((m) => m.includes('dormancy_restored'))).toBe(true);

    t.cleanup();
  });
});
