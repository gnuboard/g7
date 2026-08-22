/**
 * RouteTreePanel.tsx
 *
 * 좌측 라우트 트리 패널 — Phase 1 골격.
 *
 * 그룹 5종 표시 (공통 레이아웃 / 템플릿 / 모듈:N / 플러그인:N / 모달).
 * 접기/펼치기 토글. 라우트 클릭 시 SELECT_ROUTE.
 *
 * 라벨이 `$t:` 키이면 코어 TranslationEngine 이 자동 해석.
 *
 * @since engine-v1.50.0
 */

import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from '../../TranslationContext';
import type { TranslationEngine, TranslationContext as ITranslationContext } from '../../TranslationEngine';
import { useLayoutEditor, type RouteTreeNode } from '../LayoutEditorContext';
import { useLayoutDocumentContext } from '../LayoutDocumentContext';
import { buildEditorUrl, EDITOR_HISTORY_STATE_MARKER } from '../hooks/useEditorMode';

// 안정 식별자 — docCtx 부재(Provider 밖 테스트 등) 시 매 렌더 새 Set 생성 회피.
const EMPTY_DIRTY_SET: ReadonlySet<string> = new Set();

/**
 * 그룹 헤더 노드 여부 — 최상위 그룹(`__group__/`)과 라우트 자식의 연결 그룹
 * (`__conngroup__/`화면별 모달/확장 목록 헤더) 둘 다 헤더로 취급한다.
 * 헤더는 선택 불가 + 헤더 스타일(소문자 회색) + 검색 강조 제외.
 *
 * @param node 라우트 트리 노드
 * @returns 그룹 헤더면 true
 */
function isGroupNode(node: RouteTreeNode): boolean {
  return node.path.startsWith('__group__/') || node.path.startsWith('__conngroup__/');
}

/** 라벨 해석에 필요한 번역 컨텍스트 묶음 — 검색 필터/렌더가 공유하는 SSoT. */
interface LabelResolveCtx {
  t: (key: string, params?: Record<string, string>) => string;
  translationEngine: TranslationEngine | null;
  translationContext: ITranslationContext | null;
  templateIdentifier: string;
}

/**
 * 라우트 트리 노드의 표시 라벨을 해석한다 (`$t:` 키 → 사람이 읽는 텍스트).
 *
 * 종전 RouteTreeItem 내부 클로저였던 로직을 검색 필터와 렌더가 함께 쓰도록 추출.
 * 라우트 노드는 편집 대상 템플릿 사전 우선 조회 후 부팅/전역/path 순으로 폴백한다.
 *
 * @param node 라우트 트리 노드
 * @param ctx 번역 컨텍스트 묶음
 * @returns 해석된 표시 라벨
 * @since engine-v1.50.0
 */
function resolveNodeLabel(node: RouteTreeNode, ctx: LabelResolveCtx): string {
  const { t, translationEngine, translationContext, templateIdentifier } = ctx;
  const isGroup = isGroupNode(node);
  if (!node.label.startsWith('$t:')) return node.label;
  const stripped = node.label.slice(3);
  const pipeIdx = stripped.indexOf('|');
  const key = pipeIdx === -1 ? stripped : stripped.slice(0, pipeIdx);
  const params: Record<string, string> = {};
  if (pipeIdx !== -1) {
    for (const pair of stripped.slice(pipeIdx + 1).split('|')) {
      const eqIdx = pair.indexOf('=');
      if (eqIdx === -1) continue;
      params[pair.slice(0, eqIdx)] = pair.slice(eqIdx + 1);
    }
  }

  // 라우트/모달 노드는 편집 대상 lang 우선 조회 후 fallback.
  // 모달도 포함하는 이유: 편집기 최초 렌더 시 코어 t() 의 편집대상 번역이 전역 컨텍스트에
  // 반영되기 전이면 모달 라벨이 `$t:` 키 raw 로 노출되던 결함. translationEngine
  // 으로 편집대상 dictionary 를 직접 조회하면 로드 타이밍과 무관하게 즉시 해석된다.
  if (
    (node.kind === 'route' || node.kind === 'modal') &&
    !isGroup &&
    translationEngine &&
    translationContext &&
    templateIdentifier !== translationContext.templateId
  ) {
    const targetCtx = {
      templateId: templateIdentifier,
      locale: translationContext.locale,
    };
    const paramsStr =
      Object.keys(params).length > 0
        ? '|' + Object.entries(params).map(([k, v]) => `${k}=${v}`).join('|')
        : undefined;
    const targetResult = translationEngine.translate(key, targetCtx, paramsStr);
    if (targetResult !== key) return targetResult;
  }

  const result = t(key, params);
  if (result === key && typeof window !== 'undefined') {
    const globalT = (window as any).G7Core?.t;
    if (typeof globalT === 'function') {
      try {
        const globalResult = globalT(key, params);
        if (globalResult !== key) return globalResult;
      } catch {
        // 전역 t 가 throw 하면 path 폴백으로 진행
      }
    }
  }
  if (result === key && node.kind === 'route' && !isGroup) {
    const path = node.path;
    if (path && path !== '/') {
      const segments = path.split('/').filter(Boolean);
      return segments[segments.length - 1] ?? path;
    }
    return path === '/' ? '/' : key;
  }
  return result;
}

