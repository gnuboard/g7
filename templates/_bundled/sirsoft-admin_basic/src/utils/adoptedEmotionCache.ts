/**
 * emotion 캐시를 생성형 스타일시트에 싣는다 — 스타일 정책을 강제해도 react-select 배치 규칙이 적용되게.
 *
 * react-select 는 emotion 으로 배치 규칙(목록 `position:absolute`, 화면 낭독용 안내 문구 `clip` 숨김, 칸 `display:flex` 등)을
 * 런타임에 넣는다. emotion 기본 캐시(`@emotion/sheet`)는 `<style data-emotion>` 요소를 `<head>` 에 만들고, 스타일 정책
 * (기본 `style-src-elem 'self'`, 엄격 `style-src 'self'; style-src-attr 'none'`)이 그 요소를 막는다. 그래서 목록이
 * 문서 흐름에 펼쳐지고(1438×1606 실측) 안내 문구가 칸 위에 그대로 보였다.
 *
 * 생성형 스타일시트(`new CSSStyleSheet()` + `document.adoptedStyleSheets`)는 CSSOM 대입이라 이 정책에 걸리지 않는다 —
 * 코어 `RuntimeStyleSheet`(`G7Core.style.setRules`)와 같은 경로다. 코어 API 는 규칙 묶음을 통째로 바꾸는 계약이라,
 * 규칙을 하나씩 더하는 emotion 시트는 여기서 같은 방식(배열을 덮어쓰지 않고 자기 시트만 더한다)으로 직접 구현한다.
 *
 * - emotion 은 규칙마다 `cache.sheet.insert(rule)` 을 부른다. 그 `sheet` 를 생성형 시트에 `insertRule` 하는 객체로 바꾼다.
 *   `<style>` 요소는 만들지 않는다(emotion 기본 시트는 첫 규칙을 넣을 때 요소를 만든다 — 바꿔 끼운 뒤라 만들지 않는다).
 * - react-select 의 React `style` 속성(입력칸 너비 등)은 React 가 `el.style.x = …` CSSOM 으로 대입하므로 `style-src-attr` 에
 *   걸리지 않는다.
 * - 생성형 시트를 지원하지 않는 환경(구형 브라우저)이면 null — 호출하는 쪽은 emotion 기본 캐시로 그린다(종전과 같다).
 *
 * @since sirsoft-admin_basic 1.2.0
 */
import createCache from '@emotion/cache';
import type { EmotionCache } from '@emotion/cache';

/** 태그 입력(react-select) emotion 캐시 키 — 클래스 이름 앞글자(`tag-css-…`)가 된다. emotion 키는 소문자와 `-` 만 받는다. */
export const TAG_INPUT_EMOTION_KEY = 'tag-css';

/**
 * 생성형 스타일시트를 쓸 수 있는 환경인지 판정합니다.
 *
 * @returns 쓸 수 있으면 true
 */
function supportsAdopted(): boolean {
  try {
    return typeof document !== 'undefined'
      && typeof CSSStyleSheet === 'function'
      && typeof (CSSStyleSheet.prototype as { insertRule?: unknown }).insertRule === 'function'
      && Array.isArray((document as { adoptedStyleSheets?: unknown }).adoptedStyleSheets);
  } catch {
    return false;
  }
}

/**
 * emotion `StyleSheet` 과 같은 모양으로, 규칙을 생성형 스타일시트에 넣는 시트.
 *
 * emotion 이 읽는 필드(`key`·`nonce`·`container`·`isSpeedy`·`ctr`·`tags`·`before`·`prepend`·`insertionPoint`)와 메서드
 * (`insert`·`flush`·`hydrate`)를 갖춘다. `tags` 는 늘 빈 배열이다 — 요소를 만들지 않는다.
 */
export class AdoptedEmotionSheet {
  key: string;
  nonce: string | undefined;
  container: Node;
  isSpeedy = true;
  ctr = 0;
  tags: HTMLStyleElement[] = [];
  before: Element | null = null;
  prepend: boolean | undefined = undefined;
  insertionPoint: HTMLElement | undefined = undefined;
  private sheet: CSSStyleSheet | null = null;

