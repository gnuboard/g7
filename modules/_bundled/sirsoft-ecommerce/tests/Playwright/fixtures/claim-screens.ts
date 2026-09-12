/**
 * 클레임 화면 E2E 공용 픽스처 (#599 S15b)
 *
 * **왜 응답을 가로채는가.** `playwright:issue-token` 은 호출마다 **새 회원**을 만든다
 * (`User::factory()->create()`). 그래서 회원 토큰으로 목록 API 를 훑어 신청서를 찾는 방식은
 * 데이터가 있는 사이트에서도 **구조적으로 언제나 0건**이라, 그 분기를 쓰는 테스트는 환경과
 * 무관하게 영구히 skip 된다(실측: `user-claim-exchange.spec.ts` 3건 중 2건). 그 상태의
 * "green" 은 화면을 한 번도 그리지 않았다는 뜻이므로 게이트로 쓸 수 없다.
 *
 * 그래서 이 픽스처는 **서버 계약이 이미 고정된 응답**만 갈아끼우고, 재는 것은 그 응답을 받은
 * **화면과 엔진**이다 — 유형 분기, 주소 왕복, 잠금, 라벨 전환, 미리보기 왕복. 서버 계약 자체는
 * Feature/Unit 테스트가 고정하며 이 파일은 그 계약을 재현할 뿐 새로 정의하지 않는다.
 * 응답을 통째로 만들지 않고 실서버 응답을 고쳐 쓰는 형제 spec 의 방식
 * (`checkout-plugin-payment-method.spec.ts` 의 client-config 인터셉트)과 같은 규율이며,
 * 여기서는 그 원본(회원 소유 주문)이 어느 환경에도 없기 때문에 전체를 세운다.
 *
 * 시드 커맨드(`playwright:seed-ecommerce`)의 클레임 축이 생기면 이 파일의 자리를 그것이 가져간다
 * (계획서 이월 — 미배정).
 */
import type { Page, Route } from '@playwright/test';

/** 모듈 API 접두 */
export const API = '/api/modules/sirsoft-ecommerce';

/** 화면이 쓰는 가상의 주문 (실제 주문과 겹치지 않는 번호) */
export const MOCK_ORDER = { id: 990120, number: 'E2E20260902-0001' };

/** 비회원 조회 토큰 3키의 값 */
export const GUEST_SESSION = {
  token: 'e2e-guest-lookup-token',
  orderNumber: MOCK_ORDER.number,
};

/** 상품 옵션 한 줄 (claimable-options 의 `options[].option`) */
type OptionSeed = {
  id: number;
  name?: string;
  optionName?: string;
  unitPrice?: number;
};

/** claimable-options 의 한 항목 */
export type ClaimableSeed = {
  option: OptionSeed;
  availableQuantity?: number;
  lockedQuantity?: number;
  types: string[];
  /** 취소·환불 분기 — `auto`(접수 후 자동 전이) · `request`(접수 후 승인 대기). 두 값 모두 환불신청서를 만든다(#599 후속 D2). */
  refundBranch?: 'auto' | 'request' | null;
};

/**
 * JSON 응답으로 라우트를 채웁니다.
 *
 * @param route Playwright 라우트
 * @param data `data` 봉투에 실을 페이로드
 * @returns 완료 Promise
 */
async function fulfillData(route: Route, data: unknown): Promise<void> {
  await route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ success: true, message: null, data }),
  });
}

/**
 * 주문 상세 응답(신청서 셸의 `order` 데이터소스)을 세웁니다.
 *
 * 회원 셸의 이 데이터소스는 `blocking` 이라 응답 전에는 화면 자체가 서지 않는다.
 *
 * @param page Playwright 페이지
 * @param overrides 주문 응답 덮어쓰기
 * @returns 완료 Promise
 */
export async function mockOrder(page: Page, overrides: Record<string, unknown> = {}): Promise<void> {
  await page.route(`**${API}/user/orders/${MOCK_ORDER.number}*`, async (route) => {
    await fulfillData(route, {
      id: MOCK_ORDER.id,
      order_number: MOCK_ORDER.number,
      order_status: 'delivered',
      base_currency: 'KRW',
      currency_code: 'KRW',
      ordered_at_formatted: '2026-09-01 10:00',
      options: [],
      ...overrides,
    });
  });
}

