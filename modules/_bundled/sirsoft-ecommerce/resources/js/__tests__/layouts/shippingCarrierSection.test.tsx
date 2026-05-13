/**
 * 배송 택배사 관리 섹션 레이아웃 구조 테스트
 *
 * 검증 대상:
 * - _shipping_carrier_section.json (택배사 관리 — 메인 저장 통합)
 * - _tab_shipping.json 에 partial 참조 존재
 *
 * 택배사 섹션 기능:
 * - 택배사 목록 테이블 (code, name, type, tracking_url, is_active, actions 컬럼)
 * - 모든 행이 항상 Input/Select/Toggle/MultilingualInput으로 표시
 * - 필드 변경 시 .map() 패턴 setState로 _local.form.shipping.carriers 직접 수정 + hasChanges=true
 * - 삭제 시 .filter() 패턴 setState + confirm → 메인 저장 시 DB 반영
 * - 추가 폼: 로컬 검증 후 배열에 추가 (apiCall 없음)
 * - 저장/취소 per-row 버튼 없음 → 메인 탭 저장 버튼으로만 반영
 * - Validation 에러: 추가 폼 (carrierFormErrors) + 테이블 행 (_local.errors)
 */
import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import carrierSection from '../../../layouts/admin/partials/admin_ecommerce_settings/_shipping_carrier_section.json';
import carrierCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_shipping_carrier_cards.json';
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
    if (node.itemTemplate) result.push(...flattenAll(node.itemTemplate));
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

/** 특정 아이콘을 가진 버튼 찾기 */
function findButtonWithIcon(allNodes: any[], iconName: string): any {
    const buttons = findAllByName(allNodes, 'Button');
    return buttons.find((b: any) => {
        const btnNodes = flattenAll(b);
        return btnNodes.some(
            (n: any) => n.name === 'Icon' && n.props?.name?.includes(iconName)
        );
    });
}

/** 모든 Span/Button 중 text를 가진 노드(children 내부 Span 포함)에서 텍스트 검색 */
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
// 1. _tab_shipping.json 에서 carrier section partial 참조
// ══════════════════════════════════════════════════════════
describe('배송설정 탭에서 택배사 섹션 참조', () => {
    const allTabNodes = flattenAll(tabShipping);

    it('_tab_shipping.json에 _shipping_carrier_section.json partial이 포함되어 있다', () => {
        const partialRef = allTabNodes.find(
            (n: any) => n.partial?.includes('_shipping_carrier_section.json')
        );
        expect(partialRef).toBeDefined();
    });
});

// ══════════════════════════════════════════════════════════
// 2. 택배사 섹션 루트 구조
// ══════════════════════════════════════════════════════════
describe('택배사 섹션 루트 구조 (_shipping_carrier_section.json)', () => {
    it('루트 노드가 card 클래스의 Div이다', () => {
        expect(carrierSection.name).toBe('Div');
        expect(carrierSection.type).toBe('basic');
        expect(carrierSection.props?.className).toContain('card');
    });

    it('card-header 영역이 존재한다', () => {
        const allNodes = flattenAll(carrierSection);
        const headerDiv = allNodes.find(
            (n: any) => n.props?.className?.includes('card-header')
        );
        expect(headerDiv).toBeDefined();
    });

    it('섹션 제목(H3)이 다국어 키로 표시된다', () => {
        const allNodes = flattenAll(carrierSection);
        const title = allNodes.find(
            (n: any) => n.name === 'H3' && n.props?.className?.includes('card-title')
        );
        expect(title).toBeDefined();
        expect(title.text).toBe('$t:sirsoft-ecommerce.admin.settings.shipping.carriers.section_title');
    });
});

// ══════════════════════════════════════════════════════════
// 3. 추가 버튼
// ══════════════════════════════════════════════════════════
describe('택배사 추가 버튼', () => {
    const allNodes = flattenAll(carrierSection);

    it('추가 버튼이 존재한다 (children Span에 add_button 텍스트)', () => {
        const addBtn = findButtonByChildText(allNodes, 'carriers.add_button');
        expect(addBtn).toBeDefined();
    });

    it('추가 폼이 열려있지 않을 때만 버튼이 표시된다', () => {
        const addBtn = findButtonByChildText(allNodes, 'carriers.add_button');
        // 추가 버튼은 if에 !_local.isAddingCarrier 조건이 있어야 함
        // 단, 저장 버튼도 add_button 텍스트를 가지므로 if 조건으로 구분
        const headerAddBtn = [addBtn].find((b: any) => b.if?.includes('!_local.isAddingCarrier'));
        expect(headerAddBtn).toBeDefined();
    });

    it('추가 버튼에 type="button"이 명시되어 있다 (submit 방지)', () => {
        const addBtn = findButtonByChildText(allNodes, 'carriers.add_button');
        // 헤더 추가 버튼 (if 조건 있는 것)
        const headerAddBtn = findAllByName(allNodes, 'Button').find(
            (b: any) => b.if?.includes('!_local.isAddingCarrier')
        );
        expect(headerAddBtn?.props?.type).toBe('button');
    });

    it('추가 버튼에 Tailwind className 스타일이 적용되어 있다', () => {
        const headerAddBtn = findAllByName(allNodes, 'Button').find(
            (b: any) => b.if?.includes('!_local.isAddingCarrier')
        );
        expect(headerAddBtn?.props?.className).toContain('bg-gray-900');
        expect(headerAddBtn?.props?.className).toContain('dark:bg-white');
        expect(headerAddBtn?.props?.className).toContain('rounded-lg');
        expect(headerAddBtn?.props?.className).toContain('font-medium');
    });

    it('추가 버튼 클릭 시 isAddingCarrier가 true로 설정되고 carrierForm 초기화가 된다', () => {
        const headerAddBtn = findAllByName(allNodes, 'Button').find(
            (b: any) => b.if?.includes('!_local.isAddingCarrier')
        );
        const clickAction = headerAddBtn?.actions?.find(
            (a: any) => a.type === 'click' && a.handler === 'setState'
        );
        expect(clickAction).toBeDefined();
        expect(clickAction.params?.isAddingCarrier).toBe(true);
        expect(clickAction.params?.carrierForm).toBeDefined();
        expect(clickAction.params?.carrierForm.code).toBe('');
        expect(clickAction.params?.carrierForm.type).toBe('domestic');
        expect(clickAction.params?.carrierForm.tracking_url).toBe('');
        expect(clickAction.params?.carrierForm.is_active).toBe(true);
    });

    it('추가 버튼 클릭 시 carrierForm.name이 다국어 객체로 초기화된다', () => {
        const headerAddBtn = findAllByName(allNodes, 'Button').find(
            (b: any) => b.if?.includes('!_local.isAddingCarrier')
        );
        const clickAction = headerAddBtn?.actions?.find(
            (a: any) => a.type === 'click' && a.handler === 'setState'
        );
        expect(clickAction.params?.carrierForm.name).toEqual({ ko: '', en: '' });
    });

    it('추가 버튼 클릭 시 carrierFormErrors가 null로 초기화된다', () => {
        const headerAddBtn = findAllByName(allNodes, 'Button').find(
            (b: any) => b.if?.includes('!_local.isAddingCarrier')
        );
        const clickAction = headerAddBtn?.actions?.find(
            (a: any) => a.type === 'click' && a.handler === 'setState'
        );
        expect(clickAction.params?.carrierFormErrors).toBeNull();
    });
});

