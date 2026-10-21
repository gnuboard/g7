import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface BadgeProps {
    /** 색상명 직접 지정 (blue/green/red/gray/yellow/... ). variant 보다 우선 적용 */
    color?: string;
    /** 의미 기반 변형 (success/warning/danger/info/primary/secondary/text). 백엔드 Enum variant() 출력과 매핑 */
    variant?: string;
    /**
     * 운영자 데이터 색(hex — 라벨·태그 색 등). 주면 color·variant 대신 이 색으로 그린다. 글자색은 데이터 색을 그대로
     * 쓰지 않고 바탕과 4.5 이상이 되도록 계산한다(연한 칩은 같은 계열을 짙게/밝게 섞은 단계, 꽉 찬 칩은 검정·흰색).
     * 읽을 수 없는 값이면 color·variant 로 그린다.
     */
    customColor?: string | null;
    /** customColor 칩 모양 — soft(옅은 바탕, 기본) | solid(데이터 색 바탕) */
    customColorStyle?: 'soft' | 'solid';
    text?: string;
    /**
     * 텍스트 앞에 그릴 아이콘 — 서버 상태 Enum 이 내보내는 의미 토큰(`status-*`)도 그대로 받는다
     * (Icon 이 토큰을 글리프로 해석). 비어 있으면 그리지 않는다.
     */
    icon?: string;
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
