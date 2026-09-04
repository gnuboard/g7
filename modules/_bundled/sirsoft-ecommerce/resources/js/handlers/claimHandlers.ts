/**
 * 관리자 클레임(교환/반품/환불/취소) 목록·상세 핸들러 — S8b(목록) · S9a(상세)
 *
 * 목록 본체가 쓰는 두 갈래를 담는다:
 *  - 일괄 상태 변경: 확인 모달 → `POST admin/claims/bulk-status` (부분 성공 계약)
 *  - 완료 처리 모달 (D26): 세 진입점 공용 — 미리보기(`completion-preview`) → 실행
 *
 * 상세 화면이 쓰는 갈래는 셋이다:
 *  - 상품 단위 일괄 전이 (D25): `PATCH admin/{세그먼트}/{id}/options/bulk-status`
 *  - 회수·재발송 송장 저장: `PUT .../collection-tracking` · `PUT exchanges/{id}/redelivery-tracking`
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
 * @param error 오류 객체
 * @returns 사유 문구 (없으면 null)
 */
function serverMessageOf(error: any): string | null {
    const data = error?.response?.data ?? error?.data ?? {};

    return data?.message ?? error?.message ?? null;
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

    const targets: ClaimCompleteTarget[] = context.targets ?? [];
    const restoreMap: Record<string, boolean> = globalState.claimCompleteRestore ?? {};
    const createRefundRequest: boolean = globalState.claimCompleteCreateRefundRequest !== false;

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
    });

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

    if (!context?.execute || rows.length === 0) {
        G7Core.toast?.error?.(t('sirsoft-ecommerce.admin.claim.complete.error', '완료 처리에 실패했습니다.'));

        return;
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

    const body: Record<string, unknown> = {
        ...(context.execute.body ?? {}),
        status: 'completed',
        completion: {
            restore_stock_by_item: mergedRestore,
            create_refund_request: globalState.claimCompleteCreateRefundRequest !== false,
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
    });
}

/**
 * 일괄 상태 변경 확인 데이터를 만듭니다.
 *
 * 완료 전이는 곧바로 실행하지 않고 완료 처리 모달을 연다 (D26) — 재고 복구 여부와
 * 환불신청서 접수 여부를 운영자가 골라야 하고, 그 선택 없이 보내면 요청이 422 다.
 *
 * @param action 액션 객체 (params.claimType / status / ids / rows)
 * @param _context 액션 컨텍스트
 */
export async function buildClaimBulkConfirmDataHandler(
    action: ActionWithParams<{
        claimType?: string;
        status?: string;
        ids?: number[];
        rows?: ClaimListRow[];
        completeDefaults?: ClaimCompleteDefaults;
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
        status?: string;
        rows?: ClaimDetailItemRow[];
        completeDefaults?: ClaimCompleteDefaults;
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

    // 상품 단위 전이는 교환·반품에만 있다 — 환불에는 상품 회수·개별 상품 전이 축이 없다.
    if (claimType !== 'exchange' && claimType !== 'return') {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.detail.items.bulk_not_supported', '이 유형은 상품 단위 상태 변경을 지원하지 않습니다.'),
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
                body: { items: itemIds },
                refetch: DETAIL_REFETCH_SOURCES,
                clearLocal: { selectedClaimItems: [], itemBulkStatus: '' },
            },
        });

        return;
    }

    try {
        const response = await G7Core.api.patch(`${base}/options/bulk-status`, {
            items: itemIds,
            status,
        });

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

        G7Core.state.setLocal?.({ selectedClaimItems: [], itemBulkStatus: '' });
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[executeClaimItemBulkStatus] 상품 단위 전이 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.items.bulk_error', '신청 상품 상태 변경에 실패했습니다.'),
        );
        throw error;
    }
}

/**
 * 회수·재발송 운송장을 저장합니다. (상세 화면 배송 정보 섹션)
 *
 * 택배사와 송장번호는 함께 입력해야 한다 — 하나만 보내면 서버가 `claim_tracking_incomplete`
 * 로 거절하므로, 왕복을 돌기 전에 화면에서 먼저 알린다. 회수준비중·재발송준비중이면 저장이
 * 다음 상태로의 전이를 함께 일으키므로 성공 후 상세를 다시 읽는다.
 *
 * @param action 액션 객체 (params)
 * @param _context 액션 컨텍스트
 */
