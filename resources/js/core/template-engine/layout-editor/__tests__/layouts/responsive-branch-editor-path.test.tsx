/**
 * responsive-branch-editor-path — 디바이스 분기(responsive 자식 교체) 노드의
 * 편집 path 발급 렌더링 회귀.
 *
 * 결함: 모바일 보기에서 분기 children 으로 교체된 자식을 클릭해도 같은 인덱스의
 * base 분기 노드가 선택됐다(분기 출처가 path 에 없었음). DynamicRenderer 가 분기
 * children 을 렌더할 때 자식 path 에 `.responsive.{key}.children` prefix 를 끼우면
 * 편집기가 보이는 분기 노드를 정확히 가리킨다.
 *
 * 검증:
 *  (모바일) 분기 자식 DOM 의 data-editor-path = `0.responsive.portable.children.{N}`
 *  (데스크톱) base 자식 DOM 의 data-editor-path = `0.children.{N}` (무손실 회귀)
 *  (props-only override) 자식 교체가 없으면 base path 유지
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import DynamicRenderer from '../../../DynamicRenderer';
import { ComponentRegistry } from '../../../ComponentRegistry';
import { DataBindingEngine } from '../../../DataBindingEngine';
import { TranslationEngine } from '../../../TranslationEngine';
import { ActionDispatcher } from '../../../ActionDispatcher';
import * as ResponsiveContextModule from '../../../ResponsiveContext';

function makeRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.createIsolatedInstance();
  // basic Div: {...props} 패스스루 → data-editor-* 표식이 DOM 에 도달.
  (registry as any).registry.Div = {
    component: (props: any) => React.createElement('div', props),
    metadata: { name: 'Div', type: 'basic' },
  };
  (registry as any).registry.Span = {
    component: (props: any) => React.createElement('span', props),
    metadata: { name: 'Span', type: 'basic' },
  };
  return registry;
}

function setWidth(width: number) {
  vi.spyOn(ResponsiveContextModule, 'useResponsive').mockReturnValue({
    width,
    isMobile: width < 768,
    isTablet: width >= 768 && width < 1024,
    isDesktop: width >= 1024,
    matchedPreset: width < 768 ? 'mobile' : width < 1024 ? 'tablet' : 'desktop',
  } as any);
}

function renderDef(def: any) {
  return render(
    React.createElement(DynamicRenderer, {
      componentDef: def,
      dataContext: {},
      registry: makeRegistry(),
      bindingEngine: new DataBindingEngine(),
      translationEngine: new TranslationEngine(),
      actionDispatcher: new ActionDispatcher({}),
      isEditMode: true,
      isRootRenderer: true,
      componentPath: '0',
      onComponentSelect: () => {},
      onComponentHover: () => {},
    } as any),
  );
}

// children 완전 교체형 — base 에 SpanA, portable(0-599) 에 SpanM0/SpanM1.
function branchDef() {
  return {
    name: 'Div',
    type: 'basic',
    props: {},
    children: [{ name: 'Span', type: 'basic', text: 'base-A', props: { 'data-tag': 'base-A' } }],
    responsive: {
      'portable': {
        children: [
          { name: 'Span', type: 'basic', text: 'm0', props: { 'data-tag': 'm0' } },
          { name: 'Span', type: 'basic', text: 'm1', props: { 'data-tag': 'm1' } },
        ],
      },
    },
  };
}

describe('responsive 분기 자식 — 편집 path prefix', () => {
  beforeEach(() => setWidth(1024));
  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
  });

  it('(데스크톱) base 자식 → data-editor-path 는 number-only(무손실 회귀)', () => {
    setWidth(1280);
    const { container } = renderDef(branchDef());
    const base = container.querySelector('[data-tag="base-A"]') as HTMLElement;
    expect(base).not.toBeNull();
    expect(base.getAttribute('data-editor-path')).toBe('0.children.0');
  });

  it('(모바일) 분기 자식 → data-editor-path 에 responsive.portable prefix', () => {
    // portable = 0-599 커스텀 범위로 가정 — 폭 400 매칭.
    setWidth(400);
    const { container } = renderDef(branchDef());
    const m0 = container.querySelector('[data-tag="m0"]') as HTMLElement;
    const m1 = container.querySelector('[data-tag="m1"]') as HTMLElement;
    expect(m0).not.toBeNull();
    expect(m1).not.toBeNull();
    expect(m0.getAttribute('data-editor-path')).toBe('0.responsive.portable.children.0');
    expect(m1.getAttribute('data-editor-path')).toBe('0.responsive.portable.children.1');
    // base 자식은 모바일 보기에서 렌더되지 않는다(완전 교체).
    expect(container.querySelector('[data-tag="base-A"]')).toBeNull();
  });

  it('(props-only override) 자식 교체 없으면 base path 유지', () => {
    setWidth(400);
    const def = {
      name: 'Div',
      type: 'basic',
      props: { className: 'base' },
      children: [{ name: 'Span', type: 'basic', text: 'A', props: { 'data-tag': 'only-A' } }],
      responsive: {
        // children 미지정 — props 만 오버라이드.
        'portable': { props: { className: 'mobile' } },
      },
    };
    const { container } = renderDef(def);
    const a = container.querySelector('[data-tag="only-A"]') as HTMLElement;
    expect(a).not.toBeNull();
    // 자식 교체가 없으므로 base children path 그대로.
    expect(a.getAttribute('data-editor-path')).toBe('0.children.0');
  });
});
