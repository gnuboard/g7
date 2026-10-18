/**
 * 방문자 통계 트래커
 *
 * 화면 이동·내려받기·외부 링크를 서버에 알립니다.
 *
 * **이 트래커는 사이트 동작에 어떤 영향도 주지 않습니다.** 전송은 예외를 전부 삼키고,
 * 응답을 기다리지 않으며, 화면·상태·라우팅에 아무 값도 돌려주지 않습니다. 통계가
 * 실패했다고 방문자가 보던 화면이 멈추거나 느려지면 안 됩니다.
 */

import { ConsentBridge, consentGateRequired, type ConsentSource } from './consent';
import { type AnalyticsClientSettings, isPrerendering, isWebDriver, shouldSkip } from './exclusions';
import { resolvePageTitle, type TitleSource } from './pageTitle';

/** 수집 경로 */
const COLLECT_ENDPOINT = '/api/analytics/collect';

/** 수집 제외 토글 주소 — 서버가 `g7_analytics_optout` 쿠키를 심고 지운다. */
const OPT_OUT_ENDPOINT = '/api/admin/analytics/opt-out';

/** 직전 페이지 체류 시간 상한 (초) — 서버도 같은 값으로 자른다 */
const MAX_TIME_SPENT_SECONDS = 1800;

/** 같은 화면으로의 되풀이 이동을 한 번으로 보는 창 (ms) — 링크 클릭 중복 억제와 같은 값 */
const PAGEVIEW_DEDUPE_WINDOW_MS = 1000;

/**
 * 동의 조회가 끝나기 전에 들고 있을 비콘 수의 상한.
 *
 * 조회는 부팅 직후 한 번이라 보통 첫 화면 1건뿐이다. 조회가 오래 걸려도 무한히 쌓지 않는다.
 */
const MAX_HELD_BEACONS = 5;

/**
 * 서버 수집 요청 검증(`CollectRequest`)의 길이 상한.
 *
 * 한 필드라도 넘으면 서버는 **비콘 전체를** 검증 실패로 버린다(긴 제목 하나 때문에 그 화면
 * 열람이 통째로 빠진다). 그래서 보내기 전에 여기서 맞춘다 — 글자 수는 서버와 같이 코드
 * 포인트로 센다. 서버 규칙을 바꾸면 이 값도 같이 바꾼다.
 */
const SERVER_LIMITS = {
  url: 2048,
  title: 512,
  ref: 1024,
  target: 1024,
  name: 255,
  dims: 20,
  dimValue: 255,
} as const;

/** 미리 그리던 화면이 실제로 열렸음을 알리는 이벤트 (구형 표기는 visibilitychange 로 온다) */
const ACTIVATION_EVENTS = ['prerenderingchange', 'visibilitychange'] as const;

/**
 * 문자열을 코드 포인트 기준으로 자릅니다.
 *
 * UTF-16 단위로 자르면 이모지 같은 서로게이트 쌍이 반쪽 나고, 서버의 JSON 해석이 그 반쪽 때문에
 * 요청 전체를 거절한다. 서버의 `max` 도 코드 포인트(`mb_strlen`)로 센다.
 *
 * @param value 원본 문자열
 * @param max 최대 글자 수
 * @returns 잘린 문자열
 */
