/**
 * @file required-info-pending-own-fail.test.ts
 * @description 스스로 실패 처리를 정한 쓰기 요청도 「추가 정보 필요」 안내 창을 연다
 *
 * 필수 항목이 비어 있는 회원의 쓰기 요청을 서버가 거부하면(403, error_code 가 required_fields_pending) 템플릿 수준 오류 처리가
 * 안내 창을 연다. 그런데 자기 실패 처리(onError)를 가진 요청은 그 처리가 먼저라 템플릿 수준에 닿지 않는다.
 * 그래서 회원이 보낼 수 있고 서버가 막을 수 있는 쓰기 요청의 실패 처리마다 맨 앞에서 안내 창을 열고,
 * 일반 오류 알림은 그 거부가 아닐 때만 띄운다. 로딩 표시를 끄는 상태 정리는 두 경우 모두 그대로 돈다.
 *
 * 구운 레이아웃 JSON 의 실패 처리를 실제 ActionDispatcher 와 ErrorHandlingResolver 로 돌린다.
 *
 * @vitest-environment jsdom
 * @scenario-manifest tests/scenarios/required-info-pending-own-fail.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import { ErrorHandlingResolver, getErrorHandlingResolver } from '@core/error/ErrorHandlingResolver';

import templateManifest from '../../template.json';

type Node = Record<string, any>;

const PENDING = 'required_fields_pending';
const MODAL = 'required_info_pending_modal';
const LAYOUTS = path.resolve(__dirname, '../../layouts');

/** 배열이든 객체 하나든 배열로 맞춘다 */
function list<T = Node>(value: unknown): T[] {
  if (value === undefined || value === null) return [];
  return (Array.isArray(value) ? value : [value]) as T[];
}

/** 노드 아래의 모든 객체를 훑는다 */
function walk(node: unknown, visit: (found: Node) => void): void {
  if (!node || typeof node !== 'object') return;
  if (!Array.isArray(node)) visit(node as Node);
  for (const value of Object.values(node as Node)) walk(value, visit);
}

/**
 * 고친 요청 — 화면 조각, 요청 주소(레이아웃에 적힌 그대로), 메서드, 그 조각 안의 개수,
 * 그 거부가 아닌 실패에서 일반 오류 알림이 뜨는지(종전에도 알림이 없던 요청은 false).
 */
interface Target {
  screen: string;
  file: string;
  target: string;
  method: string;
  count: number;
  toast: boolean;
}

const BOARD = '/api/modules/sirsoft-board';
const SHOP = '/api/modules/sirsoft-ecommerce';
const ADDRESS_SAVE = (state: string) => `{{${state}?.id ? '${SHOP}/user/addresses/' + ${state.replace('?.', '.')}.id : '${SHOP}/user/addresses'}}`;

