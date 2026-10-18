import React, { useEffect, useId, useRef } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

export interface ModalProps {
  isOpen: boolean;
  onClose: (event?: Event | React.SyntheticEvent) => void;
  title?: string;
  /**
   * 타이틀 옆에 표시할 아이콘 이름 (Font Awesome)
   * @example "lock", "trash", "warning"
   */
  icon?: string;
  /**
   * 아이콘 색상 클래스 (Tailwind)
   * @default "text-gray-500"
   */
  iconClassName?: string;
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
  /**
   * 이름 있는 크기 단계 — `width` 를 직접 적지 않고 고르는 자리.
   *
   * `width` 를 함께 주면 `width` 가 이긴다(기존 `width` 사용처 무영향). 알 수 없는 값은
   * 기본값으로 떨어져 작성 오타가 화면을 깨뜨리지 않는다. 컨테이너에 CSS
   * `max-w-[calc(100vw-2rem)]` 이 걸려 있어 좁은 화면 넘침은 스타일 계층이 막는다.
   */
  size?: 'sm' | 'small' | 'md' | 'medium' | 'lg' | 'large' | 'xl' | '2xl';
  width?: string;
  className?: string;
  style?: React.CSSProperties;
}

/**
 * `size` 단계 → 실제 너비 매핑.
 *
 * `md`/`medium` 은 종전 기본값 `500px` 과 같은 값이다 — 이 표면을 뒤늦게 살릴 때
 * 그 두 단계를 쓰던 화면이 한 픽셀도 움직이지 않도록 일부러 맞췄다.
 */
export const MODAL_SIZE_WIDTHS: Record<string, string> = {
  sm: '400px',
  small: '400px',
  md: '500px',
  medium: '500px',
  lg: '720px',
  large: '720px',
  xl: '900px',
  '2xl': '1120px',
};

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
/** 열린 모달 순서(맨 뒤가 맨 위) — Esc 는 맨 위 창만 처리한다. */
const openModalStack: symbol[] = [];

export const Modal: React.FC<ModalProps> = ({
  isOpen,
  onClose,
  title,
  icon,
  iconClassName = 'text-gray-500 dark:text-gray-400',
  closeOnBackdropClick = true,
  closeOnOverlayClick,
  showCloseButton = true,
  closeOnEscape = true,
  children,
  size,
  width,
  className = '',
  style,
}) => {
  // 너비 결정 순서: 명시 width > size 단계 > 종전 기본값.
  // size 를 모르는 값으로 적어도 기본값으로 떨어져 화면이 깨지지 않는다.
  const resolvedWidth = width ?? (size ? MODAL_SIZE_WIDTHS[size] : undefined) ?? '500px';

  // closeOnOverlayClick 별칭 지원 (레이아웃 JSON 호환)
  const shouldCloseOnBackdrop = closeOnOverlayClick !== undefined
    ? closeOnOverlayClick
    : closeOnBackdropClick;
  const modalRef = useRef<HTMLDivElement>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);
  // 제목 id 는 창마다 달라야 한다 — 고정 `modal-title` 이면 겹친 창의 이름이 문서에서 먼저 나온
  // 아래 창 제목으로 읽혔다(#736 F 실측, 관리자 템플릿과 같은 구현).
  const titleId = `modal-title-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;

  // ESC 키로 모달 닫기 — 맨 위 창 하나만 닫는다(#736 S2 — 열린 창마다 리스너가 있어 Esc 한 번에 전부 닫혔다).
  // 순서는 열린 시점으로 정하므로 onClose·closeOnEscape 는 ref 로 읽어 다시 그려져도 순서가 바뀌지 않게 한다.
  const onCloseRef = useRef(onClose);
  const closeOnEscapeRef = useRef(closeOnEscape);
  useEffect(() => {
    onCloseRef.current = onClose;
    closeOnEscapeRef.current = closeOnEscape;
  });

  useEffect(() => {
    if (!isOpen) return;

    const token = Symbol('modal');
    openModalStack.push(token);

    // 창 안에서 펼친 팝업(선택 목록·검색 드롭다운·작업 메뉴 등)이 있으면 이 Esc 는 팝업 몫이다 — 팝업이 자기 리스너로 닫는다.
    // 창과 팝업이 각자 Esc 를 받아, 목록을 펼친 채 누른 Esc 한 번에 창까지 닫혀 저장 안 한 입력이 사라졌다(7.1.0 O-K3-16,
    // g7 D-J3 와 같은 결함, admin_basic 대칭). 아코디언처럼 aria-haspopup 없는 펼침은 팝업이 아니므로 막지 않는다.
    // 자기 onKeyDown 으로 닫는 팝업은 브라우저에서 이 리스너보다 먼저 닫힌 상태가 그려지므로, 키를 누른 순간(캡처 단계)의
    // 펼침 여부를 함께 본다.
    const hasOpenPopup = () => Boolean(
      modalRef.current?.querySelector('[aria-expanded="true"][aria-haspopup]:not([aria-haspopup="false"])')
    );
    let popupOpenAtKeyDown = false;
    const notePopupOnKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') popupOpenAtKeyDown = hasOpenPopup();
    };

    const handleEscape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape' || openModalStack[openModalStack.length - 1] !== token) return;
      const popupWasOpen = popupOpenAtKeyDown;
      popupOpenAtKeyDown = false;
      if (popupWasOpen || hasOpenPopup()) return;
      if (closeOnEscapeRef.current) {
        // 닫기 처리기가 템플릿 엔진 액션 처리기일 수 있다 — 엔진은 이벤트를 받는다고 보고 event.type 을
        // 읽으므로 닫기(X)·바깥 클릭처럼 원본 이벤트를 넘긴다(빈 인자면 TypeError 로 창이 닫히지 않았다).
        onCloseRef.current(event);
      }
    };

    document.addEventListener('keydown', notePopupOnKeyDown, true);
    document.addEventListener('keydown', handleEscape);
    return () => {
      document.removeEventListener('keydown', notePopupOnKeyDown, true);
      document.removeEventListener('keydown', handleEscape);
      const index = openModalStack.indexOf(token);
      if (index !== -1) openModalStack.splice(index, 1);
    };
  }, [isOpen]);

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
        className={`relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-h-[90vh] max-w-[calc(100vw-2rem)] overflow-hidden ${className}`}
        style={{ width: resolvedWidth, ...(style ? { ...style, zIndex: undefined } : {}) }}
        onClick={(e) => e.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? titleId : undefined}
      >
        {/* Header */}
        {(title || icon || showCloseButton) && (
          <Div className="flex items-center justify-between p-4">
            {(title || icon) && (
              <Div
                id={titleId}
                className="flex items-center gap-3 text-lg font-semibold text-gray-900 dark:text-white"
              >
                {icon && <Icon name={icon} size="md" className={iconClassName} />}
                {title}
              </Div>
            )}
            {showCloseButton && (
              <Button
                onClick={onClose}
                // 사용자 템플릿 기반 스타일은 button 에 손가락 커서를 주지 않는다 — 같은 창의 「취소」·확인 버튼처럼
                // 직접 단다(7.1.0 실측 O-K2-8: 닫기만 cursor default).
                className="ml-auto text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none cursor-pointer"
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
