/**
 * 배송설정 탭 (환경설정 내) 레이아웃 구조 테스트
 *
 * 검증 대상:
 * - admin_ecommerce_settings.json 에 shipping 탭 존재
 * - _tab_shipping.json (배송설정 기본 + 배송가능국가 + 도서산간)
 * - _shipping_country_table.json (국가 테이블)
 */
import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import settingsLayout from '../../../layouts/admin/admin_ecommerce_settings.json';
import tabShipping from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_shipping.json';
import countryTable from '../../../layouts/admin/partials/admin_ecommerce_settings/_shipping_country_table.json';
import countryCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_shipping_country_cards.json';
import disableIntlModal from '../../../layouts/admin/partials/admin_ecommerce_settings/_disable_international_shipping_modal.json';

// ── 헬퍼 함수 ──────────────────────────────────────────
function flattenAll(node: any): any[] {
    const result: any[] = [];
    if (!node) return result;
    if (Array.isArray(node)) {
        node.forEach((n) => result.push(...flattenAll(n)));
        return result;
    }
    result.push(node);
    if (node.children) result.push(...flattenAll(node.children));
    if (node.slots) {
        Object.values(node.slots).forEach((s: any) => result.push(...flattenAll(s)));
    }
    return result;
}

function findById(nodes: any[], id: string): any {
    return nodes.find((n: any) => n.id === id);
}

function findAllByType(nodes: any[], name: string): any[] {
    return nodes.filter((n: any) => n.name === name);
}

function collectI18nKeys(obj: any): string[] {
    const keys: string[] = [];
    const str = JSON.stringify(obj);
    const matches = str.match(/\$t:[a-zA-Z0-9_.\-]+/g);
    if (matches) keys.push(...matches);
    return [...new Set(keys)];
}

// ══════════════════════════════════════════════════════════
// 1. 메인 설정 레이아웃에서 shipping 탭 정의
// ══════════════════════════════════════════════════════════
describe('환경설정 메인 레이아웃 - shipping 탭', () => {
    const allNodes = flattenAll(settingsLayout);

    it('TabNavigation에 shipping 탭이 정의되어 있다', () => {
        const tabNav = allNodes.find((n: any) => n.name === 'TabNavigation');
        expect(tabNav).toBeDefined();
        const tabs = tabNav.props?.tabs ?? [];
        const shippingTab = tabs.find((t: any) => t.id === 'shipping');
        expect(shippingTab).toBeDefined();
        expect(shippingTab.label).toContain('$t:sirsoft-ecommerce.admin.settings.tabs.shipping');
    });

    it('shipping 탭 순서가 order_settings 다음이다', () => {
        const tabNav = allNodes.find((n: any) => n.name === 'TabNavigation');
        const tabs = tabNav.props?.tabs ?? [];
        const tabIds = tabs.map((t: any) => t.id);
        const orderIdx = tabIds.indexOf('order_settings');
        const shippingIdx = tabIds.indexOf('shipping');
        expect(shippingIdx).toBeGreaterThan(orderIdx);
    });

    it('_tab_shipping.json partial이 포함되어 있다', () => {
        const partialRef = allNodes.find(
            (n: any) => n.partial?.includes('_tab_shipping.json')
        );
        expect(partialRef).toBeDefined();
    });
});

