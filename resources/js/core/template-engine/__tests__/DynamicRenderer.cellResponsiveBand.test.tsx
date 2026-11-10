/**
 * @file DynamicRenderer.cellResponsiveBand.test.tsx
 * @description 셀 정의(`cellChildren` 등 컴포넌트가 직접 그리는 키)에 둔 `responsive` 가, 폭 구독을 하지 않는 복합 컴포넌트에서도
 * 구간 경계를 넘을 때 다시 그려지는지
 *
 * 셀은 복합 컴포넌트가 렌더 중에 `renderItemChildren` 으로 그리고, 그 순간의 폭(`renderEnvRef.current.responsiveWidth`)으로
 * 분기를 고른다. 번들 DataGrid·CardGrid 는 스스로 `useResponsive` 를 구독해 폭이 바뀌면 다시 그린다. 구독하지 않는
 * 서드파티 컴포넌트는 props 가 그대로면 다시 그려지지 않았고(레지스트리가 모든 컴포넌트를 `React.memo` 로 감싼다),
 * 셀이 이전 구간 값에 머물렀다. 이제 엔진이 셀 정의 안의 `responsive` 분기 판정 문자열을 `__responsiveBand` prop 으로
 * 넘겨, 구간 경계에서만 memo 비교가 깨지게 한다. 같은 구간 안 폭 변경에는 다시 그리지 않는다.
 *
 * 컴포넌트는 `registerComponent`(실제 등록 경로, memo 감쌈)로 넣는다 — 감싸지 않은 컴포넌트를 직접 넣으면 요소만 새로
 * 만들어도 다시 그려져 운영과 다른 거짓 green 이 된다.
 *
 * 하네스: 실물 `ResponsiveProvider`·`ResponsiveManager`·`DynamicRenderer`·`renderItemChildren`.
 *
 * @scenario-manifest tests/scenarios/cell-responsive-band.yaml
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
import { renderItemChildren } from '../helpers/RenderHelpers';

/** 복합 컴포넌트가 실제로 다시 그려진 횟수 */
let gridRenders = 0;

const Label: React.FC<{ text?: string; children?: React.ReactNode; 'data-testid'?: string; className?: string }> = ({
  text,
  children,
  className,
  'data-testid': testId,
}) => <span data-testid={testId} className={className}>{children ?? text}</span>;

const componentMap = { Label };

/** 폭을 구독하지 않는 서드파티형 셀 그리드 — 받은 셀 정의를 행마다 renderItemChildren 으로 그린다 */
const PlainCellGrid: React.FC<{ rows?: any[]; cellChildren?: any[]; __componentContext?: any }> = ({
  rows = [],
  cellChildren = [],
  __componentContext,
}) => {
  gridRenders += 1;

  return (
    <div data-testid="grid">
      {rows.map((row, index) => (
        <div key={index}>
          {renderItemChildren(cellChildren, { row }, componentMap, `cell-${index}`, { componentContext: __componentContext })}
        </div>
      ))}
    </div>
  );
};

describe('[case:components-misc-cell-responsive-band-without-subscription] 셀 정의의 responsive — 폭을 구독하지 않는 복합 컴포넌트도 구간 경계에서 다시 그린다', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  const translationContext: TranslationContext = { templateId: 'test-template', locale: 'ko' };
  const dataContext = { rows: [{ name: 'A' }, { name: 'B' }] };

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Label: { component: Label, metadata: { name: 'Label', type: 'basic' } },
    };
    // 실제 등록 경로 — registerComponent 가 React.memo 로 감싼다(운영의 모든 컴포넌트와 같다)
    (registry as any).registerComponent('PlainCellGrid', PlainCellGrid, { name: 'PlainCellGrid', type: 'composite' });
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    gridRenders = 0;
    act(() => responsiveManager._setWidthForTesting(1280));
  });

  afterEach(() => {
    cleanup();
    act(() => responsiveManager._setWidthForTesting(1024));
  });

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

  const resizeTo = (width: number): void => {
    act(() => responsiveManager._setWidthForTesting(width));
  };

  const textsOf = (testId: string): string[] =>
    Array.from(document.querySelectorAll(`[data-testid="${testId}"]`)).map((el) => el.textContent ?? '');

  /** 셀 text 를 구간마다 바꾸는 그리드 정의 */
  const gridDef = (cell: Record<string, any>): ComponentDefinition => ({
    id: 'plain-grid',
    type: 'composite',
    name: 'PlainCellGrid',
    props: { rows: '{{rows}}', cellChildren: [cell] },
  });

  // @scenario case=top_level_cell
  // @effects cell_responsive_follows_band_without_subscription
  it('최상위 셀의 responsive text 가 1280→500→1280 로 바뀐다', () => {
    render(
      <ResponsiveProvider>
        {rendererOf(gridDef({ type: 'basic', name: 'Label', props: { 'data-testid': 'cell' }, text: 'wide {{row.name}}', responsive: { portable: { text: 'narrow {{row.name}}' } } }))}
      </ResponsiveProvider>,
    );
    expect(textsOf('cell')).toEqual(['wide A', 'wide B']);

    resizeTo(500);
    expect(textsOf('cell')).toEqual(['narrow A', 'narrow B']);

    resizeTo(1280);
    expect(textsOf('cell')).toEqual(['wide A', 'wide B']);
  });

  // @scenario case=nested_cell
  // @effects cell_responsive_follows_band_without_subscription
  it('셀 안 깊은 자식과 사용자 지정 구간(0-639)도 경계를 넘으면 바뀐다', () => {
    render(
      <ResponsiveProvider>
        {rendererOf(gridDef({
          type: 'basic',
          name: 'Label',
          children: [{ type: 'basic', name: 'Label', props: { 'data-testid': 'inner', className: 'wide' }, responsive: { '0-639': { props: { className: 'narrow' } } } }],
        }))}
      </ResponsiveProvider>,
    );
    const classesOf = (): string[] => Array.from(document.querySelectorAll('[data-testid="inner"]')).map((el) => el.className);
    expect(classesOf()).toEqual(['wide', 'wide']);

    resizeTo(700);
    expect(classesOf()).toEqual(['wide', 'wide']);

    resizeTo(600);
    expect(classesOf()).toEqual(['narrow', 'narrow']);
  });

  // @scenario case=same_band
  // @effects cell_band_rerender_only_on_boundary
  it('같은 구간 안의 폭 변경(1280→1300→1440)에는 복합 컴포넌트를 다시 그리지 않는다', () => {
    render(
      <ResponsiveProvider>
        {rendererOf(gridDef({ type: 'basic', name: 'Label', props: { 'data-testid': 'cell' }, text: 'wide', responsive: { portable: { text: 'narrow' } } }))}
      </ResponsiveProvider>,
    );
    const before = gridRenders;

    resizeTo(1300);
    resizeTo(1440);
    expect(gridRenders).toBe(before);
  });

  // @scenario case=no_cell_responsive
  // @effects cell_band_rerender_only_on_boundary
  it('셀에 responsive 가 없으면 구간 경계를 넘어도 다시 그리지 않는다(종전과 같음)', () => {
    render(
      <ResponsiveProvider>
        {rendererOf(gridDef({ type: 'basic', name: 'Label', props: { 'data-testid': 'cell' }, text: 'same' }))}
      </ResponsiveProvider>,
    );
    const before = gridRenders;

    resizeTo(500);
    resizeTo(1280);
    expect(gridRenders).toBe(before);
  });
});
