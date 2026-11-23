import React, { useEffect, useId, useRef } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { useViewportWidth } from '../layout/viewportWidth';

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
   * 본문 스크롤 밖 고정 하단에 그릴 컴포넌트 배열(확인·취소 단추). `.g7.html` 의 `<footer>`(`<prop-footer>`).
   *
   * 본문 자식과 같은 데이터 컨텍스트·상태 범위로 그린다 — 단추가 창을 연 뒤 입력한 값을 읽는다.
   * 렌더 폭 640px 이하에서는 세로로 쌓고 폭을 채운다(관리자 템플릿 Modal 과 같은 계약, #869).
   */
  footer?: any[];
  /** DynamicRenderer용 데이터 컨텍스트 (내부 사용) */
  __dataContext?: Record<string, any>;
  /** DynamicRenderer용 번역 컨텍스트 (내부 사용) */
  __translationContext?: any;
  /**
   * 엔진이 자동 주입하는 부모 컴포넌트 컨텍스트 (내부 사용) — 렌더 환경(레이아웃 편집기 미리보기의 레지스트리·
   * dispatcher·편집 모드)과 Modal 노드의 확장 데이터 컨텍스트를 실어 온다.
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
    /** Modal 노드의 확장 데이터 컨텍스트 — 본문 자식이 받는 dataContext 와 같은 객체 */
    parentDataContext?: Record<string, any>;
    /**
     * Modal 노드의 편집기 경로(레이아웃 편집기 미리보기에서만 값이 있다). footer 단추마다
     * `${componentPath}.props.footer.${index}` 를 넘겨 편집기가 footer 단추를 고르게 한다(#141).
     */
    componentPath?: string;
    [key: string]: any;
  };
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
/** 좁은 화면 상한(px, 포함) — 이 폭 이하에서는 바닥 단추를 세로로 쌓고 폭을 채운다(관리자 Modal 과 같은 값) */
const NARROW_FOOTER_MAX_WIDTH = 640;

/** 열린 모달 순서(맨 뒤가 맨 위) — Esc 는 맨 위 창만 처리한다. */
const openModalStack: symbol[] = [];

export const Modal: React.FC<ModalProps> = ({
  isOpen,
  onClose,
  title,
  icon,
  iconClassName = 'text-muted',
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
  // 바닥 단추 줄 배치를 고르는 렌더 폭 — 엔진 폭(편집기 미리보기에서는 기기 폭) 우선
  const renderWidth = useViewportWidth();
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
    // 창과 팝업이 각자 Esc 를 받아, 목록을 펼친 채 누른 Esc 한 번에 창까지 닫혀 저장 안 한 입력이 사라졌다(7.1.0,
    // 다른 화면과 같은 결함, admin_basic 대칭). 아코디언처럼 aria-haspopup 없는 펼침은 팝업이 아니므로 막지 않는다.
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

  // footer 렌더링 헬퍼 (레이아웃 JSON 컴포넌트 배열 → DynamicRenderer)
  const renderFooter = (components: any[] | undefined) => {
    if (!components || components.length === 0) return null;

    const g7Core = (window as any).G7Core;
    const DynamicRenderer = g7Core?.getDynamicRenderer?.();
    if (!DynamicRenderer) return null;

    // 렌더 환경(이 Modal 을 그린 렌더러) > 호스트 값. 편집 모드면 액션을 바인딩하지 않는다.
    const env = __componentContext?.renderEnvRef?.current ?? null;
    // 편집기 경로 — 엔진이 실어 준 Modal 노드 경로 뒤에 `.props.footer.{index}` 를 붙인다(#141).
    // 편집기는 이 표기를 `props.footer` 배열의 항목으로 읽어 children 과 같게 고르고 고친다.
    // 경로가 없으면(일반 화면) 넘기지 않는다.
    const ownerPath = __componentContext?.componentPath;
    // 데이터 컨텍스트와 상태 범위는 엔진이 본문 자식을 그릴 때와 같게 준다 — Modal 노드의 확장 컨텍스트와 컴포넌트 컨텍스트
    const footerDataContext = __dataContext ?? __componentContext?.parentDataContext ?? {};

    return components.map((componentDef, index) => (
      <DynamicRenderer
        key={`footer-${index}`}
        componentDef={componentDef}
        {...(ownerPath ? { componentPath: `${ownerPath}.props.footer.${index}` } : {})}
        dataContext={footerDataContext}
        parentComponentContext={__componentContext}
        translationContext={__translationContext}
        registry={env?.componentRegistry ?? g7Core.getComponentRegistry?.()}
        bindingEngine={env?.bindingEngine ?? g7Core.getDataBindingEngine?.()}
        translationEngine={env?.translationEngine ?? g7Core.getTranslationEngine?.()}
        actionDispatcher={env?.actionDispatcher ?? g7Core.getActionDispatcher?.()}
        isRootRenderer={false}
        isEditMode={env?.isEditMode}
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
                // 직접 단다(7.1.0 실측: 닫기만 cursor default).
                className="ml-auto text-muted hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none cursor-pointer"
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

        {/* Footer — 본문 스크롤 영역 밖이라 내용이 길어도 단추가 항상 보인다.
            640 이하에서는 단추를 세로로 쌓고 폭을 채운다 — 폭 미디어가 없는 바탕(`modal-footer-bar`)에 렌더 폭으로 더한다.
            단추가 각자 `if` 로 모두 빠지면 줄이 비므로 `empty:hidden` 으로 테두리와 여백까지 숨긴다(#869 C1) */}
        {footer && footer.length > 0 && (
          <Div className={renderWidth <= NARROW_FOOTER_MAX_WIDTH ? 'modal-footer-bar empty:hidden flex-col gap-2 [&_button]:w-full' : 'modal-footer-bar empty:hidden'}>
            {renderFooter(footer)}
          </Div>
        )}
      </Div>
    </Div>
  );
};
