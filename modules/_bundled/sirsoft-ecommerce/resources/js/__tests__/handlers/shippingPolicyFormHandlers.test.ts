/**
 * shippingPolicyFormHandlers 테스트
 *
 * @description
 * 배송정책 폼 핸들러 12개 전체 테스트:
 * - initShippingPolicyFormHandler: 폼 초기화 (등록/수정 모드)
 * - addCountrySettingHandler: 국가별 설정 추가
 * - removeCountrySettingHandler: 국가별 설정 삭제
 * - switchCountryTabHandler: 국가 탭 전환
 * - onChargePolicyChangeHandler: 부과정책 변경 시 가시성 플래그
 * - addRangeTierHandler: 구간 tier 추가
 * - removeRangeTierHandler: 구간 tier 삭제
 * - updateRangeTierFieldHandler: 구간 tier 필드 업데이트
 * - validateRangeTiersHandler: 구간 tier 검증
 * - addExtraFeeRowHandler: 도서산간 추가배송비 행 추가
 * - removeExtraFeeRowHandler: 도서산간 추가배송비 행 삭제
 * - applyExtraFeeTemplateHandler: 도서산간 추가배송비 템플릿 적용
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
    initShippingPolicyFormHandler,
    addCountrySettingHandler,
    removeCountrySettingHandler,
    switchCountryTabHandler,
    onChargePolicyChangeHandler,
    addRangeTierHandler,
    removeRangeTierHandler,
    updateRangeTierFieldHandler,
    validateRangeTiersHandler,
    addExtraFeeRowHandler,
    removeExtraFeeRowHandler,
    applyExtraFeeTemplateHandler,
} from '../../handlers/shippingPolicyFormHandlers';

// ===== 헬퍼: 중첩 경로에 값 설정 =====

/**
 * 중첩 객체 경로에 값을 설정합니다.
 * 예: setNestedValue(obj, 'country_settings[0].ranges', value)
 */
function setNestedValue(obj: Record<string, any>, path: string, value: any): void {
    // 경로를 토큰으로 분해: 'country_settings[0].ranges' → ['country_settings', '0', 'ranges']
    const tokens = path.replace(/\[(\d+)\]/g, '.$1').split('.');
    let current = obj;

    for (let i = 0; i < tokens.length - 1; i++) {
        const token = tokens[i];
        const nextToken = tokens[i + 1];
        const isNextIndex = /^\d+$/.test(nextToken);

        if (current[token] === undefined || current[token] === null) {
            current[token] = isNextIndex ? [] : {};
        }
        current = current[token];
    }

    const lastToken = tokens[tokens.length - 1];
    current[lastToken] = value;
}

// ===== G7Core mock =====

let mockLocalState: Record<string, any> = {};

const mockSetLocal = vi.fn((updates: Record<string, any>) => {
    for (const [key, value] of Object.entries(updates)) {
        if (key.startsWith('form.')) {
            const path = key.replace('form.', '');
            if (!mockLocalState.form) mockLocalState.form = {};
            setNestedValue(mockLocalState.form, path, value);
        } else {
            mockLocalState[key] = value;
        }
    }
});

const mockToastSuccess = vi.fn();

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: mockSetLocal,
    },
    createLogger: () => ({
        log: vi.fn(),
        warn: vi.fn(),
        error: vi.fn(),
    }),
    t: (key: string) => key,
    toast: {
        success: mockToastSuccess,
    },
};

const mockContext = {
    setLocalState: vi.fn((updates: Record<string, any>) => {
        mockSetLocal(updates);
    }),
} as any;

function createAction(params: Record<string, any> = {}) {
    return { handler: 'test', params };
}

// ===== 국가별 설정 기본값 (핸들러 내부 DEFAULT_COUNTRY_SETTING과 동일) =====

