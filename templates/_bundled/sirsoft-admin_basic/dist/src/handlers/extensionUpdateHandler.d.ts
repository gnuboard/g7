/**
 * 확장(모듈·플러그인·템플릿·언어팩) 업데이트 창의 진행 시계와 끊긴 뒤 결과 확인.
 *
 * 업데이트 요청은 한 번에 끝까지 돈다(몇 분 걸릴 수 있다). 그동안 화면은
 * 「업데이트 중입니다 — 창을 닫지 마세요」와 시작 시각·경과 시간을 보인다. 경과 시간은
 * 화면이 1초마다 계산한다(서버는 진행 단계를 알려 주지 않는다 — 실시간 단계 표시는 다음 판).
 *
 * 요청이 끊기거나(응답 없음·시간 초과) JSON 이 아닌 오류(502·503·504)로 끝나면 서버에서는
 * 업데이트가 끝났을 수도 있다. 그래서 그 확장을 다시 읽어 판본과 「끝나지 않은 업데이트」를
 * 확인하고, 확인한 결과를 결과 창 상태(`_global.extUpdateResult`)에 담는다.
 */
/** 끊긴 뒤 서버에서 확인한 결과 */
export type ReconcileVerdict = 'completed' | 'unfinished' | 'not_applied' | 'unknown';
/**
 * 경과 초를 「분:초」로 바꾼다.
 *
 * @param seconds 경과 초
 * @returns 예: 75 → "1:15"
 */
export declare function formatElapsed(seconds: number): string;
/**
 * 업데이트·설치 요청을 보내기 직전에 진행 시계를 켠다.
 *
 * 시작 시각(`extUpdateStartedAt`)과 경과(`extUpdateElapsed`)를 담고 1초마다 경과를 고친다.
 * 이미 돌고 있으면 새로 시작한다.
 *
 * @param _action 액션 정의 (쓰지 않음)
 */
export declare function startExtensionUpdateClockHandler(_action?: any): void;
/**
 * 요청이 끝나면(성공·실패 모두) 진행 시계를 끈다.
 *
 * @param _action 액션 정의 (쓰지 않음)
 */
export declare function stopExtensionUpdateClockHandler(_action?: any): void;
/**
 * 다시 읽은 확장 행으로 업데이트가 서버에서 어떻게 끝났는지 판정한다.
 *
 * 필드가 없는 옛 응답(`unfinished_update`·`stuck_update` 없음)이면 판본과 상태만으로 가른다.
 *
 * @param row 다시 읽은 확장 행 (없으면 null)
 * @param fromVersion 업데이트 전 판본
 * @param toVersion 올리려던 판본
 * @returns 판정
 */
export declare function judgeReconcile(row: any, fromVersion: string | null, toVersion: string | null): ReconcileVerdict;
/**
 * 응답이 끊긴 업데이트를 서버에서 다시 확인해 결과 창 상태를 채운다.
 *
 * params: kind(module|plugin|template|lang_pack), identifier, name, from_version, to_version, message(끊긴 사유).
 * 결과는 `_global.extUpdateResult` 에 `disconnected: true` 와 판정(`reconcile`)으로 담는다 — 여는 쪽이 결과 창을 연다.
 *
 * @param action 액션 정의
 * @returns 판정
 */
export declare function reconcileExtensionUpdateHandler(action?: any): Promise<ReconcileVerdict>;
/**
 * 실패 응답 본문에서 업데이트 실패 결과(outcome·warnings·retry_command·recovered)를 꺼낸다.
 *
 * 서버는 이 값을 오류 응답 본문 최상위 `data` 에 싣는다. 레이아웃 바인딩은 `error.errors` 만 읽게 돼 있어
 * (응답 본문 경로를 레이아웃에 적지 않는다) 여기서 꺼내 전역 상태로 넘긴다. 필드가 없는 옛 응답이면 null 이다.
 *
 * @param error 실패 갈래의 오류 문맥(status·message·errors·data — data 는 응답 본문 전체)
 * @returns 실패 결과, 없으면 null
 */
export declare function extractUpdateFailure(error: any): Record<string, unknown> | null;
/**
 * 업데이트 실패 갈래에서 실패 결과를 `_global.extUpdateFailure` 에 담는다.
 *
 * @param action 액션 정의 — `params.error` 에 오류 객체(레이아웃이 넘김)
 * @param context 액션 문맥 — 넘긴 값이 없으면 `data.error` 를 본다
 */
export declare function captureExtensionUpdateFailureHandler(action?: any, context?: any): void;
/**
 * 시험용 — 시계 상태를 처음으로 되돌린다.
 */
export declare function __resetExtensionUpdateClockForTests(): void;
