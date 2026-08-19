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
    ranges: { type?: string; tiers?: RangeTier[]; unit_value?: number | null } | null;
    api_endpoint: string | null;
    api_request_fields: string[] | null;
    api_response_fee_field: string | null;
    api_config: ApiConfig | null;
    extra_fee_enabled: boolean;
    extra_fee_settings: Array<{ zipcode: string; fee: number; region?: string }> | null;
    extra_fee_multiply: boolean;
    is_active: boolean;
}

interface RangeTier {
    /** 시작값 — 직전 구간의 종료값에서 자동 파생되며 화면에서는 읽기전용 표시 */
    min: number | null;
    max: number | null;
    fee: number | null;
}

interface ApiConfig {
    http_method?: string;
    auth_type?: string;
    auth_token?: string | null;
    auth_header_name?: string | null;
    response_type?: string;
    response_path?: string | null;
    field_map?: Record<string, string> | null;
    has_auth_token?: boolean;
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

/**
 * 구간 경계값이 이산형(정수)인 정책.
 *
 * 수량은 "5개 다음이 6개"(max + 1)로 이어지고, 금액·무게·부피는 연속값이라
 * "5kg 다음은 5kg 초과"(다음 min = max)로 이어진다. 서버 검증(ChargePolicyEnum::
 * hasDiscreteRangeValues)과 같은 판정을 사용해야 화면과 저장 결과가 어긋나지 않는다.
 */
const DISCRETE_RANGE_POLICIES = ['range_quantity'];

/** 연속형 구간 경계 비교 허용 오차 (서버 검증과 동일) */
const CONTINUITY_EPSILON = 0.001;

/**
 * 부과정책이 이산형 구간을 쓰는지 판정합니다.
 *
 * @param chargePolicy 부과정책 값
 * @returns 이산형이면 true
 */
function isDiscreteRangePolicy(chargePolicy: string): boolean {
    return DISCRETE_RANGE_POLICIES.includes(chargePolicy);
}

/**
 * 직전 구간의 종료값으로부터 다음 구간의 시작값을 파생합니다.
 *
 * @param previousMax 직전 구간 종료값
 * @param chargePolicy 부과정책 값
 * @returns 파생된 시작값 (종료값 미입력 시 null)
 */
function deriveNextMin(previousMax: number | null | undefined, chargePolicy: string): number | null {
    if (previousMax === null || previousMax === undefined || Number.isNaN(previousMax)) {
        return null;
    }

    return isDiscreteRangePolicy(chargePolicy) ? previousMax + 1 : previousMax;
}

/**
 * 구간 배열의 시작값을 직전 구간 종료값 기준으로 전부 재파생합니다.
 *
 * 첫 구간은 항상 0 이며, 이후 구간은 직전 종료값에서 파생됩니다.
 *
 * @param tiers 구간 배열
 * @param chargePolicy 부과정책 값
 * @returns 시작값이 재파생된 새 구간 배열
 */
function rederiveTierMins(tiers: RangeTier[], chargePolicy: string): RangeTier[] {
    return tiers.map((tier, index) => ({
        ...tier,
        min: index === 0 ? 0 : deriveNextMin(tiers[index - 1]?.max, chargePolicy),
    }));
}

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
    api_config: null,
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

        // 저장된 구간의 시작값을 현재 계약으로 재파생한다.
        // 기존 데이터는 "1~5개 / 6개~" 처럼 첫 시작값이 1 인 형태가 있는데, 시작값이
        // 읽기전용이 된 이상 화면이 정규화하지 않으면 운영자가 고칠 수단이 없고
        // 그대로 저장하면 "첫 구간의 시작값은 0이어야 합니다" 로 거부된다.
        const normalized = countrySettings.map((cs: any) => {
            const tiers = cs?.ranges?.tiers;

            if (!Array.isArray(tiers) || tiers.length === 0) {
                return cs;
            }

            return {
                ...cs,
                ranges: { ...cs.ranges, tiers: rederiveTierMins(tiers, cs.charge_policy) },
            };
        });

        stateUpdates['form.country_settings'] = normalized;

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

