import { default as React } from 'react';
export interface ModalProps {
    isOpen: boolean;
    onClose: (event?: Event | React.SyntheticEvent) => void;
    title?: string;
    /**
     * 타이틀 옆에 표시할 아이콘 이름 (Font Awesome)
     * @example "lock", "trash", "warning"
     */
    icon?: string;
    /**
     * 아이콘 색상 클래스 (Tailwind)
     * @default "text-gray-500"
     */
    iconClassName?: string;
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
     * 본문 스크롤 밖 고정 하단에 그릴 컴포넌트 배열(확인·취소 단추). `.g7.html` 의 `<footer>`(`<prop-footer>`).
     *
     * 본문 자식과 같은 데이터 컨텍스트·상태 범위로 그린다 — 단추가 창을 연 뒤 입력한 값을 읽는다.
     * 렌더 폭 640px 이하에서는 세로로 쌓고 폭을 채운다(관리자 템플릿 Modal 과 같은 계약).
     */
    footer?: any[];
    /** DynamicRenderer용 데이터 컨텍스트 (내부 사용) */
    __dataContext?: Record<string, any>;
    /** DynamicRenderer용 번역 컨텍스트 (내부 사용) */
    __translationContext?: any;
    /**
     * 엔진이 자동 주입하는 부모 컴포넌트 컨텍스트 (내부 사용) — 렌더 환경(레이아웃 편집기 미리보기의 레지스트리·
     * dispatcher·편집 모드)과 Modal 노드의 확장 데이터 컨텍스트를 실어 온다.
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
        /** Modal 노드의 확장 데이터 컨텍스트 — 본문 자식이 받는 dataContext 와 같은 객체 */
        parentDataContext?: Record<string, any>;
        /**
         * Modal 노드의 편집기 경로(레이아웃 편집기 미리보기에서만 값이 있다). footer 단추마다
         * `${componentPath}.props.footer.${index}` 를 넘겨 편집기가 footer 단추를 고르게 한다(공개 #141).
         */
        componentPath?: string;
        [key: string]: any;
    };
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
}
/**
 * `size` 단계 → 실제 너비 매핑.
 *
 * `md`/`medium` 은 종전 기본값 `500px` 과 같은 값이다 — 이 표면을 뒤늦게 살릴 때
 * 그 두 단계를 쓰던 화면이 한 픽셀도 움직이지 않도록 일부러 맞췄다.
 */
export declare const MODAL_SIZE_WIDTHS: Record<string, string>;
export declare const Modal: React.FC<ModalProps>;
