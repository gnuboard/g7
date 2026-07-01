/**
 * ActionDispatcher — IDV 가드 토스트 중복 억제 테스트.
 *
 * 본인확인은 성공했으나 부가 목적(성인인증 등)을 충족하지 못해 challenge 가 실패하면,
 * provider 가 "성인 인증이 필요합니다" 같은 고유 사유를 이미 토스트로 표출한다. 그 직후
 * 원 요청의 onError 가 generic IDV 가드 토스트("본인 확인이 필요합니다")를 중복 발화하는데,
 * provider 가 markDomainNoticeShown() 신호를 남긴 경우 코어 handleToast 가 그 1건을 skip 한다.
 *
 * 일반 본인인증 실패(본인확인 자체 실패/취소)는 신호가 없어 가드 토스트가 그대로 유지된다.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { IdentityGuardInterceptor } from '../../identity/IdentityGuardInterceptor';

describe('ActionDispatcher — IDV 가드 토스트 중복 억제', () => {
  let dispatcher: ActionDispatcher;
  let toasts: any[];

  beforeEach(() => {
    dispatcher = new ActionDispatcher();
    IdentityGuardInterceptor.reset();
    toasts = [];

    // globalStateUpdater 가 toasts 배열을 누적하도록 모킹
    dispatcher.setGlobalStateUpdater((updates: any) => {
      if (updates.toasts) {
        toasts = updates.toasts;
      }
    });

    // handleToast 가 현재 toasts 를 읽기 위해 사용하는 G7Core.state.get() 모킹
    (window as any).G7Core = {
      state: { get: () => ({ toasts }) },
    };
  });

  afterEach(() => {
    IdentityGuardInterceptor.reset();
    delete (window as any).G7Core;
    vi.restoreAllMocks();
  });

  /** IDV 가드 응답으로 인한 onError 토스트 컨텍스트를 모사 */
  const identityGuardErrorContext = {
    data: {
      error: {
        status: 428,
        message: '본인 확인이 필요합니다.',
        error_code: 'identity_verification_required',
        data: { error_code: 'identity_verification_required' },
      },
    },
  };

  it('도메인 안내 신호가 있으면 IDV 가드 error 토스트를 skip', async () => {
    // provider 가 고유 사유(성인인증 실패)를 표출했음을 신호
    IdentityGuardInterceptor.markDomainNoticeShown();

    await dispatcher.dispatchAction(
      { handler: 'toast', params: { type: 'error', message: '본인 확인이 필요합니다.' } },
      identityGuardErrorContext as any,
    );

    // 가드 토스트는 skip 되어 추가되지 않음
    expect(toasts).toHaveLength(0);
    // 신호는 소비됨
    expect(IdentityGuardInterceptor.consumeDomainNoticeShown()).toBe(false);
  });

  it('신호가 없으면 IDV 가드 error 토스트를 그대로 표출 (일반 본인인증 실패)', async () => {
    // markDomainNoticeShown 미호출 (일반 본인인증 실패류)

    await dispatcher.dispatchAction(
      { handler: 'toast', params: { type: 'error', message: '본인 확인이 필요합니다.' } },
      identityGuardErrorContext as any,
    );

    // 가드 토스트가 그대로 추가됨
    expect(toasts).toHaveLength(1);
    expect(toasts[0].message).toBe('본인 확인이 필요합니다.');
  });

  it('신호가 있어도 IDV 가드가 아닌 error 토스트는 skip 하지 않음', async () => {
    IdentityGuardInterceptor.markDomainNoticeShown();

    // error_code 가 IDV 가드가 아닌 일반 에러 토스트
    await dispatcher.dispatchAction(
      {
        handler: 'toast',
        params: { type: 'error', message: '서버 오류가 발생했습니다.' },
      },
      { data: { error: { status: 500, error_code: 'server_error' } } } as any,
    );

    // 가드가 아니므로 표출됨 + 신호는 소비되지 않아 다음 가드 토스트용으로 남음
    expect(toasts).toHaveLength(1);
    expect(toasts[0].message).toBe('서버 오류가 발생했습니다.');
    expect(IdentityGuardInterceptor.consumeDomainNoticeShown()).toBe(true);
  });

  it('신호가 있어도 success 토스트는 skip 하지 않음 (error 타입만 대상)', async () => {
    IdentityGuardInterceptor.markDomainNoticeShown();

    await dispatcher.dispatchAction(
      {
        handler: 'toast',
        params: { type: 'success', message: '저장되었습니다.' },
      },
      identityGuardErrorContext as any,
    );

    expect(toasts).toHaveLength(1);
    expect(toasts[0].message).toBe('저장되었습니다.');
  });

  it('skip 은 1회만 — 신호 소비 후 두 번째 가드 토스트는 표출', async () => {
    IdentityGuardInterceptor.markDomainNoticeShown();

    // 첫 번째 가드 토스트 → skip
    await dispatcher.dispatchAction(
      { handler: 'toast', params: { type: 'error', message: '본인 확인이 필요합니다.' } },
      identityGuardErrorContext as any,
    );
    expect(toasts).toHaveLength(0);

    // 두 번째 가드 토스트 → 신호 소비됨, 표출
    await dispatcher.dispatchAction(
      { handler: 'toast', params: { type: 'error', message: '본인 확인이 필요합니다.' } },
      identityGuardErrorContext as any,
    );
    expect(toasts).toHaveLength(1);
  });
});
