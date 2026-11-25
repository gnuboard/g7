/**
 * BottomSheet 컴포넌트
 *
 * 좁은 화면 아래에서 올라오는 시트. 창(`Modal`)과 달리 **화면 레이아웃 안에 그대로 두는** 컨테이너라
 * 안쪽 내용이 그 화면의 상태를 그대로 읽고 쓴다 — 상품 상세의 옵션 선택을 본문 구매 카드와 시트가 함께 쓴다.
 *
 * - 닫혀 있으면 DOM 에 없다. 넓은 화면(768px 이상)에서는 열려 있어도 보이지 않는다(본문에서 이어 간다).
 *   좁은지 넓은지는 창 폭(CSS 미디어)이 아니라 엔진 폭(편집기 미리보기에서는 기기 폭)으로 가른다. 넓은 화면에서
 *   숨은 동안에는 초점 이동·가두기·Esc 도 쉬고 아래 body 표시도 남기지 않는다.
 * - 대화 상자로 동작한다: 제목으로 이름 붙이고, 열면 안으로 초점을 옮기고 닫으면 연 단추로 되돌리며,
 *   Tab 이 밖으로 나가지 않는다. 배경 누름·Esc·닫기 단추로 닫는다.
 * - 좁은 화면에서 열려 있는 동안 `body` 에 `g7-bottom-sheet-open` 을 남긴다. 스타일시트가 그 표시를 보고
 *   본문 스크롤을 잠그고 하단 구매바를 숨긴다(시트 바닥의 단추가 그 일을 대신한다).
 */

import React, { useEffect, useId, useRef } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { H3 } from '../basic/H3';
import { Icon } from '../basic/Icon';
import type { EditorAttrs } from '../../types';
import { BREAKPOINTS, useViewportWidth } from '../layout/viewportWidth';

export interface BottomSheetProps {
  /** 열림 여부 */
  open?: boolean;
  /** 시트 제목 */
  title?: string;
  /** 닫기 단추 이름 (기본: 공통 「닫기」 문구) */
  closeLabel?: string;
  /** 닫으려 할 때 (배경 누름·Esc·닫기 단추) */
  onClose?: () => void;
  /** 추가 CSS 클래스 (시트 본체) */
  className?: string;
  /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
  /** 시트 내용 */
  children?: React.ReactNode;
}

const BODY_CLASS = 'g7-bottom-sheet-open';
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** 보이는(좁은 화면에서 열린) 시트 수 — 마지막 시트가 닫히거나 숨을 때만 body 표시를 지운다 */
let openSheets = 0;

const t = (key: string): string => (window as any).G7Core?.t?.(key) ?? key;

/**
 * 좁은 화면 바텀시트
 *
 * @example
 * ```html
 * <bottom-sheet data-type="composite" open="{{!!_local.buySheetOpen}}" title="$t:shop.product.select_options">
 *   <on event="onClose"><set-state target="local" :buySheetOpen="false"/></on>
 *   …
 * </bottom-sheet>
 * ```
 */
export const BottomSheet: React.FC<BottomSheetProps> = ({ open = false, title, closeLabel, onClose, className = '', id, editorAttrs, children }) => {
  const dialogRef = useRef<HTMLDivElement>(null);
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;
  const titleId = useId();
  const isNarrow = useViewportWidth() < BREAKPOINTS.md;
  const active = open && isNarrow;

  useEffect(() => {
    if (!active) return;

    const opener = document.activeElement as HTMLElement | null;
    const dialog = dialogRef.current;
    openSheets += 1;
    document.body.classList.add(BODY_CLASS);

    // 첫 칸으로 초점 — 칸이 없으면 시트 자체로
    const first = dialog?.querySelector<HTMLElement>(FOCUSABLE);
    (first ?? dialog)?.focus();

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        onCloseRef.current?.();
        return;
      }
      if (event.key !== 'Tab' || !dialog) return;

      const focusables = Array.from(dialog.querySelectorAll<HTMLElement>(FOCUSABLE));
      if (focusables.length === 0) {
        event.preventDefault();
        dialog.focus();
        return;
      }
      const firstEl = focusables[0];
      const lastEl = focusables[focusables.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === firstEl || !dialog.contains(active))) {
        event.preventDefault();
        lastEl.focus();
      } else if (!event.shiftKey && (active === lastEl || !dialog.contains(active))) {
        event.preventDefault();
        firstEl.focus();
      }
    };
    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      openSheets = Math.max(0, openSheets - 1);
      if (openSheets === 0) document.body.classList.remove(BODY_CLASS);
      // 연 단추가 아직 화면에 있으면 그리로 돌려보낸다
      if (opener && document.contains(opener)) opener.focus();
    };
  }, [active]);

  if (!open) return null;

  return (
    <Div
      data-bottom-sheet=""
      id={id}
      className={isNarrow ? 'fixed inset-0 z-40 flex flex-col justify-end' : 'hidden'}
      {...(editorAttrs as Record<string, unknown>)}
    >
      {/* 배경 — 누르면 닫는다. 장식이라 보조 기술에는 숨긴다(닫기 단추가 따로 있다) */}
      <Div data-bottom-sheet-backdrop="" aria-hidden="true" onClick={() => onClose?.()} className="absolute inset-0 bg-black/50 dark:bg-black/70" />
      <Div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        className={`relative flex flex-col w-full max-h-[85vh] rounded-t-2xl bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-xl pb-[env(safe-area-inset-bottom)] animate-[g7-sheet-up_200ms_ease-out] motion-reduce:animate-none focus:outline-none ${className}`.trim()}
      >
        {/* 손잡이 막대 (장식) */}
        <Div aria-hidden="true" className="mx-auto mt-2 h-1 w-10 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600" />
        <Div className="flex shrink-0 items-center justify-between gap-3 px-4 pt-2 pb-3 border-b border-gray-200 dark:border-gray-700">
          <H3 id={titleId} className="min-w-0 truncate text-base font-semibold text-gray-900 dark:text-white">
            {title}
          </H3>
          <Button
            type="button"
            data-bottom-sheet-close=""
            aria-label={closeLabel ?? t('common.close')}
            onClick={() => onClose?.()}
            className="shrink-0 w-9 h-9 inline-flex items-center justify-center rounded-lg text-muted hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer"
          >
            <Icon name="x" />
          </Button>
        </Div>
        {/* 본문 — 머리는 고정하고 여기만 스크롤한다 */}
        <Div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4">{children}</Div>
      </Div>
    </Div>
  );
};

export default BottomSheet;
