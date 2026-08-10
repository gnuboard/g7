/**
 * 현금영수증 UI 구조·조건 검증 (#454 S3)
 *
 * @description
 * 다섯 개 표면을 한 곳에서 고정한다:
 *  - W-1 체크아웃 신청 폼 (extension_point 주입) — 확장 병합 칸(_local.checkoutExtraPayload) 기입
 *  - W-2 유저 주문상세 카드 (extension_point 주입) — 상태머신 5종
 *  - W-3 관리자 주문상세 카드 + 발급 모달
 *  - W-4 취소 모달 환불계좌
 *  - W-5 환경설정 (프로바이더 / 배송비 과세 / 자진발급)
 *
 * 조건식은 문자열 매칭이 아니라 실제 엔진(evaluateStringCondition)으로 평가한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { evaluateStringCondition } from '@core/template-engine/helpers/ConditionEvaluator';

import checkoutExt from '../../../extensions/checkout_cash_receipt.json';
import mypageExt from '../../../extensions/mypage_order_cash_receipt.json';
import paymentInfo from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_payment_info.json';
import issueModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_issue_cash_receipt.json';
import cancelModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_cancel_order.json';
import orderSettingsTab from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_order_settings.json';

const engine = new DataBindingEngine();
const evalIf = (expr: string, ctx: Record<string, any>) => evaluateStringCondition(expr, ctx, engine);

/** 트리 전체를 평탄화 (children 뿐 아니라 모든 객체 값을 순회) */
function flatten(node: any, acc: any[] = []): any[] {
    if (!node || typeof node !== 'object') return acc;
    acc.push(node);
    for (const v of Object.values(node)) {
        if (Array.isArray(v)) v.forEach((x) => flatten(x, acc));
        else if (v && typeof v === 'object') flatten(v, acc);
    }
    return acc;
}

const byId = (root: any, id: string) => flatten(root).find((n) => n.id === id);

/**
 * 결제행 iteration 안의 노드를 id 로 찾는다.
 *
 * iteration 안에서는 같은 id 가 행마다 중복되므로 index_var(payIdx)로 행마다 고유해야 한다
 * (audit `layout-iteration-static-id`). 조회 키에도 접미사를 붙여 그 규약을 함께 고정한다 —
 * 누군가 id 를 정적으로 되돌리면 이 헬퍼가 찾지 못해 테스트가 깨진다.
 */
const byRowId = (root: any, id: string) => byId(root, `${id}_{{payIdx}}`);

// ------------------------------------------------------------------ W-1 체크아웃

