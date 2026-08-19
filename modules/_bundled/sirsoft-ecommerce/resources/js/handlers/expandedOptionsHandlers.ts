// audit:allow extension-dist-source-literal-sync 수신 함수가 읽지 않는 handler 필드를 rollup 최적화가 소거해 해당 리터럴만 번들에서 빠진다 (fresh 빌드로 실측 — stale 아님)
/**
 * 상품 목록 확장 행 옵션 지연 로딩 핸들러
 *
 * 관리자 상품목록은 옵션을 기본 적재하지 않는다(한 페이지를 여는 것만으로 그 페이지 전 상품의
 * 옵션이 응답에 실리기 때문). 옵션 상세는 행을 펼칠 때 배치 엔드포인트로 가져와
 * **products 데이터소스의 `product.options` 에 그대로 써넣는다.**
 *
 * 별도 캐시(`_local.optionsByProduct`)를 두지 않는 이유:
 *   - 옵션을 소비하는 기존 핸들러 6종(updateOptionField / setDefaultOption / toggleOption /
 *     syncProductSelection / getProductOptionStates / bulkUpdate)이 전부 `product.options` 를
 *     읽고 쓴다. 데이터소스에 병합하면 이들은 한 줄도 고칠 게 없다
 *   - 사본을 두면 인라인 편집 결과가 두 곳에 존재해 stale 사본 버그가 새로 생긴다
 *   - `expandContext` 는 `{{...}}` 단일 바인딩만 식으로 평가한다. 맵 조회 표현식
 *     (`_local.optionsByProduct[row.id] || {}`)은 `}` 때문에 그 판정에 걸려 문자열로 굳는다
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:ExpandedOptions')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:ExpandedOptions]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:ExpandedOptions]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:ExpandedOptions]', ...args),
};

/** 옵션 배치 조회 엔드포인트 */
const OPTIONS_ENDPOINT = '/api/modules/sirsoft-ecommerce/admin/products/options';

/** 한 번에 요청할 수 있는 상품 수 (서버 FormRequest 의 max 와 동일) */
const MAX_BATCH_SIZE = 100;

interface LoadExpandedOptionsParams {
    /** 확장된 행 ID 목록 (DataGrid onExpandChange 인자) */
    expandedRows?: Array<number | string>;
    /** 확장 상태와 무관하게 특정 상품만 로드할 때 */
    productIds?: Array<number | string>;
    /** 단일 상품만 지정할 때 (재시도 버튼 등 — 레이아웃에서 배열 리터럴을 피하기 위함) */
    productId?: number | string;
    /** 이미 로드된 행도 다시 가져올지 여부 (저장 후 재로드) */
    force?: boolean;
    /** 대상 데이터소스 ID */
    dataSourceId?: string;
}

interface ActionWithParams {
    handler: string;
    params?: LoadExpandedOptionsParams;
    [key: string]: any;
}

/**
 * 진행 중인 요청의 상품 ID 집합 (모듈 스코프)
 *
 * 빠르게 접었다 펴기를 반복하면 같은 상품에 대한 요청이 겹친다. 이미 날아간 요청이 있는 상품은
 * 대상에서 빼서 중복 호출을 병합한다.
 */
const inFlight = new Set<string>();

/**
 * 테스트에서 모듈 스코프 상태를 초기화하기 위한 진입점.
 */
export function __resetExpandedOptionsState(): void {
    inFlight.clear();
}

/**
 * products 데이터소스의 상품 배열을 돌려줍니다.
 *
 * @param G7Core G7Core 인스턴스
 * @param dataSourceId 데이터소스 ID
 * @return 상품 배열 (없으면 빈 배열)
 */
function readProducts(G7Core: any, dataSourceId: string): any[] {
    const current = G7Core?.dataSource?.get?.(dataSourceId);
    const products = current?.data?.data;

    return Array.isArray(products) ? products : [];
}

/**
 * 상품 ID → 옵션 배열 맵을 데이터소스에 병합합니다.
 *
 * 요청을 보낸 시점의 배열을 캡처해 두고 나중에 그대로 쓰면, 응답을 기다리는 동안 이뤄진
 * 인라인 편집이 통째로 사라진다. 그래서 **콜백 안에서 데이터소스를 다시 읽어** 병합한다.
 *
 * @param G7Core G7Core 인스턴스
 * @param dataSourceId 데이터소스 ID
 * @param optionsByProductId 상품 ID(문자열) → 옵션 배열
 * @return void
 */
function mergeOptionsIntoDataSource(
    G7Core: any,
    dataSourceId: string,
    optionsByProductId: Record<string, any[]>
): void {
    const current = G7Core?.dataSource?.get?.(dataSourceId);
    const products = current?.data?.data;

    if (!current || !Array.isArray(products)) {
        logger.warn('[loadExpandedOptions] DataSource not available for merge:', dataSourceId);

        return;
    }

    const merged = products.map((product: any) => {
        const key = String(product?.id);

        if (!Object.prototype.hasOwnProperty.call(optionsByProductId, key)) {
            return product;
        }

        return { ...product, options: optionsByProductId[key] };
    });

    G7Core.dataSource.set(dataSourceId, {
        ...current,
        data: {
            ...current.data,
            data: merged,
        },
    });
}

