import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PermissionTree, PermissionNode, ScopeOption } from '../PermissionTree';

/**
 * 권한 범위(스코프) 드롭다운 노출 조건 회귀 가드.
 *
 * 배경: 범위 드롭다운은 「체크했고 + 리소스 라우트 키가 있으면」 떴다. 그래서
 * - 비회원 역할에도 드롭다운이 떠, 서버가 적용하지 못하는 「본인만」을 저장할 수 있었다
 *   (비회원은 소유자 개념이 없어 스코프 판정 자체가 생략된다 — 고르는 순간 아무 제한 없는 부여가 됨).
 * - 소유자 컬럼(owner_key)이 없는 권한에도 드롭다운이 떠, 고를 수는 있지만 적용되지 않는 거짓 선택지가 됐다.
 *
 * 처치: `scopeDisabled` prop 으로 역할 단위 숨김, 노드 `scope_supported`(서버 판정) 우선 —
 * 없으면 `resource_route_key && owner_key` 로 폴백.
 */

const SCOPE_OPTIONS: ScopeOption[] = [
    { value: null, label: '범위-전체' },
    { value: 'role', label: '범위-소유역할' },
    { value: 'self', label: '범위-본인만' },
];

/**
 * 리프 권한 하나를 품은 모듈 노드를 만든다.
 *
 * @param leaf 리프 권한 덮어쓸 값
 * @returns 트리 데이터
 */
function treeWith(leaf: Partial<PermissionNode>): PermissionNode[] {
    return [
        {
            id: 100,
            identifier: 'sirsoft-board',
            name: '게시판',
            extension_type: 'module',
            extension_identifier: 'sirsoft-board',
            is_assignable: false,
            leaf_count: 1,
            children: [
                {
                    id: 1,
                    identifier: 'sirsoft-board.qa.posts.read',
                    name: '게시글 조회',
                    is_assignable: true,
                    leaf_count: 1,
                    children: [],
                    ...leaf,
                },
            ],
        },
    ];
}

/**
 * 렌더된 범위 드롭다운 수(현재 선택 라벨이 보이는 트리거 수)를 센다.
 */
function countScopeDropdowns(): number {
    return screen.queryAllByText('범위-전체').length;
}

describe('PermissionTree — 범위 드롭다운 노출 조건', () => {
    // @scenario role=general, support=scope_supported
    // @effects general_role_scope_dropdown_unchanged
    it('기존 표시 무회귀: 체크 + resource_route_key + owner_key 가 있으면 드롭다운이 뜬다', () => {
        render(
            <PermissionTree
                data={treeWith({ resource_route_key: 'post', owner_key: 'user_id' })}
                value={[{ id: 1, scope_type: null }]}
                scopeOptions={SCOPE_OPTIONS}
            />
        );

        expect(countScopeDropdowns()).toBe(1);
    });

    // @scenario role=general, support=scope_supported
    // @effects general_role_scope_dropdown_unchanged
    it('체크하지 않은 권한에는 드롭다운이 없다', () => {
        render(
            <PermissionTree
                data={treeWith({ resource_route_key: 'post', owner_key: 'user_id' })}
                value={[]}
                scopeOptions={SCOPE_OPTIONS}
            />
        );

        expect(countScopeDropdowns()).toBe(0);
    });

    // @scenario role=guest, support=scope_supported
    // @effects scope_dropdown_hidden_for_guest_role
    it('scopeDisabled 이면 체크한 범위 지원 권한이어도 드롭다운을 숨긴다 (비회원 역할)', () => {
        render(
            <PermissionTree
                data={treeWith({ resource_route_key: 'post', owner_key: 'user_id', scope_supported: true })}
                value={[{ id: 1, scope_type: null }]}
                scopeOptions={SCOPE_OPTIONS}
                scopeDisabled
            />
        );

        expect(countScopeDropdowns()).toBe(0);
    });

    // @scenario role=general, support=owner_key_missing
    // @effects scope_dropdown_hidden_without_owner_key
    it('owner_key 가 없으면(scope_supported 미제공) 드롭다운을 숨긴다 — 적용되지 않는 거짓 선택지', () => {
        render(
            <PermissionTree
                data={treeWith({ resource_route_key: 'memo', owner_key: null })}
                value={[{ id: 1, scope_type: null }]}
                scopeOptions={SCOPE_OPTIONS}
            />
        );

        expect(countScopeDropdowns()).toBe(0);
    });

    // @scenario role=general, support=scope_supported
    // @effects server_scope_flag_overrides_key_fallback
    it('scope_supported 가 있으면 그 값이 키 조합보다 우선한다 (false → 숨김)', () => {
        render(
            <PermissionTree
                data={treeWith({ resource_route_key: 'post', owner_key: 'user_id', scope_supported: false })}
                value={[{ id: 1, scope_type: null }]}
                scopeOptions={SCOPE_OPTIONS}
            />
        );

        expect(countScopeDropdowns()).toBe(0);
    });

    // @scenario role=general, support=owner_key_missing
    // @effects server_scope_flag_overrides_key_fallback
    it('scope_supported 가 있으면 그 값이 키 조합보다 우선한다 (true → 표시)', () => {
        render(
            <PermissionTree
                data={treeWith({ resource_route_key: null, owner_key: null, scope_supported: true })}
                value={[{ id: 1, scope_type: null }]}
                scopeOptions={SCOPE_OPTIONS}
            />
        );

        expect(countScopeDropdowns()).toBe(1);
    });

    // @scenario role=general, support=scope_supported
    // @effects general_role_scope_dropdown_unchanged
    it('scopeOptions 가 비면 드롭다운이 없다', () => {
        render(
            <PermissionTree
                data={treeWith({ resource_route_key: 'post', owner_key: 'user_id', scope_supported: true })}
                value={[{ id: 1, scope_type: null }]}
                scopeOptions={[]}
            />
        );

        expect(countScopeDropdowns()).toBe(0);
    });

    // @scenario role=guest, support=owner_key_missing
    // @effects scope_dropdown_hidden_for_guest_role, scope_dropdown_hidden_without_owner_key
    it('scopeDisabled 이고 owner_key 도 없으면 당연히 숨긴다', () => {
        render(
            <PermissionTree
                data={treeWith({ resource_route_key: 'memo', owner_key: null })}
                value={[{ id: 1, scope_type: null }]}
                scopeOptions={SCOPE_OPTIONS}
                scopeDisabled
            />
        );

        expect(countScopeDropdowns()).toBe(0);
    });
});
