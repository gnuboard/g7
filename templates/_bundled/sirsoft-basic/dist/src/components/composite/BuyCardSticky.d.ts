import { default as React } from 'react';
export interface SpecRow {
    label: string;
    value: string;
}
export interface BuyCardStickyProps {
    category?: string;
    title: string;
    summary?: string;
    rating?: number;
    reviewCount?: number;
    price?: number | string;
    priceNote?: string;
    purchaseHref?: string;
    demoHref?: string;
    /** 사양 행 (최소 요구 / 라이선스 / 업데이트 / 다운로드 등) */
    specs?: SpecRow[];
    /** 공유 버튼 표시 여부 */
    showShare?: boolean;
}
/**
 * 테마 상세 페이지 우측 sticky 구매 박스.
 * 카테고리 태그 + 제목 + 요약 + 별점 + 가격 + 구매/데모 CTA + 사양표 + 공유.
 */
declare const BuyCardSticky: React.FC<BuyCardStickyProps>;
export default BuyCardSticky;
