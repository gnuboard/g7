/**
 * E2E: 사용자 화면 입력칸의 접근 가능한 이름 (#757)
 *
 * 결함: 사용자 템플릿에는 곁 라벨 자동 연결이 없어, 레이아웃이 이름을 달지 않은 선택칸·체크박스가 화면 낭독기에서
 *   「콤보 상자」「체크박스」로만, 목록형 선택칸(options 모드)은 고른 값만(「제목+내용, 단추」) 읽혔다.
 *   레이아웃마다 이름을 달고, 목록형 Select 는 단추를 「{이름} {현재 값}」으로 읽히게 고쳤다.
 *
 * 단위/정적:
 *   - __tests__/layouts/control-accessible-names.test.ts — 레이아웃 전수의 이름 없는 칸 0
 *   - src/components/basic/__tests__/Select.accessibleName.test.tsx — 목록형 단추 이름 계약
 *   이 spec 은 브라우저 접근성 트리(getByRole name)로 실제 읽기 이름을 확인한다.
 *
 * 화면 언어는 ko 기준이다(이름 문자열을 글자 그대로 단언).
 * 라이브 쓰기(전부 되돌린다):
 *   - 회원정보 수정·주문서는 비밀번호를 아는 시드 회원(`playwright:seed-password-expiry --stage=fresh`, user 역할)으로
 *     실제 비밀번호 확인 게이트를 지난다(상태 조작으로 게이트를 건너뛰지 않는다). 계정은 afterAll 의 `--purge-users` 로 지운다.
 *   - 주문서는 그 회원 장바구니에 상품 하나를 실제 장바구니 API 로 담고, 끝나면 finally 에서 장바구니를 비운다.
 *   - 마이페이지 필터는 권한 없는 일반 회원 토큰으로 읽기만 한다.
 *
 * @scenario-manifest tests/scenarios/control-accessible-names.yaml
 */
import { test, expect, type Page } from '@playwright/test';

import { issueScopedToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';
import { runSeedPasswordExpiry, seedAccount, type SeededAccount } from '../../../../../../tests/Playwright/fixtures/password-expiry';

test.describe.configure({ mode: 'serial' });

/** 비밀번호를 아는 시드 회원 — 회원정보 수정 게이트와 주문서에 쓴다(한 번 만들어 같이 쓴다) */
let member: SeededAccount | null = null;

/**
 * 시드 회원을 만들거나 이미 만든 것을 돌려준다.
 *
 * @returns 시드 회원
 */
function seededMember(): SeededAccount {
  if (!member) member = seedAccount('fresh', { issueToken: true });

  return member;
}

test.afterAll(() => {
  // 시드 회원(pwexp-…@playwright.local)을 지운다 — 장바구니는 각 시험의 finally 가 이미 비웠다
  if (member) runSeedPasswordExpiry(['--purge-users']);
});

/**
 * GDPR 쿠키 동의 배너를 닫습니다(새 컨텍스트에는 동의 이력이 없어 하단에 뜬다). 없으면 조용히 지나간다.
 *
 * @param page 대상 페이지
 */
async function dismissCookieBanner(page: Page): Promise<void> {
  const accept = page.getByRole('button', { name: '모두 동의' }).first();

  try {
    await accept.waitFor({ state: 'visible', timeout: 3000 });
  } catch {
    return;
  }

  // 배너는 동의 상태 조회가 끝나면 스스로 다시 그려지거나 사라진다 — 그 사이 클릭은 「요소가 안정되지 않음 → DOM 에서 빠짐」으로
  // 다시 나타나지 않는 단추를 시험 제한까지 기다렸다(실행 트레이스로 확인). 짧게 시도하고, 스스로 사라졌으면 닫힌 것으로 본다.
  try {
    await accept.click({ timeout: 5000 });
  } catch {
    // 이미 사라졌다
  }
  await accept.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => undefined);
}

/**
 * 일반 회원으로 로그인해 경로를 엽니다(화면 언어 ko).
 *
 * @param page 대상 페이지
 * @param path 열 경로
 */
async function openAsMember(page: Page, path: string, token: string = issueScopedToken()): Promise<void> {
  await page.addInitScript(() => {
    try {
      localStorage.setItem('g7_locale', 'ko');
    } catch {
      // 저장소 접근이 막힌 환경 — 기본 언어로 진행
    }
  });
  await authenticatePage(page, token);
  await page.goto(path);
  await page.waitForFunction(() => typeof (window as unknown as { G7Core?: unknown }).G7Core === 'object', null, { timeout: 60_000 });
  await dismissCookieBanner(page);
}

