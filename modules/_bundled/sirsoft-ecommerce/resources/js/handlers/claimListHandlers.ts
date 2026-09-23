/**
 * 클레임 신청내역 핸들러 (회원 · 비회원 공용)
 *
 * 신청서를 접수한 뒤의 조작 — **철회**와 **미수납 추가결제**다. 철회는 신청서를 되돌리는
 * 것이므로 확인 모달을 거치고, 확인 뒤에는 서버가 상태 전이와 후속 처리(수량 잠금 해제,
 * 이미 받은 추가결제의 환급)를 한 흐름으로 수행한다. 화면은 결과만 반영한다.
 *
 * 추가결제는 청구 방식이 `pay_on_approval`(승인 시점 결제)일 때 생긴다 — 운영자가 승인하면
 * 서버가 결제 대기 건(CP)을 만들고 신청서를 결제대기로 옮기는데, 그 뒤 **구매자가 낼 자리**가
 * 이 화면이다. 진입점이 없으면 상세는 미수납 금액만 보여 주고 낼 방법은 어디에도 없다
 * (2026-09-01 계획 검증 2차 확정 — 그 전까지 `POST claim-payments/{번호}/pay` 의
 * 프로덕션 소비처가 0건이었다).
 *
 * 철회 대상은 **클레임 번호**로 지목한다 — 유형별 접두사(EX/RT/RF/CN)는 운영자가 채번 설정에서
 * 바꿀 수 있으므로 화면이 접두사로 유형을 판정하지 않는다. 서버가 번호로 유형을 해석한다
 * (`UserClaimService::resolveByNumber`).
 *
 * 철회 주소의 앞부분(`withdrawApiBase`)은 **셸이 통째로 준다**. 회원과 비회원의 라우트 모양이
 * 다르기 때문이다:
 *
 *   회원    POST /user/claims/{claimNumber}/withdraw                        (주문 스코프 **없음**)
 *   비회원  POST /guest/orders/{orderNumber}/claims/{claimNumber}/withdraw  (주문 스코프 있음)
 *
 * 신청서 작성이 쓰는 주문 스코프 주소(`claimApiBase`)로 조립하면 회원 경로가 404 가 된다
 * (2026-08-31 브라우저 실측 — 단위 테스트는 핸들러가 만든 주소를 그대로 단언하고 있어
 * 자기 자신과만 맞았다). 그렇다고 핸들러가 회원/비회원을 스스로 판단하면 공유 partial 규율을
 * 깨므로, 그 판단은 셸이 하고 핸들러는 받은 앞부분에 번호만 잇는다.
 */

import type { ActionContext } from '../types';
import { claimRequestConfig, claimText } from './claimImageHandlers';
import { isUserFixableClaimError } from './claimApplyHandlers';

const logger = ((window as any).G7Core?.createLogger?.('Ecom:ClaimList')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:ClaimList]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:ClaimList]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:ClaimList]', ...args),
};

interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/**
 * 철회 확인 모달을 엽니다. (대상 클레임 번호를 모달 컨텍스트에 세운다)
 *
 * 대상을 넘기지 않으면 모달은 열리는데 확인 버튼이 어느 신청서를 가리키는지 알 수 없다 —
 * 그 상태에서 확인을 누르면 조용히 아무 일도 일어나지 않는다. 그래서 번호가 없으면 열지 않는다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.openClaimWithdraw",
 *   "params": { "claimNumber": "{{claim.claim_number}}", "claimLabel": "{{claim.type_label}}" }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function openClaimWithdrawHandler(
    action: ActionWithParams<{ claimNumber?: string; claimLabel?: string }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const claimNumber = String(action.params?.claimNumber ?? '').trim();

    if (!claimNumber) {
        logger.warn('[openClaimWithdraw] 대상 클레임 번호가 없어 열지 않는다');

        return;
    }

    G7Core.state.setLocal({
        withdrawClaimNumber: claimNumber,
        withdrawClaimLabel: String(action.params?.claimLabel ?? ''),
        withdrawError: null,
        isWithdrawingClaim: false,
    });

    G7Core.modal?.open?.('modal_claim_withdraw');
}

/**
 * 철회 확인 모달을 닫습니다. (대상·오류 정리)
 *
 * 대상을 남긴 채 닫으면 다음에 다른 신청서로 열었을 때 잠깐 앞 대상이 보이고, 그 사이에
 * 확인을 누르면 고르지 않은 신청서가 철회된다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function closeClaimWithdrawHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    // setLocal 은 깊은 병합이라 빈 객체는 무동작이다 — 값을 지울 때는 null 을 쓴다.
    G7Core.state.setLocal({
        withdrawClaimNumber: null,
        withdrawClaimLabel: null,
        withdrawError: null,
        isWithdrawingClaim: false,
    });

    G7Core.modal?.close?.('modal_claim_withdraw');
}

/**
 * 철회를 실행합니다.
 *
 * 이미 받은 추가결제가 있으면 서버가 같은 흐름에서 환급한다 — 화면이 환급을 따로 부르지
 * 않는다(두 호출로 나누면 사이에서 끊겼을 때 철회는 됐는데 돈은 그대로인 상태가 남는다).
 *
 * 승인 뒤에는 철회할 수 없어 서버가 409 로 거절한다. 그 사유는 구매자가 이 화면에서 고칠 수
 * 있는 것이 아니지만 **왜 안 되는지**는 알아야 하므로, 4xx 는 모달 안에 남기고 5xx·네트워크
 * 실패만 토스트로 알린다(§6-3 P13 과 같은 채널 규약).
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.withdrawClaim",
 *   "params": { "withdrawApiBase": "{{_local.claimWithdrawApiBase}}", "isGuest": false, "refreshDataSourceId": "claims" }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function withdrawClaimHandler(
    action: ActionWithParams<{
        withdrawApiBase?: string;
        isGuest?: boolean;
        refreshDataSourceId?: string;
        successPath?: string;
    }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    // 신청서 작성용 주문 스코프 주소로 대신 조립하지 않는다 — 회원 철회 라우트는 주문 스코프가
    // 아니라서 그렇게 만든 주소는 404 다. 셸이 값을 주지 않았으면 보내지 않는다.
    const withdrawApiBase = String(action.params?.withdrawApiBase ?? '').replace(/\/+$/, '');
    const isGuest = action.params?.isGuest === true;
    const local = G7Core.state.getLocal();
    const claimNumber = String(local.withdrawClaimNumber ?? '').trim();

    if (!withdrawApiBase || !claimNumber) return;
    // 더블클릭으로 두 번 나가면 두 번째는 이미 철회된 신청서를 다시 철회하려다 거절된다 —
    // 성공한 철회 뒤에 실패 안내가 뜬다.
    if (local.isWithdrawingClaim === true) return;

    G7Core.state.setLocal({ isWithdrawingClaim: true, withdrawError: null });

    try {
        const response = await G7Core.api.post(
            withdrawApiBase + '/' + encodeURIComponent(claimNumber) + '/withdraw',
            {},
            claimRequestConfig(isGuest)
        );

        if (!response?.success) {
            throw new Error(response?.message ?? 'Claim withdraw failed');
        }

        G7Core.state.setLocal({
            isWithdrawingClaim: false,
            withdrawClaimNumber: null,
            withdrawClaimLabel: null,
            withdrawError: null,
        });

        G7Core.modal?.close?.('modal_claim_withdraw');
        G7Core.toast?.success?.(
            response.message
            ?? claimText('sirsoft-ecommerce.user.claim.withdraw.success', '신청이 철회되었습니다.')
        );

        if (action.params?.refreshDataSourceId) {
            await G7Core.dispatch?.({
                handler: 'refetchDataSource',
                params: { dataSourceId: action.params.refreshDataSourceId },
            });
        }

        if (action.params?.successPath) {
            await G7Core.dispatch?.({
                handler: 'navigate',
                params: { path: action.params.successPath },
            });
        }
    } catch (error: any) {
        logger.error('[withdrawClaim] 실패:', error);

        const message = error?.response?.data?.message
            ?? error?.data?.message
            ?? error?.message
            ?? claimText('sirsoft-ecommerce.user.claim.withdraw.failed', '신청 철회에 실패했습니다.');
        const userFixable = isUserFixableClaimError(error);

        G7Core.state.setLocal({
            isWithdrawingClaim: false,
            // 4xx 는 모달 안에 남긴다 — 토스트는 사라진 뒤 왜 철회가 막혔는지 화면에 남지 않는다.
            withdrawError: userFixable ? message : null,
        });

        if (!userFixable) {
            G7Core.toast?.error?.(message);
        }
    }
}

/**
 * 신청서의 미수납 추가결제를 시작합니다. (결제 대기 건 → PG 결제창 또는 마일리지 즉시 결제)
 *
 * 청구 방식이 `pay_on_approval` 이면 운영자 승인 시점에 서버가 결제 대기 건(CP)을 만들고
 * 신청서를 결제대기로 옮긴다. 그 뒤 낼 사람은 구매자인데, 그 자리가 없으면 상세는 미수납
 * 금액만 보여 주고 결제는 영원히 완결되지 않는다 — 운영자가 그 청구 방식을 허용해도 그렇다.
 *
 * 결제 수단은 **화면이 고른 경우에만** 보낸다. 대기 건의 수단은 서버가 만들 때 상점의 허용
 * 수단으로 확정해 두므로(`resolveDefaultPaymentMethodFor`) 평소에는 빈 본문으로 충분하다.
 *
 * 그러나 확정 **뒤에 낼 수 없게 되는 경우**가 있다 — 마일리지 잔액이 모자라거나, 운영자가 그
 * 수단을 `claim.charge_payment_methods` 에서 내리거나. 두 경우 모두 `pay()` 가 거절하는데, 화면이
 * 수단을 다시 고를 수 없으면 사용자는 「미수납 ○○원 + 결제하기」를 보면서 누를 때마다 같은 422 를
 * 받는다(#599 3차 실측 F-1·F-2 — 출구가 신청서 철회뿐이었다).
 *
 * 종전 주석은 수단 변경을 미루는 이유로 「카탈로그(`claimable-options`)가 주문 스코프라 상세가
 * 데이터소스를 하나 더 들어야 한다」를 들었으나 **그것은 사실이 아니었다** — 허용 목록의 단일
 * 출처는 전역 모듈 설정이고 신청 화면이 읽는 것도 그 사본이다. 그래서 상세 응답의
 * `available_payment_methods` 로 같은 목록을 싣고, 화면이 고른 값을 여기서 실어 보낸다.
 *
 * 결제 주소의 앞부분(`payApiBase`)은 철회와 같은 이유로 **셸이 통째로 준다**:
 *
 *   회원    POST /user/claim-payments/{paymentNumber}/pay                        (주문 스코프 **없음**)
 *   비회원  POST /guest/orders/{orderNumber}/claim-payments/{paymentNumber}/pay  (주문 스코프 있음)
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.payClaimOutstanding",
 *   "params": {
 *     "payApiBase": "{{_local.claimPayApiBase}}",
 *     "paymentNumber": "{{claim.data?.claim?.pending_payment?.payment_number}}",
 *     "isGuest": false,
 *     "refreshDataSourceId": "claim"
 *   }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function payClaimOutstandingHandler(
    action: ActionWithParams<{
        payApiBase?: string;
        paymentNumber?: string;
        paymentMethod?: string;
        isGuest?: boolean;
        refreshDataSourceId?: string;
    }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    // 신청서 작성용 주문 스코프 주소로 대신 조립하지 않는다 — 회원 결제 라우트는 주문 스코프가
    // 아니라서 그렇게 만든 주소는 404 다(철회와 같은 결). 셸이 값을 주지 않았으면 보내지 않는다.
    const payApiBase = String(action.params?.payApiBase ?? '').replace(/\/+$/, '');
    const paymentNumber = String(action.params?.paymentNumber ?? '').trim();
    const isGuest = action.params?.isGuest === true;

    if (!payApiBase || !paymentNumber) {
        logger.warn('[payClaimOutstanding] 결제 주소 또는 결제번호가 없어 보내지 않는다');

        return;
    }

    const local = G7Core.state.getLocal();

    // 더블클릭으로 두 번 나가면 두 번째는 이미 결제 진입한 건을 다시 열려다 거절된다 —
    // 결제창이 뜬 뒤에 실패 안내가 겹친다.
    if (local.isPayingClaim === true) return;

    G7Core.state.setLocal({ isPayingClaim: true, claimPayError: null });

    try {
        // 화면이 수단을 골랐으면 그것을 실어 보낸다 — 대기 건의 수단은 접수 때 확정되지만
        // 그 뒤에 낼 수 없게 될 수 있고(마일리지 잔액 부족, 운영자가 수단을 내림), 그때 화면이
        // 다른 수단을 골라도 보내지 않으면 서버는 옛 수단으로 판정해 같은 거절을 돌려준다.
        // 고르지 않았으면 종전대로 빈 본문이다 — 서버가 대기 건에 확정해 둔 값을 쓴다.
        const paymentMethod = String(action.params?.paymentMethod ?? '').trim();

        const response = await G7Core.api.post(
            payApiBase + '/' + encodeURIComponent(paymentNumber) + '/pay',
            paymentMethod ? { payment_method: paymentMethod } : {},
            claimRequestConfig(isGuest)
        );

        if (!response?.success) {
            throw new Error(response?.message ?? 'Claim payment failed');
        }

        const data = response.data ?? {};

        // PG 결제창으로 넘어가는 경우에는 진행 표시를 내리지 않는다 — 결제창이 뜨는 동안
        // 버튼이 다시 살아나면 같은 결제 건으로 두 번 진입할 수 있다. 돌아온 화면은
        // 상세를 다시 읽으므로(진입 시 refetch) 그때 상태가 서버 값으로 맞춰진다.
        if (data.requires_pg_payment && data.pg_payment_handler) {
            await G7Core.dispatch?.({
                handler: data.pg_payment_handler,
                params: { pgPaymentData: data.pg_payment_data },
            });

            return;
        }

        // 마일리지 즉시 결제 — 결제창 없이 그 자리에서 끝난다.
        G7Core.state.setLocal({ isPayingClaim: false, claimPayError: null });
        G7Core.toast?.success?.(
            response.message
            ?? claimText('sirsoft-ecommerce.user.claim.pay.success', '결제가 완료되었습니다.')
        );

        if (action.params?.refreshDataSourceId) {
            await G7Core.dispatch?.({
                handler: 'refetchDataSource',
                params: { dataSourceId: action.params.refreshDataSourceId },
            });
        }
    } catch (error: any) {
        logger.error('[payClaimOutstanding] 실패:', error);

        const message = error?.response?.data?.message
            ?? error?.data?.message
            ?? error?.message
            ?? claimText('sirsoft-ecommerce.user.claim.pay.failed', '결제를 시작하지 못했습니다.');
        const userFixable = isUserFixableClaimError(error);

        G7Core.state.setLocal({
            isPayingClaim: false,
            // 4xx 는 화면에 남긴다 — 토스트는 사라진 뒤 왜 결제가 막혔는지 근거가 남지 않는다
            // (철회와 같은 채널 규약 §6-3 P13).
            claimPayError: userFixable ? message : null,
        });

        if (!userFixable) {
            G7Core.toast?.error?.(message);
        }
    }
}
