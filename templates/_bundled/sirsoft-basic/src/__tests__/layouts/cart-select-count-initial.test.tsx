/**
 * @file cart-select-count-initial.test.tsx
 * @description 장바구니 전체 선택 개수 최초 로딩 표시 회귀 (공개 이슈 #92)
 *
 * 결함: 장바구니를 처음 열면 전체 선택 옆 개수가 `(/27)` 처럼 선택 개수 없이 나온다.
 *   표시식이 `{{_local.selectedCount}}` 인데 `selectedCount` 는 체크박스 change 핸들러에서만
 *   기록되므로 최초 마운트 시점에는 undefined 이고, 엔진은 undefined 를 빈 문자열로 서식한다
 *   (예외·콘솔 에러 없음 — 표시 전용 결함). 체크박스를 한 번 건드리면 정상화된다.
 *
 * 정정: 표시식을 선택 상태의 SSoT 인 `_local.selectedItems` 파생으로 교체한다.
 *   같은 화면 주문 요약(`_cart_summary.json`)이 이미 쓰던 표현식과 동일 형태로 통일해
 *   두 카운터의 계산 방식 비대칭도 함께 해소한다.
 *
 * 함께 검증(동일 근본 원인 — `null` 로만 선언된 키를 옵셔널 없이 하위 접근):
 *   - `_modal_cart_option_change.json` 재고 안내 (`matchedOption`)
 *   - `_edit.json` 프로필 오류 메시지 (`profileErrors` — 가드/표시식 옵셔널 엇갈림)
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, fireEvent, cleanup } from '@testing-library/react';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    screen,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import cartListPartial from '../../../layouts/partials/shop/_cart_list.json';
import cartOptionChangeModal from '../../../layouts/partials/shop/_modal_cart_option_change.json';
import profileEditPartial from '../../../layouts/partials/mypage/profile/_edit.json';
import memberFieldsPartial from '../../../layouts/partials/member/_member_fields.json';
import MemberFieldInput from '../../components/composite/MemberFieldInput';

// ========== 공통 유틸 ==========

/**
 * JSON 트리를 순회하며 모든 노드에 콜백을 적용합니다.
 *
 * @param node 순회 시작 노드
 * @param visit 노드 방문 콜백
 */
function walk(node: unknown, visit: (n: Record<string, unknown>) => void): void {
    if (Array.isArray(node)) {
        node.forEach((n) => walk(n, visit));
        return;
    }
    if (!node || typeof node !== 'object') return;
    const obj = node as Record<string, unknown>;
    visit(obj);
    Object.values(obj).forEach((v) => walk(v, visit));
}

/**
 * JSON 트리에서 특정 문자열을 포함하는 문자열 값을 모두 수집합니다.
 *
 * @param node 순회 시작 노드
 * @param needle 찾을 부분 문자열
 * @returns 매칭된 문자열 값 목록
 */
function collectStringsContaining(node: unknown, needle: string): string[] {
    const out: string[] = [];
    walk(node, (obj) => {
        for (const v of Object.values(obj)) {
            if (typeof v === 'string' && v.includes(needle)) out.push(v);
        }
    });
    return out;
}

/**
 * 장바구니 데이터소스 mock 응답을 만듭니다.
 *
 * @param count 장바구니 상품 수
 * @returns cart/query 응답 형태
 */
function cartResponse(count: number) {
    const items = Array.from({ length: count }, (_, i) => ({
        id: i + 1,
        quantity: 1,
        product: { name: `상품 ${i + 1}` },
        product_option: { option_name: '기본', price: 1000 },
    }));
    return {
        data: {
            items,
            item_ids: items.map((it) => it.id),
            item_count: count,
            calculation: null,
        },
    };
}

/** 실 파일(`_cart_list.json`)을 그대로 감싼 테스트용 래퍼 레이아웃 */
const wrapperLayout = {
    version: '1.0.0',
    layout_name: 'shop/cart#test-wrapper',
    data_sources: [
        {
            id: 'cartItems',
            type: 'api',
            endpoint: '/api/modules/sirsoft-ecommerce/cart/query',
            method: 'POST',
            auto_fetch: true,
        },
    ],
    components: [cartListPartial],
};

