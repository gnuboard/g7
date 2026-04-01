import React from 'react';
import { Dialog, DialogAction } from './Dialog';
import { P } from '../basic/P';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

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
export const AlertDialog: React.FC<AlertDialogProps> = ({
  isOpen,
  onClose,
  title,
  message,
  confirmText,
  onConfirm,
  confirmButtonVariant = 'primary',
  width = '500px',
  closeOnOverlay = true,
}) => {
  // props로 전달된 값이 없으면 다국어 키 사용
  const resolvedConfirmText = confirmText ?? t('common.confirm');

  const handleConfirm = () => {
    onConfirm?.();
    onClose();
  };

  const actions: DialogAction[] = [
    {
      label: resolvedConfirmText,
      onClick: handleConfirm,
      variant: confirmButtonVariant,
    },
  ];

  // message가 문자열인 경우 P 컴포넌트로 래핑
  const content = typeof message === 'string' ? (
    <P className="text-gray-700 dark:text-gray-300">{message}</P>
  ) : (
    message
  );

  return (
    <Dialog
      isOpen={isOpen}
      onClose={onClose}
      title={title}
      content={content}
      actions={actions}
      width={width}
      closeOnOverlay={closeOnOverlay}
    />
  );
};
