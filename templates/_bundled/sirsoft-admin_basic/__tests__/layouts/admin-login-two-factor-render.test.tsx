/**
 * @file admin-login-two-factor-render.test.tsx
 * @description 관리자 로그인 2단계 인증 단계 **렌더링** 회귀 테스트 (sirsoft-admin_basic)
 *
 * 형제 파일 `admin-login-two-factor-step.test.tsx` 는 레이아웃 JSON 의 구조를 단언한다.
 * 이 파일은 그 JSON 을 실제로 렌더해 화면에 무엇이 나타나는지를 단언한다.
 *
 * 관리자가 들어갈 수 없으면 설정을 되돌릴 수단까지 사라지므로 사용자 화면보다 파급이 크다
 * (공개 #133).
 *
 * @vitest-environment jsdom
 * @since engine-v1.65.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import adminLogin from '../../layouts/admin_login.json';

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

type Common = {
  className?: string;
  children?: React.ReactNode;
  text?: string;
};

const TestDiv: React.FC<Common & { role?: string; id?: string }> = ({ className, children, role }) => (
  <div className={className} role={role}>{children}</div>
);
const TestSpan: React.FC<Common> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);
const TestP: React.FC<Common & { role?: string }> = ({ className, children, text, role }) => (
  <p className={className} role={role}>{children || text}</p>
);
const TestH1: React.FC<Common> = ({ className, children, text }) => (
  <h1 className={className}>{children || text}</h1>
);
const TestLabel: React.FC<Common & { htmlFor?: string }> = ({ className, children, text, htmlFor }) => (
  <label className={className} htmlFor={htmlFor}>{children || text}</label>
);
const TestForm: React.FC<Common & { onSubmit?: (e: React.FormEvent) => void }> = ({
  className,
  children,
  onSubmit,
}) => <form className={className} onSubmit={onSubmit}>{children}</form>;
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
  id?: string;
  type?: string;
  name?: string;
  value?: string;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  inputMode?: string;
  autoComplete?: string;
  maxLength?: number;
}> = ({ id, type, name, value, placeholder, disabled, className, inputMode, autoComplete, maxLength }) => (
  <input
    id={id}
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
const TestSelect: React.FC<{ className?: string; value?: string }> = ({ className }) => (
  <select className={className} />
);
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

/** init_actions 는 렌더 대상이 아니므로 제거하고 상태를 직접 준다. */
const layout = { ...(adminLogin as any), init_actions: [] };

const CHALLENGE_LOCAL = {
  isLoggingIn: false,
  loginError: null,
  loginErrors: null,
  loginForm: { email: '', password: '' },
  twoFactor: {
    required: true,
    challenge_id: '9f1c2f2e-0b3a-4f0a-9a1e-5c1b7f9d2c40',
    provider_id: 'g7:core.mail',
    expires_at: '2026-09-07T14:03:00+09:00',
    code: '',
    error: null,
    verifying: false,
    resending: false,
    resent: false,
  },
};