/**
 * `claimable-options` 응답을 세웁니다.
 *
 * 주문 ID 를 알기 전의 첫 호출(`/orders/0/...`)도 같은 응답을 받는다 — 그 호출은 화면이
 * 주문 응답을 받기 전에 한 번 나가며, 실패시켜도 신청서가 서지 않을 뿐이라 축이 되지 않는다.
 *
 * @param page Playwright 페이지
 * @param seeds 상품 줄
 * @param extra `resolved_type` · `reasons` · `settings` 덮어쓰기
 * @returns 완료 Promise
 */
export async function mockClaimable(
  page: Page,
  seeds: ClaimableSeed[],
  extra: Record<string, unknown> = {},
): Promise<void> {
  await page.route(`**${API}/user/orders/*/claimable-options*`, async (route) => {
    await fulfillData(route, {
      order_id: MOCK_ORDER.id,
      order_number: MOCK_ORDER.number,
      resolved_type: null,
      options: seeds.map((seed) => ({
        option: {
          id: seed.option.id,
          product_id: 5000 + seed.option.id,
          product_name: seed.option.name ?? `상품 ${seed.option.id}`,
          product_option_name: seed.option.optionName ?? '기본 옵션',
          unit_price: seed.option.unitPrice ?? 29000,
          unit_price_formatted: `${(seed.option.unitPrice ?? 29000).toLocaleString('ko-KR')}원`,
          thumbnail_url: null,
        },
        available_quantity: seed.availableQuantity ?? 1,
        locked_quantity: seed.lockedQuantity ?? 0,
        claimable_types: seed.types,
        refund_branch: seed.refundBranch ?? null,
      })),
      reasons: {
        exchange: [{ code: 'size_mismatch', localized_name: '사이즈가 맞지 않음', fault_type: 'customer' }],
        return: [{ code: 'change_of_mind', localized_name: '단순 변심', fault_type: 'customer' }],
        refund: [{ code: 'change_of_mind', localized_name: '단순 변심', fault_type: 'customer' }],
      },
      settings: {
        allowed_charge_methods: ['pay_on_request', 'deduct_from_refund'],
        charge_payment_methods: ['card'],
        max_images: 5,
        image_max_size_kb: 5120,
      },
      pending_images: [],
      ...extra,
    });
  });
}

/** 미리보기 응답을 만드는 함수 — 요청 본문을 받아 페이로드를 돌려준다 */
export type PreviewFactory = (body: Record<string, any>) => Record<string, unknown>;

/**
 * 미리보기(`claims/preview`) 응답을 세우고, 나간 요청 본문을 모읍니다.
 *
 * @param page Playwright 페이지
 * @param factory 요청 본문 → 응답 페이로드
 * @returns 나간 요청 본문 배열 (테스트가 그대로 관찰한다)
 */
export async function mockPreview(page: Page, factory: PreviewFactory): Promise<Record<string, any>[]> {
  const calls: Record<string, any>[] = [];

  await page.route('**/claims/preview', async (route) => {
    let body: Record<string, any> = {};
    try {
      body = JSON.parse(route.request().postData() ?? '{}');
    } catch {
      body = {};
    }
    calls.push(body);
    await fulfillData(route, factory(body));
  });

  return calls;
}

/**
 * 신청내역 목록(`user/claims`) 응답을 세웁니다.
 *
 * 유형마다 서버가 다른 테이블을 읽으므로 `?type=` 별로 다른 행을 돌려준다 — 그 분기가 곧
 * 금액 열 분기의 입력이다.
 *
 * @param page Playwright 페이지
 * @param rowsByType 유형별 행 (없는 유형은 빈 목록)
 * @returns 완료 Promise
 */
export async function mockClaimList(
  page: Page,
  rowsByType: Record<string, Record<string, unknown>[]>,
): Promise<void> {
  await page.route(`**${API}/user/claims?**`, async (route) => {
    const url = new URL(route.request().url());
    const type = url.searchParams.get('type') ?? 'exchange';
    const rows = rowsByType[type] ?? [];

    await fulfillData(route, {
      data: rows,
      pagination: {
        current_page: Number(url.searchParams.get('page') ?? 1),
        per_page: 20,
        total: rows.length,
        last_page: rows.length > 0 ? 1 : 1,
        total_relation: 'eq',
      },
    });
  });
}

