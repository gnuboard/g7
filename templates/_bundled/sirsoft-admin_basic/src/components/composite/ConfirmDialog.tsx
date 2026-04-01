import React from 'react';
import { Modal } from './Modal';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { P } from '../basic/P';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

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
export const ConfirmDialog: React.FC<ConfirmDialogProps> = ({
  isOpen,
  onClose,
  title,
  message,
  confirmText,
  cancelText,
  onConfirm,
  onCancel,
  confirmButtonVariant = 'primary',
  // cancelButtonVariant는 현재 고정 스타일 사용으로 미사용
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  cancelButtonVariant: _cancelButtonVariant = 'secondary',
  width = '500px',
  // closeOnOverlay는 Modal에서 처리하므로 미사용
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  closeOnOverlay: _closeOnOverlay = true,
  isLoading = false,
  loadingText,
}) => {
  // props로 전달된 값이 없으면 다국어 키 사용
  const resolvedConfirmText = confirmText ?? t('common.confirm');
  const resolvedCancelText = cancelText ?? t('common.cancel');
  const resolvedLoadingText = loadingText ?? t('common.loading');

  const handleConfirm = () => {
    onConfirm();
  };

  const handleCancel = () => {
    // 로딩 중에는 취소 불가
    if (isLoading) return;
    onCancel?.();
    onClose();
  };

  const handleClose = () => {
    // 로딩 중에는 닫기 불가
    if (isLoading) return;
    onClose();
  };

  // 버튼 variant별 스타일 매핑
  const confirmVariantClassMap: Record<'primary' | 'secondary' | 'danger' | 'ghost', string> = {
    primary: 'bg-blue-600 text-white hover:bg-blue-700',
    secondary: 'bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600',
    danger: 'bg-red-600 text-white hover:bg-red-700',
    ghost: 'bg-transparent text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700',
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={handleClose}
      title={title}
      width={width}
    >
      {/* 본문 */}
      <Div className="py-2">
        {typeof message === 'string' ? (
          <P className="text-gray-700 dark:text-gray-300">{message}</P>
        ) : (
          message
        )}
      </Div>

      {/* 푸터 (버튼 영역) */}
      <Div className="flex justify-end gap-3 mt-6">
        {/* 취소 버튼 */}
        <Button
          onClick={handleCancel}
          disabled={isLoading}
          className="px-4 py-2 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <Span>{resolvedCancelText}</Span>
        </Button>

        {/* 확인 버튼 */}
        <Button
          onClick={handleConfirm}
          disabled={isLoading}
          className={`flex items-center gap-2 px-4 py-2 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed ${confirmVariantClassMap[confirmButtonVariant]}`}
        >
          {isLoading && (
            <Icon name={IconName.Spinner} className="w-4 h-4 animate-spin" />
          )}
          <Span>{isLoading ? resolvedLoadingText : resolvedConfirmText}</Span>
        </Button>
      </Div>
    </Modal>
  );
};
