/**
 * 클레임(교환/반품/취소·환불) 신청서 작성 핸들러 (회원 · 비회원 공용)
 *
 * 작성 화면의 상태를 한 곳에서 다룬다 — 신청 상품 선택·수량, 교환 대상(상품 속성),
 * 사유 선택, 재발송지, 청구 2축, 미리보기(서버 재계산), 제출·결제 진입.
 *
 * 금액은 전부 서버가 정한다(`claims/preview`). 화면은 표시만 하고, 이 핸들러도 금액을
 * 스스로 계산하지 않는다 — 미리보기와 실행이 다른 식을 쓰면 예고한 금액과 청구된 금액이
 * 조용히 갈라진다.
 *
 * 미리보기는 **낙관적 선택 ↔ 서버 재계산의 왕복**이다. 그래서 셋을 지킨다:
 *  ① 요청마다 세대 번호를 붙여 **최신 요청의 응답만** 반영한다(성공·실패 양 경로).
 *  ② 재계산이 실패하면 금액을 지우고 제출을 막는다 — 옛 요약을 남긴 채 새 선택으로
 *     제출되면 화면이 예고한 금액과 실제 청구가 어긋난다.
 *  ③ 체크·라디오의 표시값은 **사용자의 선택 상태**에서 읽는다. 서버가 정하는 것은 요약과
 *     금액이지 체크 표시가 아니다.
 *
 * 회원/비회원 분기는 셸이 준 `claimApiBase` / `isGuest` 로만 이뤄진다 — 핸들러가 로그인
 * 여부를 스스로 판단하지 않는다(공유 partial 규율).
 */

import type { ActionContext } from '../types';
import { formatAmountInCurrency } from './calculateCurrencyPrices';
import { claimRequestConfig, uploadPendingClaimFiles } from './claimImageHandlers';

const logger = ((window as any).G7Core?.createLogger?.('Ecom:ClaimApply')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:ClaimApply]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:ClaimApply]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:ClaimApply]', ...args),
};

interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/**
 * 안내 문구를 모듈 다국어 키로 해석합니다. (미해석이면 폴백)
 *
 * 이 핸들러는 어느 사용자 템플릿에서든 돈다 — 템플릿 키(`claim.*`)에 기대면 그 키를 갖지 않은
 * 템플릿에서 키 원문이 토스트로 뜬다. 그래서 모듈 키(`sirsoft-ecommerce.user.claim_apply.*`)를 쓴다.
 * `G7Core.t` 는 번역이 없으면 **키를 그대로** 돌려주므로 `?? '문구'` 폴백은 돌지 않는다 — 키와 같은
 * 값이 돌아오면 폴백으로 떨어뜨린다(`claimHandlers.ts` 의 같은 헬퍼와 같은 규칙).
 *
 * @param key 모듈 다국어 키
 * @param fallback 폴백 문구 (번역이 없을 때)
 * @returns 해석된 문구
 */
function t(key: string, fallback: string): string {
    const resolved = (window as any).G7Core?.t?.(key);

    return typeof resolved === 'string' && resolved !== '' && resolved !== key ? resolved : fallback;
}

/** 신청서 작성 중인 상품 한 줄 */
export interface ClaimApplyItem {
    order_option_id: number;
    product_id: number | null;
    product_name: string;
    product_option_name: string;
    thumbnail_url: string;
    unit_price: number;
    unit_price_formatted: string;
    available_quantity: number;
    locked_quantity: number;
    claimable_types: string[];
    refund_branch: string | null;
    selected: boolean;
    quantity: number;
    /** 교환 방식은 신청서 전체가 아니라 상품마다 정한다 (D29) */
    exchange_mode: 'same' | 'change';
    new_product_option_id: number | null;
    new_option_label: string;
    /** 이 교환에 얹은 추가옵션의 표시 이름 (작성 화면 전용 — 접수 후에는 서버 동결 스냅샷을 읽는다) */
    new_additional_labels: string[];
    custom_options: Record<string, any> | null;
    price_difference: number;
    price_difference_formatted: string;
    /** 단가의 보조통화 표기 (기준통화 제외) */
    mc_unit_price: SecondaryCurrencyAmount[];
    /** 제출 거절(422)이 이 줄을 지목한 사유 — 없으면 빈 문자열 */
    validation_error: string;
}

/** 보조통화 한 줄 — 화면은 `formatted` 만 그린다 */
export interface SecondaryCurrencyAmount {
    code: string;
    formatted: string;
}

/**
 * 엔진이 커스텀 핸들러에 넘기는 액션 컨텍스트 중 이 파일이 쓰는 부분.
 *
 * `setState` 는 핸들러를 부른 컴포넌트의 로컬 상태 writer 다.
 */
type HandlerContext = ActionContext & {
    setState?: (updates: Record<string, any>) => void;
};

/** 로컬 상태 쓰기 함수 */
type LocalWriter = (updates: Record<string, any>) => void;

/** 미리보기 debounce 타이머 */
let claimEstimateDebounceTimer: ReturnType<typeof setTimeout> | null = null;

/** 미리보기 세대 번호 — 최신 요청의 응답만 반영한다 */
let claimEstimateGeneration = 0;

/**
 * debounce 타이머와 세대 번호를 정리합니다.
 *
 * 화면을 떠날 때(lifecycle.onUnmount) 호출한다. 정리하지 않으면 떠난 화면의 응답이
 * 다음 화면의 로컬 상태에 쓰인다.
 */
export function clearClaimApplyTimers(): void {
    if (claimEstimateDebounceTimer) {
        clearTimeout(claimEstimateDebounceTimer);
        claimEstimateDebounceTimer = null;
    }

    claimEstimateGeneration += 1;
}

/**
 * `claimable-options` 응답 페이로드를 읽습니다.
 *
 * 진입 시 `_local.claimableSnapshot` 에 박아 둔 사본이 1순위다. 데이터소스 store
 * (`G7Core.dataSource.get`)는 화면에 따라 이 페이로드를 돌려주지 않는다 — 주문 데이터소스가
 * `blocking` 인 작성 셸에서는 refetch 로 받은 값이 store 에 나타나지 않아, 렌더 바인딩
 * (`claimable?.data?...`)은 값을 그리는데 핸들러만 null 을 본다(2026-08-31 브라우저 실측).
 * 그 상태에서는 사유를 골라도 귀책이 비어 오류 없이 안내만 사라진다.
 *
 * @param G7Core G7Core 인스턴스
 * @returns 페이로드 (없으면 null)
 */
function claimableData(G7Core: any): any {
    const snapshot = G7Core.state?.getLocal?.()?.claimableSnapshot;

    return snapshot ?? G7Core.dataSource?.get?.('claimable')?.data ?? null;
}

/**
 * 주문 시점 기준 통화 코드를 읽습니다.
 *
 * 금액 표기는 거래 시점 통화로 고정한다 — 운영자가 이후 기본 통화를 바꿔도 이 신청서의
 * 표기는 주문 통화를 따른다.
 *
 * **`claimable` 응답을 먼저 본다.** 이 화면의 상품 줄은 `claimable` 의 onSuccess 에서 세워지는데,
 * 통화 기준을 `order` 데이터소스에서만 읽으면 두 응답 중 어느 쪽이 먼저 오느냐에 따라 결과가
 * 갈린다 — `order` 가 늦게 오는 순서에서는 기준이 null 이 되어 보조 통화 목록에서 기준 통화가
 * 걸러지지 않고, 같은 금액이 두 줄로 나온다(S17 트랙 A F-5). 반응 대상 응답이 스스로 그 기준을
 * 들고 있으면 경합 자체가 성립하지 않는다. `order` 는 그 값이 없는 예전 응답을 위한 폴백이다.
 *
 * @param G7Core G7Core 인스턴스
 * @param claimable `claimable-options` 응답 (있으면 이 값을 우선한다)
 * @returns 통화 코드 (없으면 null → 설정 기본 통화)
 */
function orderCurrencyCode(G7Core: any, claimable?: any): string | null {
    return (claimable ?? claimableData(G7Core))?.base_currency
        ?? G7Core.dataSource?.get?.('order')?.data?.base_currency
        ?? null;
}

/**
 * 오류를 구매자가 이 화면에서 고칠 수 있는 것과 그렇지 않은 것으로 가릅니다. (§6-3 P13)
 *
 * 4xx 는 상품·수량·사유처럼 구매자가 고쳐서 다시 시도할 수 있는 거절이므로 **화면에** 남긴다.
 * 5xx 와 응답 자체가 오지 않은 실패(네트워크 끊김 — 상태코드가 없다)는 구매자가 고칠 것이
 * 없고 화면에 남길 상태도 아니므로 **토스트로만** 알린다.
 *
 * 상태코드를 보지 않고 한쪽으로 몰면 서버 장애가 "상품·수량·사유를 다시 확인해 주세요" 로
 * 안내되어, 구매자는 자기 신청서에서 잘못을 찾다가 못 찾고 몇 번을 고쳐도 계속 막힌다.
 *
 * @param error 잡은 오류
 * @returns 구매자가 고칠 수 있는 거절이면 true
 */
export function isUserFixableClaimError(error: any): boolean {
    const status = Number(error?.response?.status ?? error?.status ?? 0);

    return status >= 400 && status < 500;
}

/**
 * 신청서 작성 화면의 라우트 값(유형·미리 선택된 상품)을 주소에서 읽습니다.
 *
 * 이 핸들러는 `claimable` 데이터소스의 onSuccess 로 실행되는데, 그 실행 컨텍스트에는
 * **`route`·`query` 가 없다** — 데이터소스 onSuccess 는 `{ response }` 만 넘긴다. 그래서
 * 레이아웃이 `{{route.type}}` 을 param 으로 넘겨도 빈 값이 도착하고, 화면은 응답 200 에
 * 오류 0 인 채로 빈 신청서가 된다. 주소는 그 시점에도 확정돼 있으므로 여기서 읽는다.
 *
 * 라우트 형태: `/mypage/orders/{orderNumber}/claims/apply/{type}?options=1,2`
 * (비회원 셸도 `.../claims/apply/{type}` 로 끝나는 같은 형태다.)
 *
 * @returns 유형과 미리 선택된 주문 옵션 ID 문자열
 */
export function resolveClaimApplyRoute(): { claimType: string; preselected: string } {
    if (typeof window === 'undefined' || !window.location) {
        return { claimType: '', preselected: '' };
    }

    const segments = String(window.location.pathname || '').split('/').filter(Boolean);
    const applyIndex = segments.lastIndexOf('apply');
    const claimType = applyIndex >= 0 ? (segments[applyIndex + 1] ?? '') : '';

    let preselected = '';
    try {
        preselected = new URLSearchParams(window.location.search || '').get('options') ?? '';
    } catch {
        preselected = '';
    }

    return { claimType, preselected };
}

/**
 * 지금 열려 있는 화면이 **수정(edit)** 인지 판정합니다.
 *
 * 수정 라우트는 유형(`:type`)이 없고 클레임 번호만 받는다 — 유형은 서버가 번호로 해석한다
 * (`/mypage/claims/{번호}/edit`, 비회원 `{shopBase}/guest/claims/{번호}/edit`). 그래서 작성
 * 화면의 유형 가드를 그대로 적용하면 수정 진입이 통째로 404 가 된다.
 *
 * @returns 수정 화면이면 true
 */
export function isClaimEditRoute(): boolean {
    return resolveClaimEditNumber() !== '';
}

/**
 * 수정 대상 클레임 번호를 주소에서 읽습니다.
 *
 * 유형별 접두사(EX/RT/RF/CN)로 판정하지 않는다 — 운영자가 채번 설정에서 접두사를 바꿀 수
 * 있어, 접두사로 가르면 그 설정을 바꾼 상점에서만 조용히 어긋난다. 위치(`.../{번호}/edit`)로
 * 읽고 유형 해석은 서버에 맡긴다.
 *
 * @returns 클레임 번호 (수정 화면이 아니면 빈 문자열)
 */
export function resolveClaimEditNumber(): string {
    if (typeof window === 'undefined' || !window.location) return '';

    const segments = String(window.location.pathname || '').split('/').filter(Boolean);
    const editIndex = segments.lastIndexOf('edit');

    if (editIndex < 1) return '';
    if (segments[editIndex - 2] !== 'claims') return '';

    return decodeURIComponent(segments[editIndex - 1] ?? '');
}

/**
 * 작성 셸의 주문 데이터소스가 404 로 끝났을 때의 처분을 정합니다.
 *
 * 작성 진입은 주소가 주문번호를 품으므로 404 는 곧 "열 수 없는 주문" 이고 오류 화면을
 * 세워야 한다. 수정 진입은 주소에 주문번호가 없어 **첫 호출이 반드시 404** 다 — 주문번호는
 * 신청서 응답이 알려 주고 그 뒤 체인이 올바른 번호로 다시 부른다. 그 정상 경로에서 오류
 * 화면을 세우면 수정 화면이 열리지 않는다.
 *
 * 두 경우를 데이터소스의 `errorHandling` 만으로는 가를 수 없다 — 그 자리는 조건 분기를
 * 받지 않는다(핸들러의 `conditions` 배열이 전달되지 않는다). 그래서 판정을 여기서 한다.
 *
 * @example
 * { "handler": "sirsoft-ecommerce.claimApplyOrderMissing" }
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function claimApplyOrderMissingHandler(
    _action: ActionWithParams,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.dispatch) return;

    if (isClaimEditRoute()) return;

    await G7Core.dispatch({
        handler: 'showErrorPage',
        params: { errorCode: 404, target: 'content' },
    });
}

/**
 * 저장된 신청서의 환불 우선순위를 되살립니다. (S1-D13)
 *
 * 응답이 접수 때 고른 우선순위(`refund_priority`)를 실어 주면 그 값이 정본이다. 금액 분해로
 * 역산하면 PG 잔액이 모자라 마일리지로 넘친 **PG 우선** 신청서가 「마일리지 우선」 으로 뒤집힌다
 * (#599 validate 2026-09-23) — 두 우선순위가 같은 분해를 낳는 경우가 있어 분해만으로는 가를 수 없다.
 *
 * 그 필드가 없는 응답(구버전 서버)만 종전처럼 분해로 역산한다: 마일리지 환불액이 0 이면 PG 우선,
 * 마일리지가 먼저 소진됐다면 마일리지 우선.
 *
 * @param claim 신청서 페이로드
 * @returns 'points_first' | 'pg_first'
 */
