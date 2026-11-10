/**
 * 히스토리 항목별 스크롤 위치 기억·복원 (뒤로/앞으로 가기·새로고침)
 *
 * SPA 화면은 뒤로/앞으로 가기에서 데이터를 다시 받아 높이가 늦게 늘어난다. 브라우저 기본 복원
 * (`history.scrollRestoration = 'auto'`)은 popstate 직후 한 번만 시도해, 그때 문서가 짧으면 맨 위에
 * 머문다(실측: 모바일 홈 2,742px 에서 상품으로 갔다가 돌아오면 0 — 홈 높이가 약 1초에 걸쳐 3,416 → 5,574px).
 * 그래서 기본 복원을 끄고(`manual`) 항목마다 위치를 기억했다가, 화면을 다시 그린 뒤 설정에 따라 되돌린다.
 *
 * 역할 분담
 * - 이 모듈: 항목 키·위치(창 + 내부 스크롤 영역) 기억, 이동 종류 표시, `returnScroll` 덮어쓰기, 복원 실행기
 * - Router: 이동 종류를 표시한다(새 이동 `push` · 뒤로/앞으로 `pop` · 첫 진입 `initial`/`reload`)
 * - TemplateApp: 레이아웃을 받은 뒤 설정(템플릿 config → 레이아웃 meta → 이동 액션)을 합쳐 첫 렌더 직후(높이 대기)
 *   또는 데이터를 다 받은 뒤(데이터 기준) {@link runScrollRestore} 를 부른다
 *
 * 항목 식별: Navigation API 의 `navigation.currentEntry.key`(같은 항목의 replaceState 에도 유지), 없으면 주소.
 *
 * @since engine-v1.85.0
 */

import { createLogger } from '../utils/Logger';

const logger = createLogger('ScrollMemory');

/** 돌아왔을 때 동작 — 복원 · 맨 위 · 관여 안 함 · 선택자(그 요소로) */
export type ScrollRestorationMode = 'restore' | 'top' | 'none' | string;

/**
 * mode 값이 쓸 수 있는 값인지 — 정해진 값이거나 `#`·`.`·`[` 로 시작하는 선택자
 *
 * @param mode 값
 * @returns 쓸 수 있으면 true
 */
