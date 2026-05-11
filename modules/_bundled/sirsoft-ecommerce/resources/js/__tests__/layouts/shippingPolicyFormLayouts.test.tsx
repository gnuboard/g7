/**
 * 배송정책 등록/수정 폼 레이아웃 렌더링 테스트
 *
 * @description
 * - 메인 레이아웃 JSON 구조 및 데이터소스 검증
 * - Partial: 기본 정보 필드 구조 검증
 * - Partial: 국가별 탭 (탭 전환, 국가 추가/삭제) 검증
 * - Partial: 상세 설정 (국가별 부과정책, 조건부 필드) 검증
 * - Partial: 도서산간 추가배송비 (KR 전용, iteration, 핸들러 연결) 검증
 * - Modal: 도서산간 템플릿 적용 모달 검증
 * - i18n 키 경로 검증
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import mainLayout from '../../../layouts/admin/admin_ecommerce_shipping_policy_form.json';
import basicInfoPartial from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_basic_info.json';
import countryTabsPartial from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_country_tabs.json';
import countryBasicFieldsPartial from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_country_basic_fields.json';
import chargeSettingsPartial from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_charge_settings.json';
import extraFeePartial from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_partial_extra_fee.json';
import extraFeeTemplateModal from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_modal_extra_fee_template.json';

// ===== 유틸리티 =====

/** JSON 트리에서 id로 노드를 재귀 탐색 */
function findById(node: any, id: string): any {
    if (!node) return null;
    if (node.id === id) return node;
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findById(child, id);
            if (found) return found;
        }
    }
    return null;
}

/** JSON 트리에서 모든 $t: 키를 추출 */
function extractI18nKeys(obj: any): string[] {
    const keys: string[] = [];
    const json = JSON.stringify(obj);
    const regex = /\$t:([a-zA-Z][a-zA-Z0-9_.-]+(?:\.[a-zA-Z0-9_-]+)+)/g;
    let match;
    while ((match = regex.exec(json)) !== null) {
        keys.push(match[1]);
    }
    return [...new Set(keys)];
}

// ===== 메인 레이아웃 =====