// ══════════════════════════════════════════════════════════
// 4. 추가 폼
// ══════════════════════════════════════════════════════════
describe('택배사 추가 폼', () => {
    const allNodes = flattenAll(carrierSection);

    it('isAddingCarrier가 true일 때만 폼이 표시된다', () => {
        const formContainer = allNodes.find(
            (n: any) =>
                n.if?.includes('_local.isAddingCarrier') &&
                !n.if?.includes('!') &&
                n.props?.className?.includes('border-b')
        );
        expect(formContainer).toBeDefined();
    });

    it('코드(code) 입력 Input이 존재한다', () => {
        const inputs = findAllByName(allNodes, 'Input');
        const codeInput = inputs.find(
            (i: any) => i.props?.value?.includes('carrierForm?.code')
        );
        expect(codeInput).toBeDefined();
        expect(codeInput.props?.type).toBe('text');
    });

    it('코드 Input의 onChange가 carrierForm.code를 setState한다', () => {
        const inputs = findAllByName(allNodes, 'Input');
        const codeInput = inputs.find(
            (i: any) => i.props?.value?.includes('carrierForm?.code')
        );
        const action = codeInput?.actions?.find(
            (a: any) => a.type === 'change' && a.handler === 'setState'
        );
        expect(action).toBeDefined();
        expect(action.params?.['carrierForm.code']).toContain('$event.target.value');
    });

    it('이름(name) 필드가 MultilingualInput 컴포지트 컴포넌트이다', () => {
        const composites = allNodes.filter(
            (n: any) => n.type === 'composite' && n.name === 'MultilingualInput'
        );
        const addFormMultilingual = composites.find(
            (c: any) => c.props?.value?.includes('carrierForm?.name')
        );
        expect(addFormMultilingual).toBeDefined();
        expect(addFormMultilingual.props?.layout).toBe('inline');
    });

    it('MultilingualInput의 onChange가 carrierForm.name을 setState한다', () => {
        const composites = allNodes.filter(
            (n: any) => n.type === 'composite' && n.name === 'MultilingualInput'
        );
        const addFormMultilingual = composites.find(
            (c: any) => c.props?.value?.includes('carrierForm?.name')
        );
        const action = addFormMultilingual?.actions?.find(
            (a: any) => a.type === 'change' && a.handler === 'setState'
        );
        expect(action).toBeDefined();
        expect(action.params?.['carrierForm.name']).toContain('$event.target.value');
    });

    it('택배사 유형 Select가 domestic/international 옵션을 갖는다', () => {
        const selects = findAllByName(allNodes, 'Select');
        const typeSelect = selects.find(
            (s: any) => s.props?.value?.includes('carrierForm?.type')
        );
        expect(typeSelect).toBeDefined();
        const options = typeSelect.props?.options;
        expect(options).toHaveLength(2);
        const values = options.map((o: any) => o.value);
        expect(values).toContain('domestic');
        expect(values).toContain('international');
    });

    it('택배사 유형 Select의 onChange가 carrierForm.type을 setState한다', () => {
        const selects = findAllByName(allNodes, 'Select');
        const typeSelect = selects.find(
            (s: any) => s.props?.value?.includes('carrierForm?.type')
        );
        const action = typeSelect?.actions?.find(
            (a: any) => a.type === 'change' && a.handler === 'setState'
        );
        expect(action).toBeDefined();
        expect(action.params?.['carrierForm.type']).toContain('$event.target.value');
    });

    it('추적 URL Input이 존재한다', () => {
        const inputs = findAllByName(allNodes, 'Input');
        const trackingInput = inputs.find(
            (i: any) => i.props?.value?.includes('carrierForm?.tracking_url')
        );
        expect(trackingInput).toBeDefined();
    });

    it('추적 URL 도움말이 존재한다', () => {
        const helpTexts = allNodes.filter(
            (n: any) =>
                n.name === 'P' &&
                n.text?.includes('$t:sirsoft-ecommerce.admin.settings.shipping.carriers.form.tracking_url_help')
        );
        expect(helpTexts.length).toBeGreaterThan(0);
    });

    it('취소 버튼이 isAddingCarrier를 false로 설정하고 carrierForm/carrierFormErrors를 null로 초기화한다', () => {
        const cancelBtn = findButtonByChildText(allNodes, 'form.cancel');
        expect(cancelBtn).toBeDefined();
        const action = cancelBtn.actions?.find(
            (a: any) => a.type === 'click' && a.handler === 'setState'
        );
        expect(action).toBeDefined();
        expect(action.params?.isAddingCarrier).toBe(false);
        expect(action.params?.carrierForm).toBeNull();
        expect(action.params?.carrierFormErrors).toBeNull();
    });

    it('취소 버튼에 Tailwind outline 스타일이 적용되어 있다', () => {
        const cancelBtn = findButtonByChildText(allNodes, 'form.cancel');
        expect(cancelBtn.props?.className).toContain('border');
        expect(cancelBtn.props?.className).toContain('border-gray-300');
        expect(cancelBtn.props?.className).toContain('rounded-lg');
    });

    it('저장 버튼이 conditions 핸들러로 로컬 검증을 수행한다 (apiCall 아님)', () => {
        // 추가 폼의 저장 버튼 (add_button 텍스트 + if 없음 = 폼 내부 저장)
        const buttons = findAllByName(allNodes, 'Button');
        const formSaveBtn = buttons.find((b: any) => {
            const btnNodes = flattenAll(b);
            const hasAddText = btnNodes.some(
                (n: any) => n.name === 'Span' && n.text?.includes('carriers.add_button')
            );
            return hasAddText && !b.if;
        });
        expect(formSaveBtn).toBeDefined();
        const action = formSaveBtn?.actions?.find(
            (a: any) => a.type === 'click' && a.handler === 'conditions'
        );
        expect(action).toBeDefined();
        // apiCall이 아님
        const apiAction = formSaveBtn?.actions?.find(
            (a: any) => a.handler === 'apiCall'
        );
        expect(apiAction).toBeUndefined();
    });

    it('저장 conditions: 유효하면 배열에 추가 + 폼 닫기 + hasChanges=true', () => {
        const buttons = findAllByName(allNodes, 'Button');
        const formSaveBtn = buttons.find((b: any) => {
            const btnNodes = flattenAll(b);
            return btnNodes.some(
                (n: any) => n.name === 'Span' && n.text?.includes('carriers.add_button')
            ) && !b.if;
        });
        const action = formSaveBtn?.actions?.find(
            (a: any) => a.handler === 'conditions'
        );
        const validCondition = action.conditions[0];
        expect(validCondition.if).toContain('carrierForm?.code');
        expect(validCondition.if).toContain('trim()');
        expect(validCondition.if).toContain('carrierForm?.name?.ko');
        expect(validCondition.then.handler).toBe('setState');
        expect(validCondition.then.params?.['form.shipping.carriers']).toContain('...');
        expect(validCondition.then.params?.['form.shipping.carriers']).toContain('carrierForm');
        expect(validCondition.then.params?.isAddingCarrier).toBe(false);
        expect(validCondition.then.params?.carrierForm).toBeNull();
        expect(validCondition.then.params?.carrierFormErrors).toBeNull();
        expect(validCondition.then.params?.hasChanges).toBe(true);
    });

    it('저장 conditions: 검증 실패 시 carrierFormErrors에 에러 플래그를 설정한다', () => {
        const buttons = findAllByName(allNodes, 'Button');
        const formSaveBtn = buttons.find((b: any) => {
            const btnNodes = flattenAll(b);
            return btnNodes.some(
                (n: any) => n.name === 'Span' && n.text?.includes('carriers.add_button')
            ) && !b.if;
        });
        const action = formSaveBtn?.actions?.find(
            (a: any) => a.handler === 'conditions'
        );
        const elseCondition = action.conditions[1];
        // else 조건 (if 없음)
        expect(elseCondition.if).toBeUndefined();
        expect(elseCondition.then.handler).toBe('setState');
        expect(elseCondition.then.params?.carrierFormErrors).toBeDefined();
        expect(elseCondition.then.params?.carrierFormErrors.code).toContain('carrierForm?.code');
        expect(elseCondition.then.params?.carrierFormErrors.name_ko).toContain('carrierForm?.name?.ko');
    });
});

