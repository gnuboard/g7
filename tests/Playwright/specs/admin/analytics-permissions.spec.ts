/**
 * E2E: 방문자 통계 권한 3단계 — 조회·설정·개인정보 열람 (이슈 #682 S3)
 *
 * 권한은 서버(라우트 미들웨어·레이아웃 권한) 쪽에서 막힌다. 레이아웃에 권한이 걸린 화면은 권한이 없으면
 * 레이아웃 요청 자체가 403 이라, 엔진이 화면 틀 대신 「페이지 로딩 실패」 카드에 서버가 준 필요 권한을
 * 적어 보인다(코어 관리자 화면과 같은 동작 — 메뉴는 권한 없는 항목을 숨기므로 주소를 직접 쳤을 때만 닿는다).
 * 단위 테스트는 각 쪽을 따로 잠그므로 「읽기 권한만 가진 운영자가 설정 주소를 치면 무엇을 보는가」는
 * 브라우저에서 권한을 좁힌 계정으로만 확인된다. `issueScopedToken` 은 admin 역할을 주지 않는다 — 기본
 * 토큰은 전체 권한을 가져 이 분기를 만들 수 없다.
 *
 * IP 가 남은 방문자 1명을 시드하고(문서용 대역) 끝나면 지운다.
 */
import type { Page } from '@playwright/test';
import { authenticatePage, expect, issueScopedToken, test } from '../../fixtures/auth';
import { runSeed } from '../../fixtures/analytics';

let visitorKey = '';

/**
 * 권한 없는 화면 — 레이아웃 요청이 403 이고, 화면에 서버가 알려 준 필요 권한이 보인다.
 *
 * @param page 페이지
 * @param path 이동할 주소
 * @param permission 화면이 요구하는 권한 식별자
 */
async function expectForbiddenLayout(page: Page, path: string, permission: string): Promise<void> {
  const layout = page.waitForResponse((r) => r.url().includes('/api/layouts/') && r.status() === 403, { timeout: 30_000 });
  await page.goto(path);
  await layout;
  await expect(page.getByText(`필요 권한: ${permission}`), path).toBeVisible({ timeout: 30_000 });
}

test.beforeAll(() => {
  const out = runSeed('privacy-visitor');
  visitorKey = (out.match(/SEED_VISITOR_KEY=(\S+)/) ?? [])[1] ?? '';
  expect(visitorKey).not.toBe('');
});

test.afterAll(() => {
  runSeed('cleanup-privacy-visitor');
});

