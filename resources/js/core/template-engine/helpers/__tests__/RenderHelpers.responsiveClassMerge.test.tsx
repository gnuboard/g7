/**
 * 셀·반복 항목 렌더 경로(renderItemChildren)의 구간 classMerge·classMap (engine-v1.98.0)
 *
 * DynamicRenderer 와 같은 순서(props 해석 → classMap → classMerge)로 적용돼야 셀 안 노드도 폭별 클래스가 같다.
 * 종전 셀 경로는 classMap 자체를 적용하지 않았다(번들 셀 정의 사용 0건 — 기존 화면 변화 없음).
 *
 * @scenario-manifest tests/scenarios/responsive-class-merge.yaml
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import { renderItemChildren } from '../RenderHelpers';
import { DataBindingEngine } from '../../DataBindingEngine';
import { TranslationEngine } from '../../TranslationEngine';

const Probe: React.FC<{ className?: string; children?: React.ReactNode }> = ({ className, children }) => (
  <span data-testid="probe" className={className}>
    {children}
  </span>
);

describe('renderItemChildren — 구간 classMerge·classMap', () => {
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;

  beforeEach(() => {
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
  });

  afterEach(() => {
    cleanup();
  });

  const renderAt = (width: number, children: any[], context: Record<string, any>) => {
    const nodes = renderItemChildren(children, context, { Probe }, 'cell', {
      translationContext: { templateId: 'test-template', locale: 'ko' },
      bindingEngine,
      translationEngine,
      actionDispatcher: { createHandler: () => vi.fn() } as any,
      responsiveWidth: width,
    } as any);
    return render(<>{nodes}</>);
  };

  const tokens = (el: HTMLElement) => (el.getAttribute('class') ?? '').split(/\s+/).filter(Boolean);

  /**
   * @scenario surface=cell_renderer
   * @effects class_merge_resolves_bindings_first
   */
  it('모바일 폭에서 셀 노드 classMerge 를 해석해 덧붙인다', () => {
    renderAt(
      390,
      [
        {
          id: 'c1',
          type: 'basic',
          name: 'Probe',
          props: { className: 'flex px-4 {{row.tone}}' },
          responsive: { mobile: { classMerge: "{{row.dense ? 'px-1' : 'px-2'}}" } },
          text: 'x',
        },
      ],
      { row: { tone: 'text-gray-700', dense: true } }
    );
    expect(tokens(screen.getByTestId('probe'))).toEqual(['flex', 'text-gray-700', 'px-1']);
  });

  /**
   * @scenario surface=cell_renderer
   * @effects class_merge_applies_only_in_matching_range
   */
  it('데스크톱 폭에서는 classMerge 를 적용하지 않는다', () => {
    renderAt(
      1440,
      [{ id: 'c2', type: 'basic', name: 'Probe', props: { className: 'flex px-4' }, responsive: { mobile: { classMerge: 'px-2' } }, text: 'x' }],
      {}
    );
    expect(tokens(screen.getByTestId('probe'))).toEqual(['flex', 'px-4']);
  });

  /**
   * @scenario surface=cell_renderer
   * @effects breakpoint_class_map_replaces_base_class_map
   */
  it('셀 노드 classMap 과 구간 classMap 을 적용한다', () => {
    const def = {
      id: 'c3',
      type: 'basic',
      name: 'Probe',
      props: { className: 'inline-flex' },
      classMap: { key: '{{row.status}}', variants: { paid: 'text-green-600' } },
      responsive: { mobile: { classMap: { key: '{{row.status}}', variants: { paid: 'text-green-800' } } } },
      text: 'x',
    };
    const desktop = renderAt(1440, [def], { row: { status: 'paid' } });
    expect(tokens(screen.getByTestId('probe'))).toEqual(['inline-flex', 'text-green-600']);
    desktop.unmount();
    renderAt(390, [def], { row: { status: 'paid' } });
    expect(tokens(screen.getByTestId('probe'))).toEqual(['inline-flex', 'text-green-800']);
  });

  /**
   * @scenario surface=cell_renderer
   * @effects class_merge_applies_once_per_item
   */
  it('반복 항목에도 같은 규칙으로 적용된다', () => {
    renderAt(
      390,
      [
        {
          id: 'c4',
          type: 'basic',
          name: 'Probe',
          iteration: { source: '{{rows}}', item_var: 'r' },
          props: { className: 'p-4 text-sm' },
          responsive: { mobile: { classMerge: 'p-2' } },
          text: '{{r}}',
        },
      ],
      { rows: ['a', 'b', 'c'] }
    );
    const probes = screen.getAllByTestId('probe');
    expect(probes).toHaveLength(3);
    for (const probe of probes) {
      expect(tokens(probe)).toEqual(['text-sm', 'p-2']);
    }
  });
});
