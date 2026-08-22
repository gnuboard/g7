/**
 * @file mp13-i18n-ux.test.tsx
 * @description MP13 — i18n·UX 소품 레이아웃 회귀 테스트 (sirsoft-basic)
 *
 * 검증 항목:
 * - U3: 배송 메모가 라벨(delivery_memo_label) 우선·키 폴백으로 표시
 * - U21: 운송장 번호가 tracking_url 있을 때 배송조회 링크(A target=_blank), 없으면 텍스트
 * - A21: shop/show.json product data_source 에 errorHandling 404/403
 * - A5: 취소 모달 '기타' 선택 시 상세 사유 Textarea + 에러 Span + onClose 리셋 + submit param
 * - U17: common.json 에 pagination aria-label 6키 (ko/en)
 * - U19④: 주문 이력에 취소일시(cancelled_at_formatted) 행
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');

function loadJson(relPath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(baseDir, relPath), 'utf8'));
}

function serialize(node: any): string {
  return JSON.stringify(node);
}

describe('U3 — 배송 메모 라벨 표시', () => {
  const text = serialize(loadJson('layouts/partials/mypage/orders/_shipping.json'));

  it('배송 메모 표시가 delivery_memo_label 우선·delivery_memo 폴백', () => {
    expect(text).toContain('delivery_memo_label ?? order.data.shipping_address?.delivery_memo');
  });

  it('배송 메모 if 조건도 label/memo 둘 다 고려', () => {
    expect(text).toContain('delivery_memo_label || order.data.shipping_address?.delivery_memo');
  });
});

describe('U21 — 배송조회 링크', () => {
  const layout = loadJson('layouts/partials/mypage/orders/_shipping.json');
  const text = serialize(layout);

  it('tracking_url 있을 때 A(링크) target=_blank + rel=noopener', () => {
    expect(text).toContain('"if":"{{shipping.tracking_url}}"');
    expect(text).toContain('"target":"_blank"');
    expect(text).toContain('noopener noreferrer');
    expect(text).toContain('track_shipment');
  });

  it('tracking_url 없을 때 텍스트 폴백 Span', () => {
    expect(text).toContain('!shipping.tracking_url && shipping.tracking_number');
  });
});

describe('A21 — 전시중지 상품 404', () => {
  const showLayout = loadJson('layouts/shop/show.json');
  const productDs = showLayout.data_sources.find((d: any) => d.id === 'product');

  it('product data_source 에 errorHandling 404/403 핸들러', () => {
    expect(productDs.errorHandling).toBeDefined();
    expect(productDs.errorHandling['404'].handler).toBe('showErrorPage');
    expect(productDs.errorHandling['404'].params.errorCode).toBe(404);
    expect(productDs.errorHandling['403'].handler).toBe('showErrorPage');
    expect(productDs.errorHandling['403'].params.errorCode).toBe(403);
  });
});

describe('A5 — 취소 모달 기타 사유', () => {
  const text = serialize(loadJson('layouts/partials/mypage/orders/_modal_cancel.json'));

  it('onClose 리셋에 cancelReasonDetail 포함', () => {
    expect(text).toContain('"cancelReasonDetail":""');
  });

  it("기타(etc) 선택 시 상세 사유 Textarea (if cancelReason === 'etc')", () => {
    expect(text).toContain("_local.cancelReason === 'etc'");
    expect(text).toContain('cancel_reason_detail_placeholder');
  });

  it('상세 사유 에러 Span (cancelValidationErrors.reason_detail)', () => {
    expect(text).toContain('cancelValidationErrors?.reason_detail');
  });

  it('submit param 에 cancelReasonDetail 전송', () => {
    expect(text).toContain('"cancelReasonDetail":"{{_local.cancelReasonDetail ?? \'\'}}"');
  });
});

describe('U17 — 페이지네이션 aria-label 6키', () => {
  const ko = loadJson('lang/partial/ko/common.json');
  const en = loadJson('lang/partial/en/common.json');
  const keys = ['pagination', 'first_page', 'prev_page', 'next_page', 'last_page', 'page_n'];

  it('ko common.json 에 6키 정의', () => {
    keys.forEach((k) => expect(ko[k], `ko.${k}`).toBeTruthy());
    expect(ko.page_n).toContain('{{n}}');
  });

  it('en common.json 에 6키 정의', () => {
    keys.forEach((k) => expect(en[k], `en.${k}`).toBeTruthy());
    expect(en.page_n).toContain('{{n}}');
  });
});

describe('U19④ — 취소일시 표시', () => {
  const text = serialize(loadJson('layouts/partials/mypage/orders/_history.json'));

  it('취소일시 행이 cancelled_at_formatted 조건/바인딩으로 추가', () => {
    expect(text).toContain('"if":"{{order.data.cancelled_at_formatted}}"');
    expect(text).toContain('cancelled_at_formatted ?? \'\'');
    expect(text).toContain('mypage.order_detail.cancelled_at');
  });
});

describe('취소 사유 표시 — 유저 주문 이력', () => {
  const text = serialize(loadJson('layouts/partials/mypage/orders/_history.json'));

  it('취소 이력이 있을 때만 노출되는 취소 사유 블록', () => {
    expect(text).toContain('mypage.order_detail.cancel_reason_title');
    expect(text).toContain('order.data.cancels?.data ?? order.data.cancels ?? []');
  });

  it('취소 건별 사유 라벨·상세 사유 바인딩', () => {
    expect(text).toContain('cancel.cancel_reason_label ?? \'\'');
    expect(text).toContain('"if":"{{cancel.cancel_reason_detail}}"');
    expect(text).toContain('cancel.cancel_reason_detail ?? \'\'');
  });

  it('취소 건은 iteration(item_var=cancel)으로 렌더', () => {
    expect(text).toContain('"item_var":"cancel"');
  });
});

describe('i18n 키 동반', () => {
  const ko = loadJson('lang/partial/ko/mypage.json');
  const en = loadJson('lang/partial/en/mypage.json');

  it('order_detail 에 track_shipment / cancelled_at / cancel_reason_detail_placeholder', () => {
    expect(ko.order_detail.track_shipment).toBeTruthy();
    expect(en.order_detail.track_shipment).toBeTruthy();
    expect(ko.order_detail.cancelled_at).toBeTruthy();
    expect(en.order_detail.cancelled_at).toBeTruthy();
    expect(ko.order_detail.cancel_modal.cancel_reason_detail_placeholder).toBeTruthy();
    expect(en.order_detail.cancel_modal.cancel_reason_detail_placeholder).toBeTruthy();
  });

  it('취소 사유 표시용 키(cancel_reason_title / cancel_reason / cancel_reason_detail)', () => {
    expect(ko.order_detail.cancel_reason_title).toBeTruthy();
    expect(en.order_detail.cancel_reason_title).toBeTruthy();
    expect(ko.order_detail.cancel_reason).toBeTruthy();
    expect(en.order_detail.cancel_reason).toBeTruthy();
    expect(ko.order_detail.cancel_reason_detail).toBeTruthy();
    expect(en.order_detail.cancel_reason_detail).toBeTruthy();
  });
});
