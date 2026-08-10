// e2e:allow 레이아웃 트리 위치 검증(정적 구조 회귀 가드)이라 vitest 로 충분. 실제 화면 렌더는
// Chrome MCP 로 확인·수정까지 완료(2026-07-28). 알림설정 화면 Playwright 인프라 부재
/**
 * 이커머스 알림 정의 행 하단 확장 슬롯(notification_definition_row_footer) 위치 회귀 가드
 *
 * @description
 * 회귀 시나리오: extension_point 를 행 정보 컬럼(`flex-1 min-w-0`) 밖에 형제로 잘못 넣으면,
 * 확장이 이 슬롯에 주입하는 버튼이 정보 컬럼과 토글·편집 버튼 컬럼
 * (`flex-center gap-3 flex-shrink-0`) 사이의 3번째 flex 아이템이 되어 옆으로 붙어 보인다
 * (변수 뱃지 아래 새 줄이 아니라 오른쪽 버튼 옆으로 밀림).
 *
 * extension_point 는 반드시 정보 컬럼의 children 배열 마지막 원소(variables 뱃지의 형제)여야
 * 하며, 토글/편집 버튼을 담는 형제 Div 의 children 에는 나타나지 않아야 한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import tab from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_notification_definitions.json';

type AnyNode = Record<string, unknown> & {
    id?: string;
    type?: string;
    name?: string;
    props?: { className?: string };
    children?: AnyNode[];
};

function findAll(node: AnyNode | undefined, pred: (n: AnyNode) => boolean, results: AnyNode[] = []): AnyNode[] {
    if (!node) return results;
    if (pred(node)) results.push(node);
    if (Array.isArray(node.children)) {
        for (const c of node.children) findAll(c, pred, results);
    }
    return results;
}

describe('이커머스 알림 행 footer extension_point 위치', () => {
    it('extension_point 는 정보 컬럼(flex-1 min-w-0)의 children 이다', () => {
        const infoColumns = findAll(tab as AnyNode, (n) => n.props?.className === 'flex-1 min-w-0');
        expect(infoColumns.length).toBeGreaterThan(0);

        const infoColumnHasEp = infoColumns.some((col) =>
            (col.children ?? []).some((c) => c.type === 'extension_point' && c.name === 'notification_definition_row_footer'),
        );
        expect(infoColumnHasEp).toBe(true);
    });

    it('extension_point 는 토글·편집 버튼 컬럼(flex-center gap-3 flex-shrink-0)의 children 이 아니다', () => {
        const buttonColumns = findAll(
            tab as AnyNode,
            (n) => n.props?.className === 'flex-center gap-3 flex-shrink-0',
        );
        expect(buttonColumns.length).toBeGreaterThan(0);

        const buttonColumnHasEp = buttonColumns.some((col) =>
            (col.children ?? []).some((c) => c.type === 'extension_point'),
        );
        expect(buttonColumnHasEp).toBe(false);
    });

    it('extension_point 가 정보 컬럼과 버튼 컬럼의 공통 부모(행 wrapper)에 직접 걸려 있지 않다', () => {
        const rowWrappers = findAll(
            tab as AnyNode,
            (n) =>
                Array.isArray(n.children) &&
                n.children.some((c) => c.props?.className === 'flex-1 min-w-0') &&
                n.children.some((c) => c.props?.className === 'flex-center gap-3 flex-shrink-0'),
        );
        expect(rowWrappers.length).toBeGreaterThan(0);

        for (const wrapper of rowWrappers) {
            const directEp = (wrapper.children ?? []).some((c) => c.type === 'extension_point');
            expect(directEp, 'extension_point 가 행 wrapper 의 3번째 flex 형제가 되면 옆으로 붙는 회귀').toBe(false);
        }
    });
});