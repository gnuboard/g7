/**
 * 디버그 모드 중복 DOM id 경고 (engine-v1.99.0, #408)
 *
 * 한 화면에 같은 DOM id 가 둘 이상이면 `getElementById`·`<label for>`·`aria-*` 연결과 확장 주입(target_id)이
 * 첫 요소에만 걸린다. 정적 감사와 전 화면 크롤 E2E 가 모달·시트 열림 같은 상호작용 상태를 다 보지 못하므로,
 * 디버그 모드(`G7Config.debug`)에서 개발자가 화면을 쓰는 동안 중복을 콘솔로 알린다(결정 D5).
 *
 * - 앱 루트 아래 DOM 변화(childList·subtree·id 속성)를 지켜보고, 변화가 멈춘 뒤 1000ms 에 중복을 센다.
 * - 같은 (pathname, id) 쌍은 한 번만 경고한다. pathname 이 바뀌면 기록을 비운다.
 * - 운영(디버그 꺼짐)에서는 호출 자체를 하지 않는다 — template-engine.ts 초기화가 debugMode 일 때만 부른다.
 *
 * @since engine-v1.99.0
 */

/** 변화가 멈춘 뒤 검사까지 기다리는 시간(ms) */
export const DUPLICATE_ID_SETTLE_MS = 1000;

/** 경고 문구 */
export const DUPLICATE_ID_WARNING = '[G7] 같은 DOM id 가 화면에 여러 개 있습니다';

/**
 * root 아래의 중복 id 를 센다.
 *
 * @param root 검사할 루트 요소
 * @returns id 별 개수(2 이상만)
 */
export function collectDuplicateIds(root: ParentNode): Array<{ id: string; count: number }> {
  const counts = new Map<string, number>();
  root.querySelectorAll('[id]').forEach((el) => {
    const id = (el as Element).id;
    if (id) counts.set(id, (counts.get(id) ?? 0) + 1);
  });
  const result: Array<{ id: string; count: number }> = [];
  counts.forEach((count, id) => {
    if (count > 1) result.push({ id, count });
  });
  return result;
}

/**
 * 중복 id 감시를 시작한다.
 *
 * @param root 감시할 앱 루트 요소
 * @returns 감시를 멈추는 함수
 */
export function startDuplicateIdMonitor(root: HTMLElement): () => void {
  if (typeof MutationObserver === 'undefined' || !root) {
    return () => {};
  }

  // base-path:allow 화면이 바뀌었는지 가르는 키이자 경고에 싣는 값이라(라우트 대조가 아니다) 브라우저 주소를 그대로 쓴다.
  let reportedPath = typeof location !== 'undefined' ? location.pathname : '';
  const reported = new Set<string>();
  let timer: ReturnType<typeof setTimeout> | null = null;
  let idleHandle: number | null = null;
  let stopped = false;

  const check = (): void => {
    if (stopped) return;
    // base-path:allow 위 reportedPath 와 같은 키로 비교하고 경고에 그대로 싣는다(라우트 대조가 아니다).
    const pathname = typeof location !== 'undefined' ? location.pathname : '';
    if (pathname !== reportedPath) {
      reported.clear();
      reportedPath = pathname;
    }
    for (const { id, count } of collectDuplicateIds(root)) {
      const key = `${pathname}\u0000${id}`;
      if (reported.has(key)) continue;
      reported.add(key);
      // eslint-disable-next-line no-console
      console.warn(DUPLICATE_ID_WARNING, { id, count, pathname });
    }
  };

  const cancelPending = (): void => {
    if (timer !== null) {
      clearTimeout(timer);
      timer = null;
    }
    if (idleHandle !== null && typeof cancelIdleCallback === 'function') {
      cancelIdleCallback(idleHandle);
      idleHandle = null;
    }
  };

  const schedule = (): void => {
    cancelPending();
    timer = setTimeout(() => {
      timer = null;
      if (typeof requestIdleCallback === 'function') {
        idleHandle = requestIdleCallback(() => {
          idleHandle = null;
          check();
        });
      } else {
        check();
      }
    }, DUPLICATE_ID_SETTLE_MS);
  };

  const observer = new MutationObserver(schedule);
  observer.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['id'] });
  schedule();

  return () => {
    stopped = true;
    cancelPending();
    observer.disconnect();
  };
}
