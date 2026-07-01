/**
 * useExtensionDocument.ts — 레이아웃 확장 조각 편집 문서 훅
 *
 * 확장 편집 모드(`editMode==='extension'`)에서 `/api/admin/templates/{id}/layout-extensions/
 * {extensionId}` 로 확장 content 를 로드/수정/저장한다. `useLayoutDocument` 와 동형의
 * 인터페이스(document/isDirty/patchLayout/setLayoutComponents/save/reload)를 제공해
 * 캔버스·오버레이·속성 모달 인프라를 그대로 재사용한다.
 *
 * 호스트 병합 렌더:
 *   확장 조각만 단독 렌더하지 않는다. 확장이 실제로 주입되는 **호스트 레이아웃 전체**를
 *   `/api/layouts/{id}/{host}.json?with_source_meta=1` 로 로드해 캔버스에 렌더하고(호스트는
 *   이미 `applyExtensions` 로 확장이 주입되고 `__source` 메타가 부여된 상태), 그 안에서 편집
 *   중인 확장 조각 노드(`__source.extensionId === 현재 확장`)만 편집 가능하게 한다. 호스트
 *  본체(base/route/partial)·타 확장 노드는 잠금 매트릭스(`isNodeLocked('extension',
 *   currentExtensionId)`)로 자물쇠+음영 처리된다.
 *
 *   - `document.raw` = 호스트 병합 트리(편집 중 확장 조각이 실제 주입 위치에 합성). 캔버스/
 *     오버레이/드래그/속성 인프라가 라우트/base 편집과 동일 코드 경로로 동작한다.
 *   - 편집(setLayoutComponents/patchLayout)은 호스트 트리 path 로 들어오고, 잠금 매트릭스가
 *     호스트 본체 편집을 차단하므로 실제 변형은 확장 조각 노드에만 일어난다.
 *   - 저장 시 호스트 트리에서 현재 확장의 노드만 추출(`__source.extensionId` 매칭)해 원본
 *     확장 content(`components`/`injections[].components`) 형태로 재조립 후 PUT 한다.
 *
 * 확장 content 두 형태:
 *  - extension_point: `{ extension_point, mode, components[], data_sources?, priority }`
 *    → 편집 단위 = `content.components` 트리.
 *  - overlay: `{ target_layout, injections[{ target_id, position, components?/props? }],
 *    priority }` → 편집 단위 = 각 injection 의 `components` 트리.
 *
 * 저장은 `PUT .../layout-extensions/{id}` 로 `{ content, expected_lock_version }` 전송.
 * content 의 비편집 키(extension_point/mode/priority/inject_props injection 등)는 무손실
 * 보존한다(원칙 4.4). `original_content_hash` 는 백엔드가 불변 유지해 모듈 업데이트 수정
 * 감지가 동작한다.
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { useLayoutEditor } from '../LayoutEditorContext';
import { buildAuthHeaders } from '../utils/authToken';
import { buildEditorAccessError, buildNetworkError } from '../types/editorErrors';
import type { EditorAccessError } from '../types/editorErrors';
import type { EditorNode, NodeSource } from '../utils/layoutTreeUtils';
import { trackEditorDocument } from '../devtools/editorTrackers';
import { readSanctumToken } from '../utils/authToken';
import { getCacheBustNonce, bumpCacheBustNonce } from '../utils/editorCacheBust';
import type { SaveResult } from './useLayoutDocument';

/**
 * 확장 편집 모드의 가상 path(`__extension__/{extensionId}`)에서 extensionId 를 추출한다.
 * reducer ENTER_EXTENSION_EDIT 가 만드는 형식. 형식 불일치 시 null.
 *
 * @param path selectedRoute.path
 * @returns extensionId(문자열) 또는 null
 */
export function extractExtensionIdFromPath(path: string | null | undefined): string | null {
  if (typeof path !== 'string') return null;
  const prefix = '__extension__/';
  if (!path.startsWith(prefix)) return null;
  const id = path.slice(prefix.length);
  if (id === '' || id.includes('/')) return null;
  return id;
}

/**
 * 확장 content 의 편집 단위(components 트리)를 추출한다.
 *
 * - extension_point: `content.components`.
 * - overlay: components 를 가진 injection 들의 components 를 순서대로 평탄화. 각 노드에
 *   `__injectionIndex` 메타를 부여해 저장 시 원래 injection 으로 되돌린다.
 *
 * @param content 파싱된 확장 content
 * @returns 편집 가능한 루트 노드 배열
 */
