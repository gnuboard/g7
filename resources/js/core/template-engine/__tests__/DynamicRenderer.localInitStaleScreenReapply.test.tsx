// e2e:allow 은퇴 슬롯(engine-v1.88.0) — 전환 중 앞 트리 재렌더 경합이라 실물 렌더 시험 + Chrome MCP 실제 키 재실측으로 잠근다.
/**
 * 화면 전환 뒤 앞 화면 `_localInit` 슬롯이 새 화면 `_local` 에 적용되지 않는지
 *
 * 재현(관리자): 상품 수정 → 브라우저 「뒤로」로 상품 목록. 목록 `_local` 에 수정 화면의 `form`(상품 전체)·`hasChanges`
 * 가 남았다. 레이아웃 전환은 `_local` 을 비우고 `_localInit` 전역 추적(`resetLocalInitTracking`)도 비우는데, 새 화면이
 * 그려지기 전 앞 화면 트리가 전역 상태 구독으로 다시 그려지면 `_local` 리셋으로 조건부 구역이 뒤집혀 렌더러가 새로 붙는다.
 * 새 렌더러는 처리 기록이 없고 전역 추적도 비어 있어, 아직 컨텍스트에 남은 앞 화면 슬롯(`form`·`_forceLocalInit`)을
 * `apply` 했다(브라우저 실측: 오염 쓰기 순간 렌더 컨텍스트 경로가 앞 화면 `…/edit`).
 *
 * 잠금: 전환이 떠나는 화면의 슬롯을 은퇴로 표시하면(`resetLocalInitTracking(slot)`) 어떤 렌더러도 그 슬롯을 적용하지 않는다.
 * 새 화면의 새 슬롯은 종전대로 적용한다(같은 레이아웃 「수정 → 등록 → 뒤로」 의 재적용 — engine-v1.79.0 — 포함).
 *
 * @scenario-manifest tests/scenarios/engine-same-layout-route-store-reset.yaml
 * @since engine-v1.88.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, act } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { isLocalInitRetired, resetLocalInitTracking } from '../localInitSlot';

const TestDiv: React.FC<Record<string, unknown>> = (props) => {
  const { children, ...rest } = props as { children?: React.ReactNode };
  return <div {...(rest as Record<string, never>)}>{children}</div>;
};

/** TemplateApp.setGlobalState 와 같은 계약의 대역 — 객체는 얕은 펼침, 함수형은 prev 로 교체 */
const createStore = (initial: Record<string, any>) => {
  let state = initial;
  return {
    get current() {
      return state;
    },
    setGlobalState: (updates: Record<string, any> | ((prev: Record<string, any>) => Record<string, any>)) => {
      state = typeof updates === 'function' ? updates(state) : { ...state, ...updates };
    },
  };
};

