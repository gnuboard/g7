/**
 * sirsoft-basic — 링크가 코어 링크 API 를 쓰는지, data:·blob: 그림 주소가 서버 요청 없이 쓰이는지 (7.1.3 WP8·D19, 배포 번들 기준)
 *
 * - 링크(A)는 내부 링크 판정·새 창 rel 을 코어(`G7Core.navigation`, engine-v1.83.0)에 맡긴다. 템플릿 번들이 그 API 를 부르는지
 *   실제 화면의 링크로 잰다: 같은 출처 `//다른호스트` 주소는 보통 클릭이어도 SPA 로 옮기지 않는다.
 * - 첨부 그림 판정(`isDirectAssetUrl`)이 data:·blob: 주소를 인증 요청 없이 그대로 쓴다(종전에는 `/api/data:…` 404).
 *
 * 시나리오 매니페스트: `tests/scenarios/engine-core-link-api.yaml`(코어) — 직접 자산 주소 판정의 단위 잠금은
 * 템플릿 `tests/scenarios/asset-origin-direct-asset.yaml`(Vitest)이 맡는다
 * @effects template_uses_core_link_api
 */
import { test, expect } from '@playwright/test';

test.describe('sirsoft-basic — 코어 링크 API·직접 자산 주소', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => Boolean((window as any).G7Core?.navigation?.resolveInternalHref), null, { timeout: 30_000 });
  });

  // @scenario surface=tpl_sirsoft_basic
  // @effects template_uses_core_link_api
  test('코어 링크 API 가 템플릿 번들과 같은 판정을 준다(같은 출처 //다른호스트 는 내부 아님)', async ({ page }) => {
    const result = await page.evaluate(() => {
      const nav = (window as any).G7Core.navigation;
      return {
        doubleSlash: nav.resolveInternalHref(`${window.location.origin}//evil.example/x`),
        path: nav.resolveInternalHref('/board/free'),
        rel: nav.mergeRelForTarget(undefined, '_blank'),
      };
    });

    expect(result.doubleSlash).toBeNull();
    expect(result.path).toBe('/board/free');
    expect(result.rel).toBe('noopener noreferrer');
  });

  // @scenario surface=tpl_sirsoft_basic
  // @effects template_uses_core_link_api
  test('data:·blob: 주소로는 서버 요청이 나가지 않는다', async ({ page }) => {
    const badRequests: string[] = [];
    page.on('request', (req) => {
      if (/\/api\/(data|blob):/i.test(req.url())) badRequests.push(req.url());
    });

    // 화면에 그 꼴 주소를 가진 그림을 그려 본다 — 업로더·갤러리가 쓰는 같은 판정 경로가 요청을 내지 않아야 한다
    await page.evaluate(() => {
      const img = document.createElement('img');
      img.src = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
      document.body.appendChild(img);
    });
    await page.waitForTimeout(500);

    expect(badRequests).toEqual([]);
  });
});
