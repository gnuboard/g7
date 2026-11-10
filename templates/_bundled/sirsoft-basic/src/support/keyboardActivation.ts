import type React from 'react';

/**
 * 키보드로 누르기 — 단추가 아닌 요소를 단추처럼 쓸 때 Enter 와 Space 로 클릭과 같은 동작을 한다(#869 키보드 접근).
 *
 * Space 는 페이지 스크롤이 기본 동작이라 막는다. 처리한 키는 위로 번지지 않게 해 감싼 요소나 문서 키 처리(메뉴의 Enter 등)가
 * 같은 동작을 한 번 더 하지 않게 한다.
 *
 * @param handler 클릭과 같은 동작(키보드 이벤트를 받는다)
 * @returns onKeyDown 처리기
 */
export const activateOnKey =
  (handler?: (event: React.KeyboardEvent) => void) =>
  (event: React.KeyboardEvent): void => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    // 안쪽 입력칸·단추에서 온 키는 그 요소 몫이다
    if (event.target !== event.currentTarget) return;
    event.preventDefault();
    event.stopPropagation();
    handler?.(event);
  };

/** 키보드 포커스 표시 — 마우스 클릭에는 나타나지 않는다(폭과 무관) */
export const FOCUS_VISIBLE_RING = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500';

/**
 * 클릭을 받는 비단추 요소에 줄 키보드 접근 속성. 클릭 처리기가 없으면 아무것도 주지 않는다.
 *
 * @param onClick 클릭 처리기
 * @param options role(기본 button), 비활성 여부
 * @returns role, tabIndex, aria-disabled, onKeyDown
 */
export function keyboardActivationProps(
  onClick: ((event: any) => void) | undefined,
  options: { role?: string; disabled?: boolean } = {},
): { role?: string; tabIndex?: number; 'aria-disabled'?: boolean; onKeyDown?: (event: React.KeyboardEvent) => void } {
  if (!onClick) return {};
  return {
    role: options.role ?? 'button',
    tabIndex: options.disabled ? -1 : 0,
    ...(options.disabled ? { 'aria-disabled': true } : {}),
    onKeyDown: options.disabled ? undefined : activateOnKey(onClick),
  };
}

/**
 * 펼친 목록 닫기 — 목록 안 어디에서든(검색칸, 항목) Esc 로 닫고 여는 단추로 포커스를 돌린다(#869 키보드 접근).
 *
 * 여는 단추와 목록을 함께 감싼 요소의 onKeyDown 에 단다(포털로 그린 목록도 React 트리로 올라온다). 처리한 Esc 는 위로
 * 번지지 않게 해 목록을 품은 창(Modal)이 같은 Esc 로 닫히지 않게 한다 — 여는 단추에는 Modal 이 「펼친 팝업」으로
 * 알아보도록 `aria-haspopup` 과 `aria-expanded` 를 함께 단다. 닫혀 있으면 아무것도 하지 않는다(Esc 가 창까지 간다).
 *
 * @param open 목록이 열려 있는가
 * @param close 목록을 닫는 동작
 * @param returnTo 포커스를 돌릴 여는 단추. 없으면 포커스를 옮기지 않는다(검색어 제안처럼 입력칸에 남는 경우)
 * @returns onKeyDown 처리기
 */
export const escapeToClose =
  (open: boolean, close: () => void, returnTo?: React.RefObject<HTMLElement | null>) =>
  (event: React.KeyboardEvent): void => {
    if (event.key !== 'Escape' || !open) return;
    event.preventDefault();
    event.stopPropagation();
    close();
    returnTo?.current?.focus();
  };

/** 메뉴 안에서 포커스를 받을 수 있는 항목(role=menuitem, 잠긴 항목 제외) */
export const menuItemsIn = (menu: HTMLElement | null | undefined): HTMLElement[] =>
  Array.from(menu?.querySelectorAll<HTMLElement>('[role="menuitem"]:not([aria-disabled="true"])') ?? []);

/**
 * 메뉴의 첫 항목이나 마지막 항목으로 포커스를 옮긴다. 메뉴는 스크롤하면 닫히는 일이 많아 화면을 굴리지 않는다.
 *
 * @param menu 메뉴 요소
 * @param edge 첫 항목(first)인지 마지막 항목(last)인지
 */
