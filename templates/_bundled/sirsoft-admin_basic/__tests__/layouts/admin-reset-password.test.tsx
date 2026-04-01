/**
 * @file admin-reset-password.test.tsx
 * @description 관리자 비밀번호 재설정 레이아웃 렌더링 테스트
 *
 * 테스트 대상:
 * - 토큰 유효 시 비밀번호 재설정 폼이 표시되는지
 * - 토큰 에러 시 경고 메시지와 비밀번호 찾기 링크가 표시되는지
 * - 성공 시 성공 메시지와 로그인 버튼이 표시되는지
 * - 제출 중 상태에서 스피너가 표시되는지
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);
const TestButton: React.FC<any> = ({ className, children, type, disabled, 'data-testid': testId }) => (
  <button className={className} type={type} disabled={disabled} data-testid={testId}>{children}</button>
);
const TestInput: React.FC<any> = ({ className, type, name, placeholder, disabled, required, 'data-testid': testId, ...rest }) => (
  <input className={className} type={type} name={name} placeholder={placeholder} disabled={disabled} required={required} data-testid={testId} readOnly {...rest} />
);
const TestForm: React.FC<any> = ({ className, children }) => <form className={className}>{children}</form>;
const TestLabel: React.FC<any> = ({ className, children }) => <label className={className}>{children}</label>;
const TestSelect: React.FC<any> = ({ className, 'data-testid': testId }) => <select className={className} data-testid={testId} readOnly />;
const TestH1: React.FC<any> = ({ className, children }) => <h1 className={className}>{children}</h1>;
const TestP: React.FC<any> = ({ className, children, 'data-testid': testId }) => <p className={className} data-testid={testId}>{children}</p>;
const TestSpan: React.FC<any> = ({ className, children }) => <span className={className}>{children}</span>;
const TestIcon: React.FC<any> = ({ name, size, color }) => <i data-icon={name} data-size={size} className={color} />;
const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Form: { component: TestForm, metadata: { name: 'Form', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

// 비밀번호 재설정 레이아웃 — 정상 상태 (토큰 유효)
const resetPasswordLayout = {
  version: '1.0.0',
  layout_name: 'test_admin_reset_password',
  state: {
    isSubmitting: false,
    success: false,
    tokenError: false,
    tokenValid: true,
    resetError: null,
    resetErrors: null,
  },
  components: [
    {
      id: 'reset_card',
      type: 'basic',
      name: 'Div',
      props: { className: 'bg-white dark:bg-gray-800 rounded-lg shadow-md p-8' },
      children: [
        {
          id: 'reset_header',
          type: 'basic',
          name: 'Div',
          props: { className: 'text-center mb-8' },
          children: [
            {
              id: 'reset_icon_wrapper',
              type: 'basic',
              name: 'Div',
              if: '{{!_local.tokenError}}',
              props: { className: 'w-16 h-16 mx-auto mb-4 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center' },
              children: [
                { id: 'reset_lock_icon', type: 'basic', name: 'Icon', props: { name: 'lock', size: 'xl', color: 'text-blue-600 dark:text-blue-400' } },
              ],
            },
            {
              id: 'reset_warning_icon_wrapper',
              type: 'basic',
              name: 'Div',
              if: '{{_local.tokenError}}',
              props: { className: 'w-16 h-16 mx-auto mb-4 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center' },
              children: [
                { id: 'reset_warning_icon', type: 'basic', name: 'Icon', props: { name: 'triangle-exclamation', size: 'xl', color: 'text-red-600 dark:text-red-400' } },
              ],
            },
            {
              id: 'reset_title',
              type: 'basic',
              name: 'H1',
              if: '{{!_local.tokenError}}',
              props: { className: 'text-2xl font-bold text-gray-900 dark:text-white mb-2' },
              text: '비밀번호 재설정',
            },
            {
              id: 'reset_title_invalid',
              type: 'basic',
              name: 'H1',
              if: '{{_local.tokenError}}',
              props: { className: 'text-2xl font-bold text-gray-900 dark:text-white mb-2' },
              text: '유효하지 않은 링크',
            },
            {
              id: 'reset_subtitle',
              type: 'basic',
              name: 'P',
              if: '{{!_local.tokenError && !_local.success}}',
              props: { className: 'text-sm text-gray-600 dark:text-gray-400' },
              text: '새로운 비밀번호를 입력해주세요.',
            },
          ],
        },
        {
          id: 'reset_token_error',
          type: 'basic',
          name: 'Div',
          if: '{{_local.tokenError}}',
          props: { className: 'text-center' },
          children: [
            {
              id: 'reset_token_error_text',
              type: 'basic',
              name: 'P',
              props: { className: 'text-gray-600 dark:text-gray-400 mb-6' },
              text: '비밀번호 재설정 링크가 만료되었거나 유효하지 않습니다.',
            },
            {
              id: 'reset_token_error_button',
              type: 'basic',
              name: 'Button',
              props: {
                type: 'button',
                className: 'w-full px-4 py-3 rounded-lg font-medium bg-blue-600 text-white',
                'data-testid': 'go-to-forgot',
              },
              actions: [
                { type: 'click', handler: 'navigate', params: { path: '/admin/forgot-password' } },
              ],
              text: '비밀번호 찾기로 이동',
            },
          ],
        },
        {
          id: 'reset_success',
          type: 'basic',
          name: 'Div',
          if: '{{_local.success && !_local.tokenError}}',
          props: { className: 'text-center' },
          children: [
            {
              id: 'reset_success_icon_wrapper',
              type: 'basic',
              name: 'Div',
              props: { className: 'mb-4 p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-lg' },
              children: [
                {
                  id: 'reset_success_text',
                  type: 'basic',
                  name: 'P',
                  props: { className: 'text-green-700 dark:text-green-300 text-sm' },
                  text: '비밀번호가 성공적으로 변경되었습니다.',
                },
              ],
            },
            {
              id: 'reset_success_login_button',
              type: 'basic',
              name: 'Button',
              props: {
                type: 'button',
                className: 'w-full px-4 py-3 rounded-lg font-medium bg-blue-600 text-white',
                'data-testid': 'go-to-login',
              },
              actions: [
                { type: 'click', handler: 'navigate', params: { path: '/admin/login' } },
              ],
              text: '로그인하기',
            },
          ],
        },
        {
          id: 'reset_form',
          type: 'basic',
          name: 'Form',
          if: '{{!_local.tokenError && !_local.success}}',
          props: { className: 'space-y-6' },
          children: [
            {
              id: 'reset_password_field',
              type: 'basic',
              name: 'Div',
              children: [
                {
                  id: 'reset_password_label',
                  type: 'basic',
                  name: 'Label',
                  props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2' },
                  text: '새 비밀번호',
                },
                {
                  id: 'reset_password_input',
                  type: 'basic',
                  name: 'Input',
                  props: {
                    type: 'password',
                    name: 'password',
                    className: "{{_local.resetErrors?.password ? 'w-full px-4 py-3 border border-red-500 rounded-lg' : 'w-full px-4 py-3 border border-gray-300 rounded-lg'}}",
                    required: true,
                    disabled: '{{_local.isSubmitting ?? false}}',
                    'data-testid': 'password-input',
                  },
                },
                {
                  id: 'reset_password_error',
                  type: 'basic',
                  name: 'P',
                  if: '{{_local.resetErrors?.password}}',
                  props: { className: 'mt-2 text-sm text-red-600 dark:text-red-400', 'data-testid': 'password-error' },
                  text: "{{Array.isArray(_local.resetErrors?.password) ? _local.resetErrors.password[0] : _local.resetErrors?.password}}",
                },
              ],
            },
            {
              id: 'reset_password_confirm_field',
              type: 'basic',
              name: 'Div',
              children: [
                {
                  id: 'reset_password_confirm_label',
                  type: 'basic',
                  name: 'Label',
                  props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2' },
                  text: '비밀번호 확인',
                },
                {
                  id: 'reset_password_confirm_input',
                  type: 'basic',
                  name: 'Input',
                  props: {
                    type: 'password',
                    name: 'password_confirmation',
                    className: "{{_local.resetErrors?.password_confirmation ? 'w-full px-4 py-3 border border-red-500 rounded-lg' : 'w-full px-4 py-3 border border-gray-300 rounded-lg'}}",
                    required: true,
                    disabled: '{{_local.isSubmitting ?? false}}',
                    'data-testid': 'password-confirm-input',
                  },
                },
                {
                  id: 'reset_password_confirm_error',
                  type: 'basic',
                  name: 'P',
                  if: '{{_local.resetErrors?.password_confirmation}}',
                  props: { className: 'mt-2 text-sm text-red-600 dark:text-red-400', 'data-testid': 'password-confirm-error' },
                  text: "{{Array.isArray(_local.resetErrors?.password_confirmation) ? _local.resetErrors.password_confirmation[0] : _local.resetErrors?.password_confirmation}}",
                },
              ],
            },
            {
              id: 'reset_submit_button',
              type: 'basic',
              name: 'Button',
              props: {
                type: 'submit',
                className: 'w-full px-4 py-3 rounded-lg font-medium bg-blue-600 text-white',
                disabled: '{{_local.isSubmitting ?? false}}',
                'data-testid': 'submit-button',
              },
              children: [
                {
                  id: 'reset_submit_spinner',
                  type: 'basic',
                  name: 'Div',
                  if: '{{_local.isSubmitting}}',
                  props: { className: 'w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin', 'data-testid': 'spinner' },
                },
                {
                  id: 'reset_submit_text',
                  type: 'basic',
                  name: 'Span',
                  if: '{{!_local.isSubmitting}}',
                  text: '비밀번호 변경',
                },
                {
                  id: 'reset_submit_processing',
                  type: 'basic',
                  name: 'Span',
                  if: '{{_local.isSubmitting}}',
                  text: '처리 중...',
                },
              ],
            },
          ],
        },
      ],
    },
  ],
};

describe('관리자 비밀번호 재설정 레이아웃', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  afterEach(() => {
    testUtils?.cleanup();
  });

  describe('정상 상태 (토큰 유효, 폼 표시)', () => {
    it('비밀번호 입력 필드가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(resetPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByTestId('password-input')).toBeInTheDocument();
      expect(screen.getByTestId('password-confirm-input')).toBeInTheDocument();
    });

    it('제목과 부제목이 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(resetPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByText('비밀번호 재설정')).toBeInTheDocument();
      expect(screen.getByText('새로운 비밀번호를 입력해주세요.')).toBeInTheDocument();
    });

    it('lock 아이콘이 표시된다 (warning 아이콘 아님)', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(resetPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(document.querySelector('[data-icon="lock"]')).not.toBeNull();
      expect(document.querySelector('[data-icon="triangle-exclamation"]')).toBeNull();
    });

    it('비밀번호 변경 버튼이 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(resetPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByText('비밀번호 변경')).toBeInTheDocument();
    });

    it('토큰 에러 메시지가 숨겨져 있다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(resetPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.queryByText('비밀번호 재설정 링크가 만료되었거나 유효하지 않습니다.')).toBeNull();
    });

    it('password 타입 input이 사용된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(resetPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      const pwInput = screen.getByTestId('password-input');
      expect(pwInput).toHaveAttribute('type', 'password');
      const confirmInput = screen.getByTestId('password-confirm-input');
      expect(confirmInput).toHaveAttribute('type', 'password');
    });
  });

  describe('토큰 에러 상태', () => {
    const tokenErrorLayout = {
      ...resetPasswordLayout,
      layout_name: 'test_admin_reset_password_token_error',
      state: { isSubmitting: false, success: false, tokenError: true, tokenValid: false, resetError: null, resetErrors: null },
    };

    it('경고 아이콘이 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(tokenErrorLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(document.querySelector('[data-icon="triangle-exclamation"]')).not.toBeNull();
      expect(document.querySelector('[data-icon="lock"]')).toBeNull();
    });

    it('유효하지 않은 링크 제목이 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(tokenErrorLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByText('유효하지 않은 링크')).toBeInTheDocument();
      expect(screen.queryByText('비밀번호 재설정')).toBeNull();
    });

    it('에러 메시지와 비밀번호 찾기로 이동 버튼이 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(tokenErrorLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByText('비밀번호 재설정 링크가 만료되었거나 유효하지 않습니다.')).toBeInTheDocument();
      expect(screen.getByTestId('go-to-forgot')).toBeInTheDocument();
      expect(screen.getByText('비밀번호 찾기로 이동')).toBeInTheDocument();
    });

    it('폼이 숨겨진다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(tokenErrorLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.queryByTestId('password-input')).toBeNull();
      expect(screen.queryByTestId('submit-button')).toBeNull();
    });
  });

  describe('성공 상태', () => {
    const successLayout = {
      ...resetPasswordLayout,
      layout_name: 'test_admin_reset_password_success',
      state: { isSubmitting: false, success: true, tokenError: false, tokenValid: true, resetError: null, resetErrors: null },
    };

    it('성공 메시지가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(successLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByText('비밀번호가 성공적으로 변경되었습니다.')).toBeInTheDocument();
    });

    it('로그인하기 버튼이 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(successLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByTestId('go-to-login')).toBeInTheDocument();
      expect(screen.getByText('로그인하기')).toBeInTheDocument();
    });

    it('폼이 숨겨진다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(successLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.queryByTestId('password-input')).toBeNull();
    });
  });

  describe('제출 중 상태', () => {
    const submittingLayout = {
      ...resetPasswordLayout,
      layout_name: 'test_admin_reset_password_submitting',
      state: { isSubmitting: true, success: false, tokenError: false, tokenValid: true, resetError: null, resetErrors: null },
    };

    it('스피너가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(submittingLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByTestId('spinner')).toBeInTheDocument();
    });

    it('처리 중 텍스트가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(submittingLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByText('처리 중...')).toBeInTheDocument();
      expect(screen.queryByText('비밀번호 변경')).toBeNull();
    });
  });

  describe('필드 에러 상태 (resetErrors 존재)', () => {
    const errorLayout = {
      ...resetPasswordLayout,
      layout_name: 'test_admin_reset_password_field_error',
      state: {
        isSubmitting: false,
        success: false,
        tokenError: false,
        tokenValid: true,
        resetError: 'The given data was invalid.',
        resetErrors: {
          password: ['비밀번호는 8자 이상이어야 합니다.'],
          password_confirmation: ['비밀번호 확인이 일치하지 않습니다.'],
        },
      },
    };

    it('비밀번호 입력 필드에 빨간 테두리가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(errorLayout, { componentRegistry: registry });
      await testUtils.render();

      const pwInput = screen.getByTestId('password-input');
      expect(pwInput.className).toContain('border-red-500');
      expect(pwInput.className).not.toContain('border-gray-300');
    });

    it('비밀번호 필드 아래 에러 메시지가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(errorLayout, { componentRegistry: registry });
      await testUtils.render();

      const pwError = screen.getByTestId('password-error');
      expect(pwError).toBeInTheDocument();
      expect(pwError.textContent).toBe('비밀번호는 8자 이상이어야 합니다.');
    });

    it('비밀번호 확인 입력 필드에 빨간 테두리가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(errorLayout, { componentRegistry: registry });
      await testUtils.render();

      const confirmInput = screen.getByTestId('password-confirm-input');
      expect(confirmInput.className).toContain('border-red-500');
    });

    it('비밀번호 확인 필드 아래 에러 메시지가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(errorLayout, { componentRegistry: registry });
      await testUtils.render();

      const confirmError = screen.getByTestId('password-confirm-error');
      expect(confirmError).toBeInTheDocument();
      expect(confirmError.textContent).toBe('비밀번호 확인이 일치하지 않습니다.');
    });
  });

  describe('초기 상태에서 에러 요소 미표시', () => {
    it('에러 메시지와 빨간 테두리가 없다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(resetPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.queryByTestId('password-error')).toBeNull();
      expect(screen.queryByTestId('password-confirm-error')).toBeNull();

      const pwInput = screen.getByTestId('password-input');
      expect(pwInput.className).toContain('border-gray-300');
      expect(pwInput.className).not.toContain('border-red-500');
    });
  });
});
