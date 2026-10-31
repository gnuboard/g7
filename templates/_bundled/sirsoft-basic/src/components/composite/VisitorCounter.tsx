import React from 'react';
import type { EditorAttrs } from '../../types';

/**
 * 방문자 카운터 라벨 — 레이아웃이 `$t:` 로 번역해 넘긴다(컴포넌트는 번역하지 않는다).
 */
export interface VisitorCounterLabels {
  /** 카운터 묶음 전체의 이름 — 스크린리더가 숫자 넷을 무엇의 묶음으로 읽을지 정한다 */
  title?: string;
  today?: string;
  yesterday?: string;
  max?: string;
  total?: string;
}

export interface VisitorCounterProps {
  /** 오늘 방문자 */
  today?: number | string;
  /** 어제 방문자 */
  yesterday?: number | string;
  /** 하루 최대 방문자 */
  max?: number | string;
  /** 전체 방문자(일별 순방문자 합) */
  total?: number | string;
  /** 배치 — `row` 는 한 줄 4칸(모바일 2×2), `column` 은 세로 나열 */
  layout?: 'row' | 'column';
  /** 항목 라벨 (없으면 영문 기본값) */
  labels?: VisitorCounterLabels;
  /** 추가 CSS 클래스 */
  className?: string;
  /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

const DEFAULT_LABELS: Required<VisitorCounterLabels> = {
  title: 'Visitors',
  today: 'Today',
  yesterday: 'Yesterday',
  max: 'Peak',
  total: 'Total',
};

/**
 * 숫자를 로케일 천 단위 구분으로 표시한다. 숫자가 아니면 0 으로 본다 — 카운터에 NaN 이 떠서는 안 된다.
 *
 * @param value 표시할 값
 * @returns 포맷된 문자열
 */
function formatCount(value: number | string | undefined): string {
  const n = typeof value === 'number' ? value : Number(value ?? 0);
  return Number.isFinite(n) ? Math.max(0, Math.round(n)).toLocaleString() : '0';
}

/**
 * VisitorCounter 컴포넌트
 *
 * 사이트 홈에 놓는 방문자 카운터(오늘·어제·최대·전체). 순수 표시 컴포넌트라 데이터를 스스로
 * 받지 않는다 — 레이아웃 데이터소스(`GET /api/analytics/summary`)가 네 숫자를 넘긴다.
 * 다크 모드 변형을 함께 두고, `row` 배치는 모바일에서 2×2 로 접힌다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "VisitorCounter",
 *   "props": {
 *     "today": "{{visitor_summary?.data?.today ?? 0}}",
 *     "yesterday": "{{visitor_summary?.data?.yesterday ?? 0}}",
 *     "max": "{{visitor_summary?.data?.max ?? 0}}",
 *     "total": "{{visitor_summary?.data?.total ?? 0}}",
 *     "layout": "row",
 *     "labels": { "today": "$t:user.visitor_counter.today" }
 *   }
 * }
 */
export const VisitorCounter: React.FC<VisitorCounterProps> = ({
  today = 0,
  yesterday = 0,
  max = 0,
  total = 0,
  layout = 'row',
  labels,
  className = '',
  id,
  editorAttrs,
}) => {
  const l = { ...DEFAULT_LABELS, ...(labels ?? {}) };
  const items: Array<{ key: keyof VisitorCounterLabels; label: string; value: number | string; accent: string }> = [
    { key: 'today', label: l.today, value: today, accent: 'text-blue-600 dark:text-blue-400' },
    { key: 'yesterday', label: l.yesterday, value: yesterday, accent: 'text-gray-900 dark:text-white' },
    { key: 'max', label: l.max, value: max, accent: 'text-gray-900 dark:text-white' },
    { key: 'total', label: l.total, value: total, accent: 'text-gray-900 dark:text-white' },
  ];

  const gridClass = layout === 'column'
    ? 'grid grid-cols-1 gap-3'
    : 'grid grid-cols-2 md:grid-cols-4 gap-3';

  return (
    <div
      className={`visitor-counter bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-100 dark:border-gray-700 ${className}`}
      id={id}
      data-visitor-counter=""
      role="group"
      aria-label={l.title}
      {...editorAttrs}
    >
      {/* 라벨과 숫자를 dt/dd 로 묶는다 — span 두 개로는 스크린리더가 「오늘」과 「1,284」를 잇지 못하고
          숫자 넷을 맥락 없이 읽는다(#682 S3 라운드 3). */}
      <dl className={gridClass}>
        {items.map((item) => (
          <div key={item.key} className="flex flex-col items-center text-center" data-visitor-counter-item={item.key}>
            <dt className="text-xs text-gray-500 dark:text-gray-400">{item.label}</dt>
            <dd className={`text-xl font-bold ${item.accent}`} data-visitor-counter-value={item.key}>
              {formatCount(item.value)}
            </dd>
          </div>
        ))}
      </dl>
    </div>
  );
};

export default VisitorCounter;