export function isValidScrollMode(mode: string): boolean {
  return mode === 'restore' || mode === 'top' || mode === 'none' || /^[#.[]/.test(mode);
}

/** 복원 실행 시점 — 높이가 찰 때까지 대기 · 데이터를 다 받은 뒤 */
export type ScrollRestorationWait = 'height' | 'data';

/** 더보기 데이터 스냅샷 설정 */
export interface ScrollRestorationSnapshotConfig {
  enabled: boolean;
  ttl_seconds: number;
  revalidate: boolean;
  item_key: string;
  /**
   * 스냅샷과 함께 기억했다가 되돌릴 상태 경로(`_global.…`·`_local.…`) — 더보기의 다음 쪽 번호처럼 목록 길이와 맞아야
   * 하는 진행 상태. 화면 초기화(init) 뒤 첫 렌더 직전에 되돌린다
   */
  state: string[];
}

/** 스크롤 복원 설정 (템플릿 config.json · 레이아웃 meta 의 `scroll_restoration`) */
export interface ScrollRestorationConfig {
  mode: ScrollRestorationMode;
  on_reload: boolean;
  wait: ScrollRestorationWait;
  timeout_ms: number;
  containers: string[];
  snapshot: ScrollRestorationSnapshotConfig;
}

/** 부분 설정 (덮어쓰기용) */
export type PartialScrollRestorationConfig = Partial<Omit<ScrollRestorationConfig, 'snapshot'>> & {
  snapshot?: Partial<ScrollRestorationSnapshotConfig>;
};

/** 이동 종류 */
export type ScrollNavigationType = 'push' | 'pop' | 'initial' | 'reload';

/** 엔진 기본값 */
export const DEFAULT_SCROLL_RESTORATION: ScrollRestorationConfig = {
  mode: 'restore',
  on_reload: true,
  wait: 'height',
  timeout_ms: 5000,
  containers: [],
  snapshot: { enabled: true, ttl_seconds: 600, revalidate: true, item_key: 'id', state: [] },
};

/** 한 항목에 기억하는 것 */
interface EntryRecord {
  /** 창 세로 위치 */
  y: number;
  /** 내부 스크롤 영역 선택자 → 세로 위치 */
  c?: Record<string, number>;
  /** 이 항목으로 돌아올 때 동작 (navigate `returnScroll`) */
  r?: ScrollRestorationMode;
}

/** 이번 화면 처리에 넘기는 복원 정보 */
export interface ScrollRestoreTicket {
  type: ScrollNavigationType;
  /** 돌아온(들어온) 항목 키 */
  key: string;
  /** 떠나는 화면의 항목 키 (첫 진입이면 null) — 더보기 스냅샷을 그 키로 기억한다 */
  fromKey: string | null;
  record: EntryRecord | undefined;
}

/** sessionStorage 키 */
const STORAGE_KEY = 'g7_scroll_positions';
/** 기억하는 항목 수 상한 (오래된 것부터 버린다) */
const MAX_ENTRIES = 50;
/** 높이 확인 간격(ms) */
const POLL_MS = 50;
/** 목표에 닿은 뒤 높이가 이만큼 그대로면 끝낸다(ms) */
const SETTLE_MS = 600;
/** 사용자가 직접 스크롤을 시작했다고 보는 입력 */
const USER_INPUT_EVENTS = ['wheel', 'touchstart', 'keydown', 'pointerdown'] as const;

const records: Map<string, EntryRecord> = new Map();
let installed = false;
let suspended = false;
let framePending = false;
let pendingType: ScrollNavigationType | null = null;
let committedKey: string | null = null;
let activeContainers: string[] = [];
let cancelActiveRestore: (() => void) | null = null;
/** 진행 중 복원에 「화면 데이터를 다 받았다」 를 알리는 함수 */
let markActiveRestoreDataReady: (() => void) | null = null;
let disabled = false;
/**
 * 뒤로/앞으로 가기·새로고침이 표시된 뒤 복원이 시작되기 전까지 지켜보는 중인지 — 그 사이 사용자가 손으로 스크롤하거나
 * 화면이 스크롤 액션을 실행하면 복원하지 않는다(데이터를 기다리는 동안 이미 다른 곳을 보고 있는 사람을 끌어가지 않게)
 */
let watchingBeforeRestore = false;
/** 복원 시작 전에 사용자·화면이 스크롤을 가져갔는지 */
let tookOverBeforeRestore = false;

/**
 * 지금 히스토리 항목의 키
 *
 * @returns 항목 키
 */
export function currentScrollEntryKey(): string {
  const key = (window as any).navigation?.currentEntry?.key;

  if (typeof key === 'string' && key !== '') {
    return `entry:${key}`;
  }

  return `url:${window.location.pathname}${window.location.search}${window.location.hash}`;
}

/**
 * 설정 조각을 기본값 위에 차례로 덮어쓴다 (뒤가 우선, 적지 않은 키는 앞 값 유지)
 *
 * @param layers 설정 조각들 (undefined·null·객체 아닌 값은 건너뜀)
 * @returns 합친 설정
 */
export function mergeScrollRestorationConfig(...layers: unknown[]): ScrollRestorationConfig {
  const merged: ScrollRestorationConfig = {
    ...DEFAULT_SCROLL_RESTORATION,
    containers: [...DEFAULT_SCROLL_RESTORATION.containers],
    snapshot: { ...DEFAULT_SCROLL_RESTORATION.snapshot, state: [...DEFAULT_SCROLL_RESTORATION.snapshot.state] },
  };

  for (const layer of layers) {
    if (!layer || typeof layer !== 'object' || Array.isArray(layer)) {
      continue;
    }
    const part = layer as Record<string, unknown>;
    if (typeof part.mode === 'string' && part.mode.trim() !== '') {
      const mode = part.mode.trim();
      if (isValidScrollMode(mode)) {
        merged.mode = mode;
      } else {
        // 오타(`Restore`·`auto`)를 선택자로 읽어 5초 동안 찾다 끝나지 않게 — 버리고 앞 층 값을 쓴다
        logger.warn(`scroll_restoration.mode "${mode}" 는 restore·top·none 이나 #·.·[ 로 시작하는 선택자가 아니어서 무시합니다`);
      }
    }
    if (typeof part.on_reload === 'boolean') merged.on_reload = part.on_reload;
    if (part.wait === 'height' || part.wait === 'data') merged.wait = part.wait;
    if (typeof part.timeout_ms === 'number' && Number.isFinite(part.timeout_ms) && part.timeout_ms >= 0) {
      merged.timeout_ms = part.timeout_ms;
    }
    if (Array.isArray(part.containers)) {
      merged.containers = part.containers.filter((s): s is string => typeof s === 'string' && s.trim() !== '').map((s) => s.trim());
    }
    if (part.snapshot && typeof part.snapshot === 'object' && !Array.isArray(part.snapshot)) {
      const snap = part.snapshot as Record<string, unknown>;
      if (typeof snap.enabled === 'boolean') merged.snapshot.enabled = snap.enabled;
      if (typeof snap.ttl_seconds === 'number' && Number.isFinite(snap.ttl_seconds) && snap.ttl_seconds >= 0) {
        merged.snapshot.ttl_seconds = snap.ttl_seconds;
      }
      if (typeof snap.revalidate === 'boolean') merged.snapshot.revalidate = snap.revalidate;
      if (typeof snap.item_key === 'string' && snap.item_key.trim() !== '') merged.snapshot.item_key = snap.item_key.trim();
      if (Array.isArray(snap.state)) {
        merged.snapshot.state = snap.state
          .filter((p): p is string => typeof p === 'string' && /^_(global|local)\.[A-Za-z0-9_$][\w$.]*$/.test(p.trim()))
          .map((p) => p.trim());
      }
    }
  }

  return merged;
}

/**
 * 브라우저 기본 복원을 끄고 저장 리스너를 단다 (Router 생성 시 1회). 새로고침 전 기억을 읽어 온다.
 */
export function installScrollMemory(): void {
  if (installed || typeof window === 'undefined') {
    return;
  }
  installed = true;

  try {
    if ('scrollRestoration' in window.history) {
      window.history.scrollRestoration = 'manual';
    }
  } catch {
    // 쓰기를 막는 환경 — 기본 복원과 함께 동작한다
  }

  load();
  committedKey = currentScrollEntryKey();
  pendingType = initialNavigationType();
  if (pendingType === 'reload') {
    suspended = true;
    watchBeforeRestore();
  }
  window.addEventListener('scroll', handleScroll, { passive: true });
  window.addEventListener('pagehide', persist);
}

/**
 * 리스너를 떼고 기억을 비운다 (시험용)
 */
export function resetScrollMemoryForTesting(): void {
  if (installed && typeof window !== 'undefined') {
    window.removeEventListener('scroll', handleScroll);
    window.removeEventListener('pagehide', persist);
    try {
      window.history.scrollRestoration = 'auto';
    } catch {
      // 무시
    }
  }
  stopActiveRestore();
  stopWatchingBeforeRestore();
  records.clear();
  installed = false;
  suspended = false;
  framePending = false;
  pendingType = null;
  committedKey = null;
  activeContainers = [];
  disabled = false;
  pendingReturnScroll = null;
}

/**
 * 스크롤 기억·복원을 켜고 끈다 (레이아웃 편집기 편집 모드는 끈다)
 *
 * @param value true 면 끔
 */
export function setScrollMemoryDisabled(value: boolean): void {
  disabled = value;
  if (value) {
    stopActiveRestore();
    stopWatchingBeforeRestore();
  }
  // 끈 동안은 브라우저 기본 복원으로 되돌린다(편집기 안의 뒤로 가기는 종전과 같게)
  if (installed) {
    try {
      window.history.scrollRestoration = value ? 'auto' : 'manual';
    } catch {
      // 쓰기를 막는 환경
    }
  }
}

/**
 * 지금 화면이 함께 기억할 내부 스크롤 영역 선택자 (TemplateApp 이 화면 설정을 합친 뒤 알린다)
 *
 * @param selectors 선택자 목록
 */
export function setActiveScrollContainers(selectors: string[]): void {
  activeContainers = [...selectors];
}

/**
 * 지금 항목을 떠나기 직전 — 창·내부 영역 위치를 기억하고, 돌아올 때 동작(`returnScroll`)을 붙인다
 *
 * 새 이동(navigate)은 주소를 바꾸기 전이라 지금 항목에, popstate 는 주소가 이미 바뀐 뒤라 확정 항목(떠나는 화면)에 쓴다.
 * `returnScroll` 은 그 이동 한 번만의 것이라 새 이동은 늘 덮어쓴다(없으면 지움) — 남겨 두면 다음에 일반 링크로 떠났다
 * 돌아와도 맨 위로 간다. 뒤로/앞으로 가기는 이동 액션이 아니므로 종전 값을 둔다.
 *
 * @param returnScroll navigate 액션의 `returnScroll`
 * @param afterTraversal popstate 처리 중이면 true
 */
export function captureBeforeLeave(returnScroll?: unknown, afterTraversal = false): void {
  if (!installed || disabled) {
    return;
  }
  const key = afterTraversal ? (committedKey ?? currentScrollEntryKey()) : currentScrollEntryKey();
  const record = { ...(records.get(key) ?? { y: 0 }) };
  if (!suspended) {
    record.y = Math.max(0, Math.round(window.scrollY));
    const containers = readContainers(activeContainers);
    if (containers) {
      record.c = containers;
    }
  }
  if (typeof returnScroll === 'string' && returnScroll.trim() !== '') {
    record.r = returnScroll.trim();
  } else if (!afterTraversal) {
    delete record.r;
  }
  remember(key, record);
}

let pendingReturnScroll: string | null = null;

/**
 * navigate 액션이 이동을 부르는 동안만 `returnScroll` 을 맡겨 둔다 — Router 가 이탈 가드를 통과한 뒤
 * {@link consumePendingReturnScroll} 로 가져간다(머무르기를 고르면 붙지 않는다). 문맥의 navigate 래퍼가 옵션을
 * 버려도 닿도록 동기 범위로 감싼다(이탈 가드 우회와 같은 방식).
 *
 * @param returnScroll 돌아올 때 동작
 * @param fn 이동을 부르는 함수
 * @returns fn 의 반환값
 */
export function runWithReturnScroll<T>(returnScroll: unknown, fn: () => T): T {
  const previous = pendingReturnScroll;
  pendingReturnScroll = typeof returnScroll === 'string' && returnScroll.trim() !== '' ? returnScroll.trim() : null;
  try {
    return fn();
  } finally {
    pendingReturnScroll = previous;
  }
}

/**
 * 맡겨 둔 `returnScroll` 을 가져간다
 *
 * @returns 동작, 없으면 undefined
 */
export function consumePendingReturnScroll(): string | undefined {
  const value = pendingReturnScroll;
  pendingReturnScroll = null;

  return value ?? undefined;
}

/**
 * Router 가 이동 종류를 알린다 — `push` 는 진행 중 복원을 멈추고 저장을 다시 켠다,
 * `pop` 은 저장을 멈춘다(이전 화면이 남은 채 생기는 스크롤이 돌아갈 항목의 기억을 덮지 않게)
 *
 * @param type 이동 종류
 */
export function markScrollNavigation(type: ScrollNavigationType): void {
  if (!installed) {
    return;
  }
  stopActiveRestore();
  pendingType = type;
  suspended = type === 'pop' || type === 'reload';
  if (suspended) {
    watchBeforeRestore();
  } else {
    stopWatchingBeforeRestore();
  }
}

/**
 * 화면이 스크롤 액션(scrollIntoView·이동의 scroll 옵션)을 실행한다고 알린다 — 진행 중 복원을 멈추고, 아직 시작 전이면
 * 이번 복원을 하지 않는다(화면이 옮긴 위치를 복원이 덮지 않게).
 */
export function notifyScreenScroll(): void {
  if (!installed) {
    return;
  }
  stopActiveRestore();
  if (watchingBeforeRestore) {
    tookOverBeforeRestore = true;
  }
}

/**
 * 지금 항목을 확정 항목으로 삼는다 — 화면을 다시 그리지 않고 항목만 바뀐 경우(이탈 가드가 막은 뒤로 가기)
 */
export function syncCommittedScrollEntry(): void {
  if (!installed) {
    return;
  }
  committedKey = currentScrollEntryKey();
  suspended = false;
}

/**
 * 이번 화면 처리에 쓸 복원 정보를 가져간다 (한 번만 — 다음 화면 처리에는 남지 않는다)
 *
 * @returns 복원 정보, 엔진이 꺼져 있거나 표시된 이동이 없으면 null
 */
export function takeScrollRestoreTicket(): ScrollRestoreTicket | null {
  if (!installed) {
    return null;
  }
  const type = pendingType;
  pendingType = null;
  if (type === null) {
    return null;
  }
  const key = currentScrollEntryKey();
  const fromKey = type === 'initial' || type === 'reload' ? null : committedKey;
  committedKey = key;

  return { type, key, fromKey, record: records.get(key) };
}

/**
 * 복원 없이 저장을 다시 켠다 (복원하지 않기로 정한 화면)
 */
export function resumeScrollSaving(): void {
  suspended = false;
  stopWatchingBeforeRestore();
}

/**
 * 표를 정한 대로 스크롤을 맞춘다 — 창은 문서가 목표를 담을 만큼 자라면 맞추고, 높이가 바뀌면 다시 맞추고,
 * 높이가 멈추거나 시간이 다 되거나 사용자가 손을 대면 끝낸다. 내부 영역도 같은 규칙.
 *
 * @param ticket 복원 정보
 * @param config 합친 설정
 * @returns 끝나면 풀리는 Promise
 */
export function runScrollRestore(ticket: ScrollRestoreTicket, config: ScrollRestorationConfig): Promise<void> {
  stopActiveRestore();
  if (ticket.type === 'push' || ticket.type === 'initial') {
    // 복원 대상이 아니다 — 뒤이어 표시된 뒤로 가기의 지켜보기는 건드리지 않는다
    suspended = false;

    return Promise.resolve();
  }
  const tookOver = tookOverBeforeRestore;
  stopWatchingBeforeRestore();

  const mode = resolveMode(ticket, config);
  if (disabled || mode === 'none') {
    suspended = false;

    return Promise.resolve();
  }
  // 기다리는 사이 사용자·화면이 스크롤을 가져갔다 — 복원하지 않고 지금 위치를 이 항목의 기억으로 남긴다
  if (tookOver) {
    suspended = false;
    const record = { ...(records.get(ticket.key) ?? { y: 0 }) };
    record.y = Math.max(0, Math.round(window.scrollY));
    remember(ticket.key, record);

    return Promise.resolve();
  }

  const targetY = mode === 'restore' ? (ticket.record?.y ?? 0) : 0;
  const containerTargets = mode === 'restore' ? (ticket.record?.c ?? {}) : {};
  const selectorTarget = mode !== 'restore' && mode !== 'top' ? mode : null;

  return new Promise<void>((resolve) => {
    const startedAt = Date.now();
    let reachedAt: number | null = null;
    let lastHeight = -1;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let done = false;
    // 데이터 기준(wait:"data")은 데이터를 다 받은 뒤 시작하므로 처음부터 준비됨
    let dataReady = config.wait === 'data';
    markActiveRestoreDataReady = () => {
      dataReady = true;
      reachedAt = null; // 데이터가 온 뒤부터 안정 시간을 다시 잰다
    };

    const finish = (): void => {
      if (done) return;
      done = true;
      if (timer !== null) {
        clearTimeout(timer);
        timer = null;
      }
      USER_INPUT_EVENTS.forEach((type) => window.removeEventListener(type, onUserInput, true));
      if (cancelActiveRestore === finish) {
        cancelActiveRestore = null;
      }
      markActiveRestoreDataReady = null;
      suspended = false;
      if (!disabled) {
        const record = { ...(records.get(ticket.key) ?? { y: 0 }) };
        record.y = Math.max(0, Math.round(window.scrollY));
        remember(ticket.key, record);
      }
      resolve();
    };

    const onUserInput = (): void => finish();

    const step = (): void => {
      timer = null;
      const now = Date.now();
      let settled = true;

      if (selectorTarget !== null) {
        const el = safeQuery(selectorTarget);
        if (el) {
          el.scrollIntoView({ behavior: 'instant' as ScrollBehavior, block: 'start' });
          finish();

          return;
        }
        settled = false;
      } else {
        const height = document.documentElement.scrollHeight;
        const reachable = Math.min(targetY, maxScrollTop());
        if (height !== lastHeight || Math.round(window.scrollY) !== Math.round(reachable)) {
          lastHeight = height;
          window.scrollTo({ top: reachable, left: 0, behavior: 'instant' as ScrollBehavior });
          // 맞출 때마다(높이가 바뀌었거나 앵커링 등으로 밀렸을 때) 안정 시간을 다시 잰다
          if (reachable >= targetY) {
            reachedAt = now;
          } else {
            reachedAt = null;
          }
        }
        if (!dataReady || reachedAt === null || now - reachedAt < SETTLE_MS) {
          settled = false;
        }
        if (dataReady && reachedAt === null && Math.round(window.scrollY) === Math.round(reachable) && reachable >= targetY) {
          reachedAt = now;
        }

        for (const [selector, top] of Object.entries(containerTargets)) {
          const el = safeQuery(selector);
          if (!el) {
            settled = false;
            continue;
          }
          const maxTop = Math.max(0, el.scrollHeight - el.clientHeight);
          const reach = Math.min(top, maxTop);
          if (Math.round(el.scrollTop) !== Math.round(reach)) {
            el.scrollTop = reach;
          }
          if (reach < top) {
            settled = false;
          }
        }
      }

      if (settled || now - startedAt >= config.timeout_ms) {
        finish();

        return;
      }
      timer = setTimeout(step, POLL_MS);
    };

    USER_INPUT_EVENTS.forEach((type) => window.addEventListener(type, onUserInput, { capture: true, passive: true }));
    cancelActiveRestore = finish;
    step();
  });
}

/**
 * 화면 처리가 이 화면의 데이터를 다 받았다고 알린다 — 높이 대기 복원은 이 신호 전에는 끝내지 않는다.
 *
 * 돌아온 직후 그려지는 화면(받기 전 뼈대·앞 화면 값)이 이미 목표 위치를 담을 만큼 길면, 그 위치에 맞추고 높이가 잠시
 * 그대로라는 이유로 끝나 버린다. 그 뒤 데이터가 와서 위쪽 내용이 바뀌면 브라우저 스크롤 앵커링이 위치를 옮겨 엉뚱한 곳에
 * 남는다(실측: 모바일 홈 2,500 → 뒤로 → 4,501). 그래서 데이터가 다 온 뒤 높이가 안정돼야 끝낸다.
 */
export function notifyScrollRestoreDataReady(): void {
  markActiveRestoreDataReady?.();
}

/**
 * 기억한 것 (시험·진단용)
 *
 * @param key 항목 키 (생략 시 지금 항목)
 * @returns 기억, 없으면 undefined
 */
export function getScrollRecord(key: string = currentScrollEntryKey()): Readonly<EntryRecord> | undefined {
  return records.get(key);
}

/**
 * 돌아왔을 때 동작을 정한다 — 이동 액션의 `returnScroll` 이 화면 설정보다 앞선다. 새로고침에서 `on_reload` 가 꺼져 있으면 맨 위.
 *
 * @param ticket 복원 정보
 * @param config 합친 설정
 * @returns 동작
 */
function resolveMode(ticket: ScrollRestoreTicket, config: ScrollRestorationConfig): ScrollRestorationMode {
  const mode = ticket.record?.r ?? config.mode;
  if (ticket.type === 'reload' && !config.on_reload) {
    return 'top';
  }

  return mode;
}

/**
 * 첫 진입 종류 — 새로고침·문서 단위 뒤로/앞으로면 기억으로 되돌린다
 *
 * @returns 이동 종류
 */
function initialNavigationType(): ScrollNavigationType {
  try {
    const entry = performance.getEntriesByType?.('navigation')?.[0] as PerformanceNavigationTiming | undefined;
    if (entry && (entry.type === 'reload' || entry.type === 'back_forward')) {
      return 'reload';
    }
  } catch {
    // 지원하지 않는 환경
  }

  return 'initial';
}

/** 복원 시작 전 사용자 입력 감지 */
function onInputBeforeRestore(): void {
  tookOverBeforeRestore = true;
}

/** 뒤로/앞으로 가기·새로고침 표시 순간부터 복원 시작 전까지 사용자 입력을 지켜본다 */
function watchBeforeRestore(): void {
  stopWatchingBeforeRestore();
  watchingBeforeRestore = true;
  USER_INPUT_EVENTS.forEach((type) => window.addEventListener(type, onInputBeforeRestore, { capture: true, passive: true }));
}

/** 복원 시작 전 지켜보기를 끝낸다 */
function stopWatchingBeforeRestore(): void {
  if (watchingBeforeRestore) {
    USER_INPUT_EVENTS.forEach((type) => window.removeEventListener(type, onInputBeforeRestore, true));
  }
  watchingBeforeRestore = false;
  tookOverBeforeRestore = false;
}

/** 진행 중 복원을 멈춘다 */
function stopActiveRestore(): void {
  if (cancelActiveRestore !== null) {
    const cancel = cancelActiveRestore;
    cancelActiveRestore = null;
    cancel();
  }
}

/** 문서의 최대 세로 스크롤 위치 */
function maxScrollTop(): number {
  return Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
}

/** 잘못된 선택자도 예외 없이 */
function safeQuery(selector: string): HTMLElement | null {
  try {
    return document.querySelector<HTMLElement>(selector);
  } catch {
    return null;
  }
}

/** 내부 영역 위치 읽기 */
function readContainers(selectors: string[]): Record<string, number> | undefined {
  if (selectors.length === 0) {
    return undefined;
  }
  const out: Record<string, number> = {};
  for (const selector of selectors) {
    const el = safeQuery(selector);
    if (el) {
      out[selector] = Math.max(0, Math.round(el.scrollTop));
    }
  }

  return Object.keys(out).length > 0 ? out : undefined;
}

/** scroll 이벤트 — 프레임당 한 번 지금 항목의 창 위치를 기억한다 */
function handleScroll(): void {
  if (suspended || framePending || disabled) {
    return;
  }
  framePending = true;
  const schedule = typeof window.requestAnimationFrame === 'function'
    ? window.requestAnimationFrame.bind(window)
    : (cb: FrameRequestCallback) => setTimeout(() => cb(Date.now()), 16);

  schedule(() => {
    framePending = false;
    if (suspended || disabled) {
      return;
    }
    const key = currentScrollEntryKey();
    const record = { ...(records.get(key) ?? { y: 0 }) };
    record.y = Math.max(0, Math.round(window.scrollY));
    remember(key, record);
  });
}

/** 기억 (상한을 넘으면 가장 오래된 것부터 버린다) */
function remember(key: string, record: EntryRecord): void {
  records.delete(key);
  records.set(key, record);
  while (records.size > MAX_ENTRIES) {
    const oldest = records.keys().next().value;
    if (oldest === undefined) break;
    records.delete(oldest);
  }
}

/** pagehide — 지금 위치까지 담아 sessionStorage 에 남긴다 */
function persist(): void {
  captureBeforeLeave();
  try {
    window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(records.entries())));
  } catch {
    // 저장소를 못 쓰는 환경 — 메모리 기억만
  }
}

/** sessionStorage 에서 읽어 온다 */
function load(): void {
  try {
    const raw = window.sessionStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    const entries = JSON.parse(raw);
    if (!Array.isArray(entries)) return;
    for (const entry of entries) {
      if (!Array.isArray(entry) || typeof entry[0] !== 'string' || !entry[1] || typeof entry[1] !== 'object') continue;
      const value = entry[1] as Record<string, unknown>;
      if (typeof value.y !== 'number' || !Number.isFinite(value.y)) continue;
      const record: EntryRecord = { y: value.y };
      if (value.c && typeof value.c === 'object') {
        const c: Record<string, number> = {};
        for (const [sel, top] of Object.entries(value.c as Record<string, unknown>)) {
          if (typeof top === 'number' && Number.isFinite(top)) c[sel] = top;
        }
        if (Object.keys(c).length > 0) record.c = c;
      }
      if (typeof value.r === 'string') record.r = value.r;
      remember(entry[0], record);
    }
  } catch {
    // 깨진 값은 버린다
  }
}
