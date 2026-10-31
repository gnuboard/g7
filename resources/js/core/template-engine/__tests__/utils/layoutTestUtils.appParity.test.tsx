/**
 * @file layoutTestUtils.appParity.test.tsx
 * @description 레이아웃 시험 도구(createLayoutTest)의 렌더 컨텍스트가 실제 앱(TemplateApp·template-engine)과 같은지 (7.1.3 WP11)
 *
 * 도구가 만드는 렌더 컨텍스트가 실제 앱과 달라 시험은 통과하는데 실제 화면은 깨질 수 있었다(차이 10건 T1~T10):
 * `_computed` 없음·`$locale` 류 전역 없음·route 모양 다름·`_isolatedInit` 없음·`_dataSourceErrors` 미기록·응답 모양 두 갈래·
 * 데이터소스 initLocal 미적용·`_defines`/`_globalSetState`/`layoutWarnings` 없음·init_actions 는 setState 흉내만·
 * 토스트/모달 모의가 엔진 내장 처리보다 늦게 등록된 죽은 코드. 도구가 실제 앱과 같은 규칙(TemplateApp 의 init 처리·
 * 공용 computed 평가기·실제 ActionDispatcher·ParentContextProvider)으로 컨텍스트를 만들도록 고쳤다.
 *
 * @scenario-manifest tests/scenarios/layout-test-tool-app-parity.yaml
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { createLayoutTest, createMockComponentRegistryWithBasics } from './layoutTestUtils';

let cleanupFns: Array<() => void> = [];

afterEach(() => {
  cleanupFns.forEach((fn) => fn());
  cleanupFns = [];
});

/** 시험 하나에 쓸 도구 — 끝나면 정리한다 */
function makeTest(layout: any, options: Record<string, any> = {}) {
  const test = createLayoutTest(layout, { componentRegistry: createMockComponentRegistryWithBasics() as any, ...options });
  cleanupFns.push(test.cleanup);

  return test;
}

/** text 바인딩 하나를 그리는 레이아웃 */
const textLayout = (text: string, extra: Record<string, any> = {}) => ({
  version: '1.0.0',
  layout_name: 'parity_probe',
  components: [{ id: 'probe', type: 'basic', name: 'Span', props: { 'data-testid': 'probe', text } }],
  ...extra,
});

