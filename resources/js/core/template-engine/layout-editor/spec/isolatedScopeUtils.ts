/**
 * isolatedScopeUtils.ts — 격리 상태(`isolatedState`/`isolatedScopeId`) 스캔·짝검증 순수 유틸
 *
 *
 * `_isolated` 는 `isolatedState` 속성을 가진 컴포넌트 영역 안에서만 유효하다. 본 유틸은:
 *  - `collectIsolatedScopes`: components 트리(중첩·iteration 내부·responsive 분기 포함)에서
 *    `isolatedState`/`isolatedScopeId` 보유 노드를 전수 수집.
 *  - `classifyIsolatedOrphan`: `initIsolated` 키 orphan 판정(보수적 — isolatedState 노드 0개면
 *  전부 orphan). 상속받은 키도 짝 검증 대상.
 *  - `buildScopeIdCandidates`: scopeId 검색 드롭다운 후보(기존 scopeId + initIsolated 키 +
 *  관용 패턴 `*-scroll`/`*-selector` 등) 합집합·중복 제거.
 *
 * 모든 함수 순수.
 *
 * @since engine-v1.50.0
 */

/** 수집된 격리 스코프 1건 */
export interface IsolatedScope {
  /** isolatedScopeId 값(없으면 undefined — 노드 id 기반 자동 시드 전) */
  scopeId?: string;
  /** isolatedState 시작값(노드 속성 자체의 초기값) */
  initialState?: Record<string, unknown>;
}

/** 트리 노드(느슨) */
type AnyNode = Record<string, unknown>;

/** 한 노드의 자식 후보를 모은다 — children + responsive 분기 children + iteration 본체 */
function childNodes(node: AnyNode): AnyNode[] {
  const out: AnyNode[] = [];
  const pushArray = (v: unknown): void => {
    if (Array.isArray(v)) {
      for (const c of v) if (c && typeof c === 'object') out.push(c as AnyNode);
    }
  };
  pushArray(node.children);
  // responsive 분기의 children 도 순회(분기 내부 노드도 격리 보유 가능).
  const responsive = node.responsive;
  if (responsive && typeof responsive === 'object') {
    for (const branch of Object.values(responsive as Record<string, unknown>)) {
      if (branch && typeof branch === 'object') pushArray((branch as AnyNode).children);
    }
  }
  return out;
}

/**
 * components 트리에서 격리 스코프 보유 노드를 전수 수집한다.
 *
 * `isolatedState` 또는 `isolatedScopeId` 중 하나라도 보유하면 격리 노드로 본다(둘은 1:1 쌍이
 * 정상이나, 한쪽만 있어도 수집 — 짝 검증은 호출자/classifyIsolatedOrphan 책임).
 *
 * @param components 레이아웃 components 트리(루트 배열)
 * @return 수집된 격리 스코프 목록
 */
export function collectIsolatedScopes(components: unknown): IsolatedScope[] {
  const out: IsolatedScope[] = [];
  const visit = (node: AnyNode): void => {
    const hasIsolatedState = 'isolatedState' in node && node.isolatedState != null;
    const scopeId = node.isolatedScopeId;
    if (hasIsolatedState || (typeof scopeId === 'string' && scopeId.length > 0)) {
      out.push({
        scopeId: typeof scopeId === 'string' ? scopeId : undefined,
        initialState:
          node.isolatedState && typeof node.isolatedState === 'object'
            ? (node.isolatedState as Record<string, unknown>)
            : undefined,
      });
    }
    for (const child of childNodes(node)) visit(child);
  };
  if (Array.isArray(components)) {
    for (const node of components) if (node && typeof node === 'object') visit(node as AnyNode);
  }
  return out;
}

/**
 * `initIsolated` 키들의 orphan 여부를 판정한다(보수적).
 *
 * isolatedState 노드가 0개면 모든 initIsolated 키가 orphan(어디서도 안 쓰이는 죽은 값).
 * 1개 이상이면 비-orphan(보수적 — 어느 영역이 그 키를 쓸 수 있다고 본다). 상속받은
 * initIsolated 키도 동일 대상(I17/I18).
 *
 * @param initIsolatedKeys initIsolated 키 목록
 * @param scopes 수집된 격리 스코프(collectIsolatedScopes 결과)
 * @return key → orphan 여부 맵
 */
export function classifyIsolatedOrphan(
  initIsolatedKeys: string[],
  scopes: IsolatedScope[],
): Record<string, boolean> {
  const noScopes = scopes.length === 0;
  const out: Record<string, boolean> = {};
  for (const key of initIsolatedKeys) {
    out[key] = noScopes; // 노드 0개면 orphan, 있으면 비-orphan(보수적).
  }
  return out;
}

/** scopeId 후보의 관용 접미 패턴(실데이터 도출) */
const IDIOM_SUFFIXES = ['scroll', 'selector', 'wizard', 'slider'];

/**
 * scopeId 검색 드롭다운 후보를 빌드한다.
 *
 * 기존 scopeId 전수 + initIsolated 키 + 관용 패턴(키 기반 `{key}-scroll` 등) 합집합·중복 제거.
 *
 * @param scopes 수집된 격리 스코프
 * @param initIsolatedKeys initIsolated 키 목록
 * @return 후보 scopeId 배열(중복 제거)
 */
export function buildScopeIdCandidates(
  scopes: IsolatedScope[],
  initIsolatedKeys: string[],
): string[] {
  const set = new Set<string>();
  for (const s of scopes) {
    if (s.scopeId) set.add(s.scopeId);
  }
  for (const key of initIsolatedKeys) {
    set.add(key);
    for (const suffix of IDIOM_SUFFIXES) set.add(`${key}-${suffix}`);
  }
  return Array.from(set);
}
