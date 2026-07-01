/**
 * useRouteTree.ts
 *
 * routes 응답 + 레이아웃 목록 → RouteTreeNode[] 변환 (pure logic).
 *
 * 입력: `getRoutesDataWithModules` 응답의 `data.routes` 배열 (
 *      source 태깅 적용된 라우트들).
 * 출력: 5그룹(공통 레이아웃 / 템플릿 / 모듈 / 플러그인 / 모달) RouteTreeNode 배열.
 *
 * 라벨 해석 우선순위: meta.editor_label > meta.title > path (8.4.3).
 * 그룹 경계 유지하며 중첩 (path prefix 또는 meta.parent).
 *
 * @since engine-v1.50.0
 */

import type { RouteTreeNode } from '../LayoutEditorContext';

export interface RouteResponseItem {
  path: string;
  layout?: string;
  meta?: {
    title?: string;
    editor_label?: string;
    icon?: string;
    hidden?: boolean;
    parent?: string;
  };
  redirect?: string;
  source: { kind: 'template' | 'module' | 'plugin' | 'core'; identifier: string | null };
}

export interface ModalDefinition {
  /** 모달 id */
  modalId: string;
  /** 호스트 레이아웃 이름 (모달이 정의된 레이아웃) */
  hostLayout: string;
  /** 모달 라벨 ($t: 키 또는 평문) */
  label?: string;
}

export interface BaseLayoutDefinition {
  /** base 레이아웃 이름 (예: _user_base) */
  layoutName: string;
  /** 표시 라벨 */
  label?: string;
}

/**
 * 레이아웃 확장 항목 — `[확장 주입]` 그룹의 개별 확장.
 * `GET /api/admin/templates/{id}/layout-extensions` 응답 `extensions[]` 의 한 항목.
 */
export interface ExtensionDefinition {
  /** 확장 PK (정수 — RouteTreeNode.extensionId 는 문자열로 보관) */
  id: number;
  /** 확장 타입 — 확장점/오버레이 */
  extensionType: 'extension_point' | 'overlay';
  /** 주입 대상 이름 (extension_point 이름 또는 overlay target_layout) */
  targetName: string;
  /** 우선순위 */
  priority?: number;
  /** 활성 여부 — false 면 트리에서 흐림 */
  isActive: boolean;
  /** 수정됨 여부 — true 면 "수정됨" 배지 */
  isModified: boolean;
  /**
   * 호스트 레이아웃 목록 — 이 확장이 주입되는 레이아웃명들. overlay 는
   * `[target_layout]`, extension_point 는 그 확장점을 포함하는 레이아웃 전체. 라우트의
   * `layoutName` 이 이 목록에 포함되면 그 라우트의 "주입되는 확장" 자식으로 정적 부착한다.
   */
  hostLayouts: string[];
  /**
   * 현재(최신) 저장 버전 번호 — 확장 노드 버전 배지.
   * 이력 없는(원본) 확장은 undefined. 트리 노드 자체에는 싣지 않고 컨텍스트
   * extensionVersions 맵으로 일괄 관리한다(저장/복원 동기화 일원화).
   */
  currentVersion?: number;
}

/**
 * 출처별 확장 그룹 — `[확장 주입]` 아래 `🧩 {모듈}` / `🔌 {플러그인}`
 * 하위 그룹. `GET .../layout-extensions` 응답의 출처별 그룹핑 한 묶음.
 */
export interface ExtensionGroupDefinition {
  /** 출처 식별자 (예: sirsoft-board) */
  sourceIdentifier: string;
  /** 출처 종류 — 모듈/플러그인/템플릿 */
  sourceType: 'module' | 'plugin' | 'template';
  /** 출처 표시명 */
  sourceLabel: string;
  /** 이 출처의 확장 목록 */
  extensions: ExtensionDefinition[];
}

/**
 * 라우트 ↔ 모달/확장 연결 그룹 가상 path prefix.
 *
 * 라우트 노드 children 에 `이 화면의 모달 (N)` / `주입되는 확장 (N)` 헤더 노드를 부착할 때
 * 사용한다. RouteTreePanel 은 이 prefix 를 그룹 헤더로 인식해 선택 불가 + 헤더 스타일을 적용한다.
 */
