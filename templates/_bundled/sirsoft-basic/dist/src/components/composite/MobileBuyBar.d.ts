import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface MobileBuyBarProps {
    /** 바 종류 — 상품 상세(product) · 장바구니(cart) */
    variant?: 'product' | 'cart';
    /** 보여 줄 금액 (형식을 갖춘 글자) */
    priceText?: string;
    /** 금액 옆 보조 글자 (수량 등) */
    captionText?: string;
    /** 찜 여부 (상품 바) */
    wished?: boolean;
    /** 찜 단추 표시 여부 (상품 바, 기본 true) */
    showWish?: boolean;
    /** 장바구니 단추 잠금 (상품 바) */
    cartDisabled?: boolean;
    /** 바로구매 단추 잠금 (상품 바) */
    buyDisabled?: boolean;
    /** 주 단추 글자 (장바구니 바) */
    primaryLabel?: string;
    /** 주 단추 잠금 (장바구니 바) */
    primaryDisabled?: boolean;
    /** 진행 중 — 모든 단추를 잠그고 주 단추에 스피너를 보인다 */
    busy?: boolean;
    /** 판매 중이 아닐 때의 상태 글자 (상품 바) — 주면 장바구니·바로구매 대신 이 글자를 보인다 */
    soldOutLabel?: string;
    /** 찜 단추를 눌렀을 때 */
    onWish?: () => void;
    /** 장바구니 단추를 눌렀을 때 */
    onCart?: () => void;
    /** 바로구매 단추를 눌렀을 때 */
    onBuy?: () => void;
    /** 주 단추를 눌렀을 때 (장바구니 바) */
    onPrimary?: () => void;
    /** 추가 CSS 클래스 */
    className?: string;
    /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * 좁은 화면 하단 구매바
 *
 * @example
 * ```html
 * <mobile-buy-bar data-type="composite" variant="product" priceText="{{…}}" wished="{{…}}">
 *   <on event="onCart"><action-ref name="purchaseAddToCart"/></on>
 * </mobile-buy-bar>
 * ```
 */
export declare const MobileBuyBar: React.FC<MobileBuyBarProps>;
export default MobileBuyBar;