export function extractEditableComponents(content: Record<string, unknown>): EditorNode[] {
  if (Array.isArray(content.components)) {
    return content.components as EditorNode[];
  }
  if (Array.isArray(content.injections)) {
    const roots: EditorNode[] = [];
    (content.injections as any[]).forEach((inj, injectionIndex) => {
      if (inj && Array.isArray(inj.components)) {
        for (const node of inj.components as EditorNode[]) {
          // 저장 시 어느 injection 으로 되돌릴지 식별하기 위한 편집기 전용 메타.
          roots.push({ ...(node as any), __injectionIndex: injectionIndex });
        }
      }
    });
    return roots;
  }
  return [];
}

/**
 * 편집한 components 루트 배열을 원본 content 형태로 되돌린다(저장 직렬화).
 *
 * - extension_point: `content.components` 교체.
 * - overlay: `__injectionIndex` 메타로 각 노드를 원래 injection.components 로 분배,
 *   inject_props 등 components 없는 injection 은 보존.
 *
 * @param content 원본 파싱 content (비편집 키 보존용)
 * @param roots 편집된 루트 노드 배열
 * @returns 저장용 content 객체
 */
export function reassembleContent(
  content: Record<string, unknown>,
  roots: EditorNode[],
): Record<string, unknown> {
  if (Array.isArray(content.components)) {
    // 편집기 전용 메타 제거 후 교체.
    const cleaned = roots.map((n) => stripExtensionEditorMeta(n));
    return { ...content, components: cleaned };
  }
  if (Array.isArray(content.injections)) {
    const injections = (content.injections as any[]).map((inj) => ({ ...inj }));
    // components 를 가진 injection 의 components 를 비운 뒤 편집 결과로 재분배.
    injections.forEach((inj) => {
      if (Array.isArray(inj.components)) inj.components = [];
    });
    for (const node of roots) {
      const idx = (node as any).__injectionIndex;
      const target =
        typeof idx === 'number' && idx >= 0 && idx < injections.length ? injections[idx] : null;
      if (target) {
        if (!Array.isArray(target.components)) target.components = [];
        target.components.push(stripExtensionEditorMeta(node));
      }
    }
    return { ...content, injections };
  }
  return content;
}

/**
 * 편집기 전용/호스트 합성 메타(`__injectionIndex`/`__source`/`__injectedProps`/`_fromBase`)를
 * 노드 트리에서 재귀 제거한다(저장 직렬화 — 확장 content 원본 형태 복원).
 *
 * 호스트 병합 렌더에서 확장 조각 노드는 호스트 트리에 합성될 때 `__source`(extension
 * 메타)가 붙는다. 저장 시 그 메타는 백엔드 `applyExtensions` 가 다시 부여하므로 content 에는
 * 남기지 않는다(원본 형식 = 메타 미부여).
 */
export function stripExtensionEditorMeta(node: EditorNode): EditorNode {
  if (!node || typeof node !== 'object') return node;
  const copy: Record<string, unknown> = { ...(node as any) };
  delete copy.__injectionIndex;
  delete copy.__source;
  delete copy.__injectedProps;
  delete copy._fromBase;
  if (Array.isArray(copy.children)) {
    copy.children = (copy.children as EditorNode[]).map((c) => stripExtensionEditorMeta(c));
  }
  return copy as EditorNode;
}

/**
 * 호스트 병합 트리에서 현재 편집 중 확장(`extensionId`)의 노드만 추출한다(저장용).
 *
 * 호스트 트리를 순회하며 `__source.kind === 'extension'` 이고 `extensionId` 가 일치하는
 * 노드(= 이 확장이 주입한 조각의 진입점)를 수집한다. 진입점 노드의 자식은 같은 확장 메타를
 * 갖지만 진입점에 이미 포함되므로 자식으로는 내려가지 않는다(중복 방지). 호스트 본체/타
 * 확장 노드는 건너뛰되 그 자식은 재귀 탐색(확장 조각이 호스트 깊은 곳에 주입될 수 있음).
 *
 * @param components 호스트 병합 트리의 components 배열
 * @param extensionId 현재 편집 중 확장 PK
 * @returns 현재 확장의 진입점 노드 배열(원래 주입 순서 보존)
 */
