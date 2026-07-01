/**
 * initial-state-isolated-render.test.tsx — 격리 초기값(initIsolated)의 런타임 주입
 *
 *
 * ① initIsolated 정적값이 `isolatedState` 영역에 `_isolated` 로 주입(중첩 depth 보존,
 *    DynamicRenderer.tsx:3848-3862)
 * ② **iteration/반복 인스턴스별 독립 `_isolated`** — 한 항목 변경이 형제에 미간섭
 *    (scopeId 별 분리)
 * ③ initIsolated(레이아웃 → `_isolatedInit`) + isolatedState(노드) **같은 키 병합 순서**
 *    — `{...baseIsolatedState(노드), ..._isolatedInit(레이아웃)}` 이라 레이아웃
 *    initIsolated 가 노드 isolatedState 를 덮는다(DynamicRenderer.tsx:3850-3852)
 * ④ `isolatedState` 없는 initIsolated = 주입돼도 미참조(죽은 값)
 * ⑤ 부모+자식 상속 병합본(shallow)도 동일 주입
 * 를 검증한다.
 *
 * 엔진 사실:
 * - `isolatedState` 노드는 IsolatedStateProvider 로 감싸지며 초기 상태는
 *   `dataContext._isolatedInit` 가 있으면 `{...노드 isolatedState, ...초기값}` 으로 병합
 *   (DynamicRenderer.tsx:3843-3861). 즉 `_isolatedInit`(레이아웃 initIsolated)가 노드
 *   값을 덮는다.
 * - 스코프 안의 컴포넌트는 `{{_isolated.*}}` 로 격리 상태를 읽는다
 *   (DynamicRenderer.tsx:1702-1704).
 * - `isolatedScopeId` 가 다른 두 인스턴스는 독립 IsolatedStateProvider → 독립 상태.
 *
 * createLayoutTest 는 `_isolatedInit` 를 dataContext 에 넣지 않으므로, 본 테스트는 util
 * 과 동일한 방식으로 DynamicRenderer 를 직접 마운트하되 dataContext._isolatedInit 을
 * 주입해 레이아웃 initIsolated 주입 경로를 그대로 재현한다.
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import DynamicRenderer, { type ComponentDefinition } from '../../../DynamicRenderer';
import { DataBindingEngine } from '../../../DataBindingEngine';
import { TranslationEngine } from '../../../TranslationEngine';
import { ActionDispatcher } from '../../../ActionDispatcher';
import { createMockComponentRegistryWithBasics } from '../../../__tests__/utils/layoutTestUtils';

const registry = createMockComponentRegistryWithBasics();

/** DynamicRenderer 직접 마운트 — createLayoutTest 와 동일한 인자 구성 */
function mount(components: ComponentDefinition[], dataContext: Record<string, any>) {
  TranslationEngine.resetInstance();
  const componentDef: ComponentDefinition = {
    id: 'root',
    type: 'layout',
    name: 'Fragment',
    children: components,
  };
  return render(
    React.createElement(DynamicRenderer, {
      componentDef,
      dataContext,
      translationContext: { templateId: 'test-template', locale: 'ko' },
      registry: registry as any,
      bindingEngine: new DataBindingEngine(),
      translationEngine: TranslationEngine.getInstance(),
      actionDispatcher: new ActionDispatcher(),
    }),
  );
}