test.describe('방문자 통계 권한 3단계', () => {
  // @scenario screen=settings, state=forbidden, viewport=1440, locale=ko, e2e=permissions
  // @effects read_only_account_gets_403_page_on_settings
  test('조회 권한만 있으면 개요는 열리고 설정·방문자 상세·정보주체 화면은 레이아웃 403 이다', async ({ page }) => {
    await authenticatePage(page, issueScopedToken('core.analytics.read'));

    await page.goto('/admin/analytics');
    await expect(page.locator('#kpi_today')).toBeVisible({ timeout: 30_000 });

    await expectForbiddenLayout(page, '/admin/analytics/settings', 'core.analytics.update');
    await expectForbiddenLayout(page, `/admin/analytics/visitors/${visitorKey}`, 'core.analytics.privacy');
    await expectForbiddenLayout(page, '/admin/analytics/privacy-requests', 'core.analytics.privacy');
  });

  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko, e2e=permissions
  // @effects privacy_account_sees_visitor_ip_column
  test('열람 권한이 있으면 방문자 상세가 열리고 IP 가 보이며, 설정은 레이아웃 403 이다', async ({ page }) => {
    await authenticatePage(page, issueScopedToken('core.analytics.read', 'core.analytics.privacy'));

    const detail = page.waitForResponse((r) => r.url().includes(`/api/admin/analytics/visitors/${visitorKey}`), { timeout: 30_000 });
    await page.goto(`/admin/analytics/visitors/${visitorKey}`);
    expect((await detail).status()).toBe(200);
    await expect(page.locator('#visitor_card')).toContainText('203.0.113.77', { timeout: 30_000 });

    await expectForbiddenLayout(page, '/admin/analytics/settings', 'core.analytics.update');
  });

  // @scenario screen=privacy_requests, state=normal, viewport=1440, locale=ko, e2e=permissions
  // @effects privacy_account_exports_subject_json
  test('열람 권한으로 정보주체를 IP 로 찾아 JSON 파일로 내보낸다', async ({ page }) => {
    await authenticatePage(page, issueScopedToken('core.analytics.read', 'core.analytics.privacy'));

    await page.goto('/admin/analytics/privacy-requests?subject=ip&value=203.0.113.77');
    const exportButton = page.locator('#export_button');
    await expect(exportButton).toBeEnabled({ timeout: 30_000 });

    const exported = page.waitForResponse(
      (r) => r.url().includes('/api/admin/analytics/privacy/subjects/export') && r.request().method() === 'POST',
      { timeout: 20_000 },
    );
    await exportButton.click();
    const response = await exported;
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/json');
    expect(JSON.parse(response.request().postData() ?? '{}')).toMatchObject({ ip: '203.0.113.77' });
    const body = (await response.body()).toString('utf8');
    expect(body.trimStart().startsWith('{')).toBe(true);
    expect(body).toContain(visitorKey);
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko, e2e=permissions
  // @effects update_account_opens_settings_but_not_visitors
  test('설정 권한이 있으면 설정 화면이 열리고 방문자 목록은 레이아웃 403 이다', async ({ page }) => {
    await authenticatePage(page, issueScopedToken('core.analytics.read', 'core.analytics.update'));

    const settings = page.waitForResponse((r) => r.url().includes('/api/admin/analytics/settings'), { timeout: 30_000 });
    await page.goto('/admin/analytics/settings');
    expect((await settings).status()).toBe(200);
    await expect(page.locator('#settings_tabs')).toBeVisible({ timeout: 30_000 });

    await expectForbiddenLayout(page, '/admin/analytics/visitors', 'core.analytics.privacy');
  });
  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko, e2e=permissions
  // @effects timeline_search_row_shows_result_count
  test('방문자 상세 타임라인의 검색 행이 검색어와 결과 건수를 함께 보인다', async ({ page }) => {
    // 검색어만 보이면 「빈손으로 돌아간 검색」을 이 화면에서 가려낼 수 없어 행동 화면의 「결과 없음」
    // 열로 건너가야 한다(계획 §4.10 「검색어(+결과 수)」·§4.11 「검색 "그누보드" 결과 12건」).
    // 그 숫자는 원장에 수집 시점부터 있었고 응답에만 빠져 있었다 — 단위 가드는 노드와 문구를 보지만
    // 「펼쳐서 그 줄에 같이 보이는가」는 브라우저에서만 확인된다.
    await authenticatePage(page, issueScopedToken('core.analytics.read', 'core.analytics.privacy'));

    const detail = page.waitForResponse((r) => r.url().includes(`/api/admin/analytics/visitors/${visitorKey}`), { timeout: 30_000 });
    await page.goto(`/admin/analytics/visitors/${visitorKey}`);
    expect((await detail).status()).toBe(200);

    // 방문 아코디언을 펼친다 — 접힌 상태에서는 타임라인이 없다.
    const accordion = page.locator('[id^="visit_item_"] button').first();
    await expect(accordion).toBeVisible({ timeout: 30_000 });
    await accordion.click();

    // 시드가 심은 검색 액션(「그누보드」, 결과 12건)이 한 줄에 함께 보여야 한다.
    const searchRow = page.locator('[id^="timeline_search_result_count"]').first();
    await expect(searchRow, '검색 행에 결과 건수가 붙어야 한다').toBeVisible({ timeout: 30_000 });
    await expect(searchRow).toContainText('12');
    await expect(page.locator('body')).toContainText('그누보드');
    await expect(page.locator('body')).not.toContainText('$t:');
  });

  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko, e2e=permissions
  // @effects csv_filename_carries_the_actual_date_range
  test('방문자 상세 CSV 는 기간이 붙은 이름으로 저장된다', async ({ page }) => {
    // 내려받기는 blob 저장이라 서버가 붙인 파일명(응답 헤더)을 쓰지 못하고 화면이 준 이름이 이긴다.
    // 이 버튼만 이름을 기간 없이 고정해, 같은 사람을 두 기간으로 받으면 같은 이름이 됐다(#682 S3 라운드 13).
    await authenticatePage(page, issueScopedToken('core.analytics.read', 'core.analytics.privacy'));

    await page.goto(`/admin/analytics/visitors/${visitorKey}?from=2026-09-01&to=2026-09-07`);
    const button = page.locator('#visitor_csv_button');
    await expect(button).toBeVisible({ timeout: 30_000 });

    const download = page.waitForEvent('download', { timeout: 20_000 });
    await button.click();

    expect((await download).suggestedFilename()).toBe(`analytics-visitor-${visitorKey}-2026-09-01_2026-09-07.csv`);
  });

  // @scenario screen=privacy_requests, state=normal, viewport=1440, locale=ko, e2e=permissions
  // @effects download_filename_matches_the_server_name
  test('정보주체 JSON 은 서버가 알려 준 이름(날짜)으로 저장되고 검색한 IP 를 이름에 넣지 않는다', async ({ page }) => {
    // 화면이 검색값으로 이름을 지어 방문자 IP 가 다운로드 폴더에 남았다 — 서버 규약은 날짜다(#682 S3 라운드 15).
    await authenticatePage(page, issueScopedToken('core.analytics.privacy'));

    await page.goto('/admin/analytics/privacy-requests?subject=ip&value=203.0.113.77');
    const exportButton = page.locator('#export_button');
    await expect(exportButton).toBeEnabled({ timeout: 30_000 });

    const download = page.waitForEvent('download', { timeout: 20_000 });
    await exportButton.click();
    const name = (await download).suggestedFilename();
    expect(name).toMatch(/^analytics-subject-\d{8}\.json$/);
    expect(name).not.toContain('203.0.113');
  });

  // @scenario screen=bots, state=normal, viewport=1440, locale=ko, e2e=permissions
  // @effects download_filename_matches_the_server_name
  test('봇 › 페이지 탭 CSV 는 서버 규약 이름(bot_pages)으로 저장된다', async ({ page }) => {
    await authenticatePage(page, issueScopedToken('core.analytics.read'));

    await page.goto('/admin/analytics/bots?tab=pages&from=2026-09-01&to=2026-09-07');
    const button = page.locator('#csv_download_button');
    await expect(button).toBeVisible({ timeout: 30_000 });

    const download = page.waitForEvent('download', { timeout: 20_000 });
    await button.click();
    expect((await download).suggestedFilename()).toBe('analytics-bot_pages-2026-09-01_2026-09-07.csv');
  });

  // @scenario screen=pages, state=normal, viewport=1440, locale=ko, e2e=permissions
  // @effects report_page_size_follows_the_setting
  test('리포트 화면은 주소에 쪽 크기가 없으면 per_page 를 보내지 않고, 선택란이 응답의 쪽 크기를 보인다', async ({ page }) => {
    // 화면이 늘 `per_page=25` 를 보내 설정 「상세 TOP N」이 리포트에 닿지 않았다(#682 S3 라운드 15).
    await authenticatePage(page, issueScopedToken('core.analytics.read'));

    const report = page.waitForResponse(
      (r) => r.url().includes('/api/admin/analytics/reports/page_url') && r.request().method() === 'GET',
      { timeout: 30_000 },
    );
    await page.goto('/admin/analytics/pages?preset=7d');
    const response = await report;
    expect(new URL(response.url()).searchParams.has('per_page'), '비워 보내야 서버가 설정값을 쓴다').toBe(false);
    const perPage = String((await response.json())?.data?.pagination?.per_page ?? '');
    expect(perPage).not.toBe('');
    await expect(page.locator('#report_per_page')).toContainText(perPage, { timeout: 20_000 });
  });

  // @scenario screen=settings, state=forbidden, viewport=1440, locale=ko, e2e=permissions
  // @effects cross_permission_fallback_never_reads_as_a_fact
  test('설정 권한만 있으면 전체 삭제 창이 행 수 대신 권한 안내를 보인다 — 「0행」을 사실처럼 보이지 않는다', async ({ page }) => {
    // 행 수는 조회 권한 API 에서 온다 — 권한이 없으면 삼킨 403 의 fallback 0 이 「지금 방문 0행」으로
    // 되돌릴 수 없는 삭제 앞에 떴다(#682 S3 라운드 15). 창을 열기만 하고 지우지 않는다.
    await authenticatePage(page, issueScopedToken('core.analytics.update'));
    await page.goto('/admin/analytics/settings?tab=retention');
    const button = page.locator('#purge_all_button');
    await expect(button).toBeEnabled({ timeout: 30_000 });
    await button.click();
    const modal = page.getByRole('dialog', { name: '모든 통계 데이터 삭제' });
    await expect(modal.getByText('저장소 현황은 방문자 통계 조회 권한이 있어야 보입니다.')).toBeVisible({ timeout: 10_000 });
    await expect(modal.getByText(/지금 방문 .*행/)).toHaveCount(0);

    // 대조군 — 조회 권한도 있으면 실제 행 수를 보인다.
    await authenticatePage(page, issueScopedToken('core.analytics.read', 'core.analytics.update'));
    await page.goto('/admin/analytics/settings?tab=retention');
    await expect(button).toBeEnabled({ timeout: 30_000 });
    await button.click();
    await expect(modal.getByText(/지금 방문 [\d,]+행/)).toBeVisible({ timeout: 10_000 });
  });

  // @scenario screen=privacy_requests, state=normal, viewport=1440, locale=ko, e2e=permissions
  // @effects subject_delete_confirm_names_the_subject
  test('정보주체 화면 — 요약의 액션 수가 응답과 같고, 삭제 창이 지울 IP 를 보이며, 삭제 뒤 같은 화면에 남는다', async ({ page }) => {
    // 삭제 창이 액션 조건을 `data-if` 로 적어 삭제 뒤 방문자 목록으로 튕겼고, 대상 줄은 공란이었다. 결과 요약은
    // 서버가 싣지 않는 필드를 세어 「액션 0건」이었다(#682 S3 라운드 15). 이 케이스는 시드를 지우므로 앞뒤로 다시 심는다.
    visitorKey = (runSeed('privacy-visitor').match(/SEED_VISITOR_KEY=(\S+)/) ?? [])[1] ?? '';
    await authenticatePage(page, issueScopedToken('core.analytics.privacy'));

    const lookup = page.waitForResponse(
      (r) => r.url().includes('/api/admin/analytics/privacy/subjects?') && r.request().method() === 'GET',
      { timeout: 30_000 },
    );
    await page.goto('/admin/analytics/privacy-requests?subject=ip&value=203.0.113.77');
    const visits = ((await (await lookup).json())?.data?.visits ?? []) as Array<{ actions_count: number }>;
    const actions = visits.reduce((sum, v) => sum + (Number(v.actions_count) || 0), 0);
    expect(actions, '시드 방문은 액션을 남긴다').toBeGreaterThan(0);
    await expect(page.getByText(`방문 ${visits.length}건 · 액션 ${actions}건`)).toBeVisible({ timeout: 30_000 });

    await page.locator('#delete_button').click();
    const modal = page.getByRole('dialog', { name: '정보주체 기록 삭제' });
    await expect(modal.getByText('203.0.113.77')).toBeVisible({ timeout: 10_000 });
    await modal.locator('input[type="checkbox"]').click();
    await expect(page.locator('#subject_delete_button')).toBeEnabled({ timeout: 10_000 });

    const deleted = page.waitForResponse(
      (r) => r.url().includes('/api/admin/analytics/privacy/subjects') && r.request().method() === 'DELETE',
      { timeout: 20_000 },
    );
    const refetched = page.waitForResponse(
      (r) => r.url().includes('/api/admin/analytics/privacy/subjects?') && r.request().method() === 'GET',
      { timeout: 20_000 },
    );
    await page.locator('#subject_delete_button').click();
    expect((await deleted).status()).toBe(200);
    await refetched;
    await expect(page, '삭제 뒤 정보주체 화면에 남는다').toHaveURL(/\/admin\/analytics\/privacy-requests\?/);

    visitorKey = (runSeed('privacy-visitor').match(/SEED_VISITOR_KEY=(\S+)/) ?? [])[1] ?? '';
  });

  // @scenario screen=privacy_requests, state=empty, viewport=1440, locale=ko, e2e=permissions
  // @effects subject_actions_stay_available_without_core_visits
  test('코어 방문이 0건이어도 검색 응답이 오면 내보내기·삭제를 누를 수 있다', async ({ page }) => {
    // 코어 원장이 보존 기간으로 비었어도 확장은 자기 표에 그 사람의 기록을 가질 수 있고, 서버는 그때도
    // 확장의 정보주체 필터를 부른다 — 화면이 0건에서 버튼을 잠그면 그 요청을 처리할 길이 없다(#682 S4 라운드 6).
    await authenticatePage(page, issueScopedToken('core.analytics.privacy'));

    const lookup = page.waitForResponse(
      (r) => r.url().includes('/api/admin/analytics/privacy/subjects?') && r.request().method() === 'GET',
      { timeout: 30_000 },
    );
    await page.goto('/admin/analytics/privacy-requests?subject=ip&value=2001:db8::682:6');
    const body = await (await lookup).json();
    expect(body?.data?.visits ?? [], '문서용 대역 주소라 코어 방문이 없다').toEqual([]);

    await expect(page.locator('#export_button')).toBeEnabled({ timeout: 30_000 });
    await expect(page.locator('#delete_button')).toBeEnabled();
  });
});