describe('W-1 체크아웃 현금영수증 신청 폼', () => {
    const root = byId(checkoutExt, 'ext_checkout_cash_receipt');

    it('올바른 확장점에 append 로 주입한다', () => {
        expect(checkoutExt.extension_point).toBe('shop_checkout_cash_receipt_slot');
        expect(checkoutExt.mode).toBe('append');
    });

    it('프로바이더 미설정이면 슬롯 전체가 렌더되지 않는다 (M2)', () => {
        const ctx = (provider: string) => ({ paymentSettings: { data: { order_settings: { cash_receipt_provider: provider } } } });

        expect(evalIf(root.if, ctx('tosspayments'))).toBe(true);
        expect(evalIf(root.if, ctx(''))).toBe(false);
        expect(evalIf(root.if, { paymentSettings: { data: { order_settings: {} } } })).toBe(false);
    });

    it('신청 전에는 하위 필드가 렌더되지 않는다 (M4: 0 → 3)', () => {
        const fields = byId(checkoutExt, 'ext_checkout_cash_receipt_fields');

        expect(evalIf(fields.if, { _local: {} })).toBe(false);
        expect(evalIf(fields.if, { _local: { cashReceiptRequested: true } })).toBe(true);
    });

    it('신청 체크박스는 확장 병합 칸에 4키를 기입한다 (직접 _local 기입은 서버 미도달)', () => {
        const toggle = byId(checkoutExt, 'ext_checkout_cash_receipt_toggle');
        const params = toggle.actions[0].params;

        expect(params.checkoutExtraPayload).toBeDefined();

        // 체크 시: 4키 편입
        const on = engine.evaluateExpression(
            String(params.checkoutExtraPayload).slice(2, -2),
            { $event: { target: { checked: true } }, _local: {} }
        );
        expect(on).toEqual({
            cash_receipt_requested: true,
            cash_receipt_type: 'income',
            cash_receipt_identifier_type: 'phone',
            cash_receipt_identifier: '',
        });

        // 해제 시: 빈 객체 → 주문 payload 에서 사라진다
        const off = engine.evaluateExpression(
            String(params.checkoutExtraPayload).slice(2, -2),
            { $event: { target: { checked: false } }, _local: {} }
        );
        expect(off).toEqual({});
    });

    it('소득공제로 전환하면 사업자등록번호 선택이 휴대폰번호로 리셋된다 (M5)', () => {
        const purpose = byId(checkoutExt, 'ext_checkout_cash_receipt_purpose');
        const incomeRadio = flatten(purpose).find((n) => n.props?.value === 'income');
        const params = incomeRadio.actions[0].params;

        const ctx = { _local: { cashReceiptIdentifierType: 'business', cashReceiptIdentifier: '1234567890' } };
        const nextType = engine.evaluateExpression(String(params.cashReceiptIdentifierType).slice(2, -2), ctx);
        const nextId = engine.evaluateExpression(String(params.cashReceiptIdentifier).slice(2, -2), ctx);

        expect(nextType).toBe('phone');
        expect(nextId).toBe('');
    });

    it('지출증빙 전환 시에는 휴대폰번호 선택을 유지한다 (D10 — 휴대폰은 양쪽 유효)', () => {
        const purpose = byId(checkoutExt, 'ext_checkout_cash_receipt_purpose');
        const expenseRadio = flatten(purpose).find((n) => n.props?.value === 'expense');
        const payload = engine.evaluateExpression(
            String(expenseRadio.actions[0].params.checkoutExtraPayload).slice(2, -2),
            { _local: { cashReceiptIdentifierType: 'phone', cashReceiptIdentifier: '01012345678' } }
        );

        expect(payload.cash_receipt_type).toBe('expense');
        expect(payload.cash_receipt_identifier_type).toBe('phone');
        expect(payload.cash_receipt_identifier).toBe('01012345678');
    });

    it('발급수단 options 는 용도에 따라 2종 / 3종이다', () => {
        const select = byId(checkoutExt, 'ext_checkout_cash_receipt_identifier_type');
        const expr = String(select.props.options).slice(2, -2);
        const t = (k: string) => k;

        const income = engine.evaluateExpression(expr, { _local: { cashReceiptType: 'income' }, $t: t });
        const expense = engine.evaluateExpression(expr, { _local: { cashReceiptType: 'expense' }, $t: t });

        expect(income.map((o: any) => o.value)).toEqual(['phone', 'card']);
        expect(expense.map((o: any) => o.value)).toEqual(['business', 'phone', 'card']);
    });

    it('발급수단을 바꾸면 번호를 비운다 (형식 불일치로 인한 422 방지)', () => {
        const select = byId(checkoutExt, 'ext_checkout_cash_receipt_identifier_type');
        expect(select.actions[0].params.cashReceiptIdentifier).toBe('');
    });

    it('Select 는 valueKey/labelKey prop 을 쓰지 않는다', () => {
        const select = byId(checkoutExt, 'ext_checkout_cash_receipt_identifier_type');
        expect(select.props.valueKey).toBeUndefined();
        expect(select.props.labelKey).toBeUndefined();
    });
});

// ------------------------------------------------------------------ W-2 유저 주문상세

