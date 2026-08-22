/**
 * E2E: 구동 에셋의 자체 제공 (공개 이슈 #123)
 *
 * 배경: 브라우저가 화면을 그리기 위해 6개 제3자 CDN 에 도달해야 했다. 도달 실패는
 * 예외도 로그도 남기지 않고 화면 기능만 조용히 사라진다 — 폐쇄망·방화벽·광고차단기에서
 * 재현되며 자체 서버 로그에 흔적이 없어 운영자가 원인을 특정할 수 없다.
 *
 * 이 스펙은 **네트워크 레벨에서 무조건 측정**한다. 소스에 URL 이 없는지는 정적 검사와
 * PHPUnit 전수 스캔이 보지만, 실제 페이지가 어디로 나가는지는 브라우저만 안다 —
 * 런타임에 주입되는 스크립트·CSS 는 소스 스캔이 놓칠 수 있다.
 *
 * 시나리오 축·효과는 tests/scenarios/self-hosted-runtime-assets.yaml 참조.
 * 마커는 test 에만 둔다 — 파일 레벨에 몰아 적으면 그 test 를 지워도 효과가 green 으로 남는다.
 */
import { test, expect, authenticatePage } from '../fixtures/auth';

/** 자체 제공 대상이 아닌 외부 호스트 (서비스 SDK — manifest 에 사유와 함께 선언됨) */
const SERVICE_SDK_HOSTS = new Set(['t1.daumcdn.net']);

/**
 * 페이지가 실제로 요청한 URL 을 수집합니다.
 *
 * @param page Playwright 페이지
 * @returns 수집된 URL 배열 (수집은 호출 시점부터 시작)
 */
function collectRequests(page: any): string[] {
    const urls: string[] = [];

    page.on('request', (request: any) => {
        const type = request.resourceType();

        if (['script', 'stylesheet', 'font', 'image'].includes(type)) {
            urls.push(request.url());
        }
    });

    return urls;
}

/**
 * same-origin 도 서비스 SDK 도 아닌 요청을 골라냅니다.
 *
 * @param urls 수집된 URL 목록
 * @param origin 현재 사이트 origin
 * @returns 위반 URL 목록
 */
function thirdPartyOffenders(urls: string[], origin: string): string[] {
    return urls.filter((url) => {
        let parsed: URL;

        try {
            parsed = new URL(url);
        } catch {
            return false;
        }

        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
            return false; // data:/blob: 는 자체 제공의 반대편이 아니다
        }

        if (parsed.origin === origin) {
            return false;
        }

        return !SERVICE_SDK_HOSTS.has(parsed.hostname.toLowerCase());
    });
}

test.describe('구동 에셋 자체 제공', () => {
    test('관리자 화면 로드에 제3자 origin 요청이 없다', async ({ page }) => {
        // @scenario asset_class=vendored, outcome=loaded
        // @effects no_third_party_request_on_page_load, runtime_asset_served_same_origin
        const urls = collectRequests(page);

        await page.goto('/admin/login');
        await page.waitForFunction(() => typeof (window as any).G7Config !== 'undefined', null, {
            timeout: 30_000,
        });
        await page.waitForLoadState('networkidle');

        const origin = await page.evaluate(() => window.location.origin);

        // 측정 대상이 실제로 존재하는지 확인 (공허한 통과 방지)
        expect(urls.length).toBeGreaterThan(0);
        expect(thirdPartyOffenders(urls, origin)).toEqual([]);
    });

    test('사용자 화면 로드에 제3자 origin 요청이 없다', async ({ page }) => {
        // @scenario asset_class=vendored, outcome=loaded
        // @effects no_third_party_request_on_page_load
        const urls = collectRequests(page);

        await page.goto('/');
        await page.waitForLoadState('networkidle');

        const origin = await page.evaluate(() => window.location.origin);

        expect(urls.length).toBeGreaterThan(0);
        expect(thirdPartyOffenders(urls, origin)).toEqual([]);
    });

    test('아이콘 폰트가 실제로 적용된다 (자체 제공본이 동작한다)', async ({ page }) => {
        // 요청이 same-origin 이어도 그 파일이 깨졌으면 아이콘은 안 보인다.
        // "외부로 안 나갔다" 와 "아이콘이 보인다" 는 다른 사실이므로 둘 다 측정한다.
        // @scenario asset_class=vendored, outcome=loaded
        // @effects runtime_asset_served_same_origin
        await page.goto('/admin/login');
        await page.waitForLoadState('networkidle');

        const fontFamily = await page.evaluate(() => {
            const icon = document.querySelector('[class*="fa-"]');

            if (!icon) {
                return null;
            }

            return window.getComputedStyle(icon, '::before').fontFamily;
        });

        // 아이콘이 없는 화면이면 이 단언은 건너뛴다 (측정 대상 부재를 통과로 위장하지 않는다)
        if (fontFamily !== null) {
            expect(fontFamily).toContain('Font Awesome');
        }
    });

    test('동봉 자산이 same-origin 경로로 200 을 돌려준다', async ({ page }) => {
        // @scenario asset_class=vendored, outcome=loaded
        // @effects runtime_asset_served_same_origin, vendored_asset_declared_path_exists_on_disk
        await page.goto('/admin/login');

        const results = await page.evaluate(async () => {
            // URL 을 문자열로 조립하지 않는다 — 확장자를 정적 location 이 가로채는 서버에서는
            // 확장자 형태가 404 이고 `?file=` 형태만 200 이다. 어느 쪽이 맞는지는 서버 설정이
            // 정하므로 앱 자신의 빌더(`G7Core.asset`)에게 물어야 한다. 하드코딩하면 이 테스트는
            // `extension` 모드 서버에서만 통과하고 다른 서버에서는 제품이 멀쩡한데 실패한다.
            const asset = (window as any).G7Core?.asset;

            if (!asset) {
                return null;
            }

            const urls: string[] = [
                asset.template('sirsoft-admin_basic', 'vendor/font-awesome/6.4.0/css/all.inlined.css'),
                asset.plugin('sirsoft-ckeditor5', 'dist/vendor/ckeditor5/43.3.1/ckeditor5.css'),
            ];

            return Promise.all(
                urls.map(async (path) => {
                    const response = await fetch(path, { method: 'GET' });

                    return { path, status: response.status, type: response.headers.get('content-type') };
                })
            );
        });

        expect(results, 'G7Core.asset 이 노출되지 않았습니다 — 자산 URL 배선이 끊겼습니다.').not.toBeNull();

        for (const result of results ?? []) {
            expect(result.status, `${result.path} 가 200 이 아닙니다`).toBe(200);
            expect(result.type).toContain('css');
        }
    });
});

