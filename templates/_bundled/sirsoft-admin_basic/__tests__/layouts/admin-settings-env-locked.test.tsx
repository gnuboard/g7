/**
 * @file admin-settings-env-locked.test.tsx
 * @description 환경설정 `.env` 잠금 UI 렌더링 테스트
 *
 * 테스트 대상:
 * - `_meta.env_locked` 에 포함된 필드 → 잠금 배지 표시 + Input disabled
 * - 포함되지 않은 필드 → 배지 없음 + Input 활성
 * - `_meta.env_priority_enabled` → 안내 배너 표시/미표시
 * - can_update:false 와의 OR 중첩 (권한 잠금 × env 잠금)
 *
 * 권한 중첩 축은 실브라우저(Chrome MCP 매트릭스 T9)에서 보조 계정을 만들 수 없어
 * 이 레이아웃 테스트가 담당한다.
 *
 * @effects badge_and_disabled_rendered, switch_off_behaves_identically
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestInput: React.FC<{
  className?: string;
  disabled?: boolean;
  type?: string;
  name?: string;
  'data-testid'?: string;
}> = ({ className, disabled, type, name, 'data-testid': testId }) => (
  <input className={className} disabled={disabled} type={type} name={name} data-testid={testId || name} />
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => <span className={className}>{children || text}</span>;

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => <p className={className}>{children || text}</p>;

const TestIcon: React.FC<{ name?: string; className?: string }> = ({ name, className }) => (
  <i className={className} data-icon={name} data-testid={`icon-${name}`} />
);

const TestLabel: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => <label className={className}>{children || text}</label>;

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

const TestBadge: React.FC<{
  text?: string;
  variant?: string;
  size?: string;
  className?: string;
  'data-testid'?: string;
}> = ({ text, variant, size, className, 'data-testid': testId }) => (
  <span className={className} data-variant={variant} data-size={size} data-testid={testId}>
    {text}
  </span>
);

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Badge: { component: TestBadge, metadata: { name: 'Badge', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * 설정 API 응답을 만듭니다.
 *
 * 프로덕션 레이아웃은 `initLocal.form` 을 거쳐 `_local.form._meta` 를 읽지만,
 * 레이아웃 테스트 하네스는 initLocal 을 지원하지 않으므로 데이터소스를 직접 참조합니다.
 */
function createSettingsResponse(options: {
  canUpdate: boolean;
  envPriorityEnabled: boolean;
  envLocked: Record<string, boolean>;
}) {
  return {
    success: true,
    data: {
      general: { site_name: 'Test Site', site_description: '설명' },
      abilities: { can_update: options.canUpdate },
      _meta: {
        limits: {},
        env_priority_enabled: options.envPriorityEnabled,
        env_locked: options.envLocked,
      },
    },
  };
}

const ENV_LOCKED = "settings?.data?._meta?.env_locked";

function lockExpr(key: string): string {
  return `{{${ENV_LOCKED}?.['${key}'] === true}}`;
}

function disabledExpr(key: string): string {
  return `{{(!!settings?.data && !(settings?.data?.abilities?.can_update ?? false)) || ${ENV_LOCKED}?.['${key}'] === true}}`;
}

const settingsLayout = {
  version: '1.0.0',
  layout_name: 'test_admin_settings_env_locked',
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
          id: 'env_priority_banner',
          type: 'basic',
          name: 'Div',
          if: '{{settings?.data?._meta?.env_priority_enabled === true}}',
          props: { className: 'alert-info', 'data-testid': 'env-priority-banner' },
          children: [
            { type: 'basic', name: 'Icon', props: { name: 'lock' } },
            { type: 'basic', name: 'P', text: '.env 우선 모드' },
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
              children: [
                { type: 'basic', name: 'Span', text: '사이트 이름' },
                {
                  type: 'composite',
                  name: 'Badge',
                  if: lockExpr('general.site_name'),
                  props: {
                    text: '.env 고정',
                    variant: 'secondary',
                    size: 'sm',
                    'data-testid': 'badge-site-name',
                  },
                },
              ],
            },
            {
              type: 'basic',
              name: 'Input',
              props: {
                type: 'text',
                name: 'general.site_name',
                disabled: disabledExpr('general.site_name'),
                'data-testid': 'input-site-name',
              },
            },
          ],
        },
        {
          id: 'field_site_description',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'basic',
              name: 'Label',
              children: [
                { type: 'basic', name: 'Span', text: '사이트 설명' },
                {
                  type: 'composite',
                  name: 'Badge',
                  if: lockExpr('general.site_description'),
                  props: {
                    text: '.env 고정',
                    variant: 'secondary',
                    size: 'sm',
                    'data-testid': 'badge-site-description',
                  },
                },
              ],
            },
            {
              type: 'basic',
              name: 'Input',
              props: {
                type: 'text',
                name: 'general.site_description',
                disabled: disabledExpr('general.site_description'),
                'data-testid': 'input-site-description',
              },
            },
          ],
        },
      ],
    },
  ],
};

