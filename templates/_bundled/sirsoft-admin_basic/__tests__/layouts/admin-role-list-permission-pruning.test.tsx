/**
 * @file admin-role-list-permission-pruning.test.tsx
 * @description 역할 관리 목록 — 권한 배열 프루닝 후 화면 계약 회귀 테스트
 *
 * 회귀 배경 (#518 / 공개 #76):
 * 역할 목록 응답이 행마다 `permissions` 트리와 `permission_ids`/`permission_values` 를 3중으로
 * 싣고, Repository 가 `with('permissions')` 로 무조건 로드해 행당 `Permission::whereIn()` 이
 * 두 번씩 나갔다. 목록 화면은 이 3필드를 전혀 그리지 않으므로 목록에서 뺐고, 대신 규모를
 * 보여줄 `permissions_count` 집계를 새로 노출했다.
 *
 * 이 테스트가 잠그는 것은 두 방향이다.
 *
 *  1. **소비** — 목록 레이아웃이 뺀 3필드를 어디에서도 참조하지 않는다. 참조가 하나라도
 *     남으면 그 자리가 조용히 빈다(예외도 콘솔 경고도 없다).
 *  2. **공급** — 그 대신 들어온 `permissions_count` 를 화면이 실제로 그린다. 서버가 집계를
 *     넣어도 화면에 열이 없으면 운영자에게는 "정보가 사라진" 것과 같다.
 *
 * 한쪽만 보면 어느 방향으로 되돌아가도 통과한다 — 그래서 같은 파일에서 함께 고정한다.
 *
 * @scenario resource=role,endpoint=list,observation=consumer_screen
 * @effects role_list_omits_permission_payload
 * @effects role_list_renders_permission_count
 */

import React from 'react';
import fs from 'fs';
import path from 'path';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const LAYOUT_PATH = path.resolve(__dirname, '../../layouts/admin_role_list.json');

/** 목록에서 제거된 필드 — 행 객체에서 이 이름들을 읽으면 회귀다 */
const PRUNED_ROW_FIELDS = ['permissions', 'permission_ids', 'permission_values'];

/**
 * 레이아웃 JSON 전체에서 `{{ }}` 바인딩 문자열을 모두 수집합니다.
 *
 * 컬럼 정의·조건·props·텍스트 어디에 있든 걸리도록 값 전체를 훑는다. 키 이름이 아니라
 * **표현식** 만 모으는 것이 핵심 — 레이아웃 최상위의 `permissions`(화면 접근 권한 선언)는
 * 행 필드와 무관하므로 이름만 보면 오탐한다.
 *
 * @param node 탐색할 JSON 노드
 * @param acc 수집 결과 누적 배열
 * @returns 수집된 바인딩 표현식 목록
 */
function collectBindings(node: unknown, acc: string[] = []): string[] {
    if (typeof node === 'string') {
        const matches = node.match(/\{\{[^}]*\}\}/g);

        if (matches) {
            acc.push(...matches);
        }

        return acc;
    }

    if (Array.isArray(node)) {
        node.forEach((child) => collectBindings(child, acc));

        return acc;
    }

    if (node && typeof node === 'object') {
        Object.values(node as Record<string, unknown>).forEach((child) =>
            collectBindings(child, acc),
        );
    }

    return acc;
}

/**
 * 데이터그리드 컬럼 정의를 전부 모읍니다 (중첩 파셜/슬롯 무관).
 *
 * @param node 탐색할 JSON 노드
 * @param acc 수집 결과 누적 배열
 * @returns `field` 키를 가진 컬럼 객체 목록
 */
function collectColumns(node: unknown, acc: Record<string, any>[] = []): Record<string, any>[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collectColumns(child, acc));

        return acc;
    }

    if (node && typeof node === 'object') {
        const obj = node as Record<string, any>;

        if (typeof obj.field === 'string' && 'header' in obj) {
            acc.push(obj);
        }

        Object.values(obj).forEach((child) => collectColumns(child, acc));
    }

    return acc;
}

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode }> = ({
    className,
    children,
}) => <div className={className}>{children}</div>;

const TestSpan: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> = ({
    className,
    children,
    text,
}) => (
    <span className={className} data-testid="permission-count-cell">
        {/*
          `||` 로 폴백하면 숫자 0 이 빈칸으로 접혀, 권한 0건 역할이 "빈칸" 으로 보이는지
          "0" 으로 보이는지를 이 테스트가 구분하지 못하게 된다. 널 병합으로 받는다.
        */}
        {(children ?? text) as React.ReactNode}
    </span>
);

const TestIcon: React.FC<{ name?: string; size?: string }> = ({ name, size }) => (
    <i data-testid={`icon-${name}`} data-icon={name} data-size={size} />
);

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

/**
 * 테스트용 컴포넌트 레지스트리를 구성합니다.
 *
 * `Fragment` 는 반드시 등록해야 한다 — 테스트 하니스가 슬롯 전체를 `Fragment` 루트로 감싸
 * 렌더하므로, 빠지면 예외 없이 화면 전체가 비어 모든 단언이 "요소를 찾을 수 없음" 으로 깨진다.
 *
 * @returns 구성된 ComponentRegistry 인스턴스
 */
function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();

    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
        Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    };

    return registry;
}