/**
 * 검색어를 정규화한다 (소문자 + 좌우 공백 제거). 빈 문자열이면 검색 비활성.
 *
 * @param raw 입력 검색어
 * @returns 정규화 결과
 */
function normalizeQuery(raw: string): string {
  return raw.trim().toLocaleLowerCase();
}

/**
 * 텍스트에서 검색어와 일치하는 부분을 `<mark>` 로 강조한 React 노드 배열을 만든다.
 *
 * 대소문자 무시 매칭. 검색어가 비었거나 매칭이 없으면 원문 텍스트만 반환한다.
 *
 * @param text 표시 텍스트
 * @param query 정규화된 검색어 (소문자)
 * @returns 강조 적용된 React 노드 (또는 원문 문자열)
 * @since engine-v1.50.0
 */
function highlightMatch(text: string, query: string): React.ReactNode {
  if (!query) return text;
  const lower = text.toLocaleLowerCase();
  const parts: React.ReactNode[] = [];
  let cursor = 0;
  let matchIdx = lower.indexOf(query, cursor);
  if (matchIdx === -1) return text;
  let segKey = 0;
  while (matchIdx !== -1) {
    if (matchIdx > cursor) parts.push(text.slice(cursor, matchIdx));
    parts.push(
      <mark
        key={`m${segKey++}`}
        className="g7le-route-tree__highlight"
        style={{ background: '#fde68a', color: 'inherit', borderRadius: 2, padding: '0 1px' }}
      >
        {text.slice(matchIdx, matchIdx + query.length)}
      </mark>,
    );
    cursor = matchIdx + query.length;
    matchIdx = lower.indexOf(query, cursor);
  }
  if (cursor < text.length) parts.push(text.slice(cursor));
  return parts;
}

/**
 * 노드의 레이아웃 파일 전체 경로 문자열을 구성한다 (확장 prefix 포함).
 *
 * routes 의 `layout` 값(= layoutName)은 레이아웃 디렉토리 기준 상대 경로(확장자 제외)다.
 * 출처(source.kind)별로 실제 파일이 위치하는 디렉토리 규약이 다르므로 prefix 를 달리한다:
 *   - template:        `{identifier} · layouts/{layoutName}.json`
 *   - module/plugin:   `{identifier} · resources/layouts/{layoutName}.json`
 *   - core(base 등):   `layouts/{layoutName}.json`
 *
 * layoutName 이 없으면(그룹/확장 가상 노드 등) null 을 반환해 표시하지 않는다.
 *
 * @param node 라우트 트리 노드
 * @returns 표시용 경로 문자열 또는 null
 * @since engine-v1.50.0
 */
function buildLayoutPathLabel(node: RouteTreeNode): string | null {
  if (!node.layoutName) return null;
  const file = `${node.layoutName}.json`;
  switch (node.source.kind) {
    case 'module':
    case 'plugin': {
      const prefix = node.source.identifier ? `${node.source.identifier} · ` : '';
      return `${prefix}resources/layouts/${file}`;
    }
    case 'template': {
      const prefix = node.source.identifier ? `${node.source.identifier} · ` : '';
      return `${prefix}layouts/${file}`;
    }
    default:
      // core(base 레이아웃 등) — 식별자 없음
      return `layouts/${file}`;
  }
}

function NodeBadges({ node }: { node: RouteTreeNode }): React.ReactElement | null {
  const { t } = useTranslation();
  const badges: React.ReactElement[] = [];
  const badgeBase: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    padding: '1px 6px',
    fontSize: 10,
    fontWeight: 600,
    borderRadius: 4,
    letterSpacing: 0.3,
    textTransform: 'uppercase',
    border: '1px solid transparent',
  };
  if (node.isHidden) {
    badges.push(
      <span
        key="hidden"
        className="g7le-route-tree__badge g7le-route-tree__badge--hidden"
        data-testid="g7le-route-tree-badge-hidden"
        style={{
          ...badgeBase,
          background: '#f1f5f9',
          color: '#64748b',
          borderColor: '#e2e8f0',
        }}
      >
        {t('layout_editor.chrome.route_tree.badge.hidden')}
      </span>
    );
  }
  if (node.isRedirect) {
    badges.push(
      <span
        key="redirect"
        className="g7le-route-tree__badge g7le-route-tree__badge--redirect"
        data-testid="g7le-route-tree-badge-redirect"
        style={{
          ...badgeBase,
          background: '#fef3c7',
          color: '#92400e',
          borderColor: '#fde68a',
        }}
      >
        {t('layout_editor.chrome.route_tree.badge.redirect')}
      </span>
    );
  }
  if (badges.length === 0) return null;
  return <span style={{ marginLeft: 'auto', display: 'inline-flex', gap: 4 }}>{badges}</span>;
}

