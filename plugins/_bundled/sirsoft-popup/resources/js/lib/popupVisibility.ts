/**
 * 사용자 레이어 노출 판정 — 경로·기기·「보지 않기」·이번 페이지에서 닫음.
 *
 * 경로 규칙은 서버 `Plugins\Sirsoft\Popup\Support\RoutePath` 와 같다(PO 결정 #812 D2): 경로 경계 하위 포함 —
 * `/shop` 은 `/shop`·`/shop/…` 에 맞고 `/shopping` 에는 맞지 않는다. `/` 는 홈에만 맞는다. 대소문자는 구분한다.
 * 두 구현이 같은 표본에서 같은 결과를 내는지 양쪽 테스트가 잠근다.
 */
import type { PublicPopup } from './types';

/** 모바일로 보는 최대 폭(px) — 엔진 반응형 mobile 구간(0~767)과 같다. 태블릿은 PC 로 본다. */
export const MOBILE_MAX_WIDTH = 767;

/** 새로고침 전까지 「닫기」로 닫은 팝업 — 모듈 범위라 컴포넌트가 다시 마운트돼도 남는다. */
const closedThisPage = new Set<number>();

/**
 * 경로를 정규화합니다 — 앞뒤 공백을 지우고 끝 `/` 를 뗍니다(`/` 자체는 그대로).
 *
 * @param route 경로
 * @returns 정규화한 경로
 */
export function normalizeRoute(route: string): string {
  let value = route.trim();
  if (value !== '/' && value.endsWith('/')) {
    value = value.replace(/\/+$/, '');
    if (value === '') {
      value = '/';
    }
  }

  return value;
}

/**
 * 현재 주소에서 쿼리·해시를 뗍니다.
 *
 * @param path 현재 경로 (쿼리·해시가 붙어 있을 수 있다)
 * @returns 경로만
 */
export function stripQueryAndHash(path: string): string {
  const cut = path.search(/[?#]/);

  return cut === -1 ? path : path.slice(0, cut);
}

/**
 * 경로가 지정 경로에 맞는지 판정합니다.
 *
 * @param path 현재 경로
 * @param route 지정 경로
 * @returns 맞으면 true
 */
export function matchesRoute(path: string, route: string): boolean {
  const current = normalizeRoute(stripQueryAndHash(path) || '/');
  const target = normalizeRoute(route);

  if (target === '/') {
    return current === '/';
  }

  return current === target || current.startsWith(`${target}/`);
}

/**
 * 팝업이 그 경로에 보이는지 판정합니다(전체 페이지면 어디서나).
 *
 * @param popup 팝업
 * @param path 현재 경로
 * @returns 보이면 true
 */
export function matchesPage(popup: PublicPopup, path: string): boolean {
  if (popup.page_scope !== 'routes') {
    return true;
  }

  return (popup.routes ?? []).some((route) => matchesRoute(path, route));
}

/**
 * 팝업이 그 기기에 보이는지 판정합니다.
 *
 * @param popup 팝업
 * @param isMobile 모바일 폭인지
 * @returns 보이면 true
 */
export function matchesDevice(popup: PublicPopup, isMobile: boolean): boolean {
  if (popup.device === 'all') {
    return true;
  }

  return popup.device === (isMobile ? 'mobile' : 'pc');
}

/**
 * 「닫기」로 닫은 팝업을 이번 페이지 사용 동안 기억합니다.
 *
 * @param ids 닫은 팝업 ID
 */
export function rememberClosed(ids: number[]): void {
  ids.forEach((id) => closedThisPage.add(id));
}

/**
 * 이번 페이지에서 닫은 팝업인지 판정합니다.
 *
 * @param id 팝업 ID
 * @returns 닫았으면 true
 */
export function isClosedThisPage(id: number): boolean {
  return closedThisPage.has(id);
}

/**
 * 닫은 기록을 비웁니다(테스트용 — 새로고침과 같다).
 */
export function resetClosedThisPage(): void {
  closedThisPage.clear();
}

/**
 * 지금 보일 팝업을 서버 순서 그대로 고릅니다.
 *
 * @param popups 공개 API 팝업 (서버 순서)
 * @param context 현재 경로·모바일 여부·「보지 않기」 기록(id → 만료 epoch 초)·지금(epoch 초)
 * @returns 보일 팝업
 */
export function visiblePopups(
  popups: PublicPopup[],
  context: { path: string; isMobile: boolean; dismissed: Record<string, number>; nowSeconds: number },
): PublicPopup[] {
  return popups.filter((popup) => {
    const until = context.dismissed[String(popup.id)];

    return matchesPage(popup, context.path)
      && matchesDevice(popup, context.isMobile)
      && !(typeof until === 'number' && until > context.nowSeconds)
      && !isClosedThisPage(popup.id);
  });
}