test.describe('#757 사용자 화면 입력칸 이름', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
  });

  /**
   * 마이페이지 「내가 쓴 글」 필터 — 게시판·정렬은 기본 select(콤보 상자), 검색 대상은 목록형 단추(이름 + 값).
   *
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name, listbox_reads_name_and_value
   */
  test('마이페이지 게시글 필터가 「게시판」「정렬 기준」「검색 대상 {값}」으로 읽힌다', async ({ page }) => {
    await openAsMember(page, '/mypage/board');

    await expect(page.getByRole('combobox', { name: '게시판', exact: true }).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('combobox', { name: '정렬 기준', exact: true }).first()).toBeVisible();
    // 목록형 단추는 이름 뒤에 현재 값이 붙는다 — 기본값은 「제목+내용」
    await expect(page.getByRole('button', { name: '검색 대상 제목+내용', exact: true }).first()).toBeVisible();
  });

  /**
   * 목록형 단추의 값이 바뀌면 이름 뒤 값도 바뀐다(값 span 을 aria-labelledby 로 잇기 때문).
   *
   * @scenario surface=user_layout
   * @effects listbox_reads_name_and_value
   */
  test('검색 대상을 바꾸면 단추 이름이 「검색 대상 제목」으로 바뀐다', async ({ page }) => {
    await openAsMember(page, '/mypage/board');

    const trigger = page.getByRole('button', { name: '검색 대상 제목+내용', exact: true }).first();
    await expect(trigger).toBeVisible({ timeout: 30_000 });
    await trigger.click();
    await page.getByRole('option', { name: '제목', exact: true }).first().click();

    await expect(page.getByRole('button', { name: '검색 대상 제목', exact: true }).first()).toBeVisible();
  });

  /**
   * 회원정보 수정 — 보이는 제목(국가·언어·시간대)이 목록형 단추 이름의 앞부분이 된다.
   *
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name, listbox_reads_name_and_value
   */
  test('회원정보 수정의 국가·언어·시간대 선택칸이 제목으로 읽힌다', async ({ page }) => {
    const account = seededMember();
    await openAsMember(page, '/mypage/profile/edit', account.otherDeviceToken!);

    // 비밀번호 확인 게이트를 실제로 지난다. 확인 단추는 입력값이 상태에 들어가야 풀린다(disabled = !_local.verifyPassword) —
    // 풀리기 전에 Enter 를 누르면 브라우저가 비활성 제출 단추의 암묵 제출을 버려 요청이 나가지 않는다(실측 트레이스:
    // 입력 0.1초 뒤 Enter, 이후 26초간 verify-password 요청 0). 그래서 보이는 확인 단추가 풀린 뒤 누른다.
    const verify = page.locator('#verifyPasswordForm input[name="password"]');
    await expect(verify).toBeVisible({ timeout: 30_000 });
    await verify.fill(account.password);
    const confirm = page.locator('button[type="submit"][form="verifyPasswordForm"]:visible').first();
    await expect(confirm).toBeEnabled({ timeout: 10_000 });
    const [verified] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/api/me/verify-password') && r.request().method() === 'POST'),
      confirm.click(),
    ]);
    expect(verified.status(), '비밀번호 확인 응답').toBe(200);

    // placeholder 로만 읽히던 칸이 보이는 제목으로 읽힌다
    for (const title of ['이름', '닉네임', '휴대폰 번호']) {
      await expect(page.getByRole('textbox', { name: title, exact: true }).first(), `「${title}」 칸의 읽기 이름`).toBeVisible({ timeout: 30_000 });
    }

    for (const title of ['국가', '언어', '시간대']) {
      // 값은 회원마다 다르므로 「제목 + 공백」으로 시작하는지만 본다
      await expect(
        page.getByRole('button', { name: new RegExp(`^${title}( |$)`) }).first(),
        `「${title}」 선택칸의 읽기 이름이 제목으로 시작하지 않습니다`,
      ).toBeVisible({ timeout: 30_000 });
    }
  });

  /**
   * 주문 내역 상태 필터 — 기본 select 에 「주문 상태」 이름.
   *
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name
   */
  test('주문 내역 상태 필터가 「주문 상태」로 읽힌다', async ({ page }) => {
    await openAsMember(page, '/mypage/orders');

    await expect(page.getByRole('combobox', { name: '주문 상태', exact: true }).first()).toBeVisible({ timeout: 30_000 });
  });

  /**
   * 장바구니·주문서 — 시드 회원 장바구니에 상품 하나를 담아 실제 「주문하기」 흐름으로 주문서를 열고, 끝나면 장바구니를 비운다.
   * 회원 주문서의 주문 쿠폰·배송비 쿠폰·배송 메모는 기본 select, 배송 국가(해외배송 켠 경우)는 목록형 단추다.
   *
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name
   */
  test('주문서의 쿠폰·배송 메모·환불 은행 선택칸이 제목으로 읽힌다', async ({ page, request }) => {
    // 장바구니 → 새로고침 → 주문하기(임시 주문) → 주문서 → 결제수단 전환까지 화면 네 번을 지나는 시험이라 기본 30초보다 넉넉히 둔다
    test.setTimeout(90_000);
    const account = seededMember();
    await openAsMember(page, '/shop/cart', account.otherDeviceToken!);

    // 옵션을 고르지 않아도 담기는 상품 하나를 실제 장바구니 API 로 담는다(여러 옵션 상품은 서버가 거절 → 다음 상품)
    const addedProductId = await page.evaluate(async () => {
      const token = localStorage.getItem('auth_token');
      const headers = { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };
      const list = await (await fetch('/api/modules/sirsoft-ecommerce/products?per_page=30', { headers })).json();
      const rows: Array<{ id: number }> = list?.data?.data ?? list?.data ?? [];
      for (const row of rows) {
        const res = await fetch('/api/modules/sirsoft-ecommerce/cart', {
          method: 'POST',
          headers,
          body: JSON.stringify({ product_id: row.id, items: [{ quantity: 1 }] }),
        });
        if (res.ok) return row.id;
      }

      return null;
    });

    try {
      expect(addedProductId, '옵션 없이 담기는 상품이 없어 주문서를 열 수 없습니다 — 판매 중인 단일 옵션 상품이 필요합니다').not.toBeNull();

      await page.reload();
      await page.waitForFunction(() => typeof (window as unknown as { G7Core?: unknown }).G7Core === 'object', null, { timeout: 60_000 });
      await dismissCookieBanner(page);

      // 장바구니 품목 체크박스는 「{상품명} 선택」으로 읽힌다
      const item = page.locator('[data-testid="cart-item"]').first();
      await expect(item).toBeVisible({ timeout: 30_000 });
      await expect(item.getByRole('checkbox', { name: / 선택$/ })).toBeVisible();

      // 실제 흐름으로 주문서에 들어간다 — 주문하기가 임시 주문을 만들고 주문서로 옮긴다.
      // 단추는 선택 품목·주문 권한·배송 가능 여부가 갖춰져야 풀린다 — 풀리지 않으면 그 사유로 실패한다(무한 대기 금지)
      // 레이아웃 data-ref 는 DOM 에서 id 로 그려진다
      const order = page.locator('#cart_order_button, button:has-text("주문하기")').first();
      await expect(order, '주문하기 단추가 풀리지 않습니다(선택 품목·주문 권한·배송 가능 여부 확인)').toBeEnabled({ timeout: 15_000 });
      await order.click();
      await page.waitForURL(/\/checkout/, { timeout: 30_000 });
      await dismissCookieBanner(page);

      await expect(page.getByRole('combobox', { name: '배송 메모', exact: true }).first()).toBeVisible({ timeout: 30_000 });
      await expect(page.getByRole('combobox', { name: '주문 쿠폰', exact: true }).first()).toBeVisible();
      await expect(page.getByRole('combobox', { name: '배송비 쿠폰', exact: true }).first()).toBeVisible();
      // placeholder 로만 읽히던 주문자·수령인 칸이 보이는 제목으로 읽힌다
      await expect(page.getByRole('textbox', { name: '주문자명', exact: true }).first()).toBeVisible();

      // 환불 은행 선택칸 — 무통장입금(없으면 가상계좌)을 고르면 환불 계좌 카드가 열린다. 목록형 단추라
      // 아무것도 안 골랐을 때는 이름만(안내 선택지 「은행 선택」 은 이름으로 시작하는 자리표시), 은행을 고르면 「은행 {은행명}」.
      const bankMethod = page.locator('[data-testid="checkout-payment-method-dbank"], [data-testid="checkout-payment-method-vbank"]').first();
      await expect(bankMethod, '무통장입금·가상계좌 결제수단이 꺼져 있어 환불 은행 칸이 열리지 않습니다').toBeVisible();
      await bankMethod.click();
      const refundBank = page.getByRole('button', { name: '은행', exact: true }).first();
      await expect(refundBank).toBeVisible({ timeout: 15_000 });
      await refundBank.click();
      // 펼친 은행 목록 안에서만 고른다 — 주문서의 기본 select(쿠폰 등)도 option 을 품어 페이지 전체로 찾으면 숨은 그쪽 option 이 잡힌다
      const bankList = page.locator('[role="listbox"]:visible').first();
      await expect(bankList).toBeVisible();
      await bankList.getByRole('option').nth(1).click();
      await expect(page.getByRole('button', { name: /^은행 \S/ }).first(), '은행을 고른 뒤 「은행 {은행명}」 으로 읽혀야 합니다').toBeVisible();
    } finally {
      // 담은 것을 되돌린다 — 이 회원의 장바구니 전체 비우기(시드 회원 전용 장바구니라 다른 데이터에 닿지 않는다).
      // 시험이 시간 초과로 끊겨 창이 닫혀도 정리되도록 창이 아니라 API 요청 문맥으로 지운다(afterAll 시드 회원 삭제가 이중 안전망)
      await request
        .delete('/api/modules/sirsoft-ecommerce/cart/all', {
          headers: { Accept: 'application/json', Authorization: `Bearer ${account.otherDeviceToken}` },
        })
        .catch(() => undefined);
    }
  });
});
