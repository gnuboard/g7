// e2e:allow 순수 트리 마스킹 유틸 — DOM/네트워크 영향 없음. Vitest 75/75 + PHPUnit 10/10 +  검증으로 회귀 잠금. 레이아웃 편집기 GA 시점에 spec 추가 예정
/**
 * layoutTreeUtils.ts — 편집기 잠금/데이터 결정 노드 판정 + 트리 조작 유틸
 *
 * - Phase 2: 잠금/데이터 결정 노드 판정 (`isBaseOriginatedNode`,
 *   `isDataBoundNode`, `isNodeLocked` 등)
 * - Phase 3: 트리 조작 함수 (`findNodeByPath`, `insertNode`,
 *   `removeNode`, `moveNode`, `duplicateNode`, `patchNode`, `validate`,
 *   `ensureNodeId`)
 *
 * 모든 함수는 순수 함수 — side effect 없음. 입력 노드 객체는 변경하지 않고,
 * 트리 변형 함수는 새 트리 사본을 반환한다.
 *
 * @since engine-v1.50.0 / engine-v1.50.0
 */

// 데이터 칩(custom param 키) 판정용 — 칩 키 분류 SSoT(6차 세션) 재사용. inlineBindingUtils 는
// layoutTreeUtils 를 import 하지 않으므로 순환 없음.
import { extractParamBindings } from '../spec/inlineBindingUtils';

/**
 * `__source` 메타 형식 
 */
export interface NodeSource {
  kind: 'base' | 'route' | 'partial' | 'extension';
  layout?: string;
  extensionId?: number;
  /** 확장 출처 타입(`module`/`plugin`/`template`) — 오버레이 라벨 폴백용 */
  extensionSourceType?: string;
  /** 확장 출처 식별자(예: `sirsoft-board`) — 오버레이 "확장 편집" 라벨에 표시 */
  extensionIdentifier?: string;
  /** 확장 로케일 표시명(예: `게시판`) — 식별자와 함께 표시 */
  extensionName?: string;
}

/**
 * 편집기에서 다루는 일반화된 노드 형식.
 * `text` / `props` / `iteration` / `__source` / `type` / `children` 만 사용.
 */
export interface EditorNode {
  type?: string;
  name?: string;
  text?: unknown;
  props?: Record<string, unknown>;
  iteration?: { source?: unknown } | null;
  children?: EditorNode[] | unknown;
  __source?: NodeSource;
  /**
   * inject_props 호스트 노드의 확장 주입 메타. 백엔드
   * `applyExtensions(with_source_meta)` 가 부여. 편집기 속성 모달의 "확장이 주입한 속성"
   * 섹션이 읽고, 저장 시 `__` 메타로 마스킹돼 영속되지 않는다(확장 행이 SSoT).
   */
  __injectedProps?: Array<{
    extensionId: number;
    extensionSourceType?: string;
    extensionIdentifier?: string;
    extensionName?: string;
    props: Record<string, unknown>;
  }>;
  /**
   * 표시조건 식 (node-level structural key) — `if` 는 props 가 아닌 노드 최상위에
   * 둔다(렌더러/디스패처가 읽는 위치). 편집기 스타일/표시조건 탭이 base scope 의
   * 표시조건을 여기에 쓰고 읽는다.
   */
  if?: unknown;
  /**
   * breakpoint 별 오버라이드.
   *
   * 키 = preset(`mobile`/`tablet`/`desktop`/`portable`) 또는 커스텀 범위 문자열
   * (`"600-900"`/`"-599"`/`"1200-"`). 각 브랜치는 base 노드의 `props`/`children`/
   * `text`/`if`/`iteration` 을 부분 오버라이드한다. `DynamicRenderer` 가 매칭
   * breakpoint 에서 `{...base.props, ...override.props}`(얕은 병합)로 합성한다.
   * 편집기 스타일/표시조건 탭이 `props`/`if` 만 직접 쓰고 읽으며, 나머지 키는
   * 코드 편집 위임(무손실 보존).
   */
  responsive?: Record<
    string,
    {
      props?: Record<string, unknown>;
      children?: unknown;
      text?: unknown;
      if?: unknown;
      iteration?: unknown;
    }
  >;
  [key: string]: unknown;
}

/**
 * 노드의 `__source` 메타를 안전하게 반환.
 */
export function getNodeSource(node: EditorNode): NodeSource | null {
  return node.__source ?? null;
}

/**
 * base 레이아웃에서 상속된 노드인지 판정.
 *
 * 라우트 편집 모드에서는 이 결과가 true 면 노드 잠금.
 */
export function isBaseOriginatedNode(node: EditorNode): boolean {
  return node.__source?.kind === 'base';
}

/**
 * partial 합성에서 온 노드인지 판정.
 *
 * 호스트 레이아웃 소속으로 분류되어 본 라우트 편집 모드에서는 잠금이지만,
 * base 편집 모드에서는 편집 가능 — 본 함수는 그 분기 판정의 보조 역할.
 */
export function isPartialOriginatedNode(node: EditorNode): boolean {
  return node.__source?.kind === 'partial';
}

/**
 * 본 라우트의 slot 콘텐츠로 온 노드인지 판정.
 */
export function isOwnRouteContentNode(node: EditorNode): boolean {
  return node.__source?.kind === 'route';
}

/**
 * 확장 주입 노드인지 판정.
 *
 * `currentExtensionId` 가 전달되면 현재 편집 중인 확장만 편집 가능으로 분류
 * 하고 그 외 확장 노드는 잠금. 미전달 시 모든 확장 노드 잠금 (기본 동작).
 */
export function isExtensionOriginatedNode(
  node: EditorNode,
  currentExtensionId?: number,
): boolean {
  if (node.__source?.kind !== 'extension') {
    return false;
  }
  if (currentExtensionId === undefined || currentExtensionId === null) {
    return true; // 확장 모드 외 — 모든 확장 노드 잠금
  }
  return node.__source.extensionId !== currentExtensionId;
}

/**
 * `extension_point` 노드 자체인지 판정.
 *
 * 확장 슬롯의 위치·존재는 호스트 레이아웃 개발자가 코드로 정한 것이므로
 * 편집 대상이 아니다 — 선택/드래그/속성 모달/요소 추가 차단.
 */
export function isExtensionPointNode(node: EditorNode): boolean {
  return node.type === 'extension_point';
}

/**
 * 노드가 데이터 결정(읽기 전용) 노드인지 판정.
 *
 * 다음 중 하나면 true:
 * - `text` / `props` 값에 `{{...}}` 바인딩 포함
 * - `iteration.source` 가 정의됨 (데이터소스 참조)
 * - 조상 중 iteration 노드 존재 (ancestors 로 전달)
 *
 * `ancestors` 는 루트→현재 노드 경로의 노드 배열 (현재 노드는 제외).
 */
/**
 * 노드가 **반복(iteration) 인스턴스 내부**인지 — 조상 중 iteration 정의 노드가 있는지.
 *
 * `isDataBoundNode` 의 세 조건(조상 iteration / 자신 iteration / 자신 바인딩) 중 **조상
 * iteration** 만 분리한 판정. 반복 펼침 인스턴스(`...iteration.N` 및 그 자손)는 한 항목
 * 템플릿이 N개로 펼쳐진 것이라, 개별 인스턴스를 캔버스에서 직접 선택/드래그하면 "어느
 * 인스턴스를 고쳐야 원본 템플릿에 반영되나"가 모호하다. 따라서 자신 바인딩
 * data_bound(상품 이미지 갤러리 등 — 선택/드래그 허용)와 달리, **조상 iteration 영역
 * 내부 노드는 드래그/핸들에서 제외**한다(이터레이션 가상 묶음 구현 전까지의
 * 경계 — 묶음 단위 편집은 그 별도 모드에서). 자신이 iteration 정의 노드(반복 원본)는
 * 여기 포함하지 않는다 — 그 노드 자체는 묶음 이동 대상이 될 수 있다.
 *
 * @param ancestors 루트→부모 순 조상 노드 배열
 */