export function extractCurrentExtensionNodes(
  components: EditorNode[] | undefined,
  extensionId: number,
): EditorNode[] {
  const collected: EditorNode[] = [];
  const walk = (nodes: EditorNode[] | undefined): void => {
    if (!Array.isArray(nodes)) return;
    for (const node of nodes) {
      if (!node || typeof node !== 'object') continue;
      const src = node.__source;
      if (src?.kind === 'extension' && src.extensionId === extensionId) {
        // 이 확장의 진입점 — 자식까지 통째로 수집(자식은 내려가지 않음).
        collected.push(node);
        continue;
      }
      // 호스트 본체/타 확장 — 자식 안에 이 확장 조각이 주입돼 있을 수 있어 재귀.
      if (Array.isArray(node.children)) {
        walk(node.children as EditorNode[]);
      }
    }
  };
  walk(components);
  return collected;
}

/** 로드된 확장 문서 */
export interface LoadedExtensionDocument {
  /** 확장 PK */
  extensionId: number;
  /** 파싱된 content 전체 (비편집 키 보존) */
  content: Record<string, unknown>;
  /** 확장 타입 */
  extensionType: 'extension_point' | 'overlay';
  /**
   * 캔버스 렌더 대상 — **호스트 병합 트리**(`.7). 호스트 레이아웃 전체에 편집 중 확장
   * 조각이 주입된 트리. 확장 조각 노드는 `__source.extensionId === extensionId` 로 식별되어
   * 편집 가능, 그 외(호스트 본체·타 확장)는 잠금 매트릭스로 차단된다.
   */
  components: EditorNode[];
  /** 낙관적 잠금 버전 */
  lockVersion: number;
  /**
   * 호스트 레이아웃 후보 — 확장이 주입되는 호스트 레이아웃들.
   * overlay = [target_layout], extension_point = 그 확장점을 포함하는 레이아웃 전체.
   */
  hostLayouts: string[];
  /**
   * 확정된 호스트 레이아웃명 (호스트 병합 렌더 대상). hostLayouts 가 1개면 그것,
   * 복수면 picker 선택 전까지 null(needsHostPicker=true).
   */
  hostLayoutName: string | null;
  /**
   * 호스트 레이아웃 원본 raw (G3 데이터 로드). 호스트의 `data_sources`/`meta`/
   * `modals` 등 비-components 키를 보존해, 캔버스가 호스트 바인딩(`{{...}}`)을 샘플 데이터로
   * 해석할 수 있게 한다(adaptExtensionToLayoutDocument 가 `{...hostRaw, components}` 로 합성).
   * 호스트 미병합(조각 단독 디그레이드)이면 빈 객체.
   */
  hostRaw: Record<string, unknown>;
  /**
   * 시각 편집 가능 여부.
   *  - `'ok'`            — 호스트 병합 트리에 이 확장의 주입 노드가 존재(시각 편집 가능 후보).
   *  - `'no-injection'`  — 호스트에 이 확장의 주입 노드가 0개(진짜 주입 0건 / 호스트 미병합).
   *  → 라이브 호스트 렌더 대신 디그레이드 안내(PreviewCanvas D-40-3).
   *  - `'pending'`       — 호스트 미확정(picker 대기) 또는 호스트 로드 실패. 폴백 대상이 아니다
   *                        (picker 모달/조각 단독 렌더 우선 — 라이브 호스트 렌더 결함과 무관).
   * 게이트/모달 뒤라 캔버스 DOM 에 안 보이는 `'gated-no-state'` 판정은 렌더 후 PreviewCanvas 의
   * 렌더 검증(2단계)이 수행한다 — 본 훅은 동기적으로 판정 가능한 1단계만 책임진다.
   */
  editability: 'ok' | 'no-injection' | 'pending';
}

export interface UseExtensionDocumentResult {
  document: LoadedExtensionDocument | null;
  isLoading: boolean;
  error: EditorAccessError | null;
  isDirty: boolean;
  saveSuccessCounter: number;
  setLayoutComponents: (next: EditorNode[]) => void;
  patchLayout: (patcher: (current: EditorNode[]) => EditorNode[]) => void;
  save: () => Promise<SaveResult>;
  reload: () => Promise<void>;
  /**
   * extension_point 확장이 복수 호스트에 주입돼 대표 호스트 선택(picker)이 필요한지.
   * true 면 LayoutEditorChrome 이 호스트 선택 모달을 띄우고 selectHost 로 확정한다.
   */
  needsHostPicker: boolean;
  /** 대표 호스트 레이아웃 확정 (picker 선택) — */
  selectHost: (hostLayoutName: string) => void;
}

