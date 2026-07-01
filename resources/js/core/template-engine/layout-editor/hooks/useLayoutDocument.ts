/**
 * useLayoutDocument.ts — 편집기 캔버스의 레이아웃 문서 로드/패치/저장 hook
 *
 *
 * 라우트 선택 시 `with_source_meta=1` 응답을 fetch 해 캔버스에 렌더.
 * in-memory patch + save() + dirty 추적 + 활성 확장 재검증
 *  가드 + 낙관적 잠금 409 응답 분기.
 *
 * 본 hook 은 단일 진실 공급원으로 layoutDocument 를 가지며, 외부에서는
 * patchLayout/setLayoutComponents 로만 변형한다.
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useLayoutEditor } from '../LayoutEditorContext';
import {
  buildEditorAccessError,
  buildNetworkError,
  type EditorAccessError,
} from '../types/editorErrors';
import { trackEditorDocument } from '../devtools/editorTrackers';
import {
  type EditorNode,
  stripInheritedFromLayoutContent,
  stripInheritedNodes,
  findNodeByPath,
  patchNode,
} from '../utils/layoutTreeUtils';
import { parseEditorPath } from './useElementSelection';
import { readSanctumToken } from '../utils/authToken';
import { getCacheBustNonce, bumpCacheBustNonce } from '../utils/editorCacheBust';
import { hasPending, flushPending } from './pendingCustomTranslations';

/**
 * 로드된 레이아웃 문서 — 백엔드 응답의 data 부분 (병합 + 확장 + 메타 포함)
 */
/**
 * 모달 편집 모드에서 호스트 레이아웃의 modals[i] 를 단독 편집하기 위한 컨텍스트.
 * `raw` 에는 모달 조각(`{components: modals[i].components, ...}`)이 노출돼 캔버스/오버레이/
 * 패치 인프라를 그대로 재사용하고, 저장 시 `hostRaw` 의 `modals[modalIndex]` 만 편집분으로
 * 갱신해 호스트의 나머지 content 를 보존한 채 호스트 레이아웃을 PUT 한다(저장 격리).
 */
export interface ModalEditContext {
  /** 편집 대상 modalId */
  modalId: string;
  /** 호스트 레이아웃 modals[] 에서의 인덱스 */
  modalIndex: number;
  /** 호스트 레이아웃 원본 전체 (저장 시 modals[modalIndex] 만 갱신) */
  hostRaw: Record<string, unknown>;
  /**
   * 원본 모달 노드 (편집 표시용 isOpen=true 강제 전). 저장 시 편집 결과에 원본의
   * isOpen 바인딩을 복원해 운영 content 를 오염시키지 않는다.
   */
  originalModalNode: Record<string, unknown>;
  /**
   * 인플레이스 렌더 시 호스트 components 트리에서 편집 대상 모달 노드의 인덱스 경로.
   * 모달 노드를 호스트 components 끝에 append 해 호스트 전체를 인플레이스로 렌더하고(딤 +
   * 모달만 밝게), 이 경로로 SourceLockDimLayer 구멍/선택 잠금을 잡는다. 저장 시 이 노드를
   * 추출해 modals[modalIndex] 로 되돌리고 components 에서는 제거(원본 호스트 보존).
   */
  editIndexPath?: number[];
}

/**
 * 반복 항목 편집 모드에서 iteration 원본 노드의 항목 템플릿(children)을 단독
 * 편집하기 위한 컨텍스트. `raw.components` 에 iteration 노드의 children(항목 템플릿)을
 * 노출해 캔버스가 항목 1개를 단독 렌더하고, 저장 시 hostRaw 의 그 iteration 원본 노드
 * children 만 갱신해(전 인스턴스 1개 템플릿 반영) 호스트의 나머지 content 를 보존한다.
 */
export interface IterationEditContext {
  /** iteration 원본 노드의 에디터 path (`0.children.2` 등) */
  sourcePath: string;
  /** parseEditorPath 로 파싱한 인덱스 경로 (저장 시 노드 위치 탐색) */
  sourceIndexPath: number[];
  /** 호스트 레이아웃 원본 전체 (저장 시 iteration 원본 노드 children 만 갱신) */
  hostRaw: Record<string, unknown>;
}

export interface LoadedLayoutDocument {
  /** 레이아웃 이름 */
  layoutName: string;
  /** 백엔드 응답의 data 객체 (components/data_sources/modals/...) */
  raw: Record<string, unknown>;
  /** 낙관적 잠금 버전 (LayoutResource.lock_version) — 노출 */
  lockVersion: number;
  /**
   * 모달 편집 컨텍스트. editMode==='modal' 일 때만 존재.
   * 존재 시 `raw` 는 모달 조각이고, 저장은 hostRaw 의 modals[modalIndex] 만 패치.
   */
  modalContext?: ModalEditContext;
  /**
   * 반복 항목 편집 컨텍스트. editMode==='iteration_item' 일 때만 존재.
   * 존재 시 `raw.components` 는 iteration 항목 템플릿(children)이고, 저장은 hostRaw 의
   * iteration 원본 노드 children 만 패치.
   */
  iterationContext?: IterationEditContext;
}

/**
 * @deprecated 공통 `EditorAccessError` 사용 권장 — types/editorErrors 참조.
 * 본 alias 는 외부 호출자 시그니처 유지를 위해 남겨 둔다.
 */
export type LayoutLoadError = EditorAccessError;

/**
 * 저장 결과 — UI 가 분기 처리할 수 있도록 명시적 종류 구분.
 */
export type SaveResult =
  | {
      kind: 'success';
      newLockVersion: number;
      /**
       * 저장 후 현재(최신) 콘텐츠 버전 번호.
       * 백엔드 PUT 응답 `data.current_version`. 구버전 백엔드/누락 시 undefined —
       * 호출자(LayoutEditorChrome)가 배지 갱신을 생략(다음 routes 재fetch 때 동기화).
       */
      newContentVersion?: number;
      /** 저장된 레이아웃 이름 — 버전 배지 갱신 대상 키. */
      savedLayoutName?: string;
      /**
       * 저장된 확장 ID — 확장 편집 저장(useExtensionDocument) 시에만 설정 (
       * 버전 기록). 확장 노드 버전 배지(extensionVersions) 갱신 대상 키.
       */
      savedExtensionId?: string;
    }
  | { kind: 'validation_failed'; status: number; errors: Record<string, string[]> | null }
  | { kind: 'concurrent_modification'; currentVersion: number; yourVersion: number }
  | { kind: 'blocked_inactive_extension'; blockedPaths: string[] }
  | { kind: 'network_error'; message: string }
  | { kind: 'guard_no_document' };