// ══════════════════════════════════════════════════════════
// 5. 추가 폼 Validation 에러 표시
// ══════════════════════════════════════════════════════════
describe('추가 폼 Validation 에러 표시', () => {
    const allNodes = flattenAll(carrierSection);

    it('코드 Input에 carrierFormErrors.code 조건부 input-error 클래스가 적용된다', () => {
        const inputs = findAllByName(allNodes, 'Input');
        const codeInput = inputs.find(
            (i: any) => i.props?.value?.includes('carrierForm?.code')
        );
        expect(codeInput?.props?.className).toContain('carrierFormErrors?.code');
        expect(codeInput?.props?.className).toContain('input-error');
    });

    it('코드 에러 Span이 carrierFormErrors.code 조건으로 표시된다', () => {
        const spans = findAllByName(allNodes, 'Span');
        const errorSpan = spans.find(
            (s: any) =>
                s.if?.includes('carrierFormErrors?.code') &&
                s.text?.includes('validation.code_required')
        );
        expect(errorSpan).toBeDefined();
        expect(errorSpan.props?.className).toContain('text-red-500');
        expect(errorSpan.props?.className).toContain('text-xs');
    });

    it('이름 MultilingualInput에 error prop이 carrierFormErrors.name_ko 기반으로 설정된다', () => {
        const composites = allNodes.filter(
            (n: any) => n.type === 'composite' && n.name === 'MultilingualInput'
        );
        const addFormMultilingual = composites.find(
            (c: any) => c.props?.value?.includes('carrierForm?.name')
        );
        expect(addFormMultilingual?.props?.error).toContain('carrierFormErrors?.name_ko');
        expect(addFormMultilingual?.props?.error).toContain('validation.name_required');
    });
});

// ══════════════════════════════════════════════════════════
// 6. 빈 상태 메시지
// ══════════════════════════════════════════════════════════
describe('택배사 빈 상태 표시', () => {
    const allNodes = flattenAll(carrierSection);

    it('_local.form.shipping.carriers 기반으로 빈 상태 메시지가 표시된다', () => {
        const emptyDiv = allNodes.find(
            (n: any) =>
                n.if?.includes('_local.form?.shipping?.carriers') &&
                n.if?.includes('length === 0')
        );
        expect(emptyDiv).toBeDefined();
        expect(emptyDiv.text).toBe('$t:sirsoft-ecommerce.admin.settings.shipping.carriers.empty');
    });

    it('빈 상태 메시지에 다크모드 클래스가 있다', () => {
        const emptyDiv = allNodes.find(
            (n: any) =>
                n.if?.includes('_local.form?.shipping?.carriers') &&
                n.if?.includes('length === 0')
        );
        expect(emptyDiv?.props?.className).toContain('dark:');
    });
});

// ══════════════════════════════════════════════════════════
// 7. 택배사 테이블 구조
// ══════════════════════════════════════════════════════════
describe('택배사 테이블 구조', () => {
    const allNodes = flattenAll(carrierSection);

    it('_local.form.shipping.carriers 기반으로 테이블 영역이 표시된다', () => {
        const tableContainer = allNodes.find(
            (n: any) =>
                n.if?.includes('_local.form?.shipping?.carriers') &&
                n.if?.includes('length > 0')
        );
        expect(tableContainer).toBeDefined();
    });

    it('Table 컴포넌트가 존재한다', () => {
        const tables = findAllByName(allNodes, 'Table');
        expect(tables.length).toBeGreaterThan(0);
    });

    it('Thead에 7개 컬럼 헤더가 있다 (드래그핸들, code, name, type, tracking_url, is_active, actions)', () => {
        const thNodes = findAllByName(allNodes, 'Th');
        expect(thNodes.length).toBe(7);
    });

    it('컬럼 헤더에 올바른 다국어 키가 사용된다', () => {
        const thNodes = findAllByName(allNodes, 'Th');
        const expectedKeys = [
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.columns.code',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.columns.name',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.columns.type',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.columns.tracking_url',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.columns.is_active',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.columns.actions',
        ];
        const actualTexts = thNodes.map((th: any) => th.text);
        for (const key of expectedKeys) {
            expect(actualTexts).toContain(key);
        }
    });

    it('Tbody에 sortable이 정의되어 carriers를 드래그앤드롭으로 순서 변경할 수 있다', () => {
        const tbodies = allNodes.filter((n: any) => n.name === 'Tbody');
        const sortableTbody = tbodies.find((tb: any) => tb.sortable);
        expect(sortableTbody).toBeDefined();
        expect(sortableTbody.sortable.source).toContain('_local.form?.shipping?.carriers');
        expect(sortableTbody.sortable.itemVar).toBe('$carrier');
        expect(sortableTbody.sortable.wrapperElement).toBe('tr');
    });

    it('sortable onSortEnd가 sort_order를 재계산한다', () => {
        const tbodies = allNodes.filter((n: any) => n.name === 'Tbody');
        const sortableTbody = tbodies.find((tb: any) => tb.sortable);
        const sortEndAction = sortableTbody?.actions?.find((a: any) => a.event === 'onSortEnd');
        expect(sortEndAction).toBeDefined();
        expect(sortEndAction.params['form.shipping.carriers']).toContain('sort_order');
    });
});