/**
 * 체크된 상품의 옵션을 옵션 선택 상태에 합칩니다.
 *
 * 상품을 체크한 뒤 행을 펼치면, 옵션이 그제서야 도착하므로 옵션 체크박스가 비어 보인다.
 * 실제 저장 범위는 서버가 상품 ID 로 전개하므로 동작에는 문제가 없지만 표시가 어긋난다.
 *
 * @param G7Core G7Core 인스턴스
 * @param optionsByProductId 상품 ID(문자열) → 옵션 배열
 * @return void
 */
function hydrateSelectedOptionIds(G7Core: any, optionsByProductId: Record<string, any[]>): void {
    const localState = G7Core?.state?.getLocal?.() || {};
    const selectedItems: Array<number | string> = localState.selectedItems || [];

    if (selectedItems.length === 0) {
        return;
    }

    const selectedProductKeys = new Set(selectedItems.map((id) => String(id)));
    const nextSelected = new Set<string>(localState.selectedOptionIds || []);
    let changed = false;

    Object.entries(optionsByProductId).forEach(([productKey, options]) => {
        if (!selectedProductKeys.has(productKey)) {
            return;
        }

        options.forEach((option: any) => {
            const optionKey = `${productKey}-${option?.id}`;

            if (!nextSelected.has(optionKey)) {
                nextSelected.add(optionKey);
                changed = true;
            }
        });
    });

    if (changed) {
        G7Core.state.setLocal({ selectedOptionIds: Array.from(nextSelected) });
    }
}

/**
 * 로딩/에러 표시 상태를 갱신합니다.
 *
 * @param G7Core G7Core 인스턴스
 * @param mutate 현재 상태를 받아 다음 상태를 만드는 함수
 * @return void
 */
function updateProgressState(
    G7Core: any,
    mutate: (loading: string[], errored: string[]) => { loading: string[]; errored: string[] }
): void {
    const localState = G7Core?.state?.getLocal?.() || {};
    const next = mutate(localState.optionsLoadingIds || [], localState.optionsErrorIds || []);

    G7Core.state.setLocal({
        optionsLoadingIds: next.loading,
        optionsErrorIds: next.errored,
    });
}

/**
 * 확장된 행의 옵션을 로드합니다.
 *
 * 확장 상태 갱신(`_local.expandedRows`)과 옵션 로드를 한 액션으로 묶는다. 레이아웃에서 setState
 * 와 로드를 두 액션으로 나열하면 실행 순서에 따라 방금 펼친 행이 대상에서 빠질 수 있다.
 *
 * @param action 액션 객체 (params 포함)
 * @param context 액션 컨텍스트
 * @return void
 */
