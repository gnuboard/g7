// e2e:allow 실제 관리자 템플릿 Select 를 엔진 렌더러로 그려 목록형(단추 + 목록 상자) 여부와 고른 값이 동작까지 닿는지 잰다 — 브라우저 축은 상품 옵션 과세 종류 Playwright(product-option-tax-status.spec.ts) 와 출시 전 화면 재실측이 덮는다
/**
 * @file adminSelectDropdownMode.test.tsx
 * @description 이커머스 관리자 선택칸이 브라우저 기본 선택상자가 아니라 관리자 템플릿의 목록형 드롭다운으로 그려진다
 *
 * 관리자 템플릿 Select 는 `props.options` 가 있으면 단추(`aria-haspopup=listbox`) + 목록 상자로, 없으면 브라우저 기본
 * `<select>` 로 그린다. 레이아웃이 `<select>` 바로 아래 `<option>` 을 적으면 options 가 비어 기본 선택상자가 됐다
 * (상품 옵션 표 「과세 종류」·「적립 단위」, 상품정보고시 템플릿, 주문 취소 사유, 개인결제 주문 찾기, 일괄 가격·재고 변경).
 *
 * 실물 Select 를 엔진 렌더러(createLayoutTest)로 그리고, 단추를 눌러 항목을 고르면 그 칸의 change 동작이 고른 값을 받는지
 * (`$event.target.value`) 와 단추 글자가 고른 값으로 바뀌는지(값 바인딩), 오류 테두리가 단추에 실제로 남는지를 본다.
 *
 * @scenario-manifest tests/scenarios/admin-select-dropdown-mode.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import { Select } from '../../../../../../../templates/_bundled/sirsoft-admin_basic/src/components/basic/Select';

import productOptions from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_product_options.json';
import productNotice from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_product_notice.json';
import cancelModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_cancel_order.json';
import orderSearchModal from '../../../layouts/admin/partials/admin_ecommerce_personal_payment_create/_modal_order_search.json';
import bulkPriceModal from '../../../layouts/admin/partials/admin_ecommerce_product_list/_modal_bulk_price.json';
import bulkStockModal from '../../../layouts/admin/partials/admin_ecommerce_product_list/_modal_bulk_stock.json';

type Node = Record<string, any>;

/**
 * 조건에 맞는 노드를 모두 모은다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @param acc 결과 누적
 * @returns 찾은 노드 목록
 */
function findAll(node: unknown, match: (n: Node) => boolean, acc: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((child) => findAll(child, match, acc));

        return acc;
    }
    if (!node || typeof node !== 'object') return acc;
    if (match(node as Node)) acc.push(node as Node);
    Object.values(node as Node).forEach((value) => findAll(value, match, acc));

    return acc;
}

const selectsOf = (root: unknown): Node[] => findAll(root, (n) => n.name === 'Select');
const actionsText = (n: Node): string => JSON.stringify(n.actions ?? []);

const taxSelects = selectsOf(productOptions).filter((n) => actionsText(n).includes('"field":"tax_status"'));
const mileageSelects = selectsOf(productOptions).filter((n) => actionsText(n).includes('"field":"mileage_type"'));
const noticeSelect = selectsOf(productNotice)[0];
const cancelReasonSelect = selectsOf(cancelModal).find((n) => actionsText(n).includes('cancelReason'));
const orderSearchSelects = selectsOf(orderSearchModal);
const bulkPriceSelects = selectsOf(bulkPriceModal);
const bulkStockSelects = selectsOf(bulkStockModal);

/** 행 반복(`opt`, `optIdx`)을 품은 상자 — 옵션 표·카드 칸은 이 반복 안에서만 값이 풀린다 */
const inOptionRows = (select: Node): Node => ({
    id: 'option_rows',
    type: 'basic',
    name: 'Div',
    iteration: { source: '{{_local.form.options ?? []}}', item_var: 'opt', index_var: 'optIdx' },
    children: [select],
});

const OPTION_ROWS = [
    { option_name: { ko: '내수용' }, tax_status: null, mileage_type: 'percent' },
    { option_name: { ko: '수출용' }, tax_status: 'zero_rated', mileage_type: 'fixed' },
];

let registry: MockComponentRegistry;
let calls: Record<string, any[]>;
let utils: ReturnType<typeof createLayoutTest> | null = null;

beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Select', Select as any);
    calls = {};
    // 모듈 핸들러는 시험 렌더에 실리지 않는다 — 받은 인자만 기록하는 대역을 운영과 같은 이름으로 둔다
    const original = ActionDispatcher.prototype.setGlobalStateUpdater;
    vi.spyOn(ActionDispatcher.prototype, 'setGlobalStateUpdater').mockImplementation(function (this: any, updater: any) {
        original.call(this, updater);
        ['updateFormOptionField', 'confirmSelectNoticeTemplate'].forEach((name) =>
            this.registerHandler(`sirsoft-ecommerce.${name}`, (action: any) => {
                (calls[name] ??= []).push(action.params);
            }),
        );
    });
});