describe('W-2 유저 주문상세 현금영수증 카드 — 상태머신 5종', () => {
    const card = byId(mypageExt, 'ext_mypage_cash_receipt_card');

    const payment = (over: Record<string, any> = {}) => ({
        payment_method: 'dbank',
        cash_receipt_provider: 'tosspayments',
        payment_status: 'paid',
        ...over,
    });

    it('① 무통장이 아니거나 프로바이더 미설정이면 카드 자체를 렌더하지 않는다', () => {
        expect(evalIf(card.if, { order: { data: { payment: payment() } } })).toBe(true);
        expect(evalIf(card.if, { order: { data: { payment: payment({ payment_method: 'card' }) } } })).toBe(false);
        expect(evalIf(card.if, { order: { data: { payment: payment({ cash_receipt_provider: null }) } } })).toBe(false);
    });

    it('② 입금 전에는 안내만 노출한다 — 무통장은 ready, 가상계좌는 waiting_deposit', () => {
        const node = byId(mypageExt, 'ext_mypage_cash_receipt_awaiting');

        // PaymentStatusEnum::isAwaitingDeposit() 이 true 인 두 상태 모두 "입금 전" 이다.
        // 무통장 주문은 ready 로 생성된다 — waiting_deposit 만 검사하면 무통장 전부에서 안내가 사라진다.
        expect(evalIf(node.if, { order: { data: { payment: payment({ payment_status: 'ready' }) } } })).toBe(true);
        expect(evalIf(node.if, { order: { data: { payment: payment({ payment_status: 'waiting_deposit' }) } } })).toBe(true);

        expect(evalIf(node.if, { order: { data: { payment: payment({ payment_status: 'paid' }) } } })).toBe(false);
        expect(evalIf(node.if, { order: { data: { payment: payment({ payment_status: 'cancelled' }) } } })).toBe(false);
    });

    it('입금 전(ready) 주문은 5개 상태 중 정확히 하나에 매칭된다 (빈 카드 방지)', () => {
        const ctx = { order: { data: { payment: payment({ payment_status: 'ready' }), cash_receipt: null, cash_receipts: [] } } };
        const states = [
            'ext_mypage_cash_receipt_awaiting',
            'ext_mypage_cash_receipt_issuable',
            'ext_mypage_cash_receipt_issued',
            'ext_mypage_cash_receipt_failed',
        ];
        const matched = states.filter((id) => evalIf(byId(mypageExt, id).if, ctx));
        expect(matched).toEqual(['ext_mypage_cash_receipt_awaiting']);
    });

    it('③ 입금완료 + 미발급 → 발급 버튼', () => {
        const node = byId(mypageExt, 'ext_mypage_cash_receipt_issuable');
        expect(evalIf(node.if, { order: { data: { payment: payment(), cash_receipt: null, cash_receipts: [] } } })).toBe(true);
        expect(evalIf(node.if, { order: { data: { payment: payment(), cash_receipt: { id: 1 }, cash_receipts: [] } } })).toBe(false);
    });

    it('입금완료 주문의 상태 ③ 과 ⑤ 는 동시에 매칭되지 않는다 (배타)', () => {
        const failedCtx = { order: { data: { payment: payment(), cash_receipt: null, cash_receipts: [{ issue_status: 'FAILED' }] } } };

        const issuable = byId(mypageExt, 'ext_mypage_cash_receipt_issuable');
        const failed = byId(mypageExt, 'ext_mypage_cash_receipt_failed');

        expect(evalIf(failed.if, failedCtx)).toBe(true);
        expect(evalIf(issuable.if, failedCtx)).toBe(false);
    });

    it('④ 발급완료 → 영수증 링크', () => {
        const node = byId(mypageExt, 'ext_mypage_cash_receipt_issued');
        expect(evalIf(node.if, { order: { data: { cash_receipt: { id: 1 } } } })).toBe(true);
        expect(evalIf(node.if, { order: { data: { cash_receipt: null } } })).toBe(false);
    });

    it('⑤ 취소 성공 + 재발급 실패 → 경고 (issue_status 는 대문자 FAILED)', () => {
        const node = byId(mypageExt, 'ext_mypage_cash_receipt_failed');

        const failed = { order: { data: { cash_receipt: null, cash_receipts: [{ issue_status: 'FAILED' }] } } };
        const ok = { order: { data: { cash_receipt: null, cash_receipts: [{ issue_status: 'COMPLETED' }] } } };
        const none = { order: { data: { cash_receipt: null, cash_receipts: [] } } };

        expect(evalIf(node.if, failed)).toBe(true);
        expect(evalIf(node.if, ok)).toBe(false);
        expect(evalIf(node.if, none)).toBe(false);
    });

    it('유저에게는 발급취소·수동 재발급 버튼을 주지 않는다 (관리자 전용)', () => {
        // 'reissue_failed' 안내 문구는 정상 — 금지 대상은 재발급/발급취소를 실행하는 액션이다.
        const actions = flatten(mypageExt).flatMap((n: any) => n.actions ?? []);
        const apiCalls = actions.filter((a: any) => a.handler === 'apiCall');

        expect(apiCalls.some((a: any) => String(a.target).includes('/reissue'))).toBe(false);
        expect(apiCalls.some((a: any) => a.params?.method === 'DELETE')).toBe(false);

        // 남은 apiCall 은 발급(POST) 하나뿐이어야 한다
        expect(apiCalls.map((a: any) => a.params?.method)).toEqual(['POST']);
    });

    it('발급 모달은 modals 섹션에 등록한다 (인라인 Modal 금지)', () => {
        expect(Array.isArray(mypageExt.modals)).toBe(true);
        expect(mypageExt.modals[0].id).toBe('ext_cash_receipt_issue_modal');
    });

    it('회원은 user/orders/{id}, 비회원은 guest/orders/{orderNumber} 로 발급한다', () => {
        const submit = byId(mypageExt, 'ext_cash_receipt_issue_submit');
        const expr = String(submit.actions[0].target).slice(2, -2);

        const member = engine.evaluateExpression(expr, {
            _global: { currentUser: { uuid: 'u1' } },
            order: { data: { id: 42, order_number: 'ORD-1' } },
        });
        const guest = engine.evaluateExpression(expr, {
            _global: { currentUser: null },
            order: { data: { id: 42, order_number: 'ORD-1' } },
        });

        expect(member).toBe('/api/modules/sirsoft-ecommerce/user/orders/42/cash-receipt');
        expect(guest).toBe('/api/modules/sirsoft-ecommerce/guest/orders/ORD-1/cash-receipt');
    });
});