export interface UseLayoutDocumentResult {
  /** 현재 로드된 문서 (null = 로드 전 / 라우트 미선택) */
  document: LoadedLayoutDocument | null;
  /** 로딩 중 여부 */
  isLoading: boolean;
  /** 마지막 에러 (null = 정상) — kind 별로 PreviewCanvas 가 분기 렌더 */
  error: EditorAccessError | null;
  /** dirty 상태 — patchLayout 이후 true, save 성공 또는 reload 시 false */
  isDirty: boolean;
  /**
   * save success 카운터.
   *
   * save 가 성공할 때마다 +1. 외부 hook(EditorCanvasOverlay 의 useEditorHistory 등)
   * 이 useEffect dependency 로 받아 history.clear() 를 호출하면, 저장된 상태가
   * 새 baseline 이 되어 undo/redo 가 양쪽 disabled 로 리셋된다.
   *
   * 데이터 정합성 보호: 저장 후 Undo 로 클라이언트가 이전 상태로 돌아간 뒤 다시
   * 저장하면 서버 DB 가 빈 상태로 덮어쓰이는 결함을 방지.
   */
  saveSuccessCounter: number;
  /** 강제 재로드 — 캐시 무효화 후 호출. dirty 도 false 로 리셋 */
  reload: () => Promise<void>;
  /**
   * 트리 일부를 변경 — patcher 는 현재 components 배열에 대한 immutable
   * 변형을 수행해 새 배열 반환. dirty 가 true 가 된다.
   */
  patchLayout: (patcher: (current: EditorNode[]) => EditorNode[]) => void;
  /** 트리 전체를 직접 교체 (undo/redo 시 사용). dirty 는 true 가 된다 */
  setLayoutComponents: (next: EditorNode[]) => void;
  /**
   * 레이아웃 문서의 최상위 구조 키(`data_sources` 등)를 변경.
   *
   * `components` 외의 최상위 키(예 `data_sources`)는 patchLayout/setLayoutComponents
   * 로 변형되지 않는다. 본 메서드는 임의 최상위 키를 immutable 하게 교체하되,
   * **저장 마스킹이 `__editor.original` 을 골격으로 쓰므로**(stripInheritedFromLayoutContent)
   * `raw.__editor.original[key]` 에 영속 대상 값을 기입해 편집분이 save 시 유실되지
   * 않게 한다(§저장 마스킹 SSoT).
   *
   * `data_sources` 같이 **편집기 표시용(merged: 상속 ∪ 자체)** 과 **영속용(자체만)**
   * 이 다른 키를 위해 `originalValue` 를 분리 전달할 수 있다:
   *  - `value`         → 최상위 `raw[key]` (검색 후보·오버레이가 즉시 읽음, merged)
   *  - `originalValue` → `raw.__editor.original[key]` (저장 골격에 들어감, 자체만)
   * `originalValue` 미지정 시 `value` 를 양쪽에 동일 기입(상속 없는 독립 키).
   * dirty 가 true 가 된다.
   */
  patchDocumentRaw: (key: string, value: unknown, originalValue?: unknown) => void;
  /**
   * dirty 라우트 키 집합 — `${editMode}|${layoutName}` 형태.
   *
   * 셸 생명주기 메모리 캐시에 미저장 편집분(dirty 스냅샷)이 보관된 라우트의 키.
   * RouteTreePanel 이 dirty 배지(●) 표시에, beforeunload 가드가 "미저장 변경 있음"
   * 판정에 사용한다. 저장 성공 / 초기화 시 해당 키가 제거된다.
   */
  dirtyKeys: ReadonlySet<string>;
  /**
   * 현재 편집 모드(route) 기준 dirty 레이아웃 이름 집합 —
   * RouteTreePanel 이 path↔layoutName 매핑으로 배지 표시. base/modal/extension 가상
   * 경로는 별도 키이므로 트리 배지 대상에서 제외된다.
   */
  dirtyLayoutNames: ReadonlySet<string>;
  /**
   * 현재 레이아웃의 세션 캐시 엔트리 제거.
   * 다음 진입 시 서버 최신을 로드하도록 캐시를 비우고 dirty 키도 해제한다.
   */
  invalidateCurrentCache: () => void;
  /**
   * reload 신호 카운터 — `reload` 호출 시 +1. EditorCanvasOverlay 가
   * 이 변화를 감지해 history baseline 을 재설정한다(layoutName 동일 시 baseline
   * 재push 가 누락되는 경로 보강).
   */
  reloadCounter: number;
  /**
   * 저장 — + 가드 + lock_version 흐름.
   *
   * 호출 순서:
   *  1. 활성 확장 재검증 가드 (resolveActiveExtensions 가 주어진 경우만)
   *     — 부팅 시점의 비활성 확장 컴포넌트가 신규 노드로 포함된 경우 차단.
   *  2. PUT /api/admin/templates/{id}/layouts/{name} ({ content, expected_lock_version })
   *  3. 200: lockVersion 갱신 + dirty=false / 422: 검증 실패 / 409: 동시 수정.
   */
  save: (options?: SaveOptions) => Promise<SaveResult>;
  /**
   * 문서를 dirty 로 표시 — 데이터 칩 키 값 편집처럼 node.text 는 안 바뀌지만 저장-지연
   * 버퍼에 변경이 쌓인 경우, [저장] 버튼을 활성화해 flush 가 일어나게 한다.
   * (선택 — 일부 테스트 mock 은 미구현. 호출처는 `markDirty?.()` 로 옵셔널 호출.)
   */
  markDirty?: () => void;
}

export interface SaveOptions {
  /**
   * 활성 확장 재검증 — 호출자가 현재 활성 모듈/플러그인 식별자 집합을 동기/비동기
   * 로 제공한다. 미제공 시 가드를 건너뛴다.
   */
  resolveActiveExtensions?: () => Promise<{
    moduleIds: string[];
    pluginIds: string[];
  }>;
  /**
   * 본 세션에서 새로 추가된 노드의 componentPath 집합. 가드가 이 집합을 기준으로
   * 비활성 확장 출처 검사 (부팅 전 이미 있던 비활성 확장 노드는 무손실 보존).
   */
  sessionAddedPaths?: string[];
}

