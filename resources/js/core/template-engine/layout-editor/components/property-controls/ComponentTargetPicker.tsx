/**
 * ComponentTargetPicker.tsx — 범용 컴포넌트 영역 picker 위젯
 *
 * "캔버스에서 ID 보유 컴포넌트 1개를 골라 그 ID 를 돌려주는" 독립 위젯. transition_overlay 에
 * 결합하지 않는다 — 단지 "캔버스에서 ID 하나 고르기"만 한다. 재사용처:
 *  ① [로딩 화면] target/fallback_target ② navigate 스펙 transition_overlay_target
 *  ③ 향후 임의의 "요소 ID 참조" param. editor-spec param widget 타입 `component-target-picker`
 *  로 스펙에서 선언만 하면 어느 폼에서나 동작(신규 폼 코드 0).
 *
 * 두 경로로 ID 지정:
 *  - **직접 입력칸**(항상 동작): ID 문자열 타이핑.
 *  - **[🎯 영역 선택]**: 호스트 모달을 최소화하고 캔버스 선택 모드 진입 신호를
 *    발사. 캔버스가 hover 하이라이트 → 노드 클릭 → 그 id 를 `g7le:component-target-picked`
 *    이벤트로 회신 → onChange + 모달 복원. ID 미부여 노드는 "ID 부여가 필요합니다" 호버 안내
 *    (선택 불가). 캔버스 선택 모드 마운트/하이라이트는 캔버스 오버레이 소관(후속 세션 wiring).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback } from 'react';
import type { WidgetProps } from '../../spec/widgetRegistry';
import { useEditorModal } from '../../EditorModalContext';

/** 캔버스 → picker 회신 이벤트 — detail.requestId 로 요청-응답 짝을 식별 */
export const COMPONENT_TARGET_PICKED_EVENT = 'g7le:component-target-picked';
/** picker → 캔버스 선택 모드 진입 요청 이벤트 */
export const COMPONENT_TARGET_PICK_REQUEST_EVENT = 'g7le:component-target-pick-request';

let nextPickRequestId = 1;

/**
 * 컴포넌트 영역 picker 위젯.
 *
 * @param props WidgetProps — value(현재 ID), onChange(선택 ID), t(다국어), control(라벨/옵션)
 * @return picker 위젯 엘리먼트
 */
export function ComponentTargetPicker({ value, onChange, t, control }: WidgetProps): React.ReactElement {
  const modal = useEditorModal();
  const current = value === undefined || value === null ? '' : String(value);
  // 직접 입력 허용 여부 — control.allowManualInput(기본 true).
  const allowManualInput = (control as { allowManualInput?: boolean })?.allowManualInput !== false;

  const requestPick = useCallback((): void => {
    if (typeof window === 'undefined') return;
    const requestId = `pick-${nextPickRequestId++}`;
    // 호스트 모달 최소화 — 현재 열린 최상위 모달 id 를 캔버스 선택 동안 비킨다.
    const top = modal.stack[modal.stack.length - 1];
    const hostId = top?.id;
    if (hostId) {
      modal.minimize(hostId, t('layout_editor.target_picker.minimized_hint'));
    }
    // 1회성 회신 핸들러 — 같은 requestId 의 응답만 수신.
    const onPicked = (e: Event): void => {
      const detail = (e as CustomEvent).detail as { requestId?: string; id?: string; cancelled?: boolean };
      if (detail?.requestId !== requestId) return;
      window.removeEventListener(COMPONENT_TARGET_PICKED_EVENT, onPicked);
      if (hostId) modal.restore(hostId);
      if (!detail.cancelled && typeof detail.id === 'string') {
        onChange(detail.id);
      }
    };
    window.addEventListener(COMPONENT_TARGET_PICKED_EVENT, onPicked);
    // 캔버스 선택 모드 진입 요청 발사 — 캔버스 오버레이가 수신해 하이라이트/클릭 모드 진입.
    window.dispatchEvent(
      new CustomEvent(COMPONENT_TARGET_PICK_REQUEST_EVENT, { detail: { requestId } }),
    );
  }, [modal, onChange, t]);

  return (
    <div className="g7le-component-target-picker" data-testid="g7le-component-target-picker">
      <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
        {allowManualInput ? (
          <input
            type="text"
            className="g7le-widget g7le-widget--text"
            data-testid="g7le-component-target-input"
            value={current}
            placeholder={t('layout_editor.target_picker.id_placeholder')}
            onChange={(e) => onChange(e.target.value === '' ? undefined : e.target.value)}
            style={{
              flex: 1,
              minWidth: 0,
              padding: '6px 8px',
              border: '1px solid #cbd5e1',
              borderRadius: 6,
              fontSize: 13,
            }}
          />
        ) : (
          <span data-testid="g7le-component-target-value" style={{ flex: 1, minWidth: 0 }}>
            {current || t('layout_editor.target_picker.none')}
          </span>
        )}
        <button
          type="button"
          data-testid="g7le-component-target-pick"
          onClick={requestPick}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 4,
            padding: '6px 10px',
            background: '#1e293b',
            color: '#fff',
            border: 'none',
            borderRadius: 6,
            cursor: 'pointer',
            fontSize: 13,
            whiteSpace: 'nowrap',
          }}
        >
          <span aria-hidden="true">🎯</span>
          {t('layout_editor.target_picker.select_area')}
        </button>
      </div>
    </div>
  );
}
