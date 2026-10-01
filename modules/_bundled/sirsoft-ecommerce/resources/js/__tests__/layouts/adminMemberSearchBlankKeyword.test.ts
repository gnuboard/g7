/**
 * @file adminMemberSearchBlankKeyword.test.ts
 * @description 이커머스 관리자 화면의 회원 검색이 빈 검색어로 요청하지 않는지 (#703)
 *
 * 코어 회원 검색 API(`/api/admin/users/search`)는 keyword 가 비면(공백만 있어도 요청 정리 뒤 비게 된다)
 * 422 로 거절한다. 이커머스에는 이 API 를 검색어로 부르는 자리가 다섯 곳 있다.
 *   - 환경설정 > 알림 템플릿 편집 창의 「특정 회원」 수신자 드롭다운 (검색칸을 지우면 빈 문자열로 onSearch)
 *   - 주문 목록 필터의 주문자 드롭다운 (같은 드롭다운)
 *   - 쿠폰 목록 필터의 만든 사람 드롭다운 (같은 드롭다운)
 *   - 마일리지 내역 > 수동 지급/차감 창의 회원 검색 입력칸 (공백만 적으면 길이 검사를 통과했다)
 *   - 쿠폰 목록 > 직접 발급 창의 회원 검색 입력칸 (같은 입력칸)
 * 드롭다운 세 곳은 조건이 아예 없어 검색칸을 지울 때마다 422 가 났고, 입력칸 두 곳은 길이만 봐서
 * 공백만 적으면 422 가 났다(직접 발급 창은 오류 토스트까지 떴다).
 */

import { describe, it, expect } from 'vitest';
import notificationTemplateEdit from '../../../layouts/admin/partials/admin_ecommerce_settings/_modal_notification_template_edit.json';
import orderFilter from '../../../layouts/admin/partials/admin_ecommerce_order_list/_partial_filter_section.json';
import couponFilter from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_partial_filter_section.json';
import manualTransactionModal from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_modal_manual_transaction.json';
import directIssueModal from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_direct_issue.json';

/** 회원 검색을 검색어로 부르는 화면 조각 — 이름은 실패 메시지에 쓴다 */
const SURFACES: Array<[string, unknown]> = [
  ['알림 템플릿 편집 창 — 특정 회원 수신자', notificationTemplateEdit],
  ['주문 목록 필터 — 주문자', orderFilter],
  ['쿠폰 목록 필터 — 만든 사람', couponFilter],
  ['마일리지 수동 지급/차감 창 — 회원 검색', manualTransactionModal],
  ['쿠폰 직접 발급 창 — 회원 검색', directIssueModal],
];

/** 객체 트리에서 조건을 만족하는 노드를 모두 모은다 */
function collect(node: unknown, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((v) => collect(v, predicate, acc));
    return acc;
  }
  if (node && typeof node === 'object') {
    if (predicate(node)) acc.push(node);
    Object.values(node).forEach((v) => collect(v, predicate, acc));
  }
  return acc;
}

/** 검색어로 회원 검색을 부르는 호출만 모은다(uuid 로 한 명을 되찾는 호출은 제외) */
function keywordSearchCalls(layout: unknown): any[] {
  return collect(
    layout,
    (n) => n.handler === 'apiCall'
      && String(n.target).includes('/api/admin/users/search')
      && n.params?.query?.keyword !== undefined,
  );
}

/**
 * `{{ ... }}` 표현식을 평가한다. 드롭다운은 검색어를 `$args[0]` 로, 입력칸은 `$event.target.value` 로
 * 넘기므로 두 자리에 같은 값을 넣는다.
 */
function evaluate(raw: string, term: unknown): unknown {
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function('$args', '$event', '_global', `return (${expr});`)(
    [term],
    { target: { value: term } },
    {},
  );
}

