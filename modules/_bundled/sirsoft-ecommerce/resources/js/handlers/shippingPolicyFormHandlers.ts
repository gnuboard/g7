/**
 * 배송정책 등록/수정 폼 핸들러
 *
 * 배송정책 폼 화면에서 국가별 탭 관리, 부과정책 변경에 따른 필드 가시성 제어,
 * 구간 관리/검증, 도서산간 추가배송비 행 관리 등을 처리합니다.
 */

import type { ActionContext } from '../types';

// Logger 설정
const logger = ((window as any).G7Core?.createLogger?.('Ecom:ShippingPolicyForm')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:ShippingPolicyForm]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:ShippingPolicyForm]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:ShippingPolicyForm]', ...args),
};

interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

interface CountrySetting {
    country_code: string;
    shipping_method: string;
    custom_shipping_name: Record<string, string> | null;
    carrier: string | null;
    currency_code: string;
    charge_policy: string;
    base_fee: number;
    free_threshold: number | null;
    ranges: { type?: string; tiers?: RangeTier[]; unit_value?: number } | null;
    api_endpoint: string | null;
    api_request_fields: string[] | null;
    api_response_fee_field: string | null;
    extra_fee_enabled: boolean;
    extra_fee_settings: Array<{ zipcode: string; fee: number; region?: string }> | null;
    extra_fee_multiply: boolean;
    is_active: boolean;
}

interface RangeTier {
    min: number;
    max: number | null;
    fee: number;
}

interface RangeTierError {
    min?: string;
    max?: string;
    fee?: string;
}

// ===== 부과정책별 필드 요구사항 매핑 =====

/** 기본 배송비가 필요한 정책 */
const REQUIRES_BASE_FEE = [
    'fixed', 'conditional_free',
    'per_quantity', 'per_weight', 'per_volume', 'per_volume_weight', 'per_amount',
];

/** 무료 기준금액이 필요한 정책 */
const REQUIRES_FREE_THRESHOLD = ['conditional_free'];

/** 구간 설정이 필요한 정책 */
const REQUIRES_RANGES = [
    'range_amount', 'range_quantity', 'range_weight', 'range_volume', 'range_volume_weight',
];

/** API 설정이 필요한 정책 */
const REQUIRES_API = ['api'];

/** 단위당 배송비 설정이 필요한 정책 */
const REQUIRES_UNIT_VALUE = [
    'per_quantity', 'per_weight', 'per_volume', 'per_volume_weight', 'per_amount',
];

// ===== 국가별 기본 설정값 =====

const DEFAULT_COUNTRY_SETTING: Omit<CountrySetting, 'country_code'> = {
    shipping_method: 'parcel',
    custom_shipping_name: null,
    carrier: null,
    currency_code: 'KRW',
    charge_policy: 'fixed',
    base_fee: 0,
    free_threshold: null,
    ranges: null,
    api_endpoint: null,
    api_request_fields: null,
    api_response_fee_field: null,
    extra_fee_enabled: false,
    extra_fee_settings: [],
    extra_fee_multiply: false,
    is_active: true,
};

// ===== 헬퍼 함수 =====

/**
 * 부과정책 값에 따른 가시성 플래그를 계산합니다.
 *
 * @param chargePolicy 부과정책 값
 * @returns 가시성 플래그 객체
 */
function getVisibilityFlags(chargePolicy: string): Record<string, boolean> {
    return {
        showBaseFee: REQUIRES_BASE_FEE.includes(chargePolicy),
        showFreeThreshold: REQUIRES_FREE_THRESHOLD.includes(chargePolicy),
        showRanges: REQUIRES_RANGES.includes(chargePolicy),
        showApiSettings: REQUIRES_API.includes(chargePolicy),
        showUnitValue: REQUIRES_UNIT_VALUE.includes(chargePolicy),
    };
}

/**
 * G7Core.state에 안전하게 접근합니다.
 */
function getG7Core(): any {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('G7Core.state not available');
        return null;
    }
    return G7Core;
}

/**
 * 로컬 상태를 안전하게 업데이트합니다.
 */
function updateLocalState(
    context: ActionContext,
    updates: Record<string, any>
): void {
    if (context.setLocalState) {
        context.setLocalState(updates);
    } else {
        const G7Core = getG7Core();
        G7Core?.state?.setLocal(updates);
    }
}

