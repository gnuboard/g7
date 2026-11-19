/**
 * @file productQuickActions.test.ts
 * @description 상품 카드의 곧바로 담기·옵션 선택 창 열기·찜 핸들러를 잠근다.
 *
 * @scenario-manifest tests/scenarios/product-card-quick-actions.yaml
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { quickAddToCartHandler, openQuickOptionHandler, toggleWishlistQuickHandler } from '../productQuickActions';
import { getWishlistOverride, clearWishlistOverrides } from '../../support/wishlistOverrides';

const CART = '/api/modules/sirsoft-ecommerce/cart';

let globalState: Record<string, any>;
let dispatch: ReturnType<typeof vi.fn>;
let stateSet: ReturnType<typeof vi.fn>;
let modalOpen: ReturnType<typeof vi.fn>;
let toast: Record<string, ReturnType<typeof vi.fn>>;
let order: string[];

const detail = (overrides: Record<string, unknown> = {}) => ({
  id: 7,
  product_code: 'P-7',
  min_purchase_qty: 1,
  has_options: false,
  option_groups: [],
  additional_options: [],
  options: [
    {
      id: 70,
      option_values: [],
      option_values_localized: {},
      selling_price: 5000,
      selling_price_formatted: '5,000원',
      multi_currency_selling_price: { KRW: { value: 5000, formatted: '5,000원' } },
      stock_quantity: 9,
      is_active: true,
    },
  ],
  ...overrides,
});

beforeEach(() => {
  clearWishlistOverrides();
  order = [];
  globalState = { cartKey: 'ck_test', currentUser: null, preferredCurrency: 'KRW' };
  stateSet = vi.fn((updates: Record<string, any>) => {
    order.push('set:' + JSON.stringify(Object.keys(updates)) + ':' + (updates.quickOptionModal === null ? 'null' : 'value'));
    Object.assign(globalState, updates);
  });
  modalOpen = vi.fn(() => order.push('modal.open'));
  toast = { success: vi.fn(), warning: vi.fn(), error: vi.fn() };
  dispatch = vi.fn(async (action: any) => {
    order.push('dispatch:' + action.target);
    if (action.target === CART) return { success: true, data: { success: true, data: { cart_count: 3 } } };
    if (String(action.target).includes('/wishlist/toggle')) return { success: true, data: { success: true, data: { added: true } } };
    return { success: true, data: { success: true, data: detail() } };
  });
  (window as any).G7Core = {
    t: (k: string) => k,
    dispatch,
    toast,
    modal: { open: modalOpen },
    state: { get: () => globalState, set: stateSet },
  };
});

describe('곧바로 담기', () => {
  // @scenario case=c10
  // @effects quick_add_posts_cart_with_min_quantity
  it('고를 것이 없는 상품은 최소 구매 수량으로 곧바로 담고 장바구니 수를 갱신한다', async () => {
    await quickAddToCartHandler({ handler: 'x', params: { product: { id: 7, product_code: 'P-7', options_count: 1, has_additional_options: false, min_purchase_qty: 3 } } });

    expect(dispatch).toHaveBeenCalledTimes(1);
    const call = dispatch.mock.calls[0][0];
    expect(call.handler).toBe('apiCall');
    expect(call.target).toBe(CART);
    expect(call.auth_mode).toBe('optional');
    expect(call.params.method).toBe('POST');
    expect(call.params.body).toEqual({ product_id: 7, items: [{ quantity: 3 }] });
    expect(call.params.headers).toEqual({ 'X-Cart-Key': 'ck_test' });
    expect(stateSet).toHaveBeenCalledWith({ cartCount: 3 });
    expect(toast.success).toHaveBeenCalledWith('shop.cart_added');
    expect(modalOpen).not.toHaveBeenCalled();
  });

  // @scenario case=c11
  // @effects quick_add_issues_cart_key_first
  it('장바구니 키가 없으면 기존 발급 경로로 먼저 받고 그 키로 담는다', async () => {
    // 저장된 키는 서버 형식(`ck_` + 영숫자 32자)이어야 그대로 쓴다 — 형식이 망가진 키는 initCartKey 가 버리고 새로 발급한다(#869)
    const storedKey = 'ck_' + 'Stored0'.repeat(4) + 'Key0';
    globalState.cartKey = null;
    localStorage.setItem('g7_cart_key', storedKey);

    await quickAddToCartHandler({ handler: 'x', params: { product: { id: 7, has_options: false, has_additional_options: false } } });

    const call = dispatch.mock.calls.find((c) => c[0].target === CART)![0];
    expect(call.params.headers['X-Cart-Key']).toBe(storedKey);
    localStorage.removeItem('g7_cart_key');
  });

  // @scenario case=c12
  // @effects quick_add_opens_option_modal_when_choice_needed
  it.each([
    ['옵션이 둘 이상', { id: 7, product_code: 'P-7', options_count: 2, has_additional_options: false }],
    ['추가옵션이 있음', { id: 7, product_code: 'P-7', options_count: 1, has_additional_options: true }],
    ['값을 모름(옛 응답)', { id: 7, product_code: 'P-7' }],
  ])('%s → 담지 않고 옵션 선택 창을 연다', async (_label, product) => {
    await quickAddToCartHandler({ handler: 'x', params: { product } });

    expect(dispatch.mock.calls.some((c) => c[0].target === CART)).toBe(false);
    expect(dispatch.mock.calls[0][0].target).toBe('/api/modules/sirsoft-ecommerce/products/P-7');
    expect(modalOpen).toHaveBeenCalledWith('quick_option_modal');
  });

  // @scenario case=c13
  // @effects quick_add_falls_back_to_modal_on_additional_option_422
  it('서버가 추가옵션 사유로 거절하면 옵션 선택 창으로 넘긴다', async () => {
    dispatch.mockImplementationOnce(async () => {
      const original: any = new Error('담을 수 없습니다');
      original.status = 422;
      original.response = { errors: { unavailable_items: [{ reason: 'additional_option_required' }] } };
      const error: any = new Error('담을 수 없습니다');
      error.originalError = original;
      return { success: false, error };
    });

    await quickAddToCartHandler({ handler: 'x', params: { product: { id: 7, product_code: 'P-7', options_count: 1, has_additional_options: false } } });

    expect(toast.error).not.toHaveBeenCalled();
    expect(modalOpen).toHaveBeenCalledWith('quick_option_modal');
  });

  // @scenario case=c14
  // @effects quick_add_posts_cart_with_min_quantity
  it('그 밖의 실패는 서버 문구를 알리고 창을 열지 않는다', async () => {
    dispatch.mockImplementationOnce(async () => {
      const original: any = new Error('재고가 부족합니다');
      original.status = 422;
      original.response = { message: '재고가 부족합니다', errors: {} };
      const error: any = new Error('재고가 부족합니다');
      error.originalError = original;
      return { success: false, error };
    });

    await quickAddToCartHandler({ handler: 'x', params: { product: { id: 7, options_count: 1, has_additional_options: false } } });

    expect(toast.error).toHaveBeenCalledWith('재고가 부족합니다');
    expect(modalOpen).not.toHaveBeenCalled();
  });
});

describe('옵션 선택 창 열기', () => {
  // @scenario case=c15
  // @effects open_quick_option_clears_namespace_first
  it('창 상태를 null 로 먼저 비운 뒤 채우고, 상세를 받은 다음 창을 연다', async () => {
    globalState.quickOptionModal = { selectedOptionItems: [{ id: 'old' }] };

    await openQuickOptionHandler({ handler: 'x', params: { product: { id: 7, product_code: 'P-7' } } });

    const sets = order.filter((o) => o.startsWith('set:["quickOptionModal"]'));
    expect(sets[0]).toContain(':null');
    expect(sets[1]).toContain(':value');
    expect(order.indexOf('dispatch:/api/modules/sirsoft-ecommerce/products/P-7')).toBeGreaterThan(order.indexOf(sets[1]));
    expect(order[order.length - 1]).toBe('modal.open');

    const call = dispatch.mock.calls[0][0];
    expect(call.auth_mode).toBe('optional');
    expect(call.params.method).toBe('GET');
    expect(globalState.quickOptionModal.loading).toBe(false);
    expect(globalState.quickOptionModal.detail.id).toBe(7);
  });

  // @scenario case=c16
  // @effects open_quick_option_prefills_single_option_row
  it('옵션이 하나 이하면 그 옵션으로 고정 줄 하나를 미리 채운다(최소 구매 수량)', async () => {
    dispatch.mockImplementationOnce(async () => ({ success: true, data: { data: detail({ min_purchase_qty: 2 }) } }));

    await openQuickOptionHandler({ handler: 'x', params: { product: { id: 7, product_code: 'P-7' } } });

    const modal = globalState.quickOptionModal;
    expect(modal.matchedOption.id).toBe(70);
    expect(modal.selectedOptionItems).toHaveLength(1);
    expect(modal.selectedOptionItems[0]).toMatchObject({ optionId: 70, quantity: 2, fixed: true, totalPrice: 10000 });
  });

  // @scenario case=c17
  // @effects open_quick_option_prefills_single_option_row
  it('옵션이 둘 이상이면 줄 0 에서 시작한다', async () => {
    dispatch.mockImplementationOnce(async () => ({
      success: true,
      data: { data: detail({ has_options: true, options: [{ id: 70 }, { id: 71 }], option_groups: [{ name_localized: '색상' }] }) },
    }));

    await openQuickOptionHandler({ handler: 'x', params: { product: { id: 7, product_code: 'P-7' } } });

    expect(globalState.quickOptionModal.selectedOptionItems).toEqual([]);
    expect(globalState.quickOptionModal.matchedOption).toBeNull();
  });

  // @scenario case=c18
  // @effects open_quick_option_clears_namespace_first
  it('상세를 받지 못하면 알리고 창을 열지 않는다', async () => {
    dispatch.mockImplementationOnce(async () => ({ success: false, error: new Error('찾을 수 없습니다') }));

    await openQuickOptionHandler({ handler: 'x', params: { product: { id: 7, product_code: 'P-7' } } });

    expect(modalOpen).not.toHaveBeenCalled();
    expect(toast.error).toHaveBeenCalledWith('찾을 수 없습니다');
    expect(globalState.quickOptionModal).toBeNull();
  });
});

describe('카드 찜', () => {
  // @scenario case=c19
  // @effects wishlist_guest_makes_no_request
  it('비회원은 요청 없이 로그인 안내만 받는다', async () => {
    const result = await toggleWishlistQuickHandler({ handler: 'x', params: { product: { id: 7, is_wishlisted: false } } });

    expect(result).toBeNull();
    expect(dispatch).not.toHaveBeenCalled();
    expect(toast.warning).toHaveBeenCalledWith('shop.wishlist_login_required');
  });

  // @scenario case=c20
  // @effects wishlist_member_toggles_and_shares_state
  it('회원은 찜을 바꾸고 결과를 같은 상품의 다른 카드와 나눈다', async () => {
    globalState.currentUser = { uuid: 'u-1' };

    const result = await toggleWishlistQuickHandler({ handler: 'x', params: { product: { id: 7, is_wishlisted: false } } });

    const call = dispatch.mock.calls[0][0];
    expect(call.target).toBe('/api/modules/sirsoft-ecommerce/wishlist/toggle');
    expect(call.auth_mode).toBe('required');
    expect(call.params).toEqual({ method: 'POST', body: { product_id: 7 } });
    expect(result).toBe(true);
    expect(getWishlistOverride(7)).toBe(true);
    expect(toast.success).toHaveBeenCalledWith('shop.wishlist_added');

    // 다른 회원으로 바뀌면 앞 회원의 결과를 버린다
    globalState.currentUser = { uuid: 'u-2' };
    expect(getWishlistOverride(7)).toBeUndefined();
  });
});

describe('추가 정보 미입력 거부(403 required_fields_pending)', () => {
  const PENDING_MODAL = 'required_info_pending_modal';
  let engineGlobal: Record<string, any>;

  /**
   * 카드 핸들러의 요청을 실제 엔진(ActionDispatcher·ErrorHandlingResolver)으로 보낸다.
   * 화면 레이아웃 수준 403 처리(상품 상세의 오류 화면, 목록의 접근 거부 표시)가 걸린 상태를 그대로 만든다 —
   * 자기 오류 처리가 없는 요청은 그 화면 처리가 템플릿 수준 「추가 정보 필요」 처리보다 먼저라 창이 뜨지 않았다.
   */
  async function withEngine(layout: 'shop/show' | 'shop/index', status: number, body: Record<string, unknown>) {
    const { ActionDispatcher } = await import('@core/template-engine/ActionDispatcher');
    const { ErrorHandlingResolver, getErrorHandlingResolver } = await import('@core/error/ErrorHandlingResolver');
    const templateManifest = (await import('../../../template.json')).default as any;
    const layoutJson = (layout === 'shop/show'
      ? (await import('../../../layouts/shop/show.json')).default
      : (await import('../../../layouts/shop/index.json')).default) as any;

    ErrorHandlingResolver.resetInstance();
    engineGlobal = { ...globalState, toasts: [], modalStack: [] };
    const dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    dispatcher.setGlobalStateUpdater((updates: any) => {
      const next = typeof updates === 'function' ? updates(engineGlobal) : updates;
      engineGlobal = { ...engineGlobal, ...next };
    });
    (window as any).G7Core.state.get = () => engineGlobal;
    (window as any).G7Core.state.getLocal = () => ({});
    (window as any).G7Core.state.setLocal = () => {};
    (window as any).G7Core.dispatch = (action: any) => dispatcher.dispatchAction(action, { data: {} } as any);
    (window as any).__templateApp = { getRouter: () => ({ match: () => ({ route: {} }) }) };
    getErrorHandlingResolver().setTemplateConfig(templateManifest.errorHandling);
    getErrorHandlingResolver().setLayoutConfig(layoutJson.errorHandling);
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => new Response(JSON.stringify(body), { status }));
  }

  const pendingBody = {
    success: false,
    message: '추가 정보를 입력해야 이용할 수 있습니다.',
    error_code: 'required_fields_pending',
    data: { pending_required_fields: ['homepage'] },
  };

  afterEach(async () => {
    vi.restoreAllMocks();
    const { ErrorHandlingResolver } = await import('@core/error/ErrorHandlingResolver');
    ErrorHandlingResolver.resetInstance();
    delete (window as any).__templateApp;
  });

  // @scenario case=c32
  // @effects card_actions_open_required_info_modal_on_pending
  it.each(['shop/show', 'shop/index'] as const)('%s 화면의 카드 찜·곧바로 담기가 그 거부를 받으면 오류 알림 대신 「추가 정보 필요」 창을 연다', async (layout) => {
    globalState.currentUser = { uuid: 'u-1' };
    await withEngine(layout, 403, pendingBody);
    expect(await toggleWishlistQuickHandler({ handler: 'x', params: { product: { id: 7, is_wishlisted: false } } })).toBeNull();
    expect(engineGlobal.modalStack).toEqual([PENDING_MODAL]);
    expect(toast.error).not.toHaveBeenCalled();
    expect(engineGlobal.toasts ?? []).toEqual([]);

    engineGlobal.modalStack = [];
    expect(await quickAddToCartHandler({ handler: 'x', params: { product: { id: 7, options_count: 1, has_additional_options: false } } })).toBe(false);
    expect(engineGlobal.modalStack).toEqual([PENDING_MODAL]);
    expect(toast.error).not.toHaveBeenCalled();
    expect(engineGlobal.toasts ?? []).toEqual([]);
  });

  // @scenario case=c33
  // @effects card_actions_keep_toast_for_other_403
  it('다른 403 은 창을 열지 않고 서버 문구를 알린다(화면 수준 403 처리로 넘어가지 않는다)', async () => {
    globalState.currentUser = { uuid: 'u-1' };
    await withEngine('shop/show', 403, { success: false, message: '권한이 없습니다.' });
    await toggleWishlistQuickHandler({ handler: 'x', params: { product: { id: 7, is_wishlisted: false } } });
    expect(engineGlobal.modalStack ?? []).not.toContain(PENDING_MODAL);
    expect(toast.error).toHaveBeenCalledWith('권한이 없습니다.');
  });
});