function createDefaultCountrySetting(countryCode: string) {
    return {
        country_code: countryCode,
        shipping_method: 'parcel',
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
}

describe('shippingPolicyFormHandlers', () => {
    beforeEach(() => {
        mockLocalState = {};
        mockSetLocal.mockClear();
        mockContext.setLocalState.mockClear();
        mockToastSuccess.mockClear();
        (window as any).G7Core = mockG7Core;
    });

    // ===== initShippingPolicyFormHandler =====

    describe('initShippingPolicyFormHandler', () => {
        it('수정 모드: 첫 번째 country_settings의 charge_policy 기반 가시성 설정', () => {
            initShippingPolicyFormHandler(
                createAction({
                    isEdit: true,
                    policy: {
                        country_settings: [
                            { country_code: 'KR', charge_policy: 'conditional_free' },
                        ],
                    },
                }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.activeCountryTab).toBe(0);
            expect(updates.rangeErrors).toEqual({});
            expect(updates.showBaseFee).toBe(true);
            expect(updates.showFreeThreshold).toBe(true);
            expect(updates.showRanges).toBe(false);
            expect(updates.showApiSettings).toBe(false);
            expect(updates.showUnitValue).toBe(false);
        });

        it('수정 모드 + 다중 국가: activeCountryTab=0, 첫 국가 기준 가시성', () => {
            initShippingPolicyFormHandler(
                createAction({
                    isEdit: true,
                    policy: {
                        country_settings: [
                            { country_code: 'KR', charge_policy: 'range_weight' },
                            { country_code: 'US', charge_policy: 'fixed' },
                            { country_code: 'JP', charge_policy: 'api' },
                        ],
                    },
                }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.activeCountryTab).toBe(0);
            // 첫 번째 국가(KR)의 range_weight 기준
            expect(updates.showRanges).toBe(true);
            expect(updates.showBaseFee).toBe(false);
            expect(updates.showApiSettings).toBe(false);
        });

        it('등록 모드: fixed 기준 기본 가시성', () => {
            initShippingPolicyFormHandler(
                createAction({ isEdit: false }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.activeCountryTab).toBe(0);
            expect(updates.rangeErrors).toEqual({});
            expect(updates.showBaseFee).toBe(true);
            expect(updates.showFreeThreshold).toBe(false);
            expect(updates.showRanges).toBe(false);
            expect(updates.showApiSettings).toBe(false);
            expect(updates.showUnitValue).toBe(false);
        });

        it('policy 데이터 없음: fixed 기준 fallback', () => {
            initShippingPolicyFormHandler(
                createAction({ isEdit: true, policy: undefined }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            // isEdit && policy가 falsy이므로 else 분기 (등록 모드 fallback)
            expect(updates.showBaseFee).toBe(true);
            expect(updates.showRanges).toBe(false);
        });

        it('availableCountries 파라미터 전달 시 오류 없음', () => {
            expect(() => {
                initShippingPolicyFormHandler(
                    createAction({
                        isEdit: false,
                        availableCountries: [
                            { code: 'KR', name: { ko: '한국' }, is_active: true },
                            { code: 'US', name: { ko: '미국' }, is_active: true },
                        ],
                    }),
                    mockContext
                );
            }).not.toThrow();

            expect(mockContext.setLocalState).toHaveBeenCalled();
        });
    });

    // ===== addCountrySettingHandler =====

    describe('addCountrySettingHandler', () => {
        it('빈 배열에 첫 번째 국가 추가 → defaults + country_code', () => {
            mockLocalState = { form: { country_settings: [] } };

            addCountrySettingHandler(
                createAction({ country_code: 'KR' }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const cs = updates['form.country_settings'];
            expect(cs).toHaveLength(1);
            expect(cs[0].country_code).toBe('KR');
            expect(cs[0].shipping_method).toBe('parcel');
            expect(cs[0].charge_policy).toBe('fixed');
            expect(cs[0].base_fee).toBe(0);
            expect(cs[0].is_active).toBe(true);
            expect(updates.activeCountryTab).toBe(0);
        });

        it('두 번째 국가 추가 → activeCountryTab이 새 인덱스로 전환', () => {
            mockLocalState = {
                form: {
                    country_settings: [createDefaultCountrySetting('KR')],
                },
            };

            addCountrySettingHandler(
                createAction({ country_code: 'US' }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const cs = updates['form.country_settings'];
            expect(cs).toHaveLength(2);
            expect(cs[1].country_code).toBe('US');
            expect(updates.activeCountryTab).toBe(1);
        });

        it('중복 country_code → 아무 동작 안함 (길이 변화 없음)', () => {
            mockLocalState = {
                form: {
                    country_settings: [createDefaultCountrySetting('KR')],
                },
            };

            addCountrySettingHandler(
                createAction({ country_code: 'KR' }),
                mockContext
            );

            expect(mockContext.setLocalState).not.toHaveBeenCalled();
        });
    });

    // ===== removeCountrySettingHandler =====

    describe('removeCountrySettingHandler', () => {
        it('중간 인덱스 삭제 → 배열 무결성 + activeCountryTab 조정', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        createDefaultCountrySetting('KR'),
                        createDefaultCountrySetting('US'),
                        createDefaultCountrySetting('JP'),
                    ],
                },
                activeCountryTab: 2,
                rangeErrors: {},
            };

            removeCountrySettingHandler(
                createAction({ index: 1 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const cs = updates['form.country_settings'];
            expect(cs).toHaveLength(2);
            expect(cs[0].country_code).toBe('KR');
            expect(cs[1].country_code).toBe('JP');
            // activeCountryTab=2 이고 index=1을 삭제 → currentTab > index이므로 newTab = 2-1 = 1
            expect(updates.activeCountryTab).toBe(1);
        });

        it('마지막 인덱스 삭제 → activeCountryTab이 이전으로 이동', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        createDefaultCountrySetting('KR'),
                        createDefaultCountrySetting('US'),
                    ],
                },
                activeCountryTab: 1,
                rangeErrors: {},
            };

            removeCountrySettingHandler(
                createAction({ index: 1 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const cs = updates['form.country_settings'];
            expect(cs).toHaveLength(1);
            // currentTab(1) >= length(1) → newTab = 0
            expect(updates.activeCountryTab).toBe(0);
        });

        it('활성 탭 삭제 → activeCountryTab 재계산', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        createDefaultCountrySetting('KR'),
                        createDefaultCountrySetting('US'),
                        createDefaultCountrySetting('JP'),
                    ],
                },
                activeCountryTab: 1,
                rangeErrors: {},
            };

            removeCountrySettingHandler(
                createAction({ index: 1 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const cs = updates['form.country_settings'];
            expect(cs).toHaveLength(2);
            // currentTab(1) >= length(2)는 false, currentTab(1) > index(1)는 false → newTab = 1 그대로
            // 하지만 index=1삭제 후 length=2이고 currentTab=1 < length이므로 조건 불만족
            expect(updates.activeCountryTab).toBe(1);
        });

        it('삭제된 국가의 rangeErrors 초기화 (deepMerge 호환: 빈 배열)', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        createDefaultCountrySetting('KR'),
                        createDefaultCountrySetting('US'),
                    ],
                },
                activeCountryTab: 0,
                rangeErrors: {
                    KR: [{ min: '오류' }],
                    US: [{ fee: '오류' }],
                },
            };

            removeCountrySettingHandler(
                createAction({ index: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.rangeErrors.KR).toEqual([]);
            expect(updates.rangeErrors).toHaveProperty('US');
        });
    });

    // ===== switchCountryTabHandler =====

    describe('switchCountryTabHandler', () => {
        it('탭 전환 → activeCountryTab 업데이트', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        createDefaultCountrySetting('KR'),
                        createDefaultCountrySetting('US'),
                    ],
                },
            };

            switchCountryTabHandler(
                createAction({ index: 1 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.activeCountryTab).toBe(1);
        });

        it('대상 국가의 charge_policy 기반 가시성 재계산', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        createDefaultCountrySetting('KR'),
                        { ...createDefaultCountrySetting('US'), charge_policy: 'api' },
                    ],
                },
            };

            switchCountryTabHandler(
                createAction({ index: 1 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.showApiSettings).toBe(true);
            expect(updates.showBaseFee).toBe(false);
            expect(updates.showRanges).toBe(false);
        });

        it('범위 밖 인덱스 → 아무 동작 안함', () => {
            mockLocalState = {
                form: {
                    country_settings: [createDefaultCountrySetting('KR')],
                },
            };

            switchCountryTabHandler(
                createAction({ index: 5 }),
                mockContext
            );

            expect(mockContext.setLocalState).not.toHaveBeenCalled();
        });
    });

    // ===== onChargePolicyChangeHandler =====

    describe('onChargePolicyChangeHandler', () => {
        it('fixed → showBaseFee=true, 나머지 false', () => {
            mockLocalState = {
                form: {
                    country_settings: [createDefaultCountrySetting('KR')],
                },
            };

            onChargePolicyChangeHandler(
                createAction({ value: 'fixed', index: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.showBaseFee).toBe(true);
            expect(updates.showFreeThreshold).toBe(false);
            expect(updates.showRanges).toBe(false);
            expect(updates.showApiSettings).toBe(false);
            expect(updates.showUnitValue).toBe(false);
        });

        it('range_weight → showRanges=true, ranges 초기화 (빈 경우)', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        { ...createDefaultCountrySetting('KR'), ranges: null },
                    ],
                },
            };

            onChargePolicyChangeHandler(
                createAction({ value: 'range_weight', index: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.showRanges).toBe(true);
            expect(updates.showBaseFee).toBe(false);
            expect(updates['form.country_settings'][0].ranges).toEqual({
                type: 'weight',
                tiers: [{ min: 0, max: null, fee: 0 }],
            });
        });

        it('api → showApiSettings=true', () => {
            mockLocalState = {
                form: {
                    country_settings: [createDefaultCountrySetting('KR')],
                },
            };

            onChargePolicyChangeHandler(
                createAction({ value: 'api', index: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.showApiSettings).toBe(true);
            expect(updates.showBaseFee).toBe(false);
            expect(updates.showRanges).toBe(false);
        });

        it('per_quantity → showUnitValue=true, showBaseFee=true', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        { ...createDefaultCountrySetting('KR'), ranges: null },
                    ],
                },
            };

            onChargePolicyChangeHandler(
                createAction({ value: 'per_quantity', index: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.showBaseFee).toBe(true);
            expect(updates.showUnitValue).toBe(true);
            expect(updates.showRanges).toBe(false);
            expect(updates['form.country_settings'][0].ranges).toEqual({
                type: 'per_quantity',
                unit_value: 1,
            });
        });

        it('불필요한 필드 초기화 (range → fixed 전환)', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            charge_policy: 'range_weight',
                            ranges: { type: 'weight', tiers: [{ min: 0, max: null, fee: 3000 }] },
                            api_endpoint: 'https://example.com',
                        },
                    ],
                },
            };

            onChargePolicyChangeHandler(
                createAction({ value: 'fixed', index: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.showBaseFee).toBe(true);
            // ranges 초기화 (showRanges=false && showUnitValue=false)
            expect(updates['form.country_settings'][0].ranges).toBeNull();
            // API 필드 초기화
            expect(updates['form.country_settings'][0].api_endpoint).toBeNull();
            expect(updates['form.country_settings'][0].api_request_fields).toBeNull();
            expect(updates['form.country_settings'][0].api_response_fee_field).toBeNull();
        });

        it('기존 ranges가 있으면 range 정책 전환 시 보존', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [{ min: 0, max: 10000, fee: 3000 }],
                            },
                        },
                    ],
                },
            };

            onChargePolicyChangeHandler(
                createAction({ value: 'range_amount', index: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.showRanges).toBe(true);
            // 기존 ranges에 tiers가 있으므로 그대로 보존
            expect(updates['form.country_settings'][0].ranges).toEqual({
                type: 'amount',
                tiers: [{ min: 0, max: 10000, fee: 3000 }],
            });
        });
    });

    // ===== addRangeTierHandler =====

    describe('addRangeTierHandler', () => {
        it('country_settings[index].ranges.tiers에 추가', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [{ min: 0, max: 10000, fee: 3000 }],
                            },
                        },
                    ],
                },
                rangeErrors: {},
            };

            addRangeTierHandler(
                createAction({ index: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const ranges = updates['form.country_settings'][0].ranges;
            expect(ranges.tiers).toHaveLength(2);
            expect(ranges.tiers[1]).toEqual({ min: 10001, max: null, fee: 0 });
        });

        it('새 tier의 min = 이전 tier의 max + 1 (포함 범위)', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'weight',
                                tiers: [
                                    { min: 0, max: 5, fee: 3000 },
                                    { min: 6, max: 20, fee: 5000 },
                                ],
                            },
                        },
                    ],
                },
                rangeErrors: {},
            };

            addRangeTierHandler(
                createAction({ index: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const ranges = updates['form.country_settings'][0].ranges;
            expect(ranges.tiers).toHaveLength(3);
            expect(ranges.tiers[2].min).toBe(21);
        });

        it('빈 tiers에 첫 추가', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: { type: 'amount', tiers: [] },
                        },
                    ],
                },
                rangeErrors: {},
            };

            addRangeTierHandler(
                createAction({ index: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const ranges = updates['form.country_settings'][0].ranges;
            expect(ranges.tiers).toHaveLength(1);
            expect(ranges.tiers[0]).toEqual({ min: 0, max: null, fee: 0 });
        });
    });

    // ===== removeRangeTierHandler =====

    describe('removeRangeTierHandler', () => {
        it('유효한 tierIndex 삭제', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [
                                    { min: 0, max: 10000, fee: 3000 },
                                    { min: 10000, max: 30000, fee: 2000 },
                                    { min: 30000, max: null, fee: 0 },
                                ],
                            },
                        },
                    ],
                },
                rangeErrors: {},
            };

            removeRangeTierHandler(
                createAction({ countryIndex: 0, tierIndex: 1 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const ranges = updates['form.country_settings'][0].ranges;
            expect(ranges.tiers).toHaveLength(2);
            expect(ranges.tiers[0].max).toBe(10000);
            expect(ranges.tiers[1].max).toBeNull();
        });

        it('범위 밖 tierIndex → 아무 동작 안함', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [{ min: 0, max: null, fee: 3000 }],
                            },
                        },
                    ],
                },
            };

            removeRangeTierHandler(
                createAction({ countryIndex: 0, tierIndex: 5 }),
                mockContext
            );

            expect(mockContext.setLocalState).not.toHaveBeenCalled();
        });

        it('삭제 후 range type 보존', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'weight',
                                tiers: [
                                    { min: 0, max: 5, fee: 3000 },
                                    { min: 5, max: null, fee: 5000 },
                                ],
                            },
                        },
                    ],
                },
                rangeErrors: {},
            };

            removeRangeTierHandler(
                createAction({ countryIndex: 0, tierIndex: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const ranges = updates['form.country_settings'][0].ranges;
            expect(ranges.type).toBe('weight');
            expect(ranges.tiers).toHaveLength(1);
        });
    });

    // ===== updateRangeTierFieldHandler =====

    describe('updateRangeTierFieldHandler', () => {
        it('min 필드 업데이트', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [{ min: 0, max: 10000, fee: 3000 }],
                            },
                        },
                    ],
                },
                rangeErrors: {},
            };

            updateRangeTierFieldHandler(
                createAction({ countryIndex: 0, tierIndex: 0, field: 'min', value: 500 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const ranges = updates['form.country_settings'][0].ranges;
            expect(ranges.tiers[0].min).toBe(500);
            // 다른 필드는 유지
            expect(ranges.tiers[0].max).toBe(10000);
            expect(ranges.tiers[0].fee).toBe(3000);
        });

        it('max를 null로 업데이트 (마지막 tier)', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [{ min: 0, max: 10000, fee: 3000 }],
                            },
                        },
                    ],
                },
                rangeErrors: {},
            };

            updateRangeTierFieldHandler(
                createAction({ countryIndex: 0, tierIndex: 0, field: 'max', value: null }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const ranges = updates['form.country_settings'][0].ranges;
            expect(ranges.tiers[0].max).toBeNull();
        });

        it('잘못된 tierIndex → 아무 동작 안함', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [{ min: 0, max: null, fee: 0 }],
                            },
                        },
                    ],
                },
            };

            updateRangeTierFieldHandler(
                createAction({ countryIndex: 0, tierIndex: 5, field: 'fee', value: 1000 }),
                mockContext
            );

            expect(mockContext.setLocalState).not.toHaveBeenCalled();
        });

        it('연속 구간 수정 시 에러가 정상 해제됨 (deepMerge 호환: 2998→2999, nextMin=3000)', () => {
            // 시나리오: 사용자가 max=2998로 입력 → 에러 발생 → max=2999로 수정 → 에러 해제
            // 근본 원인: deepMerge({}, { KR: [...] })는 KR을 제거하지 못함
            // 해결: delete 대신 빈 배열 할당
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            charge_policy: 'range_amount',
                            ranges: {
                                type: 'amount',
                                tiers: [
                                    { min: 0, max: 2998, fee: 3000 },
                                    { min: 3000, max: null, fee: 0 },
                                ],
                            },
                        },
                    ],
                },
                rangeErrors: {
                    KR: [{ max: '구간이 연속적이지 않습니다.' }, {}],
                },
            };

            // 1단계: max를 2999로 수정 (2999+1=3000=nextMin → 연속)
            updateRangeTierFieldHandler(
                createAction({ countryIndex: 0, tierIndex: 0, field: 'max', value: 2999 }),
                mockContext
            );

            // setLocalState 호출 확인: 1번째 = form.country_settings, 2번째 = rangeErrors
            expect(mockContext.setLocalState).toHaveBeenCalledTimes(2);

            // form 데이터 업데이트 확인
            const formUpdates = mockContext.setLocalState.mock.calls[0][0];
            expect(formUpdates['form.country_settings'][0].ranges.tiers[0].max).toBe(2999);

            // rangeErrors 해제 확인: KR은 빈 배열이어야 함 (delete가 아님)
            const errorUpdates = mockContext.setLocalState.mock.calls[1][0];
            expect(errorUpdates.rangeErrors.KR).toEqual([]);
        });
    });

    // ===== validateRangeTiersHandler =====

    describe('validateRangeTiersHandler', () => {
        it('빈 tiers → 오류 없음 (deepMerge 호환: 빈 배열)', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: { type: 'amount', tiers: [] },
                        },
                    ],
                },
                rangeErrors: { KR: [{ min: '이전 오류' }] },
            };

            validateRangeTiersHandler(
                createAction({ countryIndex: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.rangeErrors.KR).toEqual([]);
        });

        it('유효한 tiers → 오류 없음 (기존 오류 제거, deepMerge 호환: 빈 배열)', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [
                                    { min: 0, max: 9999, fee: 3000 },
                                    { min: 10000, max: 29999, fee: 2000 },
                                    { min: 30000, max: null, fee: 0 },
                                ],
                            },
                        },
                    ],
                },
                rangeErrors: { KR: [{ min: '이전 오류' }] },
            };

            validateRangeTiersHandler(
                createAction({ countryIndex: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.rangeErrors.KR).toEqual([]);
        });

        it('첫 구간 min !== 0 → 오류', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [{ min: 100, max: null, fee: 3000 }],
                            },
                        },
                    ],
                },
                rangeErrors: {},
            };

            validateRangeTiersHandler(
                createAction({ countryIndex: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.rangeErrors.KR).toBeDefined();
            expect(updates.rangeErrors.KR[0].min).toBeTruthy();
        });

        it('마지막 구간 max !== null → 오류', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [{ min: 0, max: 10000, fee: 3000 }],
                            },
                        },
                    ],
                },
                rangeErrors: {},
            };

            validateRangeTiersHandler(
                createAction({ countryIndex: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.rangeErrors.KR).toBeDefined();
            expect(updates.rangeErrors.KR[0].max).toBeTruthy();
        });

        it('비연속 구간 → 오류', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [
                                    { min: 0, max: 10000, fee: 3000 },
                                    { min: 20000, max: null, fee: 2000 }, // 10000+1 !== 20000
                                ],
                            },
                        },
                    ],
                },
                rangeErrors: {},
            };

            validateRangeTiersHandler(
                createAction({ countryIndex: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.rangeErrors.KR).toBeDefined();
            expect(updates.rangeErrors.KR[0].max).toBeTruthy();
        });

        it('min >= max → 오류', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [
                                    { min: 10000, max: 5000, fee: 3000 }, // min > max
                                    { min: 5001, max: null, fee: 2000 },
                                ],
                            },
                        },
                    ],
                },
                rangeErrors: {},
            };

            validateRangeTiersHandler(
                createAction({ countryIndex: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.rangeErrors.KR).toBeDefined();
            expect(updates.rangeErrors.KR[0].min).toBeTruthy();
        });

        it('fee < 0 → 오류', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            ranges: {
                                type: 'amount',
                                tiers: [{ min: 0, max: null, fee: -100 }],
                            },
                        },
                    ],
                },
                rangeErrors: {},
            };

            validateRangeTiersHandler(
                createAction({ countryIndex: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates.rangeErrors.KR).toBeDefined();
            expect(updates.rangeErrors.KR[0].fee).toBeTruthy();
        });
    });

    // ===== addExtraFeeRowHandler =====

    describe('addExtraFeeRowHandler', () => {
        it('KR 국가: extra_fee_settings에 행 추가', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        { ...createDefaultCountrySetting('KR'), extra_fee_settings: [] },
                    ],
                },
            };

            addExtraFeeRowHandler(
                createAction({ countryIndex: 0 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const settings = updates['form.country_settings'][0].extra_fee_settings;
            expect(settings).toHaveLength(1);
            expect(settings[0]).toEqual({ zipcode: '', fee: 0, region: '' });
        });

        it('KR 이외 국가 → 아무 동작 안함', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        { ...createDefaultCountrySetting('US'), extra_fee_settings: [] },
                    ],
                },
            };

            addExtraFeeRowHandler(
                createAction({ countryIndex: 0 }),
                mockContext
            );

            expect(mockContext.setLocalState).not.toHaveBeenCalled();
        });
    });

    // ===== removeExtraFeeRowHandler =====

    describe('removeExtraFeeRowHandler', () => {
        it('유효한 feeIndex 행 삭제', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            extra_fee_settings: [
                                { zipcode: '63000', fee: 3000 },
                                { zipcode: '63100', fee: 4000 },
                                { zipcode: '63200', fee: 5000 },
                            ],
                        },
                    ],
                },
            };

            removeExtraFeeRowHandler(
                createAction({ countryIndex: 0, feeIndex: 1 }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            const settings = updates['form.country_settings'][0].extra_fee_settings;
            expect(settings).toHaveLength(2);
            expect(settings[0].zipcode).toBe('63000');
            expect(settings[1].zipcode).toBe('63200');
        });

        it('잘못된 feeIndex → 아무 동작 안함', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            extra_fee_settings: [{ zipcode: '63000', fee: 3000 }],
                        },
                    ],
                },
            };

            removeExtraFeeRowHandler(
                createAction({ countryIndex: 0, feeIndex: -1 }),
                mockContext
            );

            expect(mockContext.setLocalState).not.toHaveBeenCalled();
        });
    });

    // ===== applyExtraFeeTemplateHandler =====

    describe('applyExtraFeeTemplateHandler', () => {
        it('extra_fee_settings를 템플릿으로 대체', () => {
            mockLocalState = {
                form: {
                    country_settings: [
                        {
                            ...createDefaultCountrySetting('KR'),
                            extra_fee_settings: [{ zipcode: '63000', fee: 1000 }],
                        },
                    ],
                },
            };

            const templateSettings = [
                { zipcode: '63000-63644', fee: 3000 },
                { zipcode: '54000', fee: 5000 },
            ];

            applyExtraFeeTemplateHandler(
                createAction({ countryIndex: 0, settings: templateSettings }),
                mockContext
            );

            const updates = mockContext.setLocalState.mock.calls[0][0];
            expect(updates['form.country_settings'][0].extra_fee_settings).toEqual(templateSettings);
        });

        it('toast.success 호출', () => {
            mockLocalState = {
                form: {
                    country_settings: [createDefaultCountrySetting('KR')],
                },
            };

            applyExtraFeeTemplateHandler(
                createAction({ countryIndex: 0, settings: [{ zipcode: '63000', fee: 3000 }] }),
                mockContext
            );

            expect(mockToastSuccess).toHaveBeenCalled();
        });
    });

    // ===== 전체 부과정책 가시성 매트릭스 (14개 정책) =====

    describe('부과정책별 가시성 매트릭스', () => {
        const testCases = [
            { policy: 'free', baseFee: false, threshold: false, ranges: false, api: false, unit: false },
            { policy: 'fixed', baseFee: true, threshold: false, ranges: false, api: false, unit: false },
            { policy: 'conditional_free', baseFee: true, threshold: true, ranges: false, api: false, unit: false },
            { policy: 'range_amount', baseFee: false, threshold: false, ranges: true, api: false, unit: false },
            { policy: 'range_quantity', baseFee: false, threshold: false, ranges: true, api: false, unit: false },
            { policy: 'range_weight', baseFee: false, threshold: false, ranges: true, api: false, unit: false },
            { policy: 'range_volume', baseFee: false, threshold: false, ranges: true, api: false, unit: false },
            { policy: 'range_volume_weight', baseFee: false, threshold: false, ranges: true, api: false, unit: false },
            { policy: 'api', baseFee: false, threshold: false, ranges: false, api: true, unit: false },
            { policy: 'per_quantity', baseFee: true, threshold: false, ranges: false, api: false, unit: true },
            { policy: 'per_weight', baseFee: true, threshold: false, ranges: false, api: false, unit: true },
            { policy: 'per_volume', baseFee: true, threshold: false, ranges: false, api: false, unit: true },
            { policy: 'per_volume_weight', baseFee: true, threshold: false, ranges: false, api: false, unit: true },
            { policy: 'per_amount', baseFee: true, threshold: false, ranges: false, api: false, unit: true },
        ];

        testCases.forEach(({ policy, baseFee, threshold, ranges, api, unit }) => {
            it(`${policy}: baseFee=${baseFee}, threshold=${threshold}, ranges=${ranges}, api=${api}, unit=${unit}`, () => {
                // onChargePolicyChangeHandler는 country_settings[index]가 필요
                mockLocalState = {
                    form: {
                        country_settings: [
                            { ...createDefaultCountrySetting('KR'), charge_policy: policy },
                        ],
                    },
                };

                onChargePolicyChangeHandler(
                    createAction({ value: policy, index: 0 }),
                    mockContext
                );

                const updates = mockContext.setLocalState.mock.calls[0][0];
                expect(updates.showBaseFee).toBe(baseFee);
                expect(updates.showFreeThreshold).toBe(threshold);
                expect(updates.showRanges).toBe(ranges);
                expect(updates.showApiSettings).toBe(api);
                expect(updates.showUnitValue).toBe(unit);
            });
        });
    });
});