// ------------------------------------------------------------------ W-3 관리자 주문상세

describe('W-3 관리자 주문상세 현금영수증 카드', () => {
    const card = byRowId(paymentInfo, 'payment_cash_receipt_card');

    it('결제카드 반복(payment) 컨텍스트에서 무통장 + 프로바이더 설정 시에만 렌더된다', () => {
        expect(evalIf(card.if, { payment: { payment_method: 'dbank', cash_receipt_provider: 'toss' } })).toBe(true);
        expect(evalIf(card.if, { payment: { payment_method: 'vbank', cash_receipt_provider: 'toss' } })).toBe(false);
        expect(evalIf(card.if, { payment: { payment_method: 'dbank', cash_receipt_provider: '' } })).toBe(false);
    });

    it('입금 전 안내는 무통장(ready)과 가상계좌(waiting_deposit) 모두에서 노출된다', () => {
        const node = byRowId(paymentInfo, 'payment_cash_receipt_awaiting');

        expect(evalIf(node.if, { payment: { payment_status: 'ready' } })).toBe(true);
        expect(evalIf(node.if, { payment: { payment_status: 'waiting_deposit' } })).toBe(true);
        expect(evalIf(node.if, { payment: { payment_status: 'paid' } })).toBe(false);
    });

    it('관리자 전용 버튼 3종(발급/발급취소/수동 재발급)이 존재한다', () => {
        expect(byRowId(paymentInfo, 'payment_cash_receipt_issue_button')).toBeDefined();
        expect(byRowId(paymentInfo, 'payment_cash_receipt_cancel_button')).toBeDefined();
        expect(byRowId(paymentInfo, 'payment_cash_receipt_reissue_button')).toBeDefined();
    });

    it('재발급 실패 상태에서 발급 버튼과 재발급 버튼이 동시에 노출되지 않는다', () => {
        // 실패 이력이 있으면 복구는 "수동 재발급" 경로다. 신규 발급 버튼을 함께 띄우면
        // 관리자가 어느 쪽을 눌러야 하는지 알 수 없다.
        const failed = { payment: { payment_status: 'paid' }, order: { data: { cash_receipt: null, cash_receipts: [{ issue_status: 'FAILED' }] } } };

        const issue = byRowId(paymentInfo, 'payment_cash_receipt_issue_button');
        const reissue = byRowId(paymentInfo, 'payment_cash_receipt_reissue_button');

        expect(evalIf(reissue.if, failed)).toBe(true);
        expect(evalIf(issue.if, failed)).toBe(false);
    });

    it('이력이 전혀 없는 입금완료 주문에서는 발급 버튼만 노출된다', () => {
        const fresh = { payment: { payment_status: 'paid' }, order: { data: { cash_receipt: null, cash_receipts: [] } } };

        expect(evalIf(byRowId(paymentInfo, 'payment_cash_receipt_issue_button').if, fresh)).toBe(true);
        expect(evalIf(byRowId(paymentInfo, 'payment_cash_receipt_reissue_button').if, fresh)).toBe(false);
    });

    it('수동 재발급 버튼은 재발급 실패 상태에서만 노출된다', () => {
        const btn = byRowId(paymentInfo, 'payment_cash_receipt_reissue_button');
        const failed = { order: { data: { cash_receipt: null, cash_receipts: [{ issue_status: 'FAILED' }] } } };
        const issued = { order: { data: { cash_receipt: { id: 1 }, cash_receipts: [{ issue_status: 'COMPLETED' }] } } };

        expect(evalIf(btn.if, failed)).toBe(true);
        expect(evalIf(btn.if, issued)).toBe(false);
    });

    it('관리자 apiCall 은 모두 auth_required 를 선언한다 (미선언 시 Bearer 토큰 미부착 → 401)', () => {
        const adminApiCalls = [
            ...flatten(paymentInfo).flatMap((n: any) => n.actions ?? []),
            ...flatten(issueModal).flatMap((n: any) => n.actions ?? []),
        ].filter((a: any) => a.handler === 'apiCall' && String(a.target).includes('/admin/'));

        expect(adminApiCalls.length).toBeGreaterThan(0);
        for (const a of adminApiCalls) {
            expect(a.auth_required, `auth_required 누락: ${a.target}`).toBe(true);
        }
    });

    it('발급취소는 DELETE, 재발급은 reissue 엔드포인트를 호출한다', () => {
        const cancel = byRowId(paymentInfo, 'payment_cash_receipt_cancel_button');
        const reissue = byRowId(paymentInfo, 'payment_cash_receipt_reissue_button');

        expect(cancel.actions[0].params.method).toBe('DELETE');
        expect(reissue.actions[0].target).toContain('/cash-receipt/reissue');
    });

    it('발급 이력표는 이력이 있을 때만, 그리고 기본 접힌 상태로 존재한다', () => {
        const wrap = byRowId(paymentInfo, 'payment_cash_receipt_history');
        const body = byRowId(paymentInfo, 'payment_cash_receipt_history_body');

        // 껍데기: 이력 0건이면 토글 헤더조차 띄우지 않는다
        expect(evalIf(wrap.if, { order: { data: { cash_receipts: [] } } })).toBe(false);
        expect(evalIf(wrap.if, { order: { data: { cash_receipts: [{ id: 1 }] } } })).toBe(true);

        // 본문: 초기 상태(_local.expandedCashReceiptHistory = [])에서 접혀 있어야 한다
        expect(evalIf(body.if, { payIdx: 0, _local: { expandedCashReceiptHistory: [] } })).toBe(false);
        expect(evalIf(body.if, { payIdx: 0, _local: { expandedCashReceiptHistory: [0] } })).toBe(true);

        // 상태 키가 아직 초기화되지 않은 순간에도 터지지 않는다 (?? [] fallback)
        expect(evalIf(body.if, { payIdx: 0, _local: {} })).toBe(false);
    });

    it('이력 토글은 결제 건마다 독립적으로 접히고 펼쳐진다', () => {
        // 한 주문에 결제행이 여러 개일 수 있다. payIdx 를 배열에 넣고 빼는 방식이라야
        // 0번 행을 펼쳐도 1번 행이 함께 펼쳐지지 않는다.
        const toggle = byRowId(paymentInfo, 'payment_cash_receipt_history_toggle');
        const body = byRowId(paymentInfo, 'payment_cash_receipt_history_body');
        const expr = String(toggle.actions[0].params.expandedCashReceiptHistory).slice(2, -2);

        // 0번 행 펼치기
        const afterOpen0 = engine.evaluateExpression(expr, { payIdx: 0, _local: { expandedCashReceiptHistory: [] } });
        expect(afterOpen0).toEqual([0]);

        // 그 상태에서 1번 행 펼치기 → 0번은 그대로 열려 있다
        const afterOpen1 = engine.evaluateExpression(expr, { payIdx: 1, _local: { expandedCashReceiptHistory: afterOpen0 } });
        expect(afterOpen1).toEqual([0, 1]);
        expect(evalIf(body.if, { payIdx: 0, _local: { expandedCashReceiptHistory: afterOpen1 } })).toBe(true);

        // 0번 행 다시 접기 → 1번은 열린 채 남는다
        const afterClose0 = engine.evaluateExpression(expr, { payIdx: 0, _local: { expandedCashReceiptHistory: afterOpen1 } });
        expect(afterClose0).toEqual([1]);
        expect(evalIf(body.if, { payIdx: 0, _local: { expandedCashReceiptHistory: afterClose0 } })).toBe(false);
        expect(evalIf(body.if, { payIdx: 1, _local: { expandedCashReceiptHistory: afterClose0 } })).toBe(true);
    });

    it('토글 버튼은 접힘 상태를 아이콘과 aria-expanded 로 함께 알린다', () => {
        const toggle = byRowId(paymentInfo, 'payment_cash_receipt_history_toggle');
        const body = byRowId(paymentInfo, 'payment_cash_receipt_history_body');

        // Form 밖이지만 submit 오작동 방지 규약을 따른다
        expect(toggle.props.type).toBe('button');
        // 스크린리더가 본문 존재를 알 수 있어야 한다
        expect(toggle.props['aria-controls']).toBe(body.id);

        const icon = flatten(toggle).find((n: any) => n.name === 'Icon');
        const iconExpr = String(icon.props.name).slice(2, -2);
        const ariaExpr = String(toggle.props['aria-expanded']).slice(2, -2);

        const closed = { payIdx: 0, _local: { expandedCashReceiptHistory: [] } };
        const opened = { payIdx: 0, _local: { expandedCashReceiptHistory: [0] } };

        expect(engine.evaluateExpression(iconExpr, closed)).toBe('chevron-right');
        expect(engine.evaluateExpression(iconExpr, opened)).toBe('chevron-down');
        expect(engine.evaluateExpression(ariaExpr, closed)).toBe(false);
        expect(engine.evaluateExpression(ariaExpr, opened)).toBe(true);

        // Icon 은 w-N/h-N 클래스가 아니라 size prop 으로 크기를 지정한다
        expect(icon.props.size).toBeDefined();
        expect(icon.props.className ?? '').not.toMatch(/\b[wh]-\d/);
    });

    it('액션 영역은 데스크톱 가로 → portable 세로 스택 (§6-0)', () => {
        const actions = byRowId(paymentInfo, 'payment_cash_receipt_actions');
        expect(actions.props.className).toContain('flex-row');
        expect(actions.responsive.portable.props.className).toContain('flex-col');
        expect(Object.keys(actions.responsive)).toEqual(['portable']);
    });

    it('신설 카드에 Tailwind breakpoint(md:/lg:) 를 쓰지 않는다', () => {
        for (const n of flatten(card)) {
            expect(n.props?.className ?? '').not.toMatch(/(^|\s)(md|lg|xl|2xl):/);
        }
    });
});

