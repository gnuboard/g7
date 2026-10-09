/**
 * 주문 상세 레이아웃 렌더링 테스트
 *
 * createLayoutTest() 유틸리티를 사용한 실제 렌더링 기반 테스트입니다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 정의 ==========

const TestDiv: React.FC<{
    className?: string;
    children?: React.ReactNode;
    'data-testid'?: string;
    'data-section-id'?: string;
}> = ({ className, children, 'data-testid': testId, 'data-section-id': sectionId }) => (
    <div className={className} data-testid={testId} data-section-id={sectionId}>{children}</div>
);

const TestSpan: React.FC<{
    className?: string;
    children?: React.ReactNode;
    text?: string;
}> = ({ className, children, text }) => (
    <span className={className}>{children || text}</span>
);

const TestButton: React.FC<{
    type?: string;
    className?: string;
    disabled?: boolean;
    children?: React.ReactNode;
    onClick?: () => void;
    'data-testid'?: string;
}> = ({ type, className, disabled, children, onClick, 'data-testid': testId }) => (
    <button type={type as any} className={className} disabled={disabled} onClick={onClick} data-testid={testId}>
        {children}
    </button>
);

const TestInput: React.FC<{
    type?: string;
    placeholder?: string;
    value?: string;
    className?: string;
    readOnly?: boolean;
    name?: string;
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
    'data-testid'?: string;
}> = ({ type, placeholder, value, className, readOnly, name, onChange, 'data-testid': testId }) => (
    <input type={type} placeholder={placeholder} value={value} className={className}
        readOnly={readOnly} name={name} onChange={onChange} data-testid={testId} />
);

const TestTextarea: React.FC<{
    placeholder?: string;
    value?: string;
    className?: string;
    name?: string;
    rows?: number;
    'data-testid'?: string;
}> = ({ placeholder, value, className, name, rows, 'data-testid': testId }) => (
    <textarea placeholder={placeholder} value={value} className={className}
        name={name} rows={rows} data-testid={testId} />
);

const TestH1: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h1 className={className}>{children || text}</h1>;

const TestH2: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h2 className={className}>{children || text}</h2>;

const TestH3: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h3 className={className}>{children || text}</h3>;

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <p className={className}>{children || text}</p>;

const TestLabel: React.FC<{ className?: string; children?: React.ReactNode; text?: string; htmlFor?: string }> =
    ({ className, children, text, htmlFor }) => <label className={className} htmlFor={htmlFor}>{children || text}</label>;

const TestA: React.FC<{ className?: string; href?: string; target?: string; children?: React.ReactNode; text?: string }> =
    ({ className, href, target, children, text }) => <a className={className} href={href} target={target}>{children || text}</a>;

const TestIcon: React.FC<{ name?: string; className?: string }> =
    ({ name, className }) => <i className={`icon-${name} ${className || ''}`} data-icon={name} />;

const TestFragment: React.FC<{ children?: React.ReactNode }> =
    ({ children }) => <>{children}</>;

// Composite 컴포넌트
const TestSelect: React.FC<{
    value?: string;
    className?: string;
    children?: React.ReactNode;
    options?: any[];
    'data-testid'?: string;
}> = ({ value, className, children, 'data-testid': testId }) => (
    <select value={value} className={className} data-testid={testId}>{children}</select>
);

const TestOption: React.FC<{ value?: string; children?: React.ReactNode }> =
    ({ value, children }) => <option value={value}>{children}</option>;

const TestBadge: React.FC<{ variant?: string; children?: React.ReactNode; text?: string; className?: string }> =
    ({ variant, children, text, className }) => (
        <span className={className} data-variant={variant} data-testid="badge">{children || text}</span>
    );

const TestCheckbox: React.FC<{ checked?: boolean; onChange?: () => void; className?: string }> =
    ({ checked, onChange, className }) => (
        <input type="checkbox" checked={checked} onChange={onChange} className={className} />
    );

const TestModal: React.FC<{
    id?: string;
    isOpen?: boolean;
    title?: string;
    children?: React.ReactNode;
}> = ({ id, isOpen, title, children }) => (
    isOpen ? (
        <div data-testid={`modal-${id}`} role="dialog">
            <h2>{title}</h2>
            {children}
        </div>
    ) : null
);

const TestTabNavigationScroll: React.FC<{
    tabs?: any[];
    activeTab?: string;
    children?: React.ReactNode;
}> = ({ tabs, activeTab, children }) => (
    <div data-testid="tab-navigation" data-active={activeTab}>
        {tabs?.map((tab: any) => (
            <button key={tab.id} data-tab={tab.id}>{tab.label}</button>
        ))}
        {children}
    </div>
);

const TestActionMenu: React.FC<{
    items?: any[];
    children?: React.ReactNode;
    'data-testid'?: string;
}> = ({ items, children, 'data-testid': testId }) => (
    <div data-testid={testId || 'action-menu'}>
        {items?.map((item: any, i: number) => (
            <button key={i} data-action={item.id}>{item.label}</button>
        ))}
        {children}
    </div>
);

const TestForm: React.FC<{ dataKey?: string; children?: React.ReactNode }> =
    ({ dataKey, children }) => <form data-testid={`form-${dataKey}`}>{children}</form>;

const TestImg: React.FC<{ src?: string; alt?: string; className?: string }> =
    ({ src, alt, className }) => <img src={src} alt={alt} className={className} />

const TestDataGrid: React.FC<{
    data?: any[];
    columns?: any[];
    selectable?: boolean;
    selectedIds?: any[];
    pagination?: boolean;
    footerCells?: any[];
    footerClassName?: string;
    footerCardChildren?: any[];
    children?: React.ReactNode;
    'data-testid'?: string;
}> = ({ data, columns, selectable, selectedIds, 'data-testid': testId, children }) => (
    <div data-testid={testId || 'datagrid'} data-rows={data?.length || 0} data-selectable={selectable}>
        {columns?.map((col: any) => (
            <span key={col.field} data-field={col.field}>{col.header}</span>
        ))}
        {children}
    </div>
);

const TestHr: React.FC<{ className?: string }> =
    ({ className }) => <hr className={className} />;

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();

    (registry as any).registry = {
        // Basic 컴포넌트
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
        Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
        Textarea: { component: TestTextarea, metadata: { name: 'Textarea', type: 'basic' } },
        H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
        H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
        H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
        P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
        Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
        A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
        Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
        Img: { component: TestImg, metadata: { name: 'Img', type: 'basic' } },
        Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
        Option: { component: TestOption, metadata: { name: 'Option', type: 'basic' } },

        // Composite 컴포넌트
        Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
        Badge: { component: TestBadge, metadata: { name: 'Badge', type: 'composite' } },
        Checkbox: { component: TestCheckbox, metadata: { name: 'Checkbox', type: 'composite' } },
        Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
        TabNavigationScroll: { component: TestTabNavigationScroll, metadata: { name: 'TabNavigationScroll', type: 'composite' } },
        ActionMenu: { component: TestActionMenu, metadata: { name: 'ActionMenu', type: 'composite' } },
        Form: { component: TestForm, metadata: { name: 'Form', type: 'composite' } },
        DataGrid: { component: TestDataGrid, metadata: { name: 'DataGrid', type: 'composite' } },
        Hr: { component: TestHr, metadata: { name: 'Hr', type: 'basic' } },
    };

    return registry;
}

// ========== 레이아웃 JSON 임포트 ==========

import mainLayout from '../../../layouts/admin/admin_ecommerce_order_detail.json';
import orderInfoPartial from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json';
import paymentInfoPartial from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_payment_info.json';
import activityLogPartial from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_activity_log.json';
import batchChangeModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_batch_change_confirm.json';
import smsModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_send_sms.json';
import emailModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_send_email.json';
import resetGuestPasswordModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_reset_guest_password.json';
import adminClaimRequestModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_admin_claim_request.json';

// ========== Mock 주문 데이터 ==========

const mockOrderData = {
    id: 1,
    order_number: 'ORD-2025-001234',
    ordered_at_formatted: '2025-01-15 14:30:25',
    order_status: 'payment_complete',
    order_status_label: '결제완료',
    order_status_variant: 'info',
    user_id: 10,
    orderer_name: '홍길동',
    orderer_phone: '010-1234-5678',
    orderer_email: 'hong@example.com',
    recipient_name: '홍길동',
    recipient_phone: '010-1234-5678',
    recipient_tel: '02-1234-5678',
    recipient_zipcode: '12345',
    recipient_address: '서울특별시 강남구',
    recipient_detail_address: '역삼동 123-45',
    delivery_memo: '부재시 경비실',
    admin_memo: '테스트 메모',
    total_amount: 150000,
    shipping_amount: 3000,
    total_payment_amount: 153000,
    tax_amount: 13909,
    vat_amount: 1391,
    used_points_amount: 0,
    used_deposit_amount: 0,
    options: [
        {
            id: 1,
            product_name: '테스트 상품 A',
            option_name: '빨강/L',
            sku: 'SKU-001',
            thumbnail_url: '/img/test.jpg',
            unit_price: 30000,
            unit_price_formatted: '30,000원',
            quantity: 3,
            subtotal_price: 90000,
            subtotal_price_formatted: '90,000원',
            subtotal_discount_amount: 0,
            product_coupon_discount_amount: 0,
            code_discount_amount: 0,
            subtotal_earned_points_amount: 900,
            option_status: 'payment_complete',
            option_status_label: '결제완료',
            option_status_variant: 'info',
        },
        {
            id: 2,
            product_name: '테스트 상품 B',
            option_name: '파랑/M',
            sku: 'SKU-002',
            thumbnail_url: '/img/test2.jpg',
            unit_price: 20000,
            unit_price_formatted: '20,000원',
            quantity: 2,
            subtotal_price: 40000,
            subtotal_price_formatted: '40,000원',
            subtotal_discount_amount: 5000,
            product_coupon_discount_amount: 3000,
            code_discount_amount: 2000,
            subtotal_earned_points_amount: 400,
            option_status: 'payment_complete',
            option_status_label: '결제완료',
            option_status_variant: 'info',
        },
    ],
    payments: [
        {
            id: 1,
            payment_number: 'PAY-2025-001234',
            payment_type: 'original',
            payment_type_label: '원주문',
            payment_status: 'paid',
            payment_status_label: '결제완료',
            payment_status_variant: 'success',
            payment_method: 'credit_card',
            payment_method_label: '신용카드',
            requested_at_formatted: '2025-01-15 14:30:25',
            paid_at_formatted: '2025-01-15 14:31:20',
            order_amount: 130000,
            shipping_amount: 3000,
            discount_amount: 5000,
            total_payment_amount: 128000,
        },
    ],
};

// ========== 메인 레이아웃 테스트 ==========

describe('admin_ecommerce_order_detail.json (메인 레이아웃)', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;
    let registry: ComponentRegistry;

    beforeEach(() => {
        registry = setupTestRegistry();

        testUtils = createLayoutTest(mainLayout as any, {
            auth: {
                isAuthenticated: true,
                user: { id: 1, name: 'Admin', role: 'super_admin' },
                authType: 'admin',
            },
            routeParams: { id: '1' },
            translations: {
                'sirsoft-ecommerce': {
                    admin: {
                        order: {
                            detail: {
                                title: '주문 상세',
                                description: '주문 상세 정보를 확인합니다.',
                                order_number: '주문번호',
                                order_date: '주문일시',
                            },
                        },
                    },
                    common: {
                        list: '목록',
                    },
                },
            },
            locale: 'ko',
            componentRegistry: registry,
        });
    });

    afterEach(() => {
        testUtils.cleanup();
    });

    describe('레이아웃 구조 검증', () => {
        /**
         * @scenario surface=shell
         * @effects shell_layout_metadata_matches_name_and_version
         */
        it('레이아웃 정보가 올바르게 로드된다', () => {
            const info = testUtils.getLayoutInfo();
            expect(info.name).toBe('admin_ecommerce_order_detail');
            expect(info.version).toBe('1.0.0');
        });

        /**
         * @scenario surface=shell
         * @effects shell_declares_order_logs_and_carriers_data_sources
         */
        it('order 데이터소스가 정의되어 있다', () => {
            const dataSources = testUtils.getDataSources();
            expect(dataSources.length).toBeGreaterThan(0);

            const orderDs = dataSources.find((ds: any) => ds.id === 'order');
            expect(orderDs).toBeDefined();
            expect(orderDs?.type).toBe('api');
            expect(orderDs?.endpoint).toContain('orders');
            expect(orderDs?.method).toBe('GET');
        });

        /**
         * @scenario surface=shell
         * @effects shell_order_data_source_init_local_seeds_recipient_form
         */
        it('order 데이터소스 initLocal 맵이 form 필드 바인딩을 담당한다', () => {
            // onLoaded + initOrderDetailForm 핸들러 패턴 → initLocal map 형태로 대체
            // (sirsoft-ecommerce.initOrderDetailForm 핸들러 제거됨)
            const dataSources = testUtils.getDataSources();
            const orderDs = dataSources.find((ds: any) => ds.id === 'order');
            expect(orderDs?.initLocal).toBeDefined();
            expect(typeof orderDs?.initLocal).toBe('object');
            expect(orderDs?.initLocal?.['form.recipient_name']).toContain('data.recipient_name');
            expect(orderDs?.initLocal?.['form.admin_memo']).toContain('data.admin_memo');
        });

        /**
         * @scenario surface=shell
         * @effects shell_declares_order_logs_and_carriers_data_sources
         */
        it('order_logs 데이터소스가 정의되어 있다', () => {
            const dataSources = testUtils.getDataSources();
            const logsDs = dataSources.find((ds: any) => ds.id === 'order_logs');
            expect(logsDs).toBeDefined();
            expect(logsDs?.type).toBe('api');
            expect(logsDs?.endpoint).toContain('logs');
            expect(logsDs?.method).toBe('GET');
        });

        /**
         * @scenario surface=shell
         * @effects shell_declares_order_logs_and_carriers_data_sources
         */
        it('active_carriers 데이터소스가 정의되어 있다', () => {
            const dataSources = testUtils.getDataSources();
            const carriersDs = dataSources.find((ds: any) => ds.id === 'active_carriers');
            expect(carriersDs).toBeDefined();
        });

        /**
         * @scenario surface=shell
         * @effects shell_computed_never_calculates_amounts
         */
        it('computed 가 금액을 계산하지 않는다 (합계는 백엔드 필드 사용)', () => {
            const layout = mainLayout as any;
            expect(layout.computed).toBeDefined();

            // 이 단언이 지키는 계약은 "computed 가 없다" 가 아니라 **화면이 금액을 만들지
            // 않는다** 다. 통화 기호·자릿수·합계를 화면이 조립하면 기본 통화가 다른 상점에서
            // 값은 맞고 단위만 틀린 금액이 나가고, 서버 집계와 갈려도 오류가 남지 않는다.
            // S9c 가 더한 세 항목(탭 배열 · 유형 서브탭 · 서브탭 필터)은 표시 축이고 금액을
            // 건드리지 않는다 — 형제 화면(admin_ecommerce_claim_detail)의 탭 배열도 같은 형태다.
            for (const [key, expression] of Object.entries(layout.computed) as Array<[string, string]>) {
                if (key.startsWith('_comment_')) continue;

                expect(`${key}: ${expression}`).not.toMatch(/_amount|_fee|_total|toLocaleString|reduce\(/);
            }
        });

        /**
         * @scenario surface=shell
         * @effects shell_modal_partial_set_is_pinned_by_name
         */
        it('modals에 등록된 모달 partial 집합이 정확히 일치한다', () => {
            const layout = mainLayout as any;
            expect(Array.isArray(layout.modals)).toBe(true);

            // 개수와 이름을 따로 단언하면 모달 추가 시 개수만 어긋나 무엇이 늘었는지 드러나지 않는다.
            // 집합 자체를 고정해 추가·삭제가 진단 가능하도록 한다.
            const partialPaths = layout.modals.map((m: any) => m.partial);
            expect(new Set(partialPaths)).toEqual(
                new Set([
                    'partials/admin_ecommerce_order_detail/_modal_batch_change_confirm.json',
                    'partials/admin_ecommerce_order_detail/_modal_send_sms.json',
                    'partials/admin_ecommerce_order_detail/_modal_send_email.json',
                    'partials/admin_ecommerce_order_detail/_modal_cancel_order.json',
                    'partials/admin_ecommerce_order_detail/_modal_issue_cash_receipt.json',
                    'partials/admin_ecommerce_order_detail/_modal_reset_guest_password.json',
                    'partials/admin_ecommerce_order_detail/_modal_confirm_deposit.json',
                    // 관리자 직권 교환·반품·취소 접수(#599 §3-11)
                    'partials/admin_ecommerce_order_detail/_modal_admin_claim_request.json',
                ])
            );
        });
    });

    describe('렌더링 및 상태 테스트', () => {
        /**
         * @scenario surface=shell
         * @effects shell_renders_and_survives_order_api_error
         */
        it('API 모킹 후 렌더링이 성공한다', async () => {
            testUtils.mockApi('order', {
                response: { data: mockOrderData },
            });

            await testUtils.render();

            expect(testUtils.getState()._local).toBeDefined();
        });

        /**
         * @scenario surface=shell
         * @effects shell_initial_local_state_seeds_selection_and_log_paging
         */
        it('초기 상태가 올바르게 설정된다', async () => {
            testUtils.mockApi('order', {
                response: { data: mockOrderData },
            });

            await testUtils.render();

            const state = testUtils.getState();
            // state 속성 및 init_actions에서 설정된 초기 상태 검증
            expect(state._local.selectedProducts).toEqual([]);
            expect(state._local.selectAll).toBe(false);
            expect(state._local.batchOrderStatus).toBe('');
            expect(state._local.activeOrderTab).toBe('order_info');
            // init_actions의 setState로 설정되는 값
            expect(state._local.logsPage).toBe(1);
            expect(state._local.logsPerPage).toBe(10);
            expect(state._local.logsSort).toBe('date_desc');
        });

        /**
         * @scenario surface=shell
         * @effects shell_list_button_navigates_to_order_index
         */
        it('목록 버튼 클릭 시 주문 목록으로 이동한다', async () => {
            testUtils.mockApi('order', {
                response: { data: mockOrderData },
            });

            await testUtils.render();

            await testUtils.triggerAction({
                type: 'click',
                handler: 'navigate',
                params: { path: '/admin/ecommerce/orders' },
            });

            expect(testUtils.getNavigationHistory()).toContain('/admin/ecommerce/orders');
        });

        /**
         * @scenario surface=shell
         * @effects shell_selection_and_batch_state_round_trip
         */
        it('일괄변경 상태를 관리할 수 있다', async () => {
            testUtils.mockApi('order', {
                response: { data: mockOrderData },
            });

            await testUtils.render();

            testUtils.setState('selectedProducts', [1, 2], 'local');
            testUtils.setState('batchOrderStatus', 'shipped', 'local');
            testUtils.setState('batchCarrierId', 'cj', 'local');

            const state = testUtils.getState();
            expect(state._local.selectedProducts).toEqual([1, 2]);
            expect(state._local.batchOrderStatus).toBe('shipped');
            expect(state._local.batchCarrierId).toBe('cj');
        });

        /**
         * @scenario surface=shell
         * @effects shell_selection_and_batch_state_round_trip
         */
        it('DataGrid onSelectionChange로 선택 상태를 관리할 수 있다', async () => {
            testUtils.mockApi('order', {
                response: { data: mockOrderData },
            });

            await testUtils.render();

            // DataGrid의 onSelectionChange가 selectedProducts를 업데이트하는 방식
            testUtils.setState('selectedProducts', [1, 2], 'local');

            const state = testUtils.getState();
            expect(state._local.selectedProducts).toEqual([1, 2]);
        });
    });

    describe('모달 테스트', () => {
        /**
         * @scenario surface=shell
         * @effects shell_modals_open_by_id
         */
        it('일괄변경 확인 모달을 열 수 있다', async () => {
            testUtils.mockApi('order', {
                response: { data: mockOrderData },
            });

            await testUtils.render();

            testUtils.openModal('modal_batch_change_confirm');
            expect(testUtils.getModalStack()).toContain('modal_batch_change_confirm');
        });

        /**
         * @scenario surface=shell
         * @effects shell_modals_open_by_id
         */
        it('SMS 모달을 열 수 있다', async () => {
            testUtils.mockApi('order', {
                response: { data: mockOrderData },
            });

            await testUtils.render();

            testUtils.openModal('modal_send_sms');
            expect(testUtils.getModalStack()).toContain('modal_send_sms');
        });

        /**
         * @scenario surface=shell
         * @effects shell_modals_open_by_id
         */
        it('이메일 모달을 열 수 있다', async () => {
            testUtils.mockApi('order', {
                response: { data: mockOrderData },
            });

            await testUtils.render();

            testUtils.openModal('modal_send_email');
            expect(testUtils.getModalStack()).toContain('modal_send_email');
        });
    });

    describe('API 에러 처리', () => {
        /**
         * @scenario surface=shell
         * @effects shell_renders_and_survives_order_api_error
         */
        it('API 에러 시에도 레이아웃이 유지된다', async () => {
            testUtils.mockApiError('order', 500, '서버 오류');

            await testUtils.render();

            const state = testUtils.getState();
            expect(state._local).toBeDefined();
        });
    });
});

