/**
 * DynamicRenderer.resizePendingClear.test.tsx
 *
 * 사례 40(engine-v1.63.3) 위험 핀 — 트리거의 절반을 실물 렌더로 잠근다.
 *
 * 공개 이슈 #130 의 재현 조건은 두 조각으로 이루어진다:
 *   ① CKEditor 가 `setLocal({ render:false, selfManaged:true })` 로 저장소 B 에만 본문을 기록
 *   ② **브레이크포인트를 넘지 않는 폭 변경만으로도** 루트 렌더러가 리렌더되어
 *      `__g7PendingLocalState` 가 null 이 된다
 *
 * 이 파일은 ②만 잠근다. `useLayoutEffect` 에 의존성 배열이 없어(DynamicRenderer.tsx:1082~1116)
 * ResponsiveContext 의 width 값이 바뀌어 리렌더가 일어나기만 하면 클리어가 실행된다는 사실이
 * 코드 주석이 아니라 실물 렌더로 고정된다. 이 줄에 의존성 배열이 붙거나 조건이 생기면
 * 여기서 red 가 된다.
 *
 * 소실 자체(저장소 B 통째 교체)는 이 계층에서 재현할 수 없다 — TemplateApp 재구현이 필요하다.
 * 그 축은 troubleshooting-state-setstate.test.ts 의 `[사례 40]` 이 담당한다.
 * 따라서 이 파일은 fail-first 가 아니다.
 *
 * @see 트러블슈팅 사례 40 (저장소 B 통째 교체 + stale 반환값)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import * as ResponsiveContextModule from '../ResponsiveContext';

vi.mock('../ResponsiveManager', () => ({
  responsiveManager: {
    getWidth: vi.fn(() => 1440),
    subscribe: vi.fn(() => () => {}),
    getMatchingKey: vi.fn(() => null),
    parseRange: vi.fn(() => null),
  },
}));

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode }> = ({
  className,
  children,
}) => (
  <div data-testid="test-div" className={className}>
    {children}
  </div>
);

describe('DynamicRenderer — 폭 변경만으로 __g7PendingLocalState 가 클리어된다 (사례 40 위험 핀)', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  const setWidth = (width: number) => {
    vi.spyOn(ResponsiveContextModule, 'useResponsive').mockReturnValue({
      width,
      isMobile: width < 768,
      isTablet: width >= 768 && width < 1024,
      isDesktop: width >= 1024,
      matchedPreset: width < 768 ? 'mobile' : width < 1024 ? 'tablet' : 'desktop',
    } as any);
  };

  const componentDef: ComponentDefinition = {
    id: 'resize-pending-root',
    type: 'basic',
    name: 'Div',
    props: { className: 'root' },
  };

  const renderTree = () => (
    <DynamicRenderer
      componentDef={componentDef}
      dataContext={{ _local: { form: { title: '제목' } } }}
      translationContext={translationContext}
      registry={registry}
      bindingEngine={bindingEngine}
      translationEngine={translationEngine}
      actionDispatcher={actionDispatcher}
    />
  );

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    };

    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };

    setWidth(1440);
    delete (window as any).__g7PendingLocalState;
    delete (window as any).__g7SetLocalOverrideKeys;
    delete (window as any).__g7ForcedLocalFields;
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    delete (window as any).__g7PendingLocalState;
    delete (window as any).__g7SetLocalOverrideKeys;
    delete (window as any).__g7ForcedLocalFields;
  });

  it('브레이크포인트를 넘지 않는 19px 변경만으로 pending 이 null 이 된다', () => {
    // @scenario save_flow=create, resize_kind=same_breakpoint
    // @effects resize_clears_pending_local_state
    const { rerender } = render(renderTree());

    // CKEditor 의 setLocal(render:false) 이 남긴 상태를 모사한다
    (window as any).__g7PendingLocalState = { form: { content: '<p>본문</p>' } };
    (window as any).__g7SetLocalOverrideKeys = { form: { content: '<p>본문</p>' } };
    (window as any).__g7ForcedLocalFields = { form: { content: '<p>본문</p>' } };

    // 1440 → 1421: desktop 프리셋 안에서만 움직인다 (브레이크포인트 미교차)
    setWidth(1421);
    rerender(renderTree());

    expect((window as any).__g7PendingLocalState).toBeNull();
  });

  it('같은 렌더에서 __g7ForcedLocalFields 는 살아남는다 (__g7SetLocalOverrideKeys 가 있으므로)', () => {
    // @scenario save_flow=create, resize_kind=crossed_breakpoint
    // @effects resize_clears_pending_local_state
    const { rerender } = render(renderTree());

    (window as any).__g7PendingLocalState = { form: { content: '<p>본문</p>' } };
    (window as any).__g7SetLocalOverrideKeys = { form: { content: '<p>본문</p>' } };
    (window as any).__g7ForcedLocalFields = { form: { content: '<p>본문</p>' } };

    setWidth(1421);
    rerender(renderTree());

    // 오버레이는 조건부 클리어라 남는다 — 그럼에도 결함이 나는 이유는
    // extendedDataContext useMemo 의 deps 가 이 전역을 포함하지 않아
    // 리사이즈 렌더에서 오버레이가 context.state 로 합성되지 않기 때문이다.
    expect((window as any).__g7ForcedLocalFields).toEqual({ form: { content: '<p>본문</p>' } });
  });

  it('__g7SetLocalOverrideKeys 가 없으면 오버레이도 함께 클리어된다 (조건부 클리어 계약)', () => {
    const { rerender } = render(renderTree());

    (window as any).__g7PendingLocalState = { form: { content: '<p>본문</p>' } };
    (window as any).__g7ForcedLocalFields = { form: { content: '<p>본문</p>' } };
    delete (window as any).__g7SetLocalOverrideKeys;

    setWidth(1421);
    rerender(renderTree());

    expect((window as any).__g7PendingLocalState).toBeNull();
    expect((window as any).__g7ForcedLocalFields).toBeUndefined();
  });
});