const TARGETS: Target[] = [
  // 게시판
  { screen: '게시글 등록과 수정', file: 'partials/board/form/_post_form.json', target: `{{route.id ? '${BOARD}/boards/' + route.slug + '/posts/' + route.id : '${BOARD}/boards/' + route.slug + '/posts'}}`, method: "{{route.id ? 'PUT' : 'POST'}}", count: 1, toast: true },
  { screen: '댓글 등록', file: 'partials/board/show/_comment_input.json', target: `${BOARD}/boards/{{route?.slug}}/posts/{{route?.id}}/comments`, method: 'POST', count: 1, toast: true },
  { screen: '댓글 반응', file: 'partials/board/show/_comment_item.json', target: `${BOARD}/boards/{{route.slug}}/comments/{{comment?.id}}/react`, method: 'POST', count: 1, toast: true },
  { screen: '댓글 수정', file: 'partials/board/show/_comment_item.json', target: `${BOARD}/boards/{{route?.slug}}/posts/{{route?.id}}/comments/{{comment?.id}}`, method: 'PUT', count: 1, toast: true },
  { screen: '답글 등록', file: 'partials/board/show/_comment_item.json', target: `${BOARD}/boards/{{route?.slug}}/posts/{{route?.id}}/comments`, method: 'POST', count: 1, toast: true },
  { screen: '게시글과 댓글 삭제', file: 'partials/board/show/modals/_modal_delete.json', target: '{{_global.deleteModal.apiEndpoint}}', method: 'DELETE', count: 1, toast: true },
  { screen: '게시글과 댓글 신고', file: 'partials/board/show/modals/_modal_report.json', target: '{{_global.reportModal?.apiEndpoint}}', method: 'POST', count: 1, toast: true },
  { screen: '게시글 반응', file: 'partials/board/types/basic/show.json', target: `${BOARD}/boards/{{route.slug}}/posts/{{route.id}}/react`, method: 'POST', count: 1, toast: true },
  { screen: '게시글 스크랩', file: 'partials/board/types/basic/show.json', target: `${BOARD}/boards/{{route.slug}}/posts/{{route.id}}/scrap/toggle`, method: 'POST', count: 1, toast: true },
  { screen: '내 반응 선택 삭제', file: 'partials/mypage/board/_modal_reaction_bulk_delete.json', target: `${BOARD}/me/reactions`, method: 'DELETE', count: 1, toast: true },
  { screen: '내 반응 삭제', file: 'partials/mypage/board/_modal_reaction_delete.json', target: `${BOARD}/me/reactions/{{_global.reactionDeleteModal?.id}}`, method: 'DELETE', count: 1, toast: true },
  { screen: '내 신고 취소', file: 'partials/mypage/board/_modal_report_cancel.json', target: `${BOARD}/me/reports/{{_global.reportCancelModal?.id}}`, method: 'DELETE', count: 1, toast: true },
  { screen: '스크랩 선택 삭제', file: 'partials/mypage/scrap/_modal_scrap_bulk_delete.json', target: `${BOARD}/me/scraps`, method: 'DELETE', count: 1, toast: true },
  { screen: '스크랩 삭제', file: 'partials/mypage/scrap/_modal_scrap_delete.json', target: `${BOARD}/me/scraps/{{_global.scrapDeleteModal?.id}}`, method: 'DELETE', count: 1, toast: true },
  // 마이페이지 배송지와 찜
  { screen: '배송지 저장', file: 'partials/mypage/addresses/_modal_address.json', target: ADDRESS_SAVE('_global.editingAddress'), method: "{{_global.editingAddress?.id ? 'PUT' : 'POST'}}", count: 1, toast: true },
  { screen: '배송지 삭제', file: 'partials/mypage/addresses/_modal_confirm_delete.json', target: `${SHOP}/user/addresses/{{_global.deletingAddressId}}`, method: 'DELETE', count: 1, toast: true },
  { screen: '배송지 덮어쓰기', file: 'partials/mypage/addresses/_modal_confirm_overwrite.json', target: ADDRESS_SAVE('_global.editingAddress'), method: "{{_global.editingAddress?.id ? 'PUT' : 'POST'}}", count: 1, toast: true },
  { screen: '찜 목록에서 삭제', file: 'partials/mypage/wishlist/_list.json', target: `${SHOP}/wishlist/{{item.id}}`, method: 'DELETE', count: 1, toast: true },
  // 장바구니
  { screen: '장바구니 수량 변경', file: 'partials/shop/_cart_item.json', target: `${SHOP}/cart/{{item.id}}/quantity`, method: 'PATCH', count: 1, toast: true },
  { screen: '장바구니에서 주문하기', file: 'partials/shop/_cart_summary.json', target: `${SHOP}/checkout`, method: 'POST', count: 1, toast: true },
  { screen: '장바구니 선택 삭제', file: 'partials/shop/_modal_cart_delete_confirm.json', target: `${SHOP}/cart`, method: 'DELETE', count: 1, toast: true },
  { screen: '장바구니 옵션 변경', file: 'partials/shop/_modal_cart_option_change.json', target: `${SHOP}/cart/{{$parent._local.optionModal.targetItem?.id}}/option`, method: 'PUT', count: 1, toast: false },
  // 주문서
  { screen: '주문서 할인', file: 'partials/shop/_checkout_discount.json', target: `${SHOP}/checkout`, method: 'PUT', count: 3, toast: true },
  { screen: '주문서 상품 쿠폰', file: 'partials/shop/_checkout_items.json', target: `${SHOP}/checkout`, method: 'PUT', count: 2, toast: true },
  { screen: '주문서 적립금', file: 'partials/shop/_checkout_mileage.json', target: `${SHOP}/checkout`, method: 'PUT', count: 1, toast: true },
  { screen: '주문서 배송지', file: 'partials/shop/_checkout_shipping.json', target: `${SHOP}/checkout`, method: 'PUT', count: 4, toast: true },
  { screen: '주문 생성', file: 'partials/shop/_checkout_summary.json', target: `${SHOP}/user/orders`, method: 'POST', count: 1, toast: true },
  { screen: '주문서 배송지 삭제', file: 'partials/shop/_modal_address_manage.json', target: `${SHOP}/user/addresses/{{addr.id}}`, method: 'DELETE', count: 1, toast: true },
  { screen: '주문서 배송지 저장', file: 'partials/shop/_modal_address_manage.json', target: ADDRESS_SAVE('_global.checkoutAddressModal?.editing'), method: "{{_global.checkoutAddressModal?.editing?.id ? 'PUT' : 'POST'}}", count: 1, toast: true },
  { screen: '주문서 쿠폰 받기', file: 'partials/shop/_modal_coupon_download.json', target: `${SHOP}/user/coupons/{{coupon.coupon_id}}/download`, method: 'POST', count: 1, toast: true },
  { screen: '주문서 쿠폰 받은 뒤 다시 계산', file: 'partials/shop/_modal_coupon_download.json', target: `${SHOP}/checkout`, method: 'PUT', count: 1, toast: true },
  { screen: '주문 완료 화면 배송지 저장', file: 'shop/order_complete.json', target: `${SHOP}/user/addresses`, method: 'POST', count: 1, toast: true },
  { screen: '다시 주문', file: 'shop/reorder.json', target: `${SHOP}/user/orders/{{route.id}}/reorder`, method: 'POST', count: 1, toast: true },
  // 상품 상세
  { screen: '상품 찜', file: 'partials/shop/detail/_header.json', target: `${SHOP}/wishlist/toggle`, method: 'POST', count: 1, toast: false },
  { screen: '상품 쿠폰 받기', file: 'partials/shop/detail/_modal_coupon_download_confirm.json', target: `${SHOP}/user/coupons/{{$parent._local.selectedCouponForDownload?.coupon_id}}/download`, method: 'POST', count: 1, toast: true },
  { screen: '상품 문의와 답변 삭제', file: 'partials/shop/detail/_modal_inquiry_delete.json', target: `{{(_global.qnaDeleteIsAdmin ?? false) ? ((_global.qnaDeleteIsReply ?? false) ? '${SHOP}/admin/inquiries/' + _global.qnaDeleteId + '/reply' : '${SHOP}/admin/inquiries/' + _global.qnaDeleteId) : ((_global.qnaDeleteIsReply ?? false) ? '${SHOP}/user/inquiries/' + _global.qnaDeleteId + '/reply' : '${SHOP}/user/inquiries/' + _global.qnaDeleteId)}}`, method: 'DELETE', count: 1, toast: true },
  { screen: '상품 문의 답변 수정(창)', file: 'partials/shop/detail/_modal_qna_reply.json', target: `${SHOP}/user/inquiries/{{_global.qnaReplyEditId}}/reply`, method: 'PUT', count: 1, toast: true },
  { screen: '상품 문의 답변 등록(창)', file: 'partials/shop/detail/_modal_qna_reply.json', target: `${SHOP}/user/inquiries/{{_global.qnaReplyTargetId}}/reply`, method: 'POST', count: 1, toast: true },
  { screen: '상품 문의 수정', file: 'partials/shop/detail/_modal_qna_write.json', target: `${SHOP}/user/inquiries/{{_global.qnaEditId}}`, method: 'PUT', count: 1, toast: true },
  { screen: '상품 문의 등록', file: 'partials/shop/detail/_modal_qna_write.json', target: `${SHOP}/products/{{_global.qnaProductId}}/inquiries`, method: 'POST', count: 1, toast: true },
  { screen: '바로 구매', file: 'partials/shop/detail/_purchase_card.json', target: `${SHOP}/checkout`, method: 'POST', count: 1, toast: true },
  { screen: '장바구니 담기', file: 'partials/shop/detail/_purchase_card.json', target: `${SHOP}/cart`, method: 'POST', count: 1, toast: true },
  { screen: '상품 문의 답변 수정(목록)', file: 'partials/shop/detail/_tab_qna.json', target: `${SHOP}/user/inquiries/{{item.id}}/reply`, method: 'PUT', count: 1, toast: true },
];

