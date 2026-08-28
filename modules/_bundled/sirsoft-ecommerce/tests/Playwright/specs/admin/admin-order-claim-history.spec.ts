/**
 * 관리자 주문상세 '클레임 내역' 섹션 — 탭 진입·서브탭 전환·새 창 진입 (#599 S9c · D20)
 *
 * 이 섹션에서 브라우저로만 드러나는 축을 본다:
 *
 *  ① **탭을 눌러야 섹션이 보이는가.** 탭 배열과 섹션 존재가 어긋나면(D17-①) 탭은 그려지는데
 *     클릭이 아무 일도 하지 않는다 — 예외도 로그도 남지 않는다.
 *  ② **서브탭 전환이 재조회를 일으키지 않는가** (D20 — "별도 데이터소스를 만들지 않는다").
 *     레이아웃 정적 검사는 `apiCall` 이 없다는 것까지만 본다. 실제로 요청이 나가는지는
 *     네트워크를 감시해야 알 수 있다.
 *  ③ **[클레임 신청서 조회하기]가 새 창으로 열리는가.** 같은 탭에서 열리면 주문상세의 작업
 *     맥락(스크롤 위치·서브탭 선택)이 사라진다.
 *
 * 클레임이 달린 주문 한 건이 필요하다. 시드 커맨드(`playwright:seed-ecommerce`)의 클레임
 * 축이 아직 stub 이라(계획서 이월) 목록에서 발견되는 주문으로 진행하고, 한 건도 없으면 그
 * 사실을 드러내며 건너뛴다 — 없는 데이터를 있는 척 단언하지 않는다.
 *
 * @scenario admin-order-claim-history
 * @axes claim_type=exchange|return|cancel|refund
 * @effects order_detail_claim_history_tab_reveals_section, order_detail_claim_history_subtabs_switch_without_refetch, order_detail_claim_history_detail_link_targets_blank
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const DETAIL_URL = (orderNumber: string) => `/admin/ecommerce/orders/${encodeURIComponent(orderNumber)}`;
const CLAIMS_API = '/claims';

/** 클레임이 달린 주문 (유형별 건수 포함) */
type DiscoveredOrder = { orderNumber: string; counts: Record<string, number>; detailUrl: string | null };

/**
 * 클레임이 달린 주문 한 건을 찾습니다.
 *
 * 관리자 클레임 목록에서 신청서를 얻고 그 주문번호로 D20 그룹 응답을 확인한다 — 주문 목록을
 * 훑으며 주문마다 클레임을 조회하면 요청이 페이지 크기만큼 늘어난다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 인증 토큰
 * @returns 발견한 주문 (없으면 null)
 */
async function discoverOrderWithClaims(
  request: import('@playwright/test').APIRequestContext,
  token: string,
): Promise<DiscoveredOrder | null> {
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };

  for (const claimType of ['exchange', 'return', 'refund', 'cancel']) {
    const listed = await request.get(
      `/api/modules/sirsoft-ecommerce/admin/claims?type=${claimType}&per_page=5`,
      { headers },
    );

    if (!listed.ok()) continue;

    for (const row of ((await listed.json())?.data?.data ?? []) as Array<Record<string, any>>) {
      if (!row.order_number) continue;

      const grouped = await request.get(
        `/api/modules/sirsoft-ecommerce/admin/orders/${encodeURIComponent(row.order_number)}/claims`,
        { headers },
      );

      if (!grouped.ok()) continue;

      const payload = (await grouped.json())?.data ?? {};
      const claims = (payload.claims ?? []) as Array<Record<string, any>>;

      if (claims.length === 0) continue;

      return {
        orderNumber: row.order_number,
        counts: payload.counts ?? {},
        detailUrl: claims.find((claim) => claim.detail_url)?.detail_url ?? null,
      };
    }
  }

  return null;
}

/**
 * 주문상세가 그려질 때까지 기다립니다.
 *
 * `networkidle` 은 쓰지 않는다 — 관리자 SPA 는 실시간 알림 연결·주기 폴링이 붙어 무통신
 * 구간이 오지 않을 수 있다. 다음 단계에 필요한 구체 신호(탭)만 기다린다.
 *
 * @param page Playwright 페이지
 */
async function waitForOrderDetail(page: import('@playwright/test').Page): Promise<void> {
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#tab_navigation')).toBeVisible({ timeout: 30_000 });
}

/**
 * 유형 서브탭 중 건수가 있는 것의 값을 고릅니다.
 *
 * @param counts 유형별 건수
 * @returns 건수가 있는 유형 값 배열
 */
function typesWithClaims(counts: Record<string, number>): string[] {
  return ['exchange', 'return', 'cancel', 'refund'].filter((type) => (counts[type] ?? 0) > 0);
}

