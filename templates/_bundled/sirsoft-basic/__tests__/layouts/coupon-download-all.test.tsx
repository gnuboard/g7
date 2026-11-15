/**
 * @file coupon-download-all.test.tsx
 * @description 쿠폰 「모두 받기」 — 쿠폰 받기 창과 상품 상세 쿠폰 줄에서 받지 않은 쿠폰을 한 번에 받는다.
 *
 * 검증 항목:
 * - 창의 「모두 받기」는 받지 않은 쿠폰이 있을 때만 보이고 진행 중에는 잠긴다
 * - 상품 상세에서 연 창은 그 상품의 받지 않은 쿠폰 번호만, 주문서에서 연 창은 번호 없이(서버가 전체) 보낸다
 * - 성공하면 받은·이미 받은 쿠폰을 받음으로 표시한 뒤 목록 재조회·서버 문구 안내·주문서 갱신 순으로 간다
 * - 창을 여는 두 자리가 범위(scope)를 남긴다
 * - 상세 쿠폰 줄의 「모두 받기」는 비회원이면 로그인 안내, 회원이면 그 상품 쿠폰 번호로 요청한다
 *
 * @scenario-manifest tests/scenarios/coupon-download-all.yaml
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const layoutsDir = path.resolve(__dirname, '../../layouts');
const BULK = '/api/modules/sirsoft-ecommerce/user/coupons/bulk-download';

function loadJson(relative: string): any {
  return JSON.parse(fs.readFileSync(path.join(layoutsDir, relative), 'utf-8'));
}

function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => collect(n, match, out));
    return out;
  }
  if (match(node)) out.push(node);
  Object.values(node).forEach((v) => collect(v, match, out));
  return out;
}

function evaluate(expression: string, scope: Record<string, any>): any {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  const names = Object.keys(scope);
  return new Function(...names, `return (${body});`)(...names.map((n) => scope[n]));
}

const modal = loadJson('partials/shop/_modal_coupon_download.json');
const summary = loadJson('partials/shop/detail/_info_summary.json');
const discount = loadJson('partials/shop/_checkout_discount.json');

const coupons = [
  { coupon_id: 1, is_downloaded: false },
  { coupon_id: 2, is_downloaded: true },
  { coupon_id: 3, is_downloaded: false },
];

describe('쿠폰 받기 창 — 모두 받기', () => {
  const button = collect(modal, (n) => n.name === 'Button' && JSON.stringify(n).includes(BULK))[0];
  const call = collect(button, (n) => n.handler === 'apiCall' && n.target === BULK)[0];

  // @scenario case=c01
  // @effects download_all_shown_only_when_something_left
  it('받지 않은 쿠폰이 있을 때만 보이고 진행 중에는 잠긴다', () => {
    expect(button).toBeTruthy();
    // 단추를 감싼 줄째로 숨긴다(빈 줄 간격이 남지 않게)
    const row = collect(modal, (n) => typeof n.if === 'string' && n.if.includes('!c.is_downloaded') && JSON.stringify(n).includes(BULK))[0];
    const visible = (data: any[]) => evaluate(row.if, { _global: { couponDownloadModal: { coupons: { data } } } });
    expect(visible(coupons)).toBe(true);
    expect(visible(coupons.map((c) => ({ ...c, is_downloaded: true })))).toBe(false);
    expect(visible([])).toBe(false);

    expect(evaluate(button.props.disabled, { _global: { couponDownloadModal: { downloadingAll: true } } })).toBe(true);
    expect(evaluate(button.props.disabled, { _global: { couponDownloadModal: {} } })).toBe(false);
    expect(JSON.stringify(button)).toContain('$t:shop.coupon_download.download_all');
    expect(String(button.props.className)).toMatch(/dark:/);

    // 누르면 먼저 잠근다
    const first = collect(button, (n) => n.handler === 'sequence')[0].actions[0];
    expect(first.handler).toBe('setState');
    expect(first.params['couponDownloadModal.downloadingAll']).toBe(true);
  });

  // @scenario case=c02
  // @effects download_all_body_follows_scope
  it('상품 상세에서 연 창은 받지 않은 쿠폰 번호만, 주문서에서 연 창은 번호 없이 보낸다', () => {
    expect(call.auth_mode).toBe('required');
    expect(call.params.method).toBe('POST');

    const body = (scope: string) => evaluate(call.params.body, { _global: { couponDownloadModal: { scope, coupons: { data: coupons } } } });
    expect(body('product')).toEqual({ coupon_ids: [1, 3] });
    expect(body('all')).toEqual({});
  });

  // @scenario case=c03
  // @effects download_all_marks_then_refreshes
  it('성공하면 받은·이미 받은 쿠폰을 받음으로 표시하고 재조회·안내·주문서 갱신 순으로 간다', () => {
    const success = Array.isArray(call.onSuccess) ? call.onSuccess : [call.onSuccess];
    expect(success[0].handler).toBe('setState');
    expect(success[0].params['couponDownloadModal.downloadingAll']).toBe(false);

    const next = evaluate(success[0].params['couponDownloadModal.coupons'], {
      _global: { couponDownloadModal: { coupons: { current_page: 1, data: coupons } } },
      response: {
        data: {
          results: [
            { coupon_id: 1, status: 'issued', reason_code: null },
            { coupon_id: 3, status: 'skipped', reason_code: 'issue_limit_reached' },
          ],
        },
      },
    });
    expect(next.current_page).toBe(1);
    expect(next.data.map((c: any) => c.is_downloaded)).toEqual([true, true, false]);

    // 이미 받았다고 답한 쿠폰도 받음으로 맞춘다
    const again = evaluate(success[0].params['couponDownloadModal.coupons'], {
      _global: { couponDownloadModal: { coupons: { data: coupons } } },
      response: { data: { results: [{ coupon_id: 3, status: 'skipped', reason_code: 'already_downloaded' }] } },
    });
    expect(again.data[2].is_downloaded).toBe(true);

    expect(success[1].handler).toBe('refetchDataSource');
    expect(JSON.stringify(success[1])).toContain('productDownloadableCoupons');
    expect(success[2].handler).toBe('toast');
    expect(success[2].params.message).toBe('{{response.message}}');
    // 단건 받기와 같은 주문서 갱신 조건
    const single = collect(modal, (n) => n.handler === 'apiCall' && String(n.target).endsWith('/download'))[0];
    const singleCheckout = collect(single, (n) => n.handler === 'conditions')[0];
    const withoutComment = ({ comment: _comment, ...rest }: any) => rest;
    expect(withoutComment(success[3])).toEqual(withoutComment(singleCheckout));

    const fail = JSON.stringify(call.onError);
    expect(fail).toContain('"couponDownloadModal.downloadingAll":false');
    expect(fail).toContain('toast');
  });
});

describe('쿠폰 받기 창을 여는 자리 — 범위 기록', () => {
  // @scenario case=c04
  // @effects coupon_modal_openers_record_scope
  it('상품 상세의 더보기는 product, 주문서는 all 을 남긴다', () => {
    const scopeOf = (layout: any) =>
      collect(layout, (n) => n.handler === 'setState' && n.params?.couponDownloadModal && typeof n.params.couponDownloadModal === 'object').map(
        (n) => n.params.couponDownloadModal,
      );

    const fromDetail = scopeOf(summary);
    expect(fromDetail).toHaveLength(1);
    expect(fromDetail[0].scope).toBe('product');
    expect(fromDetail[0].downloadingAll).toBe(false);

    const fromCheckout = scopeOf(discount);
    expect(fromCheckout).toHaveLength(1);
    expect(fromCheckout[0].scope).toBe('all');
    expect(fromCheckout[0].downloadingAll).toBe(false);
  });
});

describe('상품 상세 쿠폰 줄 — 모두 받기', () => {
  const button = collect(summary, (n) => n.name === 'Button' && JSON.stringify(n).includes(BULK))[0];

  // @scenario case=c05
  // @effects detail_download_all_guest_goes_to_login
  it('받지 않은 쿠폰이 있을 때만 보이고, 비회원이면 요청 대신 로그인 안내를 연다', () => {
    expect(button).toBeTruthy();
    const visible = (data: any[]) => evaluate(button.if, { productDownloadableCoupons: { data: { data } } });
    expect(visible(coupons)).toBe(true);
    expect(visible([{ coupon_id: 2, is_downloaded: true }])).toBe(false);

    const branches = collect(button, (n) => n.handler === 'conditions')[0].conditions;
    expect(branches[0].if).toBe('{{_global.currentUser?.uuid}}');
    expect(JSON.stringify(branches[0])).toContain(BULK);
    const guest = branches.find((b: any) => !b.if);
    expect(JSON.stringify(guest)).toContain('login_required_modal');
    expect(JSON.stringify(guest)).not.toContain(BULK);
  });

  // @scenario case=c06
  // @effects detail_download_all_posts_product_coupons
  it('회원이면 그 상품의 받지 않은 쿠폰 번호로 요청하고 성공하면 목록을 다시 받는다', () => {
    const call = collect(button, (n) => n.handler === 'apiCall' && n.target === BULK)[0];
    expect(call.auth_mode).toBe('required');
    expect(evaluate(call.params.body, { productDownloadableCoupons: { data: { data: coupons } } })).toEqual({ coupon_ids: [1, 3] });

    const success = JSON.stringify(call.onSuccess);
    expect(success).toContain('refetchDataSource');
    expect(success).toContain('{{response.message}}');
    expect(evaluate(button.props.disabled, { _local: { couponDownloadingAll: true } })).toBe(true);
  });
});
