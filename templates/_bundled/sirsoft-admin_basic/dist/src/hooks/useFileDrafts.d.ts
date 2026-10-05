/** 한 파일의 저장되지 않은 초안. */
export interface FileDraft {
    /** 편집 중이던 본문 */
    value: string;
    /** 마지막으로 적어 둔 시각 (epoch ms) */
    savedAt: number;
    /** 초안을 뜨기 시작한 시점의 서버 판본 — 복원할 때 그 사이 저장이 있었는지 가른다 */
    baseRevision: number;
}
/** 키 → 초안. */
export type FileDraftMap = Record<string, FileDraft>;
export interface UseFileDraftsOptions {
    /** localStorage 키 접두 (템플릿별로 갈린다) */
    namespace: string;
    /** 쓰기 debounce (ms) */
    debounceMs?: number;
    /** 전체 초안 크기 상한 (바이트) */
    maxBytes?: number;
}
export interface UseFileDraftsResult {
    /** 화면 진입 시 읽어 둔 초안 (자동 복원하지 않는다 — 사용자가 고르게 한다) */
    pendingDrafts: FileDraftMap;
    /** 초안 1건을 적어 둔다 (debounce) */
    rememberDraft: (key: string, value: string, baseRevision: number) => void;
    /** 초안 1건을 지운다 (저장 성공·사용자가 버림) */
    forgetDraft: (key: string) => void;
    /** 초안 전체를 지운다 */
    forgetAllDrafts: () => void;
    /** 복원 배너를 닫는다 (초안은 남긴다 — 다음 진입에서 다시 물을 수 있게) */
    dismissPending: () => void;
}
/**
 * 미저장 편집분을 브라우저에 적어 두고 되살립니다 (결정 D-4).
 *
 * ## 자동 복원하지 않는 이유
 *
 * 진입 시 읽은 초안은 {@link UseFileDraftsResult.pendingDrafts} 로 **돌려주기만** 한다.
 * 자동으로 편집기에 밀어 넣으면, 어제 다른 사람이 남긴 초안이 오늘 내 화면에서 서버 본문을
 * 덮어쓴 것처럼 보이고, 그 상태로 저장하면 남의 미완성 편집이 실제로 배포된다.
 * 무엇이 언제 것인지 보여 주고 사용자가 [복원]/[버리기] 를 고르게 한다.
 *
 * @param options 네임스페이스·debounce·상한
 * @returns 초안 읽기·쓰기·지우기 도구
 */
export declare function useFileDrafts({ namespace, debounceMs, maxBytes, }: UseFileDraftsOptions): UseFileDraftsResult;