// ══════════════════════════════════════════════════════════
// 8. 테이블 행 필드 — .map() 패턴 setState
// ══════════════════════════════════════════════════════════
describe('테이블 행 필드 — .map() 패턴 setState', () => {
    const allNodes = flattenAll(carrierSection);

    describe('코드(code) 컬럼', () => {
        it('코드 Input의 value가 $carrier.code를 직접 참조한다', () => {
            const inputs = findAllByName(allNodes, 'Input');
            const codeInput = inputs.find(
                (i: any) =>
                    i.props?.value?.includes('$carrier.code') &&
                    i.props?.className?.includes('font-mono')
            );
            expect(codeInput).toBeDefined();
            expect(codeInput.props?.value).toBe('{{$carrier.code ?? \'\'}}');
        });

        it('코드 Input 변경 시 .map() 패턴 setState로 carriers 배열을 수정한다', () => {
            const inputs = findAllByName(allNodes, 'Input');
            const codeInput = inputs.find(
                (i: any) =>
                    i.props?.value?.includes('$carrier.code') &&
                    i.props?.className?.includes('font-mono')
            );
            const action = codeInput?.actions?.find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(action).toBeDefined();
            expect(action.params?.['form.shipping.carriers']).toContain('.map(');
            expect(action.params?.['form.shipping.carriers']).toContain('$carrier._idx');
            expect(action.params?.['form.shipping.carriers']).toContain('code: $event.target.value');
            expect(action.params?.hasChanges).toBe(true);
        });

        it('코드 Input에 conditions 핸들러가 아닌 setState 핸들러를 사용한다', () => {
            const inputs = findAllByName(allNodes, 'Input');
            const codeInput = inputs.find(
                (i: any) =>
                    i.props?.value?.includes('$carrier.code') &&
                    i.props?.className?.includes('font-mono')
            );
            const conditionsAction = codeInput?.actions?.find(
                (a: any) => a.handler === 'conditions'
            );
            expect(conditionsAction).toBeUndefined();
        });
    });

    describe('이름(name) 컬럼', () => {
        it('이름 필드가 MultilingualInput 컴포지트 컴포넌트이다 (compact 레이아웃)', () => {
            const composites = allNodes.filter(
                (n: any) => n.type === 'composite' && n.name === 'MultilingualInput'
            );
            const tableMultilingual = composites.find(
                (c: any) =>
                    c.props?.value?.includes('$carrier.name') &&
                    c.props?.layout === 'compact'
            );
            expect(tableMultilingual).toBeDefined();
        });

        it('이름 MultilingualInput 변경 시 .map() 패턴 setState로 carriers 배열을 수정한다', () => {
            const composites = allNodes.filter(
                (n: any) => n.type === 'composite' && n.name === 'MultilingualInput'
            );
            const tableMultilingual = composites.find(
                (c: any) =>
                    c.props?.value?.includes('$carrier.name') &&
                    c.props?.layout === 'compact'
            );
            const action = tableMultilingual?.actions?.find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(action).toBeDefined();
            expect(action.params?.['form.shipping.carriers']).toContain('.map(');
            expect(action.params?.['form.shipping.carriers']).toContain('name: $event.target.value');
            expect(action.params?.hasChanges).toBe(true);
        });
    });

    describe('유형(type) 컬럼', () => {
        it('유형 Select의 value가 $carrier.type을 직접 참조한다', () => {
            const selects = findAllByName(allNodes, 'Select');
            const typeSelect = selects.find(
                (s: any) =>
                    s.props?.value?.includes('$carrier.type') &&
                    !s.props?.value?.includes('carrierForm')
            );
            expect(typeSelect).toBeDefined();
        });

        it('유형 Select에 domestic/international 옵션이 있다', () => {
            const selects = findAllByName(allNodes, 'Select');
            const typeSelect = selects.find(
                (s: any) =>
                    s.props?.value?.includes('$carrier.type') &&
                    !s.props?.value?.includes('carrierForm')
            );
            const values = typeSelect?.props?.options?.map((o: any) => o.value);
            expect(values).toContain('domestic');
            expect(values).toContain('international');
        });

        it('유형 Select 변경 시 .map() 패턴 setState로 carriers 배열을 수정한다', () => {
            const selects = findAllByName(allNodes, 'Select');
            const typeSelect = selects.find(
                (s: any) =>
                    s.props?.value?.includes('$carrier.type') &&
                    !s.props?.value?.includes('carrierForm')
            );
            const action = typeSelect?.actions?.find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(action).toBeDefined();
            expect(action.params?.['form.shipping.carriers']).toContain('.map(');
            expect(action.params?.['form.shipping.carriers']).toContain('type: $event.target.value');
            expect(action.params?.hasChanges).toBe(true);
        });
    });

    describe('추적 URL(tracking_url) 컬럼', () => {
        it('추적 URL Input의 value가 $carrier.tracking_url을 직접 참조한다', () => {
            const inputs = findAllByName(allNodes, 'Input');
            const trackingInput = inputs.find(
                (i: any) =>
                    i.props?.value?.includes('$carrier.tracking_url') &&
                    !i.props?.value?.includes('carrierForm')
            );
            expect(trackingInput).toBeDefined();
        });

        it('추적 URL Input 변경 시 .map() 패턴 setState로 carriers 배열을 수정한다', () => {
            const inputs = findAllByName(allNodes, 'Input');
            const trackingInput = inputs.find(
                (i: any) =>
                    i.props?.value?.includes('$carrier.tracking_url') &&
                    !i.props?.value?.includes('carrierForm')
            );
            const action = trackingInput?.actions?.find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(action).toBeDefined();
            expect(action.params?.['form.shipping.carriers']).toContain('.map(');
            expect(action.params?.['form.shipping.carriers']).toContain('tracking_url: $event.target.value');
            expect(action.params?.hasChanges).toBe(true);
        });
    });

    describe('활성/비활성(is_active) 컬럼', () => {
        it('Toggle의 checked가 $carrier.is_active를 직접 참조한다', () => {
            const toggles = findAllByName(allNodes, 'Toggle');
            const activeToggle = toggles.find(
                (t: any) => t.props?.checked?.includes('$carrier.is_active')
            );
            expect(activeToggle).toBeDefined();
        });

        it('Toggle에 size="sm"이 적용되어 있다', () => {
            const toggles = findAllByName(allNodes, 'Toggle');
            const activeToggle = toggles.find(
                (t: any) => t.props?.checked?.includes('$carrier.is_active')
            );
            expect(activeToggle?.props?.size).toBe('sm');
        });

        it('Toggle 변경 시 .map() 패턴 setState로 is_active를 토글한다 (apiCall 아님)', () => {
            const toggles = findAllByName(allNodes, 'Toggle');
            const activeToggle = toggles.find(
                (t: any) => t.props?.checked?.includes('$carrier.is_active')
            );
            const action = activeToggle?.actions?.find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(action).toBeDefined();
            expect(action.params?.['form.shipping.carriers']).toContain('.map(');
            expect(action.params?.['form.shipping.carriers']).toContain('is_active');
            expect(action.params?.hasChanges).toBe(true);
            // apiCall이 아닌 setState 사용
            const apiAction = activeToggle?.actions?.find(
                (a: any) => a.handler === 'apiCall'
            );
            expect(apiAction).toBeUndefined();
        });
    });
});

