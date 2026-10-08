/**
 * 관리자 클레임(교환/반품/환불/취소) 목록·상세 핸들러 — S8b(목록) · S9a(상세)
 *
 * 목록 본체가 쓰는 두 갈래를 담는다:
 *  - 일괄 상태 변경: 확인 모달 → `POST admin/claims/bulk-status` (부분 성공 계약)
 *  - 완료 처리 모달 (D26): 세 진입점 공용 — 미리보기(`completion-preview`) → 실행
 *
 * 상세 화면이 쓰는 갈래는 셋이다:
 *  - 상품 단위 일괄 전이 (D25): `PATCH admin/{세그먼트}/{id}/options/bulk-status`
 *  - 상품 행 송장(「송장만 저장」): `PATCH admin/{세그먼트}/{id}/options/tracking` · 운송장 단위 도착 `POST …/tracking/deliver`
 *  - 진행 방식(일반·선교환) · 대리 철회 · 신청 내용 수정 · 뒤늦은 재고 복구
 *  - 관리자 메모 저장: `PUT .../amounts` (메모는 금액 수정 엔드포인트가 함께 받는 축이다)
 *
 * 유형 스코프 경로 세그먼트(`exchanges`/`returns`/`refunds`)는 **서버가 준 값**만 쓴다 —
 * `claim_type + 's'` 로 조립하면 그 조립이 세그먼트 규칙의 두 번째 출처가 되고, 규칙이
 * 바뀌어도 오류 없이 404 만 나는 URL 이 만들어진다.
 *
 * 완료 처리 모달의 상태는 전부 `_global.claimComplete*` 에 둔다. 모달 partial 하나를
 * 목록·상세·완료 버튼 세 지점이 공유해야 하므로(D26), 모달이 특정 화면의 데이터소스를
 * 읽으면 나머지 두 진입점에서 그 바인딩이 비어 버린다. 여는 쪽이 대상과 실행 경로를
 * `openClaimCompleteModal` 에 넘기고, 모달은 그 결과만 그린다.
 */

import type { ActionContext } from '../types';
import { formatAmountInCurrency } from './calculateCurrencyPrices';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:Claim')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:Claim]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:Claim]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:Claim]', ...args),
};

/**
 * 커스텀 핸들러에 전달되는 액션 객체 인터페이스
 */
interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/** 목록 응답의 행 (필요한 축만) */
interface ClaimListRow {
    id: number;
    claim_number?: string | null;
    requester_name?: string | null;
    item_summary?: { product_name?: string | null; item_count?: number; total_quantity?: number } | null;
    /** 저장된 회수·재발송 송장 — 회수중·발송중 일괄 모달이 미리 채운다 */
    collection_carrier_id?: number | null;
    collection_tracking_number?: string | null;
    redelivery_carrier_id?: number | null;
    redelivery_tracking_number?: string | null;
}

/** 택배사 선택지 (서버 `carriers`) */
interface CarrierOption {
    value: number | string;
    label: string;
}

/** 목록 일괄 회수중·발송중 모달의 신청서 한 줄 */
interface ClaimBulkTrackingRow {
    id: number;
    claim_number: string | null;
    requester_name: string | null;
    carrier_id: string;
    tracking_number: string;
}

/** 완료 처리 모달의 실행 경로 — 진입점마다 다르다 (D26) */
interface ClaimCompleteExecute {
    /** 요청 URL */
    url: string;
    /** HTTP 메서드 (`POST` 목록 일괄 / `PATCH` 단건·상품 단위) */
    method: 'POST' | 'PATCH';
    /** 본문에 함께 실을 고정 값 (일괄은 `{type, ids}`, 상품 단위는 `{item_ids}` 등) */
    body?: Record<string, unknown>;
    /**
     * 성공 후 다시 읽을 데이터소스 id (생략 시 목록 `claims`).
     *
     * 진입점마다 화면이 다르므로 여는 쪽이 알려준다 — 모달이 특정 화면의 데이터소스 이름을
     * 알고 있으면 나머지 진입점에서 그 재조회가 조용히 아무 일도 하지 않는다.
     *
     * 한 화면이 여러 소스를 그리면 배열로 준다 — 상세는 신청서와 활동 로그 둘이다.
     */
    refetch?: string | string[];
    /** 성공 후 되돌릴 로컬 상태 (생략 시 목록의 선택·상태 셀렉트) */
    clearLocal?: Record<string, unknown>;
}

/** 완료 처리 모달의 초기 선택값 (설정 `claim.complete_defaults`) */
interface ClaimCompleteDefaults {
    create_refund_request?: boolean;
    restore_stock?: boolean;
}

/** 완료 처리 모달이 여는 쪽에서 받는 대상 한 건 */
interface ClaimCompleteTarget {
    id: number;
    claim_number?: string | null;
    requester_name?: string | null;
    /** 상품 표기 (목록은 요약, 상세는 상품명) */
    item_label?: string | null;
    /** 이번 라운드 완료 대상 상품 행 (생략하면 전체 — 상품 단위 진입점만 보낸다) */
    item_ids?: number[];
}

/** 완료 처리 모달을 여는 쪽이 넘기는 값 */
interface ClaimCompleteOpenParams {
    claimType?: string;
    targets?: ClaimCompleteTarget[];
    execute?: ClaimCompleteExecute;
    defaults?: ClaimCompleteDefaults;
}

const PREVIEW_URL = '/api/modules/sirsoft-ecommerce/admin/claims/completion-preview';
const BULK_STATUS_URL = '/api/modules/sirsoft-ecommerce/admin/claims/bulk-status';

/**
 * 송장이 있어야 들어가는 상태 — 서버 `ValidatesClaimTransitionTracking` 과 같은 목록이다.
 *
 * 회수중·발송중은 택배가 물건을 싣고 가는 중이라는 사실이라 송장 없이 들어가면 고객 알림의
 * 운송장 칸이 비어서 나간다(#599 후속 운영 결정). 서버가 422 로 막지만, 수량 확인 모달까지 연 뒤에야
 * 거절되지 않게 입구에서 먼저 세운다.
 */
const TRACKING_REQUIRED_STATUSES = ['collecting', 'redelivering'];

/** 완료 미리보기 재조회 debounce (ms) — 형제 취소 모달(`cancelOrderHandlers`)과 같은 규율. */
const PREVIEW_DEBOUNCE_MS = 200;

/**
 * 미리보기 요청 세대 번호.
 *
 * 미리보기는 선택을 바꿀 때마다 나가는데 응답 순서는 보장되지 않는다. 늦게 도착한 옛 응답이
 * 화면(`claimCompleteRows`/`claimCompletePreview`)을 덮으면, 실행이 쓰는 값
 * (`claimCompleteRestore`)만 최신으로 남아 **화면이 예고한 것과 다른 처리가 실행**된다.
 * 모달을 다시 연 뒤 앞 세션 응답이 도착하면 대상 신청서까지 갈린다.
 *
 * 그래서 요청마다 세대를 부여하고, 응답을 반영하기 전에 자기가 아직 최신인지 확인한다.
 * 세대는 새 요청·모달 재진입·상태 초기화에서 올라간다.
 */
let previewGeneration = 0;

/** 대기 중인 debounce 타이머. */
let previewDebounceTimer: ReturnType<typeof setTimeout> | null = null;

/** debounce 로 묶인 호출부에게 결과를 돌려줄 resolver 목록. */
let previewDebounceWaiters: Array<(applied: boolean) => void> = [];

/**
 * 완료 미리보기 재조회를 debounce 로 예약합니다.
 *
 * 토글마다 즉시 요청하면 선택 1회당 왕복이 한 번씩 나가고, 대상이 많을수록 겹친 요청이
 * 늘어난다. 형제 모달 2종이 이미 같은 규율을 갖고 있다.
 *
 * 반환 Promise 는 **실제로 나간 요청**이 끝날 때 함께 풀린다 — 호출부(액션 핸들러)가
 * await 로 완료를 기다릴 수 있어야 오류 전파와 테스트 관찰이 성립한다.
 *
 * @returns 반영 성공 여부
 */
function scheduleClaimCompletePreview(): Promise<boolean> {
    // 새 선택의 예약부터 이전 응답을 무효화한다. 전송 시점까지 기다리면 debounce
    // 사이에 끝난 이전 요청이 새 선택의 스피너와 실행 잠금을 풀어 버린다.
    previewGeneration += 1;
    core()?.state?.set?.({ claimCompletePreviewLoading: true });
    if (previewDebounceTimer) {
        clearTimeout(previewDebounceTimer);
        previewDebounceTimer = null;
    }

    return new Promise<boolean>((resolve) => {
        previewDebounceWaiters.push(resolve);

        previewDebounceTimer = setTimeout(() => {
            previewDebounceTimer = null;

            const waiters = previewDebounceWaiters;
            previewDebounceWaiters = [];

            refreshClaimCompletePreview().then(
                (applied) => waiters.forEach((waiter) => waiter(applied)),
                () => waiters.forEach((waiter) => waiter(false)),
            );
        }, PREVIEW_DEBOUNCE_MS);
    });
}

/**
 * 대기 중인 미리보기 재조회를 취소하고 진행 중 응답을 무효화합니다.
 *
 * 모달을 닫는 지점에서 부른다 — 닫힌 모달의 응답이 전역 상태를 늦게 덮지 않게 한다.
 */
export function clearClaimCompleteTimersHandler(): void {
    if (previewDebounceTimer) {
        clearTimeout(previewDebounceTimer);
        previewDebounceTimer = null;
    }

    const waiters = previewDebounceWaiters;
    previewDebounceWaiters = [];
    waiters.forEach((waiter) => waiter(false));

    previewGeneration += 1;
    core()?.state?.set?.({ claimCompletePreviewLoading: false });
}

/**
 * 상세 화면이 처리 후 다시 읽어야 하는 데이터소스.
 *
 * 상태 전이·송장·메모는 신청서만 바꾸는 것이 아니라 **활동 로그도 남긴다**. 신청서만
 * 다시 읽으면 헤더·진행단계는 갱신되는데 활동 로그 섹션만 옛 목록으로 남아, 운영자는
 * 방금 자기가 한 처리가 이력에 없는 것으로 본다 — 오류도 경고도 남지 않는다(실측).
 */
const DETAIL_REFETCH_SOURCES = ['claim', 'claim_logs'];

/**
 * 데이터소스를 다시 읽습니다. (하나 또는 여럿)
 *
 * @param sources 데이터소스 id (문자열 또는 배열)
 */
function refetchSources(sources: string | string[]): void {
    const G7Core = core();
    if (!G7Core) return;

    (Array.isArray(sources) ? sources : [sources]).forEach((dataSourceId) => {
        G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId } });
    });
}

/** 유형별 상태 라벨 키 접두사 — 확인 모달의 라벨 해석에 쓴다. */
const STATUS_LABEL_PREFIX: Record<string, string> = {
    exchange: 'sirsoft-ecommerce.enums.exchange_status.',
    return: 'sirsoft-ecommerce.enums.return_status.',
    refund: 'sirsoft-ecommerce.enums.refund_status.',
};

/**
 * G7Core 전역을 반환합니다. (미초기화면 null)
 *
 * @returns G7Core 전역 또는 null
 */
function core(): any | null {
    return (window as any).G7Core ?? null;
}

/**
 * 다국어 문구를 해석합니다. (미해석이면 폴백)
 *
 * @param key 다국어 키
 * @param fallback 폴백 문구
 * @param params 치환 파라미터
 * @returns 해석된 문구
 */
function t(key: string, fallback: string, params?: Record<string, unknown>): string {
    const resolved = core()?.t?.(key, params);

    return typeof resolved === 'string' && resolved !== key ? resolved : fallback;
}

/**
 * 서버 오류 응답에서 사용자에게 보일 사유를 뽑아냅니다.
 *
 * axios 는 `error.message` 에 "Request failed with status code 422" 같은 원문만 담으므로,
 * 실제 사유가 들어 있는 응답 본문의 `message` 를 먼저 본다.
 *
 * **전송 자체가 실패한 경우(네트워크 끊김·타임아웃)는 null 을 돌려준다.** 그때 axios 가
 * 넣는 값은 `Network Error` 같은 내부 문자열이라 번역도 사이트 문구도 아니다 — 그대로
 * 띄우면 운영자는 영문 원문을 본다(실측 §9 T4 에서 실제로 그랬다). 부르는 쪽이 가진
 * 번역된 기본 문구로 넘긴다. 우리 코드가 서버 메시지를 담아 던진 `Error` 는 응답이 없어도
 * 전송 실패가 아니므로 그대로 쓴다.
 *
 * @param error 오류 객체
 * @returns 사유 문구 (없으면 null)
 */
function serverMessageOf(error: any): string | null {
    const data = error?.response?.data ?? error?.data ?? {};
    // 서버는 일반 문구(`message`)와 실제 사유(`errors.detail` — 결제대행사 거절 사유 등)를 따로 싣는다.
    // 사유가 있으면 그것을 보인다 — 일반 문구만으로는 운영자가 무엇을 해야 하는지 알 수 없다(S3-D11).
    // 주문 취소·입금확인 핸들러도 같은 순서로 읽는다.
    const detail = data?.errors?.detail;
    if (typeof detail === 'string' && detail !== '') return detail;
    if (typeof data?.message === 'string' && data.message !== '') return data.message;

    const isTransportFailure = error?.isAxiosError === true && !error?.response;
    if (isTransportFailure) return null;

    return error?.message ?? null;
}

/**
 * 완료 처리 모달의 미리보기를 다시 계산하고 화면 상태를 갱신합니다.
 *
 * 재고 복구 체크·환불신청서 라디오가 바뀔 때마다 부른다 — '함께 실행되는 처리' 요약과
 * 환불 예정액이 그 선택에 따라 달라지고, 그 값의 SSoT 는 서버(`completion-preview`)다.
 *
 * 서버가 받는 재고 맵의 키는 **상품 행 ID** 인데 그 목록은 미리보기 응답에만 있다. 그래서
 * 모달을 처음 열 때는 빈 맵으로 나가고 서버는 그것을 '체크 없음'(전건 폐기)으로 계산한다 —
 * 체크박스는 켜져 있는데 요약만 "재고 0복구 / N폐기" 로 나오는 상태다. 값은 실행 시점에
 * 다시 만들어져 결과는 옳지만, 미리보기와 실행이 **같은 식**을 쓰는 것이 D26 의 계약이므로
 * 응답이 답한 상품 행으로 맵을 다시 만들어 보낸 것과 다르면 **한 번만** 더 부른다.
 * 다르지 않으면(재고 기본값이 꺼진 상점, 토글 이후의 갱신) 추가 요청은 없다.
 *
 * @param allowRetry 상품 행이 새로 밝혀졌을 때 1회 재호출을 허용할지 (재호출 자신은 false)
 * @returns 갱신 성공 여부
 */