function RouteTreeItem({
  node,
  depth,
  onSelect,
  selectedRoutePath,
  activeHostRoutePath,
  dirtyLayoutNames,
  query,
  forceExpand,
}: {
  node: RouteTreeNode;
  depth: number;
  onSelect: (node: RouteTreeNode) => void;
  selectedRoutePath: string | null;
  /**
   * 현재 별도 편집 중(modal/extension)인 항목이 속한 호스트 라우트 path.
   * 이 path 의 라우트 노드는 selectedRoute 가 가상 path 로 바뀌어도 강조를 유지한다.
   */
  activeHostRoutePath: string | null;
  dirtyLayoutNames: ReadonlySet<string>;
  /** 정규화된 검색어 (소문자) — 빈 문자열이면 강조 없음 */
  query: string;
  /** 검색 중 연결 그룹 강제 펼침 (매칭 결과를 가리지 않도록) */
  forceExpand: boolean;
}): React.ReactElement {
  const { t, translationEngine, translationContext } = useTranslation();
  const { state } = useLayoutEditor();
  const isGroup = isGroupNode(node);
  // 연결 그룹(이 화면의 모달 / 주입되는 확장)은 헤더 클릭으로 접기/펼치기. 기본 접힘이라
  // 라우트를 펼쳐도 트리가 길어지지 않는다. 일반 그룹(__group__)은
  // 항상 펼침(종전 동작 유지).
  const isConnGroup = node.path.startsWith('__conngroup__/');
  // 현재 활성(선택) 별도 편집 대상이 이 연결 그룹 안에 있으면 자동 펼침.
  // `?edit=__extension__/{id}` 직접 진입·새로고침 시 그 확장이 속한 "주입되는 확장" 그룹이
  // 접혀 있으면 어느 라우트의 확장인지 안 보인다 → 활성 항목 포함 그룹은 펼쳐 노출한다.
  const activeEditTargetId = useMemo<string | null>(() => {
    const p = state.selectedRoute?.path ?? '';
    if (p.startsWith('__extension__/') || p.startsWith('__modal__/')) {
      return p.slice(p.lastIndexOf('/') + 1);
    }
    return null;
  }, [state.selectedRoute?.path]);
  const containsActiveTarget = useMemo<boolean>(() => {
    if (!isConnGroup || !activeEditTargetId) return false;
    return (node.children ?? []).some(
      (c) =>
        (c.kind === 'extension' && c.extensionId === activeEditTargetId) ||
        (c.kind === 'modal' && c.modalId === activeEditTargetId),
    );
  }, [isConnGroup, activeEditTargetId, node.children]);
  const [connCollapsed, setConnCollapsed] = useState(true);
  // 활성 대상이 이 그룹에 들어오면 펼친다(사용자가 수동으로 접기 전까지). 그룹 밖으로 활성이
  // 빠지면 사용자 토글 상태를 보존한다(자동으로 다시 접지 않음 — 깜빡임 방지).
  useEffect(() => {
    if (containsActiveTarget) setConnCollapsed(false);
  }, [containsActiveTarget]);
  // 호스트 라우트 강조 유지 — 모달/확장 자식 클릭으로 가상 path 가 선택돼도 이 라우트를 강조.
  //
  // 확장 노드 강조 — host 까지 일치해야 한다. 같은 확장(PK)이 여러 라우트
  // 하위에 중복 표시되는데(예: CKEditor html_content 가 글쓰기·상품상세 양쪽 host), path 가
  // `__extension__/{id}` 로 동일해 path 만 비교하면 같은 확장의 모든 트리 위치가 동시 강조된다.
  // 진입 시 확정된 host(selectedRoute.layoutName)와 이 노드의 host 가 일치할 때만 강조한다.
  const selectedExtHost =
    state.editMode === 'extension' ? (state.selectedRoute?.layoutName ?? null) : null;
  const nodeExtHost =
    node.kind === 'extension' ? resolveExtensionHost(node, state.routeTree) ?? null : null;
  const isExtensionPathMatch =
    node.kind === 'extension' &&
    selectedRoutePath === node.path &&
    // host 가 양쪽 다 확정된 경우만 host 일치 요구. selectedExtHost 미확정(picker 전)이면 path 매칭.
    (selectedExtHost === null || nodeExtHost === null || selectedExtHost === nodeExtHost);
  // 모달 노드 강조 — modalId 매칭. 트리 모달 노드 path 는 `__modal__/{hostLayout}/
  // {modalId}` 인데 reducer 의 selectedRoute.path 는 `__modal__/{modalId}`(hostLayout 미포함)라
  // path 직접 비교가 어긋나 모달 편집 진입 시 좌측 트리에 선택 강조가 안 떴다. 확장과
  // 동일하게 식별자(modalId) + host 일치로 강조한다(같은 modalId 가 여러 host 에 중복될 때 구분).
  const selectedModalId =
    state.editMode === 'modal' && selectedRoutePath?.startsWith('__modal__/')
      ? selectedRoutePath.slice(selectedRoutePath.lastIndexOf('/') + 1)
      : null;
  const selectedModalHost =
    state.editMode === 'modal' ? (state.selectedRoute?.layoutName ?? null) : null;
  const isModalPathMatch =
    node.kind === 'modal' &&
    !!selectedModalId &&
    node.modalId === selectedModalId &&
    (selectedModalHost === null ||
      node.modalHostLayout == null ||
      selectedModalHost === node.modalHostLayout);
  const isSelected =
    (node.kind !== 'extension' && node.kind !== 'modal' && selectedRoutePath === node.path) ||
    isExtensionPathMatch ||
    isModalPathMatch ||
    (node.kind === 'route' && !isGroup && activeHostRoutePath === node.path);
  const hasChildren = (node.children?.length ?? 0) > 0;
  // 연결 그룹은 접힘 상태(검색 중엔 강제 펼침)이면 자식을 렌더하지 않는다.
  const childrenVisible = hasChildren && (!isConnGroup || forceExpand || !connCollapsed);
  // dirty 배지 — 이 라우트의 layoutName 에 미저장 편집분이 캐시돼 있으면 ● 표시.
  const isDirty = !isGroup && !!node.layoutName && dirtyLayoutNames.has(node.layoutName);
  // 레이아웃 버전 배지 — 이 노드의 레이아웃이 저장 이력을 가지면 현재(최신)
  // 버전 번호를 표시한다. 저장/버전 복원 성공 시 컨텍스트 layoutVersions 가 동기화돼
  // 배지도 즉시 갱신된다. 이력이 없는(원본) 레이아웃은 맵에 없어 미표시.
  // 확장 노드는 레이아웃이 아닌 확장 자체 버전 이력(extensionVersions — 확장 ID 키)을
  // 표시한다.
  const layoutVersion = isGroup
    ? undefined
    : node.kind === 'extension'
      ? (node.extensionId ? state.extensionVersions[node.extensionId] : undefined)
      : node.layoutName
        ? state.layoutVersions[node.layoutName]
        : undefined;

  const onClick = (): void => {
    if (isConnGroup) {
      // 연결 그룹 헤더 — 선택이 아니라 접기/펼치기 토글.
      setConnCollapsed((v) => !v);
      return;
    }
    if (isGroup) return; // 일반 그룹 헤더는 선택 불가
    onSelect(node);
  };

  // node.label 은 외부(useRouteTree)에서 들어오며 `$t:` prefix 또는 `$t:key|param=value` 파이프
  // 표현식일 수 있다. 라벨 해석 로직은 검색 필터와 공유하기 위해 resolveNodeLabel 로 추출됨.
  const labelText = resolveNodeLabel(node, {
    t,
    translationEngine,
    translationContext,
    templateIdentifier: state.templateIdentifier,
  });

  // 레이아웃 파일 경로/파일명 — 라벨 하단에 회색 작은 글씨로 노출.
  // 그룹 헤더는 layoutName 이 없으므로 자동으로 null → 미표시.
  const layoutPathLabel = isGroup ? null : buildLayoutPathLabel(node);

  const style: React.CSSProperties = {
    paddingLeft: 12 + depth * 14,
    paddingRight: 12,
    // 항목 세로 간격 — 종전 6px 의 2/3 로 축소
    paddingTop: 4,
    paddingBottom: 4,
    opacity: node.isHidden ? 0.55 : 1,
    cursor: isConnGroup ? 'pointer' : isGroup ? 'default' : 'pointer',
    background: isSelected ? '#eff6ff' : 'transparent',
    borderLeft: isSelected ? '2px solid #2563eb' : '2px solid transparent',
    fontWeight: isGroup ? 600 : isSelected ? 600 : 400,
    fontSize: isGroup ? 11 : 13,
    color: isGroup ? '#64748b' : isSelected ? '#1d4ed8' : '#1e293b',
    textTransform: isGroup ? 'uppercase' : 'none',
    letterSpacing: isGroup ? 0.6 : undefined,
    transition: 'background-color 100ms, color 100ms',
    display: 'flex',
    // 경로 줄을 라벨 아래에 쌓기 위해 column. 라벨/배지 row 는 내부 wrapper 가 담당.
    flexDirection: 'column',
    alignItems: 'stretch',
    gap: 2,
    userSelect: 'none',
  };

  return (
    <li>
      <div
        data-testid={isGroup ? 'g7le-route-tree-group' : 'g7le-route-tree-item'}
        data-route-path={node.path}
        data-route-kind={node.kind}
        data-conn-collapsed={isConnGroup ? (forceExpand ? 'false' : String(connCollapsed)) : undefined}
        role={isConnGroup || !isGroup ? 'button' : undefined}
        tabIndex={isConnGroup || !isGroup ? 0 : undefined}
        aria-expanded={isConnGroup ? (forceExpand ? true : !connCollapsed) : undefined}
        onClick={onClick}
        onKeyDown={(e): void => {
          if (isConnGroup && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            setConnCollapsed((v) => !v);
          } else if (!isGroup && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            onSelect(node);
          }
        }}
        style={style}
      >
        <span style={{ display: 'flex', alignItems: 'center', gap: 6, minWidth: 0 }}>
          {isConnGroup && (
            <span
              aria-hidden="true"
              style={{ flex: '0 0 auto', fontSize: 9, color: '#94a3b8', width: 10, display: 'inline-block' }}
            >
              {forceExpand || !connCollapsed ? '▾' : '▸'}
            </span>
          )}
          {isDirty && (
            <span
              className="g7le-route-tree__dirty-dot"
              data-testid="g7le-route-tree-dirty"
              title={t('layout_editor.chrome.route_tree.badge.modified')}
              aria-label={t('layout_editor.chrome.route_tree.badge.modified')}
              style={{ width: 7, height: 7, borderRadius: '50%', background: '#f59e0b', flex: '0 0 auto' }}
            />
          )}
          {/* 그룹 헤더는 검색 강조 대상 아님(항상 표시) — 라우트 라벨만 강조 */}
          {isGroup ? labelText : highlightMatch(labelText, query)}
          {typeof layoutVersion === 'number' && (
            <span
              className="g7le-route-tree__version-badge"
              data-testid="g7le-route-tree-version"
              title={t('layout_editor.chrome.route_tree.badge.version_tooltip', {
                version: String(layoutVersion),
              })}
              style={{
                flex: '0 0 auto',
                padding: '0 5px',
                fontSize: 10,
                fontWeight: 600,
                lineHeight: '16px',
                borderRadius: 4,
                background: '#eef2ff',
                color: '#4f46e5',
                border: '1px solid #e0e7ff',
                letterSpacing: 0.2,
              }}
            >
              v{layoutVersion}
            </span>
          )}
          <NodeBadges node={node} />
        </span>
        {layoutPathLabel && (
          <span
            className="g7le-route-tree__layout-path"
            data-testid="g7le-route-tree-layout-path"
            title={layoutPathLabel}
            style={{
              fontSize: 11,
              fontWeight: 400,
              color: '#94a3b8',
              textTransform: 'none',
              letterSpacing: 0,
              overflow: 'hidden',
              textOverflow: 'ellipsis',
              whiteSpace: 'nowrap',
            }}
          >
            {highlightMatch(layoutPathLabel, query)}
          </span>
        )}
      </div>
      {childrenVisible && (
        <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
          {node.children!.map((child) => (
            <RouteTreeItem
              key={child.path}
              node={child}
              depth={depth + 1}
              onSelect={onSelect}
              selectedRoutePath={selectedRoutePath}
              activeHostRoutePath={activeHostRoutePath}
              dirtyLayoutNames={dirtyLayoutNames}
              query={query}
              forceExpand={forceExpand}
            />
          ))}
        </ul>
      )}
    </li>
  );
}

