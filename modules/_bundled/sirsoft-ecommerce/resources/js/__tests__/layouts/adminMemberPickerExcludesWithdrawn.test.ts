// e2e:allow 레이아웃 정적 구조(회원 검색 요청의 query 인자) 단언 전용 — 서버 거르기는 코어 PHPUnit(`exclude_withdrawn`)이, 브라우저 축은 7.1.1 출시 전 캠페인 재실측(탈퇴 회원 이름으로 검색해 결과에 없음)이 덮는다.
/**
 * @file adminMemberPickerExcludesWithdrawn.test.ts
 * @description 마일리지 수동 지급/차감·쿠폰 직접 발급 창의 회원 고르기는 탈퇴 회원을 검색하지 않는다 (7.1.1 넘김)
 *
 * 두 창은 코어 회원 검색(`GET /api/admin/users/search`)으로 받을 회원을 고른다. 검색은 기본으로 탈퇴 회원(`status=withdrawn`)도
 * 돌려주는데, 서버는 그 회원에게도 그대로 지급·발급한다 — 마일리지 관리자 지급(`UserMileageService::adminEarn`)은 주문 적립·환불
 * 반환과 달리 `forfeitIfWithdrawn` 를 거치지 않아 잔액이 그대로 남고, 쿠폰 직접 발급(`UserCouponService::issueDirectlyToUser`)도
 * 회원 상태를 보지 않는다. 탈퇴 회원은 로그인할 수 없으니 받은 것을 쓸 수 없다. 그래서 고르는 자리에서 뺀다
 * (코어가 7.1.1 에 더한 `exclude_withdrawn=1` — 알림 수신자 고르기 5곳과 같은 방식).
 *
 * 반대로 목록 필터(주문·쿠폰 사용 내역·배송지의 「회원」 필터)는 탈퇴 회원의 기록을 찾는 자리라 빼지 않는다 — 함께 잠근다.
 *
 * 축 요약(마커 아님 — 평문): surface. 매핑은 테스트마다 단다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const ROOT = resolve(__dirname, '../../../..');
const P = 'resources/layouts/admin/partials';

/** 받을 회원을 고르는 창 — 탈퇴 회원을 빼야 한다 */
const PICKERS = [
    `${P}/admin_ecommerce_mileage_transaction_index/_modal_manual_transaction.json`,
    `${P}/admin_ecommerce_promotion_coupon_list/_modal_direct_issue.json`,
];

/** 기록을 찾는 목록 필터 — 탈퇴 회원도 찾아야 한다 */
const FILTERS = [
    `${P}/admin_ecommerce_order_list/_partial_filter_section.json`,
    `${P}/admin_ecommerce_promotion_coupon_list/_partial_filter_section.json`,
    `${P}/admin_ecommerce_user_address_index/_partial_filter_section.json`,
];

/**
 * 레이아웃 JSON 에서 코어 회원 검색 요청의 query 를 모은다(apiCall 액션·데이터소스 모두).
 *
 * @param rel 확장 루트 기준 경로
 * @returns query 객체 목록
 */
function searchQueries(rel: string): Array<Record<string, any>> {
    const out: Array<Record<string, any>> = [];
    (function visit(o: any): void {
        if (Array.isArray(o)) return o.forEach(visit);
        if (!o || typeof o !== 'object') return;
        const target = String(o.target ?? o.endpoint ?? '');
        if (target.includes('/api/admin/users/search')) out.push(o.params?.query ?? o.query ?? o.params ?? {});
        Object.values(o).forEach(visit);
    })(JSON.parse(readFileSync(join(ROOT, rel), 'utf-8')));

    return out;
}

describe('회원 고르기 창은 탈퇴 회원을 검색하지 않는다', () => {
    /**
     * 받을 회원을 고르는 두 창(마일리지 수동 지급/차감 · 쿠폰 직접 발급)의 회원 검색이 exclude_withdrawn=1 을 싣는다.
     *
     * @scenario surface=admin_modal
     * @effects member_picker_excludes_withdrawn
     */
    it.each(PICKERS)('%s — 회원 검색에 exclude_withdrawn=1 을 싣는다', (rel) => {
        const queries = searchQueries(rel);
        expect(queries.length).toBeGreaterThan(0);
        expect(queries.map((q) => String(q.exclude_withdrawn))).toEqual(queries.map(() => '1'));
    });

    /**
     * 기록을 찾는 목록 「회원」 필터 세 곳(주문·쿠폰·배송지)은 exclude_withdrawn 을 싣지 않는다.
     *
     * @scenario surface=admin_filter
     * @effects member_picker_excludes_withdrawn
     */
    it.each(FILTERS)('%s — 목록 필터는 탈퇴 회원 기록도 찾아야 하므로 빼지 않는다', (rel) => {
        const queries = searchQueries(rel);
        expect(queries.every((q) => q.exclude_withdrawn === undefined)).toBe(true);
    });
});
