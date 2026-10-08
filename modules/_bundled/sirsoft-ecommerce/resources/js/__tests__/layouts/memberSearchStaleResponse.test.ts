/**
 * @file memberSearchStaleResponse.test.ts
 * @description 관리자 회원 검색(쿠폰 직접 발급·마일리지 수동 지급)의 늦게 온 옛 응답 회귀
 *
 * 입력할 때마다 회원 검색을 부르므로, 첫 글자 하나가 부른 큰 응답(전체 회원)이 가장 늦게 도착할 수 있다.
 * 결과를 응답 키워드와 함께 저장하고 「저장된 키워드 = 지금 입력」일 때만 목록을 그리면 엉뚱한 목록은
 * 막지만, 옛 응답이 정답 결과를 **덮어써** 버려 이미 보이던 정답 목록이 사라지고 다시 오지 않는다
 * (2026-10-07 실측: 「e130.s5.m3」 입력 뒤 「e」 응답이 4.9초에 도착 → 표시 true → false 로 고정).
 * 응답 키워드가 지금 검색어와 같을 때만 새 결과를 담고 아니면 지금 값을 다시 넣는다. 액션 `if` 와 `$event` 는
 * 요청 시점 문맥이라 옛 응답도 통과하므로(2026-10-07 재실측), 평가 시점 최신 상태인 값 식의 `_global` 로 견준다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const PARTIALS = path.resolve(__dirname, '../../../layouts/admin/partials');

const TARGETS = [
    {
        file: path.join(PARTIALS, 'admin_ecommerce_promotion_coupon_list/_modal_direct_issue.json'),
        resultKey: 'directIssueSearchResults',
    },
    {
        file: path.join(PARTIALS, 'admin_ecommerce_mileage_transaction_index/_modal_manual_transaction.json'),
        resultKey: 'mileageManual.memberSearchResults',
    },
];

/**
 * 트리를 순회하며 조건에 맞는 노드를 모읍니다.
 *
 * @param node 시작 노드
 * @param predicate 수집 조건
 * @return 일치 노드 목록
 */
function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
    const found: any[] = [];
    const walk = (current: any): void => {
        if (Array.isArray(current)) {
            current.forEach(walk);

            return;
        }
        if (current && typeof current === 'object') {
            if (predicate(current)) {
                found.push(current);
            }
            Object.values(current).forEach(walk);
        }
    };
    walk(node);

    return found;
}

describe.each(TARGETS)('회원 검색 결과 저장 — $resultKey', ({ file, resultKey }) => {
    const layout = JSON.parse(fs.readFileSync(file, 'utf8'));
    const stores = collectNodes(
        layout,
        (n) => n?.handler === 'setState' && n?.params && Object.prototype.hasOwnProperty.call(n.params, resultKey)
            && typeof n.params[resultKey] === 'string' && n.params[resultKey].includes('response')
    );

    /**
     * @scenario case=c01
     * @effects stale_member_search_response_ignored
     */
    it('응답으로 결과를 저장하는 setState 가 있다', () => {
        expect(stores.length).toBe(1);
    });

    /**
     * @scenario case=c02
     * @effects stale_member_search_response_ignored
     */
    it('응답 키워드가 지금 검색어(값 식의 최신 _global)와 같을 때만 새 결과를, 아니면 지금 값을 넣는다', () => {
        const value = String(stores[0].params[resultKey]);
        expect(value).toContain('response?.meta?.keyword');
        expect(value).toContain('_global.');
        // 옛 응답이면 지금 값(_global …)을 그대로 다시 넣는다
        expect(value).toMatch(/\?[\s\S]*:\s*\(_global\./);
        // 액션 if·$event 는 요청 시점 문맥이라 옛 응답을 막지 못한다
        expect(String(stores[0].if ?? '')).not.toContain('$event');
    });
});
