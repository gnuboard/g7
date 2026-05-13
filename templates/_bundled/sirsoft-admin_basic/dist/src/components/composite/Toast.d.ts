import { default as React } from 'react';
export type ToastType = 'success' | 'error' | 'warning' | 'info';
export type ToastPosition = 'top-left' | 'top-center' | 'top-right' | 'bottom-left' | 'bottom-center' | 'bottom-right';
export interface ToastItem {
    id: string | number;
    type: ToastType;
    message: string;
    /** 커스텀 아이콘 이름 (지정하지 않으면 타입별 기본 아이콘 사용) */
    icon?: string;
    /** 개별 토스트 자동 닫힘 시간 (ms) */
    duration?: number;
}
export interface ToastProps {
    /** 토스트 배열 (전역 상태 _global.toasts) */
    toasts?: ToastItem[] | null;
    /** 토스트 표시 위치 */
    position?: ToastPosition;
    /** 기본 자동 닫힘 시간 (ms, 기본 3000) */
    duration?: number;
    /** 토스트 제거 콜백 (전역 상태 업데이트용) */
    onRemove?: (id: string | number) => void;
}
/**
 * Toast 컴포넌트
 *
 * 전역 상태(_global.toasts)를 구독하여 토스트 알림을 스택으로 표시하는 컴포넌트.
 * ActionDispatcher의 toast 핸들러와 연동됩니다.
 *
 * 기능:
 * - 다중 토스트 스택 지원
 * - 타입별 스타일 (success, error, warning, info)
 * - 커스텀 아이콘 지정 가능
 * - 개별 토스트 자동 닫힘 시간 설정
 * - 수동 닫기 버튼
 *
 * @example
 * // 레이아웃 JSON 사용 예시 (_admin_base.json)
 * {
 *   "id": "global_toast",
 *   "type": "composite",
 *   "name": "Toast",
 *   "props": {
 *     "toasts": "{{_global.toasts}}",
 *     "position": "bottom-center",
 *     "duration": 3000
 *   }
 * }
 *
 * @example
 * // toast 핸들러 호출 예시 (레이아웃 JSON)
 * {
 *   "handler": "toast",
 *   "params": {
 *     "type": "success",
 *     "message": "$t:admin.users.modals.bulk_activate_success",
 *     "icon": "check",
 *     "duration": 5000
 *   }
 * }
 */
export declare const Toast: React.FC<ToastProps>;
