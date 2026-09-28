/**
 * 주문 목록 '클레임 기준' 필터 · 클레임 컬럼 (#599 S10 · D22)
 *
 * 브라우저로만 드러나는 축을 본다:
 *
 *  ① **필터 칩이 실제로 목록을 거르는가.** 화면 어휘가 서버 게이트를 벗어나면 칩 하나를
 *     누른 순간 목록 전체가 422 가 되는데, 화면에는 직전 목록이 그대로 남아 "필터가 걸렸다"
 *     로 보인다. 응답 코드를 직접 봐야 갈린다.
 *  ② **총 건수가 조인 부풀림 없이 정확한가.** 저장소가 `whereExists` 대신 조인으로 걸면
 *     1:N 이라 원 행이 부풀어 총 건수와 페이지 경계가 함께 깨진다 — 목록은 정상으로 보인다.
 *  ③ **클레임 요약이 항상 실리는가.** 종전에는 컬럼을 켠 요청에만 싣는 opt-in 이었는데,
 *     표시(브라우저 저장값)와 값 수신(주소)이 근거를 달리해 어긋났다 — 컬럼을 한 번 켠
 *     운영자의 다음 진입에서 컬럼은 보이는데 전 행이 "-" 가 됐다. 지금은 서버가 항상 싣고
 *     표시 여부는 컬럼 선택 하나가 정한다.
 *
 * @scenario claim_axis=presence
 * @effects order_list_claim_filter_block_renders, order_list_claim_filter_narrows_result, order_list_claim_filter_total_is_not_inflated, order_list_claim_column_is_always_served
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const LIST_URL = '/admin/ecommerce/orders';
const ORDERS_API = '/api/modules/sirsoft-ecommerce/admin/orders';

/**
 * 주문 목록이 그려질 때까지 기다립니다.
 *
 * @param page Playwright 페이지
 */
async function waitForOrderList(page: import('@playwright/test').Page): Promise<void> {
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#filter_card')).toBeVisible({ timeout: 30_000 });
}