// ========== Partial 구조 검증 (JSON 기반) ==========

describe('Partial 레이아웃 구조 검증', () => {
    describe('_partial_order_info.json (주문정보 탭)', () => {
        /**
         * @scenario surface=order_info
         * @effects order_info_partial_is_a_basic_div_root
         */
        it('최상위에 type과 name이 정의되어 있다', () => {
            expect(orderInfoPartial.type).toBe('basic');
            expect(orderInfoPartial.name).toBe('Div');
            expect(orderInfoPartial.children).toBeDefined();
            expect(Array.isArray(orderInfoPartial.children)).toBe(true);
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_bulk_bar_calls_build_bulk_confirm_handler
         */
        it('일괄변경 버튼이 buildOrderDetailBulkConfirmData 핸들러를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"handler":"sirsoft-ecommerce.buildOrderDetailBulkConfirmData"');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_grid_reads_order_options_and_reports_selection
         */
        it('DataGrid에 selectedCountText가 빈 문자열로 설정되어 있다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"selectedCountText":""');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_carrier_fields_appear_only_for_shipping_statuses
         */
        it('운송사/송장번호가 배송 관련 상태 선택 시에만 표시된다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain("_local.batchOrderStatus === 'shipping_ready' || _local.batchOrderStatus === 'shipping' || _local.batchOrderStatus === 'delivered'");
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_bulk_bar_disabled_without_selection
         */
        it('일괄변경 버튼이 선택 항목 없을 때 비활성화된다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('!_local.selectedProducts || _local.selectedProducts.length === 0');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_grid_cells_avoid_wrapping_except_product_column
         */
        it('셀에 whitespace-nowrap이 적용되어 있다 (상품정보 제외)', () => {
            const json = JSON.stringify(orderInfoPartial);
            // price, quantity, discount, actual_price, points, status, delivery, subtotal 셀에 whitespace-nowrap
            const nowrapCount = (json.match(/whitespace-nowrap/g) || []).length;
            expect(nowrapCount).toBeGreaterThanOrEqual(8);
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_grid_reads_order_options_and_reports_selection
         */
        it('주문상품 DataGrid가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"name":"DataGrid"');
            expect(json).toContain('"id":"order_product_datagrid"');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_grid_reads_order_options_and_reports_selection
         */
        it('DataGrid가 order.data.options를 데이터 소스로 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.options');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_grid_reads_order_options_and_reports_selection
         */
        it('DataGrid의 onSelectionChange가 selectedProducts를 업데이트한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"event":"onSelectionChange"');
            expect(json).toContain('"selectedProducts"');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_admin_memo_is_bound_to_the_shared_form
         */
        it('admin_memo 입력 영역이 form 바인딩으로 통합되어 있다', () => {
            // 별도 saveAdminMemo 핸들러 → 일반 form apiCall 로 통합됨
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('admin_memo_section');
            expect(json).toContain('_local.form.admin_memo');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_cancel_section_appears_only_with_cancel_history
         */
        it('취소 사유 섹션이 취소 이력이 있을 때만 노출된다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('cancel_reason_section');
            expect(json).toContain('order.data?.cancels?.data ?? order.data?.cancels ?? []');
            expect(json).toContain('sirsoft-ecommerce.admin.order.detail.order_info.cancel_reason.title');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_cancel_rows_bind_reason_type_and_detail
         */
        it('취소 건별 사유 라벨·취소유형·상세 사유가 바인딩된다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"item_var":"cancel"');
            expect(json).toContain('cancel.cancel_reason_label ?? \'\'');
            expect(json).toContain('cancel.cancel_type_label ?? \'\'');
            expect(json).toContain('"if":"{{cancel.cancel_reason_detail}}"');
            expect(json).toContain('cancel.cancel_reason_detail ?? \'\'');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_email_modal_opener_exists
         */
        it('이메일 모달 열기 버튼이 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"target":"modal_send_email"');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_member_action_menu_links_to_user_detail
         */
        it('회원용 ActionMenu가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"name":"ActionMenu"');
            expect(json).toContain('"id":"view_member"');
            // search_by_member 항목은 id 가 search_by_orderer 로 변경됨 (label 키만 유지)
            expect(json).toContain('"id":"search_by_orderer"');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_guest_block_offers_its_own_action_menu
         */
        it('비회원용 ActionMenu가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // 비회원 분기 진입 조건: !order.data?.user_id
            expect(json).toContain('!order.data?.user_id');
            // 비회원 검색 액션은 ActionMenu 의 별도 메뉴 항목으로 존재
            expect(json).toContain('"name":"ActionMenu"');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_member_action_menu_links_to_user_detail
         */
        it('회원용 ActionMenu에 navigate 경로가 /admin/users/{user_id} 로 연결되어 있다', () => {
            // 회원 라우트가 /admin/members → /admin/users 로 통합됨
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('/admin/users/{{order.data?.user_id}}');
        });

        // ========== 상품 이미지/상품명 링크 검증 ==========

        /**
         * @scenario surface=order_info
         * @effects order_info_product_image_and_name_link_to_product_edit_in_new_tab
         */
        it('상품 이미지가 A 태그로 감싸여 상품수정 페이지로 링크된다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // product_info cellChildren에서 A 컴포넌트가 이미지를 감싸고 있어야 함
            expect(json).toContain('/admin/ecommerce/products/');
            expect(json).toContain("row.product_snapshot?.product_code");
            expect(json).toContain("/edit");
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_product_image_and_name_link_to_product_edit_in_new_tab
         */
        it('상품명이 A 태그로 상품수정 페이지 링크가 적용되어 있다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // A 컴포넌트가 product_name 텍스트를 포함하고 있어야 함
            expect(json).toContain('"name":"A"');
            expect(json).toContain('"text":"{{row.product_name}}"');
            // A 태그의 href에 상품수정 경로가 포함되어야 함
            expect(json).toContain("row.product_snapshot?.product_code");
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_product_image_and_name_link_to_product_edit_in_new_tab
         */
        it('상품 링크가 새 창(target="_blank")으로 열린다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // product_info cellChildren 내 A 태그에 target="_blank" 확인
            // 이미지 링크 + 상품명 링크 = 2곳에서 _blank 사용
            const productLinkPattern = /"name":"A".*?"target":"_blank"/g;
            const matches = json.match(productLinkPattern);
            expect(matches).not.toBeNull();
            expect(matches!.length).toBeGreaterThanOrEqual(2);
        });

        // ========== 합계행 백엔드 필드 마이그레이션 검증 ==========

        /**
         * 합계행(footerCells)이 order.data 백엔드 필드에 바인딩되어 있는지의 구조 검증.
         * 공개 #98(stale dist) 시나리오 매니페스트 admin-order-detail-footer-totals.yaml 의
         * 레이아웃 계층 커버를 담당한다 — 서빙 번들 실검은 Playwright spec 이 맡는다.
         *
         * @effects order_detail_footer_totals_fields_bound
         */
        it('합계행(footer)이 computed 대신 order.data 백엔드 필드를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // computed 참조가 없어야 함
            expect(json).not.toContain('_computed.totalQuantity');
            expect(json).not.toContain('_computed.totalSellingPrice');
            expect(json).not.toContain('_computed.totalDiscount');
            expect(json).not.toContain('_computed.totalCouponDiscount');
            expect(json).not.toContain('_computed.totalCodeDiscount');
            expect(json).not.toContain('_computed.totalEarnedPoints');
            expect(json).not.toContain('_computed.totalListPrice');
            expect(json).not.toContain('_computed.optionCount');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_footer_totals_read_backend_aggregates_not_computed
         */
        it('합계행 수량이 order.data?.total_quantity를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.total_quantity');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_footer_totals_read_backend_aggregates_not_computed
         */
        it('합계행 판매가가 order.data?.subtotal_amount_formatted를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.subtotal_amount_formatted');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_footer_totals_read_backend_aggregates_not_computed
         */
        it('합계행 정가가 order.data?.total_list_price_formatted를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.total_list_price_formatted');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_footer_totals_read_backend_aggregates_not_computed
         */
        it('합계행 할인이 order.data?.total_discount_amount를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.total_discount_amount_formatted');
            expect(json).toContain('order.data?.total_coupon_discount_amount_formatted');
            expect(json).toContain('order.data?.total_code_discount_amount_formatted');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_footer_totals_read_backend_aggregates_not_computed
         */
        it('합계행 적립예정이 order.data?.total_earned_points_amount를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.total_earned_points_amount');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_footer_totals_read_backend_aggregates_not_computed
         */
        it('합계행 소계가 order.data?.subtotal_amount_formatted를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // footerCells의 subtotal 필드
            expect(json).toContain('order.data?.subtotal_amount_formatted');
        });

        // ========== 개별 행 실구매가격 검증 ==========

        /**
         * @scenario surface=order_info
         * @effects order_info_row_amounts_use_backend_formatted_fields
         */
        it('개별 행 실구매가격이 final_amount_formatted를 사용한다 (할인 후)', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('row.final_amount_formatted');
            // 할인 전 subtotal_price_formatted는 실구매가격에 사용되지 않아야 함
            // (소계 열에서는 여전히 사용)
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_row_amounts_use_backend_formatted_fields
         */
        it('개별 행 마일리지/예치금이 올바른 필드명을 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('row.subtotal_points_used_amount');
            expect(json).toContain('row.subtotal_deposit_used_amount');
        });

        // ========== 다통화 표시 검증 ==========

        /**
         * @scenario surface=order_info
         * @effects order_info_secondary_currency_rendered_by_iteration_with_preferred_filter
         */
        it('개별 행 판매가에 다통화 표시가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('row.mc_unit_price');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_secondary_currency_rendered_by_iteration_with_preferred_filter
         */
        it('개별 행 소계에 다통화 표시가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('row.mc_subtotal_price');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_secondary_currency_rendered_by_iteration_with_preferred_filter
         */
        it('합계행에 다통화 표시가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.mc_subtotal_amount');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_secondary_currency_rendered_by_iteration_with_preferred_filter
         */
        it('다통화 표시에 preferredCurrency 필터가 적용된다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // 보조 통화만 표시 (기본 통화 제외)
            expect(json).toContain("_global.preferredCurrency ?? _global.defaultCurrency");
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_secondary_currency_rendered_by_iteration_with_preferred_filter
         */
        it('다통화 표시에 iteration 패턴이 사용된다 (engine-v1.19.1)', () => {
            const json = JSON.stringify(orderInfoPartial);
            // DataGrid 셀 내부에서 iteration 패턴 사용 (엔진 수정으로 가능)
            expect(json).toContain('"item_var":"currency"');
            expect(json).toContain('currency[1]?.formatted');
            // .map().join() 워크어라운드가 아닌 iteration 패턴 확인
            expect(json).not.toContain('.map(([code, val]) => val?.formatted).filter(Boolean).join');
        });

        // ========== 재고 적용 여부 / 적립 여부 배지 검증 ==========

        /**
         * @scenario stock_deduction=deducted, points_earn=pending, non_purchase_tx=none
         * @effects quantity_column_renders_stock_badge_by_deduction
         */
        it('구매수량 컬럼에 재고 차감/미차감 배지가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // 재고 적용 여부에 따른 배지 분기 (is_stock_deducted)
            expect(json).toContain('"if":"{{row.is_stock_deducted}}"');
            expect(json).toContain('"if":"{{!row.is_stock_deducted}}"');
            // 다국어 키
            expect(json).toContain('stock_status.deducted');
            expect(json).toContain('stock_status.not_deducted');
        });

        /**
         * @scenario stock_deduction=deducted, points_earn=earned, non_purchase_tx=none
         * @effects points_column_renders_earn_badge_when_points_exist, earn_badge_hidden_when_no_points
         */
        it('적립예정 컬럼에 적립완료/예정 배지가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // 적립예정액이 있을 때만 + 실제 적립 여부(is_points_earned)에 따른 분기
            expect(json).toContain('row.subtotal_earned_points_amount > 0 && row.is_points_earned');
            expect(json).toContain('row.subtotal_earned_points_amount > 0 && !row.is_points_earned');
            // 다국어 키
            expect(json).toContain('earn_status.earned');
            expect(json).toContain('earn_status.pending');
        });

        /**
         * 적립 배지는 **셋** 이다 — 취소된 옵션은 지킬 수 없는 「적립예정」을 말하지 않는다 (S1-D19).
         *
         * 실측(#599 통합실측 세션1 · 1-M · 주문 108): 전량 취소되어 76,500원 전액 환불된
         * 주문의 옵션 3행이 각각 `228P 적립예정` · `160P 적립예정` · `440P 적립예정` 을
         * 말하고 있었다. 그 주문의 `purchase_earn` 거래는 0건이고 앞으로도 0건이다.
         *
         * 조건은 배타적이어야 한다 — pending 조건이 `is_points_earn_cancelled` 를 배제하지
         * 않으면 취소 옵션에 배지가 **둘** 붙는다.
         */
        // @scenario stock_deduction=not_deducted, points_earn=earn_cancelled, non_purchase_tx=none
        // @effects resource_marks_cancelled_option_as_earn_cancelled
        it('적립 배지가 완료·예정·없음 셋으로 갈리고 조건이 서로 배타적이다 (S1-D19)', () => {
            const json = JSON.stringify(orderInfoPartial);

            // 세 번째 배지가 새 상태 필드로 분기한다
            expect(json).toContain('row.subtotal_earned_points_amount > 0 && row.is_points_earn_cancelled');
            expect(json).toContain('earn_status.cancelled');

            // 「적립예정」은 취소된 옵션을 배제한다 — 이 배제가 없으면 배지 2개가 동시 노출
            expect(json).toContain(
                'row.subtotal_earned_points_amount > 0 && !row.is_points_earned && !row.is_points_earn_cancelled',
            );

            // 세 조건이 같은 상태에서 동시에 성립하지 않는다 (진리표)
            const activeBadges = (earned: boolean, earnCancelled: boolean) => [
                earned,                              // 적립완료
                !earned && !earnCancelled,           // 적립예정
                earnCancelled,                       // 적립 없음
            ].filter(Boolean).length;

            expect(activeBadges(true, false)).toBe(1);   // 지급됨
            expect(activeBadges(false, false)).toBe(1);  // 아직 — 진행 중인 주문
            expect(activeBadges(false, true)).toBe(1);   // 취소됨 — 이전엔 「적립예정」이었다
        });


        /**
         * @scenario stock_deduction=not_deducted, points_earn=no_points, non_purchase_tx=order_use_only
         * @effects badges_use_badge_component_with_color_prop
         */
        it('재고/적립 배지가 Badge 컴포넌트로 color prop을 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // Badge 컴포넌트 사용 + color prop (admin Badge 는 color prop 기반)
            expect(json).toContain('"name":"Badge"');
            expect(json).toContain('"color":"green"');
            expect(json).toContain('"color":"gray"');
        });

        // ========== 합계행 세부 표시 검증 ==========

        /**
         * @scenario surface=order_info
         * @effects order_info_footer_detail_shows_tax_points_and_deposit
         */
        it('합계행 실구매가격에 세금/마일리지/예치금 세부 표시가 있다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.total_tax_amount');
            expect(json).toContain('order.data?.total_vat_amount');
            expect(json).toContain('order.data?.total_points_used_amount');
            expect(json).toContain('order.data?.total_deposit_used_amount');
        });

        /**
         * @scenario surface=order_info
         * @effects order_info_translation_keys_stay_in_module_namespace
         */
        it('다국어 키가 올바른 네임스페이스를 사용한다', () => {
            const keys: string[] = [];
            const extract = (node: any) => {
                if (!node) return;
                if (typeof node === 'string') {
                    const matches = node.match(/\$t:[a-zA-Z0-9._-]+/g);
                    if (matches) keys.push(...matches);
                }
                if (typeof node === 'object') {
                    for (const value of Object.values(node)) extract(value);
                }
            };
            extract(orderInfoPartial);
            const invalidKeys = keys.filter((key) => !key.startsWith('$t:sirsoft-ecommerce.'));
            expect(invalidKeys).toEqual([]);
        });

        /**
         * 취소된 줄의 배송비 몫은 0 이다(부분취소·재활성 재계산 — S-Q26-b). 「무료배송 0원」 으로 읽히지 않게
         * 유료/무료 줄과 배송비 줄을 숨긴다.
         *
         * 시나리오 매니페스트: `tests/scenarios/partial-cancel-row-allocation.yaml`
         * @effects cancelled_lines_hide_shipping_fee_on_admin_and_member_screens
         */
        it('취소된 줄은 배송비 줄을 숨긴다', () => {
            const found: Record<string, any>[] = [];
            const walk = (node: any): void => {
                if (Array.isArray(node)) {
                    node.forEach(walk);
                    return;
                }
                if (node && typeof node === 'object') {
                    const text = typeof node.text === 'string' ? node.text : '';
                    if (text.includes('delivery_info.paid_shipping') || text.includes('delivery_info.shipping_fee')) {
                        found.push(node);
                    }
                    Object.values(node).forEach(walk);
                }
            };
            walk(orderInfoPartial);

            expect(found).toHaveLength(2);
            for (const node of found) {
                expect(node.if).toContain("row.option_status !== 'cancelled'");
            }
        });
    });

    describe('_partial_payment_info.json (결제정보 탭)', () => {
        /**
         * @scenario surface=payment_info
         * @effects payment_info_partial_is_a_basic_div_root
         */
        it('최상위에 type과 name이 정의되어 있다', () => {
            expect(paymentInfoPartial.type).toBe('basic');
            expect(paymentInfoPartial.name).toBe('Div');
            expect(paymentInfoPartial.children).toBeDefined();
        });

        /**
         * @scenario surface=payment_info
         * @effects payment_info_cards_iterate_payments
         */
        it('결제 카드가 payments iteration을 사용한다', () => {
            const json = JSON.stringify(paymentInfoPartial);
            expect(json).toContain('payments');
            expect(json).toContain('iteration');
        });

        /**
         * @scenario surface=payment_info
         * @effects payment_info_order_amounts_read_order_data
         */
        it('주문 금액은 order.data 경로를 사용한다', () => {
            const json = JSON.stringify(paymentInfoPartial);
            expect(json).toContain('order.data?.subtotal_amount_formatted');
            expect(json).toContain('order.data?.total_shipping_amount_formatted');
            expect(json).toContain('order.data?.total_paid_amount_formatted');
        });

        /**
         * @scenario surface=payment_info
         * @effects payment_info_payment_only_fields_keep_payment_path
         */
        it('결제 고유 필드는 payment 경로를 유지한다', () => {
            const json = JSON.stringify(paymentInfoPartial);
            expect(json).toContain('payment.payment_method_label');
            expect(json).toContain('payment.paid_at_formatted');
            expect(json).toContain('payment.payment_status_label');
        });

        // 마일리지 사용/적립 표시는 주문(Order) 레벨 집계를 바인딩한다.
        /**
         * @scenario surface=payment_info
         * @effects payment_info_mileage_reads_order_level_aggregate
         */
        it('마일리지 사용/적립 표시는 order.data 경로를 사용한다', () => {
            const json = JSON.stringify(paymentInfoPartial);
            expect(json).toContain('order.data?.total_points_used_amount');
            expect(json).toContain('order.data?.total_points_used_amount_formatted');
            expect(json).toContain('order.data?.total_earned_points_amount');
        });

        // 현금 환불 중 「마일리지로 환불」로 지급한 몫은 환불금액의 그중 내역으로 따로 그린다(Q3).
        /**
         * @scenario surface=payment_info
         * @effects payment_info_shows_mileage_credited_cash_share
         */
        it('마일리지로 지급한 현금분 행이 주문 집계를 읽는다', () => {
            const json = JSON.stringify(paymentInfoPartial);
            expect(json).toContain('order.data?.total_refunded_mileage_credit_amount');
            expect(json).toContain('order.data?.total_refunded_mileage_credit_amount_formatted');
        });

        // 결함 C-2: 환불 금액/환불 마일리지는 결제(payment) 레벨이 아니라
        // 주문(Order) 레벨 집계(total_refunded_amount / total_refunded_points_amount)가 SSoT.
        // OrderPaymentResource 에 없는 payment.refund_* / payment.refunded_amount 바인딩은 제거되어야 한다 (silent 미표시 회귀 차단).
        /**
         * @scenario surface=payment_info
         * @effects payment_info_refund_reads_order_level_aggregate
         */
        it('환불 금액/환불 마일리지는 order.data 집계 경로를 바인딩한다', () => {
            const json = JSON.stringify(paymentInfoPartial);
            expect(json).toContain('order.data?.total_refunded_amount');
            expect(json).toContain('order.data?.total_refunded_points_amount');
            expect(json).toContain('order.data?.total_cancelled_amount');
        });

        /**
         * @scenario surface=payment_info
         * @effects payment_info_never_binds_unexposed_payment_refund_keys
         */
        it('OrderPaymentResource 미노출 환불 키(payment.refund_*/payment.refunded_amount)를 바인딩하지 않는다', () => {
            const json = JSON.stringify(paymentInfoPartial);
            expect(json).not.toContain('payment.refunded_amount');
            expect(json).not.toContain('payment.refund_points_amount');
            expect(json).not.toContain('payment.refund_status_label');
        });
    });

    describe('_partial_activity_log.json (활동 로그 탭)', () => {
        /**
         * @scenario surface=activity_log
         * @effects activity_log_partial_is_a_basic_div_root
         */
        it('최상위에 type과 name이 정의되어 있다', () => {
            expect(activityLogPartial.type).toBe('basic');
            expect(activityLogPartial.name).toBe('Div');
            expect(activityLogPartial.children).toBeDefined();
        });

        /**
         * @scenario surface=activity_log
         * @effects activity_log_sort_and_per_page_controls_exist
         */
        it('정렬 드롭다운이 존재한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('logsSort');
            expect(json).toContain('date_desc');
            expect(json).toContain('date_asc');
        });

        /**
         * @scenario surface=activity_log
         * @effects activity_log_sort_and_per_page_controls_exist
         */
        it('페이지당 드롭다운이 존재한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('logsPerPage');
            expect(json).toContain('per_page_option');
        });

        /**
         * @scenario surface=activity_log
         * @effects activity_log_iterates_order_logs_api_without_dummy_data
         */
        it('로그 iteration이 order_logs API 데이터를 소스로 사용한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('order_logs.data');
            expect(json).toContain('iteration');
            expect(json).not.toContain('dummyLogs');
        });

        /**
         * @scenario surface=activity_log
         * @effects activity_log_rows_bind_activity_log_resource_fields
         */
        it('ActivityLogResource 필드를 사용한다 (created_at, user.name, description)', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('log.created_at');
            expect(json).toContain('log.user?.name');
            expect(json).toContain('log.localized_description');
            expect(json).not.toContain('created_at_formatted');
        });

        /**
         * @scenario surface=activity_log
         * @effects activity_log_actor_column_shows_avatar_circle_and_name
         */
        it('처리자 열이 아바타 원형 + 이름 스타일을 사용한다', () => {
            const json = JSON.stringify(activityLogPartial);
            // 아바타 원형 (rounded-full)
            expect(json).toContain('rounded-full');
            // flex + items-center 클러스터는 .flex-center 자산이 흡수
            // (justify-center 는 다른 토큰들과 섞일 수 있어 별도 검사)
            expect(json).toContain('flex-center');
            expect(json).toContain('justify-center');
            // 이름 첫 글자 추출 표현식
            expect(json).toContain('.charAt(0).toUpperCase()');
        });

        /**
         * @scenario surface=activity_log
         * @effects activity_log_actor_menu_replaces_the_type_badge
         */
        it('Badge가 아닌 ActionMenu를 사용한다 (log_type_label Badge 제거)', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).not.toContain('"name":"Badge"');
            expect(json).toContain('"name":"ActionMenu"');
        });

        /**
         * @scenario surface=activity_log
         * @effects activity_log_actor_menu_only_for_members_with_uuid
         */
        it('ActionMenu가 user.uuid가 있는 경우에만 표시된다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('!!log.user?.uuid');
            expect(json).toContain('!log.user?.uuid');
        });

        /**
         * @scenario surface=activity_log
         * @effects activity_log_actor_menu_only_for_members_with_uuid
         */
        it('ActionMenu에 회원정보 보기 메뉴가 있다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('view_member');
            expect(json).toContain('actor_action.view_member');
        });

        /**
         * @scenario surface=activity_log
         * @effects activity_log_actor_menu_opens_user_detail_in_new_window
         */
        it('회원 클릭 시 openWindow로 회원 상세 페이지를 연다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('"handler":"openWindow"');
            expect(json).toContain('/admin/users/{{log.user.uuid}}');
        });

        /**
         * @scenario surface=activity_log
         * @effects activity_log_system_actor_has_no_action_menu
         */
        it('시스템 사용자는 ActionMenu 없이 아바타+이름만 표시된다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('activity_log.system');
        });

        /**
         * @scenario surface=activity_log
         * @effects activity_log_empty_and_pagination_are_present
         */
        it('빈 상태 메시지가 존재한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('activity_log.empty');
        });

        /**
         * @scenario surface=activity_log
         * @effects activity_log_empty_and_pagination_are_present
         */
        it('Pagination 컴포넌트가 존재한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('logsPage');
            expect(json).toContain('"name":"Pagination"');
            expect(json).toContain('currentPage');
            expect(json).toContain('totalPages');
        });
    });

    describe('모달 레이아웃 검증', () => {
        /**
         * @scenario surface=modals
         * @effects batch_change_modal_wires_process_handler_and_close
         */
        it('일괄변경 모달에 processOrderDetailBulkChange 핸들러가 연결되어 있다', () => {
            const json = JSON.stringify(batchChangeModal);
            expect(json).toContain('"handler":"sirsoft-ecommerce.processOrderDetailBulkChange"');
        });

        /**
         * @scenario surface=modals
         * @effects batch_change_modal_wires_process_handler_and_close
         */
        it('일괄변경 모달에 closeModal 핸들러가 연결되어 있다', () => {
            const json = JSON.stringify(batchChangeModal);
            expect(json).toContain('"handler":"closeModal"');
        });

        /**
         * @scenario surface=modals
         * @effects sms_and_email_modals_bind_their_recipient_fields
         */
        it('SMS 모달에 smsPhone 바인딩이 있다', () => {
            const json = JSON.stringify(smsModal);
            expect(json).toContain('smsPhone');
        });

        /**
         * @scenario surface=modals
         * @effects sms_and_email_modals_bind_their_recipient_fields
         */
        it('이메일 모달에 emailAddress 바인딩이 있다', () => {
            const json = JSON.stringify(emailModal);
            expect(json).toContain('emailAddress');
        });
    });
});

