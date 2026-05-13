/**
 * @file admin-identity-challenge-modal.test.tsx
 * @description Admin 본인인증 모달의 cancel 흐름 audit trail 정합 회귀 테스트
 *
 * 검증 대상:
 * 1. 취소 버튼 sequence 가 [apiCall(cancel), resolveIdentityChallenge(cancelled), closeModal] 순서
 * 2. cancel apiCall 의 target/method 정합 (apiCall 표준: target 은 top-level, params 는 method/body 만)
 * 3. cancel apiCall 이 challenge_id 가용성에 따라 조건부 호출 (if 가드)
 * 4. cancel apiCall 실패해도 모달 닫기는 진행 (onError suppress)
 *
 * @since 7.0.0-beta.4
 */

import { describe, expect, it } from 'vitest';

import identityModal from '../../layouts/partials/_identity_challenge_modal.json';

type Action = {
  event?: string;
  type?: string;
  handler?: string;
  if?: string;
  target?: string;
  params?: Record<string, any>;
  onSuccess?: Action[];
  onError?: Action[];
  actions?: Action[];
};

type Node = {
  id?: string;
  type?: string;
  name?: string;
  props?: Record<string, any>;
  children?: Node[] | string;
  events?: Record<string, { actions?: Action[] }>;
  actions?: Action[];
  default?: Node[];
  slots?: Record<string, Node[]>;
};

function walk(input: Node | Node[] | undefined, visit: (node: Node) => void): void {
  if (!input) return;
  const nodes = Array.isArray(input) ? input : [input];
  for (const node of nodes) {
    visit(node);
    if (node.default) walk(node.default, visit);
    if (node.children && Array.isArray(node.children)) walk(node.children as Node[], visit);
    if (node.slots) {
      for (const key of Object.keys(node.slots)) walk(node.slots[key], visit);
    }
  }
}

function collectActions(node: Node): Action[] {
  const out: Action[] = [];
  walk(node, (n) => {
    if (Array.isArray(n.actions)) out.push(...n.actions);
    if (n.events) {
      for (const ev of Object.values(n.events)) {
        if (Array.isArray(ev.actions)) out.push(...ev.actions);
      }
    }
  });
  return out;
}

function deepFindActions(actions: Action[], predicate: (a: Action) => boolean): Action[] {
  const result: Action[] = [];
  const visit = (list: Action[] | undefined) => {
    if (!list) return;
    for (const a of list) {
      if (predicate(a)) result.push(a);
      if (a.handler === 'sequence' && Array.isArray(a.params?.actions)) {
        visit(a.params!.actions as Action[]);
      }
      if (Array.isArray(a.actions)) visit(a.actions);
      if (Array.isArray(a.onSuccess)) visit(a.onSuccess);
      if (Array.isArray(a.onError)) visit(a.onError);
    }
  };
  visit(actions);
  return result;
}

describe('Admin 본인인증 모달 cancel 흐름 (sirsoft-admin_basic)', () => {
  const allActions = collectActions(identityModal as unknown as Node);

  function findCancelSequence(): Action | undefined {
    const sequences = deepFindActions(allActions, (a) => a.handler === 'sequence');
    return sequences.find((seq) => {
      const inner = (seq.params?.actions ?? []) as Action[];
      return inner.some(
        (a) => a.handler === 'resolveIdentityChallenge' && a.params?.result === 'cancelled',
      );
    });
  }

  it('cancel sequence 가 존재한다', () => {
    expect(findCancelSequence()).toBeDefined();
  });

  it('cancel sequence 의 첫 액션이 서버 cancel API 호출 — audit trail 정합', () => {
    const seq = findCancelSequence();
    const inner = (seq!.params!.actions ?? []) as Action[];
    const apiCall = inner.find((a) => a.handler === 'apiCall');
    expect(apiCall).toBeDefined();
    // apiCall 표준: target 은 액션 top-level, params 안은 method/body 만
    expect(apiCall!.params?.method).toBe('POST');
    expect(typeof apiCall!.target).toBe('string');
    expect(apiCall!.target!).toContain('/api/identity/challenges/');
    expect(apiCall!.target!).toContain('/cancel');
    expect(apiCall!.target!).toContain('_global.identityChallenge.challenge_id');
  });

  it('cancel apiCall 이 challenge 발급된 경우에만 호출 (if 가드)', () => {
    const seq = findCancelSequence();
    const inner = (seq!.params!.actions ?? []) as Action[];
    const apiCall = inner.find((a) => a.handler === 'apiCall');
    expect(apiCall!.if).toContain('_global.identityChallenge?.challenge_id');
  });

  it('cancel apiCall 실패해도 모달 닫기는 진행 (onError suppress)', () => {
    const seq = findCancelSequence();
    const inner = (seq!.params!.actions ?? []) as Action[];
    const apiCall = inner.find((a) => a.handler === 'apiCall');
    const onError = apiCall!.onError ?? [];
    expect(onError.length).toBeGreaterThanOrEqual(1);
    expect(onError[0].handler).toBe('suppress');
  });

  it('cancel sequence 가 [apiCall, resolveIdentityChallenge, closeModal] 순서', () => {
    const seq = findCancelSequence();
    const inner = (seq!.params!.actions ?? []) as Action[];
    const handlers = inner.map((a) => a.handler);
    expect(handlers).toEqual(['apiCall', 'resolveIdentityChallenge', 'closeModal']);
  });
});
