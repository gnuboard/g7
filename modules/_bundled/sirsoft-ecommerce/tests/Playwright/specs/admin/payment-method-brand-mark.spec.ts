/**
 * 주문설정 결제수단 — 브랜드 마크 렌더 회귀.
 *
 * 배경: 관리자 결제수단 목록은 아이콘 열을 `Icon name={{$method._cached_icon}}` 하나로만 그렸고,
 * 각 PG 플러그인이 관리자 전용 주입 스크립트로 자기 결제수단 행의 아이콘을 브랜드 배지로 바꿔 끼웠다.
 * #639 에서 주입 스크립트를 지우고, 목록이 카탈로그 `_cached_brand_mark` 를 `BrandMark` 로 직접 그린다
 * (넓은 화면 표 `_payment_methods_list`, 좁은 화면 카드 `_payment_methods_cards`).
 *
 * 이 spec 이 고정하는 두 가지:
 *  1. 브랜드 마크를 선언한 결제수단 행에 마크가 그려진다(행 루트 `data-payment-method` 안의 BrandMark).
 *  2. **다른 탭에 갔다 돌아와도** 마크가 그대로다. 주입 스크립트 시절에는 replaceState 탭 전환 뒤
 *     배지가 사라진 채 남았다 — 레이아웃이 그리는 지금은 탭 복귀가 그냥 다시 렌더다.
 *
 * 측정 규율:
 *  - 결제수단 ID·PG명을 하드코딩하지 않는다. 설치 구성은 환경마다 다르므로 브랜드 마크를
 *    결제수단 id 별 **런타임 카운트**로 판정하고, 마크가 0인 환경(간편결제 플러그인 미설치)은
 *    skip 이 아니라 "행이 렌더됐는지" 를 먼저 확인해 거짓 통과를 만들지 않는다.
 *
 * 마킹은 쉼표로 축을 나눈다 — 마커 파서가 `,` 로만 분리하므로, 저장소에 흔한 `×` 표기는
 * 첫 축만 잡히고 나머지가 값 문자열에 묻혀 cross product 커버로 집계되지 않는다.
 *
 * @scenario extension_payment_method, method_kind=extension, capability_declared=declared, capability=brand_mark
 * @effects admin_shows_brand_mark, admin_rebinds_brand_mark_after_tab_return
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

const ORDER_SETTINGS_URL = '/admin/ecommerce/settings?tab=order_settings';

/**
 * 렌더된 결제수단 행 수.
 *
 * 행 판정에 `pg-locked-badge-*` 하나만 쓰지 않는다 — 그 배지는 `pg_locked === true` 인 수단에만
 * 렌더되므로, PG 고정 수단이 하나도 없는 구성에서는 화면이 정상 렌더돼도 0 이 되어 준비 게이트가
 * 영원히 열리지 않는다(설치 구성을 하드코딩하지 않는다는 이 spec 의 규율과 어긋난다).
 *
 * PG 열은 비고아 행마다 정확히 하나의 분기를 그린다 — pg_locked / PG 셀렉트 / PG 미설치 /
 * PG 불필요. 네 접두사를 합치면 설치 구성과 무관하게 비고아 행 수와 일치한다.
 */
const PG_CELL_TESTID_PREFIXES = [
  'pg-locked-badge-',
  'pg-select-',
  'pg-not-installed-',
  'pg-not-required-',
] as const;

async function rowCount(page: Page): Promise<number> {
  return page.evaluate(
    (prefixes) =>
      prefixes.reduce(
        (sum, prefix) => sum + document.querySelectorAll(`[data-testid^="${prefix}"]`).length,
        0,
      ),
    PG_CELL_TESTID_PREFIXES as unknown as string[],
  );
}

/**
 * 결제수단 id 별 브랜드 마크 수.
 *
 * BrandMark 는 색 배지(`span`) 또는 SVG 로고(`div`)를 `aria-hidden="true"` 와 고정 크기 인라인 스타일
 * (`flex: 0 0 Npx`)로 그린다. 보이는 행 루트(`data-payment-method`) 안에서 그 모양만 센다 — 아이콘
 * 폴백(`<i>`)과 숨겨진 좁은 화면 카드는 세지 않는다.
 */
