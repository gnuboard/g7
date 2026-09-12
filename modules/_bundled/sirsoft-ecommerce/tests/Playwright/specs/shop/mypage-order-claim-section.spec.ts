/**
 * 회원 주문상세 '이 주문의 클레임' 섹션 — 신청번호 새 창 링크 · 썸네일 자리표시 · 서브탭 왕복 (#599)
 *
 * 브라우저로만 드러나는 축을 본다.
 *
 *  ① **번호가 새 창으로 열리는가.** 같은 창에서 열리면 주문 상세의 작업 맥락(고른 상품·서브탭)이
 *     사라진다. `<a target=_blank>` 가 실제로 새 탭을 만드는지와 그 탭이 그 신청서 주소인지는
 *     DOM 속성만으로는 알 수 없다.
 *  ② **이미지 없는 상품의 자리.** 자리표시 없이 Img 만 조건부로 두면 줄의 시작점이 흔들리는데
 *     jsdom 은 레이아웃을 재지 않는다.
 *  ③ **서브탭이 주문 스코프 목록을 유형별로 다시 부르는가.** 회원 목록 API 는 주문 ID 를
 *     받으므로 이 화면은 주문 응답 뒤 체인으로 목록을 부른다 — 서브탭 전환이 그 체인을 다시
 *     태우지 않으면 첫 유형의 목록이 그대로 남는다(오류 없음).
 *
 * 응답은 전부 가로챈다 — 새로 발급된 회원은 주문이 0건이라 실제 데이터로는 이 섹션이 서지 않는다.
 *
 * 매니페스트 마킹(@scenario/@effects)은 **테스트마다** 그 케이스 위에 둔다.
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import {
  API,
  MOCK_ORDER,
  claimRow,
  grantFunctionalConsent,
  mockClaimable,
  mockOrder,
  mockOrderClaimList,
  waitForApp,
} from '../../fixtures/claim-screens';
import type { Page } from '@playwright/test';

/** 화면 전체 글자 (공백 정규화) */
async function screenText(page: Page): Promise<string> {
  return ((await page.locator('body').innerText()) ?? '').replace(/\s+/g, ' ');
}

/**
 * 주문 상세를 열고 클레임 섹션의 첫 카드가 도착할 때까지 기다립니다.
 *
 * @param page Playwright 페이지
 * @param claimNumber 도착의 신호가 되는 신청번호
 * @param query 주소에 붙일 쿼리스트링
 * @returns 완료 Promise
 */
async function openOrderDetail(page: Page, claimNumber: string, query = ''): Promise<void> {
  await page.goto(`/mypage/orders/${encodeURIComponent(MOCK_ORDER.number)}${query}`);
  await waitForApp(page);
  await expect
    .poll(async () => (await screenText(page)).includes(claimNumber), { timeout: 30_000 })
    .toBe(true);
}