/** 카운터 Span 의 현재 텍스트를 읽는다 (`(선택/전체)` 형태) */
function readCounterText(): string {
    // `(3/3)` 정상, `(/3)` 결함 — 두 형태를 모두 잡는 패턴
    const el = screen.getByText(/^\(\d*\/\d+\)$/);
    return el.textContent ?? '';
}

let registry: ReturnType<typeof createMockComponentRegistryWithBasics>;

beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
    // basics 에 없는 컴포넌트 보강
    registry.register('basic', 'Checkbox', ({ id, name, checked, className }: any) =>
        React.createElement('input', {
            type: 'checkbox',
            id,
            name,
            checked: !!checked,
            className,
            readOnly: true,
        })
    );
    registry.register('basic', 'Icon', ({ name, className }: any) =>
        React.createElement('i', { 'data-icon': name, className })
    );
});

// ========== 1. 렌더링 회귀 (이슈 #92 본체) ==========

describe('_cart_list.json — 전체 선택 개수 최초 표시 (#92)', () => {
    const ITEM_COUNT = 3;

    /**
     * 최초 마운트 상태를 재현해 카운터 텍스트를 읽는다.
     * `selectedCount` 는 의도적으로 주입하지 않는다 — 체크박스를 아직 건드리지 않은 상태.
     *
     * @param selectedItems 선택된 상품 id 목록
     * @returns 렌더 유틸과 카운터 텍스트
     */
    async function mountAndRead(selectedItems: number[]) {
        const testUtils = createLayoutTest(wrapperLayout as any, {
            componentRegistry: registry,
            initialState: {
                _local: {
                    selectedItems,
                    allSelected: selectedItems.length === ITEM_COUNT,
                },
            },
        });
        testUtils.mockApi('cartItems', { response: cartResponse(ITEM_COUNT) });
        await testUtils.render();
        const text = readCounterText();
        return { testUtils, text };
    }

    /**
     * @scenario mount_path=initial_load, selection=all
     * @effects counter_matches_checked_state, counter_matches_summary_panel
     */
    it('최초 로딩 + 전체 선택 상태에서 (3/3) 을 표시한다', async () => {
        const { testUtils, text } = await mountAndRead([1, 2, 3]);
        expect(text).toBe('(3/3)');
        testUtils.cleanup();
    });

    /**
     * @scenario mount_path=initial_load, selection=partial
     * @effects counter_matches_checked_state
     */
    it('최초 로딩 + 부분 선택 상태에서 (2/3) 을 표시한다', async () => {
        const { testUtils, text } = await mountAndRead([1, 2]);
        expect(text).toBe('(2/3)');
        testUtils.cleanup();
    });

    /**
     * @scenario mount_path=initial_load, selection=none
     * @effects counter_matches_checked_state
     */
    it('최초 로딩 + 미선택 상태에서 (0/3) 을 표시한다 (빈 문자열 금지)', async () => {
        const { testUtils, text } = await mountAndRead([]);
        expect(text).toBe('(0/3)');
        testUtils.cleanup();
    });

    /**
     * @scenario mount_path=reload, selection=all
     * @effects counter_matches_checked_state, counter_matches_summary_panel
     */
    it('새로고침(재마운트) 후에도 전체 선택 카운터가 (3/3) 이다', async () => {
        const first = await mountAndRead([1, 2, 3]);
        first.testUtils.cleanup();
        const { testUtils, text } = await mountAndRead([1, 2, 3]);
        expect(text).toBe('(3/3)');
        testUtils.cleanup();
    });

    /**
     * @scenario mount_path=reload, selection=partial
     * @effects counter_matches_checked_state
     */
    it('새로고침(재마운트) 후 부분 선택 카운터가 (2/3) 이다', async () => {
        const first = await mountAndRead([1, 2]);
        first.testUtils.cleanup();
        const { testUtils, text } = await mountAndRead([1, 2]);
        expect(text).toBe('(2/3)');
        testUtils.cleanup();
    });

    /**
     * @scenario mount_path=reload, selection=none
     * @effects counter_matches_checked_state
     */
    it('새로고침(재마운트) 후 미선택 카운터가 (0/3) 이다', async () => {
        const first = await mountAndRead([]);
        first.testUtils.cleanup();
        const { testUtils, text } = await mountAndRead([]);
        expect(text).toBe('(0/3)');
        testUtils.cleanup();
    });

    /**
     * @scenario mount_path=spa_return, selection=all
     * @effects counter_matches_checked_state, counter_matches_summary_panel
     */
    it('SPA 이동 후 복귀(마운트 해제 → 재마운트)에도 (3/3) 이다', async () => {
        const away = await mountAndRead([1, 2, 3]);
        away.testUtils.cleanup();
        const { testUtils, text } = await mountAndRead([1, 2, 3]);
        expect(text).toBe('(3/3)');
        testUtils.cleanup();
    });

    /**
     * @scenario mount_path=spa_return, selection=partial
     * @effects counter_matches_checked_state
     */
    it('SPA 복귀 + 부분 선택에서 (2/3) 이다', async () => {
        const away = await mountAndRead([1, 2]);
        away.testUtils.cleanup();
        const { testUtils, text } = await mountAndRead([1, 2]);
        expect(text).toBe('(2/3)');
        testUtils.cleanup();
    });

    /**
     * @scenario mount_path=spa_return, selection=none
     * @effects counter_matches_checked_state
     */
    it('SPA 복귀 + 미선택에서 (0/3) 이다', async () => {
        const away = await mountAndRead([]);
        away.testUtils.cleanup();
        const { testUtils, text } = await mountAndRead([]);
        expect(text).toBe('(0/3)');
        testUtils.cleanup();
    });
});