  /**
   * @param options emotion `StyleSheet` 생성자와 같은 옵션(쓰는 것은 key·nonce·container) — emotion `Global` 이
   *   `new cache.sheet.constructor(...)` 로 시트를 하나 더 만들 때도 같은 경로를 탄다
   */
  constructor(options: { key: string; nonce?: string; container?: Node }) {
    this.key = options.key;
    this.nonce = options.nonce;
    this.container = options.container ?? document.head;
  }

  /**
   * 생성형 시트를 돌려줍니다. 없으면 만들고, 문서에서 빠져 있으면(다른 코드가 배열을 통째로 덮어씀) 다시 붙입니다.
   * 배열을 덮어쓰지 않고 자기 시트만 더해 남이 넣은 시트를 보존합니다.
   *
   * @returns 생성형 시트
   */
  private ensure(): CSSStyleSheet {
    if (!this.sheet) {
      this.sheet = new CSSStyleSheet();
    }
    const adopted = document.adoptedStyleSheets;
    if (!adopted.includes(this.sheet)) {
      document.adoptedStyleSheets = [...adopted, this.sheet];
    }

    return this.sheet;
  }

  /**
   * 규칙 하나를 시트 끝에 넣습니다. 브라우저가 모르는 접두 선택자(`::-moz-…` 등)는 emotion speedy 모드처럼 건너뜁니다.
   *
   * @param rule CSS 규칙 한 개
   * @returns 반환값 없음
   */
  insert(rule: string): void {
    const sheet = this.ensure();
    try {
      sheet.insertRule(rule, sheet.cssRules.length);
    } catch {
      // 이 브라우저가 해석하지 못하는 규칙 — 다른 브라우저용 접두 규칙이라 건너뛴다
    }
    this.ctr++;
  }

  /**
   * 넣은 규칙을 모두 걷고 시트를 문서에서 뗍니다. 남이 넣은 시트는 그대로 둡니다.
   *
   * @returns 반환값 없음
   */
  flush(): void {
    const sheet = this.sheet;
    if (sheet) {
      document.adoptedStyleSheets = document.adoptedStyleSheets.filter((item) => item !== sheet);
      this.sheet = null;
    }
    this.ctr = 0;
  }

  /**
   * 서버가 그린 `<style>` 을 넘겨받는 자리 — 관리자 화면은 클라이언트에서만 그려 넘겨받을 것이 없습니다.
   *
   * @returns 반환값 없음
   */
  hydrate(): void {
    // 서버 렌더링 없음
  }
}

/**
 * 규칙을 생성형 스타일시트에 싣는 emotion 캐시를 만듭니다.
 *
 * @param key emotion 캐시 키(소문자와 `-`) — 클래스 이름 앞글자가 된다
 * @returns 캐시. 생성형 시트를 지원하지 않는 환경이면 null(호출하는 쪽은 emotion 기본 캐시를 쓴다)
 */
export function createAdoptedEmotionCache(key: string): EmotionCache | null {
  if (!supportsAdopted()) return null;

  const cache = createCache({ key });
  // createCache 가 만든 기본 시트는 아직 요소를 만들지 않았다(첫 insert 때 만든다) — 첫 규칙 전에 바꿔 끼운다
  (cache as { sheet: unknown }).sheet = new AdoptedEmotionSheet({ key });

  return cache;
}

let tagInputCache: EmotionCache | null | undefined;

/**
 * 태그 입력이 함께 쓰는 emotion 캐시(한 생성형 시트) — 처음 부를 때 만들고 이후 같은 것을 돌려줍니다.
 *
 * @returns 캐시. 생성형 시트를 지원하지 않는 환경이면 null
 */
export function getTagInputEmotionCache(): EmotionCache | null {
  if (tagInputCache === undefined) {
    tagInputCache = createAdoptedEmotionCache(TAG_INPUT_EMOTION_KEY);
  }

  return tagInputCache;
}
