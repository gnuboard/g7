/**
 * 유저 쇼핑 흐름 E2E 공용 시드 — "구매 가능한 메인 옵션 2개" 상품을 이커머스 관리자 API 로 만들고 지운다.
 *
 * 종전에는 공개 상품 목록 앞쪽에서 대상을 골랐다. 공유 개발 DB 의 목록 앞쪽은 다른 작업이 만든 시험 상품이
 * 차지하고 있어, 재고 0 인 상품이 골려 담기가 422 `cart_unavailable` 로 거부되거나(실측: 상품명
 * `qa720r2-w4 특수 …`, 가용 0개), 상세 조회를 차례로 도느라 30초 시험 제한을 넘겼다. 담을 수 있는 상품이
 * 있는지는 개발 DB 상태에 달린 일이므로, spec 이 그 상품을 직접 만들고 끝나면 지운다.
 *
 * 이커머스 모듈의 같은 역할 fixture(`additional-option-product-seed.ts`)를 가져다 쓰지 않는 이유:
 * 이 템플릿은 이커머스를 `dependencies` 가 아니라 `integrations` 로만 선언한다. 선언하지 않은 확장의
 * 내부 파일을 경로로 끌어오면 그 확장의 시험 디렉터리 구조에 묶인다(mem:extension-names-only-declared-dependencies).
 * 이 fixture 는 이커머스의 공개 HTTP 계약(관리자 상품 API)만 쓴다.
 *
 * 호출은 Playwright `APIRequestContext` 로 한다 — 페이지에 관리자 토큰을 심지 않으므로 비회원 화면을
 * 재는 spec 도 자기 인증 상태를 그대로 쓴다. 상품 생성 POST 1건은 공유 개발 서버에서 실측 17초까지
 * 걸리므로(이커머스 spec 실측) 호출하는 시험은 시간 예산을 그만큼 잡는다.
 */
import type { APIRequestContext } from '@playwright/test';

const PRODUCT_API = '/api/modules/sirsoft-ecommerce/admin/products';
const CATEGORY_API = '/api/modules/sirsoft-ecommerce/admin/categories';
const SHIPPING_POLICY_API = '/api/modules/sirsoft-ecommerce/admin/shipping-policies';
const PUBLIC_PRODUCT_API = '/api/modules/sirsoft-ecommerce/products';

/** 시드한 상품 */
export interface SeededOptionProduct {
  /** 상품 숫자 id (삭제용) */
  id: number;
  /** 상품 코드 */
  code: string;
  /** 상품 상세 경로 */
  url: string;
  /** 메인 옵션 그룹별로 고를 값 라벨 (그룹을 **전부** 골라야 선택 블럭이 생긴다) */
  mainValues: string[];
  /** 첫 옵션의 숫자 id (장바구니 API 로 바로 담을 때) */
  firstOptionId: number;
}

/** 다국어 값 — 사이트 기본 언어가 무엇이든 필수 로케일 검증을 통과하도록 세 언어를 채운다 */
function tr(text: string): Record<string, string> {
  return { ko: text, en: text, ja: text };
}

/**
 * 관리자 토큰 헤더를 만든다.
 *
 * @param token 관리자 Sanctum 토큰
 * @return 요청 헤더
 */
function adminHeaders(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Accept-Language': 'ko' };
}

/**
 * 구매 가능한 메인 옵션 2개 상품을 만든다.
 *
 * 색상 그룹 하나에 값 2개(옵션 2개)를 두고 판매중·전시중·재고 999 로 만든다. 배송정책은 사이트 기본 정책을
 * 붙인다(없으면 비워 둔다).
 *
 * @param request Playwright APIRequestContext
 * @param token 상품 생성·삭제 권한이 있는 관리자 토큰 (`issueToken()` 은 admin 역할을 함께 준다)
 * @return 시드한 상품
 */
export async function seedOptionProduct(request: APIRequestContext, token: string): Promise<SeededOptionProduct> {
  const headers = adminHeaders(token);

  const categories = await request.get(`${CATEGORY_API}?per_page=1`, { headers });
  const categoryJson = await categories.json();
  const categoryId = categoryJson?.data?.data?.[0]?.id ?? categoryJson?.data?.[0]?.id;
  if (!categoryId) {
    throw new Error(`카테고리가 1건 이상 있어야 상품을 만들 수 있다 (status ${categories.status()})`);
  }

  const policies = await request.get(`${SHIPPING_POLICY_API}?per_page=100`, { headers });
  const policyRows: any[] = policies.ok() ? ((await policies.json())?.data?.data ?? []) : [];
  const shippingPolicyId = policyRows.find((p) => p?.is_default)?.id ?? null;

  const suffix = `${Date.now()}`.slice(-9);
  const code = `E2E-OPT-${suffix}`;
  const colors = ['블랙', '화이트'];

  const created = await request.post(PRODUCT_API, {
    headers,
    data: {
      name: tr(`E2E 옵션상품 ${suffix}`),
      product_code: code,
      category_ids: [categoryId],
      list_price: 20000,
      selling_price: 20000,
      stock_quantity: 999,
      sales_status: 'on_sale',
      display_status: 'visible',
      tax_status: 'taxable',
      shipping_policy_id: shippingPolicyId,
      has_options: true,
      option_groups: [{ name: tr('색상'), values: colors.map(tr) }],
      options: colors.map((color, i) => ({
        option_code: `${code}-${i + 1}`,
        option_name: tr(color),
        option_values: [{ key: tr('색상'), value: tr(color) }],
        list_price: 20000,
        selling_price: 20000,
        stock_quantity: 999,
        is_default: i === 0,
        is_active: true,
      })),
    },
  });
  const createdJson = await created.json().catch(() => null);
  if (created.status() !== 201) {
    throw new Error(`옵션 상품 생성 실패 (${created.status()}): ${JSON.stringify(createdJson?.errors ?? createdJson)}`);
  }
  const id = Number(createdJson?.data?.id);

  // 옵션 id 는 공개 상세 응답에서 읽는다 — 유저 화면이 담기 요청에 싣는 product_option_id 와 같은 원천이다.
  const detail = await request.get(`${PUBLIC_PRODUCT_API}/${code}`, {
    headers: { Accept: 'application/json', 'Accept-Language': 'ko' },
  });
  const firstOptionId = Number(((await detail.json().catch(() => null))?.data?.options ?? [])[0]?.id);
  if (!firstOptionId) {
    await deleteSeededProduct(request, token, id);
    throw new Error(`시드 상품의 공개 상세에 옵션이 없다 (status ${detail.status()})`);
  }

  return { id, code, url: `/shop/products/${code}`, mainValues: [colors[0]], firstOptionId };
}

/**
 * 시드 상품을 지운다 (장바구니 행은 FK cascade 로 함께 지워진다).
 *
 * @param request Playwright APIRequestContext
 * @param token 상품 삭제 권한이 있는 관리자 토큰
 * @param id 상품 숫자 id
 * @return 없음
 */
export async function deleteSeededProduct(request: APIRequestContext, token: string, id: number): Promise<void> {
  await request.delete(`${PRODUCT_API}/${id}`, { headers: adminHeaders(token) });
}