// ========================================
// 비회원 조회 비밀번호 재설정 (모달 + 버튼) 검증
// ========================================

describe('비회원 조회 비밀번호 재설정', () => {
    describe('_modal_reset_guest_password.json', () => {
        /**
         * @scenario surface=modals
         * @effects guest_password_modal_is_declared_with_its_id
         */
        it('id가 modal_reset_guest_password 인 Modal 이다', () => {
            expect((resetGuestPasswordModal as any).id).toBe('modal_reset_guest_password');
            expect((resetGuestPasswordModal as any).name).toBe('Modal');
        });

        /**
         * @scenario surface=modals
         * @effects guest_password_modal_posts_to_reset_endpoint_with_confirmation
         */
        it('재설정 API 엔드포인트(reset-guest-lookup-password)를 호출한다', () => {
            const json = JSON.stringify(resetGuestPasswordModal);
            expect(json).toContain('/reset-guest-lookup-password');
            expect(json).toContain('"method":"POST"');
        });

        /**
         * @scenario surface=modals
         * @effects guest_password_modal_posts_to_reset_endpoint_with_confirmation
         */
        it('새 비밀번호와 확인 필드를 body로 전송한다', () => {
            const json = JSON.stringify(resetGuestPasswordModal);
            expect(json).toContain('guest_lookup_password');
            expect(json).toContain('guest_lookup_password_confirmation');
        });

        /**
         * @scenario surface=modals
         * @effects guest_password_modal_confirms_and_closes_on_success
         */
        it('성공 시 토스트 + closeModal 한다', () => {
            const json = JSON.stringify(resetGuestPasswordModal);
            expect(json).toContain('reset_success');
            expect(json).toContain('closeModal');
        });

        /**
         * @scenario surface=modals
         * @effects guest_password_modal_masks_the_plain_text_input
         */
        it('평문 비밀번호 입력은 password 타입 input 으로 마스킹한다', () => {
            const json = JSON.stringify(resetGuestPasswordModal);
            expect(json).toContain('"type":"password"');
        });
    });

    describe('주문정보 비회원 블록 재설정 버튼', () => {
        /**
         * @scenario surface=order_info
         * @effects guest_password_reset_button_lives_in_the_guest_block
         */
        it('!user_id 조건의 비회원 블록에 재설정 버튼이 있다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('modal_reset_guest_password');
            expect(json).toContain('reset_guest_password');
        });

        /**
         * @scenario surface=order_info
         * @effects guest_password_reset_button_lives_in_the_guest_block
         */
        it('재설정 버튼이 openModal 핸들러로 모달을 연다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // openModal + 대상 모달 id 가 함께 존재
            expect(json).toContain('"handler":"openModal"');
            expect(json).toContain('"target":"modal_reset_guest_password"');
        });
    });
});

