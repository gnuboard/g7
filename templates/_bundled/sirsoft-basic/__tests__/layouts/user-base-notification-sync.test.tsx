/**
 * @file user-base-notification-sync.test.tsx
 * @description 사용자 알림 레이어 동기화 회귀 테스트
 *
 * 회귀 방지 대상:
 * 1. onNotificationClose(read-batch) 이후 notifications 데이터소스가 refetch되어야 함
 *    → 이전: notification_unread_count만 refetch되어 다음 토글 시 marked_count: 0
 * 2. onNotificationLoadMore 무한 스크롤 요청에 read 파라미터가 포함되어야 함
 *    → 이전: page/per_page만 포함되어 읽은 알림이 섞여서 로드됨
 *
 * 참고: gnuboard/g7#14 (공개 제보)
 */

import { describe, it, expect } from 'vitest';

import userBase from '../../layouts/_user_base.json';

type ActionNode = {
  event?: string;
  handler?: string;
  params?: Record<string, any>;
  onSuccess?: ActionNode[];
  actions?: ActionNode[];
  target?: string;
};

type LayoutNode = {
  actions?: ActionNode[];
  children?: LayoutNode[];
  slots?: Record<string, LayoutNode[]>;
  [key: string]: unknown;
};

function walk(input: LayoutNode | LayoutNode[] | undefined, visit: (node: LayoutNode) => void) {
  if (!input) return;
  const nodes = Array.isArray(input) ? input : [input];
  for (const node of nodes) {
    visit(node);
    if (node.slots) {
      for (const key of Object.keys(node.slots)) {
        walk(node.slots[key], visit);
      }
    }
    if (node.children) {
      walk(node.children, visit);
    }
  }
}

function collectActionsByEvent(layout: any, eventName: string): ActionNode[] {
  const roots = Array.isArray(layout.components) ? layout.components : [layout];
  const matched: ActionNode[] = [];
  walk(roots, (node) => {
    if (!Array.isArray(node.actions)) return;
    for (const action of node.actions) {
      if (action && action.event === eventName) {
        matched.push(action);
      }
    }
  });
  return matched;
}

function flattenSequenceActions(action: ActionNode): ActionNode[] {
  if (action.handler === 'sequence' && Array.isArray(action.params?.actions)) {
    return action.params!.actions as ActionNode[];
  }
  return [action];
}

describe('사용자 알림 레이어 동기화 (gnuboard/g7#14)', () => {
  describe('Bug 1: onNotificationClose 후 notifications 데이터소스 refetch', () => {
    const closeActions = collectActionsByEvent(userBase, 'onNotificationClose');

    it('onNotificationClose 핸들러가 존재해야 한다', () => {
      expect(closeActions.length).toBeGreaterThanOrEqual(1);
    });

    it('read-batch apiCall을 포함해야 한다', () => {
      const steps = flattenSequenceActions(closeActions[0]);
      const apiCall = steps.find(
        (s) => s.handler === 'apiCall' && typeof s.target === 'string' && s.target.endsWith('/notifications/read-batch'),
      );
      expect(apiCall).toBeDefined();
    });

    it('read-batch onSuccess에 notifications + notification_unread_count refetch가 포함되어야 한다', () => {
      const steps = flattenSequenceActions(closeActions[0]);
      const apiCall = steps.find(
        (s) => s.handler === 'apiCall' && typeof s.target === 'string' && s.target.endsWith('/notifications/read-batch'),
      )!;
      const successSteps = (apiCall.onSuccess ?? []) as ActionNode[];
      const refetchIds = successSteps
        .filter((s) => s.handler === 'refetchDataSource')
        .map((s) => s.params?.dataSourceId);
      expect(refetchIds).toContain('notifications');
      expect(refetchIds).toContain('notification_unread_count');
    });

    it('onSuccess에 페이지네이션 리셋 setState가 포함되어야 한다', () => {
      const steps = flattenSequenceActions(closeActions[0]);
      const apiCall = steps.find(
        (s) => s.handler === 'apiCall' && typeof s.target === 'string' && s.target.endsWith('/notifications/read-batch'),
      )!;
      const successSteps = (apiCall.onSuccess ?? []) as ActionNode[];
      const setState = successSteps.find((s) => s.handler === 'setState');
      expect(setState).toBeDefined();
      expect(setState!.params?.target).toBe('global');
      expect(setState!.params?.notificationsCurrentPage).toBe(1);
      expect(setState!.params?.notificationsHasMore).toBeNull();
    });
  });

  describe('Bug 2: onNotificationLoadMore query에 read 파라미터 유지', () => {
    const loadMoreActions = collectActionsByEvent(userBase, 'onNotificationLoadMore');

    it('onNotificationLoadMore 핸들러가 존재해야 한다', () => {
      expect(loadMoreActions.length).toBeGreaterThanOrEqual(1);
    });

    it('apiCall query에 read 파라미터가 포함되어야 한다', () => {
      const steps = flattenSequenceActions(loadMoreActions[0]);
      const apiCall = steps.find(
        (s) => s.handler === 'apiCall' && typeof s.target === 'string' && s.target.endsWith('/user/notifications'),
      );
      expect(apiCall).toBeDefined();
      const query = apiCall!.params?.query ?? {};
      expect(query).toHaveProperty('page');
      expect(query).toHaveProperty('per_page');
      expect(query).toHaveProperty('read');
      expect(String(query.read)).toContain('notificationsUnreadOnly');
    });
  });
});
