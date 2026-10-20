/**
 * 쿠폰 등록/수정 폼 레이아웃 렌더링 테스트
 *
 * @description
 * - 메인 레이아웃 JSON 구조 및 데이터소스 검증
 * - Partial: 기본 정보 필드 구조 검증
 * - Partial: 혜택 설정 필드 구조 검증
 * - Partial: 발급 설정 필드 구조 검증
 * - Partial: 사용 조건 설정 필드 구조 검증
 * - i18n 키 경로 검증
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import mainLayout from '../../../layouts/admin/admin_ecommerce_promotion_coupon_form.json';
import basicInfoPartial from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_form/_partial_basic_info.json';
import benefitSettingsPartial from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_form/_partial_benefit_settings.json';
import issueSettingsPartial from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_form/_partial_issue_settings.json';
import usageConditionsPartial from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_form/_partial_usage_conditions.json';
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
    // slots 내부도 탐색
    if (node.slots) {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren as any[]) {
                    const found = findById(child, id);
                    if (found) return found;
                }
            }
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

/** JSON 트리에서 name으로 컴포넌트를 재귀 탐색 (첫 번째 매치) */
function findByName(node: any, name: string): any {
    if (!node) return null;
    if (node.name === name) return node;
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findByName(child, name);
            if (found) return found;
        }
    }
    return null;
}

/** JSON 트리에서 특정 name의 모든 컴포넌트를 수집 */
function findAllByName(node: any, name: string, results: any[] = []): any[] {
    if (!node) return results;
    if (node.name === name) results.push(node);
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            findAllByName(child, name, results);
        }
    }
    return results;
}

/**
 * 카드 본문의 필드 컨테이너(FormField 들을 직접 자식으로 갖는 Div)를 찾는다.
 * 시맨틱화 회귀 검출용: 이 컨테이너에 row-stack 이 있어야 필드 사이
 * 세로 여백(divide-y + py-2)이 적용된다. 불필요한 빈 Div 중첩이 끼면
 * row-stack 이 손자에 도달하지 못해 간격이 사라진다.
 */
function findFieldContainer(partial: any): any {
    const queue: any[] = [partial];
    while (queue.length) {
        const node = queue.shift();
        if (
            node?.name === 'Div' &&
            Array.isArray(node.children) &&
            node.children.some((c: any) => c?.name === 'FormField' || c?.id?.startsWith?.('field_'))
        ) {
            return node;
        }
        if (Array.isArray(node?.children)) queue.push(...node.children);
    }
    return null;
}

// ===== 메인 레이아웃 =====