describe('[case:state-global-retired-local-init-reapplied-by-stale-tree] _localInit — 화면 전환으로 은퇴한 앞 화면 슬롯', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  beforeEach(() => {
    resetLocalInitTracking();
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });

  const def = (id: string): ComponentDefinition =>
    ({ id, type: 'basic', name: 'Div', props: { 'data-testid': id }, children: [] }) as unknown as ComponentDefinition;

  const productSlot = () => ({ form: { id: 80, name: { ko: '스테인리스 텀블러 #80' }, selling_price: 29000 }, _forceLocalInit: 1762400000000 });

  const renderer = (
    store: ReturnType<typeof createStore>,
    id: string,
    localInit: Record<string, any> | undefined,
    path: string,
    layoutKey: string,
  ) => (
    <DynamicRenderer
      key={id}
      componentDef={def(id)}
      dataContext={{
        _local: store.current._local,
        _global: { ...store.current },
        _globalSetState: store.setGlobalState,
        _localInit: localInit,
        route: { path },
      }}
      translationContext={translationContext}
      registry={registry}
      bindingEngine={bindingEngine}
      translationEngine={translationEngine}
      actionDispatcher={actionDispatcher}
      layoutKey={layoutKey}
    />
  );

  /**
   * @scenario case=stale_screen_local_init_reapply
   * @effects stale_screen_does_not_reapply_local_init
   */
  it('전환 뒤 앞 화면 트리에 새로 붙은 렌더러가 은퇴한 슬롯을 받아도 앞 폼·hasChanges 를 새 _local 에 쓰지 않는다', () => {
    const store = createStore({ _local: {} });
    const slot = productSlot();
    const view = render(<>{renderer(store, 'edit_root', slot, '/admin/ecommerce/products/H1/edit', 'admin_ecommerce_product_form')}</>);
    expect(store.current._local.form?.id).toBe(80);

    // 레이아웃 전환 — TemplateApp.handleRouteChange: _local 을 비우고, 떠나는 화면 슬롯을 은퇴로 표시하며 추적을 비운다
    act(() => {
      store.setGlobalState({ _local: { filter: { page: 1 } } });
      resetLocalInitTracking(slot);
    });
    expect(isLocalInitRetired(slot)).toBe(true);

    // 새 화면이 그려지기 전 앞 화면 트리가 다시 그려지며 조건부 구역의 렌더러가 새로 붙는다(같은 앞 화면 컨텍스트·슬롯)
    view.rerender(
      <>
        {renderer(store, 'edit_root', slot, '/admin/ecommerce/products/H1/edit', 'admin_ecommerce_product_form')}
        {renderer(store, 'edit_section_mounted_late', slot, '/admin/ecommerce/products/H1/edit', 'admin_ecommerce_product_form')}
      </>,
    );

    expect(store.current._local).toEqual({ filter: { page: 1 } });
    expect('form' in store.current._local).toBe(false);
    expect('hasChanges' in store.current._local).toBe(false);
  });

  /**
   * @scenario case=stale_screen_local_init_reapply
   * @effects store_reset_refills_same_local_init
   */
  it('새 화면의 새 슬롯은 종전대로 적용한다 — 같은 레이아웃 수정 → 등록(슬롯 없음) → 뒤로(같은 내용 새 슬롯)', () => {
    const store = createStore({ _local: {} });
    const layoutKey = 'admin_board_form';
    const editSlot = productSlot();
    const view = render(renderer(store, 'root', editSlot, '/admin/boards/a/edit', layoutKey));
    expect(store.current._local.form?.id).toBe(80);

    act(() => {
      store.setGlobalState({ _local: { form: null } });
      resetLocalInitTracking(editSlot);
    });
    view.rerender(renderer(store, 'root', undefined, '/admin/boards/create', layoutKey));
    expect(store.current._local.form).toBeNull();

    act(() => {
      resetLocalInitTracking(undefined);
    });
    view.rerender(renderer(store, 'root', productSlot(), '/admin/boards/a/edit', layoutKey));

    expect(store.current._local.form?.id).toBe(80);
  });

  /**
   * @scenario case=stale_screen_local_init_reapply
   * @effects stale_screen_does_not_reapply_local_init
   */
  it('같은 화면에서 같은 내용의 슬롯이 다시 와도 사용자 편집을 되돌리지 않는다 — 종전 계약', () => {
    const store = createStore({ _local: {} });
    const view = render(renderer(store, 'root', productSlot(), '/admin/ecommerce/products/H1/edit', 'admin_ecommerce_product_form'));

    act(() => {
      store.setGlobalState({ _local: { ...store.current._local, form: { ...store.current._local.form, selling_price: 31000 }, hasChanges: true } });
    });
    view.rerender(renderer(store, 'root', productSlot(), '/admin/ecommerce/products/H1/edit', 'admin_ecommerce_product_form'));

    expect(store.current._local.form.selling_price).toBe(31000);
    expect(store.current._local.hasChanges).toBe(true);
  });

  /**
   * @scenario case=stale_screen_local_init_reapply
   * @effects stale_screen_does_not_reapply_local_init
   */
  it('은퇴 표시는 넘긴 슬롯 객체에만 걸린다 — 슬롯 없음·원시값은 무시', () => {
    const slot = productSlot();
    resetLocalInitTracking(slot);
    expect(isLocalInitRetired(slot)).toBe(true);
    expect(isLocalInitRetired(productSlot())).toBe(false);
    expect(isLocalInitRetired(undefined)).toBe(false);
    resetLocalInitTracking('x' as unknown as object);
    expect(isLocalInitRetired('x')).toBe(false);
  });
});