describe('admin-settings .env 잠금 UI', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  describe('스위치 ON + 일부 키 잠금', () => {
    const response = createSettingsResponse({
      canUpdate: true,
      envPriorityEnabled: true,
      envLocked: { 'general.site_name': true },
    });

    // @scenario switch=on, key_state=unlocked, surface=ui_lock
    // @effects badge_and_disabled_rendered
    it('안내 배너가 표시되어야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', { response });

      await testUtils.render();

      expect(screen.getByTestId('env-priority-banner')).toBeTruthy();

      testUtils.cleanup();
    });

    // @scenario switch=on, key_state=locked_plain, surface=ui_lock
    // @effects badge_and_disabled_rendered
    it('잠긴 필드에만 배지가 렌더되어야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', { response });

      await testUtils.render();

      expect(screen.getByTestId('badge-site-name')).toBeTruthy();
      expect(screen.queryByTestId('badge-site-description')).toBeNull();

      testUtils.cleanup();
    });

    it('잠긴 필드만 disabled 여야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', { response });

      await testUtils.render();

      expect((screen.getByTestId('input-site-name') as HTMLInputElement).disabled).toBe(true);
      expect((screen.getByTestId('input-site-description') as HTMLInputElement).disabled).toBe(false);

      testUtils.cleanup();
    });
  });

  describe('스위치 OFF (현행)', () => {
    const response = createSettingsResponse({
      canUpdate: true,
      envPriorityEnabled: false,
      envLocked: {},
    });

    // @scenario switch=off, key_state=unlocked, surface=ui_lock
    // @effects switch_off_behaves_identically
    it('배너와 배지가 모두 없어야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', { response });

      await testUtils.render();

      expect(screen.queryByTestId('env-priority-banner')).toBeNull();
      expect(screen.queryByTestId('badge-site-name')).toBeNull();
      expect(screen.queryByTestId('badge-site-description')).toBeNull();

      testUtils.cleanup();
    });

    it('모든 필드가 활성이어야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', { response });

      await testUtils.render();

      expect((screen.getByTestId('input-site-name') as HTMLInputElement).disabled).toBe(false);
      expect((screen.getByTestId('input-site-description') as HTMLInputElement).disabled).toBe(false);

      testUtils.cleanup();
    });
  });

  describe('권한 잠금 × env 잠금 (OR 중첩)', () => {
    it('can_update:false 면 env 잠금과 무관하게 모두 disabled 여야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', {
        response: createSettingsResponse({
          canUpdate: false,
          envPriorityEnabled: true,
          envLocked: { 'general.site_name': true },
        }),
      });

      await testUtils.render();

      expect((screen.getByTestId('input-site-name') as HTMLInputElement).disabled).toBe(true);
      expect((screen.getByTestId('input-site-description') as HTMLInputElement).disabled).toBe(true);

      testUtils.cleanup();
    });

    it('can_update:false 여도 잠금 배지는 env 잠금 필드에만 붙어야 한다', async () => {
      const testUtils = createLayoutTest(settingsLayout);
      testUtils.mockApi('settings', {
        response: createSettingsResponse({
          canUpdate: false,
          envPriorityEnabled: true,
          envLocked: { 'general.site_name': true },
        }),
      });

      await testUtils.render();

      expect(screen.getByTestId('badge-site-name')).toBeTruthy();
      expect(screen.queryByTestId('badge-site-description')).toBeNull();

      testUtils.cleanup();
    });
  });
});
