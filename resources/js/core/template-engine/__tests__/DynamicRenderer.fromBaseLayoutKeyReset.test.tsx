/**
 * DynamicRenderer.fromBaseLayoutKeyReset.test.tsx
 *
 * 트러블슈팅 state-closure 사례 4 (engine-v1.72.1) — 실물 렌더 회귀 잠금.
 *
 * SPA 네비게이션에서 `_fromBase` 보존 컴포넌트(관리자 base 의 `admin_layout_root`)는 stable key 로
 * unmount 되지 않는다. 그 인스턴스의 저장소 A(`localDynamicState`)에는 이전 페이지의 `_local` 전체가
 * 남아 있고, engine-v1.24.8 의 초기화는 `useEffect` 라 **새 페이지의 첫 렌더와 자식 useEffect 뒤**에
 * 실행됐다. 그 사이에:
 *   1. 첫 렌더가 `deepMergeState(dataContext._local, dynamicState)` 로 stale A 를 새 `_local` 위에 덮은
 *      컨텍스트를 자식에게 주고,
 *   2. 자식의 마운트 동기화 콜백(ColumnSelector/FilterVisibilitySelector 의 init)이 그 오염값을
 *      `setState(target:"_local")` 로 되쓰며,
 *   3. engine-v1.50.0 부터 COMPONENT 경로 setState 가 `_global._local`(저장소 B)을 동기화하므로 되쓴 값이
 *      B 에 영구 고정된다.
 *
 * 이 파일은 실물 React 렌더로 그 세 단계를 그대로 밟는다. 자식은 ColumnSelector 처럼 마운트 시 자기가
 * 받은 `visibleColumns` 를 콜백으로 되돌려 주고, 레이아웃은 그 콜백을 `setState` 액션에 잇는다.
 * fail-first: 초기화가 useEffect 로 되돌아가면 페이지 B 의 첫 렌더가 페이지 A 의 컬럼을 받고
 * 저장소 B 도 A 의 값으로 굳어 여기서 red 가 된다.
 *
 * @see 트러블슈팅 state-closure 사례 4 (engine-v1.72.1) · docs/frontend/state-management.md 「엔진 수정 시 금지 사항」
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, act } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { resetLocalInitTracking } from '../localInitSlot';

vi.mock('../ResponsiveManager', () => ({
  responsiveManager: {
    getWidth: vi.fn(() => 1440),
    subscribe: vi.fn(() => () => {}),
    getMatchingKey: vi.fn(() => null),
    parseRange: vi.fn(() => null),
  },
}));

type RenderLogEntry = { page: string; visibleColumns: unknown };

const TestDiv: React.FC<Record<string, unknown>> = (props) => {
  const { children, ...rest } = props as { children?: React.ReactNode };
  const domProps: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(rest)) {
    if (typeof value !== 'object' && typeof value !== 'function') domProps[key] = value;
  }
  return <div {...(domProps as Record<string, never>)}>{children}</div>;
};

/**
 * ColumnSelector 의 마운트 동기화만 남긴 최소 모형.
 *
 * 실제 ColumnSelector(templates/_bundled/sirsoft-admin_basic/src/components/composite/ColumnSelector.tsx)는
 * 마운트 시 `getInitialColumns()`(저장값 > 외부 prop > 기본값)로 정한 목록을
 * `onColumnVisibilityChange(cols, { source: 'init' })` 로 부모에 되돌려 준다. 저장값이 없는 브라우저에서는
 * 외부 prop(`{{_local.visibleColumns}}`)이 그대로 되돌아간다 — 그 외부 prop 이 첫 렌더에서 오염돼 있으면
 * 오염값이 되돌아간다. 여기서는 그 경로만 재현한다.
 */