describe('#599 직권 접수 — 꺼진 버튼의 이유 문구', () => {
    /**
     * 배송완료 주문을 고르면 교환·반품은 켜지고 취소·환불만 꺼진다 — 그 한 버튼이 꺼진 이유도 곁에 서야 한다.
     * 셋 다 꺼졌을 때만 안내하면 운영자는 꺼진 [취소·환불 접수] 를 권한 문제로 읽는다(Chrome 실측 P-1).
     *
     * @effects admin_claim_request_gate_hint_shows_when_any_button_is_disabled
     */
    it('버튼이 하나라도 꺼지면 게이트 안내가 선다', () => {
        const nodes: any[] = [];
        const walk = (n: any) => {
            if (Array.isArray(n)) return n.forEach(walk);
            if (n && typeof n === 'object') {
                nodes.push(n);
                Object.values(n).forEach(walk);
            }
        };
        walk(orderInfoPartial);
        const hint = nodes.find((n) => n.id === 'crb_004_disabled_hint');

        expect(hint).toBeDefined();
        expect(hint.if).toContain("_computed.claimRequestGateExchange !== true || _computed.claimRequestGateReturn !== true || _computed.claimRequestGateRefund !== true");
        expect(hint.text ?? JSON.stringify(hint.children ?? '')).toContain('claim_request.gate_hint');
    });
});

