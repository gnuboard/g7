/**
 * 액션 탭 컴포넌트
 *
 * 액션 실행 이력과 Sequence 상세 정보를 표시합니다.
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { ActionLog, SequenceActionInfo, SequenceTrackingInfo, StateDiff } from '../../types';

export interface ActionsTabProps {
    actions: ActionLog[];
    devTools?: G7DevToolsCore;
}

/**
 * 액션 하나가 바꾼 상태 경로를 모은다 — stateDiff 의 추가, 변경, 제거 키에 스코프 접두를 붙인다
 *
 * @param action Sequence 안 액션 정보
 * @returns `_global.theme` 같은 경로 목록
 */
const changedStatePaths = (action: SequenceActionInfo): string[] => {
    const scopes: Array<[string, StateDiff | undefined]> = [
        ['_global', action.stateDiff?.global],
        ['_local', action.stateDiff?.local],
        ['_isolated', action.stateDiff?.isolated],
    ];
    return scopes.flatMap(([prefix, diff]) =>
        diff
            ? [...diff.added, ...diff.changed.map(change => change.path), ...diff.removed].map(path => `${prefix}.${path}`)
            : []
    );
};

/**
 * 액션 탭
 */
export const ActionsTab: React.FC<ActionsTabProps> = ({ actions, devTools }) => {
    const [filter, setFilter] = useState<string>('');
    const [viewMode, setViewMode] = useState<'actions' | 'sequences'>('actions');
    const [sequenceInfo, setSequenceInfo] = useState<SequenceTrackingInfo | null>(null);
    const [selectedSequence, setSelectedSequence] = useState<string | null>(null);

    // Sequence 데이터 새로고침
    const refreshSequenceData = useCallback(() => {
        if (devTools) {
            setSequenceInfo(devTools.getSequenceTrackingInfo());
        }
    }, [devTools]);

    useEffect(() => {
        if (viewMode === 'sequences' && devTools) {
            refreshSequenceData();
            const intervalId = setInterval(refreshSequenceData, 2000);
            return () => clearInterval(intervalId);
        }
    }, [viewMode, devTools, refreshSequenceData]);

    const filteredActions = filter
        ? actions.filter(a => (a.type ?? '').toLowerCase().includes(filter.toLowerCase()) || a.status === filter)
        : actions;

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'success':
                return 'g7dt-status-success';
            case 'error':
                return 'g7dt-status-error';
            case 'started':
                return 'g7dt-status-started';
            default:
                return 'g7dt-status-default';
        }
    };

    const getStatusBadgeColor = (status: string) => {
        switch (status) {
            case 'success':
                return 'g7dt-badge-success';
            case 'error':
                return 'g7dt-badge-error';
            default:
                return 'g7dt-badge-warning';
        }
    };

    // 선택된 Sequence
    const selectedSequenceData = selectedSequence && sequenceInfo
        ? sequenceInfo.executions.find(s => s.sequenceId === selectedSequence)
        : null;

    return (
        <div className="g7dt-space-y-4">
            {/* 뷰 모드 선택 */}
            {devTools && (
                <div className="g7dt-card">
                    <div className="g7dt-flex g7dt-gap-2">
                        <button
                            className={`g7dt-tab-button ${viewMode === 'actions' ? 'g7dt-tab-active' : ''}`}
                            onClick={() => setViewMode('actions')}
                        >
                            📋 액션 ({actions.length})
                        </button>
                        <button
                            className={`g7dt-tab-button ${viewMode === 'sequences' ? 'g7dt-tab-active' : ''}`}
                            onClick={() => setViewMode('sequences')}
                        >
                            🔗 Sequence ({sequenceInfo?.executions.length || 0})
                        </button>
                    </div>
                </div>
            )}

            {/* 필터 (액션 모드) */}
            {viewMode === 'actions' && (
                <div className="g7dt-flex g7dt-gap-2 g7dt-flex-shrink-0">
                    <input
                        type="text"
                        placeholder="액션 타입 또는 상태로 필터..."
                        value={filter}
                        onChange={e => setFilter(e.target.value)}
                        className="g7dt-input g7dt-flex-1"
                    />
                    <button onClick={() => setFilter('')} className="g7dt-btn-text">
                        초기화
                    </button>
                </div>
            )}

            {/* Sequence Details (시퀀스 모드) */}
            {viewMode === 'sequences' && sequenceInfo && (
                <>
                    {/* Sequence 통계 */}
                    <div className="g7dt-card">
                        <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">Sequence 통계</h3>
                        <div className="g7dt-stats-grid-3">
                            <div className="g7dt-text-center">
                                <div className="g7dt-text-2xl g7dt-font-bold g7dt-text-blue">
                                    {sequenceInfo.stats.totalExecutions}
                                </div>
                                <div className="g7dt-text-xs g7dt-text-muted">총 실행</div>
                            </div>
                            <div className="g7dt-text-center">
                                <div className="g7dt-text-2xl g7dt-font-bold g7dt-text-green">
                                    {sequenceInfo.stats.successCount}
                                </div>
                                <div className="g7dt-text-xs g7dt-text-muted">성공</div>
                            </div>
                            <div className="g7dt-text-center">
                                <div className="g7dt-text-2xl g7dt-font-bold g7dt-text-red">
                                    {sequenceInfo.stats.errorCount}
                                </div>
                                <div className="g7dt-text-xs g7dt-text-muted">실패</div>
                            </div>
                        </div>
                    </div>

                    {/* Sequence 목록 */}
                    <div className="g7dt-card">
                        <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">Sequence 실행 목록</h3>
                        {sequenceInfo.executions.length === 0 ? (
                            <div className="g7dt-empty-state">실행된 Sequence가 없습니다</div>
                        ) : (
                            <div className="g7dt-space-y-2 g7dt-max-h-40 g7dt-overflow-y-auto">
                                {sequenceInfo.executions.slice().reverse().map((seq) => (
                                    <div
                                        key={seq.sequenceId}
                                        className={`g7dt-list-item g7dt-cursor-pointer ${
                                            selectedSequence === seq.sequenceId ? 'g7dt-selected' : ''
                                        } ${seq.status === 'error' ? 'g7dt-border-red' : ''}`}
                                        onClick={() => setSelectedSequence(
                                            selectedSequence === seq.sequenceId ? null : seq.sequenceId
                                        )}
                                    >
                                        <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                            <span>{seq.status === 'success' ? '✅' : seq.status === 'error' ? '❌' : '⏳'}</span>
                                            <span className="g7dt-text-xs g7dt-text-white">
                                                {seq.actions.length}개 액션
                                            </span>
                                            <span className="g7dt-text-xs g7dt-text-muted g7dt-ml-auto">
                                                {seq.totalDuration.toFixed(0)}ms
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* 선택된 Sequence 상세 */}
                    {selectedSequenceData && (
                        <div className="g7dt-card g7dt-border-blue">
                            <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">
                                🔗 Sequence 상세 ({selectedSequenceData.actions.length}개 액션)
                            </h3>
                            <div className="g7dt-space-y-2 g7dt-max-h-60 g7dt-overflow-y-auto">
                                {selectedSequenceData.actions.map((action, idx) => {
                                    const stateChanges = changedStatePaths(action);
                                    return (
                                        <div
                                            key={idx}
                                            className={`g7dt-list-item ${
                                                action.status === 'error' ? 'g7dt-border-red' : ''
                                            }`}
                                        >
                                            <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                                <span className="g7dt-text-xs g7dt-text-muted">#{action.index + 1}</span>
                                                <span className="g7dt-text-xs g7dt-text-white g7dt-font-mono">
                                                    {action.handler}
                                                </span>
                                                <span className={`g7dt-text-xs ${
                                                    action.status === 'success' ? 'g7dt-text-green' : 'g7dt-text-red'
                                                }`}>
                                                    {action.status === 'success' ? '✓' : '✕'}
                                                </span>
                                                <span className="g7dt-text-xs g7dt-text-muted g7dt-ml-auto">
                                                    {action.duration.toFixed(0)}ms
                                                </span>
                                            </div>
                                            {stateChanges.length > 0 && (
                                                <div className="g7dt-mt-1">
                                                    <div className="g7dt-text-xs g7dt-text-muted">상태 변경:</div>
                                                    {stateChanges.slice(0, 3).map((path, cIdx) => (
                                                        <div key={cIdx} className="g7dt-text-xs g7dt-text-yellow g7dt-ml-2">
                                                            {path}
                                                        </div>
                                                    ))}
                                                    {stateChanges.length > 3 && (
                                                        <div className="g7dt-text-xs g7dt-text-muted g7dt-ml-2">
                                                            ...외 {stateChanges.length - 3}개
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </>
            )}

            {/* 액션 목록 (액션 모드) */}
            {viewMode === 'actions' && (
            <div className="g7dt-section">
                <div className="g7dt-scroll-list g7dt-space-y-2-flat g7dt-action-list">
                    {filteredActions.length === 0 ? (
                        <div className="g7dt-empty-message">액션 이력이 없습니다</div>
                    ) : (
                        filteredActions
                            .slice(-30)
                            .reverse()
                            .map((action, i) => (
                                <div key={`${action.id}-${i}`} className={`g7dt-action-item ${getStatusColor(action.status)}`}>
                                    <div className="g7dt-flex g7dt-justify-between g7dt-items-start">
                                        <div>
                                            <span className="g7dt-font-mono g7dt-font-bold">{action.type}</span>
                                            <span className={`g7dt-status-badge ${getStatusBadgeColor(action.status)}`}>
                                                {action.status}
                                            </span>
                                        </div>
                                        <span className="g7dt-text-gray">
                                            {action.duration ? `${action.duration.toFixed(2)}ms` : '-'}
                                        </span>
                                    </div>
                                    {action.error && <div className="g7dt-error-box">{action.error.message}</div>}
                                    {action.params && Object.keys(action.params).length > 0 && (
                                        <details className="g7dt-details">
                                            <summary className="g7dt-summary">파라미터</summary>
                                            <pre className="g7dt-pre">{JSON.stringify(action.params, null, 2)}</pre>
                                        </details>
                                    )}
                                </div>
                            ))
                    )}
                </div>
            </div>
            )}
        </div>
    );
};

export default ActionsTab;
