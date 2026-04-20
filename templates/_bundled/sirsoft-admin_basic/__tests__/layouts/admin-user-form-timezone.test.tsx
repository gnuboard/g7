/**
 * @file admin-user-form-timezone.test.tsx
 * @description 사용자 등록/수정 폼 - 타임존 Select 검색 지원 테스트
 *
 * 테스트 대상:
 * - timezone Select가 `_global.appConfig.supportedTimezones`를 options로 사용
 * - searchable=true 프로퍼티가 컴포넌트에 전달됨
 * - 백엔드가 내려준 {value, label} 객체 배열 형식 그대로 바인딩
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

type SelectOption = { value: string | number; label: string };

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestLabel: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <label className={className}>{children || text}</label>
);

const TestSelect: React.FC<{
  name?: string;
  value?: string;
  options?: SelectOption[];
  className?: string;
  searchable?: boolean;
  searchPlaceholder?: string;
  defaultValue?: string;
  'data-testid'?: string;
}> = ({ name, value, options, searchable, searchPlaceholder, defaultValue, 'data-testid': testId }) => (
  <div
    data-testid={testId || `select-${name}`}
    data-name={name}
    data-value={value ?? defaultValue ?? ''}
    data-options={JSON.stringify(options ?? [])}
    data-searchable={searchable ? 'true' : 'false'}
    data-search-placeholder={searchPlaceholder ?? ''}
  />
);

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const mockTimezoneOptions: SelectOption[] = [
  { value: 'Pacific/Pago_Pago', label: '(UTC-11:00) Pacific/Pago_Pago' },
  { value: 'America/New_York', label: '(UTC-05:00) America/New_York' },
  { value: 'UTC', label: '(UTC+00:00) UTC' },
  { value: 'Europe/Paris', label: '(UTC+01:00) Europe/Paris' },
  { value: 'Asia/Seoul', label: '(UTC+09:00) Asia/Seoul' },
  { value: 'Asia/Tokyo', label: '(UTC+09:00) Asia/Tokyo' },
  { value: 'Pacific/Auckland', label: '(UTC+13:00) Pacific/Auckland' },
];

const timezoneFieldLayout = {
  version: '1.0.0',
  layout_name: 'test_admin_user_form_timezone_field',
  components: [
    {
      id: 'field_timezone',
      type: 'basic',
      name: 'Div',
      props: { className: 'md:col-span-1' },
      children: [
        {
          type: 'basic',
          name: 'Label',
          props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1' },
          text: '시간대',
        },
        {
          id: 'timezone_select',
          type: 'composite',
          name: 'Select',
          props: {
            name: 'timezone',
            className: 'w-full',
            defaultValue: 'Asia/Seoul',
            options: '{{_global.appConfig?.supportedTimezones ?? []}}',
            searchable: true,
            searchPlaceholder: '검색',
          },
        },
      ],
    },
  ],
};

describe('admin_user_form timezone Select 렌더링', () => {
  let registry: ComponentRegistry;
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
  });

  it('timezone Select가 렌더링되고 라벨이 보인다', async () => {
    testUtils = createLayoutTest(timezoneFieldLayout, {
      translations: {},
      componentRegistry: registry,
      initialState: {
        _global: { appConfig: { supportedTimezones: mockTimezoneOptions } },
      },
    });

    await testUtils.render();

    expect(screen.getByText('시간대')).toBeInTheDocument();
    expect(screen.getByTestId('select-timezone')).toBeInTheDocument();
  });

  it('_global.appConfig.supportedTimezones가 options에 그대로 바인딩된다', async () => {
    testUtils = createLayoutTest(timezoneFieldLayout, {
      translations: {},
      componentRegistry: registry,
      initialState: {
        _global: { appConfig: { supportedTimezones: mockTimezoneOptions } },
      },
    });

    await testUtils.render();

    const select = screen.getByTestId('select-timezone');
    const options = JSON.parse(select.getAttribute('data-options') || '[]');

    expect(options).toEqual(mockTimezoneOptions);
    expect(options[4]).toEqual({ value: 'Asia/Seoul', label: '(UTC+09:00) Asia/Seoul' });
  });

  it('searchable=true가 Select에 전달된다', async () => {
    testUtils = createLayoutTest(timezoneFieldLayout, {
      translations: {},
      componentRegistry: registry,
      initialState: {
        _global: { appConfig: { supportedTimezones: mockTimezoneOptions } },
      },
    });

    await testUtils.render();

    const select = screen.getByTestId('select-timezone');
    expect(select.getAttribute('data-searchable')).toBe('true');
    expect(select.getAttribute('data-search-placeholder')).toBe('검색');
  });

  it('supportedTimezones가 없을 때 빈 배열로 fallback', async () => {
    testUtils = createLayoutTest(timezoneFieldLayout, {
      translations: {},
      componentRegistry: registry,
    });

    await testUtils.render();

    const select = screen.getByTestId('select-timezone');
    const options = JSON.parse(select.getAttribute('data-options') || '[]');

    expect(options).toEqual([]);
  });
});
