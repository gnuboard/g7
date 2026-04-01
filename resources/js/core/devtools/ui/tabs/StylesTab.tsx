/**
 * 스타일 검증 탭 컴포넌트
 *
 * CSS 스타일 이슈를 감지하고 표시합니다.
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { StyleValidationInfo } from '../../types';

export interface StylesTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 스타일 검증 탭
 */
export const StylesTab: React.FC<StylesTabProps> = ({ devTools }) => {
    const [styleInfo, setStyleInfo] = useState<StyleValidationInfo | null>(null);
    const [selectedIssue, setSelectedIssue] = useState<string | null>(null);
    const [typeFilter, setTypeFilter] = useState<string>('all');

    // 데이터 새로고침
    const refreshData = useCallback(() => {
        setStyleInfo(devTools.getStyleValidationInfo());
    }, [devTools]);

    // 주기적 갱신
    useEffect(() => {
        refreshData();
        const intervalId = setInterval(refreshData, 2000);
        return () => clearInterval(intervalId);
    }, [refreshData]);

    if (!styleInfo) {
        return <div className="g7dt-empty-state">로딩 중...</div>;
    }

    // 필터링된 이슈
    const filteredIssues = styleInfo.issues.filter(issue => {
        if (typeFilter === 'all') return true;
        return issue.type === typeFilter;
    });

    // 선택된 이슈
    const selectedIssueData = selectedIssue
        ? styleInfo.issues.find(i => i.componentId === selectedIssue)
        : null;

    const stats = styleInfo.stats;

    return (
        <div className="g7dt-space-y-4">
            {/* 통계 */}
            <div className="g7dt-stats-grid">
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value g7dt-text-blue">
                        {stats.totalComponents}
                    </div>
                    <div className="g7dt-stat-label">총 컴포넌트</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className={`g7dt-stat-value ${stats.invisibleCount > 0 ? 'g7dt-text-red' : 'g7dt-text-green'}`}>
                        {stats.invisibleCount}
                    </div>
                    <div className="g7dt-stat-label">숨겨진 요소</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className={`g7dt-stat-value ${stats.tailwindIssueCount > 0 ? 'g7dt-text-yellow' : 'g7dt-text-green'}`}>
                        {stats.tailwindIssueCount}
                    </div>
                    <div className="g7dt-stat-label">Tailwind 이슈</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className={`g7dt-stat-value ${stats.darkModeIssueCount > 0 ? 'g7dt-text-purple' : 'g7dt-text-green'}`}>
                        {stats.darkModeIssueCount}
                    </div>
                    <div className="g7dt-stat-label">다크모드 이슈</div>
                </div>
            </div>

            {/* 필터 */}
            <div className="g7dt-flex g7dt-gap-2">
                <select
                    value={typeFilter}
                    onChange={e => setTypeFilter(e.target.value)}
                    className="g7dt-select"
                >
                    <option value="all">모든 이슈</option>
                    <option value="invisible-element">숨겨진 요소</option>
                    <option value="tailwind-purging">Tailwind 퍼징</option>
                    <option value="dark-mode-missing">다크모드 누락</option>
                </select>
                <button
                    onClick={refreshData}
                    className="g7dt-btn-secondary"
                >
                    새로고침
                </button>
            </div>

            {/* 이슈 목록 */}
            <div className="g7dt-scroll-list">
                {filteredIssues.length === 0 ? (
                    <div className="g7dt-empty-state">
                        {styleInfo.issues.length === 0 ? '스타일 이슈가 없습니다 ✓' : '필터에 맞는 이슈가 없습니다'}
                    </div>
                ) : (
                    filteredIssues.map(issue => {
                        const icon = issue.type === 'invisible-element' ? '👁️' :
                            issue.type === 'tailwind-purging' ? '🎨' : '🌙';
                        const severityClass = issue.severity === 'error' ? 'g7dt-severity-error' :
                            issue.severity === 'warning' ? 'g7dt-severity-warning' : 'g7dt-severity-info';

                        return (
                            <div
                                key={issue.componentId}
                                onClick={() => setSelectedIssue(issue.componentId)}
                                className={`g7dt-style-issue-item ${severityClass} ${
                                    selectedIssue === issue.componentId ? 'g7dt-selected' : ''
                                }`}
                            >
                                <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                                    <span className="g7dt-mono g7dt-text-blue">
                                        {icon} {issue.componentName}
                                    </span>
                                    <span className={
                                        issue.severity === 'error' ? 'g7dt-badge-error' :
                                        issue.severity === 'warning' ? 'g7dt-badge-warning' :
                                        'g7dt-badge-info'
                                    }>
                                        {issue.type}
                                    </span>
                                </div>
                                <div className="g7dt-text-muted g7dt-mt-1 g7dt-truncate">{issue.description}</div>
                            </div>
                        );
                    })
                )}
            </div>

            {/* 선택된 이슈 상세 */}
            {selectedIssueData && (
                <div className="g7dt-card">
                    <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-mb-2">
                        <h3 className="g7dt-text-sm g7dt-font-bold">{selectedIssueData.componentName}</h3>
                        <button
                            onClick={() => setSelectedIssue(null)}
                            className="g7dt-close-btn"
                        >
                            ✕
                        </button>
                    </div>
                    <div className="g7dt-space-y-2 g7dt-text-xs">
                        <div>
                            <span className="g7dt-text-muted">이슈 타입:</span>
                            <span className="g7dt-ml-2 g7dt-text-yellow">{selectedIssueData.type}</span>
                        </div>
                        <div>
                            <span className="g7dt-text-muted">설명:</span>
                            <div className="g7dt-mt-1 g7dt-text-white">{selectedIssueData.description}</div>
                        </div>
                        <div>
                            <span className="g7dt-text-muted">CSS 속성:</span>
                            <span className="g7dt-ml-2 g7dt-text-purple">{selectedIssueData.property}</span>
                            <span className="g7dt-ml-2 g7dt-text-muted">= {selectedIssueData.currentValue}</span>
                            {selectedIssueData.expectedValue && (
                                <span className="g7dt-ml-2 g7dt-text-green">(예상: {selectedIssueData.expectedValue})</span>
                            )}
                        </div>
                        {selectedIssueData.suggestion && (
                            <div>
                                <span className="g7dt-text-muted">해결 방법:</span>
                                <div className="g7dt-mt-1 g7dt-text-green">{selectedIssueData.suggestion}</div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* 컴포넌트 스타일 정보 */}
            {styleInfo.componentStyles.length > 0 && (
                <div>
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">컴포넌트 스타일 ({styleInfo.componentStyles.length})</h3>
                    <div className="g7dt-scroll-list-sm">
                        {styleInfo.componentStyles.slice(0, 20).map((comp, i) => (
                            <div key={i} className="g7dt-list-item">
                                <div className="g7dt-flex g7dt-justify-between">
                                    <span className="g7dt-text-blue">{comp.componentName}</span>
                                    <span className="g7dt-text-muted">{comp.classes.length} classes</span>
                                </div>
                                {comp.classes.length > 0 && (
                                    <div className="g7dt-text-muted g7dt-mt-1 g7dt-truncate">
                                        {comp.classes.slice(0, 5).join(' ')}
                                        {comp.classes.length > 5 && '...'}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
};

export default StylesTab;