export const CONNECTED_MODALS_PATH_PREFIX = '__conngroup__/modals/';
export const CONNECTED_EXTENSIONS_PATH_PREFIX = '__conngroup__/extensions/';

export interface BuildRouteTreeInput {
  routes: RouteResponseItem[];
  modals: ModalDefinition[];
  baseLayouts: BaseLayoutDefinition[];
  /**
   * 출처별 확장 그룹 — `[확장 주입]` 그룹. 미전달 시 그룹 미표시
   * (확장 응답 로드 전/실패 시 디그레이드).
   */
  extensionGroups?: ExtensionGroupDefinition[];
  /** 모듈 식별자 → 표시명 (G7Config.activeModules) */
  moduleDisplayNames: Record<string, string>;
  /** 플러그인 식별자 → 표시명 */
  pluginDisplayNames: Record<string, string>;
}

interface FlatNodeCarrier {
  node: RouteTreeNode;
  parentKey: string | null;
  groupKey: string;
}

/**
 * 라벨 해석 — 8.4.3 우선순위.
 */
function resolveLabel(item: RouteResponseItem): { label: string; labelSource: RouteTreeNode['labelSource'] } {
  if (item.meta?.editor_label) {
    return { label: item.meta.editor_label, labelSource: 'editor_label' };
  }
  if (item.meta?.title) {
    return { label: item.meta.title, labelSource: 'title' };
  }
  return { label: item.path, labelSource: 'path' };
}

/**
 * 그룹 키 결정 — source 메타 기준.
 *
 * `core` source 는 라우트 트리에서 제외 (시스템 라우트 — preview 등).
 *
 * @returns 그룹 키 또는 null (제외 대상)
 */
function resolveGroupKey(item: RouteResponseItem): string | null {
  switch (item.source.kind) {
    case 'template':
      return 'template';
    case 'module':
      return `module:${item.source.identifier ?? ''}`;
    case 'plugin':
      return `plugin:${item.source.identifier ?? ''}`;
    case 'core':
      return null;
    default:
      return null;
  }
}

/**
 * 부모-자식 중첩 — path prefix 또는 meta.parent.
 */
function findParentPath(item: RouteResponseItem, allPaths: Set<string>): string | null {
  if (item.meta?.parent && allPaths.has(item.meta.parent)) {
    return item.meta.parent;
  }
  // path prefix 검사: /board/notice 의 부모는 /board
  const segments = item.path.split('/').filter(Boolean);
  for (let i = segments.length - 1; i >= 1; i--) {
    const candidate = '/' + segments.slice(0, i).join('/');
    if (candidate !== item.path && allPaths.has(candidate)) {
      return candidate;
    }
  }
  return null;
}

/**
 * 동일 레이아웃을 N개 라우트가 공유하는 경우 노드에 메타 추가.
 *
 * 8.4.4: "한 레이아웃을 여러 라우트가 공유하면 노드에 'N개 라우트 공용' 배지."
 */
function attachSharedLayoutCounts(routes: RouteResponseItem[]): Map<string, number> {
  const counts = new Map<string, number>();
  for (const r of routes) {
    if (r.layout) {
      counts.set(r.layout, (counts.get(r.layout) ?? 0) + 1);
    }
  }
  return counts;
}

/**
 * 모달을 호스트 레이아웃명으로 역인덱싱한다 — `layoutName` → 그 레이아웃에 정의된 모달들.
 *
 * 라우트 노드(`layoutName`)에 "이 화면의 모달" 자식을 부착하기 위한 조회 맵.
 *
 * @param modals 인라인 모달 정의 배열
 * @returns 호스트 레이아웃명 → 모달 배열
 */
function indexModalsByHostLayout(modals: ModalDefinition[]): Map<string, ModalDefinition[]> {
  const map = new Map<string, ModalDefinition[]>();
  for (const m of modals) {
    if (!m.hostLayout) continue;
    const list = map.get(m.hostLayout) ?? [];
    list.push(m);
    map.set(m.hostLayout, list);
  }
  return map;
}