// ══════════════════════════════════════════════════════════
// 2. _tab_shipping.json 구조
// ══════════════════════════════════════════════════════════
describe('배송설정 탭 콘텐츠 (_tab_shipping.json)', () => {
    const allNodes = flattenAll(tabShipping);

    describe('메타 정보', () => {
        it('is_partial이 true', () => {
            expect(tabShipping.meta?.is_partial).toBe(true);
        });

        it('id가 tab_content_shipping', () => {
            expect(tabShipping.id).toBe('tab_content_shipping');
        });

        it('if 조건에 shipping 탭 매칭이 있다', () => {
            expect(tabShipping.if).toContain("'shipping'");
        });
    });

    describe('기본 배송설정 카드', () => {
        it('shipping_basic_card가 존재한다', () => {
            const card = findById(allNodes, 'shipping_basic_card');
            expect(card).toBeDefined();
        });

        it('기본 국가 Select가 있다', () => {
            const defaultField = findById(allNodes, 'default_country_field');
            expect(defaultField).toBeDefined();
            const selects = flattenAll(defaultField).filter((n: any) => n.name === 'Select');
            expect(selects.length).toBeGreaterThan(0);
            // Select의 value가 default_country 바인딩
            const select = selects[0];
            expect(select.props?.value).toContain('default_country');
        });

        it('기본 국가 Select의 options가 available_countries에서 동적 생성된다', () => {
            const defaultField = findById(allNodes, 'default_country_field');
            const select = flattenAll(defaultField).find((n: any) => n.name === 'Select');
            expect(select.props?.options).toContain('available_countries');
            expect(select.props?.options).toContain('filter');
            expect(select.props?.options).toContain('is_active');
        });

        it('해외배송 Toggle이 수동 바인딩(basic + checked + actions)으로 구성되어 있다', () => {
            const intlField = findById(allNodes, 'international_shipping_field');
            expect(intlField).toBeDefined();
            const toggles = flattenAll(intlField).filter((n: any) => n.name === 'Toggle');
            expect(toggles.length).toBeGreaterThan(0);
            const toggle = toggles[0];
            // 수동 바인딩: type=basic, checked prop, actions 있음
            expect(toggle.type).toBe('basic');
            expect(toggle.props?.checked).toContain('international_shipping_enabled');
            expect(toggle.actions).toBeDefined();
            expect(toggle.actions.length).toBeGreaterThanOrEqual(2);
        });

        it('해외배송 Toggle ON 시 setState로 직접 활성화한다', () => {
            const intlField = findById(allNodes, 'international_shipping_field');
            const toggle = flattenAll(intlField).find((n: any) => n.name === 'Toggle');
            const onAction = toggle.actions.find(
                (a: any) => a.type === 'change' && a.if?.includes('$event.target.checked') && !a.if?.includes('!')
            );
            expect(onAction).toBeDefined();
            expect(onAction.handler).toBe('setState');
            expect(onAction.params?.['form.shipping.international_shipping_enabled']).toBe(true);
            expect(onAction.params?.hasChanges).toBe(true);
        });

        it('해외배송 Toggle OFF 시 conditions 핸들러로 분기한다', () => {
            const intlField = findById(allNodes, 'international_shipping_field');
            const toggle = flattenAll(intlField).find((n: any) => n.name === 'Toggle');
            const offAction = toggle.actions.find(
                (a: any) => a.type === 'change' && a.if?.includes('!$event.target.checked')
            );
            expect(offAction).toBeDefined();
            expect(offAction.handler).toBe('conditions');
            expect(offAction.conditions).toBeDefined();
            expect(offAction.conditions.length).toBe(2);
            // 첫 번째 조건: 외국 활성 국가 존재 → openModal
            expect(offAction.conditions[0].if).toContain('is_active');
            expect(offAction.conditions[0].then.handler).toBe('openModal');
            expect(offAction.conditions[0].then.params.id).toBe('disable_international_shipping_modal');
            // 두 번째 조건: 기본(fallback) → setState
            expect(offAction.conditions[1].then.handler).toBe('setState');
            expect(offAction.conditions[1].then.params?.['form.shipping.international_shipping_enabled']).toBe(false);
        });
    });

    describe('배송가능국가 카드', () => {
        it('available_countries_card가 존재한다', () => {
            const card = findById(allNodes, 'available_countries_card');
            expect(card).toBeDefined();
        });

        it('국가 추가 버튼이 있고 해외배송 활성 + 추가 폼 미열림 시만 표시된다', () => {
            const addBtn = findById(allNodes, 'add_country_button');
            expect(addBtn).toBeDefined();
            expect(addBtn.if).toContain('!_local.isAddingCountry');
            expect(addBtn.if).toContain('international_shipping_enabled');
        });

        it('국가 추가 버튼의 onClick이 isAddingCountry를 true로 설정한다', () => {
            const addBtn = findById(allNodes, 'add_country_button');
            const clickAction = addBtn.actions?.find(
                (a: any) => a.type === 'click' && a.handler === 'setState'
            );
            expect(clickAction).toBeDefined();
            expect(clickAction.params?.isAddingCountry).toBe(true);
        });

        it('_shipping_country_table.json partial이 포함되어 있다', () => {
            const card = findById(allNodes, 'available_countries_card');
            const cardNodes = flattenAll(card);
            const partialRef = cardNodes.find(
                (n: any) => n.partial?.includes('_shipping_country_table.json')
            );
            expect(partialRef).toBeDefined();
        });

        it('국가 추가 폼이 isAddingCountry + 해외배송 활성 시만 표시된다', () => {
            const addForm = findById(allNodes, 'add_country_form');
            expect(addForm).toBeDefined();
            expect(addForm.if).toContain('_local.isAddingCountry');
            expect(addForm.if).toContain('international_shipping_enabled');
        });

        it('국가 추가 폼에 코드/한국어명/영문명 Input이 있다', () => {
            const addForm = findById(allNodes, 'add_country_form');
            const formNodes = flattenAll(addForm);
            const inputs = formNodes.filter((n: any) => n.name === 'Input');
            // code, name.ko, name.en => 3개
            expect(inputs.length).toBeGreaterThanOrEqual(3);
            // code input
            const codeInput = inputs.find((i: any) => i.props?.value?.includes('newCountry?.code'));
            expect(codeInput).toBeDefined();
            expect(codeInput.props?.maxLength).toBe(10);
            // name.ko input
            const koInput = inputs.find((i: any) => i.props?.value?.includes('newCountry?.name?.ko'));
            expect(koInput).toBeDefined();
            // name.en input
            const enInput = inputs.find((i: any) => i.props?.value?.includes('newCountry?.name?.en'));
            expect(enInput).toBeDefined();
        });

        it('추가 버튼은 code와 name.ko가 없으면 disabled', () => {
            const addForm = findById(allNodes, 'add_country_form');
            const formNodes = flattenAll(addForm);
            const buttons = formNodes.filter((n: any) => n.name === 'Button');
            // 추가 버튼 (disabled prop 가진 것)
            const addButton = buttons.find((b: any) => b.props?.disabled);
            expect(addButton).toBeDefined();
            expect(addButton.props.disabled).toContain('newCountry?.code');
            expect(addButton.props.disabled).toContain('newCountry?.name?.ko');
        });

        it('추가 버튼 클릭 시 available_countries에 새 국가가 push된다', () => {
            const addForm = findById(allNodes, 'add_country_form');
            const formNodes = flattenAll(addForm);
            const buttons = formNodes.filter((n: any) => n.name === 'Button');
            const addButton = buttons.find((b: any) => b.props?.disabled);
            const clickAction = addButton?.actions?.find(
                (a: any) => a.type === 'click'
            );
            expect(clickAction).toBeDefined();
            // sequence 내부에 setState가 있어야 함
            if (clickAction.handler === 'sequence') {
                const setStateAction = clickAction.actions?.find(
                    (a: any) => a.handler === 'setState'
                );
                expect(setStateAction).toBeDefined();
                expect(setStateAction.params?.['form.shipping.available_countries']).toContain('_local.newCountry');
                expect(setStateAction.params?.isAddingCountry).toBe(false);
            } else {
                expect(clickAction.handler).toBe('setState');
            }
        });

        it('취소 버튼이 있고 isAddingCountry를 false로 설정한다', () => {
            const addForm = findById(allNodes, 'add_country_form');
            const formNodes = flattenAll(addForm);
            const buttons = formNodes.filter((n: any) => n.name === 'Button');
            // disabled prop이 없는 button 중 cancel 텍스트를 가진 것
            const cancelBtn = buttons.find(
                (b: any) =>
                    !b.props?.disabled &&
                    flattenAll(b).some((n: any) => n.text?.includes('$t:common.cancel'))
            );
            expect(cancelBtn).toBeDefined();
            const action = cancelBtn.actions?.find(
                (a: any) => a.handler === 'setState'
            );
            expect(action?.params?.isAddingCountry).toBe(false);
        });
    });

    describe('도서산간 전역 설정 카드', () => {
        it('remote_area_card가 존재한다', () => {
            const card = findById(allNodes, 'remote_area_card');
            expect(card).toBeDefined();
        });

        it('도서산간 활성화 Toggle이 자동 바인딩으로 구성되어 있다', () => {
            const card = findById(allNodes, 'remote_area_card');
            const cardNodes = flattenAll(card);
            const toggles = cardNodes.filter((n: any) => n.name === 'Toggle');
            expect(toggles.length).toBeGreaterThan(0);
            const enableToggle = toggles.find((t: any) =>
                t.props?.name === 'shipping.remote_area_enabled'
            );
            expect(enableToggle).toBeDefined();
            // 자동 바인딩: type=composite, name prop, value prop, actions 없음
            expect(enableToggle.type).toBe('composite');
            expect(enableToggle.props?.value).toContain('remote_area_enabled');
            expect(enableToggle.actions).toBeUndefined();
        });

        it('도서산간 활성 시 추가배송비 Input이 조건부 표시된다', () => {
            const card = findById(allNodes, 'remote_area_card');
            const cardNodes = flattenAll(card);
            // if 조건에 remote_area_enabled가 있는 Div
            const conditionalDiv = cardNodes.find(
                (n: any) => n.if?.includes('remote_area_enabled') && n.name === 'Div'
            );
            expect(conditionalDiv).toBeDefined();
            // 내부에 number Input 2개 (산간/도서)
            const innerNodes = flattenAll(conditionalDiv);
            const numInputs = innerNodes.filter(
                (n: any) => n.name === 'Input' && n.props?.type === 'number'
            );
            expect(numInputs.length).toBe(2);
        });

        it('산간 추가배송비 Input이 remote_area_extra_fee를 바인딩한다', () => {
            const card = findById(allNodes, 'remote_area_card');
            const cardNodes = flattenAll(card);
            const remoteInput = cardNodes.find(
                (n: any) =>
                    n.name === 'Input' &&
                    n.props?.value?.includes('remote_area_extra_fee')
            );
            expect(remoteInput).toBeDefined();
            expect(remoteInput.props.min).toBe(0);
        });

        it('도서 추가배송비 Input이 island_extra_fee를 바인딩한다', () => {
            const card = findById(allNodes, 'remote_area_card');
            const cardNodes = flattenAll(card);
            const islandInput = cardNodes.find(
                (n: any) =>
                    n.name === 'Input' &&
                    n.props?.value?.includes('island_extra_fee')
            );
            expect(islandInput).toBeDefined();
            expect(islandInput.props.min).toBe(0);
        });
    });
});

