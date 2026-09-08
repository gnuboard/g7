/**
 * 관리자 클레임 상세 골격 — 섹션 탭 스크롤 동기화·모바일 전환·권한 게이팅 (#599 S9a)
 *
 * 상세 화면에서 브라우저로만 드러나는 축을 본다:
 *
 *  ① **탭 클릭 → 섹션 스크롤 도달**. 탭 배열과 섹션 존재가 같은 조건으로 분기하지 않으면
 *     (D17-①) 탭은 보이는데 클릭이 아무 일도 하지 않는다 — 예외도 로그도 남지 않는다.
 *  ② **스크롤 → 활성 탭 역동기화**. scrollSpy 가 섹션 id 접두를 못 맞추면 스크롤해도 탭이
 *     따라오지 않고, 이 역시 오류 없이 조용하다.
 *  ③ **모바일 390 에서 탭이 Select 로 전환**. 컴포넌트가 자체 전환하므로 레이아웃에는
 *     그 코드가 없다 — 실측이 유일한 확인 수단이다.
 *  ④ **권한 게이팅**. 송장·메모 저장은 서로 다른 권한(`claims.update-status` /
 *     `claims.update-amounts`)이 정한다. 읽기 권한만 가진 운영자에게 컨트롤이 살아 있으면
 *     저장 시점에야 403 이 된다.
 *  ⑤ **없는 클레임 번호**는 화면이 오류 페이지로 떨어져야 한다 — 빈 화면으로 남으면
 *     운영자는 로딩이 끝나지 않은 것으로 읽는다.
 *
 * 상세를 여는 데는 신청서 한 건이 필요하다. 시드 커맨드(`playwright:seed-ecommerce`)의
 * 클레임 축이 아직 stub 이라(계획서 이월 5) 목록에서 발견되는 건으로 진행하고, 한 건도
 * 없으면 그 사실을 드러내며 건너뛴다 — 없는 데이터를 있는 척 단언하지 않는다.
 *
 *  ⑥ **신청상품 표의 금액·추가옵션 표기**(S17). 이 표는 DataGrid 셀 안에서 그려지므로
 *     단위 테스트 하네스가 `$t:defer:` 를 해소하지 못한다 — 차액 금액이 실제로 나오는지는
 *     브라우저에서만 확인된다. 교환 후 금액이 단가가 아닌 소계인지도 수량 2 이상에서만
 *     드러난다.
 *
 * @scenario admin-claim-detail
 * @axes claim_type=exchange|return|refund
 * @effects detail_tab_click_scrolls_to_section, detail_scroll_syncs_active_tab, detail_tabs_collapse_to_select_on_mobile, detail_items_amount_pairs_subtotal, detail_items_price_difference_shows_amount, claim_progress_cells_uniform_width_with_chevrons, claim_progress_wraps_on_mobile_without_scroll
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const LIST_URL = '/admin/ecommerce/claims';
const DETAIL_URL = (claimNumber: string) => `/admin/ecommerce/claims/${encodeURIComponent(claimNumber)}`;

/** 목록 API 에서 발견한 신청서 (유형 서브탭 순회 결과) */
type DiscoveredClaim = { claimNumber: string; claimType: string };

/**
 * 관리자 목록 API 로 신청서 한 건을 찾습니다.
 *
 * 교환 → 반품 → 환불 순으로 본다. 상세 화면의 분기가 가장 넓은 유형이 교환이라 그쪽을
 * 먼저 고른다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 인증 토큰
 * @returns 발견한 신청서 (없으면 null)
 */
async function discoverClaim(
  request: import('@playwright/test').APIRequestContext,
  token: string,
): Promise<DiscoveredClaim | null> {
  for (const claimType of ['exchange', 'return', 'refund']) {
    const found = await discoverClaimOfType(request, token, claimType);

    if (found) {
      return found;
    }
  }

  return null;
}

