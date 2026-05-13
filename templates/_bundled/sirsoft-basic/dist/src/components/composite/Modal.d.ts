import { default as React } from 'react';
export interface ModalProps {
    isOpen: boolean;
    onClose: () => void;
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
    width?: string;
    className?: string;
    style?: React.CSSProperties;
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
export declare const Modal: React.FC<ModalProps>;
