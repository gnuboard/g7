import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
import { EditorAttrs } from '../../types';
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
export declare const StatCard: React.FC<StatCardProps>;
