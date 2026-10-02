/**
 * 렌더 경로 패리티 — 미리보기 폭 (공개 이슈 #141 제보자 요청 회귀 테스트)
 *
 * 같은 트리 안에서
 *   ① DynamicRenderer 가 그리는 자식(Context 폭 = 미리보기 폭)
 *   ② composite 가 셀 렌더 함수(renderItemChildren)로 직접 그리는 자식
 * 이 같은 `responsive` 분기를 골라야 한다. 종전에는 ②가 전역 싱글톤(브라우저 창 폭)을 읽어
 * 미리보기 390 · 창 1440 에서 ①은 portable, ②는 base 로 갈렸다.
 *
 * ②는 템플릿이 쓰는 두 전달 방식을 모두 밟는다:
 *   - CardGrid 방식: `{ renderEnv: __componentContext.renderEnvRef.current }`
 *   - DataGrid 방식: `{ componentContext: __componentContext }`
 * (G7Core.renderItemChildren 래퍼는 두 옵션을 그대로 넘긴다 — G7CoreGlobals.renderEnvironment.test.ts)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { ResponsiveProvider } from '../ResponsiveContext';
import { responsiveManager } from '../ResponsiveManager';
import { renderItemChildren } from '../helpers/RenderHelpers';

const Div: React.FC<Record<string, any>> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>
    {children}
  </div>
);

/** 셀 정의를 렌더 함수로 직접 그리는 composite (CardGrid·DataGrid 의 셀 경로 축소판) */
const CellHost: React.FC<Record<string, any>> = ({ cellChildren, mode, __componentContext, children }) => {
  const options =
    mode === 'componentContext'
      ? { componentContext: __componentContext }
      : { renderEnv: __componentContext?.renderEnvRef?.current };
  const cells = renderItemChildren(cellChildren, { row: { id: 1 } }, { Div }, 'cell', options);
  return (
    <div>
      {children}
      {cells}
    </div>
  );
};

const responsiveDiv = (testId: string) => ({
  id: testId,
  type: 'basic',
  name: 'Div',
  props: { className: 'base', 'data-testid': testId },
  responsive: { portable: { props: { className: 'portable' } } },
});

const hostDef = (mode: string) =>
  ({
    id: 'host',
    type: 'composite',
    name: 'CellHost',
    props: { mode, cellChildren: [responsiveDiv('cell-path')] },
    children: [responsiveDiv('dr-path')],
  }) as unknown as ComponentDefinition;

describe('미리보기 폭 — DynamicRenderer 경로와 셀 렌더 경로가 같은 분기', () => {
  let registry: ComponentRegistry;
  let originalWidth: number;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: Div, metadata: { name: 'Div', type: 'basic' } },
      CellHost: { component: CellHost, metadata: { name: 'CellHost', type: 'composite' } },
    };
    originalWidth = responsiveManager.getWidth();
  });

  afterEach(() => {
    responsiveManager._setWidthForTesting(originalWidth);
    cleanup();
  });

  const renderTree = (overrideWidth: number, mode: string) =>
    render(
      <ResponsiveProvider overrideWidth={overrideWidth}>
        <DynamicRenderer
          componentDef={hostDef(mode)}
          dataContext={{}}
          translationContext={{ templateId: 't', locale: 'ko' }}
          registry={registry}
          bindingEngine={new DataBindingEngine()}
          translationEngine={new TranslationEngine()}
          actionDispatcher={new ActionDispatcher({ navigate: vi.fn() })}
          isEditMode
        />
      </ResponsiveProvider>,
    );

  for (const mode of ['renderEnv', 'componentContext']) {
    /**
     * @scenario preview_device=mobile, window_width=1440, cell_option=renderEnv|componentContext
     * @effects preview_cell_render_follows_preview_width
     */
    it(`[${mode}] 창 1440 · 미리보기 390 → 두 경로 모두 portable`, () => {
      responsiveManager._setWidthForTesting(1440);
      const { getByTestId } = renderTree(390, mode);
      expect(getByTestId('dr-path').className).toBe('portable');
      expect(getByTestId('cell-path').className).toBe('portable');
    });

    /**
     * @scenario preview_device=desktop, window_width=901, cell_option=renderEnv|componentContext
     * @effects preview_cell_render_follows_preview_width
     */
    it(`[${mode}] 역방향: 창 901 · 미리보기 1280 → 두 경로 모두 base`, () => {
      responsiveManager._setWidthForTesting(901);
      const { getByTestId } = renderTree(1280, mode);
      expect(getByTestId('dr-path').className).toBe('base');
      expect(getByTestId('cell-path').className).toBe('base');
    });
  }
});
