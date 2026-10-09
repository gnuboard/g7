/**
 * 배송유형 관리 섹션 레이아웃 구조 테스트
 *
 * 검증 대상:
 * - _shipping_type_section.json (배송유형 관리 — 메인 저장 통합)
 * - _shipping_type_cards.json (모바일 카드뷰)
 * - _tab_shipping.json 에 partial 참조 존재
 *
 * 배송유형 섹션 기능:
 * - 배송유형 목록 테이블 (code, name, category, is_active, actions 컬럼)
 * - 인라인 편집 (name: MultilingualInput, category: Select, is_active: Toggle)
 * - 추가 폼: code, name, category 필드 + 검증
 * - 삭제: confirm 후 setState
 * - 모바일 카드뷰: responsive portable
 */
import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import typeSection from '../../../layouts/admin/partials/admin_ecommerce_settings/_shipping_type_section.json';
import typeCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_shipping_type_cards.json';
import tabShipping from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_shipping.json';

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

function findAllByName(nodes: any[], name: string): any[] {
    return nodes.filter((n: any) => n.name === name);
}

function collectI18nKeys(obj: any): string[] {
    const keys: string[] = [];
    const str = JSON.stringify(obj);
    const matches = str.match(/\$t:[a-zA-Z0-9_.\-]+/g);
    if (matches) keys.push(...matches);
    return [...new Set(keys)];
}

function findButtonWithIcon(allNodes: any[], iconName: string): any {
    const buttons = findAllByName(allNodes, 'Button');
    return buttons.find((b: any) => {
        const btnNodes = flattenAll(b);
        return btnNodes.some(
            (n: any) => n.name === 'Icon' && n.props?.name?.includes(iconName)
        );
    });
}

function findButtonByChildText(allNodes: any[], textMatch: string): any {
    const buttons = findAllByName(allNodes, 'Button');
    return buttons.find((b: any) => {
        const btnNodes = flattenAll(b);
        return btnNodes.some(
            (n: any) => n.name === 'Span' && typeof n.text === 'string' && n.text.includes(textMatch)
        );
    });
}