function resolveRefundPriorityFromClaim(claim: any): string {
    const saved = claim?.refund_priority;

    if (saved === 'pg_first' || saved === 'points_first') {
        return saved;
    }

    const points = Number(claim?.refund_points_amount ?? 0);

    return points > 0 ? 'points_first' : 'pg_first';
}

/**
 * 수정 화면의 신청서를 읽어 셸 계약값을 세우고 주문 조회를 잇습니다.
 *
 * 데이터소스가 아니라 핸들러가 부른다 — 데이터소스는 조건부 auto_fetch 를 받지 않아,
 * 작성 진입에서도 신청서 조회가 한 번 나가게 된다(주소에 클레임 번호가 없어 반드시 실패하는
 * 요청이다). 진입 유형이 정해진 값을 화면이 아는 상황이라 그 요청을 내지 않는 것이 맞다.
 *
 * 신청서에서 주문번호를 얻은 뒤 `order` 를 다시 부르면 그 뒤는 작성 화면과 같은 체인이다
 * (order → claimOrderId → claimable → initClaimApply). `initClaimApply` 가 여기서 세운
 * `claimEditSnapshot` 을 보고 작성 대신 수정 상태를 만든다.
 *
 * 승인 이후의 신청서(`is_editable: false`)는 수정 폼을 세우지 않는다 — 안내 토스트를 띄우고
 * `detailPath`(신청서 상세)로 돌려보낸다(#599 T2-D7 · 운영 결정 2026-09-23: 승인 이후 수정은
 * 구매자는 못 하고 관리자만 한다). 서버도 같은 경우를 422(`claim_not_editable`)로 막지만,
 * 화면이 먼저 막지 않으면 구매자는 다 고쳐 쓴 뒤에야 거절을 받는다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.initClaimEdit",
 *   "params": { "claimApiBase": "/api/modules/sirsoft-ecommerce/user/claims", "isGuest": false, "detailPath": "/mypage/claims/EX20260901-0001" }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function initClaimEditHandler(
    action: ActionWithParams<{ claimApiBase?: string; isGuest?: boolean; detailPath?: string }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const base = String(action.params?.claimApiBase ?? '').replace(/\/+$/, '');
    const claimNumber = resolveClaimEditNumber();
    const isGuest = action.params?.isGuest === true;

    // 셸이 주소 앞부분을 주지 않았으면 보내지 않는다 — 회원과 비회원의 라우트 모양이 달라
    // 핸들러가 조립하면 한쪽이 404 가 된다(공유 partial 규율과 같은 근거).
    if (!base || !claimNumber) return;

    // claimIsGuest — 수정 진입의 첫 미리보기(initClaimApply 의 수정 분기)가 같은 자격으로 부르도록 남긴다.
    // 그 시점의 액션 파라미터에는 isGuest 가 없다(claimable.onSuccess 는 페이로드만 넘긴다).
    G7Core.state.setLocal({
        claimEditMode: true,
        claimEditNumber: claimNumber,
        claimEditLoading: true,
        claimIsGuest: isGuest,
    });

    try {
        const response = await G7Core.api.get(
            base + '/' + encodeURIComponent(claimNumber),
            claimRequestConfig(isGuest)
        );

        const claim = response?.data?.claim ?? null;

        if (!claim) {
            throw new Error(response?.message ?? 'Claim not found');
        }

        // 승인 이후의 신청서는 구매자가 고칠 수 없다(T2-D7) — 폼을 세우지 않고 상세로 돌려보낸다.
        // `=== false` 로 본다: 필드가 없는 응답(구버전 서버)은 지금처럼 열고 서버 422 에 맡긴다.
        if (claim.is_editable === false) {
            G7Core.state.setLocal({ claimEditMode: false, claimEditLoading: false });
            G7Core.toast?.warning?.(
                t(
                    'sirsoft-ecommerce.user.claim_apply.edit_not_editable',
                    '승인 이후에는 신청 내용을 수정할 수 없습니다. 변경이 필요하면 고객센터로 문의해 주세요.'
                )
            );

            const detailPath = String(action.params?.detailPath ?? '');

            if (detailPath) {
                await G7Core.dispatch?.({ handler: 'navigate', params: { path: detailPath } });
            }

            return;
        }

        G7Core.state.setLocal({
            claimEditSnapshot: claim,
            claimEditLoading: false,
            // 주문번호는 order 데이터소스의 주소 재료다. 이 값이 서기 전의 첫 호출은
            // 404 로 끝나고(claimApplyOrderMissing 이 그 404 를 정상 경로로 판정한다),
            // 여기서 값을 세운 뒤의 refetch 가 실제 주문을 읽는다.
            claimEditOrderNumber: claim.order_number ?? null,
            // 접수 때 고른 환불 우선순위를 되살린다 (S1-D13).
            //
            // 세우지 않으면 `_local.claimRefundPriority` 가 기본값 `pg_first` 로 남아,
            // **마일리지 우선으로 접수한 신청서의 수정 화면이 PG 우선으로 보인다** — 읽기 전용
            // 라벨도, 미리보기가 다시 계산하는 금액 분해(결제수단/마일리지 줄·잔여 마일리지)도
            // 전부 저장값과 어긋나고, 그 상태로 저장하면 분해가 조용히 뒤집힌다.
            // 응답의 `refund_priority` 가 정본이고, 그 필드가 없는 응답만 저장된 분해로 역산한다.
            claimRefundPriority: resolveRefundPriorityFromClaim(claim),
        });

        await G7Core.dispatch?.({
            handler: 'refetchDataSource',
            params: { dataSourceId: 'order' },
        });
    } catch (error: any) {
        logger.error('[initClaimEdit] 실패:', error);

        G7Core.state.setLocal({ claimEditLoading: false });

        // 열 수 없는 신청서(없음·남의 것·토큰 만료)는 오류 화면으로 끝낸다 — 빈 수정
        // 폼을 세우면 구매자가 채워 넣은 뒤 저장에서야 거절된다.
        await G7Core.dispatch?.({
            handler: 'showErrorPage',
            params: { errorCode: Number(error?.response?.status ?? error?.status ?? 404), target: 'content' },
        });
    }
}

/**
 * 접수된 신청서의 상품 행을 수정 화면의 상품 줄로 옮깁니다.
 *
 * 수정 화면의 품목·수량·교환 대상은 읽기 전용이다(D13) — 그래도 같은 형태로 만들어 두는
 * 것은 미리보기 재호출(재발송지 변경, D14)이 작성과 같은 페이로드를 쓰기 때문이다. 형태가
 * 갈리면 수정 화면에서만 미리보기 본문이 달라져 금액이 다르게 나온다.
 *
 * @param claim 신청서 응답
 * @param currency 주문 시점 기준 통화
 * @returns 상품 줄
 */
export function buildClaimEditItems(claim: any, currency: string | null): ClaimApplyItem[] {
    return (claim?.items ?? []).map((item: any) => {
        const quantity = Number(item.quantity ?? 0);

        return {
            order_option_id: Number(item.order_option_id ?? 0),
            product_id: item.product_id ?? null,
            product_name: item.product_name ?? '',
            product_option_name: item.product_option_name ?? '',
            thumbnail_url: item.thumbnail_url ?? '',
            unit_price: Number(item.unit_price ?? 0),
            unit_price_formatted: item.unit_price_formatted ?? '',
            // 이미 이 신청서가 잡아 둔 수량이다 — 남은 신청 가능 수량이 아니라 신청한 수량을
            // 상한으로 둔다(수정 화면에서 수량은 바꿀 수 없다).
            available_quantity: quantity,
            locked_quantity: 0,
            claimable_types: [String(claim?.claim_type ?? '')],
            refund_branch: null,
            selected: true,
            quantity,
            exchange_mode: item.is_option_change === true ? 'change' : 'same',
            new_product_option_id: item.new_product_option_id ?? null,
            new_option_label: item.new_option_label ?? '',
            // 수정 화면은 접수 때 동결된 이름을 그대로 되살린다 — 다시 해석하면 그 사이 운영자가
            // 선택지를 고쳤을 때 신청서에 남은 표기와 화면이 갈린다.
            new_additional_labels: (item.new_additional_options ?? []).map((entry: any) => {
                const text = String(entry?.custom_text ?? '').trim();

                return text !== '' ? `${entry?.name ?? ''} (${text})` : String(entry?.name ?? '');
            }).filter((label: string) => label !== ''),
            custom_options: item.new_option_snapshot?.custom_options ?? null,
            price_difference: Number(item.price_difference ?? 0),
            // 부호 없는 절대값으로 포맷한다 — 화면이 차액 방향(`+`/`-`)을 따로 붙인다. 서버의
            // `price_difference_formatted` 는 부호를 품으므로(`-5,000원`) 그대로 받으면 `--5,000원` 이
            // 된다(#599 validate 2026-09-23). 작성 화면의 미리보기 경로도 같은 규칙이다.
            price_difference_formatted: formatAmountInCurrency(Math.abs(Number(item.price_difference ?? 0)), currency),
            // 단가 줄의 보조 통화는 **단가**에서 온다. 예전에는 여기에 `mc_price_difference` 를
            // 실어, 수정 화면의 `28,000원` 줄에 교환 차액인 `$-3.00` 이 붙었다(S3-D6 실측).
            // 이름은 단가인데 값이 차액이라 서식은 정상이고 금액만 틀려 흔적이 남지 않는다.
            mc_unit_price: toSecondaryCurrencyList(item.mc_unit_price, currency),
            validation_error: '',
        } as ClaimApplyItem;
    });
}

/**
 * 접수된 신청서의 첨부를 수정 화면의 첨부 줄로 옮깁니다.
 *
 * `attached: true` 를 달아 둔다 — 이미 신청서에 귀속된 첨부는 그 자리에서 지우지 않고
 * 저장할 때 `remove_image_ids` 로 넘긴다. 바로 지우면 저장을 취소하고 나가도 첨부만
 * 영구히 사라지고, 서버의 장수 상한 판정(삭제 후 남을 장수)도 화면과 갈린다.
 *
 * `removable` 은 **고객이 올린** 첨부에만 참이다. 운영자가 상세에서 대리로 올린 사진과 주체
 * 미상 사진은 "누가 낸 증거인가" 를 가르는 기록이라 서버도 고객 수정으로는 지우지 않는다
 * (`claim_image_not_removable`). 화면이 삭제 버튼을 보여 주면 저장이 통째로 422 가 된다.
 *
 * @param claim 신청서 응답
 * @returns 첨부 줄
 */
export function buildClaimEditImages(claim: any): Array<Record<string, any>> {
    return (claim?.images ?? []).map((image: any) => ({
        id: Number(image.id ?? 0),
        hash: image.hash ?? '',
        original_name: image.original_name ?? '',
        download_url: image.download_url ?? '',
        attached: true,
        removable: image.uploader_type === 'customer',
        uploader_type_label: image.uploader_type_label ?? null,
    }));
}

/**
 * 취소·환불 신청서에서 지금 선택된 상품이 속한 분기를 돌려줍니다. (`auto` | `request` | null)
 *
 * 취소·환불은 한 화면이고 서버도 **한 파이프라인**이다 — 두 분기 모두 환불신청서를 만든다.
 * 다른 것은 전이 방식뿐이다: `auto` 는 승인·완료까지 자동으로 밀고(결제완료·미결제 상품),
 * `request` 는 접수에서 멈춰 운영자 승인을 기다린다(배송준비 이후 상품).
 *
 * 그래도 두 분기의 상품을 한 신청서에 섞으면 서버가 422(`claim_refund_branch_mixed`)로
 * 거절한다 — 한쪽은 바로 환불되고 다른 쪽은 심사를 기다리므로, 고객이 보는 환불 예정액과
 * 실제 환불액이 갈리기 때문이다.
 *
 * 분기는 **선택된 상품**으로 정해진다. 선택이 없으면 아직 정해지지 않은 것이므로 null 이다.
 *
 * @param items 신청 상품 줄
 * @returns 분기 (선택 없음 → null, 두 분기가 섞임 → 'mixed')
 */
export function resolveSelectedRefundBranch(items: ClaimApplyItem[]): string | null {
    const branches = Array.from(
        new Set(
            items
                .filter((item) => item.selected && !!item.refund_branch)
                .map((item) => String(item.refund_branch))
        )
    );

    if (branches.length === 0) return null;

    return branches.length === 1 ? branches[0] : 'mixed';
}

