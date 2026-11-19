import { useEffect, useRef } from 'react';

/**
 * 서버가 알려 준 다음 기간 경계와 탭 복귀에서만 공개 소스를 다시 받는다.
 * 0건도 구독하며, 관리자 미리보기는 제외한다. 숨긴 탭의 타이머·해제된 화면의 리스너는 남기지 않는다.
 */
export function usePopupRefresh(refreshAt?: string | null, serverTime?: string | null, enabled = true): void {
  const inFlight = useRef(false);
  const lastAttempt = useRef(-Infinity);

  useEffect(() => {
    if (!enabled) return;
    let disposed = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const server = Date.parse(serverTime ?? '');
    const boundary = Date.parse(refreshAt ?? '');
    // 방문자 시계가 서버와 달라도 응답 안의 상대 시간으로 잰다.
    const due = Date.now() + (boundary - server);
    const clear = () => { if (timer !== undefined) clearTimeout(timer); timer = undefined; };
    const refresh = async () => {
      if (disposed || document.visibilityState === 'hidden' || inFlight.current || Date.now() - lastAttempt.current < 1000) return;
      const refetch = (window as any).G7Core?.dataSource?.refetch;
      if (!refetch) return;
      inFlight.current = true;
      lastAttempt.current = Date.now();
      try {
        await refetch('sirsoftPopup_public', { skipCache: true });
      } catch {
        // 일시 실패는 빠르게 반복하지 않는다. 다음 탭 복귀·SPA 조회에서 다시 받는다.
      } finally {
        inFlight.current = false;
      }
    };
    const schedule = () => {
      clear();
      if (!Number.isFinite(due) || document.visibilityState === 'hidden') return;
      const remaining = due - Date.now() + 100;
      // 브라우저 타이머 상한보다 먼 예약은 상한 뒤 남은 시간만 다시 예약한다.
      timer = setTimeout(() => {
        if (due > Date.now()) schedule(); else void refresh();
      }, Math.max(100, Math.min(remaining, 2147483647)));
    };
    const onVisible = () => {
      if (document.visibilityState === 'hidden') clear();
      else { schedule(); void refresh(); }
    };
    const onPageShow = (event: PageTransitionEvent) => { if (event.persisted) onVisible(); };
    schedule();
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('pageshow', onPageShow);
    return () => {
      disposed = true;
      clear();
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('pageshow', onPageShow);
    };
  }, [refreshAt, serverTime, enabled]);
}
