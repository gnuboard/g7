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

/**
 * 빈 일반 객체(`{}` — 프로토타입이 Object.prototype 또는 null, 자기 키 0개)인지 판정합니다.
 *
 * 배열·null·Date/File 같은 비일반 객체는 false 입니다.
 *
 * @param value 판정할 값
 * @return 빈 일반 객체면 true
 *
 * @since engine-v1.81.1
 */
export function isEmptyPlainObject(value: unknown): boolean {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return false;
  const proto = Object.getPrototypeOf(value);
  if (proto !== Object.prototype && proto !== null) return false;

  return Object.keys(value as Record<string, unknown>).length === 0;
}

/**
 * setState payload 에서 값이 빈 일반 객체 `{}` 인 자리를 모두 찾아, 그 자리만 `null` 로 둔 같은 모양의 객체를 만듭니다.
 *
 * setState 액션의 deep 병합은 `{}` 를 「바꿀 키 없음」 으로 병합해 기존 값을 지우지 못했다(7.1.1 — 7.1.0 의 레이아웃
 * 빈 객체 보존부터 드러남). 레이아웃은 그 키를 `null` 로 먼저 쓰고 `{}` 를 다시 쓰는 2단계로 피했다. 엔진이 같은 2단계를
 * 대신하려고 `handleSetState` 가 이 결과로 선행 쓰기를 한다 — `null` 은 저장소 A·B·pending·forced·격리·스택 병합이 모두
 * 「교체」 로 처리하므로, 기존 writer 를 그대로 거쳐 두 저장소가 함께 비워진다.
 *
 * - 어느 깊이든 찾는다. 점 경로 키(`"form.meta": {}`)는 키 그대로 둔다(`{"form.meta": null}`).
 * - `errors` 키는 병합기가 이미 통째 교체하므로 건너뛴다.
 * - 메타 키(`__mergeMode`·`__setStateId` 등 `__` 로 시작)와 펼침 키 `...` 는 건너뛴다.
 * - 배열 안은 보지 않는다(배열은 병합하지 않고 통째 교체된다).
 *
 * @param payload setState payload (target·merge 등 제어 키를 뺀 것)
 * @return 비울 자리만 `null` 로 둔 객체, 비울 자리가 없으면 null
 *
 * @since engine-v1.81.1
 */
export function collectEmptyObjectNullTree(payload: Record<string, any>): Record<string, any> | null {
  let tree: Record<string, any> | null = null;
  for (const [key, value] of Object.entries(payload)) {
    if (key === '...' || key === 'errors' || key.startsWith('__')) continue;
    if (isEmptyPlainObject(value)) {
      if (!tree) tree = {};
      tree[key] = null;
      continue;
    }
    if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
      const proto = Object.getPrototypeOf(value);
      if (proto !== Object.prototype && proto !== null) continue;
      const sub = collectEmptyObjectNullTree(value);
      if (sub) {
        if (!tree) tree = {};
        tree[key] = sub;
      }
    }
  }

  return tree;
}

/**
 * 객체의 모든 키가 숫자인지 확인합니다.
 * convertDotNotationToObject가 배열 인덱스를 객체 키로 변환한 경우를 감지합니다.
 */
function hasOnlyNumericKeys(obj: Record<string, any>): boolean {
  const keys = Object.keys(obj);
  return keys.length > 0 && keys.every((k) => /^\d+$/.test(k));
}