/**
 * 접수 응답을 보고 이동할 신청내역 탭 주소를 만듭니다. (F8 — 서버 `branch` 가 정본)
 *
 * 화면이 만든 `successPath` 는 주문 전체 기준 추측값이라 혼재 주문에서 접수한 탭과 어긋난다.
 * 응답에 실린 신청서의 `claim_type` 이 "무엇이 접수됐는가" 의 정본이므로, 그 값이 있으면
 * 쿼리의 `type` 을 그것으로 덮어쓴다. 응답에 없으면(구형 서버·다른 유형) 원 주소를 그대로 쓴다.
 *
 * @param fallbackPath 화면이 만든 이동 주소
 * @param data 접수 응답의 `data` (branch / claim)
 * @param detailPathBase 신청서 상세 주소의 앞부분 (셸이 준다 — 있으면 1순위)
 * @returns 이동할 주소 (만들 수 없으면 원 주소)
 */
export function resolveClaimSuccessPath(fallbackPath: unknown, data: any, detailPathBase?: unknown): string {
    const path = typeof fallbackPath === 'string' ? fallbackPath : '';

    // 1순위 — 방금 만든 신청서 상세. 서버가 신청번호를 실어 준다(F8).
    //
    // 목록으로 보내면 구매자는 방금 낸 신청서를 스스로 찾아야 하고, 유형 탭이 어긋나면 아예
    // 보이지 않는다. 접수 결과가 어느 문서인지는 서버만 알므로 그 번호를 그대로 따른다.
    const detailBase = typeof detailPathBase === 'string' ? detailPathBase.replace(/\/+$/, '') : '';
    const claimNumber = data?.claim?.claim_number;

    if (detailBase && typeof claimNumber === 'string' && claimNumber !== '') {
        return `${detailBase}/${claimNumber}`;
    }

    // 2순위 — 신청번호를 받지 못했을 때의 대비책. 유형 서브탭을 맞춰 목록으로 보낸다.
    const claimType = data?.claim?.claim_type;

    if (!path || typeof claimType !== 'string' || claimType === '') return path;

    const [base, query = ''] = path.split('?');
    const params = new URLSearchParams(query);
    params.set('type', claimType);

    return `${base}?${params.toString()}`;
}

/**
 * 이 상품을 지금 고를 수 있는지 판정합니다. (취소·환불의 분기 혼재 차단)
 *
 * 이미 고른 상품과 분기가 다르면 서버가 신청 전체를 거절한다. 화면이 그 조합을 만들 수 있게
 * 두면 구매자는 사유까지 다 채운 뒤에야 "나누어 신청해 주세요" 를 받는다 — 미리보기도 같은
 * 이유로 실패하므로 금액조차 볼 수 없다. 고를 수 없는 상품은 애초에 잠근다.
 *
 * 교환·반품은 분기가 없는 유형이라 언제나 고를 수 있다.
 *
 * @param items 신청 상품 줄
 * @param index 판정 대상 줄 번호
 * @param claimType 클레임 유형
 * @returns 지금 고를 수 있으면 true
 */
export function canSelectClaimItem(items: ClaimApplyItem[], index: number, claimType: string): boolean {
    const target = items[index];

    if (!target) return false;
    // 이미 고른 상품은 언제나 해제할 수 있다 — 잠그면 잘못 고른 조합에서 빠져나올 수 없다.
    if (target.selected) return true;
    if (claimType !== 'refund') return true;

    const current = resolveSelectedRefundBranch(items);

    return current === null || current === target.refund_branch;
}

/**
 * 서버가 실어 준 등록 환불계좌를 입력칸 초기값 형태로 정규화합니다.
 *
 * 세 값이 모두 있을 때만 채운다 — 서버는 "셋 다 오거나 하나도 오지 않거나" 만 허용하므로,
 * 반쯤 채운 폼을 열면 구매자가 나머지를 채우기 전까지 제출이 거절된다. 서버는 이미 그 조건으로
 * 걸러 내지만, 화면도 같은 규칙을 갖는다 — 한쪽만 지키면 응답 형태가 바뀔 때 조용히 갈린다.
 *
 * @param prefill 서버 응답의 `refund_account.prefill`
 * @returns 입력칸 초기값 세 개 (없으면 빈 문자열)
 */
export function normalizeRefundAccountPrefill(
    prefill: any
): { bank_code: string; account_number: string; holder: string } {
    const empty = { bank_code: '', account_number: '', holder: '' };

    if (!prefill || typeof prefill !== 'object') return empty;

    const bankCode = String(prefill.bank_code ?? '').trim();
    const accountNumber = String(prefill.account_number ?? '').trim();
    const holder = String(prefill.holder ?? '').trim();

    if (!bankCode || !accountNumber || !holder) return empty;

    return { bank_code: bankCode, account_number: accountNumber, holder };
}

/**
 * 원 배송지를 재발송지 페이로드 형태로 옮깁니다. (D14)
 *
 * 주문 배송지 리소스(`OrderAddressResource`)와 재발송지 페이로드는 필드 이름이 다르다
 * (`recipient_phone` ↔ `phone`, `address`/`address_detail` ↔ `address1`/`address2`).
 * 이름이 어긋난 채 넘기면 서버는 빈 값으로 받아 원 배송지와 같은 주소로 재발송된다.
 *
 * 해외 주문은 국내 칸이 빈 문자열로 오므로 `??` 가 아니라 `||` 로 해외 칸(`intl_postal_code`·
 * `address_line_1/2`)에 넘어가고, 도시·주는 `city`·`state` 로 옮긴다 — 서버 스냅샷과 같은 규칙이다.
 *
 * @param G7Core G7Core 인스턴스
 * @returns 재발송지 편집 버퍼 초기값
 */
export function orderShippingAddressAsRedelivery(G7Core: any): Record<string, string> {
    const address = G7Core.dataSource?.get?.('order')?.data?.shipping_address ?? {};

    return {
        recipient_name: address.recipient_name ?? '',
        phone: address.recipient_phone ?? '',
        zipcode: address.zipcode || address.intl_postal_code || '',
        address1: address.address || address.address_line_1 || '',
        address2: address.address_detail || address.address_line_2 || '',
        city: address.intl_city || '',
        state: address.intl_state || '',
        country_code: address.recipient_country_code ?? '',
    };
}

/**
 * 사유 카탈로그에서 귀책 구분을 찾습니다.
 *
 * @param G7Core G7Core 인스턴스
 * @param claimType 클레임 유형
 * @param reasonCode 사유 코드
 * @returns 귀책 구분 (없으면 null)
 */
function resolveFaultType(G7Core: any, claimType: string, reasonCode: string): string | null {
    const reasons = claimableData(G7Core)?.reasons?.[claimType] ?? [];
    const matched = reasons.find((reason: any) => reason.code === reasonCode);

    return matched?.fault_type ?? null;
}

/**
 * 신청 대상(선택된 상품)만 서버 페이로드 형태로 만듭니다.
 *
 * @param items 작성 중인 상품 목록
 * @returns 서버 items 페이로드
 */
export function buildClaimItemsPayload(items: ClaimApplyItem[]): Array<Record<string, any>> {
    return (items ?? [])
        .filter((item) => item.selected)
        .map((item) => {
            const payload: Record<string, any> = {
                order_option_id: item.order_option_id,
                quantity: item.quantity,
            };

            if (item.exchange_mode === 'change' && item.new_product_option_id) {
                payload.new_product_option_id = item.new_product_option_id;
            }

            if (item.custom_options && Object.keys(item.custom_options).length > 0) {
                payload.custom_options = item.custom_options;
            }

            return payload;
        });
}

/**
 * 통화별 금액 맵을 화면이 그릴 수 있는 배열로 폅니다. (기준통화 제외)
 *
 * 이 모듈의 다통화 값은 **출처마다 형태가 다르다**:
 *  - 미리보기(`mc_total_charge_amount` · `shipping_fee.mc_fee`) → `{통화코드: 숫자}`
 *  - 주문 리소스(`mc_unit_price` 등 `formatStoredMultiCurrency` 산물) → `{통화코드: {amount, formatted}}`
 *  - 정산 표기(`adjustment.refund_formatted.mc.*`) → `{통화코드: 포맷된 문자열}`
 *
 * 그래서 화면이 한 형태를 가정하고 `.formatted` 로 읽으면 다른 형태에서는 오류 없이
 * **빈 문자열**이 되어 그 줄이 통째로 사라진다(2026-08-31 3차 계획 검증 실측).
 * 세 형태를 여기서 하나로 흡수하고, 화면은 `formatted` 만 읽는다.
 *
 * 미리보기는 `*_formatted` 를 주지 않으므로(판정③) 숫자는 그 통화로 여기서 포맷한다.
 *
 * @param amounts 통화코드 → 금액(숫자 · 포맷 문자열 · `{amount, formatted}` 중 하나)
 * @param baseCurrency 기준통화 코드 (이 통화는 본문에 이미 표기되므로 제외)
 * @returns 보조통화 표기 배열
 */
export function toSecondaryCurrencyList(
    amounts: unknown,
    baseCurrency: string | null
): SecondaryCurrencyAmount[] {
    if (!amounts || typeof amounts !== 'object' || Array.isArray(amounts)) {
        return [];
    }

    return Object.entries(amounts as Record<string, any>)
        .filter(([code]) => code !== baseCurrency)
        .map(([code, value]) => {
            if (typeof value === 'string') {
                return { code, formatted: value };
            }

            if (value && typeof value === 'object' && typeof value.formatted === 'string') {
                return { code, formatted: value.formatted };
            }

            return { code, formatted: formatAmountInCurrency(Number(value ?? 0), code) };
        })
        .filter((entry) => entry.formatted !== '');
}

/**
 * 제출 거절(422)의 항목별 오류를 그 상품 줄에 붙입니다.
 *
 * 서버 키는 `items.{순번}.{필드}` 인데 그 순번은 **제출 페이로드의 순번**(선택된 행만
 * 실린다)이라 화면의 행 번호와 다르다. 그 대응을 풀지 않으면 오류 본문이 어디에도
 * 그려지지 않고 요약 카드에는 `message` 만 남는다(§6-3 P13 "필드 오류는 그 필드 옆에").
 *
 * @param items 현재 신청 상품 줄
 * @param errors 서버가 준 필드 오류 맵 (없으면 전 줄의 오류를 지운다)
 * @returns 오류가 반영된 상품 줄
 */
export function attachClaimItemErrors(
    items: ClaimApplyItem[],
    errors: Record<string, any> | null | undefined
): ClaimApplyItem[] {
    const payloadOrder = items.filter((item) => item.selected).map((item) => item.order_option_id);

    return items.map((item) => {
        const payloadIndex = payloadOrder.indexOf(item.order_option_id);
        let message = '';

        if (payloadIndex >= 0 && errors) {
            const raw =
                errors['items.' + payloadIndex + '.quantity']
                ?? errors['items.' + payloadIndex + '.new_product_option_id']
                ?? errors['items.' + payloadIndex + '.order_option_id'];

            message = Array.isArray(raw) ? String(raw[0] ?? '') : String(raw ?? '');
        }

        return item.validation_error === message ? item : { ...item, validation_error: message };
    });
}

/**
 * 필드 오류를 화면의 표시 자리별로 묶습니다.
 *
 * 제출 거절(422)의 필드 오류는 그 필드 옆에 남긴다(§6-3 P13). 사유·상세사유는 자기 키를
 * 그대로 읽고 상품 줄은 순번으로 매핑하지만, 첨부(`image_ids`·`image_ids.*`)와 재발송지
 * (`redelivery_address.*` 7키)는 서버가 여러 키로 쪼개 돌려준다. 화면이 그 키를 하나씩
 * 열거하면 서버가 키를 더하거나 이름을 바꿀 때 그 사유만 조용히 사라지고, 구매자에게는
 * 요약 카드의 첫 줄만 남아 어느 칸이 막혔는지 알 수 없다. 접두사로 묶어 그 자리에 남긴다.
 *
 * @param errors 서버 422 의 `errors` 페이로드
 * @return 표시 자리별 첫 사유 (없으면 빈 문자열)
 */
export function collectClaimFieldErrors(
    errors: Record<string, any> | null | undefined
): Record<string, string> {
    const first = (prefixes: string[]): string => {
        if (!errors) return '';

        for (const key of Object.keys(errors)) {
            if (!prefixes.some((prefix) => key === prefix || key.startsWith(prefix + '.'))) {
                continue;
            }

            const raw = errors[key];
            const message = Array.isArray(raw) ? String(raw[0] ?? '') : String(raw ?? '');

            if (message) return message;
        }

        return '';
    };

    return {
        charge_method: first(['charge_method']),
        payment_method: first(['payment_method', 'refund_priority']),
        images: first(['image_ids']),
        redelivery: first(['redelivery_address']),
        // 환불계좌는 세 필드가 함께 와야 하므로(`StoreRefundRequestRequest::withValidator`) 서버가
        // 묶음 키(`refund_bank`)와 개별 키를 모두 돌려줄 수 있다. 접두사로 묶어 그 카드 아래 남긴다.
        refund_bank: first(['refund_bank']),
    };
}

/**
 * 필드 자리에 배치되지 않은 사유만 골라 냅니다.
 *
 * 요약 카드의 제출 오류 블록은 **필드에 매이지 않는 사유**만 맡는다(§6-3 P13). 서버가 주는
 * 최상위 `message` 는 첫 오류 메시지라, 그것을 그대로 요약에 두면 이미 그 칸 옆에 선 사유가
 * 화면에 두 번 나온다 — 2026-08-31 실측에서 청구 방식 거절이 축1 아래와 요약 카드에 나란히
 * 떴다. 어느 쪽을 고쳐야 하는지 흐려지므로 배치된 사유는 요약에서 뺀다.
 *
 * 상품 줄은 순번 매핑에 성공한 키만 배치된 것으로 본다 — 매핑하지 않는 키(`custom_options`)는
 * 어디에도 서지 않으므로 요약이 받아야 한다.
 *
 * @param errors 서버 422 의 `errors` 페이로드
 * @param items 순번 매핑 대상 상품 줄 (제출 페이로드와 같은 순서)
 * @return 배치되지 않은 첫 사유 (전부 배치됐으면 빈 문자열)
 */