/**
 * 현재 country_settings 배열을 가져옵니다.
 */
function getCountrySettings(G7Core: any): CountrySetting[] {
    const localState = G7Core.state.getLocal?.() ?? {};
    return [...(localState.form?.country_settings ?? [])];
}

// ===== 핸들러 구현 =====

/**
 * 배송정책 폼을 초기화합니다.
 *
 * 수정 모드: API 응답의 country_settings를 매핑하고 첫 번째 국가의 charge_policy 기반 가시성 설정.
 * 등록 모드: 빈 country_settings, activeCountryTab=0, 기본 가시성(fixed) 설정.
 *
 * @param action params.isEdit: 수정 모드 여부, params.policy: 기존 정책 데이터, params.availableCountries: 배송가능국가 목록
 * @param context 액션 컨텍스트
 */
export function initShippingPolicyFormHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const policy = params.policy as Record<string, any> | undefined;
    const isEdit = !!params.isEdit;
    const isCopy = !!params.isCopy;
    const availableCountries = (params.availableCountries ?? []) as Array<{ code: string; name: Record<string, string>; is_active: boolean }>;

    const G7Core = getG7Core();
    if (!G7Core) return;

    const stateUpdates: Record<string, any> = {
        activeCountryTab: 0,
        rangeErrors: {},
    };

    if ((isEdit || isCopy) && policy) {
        // 수정/복사 모드: country_settings 배열이 API 응답에 포함
        const countrySettings = policy.country_settings ?? [];
        const firstSetting = countrySettings[0];
        const chargePolicy = firstSetting?.charge_policy ?? 'fixed';
        const flags = getVisibilityFlags(chargePolicy);
        Object.assign(stateUpdates, flags);
        logger.log('[initShippingPolicyForm]', isEdit ? 'Edit' : 'Copy', 'mode, countries:', countrySettings.length, 'first charge_policy:', chargePolicy);
    } else {
        // 등록 모드: 기본 가시성 (fixed 기준)
        const flags = getVisibilityFlags('fixed');
        Object.assign(stateUpdates, flags);
        logger.log('[initShippingPolicyForm] Create mode, default flags');
    }

    updateLocalState(context, stateUpdates);
}

/**
 * 국가별 설정을 추가합니다.
 *
 * @param action params.country_code: 추가할 국가코드
 * @param context 액션 컨텍스트
 */
export function addCountrySettingHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const countryCode = (params.country_code ?? '') as string;

    if (!countryCode) {
        logger.warn('[addCountrySetting] country_code is empty');
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const countrySettings = getCountrySettings(G7Core);

    // 중복 체크
    if (countrySettings.some(cs => cs.country_code === countryCode)) {
        logger.warn('[addCountrySetting] Duplicate country_code:', countryCode);
        return;
    }

    const newSetting: CountrySetting = {
        country_code: countryCode,
        ...DEFAULT_COUNTRY_SETTING,
    };

    countrySettings.push(newSetting);

    const newIndex = countrySettings.length - 1;
    const flags = getVisibilityFlags(newSetting.charge_policy);

    const stateUpdates: Record<string, any> = {
        'form.country_settings': countrySettings,
        activeCountryTab: newIndex,
        ...flags,
    };

    updateLocalState(context, stateUpdates);
    logger.log('[addCountrySetting] Added country:', countryCode, 'index:', newIndex);
}

/**
 * 국가별 설정을 삭제합니다.
 *
 * @param action params.index: 삭제할 인덱스
 * @param context 액션 컨텍스트
 */
