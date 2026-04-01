import { default as React } from 'react';
/** Alert 박스 타입 */
export type ConfirmModalAlertType = 'warning' | 'danger' | 'info';
/** Alert 박스 설정 */
export interface ConfirmModalAlert {
    /** Alert 타입 (warning, danger, info) - 기본값: warning */
    type?: ConfirmModalAlertType;
    /** Alert 제목 (예: "경고") */
    title?: string;
    /** Alert 내용 (문자열 배열은 리스트로, ReactNode는 그대로 렌더링) */
    content: string[] | React.ReactNode;
}
export interface ConfirmModalProps {
    /** 모달 열림 상태 */
    isOpen: boolean;
    /** 모달 닫기 핸들러 */
    onClose: () => void;
    /** 모달 제목 (기본값: common.confirm) */
    title?: string;
    /** 확인 메시지 */
    message: string | React.ReactNode;
    /** 확인 버튼 텍스트 (기본값: common.confirm) */
    confirmText?: string;
    /** 취소 버튼 텍스트 (기본값: common.cancel) */
    cancelText?: string;
    /** 확인 버튼 클릭 핸들러 */
    onConfirm: () => void;
    /** 취소 버튼 클릭 핸들러 */
    onCancel?: () => void;
    /** 확인 버튼 variant */
    confirmButtonVariant?: 'primary' | 'secondary' | 'danger' | 'ghost';
    /** 로딩 중 여부 (확인 버튼 비활성화 및 텍스트 변경) */
    isLoading?: boolean;
    /** 로딩 중 확인 버튼에 표시할 텍스트 */
    loadingText?: string;
    /** 모달 너비 (기본값: 400px) */
    width?: string;
    /** 경고/추가 정보 영역 (Alert 박스) */
    alert?: ConfirmModalAlert;
    /** 추가 경고 메시지 (빨간색 텍스트로 표시) */
    warningText?: string;
}
/**
 * ConfirmModal 컴포넌트
 *
 * 사용자에게 확인을 요청하는 모달 다이얼로그 컴포넌트.
 * 확인/취소 버튼을 제공하며, 로딩 상태를 지원합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "ConfirmModal",
 *   "props": {
 *     "isOpen": "{{modals.saveConfirm}}",
 *     "title": "확인",
 *     "message": "변경사항을 저장하시겠습니까?",
 *     "confirmText": "$t:common.confirm",
 *     "cancelText": "$t:common.cancel"
 *   }
 * }
 */
export declare const ConfirmModal: React.FC<ConfirmModalProps>;