describe('격리 초기값(initIsolated) 런타임 주입', () => {
  afterEach(() => cleanup());

  it('① isolatedState 영역 안의 컴포넌트가 격리 초기값을 _isolated 로 읽는다', () => {
    // 노드 isolatedState 에 선언한 초기값이 영역 안에서 {{_isolated.*}} 로 노출된다.
    const components: ComponentDefinition[] = [
      {
        id: 'scope-a',
        type: 'basic',
        name: 'Div',
        isolatedState: { label: '격리값', count: 7 },
        isolatedScopeId: 'scope-a',
        children: [
          { id: 'lbl', type: 'basic', name: 'Span', text: '{{_isolated.label}}' },
          { id: 'cnt', type: 'basic', name: 'Span', text: '카운트 {{_isolated.count}}' },
        ],
      } as ComponentDefinition,
    ];

    mount(components, {});

    expect(screen.getByText('격리값')).toBeInTheDocument();
    expect(screen.getByText('카운트 7')).toBeInTheDocument();
  });

  it('①-b 중첩 객체 격리값도 depth 보존되어 주입된다', () => {
    const components: ComponentDefinition[] = [
      {
        id: 'scope-nested',
        type: 'basic',
        name: 'Div',
        isolatedState: { meta: { author: { name: '홍길동' } } },
        isolatedScopeId: 'scope-nested',
        children: [
          { id: 'a', type: 'basic', name: 'Span', text: '{{_isolated.meta.author.name}}' },
        ],
      } as ComponentDefinition,
    ];

    mount(components, {});
    expect(screen.getByText('홍길동')).toBeInTheDocument();
  });

  it('② 반복 인스턴스별로 독립 _isolated 를 가진다 (형제 미간섭, scopeId 분리)', () => {
    // 같은 모양이지만 scopeId 가 다른 두 인스턴스 — 각자 자기 격리값만 본다.
    const components: ComponentDefinition[] = [
      {
        id: 'inst-1',
        type: 'basic',
        name: 'Div',
        isolatedState: { selected: '첫번째' },
        isolatedScopeId: 'slider-0',
        children: [{ id: 's1', type: 'basic', name: 'Span', text: '{{_isolated.selected}}' }],
      } as ComponentDefinition,
      {
        id: 'inst-2',
        type: 'basic',
        name: 'Div',
        isolatedState: { selected: '두번째' },
        isolatedScopeId: 'slider-1',
        children: [{ id: 's2', type: 'basic', name: 'Span', text: '{{_isolated.selected}}' }],
      } as ComponentDefinition,
    ];

    mount(components, {});

    // 각 인스턴스가 자기 값만 — 상호 간섭 없음.
    expect(screen.getByText('첫번째')).toBeInTheDocument();
    expect(screen.getByText('두번째')).toBeInTheDocument();
  });

  it('③ initIsolated(레이아웃)+isolatedState(노드) 같은 키 → 레이아웃 initIsolated 가 덮는다', () => {
    // DynamicRenderer.tsx:3850-3852 — {...baseIsolatedState(노드), ..._isolatedInit(레이아웃)}
    // 이므로 레이아웃 initIsolated 가 노드 값을 덮는다(병합 순서).
    const components: ComponentDefinition[] = [
      {
        id: 'scope-merge',
        type: 'basic',
        name: 'Div',
        isolatedState: { mode: '노드값', onlyNode: 'N' },
        isolatedScopeId: 'merge-scope',
        children: [
          { id: 'm', type: 'basic', name: 'Span', text: '{{_isolated.mode}}' },
          { id: 'n', type: 'basic', name: 'Span', text: '노드:{{_isolated.onlyNode}}' },
        ],
      } as ComponentDefinition,
    ];

    // 레이아웃 initIsolated → dataContext._isolatedInit
    mount(components, { _isolatedInit: { mode: '레이아웃값' } });

    // 같은 키 mode 는 레이아웃 initIsolated 가 덮음
    expect(screen.getByText('레이아웃값')).toBeInTheDocument();
    // 노드 고유 키 onlyNode 는 보존
    expect(screen.getByText('노드:N')).toBeInTheDocument();
  });

  it('④ isolatedState 노드가 없으면 initIsolated 는 주입돼도 어디서도 참조되지 않는다 (죽은 값)', () => {
    // 격리 영역(isolatedState 노드)이 없는 레이아웃 — _isolatedInit 가 있어도
    // _isolated 네임스페이스가 생성되지 않아 참조 불가(엔진 경고 없음, 죽은 값).
    const components: ComponentDefinition[] = [
      // isolatedState 없는 일반 노드 — {{_isolated.ghost}} 는 빈 값으로 평가됨.
      { id: 'plain', type: 'basic', name: 'Span', text: '값[{{_isolated.ghost ?? ""}}]' },
    ];

    mount(components, { _isolatedInit: { ghost: '죽은값' } });

    // _isolated 영역 밖이라 ghost 미참조 → 빈 값.
    expect(screen.getByText('값[]')).toBeInTheDocument();
    expect(screen.queryByText('값[죽은값]')).not.toBeInTheDocument();
  });

  it('⑤ 부모+자식 상속 병합본(shallow)도 isolatedState 영역에 동일 주입된다', () => {
    // 상속 병합 SSoT = mergeShallow(initIsolated) — 자식이 부모 키 덮음. 프론트는
    // 병합된 initIsolated 를 _isolatedInit 로 받는다.
    const parentInitIsolated = { scrollIdx: 0, theme: 'light' };
    const childInitIsolated = { theme: 'dark' }; // 부모 theme 덮음
    const mergedInitIsolated = { ...parentInitIsolated, ...childInitIsolated };

    const components: ComponentDefinition[] = [
      {
        id: 'scope-inherit',
        type: 'basic',
        name: 'Div',
        isolatedState: {},
        isolatedScopeId: 'inherit-scope',
        children: [
          { id: 'i', type: 'basic', name: 'Span', text: 'idx{{_isolated.scrollIdx}}' },
          { id: 't', type: 'basic', name: 'Span', text: '테마:{{_isolated.theme}}' },
        ],
      } as ComponentDefinition,
    ];

    mount(components, { _isolatedInit: mergedInitIsolated });

    expect(screen.getByText('idx0')).toBeInTheDocument(); // 미덮은 부모 키
    expect(screen.getByText('테마:dark')).toBeInTheDocument(); // 자식 덮은 키
  });
});