export async function saveClaimTrackingHandler(
    action: ActionWithParams<{
        pathSegment?: string;
        claimId?: number | string | null;
        kind?: 'collection' | 'redelivery';
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
        logger.warn('[saveClaimTracking] 신청서 식별 축이 없습니다.');

        return;
    }

    const carrierId = params.carrierId === undefined || params.carrierId === null || params.carrierId === ''
        ? null
        : Number(params.carrierId);
    const trackingNumber = (params.trackingNumber ?? '').trim();

    if ((carrierId === null) !== (trackingNumber === '')) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.admin.claim.detail.shipping.tracking_incomplete', '택배사와 송장번호를 함께 입력해주세요.'),
        );

        return;
    }

    const isRedelivery = params.kind === 'redelivery';
    const savingKey = isRedelivery ? 'isSavingRedelivery' : 'isSavingCollection';
    const url = isRedelivery ? `${base}/redelivery-tracking` : `${base}/collection-tracking`;

    G7Core.state.setLocal?.({ [savingKey]: true });

    try {
        const response = await G7Core.api.put(url, {
            carrier_id: carrierId,
            tracking_number: trackingNumber === '' ? null : trackingNumber,
        });

        if (response?.success === false) {
            throw new Error(response?.message ?? 'tracking save failed');
        }

        G7Core.toast?.success?.(
            t('sirsoft-ecommerce.admin.claim.detail.shipping.tracking_saved', '운송장 정보를 저장했습니다.'),
        );
        refetchSources(DETAIL_REFETCH_SOURCES);
    } catch (error: any) {
        logger.error('[saveClaimTracking] 운송장 저장 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error)
            ?? t('sirsoft-ecommerce.admin.claim.detail.shipping.tracking_error', '운송장 정보를 저장하지 못했습니다.'),
        );
        throw error;
    } finally {
        // 실패해도 버튼은 되살린다 — 그러지 않으면 한 번 실패한 뒤로 저장 자체를 못 한다.
        G7Core.state.setLocal?.({ [savingKey]: false });
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
    /** 환불 수단 값 */
    refundMethod: string;
    /** 환불 수단 라벨 (서버가 준 선택지에서 해석) */
    refundMethodLabel: string;
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
        refundMethods?: Array<{ value?: string; label?: string }>;
        refundBankName?: string | null;
        refundBankAccount?: string | null;
        refundBankHolder?: string | null;
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
    const methodLabel = (params.refundMethods ?? [])
        .find((option) => option?.value === method)?.label ?? method;

    // 계좌 이체 환불은 운영자가 손으로 보낸다 — 어느 계좌인지 이 창에 없으면 다른 화면을
    // 뒤져야 하고, 못 찾으면 구매자에게 다시 묻게 된다. 은행명은 서버가 해석해 실어 준다:
    // 화면이 은행 코드표를 읽으려면 설정 데이터소스가 필요한데 그 게이트가 이 화면의 라우트
    // 권한보다 좁아, 클레임만 볼 수 있는 운영자에게는 그 데이터소스만 403 이 되어 은행명이
    // 조용히 빈다.
    const context: ClaimRefundExecuteContext = {
        claimId,
        claimNumber: params.claimNumber ?? '',
        currencyCode: params.currencyCode ?? '',
        refundAmount: amountOrNull(params.refundAmount) ?? 0,
        refundPointsAmount: amountOrNull(params.refundPointsAmount) ?? 0,
        refundMethod: method,
        refundMethodLabel: methodLabel,
        refundBankName: (params.refundBankName ?? '').trim(),
        refundBankAccount: (params.refundBankAccount ?? '').trim(),
        refundBankHolder: (params.refundBankHolder ?? '').trim(),
    };

    G7Core.state.set({ claimRefundExecuteContext: context, isProcessing: false });
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

        const response = await G7Core.api.post(`${REFUND_EXECUTE_URL}/${context.claimId}/execute`, body);

        if (response?.success === false) {
            throw new Error(response?.message ?? 'refund execution failed');
        }

        G7Core.toast?.success?.(
            t('sirsoft-ecommerce.admin.claim.detail.refund_execute.executed', '환불을 실행했습니다.'),
        );

        G7Core.state.set({ claimRefundExecuteContext: null });
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

    try {
        const response = await G7Core.api.post(BULK_STATUS_URL, {
            type: confirmData.claimType,
            ids: confirmData.ids,
            status: confirmData.status,
        });

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
        G7Core.state.setLocal?.({ selectedItems: [], bulkClaimStatus: '' });
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
