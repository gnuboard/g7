/**
 * PermissionSelector.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 권한 선택기 컴포넌트
 *
 * 역할:
 * - 레이아웃 편집 시 permissions 필드 설정
 * - 권한 목록 API 조회 및 트리 표시
 * - 권한 ID와 명칭 함께 표시
 */

import React, { useState, useEffect, useCallback } from 'react';

// ============================================================================
// 타입 정의
// ============================================================================

interface PermissionOption {
    id: number;
    identifier: string;
    name: string;
    description?: string;
    children?: PermissionOption[];
}

interface PermissionGroup {
    label: string;
    icon: string;
    permissions: PermissionOption[];
}

export interface PermissionSelectorProps {
    /** 선택된 권한 ID 배열 */
    value: string[];
    /** 변경 시 콜백 */
    onChange: (value: string[]) => void;
    /** 비활성화 여부 */
    disabled?: boolean;
    /** 플레이스홀더 텍스트 */
    placeholder?: string;
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export const PermissionSelector: React.FC<PermissionSelectorProps> = ({
    value,
    onChange,
    disabled = false,
    placeholder = '권한을 선택하세요',
}) => {
    const [permissionGroups, setPermissionGroups] = useState<Record<string, PermissionGroup>>({});
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [expandedGroups, setExpandedGroups] = useState<Set<string>>(new Set(['admin']));
    const [searchQuery, setSearchQuery] = useState('');

    // 권한 목록 조회
    useEffect(() => {
        const fetchPermissions = async () => {
            try {
                setLoading(true);
                setError(null);
                const response = await fetch('/api/admin/permissions', {
                    headers: {
                        'Authorization': `Bearer ${localStorage.getItem('auth_token')}`,
                        'Accept': 'application/json',
                    },
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const data = await response.json();
                // API 응답 구조: { success, message, data: { data: { permissions: {...} } } }
                setPermissionGroups(data.data?.data?.permissions || {});
            } catch (e) {
                console.error('권한 목록 조회 실패:', e);
                setError('권한 목록을 불러올 수 없습니다');
            } finally {
                setLoading(false);
            }
        };
        fetchPermissions();
    }, []);

    // 권한 토글
    const togglePermission = useCallback((identifier: string) => {
        if (disabled) return;
        if (value.includes(identifier)) {
            onChange(value.filter(v => v !== identifier));
        } else {
            onChange([...value, identifier]);
        }
    }, [value, onChange, disabled]);

    // 그룹 토글
    const toggleGroup = useCallback((groupKey: string) => {
        setExpandedGroups(prev => {
            const next = new Set(prev);
            if (next.has(groupKey)) {
                next.delete(groupKey);
            } else {
                next.add(groupKey);
            }
            return next;
        });
    }, []);

    // 모든 권한 선택/해제
    const selectAll = useCallback(() => {
        if (disabled) return;
        const allIdentifiers: string[] = [];
        const extractIdentifiers = (perms: PermissionOption[]) => {
            perms.forEach(p => {
                allIdentifiers.push(p.identifier);
                if (p.children?.length) extractIdentifiers(p.children);
            });
        };
        Object.values(permissionGroups).forEach(group => {
            if (group.permissions) extractIdentifiers(group.permissions);
        });
        onChange(allIdentifiers);
    }, [permissionGroups, onChange, disabled]);

    const clearAll = useCallback(() => {
        if (disabled) return;
        onChange([]);
    }, [onChange, disabled]);

    // 검색 필터
    const filterPermissions = useCallback((perms: PermissionOption[]): PermissionOption[] => {
        if (!searchQuery.trim()) return perms;
        const query = searchQuery.toLowerCase();
        const filter = (p: PermissionOption): PermissionOption | null => {
            const matchesName = p.name.toLowerCase().includes(query);
            const matchesId = p.identifier.toLowerCase().includes(query);
            const filteredChildren = p.children?.map(filter).filter(Boolean) as PermissionOption[] | undefined;
            if (matchesName || matchesId || (filteredChildren && filteredChildren.length > 0)) {
                return { ...p, children: filteredChildren };
            }
            return null;
        };
        return perms.map(filter).filter(Boolean) as PermissionOption[];
    }, [searchQuery]);

    // 권한 트리 렌더링
    const renderPermission = (perm: PermissionOption, depth = 0) => {
        const isSelected = value.includes(perm.identifier);
        const hasChildren = perm.children && perm.children.length > 0;

        return (
            <div key={perm.identifier}>
                <label
                    className={`flex items-center gap-2 py-1.5 px-2 rounded cursor-pointer transition-colors
                        ${disabled ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-gray-700'}
                        ${isSelected ? 'bg-blue-50 dark:bg-blue-900/30' : ''}
                    `}
                    style={{ paddingLeft: `${8 + depth * 16}px` }}
                >
                    <input
                        type="checkbox"
                        checked={isSelected}
                        onChange={() => togglePermission(perm.identifier)}
                        disabled={disabled}
                        className="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500"
                    />
                    <span className="flex-1 text-sm text-gray-900 dark:text-gray-100">
                        {perm.name}
                        <span className="ml-2 text-xs text-gray-500 dark:text-gray-400">
                            ({perm.identifier})
                        </span>
                    </span>
                </label>
                {hasChildren && perm.children?.map(child => renderPermission(child, depth + 1))}
            </div>
        );
    };

    // 로딩 상태
    if (loading) {
        return (
            <div className="p-4 text-center text-sm text-gray-500 dark:text-gray-400">
                <span className="inline-block animate-spin mr-2">⏳</span>
                권한 목록 로딩 중...
            </div>
        );
    }

    // 에러 상태
    if (error) {
        return (
            <div className="p-4 text-center text-sm text-red-500 dark:text-red-400">
                {error}
            </div>
        );
    }

    const groupEntries = Object.entries(permissionGroups);

    return (
        <div className="border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 overflow-hidden">
            {/* 헤더 */}
            <div className="p-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
                <div className="flex items-center justify-between mb-2">
                    <span className="text-xs font-medium text-gray-700 dark:text-gray-300">
                        선택된 권한: <span className="text-blue-600 dark:text-blue-400">{value.length}개</span>
                        {value.length === 0 && <span className="text-gray-500 ml-1">(공개 레이아웃)</span>}
                    </span>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={selectAll}
                            disabled={disabled}
                            className="text-xs text-blue-600 dark:text-blue-400 hover:underline disabled:opacity-50"
                        >
                            전체 선택
                        </button>
                        <button
                            type="button"
                            onClick={clearAll}
                            disabled={disabled || value.length === 0}
                            className="text-xs text-red-600 dark:text-red-400 hover:underline disabled:opacity-50"
                        >
                            전체 해제
                        </button>
                    </div>
                </div>
                {/* 검색 */}
                <input
                    type="text"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    placeholder="권한 검색..."
                    className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                />
            </div>

            {/* 권한 그룹 목록 */}
            <div className="max-h-64 overflow-y-auto">
                {groupEntries.length === 0 ? (
                    <div className="p-4 text-center text-sm text-gray-500 dark:text-gray-400">
                        권한이 없습니다
                    </div>
                ) : (
                    groupEntries.map(([groupKey, group]) => {
                        const filteredPermissions = filterPermissions(group.permissions || []);
                        if (searchQuery && filteredPermissions.length === 0) return null;

                        const isExpanded = expandedGroups.has(groupKey);

                        return (
                            <div key={groupKey} className="border-b border-gray-100 dark:border-gray-800 last:border-b-0">
                                {/* 그룹 헤더 */}
                                <button
                                    type="button"
                                    onClick={() => toggleGroup(groupKey)}
                                    className="w-full flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                                >
                                    <span className="text-gray-400">{isExpanded ? '▼' : '▶'}</span>
                                    <span>{group.label}</span>
                                    <span className="text-xs text-gray-500 dark:text-gray-400 ml-auto">
                                        {filteredPermissions.length}개
                                    </span>
                                </button>

                                {/* 그룹 내 권한 목록 */}
                                {isExpanded && (
                                    <div className="pb-2">
                                        {filteredPermissions.map(perm => renderPermission(perm))}
                                    </div>
                                )}
                            </div>
                        );
                    })
                )}
            </div>

            {/* 선택된 권한 요약 */}
            {value.length > 0 && (
                <div className="p-2 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
                    <div className="text-xs text-gray-600 dark:text-gray-400 mb-1">선택됨:</div>
                    <div className="flex flex-wrap gap-1">
                        {value.slice(0, 5).map(permId => (
                            <span
                                key={permId}
                                className="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300 rounded"
                                title={permId}
                            >
                                {permId}
                                <button
                                    type="button"
                                    onClick={() => togglePermission(permId)}
                                    disabled={disabled}
                                    className="hover:text-red-500"
                                >
                                    ×
                                </button>
                            </span>
                        ))}
                        {value.length > 5 && (
                            <span className="text-xs text-gray-500">+{value.length - 5}개 더...</span>
                        )}
                    </div>
                </div>
            )}

            {/* 안내 메시지 */}
            <div className="p-2 border-t border-gray-200 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400">
                권한을 선택하지 않으면 공개 레이아웃이 됩니다. 선택된 권한은 AND 조건으로 적용됩니다.
            </div>
        </div>
    );
};

export default PermissionSelector;
