/**
 * 방문자 통계 트래커
 *
 * 화면 이동·내려받기·외부 링크를 서버에 알립니다.
 *
 * **이 트래커는 사이트 동작에 어떤 영향도 주지 않습니다.** 전송은 예외를 전부 삼키고,
 * 응답을 기다리지 않으며, 화면·상태·라우팅에 아무 값도 돌려주지 않습니다. 통계가
 * 실패했다고 방문자가 보던 화면이 멈추거나 느려지면 안 됩니다.
 */

import { ConsentBridge, consentGateRequired } from './consent';
import { type AnalyticsClientSettings, isWebDriver, shouldSkip } from './exclusions';
import { resolvePageTitle, type TitleSource } from './pageTitle';

/** 수집 경로 */
const COLLECT_ENDPOINT = '/api/analytics/collect';

/** 수집 제외 토글 주소 — 서버가 `g7_analytics_optout` 쿠키를 심고 지운다. */
const OPT_OUT_ENDPOINT = '/api/admin/analytics/opt-out';

/** 직전 페이지 체류 시간 상한 (초) — 서버도 같은 값으로 자른다 */
const MAX_TIME_SPENT_SECONDS = 1800;

/** 비콘 페이로드 */
export interface BeaconPayload {
  /** 액션 종류 */
  t: string;
  /** 방문 URL */
  url: string;
  /** 페이지 제목 */
  title?: string;
  /** 유입 URL (첫 비콘에만) */
  ref?: string;
  /** 화면 해상도 */
  res?: string;
  /** 브라우저 언어 */
  lang?: string;
  /** 사이트 로케일 */
  locale?: string;
  /** 직전 페이지 체류 시간 (초) */
  prev?: number;
  /** 이 방문의 첫 비콘 여부 */
  init?: boolean;
  /** 내려받기·외부 링크 대상 */
  target?: string;
  /** 표시 이름 */
  name?: string;
  /** 목표 키 */
  goal?: string;
  /** 목표 속성 */
  attrs?: Record<string, unknown>;
  /** 확장 차원 값 */
  dims?: Record<string, string>;
  /** 클라이언트 힌트 */
  ch?: Record<string, unknown>;
  /** 자동화 브라우저 표식 */
  wd?: boolean;
}

/** 트래커 생성 옵션 */
export interface TrackerOptions {
  /** 수집 설정 (노출된 키만) */
  settings: AnalyticsClientSettings;
  /** 동의 관리 플러그인 활성 여부 */
  consentPluginActive?: boolean;
  /** 사이트 로케일 */
  locale?: string;
  /** 인증 토큰 조회 (회원·관리자 방문을 가르는 데 쓴다) */
  getAuthToken?: () => string | null;
  /** 번역 함수 (페이지 제목 판정용) */
  translate?: (key: string) => string;
}

/**
 * 방문자 통계 트래커.
 */
export class AnalyticsTracker {
  /** 동의 브리지 */
  private readonly consent: ConsentBridge;

  /** 이 방문에서 비콘을 이미 보냈는지 */
  private initialSent = false;

  /** 직전 페이지뷰를 보낸 시각 (ms) */
  private lastPageviewAt: number | null = null;

  /** 확장이 넣어 둔 차원 값 */
  private dimensions: Record<string, string> = {};

  /**
   * 트래커를 만듭니다.
   *
   * @param options 생성 옵션
   */
  constructor(private readonly options: TrackerOptions) {
    // 동의 게이트는 「동의 연동」 모드에서만 선다. 플러그인이 있다는 이유만으로 막으면
    // IP 저장 모드 사이트에서 미동의 방문자의 화면 이동이 전부 빠진다.
    this.consent = new ConsentBridge(
      consentGateRequired(options.consentPluginActive === true, options.settings.privacy_mode),
      options.getAuthToken,
    );
  }

  /**
   * 동의 상태를 읽고 구독을 시작합니다.
   *
   * @returns 완료 Promise
   */
  async start(): Promise<void> {
    await this.consent.start();
  }