describe('shippingPolicyFormLayouts', () => {
    describe('메인 레이아웃 (admin_ecommerce_shipping_policy_form.json)', () => {
        it('올바른 레이아웃 기본 구조', () => {
            expect(mainLayout.version).toBe('1.0.0');
            expect(mainLayout.layout_name).toBe('admin_ecommerce_shipping_policy_form');
            expect(mainLayout.extends).toBe('_admin_base');
            expect(mainLayout.permissions).toContain('sirsoft-ecommerce.shipping-policies.read');
        });

        it('state에 country_settings 기반 폼 기본값', () => {
            const { state } = mainLayout;
            // 폼 필드: 정책 메타데이터만 (국가별 설정은 country_settings 배열)
            // name 은 LocaleInput 의 빈 다국어 컨테이너 → [] 로 초기화 (엔진이 다국어 객체로 변환)
            expect(state.form.name).toEqual([]);
            expect(state.form.is_active).toBe(true);
            expect(state.form.is_default).toBe(false);
            expect(state.form.country_settings).toEqual([]);

            // 탭/에러 상태 (rangeErrors 는 dot notation key 기반 → [] 빈 배열로 초기화)
            expect(state.activeCountryTab).toBe(0);
            expect(state.rangeErrors).toEqual([]);

            // flat 필드 제거 확인
            expect(state.form).not.toHaveProperty('shipping_method');
            expect(state.form).not.toHaveProperty('countries');
            expect(state.form).not.toHaveProperty('currency_code');
            expect(state.form).not.toHaveProperty('charge_policy');
            expect(state.form).not.toHaveProperty('base_fee');
            expect(state.form).not.toHaveProperty('extra_fee_enabled');
            expect(state.form).not.toHaveProperty('extra_fee_settings');
        });

        it('state에 템플릿 CRUD 상태 기본값', () => {
            const { state } = mainLayout;
            expect(state.templateSearch).toBe('');
            expect(state.selectedTemplateIds).toEqual([]);
            expect(state.editingTemplate).toBeNull();
            expect(state.templateForm).toEqual({
                zipcode: '',
                fee: 3000,
                region: '',
                description: '',
            });
            // templateFormErrors 는 동적 dot notation key 사용 → [] 빈 배열
            expect(state.templateFormErrors).toEqual([]);
        });

        it('가시성 플래그 기본값 (fixed 기준)', () => {
            const { state } = mainLayout;
            expect(state.showBaseFee).toBe(true);
            expect(state.showFreeThreshold).toBe(false);
            expect(state.showRanges).toBe(false);
            expect(state.showApiSettings).toBe(false);
            expect(state.showUnitValue).toBe(false);
        });

        it('data_sources: policy (GET, blocking, initLocal)', () => {
            const policyDs = mainLayout.data_sources.find((ds: any) => ds.id === 'policy');
            expect(policyDs).toBeDefined();
            expect(policyDs!.method).toBe('GET');
            expect(policyDs!.if).toBe('{{!!route.id}}');
            expect(policyDs!.initLocal).toBe('form');
            expect(policyDs!.loading_strategy).toBe('blocking');
            expect(policyDs!.auth_required).toBe(true);
        });

        it('data_sources: copy_source (복사 모드용, query.copy_id 기반)', () => {
            const copyDs = mainLayout.data_sources.find((ds: any) => ds.id === 'copy_source');
            expect(copyDs).toBeDefined();
            expect(copyDs!.method).toBe('GET');
            expect(copyDs!.endpoint).toContain('{{query.copy_id}}');
            expect(copyDs!.if).toBe('{{!!query?.copy_id && !route?.id}}');
            expect(copyDs!.initLocal).toBe('form');
            expect(copyDs!.loading_strategy).toBe('blocking');
            expect(copyDs!.auto_fetch).toBe(true);
            expect(copyDs!.auth_required).toBe(true);
            expect(copyDs!.fallback).toEqual({ data: null });
        });

        it('data_sources: ecommerce_settings (GET, blocking, auto_fetch)', () => {
            const settingsDs = mainLayout.data_sources.find((ds: any) => ds.id === 'ecommerce_settings');
            expect(settingsDs).toBeDefined();
            expect(settingsDs!.method).toBe('GET');
            expect(settingsDs!.endpoint).toContain('/admin/settings');
            expect(settingsDs!.auto_fetch).toBe(true);
            expect(settingsDs!.loading_strategy).toBe('blocking');
            expect(settingsDs!.auth_required).toBe(true);
        });

        it('data_sources: extra_fee_templates (auto_fetch false, progressive, search params)', () => {
            const templatesDs = mainLayout.data_sources.find((ds: any) => ds.id === 'extra_fee_templates');
            expect(templatesDs).toBeDefined();
            expect(templatesDs!.auto_fetch).toBe(false);
            expect(templatesDs!.loading_strategy).toBe('progressive');
            // 검색 params 추가 확인
            expect(templatesDs!.params).toBeDefined();
            expect(templatesDs!.params.search).toContain('templateSearch');
            expect(templatesDs!.params.per_page).toBe(20);
        });

        it('computed: 8개 계산 속성 정의', () => {
            const { computed } = mainLayout;
            expect(computed).toHaveProperty('availableCountries');
            expect(computed).toHaveProperty('addableCountries');
            expect(computed).toHaveProperty('addableCountryOptions');
            expect(computed).toHaveProperty('shippingMethodOptions');
            expect(computed).toHaveProperty('chargePolicyOptions');
            expect(computed).toHaveProperty('currencyOptions');
            expect(computed).toHaveProperty('activeCountrySetting');
            expect(computed).toHaveProperty('activeCountryCode');
        });

        it('computed: activeCountrySetting이 activeCountryTab 기반', () => {
            const expr = mainLayout.computed.activeCountrySetting;
            expect(expr).toContain('_local.form?.country_settings');
            expect(expr).toContain('_local.activeCountryTab');
        });

        it('computed: activeCountryCode가 country_code 추출', () => {
            const expr = mainLayout.computed.activeCountryCode;
            expect(expr).toContain('country_code');
            expect(expr).toContain('_local.activeCountryTab');
        });

        it('computed: availableCountries가 ecommerce_settings에서 활성 국가 필터링', () => {
            const expr = mainLayout.computed.availableCountries;
            expect(expr).toContain('ecommerce_settings?.data?.shipping?.available_countries');
            expect(expr).toContain('c.is_active');
        });

        it('computed: addableCountries가 미추가 국가만 필터링', () => {
            const expr = mainLayout.computed.addableCountries;
            expect(expr).toContain('_local.form?.country_settings');
            expect(expr).toContain('c.code');
        });

        it('computed: shippingMethodOptions이 ecommerce_settings 의 동적 types 기반이다', () => {
            // 7개 hardcoded → ecommerce_settings.shipping.types 동적 매핑으로 변경
            const expr = mainLayout.computed.shippingMethodOptions;
            expect(expr).toContain('ecommerce_settings?.data?.shipping?.types');
            expect(expr).toContain('t.is_active');
        });

        it('computed: chargePolicyOptions에 14개 부과정책', () => {
            const expr = mainLayout.computed.chargePolicyOptions;
            const policies = [
                'free', 'fixed', 'conditional_free',
                'range_amount', 'range_quantity', 'range_weight', 'range_volume', 'range_volume_weight',
                'per_quantity', 'per_weight', 'per_volume', 'per_volume_weight', 'per_amount',
                'api',
            ];
            policies.forEach(p => {
                expect(expr).toContain(`value: '${p}'`);
            });
        });

        it('computed: currencyOptions가 ecommerce_settings 통화 목록 기반', () => {
            const expr = mainLayout.computed.currencyOptions;
            expect(expr).toContain('ecommerce_settings?.data?.language_currency?.currencies');
        });

        it('init_actions: 글로벌 상태 초기화 + 커스텀 핸들러 + 복사 모드 is_default 해제', () => {
            expect(mainLayout.init_actions).toHaveLength(3);

            // 글로벌 상태 초기화 (템플릿 모달 로딩 플래그)
            const globalInit = mainLayout.init_actions[0];
            expect(globalInit.handler).toBe('setState');
            expect(globalInit.params.target).toBe('global');
            expect(globalInit.params.isTemplateSaving).toBe(false);
            expect(globalInit.params.isTemplateDeleting).toBe(false);

            // 커스텀 핸들러로 수정/등록/복사 모드 분기
            const formInit = mainLayout.init_actions[1];
            expect(formInit.handler).toBe('sirsoft-ecommerce.initShippingPolicyForm');
            expect(formInit.params.isEdit).toBe('{{!!route.id}}');
            expect(formInit.params.isCopy).toBe('{{!!query?.copy_id && !route?.id}}');
            expect(formInit.params.policy).toBe('{{route.id ? policy?.data : copy_source?.data}}');
            expect(formInit.params.availableCountries).toBe('{{_computed.availableCountries}}');
            expect(formInit).not.toHaveProperty('if');

            // 복사 모드일 때 is_default=false 강제 해제
            const copyDefaultReset = mainLayout.init_actions[2];
            expect(copyDefaultReset.if).toBe('{{!!query?.copy_id && !route?.id}}');
            expect(copyDefaultReset.handler).toBe('setState');
            expect(copyDefaultReset.params.target).toBe('local');
            expect(copyDefaultReset.params['form.is_default']).toBe(false);
        });

        it('modals: extra_fee_template 모달 참조', () => {
            expect(mainLayout.modals).toBeDefined();
            expect(Array.isArray(mainLayout.modals)).toBe(true);
            const extraFeeModal = mainLayout.modals[0];
            expect(extraFeeModal).toBeDefined();
            expect(extraFeeModal.partial).toContain('_modal_extra_fee_template.json');
        });

        it('5개 partial 참조 (basic_info + country_settings_wrapper[tabs, basic_fields, charge, extra_fee])', () => {
            const content = mainLayout.slots.content[0];
            const formSections = findById(content, 'form_sections');
            expect(formSections).toBeDefined();
            // form_sections: basic_info + country_settings_wrapper
            expect(formSections.children).toHaveLength(2);
            expect(formSections.children[0].partial).toContain('_partial_basic_info.json');

            // country_settings_wrapper: 4개 partial (tabs, basic_fields, charge, extra_fee)
            const wrapper = formSections.children[1];
            expect(wrapper.id).toBe('country_settings_wrapper');
            expect(wrapper.children).toHaveLength(4);
            expect(wrapper.children[0].partial).toContain('_partial_country_tabs.json');
            expect(wrapper.children[1].partial).toContain('_partial_country_basic_fields.json');
            expect(wrapper.children[2].partial).toContain('_partial_charge_settings.json');
            expect(wrapper.children[3].partial).toContain('_partial_extra_fee.json');
        });

        it('form_sections에 dataKey + debounce 설정', () => {
            const content = mainLayout.slots.content[0];
            const formSections = findById(content, 'form_sections');
            expect(formSections).toBeDefined();
            expect(formSections.dataKey).toBe('form');
            expect(formSections.debounce).toBe(300);
        });

        it('title이 route.id 기반 조건부 렌더링', () => {
            const meta = mainLayout.meta;
            expect(meta.title).toContain('route.id');
            expect(meta.title).toContain('title_edit');
            expect(meta.title).toContain('title_create');
        });

        it('validation_error_banner가 _local.errors 조건부', () => {
            const content = mainLayout.slots.content[0];
            const errorBanner = findById(content, 'validation_error_banner');
            expect(errorBanner).toBeDefined();
            expect(errorBanner.if).toBe('{{_local.errors}}');
        });

        it('sticky_header: 목록 + 저장 버튼', () => {
            const content = mainLayout.slots.content[0];
            const listBtn = findById(content, 'list_button');
            expect(listBtn).toBeDefined();
            const listJson = JSON.stringify(listBtn);
            expect(listJson).toContain('"handler":"navigate"');
            expect(listJson).toContain('/admin/ecommerce/shipping-policies');

            const saveBtn = findById(content, 'save_button');
            expect(saveBtn).toBeDefined();
            expect(saveBtn.props.className).toContain('btn-primary');
        });

        it('저장 버튼 sequence: setState → apiCall → onSuccess/onError', () => {
            const content = mainLayout.slots.content[0];
            const saveBtn = findById(content, 'save_button');
            const json = JSON.stringify(saveBtn);
            expect(json).toContain('"handler":"sequence"');
            expect(json).toContain('"handler":"apiCall"');
            expect(json).toContain('"handler":"toast"');
            expect(json).toContain('"handler":"navigate"');
        });

        it('저장 apiCall: target 사용, body 사용, auth_required', () => {
            const content = mainLayout.slots.content[0];
            const saveBtn = findById(content, 'save_button');
            const findApiCall = (node: any): any => {
                if (!node) return null;
                if (node.handler === 'apiCall') return node;
                if (node.actions) {
                    for (const a of node.actions) {
                        const found = findApiCall(a);
                        if (found) return found;
                    }
                }
                if (node.params?.actions) {
                    for (const a of node.params.actions) {
                        const found = findApiCall(a);
                        if (found) return found;
                    }
                }
                return null;
            };
            const apiCall = findApiCall(saveBtn);
            expect(apiCall).toBeDefined();
            expect(apiCall.target).toBeDefined();
            expect(apiCall.target).toContain('/api/modules/sirsoft-ecommerce/admin/shipping-policies');
            expect(apiCall.params).not.toHaveProperty('endpoint');
            expect(apiCall.params.body).toBe('{{_local.form}}');
            expect(apiCall.params).not.toHaveProperty('data');
            expect(apiCall.auth_required).toBe(true);
        });
    });

    // ===== 기본 정보 Partial =====

    describe('Partial: 기본 정보 (_partial_basic_info.json)', () => {
        it('is_partial 메타 설정', () => {
            expect(basicInfoPartial.meta.is_partial).toBe(true);
        });

        it('배송정책명(MultilingualInput) 필드', () => {
            const nameField = findById(basicInfoPartial, 'field_name');
            expect(nameField).toBeDefined();

            const json = JSON.stringify(nameField);
            expect(json).toContain('"name":"MultilingualInput"');
            expect(json).toContain('"name":"name"');
        });

        it('Toggle: is_active, is_default 필드', () => {
            const isActiveField = findById(basicInfoPartial, 'field_is_active');
            expect(isActiveField).toBeDefined();
            expect(JSON.stringify(isActiveField)).toContain('"name":"Toggle"');

            const isDefaultField = findById(basicInfoPartial, 'field_is_default');
            expect(isDefaultField).toBeDefined();
            expect(JSON.stringify(isDefaultField)).toContain('"name":"Toggle"');
        });

        it('배송방법/운송사/국가 필드가 제거되었는지 확인', () => {
            // 기존 flat 필드들은 country_tabs partial로 이동
            expect(findById(basicInfoPartial, 'field_shipping_method')).toBeNull();
            expect(findById(basicInfoPartial, 'field_carrier')).toBeNull();
            expect(findById(basicInfoPartial, 'field_countries')).toBeNull();
            expect(findById(basicInfoPartial, 'field_currency_code')).toBeNull();
        });
    });

    // ===== 국가별 탭 Partial =====

    describe('Partial: 국가별 탭 (_partial_country_tabs.json)', () => {
        it('is_partial 메타 설정', () => {
            expect(countryTabsPartial.meta.is_partial).toBe(true);
        });

        it('국가 추가 영역: addableCountries 기반 조건부', () => {
            const addArea = findById(countryTabsPartial, 'add_country_area');
            expect(addArea).toBeDefined();
            expect(addArea.if).toContain('_computed.addableCountries');
            expect(addArea.if).toContain('length > 0');
        });

        it('국가 추가 Select + Button: addCountrySetting 핸들러', () => {
            const addArea = findById(countryTabsPartial, 'add_country_area');
            const json = JSON.stringify(addArea);
            expect(json).toContain('_computed.addableCountryOptions');
            expect(json).toContain('sirsoft-ecommerce.addCountrySetting');
            expect(json).toContain('_selectedCountryToAdd');
        });

        it('빈 국가 설정 상태 표시', () => {
            const emptyState = findById(countryTabsPartial, 'empty_country_settings');
            expect(emptyState).toBeDefined();
            expect(emptyState.if).toContain('country_settings');
            expect(emptyState.if).toContain('length === 0');
        });

        it('탭 버튼 영역: country_settings.length > 0 조건', () => {
            const tabButtons = findById(countryTabsPartial, 'country_tab_buttons');
            expect(tabButtons).toBeDefined();
            expect(tabButtons.if).toContain('country_settings');
            expect(tabButtons.if).toContain('length > 0');
        });

        it('탭 버튼 iteration: country_settings 기반', () => {
            const tabButtons = findById(countryTabsPartial, 'country_tab_buttons');
            expect(tabButtons).toBeDefined();

            // iteration은 children[0] (Button)에 있음
            const btn = tabButtons.children[0];
            expect(btn.iteration).toBeDefined();
            expect(btn.iteration.source).toContain('_local.form?.country_settings');
            expect(btn.iteration.item_var).toBe('tabItem');
        });

        it('탭 전환 핸들러: switchCountryTab', () => {
            const json = JSON.stringify(countryTabsPartial);
            expect(json).toContain('sirsoft-ecommerce.switchCountryTab');
            expect(json).toContain('"index"');
            expect(json).toContain('tabItem._idx');
        });

        it('탭 바에 기본 필드 없음 (country_basic_fields partial로 분리됨)', () => {
            expect(findById(countryTabsPartial, 'field_country_shipping_method')).toBeNull();
            expect(findById(countryTabsPartial, 'field_country_carrier')).toBeNull();
            expect(findById(countryTabsPartial, 'field_country_currency')).toBeNull();
            expect(findById(countryTabsPartial, 'field_country_is_active')).toBeNull();
            expect(findById(countryTabsPartial, 'country_remove_area')).toBeNull();
        });
    });

    // ===== 국가별 기본 필드 Partial =====

    describe('Partial: 국가별 기본 필드 (_partial_country_basic_fields.json)', () => {
        it('is_partial 메타 설정', () => {
            expect(countryBasicFieldsPartial.meta.is_partial).toBe(true);
        });

        it('최상위 조건: _computed.activeCountrySetting', () => {
            expect(countryBasicFieldsPartial.if).toBe('{{_computed.activeCountrySetting}}');
        });

        it('국가별 기본 필드: 배송방법, 통화, 사용여부', () => {
            expect(findById(countryBasicFieldsPartial, 'field_country_shipping_method')).toBeDefined();
            expect(findById(countryBasicFieldsPartial, 'field_country_currency')).toBeDefined();
            expect(findById(countryBasicFieldsPartial, 'field_country_is_active')).toBeDefined();
        });

        it('배송방법 Select: computed shippingMethodOptions 참조', () => {
            const field = findById(countryBasicFieldsPartial, 'field_country_shipping_method');
            const json = JSON.stringify(field);
            expect(json).toContain('_computed.shippingMethodOptions');
            expect(json).toContain('_computed.activeCountrySetting?.shipping_method');
        });

        it('통화 Select: computed currencyOptions 참조', () => {
            const field = findById(countryBasicFieldsPartial, 'field_country_currency');
            const json = JSON.stringify(field);
            expect(json).toContain('_computed.currencyOptions');
            expect(json).toContain('_computed.activeCountrySetting?.currency_code');
        });

        it('사용여부 Toggle: activeCountrySetting?.is_active 바인딩', () => {
            const field = findById(countryBasicFieldsPartial, 'field_country_is_active');
            const json = JSON.stringify(field);
            expect(json).toContain('_computed.activeCountrySetting?.is_active');
        });

        it('국가 삭제: country_settings.length > 1 조건 + removeCountrySetting', () => {
            const removeArea = findById(countryBasicFieldsPartial, 'country_remove_area');
            expect(removeArea).toBeDefined();
            expect(removeArea.if).toContain('country_settings');
            expect(removeArea.if).toContain('length > 1');

            const json = JSON.stringify(removeArea);
            expect(json).toContain('sirsoft-ecommerce.removeCountrySetting');
        });

        it('모든 필드가 updateCountryField 커스텀 핸들러 사용', () => {
            const json = JSON.stringify(countryBasicFieldsPartial);
            expect(json).toContain('sirsoft-ecommerce.updateCountryField');
            // setState with {{}} keys 패턴 미사용 확인
            expect(json).not.toContain('form.country_settings[{{');
        });
    });

    // ===== 상세 설정 Partial =====

    describe('Partial: 상세 설정 (_partial_charge_settings.json)', () => {
        it('is_partial 메타 설정', () => {
            expect(chargeSettingsPartial.meta.is_partial).toBe(true);
        });

        it('최상위 조건: _computed.activeCountrySetting', () => {
            expect(chargeSettingsPartial.if).toBe('{{_computed.activeCountrySetting}}');
        });

        it('부과정책 Select: computed chargePolicyOptions + onChargePolicyChange 핸들러', () => {
            const chargePolicyField = findById(chargeSettingsPartial, 'field_charge_policy');
            expect(chargePolicyField).toBeDefined();

            const json = JSON.stringify(chargePolicyField);
            expect(json).toContain('_computed.chargePolicyOptions');
            expect(json).toContain('_computed.activeCountrySetting?.charge_policy');
            // 단일 커스텀 핸들러 (sequence 미사용)
            expect(json).toContain('sirsoft-ecommerce.onChargePolicyChange');
            expect(json).not.toContain('"handler":"sequence"');
        });

        it('배송비 필드 조건부: showBaseFee', () => {
            const baseFeeField = findById(chargeSettingsPartial, 'field_base_fee');
            expect(baseFeeField).toBeDefined();
            expect(baseFeeField.if).toBe('{{_local.showBaseFee}}');
        });

        it('무료배송 기준금액 조건부: showFreeThreshold', () => {
            const thresholdField = findById(chargeSettingsPartial, 'field_free_threshold');
            expect(thresholdField).toBeDefined();
            expect(thresholdField.if).toBe('{{_local.showFreeThreshold}}');
        });

        it('단위당 설정 조건부: showUnitValue', () => {
            const unitField = findById(chargeSettingsPartial, 'field_unit_value');
            expect(unitField).toBeDefined();
            expect(unitField.if).toBe('{{_local.showUnitValue}}');
        });

        it('구간별 배송비 조건부: showRanges + iteration (activeCountrySetting 기반)', () => {
            const rangesField = findById(chargeSettingsPartial, 'field_ranges');
            expect(rangesField).toBeDefined();
            expect(rangesField.if).toBe('{{_local.showRanges}}');

            // tier 행 iteration
            const tierRow = findById(chargeSettingsPartial, 'range_tier_row');
            expect(tierRow).toBeDefined();
            expect(tierRow.iteration).toBeDefined();
            expect(tierRow.iteration.source).toBe('{{_computed.activeCountrySetting?.ranges?.tiers ?? []}}');
            expect(tierRow.iteration.item_var).toBe('tier');
            expect(tierRow.iteration.index_var).toBe('tierIdx');
        });

        it('구간 추가/삭제 핸들러 연결', () => {
            const json = JSON.stringify(chargeSettingsPartial);
            expect(json).toContain('sirsoft-ecommerce.addRangeTier');
            expect(json).toContain('sirsoft-ecommerce.removeRangeTier');
        });

        it('API 설정 조건부: showApiSettings', () => {
            const apiSection = findById(chargeSettingsPartial, 'field_api_settings');
            expect(apiSection).toBeDefined();
            expect(apiSection.if).toBe('{{_local.showApiSettings}}');
        });

        it('배송비 Input: activeCountrySetting?.base_fee 바인딩', () => {
            const baseFeeField = findById(chargeSettingsPartial, 'field_base_fee');
            const json = JSON.stringify(baseFeeField);
            expect(json).toContain('_computed.activeCountrySetting?.base_fee');
        });

        it('모든 필드가 커스텀 핸들러 사용 (setState {{}} 키 미사용)', () => {
            const json = JSON.stringify(chargeSettingsPartial);
            // setState with {{}} keys 패턴 미사용 확인
            expect(json).not.toContain('form.country_settings[{{');
            // 커스텀 핸들러 사용 확인
            expect(json).toContain('sirsoft-ecommerce.onChargePolicyChange');
            expect(json).toContain('sirsoft-ecommerce.updateCountryField');
            expect(json).toContain('sirsoft-ecommerce.updateUnitValue');
        });
    });

    // ===== 도서산간 배송비 Partial =====

    describe('Partial: 도서산간 배송비 (_partial_extra_fee.json)', () => {
        it('is_partial 메타 설정', () => {
            expect(extraFeePartial.meta.is_partial).toBe(true);
        });

        it('최상위 조건: activeCountrySetting + KR 국가 전용', () => {
            expect(extraFeePartial.if).toBe(
                '{{_computed.activeCountrySetting && _computed.activeCountryCode === \'KR\'}}'
            );
        });

        it('KR 전용 배지 표시', () => {
            const json = JSON.stringify(extraFeePartial);
            expect(json).toContain('extra_fee_kr_only');
        });

        it('extra_fee_enabled Toggle: activeCountrySetting 바인딩', () => {
            const enabledField = findById(extraFeePartial, 'field_extra_fee_enabled');
            expect(enabledField).toBeDefined();
            const json = JSON.stringify(enabledField);
            expect(json).toContain('"name":"Toggle"');
            expect(json).toContain('_computed.activeCountrySetting?.extra_fee_enabled');
        });

        it('상세 영역 조건부: activeCountrySetting?.extra_fee_enabled', () => {
            const detailSection = findById(extraFeePartial, 'extra_fee_detail');
            expect(detailSection).toBeDefined();
            expect(detailSection.if).toBe('{{_computed.activeCountrySetting?.extra_fee_enabled}}');
        });

        it('extra_fee_multiply Toggle', () => {
            const multiplyToggle = findById(extraFeePartial, 'extra_fee_multiply_toggle');
            expect(multiplyToggle).toBeDefined();
            expect(JSON.stringify(multiplyToggle)).toContain('_computed.activeCountrySetting?.extra_fee_multiply');
        });

        it('템플릿 불러오기 버튼: sequence(refetchDataSource → openModal)', () => {
            const json = JSON.stringify(extraFeePartial);
            expect(json).toContain('"handler":"refetchDataSource"');
            expect(json).toContain('"dataSourceId":"extra_fee_templates"');
            expect(json).toContain('"handler":"openModal"');
            expect(json).toContain('"target":"extra_fee_template"');

            // openModal의 target은 액션 레벨에 위치해야 함 (params 내부 금지)
            const header = findById(extraFeePartial, 'extra_fee_settings_header');
            const loadBtn = header.children[1].children[0]; // 첫 번째 버튼 (템플릿 불러오기)
            const seqActions = loadBtn.actions[0].params.actions;
            const openModalAction = seqActions.find((a: any) => a.handler === 'openModal');
            expect(openModalAction).toBeDefined();
            expect(openModalAction.target).toBe('extra_fee_template');
            expect(openModalAction).not.toHaveProperty('params');
        });

        it('PC: Table 구조 (Thead + Tbody + iteration)', () => {
            const listContainer = findById(extraFeePartial, 'extra_fee_list_container');
            expect(listContainer).toBeDefined();
            expect(listContainer.if).toContain('_computed.activeCountrySetting?.extra_fee_settings');

            const json = JSON.stringify(listContainer);
            // Table > Thead > Tbody 구조
            expect(json).toContain('"name":"Table"');
            expect(json).toContain('"name":"Thead"');
            expect(json).toContain('"name":"Tbody"');
            // 4개 컬럼: 우편번호, 지역명, 추가배송비, 액션
            expect(json).toContain('extra_fee_zipcode');
            expect(json).toContain('extra_fee_region');
            expect(json).toContain('extra_fee_amount');
            // Tr에 iteration 설정
            expect(json).toContain('_computed.activeCountrySetting?.extra_fee_settings');
            expect(json).toContain('"item_var":"feeItem"');
            expect(json).toContain('"index_var":"feeIdx"');
        });

        it('PC: 지역명(region) 입력 필드 포함', () => {
            const json = JSON.stringify(extraFeePartial);
            // region 필드 바인딩
            expect(json).toContain('feeItem.region');
            // region 필드 업데이트 핸들러
            expect(json).toContain('"field":"region"');
        });

        it('모바일: responsive.portable 카드 구조', () => {
            const listContainer = findById(extraFeePartial, 'extra_fee_list_container');
            expect(listContainer).toBeDefined();
            // responsive.portable.children 존재
            expect(listContainer.responsive).toBeDefined();
            expect(listContainer.responsive.portable).toBeDefined();
            expect(listContainer.responsive.portable.children).toBeDefined();
            expect(listContainer.responsive.portable.children.length).toBeGreaterThan(0);

            const mobileJson = JSON.stringify(listContainer.responsive.portable.children);
            // 카드 구조 (excel-card)
            expect(mobileJson).toContain('excel-card');
            expect(mobileJson).toContain('excel-card-header');
            expect(mobileJson).toContain('excel-card-body');
            // iteration 설정 동일
            expect(mobileJson).toContain('"item_var":"feeItem"');
            // 모바일에서도 region, zipcode, fee 필드
            expect(mobileJson).toContain('"field":"zipcode"');
            expect(mobileJson).toContain('"field":"region"');
            expect(mobileJson).toContain('"field":"fee"');
        });

        it('행 추가/삭제 핸들러에 countryIndex 파라미터', () => {
            const json = JSON.stringify(extraFeePartial);
            expect(json).toContain('sirsoft-ecommerce.addExtraFeeRow');
            expect(json).toContain('sirsoft-ecommerce.removeExtraFeeRow');
            expect(json).toContain('"countryIndex"');
        });

        it('빈 상태 메시지: activeCountrySetting 기반 조건', () => {
            const json = JSON.stringify(extraFeePartial);
            expect(json).toContain('form.no_extra_fee');
            expect(json).toContain('_computed.activeCountrySetting?.extra_fee_settings');
        });

        it('모든 필드가 커스텀 핸들러 사용 (setState {{}} 키 미사용)', () => {
            const json = JSON.stringify(extraFeePartial);
            // setState with {{}} keys 패턴 미사용 확인
            expect(json).not.toContain('form.country_settings[{{');
            // 커스텀 핸들러 사용 확인
            expect(json).toContain('sirsoft-ecommerce.updateCountryField');
            expect(json).toContain('sirsoft-ecommerce.updateExtraFeeField');
        });
    });

    // ===== 도서산간 템플릿 Modal (CRUD) =====

    describe('Modal: 도서산간 템플릿 CRUD (_modal_extra_fee_template.json)', () => {
        it('Modal 컴포넌트 구조: width 900px + 반응형', () => {
            expect(extraFeeTemplateModal.type).toBe('composite');
            expect(extraFeeTemplateModal.name).toBe('Modal');
            expect(extraFeeTemplateModal.props.title).toBe(
                '$t:sirsoft-ecommerce.admin.shipping_policy.modal_extra_fee.title'
            );
            expect(extraFeeTemplateModal.props.width).toBe('900px');
            expect(extraFeeTemplateModal.props.className).toContain('max-w-');
        });

        it('is_partial 메타 설정', () => {
            expect(extraFeeTemplateModal.meta.is_partial).toBe(true);
        });

        // ---- 검색 섹션 ----

        it('검색 섹션: Input + Button', () => {
            const searchSection = findById(extraFeeTemplateModal, 'search_section');
            expect(searchSection).toBeDefined();
            const json = JSON.stringify(searchSection);
            // Input value는 $parent._local.templateSearch 바인딩
            expect(json).toContain('$parent._local.templateSearch');
            // 검색 버튼 존재
            expect(json).toContain('"name":"Button"');
            // refetchDataSource 호출
            expect(json).toContain('"handler":"refetchDataSource"');
            expect(json).toContain('"dataSourceId":"extra_fee_templates"');
        });

        // ---- 툴바 ----

        it('툴바: 선택 카운트 + 선택 삭제 + 전체 적용 + 선택 적용', () => {
            const toolbar = findById(extraFeeTemplateModal, 'toolbar_section');
            expect(toolbar).toBeDefined();
            const json = JSON.stringify(toolbar);
            // 선택 카운트
            expect(json).toContain('selectedTemplateIds');
            // 선택 삭제 버튼
            expect(json).toContain('modal_extra_fee.bulk_delete');
            // 전체 적용 버튼
            expect(json).toContain('modal_extra_fee.apply_all');
            // 선택 적용 버튼
            expect(json).toContain('modal_extra_fee.apply_selected');
        });

        // ---- 테이블 ----

        it('템플릿 목록 테이블: Table + iteration(Tr) + 체크박스 + 인라인 편집', () => {
            const tableContainer = findById(extraFeeTemplateModal, 'table_container');
            expect(tableContainer).toBeDefined();
            const json = JSON.stringify(tableContainer);
            // Table > Thead > Tbody 구조
            expect(json).toContain('"name":"Table"');
            expect(json).toContain('"name":"Thead"');
            expect(json).toContain('"name":"Tbody"');
            // Tr에 iteration 설정
            expect(json).toContain('"source":"{{extra_fee_templates?.data?.data ?? []}}"');
            expect(json).toContain('"item_var":"template"');
            // 체크박스 (전체 선택 + 개별 선택)
            expect(json).toContain('"type":"checkbox"');
            expect(json).toContain('selectedTemplateIds');
        });

        it('템플릿 행에 수정(pencil)/삭제(trash) 아이콘 버튼', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // 수정 버튼 (pencil 아이콘)
            expect(json).toContain('"name":"pencil"');
            // 삭제 버튼 (trash 아이콘)
            expect(json).toContain('"name":"trash"');
            // 인라인 편집 시 확인(check)/취소(xmark) 아이콘
            expect(json).toContain('"name":"check"');
            expect(json).toContain('"name":"xmark"');
        });

        it('빈 상태 처리', () => {
            const emptyState = findById(extraFeeTemplateModal, 'empty_state');
            expect(emptyState).toBeDefined();
            expect(emptyState.if).toContain('extra_fee_templates?.data?.data');
            expect(emptyState.if).toContain('length === 0');
            const json = JSON.stringify(emptyState);
            expect(json).toContain('modal_extra_fee.no_templates');
        });

        // ---- CRUD 핸들러 ----

        it('삭제 핸들러: confirm 속성 + apiCall(DELETE) + auth_required', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // apiCall DELETE 존재
            expect(json).toContain('"method":"DELETE"');
            // confirm 속성 사용 (handler가 아닌 action property)
            expect(json).toContain('"confirm"');
            expect(json).toContain('modal_extra_fee.delete_confirm');
        });

        it('일괄 삭제 핸들러: confirm 속성 + apiCall(DELETE /bulk)', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            expect(json).toContain('/extra-fee-templates/bulk');
            expect(json).toContain('modal_extra_fee.bulk_delete_confirm');
        });

        it('저장/추가 핸들러: 신규(POST) + 수정(PUT) 분리 + auth_required', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // 신규 추가 시 POST (new_template_row에서)
            expect(json).toContain('"method":"POST"');
            // 수정 시 PUT (인라인 편집에서)
            expect(json).toContain('"method":"PUT"');
            // auth_required 존재
            expect(json).toContain('"auth_required":true');
        });

        it('적용 핸들러: applyExtraFeeTemplate + closeModal + countryIndex + region', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            expect(json).toContain('sirsoft-ecommerce.applyExtraFeeTemplate');
            expect(json).toContain('"handler":"closeModal"');
            // countryIndex 파라미터 전달 확인
            expect(json).toContain('$parent._local.activeCountryTab');
            // region 필드가 settings 매핑에 포함 확인
            expect(json).toContain('region: t.region');
        });

        // ---- 추가/수정 폼 ----

        it('인라인 추가 행(new_template_row): zipcode, fee, region 입력 필드', () => {
            const newRow = findById(extraFeeTemplateModal, 'new_template_row');
            expect(newRow).toBeDefined();
            // isAddingTemplate 조건
            expect(newRow.if).toContain('isAddingTemplate');
            const json = JSON.stringify(newRow);
            // 폼 필드 바인딩
            expect(json).toContain('$parent._local.templateForm.zipcode');
            expect(json).toContain('$parent._local.templateForm.fee');
            expect(json).toContain('$parent._local.templateForm.region');
            // 저장(check) + 취소(xmark) 아이콘 버튼
            expect(json).toContain('"name":"check"');
            expect(json).toContain('"name":"xmark"');
        });

        it('툴바 추가 버튼 + 인라인 편집 로딩 상태', () => {
            const toolbar = findById(extraFeeTemplateModal, 'toolbar_section');
            const json = JSON.stringify(toolbar);
            // 추가 버튼 (toolbar에 위치)
            expect(json).toContain('modal_extra_fee.add_btn');
            // plus 아이콘
            expect(json).toContain('"name":"plus"');
            // 전체 모달에서 로딩 상태 참조
            const fullJson = JSON.stringify(extraFeeTemplateModal);
            expect(fullJson).toContain('_global.isTemplateSaving');
        });

        // ---- 상태 관리 규칙 ----

        it('모든 CRUD state는 $parent._local 사용', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // $parent._local 패턴 존재 확인
            expect(json).toContain('$parent._local');
            // templateSearch, selectedTemplateIds, templateForm 등
            expect(json).toContain('$parent._local.templateSearch');
            expect(json).toContain('$parent._local.selectedTemplateIds');
            expect(json).toContain('$parent._local.templateForm');
        });

        it('로딩 상태는 _global 사용', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            expect(json).toContain('_global.isTemplateSaving');
            expect(json).toContain('_global.isTemplateDeleting');
        });

        it('setState target: CRUD → $parent._local, 로딩 → global', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // $parent._local target 존재
            expect(json).toContain('"target":"$parent._local"');
            // global target 존재
            expect(json).toContain('"target":"global"');
        });

        // ---- Button type 규칙 ----

        it('모든 Button에 type="button" 명시 (form submit 방지)', () => {
            const allButtons: any[] = [];
            const findButtons = (node: any) => {
                if (!node) return;
                if (node.name === 'Button' && node.type === 'basic') {
                    allButtons.push(node);
                }
                if (Array.isArray(node.children)) {
                    node.children.forEach(findButtons);
                }
            };
            findButtons(extraFeeTemplateModal);

            allButtons.forEach((btn, idx) => {
                expect(btn.props?.type).toBe('button',
                    `Button #${idx} (id: ${btn.id ?? 'no-id'})에 type="button" 누락`
                );
            });
        });

        // ---- Footer ----

        it('닫기 버튼 (footer)', () => {
            const footer = findById(extraFeeTemplateModal, 'modal_footer');
            expect(footer).toBeDefined();
            const json = JSON.stringify(footer);
            expect(json).toContain('"handler":"closeModal"');
        });

        // ---- onSuccess/onError 순서 ----

        it('onSuccess에서 refetchDataSource 호출', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // 모든 CUD 작업 후 데이터 새로고침
            const refetchMatches = json.match(/"handler":"refetchDataSource"/g);
            expect(refetchMatches).toBeDefined();
            // 검색 + CRUD onSuccess에서 다수 사용
            expect(refetchMatches!.length).toBeGreaterThanOrEqual(2);
        });

        // ---- 필드별 에러 표시 (templateFormErrors) ----

        it('모달 JSON에 templateFormErrors 참조 존재', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            expect(json).toContain('templateFormErrors');
        });

        it('zipcode/region/fee Input에 조건부 적색 테두리 클래스', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // 에러 시 border-red-500 적용
            expect(json).toContain('templateFormErrors?.zipcode');
            expect(json).toContain('templateFormErrors?.fee');
            expect(json).toContain('templateFormErrors?.region');
            expect(json).toContain('border-red-500');
        });

        it('에러 문구 Span이 templateFormErrors 필드별로 존재', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // 각 필드 에러의 첫 번째 메시지 표시
            expect(json).toContain('templateFormErrors?.zipcode?.[0]');
            expect(json).toContain('templateFormErrors?.fee?.[0]');
            expect(json).toContain('templateFormErrors?.region?.[0]');
        });

        it('onError에서 templateFormErrors에 error.errors 저장', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // apiCall onError에서 필드별 에러 상태 세팅
            expect(json).toContain('error.errors ?? {}');
        });

        it('취소/성공 시 templateFormErrors 초기화', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // 빈 객체로 초기화 패턴: "{{({})}}"
            const resetPattern = '"templateFormErrors":"{{({})}}"';
            const count = json.split(resetPattern).length - 1;
            // 최소 4곳: 새 행 ✓ 전/성공/취소 + 편집 행 ✓ 전/성공/취소
            expect(count).toBeGreaterThanOrEqual(4);
        });
    });

    // ===== i18n 키 경로 무결성 =====

    describe('i18n 키 경로 무결성', () => {
        it('enum 키가 computed에서 admin.shipping_policy.enums.* 경로 사용 (charge_policy 만 hardcoded)', () => {
            // shipping_method 는 ecommerce_settings 동적 → enums 키 미사용
            // charge_policy 는 정적 옵션 → enums 키 유지
            const computedJson = JSON.stringify(mainLayout.computed);
            expect(computedJson).toContain('admin.shipping_policy.enums.charge_policy');
        });

        it('partial에서 enum 하드코딩 없음 (computed 참조만)', () => {
            // basic_info, country_tabs, country_basic_fields, charge_settings에서 enum 직접 참조 없음
            // (Select options는 computed 참조)
            const partials = [basicInfoPartial, countryTabsPartial, countryBasicFieldsPartial, chargeSettingsPartial];
            partials.forEach(partial => {
                const json = JSON.stringify(partial);
                expect(json).not.toContain('enums.shipping_method.');
                expect(json).not.toContain('enums.carrier.');
                expect(json).not.toContain('enums.charge_policy.');
            });
        });

        // ---- 권한 제어 (isReadOnly disabled) ----

        it('추가/내보내기/가져오기/선택삭제 버튼에 $parent._computed.isReadOnly disabled', () => {
            const toolbar = findById(extraFeeTemplateModal, 'toolbar_section');
            const json = JSON.stringify(toolbar);
            // 추가 버튼: isReadOnly OR 추가
            expect(json).toContain('$parent._computed.isReadOnly');
            // 선택 삭제 버튼: isTemplateDeleting || isReadOnly
            expect(json).toContain('_global.isTemplateDeleting || $parent._computed.isReadOnly');
            // 전체 내보내기 버튼
            expect(json).toContain('"disabled":"{{$parent._computed.isReadOnly}}"');
            // disabled 스타일 클래스
            expect(json).toContain('disabled:opacity-50');
            expect(json).toContain('disabled:cursor-not-allowed');
        });

        it('전체선택/항목선택 Checkbox에 $parent._computed.isReadOnly disabled', () => {
            const allCheckboxes: any[] = [];
            const findCheckboxes = (node: any) => {
                if (!node) return;
                if (node.name === 'Input' && node.props?.type === 'checkbox') {
                    allCheckboxes.push(node);
                }
                if (Array.isArray(node.children)) {
                    node.children.forEach(findCheckboxes);
                }
            };
            const tableContainer = findById(extraFeeTemplateModal, 'table_container');
            findCheckboxes(tableContainer);
            // 전체선택 + 개별선택 최소 2개
            expect(allCheckboxes.length).toBeGreaterThanOrEqual(2);
            allCheckboxes.forEach((cb, idx) => {
                expect(cb.props.disabled).toContain('$parent._computed.isReadOnly');
            });
        });

        it('신규 입력 폼 Input(zipcode/region/fee)에 disabled', () => {
            const newRow = findById(extraFeeTemplateModal, 'new_template_row');
            const json = JSON.stringify(newRow);
            // 3개 Input 필드에 disabled 추가
            const disabledCount = (json.match(/\$parent\._computed\.isReadOnly/g) || []).length;
            // zipcode, region, fee Input + 저장 버튼 = 최소 4개소
            expect(disabledCount).toBeGreaterThanOrEqual(4);
        });

        it('편집모드 Input/Toggle/버튼에 $parent._computed.isReadOnly disabled', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // 전체 모달에서 isReadOnly 참조 총 개수 (17개소)
            const totalCount = (json.match(/\$parent\._computed\.isReadOnly/g) || []).length;
            expect(totalCount).toBeGreaterThanOrEqual(17);
        });

        it('편집/삭제 버튼에 disabled:opacity-50 disabled:cursor-not-allowed 클래스', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // pencil(편집) 버튼 disabled 스타일
            expect(json).toContain('btn-icon text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 disabled:opacity-50 disabled:cursor-not-allowed');
            // trash(삭제) 버튼 disabled 스타일
            expect(json).toContain('btn-icon text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 disabled:opacity-50 disabled:cursor-not-allowed');
        });

        it('모든 form 키가 admin.shipping_policy.form.* 경로 사용', () => {
            const formKeys = extractI18nKeys(basicInfoPartial).filter(k =>
                k.includes('.form.') && k.startsWith('sirsoft-ecommerce.')
            );
            formKeys.forEach(key => {
                expect(key).toMatch(/^sirsoft-ecommerce\.admin\.shipping_policy\.form\./);
            });
        });

        it('메인 레이아웃 title이 admin.shipping_policy.title_* 경로 사용', () => {
            const keys = extractI18nKeys(mainLayout);
            const titleKeys = keys.filter(k => k.includes('title_'));
            titleKeys.forEach(key => {
                expect(key).not.toContain('.form.title_');
                expect(key).toMatch(/admin\.shipping_policy\.title_/);
            });
        });

        it('country_tabs partial에서 form 키 사용', () => {
            const keys = extractI18nKeys(countryTabsPartial);
            const formKeys = keys.filter(k =>
                k.includes('.form.') && k.startsWith('sirsoft-ecommerce.')
            );
            // 탭 바 관련 form 키 확인 (기본 필드는 country_basic_fields partial로 분리)
            expect(formKeys.some(k => k.includes('country_tab_add'))).toBe(true);
            expect(formKeys.some(k => k.includes('country_select_placeholder'))).toBe(true);
            expect(formKeys.some(k => k.includes('no_country_settings'))).toBe(true);
            expect(formKeys.some(k => k.includes('section_country_settings'))).toBe(true);
        });

        it('country_basic_fields partial에서 form 키 사용', () => {
            const keys = extractI18nKeys(countryBasicFieldsPartial);
            const formKeys = keys.filter(k =>
                k.includes('.form.') && k.startsWith('sirsoft-ecommerce.')
            );
            // 국가별 기본 필드 form 키 확인
            expect(formKeys.some(k => k.includes('shipping_method'))).toBe(true);
            expect(formKeys.some(k => k.includes('currency_code'))).toBe(true);
            expect(formKeys.some(k => k.includes('country_tab_remove'))).toBe(true);
        });
    });
});
