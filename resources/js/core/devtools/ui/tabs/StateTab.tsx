/**
 * 상태 탭 컴포넌트
 *
 * _global, _local, _computed, _isolated, $parent 상태를 트리 형태로 표시합니다.
 * Computed 의존성 추적 기능 포함 (Phase 7).
 */

import React, { useState, useCallback, useEffect } from 'react';
import type { StateView, ComputedDependencyTrackingInfo, ComputedRecalcTrigger } from '../../types';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import { JsonTreeNode } from '../components/JsonTreeNode';

export interface StateTabProps {
    state: StateView;
    devTools?: G7DevToolsCore;
}

/**
 * 상태 탭
 */
export const StateTab: React.FC<StateTabProps> = ({ state, devTools }) => {
    const [expandedPaths, setExpandedPaths] = useState<Set<string>>(
        new Set(['_global', '_local', '_computed', '_isolated', '$parent'])
    );
    const [searchTerm, setSearchTerm] = useState('');
    const [expandAll, setExpandAll] = useState(false);
    const [showComputedTracking, setShowComputedTracking] = useState(false);
    const [computedInfo, setComputedInfo] = useState<ComputedDependencyTrackingInfo | null>(null);

    // Computed 추적 데이터 새로고침
    const refreshComputedData = useCallback(() => {
        if (devTools) {
            setComputedInfo(devTools.getComputedDependencyTrackingInfo());
        }
    }, [devTools]);

    // 주기적 갱신
    useEffect(() => {
        if (showComputedTracking && devTools) {
            refreshComputedData();
            const intervalId = setInterval(refreshComputedData, 2000);
            return () => clearInterval(intervalId);
        }
    }, [showComputedTracking, devTools, refreshComputedData]);

    // 트리거 아이콘
    const getTriggerIcon = (trigger: ComputedRecalcTrigger) => {
        switch (trigger) {
            case 'state-change': return '📊';
            case 'prop-change': return '📥';
            case 'dependency-change': return '🔗';
            case 'manual': return '👆';
            case 'initial': return '🚀';
            default: return '❓';
        }
    };

    // 경로 토글
    const handleToggle = useCallback((path: string) => {
        setExpandedPaths(prev => {
            const next = new Set(prev);
            if (next.has(path)) {
                next.delete(path);
            } else {
                next.add(path);
            }
            return next;
        });
    }, []);

    // 모든 경로 수집 (재귀)
    const collectAllPaths = useCallback((obj: unknown, prefix: string): string[] => {
        const paths: string[] = [prefix];
        if (obj !== null && typeof obj === 'object') {
            for (const [key, value] of Object.entries(obj as Record<string, unknown>)) {
                paths.push(...collectAllPaths(value, `${prefix}.${key}`));
            }
        }
        return paths;
    }, []);

    // 모두 펼치기/접기
    const handleExpandAll = useCallback(() => {
        if (expandAll) {
            // 모두 접기 - 루트만 남김
            setExpandedPaths(new Set(['_global', '_local', '_computed', '_isolated', '$parent']));
        } else {
            // 모두 펼치기
            const allPaths = new Set<string>();
            collectAllPaths(state._global, '_global').forEach(p => allPaths.add(p));
            collectAllPaths(state._local, '_local').forEach(p => allPaths.add(p));
            collectAllPaths(state._computed, '_computed').forEach(p => allPaths.add(p));
            collectAllPaths(state._isolated, '_isolated').forEach(p => allPaths.add(p));
            if (state.$parent) {
                collectAllPaths(state.$parent, '$parent').forEach(p => allPaths.add(p));
            }
            setExpandedPaths(allPaths);
        }
        setExpandAll(!expandAll);
    }, [expandAll, state, collectAllPaths]);

    // 섹션별 키 개수
    const globalCount = Object.keys(state._global || {}).length;
    const localCount = Object.keys(state._local || {}).length;
    const computedCount = Object.keys(state._computed || {}).length;
    const isolatedCount = Object.keys(state._isolated || {}).length;
    const parentCount = Object.keys(state.$parent || {}).length;

    return (
        <div className="g7dt-space-y-4">
            {/* 검색 및 컨트롤 */}
            <div className="g7dt-flex g7dt-gap-2 g7dt-items-center g7dt-flex-shrink-0">
                <input
                    type="text"
                    placeholder="키 또는 값 검색..."
                    value={searchTerm}
                    onChange={e => setSearchTerm(e.target.value)}
                    className="g7dt-input g7dt-flex-1"
                />
                <button onClick={handleExpandAll} className="g7dt-btn g7dt-bg-gray-700">
                    {expandAll ? '모두 접기' : '모두 펼치기'}
                </button>
            </div>

            {/* 타입 범례 */}
            <div className="g7dt-flex g7dt-gap-3 g7dt-text-xs g7dt-text-gray g7dt-flex-wrap g7dt-flex-shrink-0">
                <span>
                    <span className="g7dt-text-yellow">●</span> string
                </span>
                <span>
                    <span className="g7dt-text-cyan">●</span> number
                </span>
                <span>
                    <span className="g7dt-text-pink">●</span> boolean
                </span>
                <span>
                    <span className="g7dt-text-gray">●</span> null
                </span>
                <span>
                    <span className="g7dt-text-orange">●</span> array
                </span>
                <span>
                    <span className="g7dt-text-blue">●</span> object
                </span>
            </div>

            {/* 상태 트리 - flex로 남은 공간 채움 */}
            <div
                className="g7dt-bg-gray-800 g7dt-rounded g7dt-p-2 g7dt-overflow-auto g7dt-border"
                style={{ flex: 1, minHeight: 0 }}
            >
                {/* _global 섹션 */}
                <div className="g7dt-mb-2">
                    <div
                        className="g7dt-flex g7dt-items-center g7dt-gap-2 g7dt-text-sm g7dt-font-bold g7dt-text-green g7dt-mb-1 g7dt-cursor-pointer"
                        onClick={() => handleToggle('_global')}
                    >
                        <span>{expandedPaths.has('_global') ? '▼' : '▶'}</span>
                        <span>_global</span>
                        <span className="g7dt-text-gray g7dt-text-xs" style={{ fontWeight: 'normal' }}>
                            ({globalCount}개 키)
                        </span>
                    </div>
                    {expandedPaths.has('_global') && (
                        <div className="g7dt-ml-2 g7dt-border-l">
                            {Object.entries(state._global || {}).map(([key, value]) => (
                                <JsonTreeNode
                                    key={`_global.${key}`}
                                    keyName={key}
                                    value={value}
                                    path={`_global.${key}`}
                                    expandedPaths={expandedPaths}
                                    onToggle={handleToggle}
                                    searchTerm={searchTerm}
                                />
                            ))}
                        </div>
                    )}
                </div>

                {/* _local 섹션 */}
                <div className="g7dt-mb-2">
                    <div
                        className="g7dt-flex g7dt-items-center g7dt-gap-2 g7dt-text-sm g7dt-font-bold g7dt-text-blue g7dt-mb-1 g7dt-cursor-pointer"
                        onClick={() => handleToggle('_local')}
                    >
                        <span>{expandedPaths.has('_local') ? '▼' : '▶'}</span>
                        <span>_local</span>
                        <span className="g7dt-text-gray g7dt-text-xs" style={{ fontWeight: 'normal' }}>
                            ({localCount}개 키)
                        </span>
                    </div>
                    {expandedPaths.has('_local') && (
                        <div className="g7dt-ml-2 g7dt-border-l">
                            {Object.entries(state._local || {}).map(([key, value]) => (
                                <JsonTreeNode
                                    key={`_local.${key}`}
                                    keyName={key}
                                    value={value}
                                    path={`_local.${key}`}
                                    expandedPaths={expandedPaths}
                                    onToggle={handleToggle}
                                    searchTerm={searchTerm}
                                />
                            ))}
                        </div>
                    )}
                </div>

                {/* _computed 섹션 */}
                <div className="g7dt-mb-2">
                    <div
                        className="g7dt-flex g7dt-items-center g7dt-gap-2 g7dt-text-sm g7dt-font-bold g7dt-text-purple g7dt-mb-1 g7dt-cursor-pointer"
                        onClick={() => handleToggle('_computed')}
                    >
                        <span>{expandedPaths.has('_computed') ? '▼' : '▶'}</span>
                        <span>_computed</span>
                        <span className="g7dt-text-gray g7dt-text-xs" style={{ fontWeight: 'normal' }}>
                            ({computedCount}개 키)
                        </span>
                    </div>
                    {expandedPaths.has('_computed') && (
                        <div className="g7dt-ml-2 g7dt-border-l">
                            {Object.entries(state._computed || {}).map(([key, value]) => (
                                <JsonTreeNode
                                    key={`_computed.${key}`}
                                    keyName={key}
                                    value={value}
                                    path={`_computed.${key}`}
                                    expandedPaths={expandedPaths}
                                    onToggle={handleToggle}
                                    searchTerm={searchTerm}
                                />
                            ))}
                        </div>
                    )}
                </div>

                {/* _isolated 섹션 (격리된 상태) */}
                {isolatedCount > 0 && (
                    <div>
                        <div
                            className="g7dt-flex g7dt-items-center g7dt-gap-2 g7dt-text-sm g7dt-font-bold g7dt-text-orange g7dt-mb-1 g7dt-cursor-pointer"
                            onClick={() => handleToggle('_isolated')}
                        >
                            <span>{expandedPaths.has('_isolated') ? '▼' : '▶'}</span>
                            <span>_isolated</span>
                            <span className="g7dt-text-gray g7dt-text-xs" style={{ fontWeight: 'normal' }}>
                                ({isolatedCount}개 스코프)
                            </span>
                        </div>
                        {expandedPaths.has('_isolated') && (
                            <div className="g7dt-ml-2 g7dt-border-l">
                                {Object.entries(state._isolated || {}).map(([scopeId, scopeState]) => (
                                    <JsonTreeNode
                                        key={`_isolated.${scopeId}`}
                                        keyName={scopeId}
                                        value={scopeState}
                                        path={`_isolated.${scopeId}`}
                                        expandedPaths={expandedPaths}
                                        onToggle={handleToggle}
                                        searchTerm={searchTerm}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {/* $parent 섹션 (부모 컨텍스트 - 모달에서 사용) */}
                {parentCount > 0 && (
                    <div>
                        <div
                            className="g7dt-flex g7dt-items-center g7dt-gap-2 g7dt-text-sm g7dt-font-bold g7dt-text-cyan g7dt-mb-1 g7dt-cursor-pointer"
                            onClick={() => handleToggle('$parent')}
                        >
                            <span>{expandedPaths.has('$parent') ? '▼' : '▶'}</span>
                            <span>$parent</span>
                            <span className="g7dt-text-gray g7dt-text-xs" style={{ fontWeight: 'normal' }}>
                                ({parentCount}개 키)
                            </span>
                        </div>
                        {expandedPaths.has('$parent') && (
                            <div className="g7dt-ml-2 g7dt-border-l">
                                {Object.entries(state.$parent || {}).map(([key, value]) => (
                                    <JsonTreeNode
                                        key={`$parent.${key}`}
                                        keyName={key}
                                        value={value}
                                        path={`$parent.${key}`}
                                        expandedPaths={expandedPaths}
                                        onToggle={handleToggle}
                                        searchTerm={searchTerm}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Computed 추적 토글 */}
            {devTools && (
                <div className="g7dt-flex g7dt-justify-end g7dt-mt-2">
                    <button
                        onClick={() => setShowComputedTracking(!showComputedTracking)}
                        className={`g7dt-tab-button ${showComputedTracking ? 'g7dt-tab-active' : ''}`}
                    >
                        🧮 Computed 추적
                    </button>
                </div>
            )}

            {/* Computed 추적 섹션 */}
            {showComputedTracking && computedInfo && (
                <div className="g7dt-card g7dt-mt-2">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">
                        Computed 의존성 추적 ({computedInfo.properties.length}개)
                    </h3>

                    {/* 통계 */}
                    <div className="g7dt-stats-grid g7dt-mb-3">
                        <div className="g7dt-stat-card">
                            <div className="g7dt-stat-value g7dt-text-purple">
                                {computedInfo.stats.totalComputed}
                            </div>
                            <div className="g7dt-stat-label">Computed</div>
                        </div>
                        <div className="g7dt-stat-card">
                            <div className="g7dt-stat-value g7dt-text-blue">
                                {computedInfo.stats.totalRecalculations}
                            </div>
                            <div className="g7dt-stat-label">재계산</div>
                        </div>
                        <div className="g7dt-stat-card">
                            <div className="g7dt-stat-value g7dt-text-yellow">
                                {computedInfo.stats.unnecessaryRecalculations}
                            </div>
                            <div className="g7dt-stat-label">불필요</div>
                        </div>
                        <div className="g7dt-stat-card">
                            <div className="g7dt-stat-value g7dt-text-green">
                                {computedInfo.stats.avgComputationTime.toFixed(1)}ms
                            </div>
                            <div className="g7dt-stat-label">평균시간</div>
                        </div>
                    </div>

                    {/* 순환 의존성 경고 */}
                    {computedInfo.stats.cycleDetectionCount > 0 && (
                        <div className="g7dt-text-xs g7dt-text-red g7dt-mb-3 g7dt-p-2 g7dt-bg-red-900 g7dt-rounded">
                            ⚠️ 순환 의존성 감지: {computedInfo.stats.cycleDetectionCount}건
                        </div>
                    )}

                    {/* 자주 재계산되는 속성 */}
                    {computedInfo.stats.topRecalculated.length > 0 && (
                        <div className="g7dt-mb-3">
                            <div className="g7dt-text-xs g7dt-text-muted g7dt-mb-2">자주 재계산되는 속성</div>
                            <div className="g7dt-space-y-1">
                                {computedInfo.stats.topRecalculated.slice(0, 3).map((item, idx) => (
                                    <div key={idx} className="g7dt-flex g7dt-justify-between g7dt-text-xs">
                                        <span className="g7dt-text-purple g7dt-font-mono">{item.name}</span>
                                        <span className="g7dt-text-muted">{item.count}회</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* 재계산 로그 */}
                    {computedInfo.recalcLogs.length === 0 ? (
                        <div className="g7dt-empty-state">재계산 로그가 없습니다</div>
                    ) : (
                        <div className="g7dt-space-y-2 g7dt-max-h-40 g7dt-overflow-y-auto">
                            {computedInfo.recalcLogs.slice().reverse().slice(0, 20).map((log) => (
                                <div
                                    key={log.id}
                                    className={`g7dt-list-item ${!log.valueChanged ? 'g7dt-border-yellow' : ''}`}
                                >
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        <span>{getTriggerIcon(log.trigger)}</span>
                                        <span className="g7dt-text-xs g7dt-text-purple g7dt-font-mono">
                                            {log.computedName}
                                        </span>
                                        {!log.valueChanged && (
                                            <span className="g7dt-badge g7dt-text-xs g7dt-text-yellow">
                                                값 변경 없음
                                            </span>
                                        )}
                                        <span className="g7dt-text-xs g7dt-text-muted g7dt-ml-auto">
                                            {log.computationTime.toFixed(1)}ms
                                        </span>
                                    </div>
                                    {log.triggeredBy && (
                                        <div className="g7dt-text-xs g7dt-text-muted g7dt-mt-1">
                                            트리거: <span className="g7dt-text-blue">{log.triggeredBy.path}</span>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

export default StateTab;
