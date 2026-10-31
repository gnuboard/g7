/**
 * sirsoft-admin_basic — 목록 필터 기간 칸의 읽기 이름 (#747 리베이스 후속).
 *
 * 결함: 곁 라벨 이름을 자동으로 잇는 훅은 라벨 하나 뒤에 칸이 둘 이상이면 어느 칸에도 잇지 않는다
 *   (「가입일」 을 두 칸에 똑같이 달면 둘을 구별할 수 없다). 그래서 회원 목록의 기간 칸이 화면 낭독기에서
 *   이름 없이 「날짜」 로만 읽혔다. 레이아웃이 칸마다 `$t:common.range_start|label=…` 로 이름을 단다.
 *
 * 레이아웃 시험은 소스의 aria-label 표현식만 본다. 이 spec 은 엔진이 그 표현식을 실제 화면에서
 *   「가입일 시작일」 처럼 풀어내는지 — 원문 키(`$t:`)·보간(`{{`)이 새지 않는지 — 를 로케일별로 잰다.
 *
 * 시나리오 매니페스트: `tests/scenarios/range-date-input-names.yaml`
 * @effects range_date_inputs_named
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';

/** 회원 목록 필터의 기간 칸 — [시작 칸 name, 종료 칸 name, 한국어 라벨]. */
const KO_RANGES: Array<[string, string, string]> = [
  ['createdStartDate', 'createdEndDate', '가입일'],
  ['lastLoginStartDate', 'lastLoginEndDate', '최근 로그인'],
  ['dormantStartDate', 'dormantEndDate', '휴면 전환일'],
  ['statusChangedStart', 'statusChangedEnd', '상태 변경일'],
];

test.describe('@sirsoft-admin_basic 목록 필터 기간 칸 이름 (#747)', () => {
  // @scenario case=c02
  // @effects range_date_inputs_named
  test('회원 목록 기간 칸이 칸마다 「{라벨} 시작일/종료일」 로 읽힌다', async ({ page, userManageToken }) => {
    await authenticatePage(page, userManageToken);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.goto('/admin/users');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 가입일 외 기간은 상세검색 영역에 있다.
    await page.getByRole('button', { name: '상세검색' }).click();
    await expect(page.locator('input[name="lastLoginStartDate"]')).toBeVisible({ timeout: 15_000 });

    for (const [start, end, label] of KO_RANGES) {
      await expect(page.locator(`input[name="${start}"]`)).toHaveAccessibleName(`${label} 시작일`);
      await expect(page.locator(`input[name="${end}"]`)).toHaveAccessibleName(`${label} 종료일`);
    }

    // 화면의 모든 날짜 칸에 이름이 있고, 원문 키·보간이 새지 않는다.
    const leaked = await page.locator('input[type="date"]').evaluateAll((els) =>
      els
        .map((el) => el.getAttribute('aria-label') ?? '')
        .filter((name) => name.trim() === '' || name.includes('$t:') || name.includes('{{')),
    );
    expect(leaked).toEqual([]);
  });

  // @scenario case=c02
  // @effects range_date_inputs_named
  test('영어 화면에서도 같은 칸이 「{label} start date/end date」 로 읽힌다', async ({ page, userManageToken }) => {
    await authenticatePage(page, userManageToken);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'en'));
    await page.goto('/admin/users');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await expect(page.locator('input[name="createdStartDate"]')).toHaveAccessibleName('Joined start date');
    await expect(page.locator('input[name="createdEndDate"]')).toHaveAccessibleName('Joined end date');
  });
});