async function refreshClaimCompletePreview(allowRetry = true): Promise<boolean> {
    const G7Core = core();
    if (!G7Core) return false;

    const globalState = G7Core.state.get() ?? {};
    const context = globalState.claimCompleteContext;
    if (!context) return false;

    // 이 요청의 세대. 응답을 반영하기 전에 아직 최신인지 확인한다.
    const generation = ++previewGeneration;
    G7Core.state.set({ claimCompletePreviewLoading: true });

    const targets: ClaimCompleteTarget[] = context.targets ?? [];
    const restoreMap: Record<string, boolean> = globalState.claimCompleteRestore ?? {};
    const createRefundRequest: boolean = globalState.claimCompleteCreateRefundRequest !== false;
    // 「미회수로 완료」 로 고른 행 — 실행은 이 행도 완료하되 재고를 되돌리지 않는다. 미리보기가 모르면
    // 완료 건수·재고 요약·환불 예정액이 실행과 갈린다(D26). 서버가 신청서별 후보와 교집합을 낸다.
    const unreturnedIds: number[] = Object.keys(globalState.claimCompleteUnreturned ?? {}).map(Number).filter(Number.isFinite);

    // 보낸 맵을 기억해 둔다 — 응답이 상품 행을 새로 알려 주면 이 값과 비교해 재호출 여부를 정한다.
    const sentRestore = targets.map(
        (target) => buildRestoreByItem(target.id, restoreMap[String(target.id)] !== false),
    );

    const body = {
        claim_type: context.claimType,
        create_refund_request: createRefundRequest,
        claims: targets.map((target, index) => ({
            id: target.id,
            ...(target.item_ids && target.item_ids.length > 0 ? { item_ids: target.item_ids } : {}),
            // 서버는 상품 행 ID 단위 맵을 받는다. 모달의 체크는 신청서 단위이므로 앞선
            // 미리보기가 답한 `completable_item_ids` 전체에 같은 값을 편다.
            restore_stock_by_item: sentRestore[index],
            ...(unreturnedIds.length > 0 ? { unreturned_item_ids: unreturnedIds } : {}),
        })),
    };

    try {
        const response = await G7Core.api.post(PREVIEW_URL, body);

        // 더 새로운 요청(또는 모달 재진입·닫기)이 있었으면 이 응답은 옛 선택의 답이다.
        // 반영하면 화면이 옛 값을 그리는데 실행은 최신 값을 쓴다.
        if (generation !== previewGeneration) return false;

        const preview = response?.data ?? null;

        if (!preview) {
            throw new Error('empty preview');
        }

        // 환불이 발생하지 않는 대상만 모여 있으면 '지금 접수' 는 고를 수 없는 선택지다 —
        // 그대로 제출하면 서버가 422 로 거절하고 모달은 열린 채 남는다. 모달이 스스로
        // "0 건 생성" 을 표시하면서 기본값은 '지금 접수' 였던 것이 S17 B1 이다.
        // 한 건이라도 환불 대상이면 서버가 나머지를 건너뛰므로 선택을 건드리지 않는다.
        const refundApplicable = (preview?.claims ?? [])
            .some((claim: any) => claim?.refund?.applicable === true);
        const effectiveRefundRequest = refundApplicable ? createRefundRequest : false;

        G7Core.state.set({
            claimCompletePreview: preview,
            claimCompleteRows: buildClaimCompleteRows(preview, targets, restoreMap),
            claimCompleteCreateRefundRequest: effectiveRefundRequest,
            // 이 응답을 만든 환불신청서 선택 — 실패 시 되돌릴 기준이다. 재고 쪽은 행
            // (`claimCompleteRows[].restore`)이 같은 역할을 하므로 따로 두지 않는다.
            claimCompleteAppliedRefundRequest: effectiveRefundRequest,
        });

        // 응답이 답한 상품 행으로 맵을 다시 만들어 보낸 것과 다르면 요약이 체크 상태와
        // 어긋난 채 남는다 (첫 호출은 상품 행을 모른 채 빈 맵으로 나갔다). 한 번만 더 부른다.
        if (allowRetry && restoreMapChanged(targets, restoreMap, sentRestore)) {
            return refreshClaimCompletePreview(false);
        }

        return true;
    } catch (error: any) {
        // 옛 요청의 실패는 지금 화면의 사정이 아니다 — 되돌리기도 토스트도 하지 않는다.
        if (generation !== previewGeneration) return false;

        logger.error('[claimCompletePreview] 미리보기 실패:', error);

        // 모달은 직전 성공 응답을 계속 그린다 — 체크박스는 `claimCompleteRows[].restore`,
        // 요약은 `claimCompletePreview` 다. 그런데 실행이 쓰는 값은 이미 새 선택으로 바뀌어
        // 있으므로, 그대로 두면 화면이 예고하는 것과 실행하는 것이 갈린다(D26 이 모달을 둔
        // 이유가 그 예고다). 제출 버튼은 계속 활성이라 그 상태로 실행될 수 있다.
        revertSelectionToPreviewed();

        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.complete.preview_error', '완료 처리 요약을 불러오지 못했습니다.'),
        );

        return false;
    } finally {
        if (generation === previewGeneration) {
            G7Core.state.set({ claimCompletePreviewLoading: false });
        }
    }
}

/**
 * 선택 상태를 화면이 지금 그리고 있는 미리보기 기준으로 되돌립니다.
 *
 * 되돌리기 기준은 "직전 선택" 이 아니라 **화면에 반영된 값**이다 — 첫 요청은 성공하고
 * 재요청만 실패한 경우 화면은 이미 새 선택을 그리고 있으므로, 직전 값으로 되돌리면
 * 이번에는 반대 방향으로 어긋난다.
 *
 * 아직 그려진 미리보기가 없으면(첫 호출부터 실패) 되돌릴 화면 상태도 없다 — 그때는
 * 행이 비어 제출 버튼이 잠기므로 어긋난 채 실행될 경로가 없다.
 */
function revertSelectionToPreviewed(): void {
    const G7Core = core();
    if (!G7Core) return;

    const globalState = G7Core.state.get?.() ?? {};
    const rows: Array<Record<string, any>> = globalState.claimCompleteRows ?? [];

    if (rows.length === 0) return;

    G7Core.state.set({
        claimCompleteRestore: rows.reduce<Record<string, boolean>>((map, row) => {
            map[String(row.id)] = row.restore !== false;

            return map;
        }, {}),
        claimCompleteCreateRefundRequest: globalState.claimCompleteAppliedRefundRequest !== false,
    });
}

/**
 * 신청서 하나의 재고 복구 맵(상품 행 ID → 여부)을 만듭니다.
 *
 * 직전 미리보기가 답한 `completable_item_ids` 를 근거로 편다 — 그 목록을 아직 모르는 첫
 * 호출에서는 빈 맵이 되고, 응답이 목록을 알려 준 뒤 `restoreMapChanged()` 가 그 차이를
 * 잡아 미리보기를 한 번 더 부른다.
 *
 * @param claimId 신청서 ID
 * @param restore 신청서 단위 체크 상태
 * @returns 상품 행 ID → 복구 여부 맵
 */
function buildRestoreByItem(claimId: number, restore: boolean): Record<string, boolean> {
    const preview = core()?.state?.get?.()?.claimCompletePreview;
    const row = (preview?.claims ?? []).find((claim: any) => Number(claim.id) === Number(claimId));
    const itemIds: number[] = row?.completable_item_ids ?? [];

    return itemIds.reduce<Record<string, boolean>>((map, itemId) => {
        map[String(itemId)] = restore;

        return map;
    }, {});
}

/**
 * 직전 요청에 실은 재고 맵이, 방금 받은 응답 기준으로 다시 만든 맵과 달라졌는지 판정합니다.
 *
 * 첫 호출은 상품 행 ID 를 모른 채 빈 맵으로 나가므로 응답을 받고 나서야 실제로 보낼 맵이
 * 정해진다 — 그 차이를 여기서 잡아 재호출을 딱 한 번 유발한다.
 *
 * 비교는 **서버가 읽는 의미**로 한다 — 서버의 `shouldRestoreStock()` 은 없는 키를 false 로
 * 읽으므로(`ClaimCompletionOptions`), 빈 맵과 전건 false 맵은 같은 요청이다. 그래서 재고
 * 기본값이 꺼진 상점에서는 추가 요청이 아예 발생하지 않는다.
 *
 * @param targets 대상 목록
 * @param restoreMap 신청서 단위 체크 상태
 * @param sentRestore 직전 요청에 실은 신청서별 상품 행 맵
 * @returns 서버가 다르게 읽을 맵이면 true
 */
function restoreMapChanged(
    targets: ClaimCompleteTarget[],
    restoreMap: Record<string, boolean>,
    sentRestore: Array<Record<string, boolean>>,
): boolean {
    return targets.some((target, index) => {
        const next = buildRestoreByItem(target.id, restoreMap[String(target.id)] !== false);
        const sent = sentRestore[index] ?? {};

        return [...new Set([...Object.keys(next), ...Object.keys(sent)])]
            .some((key) => (next[key] ?? false) !== (sent[key] ?? false));
    });
}

/**
 * 모달이 그릴 행 목록을 만듭니다. (여는 쪽이 준 표기 + 서버 미리보기 결과의 병합)
 *
 * 서버 응답에는 상품 표기가 없고, 여는 쪽에는 환불 예정액이 없다. 표현식으로 두 배열을
 * 조인하면 모달이 진입점마다 다른 모양의 데이터를 알아야 하므로 여기서 한 벌로 만든다.
 *
 * @param preview 미리보기 응답 데이터
 * @param targets 여는 쪽이 넘긴 대상 목록
 * @param restoreMap 신청서 단위 재고 복구 체크 상태
 * @returns 모달 행 목록
 */
function buildClaimCompleteRows(
    preview: any,
    targets: ClaimCompleteTarget[],
    restoreMap: Record<string, boolean>,
): Array<Record<string, unknown>> {
    return (preview?.claims ?? []).map((claim: any) => {
        const target = targets.find((entry) => Number(entry.id) === Number(claim.id));

        return {
            id: claim.id,
            claim_number: claim.claim_number ?? target?.claim_number ?? null,
            requester_name: target?.requester_name ?? null,
            item_label: target?.item_label ?? null,
            completable_item_count: claim.completable_item_count ?? 0,
            refund_applicable: claim.refund?.applicable === true,
            refund_amount_formatted: claim.refund?.refund_amount_formatted ?? null,
            // 사용 마일리지 반환액 — 환불신청서는 현금과 함께 이 금액도 싣는다(EC-694). 0 이면 창이 줄을 그리지 않는다.
            refund_points_amount: Number(claim.refund?.refund_points_amount ?? 0),
            refund_points_amount_formatted: claim.refund?.refund_points_amount_formatted ?? null,
            blocked_reason: claim.refund?.blocked_reason ?? null,
            restore: restoreMap[String(claim.id)] !== false,
        };
    });
}

/**
 * 완료 처리 모달을 엽니다. (D26 — 세 진입점 공용 입구)
 *
 * @example
 * // 목록 일괄 변경에서
 * {
 *   "handler": "sirsoft-ecommerce.openClaimCompleteModal",
 *   "params": {
 *     "claimType": "return",
 *     "targets": [{ "id": 31, "claim_number": "RT...", "item_label": "니트 L" }],
 *     "defaults": "{{claims?.data?.complete_defaults ?? {}}}",
 *     "execute": { "url": "/api/.../admin/claims/bulk-status", "method": "POST", "body": { "type": "return", "ids": [31] } }
 *   }
 * }
 *
 * @param action 액션 객체 (params 포함)
 * @param _context 액션 컨텍스트
 */
export async function openClaimCompleteModalHandler(
    action: ActionWithParams<ClaimCompleteOpenParams>,
    _context?: ActionContext,
): Promise<void> {
    await openClaimCompleteModal(action.params ?? {});
}

/**
 * 완료 처리 모달을 엽니다. (핸들러와 내부 호출부의 공용 진입점)
 *
 * 일괄 변경 바의 '완료' 선택도 이 경로로 들어온다 — 그쪽에서 가짜 액션 객체를 만들어
 * 핸들러를 다시 부르면 핸들러 이름이 두 곳에 적히고, 한쪽만 고치면 조용히 갈린다.
 *
 * @param params 대상·실행 경로·초기 선택값
 */
async function openClaimCompleteModal(params: ClaimCompleteOpenParams): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const claimType = params.claimType ?? '';
    const targets = params.targets ?? [];
    const execute = params.execute;

    if (!execute || targets.length === 0) {
        logger.warn('[openClaimCompleteModal] 대상 또는 실행 경로가 없습니다.');

        return;
    }

    // 두 라디오·체크의 초기 선택값은 설정(`claim.complete_defaults`)이 정한다 — 초기값일
    // 뿐 자동 실행이 아니다 (D26). 설정을 읽지 못하면 계약 기본값(둘 다 true)으로 시드한다.
    const defaults = params.defaults ?? {};

    G7Core.state.set({
        claimCompleteContext: { claimType, targets, execute },
        claimCompletePreview: null,
        claimCompleteRows: [],
        claimCompleteRestore: seedRestoreMap(targets, defaults.restore_stock !== false),
        claimCompleteCreateRefundRequest: defaults.create_refund_request !== false,
        claimCompleteAppliedRefundRequest: defaults.create_refund_request !== false,
        // 미회수 선택은 신청서마다 다르다 — 앞서 취소한 모달의 행 ID 가 다음 신청서 요청에 실리면
        // 화면에 없는 행의 사유를 요구하거나 서버가 `claim_unreturned_not_eligible` 로 막는다.
        claimCompleteUnreturned: {},
    // 엔진의 전역 set 은 기본이 deep 병합이다 — 맵 값(`claimCompleteRestore`·`claimCompleteUnreturned`)을
    // 빈 객체·새 맵으로 **바꾸려면** shallow 여야 한다. deep 이면 앞 신청서의 키가 그대로 남는다(Chrome 실측 V-M6M7).
    }, { merge: 'shallow' });

    G7Core.modal?.open?.('modal_claim_complete');

    await refreshClaimCompletePreview();
}

/**
 * 재고 복구 체크의 초기 상태를 만듭니다. (설정 `complete_defaults.restore_stock` 기준)
 *
 * @param targets 대상 목록
 * @param initial 초기 체크 상태
 * @returns 신청서 ID → 체크 상태 맵
 */
function seedRestoreMap(targets: ClaimCompleteTarget[], initial: boolean): Record<string, boolean> {
    return targets.reduce<Record<string, boolean>>((map, target) => {
        map[String(target.id)] = initial;

        return map;
    }, {});
}

/**
 * 완료 처리 모달의 신청서 단위 재고 복구 체크를 토글합니다.
 *
 * @param action 액션 객체 (params.claimId / params.restore)
 * @param _context 액션 컨텍스트
 */
export async function toggleClaimCompleteStockHandler(
    action: ActionWithParams<{ claimId?: number | string; restore?: boolean }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const claimId = params.claimId;
    if (claimId === undefined || claimId === null) return;

    const current: Record<string, boolean> = G7Core.state.get?.()?.claimCompleteRestore ?? {};

    G7Core.state.set({
        claimCompleteRestore: { ...current, [String(claimId)]: params.restore === true },
    });

    await scheduleClaimCompletePreview();
}

/**
 * 완료 처리 모달의 헤더 일괄 체크를 반영합니다.
 *
 * @param action 액션 객체 (params.restore)
 * @param _context 액션 컨텍스트
 */
export async function toggleClaimCompleteStockAllHandler(
    action: ActionWithParams<{ restore?: boolean }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const restore = action.params?.restore === true;
    const targets: ClaimCompleteTarget[] = G7Core.state.get?.()?.claimCompleteContext?.targets ?? [];

    G7Core.state.set({
        claimCompleteRestore: targets.reduce<Record<string, boolean>>((map, target) => {
            map[String(target.id)] = restore;

            return map;
        }, {}),
    });

    await scheduleClaimCompletePreview();
}

/**
 * 환불신청서 접수 여부 라디오를 반영합니다.
 *
 * @param action 액션 객체 (params.value)
 * @param _context 액션 컨텍스트
 */
export async function setClaimCompleteRefundRequestHandler(
    action: ActionWithParams<{ value?: boolean }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    G7Core.state.set({ claimCompleteCreateRefundRequest: action.params?.value === true });

    await scheduleClaimCompletePreview();
}

