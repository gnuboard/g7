/**
 * 표현식 탭 컴포넌트
 *
 * 현재 화면에서 평가된 모든 표현식을 추적하고 디버깅 정보를 표시합니다.
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { ExpressionEvalInfo, ExpressionStats } from '../../types';

export interface ExpressionTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 표현식 탭
 */
export const ExpressionTab: React.FC<ExpressionTabProps> = ({ devTools }) => {
    const [expressions, setExpressions] = useState<ExpressionEvalInfo[]>([]);
    const [stats, setStats] = useState<ExpressionStats>({
        totalEvaluations: 0,
        uniqueExpressions: 0,
        warningCount: 0,
        cacheHitRate: 0,
        averageDuration: 0,
        byType: {},
        byWarning: {},
    });
    const [filter, setFilter] = useState<string>('');
    const [showWarningsOnly, setShowWarningsOnly] = useState(false);
    const [selectedExpression, setSelectedExpression] = useState<ExpressionEvalInfo | null>(null);

    // 데이터 새로고침
    const refreshData = useCallback(() => {
        const info = devTools.getExpressionInfo();
        setExpressions(info.expressions);
        setStats(info.stats);
    }, [devTools]);

    // 초기 로드 및 구독
    useEffect(() => {
        refreshData();

        // 표현식 변경 구독
        const unsubscribe = devTools.watchExpressions(() => {
            refreshData();
        });

        // 주기적 갱신 (2초)
        const intervalId = setInterval(refreshData, 2000);

        return () => {
            unsubscribe();
            clearInterval(intervalId);
        };
    }, [devTools, refreshData]);

    // 필터링된 표현식
    const filteredExpressions = expressions.filter(expr => {
        if (showWarningsOnly && !expr.warning) return false;
        if (filter) {
            const searchLower = filter.toLowerCase();
            return (
                expr.expression.toLowerCase().includes(searchLower) ||
                expr.componentName?.toLowerCase().includes(searchLower) ||
                expr.propName?.toLowerCase().includes(searchLower) ||
                expr.resultType.toLowerCase().includes(searchLower)
            );
        }
        return true;
    });

    // 결과 타입에 따른 색상
    const getTypeColor = (type: string) => {
        switch (type) {
            case 'string':
                return 'g7dt-text-green';
            case 'number':
                return 'g7dt-text-blue';
            case 'boolean':
                return 'g7dt-text-purple';
            case 'array':
                return 'g7dt-text-cyan';
            case 'object':
                return 'g7dt-text-yellow';
            case 'undefined':
            case 'null':
                return 'g7dt-text-red';
            default:
                return 'g7dt-text-gray';
        }
    };

    // 경고 타입에 따른 메시지
    const getWarningLabel = (type: string) => {
        switch (type) {
            case 'undefined-result':
                return 'undefined 결과';
            case 'null-result':
                return 'null 결과';
            case 'array-to-string':
                return '배열→문자열';
            case 'object-to-string':
                return '객체→문자열';
            case 'missing-optional-chain':
                return '?. 누락 의심';
            case 'wrong-iteration-var':
                return '잘못된 반복 변수';
            case 'type-mismatch':
                return '타입 불일치';
            case 'cache-stale':
                return '캐시 오래됨';
            case 'slow-evaluation':
                return '느린 평가';
            default:
                return type;
        }
    };

    // 결과값 포맷팅
    const formatResult = (result: any, type: string): string => {
        if (type === 'undefined') return 'undefined';
        if (type === 'null') return 'null';
        if (type === 'string') return `"${String(result).substring(0, 50)}${String(result).length > 50 ? '...' : ''}"`;
        if (type === 'array') return `Array(${Array.isArray(result) ? result.length : '?'})`;
        if (type === 'object') return 'Object{...}';
        return String(result);
    };

    // 표현식 초기화
    const handleClear = () => {
        devTools.clearExpressions();
        setExpressions([]);
        setSelectedExpression(null);
    };

    return (
        <div className="g7dt-space-y-4">
            {/* 통계 요약 */}
            <div className="g7dt-stats-grid-5">
                <div className="g7dt-stat-card-sm">
                    <div className="g7dt-stat-value-lg g7dt-text-blue">{stats.totalEvaluations}</div>
                    <div className="g7dt-stat-label-xs">총 평가</div>
                </div>
                <div className="g7dt-stat-card-sm">
                    <div className="g7dt-stat-value-lg g7dt-text-green">{stats.uniqueExpressions}</div>
                    <div className="g7dt-stat-label-xs">고유 표현식</div>
                </div>
                <div className="g7dt-stat-card-sm">
                    <div className="g7dt-stat-value-lg g7dt-text-yellow">{stats.warningCount}</div>
                    <div className="g7dt-stat-label-xs">경고</div>
                </div>
                <div className="g7dt-stat-card-sm">
                    <div className="g7dt-stat-value-lg g7dt-text-purple">{(stats.cacheHitRate * 100).toFixed(0)}%</div>
                    <div className="g7dt-stat-label-xs">캐시 히트</div>
                </div>
                <div className="g7dt-stat-card-sm">
                    <div className="g7dt-stat-value-lg g7dt-text-cyan">{stats.averageDuration.toFixed(1)}ms</div>
                    <div className="g7dt-stat-label-xs">평균 시간</div>
                </div>
            </div>

            {/* 필터 및 컨트롤 */}
            <div className="g7dt-flex g7dt-gap-2 g7dt-items-center">
                <input
                    type="text"
                    placeholder="표현식, 컴포넌트, prop 검색..."
                    value={filter}
                    onChange={e => setFilter(e.target.value)}
                    className="g7dt-input g7dt-flex-1"
                />
                <label className="g7dt-checkbox-label">
                    <input
                        type="checkbox"
                        checked={showWarningsOnly}
                        onChange={e => setShowWarningsOnly(e.target.checked)}
                        className="g7dt-checkbox"
                    />
                    경고만
                </label>
                <button
                    onClick={handleClear}
                    className="g7dt-btn-danger-sm"
                >
                    초기화
                </button>
            </div>

            {/* 타입별 분포 */}
            {Object.keys(stats.byType).length > 0 && (
                <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-1">
                    {Object.entries(stats.byType).map(([type, count]) => (
                        <span
                            key={type}
                            className={`g7dt-type-tag ${getTypeColor(type)}`}
                        >
                            {type}: {count}
                        </span>
                    ))}
                </div>
            )}

            {/* 표현식 목록 */}
            <div className="g7dt-space-y-1 g7dt-expr-list">
                {filteredExpressions.length === 0 ? (
                    <div className="g7dt-empty-message g7dt-py-4">
                        {filter || showWarningsOnly ? '조건에 맞는 표현식이 없습니다' : '추적된 표현식이 없습니다'}
                    </div>
                ) : (
                    filteredExpressions.slice(-50).reverse().map(expr => (
                        <div
                            key={expr.id}
                            onClick={() => setSelectedExpression(expr)}
                            className={`g7dt-expr-item ${
                                selectedExpression?.id === expr.id
                                    ? 'g7dt-expr-selected'
                                    : expr.warning
                                    ? 'g7dt-expr-warning'
                                    : ''
                            }`}
                        >
                            <div className="g7dt-flex g7dt-justify-between g7dt-items-start g7dt-gap-2">
                                <div className="g7dt-flex-1 g7dt-min-w-0">
                                    <div className="g7dt-font-mono g7dt-text-white g7dt-truncate" title={expr.expression}>
                                        {expr.expression}
                                    </div>
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2 g7dt-mt-1 g7dt-text-xxs">
                                        {expr.componentName && (
                                            <span className="g7dt-text-gray">
                                                {expr.componentName}
                                                {expr.propName && `.${expr.propName}`}
                                            </span>
                                        )}
                                        <span className={getTypeColor(expr.resultType)}>
                                            {formatResult(expr.result, expr.resultType)}
                                        </span>
                                    </div>
                                </div>
                                <div className="g7dt-flex g7dt-flex-col g7dt-items-end g7dt-gap-1">
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-1">
                                        {expr.fromCache && (
                                            <span className="g7dt-mini-badge g7dt-badge-success">
                                                캐시
                                            </span>
                                        )}
                                        {expr.warning && (
                                            <span className="g7dt-mini-badge g7dt-badge-warning">
                                                {getWarningLabel(expr.warning.type)}
                                            </span>
                                        )}
                                    </div>
                                    {expr.duration !== undefined && (
                                        <span className="g7dt-text-xxs g7dt-text-gray">
                                            {expr.duration.toFixed(2)}ms
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>
                    ))
                )}
            </div>

            {/* 선택된 표현식 상세 정보 */}
            {selectedExpression && (
                <div className="g7dt-card">
                    <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-mb-2">
                        <h3 className="g7dt-text-sm g7dt-font-bold">표현식 상세</h3>
                        <button
                            onClick={() => setSelectedExpression(null)}
                            className="g7dt-btn-close"
                        >
                            ✕
                        </button>
                    </div>

                    <div className="g7dt-space-y-2 g7dt-text-xs">
                        {/* 표현식 */}
                        <div>
                            <span className="g7dt-text-gray">표현식:</span>
                            <code className="g7dt-code g7dt-text-green">
                                {selectedExpression.expression}
                            </code>
                        </div>

                        {/* 결과 */}
                        <div>
                            <span className="g7dt-text-gray">결과:</span>
                            <span className={`g7dt-ml-2 ${getTypeColor(selectedExpression.resultType)}`}>
                                {JSON.stringify(selectedExpression.result, null, 2)?.substring(0, 200)}
                            </span>
                            <span className="g7dt-ml-2 g7dt-text-darkgray">({selectedExpression.resultType})</span>
                        </div>

                        {/* 컴포넌트 정보 */}
                        {selectedExpression.componentName && (
                            <div>
                                <span className="g7dt-text-gray">컴포넌트:</span>
                                <span className="g7dt-ml-2 g7dt-text-blue">{selectedExpression.componentName}</span>
                                {selectedExpression.propName && (
                                    <span className="g7dt-text-gray">.{selectedExpression.propName}</span>
                                )}
                            </div>
                        )}

                        {/* 메서드 */}
                        {selectedExpression.method && (
                            <div>
                                <span className="g7dt-text-gray">메서드:</span>
                                <span className="g7dt-ml-2 g7dt-text-purple">{selectedExpression.method}</span>
                            </div>
                        )}

                        {/* 캐시 상태 */}
                        <div>
                            <span className="g7dt-text-gray">캐시:</span>
                            <span className={`g7dt-ml-2 ${selectedExpression.fromCache ? 'g7dt-text-green' : 'g7dt-text-gray'}`}>
                                {selectedExpression.fromCache ? '캐시에서 로드' : '새로 평가'}
                                {selectedExpression.skipCache && ' (skipCache)'}
                            </span>
                        </div>

                        {/* 소요 시간 */}
                        {selectedExpression.duration !== undefined && (
                            <div>
                                <span className="g7dt-text-gray">소요 시간:</span>
                                <span className={`g7dt-ml-2 ${selectedExpression.duration > 10 ? 'g7dt-text-red' : 'g7dt-text-lightgray'}`}>
                                    {selectedExpression.duration.toFixed(3)}ms
                                </span>
                            </div>
                        )}

                        {/* 경고 */}
                        {selectedExpression.warning && (
                            <div className="g7dt-warning-box-light">
                                <div className="g7dt-text-yellow g7dt-font-bold">
                                    ⚠️ {getWarningLabel(selectedExpression.warning.type)}
                                </div>
                                <div className="g7dt-text-lightgray g7dt-mt-1">{selectedExpression.warning.message}</div>
                                {selectedExpression.warning.suggestion && (
                                    <div className="g7dt-text-gray g7dt-mt-1 g7dt-text-xxs">
                                        💡 {selectedExpression.warning.suggestion}
                                    </div>
                                )}
                            </div>
                        )}

                        {/* 평가 단계 */}
                        {selectedExpression.steps && selectedExpression.steps.length > 0 && (
                            <details className="g7dt-details">
                                <summary className="g7dt-summary">
                                    평가 단계 ({selectedExpression.steps.length})
                                </summary>
                                <div className="g7dt-mt-1 g7dt-space-y-1 g7dt-pl-2 g7dt-border-l">
                                    {selectedExpression.steps.map((step, i) => (
                                        <div key={i} className="g7dt-text-xxs">
                                            <span className="g7dt-text-purple">{step.step}</span>
                                            {step.path && <span className="g7dt-text-gray g7dt-ml-1">{step.path}</span>}
                                            {step.fromCache && <span className="g7dt-text-green g7dt-ml-1">(cached)</span>}
                                            {step.error && <span className="g7dt-text-red g7dt-ml-1">{step.error}</span>}
                                        </div>
                                    ))}
                                </div>
                            </details>
                        )}
                    </div>
                </div>
            )}

            {/* 경고 요약 */}
            {Object.keys(stats.byWarning).length > 0 && (
                <div className="g7dt-warning-box-light">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-text-yellow g7dt-mb-2">경고 요약</h3>
                    <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-2">
                        {Object.entries(stats.byWarning).map(([type, count]) => (
                            <span
                                key={type}
                                className="g7dt-warning-tag"
                            >
                                {getWarningLabel(type)}: {count}
                            </span>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
};

export default ExpressionTab;