export function isInsideIterationInstance(ancestors: EditorNode[] = []): boolean {
  for (const ancestor of ancestors) {
    if (ancestor.iteration && (ancestor.iteration as any).source !== undefined) {
      return true;
    }
  }
  return false;
}

export function isDataBoundNode(node: EditorNode, ancestors: EditorNode[] = []): boolean {
  // 조상 중 iteration 노드가 있으면 데이터 결정 영역
  for (const ancestor of ancestors) {
    if (ancestor.iteration && (ancestor.iteration as any).source !== undefined) {
      return true;
    }
  }

  // 자기 자신이 iteration 정의 노드
  if (node.iteration && (node.iteration as any).source !== undefined) {
    return true;
  }

  return isSelfDataBoundNode(node);
}

/**
 * 노드 **자신**의 값(text/props)이 데이터 바인딩(`{{... }}`)인지 판정한다.
 *
 * `isDataBoundNode` 와 달리 **조상 iteration 을 보지 않는다**. 반복 항목 편집 모드에서는 편집
 * 대상 자체가 iteration 항목 템플릿 안이라 모든 내부 노드가 `isDataBoundNode` 기준 data_bound
 * 가 된다(조상 iteration 때문). 그러나 항목 내부에서도 **평문 노드는 편집 가능**해야 하고
 * **바인딩 노드만 "데이터 영역 편집 불가"** 로 표시되어야 한다(일반 편집기와 동일 가시).
 * 본 함수는 그 구분을 위해 노드 자신의 바인딩만 본다.
 *
 * @param node 대상 노드
 * @returns 노드 자신의 text/props 가 바인딩이면 true
 */
export function isSelfDataBoundNode(node: EditorNode): boolean {
  // text 값에 바인딩 — 단, 데이터 칩이 든 **custom param 키**(`$t:custom.*|pN={{}}`)는 제외한다
  // 그 노드는 평문+칩 혼합의 **편집 가능한 다국어 문구**이고, text 안의
  // `{{}}` 는 칩의 데이터 인자일 뿐 표시 텍스트의 본질은 사용자가 인라인/속성탭에서 편집하는 문구다
  // (7차 트랙이 만든 기능 — 표 셀·목록·옵션 라벨에 칩 추가 후에도 더블클릭 인라인 편집이 가능해야
  // 함). 순수 바인딩(`{{...}}` only)과 lang named-param(비-custom)은 종전대로 data_bound 유지.
  if (typeof node.text === 'string' && containsBinding(node.text) && !isCustomParamKeyText(node.text)) {
    return true;
  }

  // props 값 중 어느 하나라도 바인딩
  if (node.props && typeof node.props === 'object') {
    for (const value of Object.values(node.props)) {
      if (typeof value === 'string' && containsBinding(value)) {
        return true;
      }
    }
  }

  return false;
}

/**
 * 문자열에 `{{ ... }}` 바인딩 패턴이 포함되는지 검사 — 단순 substring 매칭이
 * 아닌 정규식으로 정확히 판정.
 */
function containsBinding(value: string): boolean {
  // `{{` 와 `}}` 사이에 임의 문자가 있는 패턴
  return /\{\{[\s\S]*?\}\}/.test(value);
}

/**
 * 텍스트가 데이터 칩이 든 **custom param 키**(`$t:custom.*|pN={{}}`)인지 판정한다.
 *
 * `classifyCustomText`/`extractParamBindings` 와 동일 SSoT(6차 세션) — param 정규화된 키의 키
 * 식별자가 `custom.` 으로 시작하는 경우만 true. lang named-param(`$t:user.*|count={{}}`, 비-custom)
 * 은 false(키화 전 — 종전대로 data_bound 유지). 잠금 판정에서 "편집 가능한 칩 문구"를 순수 데이터
 * 노드와 구분하는 데 쓴다.
 *
 * @param text 노드 text 값
 * @returns custom param 키면 true
 */
function isCustomParamKeyText(text: string): boolean {
  const paramized = extractParamBindings(text);
  return paramized !== null && paramized.key.startsWith('custom.');
}

/**
 * "공통 레이아웃 편집" 진입 시 로드할 base 레이아웃 식별자를 해석한다.
 *
 * 진입 대상은 **선택된 base 노드의 출처 파일**(`__source.layout`, 예 `_user_base`)이다.
 * 이는 진입 칩 라벨(`selectedBaseLayout`)이 가리키는 그 파일이며, 현재 보고 있는 라우트의
 * `layoutName`(예 `board/form`)과는 다르다. base 출처가 없을 때만(예외 — 메타 부재) 현재
 * 라우트 layoutName 으로 폴백한다.
 *
 * @param selectedBaseLayout 선택 노드의 base 출처 파일명(`__source.layout`) 또는 null
 * @param routeLayoutName 현재 라우트의 layoutName 또는 null/undefined (폴백)
 * @returns 진입할 base 레이아웃 식별자 또는 null(둘 다 없음)
 */
export function resolveBaseEditTarget(
  selectedBaseLayout: string | null | undefined,
  routeLayoutName: string | null | undefined,
): string | null {
  return selectedBaseLayout ?? routeLayoutName ?? null;
}

/**
 * 편집 모드별 잠금 판정 매트릭스 (표).
 *
 * @param node 대상 노드
 * @param editMode 현재 편집 모드
 * @param currentExtensionId 확장 편집 모드일 때 편집 중인 확장 PK
 * @returns 잠금이면 true, 편집 가능이면 false
 */
