/**
 * 모달 상태 스코프 추적 탭 컴포넌트
 *
 * 모달 상태 격리, 유출 감지, 중첩 모달 관계를 표시합니다.
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { ModalStateScopeTrackingInfo, ModalStateInfo, ModalStateIssue } from '../../types';

export interface ModalScopeTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 모달 상태 스코프 추적 탭
 */
export const ModalScopeTab: React.FC<ModalScopeTabProps> = ({ devTools }) => {
    const [trackingInfo, setTrackingInfo] = useState<ModalStateScopeTrackingInfo | null>(null);
    const [selectedModal, setSelectedModal] = useState<string | null>(null);
    const [selectedIssue, setSelectedIssue] = useState<string | null>(null);
    const [viewMode, setViewMode] = useState<'modals' | 'issues' | 'logs'>('modals');

    // 데이터 새로고침
    const refreshData = useCallback(() => {
        setTrackingInfo(devTools.getModalStateScopeTrackingInfo());
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

    const { modals, issues, relations, changeLogs, stats } = trackingInfo;

    // 선택된 모달
    const selectedModalData = selectedModal
        ? modals.find(m => m.modalId === selectedModal)
        : null;

    // 선택된 이슈
    const selectedIssueData = selectedIssue
        ? issues.find(i => i.id === selectedIssue)
        : null;

    // 스코프 타입 아이콘
    const getScopeIcon = (scopeType: string) => {
        switch (scopeType) {
            case 'isolated': return '🔒';
            case 'shared': return '🔓';
            case 'inherited': return '📥';
            default: return '❓';
        }
    };

    // 이슈 타입 아이콘
    const getIssueTypeIcon = (type: string) => {
        switch (type) {
            case 'state-leakage': return '💧';
            case 'isolation-violation': return '🔓';
            case 'parent-mutation': return '👆';
            case 'orphaned-state': return '👻';
            case 'scope-mismatch': return '🔀';
            case 'cleanup-failure': return '🧹';
            case 'missing-definition': return '🚫';
            default: return '❓';
        }
    };

    // 심각도 아이콘
    const getSeverityIcon = (severity: string) => {
        switch (severity) {
            case 'error': return '❌';
            case 'warning': return '⚠️';
            default: return '❓';
        }
    };

    return (
        <div className="g7dt-space-y-4">
            {/* 통계 */}
            <div className="g7dt-card">
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">모달 상태 스코프 통계</h3>
                <div className="g7dt-stats-grid-3">
                    <div className="g7dt-text-center">
                        <div className="g7dt-text-2xl g7dt-font-bold g7dt-text-blue">{stats.totalModals}</div>
                        <div className="g7dt-text-xs g7dt-text-muted">총 모달</div>
                    </div>
                    <div className="g7dt-text-center">
                        <div className="g7dt-text-2xl g7dt-font-bold g7dt-text-green">{stats.openModals}</div>
                        <div className="g7dt-text-xs g7dt-text-muted">열린 모달</div>
                    </div>
                    <div className="g7dt-text-center">
                        <div className="g7dt-text-2xl g7dt-font-bold g7dt-text-red">{stats.totalIssues}</div>
                        <div className="g7dt-text-xs g7dt-text-muted">이슈</div>
                    </div>
                </div>
            </div>

            {/* 스코프 타입별 통계 */}
            <div className="g7dt-card">
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">스코프별 / 이슈별</h3>
                <div className="g7dt-grid-2 g7dt-gap-4">
                    <div>
                        <div className="g7dt-text-xs g7dt-text-muted g7dt-mb-2">스코프 타입</div>
                        <div className="g7dt-space-y-1">
                            {Object.entries(stats.byScope).map(([scope, count]) => (
                                <div key={scope} className="g7dt-flex g7dt-justify-between g7dt-text-xs">
                                    <span>{getScopeIcon(scope)} {scope}</span>
                                    <span className="g7dt-font-mono">{count as number}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                    <div>
                        <div className="g7dt-text-xs g7dt-text-muted g7dt-mb-2">이슈 타입</div>
                        <div className="g7dt-space-y-1">
                            {Object.entries(stats.issuesByType)
                                .filter(([_, count]) => (count as number) > 0)
                                .map(([type, count]) => (
                                    <div key={type} className="g7dt-flex g7dt-justify-between g7dt-text-xs">
                                        <span>{getIssueTypeIcon(type)} {type}</span>
                                        <span className="g7dt-font-mono g7dt-text-red">{count as number}</span>
                                    </div>
                                ))}
                            {Object.values(stats.issuesByType).every(v => v === 0) && (
                                <div className="g7dt-text-xs g7dt-text-green">✅ 이슈 없음</div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* 뷰 모드 선택 */}
            <div className="g7dt-card">
                <div className="g7dt-flex g7dt-gap-2">
                    <button
                        className={`g7dt-tab-button ${viewMode === 'modals' ? 'g7dt-tab-active' : ''}`}
                        onClick={() => setViewMode('modals')}
                    >
                        🪟 모달 ({modals.length})
                    </button>
                    <button
                        className={`g7dt-tab-button ${viewMode === 'issues' ? 'g7dt-tab-active' : ''}`}
                        onClick={() => setViewMode('issues')}
                    >
                        ⚠️ 이슈 ({issues.length})
                    </button>
                    <button
                        className={`g7dt-tab-button ${viewMode === 'logs' ? 'g7dt-tab-active' : ''}`}
                        onClick={() => setViewMode('logs')}
                    >
                        📝 로그 ({changeLogs.length})
                    </button>
                </div>
            </div>

            {/* 모달 목록 */}
            {viewMode === 'modals' && (
                <div className="g7dt-card">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">모달 목록</h3>
                    {modals.length === 0 ? (
                        <div className="g7dt-empty-state">추적된 모달이 없습니다</div>
                    ) : (
                        <div className="g7dt-space-y-2 g7dt-max-h-60 g7dt-overflow-y-auto">
                            {modals.map((modal) => {
                                const hasIssues = issues.some(i => i.modalId === modal.modalId);
                                return (
                                    <div
                                        key={modal.modalId}
                                        className={`g7dt-list-item g7dt-cursor-pointer ${
                                            selectedModal === modal.modalId ? 'g7dt-selected' : ''
                                        } ${hasIssues ? 'g7dt-border-red' : ''}`}
                                        onClick={() => setSelectedModal(
                                            selectedModal === modal.modalId ? null : modal.modalId
                                        )}
                                    >
                                        <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                            <span>{modal.closedAt === null ? '📭' : '📪'}</span>
                                            <span>{getScopeIcon(modal.scopeType)}</span>
                                            <span className="g7dt-text-xs g7dt-text-white">
                                                {modal.modalName}
                                            </span>
                                            {hasIssues && <span className="g7dt-text-red">⚠️</span>}
                                            <span className="g7dt-text-xs g7dt-text-muted g7dt-ml-auto">
                                                변경: {modal.stateChangeCount}
                                            </span>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            )}

            {/* 이슈 목록 */}
            {viewMode === 'issues' && (
                <div className="g7dt-card">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">이슈 목록</h3>
                    {issues.length === 0 ? (
                        <div className="g7dt-empty-state g7dt-text-green">
                            ✅ 모달 상태 이슈가 없습니다
                        </div>
                    ) : (
                        <div className="g7dt-space-y-2 g7dt-max-h-60 g7dt-overflow-y-auto">
                            {issues.slice().reverse().map((issue) => (
                                <div
                                    key={issue.id}
                                    className={`g7dt-list-item g7dt-cursor-pointer ${
                                        selectedIssue === issue.id ? 'g7dt-selected' : ''
                                    }`}
                                    onClick={() => setSelectedIssue(
                                        selectedIssue === issue.id ? null : issue.id
                                    )}
                                >
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        <span>{getSeverityIcon(issue.severity)}</span>
                                        <span>{getIssueTypeIcon(issue.type)}</span>
                                        <span className="g7dt-text-xs g7dt-text-white">
                                            {issue.modalName}
                                        </span>
                                        <span className="g7dt-text-xs g7dt-text-muted g7dt-ml-auto">
                                            {new Date(issue.timestamp).toLocaleTimeString()}
                                        </span>
                                    </div>
                                    <div className="g7dt-text-xs g7dt-text-muted g7dt-mt-1 g7dt-truncate">
                                        {issue.description}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* 상태 변경 로그 */}
            {viewMode === 'logs' && (
                <div className="g7dt-card">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">상태 변경 로그</h3>
                    {changeLogs.length === 0 ? (
                        <div className="g7dt-empty-state">상태 변경 로그가 없습니다</div>
                    ) : (
                        <div className="g7dt-space-y-2 g7dt-max-h-60 g7dt-overflow-y-auto">
                            {changeLogs.slice().reverse().slice(0, 50).map((log) => (
                                <div
                                    key={log.id}
                                    className={`g7dt-list-item ${log.violatesIsolation ? 'g7dt-border-red' : ''}`}
                                >
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        <span className="g7dt-text-xs g7dt-text-white">
                                            {log.modalName}
                                        </span>
                                        <span className="g7dt-text-xs g7dt-text-blue g7dt-mono">
                                            {log.stateKey}
                                        </span>
                                        {log.violatesIsolation && (
                                            <span className="g7dt-text-red">⚠️ 격리 위반</span>
                                        )}
                                        <span className="g7dt-text-xs g7dt-text-muted g7dt-ml-auto">
                                            {new Date(log.timestamp).toLocaleTimeString()}
                                        </span>
                                    </div>
                                    <div className="g7dt-flex g7dt-gap-2 g7dt-text-xs g7dt-mt-1">
                                        <span className="g7dt-text-red g7dt-truncate" style={{ maxWidth: '45%' }}>
                                            {JSON.stringify(log.previousValue)}
                                        </span>
                                        <span className="g7dt-text-muted">→</span>
                                        <span className="g7dt-text-green g7dt-truncate" style={{ maxWidth: '45%' }}>
                                            {JSON.stringify(log.newValue)}
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* 선택된 모달 상세 */}
            {selectedModalData && viewMode === 'modals' && (
                <div className="g7dt-card g7dt-border-blue">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">
                        {getScopeIcon(selectedModalData.scopeType)} 모달 상세: {selectedModalData.modalName}
                    </h3>
                    <div className="g7dt-space-y-3">
                        <div className="g7dt-grid-2 g7dt-gap-3">
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted">스코프</div>
                                <div className="g7dt-text-sm g7dt-text-white">
                                    {getScopeIcon(selectedModalData.scopeType)} {selectedModalData.scopeType}
                                </div>
                            </div>
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted">상태</div>
                                <div className="g7dt-text-sm g7dt-text-white">
                                    {selectedModalData.closedAt === null ? '📭 열림' : '📪 닫힘'}
                                </div>
                            </div>
                        </div>

                        {selectedModalData.parentModalId && (
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted">부모 모달</div>
                                <div className="g7dt-text-sm g7dt-text-purple g7dt-mono">
                                    {selectedModalData.parentModalId}
                                </div>
                            </div>
                        )}

                        <div>
                            <div className="g7dt-text-xs g7dt-text-muted">상태 변경 횟수</div>
                            <div className="g7dt-text-sm g7dt-text-white">
                                {selectedModalData.stateChangeCount}
                            </div>
                        </div>

                        {selectedModalData.isolatedStateKeys.length > 0 && (
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted g7dt-mb-1">격리된 상태 키</div>
                                <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-1">
                                    {selectedModalData.isolatedStateKeys.map((key) => (
                                        <span key={key} className="g7dt-badge g7dt-badge-red g7dt-text-xs">
                                            🔒 {key}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}

                        {selectedModalData.sharedStateKeys.length > 0 && (
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted g7dt-mb-1">공유된 상태 키</div>
                                <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-1">
                                    {selectedModalData.sharedStateKeys.map((key) => (
                                        <span key={key} className="g7dt-badge g7dt-badge-blue g7dt-text-xs">
                                            🔓 {key}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* 선택된 이슈 상세 */}
            {selectedIssueData && viewMode === 'issues' && (
                <div className="g7dt-card g7dt-border-red">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">
                        {getSeverityIcon(selectedIssueData.severity)} 이슈 상세
                    </h3>
                    <div className="g7dt-space-y-3">
                        <div>
                            <div className="g7dt-text-xs g7dt-text-muted">타입</div>
                            <div className="g7dt-text-sm g7dt-text-white">
                                {getIssueTypeIcon(selectedIssueData.type)} {selectedIssueData.type}
                            </div>
                        </div>
                        <div>
                            <div className="g7dt-text-xs g7dt-text-muted">설명</div>
                            <div className="g7dt-text-sm g7dt-text-white">
                                {selectedIssueData.description}
                            </div>
                        </div>
                        {selectedIssueData.affectedStateKeys.length > 0 && (
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted">영향 받는 상태 키</div>
                                <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-1 g7dt-mt-1">
                                    {selectedIssueData.affectedStateKeys.map((key) => (
                                        <span key={key} className="g7dt-badge g7dt-text-xs">
                                            {key}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}
                        {selectedIssueData.leakedValue !== undefined && (
                            <div>
                                <div className="g7dt-text-xs g7dt-text-muted">유출된 값</div>
                                <pre className="g7dt-code-block g7dt-text-red">
                                    {JSON.stringify(selectedIssueData.leakedValue, null, 2)}
                                </pre>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* 모달 관계 (항상 표시) */}
            {relations.length > 0 && (
                <div className="g7dt-card">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">모달 관계</h3>
                    <div className="g7dt-space-y-2">
                        {relations.map((rel, idx) => (
                            <div key={idx} className="g7dt-flex g7dt-items-center g7dt-gap-2 g7dt-text-xs">
                                <span className="g7dt-text-purple">{rel.parentModalId}</span>
                                <span className="g7dt-text-muted">→</span>
                                <span className="g7dt-text-blue">{rel.childModalId}</span>
                                <span className="g7dt-badge g7dt-text-xs g7dt-ml-auto">
                                    {rel.relationType}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
};

export default ModalScopeTab;