describe('#599 직권 접수 — 접수 권한이 없을 때', () => {
    /**
     * `claims.create` 가 없으면 문맥 조회가 403 이고(fallback `unavailable`), 버튼 셋은 숨지 않고 꺼진 채 선다 —
     * 곁에는 상태 안내 대신 권한 안내가 선다(Chrome 실측 P-6).
     *
     * @effects admin_claim_request_buttons_stay_disabled_without_permission
     */
    it('버튼 묶음은 문맥과 무관하게 서고, 권한 안내는 문맥이 없을 때만 선다', () => {
        const nodes: any[] = [];
        const walk = (n: any) => {
            if (Array.isArray(n)) return n.forEach(walk);
            if (n && typeof n === 'object') {
                nodes.push(n);
                Object.values(n).forEach(walk);
            }
        };
        walk(orderInfoPartial);
        const group = nodes.find((n) => n.id === 'claim_request_buttons');
        const stateHint = nodes.find((n) => n.id === 'crb_004_disabled_hint');
        const permissionHint = nodes.find((n) => n.id === 'crb_005_no_permission_hint');

        expect(group.if).toBeUndefined();
        expect(stateHint.if).toContain('claimRequestContext?.data?.unavailable !== true');
        const errorHint = nodes.find((n) => n.id === 'crb_005b_context_error_hint');

        // 문맥 조회 실패는 데이터 소스가 남긴 실패 종류(403=forbidden, 그 밖=failed)로 가른다 — 권한이 있는데 500·네트워크로 못 받은 것을
        // 「권한 없음」 으로 알리면 운영자는 엉뚱하게 권한을 요청하러 간다(3차 검증 인터뷰 2026-09-26).
        expect(permissionHint.if).toBe("{{claimRequestContext?.data?.unavailable === true && _local.claimRequestContextError !== 'failed'}}");
        expect(JSON.stringify(permissionHint)).toContain('claim_request.no_permission');
        expect(errorHint.if).toBe("{{claimRequestContext?.data?.unavailable === true && _local.claimRequestContextError === 'failed'}}");
        expect(JSON.stringify(errorHint)).toContain('claim_request.context_load_failed');
    });

    /**
     * 접수 문맥 조회가 실패하면 데이터 소스가 실패 종류를 남긴다 — 403 만 「권한 없음」, 500·네트워크는 「불러오지 못함」.
     * 핸들러가 없으면 기본 정책(토스트)으로 번지고, 종류가 없으면 두 안내를 가를 수 없다.
     *
     * @effects admin_claim_request_context_error_distinguishes_forbidden_from_failure
     */
    it('접수 문맥 조회 실패는 403 과 그 밖을 다른 종류로 남긴다', () => {
        const source = (mainLayout as any).data_sources.find((d: any) => d.id === 'claimRequestContext');

        expect(source.errorHandling['403']).toMatchObject({ handler: 'setState', params: { target: 'local', claimRequestContextError: 'forbidden' } });
        expect(source.errorHandling.default).toMatchObject({ handler: 'setState', params: { target: 'local', claimRequestContextError: 'failed' } });
    });
});

