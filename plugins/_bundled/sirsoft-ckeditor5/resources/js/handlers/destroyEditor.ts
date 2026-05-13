/**
 * CKEditor5 인스턴스 정리 핸들러
 *
 * 컴포넌트 언마운트 시 CKEditor 인스턴스를 파괴합니다.
 */

// 컨테이너 ID → 인스턴스 맵 (initEditor.ts와 공유)
import { editorInstances } from './editorInstances';
import { disconnectDarkModeObserver } from './initEditor';

/**
 * destroyEditor 핸들러
 *
 * onUnmount 시 해당 컨테이너의 모든 CKEditor 인스턴스를 파괴합니다.
 *
 * @param params 핸들러 파라미터 (사용 안 함)
 * @param context 액션 컨텍스트
 */
export async function destroyEditorHandler(
    action: Record<string, any>,
    _context: unknown
): Promise<void> {
    // ActionHandler 시그니처: (action: ActionDefinition, context: ActionContext)
    // action.params.name 기반으로 containerId 계산 (initEditor와 동일한 패턴)
    const editorName = (action.params?.name as string) ?? 'content';
    const containerId = `ckeditor5-${editorName}`;
    const instances = editorInstances.get(containerId);

    if (!instances || instances.size === 0) {
        return;
    }

    const destroyPromises: Promise<void>[] = [];

    instances.forEach((editor) => {
        if (editor && typeof editor.destroy === 'function') {
            destroyPromises.push(
                editor.destroy().catch((err: unknown) => {
                    console.warn('[sirsoft-ckeditor5] destroyEditor error:', err);
                })
            );
        }
    });

    await Promise.allSettled(destroyPromises);

    editorInstances.delete(containerId);

    // height style 요소 정리
    const heightStyle = document.getElementById(`ckeditor5-height-style-${containerId}`);
    if (heightStyle) {
        heightStyle.remove();
    }

    // 모든 에디터 인스턴스가 제거되면 다크모드 옵저버도 해제
    if (editorInstances.size === 0) {
        disconnectDarkModeObserver();
    }
}