    // 무료배송 기준금액은 비면 배송비가 계산되지 않으므로 저장 전에 화면에서 먼저 알린다
    if (field === 'free_threshold') {
        validateChargeSettingRequirements(G7Core, context, index, countrySettings[index]);
    }

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
        updatedCS.api_config = null;
    }

    // ranges 초기화 (구간 정책 선택 시 기본 구조 제공)
    // 배송비는 빈 값으로 두어 미입력 상태가 required 검증에 걸리도록 한다
    // (0 으로 채우면 운영자가 입력하지 않은 구간이 무료배송으로 저장된다).
    if (flags.showRanges) {
        if (!updatedCS.ranges || !updatedCS.ranges.tiers) {
            const rangeType = chargePolicy.replace('range_', '');
            updatedCS.ranges = {
                type: rangeType,
                tiers: [{ min: 0, max: null, fee: null }],
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

    // 정책이 바뀌면 요구되는 필수값도 바뀐다 — 직전 정책에서 남은 오류를 지우고 새 요구사항을 반영
    validateChargeSettingRequirements(G7Core, context, countryIndex, updatedCS);
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

    // 직전까지 마지막이던 구간은 이제 종료값이 필요하다 (무제한은 새 구간이 이어받는다).
    // 종료값은 비운 상태로 두어 운영자 입력을 기다리고, 새 구간의 시작값은 그 입력 시점에 파생된다.
    const currentTiers = [
        ...(cs.ranges?.tiers ?? []),
        { min: null, max: null, fee: null } as RangeTier,
    ];
    const nextTiers = rederiveTierMins(currentTiers, cs.charge_policy);

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = {
        ...cs,
        ranges: { ...(cs.ranges ?? {}), tiers: nextTiers },
    };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });

    // 구간 검증 실행
    validateRangeTiersInternal(G7Core, context, countryIndex, nextTiers, cs.country_code, cs.charge_policy);
    logger.log('[addRangeTier] Added tier, total:', nextTiers.length);
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

    // 삭제로 앞뒤 구간이 이어졌으므로 시작값을 다시 파생한다
    const nextTiers = rederiveTierMins(currentTiers, cs.charge_policy);

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = {
        ...cs,
        ranges: { ...(cs.ranges ?? {}), tiers: nextTiers },
    };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });

    // 구간 검증 실행
    validateRangeTiersInternal(G7Core, context, countryIndex, nextTiers, cs.country_code, cs.charge_policy);
    logger.log('[removeRangeTier] Removed tier:', tierIndex, 'remaining:', nextTiers.length);
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
    // 빈 값은 null 로 보존한다 — 0 으로 강제하면 미입력 구간이 무료배송으로 저장된다.
    const isEmpty = value === '' || value === null || value === undefined;
    const parsedValue: number | null = isEmpty ? null : Number(value);

    currentTiers[tierIndex] = { ...currentTiers[tierIndex], [field]: parsedValue };

    // 종료값이 바뀌면 뒤따르는 구간의 시작값을 자동 재파생한다 (시작값은 입력 대상이 아니다)
    const nextTiers = field === 'max'
        ? rederiveTierMins(currentTiers, cs.charge_policy)
        : currentTiers;

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = {
        ...cs,
        ranges: { ...(cs.ranges ?? {}), tiers: nextTiers },
    };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });

    // 구간 검증 실행
    validateRangeTiersInternal(G7Core, context, countryIndex, nextTiers, cs.country_code, cs.charge_policy);
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
    validateRangeTiersInternal(G7Core, context, countryIndex, tiers, cs.country_code, cs.charge_policy);
}

/**
 * 구간 검증 내부 로직
 *
 * 서버(StoreShippingPolicyRequest::validateRangeTiersContinuity)와 동일한 규칙을 적용합니다.
 * 한쪽만 바뀌면 화면에서 통과한 설정이 저장 시 422 로 거부되거나, 그 반대가 됩니다.
 */
