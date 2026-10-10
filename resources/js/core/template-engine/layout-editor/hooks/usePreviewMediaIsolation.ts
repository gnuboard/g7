/**
 * usePreviewMediaIsolation.ts — 편집기 미리보기 CSS 매체 격리 (이슈 #141 D7)
 *
 * 미리보기는 iframe 이 아니라 관리자 문서 안의 div(`.g7le-preview-frame`, 폭 = 기기 폭)다. CSS 의
 * 폭 `@media` 와 `vw` 는 브라우저가 언제나 **창 폭**으로 판정하므로, 창 1440 에서 모바일 미리보기(390)를
 * 골라도 `md:grid-cols-2` 같은 규칙이 켜져 있었다. div 폭으로 판정할 수 있는 것은 컨테이너 쿼리뿐이다.
 *
 * 편집기가 떠 있는 동안:
 *  1. 프레임을 이름 있는 컨테이너(`container: g7le-preview / inline-size`)로 지정한다.
 *  2. 문서의 모든 읽을 수 있는 스타일시트에서 폭 `@media` 규칙을 원본(프레임 밖 전용 가드) +
 *     `@container g7le-preview` 복제본으로 나누고, `vw` 규칙은 프레임 폭 변수 복제본을 만든다(sheetRewriter).
 *  3. 새 `<style>`·`<link>`·시트 규칙 추가(CSS-in-JS)를 감시해 다시 처리한다.
 * 언마운트 때 모두 원문으로 되돌리고 컨테이너 지정도 지운다 — 편집기 밖 화면에는 흔적이 남지 않는다.
 *
 * 선택자 이름이 아니라 조건식만 보므로 일반 CSS·Bootstrap·Tailwind v3/v4·CSS-in-JS 모두 같은 방식으로
 * 동작한다. 컨테이너 쿼리를 지원하지 않는 환경에서는 아무것도 하지 않는다.
 *
 * @since engine-v1.75.2
 */

import { useCallback, useEffect, useRef } from 'react';
import { createLogger } from '../../../utils/Logger';
import {
  createRewriteState,
  restoreAll,
  rewriteSheets,
  type RewriteState,
} from '../utils/previewMediaIsolation/sheetRewriter';
import {
  MEDIA_ISOLATION_OPT_OUT_ATTR,
  MEDIA_ISOLATION_STYLE_ATTR,
  PREVIEW_CONTAINER_NAME,
  PREVIEW_FRAME_CLASS,
} from '../utils/previewMediaIsolation/constants';

const logger = createLogger('usePreviewMediaIsolation');

/** 계측값 — `window.__g7lePreviewMediaIsolation` 로도 노출한다 */
export interface PreviewMediaIsolationStats {
  /** 마지막 실행에서 훑은 시트 수 */
  sheets: number;
  /** 누적 변환 블록 수 */
  converted: number;
  /** 누적 변환 불가(원문 유지) 수 */
  unsupported: number;
  /** 누적 건너뛴 시트 수(교차 출처·옵트아웃) — 시트마다 한 번만 센다 */
  skipped: number;
  /** 첫 실행(문서의 기존 시트 전체) 소요 시간(ms) */
  ms: number;
  /** 지금까지 가장 오래 걸린 실행 시간(ms) */
  maxMs: number;
  /** 실행 횟수 */
  runs: number;
}

/** 현재 살아 있는 인스턴스의 재처리 함수 — PreviewCanvas 가 자산 준비·기기 폭 변경 때 부른다 */
let activeRescan: (() => void) | null = null;

/**
 * 살아 있는 격리 인스턴스에 재처리를 요청한다. 편집기가 없으면 아무것도 하지 않는다.
 */
export function rescanPreviewMediaIsolation(): void {
  activeRescan?.();
}

/**
 * 컨테이너 쿼리 지원 여부.
 *
 * @returns 지원하면 true
 */
function supportsContainerQueries(): boolean {
  try {
    return typeof CSS !== 'undefined' && typeof CSS.supports === 'function' && CSS.supports('container-type: inline-size');
  } catch {
    return false;
  }
}

/**
 * `@media` 의 rem/em 기준(브라우저 기본 글꼴 크기)을 px 로 잰다.
 *
 * @returns px (측정 불가면 16)
 */
function measureMediaRemPx(): number {
  try {
    const probe = document.createElement('div');
    probe.setAttribute('aria-hidden', 'true');
    probe.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none;font-size:medium;width:0;height:0;';
    document.documentElement.appendChild(probe);
    const px = parseFloat(getComputedStyle(probe).fontSize);
    probe.remove();
    return Number.isFinite(px) && px > 0 ? px : 16;
  } catch {
    return 16;
  }
}

/**
 * 문서의 대상 시트 목록 — `document.styleSheets` + `adoptedStyleSheets`.
 *
 * @returns 시트 배열
 */
