/**
 * E2E: if 로 갈린 값 칸에 흐림이 붙은 flex 행의 배치 (engine-v1.99.2)
 *
 * 흐림 래퍼(`blur_until_loaded`)가 if 거짓 노드에도 빈 <div> 로 남아, 양 끝 정렬(`flex-between`) 행의 세 번째
 * 자식이 되어 값이 가운데로 밀렸다. 텍스트·존재만 보는 단위 시험·실측은 이를 놓쳤으므로(7.2.0·7.2.1 출시 실측 통과),
 * 이 spec 은 **배치**(행 자식 수·값 오른쪽 끝 좌표)를 받기 전과 받은 뒤, 그리고 응답을 바꿔 만든 데이터 상태 변종
 * (건수 추정치)에서 잰다.
 *
 * @scenario-manifest tests/scenarios/blur-wrapper-if-false.yaml
 */
import { test, expect, issueToken, authenticatePage, type Page } from '../../fixtures/auth';

const PERMISSIONS = ['core.settings.read', 'core.users.read'];

/**
 * 첫 자식 텍스트가 label 인 flex 행의 배치를 잽니다.
 *
 * @param page Playwright 페이지
 * @param selector 행 셀렉터
 * @param label 첫 자식 텍스트(없으면 id 로 찾은 행)
 * @return 자식 수·빈 div 수·마지막 보이는 자식의 오른쪽 간격
 */
async function rowLayout(page: Page, selector: string, label: string | null) {
  return page.evaluate(({ selector, label }) => {
    const rows = [...document.querySelectorAll(selector)] as HTMLElement[];
    const row = label === null ? rows[0] : rows.find((r) => r.firstElementChild?.textContent?.trim() === label);
    if (!row) return null;
    const kids = [...row.children] as HTMLElement[];
    const visible = kids.filter((c) => c.textContent!.trim() !== '');
    const last = visible[visible.length - 1];
    return {
      children: kids.length,
      emptyDivs: kids.filter((c) => c.tagName === 'DIV' && c.childNodes.length === 0).length,
      rightGap: Math.round(row.getBoundingClientRect().right - last.getBoundingClientRect().right),
      firstLeftGap: Math.round(visible[0].getBoundingClientRect().left - row.getBoundingClientRect().left),
    };
  }, { selector, label });
}

test.describe('흐림이 붙은 if 분기 flex 행의 배치', () => {
  test.setTimeout(120_000);
  let pageErrors: string[];
  let token: string;

  test.beforeEach(async ({ page }) => {
    token = issueToken(...PERMISSIONS);
    await authenticatePage(page, token);
    await page.addInitScript(() => {
      try { localStorage.setItem('g7_locale', 'ko'); } catch { /* storage 접근 불가 환경은 무시 */ }
    });
    pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
  });

  /**
   * @scenario case=received_if_false_row
   * @effects blur_wrapper_absent_when_if_false
   */
  test('정보 탭 메모리·디스크 값은 받은 뒤 CPU 행처럼 오른쪽 끝에 붙는다', async ({ page }) => {
    await page.goto('/admin/settings?tab=info');
    await expect(page.locator('.flex-between', { hasText: '디스크' }).first()).toContainText(/GB|측정/, { timeout: 90_000 });

    for (const label of ['CPU', '메모리', '디스크']) {
      const m = await rowLayout(page, '.flex-between', label);
      expect(m, label).not.toBeNull();
      expect(m!.children, `${label} 행 자식 수`).toBe(2);
      expect(m!.emptyDivs, `${label} 빈 div`).toBe(0);
      expect(Math.abs(m!.rightGap), `${label} 값 오른쪽 간격`).toBeLessThanOrEqual(1);
    }
    expect(pageErrors).toEqual([]);
  });

  /**
   * @scenario case=pending_if_true_row
   * @effects blur_wrapper_kept_when_rendered
   */
  test('받기 전에는 받는 중 자리표시가 흐림 래퍼째 값 자리에 있다', async ({ page }) => {
    let release: () => void = () => {};
    const gate = new Promise<void>((r) => { release = r; });
    await page.route('**/api/admin/settings/system-info*', async (route) => { await gate; await route.continue(); });
    await page.goto('/admin/settings?tab=info');
    const row = page.locator('.flex-between', { hasText: '메모리' }).first();
    await expect(row).toBeVisible({ timeout: 90_000 });
    const m = await rowLayout(page, '.flex-between', '메모리');
    expect(m!.children).toBe(2);
    expect(Math.abs(m!.rightGap)).toBeLessThanOrEqual(1);
    release();
    await expect(row).toContainText(/GB|측정/, { timeout: 60_000 });
    expect((await rowLayout(page, '.flex-between', '메모리'))!.children).toBe(2);
  });

  /**
   * 데이터 상태 변종 — 응답을 바꿔 건수 추정치(total_is_exact=false)를 만든다.
   *
   * @scenario case=estimated_count_row
   * @effects blur_wrapper_absent_when_if_false
   */
  test('회원 목록 건수가 추정치여도 건수 문구는 왼쪽 끝에 있다', async ({ page, request }) => {
    // 라우트 처리 중에 원래 요청을 다시 보내면(route.fetch·page.request·request) 이 개발 서버에서 응답 없이 멈춰
    // (2026-11-25 실측) 목록 응답을 진입 전에 한 번 받아 두고, 화면의 목록 요청에는 건수만 추정치로 바꿔 돌려준다
    const res = await request.get('/api/admin/users?per_page=10', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
    expect(res.ok()).toBe(true);
    const body = await res.json();
    body.data.pagination.total_is_exact = false;
    await page.route('**/api/admin/users?*', (route) => route.fulfill({ status: 200, json: body }));
    await page.goto('/admin/users');
    const toolbar = page.locator('#list_toolbar');
    // 받기 전에는 정확 건수 분기가 참이라 행이 정상이다 — 추정치 문구가 뜬 뒤(받은 뒤)에 잰다
    await expect(toolbar).toContainText('이상', { timeout: 90_000 });
    const m = await rowLayout(page, '#list_toolbar', null);
    expect(m!.children).toBe(2);
    expect(m!.emptyDivs).toBe(0);
    expect(Math.abs(m!.firstLeftGap)).toBeLessThanOrEqual(1);
    expect(pageErrors).toEqual([]);
  });
});