function validateRangeTiersInternal(
    G7Core: any,
    context: ActionContext,
    _countryIndex: number,
    tiers: RangeTier[],
    countryCode: string,
    chargePolicy: string
): void {
    const localState = G7Core.state.getLocal?.() ?? {};
    const rangeErrors: Record<string, RangeTierError[]> = { ...(localState.rangeErrors ?? {}) };
    const t = G7Core.t;

    if (tiers.length === 0) {
        // 구간별 정책인데 구간이 하나도 없으면 모든 주문이 0원(무료배송)이 된다
        rangeErrors[countryCode] = REQUIRES_RANGES.includes(chargePolicy)
            ? [{
                max: t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_min_one')
                    ?? '구간별 배송비 정책은 구간을 1개 이상 등록해야 합니다.',
            }]
            : [];
        updateLocalState(context, { rangeErrors });
        return;
    }

    const tierErrors: RangeTierError[] = new Array(tiers.length).fill(null).map(() => ({}));
    let hasError = false;
    const isDiscrete = isDiscreteRangePolicy(chargePolicy);
    const isUnlimited = (max: number | null | undefined) => max === null || max === undefined;

    for (let i = 0; i < tiers.length; i++) {
        const tier = tiers[i];
        const isLast = i === tiers.length - 1;

        // 첫 구간 min은 0이어야 함
        if (i === 0 && Number(tier.min ?? 0) !== 0) {
            tierErrors[i].min = t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_first_min')
                ?? '첫 구간의 시작값은 0이어야 합니다.';
            hasError = true;
        }

        // 마지막 구간 max는 비어 있어야 함 (무제한)
        if (isLast && !isUnlimited(tier.max)) {
            tierErrors[i].max = t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_last_max')
                ?? '마지막 구간의 종료값은 비워야 합니다.';
            hasError = true;
            continue;
        }

        if (isLast) {
            // 마지막 구간은 아래 종료값 기반 검사 대상이 아니다
            if (tier.fee === null || tier.fee === undefined) {
                tierErrors[i].fee = t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_fee_required')
                    ?? '구간 배송비를 입력해주세요.';
                hasError = true;
            } else if (tier.fee < 0) {
                tierErrors[i].fee = t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_fee')
                    ?? '배송비는 0 이상이어야 합니다.';
                hasError = true;
            }
            continue;
        }

        // 중간 구간의 종료값 누락 금지 (뒤 구간이 영구히 도달 불가해진다)
        if (isUnlimited(tier.max)) {
            tierErrors[i].max = t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_middle_max')
                ?? '마지막 구간을 제외한 구간에는 종료값을 입력해야 합니다.';
            hasError = true;
        } else {
            const max = Number(tier.max);

            // 수량 구간의 경계값은 정수
            if (isDiscrete && !Number.isInteger(max)) {
                tierErrors[i].max = t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_integer')
                    ?? '수량 구간의 시작값과 종료값은 정수여야 합니다.';
                hasError = true;
            }

            // min < max
            if (Number(tier.min ?? 0) >= max) {
                tierErrors[i].min = t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_min_max')
                    ?? '시작값이 종료값보다 작아야 합니다.';
                hasError = true;
            }

            // 구간 연속성 (이산형은 max + 1, 연속형은 max 가 다음 min)
            const expectedNextMin = isDiscrete ? max + 1 : max;
            const actualNextMin = Number(tiers[i + 1]?.min ?? 0);
            if (Math.abs(expectedNextMin - actualNextMin) >= CONTINUITY_EPSILON) {
                tierErrors[i].max = t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_continuity')
                    ?? '구간이 연속적이지 않습니다.';
                hasError = true;
            }
        }

        // 배송비 필수 + 0 이상
        if (tier.fee === null || tier.fee === undefined) {
            tierErrors[i].fee = t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_fee_required')
                ?? '구간 배송비를 입력해주세요.';
            hasError = true;
        } else if (tier.fee < 0) {
            tierErrors[i].fee = t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_fee')
                ?? '배송비는 0 이상이어야 합니다.';
            hasError = true;
        }
    }

    // deepMerge에서 delete로 키를 제거할 수 없으므로, 에러 없음 시 빈 배열로 대체
    rangeErrors[countryCode] = hasError ? tierErrors : [];

    updateLocalState(context, { rangeErrors });
}

/**
 * 폼 레벨 오류 키를 만듭니다.
 *
 * 서버 422 응답의 키 형태(`country_settings.{index}.{field}`)와 동일해야 레이아웃의 기존
 * 오류 표시가 클라이언트 검증 결과에도 그대로 쓰인다.
 *
 * @param countryIndex 국가 탭 인덱스
 * @param field 필드 경로
 * @returns 오류 맵 키
 */
function chargeSettingErrorKey(countryIndex: number, field: string): string {
    return `country_settings.${countryIndex}.${field}`;
}