describe('_cart_list.json — 빈 장바구니 (T8 에지)', () => {
    /**
     * 브라우저 실측에서 이 축은 기존 장바구니를 통째로 비워야만 재현되므로,
     * 데이터 파괴 없이 여기서 결정적으로 고정한다.
     *
     * @scenario mount_path=reload, selection=none
     * @effects counter_matches_checked_state
     */
    it('상품이 0건이면 전체 선택 카드 자체가 렌더되지 않는다', async () => {
        const testUtils = createLayoutTest(wrapperLayout as any, {
            componentRegistry: registry,
            initialState: { _local: { selectedItems: [], allSelected: false } },
        });
        testUtils.mockApi('cartItems', { response: cartResponse(0) });
        await testUtils.render();

        // 감싸는 if 가 `items.length > 0` 이므로 카운터 Span 자체가 없다
        expect(screen.queryByText(/^\(\d*\/\d+\)$/)).toBeNull();
        testUtils.cleanup();
    });

    /**
     * @scenario mount_path=spa_return, selection=none
     * @effects counter_matches_checked_state
     */
    it('분모·분자 모두 폴백이 있어 데이터가 비어도 빈 문자열이 되지 않는다', () => {
        const counter = collectStringsContaining(cartListPartial, '/{{cartItems').find((t) =>
            t.startsWith('(')
        );
        // 값이 없을 때 나오는 문자열을 실제로 계산해 본다 (엔진과 동일한 네이티브 평가)
        const exprs = [...(counter ?? '').matchAll(/\{\{([\s\S]*?)\}\}/g)].map((m) => m[1]);
        expect(exprs.length).toBe(2);
        const rendered = exprs.map((e) =>
            // eslint-disable-next-line no-new-func
            new Function('_local', 'cartItems', `return (${e});`)({}, { data: {} })
        );
        expect(rendered).toEqual([0, 0]);
    });
});

// ========== 2. 정적 회귀 가드 ==========

