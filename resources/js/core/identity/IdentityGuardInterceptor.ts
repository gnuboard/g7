/**
 * IDV Guard Interceptor — 프론트엔드 전역 428 인터셉트.
 *
 * 서버가 HTTP 428 + error_code='identity_verification_required' 을 반환하면,
 * 이 모듈이 Challenge 모달 launcher 를 호출하고 verify 성공 후 원 요청을 자동 재실행합니다.
 *
 * 사용 방식:
 * - 부트스트랩 시 템플릿이 `setLauncher()` 로 모달 launcher 를 등록합니다.
 *   launcher 의 반환 타입은 `Promise<VerificationResult>` 로, 모달이 verify 성공/취소/실패를
 *   `resolveIdentityChallenge` 핸들러로 통보하면 인터셉터가 deferred resolver 를 통해 resolve 합니다.
 * - launcher 미등록 외부 템플릿은 `defaultLauncher` 로 폴백 — 토스트 발행 + `/identity/challenge` navigate.
 * - `render_hint='external_redirect'` 또는 `redirect_url` 존재 시 launcher 가 sessionStorage stash 후
 *   `window.location.href` 로 이동(헬퍼 `redirectExternally` 제공).
 *
 * `ActionDispatcher.handleApiCall` 응답 후처리 한 곳에서 `isIdentityRequired()` / `handle()`
 * 정적 메서드를 직접 호출 — 별도 디스패처 인프라 없이 모든 apiCall 의 choke point 가 됨.
 *
 * @since engine-v1.44.0
 */

import { createLogger } from '../utils/Logger';
import {
  IDENTITY_REDIRECT_STASH_KEY,
  type IdentityResponse428,
  type IdentityRedirectStash,
  type ModalLauncher,
  type VerificationPayload,
  type VerificationResult,
} from './types';

const logger = createLogger('IdentityGuardInterceptor');

export type {
  IdentityResponse428,
  IdentityRedirectStash,
  IdentityVerificationTarget,
  ModalLauncher,
  VerificationPayload,
  VerificationResult,
} from './types';
export { IDENTITY_REDIRECT_STASH_KEY } from './types';

/**
 * 428 응답을 감지해 인증 모달 launcher 를 실행한 뒤 return_request 를 재실행합니다.
 *
 * 실제 모달 UI 는 템플릿(공통 모달 파셜) + Extension Point 슬롯이 제공합니다.
 * 이 인터셉터는 launcher 를 주입받아 호출하고, deferred resolver 슬롯을 통해
 * 모달과 launcher Promise 를 잇는 글로벌 choke point 역할만 합니다.
 */
export class IdentityGuardInterceptor {
  private static launcher: ModalLauncher | null = null;
  private static deferredResolver:
    | ((result: VerificationResult) => void)
    | null = null;

  /**
   * challenge 레이어(모달/launcher)가 이번 IDV 사이클에서 자기 고유의 도메인 안내를
   * 사용자에게 이미 표출했음을 표시하는 1회성 플래그.
   *
   * 배경: 본인확인은 성공했으나 부가 목적(성인인증 등)을 충족하지 못해 challenge 가
   * 실패로 종료되면, provider 가 "성인 인증이 필요합니다" 같은 고유 사유를 토스트로 표출한다.
   * 그 직후 코어가 원 428 을 onError 로 흘려보내면 원 요청의 generic 가드 토스트
   * ("본인 확인이 필요합니다")가 중복으로 뜬다. 이 플래그가 set 되어 있으면 코어 toast 핸들러가
   * 동일 사이클의 generic IDV 가드 토스트 1건을 skip 한다.
   *
   * 일반 본인인증 실패(본인확인 자체 실패/취소)는 이 플래그를 set 하지 않으므로
   * generic 가드 토스트가 그대로 유지된다 — provider 무지식의 범용 신호다.
   *
   * @since engine-v1.50.0
   */
  private static domainNoticeShown = false;