/**
 * 부과정책이 요구하는 폼 레벨 필수값(단위값 / 무료배송 기준금액)을 선제 검증합니다.
 *
 * 서버(StoreShippingPolicyRequest::validatePolicyRequiredSettings)와 같은 규칙을 화면에서
 * 먼저 적용해, 저장을 눌러 422 를 받기 전에 빈 값이 드러나게 한다. 이 두 값이 비면 배송비가
 * 계산되지 않아 조용히 0원이 되므로 서버도 저장을 거부한다.
 *
 * 오류 없음은 `null` 로 기록한다 — deepMerge 로는 키를 제거할 수 없고, 빈 배열은 레이아웃의
 * `if` 조건에서 truthy 라 빈 오류 문구가 남는다.
 *
 * @param G7Core G7Core 전역 객체
 * @param context 액션 컨텍스트
 * @param countryIndex 국가 탭 인덱스
 * @param cs 검증 대상 국가별 설정
 */
function validateChargeSettingRequirements(
    G7Core: any,
    context: ActionContext,
    countryIndex: number,
    cs: CountrySetting
): void {
    const localState = G7Core.state.getLocal?.() ?? {};
    const errors: Record<string, any> = { ...(localState.errors ?? {}) };
    const t = G7Core.t;

    const unitValue = cs.ranges?.unit_value;
    const unitValueMissing = REQUIRES_UNIT_VALUE.includes(cs.charge_policy)
        && (unitValue === null || unitValue === undefined || Number(unitValue) <= 0);

    errors[chargeSettingErrorKey(countryIndex, 'ranges.unit_value')] = unitValueMissing
        ? [t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_unit_value_required')
            ?? '단위당 배송비 정책은 단위값을 입력해야 합니다.']
        : null;

    const freeThreshold = cs.free_threshold;
    const freeThresholdMissing = REQUIRES_FREE_THRESHOLD.includes(cs.charge_policy)
        && (freeThreshold === null || freeThreshold === undefined || String(freeThreshold) === '');

    errors[chargeSettingErrorKey(countryIndex, 'free_threshold')] = freeThresholdMissing
        ? [t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_free_threshold_required')
            ?? '조건부 무료배송 정책은 무료배송 기준금액을 입력해야 합니다.']
        : null;

    updateLocalState(context, { errors });
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
    const raw = params.value;
    // 빈 값은 null 로 보존한다 — 1 로 강제하면 미입력이 조용히 유효값이 되어 서버 필수 검증을 우회한다.
    const isEmpty = raw === '' || raw === null || raw === undefined;
    const parsed = parseFloat(raw as string);
    const value: number | null = isEmpty || Number.isNaN(parsed) ? null : parsed;

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

    // 단위값이 비거나 0 이하이면 배송비가 계산되지 않으므로 저장 전에 화면에서 먼저 알린다
    validateChargeSettingRequirements(G7Core, context, countryIndex, countrySettings[countryIndex]);

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
 * API 요청 참고 필드 후보를 토글합니다 (체크박스 선택/해제).
 *
 * 후보 5종(policy_id/country_code/items/group_total/total_quantity) 중 하나를
 * api_request_fields 배열에 추가하거나 제거합니다. 자유 텍스트 입력을 대체하여
 * 시스템이 지원하지 않는 필드명 입력(silent drop)을 원천 차단합니다.
 *
 * @param action params.field: 토글할 후보 필드 값
 * @param context 액션 컨텍스트
 */
export function toggleApiRequestFieldHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const field = (params.field ?? '') as string;
    if (!field) {
        logger.warn('[toggleApiRequestField] Missing field param');
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
    const existingIndex = currentFields.indexOf(field);

    if (existingIndex >= 0) {
        currentFields.splice(existingIndex, 1);
    } else {
        currentFields.push(field);
    }

    // 빈 배열은 null 로 정규화 (전체 전송 = 현 동작 유지)
    const nextFields = currentFields.length > 0 ? currentFields : null;

    // 새 객체 생성 (원본 mutation 방지 — deepMerge 변경 감지를 위해 필수)
    countrySettings[countryIndex] = { ...cs, api_request_fields: nextFields };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });
    logger.log('[toggleApiRequestField] Toggled:', field, 'selected:', nextFields);
}

/**
 * 계산 API 고급 설정(api_config)의 개별 필드를 업데이트합니다.
 *
 * api_config 는 중첩 객체이므로 updateCountryField(평면 필드)와 별도로 처리합니다.
 *
 * @param action params.field: api_config 하위 키, params.value: 새 값
 * @param context 액션 컨텍스트
 */