/**
 * 선택된 가상 path(모달/확장)에 해당하는 연결 자식 노드를 트리에서 찾아 그 호스트 라우트
 * path 를 반환한다.
 *
 * selectedRoute.path 가 `__modal__/` 또는 `__extension__/` 가상 path 일 때, 그 노드를
 * 연결 그룹 자식에서 찾아 `connectedHostRoutePath` 를 얻는다. 못 찾으면 null(일반 라우트
 * 선택이거나 연결 그룹 외부 노드).
 *
 * @param nodes 라우트 트리 노드 배열
 * @param selectedPath 선택된 path
 * @returns 호스트 라우트 path 또는 null
 */
function findHostRoutePathFor(nodes: RouteTreeNode[], selectedPath: string | null): string | null {
  if (!selectedPath || !selectedPath.startsWith('__')) return null;

  // 매칭 기준: 모달/확장 노드의 식별자. selectedRoute.path 는 reducer 가 만들며 모달은
  // `__modal__/{modalId}` (hostLayout 미포함), 확장은 `__extension__/{extensionId}` 형태다.
  // 트리의 연결 자식 노드 path 는 `__modal__/{hostLayout}/{modalId}` 라 path 직접 비교가
  // 어긋나므로, 식별자(modalId/extensionId)로 매칭한다.
  const isModal = selectedPath.startsWith('__modal__/');
  const isExtension = selectedPath.startsWith('__extension__/');
  if (!isModal && !isExtension) return null;
  const targetId = selectedPath.slice(selectedPath.lastIndexOf('/') + 1);

  const walk = (list: RouteTreeNode[]): string | null => {
    for (const node of list) {
      if (node.connectedHostRoutePath) {
        if (isModal && node.kind === 'modal' && node.modalId === targetId) {
          return node.connectedHostRoutePath;
        }
        if (isExtension && node.kind === 'extension' && node.extensionId === targetId) {
          return node.connectedHostRoutePath;
        }
      }
      if (node.children && node.children.length > 0) {
        const found = walk(node.children);
        if (found) return found;
      }
    }
    return null;
  };
  return walk(nodes);
}

