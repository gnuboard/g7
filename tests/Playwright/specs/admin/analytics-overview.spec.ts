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

test.describe('방문자 통계 — 빈 화면과 방문자 상세 (#682 S4 라운드 8)', () => {
  // @scenario screen=referrers, state=empty, viewport=1440, locale=ko, e2e=overview
  // @effects empty_grid_shows_one_empty_message
  test('기록이 없는 기간의 유입 사이트 탭은 빈 안내를 한 번만 보이고 쪽 번호를 그리지 않는다', async ({ page }) => {
    await authenticatePage(page, issueToken('core.analytics.read'));
    const report = page.waitForResponse((r) => r.url().includes('/api/admin/analytics/reports/referrer_name'), { timeout: 30_000 });
    // 통계를 들이기 전의 과거 이틀 — 원장·집계 어디에도 기록이 없다.
    await page.goto('/admin/analytics/referrers?tab=sites&from=2020-01-01&to=2020-01-02');
    const body = await (await report).json();
    expect(body?.data?.data ?? [], '전제: 이 기간은 비어 있다').toEqual([]);

    const empty = page.locator('#report_empty');
    await expect(empty).toBeVisible({ timeout: 20_000 });
    const title = ((await empty.locator('h2').innerText()) ?? '').trim();
    await expect(page.getByText(title, { exact: true }), '빈 안내 문구는 한 번만').toHaveCount(1);
    await expect(page.locator('#analytics_report_grid'), '빈 표·쪽 번호는 그리지 않는다').toHaveCount(0);
    // 진단으로 가는 버튼은 버튼 모양(`.btn`)이어야 한다 — 색 클래스만 있으면 테두리만 남는다.
    await expect(page.locator('#report_empty_diagnostics_link')).toHaveClass(/(^|\s)btn(\s|$)/);
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects quality_cards_share_one_shape
  test('개요 품질 줄의 네 카드(이탈률·평균 체류·방문당 페이지·신규/재방문)는 높이와 윗선이 같다', async ({ page }) => {
    // 신규·재방문 카드는 비율 막대 때문에 StatCard 대신 직접 만든 카드다 — 틀이 다르면 줄 안에서 혼자 낮고 모양이 달랐다.
    await authenticatePage(page, issueToken('core.analytics.read'));
    const ids = ['quality_bounce', 'quality_time', 'quality_pages', 'quality_new_returning'];
    // 두 모양을 다 잰다 — 숫자·비율 막대(쿠키로 가른 칸이 있을 때)와 「구분할 수 없습니다」(전부 익명일 때).
    // 숫자 쪽은 응답 점에 신규·재방문 값을 실어 만든다(개발 DB 의 수집 모드와 무관하게).
    let shape: 'numbers' | 'unavailable' = 'numbers';
    await page.route('**/api/admin/analytics/overview**', async (route) => {
      const response = await route.fetch();
      const json = await response.json();
      for (const p of json?.data?.series?.points ?? []) {
        p.new_visitors = shape === 'numbers' ? 12345 : null;
        p.returning_visitors = shape === 'numbers' ? 6789 : null;
      }
      await route.fulfill({ response, json });
    });
    for (const [width, s] of [[1440, 'numbers'], [375, 'numbers'], [1440, 'unavailable'], [375, 'unavailable']] as const) {
      shape = s;
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/admin/analytics?preset=7d');
      await expect(page.locator('#quality_new_returning')).toContainText(s === 'numbers' ? '· ' : '구분할 수 없습니다', { timeout: 30_000 });
      const boxes = await Promise.all(ids.map(async (id) => (await page.locator(`#${id}`).boundingBox())!));
      const heights = boxes.map((b) => Math.round(b.height));
      expect(Math.max(...heights) - Math.min(...heights), `${width}px ${s} 높이 ${heights.join('/')}`).toBeLessThanOrEqual(1);
      // 1440 은 한 줄 네 칸, 375 는 두 칸씩 두 줄 — 같은 줄 카드끼리 윗선이 같다.
      const rows = width >= 1024 ? [[0, 1, 2, 3]] : [[0, 1], [2, 3]];
      for (const row of rows) {
        const tops = row.map((i) => Math.round(boxes[i].y));
        expect(Math.max(...tops) - Math.min(...tops), `${width}px ${s} 윗선 ${tops.join('/')}`).toBeLessThanOrEqual(1);
      }
    }
    // 화면이 뒤이어 다시 받는 개요 요청이 닫힌 페이지에서 가로채지지 않게 푼다.
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects visitor_detail_screen_shows_visit_details
  test('방문자 상세는 방문을 펼치면 해상도 등 부수정보를 보인다', async ({ page }) => {
    const token = issueToken('core.analytics.privacy');
    await authenticatePage(page, token);
    const list = await page.request.get('/api/admin/analytics/visitors?preset=30d&per_page=50', {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const rows: any[] = (await list.json())?.data?.data ?? [];
    let target: { key: string; resolution: string } | null = null;
    for (const row of rows) {
      const detail = await page.request.get(`/api/admin/analytics/visitors/${row.visitor_key}?preset=30d`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      const visit = (await detail.json())?.data?.visits?.[0];
      if (visit?.screen_resolution) {
        target = { key: row.visitor_key, resolution: visit.screen_resolution };
        break;
      }
    }
    test.skip(target === null, '최근 30일에 해상도가 기록된 방문이 없다');

    await page.goto(`/admin/analytics/visitors/${target!.key}?preset=30d`);
    await expect(page.locator('#visitor_card_resolution, #card_resolution_row')).toContainText(target!.resolution, { timeout: 30_000 });
    await page.locator('#visit_item_0 button').first().click();
    await expect(page.locator('#visit_info_0')).toContainText(target!.resolution);

    // 라벨 폭이 달라도 값은 열마다 한 줄로 시작하고, 라벨(작은 글자)과 값의 기준선이 맞는다(#682 S4 라운드 8).
    const geometry = await page.locator('#visit_info_0').evaluate((grid) => {
      // 행 요소의 클래스와 무관하게 짝(라벨·값 span)을 잰다 — 모양 규칙이 아니라 보이는 위치가 기준이다.
      const spans = Array.from(grid.querySelectorAll(':scope > div > span')) as HTMLElement[];
      const pairs: Array<{ label: DOMRect; value: DOMRect }> = [];
      for (let i = 0; i + 1 < spans.length; i += 2) pairs.push({ label: spans[i].getBoundingClientRect(), value: spans[i + 1].getBoundingClientRect() });
      return pairs.map((p) => ({ valueLeft: Math.round(p.value.left), labelLeft: Math.round(p.label.left), bottomGap: Math.abs(p.label.bottom - p.value.bottom), valueHeight: p.value.height }));
    });
    expect(geometry.length, '방문 정보 짝').toBeGreaterThanOrEqual(10);
    const columns = new Set(geometry.map((g) => g.labelLeft));
    const valueStarts = new Set(geometry.map((g) => g.valueLeft));
    expect(valueStarts.size, `값 시작 위치(열 ${columns.size}개)`).toBe(columns.size);
    for (const g of geometry.filter((x) => x.valueHeight < 24)) expect(g.bottomGap, '라벨·값 기준선').toBeLessThanOrEqual(2);
  });

  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects stored_codes_are_shown_as_human_labels
  test('수집 진단의 집계 실행 이력은 실행 주체를 코드(scheduled·manual·on_view)가 아니라 사람 말로 보인다', async ({ page }) => {
    await authenticatePage(page, issueToken('core.analytics.read'));
    const runs = page.waitForResponse((r) => r.url().includes('/api/admin/analytics/archive-runs'), { timeout: 30_000 });
    await page.goto('/admin/analytics/diagnostics');
    const body = await (await runs).json();
    test.skip((body?.data?.data ?? []).length === 0, '집계 실행 이력이 없다');

    const grid = page.locator('#archive_runs_grid');
    // 첫 그림은 불러오기 전의 빈 줄이다 — 행이 채워질 때까지 기다린다.
    await expect(grid.locator('tbody'), '실행 주체가 사람 말로 보인다').toContainText(/자동\(예약\)|수동|화면 열 때/, { timeout: 30_000 });
    const text = await grid.locator('tbody').innerText();
    expect(text, '실행 주체 코드가 그대로 보이지 않는다').not.toMatch(/\b(scheduled|manual|on_view)\b/);
  });
});