/**
 * 완료 처리를 실행합니다. (D26 — 모달의 [N건 완료 처리])
 *
 * 미리보기와 실행은 페이로드 모양이 다르다 — 미리보기는 신청서마다 재고 맵을 나눠 싣고,
 * 실행은 `completion.restore_stock_by_item` 한 맵에 전 신청서의 상품 행 ID 를 모은다.
 * 그래서 같은 객체를 그대로 재사용하지 않고 여기서 다시 만든다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function executeClaimCompletionHandler(
    _action: ActionWithParams,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const globalState = G7Core.state.get() ?? {};
    const context = globalState.claimCompleteContext;
    const rows: Array<Record<string, any>> = globalState.claimCompleteRows ?? [];

    // 조기 종료는 **실패**로 끝낸다 — 그냥 return 하면 액션이 성공으로 끝나 레이아웃의
    // success 갈래가 모달을 닫는다(대상이 없다는 오류 토스트와 함께 닫히기도 했다). throw 하면
    // fail 갈래가 진행 표식만 되돌리고 모달은 남아, 운영자가 그 자리에서 다시 시도한다.
    if (globalState.claimCompletePreviewLoading === true) {
        throw new Error('claim completion preview is still loading');
    }

    if (!context?.execute || rows.length === 0) {
        G7Core.toast?.error?.(t('sirsoft-ecommerce.admin.claim.complete.error', '완료 처리에 실패했습니다.'));

        throw new Error('claim completion has no target');
    }

    const restoreMap: Record<string, boolean> = globalState.claimCompleteRestore ?? {};
    const preview = globalState.claimCompletePreview;

    // 미리보기가 답한 대상 신청서만 싣는다 — 조회되지 않은 건(skipped)은 대상에서 뺀다.
    const previewClaims: any[] = preview?.claims ?? [];
    const mergedRestore = previewClaims.reduce<Record<string, boolean>>((map, claim: any) => {
        const restore = restoreMap[String(claim.id)] !== false;
        (claim.completable_item_ids ?? []).forEach((itemId: number) => {
            map[String(itemId)] = restore;
        });

        return map;
    }, {});

    // 선교환 「미회수로 완료」(D3) — 체크한 행은 사유가 필수다. 빈 사유를 보내면 서버가 422 로 막지만,
    // 모달을 닫기 전에 먼저 세운다.
    const unreturned: Record<string, string> = globalState.claimCompleteUnreturned ?? {};

    if (Object.values(unreturned).some((reason) => String(reason ?? '').trim() === '')) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.complete.unreturned_reason_required', '미회수로 완료할 상품의 사유를 입력해주세요.'),
        );

        throw new Error('claim completion unreturned reason missing');
    }

    const body: Record<string, unknown> = {
        ...(context.execute.body ?? {}),
        status: 'completed',
        completion: {
            restore_stock_by_item: mergedRestore,
            create_refund_request: globalState.claimCompleteCreateRefundRequest !== false,
            ...(Object.keys(unreturned).length > 0 ? { unreturned } : {}),
        },
    };

    // 일괄 경로는 미리보기에서 살아남은 신청서로 대상을 좁힌다.
    if (Array.isArray((context.execute.body ?? {}).ids)) {
        body.ids = previewClaims.map((claim: any) => Number(claim.id));
    }

    try {
        const response = context.execute.method === 'PATCH'
            ? await G7Core.api.patch(context.execute.url, body)
            : await G7Core.api.post(context.execute.url, body);

        if (response?.success === false) {
            throw new Error(response?.message ?? 'completion failed');
        }

        // 재조회·정리 대상은 여는 쪽이 정한다 — 목록에서 열면 목록을, 상세에서 열면 상세를
        // 다시 읽는다. 여기에 화면 이름을 박으면 나머지 진입점의 화면이 옛 값을 그린 채 남는다.
        const refetchTargets = context.execute.refetch ?? 'claims';
        const clearLocal = context.execute.clearLocal ?? { selectedItems: [], bulkClaimStatus: '' };

        G7Core.toast?.success?.(t('sirsoft-ecommerce.admin.claim.complete.success', '완료 처리를 반영했습니다.'));
        reportBulkSkips(response?.data);
        resetClaimCompleteState();
        G7Core.state.setLocal?.(clearLocal);
        refetchSources(refetchTargets);
    } catch (error: any) {
        logger.error('[executeClaimCompletion] 완료 처리 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.complete.error', '완료 처리에 실패했습니다.'),
        );
        throw error;
    }
}

/**
 * 완료 처리 모달 상태를 비웁니다. (다음 진입이 앞선 선택을 물려받지 않게)
 */
function resetClaimCompleteState(): void {
    core()?.state?.set?.({
        claimCompleteContext: null,
        claimCompletePreview: null,
        claimCompleteRows: [],
        claimCompleteRestore: {},
        claimCompleteCreateRefundRequest: null,
        claimCompleteAppliedRefundRequest: null,
        claimCompleteUnreturned: {},
    }, { merge: 'shallow' });
}

/**
 * 완료 모달의 「미회수로 완료」 체크를 토글합니다. (선교환 D3)
 *
 * 체크된 행은 `_global.claimCompleteUnreturned` 맵의 키로 둔다 — 값은 사유다(빈 문자열로 시작).
 *
 * @param action 액션 객체 (params: itemId / checked)
 * @param _context 액션 컨텍스트
 */
export function toggleClaimCompleteUnreturnedHandler(
    action: ActionWithParams<{ itemId?: number | string; checked?: boolean | string }>,
    _context?: ActionContext,
): void {
    const G7Core = core();
    if (!G7Core) return;

    const key = String(action.params?.itemId ?? '');
    if (key === '') return;

    const checked = action.params?.checked === true || action.params?.checked === 'true';
    const current: Record<string, string> = { ...(G7Core.state.get()?.claimCompleteUnreturned ?? {}) };

    if (checked) {
        current[key] = current[key] ?? '';
    } else {
        delete current[key];
    }

    // 맵을 통째로 바꾼다 — deep 병합이면 뺀 키가 남아 체크 해제가 풀리지 않는다(Chrome 실측 V-M6M7).
    G7Core.state.set({ claimCompleteUnreturned: current }, { merge: 'shallow' });
    // 미회수 행이 완료 대상에 들고 나면 건수·재고 요약·환불 예정액이 바뀐다 — 미리보기를 다시 받는다.
    void scheduleClaimCompletePreview();
}

/**
 * 완료 모달의 「미회수로 완료」 사유를 씁니다. (선교환 D3)
 *
 * @param action 액션 객체 (params: itemId / reason)
 * @param _context 액션 컨텍스트
 */
export function setClaimCompleteUnreturnedReasonHandler(
    action: ActionWithParams<{ itemId?: number | string; reason?: string | null }>,
    _context?: ActionContext,
): void {
    const G7Core = core();
    if (!G7Core) return;

    const key = String(action.params?.itemId ?? '');
    const current: Record<string, string> = { ...(G7Core.state.get()?.claimCompleteUnreturned ?? {}) };

    if (key === '' || !Object.prototype.hasOwnProperty.call(current, key)) return;

    current[key] = String(action.params?.reason ?? '');
    G7Core.state.set({ claimCompleteUnreturned: current }, { merge: 'shallow' });
}

/**
 * 일괄 상태 변경 확인 데이터를 만듭니다.
 *
 * 완료 전이는 곧바로 실행하지 않고 완료 처리 모달을 연다 (D26) — 재고 복구 여부와
 * 환불신청서 접수 여부를 운영자가 골라야 하고, 그 선택 없이 보내면 요청이 422 다.
 *
 * @param action 액션 객체 (params.claimType / status / rejectReason / ids / rows)
 * @param _context 액션 컨텍스트
 */
export async function buildClaimBulkConfirmDataHandler(
    action: ActionWithParams<{
        claimType?: string;
        status?: string;
        rejectReason?: string | null;
        ids?: number[];
        rows?: ClaimListRow[];
        completeDefaults?: ClaimCompleteDefaults;
        carriers?: CarrierOption[];
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const claimType = params.claimType ?? '';
    const status = params.status ?? '';
    const ids = (params.ids ?? []).map((id) => Number(id));
    const rows = params.rows ?? [];

    if (ids.length === 0) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.bulk.no_selection', '변경할 신청서를 선택해주세요.'),
        );

        return;
    }

    if (!status) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.bulk.no_status_selected', '변경할 상태를 선택해주세요.'),
        );

        return;
    }

    if (claimType === 'cancel') {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.bulk.cancel_not_transitionable', '취소는 일괄 상태 변경 대상이 아닙니다.'),
        );

        return;
    }

    if (rejectReasonMissing(status, params.rejectReason)) {
        return;
    }

    if (status === 'completed') {
        await openClaimCompleteModal({
            claimType,
            targets: ids.map((id) => toCompleteTarget(id, rows)),
            defaults: params.completeDefaults ?? {},
            execute: { url: BULK_STATUS_URL, method: 'POST', body: { type: claimType, ids } },
        });

        return;
    }

    G7Core.state.set({
        claimBulkConfirmData: {
            claimType,
            ids,
            status,
            statusLabel: resolveStatusLabel(claimType, status),
            // 목록 일괄 반려의 사유 — 고른 신청서 전부에 같은 사유가 들어간다(#599 후속 운영 결정).
            rejectReason: status === 'rejected' ? (params.rejectReason ?? '').trim() : '',
            // 회수중·발송중은 신청서마다 송장을 받는다 — 송장은 신청서마다 다르다(#599 후속 운영 결정).
            // 저장된 송장이 있으면 미리 채워 같은 번호를 다시 치지 않게 한다.
            trackings: TRACKING_REQUIRED_STATUSES.includes(status) ? ids.map((id) => bulkTrackingRow(id, status, rows)) : [],
            carriers: params.carriers ?? [],
        },
    });

    G7Core.modal?.open?.('modal_claim_bulk_confirm');
}

/** 상세 화면의 신청 상품 행 (필요한 축만) */
interface ClaimDetailItemRow {
    id: number;
    product_name?: string | null;
    option_name?: string | null;
    quantity?: number | null;
    /** 행 송장 (방향별 — 교환·반품) */
    collection_carrier_id?: number | null;
    collection_tracking_number?: string | null;
    redelivery_carrier_id?: number | null;
    redelivery_tracking_number?: string | null;
    /** 이 행의 회수지 (S4 — 회수 운송장 묶기 판정 R8) */
    return_address_snapshot?: Record<string, unknown> | null;
}

/** 상세 화면 액션이 공통으로 넘기는 신청서 식별 축 */
interface ClaimDetailTarget {
    /** 유형 스코프 경로 세그먼트 (서버가 준 `claim_path_segment`) */
    pathSegment?: string;
    /** 신청서 ID */
    claimId?: number | string | null;
}

/**
 * 유형 스코프 API 의 기본 경로를 만듭니다.
 *
 * 세그먼트는 서버가 준 값을 그대로 쓴다 — 화면이 유형 값에서 조립하면 그 조립이 세그먼트
 * 규칙의 두 번째 출처가 되고, 규칙이 바뀌어도 오류 없이 404 만 나는 URL 이 만들어진다.
 *
 * @param target 신청서 식별 축
 * @returns 기본 경로 (식별 축이 비면 null)
 */
function claimScopedBase(target: ClaimDetailTarget): string | null {
    const segment = (target.pathSegment ?? '').trim();
    const claimId = target.claimId;

    if (!segment || claimId === undefined || claimId === null || claimId === '') {
        return null;
    }

    return `/api/modules/sirsoft-ecommerce/admin/${segment}/${claimId}`;
}

/**
 * 상세 화면의 신청 상품 행에서 완료 모달의 상품 표기를 만듭니다.
 *
 * @param rows 신청 상품 행
 * @param itemIds 이번 라운드 대상 행 ID (비면 전체)
 * @returns 상품 표기 (없으면 null)
 */
function detailItemLabel(rows: ClaimDetailItemRow[], itemIds: number[]): string | null {
    const targets = itemIds.length > 0
        ? rows.filter((row) => itemIds.includes(Number(row.id)))
        : rows;

    const label = targets
        .map((row) => row.product_name)
        .filter((name): name is string => typeof name === 'string' && name !== '')
        .join(', ');

    return label === '' ? null : label;
}

/**
 * 클레임 상세의 [완료 처리] 버튼에서 완료 처리 모달을 엽니다. (D26 — 진입점 ③)
 *
 * 신청서 단위 진입점이므로 `item_ids` 를 싣지 않는다 — 생략하면 서버가 그 신청서의 완료
 * 가능 상품 전체를 대상으로 본다. 목록·상품 단위와 **같은 모달 파일**을 연다.
 *
 * @param action 액션 객체 (params)
 * @param _context 액션 컨텍스트
 */
export async function openClaimDetailCompleteHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimType?: string;
        claimId?: number | string | null;
        claimNumber?: string | null;
        requesterName?: string | null;
        rows?: ClaimDetailItemRow[];
        completeDefaults?: ClaimCompleteDefaults;
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const base = claimScopedBase(params);

    if (base === null) {
        logger.warn('[openClaimDetailComplete] 신청서 식별 축이 없습니다.');

        return;
    }

    await openClaimCompleteModal({
        claimType: params.claimType ?? '',
        targets: [{
            id: Number(params.claimId),
            claim_number: params.claimNumber ?? null,
            requester_name: params.requesterName ?? null,
            item_label: detailItemLabel(params.rows ?? [], []),
        }],
        defaults: params.completeDefaults ?? {},
        execute: {
            url: `${base}/status`,
            method: 'PATCH',
            refetch: DETAIL_REFETCH_SOURCES,
            clearLocal: { selectedClaimItems: [], itemBulkStatus: '' },
        },
    });
}

/** 수량 확인 모달이 그리는 행 한 줄 */
interface ClaimItemQuantityRow {
    id: number;
    label: string;
    quantity: number;
    splittable: boolean;
}

/** 수량 확인 모달이 읽는 문맥 — 모달은 부모 화면의 `_local` 을 해석하지 못한다 */
interface ClaimItemQuantityContext {
    pathSegment: string;
    claimType: string;
    claimId: number;
    claimNumber: string;
    requesterName: string;
    status: string;
    statusLabel: string;
    /** 반려 사유 — 반려일 때만 실린다(모달 [확인] 이 실행 본문에 그대로 싣는다). */
    rejectReason: string;
    /** 회수중·발송중의 택배사 ID — 그 두 상태일 때만 실린다 */
    carrierId: string;
    /** 회수중·발송중의 운송장 번호 */
    trackingNumber: string;
    /** 모달 확인 문구용 택배사 이름 */
    carrierLabel: string;
    itemIds: number[];
    rows: ClaimItemQuantityRow[];
    quantities: Record<string, number>;
    completeDefaults: ClaimCompleteDefaults;
    detailRows: ClaimDetailItemRow[];
    /** 「송장만 저장」 모드 — 상태는 옮기지 않고 행 송장만 쓴다(R7) */
    trackingOnly?: boolean;
    /** 송장 방향 (`collection` / `redelivery`) */
    direction?: string;
    /** 모달 문구용 방향 라벨 */
    directionLabel?: string;
}

/**
 * 전이 지시 본문을 만듭니다. (`items: [{id, quantity?}]`)
 *
 * 서버는 이 한 모양만 받는다 — 스칼라 배열을 함께 허용하면 상위 스코프 검사가 두 벌이 되어
 * 다른 신청서의 행이 통과하는 결함이 되살아난다. 수량을 싣지 않은 행은 전량이다.
 *
 * @param itemIds 대상 상품 행 ID
 * @param quantities 행별 전이 수량 (없으면 전량)
 * @returns 요청 본문의 `items`
 */
function claimItemPayload(
    itemIds: number[],
    quantities?: Record<string, number | string | null>,
): Array<{ id: number; quantity?: number }> {
    return itemIds.map((id) => {
        const raw = Number(quantities?.[String(id)]);

        return Number.isFinite(raw) && raw > 0 ? { id, quantity: raw } : { id };
    });
}

