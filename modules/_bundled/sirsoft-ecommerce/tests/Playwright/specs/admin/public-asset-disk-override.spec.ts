/**
 * E2E: 이커머스 공개 자산 디스크 오버라이드 필드 (공개#100)
 *
 * 시나리오 매니페스트: `tests/scenarios/public-asset-cdn.yaml`
 * (케이스 마킹은 각 test 의 라인 주석에 있다 — 축 구분자는 쉼표)
 *
 * 기본정보 탭의 "공개 자산 디스크" Select 가 [코어 설정 따름('')] + 코어 카탈로그를
 * 그리는지, 그리고 선택→저장→재진입 왕복에서 값이 유지되는지 검증한다.
 * 코어 드라이버 탭 카드는 admin_basic 템플릿 spec 이 담당한다 — 여기서는 모듈이
 * 소유한 오버라이드 표면만 본다.
 *
 * composite Select 는 네이티브 select 가 아니라 커스텀 드롭다운(root div 에 name 속성,
 * Button 토글, portal listbox role=option)이다 — 상호작용은 그 구조를 따른다.
 * 저장 후에는 반드시 원값으로 되돌려 사이트 설정을 바꾸지 않는다.
 *
 * @effects settings_catalog_includes_plugin_registered_disks,
 *          none_override_forces_streaming_over_global
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

const ECOMMERCE_BASIC_TAB = '/admin/ecommerce/settings?tab=basic_info';
const MODULE_SELECT_ROOT = '[name="basic_info.public_asset_disk"]';

/**
 * 기본정보 탭 진입 — 카탈로그 적재를 엔진 상태로 확인한다.
 *
 * DOM attach 만 기다리면 바인딩 전 빈 Select 를 카탈로그 누락으로 오인한다.
 *
 * @param page Playwright 페이지
 */
async function gotoBasicInfoTab(page: Page): Promise<void> {
  await page.goto(ECOMMERCE_BASIC_TAB);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect
    .poll(
      async () =>
        await page.evaluate(() => {
          const local = (window as any).G7Core?.state?.getLocal?.();
          return Array.isArray(local?.form?.available_public_asset_disks);
        }),
      { timeout: 20_000 },
    )
    .toBe(true);
}

/**
 * 커스텀 드롭다운을 열고 옵션 라벨 목록을 읽는다 (읽은 뒤 드롭다운은 닫지 않음).
 *
 * @param page Playwright 페이지
 * @returns 옵션 라벨 배열
 */
async function openAndReadOptions(page: Page): Promise<string[]> {
  await page.locator(`${MODULE_SELECT_ROOT} button`).first().click();
  const listbox = page.locator('[role="listbox"]');
  await expect(listbox).toBeVisible({ timeout: 10_000 });

  return listbox.locator('[role="option"]').allInnerTexts();
}

/**
 * 엔진 로컬 상태에서 오버라이드 필드 값을 읽는다.
 *
 * @param page Playwright 페이지
 * @returns 현재 폼 값 (미설정이면 '')
 */
async function readOverrideValue(page: Page): Promise<unknown> {
  return page.evaluate(() => {
    const form = (window as any).G7Core?.state?.getLocal?.()?.form;
    return form?.basic_info?.public_asset_disk ?? '';
  });
}

/**
 * 드롭다운에서 라벨로 옵션을 고른다.
 *
 * @param page Playwright 페이지
 * @param label 고를 옵션 라벨(부분 일치)
 */
async function selectOptionByLabel(page: Page, label: string): Promise<void> {
  await openAndReadOptions(page);
  await page.locator('[role="listbox"] [role="option"]', { hasText: label }).first().click();
}

/** 저장 버튼을 눌러 성공 토스트를 기다린다. */
async function save(page: Page): Promise<void> {
  await page.locator('#save_button button, button#save_button, [data-testid="settings-save-button"]').first().click();
  await expect(page.getByText('설정이 저장되었습니다')).toBeVisible({ timeout: 20_000 });
}

test.describe('이커머스 공개 자산 디스크 오버라이드', () => {
  // @scenario consumer=product, disk_setting=none, e2e=ecommerce_settings_field, hook=unregistered, override=follow_core, row_state=legacy_local_row
  // @effects settings_catalog_includes_plugin_registered_disks
  test('코어 따름 기본 옵션 + 코어 카탈로그를 그린다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await gotoBasicInfoTab(page);

    await expect(page.locator(MODULE_SELECT_ROOT)).toBeVisible({ timeout: 20_000 });

    const labels = await openAndReadOptions(page);
    // 첫 옵션 = 코어 설정 따름('') + 카탈로그(none 포함 — 강제 스트리밍 오버라이드)
    expect(labels[0]).toContain('코어 설정 따름');
    expect(labels.join('|')).toContain('사용 안 함');
    expect(labels.join('|')).toContain('Amazon S3');

    await page.keyboard.press('Escape');
  });

  // @scenario consumer=product, disk_setting=public, e2e=save_roundtrip, hook=unregistered, override=module_override, row_state=new_remote_row
  // @effects none_override_forces_streaming_over_global
  test('선택-저장-재진입 왕복에서 오버라이드 값이 유지되고 원복된다', async ({ page, settingsToken }) => {
    test.setTimeout(150_000);
    await authenticatePage(page, settingsToken);
    await gotoBasicInfoTab(page);

    const original = (await readOverrideValue(page)) as string;

    // 모듈 개별 오버라이드로 Public 디스크 지정 → 저장
    await selectOptionByLabel(page, 'Public');
    await expect.poll(() => readOverrideValue(page)).toBe('public');
    await save(page);

    // 재진입 — 서버 저장값이 폼으로 복원되는지 (저장 응답의 카탈로그 재부착 포함)
    await gotoBasicInfoTab(page);
    await expect.poll(() => readOverrideValue(page), { timeout: 20_000 }).toBe('public');

    // 원복 (사이트 설정 보존)
    const restoreLabel =
      original === 'public' ? 'Public' : original === 's3' ? 'Amazon S3' : original === 'none' ? '사용 안 함' : '코어 설정 따름';
    await selectOptionByLabel(page, restoreLabel);
    await save(page);

    await gotoBasicInfoTab(page);
    await expect.poll(() => readOverrideValue(page), { timeout: 20_000 }).toBe(original || '');
  });
});
