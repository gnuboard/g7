import React from 'react';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

export type StatusType = 'success' | 'warning' | 'error' | 'info' | 'pending' | 'default';

export interface StatusBadgeProps {
  status: StatusType;
  label?: string;
  showIcon?: boolean;
  iconName?: IconName;
  className?: string;
  style?: React.CSSProperties;
}

/**
 * StatusBadge 집합 컴포넌트
 *
 * 상태별 색상 매핑을 지원하는 뱃지 컴포넌트입니다.
 * 상태에 따라 색상과 아이콘이 자동으로 매핑됩니다.
 *
 * 기본 컴포넌트 조합: Span + Icon
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "StatusBadge",
 *   "props": {
 *     "status": "success",
 *     "label": "완료",
 *     "showIcon": true
 *   }
 * }
 */
export const StatusBadge: React.FC<StatusBadgeProps> = ({
  status,
  label,
  showIcon = true,
  iconName,
  className = '',
  style,
}) => {
  // 상태별 스타일 매핑
  const statusStyles: Record<StatusType, string> = {
    success: 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 border-green-200 dark:border-green-800',
    warning: 'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-400 border-yellow-200 dark:border-yellow-800',
    error: 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800',
    info: 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-800',
    pending: 'bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-gray-200 dark:border-gray-600',
    default: 'bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-400 border-gray-200 dark:border-gray-600',
  };

  // 상태별 기본 아이콘 매핑
  const statusIcons: Record<StatusType, IconName> = {
    success: IconName.CheckCircle,
    warning: IconName.ExclamationTriangle,
    error: IconName.XCircle,
    info: IconName.InfoCircle,
    pending: IconName.Clock,
    default: IconName.QuestionCircle,
  };

  // 상태별 기본 라벨 매핑
  const statusLabels: Record<StatusType, string> = {
    success: '성공',
    warning: '경고',
    error: '오류',
    info: '정보',
    pending: '대기 중',
    default: '기본',
  };

  const displayIcon = iconName || statusIcons[status];
  const displayLabel = label || statusLabels[status];
  const styleClasses = statusStyles[status];

  return (
    <Span
      className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border ${styleClasses} ${className}`}
      style={style}
    >
      {showIcon && (
        <Icon name={displayIcon} className="w-4 h-4" />
      )}
      <Span>{displayLabel}</Span>
    </Span>
  );
};
