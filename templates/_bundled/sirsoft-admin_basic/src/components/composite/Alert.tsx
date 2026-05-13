import React from 'react';
import { Div } from '../basic/Div';
import { P } from '../basic/P';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

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
export const Alert: React.FC<AlertProps> = ({
  type,
  message,
  dismissible = false,
  onDismiss,
  className = '',
}) => {
  // 타입별 색상 및 아이콘 매핑
  const typeConfig = {
    info: {
      bgColor: 'bg-blue-100 dark:bg-blue-900',
      borderColor: 'border-blue-400 dark:border-blue-600',
      textColor: 'text-blue-800 dark:text-blue-200',
      iconColor: 'text-blue-600 dark:text-blue-400',
      icon: IconName.InfoCircle,
    },
    success: {
      bgColor: 'bg-green-100 dark:bg-green-900',
      borderColor: 'border-green-400 dark:border-green-600',
      textColor: 'text-green-800 dark:text-green-200',
      iconColor: 'text-green-600 dark:text-green-400',
      icon: IconName.CheckCircle,
    },
    warning: {
      bgColor: 'bg-yellow-100 dark:bg-yellow-900',
      borderColor: 'border-yellow-400 dark:border-yellow-600',
      textColor: 'text-yellow-800 dark:text-yellow-200',
      iconColor: 'text-yellow-600 dark:text-yellow-400',
      icon: IconName.ExclamationTriangle,
    },
    error: {
      bgColor: 'bg-red-100 dark:bg-red-900',
      borderColor: 'border-red-400 dark:border-red-600',
      textColor: 'text-red-800 dark:text-red-200',
      iconColor: 'text-red-600 dark:text-red-400',
      icon: IconName.TimesCircle,
    },
  };

  const config = typeConfig[type];

  // 컨테이너 클래스 조합
  const containerClasses = `
    flex items-start gap-3 p-4 rounded-lg border
    ${config.bgColor}
    ${config.borderColor}
    ${className}
  `.trim().replace(/\s+/g, ' ');

  return (
    <Div
      className={containerClasses}
      role="alert"
      aria-live="polite"
    >
      {/* 아이콘 */}
      <Icon
        name={config.icon}
        className={`${config.iconColor} flex-shrink-0 mt-0.5`}
        ariaLabel={`${type} icon`}
      />

      {/* 메시지 */}
      <P className={`flex-1 ${config.textColor} text-sm`}>
        {message}
      </P>

      {/* 닫기 버튼 (dismissible이 true일 때만 표시) */}
      {dismissible && (
        <Button
          onClick={onDismiss}
          className={`${config.textColor} hover:opacity-70 flex-shrink-0 bg-transparent border-0 p-0 cursor-pointer`}
          aria-label="Close alert"
        >
          <Icon
            name={IconName.Times}
            className={config.iconColor}
            ariaLabel="Close"
          />
        </Button>
      )}
    </Div>
  );
};
