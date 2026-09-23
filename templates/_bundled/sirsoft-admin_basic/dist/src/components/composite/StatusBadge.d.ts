import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
import { EditorAttrs } from '../../types';
/**
 * 뱃지 상태 어휘.
 *
 * `danger`·`primary`·`secondary`·`dark` 는 도메인 Enum 의 `variant()` 가 실제로 내려보내는
 * 값이다(이커머스 클레임 상태 등). 이 유니언에 없으면 스타일 조회가 `undefined` 가 되어
 * className 에 리터럴 `"undefined"` 가 박히고 배경·글자색·테두리가 통째로 사라진다 —
 * 레이아웃 JSON 은 TS 타입 검사 밖이라 컴파일에도 걸리지 않는다.
 */
export type StatusType = 'success' | 'warning' | 'error' | 'danger' | 'info' | 'primary' | 'secondary' | 'dark' | 'pending' | 'default';
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
export declare const StatusBadge: React.FC<StatusBadgeProps>;
