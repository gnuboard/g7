/**
 * 주문 상세 — 상품 목록 숫자 열 정렬 일치 회귀.
 *
 * 배경: 상품 목록의 숫자 열 6개 중 `정가/판매가`·`할인가격`·`실 구매가격`·`소계` 는
 * `text-right` 로 우측 정렬인데, `구매수량`·`적립예정` 만 `flex flex-col items-center`
 * 로 가운데 정렬이라 값이 서로 어긋나 보였다. 표(≥768px)·카드(<768px) 양쪽에서 발생.
 *
 * 측정 규율 (이 spec 을 고칠 사람에게):
 *  - `items-center` 는 `text-align` 이 아니라 `align-items` 로 정렬하므로,
 *    `textAlign === 'right'` 만 단언하면 두 셀을 영영 못 잡는다. 실제 **콘텐츠의
 *    우측 여백(rightGap)** 이 열끼리 같은지를 본다.
 *  - 절대 좌표/임계값(`toBeGreaterThan(N)`)은 레이아웃이 바뀌면 거짓 통과한다.
 *    같은 행 안에서 **열끼리 서로 같은가**(집합 크기 1)를 단언한다.
 *
 * @scenario order_detail_numeric_alignment viewport=1280|390
 * @effects numeric_columns_right_aligned_consistently
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

/**
 * 숫자 값을 담는 열의 수 (정가/판매가·구매수량·할인가격·실 구매가격·적립예정·소계).
 *
 * 헤더 텍스트로 찾지 않는다 — E2E 세션 로케일이 en 이면 `Quantity` 로 렌더돼
 * 한글 문자열 단언이 통째로 새기 때문(실측 확인). 대신 "우측 정렬로 선언된 셀" 을
 * 숫자 열로 식별한다. 회귀(= `items-center` 복귀)가 나면 그 셀이 목록에서 빠지므로
 * 개수 단언이 먼저 깨진다.
 */
const NUMERIC_COLUMN_COUNT = 6;

/**
 * 첫 주문의 상세 URL 을 얻는다 (주문번호 하드코딩 회피).
 *
 * 목록 화면은 좁은 뷰포트에서 표가 아니라 카드로 렌더되므로, 진입은 항상 데스크톱 폭에서
 * 한다. 상세 진입은 `<a href>` 가 아니라 주문번호 `<span>` 클릭(DataGrid onRowClick 계열).
 * 상세 URL 은 숫자 id 가 아니라 주문번호(예: `20260709-0144414102`) 다.
 */
async function firstOrderDetailUrl(page: Page): Promise<string> {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/admin/ecommerce/orders');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const orderNumber = page.locator('table tbody tr span.cursor-pointer').first();
  await orderNumber.waitFor({ state: 'visible', timeout: 30_000 });
  await orderNumber.click();

  await page.waitForURL(/\/admin\/ecommerce\/orders\/[\w-]+$/, { timeout: 30_000 });
  return new URL(page.url()).pathname;
}

/**
 * 우측 정렬로 선언된 셀들의 "콘텐츠 우측 여백" 을 잰다.
 *
 * 셀 경계와 실제 콘텐츠 우측 끝의 거리. 모든 숫자 열이 같은 방식으로 우측 정렬돼 있으면
 * 이 값이 열끼리 같다. `구매수량`·`적립예정` 이 `items-center` 로 돌아가면 그 셀은
 * 우측 정렬 목록에서 빠지므로 개수가 줄고, 설령 잡히더라도 여백이 달라진다.
 *
 * `text-right`(block) 와 `items-end`(flex) 둘 다 우측 정렬이므로 함께 센다.
 */
async function rightGaps(page: Page): Promise<number[]> {
  return page.evaluate(() => {
    const isRightAligned = (el: Element): boolean => {
      const cs = getComputedStyle(el);
      return cs.textAlign === 'right' || cs.alignItems === 'flex-end';
    };
    const gapOf = (container: Element, inner: Element): number => {
      const content = inner.firstElementChild ?? inner;
      return Math.round(container.getBoundingClientRect().right - content.getBoundingClientRect().right);
    };

    // 표 뷰(≥768px): thead 가 있는 상품 목록 table
    const table = [...document.querySelectorAll('table')].find((t) => t.querySelector('tbody tr td'));
    if (table) {
      const row = table.querySelector('tbody tr');
      if (!row) return [];
      return [...row.querySelectorAll('td')]
        .map((td) => {
          const inner = td.firstElementChild;
          return inner && isRightAligned(inner) ? gapOf(td, inner) : null;
        })
        .filter((v): v is number => v !== null);
    }

    // 카드 뷰(<768px): DataGrid 가 컬럼을 grid 셀로 편다
    const grid = [...document.querySelectorAll('[class*="grid-cols-"]')].find((g) => g.querySelector('.min-w-0'));
    if (!grid) return [];
    return [...grid.children]
      .map((cell) => {
        const label = cell.querySelector('span');
        const value = [...cell.children].find((c) => c !== label);
        const inner = value?.firstElementChild;
        return inner && isRightAligned(inner) ? gapOf(cell, inner) : null;
      })
      .filter((v): v is number => v !== null);
  });
}

test.describe('@sirsoft-ecommerce 주문 상세 상품 목록 정렬', () => {
  // @scenario order_detail_numeric_alignment:desktop_table
  // @effects numeric_columns_right_aligned_consistently
  test('1280px 표 뷰: 숫자 열이 모두 같은 우측 여백으로 정렬된다', async ({ page, ordersReadToken }) => {
    await authenticatePage(page, ordersReadToken);
    await firstOrderDetailUrl(page); // 진입만 하면 이미 1280px 표 뷰

    // 회귀 시 `구매수량`·`적립예정` 이 items-center 로 돌아가 우측 정렬 셀이 4개로 줄어든다.
    await expect
      .poll(async () => (await rightGaps(page)).length, { timeout: 20_000 })
      .toBe(NUMERIC_COLUMN_COUNT);

    const gaps = await rightGaps(page);
    expect(new Set(gaps).size, `열별 우측 여백: ${JSON.stringify(gaps)}`).toBe(1);
  });

  // @scenario order_detail_numeric_alignment:mobile_card
  // @effects numeric_columns_right_aligned_consistently
  test('390px 카드 뷰: 숫자 열이 모두 같은 우측 여백으로 정렬된다', async ({ page, ordersReadToken }) => {
    await authenticatePage(page, ordersReadToken);
    // 목록은 데스크톱 폭에서 진입(좁은 폭에선 목록도 카드라 주문번호 span 이 없다)
    const detailPath = await firstOrderDetailUrl(page);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(detailPath);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect
      .poll(async () => (await rightGaps(page)).length, { timeout: 20_000 })
      .toBe(NUMERIC_COLUMN_COUNT);

    const gaps = await rightGaps(page);
    expect(new Set(gaps).size, `열별 우측 여백: ${JSON.stringify(gaps)}`).toBe(1);
  });
});
