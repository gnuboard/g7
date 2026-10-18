/**
 * E2E: 언어팩 관리의 쪽 이동
 *
 * @scenario envelope_slot=pagination, surface=language_pack_list, consumer=pager_buttons, position=last
 * @effects page_numbers_visible, page_navigates, last_page_shows_real_remainder
 *
 * 「보호된 언어팩 제외」를 풀면 번들·코어 팩까지 잡혀 목록이 여러 쪽이 된다.
 * 그런데 쪽 단추가 1번 하나만 그려져 2쪽 이후를 볼 수 없었다 — 목록 API 가
 * 쪽 정보를 `meta` 에만 담고 화면이 읽는 `pagination` 자리는 비워 둔 탓이다.
 * 아래 「총 N개 · 1-10 표시」 는 `meta` 를 읽어 정상이라, 눈으로는 총 건수는
 * 맞는데 이동만 안 되는 모순으로 보였다.
 *
 * 응답에 쪽 정보가 실제로 실렸는지(계약)와, 그 값으로 화면이 쪽을 그리고
 * 눌러서 이동하는지(동작)를 함께 본다.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const LIST_URL = '/api/admin/language-packs?exclude_protected=0&per_page=10';
const PAGE_URL = '/admin/settings?tab=language_packs&exclude_protected=0&per_page=10';

// @scenario envelope_slot=pagination, surface=language_pack_list, consumer=pager_buttons, position=last
// @effects page_numbers_visible, page_navigates, last_page_shows_real_remainder
test('#677 후속 - 보호된 언어팩까지 펼치면 쪽 단추가 모두 그려지고 눌러서 이동한다', async ({ page }) => {
  const token = issueToken('core.language_packs.manage');
  await authenticatePage(page, token);

  const res = await page.request.get(LIST_URL, { headers: { Authorization: `Bearer ${token}` } });
  expect(res.ok(), `목록 조회 실패: ${res.status()}`).toBeTruthy();
  const pagination = (await res.json())?.data?.pagination;

  // 쪽 정보를 화면이 읽는 자리에 담는다는 계약 자체를 먼저 고정한다.
  expect(pagination, '응답에 pagination 이 없다 — 화면이 마지막 쪽 수를 알 수 없다').toBeTruthy();
  expect(typeof pagination.last_page).toBe('number');

  test.skip(pagination.last_page < 2, '언어팩이 한 쪽에 다 들어가 쪽 이동 시나리오의 전제가 성립하지 않는다');

  await page.goto(PAGE_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  // 페이저 루트는 Div(role=navigation) 이라 태그가 아니라 역할·이름으로 찾는다.
  const nav = page.getByRole('navigation', { name: '페이지네이션' }).first();
  await expect(nav).toBeVisible({ timeout: 30_000 });

  // 마지막 쪽 번호까지 단추가 있어야 한다 — 1번 하나만 남던 것이 이 결함이었다.
  await expect(nav.getByRole('button', { name: `페이지 ${pagination.last_page}`, exact: true })).toBeVisible();

  // 눌러서 실제로 그 쪽으로 넘어가는지 — 주소와 아래 표시 줄 둘 다로 확인한다.
  await nav.getByRole('button', { name: '페이지 2', exact: true }).click();
  await expect(page).toHaveURL(/[?&]page=2\b/, { timeout: 30_000 });

  const from = (pagination.per_page ?? 10) + 1;
  await expect(page.getByText(new RegExp(`${from}\\s*-\\s*\\d+`)).first()).toBeVisible({ timeout: 30_000 });
});