/**
 * 특정 유형의 신청서 한 건을 찾습니다.
 *
 * 유형이 정하는 축(환불 실행 섹션 등)을 보는 테스트는 이쪽을 쓴다 — `discoverClaim` 은
 * 교환을 먼저 고르므로, 교환이 한 건이라도 있으면 환불 축이 영영 건너뛰어진다(그 자리에서
 * skip 이 나므로 실패로도 드러나지 않는다).
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 인증 토큰
 * @param claimType 클레임 유형
 * @returns 발견한 신청서 (없으면 null)
 */
async function discoverClaimOfType(
  request: import('@playwright/test').APIRequestContext,
  token: string,
  claimType: string,
): Promise<DiscoveredClaim | null> {
  const response = await request.get(
    `/api/modules/sirsoft-ecommerce/admin/claims?type=${claimType}&per_page=1`,
    { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
  );

  if (!response.ok()) {
    return null;
  }

  const row = (await response.json())?.data?.data?.[0];

  return row?.claim_number ? { claimNumber: row.claim_number, claimType } : null;
}

/**
 * 상세 화면이 그려질 때까지 기다립니다.
 *
 * `networkidle` 은 쓰지 않는다 — 관리자 SPA 는 실시간 알림 연결·주기 폴링이 붙어 무통신
 * 구간이 오지 않을 수 있다. 다음 단계에 필요한 구체 신호(탭)만 기다린다.
 *
 * @param page Playwright 페이지
 */
async function waitForDetail(page: import('@playwright/test').Page): Promise<void> {
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#claim_tab_navigation')).toBeVisible({ timeout: 30_000 });
}

test.describe('관리자 클레임 상세 골격 (#599 S9a)', () => {
  /**
   * 라우트가 없으면 템플릿 404 다 — 목록의 클레임번호를 눌러도 아무 화면이 나오지 않는다.
   * 없는 번호는 서버가 404 로 답하고 화면은 오류 페이지로 떨어진다.
   */
  // @scenario claim_type=exchange, claim_stage=in_progress
  // @effects detail_unknown_number_shows_error_page
  test('없는 클레임 번호는 빈 화면이 아니라 오류로 떨어진다', async ({ page, claimsManageToken }) => {
    await authenticatePage(page, claimsManageToken);

    // 라우트 자체는 존재한다 — 존재하지 않으면 API 요청조차 나가지 않는다.
    const detailResponse = page.waitForResponse(
      (response) => response.url().includes('/admin/claims/PW-NO-SUCH-CLAIM')
        && !response.url().includes('/logs'),
      { timeout: 30_000 },
    );

    await page.goto(DETAIL_URL('PW-NO-SUCH-CLAIM'));

    expect((await detailResponse).status()).toBe(404);

    // 화면은 오류 페이지로 떨어진다 — 빈 채로 남으면 운영자는 로딩이 끝나지 않은 것으로 읽는다.
    await expect(page.locator('#main_content')).toContainText('404', { timeout: 30_000 });
    await expect(page.locator('#claim_tab_navigation')).toHaveCount(0);
  });

  // @scenario claim_type=exchange, claim_stage=in_progress
  // @effects detail_header_and_sections_render
  test('상세 헤더와 섹션이 그려지고 탭 수가 유형과 맞는다', async ({ page, request, claimsManageToken }) => {
    const claim = await discoverClaim(request, claimsManageToken);
    test.skip(
      claim === null,
      '클레임 신청서가 한 건도 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월 5) 선행',
    );

    await authenticatePage(page, claimsManageToken);
    await page.goto(DETAIL_URL(claim!.claimNumber));
    await waitForDetail(page);

    await expect(page.locator('#cd_003_claim_number')).toHaveText(claim!.claimNumber);
    await expect(page.locator('[data-section-id="claim_info"]')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('[data-section-id="activity_log"]')).toHaveCount(1);

    // 배송 섹션은 교환·반품에만 있다 — 탭 배열과 같은 조건이다 (D17-①).
    const expectShipping = claim!.claimType === 'exchange' || claim!.claimType === 'return';
    await expect(page.locator('[data-section-id="shipping_info"]')).toHaveCount(expectShipping ? 1 : 0);

    // 원문 다국어 키가 화면에 노출되지 않는다.
    expect(await page.locator('body').innerText()).not.toContain('sirsoft-ecommerce.admin.claim.detail');
  });

  /**
   * 진행단계 — 칸 폭이 라벨 길이를 따라가면 언어마다 들쭉날쭉해지고, 모바일에서 한 줄에 몰아넣으면
   * 가로 스크롤이 생긴다. 꺾쇠는 칸 사이에만 선다(PC 는 마지막 칸 제외, 모바일은 줄 끝도 제외).
   * 어느 쪽도 오류를 남기지 않아 실측으로만 드러난다.
   */
  // @scenario claim_type=exchange, claim_stage=in_progress
  // @effects claim_progress_cells_uniform_width_with_chevrons, claim_progress_wraps_on_mobile_without_scroll, claim_progress_steps_carry_icon_and_variant
  test('진행단계는 칸 폭이 균일하고 꺾쇠가 칸 사이에만 서며 모바일에서 스크롤 없이 줄바꿈된다', async ({ page, request, claimsManageToken }) => {
    const claim = await discoverClaim(request, claimsManageToken);
    test.skip(
      claim === null,
      '클레임 신청서가 한 건도 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월 5) 선행',
    );

    await authenticatePage(page, claimsManageToken);
    await page.goto(DETAIL_URL(claim!.claimNumber));
    await waitForDetail(page);

    const cells = page.locator('[id^="claim_progress_step_"]');
    await expect(cells.first()).toBeVisible({ timeout: 30_000 });
    const count = await cells.count();
    expect(count).toBeGreaterThan(1);

    // 칸마다 서버가 준 상태 아이콘 하나
    await expect(page.locator('[id^="claim_progress_step_"] .rounded-full i[class*="fa-"]')).toHaveCount(count);
    // 상태 속성은 서버 배열의 값 그대로
    const states = await cells.evaluateAll((els) => els.map((el) => el.getAttribute('data-step-state')));
    expect(states.every((s) => ['done', 'current', 'upcoming', 'off_path'].includes(s ?? ''))).toBe(true);

    // PC — 칸 폭 균일 · 9칸까지는 한 줄, 10칸 이상은 6칸/줄 · 꺾쇠는 줄 끝을 뺀 칸 사이
    const widths = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().width)));
    expect(Math.max(...widths) - Math.min(...widths)).toBeLessThanOrEqual(1);
    const pcTops = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
    const pcPerRow = count > 9 ? 6 : count;
    expect(new Set(pcTops).size).toBe(Math.ceil(count / pcPerRow));
    const expectedPcChevrons = [...Array(count - 1).keys()].filter((i) => i % pcPerRow !== pcPerRow - 1).length;
    await expect(page.locator('[data-step-chevron="pc"]:visible')).toHaveCount(expectedPcChevrons);

    // 꺾쇠는 양쪽 원과 같은 거리에 선다 (칸 경계 + 간격 절반에 중심)
    const gaps = await page.locator('[id^="claim_progress_step_"]').evaluateAll((els) => {
      const out: number[] = [];
      els.forEach((el, i) => {
        const chevron = el.querySelector('[data-step-chevron="pc"] i');
        const next = els[i + 1];
        if (!chevron || !next || getComputedStyle(chevron.parentElement as Element).display === 'none') return;
        const a = (el.querySelector('.rounded-full') as Element).getBoundingClientRect();
        const b = (next.querySelector('.rounded-full') as Element).getBoundingClientRect();
        const g = chevron.getBoundingClientRect();
        out.push(Math.abs((g.left - a.right) - (b.left - g.right)));
      });
      return out;
    });
    expect(gaps.length).toBeGreaterThan(0);
    expect(Math.max(...gaps)).toBeLessThanOrEqual(2);
    await expect(page.locator('[data-step-chevron="mobile"]:visible')).toHaveCount(0);

    // 모바일 390 — 4칸/줄 줄바꿈 · 가로 스크롤 없음 · 줄 끝 꺾쇠 없음
    await page.setViewportSize({ width: 390, height: 844 });
    await expect.poll(async () => {
      const tops = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));

      return new Set(tops).size;
    }, { timeout: 10_000 }).toBe(Math.ceil(count / 4));

    const mobileWidths = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().width)));
    expect(Math.max(...mobileWidths) - Math.min(...mobileWidths)).toBeLessThanOrEqual(1);

    const box = await page.locator('#claim_progress_steps').evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth }));
    expect(box.scroll).toBeLessThanOrEqual(box.client + 1);
    const doc = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
    expect(doc.scroll).toBeLessThanOrEqual(doc.client + 1);

    const expectedMobileChevrons = [...Array(count - 1).keys()].filter((i) => i % 4 !== 3).length;
    await expect(page.locator('[data-step-chevron="mobile"]:visible')).toHaveCount(expectedMobileChevrons);
    await expect(page.locator('[data-step-chevron="pc"]:visible')).toHaveCount(0);
  });

  /**
   * 탭 배열과 섹션 존재가 어긋나면 클릭이 무반응이다 — 스크롤 위치로만 드러난다.
   */
  // @scenario claim_type=exchange, claim_stage=in_progress
  // @effects detail_tab_click_scrolls_to_section
  test('탭을 누르면 그 섹션까지 스크롤이 이동한다', async ({ page, request, claimsManageToken }) => {
    const claim = await discoverClaim(request, claimsManageToken);
    test.skip(
      claim === null,
      '클레임 신청서가 한 건도 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월 5) 선행',
    );

    await authenticatePage(page, claimsManageToken);
    await page.goto(DETAIL_URL(claim!.claimNumber));
    await waitForDetail(page);

    // 스크롤 컨테이너는 화면 구조가 정한다(핸들러가 자동 탐색한다) — 그래서 `window.scrollY`
    // 가 아니라 **대상 섹션이 뷰포트에서 얼마나 위로 올라왔는가** 로 잰다.
    const sectionTop = () => page.evaluate(() => {
      const el = document.getElementById('section_activity_log');

      return el ? el.getBoundingClientRect().top : Number.MAX_SAFE_INTEGER;
    });

    const before = await sectionTop();

    // 탭 버튼의 role 은 `tab` 이다 (WAI-ARIA Tabs 규약) — `button` 으로 찾으면 잡히지 않는다.
    await page.locator('#claim_tab_navigation').getByRole('tab', { name: /활동 로그/ }).click();
    await page.waitForTimeout(1_500);

    const after = await sectionTop();

    expect(after).toBeLessThan(before);
    // 도착 지점이 그 섹션이다 — 스크롤만 움직이고 다른 곳에 서면 클릭이 무의미하다.
    expect(Math.abs(after)).toBeLessThan(260);
  });

  /**
   * scrollSpy 가 섹션 id 접두를 못 맞추면 스크롤해도 탭이 따라오지 않는다.
   */
  // @scenario claim_type=exchange, claim_stage=in_progress
  // @effects detail_scroll_syncs_active_tab
  test('아래로 스크롤하면 활성 탭이 따라온다', async ({ page, request, claimsManageToken }) => {
    const claim = await discoverClaim(request, claimsManageToken);
    test.skip(
      claim === null,
      '클레임 신청서가 한 건도 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월 5) 선행',
    );

    await authenticatePage(page, claimsManageToken);
    await page.goto(DETAIL_URL(claim!.claimNumber));
    await waitForDetail(page);

    await page.evaluate(() => {
      document.getElementById('section_activity_log')?.scrollIntoView({ block: 'center' });
    });
    await page.waitForTimeout(1_500);

    const activeLabels = await page
      .locator('#claim_tab_navigation [role="tab"][aria-selected="true"]')
      .allInnerTexts();

    expect(activeLabels.join(' ')).toContain('활동 로그');
  });

  /**
   * 모바일 대응 코드는 레이아웃에 없다 — 컴포넌트가 768px 미만에서 자체 전환한다.
   * 실측이 유일한 확인 수단이다 (§8-1 표제 축).
   */
  // @scenario claim_type=exchange, claim_stage=in_progress
  // @effects detail_tabs_collapse_to_select_on_mobile
  test('모바일 390 에서 섹션 탭이 Select 로 전환된다', async ({ page, request, claimsManageToken }) => {
    const claim = await discoverClaim(request, claimsManageToken);
    test.skip(
      claim === null,
      '클레임 신청서가 한 건도 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월 5) 선행',
    );

    await page.setViewportSize({ width: 390, height: 844 });
    await authenticatePage(page, claimsManageToken);
    await page.goto(DETAIL_URL(claim!.claimNumber));
    await waitForDetail(page);

    const nav = page.locator('#claim_tab_navigation');

    // 768px 미만에서 탭 목록(role=tablist)이 통째로 사라지고 드롭다운 하나로 바뀐다.
    // 그 드롭다운은 네이티브 `<select>` 가 아니라 커스텀 트리거 버튼이다
    // (`options` 가 있는 Select 의 렌더 구조 — 트러블슈팅 사례).
    await expect(page.locator('#claim_tab_navigation[role="tablist"]')).toHaveCount(0);
    await expect(nav.locator('button[aria-haspopup="listbox"]')).toHaveCount(1);

    // 가로 스크롤이 생기면 390 에서 화면 일부가 손에 닿지 않는다.
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(1);
  });

  /**
   * 송장·메모 저장은 서로 다른 권한이 정한다 — 읽기 권한만으로 컨트롤이 살아 있으면
   * 저장 시점에야 403 이 된다.
   */
  // @scenario claim_type=exchange, claim_stage=in_progress
  // @effects detail_controls_gated_by_write_abilities
  test('읽기 권한만 가진 운영자에게는 저장 컨트롤이 잠긴다', async ({ page, request, claimsReadToken }) => {
    const claim = await discoverClaim(request, claimsReadToken);
    test.skip(
      claim === null,
      '클레임 신청서가 한 건도 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월 5) 선행',
    );

    await authenticatePage(page, claimsReadToken);
    await page.goto(DETAIL_URL(claim!.claimNumber));
    await waitForDetail(page);

    // 응답이 도착해 능력이 반영된 뒤에 본다 — 도착 전 상태를 보면 게이트가 아니라
    // 로딩 때문에 잠긴 컨트롤을 보고 초록이 된다 (S8b 2차 F6 과 같은 함정).
    await expect(page.locator('[data-section-id="claim_info"]')).toBeVisible({ timeout: 30_000 });

    const applyButton = page.locator('#ci_004_item_bulk_apply');
    if (await applyButton.count()) {
      await expect(applyButton).toBeDisabled();
    }

    const memoSave = page.locator('#cm_004_memo_save');
    if (await memoSave.count()) {
      await expect(memoSave).toBeDisabled();
    }

    const collectionSave = page.locator('#cs_006_collection_save');
    if (await collectionSave.count()) {
      await expect(collectionSave).toBeDisabled();
    }
  });

  /**
   * 결제 정보 탭은 유형과 무관하게 있고, 그 안의 금액은 전부 서버가 표기한 문자열이다 —
   * 원 주문의 결제 정보는 `orders.read` 게이트라 화면이 직접 부르면 클레임 권한만 가진
   * 운영자에게만 탭이 비는데, 전권 계정에서는 재현되지 않는다.
   */
  // @scenario claim_type=exchange, cancellable=absent
  // @effects detail_payment_tab_reveals_order_amounts
  test('결제 정보 탭을 누르면 원 주문의 금액 행이 드러난다', async ({ page, request, claimsManageToken }) => {
    const claim = await discoverClaim(request, claimsManageToken);
    test.skip(
      claim === null,
      '클레임 신청서가 한 건도 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월 4) 선행',
    );

    await authenticatePage(page, claimsManageToken);
    await page.goto(DETAIL_URL(claim!.claimNumber));
    await waitForDetail(page);

    // 탭은 전 유형에 있다 — 섹션도 같은 조건으로 함께 선다 (D17-①).
    const paymentTab = page.locator('[role="tab"][data-tab-id="payment_info"], [role="tab"]', {
      hasText: /결제 정보|Payment/,
    }).first();
    await expect(paymentTab).toBeVisible({ timeout: 30_000 });
    await paymentTab.click();

    const section = page.locator('[data-section-id="payment_info"]');
    await expect(section).toHaveCount(1);

    // 정산 블록은 결제 정보 섹션 안의 별도 카드다 — 주문이 얼마를 받았는가와 이 신청서가
    // 얼마를 청구·환불하는가를 한 합계로 읽히게 두지 않는다.
    await expect(page.locator('#claim_settlement_card')).toHaveCount(1);

    // 금액 표기는 서버가 만든다 — 화면이 기호를 붙였다면 원문 키나 조립 잔재가 남는다.
    const sectionText = await section.innerText();
    expect(sectionText).not.toContain('sirsoft-ecommerce.admin.claim.detail');
    expect(sectionText).not.toContain('undefined');
  });

  /**
   * 환불 실행은 환불신청서에만 있는 축이다 — 다른 유형에 탭이 서면 클릭이 아무 일도 하지
   * 않거나 누를 수 있는 컨트롤이 언제나 실패한다.
   */
  // @scenario claim_type=refund, cancellable=present
  // @effects detail_refund_execute_tab_present_only_for_refund
  test('환불 실행 탭과 섹션이 환불신청서에서만 선다', async ({ page, request, claimsManageToken }) => {
    // 환불 축을 보는 테스트다 — 교환을 먼저 고르는 발견 순서를 쓰면 교환이 한 건이라도
    // 있을 때 이 축이 영영 확인되지 않는다. 환불이 없으면 교환·반품 쪽 부재를 확인한다.
    const claim =
      (await discoverClaimOfType(request, claimsManageToken, 'refund'))
      ?? (await discoverClaim(request, claimsManageToken));
    test.skip(
      claim === null,
      '클레임 신청서가 한 건도 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월 4) 선행',
    );

    await authenticatePage(page, claimsManageToken);
    await page.goto(DETAIL_URL(claim!.claimNumber));
    await waitForDetail(page);

    const isRefund = claim!.claimType === 'refund';
    const section = page.locator('[data-section-id="refund_execute"]');

    await expect(section).toHaveCount(isRefund ? 1 : 0);

    // 탭 배열과 섹션 존재는 같은 조건이다 — 한쪽만 서면 클릭이 무반응이 된다.
    const tabIds = await page.locator('[role="tab"]').allInnerTexts();
    expect(tabIds.some((label) => /환불 실행|Refund execution/.test(label))).toBe(isRefund);

    if (!isRefund) {
      return;
    }

    // 세 축과 최종 금액은 서버가 산정하고 서버가 표기한다 (D19).
    await expect(page.locator('#cre_cancellable_card')).toBeVisible({ timeout: 30_000 });
    const cancellableText = await page.locator('#cre_cancellable_card').innerText();
    expect(cancellableText).not.toContain('sirsoft-ecommerce.admin.claim.detail');
  });

  /**
   * 환불은 되돌릴 수 없는 처리라 [환불하기]가 곧바로 실행하지 않는다. 확인 모달이 예고하는
   * 값은 지금 입력된 값이어야 한다 — 모달은 부모 화면의 `_local` 을 해석하지 못하므로 여는
   * 핸들러가 전역 문맥으로 넘긴 값을 읽는다.
   *
   * 실행은 하지 않는다 — 재고·마일리지·PG 까지 되돌리기 어려운 처리라 열림과 예고값까지만
   * 본다 (S9a 가 완료 처리 모달에 대해 세운 규율과 같다).
   */
  // @scenario claim_type=refund, cancellable=present
  // @effects detail_refund_execute_confirm_modal_opens
  test('[환불하기] 는 곧바로 실행하지 않고 확인 모달을 연다', async ({ page, request, claimsManageToken }) => {
    const claim = await discoverClaimOfType(request, claimsManageToken, 'refund');
    test.skip(
      claim === null,
      '환불신청서가 한 건도 없다 — 환불 실행 섹션은 그 유형에만 있다',
    );

    await authenticatePage(page, claimsManageToken);
    await page.goto(DETAIL_URL(claim!.claimNumber));
    await waitForDetail(page);

    const executeButton = page.locator('#cre_014_execute_button');
    await expect(executeButton).toHaveCount(1);

    if (await executeButton.isDisabled()) {
      // 실행 권한이 없는 토큰이면 게이팅이 정답이다 — 그 사실을 단언하고 끝낸다.
      return;
    }

    // 실행 요청이 나가면 실패다 — 확인 모달을 거치지 않고 실행했다는 뜻이다.
    let executed = false;
    page.on('request', (req) => {
      if (req.url().includes('/admin/refunds/') && req.url().endsWith('/execute')) {
        executed = true;
      }
    });

    await executeButton.click();

    await expect(page.locator('#crm_005_total')).toBeVisible({ timeout: 15_000 });
    expect(executed).toBe(false);

    // 모달이 예고하는 합계는 화면의 입력값에서 온다 — 비어 있으면 문맥이 도달하지 않은 것이다.
    const total = (await page.locator('#crm_005_total').innerText()).trim();
    expect(total.length).toBeGreaterThan(0);
    expect(total).not.toContain('undefined');

    await page.locator('#crm_007_cancel').click();
    await expect(page.locator('#crm_005_total')).toHaveCount(0);
  });

  /**
   * 신청상품 표의 금액 칸이 좌우 같은 단위(소계)를 말하고, 차액 줄에 금액이 함께 나온다.
   *
   * 두 축 모두 브라우저에서만 드러난다 — 차액 줄은 DataGrid 셀 안이라 `$t:defer:` 로
   * 미뤄야 항목 컨텍스트와 함께 해소되고(일반 `$t:` 는 라벨만 남는다), 소계·단가 혼재는
   * 수량 1 에서는 값이 같아 보인다.
   */
  // @scenario claim_type=exchange, claim_stage=in_progress
  // @effects detail_items_amount_pairs_subtotal, detail_items_price_difference_shows_amount
  test('신청상품 표가 교환 전후 소계를 짝으로 그리고 차액 금액을 함께 보여준다', async ({ page, request, claimsManageToken }) => {
    const claim = await discoverClaimOfType(request, claimsManageToken, 'exchange');
    test.skip(claim === null, '교환 신청서가 한 건도 없다 — 이 표의 교환 열은 그 유형에만 선다');

    await authenticatePage(page, claimsManageToken);
    await page.goto(DETAIL_URL(claim!.claimNumber));
    await waitForDetail(page);

    // 서버가 그 행에 무엇을 실었는지 먼저 읽는다 — 화면이 그리는 값의 정답지다.
    const payload = await request.get(
      `/api/modules/sirsoft-ecommerce/admin/claims/${encodeURIComponent(claim!.claimNumber)}`,
      { headers: { Authorization: `Bearer ${claimsManageToken}`, Accept: 'application/json' } },
    );
    expect(payload.ok()).toBe(true);
    const items = (await payload.json())?.data?.claim?.items ?? [];
    const optionChange = items.find((item: any) => item?.is_option_change === true);
    test.skip(!optionChange, '옵션 변경 교환 행이 없다 — 교환 후 금액 줄은 그 행에만 선다');

    await expect(page.locator('#claim_items_card')).toBeVisible({ timeout: 15_000 });

    const bodyText = await page.locator('body').innerText();

    // 좌우가 같은 단위 — 교환 후 금액은 단가가 아니라 소계다.
    expect(bodyText).toContain(String(optionChange.subtotal_amount_formatted));
    expect(bodyText).toContain(String(optionChange.new_subtotal_amount_formatted));

    // 차액이 있는 행이면 금액이 라벨과 함께 나온다 — 라벨만 남으면 치환이 죽은 것이다.
    if (Number(optionChange.price_difference ?? 0) !== 0) {
      expect(bodyText).toContain(String(optionChange.price_difference_formatted));
    }

    // 교환에 얹은 추가옵션은 이름과 직접 입력 문구까지 보인다.
    for (const option of optionChange.new_additional_options ?? []) {
      expect(bodyText).toContain(String(option.name));

      if (option.custom_text) {
        expect(bodyText).toContain(String(option.custom_text));
      }
    }
  });
});
