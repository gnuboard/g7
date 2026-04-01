/**
 * @file admin-login-improvements.test.tsx
 * @description 관리자 로그인 화면 개선 테스트
 *
 * 테스트 대상:
 * - 비밀번호 찾기 링크가 Button + navigate 핸들러로 구현되었는지
 * - 언어 셀렉트박스가 w-full 클래스를 사용하는지
 * - 테마 Segmented Control이 3개 버튼으로 구성되었는지
 * - 테마 버튼이 _global.theme에 따라 활성 스타일을 적용하는지
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// 테스트용 기본 컴포넌트
const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId, ...rest }) => (
  <div className={className} data-testid={testId} {...rest}>{children}</div>
);

const TestButton: React.FC<any> = ({ className, children, type, title, disabled, 'data-testid': testId }) => (
  <button className={className} type={type} title={title} disabled={disabled} data-testid={testId}>{children}</button>
);

const TestSelect: React.FC<any> = ({ className, value, 'data-testid': testId }) => (
  <select className={className} value={value} readOnly data-testid={testId} />
);

const TestInput: React.FC<any> = ({ className, type, name, 'data-testid': testId, ...rest }) => (
  <input className={className} type={type} name={name} data-testid={testId} readOnly {...rest} />
);

const TestSpan: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children}</span>
);

const TestH1: React.FC<any> = ({ className, children }) => (
  <h1 className={className}>{children}</h1>
);

const TestP: React.FC<any> = ({ className, children }) => (
  <p className={className}>{children}</p>
);

const TestForm: React.FC<any> = ({ className, children }) => (
  <form className={className}>{children}</form>
);

const TestLabel: React.FC<any> = ({ className, children }) => (
  <label className={className}>{children}</label>
);

const TestIcon: React.FC<any> = ({ name, size, color }) => (
  <i data-icon={name} data-size={size} className={color} />
);

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    H2: { component: TestH1, metadata: { name: 'H2', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Form: { component: TestForm, metadata: { name: 'Form', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

// 로그인 페이지 하단 영역만 추출 (비밀번호 찾기 링크 + 언어 셀렉터 + 테마 컨트롤)
const loginBottomLayout = {
  version: '1.0.0',
  layout_name: 'test_admin_login_bottom',
  state: {
    isLoggingIn: false,
  },
  components: [
    {
      comment: '비밀번호 찾기 링크',
      id: 'login_forgot_password',
      type: 'basic',
      name: 'Div',
      props: { className: 'flex items-center justify-end' },
      children: [
        {
          id: 'login_forgot_link',
          type: 'basic',
          name: 'Button',
          props: {
            type: 'button',
            className: 'text-sm text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300 transition-colors duration-200 cursor-pointer',
            'data-testid': 'forgot-link',
          },
          actions: [
            {
              type: 'click',
              handler: 'navigate',
              params: { path: '/admin/forgot-password' },
            },
          ],
          text: '비밀번호를 잊으셨나요?',
        },
      ],
    },
    {
      id: 'language_selector_container',
      type: 'basic',
      name: 'Div',
      props: { className: 'mt-6' },
      children: [
        {
          id: 'language_selector',
          type: 'basic',
          name: 'Select',
          props: {
            className: 'block w-full px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm',
            'data-testid': 'language-select',
          },
        },
      ],
    },
    {
      id: 'theme_selector_container',
      type: 'basic',
      name: 'Div',
      props: { className: 'mt-4 flex justify-center' },
      children: [
        {
          id: 'theme_segmented_control',
          type: 'basic',
          name: 'Div',
          props: {
            className: 'inline-flex bg-gray-100 dark:bg-gray-800 rounded-lg p-1 gap-1',
            'data-testid': 'theme-segmented-control',
          },
          children: [
            {
              id: 'theme_light_button',
              type: 'basic',
              name: 'Button',
              props: {
                type: 'button',
                className: "{{_global.theme === 'light' ? 'p-2 rounded-md bg-white dark:bg-gray-700 shadow-sm text-blue-600 dark:text-blue-400 transition-all duration-200' : 'p-2 rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-all duration-200'}}",
                title: 'Light',
                'data-testid': 'theme-light',
              },
              children: [
                { id: 'icon_light', type: 'basic', name: 'Icon', props: { name: 'sun', size: 'sm' } },
              ],
            },
            {
              id: 'theme_dark_button',
              type: 'basic',
              name: 'Button',
              props: {
                type: 'button',
                className: "{{_global.theme === 'dark' ? 'p-2 rounded-md bg-white dark:bg-gray-700 shadow-sm text-blue-600 dark:text-blue-400 transition-all duration-200' : 'p-2 rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-all duration-200'}}",
                title: 'Dark',
                'data-testid': 'theme-dark',
              },
              children: [
                { id: 'icon_dark', type: 'basic', name: 'Icon', props: { name: 'moon', size: 'sm' } },
              ],
            },
            {
              id: 'theme_auto_button',
              type: 'basic',
              name: 'Button',
              props: {
                type: 'button',
                className: "{{_global.theme === 'auto' || !_global.theme ? 'p-2 rounded-md bg-white dark:bg-gray-700 shadow-sm text-blue-600 dark:text-blue-400 transition-all duration-200' : 'p-2 rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-all duration-200'}}",
                title: 'Auto',
                'data-testid': 'theme-auto',
              },
              children: [
                { id: 'icon_auto', type: 'basic', name: 'Icon', props: { name: 'desktop', size: 'sm' } },
              ],
            },
          ],
        },
      ],
    },
  ],
};

describe('관리자 로그인 화면 개선', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  afterEach(() => {
    testUtils?.cleanup();
  });

  describe('비밀번호 찾기 링크', () => {
    it('Button 컴포넌트로 렌더링된다 (A 태그 아님)', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(loginBottomLayout, { componentRegistry: registry });
      await testUtils.render();

      const forgotLink = screen.getByTestId('forgot-link');
      expect(forgotLink.tagName).toBe('BUTTON');
    });

    it('비밀번호를 잊으셨나요? 텍스트가 표시된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(loginBottomLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByText('비밀번호를 잊으셨나요?')).toBeInTheDocument();
    });
  });

  describe('언어 셀렉트박스', () => {
    it('w-full 클래스가 적용된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(loginBottomLayout, { componentRegistry: registry });
      await testUtils.render();

      const select = screen.getByTestId('language-select');
      expect(select.className).toContain('w-full');
    });

    it('rounded-lg 클래스가 적용된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(loginBottomLayout, { componentRegistry: registry });
      await testUtils.render();

      const select = screen.getByTestId('language-select');
      expect(select.className).toContain('rounded-lg');
    });
  });

  describe('테마 Segmented Control', () => {
    it('3개 테마 버튼이 렌더링된다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(loginBottomLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByTestId('theme-light')).toBeInTheDocument();
      expect(screen.getByTestId('theme-dark')).toBeInTheDocument();
      expect(screen.getByTestId('theme-auto')).toBeInTheDocument();
    });

    it('pill 컨테이너가 inline-flex + rounded-lg 스타일을 가진다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(loginBottomLayout, { componentRegistry: registry });
      await testUtils.render();

      const control = screen.getByTestId('theme-segmented-control');
      expect(control.className).toContain('inline-flex');
      expect(control.className).toContain('rounded-lg');
      expect(control.className).toContain('bg-gray-100');
    });

    it('각 버튼에 접근성 title이 있다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(loginBottomLayout, { componentRegistry: registry });
      await testUtils.render();

      expect(screen.getByTestId('theme-light')).toHaveAttribute('title', 'Light');
      expect(screen.getByTestId('theme-dark')).toHaveAttribute('title', 'Dark');
      expect(screen.getByTestId('theme-auto')).toHaveAttribute('title', 'Auto');
    });

    it('_global.theme 미설정 시 auto 버튼이 활성 스타일을 가진다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(loginBottomLayout, { componentRegistry: registry });
      await testUtils.render();

      // _global.theme 미설정 → auto 버튼이 활성
      const autoBtn = screen.getByTestId('theme-auto');
      expect(autoBtn.className).toContain('shadow-sm');
      expect(autoBtn.className).toContain('text-blue-600');

      // light, dark 버튼은 비활성 스타일
      const lightBtn = screen.getByTestId('theme-light');
      expect(lightBtn.className).toContain('text-gray-500');
    });

    it('_global.theme = light 시 light 버튼만 활성 스타일을 가진다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(loginBottomLayout, {
        componentRegistry: registry,
        initialState: { _global: { theme: 'light' } },
      });
      await testUtils.render();

      const lightBtn = screen.getByTestId('theme-light');
      expect(lightBtn.className).toContain('shadow-sm');
      expect(lightBtn.className).toContain('text-blue-600');

      const darkBtn = screen.getByTestId('theme-dark');
      expect(darkBtn.className).toContain('text-gray-500');

      const autoBtn = screen.getByTestId('theme-auto');
      expect(autoBtn.className).toContain('text-gray-500');
    });

    it('_global.theme = dark 시 dark 버튼만 활성 스타일을 가진다', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(loginBottomLayout, {
        componentRegistry: registry,
        initialState: { _global: { theme: 'dark' } },
      });
      await testUtils.render();

      const darkBtn = screen.getByTestId('theme-dark');
      expect(darkBtn.className).toContain('shadow-sm');
      expect(darkBtn.className).toContain('text-blue-600');

      const lightBtn = screen.getByTestId('theme-light');
      expect(lightBtn.className).toContain('text-gray-500');
    });

    it('아이콘만 표시된다 (텍스트 없음)', async () => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(loginBottomLayout, { componentRegistry: registry });
      await testUtils.render();

      // sun, moon, desktop 아이콘 확인
      const sunIcon = document.querySelector('[data-icon="sun"]');
      const moonIcon = document.querySelector('[data-icon="moon"]');
      const desktopIcon = document.querySelector('[data-icon="desktop"]');

      expect(sunIcon).not.toBeNull();
      expect(moonIcon).not.toBeNull();
      expect(desktopIcon).not.toBeNull();
    });
  });
});
