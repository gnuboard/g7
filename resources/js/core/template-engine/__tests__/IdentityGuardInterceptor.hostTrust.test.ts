/**
 * 본인인증 재요청·폴백 이동의 출처 회귀
 *
 * - 428 의 `return_request.url` 이 교차 출처면 인증 헤더·검증 토큰을 실어 재요청하지 않는다.
 * - 폴백 실행기는 챌린지 화면에 `return_url=<경로>`(레이아웃이 읽는 이름, 절대 주소 아님)를 넘긴다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { IdentityGuardInterceptor, defaultLauncher } from '../../identity/IdentityGuardInterceptor';

describe('IdentityGuardInterceptor — 재요청 출처', () => {
  let fetchSpy: ReturnType<typeof vi.fn>;
  let originalFetch: typeof fetch;

  beforeEach(() => {
    IdentityGuardInterceptor.reset();
    IdentityGuardInterceptor.setLauncher(async () => ({ status: 'verified', token: 'vt-1' }) as any);
    originalFetch = globalThis.fetch;
    fetchSpy = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }));
    globalThis.fetch = fetchSpy as unknown as typeof fetch;
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    IdentityGuardInterceptor.reset();
  });

  const response428 = (url: string) => ({
    verification: {
      policy_key: 'core.profile.contact_change',
      purpose: 'sensitive_action',
      provider_id: 'g7:core.mail',
      render_hint: 'text_code',
      challenge_start_url: '/api/identity/challenges',
      return_request: { method: 'PUT', url },
    },
  }) as any;

  // @scenario host_header=forged, sink=front_identity_retry
  // @effects identity_retry_only_same_origin
  it('교차 출처 재요청 주소면 재요청하지 않는다', async () => {
    const result = await IdentityGuardInterceptor.handle(
      response428('https://evil.example/api/me'),
      { headers: { Authorization: 'Bearer secret' } },
    );

    expect(result).toBeNull();
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  // @scenario host_header=canonical, sink=front_identity_retry
  // @effects identity_retry_only_same_origin
  it('경로 재요청 주소면 토큰을 붙여 재요청한다', async () => {
    await IdentityGuardInterceptor.handle(response428('/api/me/profile?x=1'), { headers: { Accept: 'application/json' } });

    expect(fetchSpy).toHaveBeenCalledTimes(1);
    expect(fetchSpy.mock.calls[0][0]).toBe('/api/me/profile?x=1&verification_token=vt-1');
  });
});

describe('defaultLauncher — 챌린지 화면 복귀 주소', () => {
  let dispatchSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    dispatchSpy = vi.fn().mockResolvedValue(undefined);
    (window as any).G7Core = { dispatch: dispatchSpy };
    window.history.pushState({}, '', '/mypage/profile?tab=info#contact');
  });

  afterEach(() => {
    delete (window as any).G7Core;
    window.history.pushState({}, '', '/');
  });

  // @scenario host_header=canonical, sink=front_identity_challenge_return
  // @effects identity_challenge_receives_return_url_path
  it('return_url 에 현재 경로+쿼리를 넘기고 해시는 뺀다', async () => {
    void defaultLauncher({
      policy_key: 'k',
      purpose: 'sensitive_action',
      provider_id: 'g7:core.mail',
      render_hint: 'text_code',
    } as any);
    await new Promise((r) => setTimeout(r, 0));

    const navigate = dispatchSpy.mock.calls.find((c: any[]) => c[0]?.handler === 'navigate');
    expect(navigate?.[0].params.path).toBe(
      `/identity/challenge?return_url=${encodeURIComponent('/mypage/profile?tab=info')}`,
    );
  });
});