describe('이커머스 관리자 회원 검색 — 빈 검색어 요청 차단', () => {
  /**
   * 검색칸을 비우면(빈 문자열·값 없음) 다섯 곳 모두 회원 검색을 요청하지 않는다.
   *
   * @scenario search_term=empty
   *
   * @effects member_search_skips_empty_keyword
   */
  it('검색칸을 비우면 회원 검색을 요청하지 않는다', () => {
    for (const [name, layout] of SURFACES) {
      const calls = keywordSearchCalls(layout);
      expect(calls.length, `${name}: 회원 검색 호출`).toBeGreaterThan(0);
      for (const call of calls) {
        expect(call.if, `${name}: 빈 검색어 차단 조건이 없습니다 — 서버가 422 로 거절합니다.`).toBeDefined();
        expect(evaluate(call.if, ''), `${name}: 빈 문자열`).toBe(false);
        expect(evaluate(call.if, undefined), `${name}: 값 없음`).toBe(false);
      }
    }
  });

  /**
   * 공백만 적어도 요청하지 않는다 — 서버 요청 정리가 공백을 지워 빈 값이 되므로 길이만 보면 422 가 난다.
   *
   * @scenario search_term=blank
   *
   * @effects member_search_skips_empty_keyword
   */
  it('공백만 적어도 회원 검색을 요청하지 않는다', () => {
    for (const [name, layout] of SURFACES) {
      for (const call of keywordSearchCalls(layout)) {
        expect(call.if, name).toBeDefined();
        expect(evaluate(call.if, '   '), `${name}: 공백만`).toBe(false);
      }
    }
  });

  /**
   * 공백만 적으면 입력칸 두 곳이 이전 검색 결과를 남겨 두지 않는다 — 요청을 건너뛰는 만큼 옛 목록이
   * 방금 친 검색어와 맞지 않은 채 떠 있게 된다.
   *
   * @scenario search_term=blank
   *
   * @effects member_search_blank_keyword_hides_previous_results
   */
  it('공백만 적으면 입력칸의 이전 검색 결과를 비우거나 숨긴다', () => {
    // 직접 발급 창: 검색어가 비면 결과 목록을 비우는 동작이 공백에도 걸린다
    const clears = collect(
      directIssueModal,
      (n) => n.handler === 'setState' && Array.isArray(n.params?.directIssueSearchResults) && n.if,
    );
    expect(clears.length, '직접 발급 창: 결과 비우기').toBeGreaterThan(0);
    for (const clear of clears) {
      expect(evaluate(clear.if, '   ')).toBe(true);
      expect(evaluate(clear.if, '홍')).toBe(false);
    }

    // 수동 지급/차감 창: 결과 목록은 검색어가 공백뿐이면 보이지 않는다. 목록에는 응답이 실어 온 검색어가
    // 도장돼 있어야 그려지므로(adminMemberSearchKeywordGuard) 그 도장이 지금 검색어와 같은 상태로 잰다
    const [results] = collect(manualTransactionModal, (n) => n.id === 'member_search_results');
    expect(results?.if).toBeDefined();
    const shown = (keyword: string) => new Function('_global', `return (${String(results.if).replace(/^\{\{/, '').replace(/\}\}$/, '')});`)(
      { mileageManual: { memberSearchKeyword: keyword, memberSearchResultsKeyword: keyword.trim() } },
    );
    expect(shown('   ')).toBe(false);
    expect(shown('홍')).toBe(true);
  });

  /**
   * 검색어가 있으면 다섯 곳 모두 그 값을 keyword 로 보낸다(차단 조건이 정상 검색까지 막지 않는다).
   *
   * @scenario search_term=filled
   *
   * @effects member_search_sends_filled_keyword
   */
  it('검색어가 있으면 그 값을 keyword 로 보낸다', () => {
    for (const [name, layout] of SURFACES) {
      for (const call of keywordSearchCalls(layout)) {
        expect(call.if, name).toBeDefined();
        expect(evaluate(call.if, '홍'), `${name}: 검색어 있음`).toBe(true);
        expect(['{{$args[0]}}', '{{$event.target.value}}'], name).toContain(call.params.query.keyword);
      }
    }
  });
});
