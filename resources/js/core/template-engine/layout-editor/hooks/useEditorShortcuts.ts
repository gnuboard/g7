/**
 * useEditorShortcuts.ts — 레이아웃 편집기 전역 단축키 디스패처
 *
 * `editorShortcuts.ts`(SSoT)의 키맵으로 window keydown 을 매칭해 액션 콜백을 호출한다.
 * 입력칸(INPUT/TEXTAREA/SELECT/contentEditable) 또는 편집기 모달(`.g7le-modal-backdrop`)
 * 포커스 시에는 가로채지 않는다 — 텍스트 편집/모달 키(모달 Esc 닫기 등)를 침범하지 않도록.
 *
 * `requiresSelection` 액션은 선택 노드가 없으면 무시. Escape 는 deselect/exit 둘 다
 * 매칭하므로 우선순위 처리: 선택이 있으면 deselect, 없으면 exit(toolbar 나가기). 단축키맵
 * 모달(`help`)은 어디서나 동작(입력 중 제외).
 *
 * @since engine-v1.50.0
 */

import { useEffect } from 'react';
import { eventCombo, matchShortcut, type ShortcutActionId } from '../spec/editorShortcuts';

/** 액션 id → 핸들러. 미정의 액션은 무시(부분 결선 허용). */
export type ShortcutHandlers = Partial<Record<ShortcutActionId, () => void>>;

export interface UseEditorShortcutsParams {
  handlers: ShortcutHandlers;
  /** 선택 노드 존재 여부 — requiresSelection 가드 + Escape 우선순위 판정. */
  hasSelection: boolean;
  /** false 면 전체 비활성. */
  enabled?: boolean;
}

function isEditableTarget(target: EventTarget | null): boolean {
  if (!target || !(target instanceof HTMLElement)) return false;
  const tag = target.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
  if (target.isContentEditable) return true;
  return false;
}

function modalOpen(): boolean {
  if (typeof document === 'undefined') return false;
  return !!document.querySelector('.g7le-modal-backdrop, .g7le-modal');
}

export function useEditorShortcuts(params: UseEditorShortcutsParams): void {
  const { handlers, hasSelection, enabled = true } = params;

  useEffect(() => {
    if (!enabled || typeof window === 'undefined') return;

    const onKeyDown = (e: KeyboardEvent): void => {
      if (isEditableTarget(e.target)) return;

      const ev = eventCombo(e);
      const spec = matchShortcut(ev);
      if (!spec) return;

      // 모달 열림 시: help 모달 토글(`?`)·문서 액션도 모달 키에 양보(모달 자체 Esc 닫기 등).
      if (modalOpen()) return;

      // requiresSelection 가드.
      if (spec.requiresSelection && !hasSelection) return;

      // Escape 우선순위 — deselect(선택 있음) vs exit(선택 없음).
      if (spec.key === 'escape' || ev.key === 'escape') {
        if (hasSelection) {
          const h = handlers.deselect;
          if (h) { e.preventDefault(); h(); }
        } else {
          const h = handlers.exit;
          if (h) { e.preventDefault(); h(); }
        }
        return;
      }

      const handler = handlers[spec.id];
      if (handler) {
        e.preventDefault();
        handler();
      }
    };

    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [handlers, hasSelection, enabled]);
}
