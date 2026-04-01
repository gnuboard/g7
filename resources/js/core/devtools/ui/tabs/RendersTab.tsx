/**
 * 렌더링 탭 컴포넌트
 *
 * setState 후 렌더링 추적 정보를 표시합니다.
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';

export interface RendersTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 렌더링 탭
 */
export const RendersTab: React.FC<RendersTabProps> = ({ devTools }) => {
    const [renderInfo, setRenderInfo] = useState(devTools.getStateRenderingInfo());
    const [selectedLog, setSelectedLog] = useState<string | null>(null);
    const [showNoRenderOnly, setShowNoRenderOnly] = useState(false);
    const [pathFilter, setPathFilter] = useState('');

    // 데이터 새로고침
    const refreshData = useCallback(() => {
        setRenderInfo(devTools.getStateRenderingInfo());
    }, [devTools]);

    // 주기적 갱신
    useEffect(() => {
        refreshData();
        const intervalId = setInterval(refreshData, 2000);
        return () => clearInterval(intervalId);
    }, [refreshData]);

    // 필터링된 로그
    const filteredLogs = renderInfo.logs.filter(log => {
        // 렌더링 없는 것만 필터
        if (showNoRenderOnly && log.renderedComponents.length > 0) return false;
        // 경로 필터
        if (pathFilter && !log.statePath.toLowerCase().includes(pathFilter.toLowerCase())) return false;
        return true;
    });

    // 선택된 로그
    const selectedLogData = selectedLog
        ? renderInfo.logs.find(log => log.setStateId === selectedLog)
        : null;

    // 통계
    const stats = renderInfo.stats;
    const noRenderCount = renderInfo.logs.filter(log => log.renderedComponents.length === 0).length;

    return (
        <div className="g7dt-space-y-4">
            {/* 통계 */}
            <div className="g7dt-stats-grid">
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value g7dt-text-blue">
                        {stats.totalStateChanges}
                    </div>
                    <div className="g7dt-stat-label">상태 변경</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value g7dt-text-green">
                        {stats.totalRenders}
                    </div>
                    <div className="g7dt-stat-label">총 렌더링</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value g7dt-text-purple">
                        {stats.avgRenderDuration.toFixed(1)}ms
                    </div>
                    <div className="g7dt-stat-label">평균 렌더 시간</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className={`g7dt-stat-value ${noRenderCount > 0 ? 'g7dt-text-yellow' : 'g7dt-text-green'}`}>
                        {noRenderCount}
                    </div>
                    <div className="g7dt-stat-label">렌더링 없음</div>
                </div>
            </div>

            {/* 필터 */}
            <div className="g7dt-flex g7dt-gap-2">
                <input
                    type="text"
                    placeholder="상태 경로 필터 (예: _global.user)"
                    value={pathFilter}
                    onChange={e => setPathFilter(e.target.value)}
                    className="g7dt-input g7dt-flex-1"
                />
                <button
                    onClick={() => setShowNoRenderOnly(!showNoRenderOnly)}
                    className={showNoRenderOnly ? 'g7dt-filter-btn-active-yellow' : 'g7dt-filter-btn'}
                >
                    렌더링 없음만
                </button>
            </div>

            {/* 로그 목록 */}
            <div className="g7dt-scroll-list">
                {filteredLogs.length === 0 ? (
                    <div className="g7dt-empty-state">
                        상태-렌더링 로그가 없습니다
                    </div>
                ) : (
                    filteredLogs.slice(-30).reverse().map(log => {
                        const hasRender = log.renderedComponents.length > 0;
                        const icon = hasRender ? '✅' : '⚠️';
                        const time = new Date(log.timestamp).toLocaleTimeString();

                        return (
                            <div
                                key={log.setStateId}
                                onClick={() => setSelectedLog(log.setStateId)}
                                className={`g7dt-render-log-item ${
                                    selectedLog === log.setStateId
                                        ? 'g7dt-selected'
                                        : hasRender
                                        ? ''
                                        : 'g7dt-warning'
                                }`}
                            >
                                <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                                    <span className="g7dt-mono g7dt-text-blue">
                                        {icon} {log.statePath}
                                    </span>
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        {hasRender ? (
                                            <span className="g7dt-text-green">
                                                {log.renderedComponents.length}개 렌더
                                            </span>
                                        ) : (
                                            <span className="g7dt-text-yellow">렌더링 없음</span>
                                        )}
                                        <span className="g7dt-text-muted">{time}</span>
                                    </div>
                                </div>
                                {log.triggeredBy?.handlerType && (
                                    <div className="g7dt-text-muted g7dt-mt-1">
                                        트리거: {log.triggeredBy.handlerType}
                                    </div>
                                )}
                            </div>
                        );
                    })
                )}
            </div>

            {/* 선택된 로그 상세 */}
            {selectedLogData && (
                <div className="g7dt-card">
                    <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-mb-2">
                        <h3 className="g7dt-text-sm g7dt-font-bold">{selectedLogData.statePath}</h3>
                        <button
                            onClick={() => setSelectedLog(null)}
                            className="g7dt-close-btn"
                        >
                            ✕
                        </button>
                    </div>

                    <div className="g7dt-space-y-2 g7dt-text-xs">
                        {/* ID 및 시간 */}
                        <div>
                            <span className="g7dt-text-muted">setStateId:</span>
                            <span className="g7dt-ml-2 g7dt-text-purple g7dt-mono">{selectedLogData.setStateId}</span>
                        </div>

                        {/* 트리거 정보 */}
                        {selectedLogData.triggeredBy && (
                            <div>
                                <span className="g7dt-text-muted">트리거:</span>
                                <span className="g7dt-ml-2 g7dt-text-yellow">
                                    {selectedLogData.triggeredBy.handlerType}
                                    {selectedLogData.triggeredBy.actionId && ` (${selectedLogData.triggeredBy.actionId})`}
                                </span>
                            </div>
                        )}

                        {/* 값 변경 */}
                        <div>
                            <span className="g7dt-text-muted">이전 값:</span>
                            <pre className="g7dt-pre g7dt-text-red">
                                {JSON.stringify(selectedLogData.oldValue, null, 2)?.slice(0, 200)}
                            </pre>
                        </div>
                        <div>
                            <span className="g7dt-text-muted">새 값:</span>
                            <pre className="g7dt-pre g7dt-text-green">
                                {JSON.stringify(selectedLogData.newValue, null, 2)?.slice(0, 200)}
                            </pre>
                        </div>

                        {/* 렌더링 정보 */}
                        {selectedLogData.renderedComponents.length > 0 ? (
                            <div>
                                <span className="g7dt-text-muted">
                                    렌더링된 컴포넌트 ({selectedLogData.renderedComponents.length}개):
                                </span>
                                <div className="g7dt-mt-1 g7dt-space-y-1">
                                    {selectedLogData.renderedComponents.slice(0, 10).map((comp, i) => (
                                        <div
                                            key={i}
                                            className="g7dt-render-comp-item"
                                        >
                                            <span className="g7dt-text-blue">{comp.componentName}</span>
                                            <span className="g7dt-text-muted">
                                                {comp.renderDuration.toFixed(2)}ms
                                            </span>
                                        </div>
                                    ))}
                                    {selectedLogData.renderedComponents.length > 10 && (
                                        <div className="g7dt-text-muted g7dt-text-center">
                                            ... 외 {selectedLogData.renderedComponents.length - 10}개
                                        </div>
                                    )}
                                </div>
                                <div className="g7dt-mt-2 g7dt-text-muted">
                                    총 렌더 시간: {selectedLogData.totalRenderDuration.toFixed(2)}ms
                                </div>
                            </div>
                        ) : (
                            <div className="g7dt-warning-box">
                                <span className="g7dt-text-yellow">⚠️ 렌더링이 발생하지 않았습니다</span>
                                <div className="g7dt-text-muted g7dt-mt-1 g7dt-text-xxs">
                                    가능한 원인:
                                    <ul className="g7dt-list g7dt-mt-1">
                                        <li>해당 상태를 바인딩하는 컴포넌트가 없음</li>
                                        <li>컴포넌트가 마운트되지 않은 상태</li>
                                        <li>동일한 값으로 상태 설정</li>
                                    </ul>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Top 렌더링 컴포넌트 */}
            {stats.topRenderedComponents.length > 0 && (
                <div>
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">가장 많이 렌더링된 컴포넌트</h3>
                    <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-2">
                        {stats.topRenderedComponents.slice(0, 5).map((item, i) => (
                            <span
                                key={i}
                                className="g7dt-tag"
                            >
                                {item.name}: <span className="g7dt-text-blue">{item.count}</span>
                            </span>
                        ))}
                    </div>
                </div>
            )}

            {/* 새로고침 및 초기화 버튼 */}
            <div className="g7dt-flex g7dt-gap-2">
                <button
                    onClick={refreshData}
                    className="g7dt-btn-secondary"
                >
                    새로고침
                </button>
                <button
                    onClick={() => {
                        devTools.clearStateRenderingLogs();
                        refreshData();
                    }}
                    className="g7dt-btn-danger"
                >
                    로그 초기화
                </button>
            </div>
        </div>
    );
};

export default RendersTab;
