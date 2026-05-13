/**
 * 기타 섹션 핸들러
 *
 * 상품 등록/수정 화면에서 라벨, 상품코드, 배송비, 쇼핑연동, 식별코드, 처리로그 등
 * 기타 기능들을 처리합니다.
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:Misc')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:Misc]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:Misc]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:Misc]', ...args),
};

interface ShippingPolicy {
    id: number;
    name: string;
    is_default?: boolean;
    [key: string]: any;
}

interface CommonInfoTemplate {
    id: number;
    content: Record<string, string>;
}

interface LabelWithPeriod {
    id: number;
    start_date: string | null;
    end_date: string | null;
}

interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

/**
 * 라벨 선택을 토글합니다.
 *
 * @param action 액션 객체 (params.labelId 필요)
 * @param _context 액션 컨텍스트
 */
export function toggleLabelHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const labelId = params.labelId as number;

    if (!labelId) {
        logger.warn('[toggleLabel] Missing labelId param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[toggleLabel] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const labels: number[] = state.form?.labels ?? [];

    const newLabels = labels.includes(labelId)
        ? labels.filter((id) => id !== labelId)
        : [...labels, labelId];

    G7Core.state.setLocal({
        form: { ...state.form, labels: newLabels },
        hasChanges: true,
    });

    logger.log(`[toggleLabel] Toggled label ${labelId}. Current labels: ${newLabels.length}`);
}

/**
 * 상품코드를 자동 생성합니다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function generateProductCodeHandler(
    _action: ActionWithParams,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state || !G7Core?.api) {
        logger.warn('[generateProductCode] G7Core.state or G7Core.api is not available');
        return;
    }

    try {
        const response = await G7Core.api.post(
            '/api/modules/sirsoft-ecommerce/admin/products/generate-code'
        );

        const state = G7Core.state.getLocal() || {};
        G7Core.state.setLocal({
            form: { ...state.form, product_code: response.data.product_code },
        });

        logger.log(`[generateProductCode] Generated: ${response.data.product_code}`);
    } catch (error) {
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.messages.code_generate_error')
            ?? 'Failed to generate product code.'
        );
        logger.error('[generateProductCode] Failed:', error);
    }
}

/**
 * 배송정책 정보를 반환합니다.
 *
 * @param action 액션 객체 (params.policyId, params.field, params.policies 필요)
 * @param _context 액션 컨텍스트
 * @returns 배송정책 필드 값
 */
export function getShippingPolicyInfoHandler(
    action: ActionWithParams,
    _context: ActionContext
): string {
    const params = action.params || {};
    const policyId = params.policyId as number;
    const field = params.field as string;
    const policies = params.policies as ShippingPolicy[];

    if (!policyId || !field || !policies) {
        return '-';
    }

    const policy = policies.find((p) => p.id === policyId);
    if (!policy) return '-';
    return policy[field] ?? '-';
}

/**
 * 공통정보 템플릿 내용을 반환합니다.
 *
 * @param action 액션 객체 (params.templateId, params.templates 필요)
 * @param _context 액션 컨텍스트
 * @returns 공통정보 내용
 */
export function getCommonInfoContentHandler(
    action: ActionWithParams,
    _context: ActionContext
): string {
    const params = action.params || {};
    const templateId = params.templateId as number;
    const templates = params.templates as CommonInfoTemplate[];

    if (!templateId || !templates) {
        return '';
    }

    const G7Core = (window as any).G7Core;
    const locale = G7Core?.locale?.current?.() || 'ko';

    const template = templates.find((t) => t.id === templateId);
    return template?.content?.[locale] ?? template?.content?.ko ?? '';
}

/**
 * 쇼핑 플랫폼 연동 설정을 업데이트합니다.
 *
 * @param action 액션 객체 (params.platform, params.field, params.value 필요)
 * @param _context 액션 컨텍스트
 */
export function updateShoppingIntegrationHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const platform = params.platform as 'naver' | 'daum' | 'facebook' | 'google';
    const field = params.field as 'enabled' | 'sync_interval';
    const value = params.value as boolean | string;

    if (!platform || !field) {
        logger.warn('[updateShoppingIntegration] Missing required params');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateShoppingIntegration] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const integrations = state.form?.shopping_integrations ?? {
        naver: { enabled: false, sync_interval: 'manual' },
        daum: { enabled: false, sync_interval: 'manual' },
        facebook: { enabled: false, sync_interval: 'manual' },
        google: { enabled: false, sync_interval: 'manual' },
    };

    G7Core.state.setLocal({
        form: {
            ...state.form,
            shopping_integrations: {
                ...integrations,
                [platform]: {
                    ...integrations[platform],
                    [field]: value,
                },
            },
        },
        hasChanges: true,
    });

    logger.log(`[updateShoppingIntegration] Updated ${platform}.${field} to ${value}`);
}

