import React from 'react';
import { Div } from '../basic/Div';
import { H3 } from '../basic/H3';
import { Span } from '../basic/Span';
import { P } from '../basic/P';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import type { EditorAttrs } from '../../types';

export interface StatCardProps {
  value: string | number;
  label: string;
  change?: number;
  changeLabel?: string;
  iconName?: IconName;
  trend?: 'up' | 'down' | 'neutral';
  className?: string;
  style?: React.CSSProperties;
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

/**
 * StatCard 집합 컴포넌트
 *
 * 통계 수치와 변화율을 시각화하는 카드 컴포넌트입니다.
 * 수치, 라벨, 변화율(증가/감소), 아이콘을 표시합니다.
 *
 * 기본 컴포넌트 조합: Div + H3 + Span + P + Icon
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "StatCard",
 *   "props": {
 *     "value": 12345,
 *     "label": "총 사용자",
 *     "change": 12.5,
 *     "changeLabel": "지난달 대비",
 *     "iconName": "users",
 *     "trend": "up"
 *   }
 * }
 */
export const StatCard: React.FC<StatCardProps> = ({
  value,
  label,
  change,
  changeLabel = '전월 대비',
  iconName,
  trend = 'neutral',
  className = '',
  style,
  id,
  editorAttrs,
}) => {
  const isPositive = trend === 'up';
  const isNegative = trend === 'down';
  const changeValue = change !== undefined ? Math.abs(change) : 0;

  const trendClass = isPositive
    ? 'stats-trend-up'
    : isNegative
      ? 'stats-trend-down'
      : 'stats-trend-neutral';

  const trendIcon = isPositive ? IconName.ArrowUp : isNegative ? IconName.ArrowDown : undefined;

  return (
    <Div
      className={`stat-card ${className}`}
      style={style}
      id={id} {...editorAttrs}
    >
      {/* 헤더: 아이콘과 변화율 */}
      <Div className="flex-between mb-md">
        {iconName && (
          <Div className="stats-icon stats-icon-blue">
            <Icon name={iconName} className="icon-lg" />
          </Div>
        )}

        {change !== undefined && (
          <Div className={`stats-change ${trendClass}`}>
            {trendIcon && (
              <Icon name={trendIcon} className="icon-sm" />
            )}
            <Span className="text-sm font-semibold">
              {changeValue}%
            </Span>
          </Div>
        )}
      </Div>

      {/* 통계 수치 */}
      <H3 className="stats-value-lg">
        {typeof value === 'number' ? value.toLocaleString() : value}
      </H3>

      {/* 라벨 */}
      <P className="stats-label">
        {label}
      </P>

      {/* 변화율 설명 */}
      {change !== undefined && changeLabel && (
        <Div className="stats-sublabel">
          <Span>
            {changeLabel}
          </Span>
        </Div>
      )}
    </Div>
  );
};