/**
 * 선택된 행들이 **함께** 갈 수 있는 상태의 교집합을 만듭니다.
 *
 * 어휘는 서버가 행마다 실어 준다(`transition_options`) — 화면이 상태 목록을 리터럴로 들면
 * 전이표가 바뀔 때 화면과 서버가 갈리고, 그 사이 운영자는 "가끔 안 되는 버튼" 을 본다.
 *
 * 교집합을 쓰는 이유: 선택한 행들의 현재 상태가 서로 다를 수 있고, 한 행만 갈 수 있는 상태를
 * 목록에 세우면 나머지는 **건너뛰기로 보고되며 조용히 아무 일도 일어나지 않는다.**
 *
 * @param rows 상세 상품 행 (서버 응답 그대로)
 * @param selectedIds 체크된 행 ID
 * @returns 교집합 선택지 (등장 순서 유지)
 */
function commonTransitionOptions(
    rows: ClaimDetailItemRow[],
    selectedIds: number[],
): Array<{ value: string; label: string }> {
    const selected = rows.filter((row) => selectedIds.includes(Number(row.id)));

    if (selected.length === 0) return [];

    const first = (selected[0] as any).transition_options ?? [];

    return first.filter((option: { value: string }) => selected.every(
        (row) => ((row as any).transition_options ?? []).some(
            (candidate: { value: string }) => candidate.value === option.value,
        ),
    ));
}

/**
 * 선택이 바뀔 때 일괄 바가 읽는 상태를 한 번에 깝니다. (§4-4)
 *
 * 레이아웃 인라인 식으로 교집합을 내지 않는 이유는 두 가지다. 구조 테스트가 `if` 를 단일
 * 바인딩으로 못박고 있고(`claim_detail_if_conditions_are_single_bindings`), **선택이 바뀌면
 * 이미 고른 상태가 새 교집합에 없을 수 있어** 그 판정이 갈리면 화면엔 값이 남았는데 서버는
 * 422 를 준다. 그래서 선택지와 고른 값을 같은 자리에서 함께 정한다.
 *
 * @param action 액션 객체 (params: selectedIds / rows)
 * @param _context 액션 컨텍스트
 */
export function syncClaimItemSelectionHandler(
    action: ActionWithParams<{ selectedIds?: Array<number | string>; rows?: ClaimDetailItemRow[] }>,
    _context?: ActionContext,
): void {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const selectedIds = (params.selectedIds ?? []).map((id) => Number(id));
    const rows = params.rows ?? [];
    const options = commonTransitionOptions(rows, selectedIds);
    const local = G7Core.state.getLocal?.() ?? {};
    const current = local.itemBulkStatus ?? '';
    const nextStatus = options.some((option) => option.value === current) ? current : '';

    G7Core.state.setLocal?.({
        selectedClaimItems: selectedIds,
        itemTransitionOptions: options,
        // 고른 상태가 새 교집합 밖이면 비운다 — 남겨 두면 화면엔 값이 보이는데 [적용] 이 422 다.
        itemBulkStatus: nextStatus,
        ...reprefilledTracking(local, rows, selectedIds, nextStatus),
    });
}

/**
 * 선택이 바뀔 때 미리 채운 송장을 새 선택 기준으로 다시 정합니다. (P1 「미리 채움」의 선택 변경 축)
 *
 * 미리 채움은 상태를 고를 때·송장 칸을 열 때·방향을 바꿀 때 계산된다. 그 **뒤에** 송장 없는 행을 더
 * 고르면 앞 선택의 송장이 칸에 남아, 그대로 적용하면 송장 없는 상품에 다른 상품의 송장이 붙었다
 * (2026-10-07 실측). 칸의 값이 **직전 선택의 자동 값과 같을 때만** 새 선택의 공통 송장(없으면 빈 값)으로
 * 바꾼다 — 운영자가 손으로 적은 값은 건드리지 않는다.
 *
 * @param local 화면 지역 상태 (직전 선택·상태·송장 칸)
 * @param rows 상세 상품 행
 * @param selectedIds 새로 고른 행 ID
 * @param status 새 선택에서 남은 목표 상태
 * @returns 바꿀 송장 칸 (바꿀 것이 없으면 빈 객체)
 */
function reprefilledTracking(
    local: Record<string, any>,
    rows: ClaimDetailItemRow[],
    selectedIds: number[],
    status: string,
): { itemCarrierId?: string; itemTrackingNumber?: string } {
    const trackingMode = local.itemTrackingMode === true;

    if (!trackingMode && !TRACKING_REQUIRED_STATUSES.includes(status)) {
        return {};
    }

    const direction = trackingMode
        ? (local.itemTrackingDirection === 'redelivery' ? 'redelivery' : 'collection')
        : directionForStatus(status);
    const previousIds: number[] = (local.selectedClaimItems ?? []).map((id: unknown) => Number(id));
    const previous = commonRowTracking(rows, previousIds, direction);
    const untouched = String(local.itemCarrierId ?? '') === previous.carrierId
        && String(local.itemTrackingNumber ?? '') === previous.trackingNumber;

    if (!untouched) {
        return {};
    }

    const next = commonRowTracking(rows, selectedIds, direction);

    return { itemCarrierId: next.carrierId, itemTrackingNumber: next.trackingNumber };
}

/**
 * 수량 확인 모달 문맥에 실을 송장 축을 만듭니다. (회수중·발송중이 아니면 빈 값)
 *
 * @param status 목표 상태
 * @param carrierId 택배사 ID
 * @param trackingNumber 운송장 번호
 * @param carriers 택배사 선택지 (확인 문구의 이름 해석용)
 * @returns 문맥의 송장 축
 */
function itemTrackingContext(
    status: string,
    carrierId: string | number | null | undefined,
    trackingNumber: string | null | undefined,
    carriers: CarrierOption[],
): Pick<ClaimItemQuantityContext, 'carrierId' | 'trackingNumber' | 'carrierLabel'> {
    if (!TRACKING_REQUIRED_STATUSES.includes(status)) {
        return { carrierId: '', trackingNumber: '', carrierLabel: '' };
    }

    const carrier = String(carrierId ?? '');

    return {
        carrierId: carrier,
        trackingNumber: (trackingNumber ?? '').trim(),
        carrierLabel: carriers.find((option) => String(option.value) === carrier)?.label ?? '',
    };
}

/**
 * 수량 확인 모달을 엽니다. (§4-6 — 일괄 바 [적용] 의 진입점)
 *
 * 수량 입력을 일괄 바에 두면 행이 여럿일 때 수량이 하나뿐이라 "3 중 2, 5 중 1" 을 표현할 수
 * 없고, 행 인라인에 두면 선택과 무관하게 살아 있어 "체크하지 않은 행의 수량을 바꿔 둔"
 * 상태가 생긴다. 주문상세 선례도 확인 모달이다.
 *
 * 완료(`completed`)는 이 모달을 거치지 않는다 — 완료 처리 모달(D26)이 재고 복구·환불신청서
 * 접수를 함께 물으며, 완료의 수량 분할은 이번 범위 밖이다(전량 완료만).
 *
 * @param action 액션 객체 (params)
 * @param context 액션 컨텍스트
 */
export async function openClaimItemQuantityConfirmHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimType?: string;
        claimId?: number | string | null;
        claimNumber?: string | null;
        requesterName?: string | null;
        itemIds?: Array<number | string>;
        status?: string;
        rejectReason?: string | null;
        carrierId?: string | number | null;
        trackingNumber?: string | null;
        carriers?: CarrierOption[];
        rows?: ClaimDetailItemRow[];
        completeDefaults?: ClaimCompleteDefaults;
    }>,
    context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const status = params.status ?? '';
    const itemIds = (params.itemIds ?? []).map((id) => Number(id));
    const rows = params.rows ?? [];

    if (itemIds.length === 0) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.detail.items.bulk_no_selection', '변경할 상품을 선택해주세요.'),
        );

        return;
    }

    if (!status) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.detail.items.bulk_no_status_selected', '변경할 상태를 선택해주세요.'),
        );

        return;
    }

    if (rejectReasonMissing(status, params.rejectReason)) {
        return;
    }

    if (trackingMissing(status, params.carrierId, params.trackingNumber)) {
        return;
    }

    if (status === 'completed') {
        await executeClaimItemBulkStatusHandler(action as any, context);

        return;
    }

    const selected = rows.filter((row) => itemIds.includes(Number(row.id)));
    const modalRows: ClaimItemQuantityRow[] = selected.map((row) => ({
        id: Number(row.id),
        label: [row.product_name ?? '', row.option_name ?? ''].filter(Boolean).join(' / '),
        quantity: Number(row.quantity ?? 1),
        // 서버가 실어 준 판정을 그대로 쓴다 — 화면이 조건을 복제하면 교환 후속 옵션 규칙이
        // 두 벌이 되고, 한쪽만 고쳐졌을 때 운영자는 입력해 놓고 [확인] 에서야 422 를 본다.
        splittable: (row as any).is_splittable === true && Number(row.quantity ?? 1) > 1,
    }));

    const quantities: Record<string, number> = {};
    modalRows.forEach((row) => {
        quantities[String(row.id)] = row.quantity;
    });

    const prefix = STATUS_LABEL_PREFIX[params.claimType ?? ''] ?? '';

    const modalContext: ClaimItemQuantityContext = {
        pathSegment: params.pathSegment ?? '',
        claimType: params.claimType ?? '',
        claimId: Number(params.claimId),
        claimNumber: params.claimNumber ?? '',
        requesterName: params.requesterName ?? '',
        status,
        statusLabel: prefix ? t(`${prefix}${status}`, status) : status,
        rejectReason: status === 'rejected' ? (params.rejectReason ?? '').trim() : '',
        ...itemTrackingContext(status, params.carrierId, params.trackingNumber, params.carriers ?? []),
        itemIds,
        rows: modalRows,
        quantities,
        completeDefaults: params.completeDefaults ?? {},
        detailRows: rows,
    };

    // 문맥을 통째로 바꾼다 — [취소] 는 창만 닫으므로 deep 병합이면 앞서 연 「송장만 저장」 의
    // `trackingOnly`·`direction` 이 남아 [적용] 이 송장 저장으로 나간다(사례 43).
    G7Core.state.set({ claimItemQuantityContext: modalContext }, { merge: 'shallow' });
    G7Core.modal?.open?.('modal_claim_item_quantity_confirm');
}

/**
 * 확인 모달의 수량 입력을 클램프해 문맥에 씁니다.
 *
 * 주문상세의 수량 핸들러를 공유하지 않는다 — 그쪽은 `_local.changeQuantities` 라는 주문상세
 * 전용 키에 쓰므로, 공유하면 탭을 갔다 온 뒤 두 화면의 수량이 섞인다.
 *
 * @param action 액션 객체 (params: itemId / value)
 * @param _context 액션 컨텍스트
 */
export function updateClaimItemQuantityHandler(
    action: ActionWithParams<{ itemId?: number | string; value?: number | string }>,
    _context?: ActionContext,
): void {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const itemId = Number(params.itemId);
    const context: ClaimItemQuantityContext | null = G7Core.state.get()?.claimItemQuantityContext ?? null;

    if (!context || !Number.isFinite(itemId)) return;

    const row = context.rows.find((candidate) => candidate.id === itemId);

    if (!row) return;

    const raw = Number(params.value);
    // 빈 입력·문자 입력은 1 로 본다 — NaN 을 그대로 두면 본문에 실려 422 가 된다.
    const clamped = Number.isFinite(raw) ? Math.min(Math.max(Math.trunc(raw), 1), row.quantity) : 1;

    G7Core.state.set({
        claimItemQuantityContext: {
            ...context,
            quantities: { ...context.quantities, [String(itemId)]: clamped },
        },
    });
}

/**
 * 분할이 일어난 행을 운영자에게 알립니다.
 *
 * 표가 한 줄 늘어난 것을 스스로 해석하게 두지 않는다 — "2개 처리, 1개는 지금 상태 그대로 남음".
 * 남은 수량은 **나누기 전 상태**에 남는다(심사 단계면 신청, 발송중 상품을 일부만 발송도착으로 옮기면 발송중) —
 * 「신청 상태」 라고 적으면 표와 어긋난다(S1-D10).
 *
 * @param data 전이 응답 데이터
 */
function reportClaimItemSplits(data: any): void {
    const G7Core = core();
    const splits = data?.split ?? [];

    if (!G7Core || splits.length === 0) return;

    const kept = splits.reduce((sum: number, entry: any) => sum + Number(entry?.quantity ?? 0), 0);
    const remaining = splits.reduce((sum: number, entry: any) => sum + Number(entry?.remainder_quantity ?? 0), 0);

    G7Core.toast?.info?.(
        t(
            'sirsoft-ecommerce.admin.claim.detail.items.bulk_split_notice',
            `${kept}개를 처리하고 ${remaining}개는 지금 상태 그대로 남겼습니다.`,
            { kept, remaining, count: kept },
        ),
    );
}

/**
 * 신청 상품 단위 일괄 전이를 실행합니다. (D25 — 상세 화면 신청상품 일괄 변경 바)
 *
 * 완료 전이는 곧바로 실행하지 않고 완료 처리 모달을 연다 (D26 — 진입점 ②). 그쪽은 체크한
 * 상품만 이번 라운드 대상이므로 `item_ids` 를 함께 싣는다 — 생략하면 서버가 그 신청서의
 * 완료 가능 상품 전체를 대상으로 본다.
 *
 * 그 외 전이는 부분 성공 계약이다 — 게이트에 막힌 상품은 건너뛰고 사유와 함께 보고된다.
 *
 * @param action 액션 객체 (params)
 * @param _context 액션 컨텍스트
 */
export async function executeClaimItemBulkStatusHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimType?: string;
        claimId?: number | string | null;
        claimNumber?: string | null;
        requesterName?: string | null;
        itemIds?: Array<number | string>;
        itemQuantities?: Record<string, number | string | null>;
        status?: string;
        rejectReason?: string | null;
        carrierId?: string | number | null;
        trackingNumber?: string | null;
        rows?: ClaimDetailItemRow[];
        completeDefaults?: ClaimCompleteDefaults;
        trackingOnly?: boolean;
        direction?: string | null;
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const claimType = params.claimType ?? '';
    const status = params.status ?? '';
    const itemIds = (params.itemIds ?? []).map((id) => Number(id));
    const rows = params.rows ?? [];
    const base = claimScopedBase(params);

    if (base === null) {
        logger.warn('[executeClaimItemBulkStatus] 신청서 식별 축이 없습니다.');

        return;
    }

    // 「송장만 저장」 — 같은 모달이 상태 전이와 송장 저장을 모두 확인하므로 실행도 같은 입구에서 가른다.
    if (params.trackingOnly === true) {
        await saveClaimItemTracking(base, {
            itemIds,
            itemQuantities: params.itemQuantities,
            direction: params.direction,
            carrierId: params.carrierId,
            trackingNumber: params.trackingNumber,
        });

        return;
    }

    // 취소신청서만 상품 단위 전이 축이 없다 — 생성이 곧 확정이라 옮길 상태가 없다.
    // 환불은 §4 에서 이 축이 열렸다(승인·보류·반려를 상품마다 가를 수 있다).
    // 안내는 전용 키다 — 「변경할 상품을 선택해주세요」 는 상품을 고른 운영자에게 막힌 이유를
    // 틀리게 말한다(선택이 아니라 유형이 이유다).
    if (claimType === 'cancel') {
        G7Core.toast?.warning?.(
            t(
                'sirsoft-ecommerce.admin.claim.detail.items.cancel_not_transitionable',
                '취소 신청서는 상품별로 옮길 상태가 없습니다. 취소는 접수와 동시에 확정됩니다.',
            ),
        );

        return;
    }

    if (itemIds.length === 0) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.detail.items.bulk_no_selection', '변경할 상품을 선택해주세요.'),
        );

        return;
    }

    if (!status) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.detail.items.bulk_no_status_selected', '변경할 상태를 선택해주세요.'),
        );

        return;
    }

    if (rejectReasonMissing(status, params.rejectReason)) {
        return;
    }

    if (trackingMissing(status, params.carrierId, params.trackingNumber)) {
        return;
    }

    if (status === 'completed') {
        await openClaimCompleteModal({
            claimType,
            targets: [{
                id: Number(params.claimId),
                claim_number: params.claimNumber ?? null,
                requester_name: params.requesterName ?? null,
                item_label: detailItemLabel(rows, itemIds),
                item_ids: itemIds,
            }],
            defaults: params.completeDefaults ?? {},
            execute: {
                url: `${base}/options/bulk-status`,
                method: 'PATCH',
                // 완료는 전량만이다 — 완료 모달은 재고 복구를 **행 단위**로 물으므로 거기에
                // 수량 축까지 얹으면 "2개는 복구, 1개는 폐기" 같은 답이 필요해진다.
                body: { items: claimItemPayload(itemIds) },
                refetch: DETAIL_REFETCH_SOURCES,
                clearLocal: { selectedClaimItems: [], itemBulkStatus: '', itemTransitionOptions: [] },
            },
        });

        return;
    }

    if (G7Core.state.getLocal?.()?.isUpdatingClaimItems === true) return;
    // 잠금은 선택 바 두 버튼·확인 창이 함께 쓰고, 스피너는 누른 쪽에만 돈다 — 종류로 가른다.
    G7Core.state.setLocal?.({ isUpdatingClaimItems: true, claimItemsUpdateKind: 'status' });

    try {
        const body: Record<string, unknown> = {
            items: claimItemPayload(itemIds, params.itemQuantities),
            status,
        };

        if (status === 'rejected') {
            body.reject_reason = (params.rejectReason ?? '').trim();
        }

        if (TRACKING_REQUIRED_STATUSES.includes(status)) {
            body.carrier_id = Number(params.carrierId);
            body.tracking_number = (params.trackingNumber ?? '').trim();
        }

        const response = await G7Core.api.patch(`${base}/options/bulk-status`, body);

        if (response?.success === false) {
            throw new Error(response?.message ?? 'option bulk status failed');
        }

        const data = response?.data ?? {};

        G7Core.toast?.success?.(
            t('sirsoft-ecommerce.admin.claim.detail.items.bulk_success', '신청 상품 상태 변경을 처리했습니다.', {
                succeeded: (data.succeeded ?? []).length,
                skipped: (data.skipped ?? []).length,
            }),
        );
        reportBulkSkips(data);
        reportClaimItemSplits(data);

        G7Core.modal?.close?.('modal_claim_item_quantity_confirm');
        G7Core.state.set?.({ claimItemQuantityContext: null });
        G7Core.state.setLocal?.({ selectedClaimItems: [], itemBulkStatus: '', itemRejectReason: '', itemCarrierId: '', itemTrackingNumber: '', itemTransitionOptions: [] });
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[executeClaimItemBulkStatus] 상품 단위 전이 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.items.bulk_error', '신청 상품 상태 변경에 실패했습니다.'),
        );
        throw error;
    } finally {
        G7Core.state.setLocal?.({ isUpdatingClaimItems: false, claimItemsUpdateKind: null });
    }
}

/**
 * 반려인데 사유가 비었으면 안내하고 true 를 돌려줍니다. (신청서 칸·상품 칸·목록 일괄 공용)
 *
 * 반려는 사유가 정본이다 — 비워 보내면 구매자 화면의 반려 사유가 빈 칸으로 남는다. 서버도
 * 같은 조건을 422 로 막지만, 수량 확인 모달까지 연 뒤에야 거절되지 않게 입구에서 먼저 세운다.
 *
 * @param status 목표 상태
 * @param rejectReason 입력된 반려 사유
 * @returns 사유가 필요한데 비었으면 true
 */
function rejectReasonMissing(status: string, rejectReason?: string | null): boolean {
    if (status !== 'rejected' || (rejectReason ?? '').trim() !== '') {
        return false;
    }

    core()?.toast?.warning?.(
        t('sirsoft-ecommerce.admin.claim.detail.transition.reject_reason_required', '반려 사유를 입력해주세요.'),
    );

    return true;
}

/**
 * 신청서 **단위** 상태를 전이시킵니다. (상세 화면 전이 바 — #599 후속 F1)
 *
 * 예전에는 상세에 신청서 단위 전이 진입점이 통째로 없었다. 승인·반려·보류·결제대기는 **목록의
 * 일괄 바**에서만 가능했고, 환불 유형은 상품 단위 축조차 없어 상세에서 할 수 있는 전이가
 * 하나도 없었다 — 운영자는 신청서를 열어 내용을 확인한 뒤 목록으로 되돌아가 그 줄을 찾아
 * 체크해야 했다. F2([환불하기]가 상태를 보지 않던 결함)와 겹쳐 "상세에서 승인할 수 없는데
 * 승인 상태에서만 눌리는 버튼" 이라는 막다른 길이 됐다.
 *
 * 선택지는 서버가 준 **심사 어휘**(결제 요청·승인·보류·반려)뿐이다 — 회수·발송 단계는 상품 칸,
 * 완료는 [완료 처리](`openClaimDetailComplete`)가 입구다. 예전에는 완료가 이 칸에 서서 고르면
 * "완료 모달에서 진행해주세요" 안내만 뜨고 아무 일도 일어나지 않았다(#599 후속).
 *
 * @param action 액션 객체 (params: pathSegment / claimId / status / rejectReason)
 * @param _context 액션 컨텍스트
 */
export async function executeClaimStatusTransitionHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimType?: string;
        claimId?: number | string | null;
        status?: string;
        rejectReason?: string | null;
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const status = params.status ?? '';
    const base = claimScopedBase(params);

    if (base === null) {
        logger.warn('[executeClaimStatusTransition] 신청서 식별 축이 없습니다.');

        return;
    }

    if (!status) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.detail.transition.no_status_selected', '변경할 상태를 선택해주세요.'),
        );

        return;
    }

    if (rejectReasonMissing(status, params.rejectReason)) {
        return;
    }

    if (G7Core.state.getLocal?.()?.isTransitioningClaim === true) return;
    G7Core.state.setLocal?.({ isTransitioningClaim: true });

    try {
        const body: Record<string, unknown> = { status };

        if (status === 'rejected') {
            body.reject_reason = (params.rejectReason ?? '').trim();
        }

        const response = await G7Core.api.patch(`${base}/status`, body);

        if (response?.success === false) {
            throw new Error(response?.message ?? 'claim status transition failed');
        }

        G7Core.toast?.success?.(
            response?.message
            ?? t('sirsoft-ecommerce.admin.claim.detail.transition.success', '신청서 상태를 변경했습니다.'),
        );

        G7Core.state.setLocal?.({ claimBulkStatus: '', claimRejectReason: '' });
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[executeClaimStatusTransition] 신청서 전이 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.transition.error', '신청서 상태 변경에 실패했습니다.'),
        );
        throw error;
    } finally {
        G7Core.state.setLocal?.({ isTransitioningClaim: false });
    }
}

/**
 * 완료된 교환·반품에 환불신청서를 **수동으로** 접수합니다. (정산 카드 — #599 후속 F5)
 *
 * 라우트·컨트롤러·서비스는 처음부터 완비돼 있었고 상세는 접수 **불가 사유**까지 보여 주는데,
 * 정작 접수 버튼만 없었다 — 완료 모달에서 "환불신청서 생성" 을 끈 건은 그 뒤로 환불을 접수할
 * 경로가 화면 어디에도 없었다.
 *
 * @param action 액션 객체 (params: pathSegment / claimId)
 * @param _context 액션 컨텍스트
 */
export async function storeClaimRefundRequestHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimType?: string;
        claimId?: number | string | null;
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const base = claimScopedBase(action.params ?? {});

    if (base === null) {
        logger.warn('[storeClaimRefundRequest] 신청서 식별 축이 없습니다.');

        return;
    }

    if (G7Core.state.getLocal?.()?.isFilingRefundRequest === true) return;
    G7Core.state.setLocal?.({ isFilingRefundRequest: true });

    try {
        const response = await G7Core.api.post(`${base}/refund-request`, {});

        if (response?.success === false) {
            throw new Error(response?.message ?? 'refund request failed');
        }

        G7Core.toast?.success?.(
            response?.message
            ?? t('sirsoft-ecommerce.admin.claim.detail.settlement.file_refund_success', '환불신청서를 접수했습니다.'),
        );

        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[storeClaimRefundRequest] 환불신청서 접수 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.settlement.file_refund_error', '환불신청서 접수에 실패했습니다.'),
        );
        throw error;
    } finally {
        G7Core.state.setLocal?.({ isFilingRefundRequest: false });
    }
}

/**
 * 첨부가 늘거나 줄어든 뒤 상세를 다시 읽습니다. (사유 카드 — #599 후속)
 *
 * **업로드·삭제는 공용 업로더 컴포넌트가 한다.** 이 핸들러가 하는 일은 그 결과를 화면의
 * 나머지(첨부 출처 스트립·활동 로그)에 반영하는 것뿐이다 — 업로드와 삭제가 같은 일을
 * 하므로 두 콜백이 이 하나를 쓴다.
 *
 * 종전에는 핸들러가 직접 `FormData` 를 만들어 올렸다. 그러면 진행률·다중 선택·상한 안내·
 * 개별 삭제를 이 핸들러가 다시 구현해야 하고, 그 구현은 구매자 화면과 갈린다.
 *
 * 결과를 **말로도 알린다.** 업로더는 자기 격자만 갱신하므로, 안내가 없으면 운영자는
 * 출처 스트립과 활동 로그가 왜 바뀌었는지(또는 바뀌긴 했는지) 알 수 없다. 올린 것과 지운
 * 것은 다른 사건이라 문구도 나눈다.
 *
 * @param action 액션 객체 (params.event: `upload` | `remove`)
 * @param _context 액션 컨텍스트
 */
export async function refreshClaimAttachmentsHandler(
    action?: ActionWithParams<{ event?: string }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();

    refetchSources(DETAIL_REFETCH_SOURCES);

    if (!G7Core) return;

    const removed = (action?.params?.event ?? '') === 'remove';

    G7Core.toast?.success?.(
        removed
            ? t('sirsoft-ecommerce.admin.claim.detail.reason.image_delete_success', '첨부를 삭제했습니다.')
            : t('sirsoft-ecommerce.admin.claim.detail.reason.image_upload_success', '첨부를 등록했습니다.'),
    );
}

/**
 * 신청서의 첨부를 **일괄 정리**합니다. (사유 카드 — #599 후속 F6)
 *
 * 잘못 올라온 증빙(개인정보가 찍힌 사진 등)을 운영자가 지울 수 있어야 한다 — 라우트는
 * 있었지만 화면에는 삭제 진입점이 없었다.
 *
 * @param action 액션 객체 (params: pathSegment / claimId)
 * @param _context 액션 컨텍스트
 */
export async function clearClaimImagesHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimType?: string;
        claimId?: number | string | null;
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const base = claimScopedBase(action.params ?? {});

    if (base === null) {
        logger.warn('[clearClaimImages] 신청서 식별 축이 없습니다.');

        return;
    }

    if (G7Core.state.getLocal?.()?.isClearingClaimImages === true) return;
    G7Core.state.setLocal?.({ isClearingClaimImages: true });

    try {
        const response = await G7Core.api.delete(`${base}/images`);

        if (response?.success === false) {
            throw new Error(response?.message ?? 'claim image clear failed');
        }

        G7Core.toast?.success?.(
            response?.message
            ?? t('sirsoft-ecommerce.admin.claim.detail.reason.image_clear_success', '첨부를 정리했습니다.'),
        );

        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[clearClaimImages] 첨부 정리 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.reason.image_clear_error', '첨부 정리에 실패했습니다.'),
        );
        throw error;
    } finally {
        G7Core.state.setLocal?.({ isClearingClaimImages: false });
    }
}

/**
 * 한 방향의 송장 칸 이름을 돌려줍니다. (`collection` → `collection_*`, `redelivery` → `redelivery_*`)
 *
 * @param status 목표 상태 (회수중·발송중에서 방향을 읽는다)
 * @returns 방향 값 (회수중·기타 = collection, 발송중 = redelivery)
 */
function directionForStatus(status: string): 'collection' | 'redelivery' {
    return status === 'redelivering' ? 'redelivery' : 'collection';
}

/**
 * 고른 행들이 **모두 같은 송장**을 가질 때만 그 값을 돌려줍니다. (P1 「미리 채움」)
 *
 * 송장은 상품 행마다 있다 — 행마다 다르면 미리 채울 값이 없어 비워 두고 새로 적게 한다.
 *
 * @param rows 상세 상품 행
 * @param selectedIds 고른 행 ID
 * @param direction 방향
 * @returns 공통 송장 (없으면 빈 값)
 */
function commonRowTracking(
    rows: ClaimDetailItemRow[],
    selectedIds: number[],
    direction: 'collection' | 'redelivery',
): { carrierId: string; trackingNumber: string } {
    const selected = rows.filter((row) => selectedIds.includes(Number(row.id)));
    const pairs = new Set(selected.map((row) => {
        const carrier = (row as any)[`${direction}_carrier_id`];
        const number = (row as any)[`${direction}_tracking_number`];

        return `${carrier ?? ''}|${number ?? ''}`;
    }));

    if (pairs.size !== 1) {
        return { carrierId: '', trackingNumber: '' };
    }

    const [carrierId, trackingNumber] = [...pairs][0].split('|');

    return trackingNumber ? { carrierId, trackingNumber } : { carrierId: '', trackingNumber: '' };
}

/**
 * 상품 칸 일괄 바의 상태를 고릅니다. (회수중·발송중이면 고른 행들의 공통 송장을 미리 채운다)
 *
 * 레이아웃 인라인 식으로 미리 채우지 않는 이유: 송장은 상품 행마다 있어 「고른 행이 모두 같은
 * 송장인가」 를 판정해야 하고, 그 판정이 식 안에 들어가면 구조 테스트가 막는 다중 바인딩이 된다.
 *
 * @param action 액션 객체 (params: status / rows)
 * @param _context 액션 컨텍스트
 */
export function selectClaimItemBulkStatusHandler(
    action: ActionWithParams<{ status?: string; rows?: ClaimDetailItemRow[] }>,
    _context?: ActionContext,
): void {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const status = params.status ?? '';
    const selectedIds: number[] = (G7Core.state.getLocal?.()?.selectedClaimItems ?? []).map((id: unknown) => Number(id));
    const tracking = TRACKING_REQUIRED_STATUSES.includes(status)
        ? commonRowTracking(params.rows ?? [], selectedIds, directionForStatus(status))
        : { carrierId: '', trackingNumber: '' };

    G7Core.state.setLocal?.({
        itemBulkStatus: status,
        // 상태를 고르면 「송장만 저장」 칸은 닫는다 — 두 입력이 같은 칸을 공유하므로 모드가 섞이면 무엇이 저장될지 모른다.
        itemTrackingMode: false,
        itemCarrierId: tracking.carrierId,
        itemTrackingNumber: tracking.trackingNumber,
    });
}

/**
 * 「송장만 저장」 의 방향을 고릅니다. (그 방향의 공통 송장을 다시 미리 채운다)
 *
 * @param action 액션 객체 (params: direction / rows)
 * @param _context 액션 컨텍스트
 */
