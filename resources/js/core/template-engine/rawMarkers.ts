/**
 * Raw 바인딩 마커 유틸리티
 *
 * {{raw:expression}} 바인딩의 결과를 번역 면제로 표시하는
 * Unicode Noncharacter 기반 마커 시스템.
 *
 * @since engine-v1.27.0
 */

/** raw: 바인딩 접두사 */
export const RAW_PREFIX = 'raw:';

/**
 * 바인딩 식에서 `raw:` 접두사 제거 (없으면 원본 그대로)
 *
 * 접두사를 벗기지 않은 채 식으로 평가하면 `raw:` 의 콜론이 식의 일부로 파싱된다.
 *
 * @since engine-v1.54.9
 */
export function stripRawPrefix(expr: string): string {
  return expr.startsWith(RAW_PREFIX) ? expr.slice(RAW_PREFIX.length) : expr;
}

/** 번역 면제 시작 마커 (Unicode Noncharacter) */
export const RAW_MARKER_START = '\uFDD0';

/** 번역 면제 종료 마커 (Unicode Noncharacter) */
export const RAW_MARKER_END = '\uFDD1';

/** 번역 면제 플레이스홀더 마커 (혼합 보간 시 사용) */
export const RAW_PLACEHOLDER_MARKER = '\uFDD2';

/** 문자열을 raw 마커로 래핑 */
export function wrapRaw(value: string): string {
  return RAW_MARKER_START + value + RAW_MARKER_END;
}

/** raw 마커 존재 여부 확인 (O(1) - charCodeAt 비교) */
export function isRawWrapped(value: string): boolean {
  return value.length >= 2 && value.charCodeAt(0) === 0xFDD0 && value.charCodeAt(value.length - 1) === 0xFDD1;
}

/** raw 마커 내부에 포함 여부 확인 (혼합 보간용) */
export function containsRawMarker(value: string): boolean {
  return value.includes(RAW_MARKER_START);
}

/** raw 마커 제거 */
export function unwrapRaw(value: string): string {
  return value.slice(1, -1);
}

/**
 * 값의 모든 리프 문자열을 재귀적으로 raw 마커로 래핑
 *
 * {{raw:expr}}이 객체/배열을 반환하는 경우 사용.
 * 내부의 모든 문자열 값에 마커를 부착하여
 * resolveTranslationsDeep가 번역을 건너뛰도록 함.
 */
export function wrapRawDeep(value: any): any {
  if (typeof value === 'string') {
    return wrapRaw(value);
  }
  if (Array.isArray(value)) {
    return value.map(wrapRawDeep);
  }
  if (value && typeof value === 'object') {
    const result: Record<string, any> = {};
    for (const [k, v] of Object.entries(value)) {
      result[k] = wrapRawDeep(v);
    }
    return result;
  }
  return value;
}

/**
 * 값의 모든 리프 문자열에서 raw 마커를 재귀적으로 제거 (`wrapRawDeep` 의 역연산)
 *
 * 마커는 번역 패스가 raw 값을 건너뛰게 하려고 붙이는 **내부 표식**이므로, 값이 화면
 * (React props/children)으로 나가기 전에 반드시 벗겨야 한다. 남으면 Unicode Noncharacter
 * 두 글자가 그대로 DOM 에 실린다.
 *
 * 단발 렌더 경로는 `resolveTranslationsDeep` 안에서 벗기지만, 반복 렌더 경로
 * (`renderItemChildren`)에는 그 패스가 없어 마커가 그대로 나갔다.
 *
 * React 엘리먼트는 순회하지 않는다 — 이미 렌더된 자식 트리이고, 그 안의 값은 각자
 * 자기 경로에서 이미 처리됐다.
 *
 * @since engine-v1.56.3
 */
export function stripRawDeep(value: any): any {
  if (typeof value === 'string') {
    if (isRawWrapped(value)) return unwrapRaw(value);
    // 혼합 보간으로 문자열 중간에 마커가 박힌 경우
    if (containsRawMarker(value) || value.includes(RAW_MARKER_END)) {
      return value.split(RAW_MARKER_START).join('').split(RAW_MARKER_END).join('');
    }
    return value;
  }

  if (Array.isArray(value)) {
    let changed = false;
    const out = value.map((v) => {
      const next = stripRawDeep(v);
      if (next !== v) changed = true;
      return next;
    });
    // 마커가 없으면 원본 참조를 그대로 돌려준다 (반복 렌더에서 매 항목 재할당 방지)
    return changed ? out : value;
  }

  if (value && typeof value === 'object') {
    // React 엘리먼트는 이미 렌더된 자식 트리다 — 순회하지 않는다
    if ((value as any).$$typeof !== undefined) return value;

    let changed = false;
    const result: Record<string, any> = {};
    for (const [k, v] of Object.entries(value)) {
      const next = stripRawDeep(v);
      if (next !== v) changed = true;
      result[k] = next;
    }
    return changed ? result : value;
  }

  return value;
}