/**
 * 중첩 객체에 대해 깊은 병합을 수행하는 헬퍼 함수 (렌더러의 `_local` 합성 병합)
 *
 * @since engine-v1.81.2 DynamicRenderer 에서 이 파일로 옮겼다 — DynamicRenderer 는 같은 이름으로 다시 내보낸다
 *
 * replaceOnlyKeys에 포함된 키(예: 'errors')는 깊은 병합 대신 완전히 교체됩니다.
 * 이를 통해 validation 에러가 누적되지 않고 최신 에러로 갱신됩니다.
 *
 * @param target 기존 상태 객체
 * @param source 업데이트할 객체
 * @returns 깊은 병합된 새 객체
 *
 * @example 일반 객체는 깊은 병합
 * // target: { filter: { searchField: 'all', salesStatus: ['on_sale'] } }
 * // source: { filter: { dateQuick: 'today' } }
 * // result: { filter: { searchField: 'all', salesStatus: ['on_sale'], dateQuick: 'today' } }
 *
 * @example errors는 완전 교체
 * // target: { errors: { name: ["필수"], email: ["필수"] } }
 * // source: { errors: { email: ["필수"] } }
 * // result: { errors: { email: ["필수"] } }  -- name 에러 제거됨
 */
export const deepMergeState = (
  target: Record<string, any>,
  source: Record<string, any>
): Record<string, any> => {
  // 깊은 병합에서 제외할 키 목록 (완전 교체되어야 하는 필드들)
  // validation errors 등은 병합하면 이전 오류가 남아있게 됨
  const replaceOnlyKeys = ['errors'];

  const result: Record<string, any> = { ...target };

  for (const key of Object.keys(source)) {
    const sourceValue = source[key];
    const targetValue = target[key];

    // source 값이 null이면 그대로 설정
    if (sourceValue === null) {
      result[key] = null;
      continue;
    }

    // errors 등 특정 키는 항상 완전히 교체 (validation 에러 등은 병합하면 안됨)
    if (replaceOnlyKeys.includes(key)) {
      result[key] = sourceValue;
      continue;
    }

    // source 값이 배열이면 배열로 교체 (배열은 병합하지 않음)
    if (Array.isArray(sourceValue)) {
      result[key] = sourceValue;
      continue;
    }

    // 특수 케이스: target이 배열이고 source가 숫자 키만 가진 객체인 경우
    // convertDotNotationToObject가 "optionInputs.0.values" 같은 경로를
    // { optionInputs: { "0": { values: ... } } } 로 변환하므로,
    // 이를 배열 인덱스로 해석하여 해당 위치의 요소를 병합
    if (
      Array.isArray(targetValue) &&
      sourceValue !== null &&
      typeof sourceValue === 'object' &&
      !Array.isArray(sourceValue) &&
      hasOnlyNumericKeys(sourceValue)
    ) {
      const numericKeys = Object.keys(sourceValue).map((k) => parseInt(k, 10));
      const maxKey = numericKeys.length > 0 ? Math.max(...numericKeys) : 0;

      // Sparse array 방지: source 키가 배열 범위를 크게 초과하면
      // 배열 인덱스가 아닌 ID/키 매핑으로 간주하여 객체로 교체
      if (maxKey >= targetValue.length + numericKeys.length + 10) {
        result[key] = { ...sourceValue };
      } else {
        const arrResult = [...targetValue];
        for (const [srcKey, srcVal] of Object.entries(sourceValue)) {
          const index = parseInt(srcKey, 10);
          if (index >= 0 && index < arrResult.length) {
            if (
              srcVal !== null &&
              typeof srcVal === 'object' &&
              !Array.isArray(srcVal) &&
              arrResult[index] !== null &&
              typeof arrResult[index] === 'object'
            ) {
              arrResult[index] = deepMergeState(arrResult[index], srcVal);
            } else {
              arrResult[index] = srcVal;
            }
          } else if (index >= arrResult.length) {
            arrResult[index] = srcVal;
          }
        }
        result[key] = arrResult;
      }
    } else if (
      // 둘 다 일반 객체인 경우 재귀적으로 깊은 병합
      sourceValue !== null &&
      typeof sourceValue === 'object' &&
      targetValue !== null &&
      typeof targetValue === 'object' &&
      !Array.isArray(targetValue)
    ) {
      result[key] = deepMergeState(targetValue, sourceValue);
    } else {
      // 그 외의 경우 덮어쓰기
      result[key] = sourceValue;
    }
  }

  return result;
};