// ══════════════════════════════════════════════════════════
// 3. _shipping_country_table.json 구조
// ══════════════════════════════════════════════════════════
describe('배송가능국가 테이블 (_shipping_country_table.json)', () => {
    const allNodes = flattenAll(countryTable);

    describe('메타 정보', () => {
        it('is_partial이 true', () => {
            expect(countryTable.meta?.is_partial).toBe(true);
        });

        it('id가 shipping_country_table_container', () => {
            expect(countryTable.id).toBe('shipping_country_table_container');
        });
    });

    describe('빈 상태 표시', () => {
        it('국가가 없을 때 빈 상태가 표시된다', () => {
            const emptyDiv = allNodes.find(
                (n: any) =>
                    n.if?.includes('available_countries') &&
                    n.if?.includes('length === 0')
            );
            expect(emptyDiv).toBeDefined();
            // 아이콘과 텍스트
            const innerNodes = flattenAll(emptyDiv);
            const icon = innerNodes.find((n: any) => n.name === 'Icon' && n.props?.name === 'globe');
            expect(icon).toBeDefined();
            const text = innerNodes.find((n: any) =>
                n.text?.includes('$t:sirsoft-ecommerce.admin.settings.shipping.countries.empty')
            );
            expect(text).toBeDefined();
        });
    });

    describe('테이블 구조', () => {
        it('country_list 래퍼가 국가가 있을 때만 표시된다', () => {
            const wrapper = findById(allNodes, 'country_list');
            expect(wrapper).toBeDefined();
            expect(wrapper.name).toBe('Div');
            expect(wrapper.if).toContain('length > 0');
        });

        it('Table이 country_list 래퍼 내부에 있다', () => {
            const wrapper = findById(allNodes, 'country_list');
            const wrapperNodes = flattenAll(wrapper);
            const table = wrapperNodes.find((n: any) => n.id === 'country_table');
            expect(table).toBeDefined();
            expect(table.name).toBe('Table');
        });

        it('Thead에 4개 컬럼 헤더가 있다', () => {
            const theadNodes = allNodes.filter((n: any) => n.name === 'Th');
            // country_code, country_name, is_active, actions(empty)
            expect(theadNodes.length).toBe(4);
        });

        it('Tbody에 iteration이 있다', () => {
            const iterNode = findById(allNodes, 'country_row');
            expect(iterNode).toBeDefined();
            expect(iterNode.iteration).toBeDefined();
            expect(iterNode.iteration.source).toContain('available_countries');
            expect(iterNode.iteration.item_var).toBe('country');
            expect(iterNode.iteration.index_var).toBe('countryIndex');
        });

        it('국가코드가 국기 아이콘과 함께 표시된다', () => {
            const spans = allNodes.filter((n: any) => n.name === 'Span');
            // 국기 아이콘 Span (fi fi-xx 클래스)
            const flagSpan = spans.find((s: any) => s.props?.className?.includes('fi fi-'));
            expect(flagSpan).toBeDefined();
            expect(flagSpan.props.className).toContain('fis');
            expect(flagSpan.props.className).toContain('rounded');
            // 국가코드 텍스트 Span
            const codeSpan = spans.find((s: any) => s.text === '{{country.code}}');
            expect(codeSpan).toBeDefined();
        });

        it('국가명이 $localized로 표시된다', () => {
            const spans = allNodes.filter((n: any) => n.name === 'Span');
            const nameSpan = spans.find((s: any) => s.text?.includes('$localized(country.name)'));
            expect(nameSpan).toBeDefined();
        });

        it('활성여부 Toggle이 있다', () => {
            const toggles = allNodes.filter((n: any) => n.name === 'Toggle');
            const activeToggle = toggles.find((t: any) =>
                t.props?.checked?.includes('country.is_active')
            );
            expect(activeToggle).toBeDefined();
            expect(activeToggle.props.size).toBe('sm');
        });

        it('활성여부 Toggle이 해외배송 OFF + 비기본국가 시 disabled', () => {
            const toggles = allNodes.filter((n: any) => n.name === 'Toggle');
            const activeToggle = toggles.find((t: any) =>
                t.props?.checked?.includes('country.is_active')
            );
            expect(activeToggle.props.disabled).toBeDefined();
            expect(activeToggle.props.disabled).toContain('international_shipping_enabled');
            expect(activeToggle.props.disabled).toContain('default_country');
        });

        it('활성여부 Toggle의 setState가 배열 map으로 업데이트한다', () => {
            const toggles = allNodes.filter((n: any) => n.name === 'Toggle');
            const activeToggle = toggles.find((t: any) =>
                t.props?.checked?.includes('country.is_active')
            );
            const action = activeToggle?.actions?.find(
                (a: any) => a.handler === 'setState'
            );
            expect(action).toBeDefined();
            // 전체 배열 교체 패턴 (map): 키에 템플릿 표현식 없음
            const value = action.params?.['form.shipping.available_countries'];
            expect(value).toBeDefined();
            expect(value).toContain('map');
            expect(value).toContain('is_active');
        });

        it('삭제 버튼이 기본 국가가 아닌 경우에만 표시된다', () => {
            const buttons = allNodes.filter((n: any) => n.name === 'Button');
            const deleteBtn = buttons.find(
                (b: any) =>
                    b.if?.includes('country.code') &&
                    b.if?.includes('default_country')
            );
            expect(deleteBtn).toBeDefined();
            // !== 조건 (기본국가가 아닌 경우만 표시)
            expect(deleteBtn.if).toContain('!==');
        });

        it('삭제 버튼이 해외배송 OFF 시 disabled', () => {
            const buttons = allNodes.filter((n: any) => n.name === 'Button');
            const deleteBtn = buttons.find(
                (b: any) =>
                    b.if?.includes('country.code') &&
                    b.if?.includes('default_country')
            );
            expect(deleteBtn.props.disabled).toBeDefined();
            expect(deleteBtn.props.disabled).toContain('international_shipping_enabled');
        });

        it('삭제 버튼 클릭 시 filter로 해당 국가를 제거한다', () => {
            const buttons = allNodes.filter((n: any) => n.name === 'Button');
            const deleteBtn = buttons.find(
                (b: any) =>
                    b.if?.includes('country.code') &&
                    b.if?.includes('default_country')
            );
            const action = deleteBtn?.actions?.find(
                (a: any) => a.handler === 'setState'
            );
            expect(action).toBeDefined();
            const countryKey = Object.keys(action.params || {}).find((k) =>
                k.includes('available_countries')
            );
            expect(countryKey).toBeDefined();
            expect(action.params?.[countryKey!]).toContain('filter');
        });

        it('기본 국가에는 기본 배지가 표시된다', () => {
            const spans = allNodes.filter((n: any) => n.name === 'Span');
            const defaultBadge = spans.find(
                (s: any) =>
                    s.if?.includes('country.code') &&
                    s.if?.includes('default_country') &&
                    s.if?.includes('===')
            );
            expect(defaultBadge).toBeDefined();
            expect(defaultBadge.text).toContain('$t:sirsoft-ecommerce.admin.settings.shipping.countries.default_badge');
        });
    });
});

