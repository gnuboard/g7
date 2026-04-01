/**
 * 주문설정 탭 레이아웃 구조 검증 테스트
 *
 * @description
 * - _tab_order_settings.json 5개 카드 섹션 구조 검증
 * - 결제수단 Sortable 리스트 구조 검증
 * - 계좌번호 테이블 구조 검증
 * - 은행 관리 모달 구조 검증
 * - 폼 바인딩 및 핸들러 검증
 * - 다국어 키 검증
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import tabOrderSettings from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_order_settings.json';
import paymentMethodsList from '../../../layouts/admin/partials/admin_ecommerce_settings/_payment_methods_list.json';
import paymentMethodsCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_payment_methods_cards.json';
import bankAccountsTable from '../../../layouts/admin/partials/admin_ecommerce_settings/_bank_accounts_table.json';
import bankAccountsCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_bank_accounts_cards.json';
import bankManagementModal from '../../../layouts/admin/partials/admin_ecommerce_settings/_bank_management_modal.json';

/**
 * 재귀적으로 컴포넌트 트리에서 id로 검색
 */
function findById(node: any, id: string): any | null {
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

/**
 * 재귀적으로 컴포넌트 트리에서 name으로 모든 항목 검색
 */
function findAllByName(node: any, name: string): any[] {
    const results: any[] = [];
    if (!node) return results;
    if (node.name === name) results.push(node);
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            results.push(...findAllByName(child, name));
        }
    }
    if (node.itemTemplate) {
        results.push(...findAllByName(node.itemTemplate, name));
    }
    return results;
}

/**
 * 재귀적으로 $t: 다국어 키 수집
 */
function collectI18nKeys(node: any): string[] {
    const keys: string[] = [];
    if (!node) return keys;

    // text 속성에서 $t: 키 추출
    if (typeof node.text === 'string' && node.text.startsWith('$t:')) {
        keys.push(node.text.replace('$t:', ''));
    }
    // props 내부 문자열 검색
    if (node.props) {
        for (const val of Object.values(node.props)) {
            if (typeof val === 'string' && val.startsWith('$t:')) {
                keys.push(val.replace('$t:', ''));
            }
            // options 배열 내부
            if (Array.isArray(val)) {
                for (const opt of val) {
                    if (opt && typeof opt.label === 'string' && opt.label.startsWith('$t:')) {
                        keys.push(opt.label.replace('$t:', ''));
                    }
                }
            }
        }
    }

    // 자식/itemTemplate 재귀
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            keys.push(...collectI18nKeys(child));
        }
    }
    if (node.itemTemplate) {
        keys.push(...collectI18nKeys(node.itemTemplate));
    }
    return keys;
}

// ─── _tab_order_settings.json 구조 검증 ───

