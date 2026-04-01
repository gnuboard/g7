/**
 * JSON 트리 노드 컴포넌트
 *
 * 중첩된 JSON 데이터를 트리 형태로 렌더링합니다.
 * 펼침/접기, 검색 하이라이트 기능을 제공합니다.
 */

import React from 'react';
import { getValueColor, getTypeLabel, formatValue } from '../utils';

export interface JsonTreeNodeProps {
    /** 표시할 키 이름 */
    keyName: string;
    /** 표시할 값 */
    value: unknown;
    /** 현재 노드의 경로 (예: "_global.user.name") */
    path: string;
    /** 펼쳐진 경로들의 Set */
    expandedPaths: Set<string>;
    /** 경로 토글 핸들러 */
    onToggle: (path: string) => void;
    /** 검색어 (하이라이트용) */
    searchTerm: string;
    /** 현재 깊이 (기본값: 0) */
    depth?: number;
}

/**
 * JSON 트리 노드 컴포넌트
 */
export const JsonTreeNode: React.FC<JsonTreeNodeProps> = ({
    keyName,
    value,
    path,
    expandedPaths,
    onToggle,
    searchTerm,
    depth = 0,
}) => {
    const isExpandable = value !== null && typeof value === 'object';
    const isExpanded = expandedPaths.has(path);
    const isArray = Array.isArray(value);

    // 검색어 하이라이트 체크
    const matchesSearch =
        searchTerm &&
        (keyName.toLowerCase().includes(searchTerm.toLowerCase()) ||
            (typeof value === 'string' && value.toLowerCase().includes(searchTerm.toLowerCase())) ||
            (typeof value === 'number' && String(value).includes(searchTerm)));

    const handleToggle = (e: React.MouseEvent) => {
        e.stopPropagation();
        if (isExpandable) {
            onToggle(path);
        }
    };

    // 렌더링할 자식 엔트리
    const childEntries = isExpandable ? Object.entries(value as Record<string, unknown>) : [];

    return (
        <div style={{ marginLeft: depth > 0 ? '16px' : '0' }}>
            <div
                className={`g7dt-tree-node g7dt-flex g7dt-items-center g7dt-gap-1 ${matchesSearch ? 'g7dt-ring-highlight' : ''}`}
                onClick={handleToggle}
            >
                {/* 펼침/접기 아이콘 */}
                {isExpandable ? (
                    <span
                        className="g7dt-text-gray g7dt-w-4 g7dt-text-xs g7dt-select-none"
                        style={{ textAlign: 'center' }}
                    >
                        {isExpanded ? '▼' : '▶'}
                    </span>
                ) : (
                    <span className="g7dt-w-4" />
                )}

                {/* 키 이름 */}
                <span className="g7dt-text-purple g7dt-text-xs">{keyName}</span>
                <span className="g7dt-text-gray-dark g7dt-text-xs">:</span>

                {/* 값 또는 타입 */}
                {isExpandable ? (
                    <span className={`g7dt-text-xs ${getValueColor(value)}`}>
                        {isArray ? '[' : '{'}
                        {!isExpanded && (
                            <span className="g7dt-text-gray" style={{ margin: '0 4px' }}>
                                {getTypeLabel(value)}
                            </span>
                        )}
                        {!isExpanded && (isArray ? ']' : '}')}
                    </span>
                ) : (
                    <span className={`g7dt-text-xs ${getValueColor(value)}`}>{formatValue(value)}</span>
                )}

                {/* 타입 힌트 (primitive) */}
                {!isExpandable && (
                    <span className="g7dt-text-gray-dark g7dt-text-xs g7dt-ml-1">({typeof value})</span>
                )}
            </div>

            {/* 자식 노드 */}
            {isExpandable && isExpanded && (
                <div className="g7dt-border-l" style={{ marginLeft: '8px' }}>
                    {childEntries.map(([childKey, childValue]) => (
                        <JsonTreeNode
                            key={`${path}.${childKey}`}
                            keyName={isArray ? `[${childKey}]` : childKey}
                            value={childValue}
                            path={`${path}.${childKey}`}
                            expandedPaths={expandedPaths}
                            onToggle={onToggle}
                            searchTerm={searchTerm}
                            depth={depth + 1}
                        />
                    ))}
                    {/* 닫는 괄호 */}
                    <div className="g7dt-ml-4 g7dt-text-xs">
                        <span className={getValueColor(value)}>{isArray ? ']' : '}'}</span>
                    </div>
                </div>
            )}
        </div>
    );
};

export default JsonTreeNode;