export function selectClaimItemTrackingDirectionHandler(
    action: ActionWithParams<{ direction?: string; rows?: ClaimDetailItemRow[] }>,
    _context?: ActionContext,
): void {
    const G7Core = core();
    if (!G7Core) return;

    const direction = action.params?.direction === 'redelivery' ? 'redelivery' : 'collection';
    const selectedIds: number[] = (G7Core.state.getLocal?.()?.selectedClaimItems ?? []).map((id: unknown) => Number(id));
    const tracking = commonRowTracking(action.params?.rows ?? [], selectedIds, direction);

    G7Core.state.setLocal?.({
        itemTrackingDirection: direction,
        itemCarrierId: tracking.carrierId,
        itemTrackingNumber: tracking.trackingNumber,
    });
}

/**
 * 「송장만 저장」 을 누릅니다. (R7 — 상태 불변)
 *
 * 처음 누르면 방향·택배사·운송장 칸을 열고 고른 행의 공통 송장을 미리 채운다. 칸이 열린 뒤 누르면
 * 입력을 확인하고 수량 확인 모달로 간다 — 수량을 줄이면 그 행이 쪼개져 지정 수량에만 송장이 붙는다(R4).
 *
 * 요청 전에 막는 것: 택배사·운송장 반쪽(R2), 회수지가 다른 행을 한 회수 운송장으로 묶기(R8).
 * 서버도 같은 조건을 422 로 거절하지만, 모달까지 연 뒤에야 거절되지 않게 입구에서 먼저 세운다.
 *
 * @param action 액션 객체 (params)
 * @param _context 액션 컨텍스트
 */
export async function openClaimItemTrackingOnlyHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimType?: string;
        claimId?: number | string | null;
        itemIds?: Array<number | string>;
        trackingMode?: boolean;
        direction?: string;
        carrierId?: string | number | null;
        trackingNumber?: string | null;
        carriers?: CarrierOption[];
        rows?: ClaimDetailItemRow[];
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const itemIds = (params.itemIds ?? []).map((id) => Number(id));
    const rows = params.rows ?? [];
    const direction = params.direction === 'redelivery' ? 'redelivery' : 'collection';

    if (itemIds.length === 0) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.detail.items.bulk_no_selection', '변경할 상품을 선택해주세요.'),
        );

        return;
    }

    if (params.trackingMode !== true) {
        const tracking = commonRowTracking(rows, itemIds, direction);

        G7Core.state.setLocal?.({
            itemTrackingMode: true,
            itemBulkStatus: '',
            itemTrackingDirection: direction,
            itemCarrierId: tracking.carrierId,
            itemTrackingNumber: tracking.trackingNumber,
        });

        return;
    }

    const carrier = String(params.carrierId ?? '');
    const trackingNumber = (params.trackingNumber ?? '').trim();

    if ((carrier === '') !== (trackingNumber === '')) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.detail.shipping.tracking_incomplete', '택배사와 송장번호를 함께 입력해주세요.'),
        );

        return;
    }

    if (direction === 'collection' && trackingNumber !== '') {
        const addresses = new Set(rows
            .filter((row) => itemIds.includes(Number(row.id)))
            .map((row) => JSON.stringify((row as any).return_address_snapshot ?? null)));

        if (addresses.size > 1) {
            G7Core.toast?.warning?.(
                t('sirsoft-ecommerce.admin.claim.detail.items.tracking_mixed_address', '회수지가 다른 상품은 한 운송장으로 묶을 수 없습니다. 회수지별로 나눠 저장해 주세요.'),
            );

            return;
        }
    }

    const selected = rows.filter((row) => itemIds.includes(Number(row.id)));
    const modalRows: ClaimItemQuantityRow[] = selected.map((row) => ({
        id: Number(row.id),
        label: [row.product_name ?? '', row.option_name ?? ''].filter(Boolean).join(' / '),
        quantity: Number(row.quantity ?? 1),
        splittable: (row as any).is_splittable === true && Number(row.quantity ?? 1) > 1,
    }));
    const quantities: Record<string, number> = {};
    modalRows.forEach((row) => {
        quantities[String(row.id)] = row.quantity;
    });

    const modalContext: ClaimItemQuantityContext = {
        pathSegment: params.pathSegment ?? '',
        claimType: params.claimType ?? '',
        claimId: Number(params.claimId),
        claimNumber: '',
        requesterName: '',
        status: '',
        statusLabel: '',
        rejectReason: '',
        carrierId: carrier,
        trackingNumber,
        carrierLabel: (params.carriers ?? []).find((option) => String(option.value) === carrier)?.label ?? '',
        itemIds,
        rows: modalRows,
        quantities,
        completeDefaults: {},
        detailRows: rows,
        trackingOnly: true,
        direction,
        directionLabel: direction === 'redelivery'
            ? t('sirsoft-ecommerce.admin.claim.detail.shipping.redelivery_title', '재발송')
            : t('sirsoft-ecommerce.admin.claim.detail.shipping.collection_title', '회수'),
    };

    // 문맥을 통째로 바꾼다 — 상태 전이 창과 같은 문맥이라 앞 창의 키가 남으면 안 된다(사례 43).
    G7Core.state.set({ claimItemQuantityContext: modalContext }, { merge: 'shallow' });
    G7Core.modal?.open?.('modal_claim_item_quantity_confirm');
}

/**
 * 「송장만 저장」 을 실행합니다. (수량 확인 모달 [송장만 저장] — `PATCH …/options/tracking`)
 *
 * @param base 유형 스코프 기본 경로
 * @param params 실행 파라미터
 */
async function saveClaimItemTracking(
    base: string,
    params: {
        itemIds: number[];
        itemQuantities?: Record<string, number | string | null>;
        direction?: string | null;
        carrierId?: string | number | null;
        trackingNumber?: string | null;
    },
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    if (G7Core.state.getLocal?.()?.isUpdatingClaimItems === true) return;
    G7Core.state.setLocal?.({ isUpdatingClaimItems: true, claimItemsUpdateKind: 'tracking' });

    try {
        const carrier = String(params.carrierId ?? '');
        const trackingNumber = (params.trackingNumber ?? '').trim();
        const response = await G7Core.api.patch(`${base}/options/tracking`, {
            direction: params.direction === 'redelivery' ? 'redelivery' : 'collection',
            carrier_id: carrier === '' ? null : Number(carrier),
            tracking_number: trackingNumber === '' ? null : trackingNumber,
            items: claimItemPayload(params.itemIds, params.itemQuantities),
        });

        if (response?.success === false) {
            throw new Error(response?.message ?? 'item tracking save failed');
        }

        G7Core.toast?.success?.(
            response?.message
            ?? t('sirsoft-ecommerce.admin.claim.detail.shipping.tracking_saved', '운송장 정보를 저장했습니다.'),
        );
        reportClaimItemSplits(response?.data ?? {});

        G7Core.modal?.close?.('modal_claim_item_quantity_confirm');
        G7Core.state.set?.({ claimItemQuantityContext: null });
        G7Core.state.setLocal?.({ selectedClaimItems: [], itemBulkStatus: '', itemCarrierId: '', itemTrackingNumber: '', itemTrackingMode: false, itemTransitionOptions: [] });
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[saveClaimItemTracking] 송장 저장 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.shipping.tracking_error', '운송장 정보를 저장하지 못했습니다.'),
        );
        throw error;
    } finally {
        G7Core.state.setLocal?.({ isUpdatingClaimItems: false, claimItemsUpdateKind: null });
    }
}

/**
 * 운송장 한 개에 묶인 행을 한꺼번에 도착 처리합니다. (배송 정보 탭 [도착 처리] — R9)
 *
 * @param action 액션 객체 (params: pathSegment / claimId / direction / carrierId / trackingNumber)
 * @param _context 액션 컨텍스트
 */
export async function deliverClaimTrackingHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimId?: number | string | null;
        direction?: string;
        carrierId?: number | string | null;
        trackingNumber?: string | null;
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const base = claimScopedBase(params);

    if (base === null) {
        logger.warn('[deliverClaimTracking] 신청서 식별 축이 없습니다.');

        return;
    }

    if (G7Core.state.getLocal?.()?.isDeliveringTracking === true) return;

    const direction = params.direction === 'redelivery' ? 'redelivery' : 'collection';
    const carrierId = Number(params.carrierId);
    const trackingNumber = (params.trackingNumber ?? '').trim();

    // 잠금은 모든 묶음 버튼이 함께 쓰고(중복 요청 방지), 스피너는 누른 묶음에만 돈다 — 화면은 이 키와 묶음의 (방향, 택배사, 운송장)을 맞춰 본다.
    G7Core.state.setLocal?.({ isDeliveringTracking: true, deliveringTrackingKey: `${direction}|${carrierId}|${trackingNumber}` });

    try {
        const response = await G7Core.api.post(`${base}/tracking/deliver`, {
            direction,
            carrier_id: carrierId,
            tracking_number: trackingNumber,
        });

        if (response?.success === false) {
            throw new Error(response?.message ?? 'tracking deliver failed');
        }

        const data = response?.data ?? {};

        G7Core.toast?.success?.(
            t('sirsoft-ecommerce.admin.claim.detail.shipping.deliver_success', '도착 처리했습니다.', {
                succeeded: (data.succeeded ?? []).length,
                skipped: (data.skipped ?? []).length,
            }),
        );
        reportBulkSkips(data);
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[deliverClaimTracking] 도착 처리 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.shipping.deliver_error', '도착 처리에 실패했습니다.'),
        );
        throw error;
    } finally {
        G7Core.state.setLocal?.({ isDeliveringTracking: false, deliveringTrackingKey: null });
    }
}

/**
 * 진행 방식 전환 확인 모달을 엽니다. (D1)
 *
 * @param action 액션 객체 (params: claimId / mode / dueDays)
 * @param _context 액션 컨텍스트
 */
export function openClaimExchangeModeConfirmHandler(
    action: ActionWithParams<{ claimId?: number | string | null; mode?: string; dueDays?: number | string | null }>,
    _context?: ActionContext,
): void {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};

    G7Core.state.set({
        claimExchangeModeContext: {
            claimId: Number(params.claimId),
            mode: params.mode === 'advance' ? 'advance' : 'standard',
            dueDays: params.dueDays ?? '',
        },
    });
    G7Core.modal?.open?.('modal_claim_exchange_mode_confirm');
}

/**
 * 교환의 진행 방식을 바꿉니다. (`PUT admin/exchanges/{id}/exchange-mode`)
 *
 * @param action 액션 객체 (params: claimId / mode)
 * @param _context 액션 컨텍스트
 */
export async function setClaimExchangeModeHandler(
    action: ActionWithParams<{ claimId?: number | string | null; mode?: string }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const claimId = Number(params.claimId);

    if (!Number.isFinite(claimId) || claimId <= 0) {
        logger.warn('[setClaimExchangeMode] 신청서 식별 축이 없습니다.');

        return;
    }

    if (G7Core.state.get?.()?.isSettingExchangeMode === true) return;
    G7Core.state.set?.({ isSettingExchangeMode: true });

    try {
        const response = await G7Core.api.put(`/api/modules/sirsoft-ecommerce/admin/exchanges/${claimId}/exchange-mode`, {
            mode: params.mode === 'advance' ? 'advance' : 'standard',
        });

        if (response?.success === false) {
            throw new Error(response?.message ?? 'exchange mode failed');
        }

        G7Core.toast?.success?.(
            response?.message ?? t('sirsoft-ecommerce.admin.claim.detail.exchange_mode.updated', '진행 방식을 바꿨습니다.'),
        );
        G7Core.modal?.close?.('modal_claim_exchange_mode_confirm');
        G7Core.state.set?.({ claimExchangeModeContext: null });
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[setClaimExchangeMode] 진행 방식 변경 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.exchange_mode.error', '진행 방식을 바꾸지 못했습니다.'),
        );
        throw error;
    } finally {
        G7Core.state.set?.({ isSettingExchangeMode: false });
    }
}

/**
 * 고객 요청 대리 철회 모달을 엽니다. (S14)
 *
 * @param action 액션 객체 (params: pathSegment / claimId)
 * @param _context 액션 컨텍스트
 */
export function openClaimWithdrawOnBehalfHandler(
    action: ActionWithParams<{ pathSegment?: string; claimId?: number | string | null }>,
    _context?: ActionContext,
): void {
    const G7Core = core();
    if (!G7Core) return;

    G7Core.state.set({
        claimWithdrawContext: {
            pathSegment: action.params?.pathSegment ?? '',
            claimId: Number(action.params?.claimId),
        },
        claimWithdrawReason: '',
    });
    G7Core.modal?.open?.('modal_claim_withdraw_on_behalf');
}

/**
 * 고객 요청으로 신청을 대신 철회합니다. (S14 — `POST …/withdraw`)
 *
 * @param action 액션 객체 (params: pathSegment / claimId / reason)
 * @param _context 액션 컨텍스트
 */
export async function withdrawClaimOnBehalfHandler(
    action: ActionWithParams<{ pathSegment?: string; claimId?: number | string | null; reason?: string | null }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const base = claimScopedBase(params);
    const reason = (params.reason ?? '').trim();

    if (base === null) {
        logger.warn('[withdrawClaimOnBehalf] 신청서 식별 축이 없습니다.');

        return;
    }

    if (reason === '') {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.detail.withdraw_on_behalf.reason_required', '철회 사유를 입력해주세요.'),
        );

        return;
    }

    if (G7Core.state.get?.()?.isWithdrawingClaim === true) return;
    G7Core.state.set?.({ isWithdrawingClaim: true });

    try {
        const response = await G7Core.api.post(`${base}/withdraw`, { reason });

        if (response?.success === false) {
            throw new Error(response?.message ?? 'withdraw failed');
        }

        G7Core.toast?.success?.(
            response?.message ?? t('sirsoft-ecommerce.admin.claim.detail.withdraw_on_behalf.success', '고객 요청으로 신청을 철회했습니다.'),
        );
        G7Core.modal?.close?.('modal_claim_withdraw_on_behalf');
        G7Core.state.set?.({ claimWithdrawContext: null, claimWithdrawReason: '' });
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[withdrawClaimOnBehalf] 대리 철회 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.withdraw_on_behalf.error', '신청을 철회하지 못했습니다.'),
        );
        throw error;
    } finally {
        G7Core.state.set?.({ isWithdrawingClaim: false });
    }
}

/**
 * 신청 내용 수정 모달을 엽니다. (S15)
 *
 * @param action 액션 객체 (params: pathSegment / claimId / reasonCode / reasonDetail / reasonOptions)
 * @param _context 액션 컨텍스트
 */
export function openClaimRequestEditHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimId?: number | string | null;
        reasonCode?: string | null;
        reasonDetail?: string | null;
        reasonOptions?: Array<{ value: string; label: string }>;
    }>,
    _context?: ActionContext,
): void {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};

    G7Core.state.set({
        claimRequestEditContext: {
            pathSegment: params.pathSegment ?? '',
            claimId: Number(params.claimId),
            reasonOptions: params.reasonOptions ?? [],
        },
        claimRequestEditReasonCode: params.reasonCode ?? '',
        claimRequestEditReasonDetail: params.reasonDetail ?? '',
    });
    G7Core.modal?.open?.('modal_claim_request_edit');
}

/**
 * 신청 내용을 운영자가 고칩니다. (S15 — `PATCH admin/{세그먼트}/{id}`)
 *
 * @param action 액션 객체 (params: pathSegment / claimId / reasonCode / reasonDetail)
 * @param _context 액션 컨텍스트
 */
export async function updateClaimRequestOnBehalfHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimId?: number | string | null;
        reasonCode?: string | null;
        reasonDetail?: string | null;
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const base = claimScopedBase(params);

    if (base === null) {
        logger.warn('[updateClaimRequestOnBehalf] 신청서 식별 축이 없습니다.');

        return;
    }

    if (G7Core.state.get?.()?.isEditingClaimRequest === true) return;
    G7Core.state.set?.({ isEditingClaimRequest: true });

    try {
        const response = await G7Core.api.patch(base, {
            reason_code: params.reasonCode ?? null,
            reason_detail: params.reasonDetail ?? null,
        });

        if (response?.success === false) {
            throw new Error(response?.message ?? 'request edit failed');
        }

        G7Core.toast?.success?.(
            response?.message ?? t('sirsoft-ecommerce.admin.claim.detail.request_edit.success', '신청 내용을 수정했습니다.'),
        );
        G7Core.modal?.close?.('modal_claim_request_edit');
        G7Core.state.set?.({ claimRequestEditContext: null });
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[updateClaimRequestOnBehalf] 신청 내용 수정 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.request_edit.error', '신청 내용을 수정하지 못했습니다.'),
        );
        throw error;
    } finally {
        G7Core.state.set?.({ isEditingClaimRequest: false });
    }
}

/**
 * 완료됐지만 재고를 복구하지 않은 행의 재고를 복구합니다. (S13 — `POST …/items/restore-stock`)
 *
 * @param action 액션 객체 (params: pathSegment / claimId / itemIds)
 * @param _context 액션 컨텍스트
 */
export async function restoreClaimStockHandler(
    action: ActionWithParams<{ pathSegment?: string; claimId?: number | string | null; itemIds?: Array<number | string> }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const base = claimScopedBase(params);

    if (base === null) {
        logger.warn('[restoreClaimStock] 신청서 식별 축이 없습니다.');

        return;
    }

    if (G7Core.state.getLocal?.()?.isRestoringStock === true) return;
    G7Core.state.setLocal?.({ isRestoringStock: true });

    try {
        const response = await G7Core.api.post(`${base}/items/restore-stock`, {
            items: (params.itemIds ?? []).map((id) => Number(id)),
        });

        if (response?.success === false) {
            throw new Error(response?.message ?? 'restore stock failed');
        }

        G7Core.toast?.success?.(
            response?.message ?? t('sirsoft-ecommerce.admin.claim.detail.completion.restore_stock_success', '재고를 복구했습니다.'),
        );
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[restoreClaimStock] 재고 복구 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.completion.restore_stock_error', '재고를 복구하지 못했습니다.'),
        );
        throw error;
    } finally {
        G7Core.state.setLocal?.({ isRestoringStock: false });
    }
}

/**
 * 관리자 메모를 저장합니다. (상세 화면 신청 정보 섹션)
 *
 * 메모는 금액 수정 엔드포인트(D18)가 함께 받는 축이라 게이트도 `claims.update-amounts` 다.
 * 환불신청서에는 저장 컬럼이 없어 서버가 그 필드를 거절하므로 화면도 입력을 그리지 않는다.
 *
 * @param action 액션 객체 (params)
 * @param _context 액션 컨텍스트
 */
export async function saveClaimAdminMemoHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimId?: number | string | null;
        adminMemo?: string | null;
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const base = claimScopedBase(params);

    if (base === null) {
        logger.warn('[saveClaimAdminMemo] 신청서 식별 축이 없습니다.');

        return;
    }

    if (G7Core.state.getLocal?.()?.isSavingMemo === true) return;
    G7Core.state.setLocal?.({ isSavingMemo: true });

    try {
        const response = await G7Core.api.put(`${base}/amounts`, {
            admin_memo: params.adminMemo ?? '',
        });

        if (response?.success === false) {
            throw new Error(response?.message ?? 'admin memo save failed');
        }

        G7Core.toast?.success?.(
            t('sirsoft-ecommerce.admin.claim.detail.memo.saved', '관리자 메모를 저장했습니다.'),
        );
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[saveClaimAdminMemo] 관리자 메모 저장 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.memo.error', '관리자 메모를 저장하지 못했습니다.'),
        );
        throw error;
    } finally {
        G7Core.state.setLocal?.({ isSavingMemo: false });
    }
}

/** 환불 실행 엔드포인트 — 유형 스코프가 아니라 환불신청서 ID 축이다 */
const REFUND_EXECUTE_URL = '/api/modules/sirsoft-ecommerce/admin/refunds';

/** 환불 실행 확인 모달이 읽는 문맥 (모달은 부모 화면의 `_local` 을 해석하지 못한다) */
interface ClaimRefundExecuteContext {
    /** 환불신청서 ID */
    claimId: number;
    /** 클레임 번호 (모달 안내 문구) */
    claimNumber: string;
    /** 표기 통화 코드 (서버가 준 값 — 화면이 기호를 만들지 않는다) */
    currencyCode: string;
    /** PG 환불액 */
    refundAmount: number;
    /** 마일리지 환불액 */
    refundPointsAmount: number;
    /** 최종 환불 합계 — 현금 + 마일리지 + 교환 차액 환급, 부동소수 오차를 걷어 낸 값(모달 표시 전용) */
    refundTotal: number;
    /**
     * 위 세 금액의 표기(`32,500원`·`$12.50`) — 창은 이 문자열을 그린다. 숫자에 통화 코드를 이어 붙이면
     * 자릿수 구분 없는 `32500 KRW` 가 되어, 같은 신청서 상세의 금액과 꼴이 달라진다(S3-D1).
     */
    refundAmountFormatted: string;
    refundPointsAmountFormatted: string;
    refundTotalFormatted: string;
    /** 교환 차액 환급 합계 (교환으로 받은 상품의 환불일 때만, 없으면 0) — 실행 본문에는 싣지 않는다(서버 저장값) */
    exchangeDifferenceTotal: number;
    /** 교환 차액 환급 표기 — 서버가 만든 「4,000원 (마일리지)」 */
    exchangeDifferenceFormatted: string;
    /** 환불 수단 값 */
    refundMethod: string;
    /** 환불 수단 라벨 (서버가 준 선택지에서 해석) */
    refundMethodLabel: string;
    /**
     * 이 환불을 실행하려면 **보낼 계좌가 있어야 하는가** (T2-D6).
     *
     * 서버가 실행 게이트와 같은 술어로 **환불 수단마다** 판정해 내려 준다(`refund_account_by_method`) —
     * 지금 고른 수단의 값을 쓴다. 화면이 결제수단 코드로 재현하면 확장 결제수단에서 갈리고, 어긋난 쪽은
     * 오류 없이 「계좌 칸이 없는 계좌 환불」 또는 「PG 환불인데 계좌를 요구」가 된다.
     */
    refundAccountRequired: boolean;
    /** 지금 고른 수단으로 실행하면 돈이 고객 계좌로 나가는가 — 저장 계좌 줄의 표시 조건 */
    refundAccountUsed: boolean;
    /** 사용 마일리지를 차감하지 않은 주문이라 반환액이 0 인가(Q15) — 창이 그 이유를 보인다 */
    pointsNotDeducted: boolean;
    /** 환불 계좌 은행명 (계좌 이체 환불일 때만) */
    refundBankName: string;
    /** 환불 계좌번호 (열람 권한이 없으면 마스킹된 값) */
    refundBankAccount: string;
    /** 환불 계좌 예금주 (열람 권한이 없으면 마스킹된 값) */
    refundBankHolder: string;
}

/**
 * 입력 문자열을 금액으로 해석합니다.
 *
 * 빈 문자열은 "보내지 않음" 이고 `0` 은 "0원으로 고침" 이다 — 둘을 뭉뚱그리면 운영자가
 * 비워 둔 칸이 0 저장으로 나가 저장된 금액이 조용히 지워진다.
 *
 * @param value 입력 값
 * @returns 숫자 (해석 불가·빈 값이면 null)
 */
function amountOrNull(value: unknown): number | null {
    if (value === undefined || value === null || value === '') {
        return null;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * 신청서 금액을 저장합니다. (D18 — `PUT {유형}/{id}/amounts`)
 *
 * 유형마다 받는 축이 다르고 다른 유형의 축을 보내면 서버가 422 로 명시 거절한다 — 화면도
 * 그 유형의 축만 싣는다. 보내지 않은 키는 갱신되지 않으므로 **비어 있는 칸은 빼고** 보낸다:
 * 빈 칸을 0 으로 바꿔 보내면 운영자가 손대지 않은 금액이 지워진다.
 *
 * 청구가 0 이던 신청서의 배송비를 올릴 때는 청구 방식이 함께 있어야 한다 — 없으면 서버가
 * `claim_charge_method_required` 로 거절하며, 그 사유는 토스트로 그대로 전달된다.
 *
 * @param action 액션 객체 (params)
 * @param _context 액션 컨텍스트
 */
export async function saveClaimAmountsHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimId?: number | string | null;
        claimType?: string;
        claimShippingFee?: string | number | null;
        chargeMethod?: string | null;
        refundAmount?: string | number | null;
        refundPointsAmount?: string | number | null;
        refundMethod?: string | null;
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const base = claimScopedBase(params);

    if (base === null) {
        logger.warn('[saveClaimAmounts] 신청서 식별 축이 없습니다.');

        return;
    }

    const body: Record<string, unknown> = {};

    if (params.claimType === 'refund') {
        const refundAmount = amountOrNull(params.refundAmount);
        const pointsAmount = amountOrNull(params.refundPointsAmount);
        const method = (params.refundMethod ?? '').trim();

        if (refundAmount !== null) body.refund_amount = refundAmount;
        if (pointsAmount !== null) body.refund_points_amount = pointsAmount;
        if (method !== '') body.refund_method = method;
    } else {
        const shippingFee = amountOrNull(params.claimShippingFee);
        const chargeMethod = (params.chargeMethod ?? '').trim();

        if (shippingFee !== null) body.claim_shipping_fee = shippingFee;
        if (chargeMethod !== '') body.charge_method = chargeMethod;
    }

    if (Object.keys(body).length === 0) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.detail.settlement.nothing_to_save', '저장할 금액이 없습니다.'),
        );

        return;
    }

    if (G7Core.state.getLocal?.()?.isSavingAmounts === true) return;
    G7Core.state.setLocal?.({ isSavingAmounts: true });

    try {
        const response = await G7Core.api.put(`${base}/amounts`, body);

        if (response?.success === false) {
            throw new Error(response?.message ?? 'claim amounts save failed');
        }

        G7Core.toast?.success?.(
            t('sirsoft-ecommerce.admin.claim.detail.settlement.saved', '금액을 저장했습니다.'),
        );
        // 금액 수정은 총 청구액·환불 예정액·취소 가능 금액을 함께 바꾸고 활동 로그도 남긴다 —
        // 상세만 다시 읽으면 활동 로그 섹션이 옛 목록으로 남는다 (S9a 가 잡은 결함과 같은 축).
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[saveClaimAmounts] 금액 저장 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.settlement.error', '금액을 저장하지 못했습니다.'),
        );
        throw error;
    } finally {
        // 실패해도 버튼은 되살린다 — 그러지 않으면 한 번 실패한 뒤로 저장 자체를 못 한다.
        G7Core.state.setLocal?.({ isSavingAmounts: false });
    }
}

/**
 * 두 금액을 더하되 부동소수 오차를 걷어 냅니다.
 *
 * `0.1 + 0.2` 는 `0.30000000000000004` 다 — 화면이 그대로 찍으면 소수 통화에서 합계가
 * 이상한 자릿수로 보인다. 소수 8자리에서 반올림한다: 어느 통화의 소수 자릿수(최대 8)보다
 * 깊은 자리라 실제 값은 바뀌지 않고 오차만 사라진다.
 *
 * @param a 첫째 금액
 * @param b 둘째 금액
 * @returns 반올림한 합
 */
function sumAmounts(a: number, b: number): number {
    return Math.round((a + b) * 100000000) / 100000000;
}

/**
 * [금액 수정] 칸의 최종 환불 합계를 상점 통화 표기로 다시 적습니다. (S3-D27)
 *
 * 합계 줄은 숫자에 통화 코드를 이어 붙여 `22500 KRW` 였다 — 같은 화면의 다른 금액과 실행 창(S3-D1)은
 * `22,500원` 이다. 화면 표현식은 상점 통화 설정을 읽는 포맷터를 부를 수 없어, 두 입력 칸이 바뀔 때 이
 * 핸들러가 표기 문자열을 `_local.refundInputTotalFormatted` 에 적는다. 첫 표기는 상세 응답의
 * `refund_total_formatted` 가 채운다.
 *
 * @param action 액션 객체 (params: refundAmount · refundPointsAmount · currencyCode)
 * @param _context 액션 컨텍스트
 */
export function formatRefundInputTotalHandler(
    action: ActionWithParams<{
        refundAmount?: string | number | null;
        refundPointsAmount?: string | number | null;
        currencyCode?: string | null;
    }>,
    _context?: ActionContext,
): void {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const total = sumAmounts(amountOrNull(params.refundAmount) ?? 0, amountOrNull(params.refundPointsAmount) ?? 0);

    G7Core.state.setLocal?.({ refundInputTotalFormatted: formatAmountInCurrency(total, params.currencyCode) });
}

/**
 * 환불 실행 확인 모달을 엽니다. (D18 — 진입 지점)
 *
 * 모달은 부모 화면과 다른 컨텍스트라 `_local` 표현식을 해석하지 못한다. 그래서 **지금 입력된
 * 값**을 전역 문맥으로 넘겨, 모달이 예고하는 값과 실행이 싣는 본문이 같은 하나를 읽게 한다 —
 * 모달이 제 사본을 만들면 그 둘이 갈리고, 화면이 예고한 것과 다른 금액이 나간다.
 *
 * @param action 액션 객체 (params)
 * @param _context 액션 컨텍스트
 */
