/**
 * resize-handle-canvas.test.tsx — 캔버스 모서리 리사이즈 ↔ 속성 모달 양방향 동기
 *
 *  의 두 번째 레이아웃 렌더링 테스트. 캔버스 리사이즈 핸들 드래그가
 * width/height 컨트롤 레시피로 노드를 패치하고, 그 패치된 노드를 실제 DynamicRenderer 가
 * 렌더하면 DOM 크기가 변하며, 같은 레시피를 쓰는 속성 모달 컨트롤의 현재값도
 * 양방향으로 동기되는지 검증한다(같은 width/height 컨트롤 공유).
 *
 * useResizeHandles 단위 테스트(hooks/)가 델타→patch 변환을 다룬다면, 본 테스트는
 * "리사이즈 결과 노드가 실제 캔버스에 렌더되고 + 모달 컨트롤이 같은 값을 역해석한다"는
 * 통합 라운드트립을 다룬다. DynamicRenderer 는 실제 API(componentDef + 엔진 주입)로 렌더.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, cleanup, act, renderHook } from '@testing-library/react';
import DynamicRenderer from '../../../DynamicRenderer';
import { ComponentRegistry } from '../../../ComponentRegistry';
import { DataBindingEngine } from '../../../DataBindingEngine';
import { TranslationEngine } from '../../../TranslationEngine';
import { ActionDispatcher } from '../../../ActionDispatcher';
import { useResizeHandles } from '../../hooks/useResizeHandles';
import { reverseResolve } from '../../spec/recipeEngine';
import type { EditorControlSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const widthControl: EditorControlSpec = { widget: 'select', apply: { type: 'styleProp', prop: 'width' } };
const heightControl: EditorControlSpec = { widget: 'select', apply: { type: 'styleProp', prop: 'height' } };

afterEach(() => cleanup());

function dispatchPointer(type: string, x: number, y: number): void {
  const ev = new Event(type) as PointerEvent & { clientX: number; clientY: number };
  Object.defineProperty(ev, 'clientX', { value: x });
  Object.defineProperty(ev, 'clientY', { value: y });
  window.dispatchEvent(ev);
}

/** Div 를 props 패스스루로 렌더하는 격리 레지스트리 */
function makeRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.createIsolatedInstance();
  (registry as unknown as { registry: Record<string, unknown> }).registry.Div = {
    component: (props: Record<string, unknown>) => React.createElement('div', props),
    metadata: { name: 'Div', type: 'basic' },
  };
  return registry;
}

/** 패치된 노드를 실제 DynamicRenderer 로 캔버스에 렌더 */
function renderCanvas(node: EditorNode) {
  return render(
    React.createElement(DynamicRenderer, {
      componentDef: { name: 'Div', type: 'basic', props: node.props ?? {} },
      dataContext: {},
      registry: makeRegistry(),
      bindingEngine: new DataBindingEngine(),
      translationEngine: new TranslationEngine(),
      actionDispatcher: new ActionDispatcher({}),
      isEditMode: false,
      isRootRenderer: true,
      componentPath: '0',
    } as never),
  );
}

describe('캔버스 리사이즈 → 렌더 + 모달 양방향 동기 ', () => {
  it('e 핸들 드래그 결과 노드가 캔버스에 변경된 width 로 렌더된다', () => {
    let patched: EditorNode = { name: 'Div', props: { style: { width: '100px', height: '50px' } } };
    const { result } = renderHook(() =>
      useResizeHandles({
        node: patched,
        widthControl,
        heightControl,
        scale: 1,
        onResize: (n) => {
          patched = n;
        },
      }),
    );
    act(() => {
      result.current.onHandlePointerDown('e', { clientX: 0, clientY: 0 });
      dispatchPointer('pointermove', 60, 0);
      dispatchPointer('pointerup', 60, 0);
    });
    const { container } = renderCanvas(patched);
    const el = container.querySelector('[style*="width"]') as HTMLElement | null;
    expect(el).toBeTruthy();
    // 100 + 60 = 160px
    expect((el!.getAttribute('style') ?? '').replace(/\s/g, '')).toContain('width:160px');
  });

  it('리사이즈 결과 width 를 속성 모달 width 컨트롤이 같은 값으로 역해석한다 (양방향 동기)', () => {
    let patched: EditorNode = { name: 'Div', props: { style: { width: '120px' } } };
    const { result } = renderHook(() =>
      useResizeHandles({
        node: patched,
        widthControl,
        heightControl: null,
        scale: 1,
        onResize: (n) => {
          patched = n;
        },
      }),
    );
    act(() => {
      result.current.onHandlePointerDown('e', { clientX: 0, clientY: 0 });
      dispatchPointer('pointermove', 30, 0);
      dispatchPointer('pointerup', 30, 0);
    });
    const resolved = reverseResolve(patched, widthControl);
    expect(resolved.matched).toBe(true);
    expect(resolved.value).toBe('150px'); // 120 + 30
  });

  it('캔버스 노드의 현재 크기를 width 컨트롤이 역해석해 표시할 수 있다 (모달←캔버스 방향)', () => {
    // 캔버스에 width 150px 인 노드 → 같은 레시피로 역해석 시 모달 컨트롤이 그 값을 받는다
    const node: EditorNode = { name: 'Div', props: { style: { width: '150px' } } };
    const { container } = renderCanvas(node);
    const el = container.querySelector('[style*="width"]') as HTMLElement | null;
    expect(el).toBeTruthy();
    expect((el!.getAttribute('style') ?? '').replace(/\s/g, '')).toContain('width:150px');
    // 모달 컨트롤이 같은 노드에서 역해석하는 값과 캔버스 렌더값이 일치
    expect(reverseResolve(node, widthControl)).toEqual({ value: '150px', matched: true });
  });

  it('height 미선언 축의 n 핸들 드래그는 렌더 결과를 바꾸지 않는다 (축 게이팅)', () => {
    let patched: EditorNode = { name: 'Div', props: { style: { width: '100px' } } };
    const onResize = vi.fn((n: EditorNode) => {
      patched = n;
    });
    const { result } = renderHook(() =>
      useResizeHandles({ node: patched, widthControl, heightControl: null, scale: 1, onResize }),
    );
    act(() => {
      result.current.onHandlePointerDown('n', { clientX: 0, clientY: 0 }); // 세로 전용 핸들
      dispatchPointer('pointermove', 0, 40);
      dispatchPointer('pointerup', 0, 40);
    });
    expect(onResize).not.toHaveBeenCalled();
    expect((patched.props?.style as Record<string, unknown>).width).toBe('100px'); // 불변
  });

  it('enabledAxes 가 스펙 선언을 반영한다 (핸들 표시 게이팅 근거)', () => {
    const { result } = renderHook(() =>
      useResizeHandles({ node: { name: 'Div' }, widthControl, heightControl: null, scale: 1, onResize: vi.fn() }),
    );
    expect(result.current.enabledAxes).toEqual({ width: true, height: false });
  });
});