describe('W-3 관리자 발급 모달', () => {
    it('modals 파트너 id 로 등록되고 발급 버튼이 이를 연다', () => {
        expect(issueModal.id).toBe('modal_issue_cash_receipt');

        const btn = byRowId(paymentInfo, 'payment_cash_receipt_issue_button');
        const open = flatten(btn.actions).find((a: any) => a.handler === 'openModal');
        expect(open.target).toBe('modal_issue_cash_receipt');
    });

    it('발급수단 options 는 용도에 따라 2종 / 3종이다', () => {
        const select = byId(issueModal, 'modal_cash_receipt_identifier_type');
        const expr = String(select.props.options).slice(2, -2);
        const t = (k: string) => k;

        const income = engine.evaluateExpression(expr, { _global: { adminCashReceiptForm: { receipt_type: 'income' } }, $t: t });
        const expense = engine.evaluateExpression(expr, { _global: { adminCashReceiptForm: { receipt_type: 'expense' } }, $t: t });

        expect(income.map((o: any) => o.value)).toEqual(['phone', 'card']);
        expect(expense.map((o: any) => o.value)).toEqual(['business', 'phone', 'card']);
    });

    it('번호가 비어 있으면 발급 버튼이 비활성화된다', () => {
        const submit = byId(issueModal, 'modal_cash_receipt_submit');
        const expr = String(submit.props.disabled).slice(2, -2);

        expect(engine.evaluateExpression(expr, { _global: { adminCashReceiptForm: { identifier: '' } } })).toBe(true);
        expect(engine.evaluateExpression(expr, { _global: { adminCashReceiptForm: { identifier: '01012345678' } } })).toBe(false);
    });

    it('성공 시 setState 를 closeModal 보다 먼저 실행한다 (순서 역전 시 상태 잔존)', () => {
        const submit = byId(issueModal, 'modal_cash_receipt_submit');
        const onSuccess = submit.actions[0].onSuccess.map((a: any) => a.handler);

        expect(onSuccess.indexOf('setState')).toBeLessThan(onSuccess.indexOf('closeModal'));
        expect(onSuccess).toContain('refetchDataSource');
    });
});

