/**
 * E2E: 브라우저 검수에서 짚은 것들 (이슈 #682 S3 라운드 4)
 *
 * 이 넷은 레이아웃 계약 테스트로는 끝까지 잠글 수 없다.
 *
 * - **뒤로 가기**: 「이동이 `replace` 가 아니다」까지는 구운 JSON 으로 잰다. 그러나 뒤로 갔을 때
 *   *바가 주소를 따라오는가*는 라우터·데이터소스·표현식이 함께 도는 브라우저에서만 드러난다 —
 *   실제로 이 라운드에서 push 로 바꾸자 주소는 30일인데 칩·달력은 90일로 남는 회귀가 났다.
 * - **유입 주소 드릴다운**: 라벨을 눌러 `host` 가 실리고 표가 다른 소스로 갈아타는 왕복.
 * - **회원 행위자 메뉴**: 새 창 열기와 검색 칸 재주입은 클릭 위임·라우팅이 함께 돌아야 한다.
 * - **요일·시간대 지표**: 주소에 실린 값이 새 탐색에서 선택 칸으로 복원되는가.
 *
 * 축 요약(마커 아님 — 평문): 뒤로 가기, 드릴다운 왕복, 행위자 메뉴 두 갈래, 지표 복원.
 */
import { authenticatePage, expect, issueToken, test } from '../../fixtures/auth';
import { AnalyticsSettingsGuard, bearer } from '../../fixtures/analytics';