test.describe('주문 목록 클레임 기준 필터 (D22)', () => {
  test('클레임 기준 블록이 기본으로 보인다', async ({ page, ordersReadToken }) => {
    test.setTimeout(120_000);

    await authenticatePage(page, ordersReadToken);
    await page.goto(LIST_URL);
    await waitForOrderList(page);

    // 계획서 6-4-2 는 "기본 필터 아래에 블록으로 삽입(검색 편집에서 숨김 가능)" 이다.
    // 상세검색을 펼쳐야만 보이면 운영자가 검색 편집을 열어보기 전에는 이 기능이 있다는
    // 것조차 모른다 — 6차 검증에서 실제로 그 상태였고 확정으로 기본 노출로 바꿨다.
    // 상세검색을 누르지 않고 본다 — 눌러야 보이는 것은 이 축의 판정이 되지 못한다.
    const block = page.locator('#claim_filter_block');
    await expect(block, '클레임 기준 블록이 기본 화면에 보이지 않는다').toBeVisible({ timeout: 15_000 });

    // 유무 3 + 교환 14 + 반품 11 + 환불 7 + 취소 2 + 귀책 3 = 40 칩.
    // `> 0` 으로 두면 칩이 39개 사라져도 통과한다 — 어휘 산출이 한 축만 끊겨도
    // 화면은 그럴듯하게 보이고 그 축으로는 거를 수만 없어진다. 정확한 수를 고정한다.
    const chips = block.locator('button, label').filter({ hasText: /./ });
    await expect(chips, '클레임 기준 블록은 칩 40개(유무 3 + 교환 14 + 반품 11 + 환불 7 + 취소 2 + 귀책 3)를 그린다').toHaveCount(40);

    // 신청일 범위 두 입력
    await expect(page.locator('#f_261_claim_requested_from')).toBeVisible();
    await expect(page.locator('#f_263_claim_requested_to')).toBeVisible();
  });

  test('클레임 필터가 걸린 목록은 422 가 아니라 정상 응답이고 총 건수가 부풀지 않는다', async ({ request, ordersReadToken }) => {
    const headers = { Authorization: `Bearer ${ordersReadToken}`, Accept: 'application/json' };

    const plain = await request.get(`${ORDERS_API}?per_page=20`, { headers });
    expect(plain.status(), '기준 목록이 200 이 아니면 이 축을 비교할 수 없다').toBe(200);
    const plainTotal = (await plain.json())?.data?.pagination?.total ?? null;

    // ① 좁혀지는가 — 단일 조건(claim_presence=any)으로 본다. 여러 조건을 곹치면
    //   정상 동작에서도 0건이 나올 수 있어 "좁혀졌다" 와 "무시했다" 가 구분되지 않는다.
    const presence = await request.get(`${ORDERS_API}?per_page=20&claim_presence=any`, { headers });

    expect(presence.status(), '화면 어휘가 서버 게이트를 벗어나면 여기서 422 가 된다').toBe(200);

    const presenceBody = await presence.json();

    // `?? 0` 로 뭉개면 응답 형태가 무너진 것(pagination 누락·키 개명)이 "클레임 표본이 없다" 로
    // 위장돼 아래 좁힘 축이 아무것도 단언하지 않고 초록으로 끝난다. 200 을 받았다면 총 건수는
    // 반드시 있어야 하므로 그 사실을 먼저 단언한다.
    expect(
      presenceBody?.data?.pagination?.total,
      '200 인데 총 건수가 없다 — 응답 형태가 바뀌었다(표본 부족이 아니다)',
    ).toEqual(expect.any(Number));

    const presenceTotal = presenceBody?.data?.pagination?.total ?? 0;
    const presenceRows = presenceBody?.data?.data ?? [];

    if (plainTotal === null || presenceTotal === 0) {
      // 형제 축(중복 행)은 표본이 없을 때 사실을 드러낸다 — 여기만 침묵하면 "돌았다" 와
      // "건너뛰었다" 가 리포트에서 구분되지 않아, 클레임이 한 건도 없는 환경에서 이
      // effect 가 아무것도 단언하지 않은 채 초록이 된다.
      test.info().annotations.push({
        type: 'skipped-axis',
        description: `클레임 표본이 없어 좁힘 축 미검증 (무필터=${plainTotal} / 유무필터=${presenceTotal})`,
      });
    }

    if (plainTotal !== null && presenceTotal > 0) {
      // "좁혀졌다" 를 보이려면 **좁혀질 여지**가 있어야 한다. 모든 주문에 클레임이 달린
      // 환경에서는 `presenceTotal === plainTotal` 이 정상인데 `toBeLessThan` 은 red 가 된다 —
      // 전제를 확인하지 않으면 이 단언이 정상 동작을 결함으로 보고한다.
      if (presenceTotal === plainTotal) {
        test.info().annotations.push({
          type: 'skipped-axis',
          description: `무클레임 주문이 없어 좁힘 축 미검증 (무필터=${plainTotal} / 유무필터=${presenceTotal})`,
        });
      } else {
        // `<=` 만으로는 **서버가 클레임 파라미터를 통째로 무시**해도 통과한다
        // (파라미터명 오타·조건 미배선이 정확히 그 형태다). 이 spec 이 마킹한
        // `order_list_claim_filter_narrows_result` 를 검증하려면 좁혀졌음을 보여야 한다.
        expect(
          presenceTotal,
          '클레임 유무 필터가 목록을 좁히지 못했다 — 서버가 클레임 파라미터를 무시했을 수 있다',
        ).toBeLessThan(plainTotal);
      }
    }

    // ② 부풀지 않는가 — 유형별 상태를 여러 개 걸어도 원 행이 부풀지 않아야 한다 (whereExists 계약)
    const filtered = await request.get(
      `${ORDERS_API}?per_page=20&claim_presence=any`
        + '&claim_exchange_statuses[]=requested&claim_exchange_statuses[]=approved'
        + '&claim_return_statuses[]=requested'
        + '&claim_cancel_types[]=partial'
        + '&claim_fault_types[]=customer',
      { headers },
    );

    expect(filtered.status(), '여러 축을 교차해도 게이트를 통과해야 한다').toBe(200);

    const body = await filtered.json();

    expect(
      body?.data?.pagination?.total,
      '200 인데 총 건수가 없다 — 응답 형태가 바뀌었다(표본 부족이 아니다)',
    ).toEqual(expect.any(Number));

    const total = body?.data?.pagination?.total ?? 0;
    const rows = body?.data?.data ?? [];

    if (plainTotal !== null) {
      expect(total, '교차 필터의 총 건수가 무필터보다 크다 — 조인 부풀림이다').toBeLessThanOrEqual(plainTotal);
    }

    // 한 페이지 안에서도 같은 주문이 두 번 실리면 안 된다.
    // 교차 조건은 정상 동작에서도 0건일 수 있으므로, 표본이 없을 때는 유무 필터의
    // 행으로 대신 본다 — 이 케이스 전체를 skip 하면 ① 까지 함께 잃는다.
    const sample = rows.length > 0 ? rows : presenceRows;

    if (sample.length === 0) {
      // 주석대로 ① 을 잃지 않으려면 여기서 `test.skip` 을 부르면 안 된다 — 중도 skip 은
      // 케이스 전체를 skipped 로 보고해, 이미 통과한 ①·② 가 "돌았다" 인지 "건너뛰었다" 인지
      // 리포트에서 구분되지 않는다. 형제 경로처럼 사실만 남기고 끝낸다.
      test.info().annotations.push({
        type: 'sample-missing',
        description: '클레임이 걸린 주문이 한 건도 없어 중복 행 축은 확인하지 못했다 (①·② 는 실행됨)',
      });

      return;
    }

    const numbers = sample.map((r: any) => r.order_number);
    expect(new Set(numbers).size).toBe(numbers.length);
  });

  test('클레임 요약은 파라미터 없이도 항상 실린다', async ({ request, ordersReadToken }) => {
    // 종전 계약은 `with_claim_summary=1` 요청에만 싣는 opt-in 이었다. 그 결과 컬럼 표시
    // (브라우저 저장값)와 값 수신(주소)이 서로 다른 근거를 갖게 되어, 컬럼을 한 번 켠
    // 운영자의 다음 진입에서 컬럼은 보이는데 전 행이 "-" 가 됐다. 서버가 항상 싣도록
    // 바꿔 근거를 하나로 되돌렸다 — 되돌리면 이 케이스가 red 가 된다.
    const headers = { Authorization: `Bearer ${ordersReadToken}`, Accept: 'application/json' };

    const res = await request.get(`${ORDERS_API}?per_page=10`, { headers });

    expect(res.status()).toBe(200);

    const rows = (await res.json())?.data?.data ?? [];

    if (rows.length === 0) {
      // 형제 케이스와 같은 형태 — skip 대신 사실을 남기고 실패시킨다 (주문 0건이면 이 축은 측정 불가다).
      test.info().annotations.push({
        type: 'skipped-axis',
        description: '주문이 한 건도 없어 요약 직렬화 축을 확인할 수 없다',
      });
    }

    expect(rows.length, '주문 목록에 행이 있어야 요약 직렬화를 검사할 수 있다').toBeGreaterThan(0);

    for (const row of rows) {
      expect(
        Object.prototype.hasOwnProperty.call(row, 'claim_summary'),
        `주문 ${row.order_number} 응답에 claim_summary 가 없다`
      ).toBe(true);
      expect(Array.isArray(row.claim_summary)).toBe(true);
    }
  });

  test('목록 조회는 요약 opt-in 파라미터를 더 이상 보내지 않는다', async ({ page, ordersReadToken }) => {
    // 이 파라미터가 되살아나면 표시와 값 수신이 다시 두 근거를 갖는다. 화면이 그것을
    // 만들지 않는다는 사실을 브라우저에서 고정한다.
    test.setTimeout(120_000);

    await authenticatePage(page, ordersReadToken);

    const seen: string[] = [];
    page.on('request', (req) => {
      if (req.url().includes('/admin/orders')) {
        seen.push(req.url());
      }
    });

    await page.goto(LIST_URL);
    await waitForOrderList(page);

    await page.goto(`${LIST_URL}?search_field=all&search_keyword=zzzz`);
    await waitForOrderList(page);

    const leaked = seen.filter((u) => u.includes('with_claim_summary'));
    expect(leaked, `목록 조회가 opt-in 파라미터를 보냈다: ${JSON.stringify(leaked)}`).toEqual([]);
    expect(seen.length, '목록 조회 요청이 한 건도 관측되지 않았다').toBeGreaterThan(0);
  });
});
