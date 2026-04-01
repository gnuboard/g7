/**
 * 변경 감지 탭 컴포넌트
 *
 * 핸들러 실행 시 상태/데이터소스 변경 여부를 추적하고
 * early return, 기대 변경 미발생 등의 문제를 감지하여 표시합니다.
 */

import React, { useState, useEffect } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { ChangeDetectionInfo, ChangeAlertType } from '../../types';

export interface ChangeDetectionTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 변경 감지 탭
 */
export const ChangeDetectionTab: React.FC<ChangeDetectionTabProps> = ({ devTools }) => {
    const [changeInfo, setChangeInfo] = useState<ChangeDetectionInfo>(devTools.getChangeDetectionInfo());
    const [selectedExecution, setSelectedExecution] = useState<string | null>(null);
    const [showWarningsOnly, setShowWarningsOnly] = useState(false);
    const [expandedAlerts, setExpandedAlerts] = useState<Set<string>>(new Set());

    useEffect(() => {
        const interval = setInterval(() => {
            setChangeInfo(devTools.getChangeDetectionInfo());
        }, 500);
        return () => clearInterval(interval);
    }, [devTools]);

    const { executionDetails, alerts, stats } = changeInfo;

    // 필터링된 실행 목록
    const filteredExecutions = showWarningsOnly
        ? executionDetails.filter(e => e.alerts.length > 0 || e.stateChanges.length === 0)
        : executionDetails;

    // 선택된 실행 상세
    const selectedDetail = selectedExecution
        ? executionDetails.find(e => e.executionId === selectedExecution)
        : null;

    // 알림 토글
    const toggleAlert = (alertId: string) => {
        setExpandedAlerts(prev => {
            const next = new Set(prev);
            if (next.has(alertId)) {
                next.delete(alertId);
            } else {
                next.add(alertId);
            }
            return next;
        });
    };

    // 종료 사유별 아이콘
    const getExitReasonIcon = (reason?: string): string => {
        switch (reason) {
            case 'normal': return '✅';
            case 'early-return-condition': return '⏎';
            case 'early-return-validation': return '❌';
            case 'error': return '💥';
            default: return '❓';
        }
    };

    // 알림 유형별 아이콘
    const getAlertTypeIcon = (type: ChangeAlertType): string => {
        switch (type) {
            case 'no-state-change': return '⚠️';
            case 'no-datasource-change': return '📊';
            case 'expected-not-fulfilled': return '❗';
            case 'object-reference-same': return '🔄';
            case 'early-return-detected': return '⏎';
            case 'async-timing-issue': return '⏱️';
            default: return '📢';
        }
    };

    // 심각도별 색상
    const getSeverityColor = (severity: string): string => {
        switch (severity) {
            case 'error': return 'g7dt-text-red';
            case 'warning': return 'g7dt-text-yellow';
            case 'info': return 'g7dt-text-blue';
            default: return 'g7dt-text-white';
        }
    };

    // 시간 포맷
    const formatTime = (timestamp: number): string => {
        return new Date(timestamp).toLocaleTimeString('ko-KR', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            fractionalSecondDigits: 3,
        });
    };

    return (
        <div className="g7dt-space-y-4">
            {/* 알림 배너 (경고/에러가 있는 경우) */}
            {alerts.filter(a => a.severity === 'error' || a.severity === 'warning').length > 0 && (
                <div className="g7dt-card" style={{ backgroundColor: '#7f1d1d', borderColor: '#991b1b' }}>
                    <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                        <span className="g7dt-font-bold">
                            ⚠️ 변경 감지 알림: {alerts.filter(a => a.severity === 'error').length}개 에러,{' '}
                            {alerts.filter(a => a.severity === 'warning').length}개 경고
                        </span>
                        <button
                            onClick={() => devTools.clearChangeDetectionData()}
                            className="g7dt-btn-small"
                        >
                            초기화
                        </button>
                    </div>
                </div>
            )}

            {/* 통계 */}
            <div className="g7dt-card">
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">📊 변경 감지 통계</h3>
                <div className="g7dt-grid-3">
                    <div className="g7dt-stat-item">
                        <span className="g7dt-text-muted">총 실행:</span>
                        <span className="g7dt-text-blue">{stats.totalExecutions}</span>
                    </div>
                    <div className="g7dt-stat-item">
                        <span className="g7dt-text-muted">상태 변경 있음:</span>
                        <span className="g7dt-text-green">{stats.executionsWithStateChange}</span>
                    </div>
                    <div className="g7dt-stat-item">
                        <span className="g7dt-text-muted">상태 변경 없음:</span>
                        <span className={stats.executionsWithoutStateChange > 0 ? 'g7dt-text-yellow' : 'g7dt-text-gray'}>
                            {stats.executionsWithoutStateChange}
                        </span>
                    </div>
                    <div className="g7dt-stat-item">
                        <span className="g7dt-text-muted">Early Return:</span>
                        <span className="g7dt-text-orange">{stats.earlyReturnCount}</span>
                    </div>
                    <div className="g7dt-stat-item">
                        <span className="g7dt-text-muted">알림:</span>
                        <span className={stats.alertCount > 0 ? 'g7dt-text-red' : 'g7dt-text-gray'}>
                            {stats.alertCount}
                        </span>
                    </div>
                </div>
            </div>

            {/* 최근 알림 목록 */}
            {alerts.length > 0 && (
                <div className="g7dt-card">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">🔔 최근 알림</h3>
                    <div className="g7dt-space-y-2" style={{ maxHeight: '200px', overflowY: 'auto' }}>
                        {alerts.slice(-10).reverse().map(alert => (
                            <div
                                key={alert.id}
                                className="g7dt-list-item"
                                onClick={() => toggleAlert(alert.id)}
                                style={{ cursor: 'pointer' }}
                            >
                                <div className="g7dt-flex g7dt-justify-between g7dt-items-start">
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        <span>{getAlertTypeIcon(alert.type)}</span>
                                        <span className={`g7dt-text-xs ${getSeverityColor(alert.severity)}`}>
                                            {alert.message}
                                        </span>
                                    </div>
                                    <span className="g7dt-text-xs g7dt-text-muted">
                                        {formatTime(alert.timestamp)}
                                    </span>
                                </div>
                                {expandedAlerts.has(alert.id) && (
                                    <div className="g7dt-mt-2 g7dt-text-xs g7dt-text-muted g7dt-pl-6">
                                        <p>{alert.description}</p>
                                        {alert.statePath && (
                                            <p className="g7dt-mt-1">
                                                <span className="g7dt-text-cyan">경로:</span> {alert.statePath}
                                            </p>
                                        )}
                                        {alert.suggestion && (
                                            <p className="g7dt-mt-1">
                                                <span className="g7dt-text-green">제안:</span> {alert.suggestion}
                                            </p>
                                        )}
                                        {alert.docLink && (
                                            <p className="g7dt-mt-1">
                                                <span className="g7dt-text-blue">문서:</span>{' '}
                                                <code className="g7dt-code">{alert.docLink}</code>
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* 핸들러 실행 목록 */}
            <div className="g7dt-card">
                <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-mb-2">
                    <h3 className="g7dt-text-sm g7dt-font-bold">🔧 핸들러 실행 이력</h3>
                    <label className="g7dt-flex g7dt-items-center g7dt-gap-2 g7dt-text-xs">
                        <input
                            type="checkbox"
                            checked={showWarningsOnly}
                            onChange={e => setShowWarningsOnly(e.target.checked)}
                        />
                        <span>경고만 표시</span>
                    </label>
                </div>

                {filteredExecutions.length === 0 ? (
                    <div className="g7dt-empty-state">
                        핸들러 실행 이력이 없습니다.
                        <br />
                        액션을 실행하면 여기에 표시됩니다.
                    </div>
                ) : (
                    <div className="g7dt-space-y-1" style={{ maxHeight: '300px', overflowY: 'auto' }}>
                        {filteredExecutions.slice(-20).reverse().map(exec => (
                            <div
                                key={exec.executionId}
                                className={`g7dt-list-item ${selectedExecution === exec.executionId ? 'g7dt-selected' : ''}`}
                                onClick={() => setSelectedExecution(
                                    selectedExecution === exec.executionId ? null : exec.executionId
                                )}
                                style={{ cursor: 'pointer' }}
                            >
                                <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        <span>{getExitReasonIcon(exec.exitReason)}</span>
                                        <span className="g7dt-text-white g7dt-font-mono g7dt-text-xs">
                                            {exec.handlerName}
                                        </span>
                                        {exec.alerts.length > 0 && (
                                            <span className="g7dt-badge g7dt-badge-warning">
                                                {exec.alerts.length}
                                            </span>
                                        )}
                                    </div>
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2 g7dt-text-xs">
                                        <span className="g7dt-text-muted">
                                            상태: {exec.stateChanges.length}
                                        </span>
                                        <span className="g7dt-text-muted">
                                            {exec.duration ? `${exec.duration}ms` : '-'}
                                        </span>
                                        <span className="g7dt-text-muted">
                                            {formatTime(exec.startTime)}
                                        </span>
                                    </div>
                                </div>

                                {/* 선택된 실행 상세 */}
                                {selectedExecution === exec.executionId && selectedDetail && (
                                    <div className="g7dt-mt-3 g7dt-pt-3 g7dt-border-t g7dt-border-gray-700">
                                        {/* 종료 정보 */}
                                        <div className="g7dt-mb-2">
                                            <span className="g7dt-text-xs g7dt-text-muted">종료 사유: </span>
                                            <span className="g7dt-text-xs g7dt-text-white">
                                                {selectedDetail.exitReason || 'unknown'}
                                            </span>
                                            {selectedDetail.exitLocation && (
                                                <>
                                                    <span className="g7dt-text-xs g7dt-text-muted g7dt-ml-2">위치: </span>
                                                    <code className="g7dt-code g7dt-text-xs">
                                                        {selectedDetail.exitLocation}
                                                    </code>
                                                </>
                                            )}
                                        </div>
                                        {selectedDetail.exitDescription && (
                                            <div className="g7dt-mb-2 g7dt-text-xs g7dt-text-muted">
                                                {selectedDetail.exitDescription}
                                            </div>
                                        )}

                                        {/* 상태 변경 목록 */}
                                        {selectedDetail.stateChanges.length > 0 && (
                                            <div className="g7dt-mb-2">
                                                <span className="g7dt-text-xs g7dt-font-bold g7dt-text-green">
                                                    상태 변경 ({selectedDetail.stateChanges.length})
                                                </span>
                                                <div className="g7dt-mt-1 g7dt-space-y-1">
                                                    {selectedDetail.stateChanges.map(sc => (
                                                        <div key={sc.id} className="g7dt-text-xs g7dt-pl-2">
                                                            <span className="g7dt-text-cyan">{sc.path}</span>
                                                            <span className="g7dt-text-muted"> ({sc.changeType})</span>
                                                            {sc.comparison.changedKeys && (
                                                                <span className="g7dt-text-muted g7dt-ml-1">
                                                                    [변경: {sc.comparison.changedKeys.join(', ')}]
                                                                </span>
                                                            )}
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {/* 데이터소스 변경 목록 */}
                                        {selectedDetail.dataSourceChanges.length > 0 && (
                                            <div className="g7dt-mb-2">
                                                <span className="g7dt-text-xs g7dt-font-bold g7dt-text-blue">
                                                    데이터소스 변경 ({selectedDetail.dataSourceChanges.length})
                                                </span>
                                                <div className="g7dt-mt-1 g7dt-space-y-1">
                                                    {selectedDetail.dataSourceChanges.map((dc, idx) => (
                                                        <div key={idx} className="g7dt-text-xs g7dt-pl-2">
                                                            <span className="g7dt-text-cyan">{dc.dataSourceId}</span>
                                                            <span className="g7dt-text-muted">
                                                                {' '}({dc.changeType}: {dc.previousStatus} → {dc.newStatus})
                                                            </span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {/* 기대 변경 */}
                                        {selectedDetail.expectedChanges && (
                                            <div className="g7dt-mb-2">
                                                <span className="g7dt-text-xs g7dt-font-bold g7dt-text-yellow">
                                                    기대 변경
                                                </span>
                                                <div className="g7dt-mt-1 g7dt-text-xs g7dt-pl-2 g7dt-text-muted">
                                                    상태: {selectedDetail.expectedChanges.expectedStatePaths.join(', ') || '없음'}
                                                    <br />
                                                    데이터소스: {selectedDetail.expectedChanges.expectedDataSources.join(', ') || '없음'}
                                                </div>
                                            </div>
                                        )}

                                        {/* 알림 */}
                                        {selectedDetail.alerts.length > 0 && (
                                            <div>
                                                <span className="g7dt-text-xs g7dt-font-bold g7dt-text-red">
                                                    알림 ({selectedDetail.alerts.length})
                                                </span>
                                                <div className="g7dt-mt-1 g7dt-space-y-1">
                                                    {selectedDetail.alerts.map(alert => (
                                                        <div key={alert.id} className="g7dt-text-xs g7dt-pl-2">
                                                            <span>{getAlertTypeIcon(alert.type)}</span>
                                                            <span className={`g7dt-ml-1 ${getSeverityColor(alert.severity)}`}>
                                                                {alert.message}
                                                            </span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* 사용 가이드 */}
            <div className="g7dt-card">
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">💡 변경 감지 가이드</h3>
                <ul className="g7dt-help-list">
                    <li>핸들러 실행 후 상태 변경이 없으면 ⚠️ 경고가 표시됩니다</li>
                    <li>early return으로 종료된 경우 ⏎ 아이콘으로 표시됩니다</li>
                    <li>객체 변경 시 참조가 동일하면 🔄 경고가 발생합니다</li>
                    <li>문제 해결: exitLocation 확인 → 조건문 검토 → 불변성 확인</li>
                </ul>
            </div>
        </div>
    );
};

export default ChangeDetectionTab;
