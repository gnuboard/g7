/**
 * 주문 상세 — 합계행(tfoot) 텍스트 바인딩 해석 회귀 (공개 #98).
 *
 * 배경: admin_basic 1.0.4 릴리즈에 stale dist 가 실려, `components.json` 의
 * `skipBindingKeys`(footerCells 선평가 중단) 선언과 구형 JS(renderFooterCellText 부재)가
 * 어긋나 합계행 3셀(`수량`·`적립예정`·`배송비`)에 `{{order.data?.total_quantity ?? 0}}개`
 * 같은 표현식 원문이 그대로 노출됐다. 소스·단위 테스트는 정상(green)이었고 **서빙 산출물만**
 * 구형이었으므로, 서빙 번들을 실제로 로드하는 이 E2E 만이 해당 회귀를 잡는다.
 *
 * 측정 규율 (이 spec 을 고칠 사람에게):
 *  - 값 하드코딩 금지 — 주문 데이터는 변동한다. 형태(정규식)로 단언한다.
 *  - 헤더/라벨의 한글 문자열 단언 금지 — E2E 세션 로케일이 en 이면 `$t:` 라벨이 영문으로
 *    렌더된다(order-detail-numeric-alignment.spec.ts 실측 선례). 셀 식별은 레이아웃
 *    JSON 이 부여한 className 훅으로 한다: 수량=`text-center`(비 blue), 적립=`text-center`+
 *    `text-blue-600`, 배송비=`text-label`. `개`/`P` 접미사는 레이아웃에 리터럴로 박힌
 *    로케일 무관 문자라 단언에 써도 된다.
 *  - tfoot 의 선행 빈 셀(expandable/selectable) 유무에 좌우되지 않도록 인덱스 단언 금지.
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

/**
 * 첫 주문의 상세로 진입한다 (주문번호 하드코딩 회피).
 *
 * 목록 화면은 좁은 뷰포트에서 카드로 렌더되므로 진입은 항상 데스크톱 폭에서 한다.
 * 상세 진입은 주문번호 `<span>` 클릭(DataGrid onRowClick 계열).
 */
async function gotoFirstOrderDetail(page: Page): Promise<void> {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/admin/ecommerce/orders');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const orderNumber = page.locator('table tbody tr span.cursor-pointer').first();
  await orderNumber.waitFor({ state: 'visible', timeout: 30_000 });
  await orderNumber.click();

  await page.waitForURL(/\/admin\/ecommerce\/orders\/[\w-]+$/, { timeout: 30_000 });
}

test.describe('@sirsoft-ecommerce 주문 상세 합계행 텍스트 바인딩', () => {
  // @scenario footer_cell=quantity, viewport=desktop
  // @effects footer_text_bindings_resolved_no_raw_expression, footer_quantity_cell_shows_count_with_unit
  // @scenario footer_cell=points, viewport=desktop
  // @effects footer_points_cell_shows_formatted_number
  // @scenario footer_cell=delivery, viewport=desktop
  // @effects footer_delivery_cell_shows_shipping_fee_label
  test('합계행 수량·적립·배송비 셀이 표현식 원문 없이 값으로 렌더된다', async ({ page, ordersReadToken }) => {
    await authenticatePage(page, ordersReadToken);
    await gotoFirstOrderDetail(page);

    // 상품 목록 표의 합계행이 뜰 때까지 대기 (footerCells 는 데이터 로드 후에만 렌더)
    const tfoot = page.locator('table tfoot').first();
    await tfoot.waitFor({ state: 'visible', timeout: 30_000 });

    // ① 페이지 전체에 미해석 표현식 원문이 없다 — stale dist 회귀의 1차 증상.
    //    (poll: 데이터 소스 응답 직후 재렌더 타이밍 흡수)
    await expect
      .poll(async () => (await page.evaluate(() => document.body.innerText)).includes('{{'), {
        timeout: 20_000,
      })
      .toBe(false);

    // ② 수량 셀 — 레이아웃 className 훅: text-center 이면서 blue(적립) 가 아닌 셀.
    //    `개` 는 레이아웃 리터럴(로케일 무관). 회귀 시 `{{...}}개` 라 정규식이 깨진다.
    const quantityCell = tfoot.locator('td.text-center:not(.text-blue-600)').first();
    await expect(quantityCell).toHaveText(/^[\d,]+개$/);

    // ③ 적립예정 셀 — text-center + text-blue-600. `| number` 파이프 해석 결과라
    //    숫자(천단위 콤마 허용) + `P` 리터럴 형태여야 한다.
    const pointsCell = tfoot.locator('td.text-center.text-blue-600').first();
    await expect(pointsCell).toHaveText(/^[\d,]+P$/);

    // ④ 배송비 셀 — text-label. 라벨은 `$t:` 로 로케일 의존이므로 라벨 문자열 대신
    //    "라벨: 값" 구분자 존재 + 표현식 원문 부재를 단언한다.
    const deliveryCell = tfoot.locator('td.text-label').first();
    const deliveryText = (await deliveryCell.innerText()).trim();
    expect(deliveryText).not.toContain('{{');
    expect(deliveryText).toMatch(/:/);
  });
});
