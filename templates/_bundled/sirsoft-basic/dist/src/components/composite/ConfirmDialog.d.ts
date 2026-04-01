import { default as React } from 'react';
export interface ConfirmDialogProps {
    isOpen: boolean;
    onClose: () => void;
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    cancelText?: string;
    onConfirm: () => void;
    onCancel?: () => void;
    confirmButtonVariant?: 'primary' | 'secondary' | 'danger' | 'ghost';
    cancelButtonVariant?: 'primary' | 'secondary' | 'danger' | 'ghost';
    width?: string;
    closeOnOverlay?: boolean;
    /** 로딩 중 여부 (확인 버튼 비활성화 및 텍스트 변경) */
    isLoading?: boolean;
    /** 로딩 중 확인 버튼에 표시할 텍스트 */
    loadingText?: string;
}
/**
 * ConfirmDialog 집합 컴포넌트
 *
 * Modal 기반 확인/취소 다이얼로그 컴포넌트.
 * 사용자에게 확인을 요청하고 확인/취소 중 하나를 선택하도록 합니다.
 *
 * 기본 컴포넌트 조합:
 * - Modal > P(message) + Button(확인) + Button(취소)
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "ConfirmDialog",
 *   "props": {
 *     "isOpen": "{{dialogs.deleteConfirm}}",
 *     "title": "삭제 확인",
 *     "message": "정말 이 항목을 삭제하시겠습니까?",
 *     "confirmText": "삭제",
 *     "cancelText": "$t:common.cancel",
 *     "confirmButtonVariant": "danger"
 *   }
 * }
 */
export declare const ConfirmDialog: React.FC<ConfirmDialogProps>;