/**
 * 배송비 유형을 업데이트합니다.
 *
 * @param action 액션 객체 (params.type 필요)
 * @param _context 액션 컨텍스트
 */
export function updateShippingTypeHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const type = params.type as 'fixed_free' | 'fixed_paid' | 'threshold_free' | 'qty_free' | 'weight_based';

    if (!type) {
        logger.warn('[updateShippingType] Missing type param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateShippingType] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};

    // 배송비 유형에 따른 기본값 설정
    let defaultFee = 0;
    let defaultThreshold = 0;

    if (type === 'fixed_paid') {
        defaultFee = 3000;
    } else if (type === 'threshold_free') {
        defaultThreshold = 30000;
    }

    G7Core.state.setLocal({
        form: {
            ...state.form,
            shipping_type: type,
            shipping_fee: type === 'fixed_free' ? 0 : (state.form?.shipping_fee ?? defaultFee),
            free_shipping_threshold:
                type === 'threshold_free'
                    ? (state.form?.free_shipping_threshold ?? defaultThreshold)
                    : 0,
        },
        hasChanges: true,
    });

    logger.log(`[updateShippingType] Updated shipping type to ${type}`);
}

/**
 * 식별코드를 업데이트합니다.
 *
 * @param action 액션 객체 (params.field, params.value 필요)
 * @param _context 액션 컨텍스트
 */
export function updateIdentificationCodeHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const field = params.field as 'isbn_issn' | 'memo_id';
    const value = params.value as string;

    if (!field) {
        logger.warn('[updateIdentificationCode] Missing field param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateIdentificationCode] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};

    G7Core.state.setLocal({
        form: {
            ...state.form,
            [field]: value,
        },
        hasChanges: true,
    });
}

/**
 * 라벨 기간 설정 모달을 엽니다.
 *
 * @param action 액션 객체 (params.labelId, params.labelName 필요)
 * @param _context 액션 컨텍스트
 */
export function openLabelPeriodModalHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const labelId = params.labelId as number;
    const labelName = params.labelName as string;

    if (!labelId) {
        logger.warn('[openLabelPeriodModal] Missing labelId param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[openLabelPeriodModal] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const labels: LabelWithPeriod[] = state.form?.labels ?? [];
    const existingPeriod = labels.find((l) => l.id === labelId);

    G7Core.state.setLocal({
        ui: {
            ...state.ui,
            showLabelPeriodModal: true,
            labelPeriodModalData: {
                labelId,
                labelName,
                startDate: existingPeriod?.start_date ?? null,
                endDate: existingPeriod?.end_date ?? null,
            },
        },
    });
}

/**
 * 라벨 기간을 저장합니다.
 *
 * @param action 액션 객체 (params.labelId, params.startDate, params.endDate 필요)
 * @param _context 액션 컨텍스트
 */
export function saveLabelPeriodHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const labelId = params.labelId as number;
    const startDate = params.startDate as string | null;
    const endDate = params.endDate as string | null;

    if (!labelId) {
        logger.warn('[saveLabelPeriod] Missing labelId param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[saveLabelPeriod] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const labels: LabelWithPeriod[] = [...(state.form?.labels ?? [])];

    const existingIndex = labels.findIndex((l) => l.id === labelId);

    if (existingIndex >= 0) {
        labels[existingIndex] = {
            ...labels[existingIndex],
            start_date: startDate,
            end_date: endDate,
        };
    } else {
        labels.push({
            id: labelId,
            start_date: startDate,
            end_date: endDate,
        });
    }

    G7Core.state.setLocal({
        form: { ...state.form, labels },
        ui: {
            ...state.ui,
            showLabelPeriodModal: false,
            labelPeriodModalData: null,
        },
        hasChanges: true,
    });

    G7Core.toast?.success?.(
        G7Core.t?.('sirsoft-ecommerce.admin.product.messages.label_period_saved')
        ?? 'Label period has been saved.'
    );
    logger.log(`[saveLabelPeriod] Saved period for label ${labelId}`);
}

/**
 * 라벨 기간을 제거합니다. (라벨은 유지, 기간만 삭제)
 *
 * @param action 액션 객체 (params.labelId 필요)
 * @param _context 액션 컨텍스트
 */
export function removeLabelPeriodHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const labelId = params.labelId as number;

    if (!labelId) {
        logger.warn('[removeLabelPeriod] Missing labelId param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[removeLabelPeriod] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const labels: LabelWithPeriod[] = [...(state.form?.labels ?? [])];

    const existingIndex = labels.findIndex((l) => l.id === labelId);

    if (existingIndex >= 0) {
        labels[existingIndex] = {
            ...labels[existingIndex],
            start_date: null,
            end_date: null,
        };
    }

    G7Core.state.setLocal({
        form: { ...state.form, labels },
        hasChanges: true,
    });

    G7Core.toast?.success?.(
        G7Core.t?.('sirsoft-ecommerce.admin.product.messages.label_period_removed')
        ?? 'Label period has been removed.'
    );
    logger.log(`[removeLabelPeriod] Removed period for label ${labelId}`);
}

/**
 * 처리로그 정렬을 변경합니다.
 *
 * @param action 액션 객체 (params.sort 필요: 'latest' | 'oldest')
 * @param _context 액션 컨텍스트
 */
export function updateActivityLogSortHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const sort = params.sort as 'latest' | 'oldest';

    if (!sort) {
        logger.warn('[updateActivityLogSort] Missing sort param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateActivityLogSort] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};

    G7Core.state.setLocal({
        ui: {
            ...state.ui,
            activityLogSort: sort,
        },
    });

    // 데이터소스 새로고침
    G7Core.datasources?.refresh?.('activity_logs', {
        params: {
            sort: sort === 'latest' ? 'desc' : 'asc',
            per_page: state.ui?.activityLogPerPage ?? 10,
        },
    });

    logger.log(`[updateActivityLogSort] Updated sort to ${sort}`);
}

/**
 * 처리로그 페이지당 항목 수를 변경합니다.
 *
 * @param action 액션 객체 (params.perPage 필요: 10 | 20 | 50)
 * @param _context 액션 컨텍스트
 */
export function updateActivityLogPerPageHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const perPage = params.perPage as 10 | 20 | 50;

    if (!perPage) {
        logger.warn('[updateActivityLogPerPage] Missing perPage param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[updateActivityLogPerPage] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};

    G7Core.state.setLocal({
        ui: {
            ...state.ui,
            activityLogPerPage: perPage,
        },
    });

    // 데이터소스 새로고침
    G7Core.datasources?.refresh?.('activity_logs', {
        params: {
            sort: state.ui?.activityLogSort === 'oldest' ? 'asc' : 'desc',
            per_page: perPage,
        },
    });

    logger.log(`[updateActivityLogPerPage] Updated perPage to ${perPage}`);
}

/**
 * 기본 배송정책을 자동 설정합니다.
 *
 * - init_actions에서 호출 (생성 모드, condition: !route.itemCode)
 * - ui.useDefaultShippingPolicy가 true이고 is_default=true인 정책을 form.shipping_policy_id에 설정
 * - 이미 값이 설정된 경우(수정 모드) 건너뜀
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function setDefaultShippingPolicyHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[setDefaultShippingPolicy] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};

    // 이미 배송정책이 설정된 경우 (수정 모드) 건너뜀
    if (state.form?.shipping_policy_id) {
        logger.log('[setDefaultShippingPolicy] Shipping policy already set, skipping');
        return;
    }

    // '기본 배송정책 사용' 토글이 OFF이면 건너뜀
    // 기본값이 true이므로 명시적으로 false인 경우에만 건너뜀
    if (state.ui?.useDefaultShippingPolicy === false) {
        logger.log('[setDefaultShippingPolicy] useDefaultShippingPolicy is OFF, skipping');
        return;
    }

    // G7Core.dataSource를 통해 데이터소스 접근 (init_actions에서는 context.datasources가 비어있음)
    const dsData = G7Core.dataSource?.get?.('shipping_policies');
    const policies: ShippingPolicy[] = dsData?.data?.data ?? dsData?.data ?? [];

    if (policies.length === 0) {
        logger.log('[setDefaultShippingPolicy] No shipping policies found');
        return;
    }

    // is_default=true인 정책 찾기
    const defaultPolicy = policies.find((p: ShippingPolicy) => p.is_default === true);

    if (defaultPolicy) {
        G7Core.state.setLocal({
            form: {
                ...state.form,
                shipping_policy_id: defaultPolicy.id,
            },
        });
        logger.log(`[setDefaultShippingPolicy] Set default shipping policy: ${defaultPolicy.id} (${defaultPolicy.name})`);
    } else {
        logger.log('[setDefaultShippingPolicy] No default shipping policy found');
    }
}

/**
 * 기본 배송정책 사용 토글 핸들러
 *
 * - ON: is_default=true인 배송정책 자동 선택
 * - OFF: 배송정책 선택 해제 (null)
 *
 * @param action 액션 객체 (params.checked: boolean)
 * @param _context 액션 컨텍스트
 */
export function toggleDefaultShippingPolicyHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[toggleDefaultShippingPolicy] G7Core.state API is not available');
        return;
    }

    const checked = action.params?.checked ?? false;
    const state = G7Core.state.getLocal() || {};

    // 토글 상태 업데이트
    const newUi = { ...state.ui, useDefaultShippingPolicy: checked };

    if (checked) {
        // ON: 기본 배송정책 선택
        // G7Core.dataSource를 통해 데이터소스 접근
        const dsData = G7Core.dataSource?.get?.('shipping_policies');
        const policies: ShippingPolicy[] = dsData?.data?.data ?? dsData?.data ?? [];
        const defaultPolicy = policies.find((p: ShippingPolicy) => p.is_default === true);

        G7Core.state.setLocal({
            ui: newUi,
            form: {
                ...state.form,
                shipping_policy_id: defaultPolicy?.id ?? null,
            },
        });

        if (defaultPolicy) {
            logger.log(`[toggleDefaultShippingPolicy] ON - Selected default policy: ${defaultPolicy.id}`);
        } else {
            logger.log('[toggleDefaultShippingPolicy] ON - No default policy found');
        }
    } else {
        // OFF: 기존 배송정책 유지 (토글 상태만 변경)
        G7Core.state.setLocal({
            ui: newUi,
        });
        logger.log('[toggleDefaultShippingPolicy] OFF - Keeping current shipping policy');
    }
}

/**
 * 라벨 날짜 프리셋을 설정합니다.
 *
 * @param action 액션 객체 (params.preset: '7d' | '14d' | '30d' | 'permanent')
 * @param _context 액션 컨텍스트
 */
export function setLabelDatePresetHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[setLabelDatePreset] G7Core.state API is not available');
        return;
    }

    const preset = action.params?.preset as string;
    if (!preset) {
        logger.warn('[setLabelDatePreset] Missing preset param');
        return;
    }

    const today = new Date();
    const formatDate = (date: Date): string => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    const startDate = formatDate(today);
    let endDate: string | null = null;

    switch (preset) {
        case '7d': {
            const end = new Date(today);
            end.setDate(end.getDate() + 7);
            endDate = formatDate(end);
            break;
        }
        case '14d': {
            const end = new Date(today);
            end.setDate(end.getDate() + 14);
            endDate = formatDate(end);
            break;
        }
        case '30d': {
            const end = new Date(today);
            end.setDate(end.getDate() + 30);
            endDate = formatDate(end);
            break;
        }
        case 'permanent':
            endDate = null;
            break;
        default:
            logger.warn(`[setLabelDatePreset] Unknown preset: ${preset}`);
            return;
    }

    G7Core.state.setGlobal({
        'labelFormData.start_date': startDate,
        'labelFormData.end_date': endDate,
    });

    logger.log(`[setLabelDatePreset] Applied preset '${preset}': ${startDate} ~ ${endDate ?? 'permanent'}`);
}