export function removeCountrySettingHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const index = Number(params.index);

    if (isNaN(index) || index < 0) {
        logger.warn('[removeCountrySetting] Invalid index:', params.index);
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const countrySettings = getCountrySettings(G7Core);
    const localState = G7Core.state.getLocal?.() ?? {};

    if (index >= countrySettings.length) {
        logger.warn('[removeCountrySetting] Index out of bounds:', index);
        return;
    }

    const removedCode = countrySettings[index].country_code;
    countrySettings.splice(index, 1);

    // activeCountryTab 조정
    const currentTab = localState.activeCountryTab ?? 0;
    let newTab = currentTab;
    if (countrySettings.length === 0) {
        newTab = 0;
    } else if (currentTab >= countrySettings.length) {
        newTab = countrySettings.length - 1;
    } else if (currentTab > index) {
        newTab = currentTab - 1;
    }

    // rangeErrors에서 삭제된 국가 키 제거 (deepMerge 호환: delete 대신 빈 배열 할당)
    const rangeErrors = { ...(localState.rangeErrors ?? {}) };
    rangeErrors[removedCode] = [];

    // 새 활성 탭의 charge_policy 기반 가시성 재계산
    const activeCS = countrySettings[newTab];
    const flags = activeCS ? getVisibilityFlags(activeCS.charge_policy) : getVisibilityFlags('fixed');

    const stateUpdates: Record<string, any> = {
        'form.country_settings': countrySettings,
        activeCountryTab: newTab,
        rangeErrors,
        ...flags,
    };

    updateLocalState(context, stateUpdates);
    logger.log('[removeCountrySetting] Removed index:', index, 'country:', removedCode, 'newTab:', newTab);
}

/**
 * 국가별 탭을 전환합니다.
 *
 * @param action params.index: 전환할 탭 인덱스
 * @param context 액션 컨텍스트
 */
export function switchCountryTabHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const index = Number(params.index);

    if (isNaN(index) || index < 0) {
        logger.warn('[switchCountryTab] Invalid index:', params.index);
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const countrySettings = getCountrySettings(G7Core);

    if (index >= countrySettings.length) {
        logger.warn('[switchCountryTab] Index out of bounds:', index);
        return;
    }

    const targetCS = countrySettings[index];
    const flags = getVisibilityFlags(targetCS.charge_policy);

    const stateUpdates: Record<string, any> = {
        activeCountryTab: index,
        ...flags,
    };

    updateLocalState(context, stateUpdates);
    logger.log('[switchCountryTab] Switched to tab:', index, 'country:', targetCS.country_code, 'charge_policy:', targetCS.charge_policy);
}

/**
 * 국가별 설정의 개별 필드를 업데이트합니다.
 *
 * setState params 키에 {{}} 표현식을 사용할 수 없으므로,
 * 배열 인덱스 기반 필드 업데이트를 커스텀 핸들러로 처리합니다.
 *
 * @param action params.field: 변경할 필드명, params.value: 새 값
 * @param context 액션 컨텍스트
 */
export function updateCountryFieldHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const field = (params.field ?? '') as string;
    const value = params.value;

    if (!field) {
        logger.warn('[updateCountryField] field is empty');
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const localState = G7Core.state.getLocal?.() ?? {};
    const index = Number(localState.activeCountryTab ?? 0);
    const countrySettings = getCountrySettings(G7Core);

    if (index >= countrySettings.length) {
        logger.warn('[updateCountryField] Index out of bounds:', index);
        return;
    }

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[index] = { ...countrySettings[index], [field]: value } as CountrySetting;

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });
    logger.log('[updateCountryField] Updated', field, '=', value, 'at index:', index);
}

/**
 * 부과정책(charge_policy) 변경 시 가시성 플래그를 업데이트합니다.
 *
 * @param action params.value: 선택된 부과정책 값, params.index: 국가 탭 인덱스
 * @param context 액션 컨텍스트
 */