// ══════════════════════════════════════════════════════════
// 9. 테이블 행 Validation 에러 표시
// ══════════════════════════════════════════════════════════
describe('테이블 행 Validation 에러 표시', () => {
    const allNodes = flattenAll(carrierSection);

    it('코드 Input에 _local.errors 기반 조건부 input-error 클래스가 적용된다', () => {
        const inputs = findAllByName(allNodes, 'Input');
        const codeInput = inputs.find(
            (i: any) =>
                i.props?.value?.includes('$carrier.code') &&
                i.props?.className?.includes('font-mono')
        );
        expect(codeInput?.props?.className).toContain('_local.errors');
        expect(codeInput?.props?.className).toContain('shipping.carriers');
        expect(codeInput?.props?.className).toContain('input-error');
    });

    it('코드 에러 Span이 _local.errors 기반으로 표시된다', () => {
        const spans = findAllByName(allNodes, 'Span');
        const errorSpan = spans.find(
            (s: any) =>
                s.if?.includes('_local.errors') &&
                s.if?.includes('shipping.carriers') &&
                s.if?.includes('.code')
        );
        expect(errorSpan).toBeDefined();
        expect(errorSpan.props?.className).toContain('text-red-500');
        expect(errorSpan.props?.className).toContain('text-xs');
    });

    it('이름 MultilingualInput에 _local.errors 기반 error prop이 설정된다', () => {
        const composites = allNodes.filter(
            (n: any) => n.type === 'composite' && n.name === 'MultilingualInput'
        );
        const tableMultilingual = composites.find(
            (c: any) =>
                c.props?.value?.includes('$carrier.name') &&
                c.props?.layout === 'compact'
        );
        expect(tableMultilingual?.props?.error).toContain('_local.errors');
        expect(tableMultilingual?.props?.error).toContain('shipping.carriers');
        expect(tableMultilingual?.props?.error).toContain('name');
    });

    it('추적 URL Input에 _local.errors 기반 조건부 input-error 클래스가 적용된다', () => {
        const inputs = findAllByName(allNodes, 'Input');
        const trackingInput = inputs.find(
            (i: any) =>
                i.props?.value?.includes('$carrier.tracking_url') &&
                !i.props?.value?.includes('carrierForm')
        );
        expect(trackingInput?.props?.className).toContain('_local.errors');
        expect(trackingInput?.props?.className).toContain('input-error');
    });

    it('추적 URL 에러 Span이 _local.errors 기반으로 표시된다', () => {
        const spans = findAllByName(allNodes, 'Span');
        const errorSpan = spans.find(
            (s: any) =>
                s.if?.includes('_local.errors') &&
                s.if?.includes('tracking_url')
        );
        expect(errorSpan).toBeDefined();
        expect(errorSpan.props?.className).toContain('text-red-500');
    });
});

// ══════════════════════════════════════════════════════════
// 10. 삭제 버튼 — .filter() 패턴
// ══════════════════════════════════════════════════════════
describe('택배사 삭제 버튼', () => {
    const allNodes = flattenAll(carrierSection);

    it('삭제 아이콘(fa-trash) 버튼이 존재한다 (if 조건 없이 항상 표시)', () => {
        const deleteBtn = findButtonWithIcon(allNodes, 'fa-trash');
        expect(deleteBtn).toBeDefined();
        expect(deleteBtn.if).toBeUndefined();
    });

    it('삭제 버튼이 setState 핸들러를 사용한다 (apiCall 아님)', () => {
        const deleteBtn = findButtonWithIcon(allNodes, 'fa-trash');
        const action = deleteBtn.actions?.find(
            (a: any) => a.type === 'click' && a.handler === 'setState'
        );
        expect(action).toBeDefined();
        // apiCall이 아님
        const apiAction = deleteBtn.actions?.find(
            (a: any) => a.handler === 'apiCall'
        );
        expect(apiAction).toBeUndefined();
    });

    it('삭제 버튼에 confirm 속성이 있다', () => {
        const deleteBtn = findButtonWithIcon(allNodes, 'fa-trash');
        const action = deleteBtn.actions?.find(
            (a: any) => a.handler === 'setState'
        );
        expect(action?.confirm).toContain(
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.confirm_delete'
        );
    });

    it('삭제 시 .filter() 패턴으로 carriers 배열에서 해당 항목을 제거한다', () => {
        const deleteBtn = findButtonWithIcon(allNodes, 'fa-trash');
        const action = deleteBtn.actions?.find(
            (a: any) => a.handler === 'setState'
        );
        expect(action.params?.['form.shipping.carriers']).toContain('.filter(');
        expect(action.params?.['form.shipping.carriers']).toContain('$carrier._idx');
        expect(action.params?.hasChanges).toBe(true);
    });
});

// ══════════════════════════════════════════════════════════
// 11. Per-row 저장/취소 버튼이 없음을 확인
// ══════════════════════════════════════════════════════════
describe('Per-row 저장/취소 버튼 제거 확인', () => {
    const allNodes = flattenAll(carrierSection);

    it('fa-check 아이콘 버튼이 존재하지 않는다 (per-row 저장 제거)', () => {
        const saveBtn = findButtonWithIcon(allNodes, 'fa-check');
        expect(saveBtn).toBeUndefined();
    });

    it('fa-xmark 아이콘 버튼이 존재하지 않는다 (per-row 취소 제거)', () => {
        const cancelBtn = findButtonWithIcon(allNodes, 'fa-xmark');
        expect(cancelBtn).toBeUndefined();
    });

    it('editingCarrierId 참조가 JSON에 존재하지 않는다', () => {
        const jsonStr = JSON.stringify(carrierSection);
        expect(jsonStr).not.toContain('editingCarrierId');
    });

    it('editingCarrierForm 참조가 JSON에 존재하지 않는다', () => {
        const jsonStr = JSON.stringify(carrierSection);
        expect(jsonStr).not.toContain('editingCarrierForm');
    });

    it('apiCall 핸들러가 JSON에 존재하지 않는다 (모든 CRUD를 로컬 상태로 관리)', () => {
        const jsonStr = JSON.stringify(carrierSection);
        expect(jsonStr).not.toContain('"apiCall"');
    });

    it('refetchDataSource 핸들러가 JSON에 존재하지 않는다', () => {
        const jsonStr = JSON.stringify(carrierSection);
        expect(jsonStr).not.toContain('refetchDataSource');
    });
});

// ══════════════════════════════════════════════════════════
// 12. 다국어 키 검증
// ══════════════════════════════════════════════════════════
describe('다국어 키', () => {
    it('모든 i18n 키가 sirsoft-ecommerce 또는 common 네임스페이스를 사용한다', () => {
        const keys = collectI18nKeys(carrierSection);
        const moduleKeys = keys.filter((k) => k.startsWith('$t:sirsoft-ecommerce.'));
        const commonKeys = keys.filter((k) => k.startsWith('$t:common.'));
        expect(moduleKeys.length + commonKeys.length).toBe(keys.length);
    });

    it('필수 다국어 키가 존재한다', () => {
        const keys = collectI18nKeys(carrierSection);
        const requiredKeys = [
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.section_title',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.add_button',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.empty',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.columns.code',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.columns.name',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.columns.type',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.columns.tracking_url',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.columns.is_active',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.columns.actions',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.form.code',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.form.code_placeholder',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.form.name',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.form.name_ko_placeholder',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.form.type',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.form.type_domestic',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.form.type_international',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.form.tracking_url',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.form.tracking_url_placeholder',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.form.tracking_url_help',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.form.cancel',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.confirm_delete',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.validation.code_required',
            '$t:sirsoft-ecommerce.admin.settings.shipping.carriers.validation.name_required',
        ];
        for (const key of requiredKeys) {
            expect(keys).toContain(key);
        }
    });
});

