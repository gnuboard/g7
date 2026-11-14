/**
 * 관리자 마일리지 설정 — 통화별 적립 절사 기준.
 *
 * @scenario mileage-earn-rounding
 * 케이스(마커 아님 — 평문): settings_mileage_earn_rounding
 * 효과 요약(마커 아님 — 평문): 절사 컨트롤 렌더와 화면 옵션 어휘, 저장 후 새로고침 유지,
 *   통화 환산 절사와의 화면 분리. 효과 마킹은 각 테스트 주석에 있다.
 *
 * 배경: 적립 포인트 산출은 종전에 `(int) floor` 로 하드코딩돼 운영자가 조정할 수 없었다.
 *       통화별 마일리지 규칙에 절사 단위(1/10/100)와 방식(버림/반올림/올림)을 두어
 *       주문계산·부분취소 안분이 같은 기준을 따르게 한다.
 *
 * 참고: 관리자 템플릿의 Select 는 options 가 주어지면 네이티브 `<select>` 가 아니라
 *       커스텀 드롭다운(Button + portal `role="listbox"`)으로 렌더된다. 따라서
 *       `selectOption()`/`toHaveValue()` 가 아니라 열기 → `role="option"` 클릭으로 조작한다.
 *
 * 전제(스스로 만든다): 마일리지 규칙 첫 행 = 쇼핑몰 기본 통화. 서버는 첫 행이 기본 통화가 아니면
 *       마일리지 탭 저장을 422("첫 번째 통화는 기본 통화(JPY)여야 합니다")로 거절한다. 공유 개발 DB 는
 *       기본 통화 JPY + 규칙 [KRW] 로 어긋났던 적도, [JPY, KRW] 로 맞춰진 적도 있다(둘 다 실측). 그래서 각
 *       테스트 전에 마일리지 설정 파일을 떠 두고, 첫 행이 기본 통화가 아니면 API 로 기본 통화 행을 첫 행에
 *       세운 뒤, 테스트가 끝나면 떠 둔 파일을 그대로 되돌린다. 대상 행도 KRW 를 박지 않고 기본 통화 행을 쓴다.
 *
 * 화면 대기: 통화 규칙 행은 설정 데이터(GET /admin/settings)가 도착한 뒤에야 그려진다. 공유 서버가 붐빌 때
 *       (실측: 같은 시각 서버 치명 오류가 섞인 실행) 진입부터 행 표시까지 15초를 넘겨 "행 없음" 으로 위장
 *       실패했다. 그래서 그 응답을 신호로 기다린 뒤 행을 단언한다(openMileageTab).
 */
import { existsSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { APIRequestContext, Page } from '@playwright/test';

import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const SETTINGS_URL = '/admin/ecommerce/settings?tab=mileage';
const CURRENCY_URL = '/admin/ecommerce/settings?tab=language_currency';
const SETTINGS_API = '/api/modules/sirsoft-ecommerce/admin/settings';

/** 코어 루트 — 마일리지 설정 파일(storage/app/modules/sirsoft-ecommerce/settings/mileage.json)의 기준. */
const CORE_ROOT = process.env.G7_ROOT || resolve(dirname(fileURLToPath(import.meta.url)), '../../../../../../../');
const MILEAGE_SETTINGS_FILE = resolve(CORE_ROOT, 'storage/app/modules/sirsoft-ecommerce/settings/mileage.json');

/** 대상 행(기본 통화)의 testid — 테스트 전 준비에서 정해진다. */
let UNIT = '';
let METHOD = '';

/** 테스트 전 마일리지 설정 파일 원문 (파일이 없었으면 null) */
let mileageSnapshot: string | null = null;

/**
 * 마일리지 규칙 첫 행이 기본 통화가 되도록 맞추고, 대상 행 testid 를 정한다.
 *
 * @param request Playwright APIRequestContext
 * @param token 설정 조회·수정 토큰
 * @return 없음
 */
async function ensureDefaultCurrencyRuleFirst(request: APIRequestContext, token: string): Promise<void> {
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  const res = await request.get(SETTINGS_API, { headers });
  expect(res.status(), '설정 조회 실패').toBe(200);
  const data = (await res.json())?.data ?? {};
  const base = String(data?.language_currency?.default_currency ?? 'KRW');
  const mileage = data?.mileage ?? {};
  const rules: Array<Record<string, unknown>> = Array.isArray(mileage.currency_rules) ? mileage.currency_rules : [];

  UNIT = `mileage-earn-rounding-unit-${base}`;
  METHOD = `mileage-earn-rounding-method-${base}`;

  if (rules[0]?.currency_code === base) return;

  // 기본 통화 행이 있으면 그 행을, 없으면 첫 행의 값을 기본 통화로 옮겨 첫 행에 세운다
  const baseRule = rules.find((r) => r.currency_code === base) ?? { ...(rules[0] ?? {}), currency_code: base };
  const saved = await request.put(SETTINGS_API, {
    headers,
    data: {
      _tab: 'mileage',
      mileage: { ...mileage, currency_rules: [baseRule, ...rules.filter((r) => r.currency_code !== base)] },
    },
  });
  expect(saved.status(), `전제(첫 행 = 기본 통화 ${base}) 저장 실패: ${await saved.text()}`).toBe(200);
}

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

/**
 * 설정 데이터 응답(GET)을 신호로 기다리며 마일리지 탭을 열거나 새로고침하고, 기본 통화 행이 그려질 때까지 기다린다.
 *
 * @param page Playwright 페이지
 * @param mode 'goto' 면 탭 주소로 진입, 'reload' 면 새로고침
 * @return 없음
 */
async function openMileageTab(page: Page, mode: 'goto' | 'reload' = 'goto'): Promise<void> {
  const settingsLoaded = page.waitForResponse(
    (r) => new URL(r.url()).pathname === '/api/modules/sirsoft-ecommerce/admin/settings' && r.request().method() === 'GET',
    { timeout: 60_000 },
  );
  if (mode === 'reload') {
    await page.reload();
  } else {
    await page.goto(SETTINGS_URL);
  }
  const response = await settingsLoaded;
  expect(response.status(), '설정 데이터를 받지 못했다').toBe(200);
  await expect(page.getByTestId(UNIT)).toBeVisible({ timeout: 30_000 });
}

/**
 * 저장 버튼을 누르고 설정 저장 응답(PUT)이 끝날 때까지 기다린다.
 *
 * 클릭 직후 바로 새로고침하면 저장 요청이 서버에 닿기 전에 페이지가 떠나 요청이 취소되고,
 * 새로고침한 화면은 종전 값을 보여 준다 — 저장 결함과 구분되지 않는 위장 실패다.
 *
 * @param page Playwright 페이지
 * @return 없음
 */
async function saveAndWait(page: Page): Promise<void> {
  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes('/admin/settings') && r.request().method() === 'PUT',
      { timeout: 30_000 },
    ),
    page.getByTestId('ecommerce-settings-save').click(),
  ]);
  expect(response.status(), `설정 저장 실패: ${await response.text()}`).toBe(200);
}