afterEach(() => {
    utils?.cleanup();
    utils = null;
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

/**
 * 노드를 실물 Select 로 그린다.
 *
 * @param components 그릴 노드
 * @param options 초기 상태·데이터
 * @returns 그린 상자
 */
async function draw(components: Node[], options: Record<string, any> = {}): Promise<HTMLElement> {
    utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test/admin-ecommerce-select-dropdown', components } as any,
        { componentRegistry: registry as any, locale: 'ko', ...options },
    );
    const { container } = await utils.render();

    return container;
}

/** 목록형 단추들 */
const triggers = (container: HTMLElement): HTMLButtonElement[] =>
    Array.from(container.querySelectorAll<HTMLButtonElement>('button[aria-haspopup="listbox"]'));

/**
 * 단추를 눌러 글자가 맞는 항목을 고른다.
 *
 * @param trigger 목록형 단추
 * @param label 고를 항목 글자(정규식)
 */
async function choose(trigger: HTMLElement, label: RegExp): Promise<void> {
    fireEvent.click(trigger);
    const option = await waitFor(() => {
        const found = screen.getAllByRole('option').find((o) => label.test(o.textContent ?? ''));
        expect(found, `항목 ${label}`).toBeTruthy();

        return found!;
    });
    fireEvent.click(option);
}

/**
 * 노드가 목록형(options) 표기인지 — `<option>` 자식(브라우저 기본 선택상자)이 아니다.
 *
 * @param node Select 노드
 */
function expectOptionsNotation(node: Node | undefined): void {
    expect(node, 'Select 노드').toBeTruthy();
    expect(node!.props?.options, 'props.options').toBeDefined();
    expect(findAll(node!.children ?? [], (n) => n.name === 'Option' || n.name === 'Optgroup')).toHaveLength(0);
}

/**
 * 옵션 표·카드의 과세 종류 칸 — 목록형 · 행 값 표시 · 오류 테두리 · 고른 값 전달.
 *
 * @param select 과세 종류 Select 노드
 */
async function expectTaxSelect(select: Node): Promise<void> {
    expectOptionsNotation(select);
    const container = await draw([inOptionRows(select)], {
        initialState: { _local: { form: { tax_status: 'taxable', options: OPTION_ROWS }, errors: { 'options.0.tax_status': ['과세 종류를 고르세요'] } } },
    });
    expect(container.querySelector('select'), '브라우저 기본 선택상자').toBeNull();
    const [first, second] = triggers(container);
    expect(first.textContent).toContain('options.tax_status_inherit');
    expect(second.textContent).toContain('product.tax.zero_rated');
    // 오류 행만 붉은 테두리 — 기본 단추의 border-0 에 밀리지 않도록 단추 글자에 테두리를 직접 싣는다
    expect(first.className).toContain('border-red-500');
    expect(first.className).not.toContain('border-0');
    expect(second.className).not.toContain('border-red-500');

    await choose(first, /product\.tax\.tax_free$/);
    await waitFor(() => expect(calls.updateFormOptionField?.at(-1)).toMatchObject({ index: 0, field: 'tax_status', value: 'tax_free' }));
}

/**
 * 옵션 표·카드의 적립 단위 칸 — 목록형 · 행 값 표시 · 고른 값 전달.
 *
 * @param select 적립 단위 Select 노드
 */
async function expectMileageSelect(select: Node): Promise<void> {
    expectOptionsNotation(select);
    const container = await draw([inOptionRows(select)], { initialState: { _local: { form: { options: OPTION_ROWS } } } });
    expect(container.querySelector('select')).toBeNull();
    const [first, second] = triggers(container);
    expect(second.textContent).toContain('mileage_type.fixed');

    await choose(first, /mileage_type\.fixed$/);
    await waitFor(() => expect(calls.updateFormOptionField?.at(-1)).toMatchObject({ index: 0, field: 'mileage_type', value: 'fixed' }));
}

describe('이커머스 관리자 선택칸 — 목록형 드롭다운', () => {
    // @scenario screen=product_option_tax_status
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action, select_error_border_on_trigger
    it('상품 옵션 넓은 화면 표의 과세 종류는 목록형이고 고른 값이 그 행의 tax_status 로 가며, 오류 테두리가 단추에 남는다', async () => {
        expect(taxSelects).toHaveLength(2);
        await expectTaxSelect(taxSelects[0]);
    });

    // @scenario screen=product_option_tax_status
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action, select_error_border_on_trigger
    it('상품 옵션 좁은 화면 카드의 과세 종류도 같은 목록형이고 같은 동작을 한다', async () => {
        await expectTaxSelect(taxSelects[1]);
    });

    // @scenario screen=product_option_mileage_type
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action
    it('상품 옵션 넓은 화면 표의 적립 단위는 목록형이고 고른 값이 그 행의 mileage_type 으로 간다', async () => {
        expect(mileageSelects).toHaveLength(2);
        await expectMileageSelect(mileageSelects[0]);
    });

    // @scenario screen=product_option_mileage_type
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action
    it('상품 옵션 좁은 화면 카드의 적립 단위도 같은 목록형이고 같은 동작을 한다', async () => {
        await expectMileageSelect(mileageSelects[1]);
    });

    // @scenario screen=product_notice_template
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action
    it('상품정보고시 템플릿 선택은 목록형이고 고른 템플릿 번호가 확인 창 동작으로 간다', async () => {
        expectOptionsNotation(noticeSelect);
        const container = await draw([noticeSelect], {
            initialState: { _local: { ui: {} } },
            initialData: { notice_templates: { data: { data: [{ id: 3, name: { ko: '의류' } }, { id: 5, name: { ko: '식품' } }] } } },
        });
        expect(container.querySelector('select')).toBeNull();
        const [trigger] = triggers(container);
        expect(trigger.textContent).toContain('placeholders.notice_template');

        await choose(trigger, /^식품$/);
        await waitFor(() => expect(calls.confirmSelectNoticeTemplate?.at(-1)).toMatchObject({ templateId: 5 }));
    });

    // @scenario screen=order_cancel_reason
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action, select_error_border_on_trigger
    it('주문 취소 창의 취소 사유는 목록형이고 고르면 사유가 담기고 오류가 지워지며, 오류 테두리가 단추에 남는다', async () => {
        expectOptionsNotation(cancelReasonSelect);
        const container = await draw([cancelReasonSelect!], {
            initialState: { _local: { cancelReason: '', cancelValidationErrors: { reason: ['사유를 고르세요'] } } },
            initialData: { refundReasons: { data: { data: [{ code: 'change_of_mind', localized_name: '단순 변심' }, { code: 'defect', localized_name: '상품 불량' }] } } },
        });
        expect(container.querySelector('select')).toBeNull();
        const [trigger] = triggers(container);
        expect(trigger.textContent).toContain('cancel.reason_placeholder');
        expect(trigger.className).toContain('border-red-500');

        await choose(trigger, /^상품 불량$/);
        await waitFor(() => expect(utils!.getState()._local.cancelReason).toBe('defect'));
        expect(utils!.getState()._local.cancelValidationErrors).toBeNull();
    });

    // @scenario screen=personal_payment_order_search
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action
    it('개인결제 주문 찾기 창의 검색 대상·기간은 목록형이고 고른 값이 단추에 남는다', async () => {
        expect(orderSearchSelects).toHaveLength(2);
        orderSearchSelects.forEach(expectOptionsNotation);
        const container = await draw(orderSearchSelects, { initialState: { _local: {} } });
        expect(container.querySelector('select')).toBeNull();
        const [field, period] = triggers(container);
        expect(field.textContent).toContain('search_field_all');
        expect(period.textContent).toContain('order_date_all');

        await choose(field, /search_field_order_number$/);
        await waitFor(() => expect(field.textContent).toContain('search_field_order_number'));
        await choose(period, /order_date_last_30_days$/);
        await waitFor(() => expect(period.textContent).toContain('order_date_last_30_days'));
    });

    // @scenario screen=bulk_price
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action
    it('일괄 가격 변경 창의 방식·단위는 목록형이고 고른 값이 담겨 단추 글자가 바뀐다', async () => {
        expect(bulkPriceSelects).toHaveLength(2);
        bulkPriceSelects.forEach(expectOptionsNotation);
        const container = await draw(bulkPriceSelects, {
            initialState: {
                _local: {},
                _global: { modules: { 'sirsoft-ecommerce': { language_currency: { default_currency: 'KRW', currencies: [{ code: 'KRW', symbol: '₩', is_default: true }] } } } },
            },
        });
        expect(container.querySelector('select')).toBeNull();
        const [method, unit] = triggers(container);
        expect(method.textContent).toContain('bulk_price.method_increase');
        expect(unit.textContent).toContain('₩');

        await choose(method, /bulk_price\.method_fixed$/);
        await waitFor(() => expect(utils!.getState()._local.bulkPriceMethod).toBe('fixed'));
        expect(method.textContent).toContain('bulk_price.method_fixed');
        await choose(unit, /bulk_price\.unit_percent$/);
        await waitFor(() => expect(utils!.getState()._local.bulkPriceUnit).toBe('percent'));
    });

    // @scenario screen=bulk_stock
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action
    it('일괄 재고 변경 창의 방식은 목록형이고 고른 값이 담겨 단추 글자가 바뀐다', async () => {
        expect(bulkStockSelects).toHaveLength(1);
        expectOptionsNotation(bulkStockSelects[0]);
        const container = await draw(bulkStockSelects, { initialState: { _local: {} } });
        expect(container.querySelector('select')).toBeNull();
        const [method] = triggers(container);
        expect(method.textContent).toContain('bulk_stock.method_increase');

        await choose(method, /bulk_stock\.method_set$/);
        await waitFor(() => expect(utils!.getState()._local.bulkStockMethod).toBe('set'));
        expect(method.textContent).toContain('bulk_stock.method_set');
    });
});
