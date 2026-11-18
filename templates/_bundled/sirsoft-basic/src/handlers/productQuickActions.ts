/**
 * 상품 카드의 곧바로 담기·옵션 선택 창·찜 핸들러
 *
 * 상품 카드(목록·검색·가로 상품 줄)에서 상세로 들어가지 않고 장바구니에 담거나 찜한다.
 * - 고를 것이 없는 상품(옵션 하나·추가옵션 없음)은 곧바로 담는다.
 * - 고를 것이 있거나 알 수 없는 상품은 옵션 선택 창(`quick_option_modal`)을 연다.
 *
 * 요청은 엔진의 `apiCall` 로 보낸다 — 인증 헤더·전역 헤더·401 처리를 엔진이 맡는다.
 * 창 상태는 창 고유 전역 이름 공간 `_global.quickOptionModal` 에 둔다. 창은 계속 마운트돼 있어
 * 화면 상태에 쓰면 다시 열 때 앞 값이 남는다(7.1.1). 여는 쪽이 이 키를 null 로 비운 뒤 채운다.
 */

import { initCartKeyHandler } from './storageHandlers';
import { buildSelectedItemFromOption, buildSelectedItemsTotal } from './productOptions';
import { setWishlistOverride } from '../support/wishlistOverrides';

const API_BASE = '/api/modules/sirsoft-ecommerce';

/** 추가 정보 미입력 거부의 오류 코드 (서버 403 응답의 error_code) */
export const REQUIRED_FIELDS_PENDING = 'required_fields_pending';

/** 「추가 정보가 필요합니다」 안내 창 이름 (사용자 화면 상속원 `_user_base` 의 창) */
export const REQUIRED_INFO_PENDING_MODAL_ID = 'required_info_pending_modal';

/**
 * 카드 요청의 403 처리 — 추가 정보 미입력 거부면 안내 창을 열고, 다른 403 은 아무것도 하지 않는다(이 핸들러가 서버 문구를 알린다).
 *
 * 자기 오류 처리가 없는 요청은 화면 레이아웃 수준 403 처리(상품 상세의 오류 화면, 목록의 접근 거부 표시)가
 * 템플릿 수준 「추가 정보 필요」 처리보다 먼저라, 카드 단추에서는 창이 뜨지 않고 오류 알림만 나왔다.
 * 레이아웃의 실패 처리(`<fail>` 맨 앞의 open-modal)와 같은 일을 요청 수준 403 처리로 한다. 401 등 다른 상태는
 * 종전대로 엔진의 기본 처리로 간다.
 */
const PENDING_403_HANDLING = {
  403: {
    handler: 'conditions',
    conditions: [{ if: `{{error.error_code === '${REQUIRED_FIELDS_PENDING}'}}`, then: { handler: 'openModal', target: REQUIRED_INFO_PENDING_MODAL_ID } }],
  },
};

/** 옵션 선택 창 이름 (레이아웃 `partials/shop/_modal_quick_option` 의 창) */
export const QUICK_OPTION_MODAL_ID = 'quick_option_modal';

/** 카드가 넘기는 상품 (목록 응답의 한 항목) */
export interface QuickActionProduct {
  id: number;
  product_code?: string;
  /** 구매할 수 있는 옵션 수 (옛 응답에는 없다) */
  options_count?: number;
  has_options?: boolean;
  has_additional_options?: boolean;
  min_purchase_qty?: number;
  is_wishlisted?: boolean;
  [key: string]: unknown;
}

interface QuickActionDefinition {
  handler?: string;
  params?: { product?: QuickActionProduct } & Record<string, unknown>;
  [key: string]: unknown;
}

/** 엔진 apiCall 결과에서 꺼낸 값 */
interface ApiOutcome {
  ok: boolean;
  /** 성공 응답의 data */
  data?: any;
  /** 실패 HTTP 상태 */
  status?: number;
  /** 실패 응답 본문 */
  response?: any;
  /** 알릴 문구 */
  message?: string;
  /** 추가 정보 미입력 거부인가 (안내 창은 엔진이 이미 열었다 — 오류 알림을 띄우지 않는다) */
  pending?: boolean;
}