test.describe('관리자 마일리지 설정 — 적립 절사 기준', () => {
  // 토큰 발급(artisan) + 설정 조회 + 화면 진입이 한 테스트에 들어간다 — 붐빌 때 기본 30초로는 모자란다.
  test.describe.configure({ timeout: 120_000 });

  test.beforeEach(async ({ request, settingsToken }) => {
    mileageSnapshot = existsSync(MILEAGE_SETTINGS_FILE) ? readFileSync(MILEAGE_SETTINGS_FILE, 'utf-8') : null;
    await ensureDefaultCurrencyRuleFirst(request, settingsToken);
  });

  // 공유 개발 DB 를 테스트 전 상태로 되돌린다 — 전제 준비와 테스트의 저장을 함께 걷는다.
  test.afterEach(() => {
    if (mileageSnapshot === null) {
      if (existsSync(MILEAGE_SETTINGS_FILE)) unlinkSync(MILEAGE_SETTINGS_FILE);
    } else {
      writeFileSync(MILEAGE_SETTINGS_FILE, mileageSnapshot, 'utf-8');
    }
    mileageSnapshot = null;
  });

  /** @effects settings_screen_options_match_server_vocabulary */
  test('기본 통화 규칙 행에 절사 단위/방식 컨트롤이 렌더된다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await openMileageTab(page);
    await expect(page.getByTestId(METHOD)).toBeVisible();

    // 단위는 정수 포인트만 — 마일리지는 원장에 정수로 확정되므로, 통화 환산 절사의
    // 소수 단위(0.01/0.1)가 선택지로 보이면 고를 수는 있는데 저장이 422 로 막힌다.
    expect(await openAndReadOptions(page, UNIT)).toEqual(['1점', '10점', '100점']);

    // 화면 옵션 ⊆ 서버 허용 어휘(floor/round/ceil)
    expect(await openAndReadOptions(page, METHOD)).toEqual(['버림', '반올림', '올림']);
  });

  /** @effects settings_save_persists_earn_rounding_rule, settings_rounding_persists_across_reload */
  test('절사 기준 변경이 저장되고 새로고침 후에도 유지된다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await openMileageTab(page);

    // 바꿀 값은 현재 값과 달라야 하므로 현재 값을 읽는다 — 기본값(1점/버림)을 가정하지 않는다.
    // 원복은 afterEach 가 설정 파일을 테스트 전 상태로 되돌려 처리한다.
    const originalUnit = (await selectedLabel(page, UNIT).innerText()).trim();
    const originalMethod = (await selectedLabel(page, METHOD).innerText()).trim();
    const nextUnit = originalUnit === '10점' ? '100점' : '10점';
    const nextMethod = originalMethod === '올림' ? '반올림' : '올림';

    await chooseOption(page, UNIT, nextUnit);
    await chooseOption(page, METHOD, nextMethod);
    await saveAndWait(page);

    // 저장 payload 에 키가 빠지면 화면에서 고를 수는 있어도 새로고침하면 되돌아간다.
    await openMileageTab(page, 'reload');
    await expect(selectedLabel(page, UNIT)).toContainText(nextUnit);
    await expect(selectedLabel(page, METHOD)).toContainText(nextMethod);
  });

  /** @effects settings_earn_rounding_separate_from_exchange */
  test('통화 환산 절사(언어/통화 탭)와 섞이지 않는다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);

    // 적립 절사는 마일리지 탭에만 있다
    await openMileageTab(page);

    // 언어/통화 탭에는 적립 절사 컨트롤이 없다 — 두 설정이 한 화면에 섞이면 운영자가
    // 외화 표시 반올림을 바꾸려다 적립 지급 규칙을 함께 바꾼다.
    await page.goto(CURRENCY_URL);
    await expect(page.getByText('환율/통화 설정').first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId(UNIT)).toHaveCount(0);
    await expect(page.getByTestId(METHOD)).toHaveCount(0);
  });
});
