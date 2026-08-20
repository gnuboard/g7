/**
 * @file login.test.tsx
 * @description 로그인 폼 레이아웃 렌더링 테스트 (Issue #72)
 *
 * 테스트 케이스:
 * - 기본 렌더링: 로그인 폼 구성 요소 확인
 * - 로딩 상태: 버튼 스피너 표시, 입력 필드 비활성화, 블러 오버레이 미표시
 * - 에러 표시: API 에러 메시지 렌더링
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  createLayoutTest,
  screen,
  waitFor,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 정의 ==========

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);

const TestButton: React.FC<{
  type?: string;
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  text?: string;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ type, className, disabled, children, text, onClick, 'data-testid': testId }) => (
  <button
    type={type as 'button' | 'submit' | 'reset'}
    className={className}
    disabled={disabled}
    onClick={onClick}
    data-testid={testId}
  >
    {children || text}
  </button>
);

const TestInput: React.FC<{
  type?: string;
  name?: string;
  placeholder?: string;
  required?: boolean;
  disabled?: boolean;
  className?: string;
  'data-testid'?: string;
}> = ({ type, name, placeholder, required, disabled, className, 'data-testid': testId }) => (
  <input
    type={type}
    name={name}
    placeholder={placeholder}
    required={required}
    disabled={disabled}
    className={className}
    data-testid={testId}
  />
);

const TestPasswordInput: React.FC<{
  name?: string;
  placeholder?: string;
  required?: boolean;
  disabled?: boolean;
  showToggle?: boolean;
  error?: string;
  className?: string;
  'data-testid'?: string;
}> = ({ name, placeholder, required, disabled, className, 'data-testid': testId }) => (
  <input
    type="password"
    name={name}
    placeholder={placeholder}
    required={required}
    disabled={disabled}
    className={className}
    data-testid={testId}
  />
);

const TestForm: React.FC<{
  className?: string;
  children?: React.ReactNode;
  onSubmit?: (e: React.FormEvent) => void;
  'data-testid'?: string;
}> = ({ className, children, onSubmit, 'data-testid': testId }) => (
  <form className={className} onSubmit={onSubmit} data-testid={testId}>{children}</form>
);

const TestLabel: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <label className={className} data-testid={testId}>{children || text}</label>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <p className={className} data-testid={testId}>{children || text}</p>
);

const TestFragment: React.FC<{ children?: React.ReactNode }> =
  ({ children }) => <>{children}</>;

// ========== 테스트용 레이아웃 Fixture ==========

/**
 * 로그인 폼 레이아웃 (partial을 해결한 형태)
 * _login_form.json 기반 — 오버레이 제거 후 상태
 */
