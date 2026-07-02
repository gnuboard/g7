/**
 * DevTools — 번들 분리 (lazy/debug 로드) 검증. (engine-v1.52.0)
 *
 * 개발자 진단도구(DevTools)는 디버그 모드에서만 쓰이므로 코어 번들에서 분리해 별도
 * devtools.min.js 로 빌드하고, `initDevToolsAPI()` 가 `isEnabled()` 참일 때만 런타임
 * <script> 주입으로 로드한다. 디버그 판정 = `G7Config.debug`(app.debug) 또는 서버 환경설정
 * `settings.advanced.debug_mode` 중 하나라도 참.
 *
 * 검증:
 *  - 디버그 상태에 따라 devtools.min.js 로드 여부가 갈린다:
 *    · 디버그 OFF → 요청 없음 (초기 접속 payload 에서 DevTools 611KB 소스 제거)
 *    · 디버그 ON  → 번들 로드 + 패널(#g7-devtools-root) 마운트. React 사본이 둘이면
 *      패널 마운트가 크래시하므로, 마운트 성공이 곧 shim 공유(G7DevToolsCore) + 단일 React 증명.
 *
 * 라이브 서버의 디버그 상태를 강제 전환하지 않고, 실제 상태를 감지해 해당 불변식만 단언한다.
 *
 * @scenario devtools_bundle_split_lazy
 * @effects devtools_bundle_lazy_by_debug_state
 */
import { test, expect } from '../../fixtures/auth';

type PwPage = import('@playwright/test').Page;

const DEVTOOLS_BUNDLE_RE = /devtools\.min\.js/;

function collectDevToolsRequests(page: PwPage): string[] {
  const hits: string[] = [];
  page.on('request', (req) => {
    if (DEVTOOLS_BUNDLE_RE.test(req.url())) {
      hits.push(req.url());
    }
  });
  return hits;
}

test.describe('DevTools — 번들 분리 (lazy/debug 로드)', () => {
  test('디버그 상태에 따라 devtools.min.js 로드/미로드가 갈린다', async ({ page }) => {
    const devtoolsRequests = collectDevToolsRequests(page);

    await page.goto('/');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 페이지의 실제 디버그 활성 여부 감지
    const debugEnabled = await page.evaluate(() => {
      try {
        return Boolean((window as any).G7DevTools?.isEnabled?.());
      } catch {
        return false;
      }
    });

    if (debugEnabled) {
      // 디버그 ON — lazy 번들 로드 + 패널 마운트(단일 React/shim 공유 증명)
      expect(devtoolsRequests.length, '디버그 ON 인데 DevTools 번들 미로드').toBeGreaterThan(0);
      expect(devtoolsRequests[0]).toMatch(DEVTOOLS_BUNDLE_RE);
      // 패널 컨테이너가 DOM 에 마운트됐는지 확인(attached — 접힘 상태라 visible 아님).
      // 마운트 성공 = React 사본 단일 + G7DevToolsCore shim 공유 정상의 증명.
      await page.waitForSelector('#g7-devtools-root', { state: 'attached', timeout: 15_000 });
    } else {
      // 디버그 OFF — 초기 payload 에서 DevTools 번들 제거(회귀 방지 핵심)
      expect(devtoolsRequests, '디버그 OFF 인데 DevTools 번들이 로드됨(회귀)').toEqual([]);
    }
  });
});