/**
 * 모든 확장(overlay + extension_point)을 호스트 레이아웃명으로 역인덱싱한다 —
 * `hostLayouts` 의 각 레이아웃명 → 그 레이아웃에 주입되는 확장들. 백엔드가 host_layouts 를
 * 제공하므로 캔버스 로드(클릭) 없이도 라우트의 layoutName 매칭으로 정적 부착이 가능하다
 * (overlay 는 target_layout, extension_point 는 그 확장점을 포함하는 레이아웃 전체).
 *
 * 인덱스 값에는 출처 라벨/아이콘을 함께 보관해, 라우트 자식 노드 라벨을 `[확장 주입]` 그룹과
 * 동일 포맷(`{출처} · {대상명}`)으로 구성한다.
 *
 * @param extensionGroups 출처별 확장 그룹
 * @returns 호스트 레이아웃명 → 확장(+출처 메타) 배열
 */
interface IndexedExtension {
  extension: ExtensionDefinition;
  group: ExtensionGroupDefinition;
}
function indexExtensionsByHostLayout(
  extensionGroups: ExtensionGroupDefinition[],
): Map<string, IndexedExtension[]> {
  const map = new Map<string, IndexedExtension[]>();
  for (const group of extensionGroups) {
    for (const ext of group.extensions) {
      for (const host of ext.hostLayouts ?? []) {
        if (!host) continue;
        const list = map.get(host) ?? [];
        list.push({ extension: ext, group });
        map.set(host, list);
      }
    }
  }
  return map;
}

/**
 * 모달 정의를 라우트 자식용 모달 노드로 변환한다.
 *
 * `kind: 'modal'` 노드라 RouteTreePanel.handleSelect 가 기존 ENTER_MODAL_EDIT 로 진입한다
 * (연결 자식 클릭 → 모달 편집 모드, 추가 진입 로직 불필요).
 *
 * @param modal 모달 정의
 * @returns 모달 RouteTreeNode
 */
function buildConnectedModalNode(modal: ModalDefinition, hostRoutePath: string): RouteTreeNode {
  return {
    path: `__modal__/${modal.hostLayout}/${modal.modalId}`,
    layoutName: modal.hostLayout,
    label: modal.label ?? modal.modalId,
    labelSource: modal.label ? 'editor_label' : 'path',
    source: { kind: 'core', identifier: null },
    kind: 'modal',
    modalId: modal.modalId,
    modalHostLayout: modal.hostLayout,
    connectedHostRoutePath: hostRoutePath,
    children: [],
  };
}

/**
 * 확장(+출처)을 라우트 자식용 확장 노드로 변환한다 (overlay / extension_point 공통).
 *
 * `kind: 'extension'` 노드라 handleSelect 가 ENTER_EXTENSION_EDIT 로 진입한다. 라벨/배지/
 * 흐림 처리는 `[확장 주입]` 그룹의 항목과 동일 규약을 따른다.
 *
 * @param indexed 확장 + 출처 그룹
 * @param hostRoutePath 이 확장이 속한 호스트 라우트 path (강조 유지용)
 * @returns 확장 RouteTreeNode
 */
function buildConnectedExtensionNode(indexed: IndexedExtension, hostRoutePath: string): RouteTreeNode {
  const { extension: ext, group } = indexed;
  const icon = group.sourceType === 'plugin' ? '🔌' : '🧩';
  return {
    path: `__extension__/${ext.id}`,
    layoutName: null,
    label: `${icon} ${group.sourceLabel} · ${ext.targetName}`,
    labelSource: 'path',
    source: { kind: group.sourceType, identifier: group.sourceIdentifier },
    kind: 'extension',
    extensionId: String(ext.id),
    extensionType: ext.extensionType,
    extensionPriority: ext.priority,
    extensionTargetName: ext.targetName,
    isModified: ext.isModified,
    isInactive: !ext.isActive,
    connectedHostRoutePath: hostRoutePath,
    children: [],
  };
}