/**
 * 확장 노드 클릭 시 진입 호스트 레이아웃을 해석한다.
 *
 * - 라우트 하위(연결 호스트) 진입: `connectedHostRoutePath` 의 라우트 노드 layoutName.
 * - overlay 확장: `extensionTargetName`(= target_layout = 호스트 layoutName).
 * - 그 외(extension_point 를 출처 그룹에서 진입 — 호스트 모름): undefined → picker.
 *
 * @param node 클릭된 확장 트리 노드
 * @param tree 전체 라우트 트리(연결 호스트 라우트의 layoutName 조회용)
 * @returns 호스트 layoutName 또는 undefined
 */
function resolveExtensionHost(
  node: RouteTreeNode,
  tree: RouteTreeNode[],
): string | undefined {
  // 1) 라우트 하위 진입 — 연결 호스트 라우트의 layoutName.
  if (node.connectedHostRoutePath) {
    const hostRoute = findRouteNodeByPathInTree(tree, node.connectedHostRoutePath);
    if (hostRoute?.layoutName) return hostRoute.layoutName;
  }
  // 2) overlay — target_layout 이 곧 호스트 layoutName.
  if (node.extensionType === 'overlay' && node.extensionTargetName) {
    return node.extensionTargetName;
  }
  // 3) extension_point 출처 그룹 진입 — 호스트 모름 → picker.
  return undefined;
}