export function unplacedClaimErrorMessage(
    errors: Record<string, any> | null | undefined,
    items: ClaimApplyItem[]
): string {
    if (!errors) return '';

    const placedPrefixes = [
        'reason_code',
        'reason_detail',
        'charge_method',
        'payment_method',
        'refund_priority',
        'image_ids',
        'redelivery_address',
        'refund_bank',
    ];
    const mappedItemFields = ['quantity', 'new_product_option_id', 'order_option_id'];
    const payloadLength = items.filter((item) => item.selected).length;

    const isPlaced = (key: string): boolean => {
        if (placedPrefixes.some((prefix) => key === prefix || key.startsWith(prefix + '.'))) {
            return true;
        }

        const matched = key.match(/^items\.(\d+)\.(.+)$/);

        return !!matched
            && Number(matched[1]) < payloadLength
            && mappedItemFields.includes(matched[2]);
    };

    for (const key of Object.keys(errors)) {
        if (isPlaced(key)) continue;

        const raw = errors[key];
        const message = Array.isArray(raw) ? String(raw[0] ?? '') : String(raw ?? '');

        if (message) return message;
    }

    return '';
}

/**
 * 미리보기 응답의 금액을 주문 통화 표기 문자열로 만듭니다.
 *
 * 미리보기 응답은 숫자만 준다(`*_formatted` 없음). 화면이 `금액.toLocaleString() + '원'`
 * 으로 단위를 이어 붙이면 기본 통화가 원화가 아닌 상점에서 값은 맞고 단위만 틀린 금액이
 * 나가므로, 설정의 기호·소수 자릿수를 따르는 공용 포맷터를 쓴다.
 *
 * `shipping_groups` 는 배송비 합계의 **산출 근거**다(§6-3). 서버가 배송정책 그룹마다
 * `base_fee`(편도 단가) · `multiplier`(교환 2 · 반품 1) · `extra_fee`(도서산간)를 내려주므로
 * 화면은 그 값을 옮기기만 한다 — 화면이 스스로 곱셈을 하면 합계(서버)와 근거(화면)의 출처가
 * 갈려, 훅이 금액을 덮어쓴 상점에서 둘이 어긋나도 어느 쪽이 맞는지 알 수 없게 된다.
 *
 * `decomposable` 은 **`original_policy` 모드에서만** 참이다. `fixed` 는 운영자가 정책 화면에서
 * 유형별 **총액**을 직접 선언한 값이라 서버도 배수를 곱하지 않으므로(D11), 그 값을 "편도 × N"
 * 으로 적으면 선언액을 단가로 잘못 부르게 된다 — 교환인데 "× 1회" 로 보여 합계 라벨(왕복)과
 * 어긋난다(2026-08-31 실측). 분해가 성립하지 않는 모드에서는 근거 줄을 세우지 않는다.
 *
 * @param estimate 미리보기 응답 payload
 * @param currencyCode 주문 시점 기준 통화
 * @returns 표기 문자열 맵 (`shipping_groups` 만 배열)
 */
export function formatClaimEstimate(estimate: any, currencyCode: string | null): Record<string, any> {
    const fmt = (value: unknown): string => formatAmountInCurrency(Number(value ?? 0), currencyCode);

    return {
        shipping_fee: fmt(estimate?.shipping_fee?.fee),
        shipping_extra_fee: fmt(estimate?.shipping_fee?.extra_fee),
        shipping_groups: ((estimate?.shipping_fee?.groups ?? []) as any[])
            .filter((group: any) => group?.mode === 'original_policy')
            .map((group: any) => ({
                base_fee: fmt(group?.base_fee),
                multiplier: Number(group?.multiplier ?? 1),
                extra_fee: fmt(group?.extra_fee),
                has_extra_fee: Number(group?.extra_fee ?? 0) > 0,
            })),
        price_difference: fmt(Math.abs(Number(estimate?.price_difference ?? 0))),
        additional_product_amount: fmt(estimate?.additional_product_amount),
        refund_product_amount: fmt(estimate?.refund_product_amount),
        total_charge_amount: fmt(estimate?.total_charge_amount),
        refund_total: fmt(estimate?.adjustment?.refund_total),
        // 반품 요약 3줄(§6-3) — 회수비를 환불금에서 차감하는 방식은 `adjustment.refund_total` 이
        // 이미 차감된 뒤의 금액이라, 차감 전 금액과 차감액을 서버가 따로 낸다. 화면이 되더하면
        // 예고와 청구의 근거가 갈린다.
        refund_before_deduction: fmt(estimate?.refund_before_deduction),
        deducted_shipping_fee: fmt(estimate?.deducted_shipping_fee),
        // 보조통화 표기 — 세 줄 모두 서버가 재료를 준다(§5-4 "다통화는 전부 mc_* 규약").
        // 환율은 서버가 주문 통화 스냅샷의 동결 환율로 낸 것을 그대로 쓴다 — 화면이 환산하면
        // 예고와 청구의 근거가 갈린다.
        mc_total_charge_amount: toSecondaryCurrencyList(estimate?.mc_total_charge_amount, currencyCode),
        mc_shipping_fee: toSecondaryCurrencyList(estimate?.shipping_fee?.mc_fee, currencyCode),
        mc_refund_total: toSecondaryCurrencyList(
            estimate?.adjustment?.refund_formatted?.mc?.refund_total,
            currencyCode
        ),
        mc_refund_before_deduction: toSecondaryCurrencyList(estimate?.mc_refund_before_deduction, currencyCode),
        // 차액·차감 줄도 형제 금액 줄과 같은 규약이다(S17 트랙 A F-1). 이 세 줄이 비면 요약 카드
        // 안에서 어떤 줄은 보조통화를 달고 어떤 줄은 기준 통화만 남아, 같은 금액이 화면마다 다르게
        // 보인다 — 상세는 이미 `mc_price_difference` 로 병기하고 있었다.
        mc_price_difference: toSecondaryCurrencyList(estimate?.mc_price_difference, currencyCode),
        mc_additional_product_amount: toSecondaryCurrencyList(estimate?.mc_additional_product_amount, currencyCode),
        mc_refund_product_amount: toSecondaryCurrencyList(estimate?.mc_refund_product_amount, currencyCode),
        mc_deducted_shipping_fee: toSecondaryCurrencyList(estimate?.mc_deducted_shipping_fee, currencyCode),
        // 주문금액 비교 섹션(#599 후속) — 환불 요약 4줄과 치환 근거 행. 스냅샷 행은 여기서 포맷하지
        // 않는다(partial 이 서버 `formatted` 를 직독한다). 환불 총액·잔액은 서버 `refund_formatted.base.*`
        // 를 그대로 옮기고, 마일리지 환불분만 서버 포맷이 없어 같은 통화로 포맷한다(기준 통화 정수 — 환산 없음).
        refund_cash: estimate?.adjustment?.refund_formatted?.base?.refund_amount ?? fmt(estimate?.adjustment?.refund_amount),
        refund_points: fmt(estimate?.adjustment?.refund_points_amount),
        remaining_pg_balance: estimate?.adjustment?.refund_formatted?.base?.remaining_pg_balance
            ?? fmt(estimate?.adjustment?.remaining_pg_balance),
        remaining_points_balance: estimate?.adjustment?.refund_formatted?.base?.remaining_points_balance
            ?? fmt(estimate?.adjustment?.remaining_points_balance),
        mc_refund_cash: toSecondaryCurrencyList(estimate?.adjustment?.refund_formatted?.mc?.refund_amount, currencyCode),
        mc_refund_points: toSecondaryCurrencyList(estimate?.adjustment?.mc_refund_points_amount, currencyCode),
        mc_remaining_pg_balance: toSecondaryCurrencyList(estimate?.adjustment?.refund_formatted?.mc?.remaining_pg_balance, currencyCode),
        mc_remaining_points_balance: toSecondaryCurrencyList(estimate?.adjustment?.refund_formatted?.mc?.remaining_points_balance, currencyCode),
        // 교환의 치환 근거 행(상품별 old→new) — 단가는 서버가 raw 로 주므로 상품 줄 단가와 같은 통화로 포맷한다.
        substituted_items: ((estimate?.adjustment?.substituted_items ?? []) as any[]).map((row: any) => ({
            ...row,
            unit_price_formatted: fmt(row?.unit_price),
            new_unit_price_formatted: fmt(row?.new_unit_price),
        })),
    };
}

/**
 * 비교 섹션의 기본 펼침 상태를 정합니다. — PC 펼침 · 모바일(portable 0~1023px) 접힘 (P6)
 *
 * `_global.isPortable` 은 어느 런타임도 세우지 않는 값이라 쓰지 않는다. ResponsiveManager 가 없는
 * 하네스에서는 matchMedia 로, 그것도 없으면 펼침으로 떨어진다.
 *
 * @param G7Core G7Core 인스턴스
 * @returns 펼침이면 true
 */
export function defaultClaimComparisonOpen(G7Core: any): boolean {
    const manager = G7Core?.ResponsiveManager ?? (window as any).G7Core?.ResponsiveManager;

    if (manager && typeof manager.matches === 'function') {
        return !manager.matches('portable');
    }

    const media = typeof window !== 'undefined' && typeof window.matchMedia === 'function'
        ? window.matchMedia('(max-width: 1023px)')
        : null;

    return !(media?.matches === true);
}

/**
 * 수정 화면의 로컬 상태를 신청서에서 만듭니다.
 *
 * 작성 초기화(`initClaimApplyHandler`)와 **키 집합이 같아야 한다** — 공유 partial 이 그 키를
 * 읽으므로, 한쪽에만 있는 키가 생기면 그 화면에서만 조용히 빈 칸이 된다.
 *
 * @param G7Core G7Core 인스턴스
 * @param claim 신청서 응답
 * @param claimable `claimable-options` 응답 (사유 카탈로그·설정)
 * @param claimType 클레임 유형
 */
function seedClaimEditState(G7Core: any, claim: any, claimable: any, claimType: string): void {
    const currency = orderCurrencyCode(G7Core, claimable);
    const items = buildClaimEditItems(claim, currency);

    G7Core.state.setLocal({
        claimType,
        claimableSnapshot: claimable,
        claimItems: items,
        claimSelectAll: false,
        claimResolvedBranch: null,
        claimReason: claim.reason_code ?? '',
        claimReasonDetail: claim.reason_detail ?? '',
        claimFaultType: claim.fault_type ?? null,
        claimImages: buildClaimEditImages(claim),
        claimImageFiles: [],
        // 이미 귀속된 첨부의 삭제는 저장할 때 한꺼번에 넘긴다 — 그 자리에서 지우면 저장을
        // 취소하고 나가도 첨부만 사라진다.
        claimRemoveImageIds: [],
        claimChargeMethod: claim.charge_method ?? '',
        claimPaymentMethod: '',
        // 접수 때 고른 환불 우선순위를 되살린다 (S1-D13) — `pg_first` 로 고정하면 마일리지
        // 우선으로 접수한 신청서의 수정 화면이 PG 우선으로 보이고, 읽기 전용 라벨도 미리보기
        // 금액 분해(결제수단/마일리지 줄·잔여 마일리지)도 저장값과 어긋난다.
        claimRefundPriority: resolveRefundPriorityFromClaim(claim),
        claimComparisonOpen: defaultClaimComparisonOpen(G7Core),
        // 환불계좌는 이 신청서 행의 값이며 서버가 마스킹해 내려준다 — 수정 대상이 아니다
        // (D13 이 여는 것은 사유·상세 사유·첨부와 재발송지뿐이다).
        claimRefundBankCode: '',
        claimRefundBankAccount: '',
        claimRefundBankHolder: '',
        claimRefundAccountMasked: false,
        // 재발송지는 신청서에 동결된 값에서 출발한다 — 원 배송지로 되돌리면 구매자가 이미
        // 바꿔 둔 주소가 화면에서만 사라지고, 저장하면 그 주소로 되돌아간다.
        claimRedelivery: claim.redelivery_address_snapshot ?? null,
        claimRedeliveryEditing: false,
        claimRedeliveryReset: false,
        claimRedeliveryTouched: false,
        // 접수 시점의 청구액 — 재발송지를 바꿔 배송비가 다시 계산되면 이 값과의 차액이
        // 추가 청구되거나 환불된다(D14). 그 차액을 화면이 드러내려면 기준값이 필요하다.
        claimEditOriginalCharge: Number(claim.total_charge_amount ?? 0),
        claimEditChargeDelta: 0,
        claimEditChargeDeltaFormatted: '',
        claimEditSaving: false,
        claimEditError: null,
        claimEstimate: null,
        claimEstimateFormatted: null,
        claimEstimateLoading: false,
        claimEstimateError: null,
        claimSubmitError: null,
        claimValidationErrors: null,
        claimFieldErrors: null,
        isSubmittingClaim: false,
        changeProductTarget: null,
        changeProductMode: 'same',
        changeProductProduct: null,
        changeProductSelections: null,
        changeProductMatched: null,
        changeProductAdditionalSelections: null,
        changeProductAdditionalTexts: null,
    });
}

