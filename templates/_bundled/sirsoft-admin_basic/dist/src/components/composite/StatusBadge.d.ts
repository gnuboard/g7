import { default as React } from 'react';
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
export declare const StatusBadge: React.FC<StatusBadgeProps>;