/**
 * 라우트 노드에 "이 화면의 모달" / "주입되는 확장" 연결 그룹 자식을 부착한다.
 *
 * 정적 매칭만 수행 — 모달은 `host_layout`, overlay 확장은 `target_layout` 이 라우트의
 * `layoutName` 과 일치하는 경우. extension_point 확장은 슬롯 이름 기반이라 캔버스 로드 후
 * 동적 매칭(별도)으로 보강된다. 연결 항목이 0건인 그룹은 부착하지 않는다(노이즈 방지).
 *
 * 부착 위치: 라우트 노드 children **앞쪽**(중첩 하위 라우트보다 위) — 화면을 펼치면 그 화면의
 * 모달/확장이 먼저 보이고 하위 라우트가 뒤따른다.
 *
 * @param node 대상 라우트 노드 (in-place 변형)
 * @param modalsByHost 호스트 레이아웃명 → 모달 인덱스
 * @param extensionsByHost 호스트 레이아웃명 → 확장 인덱스
 */
function attachConnectedGroups(
  node: RouteTreeNode,
  modalsByHost: Map<string, ModalDefinition[]>,
  extensionsByHost: Map<string, IndexedExtension[]>,
): void {
  // route(라우트) + base(공통 레이아웃) 호스트 모두 "이 화면의 모달"/"주입되는 확장"
  // 연결 그룹을 부착한다 (`_user_base` 같은 base 호스트의 모달이 호스트 하위에
  // 노출돼야 한다). 두 종류 모두 layoutName 으로 호스트 매칭한다.
  if ((node.kind !== 'route' && node.kind !== 'base') || !node.layoutName) return;

  const connectedGroups: RouteTreeNode[] = [];

  const modals = modalsByHost.get(node.layoutName) ?? [];
  if (modals.length > 0) {
    connectedGroups.push({
      path: `${CONNECTED_MODALS_PATH_PREFIX}${node.path}`,
      layoutName: null,
      label: `$t:layout_editor.chrome.route_tree.connected.modals|count=${modals.length}`,
      labelSource: 'editor_label',
      source: { kind: 'core', identifier: null },
      kind: 'route',
      children: modals.map((m) => buildConnectedModalNode(m, node.path)),
    });
  }

  const extensions = extensionsByHost.get(node.layoutName) ?? [];
  if (extensions.length > 0) {
    connectedGroups.push({
      path: `${CONNECTED_EXTENSIONS_PATH_PREFIX}${node.path}`,
      layoutName: null,
      label: `$t:layout_editor.chrome.route_tree.connected.extensions|count=${extensions.length}`,
      labelSource: 'editor_label',
      source: { kind: 'core', identifier: null },
      kind: 'route',
      children: extensions.map((e) => buildConnectedExtensionNode(e, node.path)),
    });
  }

  if (connectedGroups.length > 0) {
    node.children = [...connectedGroups, ...(node.children ?? [])];
  }
}

/**
 * 라우트 응답을 RouteTreeNode 평면 배열로 변환.
 */
function flattenRoutes(input: BuildRouteTreeInput): FlatNodeCarrier[] {
  const carriers: FlatNodeCarrier[] = [];
  const allPaths = new Set(input.routes.map((r) => r.path));

  for (const r of input.routes) {
    const groupKey = resolveGroupKey(r);
    if (!groupKey) continue;

    const { label, labelSource } = resolveLabel(r);
    const parent = findParentPath(r, allPaths);

    const node: RouteTreeNode = {
      path: r.path,
      layoutName: r.layout ?? null,
      label,
      labelSource,
      icon: r.meta?.icon ?? null,
      source: r.source,
      isRedirect: Boolean(r.redirect),
      isHidden: Boolean(r.meta?.hidden),
      kind: 'route',
      children: [],
    };

    carriers.push({ node, parentKey: parent, groupKey });
  }

  return carriers;
}

/**
 * 그룹 라벨 생성 — 모듈/플러그인은 표시명 주입.
 */
function buildGroupLabel(groupKey: string, input: BuildRouteTreeInput): string {
  // 라벨은 `$t:` prefix 형태로 둔다 — `RouteTreePanel` 가 prefix 를 인식하여
  // `useTranslation().t(key, params)` 로 해석한다 (파이프 파라미터 자동 분리).
  if (groupKey === 'template') return '$t:layout_editor.chrome.route_tree.group.template';
  if (groupKey.startsWith('module:')) {
    const id = groupKey.slice('module:'.length);
    const name = input.moduleDisplayNames[id] ?? id;
    return `$t:layout_editor.chrome.route_tree.group.module|name=${name}`;
  }
  if (groupKey.startsWith('plugin:')) {
    const id = groupKey.slice('plugin:'.length);
    const name = input.pluginDisplayNames[id] ?? id;
    return `$t:layout_editor.chrome.route_tree.group.plugin|name=${name}`;
  }
  return groupKey;
}