/**
 * 신청서 작성 화면 초기화 핸들러
 *
 * `claimable-options` 응답으로 상품 줄을 세우고, 주문상세에서 체크해 온 상품
 * (`query.options`)을 미리 선택한다. 청구 2축의 기본값도 운영자 허용 목록의 첫 값으로
 * 정한다 — 화면이 리터럴 기본값을 들고 있으면 운영자가 그 방식을 껐을 때 제출이 422 가 된다.
 *
 * 페이로드는 **응답 컨텍스트에서 직접 받는다**. 이 핸들러는 `claimable` 데이터소스의
 * onSuccess 로 실행되는데, 그 시점의 `G7Core.dataSource.get('claimable')` 은 아직 비어 있다 —
 * TemplateApp 의 상태 갱신이 refetch 해소 뒤에 일어나기 때문이다. 데이터소스에서 읽으면
 * 응답은 200 인데 화면만 빈 신청서로 남고 오류는 어디에도 남지 않는다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.initClaimApply",
 *   "params": {
 *     "claimType": "exchange",
 *     "preselected": "{{query.options ?? ''}}",
 *     "claimable": "{{response.data.data}}"
 *   }
 * }
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트 (미리보기 debounce 콜백의 상태 반영 대상)
 */
export function initClaimApplyHandler(
    action: ActionWithParams<{ claimType?: string; preselected?: string; claimable?: any }>,
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const fromRoute = resolveClaimApplyRoute();
    const editSnapshot = G7Core.state.getLocal?.()?.claimEditSnapshot ?? null;
    // 수정 진입은 주소에 유형이 없다 — 유형은 신청서가 알려 준다(서버가 번호로 해석한 값).
    const claimType = String(
        action.params?.claimType || fromRoute.claimType || (editSnapshot?.claim_type ?? '')
    );
    const claimable = action.params?.claimable ?? claimableData(G7Core);

    if (!claimType || !claimable) return;

    // 수정 화면 — 상품·수량·교환 대상은 신청서가 정하고(읽기 전용) 사유·상세 사유·첨부와
    // 교환 재발송지만 바꾼다(D13/D14). 작성 화면의 상품 목록(claimable.options)은 이 신청서가
    // 이미 잡아 둔 수량을 신청 가능분에서 빼고 내려주므로, 그것으로 줄을 만들면 수정 화면에
    // 정작 신청한 상품이 하나도 서지 않는다.
    if (editSnapshot) {
        seedClaimEditState(G7Core, editSnapshot, claimable, claimType);

        // #599 후속(1.2.0) — 수정 진입의 첫 미리보기. 작성 화면은 상품·사유를 고르는 순간 미리보기가
        // 돌지만, 수정 화면은 상품·사유가 이미 채워져 들어오므로 구매자가 무언가 바꾸기 전에는 요약과
        // 주문금액 비교가 "계산됩니다" 자리표시에 머문다(2026-09-11 브라우저 실측). 기준 경로는 셸이
        // _local.claimApiBase 로 세운 값이다 — 레이아웃 onSuccess 파라미터의 `{{_local.…}}` 은 그 시점에
        // 비어 도착해 쓸 수 없었다.
        const seeded = G7Core.state.getLocal?.() ?? {};
        scheduleClaimEstimate(G7Core, {
            claimApiBase: String(seeded.claimApiBase ?? ''),
            isGuest: seeded.claimIsGuest === true,
        }, context);

        return;
    }

    const preselected = String(action.params?.preselected || fromRoute.preselected || '')
        .split(',')
        .map((value) => Number(value))
        .filter((value) => value > 0);

    const currency = orderCurrencyCode(G7Core, claimable);

    const items: ClaimApplyItem[] = (claimable.options ?? [])
        .filter((entry: any) => (entry.claimable_types ?? []).includes(claimType))
        .map((entry: any) => {
            const option = entry.option ?? {};
            const available = Number(entry.available_quantity ?? 0);

            return {
                order_option_id: Number(option.id ?? 0),
                product_id: option.product_id ?? null,
                product_name: option.product_name ?? '',
                product_option_name: option.product_option_name ?? '',
                thumbnail_url: option.thumbnail_url ?? '',
                unit_price: Number(option.unit_price ?? 0),
                unit_price_formatted: option.unit_price_formatted ?? '',
                available_quantity: available,
                locked_quantity: Number(entry.locked_quantity ?? 0),
                claimable_types: entry.claimable_types ?? [],
                refund_branch: entry.refund_branch ?? null,
                selected: preselected.includes(Number(option.id ?? 0)),
                // 신청 가능한 잔여 수량을 처음부터 다 담는다 — 취소·반품은 「고른 상품을 통째로」 가
                // 대부분이라 1 로 두면 구매자가 줄마다 수량을 다시 올려야 한다(2026-09-21 요구사항).
                // 줄여 신청하는 경우는 입력칸에서 내리면 되고, 상한은 그대로 available_quantity 다.
                quantity: available > 0 ? available : 1,
                exchange_mode: 'same',
                new_product_option_id: null,
                new_option_label: '',
                new_additional_labels: [],
                custom_options: null,
                price_difference: 0,
                price_difference_formatted: formatAmountInCurrency(0, currency),
                mc_unit_price: toSecondaryCurrencyList(option.mc_unit_price, currency),
                validation_error: '',
            } as ClaimApplyItem;
        });

    const settings = claimable.settings ?? {};
    const allowedCharge: string[] = settings.allowed_charge_methods ?? [];
    const allowedPayment: string[] = settings.charge_payment_methods ?? [];
    const refundPrefill = normalizeRefundAccountPrefill(claimable.refund_account?.prefill);

    G7Core.state.setLocal({
        claimType,
        claimableSnapshot: claimable,
        claimItems: items,
        claimSelectAll: items.length > 0 && items.every((item) => item.selected),
        // 취소·환불의 화면 라벨은 **지금 고른 상품**의 분기를 따른다. 서버의 `resolved_type` 은
        // 주문 전체의 판정이라 두 분기가 섞인 주문에서는 'mixed' 이고, 그 값으로 라벨을 정하면
        // 구매자가 결제완료 상품만 골라 즉시 취소되는데도 화면은 "환불 신청" 이라고 부른다.
        claimResolvedBranch: resolveSelectedRefundBranch(items),
        claimReason: '',
        claimReasonDetail: '',
        claimFaultType: null,
        claimImages: claimable.pending_images ?? [],
        claimImageFiles: [],
        claimChargeMethod: allowedCharge[0] ?? '',
        claimPaymentMethod: allowedPayment[0] ?? '',
        claimRefundPriority: 'pg_first',
        // 주문금액 비교 섹션 — PC 펼침 · 모바일 접힘(P6). 토글 뒤에는 화면을 떠날 때까지 유지된다.
        claimComparisonOpen: defaultClaimComparisonOpen(G7Core),
        // 환불계좌는 주문 시 등록해 둔 계좌가 있으면 채워서 연다(2026-08-31 확정).
        // 채우는 값은 서버가 `refund_account.prefill` 로 실어 준 **원문**이며, 표시용 응답의
        // 마스킹 값(계좌 뒤 4자리·예금주 첫 글자)을 재사용하지 않는다 — 마스킹 값을 채우면
        // 구매자가 손대지 않고 제출했을 때 별표가 섞인 계좌번호가 저장되고, 검증은 통과해
        // 환불 실행 때가 되어서야 드러난다. 등록분이 없으면 세 칸 모두 빈 상태다.
        claimRefundBankCode: refundPrefill.bank_code,
        claimRefundBankAccount: refundPrefill.account_number,
        claimRefundBankHolder: refundPrefill.holder,
        // 서버는 등록 계좌를 **가린 모양**으로 실어 준다(`masked: true` — 계좌 뒤 4자리·예금주 첫 글자).
        // 세 칸을 그대로 두고 제출하면 서버가 원문으로 되돌리지만, 한 칸이라도 바꾸면 나머지 가린 칸이
        // 별표째 저장된다(`RefundBankMask::restoreRegistered` 는 세 칸이 모두 가린 모양일 때만 되돌린다).
        // 그래서 가린 채움인지를 남겨 두고, 한 칸을 고치는 순간 나머지를 비운다(setClaimRefundAccountField).
        claimRefundAccountMasked: claimable.refund_account?.prefill?.masked === true && refundPrefill.account_number !== '',
        claimEstimate: null,
        claimEstimateFormatted: null,
        claimEstimateLoading: false,
        claimEstimateError: null,
        claimSubmitError: null,
        claimValidationErrors: null,
        claimFieldErrors: null,
        isSubmittingClaim: false,
        claimRedeliveryEditing: false,
        claimRedelivery: null,
        changeProductTarget: null,
        changeProductMode: 'same',
        changeProductProduct: null,
        // 빈 객체가 아니라 null 로 지운다 — setLocal 은 깊은 병합이라 `{}` 는 무동작이고
        // 앞 상품에서 고른 옵션이 그대로 남는다(2026-08-31 실측). 읽는 쪽은 전부 `?? {}` 다.
        changeProductSelections: null,
        changeProductMatched: null,
        changeProductAdditionalSelections: null,
        changeProductAdditionalTexts: null,
    });
}

/**
 * 신청 상품 선택 토글 핸들러
 *
 * @example
 * { "handler": "sirsoft-ecommerce.toggleClaimItem", "params": { "index": 0, "claimApiBase": "..." } }
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트 (미리보기 debounce 콜백의 상태 반영 대상)
 */
export function toggleClaimItemHandler(
    action: ActionWithParams<{ index?: number; claimApiBase?: string; isGuest?: boolean }>,
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const index = Number(action.params?.index ?? -1);
    if (index < 0) return;

    const local = G7Core.state.getLocal();
    const current: ClaimApplyItem[] = local.claimItems ?? [];

    // 취소·환불은 즉시취소와 환불신청서가 한 화면에서 갈린다 — 두 분기를 섞으면 서버가 신청
    // 전체를 거절하므로(`claim_refund_branch_mixed`) 그 조합을 만들 수 있게 두지 않는다.
    if (!canSelectClaimItem(current, index, String(local.claimType ?? ''))) {
        G7Core.toast?.warning?.(
            t(
                'sirsoft-ecommerce.user.claim_apply.refund_branch_mixed_blocked',
                '자동으로 처리되는 상품과 판매자 확인이 필요한 상품은 나누어 신청해 주세요.'
            )
        );

        return;
    }

    const items: ClaimApplyItem[] = current.map((item: ClaimApplyItem, i: number) =>
        i === index ? { ...item, selected: !item.selected } : item
    );

    G7Core.state.setLocal({
        claimItems: items,
        claimSelectAll: items.length > 0 && items.every((item) => item.selected),
        claimResolvedBranch: resolveSelectedRefundBranch(items),
    });

    scheduleClaimEstimate(G7Core, action.params ?? {}, context);
}

/**
 * 신청 상품 전체 선택 토글 핸들러
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트 (미리보기 debounce 콜백의 상태 반영 대상)
 */
export function toggleClaimSelectAllHandler(
    action: ActionWithParams<{ claimApiBase?: string; isGuest?: boolean }>,
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const local = G7Core.state.getLocal();
    const claimType = String(local.claimType ?? '');
    const current: ClaimApplyItem[] = local.claimItems ?? [];
    const next = !local.claimSelectAll;

    // 전체 선택도 같은 규칙을 탄다. 취소·환불에서 두 분기가 섞여 있으면 "전체" 는 서버가
    // 거절하는 조합이므로, 먼저 만나는 분기만 고른다 — 화면이 전건을 고른 것처럼 보이게 한 뒤
    // 제출에서야 거절하면 구매자는 무엇을 나눠야 하는지 알 수 없다.
    const targetBranch = next && claimType === 'refund'
        ? (current.find((item: ClaimApplyItem) => !!item.refund_branch)?.refund_branch ?? null)
        : null;

    const items: ClaimApplyItem[] = current.map((item: ClaimApplyItem) => ({
        ...item,
        selected: next && (targetBranch === null || item.refund_branch === targetBranch),
    }));

    G7Core.state.setLocal({
        claimItems: items,
        claimSelectAll: items.length > 0 && items.every((item) => item.selected),
        claimResolvedBranch: resolveSelectedRefundBranch(items),
    });

    scheduleClaimEstimate(G7Core, action.params ?? {}, context);
}

/**
 * 신청 수량 변경 핸들러
 *
 * 잔여 수량(`available_quantity`)을 상한으로 클램프한다 — 서버도 같은 상한으로 거절하지만,
 * 입력 칸이 넘는 값을 그대로 보여 주면 요약이 그 수량으로 계산된 것처럼 읽힌다.
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트 (미리보기 debounce 콜백의 상태 반영 대상)
 */
export function updateClaimQuantityHandler(
    action: ActionWithParams<{ index?: number; value?: unknown; claimApiBase?: string; isGuest?: boolean }>,
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const index = Number(action.params?.index ?? -1);
    if (index < 0) return;

    const local = G7Core.state.getLocal();
    const items: ClaimApplyItem[] = [...(local.claimItems ?? [])];
    const target = items[index];

    if (!target) return;

    const max = Math.max(1, Number(target.available_quantity ?? 1));
    const parsed = parseInt(String(action.params?.value ?? ''), 10);
    const clamped = Math.max(1, Math.min(Number.isNaN(parsed) ? 1 : parsed, max));

    // 클램프 결과가 **이미 들고 있던 값과 같으면** state 가 안 바뀌어 리렌더가 일어나지 않는다.
    // 그러면 입력 칸에는 사용자가 친 값(가용 2인데 99)이 그대로 남고 전송만 2 로 나간다 —
    // 화면이 말하는 수량과 실제 신청 수량이 어긋난다(S2-D9).
    // 잔여 2에서 3을 치면 state 가 1→2 로 바뀌어 칸이 따라오지만, 이어서 99를 치면
    // 이미 2라 아무 일도 일어나지 않는 것이 그 차이다. 그래서 칸을 직접 맞춰 준다.
    if (clamped !== parsed && typeof document !== 'undefined') {
        const el = document.activeElement;
        if (el instanceof HTMLInputElement && el.type === 'number') {
            // 리액트가 관리하는 칸이라 `el.value = …` 만으로는 되돌려 놓는다 — 리액트가
            // 추적하는 네이티브 setter 로 써야 그 값이 살아남는다. 값을 바꾼 뒤에는
            // input 이벤트를 흘려 보내 리액트의 내부 값도 같은 수로 맞춘다.
            const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
            if (setter) {
                setter.call(el, String(clamped));
                el.dispatchEvent(new Event('input', { bubbles: true }));
            } else {
                el.value = String(clamped);
            }
        }
    }

    items[index] = { ...target, quantity: clamped };

    G7Core.state.setLocal({ claimItems: items });

    scheduleClaimEstimate(G7Core, action.params ?? {}, context);
}