describe('주문설정 탭 구조 검증 (_tab_order_settings.json)', () => {
    const tab = tabOrderSettings as any;

    describe('탭 메인 구조', () => {
        it('is_partial 메타데이터가 설정되어야 한다', () => {
            expect(tab.meta.is_partial).toBe(true);
        });

        it('tab_content_order_settings ID를 가져야 한다', () => {
            expect(tab.id).toBe('tab_content_order_settings');
        });

        it('order_settings 탭 활성화 조건이 있어야 한다', () => {
            expect(tab.if).toContain('order_settings');
        });

        it('5개 카드 섹션을 포함해야 한다', () => {
            expect(tab.children).toHaveLength(5);
        });
    });

    describe('카드 ID 검증', () => {
        it('결제수단 설정 카드가 존재해야 한다', () => {
            expect(tab.children[0].id).toBe('payment_methods_card');
        });

        it('무통장 계좌번호 설정 카드가 존재해야 한다', () => {
            expect(tab.children[1].id).toBe('bank_accounts_card');
        });

        it('주문 자동취소 카드가 존재해야 한다', () => {
            expect(tab.children[2].id).toBe('auto_cancel_card');
        });

        it('장바구니 유효기간 카드가 존재해야 한다', () => {
            expect(tab.children[3].id).toBe('cart_expiry_card');
        });

        it('재고 관리 카드가 존재해야 한다', () => {
            expect(tab.children[4].id).toBe('stock_management_card');
        });
    });

    describe('결제수단 카드 구조', () => {
        const card = tab.children[0];

        it('card 클래스를 가져야 한다', () => {
            expect(card.props.className).toBe('card');
        });

        it('카드 헤더에 제목과 설명이 있어야 한다', () => {
            const header = card.children[0];
            expect(header.props.className).toBe('card-header');
            expect(header.children[0].text).toBe(
                '$t:sirsoft-ecommerce.admin.settings.order_settings.payment_methods.title',
            );
            expect(header.children[1].text).toBe(
                '$t:sirsoft-ecommerce.admin.settings.order_settings.payment_methods.description',
            );
        });

        it('PC/모바일 반응형 분기가 있어야 한다', () => {
            const content = card.children[1];
            // PC: partial → _payment_methods_list.json
            expect(content.children[0].partial).toContain('_payment_methods_list.json');
            // 모바일: responsive.portable → _payment_methods_cards.json
            expect(content.responsive?.portable?.children[0].partial).toContain(
                '_payment_methods_cards.json',
            );
        });
    });

    describe('계좌번호 카드 구조', () => {
        const card = tab.children[1];

        it('은행 관리 버튼이 openModal 핸들러를 사용해야 한다', () => {
            const headerButtons = card.children[0].children[1];
            const bankManageBtn = headerButtons.children[0];
            expect(bankManageBtn.actions[0].handler).toBe('openModal');
            expect(bankManageBtn.actions[0].params.id).toBe('bank_management_modal');
        });

        it('계좌 추가 버튼이 setState로 빈 계좌를 추가해야 한다', () => {
            const headerButtons = card.children[0].children[1];
            const addBtn = headerButtons.children[1];
            expect(addBtn.actions[0].handler).toBe('setState');
            expect(addBtn.actions[0].params['form.order_settings.bank_accounts']).toContain(
                'bank_code',
            );
        });

        it('PC/모바일 반응형 분기가 있어야 한다', () => {
            const content = card.children[1];
            expect(content.children[0].partial).toContain('_bank_accounts_table.json');
            expect(content.responsive?.portable?.children[0].partial).toContain(
                '_bank_accounts_cards.json',
            );
        });
    });

    describe('주문 자동취소 카드 구조', () => {
        const card = tab.children[2];

        it('자동취소 Toggle이 폼 자동바인딩 name을 사용해야 한다', () => {
            const toggleSection = card.children[1].children[0];
            const toggle = toggleSection.children[1];
            expect(toggle.name).toBe('Toggle');
            expect(toggle.props.name).toBe('order_settings.auto_cancel_expired');
        });

        it('자동취소 기한 섹션이 auto_cancel_expired 조건부 표시여야 한다', () => {
            const daysSection = card.children[1].children[1];
            expect(daysSection.if).toContain('auto_cancel_expired');
        });

        it('자동취소일 Input이 min=1, max=30이어야 한다', () => {
            const daysSection = card.children[1].children[1];
            const input = daysSection.children[1];
            expect(input.name).toBe('Input');
            expect(input.props.name).toBe('order_settings.auto_cancel_days');
            expect(input.props.min).toBe(1);
            expect(input.props.max).toBe(30);
        });

        it('가상계좌 입금기한 Input이 올바른 name을 가져야 한다', () => {
            // 구분선(인덱스 2) 뒤 가상계좌(인덱스 3)
            const vbankSection = card.children[1].children[3];
            const vbankInput = vbankSection.children[1];
            expect(vbankInput.props.name).toBe('order_settings.vbank_due_days');
        });

        it('무통장 입금기한 Input이 올바른 name을 가져야 한다', () => {
            const dbankSection = card.children[1].children[4];
            const dbankInput = dbankSection.children[1];
            expect(dbankInput.props.name).toBe('order_settings.dbank_due_days');
        });
    });

    describe('장바구니 유효기간 카드 구조', () => {
        const card = tab.children[3];

        it('cart_expiry_days Input이 min=1, max=365이어야 한다', () => {
            const content = card.children[1];
            const input = content.children[1];
            expect(input.props.name).toBe('order_settings.cart_expiry_days');
            expect(input.props.min).toBe(1);
            expect(input.props.max).toBe(365);
        });
    });

    describe('재고 관리 카드 구조', () => {
        const card = tab.children[4];

        it('재고 복구 Toggle이 폼 자동바인딩 name을 사용해야 한다', () => {
            const content = card.children[1];
            const toggle = content.children[1];
            expect(toggle.name).toBe('Toggle');
            expect(toggle.props.name).toBe('order_settings.stock_restore_on_cancel');
        });
    });
});

