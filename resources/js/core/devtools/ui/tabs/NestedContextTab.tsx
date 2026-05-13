/**
 * Nested Context 추적 탭 컴포넌트
 *
 * expandChildren, cellChildren, iteration, modal, slot 컨텍스트 추적을 표시합니다.
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { NestedContextTrackingInfo, NestedContextInfo } from '../../types';

export interface NestedContextTabProps {
    devTools: G7DevToolsCore;
}

/**
 * Nested Context 추적 탭
 */
export const NestedContextTab: React.FC<NestedContextTabProps> = ({ devTools }) => {
    const [trackingInfo, setTrackingInfo] = useState<NestedContextTrackingInfo | null>(null);
    const [selectedContext, setSelectedContext] = useState<string | null>(null);
    const [typeFilter, setTypeFilter] = useState<string>('all');

    // 데이터 새로고침
    const refreshData = useCallback(() => {
        setTrackingInfo(devTools.getNestedContextTrackingInfo());
    }, [devTools]);

    // 주기적 갱신
    useEffect(() => {
        refreshData();
        const intervalId = setInterval(refreshData, 2000);
        return () => clearInterval(intervalId);
    }, [refreshData]);

    if (!trackingInfo) {
        return <div className="g7dt-empty-state">로딩 중...</div>;
    }

    const { contexts, stats } = trackingInfo;

    // 필터링된 컨텍스트
    const filteredContexts = contexts.filter(ctx => {
        if (typeFilter === 'all') return true;
        return ctx.componentType === typeFilter;
    });

    // 선택된 컨텍스트
    const selectedContextData = selectedContext
        ? contexts.find(c => c.id === selectedContext)
        : null;

    // 컨텍스트 타입 아이콘
    const getTypeIcon = (type: string) => {
        switch (type) {
            case 'expandChildren': return '📂';
            case 'cellChildren': return '📊';
            case 'iteration': return '🔄';
            case 'modal': return '🪟';
            case 'slot': return '🔲';
            default: return '❓';
        }
    };

    // 깊이 표시
    const getDepthIndicator = (depth: number) => {
        return '│'.repeat(Math.max(0, depth - 1)) + (depth > 0 ? '├─' : '');
    };

    return (
        <div className="g7dt-space-y-4">
            {/* 통계 */}
            <div className="g7dt-card">
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">Nested Context 통계</h3>
                <div className="g7dt-stats-grid-3">
                    <div className="g7dt-text-center">
                        <div className="g7dt-text-2xl g7dt-font-bold g7dt-text-blue">{stats.totalContexts || 0}</div>
                        <div className="g7dt-text-xs g7dt-text-muted">총 컨텍스트</div>
                    </div>
                    <div className="g7dt-text-center">
                        <div className="g7dt-text-2xl g7dt-font-bold g7dt-text-purple">{stats.maxDepth || 0}</div>
                        <div className="g7dt-text-xs g7dt-text-muted">최대 깊이</div>
                    </div>
                    <div className="g7dt-text-center">
                        <div className="g7dt-text-2xl g7dt-font-bold g7dt-text-red">{stats.failedAccessCount || 0}</div>
                        <div className="g7dt-text-xs g7dt-text-muted">접근 오류</div>
                    </div>
                </div>
            </div>

            {/* 타입별 통계 */}
            {stats.byType && Object.keys(stats.byType).length > 0 && (
                <div className="g7dt-card">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">타입별 분포</h3>
                    <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-3">
                        {Object.entries(stats.byType).map(([type, count]) => (
                            <div
                                key={type}
                                className={`g7dt-badge g7dt-cursor-pointer ${
                                    typeFilter === type ? 'g7dt-badge-selected' : ''
                                }`}
                                onClick={() => setTypeFilter(typeFilter === type ? 'all' : type)}
                            >
                                {getTypeIcon(type)} {type}: {count as number}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* 필터 */}
            <div className="g7dt-card">
                <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                    <span className="g7dt-text-xs g7dt-text-muted">타입:</span>
                    <select
                        value={typeFilter}
                        onChange={(e) => setTypeFilter(e.target.value)}
                        className="g7dt-select"
                    >
                        <option value="all">전체</option>
                        <option value="expandChildren">expandChildren</option>
                        <option value="cellChildren">cellChildren</option>
                        <option value="iteration">iteration</option>
                        <option value="modal">modal</option>
                        <option value="slot">slot</option>
                    </select>
                    <span className="g7dt-text-xs g7dt-text-muted g7dt-ml-auto">
                        {filteredContexts.length}개 표시
                    </span>
                </div>
            </div>

            {/* 컨텍스트 목록 */}
            <div className="g7dt-card">
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">컨텍스트 목록</h3>
                {filteredContexts.length === 0 ? (
                    <div className="g7dt-empty-state">
                        추적된 Nested Context가 없습니다
                    </div>
                ) : (
                    <div className="g7dt-space-y-2 g7dt-max-h-60 g7dt-overflow-y-auto">
                        {filteredContexts.slice().reverse().map((ctx) => {
                            const hasAccessError = ctx.accessAttempts?.some(a => !a.found);
                            const availableVars = ctx.mergedContext?.all || [];
                            return (
                                <div
                                    key={ctx.id}
                                    className={`g7dt-list-item g7dt-cursor-pointer ${
                                        selectedContext === ctx.id ? 'g7dt-selected' : ''
                                    } ${hasAccessError ? 'g7dt-border-red' : ''}`}
                                    onClick={() => setSelectedContext(
                                        selectedContext === ctx.id ? null : ctx.id
                                    )}
                                >
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        <span className="g7dt-text-muted g7dt-mono g7dt-text-xs">
                                            {getDepthIndicator(ctx.depth)}
                                        </span>
                                        <span>{getTypeIcon(ctx.componentType)}</span>
                                        <span className="g7dt-text-xs g7dt-text-white">
                                            {ctx.componentId || ctx.componentType}
                                        </span>
                                        {hasAccessError && (
                                            <span className="g7dt-text-red g7dt-text-xs">⚠️ 오류</span>
                                        )}
                                        <span className="g7dt-text-xs g7dt-text-muted g7dt-ml-auto">
                                            깊이: {ctx.depth}
                                        </span>
                                    </div>
                                    <div className="g7dt-text-xs g7dt-text-muted g7dt-mt-1">
                                        변수: {availableVars.slice(0, 5).join(', ')}
                                        {availableVars.length > 5 && '...'}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* 선택된 컨텍스트 상세 */}
            {selectedContextData && (
                <div className="g7dt-card g7dt-border-blue">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">
                        {getTypeIcon(selectedContextData.componentType)} 컨텍스트 상세
                    </h3>
                    <div className="g7dt-space-y-3">
                        <div className="g7dt-grid-2 g7dt-gap-3">
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted">타입</div>
                                <div className="g7dt-text-sm g7dt-text-white">
                                    {selectedContextData.componentType}
                                </div>
                            </div>
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted">깊이</div>
                                <div className="g7dt-text-sm g7dt-text-white">
                                    {selectedContextData.depth}
                                </div>
                            </div>
                        </div>

                        {selectedContextData.componentId && (
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted">컴포넌트 ID</div>
                                <div className="g7dt-text-sm g7dt-text-blue g7dt-mono">
                                    {selectedContextData.componentId}
                                </div>
                            </div>
                        )}

                        {selectedContextData.parentId && (
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted">부모 컨텍스트</div>
                                <div className="g7dt-text-sm g7dt-text-purple g7dt-mono">
                                    {selectedContextData.parentId}
                                </div>
                            </div>
                        )}

                        <div>
                            <div className="g7dt-text-xs g7dt-text-muted g7dt-mb-2">사용 가능한 변수</div>
                            <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-1">
                                {(selectedContextData.mergedContext?.all || []).map((varName) => (
                                    <span
                                        key={varName}
                                        className="g7dt-badge g7dt-badge-blue g7dt-text-xs"
                                    >
                                        {varName}
                                    </span>
                                ))}
                            </div>
                        </div>

                        {selectedContextData.ownContext?.added && selectedContextData.ownContext.added.length > 0 && (
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted g7dt-mb-2">추가된 변수 (이 컨텍스트에서)</div>
                                <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-1">
                                    {selectedContextData.ownContext.added.map((varName) => (
                                        <span
                                            key={varName}
                                            className="g7dt-badge g7dt-badge-green g7dt-text-xs"
                                        >
                                            + {varName}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}

                        {selectedContextData.accessAttempts && selectedContextData.accessAttempts.length > 0 && (
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted g7dt-mb-2">접근 시도</div>
                                <div className="g7dt-space-y-1">
                                    {selectedContextData.accessAttempts.slice(0, 10).map((attempt, idx) => (
                                        <div
                                            key={idx}
                                            className={`g7dt-flex g7dt-justify-between g7dt-text-xs ${
                                                attempt.found ? '' : 'g7dt-text-red'
                                            }`}
                                        >
                                            <span className="g7dt-mono">{attempt.path}</span>
                                            <span>{attempt.found ? '✓' : '✕'}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
};

export default NestedContextTab;
