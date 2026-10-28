/**
 * 확장 목록 카드의 미충족 의존 표시 브라우저 실측 (코어 7.1.4, #152).
 *
 * 정적 테스트(`__tests__/components/ExtensionCardGrid.dependency-unmet.test.tsx`·
 * `__tests__/layouts/template-dependency-version-screens.test.ts`)가 잠그는 것은 컴포넌트·매핑식이다.
 * 여기서는 실제 화면이 목록 API 의 `dependencies[*].is_met`·`status` 를 받아 미충족 의존만 빨간 글자·경고 아이콘·
 * 툴팁(`title`)으로 그리는지, 빌드 CSS 에 그 색이 실제로 있는지를 잰다.
 *
 * 사이트의 실제 의존 상태에 기대지 않도록 목록 응답의 첫 템플릿 의존을 미충족 1건 + 충족 1건으로 바꿔 넣는다.
 *
 * @scenario surface=card_browser
 *
 * @effects card_marks_unmet_dependency, card_met_dependency_unchanged
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';

test.describe('확장 목록 카드 — 미충족 의존 표시', () => {
  test.setTimeout(90_000);

  // @scenario surface=card_browser
  // @effects card_marks_unmet_dependency, card_met_dependency_unchanged
  test('미충족 의존만 빨간 글자·경고 아이콘·사유 툴팁으로 그린다', async ({ page, extensionReadToken }) => {
    await authenticatePage(page, extensionReadToken);

    await page.route(/\/api\/admin\/templates\?/, async (route) => {
      if (route.request().method() !== 'GET') {
        await route.continue();
        return;
      }
      const response = await route.fetch();
      const body = await response.json();
      const rows = body?.data?.data ?? [];
      if (rows.length > 0) {
        rows[0].dependencies = {
          modules: [
            { identifier: 'e2e-dep152-board', name: '시험 게시판', type: 'module', is_met: true, status: 'met', required_version: '>=1.0.0', installed_version: '1.2.0' },
            { identifier: 'e2e-dep152-shop', name: '시험 상점', type: 'module', is_met: false, status: 'version_mismatch', required_version: '>=9.9.9', installed_version: '0.1.0' },
          ],
          plugins: [],
        };
      }
      await route.fulfill({ response, json: body });
    });

    await page.goto('/admin/templates/user');
    // networkidle 은 쓰지 않는다 — 관리자 앱이 알림 폴링을 계속 열어 두어 영영 idle 이 되지 않는다.
    await page.waitForSelector('#template_card_grid .grid > *', { timeout: 60_000 });

    // 다른 카드는 사이트의 실제 의존 상태대로 미충족일 수 있다 — 바꿔 넣은 항목만 잰다
    const unmet = page.locator('#template_card_grid [data-unmet="true"]', { hasText: '시험 상점' });
    await expect(unmet).toHaveCount(1);
    await expect(unmet).toHaveAttribute('title', '버전 미달 0.1.0 → >=9.9.9');
    await expect(unmet).toHaveAttribute('aria-label', '시험 상점 — 버전 미달 0.1.0 → >=9.9.9');
    await expect(unmet.locator('.fa-exclamation-triangle')).toHaveCount(1);

    const met = page.locator('#template_card_grid span.inline-flex:not([data-unmet])', { hasText: '시험 게시판' });
    await expect(met).toHaveCount(1);
    await expect(met).not.toHaveAttribute('title', /.+/);

    // 빌드 CSS 에 빨간 글자 색이 실제로 있어야 한다 — 충족 항목과 색이 달라야 한다
    const [unmetColor, metColor] = await Promise.all([
      unmet.evaluate((el) => getComputedStyle(el).color),
      met.evaluate((el) => getComputedStyle(el).color),
    ]);
    expect(unmetColor).not.toBe(metColor);
  });
});