const g7 = (): any => (window as any).G7Core;

const t = (key: string): string => g7()?.t?.(key) ?? key;

/**
 * 엔진 `apiCall` 로 요청을 보내고 결과를 읽기 쉬운 모양으로 돌려준다.
 *
 * 403 은 요청 수준 처리(`PENDING_403_HANDLING`)로 받는다 — 추가 정보 미입력 거부면 안내 창이 열리고 `pending` 이 참이다.
 *
 * @param target 요청 주소
 * @param authMode 인증 방식
 * @param params 요청 인자 (method · body · headers)
 * @returns 요청 결과
 */
async function callApi(target: string, authMode: 'required' | 'optional', params: Record<string, unknown>): Promise<ApiOutcome> {
  const result = await g7()?.dispatch?.({ handler: 'apiCall', target, auth_mode: authMode, params, errorHandling: PENDING_403_HANDLING });
  if (result && result.success !== false) {
    const body = result.data;
    return { ok: true, data: body?.data ?? body };
  }

  const error = result?.error?.originalError ?? result?.error;
  const response = error?.response;
  return {
    ok: false,
    status: error?.status,
    response,
    message: response?.errors?.message ?? response?.message ?? error?.message ?? '',
    pending: response?.error_code === REQUIRED_FIELDS_PENDING,
  };
}

/**
 * 카드 상품을 고를 것 없이 곧바로 담을 수 있는가.
 *
 * 값을 모르면(옛 이커머스 응답) 담지 않는다 — 옵션이 있는 상품을 수량만으로 보내면 서버가 거절한다.
 *
 * @param product 카드 상품
 * @returns 곧바로 담을 수 있으면 true
 */
export function canAddDirectly(product: QuickActionProduct): boolean {
  const singleChoice = product.options_count === 1 || product.has_options === false;
  return singleChoice && product.has_additional_options === false;
}

/**
 * 옵션 선택 창을 연다.
 *
 * 창 상태를 null 로 먼저 비우고(앞 상품의 줄이 남지 않게) 상세를 받아 채운 뒤 연다.
 * 옵션이 하나 이하인 상품은 그 옵션으로 고정 줄 하나를 미리 채운다 — 창에서는 추가옵션과 수량만 고른다.
 *
 * @param action 액션 정의 (params.product)
 * @returns 창을 열었으면 true
 */
export async function openQuickOptionHandler(action: QuickActionDefinition, _context?: unknown): Promise<boolean> {
  const product = action?.params?.product;
  const G7Core = g7();
  if (!product || !G7Core?.state?.set) return false;

  G7Core.state.set({ quickOptionModal: null });
  G7Core.state.set({ quickOptionModal: { loading: true, product } });

  const outcome = await callApi(`${API_BASE}/products/${product.product_code ?? product.id}`, 'optional', { method: 'GET' });
  if (!outcome.ok || !outcome.data) {
    G7Core.state.set({ quickOptionModal: null });
    if (outcome.message && !outcome.pending) G7Core.toast?.error?.(outcome.message);
    return false;
  }

  const detail = outcome.data;
  const options: any[] = Array.isArray(detail.options) ? detail.options : [];
  const matchedOption = options.length === 1 ? options[0] : null;
  const preferredCurrency = G7Core.state.get?.()?.preferredCurrency ?? G7Core.state.get?.()?.defaultCurrency;

  const selectedOptionItems = matchedOption
    ? [
        {
          ...buildSelectedItemFromOption({
            id: `option-${matchedOption.id}`,
            option: matchedOption,
            optionLabels: {},
            preferredCurrency,
            additionalOptionGroups: detail.additional_options ?? [],
            quantity: Math.max(1, Number(detail.min_purchase_qty) || 1),
          }),
          // 고정 줄 — 창에서 지울 수 없고 최소 구매 수량 아래로 내릴 수 없다
          fixed: true,
        },
      ]
    : [];

  G7Core.state.set({
    quickOptionModal: {
      loading: false,
      adding: false,
      attempted: false,
      product,
      detail,
      matchedOption,
      currentSelection: {},
      selectedOptionItems,
      selectedTotalMultiCurrency: buildSelectedItemsTotal(selectedOptionItems as any),
    },
  });
  G7Core.modal?.open?.(QUICK_OPTION_MODAL_ID);
  return true;
}