/**
 * 그룹 단위로 라우트를 묶고 중첩 트리 구성.
 *
 * 그룹 노드는 RouteTreeNode 의 `kind` 를 'route' 로 두지 않고, 별도 컨테이너
 * 형식으로 표현하기 위해 path = `__group__/{groupKey}` 형태로 가상 path 부여.
 */
function buildGroupedTree(input: BuildRouteTreeInput, carriers: FlatNodeCarrier[]): RouteTreeNode[] {
  const sharedCounts = attachSharedLayoutCounts(input.routes);

  // 라우트 ↔ 모달/overlay 확장 정적 연결 인덱스.
  // 각 라우트 노드에 layoutName 기준으로 매칭되는 모달/overlay 를 children 앞쪽에 부착한다.
  const modalsByHost = indexModalsByHostLayout(input.modals);
  const extensionsByHost = indexExtensionsByHostLayout(input.extensionGroups ?? []);

  // 그룹별 카리어 분류
  const carriersByGroup = new Map<string, FlatNodeCarrier[]>();
  for (const c of carriers) {
    if (!carriersByGroup.has(c.groupKey)) {
      carriersByGroup.set(c.groupKey, []);
    }
    carriersByGroup.get(c.groupKey)!.push(c);
    // shared layout 메타 부여 (annotation 으로 children/메타에 안 끼고 노드 외부에 보관 필요 →
    // 본 Phase 에서는 sharedCount 를 무시하고 RouteTreePanel 이 layoutName 으로 카운트 검색하도록 둠)
    void sharedCounts;
  }

  // 그룹 정렬 순서: base → template → module:* (식별자 순) → plugin:* → modal
  const sortedGroupKeys = Array.from(carriersByGroup.keys()).sort((a, b) => {
    const order = (k: string): number => {
      if (k === 'template') return 1;
      if (k.startsWith('module:')) return 2;
      if (k.startsWith('plugin:')) return 3;
      return 4;
    };
    const oa = order(a);
    const ob = order(b);
    if (oa !== ob) return oa - ob;
    return a.localeCompare(b);
  });

  const groupNodes: RouteTreeNode[] = [];

  // 공통 레이아웃 그룹 (base 가 있는 경우)
  if (input.baseLayouts.length > 0) {
    const baseChildren: RouteTreeNode[] = input.baseLayouts.map((b) => ({
      path: `__base__/${b.layoutName}`,
      layoutName: b.layoutName,
      label: b.label ?? b.layoutName,
      labelSource: b.label ? 'editor_label' : 'path',
      source: { kind: 'core', identifier: null },
      kind: 'base',
      children: [],
    }));
    // base 호스트(예: `_user_base`)에도 "이 화면의 모달"/"주입되는 확장" 연결 그룹 부착
    // 라우트와 동일하게 layoutName 매칭.
    for (const baseChild of baseChildren) {
      attachConnectedGroups(baseChild, modalsByHost, extensionsByHost);
    }
    groupNodes.push({
      path: `__group__/base`,
      layoutName: null,
      label: '$t:layout_editor.chrome.route_tree.group.base',
      labelSource: 'editor_label',
      source: { kind: 'core', identifier: null },
      kind: 'route',
      children: baseChildren,
    });
  }

  // 일반 라우트 그룹
  for (const groupKey of sortedGroupKeys) {
    const groupCarriers = carriersByGroup.get(groupKey)!;
    const byPath = new Map<string, FlatNodeCarrier>();
    for (const c of groupCarriers) byPath.set(c.node.path, c);

    // 중첩 트리 구성 — 그룹 경계 유지 (다른 그룹 부모로 묶지 않음)
    const roots: RouteTreeNode[] = [];
    for (const c of groupCarriers) {
      if (c.parentKey && byPath.has(c.parentKey)) {
        const parent = byPath.get(c.parentKey)!;
        parent.node.children = parent.node.children ?? [];
        parent.node.children.push(c.node);
      } else {
        roots.push(c.node);
      }
    }

    // 라우트 ↔ 모달/overlay 연결 그룹 부착. 중첩 push 가 끝난 뒤 부착해야
    // 연결 그룹이 children 앞쪽에 오고 하위 라우트가 뒤따른다.
    for (const c of groupCarriers) {
      attachConnectedGroups(c.node, modalsByHost, extensionsByHost);
    }

    groupNodes.push({
      path: `__group__/${groupKey}`,
      layoutName: null,
      label: buildGroupLabel(groupKey, input),
      labelSource: 'editor_label',
      source: { kind: 'core', identifier: null },
      kind: 'route',
      children: roots,
    });
  }

  // 모달 그룹
  if (input.modals.length > 0) {
    const modalChildren: RouteTreeNode[] = input.modals.map((m) => ({
      path: `__modal__/${m.hostLayout}/${m.modalId}`,
      layoutName: m.hostLayout,
      label: m.label ?? m.modalId,
      labelSource: m.label ? 'editor_label' : 'path',
      source: { kind: 'core', identifier: null },
      kind: 'modal',
      modalId: m.modalId,
      modalHostLayout: m.hostLayout,
      children: [],
    }));
    groupNodes.push({
      path: `__group__/modal`,
      layoutName: null,
      label: '$t:layout_editor.chrome.route_tree.group.modal',
      labelSource: 'editor_label',
      source: { kind: 'core', identifier: null },
      kind: 'route',
      children: modalChildren,
    });
  }

  // 확장 주입 그룹 — 출처별 하위그룹(`🧩 모듈` / `🔌 플러그인`),
  // 그 아래 각 확장 항목. 비활성 확장도 제거하지 않고 흐림으로 표시.
  const extensionGroups = input.extensionGroups ?? [];
  if (extensionGroups.length > 0) {
    const sourceSubgroups: RouteTreeNode[] = extensionGroups.map((group) => {
      const icon = group.sourceType === 'plugin' ? '🔌' : '🧩';
      const extensionChildren: RouteTreeNode[] = group.extensions.map((ext) => ({
        // 가상 path — selectedRoute.path 약속(__extension__/{id}). useEditorMode/매칭 일관.
        path: `__extension__/${ext.id}`,
        layoutName: null,
        // 라벨 = 출처 라벨 + 주입 대상 이름. 둘 다 평문이므로 path 출처.
        label: `${group.sourceLabel} · ${ext.targetName}`,
        labelSource: 'path',
        source: {
          kind: group.sourceType,
          identifier: group.sourceIdentifier,
        },
        kind: 'extension',
        extensionId: String(ext.id),
        extensionType: ext.extensionType,
        extensionPriority: ext.priority,
        extensionTargetName: ext.targetName,
        isModified: ext.isModified,
        isInactive: ! ext.isActive,
        children: [],
      }));

      return {
        path: `__extgroup__/${group.sourceType}/${group.sourceIdentifier}`,
        layoutName: null,
        label: `${icon} ${group.sourceLabel}`,
        labelSource: 'path' as const,
        source: { kind: group.sourceType, identifier: group.sourceIdentifier },
        kind: 'route' as const,
        children: extensionChildren,
      };
    });

    groupNodes.push({
      path: `__group__/extension`,
      layoutName: null,
      label: '$t:layout_editor.chrome.route_tree.group.extension',
      labelSource: 'editor_label',
      source: { kind: 'core', identifier: null },
      kind: 'route',
      children: sourceSubgroups,
    });
  }

  return groupNodes;
}

/**
 * 라우트 응답 + 모달/base/표시명 입력 → 라우트 트리 구성 (pure).
 *
 * 본 함수는 React-free. 테스트는 입력 axis cross product 로 검증.
 */
export function buildRouteTree(input: BuildRouteTreeInput): RouteTreeNode[] {
  const carriers = flattenRoutes(input);
  return buildGroupedTree(input, carriers);
}
