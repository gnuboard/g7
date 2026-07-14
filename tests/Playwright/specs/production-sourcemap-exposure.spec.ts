/**
 * E2E: 프로덕션 소스맵 노출 차단 회귀 검증
 *
 * @scenario production-sourcemap-exposure
 * @effects no_sourcemap_requests_from_loaded_bundles,
 *          page_renders_without_map_related_console_errors,
 *          module_sourcemap_asset_request_rejected_even_when_file_exists,
 *          plugin_sourcemap_asset_request_rejected_even_when_file_exists
 *
 * 배경: 소스맵(.map)에는 원본 TS/TSX 전문이 담긴다. 배포 산출물에서 (1) 맵 생성 자체를
 * 막고, (2) 서빙을 거부하며, (3) 저장소 유입을 차단했다.
 *
 * 이 spec 은 브라우저 실측으로 두 가지를 고정한다:
 *   - 로드된 코어/확장 번들이 맵을 요청하지 않는다 (sourceMappingURL 주석 부재의 결과)
 *   - 맵 주석 제거가 번들을 깨뜨리지 않았다 (페이지 정상 렌더 + 콘솔 에러 없음)
 *
 * 주의: 맵 주석이 남아있는데 .map 만 지운 상태였다면 브라우저가 맵을 요청해 404 가
 * 발생한다. 이 spec 은 그 회귀(주석/파일 불일치)도 함께 잡는다.
 */
import { test, expect } from '@playwright/test';

/** 소스맵 요청으로 간주할 URL 패턴 */
const MAP_REQUEST = /\.map(\?|$)/;

test.describe('프로덕션 소스맵 노출 차단', () => {
  test('@smoke 사용자 페이지 로드 시 소스맵을 요청하지 않는다', async ({ page }) => {
    const mapRequests: string[] = [];
    page.on('request', (req) => {
      if (MAP_REQUEST.test(req.url())) {
        mapRequests.push(req.url());
      }
    });

    await page.goto('/');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    expect(
      mapRequests,
      `소스맵 요청이 발생했습니다(산출물에 sourceMappingURL 주석 잔존): ${mapRequests.join(', ')}`,
    ).toHaveLength(0);
  });

  test('@smoke 관리자 페이지 로드 시 소스맵을 요청하지 않는다', async ({ page }) => {
    const mapRequests: string[] = [];
    page.on('request', (req) => {
      if (MAP_REQUEST.test(req.url())) {
        mapRequests.push(req.url());
      }
    });

    // 로그인 전 진입 화면이라도 코어 번들(template-engine)은 로드된다
    await page.goto('/admin');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    expect(
      mapRequests,
      `관리자 페이지에서 소스맵 요청 발생: ${mapRequests.join(', ')}`,
    ).toHaveLength(0);
  });

  test('@smoke 맵 주석 제거 후에도 페이지가 정상 렌더된다', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });

    // 정적 에셋(JS/CSS/MAP) 로드 실패만 수집한다. 도메인 API 응답(예: 이커머스
    // checkout 검증 422)은 소스맵 정책과 무관하므로 이 spec 의 판정 대상이 아니다.
    const failedAssets: string[] = [];
    page.on('response', (res) => {
      if (res.status() >= 400 && /\.(js|css|map)(\?|$)/.test(res.url())) {
        failedAssets.push(`${res.url()} → ${res.status()}`);
      }
    });

    await page.goto('/');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 렌더 성공 = 코어 엔진 + 확장 번들이 모두 정상 파싱/실행됨
    await expect(page.getByTestId('nav-home')).toBeVisible({ timeout: 15_000 });

    // 맵 주석만 남고 파일이 없으면 404 가 뜬다 (주석/파일 불일치 회귀)
    expect(
      failedAssets,
      `에셋 로드 실패(맵/JS/CSS): ${failedAssets.join(' | ')}`,
    ).toHaveLength(0);

    // 맵 주석 제거가 번들을 깨뜨렸다면 파싱/실행 에러가 뜬다.
    const parseErrors = consoleErrors.filter((e) =>
      /Unexpected token|SyntaxError|is not defined/i.test(e),
    );
    expect(parseErrors, `번들 실행 관련 콘솔 에러: ${parseErrors.join(' | ')}`).toHaveLength(0);
  });

  test('@smoke 확장 소스맵 에셋은 직접 요청해도 서빙되지 않는다', async ({ request }) => {
    // 디스크에 맵이 없더라도, 허용 확장자에서 제외됐으므로 200 이 나오면 안 된다.
    const targets = [
      '/api/modules/assets/sirsoft-ecommerce/dist/js/module.iife.js.map',
      '/api/plugins/assets/sirsoft-gdpr/dist/js/plugin.iife.js.map',
    ];

    for (const target of targets) {
      const res = await request.get(target, { maxRedirects: 0, failOnStatusCode: false });

      expect(res.status(), `${target} 이 200 으로 서빙됨 — 소스맵 노출`).not.toBe(200);

      const body = await res.text().catch(() => '');
      expect(
        body.includes('sourcesContent'),
        `${target} 응답에 원본 코드(sourcesContent)가 포함됨`,
      ).toBe(false);
    }
  });

  test('@smoke 결제 플러그인이 체크아웃 외 페이지에서 체크아웃을 프리페치하지 않는다', async ({ page }) => {
    // 플러그인 번들은 모든 페이지에 로드된다. nicepayments 캐시 프리페치가 경로를
    // 가리지 않으면 비회원이 모든 페이지에서 422(cart_key_required)를 받는다.
    const checkoutRequests: string[] = [];
    page.on('request', (req) => {
      if (req.url().includes('/sirsoft-ecommerce/checkout')) {
        checkoutRequests.push(req.url());
      }
    });

    await page.goto('/');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    expect(
      checkoutRequests,
      `홈에서 체크아웃 프리페치 발생: ${checkoutRequests.join(', ')}`,
    ).toHaveLength(0);
  });

  test('@smoke 코어 소스맵은 웹루트에서 서빙되지 않는다', async ({ request }) => {
    const targets = [
      '/build/core/template-engine.min.js.map',
      '/build/core/layout-editor.min.js.map',
      '/build/core/devtools.min.js.map',
    ];

    for (const target of targets) {
      const res = await request.get(target, { maxRedirects: 0, failOnStatusCode: false });

      expect(res.status(), `${target} 이 200 으로 서빙됨 — 원본 코드 노출`).not.toBe(200);
    }
  });
});
