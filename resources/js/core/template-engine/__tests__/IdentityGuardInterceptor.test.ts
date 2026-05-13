/**
 * IdentityGuardInterceptor 테스트.
 *
 * 428 응답 감지 / launcher 호출 / VerificationResult 분기 / verification_token query 부착 /
 * defaultLauncher 폴백 / external_redirect stash 검증.
 *
 * @since engine-v1.44.0 (engine-v1.46.0 에서 VerificationResult 4-상태 + token query 부착으로 확장)
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  IdentityGuardInterceptor,
  IDENTITY_REDIRECT_STASH_KEY,
  type IdentityResponse428,
} from '../../identity/IdentityGuardInterceptor';

describe('IdentityGuardInterceptor', () => {
  beforeEach(() => {
    IdentityGuardInterceptor.reset();
    if (typeof window !== 'undefined') {
      window.sessionStorage?.clear?.();
    }
  });

  afterEach(() => {
    IdentityGuardInterceptor.reset();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  describe('isIdentityRequired', () => {
    it('returns true for 428 + identity_verification_required error_code', () => {
      const body = { success: false, error_code: 'identity_verification_required', message: 'x' };
      expect(IdentityGuardInterceptor.isIdentityRequired(428, body)).toBe(true);
    });

    it('returns false for non-428 status', () => {
      expect(
        IdentityGuardInterceptor.isIdentityRequired(403, { error_code: 'identity_verification_required' }),
      ).toBe(false);
    });

    it('returns false when error_code differs', () => {
      expect(IdentityGuardInterceptor.isIdentityRequired(428, { error_code: 'other' })).toBe(false);
    });

    it('returns false for non-object body', () => {
      expect(IdentityGuardInterceptor.isIdentityRequired(428, null)).toBe(false);
    });
  });

  describe('handle — VerificationResult 분기', () => {
    const makeResponse = (overrides: Partial<IdentityResponse428['verification']> = {}): IdentityResponse428 => ({
      success: false,
      error_code: 'identity_verification_required',
      message: 'verify',
      verification: {
        policy_key: 'core.profile.password_change',
        purpose: 'sensitive_action',
        provider_id: 'g7:core.mail',
        render_hint: 'text_code',
        return_request: {
          method: 'PUT',
          url: '/api/me/password',
        },
        ...overrides,
      },
    });

    it('returns null when launcher resolves cancelled', async () => {
      IdentityGuardInterceptor.setLauncher(async () => ({ status: 'cancelled' }));
      const result = await IdentityGuardInterceptor.handle(makeResponse());
      expect(result).toBeNull();
    });

    it('returns null when launcher resolves failed', async () => {
      IdentityGuardInterceptor.setLauncher(async () => ({ status: 'failed', failureCode: 'INVALID_CODE' }));
      const result = await IdentityGuardInterceptor.handle(makeResponse());
      expect(result).toBeNull();
    });

    it('replays return_request and appends verification_token query when verified', async () => {
      const fetchMock = vi.fn(async () => new Response('{}', { status: 200 }));
      vi.stubGlobal('fetch', fetchMock);

      IdentityGuardInterceptor.setLauncher(async () => ({ status: 'verified', token: 'tok-abc-123' }));
      const result = await IdentityGuardInterceptor.handle(makeResponse());

      expect(result).toBeInstanceOf(Response);
      expect(fetchMock).toHaveBeenCalledOnce();
      const replayUrl = fetchMock.mock.calls[0][0] as string;
      expect(replayUrl).toContain('/api/me/password');
      expect(replayUrl).toContain('verification_token=tok-abc-123');
      expect((fetchMock.mock.calls[0][1] as RequestInit).method).toBe('PUT');
    });

    it('preserves existing query string when appending verification_token', async () => {
      const fetchMock = vi.fn(async () => new Response('{}', { status: 200 }));
      vi.stubGlobal('fetch', fetchMock);

      IdentityGuardInterceptor.setLauncher(async () => ({ status: 'verified', token: 'tok-xyz' }));
      await IdentityGuardInterceptor.handle(
        makeResponse({ return_request: { method: 'POST', url: '/api/auth/register?lang=ko' } }),
      );

      const replayUrl = fetchMock.mock.calls[0][0] as string;
      expect(replayUrl).toContain('lang=ko');
      expect(replayUrl).toContain('verification_token=tok-xyz');
    });

    /**
     * 회귀 — retry fetch 가 원 요청의 body 와 headers 를 보존해야 함.
     *
     * 과거 handle() 이 body 없이 fetch 를 호출하여 retry 시 백엔드가 빈 body 를 받고
     * 422 (필수 필드 누락) 를 반환했음. 회원가입 등 모든 POST/PUT 흐름이 IDV 통과 후
     * 자동 재실행에서 깨졌음.
     */
    it('replays request preserving original body and headers when verified', async () => {
      const fetchMock = vi.fn(async () => new Response('{}', { status: 200 }));
      vi.stubGlobal('fetch', fetchMock);

      IdentityGuardInterceptor.setLauncher(async () => ({ status: 'verified', token: 'tok-abc-123' }));

      const originalBody = JSON.stringify({
        name: '홍길동',
        email: 'user@example.com',
        password: 'password123',
      });
      const originalHeaders: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'Accept-Language': 'ko',
        'X-XSRF-TOKEN': 'csrf-token-abc',
      };

      const result = await IdentityGuardInterceptor.handle(makeResponse({
        return_request: { method: 'POST', url: '/api/auth/register' },
      }), {
        body: originalBody,
        headers: originalHeaders,
        credentials: 'include',
      });

      expect(result).toBeInstanceOf(Response);
      expect(fetchMock).toHaveBeenCalledOnce();
      const sentInit = fetchMock.mock.calls[0][1] as RequestInit;
      expect(sentInit.body).toBe(originalBody);
      expect(sentInit.headers).toMatchObject({
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': 'csrf-token-abc',
        'Accept-Language': 'ko',
      });
      expect(sentInit.credentials).toBe('include');
    });

    it('returns null when return_request is missing even on verified', async () => {
      IdentityGuardInterceptor.setLauncher(async () => ({ status: 'verified', token: 'tok-1' }));
      const response = makeResponse({ return_request: null });

      const result = await IdentityGuardInterceptor.handle(response);
      expect(result).toBeNull();
    });
  });

  describe('createDeferred / resolveDeferred', () => {
    it('createDeferred returns a Promise that resolves via resolveDeferred', async () => {
      const promise = IdentityGuardInterceptor.createDeferred();
      IdentityGuardInterceptor.resolveDeferred({ status: 'verified', token: 'tok' });
      await expect(promise).resolves.toEqual({ status: 'verified', token: 'tok' });
    });

    it('createDeferred while another deferred is pending cancels the previous one', async () => {
      const first = IdentityGuardInterceptor.createDeferred();
      const second = IdentityGuardInterceptor.createDeferred();

      IdentityGuardInterceptor.resolveDeferred({ status: 'verified', token: 'second-tok' });

      await expect(first).resolves.toEqual({ status: 'cancelled' });
      await expect(second).resolves.toEqual({ status: 'verified', token: 'second-tok' });
    });

    it('resolveDeferred without active resolver only logs (does not throw)', () => {
      expect(() =>
        IdentityGuardInterceptor.resolveDeferred({ status: 'cancelled' }),
      ).not.toThrow();
    });
  });

  describe('redirectExternally', () => {
    it('writes stash to sessionStorage and assigns window.location.href', () => {
      // window.location.href 할당 모킹 (jsdom)
      const hrefSetter = vi.fn();
      Object.defineProperty(window, 'location', {
        configurable: true,
        value: new Proxy(window.location, {
          set(_target, prop, value) {
            if (prop === 'href') {
              hrefSetter(value);
              return true;
            }
            return Reflect.set(_target, prop, value);
          },
        }),
      });

      // Promise 가 절대 resolve 되지 않으므로 await 하지 않음
      void IdentityGuardInterceptor.redirectExternally({
        policy_key: 'k',
        purpose: 'signup',
        render_hint: 'external_redirect',
        redirect_url: 'https://provider.example.com/auth?ch=abc',
      });

      // 1) sessionStorage 에 stash 기록 — 직접 값 확인 (spyOn 은 jsdom Storage prototype 우회로 신뢰 불안정)
      const stashed = window.sessionStorage.getItem(IDENTITY_REDIRECT_STASH_KEY);
      expect(stashed).not.toBeNull();
      expect(stashed!).toContain('"return_url"');
      expect(stashed!).toContain('"payload"');

      // 2) window.location.href 할당 확인
      expect(hrefSetter).toHaveBeenCalledWith('https://provider.example.com/auth?ch=abc');
    });

    it('returns failed result if redirect_url is missing', async () => {
      const result = await IdentityGuardInterceptor.redirectExternally({
        policy_key: 'k',
        purpose: 'signup',
        render_hint: 'external_redirect',
      });
      expect(result).toEqual(
        expect.objectContaining({ status: 'failed', failureCode: 'MISSING_REDIRECT_URL' }),
      );
    });
  });

  describe('defaultLauncher (launcher 미등록 폴백)', () => {
    it('handle() 가 launcher 없으면 defaultLauncher 를 사용해 G7Core.dispatch 로 navigate 한다', async () => {
      const dispatchMock = vi.fn(async () => undefined);
      (window as any).G7Core = { dispatch: dispatchMock };

      // navigate 가 호출되면 페이지가 이동하는 것으로 간주 — Promise resolve 되지 않음
      // 따라서 handle() 도 resolve 되지 않으므로 race 로 검증
      const handlePromise = IdentityGuardInterceptor.handle({
        success: false,
        error_code: 'identity_verification_required',
        message: '',
        verification: {
          policy_key: 'core.auth.signup_before_submit',
          purpose: 'signup',
          render_hint: 'text_code',
          return_request: { method: 'POST', url: '/api/auth/register' },
        },
      });

      const settled = await Promise.race([
        handlePromise.then(() => 'resolved'),
        new Promise((r) => setTimeout(() => r('pending'), 50)),
      ]);

      expect(settled).toBe('pending');
      // toast + navigate 두 번 dispatch
      expect(dispatchMock).toHaveBeenCalledWith(
        expect.objectContaining({ handler: 'toast' }),
      );
      expect(dispatchMock).toHaveBeenCalledWith(
        expect.objectContaining({
          handler: 'navigate',
          params: expect.objectContaining({
            path: expect.stringContaining('/identity/challenge?return='),
          }),
        }),
      );

      delete (window as any).G7Core;
    });

    it('G7Core 미초기화 시 console.error 로 알리고 failed 로 강등', async () => {
      delete (window as any).G7Core;
      const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      const result = await IdentityGuardInterceptor.handle({
        success: false,
        error_code: 'identity_verification_required',
        message: '',
        verification: {
          policy_key: 'k',
          purpose: 'signup',
          render_hint: 'text_code',
          return_request: { method: 'POST', url: '/api/x' },
        },
      });

      // failed → handle 은 null 반환
      expect(result).toBeNull();
      expect(errorSpy).toHaveBeenCalled();
    });
  });
});
