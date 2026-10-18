import React from 'react';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import type { EditorAttrs } from '../../types';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/**
 * 뱃지 상태 어휘.
 *
 * `danger`·`primary`·`secondary`·`dark` 는 도메인 Enum 의 `variant()` 가 실제로 내려보내는
 * 값이다(이커머스 클레임 상태 등). 이 유니언에 없으면 스타일 조회가 `undefined` 가 되어
 * className 에 리터럴 `"undefined"` 가 박히고 배경·글자색·테두리가 통째로 사라진다 —
 * 레이아웃 JSON 은 TS 타입 검사 밖이라 컴파일에도 걸리지 않는다.
 */
export type StatusType =
  | 'success'
  | 'warning'
  | 'error'
  | 'danger'
  | 'info'
  | 'primary'
  | 'secondary'
  | 'dark'
  | 'pending'
  | 'default';

export interface StatusBadgeProps {
  status: StatusType;
  label?: string;
  showIcon?: boolean;
  iconName?: IconName;
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
 *     "label": "$t:common.status_badge.success",
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
  id,
  editorAttrs,
}) => {
  // 상태별 스타일 매핑
  const statusStyles: Record<StatusType, string> = {
    success: 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 border-green-200 dark:border-green-800',
    warning: 'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-400 border-yellow-200 dark:border-yellow-800',
    error: 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800',
    danger: 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800',
    info: 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-800',
    primary: 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-400 border-indigo-200 dark:border-indigo-800',
    secondary: 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600',
    dark: 'bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-200 border-slate-300 dark:border-slate-700',
    pending: 'bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-gray-200 dark:border-gray-600',
    default: 'bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-400 border-gray-200 dark:border-gray-600',
  };

  // 상태별 기본 아이콘 매핑
  const statusIcons: Record<StatusType, IconName> = {
    success: IconName.CheckCircle,
    warning: IconName.ExclamationTriangle,
    error: IconName.XCircle,
    danger: IconName.XCircle,
    info: IconName.InfoCircle,
    primary: IconName.InfoCircle,
    secondary: IconName.QuestionCircle,
    dark: IconName.Clock,
    pending: IconName.Clock,
    default: IconName.QuestionCircle,
  };

  // 상태별 기본 라벨 — 번역 키(common.status_badge.*)로 낸다. 리터럴로 들고 있으면 영어·일본어
  // 관리자 화면에도 한국어가 그대로 뜬다.
  const statusLabels: Record<StatusType, string> = {
    success: t('common.status_badge.success'),
    warning: t('common.status_badge.warning'),
    error: t('common.status_badge.error'),
    danger: t('common.status_badge.danger'),
    info: t('common.status_badge.info'),
    primary: t('common.status_badge.primary'),
    secondary: t('common.status_badge.secondary'),
    dark: t('common.status_badge.dark'),
    pending: t('common.status_badge.pending'),
    default: t('common.status_badge.default'),
  };

  // 어휘 밖 값에서 `undefined` 가 className 에 박히지 않게 `default` 로 떨어뜨린다.
  // 종전에는 조회 실패가 곧 무색 뱃지였고, 그 사실이 화면에만 나타나 게이트가 잡지 못했다.
  const resolved: StatusType = statusStyles[status] ? status : 'default';

  const displayIcon = iconName || statusIcons[resolved];
  const displayLabel = label || statusLabels[resolved];
  const styleClasses = statusStyles[resolved];

  return (
    <Span
      className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border ${styleClasses} ${className}`}
      style={style}
      id={id} {...editorAttrs}
    >
      {showIcon && (
        <Icon name={displayIcon} className="w-4 h-4" />
      )}
      <Span>{displayLabel}</Span>
    </Span>
  );
};
