/**
 * @file notificationRecipientSearch.test.tsx
 * @description 알림 템플릿 편집 창 — 수신자 회원 검색이 빈 검색어로 요청하지 않는지 (#703)
 *
 * 코어 회원 검색 API(`/api/admin/users/search`)는 keyword 가 비면(공백만 있어도 요청 정리 뒤 비게 된다)
 * 422 로 거절한다. 검색칸을 지우면 드롭다운이 빈 문자열로 onSearch 를 부르는데, 그대로 요청하면
 * 매번 422 가 나고 실패 처리로 결과 목록까지 비워졌다. 역할 검색(`/api/admin/roles`)은 search 가
 * 비어도 전체 목록을 주므로 대상이 아니다.
 */

import { describe, it, expect } from 'vitest';
import templateEditModal from '../../../layouts/admin/partials/plugin_settings/_modal_notification_template_edit.json';

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

describe('알림 수신자 회원 검색 — 빈 검색어 요청 차단', () => {
  /**
   * @scenario search_term=empty
   * @effects user_search_skips_empty_keyword
   */
  it('검색칸을 비우면(빈 문자열·값 없음) 회원 검색을 요청하지 않는다', () => {
    const calls = collect(templateEditModal, (n) => n.handler === 'apiCall' && String(n.target).includes('/api/admin/users/search'));
    expect(calls.length, '회원 검색 호출').toBeGreaterThan(0);
    for (const call of calls) {
      expect(call.if, '빈 검색어 차단 조건이 없습니다 — 서버가 422 로 거절합니다.').toBeDefined();
      expect(evaluate(call.if, { $args: [''] })).toBe(false);
      expect(evaluate(call.if, { $args: [undefined] })).toBe(false);
    }
  });

  /**
   * @scenario search_term=blank
   * @effects user_search_skips_empty_keyword
   */
  it('공백만 적어도 요청하지 않는다(서버 요청 정리가 공백을 지워 빈 값이 된다)', () => {
    const calls = collect(templateEditModal, (n) => n.handler === 'apiCall' && String(n.target).includes('/api/admin/users/search'));
    for (const call of calls) {
      expect(call.if).toBeDefined();
      expect(evaluate(call.if, { $args: ['   '] })).toBe(false);
    }
  });

  /**
   * @scenario search_term=filled
   * @effects user_search_sends_filled_keyword
   */
  it('검색어가 있으면 그 값을 keyword 로 보낸다', () => {
    const calls = collect(templateEditModal, (n) => n.handler === 'apiCall' && String(n.target).includes('/api/admin/users/search'));
    for (const call of calls) {
      expect(call.if).toBeDefined();
      expect(evaluate(call.if, { $args: ['홍'] })).toBe(true);
      expect(call.params?.query?.keyword).toBe('{{$args[0]}}');
    }
  });
});