// ══════════════════════════════════════════════════════════
// 13. 다크모드 검증
// ══════════════════════════════════════════════════════════
describe('다크모드', () => {
    it('_shipping_carrier_section.json에 dark: 클래스가 사용된다', () => {
        const json = JSON.stringify(carrierSection);
        expect(json).toContain('dark:');
    });

    it('추가 폼 영역에 다크모드 클래스가 적용되어 있다', () => {
        const allNodes = flattenAll(carrierSection);
        const formContainer = allNodes.find(
            (n: any) => n.if?.includes('_local.isAddingCarrier') && !n.if?.includes('!')
        );
        expect(formContainer?.props?.className).toContain('dark:');
    });

    it('itemTemplate에 다크모드 border 클래스가 적용되어 있다', () => {
        const allNodes = flattenAll(carrierSection);
        const tbodies = allNodes.filter((n: any) => n.name === 'Tbody');
        const sortableTbody = tbodies.find((tb: any) => tb.sortable);
        expect(sortableTbody?.itemTemplate?.props?.className).toContain('dark:');
    });
});

// ══════════════════════════════════════════════════════════
// 14. 입력 필드 스타일 검증
// ══════════════════════════════════════════════════════════
describe('입력 필드 스타일', () => {
    const allNodes = flattenAll(carrierSection);

    it('추가 폼 Input에 input 기본 클래스와 w-full 클래스가 적용되어 있다', () => {
        const inputs = findAllByName(allNodes, 'Input');
        const addFormInputs = inputs.filter(
            (i: any) => i.props?.value?.includes('carrierForm?.')
        );
        for (const input of addFormInputs) {
            expect(input.props?.className).toContain('input');
            expect(input.props?.className).toContain('w-full');
        }
    });

    it('테이블 행 Input에 input 기본 클래스와 w-full 클래스가 적용되어 있다', () => {
        const inputs = findAllByName(allNodes, 'Input');
        const tableInputs = inputs.filter(
            (i: any) =>
                (i.props?.value?.includes('$carrier.code') ||
                 i.props?.value?.includes('$carrier.tracking_url')) &&
                !i.props?.value?.includes('carrierForm')
        );
        expect(tableInputs.length).toBeGreaterThan(0);
        for (const input of tableInputs) {
            expect(input.props?.className).toContain('input');
            expect(input.props?.className).toContain('w-full');
        }
    });

    it('추가 폼 Select에 w-full 클래스가 적용되어 있다', () => {
        const selects = findAllByName(allNodes, 'Select');
        const addTypeSelect = selects.find(
            (s: any) => s.props?.value?.includes('carrierForm?.type')
        );
        expect(addTypeSelect?.props?.className).toContain('w-full');
    });

    it('테이블 행 Select에 w-full 클래스가 적용되어 있다', () => {
        const selects = findAllByName(allNodes, 'Select');
        const tableTypeSelect = selects.find(
            (s: any) =>
                s.props?.value?.includes('$carrier.type') &&
                !s.props?.value?.includes('carrierForm')
        );
        expect(tableTypeSelect?.props?.className).toContain('w-full');
    });

    it('추가/취소 버튼에 className(Tailwind)이 적용되어 있다 (variant가 아닌 className)', () => {
        // 추가 버튼
        const headerAddBtn = findAllByName(allNodes, 'Button').find(
            (b: any) => b.if?.includes('!_local.isAddingCarrier')
        );
        expect(headerAddBtn?.props?.className).toContain('rounded-lg');
        expect(headerAddBtn?.props?.variant).toBeUndefined();
        // 취소 버튼 (추가 폼)
        const cancelBtn = findButtonByChildText(allNodes, 'form.cancel');
        expect(cancelBtn?.props?.className).toContain('rounded-lg');
        expect(cancelBtn?.props?.variant).toBeUndefined();
    });

    it('Input에 size prop이 사용되지 않는다 (지원하지 않는 prop)', () => {
        const inputs = findAllByName(allNodes, 'Input');
        for (const input of inputs) {
            expect(input.props?.size).toBeUndefined();
        }
    });
});

// ══════════════════════════════════════════════════════════
// 15. 도서산간 배송비 입력필드 (size prop 없음 검증)
// ══════════════════════════════════════════════════════════
describe('도서산간 배송비 입력필드 (_tab_shipping.json)', () => {
    const allTabNodes = flattenAll(tabShipping);

    // 환경설정 탭의 전역 도서산간 배송비 (remote_area_extra_fee/island_extra_fee)
    // 가 제거되고 배송정책 단위 surcharge 로 이전됨 (shippingPolicySettingsTab 참조)
    it('산간지역 추가배송비 Input 이 환경설정 탭에서 제거되었다', () => {
        const inputs = findAllByName(allTabNodes, 'Input');
        const remoteInput = inputs.find(
            (i: any) => i.props?.name === 'shipping.remote_area_extra_fee',
        );
        expect(remoteInput).toBeUndefined();
    });

    it('도서지역 추가배송비 Input 이 환경설정 탭에서 제거되었다', () => {
        const inputs = findAllByName(allTabNodes, 'Input');
        const islandInput = inputs.find(
            (i: any) => i.props?.name === 'shipping.island_extra_fee',
        );
        expect(islandInput).toBeUndefined();
    });
});

// ══════════════════════════════════════════════════════════
// 16. responsive 속성 검증 (테이블 → 카드 전환)
// ══════════════════════════════════════════════════════════
describe('택배사 테이블 → 카드 responsive 전환', () => {
    const allNodes = flattenAll(carrierSection);

    it('테이블 wrapper에 id="carrier_list"가 존재한다', () => {
        const wrapper = allNodes.find((n: any) => n.id === 'carrier_list');
        expect(wrapper).toBeDefined();
    });

    it('carrier_list에 overflow-x-clip 사용 (Select 드롭다운 가려짐 방지)', () => {
        const wrapper = allNodes.find((n: any) => n.id === 'carrier_list');
        expect(wrapper?.props?.className).toContain('overflow-x-clip');
        expect(wrapper?.props?.className).not.toContain('overflow-x-auto');
    });

    it('carrier_list에 responsive.portable이 존재한다', () => {
        const wrapper = allNodes.find((n: any) => n.id === 'carrier_list');
        expect(wrapper?.responsive?.portable).toBeDefined();
    });

    it('responsive.portable에서 overflow-x-auto가 제거된다 (빈 className)', () => {
        const wrapper = allNodes.find((n: any) => n.id === 'carrier_list');
        expect(wrapper?.responsive?.portable?.props?.className).toBe('');
    });

    it('responsive.portable children에 _shipping_carrier_cards.json partial이 참조된다', () => {
        const wrapper = allNodes.find((n: any) => n.id === 'carrier_list');
        const partial = wrapper?.responsive?.portable?.children?.find(
            (c: any) => c.partial?.includes('_shipping_carrier_cards.json')
        );
        expect(partial).toBeDefined();
    });

    it('기존 테이블 children이 그대로 유지된다 (Table 컴포넌트 존재)', () => {
        const wrapper = allNodes.find((n: any) => n.id === 'carrier_list');
        const wrapperNodes = flattenAll(wrapper);
        const table = wrapperNodes.find((n: any) => n.name === 'Table');
        expect(table).toBeDefined();
    });
});