test.describe('관리자 주문상세 클레임 내역 섹션 (#599 S9c)', () => {
  /**
   * 탭이 있는데 섹션이 없으면 클릭이 무반응이다 (D17-①) — 그 어긋남은 오류를 남기지 않는다.
   */
  // @scenario claim_type=exchange, claims_present=yes
  // @effects order_detail_claim_history_tab_reveals_section
  test('클레임 내역 탭이 있고 그 섹션이 조회 전용으로 그려진다', async ({ page, request, ordersReadToken }) => {
    const order = await discoverOrderWithClaims(request, ordersReadToken);
    test.skip(
      order === null,
      '클레임이 달린 주문이 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월) 선행',
    );

    await authenticatePage(page, ordersReadToken);
    await page.goto(DETAIL_URL(order!.orderNumber));
    await waitForOrderDetail(page);

    const section = page.locator('[data-section-id="claim_history"]');
    await expect(section).toHaveCount(1);

    // 탭 버튼의 role 은 `tab` 이다 (WAI-ARIA Tabs 규약) — `button` 으로 찾으면 잡히지 않는다.
    await page.locator('#tab_navigation').getByRole('tab', { name: /클레임/ }).click();
    await page.waitForTimeout(1_500);

    await expect(section).toBeVisible({ timeout: 30_000 });

    // 조회 전용 — 이 섹션 안에는 입력 요소가 없고 버튼은 유형 서브탭 4개뿐이다 (D20).
    await expect(section.locator('input, select, textarea')).toHaveCount(0);
    await expect(section.locator('button')).toHaveCount(4);

    // 원문 다국어 키가 화면에 노출되지 않는다.
    expect(await section.innerText()).not.toContain('sirsoft-ecommerce.admin.order.detail.claim_history');
  });

  /**
   * 서버가 전 유형을 한 응답에 싣는 것이 D20 의 전제다 — 전환마다 요청이 나가면 그 전제가
   * 깨진 것이고, 뱃지와 목록이 갈릴 창도 함께 열린다.
   */
  // @scenario claim_type=return, claims_present=yes
  // @effects order_detail_claim_history_subtabs_switch_without_refetch
  test('유형 서브탭을 오가도 클레임 조회 요청이 다시 나가지 않는다', async ({ page, request, ordersReadToken }) => {
    const order = await discoverOrderWithClaims(request, ordersReadToken);
    test.skip(
      order === null,
      '클레임이 달린 주문이 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월) 선행',
    );

    await authenticatePage(page, ordersReadToken);
    await page.goto(DETAIL_URL(order!.orderNumber));
    await waitForOrderDetail(page);
    await page.waitForTimeout(2_000);

    // 진입 조회가 끝난 뒤부터 센다.
    let refetches = 0;
    page.on('request', (req) => {
      if (req.url().includes(encodeURIComponent(order!.orderNumber)) && req.url().endsWith(CLAIMS_API)) {
        refetches += 1;
      }
    });

    const section = page.locator('[data-section-id="claim_history"]');
    for (const type of ['return', 'cancel', 'refund', 'exchange']) {
      await section.locator(`#claim_history_subtab_${type}`).click();
      await page.waitForTimeout(400);
    }

    expect(refetches).toBe(0);

    // 건수가 있는 유형으로 돌아오면 그 유형의 행이 다시 보인다 — 필터가 화면에서 돈다.
    const present = typesWithClaims(order!.counts);
    if (present.length > 0) {
      await section.locator(`#claim_history_subtab_${present[0]}`).click();
      await page.waitForTimeout(400);
      await expect(section.locator('#claim_history_empty')).toHaveCount(0);
    }
  });

  /**
   * 같은 탭에서 열리면 주문상세의 작업 맥락(스크롤 위치·서브탭 선택)이 사라진다.
   */
  // @scenario claim_type=exchange, claims_present=yes
  // @effects order_detail_claim_history_detail_link_targets_blank
  test('[클레임 신청서 조회하기]가 새 창으로 그 클레임 상세를 연다', async ({ page, request, ordersReadToken }) => {
    const order = await discoverOrderWithClaims(request, ordersReadToken);
    test.skip(
      order === null || order.detailUrl === null,
      '상세 라우트가 있는 클레임(취소 외)이 달린 주문이 없다 — 클레임 시드 선행',
    );

    await authenticatePage(page, ordersReadToken);
    await page.goto(DETAIL_URL(order!.orderNumber));
    await waitForOrderDetail(page);

    const section = page.locator('[data-section-id="claim_history"]');
    const withDetail = typesWithClaims(order!.counts).filter((type) => type !== 'cancel');
    test.skip(withDetail.length === 0, '상세 라우트가 있는 유형의 신청서가 이 주문에 없다');

    await section.locator(`#claim_history_subtab_${withDetail[0]}`).click();
    await page.waitForTimeout(600);

    const link = section.locator('a[target="_blank"]').first();
    await expect(link).toHaveCount(1);

    // 도착 URL 이 그 클레임 상세다 — 경로를 화면이 조립하면 규칙이 바뀔 때 조용히 404 가 된다.
    const href = await link.getAttribute('href');
    expect(href).toMatch(/^\/admin\/ecommerce\/claims\/.+/);

    const [opened] = await Promise.all([page.context().waitForEvent('page'), link.click()]);
    await opened.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    expect(new URL(opened.url()).pathname).toBe(href);
    await expect(opened.locator('#claim_tab_navigation')).toBeVisible({ timeout: 30_000 });

    await opened.close();
  });
});
