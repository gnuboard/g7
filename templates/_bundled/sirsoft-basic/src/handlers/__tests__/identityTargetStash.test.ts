/**
 * @file identityTargetStash.test.ts
 * @description 전체화면 본인인증의 비회원 인증 대상 되살리기 (KVE 후속)
 *
 * 모달을 열지 못해 전체화면으로 넘어가면 페이지를 새로 읽어 전역 상태(`_global.identityChallenge.target`)가 사라진다.
 * 비회원은 로그인 세션에서 대상을 얻을 수 없어 [코드 재전송] 이 대상 없이 나가 422 로 거절됐다. launcher 가 넘어가기
 * 전에 해석한 대상을 세션 보관함(코어 `g7.identity.redirectStash`)에 함께 넣고, 전체화면이 진입 때 그 값을 되살린다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { restoreIdentityTargetHandler } from '../identityLauncher';

const STASH_KEY = 'g7.identity.redirectStash';

let local: Record<string, any> = {};

/**
 * 세션 보관함에 코어 stash 모양으로 값을 넣습니다.
 *
 * @param payload stash 의 payload
 * @param stashedAt 보관 시각(ms)
 * @return 반환값 없음
 */
function stash(payload: Record<string, unknown>, stashedAt: number = Date.now()): void {
  window.sessionStorage.setItem(STASH_KEY, JSON.stringify({ return_url: '/shop/checkout', payload, stashed_at: stashedAt }));
}

beforeEach(() => {
  local = {};
  window.sessionStorage.clear();
  (window as any).G7Core = {
    identity: { redirectStashKey: STASH_KEY },
    state: {
      getLocal: () => local,
      setLocal: vi.fn((updates: Record<string, any>) => {
        local = { ...local, ...updates };
      }),
    },
  };
});

describe('restoreIdentityTargetHandler', () => {
  /**
   * @scenario surface=fullpage_guest_target
   * @effects fullpage_resend_restores_guest_target
   */
  it('보관된 대상의 이메일·전화번호만 화면 상태 identity_target 에 되살린다', () => {
    stash({ purpose: 'signup', target: { email: 'guest@example.test', phone: '01012345678', extra: 'x' } });

    const restored = restoreIdentityTargetHandler();

    expect(restored).toEqual({ email: 'guest@example.test', phone: '01012345678' });
    expect(local.identity_target).toEqual({ email: 'guest@example.test', phone: '01012345678' });
  });

  /**
   * 오래된 보관값(다른 흐름의 잔재)은 쓰지 않는다 — 같은 탭에서 한참 전에 시작한 다른 인증의 대상이 섞이지 않게.
   *
   * @scenario surface=fullpage_guest_target
   * @effects fullpage_resend_restores_guest_target
   */
  it('30분이 지난 보관값은 되살리지 않는다', () => {
    stash({ purpose: 'signup', target: { email: 'old@example.test' } }, Date.now() - 31 * 60 * 1000);

    expect(restoreIdentityTargetHandler()).toBeNull();
    expect(local.identity_target).toBeUndefined();
  });

  /**
   * @scenario surface=fullpage_guest_target
   * @effects fullpage_resend_restores_guest_target
   */
  it('보관값이 없거나 깨졌거나 대상이 비면 아무것도 바꾸지 않는다', () => {
    expect(restoreIdentityTargetHandler()).toBeNull();

    window.sessionStorage.setItem(STASH_KEY, '{not json');
    expect(restoreIdentityTargetHandler()).toBeNull();

    stash({ purpose: 'signup', target: null });
    expect(restoreIdentityTargetHandler()).toBeNull();

    stash({ purpose: 'signup', target: { email: '' } });
    expect(restoreIdentityTargetHandler()).toBeNull();

    expect(local.identity_target).toBeUndefined();
  });
});