// ══════════════════════════════════════════════════════════
// 17. 카드 뷰 루트 구조 (_shipping_carrier_cards.json)
// ══════════════════════════════════════════════════════════
describe('택배사 카드 뷰 루트 구조', () => {
    it('meta.is_partial이 true이다', () => {
        expect((carrierCards as any).meta?.is_partial).toBe(true);
    });

    it('루트에 space-y-3 클래스가 적용되어 있다', () => {
        expect(carrierCards.props?.className).toContain('space-y-3');
    });

    it('카드 iteration이 테이블과 동일한 source를 사용한다', () => {
        const allNodes = flattenAll(carrierCards);
        const iterCard = allNodes.find((n: any) => n.iteration);
        expect(iterCard).toBeDefined();
        expect(iterCard.iteration.source).toContain('_local.form?.shipping?.carriers');
        expect(iterCard.iteration.source).toContain('.map(');
        expect(iterCard.iteration.source).toContain('_idx');
        expect(iterCard.iteration.item_var).toBe('$carrier');
    });

    it('카드에 excel-card 클래스가 적용되어 있다', () => {
        const allNodes = flattenAll(carrierCards);
        const iterCard = allNodes.find((n: any) => n.iteration);
        expect(iterCard.props?.className).toContain('excel-card');
    });
});

// ══════════════════════════════════════════════════════════
// 18. 카드 헤더
// ══════════════════════════════════════════════════════════
describe('택배사 카드 헤더', () => {
    const allCardNodes = flattenAll(carrierCards);

    it('excel-card-header가 존재한다', () => {
        const header = allCardNodes.find(
            (n: any) => n.props?.className?.includes('excel-card-header')
        );
        expect(header).toBeDefined();
    });

    it('$carrier.code가 excel-card-title + font-mono 클래스로 표시된다', () => {
        const titleDiv = allCardNodes.find(
            (n: any) =>
                n.props?.className?.includes('excel-card-title') &&
                n.props?.className?.includes('font-mono')
        );
        expect(titleDiv).toBeDefined();
        expect(titleDiv.text).toContain('$carrier.code');
    });

    it('$localized($carrier.name)이 text-tertiary로 표시된다', () => {
        const nameDiv = allCardNodes.find(
            (n: any) =>
                n.props?.className?.includes('text-tertiary') &&
                n.text?.includes('$localized')
        );
        expect(nameDiv).toBeDefined();
        expect(nameDiv.text).toContain('$carrier.name');
    });

    it('Toggle(is_active, sm)이 헤더에 존재한다', () => {
        const toggles = findAllByName(allCardNodes, 'Toggle');
        const activeToggle = toggles.find(
            (t: any) => t.props?.checked?.includes('$carrier.is_active')
        );
        expect(activeToggle).toBeDefined();
        expect(activeToggle.props?.size).toBe('sm');
    });

    it('삭제 버튼(fa-trash)이 헤더에 존재한다', () => {
        const deleteBtn = findButtonWithIcon(allCardNodes, 'fa-trash');
        expect(deleteBtn).toBeDefined();
    });
});

// ══════════════════════════════════════════════════════════
// 19. 카드 바디 필드 — 테이블과 동일한 setState 핸들러
// ══════════════════════════════════════════════════════════
describe('택배사 카드 바디 필드', () => {
    const allCardNodes = flattenAll(carrierCards);

    describe('코드(code) 필드', () => {
        it('form-group + form-label 구조이다', () => {
            const labels = findAllByName(allCardNodes, 'Label');
            const codeLabel = labels.find(
                (l: any) => l.text?.includes('columns.code')
            );
            expect(codeLabel).toBeDefined();
            expect(codeLabel.props?.className).toContain('form-label');
        });

        it('코드 Input에 font-mono가 적용되어 있다', () => {
            const inputs = findAllByName(allCardNodes, 'Input');
            const codeInput = inputs.find(
                (i: any) =>
                    i.props?.className?.includes('font-mono') &&
                    i.props?.value?.includes('$carrier.code')
            );
            expect(codeInput).toBeDefined();
        });

        it('코드 Input 변경 시 테이블과 동일한 .map() 패턴 setState를 사용한다', () => {
            const inputs = findAllByName(allCardNodes, 'Input');
            const codeInput = inputs.find(
                (i: any) =>
                    i.props?.className?.includes('font-mono') &&
                    i.props?.value?.includes('$carrier.code')
            );
            const action = codeInput?.actions?.find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(action).toBeDefined();
            expect(action.params?.['form.shipping.carriers']).toContain('.map(');
            expect(action.params?.['form.shipping.carriers']).toContain('code: $event.target.value');
            expect(action.params?.hasChanges).toBe(true);
        });
    });

    describe('이름(name) 필드', () => {
        it('MultilingualInput (compact)이다', () => {
            const composites = allCardNodes.filter(
                (n: any) => n.type === 'composite' && n.name === 'MultilingualInput'
            );
            const nameInput = composites.find(
                (c: any) => c.props?.value?.includes('$carrier.name')
            );
            expect(nameInput).toBeDefined();
            expect(nameInput.props?.layout).toBe('compact');
        });

        it('이름 변경 시 테이블과 동일한 .map() 패턴 setState를 사용한다', () => {
            const composites = allCardNodes.filter(
                (n: any) => n.type === 'composite' && n.name === 'MultilingualInput'
            );
            const nameInput = composites.find(
                (c: any) => c.props?.value?.includes('$carrier.name')
            );
            const action = nameInput?.actions?.find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(action).toBeDefined();
            expect(action.params?.['form.shipping.carriers']).toContain('.map(');
            expect(action.params?.['form.shipping.carriers']).toContain('name: $event.target.value');
            expect(action.params?.hasChanges).toBe(true);
        });
    });

    describe('유형(type) 필드', () => {
        it('Select에 domestic/international 옵션이 있다', () => {
            const selects = findAllByName(allCardNodes, 'Select');
            const typeSelect = selects.find(
                (s: any) => s.props?.value?.includes('$carrier.type')
            );
            expect(typeSelect).toBeDefined();
            const values = typeSelect.props?.options?.map((o: any) => o.value);
            expect(values).toContain('domestic');
            expect(values).toContain('international');
        });

        it('유형 변경 시 테이블과 동일한 .map() 패턴 setState를 사용한다', () => {
            const selects = findAllByName(allCardNodes, 'Select');
            const typeSelect = selects.find(
                (s: any) => s.props?.value?.includes('$carrier.type')
            );
            const action = typeSelect?.actions?.find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(action).toBeDefined();
            expect(action.params?.['form.shipping.carriers']).toContain('.map(');
            expect(action.params?.['form.shipping.carriers']).toContain('type: $event.target.value');
            expect(action.params?.hasChanges).toBe(true);
        });
    });

    describe('추적 URL(tracking_url) 필드', () => {
        it('추적 URL Input이 존재한다', () => {
            const inputs = findAllByName(allCardNodes, 'Input');
            const trackingInput = inputs.find(
                (i: any) => i.props?.value?.includes('$carrier.tracking_url')
            );
            expect(trackingInput).toBeDefined();
        });

        it('추적 URL 변경 시 테이블과 동일한 .map() 패턴 setState를 사용한다', () => {
            const inputs = findAllByName(allCardNodes, 'Input');
            const trackingInput = inputs.find(
                (i: any) => i.props?.value?.includes('$carrier.tracking_url')
            );
            const action = trackingInput?.actions?.find(
                (a: any) => a.type === 'change' && a.handler === 'setState'
            );
            expect(action).toBeDefined();
            expect(action.params?.['form.shipping.carriers']).toContain('.map(');
            expect(action.params?.['form.shipping.carriers']).toContain('tracking_url: $event.target.value');
            expect(action.params?.hasChanges).toBe(true);
        });
    });
});