describe('#599 직권 접수 — 반품의 환불 칸', () => {
    /**
     * 반품 접수는 환불이 따라오므로 종전 취소 창의 두 칸(환불 계좌·환불 우선순위)을 같은 조건으로 세운다 —
     * 계좌는 사람이 송금하는 결제수단에 돌려줄 돈이 있을 때, 우선순위는 PG 환불이 걸릴 때만. 교환에는 서지 않고,
     * 두 버튼 모두 주문 결제 정보를 넘겨 주문 때 적은 계좌를 미리 채운다(계획 §3-11).
     *
     * @effects admin_return_request_carries_refund_priority_and_prefilled_bank
     */
    it('환불 계좌·우선순위는 반품에만 종전 취소 창 조건으로 서고, 여는 버튼이 결제 정보를 넘긴다', () => {
        const nodes: any[] = [];
        const walk = (n: any) => {
            if (Array.isArray(n)) return n.forEach(walk);
            if (n && typeof n === 'object') {
                nodes.push(n);
                Object.values(n).forEach(walk);
            }
        };
        walk(adminClaimRequestModal);
        const bank = nodes.find((n) => n.id === 'acr_012_refund_bank');
        const priority = nodes.find((n) => n.id === 'acr_013_refund_priority');

        expect(bank.if).toContain("_global.adminClaimRequest?.type === 'return'");
        expect(bank.if).toContain("['vbank', 'dbank'].includes(order.data?.payment?.payment_method)");
        expect(bank.if).toContain('total_refunded_amount');
        expect(priority.if).toContain("_global.adminClaimRequest?.type === 'return'");
        expect(priority.if).toContain('requires_pg_cancellation === true');

        const bankJson = JSON.stringify(bank);
        for (const field of ['refundBank.bank_code', 'refundBank.account_number', 'refundBank.holder']) {
            expect(bankJson).toContain(`"field":"${field}"`);
        }
        const priorityJson = JSON.stringify(priority);
        expect(priorityJson).toContain('"field":"refundPriority","value":"pg_first"');
        expect(priorityJson).toContain('"field":"refundPriority","value":"points_first"');

        const openers: any[] = [];
        const collect = (n: any) => {
            if (Array.isArray(n)) return n.forEach(collect);
            if (n && typeof n === 'object') {
                if (n.handler === 'sirsoft-ecommerce.openAdminClaimRequest') openers.push(n);
                Object.values(n).forEach(collect);
            }
        };
        collect(orderInfoPartial);
        expect(openers).toHaveLength(2);
        openers.forEach((opener) => expect(opener.params.payment).toBe('{{order?.data?.payment ?? null}}'));
    });
});