/**
 * 신청서 상세(`user/claims/{번호}`) 응답을 세웁니다.
 *
 * 상세 응답은 신청서를 `claim` 아래에 담는다(`data.claim.*`) — 목록의 행 표현과 봉투가 다르다.
 *
 * 봉투에는 `claim` 옆에 `progress_steps` 가 실린다(관리자 상세와 같은 배열) — 필요하면 `envelope` 로 넘긴다.
 *
 * @param page Playwright 페이지
 * @param claim 신청서 페이로드
 * @param envelope `claim` 옆에 실을 봉투 필드 (예: `progress_steps`)
 * @returns 완료 Promise
 */
export async function mockClaimDetail(
  page: Page,
  claim: Record<string, unknown>,
  envelope: Record<string, unknown> = {},
): Promise<void> {
  await page.route(`**${API}/user/claims/*`, async (route) => {
    if (route.request().url().includes('/user/claims?')) {
      await route.fallback();

      return;
    }
    await fulfillData(route, { claim, ...envelope });
  });
}

/**
 * 목록 카드 한 줄을 만듭니다 — 서버 목록 표현(ClaimResource)의 형태를 그대로 따른다.
 *
 * @param overrides 덮어쓸 필드
 * @returns 목록 행
 */
export function claimRow(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    claim_number: 'EX20260902-0001',
    claim_type: 'exchange',
    claim_type_label: '교환',
    order_number: MOCK_ORDER.number,
    status: 'requested',
    status_label: '교환신청',
    status_variant: 'info',
    is_editable: false,
    is_withdrawable: false,
    reason_label: '사이즈가 맞지 않음',
    fault_type_label: '고객 귀책',
    currency_code: 'KRW',
    requested_at_formatted: '2026-09-02 10:00',
    items: [
      {
        product_name: '반팔 티셔츠',
        product_option_name: '화이트 / M',
        quantity: 1,
        thumbnail_url: null,
        new_additional_options: [],
      },
    ],
    ...overrides,
  };
}

/**
 * 비회원 조회 토큰 3키를 세션에 심습니다.
 *
 * 화면 진입 **전에** 심어야 한다 — 토큰을 `_global` 로 올리는 것은 부모 레이아웃의
 * init_actions 이고, 그보다 늦게 심으면 데이터소스가 토큰 없이 나가 404 를 받는다.
 *
 * @param page Playwright 페이지
 * @param expiresAt 만료 시각 (기본: 30분 뒤)
 * @returns 완료 Promise
 */
export async function seedGuestSession(page: Page, expiresAt?: string): Promise<void> {
  const expires = expiresAt ?? new Date(Date.now() + 30 * 60 * 1000).toISOString();

  await page.addInitScript(
    ({ token, orderNumber, expires: exp }) => {
      try {
        sessionStorage.setItem('g7_guest_order_token', token);
        sessionStorage.setItem('g7_guest_order_number', orderNumber);
        sessionStorage.setItem('g7_guest_order_expires_at', exp);
      } catch {
        /* sessionStorage 접근 불가 — 그 환경은 이 spec 의 축이 아니다 */
      }
    },
    { token: GUEST_SESSION.token, orderNumber: GUEST_SESSION.orderNumber, expires },
  );
}

/**
 * 쿠키 동의(functional)를 받은 방문자를 재현합니다.
 *
 * GDPR 플러그인은 부팅 시 functional 미동의면 **strictly necessary allowlist 밖의 storage 를
 * 전부 파기**한다(EDPB §117). 비회원 조회 토큰 3키는 그 allowlist 에 없으므로, 동의하지 않은
 * 방문자의 세션에서는 심어 두어도 앱이 뜨는 순간 지워진다 — 그래서 비회원 화면 spec 은 **동의한
 * 방문자**를 재현한다. 동의 상태의 단일 출처는 서버 응답이므로 그 응답만 갈아끼운다.
 *
 * (동의하지 않은 방문자에게 조회 토큰이 부팅마다 파기되는 것이 옳은지는 이 spec 의 축이 아니라
 * GDPR 플러그인의 allowlist 판정 문제다 — #599 S15b 가 발견해 보고했다.)
 *
 * @param page Playwright 페이지
 * @returns 완료 Promise
 */
export async function grantFunctionalConsent(page: Page): Promise<void> {
  await page.route('**/api/plugins/sirsoft-gdpr/consent/cookie/status*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          consents: { necessary: true, functional: true, analytics: false, marketing: false },
          needs_renewal: false,
          current_policy_version: '1',
        },
      }),
    });
  });
}

/**
 * 세션에 남은 비회원 토큰 3키를 읽습니다.
 *
 * @param page Playwright 페이지
 * @returns 3키의 현재 값
 */