const makeColumnEcho = (renderLog: RenderLogEntry[]): React.FC<{
  page?: string;
  visibleColumns?: string[];
  onColumnVisibilityChange?: (cols: string[]) => void;
}> => {
  const ColumnEcho: React.FC<{
    page?: string;
    visibleColumns?: string[];
    onColumnVisibilityChange?: (cols: string[]) => void;
  }> = ({ page = '?', visibleColumns, onColumnVisibilityChange }) => {
    renderLog.push({ page, visibleColumns });
    const [initialColumns] = React.useState(visibleColumns);
    React.useEffect(() => {
      onColumnVisibilityChange?.(initialColumns ?? []);
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    return <div data-testid={`column-echo-${page}`}>{(visibleColumns ?? []).join(',')}</div>;
  };
  return ColumnEcho;
};

const echoDef = (page: string): ComponentDefinition =>
  ({
    id: 'column_selector',
    type: 'composite',
    name: 'ColumnEcho',
    props: { page, visibleColumns: '{{_local.visibleColumns}}' },
    actions: [
      {
        event: 'onColumnVisibilityChange',
        type: 'change',
        handler: 'setState',
        params: { target: '_local', visibleColumns: '{{$args[0]}}' },
      },
    ],
  }) as unknown as ComponentDefinition;

/**
 * 관리자 base 의 골격: `_fromBase` 루트(stable key, 보존) → 슬롯 래퍼(_fromBase 없음, layoutKey key 로
 * remount) → 페이지 고유 컴포넌트.
 */
const rootDef = (page: string): ComponentDefinition =>
  ({
    id: 'admin_layout_root',
    type: 'basic',
    name: 'Div',
    _fromBase: true,
    props: { 'data-testid': 'admin-layout-root' },
    children: [
      {
        id: 'content_slot',
        type: 'basic',
        name: 'Div',
        props: { 'data-testid': `content-slot-${page}` },
        children: [echoDef(page)],
      },
    ],
  }) as unknown as ComponentDefinition;

describe('[case:state-closure-spa-from-base-stale-first-render] SPA 이동 시 보존된 base 루트의 stale 저장소 A 가 첫 렌더에 병합되어 자식 마운트 콜백이 이전 페이지 값을 되쓴다 (engine-v1.72.1)', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;
  let globalState: { _local: Record<string, any>; [key: string]: any };
  let renderLog: RenderLogEntry[];

  const flush = async (): Promise<void> => {
    await act(async () => {
      await Promise.resolve();
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  };

  beforeEach(() => {
    resetLocalInitTracking();
    renderLog = [];
    globalState = { _local: {} };

    (window as any).__templateApp = {
      getGlobalState: () => globalState,
      setGlobalState: (updates: any) => {
        globalState = { ...globalState, ...updates };
      },
    };
    (window as any).__g7PendingLocalState = null;
    (window as any).__g7ForcedLocalFields = undefined;
    (window as any).__g7SetLocalOverrideKeys = undefined;
    (window as any).__g7SequenceLocalSync = undefined;
    (window as any).__g7LayoutContextStack = [];

    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
      ColumnEcho: { component: makeColumnEcho(renderLog), metadata: { name: 'ColumnEcho', type: 'composite' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    actionDispatcher.setGlobalStateUpdater((updates: any) => {
      (window as any).__templateApp.setGlobalState(updates);
    });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    delete (window as any).__templateApp;
    delete (window as any).__g7PendingLocalState;
    delete (window as any).__g7ForcedLocalFields;
    delete (window as any).__g7SetLocalOverrideKeys;
    delete (window as any).__g7SequenceLocalSync;
    (window as any).__g7LayoutContextStack = [];
  });

  const renderPage = (page: string, layoutKey: string, local: Record<string, any>) => (
    <DynamicRenderer
      componentDef={rootDef(page)}
      dataContext={{ _local: local, _global: globalState }}
      translationContext={translationContext}
      registry={registry}
      bindingEngine={bindingEngine}
      translationEngine={translationEngine}
      actionDispatcher={actionDispatcher}
      isRootRenderer={false}
      layoutKey={layoutKey}
    />
  );

  /**
   * 증상: 주문 목록(visibleColumns=[주문 컬럼]) → 쿠폰 목록 SPA 이동 시 쿠폰 목록의 첫 렌더가 주문 컬럼을
   *       받고, ColumnSelector 의 init 콜백이 그 값을 되써 `_global._local.visibleColumns` 가 주문 컬럼으로 굳는다.
   * 해결: `_fromBase` 컴포넌트의 저장소 A 초기화를 useEffect 가 아니라 렌더 단계에서 수행해
   *       새 페이지의 자식이 오염된 컨텍스트를 한 번도 받지 않게 한다.
   *
   * @scenario transition=layout_switch, origin=order_list
   * @effects first_render_context_uses_destination_local, mount_callback_echoes_destination_value, previous_page_keys_absent_in_global_local
   */
  it('페이지 B 의 첫 렌더는 페이지 A 의 visibleColumns 를 받지 않고, 저장소 B 도 페이지 A 값으로 굳지 않는다', async () => {
    // 1) 페이지 A 마운트 — TemplateApp 이 initLocal 을 B 에 적용한 상태를 그대로 흉내 낸다
    globalState._local = { visibleColumns: ['ordered_at', 'order_number'], filterA: { keyword: '' } };
    const view = render(renderPage('a', 'admin_order_list', globalState._local));
    await flush();

    // 마운트 콜백 → COMPONENT 경로 setState → 저장소 A 에 전체 스냅샷 + 저장소 B 동기화 (engine-v1.50.0)
    expect(globalState._local.visibleColumns).toEqual(['ordered_at', 'order_number']);
    expect(renderLog.filter((entry) => entry.page === 'a').length).toBeGreaterThan(0);

    // 2) SPA 이동 — TemplateApp.handleRouteChange 가 하는 일: _local 리셋 → 새 레이아웃 state 적용 → 렌더
    //    루트(admin_layout_root)는 stable key 로 보존되고, 슬롯 래퍼·페이지 컴포넌트는 layoutKey key 로 remount 된다
    globalState._local = { visibleColumns: ['target_type_label', 'name'] };
    const pageBLocal = globalState._local;
    view.rerender(renderPage('b', 'admin_promotion_coupon_list', pageBLocal));
    await flush();

    const pageBRenders = renderLog.filter((entry) => entry.page === 'b');
    expect(pageBRenders.length).toBeGreaterThan(0);

    // 첫 렌더부터 마지막 렌더까지 페이지 B 의 자식은 페이지 B 의 컬럼만 받는다
    expect(pageBRenders[0].visibleColumns).toEqual(['target_type_label', 'name']);
    for (const entry of pageBRenders) {
      expect(entry.visibleColumns).toEqual(['target_type_label', 'name']);
    }

    // 마운트 콜백이 되쓴 값도 페이지 B 의 컬럼이고, 페이지 A 전용 키는 저장소 B 에 새지 않는다
    expect(globalState._local.visibleColumns).toEqual(['target_type_label', 'name']);
    expect(globalState._local).not.toHaveProperty('filterA');
    expect(view.getByTestId('column-echo-b').textContent).toBe('target_type_label,name');
  });

  /**
   * 같은 레이아웃 안의 리렌더(검색·페이지 번호 등 query 만 바뀌는 이동)는 layoutKey 가 같으므로
   * 초기화가 발동하지 않는다 — engine-v1.24.8 과 같은 경계다.
   *
   * @scenario transition=same_layout_rerender, origin=order_list
   * @effects store_a_kept_on_same_layout_rerender
   */
  it('layoutKey 가 같으면 보존 컴포넌트의 저장소 A 를 초기화하지 않는다', async () => {
    globalState._local = { visibleColumns: ['ordered_at'] };
    const view = render(renderPage('a', 'admin_order_list', globalState._local));
    await flush();

    const rendersBefore = renderLog.length;
    view.rerender(renderPage('a', 'admin_order_list', { ...globalState._local, extra: 1 }));
    await flush();

    // 페이지 A 의 자식이 다시 그려져도 값은 그대로다 (초기화로 인한 빈 컬럼 렌더가 끼어들지 않는다)
    const after = renderLog.slice(rendersBefore).filter((entry) => entry.page === 'a');
    for (const entry of after) {
      expect(entry.visibleColumns).toEqual(['ordered_at']);
    }
    expect(globalState._local.visibleColumns).toEqual(['ordered_at']);
  });
});