test.describe('회원 주문상세 클레임 섹션', () => {
  // 목록 진입 → 주문 응답 → 체인 조회 → 새 창까지 한 테스트 안에서 이어지므로 기본 30초를 넘긴다.
  test.describe.configure({ timeout: 90_000 });

  test.beforeEach(async ({ page, memberToken }) => {
    await grantFunctionalConsent(page);
    await authenticatePage(page, memberToken);
    await mockOrder(page, { order_status_label: '배송완료', progress_steps: [] });
    await mockClaimable(page, []);
  });

  // @scenario surface=member, has_image=no
  // @effects claim_page_keeps_scroll_and_focus_without_order_refetch
  test('페이지 이동은 주문을 다시 조회하지 않고 보고 있던 위치를 유지한다', async ({ page }) => {
    await page.route(`**${API}/user/orders/*/claims?**`, async route => {
      const current = Number(new URL(route.request().url()).searchParams.get('page') || 1);
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: {
        data: [claimRow({ claim_number: `EX-PAGE-${current}` })],
        claim_type: 'exchange', counts: { exchange: 21 },
        pagination: { current_page: current, per_page: 20, total: 21, last_page: 2 },
      } }) });
    });
    await openOrderDetail(page, 'EX-PAGE-1');
    const second = page.getByRole('button', { name: '페이지 2', exact: true });
    await second.scrollIntoViewIfNeeded();
    await second.focus();
    const before = await page.evaluate(() => ({ y: scrollY, origin: performance.timeOrigin }));
    const requests: string[] = [];
    page.on('request', request => { if (request.url().includes('/user/orders/')) requests.push(request.url()); });
    await second.click();
    await expect(page.getByText('EX-PAGE-2', { exact: true })).toBeVisible();
    expect(new URL(page.url()).searchParams.get('page')).toBe('2');
    expect(await page.evaluate(() => performance.timeOrigin)).toBe(before.origin);
    expect(await page.evaluate(() => scrollY)).toBeCloseTo(before.y, 0);
    await expect(second).toBeFocused();
    expect(requests.every(url => new URL(url).pathname.endsWith('/claims'))).toBe(true);
  });

  // @scenario surface=member, has_image=no
  // @effects claim_async_buttons_show_spinner_and_block_duplicate_requests
  test('탭 조회 중 스피너와 중복 클릭 차단이 유지되고 실패 후 다시 누를 수 있다', async ({ page }) => {
    await mockOrderClaimList(page, { exchange: [claimRow({ claim_number: 'EX-PENDING' })] });
    await openOrderDetail(page, 'EX-PENDING');
    let release!: () => void;
    const held = new Promise<void>(resolve => { release = resolve; });
    let count = 0;
    await page.route(`**${API}/user/orders/*/claims?type=return&page=1`, async route => {
      count++;
      await held;
      await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'test failure' }) });
    });
    const tab = page.getByRole('button', { name: /^반품( \d+)?$/ });
    await tab.scrollIntoViewIfNeeded();
    await tab.focus();
    const element = await tab.elementHandle();
    try {
      await element!.evaluate((button: HTMLButtonElement) => button.click());
      await expect.poll(() => count).toBe(1);
      expect(await element!.evaluate(button => button.querySelectorAll('.animate-spin').length)).toBe(1);
      expect(await element!.getAttribute('aria-disabled')).toBe('true');
      await element!.evaluate((button: HTMLButtonElement) => button.click());
      expect(count).toBe(1);
    } finally {
      release();
    }
    await expect.poll(() => element!.getAttribute('aria-disabled')).toBe('false');
    expect(await element!.evaluate(button => button.querySelectorAll('.animate-spin').length)).toBe(0);
    expect(await element!.evaluate(button => document.activeElement === button)).toBe(true);
  });

  // @scenario surface=member, has_image=no
  // @effects member_order_detail_number_opens_detail_in_new_window, order_claim_section_thumbnail_placeholder_when_missing
  test('신청번호가 새 창으로 그 신청서 상세를 열고, 이미지 없는 상품은 자리표시가 선다', async ({ page }) => {
    await mockOrderClaimList(page, {
      exchange: [claimRow({ claim_number: 'EX20260902-0900' })],
    });

    // 새 창은 이 페이지의 가로채기를 물려받지 않는다 — 상세 응답은 컨텍스트 단위로 세운다.
    await page.context().route(`**${API}/user/claims/EX20260902-0900*`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          message: null,
          data: { claim: { ...claimRow({ claim_number: 'EX20260902-0900' }), images: [] }, progress_steps: [] },
        }),
      });
    });

    await openOrderDetail(page, 'EX20260902-0900');

    expect(await screenText(page), '클레임 섹션 제목이 없다').toContain('이 주문의 취소·반품·교환');

    const link = page.locator('a[target="_blank"]', { hasText: 'EX20260902-0900' });
    await expect(link).toHaveCount(1);
    expect(await link.getAttribute('href')).toBe('/mypage/claims/EX20260902-0900');
    // 번호가 곧 링크라 푸터 [상세보기] 는 없다
    await expect(page.getByRole('button', { name: '상세보기' })).toHaveCount(0);

    // 이미지 없는 상품 — 자리표시 아이콘이 서고 img 는 없다 (claimRow 의 thumbnail_url 은 null)
    const card = page.locator('[id^="claim_list_number_link_"]').first().locator('xpath=ancestor::div[contains(@class,"rounded-lg")][1]');
    await expect(card.locator('i[class*="fa-image"]')).toHaveCount(1);
    await expect(card.locator('img')).toHaveCount(0);

    const [opened] = await Promise.all([page.context().waitForEvent('page'), link.click()]);
    await opened.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    expect(new URL(opened.url()).pathname).toBe('/mypage/claims/EX20260902-0900');
    // 원래 창은 주문 상세에 그대로 남는다
    expect(new URL(page.url()).pathname).toBe(`/mypage/orders/${MOCK_ORDER.number}`);

    await opened.close();
  });

  /**
   * 종전에는 늘 교환 탭이라 취소만 있는 주문에서 '접수된 건이 없습니다' 가 먼저 보였다. 주소에
   * 유형이 없으면 화면은 type 을 비워 보내고 서버가 접수 건이 있는 첫 유형을 골라 응답
   * claim_type 으로 알려 준다 — 아무것도 누르지 않은 상태를 본다.
   */
  // @scenario surface=member, has_image=no
  // @effects order_claim_section_active_subtab_follows_server_default, member_order_detail_sends_no_type_when_address_has_none
  test('주소에 유형이 없으면 접수 건이 있는 유형이 먼저 열린다 — 누르기 전에', async ({ page }) => {
    const requested = await mockOrderClaimList(page, {
      return: [
        claimRow({
          claim_number: 'RT20260902-0920',
          claim_type: 'return',
          claim_type_label: '반품',
          status_label: '반품신청',
          items: [{ product_name: '가죽 크로스백', product_option_name: '블랙 / M', quantity: 1, thumbnail_url: null, new_additional_options: [] }],
        }),
      ],
    });

    await openOrderDetail(page, 'RT20260902-0920');

    // 활성 서브탭은 진한 밑줄(border-gray-900)이다 — 반품만 활성이고 교환은 아니다
    await expect(page.getByRole('button', { name: /^반품( \d+)?$/ })).toHaveClass(/border-gray-900/);
    await expect(page.getByRole('button', { name: /^교환( \d+)?$/ })).not.toHaveClass(/border-gray-900/);
    expect(new URL(page.url()).searchParams.get('type')).toBeNull();

    // 화면이 유형을 정하지 않았다 — 나간 요청의 type 은 전부 비어 있다
    expect(requested.length).toBeGreaterThan(0);
    expect(requested.every((u) => !u.searchParams.get('type'))).toBe(true);
  });

  // @scenario surface=member, has_image=yes
  // @effects member_order_detail_claim_list_is_paginated_by_type, claim_tab_keeps_scroll_and_focus_without_order_refetch
  test('서브탭 전환이 주문 스코프 목록을 그 유형으로 다시 부른다', async ({ page }) => {
    const requested = await mockOrderClaimList(page, {
      exchange: [claimRow({ claim_number: 'EX20260902-0910' })],
      return: [
        claimRow({
          claim_number: 'RT20260902-0911',
          claim_type: 'return',
          claim_type_label: '반품',
          status_label: '반품신청',
          items: [{ product_name: '가죽 크로스백', product_option_name: '블랙 / M', quantity: 1, thumbnail_url: 'https://example.test/bag.jpg', new_additional_options: [] }],
        }),
      ],
    });

    await openOrderDetail(page, 'EX20260902-0910');

    const tab = page.getByRole('button', { name: /^반품( \d+)?$/ });
    await tab.scrollIntoViewIfNeeded();
    await tab.focus();
    const before = await page.evaluate(() => ({ y: scrollY, origin: performance.timeOrigin }));
    const requests: string[] = [];
    page.on('request', request => { if (request.url().includes(API)) requests.push(request.url()); });
    await tab.click();

    await expect
      .poll(() => new URL(page.url()).searchParams.get('type'), { timeout: 15_000 })
      .toBe('return');
    await expect
      .poll(async () => (await screenText(page)).includes('RT20260902-0911'), { timeout: 30_000 })
      .toBe(true);

    // 목록 요청이 유형별로 나갔다 — 주문 ID 스코프 경로로. 진입 요청은 유형을 비워 보내고(서버가
    // 접수 건이 있는 첫 유형을 고른다), 서브탭을 누른 뒤에는 그 유형을 실어 보낸다.
    expect(requested.some((u) => !u.searchParams.get('type'))).toBe(true);
    expect(requested.some((u) => u.searchParams.get('type') === 'return')).toBe(true);
    expect(requested.every((u) => u.pathname.endsWith(`/user/orders/${MOCK_ORDER.id}/claims`))).toBe(true);
    expect(await page.evaluate(() => performance.timeOrigin)).toBe(before.origin);
    expect(await page.evaluate(() => scrollY)).toBeCloseTo(before.y, 0);
    await expect(tab).toBeFocused();
    expect(requests.filter(url => url.includes('/user/orders/')).every(url => new URL(url).pathname.endsWith('/claims'))).toBe(true);

    // 이미지가 있는 상품은 썸네일이 선다
    await expect(page.locator('img[src="https://example.test/bag.jpg"]')).toHaveCount(1);
  });
});
