/**
 * 상품 카드의 곧바로 담기·옵션 선택 창·찜 핸들러
 *
 * 상품 카드(목록·검색·가로 상품 줄)에서 상세로 들어가지 않고 장바구니에 담거나 찜한다.
 * - 고를 것이 없는 상품(옵션 하나·추가옵션 없음)은 곧바로 담는다.
 * - 고를 것이 있거나 알 수 없는 상품은 옵션 선택 창(`quick_option_modal`)을 연다.
 *
 * 요청은 엔진의 `apiCall` 로 보낸다 — 인증 헤더·전역 헤더·401 처리를 엔진이 맡는다.
 * 창 상태는 창 고유 전역 이름 공간 `_global.quickOptionModal` 에 둔다. 창은 계속 마운트돼 있어
 * 화면 상태에 쓰면 다시 열 때 앞 값이 남는다(7.1.1). 여는 쪽이 이 키를 null 로 비운 뒤 채운다.
 */
/** 옵션 선택 창 이름 (레이아웃 `partials/shop/_modal_quick_option` 의 창) */
export declare const QUICK_OPTION_MODAL_ID = "quick_option_modal";
/** 카드가 넘기는 상품 (목록 응답의 한 항목) */
export interface QuickActionProduct {
    id: number;
    product_code?: string;
    /** 구매할 수 있는 옵션 수 (옛 응답에는 없다) */
    options_count?: number;
    has_options?: boolean;
    has_additional_options?: boolean;
    min_purchase_qty?: number;
    is_wishlisted?: boolean;
    [key: string]: unknown;
}
interface QuickActionDefinition {
    handler?: string;
    params?: {
        product?: QuickActionProduct;
    } & Record<string, unknown>;
    [key: string]: unknown;
}
/**
 * 카드 상품을 고를 것 없이 곧바로 담을 수 있는가.
 *
 * 값을 모르면(옛 이커머스 응답) 담지 않는다 — 옵션이 있는 상품을 수량만으로 보내면 서버가 거절한다.
 *
 * @param product 카드 상품
 * @returns 곧바로 담을 수 있으면 true
 */
export declare function canAddDirectly(product: QuickActionProduct): boolean;
/**
 * 옵션 선택 창을 연다.
 *
 * 창 상태를 null 로 먼저 비우고(앞 상품의 줄이 남지 않게) 상세를 받아 채운 뒤 연다.
 * 옵션이 하나 이하인 상품은 그 옵션으로 고정 줄 하나를 미리 채운다 — 창에서는 추가옵션과 수량만 고른다.
 *
 * @param action 액션 정의 (params.product)
 * @returns 창을 열었으면 true
 */
export declare function openQuickOptionHandler(action: QuickActionDefinition, _context?: unknown): Promise<boolean>;
/**
 * 카드에서 장바구니에 담는다.
 *
 * 고를 것이 없는 상품만 곧바로 담고, 그 밖은 옵션 선택 창으로 넘긴다. 수량은 최소 구매 수량이다 —
 * 그보다 적게 보내면 서버가 거절한다. 장바구니 키가 아직 없으면 기존 발급 경로로 먼저 받는다.
 *
 * @param action 액션 정의 (params.product)
 * @returns 담았으면 true (창으로 넘겼거나 실패면 false)
 */
export declare function quickAddToCartHandler(action: QuickActionDefinition, _context?: unknown): Promise<boolean>;
/**
 * 카드에서 찜을 켜거나 끈다.
 *
 * 비회원은 요청 없이 로그인 안내만 한다. 결과는 찜 공유 저장소에 두어 같은 상품의 다른 카드도 함께 바뀐다.
 *
 * @param action 액션 정의 (params.product)
 * @returns 바뀐 찜 여부 (비회원·실패면 null)
 */
export declare function toggleWishlistQuickHandler(action: QuickActionDefinition, _context?: unknown): Promise<boolean | null>;
export {};