export function isNodeLocked(
  node: EditorNode,
  editMode: 'route' | 'base' | 'modal' | 'extension' | 'iteration_item',
  currentExtensionId?: number,
): boolean {
  // extension_point 노드 자체는 모든 편집 모드에서 잠금
  if (isExtensionPointNode(node)) {
    return true;
  }

  switch (editMode) {
    case 'route':
      // base · partial · extension = 잠금 / route = 편집
      return (
        isBaseOriginatedNode(node) ||
        isPartialOriginatedNode(node) ||
        isExtensionOriginatedNode(node)
      );

    case 'base':
      // base 편집 모드는 base 레이아웃(`_user_base` 등)을 **단독 로드**한다. 그 트리의 노드는
      // 머지 컨텍스트가 없어 백엔드가 기본값 `kind:'route'` 로 태깅한다(자식 슬롯에 머지될 때만
      // `kind:'base'`). 따라서 단독 로드된 base 노드 = 곧 base 본체(편집 대상)이므로 route 출처
      // 라고 잠그면 base 전체가 잠긴다. base 모드에서
      // 잠가야 할 것은 base 에 주입된 **확장**뿐이다(확장은 그 확장이 소유·편집).
      return isExtensionOriginatedNode(node);

    case 'modal':
      // extension = 잠금 / 모달 자체 children = 편집
      return isExtensionOriginatedNode(node);

    case 'extension':
      // base · route · partial · 다른 확장 = 잠금 / 편집 중 확장 = 편집
      if (isExtensionOriginatedNode(node, currentExtensionId)) {
        return true;
      }
      return (
        isBaseOriginatedNode(node) ||
        isOwnRouteContentNode(node) ||
        isPartialOriginatedNode(node)
      );

    default:
      return false;
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// 트리 조작 함수
//
// 모든 함수는 입력 트리를 변경하지 않고 새 트리 사본을 반환한다 (immutable).
// 노드는 `componentPath` (트리 인덱스 경로 `number[]`) 로 식별한다
// 의 `DynamicRenderer` `data-editor-path` 가 같은 경로 모델을 사용한다.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * 디바이스 분기(responsive) 세그먼트 — base children 이 아니라
 * `node.responsive[key].children` 배열로 내려가는 선택자다 (디바이스
 * 분기 자식 교체 노드의 편집 path 1급화).
 *
 * `key` = 노드가 선언한 breakpoint 키 원문(`portable`/`mobile`/`"600-900"` 등 —
 * `DynamicRenderer` 의 `responsiveManager.getMatchingKey` 가 반환하는 키와 문자 단위
 * 일치). 이 세그먼트는 노드를 한 단계 내리지 않고, "다음 children 배열을 base 가 아닌
 * 이 분기의 children 으로 본다"는 의미만 갖는다.
 */
export interface ResponsiveSegment {
  responsive: string;
}

/**
 * path 세그먼트 — 일반 자식 인덱스(number) 또는 디바이스 분기 선택자.
 */
export type PathSegment = number | ResponsiveSegment;

/**
 * 트리 인덱스 경로 — 루트의 children 인덱스부터 시작.
 *
 * 예: `[0, 2, 1]` = `root.children[0].children[2].children[1]`.
 *
 * 디바이스 분기 자식(자식 완전 교체형 `responsive.{key}.children`)은 base children
 * 과 **별개 배열**이므로, 그 분기 안 노드의 path 에는 base index 앞에 분기 세그먼트가
 * 1개 삽입된다:
 *   예 `[0, {responsive:'portable'}, 2]`
 *      = `root.children[0].responsive.portable.children[2]`.
 *
 * responsive 분기가 **없는** 기존 노드의 path 는 number 세그먼트만 사용 →
 * 하위 호환(동작 불변).
 */
export type ComponentPath = PathSegment[];

/**
 * 세그먼트가 디바이스 분기 선택자인지 판정.
 */
export function isResponsiveSegment(seg: PathSegment): seg is ResponsiveSegment {
  return typeof seg === 'object' && seg !== null && typeof (seg as ResponsiveSegment).responsive === 'string';
}

/**
 * 세그먼트 동등 비교 — number 는 `===`, responsive 는 `key` 문자열 비교.
 *
 * 세그먼트가 객체(ResponsiveSegment)가 되면서 원시 `!==` 는 **참조 비교**로 바뀐다.
 * 의미상 같은 `{responsive:'portable'}` 두 개도 "다름"으로 오판하면 moveNode 의 사이클
 * 차단·rebase·동일부모 판정이 전부 깨진다(G-1). 모든 세그먼트 비교는 본 헬퍼를
 * 거친다.
 */
export function segEqual(a: PathSegment, b: PathSegment): boolean {
  if (isResponsiveSegment(a)) {
    return isResponsiveSegment(b) && a.responsive === b.responsive;
  }
  return !isResponsiveSegment(b) && a === b;
}

/**
 * ComponentPath → DOM dot-path 문자열 직렬화. `parseEditorPath` 의 **역함수**.
 *
 * 규칙(DynamicRenderer 의 `data-editor-path` 발급과 동일):
 *  - 루트 첫 number 세그먼트 → `"N"`
 *  - 이후 number 세그먼트 → `"children.N"`
 *  - responsive 세그먼트 → `"responsive.{key}"` (다음 number 가 그 분기 children)
 *
 * 예:
 *  - `[0]`                              → `"0"`
 *  - `[0, 2]`                           → `"0.children.2"`
 *  - `[0, {responsive:'portable'}, 2]`  → `"0.responsive.portable.children.2"`
 *
 * 세그먼트가 객체가 되면서 기존 `path.map((s,i)=> i===0?String(s):\`children.${s}\`)`
 * 직렬화는 responsive 세그먼트를 `[object Object]` 로 깨뜨린다(G-3 DOM selector
 * 결함). path 를 문자열로 만드는 모든 곳은 본 헬퍼를 거친다.
 *
 * @since engine-v1.50.0
 */
export function serializeEditorPath(path: ComponentPath): string {
  const out: string[] = [];
  let sawIndex = false;
  for (const seg of path) {
    if (isResponsiveSegment(seg)) {
      out.push('responsive', seg.responsive);
      // 다음 number 세그먼트는 분기 children 의 인덱스 — 'children' prefix 가 필요.
      sawIndex = true;
      continue;
    }
    if (!sawIndex) {
      out.push(String(seg));
      sawIndex = true;
    } else {
      out.push('children', String(seg));
    }
  }
  return out.join('.');
}

/**
 * 노드 children 을 안전하게 배열로 반환. children 이 아예 없거나 배열이 아닌
 * 경우 빈 배열 반환 (변형 없음).
 */
function asChildrenArray(node: EditorNode): EditorNode[] {
  return Array.isArray(node.children) ? (node.children as EditorNode[]) : [];
}

/**
 * 디바이스 분기의 children 배열을 안전하게 반환. 분기/children 부재 시 빈 배열.
 */
function asResponsiveChildren(node: EditorNode, key: string): EditorNode[] {
  const branch = node.responsive?.[key];
  return branch && Array.isArray(branch.children) ? (branch.children as EditorNode[]) : [];
}

/**
 * 경로로 노드 조회. 경로가 유효하지 않으면 null.
 *
 * @param root 루트 노드 (또는 children 배열을 가진 가상 루트)
 * @param path 인덱스 경로
 */
export function findNodeByPath(root: EditorNode, path: ComponentPath): EditorNode | null {
  if (!root) return null;
  if (path.length === 0) return root;

  // 하강 상태: current = 현재 노드, childArray = "다음 number 세그먼트가 인덱싱할
  // children 배열". responsive 세그먼트는 노드를 내리지 않고 childArray 만 분기
  // children 으로 바꾼다.
  let current: EditorNode = root;
  let childArray: EditorNode[] = asChildrenArray(root);
  for (const seg of path) {
    if (isResponsiveSegment(seg)) {
      childArray = asResponsiveChildren(current, seg.responsive);
      continue;
    }
    if (seg < 0 || seg >= childArray.length) return null;
    current = childArray[seg]!;
    if (!current) return null;
    childArray = asChildrenArray(current);
  }
  return current;
}

/**
 * 확장 주입 조각의 **진입점**(부모가 extension 출처가 아닌 최상위 extension 노드)
 * path 로 정규화한다 (통짜 표시).
 *
 * 확장이 주입한 조각은 그 루트뿐 아니라 내부 자식 노드에도 모두 `__source.kind ===
 * 'extension'` 메타가 붙는다(`applyExtensions` 가 서브트리 전체에 부여). 캔버스에서
 * 확장 조각 내부 자식을 클릭하면 그 자식 path 가 선택되어 마치 개별 노드처럼 보이지만,
 * 확장 조각은 "통짜(블랙박스)" 단위로만 다뤄야 한다(내부 구조는 그 확장이 소유·버전
 * 관리). 본 함수는 클릭된 path 가 확장 조각 내부면 그 **진입점** path 로 올려, 선택·
 * 잠금·어포던스가 항상 조각 루트 1개에 대해 일관 적용되게 한다.
 *
 * 판정: 루트→path 경로를 따라가며 "부모는 extension 이 아니고 자신은 extension" 인
 * 첫 노드의 깊이까지 path 를 잘라 반환. 경로상 extension 진입점이 없으면 원본 path 를
 * 그대로 반환(일반 노드 — 변경 없음). 편집 중 확장(currentExtensionId)에 속한 노드는
 * 그 확장 편집 모드에서 자유 편집 대상이므로 정규화하지 않는다.
 *
 * @param root 루트 노드 (가상 루트 — `{ children: components }`)
 * @param path 클릭된 노드의 인덱스 경로
 * @param currentExtensionId 확장 편집 모드일 때 편집 중인 확장 PK (그 외 미전달)
 * @returns 진입점 path (확장 조각 내부면 잘린 path, 아니면 원본 path)
 */
export function normalizeToExtensionEntry(
  root: EditorNode | null,
  path: ComponentPath,
  currentExtensionId?: number,
): ComponentPath {
  if (!root || path.length === 0) return path;
  let current: EditorNode = root;
  let childArray: EditorNode[] = asChildrenArray(root);
  let parentKind: string | undefined;
  for (let depth = 0; depth < path.length; depth++) {
    const seg = path[depth]!;
    // responsive 세그먼트는 노드를 내리지 않고 childArray 만 분기로 전환 — 출처
    // 판정에는 영향 없음(다음 number 세그먼트가 실제 노드를 선택). 잘린 path 반환
    // 시에도 분기 세그먼트는 보존돼야 해당 분기 진입점을 정확히 가리킨다.
    if (isResponsiveSegment(seg)) {
      childArray = asResponsiveChildren(current, seg.responsive);
      continue;
    }
    const node = childArray[seg] ?? null;
    if (!node) return path; // 경로 불일치 — 원본 유지
    const kind = node.__source?.kind;
    const extId = node.__source?.extensionId;
    // 진입점 = 부모가 extension 이 아니고 자신이 extension. 편집 중 확장은 제외.
    const isOwnEditedExtension =
      kind === 'extension' && currentExtensionId != null && extId === currentExtensionId;
    if (kind === 'extension' && parentKind !== 'extension' && !isOwnEditedExtension) {
      return path.slice(0, depth + 1);
    }
    parentKind = kind;
    current = node;
    childArray = asChildrenArray(node);
  }
  return path;
}

/**
 * 부모 노드 조회. 빈 경로(루트)는 부모가 없으므로 null.
 *
 * 주의: responsive 분기 자식의 "부모"는 분기 세그먼트를 제거한 노드(분기를 소유한
 * 노드)다. 예 `[0, {responsive:'portable'}, 2]` 의 부모는 `[0]` 노드 —
 * 분기 children 은 그 노드의 `responsive.portable.children` 에 산다. 따라서 마지막
 * number 세그먼트와 (있다면) 직전 responsive 세그먼트를 함께 잘라낸다.
 */
export function findParentByPath(root: EditorNode, path: ComponentPath): EditorNode | null {
  if (path.length === 0) return null;
  // 마지막 number 세그먼트 제거. 그 직전이 responsive 세그먼트면 함께 제거 —
  // 분기 children 의 소유 노드(분기 세그먼트 이전)가 진짜 부모.
  let cut = path.length - 1;
  if (cut > 0 && isResponsiveSegment(path[cut - 1]!)) {
    cut -= 1;
  }
  return findNodeByPath(root, path.slice(0, cut));
}

/**
 * 자식 슬롯 디스크립터 — path 가 가리키는 노드가 속한 children 배열과, 그 배열을
 * 소유 노드에 되써넣는 setter 를 함께 반환한다. insert/remove/patch 가 base children
 * 과 responsive 분기 children 을 구분 없이 다루기 위한 공용 하강 헬퍼.
 *
 * 반환:
 *  - `owner`   : children 배열을 소유한 노드(분기 children 이면 분기를 가진 노드).
 *  - `children`: 그 배열(읽기 전용 사본 아님 — 호출자가 새 배열로 교체할 것).
 *  - `branchKey`: responsive 분기 children 이면 그 키, base children 이면 undefined.
 *  - `index`   : path 의 마지막 number 세그먼트(슬롯 인덱스).
 *
 * path 가 유효하지 않으면 null.
 */
interface ChildSlot {
  owner: EditorNode;
  children: EditorNode[];
  branchKey?: string;
  index: number;
}

function resolveChildSlot(root: EditorNode, path: ComponentPath): ChildSlot | null {
  if (path.length === 0) return null;
  const last = path[path.length - 1]!;
  if (isResponsiveSegment(last)) return null; // 분기 세그먼트로 끝나는 path 는 슬롯 아님
  const index = last;

  // 마지막 number 직전이 responsive 세그먼트면 분기 children, 아니면 base children.
  const prev = path.length >= 2 ? path[path.length - 2]! : undefined;
  if (prev !== undefined && isResponsiveSegment(prev)) {
    const owner = findNodeByPath(root, path.slice(0, -2));
    if (!owner) return null;
    return { owner, children: asResponsiveChildren(owner, prev.responsive), branchKey: prev.responsive, index };
  }
  const owner = findNodeByPath(root, path.slice(0, -1));
  if (!owner) return null;
  return { owner, children: asChildrenArray(owner), index };
}

/**
 * 슬롯의 children 배열을 새 배열로 되써넣는다 — base 면 `owner.children`,
 * responsive 분기면 `owner.responsive[key].children`. owner 는 deepClone 으로 만든
 * 사본이어야 한다(immutable 보장은 호출자 책임).
 */
function writeSlotChildren(slot: ChildSlot, next: EditorNode[]): void {
  if (slot.branchKey !== undefined) {
    const responsive = { ...(slot.owner.responsive ?? {}) } as Record<string, { children?: unknown }>;
    responsive[slot.branchKey] = { ...(responsive[slot.branchKey] ?? {}), children: next };
    slot.owner.responsive = responsive as EditorNode['responsive'];
  } else {
    slot.owner.children = next;
  }
}

/**
 * 트리 깊은 사본 — 노드 객체와 children 배열을 새 인스턴스로 만든다.
 * Primitive props/text 는 그대로 참조 보존(불변이므로 안전).
 *
 * 디바이스 분기(`responsive.{key}.children` — 자식 완전 교체형)도 base children 과
 * 동일하게 깊은 사본을 만든다. 분기 children 을
 * 빠뜨리면 `insertNode`/`removeNode`/`moveNode` 가 만든 "새 트리"가 원본과 분기 배열을
 * **참조 공유**하게 되어, history 스냅샷 사이에 분기 substructure 가 공유된다 →
 * 한 분기를 변형하면 그 변형이 다른 스냅샷(다른 분기 포함)에도 번져 undo 가 원복하지
 * 못한다.
 */
function deepCloneTree(node: EditorNode): EditorNode {
  const cloned: EditorNode = { ...node };
  if (cloned.props && typeof cloned.props === 'object') {
    cloned.props = { ...(cloned.props as Record<string, unknown>) };
  }
  const children = asChildrenArray(node);
  if (children.length > 0) {
    cloned.children = children.map(deepCloneTree);
  }
  // 디바이스 분기 children 깊은 사본 — 각 분기 객체와 그 children 배열을 새 인스턴스로.
  if (node.responsive && typeof node.responsive === 'object') {
    const clonedResponsive: NonNullable<EditorNode['responsive']> = {};
    for (const key of Object.keys(node.responsive)) {
      const branch = node.responsive[key];
      if (!branch || typeof branch !== 'object') {
        clonedResponsive[key] = branch as never;
        continue;
      }
      const clonedBranch = { ...branch };
      if (Array.isArray(branch.children)) {
        clonedBranch.children = (branch.children as EditorNode[]).map(deepCloneTree);
      }
      if (clonedBranch.props && typeof clonedBranch.props === 'object') {
        clonedBranch.props = { ...(clonedBranch.props as Record<string, unknown>) };
      }
      clonedResponsive[key] = clonedBranch;
    }
    cloned.responsive = clonedResponsive;
  }
  return cloned;
}

/**
 * 노드를 부모 children 의 index 위치에 삽입한 새 트리 반환.
 *
 * @param root 루트 노드
 * @param parentPath 부모 경로
 * @param index 삽입할 인덱스 (음수/초과 시 배열 끝으로 클램프)
 * @param newNode 새 노드
 */
export function insertNode(
  root: EditorNode,
  parentPath: ComponentPath,
  index: number,
  newNode: EditorNode,
): EditorNode {
  const cloned = deepCloneTree(root);
  // parentPath 가 responsive 분기 세그먼트로 끝나면(예 `[0,{responsive:'portable'}]`)
  // 그 분기 children 에 삽입. 아니면 base children 에 삽입.
  const lastSeg = parentPath.length > 0 ? parentPath[parentPath.length - 1]! : undefined;
  if (lastSeg !== undefined && isResponsiveSegment(lastSeg)) {
    const owner = findNodeByPath(cloned, parentPath.slice(0, -1));
    if (!owner) return cloned;
    const children = asResponsiveChildren(owner, lastSeg.responsive);
    const safeIndex = Math.max(0, Math.min(index, children.length));
    const next = [...children];
    next.splice(safeIndex, 0, newNode);
    writeSlotChildren({ owner, children, branchKey: lastSeg.responsive, index: safeIndex }, next);
    return cloned;
  }

  const parent = findNodeByPath(cloned, parentPath);
  if (!parent) return cloned;

  const children = asChildrenArray(parent);
  const safeIndex = Math.max(0, Math.min(index, children.length));
  const next = [...children];
  next.splice(safeIndex, 0, newNode);
  parent.children = next;

  return cloned;
}

/**
 * 경로의 노드를 제거한 새 트리 반환.
 */
export function removeNode(root: EditorNode, path: ComponentPath): EditorNode {
  if (path.length === 0) return root; // 루트는 제거 불가
  const cloned = deepCloneTree(root);
  const slot = resolveChildSlot(cloned, path);
  if (!slot) return cloned;

  if (slot.index < 0 || slot.index >= slot.children.length) return cloned;

  const next = [...slot.children];
  next.splice(slot.index, 1);
  writeSlotChildren(slot, next);

  return cloned;
}

/**
 * 디바이스 전용 '분리 생성' — `path` 노드의 기본(base) children 을 깊은 사본으로
 * 그 노드의 `responsive[key].children` 분기에 신설한다.
 *
 * 분리 = "이 영역을 [현재 디바이스] 전용으로 따로 편집하겠다"는 선언. 복사 원본은
 * **기본(맨바탕) 구성** — base children 을 그대로 복제해 분기를
 * 시드한다. 시드 후 `DynamicRenderer` 가 그 디바이스 폭에서 분기 children 을 렌더하고,
 * 다른 디바이스는 base children 을 그대로 본다(자식 완전 교체형).
 *
 * 멱등: 이미 `responsive[key]` 분기에 **children 이 있으면**(분리 신설 완료) 변형 없이 사본만
 * 반환한다(중복 분리 차단). 단 그 분기가 **props 만** 가진 경우(스타일 override)는 분리 대상이다 —
 * props/children 은 독립 축이므로, props-only 분기에 children 을 신설해 분리를 완성한다.
 * (`resolveBranchSeparationMode` 의 children-유무 판정과 정합)
 *
 * 복제 원본(`sourceKey`): 기본은 base children 을 복제하지만, `sourceKey` 가 주어지고 그 분기에
 * children 이 있으면 **그 포괄 분기의 children** 을 복제한다. 현재 디바이스가
 * 더 넓은 분기(예: portable)에 포괄돼 그 구성이 화면에 보일 때, 그 보이는 구성을 그대로 이어
 * 받아 현재 디바이스 전용으로 분기하기 위함이다(base 맨바탕 복제는 화면과 달라 혼선).
 *
 * @param root 루트 노드
 * @param path 분리 대상 노드 경로 (base 를 보는 중인 노드 — 분기 세그먼트 미포함)
 * @param key  현재 디바이스 보기의 breakpoint 키 원문(`mobile`/`portable`/`"600-900"` 등)
 * @param sourceKey 복제 원본 분기 키(포괄 분기). 미지정/그 분기에 children 없으면 base 복제.
 * @return 분기가 신설된 새 트리 사본
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */
export function separateBranch(
  root: EditorNode,
  path: ComponentPath,
  key: string,
  sourceKey?: string,
): EditorNode {
  if (path.length === 0 || !key) return root;
  if (isResponsiveSegment(path[path.length - 1]!)) return root; // 분기 노드는 대상 아님
  const cloned = deepCloneTree(root);
  const node = findNodeByPath(cloned, path);
  if (!node) return cloned;

  // 이미 해당 디바이스 분기에 children 이 있으면 멱등(중복 분리 차단). props-only 분기는
  // 분리 대상 — 아래에서 그 분기에 children 을 신설한다(props 보존).
  const existingBranch = node.responsive && typeof node.responsive === 'object'
    ? (node.responsive[key] as { children?: unknown } | undefined)
    : undefined;
  if (existingBranch && Array.isArray(existingBranch.children)) {
    return cloned;
  }

  // 복제 원본 결정: sourceKey 분기에 children 이 있으면 그것을(포괄 분기 이어받기), 아니면 base.
  const sourceBranch = sourceKey && node.responsive && typeof node.responsive === 'object'
    ? (node.responsive[sourceKey] as { children?: unknown } | undefined)
    : undefined;
  const sourceChildren = sourceBranch && Array.isArray(sourceBranch.children)
    ? (sourceBranch.children as EditorNode[])
    : asChildrenArray(node);
  const seedChildren = sourceChildren.map(deepCloneTree);
  const responsive = { ...(node.responsive ?? {}) } as NonNullable<EditorNode['responsive']>;
  responsive[key] = { ...(responsive[key] ?? {}), children: seedChildren };
  node.responsive = responsive;
  return cloned;
}

/**
 * 분리 제거(되돌림) — `path` 노드의 `responsive[key]` 분기에서 **children 교체만** 제거해
 * 그 디바이스의 자식 구성을 기본(base)으로 복귀시킨다.
 *
 * **props 등 다른 오버라이드는 보존한다**(props/children 독립 축) — 분리 해제는
 * "children 교체"만 되돌리는 것이지 그 디바이스의 스타일 오버라이드까지 지우는 것이 아니다.
 * children 제거 후 분기에 다른 키(props/text/if/iteration)가 남으면 분기를 유지하고, 분기가
 * 완전히 비면 그 키를 제거한다. `responsive` 가 비면 객체 자체를 undefined 로(base 폴백 정합).
 *
 * children 이 없는 분기(props-only)에 대한 호출은 변형 없음(no-op) — 제거할 children 이 없다.
 *
 * @param root 루트 노드
 * @param path 대상 노드 경로 (분기 세그먼트 미포함 — 노드 자신)
 * @param key  분리 해제할 디바이스 분기 키 원문
 * @return children 분기가 제거된 새 트리 사본
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */
export function mergeBranch(root: EditorNode, path: ComponentPath, key: string): EditorNode {
  if (path.length === 0 || !key) return root;
  if (isResponsiveSegment(path[path.length - 1]!)) return root;
  const cloned = deepCloneTree(root);
  const node = findNodeByPath(cloned, path);
  if (!node || !node.responsive || typeof node.responsive !== 'object') return cloned;
  const existing = node.responsive[key] as Record<string, unknown> | undefined;
  if (!existing || !Array.isArray((existing as { children?: unknown }).children)) return cloned; // children 없으면 no-op

  const responsive = { ...node.responsive } as NonNullable<EditorNode['responsive']>;
  const branch: Record<string, unknown> = { ...(responsive[key] as Record<string, unknown>) };
  delete branch.children; // children 교체만 제거 — props/text/if/iteration 등은 보존
  if (Object.keys(branch).length === 0) {
    delete responsive[key]; // 분기가 children 만 가졌으면 분기 제거
  } else {
    responsive[key] = branch as NonNullable<EditorNode['responsive']>[string];
  }
  node.responsive = Object.keys(responsive).length > 0 ? responsive : undefined;
  return cloned;
}

/**
 * 노드를 다른 위치로 이동. fromPath 의 노드를 잘라내 toParentPath/toIndex 에 삽입.
 *
 * 동일 부모 내 이동도 안전하게 처리(인덱스 보정 포함).
 *
 * **좌표 정합**: `removeNode(fromPath)` 후 트리가 변형되므로,
 * 원본 좌표로 받은 `toParentPath` 가 fromPath 의 **뒤쪽 형제(또는 그 자손)** 를
 * 가리키면 한 칸 밀려 어긋난다. 제거 후 좌표로 `toParentPath` 를 rebase 해야
 * 깊은 컨테이너로 이동 시 노드가 유실되지 않는다.
 */
export function moveNode(
  root: EditorNode,
  fromPath: ComponentPath,
  toParentPath: ComponentPath,
  toIndex: number,
): EditorNode {
  if (fromPath.length === 0) return root;
  const foundMoving = findNodeByPath(root, fromPath);
  if (!foundMoving) return root;

  // toParentPath 가 fromPath 의 자손이면 사이클 — 차단
  if (isDescendantPath(fromPath, toParentPath)) {
    return root;
  }

  // 이동할 노드를 **깊은 사본**으로 분리한다.
  // `findNodeByPath` 가 반환하는 노드는 입력 `root` 트리의 **참조**다. 이를 그대로
  // `insertNode` 에 넘기면, `removeNode`/`insertNode` 가 deepClone 으로 만든 새 트리에
  // 입력 트리의 노드 객체가 그대로 스플라이스돼 결과 트리와 입력 트리가 그 서브트리를
  // **참조 공유**한다. 그러면 history 의 직전 스냅샷(이동 전, 입력 트리 참조 보관)과
  // 이동 후 스냅샷이 같은 노드를 공유 → undo 시 그 노드가 원위치·새위치 양쪽에 나타나
  // **복사(중복)** 처럼 보인다.
  const moving = deepCloneTree(foundMoving);

  const afterRemove = removeNode(root, fromPath);

  // toParentPath 를 제거 후 좌표로 rebase — fromPath 의 부모 레벨에서 fromIndex 보다
  // 큰 형제 인덱스는 -1 (제거로 당겨짐).
  const rebasedToParentPath = rebasePathAfterRemoval(toParentPath, fromPath);

  // 같은 부모 + fromIndex < toIndex 면 제거로 인해 인덱스가 1 줄어든다
  let adjustedToIndex = toIndex;
  const fromParentPath = fromPath.slice(0, -1);
  if (pathsEqual(fromParentPath, toParentPath)) {
    const fromLast = fromPath[fromPath.length - 1];
    const fromIndex = typeof fromLast === 'number' ? fromLast : 0;
    if (fromIndex < toIndex) {
      adjustedToIndex = Math.max(0, toIndex - 1);
    }
  }

  return insertNode(afterRemove, rebasedToParentPath, adjustedToIndex, moving);
}

/**
 * `fromPath` 노드를 제거한 뒤의 트리 좌표로 `targetPath` 를 rebase.
 *
 * 제거는 fromPath 부모 레벨에서 fromIndex 이후 형제만 한 칸 당긴다. targetPath 가
 * fromPath 와 같은 부모 prefix 를 공유하고 그 레벨 인덱스가 fromIndex 보다 크면 -1,
 * 그 외(다른 가지/앞쪽 형제/더 얕은 경로)는 불변.
 */
export function rebasePathAfterRemoval(
  targetPath: ComponentPath,
  fromPath: ComponentPath,
): ComponentPath {
  if (fromPath.length === 0) return targetPath;
  const parentLevel = fromPath.length - 1;
  if (targetPath.length <= parentLevel) return targetPath;
  // 부모 prefix 가 세그먼트 단위로 동일해야 같은 가지 — segEqual 로 비교
  // (responsive 세그먼트는 객체이므로 원시 `!==` 금지 G-1).
  for (let i = 0; i < parentLevel; i++) {
    if (!segEqual(targetPath[i]!, fromPath[i]!)) return targetPath;
  }
  const fromSeg = fromPath[parentLevel]!;
  const targetSeg = targetPath[parentLevel]!;
  // 제거로 인한 인덱스 당김은 같은 children 배열 안 number 세그먼트에만 적용된다.
  // 한쪽이 responsive 세그먼트면(다른 children 배열) rebase 대상 아님.
  if (isResponsiveSegment(fromSeg) || isResponsiveSegment(targetSeg)) return targetPath;
  if (targetSeg > fromSeg) {
    const next = [...targetPath];
    next[parentLevel] = targetSeg - 1;
    return next;
  }
  return targetPath;
}

function pathsEqual(a: ComponentPath, b: ComponentPath): boolean {
  if (a.length !== b.length) return false;
  for (let i = 0; i < a.length; i++) {
    if (!segEqual(a[i]!, b[i]!)) return false;
  }
  return true;
}

function isDescendantPath(ancestorPath: ComponentPath, candidatePath: ComponentPath): boolean {
  if (candidatePath.length < ancestorPath.length) return false;
  for (let i = 0; i < ancestorPath.length; i++) {
    if (!segEqual(candidatePath[i]!, ancestorPath[i]!)) return false;
  }
  return candidatePath.length > ancestorPath.length || pathsEqual(ancestorPath, candidatePath);
}

/**
 * 노드 복제 (id 미부여 정책).
 *
 * - 원본에 id 가 없으면 복제본도 id 없이.
 * - 원본에 id 가 있으면 복제본의 id 만 새로 생성(같은 레이아웃 내 중복 방지).
 * - 자식 트리도 동일 규칙으로 재귀.
 */
export function duplicateNode(node: EditorNode): EditorNode {
  const cloned: EditorNode = { ...node };

  if (cloned.props && typeof cloned.props === 'object') {
    cloned.props = { ...(cloned.props as Record<string, unknown>) };
  }

  // id 가 있는 노드만 새 id 부여 — 없는 노드는 그대로 id 없음
  if (typeof cloned.id === 'string' && cloned.id.length > 0) {
    const name = typeof cloned.name === 'string' ? cloned.name : 'node';
    cloned.id = `${name}_${generateShortId()}`;
  }

  const children = asChildrenArray(node);
  if (children.length > 0) {
    cloned.children = children.map(duplicateNode);
  }

  return cloned;
}

/**
 * 노드 일부 속성을 patch (부분 갱신). path 경로의 노드를 patcher 가 반환한
 * 새 노드로 대체한다. patcher 는 immutable 하게 새 노드를 만들어 반환할 것.
 */
export function patchNode(
  root: EditorNode,
  path: ComponentPath,
  patcher: (node: EditorNode) => EditorNode,
): EditorNode {
  if (path.length === 0) {
    return patcher(root);
  }
  const cloned = deepCloneTree(root);
  const target = findNodeByPath(cloned, path);
  if (!target) return cloned;

  const newNode = patcher(target);
  const slot = resolveChildSlot(cloned, path);
  if (!slot) return cloned;

  if (slot.index < 0 || slot.index >= slot.children.length) return cloned;

  const next = [...slot.children];
  next[slot.index] = newNode;
  writeSlotChildren(slot, next);

  return cloned;
}

/**
 * id 지연 부여.
 *
 * - 이미 id 가 있으면 그대로 반환(노드를 변경하지 않음).
 * - id 가 없으면 `{name}_{shortid}` 형식으로 부여한 새 노드 사본 반환.
 *
 * 트리 전체에 id 를 일괄 부여하지 않는다 — 본 함수는 그 노드 하나만 처리한다.
 * 모달 참조 / openModal target 등 id 가 기능적으로 필요한 시점에 호출.
 */
export function ensureNodeId(node: EditorNode): EditorNode {
  if (typeof node.id === 'string' && node.id.length > 0) {
    return node;
  }
  const name = typeof node.name === 'string' ? node.name : 'node';
  return { ...node, id: `${name}_${generateShortId()}` };
}

/**
 * 짧은 ID 생성 — 8자 base36. 충돌 가능성은 낮지만(36^8 ≈ 2.8조), 모달 등 id
 * 가 필요한 노드만 부여하므로 같은 레이아웃 내 충돌은 사실상 없다.
 */
function generateShortId(): string {
  const random = Math.floor(Math.random() * 36 ** 8);
  return random.toString(36).padStart(8, '0');
}

export interface ValidationIssue {
  path: ComponentPath;
  code: string;
  message: string;
}

/**
 * 클라이언트 1차 검증 — 저장 전 즉시 피드백용. 백엔드의 ValidLayout
 * Structure 와 같은 결정을 모두 모사하지는 않고, 명백한 구조 위반만 잡는다.
 *
 * 위반 케이스(MVP):
 *  - 노드에 `type` 도 `name` 도 없음
 *  - children 이 배열이 아닌 truthy 값
 */
export function validate(root: EditorNode): ValidationIssue[] {
  const issues: ValidationIssue[] = [];
  walk(root, [], issues);
  return issues;
}

function walk(node: EditorNode, path: ComponentPath, issues: ValidationIssue[]): void {
  if (!node || typeof node !== 'object') {
    issues.push({ path, code: 'invalid_node', message: 'node must be an object' });
    return;
  }

  const hasType = typeof node.type === 'string' && node.type.length > 0;
  const hasName = typeof node.name === 'string' && node.name.length > 0;
  // 루트 노드는 컴포넌트가 아니라 컨테이너(레이아웃 root) 일 수 있으므로
  // type/name 부재를 허용한다. 자식 노드부터는 둘 중 하나 필수.
  if (path.length > 0 && !hasType && !hasName) {
    issues.push({
      path,
      code: 'missing_type_and_name',
      message: 'node requires type or name',
    });
  }

  const children = node.children;
  if (children !== undefined && !Array.isArray(children)) {
    issues.push({
      path,
      code: 'children_not_array',
      message: 'children must be an array',
    });
    return;
  }

  if (Array.isArray(children)) {
    for (let i = 0; i < children.length; i++) {
      walk(children[i] as EditorNode, [...path, i], issues);
    }
  }
}

/**
 * 저장 페이로드 마스킹 — 상속/주입/partial 출처 노드 제거 + 편집기 전용 메타 제거.
 *
 * 편집 모드 응답(`with_source_meta=1`)은 자식 레이아웃의 슬롯에 base 레이아웃 노드를
 * 머지해 노출한다 (`__source.kind === 'base'`, `_fromBase: true`). 확장 주입 노드
 * (`kind === 'extension'`)와 partial 인클루드 노드(`kind === 'partial'`)도 동일하게
 * 응답 트리에 함께 들어 있다. 이 메타 노드들이 그대로 PUT 페이로드에 포함되면:
 *
 *   1) **데이터 손상**: 자식 레이아웃이 base 노드를 자기 components 로 흡수해
 *      다음 로드 시 머지 결과가 중복되거나 base 변경이 자식에서 사라진다.
 *   2) **확장/partial 오염**: layout_extensions / partial 파일과 별개로 라우트
 *      레이아웃에 같은 노드가 박혀, 확장 비활성/partial 수정에도 잔존한다.
 *   3) **422 검증 실패**: `__source` / `_fromBase` 메타 필드가 ValidLayoutStructure
 *      의 type/name 단순 검증을 거쳐도, 머지된 트리는 자식 레이아웃의 원래 형태가
 *      아니므로 형식 위반이 누적된다.
 *
 * 본 함수는 저장 직전 클라이언트 1차 마스킹으로 호출되며, 동일 정책의 백엔드 2차
 * 가드(`UpdateLayoutContentRequest::prepareForValidation`)가 최종 방어선이다.
 *
 * 정책:
 * - `__source.kind === 'base'` 또는 `_fromBase === true` → 노드 통째 제거.
 * - `__source.kind === 'extension'` → 노드 통째 제거 (layout_extensions 가 SSoT).
 * - `__source.kind === 'partial'` → 노드 통째 제거 (partial 파일이 SSoT).
 * - `__source.kind === 'route'` 또는 `__source` 미부여 → 보존 + children 재귀 마스킹.
 * - 살아남은 노드의 `__source` / `_fromBase` 메타는 모두 제거 (저장 형식 = 원본 형식).
 *
 * @param components 편집기 응답의 머지된 components 배열
 * @returns 자식 레이아웃의 원본 components 형태에 가까운 마스킹된 배열
 *
 * @since engine-v1.50.0
 */
export function stripInheritedNodes(components: EditorNode[] | undefined): EditorNode[] {
  if (!Array.isArray(components) || components.length === 0) {
    return [];
  }
  const result: EditorNode[] = [];
  for (const node of components) {
    const cleanedList = stripInheritedNode(node);
    if (cleanedList.length > 0) {
      result.push(...cleanedList);
    }
  }
  return result;
}

/**
 * 단일 노드 마스킹 — 노드 종류에 따라 0개·1개·N개를 반환한다.
 *
 * **slot 래퍼 처리 (결함 T-2 / 2026-05-27)**:
 * `LayoutService::replaceSlots` 가 머지할 때 다음 두 종류의 base 노드를 만든다:
 *  - **slot 래퍼**: `__source.kind === 'base'` + `_fromBase` 부재. base 레이아웃의
 *    `slot` 정의 위치에 자식 콘텐츠(route 출처)가 끼워진 컨테이너. 슬롯 자체는
 *    base 가 정의했지만 그 안의 콘텐츠는 자식 레이아웃 소속.
 *  - **일반 base 노드**: `__source.kind === 'base'` + `_fromBase: true`. 헤더/사이드바
 *    /푸터 등 base 가 정의하고 자식 레이아웃에는 박힐 일이 없는 노드.
 *
 * 마스킹 규칙:
 *  - slot 래퍼: 자체는 버리되 **children 을 부모 배열로 끌어올림** (재귀 마스킹).
 *    이러면 슬롯에 박힌 route 콘텐츠와 그 안에 사용자가 추가한 신규 노드가 살아남는다.
 *  - 일반 base 노드 (`_fromBase: true`): 통째 제거.
 *  - extension/partial 노드: 통째 제거.
 *  - route 또는 메타 미부여 노드: 보존 + 메타 제거 + children 재귀 마스킹.
 */
function stripInheritedNode(node: EditorNode | undefined | null): EditorNode[] {
  if (!node || typeof node !== 'object') return [];

  const src = (node as EditorNode).__source;
  const fromBase = (node as Record<string, unknown>)._fromBase === true;
  const kind = src?.kind;

  // extension/partial 노드 — 통째 제거 (SSoT 는 layout_extensions / partial 파일)
  if (kind === 'extension' || kind === 'partial') return [];

  // base 출처 노드 (slot 래퍼 또는 일반 base 노드) — 자체는 버리되 children 의 route
  // 콘텐츠는 끌어올린다. `LayoutService::replaceSlots` 가 base 의 깊은 자손에 slot
  // 래퍼를 두고 그 안에 route 콘텐츠를 끼우므로, base 노드를 무조건 제거하면 그
  // 자손의 route 콘텐츠까지 사라진다. children 재귀 마스킹으로 살아남은 route 노드만
  // 부모 배열로 끌어올림.
  if (kind === 'base' || fromBase) {
    const children = Array.isArray(node.children) ? (node.children as EditorNode[]) : [];
    return stripInheritedNodes(children);
  }

  // route 또는 메타 미부여 노드 — 보존 + 메타 제거 + children 재귀 마스킹
  const cleaned: EditorNode = { ...node };
  delete (cleaned as Record<string, unknown>).__source;
  delete (cleaned as Record<string, unknown>)._fromBase;
  // inject_props 호스트 메타 — 편집기 전용. 저장 시 제거(확장 행이 SSoT).
  delete (cleaned as Record<string, unknown>).__injectedProps;

  if (Array.isArray(cleaned.children)) {
    cleaned.children = stripInheritedNodes(cleaned.children as EditorNode[]);
  }
  return [cleaned];
}

/**
 * 저장 페이로드 마스킹 — 편집 모드 응답에서 저장에 사용할 content 를 추출.
 *
 * 백엔드 `LayoutService::loadAndMergeLayout` 가 편집 모드(`withSourceMeta=true`)
 * 응답에 자식 레이아웃의 **원본 구조 메타** 를 `__editor.original` 컨테이너로
 * 동봉한다: `extends`, `slots` 의 슬롯 이름 집합,
 * 그리고 components 머지 시 사용된 base/partial/extension 구조.
 *
 * **핵심 정책 — 결함 T (2026-05-27) 보강**:
 * `__editor.original` 은 **구조 메타데이터의 SSoT** 일 뿐 콘텐츠의 SSoT 가 아니다.
 * 사용자가 캔버스에서 편집한 콘텐츠는 `content.components` (머지된 트리) 에 누적
 * 되며, 저장 시에는 그 트리에서 **`__source.kind === 'route'` + 메타 미부여(신규
 * 추가) 노드만 추출** 하여 슬롯에 매핑해야 한다. `__editor.original` 의 slots 를
 * 그대로 보내면 사용자 편집분이 통째로 무시되는 결함이 발생 (이슈).
 *
 * 흐름:
 * 1. `content.components` (머지된 트리) 를 마스킹 → 본 자식 레이아웃의 route 콘텐츠만 남김
 * 2. `__editor.original` 에서 `extends`/`meta`/`data_sources` 등 구조 메타 차용
 * 3. extends 가 있으면 components 키 제거 + slots 로 재구성, 없으면 components 보존
 * 4. 응답 전용 메타(`lock_version`, `__editor`) 제거
 *
 * **slots 재구성 (extends 자식 레이아웃)**:
 * `__editor.original.slots` 의 각 슬롯 이름을 키로 두고, 값은 마스킹된 components 의
 * 자식 트리에서 추출한다. 단일 슬롯(`content`) 레이아웃은 마스킹된 components 전체를
 * 그 슬롯에 매핑. 다중 슬롯은 슬롯별 매핑이 모호하므로 폴백 — 원본 slots 보존.
 *
 * @since engine-v1.50.0
 */
export function stripInheritedFromLayoutContent(
  content: Record<string, unknown>,
): Record<string, unknown> {
  if (!content || typeof content !== 'object') return content;

  const editorMeta = (content as Record<string, unknown>).__editor;
  const editorOriginalRaw =
    editorMeta && typeof editorMeta === 'object' && !Array.isArray(editorMeta)
      ? (editorMeta as Record<string, unknown>).original
      : undefined;
  // original 은 plain object 일 때만 SSoT 로 사용. 그 외(string, array, null 등)는 폴백.
  const original =
    editorOriginalRaw && typeof editorOriginalRaw === 'object' && !Array.isArray(editorOriginalRaw)
      ? (editorOriginalRaw as Record<string, unknown>)
      : undefined;

  // 1) 사용자 편집분이 담긴 머지된 components 트리를 마스킹
  const mergedComponents = Array.isArray((content as Record<string, unknown>).components)
    ? ((content as Record<string, unknown>).components as EditorNode[])
    : [];
  const hasMergedComponents = mergedComponents.length > 0;
  const maskedComponents = stripInheritedNodes(mergedComponents);

  // 2) 결과 골격 — __editor.original 의 구조 메타가 있으면 우선 사용 (extends/meta/data_sources/permissions 등)
  //    없으면 content 자체를 골격으로 사용 (레거시 응답 / 비편집 모드)
  const skeleton: Record<string, unknown> = original
    ? { ...(original as Record<string, unknown>) }
    : { ...(content as Record<string, unknown>) };

  const hasExtends = typeof skeleton.extends === 'string' && (skeleton.extends as string).length > 0;

  // Path A — extends 자식 + components 미존재 (클라이언트가 이미 마스킹한 slots 만 전송) /
  //          또는 __editor 미존재 레거시 응답: skeleton.slots 를 마스킹만 수행하고 그대로 보존
  // Path B — components 머지 트리 존재: 마스킹된 components 를 slots 에 재구성
  if (hasExtends) {
    delete skeleton.components;

    const skeletonSlots =
      skeleton.slots && typeof skeleton.slots === 'object' && !Array.isArray(skeleton.slots)
        ? (skeleton.slots as Record<string, unknown>)
        : {};
    const slotNames = Object.keys(skeletonSlots);

    if (!hasMergedComponents && slotNames.length > 0) {
      // Path A: 클라이언트가 이미 마스킹한 slots 만 있는 응답 — slots 의 각 슬롯을 마스킹만 수행
      const nextSlots: Record<string, unknown> = {};
      for (const [slotName, slotValue] of Object.entries(skeletonSlots)) {
        nextSlots[slotName] = Array.isArray(slotValue)
          ? stripInheritedNodes(slotValue as EditorNode[])
          : slotValue;
      }
      skeleton.slots = nextSlots;
    } else if (slotNames.length === 1) {
      // Path B 단일 슬롯: 마스킹된 components 전체를 그 슬롯에 매핑
      skeleton.slots = { [slotNames[0]!]: maskedComponents };
    } else if (slotNames.length === 0) {
      // 슬롯 정의 부재 — base 머지 결과에서 슬롯 이름을 알 수 없음. 안전 폴백: 'content' 슬롯에 매핑
      skeleton.slots = { content: maskedComponents };
    } else {
      // Path B 다중 슬롯: 슬롯별 매핑 불가 — 각 슬롯의 마스킹된 원본 보존 (사용자 편집 손실 방지를 위한 차선)
      const nextSlots: Record<string, unknown> = {};
      for (const [slotName, slotValue] of Object.entries(skeletonSlots)) {
        nextSlots[slotName] = Array.isArray(slotValue)
          ? stripInheritedNodes(slotValue as EditorNode[])
          : slotValue;
      }
      skeleton.slots = nextSlots;
    }
  } else {
    // 독립 레이아웃: 마스킹된 components 를 그대로 사용
    skeleton.components = maskedComponents;
    delete skeleton.slots;
  }

  // 3) 응답 전용 메타 제거
  delete skeleton.lock_version;
  delete skeleton.__editor;

  return skeleton;
}
