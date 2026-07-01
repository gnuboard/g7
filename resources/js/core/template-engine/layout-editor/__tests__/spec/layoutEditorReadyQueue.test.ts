/**
 * layoutEditorReadyQueue.test.ts — `G7Core.layoutEditor` 예약 접수함(ready 큐)
 *
 * 시나리오: 템플릿이 편집기 셸 로드 **전**(stub 상태)에 위젯을 등록하면 큐에 보존되고,
 * 편집기 셸 로드 시 `exposeLayoutEditorGlobals` 가 실제 레지스트리로 교체하면서 큐를
 * 일괄 flush 한다. ready 콜백도 호출된다.
 *
 * stub 자체는 메인 번들(G7CoreGlobals)에 있어 본 테스트에서 직접 import 하지 않고,
 * stub 형태를 그대로 모사(window.G7Core.layoutEditor 에 __isStub/__queue/__readyCallbacks)한 뒤
 * exposeLayoutEditorGlobals 가 그 큐를 회수·flush 하는지 검증한다.
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { exposeLayoutEditorGlobals } from '../../spec/exposeLayoutEditorGlobals';
import { getWidget, clearWidgetRegistry } from '../../spec/widgetRegistry';
import { getNodeEditor, clearNodeEditorRegistry } from '../../spec/nodeEditorRegistry';
import { getCanvasOverlay, clearCanvasOverlayRegistry } from '../../spec/canvasOverlayRegistry';

const DummyWidget = () => null;
const DummyNodeEditor = () => null;
const DummyOverlay = () => null;

/** 메인 번들 stub 모사 — initLayoutEditorStub 가 노출하는 형태와 동일 */
function installStub(): { queue: Array<[string, ...unknown[]]>; readyCalls: string[] } {
  const queue: Array<[string, ...unknown[]]> = [];
  const readyCallbacks: Array<() => void> = [];
  const readyCalls: string[] = [];
  (window as any).G7Core = (window as any).G7Core ?? {};
  (window as any).G7Core.layoutEditor = {
    __isStub: true,
    __queue: queue,
    __readyCallbacks: readyCallbacks,
    registerWidget: (name: string, comp: unknown) => queue.push(['widget', name, comp]),
    registerNodeEditor: (kind: string, comp: unknown) => queue.push(['nodeEditor', kind, comp]),
    registerCanvasOverlay: (kind: string, ov: unknown) => queue.push(['canvasOverlay', kind, ov]),
    onReady: (cb: () => void) => readyCallbacks.push(cb),
  };
  // 테스트가 ready 콜백 호출 여부를 추적하도록 헬퍼
  (window as any).__readyCalls = readyCalls;
  return { queue, readyCalls };
}

describe('G7Core.layoutEditor 예약 접수함(ready 큐)', () => {
  beforeEach(() => {
    clearWidgetRegistry();
    clearNodeEditorRegistry();
    clearCanvasOverlayRegistry();
    delete (window as any).G7Core;
  });

  it('stub 시절 등록(큐 적재) → expose 시 일괄 flush 되어 실제 레지스트리에 반영', () => {
    const { readyCalls } = installStub();
    const le = (window as any).G7Core.layoutEditor;
    // 편집기 로드 전 — stub 의 register* 는 큐에 적재만(실제 레지스트리는 아직 비어 있음)
    le.registerWidget('icon-picker', DummyWidget);
    le.registerNodeEditor('my-kind', DummyNodeEditor);
    le.registerCanvasOverlay('my-overlay', DummyOverlay);
    le.onReady(() => readyCalls.push('ready'));
    expect(getWidget('icon-picker')).toBeNull(); // flush 전이라 미반영

    // 편집기 셸 로드 — expose 가 실제 API 로 교체 + 큐 flush + ready 호출
    exposeLayoutEditorGlobals();

    expect(getWidget('icon-picker')).toBe(DummyWidget);
    expect(getNodeEditor('my-kind')).toBe(DummyNodeEditor);
    expect(getCanvasOverlay('my-overlay')).toBe(DummyOverlay);
    expect(readyCalls).toEqual(['ready']);
    // 교체 후 stub 표식 제거(실제 API)
    expect((window as any).G7Core.layoutEditor.__isStub).toBeUndefined();
  });

  it('expose 후 등록은 즉시 반영(큐 경유 아님)', () => {
    installStub();
    exposeLayoutEditorGlobals();
    const le = (window as any).G7Core.layoutEditor;
    le.registerWidget('icon-picker', DummyWidget);
    expect(getWidget('icon-picker')).toBe(DummyWidget);
  });

  it('expose 후 onReady 는 즉시 콜백 실행(이미 ready)', () => {
    installStub();
    exposeLayoutEditorGlobals();
    let called = false;
    (window as any).G7Core.layoutEditor.onReady(() => {
      called = true;
    });
    expect(called).toBe(true);
  });

  it('G7Core 부재 시 expose 는 no-op(예외 없음)', () => {
    delete (window as any).G7Core;
    expect(() => exposeLayoutEditorGlobals()).not.toThrow();
  });
});
