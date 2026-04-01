/**
 * @file admin-settings-readonly.test.tsx
 * @description 환경설정 읽기 전용 UI 제어 렌더링 테스트
 *
 * 테스트 대상:
 * - can_update: true → footer_buttons 표시, readonly 배너 미표시, Input 활성
 * - can_update: false → footer_buttons 숨김, readonly 배너 표시, Input disabled
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// 테스트용 컴포넌트 정의
const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestButton: React.FC<{
  className?: string;
  disabled?: boolean;
  type?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, disabled, type, children, text, 'data-testid': testId }) => (
  <button className={className} disabled={disabled} type={type as any} data-testid={testId}>
    {children || text}
  </button>
);

const TestInput: React.FC<{
  className?: string;
  disabled?: boolean;
  type?: string;
  name?: string;
  placeholder?: string;
  'data-testid'?: string;
}> = ({ className, disabled, type, name, placeholder, 'data-testid': testId }) => (
  <input
    className={className}
    disabled={disabled}
    type={type}
    name={name}
    placeholder={placeholder}
    data-testid={testId || name}
  />
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

const TestIcon: React.FC<{
  name?: string;
  className?: string;
}> = ({ name, className }) => (
  <i className={className} data-icon={name} data-testid={`icon-${name}`} />
);

const TestPageHeader: React.FC<{
  title?: string;
  description?: string;
  children?: React.ReactNode;
}> = ({ title, description, children }) => (
  <div data-testid="page-header">
    {title && <h1>{title}</h1>}
    {description && <p>{description}</p>}
    {children}
  </div>
);

const TestTabNavigation: React.FC<{
  tabs?: any[];
  activeTabId?: string;
  variant?: string;
  children?: React.ReactNode;
}> = ({ tabs, activeTabId, children }) => (
  <div data-testid="tab-navigation" data-active-tab={activeTabId}>
    {children}
  </div>
);

const TestLabel: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <label className={className}>{children || text}</label>
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// 컴포넌트 레지스트리 설정
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    PageHeader: { component: TestPageHeader, metadata: { name: 'PageHeader', type: 'composite' } },
    TabNavigation: { component: TestTabNavigation, metadata: { name: 'TabNavigation', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// Mock API 응답 생성 헬퍼
function createSettingsResponse(abilities: { can_update: boolean }) {
  return {
    success: true,
    data: {
      general: { site_name: 'Test Site' },
      abilities,
    },
  };
}

// 인라인 표현식 (프로덕션에서는 _computed.isReadOnly가 _local.form.abilities 경로 사용,
// 테스트에서는 initLocal 미지원으로 데이터소스 직접 참조)
const IS_READONLY = '{{!!settings?.data && !(settings?.data?.abilities?.can_update ?? false)}}';
const IS_NOT_READONLY = '{{!!settings?.data && (settings?.data?.abilities?.can_update ?? false)}}';

// 환경설정 간략화 레이아웃 (배너 + general 탭 Input + footer)
const settingsLayout = {
  version: '1.0.0',
  layout_name: 'test_admin_settings',
  data_sources: [
    {
      id: 'settings',
      type: 'api',
      endpoint: '/api/admin/settings',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      fallback: { data: [] },
    },
  ],
  components: [
    {
      id: 'settings_content',
      type: 'basic',
      name: 'Div',
      children: [
        {
          id: 'readonly_banner',
          type: 'basic',
          name: 'Div',
          if: IS_READONLY,
          props: {
            className: 'bg-yellow-50',
            'data-testid': 'readonly-banner',
          },
          children: [
            {
              type: 'basic',
              name: 'Div',
              props: { className: 'flex items-center gap-3' },
              children: [
                {
                  type: 'basic',
                  name: 'Icon',
                  props: { name: 'lock' },
                },
                {
                  type: 'basic',
                  name: 'P',
                  text: '이 설정은 읽기 전용입니다.',
                },
              ],
            },
          ],
        },
        {
          id: 'field_site_name',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'basic',
              name: 'Label',
              text: '사이트 이름',
            },
            {
              type: 'basic',
              name: 'Input',
              props: {
                type: 'text',
                name: 'general.site_name',
                disabled: IS_READONLY,
                'data-testid': 'input-site-name',
              },
            },
          ],
        },
        {
          id: 'footer_buttons',
          type: 'basic',
          name: 'Div',
          if: IS_NOT_READONLY,
          props: {
            'data-testid': 'footer-buttons',
          },
          children: [
            {
              type: 'basic',
              name: 'Button',
              props: {
                type: 'submit',
                'data-testid': 'btn-save',
              },
              text: '저장',
            },
            {
              type: 'basic',
              name: 'Button',
              props: {
                type: 'button',
                'data-testid': 'btn-cancel',
              },
              text: '취소',
            },
          ],
        },
      ],
    },
  ],
};

describe('admin-settings 읽기 전용 UI 제어', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  describe('can_update: true (수정 가능)', () => {
    it('readonly 배너가 표시되지 않아야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', {
        response: createSettingsResponse({ can_update: true }),
      });

      await testUtils.render();

      expect(screen.queryByTestId('readonly-banner')).toBeNull();

      testUtils.cleanup();
    });

    it('footer 저장/취소 버튼이 표시되어야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', {
        response: createSettingsResponse({ can_update: true }),
      });

      await testUtils.render();

      expect(screen.getByTestId('footer-buttons')).toBeTruthy();
      expect(screen.getByTestId('btn-save')).toBeTruthy();
      expect(screen.getByTestId('btn-cancel')).toBeTruthy();

      testUtils.cleanup();
    });

    it('Input이 활성 상태여야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', {
        response: createSettingsResponse({ can_update: true }),
      });

      await testUtils.render();

      const input = screen.getByTestId('input-site-name') as HTMLInputElement;
      expect(input.disabled).toBe(false);

      testUtils.cleanup();
    });
  });

  describe('can_update: false (읽기 전용)', () => {
    it('readonly 배너가 표시되어야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', {
        response: createSettingsResponse({ can_update: false }),
      });

      await testUtils.render();

      expect(screen.getByTestId('readonly-banner')).toBeTruthy();

      testUtils.cleanup();
    });

    it('footer 저장/취소 버튼이 숨겨져야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', {
        response: createSettingsResponse({ can_update: false }),
      });

      await testUtils.render();

      expect(screen.queryByTestId('footer-buttons')).toBeNull();
      expect(screen.queryByTestId('btn-save')).toBeNull();

      testUtils.cleanup();
    });

    it('Input이 disabled 상태여야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', {
        response: createSettingsResponse({ can_update: false }),
      });

      await testUtils.render();

      const input = screen.getByTestId('input-site-name') as HTMLInputElement;
      expect(input.disabled).toBe(true);

      testUtils.cleanup();
    });
  });

  describe('abilities 미포함 (하위 호환)', () => {
    it('abilities가 없으면 읽기 전용으로 처리되어야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', {
        response: {
          success: true,
          data: {
            general: { site_name: 'Test Site' },
            // abilities 없음
          },
        },
      });

      await testUtils.render();

      // abilities 없으면 can_update가 false이므로 읽기 전용
      expect(screen.getByTestId('readonly-banner')).toBeTruthy();
      expect(screen.queryByTestId('footer-buttons')).toBeNull();

      testUtils.cleanup();
    });
  });
});
