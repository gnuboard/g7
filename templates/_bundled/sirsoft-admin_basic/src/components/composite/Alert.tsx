// e2e:allow편집기 추가 직후 type 미지정 시 크래시("컴포넌트 로드 실패") 결함#4 수정(typeConfig 폴백). 라이브 검증은 Chrome MCP T1~T7(에디터 추가/속성편집/저장200/reload영속/게스트 사용자화면 렌더)로 수행, 단위 회귀는 Alert.test.tsx 결함#4 describe.
import React from 'react';
import { Div } from '../basic/Div';
import { P } from '../basic/P';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import type { EditorAttrs } from '../../types';

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

  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /**
   * 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread)
   */
  editorAttrs?: EditorAttrs;
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
  id,
  editorAttrs,
}) => {
  // 타입별 시맨틱 클래스 및 아이콘 매핑
  const typeConfig = {
    info: {
      containerClass: 'alert-info',
      iconClass: 'alert-icon-info',
      textClass: 'alert-text-info',
      icon: IconName.InfoCircle,
    },
    success: {
      containerClass: 'alert-success',
      iconClass: 'alert-icon-success',
      textClass: 'alert-text-success',
      icon: IconName.CheckCircle,
    },
    warning: {
      containerClass: 'alert-warning',
      iconClass: 'alert-icon-warning',
      textClass: 'alert-text-warning',
      icon: IconName.ExclamationTriangle,
    },
    error: {
      containerClass: 'alert-error',
      iconClass: 'alert-icon-error',
      textClass: 'alert-text-error',
      icon: IconName.TimesCircle,
    },
  };

  // type 미지정/미지원 값일 때도 크래시하지 않도록 info 로 폴백한다(레이아웃 편집기에서
  // 컴포넌트를 추가한 직후 작성자가 type 을 지정하기 전 상태를 안전하게 렌더).
  const config = typeConfig[type] ?? typeConfig.info;

  return (
    <Div
      className={`${config.containerClass} ${className}`}
      role="alert"
      aria-live="polite"
      id={id} {...editorAttrs}
    >
      {/* 아이콘 */}
      <Icon
        name={config.icon}
        className={config.iconClass}
        ariaLabel={`${type} icon`}
      />

      {/* 메시지 */}
      <P className={config.textClass}>
        {message}
      </P>

      {/* 닫기 버튼 (dismissible이 true일 때만 표시) */}
      {dismissible && (
        <Button
          onClick={onDismiss}
          className={`alert-dismiss ${config.textClass}`}
          aria-label="Close alert"
        >
          <Icon
            name={IconName.Times}
            className={config.iconClass}
            ariaLabel="Close"
          />
        </Button>
      )}
    </Div>
  );
};