  /**
   * 구독을 해제합니다.
   *
   * @returns 없음
   */
  stop(): void {
    this.consent.stop();
  }

  /**
   * 화면 열람을 알립니다.
   *
   * @param route 현재 라우트
   * @returns 없음
   */
  trackPageview(route: TitleSource | null): void {
    const now = Date.now();
    const prev = this.lastPageviewAt === null
      ? undefined
      : Math.min(MAX_TIME_SPENT_SECONDS, Math.round((now - this.lastPageviewAt) / 1000));

    const isInitial = !this.initialSent;
    this.initialSent = true;
    this.lastPageviewAt = now;

    this.send({
      t: 'pageview',
      url: this.currentUrl(),
      title: resolvePageTitle(route, this.options.translate),
      // 유입 정보는 방문의 첫 비콘에만 의미가 있다. SPA 이동의 referrer 는 자기 사이트다.
      ref: isInitial ? document.referrer || undefined : undefined,
      init: isInitial,
      prev,
    });
  }

  /**
   * 파일 내려받기를 알립니다.
   *
   * @param url 파일 주소
   * @param name 표시 이름
   * @returns 없음
   */
  trackDownload(url: string, name?: string): void {
    if (this.options.settings.track_downloads === false) return;

    this.send({ t: 'download', url: this.currentUrl(), target: url, name });
  }

  /**
   * 외부 링크 이동을 알립니다.
   *
   * @param url 외부 주소
   * @returns 없음
   */
  trackOutlink(url: string): void {
    if (this.options.settings.track_outlinks === false) return;

    this.send({ t: 'outlink', url: this.currentUrl(), target: url });
  }

  /**
   * 목표 달성을 알립니다.
   *
   * @param key 목표 키
   * @param attributes 목표 속성 (금액·통화·라벨)
   * @returns 없음
   */
  trackGoal(key: string, attributes: Record<string, unknown> = {}): void {
    this.send({ t: 'goal', url: this.currentUrl(), goal: key, attrs: attributes });
  }

  /**
   * 사용자 정의 이벤트를 알립니다.
   *
   * @param name 이벤트 이름
   * @param attributes 이벤트 속성
   * @returns 없음
   */
  trackEvent(name: string, attributes: Record<string, unknown> = {}): void {
    this.send({ t: 'event', url: this.currentUrl(), name, attrs: attributes });
  }

  /**
   * 확장 차원 값을 설정합니다.
   *
   * 다음 비콘부터 함께 실립니다. 등록되지 않은 차원은 서버가 버립니다.
   *
   * @param key 차원 키
   * @param value 차원 값
   * @returns 없음
   */
  setDimension(key: string, value: string): void {
    if (!key) return;

    this.dimensions[key] = String(value);
  }

  /**
   * 방문자가 수집 제외를 선택했는지 확인합니다.
   *
   * @returns 제외 선택 여부
   */
  isOptedOut(): boolean {
    return shouldSkip(this.currentUrl(), this.options.settings) === 'opt_out';
  }

  /**
   * 이 브라우저를 수집에서 뺍니다.
   *
   * 쿠키는 **서버가** 심습니다 — GDPR 플러그인의 쿠키 가로채기가 `document.cookie`
   * 쓰기를 막고, 트래커가 되읽어야 하므로 HttpOnly 도 아니어야 하기 때문입니다.
   * 관리자가 자기 방문을 인기 페이지·순방문자에서 빼는 데 씁니다.
   *
   * @returns 서버가 쿠키를 심으면 완료되는 약속 (실패해도 거부하지 않습니다)
   */
  async optOut(): Promise<void> {
    await this.toggleOptOut('POST');
  }

  /**
   * 수집 제외를 해제합니다.
   *
   * @returns 서버가 쿠키를 지우면 완료되는 약속 (실패해도 거부하지 않습니다)
   */
  async optIn(): Promise<void> {
    await this.toggleOptOut('DELETE');
  }

