/**
 * E2E: 방문자 통계 개요 — 기간 프리셋 왕복과 CSV 내려받기 (이슈 #682 S3)
 *
 * 레이아웃 계약 테스트는 「이동이 mergeQuery 다」까지만 잠근다. 실제 브라우저에서 프리셋 클릭이
 * 주소를 바꾸고, 새로고침이 그 주소를 복원하고, 「더보기」로 다른 화면에 가거나 서브탭을 눌러도
 * 기간이 따라가는 것은 라우터·데이터소스·핸들러가 함께 도는 브라우저에서만 드러난다. CSV 도
 * 마찬가지다 — 토큰이 실리지 않으면 401 이고, BOM 이 빠지면 엑셀에서 한글이 깨진다.
 *
 * 축 요약(마커 아님 — 평문): 프리셋 → 주소 → 새로고침 → 화면 이동 → 서브탭 이동, CSV 응답 형식.
 */
import { readFileSync } from 'fs';
import { authenticatePage, expect, issueToken, test } from '../../fixtures/auth';

test.describe('방문자 통계 개요 — 기간 왕복과 CSV', () => {
  test.beforeEach(async ({ page }) => {
    await authenticatePage(page, issueToken('core.analytics.read'));
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects preset_survives_reload_screen_change_and_subtab
  test('프리셋 「30일」이 주소에 남고 새로고침·화면 이동·서브탭 이동에도 기간이 유지된다', async ({ page }) => {
    await page.goto('/admin/analytics');
    await expect(page.locator('#analytics_period_bar')).toBeVisible({ timeout: 30_000 });

    const overviewRequest = page.waitForResponse(
      (r) => r.url().includes('/api/admin/analytics/overview') && r.url().includes('preset=30d'),
      { timeout: 20_000 },
    );
    await page.locator('#preset_30d').click();
    expect((await overviewRequest).status()).toBe(200);
    await expect(page).toHaveURL(/preset=30d/);
    await expect(page.locator('#preset_30d')).toHaveClass(/active/);
    // 프리셋 클릭은 달력 값도 계산해 채운다 — 30일 프리셋의 시작일은 종료일보다 앞이다.
    const from = await page.locator('#period_from').inputValue();
    const to = await page.locator('#period_to').inputValue();
    expect(from < to).toBe(true);

    await page.reload();
    await expect(page.locator('#analytics_period_bar')).toBeVisible({ timeout: 30_000 });
    await expect(page).toHaveURL(/preset=30d/);
    await expect(page.locator('#preset_30d')).toHaveClass(/active/);

    // 「더보기」→ 페이지 화면. 리포트 요청에 같은 프리셋이 실린다(mergeQuery).
    const pagesRequest = page.waitForResponse(
      (r) => r.url().includes('/api/admin/analytics/reports/page_url') && r.url().includes('preset=30d'),
      { timeout: 20_000 },
    );
    await page.locator('#top_pages_card button').first().click();
    expect((await pagesRequest).status()).toBe(200);
    await expect(page).toHaveURL(/\/admin\/analytics\/pages\?.*preset=30d/);
    await expect(page.locator('#preset_30d')).toHaveClass(/active/);

    // 서브탭 전환(`?tab=`)은 화면 이동과 다른 경로다 — `mergeQuery` 가 아니라 기간 키를
    // `<query>` 로 **명시 전달**한다(쪽·검색·정렬은 일부러 버린다). 그 명시 목록에서 키가
    // 하나라도 빠지면 탭만 눌러도 기간이 기본값으로 되돌아간다(#682 S3 라운드 7).
    // 로케일 비의존으로 고르려고 라벨 대신 두 번째 탭(진입 페이지)을 순서로 집는다.
    const entryTabRequest = page.waitForResponse(
      (r) => r.url().includes('/api/admin/analytics/reports/entry_pages') && r.url().includes('preset=30d'),
      { timeout: 20_000 },
    );
    await page.locator('#report_tabs [role="tab"]').nth(1).click();
    expect((await entryTabRequest).status()).toBe(200);
    await expect(page).toHaveURL(/tab=entry/);
    await expect(page).toHaveURL(/preset=30d/);
    await expect(page.locator('#preset_30d')).toHaveClass(/active/);
  });

  // @scenario screen=pages, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects csv_download_is_text_csv_with_bom
  test('CSV 다운로드는 토큰이 실린 text/csv 응답이고 BOM 으로 시작한다', async ({ page }) => {
    await page.goto('/admin/analytics/pages?preset=7d');
    await expect(page.locator('#csv_download_button')).toBeVisible({ timeout: 30_000 });

    const csvResponse = page.waitForResponse((r) => r.url().includes('format=csv'), { timeout: 20_000 });
    const download = page.waitForEvent('download', { timeout: 20_000 });
    await page.locator('#csv_download_button').click();

    const response = await csvResponse;
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('text/csv');
    expect(response.headers()['content-disposition'] ?? '').toContain('analytics-page_url');

    // 바이트는 **사용자가 실제로 저장한 파일**에서 본다. 브라우저 응답 본문(CDP)은 text/csv 를 문자로
    // 디코드하면서 BOM 을 떼어 내므로, 거기서 재면 서버가 BOM 을 보내도 없는 것처럼 보인다.
    const saved = await download;
    // 서버 규약(analytics-{report}-{from}_{to}.csv)대로 날짜 범위를 담아야 어느 기간 파일인지 알 수 있다 —
    // blob 저장은 응답 헤더의 이름을 쓰지 못해 화면이 준 이름이 이긴다.
    expect(saved.suggestedFilename()).toMatch(/^analytics-page_url-\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.csv$/);
    const bytes = readFileSync(await saved.path());
    // 엑셀이 UTF-8 로 읽게 하는 바이트 순서 표식 — 없으면 한글이 깨진다.
    expect(Array.from(bytes.subarray(0, 3))).toEqual([0xef, 0xbb, 0xbf]);
    expect(bytes.toString('utf8')).toContain('label,label_display,visits');
  });
});
