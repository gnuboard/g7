/**
 * @file core.ts
 * @description 컴포넌트 테스트용 G7Core 대역 — `t()` 는 플러그인 ko.json 을 읽어 실제 화면 문구를 돌려준다.
 */
import ko from '../../../lang/ko.json';

/** 플러그인 번역 키 접두 */
const PREFIX = 'sirsoft-popup.';

/**
 * ko.json 에서 번역 문구를 찾습니다(없으면 키).
 *
 * @param key 전체 번역 키 (`sirsoft-popup.…`)
 * @param params 치환 값 (`{{name}}`)
 * @returns 문구
 */
export function koT(key: string, params?: Record<string, string | number>): string {
  const found = key.startsWith(PREFIX)
    ? key.slice(PREFIX.length).split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], ko)
    : undefined;
  if (typeof found !== 'string') {
    return key;
  }

  return Object.entries(params ?? {}).reduce((text, [name, value]) => text.split(`{{${name}}}`).join(String(value)), found);
}

/**
 * window.G7Core 대역을 설치합니다.
 *
 * @param extra 더할 멤버 (dispatch 등)
 */
export function installCore(extra: Record<string, unknown> = {}): void {
  (window as any).G7Core = { t: koT, ...extra };
}

/**
 * 창 폭을 정합니다 — 레이어는 엔진 훅이 없을 때 `window.innerWidth` 로 기기를 판정한다.
 *
 * @param mobile 모바일 폭(375)이면 true, 아니면 데스크톱 폭(1280)
 */
export function setViewportMobile(mobile: boolean): void {
  Object.defineProperty(window, 'innerWidth', { value: mobile ? 375 : 1280, configurable: true, writable: true });
}
