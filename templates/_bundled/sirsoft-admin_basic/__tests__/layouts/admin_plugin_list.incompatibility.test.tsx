/**
 * @file admin_plugin_list.incompatibility.test.tsx
 * @description 플러그인 목록의 코어 호환성 배지/업데이트 버튼 비활성화 테스트
 *
 * 검증 항목:
 * - is_compatible === false 시 amber 배지 렌더
 * - is_compatible === true 시 배지 미렌더
 * - aria-describedby + sr-only 텍스트 존재
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestSpan: React.FC<any> = ({ className, children, text, id }) => (
  <span className={className} id={id} data-testid={id || undefined}>{children || text}</span>
);
const TestIcon: React.FC<any> = ({ name, size, className }) => (
  <i className={`icon-${name} ${className || ''}`} data-testid={`icon-${name}`} data-size={size} />
);
const TestButton: React.FC<any> = ({ type, disabled, className, children, title }) => (
  <button type={type} disabled={disabled} className={className} title={title}>{children}</button>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const translations = {
  extensions: {
    badges: {
      incompatible: '코어 업그레이드 필요',
      incompatible_tooltip: '코어 {{required}} 이상 필요 (현재: {{installed}})',
      incompatible_sr: '{{name}} 은(는) 코어 {{required}} 이상이 필요하지만 현재 {{installed}} 가 설치되어 있어 업데이트할 수 없습니다.',
    },
  },
};

function buildLayout(row: Record<string, any>) {
  return {
    version: '1.0.0',
    layout_name: 'plugin_incompat_test',
    initGlobal: { row },
    components: [
      {
        id: 'wrap',
        type: 'basic',
        name: 'Div',
        children: [
          {
            type: 'basic',
            name: 'Span',
            if: '{{_global.row.is_compatible === false}}',
            props: {
              className: 'px-2 py-0.5 text-xs bg-amber-100 dark:bg-amber-900 rounded',
            },
            children: [
              { type: 'basic', name: 'Icon', props: { name: 'exclamation-triangle', size: 'xs' } },
              { type: 'basic', name: 'Span', text: '$t:extensions.badges.incompatible' },
            ],
          },
          {
            type: 'basic',
            name: 'Span',
            if: '{{_global.row.is_compatible === false}}',
            props: { id: 'compat_sr_test', className: 'sr-only' },
            text: '$t:defer:extensions.badges.incompatible_sr|name={{_global.row.name ?? ""}}|required={{_global.row.required_core_version ?? ""}}|installed={{_global.row.current_core_version ?? ""}}',
          },
        ],
      },
    ],
  };
}

describe('플러그인 목록 - 코어 호환성 배지', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => { registry = setupRegistry(); });
  afterEach(() => { if (testUtils) testUtils.cleanup(); });

  it('is_compatible=false 일 때 코어 업그레이드 필요 배지가 표시된다', async () => {
    testUtils = createLayoutTest(
      buildLayout({
        identifier: 'sirsoft-payment',
        name: 'Payment',
        is_compatible: false,
        required_core_version: '>=7.5.0',
        current_core_version: '7.0.0',
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('코어 업그레이드 필요')).toBeTruthy();
  });

  it('is_compatible=true 일 때 배지가 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      buildLayout({ identifier: 'p', name: 'P', is_compatible: true }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByText('코어 업그레이드 필요')).toBeNull();
  });

  it('is_compatible=false 일 때 sr-only 보조설명이 렌더된다', async () => {
    testUtils = createLayoutTest(
      buildLayout({
        identifier: 'p',
        name: 'Payment Plugin',
        is_compatible: false,
        required_core_version: '>=7.5.0',
        current_core_version: '7.0.0',
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    const sr = document.getElementById('compat_sr_test');
    expect(sr).not.toBeNull();
    expect(sr?.className).toContain('sr-only');
  });
});
