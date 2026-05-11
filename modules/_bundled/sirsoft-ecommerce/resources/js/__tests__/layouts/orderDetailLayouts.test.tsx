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
        it('레이아웃 정보가 올바르게 로드된다', () => {
            const info = testUtils.getLayoutInfo();
            expect(info.name).toBe('admin_ecommerce_order_detail');
            expect(info.version).toBe('1.0.0');
        });

        it('order 데이터소스가 정의되어 있다', () => {
            const dataSources = testUtils.getDataSources();
            expect(dataSources.length).toBeGreaterThan(0);

            const orderDs = dataSources.find((ds: any) => ds.id === 'order');
            expect(orderDs).toBeDefined();
            expect(orderDs?.type).toBe('api');
            expect(orderDs?.endpoint).toContain('orders');
            expect(orderDs?.method).toBe('GET');
        });

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

        it('order_logs 데이터소스가 정의되어 있다', () => {
            const dataSources = testUtils.getDataSources();
            const logsDs = dataSources.find((ds: any) => ds.id === 'order_logs');
            expect(logsDs).toBeDefined();
            expect(logsDs?.type).toBe('api');
            expect(logsDs?.endpoint).toContain('logs');
            expect(logsDs?.method).toBe('GET');
        });

        it('active_carriers 데이터소스가 정의되어 있다', () => {
            const dataSources = testUtils.getDataSources();
            const carriersDs = dataSources.find((ds: any) => ds.id === 'active_carriers');
            expect(carriersDs).toBeDefined();
        });

        it('computed 속성이 비어있다 (합계는 백엔드 필드 사용)', () => {
            const layout = mainLayout as any;
            expect(layout.computed).toBeDefined();
            expect(Object.keys(layout.computed)).toHaveLength(0);
        });

        it('modals에 4개의 모달 partial이 정의되어 있다 (cancel_order 추가)', () => {
            const layout = mainLayout as any;
            expect(Array.isArray(layout.modals)).toBe(true);
            expect(layout.modals).toHaveLength(4);
            const partialPaths = layout.modals.map((m: any) => m.partial);
            expect(partialPaths).toContain('partials/admin_ecommerce_order_detail/_modal_batch_change_confirm.json');
            expect(partialPaths).toContain('partials/admin_ecommerce_order_detail/_modal_send_sms.json');
            expect(partialPaths).toContain('partials/admin_ecommerce_order_detail/_modal_send_email.json');
            expect(partialPaths).toContain('partials/admin_ecommerce_order_detail/_modal_cancel_order.json');
        });
    });

    describe('렌더링 및 상태 테스트', () => {
        it('API 모킹 후 렌더링이 성공한다', async () => {
            testUtils.mockApi('order', {
                response: { data: mockOrderData },
            });

            await testUtils.render();

            expect(testUtils.getState()._local).toBeDefined();
        });

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
        it('일괄변경 확인 모달을 열 수 있다', async () => {
            testUtils.mockApi('order', {
                response: { data: mockOrderData },
            });

            await testUtils.render();

            testUtils.openModal('modal_batch_change_confirm');
            expect(testUtils.getModalStack()).toContain('modal_batch_change_confirm');
        });

        it('SMS 모달을 열 수 있다', async () => {
            testUtils.mockApi('order', {
                response: { data: mockOrderData },
            });

            await testUtils.render();

            testUtils.openModal('modal_send_sms');
            expect(testUtils.getModalStack()).toContain('modal_send_sms');
        });

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
        it('최상위에 type과 name이 정의되어 있다', () => {
            expect(orderInfoPartial.type).toBe('basic');
            expect(orderInfoPartial.name).toBe('Div');
            expect(orderInfoPartial.children).toBeDefined();
            expect(Array.isArray(orderInfoPartial.children)).toBe(true);
        });

        it('일괄변경 버튼이 buildOrderDetailBulkConfirmData 핸들러를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"handler":"sirsoft-ecommerce.buildOrderDetailBulkConfirmData"');
        });

        it('DataGrid에 selectedCountText가 빈 문자열로 설정되어 있다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"selectedCountText":""');
        });

        it('운송사/송장번호가 배송 관련 상태 선택 시에만 표시된다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain("_local.batchOrderStatus === 'shipping_ready' || _local.batchOrderStatus === 'shipping' || _local.batchOrderStatus === 'delivered'");
        });

        it('일괄변경 버튼이 선택 항목 없을 때 비활성화된다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('!_local.selectedProducts || _local.selectedProducts.length === 0');
        });

        it('셀에 whitespace-nowrap이 적용되어 있다 (상품정보 제외)', () => {
            const json = JSON.stringify(orderInfoPartial);
            // price, quantity, discount, actual_price, points, status, delivery, subtotal 셀에 whitespace-nowrap
            const nowrapCount = (json.match(/whitespace-nowrap/g) || []).length;
            expect(nowrapCount).toBeGreaterThanOrEqual(8);
        });

        it('주문상품 DataGrid가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"name":"DataGrid"');
            expect(json).toContain('"id":"order_product_datagrid"');
        });

        it('DataGrid가 order.data.options를 데이터 소스로 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.options');
        });

        it('DataGrid의 onSelectionChange가 selectedProducts를 업데이트한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"event":"onSelectionChange"');
            expect(json).toContain('"selectedProducts"');
        });

        it('admin_memo 입력 영역이 form 바인딩으로 통합되어 있다', () => {
            // 별도 saveAdminMemo 핸들러 → 일반 form apiCall 로 통합됨
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('admin_memo_section');
            expect(json).toContain('_local.form.admin_memo');
        });

        it('이메일 모달 열기 버튼이 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"target":"modal_send_email"');
        });

        it('회원용 ActionMenu가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('"name":"ActionMenu"');
            expect(json).toContain('"id":"view_member"');
            // search_by_member 항목은 id 가 search_by_orderer 로 변경됨 (label 키만 유지)
            expect(json).toContain('"id":"search_by_orderer"');
        });

        it('비회원용 ActionMenu가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // 비회원 분기 진입 조건: !order.data?.user_id
            expect(json).toContain('!order.data?.user_id');
            // 비회원 검색 액션은 ActionMenu 의 별도 메뉴 항목으로 존재
            expect(json).toContain('"name":"ActionMenu"');
        });

        it('회원용 ActionMenu에 navigate 경로가 /admin/users/{user_id} 로 연결되어 있다', () => {
            // 회원 라우트가 /admin/members → /admin/users 로 통합됨
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('/admin/users/{{order.data?.user_id}}');
        });

        // ========== 상품 이미지/상품명 링크 검증 ==========

        it('상품 이미지가 A 태그로 감싸여 상품수정 페이지로 링크된다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // product_info cellChildren에서 A 컴포넌트가 이미지를 감싸고 있어야 함
            expect(json).toContain('/admin/ecommerce/products/');
            expect(json).toContain("row.product_snapshot?.product_code");
            expect(json).toContain("/edit");
        });

        it('상품명이 A 태그로 상품수정 페이지 링크가 적용되어 있다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // A 컴포넌트가 product_name 텍스트를 포함하고 있어야 함
            expect(json).toContain('"name":"A"');
            expect(json).toContain('"text":"{{row.product_name}}"');
            // A 태그의 href에 상품수정 경로가 포함되어야 함
            expect(json).toContain("row.product_snapshot?.product_code");
        });

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

        it('합계행 수량이 order.data?.total_quantity를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.total_quantity');
        });

        it('합계행 판매가가 order.data?.subtotal_amount_formatted를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.subtotal_amount_formatted');
        });

        it('합계행 정가가 order.data?.total_list_price_formatted를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.total_list_price_formatted');
        });

        it('합계행 할인이 order.data?.total_discount_amount를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.total_discount_amount_formatted');
            expect(json).toContain('order.data?.total_coupon_discount_amount_formatted');
            expect(json).toContain('order.data?.total_code_discount_amount_formatted');
        });

        it('합계행 적립예정이 order.data?.total_earned_points_amount를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.total_earned_points_amount');
        });

        it('합계행 소계가 order.data?.subtotal_amount_formatted를 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // footerCells의 subtotal 필드
            expect(json).toContain('order.data?.subtotal_amount_formatted');
        });

        // ========== 개별 행 실구매가격 검증 ==========

        it('개별 행 실구매가격이 final_amount_formatted를 사용한다 (할인 후)', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('row.final_amount_formatted');
            // 할인 전 subtotal_price_formatted는 실구매가격에 사용되지 않아야 함
            // (소계 열에서는 여전히 사용)
        });

        it('개별 행 마일리지/예치금이 올바른 필드명을 사용한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('row.subtotal_points_used_amount');
            expect(json).toContain('row.subtotal_deposit_used_amount');
        });

        // ========== 다통화 표시 검증 ==========

        it('개별 행 판매가에 다통화 표시가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('row.mc_unit_price');
        });

        it('개별 행 소계에 다통화 표시가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('row.mc_subtotal_price');
        });

        it('합계행에 다통화 표시가 존재한다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.mc_subtotal_amount');
        });

        it('다통화 표시에 preferredCurrency 필터가 적용된다', () => {
            const json = JSON.stringify(orderInfoPartial);
            // 보조 통화만 표시 (기본 통화 제외)
            expect(json).toContain("_global.preferredCurrency ?? 'KRW'");
        });

        it('다통화 표시에 iteration 패턴이 사용된다 (engine-v1.19.1)', () => {
            const json = JSON.stringify(orderInfoPartial);
            // DataGrid 셀 내부에서 iteration 패턴 사용 (엔진 수정으로 가능)
            expect(json).toContain('"item_var":"currency"');
            expect(json).toContain('currency[1]?.formatted');
            // .map().join() 워크어라운드가 아닌 iteration 패턴 확인
            expect(json).not.toContain('.map(([code, val]) => val?.formatted).filter(Boolean).join');
        });

        // ========== 합계행 세부 표시 검증 ==========

        it('합계행 실구매가격에 세금/마일리지/예치금 세부 표시가 있다', () => {
            const json = JSON.stringify(orderInfoPartial);
            expect(json).toContain('order.data?.total_tax_amount');
            expect(json).toContain('order.data?.total_vat_amount');
            expect(json).toContain('order.data?.total_points_used_amount');
            expect(json).toContain('order.data?.total_deposit_used_amount');
        });

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
    });

    describe('_partial_payment_info.json (결제정보 탭)', () => {
        it('최상위에 type과 name이 정의되어 있다', () => {
            expect(paymentInfoPartial.type).toBe('basic');
            expect(paymentInfoPartial.name).toBe('Div');
            expect(paymentInfoPartial.children).toBeDefined();
        });

        it('결제 카드가 payments iteration을 사용한다', () => {
            const json = JSON.stringify(paymentInfoPartial);
            expect(json).toContain('payments');
            expect(json).toContain('iteration');
        });

        it('주문 금액은 order.data 경로를 사용한다', () => {
            const json = JSON.stringify(paymentInfoPartial);
            expect(json).toContain('order.data?.subtotal_amount_formatted');
            expect(json).toContain('order.data?.total_shipping_amount_formatted');
            expect(json).toContain('order.data?.total_paid_amount_formatted');
        });

        it('결제 고유 필드는 payment 경로를 유지한다', () => {
            const json = JSON.stringify(paymentInfoPartial);
            expect(json).toContain('payment.payment_method_label');
            expect(json).toContain('payment.paid_at_formatted');
            expect(json).toContain('payment.payment_status_label');
        });
    });

    describe('_partial_activity_log.json (활동 로그 탭)', () => {
        it('최상위에 type과 name이 정의되어 있다', () => {
            expect(activityLogPartial.type).toBe('basic');
            expect(activityLogPartial.name).toBe('Div');
            expect(activityLogPartial.children).toBeDefined();
        });

        it('정렬 드롭다운이 존재한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('logsSort');
            expect(json).toContain('date_desc');
            expect(json).toContain('date_asc');
        });

        it('페이지당 드롭다운이 존재한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('logsPerPage');
            expect(json).toContain('per_page_option');
        });

        it('로그 iteration이 order_logs API 데이터를 소스로 사용한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('order_logs.data');
            expect(json).toContain('iteration');
            expect(json).not.toContain('dummyLogs');
        });

        it('ActivityLogResource 필드를 사용한다 (created_at, user.name, description)', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('log.created_at');
            expect(json).toContain('log.user?.name');
            expect(json).toContain('log.localized_description');
            expect(json).not.toContain('created_at_formatted');
        });

        it('처리자 열이 아바타 원형 + 이름 스타일을 사용한다', () => {
            const json = JSON.stringify(activityLogPartial);
            // 아바타 원형 (rounded-full)
            expect(json).toContain('rounded-full');
            expect(json).toContain('flex items-center justify-center');
            // 이름 첫 글자 추출 표현식
            expect(json).toContain('.charAt(0).toUpperCase()');
        });

        it('Badge가 아닌 ActionMenu를 사용한다 (log_type_label Badge 제거)', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).not.toContain('"name":"Badge"');
            expect(json).toContain('"name":"ActionMenu"');
        });

        it('ActionMenu가 user.uuid가 있는 경우에만 표시된다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('!!log.user?.uuid');
            expect(json).toContain('!log.user?.uuid');
        });

        it('ActionMenu에 회원정보 보기 메뉴가 있다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('view_member');
            expect(json).toContain('actor_action.view_member');
        });

        it('회원 클릭 시 openWindow로 회원 상세 페이지를 연다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('"handler":"openWindow"');
            expect(json).toContain('/admin/users/{{log.user.uuid}}');
        });

        it('시스템 사용자는 ActionMenu 없이 아바타+이름만 표시된다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('activity_log.system');
        });

        it('빈 상태 메시지가 존재한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('activity_log.empty');
        });

        it('Pagination 컴포넌트가 존재한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('logsPage');
            expect(json).toContain('"name":"Pagination"');
            expect(json).toContain('currentPage');
            expect(json).toContain('totalPages');
        });
    });

    describe('모달 레이아웃 검증', () => {
        it('일괄변경 모달에 processOrderDetailBulkChange 핸들러가 연결되어 있다', () => {
            const json = JSON.stringify(batchChangeModal);
            expect(json).toContain('"handler":"sirsoft-ecommerce.processOrderDetailBulkChange"');
        });

        it('일괄변경 모달에 closeModal 핸들러가 연결되어 있다', () => {
            const json = JSON.stringify(batchChangeModal);
            expect(json).toContain('"handler":"closeModal"');
        });

        it('SMS 모달에 smsPhone 바인딩이 있다', () => {
            const json = JSON.stringify(smsModal);
            expect(json).toContain('smsPhone');
        });

        it('이메일 모달에 emailAddress 바인딩이 있다', () => {
            const json = JSON.stringify(emailModal);
            expect(json).toContain('emailAddress');
        });
    });
});
