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
