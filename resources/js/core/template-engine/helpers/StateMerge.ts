/**
 * 상태 병합 헬퍼
 *
 * 이중 저장소(React `localDynamicState` = 저장소 A, `globalState._local` = 저장소 B)를
 * 동기화하는 두 쓰기 경로가 공유하는 병합 프리미티브를 담는다.
 *
 * 왜 `helpers/` 인가:
 *   `addMissingLeafKeys` 는 `G7CoreGlobals.ts` 의 모듈 프라이빗 함수였다. 그런데
 *   `G7CoreGlobals.ts` 가 `ActionDispatcher` 를 import 하므로 `ActionDispatcher` 에서
 *   역방향 값 import 를 하면 런타임 순환이 된다. `helpers/` 는 엔진 모듈을 import 하지
 *   않으므로 양쪽이 안전하게 공유할 수 있다.
 *
 * @since engine-v1.63.3
 * @packageDocumentation
 */

/**
 * base 객체에 없는 leaf 키만 extra에서 추가합니다.
 *
 * deepMerge와 달리 base에 이미 존재하는 값(배열 포함)은 절대 덮어쓰지 않습니다.
 * extra에만 존재하는 키는 재귀적으로 추가됩니다.
 *
 * 용도:
 * - `setLocal`(G7CoreGlobals)에서 dynamicLocal(actionContext.state)의 setState 전용 키를
 *   globalLocal에 안전하게 추가할 때. dynamicLocal의 stale 배열(init_actions 기본값)이
 *   globalLocal의 정상 API 데이터를 덮어쓰는 것을 방지합니다.
 * - `handleSetState`(ActionDispatcher) COMPONENT path 에서 저장소 B 를 base 로 삼을 때
 *   저장소 A 전용 키(`loadingActions`, `apiError`, `*_result` 등)를 보충할 때.
 *
 * @param base 우선하는 기준 객체 (충돌 시 이 값이 이긴다)
 * @param extra base 에 없는 키만 가져올 보충 객체
 * @return base 를 얕게 복사한 뒤 누락 키를 채운 새 객체 (base/extra 모두 변이하지 않음)
 *
 * @since engine-v1.41.0 (G7CoreGlobals 내부 함수로 최초 도입)
 * @since engine-v1.63.3 helpers/StateMerge 로 이동해 ActionDispatcher 와 공유
 *
 * @example
 * ```ts
 * const base = { form: { category_ids: [381, 384], name: 'A' } };
 * const extra = { form: { category_ids: [], options: [] }, selectedProducts: [1] };
 * addMissingLeafKeys(base, extra);
 * // → { form: { category_ids: [381, 384], name: 'A', options: [] }, selectedProducts: [1] }
 * // base의 category_ids는 보존, extra의 selectedProducts와 options는 추가
 * ```
 */
export function addMissingLeafKeys(
  base: Record<string, any>,
  extra: Record<string, any>
): Record<string, any> {
  const result = { ...base };
  for (const key of Object.keys(extra)) {
    if (!(key in result)) {
      // base에 없는 키: extra 값 그대로 추가
      result[key] = extra[key];
    } else if (
      result[key] !== null &&
      typeof result[key] === 'object' &&
      !Array.isArray(result[key]) &&
      extra[key] !== null &&
      typeof extra[key] === 'object' &&
      !Array.isArray(extra[key])
    ) {
      // 양쪽 모두 plain object: 재귀적으로 처리
      result[key] = addMissingLeafKeys(result[key], extra[key]);
    }
    // base에 이미 존재하는 leaf 값(배열, 문자열, 숫자 등): 건너뜀 (base 값 보존)
  }
  return result;
}