// ══════════════════════════════════════════════════════════
// 20. 카드 validation 에러
// ══════════════════════════════════════════════════════════
describe('택배사 카드 validation 에러', () => {
    const allCardNodes = flattenAll(carrierCards);

    it('코드 Input에 _local.errors 기반 조건부 input-error 클래스가 적용된다', () => {
        const inputs = findAllByName(allCardNodes, 'Input');
        const codeInput = inputs.find(
            (i: any) =>
                i.props?.className?.includes('font-mono') &&
                i.props?.value?.includes('$carrier.code')
        );
        expect(codeInput?.props?.className).toContain('_local.errors');
        expect(codeInput?.props?.className).toContain('input-error');
    });

    it('코드 에러 Span이 _local.errors 기반으로 표시된다', () => {
        const spans = findAllByName(allCardNodes, 'Span');
        const errorSpan = spans.find(
            (s: any) =>
                s.if?.includes('_local.errors') &&
                s.if?.includes('.code')
        );
        expect(errorSpan).toBeDefined();
        expect(errorSpan.props?.className).toContain('text-red-500');
        expect(errorSpan.props?.className).toContain('text-xs');
    });

    it('이름 MultilingualInput에 _local.errors 기반 error prop이 설정된다', () => {
        const composites = allCardNodes.filter(
            (n: any) => n.type === 'composite' && n.name === 'MultilingualInput'
        );
        const nameInput = composites.find(
            (c: any) => c.props?.value?.includes('$carrier.name')
        );
        expect(nameInput?.props?.error).toContain('_local.errors');
        expect(nameInput?.props?.error).toContain('name');
    });

    it('유형 Select에 _local.errors 기반 조건부 select-error 클래스가 적용된다', () => {
        const selects = findAllByName(allCardNodes, 'Select');
        const typeSelect = selects.find(
            (s: any) => s.props?.value?.includes('$carrier.type')
        );
        expect(typeSelect?.props?.className).toContain('_local.errors');
        expect(typeSelect?.props?.className).toContain('select-error');
    });

    it('추적 URL Input에 _local.errors 기반 조건부 input-error 클래스가 적용된다', () => {
        const inputs = findAllByName(allCardNodes, 'Input');
        const trackingInput = inputs.find(
            (i: any) => i.props?.value?.includes('$carrier.tracking_url')
        );
        expect(trackingInput?.props?.className).toContain('_local.errors');
        expect(trackingInput?.props?.className).toContain('input-error');
    });

    it('추적 URL 에러 Span이 _local.errors 기반으로 표시된다', () => {
        const spans = findAllByName(allCardNodes, 'Span');
        const errorSpan = spans.find(
            (s: any) =>
                s.if?.includes('_local.errors') &&
                s.if?.includes('tracking_url')
        );
        expect(errorSpan).toBeDefined();
        expect(errorSpan.props?.className).toContain('text-red-500');
    });
});

// ══════════════════════════════════════════════════════════
// 21. 카드 삭제/토글
// ══════════════════════════════════════════════════════════
describe('택배사 카드 삭제/토글', () => {
    const allCardNodes = flattenAll(carrierCards);

    it('삭제 버튼이 .filter() 패턴 setState를 사용한다', () => {
        const deleteBtn = findButtonWithIcon(allCardNodes, 'fa-trash');
        const action = deleteBtn?.actions?.find(
            (a: any) => a.handler === 'setState'
        );
        expect(action).toBeDefined();
        expect(action.params?.['form.shipping.carriers']).toContain('.filter(');
        expect(action.params?.['form.shipping.carriers']).toContain('$carrier._idx');
        expect(action.params?.hasChanges).toBe(true);
    });

    it('삭제 버튼에 confirm 속성이 있다', () => {
        const deleteBtn = findButtonWithIcon(allCardNodes, 'fa-trash');
        const action = deleteBtn?.actions?.find(
            (a: any) => a.handler === 'setState'
        );
        expect(action?.confirm).toContain('confirm_delete');
    });

    it('삭제 버튼에 type="button"이 명시되어 있다', () => {
        const deleteBtn = findButtonWithIcon(allCardNodes, 'fa-trash');
        expect(deleteBtn?.props?.type).toBe('button');
    });

    it('Toggle 변경 시 .map() 패턴 setState로 is_active를 토글한다', () => {
        const toggles = findAllByName(allCardNodes, 'Toggle');
        const activeToggle = toggles.find(
            (t: any) => t.props?.checked?.includes('$carrier.is_active')
        );
        const action = activeToggle?.actions?.find(
            (a: any) => a.handler === 'setState'
        );
        expect(action).toBeDefined();
        expect(action.params?.['form.shipping.carriers']).toContain('.map(');
        expect(action.params?.['form.shipping.carriers']).toContain('is_active');
        expect(action.params?.hasChanges).toBe(true);
    });

    it('Toggle/삭제에 apiCall이 사용되지 않는다 (로컬 상태만)', () => {
        const jsonStr = JSON.stringify(carrierCards);
        expect(jsonStr).not.toContain('"apiCall"');
    });
});

// ══════════════════════════════════════════════════════════
// 22. 카드 다국어/다크모드
// ══════════════════════════════════════════════════════════
describe('택배사 카드 다국어/다크모드', () => {
    it('모든 i18n 키가 sirsoft-ecommerce 또는 common 네임스페이스를 사용한다', () => {
        const keys = collectI18nKeys(carrierCards);
        const moduleKeys = keys.filter((k) => k.startsWith('$t:sirsoft-ecommerce.'));
        const commonKeys = keys.filter((k) => k.startsWith('$t:common.'));
        expect(moduleKeys.length + commonKeys.length).toBe(keys.length);
    });

    it('카드에 다크모드 지원 시맨틱 클래스(excel-card, form-label, text-tertiary)가 사용된다', () => {
        const json = JSON.stringify(carrierCards);
        expect(json).toContain('excel-card');
        expect(json).toContain('form-label');
        expect(json).toContain('text-tertiary');
    });

    it('에러 표시에 text-red-500 클래스가 사용된다', () => {
        const json = JSON.stringify(carrierCards);
        expect(json).toContain('text-red-500');
    });
});