// ─── _payment_methods_list.json (PC Sortable) 구조 검증 ───

describe('결제수단 Sortable 리스트 구조 검증 (_payment_methods_list.json)', () => {
    const layout = paymentMethodsList as any;

    describe('Sortable 설정', () => {
        it('sortable source가 payment_methods를 참조해야 한다', () => {
            expect(layout.sortable.source).toContain('payment_methods');
        });

        it('sortable itemKey가 id여야 한다', () => {
            expect(layout.sortable.itemKey).toBe('id');
        });

        it('수직 리스트 전략을 사용해야 한다', () => {
            expect(layout.sortable.strategy).toBe('verticalList');
        });

        it('드래그 핸들 셀렉터가 [data-drag-handle]이어야 한다', () => {
            expect(layout.sortable.handle).toBe('[data-drag-handle]');
        });

        it('onSortEnd 시 sort_order를 업데이트해야 한다', () => {
            const sortEndAction = layout.actions[0];
            expect(sortEndAction.event).toBe('onSortEnd');
            expect(sortEndAction.handler).toBe('setState');
            expect(sortEndAction.params['form.order_settings.payment_methods']).toContain(
                'sort_order',
            );
        });
    });

    describe('itemTemplate 구조', () => {
        const tpl = layout.itemTemplate;

        it('고아 항목 classMap이 정의되어야 한다', () => {
            expect(tpl.classMap).toBeDefined();
            expect(tpl.classMap.key).toContain('_orphaned');
            expect(tpl.classMap.variants.orphaned).toContain('opacity-60');
        });

        it('드래그 핸들이 고아가 아닐 때만 표시되어야 한다', () => {
            const handle = tpl.children[0];
            expect(handle.if).toContain('!$method._orphaned');
            expect(handle.props['data-drag-handle']).toBe(true);
        });

        it('아이콘이 _cached_icon을 사용해야 한다', () => {
            const icon = tpl.children[1];
            expect(icon.props.name).toContain('_cached_icon');
        });

        it('이름이 $localized + _cached_name을 사용해야 한다', () => {
            const nameDiv = tpl.children[2];
            const nameSpan = nameDiv.children[0].children[0];
            expect(nameSpan.text).toContain('$localized');
            expect(nameSpan.text).toContain('_cached_name');
        });

        it('고아 배지가 _orphaned 조건에서만 표시되어야 한다', () => {
            const nameDiv = tpl.children[2];
            const badge = nameDiv.children[0].children[1];
            expect(badge.if).toContain('$method._orphaned');
            expect(badge.text).toBe(
                '$t:sirsoft-ecommerce.admin.settings.order_settings.payment_methods.orphaned_badge',
            );
        });

        it('재고차감시점 Select가 2개 옵션을 가져야 한다', () => {
            // children[3] = 재고차감시점 Div (if: !_orphaned)
            const timingDiv = tpl.children[3];
            expect(timingDiv.if).toContain('!$method._orphaned');
            const select = timingDiv.children[1];
            expect(select.name).toBe('Select');
            expect(select.props.options).toHaveLength(2);
            expect(select.props.options[0].value).toBe('order_placed');
            expect(select.props.options[1].value).toBe('payment_complete');
        });

        it('재고차감시점 변경이 setState로 배열 전체를 업데이트해야 한다', () => {
            const select = tpl.children[3].children[1];
            const action = select.actions[0];
            expect(action.handler).toBe('setState');
            expect(action.params['form.order_settings.payment_methods']).toContain(
                'stock_deduction_timing',
            );
        });

        it('최소주문금액 Input이 있어야 한다', () => {
            const amountDiv = tpl.children[4];
            expect(amountDiv.if).toContain('!$method._orphaned');
            const input = amountDiv.children[1];
            expect(input.name).toBe('Input');
            expect(input.props.type).toBe('number');
        });

        it('사용여부 Toggle이 setState로 is_active를 토글해야 한다', () => {
            const toggleDiv = tpl.children[5];
            expect(toggleDiv.if).toContain('!$method._orphaned');
            const toggle = toggleDiv.children[0];
            expect(toggle.name).toBe('Toggle');
            const action = toggle.actions[0];
            expect(action.handler).toBe('setState');
            expect(action.params['form.order_settings.payment_methods']).toContain('is_active');
        });

        it('고아 항목 삭제 버튼이 _orphaned 조건에서만 표시되어야 한다', () => {
            const deleteBtn = tpl.children[6];
            expect(deleteBtn.if).toContain('$method._orphaned');
            expect(deleteBtn.actions[0].handler).toBe('setState');
            expect(deleteBtn.actions[0].params['form.order_settings.payment_methods']).toContain(
                'filter',
            );
        });
    });
});

