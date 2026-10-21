// e2e:allow 레이아웃 정적 구조(onNotificationRead 액션 모양) 단언 전용 — 새 탭으로 연 알림의 읽음 처리 동작은 NavigationLinks.menus.test.tsx(컴포넌트)와 M1 알림 실측이 덮는다(시나리오 navigation-links.yaml).
/**
 * @file admin-base-notification-new-tab-read.test.ts
 * @description 새 탭으로 연 링크 알림의 읽음 처리 (#757 링크 단계)
 *
 * 알림 본문이 `<a href>` 가 되면서 Ctrl·가운데 클릭이나 바깥 주소 알림은 브라우저가 새 탭에서 열고, 이동 콜백
 * (onNotificationClick — 이동 + 읽음)은 불리지 않는다. 그래서 새 탭으로 연 알림이 계속 안 읽음으로 남았다.
 * 컴포넌트는 그때 onNotificationRead 를 부르고, 관리자 기본 레이아웃은 그 이벤트에서 이동 없이 읽음 API 만 부른다.
 * 데스크톱·모바일 헤더 양쪽에 같은 구조가 있어야 한다.
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 */

import { describe, it, expect } from 'vitest';

import adminBase from '../../layouts/_admin_base.json';

/**
 * 레이아웃 트리에서 이름이 같은 이벤트 액션을 모읍니다.
 *
 * @param input 레이아웃 노드(들)
 * @param eventName 이벤트 이름
 * @return 액션 목록
 */
function collectActionsByEvent(input: unknown, eventName: string): Array<Record<string, unknown>> {
  const out: Array<Record<string, unknown>> = [];
  const visit = (node: any): void => {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
      node.forEach(visit);
      return;
    }
    for (const action of Array.isArray(node.actions) ? node.actions : []) {
      if (action?.event === eventName) out.push(action);
    }
    visit(node.children);
    if (node.slots && typeof node.slots === 'object') Object.values(node.slots).forEach(visit);
    visit(node.components);
  };
  visit(input);

  return out;
}

describe('관리자 기본 레이아웃 — 새 탭으로 연 알림의 읽음 처리 (#757)', () => {
  const readActions = collectActionsByEvent(adminBase, 'onNotificationRead');

  // @scenario component=notification_center
  // @effects link_open_marks_notification_read
  it('데스크톱·모바일 알림 센터 양쪽에 onNotificationRead 가 있고, 읽음 권한일 때 그 알림의 읽음 API 만 부르며 이동하지 않는다', () => {
    expect(readActions).toHaveLength(2);
    for (const action of readActions) {
      const text = JSON.stringify(action);
      expect(text).toContain('/api/admin/notifications/{{$args[0].id}}/read');
      expect(text).toContain('PATCH');
      expect(text).toContain('can_update === true');
      expect(text).not.toContain('"navigate"');
    }
  });
});