/** path 로 라우트 노드를 트리에서 찾는다(layoutName 조회용). */
function findRouteNodeByPathInTree(
  nodes: RouteTreeNode[],
  path: string,
): RouteTreeNode | null {
  for (const node of nodes) {
    if (node.kind === 'route' && node.path === path) return node;
    if (node.children && node.children.length > 0) {
      const found = findRouteNodeByPathInTree(node.children, path);
      if (found) return found;
    }
  }
  return null;
}

/**
 * 검색어로 라우트 트리를 필터링한다.
 *
 * 라우트 노드는 (1) 해석된 표시 라벨 또는 (2) path 문자열이 검색어를 포함하면 유지된다.
 * 그룹 노드는 자손 중 매칭이 하나라도 있으면 유지하되, 자식은 재귀적으로 필터링된 것만
 * 남긴다. 매칭이 없으면 빈 배열을 반환한다. 검색어가 비면 호출자가 원본을 그대로 쓴다.
 *
 * @param nodes 라우트 트리 노드 배열
 * @param query 정규화된 검색어 (소문자, 비어있지 않음)
 * @param ctx 라벨 해석 컨텍스트
 * @returns 필터링된 노드 배열 (얕은 복사 — children 만 교체)
 * @since engine-v1.50.0
 */
function filterTree(
  nodes: RouteTreeNode[],
  query: string,
  ctx: LabelResolveCtx,
): RouteTreeNode[] {
  const out: RouteTreeNode[] = [];
  for (const node of nodes) {
    const isGroup = isGroupNode(node);
    const filteredChildren =
      node.children && node.children.length > 0
        ? filterTree(node.children, query, ctx)
        : [];

    if (isGroup) {
      // 그룹: 필터링된 자식이 남으면 유지(그룹 라벨 자체는 매칭 대상에서 제외)
      if (filteredChildren.length > 0) {
        out.push({ ...node, children: filteredChildren });
      }
      continue;
    }

    // 라우트/모달/base/확장 노드: 라벨 또는 path 매칭
    const label = resolveNodeLabel(node, ctx).toLocaleLowerCase();
    const path = node.path.toLocaleLowerCase();
    const selfMatch = label.includes(query) || (!path.startsWith('__') && path.includes(query));

    if (selfMatch || filteredChildren.length > 0) {
      out.push({ ...node, children: filteredChildren });
    }
  }
  return out;
}

