/**
 * 이커머스 관리자 입력칸 이름 — 화면 낭독기가 읽는 이름(접근성 트리) 회귀 (#757).
 *
 * 배경: 제목이 표식 없는 span·표 열 머리인 칸, 표·반복 행 칸, 체크박스 옆 글자가 label 이 아닌 칸이
 * 이름 없이 「편집 가능 텍스트」·「체크박스」·「스위치」로만 읽혔다. 레이아웃이 「{행 이름} {열 이름}」·
 * 제목 키·감싼 label 로 이름을 달았다. 이름 문자열은 접근성 트리(`getByRole(…, { name })`)로 단언한다.
 *
 * 측정 규율 (이 spec 을 고칠 사람에게):
 *  - 화면 언어를 ko 로 고정한다 — 세션 로케일이 en 이면 「판매가」 가 `Selling price` 로 읽혀 단언이 통째로 샌다.
 *  - 상품·배송정책 이름은 하드코딩하지 않고 실행 시점에 API 로 찾는다(시드가 정리되면 spec 이 「대상 없음」과
 *    구분되지 않게 죽는다).
 *  - 다국어칸 이름은 켜진 언어가 둘 이상이면 「상품명 (한국어)」, 하나면 「상품명」 이다(D7) — 둘 다 허용한다.
 *
 * @scenario surface=admin_layout|admin_modal|admin_row
 * @effects controls_have_accessible_name, row_controls_read_row_and_column
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

const PRODUCTS_API = '/api/modules/sirsoft-ecommerce/admin/products?per_page=40';
const PRODUCT_DETAIL_API = '/api/modules/sirsoft-ecommerce/admin/products';
const SHIPPING_POLICIES_API = '/api/modules/sirsoft-ecommerce/admin/shipping-policies';
const ORDERS_API = '/api/modules/sirsoft-ecommerce/admin/orders?per_page=20';
/** 행 선택 이름이 일시 원문(「2026-10-07T02:50:00+00:00 선택」)이면 안 된다 */
const ISO_ROW_NAME = /^\d{4}-\d{2}-\d{2}T/;
/** 행 선택 체크박스 — 머리줄 「전체 선택」 은 빼고 센다(그것이 먼저 그려져 행이 오기 전에 대기가 끝났다) */
const ROW_SELECT_NAME = /^(?!전체 선택$).+ 선택$/;

/**
 * 정규식 특수문자를 이스케이프합니다.
 *
 * @param text 원문
 * @return 이스케이프한 글자
 */