// ══════════════════════════════════════════════════════════
// 4. 바인딩 패턴 일관성 검증 (저장 버튼 활성화)
// ══════════════════════════════════════════════════════════
describe('바인딩 패턴 일관성 검증', () => {
    /**
     * 트러블슈팅 사례: trackChanges가 작동하지 않음 (저장 버튼 비활성화)
     *
     * 해결 패턴:
     * - 단순 필드 (Toggle, Input): 자동 바인딩 (name prop) → trackChanges가 자동 감지
     * - 동적 옵션/배열 조작 (Select, 국가 추가/삭제): 수동 바인딩 + hasChanges: true
     *
     * order_settings 탭과 동일한 패턴 적용
     */

    describe('자동 바인딩 필드 (name prop, actions 없음)', () => {
        const allNodes = flattenAll(tabShipping);

        it('해외배송 Toggle이 수동 바인딩이며 ON/OFF 모두 hasChanges를 설정한다', () => {
            const intlField = findById(allNodes, 'international_shipping_field');
            const toggle = flattenAll(intlField).find((n: any) => n.name === 'Toggle');
            expect(toggle.type).toBe('basic');
            expect(toggle.props?.checked).toContain('international_shipping_enabled');
            expect(toggle.actions).toBeDefined();
            // ON action에 hasChanges: true
            const onAction = toggle.actions.find(
                (a: any) => a.if?.includes('$event.target.checked') && !a.if?.includes('!')
            );
            expect(onAction.params?.hasChanges).toBe(true);
            // OFF action은 conditions 핸들러 (setState 또는 openModal)
            const offAction = toggle.actions.find(
                (a: any) => a.if?.includes('!$event.target.checked')
            );
            expect(offAction.handler).toBe('conditions');
        });

        it('도서산간 활성화 Toggle이 name prop 자동 바인딩이다', () => {
            const card = findById(allNodes, 'remote_area_card');
            const cardNodes = flattenAll(card);
            const enableToggle = cardNodes.find(
                (n: any) => n.name === 'Toggle' && n.props?.name === 'shipping.remote_area_enabled'
            );
            expect(enableToggle).toBeDefined();
            expect(enableToggle.type).toBe('composite');
            expect(enableToggle.actions).toBeUndefined();
        });

        it('산간 추가배송비 Input이 name prop 자동 바인딩이다', () => {
            const card = findById(allNodes, 'remote_area_card');
            const cardNodes = flattenAll(card);
            const remoteInput = cardNodes.find(
                (n: any) =>
                    n.name === 'Input' &&
                    n.props?.name === 'shipping.remote_area_extra_fee'
            );
            expect(remoteInput).toBeDefined();
            expect(remoteInput.actions).toBeUndefined();
        });

        it('도서 추가배송비 Input이 name prop 자동 바인딩이다', () => {
            const card = findById(allNodes, 'remote_area_card');
            const cardNodes = flattenAll(card);
            const islandInput = cardNodes.find(
                (n: any) =>
                    n.name === 'Input' &&
                    n.props?.name === 'shipping.island_extra_fee'
            );
            expect(islandInput).toBeDefined();
            expect(islandInput.actions).toBeUndefined();
        });
    });

    describe('수동 바인딩 필드 (hasChanges: true 필수)', () => {
        const tabNodes = flattenAll(tabShipping);
        const tableNodes = flattenAll(countryTable);

        it('기본 국가 Select의 setState에 hasChanges: true가 포함된다', () => {
            const defaultField = findById(tabNodes, 'default_country_field');
            const select = flattenAll(defaultField).find((n: any) => n.name === 'Select');
            const action = select?.actions?.find((a: any) => a.handler === 'setState');
            expect(action).toBeDefined();
            expect(action.params?.hasChanges).toBe(true);
        });

        it('국가 추가 확인 버튼의 setState에 hasChanges: true가 포함된다', () => {
            const addForm = findById(tabNodes, 'add_country_form');
            const formNodes = flattenAll(addForm);
            const buttons = formNodes.filter((n: any) => n.name === 'Button');
            const addButton = buttons.find((b: any) => b.props?.disabled);
            const clickAction = addButton?.actions?.find((a: any) => a.type === 'click');
            expect(clickAction).toBeDefined();
            // sequence 내부의 setState 확인
            if (clickAction.handler === 'sequence') {
                const setStateAction = clickAction.actions?.find(
                    (a: any) => a.handler === 'setState'
                );
                expect(setStateAction.params?.hasChanges).toBe(true);
            } else {
                expect(clickAction.params?.hasChanges).toBe(true);
            }
        });

        it('국가 활성여부 Toggle의 setState에 hasChanges: true가 포함된다', () => {
            const toggles = tableNodes.filter((n: any) => n.name === 'Toggle');
            const activeToggle = toggles.find((t: any) =>
                t.props?.checked?.includes('country.is_active')
            );
            const action = activeToggle?.actions?.find((a: any) => a.handler === 'setState');
            expect(action).toBeDefined();
            expect(action.params?.hasChanges).toBe(true);
            // 배열 map 패턴 사용 (키에 템플릿 표현식 없음)
            expect(action.params?.['form.shipping.available_countries']).toBeDefined();
        });

        it('국가 삭제 버튼의 setState에 hasChanges: true가 포함된다', () => {
            const buttons = tableNodes.filter((n: any) => n.name === 'Button');
            const deleteBtn = buttons.find(
                (b: any) =>
                    b.if?.includes('country.code') &&
                    b.if?.includes('default_country')
            );
            const action = deleteBtn?.actions?.find((a: any) => a.handler === 'setState');
            expect(action).toBeDefined();
            expect(action.params?.hasChanges).toBe(true);
        });
    });
});

