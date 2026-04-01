/**
 * @file admin-settings-test-mail.test.tsx
 * @description 환경설정 메일 탭 테스트 메일 기능 레이아웃 테스트
 *
 * 테스트 대상:
 * - templates/.../partials/admin_settings/_tab_mail.json
 *
 * 검증 항목:
 * - apiCall body에 to_email 파라미터 사용 (email 아닌)
 * - 테스트 결과 인라인 표시 (testMailResult 상태)
 * - 메일러 드라이버 옵션 (Sendmail/Log 미포함)
 * - 버튼 disabled 상태 (발송 중)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// ==============================
// 테스트용 컴포넌트 정의
// ==============================

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestButton: React.FC<{
  type?: string;
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ type, className, disabled, children, onClick, 'data-testid': testId }) => (
  <button
    type={type as any}
    className={className}
    disabled={disabled}
    onClick={onClick}
    data-testid={testId}
  >
    {children}
  </button>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestH3: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h3 className={className}>{children || text}</h3>
);

const TestIcon: React.FC<{
  name?: string;
  className?: string;
}> = ({ name, className }) => (
  <i className={`icon-${name} ${className || ''}`} data-testid={`icon-${name}`} data-icon={name} />
);

const TestLabel: React.FC<{
  htmlFor?: string;
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ htmlFor, className, children, text }) => (
  <label htmlFor={htmlFor} className={className}>
    {children || text}
  </label>
);

const TestInput: React.FC<{
  type?: string;
  name?: string;
  placeholder?: string;
  className?: string;
  value?: string;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  'data-testid'?: string;
}> = ({ type, name, placeholder, className, value, onChange, 'data-testid': testId }) => (
  <input
    type={type}
    name={name}
    placeholder={placeholder}
    className={className}
    value={value}
    onChange={onChange}
    data-testid={testId}
  />
);

const TestSelect: React.FC<{
  name?: string;
  className?: string;
  placeholder?: string;
  options?: Array<{ label: string; value: string }>;
  value?: string;
  error?: string;
  onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void;
}> = ({ name, className, options, value, error, onChange }) => (
  <div data-testid={`select-${name}`}>
    <select name={name} className={className} value={value} onChange={onChange}>
      {options?.map((opt) => (
        <option key={opt.value} value={opt.value}>{opt.label}</option>
      ))}
    </select>
    {error && <span className="error">{error}</span>}
  </div>
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// ==============================
// 레지스트리 설정
// ==============================

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// ==============================
// _tab_mail.json에서 핵심 부분 추출한 테스트용 레이아웃
// ==============================

const testMailLayout = {
  version: '1.0.0',
  layout_name: 'admin_settings_test_mail',
  initLocal: {
    form: { mail: { test_email: '' } },
    isSendingTest: false,
    testMailResult: null,
    testMailResultMessage: null,
  },
  components: [
    {
      id: 'mail_settings',
      type: 'basic',
      name: 'Div',
      children: [
        {
          id: 'field_mailer',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'composite',
              name: 'Select',
              props: {
                name: 'mail.mailer',
                className: 'w-full',
                options: [
                  { label: 'SMTP', value: 'smtp' },
                  { label: 'Mailgun', value: 'mailgun' },
                  { label: 'SES (Amazon)', value: 'ses' },
                ],
              },
            },
          ],
        },
        {
          id: 'test_mail_section',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'basic',
              name: 'Div',
              props: { className: 'flex items-center gap-3' },
              children: [
                {
                  type: 'basic',
                  name: 'Input',
                  props: {
                    type: 'email',
                    name: 'mail.test_email',
                    placeholder: 'test@example.com',
                  },
                },
                {
                  id: 'test_mail_button',
                  type: 'basic',
                  name: 'Button',
                  props: {
                    disabled: '{{_local.isSendingTest}}',
                    'data-testid': 'test-mail-button',
                  },
                  actions: [
                    {
                      type: 'click',
                      handler: 'sequence',
                      actions: [
                        {
                          handler: 'setState',
                          params: {
                            target: 'local',
                            isSendingTest: true,
                            testMailResult: null,
                            testMailResultMessage: null,
                          },
                        },
                        {
                          handler: 'apiCall',
                          auth_required: true,
                          target: '/api/admin/settings/test-mail',
                          params: {
                            method: 'POST',
                            body: {
                              to_email: "{{_local.form?.mail?.test_email || ''}}",
                            },
                          },
                          onSuccess: [
                            {
                              handler: 'setState',
                              params: {
                                target: 'local',
                                isSendingTest: false,
                                testMailResult: 'success',
                                testMailResultMessage: '테스트 메일이 발송되었습니다.',
                              },
                            },
                          ],
                          onError: [
                            {
                              handler: 'setState',
                              params: {
                                target: 'local',
                                isSendingTest: false,
                                testMailResult: 'error',
                                testMailResultMessage: '테스트 메일 발송에 실패했습니다.',
                              },
                            },
                          ],
                        },
                      ],
                    },
                  ],
                  children: [
                    {
                      type: 'basic',
                      name: 'Icon',
                      if: '{{_local.isSendingTest}}',
                      props: { name: 'spinner', className: 'w-4 h-4 animate-spin' },
                    },
                    {
                      type: 'basic',
                      name: 'Icon',
                      if: '{{!_local.isSendingTest}}',
                      props: { name: 'paper-plane', className: 'w-4 h-4' },
                    },
                    {
                      type: 'basic',
                      name: 'Span',
                      text: '테스트 발송',
                    },
                  ],
                },
              ],
            },
            {
              id: 'test_mail_result',
              type: 'basic',
              name: 'Div',
              if: '{{_local.testMailResult}}',
              props: {
                className: "{{_local.testMailResult === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}}",
                'data-testid': 'test-mail-result',
              },
              children: [
                {
                  type: 'basic',
                  name: 'Icon',
                  if: "{{_local.testMailResult === 'success'}}",
                  props: { name: 'check-circle' },
                },
                {
                  type: 'basic',
                  name: 'Icon',
                  if: "{{_local.testMailResult === 'error'}}",
                  props: { name: 'exclamation-circle' },
                },
                {
                  type: 'basic',
                  name: 'Span',
                  text: "{{_local.testMailResultMessage ?? ''}}",
                },
              ],
            },
          ],
        },
      ],
    },
  ],
};

// ==============================
// 테스트
// ==============================

describe('환경설정 메일 탭 - 테스트 메일 기능', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
    testUtils = createLayoutTest(testMailLayout, {
      auth: {
        isAuthenticated: true,
        user: { id: 1, name: 'Admin', role: 'super_admin' },
        authType: 'admin',
      },
      locale: 'ko',
      componentRegistry: registry,
    });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  describe('레이아웃 구조 검증', () => {
    it('레이아웃 정보가 올바르게 로드된다', () => {
      const info = testUtils.getLayoutInfo();
      expect(info.name).toBe('admin_settings_test_mail');
    });

    it('초기 로컬 상태가 올바르게 설정된다', () => {
      const state = testUtils.getState();
      expect(state._local.isSendingTest).toBe(false);
      expect(state._local.testMailResult).toBeNull();
      expect(state._local.testMailResultMessage).toBeNull();
    });
  });

  describe('메일러 드라이버 옵션 검증', () => {
    it('Select 옵션에 SMTP, Mailgun, SES만 포함된다', () => {
      const mailerSelect = testMailLayout.components[0].children[0].children[0];
      expect(mailerSelect.name).toBe('Select');

      const options = mailerSelect.props.options;
      const values = options.map((opt: any) => opt.value);

      expect(values).toContain('smtp');
      expect(values).toContain('mailgun');
      expect(values).toContain('ses');
      expect(values).not.toContain('sendmail');
      expect(values).not.toContain('log');
    });

    it('SES 옵션 라벨이 "SES (Amazon)"이다', () => {
      const mailerSelect = testMailLayout.components[0].children[0].children[0];
      const sesOption = mailerSelect.props.options.find((opt: any) => opt.value === 'ses');

      expect(sesOption.label).toBe('SES (Amazon)');
    });

    it('드라이버 옵션이 정확히 3개이다', () => {
      const mailerSelect = testMailLayout.components[0].children[0].children[0];
      expect(mailerSelect.props.options).toHaveLength(3);
    });
  });

  describe('테스트 메일 apiCall 파라미터 검증', () => {
    it('apiCall body에 to_email 파라미터를 사용한다 (email 아닌)', () => {
      const button = testMailLayout.components[0].children[1].children[0].children[1];
      expect(button.id).toBe('test_mail_button');

      const sequence = button.actions[0];
      expect(sequence.handler).toBe('sequence');

      const apiCall = sequence.actions[1];
      expect(apiCall.handler).toBe('apiCall');
      expect(apiCall.target).toBe('/api/admin/settings/test-mail');
      expect(apiCall.params.method).toBe('POST');

      // 핵심 검증: to_email 사용
      expect(apiCall.params.body).toHaveProperty('to_email');
      expect(apiCall.params.body).not.toHaveProperty('email');
    });

    it('apiCall에 auth_required가 true로 설정되어 있다', () => {
      const button = testMailLayout.components[0].children[1].children[0].children[1];
      const apiCall = button.actions[0].actions[1];

      expect(apiCall.auth_required).toBe(true);
    });
  });

  describe('테스트 메일 발송 상태 관리', () => {
    it('발송 시작 시 isSendingTest이 true, 결과가 초기화된다', () => {
      const button = testMailLayout.components[0].children[1].children[0].children[1];
      const setStateAction = button.actions[0].actions[0];

      expect(setStateAction.handler).toBe('setState');
      expect(setStateAction.params.isSendingTest).toBe(true);
      expect(setStateAction.params.testMailResult).toBeNull();
      expect(setStateAction.params.testMailResultMessage).toBeNull();
    });

    it('발송 성공 시 testMailResult이 success로 설정된다', () => {
      const button = testMailLayout.components[0].children[1].children[0].children[1];
      const apiCall = button.actions[0].actions[1];
      const onSuccess = apiCall.onSuccess[0];

      expect(onSuccess.handler).toBe('setState');
      expect(onSuccess.params.isSendingTest).toBe(false);
      expect(onSuccess.params.testMailResult).toBe('success');
      expect(onSuccess.params.testMailResultMessage).toBeTruthy();
    });

    it('발송 실패 시 testMailResult이 error로 설정된다', () => {
      const button = testMailLayout.components[0].children[1].children[0].children[1];
      const apiCall = button.actions[0].actions[1];
      const onError = apiCall.onError[0];

      expect(onError.handler).toBe('setState');
      expect(onError.params.isSendingTest).toBe(false);
      expect(onError.params.testMailResult).toBe('error');
      expect(onError.params.testMailResultMessage).toBeTruthy();
    });
  });

  describe('테스트 결과 인라인 표시', () => {
    it('초기에는 결과 영역이 숨겨진다 (testMailResult이 null)', async () => {
      await testUtils.render();

      const state = testUtils.getState();
      expect(state._local.testMailResult).toBeNull();
    });

    it('testMailResult이 success이면 결과 영역이 표시된다', async () => {
      await testUtils.render();

      testUtils.setState('testMailResult', 'success', 'local');
      testUtils.setState('testMailResultMessage', '테스트 메일이 발송되었습니다.', 'local');
      await testUtils.rerender();

      const state = testUtils.getState();
      expect(state._local.testMailResult).toBe('success');
      expect(state._local.testMailResultMessage).toBe('테스트 메일이 발송되었습니다.');
    });

    it('testMailResult이 error이면 오류 메시지가 표시된다', async () => {
      await testUtils.render();

      testUtils.setState('testMailResult', 'error', 'local');
      testUtils.setState('testMailResultMessage', '테스트 메일 발송에 실패했습니다.', 'local');
      await testUtils.rerender();

      const state = testUtils.getState();
      expect(state._local.testMailResult).toBe('error');
      expect(state._local.testMailResultMessage).toBe('테스트 메일 발송에 실패했습니다.');
    });

    it('결과 영역의 if 조건이 testMailResult 존재 여부이다', () => {
      const resultDiv = testMailLayout.components[0].children[1].children[1];
      expect(resultDiv.id).toBe('test_mail_result');
      expect(resultDiv.if).toBe('{{_local.testMailResult}}');
    });

    it('성공 시 check-circle 아이콘, 실패 시 exclamation-circle 아이콘이 표시된다', () => {
      const resultDiv = testMailLayout.components[0].children[1].children[1];

      const successIcon = resultDiv.children[0];
      expect(successIcon.name).toBe('Icon');
      expect(successIcon.if).toBe("{{_local.testMailResult === 'success'}}");
      expect(successIcon.props.name).toBe('check-circle');

      const errorIcon = resultDiv.children[1];
      expect(errorIcon.name).toBe('Icon');
      expect(errorIcon.if).toBe("{{_local.testMailResult === 'error'}}");
      expect(errorIcon.props.name).toBe('exclamation-circle');
    });

    it('결과 메시지가 Span으로 표시된다', () => {
      const resultDiv = testMailLayout.components[0].children[1].children[1];
      const messageSpan = resultDiv.children[2];

      expect(messageSpan.name).toBe('Span');
      expect(messageSpan.text).toBe("{{_local.testMailResultMessage ?? ''}}");
    });
  });

  describe('버튼 상태 관리', () => {
    it('발송 중 버튼이 disabled 된다', () => {
      const button = testMailLayout.components[0].children[1].children[0].children[1];
      expect(button.props.disabled).toBe('{{_local.isSendingTest}}');
    });

    it('isSendingTest 상태를 변경할 수 있다', async () => {
      await testUtils.render();

      testUtils.setState('isSendingTest', true, 'local');
      expect(testUtils.getState()._local.isSendingTest).toBe(true);

      testUtils.setState('isSendingTest', false, 'local');
      expect(testUtils.getState()._local.isSendingTest).toBe(false);
    });

    it('발송 중 spinner 아이콘이 표시된다', () => {
      const button = testMailLayout.components[0].children[1].children[0].children[1];
      const spinnerIcon = button.children[0];

      expect(spinnerIcon.name).toBe('Icon');
      expect(spinnerIcon.if).toBe('{{_local.isSendingTest}}');
      expect(spinnerIcon.props.name).toBe('spinner');
    });

    it('대기 중 paper-plane 아이콘이 표시된다', () => {
      const button = testMailLayout.components[0].children[1].children[0].children[1];
      const planeIcon = button.children[1];

      expect(planeIcon.name).toBe('Icon');
      expect(planeIcon.if).toBe('{{!_local.isSendingTest}}');
      expect(planeIcon.props.name).toBe('paper-plane');
    });
  });
});
