/**
 * sirsoft-admin_basic — 관리자 입력칸의 접근 가능한 이름 (#757).
 *
 * 결함: 메뉴 관리 스위치는 「스위치, 켬」만, 역할 등록의 권한 그룹 체크박스는 「체크박스」만, 역할 목록 행 스위치는 이름 없이,
 *   회원 목록 정렬 선택 상자는 고른 값(「가입일시 최근순」)만 읽혔다. 검색 필터 칩은 실제 체크박스가 display:none 이라
 *   키보드로 닿지 않았다.
 *
 * 컴포넌트 시험은 jsdom 에서 이름 계산을 보고, 레이아웃 잠금 시험은 굽기 산출물의 속성만 본다. 이 spec 은 실제 화면에서
 *   엔진이 `$t:common.row_cell|row=…|column=…` 같은 이름을 풀어내는지, 접근성 트리의 이름이 기대 문구와 같은지
 *   (`getByRole(…, { name })` — 접근성 트리가 SSoT) 잰다. 원문 키(`$t:`)·보간(`{{`)이 새면 붉어진다.
 *
 * 시나리오 매니페스트: `tests/scenarios/control-accessible-names.yaml`
 * @effects controls_have_accessible_name, listbox_reads_name_and_value, row_controls_read_row_and_column
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page } from '@playwright/test';

/** 화면 진입 — 한국어로 고정하고 데이터가 다 올 때까지 기다린다. */
async function open(page: Page, token: string, path: string): Promise<void> {
  await authenticatePage(page, token);
  await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  await page.goto(path);
  await page.waitForLoadState('networkidle', { timeout: 30_000 });
}

/** 접근성 이름이 비었거나 원문 키·보간이 샌 요소 수 */
async function leakedNames(page: Page, selector: string): Promise<string[]> {
  return page.locator(selector).evaluateAll((els) =>
    els
      .map((el) => {
        const labelledBy = el.getAttribute('aria-labelledby');
        const byIds = labelledBy
          ? labelledBy.split(/\s+/).map((id) => document.getElementById(id)?.textContent ?? '').join(' ')
          : '';
        return (el.getAttribute('aria-label') ?? '') || byIds;
      })
      .filter((name) => name.trim() === '' || name.includes('$t:') || name.includes('{{')),
  );
}

test.describe('@sirsoft-admin_basic 입력칸 이름 (#757)', () => {
  // @scenario component=sortable_menu_item
  // @effects controls_have_accessible_name
  test('메뉴 관리 스위치가 「{메뉴명} 사용」으로 읽힌다', async ({ page }) => {
    await open(page, issueToken('core.menus.read', 'core.menus.update'), '/admin/menus');
    const switches = page.getByRole('switch', { name: /\S+ 사용$/ });
    await expect(switches.first()).toBeVisible({ timeout: 15_000 });
    expect(await leakedNames(page, '[role="switch"]')).toEqual([]);
  });

  // @scenario component=permission_tree
  // @effects controls_have_accessible_name
  test('역할 추가의 권한 그룹 체크박스가 「{그룹명} 전체 선택」으로 읽힌다', async ({ page }) => {
    await open(page, issueToken('core.permissions.read', 'core.permissions.create'), '/admin/roles/create');
    const groups = page.getByRole('checkbox', { name: /\S+ 전체 선택$/ });
    await expect(groups.first()).toBeAttached({ timeout: 15_000 });
    expect(await groups.count()).toBeGreaterThan(0);
  });

  // @scenario component=role_list_row_switch
  // @effects row_controls_read_row_and_column
  test('역할 목록 행 스위치가 「{역할명} 상태」로 읽힌다', async ({ page }) => {
    await open(page, issueToken('core.permissions.read', 'core.permissions.update'), '/admin/roles');
    const rowSwitch = page.getByRole('switch', { name: /\S+ 상태$/ });
    await expect(rowSwitch.first()).toBeVisible({ timeout: 15_000 });
    expect(await leakedNames(page, '[role="switch"]')).toEqual([]);
  });

  // @scenario component=select
  // @effects listbox_reads_name_and_value
  test('회원 목록 정렬 선택 상자가 「정렬 기준 {현재 값}」으로 읽힌다', async ({ page, userManageToken }) => {
    await open(page, userManageToken, '/admin/users');
    await expect(page.getByRole('button', { name: '정렬 기준 가입일시 최근순' })).toBeVisible({ timeout: 15_000 });
    // 쪽당 개수 선택지의 보이는 글자는 `admin.users.per_page|count=10` → 「10개」(기본 10)
    await expect(page.getByRole('button', { name: '페이지당 표시 개수 10개' })).toBeVisible();
  });

  // @scenario component=tab_navigation
  // @effects listbox_reads_name_and_value
  test('좁은 화면(390px) 환경설정 탭 선택 상자가 값만이 아니라 「섹션 이동 {현재 탭}」으로 읽힌다', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await open(page, issueToken('core.settings.read'), '/admin/settings');
    const select = page.getByRole('button', { name: /^섹션 이동 \S/ });
    await expect(select.first()).toBeVisible({ timeout: 15_000 });
  });

  // @scenario component=chip_checkbox
  // @effects controls_have_accessible_name
  test('검색 필터 칩 체크박스에 키보드 포커스가 가고 Space 로 바뀐다', async ({ page }) => {
    // 칩은 이커머스 주문 목록 필터에 있다 — 이 템플릿의 ChipCheckbox 가 그린다
    await open(page, issueToken('sirsoft-ecommerce.orders.read'), '/admin/ecommerce/orders');
    const chip = page.locator('label input[type="checkbox"].sr-only').first();
    await expect(chip).toBeAttached({ timeout: 15_000 });
    const before = await chip.isChecked();
    await chip.focus();
    await expect(chip).toBeFocused();
    await page.keyboard.press('Space');
    await expect(chip).toBeChecked({ checked: !before });
    const ringClass = await chip.evaluate((el) => el.closest('label')?.className ?? '');
    expect(ringClass).toContain('has-[:focus-visible]:ring-2');
    // 원래 상태로 되돌린다
    await page.keyboard.press('Space');
    await expect(chip).toBeChecked({ checked: before });
  });
});