// ══════════════════════════════════════════════════════════
// 1. _tab_shipping.json 에서 shipping type section partial 참조
// ══════════════════════════════════════════════════════════
describe('배송설정 탭에서 배송유형 섹션 참조', () => {
    const allTabNodes = flattenAll(tabShipping);

    /**
     * @scenario section=type
     * @effects settings_shipping_tab_includes_type_section
     */
    it('_tab_shipping.json에 _shipping_type_section.json partial이 포함되어 있다', () => {
        const partials = allTabNodes.filter(
            (n: any) => n.partial === '_shipping_type_section.json'
        );
        expect(partials.length).toBeGreaterThanOrEqual(1);
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_tab_includes_type_section
     */
    it('배송유형 섹션은 배송사 섹션보다 앞에 위치한다', () => {
        const jsonStr = JSON.stringify(tabShipping);
        const typePos = jsonStr.indexOf('_shipping_type_section.json');
        const carrierPos = jsonStr.indexOf('_shipping_carrier_section.json');
        expect(typePos).toBeLessThan(carrierPos);
    });
});

// ══════════════════════════════════════════════════════════
// 2. _shipping_type_section.json 구조 검증
// ══════════════════════════════════════════════════════════
describe('배송유형 관리 섹션 구조', () => {
    const allNodes = flattenAll(typeSection);

    /**
     * @scenario section=type
     * @effects settings_shipping_type_section_structure
     */
    it('카드 구조 (admin-card → card-header + 테이블)를 가진다', () => {
        expect(typeSection.props?.className).toBe('admin-card');
        const cardHeaders = allNodes.filter(
            (n: any) => n.props?.className === 'card-header'
        );
        expect(cardHeaders.length).toBeGreaterThanOrEqual(1);
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_section_structure
     */
    it('섹션 제목이 다국어 키를 사용한다', () => {
        const h3 = findAllByName(allNodes, 'H3').find(
            (n: any) => n.props?.className === 'card-title'
        );
        expect(h3?.text).toBe('$t:sirsoft-ecommerce.admin.settings.shipping.types.section_title');
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_section_structure
     */
    it('추가 버튼이 존재하고 isAddingShippingType 조건으로 표시된다', () => {
        const addBtn = findButtonByChildText(allNodes, 'types.add_button');
        expect(addBtn).toBeDefined();
        expect(addBtn.if).toContain('!_local.isAddingShippingType');
    });
});

// ══════════════════════════════════════════════════════════
// 3. 추가 폼 구조 검증
// ══════════════════════════════════════════════════════════
describe('배송유형 추가 폼', () => {
    const allNodes = flattenAll(typeSection);

    /**
     * @scenario section=type
     * @effects settings_shipping_type_add_form_requires_site_locale_name
     */
    it('추가 폼이 isAddingShippingType 조건으로 표시된다', () => {
        const addForm = allNodes.find(
            (n: any) => n.if === '{{_local.isAddingShippingType}}' && n.props?.className?.includes('border-b')
        );
        expect(addForm).toBeDefined();
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_add_form_requires_site_locale_name
     */
    it('코드, 유형명(MultilingualInput), 카테고리(Select) 3개 필드가 있다', () => {
        // 추가 폼 내 Input (code)
        const codeInput = allNodes.find(
            (n: any) => n.name === 'Input' && n.props?.value?.includes('shippingTypeForm?.code')
        );
        expect(codeInput).toBeDefined();

        // 추가 폼 내 MultilingualInput (name)
        const nameInput = allNodes.find(
            (n: any) => n.name === 'MultilingualInput' && n.props?.value?.includes('shippingTypeForm?.name')
        );
        expect(nameInput).toBeDefined();

        // 추가 폼 내 Select (category)
        const categorySelect = allNodes.find(
            (n: any) => n.name === 'Select' && n.props?.value?.includes('shippingTypeForm?.category')
        );
        expect(categorySelect).toBeDefined();
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_add_form_requires_site_locale_name
     */
    it('카테고리 Select에 domestic, international, other 옵션이 있다', () => {
        const selects = findAllByName(allNodes, 'Select');
        const categorySelect = selects.find(
            (s: any) => s.props?.value?.includes('shippingTypeForm?.category')
        );
        expect(categorySelect).toBeDefined();
        const options = categorySelect.props.options;
        expect(options).toHaveLength(3);
        expect(options.map((o: any) => o.value)).toEqual(['domestic', 'international', 'other']);
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_add_form_requires_site_locale_name
     */
    it('취소/추가 버튼이 있다', () => {
        const cancelBtn = findButtonByChildText(allNodes, 'types.form.cancel');
        const saveBtn = findButtonByChildText(allNodes, 'types.form.save');
        expect(cancelBtn).toBeDefined();
        expect(saveBtn).toBeDefined();
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_add_form_requires_site_locale_name
     */
    it('추가 버튼은 conditions 핸들러로 코드/이름 필수 검증을 수행한다', () => {
        const saveBtn = findButtonByChildText(allNodes, 'types.form.save');
        const clickAction = saveBtn.actions?.find((a: any) => a.type === 'click');
        expect(clickAction?.handler).toBe('conditions');
        expect(clickAction?.conditions).toBeDefined();
        expect(clickAction.conditions.length).toBeGreaterThanOrEqual(2);
    });

    // 이름 필수 판정은 **현재 로케일**이다 — `ko` 고정이 아니다. 형제인 배송사·사유 3종과 같은
    // 계약이며(S10), en/ja 로케일 운영자가 자기 언어 칸을 채우고도 폼 게이트에서 막히던 것을 없앤다.
    //
    // 배송사에는 이 단언이 있었고 배송유형에는 없었다 — 같은 수정을 두 파셜에 넣었는데 한쪽만
    // 계약으로 고정돼, 배송유형은 `ko` 로 되돌려도 어느 테스트도 붉어지지 않는 상태였다.
    /**
     * @scenario section=type
     * @effects settings_shipping_type_add_form_requires_site_locale_name
     */
    it('이름 필수 판정이 현재 로케일을 읽는다 (ko 고정 금지)', () => {
        const saveBtn = findButtonByChildText(allNodes, 'types.form.save');
        const clickAction = saveBtn.actions?.find((a: any) => a.type === 'click');

        const validCondition = clickAction.conditions[0];
        expect(validCondition.if).toContain("shippingTypeForm?.name?.[(_global.appConfig?.siteLocale ?? $locale ?? 'ko')]");
        expect(validCondition.if).not.toContain('shippingTypeForm?.name?.ko');

        const elseCondition = clickAction.conditions[clickAction.conditions.length - 1];
        expect(elseCondition.then.params?.shippingTypeFormErrors?.name_ko).toContain(
            "shippingTypeForm?.name?.[(_global.appConfig?.siteLocale ?? $locale ?? 'ko')]"
        );
        expect(elseCondition.then.params?.shippingTypeFormErrors?.name_ko).not.toContain(
            'shippingTypeForm?.name?.ko'
        );
    });
});

// ══════════════════════════════════════════════════════════
// 4. 테이블 구조 검증
// ══════════════════════════════════════════════════════════
describe('배송유형 테이블', () => {
    const allNodes = flattenAll(typeSection);

    /**
     * @scenario section=type
     * @effects settings_shipping_type_table
     */
    it('6개 컬럼 헤더가 있다 (드래그핸들, code, name, category, is_active, actions)', () => {
        const ths = findAllByName(allNodes, 'Th');
        expect(ths.length).toBe(6);
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_table
     */
    it('Tbody에 sortable이 정의되어 _local.form.shipping.types를 순회한다', () => {
        const tbodies = allNodes.filter((n: any) => n.name === 'Tbody');
        const sortableTbody = tbodies.find((tb: any) => tb.sortable);
        expect(sortableTbody).toBeDefined();
        expect(sortableTbody.sortable.source).toContain('_local.form?.shipping?.types');
        expect(sortableTbody.sortable.itemVar).toBe('$type');
        expect(sortableTbody.sortable.wrapperElement).toBe('tr');
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_table
     */
    it('sortable onSortEnd가 sort_order를 재계산한다', () => {
        const tbodies = allNodes.filter((n: any) => n.name === 'Tbody');
        const sortableTbody = tbodies.find((tb: any) => tb.sortable);
        const sortEndAction = sortableTbody?.actions?.find((a: any) => a.event === 'onSortEnd');
        expect(sortEndAction).toBeDefined();
        expect(sortEndAction.params['form.shipping.types']).toContain('sort_order');
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_table
     */
    it('itemTemplate에 드래그 핸들, MultilingualInput, Select, Toggle이 있다', () => {
        const tbodies = allNodes.filter((n: any) => n.name === 'Tbody');
        const sortableTbody = tbodies.find((tb: any) => tb.sortable);
        const templateNodes = flattenAll(sortableTbody?.itemTemplate);

        // 드래그 핸들
        const dragHandle = templateNodes.find((n: any) => n.props?.['data-drag-handle']);
        expect(dragHandle).toBeDefined();

        const multiInput = findAllByName(templateNodes, 'MultilingualInput');
        expect(multiInput.length).toBeGreaterThanOrEqual(1);

        const selects = findAllByName(templateNodes, 'Select');
        expect(selects.length).toBeGreaterThanOrEqual(1);

        const toggles = findAllByName(templateNodes, 'Toggle');
        expect(toggles.length).toBeGreaterThanOrEqual(1);
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_table
     */
    it('itemTemplate에 삭제 버튼이 confirm 포함 setState를 사용한다', () => {
        const tbodies = allNodes.filter((n: any) => n.name === 'Tbody');
        const sortableTbody = tbodies.find((tb: any) => tb.sortable);
        const templateNodes = flattenAll(sortableTbody?.itemTemplate);
        const deleteBtn = findButtonWithIcon(templateNodes, 'fa-trash');
        expect(deleteBtn).toBeDefined();
        const clickAction = deleteBtn.actions?.find((a: any) => a.type === 'click');
        expect(clickAction?.handler).toBe('setState');
        expect(clickAction?.confirm).toContain('confirm_delete');
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_table
     */
    it('빈 상태 메시지가 있다', () => {
        const emptyMsg = allNodes.find(
            (n: any) => n.text?.includes('types.empty')
        );
        expect(emptyMsg).toBeDefined();
    });
});

// ══════════════════════════════════════════════════════════
// 5. 반응형 (모바일 카드뷰) 검증
// ══════════════════════════════════════════════════════════
describe('배송유형 모바일 카드뷰', () => {
    /**
     * @scenario section=type
     * @effects settings_shipping_type_mobile_cards
     */
    it('데스크톱 테이블에 responsive.portable이 정의되어 있다', () => {
        const allNodes = flattenAll(typeSection);
        const listDiv = allNodes.find((n: any) => n.id === 'shipping_type_list');
        expect(listDiv).toBeDefined();
        expect(listDiv.responsive?.portable).toBeDefined();
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_mobile_cards
     */
    it('shipping_type_list에 overflow-x-clip 사용 (Select 드롭다운 가려짐 방지)', () => {
        const allNodes = flattenAll(typeSection);
        const listDiv = allNodes.find((n: any) => n.id === 'shipping_type_list');
        expect(listDiv?.props?.className).toContain('overflow-x-clip');
        expect(listDiv?.props?.className).not.toContain('overflow-x-auto');
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_mobile_cards
     */
    it('카드뷰가 _shipping_type_cards.json partial을 참조한다', () => {
        const allNodes = flattenAll(typeSection);
        const listDiv = allNodes.find((n: any) => n.id === 'shipping_type_list');
        const portableChildren = listDiv?.responsive?.portable?.children;
        expect(portableChildren).toBeDefined();
        expect(portableChildren[0]?.partial).toBe('_shipping_type_cards.json');
    });
});

// ══════════════════════════════════════════════════════════
// 6. _shipping_type_cards.json 구조 검증
// ══════════════════════════════════════════════════════════
describe('배송유형 카드뷰 구조', () => {
    const allNodes = flattenAll(typeCards);

    /**
     * @scenario section=type
     * @effects settings_shipping_type_card_structure
     */
    it('iteration으로 _local.form.shipping.types를 순회한다', () => {
        const card = allNodes.find((n: any) => n.id === 'shipping_type_card_{{tIdx}}');
        expect(card).toBeDefined();
        expect(card.iteration.source).toContain('_local.form?.shipping?.types');
        expect(card.iteration.item_var).toBe('type');
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_card_structure
     */
    it('카드 헤더에 code 텍스트와 is_active Toggle이 있다', () => {
        const cardHeader = allNodes.find(
            (n: any) => n.props?.className === 'excel-card-header'
        );
        expect(cardHeader).toBeDefined();
        const headerNodes = flattenAll(cardHeader);

        // code 표시
        const codeText = headerNodes.find(
            (n: any) => n.text === '{{type.code}}'
        );
        expect(codeText).toBeDefined();

        // Toggle
        const toggles = findAllByName(headerNodes, 'Toggle');
        expect(toggles.length).toBeGreaterThanOrEqual(1);
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_card_structure
     */
    it('카드 본문에 name(MultilingualInput)과 category(Select)가 있다', () => {
        const body = allNodes.find(
            (n: any) => n.props?.className === 'excel-card-body'
        );
        const bodyNodes = flattenAll(body);

        const multiInput = findAllByName(bodyNodes, 'MultilingualInput');
        expect(multiInput.length).toBeGreaterThanOrEqual(1);

        const selects = findAllByName(bodyNodes, 'Select');
        expect(selects.length).toBeGreaterThanOrEqual(1);
    });

    /**
     * @scenario section=type
     * @effects settings_shipping_type_card_structure
     */
    it('카드 푸터에 삭제 버튼이 있다', () => {
        const footer = allNodes.find(
            (n: any) => n.props?.className === 'excel-card-footer'
        );
        const footerNodes = flattenAll(footer);
        const deleteBtn = findButtonWithIcon(footerNodes, 'fa-trash');
        expect(deleteBtn).toBeDefined();
    });
});

// ══════════════════════════════════════════════════════════
// 7. 다국어 키 검증
// ══════════════════════════════════════════════════════════
describe('배송유형 관리 다국어 키', () => {
    /**
     * @scenario section=type
     * @effects settings_shipping_type_i18n_keys_exist
     */
    it('섹션에 필수 다국어 키가 포함되어 있다', () => {
        const keys = collectI18nKeys(typeSection);

        const requiredKeys = [
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.section_title',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.add_button',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.empty',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.form.code',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.form.name',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.form.category',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.form.cancel',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.form.save',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.categories.domestic',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.categories.international',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.categories.other',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.confirm_delete',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.columns.code',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.columns.name',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.columns.category',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.columns.is_active',
            '$t:sirsoft-ecommerce.admin.settings.shipping.types.columns.actions',
        ];

        for (const key of requiredKeys) {
            expect(keys).toContain(key);
        }
    });
});

// ══════════════════════════════════════════════════════════
// 8. 주문 필터 동적화 검증
// ══════════════════════════════════════════════════════════
describe('주문목록 필터 배송유형 동적화', () => {
    // 주문목록 레이아웃 임포트
    const orderList = require('../../../layouts/admin/admin_ecommerce_order_list.json');

    /**
     * @scenario section=type
     * @effects order_list_filter_uses_active_shipping_types
     */
    it('computed에 shippingTypeOptions가 정의되어 있다', () => {
        expect(orderList.computed?.shippingTypeOptions).toBeDefined();
        expect(orderList.computed.shippingTypeOptions).toContain('shipping?.types');
        expect(orderList.computed.shippingTypeOptions).toContain('is_active');
    });
});

// ══════════════════════════════════════════════════════════
// 9. 배송정책 폼 동적화 검증
// ══════════════════════════════════════════════════════════
describe('배송정책 폼 배송방법 동적화', () => {
    const policyForm = require('../../../layouts/admin/admin_ecommerce_shipping_policy_form.json');

    /**
     * @scenario section=type
     * @effects shipping_policy_form_methods_from_shipping_types
     */
    it('computed의 shippingMethodOptions가 DB 기반 동적으로 변경되었다', () => {
        const options = policyForm.computed?.shippingMethodOptions;
        expect(options).toBeDefined();
        // 하드코딩된 값이 아닌 ecommerce_settings 참조
        expect(options).toContain('ecommerce_settings');
        expect(options).toContain('shipping?.types');
        // 기존 하드코딩 패턴이 없어야 함
        expect(options).not.toContain("$t('sirsoft-ecommerce.admin.shipping_policy.enums.shipping_method");
    });
});
