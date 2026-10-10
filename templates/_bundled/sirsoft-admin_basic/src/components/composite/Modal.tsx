import React, { useEffect, useId, useRef } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

export interface ModalProps {
  isOpen: boolean;
  onClose: (event?: Event | React.SyntheticEvent) => void;
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
  /**
   * 본문 아래 고정 영역에 렌더링할 컴포넌트 배열 (레이아웃 JSON)
   *
   * 확인/취소 같은 하단 액션 버튼 자리다. `children` 은 `.modal-body-content` 안이라 내용이 길면
   * 함께 스크롤되어 버튼이 화면 밖으로 밀리는데, 이 영역은 스크롤 밖에 있어 항상 보인다.
   */
  footer?: any[];
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
  /** DynamicRenderer용 데이터 컨텍스트 (내부 사용) */
  __dataContext?: Record<string, any>;
  /** DynamicRenderer용 번역 컨텍스트 (내부 사용) */
  __translationContext?: any;
  /**
   * 엔진이 자동 주입하는 부모 컴포넌트 컨텍스트 (내부 사용)
   *
   * `renderEnvRef.current` 의 레지스트리·엔진·dispatcher·편집 모드로 titlePrefix/titleSuffix/footer 를
   * 그린다 — 레이아웃 편집기 미리보기에서 호스트 값 대신 미리보기 값을 따른다(#141).
   */
  __componentContext?: {
    renderEnvRef?: {
      current: {
        componentRegistry?: any;
        bindingEngine?: any;
        translationEngine?: any;
        actionDispatcher?: any;
        isEditMode?: boolean;
      } | null;
    };
  };
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

/** 열린 모달 순서(맨 뒤가 맨 위) — Esc 는 맨 위 창만 처리한다. */
const openModalStack: symbol[] = [];

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
  footer,
  size,
  width,
  className = '',
  style,
  __dataContext,
  __translationContext,
  __componentContext,
}) => {
  // closeOnOverlayClick 별칭 지원 (레이아웃 JSON 호환)
  // 너비 결정 순서: 명시 width > size 단계 > 종전 기본값.
  // size 를 모르는 값으로 적어도 기본값으로 떨어져 화면이 깨지지 않는다.
  const resolvedWidth = width ?? (size ? MODAL_SIZE_WIDTHS[size] : undefined) ?? '500px';

  const shouldCloseOnBackdrop = closeOnOverlayClick !== undefined
    ? closeOnOverlayClick
    : closeOnBackdropClick;
  const modalRef = useRef<HTMLDivElement>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);
  // 제목 id 는 창마다 달라야 한다 — 고정 `modal-title` 이면 겹친 창(정보 위 라이선스 등)의 이름이
  // 문서에서 먼저 나온 아래 창 제목으로 읽혔다(#736 F 실측). 클래스 `modal-title` 은 그대로 둔다.
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
    // g7 D-J3 와 같은 결함). 아코디언처럼 aria-haspopup 없는 펼침은 팝업이 아니므로 막지 않는다.
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

  // titlePrefix/titleSuffix/footer 렌더링 헬퍼 (레이아웃 JSON 컴포넌트 배열 → DynamicRenderer)
  const renderLayoutComponents = (components: any[] | undefined, keyPrefix: string) => {
    if (!components || components.length === 0) return null;

    const g7Core = (window as any).G7Core;
    if (!g7Core) return null;

    const DynamicRenderer = g7Core.getDynamicRenderer?.();
    if (!DynamicRenderer) return null;

    // 렌더 환경(이 Modal 을 그린 렌더러) > 호스트 값. 편집 모드면 액션을 바인딩하지 않는다.
    // componentPath 는 넘기지 않는다 — `footer` 배열은 편집기 경로 문법(children/iteration/responsive) 밖이다(#141).
    const env = __componentContext?.renderEnvRef?.current ?? null;

    return components.map((componentDef, index) => (
      <DynamicRenderer
        key={`${keyPrefix}-${index}`}
        componentDef={componentDef}
        dataContext={__dataContext || {}}
        translationContext={__translationContext}
        registry={env?.componentRegistry ?? g7Core.getComponentRegistry()}
        bindingEngine={env?.bindingEngine ?? g7Core.getDataBindingEngine()}
        translationEngine={env?.translationEngine ?? g7Core.getTranslationEngine()}
        actionDispatcher={env?.actionDispatcher ?? g7Core.getActionDispatcher()}
        isRootRenderer={false}
        isEditMode={env?.isEditMode}
      />
    ));
  };

  return (
    <Div
      className="modal-wrapper"
      style={{ zIndex }}
      onClick={shouldCloseOnBackdrop ? onClose : undefined}
    >
      {/* Overlay */}
      <Div
        className="modal-backdrop"
        aria-hidden="true"
      />

      {/* Modal Content */}
      <Div
        ref={modalRef}
        className={`modal-container ${className}`}
        style={{ width: resolvedWidth, ...(style ? { ...style, zIndex: undefined } : {}) }}
        onClick={(e) => e.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? titleId : undefined}
      >
        {/* Header */}
        {(title || titlePrefix || titleSuffix || showCloseButton) && (
          <Div className="modal-header-row">
            <Div className="flex-center gap-sm">
              {/* titlePrefix: 타이틀 앞에 렌더링 */}
              {renderLayoutComponents(titlePrefix, 'title-prefix')}
              {title && (
                <Div
                  id={titleId}
                  className="modal-title"
                >
                  {title}
                </Div>
              )}
              {/* titleSuffix: 타이틀 뒤에 렌더링 */}
              {renderLayoutComponents(titleSuffix, 'title-suffix')}
            </Div>
            {showCloseButton && (
              <Button
                onClick={onClose}
                className="modal-close-btn"
                aria-label={t('common.close_modal')}
              >
                <svg
                  className="icon-lg"
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
        <Div className="modal-body-content">
          {children}
        </Div>

        {/* Footer — 본문 스크롤 영역 밖이라 내용이 길어도 액션 버튼이 항상 보인다 */}
        {footer && footer.length > 0 && (
          <Div className="modal-footer-row">
            {renderLayoutComponents(footer, 'footer')}
          </Div>
        )}
      </Div>
    </Div>
  );
};
