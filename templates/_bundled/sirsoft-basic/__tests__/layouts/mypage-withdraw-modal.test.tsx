/**
 * @file mypage-withdraw-modal.test.tsx
 * @description 마이페이지 탈퇴 확인 모달 회귀 테스트
 *
 * 테스트 대상:
 * - templates/.../partials/mypage/profile/_modal_withdraw.json
 *
 * 회귀 차단 항목:
 * - DELETE /api/me onSuccess 체인이 logout 핸들러로 일원화되어 있는지
 *   (이전 회귀들의 통합 해결책):
 *     - params.url 키 회귀 — navigate 자체 제거로 무효화
 *     - navigate 직후 홈 페이지가 401 응답을 받아 /login?reason=session_expired
 *       로 강제 리다이렉트되는 회귀 — logout 이 토큰+state+redirect 를 원자적으로 처리
 * - logout 핸들러가 sequence 의 마지막 액션이어서 closeModal/toast 후 발화
 * - 토큰 + 글로벌 state 정리를 별도 setState 가 아닌 logout 에 위임 (단일 책임)
 * - onError 체인은 그대로 (isWithdrawing 복구 + 에러 토스트)
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const layoutPath = path.resolve(
  __dirname,
  '../../layouts/partials/mypage/profile/_modal_withdraw.json'
);

interface Action {
  handler: string;
  params?: Record<string, unknown>;
  target?: string;
  onSuccess?: Action[];
  onError?: Action[];
  actions?: Action[];
}

function loadLayout(): any {
  const raw = fs.readFileSync(layoutPath, 'utf8');
  return JSON.parse(raw);
}

function findApiCall(actions: Action[]): Action | undefined {
  for (const action of actions) {
    if (action.handler === 'apiCall') return action;
    if (action.actions) {
      const nested = findApiCall(action.actions);
      if (nested) return nested;
    }
  }
  return undefined;
}

function findConfirmButton(node: any): any | undefined {
  if (!node) return undefined;
  if (
    node.name === 'Button' &&
    Array.isArray(node.actions) &&
    node.actions.some((a: Action) => a.handler === 'sequence')
  ) {
    return node;
  }
  if (Array.isArray(node.children)) {
    for (const child of node.children) {
      const found = findConfirmButton(child);
      if (found) return found;
    }
  }
  return undefined;
}

describe('_modal_withdraw.json (마이페이지 탈퇴 모달)', () => {
  const layout = loadLayout();

  it('Modal 타입의 partial 레이아웃이다', () => {
    expect(layout.meta?.is_partial).toBe(true);
    expect(layout.type).toBe('composite');
    expect(layout.name).toBe('Modal');
  });

  it('탈퇴 확인 버튼이 sequence 액션을 가진다', () => {
    const btn = findConfirmButton(layout);
    expect(btn).toBeDefined();
    expect(btn.actions[0].handler).toBe('sequence');
  });

  it('sequence 안에 DELETE /api/me apiCall 이 정의되어 있다', () => {
    const btn = findConfirmButton(layout);
    const apiCall = findApiCall(btn.actions);
    expect(apiCall).toBeDefined();
    expect(apiCall!.target).toBe('/api/me');
    expect((apiCall!.params as any)?.method).toBe('DELETE');
  });

  it('onSuccess 체인에 logout 핸들러가 포함된다 (토큰+state+redirect 원자적 처리)', () => {
    const btn = findConfirmButton(layout);
    const apiCall = findApiCall(btn.actions);
    const logoutAction = apiCall!.onSuccess!.find((a) => a.handler === 'logout');

    expect(logoutAction).toBeDefined();
  });

  it('onSuccess 체인에 navigate / setState global currentUser 가 직접 포함되지 않는다 (logout 위임 회귀 차단)', () => {
    const btn = findConfirmButton(layout);
    const apiCall = findApiCall(btn.actions);
    const chain = apiCall!.onSuccess!;

    // navigate 는 logout 이 내부 처리 — 모달에서 직접 호출 금지
    const directNavigate = chain.find((a) => a.handler === 'navigate');
    expect(directNavigate).toBeUndefined();

    // currentUser=null setState 도 logout 이 내부 처리 — 모달에서 중복 호출 금지
    const directClearUser = chain.find(
      (a) =>
        a.handler === 'setState' &&
        (a.params as any)?.target === 'global' &&
        (a.params as any)?.currentUser === null
    );
    expect(directClearUser).toBeUndefined();
  });

  it('onSuccess 체인이 closeModal → toast → logout 순서를 따른다', () => {
    const btn = findConfirmButton(layout);
    const apiCall = findApiCall(btn.actions);
    const handlers = apiCall!.onSuccess!.map((a) => a.handler);

    const closeModalIdx = handlers.indexOf('closeModal');
    const toastIdx = handlers.indexOf('toast');
    const logoutIdx = handlers.indexOf('logout');

    expect(closeModalIdx).toBeGreaterThanOrEqual(0);
    expect(toastIdx).toBeGreaterThan(closeModalIdx);
    expect(logoutIdx).toBeGreaterThan(toastIdx);
  });

  it('onError 체인에 isWithdrawing=false 복구 + 에러 토스트가 포함된다', () => {
    const btn = findConfirmButton(layout);
    const apiCall = findApiCall(btn.actions);
    const errorChain = apiCall!.onError!;

    const resetState = errorChain.find(
      (a) =>
        a.handler === 'setState' &&
        (a.params as any)?.target === 'local' &&
        (a.params as any)?.isWithdrawing === false
    );
    const errorToast = errorChain.find(
      (a) => a.handler === 'toast' && (a.params as any)?.type === 'error'
    );

    expect(resetState).toBeDefined();
    expect(errorToast).toBeDefined();
  });
});