export function loadExpandedOptionsHandler(action: ActionWithParams, context: ActionContext): void {
    const params = (action.params || {}) as LoadExpandedOptionsParams;
    const dataSourceId = params.dataSourceId || 'products';
    const force = params.force === true;

    const G7Core = (window as any).G7Core;

    if (!G7Core?.dataSource?.get || !G7Core?.dataSource?.set || !G7Core?.state?.setLocal) {
        logger.warn('[loadExpandedOptions] G7Core dataSource/state API is not available');

        return;
    }

    // 확장 상태를 **먼저** 반영한다 (레이아웃의 setState 액션을 이 핸들러가 흡수한다)
    if (Array.isArray(params.expandedRows)) {
        G7Core.state.setLocal({ expandedRows: params.expandedRows });
    }

    const requestedIds = Array.isArray(params.productIds)
        ? params.productIds
        : (params.productId !== undefined && params.productId !== null
            ? [params.productId]
            : (Array.isArray(params.expandedRows)
                ? params.expandedRows
                : (G7Core.state.getLocal?.()?.expandedRows || [])));

    if (!Array.isArray(requestedIds) || requestedIds.length === 0) {
        return;
    }

    const products = readProducts(G7Core, dataSourceId);
    const productByKey = new Map<string, any>(products.map((p: any) => [String(p?.id), p]));

    const toFetch: string[] = [];
    const emptyByLocalKnowledge: Record<string, any[]> = {};

    requestedIds.forEach((rawId) => {
        const key = String(rawId);
        const product = productByKey.get(key);

        if (!product) {
            return;
        }

        // 배열 여부로 판정한다. `undefined` 는 "아직 안 불러옴", `[]` 는 "불러왔고 옵션이 없음".
        // 이걸 구분하지 못하면 옵션 0건 상품을 펼칠 때마다 매번 다시 요청한다.
        if (!force && Array.isArray(product.options)) {
            return;
        }

        // 전체 옵션 수가 0 이면 네트워크 없이 로컬에서 확정한다
        if (Number(product.options_total_count) === 0) {
            emptyByLocalKnowledge[key] = [];

            return;
        }

        if (inFlight.has(key)) {
            return;
        }

        toFetch.push(key);
    });

    if (Object.keys(emptyByLocalKnowledge).length > 0) {
        mergeOptionsIntoDataSource(G7Core, dataSourceId, emptyByLocalKnowledge);
    }

    if (toFetch.length === 0) {
        return;
    }

    if (toFetch.length > MAX_BATCH_SIZE) {
        logger.warn(
            `[loadExpandedOptions] Requested ${toFetch.length} products, truncating to ${MAX_BATCH_SIZE}`
        );
        toFetch.length = MAX_BATCH_SIZE;
    }

    toFetch.forEach((key) => inFlight.add(key));

    updateProgressState(G7Core, (loading, errored) => ({
        loading: Array.from(new Set([...loading, ...toFetch])),
        errored: errored.filter((id) => !toFetch.includes(id)),
    }));

    G7Core.api
        .get(OPTIONS_ENDPOINT, { params: { product_ids: toFetch.map((id) => Number(id)) } })
        .then((response: any) => {
            const payload = response?.data || {};
            const optionsMap = payload.options || {};

            const merged: Record<string, any[]> = {};

            // 요청한 ID 는 응답에 없더라도 `[]` 로 확정한다. 그러지 않으면 권한 밖이거나 사라진
            // 상품의 행이 영원히 스켈레톤 상태로 남는다.
            toFetch.forEach((key) => {
                const options = optionsMap[key];
                merged[key] = Array.isArray(options) ? options : [];
            });

            mergeOptionsIntoDataSource(G7Core, dataSourceId, merged);
            hydrateSelectedOptionIds(G7Core, merged);

            updateProgressState(G7Core, (loading, errored) => ({
                loading: loading.filter((id) => !toFetch.includes(id)),
                errored: errored.filter((id) => !toFetch.includes(id)),
            }));
        })
        .catch((error: any) => {
            logger.error('[loadExpandedOptions] Failed to load options:', error);

            updateProgressState(G7Core, (loading, errored) => ({
                loading: loading.filter((id) => !toFetch.includes(id)),
                errored: Array.from(new Set([...errored, ...toFetch])),
            }));

            const message = error?.response?.data?.message
                || G7Core.t?.('sirsoft-ecommerce.admin.product.table.options.load_failed')
                || 'Failed to load product options.';

            G7Core.toast?.error?.(message);
        })
        .finally(() => {
            toFetch.forEach((key) => inFlight.delete(key));
        });
}

/**
 * 현재 펼쳐진 모든 행의 옵션을 다시 로드합니다.
 *
 * 일괄 저장 후 `refetch('products')` 로 목록을 새로 받으면 옵션은 응답에 없으므로, 펼친 행은
 * 열린 채 옵션만 사라진다(`resetBulkState` 는 `expandedRows` 를 지우지 않는다). 리페치 직후
 * 이 함수를 이어 붙여 펼침 상태와 내용이 어긋나지 않게 한다.
 *
 * @param G7Core G7Core 인스턴스
 * @param options 로드 옵션 (force: 이미 로드된 행도 다시 가져올지)
 * @return void
 */
export function reloadExpandedOptions(
    G7Core: any,
    options: { force?: boolean; dataSourceId?: string } = {}
): void {
    const expandedRows = G7Core?.state?.getLocal?.()?.expandedRows || [];

    if (!Array.isArray(expandedRows) || expandedRows.length === 0) {
        return;
    }

    loadExpandedOptionsHandler(
        {
            handler: 'sirsoft-ecommerce.loadExpandedOptions',
            params: {
                productIds: expandedRows,
                force: options.force === true,
                dataSourceId: options.dataSourceId || 'products',
            },
        },
        {} as ActionContext
    );
}

/**
 * 옵션 로드 실패 행을 다시 시도하는 핸들러 (에러 블록의 재시도 버튼용).
 *
 * 재시도 버튼은 실패한 **그 행** 에 붙어 있어 단수 `productId` 를 보낸다. 단수 키를 함께
 * 포워딩하지 않으면 대상 산출이 `expandedRows` 폴백으로 떨어져, 한 행 재시도가 펼쳐 둔 모든
 * 행을 `force` 로 다시 불러온다(이미 성공한 행까지 재요청 + 인라인 편집분 덮어쓰기).
 *
 * @param action 액션 객체 (params.productId 또는 params.productIds)
 * @param context 액션 컨텍스트
 * @return void
 */
export function retryExpandedOptionsHandler(action: ActionWithParams, context: ActionContext): void {
    const params = (action.params || {}) as LoadExpandedOptionsParams;

    loadExpandedOptionsHandler(
        {
            handler: 'sirsoft-ecommerce.retryExpandedOptions',
            params: {
                productId: params.productId,
                productIds: params.productIds,
                force: true,
                dataSourceId: params.dataSourceId,
            },
        },
        context
    );
}
