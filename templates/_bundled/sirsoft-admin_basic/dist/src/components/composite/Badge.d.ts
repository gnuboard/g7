import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface BadgeProps {
    /** 색상명 직접 지정 (blue/green/red/gray/yellow/... ). variant 보다 우선 적용 */
    color?: string;
    /** 의미 기반 변형 (success/warning/danger/info/primary/secondary/text). 백엔드 Enum variant() 출력과 매핑 */
    variant?: string;
    text?: string;
    size?: 'sm' | 'md' | 'lg';
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
 * Badge 집합 컴포넌트
 *
 * 색상 기반의 라벨 뱃지입니다. 상태, 타입 등의 분류를 시각적으로 표현합니다.
 *
 * 기본 컴포넌트 조합: Span
 *
 * @example
 * // 레이아웃 JSON 사용 예시 — 색상 직접 지정
 * {
 *   "name": "Badge",
 *   "props": { "color": "blue", "text": "활성" }
 * }
 * @example
 * // 의미 기반 variant (백엔드 Enum variant() 출력 바인딩)
 * {
 *   "name": "Badge",
 *   "props": { "variant": "{{row.option_status_variant}}", "text": "{{row.option_status_label}}" }
 * }
 */
export declare const Badge: React.FC<React.PropsWithChildren<BadgeProps>>;