function collectSheets(): any[] {
  const sheets: any[] = Array.from(document.styleSheets ?? []);
  const adopted = (document as any).adoptedStyleSheets;
  if (Array.isArray(adopted)) sheets.push(...adopted);
  return sheets;
}

/**
 * 편집기 생존 동안 미리보기 매체 격리를 적용한다.
 *
 * 테스트/SSR 안전: `document` 부재·컨테이너 쿼리 미지원이면 no-op.
 *
 * @returns `rescan` — 시트를 다시 훑는다(이미 처리한 규칙은 건너뛴다)
 */
export function usePreviewMediaIsolation(): { rescan: () => void } {
  const rescanRef = useRef<() => void>(() => {});

  useEffect(() => {
    if (typeof document === 'undefined' || !supportsContainerQueries()) {
      return;
    }

    const state: RewriteState = createRewriteState();
    const totals: PreviewMediaIsolationStats = { sheets: 0, converted: 0, unsupported: 0, skipped: 0, ms: 0, maxMs: 0, runs: 0 };
    const remPx = measureMediaRemPx();
    let loggedCrossOrigin = false;

    // 1. 프레임을 이름 있는 컨테이너로 지정 — 격리가 켜진 동안에만 존재한다
    const containerStyle = document.createElement('style');
    containerStyle.setAttribute(MEDIA_ISOLATION_STYLE_ATTR, '');
    containerStyle.setAttribute(MEDIA_ISOLATION_OPT_OUT_ATTR, '');
    containerStyle.textContent = `.${PREVIEW_FRAME_CLASS}{container:${PREVIEW_CONTAINER_NAME} / inline-size}`;
    document.head.appendChild(containerStyle);

    const run = (): void => {
      const started = typeof performance !== 'undefined' ? performance.now() : Date.now();
      const stats = rewriteSheets(collectSheets(), state, {
        remPx,
        onSkip: (reason) => {
          if (!loggedCrossOrigin) {
            loggedCrossOrigin = true;
            logger.log(`[g7le] 미리보기 매체 격리: 읽을 수 없는 시트를 건너뜀 (${reason})`);
          }
        },
      });
      const ended = typeof performance !== 'undefined' ? performance.now() : Date.now();

      totals.sheets = stats.sheets;
      totals.converted += stats.converted;
      totals.unsupported += stats.unsupported;
      totals.skipped += stats.skipped;
      const elapsed = Math.round((ended - started) * 100) / 100;
      if (totals.runs === 0) totals.ms = elapsed;
      totals.maxMs = Math.max(totals.maxMs, elapsed);
      totals.runs += 1;

      (window as any).__g7lePreviewMediaIsolation = { ...totals };
      if (stats.converted > 0 || stats.unsupported > 0) {
        logger.log('[g7le] 미리보기 매체 격리', { ...totals });
      }
    };

    run();

    // 2. 감시 — head 의 새 <style>·<link>·본문 교체는 콜백 안에서 동기로 처리해 깜빡임을 막는다
    const watchedLinks = new WeakSet<Node>();
    const watchLink = (node: Node): void => {
      if (node instanceof HTMLLinkElement && node.rel === 'stylesheet' && !watchedLinks.has(node)) {
        watchedLinks.add(node);
        node.addEventListener('load', run);
      }
    };
    document.head.querySelectorAll('link[rel="stylesheet"]').forEach(watchLink);

    let headObserver: MutationObserver | null = null;
    let bodyObserver: MutationObserver | null = null;
    let frameScheduled = false;

    if (typeof MutationObserver !== 'undefined') {
      headObserver = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
          mutation.addedNodes.forEach(watchLink);
        }
        run();
      });
      headObserver.observe(document.head, { childList: true, subtree: true, characterData: true });

      // CSS-in-JS 의 insertRule 은 DOM 변경이 없다 — 본문이 바뀔 때 시트 규칙 수가 늘었는지 다음 프레임에 확인한다
      bodyObserver = new MutationObserver(() => {
        if (frameScheduled) return;
        frameScheduled = true;
        const schedule = typeof requestAnimationFrame === 'function' ? requestAnimationFrame : (cb: () => void) => setTimeout(cb, 16);
        schedule(() => {
          frameScheduled = false;
          run();
        });
      });
      if (document.body) bodyObserver.observe(document.body, { childList: true, subtree: true });
    }

    rescanRef.current = run;
    activeRescan = run;

    return () => {
      headObserver?.disconnect();
      bodyObserver?.disconnect();
      document.head.querySelectorAll('link[rel="stylesheet"]').forEach((node) => node.removeEventListener('load', run));
      restoreAll(state);
      containerStyle.remove();
      if (activeRescan === run) activeRescan = null;
      rescanRef.current = () => {};
      delete (window as any).__g7lePreviewMediaIsolation;
    };
  }, []);

  const rescan = useCallback(() => rescanRef.current(), []);

  return { rescan };
}
