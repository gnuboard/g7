/**
 * DynamicRenderer.responsiveClassMerge.test.tsx
 *
 * responsive 구간 classMerge(덧붙이기)와 구간 classMap 적용 (engine-v1.98.0)
 *
 * 1. 구간 classMerge 가 기본 className 에 충돌 교체형으로 덧붙는다 (해당 폭에서만)
 * 2. 바인딩을 해석한 뒤 합친다 (className·classMerge 양쪽)
 * 3. 구간 props.className 이 있으면 그 위에 덧붙인다
 * 4. 구간 classMap 이 기본 classMap 을 대신한다 (종전에는 무시돼 클래스가 빠졌다)
 * 5. classMap 결과 뒤에 classMerge 가 적용된다
 * 6. 반복 노드에서도 한 번만 적용된다 (토큰 중복 없음)
 * 7. 확장 앞글자(ecommerce:) 토큰도 같은 축으로 교체한다
 *
 * @scenario-manifest tests/scenarios/responsive-class-merge.yaml
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import * as ResponsiveContextModule from '../ResponsiveContext';

// ResponsiveManager mock
vi.mock('../ResponsiveManager', () => {
  const BREAKPOINT_PRESETS: Record<string, { min: number; max: number }> = {
    mobile: { min: 0, max: 767 },
    tablet: { min: 768, max: 1023 },
    desktop: { min: 1024, max: Infinity },
  };

  const parseRange = (key: string): { min: number; max: number } | null => {
    if (BREAKPOINT_PRESETS[key]) {
      return { ...BREAKPOINT_PRESETS[key] };
    }

    const rangeMatch = key.match(/^(-?\d*)-(-?\d*)$/);
    if (rangeMatch) {
      const [, minStr, maxStr] = rangeMatch;
      const min = minStr === '' ? 0 : parseInt(minStr, 10);
      const max = maxStr === '' ? Infinity : parseInt(maxStr, 10);

      if (!isNaN(min) && !isNaN(max) && min <= max) {
        return { min, max };
      }
    }

    return null;
  };

  const getMatchingKey = (
    responsive: Record<string, any>,
    width: number
  ): string | null => {
    const matchedKeys: Array<{
      key: string;
      range: { min: number; max: number };
      isPreset: boolean;
    }> = [];

    for (const key of Object.keys(responsive)) {
      const range = parseRange(key);
      if (!range) continue;

      if (width >= range.min && width <= range.max) {
        matchedKeys.push({
          key,
          range,
          isPreset: !!BREAKPOINT_PRESETS[key],
        });
      }
    }

    if (matchedKeys.length === 0) return null;

    matchedKeys.sort((a, b) => {
      if (a.isPreset !== b.isPreset) {
        return a.isPreset ? 1 : -1;
      }
      const aWidth = a.range.max - a.range.min;
      const bWidth = b.range.max - b.range.min;
      return aWidth - bWidth;
    });

    return matchedKeys[0].key;
  };

  return {
    responsiveManager: {
      getWidth: vi.fn(() => 1024),
      subscribe: vi.fn(() => () => {}),
      getMatchingKey,
      parseRange,
    },
  };
});

// 테스트용 컴포넌트
const TestButton: React.FC<{
  text?: string;
  children?: React.ReactNode;
  className?: string;
  variant?: string;
  size?: string;
}> = ({ text, children, className, variant, size }) => (
  <button
    data-testid="test-button"
    className={className}
    data-variant={variant}
    data-size={size}
  >
    {children || text}
  </button>
);

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
}> = ({ className, children }) => (
  <div data-testid="test-div" className={className}>
    {children}
  </div>
);

const TestCard: React.FC<{
  title?: string;
  children?: React.ReactNode;
}> = ({ title, children }) => (
  <div data-testid="card">
    {title && <h2 data-testid="card-title">{title}</h2>}
    {children}
  </div>
);


describe('DynamicRenderer - responsive classMerge·구간 classMap (engine-v1.98.0)', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  const translationContext: TranslationContext = { templateId: 'test-template', locale: 'ko' };

  const setWidth = (width: number) => {
    vi.spyOn(ResponsiveContextModule, 'useResponsive').mockReturnValue({
      width,
      isMobile: width < 768,
      isTablet: width >= 768 && width < 1024,
      isDesktop: width >= 1024,
      matchedPreset: width < 768 ? 'mobile' : width < 1024 ? 'tablet' : 'desktop',
    } as any);
  };

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
      Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
      Card: { component: TestCard, metadata: { name: 'Card', type: 'composite' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    setWidth(1024);
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    delete (window as any).G7Config;
  });

  const renderDef = (componentDef: ComponentDefinition, dataContext: Record<string, any> = {}) =>
    render(
      <DynamicRenderer
        componentDef={componentDef}
        dataContext={dataContext}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />
    );

  const classTokens = (el: HTMLElement) => (el.getAttribute('class') ?? '').split(/\s+/).filter(Boolean);

  /**
   * @scenario surface=renderer
   * @effects class_merge_replaces_same_axis_and_keeps_rest
   */
  it('모바일 폭에서 구간 classMerge 가 같은 축 토큰만 교체하고 나머지는 남긴다', () => {
    setWidth(390);
    renderDef({
      id: 'cm-1',
      type: 'basic',
      name: 'Div',
      props: { className: 'flex p-8 text-sm bg-white' },
      responsive: { mobile: { classMerge: 'p-6' } },
    });
    expect(classTokens(screen.getByTestId('test-div'))).toEqual(['flex', 'text-sm', 'bg-white', 'p-6']);
  });

  /**
   * @scenario surface=renderer
   * @effects class_merge_applies_only_in_matching_range
   */
  it('구간에 맞지 않는 폭에서는 기본 className 그대로다', () => {
    setWidth(1440);
    renderDef({
      id: 'cm-2',
      type: 'basic',
      name: 'Div',
      props: { className: 'flex p-8' },
      responsive: { mobile: { classMerge: 'p-6' } },
    });
    expect(classTokens(screen.getByTestId('test-div'))).toEqual(['flex', 'p-8']);
  });

  /**
   * @scenario surface=renderer
   * @effects class_merge_resolves_bindings_first
   */
  it('className 과 classMerge 의 바인딩을 해석한 뒤 합친다', () => {
    setWidth(390);
    renderDef(
      {
        id: 'cm-3',
        type: 'basic',
        name: 'Div',
        props: { className: '{{tone}} p-4 rounded-lg' },
        responsive: { mobile: { classMerge: "{{wide ? 'p-8' : 'p-6'}} rounded-none" } },
      },
      { tone: 'bg-gray-50', wide: false }
    );
    expect(classTokens(screen.getByTestId('test-div'))).toEqual(['bg-gray-50', 'p-6', 'rounded-none']);
  });

  /**
   * @scenario surface=renderer
   * @effects class_merge_replaces_same_axis_and_keeps_rest
   */
  it('구간 props.className 이 있으면 그 위에 덧붙인다', () => {
    setWidth(390);
    renderDef({
      id: 'cm-4',
      type: 'basic',
      name: 'Div',
      props: { className: 'flex p-8' },
      responsive: { mobile: { props: { className: 'grid p-2' }, classMerge: 'gap-4 p-3' } },
    });
    expect(classTokens(screen.getByTestId('test-div'))).toEqual(['grid', 'gap-4', 'p-3']);
  });

  /**
   * @scenario surface=renderer
   * @effects breakpoint_class_map_replaces_base_class_map
   */
  it('구간 classMap 이 기본 classMap 을 대신한다 (종전에는 무시됐다)', () => {
    setWidth(390);
    renderDef(
      {
        id: 'cm-5',
        type: 'basic',
        name: 'Div',
        props: { className: 'block' },
        classMap: { key: '{{status}}', variants: { done: 'text-green-600' }, default: 'text-gray-500' },
        responsive: {
          mobile: { classMap: { base: 'font-bold', key: '{{status}}', variants: { done: 'text-green-800' } } },
        },
      },
      { status: 'done' }
    );
    const tokens = classTokens(screen.getByTestId('test-div'));
    expect(tokens).toContain('font-bold');
    expect(tokens).toContain('text-green-800');
    expect(tokens).not.toContain('text-green-600');
  });

  /**
   * @scenario surface=renderer
   * @effects breakpoint_class_map_replaces_base_class_map
   */
  it('classMap 결과 뒤에 classMerge 가 적용된다', () => {
    setWidth(390);
    renderDef(
      {
        id: 'cm-6',
        type: 'basic',
        name: 'Div',
        props: { className: 'block' },
        classMap: { key: '{{status}}', variants: { done: 'text-green-600' } },
        responsive: { mobile: { classMerge: 'text-blue-600' } },
      },
      { status: 'done' }
    );
    const tokens = classTokens(screen.getByTestId('test-div'));
    expect(tokens).toContain('text-blue-600');
    expect(tokens).not.toContain('text-green-600');
  });

  /**
   * @scenario surface=renderer
   * @effects class_merge_applies_once_per_item
   */
  it('반복 노드의 각 항목에 한 번만 적용된다', () => {
    setWidth(390);
    renderDef(
      {
        id: 'cm-7',
        type: 'basic',
        name: 'Button',
        props: { className: 'btn p-4 admin-card' },
        iteration: { source: 'items', item_var: 'item' },
        text: '{{item}}',
        responsive: { mobile: { classMerge: 'p-2 admin-card' } },
      },
      { items: ['a', 'b'] }
    );
    const buttons = screen.getAllByTestId('test-button');
    expect(buttons).toHaveLength(2);
    for (const button of buttons) {
      expect(classTokens(button)).toEqual(['btn', 'p-2', 'admin-card']);
    }
  });

  /**
   * @scenario surface=renderer
   * @effects extension_prefix_tokens_merge_on_same_axis
   */
  it('확장 앞글자 토큰도 같은 축으로 교체한다', () => {
    (window as any).G7Config = { extensionClassPrefixes: ['ecommerce'] };
    setWidth(390);
    renderDef({
      id: 'cm-8',
      type: 'basic',
      name: 'Div',
      props: { className: 'ecommerce:flex ecommerce:p-4 ecommerce-admin-card' },
      responsive: { mobile: { classMerge: 'ecommerce:p-6' } },
    });
    expect(classTokens(screen.getByTestId('test-div'))).toEqual(['ecommerce:flex', 'ecommerce-admin-card', 'ecommerce:p-6']);
  });

  /**
   * @scenario surface=renderer
   * @effects class_merge_resolves_bindings_first
   */
  it('평가에 실패한 classMerge 식의 원문 조각은 클래스로 붙지 않는다', () => {
    setWidth(390);
    renderDef({
      id: 'cm-10',
      type: 'basic',
      name: 'Div',
      props: { className: 'flex p-4' },
      responsive: { mobile: { classMerge: "p-6 {{missing.deep.call() ? 'a' : 'b'}}" } },
    });
    const tokens = classTokens(screen.getByTestId('test-div'));
    expect(tokens).toContain('p-6');
    expect(tokens.some((t) => t.includes('{{') || t.includes('}}'))).toBe(false);
  });

  /**
   * @scenario surface=renderer
   * @effects class_merge_replaces_same_axis_and_keeps_rest
   */
  it('classMerge 는 DOM 속성으로 새지 않는다', () => {
    setWidth(390);
    renderDef({
      id: 'cm-9',
      type: 'basic',
      name: 'Div',
      props: { className: 'flex' },
      responsive: { mobile: { classMerge: 'gap-2' } },
    });
    const div = screen.getByTestId('test-div');
    expect(div.getAttribute('classmerge')).toBeNull();
    expect(div.getAttribute('class-merge')).toBeNull();
  });
});