/**
 * 사유 선택 핸들러
 *
 * 사유가 귀책 구분을 정하고, 귀책이 회수·왕복 배송비의 부담 주체를 정한다 — 그래서 사유가
 * 바뀌면 미리보기를 다시 부른다.
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트 (미리보기 debounce 콜백의 상태 반영 대상)
 */
export function setClaimReasonHandler(
    action: ActionWithParams<{ value?: string; claimApiBase?: string; isGuest?: boolean }>,
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const local = G7Core.state.getLocal();
    const reasonCode = String(action.params?.value ?? '');

    G7Core.state.setLocal({
        claimReason: reasonCode,
        claimFaultType: resolveFaultType(G7Core, String(local.claimType ?? ''), reasonCode),
        claimValidationErrors: null,
        claimFieldErrors: null,
        claimSubmitError: null,
    });

    scheduleClaimEstimate(G7Core, action.params ?? {}, context);
}

/**
 * 청구 방식(축1) 선택 핸들러
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트 (미리보기 debounce 콜백의 상태 반영 대상)
 */
export function setClaimChargeMethodHandler(
    action: ActionWithParams<{ value?: string; claimApiBase?: string; isGuest?: boolean }>,
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    G7Core.state.setLocal({ claimChargeMethod: String(action.params?.value ?? '') });

    scheduleClaimEstimate(G7Core, action.params ?? {}, context);
}

/**
 * 결제 수단(축2) 선택 핸들러
 *
 * 결제 수단은 서버 재계산에 영향을 주지 않으므로 미리보기를 다시 부르지 않는다.
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function setClaimPaymentMethodHandler(
    action: ActionWithParams<{ value?: string }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    G7Core.state.setLocal({ claimPaymentMethod: String(action.params?.value ?? '') });
}

/** 환불 계좌 입력 칸 → 로컬 상태 키 */
const REFUND_ACCOUNT_FIELD_KEYS: Record<string, string> = {
    bank_code: 'claimRefundBankCode',
    account_number: 'claimRefundBankAccount',
    holder: 'claimRefundBankHolder',
};

/**
 * 환불 계좌 입력 핸들러 (은행 · 계좌번호 · 예금주)
 *
 * 등록 계좌 프리필이 **가린 모양**(`claimRefundAccountMasked`)이면 세 칸을 「전부 그대로 두거나 전부 새로
 * 쓰거나」 로 다룬다. 서버는 세 칸이 모두 가린 모양 그대로일 때만 원문으로 되돌리므로, 한 칸만 바꾸면
 * 나머지 가린 칸(`********7890` · `홍**`)이 별표째 저장된다. 그래서 가린 상태에서 한 칸이 바뀌면:
 *  - 바뀐 칸이 별표를 품은 채면(가린 값의 일부만 고침) 그 칸을 비운다 — 별표와 새 글자가 섞인 값이다.
 *  - 나머지 가린 칸(계좌번호 · 예금주)을 비운다 — 구매자가 세 칸을 처음부터 새로 쓴다.
 *  - 은행은 가린 값이 아니므로 비우지 않는다(바꾼 은행은 그대로 둔다).
 * 가리지 않은 프리필(구버전 서버)이나 가린 상태가 풀린 뒤에는 칸마다 그대로 적는다.
 *
 * 금액과 무관한 값이라 미리보기를 다시 부르지 않는다.
 *
 * @example
 * { "handler": "sirsoft-ecommerce.setClaimRefundAccountField", "params": { "field": "account_number", "value": "{{$event.target.value}}" } }
 *
 * @param action 액션 객체 (params.field: bank_code | account_number | holder, params.value: 입력값)
 * @param _context 액션 컨텍스트
 */
export function setClaimRefundAccountFieldHandler(
    action: ActionWithParams<{ field?: string; value?: unknown }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const field = String(action.params?.field ?? '');
    const key = REFUND_ACCOUNT_FIELD_KEYS[field];
    if (!key) return;

    const value = String(action.params?.value ?? '');
    const local = G7Core.state.getLocal() ?? {};

    if (local.claimRefundAccountMasked !== true || value === String(local[key] ?? '')) {
        G7Core.state.setLocal({ [key]: value });

        return;
    }

    G7Core.state.setLocal({
        claimRefundAccountMasked: false,
        claimRefundBankCode: field === 'bank_code' ? value : String(local.claimRefundBankCode ?? ''),
        claimRefundBankAccount: field === 'account_number' && !value.includes('*') ? value : '',
        claimRefundBankHolder: field === 'holder' && !value.includes('*') ? value : '',
    });
}

/**
 * 환불 우선순위(PG 우선 / 마일리지 우선) 변경 핸들러 (P3-③)
 *
 * 우선순위는 서버 재계산의 입력(`refund_priority`)이라 바꾸면 미리보기를 다시 부른다 —
 * 수량 변경과 같은 debounce·세대 가드 경로를 탄다.
 *
 * @example
 * { "handler": "sirsoft-ecommerce.changeClaimRefundPriority", "params": { "value": "points_first", "claimApiBase": "...", "isGuest": false } }
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트 (미리보기 debounce 콜백의 상태 반영 대상)
 */
export function changeClaimRefundPriorityHandler(
    action: ActionWithParams<{ value?: string; claimApiBase?: string; isGuest?: boolean }>,
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const value = action.params?.value === 'points_first' ? 'points_first' : 'pg_first';

    G7Core.state.setLocal({ claimRefundPriority: value });

    scheduleClaimEstimate(G7Core, action.params ?? {}, context);
}

/**
 * 주문금액 비교 섹션 접기/펼치기 핸들러 (P6)
 *
 * 토글 뒤 상태는 화면을 떠날 때까지 유지된다 — 새로고침하면 디바이스 기본값으로 돌아간다(설계값).
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function toggleClaimComparisonHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const local = G7Core.state.getLocal();

    G7Core.state.setLocal({ claimComparisonOpen: local.claimComparisonOpen !== true });
}

/**
 * 재발송지 편집 시작 핸들러 (D14)
 *
 * 원 배송지를 편집 버퍼의 초기값으로 복사한다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function startClaimRedeliveryEditHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const local = G7Core.state.getLocal();

    G7Core.state.setLocal({
        claimRedeliveryEditing: true,
        claimRedelivery: local.claimRedelivery ?? orderShippingAddressAsRedelivery(G7Core),
    });
}

/**
 * 재발송지 적용 핸들러 (D14)
 *
 * 우편번호·국가가 바뀌면 도서산간이 다시 판정되므로 미리보기를 다시 부른다.
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트 (미리보기 debounce 콜백의 상태 반영 대상)
 */
export function applyClaimRedeliveryHandler(
    action: ActionWithParams<{ claimApiBase?: string; isGuest?: boolean }>,
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    // `claimRedeliveryTouched` — 구매자가 재발송지를 직접 세웠다는 표식이다. 수정 화면은 신청서의
    // 동결 재발송지로 열리므로 값이 있다는 사실만으로는 손댔는지 알 수 없다(저장 참조).
    G7Core.state.setLocal({ claimRedeliveryEditing: false, claimRedeliveryReset: false, claimRedeliveryTouched: true });

    scheduleClaimEstimate(G7Core, action.params ?? {}, context);
}

/**
 * 재발송지 되돌리기 핸들러 (원 배송지로 복귀)
 *
 * 되돌렸다는 사실을 `claimRedeliveryReset` 으로 남긴다. 수정 화면의 저장은 이 표식을 보고
 * 재발송지를 null 로 보낸다 — 키를 빼면 서버는 "손대지 않음"으로 읽어 신청서에 이미
 * 저장된(바꿔 둔) 재발송지를 그대로 둔다. 작성 화면은 재발송지를 안 보내는 것이 곧 원 배송지라
 * 이 표식을 쓰지 않는다.
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트 (미리보기 debounce 콜백의 상태 반영 대상)
 */
export function resetClaimRedeliveryHandler(
    action: ActionWithParams<{ claimApiBase?: string; isGuest?: boolean }>,
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    G7Core.state.setLocal({ claimRedelivery: null, claimRedeliveryEditing: false, claimRedeliveryReset: true, claimRedeliveryTouched: false });

    scheduleClaimEstimate(G7Core, action.params ?? {}, context);
}

/**
 * 교환상품 변경 모달 열기 핸들러 (D29)
 *
 * 대상 상품의 index 를 받아 그 줄만 바꾼다. index 를 넘기지 않으면 항상 첫 상품이 바뀌고
 * 오류 없이 조용히 잘못된 신청서가 저장된다.
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function openClaimChangeProductHandler(
    action: ActionWithParams<{ index?: number }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const index = Number(action.params?.index ?? -1);
    if (index < 0) return;

    const local = G7Core.state.getLocal();
    if (local.changeProductLoading === true) return;
    const target: ClaimApplyItem | undefined = (local.claimItems ?? [])[index];

    if (!target) return;

    G7Core.state.setLocal({
        changeProductTarget: { index, order_option_id: target.order_option_id, product_id: target.product_id },
        changeProductMode: target.exchange_mode,
        changeProductProduct: null,
        // 빈 객체가 아니라 null 로 지운다 — setLocal 은 깊은 병합이라 `{}` 는 무동작이다.
        // 그대로 두면 앞 행에서 고른 옵션이 이 행의 모달에 남아, 두 상품이 같은 옵션 값을
        // 가질 때 구매자가 이 행에서 고르지 않은 값이 미리 선택된 채로 적용된다(2026-08-31 실측).
        changeProductSelections: null,
        changeProductMatched: null,
        changeProductAdditionalSelections: null,
        changeProductAdditionalTexts: null,
        changeProductLoading: true,
    });

    G7Core.modal?.open?.('modal_claim_change_product');

    try {
        const response = await G7Core.api.get(
            '/api/modules/sirsoft-ecommerce/products/' + String(target.product_id ?? '')
        );

        G7Core.state.setLocal({
            changeProductProduct: response?.data ?? null,
            changeProductLoading: false,
        });
    } catch (error: any) {
        logger.error('[openClaimChangeProduct] 상품 조회 실패:', error);
        G7Core.state.setLocal({ changeProductLoading: false });
        G7Core.toast?.error?.(
            error?.response?.data?.message
            ?? t('sirsoft-ecommerce.user.claim_apply.change_product_load_failed', '교환 가능한 옵션을 불러오지 못했습니다.')
        );
    }
}

/**
 * 교환 방식(동일상품/옵션변경) 선택 핸들러
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function setClaimChangeProductModeHandler(
    action: ActionWithParams<{ mode?: string }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    G7Core.state.setLocal({ changeProductMode: action.params?.mode === 'change' ? 'change' : 'same' });
}

/**
 * 교환상품 변경 적용 핸들러 (D29)
 *
 * 모달이 연 그 index 의 줄만 갱신한다.
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트 (미리보기 debounce 콜백의 상태 반영 대상)
 */
export function applyClaimChangeProductHandler(
    action: ActionWithParams<{ claimApiBase?: string; isGuest?: boolean }>,
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const local = G7Core.state.getLocal();
    const target = local.changeProductTarget;

    if (!target) return;

    const index = Number(target.index ?? -1);
    const items: ClaimApplyItem[] = [...(local.claimItems ?? [])];
    const row = items[index];

    if (!row) return;

    const mode = local.changeProductMode === 'change' ? 'change' : 'same';
    const matched = local.changeProductMatched ?? null;

    if (mode === 'change' && !matched?.id) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.user.claim_apply.change_product_select_option_required', '교환받을 옵션을 선택해 주세요.')
        );

        return;
    }

    const additionalSelections = local.changeProductAdditionalSelections ?? {};
    const additionalTexts = local.changeProductAdditionalTexts ?? {};
    const customOptions = Object.keys(additionalSelections).length > 0 || Object.keys(additionalTexts).length > 0
        ? { selections: additionalSelections, custom_texts: additionalTexts }
        : null;

    // 고른 추가옵션의 **이름**은 이 모달이 이미 쥐고 있다(그룹 목록을 그리고 있으므로).
    // 접수 후 화면은 서버가 동결한 스냅샷을 읽지만, 작성 화면은 아직 서버에 보내기 전이라
    // 여기서 만들어 두지 않으면 고른 직후의 요약 줄에 아무것도 나오지 않는다.
    const additionalGroups: any[] = local.changeProductProduct?.additional_options ?? [];
    const additionalLabels: string[] = Object.entries(additionalSelections)
        .map(([groupId, valueId]) => {
            const group = additionalGroups.find((g) => Number(g?.id) === Number(groupId));
            const value = (group?.values ?? []).find((v: any) => Number(v?.id) === Number(valueId));

            if (!value?.name) return '';

            const text = String(additionalTexts[groupId as any] ?? '').trim();

            return text !== '' ? `${value.name} (${text})` : String(value.name);
        })
        .filter((label) => label !== '');

    items[index] = {
        ...row,
        exchange_mode: mode,
        new_product_option_id: mode === 'change' ? Number(matched.id) : null,
        new_option_label: mode === 'change'
            ? (matched.option_name_localized ?? matched.option_name ?? '')
            : '',
        new_additional_labels: additionalLabels,
        custom_options: customOptions,
    };

    G7Core.state.setLocal({ claimItems: items, changeProductTarget: null });
    G7Core.modal?.close?.('modal_claim_change_product');

    scheduleClaimEstimate(G7Core, action.params ?? {}, context);
}

