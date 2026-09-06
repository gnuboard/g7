/**
 * CKEditor5 인스턴스 공유 저장소
 *
 * containerId → locale별 에디터 인스턴스 맵을 관리합니다.
 * initEditor / destroyEditor 핸들러가 공유합니다.
 */

// containerId → Map<locale, editorInstance>
export const editorInstances = new Map<string, Map<string, unknown>>();

// setData() 중 change:data → syncToForm 재진입 방지 플래그
let _syncSuppressed = false;
export function isSyncSuppressed(): boolean {
    return _syncSuppressed;
}
export function setSyncSuppressed(value: boolean): void {
    _syncSuppressed = value;
}

/**
 * containerId → 그 컨테이너에 붙은 외부 폼 값 재동기화 핸들 목록.
 *
 * 편집기를 파괴할 때 함께 멈춰야 한다 — 남겨두면 파괴된 인스턴스를 계속 들여다보는
 * 타이머가 화면마다 쌓인다.
 */
const externalSyncs = new Map<string, Array<{ stop(): void }>>();

/**
 * 외부 재동기화 핸들을 등록합니다.
 *
 * @param containerId 편집기 컨테이너 id
 * @param handle 재동기화 핸들
 * @return void
 */
export function registerExternalSync(containerId: string, handle: { stop(): void }): void {
    const list = externalSyncs.get(containerId) ?? [];
    list.push(handle);
    externalSyncs.set(containerId, list);
}

/**
 * 컨테이너에 붙은 외부 재동기화를 모두 멈춥니다.
 *
 * @param containerId 편집기 컨테이너 id
 * @return void
 */
export function stopExternalSyncs(containerId: string): void {
    (externalSyncs.get(containerId) ?? []).forEach(handle => {
        try {
            handle.stop();
        } catch {
            // 이미 멈춘 핸들은 무시
        }
    });
    externalSyncs.delete(containerId);
}
