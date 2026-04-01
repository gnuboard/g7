import { default as React, ReactNode } from 'react';
export interface AccordionProps {
    /** 기본 열림 상태 */
    defaultOpen?: boolean;
    /** 외부에서 열림 상태 제어 */
    isOpen?: boolean;
    /** 열림 상태 변경 콜백 */
    onToggle?: (isOpen: boolean) => void;
    /** 추가 클래스명 */
    className?: string;
    /** 인라인 스타일 */
    style?: React.CSSProperties;
    /** 자식 요소 (trigger, content 슬롯) */
    children?: ReactNode;
    /** 비활성화 여부 */
    disabled?: boolean;
}
/**
 * Accordion 집합 컴포넌트
 *
 * 접기/펼치기가 가능한 아코디언 UI를 제공합니다.
 * trigger 슬롯과 content 슬롯을 통해 헤더와 내용을 정의합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Accordion",
 *   "props": { "defaultOpen": false },
 *   "children": [
 *     {
 *       "slot": "trigger",
 *       "type": "basic",
 *       "name": "Div",
 *       "children": [{ "type": "basic", "name": "Span", "text": "제목" }]
 *     },
 *     {
 *       "slot": "content",
 *       "type": "basic",
 *       "name": "Div",
 *       "children": [{ "type": "basic", "name": "P", "text": "내용" }]
 *     }
 *   ]
 * }
 */
export declare const Accordion: React.FC<AccordionProps>;
