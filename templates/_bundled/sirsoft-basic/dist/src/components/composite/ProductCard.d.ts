import { default as React } from 'react';
interface CurrencyPrice {
    value: number;
    formatted: string;
}
interface ProductLabel {
    name: string;
    color: string;
}
interface ProductCardProps {
    product: {
        id: number;
        name?: string | Record<string, string>;
        name_localized?: string;
        thumbnail_url: string;
        primary_category?: string;
        selling_price: number;
        selling_price_formatted: string;
        list_price?: number;
        list_price_formatted?: string;
        discount_rate?: number;
        multi_currency_selling_price?: Record<string, CurrencyPrice>;
        multi_currency_list_price?: Record<string, CurrencyPrice>;
        /** 검색 하이라이트된 상품명 (HTML) */
        name_highlighted?: string;
        /** 검색 하이라이트된 설명 (HTML) */
        description_highlighted?: string;
        /** 짧은 설명 */
        short_description?: string;
        /** 판매 상태 (on_sale, sold_out, suspended, coming_soon) */
        sales_status?: string;
        /** 판매 상태 번역 라벨 */
        sales_status_label?: string;
        /** 브랜드명 */
        brand_name?: string;
        /** 상품 라벨/뱃지 목록 */
        labels?: ProductLabel[];
        /** 평균 별점 (0.0 ~ 5.0, 소수점 1자리) */
        rating_avg?: number;
        /** 전시중 리뷰 수 */
        review_count?: number;
    };
    /** 클릭 시 호출되는 콜백 */
    onClick?: (productId: number) => void;
    /** 쇼핑몰 base 경로 (예: '/shop', '/store', '/') */
    shopBase?: string;
    /** 추가 CSS 클래스 */
    className?: string;
}
declare const ProductCard: React.FC<ProductCardProps>;
export default ProductCard;