/**
 * 미리보기를 debounce 로 예약합니다.
 *
 * 토글마다 요청을 쏘면 왕복이 겹쳐 옛 응답이 새 선택 위에 덮인다. 세대 번호로 최신 요청만
 * 반영하되, 요청 수 자체도 debounce 로 줄인다.
 *
 * @param G7Core G7Core 인스턴스
 * @param params 액션 파라미터 (claimApiBase / isGuest)
 * @param context 핸들러가 받은 액션 컨텍스트 (콜백의 상태 반영 대상)
 */
function scheduleClaimEstimate(
    G7Core: any,
    params: { claimApiBase?: string; isGuest?: boolean },
    context?: HandlerContext
): void {
    const claimApiBase = params.claimApiBase ?? '';

    if (!claimApiBase) return;

    // 콜백은 액션 실행이 끝난 뒤에 돈다 — 그때의 `G7Core.state.setLocal()` 은 핸들러를 부른 컴포넌트를
    // 모르므로 정본 `_local` 만 바뀐다. 핸들러가 받은 컨텍스트의 `setState` 를 잡아 두었다가 같은 값을
    // 함께 실어 그 컴포넌트에도 반영한다(엔진 내부 전역을 바꿔 끼우지 않는다).
    const componentSetState = typeof context?.setState === 'function' ? context.setState : null;
    const writeLocal: LocalWriter = (updates) => {
        G7Core.state.setLocal(updates);
        componentSetState?.(updates);
    };

    if (claimEstimateDebounceTimer) {
        clearTimeout(claimEstimateDebounceTimer);
    }

    claimEstimateDebounceTimer = setTimeout(() => {
        void estimateClaimInternal(G7Core, claimApiBase, params.isGuest === true, writeLocal);
    }, 400);
}

/**
 * 미리보기(서버 재계산) 실행
 *
 * 미리보기는 신청과 **같은 자격 가드**를 탄다 — 성공하면 신청 가능이라는 뜻이고, 422 면
 * 그 사유가 곧 신청이 거절될 사유다. 그래서 실패는 토스트가 아니라 요약 자리의 안내로
 * 남기고 제출 버튼을 잠근다.
 *
 * @param G7Core G7Core 인스턴스
 * @param claimApiBase 주문 스코프 API 기준 경로
 * @param isGuest 비회원 여부
 * @param writeLocal 로컬 상태 writer (debounce 콜백은 핸들러 컨텍스트로 만든 writer 를 넘긴다)
 */
async function estimateClaimInternal(
    G7Core: any,
    claimApiBase: string,
    isGuest: boolean,
    writeLocal: LocalWriter = (updates) => G7Core.state.setLocal(updates)
): Promise<void> {
    const local = G7Core.state.getLocal();
    const claimType = String(local.claimType ?? '');
    const items = buildClaimItemsPayload(local.claimItems ?? []);

    if (!claimType) return;

    if (items.length === 0 || !local.claimReason) {
        // 아직 계산할 수 없는 상태다 — 옛 금액을 남기면 지금 선택의 결과처럼 읽힌다.
        claimEstimateGeneration += 1;
        writeLocal({
            claimEstimate: null,
            claimEstimateFormatted: null,
            claimEstimateLoading: false,
            claimEstimateError: null,
        });

        return;
    }

    const generation = ++claimEstimateGeneration;

    const body: Record<string, any> = {
        type: claimType,
        items,
        reason_code: local.claimReason,
        refund_priority: local.claimRefundPriority ?? 'pg_first',
    };

    if (local.claimChargeMethod) {
        body.charge_method = local.claimChargeMethod;
    }

    // 수정 화면의 미리보기 — 자기 신청서가 잡아 둔 수량을 잠금 집계에서 뺀다. 이 값을 빼면
    // 자기 자신에게 막혀 "신청할 수 있는 수량을 넘었습니다" 로 거절되고, 재발송지를 바꿔도
    // 배송비가 다시 계산되지 않는다(D14). 서버는 소유자 확인 뒤에만 제외한다.
    if (local.claimEditNumber) {
        body.claim_number = local.claimEditNumber;
    }

    if (local.claimRedelivery?.zipcode) {
        body.redelivery_address = {
            zipcode: local.claimRedelivery.zipcode,
            country_code: local.claimRedelivery.country_code,
        };
    }

    writeLocal({ claimEstimateLoading: true });

    try {
        const response = await G7Core.api.post(
            claimApiBase + '/claims/preview',
            body,
            claimRequestConfig(isGuest)
        );

        if (generation !== claimEstimateGeneration) return;

        const payload = response?.data ?? null;
        const currency = orderCurrencyCode(G7Core);

        // 상품별 차액은 서버 계산 결과를 그 줄에 되붙인다 — 화면이 스스로 곱셈을 하면
        // 요약(서버)과 줄(화면)의 근거가 갈린다.
        const latest = G7Core.state.getLocal();
        const perItem: Record<number, number> = {};
        for (const entry of payload?.items ?? []) {
            perItem[Number(entry.order_option_id)] = Number(entry.price_difference ?? 0);
        }

        const nextItems = (latest.claimItems ?? []).map((item: ClaimApplyItem) => {
            const difference = perItem[item.order_option_id];

            return difference === undefined
                ? item
                : {
                    ...item,
                    price_difference: difference,
                    price_difference_formatted: formatAmountInCurrency(Math.abs(difference), currency),
                };
        });

        // 수정 화면의 차액(D14) — 다시 계산된 청구액과 접수 시점 청구액의 차이다. 이 줄이
        // 없으면 구매자는 재발송지를 바꾼 뒤 얼마가 더 청구되는지(또는 돌아오는지) 모른 채
        // 저장하게 되고, 그 차액은 저장 뒤에야 결제·환급으로 드러난다.
        const editDelta = latest.claimEditMode === true
            ? Number(payload?.total_charge_amount ?? 0) - Number(latest.claimEditOriginalCharge ?? 0)
            : 0;

        writeLocal({
            claimItems: nextItems,
            claimEstimate: payload,
            claimEstimateFormatted: formatClaimEstimate(payload, currency),
            claimEstimateLoading: false,
            claimEstimateError: null,
            claimEditChargeDelta: editDelta,
            claimEditChargeDeltaFormatted: editDelta === 0
                ? ''
                : formatAmountInCurrency(Math.abs(editDelta), currency),
        });
    } catch (error: any) {
        if (generation !== claimEstimateGeneration) return;

        logger.error('[estimateClaim] 실패:', error);

        const message = error?.response?.data?.message
            ?? error?.data?.message
            ?? t('sirsoft-ecommerce.user.claim_apply.estimate_failed', '예상 금액을 계산하지 못했습니다.');
        const userFixable = isUserFixableClaimError(error);

        writeLocal({
            claimEstimate: null,
            claimEstimateFormatted: null,
            claimEstimateLoading: false,
            // 4xx 만 화면에 남긴다 — 그 사유가 곧 신청이 거절될 사유다. 서버 장애·네트워크
            // 실패는 화면에 남기지 않는다(§6-3 P13). 어느 쪽이든 금액은 지워지고 제출은
            // `claimEstimate` 가 비어 있어 잠긴 채로 남는다.
            claimEstimateError: userFixable ? message : null,
        });

        if (!userFixable) {
            G7Core.toast?.error?.(message);
        }
    }
}

/**
 * 미리보기 즉시 실행 핸들러 (진입 첫 조회 등)
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function estimateClaimHandler(
    action: ActionWithParams<{ claimApiBase?: string; isGuest?: boolean }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const claimApiBase = action.params?.claimApiBase ?? '';
    if (!claimApiBase) return;

    await estimateClaimInternal(G7Core, claimApiBase, action.params?.isGuest === true);
}

/**
 * 유형별 신청 엔드포인트 접미사를 반환합니다.
 *
 * @param claimType 클레임 유형
 * @returns 접미사 (알 수 없으면 null)
 */
export function claimStoreSuffix(claimType: string): string | null {
    if (claimType === 'exchange') return '/exchanges';
    if (claimType === 'return') return '/returns';
    if (claimType === 'refund') return '/refund-requests';

    return null;
}

