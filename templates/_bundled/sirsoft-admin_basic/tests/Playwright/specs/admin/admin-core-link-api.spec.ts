/**
 * sirsoft-admin_basic — 링크가 코어 링크 API 를 쓰는지 (7.1.3 WP8, 배포 번들 기준)
 *
 * 종전 이 템플릿의 링크 판정 사본은 같은 출처 `https://이 사이트//다른호스트` 를 내부 링크로 봐, href·새 탭은 바깥으로 가는데
 * 보통 클릭만 엔진에서 거부됐다. 이제 링크는 코어 `G7Core.navigation.resolveInternalHref` 하나로 판정하고, 새 창 rel 은
 * `mergeRelForTarget`(빠진 noopener·noreferrer 만 더함)을 쓴다.
 *
 * 시나리오 매니페스트: `tests/scenarios/engine-core-link-api.yaml`(코어)
 * @effects template_uses_core_link_api, internal_href_single_judgment
 */
import { test, expect } from '@playwright/test';

test.describe('sirsoft-admin_basic — 코어 링크 API', () => {
  // @scenario surface=tpl_sirsoft_admin_basic
  // @effects template_uses_core_link_api, internal_href_single_judgment
  test('관리자 화면 번들에서 코어 링크 판정·rel 규칙이 쓰인다', async ({ page }) => {
    await page.goto('/admin/login');
    await page.waitForFunction(() => Boolean((window as any).G7Core?.navigation?.mergeRelForTarget), null, { timeout: 30_000 });

    const result = await page.evaluate(() => {
      const nav = (window as any).G7Core.navigation;
      return {
        doubleSlash: nav.resolveInternalHref(`${window.location.origin}//evil.example/x`),
        backslash: nav.resolveInternalHref('/\\evil.example/x'),
        admin: nav.resolveInternalHref('/admin/users?page=2'),
        // 종전 이 템플릿 규칙은 noopener 가 있으면 noreferrer 를 더하지 않았다
        rel: nav.mergeRelForTarget('noopener', '_blank'),
      };
    });

    expect(result.doubleSlash).toBeNull();
    expect(result.backslash).toBeNull();
    expect(result.admin).toBe('/admin/users?page=2');
    expect(result.rel).toBe('noopener noreferrer');
  });
});