export async function readGuestSession(
  page: Page,
): Promise<{ token: string | null; orderNumber: string | null; expiresAt: string | null }> {
  return page.evaluate(() => ({
    token: sessionStorage.getItem('g7_guest_order_token'),
    orderNumber: sessionStorage.getItem('g7_guest_order_number'),
    expiresAt: sessionStorage.getItem('g7_guest_order_expires_at'),
  }));
}

/**
 * 화면이 뜰 때까지 기다립니다.
 *
 * `networkidle` 은 쓰지 않는다 — SPA 는 실시간 연결이 붙어 무통신 구간이 오지 않을 수 있다.
 *
 * @param page Playwright 페이지
 * @returns 완료 Promise
 */
export async function waitForApp(page: Page): Promise<void> {
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
}

/**
 * 상점 라우트 접두(`/shop` 등)를 화면에서 읽습니다.
 *
 * 운영자가 상점 경로를 바꿀 수 있으므로 spec 이 리터럴로 들지 않는다.
 *
 * @param page Playwright 페이지
 * @returns 상점 접두 (미설정이면 빈 문자열)
 */
export async function shopBase(page: Page): Promise<string> {
  return page.evaluate(() => {
    const anyWindow = window as any;
    const value = anyWindow?.G7Core?.state?.get?.('_global')?.shopBase;

    return typeof value === 'string' ? value : '/shop';
  });
}

/**
 * 회원 주문상세 '이 주문의 클레임' 목록(`user/orders/{id}/claims`) 응답을 세웁니다.
 *
 * 회원 목록(`mockClaimList`)과 같은 봉투이며 주문 ID 스코프 경로만 다르다 — 그 경로로 나간 요청의
 * URL 을 돌려주어 spec 이 "유형별로 다시 불렀는가" 를 셀 수 있게 한다.
 *
 * @param page Playwright 페이지
 * @param rowsByType 유형별 행 (없는 유형은 빈 목록)
 * @returns 이 경로로 나간 요청 URL 목록 (도착 순)
 */
/** 주문 스코프 목록의 기본 유형 판정 순서 (서버 `UserClaimService::ORDER_LIST_TYPE_PRIORITY` 와 같다) */
export const ORDER_LIST_TYPE_PRIORITY = ['exchange', 'return', 'cancel', 'refund'] as const;

export async function mockOrderClaimList(
  page: Page,
  rowsByType: Record<string, Record<string, unknown>[]>,
): Promise<URL[]> {
  const requested: URL[] = [];

  await page.route(`**${API}/user/orders/*/claims?**`, async (route) => {
    const url = new URL(route.request().url());
    // 서버 계약: 유형을 비우면 접수 건이 있는 첫 유형(교환 → 반품 → 취소 → 환불), 넷 다 없으면 교환.
    const requestedType = url.searchParams.get('type') || null;
    const type =
      requestedType ??
      ORDER_LIST_TYPE_PRIORITY.find((t) => (rowsByType[t] ?? []).length > 0) ??
      'exchange';
    const rows = rowsByType[type] ?? [];
    requested.push(url);

    await fulfillData(route, {
      data: rows,
      pagination: {
        current_page: Number(url.searchParams.get('page') ?? 1),
        per_page: 20,
        total: rows.length,
        last_page: 1,
        total_relation: 'eq',
      },
      claim_type: type,
      counts: Object.fromEntries(ORDER_LIST_TYPE_PRIORITY.map((t) => [t, (rowsByType[t] ?? []).length])),
    });
  });

  return requested;
}

/** 재계산 전/후 스냅샷 한 벌 — 숫자 + base_currency + 기준 통화 포맷. 값은 응답에만 있고 다른 줄에서 만들 수 없는 것으로 고른다. */
function comparisonSnapshot(values: Record<string, number>, formatted: Record<string, string>): Record<string, unknown> {
  return { ...values, base_currency: 'KRW', formatted };
}

/** 원 주문 스냅샷 (부가세 5,636 은 이 응답에만 있다) */
export const COMPARISON_ORIGINAL = comparisonSnapshot(
  {
    item_count: 5, total_list_price_amount: 86000, subtotal_amount: 70000, total_product_coupon_discount_amount: 0,
    total_order_coupon_discount_amount: 3000, total_code_discount_amount: 0, shipping_discount_amount: 0,
    base_shipping_amount: 0, extra_shipping_amount: 0, total_points_used_amount: 0, total_tax_amount: 62000,
    total_vat_amount: 5636, total_paid_amount: 62000, total_earned_points_amount: 620,
  },
  {
    total_list_price_amount: '86,000원', subtotal_amount: '70,000원', total_product_coupon_discount_amount: '0원',
    total_order_coupon_discount_amount: '3,000원', total_code_discount_amount: '0원', shipping_discount_amount: '0원',
    base_shipping_amount: '0원', extra_shipping_amount: '0원', total_points_used_amount: '0원',
    total_tax_amount: '62,000원', total_vat_amount: '5,636원', total_paid_amount: '62,000원',
  },
);