export function RouteTreePanel(): React.ReactElement {
  const { t, translationEngine, translationContext } = useTranslation();
  const { state, dispatch } = useLayoutEditor();
  const docCtx = useLayoutDocumentContext();
  const dirtyLayoutNames: ReadonlySet<string> = docCtx?.dirtyLayoutNames ?? EMPTY_DIRTY_SET;

  // 검색 상태 — 명칭(다국어 해석 라벨)/path 로 트리 필터 + 키워드 강조.
  const [searchRaw, setSearchRaw] = useState('');
  const query = normalizeQuery(searchRaw);

  // 필터링된 트리 — 검색어가 있을 때만 계산. 라벨 해석이 translationEngine/context 에
  // 의존하므로 그 변화도 deps 로 둔다. 화면별 연결 모달/확장은 useRouteTree 가 백엔드
  // host_layouts 기준으로 정적 부착하므로(클릭/캔버스 로드 불필요) 여기서 별도 병합은 없다.
  const visibleTree = useMemo(() => {
    if (!query) return state.routeTree;
    return filterTree(state.routeTree, query, {
      t,
      translationEngine,
      translationContext,
      templateIdentifier: state.templateIdentifier,
    });
  }, [query, state.routeTree, state.templateIdentifier, t, translationEngine, translationContext]);

  const handleSelect = (node: RouteTreeNode): void => {
    if (node.kind === 'base' && node.layoutName) {
      dispatch({ type: 'ENTER_BASE_EDIT', layoutName: node.layoutName });
      return;
    }
    if (node.kind === 'modal' && node.modalId && node.modalHostLayout) {
      dispatch({ type: 'ENTER_MODAL_EDIT', modalId: node.modalId, hostLayout: node.modalHostLayout });
      return;
    }
    if (node.kind === 'extension' && node.extensionId) {
      // 진입 시 호스트 확정 — 라우트 하위 진입(연결 호스트 라우트의 layoutName)
      // 또는 overlay(target_layout = extensionTargetName)면 호스트가 명확하므로 picker 를
      // 생략한다. extension_point 를 출처 그룹에서 진입(호스트 모름)하면 host 미전달 → picker.
      const extensionHost = resolveExtensionHost(node, state.routeTree);
      dispatch({ type: 'ENTER_EXTENSION_EDIT', extensionId: node.extensionId, extensionHost });
      return;
    }
    dispatch({ type: 'SELECT_ROUTE', route: { path: node.path, layoutName: node.layoutName } });
    // URL 동기화 — 사용자가 트리에서 라우트를 선택하면 브라우저 주소창이 즉시 반영되어
    // 새로고침/공유/뒤로가기 동작이 자연스럽게 가능. base/modal/extension 가상 path 는
    // buildEditorUrl 이 자동으로 templateIdentifier 만의 URL 로 폴백한다.
    if (typeof window !== 'undefined' && window.history?.pushState) {
      const nextUrl = buildEditorUrl(state.templateIdentifier, node.path);
      // 같은 URL 이면 history 항목 중복 방지 (반복 클릭 시)
      const current = `${window.location.pathname}${window.location.search}`;
      if (current !== nextUrl) {
        window.history.pushState({ source: EDITOR_HISTORY_STATE_MARKER, routePath: node.path }, '', nextUrl);
      }
    }
  };

  // 호스트 라우트 강조 path — 모달/확장 자식 클릭으로 별도 편집 모드에 진입하면
  // selectedRoute.path 가 가상 path 가 되므로, 그 항목이 속한 호스트 라우트를 계속 강조한다.
  const selectedPath = state.selectedRoute?.path ?? null;
  const activeHostRoutePath = useMemo(
    () => findHostRoutePathFor(state.routeTree, selectedPath),
    [state.routeTree, selectedPath],
  );

  if (state.isRouteTreeCollapsed) {
    return (
      <aside
        className="g7le-route-tree g7le-route-tree--collapsed"
        data-testid="g7le-route-tree-panel"
        data-collapsed="true"
        style={{ width: 28, borderRight: '1px solid #e2e8f0', background: '#ffffff' }}
      />
    );
  }

  const hasContent = state.routeTree.length > 0;

  return (
    <aside
      className="g7le-route-tree"
      data-testid="g7le-route-tree-panel"
      data-collapsed="false"
      style={{
        width: 280,
        borderRight: '1px solid #e2e8f0',
        background: '#ffffff',
        // 셸이 문서 흐름(브라우저 단일 스크롤)이므로 트리는 sticky 로 헤더 바로 아래에
        // 고정해 캔버스가 스크롤돼도 함께 밀리지 않게 한다.
        // top 은 sticky 헤더 높이(--g7le-header-h, 셸이 측정해 주입)만큼.
        // height 를 뷰포트 가시영역(100vh - 헤더)으로 **고정**하고(maxHeight 아님 — grid
        // 셀 stretch 와 sticky 가 충돌해 내부 ul 이 문서로 삐져나오는 것을 방지), 항목이
        // 많으면 트리 내부에서 자체 overflowY 스크롤한다. align-self:start 로 셀 stretch
        // 영향을 받지 않는다.
        position: 'sticky',
        top: 'var(--g7le-header-h, 0px)',
        alignSelf: 'start',
        height: 'calc(100vh - var(--g7le-header-h, 0px))',
        // aside 는 자식을 클리핑만 하고(overflow hidden), 실제 세로 스크롤은 아래 ul 영역이
        // 담당한다(flex column 스크롤 표준 패턴: 스크롤 자식에 flex:1 + min-height:0 +
        // overflow-y:auto). aside 에 직접 overflow:auto 를 주면 flex column 에서 ul 의
        // min-height:auto 가 콘텐츠 높이를 강제해 클리핑되지 않고 문서로 삐져나온다.
        overflow: 'hidden',
        minHeight: 0,
        display: 'flex',
        flexDirection: 'column',
      }}
      aria-label={t('layout_editor.chrome.route_tree.panel_title')}
    >
      <div
        className="g7le-route-tree__title"
        style={{
          padding: '10px 14px',
          fontSize: 12,
          fontWeight: 600,
          color: '#475569',
          textTransform: 'uppercase',
          letterSpacing: 0.6,
          borderBottom: '1px solid #e2e8f0',
          background: '#f8fafc',
          flex: '0 0 auto',
        }}
      >
        {t('layout_editor.chrome.route_tree.panel_title')}
      </div>
      {hasContent && (
        <div
          className="g7le-route-tree__search"
          style={{
            padding: '8px 10px',
            borderBottom: '1px solid #e2e8f0',
            background: '#ffffff',
            flex: '0 0 auto',
            position: 'relative',
          }}
        >
          <input
            type="text"
            className="g7le-route-tree__search-input"
            data-testid="g7le-route-tree-search"
            value={searchRaw}
            onChange={(e): void => setSearchRaw(e.target.value)}
            placeholder={t('layout_editor.chrome.route_tree.search_placeholder')}
            aria-label={t('layout_editor.chrome.route_tree.search_placeholder')}
            style={{
              width: '100%',
              boxSizing: 'border-box',
              padding: searchRaw ? '6px 26px 6px 10px' : '6px 10px',
              fontSize: 12,
              lineHeight: 1.4,
              color: '#1e293b',
              border: '1px solid #cbd5e1',
              borderRadius: 6,
              outline: 'none',
              background: '#f8fafc',
            }}
          />
          {searchRaw && (
            <button
              type="button"
              className="g7le-route-tree__search-clear"
              data-testid="g7le-route-tree-search-clear"
              onClick={(): void => setSearchRaw('')}
              aria-label={t('layout_editor.chrome.route_tree.search_clear')}
              title={t('layout_editor.chrome.route_tree.search_clear')}
              style={{
                position: 'absolute',
                right: 16,
                top: '50%',
                transform: 'translateY(-50%)',
                width: 18,
                height: 18,
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                border: 'none',
                background: 'transparent',
                color: '#94a3b8',
                cursor: 'pointer',
                fontSize: 14,
                lineHeight: 1,
                padding: 0,
              }}
            >
              ✕
            </button>
          )}
        </div>
      )}
      {!hasContent && (
        <div
          className="g7le-route-tree__empty"
          data-testid="g7le-route-tree-empty"
          style={{ padding: 16, fontSize: 12, color: '#94a3b8', textAlign: 'center' }}
        >
          {t('layout_editor.chrome.route_tree.empty')}
        </div>
      )}
      {hasContent && query && visibleTree.length === 0 && (
        <div
          className="g7le-route-tree__no-results"
          data-testid="g7le-route-tree-no-results"
          style={{ padding: 16, fontSize: 12, color: '#94a3b8', textAlign: 'center' }}
        >
          {t('layout_editor.chrome.route_tree.search_no_results')}
        </div>
      )}
      {hasContent && visibleTree.length > 0 && (
        <nav
          className="g7le-route-tree__scroll"
          data-testid="g7le-route-tree-scroll"
          style={{ flex: '1 1 auto', minHeight: 0, overflowY: 'auto' }}
        >
          <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
            {visibleTree.map((node) => (
              <RouteTreeItem
                key={node.path}
                node={node}
                depth={0}
                onSelect={handleSelect}
                selectedRoutePath={selectedPath}
                activeHostRoutePath={activeHostRoutePath}
                dirtyLayoutNames={dirtyLayoutNames}
                query={query}
                forceExpand={Boolean(query)}
              />
            ))}
          </ul>
        </nav>
      )}
    </aside>
  );
}
