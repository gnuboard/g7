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

/**
 * 트리에서 주어진 키에 값을 쓰는 setState 의 그 값(식 문자열)을 모두 모은다.
 *
 * 도장 식은 한 줄 그대로가 아니라 「응답 검색어가 지금 검색어와 같을 때만 담는」 비교 식 안에
 * 들어가 있을 수 있다(이커머스 전수 실측 S5-D7 — 늦게 온 옛 응답이 정답 목록을 덮지 않게).
 * 그래서 문자열 전체 일치가 아니라 **그 키에 쓰는 값**을 꺼내 무엇을 읽는지 본다.
 *
 * @param node 레이아웃 JSON 노드
 * @param key setState params 키
 * @param acc 누적
 * @returns 값 목록
 */
function setStateValues(node: unknown, key: string, acc: string[] = []): string[] {
    if (Array.isArray(node)) {
        node.forEach((n) => setStateValues(n, key, acc));

        return acc;
    }
    if (node && typeof node === 'object') {
        const n = node as Record<string, any>;
        if (n.handler === 'setState' && n.params && typeof n.params[key] === 'string') {
            acc.push(n.params[key]);
        }
        Object.values(n).forEach((v) => setStateValues(v, key, acc));
    }

    return acc;
}

describe('[case:state-global-autocomplete-stale-response-keyword] 회원 자동완성 — 결과에 찍는 검색어는 응답이 실어 온 값이다 (T2-D1)', () => {
    /**
     * @scenario surface=mileage_manual_transaction
     * @effects member_search_stamps_the_keyword_the_response_carried
     */
    it('마일리지 수동 지급 창이 response.meta.keyword 로 도장한다', () => {
        const stamps = setStateValues(manualTransactionModal, 'mileageManual.memberSearchResultsKeyword');

        expect(stamps.length).toBeGreaterThan(0);
        expect(stamps.some((v) => v.includes('response?.meta?.keyword'))).toBe(true);
        // 되돌아가면 red — 도장을 입력칸에서 읽는 것이 결함 그 자체였다.
        expect(stamps.some((v) => v.includes('$event.target.value'))).toBe(false);
    });

    /**
     * @scenario surface=coupon_direct_issue
     * @effects member_search_stamps_the_keyword_the_response_carried
     */
    it('쿠폰 직접 발급 창도 같은 값으로 도장한다 (쌍둥이)', () => {
        const stamps = setStateValues(directIssueModal, 'directIssueSearchResultsKeyword');

        expect(stamps.length).toBeGreaterThan(0);
        expect(stamps.some((v) => v.includes('response?.meta?.keyword'))).toBe(true);
        expect(stamps.some((v) => v.includes('$event.target.value'))).toBe(false);
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