function clip(value: string, max: number): string {
  // UTF-16 길이는 코드 포인트 수보다 작지 않다 — 이 안이면 자를 필요가 없다.
  if (value.length <= max) return value;

  return Array.from(value).slice(0, max).join('');
}

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
  /** 동의 관리 제공자가 있는지 (서버가 인라인한 `consent_provider`) */
  consentPluginActive?: boolean;
  /** 코어 동의 런타임 — 동의 연동 모드의 판정 출처 */
  consentSource?: ConsentSource | null;
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

  /** 직전 페이지뷰의 화면 키 (주소 + 라우트 경로) */
  private lastPageviewKey: string | null = null;

  /** 확장이 넣어 둔 차원 값 */
  private dimensions: Record<string, string> = {};

  /** 동의 조회가 끝나기 전에 온 비콘(방문 URL + 직렬화본) — 조회가 끝나면 동의일 때만 보낸다 */
  private held: Array<{ url: string; body: string }> = [];

  /**
   * 미리 그리는 동안 온 첫 화면 열람 (없으면 undefined).
   *
   * 미리 그린 화면은 사람이 보기 전이라 세지 않지만, 방문자가 그 화면을 실제로 열면 그때가
   * 랜딩이다. 버리면 그 방문의 첫 화면이 브라우저·서버 어디에도 남지 않는다.
   */
  private deferredPageview: { route: TitleSource | null } | undefined = undefined;

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
      options.consentSource ?? null,
      () => this.flushHeld(),
    );
  }

  /**
   * 동의 상태를 읽고 구독을 시작합니다.
   *
   * @returns 완료 Promise
   */
  async start(): Promise<void> {
    // 판정이 끝날 때마다(부팅 직후·로그인/로그아웃 직후) 브리지가 flushHeld 를 부른다.
    await this.consent.start();
  }

  /**
   * 구독을 해제합니다.
   *
   * @returns 없음
   */
  stop(): void {
    this.consent.stop();
    this.unwatchActivation();
    this.deferredPageview = undefined;
  }

  /**
   * 화면 열람을 알립니다.
   *
   * @param route 현재 라우트
   * @returns 없음
   */
  trackPageview(route: TitleSource | null): void {
    // 미리 그리는 중이면 세지도, 상태를 바꾸지도 않고 들고 있다가 실제로 열릴 때 보낸다 —
    // 여기서 첫 비콘 표식·체류 기준 시각을 먼저 잡으면 열린 뒤의 첫 비콘이 init 없이 나간다.
    if (this.deferIfPrerendering(route)) return;

    const now = Date.now();

    // 같은 화면으로의 되풀이 이동(메뉴 더블클릭 등)이 창 안에 다시 오면 한 번으로 센다 —
    // 라우터는 같은 주소로의 이동도 매번 알리므로 그대로 보내면 한 번 본 화면이 2건이 된다.
    const key = `${this.currentUrl()}|${route?.path ?? ''}`;
    if (this.lastPageviewKey === key
      && this.lastPageviewAt !== null
      && now - this.lastPageviewAt < PAGEVIEW_DEDUPE_WINDOW_MS) {
      return;
    }
    this.lastPageviewKey = key;

    const prev = this.lastPageviewAt === null
      ? undefined
      : Math.min(MAX_TIME_SPENT_SECONDS, Math.round((now - this.lastPageviewAt) / 1000));

    const isInitial = !this.initialSent;
    this.initialSent = true;
    this.lastPageviewAt = now;

    // 차원 값은 그 값을 넣은 화면의 비콘에만 싣는다 — 다른 화면으로 넘어가면 비운다. 비우지 않으면
    // 게시판에서 넣은 board_id 가 홈·다른 메뉴의 조회에도 붙어 그 게시판 수치가 부푼다. 방문 범위
    // 차원은 서버가 방문에 이미 남겼으므로 여기서 비워도 잃지 않는다. 새 화면이 넣는 값은 레이아웃이
    // 그려진 뒤(이 페이지뷰 다음)에 오므로 지워지지 않는다.
    if (!isInitial) this.dimensions = {};

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
   * 이미 받아 둔 내용을 임시 링크로 저장시키는 `blob:`·`data:` 주소는 이 탭 안에서만 뜻이 있다.
   * 서버는 URL 이 아니라며 거절하고(게시판 첨부처럼 파일을 받아 blob 으로 저장시키는 화면에서는
   * 내려받기가 통째로 빠진다) 리포트에도 쓸 데가 없으므로 주소 없이 표시 이름만 보낸다.
   *
   * @param url 파일 주소
   * @param name 표시 이름
   * @returns 없음
   */
  trackDownload(url: string, name?: string): void {
    if (this.options.settings.track_downloads === false) return;

    const target = /^(blob|data):/i.test(url) ? undefined : url;

    this.send({ t: 'download', url: this.currentUrl(), target, name });
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
   * **이벤트 이름은 저장·집계되고 속성은 저장되지 않습니다.** 방문 타임라인에 그 이름으로 한 줄이
   * 남고 「이벤트」 리포트가 이름별로 셉니다(하루 500종, 이름 255자). 이름 없이 보내면 그 화면 주소로
   * 남고 리포트에는 들지 않습니다. 정해진 뜻으로 세고 싶은 행동은 목표로 선언해 {@link trackGoal} 을,
   * 행동을 가르는 값은 커스텀 차원으로 선언해 {@link setDimension} 을 씁니다(docs/frontend/analytics-tracker.md).
   *
   * @param name 이벤트 이름 (저장·집계됨)
   * @param attributes 이벤트 속성 (저장되지 않음)
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
    // 값은 다음 화면 이동 때 비워진다(trackPageview) — 확장은 화면마다 다시 넣는다.
    if (!key) return;

    this.dimensions[key] = String(value);
  }

  /**
   * 지금 이 화면이 **수집 제외 쿠키 때문에** 세어지지 않는지 확인합니다.
   *
   * 판정은 보내기 전 제외 판정과 같은 순서를 따르고, 쿠키가 **결정 사유일 때만** true 입니다.
   * 순서상 앞선 사유(수집 꺼짐·미리 그리기·추적 거부)가 먼저 걸리면 쿠키가 있어도 false 를
   * 돌려줍니다. 「이 브라우저가 제외를 선택했는가」 자체를 묻는 용도로 쓰지 않습니다.
   *
   * @returns 제외 쿠키가 결정 사유이면 true
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

      // 동의를 아직 못 읽었으면 버리지 않고 들고 있는다 — 첫 화면 비콘은 동의 조회보다 먼저
      // 나가서, 버리면 동의한 방문자의 진입 화면·새로고침이 전부 빠진다(#682 S3 라운드 13).
      if (this.consent.isPending()) {
        if (this.held.length < MAX_HELD_BEACONS) {
          this.held.push({ url: payload.url, body: JSON.stringify(this.decorate(payload)) });
        }

        return;
      }

      if (!this.consent.allowsSending()) return;

      const body = JSON.stringify(this.decorate(payload));

      setTimeout(() => this.dispatch(body), 0);
    } catch {
      // 트래커는 어떤 경우에도 호출자에게 예외를 돌려주지 않는다.
    }
  }

  /**
   * 동의 조회가 끝난 뒤 들고 있던 비콘을 처리합니다.
   *
   * 동의로 확인되면 보내고, 미동의·조회 실패면 버립니다. 들고 있는 사이에 방문자가 수집
   * 제외를 고르거나 추적 거부가 켜졌으면 그 비콘도 버립니다 — 보낼 때의 판정이 기준이다.
   *
   * @returns 없음
   */
  private flushHeld(): void {
    const held = this.held.splice(0);

    if (held.length === 0 || !this.consent.allowsSending()) return;

    for (const { url, body } of held) {
      if (shouldSkip(url, this.options.settings) !== null) continue;

      this.dispatch(body);
    }
  }

  /**
   * 미리 그리는 중이면 화면 열람을 실제로 열릴 때까지 미룹니다.
   *
   * 운영자가 미리 가져오기 제외를 껐으면(`exclude_prefetch: false`) 미루지 않습니다 — 그때는
   * 미리 그린 화면도 세는 것이 운영자의 결정입니다. 여러 번 오면 마지막 화면만 남깁니다.
   *
   * @param route 현재 라우트
   * @returns 미뤘으면 true
   */
  private deferIfPrerendering(route: TitleSource | null): boolean {
    try {
      if (shouldSkip(this.currentUrl(), this.options.settings) !== 'prefetch') return false;

      if (this.deferredPageview === undefined) {
        for (const name of ACTIVATION_EVENTS) {
          document.addEventListener(name, this.handleActivation);
        }
      }

      this.deferredPageview = { route };

      return true;
    } catch {
      return false;
    }
  }

  /**
   * 미리 그리던 화면이 실제로 열리면 미뤄 둔 첫 화면 열람을 보냅니다.
   *
   * @returns 없음
   */
  private handleActivation = (): void => {
    try {
      if (this.deferredPageview === undefined || isPrerendering()) return;

      const { route } = this.deferredPageview;
      this.deferredPageview = undefined;
      this.unwatchActivation();

      this.trackPageview(route);
    } catch {
      // 트래커는 어떤 경우에도 호출자에게 예외를 돌려주지 않는다.
    }
  };

  /**
   * 열림 알림 구독을 해제합니다.
   *
   * @returns 없음
   */
  private unwatchActivation(): void {
    if (typeof document === 'undefined') return;

    for (const name of ACTIVATION_EVENTS) {
      document.removeEventListener(name, this.handleActivation);
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

    return this.fitToServerLimits(decorated);
  }

  /**
   * 서버 검증 상한을 넘는 필드를 맞춥니다.
   *
   * 표시용 글(제목·이름·차원 값)은 자르고, 주소(유입·대상)는 자르면 다른 주소가 되므로
   * 넘으면 뺍니다. 방문 URL 은 필수라 빼지 못해 쿼리·조각을 먼저 떼고, 그래도 길면 자릅니다.
   * 차원은 서버가 받는 개수까지만 남깁니다(먼저 넣은 순서).
   *
   * @param payload 채워진 페이로드
   * @returns 상한 안으로 맞춘 페이로드
   */
  private fitToServerLimits(payload: BeaconPayload): BeaconPayload {
    const fitted: BeaconPayload = { ...payload };

    if (Array.from(fitted.url).length > SERVER_LIMITS.url) {
      let url = fitted.url;
      try {
        const parsed = new URL(url);
        url = `${parsed.origin}${parsed.pathname}`;
      } catch {
        // 주소로 못 읽으면 그대로 자른다.
      }
      // 퍼센트 인코딩 중간에서 끊기면 서버가 URL 형식이 아니라며 거절한다.
      fitted.url = clip(url, SERVER_LIMITS.url).replace(/%[0-9A-Fa-f]?$/, '');
    }

    if (typeof fitted.title === 'string') fitted.title = clip(fitted.title, SERVER_LIMITS.title);
    if (typeof fitted.name === 'string') fitted.name = clip(fitted.name, SERVER_LIMITS.name);

    if (typeof fitted.ref === 'string' && Array.from(fitted.ref).length > SERVER_LIMITS.ref) {
      delete fitted.ref;
    }

    if (typeof fitted.target === 'string' && Array.from(fitted.target).length > SERVER_LIMITS.target) {
      delete fitted.target;
    }

    if (fitted.dims) {
      fitted.dims = Object.fromEntries(
        Object.entries(fitted.dims)
          .slice(0, SERVER_LIMITS.dims)
          .map(([key, value]) => [key, clip(value, SERVER_LIMITS.dimValue)]),
      );
    }

    return fitted;
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
