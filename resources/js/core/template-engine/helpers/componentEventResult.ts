/**
 * 레이아웃 이벤트 리스너(`<on listen>` · onComponentEvent) 결과 표식
 *
 * 레이아웃 리스너는 받은 이벤트로 액션을 실행하고 그 결과를 이 표식 객체로 돌려준다.
 * `emitEvent` 가 표식을 풀어 보낸 쪽 `_local._eventResult` 의 `success`·`data`·`error` 를 채운다.
 *
 * 받는 쪽의 실패를 보낸 쪽 예외로 바꾸지 않는다 — 받는 쪽이 자기 `<fail>` 로 이미 알렸는데 보낸 쪽까지
 * 실패시키면 기본 오류 토스트가 한 번 더 뜬다. 컴포넌트 리스너(`G7Core.componentEvent.on` 을 직접 쓰는
 * 코드)는 표식이 없으므로 지금처럼 돌려준 값이 그대로 `data` 가 되고, throw 하면 보낸 쪽이 실패한다.
 *
 * @package G7
 * @subpackage Template Engine
 * @since engine-v1.80.0
 */

import type { ActionResult } from '../ActionDispatcher';

/** 레이아웃 리스너 결과 표식 — 전역 심볼이라 번들이 갈려도 같은 값이다 */
export const LAYOUT_LISTENER_RESULT = Symbol.for('g7.layoutListenerResult');

/** 레이아웃 리스너가 돌려주는 결과 */
export interface LayoutListenerResult {
  [LAYOUT_LISTENER_RESULT]: true;
  success: boolean;
  data?: any;
  error?: string;
}

/**
 * 상태 쓰기 결과(setState 가 돌려주는 `__target` 표식 스냅숏)를 결과 데이터에서 뺍니다.
 *
 * 그 값은 병합된 `_local`·`_global` 전체로, sequence 가 다음 동작에 상태를 넘기는 엔진 내부 값이지
 * 동작의 결과 데이터가 아니다. 보낸 쪽 `_local._eventResult.data` 에 실으면 다음 emit 의 스냅숏이 이전
 * `_eventResult` 를 품어 emit 마다 한 겹씩 쌓인다. sequence 결과 배열은 자리를 유지한 채 그 항목만 비운다.
 * 분기(conditions·switch)가 남긴 실행 결과 겹은 벗긴 뒤 판별한다(unwrapActionResult).
 *
 * @param data 동작 결과 데이터
 * @returns 상태 쓰기 스냅숏을 뺀 결과 데이터
 */
function withoutStateWrites(data: any): any {
  const value = unwrapActionResult(data);
  if (Array.isArray(value)) {
    return value.some((item) => isStateWriteResult(item) || Array.isArray(item) || isActionResult(item))
      ? value.map(withoutStateWrites)
      : value;
  }

  return isStateWriteResult(value) ? undefined : value;
}

/**
 * 실행 결과 겹(`{ success, data }`·`{ success, error }`)을 벗겨 그 안의 결과 데이터를 꺼냅니다.
 *
 * 분기(conditions·switch)는 고른 동작의 실행 결과를 그대로 돌려주고 executeAction 이 그것을 다시 감싼다 —
 * 리스너 결과의 `data` 가 분기 동작의 결과가 아니라 실행 결과 객체가 되고, 그 안의 상태 쓰기 스냅숏이
 * 판별을 비켜 갔다(engine-v1.80.0 엄격 검사 K1). 분기가 겹치면 겹도 겹친다.
 *
 * @param value 동작 결과 데이터
 * @returns 실행 결과 겹을 모두 벗긴 값
 */
function unwrapActionResult(value: any): any {
  let current = value;
  for (let depth = 0; depth < 20 && isActionResult(current); depth++) {
    current = current.data;
  }

  return current;
}

/**
 * 엔진 실행 결과 객체인지 판별합니다 — `success`(불리언)와 `data`·`error` 만 가진 객체.
 *
 * API 응답 본문(`{ success, message, data }`)처럼 다른 키가 있으면 결과 데이터로 보아 벗기지 않는다.
 *
 * @param value 검사할 값
 * @returns 실행 결과 객체면 true
 */
function isActionResult(value: unknown): boolean {
  if (!value || typeof value !== 'object' || Array.isArray(value) || typeof (value as any).success !== 'boolean') {
    return false;
  }

  return Object.keys(value).every((key) => key === 'success' || key === 'data' || key === 'error');
}

/**
 * setState 결과 스냅숏(`{ __target: 'local' | 'global' | 'isolated', ... }`)인지 판별합니다.
 *
 * @param value 동작 결과 데이터
 * @returns 상태 쓰기 결과면 true
 */
function isStateWriteResult(value: unknown): boolean {
  return !!value && typeof value === 'object' && !Array.isArray(value) && typeof (value as any).__target === 'string';
}

/**
 * 액션 실행 결과를 레이아웃 리스너 결과로 감쌉니다.
 *
 * 확인 창에서 취소해 실행하지 않은 경우(결과 없음)는 실패가 아니다 — `success: true`, `data: undefined`.
 * `data` 는 마지막 동작의 결과다 — sequence(자식이 여럿인 `<on listen>` 포함)는 단계별 결과 배열을 돌려주므로
 * 그 마지막 항목을 싣는다. 상태 쓰기 결과는 싣지 않는다(withoutStateWrites).
 *
 * @param result createHandler 가 돌려준 실행 결과
 * @param options.sequence 리스너 동작이 sequence 인지 — 결과 배열의 마지막 항목을 싣는다
 * @returns 표식이 붙은 결과 객체
 */
export function makeLayoutListenerResult(
  result: ActionResult | undefined,
  options: { sequence?: boolean } = {}
): LayoutListenerResult {
  const error = result?.error as any;
  const resultData = unwrapActionResult(result?.data);
  const data = options.sequence && Array.isArray(resultData) ? resultData[resultData.length - 1] : resultData;

  return {
    [LAYOUT_LISTENER_RESULT]: true,
    success: result?.success !== false,
    data: withoutStateWrites(data),
    error: error ? (error.message ?? String(error)) : undefined,
  };
}

/**
 * 레이아웃 리스너 결과 표식이 붙은 값인지 판별합니다.
 *
 * @param value 리스너가 돌려준 값
 * @returns 표식이 있으면 true
 */
export function isLayoutListenerResult(value: unknown): value is LayoutListenerResult {
  return !!value && typeof value === 'object' && (value as any)[LAYOUT_LISTENER_RESULT] === true;
}
