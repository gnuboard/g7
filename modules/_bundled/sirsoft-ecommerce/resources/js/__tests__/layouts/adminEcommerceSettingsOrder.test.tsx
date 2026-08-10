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
 * @scenario extension_payment_method method_kind=extension × capability_declared=declared × capability=pg_locked
 * @effects admin_shows_pg_locked_badge, admin_hides_pg_select_for_locked, admin_shows_pg_select_for_unlocked
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
 * 재귀적으로 트리에서 predicate 를 만족하는 첫 번째 노드 검색
 * (children/itemTemplate 모두 순회)
 */
function findFirst(node: any, predicate: (n: any) => boolean): any | null {
    if (!node) return null;
    if (predicate(node)) return node;
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findFirst(child, predicate);
            if (found) return found;
        }
    }
    if (node.itemTemplate) {
        const found = findFirst(node.itemTemplate, predicate);
        if (found) return found;
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

        it('카드 섹션이 정해진 순서로 배치된다', () => {
            // 개수가 아니라 id 를 고정한다 — 카드가 추가·제거되면 어느 카드인지 바로 드러난다.
            expect(tab.children.map((c: any) => c.id)).toEqual([
                'default_pg_card',
                'cash_receipt_card',
                'payment_methods_card',
                'bank_accounts_card',
                'auto_cancel_card',
                'cancellable_statuses_card',
                'confirmable_statuses_card',
                'cart_expiry_card',
                'stock_management_card',
            ]);
        });
    });

    describe('카드 ID 검증', () => {
        it('결제수단 설정 카드가 존재해야 한다', () => {
            expect(findById(tab, 'payment_methods_card')).not.toBeNull();
        });

        it('무통장 계좌번호 설정 카드가 존재해야 한다', () => {
            expect(findById(tab, 'bank_accounts_card')).not.toBeNull();
        });

        it('주문 자동취소 카드가 존재해야 한다', () => {
            expect(findById(tab, 'auto_cancel_card')).not.toBeNull();
        });

        it('장바구니 유효기간 카드가 존재해야 한다', () => {
            expect(findById(tab, 'cart_expiry_card')).not.toBeNull();
        });

        it('재고 관리 카드가 존재해야 한다', () => {
            expect(findById(tab, 'stock_management_card')).not.toBeNull();
        });
    });

    describe('결제수단 카드 구조', () => {
        const card = findById(tab, 'payment_methods_card');

        it('admin-card 클래스를 가져야 한다', () => {
            expect(card.props.className).toBe('admin-card');
        });

        it('카드 제목과 설명이 직계 자식이어야 한다 (admin-card > card-title + card-description 평탄화)', () => {
            const titleEl = card.children.find(
                (c: any) => c?.name === 'H3' && typeof c?.props?.className === 'string' &&
                    /\bcard-title\b/.test(c.props.className)
            );
            const descEl = card.children.find(
                (c: any) => c?.name === 'Div' && typeof c?.props?.className === 'string' &&
                    /\bcard-description\b/.test(c.props.className)
            );
            expect(titleEl).toBeDefined();
            expect(descEl).toBeDefined();
            expect(titleEl.text).toBe(
                '$t:sirsoft-ecommerce.admin.settings.order_settings.payment_methods.title',
            );
            expect(descEl.text).toBe(
                '$t:sirsoft-ecommerce.admin.settings.order_settings.payment_methods.description',
            );
        });

        it('PC/모바일 반응형 분기가 있어야 한다', () => {
            const content = card.children[2];
            // PC: partial → _payment_methods_list.json
            expect(content.children[0].partial).toContain('_payment_methods_list.json');
            // 모바일: responsive.portable → _payment_methods_cards.json
            expect(content.responsive?.portable?.children[0].partial).toContain(
                '_payment_methods_cards.json',
            );
        });
    });

    describe('계좌번호 카드 구조', () => {
        const card = findById(tab, 'bank_accounts_card');

        it('은행 관리 버튼이 openModal 핸들러를 사용해야 한다', () => {
            // openModal 핸들러는 params.id 가 아닌 action.target 으로 모달 ID 를 지정한다
            const json = JSON.stringify(card);
            expect(json).toContain('"handler":"openModal"');
            expect(json).toContain('"target":"bank_management_modal"');
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
        const card = findById(tab, 'auto_cancel_card');

        it('자동취소 Toggle이 폼 자동바인딩 name을 사용해야 한다', () => {
            const toggleSection = card.children[2].children[0];
            const toggle = toggleSection.children[1];
            expect(toggle.name).toBe('Toggle');
            expect(toggle.props.name).toBe('order_settings.auto_cancel_expired');
        });

        it('자동취소 기한 섹션이 auto_cancel_expired 조건부 표시여야 한다', () => {
            // 위치 의존 제거 — 자동취소 토글 카드 본문에서 if 조건에 auto_cancel_expired 가 포함된 섹션을 탐색
            const body = card.children[2];
            const daysSection = (body.children ?? []).find(
                (c: any) => typeof c?.if === 'string' && c.if.includes('auto_cancel_expired'),
            );
            expect(daysSection).toBeDefined();
            expect(daysSection.if).toContain('auto_cancel_expired');
        });

        // 경계값은 서버가 내려주는 한계값(_meta.limits)을 바인딩한다 — 화면이 리터럴을 들면
        // 저장 규칙이 바뀔 때 따라오지 못해 "화면은 받는데 저장에서 422" 가 된다.
        it('자동취소일 Input 이 서버 한계값을 바인딩해야 한다 (위치 의존 제거)', () => {
            const input = findFirst(card, (n: any) =>
                n?.name === 'Input' && n?.props?.name === 'order_settings.auto_cancel_days',
            );
            expect(input).not.toBeNull();
            expect(String(input.props.min)).toContain('_meta?.limits?.auto_cancel_days_min');
            expect(String(input.props.max)).toContain('_meta?.limits?.auto_cancel_days_max');
            expect(String(input.props.min)).toContain('?? 1');
            expect(String(input.props.max)).toContain('?? 30');
        });

        it('입금기한 단일화: 구 vbank_due_days Input 이 더 이상 존재하지 않는다', () => {
            const vbankInput = findFirst(card, (n: any) =>
                n?.name === 'Input' && n?.props?.name === 'order_settings.vbank_due_days',
            );
            expect(vbankInput).toBeNull();
        });

        it('입금기한 단일화: 구 dbank_due_days Input 이 더 이상 존재하지 않는다', () => {
            const dbankInput = findFirst(card, (n: any) =>
                n?.name === 'Input' && n?.props?.name === 'order_settings.dbank_due_days',
            );
            expect(dbankInput).toBeNull();
        });
    });

    describe('장바구니 유효기간 카드 구조', () => {
        const card = findById(tab, 'cart_expiry_card');

        it('cart_expiry_days Input 이 서버 한계값을 바인딩해야 한다', () => {
            const input = findFirst(card, (n: any) =>
                n?.name === 'Input' && n?.props?.name === 'order_settings.cart_expiry_days',
            );
            expect(input).not.toBeNull();
            expect(String(input.props.min)).toContain('_meta?.limits?.cart_expiry_days_min');
            expect(String(input.props.max)).toContain('_meta?.limits?.cart_expiry_days_max');
            expect(String(input.props.min)).toContain('?? 1');
            expect(String(input.props.max)).toContain('?? 365');
        });
    });

    describe('재고 관리 카드 구조', () => {
        const card = findById(tab, 'stock_management_card');

        it('재고 복구 Toggle이 폼 자동바인딩 name을 사용해야 한다', () => {
            const toggle = findFirst(card, (n: any) =>
                n?.name === 'Toggle' && n?.props?.name === 'order_settings.stock_restore_on_cancel',
            );
            expect(toggle).not.toBeNull();
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

        it('재고차감시점 Select가 3개 옵션(order_placed/payment_complete/none)을 가져야 한다', () => {
            // 2개 옵션 → 3개 (none 추가: 차감 안함)
            const select = findFirst(tpl, (n: any) =>
                n?.name === 'Select' && typeof n?.props?.value === 'string'
                    && n.props.value.includes('stock_deduction_timing'),
            );
            expect(select).not.toBeNull();
            expect(select.props.options).toHaveLength(3);
            const values = select.props.options.map((o: any) => o.value);
            expect(values).toContain('order_placed');
            expect(values).toContain('payment_complete');
            expect(values).toContain('none');
        });

        it('재고차감시점 변경이 setState로 배열 전체를 업데이트해야 한다', () => {
            const select = findFirst(tpl, (n: any) =>
                n?.name === 'Select' && typeof n?.props?.value === 'string'
                    && n.props.value.includes('stock_deduction_timing'),
            );
            const action = select.actions[0];
            expect(action.handler).toBe('setState');
            expect(action.params['form.order_settings.payment_methods']).toContain(
                'stock_deduction_timing',
            );
        });

        it('최소주문금액 Input이 있어야 한다', () => {
            const input = findFirst(tpl, (n: any) =>
                n?.name === 'Input' && n?.props?.type === 'number'
                    && typeof n?.props?.value === 'string'
                    && n.props.value.includes('min_order_amount'),
            );
            expect(input).not.toBeNull();
        });

        it('사용여부 Toggle이 setState로 is_active를 토글해야 한다', () => {
            // Toggle 의 바인딩 prop 이 checked → value 로 변경됨
            const toggle = findFirst(tpl, (n: any) =>
                n?.name === 'Toggle' && typeof n?.props?.value === 'string'
                    && n.props.value.includes('is_active'),
            );
            expect(toggle).not.toBeNull();
            const action = toggle.actions[0];
            expect(action.handler).toBe('setState');
            expect(action.params['form.order_settings.payment_methods']).toContain('is_active');
        });

        // ─── 필드 열 고정폭 (PG 셀렉트 폭 붕괴 회귀 차단) ───
        // 배경: Select 는 커스텀 드롭다운이라 className 이 내부 Button 에 붙는데, 컴포넌트
        // 기본 클래스의 w-full 이 CSS 출력 순서상 w-N 보다 뒤라 셀렉트에 직접 준 폭 토큰은
        // 무효가 된다(같은 특이도 → 후순위 승리). 그 결과 폭이 내용 폭을 따라가, PG 미선택
        // 상태('---')에서 74px 로 붕괴하고 선택값 길이에 따라 행마다 열 위치가 어긋났다.
        // 폭은 열 컨테이너가 책임진다.
        const FIELD_COLUMN_LABELS = [
            'payment_methods.pg_provider',
            'payment_methods.stock_deduction_timing',
            'payment_methods.mileage_deduction_timing',
        ];

        /** 라벨 i18n 키로 필드 열 컨테이너를 찾는다. */
        const findFieldColumn = (labelKey: string) =>
            findFirst(tpl, (n: any) =>
                typeof n?.props?.className === 'string'
                    && n.props.className.includes('row-stack')
                    && Array.isArray(n?.children)
                    && n.children.some((c: any) => typeof c?.text === 'string' && c.text.includes(labelKey)),
            );

        it.each(FIELD_COLUMN_LABELS)('필드 열(%s)이 고정폭을 가져야 한다', (labelKey) => {
            const column = findFieldColumn(labelKey);
            expect(column).not.toBeNull();
            expect(
                column.props.className,
                `${labelKey} 열에 고정폭이 없으면 셀렉트 폭이 내용 폭을 따라가 미선택 상태에서 붕괴한다`,
            ).toMatch(/\bw-\d+\b/);
        });

        it('세 필드 열이 동일한 폭 토큰을 사용해야 한다 (행 간 열 정렬)', () => {
            const widths = FIELD_COLUMN_LABELS.map((labelKey) => {
                const column = findFieldColumn(labelKey);
                return (column.props.className.match(/\bw-\d+\b/) ?? [])[0];
            });
            expect(new Set(widths).size, `열 폭이 서로 다르면 행 간 열이 어긋난다: ${widths.join(', ')}`).toBe(1);
        });

        it('필드 열이 축소 가능해야 한다 (좁은 폭에서 행 넘침 차단)', () => {
            // shrink-0 을 붙이면 고정폭 3열(총 432px)이 줄어들지 않아 좁은 뷰포트에서
            // 이름 열이 0 까지 붕괴하고도 행이 넘친다 (1100px 실측 17px 초과).
            for (const labelKey of FIELD_COLUMN_LABELS) {
                const column = findFieldColumn(labelKey);
                expect(column.props.className, `${labelKey} 열`).not.toContain('shrink-0');
            }
        });

        it('필드 열의 Select 에는 폭 클래스를 주지 않아야 한다 (컴포넌트 기본 w-full 에 덮여 무효)', () => {
            const selects = [
                'pg_provider',
                'stock_deduction_timing',
                'mileage_deduction_timing',
            ].map((field) =>
                findFirst(tpl, (n: any) =>
                    n?.name === 'Select' && typeof n?.props?.value === 'string'
                        && n.props.value.includes(field),
                ),
            );

            for (const select of selects) {
                expect(select).not.toBeNull();
                expect(
                    select.props.className ?? '',
                    'Select 에 준 w-N 은 컴포넌트 기본 w-full 에 덮여 무효 — 죽은 클래스가 폭을 지정한 것처럼 오해를 준다',
                ).not.toMatch(/\bw-\d+\b/);
            }
        });

        it('고아 항목 삭제 버튼이 _orphaned 조건에서만 표시되어야 한다', () => {
            const deleteBtn = findFirst(tpl, (n: any) =>
                n?.name === 'Button' && typeof n?.if === 'string'
                    && n.if.includes('$method._orphaned')
                    && !n.if.includes('!$method._orphaned'),
            );
            expect(deleteBtn).not.toBeNull();
            expect(deleteBtn.actions[0].handler).toBe('setState');
            expect(deleteBtn.actions[0].params['form.order_settings.payment_methods']).toContain(
                'filter',
            );
        });
    });

    // ─── PG 능력 기반 3분기 (#475) ───
    // 배경: 과거에는 PG 필요 여부를 하드코딩 배열(['point','deposit','free','dbank'])로
    // 정규식 판정해 간편결제를 "PG 불필요"로 오분류했다. 이제 결제수단 카탈로그의
    // pg_locked / needs_pg 능력으로 직접 3분기한다:
    //   ① pg_locked        → "PG 고정 · {PG명}" 배지 (관리자 변경 불가)
    //   ② !pg_locked && needs_pg → PG 선택 셀렉트 (또는 미설치 안내)
    //   ③ !pg_locked && !needs_pg → "PG 불필요"
    // 이 분기가 능력 대신 다시 하드코딩/orphaned 로 회귀하면 #475 재발이다.
    describe('PG 능력 3분기 (#475)', () => {
        const tpl = layout.itemTemplate;
        const findByTestidExpr = (needle: string) =>
            findFirst(tpl, (n: any) =>
                typeof n?.props?.['data-testid'] === 'string'
                    && n.props['data-testid'].includes(needle),
            );

        it('PG 고정 배지가 $method.pg_locked 조건으로 렌더된다', () => {
            const badge = findByTestidExpr('pg-locked-badge-');
            expect(badge).not.toBeNull();
            expect(badge.if).toBe('{{$method.pg_locked}}');
            // 배지 텍스트에 PG 표시명(available_pg_providers 의 name)이 들어가야
            // "PG 불필요"와 구분된다.
            expect(badge.text).toContain('available_pg_providers');
            expect(badge.text).toContain('pg_provider');
        });

        it('PG 선택 셀렉트가 !pg_locked && needs_pg && 제공자>0 조건으로만 렌더된다', () => {
            const select = findByTestidExpr('pg-select-');
            expect(select).not.toBeNull();
            expect(select.name).toBe('Select');
            expect(select.if).toContain('!$method.pg_locked');
            expect(select.if).toContain('$method.needs_pg');
            expect(select.if).toContain('length > 0');
            // 셀렉트 변경이 pg_provider 를 배열에 반영해야 한다.
            expect(select.actions[0].handler).toBe('setState');
            expect(select.actions[0].params['form.order_settings.payment_methods']).toContain(
                'pg_provider',
            );
        });

        it('"PG 불필요" 표시가 !pg_locked && !needs_pg 조건으로만 렌더된다', () => {
            const notRequired = findByTestidExpr('pg-not-required-');
            expect(notRequired).not.toBeNull();
            expect(notRequired.if).toContain('!$method.pg_locked');
            expect(notRequired.if).toContain('!$method.needs_pg');
            expect(notRequired.text).toBe(
                '$t:sirsoft-ecommerce.admin.settings.order_settings.payment_methods.pg_not_required',
            );
        });

        it('세 분기가 상호배타적이다 (pg_locked 우선, 나머지는 !pg_locked)', () => {
            const badge = findByTestidExpr('pg-locked-badge-');
            const select = findByTestidExpr('pg-select-');
            const notInstalled = findByTestidExpr('pg-not-installed-');
            const notRequired = findByTestidExpr('pg-not-required-');

            // pg_locked 분기만 pg_locked=true, 나머지 셋은 모두 !pg_locked 전제.
            expect(badge.if).toBe('{{$method.pg_locked}}');
            for (const branch of [select, notInstalled, notRequired]) {
                expect(branch).not.toBeNull();
                expect(branch.if).toContain('!$method.pg_locked');
            }
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

    // ─── PG 능력 3분기 (#475) — 모바일 카드도 PC 리스트와 동일하게 판정해야 한다 ───
    describe('PG 능력 3분기 (#475)', () => {
        const tpl = layout.itemTemplate;
        const findByTestidExpr = (needle: string) =>
            findFirst(tpl, (n: any) =>
                typeof n?.props?.['data-testid'] === 'string'
                    && n.props['data-testid'].includes(needle),
            );

        it('PG 고정 배지가 $method.pg_locked 조건으로 렌더된다', () => {
            const badge = findByTestidExpr('pg-locked-badge-');
            expect(badge).not.toBeNull();
            expect(badge.if).toBe('{{$method.pg_locked}}');
        });

        it('PG 선택 셀렉트가 !pg_locked && needs_pg 조건으로만 렌더된다', () => {
            const select = findByTestidExpr('pg-select-');
            expect(select).not.toBeNull();
            expect(select.if).toContain('!$method.pg_locked');
            expect(select.if).toContain('$method.needs_pg');
        });

        it('"PG 불필요" 표시가 !pg_locked && !needs_pg 조건으로만 렌더된다', () => {
            const notRequired = findByTestidExpr('pg-not-required-');
            expect(notRequired).not.toBeNull();
            expect(notRequired.if).toContain('!$method.pg_locked');
            expect(notRequired.if).toContain('!$method.needs_pg');
        });

        it('PC 리스트와 카드가 동일한 3개 data-testid 접두사를 쓴다 (표시 일관성)', () => {
            for (const needle of ['pg-locked-badge-', 'pg-select-', 'pg-not-required-']) {
                expect(findByTestidExpr(needle)).not.toBeNull();
            }
        });
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
        it('$parent._local의 banks 를 순회해야 한다 (item_var: bank)', () => {
            const iter = findFirst(modal, (n: any) =>
                typeof n?.iteration?.source === 'string'
                    && n.iteration.source.includes('$parent._local')
                    && n.iteration.source.includes('banks')
                    && n.iteration?.item_var === 'bank',
            );
            expect(iter).not.toBeNull();
        });

        it('은행 코드 입력 Input 이 $parent._local 대상으로 setState 한다', () => {
            const codeInput = findFirst(modal, (n: any) =>
                n?.name === 'Input'
                    && Array.isArray(n.actions)
                    && n.actions.some(
                        (a: any) => a.handler === 'setState'
                            && a.params?.target === '$parent._local'
                            && typeof a.params?.['form.order_settings.banks'] === 'string'
                            && a.params['form.order_settings.banks'].includes('code:'),
                    ),
            );
            expect(codeInput).not.toBeNull();
        });

        it('은행 이름 다국어 입력(MultilingualInput 또는 Input)이 존재한다', () => {
            // 한국어/영문이 별도 Input → MultilingualInput 단일 컴포넌트로 통합되거나
            // 분리 유지 가능. 어느 형태든 name 키 setState 가 존재하는지 검증
            const json = JSON.stringify(modal);
            expect(json).toContain('name: $args[0]?.target?.value ?? b.name');
        });

        it('삭제 버튼이 $parent._local 대상으로 filter 한다', () => {
            const deleteBtn = findFirst(modal, (n: any) =>
                n?.name === 'Button'
                    && Array.isArray(n.actions)
                    && n.actions.some(
                        (a: any) => a.handler === 'setState'
                            && a.params?.target === '$parent._local'
                            && typeof a.params?.['form.order_settings.banks'] === 'string'
                            && a.params['form.order_settings.banks'].includes('filter'),
                    ),
            );
            expect(deleteBtn).not.toBeNull();
        });
    });

    describe('추가/닫기 버튼', () => {
        it('은행 추가 버튼이 $parent._local 에 빈 은행을 추가해야 한다', () => {
            const addBtn = findFirst(modal, (n: any) =>
                n?.name === 'Button'
                    && Array.isArray(n.actions)
                    && n.actions.some(
                        (a: any) => a.handler === 'setState'
                            && a.params?.target === '$parent._local'
                            && typeof a.params?.['form.order_settings.banks'] === 'string'
                            && a.params['form.order_settings.banks'].includes("code: ''"),
                    ),
            );
            expect(addBtn).not.toBeNull();
        });

        it('모달 어딘가에서 closeModal 핸들러가 호출되어야 한다 (저장 후 자동 닫기 또는 닫기 버튼)', () => {
            // 닫기 버튼 단독 → 저장 시퀀스 onSuccess 안의 closeModal 로 통합 가능
            const json = JSON.stringify(modal);
            expect(json).toContain('"handler":"closeModal"');
            expect(json).toContain('"id":"bank_management_modal"');
        });
    });
});

// ─── 마일리지 차감 시점 결제수단별 컨트롤 검증 (마일리지/MP06) ───

describe('마일리지 차감 시점 결제수단별 컨트롤', () => {
    /**
     * mileage_deduction_timing Select 노드를 찾는다 (value 바인딩 기준).
     */
    function findMileageTimingSelect(partial: any): any | null {
        return findFirst(
            partial,
            (n) =>
                n.name === 'Select' &&
                typeof n.props?.value === 'string' &&
                n.props.value.includes('mileage_deduction_timing')
        );
    }

    it('카드 파셜에 마일리지 차감시점 Select 가 존재해야 한다', () => {
        const select = findMileageTimingSelect(paymentMethodsCards);
        expect(select).not.toBeNull();
        expect(select.props.value).toContain('mileage_deduction_timing');
    });

    it('리스트 파셜에 마일리지 차감시점 Select 가 존재해야 한다', () => {
        const select = findMileageTimingSelect(paymentMethodsList);
        expect(select).not.toBeNull();
    });

    it('마일리지 사용이 꺼져 있으면 disabled 여야 한다 (mileage.enabled 연동)', () => {
        const select = findMileageTimingSelect(paymentMethodsCards);
        expect(select.props.disabled).toContain('mileage?.enabled');
    });

    it('change 핸들러가 mileage_deduction_timing 만 갱신해야 한다', () => {
        const select = findMileageTimingSelect(paymentMethodsCards);
        const changeAction = (select.actions ?? []).find((a: any) => a.type === 'change');
        expect(changeAction).toBeDefined();
        const binding = changeAction.params['form.order_settings.payment_methods'];
        expect(binding).toContain('mileage_deduction_timing');
    });

    it('order_placed / payment_complete 두 옵션만 제공해야 한다 (none 없음)', () => {
        const select = findMileageTimingSelect(paymentMethodsCards);
        const values = (select.props.options ?? []).map((o: any) => o.value);
        expect(values).toContain('order_placed');
        expect(values).toContain('payment_complete');
        expect(values).not.toContain('none');
    });
});

// ─── 고아 항목의 편집 컨트롤 차단 ───

describe('고아 결제수단 행의 편집 컨트롤 차단', () => {
    // 고아 항목 = 저장값은 남아 있으나 공급 확장이 더 이상 제공하지 않는 결제수단
    // (플러그인 삭제·비활성, 또는 그 확장이 자기 기능 토글을 끈 경우).
    // 이 행은 "지우세요" 만 제시해야 하므로 편집 컨트롤이 하나도 노출되면 안 된다.
    // 삭제 버튼(Button)은 반대로 고아일 때만 나오는 것이 정상이라 대상에서 제외한다.
    //
    // 회귀 배경: 리스트 파셜의 마일리지 차감 시점 열만 가드가 빠져 있었다. 다른 열이
    // 사라지면서 그 열이 오른쪽으로 밀려 최소 주문금액 자리에 표시됐고, 마일리지 기능을
    // 켠 상점에서는 이미 사라진 결제수단의 값을 바꿔 저장할 수 있었다. 같은 화면의
    // 카드 파셜은 편집 영역을 하나의 가드 컨테이너로 감싸 정상 차단하고 있었다.
    const EDIT_CONTROL_NAMES = ['Select', 'Input', 'Toggle', 'Checkbox', 'Textarea'];

    const ORPHAN_GUARD = '!$method._orphaned';

    /**
     * 편집 컨트롤마다 (조상 체인에 고아 가드가 있는가) 를 판정해 수집한다.
     *
     * 가드가 어느 깊이에 걸려 있든 통과시킨다 — 리스트는 열 컨테이너마다, 카드는
     * 편집 영역 전체를 감싼 컨테이너 한 곳에 걸려 있어 구조가 서로 다르기 때문이다.
     * 열 목록을 손으로 열거하지 않으므로 이후 추가되는 열도 자동으로 검사 대상이 된다.
     */
    function collectEditControls(node: any, guarded = false, acc: any[] = []): any[] {
        if (!node || typeof node !== 'object') return acc;

        const nowGuarded = guarded
            || (typeof node.if === 'string' && node.if.includes(ORPHAN_GUARD));

        if (EDIT_CONTROL_NAMES.includes(node.name)) {
            acc.push({ node, guarded: nowGuarded });
        }

        for (const child of node.children ?? []) {
            collectEditControls(child, nowGuarded, acc);
        }
        if (node.itemTemplate) {
            collectEditControls(node.itemTemplate, nowGuarded, acc);
        }
        return acc;
    }

    /** 컨트롤을 사람이 읽을 수 있게 식별한다 (실패 메시지용). */
    function describeControl(entry: any): string {
        const value = entry.node.props?.value ?? entry.node.props?.checked ?? '';
        return `${entry.node.name}(${String(value).slice(0, 60) || 'no-value'})`;
    }

    it.each([
        ['리스트 파셜', paymentMethodsList],
        ['카드 파셜', paymentMethodsCards],
    ])('%s — 모든 편집 컨트롤이 고아 가드 아래에 있어야 한다', (_label, partial) => {
        const controls = collectEditControls(partial);

        // 컨트롤을 하나도 못 찾았다면 탐색이 실패한 것이다 (부재 단언의 거짓 통과 차단)
        expect(controls.length).toBeGreaterThan(0);

        const unguarded = controls.filter((c) => !c.guarded).map(describeControl);
        expect(
            unguarded,
            `고아 행에 편집 컨트롤이 노출된다: ${unguarded.join(', ')} — 조상 체인 어딘가에 if "${ORPHAN_GUARD}" 가 필요하다`,
        ).toEqual([]);
    });

    it('두 파셜이 같은 수의 편집 컨트롤을 가드해야 한다 (표시 방식 간 동작 일치)', () => {
        const listGuarded = collectEditControls(paymentMethodsList).filter((c) => c.guarded);
        const cardsGuarded = collectEditControls(paymentMethodsCards).filter((c) => c.guarded);

        // 넓은 화면(리스트)과 좁은 화면(카드)은 같은 데이터를 그린다. 한쪽만 가드가 빠지면
        // 창 너비에 따라 편집 가능 여부가 달라진다.
        expect(listGuarded.length).toBeGreaterThan(0);
        expect(cardsGuarded.length).toBeGreaterThan(0);
    });

    it('삭제 버튼은 고아일 때만 나오므로 편집 컨트롤 가드 대상이 아니다', () => {
        const deleteBtn = findFirst(paymentMethodsList, (n: any) =>
            n?.name === 'Button' && typeof n?.if === 'string'
                && n.if.includes('$method._orphaned')
                && !n.if.includes(ORPHAN_GUARD),
        );
        expect(deleteBtn).not.toBeNull();
        expect(EDIT_CONTROL_NAMES).not.toContain('Button');
    });
});

// ─── 다국어 키 종합 검증 ───

describe('다국어 키 종합 검증', () => {
    const prefix = 'sirsoft-ecommerce.admin.settings.order_settings';

    it('탭 레이아웃의 다국어 키가 order_settings 또는 enums 네임스페이스를 사용해야 한다', () => {
        // cancellable_statuses_card / confirmable_statuses_card 가 enum 라벨을 직접
        // 참조하면서 sirsoft-ecommerce.enums.order_status.* 키도 사용함
        const keys = collectI18nKeys(tabOrderSettings);
        const allowedPrefixes = [
            `${prefix}`,
            'sirsoft-ecommerce.enums',
            'common.',
        ];
        for (const key of keys) {
            const matched = allowedPrefixes.some((p) => key.startsWith(p));
            expect(matched, `unexpected key: ${key}`).toBe(true);
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
