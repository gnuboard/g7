/**
 * 취소·환불 신청서 — 「자동으로 끝난다」는 안내는 결제수단 게이트를 함께 본다 (#599 세션A · P16·P17)
 *
 * 배경: 신청서는 두 가지를 갈라 말한다.
 *   ① 분기(`refund_branch`) — 즉시취소(auto) 인가 환불신청(request) 인가. 상품 상태가 정한다.
 *   ② 게이트(`refund_auto_approved`) — 접수 뒤 사람 손 없이 끝나는가. **결제수단**이 정한다.
 *   무통장·가상계좌는 ①이 auto 여도 ②가 false 라서 `requested` 에서 멈추고 운영자 승인을 기다린다.
 *
 * 결함(브라우저 실측 2026-09-21 · 주문 `20260921-1316400577`, 무통장):
 *   화면이 ①만 보고 상품 배지에 「자동 처리」, 하단에 「환불신청서가 접수되고 승인·환불완료까지
 *   자동으로 진행됩니다」를 띄웠다. 실제 접수 결과는 `RF20260921-1357532719` **`requested` 정지**.
 *   구매자는 곧 돈이 돌아온다고 읽고 기다리게 된다.
 *
 * 회귀 차단 포인트: 두 자리(상품 배지 · 하단 안내) 모두 `refund_auto_approved` 를 함께 본다.
 *   게이트가 false 면 이미 있는 「환불 신청」/「판매자 확인」 문구로 갈린다(새 문구를 만들지 않는다).
 */

import { describe, it, expect } from 'vitest';

import applyItems from '../../layouts/partials/mypage/claims/_apply_items.json';
import applySummary from '../../layouts/partials/mypage/claims/_apply_summary.json';

const itemsRaw = JSON.stringify(applyItems);
const summaryRaw = JSON.stringify(applySummary);

/** 게이트 키 — 서버(`UserClaimService::claimableOptions`)가 싣는 이름 그대로 */
const GATE = 'refund_auto_approved';

describe('신청서 «자동 처리» 안내', () => {
  // @scenario surface=claim_apply_badge
  // @effects claim_apply_auto_badge_respects_payment_gate
  it('상품 배지가 분기와 게이트를 함께 본다', () => {
    // 배지 문구를 고르는 표현식이 게이트 키를 참조해야 한다.
    expect(itemsRaw).toContain('refund_branch_cancel_badge');
    expect(itemsRaw.includes(GATE)).toBe(true);
  });

  // @scenario surface=claim_apply_notice
  // @effects claim_apply_auto_notice_respects_payment_gate
  it('하단 안내가 분기와 게이트를 함께 본다', () => {
    expect(summaryRaw).toContain('claim.apply.notice_cancel');
    expect(summaryRaw.includes(GATE)).toBe(true);
  });

  // @scenario surface=claim_apply_notice
  // @effects claim_apply_auto_notice_reuses_existing_wording
  it('게이트가 막을 때는 이미 있는 «판매자 확인» 문구로 갈린다', () => {
    // 새 문구를 만들면 ja 를 포함한 언어팩이 갈린다 — 기존 키를 그대로 쓴다.
    expect(summaryRaw).toContain('claim.apply.notice_refund');
    expect(itemsRaw).toContain('claim.apply.refund_branch_refund_badge');
  });
});
