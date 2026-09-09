/**
 * @file login-two-factor-render.test.tsx
 * @description 로그인 2단계 인증 단계 **렌더링** 회귀 테스트 (sirsoft-basic)
 *
 * 형제 파일 `login-two-factor-step.test.tsx` 는 레이아웃 JSON 의 구조를 단언한다.
 * 이 파일은 그 JSON 을 **실제로 렌더해** 화면에 무엇이 나타나는지를 단언한다 — 구조가
 * 맞아도 조건식이 어긋나면 두 단계가 함께 보이거나 아무것도 보이지 않을 수 있고,
 * 그 차이는 구조 단언으로 드러나지 않는다.
 *
 * 핵심 회귀: challenge 응답을 받은 상태에서
 *   - 인증번호 입력이 화면에 있고 이메일·비밀번호는 사라진다
 *   - 오류 박스에 영문 TypeError 원문이 없다 (공개 #133 의 증상)
 *
 * @vitest-environment jsdom
 * @since engine-v1.65.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
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

const TestPasswordInput: React.FC<{ name?: string; disabled?: boolean; className?: string }> = ({
  name,
  disabled,
  className,
}) => <input type="password" name={name} disabled={disabled} className={className} />;

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

/** 챌린지를 받은 뒤의 전역 상태 */
const CHALLENGE_STATE = {
  _global: {
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
  },
};

describe('sirsoft-basic 로그인 폼 렌더링 — 2단계 인증', () => {
  beforeEach(() => {
    setupRegistry();
  });

  /**
   * @scenario step=credentials, response=ok, action=submit
   *
   * @effects credential_step_hidden_on_challenge
   */
  it('초기 상태에서는 이메일·비밀번호만 보이고 인증번호 입력은 없다', async () => {
    const t = createLayoutTest(layout, { componentRegistry: setupRegistry() });
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
      initialState: CHALLENGE_STATE,
    });
    await t.render();

    const code = document.querySelector('input[name="two_factor_code"]');
    expect(code, '챌린지 상태인데 인증번호 입력이 렌더되지 않았습니다').not.toBeNull();
    expect(code?.getAttribute('inputmode')).toBe('numeric');
    expect(code?.getAttribute('autocomplete')).toBe('one-time-code');

    // 두 단계가 함께 보이면 사용자가 어느 쪽을 채워야 하는지 알 수 없다.
    expect(document.querySelector('input[name="email"]')).toBeNull();
    expect(document.querySelector('input[name="password"]')).toBeNull();

    t.cleanup();
  });

  /**
   * @scenario step=code, response=401, action=submit
   *
   * @effects no_raw_typeerror_text
   */
  it('오류 문구 자리에 영문 TypeError 원문이 나타나지 않는다', async () => {
    const t = createLayoutTest(layout, {
      componentRegistry: setupRegistry(),
      initialState: {
        _global: {
          ...CHALLENGE_STATE._global,
          twoFactor: {
            ...CHALLENGE_STATE._global.twoFactor,
            error: '인증번호가 올바르지 않거나 유효시간이 지났습니다.',
          },
        },
      },
    });
    await t.render();

    const body = document.body.textContent ?? '';

    expect(body).toContain('인증번호가 올바르지 않거나 유효시간이 지났습니다.');
    // 공개 #133 의 증상 — 응답 형태를 하나로 가정했을 때 화면에 그대로 실리던 문구다.
    expect(body).not.toContain('Cannot read properties of undefined');
    expect(body).not.toContain('undefined is not an object');

    t.cleanup();
  });

  /**
   * @scenario step=credentials, response=423, action=submit
   *
   * @effects locked_until_rendered
   */
  it('계정이 잠기면 해제 시각 줄이 함께 렌더된다', async () => {
    const t = createLayoutTest(layout, {
      componentRegistry: setupRegistry(),
      initialState: {
        _global: {
          loginError: '로그인 시도 횟수 초과로 계정이 잠겼습니다.',
          loginErrors: { locked_until: '2026-09-07T14:05:00+09:00', permanent: false },
        },
      },
    });
    await t.render();

    // 잠금 안내와 별개로 해제 시각 줄이 존재해야 한다 (문구 자체는 다국어 키 해석 대상).
    const paragraphs = Array.from(document.querySelectorAll('p'));
    expect(paragraphs.length).toBeGreaterThanOrEqual(2);
    expect(document.body.textContent ?? '').toContain('로그인 시도 횟수 초과로 계정이 잠겼습니다.');

    t.cleanup();
  });

  /**
   * 상태 주입이 아니라 **서버 응답**에서 출발한다. 이 레이아웃의 login 액션을 그대로 실행해
   * 코어(AuthManager → 액션 반환값 → onSuccess 매핑)를 통과시킨 뒤 화면을 본다.
   *
   * 자격 증명 값만 리터럴로 바꾼다 — 폼 입력 값(`{{form.*}}`)은 렌더러가 들고 있고 이 하네스가
   * 관측하지 못하는 유일한 조각이기 때문이다. 그 밖의 경로는 전부 실물이다.
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
    const submitSequence = (loginForm as any).actions[0];
    const loginAction = submitSequence.actions.find(
      (a: any) => a.handler === 'login'
    );
    expect(loginAction, 'login 액션이 제출 시퀀스에서 사라졌습니다').toBeDefined();

    await t.triggerAction({
      ...loginAction,
      if: undefined,
      params: { body: { email: 'user@example.com', password: 'pw' } },
    });

    // 코어가 challenge 응답에서 던지면(=#133) 여기까지 오지 못한다.
    expect(apiPost).toHaveBeenCalled();
    const twoFactor = t.getState()._global?.twoFactor;
    expect(twoFactor?.required, 'challenge 응답인데 2단계 상태가 서지 않았습니다').toBe(true);
    expect(twoFactor?.challenge_id).toBe('11111111-2222-3333-4444-555555555555');
    expect(twoFactor?.code).toBe('');

    await t.rerender();

    expect(document.querySelector('input[name="two_factor_code"]')).not.toBeNull();
    expect(document.querySelector('input[name="email"]')).toBeNull();

    const body = document.body.textContent ?? '';
    expect(body).not.toContain('Cannot read properties of undefined');

    t.cleanup();
  });

  /**
   * 응답이 아예 없었던 실패(네트워크 끊김)는 HTTP 오류가 아니다. 그 자리에서 axios 오류 원문이나
   * 내부 식별 문구(`Failed to execute action: login`)가 새면 오류 박스에 영문이 그대로 실린다.
   * 로그인 화면의 오류 문구는 사용자가 읽는 유일한 안내라 그 판정이 곧 화면 품질이다.
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

    const loginAction = (loginForm as any).actions[0].actions.find(
      (a: any) => a.handler === 'login'
    );

    await t.triggerAction({
      ...loginAction,
      if: undefined,
      params: { body: { email: 'user@example.com', password: 'pw' } },
    });

    const loginError = String(t.getState()._global?.loginError ?? '');

    // 다국어 안내로 해석되는 값이어야 한다 — 번역기가 붙지 않은 하네스에서는 그 키가 남는다.
    expect(loginError).toContain('core.errors.network_request_failed');
    // 판정이 무너지면 이 두 형태 중 하나가 그대로 화면에 실린다.
    expect(loginError).not.toContain('Failed to execute action');
    expect(loginError).not.toBe('Network Error');

    t.cleanup();
  });
});