/**
 * 확장 출처 메타(`extensionId` 등)를 components 트리에 재귀 부여한다.
 *
 * 호스트 병합 시 편집 중 확장 조각이 잠금 매트릭스에서 "편집 가능"으로 분류되려면
 * `__source.kind === 'extension'` + `extensionId` 메타가 필요하다. 확장 content 의 원본
 * components 는 메타가 없으므로(저장 시 제거됨), 호스트의 현재 확장 노드에서 캡처한 메타를
 * 편집 중 조각에 부여한다.
 *
 * @param nodes 메타를 부여할 노드 배열
 * @param meta 부여할 `__source` 메타(현재 확장)
 * @returns 메타가 부여된 노드 배열(새 사본)
 */
function applyExtensionSourceMeta(nodes: EditorNode[], meta: NodeSource): EditorNode[] {
  return nodes.map((node) => {
    if (!node || typeof node !== 'object') return node;
    const copy: EditorNode = { ...node, __source: meta };
    if (Array.isArray(copy.children)) {
      copy.children = applyExtensionSourceMeta(copy.children as EditorNode[], meta);
    }
    return copy;
  });
}

/**
 * 호스트 병합 트리에서 현재 확장 노드의 `__source` 메타를 찾는다(편집 조각 재태깅용).
 * 못 찾으면 최소 메타(`{kind:'extension', extensionId}`)로 폴백.
 */
function findExtensionMeta(components: EditorNode[] | undefined, extensionId: number): NodeSource {
  const found = extractCurrentExtensionNodes(components, extensionId)[0];
  const src = found?.__source;
  if (src && src.kind === 'extension' && src.extensionId === extensionId) {
    return src;
  }
  return { kind: 'extension', extensionId };
}

/**
 * 편집 중 확장 조각(`editableComponents`)을 호스트 병합 트리에 합성한다.
 *
 * 호스트 트리(`hostComponents`)에서 현재 확장이 주입된 노드 run 을 편집 중 조각으로
 * 치환한다. 백엔드가 부여한 확장 메타를 편집 조각에 부여해 잠금 매트릭스에서 편집 가능으로
 * 분류되게 한다(`__injectionIndex` 등 편집기 전용 메타도 보존).
 *
 * 치환 정책: 같은 부모 children 안의 연속된 현재-확장 노드 run 을 한 번에 편집 조각으로
 * 교체한다(extension_point 는 한 자리에 연속 주입, overlay 도 injection 별로 자리 보존).
 * 현재-확장 노드가 없는 호스트 부분은 그대로, 그 자식은 재귀 합성.
 *
 * @param hostComponents 호스트 병합 트리 components
 * @param editableComponents 편집 중 확장 조각(메타 미부여 원본 또는 직전 편집 상태)
 * @param extensionId 현재 확장 PK
 * @param meta 편집 조각에 부여할 확장 메타
 * @returns 편집 조각이 합성된 호스트 트리
 */
export function mergeEditableIntoHost(
  hostComponents: EditorNode[],
  editableComponents: EditorNode[],
  extensionId: number,
  meta: NodeSource,
): EditorNode[] {
  const tagged = applyExtensionSourceMeta(editableComponents, meta);
  let injected = false;
  const result: EditorNode[] = [];
  let i = 0;
  while (i < hostComponents.length) {
    const node = hostComponents[i]!;
    const src = node?.__source;
    const isCurrent = src?.kind === 'extension' && src.extensionId === extensionId;
    if (isCurrent) {
      // 현재-확장 노드 run 의 끝까지 스킵하고, 그 자리에 편집 조각 1회 삽입.
      while (
        i < hostComponents.length &&
        hostComponents[i]?.__source?.kind === 'extension' &&
        hostComponents[i]?.__source?.extensionId === extensionId
      ) {
        i++;
      }
      if (!injected) {
        result.push(...tagged);
        injected = true;
      }
      continue;
    }
    // 호스트 본체/타 확장 — 자식 재귀 합성(아직 미주입일 때만 더 내려가 탐색).
    if (!injected && Array.isArray(node.children) && (node.children as EditorNode[]).length > 0) {
      const childMerged = mergeEditableIntoHost(
        node.children as EditorNode[],
        editableComponents,
        extensionId,
        meta,
      );
      if (childMerged !== node.children) {
        // 자식에서 주입됐는지 확인 — 변형이 있으면 주입된 것으로 간주.
        injected = injected || hasExtensionNode(childMerged, extensionId);
      }
      result.push({ ...node, children: childMerged });
      i++;
      continue;
    }
    result.push(node);
    i++;
  }
  return result;
}

