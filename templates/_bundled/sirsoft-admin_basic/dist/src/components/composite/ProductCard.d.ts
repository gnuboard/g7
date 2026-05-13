import { default as React } from 'react';
export interface ProductCardProps {
    imageUrl?: string;
    imageAlt?: string;
    title: string;
    subtitle?: string;
    description?: string;
    price: number;
    originalPrice?: number;
    discountRate?: number;
    currency?: string;
    buttonText?: string;
    onButtonClick?: () => void;
    className?: string;
    style?: React.CSSProperties;
}
/**
 * ProductCard 집합 컴포넌트
 *
 * 상품 정보를 표시하는 카드 컴포넌트입니다.
 * 이미지, 제목, 부제목, 설명, 가격, 할인율, 액션 버튼을 포함합니다.
 *
 * 기본 컴포넌트 조합: Div + Img + H2 + H3 + P + Span + Button
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "ProductCard",
 *   "props": {
 *     "imageUrl": "/product.png",
 *     "title": "프리미엄 제품",
 *     "subtitle": "신제품",
 *     "description": "최고급 소재로 제작된 프리미엄 제품입니다.",
 *     "price": 45000,
 *     "originalPrice": 50000,
 *     "discountRate": 10,
 *     "buttonText": "구매하기"
 *   }
 * }
 */
export declare const ProductCard: React.FC<ProductCardProps>;