// ══════════════════════════════════════════════════════════
// 5. 해외배송 비활성화 확인 모달 구조
// ══════════════════════════════════════════════════════════
describe('해외배송 비활성화 확인 모달 (_disable_international_shipping_modal.json)', () => {
    const allNodes = flattenAll(disableIntlModal);

    it('is_partial이 true', () => {
        expect(disableIntlModal.meta?.is_partial).toBe(true);
    });

    it('id가 disable_international_shipping_modal', () => {
        expect(disableIntlModal.id).toBe('disable_international_shipping_modal');
    });

    it('Modal 컴포넌트로 구성된다', () => {
        expect(disableIntlModal.name).toBe('Modal');
        expect(disableIntlModal.type).toBe('composite');
    });

    it('경고 메시지가 다국어로 표시된다', () => {
        const keys = collectI18nKeys(disableIntlModal);
        expect(keys).toContain('$t:sirsoft-ecommerce.admin.settings.shipping.modal.disable_international.message');
    });

    it('취소 버튼이 closeModal 핸들러를 호출한다', () => {
        const buttons = allNodes.filter((n: any) => n.name === 'Button');
        const cancelBtn = buttons.find((b: any) => {
            const btnNodes = flattenAll(b);
            return btnNodes.some((n: any) =>
                n.text?.includes('$t:sirsoft-ecommerce.admin.settings.shipping.modal.disable_international.cancel')
            );
        });
        expect(cancelBtn).toBeDefined();
        const action = cancelBtn.actions?.find((a: any) => a.handler === 'closeModal');
        expect(action).toBeDefined();
        expect(action.params?.id).toBe('disable_international_shipping_modal');
    });

    it('확인 버튼이 sequence(setState + closeModal) 핸들러를 호출한다', () => {
        const buttons = allNodes.filter((n: any) => n.name === 'Button');
        const confirmBtn = buttons.find((b: any) => {
            const btnNodes = flattenAll(b);
            return btnNodes.some((n: any) =>
                n.text?.includes('$t:sirsoft-ecommerce.admin.settings.shipping.modal.disable_international.confirm')
            );
        });
        expect(confirmBtn).toBeDefined();
        const action = confirmBtn.actions?.find((a: any) => a.handler === 'sequence');
        expect(action).toBeDefined();
        // setState: international_shipping_enabled = false
        const setStateAction = action.actions?.find((a: any) => a.handler === 'setState');
        expect(setStateAction).toBeDefined();
        expect(setStateAction.params?.['form.shipping.international_shipping_enabled']).toBe(false);
        expect(setStateAction.params?.hasChanges).toBe(true);
        // setState: available_countries를 map으로 비기본국가 비활성화
        expect(setStateAction.params?.['form.shipping.available_countries']).toContain('map');
        expect(setStateAction.params?.['form.shipping.available_countries']).toContain('is_active: false');
        // closeModal
        const closeAction = action.actions?.find((a: any) => a.handler === 'closeModal');
        expect(closeAction).toBeDefined();
        expect(closeAction.params?.id).toBe('disable_international_shipping_modal');
    });

    it('모달이 루트 레이아웃의 modals에 등록되어 있다', () => {
        const modals = (settingsLayout as any).modals ?? [];
        const disableModal = modals.find((m: any) =>
            m.partial?.includes('_disable_international_shipping_modal.json')
        );
        expect(disableModal).toBeDefined();
    });
});

