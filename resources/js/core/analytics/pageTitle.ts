/**
 * 페이지 제목 판정
 *
 * 통계 화면의 「페이지 제목」 목록에 들어갈 값을 정합니다.
 *
 * G7 은 SPA 라 `document.title` 을 쓰는 코드가 없습니다 — 화면 제목은 라우트 메타에
 * 번역 키로 들어 있습니다. 그래서 제목을 얻는 순서가 다른 사이트와 다릅니다.
 */

/** 라우트에서 제목 판정에 필요한 부분만 */
export interface TitleSource {
  /** 라우트 메타 (제목이 번역 키로 들어 있다) */
  meta?: { title?: string };
  /** 라우트 경로 */
  path?: string;
}

/**
 * 이 화면의 제목을 정합니다.
 *
 * 순서: 라우트 메타의 번역 키 → 메타의 평문 → `document.title` → 경로.
 * 어느 것도 없으면 빈 문자열이 아니라 경로를 남깁니다 — 제목이 빈 행은 화면에서
 * 무엇을 가리키는지 알 수 없습니다.
 *
 * @param route 현재 라우트
 * @param translate 번역 함수 (`$t:` 키를 사람이 읽는 문구로 바꾼다)
 * @returns 페이지 제목
 */
export function resolvePageTitle(
  route: TitleSource | null | undefined,
  translate?: (key: string) => string,
): string {
  const metaTitle = route?.meta?.title;

  if (typeof metaTitle === 'string' && metaTitle !== '') {
    if (metaTitle.startsWith('$t:')) {
      const key = metaTitle.slice(3);
      const translated = translate?.(key);

      // 번역이 없으면 번역기가 키를 그대로 돌려준다 — 그 경우는 키를 보여 주지 않는다.
      if (translated && translated !== key) return translated;
    } else {
      return metaTitle;
    }
  }

  if (typeof document !== 'undefined' && document.title) return document.title;

  return route?.path ?? (typeof window !== 'undefined' ? window.location.pathname : '');
}