export function onChargePolicyChangeHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const chargePolicy = (params.value ?? '') as string;
    const countryIndex = Number(params.index ?? 0);

    if (!chargePolicy) {
        logger.warn('[onChargePolicyChange] charge_policy value is empty');
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const flags = getVisibilityFlags(chargePolicy);
    logger.log('[onChargePolicyChange]', chargePolicy, flags, 'countryIndex:', countryIndex);

    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) {
        logger.warn('[onChargePolicyChange] No country setting at index:', countryIndex);
        return;
    }

    // 배열 요소를 새 객체로 복제 후 수정
    // (shallow copy된 배열은 원본 객체와 같은 참조를 공유하므로,
    //  mutate하면 deepMerge에서 변경을 감지하지 못함 — 반드시 새 객체 생성 필수)
    const updatedCS: CountrySetting = { ...cs, charge_policy: chargePolicy };

    // 불필요한 필드 초기화
    if (!flags.showBaseFee) {
        updatedCS.base_fee = 0;
    }
    if (!flags.showFreeThreshold) {
        updatedCS.free_threshold = null;
    }
    if (!flags.showRanges && !flags.showUnitValue) {
        updatedCS.ranges = null;
    }
    if (!flags.showApiSettings) {
        updatedCS.api_endpoint = null;
        updatedCS.api_request_fields = null;
        updatedCS.api_response_fee_field = null;
    }

    // ranges 초기화 (구간 정책 선택 시 기본 구조 제공)
    if (flags.showRanges) {
        if (!updatedCS.ranges || !updatedCS.ranges.tiers) {
            const rangeType = chargePolicy.replace('range_', '');
            updatedCS.ranges = {
                type: rangeType,
                tiers: [{ min: 0, max: null, fee: 0 }],
            };
        }
    }

    // unit_value 초기화 (단위당 정책 선택 시)
    if (flags.showUnitValue) {
        if (!updatedCS.ranges || !updatedCS.ranges.unit_value) {
            updatedCS.ranges = {
                type: chargePolicy,
                unit_value: 1,
            };
        }
    }

    countrySettings[countryIndex] = updatedCS;

    const stateUpdates: Record<string, any> = {
        'form.country_settings': countrySettings,
        ...flags,
    };

    updateLocalState(context, stateUpdates);
}

/**
 * 구간별 배송비 tier를 추가합니다.
 *
 * @param action params.index: 국가 탭 인덱스 (activeCountryTab)
 * @param context 액션 컨텍스트
 */
export function addRangeTierHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const countryIndex = Number(params.index ?? 0);

    const G7Core = getG7Core();
    if (!G7Core) return;

    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) {
        logger.warn('[addRangeTier] No country setting at index:', countryIndex);
        return;
    }

    const currentTiers = [...(cs.ranges?.tiers ?? [])];

    // 마지막 tier의 max + 1 값을 새 tier의 min으로 사용 (포함 범위 기준)
    const lastTier = currentTiers[currentTiers.length - 1];
    const newMin = lastTier?.max != null ? lastTier.max + 1 : 0;

    const newTier: RangeTier = { min: newMin, max: null, fee: 0 };
    currentTiers.push(newTier);

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = {
        ...cs,
        ranges: { ...(cs.ranges ?? {}), tiers: currentTiers },
    };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });

    // 구간 검증 실행
    validateRangeTiersInternal(G7Core, context, countryIndex, currentTiers, cs.country_code);
    logger.log('[addRangeTier] Added tier, total:', currentTiers.length);
}

/**
 * 구간별 배송비 tier를 삭제합니다.
 *
 * @param action params.countryIndex: 국가 탭 인덱스, params.tierIndex: 삭제할 tier 인덱스
 * @param context 액션 컨텍스트
 */
export function removeRangeTierHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const countryIndex = Number(params.countryIndex ?? 0);
    const tierIndex = Number(params.tierIndex);

    if (isNaN(tierIndex) || tierIndex < 0) {
        logger.warn('[removeRangeTier] Invalid tierIndex:', params.tierIndex);
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) {
        logger.warn('[removeRangeTier] No country setting at index:', countryIndex);
        return;
    }

    const currentTiers = [...(cs.ranges?.tiers ?? [])];

    if (tierIndex >= currentTiers.length) {
        logger.warn('[removeRangeTier] tierIndex out of bounds:', tierIndex);
        return;
    }

    currentTiers.splice(tierIndex, 1);

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = {
        ...cs,
        ranges: { ...(cs.ranges ?? {}), tiers: currentTiers },
    };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });

    // 구간 검증 실행
    validateRangeTiersInternal(G7Core, context, countryIndex, currentTiers, cs.country_code);
    logger.log('[removeRangeTier] Removed tier:', tierIndex, 'remaining:', currentTiers.length);
}

/**
 * 구간별 배송비 tier 필드를 업데이트합니다.
 *
 * @param action params.countryIndex, params.tierIndex, params.field ('min'|'max'|'fee'), params.value
 * @param context 액션 컨텍스트
 */
