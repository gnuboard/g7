import { CodeShortcutActionId } from '../spec/codeEditorShortcuts';
/** 액션 식별자 → 핸들러. 등록하지 않은 액션은 눌려도 아무 일도 일어나지 않는다. */
export type CodeShortcutHandlers = Partial<Record<CodeShortcutActionId, () => void>>;
export interface UseCodeEditorShortcutsParams {
    /** 액션별 핸들러 */
    handlers: CodeShortcutHandlers;
    /** 꺼 두면 리스너를 달지 않는다 (모달이 떠 있는 동안 등) */
    enabled?: boolean;
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
export declare function useCodeEditorShortcuts({ handlers, enabled }: UseCodeEditorShortcutsParams): void;