// ══════════════════════════════════════════════════════════
// 6. 다국어 키 검증
// ══════════════════════════════════════════════════════════
describe('다국어 키', () => {
    it('_tab_shipping.json의 모든 i18n 키가 sirsoft-ecommerce 네임스페이스다', () => {
        const keys = collectI18nKeys(tabShipping);
        const moduleKeys = keys.filter((k) => k.startsWith('$t:sirsoft-ecommerce.'));
        const commonKeys = keys.filter((k) => k.startsWith('$t:common.'));
        // 모든 키가 sirsoft-ecommerce 또는 common 네임스페이스
        expect(moduleKeys.length + commonKeys.length).toBe(keys.length);
    });

    it('_shipping_country_table.json의 모든 i18n 키가 올바른 네임스페이스다', () => {
        const keys = collectI18nKeys(countryTable);
        const moduleKeys = keys.filter((k) => k.startsWith('$t:sirsoft-ecommerce.'));
        const commonKeys = keys.filter((k) => k.startsWith('$t:common.'));
        expect(moduleKeys.length + commonKeys.length).toBe(keys.length);
    });

    it('필수 다국어 키가 존재한다', () => {
        const allKeys = [
            ...collectI18nKeys(tabShipping),
            ...collectI18nKeys(countryTable),
            ...collectI18nKeys(disableIntlModal),
        ];
        const requiredKeys = [
            '$t:sirsoft-ecommerce.admin.settings.shipping.basic.title',
            '$t:sirsoft-ecommerce.admin.settings.shipping.basic.default_country',
            '$t:sirsoft-ecommerce.admin.settings.shipping.basic.international_shipping',
            '$t:sirsoft-ecommerce.admin.settings.shipping.countries.title',
            '$t:sirsoft-ecommerce.admin.settings.shipping.countries.add',
            '$t:sirsoft-ecommerce.admin.settings.shipping.countries.country_code',
            '$t:sirsoft-ecommerce.admin.settings.shipping.countries.country_name',
            '$t:sirsoft-ecommerce.admin.settings.shipping.countries.is_active',
            '$t:sirsoft-ecommerce.admin.settings.shipping.countries.empty',
            '$t:sirsoft-ecommerce.admin.settings.shipping.remote_area.title',
            '$t:sirsoft-ecommerce.admin.settings.shipping.remote_area.enable',
            '$t:sirsoft-ecommerce.admin.settings.shipping.modal.disable_international.title',
            '$t:sirsoft-ecommerce.admin.settings.shipping.modal.disable_international.message',
            '$t:sirsoft-ecommerce.admin.settings.shipping.modal.disable_international.confirm',
            '$t:sirsoft-ecommerce.admin.settings.shipping.modal.disable_international.cancel',
        ];
        for (const key of requiredKeys) {
            expect(allKeys).toContain(key);
        }
    });
});

