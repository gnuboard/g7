/**
 * 관리자 마일리지 설정 — 통화별 적립 절사 기준.
 *
 * @scenario settings_mileage_earn_rounding
 * @effects settings_controls_render_in_both_viewports,
 *          settings_screen_options_match_server_vocabulary,
 *          settings_rounding_persists_across_reload,
 *          settings_earn_rounding_separate_from_exchange
 *
 * 배경: 적립 포인트 산출은 종전에 `(int) floor` 로 하드코딩돼 운영자가 조정할 수 없었다.
 *       통화별 마일리지 규칙에 절사 단위(1/10/100)와 방식(버림/반올림/올림)을 두어
 *       주문계산·부분취소 안분이 같은 기준을 따르게 한다.
 *
 * 참고: 관리자 템플릿의 Select 는 options 가 주어지면 네이티브 `<select>` 가 아니라
 *       커스텀 드롭다운(Button + portal `role="listbox"`)으로 렌더된다. 따라서
 *       `selectOption()`/`toHaveValue()` 가 아니라 열기 → `role="option"` 클릭으로 조작한다.
 */
import type { Page } from '@playwright/test';

import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const SETTINGS_URL = '/admin/ecommerce/settings?tab=mileage';
const CURRENCY_URL = '/admin/ecommerce/settings?tab=language_currency';

const UNIT = 'mileage-earn-rounding-unit-KRW';
const METHOD = 'mileage-earn-rounding-method-KRW';

/** 커스텀 드롭다운을 열고 옵션 라벨 목록을 읽는다. */
async function openAndReadOptions(page: Page, testId: string): Promise<string[]> {
  await page.getByTestId(testId).getByRole('button').click();
  const listbox = page.getByRole('listbox');
  await expect(listbox).toBeVisible({ timeout: 5_000 });

  const labels = await listbox.getByRole('option').allInnerTexts();
  await page.keyboard.press('Escape');

  return labels.map((t) => t.trim());
}

/** 커스텀 드롭다운에서 라벨로 옵션을 고른다. */
async function chooseOption(page: Page, testId: string, label: string): Promise<void> {
  await page.getByTestId(testId).getByRole('button').click();
  await expect(page.getByRole('listbox')).toBeVisible({ timeout: 5_000 });
  await page.getByRole('listbox').getByRole('option', { name: label, exact: true }).click();
}

/** 드롭다운 버튼에 표시된 현재 선택 라벨. */
function selectedLabel(page: Page, testId: string) {
  return page.getByTestId(testId).getByRole('button');
}

test.describe('관리자 마일리지 설정 — 적립 절사 기준', () => {
  test('기본 통화 규칙 행에 절사 단위/방식 컨트롤이 렌더된다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);

    await expect(page.getByTestId(UNIT)).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId(METHOD)).toBeVisible();

    // 단위는 정수 포인트만 — 마일리지는 원장에 정수로 확정되므로, 통화 환산 절사의
    // 소수 단위(0.01/0.1)가 선택지로 보이면 고를 수는 있는데 저장이 422 로 막힌다.
    expect(await openAndReadOptions(page, UNIT)).toEqual(['1점', '10점', '100점']);

    // 화면 옵션 ⊆ 서버 허용 어휘(floor/round/ceil)
    expect(await openAndReadOptions(page, METHOD)).toEqual(['버림', '반올림', '올림']);
  });

  test('절사 기준 변경이 저장되고 새로고침 후에도 유지된다', async ({ page, settingsToken }) => {
    // 저장 → 새로고침 → 원복 → 새로고침 4왕복이라 기본 30초로는 모자란다.
    test.setTimeout(120_000);

    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await expect(page.getByTestId(UNIT)).toBeVisible({ timeout: 15_000 });

    await chooseOption(page, UNIT, '10점');
    await chooseOption(page, METHOD, '올림');

    await page.getByTestId('ecommerce-settings-save').click();

    // 저장 payload 에 키가 빠지면 화면에서 고를 수는 있어도 새로고침하면 되돌아간다.
    await page.reload();
    await expect(selectedLabel(page, UNIT)).toContainText('10점', { timeout: 15_000 });
    await expect(selectedLabel(page, METHOD)).toContainText('올림');

    // 원복 — 다른 spec 이 기본값(1점/버림)을 전제한다
    await chooseOption(page, UNIT, '1점');
    await chooseOption(page, METHOD, '버림');
    await page.getByTestId('ecommerce-settings-save').click();
    await page.reload();
    await expect(selectedLabel(page, UNIT)).toContainText('1점', { timeout: 15_000 });
    await expect(selectedLabel(page, METHOD)).toContainText('버림');
  });

  test('통화 환산 절사(언어/통화 탭)와 섞이지 않는다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);

    // 적립 절사는 마일리지 탭에만 있다
    await page.goto(SETTINGS_URL);
    await expect(page.getByTestId(UNIT)).toBeVisible({ timeout: 15_000 });

    // 언어/통화 탭에는 적립 절사 컨트롤이 없다 — 두 설정이 한 화면에 섞이면 운영자가
    // 외화 표시 반올림을 바꾸려다 적립 지급 규칙을 함께 바꾼다.
    await page.goto(CURRENCY_URL);
    await expect(page.getByText('환율/통화 설정').first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId(UNIT)).toHaveCount(0);
    await expect(page.getByTestId(METHOD)).toHaveCount(0);
  });
});