  /**
   * 모달 launcher 를 등록합니다.
   *
   * launcher 는 모달 open / 풀페이지 navigate / 외부 SDK 호출 등 UI 진입을 책임지며,
   * 사용자 액션의 결과를 `VerificationResult` 로 resolve 합니다.
   * 모달이 verify 성공 / 취소 / 실패를 알리려면 `resolveIdentityChallenge` 핸들러를 호출하세요.
   *
   * @param launcher 모달 launcher 함수
   */
  static setLauncher(launcher: ModalLauncher): void {
    this.launcher = launcher;
  }

  /**
   * 등록된 launcher 가 있는지 여부.
   */
  static hasLauncher(): boolean {
    return this.launcher !== null;
  }

  /**
   * challenge 레이어가 자기 고유의 도메인 안내(성인인증 실패 등)를 사용자에게 표출했음을 표시한다.
   *
   * provider(예: 이니시스 플러그인)가 "본인확인은 성공했으나 부가 목적 미달" 실패를 사용자에게
   * 안내한 직후 호출한다. 코어 toast 핸들러가 동일 사이클의 generic IDV 가드 토스트를 1회 skip 한다.
   * 호출 시점은 항상 코어가 원 428 을 onError 로 흘려보내기 직전(resolveIdentityChallenge 통보 전)이어야 한다.
   *
   * @since engine-v1.50.0
   */
  static markDomainNoticeShown(): void {
    this.domainNoticeShown = true;
  }

  /**
   * 도메인 안내 표출 플래그를 읽고 즉시 소비(해제)한다.
   *
   * 코어 toast 핸들러가 generic IDV 가드 토스트를 띄우기 직전 1회 호출한다.
   * true 면 그 토스트를 skip 하고, 플래그는 소비되어 다음 토스트부터는 정상 표출된다.
   *
   * @returns 직전 challenge 가 도메인 안내를 표출했으면 true (그리고 플래그 해제)
   * @since engine-v1.50.0
   */
  static consumeDomainNoticeShown(): boolean {
    const shown = this.domainNoticeShown;
    this.domainNoticeShown = false;
    return shown;
  }

  /**
   * 외부 호출(주로 `resolveIdentityChallenge` 핸들러)이 사용하는 진입점.
   *
   * launcher 진입 시 deferred resolver 가 등록되어 있어야 호출이 의미를 가집니다.
   * launcher 가 없는데 호출되면 경고만 남기고 무시.
   */
  static resolveDeferred(result: VerificationResult): void {
    const resolver = this.deferredResolver;
    if (!resolver) {
      logger.warn(
        'IdentityGuardInterceptor.resolveDeferred 호출됐지만 대기 중인 launcher 가 없습니다. 모달이 launcher 외부에서 열렸는지 확인하세요.'
      );
      return;
    }
    this.deferredResolver = null;
    resolver(result);
  }

  /**
   * launcher 가 deferred Promise 를 만들 때 사용하는 helper.
   *
   * 동시에 두 launcher 가 활성화되면 이전 resolver 는 `cancelled` 로 강제 종료됩니다.
   */
  static createDeferred(): Promise<VerificationResult> {
    if (this.deferredResolver) {
      // 이전 launcher 가 아직 살아있다면 cancelled 처리하고 새 launcher 진입 허용
      const stale = this.deferredResolver;
      this.deferredResolver = null;
      stale({ status: 'cancelled' });
    }
    return new Promise<VerificationResult>((resolve) => {
      this.deferredResolver = resolve;
    });
  }

  /**
   * API 응답이 IDV 요구 응답인지 판별합니다.
   *
   * @param status HTTP 상태 코드
   * @param body JSON 응답 본문
   */
  static isIdentityRequired(status: number, body: unknown): body is IdentityResponse428 {
    return (
      status === 428 &&
      typeof body === 'object' &&
      body !== null &&
      (body as any).error_code === 'identity_verification_required'
    );
  }