/**
 * 카드에서 장바구니에 담는다.
 *
 * 고를 것이 없는 상품만 곧바로 담고, 그 밖은 옵션 선택 창으로 넘긴다. 수량은 최소 구매 수량이다 —
 * 그보다 적게 보내면 서버가 거절한다. 장바구니 키가 아직 없으면 기존 발급 경로로 먼저 받는다.
 *
 * @param action 액션 정의 (params.product)
 * @returns 담았으면 true (창으로 넘겼거나 실패면 false)
 */
export async function quickAddToCartHandler(action: QuickActionDefinition, _context?: unknown): Promise<boolean> {
  const product = action?.params?.product;
  const G7Core = g7();
  if (!product || !G7Core) return false;

  if (!canAddDirectly(product)) {
    await openQuickOptionHandler(action);
    return false;
  }

  if (!G7Core.state?.get?.()?.cartKey) {
    await initCartKeyHandler();
  }
  const cartKey = G7Core.state?.get?.()?.cartKey;

  const outcome = await callApi(`${API_BASE}/cart`, 'optional', {
    method: 'POST',
    body: { product_id: product.id, items: [{ quantity: Math.max(1, Number(product.min_purchase_qty) || 1) }] },
    headers: { 'X-Cart-Key': cartKey },
  });

  if (outcome.ok) {
    if (outcome.data?.cart_count != null) G7Core.state?.set?.({ cartCount: outcome.data.cart_count });
    G7Core.toast?.success?.(t('shop.cart_added'));
    return true;
  }

  // 목록 응답이 낡아 추가옵션이 생긴 것을 몰랐던 경우 — 고를 수 있게 창으로 넘긴다
  const unavailable: any[] = outcome.response?.errors?.unavailable_items ?? [];
  if (outcome.status === 422 && unavailable.some((item) => String(item?.reason ?? '').startsWith('additional_option_'))) {
    await openQuickOptionHandler(action);
    return false;
  }

  if (outcome.message && !outcome.pending) G7Core.toast?.error?.(outcome.message);
  return false;
}

/**
 * 카드에서 찜을 켜거나 끈다.
 *
 * 비회원은 요청 없이 로그인 안내만 한다. 결과는 찜 공유 저장소에 두어 같은 상품의 다른 카드도 함께 바뀐다.
 *
 * @param action 액션 정의 (params.product)
 * @returns 바뀐 찜 여부 (비회원·실패면 null)
 */
export async function toggleWishlistQuickHandler(action: QuickActionDefinition, _context?: unknown): Promise<boolean | null> {
  const product = action?.params?.product;
  const G7Core = g7();
  if (!product || !G7Core) return null;

  if (!G7Core.state?.get?.()?.currentUser?.uuid) {
    G7Core.toast?.warning?.(t('shop.wishlist_login_required'));
    return null;
  }

  const outcome = await callApi(`${API_BASE}/wishlist/toggle`, 'required', { method: 'POST', body: { product_id: product.id } });
  if (!outcome.ok) {
    if (outcome.message && !outcome.pending) G7Core.toast?.error?.(outcome.message);
    return null;
  }

  const added = outcome.data?.added === true;
  setWishlistOverride(product.id, added);
  G7Core.toast?.success?.(t(added ? 'shop.wishlist_added' : 'shop.wishlist_removed'));
  return added;
}
