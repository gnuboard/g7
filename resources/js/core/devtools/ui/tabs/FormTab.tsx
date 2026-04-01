/**
 * Form 탭 컴포넌트
 *
 * 추적된 Form 정보와 Input 바인딩을 표시합니다.
 * Auto-Binding Check 기능 포함 (Phase 6).
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { FormBindingValidationTrackingInfo, FormBindingIssueType } from '../../types';

export interface FormTabProps {
    devTools: G7DevToolsCore;
}

/**
 * Form 탭
 */
export const FormTab: React.FC<FormTabProps> = ({ devTools }) => {
    const formInfo = devTools.getFormInfo();
    const [showBindingCheck, setShowBindingCheck] = useState(false);
    const [validationInfo, setValidationInfo] = useState<FormBindingValidationTrackingInfo | null>(null);

    // 바인딩 검증 데이터 새로고침
    const refreshValidationData = useCallback(() => {
        setValidationInfo(devTools.getFormBindingValidationTrackingInfo());
    }, [devTools]);

    // 주기적 갱신
    useEffect(() => {
        if (showBindingCheck) {
            refreshValidationData();
            const intervalId = setInterval(refreshValidationData, 2000);
            return () => clearInterval(intervalId);
        }
    }, [showBindingCheck, refreshValidationData]);

    // 이슈 타입 아이콘
    const getIssueIcon = (type: FormBindingIssueType) => {
        switch (type) {
            case 'missing-datakey': return '🔑';
            case 'missing-input-name': return '📝';
            case 'context-not-propagated': return '🔗';
            case 'sortable-context-break': return '📊';
            case 'modal-context-isolation': return '🪟';
            case 'duplicate-input-name': return '👯';
            case 'value-type-mismatch': return '⚠️';
            case 'binding-path-invalid': return '❌';
            default: return '❓';
        }
    };

    // 심각도 색상
    const getSeverityColor = (severity: string) => {
        switch (severity) {
            case 'error': return 'g7dt-text-red';
            case 'warning': return 'g7dt-text-yellow';
            case 'info': return 'g7dt-text-blue';
            default: return 'g7dt-text-gray';
        }
    };

    return (
        <div className="g7dt-space-y-4">
            {formInfo.length === 0 ? (
                <div className="g7dt-empty-message g7dt-py-8">
                    추적된 Form이 없습니다
                </div>
            ) : (
                formInfo.map(form => (
                    <div key={form.id} className="g7dt-card">
                        <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-mb-2">
                            <span className="g7dt-font-bold g7dt-text-blue">
                                {form.dataKey || '(no dataKey)'}
                            </span>
                            {!form.dataKey && (
                                <span className="g7dt-text-red g7dt-text-xs">dataKey 필요</span>
                            )}
                        </div>
                        <div className="g7dt-space-y-1 g7dt-mb-2">
                            {form.inputs.map((input, i) => (
                                <div key={i} className="g7dt-text-xs g7dt-flex g7dt-justify-between">
                                    <span>
                                        {input.name || (
                                            <span className="g7dt-text-red">name 없음</span>
                                        )}
                                    </span>
                                    <span className="g7dt-text-gray">{input.type}</span>
                                </div>
                            ))}
                        </div>
                        <details className="g7dt-details">
                            <summary className="g7dt-summary">
                                현재 값 보기
                            </summary>
                            <pre className="g7dt-pre">
                                {JSON.stringify(form, null, 2)}
                            </pre>
                        </details>
                    </div>
                ))
            )}

            {/* Auto-Binding Check 토글 */}
            <div className="g7dt-flex g7dt-justify-end">
                <button
                    onClick={() => setShowBindingCheck(!showBindingCheck)}
                    className={`g7dt-tab-button ${showBindingCheck ? 'g7dt-tab-active' : ''}`}
                >
                    🔍 Auto-Binding Check
                </button>
            </div>

            {/* Auto-Binding Check 섹션 */}
            {showBindingCheck && validationInfo && (
                <div className="g7dt-card">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">
                        Form 바인딩 검증 결과
                    </h3>

                    {/* 통계 */}
                    <div className="g7dt-stats-grid g7dt-mb-3">
                        <div className="g7dt-stat-card">
                            <div className="g7dt-stat-value g7dt-text-blue">
                                {validationInfo.stats.totalFormsValidated}
                            </div>
                            <div className="g7dt-stat-label">검증된 Form</div>
                        </div>
                        <div className="g7dt-stat-card">
                            <div className="g7dt-stat-value g7dt-text-green">
                                {validationInfo.stats.validForms}
                            </div>
                            <div className="g7dt-stat-label">유효</div>
                        </div>
                        <div className="g7dt-stat-card">
                            <div className="g7dt-stat-value g7dt-text-red">
                                {validationInfo.stats.formsWithIssues}
                            </div>
                            <div className="g7dt-stat-label">문제 있음</div>
                        </div>
                        <div className="g7dt-stat-card">
                            <div className="g7dt-stat-value g7dt-text-yellow">
                                {validationInfo.stats.totalIssues}
                            </div>
                            <div className="g7dt-stat-label">총 이슈</div>
                        </div>
                    </div>

                    {/* 자주 발생하는 이슈 */}
                    {validationInfo.stats.topIssues.length > 0 && (
                        <div className="g7dt-mb-3">
                            <div className="g7dt-text-xs g7dt-text-muted g7dt-mb-2">자주 발생하는 이슈</div>
                            <div className="g7dt-space-y-1">
                                {validationInfo.stats.topIssues.slice(0, 3).map((issue, idx) => (
                                    <div key={idx} className="g7dt-flex g7dt-items-center g7dt-gap-2 g7dt-text-xs">
                                        <span>{getIssueIcon(issue.type)}</span>
                                        <span className="g7dt-text-white">{issue.description}</span>
                                        <span className="g7dt-text-muted">({issue.count})</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* 이슈 목록 */}
                    {validationInfo.issues.length === 0 ? (
                        <div className="g7dt-empty-state">바인딩 이슈가 없습니다 ✅</div>
                    ) : (
                        <div className="g7dt-space-y-2 g7dt-max-h-60 g7dt-overflow-y-auto">
                            {validationInfo.issues.slice().reverse().slice(0, 30).map((issue) => (
                                <div
                                    key={issue.id}
                                    className={`g7dt-list-item ${
                                        issue.severity === 'error' ? 'g7dt-border-red' :
                                        issue.severity === 'warning' ? 'g7dt-border-yellow' : ''
                                    }`}
                                >
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        <span>{getIssueIcon(issue.type)}</span>
                                        <span className={`g7dt-text-xs g7dt-font-bold ${getSeverityColor(issue.severity)}`}>
                                            {issue.type}
                                        </span>
                                        {issue.formDataKey && (
                                            <span className="g7dt-text-xs g7dt-text-blue g7dt-font-mono">
                                                {issue.formDataKey}
                                            </span>
                                        )}
                                    </div>
                                    <div className="g7dt-text-xs g7dt-text-white g7dt-mt-1">
                                        {issue.description}
                                    </div>
                                    <div className="g7dt-text-xs g7dt-text-green g7dt-mt-1">
                                        💡 {issue.suggestion}
                                    </div>
                                    {issue.contextInfo && (
                                        <div className="g7dt-flex g7dt-gap-2 g7dt-mt-1">
                                            {issue.contextInfo.isInsideModal && (
                                                <span className="g7dt-badge g7dt-text-xs">🪟 modal</span>
                                            )}
                                            {issue.contextInfo.isInsideSortable && (
                                                <span className="g7dt-badge g7dt-text-xs">📊 sortable</span>
                                            )}
                                            <span className="g7dt-text-xs g7dt-text-muted">
                                                depth: {issue.contextInfo.depth}
                                            </span>
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

export default FormTab;