test.describe('서버가 심은 externals 의 로드 실패', () => {
    // 아이콘 폰트 CSS 는 서버가 HTML 에 직접 심으므로 엔진 번들보다 먼저 평가된다.
    // 실패해도 자바스크립트에는 아무 신호가 오지 않아, 안내 계층이 이 경로만 비어 있었다.
    // 그 결과는 "아이콘이 통째로 사라진 화면" 이고, 아이콘만으로 조작하는 버튼이 있는
    // 화면에서는 곧 조작 불능인데 배너도 로그도 남지 않았다.
    test('아이콘 글꼴을 못 불러오면 안내가 뜨고, 복구되면 사라진다', async ({ page, editToken }) => {
        // @scenario asset_class=vendored, outcome=failed
        // @effects failed_asset_shows_retry_notice
        test.setTimeout(120_000);

        let blocked = true;
        await page.route(/font-awesome/i, route => (blocked ? route.abort() : route.continue()));

        // 아이콘이 실제로 조작 수단인 화면에서 본다 — 사용자 홈은 아이콘 노드가 없어
        // "무너졌다" 를 관측할 대상 자체가 없다.
        await authenticatePage(page, editToken);
        await page.goto('/admin/dashboard');

        const notice = page.locator('#g7-asset-failure-notice');
        await expect(notice).toBeVisible({ timeout: 30_000 });
        await expect(notice).toHaveAttribute('role', 'alert');

        // 아이콘이 글리프 없이 무너졌는지 — 배너가 가리키는 실제 손상.
        // 배너는 화면이 그려지기 전에 뜨므로 아이콘 노드가 생길 때까지 기다린 뒤 잰다.
        // state: 'attached' 가 필수다 — 글리프가 사라진 아이콘은 0×0 이라 Playwright 의
        // 기본 조건('visible')으로는 영원히 기다리게 된다. 재고 싶은 손상이 곧 대기 조건을
        // 막는 형태라, 여기서 기본값을 쓰면 이 테스트는 원리상 통과할 수 없다.
        await page.waitForSelector('.fa, [class^="fa-"], [class*=" fa-"]', {
            state: 'attached',
            timeout: 30_000,
        });

        const collapsedWidth = await page.evaluate(() => {
            const icon = document.querySelector('.fa, [class^="fa-"], [class*=" fa-"]')!;
            return Math.round(icon.getBoundingClientRect().width);
        });
        expect(collapsedWidth).toBe(0);

        // 차단을 풀고 [다시 시도] → 배너가 사라지고 아이콘이 되살아난다
        blocked = false;
        await notice.locator('[data-action="retry"]').click();

        await expect(notice).toHaveCount(0, { timeout: 30_000 });

        await expect
            .poll(
                () =>
                    page.evaluate(() => {
                        const icon = document.querySelector('.fa, [class^="fa-"], [class*=" fa-"]');
                        return icon ? Math.round(icon.getBoundingClientRect().width) : 0;
                    }),
                { timeout: 20_000 },
            )
            .toBeGreaterThan(0);
    });

    test('정상 경로에서는 안내가 뜨지 않는다', async ({ page, editToken }) => {
        // 실패 신호가 오탐하면 모든 화면에 배너가 상주해 안내가 잡음이 된다.
        // @scenario asset_class=vendored, outcome=loaded
        // @effects runtime_asset_served_same_origin
        await authenticatePage(page, editToken);
        await page.goto('/admin/dashboard');
        await page.waitForTimeout(6000);

        await expect(page.locator('#g7-asset-failure-notice')).toHaveCount(0);
    });
});
