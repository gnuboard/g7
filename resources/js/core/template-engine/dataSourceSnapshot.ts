/**
 * 「더보기」로 늘어난 데이터소스의 히스토리 항목별 스냅샷 (뒤로/앞으로 가기 스크롤 복원용)
 *
 * 더보기·무한 스크롤은 `appendDataSource`(→ `G7Core.dataSource.updateData`)로 받은 묶음을 데이터소스에 덧붙인다.
 * 뒤로 가기로 돌아오면 화면은 첫 묶음만 다시 받아 짧아져, 기억한 스크롤 위치에 닿지 못한다.
 * 그래서 화면을 떠날 때 덧붙여 늘어난 데이터소스를 그 항목 키로 기억했다가, 돌아오면(유효 시간 안) 그 데이터로
 * 먼저 그려 높이를 확보하고, 다시 받은 첫 묶음은 항목 식별 필드(`item_key`)로 대조해 바뀐 항목만 갈아끼운다.
 *
 * - 기억은 메모리에만 둔다(새로고침이면 사라진다 — 높이 대기 복원만).
 * - 최근 {@link MAX_SNAPSHOT_ENTRIES} 항목까지.
 *
 * @since engine-v1.85.0
 */

/** 한 데이터소스의 스냅샷 */
export interface DataSourceSnapshot {
  id: string;
  /** 덧붙인 배열의 경로 (null 이면 데이터소스 값 자체가 배열) */
  path: string | null;
  data: unknown;
  savedAt: number;
}

/** 기억하는 항목 수 상한 */
export const MAX_SNAPSHOT_ENTRIES = 10;

/** 지금 화면에서 덧붙여 늘어난 데이터소스 — id → 배열 경로·처음 기억한 시각(되살린 스냅샷이면 그 시각, 아니면 null) */
const grown: Map<string, { path: string | null; since: number | null }> = new Map();
/** 한 항목의 스냅샷 묶음 — 데이터소스들 + 함께 기억한 상태(경로 → 값) */
interface EntrySnapshot {
  sources: DataSourceSnapshot[];
  state: Record<string, unknown>;
  /** 기억할 때의 화면 문맥(언어·계정) — 다르면 되살리지 않는다(다른 계정 데이터·이전 언어 문구 재발 방지) */
  context: string;
}

/** 돌아온 항목에서 가져간 스냅샷 */
export interface TakenSnapshot {
  sources: Map<string, DataSourceSnapshot>;
  state: Record<string, unknown>;
}

/** 항목 키 → 스냅샷 묶음 */
const store: Map<string, EntrySnapshot> = new Map();

/**
 * 데이터소스가 덧붙여 늘어났다고 표시한다 (updateData append·prepend 성공 뒤, 되살린 스냅샷을 심을 때)
 *
 * 되살린 스냅샷을 심을 때는 그 스냅샷을 처음 기억한 시각(`since`)을 넘긴다 — 다시 떠날 때 그 시각으로 기억해, 오갈 때마다
 * 유효 시간이 늘어나 2쪽 이후 항목이 한없이 다시 받지 않은 채 남지 않게 한다(첫 묶음만 다시 받는다). 이어서 더보기를 해도
 * 이미 있는 시각은 유지한다(앞쪽 묶음이 그만큼 오래됐다).
 *
 * @param id 데이터소스 id
 * @param path 덧붙인 배열 경로
 * @param since 처음 기억한 시각(ms) — 되살린 스냅샷을 심을 때만
 */
export function markDataSourceGrown(id: string, path: string | null, since?: number): void {
  const previous = grown.get(id)?.since ?? null;
  grown.set(id, { path, since: since ?? previous });
}

/**
 * 늘어남 표시를 지운다 — 되살린 스냅샷을 이번 화면에서 쓰지 못했을 때(다시 받기 실패·대체 값). 다시 떠날 때 그 데이터가
 * 새 스냅샷으로 기억되며 유효 시간이 연장되지 않게 한다.
 *
 * @param id 데이터소스 id
 */
