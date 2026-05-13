import { default as React } from 'react';
export interface CardProps {
    title?: string;
    content?: string;
    imageUrl?: string;
    imageAlt?: string;
    className?: string;
    onClick?: () => void;
    style?: React.CSSProperties;
}
/**
 * Card 집합 컴포넌트
 *
 * 기본 컴포넌트를 조합하여 카드 UI를 구성합니다.
 * 레이아웃 JSON에서 title, content, imageUrl 등의 props만 전달하면
 * 내부적으로 Div, H2, P, Img 컴포넌트를 조합하여 완성된 카드를 렌더링합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Card",
 *   "props": {
 *     "title": "사용자 정보",
 *     "content": "홍길동 (hong@example.com)",
 *     "imageUrl": "/avatar.png"
 *   }
 * }
 */
export declare const Card: React.FC<CardProps>;
