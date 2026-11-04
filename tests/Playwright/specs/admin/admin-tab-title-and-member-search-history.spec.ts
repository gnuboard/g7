/**
 * E2E: 관리자 브라우저 탭 제목 · 회원 상세 「최근 검색어」 구획 (코어 7.1.5 · engine-v1.88.0)
 *
 * 관리자 화면 탭 제목은 「{화면 제목} - {사이트 이름} 관리자」다(종전에는 어느 화면에서나 셸 제목 「{앱 이름} - Admin」).
 * 화면을 옮기면(SPA) 앞 화면 제목이 남지 않는다. 회원 상세에는 「최근 검색어」 탭·구획이 있고, 확장 탭이 많아도
 * 탭 막대가 한 줄로 서고 넘치면 가로로 스크롤된다(종전에는 탭 이름이 글자 단위로 세로로 쪼개졌다).
 *
 * 단위 시험(TemplateApp.documentTitle·admin-user-detail-search-history)이 규칙을 잠그고, 여기서는 실제 셸·번들·레이아웃으로 본다.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/** 관리자 탭 제목 꼴 — 「{화면 제목} - {사이트 이름} 관리자」 */
const adminTitle = (screen: string): RegExp => new RegExp(`^${screen} - .+ 관리자$`);

test.describe('관리자 탭 제목·회원 상세 최근 검색어', () => {
  // @scenario case=admin_screen_title
  // @effects admin_title_uses_layout_title_with_admin_suffix, admin_title_falls_back_to_route_title
  test('관리자 화면마다 탭 제목이 「{화면 제목} - {사이트 이름} 관리자」로 바뀌고 SPA 이동 뒤 앞 화면 제목이 남지 않는다', async ({ page }) => {
    test.setTimeout(150_000);
    const token = issueToken('core.users.read');
    await authenticatePage(page, token);

    await page.goto('/admin/dashboard');
    await expect(page).toHaveTitle(adminTitle('대시보드'), { timeout: 30_000 });

    const settings = await page.request.get('/api/admin/settings', { headers: { Authorization: `Bearer ${token}` } });
    test.skip(!settings.ok(), '환경설정 읽기 권한 없음');

    // SPA 이동 — 사이드바 「환경설정」 링크
    await page.getByRole('link', { name: '환경설정' }).first().click();
    await expect(page).toHaveURL(/\/admin\/settings/, { timeout: 30_000 });
    await expect(page).toHaveTitle(adminTitle('환경설정'), { timeout: 30_000 });
    await expect(page).not.toHaveTitle(/대시보드/);
  });

  // @scenario case=admin_user_detail
  // @effects admin_detail_core_section_shows_core_scope_only, admin_detail_empty_scope_keeps_section
  test('회원 상세에 「최근 검색어」 탭·구획이 있고 탭 막대가 한 줄로 선다', async ({ page }) => {
    test.setTimeout(150_000);
    const token = issueToken('core.users.read');
    await authenticatePage(page, token);

    const list = await page.request.get('/api/admin/users?per_page=1', { headers: { Authorization: `Bearer ${token}` } });
    expect(list.ok()).toBeTruthy();
    const uuid = (await list.json())?.data?.data?.[0]?.uuid;
    test.skip(!uuid, '회원이 없다');

    const detail = await page.request.get(`/api/admin/users/${uuid}`, { headers: { Authorization: `Bearer ${token}` } });
    expect(detail.ok()).toBeTruthy();
    const searchHistory = (await detail.json())?.data?.search_history;
    expect(searchHistory, '상세 응답에 search_history 가 실린다').toBeTruthy();
    expect(Object.keys(searchHistory.scopes)).toContain('core.site');

    await page.goto(`/admin/users/${uuid}`);
    await expect(page).toHaveTitle(adminTitle('사용자 정보 상세'), { timeout: 30_000 });

    const section = page.locator('#search_history_info');
    await expect(section).toBeVisible({ timeout: 30_000 });
    await expect(section.getByRole('heading', { name: '최근 검색어' })).toBeVisible();

    const coreCount = (searchHistory.scopes['core.site']?.items ?? []).length;
    if (coreCount === 0) {
      await expect(section.getByText('저장된 검색어가 없습니다.')).toBeVisible();
    } else {
      await expect(section.locator('li')).toHaveCount(coreCount);
    }

    const tabList = page.getByRole('tablist').first();
    await expect(tabList.getByRole('tab', { name: '최근 검색어' })).toBeVisible();
    const box = await tabList.boundingBox();
    expect(box?.height ?? 999, '탭 막대가 한 줄(글자 단위 줄바꿈 없음)').toBeLessThan(80);
  });
});
