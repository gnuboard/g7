/**
 * 캐시 탭 컴포넌트
 *
 * 캐시 통계와 캐시 결정 로그를 표시합니다.
 */

import React, { useState, useEffect, useCallback } from 'react';
import type { CacheStats, CacheDecisionTrackingInfo } from '../../types';
import { G7DevToolsCore } from '../../G7DevToolsCore';

export interface CacheTabProps {
    stats: CacheStats;
    devTools: G7DevToolsCore;
}

/**
 * 캐시 탭
 */
export const CacheTab: React.FC<CacheTabProps> = ({ stats, devTools }) => {
    const [decisionInfo, setDecisionInfo] = useState<CacheDecisionTrackingInfo | null>(null);
    const [showDecisionLog, setShowDecisionLog] = useState(false);

    // 캐시 결정 데이터 새로고침
    const refreshDecisionData = useCallback(() => {
        setDecisionInfo(devTools.getCacheDecisionTrackingInfo());
    }, [devTools]);

    useEffect(() => {
        if (showDecisionLog) {
            refreshDecisionData();
            const intervalId = setInterval(refreshDecisionData, 2000);
            return () => clearInterval(intervalId);
        }
    }, [showDecisionLog, refreshDecisionData]);

    const handleClearCache = () => {
        devTools.resetCacheStats();
        console.log('[G7DevTools] 캐시 통계 초기화');
    };

    // 결정 타입 아이콘
    const getDecisionIcon = (decision: string) => {
        switch (decision) {
            case 'use_cache': return '✅';
            case 'skip_cache': return '⏭️';
            case 'invalidate': return '🗑️';
            default: return '❓';
        }
    };

    return (
        <div className="g7dt-space-y-6">
            {/* 통계 카드 */}
            <div className="g7dt-stats-grid">
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value g7dt-text-green">{stats.hits}</div>
                    <div className="g7dt-stat-label">Cache Hits</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value g7dt-text-red">{stats.misses}</div>
                    <div className="g7dt-stat-label">Cache Misses</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value g7dt-text-blue">{stats.entries}</div>
                    <div className="g7dt-stat-label">Entries</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value g7dt-text-purple">{stats.hitRate.toFixed(1)}%</div>
                    <div className="g7dt-stat-label">Hit Rate</div>
                </div>
            </div>

            {/* 히트율 바 */}
            <div className="g7dt-space-y-2">
                <div className="g7dt-text-sm g7dt-text-gray">캐시 히트율</div>
                <div className="g7dt-progress-bar">
                    <div className="g7dt-progress-fill" style={{ width: `${stats.hitRate}%` }} />
                </div>
            </div>

            {/* 버튼 */}
            <div className="g7dt-flex g7dt-gap-2">
                <button onClick={handleClearCache} className="g7dt-btn-danger">
                    캐시 통계 초기화
                </button>
                <button
                    onClick={() => setShowDecisionLog(!showDecisionLog)}
                    className={`g7dt-tab-button ${showDecisionLog ? 'g7dt-tab-active' : ''}`}
                >
                    📋 Decision Log
                </button>
            </div>

            {/* Decision Log 섹션 */}
            {showDecisionLog && decisionInfo && (
                <div className="g7dt-card">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">
                        캐시 결정 로그 ({decisionInfo.decisions.length}개)
                    </h3>
                    {decisionInfo.decisions.length === 0 ? (
                        <div className="g7dt-empty-state">캐시 결정 기록이 없습니다</div>
                    ) : (
                        <div className="g7dt-space-y-2 g7dt-max-h-60 g7dt-overflow-y-auto">
                            {decisionInfo.decisions.slice().reverse().slice(0, 50).map((d) => (
                                <div
                                    key={d.id}
                                    className={`g7dt-list-item ${
                                        d.decision === 'skip_cache' ? 'g7dt-border-yellow' :
                                        d.decision === 'invalidate' ? 'g7dt-border-red' : ''
                                    }`}
                                >
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        <span>{getDecisionIcon(d.decision)}</span>
                                        <span className="g7dt-text-xs g7dt-text-white g7dt-font-mono g7dt-truncate" style={{ maxWidth: '200px' }}>
                                            {d.expression}
                                        </span>
                                        <span className="g7dt-text-xs g7dt-text-muted g7dt-ml-auto">
                                            {d.decision}
                                        </span>
                                    </div>
                                    <div className="g7dt-text-xs g7dt-text-muted g7dt-mt-1">
                                        {d.reason}
                                    </div>
                                    {d.context && (
                                        <div className="g7dt-flex g7dt-gap-2 g7dt-mt-1">
                                            {d.context.isInIteration && (
                                                <span className="g7dt-badge g7dt-text-xs">🔄 iteration</span>
                                            )}
                                            {d.context.isInAction && (
                                                <span className="g7dt-badge g7dt-text-xs">⚡ action</span>
                                            )}
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

export default CacheTab;
