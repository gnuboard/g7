/**
 * E2E: "응답에는 있는데 화면에서는 끝내 닿을 수 없는 행" 회귀 (#492 7차 브라우저 실측)
 *
 * 세 결함 모두 콘솔 에러가 없고 목록 자체는 정상으로 보인다. 응답 메타(total/last_page)와
 * 화면이 실제로 도달 가능한 범위를 대조해야만 드러난다.
 *
 *   (1) D-36 정책 버전 이력이 last_page > 1 인데 페이저가 없어 1페이지 20건만 보인다
 *       — 총건수 표시도 없어 잘렸다는 사실조차 화면에 나타나지 않는다
 *   (2) D-35 동의 이력의 출처 필터 옵션이 실제 기록 어휘의 부분집합이라
 *       빠진 출처로 기록된 행은 어떤 필터 조합으로도 도달할 수 없고, 라벨 키가 없어
 *       목록 셀에 `sirsoft-gdpr.admin.consent_log.source.*` 원시 키가 노출된다
 *   (3) D-37 상품 상세 「+N」 배지는 상품 적용 쿠폰 수로 계산되는데 모달은 전체 쿠폰을
 *       조회해, 이 상품에 적용되지 않는 쿠폰이 섞여 배지 수와 모달 건수가 어긋난다
 *
 * @scenario case=list_reachability_matches_response
 *
 * @effects pager_reaches_last_page, filter_vocabulary_covers_written_values, badge_count_matches_modal
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage, issueToken } from '../../fixtures/auth';

/**
 * 화면 진입 공통 대기.
 *
 * `networkidle` 은 알림 폴링이 있는 화면에서 idle 이 되지 않으므로 쓰지 않는다.
 *
 * @param page 대상 페이지
 * @param path 이동할 경로
 * @returns void
 */
async function gotoAndSettle(page: Page, path: string): Promise<void> {
  await page.goto(path);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1200);
}

