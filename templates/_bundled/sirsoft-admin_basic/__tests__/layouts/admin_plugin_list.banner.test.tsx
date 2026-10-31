/**
 * @file admin_plugin_list.banner.test.tsx
 * @description 자동 비활성화 배너 렌더링 테스트
 *
 * 검증 항목:
 * - auto_deactivated_banner.data.items.plugins 가 있을 때 배너 표시
 * - 비어있을 때 배너 미표시
 * - 가이드 링크 href = /admin/system/update
 * - 토글·강제 활성 창의 활성화 성공 분기가 배너 데이터 소스를 다시 받는다(#145 F8 — 실제 굽기 산출 레이아웃)
 *
 * @scenario-manifest tests/scenarios/admin-extension-banner-refetch.yaml
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import pluginListLayout from '../../layouts/admin_plugin_list.json';
import pluginForceActivateModal from '../../layouts/partials/admin_plugin_list/_modal_force_activate.json';

const TestDiv: React.FC<any> = ({ className, children, role }) => <div className={className} role={role}>{children}</div>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<any> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const TestIcon: React.FC<any> = ({ name, className }) => <i className={`icon-${name} ${className || ''}`} data-testid={`icon-${name}`} />;
const TestA: React.FC<any> = ({ href, className, children }) => <a href={href} className={className} data-testid={`link-${href}`}>{children}</a>;
const TestButton: React.FC<any> = ({ type, className, children }) => <button type={type} className={className}>{children}</button>;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const translations = {
  extensions: {
    banner: {
      title: '코어 호환성 문제로 자동 비활성화된 확장이 있습니다',
      item_required: '필요 버전: {{required}}',
      guide_link: '코어 업그레이드 가이드',
      dismiss: '닫기',
    },
  },
};

function buildLayout(plugins: any[]) {
  return {
    version: '1.0.0',
    layout_name: 'banner_test',
    initGlobal: { auto_deactivated_banner: { data: { items: { plugins } } } },
    components: [
      {
        id: 'banner',
        type: 'basic',
        name: 'Div',
        if: '{{(_global.auto_deactivated_banner?.data?.items?.plugins?.length ?? 0) > 0}}',
        props: {
          className: 'mb-4 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg',
          role: 'alert',
        },
        children: [
          { type: 'basic', name: 'P', text: '$t:extensions.banner.title' },
          {
            type: 'basic',
            name: 'A',
            props: { href: '/admin/system/update', className: 'text-sm' },
            children: [{ type: 'basic', name: 'Span', text: '$t:extensions.banner.guide_link' }],
          },
        ],
      },
    ],
  };
}

describe('플러그인 목록 - 자동 비활성화 배너', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => { registry = setupRegistry(); });
  afterEach(() => { if (testUtils) testUtils.cleanup(); });

  /**
   * @scenario case=banner_shown
   * @effects banner_renders_from_auto_deactivated_items
   */
  it('plugins 배열에 항목이 있으면 배너가 렌더된다', async () => {
    testUtils = createLayoutTest(
      buildLayout([{ identifier: 'p1', incompatible_required_version: '>=7.5.0' }]),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText(/자동 비활성화/)).toBeTruthy();
  });

  /**
   * @scenario case=banner_hidden
   * @effects banner_renders_from_auto_deactivated_items
   */
  it('plugins 배열이 비어있으면 배너가 렌더되지 않는다', async () => {
    testUtils = createLayoutTest(
      buildLayout([]),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByText(/자동 비활성화/)).toBeNull();
  });

  /**
   * @scenario case=guide_link
   * @effects banner_renders_from_auto_deactivated_items
   */
  it('가이드 링크가 /admin/system/update 로 연결된다', async () => {
    testUtils = createLayoutTest(
      buildLayout([{ identifier: 'p1', incompatible_required_version: '>=7.5.0' }]),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByTestId('link-/admin/system/update')).toBeTruthy();
  });
});

type Json = Record<string, any>;

/** 트리 전체를 돌며 조건에 맞는 노드를 모두 모은다. */
function collect(node: unknown, match: (n: Json) => boolean): Json[] {
  const out: Json[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) {
      n.forEach(walk);
      return;
    }
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (match(obj)) out.push(obj);
      Object.values(obj).forEach(walk);
    }
  };
  walk(node);
  return out;
}

/** 확장 활성화 API 호출(토글·강제 활성·활성 확인 창)만 고른다 — 언어팩 일괄 활성은 제외. */
function activationCalls(layout: unknown): Json[] {
  return collect(layout, (n) => n.handler === 'apiCall'
    && typeof n.target === 'string'
    && /(modules|plugins|templates)\/activate/.test(n.target));
}

/** 성공 분기 안에 호환성 배너 데이터 소스 재조회가 있는지. */
function refetchesBanner(call: Json): boolean {
  return collect(call.onSuccess ?? [], (n) => n.handler === 'refetchDataSource'
    && n.params?.dataSourceId === 'auto_deactivated_banner').length > 0;
}

describe('플러그인 목록 - 활성화 성공 뒤 호환성 배너 재조회 (#145 F8)', () => {
  /**
   * @scenario case=plugin_toggle
   * @effects activation_success_refetches_compatibility_banner
   */
  it('플러그인 목록 토글 의 활성화 성공 분기가 호환성 배너를 다시 받는다', () => {
    const calls = activationCalls(pluginListLayout);
    expect(calls.length, '활성화 API 호출이 있어야 한다').toBeGreaterThan(0);
    calls.forEach((call) => expect(refetchesBanner(call), `${call.target} 성공 뒤 auto_deactivated_banner 재조회`).toBe(true));
  });

  /**
   * @scenario case=plugin_force_activate
   * @effects activation_success_refetches_compatibility_banner
   */
  it('플러그인 강제 활성 창 의 활성화 성공 분기가 호환성 배너를 다시 받는다', () => {
    const calls = activationCalls(pluginForceActivateModal);
    expect(calls.length, '활성화 API 호출이 있어야 한다').toBeGreaterThan(0);
    calls.forEach((call) => expect(refetchesBanner(call), `${call.target} 성공 뒤 auto_deactivated_banner 재조회`).toBe(true));
  });
});