// ─── _payment_methods_cards.json (모바일 Sortable) 구조 검증 ───

describe('결제수단 모바일 카드 구조 검증 (_payment_methods_cards.json)', () => {
    const layout = paymentMethodsCards as any;

    it('sortable 설정이 PC와 동일한 source를 사용해야 한다', () => {
        expect(layout.sortable.source).toContain('payment_methods');
        expect(layout.sortable.itemKey).toBe('id');
    });

    it('카드형 itemTemplate을 가져야 한다', () => {
        expect(layout.itemTemplate).toBeDefined();
    });

    it('고아 항목 classMap이 정의되어야 한다', () => {
        expect(layout.itemTemplate.classMap).toBeDefined();
        expect(layout.itemTemplate.classMap.variants.orphaned).toBeDefined();
    });
});

// ─── _bank_accounts_table.json (PC 테이블) 구조 검증 ───

describe('계좌번호 테이블 구조 검증 (_bank_accounts_table.json)', () => {
    const layout = bankAccountsTable as any;
    const table = layout.children[0];

    describe('테이블 헤더', () => {
        it('Table 컴포넌트를 사용해야 한다', () => {
            expect(table.name).toBe('Table');
            expect(table.props.className).toBe('table');
        });

        it('6개 컬럼 헤더를 가져야 한다', () => {
            const thead = table.children[0];
            const headerRow = thead.children[0];
            expect(headerRow.children).toHaveLength(6);
        });

        it('헤더가 올바른 다국어 키를 사용해야 한다', () => {
            const headerRow = table.children[0].children[0];
            const headers = headerRow.children;
            // 기본, 은행, 계좌번호, 예금주, 사용, (삭제는 텍스트 없음)
            expect(headers[0].text).toContain('bank_accounts.is_default');
            expect(headers[1].text).toContain('bank_accounts.bank');
            expect(headers[2].text).toContain('bank_accounts.account_number');
            expect(headers[3].text).toContain('bank_accounts.account_holder');
            expect(headers[4].text).toContain('bank_accounts.is_active');
        });
    });

    describe('테이블 바디', () => {
        const tbody = table.children[1];

        it('빈 상태 메시지가 있어야 한다', () => {
            const emptyRow = tbody.children[0];
            expect(emptyRow.id).toBe('no_accounts_message');
            expect(emptyRow.if).toContain('bank_accounts');
            expect(emptyRow.if).toContain('length === 0');
        });

        it('iteration이 bank_accounts를 순회해야 한다', () => {
            const dataRow = tbody.children[1];
            expect(dataRow.iteration).toBeDefined();
            expect(dataRow.iteration.source).toContain('bank_accounts');
            expect(dataRow.iteration.item_var).toBe('account');
            expect(dataRow.iteration.index_var).toBe('accountIndex');
        });

        it('기본 라디오 버튼이 setState로 is_default를 변경해야 한다', () => {
            const dataRow = tbody.children[1];
            const defaultTd = dataRow.children[0];
            const btn = defaultTd.children[0];
            expect(btn.actions[0].handler).toBe('setState');
            expect(btn.actions[0].params['form.order_settings.bank_accounts']).toContain(
                'is_default',
            );
        });

        it('은행 Select가 동적 name 속성을 사용해야 한다', () => {
            const dataRow = tbody.children[1];
            const bankTd = dataRow.children[1];
            const select = bankTd.children[0];
            expect(select.props.name).toContain('order_settings.bank_accounts');
            expect(select.props.name).toContain('bank_code');
        });

        it('계좌번호 Input이 동적 name 속성을 사용해야 한다', () => {
            const dataRow = tbody.children[1];
            const accountTd = dataRow.children[2];
            const input = accountTd.children[0];
            expect(input.props.name).toContain('account_number');
        });

        it('예금주 Input이 동적 name 속성을 사용해야 한다', () => {
            const dataRow = tbody.children[1];
            const holderTd = dataRow.children[3];
            const input = holderTd.children[0];
            expect(input.props.name).toContain('account_holder');
        });

        it('사용여부 Toggle이 동적 name 속성을 사용해야 한다', () => {
            const dataRow = tbody.children[1];
            const activeTd = dataRow.children[4];
            const toggle = activeTd.children[0];
            expect(toggle.name).toBe('Toggle');
            expect(toggle.props.name).toContain('is_active');
        });

        it('삭제 버튼이 계좌 2개 이상일 때만 표시되어야 한다', () => {
            const dataRow = tbody.children[1];
            const deleteTd = dataRow.children[5];
            const btn = deleteTd.children[0];
            expect(btn.if).toContain('length > 1');
            expect(btn.actions[0].handler).toBe('setState');
            expect(btn.actions[0].params['form.order_settings.bank_accounts']).toContain('filter');
        });
    });
});

