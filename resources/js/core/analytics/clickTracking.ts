/**
 * 내려받기·외부 링크 클릭 추적
 *
 * 문서 전체에 클릭 리스너 하나를 걸어 링크를 가려 냅니다. 링크마다 리스너를 다는
 * 방식은 SPA 에서 화면이 바뀔 때마다 다시 달아야 하고, 떼는 것을 잊으면 리스너가
 * 쌓입니다.
 *
 * 캡처 단계에서 듣는 이유: 링크를 가로채는 다른 코드가 전파를 멈춰도 클릭 자체는
 * 보이기 때문입니다.
 */

import type { AnalyticsTracker } from './Tracker';

/** 같은 링크의 중복 클릭을 무시하는 시간 (ms) */
const DEDUPE_WINDOW_MS = 1000;

/**
 * 링크 클릭 추적을 시작합니다.
 *
 * @param tracker 트래커
 * @param downloadExtensions 내려받기로 볼 확장자 목록
 * @returns 추적을 멈추는 함수
 */
export function startClickTracking(
  tracker: AnalyticsTracker,
  downloadExtensions: string[] = [],
): () => void {
  if (typeof document === 'undefined') return () => undefined;

  const recent = new Map<string, number>();

  const handler = (event: MouseEvent): void => {
    try {
      const target = event.target as Element | null;
      const anchor = target?.closest?.('a[href]') as HTMLAnchorElement | null;

      if (!anchor) return;

      const href = anchor.getAttribute('href');
      if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;

      const url = new URL(href, window.location.href);

      // 같은 링크를 연달아 누르는 것(더블클릭·재시도)은 한 번으로 센다.
      const now = Date.now();
      const previous = recent.get(url.href);
      if (previous !== undefined && now - previous < DEDUPE_WINDOW_MS) return;
      recent.set(url.href, now);

      if (isDownload(anchor, url, downloadExtensions)) {
        tracker.trackDownload(url.href, downloadName(anchor, url));

        return;
      }

      if (url.origin !== window.location.origin) {
        tracker.trackOutlink(url.href);
      }
    } catch {
      // 클릭 처리는 사이트 동작이다 — 통계 때문에 막히면 안 된다.
    }
  };

  // 수정키를 눌러 새 탭으로 열든 target=_blank 든 클릭은 일어난 것이므로 그대로 센다.
  document.addEventListener('click', handler, true);

  return () => document.removeEventListener('click', handler, true);
}

/**
 * 이 링크가 파일 내려받기인지 판정합니다.
 *
 * @param anchor 링크 요소
 * @param url 해석된 주소
 * @param extensions 내려받기로 볼 확장자 목록
 * @returns 내려받기 여부
 */
function isDownload(anchor: HTMLAnchorElement, url: URL, extensions: string[]): boolean {
  if (anchor.hasAttribute('download')) return true;

  // 첨부 다운로드 경로는 확장자가 없어도 내려받기다.
  if (url.pathname.startsWith('/api/attachment/')) return true;

  const match = url.pathname.match(/\.([a-z0-9]+)$/i);
  if (!match) return false;

  return extensions.includes(match[1].toLowerCase());
}

/**
 * 내려받기 표시 이름을 정합니다.
 *
 * @param anchor 링크 요소
 * @param url 해석된 주소
 * @returns 표시 이름
 */
function downloadName(anchor: HTMLAnchorElement, url: URL): string {
  const explicit = anchor.getAttribute('download');
  if (explicit) return explicit;

  const text = anchor.textContent?.trim();
  if (text) return text.slice(0, 255);

  return url.pathname.split('/').pop() ?? url.href;
}
