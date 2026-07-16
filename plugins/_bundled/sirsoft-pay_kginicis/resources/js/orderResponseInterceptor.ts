/**
 * 주문 생성 응답 인터셉터 — 제거됨 (no-op)
 *
 * 과거에는 이 인터셉터가 다음 우회를 수행했다:
 *
 *   1. 요청 body 의 `payment_method` 가 'kginicis_*' 이면 'card' 로 위장
 *      (서버 검증이 확장 ID 를 422 로 막았기 때문)
 *   2. 응답의 `requires_pg_payment` 를 false 로, `redirect_url` 을 self 로 변조해
 *      템플릿 navigate 를 무력화하고 결제창을 직접 띄움
 *   3. `pg_payment_data` 를 order 응답에서 직접 재구성
 *   4. navigate-to-self 로 인한 체크아웃 재렌더(입력값 소실)를 막기 위해
 *      라우터의 navigate 를 1회 가로채는 suppressor 설치
 *
 * 이 우회는 두 개의 심각한 결함을 낳았다 — 서버가 간편결제 주문을 "PG 결제가 아닌 주문"
 * 으로 오인해 (a) 결제 실패했는데 관리자에게 신규주문 알림이 발송되고 (b) 임시주문이
 * 즉시 삭제되어 재결제가 불가능해졌다.
 *
 * 이제 코어가 확장 결제수단을 1급 시민으로 처리한다:
 *   - 서버 검증이 결제수단 카탈로그를 화이트리스트로 사용 (확장 ID 통과)
 *   - 플러그인이 `pg_provider` 를 자기 PG 로 고정 선언 → PG 결제 주문으로 정상 판정
 *   - provider 가 선언한 `payment_handler` 가 응답의 `pg_payment_handler` 로 내려가고,
 *     템플릿이 그 핸들러를 직접 dispatch 한다 (navigate 분기 자체가 실행되지 않음)
 *   - 원본 결제수단(예: 'kginicis_lpay')은 서버가 `pg_payment_data.payment_method` 로
 *     내려주므로 gopaymethod / CBT paymethod 매핑이 그대로 동작한다
 *
 * 템플릿이 PG 분기에서 navigate 를 하지 않으므로 navigate suppressor 도 불필요하다.
 *
 * 파일과 export 는 호출부(index.ts) 호환을 위해 유지하되 동작은 no-op 이다.
 *
 * @see https://github.com/gnuboard/dev-g7/issues/475
 */

const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_IDENTIFIER}]`, ...args),
};

/**
 * 주문 생성 인터셉터 — 더 이상 fetch 를 래핑하지 않는다.
 *
 * 결제창 진입은 코어 응답의 `pg_payment_handler` 를 템플릿이 dispatch 하는 경로로 처리된다.
 */
export function installOrderResponseInterceptor(): void {
    logger.info('order interceptor is a no-op — payment entry is dispatched via pg_payment_handler');
}
