import { default as React } from 'react';
export interface ModalProps {
    isOpen: boolean;
    onClose: (event?: Event | React.SyntheticEvent) => void;
    title?: string;
    /** 타이틀 앞에 렌더링할 컴포넌트 배열 (레이아웃 JSON) */
    titlePrefix?: any[];
    /** 타이틀 뒤에 렌더링할 컴포넌트 배열 (레이아웃 JSON) */
    titleSuffix?: any[];
    /**
     * Backdrop(오버레이) 클릭 시 모달 닫기 여부
     * @default true
     */
    closeOnBackdropClick?: boolean;
    /**
     * closeOnOverlayClick 별칭 (레이아웃 JSON 호환)
     * @default true
     */
    closeOnOverlayClick?: boolean;
    /**
     * 헤더 닫기 버튼 표시 여부
     * @default true
     */
    showCloseButton?: boolean;
    /**
     * ESC 키로 모달 닫기 허용 여부
     * @default true
     */
    closeOnEscape?: boolean;
    children?: React.ReactNode;
    /**
     * 본문 아래 고정 영역에 렌더링할 컴포넌트 배열 (레이아웃 JSON)
     *
     * 확인/취소 같은 하단 액션 버튼 자리다. `children` 은 `.modal-body-content` 안이라 내용이 길면
     * 함께 스크롤되어 버튼이 화면 밖으로 밀리는데, 이 영역은 스크롤 밖에 있어 항상 보인다.
     */
    footer?: any[];
    /**
     * 이름 있는 크기 단계 — `width` 를 직접 적지 않고 고르는 자리.
     *
     * `width` 를 함께 주면 `width` 가 이긴다(기존 `width` 사용처 무영향). 알 수 없는 값은
     * 기본값으로 떨어져 작성 오타가 화면을 깨뜨리지 않는다. 컨테이너에 CSS
     * `max-w-[calc(100vw-2rem)]` 이 걸려 있어 좁은 화면 넘침은 스타일 계층이 막는다.
     */
    size?: 'sm' | 'small' | 'md' | 'medium' | 'lg' | 'large' | 'xl' | '2xl';
    width?: string;
    className?: string;
    style?: React.CSSProperties;
    /** DynamicRenderer용 데이터 컨텍스트 (내부 사용) */
    __dataContext?: Record<string, any>;
    /** DynamicRenderer용 번역 컨텍스트 (내부 사용) */
    __translationContext?: any;
    /**
     * 엔진이 자동 주입하는 부모 컴포넌트 컨텍스트 (내부 사용)
     *
     * `renderEnvRef.current` 의 레지스트리·엔진·dispatcher·편집 모드로 titlePrefix/titleSuffix/footer 를
     * 그린다 — 레이아웃 편집기 미리보기에서 호스트 값 대신 미리보기 값을 따른다(#141).
     */
    __componentContext?: {
        renderEnvRef?: {
            current: {
                componentRegistry?: any;
                bindingEngine?: any;
                translationEngine?: any;
                actionDispatcher?: any;
                isEditMode?: boolean;
            } | null;
        };
    };
}
/**
 * Modal 집합 컴포넌트
 *
 * Div + Button 기본 컴포넌트를 조합하여 모달 UI를 구성합니다.
 * ESC 키 닫기, 포커스 트랩, 오버레이 클릭 닫기 기능을 포함합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Modal",
 *   "props": {
 *     "isOpen": "{{modals.userEdit}}",
 *     "title": "사용자 편집",
 *     "width": "600px"
 *   },
 *   "children": [...]
 * }
 */
/**
 * `size` 단계 → 실제 너비 매핑.
 *
 * `md`/`medium` 은 종전 기본값 `500px` 과 같은 값이다 — 이 표면을 뒤늦게 살릴 때
 * 그 두 단계를 쓰던 화면이 한 픽셀도 움직이지 않도록 일부러 맞췄다.
 */
export declare const MODAL_SIZE_WIDTHS: Record<string, string>;
export declare const Modal: React.FC<ModalProps>;
