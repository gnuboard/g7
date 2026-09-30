/**
 * 동의 상태 브리지
 *
 * 동의 연동 모드에서 「지금 이 방문자가 분석 쿠키에 동의했는가」를 브라우저 쪽에서
 * 압니다. 서버도 매 요청에서 다시 확인하므로 이 판정은 **보내지 않아도 될 요청을
 * 미리 거르는 용도**이지 보안 경계가 아닙니다.
 *
 * 동의를 확인할 곳이 없으면(코어 동의 필터에 답하는 확장이 없으면) 막지 않고 그대로
 * 보냅니다 — 서버가 익명 모드로 내려가 개인정보 없이 수집하기 때문입니다.
 *
 * 코어는 동의를 관리하는 확장이 무엇인지 모릅니다. 상태는 코어 API 로 묻고, 변경은 코어 이름의
 * 이벤트로 받습니다. 동의 관리 확장은 동의를 저장한 뒤 이 이벤트를 발행합니다(계약:
 * `docs/extension/hooks.md` 「방문자 통계 동의 계약」).
 */

/** 동의 상태 조회 경로 (코어 API) */
const CONSENT_STATUS_URL = '/api/analytics/consent';

/**
 * 동의 변경 알림 이벤트 이름 (코어 소유).
 *
 * `detail.has_consent` 에 새 동의 여부(boolean)를 싣는다. 없으면 코어 API 로 다시 묻는다.
 */
export const ANALYTICS_CONSENT_CHANGED_EVENT = 'g7:analytics-permission-changed';

/** 동의 상태 */
export interface ConsentState {
  /** 동의를 확인할 곳이 있는지 */
  source: 'provider' | 'none';
  /** 분석 쿠키 동의 여부 (확인할 곳이 없으면 null) */
  granted: boolean | null;
}

/**
 * 동의 상태를 지켜봅니다.
 *
 * 부팅 때 한 번 읽고, 그 뒤로는 동의 화면이 보내는 변경 알림으로 갱신합니다.
 */
/**
 * 동의 확인이 전송의 전제가 되는지 판정합니다.
 *
 * 동의를 확인할 곳이 있어도 수집 모드가 「동의 연동」이 아니면 묻지 않습니다 —
 * IP 저장·익명 모드는 동의를 게이트로 쓰지 않는다는 것이 운영자의 결정이고, 그런
 * 사이트에서 동의 조회를 기다리면 미동의 방문자의 화면 이동이 통째로 빠집니다.
 *
 * @param pluginActive 동의를 확인할 곳이 있는지 (서버가 인라인한 `consent_provider`)
 * @param privacyMode 수집 모드
 * @returns 동의 확인이 필요하면 true
 */
export function consentGateRequired(pluginActive: boolean, privacyMode?: string): boolean {
  return pluginActive && privacyMode === 'consent';
}

export class ConsentBridge {
  /** 현재 동의 상태 */
  private state: ConsentState = { source: 'none', granted: null };

  /** 첫 조회가 끝났는가 (실패로 끝나도 true — 그 뒤의 「모름」은 대기가 아니라 미동의로 다룬다) */
  private settled = false;

  /**
   * 동의 브리지를 만듭니다.
   *
   * @param pluginActive 동의를 확인할 곳이 있는지
   * @param getAuthToken 로그인 토큰을 돌려주는 함수 (없으면 게스트로 조회)
   */
  constructor(
    private readonly pluginActive: boolean,
    private readonly getAuthToken?: () => string | null,
  ) {
    if (pluginActive) {
      this.state = { source: 'provider', granted: null };
    }
  }

  /**
   * 동의 상태를 읽어 오고 변경 알림을 구독합니다.
   *
   * 실패해도 예외를 밖으로 내보내지 않습니다 — 동의를 확인하지 못한 것이 화면을
   * 망가뜨리면 안 됩니다.
   *
   * @returns 완료 Promise
   */
  async start(): Promise<void> {
    if (!this.pluginActive) return;

    await this.refresh();

    this.settled = true;

    if (typeof window !== 'undefined') {
      window.addEventListener(ANALYTICS_CONSENT_CHANGED_EVENT, this.handleChange);
    }
  }

  /**
   * 코어 API 로 동의 여부를 다시 읽습니다.
   *
   * @returns 완료 Promise
   */
  private async refresh(): Promise<void> {
    try {
      // 회원의 동의는 회원 번호로 저장된다. 토큰을 싣지 않으면 서버가 게스트 세션으로
      // 조회해, 동의한 회원도 「미동의」로 읽혀 그 방문이 통째로 빠진다.
      const token = this.getAuthToken?.();
      const headers: Record<string, string> = { Accept: 'application/json' };

      if (token) headers.Authorization = `Bearer ${token}`;

      const response = await fetch(CONSENT_STATUS_URL, {
        credentials: 'same-origin',
        headers,
      });

      if (response.ok) {
        const body = await response.json();
        this.state.granted = (body?.data?.has_consent ?? body?.has_consent) === true;
      }
    } catch {
      // 조회 실패는 「모름」이다 — 동의했다고 단정하지 않는다.
    }
  }

  /**
   * 구독을 해제합니다.
   *
   * @returns 없음
   */
  stop(): void {
    if (typeof window !== 'undefined') {
      window.removeEventListener(ANALYTICS_CONSENT_CHANGED_EVENT, this.handleChange);
    }
  }

  /**
   * 지금 보내도 되는지 판정합니다.
   *
   * @returns 전송 가능 여부
   */
  allowsSending(): boolean {
    // 확인할 곳이 없으면 막지 않는다 — 서버가 익명 모드로 처리한다.
    if (this.state.source === 'none') return true;

    // 아직 못 읽었으면 보내지 않는다. 동의 여부를 모르는 채 모으지 않는다.
    return this.state.granted === true;
  }

  /**
   * 동의 여부를 아직 읽지 못했는지 판정합니다.
   *
   * 확인할 곳이 있는데 조회가 끝나지 않은 상태다. 이때 온 비콘은 버리지 말고 조회가 끝날 때까지
   * 들고 있어야 한다 — 첫 화면 비콘은 조회보다 먼저 나가므로, 버리면 동의한 방문자의 진입
   * 화면·새로고침이 전부 빠진다(#682 S3 라운드 13).
   *
   * @returns 조회 대기 중이면 true
   */
  isPending(): boolean {
    return this.state.source === 'provider' && this.state.granted === null && !this.settled;
  }

  /**
   * 현재 동의 상태를 반환합니다.
   *
   * @returns 동의 상태
   */
  current(): ConsentState {
    return { ...this.state };
  }

  /**
   * 동의 변경 알림을 반영합니다.
   *
   * @param event 동의 변경 이벤트
   * @returns 없음
   */
  private handleChange = (event: Event): void => {
    const detail = (event as CustomEvent).detail ?? {};

    // 코어 계약의 모양은 `{ has_consent: boolean }` 하나다. 값을 싣지 않은 알림이면 코어 API 로
    // 다시 묻는다 — 모양을 추측해 읽으면 동의 관리 확장의 내부 형식에 코어가 묶인다.
    if (typeof detail?.has_consent === 'boolean') {
      this.state.granted = detail.has_consent;

      return;
    }

    void this.refresh();
  };
}
