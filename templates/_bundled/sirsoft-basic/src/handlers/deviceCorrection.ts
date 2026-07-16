/**
 * 서버측 기기 감지값(appConfig.isIos)의 클라이언트 보정.
 *
 * 서버는 User-Agent 만으로 iOS 를 판정하므로, 데스크탑 UA 를 보내는 iPadOS 를
 * 놓칠 수 있다(iPad 에서 애플페이 버튼 미표시). 부트스트랩 시점에 클라이언트
 * 신호(maxTouchPoints/userAgentData.platform)로 iOS 를 재판정해, 서버가 false
 * 로 내려보낸 경우에만 전역 상태 `appConfig.isIos` 를 true 로 보정한다.
 *
 * 서버가 이미 true 로 판정했으면 그대로 두어(다운그레이드 방지) fail-safe 를 유지한다.
 */

const logger = ((window as any).G7Core?.createLogger?.('Template:sirsoft-basic:device'))
  ?? {
    log: (...args: unknown[]) => console.log('[Template:sirsoft-basic:device]', ...args),
    warn: (...args: unknown[]) => console.warn('[Template:sirsoft-basic:device]', ...args),
  };

/**
 * 클라이언트 신호로 iOS 기기 여부를 판정합니다.
 *
 * 과거 checkoutEasyPayInjector 의 isIosMobileDevice 판정과 동일한 규칙:
 * - UA/platform 에 iphone|ipad|ipod(+ios) 매칭
 * - 데스크탑 UA 를 보내는 iPadOS: macintosh UA + maxTouchPoints > 1
 *
 * @returns iOS 기기 여부
 */
export function isIosMobileDeviceClient(): boolean {
  if (typeof navigator === 'undefined') return false;

  const nav = navigator as Navigator & {
    userAgentData?: { platform?: string };
    maxTouchPoints?: number;
  };
  const ua = (nav.userAgent || '').toLowerCase();
  const platform = ((nav.userAgentData?.platform ?? nav.platform) || '').toLowerCase();
  const touchPoints = nav.maxTouchPoints ?? 0;

  if (/iphone|ipad|ipod/.test(ua) || /iphone|ipad|ipod|ios/.test(platform)) {
    return true;
  }

  return /macintosh|mac os x/.test(ua) && touchPoints > 1;
}

/**
 * 서버가 내려준 `appConfig.isIos` 를 클라이언트 판정으로 보정합니다.
 *
 * 서버 값이 false 이고 클라이언트가 iOS 로 판정한 경우에만 전역 상태를 갱신한다.
 * (iPadOS 데스크탑 UA 케이스 복구)
 */
export function correctIosDeviceState(): void {
  const G7Core = (window as any).G7Core;
  if (!G7Core?.state?.get || !G7Core?.state?.set) {
    logger.warn('G7Core.state not available; skip iOS device correction');
    return;
  }

  if (!isIosMobileDeviceClient()) {
    return;
  }

  const globalState = G7Core.state.get() || {};
  const appConfig = globalState.appConfig || {};

  // 서버가 이미 iOS 로 판정했으면 보정 불필요.
  if (appConfig.isIos === true) {
    return;
  }

  G7Core.state.set({ appConfig: { ...appConfig, isIos: true } });
  logger.log('appConfig.isIos corrected to true by client signals (iPadOS desktop-UA)');
}