export function unmarkDataSourceGrown(id: string): void {
  grown.delete(id);
}

/**
 * 떠나는 화면의 늘어난 데이터소스를 그 항목 키로 기억한다. 늘어난 것이 없으면 그 항목의 옛 스냅샷을 지운다
 * (다시 방문해 첫 묶음만 본 화면에 앞 방문의 긴 목록을 되살리지 않게). 늘어난 것이 있으면 `statePaths` 의 상태 값도
 * 함께 기억한다(더보기의 다음 쪽 번호처럼 목록 길이와 맞아야 하는 진행 상태).
 *
 * @param entryKey 떠나는 항목 키 (null 이면 표시만 비운다)
 * @param read 데이터소스 현재 값 읽기
 * @param statePaths 함께 기억할 상태 경로(`_global.…`·`_local.…`)
 * @param readState 상태 경로 값 읽기
 * @param context 화면 문맥(언어·계정) — 되살릴 때 같아야 한다
 */
export function captureGrownDataSources(
  entryKey: string | null,
  read: (id: string) => unknown,
  statePaths: string[] = [],
  readState: (path: string) => unknown = () => undefined,
  context = '',
): void {
  if (entryKey !== null) {
    const snapshots: DataSourceSnapshot[] = [];
    const now = Date.now();
    for (const [id, { path, since }] of grown) {
      const value = read(id);
      if (value !== undefined && value !== null) {
        snapshots.push({ id, path, data: cloneData(value), savedAt: since ?? now });
      }
    }
    store.delete(entryKey);
    if (snapshots.length > 0) {
      const state: Record<string, unknown> = {};
      for (const path of statePaths) {
        const value = readState(path);
        if (value !== undefined) {
          state[path] = cloneData(value);
        }
      }
      store.set(entryKey, { sources: snapshots, state, context });
      while (store.size > MAX_SNAPSHOT_ENTRIES) {
        const oldest = store.keys().next().value;
        if (oldest === undefined) break;
        store.delete(oldest);
      }
    }
  }
  grown.clear();
}

/**
 * 돌아온 항목의 스냅샷을 가져간다 — 유효 시간이 지났거나 화면 문맥(언어·계정)이 다르면 버린다(그러면 함께 기억한 상태도
 * 되돌리지 않는다). 늘어남 표시는 여기서 하지 않는다 — 화면 처리가 실제로 그 데이터를 심을 때 {@link markDataSourceGrown}
 * 으로 한다. 여기서 표시하면 이 화면 처리가 다음 이동에 밀려 심지 못했을 때, 남아 있던 앞 화면 값이 이 항목의 스냅샷으로
 * 잘못 기억된다.
 *
 * @param entryKey 항목 키
 * @param ttlSeconds 유효 시간(초)
 * @param context 지금 화면 문맥(언어·계정)
 * @returns 데이터소스 id → 스냅샷과 함께 기억한 상태 (없으면 빈 값)
 */
export function takeDataSourceSnapshots(entryKey: string, ttlSeconds: number, context = ''): TakenSnapshot {
  const entry = store.get(entryKey);
  store.delete(entryKey);
  const out: TakenSnapshot = { sources: new Map(), state: {} };
  if (!entry || entry.context !== context) {
    return out;
  }
  const now = Date.now();
  for (const snapshot of entry.sources) {
    if (now - snapshot.savedAt <= ttlSeconds * 1000) {
      out.sources.set(snapshot.id, snapshot);
    }
  }
  if (out.sources.size > 0) {
    out.state = cloneData(entry.state);
  }

  return out;
}

/**
 * 스냅샷에 다시 받은 첫 묶음을 반영한다 — 스냅샷 목록의 항목 중 식별 필드가 같은 것은 새 값으로 바꾸고, 첫 묶음에서
 * 스냅샷과 처음 겹치는 항목보다 앞에 새로 생긴 항목(최신순 목록의 새 글·새 알림)은 앞에 붙인다. 목록 밖 필드(쪽 정보 등)는
 * 스냅샷 것을 유지한다(목록 길이와 맞게). 모양이 다르면 스냅샷을 그대로 쓴다.
 *
 * @param snapshot 스냅샷
 * @param fresh 다시 받은 값
 * @param itemKey 항목 식별 필드
 * @returns 반영한 값 (새 객체)
 */