describe('couponFormLayouts', () => {
    describe('메인 레이아웃 (admin_ecommerce_promotion_coupon_form.json)', () => {
        // @scenario section=main_layout
        // @effects coupon_form_layout_state_and_sources
        it('올바른 레이아웃 기본 구조', () => {
            expect(mainLayout.version).toBe('1.0.0');
            expect(mainLayout.layout_name).toBe('admin_ecommerce_promotion_coupon_form');
            expect(mainLayout.extends).toBe('_admin_base');
        });

        // @scenario section=main_layout
        // @effects coupon_form_layout_state_and_sources
        it('state에 폼 기본값 존재', () => {
            const state = initStateOf(mainLayout);
            expect(state).toBeDefined();
            expect(state.form).toBeDefined();

            // 주요 폼 필드 기본값
            expect(state.form.target_type).toBe('product_amount');
            expect(state.form.issue_method).toBe('direct');
            expect(state.form.issue_condition).toBe('manual');
            expect(state.form.discount_type).toBe('fixed');
            expect(state.form.valid_type).toBe('period');
            expect(state.form.is_combinable).toBe(true);
            expect(state.form.target_scope).toBe('all');
            expect(state.form.issue_status).toBe('issuing');

            // 다국어 필드
            expect(state.form.name).toEqual({ ko: '', en: '' });
            expect(state.form.description).toEqual({ ko: '', en: '' });

            // nullable 필드
            expect(state.form.discount_value).toBeNull();
            expect(state.form.discount_max_amount).toBeNull();
            expect(state.form.min_order_amount).toBeNull();
            expect(state.form.valid_from).toBeNull();
            expect(state.form.valid_to).toBeNull();
            expect(state.form.valid_days).toBeNull();
            expect(state.form.total_quantity).toBeNull();
            expect(state.form.issue_from).toBeNull();
            expect(state.form.issue_to).toBeNull();
            expect(state.form.per_user_limit).toBe(0);

            // 배열 필드
            expect(state.form.products).toEqual([]);
            expect(state.form.categories).toEqual([]);
        });

        // @scenario section=main_layout
        // @effects coupon_form_layout_state_and_sources
        it('state에 UI 전용 상태 기본값', () => {
            const state = initStateOf(mainLayout);
            expect(state.isSaving).toBe(false);
            expect(state.errors).toBeNull();

            // 라디오 그룹 UI 전용 상태
            expect(state.issue_quantity_type).toBe('unlimited');
            expect(state.issue_period_type).toBe('unlimited');
            expect(state.reissue_type).toBe('unlimited');

            // 상품 검색 UI 상태
            expect(state.productSearchOpen).toBe(false);
            expect(state.productSearchField).toBe('all');
            expect(state.productSearchKeyword).toBe('');
            expect(state.productSearchResults).toEqual([]);
            expect(state.productSearchSelected).toEqual([]);

            // 상품 검색 페이지네이션 상태 (무한스크롤)
            expect(state.productSearchPage).toBe(1);
            expect(state.productSearchLastPage).toBe(1);
            expect(state.productSearchTotal).toBe(0);
            expect(state.productSearchIsLoadingMore).toBe(false);

            // 카테고리 검색 UI 상태
            expect(state.categorySearchOpen).toBe(false);
            expect(state.categorySearchKeyword).toBe('');
            expect(state.categoryExpandedIds).toEqual([]);
            expect(state.categorySearchSelected).toEqual([]);

            // DataGrid 선택 상태
            expect(state.selectedProductIds).toEqual([]);
            expect(state.selectedCategoryIds).toEqual([]);
        });

        // @scenario section=main_layout
        // @effects coupon_form_layout_state_and_sources
        it('data_sources: coupon (GET, blocking, edit mode)', () => {
            const couponDs = mainLayout.data_sources.find((ds: any) => ds.id === 'coupon');
            expect(couponDs).toBeDefined();
            expect(couponDs!.method).toBe('GET');
            expect(couponDs!.if).toBe('{{!!route.id}}');
            expect(couponDs!.loading_strategy).toBe('blocking');
            expect(couponDs!.auth_mode).toBe('required');

            // initLocal map 형태: form + categories/products 매핑 + UI 상태 복원
            const initLocal = couponDs!.initLocal;
            expect(typeof initLocal).toBe('object');
            expect(initLocal['form']).toBe('{{data}}');
            expect(initLocal['form.categories']).toContain('included_categories');
            expect(initLocal['form.categories']).toContain('excluded_categories');
            expect(initLocal['form.products']).toContain('included_products');
            expect(initLocal['form.products']).toContain('excluded_products');
            expect(initLocal['issue_quantity_type']).toContain('total_quantity');
            expect(initLocal['issue_period_type']).toContain('issue_from');
            expect(initLocal['reissue_type']).toContain('per_user_limit');
        });

        // @scenario section=main_layout
        // @effects coupon_form_layout_state_and_sources
        it('data_sources: categories (GET, progressive)', () => {
            const categoriesDs = mainLayout.data_sources.find((ds: any) => ds.id === 'categories');
            expect(categoriesDs).toBeDefined();
            expect(categoriesDs!.method).toBe('GET');
            expect(categoriesDs!.endpoint).toContain('categories');
            expect(categoriesDs!.loading_strategy).toBe('progressive');
            expect(categoriesDs!.auth_mode).toBe('required');
        });

        // @scenario section=main_layout
        // @effects coupon_form_edit_mapping_not_clobbered
        it('init_actions 가 폼 매핑(initLocal 키)을 덮어쓰지 않는다 — 매핑은 initLocal map 에서만', () => {
            // 원래 위험(#16): init_actions 가 데이터 도착 전 _local.form 으로 form.products·form.categories·
            // issue_*_type·reissue_type 을 다시 계산해 initLocal 이 채운 값을 덮어써 수정 모드에서 카테고리·상품이 사라졌다.
            // 매핑은 coupon 데이터소스의 initLocal map 에서만 한다. init_actions 는 그 키들을 쓰지 않는 한 허용한다
            // — 이탈 확인(meta.leave_guard)용 hasChanges=false 초기화(7.1.1)가 그 예다.
            const mappedKeys = Object.keys(mainLayout.data_sources.find((ds: any) => ds.id === 'coupon').initLocal);
            expect(mappedKeys).toEqual(expect.arrayContaining(['form', 'form.products', 'form.categories', 'issue_quantity_type', 'issue_period_type', 'reissue_type']));
            const writes: string[] = [];
            for (const action of (mainLayout.init_actions ?? []) as any[]) {
                expect(action.handler).toBe('setState');
                expect(action.params?.merge).toBeUndefined();
                for (const key of Object.keys(action.params ?? {})) {
                    if (key === 'target' || key === 'merge') continue;
                    writes.push(key);
                }
            }
            const clobbers = writes.filter((key) => mappedKeys.some((m: string) => key === m || key.startsWith(m + '.') || m.startsWith(key + '.')));
            expect(clobbers).toEqual([]);
        });

        // @scenario section=main_layout
        // @effects coupon_form_layout_state_and_sources
        it('slots.content에 partials 4개 참조 (extends 레이아웃)', () => {
            // extends: "_admin_base" 사용 시 slots.content에 컴포넌트 배치
            const { slots } = mainLayout;
            expect(slots).toBeDefined();
            expect(slots.content).toBeDefined();

            // Partial 참조 여부 확인 (JSON 문자열에서 partial 경로 검색)
            const json = JSON.stringify(slots.content);
            expect(json).toContain('_partial_basic_info');
            expect(json).toContain('_partial_benefit_settings');
            expect(json).toContain('_partial_issue_settings');
            expect(json).toContain('_partial_usage_conditions');
        });

        // @scenario section=main_layout
        // @effects coupon_form_save_sequence
        it('저장 액션: apiCall with sequence', () => {
            const json = JSON.stringify(mainLayout);
            // 저장 핸들러가 sequence 패턴 사용
            expect(json).toContain('"handler":"sequence"');
            // API 호출 포함
            expect(json).toContain('"handler":"apiCall"');
            // 토스트 메시지 포함
            expect(json).toContain('"handler":"toast"');
        });
    });

    // ===== Partial 1: 기본 정보 =====

    describe('Partial: 기본 정보 (_partial_basic_info.json)', () => {
        // @scenario section=basic_info
        // @effects coupon_form_section_card_layout
        it('Div 컴포넌트로 래핑 (카드 스타일링)', () => {
            expect(basicInfoPartial.name).toBe('Div');
            expect(basicInfoPartial.type).toBe('basic');
            // 카드 스타일이 'card' 유틸리티 클래스로 통일됨
            expect(basicInfoPartial.props.className).toContain('card');
        });

        // @scenario section=basic_info
        // @effects coupon_form_choice_options_match_enums
        it('적용대상 RadioGroup: 3가지 옵션 (product_amount, order_amount, shipping_fee)', () => {
            const field = findById(basicInfoPartial, 'field_target_type');
            expect(field).toBeDefined();
            expect(field.name).toBe('FormField');

            const radioGroup = findByName(field, 'RadioGroup');
            expect(radioGroup).toBeDefined();
            expect(radioGroup.props.name).toBe('target_type');
            expect(radioGroup.props.options).toHaveLength(3);

            const values = radioGroup.props.options.map((o: any) => o.value);
            expect(values).toEqual(['product_amount', 'order_amount', 'shipping_fee']);
        });

        // @scenario section=basic_info
        // @effects coupon_form_choice_options_match_enums
        it('발급방법 RadioGroup: 3가지 옵션 (direct, download, auto)', () => {
            const field = findById(basicInfoPartial, 'field_issue_method');
            expect(field).toBeDefined();

            const radioGroup = findByName(field, 'RadioGroup');
            expect(radioGroup).toBeDefined();
            expect(radioGroup.props.name).toBe('issue_method');
            expect(radioGroup.props.options).toHaveLength(3);

            const values = radioGroup.props.options.map((o: any) => o.value);
            expect(values).toEqual(['direct', 'download', 'auto']);
        });

        // @scenario section=basic_info
        // @effects coupon_form_conditional_fields_follow_choice
        it('발급방법 변경 시 issue_condition 자동 설정 액션', () => {
            const field = findById(basicInfoPartial, 'field_issue_method');
            const radioGroup = findByName(field, 'RadioGroup');
            expect(radioGroup.actions).toBeDefined();
            expect(asArray(radioGroup?.actions).length).toBeGreaterThan(0);

            const action = asArray(radioGroup?.actions)[0];
            expect(action.handler).toBe('setState');
            expect(action.params['form.issue_condition']).toContain('auto');
        });

        // @scenario section=basic_info
        // @effects coupon_form_conditional_fields_follow_choice
        it('자동발급 시 발급조건 RadioGroup 표시: 3가지 조건', () => {
            const field = findById(basicInfoPartial, 'field_issue_condition_auto');
            expect(field).toBeDefined();
            expect(field.if).toContain('auto');

            const radioGroup = findByName(field, 'RadioGroup');
            expect(radioGroup.props.options).toHaveLength(3);

            const values = radioGroup.props.options.map((o: any) => o.value);
            expect(values).toEqual(['signup', 'first_purchase', 'birthday']);
        });

        // @scenario section=basic_info
        // @effects coupon_form_conditional_fields_follow_choice
        it('수동발급 RadioGroup (직접발급/다운로드 시)', () => {
            const field = findById(basicInfoPartial, 'field_issue_condition_manual');
            expect(field).toBeDefined();
            expect(field.if).toContain("!== 'auto'");
            expect(field.name).toBe('FormField');

            const radioGroup = findByName(field, 'RadioGroup');
            expect(radioGroup).toBeDefined();
            expect(radioGroup.props.name).toBe('issue_condition');
            expect(radioGroup.props.options).toHaveLength(1);
            expect(radioGroup.props.options[0].value).toBe('manual');
        });

        // @scenario section=basic_info
        // @effects coupon_form_conditional_fields_follow_choice
        it('직접발급 안내 박스: 아이콘 세로 중앙 정렬', () => {
            // 한 줄짜리 안내문이므로 컨테이너는 items-center 여야 아이콘이
            // 텍스트 정중앙에 온다. items-start + mt-0.5 (상단 정렬용 보정)
            // 패턴은 아이콘이 텍스트보다 위로 떠 보이는 회귀였다.
            const guide = findById(basicInfoPartial, 'direct_issue_guide');
            expect(guide).toBeDefined();
            expect(guide.props.className).toContain('items-center');
            expect(guide.props.className).not.toContain('items-start');

            const icon = findByName(guide, 'Icon');
            expect(icon).toBeDefined();
            // 상단 정렬용 margin-top 보정이 남아 있으면 안 된다.
            expect(icon.props.className).not.toContain('mt-0.5');
        });

        // @scenario section=basic_info
        // @effects coupon_form_multilingual_name_and_description
        it('쿠폰명 필드: MultilingualInput 사용', () => {
            const field = findById(basicInfoPartial, 'field_coupon_name');
            expect(field).toBeDefined();

            const multilingualInput = findByName(field, 'MultilingualInput');
            expect(multilingualInput).toBeDefined();
            expect(multilingualInput.props.name).toBe('name');
        });

        // @scenario section=basic_info
        // @effects coupon_form_multilingual_name_and_description
        it('쿠폰설명 필드: MultilingualInput 사용 (다국어 입력 컴포넌트로 통합)', () => {
            const field = findById(basicInfoPartial, 'field_coupon_description');
            expect(field).toBeDefined();

            const multilingualInput = findByName(field, 'MultilingualInput');
            expect(multilingualInput).toBeDefined();
            expect(multilingualInput.props.name).toBe('description');
        });
    });

    // ===== Partial 2: 혜택 설정 =====

    describe('Partial: 혜택 설정 (_partial_benefit_settings.json)', () => {
        // @scenario section=benefit_settings
        // @effects coupon_form_section_card_layout
        it('Div 컴포넌트로 래핑 (카드 스타일링)', () => {
            expect(benefitSettingsPartial.name).toBe('Div');
            expect(benefitSettingsPartial.type).toBe('basic');
            expect(benefitSettingsPartial.props.className).toContain('card');
        });

        // @scenario section=benefit_settings
        // @effects coupon_form_benefit_value_constraints
        it('혜택금액 필드: Input + Select (fixed/rate) 같은 줄', () => {
            const field = findById(benefitSettingsPartial, 'field_benefit_amount');
            expect(field).toBeDefined();
            expect(field.props.required).toBe(true);

            const input = findByName(field, 'Input');
            expect(input).toBeDefined();
            expect(input.props.name).toBe('discount_value');
            expect(input.props.type).toBe('number');

            const select = findByName(field, 'Select');
            expect(select).toBeDefined();
            expect(select.type).toBe('basic');
            expect(select.props.name).toBe('discount_type');
            expect(select.props.options).toHaveLength(2);
        });

        // @scenario section=benefit_settings
        // @effects coupon_form_benefit_value_constraints
        it('혜택금액 Input: min 은 정적 1 (A14 — 정액/정률 모두 1 이상), max 는 정률 100 동적 제약', () => {
            const field = findById(benefitSettingsPartial, 'field_benefit_amount');
            const input = findByName(field, 'Input');

            // A14: min 은 정액/정률 모두 1 로 고정 (서버 min:1 정합, 정액 0 미사용)
            expect(input.props.min).toBe(1);
            // max 는 정률일 때만 100 (정액은 상한 없음 → undefined)
            expect(input.props.max).toContain('rate');
            expect(input.props.max).toContain('100');
        });

        // @scenario section=benefit_settings
        // @effects coupon_form_benefit_value_constraints
        it('할인유형 Select 변경 시 discount_value 초기화 액션', () => {
            const field = findById(benefitSettingsPartial, 'field_benefit_amount');
            const select = findByName(field, 'Select');

            expect(select.actions).toBeDefined();
            expect(select.actions).toHaveLength(1);

            const action = asArray(select?.actions)[0];
            expect(action.type).toBe('change');
            expect(action.handler).toBe('setState');
            expect(action.params['form.discount_value']).toBeNull();
        });

        // @scenario section=benefit_settings
        // @effects coupon_form_conditional_fields_follow_choice
        it('최대할인금액 필드: discount_type이 rate일 때만 표시', () => {
            const field = findById(benefitSettingsPartial, 'field_discount_max_amount');
            expect(field).toBeDefined();
            expect(field.if).toContain("rate");

            const input = findByName(field, 'Input');
            expect(input.props.name).toBe('discount_max_amount');
        });

        // @scenario section=benefit_settings
        // @effects coupon_form_benefit_value_constraints
        it('최소주문금액 필드: Input 존재', () => {
            const field = findById(benefitSettingsPartial, 'field_min_order_amount');
            expect(field).toBeDefined();

            const input = findByName(field, 'Input');
            expect(input.props.name).toBe('min_order_amount');
        });

        // @scenario section=benefit_settings
        // @effects coupon_form_choice_options_match_enums
        it('유효기간 타입 RadioGroup: period / days_from_issue', () => {
            const field = findById(benefitSettingsPartial, 'field_valid_type');
            expect(field).toBeDefined();
            expect(field.props.required).toBe(true);

            const radioGroup = findByName(field, 'RadioGroup');
            expect(radioGroup.props.name).toBe('valid_type');
            expect(radioGroup.props.options).toHaveLength(2);

            const values = radioGroup.props.options.map((o: any) => o.value);
            expect(values).toEqual(['period', 'days_from_issue']);
        });

        // @scenario section=benefit_settings
        // @effects coupon_form_conditional_fields_follow_choice
        it('period 시 시작일/종료일 Input(datetime-local) 두 개 표시', () => {
            const div = findById(benefitSettingsPartial, 'valid_period_range');
            expect(div).toBeDefined();
            expect(div.if).toContain('period');

            // DateRangePicker 단일 컴포넌트 → 시작/종료 Input 두 개로 분리
            const inputs = findAllByName(div, 'Input');
            const inputNames = inputs.map((i: any) => i.props?.name);
            expect(inputNames).toContain('valid_from');
            expect(inputNames).toContain('valid_to');
        });

        // @scenario section=benefit_settings
        // @effects coupon_form_conditional_fields_follow_choice
        it('days_from_issue 시 일수 입력 표시', () => {
            const div = findById(benefitSettingsPartial, 'valid_period_days');
            expect(div).toBeDefined();
            expect(div.if).toContain('days_from_issue');

            const input = findByName(div, 'Input');
            expect(input.props.name).toBe('valid_days');
            expect(input.props.type).toBe('number');
        });
    });

    // ===== Partial 3: 발급 설정 =====

    describe('Partial: 발급 설정 (_partial_issue_settings.json)', () => {
        // @scenario section=issue_settings
        // @effects coupon_form_section_card_layout
        it('Div 컴포넌트로 래핑 (카드 스타일링)', () => {
            expect(issueSettingsPartial.name).toBe('Div');
            expect(issueSettingsPartial.type).toBe('basic');
            expect(issueSettingsPartial.props.className).toContain('card');
        });

        // @scenario section=issue_settings
        // @effects coupon_form_ui_only_radios_reset_payload
        it('발급수량: UI 전용 RadioGroup (value prop, name 없음)', () => {
            const field = findById(issueSettingsPartial, 'field_issue_quantity');
            expect(field).toBeDefined();

            const radioGroup = findByName(field, 'RadioGroup');
            expect(radioGroup.props.value).toContain('issue_quantity_type');
            // UI 전용이므로 name이 아닌 value로 바인딩
            expect(radioGroup.props).not.toHaveProperty('name');
        });

        // @scenario section=issue_settings
        // @effects coupon_form_ui_only_radios_reset_payload
        it('발급수량: unlimited 선택 시 total_quantity null로 초기화', () => {
            const field = findById(issueSettingsPartial, 'field_issue_quantity');
            const radioGroup = findByName(field, 'RadioGroup');

            const actions = radioGroup.actions;
            expect(actions).toBeDefined();
            expect(actions[0].handler).toBe('sequence');

            const sequenceActions = callbacksOf(actions[0], 'actions');
            expect(sequenceActions).toHaveLength(2);

            // UI 상태 변경
            expect(sequenceActions[0].params.issue_quantity_type).toContain('$event.target.value');
            // form 필드 초기화
            expect(sequenceActions[1].params['form.total_quantity']).toContain('unlimited');
        });

        // @scenario section=issue_settings
        // @effects coupon_form_conditional_fields_follow_choice
        it('수량 제한 시 Input 표시', () => {
            const div = findById(issueSettingsPartial, 'issue_quantity_input');
            expect(div).toBeDefined();
            expect(div.if).toContain("limited");

            const input = findByName(div, 'Input');
            expect(input.props.name).toBe('total_quantity');
            expect(input.props.type).toBe('number');
        });

        // @scenario section=issue_settings
        // @effects coupon_form_ui_only_radios_reset_payload
        it('발급기간: UI 전용 RadioGroup', () => {
            const field = findById(issueSettingsPartial, 'field_issue_period');
            expect(field).toBeDefined();

            const radioGroup = findByName(field, 'RadioGroup');
            expect(radioGroup.props.value).toContain('issue_period_type');
        });

        // @scenario section=issue_settings
        // @effects coupon_form_conditional_fields_follow_choice
        it('발급기간 제한 시 시작일/종료일 Input(datetime-local) 두 개 표시', () => {
            const div = findById(issueSettingsPartial, 'issue_period_range');
            expect(div).toBeDefined();
            expect(div.if).toContain("limited");

            const inputs = findAllByName(div, 'Input');
            const inputNames = inputs.map((i: any) => i.props?.name);
            expect(inputNames).toContain('issue_from');
            expect(inputNames).toContain('issue_to');

            // 시간까지 입력하므로 datetime-local 사용
            const fromInput = inputs.find((i: any) => i.props?.name === 'issue_from');
            expect(fromInput?.props?.type).toBe('datetime-local');
        });

        // @scenario section=issue_settings
        // @effects coupon_form_ui_only_radios_reset_payload
        it('재발급: UI 전용 RadioGroup', () => {
            const field = findById(issueSettingsPartial, 'field_reissue');
            expect(field).toBeDefined();

            const radioGroup = findByName(field, 'RadioGroup');
            expect(radioGroup.props.value).toContain('reissue_type');
        });

        // @scenario section=issue_settings
        // @effects coupon_form_conditional_fields_follow_choice
        it('재발급 제한 시 Input 표시', () => {
            const div = findById(issueSettingsPartial, 'reissue_limit_input');
            expect(div).toBeDefined();
            expect(div.if).toContain("limited");

            const input = findByName(div, 'Input');
            expect(input.props.name).toBe('per_user_limit');
        });
    });

    // ===== Partial 4: 사용 조건 설정 =====

    describe('Partial: 사용 조건 설정 (_partial_usage_conditions.json)', () => {
        // @scenario section=usage_conditions
        // @effects coupon_form_section_card_layout
        it('Div 컴포넌트로 래핑 (카드 스타일링)', () => {
            expect(usageConditionsPartial.name).toBe('Div');
            expect(usageConditionsPartial.type).toBe('basic');
            expect(usageConditionsPartial.props.className).toContain('card');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_choice_options_match_enums
        it('쿠폰 중복 사용 RadioGroup: true/false', () => {
            const field = findById(usageConditionsPartial, 'field_is_combinable');
            expect(field).toBeDefined();

            const radioGroup = findByName(field, 'RadioGroup');
            expect(radioGroup.props.name).toBe('is_combinable');
            expect(radioGroup.props.options).toHaveLength(2);
            expect(radioGroup.props.options[0].value).toBe('true');
            expect(radioGroup.props.options[1].value).toBe('false');
        });

        // @scenario section=usage_conditions
        // @effects radio_click_saves_boolean_state
        it('쿠폰 중복 사용 RadioGroup: 쓰기 경로 boolean 캐스팅 (공개 #97)', () => {
            const field = findById(usageConditionsPartial, 'field_is_combinable');
            const radioGroup = findByName(field, 'RadioGroup');

            // 자동바인딩 value 경로는 e.target.value 문자열을 그대로 저장하므로 차단
            expect(radioGroup.props.autoBinding).toBe(false);

            // 표시 바인딩: 저장된 boolean 을 문자열로 변환해 옵션 값과 비교
            expect(radioGroup.props.value).toContain('String(');
            expect(radioGroup.props.value).toContain('is_combinable');

            // 쓰기 경로: change 액션이 === 'true' 캐스팅으로 boolean 저장
            const changeAction = (radioGroup.actions ?? []).find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(changeAction).toBeDefined();
            expect(changeAction.params['form.is_combinable']).toBe(
                "{{$event.target.value === 'true'}}"
            );
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_choice_options_match_enums
        it('적용 대상 RadioGroup: all/products/categories', () => {
            const field = findById(usageConditionsPartial, 'field_target_scope');
            expect(field).toBeDefined();

            const radioGroup = findByName(field, 'RadioGroup');
            expect(radioGroup.props.name).toBe('target_scope');
            expect(radioGroup.props.options).toHaveLength(3);

            const values = radioGroup.props.options.map((o: any) => o.value);
            expect(values).toEqual(['all', 'products', 'categories']);
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_conditional_fields_follow_choice
        it('상품 선택 UI: target_scope === products일 때 표시', () => {
            const section = findById(usageConditionsPartial, 'target_products_section');
            expect(section).toBeDefined();
            expect(section.if).toContain("products");
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_conditional_fields_follow_choice
        it('상품 검색 패널: productSearchOpen 토글', () => {
            const panel = findById(usageConditionsPartial, 'product_search_panel');
            expect(panel).toBeDefined();
            expect(panel.if).toContain('productSearchOpen');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_contract
        it('상품 검색 Select: type이 basic', () => {
            const selects = findAllByName(usageConditionsPartial, 'Select');
            selects.forEach(select => {
                expect(select.type).toBe('basic');
            });
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_contract
        it('DataGrid: onSelectionChange 이벤트 사용 (type이 아닌 event)', () => {
            const dataGrids = findAllByName(usageConditionsPartial, 'DataGrid');
            dataGrids.forEach(dg => {
                if (dg.actions) {
                    asArray(dg?.actions).forEach((action: any) => {
                        if (action.event === 'onSelectionChange' || action.handler === 'setState') {
                            // selectionChange를 type으로 사용하면 안됨
                            expect(action).not.toHaveProperty('type', 'selectionChange');
                        }
                    });
                }
            });
        });

        // ===== 상품 검색 API 호출 및 무한스크롤 =====

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_contract
        it('상품 검색 버튼: apiCall with auth_mode + params.query 구조', () => {
            const panel = findById(usageConditionsPartial, 'product_search_panel');
            const buttons = findAllByName(panel, 'Button');
            const searchButton = buttons.find((b: any) =>
                JSON.stringify(asArray(b?.actions)).includes('apiCall')
            );
            expect(searchButton).toBeDefined();

            const apiAction = asArray(searchButton?.actions).find((a: any) => a.handler === 'apiCall');
            expect(apiAction.auth_mode).toBe('required');
            expect(apiAction.params.method).toBe('GET');
            expect(apiAction.params.query).toBeDefined();
            expect(apiAction.params.query.per_page).toBe(10);
            expect(apiAction.params.query.page).toBe(1);
            expect(apiAction.params.query.search_field).toContain('productSearchField');
            // A16②: 백엔드 계약(search_keyword) 과 일치 — 구버그 keyword 키 미사용
            expect(apiAction.params.query.keyword).toBeUndefined();
            expect(apiAction.params.query.search_keyword).toContain('productSearchKeyword');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_contract
        it('상품 검색 onSuccess: response 변수 사용 ($response 미사용)', () => {
            const panel = findById(usageConditionsPartial, 'product_search_panel');
            const panelJson = JSON.stringify(panel);
            // $response 미사용 확인
            expect(panelJson).not.toContain('$response');
            // response 사용 확인
            expect(panelJson).toContain('response?.data');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_contract
        it('상품 검색 onSuccess: pagination 메타 경로 사용', () => {
            const panel = findById(usageConditionsPartial, 'product_search_panel');
            const panelJson = JSON.stringify(panel);
            expect(panelJson).toContain('response?.data?.pagination?.current_page');
            expect(panelJson).toContain('response?.data?.pagination?.last_page');
            expect(panelJson).toContain('response?.data?.pagination?.total');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_infinite_scroll
        it('무한스크롤 컨테이너: overflow-y-auto + style.maxHeight', () => {
            const scrollContainer = findById(usageConditionsPartial, 'product_search_results_scroll');
            expect(scrollContainer).toBeDefined();
            expect(scrollContainer.name).toBe('Div');
            expect(scrollContainer.props.className).toContain('overflow-y-auto');
            expect(scrollContainer.props.style).toBeDefined();
            expect(scrollContainer.props.style.maxHeight).toBe('300px');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_infinite_scroll
        it('무한스크롤: type scroll 이벤트 + debounce 설정', () => {
            const scrollContainer = findById(usageConditionsPartial, 'product_search_results_scroll');
            const scrollAction = asArray(scrollContainer?.actions).find((a: any) => a.type === 'scroll');
            expect(scrollAction).toBeDefined();
            expect(scrollAction.debounce).toBe(200);
            expect(scrollAction.handler).toBe('sequence');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_infinite_scroll
        it('무한스크롤: switch 핸들러로 스크롤 위치 + hasMore + !isLoading 체크', () => {
            const scrollContainer = findById(usageConditionsPartial, 'product_search_results_scroll');
            const scrollAction = asArray(scrollContainer?.actions).find((a: any) => a.type === 'scroll');
            const switchAction = asArray(scrollAction?.actions)[0];
            expect(switchAction.handler).toBe('switch');

            const switchValue = switchAction.params.value;
            // 스크롤 위치 감지
            expect(switchValue).toContain('scrollHeight');
            expect(switchValue).toContain('scrollTop');
            expect(switchValue).toContain('clientHeight');
            // hasMore 체크
            expect(switchValue).toContain('productSearchPage');
            expect(switchValue).toContain('productSearchLastPage');
            // isLoadingMore 체크
            expect(switchValue).toContain('productSearchIsLoadingMore');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_infinite_scroll
        it('무한스크롤: true 케이스에 sequence(setState → scrollIntoView → apiCall)', () => {
            const scrollContainer = findById(usageConditionsPartial, 'product_search_results_scroll');
            const scrollAction = asArray(scrollContainer?.actions).find((a: any) => a.type === 'scroll');
            const switchAction = asArray(scrollAction?.actions)[0];
            const trueCase = switchAction.cases['true'];

            expect(trueCase.handler).toBe('sequence');
            expect(trueCase.actions).toHaveLength(3);

            // 1. setState: isLoadingMore = true
            expect(asArray(trueCase?.actions)[0].handler).toBe('setState');
            expect(asArray(trueCase?.actions)[0].params.productSearchIsLoadingMore).toBe(true);

            // 2. scrollIntoView — 로딩 인디케이터를 컨테이너 안에서 보이게 스크롤 (의도된 UX)
            expect(asArray(trueCase?.actions)[1].handler).toBe('scrollIntoView');
            expect(asArray(trueCase?.actions)[1].params.selector).toBe('#product_search_loading');
            expect(asArray(trueCase?.actions)[1].params.scrollContainer).toBe('#product_search_results_scroll');

            // 3. apiCall with onSuccess + onError
            expect(asArray(trueCase?.actions)[2].handler).toBe('apiCall');
            expect(asArray(trueCase?.actions)[2].onSuccess).toBeDefined();
            expect(asArray(trueCase?.actions)[2].onError).toBeDefined();
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_infinite_scroll
        it('무한스크롤 apiCall: 다음 페이지 요청 + 데이터 병합(spread)', () => {
            const scrollContainer = findById(usageConditionsPartial, 'product_search_results_scroll');
            const scrollAction = asArray(scrollContainer?.actions).find((a: any) => a.type === 'scroll');
            const trueCase = asArray(scrollAction?.actions)[0].cases['true'];
            const apiCallAction = asArray(trueCase?.actions)[2];

            // 다음 페이지 요청
            expect(apiCallAction.params.query.page).toContain('productSearchPage');
            expect(apiCallAction.params.query.page).toContain('+ 1');

            // onSuccess: 스프레드 연산자로 데이터 병합
            const successParams = asArray(apiCallAction?.onSuccess)[0].params;
            expect(successParams.productSearchResults).toContain('...');
            expect(successParams.productSearchIsLoadingMore).toBe(false);
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_infinite_scroll
        it('무한스크롤 onError: isLoadingMore false로 리셋', () => {
            const scrollContainer = findById(usageConditionsPartial, 'product_search_results_scroll');
            const scrollAction = asArray(scrollContainer?.actions).find((a: any) => a.type === 'scroll');
            const trueCase = asArray(scrollAction?.actions)[0].cases['true'];
            const apiCallAction = asArray(trueCase?.actions)[2];

            const errorParams = asArray(apiCallAction?.onError)[0].params;
            expect(errorParams.productSearchIsLoadingMore).toBe(false);
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_infinite_scroll
        it('LoadingSpinner: productSearchIsLoadingMore === true 시 표시', () => {
            const loading = findById(usageConditionsPartial, 'product_search_loading');
            expect(loading).toBeDefined();
            expect(loading.name).toBe('LoadingSpinner');
            expect(loading.if).toContain('productSearchIsLoadingMore === true');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_contract
        it('DataGrid 컬럼: ProductResource API 필드명 일치 (product_code, name_localized, selling_price_formatted)', () => {
            const scrollContainer = findById(usageConditionsPartial, 'product_search_results_scroll');
            const dataGrid = findByName(scrollContainer, 'DataGrid');
            expect(dataGrid).toBeDefined();

            const fields = dataGrid.props.columns.map((c: any) => c.field);
            expect(fields).toContain('product_code');
            expect(fields).toContain('name_localized');
            expect(fields).toContain('selling_price_formatted');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_conditional_fields_follow_choice
        it('카테고리 선택 UI: target_scope === categories일 때 표시', () => {
            const section = findById(usageConditionsPartial, 'target_categories_section');
            expect(section).toBeDefined();
            expect(section.if).toContain("categories");
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_category_picker_contract
        it('카테고리 선택 추가: localized_name + 브레드크럼 경로 사용', () => {
            const addBtn = findById(usageConditionsPartial, 'add_selected_categories');
            expect(addBtn).toBeDefined();

            const setStateAction = asArray(addBtn?.actions).find((a: any) => a.handler === 'setState');
            expect(setStateAction).toBeDefined();

            const expr = setStateAction.params['form.categories'];

            // localized_name 사용 (name 객체 대신)
            expect(expr).toContain('localized_name');

            // 부모 경로를 누적하는 재귀 패턴 (parentPath 인자)
            expect(expr).toContain('parentPath');

            // 구분자로 › 사용
            expect(expr).toContain('›');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_category_picker_contract
        it('CategoryTree 컴포넌트: onToggle, onSelectionChange 이벤트', () => {
            const tree = findByName(usageConditionsPartial, 'CategoryTree');
            expect(tree).toBeDefined();
            expect(tree.props.selectable).toBe(true);

            // 이벤트 필드 검증
            const toggleAction = asArray(tree?.actions).find((a: any) => a.event === 'onToggle');
            expect(toggleAction).toBeDefined();

            const selectionAction = asArray(tree?.actions).find((a: any) => a.event === 'onSelectionChange');
            expect(selectionAction).toBeDefined();
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_category_picker_contract
        it('CategoryTree: $args[0] 패턴 사용 ($event 미사용)', () => {
            const tree = findByName(usageConditionsPartial, 'CategoryTree');
            const toggleAction = asArray(tree?.actions).find((a: any) => a.event === 'onToggle');
            const selectionAction = asArray(tree?.actions).find((a: any) => a.event === 'onSelectionChange');

            // 커스텀 이벤트는 $args[0]으로 콜백 인자 접근
            expect(JSON.stringify(toggleAction.params)).toContain('$args[0]');
            expect(JSON.stringify(selectionAction.params)).toContain('$args[0]');

            // $event.xxx 패턴 미사용 확인
            expect(JSON.stringify(toggleAction.params)).not.toContain('$event.');
            expect(JSON.stringify(selectionAction.params)).not.toContain('$event.');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_category_picker_contract
        it('카테고리 데이터 필드: products_count 사용 (API 응답 필드명 일치)', () => {
            // 카테고리 추가 액션에서 API 응답 필드명과 일치하는 products_count 사용
            const partialJson = JSON.stringify(usageConditionsPartial);
            expect(partialJson).toContain('products_count');

            // DataGrid column field도 products_count 사용
            const categoriesDataGrid = findAllByName(usageConditionsPartial, 'DataGrid')
                .find((dg: any) => JSON.stringify(dg.props?.columns ?? []).includes('category_name'));
            if (categoriesDataGrid) {
                const countColumn = categoriesDataGrid.props.columns
                    .find((c: any) => c.header?.includes('product_count'));
                expect(countColumn?.field).toBe('products_count');
            }
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_product_search_contract
        it('DataGrid onSelectionChange: $args[0] 패턴 사용', () => {
            const dataGrids = findAllByName(usageConditionsPartial, 'DataGrid');
            dataGrids.forEach(dg => {
                if (dg.actions) {
                    const selectionActions = asArray(dg?.actions).filter(
                        (a: any) => a.event === 'onSelectionChange'
                    );
                    selectionActions.forEach((action: any) => {
                        const paramJson = JSON.stringify(action.params);
                        expect(paramJson).toContain('$args[0]');
                        expect(paramJson).not.toContain('$event.');
                    });
                }
            });
        });
    });

    // ===== i18n 키 검증 =====

    describe('i18n 키 검증', () => {
        // @scenario section=main_layout
        // @effects coupon_form_i18n_keys_namespaced
        it('메인 레이아웃: form.title_create/edit, button.cancel/save, messages 키 존재', () => {
            const keys = extractI18nKeys(mainLayout);
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.title_create');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.button.cancel');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.button.save');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.messages.created');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.messages.updated');
        });

        // @scenario section=basic_info
        // @effects coupon_form_i18n_keys_namespaced
        it('기본 정보 partial: section, field, option 키 존재', () => {
            const keys = extractI18nKeys(basicInfoPartial);
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.section.basic_info');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.field.target_type');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.option.target_type_product_amount');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.option.issue_method_direct');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.field.coupon_name');
        });

        // @scenario section=benefit_settings
        // @effects coupon_form_i18n_keys_namespaced
        it('혜택 설정 partial: benefit, discount, validity 키 존재', () => {
            const keys = extractI18nKeys(benefitSettingsPartial);
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.section.benefit_settings');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.field.benefit_amount');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.option.discount_type_fixed');
            // valid_type 옵션 라벨 키가 fixed_period 로 명시화됨
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.option.valid_type_fixed_period');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.hint.from_issue_date');
        });

        // @scenario section=issue_settings
        // @effects coupon_form_i18n_keys_namespaced
        it('발급 설정 partial: issue quantity, period, reissue 키 존재', () => {
            const keys = extractI18nKeys(issueSettingsPartial);
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.section.issue_settings');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.field.issue_quantity');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.option.unlimited');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.hint.unit_quantity');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.field.reissue');
        });

        // @scenario section=usage_conditions
        // @effects coupon_form_i18n_keys_namespaced
        it('사용 조건 partial: combinable, scope, search, table 키 존재', () => {
            const keys = extractI18nKeys(usageConditionsPartial);
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.section.usage_conditions');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.option.combinable_yes');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.option.target_scope_all');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.search.products');
            expect(keys).toContain('sirsoft-ecommerce.admin.promotion_coupon.form.table.product_code');
        });

        // @scenario section=main_layout
        // @effects coupon_form_i18n_keys_namespaced
        it('모든 i18n 키가 sirsoft-ecommerce 또는 common 네임스페이스', () => {
            const allKeys = [
                ...extractI18nKeys(mainLayout),
                ...extractI18nKeys(basicInfoPartial),
                ...extractI18nKeys(benefitSettingsPartial),
                ...extractI18nKeys(issueSettingsPartial),
                ...extractI18nKeys(usageConditionsPartial),
            ];

            allKeys.forEach(key => {
                const isModuleKey = key.startsWith('sirsoft-ecommerce.');
                const isCommonKey = key.startsWith('common.');
                // 코어 공용 오류 문구(다시 받기 실패 토스트 — engine-v1.82.0 오류 처리기 문맥) 허용
                const isCoreErrorKey = key.startsWith('core.errors.');
                expect(isModuleKey || isCommonKey || isCoreErrorKey).toBe(true);
            });
        });
    });

    // ===== 다크 모드 검증 =====

    describe('다크 모드 검증', () => {
        // @scenario section=usage_conditions
        // @effects coupon_form_buttons_dark_contrast
        it('사용 조건 partial: 버튼에 dark: variant 존재', () => {
            const json = JSON.stringify(usageConditionsPartial);
            // bg-blue 버튼 — 흰 글자 대비 4.5 이상: 다크도 600 단계(500 은 3.76)
            if (json.includes('bg-blue-600')) {
                expect(json).toContain('dark:bg-blue-600');
                expect(json).not.toContain('dark:bg-blue-500');
            }
            // bg-green 버튼 — 흰 글자 대비 4.5 이상: 라이트·다크 모두 700 단계(600 은 3.22, 500 은 2.22)
            if (json.includes('bg-green-700')) {
                expect(json).toContain('dark:bg-green-700');
                expect(json).not.toContain('dark:bg-green-500');
                expect(json).not.toMatch(/"[^"]*(?<![:\w-])bg-green-600[^"]*text-white/);
            }
            // bg-gray 버튼
            if (json.includes('bg-gray-600')) {
                expect(json).toContain('dark:bg-gray-500');
            }
        });
    });

    // ===== 백엔드 필드명 매칭 검증 =====

    describe('백엔드 필드명 매칭', () => {
        // @scenario section=main_layout
        // @effects coupon_form_fields_match_backend_request
        it('state.form의 필드명이 StoreCouponRequest와 일치', () => {
            const formKeys = Object.keys(initStateOf(mainLayout).form);
            // StoreCouponRequest에 정의된 필수 필드
            const expectedFields = [
                'target_type', 'issue_method', 'issue_condition',
                'name', 'description',
                'discount_type', 'discount_value', 'discount_max_amount',
                'min_order_amount',
                'valid_type', 'valid_from', 'valid_to', 'valid_days',
                'total_quantity', 'issue_from', 'issue_to', 'per_user_limit',
                'is_combinable', 'target_scope',
                'products', 'categories',
            ];

            expectedFields.forEach(field => {
                expect(formKeys).toContain(field);
            });
        });

        // @scenario section=main_layout
        // @effects coupon_form_fields_match_backend_request
        it('enum 값이 백엔드 Enum과 일치', () => {
            // target_type
            const targetTypeField = findById(basicInfoPartial, 'field_target_type');
            const targetTypeOptions = findByName(targetTypeField, 'RadioGroup').props.options;
            expect(targetTypeOptions.map((o: any) => o.value)).toEqual(['product_amount', 'order_amount', 'shipping_fee']);

            // discount_type
            const benefitField = findById(benefitSettingsPartial, 'field_benefit_amount');
            const discountSelect = findByName(benefitField, 'Select');
            expect(discountSelect.props.options.map((o: any) => o.value)).toEqual(['fixed', 'rate']);

            // valid_type
            const validTypeField = findById(benefitSettingsPartial, 'field_valid_type');
            const validTypeRadio = findByName(validTypeField, 'RadioGroup');
            expect(validTypeRadio.props.options.map((o: any) => o.value)).toEqual(['period', 'days_from_issue']);
        });
    });

    // ===== 카드 본문 필드 간격 회귀 =====

    describe('카드 본문 필드 세로 간격', () => {
        // [라벨, partial, 본문 row 항목 수].
        // 카드 본문은 구분선 없이 여백만(space-y-5, 20px) — row-stack
        // (divide-y + py-2) 은 구분선이 생기고 간격도 좁아 회귀였다.
        // 기본 정보는 7개: 적용대상/발급방법/발급조건(자동)/발급조건(수동)/직접발급안내/쿠폰명/쿠폰설명
        // (직접발급 안내 Div 는 issue_method===direct 일 때만 노출되는 조건부 직계 자식)
        const cases: Array<[string, any, number]> = [
            ['기본 정보', basicInfoPartial, 7],
            ['혜택 설정', benefitSettingsPartial, 4],
            ['발급 설정', issueSettingsPartial, 3],
            ['사용 조건', usageConditionsPartial, 4],
        ];

        // @scenario section=basic_info
        // @effects coupon_form_section_card_layout
        it.each(cases)(
            '%s: 본문 항목을 직접 감싸는 컨테이너가 space-y-5 (구분선 없는 여백)',
            (_label, partial, expectedRowCount) => {
                const container = findFieldContainer(partial);
                expect(container).not.toBeNull();
                // 여백 전용 클래스여야 함 — 구분선(divide) 들어가는 row-stack 금지
                expect(container.props?.className).toContain('space-y-5');
                expect(container.props?.className).not.toContain('row-stack');
                // 본문 항목들이 손자가 아닌 직계 자식이어야 한다.
                // 빈 Div 1개로 감싸는 회귀가 있으면 직계 자식 수가 1로 떨어진다.
                expect(container.children.length).toBe(expectedRowCount);
                expect(container.children.length).toBeGreaterThan(1);
            },
        );
    });
});