// ------------------------------------------------------------------ W-4 취소 모달

describe('W-4 취소 모달 환불계좌', () => {
    const section = byId(cancelModal, 'cancel_refund_bank_section');

    it('가상계좌·무통장에서만 노출한다 (카드·간편결제는 계좌 불필요)', () => {
        const ctx = (m: string) => ({ order: { data: { payment: { payment_method: m } } } });

        expect(evalIf(section.if, ctx('vbank'))).toBe(true);
        expect(evalIf(section.if, ctx('dbank'))).toBe(true);
        expect(evalIf(section.if, ctx('card'))).toBe(false);
        expect(evalIf(section.if, ctx('point'))).toBe(false);
    });

    it('3필드가 _local.refundBank* 에 저장된다 (cancelOrderHandlers 가 읽는 키)', () => {
        const keys = flatten(section)
            .flatMap((n: any) => n.actions ?? [])
            .filter((a: any) => a.handler === 'setState')
            .flatMap((a: any) => Object.keys(a.params).filter((k) => k !== 'target'));

        expect(keys).toEqual(expect.arrayContaining(['refundBankCode', 'refundBankAccount', 'refundBankHolder']));
    });

    it('가상계좌 입금완료 건에만 필수 안내를 띄운다', () => {
        const hint = flatten(section).find((n: any) => String(n.text ?? '').includes('refund_bank_required_hint'));
        const ctx = (m: string, s: string) => ({ order: { data: { payment: { payment_method: m, payment_status: s } } } });

        expect(evalIf(hint.if, ctx('vbank', 'paid'))).toBe(true);
        expect(evalIf(hint.if, ctx('vbank', 'waiting_deposit'))).toBe(false);
        expect(evalIf(hint.if, ctx('dbank', 'paid'))).toBe(false);
    });

    it('3열 → portable 1열로 접힌다', () => {
        const grid = flatten(section).find((n: any) => (n.props?.className ?? '').includes('grid-cols-3'));
        expect(grid.responsive.portable.props.className).toContain('grid-cols-1');
    });
});

