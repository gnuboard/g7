/**
 * inflightCustomTextCommits.ts — 진행 중인 다국어 칸 기록(평문 → `$t:custom.*` 키 생성 → 토큰 기록) 추적
 *
 * 다국어 칸(I18nTextField)에 평문을 적고 빠져나가면 서버에 커스텀 키를 만들고 번역 사전을 다시 읽은 뒤에야 그 키 토큰을
 * 레이아웃 문서에 기록한다(서버 왕복 수 초). 그사이 [저장]을 누르면 토큰이 아직 문서에 없어 저장 본문에서 빠지고, 막 만든
 * 키는 저장 순간 "레이아웃이 참조하지 않는 키"(orphaned)가 된다. 저장은 여기 남은 기록이 모두 끝날 때까지 기다린다.
 *
 * 모듈 전역(편집기 세션 단위) — 한 번에 하나의 편집 세션만 활성이다.
 *
 * @since engine-v1.88.0
 */

const inflight = new Set<Promise<void>>();

/**
 * 다국어 칸 기록 시작을 알린다. 돌려받은 함수를 기록(문서 patch)이 끝난 뒤 반드시 부른다(finally).
 *
 * @return 기록 끝 알림 함수 (여러 번 불러도 한 번만 처리)
 */
export function beginCustomTextCommit(): () => void {
  let resolve: () => void = () => {};
  const promise = new Promise<void>((r) => {
    resolve = r;
  });
  inflight.add(promise);
  let ended = false;
  return () => {
    if (ended) return;
    ended = true;
    inflight.delete(promise);
    resolve();
  };
}

/** 진행 중인 다국어 칸 기록이 있는지 */
export function hasInflightCustomTextCommits(): boolean {
  return inflight.size > 0;
}

/**
 * 진행 중인 다국어 칸 기록이 모두 끝날 때까지 기다린다(기다리는 동안 새로 시작된 기록까지).
 *
 * 응답이 오지 않는 요청 하나 때문에 저장이 영영 멈추지 않도록 상한을 둔다 — 상한이 지나면 기다리지 않고 돌아간다(종전 동작).
 *
 * @param timeoutMs 최대 대기 시간(ms)
 * @return 모두 끝났으면 true, 상한에 걸렸으면 false
 */
export async function waitForInflightCustomTextCommits(timeoutMs = 30000): Promise<boolean> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  const timeout = new Promise<false>((resolve) => {
    timer = setTimeout(() => resolve(false), timeoutMs);
  });
  const drained = (async (): Promise<true> => {
    while (inflight.size > 0) {
      await Promise.all(Array.from(inflight));
    }
    return true;
  })();
  try {
    return await Promise.race([drained, timeout]);
  } finally {
    if (timer !== undefined) clearTimeout(timer);
  }
}
