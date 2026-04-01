import React, { useEffect, useRef } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

export interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title?: string;
  /** 타이틀 앞에 렌더링할 컴포넌트 배열 (레이아웃 JSON) */
  titlePrefix?: any[];
  /** 타이틀 뒤에 렌더링할 컴포넌트 배열 (레이아웃 JSON) */
  titleSuffix?: any[];
  /**
   * Backdrop(오버레이) 클릭 시 모달 닫기 여부
   * @default true
   */
  closeOnBackdropClick?: boolean;
  /**
   * closeOnOverlayClick 별칭 (레이아웃 JSON 호환)
   * @default true
   */
  closeOnOverlayClick?: boolean;
  /**
   * 헤더 닫기 버튼 표시 여부
   * @default true
   */
  showCloseButton?: boolean;
  /**
   * ESC 키로 모달 닫기 허용 여부
   * @default true
   */
  closeOnEscape?: boolean;
  children?: React.ReactNode;
  width?: string;
  className?: string;
  style?: React.CSSProperties;
  /** DynamicRenderer용 데이터 컨텍스트 (내부 사용) */
  __dataContext?: Record<string, any>;
  /** DynamicRenderer용 번역 컨텍스트 (내부 사용) */
  __translationContext?: any;
}

/**
 * Modal 집합 컴포넌트
 *
 * Div + Button 기본 컴포넌트를 조합하여 모달 UI를 구성합니다.
 * ESC 키 닫기, 포커스 트랩, 오버레이 클릭 닫기 기능을 포함합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Modal",
 *   "props": {
 *     "isOpen": "{{modals.userEdit}}",
 *     "title": "사용자 편집",
 *     "width": "600px"
 *   },
 *   "children": [...]
 * }
 */
export const Modal: React.FC<ModalProps> = ({
  isOpen,
  onClose,
  title,
  titlePrefix,
  titleSuffix,
  closeOnBackdropClick = true,
  closeOnOverlayClick,
  showCloseButton = true,
  closeOnEscape = true,
  children,
  width = '500px',
  className = '',
  style,
  __dataContext,
  __translationContext,
}) => {
  // closeOnOverlayClick 별칭 지원 (레이아웃 JSON 호환)
  const shouldCloseOnBackdrop = closeOnOverlayClick !== undefined
    ? closeOnOverlayClick
    : closeOnBackdropClick;
  const modalRef = useRef<HTMLDivElement>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);

  // ESC 키로 모달 닫기
  useEffect(() => {
    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && isOpen && closeOnEscape) {
        onClose();
      }
    };

    if (isOpen && closeOnEscape) {
      document.addEventListener('keydown', handleEscape);
      return () => document.removeEventListener('keydown', handleEscape);
    }
  }, [isOpen, onClose, closeOnEscape]);

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

  if (!isOpen) return null;

  // z-index를 style에서 가져오거나 기본값 50 사용
  const zIndex = style?.zIndex ?? 50;

  // titlePrefix/titleSuffix 렌더링 헬퍼
  const renderTitleComponents = (components: any[] | undefined, keyPrefix: string) => {
    if (!components || components.length === 0) return null;

    const g7Core = (window as any).G7Core;
    if (!g7Core) return null;

    const DynamicRenderer = g7Core.getDynamicRenderer?.();
    if (!DynamicRenderer) return null;

    return components.map((componentDef, index) => (
      <DynamicRenderer
        key={`${keyPrefix}-${index}`}
        componentDef={componentDef}
        dataContext={__dataContext || {}}
        translationContext={__translationContext}
        registry={g7Core.getComponentRegistry()}
        bindingEngine={g7Core.getDataBindingEngine()}
        translationEngine={g7Core.getTranslationEngine()}
        actionDispatcher={g7Core.getActionDispatcher()}
        isRootRenderer={false}
      />
    ));
  };

  return (
    <Div
      className="fixed inset-0 flex items-center justify-center"
      style={{ zIndex }}
      onClick={shouldCloseOnBackdrop ? onClose : undefined}
    >
      {/* Overlay */}
      <Div
        className="absolute inset-0 bg-black/50"
        aria-hidden="true"
      />

      {/* Modal Content */}
      <Div
        ref={modalRef}
        className={`relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-h-[90vh] overflow-hidden ${className}`}
        style={{ width, ...(style ? { ...style, zIndex: undefined } : {}) }}
        onClick={(e) => e.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? 'modal-title' : undefined}
      >
        {/* Header */}
        {(title || titlePrefix || titleSuffix || showCloseButton) && (
          <Div className="flex items-center justify-between p-4">
            <Div className="flex items-center gap-2">
              {/* titlePrefix: 타이틀 앞에 렌더링 */}
              {renderTitleComponents(titlePrefix, 'title-prefix')}
              {title && (
                <Div
                  id="modal-title"
                  className="text-lg font-semibold text-gray-900 dark:text-white"
                >
                  {title}
                </Div>
              )}
              {/* titleSuffix: 타이틀 뒤에 렌더링 */}
              {renderTitleComponents(titleSuffix, 'title-suffix')}
            </Div>
            {showCloseButton && (
              <Button
                onClick={onClose}
                className="ml-auto text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none"
                aria-label={t('common.close_modal')}
              >
                <svg
                  className="w-6 h-6"
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
            )}
          </Div>
        )}

        {/* Body */}
        <Div className="px-6 py-4 overflow-y-auto max-h-[calc(90vh-140px)]">
          {children}
        </Div>
      </Div>
    </Div>
  );
};