describe('역할 관리 목록 — 권한 배열 프루닝 (#518 / 공개 #76)', () => {
    let layout: Record<string, any>;

    beforeEach(() => {
        layout = JSON.parse(fs.readFileSync(LAYOUT_PATH, 'utf-8'));
    });

    describe('소비 — 목록에서 뺀 필드를 화면이 읽지 않는다', () => {
        it('행 바인딩이 permissions / permission_ids / permission_values 를 참조하지 않는다', () => {
            const bindings = collectBindings(layout);

            // 모집단 확인이 먼저다. 수집이 0건이면 아래 부재 단언은 아무것도 증명하지 못한 채
            // 통과한다 (레이아웃 구조가 바뀌어 수집기가 헛돌아도 초록이 된다).
            expect(bindings.length, '레이아웃에서 바인딩 표현식이 수집되어야 한다').toBeGreaterThan(
                20,
            );

            const offenders = bindings.filter((binding) =>
                PRUNED_ROW_FIELDS.some((field) =>
                    new RegExp(`\\brow\\.${field}\\b`).test(binding),
                ),
            );

            expect(
                offenders,
                '목록 응답에서 제거된 권한 필드를 화면이 참조하면 그 자리가 조용히 빈다',
            ).toEqual([]);
        });

        it('제거된 필드를 컬럼 field 로 쓰지 않는다', () => {
            const columns = collectColumns(layout);

            expect(columns.length, '데이터그리드 컬럼이 수집되어야 한다').toBeGreaterThan(0);

            const offenders = columns
                .map((column) => column.field)
                .filter((field) => PRUNED_ROW_FIELDS.includes(field));

            expect(offenders).toEqual([]);
        });
    });

    describe('공급 — 대체 지표가 화면에 남아 있다', () => {
        it('permissions_count 열이 존재하고 집계 필드에 바인딩된다', () => {
            const columns = collectColumns(layout);
            const column = columns.find((candidate) => candidate.field === 'permissions_count');

            expect(column, '권한 수 열이 있어야 한다 — 목록에서 권한 배열을 뺀 대체 지표다').toBeDefined();

            const bindings = collectBindings(column);

            expect(
                bindings.some((binding) => /\brow\.permissions_count\b/.test(binding)),
                '권한 수 열이 서버 집계(permissions_count)를 읽어야 한다',
            ).toBe(true);
        });

        it('권한 수 헤더가 다국어 키로 선언된다 (하드코딩 금지)', () => {
            const columns = collectColumns(layout);
            const column = columns.find((candidate) => candidate.field === 'permissions_count');

            expect(column?.header).toBe('$t:admin.roles.columns.permissions_count');
        });
    });

    describe('렌더 — 0건 역할이 빈칸이 아니라 0 으로 보인다', () => {
        let registry: ComponentRegistry;
        let testUtils: ReturnType<typeof createLayoutTest>;

        beforeEach(() => {
            registry = setupTestRegistry();
        });

        afterEach(() => {
            testUtils?.cleanup();
            (registry as any).registry = {};
        });

        /**
         * 권한 수 셀만 떼어낸 최소 레이아웃을 만듭니다.
         *
         * 실제 레이아웃의 `cellChildren` 을 그대로 가져다 쓰되, 행 변수만 재바인딩한다.
         * `row` 는 데이터그리드 반복 변수라 그리드 밖에서는 해석되지 않으므로 `_global.row`
         * 로 옮긴다. 셀 정의를 손으로 옮겨 적으면 원본이 바뀌어도 이 테스트는 계속 통과하므로,
         * 컨텍스트 접두사만 바꾸고 구조·표현식은 원본 그대로 둔다.
         *
         * @param row 렌더할 행 객체
         * @returns 렌더 가능한 레이아웃 정의
         */
        function createCellLayout(row: Record<string, any>) {
            const columns = collectColumns(JSON.parse(fs.readFileSync(LAYOUT_PATH, 'utf-8')));
            const column = columns.find((candidate) => candidate.field === 'permissions_count');

            const rebased = JSON.parse(
                JSON.stringify(column!.cellChildren).replace(/\{\{([^}]*)\}\}/g, (whole, expr) =>
                    `{{${expr.replace(/\brow\./g, '_global.row.')}}}`,
                ),
            );

            return {
                version: '1.0.0',
                layout_name: 'admin_role_list_permission_count_cell_test',
                initGlobal: { row },
                slots: {
                    content: rebased,
                },
            };
        }

        it('권한 0건 역할은 0 으로 렌더된다 (집계 0 과 미집계를 화면이 구분한다)', async () => {
            testUtils = createLayoutTest(
                createCellLayout({ id: 1, permissions_count: 0 }) as any,
                { componentRegistry: registry },
            );

            await testUtils.render();

            expect(screen.getByTestId('permission-count-cell')).toHaveTextContent('0');
        });

        it('권한이 있는 역할은 그 수가 렌더된다', async () => {
            testUtils = createLayoutTest(
                createCellLayout({ id: 2, permissions_count: 51 }) as any,
                { componentRegistry: registry },
            );

            await testUtils.render();

            expect(screen.getByTestId('permission-count-cell')).toHaveTextContent('51');
        });

        it('집계 키가 없어도 빈칸 대신 0 으로 떨어진다 (?? 0 폴백)', async () => {
            testUtils = createLayoutTest(createCellLayout({ id: 3 }) as any, {
                componentRegistry: registry,
            });

            await testUtils.render();

            expect(screen.getByTestId('permission-count-cell')).toHaveTextContent('0');
        });
    });
});
