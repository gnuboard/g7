/**
 * useCodeEditorShortcuts.ts — 코드 편집기 단축키 매칭·디스패치
 *
 * 이식 원본: 코어 `resources/js/core/template-engine/layout-editor/hooks/useEditorShortcuts.ts`
 * 의 window keydown 매칭(기준선 10e917c6d :60-81). 템플릿은 독립 IIFE 번들이라 코어를 import
 * 할 수 없어 옮겨 적었고(계획서 §4.1·§13), 동작 계약은 `CodeWorkspace.shortcuts.test.tsx` 가
 * SSoT 배열에서 모집단을 파생해 잠근다.
 */
import { useEffect } from 'react';
import {
  CodeShortcutActionId,
  codeEventCombo,
  matchCodeShortcut,
} from '../spec/codeEditorShortcuts';

/** 액션 식별자 → 핸들러. 등록하지 않은 액션은 눌려도 아무 일도 일어나지 않는다. */
export type CodeShortcutHandlers = Partial<Record<CodeShortcutActionId, () => void>>;

export interface UseCodeEditorShortcutsParams {
  /** 액션별 핸들러 */
  handlers: CodeShortcutHandlers;
  /** 꺼 두면 리스너를 달지 않는다 (모달이 떠 있는 동안 등) */
  enabled?: boolean;
}

/**
 * 입력 중인 자리인지 판정합니다.
 *
 * 이 판정이 없으면 검색창에 `?` 를 치는 순간 도움말이 열리고 글자는 들어가지 않는다.
 * Monaco 는 내부에 `textarea` 를 두므로 같은 판정에 함께 걸린다 — 그래서 편집기 안에서도
 * 단일 키 단축키가 글자를 가로채지 않는다.
 *
 * @param target 이벤트 타깃
 * @returns 입력 자리면 true
 */
function isEditableTarget(target: EventTarget | null): boolean {
  if (!target || !(target instanceof Element)) {
    return false;
  }

  const tag = target.tagName.toLowerCase();

  if (tag === 'input' || tag === 'textarea' || tag === 'select') {
    return true;
  }

  return target.closest('[contenteditable="true"], .monaco-editor') !== null;
}

/**
 * SSoT 를 읽어 window keydown 에 단축키를 겁니다.
 *
 * ## 입력 중 오발동을 막는 규칙
 *
 * 입력 자리에 포커스가 있을 때 **`mod` 없는 단일 키는 무시한다**(`escape` 제외). 그래야
 * 검색창의 `?`·`z` 가 글자로 들어가고, `Ctrl+S` 같은 조합은 입력 중에도 그대로 저장이 된다 —
 * 편집기에 포커스를 둔 채 저장하는 것이 가장 흔한 동작이기 때문이다.
 *
 * `escape` 를 예외로 두는 이유: 입력 자리에서 빠져나오는 것이 그 키의 본래 뜻이고, 시트·서랍·
 * 도움말이 열려 있을 때 그것부터 닫는 우선순위는 핸들러 쪽이 정한다.
 *
 * @param params 핸들러와 활성 여부
 * @returns 없음
 */
export function useCodeEditorShortcuts({ handlers, enabled = true }: UseCodeEditorShortcutsParams): void {
  useEffect(() => {
    if (!enabled || typeof window === 'undefined') {
      return;
    }

    const onKeyDown = (event: KeyboardEvent): void => {
      const combo = codeEventCombo(event);
      const spec = matchCodeShortcut(combo);

      if (!spec) {
        return;
      }

      const singleKey = !combo.mod && !combo.alt;

      if (singleKey && combo.key !== 'escape' && isEditableTarget(event.target)) {
        return;
      }

      const handler = handlers[spec.id];

      if (!handler) {
        return;
      }

      // 브라우저 기본 동작을 반드시 막는다 — `mod+s` 는 "페이지 저장" 대화상자를,
      // `mod+p` 는 인쇄를 연다. 핸들러가 있을 때만 막으므로 등록하지 않은 조합은 평소대로 동작한다.
      event.preventDefault();
      handler();
    };

    window.addEventListener('keydown', onKeyDown);

    return () => window.removeEventListener('keydown', onKeyDown);
  }, [handlers, enabled]);
}