/** 그 조각에서 요청 노드를 찾는다 */
function requestsOf(target: Target): Node[] {
  const layout = JSON.parse(fs.readFileSync(path.join(LAYOUTS, target.file), 'utf8'));
  const found: Node[] = [];
  walk(layout, (node) => {
    if (node.handler === 'apiCall' && node.target === target.target && node.params?.method === target.method) found.push(node);
  });
  return found;
}

/** 실패 처리 안의 모든 동작(조건 분기와 순차 실행 안쪽 포함) */
function actionsIn(onError: unknown): Node[] {
  const found: Node[] = [];
  walk(onError, (node) => {
    if (typeof node.handler === 'string') found.push(node);
  });
  return found;
}

/** 그 거부일 때 도는 조건인지 — 동작의 if 또는 조건 분기 갈래의 if */
const opensOnPending = (condition: unknown) => condition === `{{error.error_code === '${PENDING}'}}`;

describe('스스로 실패 처리를 정한 쓰기 요청의 「추가 정보 필요」 안내', () => {
  let dispatcher: ActionDispatcher;
  let globalState: Record<string, any>;
  let localState: Record<string, any>;

  beforeEach(() => {
    ErrorHandlingResolver.resetInstance();
    globalState = { toasts: [], modalStack: [], requiredInfoGate: { from: '/board/free/write', exempt: false } };
    localState = {};
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    dispatcher.setGlobalStateUpdater((updates: any) => {
      const next = typeof updates === 'function' ? updates(globalState) : updates;
      globalState = { ...globalState, ...next };
    });
    (window as any).G7Core = {
      state: {
        get: () => globalState,
        getLocal: () => localState,
        setLocal: (updates: Record<string, any>) => {
          localState = { ...localState, ...updates };
        },
      },
    };
    (window as any).__templateApp = { getRouter: () => ({ match: () => ({ route: {} }) }) };
    // 실제 앱처럼 템플릿 수준 오류 처리도 걸어 둔다 — 요청의 자기 실패 처리가 먼저라 여기에는 닿지 않는다
    getErrorHandlingResolver().setTemplateConfig((templateManifest as Node).errorHandling);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    ErrorHandlingResolver.resetInstance();
    delete (window as any).__templateApp;
    delete (window as any).G7Core;
  });

  const rejected = (status: number, body: Record<string, unknown>) =>
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify(body), { status }));

  /** 레이아웃의 실패 처리를 그대로 달아 쓰기 요청을 보낸다 */
  const writeWith = (onError: unknown) =>
    dispatcher.dispatchAction(
      { type: 'click', handler: 'apiCall', target: '/api/modules/sirsoft-board/boards/free/posts', params: { method: 'POST', body: {} }, onError } as any,
      {
        data: {
          _local: {},
          _global: globalState,
          route: { slug: 'free', id: '1' },
          comment: { id: 1 },
          post: { data: { is_scraped: false } },
          product: { data: { is_wishlisted: false } },
          item: { id: 1 },
        },
      } as any
    );

  const toasts = () => list<Node>(globalState.toasts);

  /**
   * @scenario case=targets_exist
   * @effects every_listed_request_is_found_with_own_fail
   */
  it('표의 요청이 모두 그 조각에 있고 자기 실패 처리를 갖는다', () => {
    for (const target of TARGETS) {
      const requests = requestsOf(target);
      expect(requests.length, target.screen).toBe(target.count);
      for (const request of requests) expect(list(request.onError).length, target.screen).toBeGreaterThan(0);
    }
  });

  /**
   * @scenario case=pending_opens_modal
   * @effects pending_rejection_opens_modal_without_error_toast
   */
  it.each(TARGETS)('$screen — 추가 정보 미입력 거부면 안내 창을 열고 일반 오류 알림은 띄우지 않는다', async (target) => {
    for (const request of requestsOf(target)) {
      globalState = { ...globalState, toasts: [], modalStack: [] };
      rejected(403, {
        success: false,
        message: '추가 정보를 입력해야 이용할 수 있습니다.',
        error_code: PENDING,
        data: { pending_required_fields: ['job'], fields: [{ key: 'job', label: '직업' }] },
      });
      await writeWith(request.onError);

      expect(globalState.modalStack, target.screen).toEqual([MODAL]);
      expect(toasts(), target.screen).toEqual([]);
      vi.restoreAllMocks();
    }
  });

  /**
   * @scenario case=other_errors_unchanged
   * @effects other_failures_keep_previous_handling
   */
  it.each(TARGETS)('$screen — 다른 실패는 종전 처리 그대로다', async (target) => {
    const failures: Array<[number, Record<string, unknown>]> = [
      [403, { success: false, message: '권한이 없습니다.' }],
      [403, { success: false, message: '차단된 계정입니다.', error_code: 'account_blocked' }],
      [422, { success: false, message: '입력값을 확인해 주세요.', errors: { title: ['필수입니다.'] } }],
    ];
    for (const request of requestsOf(target)) {
      for (const [status, body] of failures) {
        globalState = { ...globalState, toasts: [], modalStack: [] };
        rejected(status, body);
        await writeWith(request.onError);

        expect(globalState.modalStack, `${target.screen} ${status}`).not.toContain(MODAL);
        expect(toasts().length, `${target.screen} ${status}`).toBe(target.toast ? 1 : 0);
        if (target.toast) expect(toasts()[0].type, `${target.screen} ${status}`).toBe('error');
        vi.restoreAllMocks();
      }
    }
  });

  /**
   * @scenario case=cleanup_in_both
   * @effects state_cleanup_runs_for_pending_and_other_failures
   */
  it('안내 창은 실패 처리 맨 앞에서 열고, 상태 정리는 그 거부 여부와 상관없이 돈다', () => {
    for (const target of TARGETS) {
      for (const request of requestsOf(target)) {
        const actions = actionsIn(request.onError);

        // 안내 창을 여는 동작은 하나뿐이고, 그 거부일 때만 돈다
        const openers = actions.filter((action) => action.handler === 'openModal' && action.target === MODAL);
        expect(openers.length, target.screen).toBe(1);

        const first = list<Node>(request.onError)[0];
        if (first.handler === 'conditions') {
          // 이미 조건 분기를 쓰던 요청 — 첫 갈래가 안내 창이다
          expect(opensOnPending(first.conditions[0].if), target.screen).toBe(true);
          expect(first.conditions[0].then, target.screen).toMatchObject({ handler: 'openModal', target: MODAL });
        } else {
          expect(first, target.screen).toMatchObject({ handler: 'openModal', target: MODAL });
          expect(opensOnPending(first.if), target.screen).toBe(true);
        }

        // 상태 정리(setState)에는 그 거부를 가르는 조건이 없다 — 두 경우 모두 돈다
        for (const action of actions.filter((item) => item.handler === 'setState')) {
          expect(String(action.if ?? ''), target.screen).not.toContain(PENDING);
        }
        // 일반 오류 알림은 그 거부가 아닐 때만 뜬다
        for (const action of actions.filter((item) => item.handler === 'toast')) {
          const guardedBySelf = String(action.if ?? '').includes(`error.error_code !== '${PENDING}'`);
          const guardedByBranch = first.handler === 'conditions';
          expect(guardedBySelf || guardedByBranch, target.screen).toBe(true);
        }
      }
    }
  });

  /**
   * @scenario case=gate_available
   * @effects go_button_origin_is_set_by_base_layout_on_every_screen
   */
  it('돌아올 주소는 상속원의 init 이 적으므로 고친 화면 어디서나 채워져 있다', () => {
    const base = JSON.parse(fs.readFileSync(path.join(LAYOUTS, '_user_base.json'), 'utf8')) as Node;
    const init = list<Node>(base.init_actions ?? base.initActions);
    expect(init.some((action) => action.handler === 'setState' && action.params?.target === 'global' && action.params?.requiredInfoGate !== undefined)).toBe(true);
    expect(list<Node>(base.modals).some((item) => item.partial === 'partials/_modal_required_info_pending.json')).toBe(true);

    // 요청을 가진 화면은 모두 상속원을 잇는다(조각은 그 화면에 끼워진다) — 상속원을 잇지 않는 화면 레이아웃이 없다
    const screens: string[] = [];
    const collect = (dir: string) => {
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
          if (entry.name !== 'partials') collect(full);
        } else if (entry.name.endsWith('.json') && entry.name !== '_user_base.json') {
          screens.push(full);
        }
      }
    };
    collect(LAYOUTS);
    expect(screens.length).toBeGreaterThan(0);
    for (const screen of screens) {
      const layout = JSON.parse(fs.readFileSync(screen, 'utf8')) as Node;
      expect(layout.extends, path.relative(LAYOUTS, screen)).toBe('_user_base');
    }
  });
});
