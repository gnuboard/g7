/**
 * @file DynamicRenderer.iterationResponsiveResize.test.tsx
 * @description 반복(`iteration`)하는 노드 자신에 둔 `responsive` 오버라이드가 창 폭만 바뀌어도 항목에 반영되는지
 *
 * 반복 노드의 항목 렌더는 폭 판정이 끝난 정의(`effectiveComponentDef`)를 복사해 항목마다 넘긴다. 그 복사본을
 * 만드는 메모가 정의의 일부(iteration·children·id)만 지켜보면, 폭이 바뀌어 props·text·if 만 달라진 경우에
 * 항목이 이전 구간의 값을 그대로 쓴다(첫 렌더는 맞고, 다른 상태가 바뀔 때까지 틀린 값이 남는다).
 *
 * 하네스: 실물 `ResponsiveProvider`(구독 경로와 미리보기 `overrideWidth` 경로)·`ResponsiveManager`·`DynamicRenderer`.
 * 데이터 컨텍스트와 렌더러 요소는 같은 참조로 고정해, 다시 그리기의 계기가 폭 변경 하나뿐이게 한다.
 *
 * @scenario-manifest tests/scenarios/iteration-responsive-resize.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, render } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { ResponsiveProvider } from '../ResponsiveContext';
import { responsiveManager } from '../ResponsiveManager';

/** 항목 컴포넌트가 실제로 다시 그려진 횟수 (data-testid 별) */
const renderCounts: Record<string, number> = {};

const Box: React.FC<{ className?: string; children?: React.ReactNode; 'data-testid'?: string }> = ({
  className,
  children,
  'data-testid': testId,
}) => {
  if (testId) renderCounts[testId] = (renderCounts[testId] ?? 0) + 1;

  return (
    <div data-testid={testId} className={className}>
      {children}
    </div>
  );
};

const Label: React.FC<{ text?: string; children?: React.ReactNode; 'data-testid'?: string }> = ({
  text,
  children,
  'data-testid': testId,
}) => <span data-testid={testId}>{children ?? text}</span>;

const items = [
  { name: 'A', mobile: true },
  { name: 'B', mobile: false },
];

