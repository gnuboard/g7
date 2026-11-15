/**
 * CKEditor5 인스턴스 정리 핸들러
 *
 * 컴포넌트 언마운트 시 CKEditor 인스턴스를 파괴합니다.
 */

// 컨테이너 ID → 인스턴스 맵 (initEditor.ts와 공유)
import { editorInstances, stopExternalSyncs } from './editorInstances';
import { MIN_HEIGHT_ATTR, MIN_HEIGHT_VAR } from './initEditor';

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
    // 외부 폼 값 재동기화 타이머를 먼저 멈춘다 — 인스턴스가 사라진 뒤에도 남으면
    // 파괴된 편집기를 계속 들여다보는 타이머가 화면마다 쌓인다.
    stopExternalSyncs(containerId);

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

    // 최소 높이 표식 정리 — 컨테이너가 아직 문서에 있으면 속성과 CSS 변수를 걷는다
    const heightHost = document.getElementById(containerId);
    if (heightHost) {
        heightHost.removeAttribute(MIN_HEIGHT_ATTR);
        heightHost.style.removeProperty(MIN_HEIGHT_VAR);
    }
}