describe('_cart_list.json — 카운터 표시식 정적 가드 (#92)', () => {
    const counterTexts = collectStringsContaining(cartListPartial, '/{{cartItems');

    /**
     * @scenario mount_path=initial_load, selection=all
     * @effects counter_matches_checked_state
     */
    it('카운터 표시식이 존재한다', () => {
        expect(counterTexts.length, '카운터 표시식을 찾지 못했다').toBeGreaterThan(0);
    });

    /**
     * @scenario mount_path=initial_load, selection=all
     * @effects counter_matches_checked_state
     */
    it('초기화되지 않는 _local.selectedCount 를 더 이상 참조하지 않는다', () => {
        const json = JSON.stringify(cartListPartial);
        expect(json).not.toContain('_local.selectedCount');
    });

    /**
     * @scenario mount_path=initial_load, selection=all
     * @effects counter_matches_checked_state
     */
    it('선택 개수는 SSoT(selectedItems) 파생 + 0 폴백으로 표시한다', () => {
        const counter = counterTexts.find((t) => t.startsWith('('));
        expect(counter, '`(선택/전체)` 형태의 카운터가 없다').toBeTruthy();
        expect(counter).toContain('_local.selectedItems?.length ?? 0');
    });

    /**
     * @scenario mount_path=initial_load, selection=all
     * @effects counter_matches_summary_panel
     */
    it('분모는 주문 요약과 동일한 item_count 폴백을 쓴다', () => {
        const counter = counterTexts.find((t) => t.startsWith('('));
        expect(counter).toContain('cartItems.data.item_count ?? 0');
    });
});

describe('_modal_cart_option_change.json — 재고 표시 옵셔널 가드 (B-6/B-7)', () => {
    const refs = collectStringsContaining(cartOptionChangeModal, 'matchedOption');

    /**
     * @scenario mount_path=initial_load, selection=all
     * @effects modal_stock_display_guarded
     */
    it('matchedOption 하위 접근은 전부 옵셔널 체이닝을 쓴다', () => {
        expect(refs.length).toBeGreaterThan(0);
        const unguarded = refs.filter((r) => /matchedOption\.[A-Za-z_]/.test(r));
        expect(
            unguarded,
            `matchedOption 뒤에 '?.' 없는 역참조: ${unguarded.join(' | ')}`
        ).toEqual([]);
    });
});

