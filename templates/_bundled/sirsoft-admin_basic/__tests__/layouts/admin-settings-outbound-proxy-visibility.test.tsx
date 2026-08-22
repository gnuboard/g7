/**
 * @file admin-settings-outbound-proxy-visibility.test.tsx
 * @description 아웃바운드 프록시 입력칸의 디버그 모드 조건부 노출 테스트
 *
 * 프록시는 코어가 바깥으로 내보내는 모든 요청의 경로를 바꾼다. 그래서 입력칸은 디버그 모드가
 * 켜진 상태에서만 드러나야 한다. 다만 화면의 조건부 렌더링은 편의이지 게이트가 아니다 —
 * 실제 차단은 서버측 판정(App\Support\OutboundProxy)이 맡고, 이 테스트는 화면이 그 의도와
 * 어긋나지 않는지만 고정한다.
 *
 * 디버그 모드 OFF 케이스에서 SQL 쿼리 로그 토글이 함께 렌더되는 것을 먼저 확인한다.
 * 그 확인이 없으면 "아직 렌더되지 않아서 없는 것" 과 "조건에 걸려 없는 것" 이 구분되지 않는다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const advancedPartial = JSON.parse(
  readFileSync(resolve(__dirname, '../../layouts/partials/admin_settings/_tab_advanced.json'), 'utf-8')
);

// ---------------------------------------------------------------------------
// 테스트용 컴포넌트
// ---------------------------------------------------------------------------

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestInput: React.FC<any> = ({ name, type }) => <input name={name} type={type} data-testid={name} />;
const TestToggle: React.FC<any> = ({ name }) => (
  <input type="checkbox" role="switch" name={name} data-testid={`toggle-${name}`} />
);
const TestTagInput: React.FC<any> = ({ name }) => <div data-testid={`tags-${name}`} />;
const TestButton: React.FC<any> = ({ children, text, disabled }) => (
  <button type="button" disabled={disabled} data-testid="btn-test-proxy">{children || text}</button>
);
const TestA: React.FC<any> = ({ children, text }) => <a href="#">{children || text}</a>;
const TestSpan: React.FC<any> = ({ children, text }) => <span>{children || text}</span>;
const TestP: React.FC<any> = ({ children, text }) => <p>{children || text}</p>;
const TestH3: React.FC<any> = ({ children, text }) => <h3>{children || text}</h3>;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/**
 * 테스트용 컴포넌트 레지스트리를 구성합니다.
 *
 * @returns 구성된 레지스트리
 */
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } },
    TagInput: { component: TestTagInput, metadata: { name: 'TagInput', type: 'composite' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * id 로 노드를 깊이 우선 탐색합니다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 노드 id
 * @returns 찾은 노드 또는 null
 */
function findNodeById(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (node.id === id) return node;
  for (const child of node.children ?? []) {
    const found = findNodeById(child, id);
    if (found) return found;
  }
  return null;
}

/**
 * partial 루트들에서 id 노드를 찾습니다.
 *
 * @param id 찾을 노드 id
 * @returns 찾은 노드 또는 null
 */
function findInPartial(id: string): any {
  for (const root of advancedPartial.components ?? [advancedPartial]) {
    const found = findNodeById(root, id);
    if (found) return found;
  }
  return null;
}

/**
 * 주어진 폼 상태로 디버그 설정 카드를 렌더합니다.
 *
 * @param advanced 폼의 advanced 하위 상태
 * @returns 레이아웃 테스트 유틸
 */
function renderDebugCard(advanced: Record<string, unknown>) {
  const card = findInPartial('card_debug_settings');
  expect(card).not.toBeNull();

  return createLayoutTest(
    {
      version: '1.0.0',
      layout_name: 'test_outbound_proxy_visibility',
      components: [card],
    } as any,
    {
      initialState: {
        _local: {
          form: { advanced },
          errors: {},
        },
      },
    }
  );
}

describe('아웃바운드 프록시 입력칸 노출 조건', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  // @scenario debug_mode=on, proxy_value=empty, bypass_list=empty
  // @effects proxy_inputs_visible_when_debug_mode_on
  it('디버그 모드가 켜져 있으면 프록시 주소와 예외 목록이 렌더된다', async () => {
    const testUtils = renderDebugCard({ debug_mode: true, sql_query_log: false });
    await testUtils.render();

    expect(screen.getByTestId('advanced.outbound_proxy')).toBeInTheDocument();
    expect(screen.getByTestId('tags-advanced.outbound_proxy_bypass')).toBeInTheDocument();

    testUtils.cleanup();
  });

  // @scenario debug_mode=off, proxy_value=valid, bypass_list=empty
  // @effects proxy_inputs_hidden_when_debug_mode_off
  it('디버그 모드가 꺼져 있으면 프록시 입력칸이 렌더되지 않는다', async () => {
    const testUtils = renderDebugCard({ debug_mode: false, sql_query_log: false });
    await testUtils.render();

    // 카드 자체는 렌더됐음을 먼저 확정한다 — 그래야 아래 부재 단언이 의미를 갖는다.
    expect(screen.getByTestId('toggle-advanced.sql_query_log')).toBeInTheDocument();

    expect(screen.queryByTestId('advanced.outbound_proxy')).not.toBeInTheDocument();
    expect(screen.queryByTestId('tags-advanced.outbound_proxy_bypass')).not.toBeInTheDocument();

    testUtils.cleanup();
  });

  // @scenario debug_mode=on, proxy_value=valid, bypass_list=empty
  // @effects proxy_inputs_visible_when_debug_mode_on
  it('레이아웃이 참조하는 폼 필드명이 서버 저장 키와 일치한다', () => {
    const block = findInPartial('outbound_proxy_settings');
    expect(block).not.toBeNull();

    const names: string[] = [];
    const collect = (node: any) => {
      if (!node || typeof node !== 'object') return;
      if (node.props?.name) names.push(node.props.name);
      for (const child of node.children ?? []) collect(child);
    };
    collect(block);

    expect(names).toContain('advanced.outbound_proxy');
    expect(names).toContain('advanced.outbound_proxy_bypass');
  });
  // @scenario debug_mode=on, proxy_value=valid, bypass_list=empty
  // @effects proxy_connection_test_button_wired
  it('연결 테스트 버튼이 제출값을 실어 테스트 엔드포인트를 호출하도록 배선되어 있다', () => {
    const block = findInPartial('btn_test_outbound_proxy');
    expect(block).not.toBeNull();

    const apiCall = (block.actions ?? []).find((a: any) => a.handler === 'apiCall');
    expect(apiCall).toBeDefined();
    expect(apiCall.target).toBe('/api/admin/settings/test-outbound-proxy');
    expect(apiCall.params.method).toBe('POST');

    // 저장된 설정이 아니라 입력창의 현재 값을 보내야 저장 전 확인이 성립한다.
    expect(apiCall.params.body.outbound_proxy).toContain('_local.form?.advanced?.outbound_proxy');
    expect(apiCall.params.body.outbound_proxy_bypass).toContain('_local.form?.advanced?.outbound_proxy_bypass');

    // 응답 후 로딩 해제가 성공/실패 양쪽에 걸려 있어야 버튼이 잠긴 채 남지 않는다.
    for (const branch of ['onSuccess', 'onError']) {
      const setState = (apiCall[branch] ?? []).find((a: any) => a.handler === 'setState');
      expect(setState, branch).toBeDefined();
      expect(setState.params.outboundProxyTesting, branch).toBe(false);
    }
  });
});
