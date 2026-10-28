/**
 * 「보지 않기」 기록 쿠키 — 그때 레이어에 보이던 팝업 id 마다 만료 시각(epoch 초)을 한 쿠키에 담는다(PO 결정 #812 D3).
 *
 * 쿠키 이름은 서버 `Plugin::DISMISS_COOKIE` 와 같아야 한다 — GDPR 필수 저장 계약 훅에 이 이름이 등록돼 있어
 * 쿠키 동의 전에도 써진다. 값이 깨져 있으면 빈 기록으로 보고 다음 쓰기 때 덮는다.
 */

/** 쿠키 이름 */
export const DISMISS_COOKIE = 'g7_popup_dismissed';

/** 한 쿠키에 담는 최대 항목 수 — 넘으면 만료가 이른 것부터 버린다(쿠키 크기 4KB 안). */
export const MAX_ENTRIES = 50;

/**
 * 쿠키 문자열에서 만료 전 기록을 읽습니다.
 *
 * @param cookieString document.cookie
 * @param nowSeconds 지금 (epoch 초)
 * @returns 팝업 id → 만료 epoch 초
 */
export function readDismissed(cookieString: string, nowSeconds: number): Record<string, number> {
  const pair = cookieString.split(';').map((part) => part.trim()).find((part) => part.startsWith(`${DISMISS_COOKIE}=`));
  if (!pair) {
    return {};
  }

  try {
    const parsed = JSON.parse(decodeURIComponent(pair.slice(DISMISS_COOKIE.length + 1)));
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return {};
    }

    const result: Record<string, number> = {};
    Object.entries(parsed as Record<string, unknown>).forEach(([id, until]) => {
      if (/^\d+$/.test(id) && typeof until === 'number' && Number.isFinite(until) && until > nowSeconds) {
        result[id] = until;
      }
    });

    return result;
  } catch {
    return {};
  }
}

/**
 * 「보지 않기」 만료 시각을 계산합니다.
 *
 * @param mode today(방문자 브라우저의 다음 자정) 또는 days(지금 + N×24시간)
 * @param days 보지 않을 일수 (days 일 때)
 * @param now 지금
 * @returns 만료 epoch 초
 */
export function dismissExpiry(mode: 'today' | 'days', days: number, now: Date): number {
  if (mode === 'today') {
    const midnight = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 0, 0, 0, 0);

    return Math.floor(midnight.getTime() / 1000);
  }

  const safeDays = Math.min(30, Math.max(1, Math.floor(days) || 1));

  return Math.floor(now.getTime() / 1000) + safeDays * 86400;
}

/**
 * 기록에 팝업들을 더한 새 쿠키 문자열을 만듭니다 — 만료 지난 항목은 빼고, 많으면 만료가 이른 것부터 버립니다.
 *
 * @param current 지금 기록 (만료 전 항목)
 * @param ids 더할 팝업 id
 * @param until 만료 epoch 초
 * @param nowSeconds 지금 (epoch 초)
 * @param secure HTTPS 면 Secure 를 붙인다
 * @returns document.cookie 에 쓸 문자열
 */
export function buildDismissCookie(
  current: Record<string, number>,
  ids: number[],
  until: number,
  nowSeconds: number,
  secure: boolean,
): string {
  const merged: Record<string, number> = {};
  Object.entries(current).forEach(([id, value]) => {
    if (value > nowSeconds) {
      merged[id] = value;
    }
  });
  ids.forEach((id) => {
    merged[String(id)] = Math.max(merged[String(id)] ?? 0, until);
  });

  const kept = Object.entries(merged)
    .sort((a, b) => b[1] - a[1])
    .slice(0, MAX_ENTRIES);
  const value: Record<string, number> = Object.fromEntries(kept);
  const latest = kept.reduce((max, [, at]) => Math.max(max, at), nowSeconds);

  return [
    `${DISMISS_COOKIE}=${encodeURIComponent(JSON.stringify(value))}`,
    `expires=${new Date(latest * 1000).toUTCString()}`,
    'path=/',
    'SameSite=Lax',
    ...(secure ? ['Secure'] : []),
  ].join('; ');
}

/**
 * 팝업들을 「보지 않기」로 기록합니다.
 *
 * @param ids 그때 레이어에 보이던 팝업 id 전부
 * @param mode today 또는 days
 * @param days 보지 않을 일수
 * @param now 지금
 */
export function rememberDismissed(ids: number[], mode: 'today' | 'days', days: number, now: Date = new Date()): void {
  const nowSeconds = Math.floor(now.getTime() / 1000);
  const current = readDismissed(document.cookie, nowSeconds);
  document.cookie = buildDismissCookie(current, ids, dismissExpiry(mode, days, now), nowSeconds, window.location.protocol === 'https:');
}
