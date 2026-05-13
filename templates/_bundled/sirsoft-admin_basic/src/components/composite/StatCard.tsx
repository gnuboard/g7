import React from 'react';
import { Div } from '../basic/Div';
import { H3 } from '../basic/H3';
import { Span } from '../basic/Span';
import { P } from '../basic/P';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

export interface StatCardProps {
  value: string | number;
  label: string;
  change?: number;
  changeLabel?: string;
  iconName?: IconName;
  trend?: 'up' | 'down' | 'neutral';
  className?: string;
  style?: React.CSSProperties;
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
}) => {
  const isPositive = trend === 'up';
  const isNegative = trend === 'down';
  const changeValue = change !== undefined ? Math.abs(change) : 0;

  const trendColor = isPositive
    ? 'text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20'
    : isNegative
      ? 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20'
      : 'text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-700';

  const trendIcon = isPositive ? IconName.ArrowUp : isNegative ? IconName.ArrowDown : undefined;

  return (
    <Div
      className={`rounded-lg bg-white dark:bg-gray-800 shadow-md hover:shadow-lg transition-shadow border border-gray-200 dark:border-gray-700 p-6 ${className}`}
      style={style}
    >
      {/* 헤더: 아이콘과 변화율 */}
      <Div className="flex items-center justify-between mb-4">
        {iconName && (
          <Div className="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
            <Icon name={iconName} className="w-6 h-6 text-blue-600 dark:text-blue-400" />
          </Div>
        )}

        {change !== undefined && (
          <Div className={`flex items-center gap-1 px-2 py-1 rounded-md ${trendColor}`}>
            {trendIcon && (
              <Icon name={trendIcon} className="w-4 h-4" />
            )}
            <Span className="text-sm font-semibold">
              {changeValue}%
            </Span>
          </Div>
        )}
      </Div>

      {/* 통계 수치 */}
      <H3 className="text-3xl font-bold text-gray-900 dark:text-white mb-1">
        {typeof value === 'number' ? value.toLocaleString() : value}
      </H3>

      {/* 라벨 */}
      <P className="text-sm text-gray-600 dark:text-gray-400 mb-2">
        {label}
      </P>

      {/* 변화율 설명 */}
      {change !== undefined && changeLabel && (
        <Div className="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
          <Span>
            {changeLabel}
          </Span>
        </Div>
      )}
    </Div>
  );
};