// ══════════════════════════════════════════════════════════
// 7. 다크모드 검증
// ══════════════════════════════════════════════════════════
describe('다크모드', () => {
    it('_tab_shipping.json에 dark: 클래스가 사용된다', () => {
        const json = JSON.stringify(tabShipping);
        expect(json).toContain('dark:');
    });

    it('_shipping_country_table.json에 dark: 클래스가 사용된다', () => {
        const json = JSON.stringify(countryTable);
        expect(json).toContain('dark:');
    });
});

// ══════════════════════════════════════════════════════════
// 8. 배송가능국가 모바일 카드 — responsive 속성 검증
// ══════════════════════════════════════════════════════════
describe('배송가능국가 모바일 카드 — responsive 속성', () => {
    const allNodes = flattenAll(countryTable);

    it('country_list에 responsive.portable 속성이 있다', () => {
        const wrapper = findById(allNodes, 'country_list');
        expect(wrapper).toBeDefined();
        expect(wrapper.responsive).toBeDefined();
        expect(wrapper.responsive.portable).toBeDefined();
    });

    it('portable에서 className을 빈 문자열로 오버라이드한다', () => {
        const wrapper = findById(allNodes, 'country_list');
        expect(wrapper.responsive.portable.props?.className).toBe('');
    });

    it('portable children에 _shipping_country_cards.json partial이 있다', () => {
        const wrapper = findById(allNodes, 'country_list');
        const portableChildren = wrapper.responsive.portable.children;
        expect(portableChildren).toBeDefined();
        expect(portableChildren.length).toBe(1);
        expect(portableChildren[0].partial).toContain('_shipping_country_cards.json');
    });

    it('래퍼 props에 overflow-x-auto가 있다 (데스크톱 테이블 가로 스크롤)', () => {
        const wrapper = findById(allNodes, 'country_list');
        expect(wrapper.props?.className).toContain('overflow-x-auto');
    });
});

// ══════════════════════════════════════════════════════════
// 9. 배송가능국가 모바일 카드 — 루트 구조
// ══════════════════════════════════════════════════════════
describe('배송가능국가 모바일 카드 — 루트 구조', () => {
    it('카드 JSON이 partial이다 (meta.is_partial)', () => {
        expect(countryCards.meta?.is_partial).toBe(true);
    });

    it('루트 컨테이너가 space-y-3 클래스를 가진다', () => {
        expect(countryCards.props?.className).toContain('space-y-3');
    });

    it('카드가 excel-card 클래스로 반복 렌더링된다', () => {
        const allNodes = flattenAll(countryCards);
        const card = findById(allNodes, 'country_card');
        expect(card).toBeDefined();
        expect(card.props?.className).toContain('excel-card');
        expect(card.iteration).toBeDefined();
        expect(card.iteration.source).toContain('available_countries');
        expect(card.iteration.item_var).toBe('country');
        expect(card.iteration.index_var).toBe('countryIndex');
    });

    it('iteration source에 _idx 인덱스 주입이 포함된다', () => {
        const allNodes = flattenAll(countryCards);
        const card = findById(allNodes, 'country_card');
        expect(card.iteration.source).toContain('_idx');
        expect(card.iteration.source).toContain('.map(');
    });
});

// ══════════════════════════════════════════════════════════
// 10. 배송가능국가 모바일 카드 — 헤더 구조
// ══════════════════════════════════════════════════════════
describe('배송가능국가 모바일 카드 — 헤더 구조', () => {
    const allNodes = flattenAll(countryCards);

    it('excel-card-header가 존재한다', () => {
        const header = allNodes.find(
            (n: any) => n.props?.className === 'excel-card-header'
        );
        expect(header).toBeDefined();
    });

    it('국기 아이콘 Span이 fi fi- 클래스로 표시된다', () => {
        const flagSpan = allNodes.find(
            (s: any) => s.name === 'Span' && s.props?.className?.includes('fi fi-')
        );
        expect(flagSpan).toBeDefined();
        expect(flagSpan.props.className).toContain('fis');
        expect(flagSpan.props.className).toContain('rounded');
    });

    it('국가코드가 excel-card-title font-mono로 표시된다', () => {
        const title = allNodes.find(
            (n: any) =>
                n.props?.className?.includes('excel-card-title') &&
                n.props?.className?.includes('font-mono')
        );
        expect(title).toBeDefined();
        expect(title.text).toBe('{{country.code}}');
    });

    it('국가명이 $localized로 text-tertiary로 표시된다', () => {
        const subtitle = allNodes.find(
            (n: any) =>
                n.props?.className?.includes('text-tertiary') &&
                n.text?.includes('$localized(country.name)')
        );
        expect(subtitle).toBeDefined();
    });

    it('활성여부 Toggle이 sm 사이즈로 존재한다', () => {
        const toggles = allNodes.filter((n: any) => n.name === 'Toggle');
        expect(toggles.length).toBe(1);
        const toggle = toggles[0];
        expect(toggle.props?.checked).toContain('country.is_active');
        expect(toggle.props?.size).toBe('sm');
    });

    it('활성여부 Toggle이 해외배송 OFF + 비기본국가 시 disabled', () => {
        const toggle = allNodes.find((n: any) => n.name === 'Toggle');
        expect(toggle.props?.disabled).toContain('international_shipping_enabled');
        expect(toggle.props?.disabled).toContain('default_country');
    });

    it('삭제 버튼이 기본 국가가 아닌 경우에만 표시된다', () => {
        const deleteBtn = allNodes.find(
            (n: any) =>
                n.name === 'Button' &&
                n.if?.includes('country.code') &&
                n.if?.includes('!==')
        );
        expect(deleteBtn).toBeDefined();
        expect(deleteBtn.props?.className).toContain('btn-icon');
        expect(deleteBtn.props?.className).toContain('text-error');
    });

    it('삭제 버튼이 해외배송 OFF 시 disabled', () => {
        const deleteBtn = allNodes.find(
            (n: any) => n.name === 'Button' && n.if?.includes('!==')
        );
        expect(deleteBtn.props?.disabled).toContain('international_shipping_enabled');
    });

    it('기본 국가에는 기본 배지가 표시된다', () => {
        const badge = allNodes.find(
            (s: any) =>
                s.name === 'Span' &&
                s.if?.includes('country.code') &&
                s.if?.includes('===')
        );
        expect(badge).toBeDefined();
        expect(badge.text).toContain('$t:sirsoft-ecommerce.admin.settings.shipping.countries.default_badge');
        expect(badge.props?.className).toContain('bg-blue-100');
        expect(badge.props?.className).toContain('dark:bg-blue-900');
    });
});