async function brandMarkCountsByMethod(page: Page): Promise<Record<string, number>> {
  return page.evaluate(() => {
    const out: Record<string, number> = {};
    for (const row of document.querySelectorAll<HTMLElement>('[data-payment-method]')) {
      const id = row.dataset.paymentMethod ?? '';
      const marks = [...row.querySelectorAll<HTMLElement>('span[aria-hidden="true"], div[aria-hidden="true"]')]
        .filter((el) => el.style.flex.startsWith('0 0') && el.getBoundingClientRect().width > 0);
      if (marks.length > 0) out[id] = (out[id] ?? 0) + marks.length;
    }
    return out;
  });
}

/** 결제수단별 카운트의 합. */
const total = (counts: Record<string, number>): number => Object.values(counts).reduce((a, b) => a + b, 0);

/** 설정 탭을 라벨 텍스트로 클릭한다 (탭은 role/accessible-name 이 라벨과 일치하지 않는다). */
async function clickTab(page: Page, label: string): Promise<void> {
  const clicked = await page.evaluate((text) => {
    const el = [...document.querySelectorAll('button, a')].find((e) => e.textContent?.trim() === text);
    if (!el) return false;
    (el as HTMLElement).click();
    return true;
  }, label);
  if (!clicked) throw new Error(`탭을 찾지 못했다: ${label}`);
}

async function gotoOrderSettings(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(ORDER_SETTINGS_URL);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect.poll(async () => await rowCount(page), { timeout: 30_000 }).toBeGreaterThan(0);
}

test.describe('@sirsoft-ecommerce 주문설정 결제수단 — 브랜드 마크', () => {
  /**
   * @scenario method_kind=extension, capability_declared=declared, capability=brand_mark
   * @effects admin_shows_brand_mark
   */
  test('브랜드 마크를 선언한 결제수단 행에 마크가 그려진다', async ({ page, settingsToken }) => {
    await gotoOrderSettings(page, settingsToken);

    await expect
      .poll(async () => total(await brandMarkCountsByMethod(page)), { timeout: 20_000 })
      .toBeGreaterThan(0);

    const byMethod = await brandMarkCountsByMethod(page);
    // 간편결제를 제공하는 PG 플러그인이 하나라도 설치돼 있으면 그 결제수단 행에 마크가 잡혀야 하고,
    // 넓은 화면에서는 표만 보이므로 행마다 정확히 하나다(아이콘과 마크가 겹쳐 그려지지 않는다).
    expect(Object.keys(byMethod).length, `브랜드 마크가 그려진 결제수단이 없다: ${JSON.stringify(byMethod)}`)
      .toBeGreaterThan(0);
    for (const [id, count] of Object.entries(byMethod)) {
      expect(count, `${id} 행의 브랜드 마크 수`).toBe(1);
    }
  });

  /**
   * @scenario method_kind=extension, capability_declared=declared, capability=brand_mark
   * @effects admin_rebinds_brand_mark_after_tab_return
   */
  test('다른 탭에 갔다 돌아와도 브랜드 마크가 그대로다 (replaceState 회귀)', async ({ page, settingsToken }) => {
    await gotoOrderSettings(page, settingsToken);

    await expect
      .poll(async () => total(await brandMarkCountsByMethod(page)), { timeout: 20_000 })
      .toBeGreaterThan(0);
    const before = await brandMarkCountsByMethod(page);

    // 탭 전환은 replaceState 로 URL 만 바꾼다 — 화면 전환을 실제 클릭으로 재현한다.
    // 탭은 접근성 이름이 라벨과 정확히 일치하지 않으므로(내부 텍스트 노드 구성) 텍스트로 찾는다.
    await clickTab(page, '배송설정');
    await expect.poll(async () => await rowCount(page), { timeout: 20_000 }).toBe(0);

    await clickTab(page, '주문설정');
    await expect.poll(async () => await rowCount(page), { timeout: 20_000 }).toBeGreaterThan(0);

    // 복귀 후 마크가 0 으로 남지 않아야 한다.
    await expect
      .poll(async () => total(await brandMarkCountsByMethod(page)), { timeout: 20_000 })
      .toBeGreaterThan(0);

    const after = await brandMarkCountsByMethod(page);
    expect(after, `탭 복귀 후 브랜드 마크가 복원되지 않았다 (before=${JSON.stringify(before)} after=${JSON.stringify(after)})`)
      .toEqual(before);
  });
});
