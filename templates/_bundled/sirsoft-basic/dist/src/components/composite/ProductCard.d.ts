import { default as React } from 'react';
import { EditorAttrs } from '../../types';
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
        /** 상품코드 — 상세 페이지 링크 식별자 (product_code 기준 라우팅). 없으면 id 로 폴백 */
        product_code?: string;
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
    /**
     * 목록 컨텍스트 왕복 보존 여부 (#75).
     *
     * 상품 목록 그리드처럼 이 카드가 페이지네이션 목록의 일부인 자리에서만 true 로 켠다.
     * 켜면 상세로 이동할 때 현재 URL 의 page/sort/keyword/category 를 상세 URL 로 승계해,
     * 상세의 '뒤로가기' 가 보던 목록 위치로 정확히 되돌아간다.
     *
     * 찜 목록·검색 결과처럼 다른 목록에서 상품 상세로 나가는 이동은 기본값(false)을 유지한다 —
     * 그 목록의 페이지 상태를 상품 목록으로 옮기면 엉뚱한 위치로 복귀한다.
     */
    preserveListQuery?: boolean;
    /** 추가 CSS 클래스 */
    className?: string;
    /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
    id?: string;
    /**
       * 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread)
       */
    editorAttrs?: EditorAttrs;
}
declare const ProductCard: React.FC<ProductCardProps>;
export default ProductCard;