describe('#599 직권·대리 입력 창 — 글자 수 제한 prop', () => {
  /**
   * Textarea 의 글자 수 제한 prop 은 camelCase(`maxLength`)다 — 소문자 `maxlength` 로 쓰면 React 가 모르는 prop 으로
   * 흘려 클라이언트 제한이 걸리지 않는다(validate branch M9). 서버 검증 한도와 같은 값이어야 한다.
   *
   * @effects admin_claim_textareas_use_camelcase_max_length
   */
  it('직권 접수·대리 철회·신청 수정 창의 입력칸이 maxLength 로 한도를 건다', async () => {
    const modals = await Promise.all([
      import('../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_admin_claim_request.json'),
      import('../../../layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_request_edit.json'),
      import('../../../layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_withdraw_on_behalf.json'),
    ]);
    const limits = modals.map((m) => {
      const json = JSON.stringify(m.default ?? m);
      expect(json).not.toMatch(/"maxlength"/);
      return (json.match(/"maxLength":(\d+)/) ?? [])[1];
    });

    expect(limits).toEqual(['1000', '1000', '2000']);
  });
});

describe('직권 접수 — 해외 주문 재발송지 (S2-D8)', () => {
  /**
   * 해외 주문은 재발송지에 도시·주가 있어야 주소가 완결된다 — 창이 국내형 칸만 두면 운영자가 도시·주를
   * 고칠 수 없다. 국내 주문에는 칸이 서지 않도록 핸들러가 정한 해외 표식으로 가른다.
   *
   * @effects admin_request_modal_seeds_the_international_redelivery_address
   */
  it('재발송지 칸 묶음이 해외 표식일 때만 도시·주 입력을 세운다', () => {
    const nodes: any[] = [];
    const walk = (n: any) => {
      if (Array.isArray(n)) return n.forEach(walk);
      if (n && typeof n === 'object') {
        nodes.push(n);
        Object.values(n).forEach(walk);
      }
    };
    walk(adminClaimRequestModal);

    const block = nodes.find((n) => n.id === 'acr_006_redelivery');
    const fields = JSON.stringify(block);
    expect(fields).toContain('"field":"redelivery.city"');
    expect(fields).toContain('"field":"redelivery.state"');

    const cityInput = nodes.find((n) => (n.actions ?? []).some((a: any) => a.params?.field === 'redelivery.city'));
    expect(cityInput?.if).toBe('{{_global.adminClaimRequest?.redeliveryInternational === true}}');
  });
});

