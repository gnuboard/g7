/**
 * NOTE: @since versions (engine-v1.x.x) refer to internal template engine
 * development iterations, not G7 platform release versions.
 */
/**
 * Named Actions 탭 컴포넌트
 *
 * 레이아웃의 named_actions 정의 목록과 actionRef 해석 이력을 표시합니다.
 *
 * @since engine-v1.19.0
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { NamedActionTrackingInfo } from '../../types';

export interface NamedActionsTabProps {
    devTools: G7DevToolsCore;
}

/**
 * Named Actions 탭
 */
export const NamedActionsTab: React.FC<NamedActionsTabProps> = ({ devTools }) => {
    const [viewMode, setViewMode] = useState<'definitions' | 'refs'>('definitions');
    const [trackingInfo, setTrackingInfo] = useState<NamedActionTrackingInfo | null>(null);
    const [filter, setFilter] = useState<string>('');

    const refreshData = useCallback(() => {
        if (devTools) {
            setTrackingInfo(devTools.getNamedActionTrackingInfo());
        }
    }, [devTools]);

    useEffect(() => {
        refreshData();
        const timer = setInterval(refreshData, 2000);
        return () => clearInterval(timer);
    }, [refreshData]);

    if (!trackingInfo) {
        return <div className="g7dt-empty-message g7dt-py-4">데이터 로딩 중...</div>;
    }

    const { definitions, refLogs, stats } = trackingInfo;
    const definitionNames = Object.keys(definitions);

    // 필터링
    const filteredDefs = definitionNames.filter(name =>
        !filter || name.toLowerCase().includes(filter.toLowerCase()) ||
        definitions[name]?.handler?.toLowerCase().includes(filter.toLowerCase())
    );

    const filteredRefs = refLogs.filter(log =>
        !filter || log.actionRefName.toLowerCase().includes(filter.toLowerCase()) ||
        log.resolvedHandler.toLowerCase().includes(filter.toLowerCase())
    );

    return (
        <div className="g7dt-space-y-4">
            {/* 통계 요약 */}
            <div className="g7dt-grid g7dt-grid-cols-3 g7dt-gap-2">
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value">{stats.totalDefinitions}</div>
                    <div className="g7dt-stat-label">정의</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value">{stats.totalRefs}</div>
                    <div className="g7dt-stat-label">참조</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value" style={{ color: stats.unusedDefinitions.length > 0 ? '#f59e0b' : '#10b981' }}>
                        {stats.unusedDefinitions.length}
                    </div>
                    <div className="g7dt-stat-label">미사용</div>
                </div>
            </div>

            {/* 뷰 모드 토글 */}
            <div className="g7dt-flex g7dt-gap-2">
                <button
                    onClick={() => setViewMode('definitions')}
                    className={viewMode === 'definitions' ? 'g7dt-filter-btn g7dt-filter-active' : 'g7dt-filter-btn'}
                >
                    정의 목록 ({definitionNames.length})
                </button>
                <button
                    onClick={() => setViewMode('refs')}
                    className={viewMode === 'refs' ? 'g7dt-filter-btn g7dt-filter-active' : 'g7dt-filter-btn'}
                >
                    참조 이력 ({refLogs.length})
                </button>
                <button
                    onClick={refreshData}
                    className="g7dt-filter-btn"
                    title="새로고침"
                >
                    ↻
                </button>
            </div>

            {/* 검색 */}
            <input
                type="text"
                placeholder={viewMode === 'definitions' ? '액션 이름 검색...' : 'actionRef 검색...'}
                value={filter}
                onChange={e => setFilter(e.target.value)}
                className="g7dt-input g7dt-w-full"
            />

            {/* 정의 목록 뷰 */}
            {viewMode === 'definitions' && (
                <div className="g7dt-space-y-1">
                    {filteredDefs.length === 0 ? (
                        <div className="g7dt-empty-message g7dt-py-4">
                            {definitionNames.length === 0 ? '정의된 named_actions가 없습니다' : '검색 결과 없음'}
                        </div>
                    ) : (
                        filteredDefs.map(name => {
                            const def = definitions[name];
                            const refCount = stats.refCountByName[name] || 0;
                            const isUnused = stats.unusedDefinitions.includes(name);

                            return (
                                <div
                                    key={name}
                                    className="g7dt-card"
                                    style={{ borderLeft: isUnused ? '3px solid #f59e0b' : '3px solid #10b981' }}
                                >
                                    <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                                        <div>
                                            <span className="g7dt-font-bold">{name}</span>
                                            <span className="g7dt-text-muted g7dt-ml-2">→ {def.handler}</span>
                                        </div>
                                        <span className="g7dt-badge" title="참조 횟수">
                                            {refCount}회
                                        </span>
                                    </div>
                                    {def.params && (
                                        <details className="g7dt-mt-1">
                                            <summary className="g7dt-text-xs g7dt-text-muted g7dt-cursor-pointer">params</summary>
                                            <pre className="g7dt-pre g7dt-text-xs g7dt-mt-1" style={{ maxHeight: '200px', overflow: 'auto' }}>
                                                {JSON.stringify(def.params, null, 2)}
                                            </pre>
                                        </details>
                                    )}
                                    {isUnused && (
                                        <div className="g7dt-text-xs" style={{ color: '#f59e0b', marginTop: '4px' }}>
                                            ⚠ 미사용 정의
                                        </div>
                                    )}
                                </div>
                            );
                        })
                    )}
                </div>
            )}

            {/* 참조 이력 뷰 */}
            {viewMode === 'refs' && (
                <div className="g7dt-space-y-1">
                    {filteredRefs.length === 0 ? (
                        <div className="g7dt-empty-message g7dt-py-4">
                            {refLogs.length === 0 ? 'actionRef 해석 이력이 없습니다' : '검색 결과 없음'}
                        </div>
                    ) : (
                        [...filteredRefs].reverse().map(log => (
                            <div key={log.id} className="g7dt-card">
                                <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                                    <div>
                                        <span className="g7dt-font-bold">{log.actionRefName}</span>
                                        <span className="g7dt-text-muted g7dt-ml-2">→ {log.resolvedHandler}</span>
                                    </div>
                                    <span className="g7dt-text-xs g7dt-text-muted">
                                        {new Date(log.timestamp).toLocaleTimeString()}
                                    </span>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            )}
        </div>
    );
};
