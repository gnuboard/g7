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