export function updateRangeTierFieldHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const countryIndex = Number(params.countryIndex ?? 0);
    const tierIndex = Number(params.tierIndex);
    const field = params.field as 'min' | 'max' | 'fee';
    const value = params.value;

    if (isNaN(tierIndex) || tierIndex < 0) {
        logger.warn('[updateRangeTierField] Invalid tierIndex:', params.tierIndex);
        return;
    }
    if (!['min', 'max', 'fee'].includes(field)) {
        logger.warn('[updateRangeTierField] Invalid field:', field);
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) {
        logger.warn('[updateRangeTierField] No country setting at index:', countryIndex);
        return;
    }

    const currentTiers = [...(cs.ranges?.tiers ?? [])];

    if (tierIndex >= currentTiers.length) {
        logger.warn('[updateRangeTierField] tierIndex out of bounds:', tierIndex);
        return;
    }

    // min/max/fee는 숫자로 변환 (DOM input의 $event.target.value는 string)
    // max는 빈 값일 경우 null (무제한)
    let parsedValue: number | null;
    if (field === 'max') {
        parsedValue = value === '' || value === null || value === undefined ? null : Number(value);
    } else {
        parsedValue = Number(value);
    }

    currentTiers[tierIndex] = { ...currentTiers[tierIndex], [field]: parsedValue };

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = {
        ...cs,
        ranges: { ...(cs.ranges ?? {}), tiers: currentTiers },
    };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });

    // 구간 검증 실행
    validateRangeTiersInternal(G7Core, context, countryIndex, currentTiers, cs.country_code);
}

/**
 * 구간별 배송비 tier를 검증합니다.
 *
 * @param action params.countryIndex: 국가 탭 인덱스
 * @param context 액션 컨텍스트
 */
export function validateRangeTiersHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const countryIndex = Number(params.countryIndex ?? 0);

    const G7Core = getG7Core();
    if (!G7Core) return;

    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) return;

    const tiers = cs.ranges?.tiers ?? [];
    validateRangeTiersInternal(G7Core, context, countryIndex, tiers, cs.country_code);
}

/**
 * 구간 검증 내부 로직
 */
function validateRangeTiersInternal(
    G7Core: any,
    context: ActionContext,
    _countryIndex: number,
    tiers: RangeTier[],
    countryCode: string
): void {
    const localState = G7Core.state.getLocal?.() ?? {};
    const rangeErrors: Record<string, RangeTierError[]> = { ...(localState.rangeErrors ?? {}) };

    if (tiers.length === 0) {
        // deepMerge에서 delete로 키를 제거할 수 없으므로 빈 배열로 대체
        rangeErrors[countryCode] = [];
        updateLocalState(context, { rangeErrors });
        return;
    }

    const tierErrors: RangeTierError[] = new Array(tiers.length).fill(null).map(() => ({}));
    let hasError = false;

    const t = G7Core.t;

    for (let i = 0; i < tiers.length; i++) {
        const tier = tiers[i];

        // 첫 구간 min은 0이어야 함
        if (i === 0 && tier.min !== 0) {
            tierErrors[i].min = t?.('sirsoft-ecommerce.validation.shipping_policy.ranges.first_min_zero')
                ?? '첫 구간의 시작값은 0이어야 합니다.';
            hasError = true;
        }

        // 마지막 구간 max는 null이어야 함
        if (i === tiers.length - 1 && tier.max !== null && tier.max !== undefined) {
            tierErrors[i].max = t?.('sirsoft-ecommerce.validation.shipping_policy.ranges.last_max_unlimited')
                ?? '마지막 구간의 종료값은 비워야 합니다.';
            hasError = true;
        }

        // min < max (마지막 구간 제외)
        if (i < tiers.length - 1 && tier.max !== null && tier.max !== undefined) {
            if (tier.min >= tier.max) {
                tierErrors[i].min = t?.('sirsoft-ecommerce.validation.shipping_policy.ranges.min_less_than_max')
                    ?? '시작값이 종료값보다 작아야 합니다.';
                hasError = true;
            }
        }

        // 구간 연속성: 현재 max + 1 === 다음 min (포함 범위 기준)
        if (i < tiers.length - 1) {
            const nextTier = tiers[i + 1];
            if (tier.max !== null && tier.max !== undefined && (tier.max + 1) !== nextTier.min) {
                tierErrors[i].max = t?.('sirsoft-ecommerce.validation.shipping_policy.ranges.continuity')
                    ?? '구간이 연속적이지 않습니다.';
                hasError = true;
            }
        }

        // fee >= 0
        if (tier.fee < 0) {
            tierErrors[i].fee = t?.('sirsoft-ecommerce.validation.shipping_policy.ranges.fee_non_negative')
                ?? '배송비는 0 이상이어야 합니다.';
            hasError = true;
        }
    }

    // deepMerge에서 delete로 키를 제거할 수 없으므로, 에러 없음 시 빈 배열로 대체
    rangeErrors[countryCode] = hasError ? tierErrors : [];

    updateLocalState(context, { rangeErrors });
}

