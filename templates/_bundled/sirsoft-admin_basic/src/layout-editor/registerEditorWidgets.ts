/**
 * registerEditorWidgets.ts — sirsoft-admin_basic 편집기 커스텀 위젯 등록
 *
 * 템플릿 부트스트랩(initTemplate)에서 호출. `G7Core.layoutEditor` 는 코어 예약 접수함
 * (stub)이 메인 번들에서 항상 노출하므로, 편집기 셸 로드 전에 호출해도 등록이 큐에
 * 보존되었다가 편집기 로드 시 flush 된다(타이밍 안전).
 *
 * 등록 위젯:
 *  - `icon-picker` — Font Awesome 검색 그리드(템플릿 소유 UI, 라이브러리 종속).
 *
 * 등록 캔버스 오버레이:
 *  - `tabnav` — TabNavigation tabs 배열 캔버스 인플레이스(+추가/✕삭제/◀▶이동). 템플릿이
 *    직접 등록해 확장점이 실동작함을 실증. 속성 패널 ArrayItemsEditor 와 동일 패치 경로 SSoT.
 */

import React from 'react';
import { IconPickerWidget } from './IconPickerWidget';
import { TabNavInplaceOverlay } from '../editor/TabNavInplaceOverlay';

const logger = (window as any).G7Core?.createLogger?.('Template:sirsoft-admin_basic:EditorWidgets') ?? {
  log: (...a: unknown[]) => console.log('[Template:sirsoft-admin_basic:EditorWidgets]', ...a),
  warn: (...a: unknown[]) => console.warn('[Template:sirsoft-admin_basic:EditorWidgets]', ...a),
};

/**
 * 편집기 커스텀 위젯을 코어 레지스트리에 등록한다. 멱등 — `G7Core.layoutEditor` 부재(코어
 * 미초기화)면 no-op(다음 호출에서 재시도하거나 stub 이 보존).
 */
export function registerSirsoftAdminBasicEditorWidgets(): void {
  if (typeof window === 'undefined') return;
  const layoutEditor = (window as any).G7Core?.layoutEditor;
  if (!layoutEditor?.registerWidget) {
    logger.warn('G7Core.layoutEditor 미노출 — icon-picker 위젯 등록 보류');
    return;
  }
  // 코어 위젯 레지스트리는 React 컴포넌트를 받는다. WidgetProps 형태로 호출됨.
  layoutEditor.registerWidget('icon-picker', IconPickerWidget as React.ComponentType<unknown>);
  logger.log("편집기 위젯 'icon-picker' 등록됨(Font Awesome 검색 그리드)");

  // 캔버스 인플레이스 오버레이 — `tabnav`(TabNavigation tabs). 부록4-bis 레퍼런스: 템플릿이
  // 직접 registerCanvasOverlay 로 등록해 확장점이 실동작함을 실증. capability `canvasOverlay.
  // kind:"tabnav"` 를 가진 노드가 선택되면 코어 EditorCanvasOverlay 가 본 오버레이를 마운트.
  if (layoutEditor.registerCanvasOverlay) {
    layoutEditor.registerCanvasOverlay('tabnav', TabNavInplaceOverlay as React.ComponentType<unknown>);
    logger.log("편집기 캔버스 오버레이 'tabnav' 등록됨(TabNavigation 탭 인플레이스)");
  }
}