export function mergeSnapshotWithFresh(snapshot: DataSourceSnapshot, fresh: unknown, itemKey: string): unknown {
  const base = cloneData(snapshot.data);
  const snapList = readPath(base, snapshot.path);
  const freshList = readPath(fresh, snapshot.path);
  if (!Array.isArray(snapList) || !Array.isArray(freshList)) {
    return base;
  }

  const byKey = new Map<unknown, unknown>();
  for (const item of freshList) {
    if (item && typeof item === 'object' && itemKey in (item as Record<string, unknown>)) {
      byKey.set((item as Record<string, unknown>)[itemKey], item);
    }
  }
  const keyOf = (item: unknown): unknown => (item && typeof item === 'object' && itemKey in (item as Record<string, unknown>)
    ? (item as Record<string, unknown>)[itemKey]
    : undefined);
  const snapKeys: Set<unknown> = new Set(snapList.map(keyOf).filter((k) => k !== undefined));
  const merged = snapList.map((item) => {
    const key = keyOf(item);
    if (key !== undefined) {
      const replacement = byKey.get(key);
      if (replacement !== undefined) {
        return cloneData(replacement);
      }
    }
    return item;
  });

  // 첫 묶음 앞쪽의 새 항목 — 스냅샷과 처음 겹치는 항목 전까지(겹치는 항목이 없으면 붙이지 않는다: 정렬·필터가 달라진 응답일 수 있다)
  const firstOverlap = freshList.findIndex((item) => snapKeys.has(keyOf(item)));
  const added = firstOverlap > 0
    ? freshList.slice(0, firstOverlap).filter((item) => keyOf(item) !== undefined && !snapKeys.has(keyOf(item))).map((item) => cloneData(item))
    : [];

  return writePath(base, snapshot.path, [...added, ...merged]);
}

/**
 * 기억을 비운다 (시험용)
 */
export function resetDataSourceSnapshotsForTesting(): void {
  grown.clear();
  store.clear();
}

/**
 * 진단용 — 항목 키의 스냅샷 데이터소스 id 목록
 *
 * @param entryKey 항목 키
 * @returns id 목록
 */
export function peekDataSourceSnapshotIds(entryKey: string): string[] {
  return (store.get(entryKey)?.sources ?? []).map((s) => s.id);
}

/** 깊은 복사 (화면이 이어서 고치는 객체와 떼어 둔다) */
function cloneData<T>(value: T): T {
  try {
    if (typeof structuredClone === 'function') {
      return structuredClone(value);
    }
  } catch {
    // 복사할 수 없는 값(함수 등) — JSON 으로
  }
  try {
    return JSON.parse(JSON.stringify(value)) as T;
  } catch {
    return value;
  }
}

/** 점 경로 읽기 */
function readPath(value: unknown, path: string | null): unknown {
  if (path === null || path === '') {
    return value;
  }
  let current: unknown = value;
  for (const part of path.split('.')) {
    if (current === null || typeof current !== 'object') {
      return undefined;
    }
    current = (current as Record<string, unknown>)[part];
  }

  return current;
}

/** 점 경로 쓰기 (경로가 없으면 값 그대로) */
function writePath(value: unknown, path: string | null, next: unknown): unknown {
  if (path === null || path === '') {
    return next;
  }
  const parts = path.split('.');
  const last = parts.pop() as string;
  let parent: unknown = value;
  for (const part of parts) {
    if (parent === null || typeof parent !== 'object') {
      return value;
    }
    parent = (parent as Record<string, unknown>)[part];
  }
  if (parent !== null && typeof parent === 'object') {
    (parent as Record<string, unknown>)[last] = next;
  }

  return value;
}
