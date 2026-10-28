/** 예약 경계·복귀·SPA 및 닫기 보존. 기존 팝업·공통 설정은 수정하지 않는다. */
import { test, expect, auth, createHtmlPopup, gotoUser, layer, API, VIEWPORTS } from '../fixtures/popup-auth';

for (const viewport of ['desktop', 'mobile'] as const) {
  // @scenario surface=scheduled_refresh_e2e
  // @effects refresh_keeps_dismissal, test_data_cleaned_up
  test(`예약 경계와 탭 복귀 갱신 ${viewport}`, async ({ page, request, managerToken, created, context }) => {
    test.setTimeout(90000);
    await page.setViewportSize(VIEWPORTS[viewport]);
    // 기존 팝업은 이 테스트의 브라우저에서만 숨긴다. 서버 데이터·공통 설정은 보존한다.
    const publicList = await request.get(`${API}/popups`);
    const before = (await publicList.json()).data.popups;
    const now = Math.floor(Date.now() / 1000);
    await context.addCookies([{ name: 'g7_popup_dismissed', value: encodeURIComponent(JSON.stringify(Object.fromEntries(before.map((p: any) => [p.id, now + 3600])))), url: test.info().project.use.baseURL as string }]);
    const id = await createHtmlPopup(request, managerToken, { is_active: false, page_scope: 'routes', routes: ['/page/about'] });
    created.push(id);
    // 상세 응답의 사이트 시각으로 시작·종료를 정해 호스트/브라우저 시간대에 기대지 않는다.
    const detail = (await (await request.get(`${API}/admin/popups/${id}`, { headers: auth(managerToken) })).json()).data;
    const summary = (await (await request.get(`${API}/admin/popups`, { headers: auth(managerToken) })).json());
    const timezone = summary.meta?.timezone ?? summary.data?.meta?.timezone ?? 'Asia/Seoul';
    const format = (ms: number) => new Intl.DateTimeFormat('sv-SE', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).format(new Date(ms)).replace(' ', 'T');
    await gotoUser(page, '/page/about');
    const payload = { ...detail, is_active: true, display_period: 'range', starts_at: format(Date.now() + 8000), ends_at: format(Date.now() + 19000) };
    expect((await request.put(`${API}/admin/popups/${id}`, { headers: auth(managerToken), data: payload })).status()).toBe(200);
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    let requests = 0;
    page.on('request', req => { if (new URL(req.url()).pathname === `${API}/popups`) requests++; });
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await expect(page.locator(`[data-popup-id="${id}"]`)).toHaveCount(0);
    await expect(page.locator(`[data-popup-id="${id}"]`)).toBeVisible({ timeout: 15000 });
    await expect(page.locator(`[data-popup-id="${id}"]`)).toHaveCount(0, { timeout: 16000 });
    expect(requests).toBeLessThanOrEqual(4); // 최초 + 시작 + 종료, 경계 중 재진입 여유만 허용
    // 같은 팝업을 재활성화하고 복귀 이벤트 경로를 명시적으로 검증한다.
    const current = (await (await request.get(`${API}/admin/popups/${id}`, { headers: auth(managerToken) })).json()).data;
    expect((await request.put(`${API}/admin/popups/${id}`, { headers: auth(managerToken), data: { ...current, display_period: 'always' } })).status()).toBe(200);
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await expect(page.locator(`[data-popup-id="${id}"]`)).toBeVisible();
    await layer(page).getByRole('button', { name: '닫기', exact: true }).click();
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await expect(page.locator(`[data-popup-id="${id}"]`)).toHaveCount(0);
    await page.getByRole('contentinfo').getByRole('link', { name: '홈', exact: true }).click();
    await page.waitForURL(url => url.pathname === '/');
    await page.getByRole('heading', { name: '그누보드7에 오신 것을 환영합니다', exact: true }).waitFor();
    await page.getByRole('link', { name: '회사소개', exact: true }).click();
    await page.waitForURL(url => url.pathname === '/page/about');
    await expect(page.locator(`[data-popup-id="${id}"]`)).toHaveCount(0);
    await page.reload();
    await expect(page.locator(`[data-popup-id="${id}"]`)).toBeVisible({ timeout: 15000 });
    expect(errors).toEqual([]);
  });
}
