/**
 * useResizeHandles.test.ts — 모서리 드래그 리사이즈
 *
 * 검증:
 *  - enabledAxes — width/height 컨트롤 선언 여부
 *  - styleProp 연속 px 적용 (scale 보정)
 *  - select/classToken 옵션 단계 스냅
 *  - min/max 클램프
 *  - 미선언 축 핸들 pointerdown 무시
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useResizeHandles } from '../../hooks/useResizeHandles';
import type { EditorControlSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const widthStyleProp: EditorControlSpec = { widget: 'select', apply: { type: 'styleProp', prop: 'width' } };
const heightStyleProp: EditorControlSpec = { widget: 'select', apply: { type: 'styleProp', prop: 'height' } };

function dispatchPointer(type: string, x: number, y: number): void {
  const ev = new Event(type) as PointerEvent & { clientX: number; clientY: number };
  Object.defineProperty(ev, 'clientX', { value: x });
  Object.defineProperty(ev, 'clientY', { value: y });
  window.dispatchEvent(ev);
}

describe('useResizeHandles — enabledAxes', () => {
  it('width/height 컨트롤 선언 여부로 축 활성', () => {
    const { result } = renderHook(() =>
      useResizeHandles({
        node: { name: 'Div' },
        widthControl: widthStyleProp,
        heightControl: null,
        scale: 1,
        onResize: vi.fn(),
      }),
    );
    expect(result.current.enabledAxes).toEqual({ width: true, height: false });
  });
});

describe('useResizeHandles — styleProp 연속 px (scale 보정)', () => {
  let node: EditorNode;
  let onResize: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    node = { name: 'Div', props: { style: { width: '100px', height: '50px' } } };
    onResize = vi.fn();
  });

  it('e 핸들 드래그 → width 가 델타/scale 만큼 증가 (px)', () => {
    const { result } = renderHook(() =>
      useResizeHandles({
        node,
        widthControl: widthStyleProp,
        heightControl: heightStyleProp,
        scale: 2, // scale 2 → 화면 델타 100 = 실제 50
        onResize,
      }),
    );
    act(() => {
      result.current.onHandlePointerDown('e', { clientX: 0, clientY: 0 });
      dispatchPointer('pointermove', 100, 0);
      dispatchPointer('pointerup', 100, 0);
    });
    // 100px(start) + 100/scale(=50) = 150px
    const lastCall = onResize.mock.calls.at(-1);
    expect(lastCall).toBeTruthy();
    const patched = lastCall![0] as EditorNode;
    expect((patched.props?.style as Record<string, unknown>).width).toBe('150px');
  });

  it('s 핸들 드래그 → height 만 변경, width 불변', () => {
    const { result } = renderHook(() =>
      useResizeHandles({ node, widthControl: widthStyleProp, heightControl: heightStyleProp, scale: 1, onResize }),
    );
    act(() => {
      result.current.onHandlePointerDown('s', { clientX: 0, clientY: 0 });
      dispatchPointer('pointermove', 0, 30);
      dispatchPointer('pointerup', 0, 30);
    });
    const patched = onResize.mock.calls.at(-1)![0] as EditorNode;
    const style = patched.props?.style as Record<string, unknown>;
    expect(style.height).toBe('80px'); // 50 + 30
    expect(style.width).toBe('100px'); // 불변
  });
});

describe('useResizeHandles — 옵션 스냅 / 클램프 / 미선언 축', () => {
  it('select 옵션 컨트롤 — 목표 px 에 가장 가까운 옵션 value 로 스냅', () => {
    const onResize = vi.fn();
    const widthSelect: EditorControlSpec = {
      widget: 'select',
      apply: { type: 'classToken', tokens: [] },
      options: [
        { value: 's', apply: { type: 'classToken', tokens: ['w-32'] } }, // 가상 px 0
        { value: 'm', apply: { type: 'classToken', tokens: ['w-64'] } }, // 가상 px 40
        { value: 'l', apply: { type: 'classToken', tokens: ['w-96'] } }, // 가상 px 80
      ],
    };
    const { result } = renderHook(() =>
      useResizeHandles({
        node: { name: 'Div', props: { style: {} } },
        widthControl: widthSelect,
        heightControl: null,
        scale: 1,
        onResize,
      }),
    );
    act(() => {
      result.current.onHandlePointerDown('e', { clientX: 0, clientY: 0 });
      dispatchPointer('pointermove', 75, 0); // 가상 px 75 → 'l'(80)에 가장 가까움
      dispatchPointer('pointerup', 75, 0);
    });
    expect(onResize).toHaveBeenCalled();
  });

  it('min/max 클램프 — styleProp width 가 max 를 넘지 않는다', () => {
    const onResize = vi.fn();
    const clamped: EditorControlSpec = {
      widget: 'select',
      apply: { type: 'styleProp', prop: 'width' },
      max: 120,
    } as EditorControlSpec;
    const { result } = renderHook(() =>
      useResizeHandles({
        node: { name: 'Div', props: { style: { width: '100px' } } },
        widthControl: clamped,
        heightControl: null,
        scale: 1,
        onResize,
      }),
    );
    act(() => {
      result.current.onHandlePointerDown('e', { clientX: 0, clientY: 0 });
      dispatchPointer('pointermove', 500, 0); // 100 + 500 = 600 → max 120 클램프
      dispatchPointer('pointerup', 500, 0);
    });
    const patched = onResize.mock.calls.at(-1)![0] as EditorNode;
    expect((patched.props?.style as Record<string, unknown>).width).toBe('120px');
  });

  it('미선언 축(height 없음)에서 n 핸들 pointerdown 은 무시', () => {
    const onResize = vi.fn();
    const { result } = renderHook(() =>
      useResizeHandles({
        node: { name: 'Div' },
        widthControl: widthStyleProp,
        heightControl: null, // height 미선언
        scale: 1,
        onResize,
      }),
    );
    act(() => {
      result.current.onHandlePointerDown('n', { clientX: 0, clientY: 0 }); // n = 세로 전용
      dispatchPointer('pointermove', 0, 30);
      dispatchPointer('pointerup', 0, 30);
    });
    expect(onResize).not.toHaveBeenCalled();
  });
});

// ============================================================================
// dimension 컨트롤(options + control-level apply)에서 리사이즈가
// 실제 style.width 를 갱신해야 한다.
//
// 4단계 회귀 가드: 이 테스트는 recipeEngine 의 control-level apply 폴백 수정 전에는
// FAIL 했다 — applyRecipe 가 options 존재 시 옵션 apply(부재)만 보고 control-level
// styleProp 을 무시해 패치 결과 노드의 style.width 가 갱신되지 않았다(리사이즈 무반응).
// 폴백 수정 후 px 가 정상 적용된다. (editor-spec width/height = dimension 위젯 형태)
// ============================================================================
describe('useResizeHandles — dimension 컨트롤(options + control-level apply) 리사이즈', () => {
  const dimensionWidth: EditorControlSpec = {
    widget: 'dimension',
    group: 'width',
    apply: { type: 'styleProp', prop: 'width' },
    options: [{ value: '100%' }, { value: '50%' }],
  };

  it('e 핸들 드래그 → onResize 패치 노드의 style.width 가 px 로 갱신', () => {
    const node: EditorNode = { name: 'Button', props: { style: { width: '100px' } } };
    const onResize = vi.fn();
    const { result } = renderHook(() =>
      useResizeHandles({
        node,
        widthControl: dimensionWidth,
        heightControl: null,
        scale: 1,
        onResize,
      }),
    );
    act(() => {
      result.current.onHandlePointerDown('e', { clientX: 0, clientY: 0 });
      dispatchPointer('pointermove', 48, 0); // +48px 가로
      dispatchPointer('pointerup', 48, 0);
    });
    expect(onResize).toHaveBeenCalled();
    const patched = onResize.mock.calls.at(-1)![0] as EditorNode;
    expect((patched.props?.style as Record<string, unknown>)?.width).toBe('148px');
  });
});

// ============================================================================
// 리사이즈가 0px 가 아닌 현재 렌더 크기에서 시작해야 한다.
//
// 4단계 회귀 가드: 수정 전에는 style.width 가 없는 요소(대부분)에서 startW=0 →
// 드래그가 0px 부터 시작해 크기가 갑자기 줄어들었다. measureStartSize 가 현재
// 렌더 크기를 공급하면 그 값을 시작점으로 쓴다(명시 style px 가 있으면 그게 우선).
// ============================================================================
describe('useResizeHandles — 시작 크기는 실측 현재 크기', () => {
  it('style.width 없음 + measureStartSize=116 → 드래그 시작이 116 기준(0 아님)', () => {
    const onResize = vi.fn();
    const node: EditorNode = { name: 'Button' }; // style.width 없음
    const dimensionWidth: EditorControlSpec = {
      widget: 'dimension', group: 'width', apply: { type: 'styleProp', prop: 'width' },
      options: [{ value: '100%' }],
    };
    const { result } = renderHook(() =>
      useResizeHandles({
        node,
        widthControl: dimensionWidth,
        heightControl: null,
        scale: 1,
        measureStartSize: () => ({ width: 116, height: 36 }),
        onResize,
      }),
    );
    act(() => {
      result.current.onHandlePointerDown('e', { clientX: 0, clientY: 0 });
      dispatchPointer('pointermove', 40, 0); // +40px
      dispatchPointer('pointerup', 40, 0);
    });
    expect(onResize).toHaveBeenCalled();
    const patched = onResize.mock.calls.at(-1)![0] as EditorNode;
    // 116 + 40 = 156px (0 + 40 = 40px 가 아님)
    expect((patched.props?.style as Record<string, unknown>)?.width).toBe('156px');
  });

  it('style.width 명시 px 가 있으면 그것이 우선(measure 무시)', () => {
    const onResize = vi.fn();
    const node: EditorNode = { name: 'Div', props: { style: { width: '200px' } } };
    const ctrl: EditorControlSpec = { widget: 'dimension', apply: { type: 'styleProp', prop: 'width' } };
    const { result } = renderHook(() =>
      useResizeHandles({
        node, widthControl: ctrl, heightControl: null, scale: 1,
        measureStartSize: () => ({ width: 999, height: 0 }), // 무시되어야 함
        onResize,
      }),
    );
    act(() => {
      result.current.onHandlePointerDown('e', { clientX: 0, clientY: 0 });
      dispatchPointer('pointermove', 30, 0);
      dispatchPointer('pointerup', 30, 0);
    });
    const patched = onResize.mock.calls.at(-1)![0] as EditorNode;
    expect((patched.props?.style as Record<string, unknown>)?.width).toBe('230px'); // 200+30
  });
});

// ============================================================================
// 리사이즈 결과가 undo 스택에 쌓이도록 onResizeEnd(pointerup) 1회 발화.
//
// 4단계 회귀 가드: 수정 전 useResizeHandles 는 onResize(move 마다)만 호출하고
// 종료 콜백이 없어, 호출처(EditorCanvasOverlay)가 history.push 할 시점이 없었다 →
// 리사이즈 후 Ctrl+Z(뒤로가기) 가 동작하지 않았다("수정 사항이 스택에 안 쌓임").
// onResizeEnd 는 pointerup 시 최종 노드로 1회만 발화하고, 델타 0(핸들만 누르고
// 안 움직임)이면 발화하지 않는다.
// ============================================================================
describe('useResizeHandles — onResizeEnd 로 종료 시 1회 발화', () => {
  it('드래그 후 pointerup → onResizeEnd 1회 + 최종 노드 전달', () => {
    const node: EditorNode = { name: 'Div', props: { style: { width: '100px' } } };
    const onResize = vi.fn();
    const onResizeEnd = vi.fn();
    const { result } = renderHook(() =>
      useResizeHandles({
        node, widthControl: widthStyleProp, heightControl: heightStyleProp, scale: 1,
        onResize, onResizeEnd,
      }),
    );
    act(() => {
      result.current.onHandlePointerDown('e', { clientX: 0, clientY: 0 });
      dispatchPointer('pointermove', 20, 0);
      dispatchPointer('pointermove', 50, 0);
      dispatchPointer('pointerup', 50, 0);
    });
    // move 는 여러 번, end 는 정확히 1회
    expect(onResize.mock.calls.length).toBeGreaterThanOrEqual(2);
    expect(onResizeEnd).toHaveBeenCalledTimes(1);
    const [patched, axis] = onResizeEnd.mock.calls[0]!;
    expect((patched.props?.style as Record<string, unknown>).width).toBe('150px'); // 100+50
    expect(axis).toBe('width');
  });

  it('핸들만 누르고 움직이지 않으면 onResizeEnd 미발화(델타 0)', () => {
    const node: EditorNode = { name: 'Div', props: { style: { width: '100px' } } };
    const onResizeEnd = vi.fn();
    const { result } = renderHook(() =>
      useResizeHandles({
        node, widthControl: widthStyleProp, heightControl: null, scale: 1,
        onResize: vi.fn(), onResizeEnd,
      }),
    );
    act(() => {
      result.current.onHandlePointerDown('e', { clientX: 0, clientY: 0 });
      dispatchPointer('pointerup', 0, 0); // move 없음
    });
    expect(onResizeEnd).not.toHaveBeenCalled();
  });
});