export const focusMenuEdge = (menu: HTMLElement | null | undefined, edge: 'first' | 'last'): void => {
  const items = menuItemsIn(menu);
  (edge === 'first' ? items[0] : items[items.length - 1])?.focus({ preventScroll: true });
};

/**
 * 여는 단추의 키 — ArrowDown 은 열고 첫 항목, ArrowUp 은 열고 마지막 항목으로 간다. Enter 와 Space 는 단추가 click 으로
 * 바꾸므로 열기만 표시해 두고(첫 항목), 실제 여닫기는 click 처리기가 맡는다. 열린 뒤 어느 항목으로 갈지는 `pendingEdge` 에 남긴다.
 *
 * @param open 메뉴가 열려 있는가
 * @param setOpen 메뉴를 여는 동작
 * @param pendingEdge 열린 뒤 포커스를 옮길 끝을 담는 ref
 * @param menuRef 열려 있을 때 바로 옮길 메뉴 요소
 * @returns onKeyDown 처리기
 */
export const menuTriggerKeyDown =
  (
    open: boolean,
    setOpen: (open: boolean) => void,
    pendingEdge: React.MutableRefObject<'first' | 'last' | null>,
    menuRef: React.RefObject<HTMLElement | null>,
  ) =>
  (event: React.KeyboardEvent): void => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const edge = event.key === 'ArrowDown' ? 'first' : 'last';
      if (open) {
        focusMenuEdge(menuRef.current, edge);
        return;
      }
      pendingEdge.current = edge;
      setOpen(true);
      return;
    }
    if ((event.key === 'Enter' || event.key === ' ') && !open) {
      pendingEdge.current = 'first';
    }
  };

/**
 * 메뉴 안의 키 — WAI-ARIA 메뉴 단추 관례. 화살표는 항목 사이를 돌고, Home 과 End 는 처음과 끝, Enter 와 Space 는 항목을
 * 실행하고 여는 단추로 돌아간다(링크의 Ctrl, Meta, Shift+Enter 는 브라우저의 새 탭 열기에 맡긴다). Esc 는 닫고 여는 단추로
 * 돌아간다. Tab 은 닫고 포커스를 여는 단추에 둔다 — 메뉴는 포털로 문서 끝에 붙어 있어, 그냥 두면 Tab 이 메뉴 밖 다음 자리가
 * 아니라 문서 끝으로 간다. 단추에 둔 뒤 기본 Tab 이동이 단추 다음 자리로 간다(관리자 ActionMenu 와 같은 규칙, #869 8차 8부).
 *
 * @param menuRef 메뉴 요소
 * @param triggerRef 여는 단추
 * @param close 메뉴를 닫는 동작
 * @returns onKeyDown 처리기
 */
export const menuKeyDown =
  (menuRef: React.RefObject<HTMLElement | null>, triggerRef: React.RefObject<HTMLElement | null>, close: () => void) =>
  (event: React.KeyboardEvent): void => {
    const items = menuItemsIn(menuRef.current);
    const index = items.indexOf(document.activeElement as HTMLElement);
    const backToTrigger = () => {
      close();
      triggerRef.current?.focus({ preventScroll: true });
    };

    switch (event.key) {
      case 'ArrowDown':
      case 'ArrowUp': {
        event.preventDefault();
        if (items.length === 0) return;
        const step = event.key === 'ArrowDown' ? 1 : -1;
        items[(index + step + items.length) % items.length].focus({ preventScroll: true });
        return;
      }
      case 'Home':
      case 'End':
        event.preventDefault();
        focusMenuEdge(menuRef.current, event.key === 'Home' ? 'first' : 'last');
        return;
      case 'Enter':
      case ' ': {
        const target = items[index];
        if (!target) return;
        if (event.key === 'Enter' && target.tagName === 'A' && (event.ctrlKey || event.metaKey || event.shiftKey)) return;
        event.preventDefault();
        target.click();
        if (triggerRef.current?.isConnected) triggerRef.current.focus({ preventScroll: true });
        return;
      }
      case 'Escape':
        event.preventDefault();
        event.stopPropagation();
        backToTrigger();
        return;
      case 'Tab':
        backToTrigger();
        return;
      default:
    }
  };
