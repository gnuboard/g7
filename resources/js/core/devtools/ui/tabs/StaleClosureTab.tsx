/**
 * Stale Closure 추적 탭 컴포넌트
 *
 * Stale Closure 경고, 캡처된 상태 vs 최신 상태 비교를 표시합니다.
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { StaleClosureTrackingInfo, StaleClosureWarning } from '../../types';

export interface StaleClosureTabProps {
    devTools: G7DevToolsCore;
}

/**
 * Stale Closure 추적 탭
 */
export const StaleClosureTab: React.FC<StaleClosureTabProps> = ({ devTools }) => {
    const [trackingInfo, setTrackingInfo] = useState<StaleClosureTrackingInfo | null>(null);
    const [selectedWarning, setSelectedWarning] = useState<string | null>(null);
    const [severityFilter, setSeverityFilter] = useState<string>('all');

    // 데이터 새로고침
    const refreshData = useCallback(() => {
        setTrackingInfo(devTools.getStaleClosureTrackingInfo());
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

    const { warnings, stats } = trackingInfo;

    // 필터링된 경고
    const filteredWarnings = warnings.filter(warning => {
        if (severityFilter === 'all') return true;
        return warning.severity === severityFilter;
    });

    // 선택된 경고
    const selectedWarningData = selectedWarning
        ? warnings.find(w => w.id === selectedWarning)
        : null;

    // 심각도 아이콘
    const getSeverityIcon = (severity: string) => {
        switch (severity) {
            case 'error': return '❌';
            case 'warning': return '⚠️';
            default: return '❓';
        }
    };

    // 경고 타입 아이콘
    const getTypeIcon = (type: string) => {
        switch (type) {
            case 'state-changed-during-async': return '⏳';
            case 'captured-in-callback': return '📸';
            case 'stale-in-handler': return '🔄';
            default: return '❓';
        }
    };

    return (
        <div className="g7dt-space-y-4">
            {/* 통계 */}
            <div className="g7dt-card">
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">Stale Closure 통계</h3>
                <div className="g7dt-stats-grid-3">
                    <div className="g7dt-text-center">
                        <div className="g7dt-text-2xl g7dt-font-bold g7dt-text-yellow">{stats.totalWarnings || 0}</div>
                        <div className="g7dt-text-xs g7dt-text-muted">총 경고</div>
                    </div>
                    <div className="g7dt-text-center">
                        <div className="g7dt-text-2xl g7dt-font-bold g7dt-text-red">{stats.warningsBySeverity?.error || 0}</div>
                        <div className="g7dt-text-xs g7dt-text-muted">에러</div>
                    </div>
                    <div className="g7dt-text-center">
                        <div className="g7dt-text-2xl g7dt-font-bold g7dt-text-orange">{stats.warningsBySeverity?.warning || 0}</div>
                        <div className="g7dt-text-xs g7dt-text-muted">경고</div>
                    </div>
                </div>
            </div>

            {/* 타입별 통계 */}
            {stats.warningsByType && Object.keys(stats.warningsByType).length > 0 && (
                <div className="g7dt-card">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">타입별 분포</h3>
                    <div className="g7dt-space-y-2">
                        {Object.entries(stats.warningsByType).map(([type, count]) => (
                            <div key={type} className="g7dt-flex g7dt-justify-between g7dt-text-xs">
                                <span className="g7dt-text-muted">
                                    {getTypeIcon(type)} {type}
                                </span>
                                <span className="g7dt-text-white g7dt-font-mono">{count as number}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* 필터 */}
            <div className="g7dt-card">
                <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                    <span className="g7dt-text-xs g7dt-text-muted">심각도:</span>
                    <select
                        value={severityFilter}
                        onChange={(e) => setSeverityFilter(e.target.value)}
                        className="g7dt-select"
                    >
                        <option value="all">전체</option>
                        <option value="error">에러</option>
                        <option value="warning">경고</option>
                    </select>
                    <span className="g7dt-text-xs g7dt-text-muted g7dt-ml-auto">
                        {filteredWarnings.length}개 표시
                    </span>
                </div>
            </div>

            {/* 경고 목록 */}
            <div className="g7dt-card">
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">Stale Closure 경고</h3>
                {filteredWarnings.length === 0 ? (
                    <div className="g7dt-empty-state g7dt-text-green">
                        ✅ Stale Closure 경고가 없습니다
                    </div>
                ) : (
                    <div className="g7dt-space-y-2 g7dt-max-h-60 g7dt-overflow-y-auto">
                        {filteredWarnings.slice().reverse().map((warning) => (
                            <div
                                key={warning.id}
                                className={`g7dt-list-item g7dt-cursor-pointer ${
                                    selectedWarning === warning.id ? 'g7dt-selected' : ''
                                }`}
                                onClick={() => setSelectedWarning(
                                    selectedWarning === warning.id ? null : warning.id
                                )}
                            >
                                <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                    <span>{getSeverityIcon(warning.severity)}</span>
                                    <span>{getTypeIcon(warning.type)}</span>
                                    <span className="g7dt-text-xs g7dt-text-white g7dt-truncate">
                                        {warning.location}
                                    </span>
                                    <span className="g7dt-text-xs g7dt-text-muted g7dt-ml-auto">
                                        {new Date(warning.timestamp).toLocaleTimeString()}
                                    </span>
                                </div>
                                <div className="g7dt-text-xs g7dt-text-muted g7dt-mt-1 g7dt-truncate">
                                    {warning.statePath}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* 선택된 경고 상세 */}
            {selectedWarningData && (
                <div className="g7dt-card g7dt-border-yellow">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">
                        {getSeverityIcon(selectedWarningData.severity)} 경고 상세
                    </h3>
                    <div className="g7dt-space-y-3">
                        <div>
                            <div className="g7dt-text-xs g7dt-text-muted">위치</div>
                            <div className="g7dt-text-sm g7dt-text-white g7dt-mono">
                                {selectedWarningData.location}
                            </div>
                        </div>
                        <div>
                            <div className="g7dt-text-xs g7dt-text-muted">타입</div>
                            <div className="g7dt-text-sm g7dt-text-white">
                                {getTypeIcon(selectedWarningData.type)} {selectedWarningData.type}
                            </div>
                        </div>
                        <div>
                            <div className="g7dt-text-xs g7dt-text-muted">상태 경로</div>
                            <div className="g7dt-text-sm g7dt-text-yellow g7dt-mono">
                                {selectedWarningData.statePath}
                            </div>
                        </div>
                        <div className="g7dt-grid-2 g7dt-gap-3">
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted">캡처된 값</div>
                                <pre className="g7dt-code-block g7dt-text-red">
                                    {JSON.stringify(selectedWarningData.capturedValue, null, 2)}
                                </pre>
                            </div>
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted">현재 값</div>
                                <pre className="g7dt-code-block g7dt-text-green">
                                    {JSON.stringify(selectedWarningData.currentValue, null, 2)}
                                </pre>
                            </div>
                        </div>
                        <div>
                            <div className="g7dt-text-xs g7dt-text-muted">시간 차이</div>
                            <div className="g7dt-text-sm g7dt-text-white">
                                {selectedWarningData.timeDiff}ms
                            </div>
                        </div>
                        {selectedWarningData.suggestion && (
                            <div className="g7dt-bg-blue-dark g7dt-p-2 g7dt-rounded">
                                <div className="g7dt-text-xs g7dt-text-blue">💡 제안</div>
                                <div className="g7dt-text-sm g7dt-text-white g7dt-mt-1">
                                    {selectedWarningData.suggestion}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
};

export default StaleClosureTab;