// ------------------------------------------------------------------ W-5 환경설정

describe('W-5 환경설정 — 현금영수증', () => {
    const card = byId(orderSettingsTab, 'cash_receipt_card');

    it('카드가 주문설정 탭에 존재한다', () => {
        expect(card).toBeDefined();
    });

    it('등록된 프로바이더가 없으면 Select 대신 안내를 노출한다', () => {
        const select = byId(orderSettingsTab, 'cash_receipt_provider_select');

        expect(evalIf(select.if, { _local: { form: { available_cash_receipt_providers: [{ id: 'toss' }] } } })).toBe(true);
        expect(evalIf(select.if, { _local: { form: { available_cash_receipt_providers: [] } } })).toBe(false);
    });

    it('배송비 과세 3정책의 value 가 백엔드 Enum 과 일치한다', () => {
        const select = byId(orderSettingsTab, 'shipping_fee_tax_policy_select');
        const opts = engine.evaluateExpression(String(select.props.options).slice(2, -2), { $t: (k: string) => k });

        expect(opts.map((o: any) => o.value)).toEqual(['proportional', 'taxable', 'follow_main_item']);
    });

    it('배송비 과세 기본값은 안분(proportional) 이다', () => {
        const select = byId(orderSettingsTab, 'shipping_fee_tax_policy_select');
        const value = engine.evaluateExpression(String(select.props.value).slice(2, -2), { _local: { form: { order_settings: {} } } });

        expect(value).toBe('proportional');
    });

    it('자진발급 토글의 기본값은 OFF 다 (D14)', () => {
        const toggle = byId(orderSettingsTab, 'cash_receipt_self_issue_toggle');
        const checked = engine.evaluateExpression(String(toggle.props.checked).slice(2, -2), { _local: { form: { order_settings: {} } } });

        expect(checked).toBe(false);
    });

    it('3개 컨트롤이 저장 대상 키에 setState 하고 hasChanges 를 세운다', () => {
        const targets = flatten(card)
            .flatMap((n: any) => n.actions ?? [])
            .filter((a: any) => a.handler === 'setState')
            .map((a: any) => a.params);

        const keys = targets.flatMap((p: any) => Object.keys(p));
        expect(keys).toEqual(expect.arrayContaining([
            'form.order_settings.cash_receipt_provider',
            'form.order_settings.shipping_fee_tax_policy',
            'form.order_settings.cash_receipt_self_issue',
        ]));
        expect(targets.every((p: any) => p.hasChanges === true)).toBe(true);
    });

    it('자진발급 안내에 국세청 의무발행 업종 링크가 있다', () => {
        // 의무발행 업종인지 판단하라고 안내만 하고 확인 경로를 주지 않으면 설정을 켤 수 없다.
        const link = byId(card, 'cash_receipt_mandatory_industry_link');

        expect(link.name).toBe('A');
        expect(link.props.href).toMatch(/^https:\/\/www\.nts\.go\.kr\//);
        expect(link.props.target).toBe('_blank');
        // 외부 링크는 opener 유출을 막는다.
        expect(link.props.rel).toBe('noopener noreferrer');

        const labels = flatten(link).map((n: any) => n.text).filter(Boolean);
        expect(labels).toContain(
            '$t:sirsoft-ecommerce.admin.settings.order_settings.cash_receipt.self_issue_mandatory_link'
        );
    });

    it('신설 카드에 Tailwind breakpoint(md:/lg:) 를 쓰지 않는다 (§6-0)', () => {
        for (const n of flatten(card)) {
            expect(n.props?.className ?? '').not.toMatch(/(^|\s)(md|lg|xl|2xl):/);
        }
    });
});

// ------------------------------------------------------------ 5개 표면 공통 규약

describe('현금영수증 5개 표면 공통 규약', () => {
    const SURFACES: Array<[string, any]> = [
        ['W-1 체크아웃', checkoutExt],
        ['W-2 유저 주문상세', mypageExt],
        ['W-3 관리자 주문상세', paymentInfo],
        ['W-3 발급 모달', issueModal],
        ['W-5 환경설정', orderSettingsTab],
    ];

    it.each(SURFACES)('%s — Icon 크기는 size prop 으로 지정한다 (w-N/h-N 금지)', (_label, root) => {
        // audit `layout-icon-classname-size` 와 같은 규약. 그 룰의 appliesTo 는
        // `**/resources/layouts/**` 라서 확장점 파일(resources/extensions/**)을 보지 못한다.
        // 그 사각을 여기서 덮는다 — 실제로 mypage 확장점의 Icon 2개가 이 규약을 어기고 있었다.
        for (const icon of flatten(root).filter((n: any) => n.name === 'Icon')) {
            const cls = String(icon.props?.className ?? '');
            expect(cls, `Icon(${icon.props?.name}) 에 w-N/h-N 클래스`).not.toMatch(/(^|\s)[wh]-\d/);
        }
    });
});
