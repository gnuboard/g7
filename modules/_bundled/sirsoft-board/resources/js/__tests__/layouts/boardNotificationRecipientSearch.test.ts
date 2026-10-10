/**
 * @file boardNotificationRecipientSearch.test.ts
 * @description 게시판 환경설정 알림 템플릿 편집 창 — 수신자 회원 검색이 빈 검색어로 요청하지 않는지 (#703)
 *
 * 코어 회원 검색 API(`/api/admin/users/search`)는 keyword 가 비면(공백만 있어도 요청 정리 뒤 비게 된다)
 * 422 로 거절한다. 「특정 회원」 수신자 드롭다운은 검색칸을 지우면 빈 문자열로 onSearch 를 부르는데,
 * 조건 없이 요청해 매번 422 가 났고 실패 처리로 결과 목록까지 비워졌다. 역할 검색(`/api/admin/roles`)은
 * search 가 비어도 전체 목록을 돌려주므로 대상이 아니다.
 */

import { describe, it, expect } from 'vitest';
import templateEditModal from '../../../layouts/admin/partials/admin_board_settings/_modal_notification_template_edit.json';
import permissionsTab from '../../../layouts/admin/partials/admin_board_form/_tab_permissions.json';

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

/** `{{ ... }}` 표현식을 주어진 이름들로 평가한다 */
function evaluate(raw: string, scope: Record<string, unknown>): unknown {
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${expr});`)(...Object.values(scope));
}

/** 회원 검색 호출을 모은다 */
function userSearchCalls(): any[] {
  return collect(templateEditModal, (n) => n.handler === 'apiCall' && String(n.target).includes('/api/admin/users/search'));
}

describe('게시판 알림 수신자 회원 검색 — 빈 검색어 요청 차단', () => {
  /**
   * 검색칸을 비우면(빈 문자열·값 없음) 회원 검색을 요청하지 않는다.
   *
   * @scenario search_term=empty
   *
   * @effects user_search_skips_empty_keyword
   */
  it('검색칸을 비우면 회원 검색을 요청하지 않는다', () => {
    const calls = userSearchCalls();
    expect(calls.length, '회원 검색 호출').toBeGreaterThan(0);
    for (const call of calls) {
      expect(call.if, '빈 검색어 차단 조건이 없습니다 — 서버가 422 로 거절합니다.').toBeDefined();
      expect(evaluate(call.if, { $args: [''] })).toBe(false);
      expect(evaluate(call.if, { $args: [undefined] })).toBe(false);
    }
  });

  /**
   * 공백만 적어도 요청하지 않는다(서버 요청 정리가 공백을 지워 빈 값이 된다).
   *
   * @scenario search_term=blank
   *
   * @effects user_search_skips_empty_keyword
   */
  it('공백만 적어도 회원 검색을 요청하지 않는다', () => {
    for (const call of userSearchCalls()) {
      expect(call.if).toBeDefined();
      expect(evaluate(call.if, { $args: ['   '] })).toBe(false);
    }
  });

  /**
   * 검색어가 있으면 그 값을 keyword 로 보낸다(차단 조건이 정상 검색까지 막지 않는다).
   *
   * @scenario search_term=filled
   *
   * @effects user_search_sends_filled_keyword
   */
  it('검색어가 있으면 그 값을 keyword 로 보낸다', () => {
    for (const call of userSearchCalls()) {
      expect(call.if).toBeDefined();
      expect(evaluate(call.if, { $args: ['홍'] })).toBe(true);
      expect(call.params?.query?.keyword).toBe('{{$args[0]}}');
    }
  });
});

/** 게시판 폼 「권한」 탭의 운영자·단계 승인자 회원 검색 호출을 모은다 */
function permissionsMemberSearchCalls(): any[] {
  return collect(permissionsTab, (n) => n.handler === 'apiCall' && String(n.target) === '/api/admin/users');
}

describe('게시판 폼 권한 탭 운영자·단계 승인자 회원 검색 — 빈 검색어 요청 차단', () => {
  /**
   * 회원 목록 API(`/api/admin/users`)는 `filters.*.value` 가 비면 그 필터를 지우고 전체 회원을 돌려준다 —
   * 다른 회원 검색 칸과 같이 검색어 없는 조회는 보내지 않는다.
   * 태그 입력은 검색칸을 지우면 빈 문자열로 onInputChange 를 부르므로 두 칸 모두 차단 조건이 있어야 한다.
   *
   * @scenario search_term=empty
   *
   * @effects user_search_skips_empty_keyword
   */
  it('검색칸을 비우거나 공백만 적으면 회원 검색을 요청하지 않는다', () => {
    const calls = permissionsMemberSearchCalls();
    expect(calls.length, '운영자·단계 승인자 회원 검색 호출').toBe(2);
    for (const call of calls) {
      expect(call.if, '빈 검색어 차단 조건이 없습니다 — 검색어 없이 전체 회원을 조회합니다.').toBeDefined();
      for (const value of ['', '   ', undefined]) {
        expect(evaluate(call.if, { $event: { target: { value } } })).toBe(false);
      }
    }
  });

  /**
   * 검색어가 있으면 그 값을 필터 값으로 보낸다.
   *
   * @scenario search_term=filled
   *
   * @effects user_search_sends_filled_keyword
   */
  it('검색어가 있으면 그 값을 필터 값으로 보낸다', () => {
    for (const call of permissionsMemberSearchCalls()) {
      expect(call.if).toBeDefined();
      expect(evaluate(call.if, { $event: { target: { value: '홍' } } })).toBe(true);
      expect(call.params?.body?.['filters[0][value]']).toBe('{{$event.target.value}}');
    }
  });
});
