/**
 * 부품 문구 번역 — 템플릿 설정 화면 묶음에서는 그 템플릿 문구(`{템플릿id}.키`)를 먼저 찾는다 (#434)
 *
 * 설정 화면 묶음(`dist/settings/*`)은 이 템플릿이 꺼져 있거나 다른 관리자 템플릿이 켜진 화면에도 실린다. 그때 이 템플릿
 * 문구는 관리자 응답에 `{템플릿id}.{묶음}` 으로 감싸여 오므로(설정 부품 매니페스트 `lang`), 설정 묶음 진입이 이름공간을
 * 정해 두면 같은 부품 코드가 그 감싼 문구를 먼저 찾는다. 기본 부품 묶음은 이름공간이 없어 종전과 같다.
 */

let namespace: string | null = null;

/**
 * 문구 이름공간을 정합니다 — 설정 화면 묶음 진입만 부른다.
 *
 * @param value 템플릿 식별자 (null 이면 이름공간 없음)
 * @returns void
 */
export function setTranslationNamespace(value: string | null): void {
  namespace = value;
}

/**
 * 키를 번역합니다 — 이름공간이 있으면 `{이름공간}.키` 를 먼저, 없거나 못 찾으면 키 그대로 찾는다.
 *
 * 호출 시점의 `window.G7Core` 를 읽는다 — 모듈을 불러온 시점에 잡아 두면 그 뒤에 준비된 번역 함수를 쓰지 못한다.
 *
 * @param key 번역 키
 * @param params 치환 값
 * @returns 번역 결과 (번역 함수가 없으면 undefined, 못 찾으면 엔진이 돌려준 키)
 */
export function translate(key: string, params?: Record<string, string | number>): string | undefined {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const core = (window as any).G7Core;

  if (namespace) {
    const scoped = `${namespace}.${key}`;
    const found = core?.t?.(scoped, params);

    if (typeof found === 'string' && found !== scoped) {
      return found;
    }
  }

  const translated = core?.t?.(key, params);

  return typeof translated === 'string' ? translated : undefined;
}
