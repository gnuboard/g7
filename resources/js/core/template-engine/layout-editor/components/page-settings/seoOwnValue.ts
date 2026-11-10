/**
 * seoOwnValue.ts — meta.seo 의 베이스 상속분과 이 화면 값 나누기 (페이지 설정 [기본 정보]·[검색엔진] 탭)
 *
 * 두 탭은 병합본 meta 를 통째로 패치한다. 그대로 저장하면 베이스 레이아웃에서 물려받은 SEO 가 이 화면 값으로
 * 굳어, 그 뒤 베이스의 SEO 를 바꿔도 이 화면에는 반영되지 않는다. 그래서 저장할 값(original)에는 이 화면이 직접
 * 적은 키와 베이스와 달라진 키만 남긴다.
 *
 * 병합 규칙은 서버 `LayoutService::mergeSeo` 와 같다 — 화면에 seo 가 없으면 베이스 seo 를 통째로 쓰고, 있으면
 * 연관 키는 깊게 병합(자식 우선), 최상위 `data_sources` 는 합집합.
 *
 * 한계(서버 규칙 그대로): 베이스가 준 키를 이 화면에서 지우는 것은 표현할 수 없다 — 병합에서 베이스 값이 다시 들어온다.
 *
 * @since engine-v1.85.0
 */

type PlainObject = Record<string, unknown>;

function isPlainObject(v: unknown): v is PlainObject {
  return !!v && typeof v === 'object' && !Array.isArray(v);
}

function sameValue(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

/**
 * 병합본 seo 에서 베이스가 준 부분을 도출한다 (병합본 − 이 화면 값)
 *
 * @param merged 병합본 meta.seo
 * @param own 이 화면이 직접 적은 meta.seo (__editor.original.meta.seo)
 * @param topLevel 최상위 호출인지 (data_sources 합집합 규칙은 최상위만)
 * @return 베이스 상속분, 없으면 undefined
 */
export function deriveInheritedSeo(merged: unknown, own: unknown, topLevel = true): PlainObject | undefined {
  if (!isPlainObject(merged)) return undefined;
  if (!isPlainObject(own)) return merged;
  const base: PlainObject = {};
  for (const [key, value] of Object.entries(merged)) {
    if (topLevel && key === 'data_sources' && Array.isArray(value)) {
      const ownList = Array.isArray(own.data_sources) ? own.data_sources : [];
      const inherited = value.filter((item) => !ownList.includes(item));
      if (inherited.length > 0) base.data_sources = inherited;
      continue;
    }
    if (!(key in own)) {
      base[key] = value;
      continue;
    }
    if (isPlainObject(value) && isPlainObject(own[key])) {
      const nested = deriveInheritedSeo(value, own[key], false);
      if (nested && Object.keys(nested).length > 0) base[key] = nested;
    }
  }

  return Object.keys(base).length > 0 ? base : undefined;
}

/**
 * 저장할 이 화면의 seo — 이 화면이 직접 적은 키는 남기고, 그 밖의 키는 베이스와 달라졌을 때만 남긴다
 *
 * @param next 고친 뒤 병합본 meta.seo
 * @param base 베이스 상속분 ({@link deriveInheritedSeo})
 * @param own 고치기 전 이 화면 값
 * @param topLevel 최상위 호출인지
 * @return 저장할 값, 남길 것이 없으면 undefined
 */
export function seoOwnForPersist(next: unknown, base: PlainObject | undefined, own: unknown, topLevel = true): PlainObject | undefined {
  if (!isPlainObject(next)) return undefined;
  const ownObject = isPlainObject(own) ? own : {};
  const out: PlainObject = {};
  for (const [key, value] of Object.entries(next)) {
    if (topLevel && key === 'data_sources' && Array.isArray(value)) {
      const baseList = Array.isArray(base?.data_sources) ? base.data_sources : [];
      const list = value.filter((item) => !baseList.includes(item));
      if (list.length > 0 || 'data_sources' in ownObject) out.data_sources = list;
      continue;
    }
    if (isPlainObject(value)) {
      const nested = seoOwnForPersist(value, isPlainObject(base?.[key]) ? (base[key] as PlainObject) : undefined, ownObject[key], false);
      if (nested && (Object.keys(nested).length > 0 || key in ownObject)) out[key] = nested;
      continue;
    }
    if (key in ownObject || base === undefined || !(key in base) || !sameValue(value, base[key])) {
      out[key] = value;
    }
  }

  return Object.keys(out).length > 0 || isPlainObject(own) ? out : undefined;
}