// ══════════════════════════════════════════════════════════
// 11. 배송가능국가 모바일 카드 — Toggle/Delete 핸들러
// ══════════════════════════════════════════════════════════
describe('배송가능국가 모바일 카드 — Toggle/Delete 핸들러', () => {
    const allNodes = flattenAll(countryCards);

    it('Toggle의 setState가 배열 map으로 is_active를 토글한다', () => {
        const toggle = allNodes.find((n: any) => n.name === 'Toggle');
        const action = toggle?.actions?.find(
            (a: any) => a.handler === 'setState'
        );
        expect(action).toBeDefined();
        expect(action.params?.target).toBe('local');
        const value = action.params?.['form.shipping.available_countries'];
        expect(value).toContain('.map(');
        expect(value).toContain('is_active');
        expect(value).toContain('country._idx');
        expect(action.params?.hasChanges).toBe(true);
    });

    it('삭제 버튼의 setState가 배열 filter로 해당 국가를 제거한다', () => {
        const deleteBtn = allNodes.find(
            (n: any) => n.name === 'Button' && n.if?.includes('!==')
        );
        const action = deleteBtn?.actions?.find(
            (a: any) => a.handler === 'setState'
        );
        expect(action).toBeDefined();
        expect(action.params?.target).toBe('local');
        const value = action.params?.['form.shipping.available_countries'];
        expect(value).toContain('.filter(');
        expect(value).toContain('country._idx');
        expect(action.params?.hasChanges).toBe(true);
    });

    it('카드 Toggle의 setState 핸들러가 테이블 Toggle과 동일하다', () => {
        const tableNodes = flattenAll(countryTable);
        const tableToggle = tableNodes.find(
            (n: any) => n.name === 'Toggle' && n.props?.checked?.includes('country.is_active')
        );
        const cardToggle = allNodes.find((n: any) => n.name === 'Toggle');

        const tableAction = tableToggle?.actions?.find(
            (a: any) => a.handler === 'setState'
        );
        const cardAction = cardToggle?.actions?.find(
            (a: any) => a.handler === 'setState'
        );

        expect(cardAction.params?.['form.shipping.available_countries']).toBe(
            tableAction.params?.['form.shipping.available_countries']
        );
    });

    it('카드 삭제 버튼의 setState 핸들러가 테이블 삭제 버튼과 동일하다', () => {
        const tableNodes = flattenAll(countryTable);
        const tableDeleteBtn = tableNodes.find(
            (n: any) =>
                n.name === 'Button' &&
                n.if?.includes('country.code') &&
                n.if?.includes('!==')
        );
        const cardDeleteBtn = allNodes.find(
            (n: any) => n.name === 'Button' && n.if?.includes('!==')
        );

        const tableAction = tableDeleteBtn?.actions?.find(
            (a: any) => a.handler === 'setState'
        );
        const cardAction = cardDeleteBtn?.actions?.find(
            (a: any) => a.handler === 'setState'
        );

        expect(cardAction.params?.['form.shipping.available_countries']).toBe(
            tableAction.params?.['form.shipping.available_countries']
        );
    });
});

// ══════════════════════════════════════════════════════════
// 12. 배송가능국가 모바일 카드 — 다국어/다크모드
// ══════════════════════════════════════════════════════════
describe('배송가능국가 모바일 카드 — 다국어/다크모드', () => {
    it('카드의 i18n 키가 올바른 네임스페이스를 사용한다', () => {
        const keys = collectI18nKeys(countryCards);
        const moduleKeys = keys.filter((k) => k.startsWith('$t:sirsoft-ecommerce.'));
        const commonKeys = keys.filter((k) => k.startsWith('$t:common.'));
        expect(moduleKeys.length + commonKeys.length).toBe(keys.length);
    });

    it('카드에 dark: 클래스가 사용된다', () => {
        const json = JSON.stringify(countryCards);
        expect(json).toContain('dark:');
    });

    it('기본 배지에 다크모드 클래스 쌍이 있다', () => {
        const allNodes = flattenAll(countryCards);
        const badge = allNodes.find(
            (s: any) =>
                s.name === 'Span' &&
                s.if?.includes('===') &&
                s.props?.className?.includes('bg-blue-100')
        );
        expect(badge).toBeDefined();
        expect(badge.props.className).toContain('dark:bg-blue-900');
        expect(badge.props.className).toContain('dark:text-blue-300');
    });
});
