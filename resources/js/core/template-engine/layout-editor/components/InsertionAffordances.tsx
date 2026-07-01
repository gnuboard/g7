/**
 * InsertionAffordances.tsx — 선택 컴포넌트 외곽 + 버튼 레이어
 *
 * 선택된 컴포넌트의 절대 위치 박스 주변에 4방향 + 버튼을 오버레이로 띄운다.
 * `useInsertionPoints` 가 결정한 활성/비활성 + parentPath/index 정보를
 * 받아서 클릭 시 팔레트로 위임.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { useTranslation } from '../../TranslationContext';
import type { OverlayBox } from '../utils/overlayGeometry';
import {
  resolveAffordancePlacement,
  resolveInsertionCrossOffsets,
} from '../utils/overlayGeometry';
import { OVERLAY_AFFORDANCE } from '../utils/overlayZIndex';
import type { InsertionPoint, InsertionDirection } from '../hooks/useInsertionPoints';

export interface InsertionAffordancesProps {
  /** 선택 노드 박스 좌표 (frame 기준 — overlayGeometry.measureOverlay 결과) */
  selectedBox: OverlayBox | null;
  /** 4방향 + 버튼 정보 */
  points: InsertionPoint[];
  /** + 버튼 클릭 시 호출 — 팔레트 오픈 콜백 */
  onAddRequest: (point: InsertionPoint) => void;
}

// 큰 박스(inside) 4방향 + 버튼 오프셋. 종전 -12 는 버튼(24px)이 박스 변에 절반
// 걸쳐 모서리 리사이즈 핸들(±-4)·코너와 겹쳤다("요소 추가 버튼이
// 모서리와 겹쳐 크기 핸들을 가린다"). 변 중앙 기준이라 코너 핸들과 직접 겹치진
// 않으나 버튼 외곽이 코너 근접 + 변 핸들(n/s/e/w, 변 중앙) 을 덮었다. 박스 바깥
// 여백으로 완전히 밀어내 핸들·코너에서 떨어뜨린다(작은 박스 outside 와 동일 의도).
const INSERTION_GAP = 30;
const DIRECTION_OFFSET: Record<InsertionDirection, React.CSSProperties> = {
  above: { top: -INSERTION_GAP, left: '50%', transform: 'translateX(-50%)' },
  below: { bottom: -INSERTION_GAP, left: '50%', transform: 'translateX(-50%)' },
  left: { left: -INSERTION_GAP, top: '50%', transform: 'translateY(-50%)' },
  right: { right: -INSERTION_GAP, top: '50%', transform: 'translateY(-50%)' },
};

export function InsertionAffordances(props: InsertionAffordancesProps): React.ReactElement | null {
  const { selectedBox, points, onAddRequest } = props;
  const { t } = useTranslation();

  if (!selectedBox || points.length === 0) {
    return null;
  }

  // 작은 박스(44px 미만)에서는 4방향 + 버튼이 고정 -12 오프셋이라 박스/서로 겹친다.
  // 방향 의미(위/아래/좌/우)는 그대로 두되, 박스 중심 기준 십자로 더 멀리 벌려
  // 겹침을 제거한다.
  const placement = resolveAffordancePlacement(selectedBox);
  const isOutside = placement === 'outside';
  const crossOffsets = isOutside ? resolveInsertionCrossOffsets(selectedBox) : null;

  return (
    <div
      className="g7le-insertion-affordances"
      data-testid="g7le-insertion-affordances"
      style={{
        position: 'absolute',
        left: selectedBox.left,
        top: selectedBox.top,
        width: selectedBox.width,
        height: selectedBox.height,
        pointerEvents: 'none',
      }}
    >
      {points.map((point) => {
        const baseStyle: React.CSSProperties = crossOffsets
          ? {
              left: crossOffsets[point.direction].left,
              top: crossOffsets[point.direction].top,
            }
          : DIRECTION_OFFSET[point.direction];
        const label = t(`layout_editor.insertion.${point.direction}`);
        return (
          <button
            key={point.direction}
            type="button"
            disabled={point.disabled}
            data-testid={`g7le-insertion-${point.direction}`}
            data-disabled={point.disabled ? 'true' : 'false'}
            data-placement={placement}
            aria-label={label}
            title={label}
            onClick={(e) => {
              e.stopPropagation();
              if (!point.disabled) onAddRequest(point);
            }}
            style={{
              position: 'absolute',
              ...baseStyle,
              width: 24,
              height: 24,
              borderRadius: 12,
              border: '1px solid #2563eb',
              background: point.disabled ? '#cbd5e1' : '#fff',
              color: point.disabled ? '#94a3b8' : '#2563eb',
              cursor: point.disabled ? 'not-allowed' : 'pointer',
              opacity: point.disabled ? 0.4 : 1,
              fontSize: 16,
              fontWeight: 700,
              lineHeight: '20px',
              padding: 0,
              pointerEvents: 'auto',
              boxShadow: '0 1px 3px rgba(0,0,0,0.15)',
              // S5b 드래그 핸들(20+depth) 위 어포던스 밴드 — 미지정 시 핸들이 + 버튼을
              // 덮어 클릭이 이동 포인터에 가로채임.
              zIndex: OVERLAY_AFFORDANCE,
            }}
          >
            +
          </button>
        );
      })}
    </div>
  );
}
