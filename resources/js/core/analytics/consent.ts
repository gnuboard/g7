/**
 * 동의 상태 브리지
 *
 * 동의 연동 모드에서 「지금 이 방문자가 분석 쿠키에 동의했는가」를 브라우저 쪽에서 압니다. 서버도 매
 * 요청에서 다시 확인하므로 이 판정은 **보내지 않아도 될 요청을 미리 거르는 용도**이지 보안 경계가 아닙니다.
 *
 * 판정 출처는 코어 동의 런타임(`resources/js/core/consent`)입니다 — 방문자 통계는 범용 방문자 동의 공개
 * 계약의 소비자이며, 자체 조회·자체 이벤트를 두지 않습니다(#639).
 *
 * - 스냅샷 `none`(동의 관리 제공자 없음): 막지 않는다 — 서버가 익명 모드로 내려가 개인정보 없이 수집한다.
 * - `ready`: 분석 동의 = 재동의 대상 아님 && `analytics`.
 * - `unknown`: 현재 세대의 첫 판정 전(부팅 직후·로그인/로그아웃 직후)이면 비콘을 들고 기다리고, 판정이
 *   끝난 뒤에도 `unknown` 이면 동의하지 않은 것으로 본다.
 */

/** 브리지가 읽는 동의 스냅샷 모양 (코어 런타임의 공개 계약) */
export interface ConsentSnapshotLike {
  status: 'unknown' | 'ready' | 'none';
  categories: Record<string, boolean>;
  needsRenewal: boolean;
}

/** 브리지가 쓰는 동의 출처 (코어 동의 런타임) */
export interface ConsentSource {
  /** 현재 스냅샷 */
  getSnapshot(): ConsentSnapshotLike;
  /** 현재 세대의 첫 조회가 끝나지 않았는지 */
  isPending(): boolean;
  /** 조회 완료 구독 (해제 함수 반환) */
  onSettled(listener: (snapshot: ConsentSnapshotLike) => void): () => void;
}

/** 동의 상태 */
export interface ConsentState {
  /** 동의를 확인할 곳이 있는지 */
  source: 'provider' | 'none';
  /** 분석 쿠키 동의 여부 (확인할 곳이 없거나 아직 모르면 null) */
  granted: boolean | null;
}

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

/**
 * 스냅샷에서 분석 동의를 파생합니다 (서버 `ConsentSnapshot::permitsAnalytics()` 와 같은 규칙).
 *
 * @param snapshot 스냅샷
 * @returns 분석 동의 여부
 */
export function permitsAnalytics(snapshot: ConsentSnapshotLike): boolean {
  return snapshot.status === 'ready' && !snapshot.needsRenewal && snapshot.categories?.analytics === true;
}

export class ConsentBridge {
  /** 조회 완료 구독 해제 함수 */
  private offSettled: (() => void) | null = null;

  /**
   * 동의 브리지를 만듭니다.
   *
   * @param gateRequired 동의 확인이 전송의 전제인지 (동의 연동 모드 + 동의 관리 제공자 있음)
   * @param source 코어 동의 런타임 (없으면 게이트가 서도 판정할 곳이 없어 보내지 않는다)
   * @param onSettled 판정이 끝날 때마다 부르는 함수 (들고 있던 비콘을 내보내거나 버리게 한다)
   */
  constructor(
    private readonly gateRequired: boolean,
    private readonly source: ConsentSource | null = null,
    private readonly onSettled?: () => void,
  ) {}

  /**
   * 조회 완료를 구독합니다. 이미 판정이 끝나 있으면 곧바로 알립니다.
   *
   * @returns 완료 Promise
   */
  async start(): Promise<void> {
    if (!this.gateRequired || !this.source || this.offSettled) return;

    this.offSettled = this.source.onSettled(() => this.onSettled?.());

    if (!this.source.isPending()) this.onSettled?.();
  }

  /**
   * 구독을 해제합니다.
   *
   * @returns 없음
   */
  stop(): void {
    this.offSettled?.();
    this.offSettled = null;
  }

  /**
   * 지금 보내도 되는지 판정합니다.
   *
   * @returns 전송 가능 여부
   */
  allowsSending(): boolean {
    if (!this.gateRequired) return true;
    if (!this.source) return false;

    const snapshot = this.source.getSnapshot();

    // 확인할 곳이 없으면 막지 않는다 — 서버가 익명 모드로 처리한다.
    if (snapshot.status === 'none') return true;

    return permitsAnalytics(snapshot);
  }

  /**
   * 동의 여부를 아직 판정하지 못했는지 반환합니다.
   *
   * 부팅 직후와 로그인·로그아웃 직후다. 이때 온 비콘은 버리지 말고 판정이 끝날 때까지 들고 있어야 한다 —
   * 첫 화면 비콘은 조회보다 먼저 나가고, 로그인 직후 화면 이동의 비콘은 재조회보다 먼저 나간다.
   *
   * @returns 판정 대기 중이면 true
   */
  isPending(): boolean {
    return this.gateRequired && this.source !== null && this.source.isPending();
  }

  /**
   * 현재 동의 상태를 반환합니다.
   *
   * @returns 동의 상태
   */
  current(): ConsentState {
    if (!this.gateRequired || !this.source) return { source: 'none', granted: null };

    const snapshot = this.source.getSnapshot();
    if (snapshot.status === 'none') return { source: 'none', granted: null };
    if (snapshot.status === 'unknown') return { source: 'provider', granted: null };

    return { source: 'provider', granted: permitsAnalytics(snapshot) };
  }
}
