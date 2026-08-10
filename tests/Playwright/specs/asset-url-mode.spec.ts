/**
 * 자산 URL 이중 모드 — 브라우저 자가 복구 및 루프 방지 불변식 (이슈 #486 단위 D)
 *
 * ## 무엇을 모사하는가
 *
 * nginx 의 정적 최적화 블록을 route intercept 로 모사한다.
 *
 * ```nginx
 * location ~* \.(js|css|json)$ { expires max; access_log off; }
 * ```
 *
 * 이 블록은 정규식 location 이라 프리픽스 location 보다 먼저 매칭되고, 내부에 PHP
 * 핸들러가 없으면 `try_files ... /index.php` 폴백이 실행될 기회 없이 nginx 가 직접
 * 파일시스템을 열려 시도해 404 가 된다. 즉 **경로가 정적 확장자로 끝나는 `/api/` 요청만**
 * 404 가 되고, 확장자 없는 형태는 정상 통과한다 — 아래 intercept 가 그 조건을 그대로 쓴다.
 *
 * ## 검증하는 불변식 (계획서 §12)
 *
 * | # | 불변식 | 이 spec 의 단언 |
 * |---|---|---|
 * | L1 | 전환은 단방향 1회 | 전환 후 확장자 형태 재요청 0회 |
 * | L2 | 기존 재시도 예산 안에서 소비 | 자산당 네트워크 시도 ≤ 3, 페이지당 ≤ 9 |
 * | L3 | 자동 location.reload() 금지 | 네비게이션 발화 0회 |
 * | L4 | 모든 실패 경로는 폴백 UI 로 수렴 | 양쪽 실패 시 폴백 UI 노출 |
 * | L8 | pending 카운터 불변식 | 초기화가 정확히 1회 |
 *
 * 계획서 §"루프 방지 전용 테스트" 는 이 spec 이 **역방향 폴백을 일부러 넣은 브랜치에서
 * 무한 루프로 터지는지** 확인해야 가드로 인정된다고 명시한다.
 */
import { test, expect } from '@playwright/test';

/** 정적 확장자로 끝나는 `/api/` 경로 — nginx 정규식 location 의 매칭 조건과 동일 */
const STATIC_EXT_API = /\/api\/.*\.(js|css|json)(\?|$)/;

/**
 * 페이지에 "정적 최적화 nginx" 를 설치한다.
 *
 * 경로가 정적 확장자로 끝나는 `/api/` 요청만 404 로 가로챈다. 쿼리스트링은 보지 않는다 —
 * nginx 의 location 정규식도 쿼리를 제외한 경로에만 매칭되기 때문이며, 이것이
 * `?file=` 형태가 안전한 근거다.
 *
 * @param page      대상 페이지
 * @param requests  가로챈 URL 이 누적될 배열
 */
async function installStaticOptimizationServer(page: import('@playwright/test').Page, requests: string[]) {
    await page.route('**/api/**', async (route) => {
        const url = route.request().url();
        const path = new URL(url).pathname;

        if (/\.(js|css|json)$/i.test(path)) {
            requests.push(url);
            await route.fulfill({ status: 404, contentType: 'text/html', body: 'Not Found' });
            return;
        }

        await route.continue();
    });
}

