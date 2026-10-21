/**
 * @file DynamicRenderer.contextBoundCache.test.tsx
 * @description 같은 화면 안에서 컴포넌트마다 값이 다른 루트를 읽는 props 가 바인딩 캐시를 공유하지 않는다 (engine-v1.82.1)
 *
 * 바인딩 값 캐시(키 = 경로·식 글자)는 컨텍스트를 모른다. 화면 전환 때 비우는 것(TemplateApp)으로는 막히지 않는 갈래 —
 * 같은 화면에서 같은 글자를 다른 값으로 읽는다.
 *  1a. 같은 화면의 두 확장 지점 주입 컴포넌트가 `{{extensionPointProps.x}}` 를 각자 값으로 읽는다
 *  2.  `_computed` 를 문자열 보간·배열·중첩 객체 props 로 읽는다(단일 바인딩만 렌더러가 캐시를 건너뛰었다 — 실사용:
 *      sirsoft-admin_basic 통계 화면 `tabs[].href = "...?tab=x{{_computed.tabQuery}}"`)
 *  3.  slot 파이프식(주석은 skipCache 인데 options 없이 평가)
 * 수정 전 엔진으로 세 건 모두 red(탐침 확인). 컨텍스트를 바꾼 재렌더는 같은 엔진 인스턴스로 한다(실물과 같음).
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
const Box: React.FC<{ tid?: string; children?: React.ReactNode }> = ({ tid, children }) => (
  <div data-testid={tid}>{children}</div>
);
const Dump: React.FC<Record<string, any>> = ({ tid, children: _c, ...rest }) => (
  <span data-testid={tid}>{JSON.stringify(rest)}</span>
);

describe('DynamicRenderer — 컴포넌트마다 값이 다른 루트는 바인딩 캐시를 공유하지 않는다 (engine-v1.82.1)', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  const el = (def: ComponentDefinition, dataContext: any) => (
    <DynamicRenderer
      componentDef={def}
      dataContext={dataContext}
      translationContext={translationContext}
      registry={registry}
      bindingEngine={bindingEngine}
      translationEngine={translationEngine}
      actionDispatcher={actionDispatcher}
    />
  );
  const read = (tid: string) => JSON.parse(screen.getByTestId(tid).textContent || '{}');

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Box: { component: Box, metadata: { name: 'Box', type: 'basic' } },
      Dump: { component: Dump, metadata: { name: 'Dump', type: 'basic' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });
  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  /**
   * @scenario surface=cache
   * @effects context_bound_roots_not_cached
   */
  it('1a. 같은 화면 두 주입 컴포넌트가 extensionPointProps 정적 경로(단일·보간)를 각자 값으로 받는다', () => {
    const child = (tid: string, pid: string): ComponentDefinition => ({
      id: tid, type: 'basic', name: 'Dump',
      props: { tid, v: '{{extensionPointProps.pid}}', s: 'id-{{extensionPointProps.pid}}' },
      extensionPointProps: { pid },
    } as any);
    const def = { id: 'root', type: 'basic', name: 'Box', props: { tid: 'root' }, children: [child('a', 'A'), child('b', 'B')] } as any;
    render(el(def, {}));

    expect(read('a')).toMatchObject({ v: 'A', s: 'id-A' });
    expect(read('b')).toMatchObject({ v: 'B', s: 'id-B' }); // 결함: { v: 'A', s: 'id-A' }
  });

  /**
   * @scenario surface=cache
   * @effects context_bound_roots_not_cached
   */
  it('2. _computed 를 보간·배열·중첩 객체로 읽는 props 가 _computed 재계산 뒤 새 값이다', () => {
    const def: ComponentDefinition = {
      id: 'p', type: 'basic', name: 'Dump',
      props: {
        tid: 'p',
        single: '{{_computed.tq}}',
        interp: '/x?tab=a{{_computed.tq}}',
        arr: ['/x?tab=a{{_computed.tq}}'],
        tabs: [{ id: 't', href: '/x?tab=a{{_computed.tq}}', whole: '{{_computed.tq}}' }],
        nested: { href: '/x?tab=a{{_computed.tq}}' },
      },
    } as any;
    const ctx = (p: string) => ({
      query: { p },
      _local: {},
      _computedDefinitions: { tq: "{{'&p=' + query.p}}" },
      _computed: {},
    });
    const { rerender } = render(el(def, ctx('1')));
    expect(read('p').interp).toBe('/x?tab=a&p=1');

    rerender(el(def, ctx('2')));
    const after = read('p');
    expect(after.single).toBe('&p=2'); // 단일 바인딩은 getComputedAwareOptions 가 skipCache — 종전에도 통과
    expect({
      interp: after.interp,
      arr: after.arr[0],
      tabsHref: after.tabs[0].href,
      tabsWhole: after.tabs[0].whole,
      nested: after.nested.href,
    }).toEqual({ // 결함: 전부 &p=1
      interp: '/x?tab=a&p=2',
      arr: '/x?tab=a&p=2',
      tabsHref: '/x?tab=a&p=2',
      tabsWhole: '&p=2',
      nested: '/x?tab=a&p=2',
    });
  });

  /**
   * @scenario surface=cache
   * @effects context_bound_roots_not_cached
   */
  it('3. slot 파이프식은 컨텍스트가 바뀌면 새 슬롯 ID 로 평가된다', () => {
    const expr = "post.area | default('main')";
    const spy = vi.spyOn(bindingEngine, 'evaluatePipeExpression');
    const def = { id: 's', type: 'basic', name: 'Dump', slot: `{{${expr}}}`, props: { tid: 's' } } as any;
    const { rerender } = render(el(def, { post: { area: 'left' } }));
    rerender(el(def, { post: { area: 'right' } }));

    const slotCallIdx = spy.mock.calls.map((c, i) => (c[0] === expr ? i : -1)).filter((i) => i >= 0);
    expect(spy.mock.results[slotCallIdx[0]].value).toBe('left');
    expect(spy.mock.results[slotCallIdx[slotCallIdx.length - 1]].value).toBe('right'); // 결함: 'left' (options undefined → 영구 캐시)
  });

});