/**
 * 신청 제출 핸들러
 *
 * 첨부를 먼저 올려 `image_ids` 를 확보한 뒤 신청서를 접수하고, 결제가 필요하면 응답이
 * 지목한 PG 결제 진입 핸들러를 그대로 dispatch 한다(provider-agnostic — 특정 PG 하드코딩 없음).
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function submitClaimHandler(
    action: ActionWithParams<{ claimApiBase?: string; isGuest?: boolean; successPath?: string; detailPathBase?: string }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const claimApiBase = action.params?.claimApiBase ?? '';
    const isGuest = action.params?.isGuest === true;

    if (!claimApiBase) return;

    const local = G7Core.state.getLocal();
    const claimType = String(local.claimType ?? '');
    if (local.isSubmittingClaim === true || local.removingClaimImageId != null) return;
    const suffix = claimStoreSuffix(claimType);
    const items = buildClaimItemsPayload(local.claimItems ?? []);

    if (!suffix) return;

    if (items.length === 0) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.user.claim_apply.select_items_first', '신청할 상품을 선택해 주세요.')
        );

        return;
    }

    if (!local.claimReason) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.user.claim_apply.reason_required', '신청 사유를 선택해 주세요.')
        );

        return;
    }

    // 환불 불가 조합(P3-①) — 버튼 잠금과 이중 안전. 미리보기가 통과해도 재계산 환불액이 0원 아래면
    // 서버가 접수를 거절하므로 여기서 먼저 막는다.
    if (local.claimEstimate?.adjustment?.cancel_blocked === true) {
        G7Core.toast?.warning?.(
            local.claimEstimate?.adjustment?.cancel_blocked_reason
            ?? t(
                'sirsoft-ecommerce.user.claim_apply.cancel_blocked',
                '이 조합은 환불액이 0원 아래로 내려가 신청할 수 없습니다 — 상품·수량을 다시 확인해 주세요'
            )
        );

        return;
    }

    G7Core.state.setLocal({
        isSubmittingClaim: true,
        claimSubmitError: null,
        claimValidationErrors: null,
        claimFieldErrors: null,
        // 지난 시도가 지목한 줄의 오류도 함께 지운다 — 남겨 두면 이미 고친 줄이 계속 붉게 남는다.
        claimItems: attachClaimItemErrors(local.claimItems ?? [], null),
    });

    let imageIds: number[] = [];

    try {
        imageIds = await uploadPendingClaimFiles(G7Core, { claimApiBase, isGuest });
    } catch (error: any) {
        logger.error('[submitClaim] 첨부 업로드 실패:', error);
    }

    // 업로드를 기다리는 동안 로컬 상태가 바뀔 수 있다(구매자가 계속 입력한다). 본문은 **대기 뒤**
    // 다시 읽은 상태로 만든다 — 대기 앞 스냅샷(`local`)으로 만들면 화면에 보이는 값과 제출된 값이
    // 갈린다(#599 validate 2026-09-23). 제출 자격 판정(위)은 대기 앞 값으로 이미 끝났다.
    const latest = G7Core.state.getLocal() ?? local;

    const body: Record<string, any> = {
        items: buildClaimItemsPayload(latest.claimItems ?? local.claimItems ?? []),
        reason_code: latest.claimReason ?? local.claimReason,
        refund_priority: latest.claimRefundPriority ?? 'pg_first',
    };

    if (latest.claimReasonDetail) {
        body.reason_detail = latest.claimReasonDetail;
    }

    if (imageIds.length > 0) {
        body.image_ids = imageIds;
    }

    if (claimType !== 'refund') {
        if (latest.claimChargeMethod) {
            body.charge_method = latest.claimChargeMethod;
        }

        if (latest.claimPaymentMethod) {
            body.payment_method = latest.claimPaymentMethod;
        }
    }

    if (claimType === 'exchange' && latest.claimRedelivery?.zipcode) {
        body.redelivery_address = { ...latest.claimRedelivery, changed_by_user: true };
    }

    // 환불계좌는 구매자에게 돈이 돌아가는 신청에서, 그리고 세 필드가 다 채워졌을 때만 싣는다 —
    // 서버는 셋 중 하나만 와도 거절한다(계좌 없이 승인되는 무통장 환불을 막는 규칙이다).
    // 아무것도 채우지 않았으면 키 자체를 보내지 않아 "입력하지 않음" 과 "일부만 입력" 이
    // 구분되고, 서버는 신청서에 계좌를 적지 않는다.
    // 교환은 저가 옵션으로 바꿔 차액이 돌아올 때만 대상이다 — 그 판정은 카드 노출 조건과 같은
    // 값(refund_product_amount)을 쓴다. 다른 값을 쓰면 칸은 보이는데 전송되지 않거나 그 반대가
    // 되고, 둘 다 오류를 남기지 않는다.
    const refundsToBuyer =
        claimType !== 'exchange' || Number(latest.claimEstimate?.refund_product_amount ?? 0) > 0;

    if (refundsToBuyer) {
        const bankCode = String(latest.claimRefundBankCode ?? '').trim();
        const bankAccount = String(latest.claimRefundBankAccount ?? '').trim();
        const bankHolder = String(latest.claimRefundBankHolder ?? '').trim();

        // 별표가 섞인 계좌는 보내지 않는다 — 가린 프리필을 **세 칸 그대로** 보낼 때만 서버가 등록 원문으로
        // 되돌린다. 가린 상태가 풀린 뒤(한 칸을 고친 뒤)에도 별표가 남아 있으면 그 값은 별표째 저장된다.
        if (latest.claimRefundAccountMasked !== true && (bankAccount.includes('*') || bankHolder.includes('*'))) {
            G7Core.state.setLocal({
                isSubmittingClaim: false,
                claimFieldErrors: {
                    ...collectClaimFieldErrors(null),
                    refund_bank: t(
                        'sirsoft-ecommerce.user.claim_apply.refund_account_masked_mixed',
                        '가려진 계좌 정보는 일부만 고칠 수 없습니다. 은행·계좌번호·예금주를 모두 새로 입력해 주세요.'
                    ),
                },
            });

            return;
        }

        if (bankCode || bankAccount || bankHolder) {
            body.refund_bank = {
                bank_code: bankCode,
                account_number: bankAccount,
                holder: bankHolder,
            };
        }
    }

    try {
        const response = await G7Core.api.post(
            claimApiBase + suffix,
            body,
            claimRequestConfig(isGuest)
        );

        if (!response?.success) {
            throw new Error(response?.message ?? 'Claim request failed');
        }

        // 접수 뒤 로컬 상태 초기화 — 다시 들어온 신청서가 지난 토글·우선순위를 물려받지 않는다(T5).
        G7Core.state.setLocal({
            isSubmittingClaim: false,
            claimImageFiles: [],
            claimImages: [],
            claimComparisonOpen: defaultClaimComparisonOpen(G7Core),
            claimRefundPriority: 'pg_first',
        });
        G7Core.toast?.success?.(
            response.message
            ?? t('sirsoft-ecommerce.user.claim_apply.submit_success', '신청이 접수되었습니다.')
        );

        const data = response.data ?? {};

        if (data.requires_pg_payment && data.pg_payment_handler) {
            await G7Core.dispatch?.({
                handler: data.pg_payment_handler,
                params: { pgPaymentData: data.pg_payment_data },
            });

            return;
        }

        // 접수 후 이동 주소는 **서버가 실어 준 `branch`** 로 만든다(F8).
        //
        // 예전에는 클라이언트가 `resolved_type`(주문 **전체** 기준)으로 추측했다. 혼재 주문에서
        // 그 값은 `mixed` 라 취소 탭으로 보내는데 접수 결과는 환불신청서였고, 그래서 제보된
        // 화면이 나왔다 — 접수는 됐는데 간 탭에는 아무것도 없다. 새 모델에서 취소·환불 접수는
        // 언제나 환불신청서이므로 `claim_type` 이 정본이고, `branch` 는 그 신청서가 어디까지
        // 자동으로 갔는지를 말할 뿐이다.
        const successPath = resolveClaimSuccessPath(action.params?.successPath, data, action.params?.detailPathBase);

        if (successPath) {
            await G7Core.dispatch?.({
                handler: 'navigate',
                params: { path: successPath },
            });
        }
    } catch (error: any) {
        logger.error('[submitClaim] 실패:', error);

        const errorData = error?.response?.data ?? error?.data;
        const message = errorData?.message
            ?? error?.message
            ?? t('sirsoft-ecommerce.user.claim_apply.submit_failed', '신청 접수에 실패했습니다.');
        const userFixable = isUserFixableClaimError(error);

        // 4xx 는 필드 오류를 그 필드 옆에, 필드에 매이지 않는 사유를 요약 카드에 남긴다 —
        // 화면에 남는 안내를 토스트로 한 번 더 띄우면 같은 말이 두 곳에서 겹친다.
        // 5xx·네트워크 실패는 화면에 남길 상태가 아니므로 토스트로만 알린다(§6-3 P13).
        const validationErrors = userFixable ? (errorData?.errors ?? null) : null;
        const currentItems = G7Core.state.getLocal()?.claimItems ?? [];
        // 필드 자리에 배치되지 않은 사유만 요약 카드가 맡는다 — 배치된 사유까지 여기 두면
        // 같은 말이 그 칸과 요약에 두 번 나온다(§6-3 P13). 오류 맵이 없는 4xx 는 message 를
        // 그대로 남긴다: 그 사유는 어느 칸에도 매이지 않는다.
        const summaryMessage = validationErrors
            ? unplacedClaimErrorMessage(validationErrors, currentItems)
            : message;

        G7Core.state.setLocal({
            isSubmittingClaim: false,
            claimSubmitError: userFixable ? (summaryMessage || null) : null,
            claimValidationErrors: validationErrors,
            // 첨부·재발송지·청구 2축의 사유는 그 칸 아래에 남긴다 — 요약 카드의 message 한 줄만
            // 남으면 구매자가 어느 칸을 고쳐야 하는지 알 수 없다(§6-3 P13).
            claimFieldErrors: collectClaimFieldErrors(validationErrors),
            // 항목별 오류는 그 상품 줄에 붙인다 — 요약 카드는 필드에 매이지 않는 사유만 맡는다.
            claimItems: attachClaimItemErrors(currentItems, validationErrors),
        });

        if (!userFixable) {
            G7Core.toast?.error?.(message);
        }
    }
}

/**
 * 수정 화면의 저장 핸들러 (PATCH — 승인 전 사유·상세 사유·첨부·교환 재발송지, D13/D14)
 *
 * 바꿀 수 있는 것만 보낸다. 품목·수량·교환 대상·청구 방식은 접수된 값이 그대로 남으므로
 * 페이로드에 싣지 않는다 — 같은 값을 다시 보내면 서버가 그 축을 "바뀐 것" 으로 처리해
 * 배송비 재산정·차액 정산이 불필요하게 도는 경로가 생긴다.
 *
 * 첨부는 두 갈래다: 아직 올리지 않은 파일은 먼저 업로드해 `add_image_ids` 로, 이미 귀속된
 * 첨부의 삭제는 `remove_image_ids` 로 넘긴다. 서버가 한 흐름에서 처리해야 장수 상한 판정이
 * 화면과 갈리지 않는다(삭제 후 남을 장수를 함께 본다).
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.saveClaimEdit",
 *   "params": {
 *     "claimEditApiBase": "{{_local.claimWithdrawApiBase ?? ''}}",
 *     "claimApiBase": "{{_local.claimApiBase ?? ''}}",
 *     "isGuest": false,
 *     "successPath": "/mypage/claims/{{route.claim_number}}"
 *   }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function saveClaimEditHandler(
    action: ActionWithParams<{
        claimEditApiBase?: string;
        claimApiBase?: string;
        isGuest?: boolean;
        successPath?: string;
    }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    // 수정 주소의 앞부분은 셸이 통째로 준다 — 회원(/user/claims)과 비회원
    // (/guest/orders/{주문번호}/claims)의 라우트 모양이 달라 핸들러가 조립하면 한쪽이 404 다.
    const editBase = String(action.params?.claimEditApiBase ?? '').replace(/\/+$/, '');
    // 첨부 업로드는 주문 스코프 주소를 쓴다(신청서 미귀속 첨부를 올리는 자리라 작성과 같다).
    const claimApiBase = String(action.params?.claimApiBase ?? '');
    const isGuest = action.params?.isGuest === true;
    const local = G7Core.state.getLocal();
    const claimNumber = String(local.claimEditNumber ?? '').trim();

    if (!editBase || !claimNumber) return;
    // 더블클릭으로 두 번 나가면 두 번째는 첫 저장이 만든 상태를 다시 고치려다 거절된다 —
    // 성공한 저장 뒤에 실패 안내가 뜬다.
    if (local.claimEditSaving === true || local.removingClaimImageId != null) return;

    if (!local.claimReason) {
        G7Core.toast?.warning?.(
            t('sirsoft-ecommerce.user.claim_apply.reason_required', '신청 사유를 선택해 주세요.')
        );

        return;
    }

    G7Core.state.setLocal({
        claimEditSaving: true,
        claimEditError: null,
        claimValidationErrors: null,
        claimFieldErrors: null,
    });

    let addImageIds: number[] = [];

    if (claimApiBase) {
        try {
            addImageIds = await uploadPendingClaimFiles(G7Core, { claimApiBase, isGuest });
        } catch (error: any) {
            logger.error('[saveClaimEdit] 첨부 업로드 실패:', error);
        }
    }

    const latest = G7Core.state.getLocal();
    const body: Record<string, any> = {
        reason_code: latest.claimReason,
        reason_detail: latest.claimReasonDetail ?? '',
    };

    if (addImageIds.length > 0) {
        body.add_image_ids = addImageIds;
    }

    const removeIds = (latest.claimRemoveImageIds ?? []).map((value: unknown) => Number(value)).filter((value: number) => value > 0);

    if (removeIds.length > 0) {
        body.remove_image_ids = removeIds;
    }

    // 재발송지는 교환에서만, 그리고 구매자가 실제로 [적용]한 경우에만 싣는다. 값이 같아도
    // `changed_by_user` 는 true 다 — 바꾼 사실 자체가 운영자에게 남아야 할 기록이다(D14).
    // 수정 화면은 신청서의 동결 재발송지로 열리므로 `claimRedelivery` 가 있다는 것만으로는
    // 손댄 것이 아니다 — 그 값으로 보내면 사유만 고친 저장이 `changed_by_user` 를 뒤집는다.
    if (latest.claimType === 'exchange' && latest.claimRedeliveryTouched === true && latest.claimRedelivery?.zipcode) {
        body.redelivery_address = { ...latest.claimRedelivery, changed_by_user: true };
    } else if (latest.claimType === 'exchange' && latest.claimRedeliveryReset === true) {
        // [원 배송지로 되돌리기] — null 이 "바꿔 둔 재발송지를 버린다"는 뜻이다. 키를 빼면
        // 서버는 손대지 않은 것으로 읽어 바꿔 둔 주소를 그대로 둔다(화면과 신청서가 갈린다).
        body.redelivery_address = null;
    }

    // 환불 계좌는 접수와 같은 규칙으로 싣는다(2026-09-01 확정 — 수정 화면에서 편집 가능).
    // 표시 응답의 계좌는 마스킹된 값이라 화면이 되돌려 보낼 수 없으므로 칸은 빈 채로 열리고,
    // 구매자가 실제로 채운 경우에만 전송한다 — 손대지 않은 저장이 기록된 계좌를 지우지
    // 않게 하는 것이 이 조건의 목적이다. 셋 중 일부만 채우면 그대로 보내 서버가 거절한다
    // (조용히 버리면 구매자는 바뀐 줄 안다).
    const editBankCode = String(latest.claimRefundBankCode ?? '').trim();
    const editBankAccount = String(latest.claimRefundBankAccount ?? '').trim();
    const editBankHolder = String(latest.claimRefundBankHolder ?? '').trim();

    if (editBankCode || editBankAccount || editBankHolder) {
        body.refund_bank = {
            bank_code: editBankCode,
            account_number: editBankAccount,
            holder: editBankHolder,
        };
    }

    try {
        const response = await G7Core.api.patch(
            editBase + '/' + encodeURIComponent(claimNumber),
            body,
            claimRequestConfig(isGuest)
        );

        if (!response?.success) {
            throw new Error(response?.message ?? 'Claim update failed');
        }

        G7Core.state.setLocal({
            claimEditSaving: false,
            claimEditError: null,
            claimImageFiles: [],
            claimRemoveImageIds: [],
        });

        G7Core.toast?.success?.(
            response.message
            ?? t('sirsoft-ecommerce.user.claim_apply.edit_save_success', '신청 내용이 수정되었습니다.')
        );

        // 재발송지 변경으로 배송비가 다시 계산되면 차액이 추가 청구될 수 있다. 그 경우
        // 서버가 결제 진입을 지목하므로 작성과 같은 방식으로 그대로 dispatch 한다
        // (특정 PG 하드코딩 없음). 차액이 환불이거나 0 이면 결제 진입이 없다.
        const data = response.data ?? {};

        if (data.requires_pg_payment && data.pg_payment_handler) {
            await G7Core.dispatch?.({
                handler: data.pg_payment_handler,
                params: { pgPaymentData: data.pg_payment_data },
            });

            return;
        }

        if (action.params?.successPath) {
            await G7Core.dispatch?.({
                handler: 'navigate',
                params: { path: action.params.successPath },
            });
        }
    } catch (error: any) {
        logger.error('[saveClaimEdit] 실패:', error);

        const errorData = error?.response?.data ?? error?.data;
        const message = errorData?.message
            ?? error?.message
            ?? t('sirsoft-ecommerce.user.claim_apply.edit_save_failed', '신청 내용 수정에 실패했습니다.');
        const userFixable = isUserFixableClaimError(error);
        const validationErrors = userFixable ? (errorData?.errors ?? null) : null;

        G7Core.state.setLocal({
            claimEditSaving: false,
            // 4xx 는 화면에 남긴다 — 승인 뒤에는 수정할 수 없어 서버가 거절하는데,
            // 토스트로만 알리면 사라진 뒤 왜 막혔는지 화면에 남지 않는다(§6-3 P13).
            claimEditError: userFixable ? message : null,
            claimValidationErrors: validationErrors,
            claimFieldErrors: collectClaimFieldErrors(validationErrors),
        });

        if (!userFixable) {
            G7Core.toast?.error?.(message);
        }
    }
}