test.describe('자산 URL 이중 모드 자가 복구', () => {
    test.beforeEach(async ({ page }) => {
        // 이전 방문 캐시가 남아 있으면 첫 요청부터 확장자 없는 형태를 써서
        // "전환이 일어나는지" 를 관측할 수 없다.
        await page.addInitScript(() => {
            try {
                window.localStorage.clear();
            } catch (e) { /* noop */ }
        });
    });

    test('정적 최적화 서버에서 설정 변경 없이 화면이 뜬다 (자가 복구)', async ({ page }) => {
        const blocked: string[] = [];
        await installStaticOptimizationServer(page, blocked);

        await page.goto('/');
        await page.waitForLoadState('networkidle', { timeout: 30_000 });

        // 재시도 백오프(300+600ms) + 폴백 렌더까지 지난 뒤에 판정한다.
        // domcontentloaded 직후에 단언하면 폴백이 아직 그려지기 전이라
        // 자가 복구가 실패한 경우에도 통과하는 거짓 green 이 된다.
        await page.waitForTimeout(3_000);

        // 확장자 형태가 실제로 가로채였는지 (모사가 유효한지) 먼저 확인
        expect(blocked.length, '정적 확장자 API 요청이 하나도 가로채이지 않았다 — 모사가 무효').toBeGreaterThan(0);

        // 자가 복구가 모드를 전환했어야 한다
        const mode = await page.evaluate(() => (window as any).__g7AssetUrlMode ?? (window as any).G7Config?.assetUrlMode);
        expect(mode, '자가 복구가 확장자 없는 모드로 전환하지 않았다').toBe('extensionless');

        // 폴백 UI 가 아니라 실제 앱이 렌더되어야 한다
        const bootstrapFailed = await page.evaluate(() => (window as any).__g7Bootstrap?.failed);
        expect(bootstrapFailed, '자가 복구에 실패해 폴백 UI 로 떨어졌다').toBeFalsy();

        const app = page.locator('#app');
        await expect(app).toBeAttached();
        await expect(app.getByText(/화면을 불러오지 못했습니다|Failed to load/i)).toHaveCount(0);
    });

    test('L1 — 전환 이후 확장자 형태를 다시 요청하지 않는다', async ({ page }) => {
        const blocked: string[] = [];
        await installStaticOptimizationServer(page, blocked);

        await page.goto('/');
        await page.waitForLoadState('networkidle', { timeout: 30_000 });

        // 전환이 확정된 시점을 기준으로 삼는다
        await page.waitForFunction(
            () => (window as any).__g7AssetUrlMode === 'extensionless',
            { timeout: 15_000 },
        );

        const beforeCount = blocked.length;

        // 전환이 확정된 뒤 추가로 시간을 줘도 확장자 형태 재요청이 없어야 한다
        await page.waitForTimeout(3_000);

        const afterSwitch = blocked.slice(beforeCount);

        expect(
            afterSwitch,
            `전환 이후 확장자 형태를 재요청했다 (역방향 복귀 의심):\n${afterSwitch.join('\n')}`,
        ).toEqual([]);
    });

    test('L2·L4 — 양쪽 형태가 모두 실패하면 3시도에서 멈추고 폴백 UI 로 끝난다', async ({ page }) => {
        // PHP 다운 모사 — 확장자 유무와 무관하게 모든 /api/ 를 404
        const attempts: string[] = [];
        await page.route('**/api/**', async (route) => {
            attempts.push(route.request().url());
            await route.fulfill({ status: 404, contentType: 'text/html', body: 'Not Found' });
        });

        await page.goto('/');
        await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

        // 재시도 백오프(300 + 600ms)와 폴백 렌더까지 충분히 기다린다
        await page.waitForTimeout(5_000);

        // 자산별 시도 횟수 — 경로(쿼리 제외) 기준으로 묶는다
        const perAsset = new Map<string, number>();
        for (const url of attempts) {
            const key = new URL(url).pathname.replace(/\.(js|css|json)$/i, '');
            perAsset.set(key, (perAsset.get(key) ?? 0) + 1);
        }

        for (const [asset, count] of perAsset) {
            expect(count, `${asset} 의 네트워크 시도가 3회를 넘었다 (요청 증폭)`).toBeLessThanOrEqual(3);
        }

        // 부트스트랩 자산은 CSS 1 + JS 2 → 페이지당 상한 9
        expect(attempts.length, '페이지당 총 시도가 상한 9를 넘었다').toBeLessThanOrEqual(9);

        // L4 — 최종 상태는 폴백 UI
        await expect(page.locator('#app')).not.toBeEmpty();
    });

    test('L3 — 어떤 경로에서도 스크립트가 페이지를 새로고침하지 않는다', async ({ page }) => {
        let navigations = 0;
        page.on('framenavigated', (frame) => {
            if (frame === page.mainFrame()) navigations += 1;
        });

        await page.route('**/api/**', async (route) => {
            await route.fulfill({ status: 404, contentType: 'text/html', body: 'Not Found' });
        });

        await page.goto('/');
        await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

        const afterGoto = navigations;

        // 10초 관찰 — 보정이 효과 없을 때 영구 리로드 루프가 도는지
        await page.waitForTimeout(10_000);

        expect(
            navigations - afterGoto,
            `goto 이후 네비게이션이 ${navigations - afterGoto}회 발생했다 (자동 리로드 루프 의심)`,
        ).toBe(0);
    });

    test('L8 — 전환 성공 시 초기화가 정확히 1회만 일어난다', async ({ page }) => {
        const blocked: string[] = [];
        await installStaticOptimizationServer(page, blocked);

        await page.goto('/');
        await page.waitForLoadState('networkidle', { timeout: 30_000 });
        await page.waitForTimeout(2_000);

        // 부트스트랩이 노출하는 관측 가능한 상태로 검증한다.
        // initTemplateApp 을 래핑해 호출 횟수를 세는 방식은 쓰지 않는다 — 코어 번들이
        // `G7Core` 를 할당한 뒤 메서드를 덧붙이는 2단계 구성이라 할당 시점 래핑이
        // 함수를 잡지 못하고 항상 0 이 나온다(계측 실패를 위반으로 오판하게 된다).
        const state = await page.evaluate(() => ({
            pending: (window as any).__g7Bootstrap?.pending,
            initialized: (window as any).__g7Bootstrap?.initialized,
            failed: (window as any).__g7Bootstrap?.failed,
        }));

        expect(state.pending, `pending 카운터가 0 에 도달하지 않았다 (실제: ${state.pending})`).toBe(0);
        expect(state.initialized, '초기화가 완료되지 않았다').toBe(true);
        expect(state.failed, '자가 복구에 성공했는데 failed 로 표시됐다').toBeFalsy();

        // tryInit 은 initialized 플래그로 1회 게이트되므로 위 상태가 곧 "정확히 1회" 의 증거다.
        //
        // `#app` 직계 자식 수는 중복 마운트 신호로 쓰지 않는다 — 정상 렌더에서도
        // 페이지 본문 + 쿠키 배너처럼 형제 컴포넌트가 여럿 붙어 2 이상이 된다.
        // 대신 부트스트랩 자산이 중복 실행되지 않았는지를 스크립트 태그 수로 본다.
        const duplicateScripts = await page.evaluate(() => {
            const srcs = Array.from(document.querySelectorAll('script[src]'))
                .map((el) => (el as HTMLScriptElement).src)
                .filter((src) => src.includes('components.iife.js') || src.includes('file=js%2Fcomponents'));

            return srcs.length - new Set(srcs).size;
        });

        expect(duplicateScripts, '동일 컴포넌트 번들 스크립트가 중복 삽입됐다').toBe(0);
    });

    test('확장자 없는 형태로 실제 자산이 서빙된다', async ({ page }) => {
        // 서버 계약 확인 — ?file= 형태가 200 을 주는지
        const response = await page.request.get('/api/system/asset-probe');

        expect(response.status()).toBe(200);
        expect(await response.text()).toContain('G7_ASSET_PROBE_OK');

        // 확장자 형태 프로브도 정상 환경에서는 200
        const withExt = await page.request.get('/api/system/asset-probe.js');
        expect(withExt.status()).toBe(200);
    });
});