// ─── _bank_accounts_cards.json (모바일 카드) 구조 검증 ───

describe('계좌번호 모바일 카드 구조 검증 (_bank_accounts_cards.json)', () => {
    const layout = bankAccountsCards as any;

    it('iteration이 bank_accounts를 순회해야 한다', () => {
        const cardContainer = layout.children[1];
        expect(cardContainer.iteration).toBeDefined();
        expect(cardContainer.iteration.source).toContain('bank_accounts');
        expect(cardContainer.iteration.item_var).toBe('account');
    });

    it('빈 상태 메시지가 있어야 한다', () => {
        const emptyMsg = layout.children[0];
        expect(emptyMsg.if).toContain('bank_accounts');
        expect(emptyMsg.if).toContain('length === 0');
    });

    it('excel-card 클래스를 사용해야 한다', () => {
        const cardContainer = layout.children[1];
        expect(cardContainer.props.className).toBe('excel-card');
    });
});

// ─── _bank_management_modal.json 구조 검증 ───

describe('은행 관리 모달 구조 검증 (_bank_management_modal.json)', () => {
    const modal = bankManagementModal as any;

    it('Modal 컴포넌트 타입이어야 한다', () => {
        expect(modal.type).toBe('composite');
        expect(modal.name).toBe('Modal');
    });

    it('bank_management_modal ID를 가져야 한다', () => {
        expect(modal.id).toBe('bank_management_modal');
    });

    it('모달 제목이 다국어 키를 사용해야 한다', () => {
        expect(modal.props.title).toBe(
            '$t:sirsoft-ecommerce.admin.settings.order_settings.bank_management.modal_title',
        );
    });

    describe('은행 목록 iteration', () => {
        const content = modal.children[0];
        const listContainer = content.children[0];
        const iterationDiv = listContainer.children[0];

        it('$parent._local의 banks를 순회해야 한다', () => {
            expect(iterationDiv.iteration).toBeDefined();
            expect(iterationDiv.iteration.source).toContain('$parent._local');
            expect(iterationDiv.iteration.source).toContain('banks');
            expect(iterationDiv.iteration.item_var).toBe('bank');
        });

        it('은행 코드/한국어/영문 3개 Input이 있어야 한다', () => {
            const inputs = findAllByName(iterationDiv, 'Input');
            expect(inputs.length).toBe(3);
        });

        it('모든 Input이 $parent._local 대상으로 setState해야 한다', () => {
            const inputs = findAllByName(iterationDiv, 'Input');
            for (const input of inputs) {
                if (input.actions) {
                    const action = input.actions[0];
                    expect(action.handler).toBe('setState');
                    expect(action.params.target).toBe('$parent._local');
                }
            }
        });

        it('삭제 버튼이 $parent._local 대상으로 filter해야 한다', () => {
            const buttons = findAllByName(iterationDiv, 'Button');
            const deleteBtn = buttons.find(
                (b: any) => b.actions?.[0]?.params?.['form.order_settings.banks']?.includes('filter'),
            );
            expect(deleteBtn).toBeDefined();
            expect(deleteBtn.actions[0].params.target).toBe('$parent._local');
        });
    });

    describe('추가/닫기 버튼', () => {
        const content = modal.children[0];

        it('은행 추가 버튼이 $parent._local에 빈 은행을 추가해야 한다', () => {
            const addBtn = content.children[1];
            expect(addBtn.actions[0].handler).toBe('setState');
            expect(addBtn.actions[0].params.target).toBe('$parent._local');
            expect(addBtn.actions[0].params['form.order_settings.banks']).toContain("code: ''");
        });

        it('확인 버튼이 closeModal을 호출해야 한다', () => {
            const confirmBtn = content.children[2];
            expect(confirmBtn.actions[0].handler).toBe('closeModal');
            expect(confirmBtn.actions[0].params.id).toBe('bank_management_modal');
        });
    });
});

// ─── 다국어 키 종합 검증 ───

describe('다국어 키 종합 검증', () => {
    const prefix = 'sirsoft-ecommerce.admin.settings.order_settings';

    it('탭 레이아웃의 모든 다국어 키가 order_settings 네임스페이스를 사용해야 한다', () => {
        const keys = collectI18nKeys(tabOrderSettings);
        for (const key of keys) {
            expect(key).toMatch(new RegExp(`^${prefix.replace(/\./g, '\\.')}`));
        }
    });

    it('결제수단 리스트의 다국어 키가 payment_methods 하위여야 한다', () => {
        const keys = collectI18nKeys(paymentMethodsList);
        for (const key of keys) {
            expect(key).toContain('order_settings');
        }
    });

    it('은행 관리 모달의 다국어 키가 bank_management 하위여야 한다', () => {
        const keys = collectI18nKeys(bankManagementModal);
        const managementKeys = keys.filter((k) => k.includes('bank_management'));
        expect(managementKeys.length).toBeGreaterThan(0);
    });
});
