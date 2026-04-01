import { default as React } from 'react';
export interface AlertDialogProps {
    isOpen: boolean;
    onClose: () => void;
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    onConfirm?: () => void;
    confirmButtonVariant?: 'primary' | 'secondary' | 'danger' | 'ghost';
    width?: string;
    closeOnOverlay?: boolean;
}
/**
 * AlertDialog 집합 컴포넌트
 *
 * Dialog 기반 단일 확인 알림 다이얼로그 컴포넌트.
 * 사용자에게 정보를 전달하고 확인만 받습니다.
 *
 * 기본 컴포넌트 조합:
 * - Dialog > P(message) + Button(확인)
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "AlertDialog",
 *   "props": {
 *     "isOpen": "{{dialogs.successAlert}}",
 *     "title": "성공",
 *     "message": "작업이 성공적으로 완료되었습니다.",
 *     "confirmText": "$t:common.confirm",
 *     "confirmButtonVariant": "primary"
 *   }
 * }
 */
export declare const AlertDialog: React.FC<AlertDialogProps>;
