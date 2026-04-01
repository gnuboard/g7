/**
 * sirsoft-ecommerce 모듈 타입 정의
 */

/**
 * 액션 컨텍스트 인터페이스
 *
 * ActionDispatcher에서 핸들러 실행 시 전달되는 컨텍스트입니다.
 */
export interface ActionContext {
    /** 현재 로컬 상태 가져오기 */
    getLocalState?: () => Record<string, any>;
    /** 로컬 상태 업데이트 */
    setLocalState?: (updates: Record<string, any>) => void;
    /** 전역 상태 가져오기 */
    getGlobalState?: () => Record<string, any>;
    /** 전역 상태 업데이트 */
    setGlobalState?: (updates: Record<string, any>) => void;
    /** 라우트 파라미터 */
    route?: Record<string, string>;
    /** 쿼리 파라미터 */
    query?: Record<string, string>;
    /** 네비게이션 함수 */
    navigate?: (path: string) => void;
    /** 이벤트 객체 */
    event?: Event;
    /** 데이터 컨텍스트 */
    dataContext?: Record<string, any>;
}

/**
 * 상품 인터페이스
 */
export interface Product {
    id: number;
    name: string;
    price: number;
    stock_quantity?: number;
    status?: string;
    options?: ProductOption[];
    _modified?: boolean;
}

/**
 * 상품 옵션 인터페이스
 */
export interface ProductOption {
    id: number;
    name: string;
    price?: number;
    stock_quantity?: number;
    _modified?: boolean;
}

/**
 * 주문 인터페이스
 */
export interface Order {
    id: number;
    order_number: string;
    order_status: string;
    order_status_label?: string;
    order_status_variant?: string;
    total_amount: number;
    total_paid_amount?: number;
    ordered_at: string;
    paid_at?: string;
    user?: {
        id: number;
        name: string;
        email: string;
    };
    shipping_address?: OrderAddress;
    payment?: OrderPayment;
    options?: OrderOption[];
    shippings?: OrderShipping[];
}

/**
 * 주문 주소 인터페이스
 */
export interface OrderAddress {
    id: number;
    orderer_name?: string;
    orderer_phone?: string;
    recipient_name?: string;
    recipient_phone?: string;
    recipient_zipcode?: string;
    recipient_address?: string;
    recipient_address_detail?: string;
    recipient_country_code?: string;
}

/**
 * 주문 결제 인터페이스
 */
export interface OrderPayment {
    id: number;
    payment_method: string;
    payment_method_label?: string;
    payment_status: string;
    payment_status_label?: string;
    amount: number;
    paid_at?: string;
}

/**
 * 주문 옵션 인터페이스
 */
export interface OrderOption {
    id: number;
    product_id: number;
    product_name: string;
    option_name?: string;
    quantity: number;
    unit_price: number;
    total_price: number;
    option_status: string;
    option_status_label?: string;
}

/**
 * 주문 배송 인터페이스
 */
export interface OrderShipping {
    id: number;
    shipping_type: string;
    shipping_type_label?: string;
    shipping_status: string;
    shipping_status_label?: string;
    carrier_id?: number;
    carrier_name?: string;
    tracking_number?: string;
    shipped_at?: string;
    delivered_at?: string;
}