/**
 * 도서산간 추가배송비 행을 추가합니다.
 *
 * @param action params.countryIndex: 국가 탭 인덱스
 * @param context 액션 컨텍스트
 */
export function addExtraFeeRowHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const countryIndex = Number(params.countryIndex ?? 0);

    const G7Core = getG7Core();
    if (!G7Core) return;

    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) {
        logger.warn('[addExtraFeeRow] No country setting at index:', countryIndex);
        return;
    }

    // KR 전용 체크
    if (cs.country_code !== 'KR') {
        logger.warn('[addExtraFeeRow] Extra fee only available for KR, current:', cs.country_code);
        return;
    }

    const currentSettings = [...(cs.extra_fee_settings ?? [])];
    currentSettings.push({ zipcode: '', fee: 0, region: '' });

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = { ...cs, extra_fee_settings: currentSettings };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });
    logger.log('[addExtraFeeRow] Added row, total:', currentSettings.length);
}

/**
 * 도서산간 추가배송비 행을 삭제합니다.
 *
 * @param action params.countryIndex: 국가 탭 인덱스, params.feeIndex: 삭제할 행 인덱스
 * @param context 액션 컨텍스트
 */
export function removeExtraFeeRowHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const countryIndex = Number(params.countryIndex ?? 0);
    const feeIndex = Number(params.feeIndex);

    if (isNaN(feeIndex) || feeIndex < 0) {
        logger.warn('[removeExtraFeeRow] Invalid feeIndex:', params.feeIndex);
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) {
        logger.warn('[removeExtraFeeRow] No country setting at index:', countryIndex);
        return;
    }

    const currentSettings = [...(cs.extra_fee_settings ?? [])];
    if (feeIndex >= currentSettings.length) {
        logger.warn('[removeExtraFeeRow] feeIndex out of bounds:', feeIndex);
        return;
    }

    currentSettings.splice(feeIndex, 1);

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = { ...cs, extra_fee_settings: currentSettings };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });
    logger.log('[removeExtraFeeRow] Removed index:', feeIndex, 'remaining:', currentSettings.length);
}

/**
 * 도서산간 추가배송비 템플릿을 적용합니다.
 *
 * @param action params.countryIndex: 국가 탭 인덱스, params.settings: 템플릿의 추가배송비 설정 배열
 * @param context 액션 컨텍스트
 */
export function applyExtraFeeTemplateHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const countryIndex = Number(params.countryIndex ?? 0);
    const settings = params.settings as Array<{ zipcode: string; fee: number; region?: string }>;

    if (!settings || !Array.isArray(settings)) {
        logger.warn('[applyExtraFeeTemplate] Invalid settings:', settings);
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) {
        logger.warn('[applyExtraFeeTemplate] No country setting at index:', countryIndex);
        return;
    }

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = { ...cs, extra_fee_settings: settings };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });

    // 토스트 알림
    const t = G7Core.t;
    const message = t?.('sirsoft-ecommerce.admin.shipping_policy.form.template_applied')
        ?? '템플릿이 적용되었습니다.';
    G7Core.toast?.success?.(message);

    logger.log('[applyExtraFeeTemplate] Applied template with', settings.length, 'rows');
}

/**
 * 단위당 배송비(unit_value)를 업데이트합니다.
 *
 * @param action params.value: 단위당 배송비 값
 * @param context 액션 컨텍스트
 */
export function updateUnitValueHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const value = parseFloat(params.value as string) || 1;

    const G7Core = getG7Core();
    if (!G7Core) return;

    const localState = G7Core.state.getLocal?.() ?? {};
    const countryIndex = Number(localState.activeCountryTab ?? 0);
    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) return;

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = {
        ...cs,
        ranges: { ...(cs.ranges ?? {}), type: cs.charge_policy, unit_value: value },
    };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });
    logger.log('[updateUnitValue] Updated unit_value:', value);
}