describe('sirsoft-admin_basic 관리자 로그인 렌더링 — 2단계 인증', () => {
  beforeEach(() => {
    setupRegistry();
  });

  /**
   * @scenario step=credentials, response=ok, action=submit
   *
   * @effects credential_step_hidden_on_challenge
   */
  it('초기 상태에서는 이메일·비밀번호만 보이고 인증번호 입력은 없다', async () => {
    const t = createLayoutTest(layout, {
      componentRegistry: setupRegistry(),
      initialState: { _local: { ...CHALLENGE_LOCAL, twoFactor: null } },
    });
    await t.render();

    expect(document.querySelector('input[name="email"]')).not.toBeNull();
    expect(document.querySelector('input[name="password"]')).not.toBeNull();
    expect(document.querySelector('input[name="two_factor_code"]')).toBeNull();

    t.cleanup();
  });

  /**
   * @scenario step=code, response=challenge, action=submit
   *
   * @effects code_step_rendered_on_challenge, credential_step_hidden_on_challenge
   */
  it('challenge 를 받으면 인증번호 입력으로 바뀌고 자격 증명 입력은 사라진다', async () => {
    const t = createLayoutTest(layout, {
      componentRegistry: setupRegistry(),
      initialState: { _local: CHALLENGE_LOCAL },
    });
    await t.render();

    const code = document.querySelector('input[name="two_factor_code"]');
    expect(code, '챌린지 상태인데 인증번호 입력이 렌더되지 않았습니다').not.toBeNull();
    expect(code?.getAttribute('inputmode')).toBe('numeric');
    expect(code?.getAttribute('autocomplete')).toBe('one-time-code');

    expect(document.querySelector('input[name="email"]')).toBeNull();
    expect(document.querySelector('input[name="password"]')).toBeNull();

    t.cleanup();
  });

  /**
   * @scenario step=code, response=403, action=submit
   *
   * @effects no_raw_typeerror_text, admin_required_message_rendered_in_code_step
   */
  it('관리자 아님(403) 문구가 인증번호 오류 자리에 표시되고 TypeError 원문은 없다', async () => {
    const t = createLayoutTest(layout, {
      componentRegistry: setupRegistry(),
      initialState: {
        _local: {
          ...CHALLENGE_LOCAL,
          twoFactor: { ...CHALLENGE_LOCAL.twoFactor, error: '관리자 권한이 필요합니다.' },
        },
      },
    });
    await t.render();

    const body = document.body.textContent ?? '';

    expect(body).toContain('관리자 권한이 필요합니다.');
    expect(body).not.toContain('Cannot read properties of undefined');

    t.cleanup();
  });

  /**
   * @scenario step=credentials, response=401, action=submit
   *
   * @effects toast_host_mounted_on_standalone_layout
   */
  it('독립 레이아웃이므로 Toast 호스트가 함께 렌더된다', async () => {
    const t = createLayoutTest(layout, {
      componentRegistry: setupRegistry(),
      initialState: { _local: { ...CHALLENGE_LOCAL, twoFactor: null } },
    });
    await t.render();

    // 호스트가 없으면 안내가 성공으로 기록되고도 화면에 나타나지 않는다.
    expect(document.querySelector('[data-testid="toast-host"]')).not.toBeNull();

    t.cleanup();
  });

  /**
   * 상태 주입이 아니라 **서버 응답**에서 출발한다. 이 레이아웃의 login 액션을 그대로 실행해
   * 코어(AuthManager → 액션 반환값 → onSuccess 매핑)를 통과시킨 뒤 화면을 본다. 관리자 경로는
   * 종전에 서버가 먼저 500 을 내던 자리라 사용자 경로와 같은 강도로 잠가 둔다.
   *
   * 자격 증명 값만 리터럴로 바꾼다 — 폼 입력 값(`{{form.*}}`)은 렌더러가 들고 있고 이 하네스가
   * 관측하지 못하는 유일한 조각이기 때문이다.
   *
   * @scenario step=credentials, response=challenge, action=submit
   *
   * @effects code_step_rendered_on_challenge, credential_step_hidden_on_challenge, no_raw_typeerror_text
   */
  it('challenge 응답을 실제로 받으면 2단계로 넘어가고 오류 원문이 남지 않는다', async () => {
    apiPost.mockReset();
    apiPost.mockResolvedValue({
      success: true,
      data: {
        two_factor_required: true,
        challenge_id: '11111111-2222-3333-4444-555555555555',
        provider_id: 'g7:core.mail',
        expires_at: '2026-09-07T14:03:00+09:00',
      },
    });

    const t = createLayoutTest(layout, { componentRegistry: setupRegistry() });
    await t.render();

    // 이 레이아웃이 실제로 선언한 login 액션 — 자격 증명만 리터럴로 채운다.
    const form = (adminLogin as any).components[1].children[0].children[1];
    const loginAction = form.actions[0].actions.find(
      (a: any) => a.handler === 'login'
    );
    expect(loginAction, 'login 액션이 제출 시퀀스에서 사라졌습니다').toBeDefined();
    expect(loginAction.target, '관리자 로그인은 admin 대상이어야 합니다').toBe('admin');

    await t.triggerAction({
      ...loginAction,
      if: undefined,
      params: { body: { email: 'admin@example.com', password: 'pw' } },
    });

    // 코어가 challenge 응답에서 던지면(=#133) 여기까지 오지 못한다.
    expect(apiPost).toHaveBeenCalled();
    const twoFactor = t.getState()._local?.twoFactor;
    expect(twoFactor?.required, 'challenge 응답인데 2단계 상태가 서지 않았습니다').toBe(true);
    expect(twoFactor?.challenge_id).toBe('11111111-2222-3333-4444-555555555555');
    expect(twoFactor?.code).toBe('');

    const body = document.body.textContent ?? '';
    expect(body).not.toContain('Cannot read properties of undefined');

    t.cleanup();
  });

  /**
   * 응답이 아예 없었던 실패(네트워크 끊김)는 HTTP 오류가 아니다. 그 자리에서 axios 오류 원문이나
   * 내부 식별 문구(`Failed to execute action: login`)가 새면 오류 박스에 영문이 그대로 실린다.
   *
   * @scenario step=credentials, response=network, action=submit
   *
   * @effects network_message_translated
   */
  it('네트워크 실패는 영문 원문이 아니라 다국어 안내로 오류 박스에 실린다', async () => {
    apiPost.mockReset();
    apiPost.mockRejectedValue(Object.assign(new Error('Network Error'), {
      code: 'ERR_NETWORK',
    }));

    const t = createLayoutTest(layout, { componentRegistry: setupRegistry() });
    await t.render();

    const form = (adminLogin as any).components[1].children[0].children[1];
    const loginAction = form.actions[0].actions.find((a: any) => a.handler === 'login');

    await t.triggerAction({
      ...loginAction,
      if: undefined,
      params: { body: { email: 'admin@example.com', password: 'pw' } },
    });

    const loginError = String(t.getState()._local?.loginError ?? '');

    expect(loginError).toContain('core.errors.network_request_failed');
    expect(loginError).not.toContain('Failed to execute action');
    expect(loginError).not.toBe('Network Error');

    t.cleanup();
  });
});