test.describe('#682 라운드 4 — 검수 지적의 브라우저 왕복', () => {
  test.beforeEach(async ({ page }) => {
    // 방문자 목록은 개인정보 권한이 있어야 열린다 — 한 토큰으로 네 축을 모두 돈다.
    await authenticatePage(page, issueToken('core.analytics.read', 'core.analytics.privacy'));
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects period_bar_navigations_are_pushed_so_back_returns_to_the_previous_period
  test('기간을 두 번 바꾼 뒤 뒤로 가기가 주소·칩·달력을 함께 직전 기간으로 되돌린다', async ({ page }) => {
    await page.goto('/admin/analytics');
    await expect(page.locator('#analytics_period_bar')).toBeVisible({ timeout: 30_000 });

    // 달력은 서버가 되돌려 준 구간(`data.meta.from`)을 읽는다 — 클릭 직후에는 직전 응답의 구간이 보이므로
    // 값이 새 구간으로 바뀐 뒤에 읽는다(#682 S3 라운드 14: 개요만 틀린 경로를 읽어 늘 브라우저 계산값이
    // 즉시 보였고, 그래서 이 대기가 없어도 초록이었다).
    const periodFrom = page.locator('#period_from');
    await expect(periodFrom).not.toHaveValue('');
    const sevenFrom = await periodFrom.inputValue();

    await page.locator('#preset_30d').click();
    await expect(page).toHaveURL(/preset=30d/);
    await expect(periodFrom).not.toHaveValue(sevenFrom);
    const thirtyFrom = await periodFrom.inputValue();

    await page.locator('#preset_90d').click();
    await expect(page).toHaveURL(/preset=90d/);
    await expect(page.locator('#preset_90d')).toHaveClass(/active/);
    await expect(periodFrom).not.toHaveValue(thirtyFrom);
    const ninetyFrom = await periodFrom.inputValue();
    // 90일 구간은 30일 구간보다 앞에서 시작한다 — 두 상태가 실제로 다르다는 확인.
    expect(ninetyFrom < thirtyFrom).toBe(true);

    await page.goBack();

    // 주소만 되돌아가고 바가 90일을 가리킨 채 남으면 화면이 주소와 다른 말을 한다.
    await expect(page).toHaveURL(/preset=30d/);
    await expect(page.locator('#preset_30d')).toHaveClass(/active/);
    await expect(page.locator('#preset_90d')).not.toHaveClass(/active/);
    await expect(page.locator('#period_from')).toHaveValue(thirtyFrom);
  });

  // @scenario screen=referrers, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects referring_site_rows_open_the_full_url_list_for_that_host
  test('유입 사이트의 도메인 줄을 누르면 그 도메인의 전체 주소 목록이 열리고 되돌아온다', async ({ page }) => {
    await page.goto('/admin/analytics/referrers?tab=sites&preset=90d');
    await expect(page.locator('#analytics_report_grid')).toBeVisible({ timeout: 30_000 });

    // 반복 셀 안의 data-ref 는 id 로 나오지 않는다(같은 id 가 행마다 생기므로) — 표 안의 링크로 집는다.
    const firstLabel = page.locator('#analytics_report_grid tbody tr td button.text-link').first();
    // 이 기간에 유입 사이트가 한 줄도 없으면 드릴다운을 잴 대상이 없다.
    test.skip((await firstLabel.count()) === 0, '이 기간에 유입 사이트 행이 없다 — 드릴다운을 잴 행이 없다');
    await expect(firstLabel).toBeVisible({ timeout: 20_000 });
    const host = (await firstLabel.innerText()).trim();

    const urlsRequest = page.waitForResponse(
      (r) => r.url().includes('/api/admin/analytics/referrer-urls'),
      { timeout: 20_000 },
    );
    await firstLabel.click();
    expect((await urlsRequest).status()).toBe(200);

    await expect(page).toHaveURL(new RegExp(`host=${encodeURIComponent(host).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));
    await expect(page.locator('#referrer_urls_card')).toBeVisible();
    // 주소 목록을 보는 동안 CSV 는 사이트 목록을 주므로 감춘다.
    await expect(page.locator('#csv_download_button')).toHaveCount(0);

    await page.locator('#referrer_urls_back').click();
    await expect(page.locator('#analytics_report_grid')).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('#referrer_urls_card')).toHaveCount(0);
  });

  // @scenario screen=visitors, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects visitor_list_shows_members_by_name_with_an_actor_menu
  test('회원 방문자의 이름을 눌러 「이 회원으로 검색」이 목록을 그 회원으로 좁힌다', async ({ page }) => {
    await page.goto('/admin/analytics/visitors?preset=90d');
    await expect(page.locator('#analytics_visitors_grid')).toBeVisible({ timeout: 30_000 });

    // 회원 방문이 한 줄도 없으면 이 축은 잴 것이 없다 — 데이터에 기대지 않고 건너뛴다.
    const menu = page.locator('#analytics_visitors_grid [role="button"]').filter({ hasText: '@' }).first();
    const hasMember = await menu.count();
    test.skip(hasMember === 0, '이 기간에 로그인 회원 방문이 없다 — 회원 칸을 잴 행이 없다');

    await menu.click();
    const search = page.getByText('이 회원으로 검색', { exact: true });
    await expect(search).toBeVisible({ timeout: 10_000 });

    const listRequest = page.waitForResponse(
      (r) => r.url().includes('/api/admin/analytics/visitors') && r.url().includes('q='),
      { timeout: 20_000 },
    );
    await search.click();
    expect((await listRequest).status()).toBe(200);

    await expect(page).toHaveURL(/[?&]q=\d+/);
    // 검색 칸에도 그 회원이 앉아야 한 번 더 누르지 않고 조건이 보인다(활동 로그와 같은 규약).
    await expect(page.locator('#visitors_search')).not.toHaveValue('');
  });

  // @scenario screen=patterns, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects patterns_metric_lives_in_the_query_not_in_screen_state
  test('요일·시간대의 지표가 주소에 실리고 그 주소로 새로 열어도 유지된다', async ({ page }) => {
    await page.goto('/admin/analytics/patterns');
    await expect(page.locator('#metric_select')).toBeVisible({ timeout: 30_000 });

    await page.locator('#metric_select').click();
    await page.getByRole('option', { name: '페이지뷰' }).click();
    await expect(page).toHaveURL(/metric=pageviews/);

    // 새 탐색(화면 상태가 사라진다) — 주소가 출처라면 선택 칸이 그대로다.
    await page.goto('/admin/analytics/patterns?metric=pageviews');
    await expect(page.locator('#metric_select')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('#metric_select')).toContainText('페이지뷰');
  });
});

test.describe('#682 라운드 6 — 마스킹 표시·회원 이동·CSV 파일명', () => {
  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects masked_ip_is_labelled_as_masked
  test('IP 마스킹을 켜면 방문자 상세·정보주체 화면이 그 사실을 함께 보인다', async ({ page }) => {
    // 이 축은 계약 테스트로 못 닫는다 — 서버가 내려준 값이 화면 분기를 타고 배지·열 이름까지
    // 도달하는지가 관건이고, 그 경로는 설정 저장 → 재조회 → 렌더가 함께 돌아야 드러난다.
    const token = issueToken('core.analytics.read', 'core.analytics.privacy', 'core.analytics.update');
    await authenticatePage(page, token);
    const guard = new AnalyticsSettingsGuard(page.request, token);

    await guard.apply({ ip_mask_octets: 2 });
    try {
      await page.goto('/admin/analytics/visitors?preset=90d');
      await expect(page.locator('#analytics_visitors_grid')).toBeVisible({ timeout: 30_000 });

      const firstKey = page.locator('#analytics_visitors_grid a, #analytics_visitors_grid [role="button"]').first();
      test.skip((await firstKey.count()) === 0, '이 기간에 방문 행이 없다 — 상세로 들어갈 행이 없다');

      const detail = page.waitForResponse(
        (r) => r.url().includes('/api/admin/analytics/visitors/') && r.status() === 200,
        { timeout: 30_000 },
      );
      await firstKey.click();
      const body = await (await detail).json();
      expect(body?.data?.ip_mask_octets, '서버가 마스킹 옥텟 수를 실어야 화면이 알 수 있다').toBe(2);

      await expect(page.locator('#visitor_card')).toBeVisible({ timeout: 30_000 });
      await expect(page.locator('#visitor_card')).toContainText('마스킹');

      // 정보주체 화면의 IP 열 이름도 같은 사실을 말한다(같은 부류 전량).
      await page.goto('/admin/analytics/privacy-requests?subject=ip&value=127.0.0.1');
      const subjects = page.waitForResponse(
        (r) => r.url().includes('/api/admin/analytics/privacy/subjects'),
        { timeout: 30_000 },
      );
      expect((await subjects).status()).toBe(200);
      await expect(page.getByText('IP (마스킹됨)')).toBeVisible({ timeout: 20_000 });
    } finally {
      await guard.restore();
    }
  });

  // @scenario screen=realtime, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects member_jump_is_gated_by_destination_permission
  test('현재 접속자 응답이 회원 이름·uuid 를 실어 회원 화면으로 갈 수 있다', async ({ page }) => {
    const token = issueToken('core.analytics.read', 'core.analytics.privacy', 'core.users.read');
    await authenticatePage(page, token);

    const snapshot = page.waitForResponse(
      (r) => r.url().includes('/api/admin/analytics/realtime') && r.status() === 200,
      { timeout: 30_000 },
    );
    await page.goto('/admin/analytics/realtime');
    const body = await (await snapshot).json();

    const rows: Array<Record<string, unknown>> = body?.data?.visitors ?? [];
    const member = rows.find((r) => r?.user && (r.user as Record<string, unknown>)?.uuid);
    test.skip(!member, '지금 접속 중인 회원이 없다 — 이 축은 잴 행이 없다');

    const user = member!.user as Record<string, string>;
    expect(user.uuid, 'uuid 가 없으면 화면이 회원 화면으로 갈 수 없다').toBeTruthy();
    expect(user.name).toBeTruthy();
    await expect(page.locator('#realtime_visitors_grid')).toContainText(user.name, { timeout: 20_000 });
  });

  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects visitor_csv_filename_carries_the_period
  test('방문자 단건 CSV 파일 이름에 조회 기간이 실린다', async ({ page }) => {
    const token = issueToken('core.analytics.read', 'core.analytics.privacy');
    await authenticatePage(page, token);

    const key = 'e'.repeat(32);
    const res = await page.request.get(
      `/api/admin/analytics/visitors/${key}?format=csv&from=2026-09-01&to=2026-09-10`,
      { headers: bearer(token) },
    );
    expect(res.status()).toBe(200);
    // 기간이 없으면 같은 사람을 두 기간으로 받았을 때 앞 파일을 덮는다(부록 D).
    expect(res.headers()['content-disposition']).toContain('2026-09-01_2026-09-10');
  });

  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects site_timezone_badge_shows_only_when_the_viewer_timezone_differs
  test('진단·설정 화면의 시간대 배지가 보는 사람의 시간대가 다를 때만 뜬다', async ({ page }) => {
    // 통계의 모든 시각은 사이트 표준시로 나간다. 두 화면은 공통 경고 조각을 쓰지 않아
    // (「설정에서 켜세요」가 순환 안내가 된다) 각자 배지로 그 사실을 알린다 — 진단 화면은
    // 시각을 여섯 자리 찍으면서 기준을 한 줄도 밝히지 않고 있었다(#682 S3 라운드 7).
    //
    // 서버가 계정 시간대와 사이트 표준시를 견주므로, 브라우저에서 확인하려면 계정 쪽을
    // 실제로 바꿔야 한다. 레이아웃 계약 테스트는 「배지가 있다」까지만 잠근다.
    const token = issueToken('core.analytics.read', 'core.analytics.update');
    await authenticatePage(page, token);

    // 프로필 갱신은 부분 수정이 아니다 — 이름·이메일이 없으면 422 로 거절한다.
    // 시간대만 실어 보내면 이 축은 영영 빨갛다(#682 S3 라운드 8 실측).
    const profile = (await (await page.request.get('/api/me', { headers: bearer(token) })).json())?.data;
    const original = profile?.timezone ?? null;

    const setTimezone = async (timezone: string) => {
      const res = await page.request.put('/api/me', {
        headers: bearer(token),
        data: { name: profile?.name, email: profile?.email, timezone },
      });
      expect(res.ok(), `프로필 갱신 실패 ${res.status()}: ${await res.text()}`).toBe(true);
    };

    try {
      // ① 사이트와 같은 시간대 — 군더더기라 띄우지 않는다.
      await setTimezone('Asia/Seoul');
      await page.goto('/admin/analytics/diagnostics');
      await expect(page.locator('#status_cards')).toBeVisible({ timeout: 30_000 });
      await expect(page.locator('#site_timezone_badge')).toHaveCount(0);

      // ② 다른 시간대 — 두 화면 모두 기준을 밝힌다.
      await setTimezone('Europe/London');
      await page.goto('/admin/analytics/diagnostics');
      await expect(page.locator('#site_timezone_badge')).toBeVisible({ timeout: 30_000 });

      await page.goto('/admin/analytics/settings');
      await expect(page.locator('#settings_tabs')).toBeVisible({ timeout: 30_000 });
      await expect(page.locator('#site_timezone_badge')).toBeVisible({ timeout: 30_000 });
    } finally {
      // 계정은 반드시 되돌린다 — 이 설치는 개발자가 계속 쓰는 개발 환경이다.
      await setTimezone(original ?? 'Asia/Seoul');
    }
  });
});

test.describe('#682 라운드 8 — 실측이 드러낸 자리', () => {
  // @scenario screen=settings, state=normal, viewport=1440, locale=ko, e2e=settings_privacy
  // @effects opt_out_button_says_what_pressing_it_does
  test('「내 방문은 세지 않기」 버튼이 자기가 하는 일을 말한다', async ({ page }) => {
    // 배지는 상태를, 버튼은 행동을 말해야 한다. 삼항이 뒤집혀 있어 세는 중인데 「제외 끄기」가
    // 보였다 — 구운 JSON 으로도 잠갔지만, 누른 뒤 글자가 실제로 뒤집히는지는 브라우저에서만
    // 드러난다(#682 S3 라운드 8: 눌렀더니 opted_out false→true 인데 글자는 그대로 반대였다).
    const token = issueToken('core.analytics.read', 'core.analytics.update');
    await authenticatePage(page, token);

    const optOut = async (method: 'POST' | 'DELETE') => {
      const res = await page.request.fetch('/api/admin/analytics/opt-out', { method, headers: bearer(token) });
      expect(res.ok(), `opt-out ${method}`).toBe(true);
    };

    await optOut('DELETE'); // 전제 — 세는 중에서 시작한다.
    try {
      await page.goto('/admin/analytics/settings?tab=collection');
      const badge = page.locator('#opt_out_badge');
      const button = page.locator('#opt_out_button');
      await expect(badge).toBeVisible({ timeout: 30_000 });

      // 세는 중 → 버튼은 「걸기」를 내민다.
      const countingBadge = (await badge.innerText()).trim();
      const countingLabel = (await button.innerText()).trim();

      await button.scrollIntoViewIfNeeded();
      await button.click();
      await expect(badge).not.toHaveText(countingBadge, { timeout: 20_000 });

      // 제외됨 → 버튼은 「풀기」를 내민다. 두 글자가 서로 달라야 방향이 산 것이다.
      const excludedLabel = (await button.innerText()).trim();
      expect(excludedLabel, '상태가 바뀌었는데 버튼 글자가 그대로면 방향이 죽어 있다').not.toBe(countingLabel);

      const res = await page.request.get('/api/admin/analytics/opt-out', { headers: bearer(token) });
      expect((await res.json())?.data?.opted_out, '제외가 실제로 걸렸다').toBe(true);
    } finally {
      await optOut('DELETE');
    }
  });

  // @scenario screen=overview, state=consent_fallback, viewport=1440, locale=ko, e2e=overview
  // @effects anonymous_fallback_is_surfaced_whatever_the_chosen_mode
  test('익명으로 내려간 사유가 있으면 개요가 그 사실을 알린다', async ({ page }) => {
    // 서버가 `full` 모드에서도 익명으로 내릴 수 있다(동의 플러그인이 쿠키를 막을 때).
    // 종전 게이트는 `consent` 모드만 봐서 그 경우 한 면도 안 떴다 — 실제로 익명으로 모으는데
    // 운영자는 설정에 적힌 「전체 수집」만 본다(#682 S3 라운드 8 실측).
    const token = issueToken('core.analytics.read');
    await authenticatePage(page, token);

    const diag = await page.request.get('/api/admin/analytics/diagnostics', { headers: bearer(token) });
    const collection = (await diag.json())?.data?.collection ?? {};
    test.skip(
      !collection.anonymous_fallback_reason,
      '이 설치는 지금 익명으로 내려가 있지 않다 — 띄울 사유가 없다',
    );

    await page.goto('/admin/analytics?preset=7d');
    await expect(page.locator('#analytics_period_bar')).toBeVisible({ timeout: 30_000 });
    // 문구는 사유마다 다르다(동의 플러그인이면 「동의하지 않은 방문자는 익명」 — #682 S4 라운드 8) — 안내 요소가
    // 뜨고 익명 수집을 말하는지만 본다.
    const alert = page.locator('#alert_consent_fallback');
    await expect(alert, '사유가 실렸는데 안내가 없으면 운영자는 익명 수집을 모른 채 지표를 읽는다').toBeVisible({ timeout: 20_000 });
    await expect(alert).toContainText('익명');
  });
});

/**
 * #682 실측 후속 4 — 필터 초기화 · 전체 삭제 확인 창의 경고 아이콘.
 *
 * 초기화는 구운 JSON 으로 「키를 지우는 이동이 있다」까지 잰다(analyticsFilterReset.test.ts). 여기서는 그 이동 뒤
 * **입력칸·선택·칩이 주소를 따라 비워지는가**와 **다른 영역의 조건이 남는가**를 브라우저에서 잰다 — 입력칸 값이
 * 주소를 다시 읽지 않으면 주소는 비었는데 칸에 옛 검색어가 남는다.
 * 경고 아이콘은 클래스 조합이 아니라 실제로 그려진 글리프 크기와 원 안 위치를 잰다.
 */
test.describe('#682 실측 후속 4 — 필터 초기화 · 경고 아이콘', () => {
  // @scenario screen=visitors, state=normal, viewport=1440, locale=ko, e2e=overview
  // @effects every_filter_area_has_a_reset_that_clears_its_keys, filter_reset_is_locked_when_nothing_to_clear
  test('방문자 초기화는 검색어·유형만 걷고 기간은 남기며, 걷은 뒤 잠긴다', async ({ page }) => {
    await authenticatePage(page, issueToken('core.analytics.read', 'core.analytics.privacy'));
    await page.goto('/admin/analytics/visitors?q=1&type=member&preset=30d&page=2');

    const reset = page.locator('#visitors_filter_reset');
    const periodReset = page.locator('#period_reset_button');
    await expect(reset).toBeEnabled({ timeout: 30_000 });
    await expect(page.locator('input[name="analytics_visitors_q"]')).toHaveValue('1');

    await reset.click();

    await expect(page).not.toHaveURL(/[?&](q|type)=/);
    await expect(page).toHaveURL(/preset=30d/);
    await expect(page.locator('input[name="analytics_visitors_q"]')).toHaveValue('');
    await expect(page.locator('#visitors_toolbar')).toContainText('전체');
    await expect(page.locator('#preset_30d')).toHaveClass(/active/);
    await expect(reset).toBeDisabled();
    // 기간은 다른 영역의 조건이다 — 방문자 초기화가 건드리지 않았으니 기간 바 초기화는 여전히 켜져 있다.
    await expect(periodReset).toBeEnabled();

    await periodReset.click();
    await expect(page).not.toHaveURL(/preset=/);
    await expect(page.locator('#preset_7d')).toHaveClass(/active/);
    await expect(periodReset).toBeDisabled();
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko, e2e=settings_privacy
  // @effects purge_confirm_warning_icon_is_centered_at_its_size
  test('전체 삭제 확인 창의 경고 아이콘은 원 가운데에 제 크기로 그려진다', async ({ page }) => {
    await authenticatePage(page, issueToken('core.analytics.read', 'core.analytics.update'));
    await page.goto('/admin/analytics/settings?tab=retention');

    // 창을 열기만 한다 — 확인 체크 없이는 실행 버튼이 잠겨 있어 데이터는 지워지지 않는다.
    await page.locator('#purge_all_button').click();
    // 구조와 무관하게 잰다 — 경고 글리프에서 출발해, 배경색과 둥근 모서리를 가진 가장 가까운 자신/조상을 원으로 본다.
    // (원 자산을 아이콘에 직접 준 옛 모양에서는 아이콘 자신이 원이다 — 클래스 부재로 실패하지 않고 증상으로 실패해야 한다.)
    const glyph = page.getByRole('dialog').locator('i.fa-triangle-exclamation').first();
    await expect(glyph).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('#purge_execute_button')).toBeDisabled();

    const m = await glyph.evaluate((i) => {
      let circleEl: HTMLElement | null = i as HTMLElement;
      while (circleEl) {
        const cs = getComputedStyle(circleEl);
        if (cs.backgroundColor !== 'rgba(0, 0, 0, 0)' && parseFloat(cs.borderTopLeftRadius) > 0) break;
        circleEl = circleEl.parentElement;
      }
      const c = (circleEl ?? i).getBoundingClientRect();
      const g = i.getBoundingClientRect();
      const fontSize = parseFloat(getComputedStyle(i).fontSize);
      // 글리프는 글자 크기 한 줄 높이로 그려진다 — 요소 상자가 글자보다 크면(옛 모양) 글리프는 상자 맨 위 줄에 놓인다.
      const glyphTop = g.height > fontSize * 1.5 ? g.top : g.top + g.height / 2 - fontSize / 2;
      return {
        circle: c.height,
        fontSize,
        glyph: getComputedStyle(i, '::before').content,
        dx: Math.abs(g.left + g.width / 2 - (c.left + c.width / 2)),
        dy: Math.abs(glyphTop + fontSize / 2 - (c.top + c.height / 2)),
      };
    });
    expect(m.glyph, '글리프가 없다(빈 아이콘)').not.toBe('none');
    // 원(48px)의 절반 크기 — 옛 모양은 본문 글자 크기(14px)로 원 위쪽에 붙었다.
    expect(m.fontSize).toBeGreaterThanOrEqual(m.circle / 2);
    expect(m.dx).toBeLessThanOrEqual(2);
    expect(m.dy).toBeLessThanOrEqual(2);

    await page.keyboard.press('Escape');
  });
});

test.describe('정보주체 요청 — 값 없이 찾기', () => {
  // @scenario screen=privacy_requests, state=empty, viewport=1440, locale=ko, e2e=overview
  // @effects subject_search_is_locked_without_a_value, subject_search_prompt_is_shown_before_query
  test('값이 비면 찾기가 잠기고 안내 카드가 보이며, 값을 넣으면 결과 카드로 바뀐다', async ({ page }) => {
    // 값 없이 찾기를 눌러도 화면이 그대로라 목록이 안 뜨는 고장으로 읽혔다 — 잠금·안내·Enter 무시를 한 번에 잰다.
    await authenticatePage(page, issueToken('core.analytics.read', 'core.analytics.privacy'));
    const subjectCalls: string[] = [];
    page.on('request', (r) => {
      if (r.url().includes('/api/admin/analytics/privacy/subjects')) subjectCalls.push(r.url());
    });

    await page.goto('/admin/analytics/privacy-requests');
    const search = page.locator('#search_button');
    const input = page.locator('input[name="analytics_subject_value"]');
    await expect(page.locator('#subjects_prompt')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('#subjects_prompt')).toContainText('찾을 사람을 지정하세요');
    await expect(page.locator('#results_card')).toHaveCount(0);
    await expect(search).toBeDisabled();

    // 공백만 넣어도 잠긴 채이고, Enter 로도 질의가 생기지 않는다.
    await input.fill('   ');
    await expect(search).toBeDisabled();
    await input.press('Enter');
    await expect(page).not.toHaveURL(/[?&]value=/);
    expect(subjectCalls).toHaveLength(0);

    await input.fill('127.0.0.1');
    await expect(search).toBeEnabled();
    const subjects = page.waitForResponse((r) => r.url().includes('/api/admin/analytics/privacy/subjects'), { timeout: 30_000 });
    await search.click();
    expect((await subjects).status()).toBe(200);
    await expect(page).toHaveURL(/value=127\.0\.0\.1/);
    await expect(page.locator('#results_card')).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('#subjects_prompt')).toHaveCount(0);
  });
});