describe('#599 직권 접수 — 청구 미리보기 금액 표기', () => {
  /**
   * 금액 줄은 핸들러가 주문 통화로 만든 `previewFormatted` 를 읽는다 — 응답에 없는 `*_formatted` 키를 읽으면
   * 반품 환불 예정액 줄이 서지 않고 교환 청구액은 단위 없는 숫자로 나온다(Chrome 실측 V-A1-m). 여는 두 버튼은
   * 주문 기준 통화를 넘긴다.
   *
   * @effects admin_request_preview_amounts_are_formatted_in_order_currency
   */
  it('교환 청구액·반품 환불 예정액이 previewFormatted 를 읽고, 여는 버튼이 통화를 넘긴다', () => {
    const nodes: any[] = [];
    const walk = (n: any) => {
      if (Array.isArray(n)) return n.forEach(walk);
      if (n && typeof n === 'object') {
        nodes.push(n);
        Object.values(n).forEach(walk);
      }
    };
    walk(adminClaimRequestModal);
    const charge = nodes.find((n) => n.id === 'acr_014_total_charge');
    const refund = nodes.find((n) => n.id === 'acr_015_refund_estimate');

    expect(JSON.stringify(charge)).toContain('previewFormatted?.total_charge_amount');
    expect(refund.if).toContain("type === 'return'");
    expect(JSON.stringify(refund)).toContain('previewFormatted?.refund_total');
    expect(JSON.stringify(adminClaimRequestModal)).not.toContain('total_refund_amount_formatted');
    expect(JSON.stringify(adminClaimRequestModal)).not.toContain('mc_total_charge_amount_formatted');

    const openers: any[] = [];
    const collect = (n: any) => {
      if (Array.isArray(n)) return n.forEach(collect);
      if (n && typeof n === 'object') {
        if (n.handler === 'sirsoft-ecommerce.openAdminClaimRequest') openers.push(n);
        Object.values(n).forEach(collect);
      }
    };
    collect(orderInfoPartial);
    openers.forEach((opener) => expect(opener.params.currency).toBe('{{order?.data?.base_currency ?? null}}'));
  });
});
