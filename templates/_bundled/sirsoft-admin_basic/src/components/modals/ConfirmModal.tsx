import React, { useEffect, useRef } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { P } from '../basic/P';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

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
export const ConfirmModal: React.FC<ConfirmModalProps> = ({
  isOpen,
  onClose,
  title,
  message,
  confirmText,
  cancelText,
  onConfirm,
  onCancel,
  confirmButtonVariant = 'primary',
  isLoading = false,
  loadingText,
  width = '400px',
  alert,
  warningText,
}) => {
  const modalRef = useRef<HTMLDivElement>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);

  // props로 전달된 값이 없으면 다국어 키 사용
  const resolvedTitle = title ?? t('common.confirm');
  const resolvedConfirmText = confirmText ?? t('common.confirm');
  const resolvedCancelText = cancelText ?? t('common.cancel');
  const resolvedLoadingText = loadingText ?? t('common.loading');

  // ESC 키로 모달 닫기
  useEffect(() => {
    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && isOpen && !isLoading) {
        onClose();
      }
    };

    if (isOpen) {
      document.addEventListener('keydown', handleEscape);
      return () => document.removeEventListener('keydown', handleEscape);
    }
  }, [isOpen, isLoading, onClose]);

  // 포커스 트랩
  useEffect(() => {
    if (isOpen && modalRef.current) {
      // 모달 열릴 때 현재 포커스 저장
      previousFocusRef.current = document.activeElement as HTMLElement;

      // 모달 내부 포커스 가능한 요소들
      const focusableElements = modalRef.current.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      );

      const firstElement = focusableElements[0] as HTMLElement;
      const lastElement = focusableElements[focusableElements.length - 1] as HTMLElement;

      // 첫 번째 요소에 포커스
      firstElement?.focus();

      // Tab 키 핸들러 (포커스 트랩)
      const handleTab = (event: KeyboardEvent) => {
        if (event.key !== 'Tab') return;

        if (event.shiftKey) {
          // Shift + Tab
          if (document.activeElement === firstElement) {
            event.preventDefault();
            lastElement?.focus();
          }
        } else {
          // Tab
          if (document.activeElement === lastElement) {
            event.preventDefault();
            firstElement?.focus();
          }
        }
      };

      document.addEventListener('keydown', handleTab);

      return () => {
        document.removeEventListener('keydown', handleTab);
        // 모달 닫힐 때 이전 포커스 복원
        previousFocusRef.current?.focus();
      };
    }
  }, [isOpen]);

  // body 스크롤 방지
  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
      return () => {
        document.body.style.overflow = '';
      };
    }
  }, [isOpen]);

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

  const handleOverlayClick = () => {
    // 로딩 중에는 오버레이 클릭으로 닫기 불가
    if (isLoading) return;
    onClose();
  };

  // 버튼 variant별 스타일 매핑 (스크린샷 기준: 어두운 확인 버튼)
  const confirmVariantClassMap: Record<'primary' | 'secondary' | 'danger' | 'ghost', string> = {
    primary: 'bg-gray-900 dark:bg-gray-700 text-white hover:bg-gray-800 dark:hover:bg-gray-600',
    secondary: 'bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600',
    danger: 'bg-red-600 text-white hover:bg-red-700',
    ghost: 'bg-transparent text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700',
  };

  // Alert 타입별 스타일 매핑
  const alertStyleMap: Record<ConfirmModalAlertType, { container: string; icon: string; title: string; text: string }> = {
    warning: {
      container: 'bg-yellow-50 dark:bg-yellow-900/20 border-l-4 border-yellow-400 dark:border-yellow-500',
      icon: 'text-yellow-600 dark:text-yellow-400',
      title: 'text-yellow-800 dark:text-yellow-300',
      text: 'text-yellow-700 dark:text-yellow-300',
    },
    danger: {
      container: 'bg-red-50 dark:bg-red-900/20 border-l-4 border-red-400 dark:border-red-500',
      icon: 'text-red-600 dark:text-red-400',
      title: 'text-red-800 dark:text-red-300',
      text: 'text-red-700 dark:text-red-300',
    },
    info: {
      container: 'bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-400 dark:border-blue-500',
      icon: 'text-blue-600 dark:text-blue-400',
      title: 'text-blue-800 dark:text-blue-300',
      text: 'text-blue-700 dark:text-blue-300',
    },
  };

  // Alert 렌더링 함수
  const renderAlert = () => {
    if (!alert) return null;

    const alertType = alert.type ?? 'warning';
    const styles = alertStyleMap[alertType];

    return (
      <Div className={`mt-4 p-3 rounded ${styles.container}`}>
        {/* Alert 헤더 (아이콘 + 제목) */}
        {alert.title && (
          <Div className="flex items-center gap-2 mb-2">
            <svg
              className={`w-5 h-5 ${styles.icon}`}
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
              />
            </svg>
            <Span className={`font-medium ${styles.title}`}>{alert.title}</Span>
          </Div>
        )}
        {/* Alert 내용 */}
        <Div className={styles.text}>
          {Array.isArray(alert.content) ? (
            <ul className="list-disc list-inside space-y-1 text-sm">
              {alert.content.map((item, index) => (
                <li key={index}>{item}</li>
              ))}
            </ul>
          ) : (
            alert.content
          )}
        </Div>
      </Div>
    );
  };

  if (!isOpen) return null;

  return (
    <Div
      className="fixed inset-0 flex items-center justify-center"
      style={{ zIndex: 50 }}
      onClick={handleOverlayClick}
    >
      {/* Overlay */}
      <Div
        className="absolute inset-0 bg-black/50"
        aria-hidden="true"
      />

      {/* Modal Content */}
      <Div
        ref={modalRef}
        className="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl"
        style={{ width }}
        onClick={(e) => e.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirm-modal-title"
      >
        {/* Header */}
        <Div className="flex items-center justify-between p-4 pb-2">
          <Div
            id="confirm-modal-title"
            className="text-lg font-semibold text-gray-900 dark:text-white"
          >
            {resolvedTitle}
          </Div>
          <Button
            onClick={handleClose}
            disabled={isLoading}
            className="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
            aria-label={t('common.close_modal')}
          >
            <svg
              className="w-5 h-5"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M6 18L18 6M6 6l12 12"
              />
            </svg>
          </Button>
        </Div>

        {/* Body */}
        <Div className="px-4 py-4">
          {/* 메시지 */}
          {typeof message === 'string' ? (
            <P className="text-gray-700 dark:text-gray-300">{message}</P>
          ) : (
            message
          )}

          {/* Alert 영역 */}
          {renderAlert()}

          {/* 추가 경고 텍스트 */}
          {warningText && (
            <P className="mt-4 text-sm text-red-600 dark:text-red-400">{warningText}</P>
          )}
        </Div>

        {/* Footer (버튼 영역) */}
        <Div className="flex justify-end gap-3 p-4 pt-2">
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
      </Div>
    </Div>
  );
};