/**
 * 현재 선택된 라우트의 레이아웃 문서를 로드.
 */
/**
 * 모달 편집 모드의 가상 path(`__modal__/{modalId}`)에서 modalId 를 추출한다.
 * 호스트 레이아웃명은 selectedRoute.layoutName
 * 에 별도 보관되므로 path 에는 modalId 만 담긴다(reducer ENTER_MODAL_EDIT + matchStateScope
 * 와 동일 형식). 형식 불일치 시 null.
 *
 * @param path selectedRoute.path
 * @returns modalId 또는 null
 */
export function extractModalIdFromPath(path: string | null | undefined): string | null {
  if (typeof path !== 'string') return null;
  const prefix = '__modal__/';
  if (!path.startsWith(prefix)) return null;
  const modalId = path.slice(prefix.length);
  // modalId 에 슬래시가 더 있으면(예상 외 형식) 무효 처리 — 단일 세그먼트만 허용.
  if (modalId === '' || modalId.includes('/')) return null;
  return modalId;
}

/**
 * 반복 항목 편집 모드의 가상 path(`__iteration__/{sourcePath}`)에서 sourcePath 를 추출한다.
 * sourcePath 는 iteration 원본 노드의 에디터 path(`0.children.2` 등).
 * 형식 불일치 시 null.
 *
 * @param path selectedRoute.path
 * @returns iteration 원본 노드 에디터 path 또는 null
 */
export function extractIterationSourcePath(path: string | null | undefined): string | null {
  if (typeof path !== 'string') return null;
  const prefix = '__iteration__/';
  if (!path.startsWith(prefix)) return null;
  const sourcePath = path.slice(prefix.length);
  return sourcePath !== '' ? sourcePath : null;
}

/**
 * 저장 후 클라이언트 캐시-버스트 nonce.
 *
 * 편집기에서 레이아웃을 PUT 저장하면 백엔드가 `ext.cache_version` 을 bump 하지만, 그 새
 * 값은 PUT 응답에 포함되지 않아 **클라이언트는 모른다**(부팅 시점 `window.G7Config.cache_version`
 * 만 보유). 따라서 저장 직후 같은 호스트 레이아웃을 GET 으로 재로드하면 `?v=<옛 값>` 으로
 * 브라우저/HTTP 캐시 stale 응답을 받아 편집 모드(반복/모달/확장) 저장분이 route 복귀 화면에
 * 미반영된다.
 *
 * 본 nonce 는 저장 성공/강제 reload 마다 증가하고 GET URL `?v=` 에 합성돼, 같은 편집기 세션 안에서
 * 서버 content 변경(저장·버전 복원) 후 재로드가 항상 신선 응답을 받게 한다. `editorCacheBust` 모듈로
 * 일원화해 useExtensionDocument(확장 문서)와도 같은 카운터를 공유한다.
 */

