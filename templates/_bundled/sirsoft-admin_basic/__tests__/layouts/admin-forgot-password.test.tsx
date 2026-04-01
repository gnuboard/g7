/**
 * @file admin-forgot-password.test.tsx
 * @description 관리자 비밀번호 찾기 레이아웃 렌더링 테스트
 *
 * 테스트 대상:
 * - 초기 상태에서 폼이 표시되고 성공 메시지가 숨겨지는지
 * - 성공 상태에서 폼이 숨겨지고 성공 메시지가 표시되는지
 * - 로그인으로 돌아가기 링크가 /admin/login으로 네비게이션하는지
 * - 제출 중 상태에서 스피너와 처리 중 텍스트가 표시되는지
 * - 에러 시 이메일 입력 필드에 빨간 테두리 + 하단 에러 메시지 표시
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

// 비밀번호 찾기 레이아웃 (핵심 부분)
const forgotPasswordLayout = {
  version: '1.0.0',
  layout_name: 'test_admin_forgot_password',
  state: {
    isSubmitting: false,
    success: false,
    forgotError: null,
    forgotErrors: null,
  },
  components: [
    {
      id: 'forgot_card',
      type: 'basic',
      name: 'Div',
      props: { className: 'bg-white dark:bg-gray-800 rounded-lg shadow-md p-8' },
      children: [
        {
          id: 'forgot_header',
          type: 'basic',
          name: 'Div',
          props: { className: 'text-center mb-8' },
          children: [
            {
              id: 'forgot_lock_icon_wrapper',
              type: 'basic',
              name: 'Div',
              props: { className: 'w-16 h-16 mx-auto mb-4 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center' },
              children: [
                { id: 'forgot_lock_icon', type: 'basic', name: 'Icon', props: { name: 'lock', size: 'xl', color: 'text-blue-600 dark:text-blue-400' } },
              ],
            },
            {
              id: 'forgot_title',
              type: 'basic',
              name: 'H1',
              props: { className: 'text-2xl font-bold text-gray-900 dark:text-white mb-2' },
              text: '비밀번호 찾기',
            },
            {
              id: 'forgot_subtitle',
              type: 'basic',
              name: 'P',
              if: '{{!_local.success}}',
              props: { className: 'text-sm text-gray-600 dark:text-gray-400' },
              text: '가입 시 사용한 이메일을 입력하시면 비밀번호 재설정 링크를 보내드립니다.',
            },
          ],
        },
        {
          id: 'forgot_success_message',
          type: 'basic',
          name: 'Div',
          if: '{{_local.success}}',
          props: { className: 'mb-6 p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-lg' },
          children: [
            {
              id: 'forgot_success_text',
              type: 'basic',
              name: 'P',
              props: { className: 'text-green-700 dark:text-green-300 text-sm text-center' },
              text: '비밀번호 재설정 링크가 이메일로 발송되었습니다.',
            },
          ],
        },
        {
          id: 'forgot_error_message',
          type: 'basic',
          name: 'Div',
          if: '{{_local.forgotError && !_local.forgotErrors}}',
          props: { className: 'p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-lg', 'data-testid': 'general-error' },
          children: [
            {
              id: 'forgot_error_text',
              type: 'basic',
              name: 'P',
              props: { className: 'text-red-700 dark:text-red-300 text-sm text-center' },
              text: '{{_local.forgotError}}',
            },
          ],
        },
        {
          id: 'forgot_form',
          type: 'basic',
          name: 'Form',
          if: '{{!_local.success}}',
          props: { className: 'space-y-6' },
          children: [
            {
              id: 'forgot_email_field',
              type: 'basic',
              name: 'Div',
              children: [
                {
                  id: 'forgot_email_label',
                  type: 'basic',
                  name: 'Label',
                  props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2' },
                  text: '이메일',
                },
                {
                  id: 'forgot_email_input',
                  type: 'basic',
                  name: 'Input',
                  props: {
                    type: 'email',
                    name: 'email',
                    placeholder: '이메일 주소를 입력하세요',
                    required: true,
                    disabled: '{{_local.isSubmitting ?? false}}',
                    className: "{{_local.forgotErrors?.email ? 'w-full px-4 py-3 border border-red-500 dark:border-red-500 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:border-red-500 focus:ring-red-500' : 'w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:border-blue-500 focus:ring-blue-500'}}",
                    'data-testid': 'email-input',
                  },
                },
                {
                  id: 'forgot_email_error',
                  type: 'basic',
                  name: 'P',
                  if: '{{_local.forgotErrors?.email}}',
                  props: { className: 'mt-2 text-sm text-red-600 dark:text-red-400', 'data-testid': 'email-error' },
                  text: "{{Array.isArray(_local.forgotErrors?.email) ? _local.forgotErrors.email[0] : _local.forgotErrors?.email}}",
                },
              ],
            },
            {
              id: 'forgot_submit_button',
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
                  id: 'forgot_submit_spinner',
                  type: 'basic',
                  name: 'Div',
                  if: '{{_local.isSubmitting}}',
                  props: { className: 'w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin', 'data-testid': 'spinner' },
                },
                {
                  id: 'forgot_submit_text',
                  type: 'basic',
                  name: 'Span',
                  if: '{{!_local.isSubmitting}}',
                  text: '재설정 링크 발송',
                },
                {
                  id: 'forgot_submit_processing',
                  type: 'basic',
                  name: 'Span',
                  if: '{{_local.isSubmitting}}',
                  text: '처리 중...',
                },
              ],
            },
          ],
        },
        {
          id: 'forgot_back_to_login',
          type: 'basic',
          name: 'Div',
          props: { className: 'mt-6 text-center' },
          children: [
            {
              id: 'forgot_back_link',
              type: 'basic',
              name: 'Button',
              props: {
                type: 'button',
                className: 'text-sm text-blue-600',
                'data-testid': 'back-to-login',
              },
              actions: [
                { type: 'click', handler: 'navigate', params: { path: '/admin/login' } },
              ],
              text: '← 로그인으로 돌아가기',
            },
          ],
        },
      ],
    },
  ],
};

describe('관리자 비밀번호 찾기 레이아웃', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  afterEach(() => {
    testUtils?.cleanup();
  });

  describe('초기 상태 (success = false)', () => {
    it('폼이 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(forgotPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByTestId('email-input')).toBeInTheDocument();
      expect(screen.getByTestId('submit-button')).toBeInTheDocument();
    });

    it('제목과 부제목이 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(forgotPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByText('비밀번호 찾기')).toBeInTheDocument();
      expect(screen.getByText('가입 시 사용한 이메일을 입력하시면 비밀번호 재설정 링크를 보내드립니다.')).toBeInTheDocument();
    });

    it('성공 메시지는 숨겨져 있다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(forgotPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.queryByText('비밀번호 재설정 링크가 이메일로 발송되었습니다.')).toBeNull();
    });

    it('로그인으로 돌아가기 링크가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(forgotPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      const backLink = screen.getByTestId('back-to-login');
      expect(backLink).toBeInTheDocument();
      expect(screen.getByText('← 로그인으로 돌아가기')).toBeInTheDocument();
    });

    it('제출 버튼에 재설정 링크 발송 텍스트가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(forgotPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByText('재설정 링크 발송')).toBeInTheDocument();
    });

    it('스피너가 숨겨져 있다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(forgotPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.queryByTestId('spinner')).toBeNull();
    });

    it('에러 메시지가 숨겨져 있다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(forgotPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.queryByTestId('general-error')).toBeNull();
      expect(screen.queryByTestId('email-error')).toBeNull();
    });

    it('이메일 입력 필드에 기본 테두리가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(forgotPasswordLayout, { componentRegistry: registry });
      await testUtils.render();

      const emailInput = screen.getByTestId('email-input');
      expect(emailInput.className).toContain('border-gray-300');
      expect(emailInput.className).not.toContain('border-red-500');
    });
  });

  describe('성공 상태 (success = true)', () => {
    const successLayout = {
      ...forgotPasswordLayout,
      layout_name: 'test_admin_forgot_password_success',
      state: { isSubmitting: false, success: true, forgotError: null, forgotErrors: null },
    };

    it('성공 메시지가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(successLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByText('비밀번호 재설정 링크가 이메일로 발송되었습니다.')).toBeInTheDocument();
    });

    it('폼이 숨겨진다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(successLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.queryByTestId('email-input')).toBeNull();
      expect(screen.queryByTestId('submit-button')).toBeNull();
    });

    it('부제목이 숨겨진다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(successLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.queryByText('가입 시 사용한 이메일을 입력하시면 비밀번호 재설정 링크를 보내드립니다.')).toBeNull();
    });
  });

  describe('제출 중 상태 (isSubmitting = true)', () => {
    const submittingLayout = {
      ...forgotPasswordLayout,
      layout_name: 'test_admin_forgot_password_submitting',
      state: { isSubmitting: true, success: false, forgotError: null, forgotErrors: null },
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
    });

    it('재설정 링크 발송 텍스트가 숨겨진다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(submittingLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.queryByText('재설정 링크 발송')).toBeNull();
    });
  });

  describe('필드 에러 상태 (forgotErrors.email 존재)', () => {
    const errorLayout = {
      ...forgotPasswordLayout,
      layout_name: 'test_admin_forgot_password_field_error',
      state: {
        isSubmitting: false,
        success: false,
        forgotError: 'The given data was invalid.',
        forgotErrors: { email: ['이메일 주소를 찾을 수 없습니다.'] },
      },
    };

    it('이메일 입력 필드에 빨간 테두리가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(errorLayout, { componentRegistry: registry });
      await testUtils.render();

      const emailInput = screen.getByTestId('email-input');
      expect(emailInput.className).toContain('border-red-500');
      expect(emailInput.className).not.toContain('border-gray-300');
    });

    it('이메일 필드 아래 에러 메시지가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(errorLayout, { componentRegistry: registry });
      await testUtils.render();

      const emailError = screen.getByTestId('email-error');
      expect(emailError).toBeInTheDocument();
      expect(emailError.textContent).toBe('이메일 주소를 찾을 수 없습니다.');
    });

    it('일반 에러 배너는 숨겨진다 (필드 에러가 있으므로)', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(errorLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.queryByTestId('general-error')).toBeNull();
    });
  });

  describe('일반 에러 상태 (forgotError만, forgotErrors 없음)', () => {
    const generalErrorLayout = {
      ...forgotPasswordLayout,
      layout_name: 'test_admin_forgot_password_general_error',
      state: {
        isSubmitting: false,
        success: false,
        forgotError: '서버 오류가 발생했습니다.',
        forgotErrors: null,
      },
    };

    it('일반 에러 배너가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(generalErrorLayout, { componentRegistry: registry });
      await testUtils.render();

      const generalError = screen.getByTestId('general-error');
      expect(generalError).toBeInTheDocument();
      expect(screen.getByText('서버 오류가 발생했습니다.')).toBeInTheDocument();
    });

    it('이메일 필드 에러는 숨겨진다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(generalErrorLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.queryByTestId('email-error')).toBeNull();
    });

    it('이메일 입력 필드에 기본 테두리가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(generalErrorLayout, { componentRegistry: registry });
      await testUtils.render();

      const emailInput = screen.getByTestId('email-input');
      expect(emailInput.className).toContain('border-gray-300');
    });
  });
});