export function updateApiConfigFieldHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const field = (params.field ?? '') as string;
    if (!field) {
        logger.warn('[updateApiConfigField] Missing field param');
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const localState = G7Core.state.getLocal?.() ?? {};
    const index = Number(localState.activeCountryTab ?? 0);
    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[index];
    if (!cs) return;

    const nextConfig: ApiConfig = { ...(cs.api_config ?? {}), [field]: params.value };

    // 새 객체 생성 (deepMerge 변경 감지)
    countrySettings[index] = { ...cs, api_config: nextConfig };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });
    logger.log('[updateApiConfigField] Updated', field, 'at index:', index);
}

/**
 * 계산 API 요청 필드의 외부 키 매핑(field_map)을 업데이트합니다.
 *
 * @param action params.field: 우리 키(후보), params.value: 외부 키 이름(빈 값이면 매핑 제거)
 * @param context 액션 컨텍스트
 */
export function updateApiFieldMapHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const params = action.params || {};
    const field = (params.field ?? '') as string;
    if (!field) {
        logger.warn('[updateApiFieldMap] Missing field param');
        return;
    }

    const G7Core = getG7Core();
    if (!G7Core) return;

    const localState = G7Core.state.getLocal?.() ?? {};
    const index = Number(localState.activeCountryTab ?? 0);
    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[index];
    if (!cs) return;

    const externalKey = (params.value ?? '') as string;
    const fieldMap: Record<string, string> = { ...(cs.api_config?.field_map ?? {}) };

    if (externalKey.trim() === '') {
        delete fieldMap[field];
    } else {
        fieldMap[field] = externalKey;
    }

    const nextConfig: ApiConfig = {
        ...(cs.api_config ?? {}),
        field_map: Object.keys(fieldMap).length > 0 ? fieldMap : null,
    };

    countrySettings[index] = { ...cs, api_config: nextConfig };

    updateLocalState(context, {
        'form.country_settings': countrySettings,
    });
    logger.log('[updateApiFieldMap] Mapped', field, '→', externalKey || '(removed)');
}

/**
 * 현재 입력 중인 계산 API 설정으로 외부 API 를 테스트 호출합니다.
 *
 * 백엔드 test-api-call 엔드포인트로 요청하고 결과를 _global.apiTestResult 에 저장합니다.
 *
 * @param action 액션 정의
 * @param context 액션 컨텍스트
 */
export function testShippingApiHandler(
    action: ActionWithParams,
    context: ActionContext
): void {
    const G7Core = getG7Core();
    if (!G7Core) return;

    const localState = G7Core.state.getLocal?.() ?? {};
    const index = Number(localState.activeCountryTab ?? 0);
    const countrySettings = getCountrySettings(G7Core);
    const cs = countrySettings[index];
    if (!cs) return;

    if (!cs.api_endpoint) {
        G7Core.toast?.warning?.(G7Core.t?.('sirsoft-ecommerce.admin.shipping_policy.form.api_test_endpoint_required') ?? 'Endpoint required');
        return;
    }

    // 전역 상태 설정은 객체 형태로 넘긴다 (set 의 첫 인자는 updates 객체)
    G7Core.state.set?.({ apiTestLoading: true, apiTestResult: null });

    // apiCall 구조: URL 은 액션 top-level target, method/body 는 params,
    // onSuccess/onError 도 top-level (코어 apiCall 규약). 백엔드 test-api-call
    // 엔드포인트가 사용자가 입력한 설정으로 외부 API 를 대신 호출하고 결과를 서빙한다.
    G7Core.dispatch({
        handler: 'apiCall',
        target: '/api/modules/sirsoft-ecommerce/admin/shipping-policies/test-api-call',
        auth_required: true,
        params: {
            method: 'POST',
            body: {
                endpoint: cs.api_endpoint,
                request_fields: cs.api_request_fields,
                config: cs.api_config ?? {},
                sample: {
                    country_code: cs.country_code,
                },
            },
        },
        onSuccess: {
            handler: 'setState',
            params: {
                target: 'global',
                apiTestLoading: false,
                apiTestResult: '{{response.data}}',
            },
        },
        onError: {
            handler: 'setState',
            params: {
                target: 'global',
                apiTestLoading: false,
                apiTestResult: '{{error.errors ?? error}}',
            },
        },
    });
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