const loginFormFixture = {
  version: '1.0.0',
  layout_name: 'auth/login',
  state: {},
  components: [
    {
      id: 'login-form',
      type: 'basic',
      name: 'Form',
      props: { className: 'space-y-6', 'data-testid': 'login-form' },
      children: [
        // API 에러 메시지
        {
          id: 'login-error',
          type: 'basic',
          name: 'Div',
          if: '{{_global.loginError}}',
          props: {
            className: 'p-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800',
            'data-testid': 'login-error',
          },
          children: [
            {
              type: 'basic',
              name: 'P',
              props: { className: 'text-sm text-red-600 dark:text-red-400' },
              text: '{{_global.loginError}}',
            },
          ],
        },
        // 이메일 입력 필드
        {
          id: 'email-field',
          type: 'basic',
          name: 'Div',
          props: { 'data-testid': 'email-field' },
          children: [
            {
              type: 'basic',
              name: 'Label',
              props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3' },
              text: '이메일',
            },
            {
              type: 'basic',
              name: 'Input',
              props: {
                type: 'email',
                name: 'email',
                placeholder: '이메일을 입력하세요',
                required: true,
                disabled: '{{_global.isLoggingIn}}',
                'data-testid': 'email-input',
              },
            },
          ],
        },
        // 비밀번호 입력 필드
        {
          id: 'password-field',
          type: 'basic',
          name: 'Div',
          props: { 'data-testid': 'password-field' },
          children: [
            {
              type: 'basic',
              name: 'Label',
              props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3' },
              text: '비밀번호',
            },
            {
              type: 'basic',
              name: 'PasswordInput',
              props: {
                name: 'password',
                placeholder: '비밀번호를 입력하세요',
                required: true,
                disabled: '{{_global.isLoggingIn}}',
                showToggle: true,
                'data-testid': 'password-input',
              },
            },
          ],
        },
        // 제출 버튼
        {
          id: 'submit-button',
          type: 'basic',
          name: 'Button',
          props: {
            type: 'submit',
            className: 'w-full py-3 mt-12 bg-gray-900 dark:bg-white text-white dark:text-gray-900 rounded-lg font-medium hover:bg-gray-800 dark:hover:bg-gray-100 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2',
            disabled: '{{_global.isLoggingIn}}',
            'data-testid': 'login-submit-btn',
          },
          children: [
            {
              id: 'button-spinner',
              type: 'basic',
              name: 'Div',
              if: '{{_global.isLoggingIn}}',
              props: {
                className: 'w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin',
                'data-testid': 'button-spinner',
              },
            },
            {
              type: 'basic',
              name: 'Span',
              if: '{{!_global.isLoggingIn}}',
              text: '로그인',
            },
            {
              type: 'basic',
              name: 'Span',
              if: '{{_global.isLoggingIn}}',
              props: { 'data-testid': 'login-processing-text' },
              text: '로그인 중...',
            },
          ],
        },
      ],
      actions: [
        {
          type: 'submit',
          handler: 'sequence',
          actions: [
            {
              handler: 'setState',
              params: {
                target: 'global',
                isLoggingIn: true,
                loginError: null,
                loginErrors: null,
              },
            },
            {
              handler: 'login',
              target: 'user',
              params: {
                body: {
                  email: '{{form.email}}',
                  password: '{{form.password}}',
                },
              },
              onSuccess: [
                { handler: 'setState', params: { target: 'global', currentUser: '{{response.user}}' } },
                { handler: 'setState', params: { target: 'global', isLoggingIn: false } },
                { handler: 'toast', params: { type: 'success', message: '로그인 성공' } },
                { handler: 'navigate', params: { path: '{{query.redirect ?? "/"}}' } },
              ],
              onError: [
                { handler: 'setState', params: { target: 'global', isLoggingIn: false, loginError: '{{error.message}}', loginErrors: '{{error.errors}}' } },
              ],
            },
          ],
        },
      ],
    },
  ],
};

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    PasswordInput: { component: TestPasswordInput, metadata: { name: 'PasswordInput', type: 'basic' } },
    Form: { component: TestForm, metadata: { name: 'Form', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// ========== 테스트 케이스 ==========

describe('로그인 폼 레이아웃 렌더링 (Issue #72)', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('기본 렌더링', () => {
    it('로그인 폼이 이메일, 비밀번호, 제출 버튼과 함께 렌더링된다', async () => {
      // Given
      const testUtils = createLayoutTest(loginFormFixture, {
        componentRegistry: registry,
      });

      // When
      await testUtils.render();

      // Then
      expect(screen.getByTestId('login-form')).toBeInTheDocument();
      expect(screen.getByTestId('email-field')).toBeInTheDocument();
      expect(screen.getByTestId('email-input')).toBeInTheDocument();
      expect(screen.getByTestId('password-field')).toBeInTheDocument();
      expect(screen.getByTestId('password-input')).toBeInTheDocument();
      expect(screen.getByTestId('login-submit-btn')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('초기 상태에서 에러 메시지가 표시되지 않는다', async () => {
      // Given: loginError가 없는 초기 상태
      const testUtils = createLayoutTest(loginFormFixture, {
        componentRegistry: registry,
      });

      // When
      await testUtils.render();

      // Then: 에러 div가 렌더링되지 않음
      expect(screen.queryByTestId('login-error')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('로딩 상태 (isLoggingIn)', () => {
    it('로그인 중 블러 오버레이가 표시되지 않는다 (Issue #72 핵심)', async () => {
      // Given: isLoggingIn=true 상태
      const testUtils = createLayoutTest(loginFormFixture, {
        componentRegistry: registry,
        initialState: { _global: { isLoggingIn: true } },
      });

      // When
      await testUtils.render();

      // Then: backdrop-blur 오버레이가 존재하지 않음
      const allDivs = document.querySelectorAll('div');
      const hasBackdropBlur = Array.from(allDivs).some(
        (div) => div.className && div.className.includes('backdrop-blur')
      );
      expect(hasBackdropBlur).toBe(false);

      // But: 버튼 내 스피너는 표시됨
      expect(screen.getByTestId('button-spinner')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('로그인 중 버튼에 스피너와 "로그인 중..." 텍스트가 표시된다', async () => {
      // Given
      const testUtils = createLayoutTest(loginFormFixture, {
        componentRegistry: registry,
        initialState: { _global: { isLoggingIn: true } },
      });

      // When
      await testUtils.render();

      // Then
      expect(screen.getByTestId('button-spinner')).toBeInTheDocument();
      expect(screen.getByTestId('login-processing-text')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('로그인 중 입력 필드가 비활성화된다', async () => {
      // Given
      const testUtils = createLayoutTest(loginFormFixture, {
        componentRegistry: registry,
        initialState: { _global: { isLoggingIn: true } },
      });

      // When
      await testUtils.render();

      // Then
      expect(screen.getByTestId('email-input')).toBeDisabled();
      expect(screen.getByTestId('password-input')).toBeDisabled();
      expect(screen.getByTestId('login-submit-btn')).toBeDisabled();

      testUtils.cleanup();
    });

    it('로그인 중이 아닐 때 스피너가 표시되지 않는다', async () => {
      // Given: isLoggingIn=false (기본)
      const testUtils = createLayoutTest(loginFormFixture, {
        componentRegistry: registry,
        initialState: { _global: { isLoggingIn: false } },
      });

      // When
      await testUtils.render();

      // Then
      expect(screen.queryByTestId('button-spinner')).not.toBeInTheDocument();
      expect(screen.queryByTestId('login-processing-text')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('에러 표시', () => {
    it('loginError 설정 시 에러 메시지가 표시된다', async () => {
      // Given
      const testUtils = createLayoutTest(loginFormFixture, {
        componentRegistry: registry,
        initialState: { _global: { loginError: '이메일 또는 비밀번호가 올바르지 않습니다.' } },
      });

      // When
      await testUtils.render();

      // Then
      expect(screen.getByTestId('login-error')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });
});