export async function openClaimRefundExecuteHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimId?: number | string | null;
        claimNumber?: string | null;
        currencyCode?: string | null;
        refundAmount?: string | number | null;
        refundPointsAmount?: string | number | null;
        refundMethod?: string | null;
        /** 상세가 준 저장(실효) 수단의 이름 — 수단이 선택지 밖일 때(꺼진 플러그인의 PG 등) 대신 그린다(S3-D25) */
        refundMethodLabel?: string | null;
        refundMethods?: Array<{ value?: string; label?: string }>;
        refundBankName?: string | null;
        refundBankAccount?: string | null;
        refundBankHolder?: string | null;
        refundAccountRequired?: boolean | string | null;
        refundAccountByMethod?: Record<string, { used?: boolean; required?: boolean; required_if_cash?: boolean }> | null;
        exchangeDifferenceTotal?: string | number | null;
        exchangeDifferenceFormatted?: string | null;
        pointsNotDeducted?: boolean | string | null;
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const claimId = Number(params.claimId);

    if (!Number.isFinite(claimId) || claimId <= 0) {
        logger.warn('[openClaimRefundExecute] 환불신청서 ID 가 없습니다.');

        return;
    }

    const method = (params.refundMethod ?? '').trim();
    // 선택지 밖의 수단은 상세가 준 저장(실효) 수단이다 — 결제 플러그인이 꺼지면 선택지에서 PG 가 빠져,
    // 값을 그대로 그리면 창에 「pg」 가 찍힌다(S3-D25). 그 수단의 이름으로 그린다.
    const methodLabel = (params.refundMethods ?? [])
        .find((option) => option?.value === method)?.label
        ?? ((params.refundMethodLabel ?? '').trim() || method);

    // 계좌 줄·입력 칸은 **지금 고른 수단**의 판정을 쓴다 — 운영자가 상세에서 수단을 바꾼 뒤 이 창을 연다.
    // 서버 판정이 없는 응답(구버전 등)이면 저장 수단 판정으로 떨어지고 계좌 줄은 값이 있을 때 보인다(종전 동작).
    const judgement = params.refundAccountByMethod?.[method] ?? null;
    // 교환 차액 환급은 별도 칼럼이라 두 환불액에 들어 있지 않다 — 고객에게 실제로 나가는 총액에 더한다.
    const exchangeDifference = amountOrNull(params.exchangeDifferenceTotal) ?? 0;

    // 계좌 이체 환불은 운영자가 손으로 보낸다 — 어느 계좌인지 이 창에 없으면 다른 화면을
    // 뒤져야 하고, 못 찾으면 구매자에게 다시 묻게 된다. 이미 적힌 계좌의 은행명은 서버가
    // 해석해 실어 준다. 계좌를 새로 적는 칸의 은행 선택지는 셸의 `paymentSettings`(공개 결제
    // 설정 — 권한 게이트가 없다)가 나른다: 관리자 설정 엔드포인트(`settings.read`)를 읽으면
    // 클레임만 볼 수 있는 운영자에게는 403 이 되어 선택지가 조용히 빈다.
    const refundAmount = amountOrNull(params.refundAmount) ?? 0;
    const refundPointsAmount = amountOrNull(params.refundPointsAmount) ?? 0;
    const refundTotal = sumAmounts(sumAmounts(refundAmount, refundPointsAmount), exchangeDifference);
    const context: ClaimRefundExecuteContext = {
        claimId,
        claimNumber: params.claimNumber ?? '',
        currencyCode: params.currencyCode ?? '',
        refundAmount,
        refundPointsAmount,
        refundTotal,
        refundAmountFormatted: formatAmountInCurrency(refundAmount, params.currencyCode),
        refundPointsAmountFormatted: formatAmountInCurrency(refundPointsAmount, params.currencyCode),
        refundTotalFormatted: formatAmountInCurrency(refundTotal, params.currencyCode),
        exchangeDifferenceTotal: exchangeDifference,
        exchangeDifferenceFormatted: exchangeDifference > 0 ? (params.exchangeDifferenceFormatted ?? '') : '',
        refundMethod: method,
        refundMethodLabel: methodLabel,
        refundBankName: (params.refundBankName ?? '').trim(),
        refundBankAccount: (params.refundBankAccount ?? '').trim(),
        refundBankHolder: (params.refundBankHolder ?? '').trim(),
        // 실행 게이트는 본문의 현금액(지금 입력칸 값)으로 계좌를 요구한다 — 저장 현금으로 판정하면 현금 0원 신청서에 현금을
        // 입력하고 실행할 때 창에 계좌 칸·동의가 없는 채 거절된다. 현금 축을 뺀 판정(required_if_cash)이 있으면 입력값과 합친다.
        refundAccountRequired: judgement
            ? (typeof judgement.required_if_cash === 'boolean'
                ? judgement.required_if_cash && (amountOrNull(params.refundAmount) ?? 0) > 0
                : judgement.required === true)
            : params.refundAccountRequired === true || params.refundAccountRequired === 'true',
        refundAccountUsed: judgement ? judgement.used === true : true,
        pointsNotDeducted: params.pointsNotDeducted === true || params.pointsNotDeducted === 'true',
    };

    // 계좌 칸·동의는 **창을 열 때마다 비운다**. 남겨 두면 앞 신청서에 적은 계좌가 다음 건의
    // 칸에 들어앉아, 운영자가 손대지 않고 [환불하기]를 눌렀을 때 엉뚱한 계좌로 송금 기록이
    // 남는다. 동의도 마찬가지로, 한 번 켠 뒤 다음 건에서 그대로 켜져 있으면 계좌를 적지 않은
    // 건이 조용히 직권 완료된다.
    G7Core.state.set({
        claimRefundExecuteContext: context,
        isProcessing: false,
        claimRefundExecuteBankCode: '',
        claimRefundExecuteBankAccount: '',
        claimRefundExecuteBankHolder: '',
        claimRefundExecuteForce: false,
    });
    G7Core.modal?.open?.('modal_claim_refund_execute');
}

/**
 * 환불을 실행합니다. (D18 — `POST admin/refunds/{id}/execute`)
 *
 * 본문 금액은 모달이 보여준 문맥 그대로다 — 저장하지 않고도 화면에 입력된 값으로 실행할 수
 * 있는 것이 이 엔드포인트의 계약이며, 상한(D19)은 서버가 실행 직전에 다시 계산해 막는다.
 *
 * 재실행(더블클릭)은 409 다 — 입력 오류가 아니라 충돌이므로 새로고침을 안내하고, 실제로
 * 상세를 다시 읽어 운영자가 확정된 상태를 보게 한다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function executeClaimRefundHandler(
    _action: ActionWithParams,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const context: ClaimRefundExecuteContext | null = G7Core.state.get()?.claimRefundExecuteContext ?? null;

    if (!context?.claimId) {
        G7Core.toast?.error?.(
            t('sirsoft-ecommerce.admin.claim.detail.refund_execute.error', '환불을 실행하지 못했습니다.'),
        );

        return;
    }

    // 페이지의 [환불하기] 는 이 플래그로 잠금을 선언한다 — 세우는 쪽이 없으면 화면이 잠금을
    // 약속해 놓고 실제로는 잠그지 않는다 (형제 `saveClaimAmounts` 와 같은 형태).
    G7Core.state.setLocal?.({ isExecutingRefund: true });

    try {
        const body: Record<string, unknown> = {
            refund_amount: context.refundAmount,
            refund_points_amount: context.refundPointsAmount,
        };

        if (context.refundMethod !== '') {
            body.refund_method = context.refundMethod;
        }

        // 이 창에서 적은 환불 계좌 / 계좌 없이 완료로 기록하겠다는 동의 (T2-D6).
        // 둘 중 하나가 없으면 서버가 422 로 막는다 — 창의 실행 버튼도 같은 조건으로 잠그므로
        // 정상 경로에서는 이 거절을 보지 않지만, 게이트는 서버가 정본이다.
        const state = G7Core.state.get() ?? {};
        const bankCode = String(state.claimRefundExecuteBankCode ?? '').trim();
        const bankAccount = String(state.claimRefundExecuteBankAccount ?? '').trim();
        const bankHolder = String(state.claimRefundExecuteBankHolder ?? '').trim();

        if (state.claimRefundExecuteForce === true) {
            body.force_without_refund_bank = true;
        } else if (bankCode && bankAccount && bankHolder) {
            body.refund_bank = {
                bank_code: bankCode,
                account_number: bankAccount,
                holder: bankHolder,
            };
        }

        const response = await G7Core.api.post(`${REFUND_EXECUTE_URL}/${context.claimId}/execute`, body);

        if (response?.success === false) {
            throw new Error(response?.message ?? 'refund execution failed');
        }

        G7Core.toast?.success?.(
            t('sirsoft-ecommerce.admin.claim.detail.refund_execute.executed', '환불을 실행했습니다.'),
        );

        G7Core.state.set({
            claimRefundExecuteContext: null,
            claimRefundExecuteBankCode: '',
            claimRefundExecuteBankAccount: '',
            claimRefundExecuteBankHolder: '',
            claimRefundExecuteForce: false,
        });
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        const status = error?.response?.status ?? error?.status ?? null;

        if (status === 409) {
            // 충돌이지 입력 오류가 아니다 — 이미 확정된 상태를 보여 주는 것이 조치다.
            G7Core.toast?.warning?.(
                serverMessageOf(error)
                ?? t(
                    'sirsoft-ecommerce.admin.claim.detail.refund_execute.conflict',
                    '이미 실행됐거나 실행 중입니다. 새로고침 후 다시 확인해주세요.',
                ),
            );
            refetchSources(DETAIL_REFETCH_SOURCES);
        } else {
            logger.error('[executeClaimRefund] 환불 실행 실패:', error);
            G7Core.toast?.error?.(
                serverMessageOf(error)
                ?? t('sirsoft-ecommerce.admin.claim.detail.refund_execute.error', '환불을 실행하지 못했습니다.'),
            );
        }

        throw error;
    } finally {
        // 실패해도 잠금은 풀린다 — 그러지 않으면 한 번 실패한 뒤로 실행 자체를 못 한다.
        G7Core.state.setLocal?.({ isExecutingRefund: false });
    }
}

/**
 * 목록 행에서 완료 처리 모달의 대상 한 건을 만듭니다.
 *
 * @param id 신청서 ID
 * @param rows 목록 행 배열
 * @returns 완료 처리 대상
 */
function toCompleteTarget(id: number, rows: ClaimListRow[]): ClaimCompleteTarget {
    const row = rows.find((entry) => Number(entry.id) === id);
    const summary = row?.item_summary ?? null;
    const extra = Math.max(0, (summary?.item_count ?? 0) - 1);

    let itemLabel: string | null = summary?.product_name ?? null;
    if (itemLabel !== null && extra > 0) {
        const more = t('sirsoft-ecommerce.admin.claim.column.item_more', '+' + String(extra), { count: extra });
        itemLabel = itemLabel + ' ' + more;
    }

    return {
        id,
        claim_number: row?.claim_number ?? null,
        requester_name: row?.requester_name ?? null,
        item_label: itemLabel,
    };
}

/**
 * 목록 일괄 회수중·발송중 모달의 신청서 한 줄을 만듭니다. (저장된 송장으로 미리 채움)
 *
 * @param id 신청서 ID
 * @param status 목표 상태 (회수중이면 회수 송장, 발송중이면 재발송 송장)
 * @param rows 목록 행
 * @returns 모달 한 줄
 */
function bulkTrackingRow(id: number, status: string, rows: ClaimListRow[]): ClaimBulkTrackingRow {
    const row = rows.find((candidate) => Number(candidate.id) === id);
    const carrierId = status === 'redelivering' ? row?.redelivery_carrier_id : row?.collection_carrier_id;
    const trackingNumber = status === 'redelivering' ? row?.redelivery_tracking_number : row?.collection_tracking_number;

    return {
        id,
        claim_number: row?.claim_number ?? null,
        requester_name: row?.requester_name ?? null,
        carrier_id: carrierId === null || carrierId === undefined ? '' : String(carrierId),
        tracking_number: trackingNumber ?? '',
    };
}

/**
 * 목록 일괄 회수중·발송중 모달에서 한 신청서의 택배사·운송장 입력을 씁니다.
 *
 * @param action 액션 객체 (params: claimId / field / value)
 * @param _context 액션 컨텍스트
 */
export function updateClaimBulkTrackingHandler(
    action: ActionWithParams<{ claimId?: number | string; field?: string; value?: string | number | null }>,
    _context?: ActionContext,
): void {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const claimId = Number(params.claimId);
    const field = params.field === 'carrier_id' ? 'carrier_id' : params.field === 'tracking_number' ? 'tracking_number' : null;
    const confirmData = G7Core.state.get()?.claimBulkConfirmData;

    if (!confirmData || field === null || !Number.isFinite(claimId)) return;

    G7Core.state.set({
        claimBulkConfirmData: {
            ...confirmData,
            trackings: (confirmData.trackings ?? []).map((row: ClaimBulkTrackingRow) => (
                row.id === claimId ? { ...row, [field]: String(params.value ?? '') } : row
            )),
        },
    });
}

/**
 * 회수중·발송중인데 택배사·운송장이 비었으면 안내하고 true 를 돌려줍니다. (상품 칸·목록 일괄 공용)
 *
 * 길이는 여기서 보지 않는다 — 입력칸이 `maxLength` 로 DB 칸 길이(50)를 이미 막는다.
 *
 * @param status 목표 상태
 * @param carrierId 택배사 ID
 * @param trackingNumber 운송장 번호
 * @returns 송장이 필요한데 비었으면 true
 */
function trackingMissing(status: string, carrierId?: string | number | null, trackingNumber?: string | null): boolean {
    if (!TRACKING_REQUIRED_STATUSES.includes(status)) {
        return false;
    }

    if (String(carrierId ?? '').trim() !== '' && (trackingNumber ?? '').trim() !== '') {
        return false;
    }

    core()?.toast?.warning?.(
        t('sirsoft-ecommerce.admin.claim.detail.shipping.tracking_incomplete', '택배사와 송장번호를 함께 입력해주세요.'),
    );

    return true;
}

/**
 * 상태 값의 표시 라벨을 유형별 어휘 사전에서 해석합니다.
 *
 * @param claimType 클레임 유형
 * @param status 상태 값
 * @returns 라벨 (미해석이면 상태 값 그대로)
 */
function resolveStatusLabel(claimType: string, status: string): string {
    const prefix = STATUS_LABEL_PREFIX[claimType];

    return prefix ? t(prefix + status, status) : status;
}

/**
 * 일괄 상태 변경을 실행합니다.
 *
 * 부분 성공 계약이다 — 게이트에 막힌 건은 건너뛰고 사유와 함께 보고된다. 성공 건수만
 * 알리면 운영자는 막힌 건을 처리된 것으로 오해하므로, 승인이 결제 요청으로 우회된 건과
 * 건너뛴 건을 나누어 알린다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function executeClaimBulkActionHandler(
    _action: ActionWithParams,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const confirmData = G7Core.state.get()?.claimBulkConfirmData;

    if (!confirmData?.ids?.length || !confirmData?.status) {
        G7Core.toast?.error?.(t('sirsoft-ecommerce.admin.claim.bulk.error', '일괄 상태 변경에 실패했습니다.'));

        return;
    }

    const trackings: ClaimBulkTrackingRow[] = confirmData.trackings ?? [];
    const requiresTracking = TRACKING_REQUIRED_STATUSES.includes(confirmData.status);

    // 한 건이라도 비었으면 보내지 않는다 — 모달이 닫히지 않도록 실패로 끝낸다(모달의 fail 은 진행 표시만 되돌린다).
    if (requiresTracking && trackings.some((row) => trackingMissing(confirmData.status, row.carrier_id, row.tracking_number))) {
        throw new Error('tracking missing');
    }

    try {
        const body: Record<string, unknown> = {
            type: confirmData.claimType,
            ids: confirmData.ids,
            status: confirmData.status,
        };

        if (confirmData.status === 'rejected') {
            body.reject_reason = confirmData.rejectReason ?? '';
        }

        if (requiresTracking) {
            body.trackings = trackings.map((row) => ({
                id: row.id,
                carrier_id: Number(row.carrier_id),
                tracking_number: row.tracking_number.trim(),
            }));
        }

        const response = await G7Core.api.post(BULK_STATUS_URL, body);

        if (response?.success === false) {
            throw new Error(response?.message ?? 'bulk status failed');
        }

        const data = response?.data ?? {};

        G7Core.toast?.success?.(
            t('sirsoft-ecommerce.admin.claim.bulk.result_summary', '일괄 상태 변경을 처리했습니다.', {
                succeeded: (data.succeeded ?? []).length,
                requested: (data.payment_requested ?? []).length,
                skipped: (data.skipped ?? []).length,
            }),
        );
        reportBulkSkips(data);

        G7Core.state.set({ claimBulkConfirmData: null });
        G7Core.state.setLocal?.({ selectedItems: [], bulkClaimStatus: '', bulkRejectReason: '' });
        G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'claims' } });
    } catch (error: any) {
        logger.error('[executeClaimBulkAction] 일괄 전이 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.bulk.error', '일괄 상태 변경에 실패했습니다.'),
        );
        throw error;
    }
}

/**
 * 건너뛴 건이 있으면 사유와 함께 따로 알립니다.
 *
 * 성공 토스트만 띄우면 막힌 건이 처리된 것으로 보인다 — 서버는 사유를 건별로 주므로
 * 건수와 첫 사유를 함께 노출한다.
 *
 * @param data 응답 데이터
 */
function reportBulkSkips(data: any): void {
    const skipped = data?.skipped ?? [];
    if (skipped.length === 0) return;

    core()?.toast?.warning?.(
        t('sirsoft-ecommerce.admin.claim.bulk.skipped_notice', `${skipped.length}건은 처리되지 않았습니다.`, {
            count: skipped.length,
            reason: skipped[0]?.message ?? '',
        }),
    );
}
