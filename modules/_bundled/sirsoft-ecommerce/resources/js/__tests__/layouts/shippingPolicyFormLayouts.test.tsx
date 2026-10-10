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
import { asArray, callbacksOf, initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

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
        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('올바른 레이아웃 기본 구조', () => {
            expect(mainLayout.version).toBe('1.0.0');
            expect(mainLayout.layout_name).toBe('admin_ecommerce_shipping_policy_form');
            expect(mainLayout.extends).toBe('_admin_base');
            expect(mainLayout.permissions).toContain('sirsoft-ecommerce.shipping-policies.read');
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('state에 country_settings 기반 폼 기본값', () => {
            const state = initStateOf(mainLayout);
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

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('state에 템플릿 CRUD 상태 기본값', () => {
            const state = initStateOf(mainLayout);
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

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('가시성 플래그 기본값 (fixed 기준)', () => {
            const state = initStateOf(mainLayout);
            expect(state.showBaseFee).toBe(true);
            expect(state.showFreeThreshold).toBe(false);
            expect(state.showRanges).toBe(false);
            expect(state.showApiSettings).toBe(false);
            expect(state.showUnitValue).toBe(false);
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('data_sources: policy (GET, blocking, initLocal)', () => {
            const policyDs = mainLayout.data_sources.find((ds: any) => ds.id === 'policy');
            expect(policyDs).toBeDefined();
            expect(policyDs!.method).toBe('GET');
            expect(policyDs!.if).toBe('{{!!route.id}}');
            expect(policyDs!.initLocal).toBe('form');
            expect(policyDs!.loading_strategy).toBe('blocking');
            expect(policyDs!.auth_required).toBe(true);
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
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

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('data_sources: ecommerce_settings (GET, blocking, auto_fetch)', () => {
            const settingsDs = mainLayout.data_sources.find((ds: any) => ds.id === 'ecommerce_settings');
            expect(settingsDs).toBeDefined();
            expect(settingsDs!.method).toBe('GET');
            expect(settingsDs!.endpoint).toContain('/admin/settings');
            expect(settingsDs!.auto_fetch).toBe(true);
            expect(settingsDs!.loading_strategy).toBe('blocking');
            expect(settingsDs!.auth_required).toBe(true);
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
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

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
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

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('computed: activeCountrySetting이 activeCountryTab 기반', () => {
            const expr = mainLayout.computed.activeCountrySetting;
            expect(expr).toContain('_local.form?.country_settings');
            expect(expr).toContain('_local.activeCountryTab');
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('computed: activeCountryCode가 country_code 추출', () => {
            const expr = mainLayout.computed.activeCountryCode;
            expect(expr).toContain('country_code');
            expect(expr).toContain('_local.activeCountryTab');
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('computed: availableCountries가 ecommerce_settings에서 활성 국가 필터링', () => {
            const expr = mainLayout.computed.availableCountries;
            expect(expr).toContain('ecommerce_settings?.data?.shipping?.available_countries');
            expect(expr).toContain('c.is_active');
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('computed: addableCountries가 미추가 국가만 필터링', () => {
            const expr = mainLayout.computed.addableCountries;
            expect(expr).toContain('_local.form?.country_settings');
            expect(expr).toContain('c.code');
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('computed: shippingMethodOptions이 ecommerce_settings 의 동적 types 기반이다', () => {
            // 7개 hardcoded → ecommerce_settings.shipping.types 동적 매핑으로 변경
            const expr = mainLayout.computed.shippingMethodOptions;
            expect(expr).toContain('ecommerce_settings?.data?.shipping?.types');
            expect(expr).toContain('t.is_active');
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
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

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('computed: currencyOptions가 ecommerce_settings 통화 목록 기반', () => {
            const expr = mainLayout.computed.currencyOptions;
            expect(expr).toContain('ecommerce_settings?.data?.language_currency?.currencies');
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('computed: defaultCurrencyCode/Label이 기본 통화 SSoT 기반 (읽기전용 표시용)', () => {
            const computed = mainLayout.computed as Record<string, string>;
            expect(computed).toHaveProperty('defaultCurrencyCode');
            expect(computed).toHaveProperty('defaultCurrencyLabel');
            expect(computed.defaultCurrencyCode).toContain('language_currency?.default_currency');
            expect(computed.defaultCurrencyLabel).toContain('language_currency?.default_currency');
        });

        /**
         * 신규 등록은 회수지 조회가 돌지 않으므로 저장 허용 플래그를 init 에서 세운다.
         *
         * @scenario claim_scope=pickup_address
         *
         * @effects shipping_policy_claim_address_loads_only_in_edit_mode
         */
        it('init_actions: 글로벌 상태 초기화 + 커스텀 핸들러 + 복사 모드 is_default 해제 + 신규 등록 회수지 허용', () => {
            // 개수·인덱스로 고정하지 않는다 — 같은 파일의 partial 순서 검사(아래)가 이미 남긴
            // 이유와 같다. 이 수는 계약이 아니라 당시 구조의 스냅샷이라, init_action 이 하나 늘 때마다
            // 무관한 단언이 함께 깨진다(18차에 로케일 주입이 들어오며 실제로 그렇게 됐다).
            // 계약은 "무엇이 있는가" 다. 한때 순서가 계약이던 로케일 주입 액션은 걷어냈다 — 표현식은
            // 엔진이 렌더·액션 컨텍스트에 넣어 주는 `$locale` 을 읽는다.
            const actions: any[] = mainLayout.init_actions;
            const byHandler = (h: string) => actions.filter((a) => a.handler === h);

            // 글로벌 상태 초기화 (템플릿 모달 로딩 플래그)
            const globalInit = byHandler('setState').find((a) => a.params?.target === 'global');
            expect(globalInit).toBeDefined();
            expect(globalInit.params.isTemplateSaving).toBe(false);
            expect(globalInit.params.isTemplateDeleting).toBe(false);

            // 커스텀 핸들러로 수정/등록/복사 모드 분기
            const [formInit] = byHandler('sirsoft-ecommerce.initShippingPolicyForm');
            expect(formInit).toBeDefined();
            expect(formInit.params.isEdit).toBe('{{!!route.id}}');
            expect(formInit.params.isCopy).toBe('{{!!query?.copy_id && !route?.id}}');
            expect(formInit.params.policy).toBe('{{route.id ? policy?.data : copy_source?.data}}');
            expect(formInit.params.availableCountries).toBe('{{_computed.availableCountries}}');
            expect(formInit).not.toHaveProperty('if');

            // 복사 모드일 때 is_default=false 강제 해제
            const copyDefaultReset = actions.find((a) => a.params?.['form.is_default'] === false);
            expect(copyDefaultReset).toBeDefined();
            expect(copyDefaultReset.if).toBe('{{!!query?.copy_id && !route?.id}}');
            expect(copyDefaultReset.handler).toBe('setState');
            expect(copyDefaultReset.params.target).toBe('local');

            // 신규 등록은 회수지 조회 데이터소스가 돌지 않으므로 저장 허용 플래그를 init 에서 세운다
            // (액션 체인의 `if` 는 `route` 를 읽지 못한다 — S10 10차 실측)
            const claimLoaded = actions.find((a) => a.params?.['claimAddresses._loaded'] === true);
            expect(claimLoaded).toBeDefined();
            expect(claimLoaded.if).toBe('{{!route.id}}');
            expect(claimLoaded.handler).toBe('setState');
            expect(claimLoaded.params.target).toBe('local');

            // 개수 리터럴을 걷어내면서 **상한이 함께 사라졌다** — 그 뒤로는 무관한 init_action 이
            // 몇 개 늘어도 이 검사가 초록이다. 형제 화면(`shippingPolicyListLayouts`)이 되살린 것과
            // 같은 형태로 상한을 세운다: 개수가 아니라 **이 화면의 init_actions 에 설 수 있는
            // 핸들러 집합**을 못박아, 예상 밖의 핸들러가 들어오면 그 이름과 함께 붉어지게 한다.
            const ALLOWED_INIT_HANDLERS = [
                'sirsoft-ecommerce.initShippingPolicyForm',
                'setState',
            ];
            expect(
                actions.map((a: any) => a?.handler).filter((h: string) => !ALLOWED_INIT_HANDLERS.includes(h)),
                '예상 밖의 init_action 이 늘었다 — 의도한 것이면 허용 집합에 함께 적는다',
            ).toEqual([]);
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('modals: extra_fee_template 모달 참조', () => {
            expect(mainLayout.modals).toBeDefined();
            expect(Array.isArray(mainLayout.modals)).toBe(true);
            const extraFeeModal = mainLayout.modals[0];
            expect(extraFeeModal).toBeDefined();
            expect(extraFeeModal.partial).toContain('_modal_extra_fee_template.json');
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('partial 이 정해진 순서로 놓이고 footer_buttons 가 마지막이다', () => {
            // 개수(toHaveLength)로 고정하지 않는다 — 이 수는 계약이 아니라 당시 구조의
            // 스냅샷이라, partial 이 하나 늘 때마다 무관한 테스트가 깨져 왔다
            // (3 → 2 → 3 → 4). 계약은 "무엇이 어떤 순서로 놓이는가" 이므로 상대 순서로 본다.
            const content = mainLayout.slots.content[0];
            const formSections = findById(content, 'form_sections');
            expect(formSections).toBeDefined();

            const order = (formSections.children as any[]).map(
                (c: any) => c.partial ?? c.id
            );
            const at = (needle: string) => order.findIndex((v: string) => v.includes(needle));

            // 기본정보 → 클레임 회수지 → 국가별 설정 → 저장 영역
            expect(at('_partial_basic_info.json')).toBe(0);
            expect(at('_partial_claim_addresses.json')).toBe(1);
            expect(at('country_settings_wrapper')).toBeGreaterThan(at('_partial_claim_addresses.json'));
            expect(at('footer_buttons')).toBe(order.length - 1);

            // country_settings_wrapper 안쪽도 순서로 본다
            const wrapper = (formSections.children as any[]).find(
                (c: any) => c.id === 'country_settings_wrapper'
            );
            expect(wrapper).toBeDefined();

            const inner = (wrapper.children as any[]).map((c: any) => c.partial ?? c.id);
            const innerAt = (needle: string) => inner.findIndex((v: string) => v.includes(needle));

            expect(innerAt('_partial_country_tabs.json')).toBe(0);
            expect(innerAt('_partial_country_basic_fields.json')).toBe(1);
            expect(innerAt('_partial_charge_settings.json')).toBeGreaterThan(
                innerAt('_partial_country_basic_fields.json')
            );
            // 클레임 배송비는 청구 설정과 추가배송비 사이
            expect(innerAt('_partial_claim_country_fee.json')).toBeGreaterThan(
                innerAt('_partial_charge_settings.json')
            );
            expect(innerAt('_partial_extra_fee.json')).toBeGreaterThan(
                innerAt('_partial_claim_country_fee.json')
            );

            // 상대 순서만 보면 같은 partial 이 두 번 실려 폼이 이중 렌더돼도 통과한다 —
            // 개수를 계약으로 삼지 않는 대신 중복만 막는다.
            expect(new Set(inner).size, `country_settings_wrapper 에 중복 partial 이 있다: ${inner.join(', ')}`).toBe(inner.length);
            expect(new Set(order).size, `form_sections 에 중복 partial 이 있다: ${order.join(', ')}`).toBe(order.length);
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('form_sections에 dataKey + debounce 설정', () => {
            const content = mainLayout.slots.content[0];
            const formSections = findById(content, 'form_sections');
            expect(formSections).toBeDefined();
            expect(formSections.dataKey).toBe('form');
            expect(formSections.debounce).toBe(300);
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('title이 route.id 기반 조건부 렌더링', () => {
            const meta = mainLayout.meta;
            expect(meta.title).toContain('route.id');
            expect(meta.title).toContain('title_edit');
            expect(meta.title).toContain('title_create');
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('validation_error_banner가 _local.errors 조건부', () => {
            const content = mainLayout.slots.content[0];
            const errorBanner = findById(content, 'validation_error_banner');
            expect(errorBanner).toBeDefined();
            expect(errorBanner.if).toBe('{{_local.errors}}');
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('sticky footer_buttons: 목록 + 저장 버튼', () => {
            const content = mainLayout.slots.content[0];
            const listBtn = findById(content, 'footer_list_button');
            expect(listBtn).toBeDefined();
            const listJson = JSON.stringify(listBtn);
            expect(listJson).toContain('"handler":"navigate"');
            expect(listJson).toContain('/admin/ecommerce/shipping-policies');

            const saveBtn = findById(content, 'footer_save_button');
            expect(saveBtn).toBeDefined();
            // 저장 버튼 외형: btn-primary 시맨틱 자산
            expect(saveBtn.props.className).toContain('btn-primary');
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('저장 버튼 sequence: setState → apiCall → onSuccess/onError', () => {
            const content = mainLayout.slots.content[0];
            const saveBtn = findById(content, 'footer_save_button');
            const json = JSON.stringify(saveBtn);
            expect(json).toContain('"handler":"sequence"');
            expect(json).toContain('"handler":"apiCall"');
            expect(json).toContain('"handler":"toast"');
            expect(json).toContain('"handler":"navigate"');
        });

        /**
         * @scenario section=main
         *
         * @effects shipping_policy_form_main_layout_contract
         */
        it('저장 apiCall: target 사용, body 사용, auth_required', () => {
            const content = mainLayout.slots.content[0];
            const saveBtn = findById(content, 'footer_save_button');
            const findApiCall = (node: any): any => {
                if (!node) return null;
                if (node.handler === 'apiCall') return node;
                if (node.actions) {
                    for (const a of asArray(node?.actions)) {
                        const found = findApiCall(a);
                        if (found) return found;
                    }
                }
                if (callbacksOf(node, 'actions')) {
                    for (const a of callbacksOf(node, 'actions')) {
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
        /**
         * @scenario section=basic_info
         *
         * @effects shipping_policy_form_basic_info_fields_bound
         */
        it('is_partial 메타 설정', () => {
            expect(basicInfoPartial.meta.is_partial).toBe(true);
        });

        /**
         * @scenario section=basic_info
         *
         * @effects shipping_policy_form_basic_info_fields_bound
         */
        it('배송정책명(MultilingualInput) 필드', () => {
            const nameField = findById(basicInfoPartial, 'field_name');
            expect(nameField).toBeDefined();

            const json = JSON.stringify(nameField);
            expect(json).toContain('"name":"MultilingualInput"');
            expect(json).toContain('"name":"name"');
        });

        /**
         * @scenario section=basic_info
         *
         * @effects shipping_policy_form_basic_info_fields_bound
         */
        it('Toggle: is_active, is_default 필드', () => {
            const isActiveField = findById(basicInfoPartial, 'field_is_active');
            expect(isActiveField).toBeDefined();
            expect(JSON.stringify(isActiveField)).toContain('"name":"Toggle"');

            const isDefaultField = findById(basicInfoPartial, 'field_is_default');
            expect(isDefaultField).toBeDefined();
            expect(JSON.stringify(isDefaultField)).toContain('"name":"Toggle"');
        });

        /**
         * @scenario section=basic_info
         *
         * @effects shipping_policy_form_basic_info_fields_bound
         */
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
        /**
         * @scenario section=country_tabs
         *
         * @effects shipping_policy_form_country_tabs_switch_and_add
         */
        it('is_partial 메타 설정', () => {
            expect(countryTabsPartial.meta.is_partial).toBe(true);
        });

        /**
         * @scenario section=country_tabs
         *
         * @effects shipping_policy_form_country_tabs_switch_and_add
         */
        it('국가 추가 영역: addableCountries 기반 조건부', () => {
            const addArea = findById(countryTabsPartial, 'add_country_area');
            expect(addArea).toBeDefined();
            expect(addArea.if).toContain('_computed.addableCountries');
            expect(addArea.if).toContain('length > 0');
        });

        /**
         * @scenario section=country_tabs
         *
         * @effects shipping_policy_form_country_tabs_switch_and_add
         */
        it('국가 추가 Select + Button: addCountrySetting 핸들러', () => {
            const addArea = findById(countryTabsPartial, 'add_country_area');
            const json = JSON.stringify(addArea);
            expect(json).toContain('_computed.addableCountryOptions');
            expect(json).toContain('sirsoft-ecommerce.addCountrySetting');
            expect(json).toContain('_selectedCountryToAdd');
        });

        /**
         * @scenario section=country_tabs
         *
         * @effects shipping_policy_form_country_tabs_switch_and_add
         */
        it('빈 국가 설정 상태 표시', () => {
            const emptyState = findById(countryTabsPartial, 'empty_country_settings');
            expect(emptyState).toBeDefined();
            expect(emptyState.if).toContain('country_settings');
            expect(emptyState.if).toContain('length === 0');
        });

        /**
         * @scenario section=country_tabs
         *
         * @effects shipping_policy_form_country_tabs_switch_and_add
         */
        it('탭 버튼 영역: country_settings.length > 0 조건', () => {
            const tabButtons = findById(countryTabsPartial, 'country_tab_buttons');
            expect(tabButtons).toBeDefined();
            expect(tabButtons.if).toContain('country_settings');
            expect(tabButtons.if).toContain('length > 0');
        });

        /**
         * @scenario section=country_tabs
         *
         * @effects shipping_policy_form_country_tabs_switch_and_add
         */
        it('탭 버튼 iteration: country_settings 기반', () => {
            const tabButtons = findById(countryTabsPartial, 'country_tab_buttons');
            expect(tabButtons).toBeDefined();

            // iteration은 children[0] (Button)에 있음
            const btn = tabButtons.children[0];
            expect(btn.iteration).toBeDefined();
            expect(btn.iteration.source).toContain('_local.form?.country_settings');
            expect(btn.iteration.item_var).toBe('tabItem');
        });

        /**
         * @scenario section=country_tabs
         *
         * @effects shipping_policy_form_country_tabs_switch_and_add
         */
        it('탭 전환 핸들러: switchCountryTab', () => {
            const json = JSON.stringify(countryTabsPartial);
            expect(json).toContain('sirsoft-ecommerce.switchCountryTab');
            expect(json).toContain('"index"');
            expect(json).toContain('tabItem._idx');
        });

        /**
         * @scenario section=country_tabs
         *
         * @effects shipping_policy_form_country_tabs_switch_and_add
         */
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
        /**
         * @scenario section=country_basic_fields
         *
         * @effects shipping_policy_form_country_fields_follow_active_tab
         */
        it('is_partial 메타 설정', () => {
            expect(countryBasicFieldsPartial.meta.is_partial).toBe(true);
        });

        /**
         * @scenario section=country_basic_fields
         *
         * @effects shipping_policy_form_country_fields_follow_active_tab
         */
        it('최상위 조건: _computed.activeCountrySetting', () => {
            expect(countryBasicFieldsPartial.if).toBe('{{_computed.activeCountrySetting}}');
        });

        /**
         * @scenario section=country_basic_fields
         *
         * @effects shipping_policy_form_country_fields_follow_active_tab
         */
        it('국가별 기본 필드: 배송방법, 통화, 사용여부', () => {
            expect(findById(countryBasicFieldsPartial, 'field_country_shipping_method')).toBeDefined();
            expect(findById(countryBasicFieldsPartial, 'field_country_currency')).toBeDefined();
            expect(findById(countryBasicFieldsPartial, 'field_country_is_active')).toBeDefined();
        });

        /**
         * @scenario section=country_basic_fields
         *
         * @effects shipping_policy_form_country_fields_follow_active_tab
         */
        it('배송방법 Select: computed shippingMethodOptions 참조', () => {
            const field = findById(countryBasicFieldsPartial, 'field_country_shipping_method');
            const json = JSON.stringify(field);
            expect(json).toContain('_computed.shippingMethodOptions');
            expect(json).toContain('_computed.activeCountrySetting?.shipping_method');
        });

        /**
         * @scenario section=country_basic_fields
         *
         * @effects shipping_policy_form_country_fields_follow_active_tab
         */
        it('통화: 읽기전용 표시 (Select 아님, 기본 통화 라벨 + 안내 문구)', () => {
            const field = findById(countryBasicFieldsPartial, 'field_country_currency');
            const json = JSON.stringify(field);

            // 통화는 상점 기본 통화로 고정되므로 입력(Select)이 아닌 읽기전용 표시여야 한다.
            expect(json).toContain('_computed.defaultCurrencyLabel');
            expect(json).toContain('currency_code_fixed_hint');

            // 통화 입력/변경 경로가 제거되었는지 (Select·updateCountryField currency_code 분기 없음)
            expect(json).not.toContain('"name": "Select"');
            expect(json).not.toContain('"field": "currency_code"');
            expect(json).not.toContain('_computed.currencyOptions');
        });

        /**
         * @scenario section=country_basic_fields
         *
         * @effects shipping_policy_form_country_fields_follow_active_tab
         */
        it('사용여부 Toggle: activeCountrySetting?.is_active 바인딩', () => {
            const field = findById(countryBasicFieldsPartial, 'field_country_is_active');
            const json = JSON.stringify(field);
            expect(json).toContain('_computed.activeCountrySetting?.is_active');
        });

        /**
         * @scenario section=country_basic_fields
         *
         * @effects shipping_policy_form_country_fields_follow_active_tab
         */
        it('국가 삭제: country_settings.length > 1 조건 + removeCountrySetting', () => {
            const removeArea = findById(countryBasicFieldsPartial, 'country_remove_area');
            expect(removeArea).toBeDefined();
            expect(removeArea.if).toContain('country_settings');
            expect(removeArea.if).toContain('length > 1');

            const json = JSON.stringify(removeArea);
            expect(json).toContain('sirsoft-ecommerce.removeCountrySetting');
        });

        /**
         * @scenario section=country_basic_fields
         *
         * @effects shipping_policy_form_country_fields_follow_active_tab
         */
        it('모든 필드가 updateCountryField 커스텀 핸들러 사용', () => {
            const json = JSON.stringify(countryBasicFieldsPartial);
            expect(json).toContain('sirsoft-ecommerce.updateCountryField');
            // setState with {{}} keys 패턴 미사용 확인
            expect(json).not.toContain('form.country_settings[{{');
        });
    });

    // ===== 상세 설정 Partial =====

    describe('Partial: 상세 설정 (_partial_charge_settings.json)', () => {
        /**
         * @scenario section=charge_settings
         *
         * @effects shipping_policy_form_charge_fields_toggle_by_policy
         */
        it('is_partial 메타 설정', () => {
            expect(chargeSettingsPartial.meta.is_partial).toBe(true);
        });

        /**
         * @scenario section=charge_settings
         *
         * @effects shipping_policy_form_charge_fields_toggle_by_policy
         */
        it('최상위 조건: _computed.activeCountrySetting', () => {
            expect(chargeSettingsPartial.if).toBe('{{_computed.activeCountrySetting}}');
        });

        /**
         * @scenario section=charge_settings
         *
         * @effects shipping_policy_form_charge_fields_toggle_by_policy
         */
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

        /**
         * @scenario section=charge_settings
         *
         * @effects shipping_policy_form_charge_fields_toggle_by_policy
         */
        it('배송비 필드 조건부: showBaseFee', () => {
            const baseFeeField = findById(chargeSettingsPartial, 'field_base_fee');
            expect(baseFeeField).toBeDefined();
            expect(baseFeeField.if).toBe('{{_local.showBaseFee}}');
        });

        /**
         * @scenario section=charge_settings
         *
         * @effects shipping_policy_form_charge_fields_toggle_by_policy
         */
        it('무료배송 기준금액 조건부: showFreeThreshold', () => {
            const thresholdField = findById(chargeSettingsPartial, 'field_free_threshold');
            expect(thresholdField).toBeDefined();
            expect(thresholdField.if).toBe('{{_local.showFreeThreshold}}');
        });

        /**
         * @scenario section=charge_settings
         *
         * @effects shipping_policy_form_charge_fields_toggle_by_policy
         */
        it('단위당 설정 조건부: showUnitValue', () => {
            const unitField = findById(chargeSettingsPartial, 'field_unit_value');
            expect(unitField).toBeDefined();
            expect(unitField.if).toBe('{{_local.showUnitValue}}');
        });

        /**
         * @scenario section=charge_settings
         *
         * @effects shipping_policy_form_charge_fields_toggle_by_policy
         */
        it('구간별 배송비 조건부: showRanges + iteration (activeCountrySetting 기반)', () => {
            const rangesField = findById(chargeSettingsPartial, 'field_ranges');
            expect(rangesField).toBeDefined();
            expect(rangesField.if).toBe('{{_local.showRanges}}');

            // tier 행 iteration
            // id 는 반복 인덱스를 포함한 템플릿 형태다 — 접두어만으로 찾으면 null 이 돌아오고,
            // toBeDefined() 는 null 도 통과시키므로 존재 확인은 not.toBeNull() 로 한다.
            const tierRow = findById(chargeSettingsPartial, 'range_tier_row_{{tierIdx}}');
            expect(tierRow).not.toBeNull();
            expect(tierRow.iteration).toBeDefined();
            expect(tierRow.iteration.source).toBe('{{_computed.activeCountrySetting?.ranges?.tiers ?? []}}');
            expect(tierRow.iteration.item_var).toBe('tier');
            expect(tierRow.iteration.index_var).toBe('tierIdx');
        });

        /**
         * @scenario section=charge_settings
         *
         * @effects shipping_policy_form_charge_fields_toggle_by_policy
         */
        it('구간 추가/삭제 핸들러 연결', () => {
            const json = JSON.stringify(chargeSettingsPartial);
            expect(json).toContain('sirsoft-ecommerce.addRangeTier');
            expect(json).toContain('sirsoft-ecommerce.removeRangeTier');
        });

        /**
         * @scenario section=charge_settings
         *
         * @effects shipping_policy_form_charge_fields_toggle_by_policy
         */
        it('API 설정 조건부: showApiSettings', () => {
            const apiSection = findById(chargeSettingsPartial, 'field_api_settings');
            expect(apiSection).toBeDefined();
            expect(apiSection.if).toBe('{{_local.showApiSettings}}');
        });

        /**
         * @scenario section=charge_settings
         *
         * @effects shipping_policy_form_charge_fields_toggle_by_policy
         */
        it('배송비 Input: activeCountrySetting?.base_fee 바인딩', () => {
            const baseFeeField = findById(chargeSettingsPartial, 'field_base_fee');
            const json = JSON.stringify(baseFeeField);
            expect(json).toContain('_computed.activeCountrySetting?.base_fee');
        });

        /**
         * @scenario section=charge_settings
         *
         * @effects shipping_policy_form_charge_fields_toggle_by_policy
         */
        it('통화 접미사: 배송비/무료기준 입력칸 모두 기본 통화 SSoT(defaultCurrencyCode) 표시', () => {
            const json = JSON.stringify(chargeSettingsPartial);
            // 기준 통화 필드와 동일한 SSoT(_computed.defaultCurrencyCode)를 따라야 한다
            expect(json).toContain('_computed.defaultCurrencyCode');
            // 옛 per-country 바인딩 + KRW 하드코딩 폴백이 남아 있으면 안 됨
            expect(json).not.toContain("activeCountrySetting?.currency_code ?? 'KRW'");
            expect(json).not.toContain('KRW');
        });

        /**
         * @scenario section=charge_settings
         *
         * @effects shipping_policy_form_charge_fields_toggle_by_policy
         */
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
        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
        it('is_partial 메타 설정', () => {
            expect(extraFeePartial.meta.is_partial).toBe(true);
        });

        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
        it('최상위 조건: activeCountrySetting + KR 국가 전용', () => {
            expect(extraFeePartial.if).toBe(
                '{{_computed.activeCountrySetting && _computed.activeCountryCode === \'KR\'}}'
            );
        });

        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
        it('KR 전용 배지 표시', () => {
            const json = JSON.stringify(extraFeePartial);
            expect(json).toContain('extra_fee_kr_only');
        });

        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
        it('extra_fee_enabled Toggle: activeCountrySetting 바인딩', () => {
            const enabledField = findById(extraFeePartial, 'field_extra_fee_enabled');
            expect(enabledField).toBeDefined();
            const json = JSON.stringify(enabledField);
            expect(json).toContain('"name":"Toggle"');
            expect(json).toContain('_computed.activeCountrySetting?.extra_fee_enabled');
        });

        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
        it('상세 영역 조건부: activeCountrySetting?.extra_fee_enabled', () => {
            const detailSection = findById(extraFeePartial, 'extra_fee_detail');
            expect(detailSection).toBeDefined();
            expect(detailSection.if).toBe('{{_computed.activeCountrySetting?.extra_fee_enabled}}');
        });

        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
        it('extra_fee_multiply Toggle', () => {
            const multiplyToggle = findById(extraFeePartial, 'extra_fee_multiply_toggle');
            expect(multiplyToggle).toBeDefined();
            expect(JSON.stringify(multiplyToggle)).toContain('_computed.activeCountrySetting?.extra_fee_multiply');
        });

        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
        it('템플릿 불러오기 버튼: sequence(refetchDataSource → openModal)', () => {
            const json = JSON.stringify(extraFeePartial);
            expect(json).toContain('"handler":"refetchDataSource"');
            expect(json).toContain('"dataSourceId":"extra_fee_templates"');
            expect(json).toContain('"handler":"openModal"');
            expect(json).toContain('"target":"extra_fee_template"');

            // openModal의 target은 액션 레벨에 위치해야 함 (params 내부 금지)
            const header = findById(extraFeePartial, 'extra_fee_settings_header');
            const loadBtn = header.children[1].children[0]; // 첫 번째 버튼 (템플릿 불러오기)
            const seqActions = callbacksOf(asArray(loadBtn?.actions)[0], 'actions');
            const openModalAction = seqActions.find((a: any) => a.handler === 'openModal');
            expect(openModalAction).toBeDefined();
            expect(openModalAction.target).toBe('extra_fee_template');
            expect(openModalAction).not.toHaveProperty('params');
        });

        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
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

        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
        it('PC: 지역명(region) 입력 필드 포함', () => {
            const json = JSON.stringify(extraFeePartial);
            // region 필드 바인딩
            expect(json).toContain('feeItem.region');
            // region 필드 업데이트 핸들러
            expect(json).toContain('"field":"region"');
        });

        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
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

        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
        it('행 추가/삭제 핸들러에 countryIndex 파라미터', () => {
            const json = JSON.stringify(extraFeePartial);
            expect(json).toContain('sirsoft-ecommerce.addExtraFeeRow');
            expect(json).toContain('sirsoft-ecommerce.removeExtraFeeRow');
            expect(json).toContain('"countryIndex"');
        });

        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
        it('빈 상태 메시지: activeCountrySetting 기반 조건', () => {
            const json = JSON.stringify(extraFeePartial);
            expect(json).toContain('form.no_extra_fee');
            expect(json).toContain('_computed.activeCountrySetting?.extra_fee_settings');
        });

        /**
         * @scenario section=extra_fee
         *
         * @effects shipping_policy_form_extra_fee_is_kr_only_and_bound
         */
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
        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('Modal 컴포넌트 구조: width 900px + 반응형', () => {
            expect(extraFeeTemplateModal.type).toBe('composite');
            expect(extraFeeTemplateModal.name).toBe('Modal');
            expect(extraFeeTemplateModal.props.title).toBe(
                '$t:sirsoft-ecommerce.admin.shipping_policy.modal_extra_fee.title'
            );
            expect(extraFeeTemplateModal.props.width).toBe('900px');
            expect(extraFeeTemplateModal.props.className).toContain('max-w-');
        });

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('is_partial 메타 설정', () => {
            expect(extraFeeTemplateModal.meta.is_partial).toBe(true);
        });

        // ---- 검색 섹션 ----

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
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

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
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

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
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

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
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

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('빈 상태 처리', () => {
            const emptyState = findById(extraFeeTemplateModal, 'empty_state');
            expect(emptyState).toBeDefined();
            expect(emptyState.if).toContain('extra_fee_templates?.data?.data');
            expect(emptyState.if).toContain('length === 0');
            const json = JSON.stringify(emptyState);
            expect(json).toContain('modal_extra_fee.no_templates');
        });

        // ---- CRUD 핸들러 ----

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('삭제 핸들러: confirm 속성 + apiCall(DELETE) + auth_required', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // apiCall DELETE 존재
            expect(json).toContain('"method":"DELETE"');
            // confirm 속성 사용 (handler가 아닌 action property)
            expect(json).toContain('"confirm"');
            expect(json).toContain('modal_extra_fee.delete_confirm');
        });

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('일괄 삭제 핸들러: confirm 속성 + apiCall(DELETE /bulk)', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            expect(json).toContain('/extra-fee-templates/bulk');
            expect(json).toContain('modal_extra_fee.bulk_delete_confirm');
        });

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('저장/추가 핸들러: 신규(POST) + 수정(PUT) 분리 + auth_required', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // 신규 추가 시 POST (new_template_row에서)
            expect(json).toContain('"method":"POST"');
            // 수정 시 PUT (인라인 편집에서)
            expect(json).toContain('"method":"PUT"');
            // auth_required 존재
            expect(json).toContain('"auth_required":true');
        });

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
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

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
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

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
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

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('모든 CRUD state는 $parent._local 사용', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // $parent._local 패턴 존재 확인
            expect(json).toContain('$parent._local');
            // templateSearch, selectedTemplateIds, templateForm 등
            expect(json).toContain('$parent._local.templateSearch');
            expect(json).toContain('$parent._local.selectedTemplateIds');
            expect(json).toContain('$parent._local.templateForm');
        });

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('로딩 상태는 _global 사용', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            expect(json).toContain('_global.isTemplateSaving');
            expect(json).toContain('_global.isTemplateDeleting');
        });

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('setState target: CRUD → $parent._local, 로딩 → global', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // $parent._local target 존재
            expect(json).toContain('"target":"$parent._local"');
            // global target 존재
            expect(json).toContain('"target":"global"');
        });

        // ---- Button type 규칙 ----

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('모든 Button에 type="button" 명시 (form submit 방지)', () => {
            const allButtons: any[] = [];
            const findButtons = (node: any) => {
                if (!node) return;
                if (node.name === 'Button') {
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

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('닫기 버튼 (footer)', () => {
            const footer = findById(extraFeeTemplateModal, 'modal_footer');
            expect(footer).toBeDefined();
            const json = JSON.stringify(footer);
            expect(json).toContain('"handler":"closeModal"');
        });

        // ---- onSuccess/onError 순서 ----

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('onSuccess에서 refetchDataSource 호출', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // 모든 CUD 작업 후 데이터 새로고침
            const refetchMatches = json.match(/"handler":"refetchDataSource"/g);
            expect(refetchMatches).toBeDefined();
            // 검색 + CRUD onSuccess에서 다수 사용
            expect(refetchMatches!.length).toBeGreaterThanOrEqual(2);
        });

        // ---- 필드별 에러 표시 (templateFormErrors) ----

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('모달 JSON에 templateFormErrors 참조 존재', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            expect(json).toContain('templateFormErrors');
        });

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('zipcode/region/fee Input에 조건부 input-error 시맨틱 클래스', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // 에러 시 input-error 시맨틱 자산 (원시 border-red-500 흡수)
            expect(json).toContain('templateFormErrors?.zipcode');
            expect(json).toContain('templateFormErrors?.fee');
            expect(json).toContain('templateFormErrors?.region');
            expect(json).toContain('input-error');
        });

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('에러 문구 Span이 templateFormErrors 필드별로 존재', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // 각 필드 에러의 첫 번째 메시지 표시
            expect(json).toContain('templateFormErrors?.zipcode?.[0]');
            expect(json).toContain('templateFormErrors?.fee?.[0]');
            expect(json).toContain('templateFormErrors?.region?.[0]');
        });

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
        it('onError에서 templateFormErrors에 error.errors 저장', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // apiCall onError에서 필드별 에러 상태 세팅
            expect(json).toContain('error.errors ?? {}');
        });

        /**
         * @scenario section=extra_fee_template_modal
         *
         * @effects shipping_policy_form_extra_fee_template_modal_crud
         */
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
        /**
         * @scenario section=i18n
         *
         * @effects shipping_policy_form_i18n_keys_resolve
         */
        it('enum 키가 computed에서 admin.shipping_policy.enums.* 경로 사용 (charge_policy 만 hardcoded)', () => {
            // shipping_method 는 ecommerce_settings 동적 → enums 키 미사용
            // charge_policy 는 정적 옵션 → enums 키 유지
            const computedJson = JSON.stringify(mainLayout.computed);
            expect(computedJson).toContain('admin.shipping_policy.enums.charge_policy');
        });

        /**
         * @scenario section=i18n
         *
         * @effects shipping_policy_form_i18n_keys_resolve
         */
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

        /**
         * @scenario section=i18n
         *
         * @effects shipping_policy_form_i18n_keys_resolve
         */
        it('추가/내보내기/가져오기/선택삭제 버튼에 $parent._computed.isReadOnly disabled', () => {
            const toolbar = findById(extraFeeTemplateModal, 'toolbar_section');
            const json = JSON.stringify(toolbar);
            // 추가 버튼: isReadOnly OR 추가
            expect(json).toContain('$parent._computed?.isReadOnly !== false');
            // 선택 삭제 버튼: isTemplateDeleting || isReadOnly
            expect(json).toContain('_global.isTemplateDeleting || $parent._computed?.isReadOnly !== false');
            // 전체 내보내기 버튼
            expect(json).toContain('"disabled":"{{$parent._computed?.isReadOnly !== false}}"');
            // disabled 스타일 클래스
            expect(json).toContain('disabled:opacity-50');
            expect(json).toContain('disabled:cursor-not-allowed');
        });

        /**
         * @scenario section=i18n
         *
         * @effects shipping_policy_form_i18n_keys_resolve
         */
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
                expect(cb.props.disabled).toContain('$parent._computed?.isReadOnly !== false');
            });
        });

        /**
         * @scenario section=i18n
         *
         * @effects shipping_policy_form_i18n_keys_resolve
         */
        it('신규 입력 폼 Input(zipcode/region/fee)에 disabled', () => {
            const newRow = findById(extraFeeTemplateModal, 'new_template_row');
            const json = JSON.stringify(newRow);
            // 3개 Input 필드에 disabled 추가
            const disabledCount = (json.match(/\$parent\._computed\?\.isReadOnly !== false/g) || []).length;
            // zipcode, region, fee Input + 저장 버튼 = 최소 4개소
            expect(disabledCount).toBeGreaterThanOrEqual(4);
        });

        /**
         * @scenario section=i18n
         *
         * @effects shipping_policy_form_i18n_keys_resolve
         */
        it('편집모드 Input/Toggle/버튼에 $parent._computed.isReadOnly disabled', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // 전체 모달에서 isReadOnly 참조 총 개수 (17개소) — 부모 값을 모르면 잠긴 쪽(`!== false`)
            const totalCount = (json.match(/\$parent\._computed\?\.isReadOnly !== false/g) || []).length;
            expect(totalCount).toBeGreaterThanOrEqual(17);
        });

        /**
         * @scenario section=i18n
         *
         * @effects shipping_policy_form_i18n_keys_resolve
         */
        it('편집/삭제 버튼에 disabled:opacity-50 disabled:cursor-not-allowed 클래스', () => {
            const json = JSON.stringify(extraFeeTemplateModal);
            // pencil(편집) 버튼 disabled 스타일
            expect(json).toContain('btn-icon text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 disabled:opacity-50 disabled:cursor-not-allowed');
            // trash(삭제) 버튼 disabled 스타일
            expect(json).toContain('btn-icon text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 disabled:opacity-50 disabled:cursor-not-allowed');
        });

        /**
         * @scenario section=i18n
         *
         * @effects shipping_policy_form_i18n_keys_resolve
         */
        it('모든 form 키가 admin.shipping_policy.form.* 경로 사용', () => {
            const formKeys = extractI18nKeys(basicInfoPartial).filter(k =>
                k.includes('.form.') && k.startsWith('sirsoft-ecommerce.')
            );
            formKeys.forEach(key => {
                expect(key).toMatch(/^sirsoft-ecommerce\.admin\.shipping_policy\.form\./);
            });
        });

        /**
         * @scenario section=i18n
         *
         * @effects shipping_policy_form_i18n_keys_resolve
         */
        it('메인 레이아웃 title이 admin.shipping_policy.title_* 경로 사용', () => {
            const keys = extractI18nKeys(mainLayout);
            const titleKeys = keys.filter(k => k.includes('title_'));
            titleKeys.forEach(key => {
                expect(key).not.toContain('.form.title_');
                expect(key).toMatch(/admin\.shipping_policy\.title_/);
            });
        });

        /**
         * @scenario section=i18n
         *
         * @effects shipping_policy_form_i18n_keys_resolve
         */
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

        /**
         * @scenario section=i18n
         *
         * @effects shipping_policy_form_i18n_keys_resolve
         */
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

    // ===== 등록/수정 안내 문구의 술어 (19차) =====

    describe('저장 성공 토스트는 route.id 로만 등록/수정을 가른다', () => {
        /**
         * `savedPolicyId` 는 **요청 주소를 정하는 값**이다 — 신규 등록에서 정책 POST 는 성공했는데
         * 뒤이은 회수지 PUT 이 거절되면 화면에 머무는데, `route.id` 는 여전히 비어 있어 다시 [저장]
         * 을 누르면 같은 정책이 하나 더 생긴다. 그래서 첫 성공 시점에 그 id 를 붙잡는다.
         *
         * 그런데 그 값을 **"수정이었는가" 의 술어로 쓰면 안 된다.** `ActionDispatcher.handleSequence`
         * 는 `__target:'local'` setState 의 결과를 `currentState` 에 병합해 같은 시퀀스의 뒤 액션
         * 스냅샷에 싣는다. 그래서 `savedPolicyId` 를 채운 줄 다음부터는 신규 등록에서도 언제나
         * truthy 이고, 토스트가 그것을 읽으면 새로 만든 정책에 "수정되었습니다" 가 나간다.
         *
         * 이 검사의 **모집단은 레이아웃에서 파생**한다 — 등록/수정 문구를 쓰는 토스트를 전부 찾아
         * 각각의 술어를 본다. 성공 경로가 하나 더 생겨도(회수지 저장 유무로 이미 둘이다) 자동으로
         * 검사 대상이 된다.
         */

        /** 등록/수정 문구를 쓰는 토스트 액션의 message 표현식을 레이아웃에서 전부 뽑는다 */
        function saveToastMessages(): string[] {
            const found: string[] = [];

            const walk = (node: any): void => {
                if (!node || typeof node !== 'object') return;

                if (Array.isArray(node)) {
                    node.forEach(walk);
                    return;
                }

                const message = node?.handler === 'toast' ? node?.params?.message : undefined;

                if (typeof message === 'string' && message.includes('shipping_policy.messages.created')) {
                    found.push(message);
                }

                Object.values(node).forEach(walk);
            };

            walk(mainLayout);

            return found;
        }

        /**
         * @scenario section=save_toast
         *
         * @effects shipping_policy_form_save_toast_follows_route_id
         */
        it('모집단이 비어 있지 않다 (성공 토스트를 못 찾으면 아래 검사가 통째로 사문이 된다)', () => {
            // 회수지를 저장하는 경로와 저장하지 않는 경로 둘 다 자기 토스트를 갖는다
            expect(saveToastMessages().length).toBeGreaterThanOrEqual(2);
        });

        /**
         * @scenario section=save_toast
         *
         * @effects shipping_policy_form_save_toast_follows_route_id
         */
        it('등록/수정 판정에 savedPolicyId 를 읽지 않는다', () => {
            for (const message of saveToastMessages()) {
                // 읽으면 신규 등록에서도 truthy 라 "수정되었습니다" 가 나간다
                expect(message).not.toContain('savedPolicyId');
            }
        });

        /**
         * @scenario section=save_toast
         *
         * @effects shipping_policy_form_save_toast_follows_route_id
         */
        it('등록/수정 판정은 route.id 가 한다', () => {
            for (const message of saveToastMessages()) {
                expect(message).toContain('route.id ?');
                expect(message).toContain('shipping_policy.messages.updated');
            }
        });

        /**
         * @scenario section=save_toast
         *
         * @effects shipping_policy_form_save_toast_follows_route_id
         */
        it('삼항의 방향이 뒤집혀 있지 않다 (route.id 가 있으면 "수정")', () => {
            // 위 두 검사는 "`route.id ?` 를 쓴다" 와 "updated 문구를 쓴다" 만 본다 — 그래서
            // `route.id ? …created : …updated` 로 **뒤집어도 셋 다 통과**한다. 그 상태의 증상은
            // 19차가 고친 결함과 정확히 같다(새로 만든 정책에 "수정되었습니다"). 방향까지 고정한다.
            for (const message of saveToastMessages()) {
                const branches = /route\.id\s*\?\s*'([^']+)'\s*:\s*'([^']+)'/.exec(message);

                expect(branches, `삼항의 두 갈래를 읽지 못했다: ${message}`).not.toBeNull();
                expect(branches![1], 'route.id 가 있는 갈래(수정)가 updated 를 가리켜야 한다').toContain(
                    'shipping_policy.messages.updated',
                );
                expect(branches![2], 'route.id 가 없는 갈래(신규)가 created 를 가리켜야 한다').toContain(
                    'shipping_policy.messages.created',
                );
            }
        });
    });
});
