/**
 * DropLine.tsx — 카드 리스트 드롭 예정 삽입선 공용 컴포넌트
 *
 *
 * 세로 카드 리스트(세그먼트 조각·화면 동작·컴포넌트 동작)에서 드래그 중 "여기에 떨어진다"를
 * 보여 주는 파란 삽입선이다. 캔버스 DnD(PlaceholderChipInput textSegDropActive)와 동일한 색
 * (2px #2563eb)을 써서 편집기 전반의 드롭 피드백 시각을 통일한다. 종전 `SegmentedValueEditor`
 * 내부에만 있던 것을 공용으로 추출해 `ActionRecipeEditor`/`InitActionsForm` 도 재사용한다
 *
 *
 * 비활성 시 높이 0(레이아웃 무영향), 드롭 예정 시에만 줄이 뜬다. 순수 표시용(aria-hidden).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React from 'react';

/**
 * 드롭 예정 삽입선.
 *
 * @param active 활성(드롭 예정) 여부 — true 면 파란 줄, false 면 높이 0
 * @param testid data-testid
 * @returns 삽입선 엘리먼트
 */
export function DropLine({ active, testid }: { active: boolean; testid: string }): React.ReactElement {
  return (
    <div
      data-testid={testid}
      data-active={active ? 'true' : 'false'}
      aria-hidden="true"
      style={active ? dropLineActive : dropLineIdle}
    />
  );
}

// 삽입선 — 비활성 시 높이 0(레이아웃 무영향), 드롭 예정 시 캔버스 DnD 와 동일한 파란 줄(2px #2563eb).
const dropLineIdle: React.CSSProperties = { height: 0, margin: 0, transition: 'height 80ms' };
const dropLineActive: React.CSSProperties = { height: 3, margin: '1px 0', borderRadius: 2, background: '#2563eb', boxShadow: '0 0 0 1px #93c5fd' };