  /**
   * 수집 제외 쿠키를 서버에 요청합니다.
   *
   * 비콘과 달리 응답을 기다립니다 — 화면이 「제외됨」 상태를 다시 그리려면 쿠키가
   * 실제로 심긴 뒤여야 하기 때문입니다. 다만 실패를 호출자에게 던지지는 않습니다
   * (D15 — 통계 호출이 화면을 깨뜨리지 않는다).
   *
   * @param method POST 면 제외, DELETE 면 해제
   * @returns 없음
   */
  private async toggleOptOut(method: 'POST' | 'DELETE'): Promise<void> {
    try {
      const token = this.options.getAuthToken?.();
      const headers: Record<string, string> = { 'Content-Type': 'application/json' };

      if (token) headers.Authorization = `Bearer ${token}`;

      await fetch(OPT_OUT_ENDPOINT, { method, credentials: 'same-origin', headers });
    } catch {
      // 제외 토글 실패는 화면에 드러나지 않는다 — 상태는 쿠키가 단일 출처다.
    }
  }

  /**
   * 비콘을 보냅니다.
   *
   * 라우트 변경 핸들러는 동기로 반환해야 하므로(엔진이 핸들러를 기다린다) 전송은
   * 다음 틱으로 미룹니다. 그래야 화면 전환이 비콘만큼 늦어지지 않습니다.
   *
   * @param payload 비콘 페이로드
   * @returns 없음
   */
  private send(payload: BeaconPayload): void {
    try {
      if (shouldSkip(payload.url, this.options.settings) !== null) return;
      if (!this.consent.allowsSending()) return;

      const body = JSON.stringify(this.decorate(payload));

      setTimeout(() => this.dispatch(body), 0);
    } catch {
      // 트래커는 어떤 경우에도 호출자에게 예외를 돌려주지 않는다.
    }
  }

  /**
   * 공통 필드를 채웁니다.
   *
   * @param payload 비콘 페이로드
   * @returns 채워진 페이로드
   */
  private decorate(payload: BeaconPayload): BeaconPayload {
    const decorated: BeaconPayload = {
      ...payload,
      res: `${window.screen?.width ?? 0}x${window.screen?.height ?? 0}`,
      lang: navigator.language,
      locale: this.options.locale,
      wd: isWebDriver(),
    };

    const hints = (navigator as Navigator & { userAgentData?: Record<string, unknown> }).userAgentData;
    if (hints) {
      decorated.ch = {
        brands: hints.brands,
        platform: hints.platform,
        mobile: hints.mobile,
      };
    }

    if (Object.keys(this.dimensions).length > 0) {
      decorated.dims = { ...this.dimensions };
    }

    return decorated;
  }

  /**
   * 실제 전송을 수행합니다.
   *
   * `keepalive` fetch 를 먼저 씁니다 — sendBeacon 은 헤더를 실을 수 없어 인증 토큰을
   * 못 보내고, 그러면 회원·관리자 방문이 전부 비회원으로 집계됩니다. 화면을 떠나는
   * 중에도 전송이 보장되는 성질은 둘이 같습니다.
   *
   * @param body 직렬화된 페이로드
   * @returns 없음
   */
  private dispatch(body: string): void {
    try {
      const token = this.options.getAuthToken?.();
      const headers: Record<string, string> = { 'Content-Type': 'application/json' };

      if (token) headers.Authorization = `Bearer ${token}`;

      if (typeof fetch === 'function' && 'keepalive' in new Request('')) {
        void fetch(COLLECT_ENDPOINT, {
          method: 'POST',
          keepalive: true,
          credentials: 'same-origin',
          headers,
          body,
        }).catch(() => {
          // 응답을 기다리지 않고 실패도 무시한다 — 429·5xx 모두 마찬가지다.
        });

        return;
      }

      navigator.sendBeacon?.(COLLECT_ENDPOINT, new Blob([body], { type: 'application/json' }));
    } catch {
      // 전송 실패가 화면에 드러나지 않는다.
    }
  }

  /**
   * 현재 주소를 반환합니다.
   *
   * @returns 현재 URL
   */
  private currentUrl(): string {
    return typeof window !== 'undefined' ? window.location.href : '';
  }
}