function escapeRegExp(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * 인증된 페이지에서 관리자 API 목록 행을 읽습니다.
 *
 * @param page 인증이 적용된 페이지 (auth_token 이 localStorage 에 있어야 한다)
 * @param url 목록 API 주소
 * @return 행 목록 (실패하면 빈 배열)
 */
async function apiRows(page: Page, url: string): Promise<any[]> {
  return page.evaluate(async (u) => {
    const token = localStorage.getItem('auth_token') ?? '';
    const res = await fetch(u, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
    if (!res.ok) return [];
    const json = await res.json();

    return json?.data?.data ?? json?.data ?? [];
  }, url);
}

/**
 * 옵션이 둘 이상인 상품의 숫자 id 와 첫 옵션의 한국어 이름을 찾습니다.
 *
 * @param page 인증이 적용된 페이지
 * @return 상품 id·옵션명 (없으면 null)
 */
async function findProductWithOptions(page: Page): Promise<{ id: number; optionName: string } | null> {
  return page.evaluate(
    async ({ listApi, detailApi }) => {
      const token = localStorage.getItem('auth_token') ?? '';
      const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` };
      const list = await fetch(listApi, { headers });
      if (!list.ok) return null;
      const rows = (await list.json())?.data?.data ?? [];
      for (const row of rows) {
        const res = await fetch(`${detailApi}/${row.id}`, { headers });
        if (!res.ok) continue;
        const options = (await res.json())?.data?.options ?? [];
        const name = options[0]?.option_name?.ko;
        if (options.length >= 2 && typeof name === 'string' && name.trim() !== '') {
          return { id: Number(row.id), optionName: name };
        }
      }

      return null;
    },
    { listApi: PRODUCTS_API, detailApi: PRODUCT_DETAIL_API },
  );
}

/**
 * 후보 이름 중 「{이름}{꼬리}」 로 읽히는 칸이 화면에 있는 첫 이름을 찾습니다(접근성 이름 기준).
 *
 * @param page 페이지
 * @param role 칸 역할
 * @param candidates 후보 행 이름
 * @param suffix 열 이름 꼬리(앞 공백 포함)
 * @return 찾은 행 이름 (없으면 undefined)
 */
async function firstNamed(
  page: Page,
  role: 'spinbutton' | 'switch',
  candidates: string[],
  suffix: string,
): Promise<string | undefined> {
  for (const name of candidates) {
    if ((await page.getByRole(role, { name: `${name}${suffix}`, exact: true }).count()) > 0) return name;
  }

  return undefined;
}

/**
 * 후보 행 이름 중 「{이름} 선택」 으로 읽히는 체크박스가 있는 첫 이름을 찾습니다.
 *
 * @param page 페이지
 * @param candidates 후보 행 이름
 * @return 찾은 행 이름 (없으면 undefined)
 */
async function firstNamedCheckbox(page: Page, candidates: string[]): Promise<string | undefined> {
  for (const name of candidates) {
    if ((await page.getByRole('checkbox', { name: `${name} 선택`, exact: true }).count()) > 0) return name;
  }

  return undefined;
}

test.describe('이커머스 관리자 입력칸 이름 (#757)', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.setViewportSize({ width: 1400, height: 900 });
  });

  // @scenario surface=admin_row
  // @effects row_controls_read_row_and_column
  test('상품 목록 — 행 숫자칸이 「{상품명} 판매가」·「{상품명} 재고」 로 읽힌다', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto('/admin/ecommerce/products');
    const names: string[] = (await apiRows(page, PRODUCTS_API)).map((r) => r.name_localized).filter(Boolean);
    test.skip(names.length === 0, '상품이 없어 검증할 수 없습니다');

    await expect(page.getByRole('spinbutton', { name: / 판매가$/ }).first()).toBeVisible({ timeout: 30_000 });
    const product = await firstNamed(page, 'spinbutton', names, ' 판매가');
    expect(product, '「{상품명} 판매가」 로 읽히는 칸이 없다').toBeTruthy();

    await expect(page.getByRole('spinbutton', { name: `${product} 재고`, exact: true })).toBeVisible();
    await expect(page.getByRole('combobox', { name: new RegExp(`^${escapeRegExp(product as string)} 판매상태`) }).or(
      page.getByRole('button', { name: new RegExp(`^${escapeRegExp(product as string)} 판매상태`) }),
    ).first()).toBeVisible();
  });

  // @scenario surface=admin_row
  // @effects row_controls_read_row_and_column
  test('상품 수정 — 옵션 표 칸이 「{옵션명} 판매가」, 상품명 칸이 「상품명 (한국어)」 로 읽힌다', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto('/admin/ecommerce/products');
    const target = await findProductWithOptions(page);
    test.skip(target === null, '옵션이 둘 이상인 상품이 없어 검증할 수 없습니다');
    await page.goto(`/admin/ecommerce/products/${target!.id}/edit`);

    await expect(page.getByRole('spinbutton', { name: `${target!.optionName} 판매가`, exact: true }).first()).toBeVisible({
      timeout: 30_000,
    });
    await expect(page.getByRole('switch', { name: `${target!.optionName} 사용`, exact: true }).first()).toBeVisible();
    await expect(page.getByRole('textbox', { name: /^상품명( \(한국어\))?$/ }).first()).toBeVisible();
  });

  // @scenario surface=admin_modal
  // @effects controls_have_accessible_name
  test('상품 복사 창 — 체크박스가 글자로 읽히고 글자를 눌러도 체크가 바뀐다', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto('/admin/ecommerce/products');
    const rows = await apiRows(page, PRODUCTS_API);
    test.skip(rows.length === 0, '상품이 없어 검증할 수 없습니다');
    await page.goto(`/admin/ecommerce/products/${rows[0].id}/edit`);

    await page.getByRole('button', { name: '복사', exact: true }).click({ timeout: 30_000 });
    const images = page.getByRole('checkbox', { name: '상품 이미지', exact: true });
    await expect(images).toBeVisible();
    const before = await images.isChecked();

    // 글자를 누른다 — 체크박스와 글자가 한 label 이라 글자 클릭도 체크를 뒤집는다
    await page.getByRole('dialog').getByText('상품 이미지', { exact: true }).click();
    await expect(images).toBeChecked({ checked: !before });
  });

  // @scenario surface=admin_row
  // @effects row_controls_read_row_and_column
  test('배송정책 목록 — 행 스위치가 「{정책명} 사용여부」·「{정책명} 기본」 으로 읽힌다', async ({
    page,
    shippingPolicyToken,
  }) => {
    await authenticatePage(page, shippingPolicyToken);
    await page.goto('/admin/ecommerce/shipping-policies');
    const names: string[] = (await apiRows(page, SHIPPING_POLICIES_API)).map((r) => r.name_localized).filter(Boolean);
    test.skip(names.length === 0, '배송정책이 없어 검증할 수 없습니다');

    await expect(page.getByRole('switch', { name: / 사용여부$/ }).first()).toBeVisible({ timeout: 30_000 });
    const policy = await firstNamed(page, 'switch', names, ' 사용여부');
    expect(policy, '「{정책명} 사용여부」 로 읽히는 스위치가 없다').toBeTruthy();
    await expect(page.getByRole('switch', { name: `${policy} 기본`, exact: true })).toBeVisible();
  });

  // @scenario surface=admin_layout
  // @effects listbox_reads_name_and_value
  test('목록 선택칸 — 정렬·쪽당 개수가 「이름 + 현재 값」 으로 읽힌다', async ({ page, productManageToken }) => {
    await authenticatePage(page, productManageToken);
    await page.goto('/admin/ecommerce/products');

    // 목록형 선택칸은 단추다 — 이름 「정렬 기준」 뒤에 고른 값이 이어진다
    await expect(page.getByRole('button', { name: /^정렬 기준 \S/ }).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('button', { name: /^페이지당 표시 개수 \S/ }).first()).toBeVisible();
  });
  // 아래는 주문 목록·상세 화면 시나리오 — 주문번호는 실행 시점에 찾는다.
  // @scenario surface=admin_layout
  // @effects controls_have_accessible_name
  test('주문 상세 — 수취인 정보 칸이 보이는 제목으로 읽히고 입력이 반영된다', async ({ page, orderManageToken }) => {
    await authenticatePage(page, orderManageToken);
    await page.goto('/admin/ecommerce/orders');
    const orders = await apiRows(page, ORDERS_API);
    test.skip(orders.length === 0, '주문이 없어 검증할 수 없습니다');
    await page.goto(`/admin/ecommerce/orders/${orders[0].order_number}`);

    // 국내 주문 기준 — 해외 주문이면 우편번호·주소·상세주소 대신 해외 주소 칸이 선다
    for (const name of ['수취인명', '핸드폰', '전화번호', '배송메시지']) {
      await expect(page.getByRole('textbox', { name, exact: true }).first()).toBeVisible({ timeout: 30_000 });
    }
    // 입력 반영(T2) — 저장하지 않고 원래 값으로 되돌린다
    const nameBox = page.getByRole('textbox', { name: '수취인명', exact: true }).first();
    const before = await nameBox.inputValue();
    await nameBox.fill(before + 'x');
    await expect(nameBox).toHaveValue(before + 'x');
    await nameBox.fill(before);
  });

  // @scenario surface=admin_row
  // @effects row_controls_read_row_and_column
  test('주문 상세 — 상품 표 행 선택이 일시 원문이 아니라 상품명으로 읽히고, 일괄 상태 선택칸이 「주문상태 변경 …」 으로 읽힌다', async ({
    page,
    orderManageToken,
  }) => {
    await authenticatePage(page, orderManageToken);
    await page.goto('/admin/ecommerce/orders');
    const orders = await apiRows(page, ORDERS_API);
    test.skip(orders.length === 0, '주문이 없어 검증할 수 없습니다');
    await page.goto(`/admin/ecommerce/orders/${orders[0].order_number}`);

    await expect(page.getByRole('checkbox', { name: ROW_SELECT_NAME }).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('checkbox', { name: ISO_ROW_NAME })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^주문상태 변경/ }).first()).toBeVisible();
  });

  // @scenario surface=admin_row
  // @effects row_controls_read_row_and_column
  test('주문 목록 — 행 선택 체크박스가 「{주문번호} 선택」 으로 읽힌다', async ({ page, orderManageToken }) => {
    await authenticatePage(page, orderManageToken);
    await page.goto('/admin/ecommerce/orders');
    const orders = await apiRows(page, ORDERS_API);
    test.skip(orders.length === 0, '주문이 없어 검증할 수 없습니다');

    await expect(page.getByRole('checkbox', { name: ROW_SELECT_NAME }).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('checkbox', { name: ISO_ROW_NAME })).toHaveCount(0);
    const numbers: string[] = orders.map((o) => String(o.order_number ?? '')).filter(Boolean);
    const found = await firstNamedCheckbox(page, numbers);
    expect(found, '「{주문번호} 선택」 으로 읽히는 체크박스가 없다').toBeTruthy();
  });
  // @scenario surface=admin_layout
  // @effects controls_have_accessible_name
  test('상품정보고시 목록 — 템플릿 스위치가 항목 수(「9」)가 아니라 「{템플릿명} 사용」 으로 읽힌다', async ({
    page,
    productManageToken,
  }) => {
    await authenticatePage(page, productManageToken);
    await page.goto('/admin/ecommerce/product-notices');
    const rows = await apiRows(page, '/api/modules/sirsoft-ecommerce/admin/product-notice-templates');
    const names: string[] = rows
      .map((r) => (typeof r.name === 'string' ? r.name : r.name?.ko ?? r.name_localized ?? ''))
      .filter(Boolean);
    test.skip(names.length === 0, '상품정보고시 템플릿이 없어 검증할 수 없습니다');

    await expect(page.getByRole('switch', { name: / 사용$/ }).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('switch', { name: /^\d+$/ })).toHaveCount(0);
    const found = await firstNamed(page, 'switch', names, ' 사용');
    expect(found, '「{템플릿명} 사용」 으로 읽히는 스위치가 없다').toBeTruthy();
  });
});