/** 재계산 스냅샷 (부가세 3,091 은 이 응답에만 있다) */
export const COMPARISON_RECALCULATED = comparisonSnapshot(
  {
    item_count: 4, total_list_price_amount: 61000, subtotal_amount: 50000, total_product_coupon_discount_amount: 0,
    total_order_coupon_discount_amount: 0, total_code_discount_amount: 0, shipping_discount_amount: 0,
    base_shipping_amount: 0, extra_shipping_amount: 0, total_points_used_amount: 0, total_tax_amount: 45000,
    total_vat_amount: 3091, total_paid_amount: 45000, total_earned_points_amount: 450,
  },
  {
    total_list_price_amount: '61,000원', subtotal_amount: '50,000원', total_product_coupon_discount_amount: '0원',
    total_order_coupon_discount_amount: '0원', total_code_discount_amount: '0원', shipping_discount_amount: '0원',
    base_shipping_amount: '0원', extra_shipping_amount: '0원', total_points_used_amount: '0원',
    total_tax_amount: '45,000원', total_vat_amount: '3,091원', total_paid_amount: '45,000원',
  },
);

/** 접수 시점 동결 비교 블록 — 상세 응답의 `calculation_comparison` 형태 */
export const FROZEN_COMPARISON = {
  original: COMPARISON_ORIGINAL,
  recalculated: COMPARISON_RECALCULATED,
  mc_original: { mc_total_paid_amount_formatted: { KRW: '62,000원', USD: '$52.70' } },
  mc_recalculated: { mc_total_paid_amount_formatted: { KRW: '45,000원', USD: '$38.25' } },
  substituted_items: [],
};

/**
 * 주문금액 비교 표가 그려질 재료를 전부 실은 미리보기 응답을 만듭니다. (#599 후속)
 *
 * 표의 값은 서버 `formatted` 직독이므로, 응답에만 있고 다른 줄에서 만들 수 없는 값
 * (부가세 5,636 → 3,091)을 심어 그 글자가 그대로 나오는지로 잰다.
 *
 * @param overrides 최상위 필드 덮어쓰기 (claim_type · refund_product_amount …)
 * @param adjustmentOverrides `adjustment` 덮어쓰기 (cancel_blocked · substituted_items …)
 * @returns 미리보기 페이로드
 */
export function previewWithComparison(
  overrides: Record<string, unknown> = {},
  adjustmentOverrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    claim_type: 'refund',
    requires_payment: false,
    shipping_fee: { fee: 0, extra_fee: 0, groups: [] },
    price_difference: 0,
    additional_product_amount: 0,
    refund_product_amount: 0,
    total_charge_amount: 0,
    refund_before_deduction: 17000,
    deducted_shipping_fee: 0,
    items: [],
    adjustment: {
      original_snapshot: COMPARISON_ORIGINAL,
      recalculated_snapshot: COMPARISON_RECALCULATED,
      mc_original_snapshot: null,
      mc_recalculated_snapshot: null,
      original_coupons: [{ name: '첫구매 쿠폰', target_type: 'order_amount', discount_amount: 3000, discount_amount_formatted: '3,000원' }],
      recalculated_coupons: [],
      refund_priority: 'pg_first',
      refund_amount: 17000,
      refund_points_amount: 0,
      refund_total: 17000,
      remaining_pg_balance: 45000,
      remaining_points_balance: 0,
      refund_formatted: {
        base_currency: 'KRW',
        base: { refund_total: '17,000원', refund_amount: '17,000원', remaining_pg_balance: '45,000원', remaining_points_balance: '0원' },
        mc: {},
      },
      restored_coupons: [{ coupon_name: '첫구매 쿠폰', discount_amount: 3000, discount_amount_formatted: '3,000원' }],
      cancel_blocked: false,
      cancel_blocked_reason: null,
      substituted_items: [],
      ...adjustmentOverrides,
    },
    ...overrides,
  };
}
