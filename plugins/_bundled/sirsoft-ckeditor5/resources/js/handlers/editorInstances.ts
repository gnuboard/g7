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
