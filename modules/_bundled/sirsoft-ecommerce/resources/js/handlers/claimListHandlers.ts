/**
 * 클레임 신청내역 핸들러 (회원 · 비회원 공용)
 *
 * 신청서를 접수한 뒤의 조작 — 지금은 **철회**다. 철회는 신청서를 되돌리는 것이므로 확인
 * 모달을 거치고, 확인 뒤에는 서버가 상태 전이와 후속 처리(수량 잠금 해제, 이미 받은 추가결제의
 * 환급)를 한 흐름으로 수행한다. 화면은 결과만 반영한다.
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
import { claimRequestConfig } from './claimImageHandlers';
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
            ?? G7Core.t?.('claim.withdraw.success')
            ?? '신청이 철회되었습니다.'
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
            ?? G7Core.t?.('claim.withdraw.failed')
            ?? '신청 철회에 실패했습니다.';
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
