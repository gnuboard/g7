/**
 * 추가옵션 E2E 공용 시드 — "메인 옵션 2개 + 추가금이 양수인 추가옵션" 상품을 API 로 만들고 지운다.
 *
 * 종전에는 공개·관리자 상품 목록 앞쪽(최신 40건)을 하나씩 상세 조회해 조건에 맞는 상품을 찾았다.
 * 다른 작업이 만든 시험 상품이 목록 앞쪽을 채우면(실측: 공개 목록 앞 20건 전부 추가옵션 0건,
 * 상세 1건당 0.9~1.9초) 조회만으로 30초 시험 제한을 넘기거나 대상 없음으로 건너뛴다.
 * 조건을 갖춘 상품이 있는지는 공유 개발 DB 상태에 달린 일이므로, spec 이 그 상품을 직접 만든다.
 *
 * 호출은 Playwright `request`(APIRequestContext)로 한다 — 페이지에 관리자 토큰을 심지 않으므로
 * 비회원·일반회원 화면을 재는 spec 도 자기 인증 상태를 그대로 쓴다.
 */
import type { APIRequestContext } from '@playwright/test';

const PRODUCT_API = '/api/modules/sirsoft-ecommerce/admin/products';
const CATEGORY_API = '/api/modules/sirsoft-ecommerce/admin/categories';
const SHIPPING_POLICY_API = '/api/modules/sirsoft-ecommerce/admin/shipping-policies';
const PUBLIC_PRODUCT_API = '/api/modules/sirsoft-ecommerce/products';

/** 시드한 상품 — 상품상세·상품폼 spec 이 쓰는 값 */
export interface SeededAddOptProduct {
  /** 상품 숫자 id (관리자 수정 화면·삭제용) */
  id: number;
  /** 상품 코드 */
  code: string;
  /** 상품 상세 경로 */
  url: string;
  /** 메인 옵션 그룹별로 고를 값 라벨 (그룹을 **전부** 골라야 블럭이 생긴다) */
  mainValues: string[];
  /** 추가옵션 그룹 id */
  groupId: number;
  /** 그 그룹에서 고를 선택지 이름 */
  valueName: string;
  /** 그 선택지의 추가금 (기본통화 기준) */
  priceAdjustment: number;
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
 * 추가옵션 상품을 만든다.
 *
 * 색상 그룹 하나에 값 2개(옵션 2개), 추가옵션 그룹 하나에 추가금 양수 선택지 2개를 둔다.
 * 배송정책은 기본 정책을 붙인다(없으면 비워 둔다 — 담기·주문서 진입은 정책 없이도 동작한다).
 *
 * @param request Playwright APIRequestContext
 * @param token 상품 생성·삭제 권한이 있는 관리자 토큰
 * @return 시드한 상품
 */
export async function seedAdditionalOptionProduct(
  request: APIRequestContext,
  token: string,
): Promise<SeededAddOptProduct> {
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
  const code = `E2E-AO-${suffix}`;
  const colors = ['블랙', '화이트'];
  const valueName = `각인 서비스 ${suffix}`;
  const priceAdjustment = 5000;

  const created = await request.post(PRODUCT_API, {
    headers,
    data: {
      name: tr(`E2E 추가옵션 ${suffix}`),
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
      additional_options: [
        {
          name: tr('추가 서비스'),
          is_required: false,
          sort_order: 0,
          values: [
            { name: tr(valueName), price_adjustment: priceAdjustment, is_active: true, sort_order: 0 },
            { name: tr(`선물 포장 ${suffix}`), price_adjustment: 2000, is_active: true, sort_order: 1 },
          ],
        },
      ],
    },
  });
  const createdJson = await created.json().catch(() => null);
  if (created.status() !== 201) {
    throw new Error(`추가옵션 상품 생성 실패 (${created.status()}): ${JSON.stringify(createdJson?.errors ?? createdJson)}`);
  }
  const id = Number(createdJson?.data?.id);

  // 그룹 id 는 공개 상세 응답에서 읽는다 — 유저 화면이 렌더하는 testid(add-option-0-{groupId})와 같은 원천이다.
  const detail = await request.get(`${PUBLIC_PRODUCT_API}/${code}`, {
    headers: { Accept: 'application/json', 'Accept-Language': 'ko' },
  });
  const group = ((await detail.json())?.data?.additional_options ?? [])[0];
  if (!group?.id) {
    await deleteSeededProduct(request, token, id);
    throw new Error(`시드 상품의 공개 상세에 추가옵션 그룹이 없다 (status ${detail.status()})`);
  }

  return {
    id,
    code,
    url: `/shop/products/${code}`,
    mainValues: [colors[0]],
    groupId: Number(group.id),
    valueName,
    priceAdjustment,
  };
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