export function useLayoutDocument(): UseLayoutDocumentResult {
  const { state } = useLayoutEditor();
  const templateIdentifier = state.templateIdentifier;
  const layoutName = state.selectedRoute?.layoutName ?? null;
  const editMode = state.editMode;
  // 모달 편집 모드 — 호스트 레이아웃의 modals[] 중 편집 대상 modalId (저장 격리).
  const modalId = editMode === 'modal' ? extractModalIdFromPath(state.selectedRoute?.path) : null;
  // 반복 항목 편집 모드 — 호스트 레이아웃의 iteration 원본 노드 path (저장 격리).
  const iterationSourcePath =
    editMode === 'iteration_item' ? extractIterationSourcePath(state.selectedRoute?.path) : null;

  const [document, setDocument] = useState<LoadedLayoutDocument | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<EditorAccessError | null>(null);
  const [isDirty, setIsDirty] = useState(false);
  // save success 카운터
  const [saveSuccessCounter, setSaveSuccessCounter] = useState(0);
  // reload 신호 카운터
  const [reloadCounter, setReloadCounter] = useState(0);

  // ─────────────────────────────────────────────────────────────────────────
  // 셸 생명주기 편집 상태 캐시 — 라우트 전환 시 미저장 편집분 보존.
  //
  // 키 = `${editMode}|${layoutName}` (base/modal/extension 가상 경로 포함). 값 =
  // dirty 스냅샷(raw/lockVersion). 라우트 전환 시 캐시에 dirty 스냅샷이 있으면
  // 서버 fetch 를 생략하고 복원한다(편집분 손실 방지). 클린 이탈 라우트는 캐시하지
  // 않아 항상 최신을 로드한다. 본 캐시는 hook 인스턴스(=편집기 세션) 수명에 묶여
  // 언마운트 시 자동 소멸 — localStorage 영속 아님(새로고침/닫기는 항목5
  // 경고로 보호).
  // ─────────────────────────────────────────────────────────────────────────
  const cacheRef = useRef<
    Map<string, { raw: Record<string, unknown>; lockVersion: number }>
  >(new Map());
  // dirty 키 집합 — 트리 배지(항목4) + beforeunload(항목5) 입력. state 로 두어 변화 시
  // 구독 컴포넌트(RouteTreePanel/Chrome)가 리렌더되게 한다.
  const [dirtyKeys, setDirtyKeys] = useState<Set<string>>(() => new Set());

  const cacheKey = useCallback(
    (mode: string, name: string | null): string => `${mode}|${name ?? ''}`,
    [],
  );

  // 최신 문서 참조 — save 호출 시 비동기 콜백 안에서 stale closure 회피.
  const documentRef = useRef<LoadedLayoutDocument | null>(null);
  useEffect(() => {
    documentRef.current = document;
  }, [document]);

  // 현재 키의 dirty 캐시 갱신 + dirty 키 등록 (patch/set 공통).
  const writeCache = useCallback(
    (raw: Record<string, unknown>, lockVersion: number): void => {
      const key = cacheKey(editMode, layoutName);
      cacheRef.current.set(key, { raw, lockVersion });
      setDirtyKeys((prev) => {
        if (prev.has(key)) return prev;
        const next = new Set(prev);
        next.add(key);
        return next;
      });
    },
    [cacheKey, editMode, layoutName],
  );

  // 키의 캐시 + dirty 등록 제거 (save 성공 / 초기화).
  const clearCacheKey = useCallback(
    (key: string): void => {
      cacheRef.current.delete(key);
      setDirtyKeys((prev) => {
        if (!prev.has(key)) return prev;
        const next = new Set(prev);
        next.delete(key);
        return next;
      });
    },
    [],
  );

  // 같은 호스트 layoutName 을 가리키는 **모든 편집 모드 캐시 키** 무효화.
  //
  // 캐시 키는 `${mode}|${layoutName}` 형태. 편집 모드(iteration_item/modal/extension)에서
  // 저장하면 그 호스트 레이아웃의 route 모드 캐시(`route|<host>`)에 남은 옛 스냅샷 때문에
  // 편집 종료 후 route 복귀 화면이 stale 로 뜬다. 저장 성공 시 host 와 동일한 layoutName
  // suffix 를 가진 캐시 키를 전부 비워 다음 진입이 항상 서버 최신을 로드하게 한다.
  const clearAllModeKeysForLayout = useCallback(
    (name: string | null): void => {
      const suffix = `|${name ?? ''}`;
      const keysToClear: string[] = [];
      for (const key of cacheRef.current.keys()) {
        if (key.endsWith(suffix)) keysToClear.push(key);
      }
      for (const key of keysToClear) {
        cacheRef.current.delete(key);
      }
      if (keysToClear.length > 0) {
        setDirtyKeys((prev) => {
          let changed = false;
          const next = new Set(prev);
          for (const key of keysToClear) {
            if (next.delete(key)) changed = true;
          }
          return changed ? next : prev;
        });
      }
    },
    [],
  );

  const fetchDocument = useCallback(async (): Promise<void> => {
    if (!layoutName) {
      setDocument(null);
      setError(null);
      setIsDirty(false);
      return;
    }

    // 라우트 전환 시 캐시에 dirty 스냅샷이 있으면 서버 fetch 를 생략하고 복원.
    // 미저장 편집분 손실 방지. 클린 라우트(캐시 미보유)는 정상 fetch 로 최신 로드.
    const key = cacheKey(editMode, layoutName);
    const cached = cacheRef.current.get(key);
    if (cached) {
      setDocument({ layoutName, raw: cached.raw, lockVersion: cached.lockVersion });
      setIsDirty(true);
      setError(null);
      setIsLoading(false);
      trackEditorDocument({
        op: 'load',
        layoutName,
        editMode,
        statusCode: 0,
        isDirty: true,
        timestamp: Date.now(),
      });
      return;
    }

    setIsLoading(true);
    setError(null);
    try {
      // 주의: layoutName 은 `auth/login` 같이 슬래시를 포함한 path-segmented 키.
      // Laravel 라우트 `{layoutName}.json` (where=`[a-zA-Z0-9_/\.-]+`) 가 슬래시
      // 포함 segment 를 직접 매칭하므로 encodeURIComponent 로 슬래시를 `%2F` 로
      // 바꾸면 라우터가 디코딩 시점/위치 차이로 404 를 반환한다.
      // 정공 패턴: 코어 `LayoutLoader.ts:742` 와 동일하게 raw 그대로 삽입.
      //
      // `?v=` 캐시 버스터 동반:
      //   PublicLayoutController 가 HTTP Cache-Control 헤더로 응답을 캐싱하므로
      //   확장 install/activate/update 또는 코어 빌드 시점에 cache_version 이 bump 되면
      //   클라이언트도 새 URL 로 인식해 stale 응답을 우회한다. `window.G7Config.cache_version`
      //   미주입 환경(SSR/테스트)은 0 으로 폴백 — 일반 동작 영향 없음.
      const cacheVersion = (window as any).G7Config?.cache_version ?? 0;
      // 저장 후 클라이언트 캐시-버스트 nonce 합성 — 같은 세션 저장→재로드가
      // 옛 cache_version 으로 stale 응답을 받지 않도록 단조 증가 nonce 를 함께 붙인다.
      const url = `/api/layouts/${encodeURIComponent(templateIdentifier)}/${layoutName}.json?with_source_meta=1&v=${cacheVersion}.${getCacheBustNonce()}`;
      const token = readSanctumToken();
      const headers: Record<string, string> = { Accept: 'application/json' };
      if (token) {
        headers.Authorization = `Bearer ${token}`;
      }

      const response = await fetch(url, { headers, credentials: 'same-origin' });
      const body = await response.json().catch(() => null);

      if (!response.ok) {
        setError(buildEditorAccessError(response.status, body, 'layout'));
        setDocument(null);
        setIsDirty(false);
        return;
      }

      const raw = (body && (body.data || body)) ?? {};
      const lockVersion =
        typeof (raw as any).lock_version === 'number' ? (raw as any).lock_version : 0;

      // 모달 편집 모드 — 호스트 레이아웃에서 편집 대상 modals[i] 를 추출해 조각으로
      // 노출. 캔버스/오버레이/패치는 일반 레이아웃과 동형으로 동작하고, 저장만 호스트의
      // modals[modalIndex] 로 격리(아래 save 분기). 모달/인덱스 미발견 시 일반 로드로 폴백.
      if (editMode === 'modal' && modalId) {
        const hostRaw = raw as Record<string, unknown>;
        const modals = Array.isArray(hostRaw.modals) ? (hostRaw.modals as any[]) : [];
        const modalIndex = modals.findIndex(
          (m) => m && (m.id === modalId || m.modal_id === modalId),
        );
        if (modalIndex >= 0) {
          // 모달 노드는 단일 컴포넌트 노드(`{id, type, name, props, children}` — 예: Modal
          // composite). 확장 편집과 **동형 인플레이스**. 종전엔 모달 노드만 단독
          // 루트([modalNode])로 노출해 호스트 컨텍스트(data_sources/딤)가 없었으나, 이제 호스트
          // 전체를 그대로 공급하고 모달 노드를 components 끝에 append 해 호스트 위에 모달이
          // 오버레이로 뜨도록 한다(Modal composite 는 isOpen=true 시 fixed 오버레이 자체 렌더).
          // SourceLockDimLayer 가 그 모달 노드만 구멍으로, 나머지 호스트를 음영으로 덮는다.
          const modalNode = modals[modalIndex] as Record<string, unknown>;
          // Modal composite 는 `isOpen` 이 false(런타임 _global 바인딩)면 `return null` 이라
          // 편집기에서 빈 화면이 된다(모달은 열린 상태로 표시). 편집 표시용으로만
          // isOpen=true 를 강제하고, 원본 노드(원래 isOpen 바인딩 포함)는 originalModalNode 에
          // 보존해 저장 시 복원한다(운영 content 무오염).
          const displayModalNode: Record<string, unknown> = {
            ...modalNode,
            props: { ...((modalNode.props as Record<string, unknown>) ?? {}), isOpen: true },
          };
          const hostComponents = Array.isArray(hostRaw.components)
            ? (hostRaw.components as EditorNode[])
            : [];
          const mergedComponents = [...hostComponents, displayModalNode as EditorNode];
          const editIndexPath = [mergedComponents.length - 1];
          setDocument({
            layoutName,
            // 인플레이스 — 호스트 전체 + 끝에 편집 대상 모달 노드 append.
            raw: { ...hostRaw, components: mergedComponents },
            lockVersion,
            modalContext: { modalId, modalIndex, hostRaw, originalModalNode: modalNode, editIndexPath },
          });
          setIsDirty(false);
          trackEditorDocument({
            op: 'load',
            layoutName,
            editMode,
            statusCode: response.status,
            timestamp: Date.now(),
          });
          return;
        }
        // 모달을 호스트에서 못 찾음 — partial 참조 모달 등. 일반 로드로 폴백(호스트 전체 노출).
      }

      // 반복 항목 편집 모드 — 확장 편집과 **동형 인플레이스**. 호스트 전체를
      // 그대로 캔버스에 공급하고(조각 단독 렌더 금지 — 종전엔 children 만 떼어 단독 문서로
      // 만들어 data_sources/바인딩 컨텍스트가 사라져 캔버스가 깨졌다: NaN/빈 회색 박스), 편집
      // 가능 영역(iteration 원본 노드)만 SourceLockDimLayer 가 구멍으로 노출하고 나머지를
      // 음영으로 잠근다. iteration source 데이터는 PreviewCanvas 가 샘플 1개로 제한해 항목
      // 하나만 렌더한다. 저장은 호스트 트리에서 sourceIndexPath 노드 children 만
      // 갱신(전 인스턴스 1개 템플릿 반영). 노드 미발견 시 일반 로드로 폴백.
      if (editMode === 'iteration_item' && iterationSourcePath) {
        const hostRaw = raw as Record<string, unknown>;
        const sourceIndexPath = parseEditorPath(iterationSourcePath);
        const virtualRoot = { children: (hostRaw.components ?? []) as EditorNode[] } as EditorNode;
        const sourceNode = findNodeByPath(virtualRoot, sourceIndexPath);
        if (sourceNode && Array.isArray((sourceNode as any).children)) {
          setDocument({
            layoutName,
            // 인플레이스 — 호스트 전체(components/data_sources/modals/...)를 그대로 렌더.
            raw: hostRaw,
            lockVersion,
            iterationContext: { sourcePath: iterationSourcePath, sourceIndexPath, hostRaw },
          });
          setIsDirty(false);
          trackEditorDocument({
            op: 'load',
            layoutName,
            editMode,
            statusCode: response.status,
            timestamp: Date.now(),
          });
          return;
        }
        // iteration 원본 노드 미발견 — 일반 로드로 폴백.
      }

      const next: LoadedLayoutDocument = {
        layoutName,
        raw: raw as Record<string, unknown>,
        lockVersion,
      };
      setDocument(next);
      setIsDirty(false);
      trackEditorDocument({
        op: 'load',
        layoutName,
        editMode,
        statusCode: response.status,
        timestamp: Date.now(),
      });
    } catch (err: unknown) {
      setError(buildNetworkError(err, 'layout'));
      setDocument(null);
      setIsDirty(false);
    } finally {
      setIsLoading(false);
    }
  }, [templateIdentifier, layoutName, editMode, modalId, iterationSourcePath, cacheKey]);

  useEffect(() => {
    fetchDocument();
  }, [fetchDocument]);

  /**
   * 강제 재로드 — 현재 캐시 엔트리를 비우고 서버 최신을 fetch.
   * `reload()` 는 "마지막 저장 시점(서버 저장본)" 으로 되돌리는 의미이므로 dirty
   * 캐시를 무시한다. fetchDocument 의 캐시-우선 분기를 우회하기 위해 키를 먼저 제거.
   *
   * 클라이언트 캐시-버스트 nonce 증가 — `fetchDocument` 의 fetch URL `?v=${cacheVersion}.${nonce}`
   * 는 브라우저 HTTP 캐시를 우회하는 키다. 종전엔 nonce 가 **save 성공 시에만** 증가했는데,
   * 버전 기록 **복원**(`POST .../versions/{id}/restore`)은 save 경로를 거치지 않아 nonce 가 그대로였다.
   * 그 결과 복원 후 reload 의 fetch URL 이 복원 전과 동일 → 브라우저 HTTP 캐시가 stale 응답을 줘
   * 캔버스가 복원 버전으로 갱신되지 않고 새로고침해야만 반영됐다(모달/확장/이터레이션/route
   * 전 편집 모드 공통, 모두 본 fetchDocument 경유). reload 는 "서버 최신 강제 회수" 의미이므로 항상
   * 캐시를 버스트한다 — clearCacheKey(클라이언트 세션 Map)와 별개로 HTTP 캐시까지 우회.
   */
  const reload = useCallback(async (): Promise<void> => {
    clearCacheKey(cacheKey(editMode, layoutName));
    bumpCacheBustNonce();
    setReloadCounter((n) => n + 1);
    await fetchDocument();
  }, [clearCacheKey, cacheKey, editMode, layoutName, fetchDocument]);

  /** 현재 레이아웃 캐시 엔트리 제거 — dirty 키 해제 + 다음 진입 서버 로드 */
  const invalidateCurrentCache = useCallback((): void => {
    clearCacheKey(cacheKey(editMode, layoutName));
  }, [clearCacheKey, cacheKey, editMode, layoutName]);

  // ─────────────────────────────────────────────────────────────────────────
  // Patch / Set / Save
  // ─────────────────────────────────────────────────────────────────────────

  const patchLayout = useCallback(
    (patcher: (current: EditorNode[]) => EditorNode[]): void => {
      setDocument((prev) => {
        if (!prev) return prev;
        const currentComponents = (prev.raw.components as EditorNode[] | undefined) ?? [];
        const nextComponents = patcher(currentComponents);
        const nextRaw = { ...prev.raw, components: nextComponents };
        // 세션 캐시에 dirty 스냅샷 기록 — 라우트 전환 후 복원 가능.
        writeCache(nextRaw, prev.lockVersion);
        return { ...prev, raw: nextRaw };
      });
      setIsDirty(true);
      trackEditorDocument({
        op: 'patch',
        layoutName,
        editMode,
        isDirty: true,
        timestamp: Date.now(),
      });
    },
    [layoutName, editMode, writeCache]
  );

  // 데이터 칩 키 값 편집(저장-지연 버퍼)처럼 node.text 변경 없이 dirty 만 올려야 할 때.
  const markDirty = useCallback((): void => {
    setIsDirty(true);
    trackEditorDocument({ op: 'patch', layoutName, editMode, isDirty: true, timestamp: Date.now() });
  }, [layoutName, editMode]);

  const setLayoutComponents = useCallback(
    (next: EditorNode[]): void => {
      setDocument((prev) => {
        if (!prev) return prev;
        const nextRaw = { ...prev.raw, components: next };
        // 세션 캐시에 dirty 스냅샷 기록.
        writeCache(nextRaw, prev.lockVersion);
        return { ...prev, raw: nextRaw };
      });
      setIsDirty(true);
      trackEditorDocument({
        op: 'patch',
        layoutName,
        editMode,
        isDirty: true,
        timestamp: Date.now(),
      });
    },
    [layoutName, editMode, writeCache]
  );

  const patchDocumentRaw = useCallback(
    (key: string, value: unknown, ...rest: [unknown?]): void => {
      // originalValue 미지정 시 value 를 양쪽에 기입(상속 없는 독립 키).
      // 화살표 함수는 자체 `arguments` 가 없으므로 rest 길이로 3번째 인자 유무를 판정한다.
      const persistValue = rest.length >= 1 ? rest[0] : value;
      setDocument((prev) => {
        if (!prev) return prev;
        // 1) 최상위 raw[key] 교체 — 검색 후보(bindingCandidates)/오버레이가 즉시 반영(merged).
        const nextRaw: Record<string, unknown> = { ...prev.raw, [key]: value };
        // 2) __editor.original[key] 에 영속 대상 값 기입 — 저장 마스킹이 골격으로
        //    original 을 쓰므로(stripInheritedFromLayoutContent), 이 동기화가 없으면
        //  편집분이 save 시 original 의 옛 값으로 덮어써져 유실된다.
        const editorMeta = prev.raw.__editor;
        if (editorMeta && typeof editorMeta === 'object' && !Array.isArray(editorMeta)) {
          const meta = editorMeta as Record<string, unknown>;
          const original = meta.original;
          if (original && typeof original === 'object' && !Array.isArray(original)) {
            nextRaw.__editor = {
              ...meta,
              original: { ...(original as Record<string, unknown>), [key]: persistValue },
            };
          }
        }
        writeCache(nextRaw, prev.lockVersion);
        return { ...prev, raw: nextRaw };
      });
      setIsDirty(true);
      trackEditorDocument({
        op: 'patch',
        layoutName,
        editMode,
        isDirty: true,
        timestamp: Date.now(),
      });
    },
    [layoutName, editMode, writeCache]
  );

  const save = useCallback(
    async (options?: SaveOptions): Promise<SaveResult> => {
      const current = documentRef.current;
      if (!current) {
        trackEditorDocument({
          op: 'save_guard_result',
          layoutName,
          editMode,
          guardBlocked: false,
          timestamp: Date.now(),
        });
        return { kind: 'guard_no_document' };
      }

      // (1) 활성 확장 재검증 가드 
      if (options?.resolveActiveExtensions && options.sessionAddedPaths?.length) {
        try {
          const active = await options.resolveActiveExtensions();
          const blockedPaths = findInactiveExtensionNodes(
            current.raw.components as EditorNode[] | undefined,
            options.sessionAddedPaths,
            active
          );
          if (blockedPaths.length > 0) {
            trackEditorDocument({
              op: 'save_guard_result',
              layoutName,
              editMode,
              guardBlocked: true,
              guardBlockedPaths: blockedPaths,
              timestamp: Date.now(),
            });
            return { kind: 'blocked_inactive_extension', blockedPaths };
          }
        } catch {
          // 가드 실패 시 보수적으로 통과 — 백엔드가 최후 방어선.
        }
      }

      // (1.5) 보류 중인 커스텀 다국어 키 값 flush — 데이터 칩 추가/이동/
      // 평문/해제는 즉시 PUT 하지 않고 버퍼에 모았다(저장-지연). 레이아웃 PUT **직전**에 함께
      // 영속해 node.text(`|pN=`)와 키 값(`{pN}`)이 같은 저장 동작에서 동기화되게 한다 — 저장 전
      // 새로고침 시 둘 다 미반영(동기), 저장 시 둘 다 반영(동기) → desync 원천 불가.
      if (hasPending()) {
        try {
          await flushPending(templateIdentifier);
        } catch {
          // flush 실패는 레이아웃 저장을 막지 않는다(버퍼는 유지되어 다음 저장 재시도). 백엔드
          // orphan 스캔이 미반영 키를 정리하므로 raw 노출은 엔진 측에서 별도 가드(미사용 자리표시).
        }
      }

      // (2) PUT 저장
      // 주의: layoutName 은 `auth/forgot_password` 같이 슬래시를 포함한 path-segmented 키.
      // Laravel 라우트 `{name}` (where=`[a-zA-Z0-9_/\.-]+`) 가 슬래시 포함 segment 를
      // 직접 매칭하므로 encodeURIComponent 로 슬래시를 `%2F` 로 바꾸면 라우터가
      // 디코딩 시점/위치 차이로 404 를 반환한다 (홈처럼 슬래시 없는 layoutName 만 통과).
      // GET 로드 URL(라인 160) 과 동일하게 raw 그대로 삽입.
      const url = `/api/admin/templates/${encodeURIComponent(
        templateIdentifier
      )}/layouts/${current.layoutName}`;
      const token = readSanctumToken();
      const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      };
      if (token) {
        headers.Authorization = `Bearer ${token}`;
      }

      // 모달/반복항목 편집 모드 (저장 격리) — 편집한 조각을 호스트 레이아웃에
      // 끼워넣어 재구성하고, 호스트의 다른 content 는 보존한다(결함 15).
      const isModalSave = !!current.modalContext;
      const isIterationSave = !!current.iterationContext;
      const saveTarget: 'layout' | 'host_layout_modal_patch' = isModalSave || isIterationSave
        ? 'host_layout_modal_patch'
        : 'layout';

      trackEditorDocument({
        op: 'save',
        layoutName: current.layoutName,
        editMode,
        saveTarget,
        endpoint: url,
        isDirty,
        timestamp: Date.now(),
      });

      // (2-a) 저장 페이로드 구성.
      let contentToSave: Record<string, unknown>;
      if (isModalSave && current.modalContext) {
        const { modalIndex, hostRaw, originalModalNode, editIndexPath } = current.modalContext;
        const hostModals = Array.isArray(hostRaw.modals) ? [...(hostRaw.modals as any[])] : [];
        // 편집한 모달 노드 = 인플레이스로 components 끝에 append 한 노드.
        // 상속/주입 마스킹 후 그 노드를 modals[modalIndex] 로 되돌린다(전체 교체). editIndexPath
        // 부재(구버전 호환) 시 components[0] 폴백. host components 에는 모달을 다시 넣지 않는다
        // (원본 hostRaw.components 보존 — 아래 maskedHost 사용).
        //
        // 모달 노드는 **마스킹 전** `current.raw.components[modalSlot]` 에서 직접 추출한
        // 뒤 그 단일 노드만 마스킹한다. 종전엔 호스트 전체를 먼저 마스킹(stripInheritedNodes)한
        // 후 editIndexPath[0] 인덱스로 모달을 찾았는데, 마스킹이 base 노드를 제거하고 slot 래퍼
        // children 을 부모 배열로 끌어올려 **배열 길이/인덱스를 재정렬**한다(예: auth/register 는
        // 호스트 components 3개가 전부 base 출처라 끌어올린 route children 수에 따라 모달 위치가
        // 인덱스 3 → 8 등으로 이동). 그 결과 editedComps[modalSlot] 이 모달이 아닌 엉뚱한 route
        // 자식(또는 undefined)을 가리켜 modals[i] 가 미갱신 → 인라인 키화(node.text)가 영속되지
        // 않았다(모달 편집 모드 한정 결함). 마스킹 전 좌표로 추출하면 인덱스가 진입
        // 시점(editIndexPath)과 항상 일치한다. 추출 후 그 노드만 stripInheritedNodes 로 메타 제거.
        const rawComps = Array.isArray((current.raw as any).components)
          ? ((current.raw as any).components as EditorNode[])
          : [];
        const modalSlot =
          editIndexPath && editIndexPath.length === 1 ? editIndexPath[0] : 0;
        const rawModalNode = rawComps.length > modalSlot ? rawComps[modalSlot] : undefined;
        const maskedModalList = rawModalNode ? stripInheritedNodes([rawModalNode]) : [];
        const editedModalNode =
          maskedModalList.length > 0
            ? ({ ...maskedModalList[0] } as Record<string, unknown>)
            : undefined;
        if (modalIndex >= 0 && modalIndex < hostModals.length && editedModalNode) {
          // 편집 표시용으로 강제했던 isOpen=true 를 원본 바인딩으로 복원(운영 content 무오염).
          // 원본에 isOpen 키가 있었으면 그 값으로, 없었으면 키 자체를 제거한다.
          const origProps = (originalModalNode.props as Record<string, unknown>) ?? {};
          const editedProps = { ...((editedModalNode.props as Record<string, unknown>) ?? {}) };
          if ('isOpen' in origProps) {
            editedProps.isOpen = origProps.isOpen;
          } else {
            delete editedProps.isOpen;
          }
          editedModalNode.props = editedProps;
          hostModals[modalIndex] = editedModalNode;
        }
        // 호스트 본체는 상속/주입 마스킹 후, 갱신된 modals 를 합친다.
        const maskedHost = stripInheritedFromLayoutContent(hostRaw);
        contentToSave = { ...maskedHost, modals: hostModals };
      } else if (isIterationSave && current.iterationContext) {
        // 반복 항목 편집 — 인플레이스 편집이므로 `current.raw` 는
        // 이미 호스트 전체이며 iteration 원본 노드 children 만 편집된 상태다. 저장 시에는
        // **편집한 항목 템플릿(그 iteration 노드 children)** 을 호스트 트리에 반영하되, 나머지
        // 호스트 트리/iteration 정의는 원본(hostRaw) 기준으로 보존한다(다른 영역은 선택 잠금
        // 으로 편집 불가하나, 저장 격리를 명시적으로 보장). 편집된 children 은 `current.raw`
        // 에서 sourceIndexPath 로 추출한다.
        const { sourceIndexPath, hostRaw } = current.iterationContext;
        const maskedEdited = stripInheritedFromLayoutContent(current.raw);
        const editedComponents = Array.isArray((maskedEdited as any).components)
          ? ((maskedEdited as any).components as EditorNode[])
          : [];
        const editedSourceNode = findNodeByPath(
          { children: editedComponents } as EditorNode,
          sourceIndexPath,
        );
        const editedItemTemplate = Array.isArray((editedSourceNode as any)?.children)
          ? ((editedSourceNode as any).children as EditorNode[])
          : [];
        const maskedHost = stripInheritedFromLayoutContent(hostRaw);
        const hostComponents = Array.isArray((maskedHost as any).components)
          ? ((maskedHost as any).components as EditorNode[])
          : [];
        // 가상 루트(`{children: hostComponents}`)에 patchNode 로 iteration 원본 노드의
        // children 만 편집한 항목 템플릿으로 교체(나머지 트리/iteration 정의 보존).
        const virtualRoot = { children: hostComponents } as EditorNode;
        const patchedRoot = patchNode(virtualRoot, sourceIndexPath, (node) => ({
          ...node,
          children: editedItemTemplate,
        }));
        const nextComponents = (patchedRoot.children ?? []) as EditorNode[];
        contentToSave = { ...maskedHost, components: nextComponents };
      } else {
        // 저장 페이로드 마스킹 — 상속/주입/partial 노드 + 편집기 전용 메타 제거 (결함 I).
        // 백엔드 `UpdateLayoutContentRequest::prepareForValidation` 가 동일 정책으로 2차 가드.
        contentToSave = stripInheritedFromLayoutContent(current.raw);
      }
      const maskedContent = contentToSave;

      let response: Response;
      try {
        response = await fetch(url, {
          method: 'PUT',
          headers,
          credentials: 'same-origin',
          body: JSON.stringify({
            content: JSON.stringify(maskedContent),
            expected_lock_version: current.lockVersion,
          }),
        });
      } catch (err: unknown) {
        const message = err instanceof Error ? err.message : 'network error';
        trackEditorDocument({
          op: 'save_response',
          layoutName: current.layoutName,
          editMode,
          endpoint: url,
          statusCode: 0,
          timestamp: Date.now(),
        });
        return { kind: 'network_error', message };
      }

      const body = await response.json().catch(() => null);

      trackEditorDocument({
        op: 'save_response',
        layoutName: current.layoutName,
        editMode,
        endpoint: url,
        statusCode: response.status,
        conflict:
          response.status === 409
            ? {
                currentVersion: (body as any)?.current_version,
                yourVersion: (body as any)?.your_version,
              }
            : undefined,
        timestamp: Date.now(),
      });

      // 200 성공 — lockVersion 증가 + dirty 리셋 + saveSuccessCounter +1 (결함 I)
      if (response.ok) {
        const newLockVersion =
          typeof (body as any)?.data?.lock_version === 'number'
            ? (body as any).data.lock_version
            : current.lockVersion + 1;
        setDocument({
          ...current,
          lockVersion: newLockVersion,
        });
        setIsDirty(false);
        // 저장 성공 — 세션 캐시 dirty 엔트리 제거 + 트리 배지 해제.
        clearCacheKey(cacheKey(editMode, current.layoutName));
        // 편집 모드(반복/모달/확장) 저장은 그 호스트 layoutName 의 route 모드 캐시도 stale 이
        // 되므로 같은 layoutName 의 전 모드 캐시 키를 함께 비운다 + 클라이언트 캐시-버스트
        // nonce 증가 — 편집 종료 후 route 복귀 화면이 저장분을 반영하도록.
        clearAllModeKeysForLayout(current.layoutName);
        bumpCacheBustNonce();
        // 결함 I — history reset 신호. EditorCanvasOverlay 가 본 counter 변화를
        // useEffect dependency 로 감지해 history.clear() 호출. 저장된 상태가
        // 새 baseline 이 되어 Undo/Redo 양쪽 disabled 로 리셋된다.
        setSaveSuccessCounter((n) => n + 1);
        // 라우트 트리 버전 배지 동기화 — 저장 응답의 현재 버전 번호를 함께
        // 반환한다(모달/반복 항목 편집 저장도 호스트 레이아웃 PUT 이라 동일 경로).
        const newContentVersion =
          typeof (body as any)?.data?.current_version === 'number'
            ? ((body as any).data.current_version as number)
            : undefined;
        return {
          kind: 'success',
          newLockVersion,
          newContentVersion,
          savedLayoutName: current.layoutName,
        };
      }

      // 409 — 동시 수정
      if (response.status === 409) {
        return {
          kind: 'concurrent_modification',
          currentVersion: (body as any)?.current_version ?? -1,
          yourVersion: (body as any)?.your_version ?? current.lockVersion,
        };
      }

      // 422 — 검증 실패
      if (response.status === 422) {
        return {
          kind: 'validation_failed',
          status: 422,
          errors: ((body as any)?.errors ?? null) as Record<string, string[]> | null,
        };
      }

      // 그 외 — network_error 로 일반화
      return {
        kind: 'network_error',
        message: (body as any)?.message ?? `HTTP ${response.status}`,
      };
    },
    [templateIdentifier, layoutName, editMode, isDirty, clearCacheKey, clearAllModeKeysForLayout, cacheKey, modalId, iterationSourcePath]
  );

  // 현재 편집 모드(route) dirty 레이아웃 이름 집합 — 트리 배지용.
  // 키 `route|<layoutName>` 만 추출(base/modal/extension 가상 경로는 트리 배지 대상 아님).
  const dirtyLayoutNames = useMemo<Set<string>>(() => {
    const names = new Set<string>();
    const prefix = 'route|';
    for (const key of dirtyKeys) {
      if (key.startsWith(prefix)) {
        const name = key.slice(prefix.length);
        if (name) names.add(name);
      }
    }
    return names;
  }, [dirtyKeys]);

  return {
    document,
    isLoading,
    error,
    isDirty,
    saveSuccessCounter,
    reloadCounter,
    dirtyKeys,
    dirtyLayoutNames,
    invalidateCurrentCache,
    reload,
    patchLayout,
    setLayoutComponents,
    patchDocumentRaw,
    save,
    markDirty,
  };
}