  /**
   * 428 응답을 처리합니다.
   *
   * launcher 가 등록되어 있으면 그 launcher 를, 아니면 `defaultLauncher` 를 호출합니다.
   * verify 성공 시 `verification_token` 을 `return_request.url` 에 query 로 부착해 재실행합니다.
   *
   * 두 번째 인자 `originalRequest` 는 caller(주로 ActionDispatcher.handleApiCall) 가 만든
   * 원 요청의 RequestInit 을 전달받습니다. retry fetch 가 원 요청의 body / headers / credentials
   * 를 그대로 재사용해야 백엔드가 빈 body 로 422 를 던지지 않습니다 (회원가입 등 모든 POST 흐름).
   *
   * @param response 428 JSON 본문
   * @param originalRequest 원 요청 RequestInit (body, headers, credentials) — 미전달 시 빈 body 로 fallback
   * @param target 흐름이 선언한 인증 대상(이메일·전화) — apiCall `identity_target` 에서 평가된 값.
   *   launcher 가 challenge 시작 시 사용한다. 비로그인 게스트 흐름의 핵심 (서버는 화면 입력값을 모름).
   * @returns 재실행 응답 또는 null (사용자 취소 / 실패 / return_request 없음)
   */
  static async handle(
    response: IdentityResponse428,
    originalRequest?: Pick<RequestInit, 'body' | 'headers' | 'credentials'>,
    target?: { email?: string; phone?: string },
  ): Promise<Response | null> {
    // 새 challenge 진입 — 이전 사이클의 미소비 도메인 안내 플래그를 정리한다.
    // (이전 사이클에서 onError 가드 토스트가 없어 consume 되지 않은 stale 플래그가
    // 이번 사이클의 가드 토스트를 잘못 skip 하는 것을 차단)
    this.domainNoticeShown = false;
    const launcher = this.launcher ?? defaultLauncher;
    // 흐름이 선언한 target 을 payload 에 병합 — launcher 는 payload.target 한 곳에서 읽는다.
    // email/phone 둘 다 빈 값이면 병합하지 않음(로그인 사용자는 서버 세션 도출).
    const hasTarget = !!(target && (target.email || target.phone));
    const verification: VerificationPayload = hasTarget
      ? { ...response.verification, target }
      : response.verification;
    const result = await launcher(verification);

    if (result.status !== 'verified') {
      return null;
    }

    const returnReq = verification.return_request;
    if (!returnReq) {
      return null;
    }

    const replayUrl = appendVerificationToken(returnReq.url, result.token);
    return fetch(replayUrl, {
      method: returnReq.method,
      headers: originalRequest?.headers ?? {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: originalRequest?.body,
      credentials: originalRequest?.credentials ?? 'same-origin',
    });
  }

  /**
   * external_redirect 분기 — sessionStorage 에 stash 후 `window.location.href` 로 이동.
   *
   * launcher 안에서 `render_hint === 'external_redirect'` 또는 `verification.redirect_url`
   * 존재 시 호출하세요. 콜백 후 페이지가 stash 를 복원해 원 요청을 재실행하는 책임은 호출자에게 있습니다.
   *
   * 반환값은 `Promise<VerificationResult>` 이지만 redirect 가 일어나면 페이지가 언로드되므로
   * 사실상 resolve 되지 않습니다. 단위 테스트에서는 `pending` 으로 모킹.
   */
  static redirectExternally(payload: VerificationPayload): Promise<VerificationResult> {
    const redirectUrl = payload.redirect_url;
    if (!redirectUrl) {
      logger.error(
        'redirectExternally 호출 시 verification.redirect_url 이 없습니다. payload:',
        payload
      );
      return Promise.resolve({
        status: 'failed',
        failureCode: 'MISSING_REDIRECT_URL',
        reason: 'verification.redirect_url is required for external_redirect flow',
      });
    }

    if (typeof window !== 'undefined') {
      const stash: IdentityRedirectStash = {
        return_url: window.location.href,
        payload,
        stashed_at: Date.now(),
      };
      try {
        window.sessionStorage?.setItem(IDENTITY_REDIRECT_STASH_KEY, JSON.stringify(stash));
      } catch (e) {
        logger.warn('sessionStorage 접근 실패 — stash 없이 redirect 합니다.', e);
      }
      window.location.href = redirectUrl;
    }

    // 페이지 언로드 — 절대 resolve 되지 않음. pending 으로 표기해 caller 가 polling 으로 전환할 수 있게 함.
    return new Promise<VerificationResult>(() => {
      /* never resolves */
    });
  }

  /**
   * 테스트 목적으로 launcher 와 deferred resolver 를 해제합니다.
   */
  static reset(): void {
    if (this.deferredResolver) {
      const stale = this.deferredResolver;
      this.deferredResolver = null;
      try {
        stale({ status: 'cancelled' });
      } catch {
        /* ignore */
      }
    }
    this.launcher = null;
    this.domainNoticeShown = false;
  }
}

/**
 * `return_request.url` 에 verification_token 을 query 로 부착합니다.
 *
 * 이미 query string 이 있으면 `&` 로, 없으면 `?` 로 시작하며,
 * 같은 키가 이미 있으면 덮어씁니다.
 */
function appendVerificationToken(url: string, token: string): string {
  if (!token) return url;
  try {
    // 절대 URL / 상대 URL 모두 안전하게 처리하기 위해 base 사용
    const base = typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
    const u = new URL(url, base);
    u.searchParams.set('verification_token', token);
    // 입력이 상대 경로였다면 상대 경로로 복원
    if (!/^https?:\/\//i.test(url)) {
      return `${u.pathname}${u.search}${u.hash}`;
    }
    return u.toString();
  } catch {
    // URL 파싱 실패 시 단순 부착
    const sep = url.includes('?') ? '&' : '?';
    return `${url}${sep}verification_token=${encodeURIComponent(token)}`;
  }
}

/**
 * launcher 미등록 외부 템플릿용 폴백 launcher.
 *
 * `/identity/challenge?return=...` 풀페이지로 navigate 하면서 토스트로 안내합니다.
 * 풀페이지가 verify 후 `resolveIdentityChallenge` 를 호출해 결과를 돌려보냅니다.
 *
 * AuthManager / G7Core 가 미초기화이면 `console.error` 만 남기고 `cancelled` 로 강등.
 */
export const defaultLauncher: ModalLauncher = async (payload) => {
  if (typeof window === 'undefined') {
    return { status: 'cancelled' };
  }

  // external_redirect 는 폴백에서도 redirect 로 분기 — 풀페이지 진입조차 무의미하기 때문.
  if (payload.render_hint === 'external_redirect' || payload.redirect_url) {
    return IdentityGuardInterceptor.redirectExternally(payload);
  }

  const G7Core = (window as any).G7Core;
  if (!G7Core?.dispatch) {
    // G7Core 미초기화 — 진입 자체가 불가
    // eslint-disable-next-line no-console
    console.error(
      '[IdentityGuardInterceptor] defaultLauncher: G7Core 가 초기화되지 않아 본인인증 흐름을 시작할 수 없습니다.'
    );
    return { status: 'failed', failureCode: 'G7_NOT_READY' };
  }

  // 토스트 안내 (best-effort)
  try {
    await G7Core.dispatch({
      handler: 'toast',
      params: {
        message: '본인 확인이 필요합니다.',
        variant: 'warning',
      },
    });
  } catch {
    /* ignore — 토스트가 없는 환경도 폴백 자체는 진행 */
  }

  // sessionStorage 에 stash 후 풀페이지로 이동
  const returnUrl = window.location.href;
  const stash: IdentityRedirectStash = {
    return_url: returnUrl,
    payload,
    stashed_at: Date.now(),
  };
  try {
    window.sessionStorage?.setItem(IDENTITY_REDIRECT_STASH_KEY, JSON.stringify(stash));
  } catch {
    /* ignore */
  }

  const challengePath = `/identity/challenge?return=${encodeURIComponent(returnUrl)}`;
  try {
    await G7Core.dispatch({
      handler: 'navigate',
      params: { path: challengePath },
    });
  } catch (e) {
    logger.warn('navigate 실패 — window.location 으로 폴백', e);
    window.location.href = challengePath;
  }

  // navigate 가 일어나면 페이지가 unmount — 이 Promise 는 사실상 resolve 되지 않음.
  return new Promise<VerificationResult>(() => {
    /* never resolves */
  });
};
