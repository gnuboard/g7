// e2e:allow 레이아웃 계약 단위 테스트 자체다 — 화면 동작은 세션2 재실측(83행 → 1행)이 브라우저로 쟀다
/**
 * 회원 자동완성이 **지금 친 검색어의 목록만** 그리는지 잠근다 (T2-D1 · #599 3차 실측)
 *
 * @description
 * 관리자 회원 검색 자동완성은 글자마다 요청을 내고 응답의 도착 순서는 보장되지 않는다.
 * 목록에 「이건 어느 검색어의 결과인가」를 도장해 두고 지금 입력값과 견주는 가드가 이미
 * 있었는데, **그 도장을 `$event.target.value` 로 찍은 것이 결함이었다** — `<success>` 안의
 * 그 식은 요청을 만든 값이 아니라 **응답이 도착한 순간의 입력칸**을 읽는다. 그래서 한 글자가
 * 부른 가장 큰 응답(전체 회원)이 가장 늦게 와서 현재 검색어의 것으로 둔갑하고, 가드가 그대로
 * 통과해 엉뚱한 목록이 그려졌다.
 *
 * 2026-09-22 실측: 마일리지 「수동 지급/차감」 창에 `MEAS599T-S2` 를 쳤는데 요청은
 * `keyword=MEAS599T-S2` 로 나가 200·1건을 받았고, 드롭다운에는 **전체 회원 83행**이 떴다
 * (1행 `관리자 | <관리자 이메일>`). 그 목록에서 고르면 **엉뚱한 회원에게 돈이 지급된다.**
 * 쿠폰 「직접 발급」 창도 같은 코드였다(전수 검사로 찾았다) — 그쪽은 쿠폰이 잘못 나간다.
 *
 * 고침은 **응답이 자기 검색어를 싣고 오게** 한 것이다(`meta.keyword` — 서버측 계약은
 * `tests/Feature/Api/Admin/UserSearchEchoesKeywordTest.php` 가 잠근다). 화면은 그 값과 지금
 * 입력값을 견주기만 한다.
 *
 * 두 창을 한 파일에서 재는 것은 **같은 결함의 쌍둥이**이기 때문이다 — 한쪽만 고치면 다른 쪽이
 * 조용히 남는다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import manualTransactionModal from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_modal_manual_transaction.json';
import directIssueModal from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_direct_issue.json';

/**
 * 레이아웃 노드를 그대로 문자열로 만든다.
 *
 * @param node 레이아웃 JSON 노드
 * @returns 직렬화 결과
 */
function jsonText(node: unknown): string {
    return JSON.stringify(node);
}

describe('[case:state-global-autocomplete-stale-response-keyword] 회원 자동완성 — 결과에 찍는 검색어는 응답이 실어 온 값이다 (T2-D1)', () => {
    /**
     * @scenario surface=mileage_manual_transaction
     * @effects member_search_stamps_the_keyword_the_response_carried
     */
    it('마일리지 수동 지급 창이 response.meta.keyword 로 도장한다', () => {
        const text = jsonText(manualTransactionModal);

        expect(text).toContain(
            `"mileageManual.memberSearchResultsKeyword":"{{response?.meta?.keyword ?? ''}}"`,
        );
        // 되돌아가면 red — 이 식이 결함 그 자체였다.
        expect(text).not.toContain(
            '"mileageManual.memberSearchResultsKeyword":"{{$event.target.value}}"',
        );
    });

    /**
     * @scenario surface=coupon_direct_issue
     * @effects member_search_stamps_the_keyword_the_response_carried
     */
    it('쿠폰 직접 발급 창도 같은 값으로 도장한다 (쌍둥이)', () => {
        const text = jsonText(directIssueModal);

        expect(text).toContain(
            `"directIssueSearchResultsKeyword":"{{response?.meta?.keyword ?? ''}}"`,
        );
        expect(text).not.toContain(
            '"directIssueSearchResultsKeyword":"{{$event.target.value}}"',
        );
    });

    /**
     * 도장만으로는 뜻이 없다 — **그 값과 지금 입력값을 견주는 가드**가 있어야 목록이 걸러진다.
     *
     * @scenario surface=mileage_manual_transaction
     * @effects member_search_list_renders_only_for_the_current_keyword
     */
    it('마일리지 창의 목록이 검색어 일치 가드를 지나야 그려진다', () => {
        const text = jsonText(manualTransactionModal);

        expect(text).toContain('memberSearchResultsKeyword ?? null) === (_global.mileageManual?.memberSearchKeyword');
    });

    /**
     * @scenario surface=coupon_direct_issue
     * @effects member_search_list_renders_only_for_the_current_keyword
     */
    it('쿠폰 창의 목록도 검색어 일치 가드를 지나야 그려진다', () => {
        const text = jsonText(directIssueModal);

        expect(text).toContain('directIssueSearchResultsKeyword');
        expect(text).toContain('directIssueSearchKeyword');
    });

    /**
     * 요청 본문의 `$event.target.value` 는 **옳다** — 그때는 사용자가 방금 친 값이다.
     * 고칠 자리는 `<success>` 안뿐이었음을 함께 잠근다(요청까지 바꾸면 검색이 멈춘다).
     *
     * @scenario surface=mileage_manual_transaction
     * @effects member_search_request_still_sends_the_typed_keyword
     */
    it('요청은 방금 친 값을 그대로 보낸다 (두 창 모두)', () => {
        expect(jsonText(manualTransactionModal)).toContain('"keyword":"{{$event.target.value}}"');
        expect(jsonText(directIssueModal)).toContain('"keyword":"{{$event.target.value}}"');
    });
});