describe('_edit.json — 알림 수신 칸은 확장별 카드가 그리고, 저장값 타입과 무관하게 체크를 정한다', () => {
    /** 게시판 알림 설정 체크박스 4종 */
    const NOTIFY_FIELDS = [
        'notify_post_complete',
        'notify_post_reply',
        'notify_comment',
        'notify_reply_comment',
    ];

    /** 조건에 맞는 노드를 모두 모은다 */
    function findNodes(tree: unknown, match: (n: Record<string, unknown>) => boolean): Record<string, any>[] {
        const out: Record<string, any>[] = [];
        walk(tree, (n) => {
            if (match(n)) out.push(n);
        });
        return out;
    }

    /** 게시판이 회원 항목으로 선언한 알림 수신 칸의 항목 계약 표본 */
    const notifyField = (name: string) => ({
        key: name,
        name,
        type: 'boolean',
        label: name,
        required: false,
        options: [],
        constraints: { widget: 'checkbox' },
    });

    afterEach(() => {
        cleanup();
    });

    /**
     * 7.2.0: 알림 수신 칸은 수정 화면에 박혀 있지 않다. 게시판 모듈이 회원 항목으로 선언하고, 수정 화면의
     * 확장별 카드가 공용 항목 조각(partials/member/_member_fields)으로 그린다. 종전 명시 바인딩이 막던 결함
     * (저장값이 boolean 이 아닐 때 체크가 풀리고 빈 문자열이 전송되어 저장값이 null 로 고착)은 이제 공용 입력
     * 컴포넌트(MemberFieldInput)가 막는다 — 값의 타입과 무관하게 checked 를 정하고 항상 true/false 를 내보낸다.
     * 컴포넌트의 예/아니오 계약 자체는 src/components/composite/__tests__/MemberFieldInput.test.tsx 가 잠근다.
     *
     * 마운트 경로·선택 상태 축과 무관한 정적 계약이라 @scenario 는 달지 않는다
     * (조합 커버리지는 위 9건이 이미 전수 담당한다).
     *
     * @effects profile_notification_checkbox_explicit_binding
     */
    it('수정 화면에 박힌 알림 수신 칸이 없고, 확장별 카드가 공용 항목 조각으로 그린다', () => {
        const raw = JSON.stringify(profileEditPartial);
        for (const field of NOTIFY_FIELDS) {
            expect(raw, `${field} 가 수정 화면에 박혀 있다`).not.toContain(field);
        }

        const cards = findNodes(profileEditPartial, (n) => n.id === 'profile_extension_card_{{memberFieldGroup.key}}');
        expect(cards).toHaveLength(1);
        expect(cards[0].iteration?.source).toBe('{{$computed.profileExtensionGroups ?? []}}');
        expect(findNodes(cards[0], (n) => n.partial === 'partials/member/_member_fields.json')).toHaveLength(1);

        // 공용 항목 조각은 항목 계약을 통째로 입력 컴포넌트에 넘긴다 — 자동바인딩에 기대는 낱개 Checkbox 가 없다
        const inputs = findNodes(memberFieldsPartial, (n) => n.name === 'MemberFieldInput');
        expect(inputs).toHaveLength(1);
        expect(inputs[0].props.field).toBe('{{field}}');
        expect(inputs[0].props.name).toBe('{{field.name}}');
        expect(findNodes(memberFieldsPartial, (n) => n.name === 'Checkbox')).toEqual([]);
    });

    /**
     * @effects profile_notification_checkbox_explicit_binding
     */
    it.each(NOTIFY_FIELDS)('%s 는 저장값의 타입과 무관하게 checked 를 정하고 true/false 를 내보낸다', (field) => {
        const emitted: unknown[] = [];
        (window as any).G7Core = {
            ...((window as any).G7Core ?? {}),
            t: (key: string) => key,
            createChangeEvent: ({ value, name }: any) => ({ target: { value, name } }),
        };
        const onChange = (event: any) => emitted.push([event.target.name, event.target.value]);
        const draw = (value: unknown) => <MemberFieldInput field={notifyField(field)} name={field} value={value} onChange={onChange} />;
        const box = () => document.querySelector(`input[name="${field}"]`) as HTMLInputElement;

        // 저장값이 boolean 이 아니어도(1, '1') 체크되고, 비어 있으면(null, undefined, 0, '') 풀린다
        const { rerender } = render(draw(true));
        for (const [value, checked] of [[true, true], [1, true], ['1', true], [false, false], [0, false], [null, false], [undefined, false], ['', false]] as const) {
            rerender(draw(value));
            expect(box().checked, `값 ${JSON.stringify(value)}`).toBe(checked);
        }

        // 누르면 빈 문자열이 아니라 boolean 을 항목 이름과 함께 내보낸다
        rerender(draw(null));
        fireEvent.click(box());
        rerender(draw(1));
        fireEvent.click(box());
        expect(emitted).toEqual([[field, true], [field, false]]);
    });
});

describe('_edit.json — 프로필 오류 메시지 옵셔널 가드 (회색지대)', () => {
    const refs = collectStringsContaining(profileEditPartial, 'profileErrors');

    /**
     * @scenario mount_path=initial_load, selection=none
     * @effects profile_error_display_guarded
     */
    it('profileErrors 하위 접근은 전부 옵셔널 체이닝을 쓴다', () => {
        expect(refs.length).toBeGreaterThan(0);
        const unguarded = refs.filter((r) => /profileErrors\.[A-Za-z_]/.test(r));
        expect(
            unguarded,
            `profileErrors 뒤에 '?.' 없는 역참조: ${unguarded.join(' | ')}`
        ).toEqual([]);
    });

    /**
     * @scenario mount_path=initial_load, selection=partial
     * @effects profile_error_display_guarded
     */
    it('오류 메시지 표시식은 배열 인덱스에도 옵셔널 + 빈 문자열 폴백을 쓴다', () => {
        const messageTexts = refs.filter((r) => /profileErrors\??\.[A-Za-z_]+\??\.\[0\]/.test(r));
        expect(messageTexts.length, '오류 메시지 표시식을 찾지 못했다').toBeGreaterThan(0);
        for (const t of messageTexts) {
            expect(t, `폴백 없는 오류 메시지 표시식: ${t}`).toContain("?? ''");
        }
    });
});