/** 트리에 현재 확장 노드가 존재하는지(합성 후 확인용). */
function hasExtensionNode(nodes: EditorNode[], extensionId: number): boolean {
  for (const node of nodes) {
    const src = node?.__source;
    if (src?.kind === 'extension' && src.extensionId === extensionId) return true;
    if (Array.isArray(node.children) && hasExtensionNode(node.children as EditorNode[], extensionId)) {
      return true;
    }
  }
  return false;
}

/**
 * 확장 편집 문서 훅. editMode==='extension' 이고 selectedRoute.path 가 `__extension__/{id}`
 * 일 때만 로드한다. 호스트 병합 렌더 — 호스트 레이아웃 전체에 편집 중 확장 조각을
 * 합성해 캔버스에 노출하고, 저장은 확장 조각만 추출해 layout-extensions API 로 PUT 한다.
 */
export function useExtensionDocument(): UseExtensionDocumentResult {
  const { state } = useLayoutEditor();
  const templateIdentifier = state.templateIdentifier;
  const extensionId =
    state.editMode === 'extension'
      ? extractExtensionIdFromPath(state.selectedRoute?.path)
      : null;
  // 진입 시점에 호스트가 확정됐으면(라우트 하위 진입·overlay — reducer 가 selectedRoute.layoutName
  // 에 담음) picker 없이 그 호스트로 병합 렌더한다.
  const preConfirmedHost =
    state.editMode === 'extension' && typeof state.selectedRoute?.layoutName === 'string'
      ? state.selectedRoute.layoutName
      : null;

  const [document, setDocument] = useState<LoadedExtensionDocument | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<EditorAccessError | null>(null);
  const [isDirty, setIsDirty] = useState(false);
  const [saveSuccessCounter, setSaveSuccessCounter] = useState(0);
  // 사용자가 picker 로 확정한 대표 호스트. 확장 전환 시 리셋.
  const [pickedHost, setPickedHost] = useState<string | null>(null);

  // 저장용 SSoT — 원본 content(비편집 키 보존) + 현재 확장의 source 메타.
  const contentRef = useRef<Record<string, unknown>>({});
  const extensionMetaRef = useRef<NodeSource>({ kind: 'extension', extensionId: 0 });
  const documentRef = useRef<LoadedExtensionDocument | null>(null);
  useEffect(() => {
    documentRef.current = document;
  }, [document]);

  // 확장이 바뀌면 picker 선택 리셋(이전 확장의 호스트 선택이 새 확장에 새지 않게).
  useEffect(() => {
    setPickedHost(null);
  }, [extensionId]);

  /**
   * 호스트 레이아웃을 `with_source_meta=1` 로 fetch 한다(이미 확장이 주입되고
   * `__source` 메타가 부여된 병합 트리). useLayoutDocument 의 라우트/base 로드와 동일 경로.
   */
  const fetchHostLayout = useCallback(
    async (hostLayoutName: string): Promise<Record<string, unknown> | null> => {
      const cacheVersion = (window as any).G7Config?.cache_version ?? 0;
      // 클라이언트 캐시-버스트 nonce 합성 — useLayoutDocument 와 공용 카운터.
      // 버전 복원/저장 후 reload 시 HTTP 캐시 stale 호스트 응답을 우회한다(확장 캔버스 미갱신 결함).
      const url = `/api/layouts/${encodeURIComponent(
        templateIdentifier,
      )}/${hostLayoutName}.json?with_source_meta=1&v=${cacheVersion}.${getCacheBustNonce()}`;
      const token = readSanctumToken();
      const headers: Record<string, string> = { Accept: 'application/json' };
      if (token) headers.Authorization = `Bearer ${token}`;
      const response = await fetch(url, { headers, credentials: 'same-origin' });
      if (!response.ok) return null;
      const body = await response.json().catch(() => null);
      // 전체 raw 보존(components 외 data_sources/meta/modals 등 — G3 호스트 바인딩 샘플 해석).
      return ((body && (body.data || body)) ?? {}) as Record<string, unknown>;
    },
    [templateIdentifier],
  );

  const fetchDocument = useCallback(async (): Promise<void> => {
    if (!extensionId) {
      setDocument(null);
      setError(null);
      setIsDirty(false);
      return;
    }
    setIsLoading(true);
    setError(null);
    try {
      const cacheVersion = (window as any).G7Config?.cache_version ?? 0;
      const url = `/api/admin/templates/${encodeURIComponent(
        templateIdentifier,
      )}/layout-extensions/${encodeURIComponent(extensionId)}?v=${cacheVersion}`;
      const response = await fetch(url, {
        headers: buildAuthHeaders(),
        credentials: 'same-origin',
      });
      const body = await response.json().catch(() => null);
      if (!response.ok) {
        setError(buildEditorAccessError(response.status, body, 'layout'));
        setDocument(null);
        setIsDirty(false);
        return;
      }
      const data = (body && (body.data || body)) ?? {};
      // content 는 JSON 문자열(LayoutExtensionResource) 또는 객체. 양쪽 모두 처리.
      let content: Record<string, unknown> = {};
      const rawContent = (data as any).content;
      if (typeof rawContent === 'string') {
        try {
          content = JSON.parse(rawContent);
        } catch {
          content = {};
        }
      } else if (rawContent && typeof rawContent === 'object') {
        content = rawContent;
      }
      const extensionType =
        (data as any).extension_type === 'overlay' ? 'overlay' : 'extension_point';
      const lockVersion =
        typeof (data as any).lock_version === 'number' ? (data as any).lock_version : 0;

      // 호스트 레이아웃 후보 — data.host_layouts (컨트롤러가 resource 에 병합).
      const hostLayouts: string[] = Array.isArray((data as any)?.host_layouts)
        ? ((data as any).host_layouts as unknown[]).filter((h): h is string => typeof h === 'string')
        : [];
      // 호스트 확정 우선순위:
      //  1) 진입 시 확정된 호스트(preConfirmedHost — 라우트 하위 진입·overlay): picker 생략.
      //  2) 후보 1개: 그것.
      //  3) picker 로 고른 것.
      //  4) 복수+미선택: null → picker.
      // preConfirmedHost 가 후보 목록에 없어도(connectedHost 등 신뢰 진입) 그대로 사용한다.
      const hostLayoutName =
        preConfirmedHost ??
        (hostLayouts.length === 1
          ? hostLayouts[0]
          : pickedHost && hostLayouts.includes(pickedHost)
            ? pickedHost
            : null);

      const numericId = Number(extensionId);
      contentRef.current = content;

      // 편집 단위(확장 조각) 추출 — 저장 직렬화 SSoT.
      const editableComponents = extractEditableComponents(content);

      // 호스트 병합 렌더 — 호스트 확정 시 호스트 트리를 로드해 편집 조각을 합성.
      // 호스트 미확정(picker 필요) 또는 호스트 로드 실패 시 조각만 노출(디그레이드).
      let canvasComponents: EditorNode[] = editableComponents;
      let hostRaw: Record<string, unknown> = {};
      // 시각 편집 가능 여부 1단계 판정. 호스트 병합 트리에 이 확장의 주입 노드가
      // 존재하면 'ok'(시각 편집 후보 — 게이트 뒤라도 상태 시뮬레이션으로 노출 가능), 호스트에
      // 주입 노드가 0개면 'no-injection'(진짜 주입 0건/호스트 미병합 — 라이브 렌더 금지, 폴백).
      // 호스트 미확정(picker 대기)/로드 실패는 'pending' — 폴백 대상이 아니다(picker 모달 우선).
      let editability: 'ok' | 'no-injection' | 'pending' = 'pending';
      const fallbackMeta: NodeSource = { kind: 'extension', extensionId: numericId };
      extensionMetaRef.current = fallbackMeta;
      if (hostLayoutName) {
        const loadedHostRaw = await fetchHostLayout(hostLayoutName);
        if (loadedHostRaw) {
          hostRaw = loadedHostRaw;
          const hostComponents = Array.isArray(loadedHostRaw.components)
            ? (loadedHostRaw.components as EditorNode[])
            : [];
          const meta = findExtensionMeta(hostComponents, numericId);
          extensionMetaRef.current = meta;
          // **호스트 트리를 그대로 캔버스에 렌더한다**(라우트 편집 모드는
          // 호스트 노드를 그대로 렌더해 정상 동작). 호스트의 현재-확장 노드는 백엔드
          // applyExtensions 가 이미 주입하며 `extensionPointProps`(호스트 EP 노드 props →
          // 자식 전달)·`__source.extensionId` 를 부여한 상태다. 이 노드를 확장 content 원본
          // (editableComponents — extensionPointProps 미부여)으로 **치환하면**, HtmlContent 처럼
          // `{{extensionPointProps.content}}` 를 읽는 위젯이 빈값으로 평가돼 미렌더된다(답글 인용
          // html_content 가 캔버스에서 사라지던 결함의 근본 원인). 따라서 치환하지 않고 호스트
          // 노드를 그대로 두면 표현식이 정상 평가되고, `__source.extensionId` 로 편집 대상 식별·
          // 잠금 매트릭스·딤(SourceLockDimLayer)이 그대로 동작한다. 편집 조각(editableComponents)은
          // 저장 시 extractCurrentExtensionNodes 로 호스트 트리에서 추출하므로 별도 합성 불필요.
          if (hasExtensionNode(hostComponents, numericId)) {
            canvasComponents = hostComponents;
            editability = 'ok';
          } else {
            // components 에 없으면 모달 내 주입(register termsModal/privacyModal,
            // addresses/orders 주소 모달, _user_base 본인인증 모달 등)을 탐색한다.
            // applyExtensionPoints/applyOverlays 는 components 와 modals 를 모두 순회하므로
            // 주입 노드가 modals[] 안에만 존재할 수 있다. 캔버스는 raw.components 만 렌더하므로
            // (모달은 modalStack 열림 시에만 — 편집기 정적 시뮬레이션에선 닫힘), 모달 편집
            // 모드와 동형으로 **주입 모달 노드만 표시용 isOpen=true 로 components
            // 끝에 append** 해 노출한다. 표시용 사본이라 운영 content 무오염 — 확장 모드 저장은
            // extractCurrentExtensionNodes 로 확장 노드만 추출해 layout-extensions 에 PUT 하며
            // 호스트 레이아웃은 저장하지 않는다.
            const hostModals = Array.isArray(loadedHostRaw.modals)
              ? (loadedHostRaw.modals as EditorNode[])
              : [];
            const containingModals = hostModals.filter(
              (m) => m && typeof m === 'object' && hasExtensionNode([m], numericId),
            );
            if (containingModals.length > 0) {
              const displayModals = containingModals.map((m) => ({
                ...m,
                props: { ...((m.props as Record<string, unknown>) ?? {}), isOpen: true },
              }));
              canvasComponents = [...hostComponents, ...(displayModals as EditorNode[])];
              extensionMetaRef.current = findExtensionMeta(containingModals, numericId);
              editability = 'ok';
            } else {
              // 호스트에 이 확장의 주입 노드가 없음(비활성/탐지 실패/진짜 주입 0건) → 호스트를
              // 라이브로 렌더하지 않고 'no-injection' 폴백. 호스트 끝 append
              // 디그레이드는 하지 않는다(라이브 유저 화면 렌더 결함 방지).
              canvasComponents = hostComponents;
              editability = 'no-injection';
            }
          }
        }
      }

      setDocument({
        extensionId: numericId,
        content,
        extensionType,
        components: canvasComponents,
        lockVersion,
        hostLayouts,
        hostLayoutName,
        hostRaw,
        editability,
      });
      setIsDirty(false);
      trackEditorDocument({
        op: 'load',
        layoutName: `extension:${extensionId}`,
        editMode: 'extension',
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
  }, [templateIdentifier, extensionId, pickedHost, preConfirmedHost, fetchHostLayout]);

  useEffect(() => {
    fetchDocument();
  }, [fetchDocument]);

  const selectHost = useCallback((hostLayoutName: string): void => {
    setPickedHost(hostLayoutName);
  }, []);

  const setLayoutComponents = useCallback((next: EditorNode[]): void => {
    setDocument((prev) => (prev ? { ...prev, components: next } : prev));
    setIsDirty(true);
    trackEditorDocument({
      op: 'patch',
      layoutName: `extension:${documentRef.current?.extensionId ?? ''}`,
      editMode: 'extension',
      isDirty: true,
      timestamp: Date.now(),
    });
  }, []);

  const patchLayout = useCallback(
    (patcher: (current: EditorNode[]) => EditorNode[]): void => {
      setDocument((prev) => {
        if (!prev) return prev;
        return { ...prev, components: patcher(prev.components) };
      });
      setIsDirty(true);
    },
    [],
  );

  const save = useCallback(async (): Promise<SaveResult> => {
    const current = documentRef.current;
    if (!current) return { kind: 'guard_no_document' };

    const url = `/api/admin/templates/${encodeURIComponent(
      templateIdentifier,
    )}/layout-extensions/${current.extensionId}`;

    // 호스트 병합 트리(current.components)에서 현재 확장의 노드만 추출 — 호스트 미병합
    // (조각 단독 디그레이드)이면 전체가 곧 조각이므로 추출이 동일 결과를 낸다.
    const extracted = extractCurrentExtensionNodes(current.components, current.extensionId);
    const fragmentRoots = extracted.length > 0 ? extracted : current.components;
    // 편집한 components 를 원본 content 형태로 재조립(비편집 키 보존, 메타 제거).
    const contentToSave = reassembleContent(contentRef.current, fragmentRoots);

    trackEditorDocument({
      op: 'save',
      layoutName: `extension:${current.extensionId}`,
      editMode: 'extension',
      saveTarget: 'layout_extension',
      endpoint: url,
      isDirty,
      timestamp: Date.now(),
    });

    let response: Response;
    try {
      response = await fetch(url, {
        method: 'PUT',
        headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
        credentials: 'same-origin',
        body: JSON.stringify({
          // content 는 백엔드가 전체 배열을 input() 으로 받는다(LayoutExtensionController::update).
          content: contentToSave,
          expected_lock_version: current.lockVersion,
        }),
      });
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'network error';
      return { kind: 'network_error', message };
    }

    const body = await response.json().catch(() => null);

    trackEditorDocument({
      op: 'save_response',
      layoutName: `extension:${current.extensionId}`,
      editMode: 'extension',
      endpoint: url,
      statusCode: response.status,
      conflict:
        response.status === 409
          ? {
              // 백엔드는 충돌 정보를 errors.* 로 감싼다(ResponseHelper::error). 루트 폴백 동반.
              currentVersion: (body as any)?.errors?.current_version ?? (body as any)?.current_version,
              yourVersion: (body as any)?.errors?.your_version ?? (body as any)?.your_version,
            }
          : undefined,
      timestamp: Date.now(),
    });

    if (response.ok) {
      const data = (body as any)?.data ?? {};
      const newLockVersion =
        typeof data.lock_version === 'number' ? data.lock_version : current.lockVersion + 1;
      setDocument((prev) => (prev ? { ...prev, lockVersion: newLockVersion } : prev));
      setIsDirty(false);
      setSaveSuccessCounter((n) => n + 1);
      // 확장 노드 버전 배지 동기화 — 저장 응답의 현재 버전 번호를
      // 함께 반환한다(LayoutExtensionResource.current_version). 구버전 백엔드는 자연 생략.
      const newContentVersion =
        typeof data.current_version === 'number' ? (data.current_version as number) : undefined;
      return {
        kind: 'success',
        newLockVersion,
        newContentVersion,
        savedExtensionId: String(current.extensionId),
      };
    }
    if (response.status === 409) {
      return {
        kind: 'concurrent_modification',
        currentVersion: (body as any)?.current_version ?? -1,
        yourVersion: (body as any)?.your_version ?? current.lockVersion,
      };
    }
    if (response.status === 422) {
      return {
        kind: 'validation_failed',
        status: 422,
        errors: ((body as any)?.errors ?? null) as Record<string, string[]> | null,
      };
    }
    return { kind: 'network_error', message: (body as any)?.message ?? `HTTP ${response.status}` };
  }, [templateIdentifier, isDirty]);

  const reload = useCallback(async (): Promise<void> => {
    // 캐시-버스트 nonce 증가 — reload 는 "서버 최신 강제 회수" 의미이므로
    // fetchHostLayout 의 `?v=` URL 을 달리해 HTTP 캐시 stale 호스트 응답을 우회한다(확장 캔버스
    // 미갱신 결함). useLayoutDocument.reload 와 동일 정책 + 공용 카운터.
    bumpCacheBustNonce();
    await fetchDocument();
  }, [fetchDocument]);

  // 복수 호스트 + 미선택이면 picker 필요.
  const needsHostPicker =
    !!document && document.hostLayouts.length > 1 && document.hostLayoutName === null;

  return {
    document,
    isLoading,
    error,
    isDirty,
    saveSuccessCounter,
    setLayoutComponents,
    patchLayout,
    save,
    reload,
    needsHostPicker,
    selectHost,
  };
}
