import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
export interface EmptyStateAction {
    label: string;
    onClick: () => void;
    variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
    iconName?: IconName;
}
export interface EmptyStateProps {
    title: string;
    description?: string;
    iconName?: IconName;
    illustrationSrc?: string;
    illustrationAlt?: string;
    actions?: EmptyStateAction[];
    className?: string;
}
/**
 * EmptyState 집합 컴포넌트
 *
 * 데이터가 없거나 검색 결과가 없을 때 표시하는 빈 상태 컴포넌트.
 * icon/illustration, title, description, actions 버튼 배열 지원.
 *
 * 기본 컴포넌트 조합:
 * - Div(컨테이너) > Icon/Img + H2(제목) + P(설명) + Div(액션) > Button[]
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "EmptyState",
 *   "props": {
 *     "title": "검색 결과가 없습니다",
 *     "description": "다른 검색어로 시도해보세요.",
 *     "iconName": "Search",
 *     "actions": [
 *       {"label": "검색 초기화", "variant": "secondary"}
 *     ]
 *   }
 * }
 */
export declare const EmptyState: React.FC<EmptyStateProps>;
