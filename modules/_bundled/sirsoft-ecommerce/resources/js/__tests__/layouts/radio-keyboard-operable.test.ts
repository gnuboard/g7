/**
 * 라디오 선택이 키보드로도 조작 가능한지 계약 테스트 (#493 후속)
 *
 * 배경: 라디오를 커스텀 스타일로 그리기 위해 `<Label>` 로 감싸고, 라디오 자체는
 *   `pointer-events-none` 으로 클릭을 통과시킨 뒤 Label 의 click 액션이 상태를 바꾼다.
 *
 * 결함: 이 구조에서 상태를 바꾸는 유일한 경로가 **click** 이다. 키보드 사용자는 라디오에
 *   포커스를 두고 방향키로 항목을 옮기는데, 그때 발생하는 것은 click 이 아니라 change 다.
 *   즉 화면상 선택 표시는 움직이는데 저장될 값은 따라오지 않는다 — 눈에 보이는 것과
 *   저장되는 것이 어긋나므로 잘못된 값을 저장하고도 알 수 없다.
 *
 *   실측(2026-07-29, 마일리지 한도 설정): 마우스 클릭은 정상. 라디오 2개가 DOM 에
 *   같은 `value` 로 렌더되는 것도 이 구조의 부산물이다(상태는 `checked` 바인딩이 결정).
 *
 * 회귀 차단 포인트:
 *   1. 라디오는 클릭을 받을 수 있어야 한다 (`pointer-events-none` 금지).
 *   2. 라디오 자신이 change 액션을 가져야 한다 — 키보드 조작의 유일한 신호다.
 *   3. change 액션이 설정하는 값은 감싼 Label 의 click 액션과 같아야 한다
 *      (둘이 갈라지면 마우스와 키보드가 다른 값을 저장한다).
 */

import { describe, it, expect } from 'vitest';

import currencyCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_currency_cards.json';
import currencyTable from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_mileage_currency_table.json';

type Node = Record<string, unknown>;

/**
 * 레이아웃 트리에서 radio 입력 노드와 그것을 감싼 액션 보유 조상을 모두 수집합니다.
 *
 * @param node 탐색 대상
 * @param ancestor 현재까지의 최근접 액션 보유 조상
 * @param out 수집 결과
 * @returns 수집된 (radio, ancestor) 쌍 목록
 */
function collectRadios(
    node: unknown,
    ancestor: Node | null = null,
    out: Array<{ node: Node; props: Node; ancestor: Node | null }> = []
): Array<{ node: Node; props: Node; ancestor: Node | null }> {
    if (Array.isArray(node)) {
        node.forEach((child) => collectRadios(child, ancestor, out));

        return out;
    }

    if (node && typeof node === 'object') {
        const current = node as Node;
        const props = (current.props ?? {}) as Node;

        if (props.type === 'radio') {
            out.push({ node: current, props, ancestor });
        }

        const nextAncestor = Array.isArray(current.actions) ? current : ancestor;

        Object.values(current).forEach((value) => collectRadios(value, nextAncestor, out));
    }

    return out;
}

/**
 * 액션 목록에서 지정 이벤트 타입의 액션을 찾습니다.
 *
 * @param owner 액션 보유 노드
 * @param type 이벤트 타입
 * @returns 찾은 액션 (없으면 undefined)
 */
function actionOfType(owner: Node | null, type: string): Node | undefined {
    const actions = (owner?.actions ?? []) as Node[];

    return Array.isArray(actions) ? actions.find((a) => a.type === type) : undefined;
}

const layouts: Array<[string, unknown]> = [
    ['마일리지 통화 카드(모바일)', currencyCards],
    ['마일리지 통화 표(데스크탑)', currencyTable],
];

describe('라디오 키보드 조작 계약 (#493 후속)', () => {
    it.each(layouts)('%s — 라디오가 클릭을 받을 수 있다', (_label, layout) => {
        const radios = collectRadios(layout);

        expect(radios.length, '라디오를 찾지 못했습니다 — 검사가 무력화됐습니다').toBeGreaterThan(0);

        radios.forEach(({ props }) => {
            expect(
                String(props.className ?? ''),
                `${props.name}: pointer-events-none 이면 키보드 포커스로 바꾼 값이 저장되지 않습니다`
            ).not.toContain('pointer-events-none');
        });
    });

    it.each(layouts)('%s — 라디오가 change 액션을 가지고, 값이 Label 클릭과 같다', (_label, layout) => {
        const radios = collectRadios(layout);

        radios.forEach(({ node, props, ancestor }) => {
            const change = actionOfType(node, 'change');

            expect(
                change,
                `${props.name}: 라디오 자신에 change 액션이 없습니다 — 키보드 조작 신호를 받을 곳이 없습니다`
            ).toBeDefined();

            const click = actionOfType(ancestor, 'click');

            expect(click, `${props.name}: 감싼 요소의 click 액션을 찾지 못했습니다`).toBeDefined();

            // 마우스와 키보드가 다른 값을 저장하면 조작 방법에 따라 결과가 갈린다
            expect(
                JSON.stringify(change?.params),
                `${props.name}: change 와 click 이 서로 다른 값을 저장합니다`
            ).toBe(JSON.stringify(click?.params));
        });
    });
});
