/**
 * ActionDispatcher.resolveIdentityChallenge 핸들러 테스트.
 *
 * 모달/풀페이지/외부 SDK callback 이 launcher Promise 에 결과를 통보하는 4-상태(`verified
 * | pending | cancelled | failed`) 분기를 검증합니다.
 *
 * 핸들러는 `IdentityGuardInterceptor` 의 deferred resolver 1회 호출만 수행하므로
 * setState/렌더 사이클/폼 자동바인딩과 무관 — 단위 시뮬레이션으로 충분히 격리 가능.
 *
 * @since engine-v1.46.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { IdentityGuardInterceptor } from '../../identity/IdentityGuardInterceptor';

describe('ActionDispatcher.resolveIdentityChallenge', () => {
  let dispatcher: ActionDispatcher;

  beforeEach(() => {
    dispatcher = new ActionDispatcher();
    IdentityGuardInterceptor.reset();
  });

  afterEach(() => {
    IdentityGuardInterceptor.reset();
    vi.restoreAllMocks();
  });

  it('result=verified + token → resolver 가 verified 결과 받음', async () => {
    const deferred = IdentityGuardInterceptor.createDeferred();

    await dispatcher.dispatchAction({
      handler: 'resolveIdentityChallenge',
      params: { result: 'verified', token: 'tok-abc' },
    });

    await expect(deferred).resolves.toEqual({
      status: 'verified',
      token: 'tok-abc',
    });
  });

  it('result=verified 인데 token 누락 → failed/MISSING_TOKEN 으로 강등', async () => {
    const deferred = IdentityGuardInterceptor.createDeferred();

    await dispatcher.dispatchAction({
      handler: 'resolveIdentityChallenge',
      params: { result: 'verified' },
    });

    await expect(deferred).resolves.toEqual({
      status: 'failed',
      failureCode: 'MISSING_TOKEN',
    });
  });

  it('result=cancelled → resolver 가 cancelled 받음', async () => {
    const deferred = IdentityGuardInterceptor.createDeferred();

    await dispatcher.dispatchAction({
      handler: 'resolveIdentityChallenge',
      params: { result: 'cancelled' },
    });

    await expect(deferred).resolves.toEqual({ status: 'cancelled' });
  });

  it('result=failed + failureCode/reason → 그대로 전달', async () => {
    const deferred = IdentityGuardInterceptor.createDeferred();

    await dispatcher.dispatchAction({
      handler: 'resolveIdentityChallenge',
      params: {
        result: 'failed',
        failureCode: 'INVALID_CODE',
        reason: '인증 코드가 일치하지 않음',
      },
    });

    await expect(deferred).resolves.toEqual({
      status: 'failed',
      failureCode: 'INVALID_CODE',
      reason: '인증 코드가 일치하지 않음',
    });
  });

  it('result=failed + failureCode 누락 → UNKNOWN 으로 보정', async () => {
    const deferred = IdentityGuardInterceptor.createDeferred();

    await dispatcher.dispatchAction({
      handler: 'resolveIdentityChallenge',
      params: { result: 'failed' },
    });

    await expect(deferred).resolves.toEqual({
      status: 'failed',
      failureCode: 'UNKNOWN',
    });
  });

  it('result=pending + pollUrl + expiresAt → pending 그대로 전달', async () => {
    const deferred = IdentityGuardInterceptor.createDeferred();

    await dispatcher.dispatchAction({
      handler: 'resolveIdentityChallenge',
      params: {
        result: 'pending',
        pollUrl: '/api/identity/challenges/abc',
        pollIntervalMs: 2000,
        expiresAt: '2026-04-27T10:00:00Z',
      },
    });

    await expect(deferred).resolves.toEqual({
      status: 'pending',
      pollUrl: '/api/identity/challenges/abc',
      pollIntervalMs: 2000,
      expiresAt: '2026-04-27T10:00:00Z',
    });
  });

  it('result=pending 인데 pollUrl/expiresAt 누락 → MALFORMED_PENDING 으로 강등', async () => {
    const deferred = IdentityGuardInterceptor.createDeferred();

    await dispatcher.dispatchAction({
      handler: 'resolveIdentityChallenge',
      params: { result: 'pending' },
    });

    await expect(deferred).resolves.toEqual({
      status: 'failed',
      failureCode: 'MALFORMED_PENDING',
    });
  });

  it('알 수 없는 result 문자열 → cancelled 로 강등 (방어적 기본값)', async () => {
    const deferred = IdentityGuardInterceptor.createDeferred();

    await dispatcher.dispatchAction({
      handler: 'resolveIdentityChallenge',
      params: { result: 'mystery' },
    });

    await expect(deferred).resolves.toEqual({ status: 'cancelled' });
  });

  it('대기 중 resolver 없을 때 호출되어도 throw 하지 않음', async () => {
    // createDeferred 호출 전 resolveIdentityChallenge 발생 시나리오 (정상 흐름은 아님)
    await expect(
      dispatcher.dispatchAction({
        handler: 'resolveIdentityChallenge',
        params: { result: 'verified', token: 'tok' },
      }),
    ).resolves.toBeDefined();
  });
});