/**
 * 본 세션에서 새로 추가된 노드 중 출처가 비활성 확장인 것 식별.
 *
 * - `sessionAddedPaths` 는 호출자(편집기)가 patchLayout 호출 시점에 누적 보관.
 * - 노드 출처는 `__source.extensionId` + `name`/`type` 컴포넌트 네임스페이스 기반.
 *   본 함수는 단순화: `__source.kind === 'extension'` 이고 `__source.extensionId`
 *   가 활성 모듈/플러그인 식별자 집합에 없는 경우 차단.
 */
function findInactiveExtensionNodes(
  components: EditorNode[] | undefined,
  sessionAddedPaths: string[],
  active: { moduleIds: string[]; pluginIds: string[] }
): string[] {
  if (!components || components.length === 0) return [];
  const blocked: string[] = [];
  const activeSet = new Set<string>([...active.moduleIds, ...active.pluginIds]);

  for (const pathStr of sessionAddedPaths) {
    const node = findNodeByDotPath(components, pathStr);
    if (!node) continue;
    const src = (node as any).__source;
    if (!src || src.kind !== 'extension') continue;
    const extId = String(src.extensionId ?? '');
    if (extId.length === 0) continue;
    if (!activeSet.has(extId)) {
      blocked.push(pathStr);
    }
  }

  return blocked;
}

function findNodeByDotPath(components: EditorNode[], pathStr: string): EditorNode | null {
  // 공용 parseEditorPath(가상 인덱스 제외 + responsive 세그먼트 보존) + findNodeByPath
  // (세그먼트 union 하강)에 위임 — responsive 분기에 추가된 노드도 정확히 해석한다.
  const path = parseEditorPath(pathStr);
  if (path.length === 0) return null;
  return findNodeByPath({ children: components } as EditorNode, path);
}
