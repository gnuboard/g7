/**
 * adaptExtensionToLayoutDocument.ts — 확장 문서 → 레이아웃 문서 컨텍스트 어댑터
 *
 *
 * 확장 편집 모드(`editMode==='extension'`)에서 `useExtensionDocument` 의 결과를
 * `LayoutDocumentContext` 가 기대하는 `UseLayoutDocumentResult` 형태로 변환한다.
 * 이로써 PreviewCanvas/EditorCanvasOverlay/PropertyEditorModal 등 캔버스 인프라가
 * 레이아웃/확장 편집을 동일 코드 경로로 처리한다.
 *
 * 매핑:
 *  - `document` → `{ layoutName: 'extension:{id}', raw: { ...content, components }, lockVersion }`
 *    (캔버스는 `raw.components` 를 렌더하므로 편집 가능한 확장 조각 트리를 노출).
 *  - `setLayoutComponents`/`patchLayout`/`save`/`reload`/`isDirty`/`saveSuccessCounter` 위임.
 *  - 레이아웃 전용 멤버(dirtyKeys/dirtyLayoutNames/patchDocumentRaw/invalidateCurrentCache/
 *    reloadCounter/markDirty)는 확장 편집에 무의미하므로 안전한 기본값(빈 집합/no-op)으로 채운다.
 *
 * @since engine-v1.50.0
 */

import type { UseLayoutDocumentResult, LoadedLayoutDocument } from './useLayoutDocument';
import type { UseExtensionDocumentResult } from './useExtensionDocument';
import type { EditorNode } from '../utils/layoutTreeUtils';

const EMPTY_SET: ReadonlySet<string> = new Set<string>();

/**
 * 호스트 raw + 확장 content + 병합 components 를 캔버스 렌더용 raw 로 합성한다(G3).
 *
 * 베이스 = 호스트 raw(data_sources/meta/modals/scripts 보존). components 는 호스트 병합
 * 트리로 교체. data_sources 는 호스트 + 확장 content 의 data_sources 를 병합해, 호스트
 * 바인딩과 확장 조각 바인딩이 모두 샘플 데이터로 해석되게 한다(중복 id 는 호스트 우선).
 *
 * 호스트 미병합(조각 단독 디그레이드 — hostRaw 비어 있음)이면 확장 content 를 베이스로 둔다.
 *
 * @param hostRaw 호스트 레이아웃 원본 raw (빈 객체면 디그레이드)
 * @param content 확장 content (data_sources 병합용)
 * @param components 캔버스 렌더 components (호스트 병합 트리)
 * @returns 캔버스 렌더용 raw
 */
export function buildMergedRaw(
  hostRaw: Record<string, unknown>,
  content: Record<string, unknown>,
  components: EditorNode[],
): Record<string, unknown> {
  const hasHost = hostRaw && Object.keys(hostRaw).length > 0;
  const base = hasHost ? hostRaw : content;
  const hostDataSources = Array.isArray((base as any).data_sources)
    ? ((base as any).data_sources as unknown[])
    : [];
  const extDataSources = Array.isArray((content as any).data_sources)
    ? ((content as any).data_sources as unknown[])
    : [];
  // data_sources 병합 — id 기준 중복 제거(베이스 우선).
  const seen = new Set<string>();
  const mergedDataSources: unknown[] = [];
  for (const ds of [...hostDataSources, ...extDataSources]) {
    const id = ds && typeof ds === 'object' ? (ds as any).id : undefined;
    const key = typeof id === 'string' ? id : `__anon_${mergedDataSources.length}`;
    if (seen.has(key)) continue;
    seen.add(key);
    mergedDataSources.push(ds);
  }
  return {
    ...base,
    components,
    ...(mergedDataSources.length > 0 ? { data_sources: mergedDataSources } : {}),
    // 응답 전용 메타 제거(저장/렌더 혼선 방지).
    lock_version: undefined,
  };
}

/**
 * 확장 문서 결과를 레이아웃 문서 컨텍스트 형태로 어댑트한다.
 *
 * @param ext useExtensionDocument 결과
 * @returns UseLayoutDocumentResult 호환 객체
 */
export function adaptExtensionToLayoutDocument(
  ext: UseExtensionDocumentResult,
): UseLayoutDocumentResult {
  const document: LoadedLayoutDocument | null = ext.document
    ? {
        layoutName: `extension:${ext.document.extensionId}`,
        //  호스트 병합 렌더 — 캔버스는 **호스트 raw** 를 베이스로 렌더해 호스트의
        // data_sources/meta/modals 로 호스트 바인딩(`{{...}}`)을 샘플 데이터로 해석한다(G3).
        // components 는 호스트 병합 트리(편집 중 확장 조각 합성)로 덮고, data_sources 는
        // 호스트 + 확장(content) 양쪽을 병합해 확장 조각 바인딩도 해석되게 한다.
        raw: {
          ...buildMergedRaw(ext.document.hostRaw, ext.document.content, ext.document.components),
          // 시각 편집 가능 여부. PreviewCanvas 가 'no-injection' 이면
          // 호스트 라이브 렌더 대신 디그레이드 안내를 띄운다. 편집기 전용 메타(`__` 접두 —
          // DynamicRenderer 가 로 필터). 일반 레이아웃 문서엔 없는 키라 영향 0.
          __editability: ext.document.editability,
          __extensionId: ext.document.extensionId,
        },
        lockVersion: ext.document.lockVersion,
      }
    : null;

  return {
    document,
    isLoading: ext.isLoading,
    error: ext.error,
    isDirty: ext.isDirty,
    saveSuccessCounter: ext.saveSuccessCounter,
    reload: ext.reload,
    patchLayout: ext.patchLayout,
    setLayoutComponents: ext.setLayoutComponents,
    // 확장 편집에서는 최상위 임의 키 편집(data_sources 등)을 components 외 경로로 다루지
    // 않는다 — 확장 content 의 components 트리만 시각 편집 대상. no-op.
    patchDocumentRaw: () => {},
    dirtyKeys: EMPTY_SET,
    dirtyLayoutNames: EMPTY_SET,
    invalidateCurrentCache: () => {},
    reloadCounter: 0,
    save: () => ext.save(),
    markDirty: () => {},
  };
}

/** components 헬퍼 재노출(외부 테스트 편의) */
export type { EditorNode };
