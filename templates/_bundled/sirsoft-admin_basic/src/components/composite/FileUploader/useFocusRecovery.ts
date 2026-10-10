/**
 * 업로더 초점 되찾기 훅
 *
 * 항목을 지우거나(「삭제」 단추가 목록에서 사라짐), 빈 드롭존에서 파일을 골라 드롭존이 목록으로 바뀌거나,
 * 「추가」 타일로 골라 한도가 차서 타일이 사라지면 초점을 쥔 요소가 DOM 에서 빠져 초점이 문서 맨 위(BODY)로
 * 떨어진다(7.1.0 O-M3-1, O-G3-2). 그때 업로더 안의 가까운 자리로 초점을 돌린다.
 *
 * - 항목 단추였으면: 같은 자리의 항목(지웠으면 다음 항목), 없으면 이전 항목, 둘 다 없으면 「추가」 자리
 *   (「추가」 타일, 목록이 비면 드롭존)
 * - 「추가」 타일·드롭존이었으면: 「추가」 자리, 없으면(한도 도달) 새로 추가된 첫 항목
 *
 * 초점이 업로더 밖의 다른 요소로 나가거나 업로더 밖을 누른 뒤에는 기록을 지워 초점을 끌어오지 않는다.
 *
 * @module composite/FileUploader/useFocusRecovery
 */

import { useEffect, useRef, type RefObject } from 'react';

/** 초점을 받는 업로더 요소의 구실 */
export type UploaderFocusAction = 'pick' | 'add' | 'primary' | 'drag' | 'download' | 'retry' | 'remove';

/** 마지막으로 초점을 쥔 업로더 요소 */
interface FocusRecord {
  /** 초점을 쥔 요소 */
  element: HTMLElement;
  /** 요소의 구실 */
  action: UploaderFocusAction;
  /** 항목 단추면 그 항목의 순번, 아니면 -1 */
  index: number;
  /** 그때의 항목 수 */
  count: number;
}

const ITEM_SELECTOR = '[data-uploader-item]';

/**
 * 업로더 루트 안의 항목 요소 목록.
 *
 * @param root 업로더 루트
 * @returns 항목 요소 목록(화면 순서)
 */
function itemsOf(root: HTMLElement): HTMLElement[] {
  return Array.from(root.querySelectorAll<HTMLElement>(ITEM_SELECTOR));
}

/**
 * 항목 안에서 초점을 줄 단추를 고른다 — 같은 구실 단추, 없으면 「삭제」, 없으면 쓸 수 있는 첫 단추.
 *
 * @param item 항목 요소
 * @param action 앞서 초점을 쥐었던 단추의 구실
 * @returns 초점을 줄 단추 또는 null
 */
function controlIn(item: HTMLElement | undefined, action: UploaderFocusAction): HTMLElement | null {
  if (!item) return null;
  return (
    item.querySelector<HTMLElement>(`[data-uploader-action="${action}"]:not(:disabled)`)
    ?? item.querySelector<HTMLElement>('[data-uploader-action="remove"]:not(:disabled)')
    ?? item.querySelector<HTMLElement>('[data-uploader-action]:not(:disabled)')
  );
}

/**
 * 「추가」 자리 — 목록 끝 「추가」 타일, 목록이 없으면 빈 드롭존.
 *
 * @param root 업로더 루트
 * @returns 「추가」 자리 요소 또는 null
 */
function addAreaOf(root: HTMLElement): HTMLElement | null {
  return (
    root.querySelector<HTMLElement>('[data-uploader-action="add"]')
    ?? root.querySelector<HTMLElement>('[data-uploader-action="pick"]:not([aria-disabled="true"])')
  );
}

/**
 * 잃은 초점을 받을 요소를 정한다.
 *
 * @param root 업로더 루트
 * @param record 마지막으로 초점을 쥔 요소의 기록
 * @returns 초점을 줄 요소 또는 null
 */
function recoveryTarget(root: HTMLElement, record: FocusRecord): HTMLElement | null {
  const items = itemsOf(root);

  if (record.index >= 0) {
    return (
      controlIn(items[record.index], record.action)
      ?? controlIn(items[record.index - 1], record.action)
      ?? controlIn(items[items.length - 1], record.action)
      ?? addAreaOf(root)
    );
  }

  return addAreaOf(root) ?? controlIn(items[record.count] ?? items[items.length - 1], 'remove');
}

/**
 * 업로더 초점 되찾기를 건다. 업로더가 다시 그려질 때마다(항목이 빠지거나 드롭존이 목록으로 바뀐 뒤) 확인한다.
 *
 * @param rootRef 업로더 루트 요소 ref
 * @returns void
 */
export function useFocusRecovery(rootRef: RefObject<HTMLElement | null>): void {
  const recordRef = useRef<FocusRecord | null>(null);

  useEffect(() => {
    const root = rootRef.current;
    if (!root) return;

    const handleFocusIn = (event: FocusEvent) => {
      const target = event.target as HTMLElement | null;
      const control = target?.closest<HTMLElement>('[data-uploader-action]');
      // 업로더 단추가 아닌 곳(삭제 확인 창 등)에 간 초점은 기록을 바꾸지 않는다 — 창이 닫힌 뒤 되찾을 자리가 남는다
      if (!control || !root.contains(control)) return;
      const items = itemsOf(root);
      const item = control.closest<HTMLElement>(ITEM_SELECTOR);
      recordRef.current = {
        element: control,
        action: control.getAttribute('data-uploader-action') as UploaderFocusAction,
        index: item ? items.indexOf(item) : -1,
        count: items.length,
      };
    };

    const handleFocusOut = () => {
      // 초점이 업로더 밖의 다른 요소로 옮겨 갔으면 사용자가 옮긴 것이다 — 기록을 지운다.
      // BODY 로 떨어진 것은 옮긴 것이 아니다 — 요소가 DOM 에서 빠졌거나, 삭제 확인 창의 단추가 처리 중으로
      // 잠기며 초점을 잃은 것이라 다시 그린 뒤 되찾는다(느린 삭제 요청 브라우저 재실측).
      window.setTimeout(() => {
        const active = document.activeElement;
        if (active && active !== document.body && !root.contains(active)) {
          recordRef.current = null;
        }
      }, 0);
    };

    // 업로더 밖을 누르면(빈 곳 포함 — 초점은 BODY 로 간다) 사용자가 자리를 옮긴 것으로 보고 기록을 지운다
    const handlePointerDown = (event: Event) => {
      if (!root.contains(event.target as Node)) recordRef.current = null;
    };

    root.addEventListener('focusin', handleFocusIn);
    root.addEventListener('focusout', handleFocusOut);
    document.addEventListener('pointerdown', handlePointerDown, true);
    document.addEventListener('mousedown', handlePointerDown, true);
    return () => {
      root.removeEventListener('focusin', handleFocusIn);
      root.removeEventListener('focusout', handleFocusOut);
      document.removeEventListener('pointerdown', handlePointerDown, true);
      document.removeEventListener('mousedown', handlePointerDown, true);
    };
  }, [rootRef]);

  useEffect(() => {
    const root = rootRef.current;
    const record = recordRef.current;
    if (!root || !record || record.element.isConnected) return;

    const active = document.activeElement;
    if (active && active !== document.body) {
      recordRef.current = null;
      return;
    }

    const target = recoveryTarget(root, record);
    recordRef.current = null;
    // 초점을 주면 focusin 이 새 자리를 기록한다
    target?.focus();
  });
}

export default useFocusRecovery;