describe('createLayoutTest — 실제 앱과 같은 렌더 컨텍스트', () => {
  /**
   * @scenario item=t1_computed
   * @effects tool_context_matches_app
   */
  it('T1: 레이아웃 computed 를 실제 앱과 같은 평가기로 계산해 _computed 로 싣고 getComputed 도 같은 값을 준다', async () => {
    const test = makeTest(textLayout('{{_computed.count}}', {
      data_sources: [{ id: 'items', type: 'api', endpoint: '/api/admin/items' }],
      computed: { count: '{{(items?.data ?? []).length}}' },
    }));
    test.mockApi('items', { response: { data: [{ id: 1 }, { id: 2 }] } });

    await test.render();

    expect(screen.getByTestId('probe').textContent).toBe('2');
    expect(test.getComputed('count')).toBe(2);
  });

  /**
   * @scenario item=t2_globals
   * @effects tool_context_matches_app
   */
  it('T2: $locale·$locales·$templateLocales·$templateId 전역을 싣는다', async () => {
    const test = makeTest(textLayout('{{$locale}}|{{$templateId}}|{{$locales.length}}|{{$templateLocales.length}}'), {
      locale: 'en',
      templateId: 'parity-tpl',
      locales: ['ko', 'en', 'ja'],
    });

    await test.render();

    expect(screen.getByTestId('probe').textContent).toBe('en|parity-tpl|3|2');
  });

  /**
   * @scenario item=t3_route
   * @effects tool_context_matches_app
   */
  it('T3: route 는 {...경로 파라미터, path} 이고 경로 파라미터를 루트에도 펼친다', async () => {
    const test = makeTest(textLayout('{{route.id}}|{{route.path}}|{{id}}'), {
      routeParams: { id: '7' },
      routePath: '/admin/items/7',
    });

    await test.render();

    expect(screen.getByTestId('probe').textContent).toBe('7|/admin/items/7|7');
  });

  /**
   * @scenario item=t4_isolated
   * @effects tool_context_matches_app
   */
  it('T4: 레이아웃 initIsolated 를 _isolatedInit 으로 격리 상태 공급자에 넘긴다', async () => {
    const test = makeTest(textLayout('{{_isolatedInit.panel.count}}', { initIsolated: { panel: { count: 3 } } }));

    await test.render();

    expect(screen.getByTestId('probe').textContent).toBe('3');
  });

  /**
   * @scenario item=t5_datasource_errors
   * @effects tool_context_matches_app
   */
  it('T5: 받기 실패를 _dataSourceErrors 에 기록하고 데이터소스 errorHandling 을 실행한다', async () => {
    const test = makeTest(textLayout('{{_dataSourceErrors.items.status}}|{{_global.failedAt}}', {
      data_sources: [{
        id: 'items',
        type: 'api',
        endpoint: '/api/admin/items',
        errorHandling: { 500: { handler: 'setState', params: { target: 'global', failedAt: 'items' } } },
      }],
    }));
    test.mockApiError('items', 500, 'boom');

    await test.render();

    await waitFor(() => expect(screen.getByTestId('probe').textContent).toBe('500|items'));
  });

  /**
   * @scenario item=t6_response_shape
   * @effects tool_context_matches_app
   */
  it('T6: 직접 모의도 URL 모의와 같은 규칙으로 응답 본문을 싣고, 받기 호출로 기록된다(waitForDataSource)', async () => {
    const test = makeTest(textLayout('{{items.data.name}}', {
      data_sources: [{ id: 'items', type: 'api', endpoint: '/api/admin/items' }],
    }));
    test.mockApi('items', { response: { data: { name: 'A' } } });

    await test.render();
    await test.waitForDataSource('items');

    expect(screen.getByTestId('probe').textContent).toBe('A');
  });

  /**
   * @scenario item=t7_datasource_init
   * @effects tool_context_matches_app
   */
  it('T7: 데이터소스 initLocal·initGlobal 을 실제 앱 규칙으로 적용한다', async () => {
    const test = makeTest(textLayout('{{_local.form.name}}|{{_global.profile.name}}', {
      data_sources: [
        { id: 'item', type: 'api', endpoint: '/api/admin/item', initLocal: 'form' },
        { id: 'me', type: 'api', endpoint: '/api/admin/me', initGlobal: 'profile' },
      ],
    }));
    test.mockApi('item', { response: { data: { name: '폼' } } });
    test.mockApi('me', { response: { data: { name: '나' } } });

    await test.render();

    await waitFor(() => expect(screen.getByTestId('probe').textContent).toBe('폼|나'));
  });

  /**
   * @scenario item=t8_defines_warnings
   * @effects tool_context_matches_app
   */
  it('T8: _defines·_globalSetState·_global.layoutWarnings 를 싣는다', async () => {
    const test = makeTest(textLayout('{{_defines.LIMIT}}|{{_globalSetState ? "setter" : "none"}}|{{_global.layoutWarnings.length}}', {
      defines: { LIMIT: 20 },
      warnings: [{ id: 'w1', message: '경고' }],
    }));

    await test.render();

    expect(screen.getByTestId('probe').textContent).toBe('20|setter|1');
  });

  /**
   * @scenario item=t9_init_actions
   * @effects tool_context_matches_app
   */
  it('T9: init_actions 를 setState 흉내가 아니라 실제 ActionDispatcher 로 실행한다(sequence 포함)', async () => {
    const test = makeTest(textLayout('{{_global.stepA}}|{{_global.stepB}}', {
      init_actions: [{
        handler: 'sequence',
        actions: [
          { handler: 'setState', params: { target: 'global', stepA: 'a' } },
          { handler: 'setState', params: { target: 'global', stepB: 'b' } },
        ],
      }],
    }));

    await test.render();

    expect(screen.getByTestId('probe').textContent).toBe('a|b');
  });

  /**
   * @scenario item=t10_modal_toast
   * @effects tool_context_matches_app
   */
  it('T10: 액션으로 띄운 토스트·모달이 getToasts·getModalStack 에 잡히고, 모달은 열렸을 때만 열린 상태로 그린다', async () => {
    const registry = createMockComponentRegistryWithBasics() as any;
    registry.register('composite', 'Modal', ({ isOpen, children }: any) =>
      React.createElement('div', { 'data-testid': 'modal', 'data-open': String(!!isOpen) }, children));
    const test = makeTest({
      version: '1.0.0',
      layout_name: 'parity_modal',
      components: [{ id: 'probe', type: 'basic', name: 'Span', props: { text: 'x' } }],
      modals: [{ id: 'm1', type: 'composite', name: 'Modal', children: [] }],
    }, { componentRegistry: registry, includeModals: true });

    await test.render();
    expect(screen.getByTestId('modal').getAttribute('data-open')).toBe('false');

    await test.triggerAction({ type: 'click', handler: 'toast', params: { type: 'success', message: '저장됨' } } as any);
    await test.triggerAction({ type: 'click', handler: 'openModal', target: 'm1' } as any);
    await test.rerender();

    expect(test.getToasts()).toEqual([{ type: 'success', message: '저장됨' }]);
    expect(test.getModalStack()).toEqual(['m1']);
    expect(screen.getByTestId('modal').getAttribute('data-open')).toBe('true');
  });
});
