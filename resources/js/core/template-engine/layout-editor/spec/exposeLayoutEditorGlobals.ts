/**
 * exposeLayoutEditorGlobals.ts — `G7Core.layoutEditor` 확장점 노출
 *
 * 템플릿이 편집기 부트스트랩(initTemplate 류)에서 커스텀 속성 위젯·노드 에디터·캔버스
 * 인플레이스 오버레이를 등록할 수 있도록 코어 레지스트리 API 를 `window.G7Core.layoutEditor`
 * 로 노출한다. 코어 위젯/모달은 위젯명·kind 만 디스패치하고(원칙 4.1 — 코어는 메커니즘만),
 * 등록된 핸들러를 레지스트리에서 찾아 렌더한다.
 *
 * 노출 시점 = 편집기 셸(LayoutEditorChrome) 모듈 로드 시 1회. 편집기 번들은 lazy 로드라
 * 본 노출도 편집기가 열릴 때만 발생한다(메인 앱 번들 비대화 회피).
 *
 * **예약 접수함(ready 큐) 연동**: 메인 번들의 `initLayoutEditorStub` 이
 * 템플릿 `initTemplate`(편집기 로드 전 실행) 시점에도 `G7Core.layoutEditor` stub 을 항상
 * 노출해 register* 호출을 `__queue` 에 적재한다. 본 함수는 실제 레지스트리 함수로 교체하면서
 * stub 의 `__queue` 를 일괄 flush(누적된 등록 실행) + `__readyCallbacks` 호출한다. 따라서
 * 템플릿은 옵셔널 체이닝으로 즉시 등록해도 누락되지 않는다(stub 이 큐로 보존 → 본 함수가 flush).
 *
 * @since engine-v1.50.0
 */

import { registerWidget } from './widgetRegistry';
import { registerNodeEditor } from './nodeEditorRegistry';
import { registerCanvasOverlay } from './canvasOverlayRegistry';

interface LayoutEditorStub {
  __isStub?: boolean;
  __queue?: Array<[string, ...unknown[]]>;
  __readyCallbacks?: Array<() => void>;
}

/**
 * `window.G7Core.layoutEditor` 에 편집기 확장점 API(실제 레지스트리)를 노출하고,
 * 메인 번들 stub 이 보존해 둔 예약 등록 큐를 일괄 flush + ready 콜백을 호출한다.
 *
 * 멱등 — G7Core 부재(SSR/테스트 일부)면 no-op. 같은 이름/kind 재등록은 덮어쓰기.
 */
export function exposeLayoutEditorGlobals(): void {
  if (typeof window === 'undefined') return;
  const g7 = (window as unknown as { G7Core?: Record<string, unknown> }).G7Core;
  if (!g7) return;

  // 메인 번들 stub 이 보존한 예약 큐/콜백 회수(있으면).
  const prev = g7.layoutEditor as LayoutEditorStub | undefined;
  const pendingQueue = Array.isArray(prev?.__queue) ? prev!.__queue! : [];
  const readyCallbacks = Array.isArray(prev?.__readyCallbacks) ? prev!.__readyCallbacks! : [];

  g7.layoutEditor = {
    /** 커스텀 속성 컨트롤 위젯 등록 — controls.json 의 `widget` 으로 참조 */
    registerWidget,
    /** 커스텀 노드 에디터(속성탭 본체) 등록 — capability `nodeEditor.kind` 로 참조 */
    registerNodeEditor,
    /** 커스텀 캔버스 인플레이스 오버레이 등록 — capability `canvasOverlay.kind` 로 참조 */
    registerCanvasOverlay,
    /** 이미 ready 이므로 콜백 즉시 실행(예약 접수함 일관 API) */
    onReady: (cb: () => void) => {
      if (typeof cb === 'function') cb();
    },
  };

  // 예약 접수함 flush — stub 시절 적재된 등록을 실제 레지스트리에 일괄 반영.
  for (const entry of pendingQueue) {
    const [kind, ...args] = entry;
    try {
      if (kind === 'widget') registerWidget(args[0] as string, args[1] as never);
      else if (kind === 'nodeEditor') registerNodeEditor(args[0] as string, args[1] as never);
      else if (kind === 'canvasOverlay')
        registerCanvasOverlay(args[0] as string, args[1] as never);
    } catch {
      // 개별 등록 실패는 다른 등록을 막지 않는다(무손실 디그레이드).
    }
  }
  // ready 콜백 호출(예약된 순서대로).
  for (const cb of readyCallbacks) {
    try {
      cb();
    } catch {
      /* 콜백 실패 격리 */
    }
  }
}