/**
 * API 요청 필드를 추가합니다.
 *
 * @param action (params 없음, activeCountryTab 자동 참조)
 * @param context 액션 컨텍스트
 */
export function addApiRequestFieldHandler(
    _action: ActionWithParams,
    context: ActionContext
): void {
    const G7Core = getG7Core();
    if (!G7Core) return;

    const localState = G7Core.state.getLocal?.() ?? {};
    const countryIndex = Number(localState.activeCountryTab ?? 0);
    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) return;

    const currentFields = [...(cs.api_request_fields ?? [])];
    currentFields.push('');

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = { ...cs, api_request_fields: currentFields };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });
    logger.log('[addApiRequestField] Added field, total:', currentFields.length);
}

/**
 * API 요청 필드를 수정합니다.
 *
 * @param action params.fieldIndex: 수정할 필드 인덱스, params.value: 새 값
 * @param context 액션 컨텍스트
 */
export function updateApiRequestFieldHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const fieldIndex = Number(params.fieldIndex);
    const value = (params.value ?? '') as string;

    if (isNaN(fieldIndex) || fieldIndex < 0) {
        logger.warn('[updateApiRequestField] Invalid fieldIndex:', params.fieldIndex);
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const localState = G7Core.state.getLocal?.() ?? {};
    const countryIndex = Number(localState.activeCountryTab ?? 0);
    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) return;

    const currentFields = [...(cs.api_request_fields ?? [])];
    if (fieldIndex >= currentFields.length) return;

    currentFields[fieldIndex] = value;

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = { ...cs, api_request_fields: currentFields };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });
    logger.log('[updateApiRequestField] Updated index:', fieldIndex, 'value:', value);
}

/**
 * API 요청 필드를 삭제합니다.
 *
 * @param action params.fieldIndex: 삭제할 필드 인덱스
 * @param context 액션 컨텍스트
 */
export function removeApiRequestFieldHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const fieldIndex = Number(params.fieldIndex);

    if (isNaN(fieldIndex) || fieldIndex < 0) {
        logger.warn('[removeApiRequestField] Invalid fieldIndex:', params.fieldIndex);
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const localState = G7Core.state.getLocal?.() ?? {};
    const countryIndex = Number(localState.activeCountryTab ?? 0);
    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) return;

    const currentFields = [...(cs.api_request_fields ?? [])];
    if (fieldIndex >= currentFields.length) return;

    currentFields.splice(fieldIndex, 1);

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = { ...cs, api_request_fields: currentFields };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });
    logger.log('[removeApiRequestField] Removed index:', fieldIndex, 'remaining:', currentFields.length);
}

/**
 * 도서산간 추가배송비 행의 필드를 수정합니다.
 *
 * @param action params.feeIndex: 행 인덱스, params.field: 필드명 ('zipcode'|'fee'|'region'), params.value: 새 값
 * @param context 액션 컨텍스트
 */
export function updateExtraFeeFieldHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const feeIndex = Number(params.feeIndex);
    const field = params.field as 'zipcode' | 'fee' | 'region';
    const rawValue = params.value;

    if (isNaN(feeIndex) || feeIndex < 0) {
        logger.warn('[updateExtraFeeField] Invalid feeIndex:', params.feeIndex);
        return;
    }
    if (!['zipcode', 'fee', 'region'].includes(field)) {
        logger.warn('[updateExtraFeeField] Invalid field:', field);
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const localState = G7Core.state.getLocal?.() ?? {};
    const countryIndex = Number(localState.activeCountryTab ?? 0);
    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[countryIndex];
    if (!cs) return;

    const currentSettings = [...(cs.extra_fee_settings ?? [])];
    if (feeIndex >= currentSettings.length) return;

    const value = field === 'fee' ? (parseFloat(rawValue as string) || 0) : rawValue;
    currentSettings[feeIndex] = { ...currentSettings[feeIndex], [field]: value };

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = { ...cs, extra_fee_settings: currentSettings };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });
    logger.log('[updateExtraFeeField] Updated index:', feeIndex, field, '=', value);
}
