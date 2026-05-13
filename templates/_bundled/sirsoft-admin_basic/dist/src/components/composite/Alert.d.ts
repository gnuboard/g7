import { default as React } from 'react';
/**
 * 알림 타입
 */
export type AlertType = 'info' | 'success' | 'warning' | 'error';
export interface AlertProps {
    /**
     * 알림 타입
     */
    type: AlertType;
    /**
     * 알림 메시지
     */
    message: string;
    /**
     * 닫기 버튼 표시 여부
     */
    dismissible?: boolean;
    /**
     * 닫기 버튼 클릭 시 콜백
     */
    onDismiss?: () => void;
    /**
     * 사용자 정의 클래스
     */
    className?: string;
}
/**
 * Alert 알림 컴포넌트
 *
 * 알림 메시지를 표시하는 composite 컴포넌트입니다.
 * type에 따라 다른 색상과 아이콘을 표시하며 dismissible 옵션을 지원합니다.
 *
 * @example
 * // 정보 알림
 * <Alert type="info" message="정보 메시지입니다." />
 *
 * // 성공 알림 (닫기 버튼 포함)
 * <Alert
 *   type="success"
 *   message="작업이 완료되었습니다."
 *   dismissible
 *   onDismiss={() => console.log('dismissed')}
 * />
 *
 * // 경고 알림
 * <Alert type="warning" message="주의가 필요합니다." />
 *
 * // 에러 알림
 * <Alert type="error" message="오류가 발생했습니다." />
 */
export declare const Alert: React.FC<AlertProps>;