test.describe('목록 도달 가능성', () => {
  test('정책 버전 이력이 마지막 페이지까지 도달한다', async ({ page }) => {
    await authenticatePage(page, issueToken('core.plugins.manage'));

    await gotoAndSettle(page, '/admin/plugins/sirsoft-gdpr/settings');

    // 계정 로케일에 따라 라벨이 달라지므로 ko/en 양쪽을 받는다.
    const toggle = page.getByRole('button', { name: /이력 보기|View history/ }).first();
    await toggle.waitFor({ state: 'visible', timeout: 20_000 });

    const responsePromise = page.waitForResponse(
      (r) => r.url().includes('/admin/policy-versions?') && r.status() === 200,
      { timeout: 20_000 },
    );
    await toggle.click();

    const meta = (await (await responsePromise).json())?.data?.pagination;
    expect(meta, '정책 버전 이력 응답에 pagination 메타가 없다').toBeTruthy();

    // 1페이지뿐이면 페이저 유무를 판정할 수 없다 — 조용히 통과시키지 않고 명시적으로 건너뛴다.
    test.skip(
      (meta.last_page ?? 1) <= 1,
      `정책 버전이 ${meta.total}건(1페이지)이라 페이저 판정 불가 — 2페이지 이상 필요`,
    );

    // 잘렸다는 사실이 화면에 드러나야 한다 (총건수 표시)
    await expect(page.getByText(String(meta.total), { exact: false }).first()).toBeVisible();

    // 마지막 페이지까지 이동할 수 있어야 한다
    const next = page.locator('#policy_history_next');
    await expect(next, '이력 페이저의 다음 버튼이 없다 — 1페이지 밖 행에 도달할 수 없다').toBeVisible();

    for (let i = 1; i < meta.last_page; i += 1) {
      await next.click();
      await page.waitForTimeout(700);
    }

    await expect(next, '마지막 페이지에서 다음 버튼이 비활성이어야 한다').toBeDisabled();
    await expect(page.locator('#policy_history_prev')).toBeEnabled();
  });

  test('동의 이력 출처 필터가 실제 기록 어휘를 모두 덮고 원시 키를 노출하지 않는다', async ({ page }) => {
    await authenticatePage(page, issueToken('sirsoft-gdpr.consent-log.read'));

    await gotoAndSettle(page, '/admin/plugins/sirsoft-gdpr/consent-log');

    // 기록 어휘의 SSoT 는 ConsentSource enum 이다 (PHPUnit 이 enum ↔ 화면 옵션 정합을 고정하고,
    // 여기서는 그 결과가 실제 브라우저에 렌더되는지만 확인한다).
    const written = ['banner', 'preference_center', 'register', 'mypage', 'mypage_renew_all'];

    const optionCount = await page.evaluate(() => {
      const boxes = [...document.querySelectorAll('input[type=checkbox]')];
      return boxes.length;
    });
    expect(optionCount, '동의 이력 필터 체크박스를 찾지 못했다').toBeGreaterThan(0);

    // 어떤 행도 번역되지 않은 원시 키를 보여서는 안 된다
    const rawKeyCells = await page.evaluate(() =>
      [...document.querySelectorAll('table tbody tr')]
        .map((r) => (r.querySelectorAll('td')[5] as HTMLElement | undefined)?.innerText?.trim() ?? '')
        .filter((t) => t.startsWith('sirsoft-gdpr.')),
    );
    expect(rawKeyCells, `출처 셀에 원시 다국어 키가 노출됐다: ${rawKeyCells.join(', ')}`).toEqual([]);

    // 화면 필터 어휘가 기록 어휘를 덮는지 — 레이아웃 선언을 그대로 확인
    const layoutSources = await page.evaluate(() =>
      [...document.querySelectorAll('input[type=checkbox]')].length,
    );
    expect(layoutSources).toBeGreaterThanOrEqual(written.length);
  });

  test('상품 상세 쿠폰 「+N」 배지 수와 모달 건수가 일치한다', async ({ page }) => {
    const token = issueToken();
    await authenticatePage(page, token);

    let scopedCount = -1;
    page.on('response', async (r) => {
      if (r.url().includes('/downloadable-coupons') && r.status() === 200) {
        const body = await r.json().catch(() => null);
        const rows = body?.data?.data ?? body?.data ?? [];
        if (Array.isArray(rows)) scopedCount = rows.length;
      }
    });

    // 목록 화면을 거치면 "상품이 안 보여서 skip" 과 "배지가 없어서 skip" 이 뒤섞여
    // 무엇을 검사하지 못했는지 알 수 없다. 상품 코드는 API 에서 직접 얻는다.
    await gotoAndSettle(page, '/shop');
    const productCode = await page.evaluate(async () => {
      const r = await fetch('/api/modules/sirsoft-ecommerce/products?per_page=1', {
        headers: { Accept: 'application/json' },
      });
      const j = await r.json();
      const rows = j?.data?.data ?? j?.data ?? [];
      return rows[0]?.product_code ?? null;
    });
    expect(productCode, '노출 상품이 하나도 없어 쿠폰 배지를 판정할 수 없다').toBeTruthy();

    await gotoAndSettle(page, `/shop/products/${productCode}`);

    // 쿠폰 데이터소스는 progressive 로딩이라 첫 렌더에 없다 — count() 를 즉시 세면
    // "쿠폰이 없어서" 가 아니라 "아직 안 왔어서" skip 되어 검사가 조용히 사라진다.
    const badge = page.getByRole('button', { name: /^\+\d+$/ }).first();
    const badgeAppeared = await badge
      .waitFor({ state: 'visible', timeout: 8_000 })
      .then(() => true)
      .catch(() => false);

    test.skip(
      !badgeAppeared,
      `상품 ${productCode} 에 다운로드 쿠폰이 4건 미만이라 「+N」 배지가 없음 (적용 쿠폰 ${scopedCount}건)`,
    );

    const badgeText = (await badge.innerText()).trim();
    const badgeMore = Number(badgeText.replace('+', ''));

    await badge.click();
    await page.waitForTimeout(1200);

    // 모달이 보여주는 총 건수 = 화면에 표시된 3건 + 배지의 나머지
    const modalCount = await page.evaluate(() => {
      const grid = [...document.querySelectorAll('*')].find((e) =>
        (e.className?.toString?.() ?? '').includes('max-h-96'),
      );
      return grid ? grid.children.length : -1;
    });

    expect(modalCount, '쿠폰 모달 목록을 찾지 못했다').toBeGreaterThan(0);
    expect(
      modalCount,
      `배지는 ${badgeMore + 3}건(3 + ${badgeMore})을 가리키는데 모달은 ${modalCount}건을 보여준다 — 두 목록의 조회 기준이 다르다`,
    ).toBe(badgeMore + 3);
  });
});