describe('[case:components-misc-iteration-self-responsive-resize] 반복 노드 자신의 responsive — 폭만 바뀌어도 항목에 반영된다', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  const translationContext: TranslationContext = { templateId: 'test-template', locale: 'ko' };
  /** 다시 그리기마다 같은 참조 — 폭 말고 다른 계기를 만들지 않는다 */
  const dataContext = { items };

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Box: { component: Box, metadata: { name: 'Box', type: 'basic' } },
      Label: { component: Label, metadata: { name: 'Label', type: 'basic' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    for (const key of Object.keys(renderCounts)) delete renderCounts[key];
    act(() => responsiveManager._setWidthForTesting(1280));
  });

  afterEach(() => {
    cleanup();
    act(() => responsiveManager._setWidthForTesting(1024));
  });

  /**
   * 렌더러 요소를 한 번만 만든다(같은 참조를 다시 넘기면 폭 문맥을 읽는 쪽만 다시 그려진다).
   *
   * @param def 렌더할 정의
   * @returns 렌더러 요소
   */
  const rendererOf = (def: ComponentDefinition): React.ReactElement => (
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

  /**
   * 창 폭 변경 — ResponsiveManager 구독자(루트 ResponsiveProvider)에게 알리는 실제 경로.
   *
   * @param width 새 폭
   */
  const resizeTo = (width: number): void => {
    act(() => responsiveManager._setWidthForTesting(width));
  };

  const classesOf = (testId: string): string[] =>
    Array.from(document.querySelectorAll(`[data-testid="${testId}"]`)).map((el) => el.className);

  const textsOf = (testId: string): string[] =>
    Array.from(document.querySelectorAll(`[data-testid="${testId}"]`)).map((el) => el.textContent ?? '');

  // @scenario case=props_resize
  // @effects iteration_items_follow_matched_branch
  it('props(className) 오버라이드가 1280→500→1280 로 바뀐다', () => {
    const def: ComponentDefinition = {
      id: 'loop-class',
      type: 'basic',
      name: 'Box',
      props: { className: 'grid-desktop', 'data-testid': 'row' },
      iteration: { source: '{{items}}', item_var: 'row' },
      responsive: { portable: { props: { className: 'grid-portable' } } },
      children: [{ id: 'row-name', type: 'basic', name: 'Label', text: '{{row.name}}' }],
    };
    render(<ResponsiveProvider>{rendererOf(def)}</ResponsiveProvider>);
    expect(classesOf('row')).toEqual(['grid-desktop', 'grid-desktop']);

    resizeTo(500);
    expect(classesOf('row')).toEqual(['grid-portable', 'grid-portable']);

    resizeTo(1280);
    expect(classesOf('row')).toEqual(['grid-desktop', 'grid-desktop']);
  });

  // @scenario case=text_resize
  // @effects iteration_items_follow_matched_branch
  it('text 오버라이드가 1280→500→1280 로 바뀐다', () => {
    const def: ComponentDefinition = {
      id: 'loop-text',
      type: 'basic',
      name: 'Label',
      props: { 'data-testid': 'cell' },
      text: '{{cell.name}}-넓은화면',
      iteration: { source: '{{items}}', item_var: 'cell' },
      responsive: { mobile: { text: '{{cell.name}}-휴대폰' } },
    };
    render(<ResponsiveProvider>{rendererOf(def)}</ResponsiveProvider>);
    expect(textsOf('cell')).toEqual(['A-넓은화면', 'B-넓은화면']);

    resizeTo(500);
    expect(textsOf('cell')).toEqual(['A-휴대폰', 'B-휴대폰']);

    resizeTo(1280);
    expect(textsOf('cell')).toEqual(['A-넓은화면', 'B-넓은화면']);
  });

  // @scenario case=if_resize
  // @effects iteration_items_follow_matched_branch
  it('if 오버라이드가 1280→500→1280 로 항목 표시를 바꾼다', () => {
    const def: ComponentDefinition = {
      id: 'loop-if',
      type: 'basic',
      name: 'Label',
      props: { 'data-testid': 'entry' },
      text: '{{entry.name}}',
      iteration: { source: '{{items}}', item_var: 'entry' },
      responsive: { mobile: { if: '{{entry.mobile}}' } },
    };
    render(<ResponsiveProvider>{rendererOf(def)}</ResponsiveProvider>);
    expect(textsOf('entry')).toEqual(['A', 'B']);

    resizeTo(500);
    expect(textsOf('entry')).toEqual(['A']);

    resizeTo(1280);
    expect(textsOf('entry')).toEqual(['A', 'B']);
  });

  // @scenario case=preview_width
  // @effects iteration_items_follow_matched_branch
  it('편집기 미리보기 폭(overrideWidth)만 바꿔도 반복 노드 자신의 className 이 바뀐다', () => {
    const def: ComponentDefinition = {
      id: 'loop-preview',
      type: 'basic',
      name: 'Box',
      props: { className: 'grid-desktop', 'data-testid': 'preview-row' },
      iteration: { source: '{{items}}', item_var: 'row' },
      responsive: { mobile: { props: { className: 'grid-mobile' } } },
    };
    const element = rendererOf(def);
    const { rerender } = render(<ResponsiveProvider overrideWidth={1280}>{element}</ResponsiveProvider>);
    expect(classesOf('preview-row')).toEqual(['grid-desktop', 'grid-desktop']);

    rerender(<ResponsiveProvider overrideWidth={390}>{element}</ResponsiveProvider>);
    expect(classesOf('preview-row')).toEqual(['grid-mobile', 'grid-mobile']);
  });

  // @scenario case=definition_replaced
  // @effects iteration_items_follow_definition
  it('반복 노드의 정의가 props 만 바뀐 새 객체로 오면(iteration·children 참조 유지) 항목이 새 props 를 쓴다', () => {
    const iteration = { source: '{{items}}', item_var: 'row' };
    const children: ComponentDefinition[] = [{ id: 'edit-name', type: 'basic', name: 'Label', text: '{{row.name}}' }];
    const before: ComponentDefinition = {
      id: 'loop-edit',
      type: 'basic',
      name: 'Box',
      props: { className: 'before', 'data-testid': 'edit-row' },
      iteration,
      children,
    };
    const { rerender } = render(<ResponsiveProvider>{rendererOf(before)}</ResponsiveProvider>);
    expect(classesOf('edit-row')).toEqual(['before', 'before']);

    const after: ComponentDefinition = { ...before, props: { className: 'after', 'data-testid': 'edit-row' } };
    rerender(<ResponsiveProvider>{rendererOf(after)}</ResponsiveProvider>);
    expect(classesOf('edit-row')).toEqual(['after', 'after']);
  });

  // @scenario case=same_branch_resize
  // @effects same_branch_resize_keeps_items
  it('같은 구간 안의 폭 변경(1280→1300→1440)은 항목을 다시 그리지 않는다', () => {
    const def: ComponentDefinition = {
      id: 'loop-same-range',
      type: 'basic',
      name: 'Box',
      props: { className: 'grid-base', 'data-testid': 'same-row' },
      iteration: { source: '{{items}}', item_var: 'row' },
      // 넓은 화면도 분기가 매칭되어야 한다 — 매칭이 없으면 원본 정의를 그대로 돌려 폭과 무관하게 같은 객체다
      responsive: {
        portable: { props: { className: 'grid-portable' } },
        desktop: { props: { className: 'grid-desktop' } },
      },
    };
    render(<ResponsiveProvider>{rendererOf(def)}</ResponsiveProvider>);
    const afterMount = renderCounts['same-row'];
    expect(afterMount).toBeGreaterThan(0);

    resizeTo(1300);
    resizeTo(1440);
    expect(renderCounts['same-row']).toBe(afterMount);
    expect(classesOf('same-row')).toEqual(['grid-desktop', 'grid-desktop']);

    resizeTo(500);
    expect(classesOf('same-row')).toEqual(['grid-portable', 'grid-portable']);
  });

  // @scenario case=non_iteration_control
  // @effects non_iteration_unchanged
  it('반복 노드가 아닌 노드의 responsive 는 종전대로 폭을 따른다 (대조)', () => {
    const def: ComponentDefinition = {
      id: 'plain',
      type: 'basic',
      name: 'Box',
      props: { className: 'plain-desktop', 'data-testid': 'plain' },
      responsive: { portable: { props: { className: 'plain-portable' } } },
    };
    render(<ResponsiveProvider>{rendererOf(def)}</ResponsiveProvider>);
    expect(classesOf('plain')).toEqual(['plain-desktop']);

    resizeTo(500);
    expect(classesOf('plain')).toEqual(['plain-portable']);
  });
});
