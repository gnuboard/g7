/**
 * 데이터소스 탭 컴포넌트
 *
 * 데이터소스 정의 및 상태를 표시합니다.
 * Path Transform 추적 기능 포함 (Phase 4).
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { DataPathTransformTrackingInfo, DataPathTransformStep } from '../../types';

export interface DataSourcesTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 데이터소스 탭
 */
export const DataSourcesTab: React.FC<DataSourcesTabProps> = ({ devTools }) => {
    const dataSources = devTools.getDataSources();
    const [statusFilter, setStatusFilter] = useState<string>('all');
    const [selectedDs, setSelectedDs] = useState<string | null>(null);
    const [showPathTransform, setShowPathTransform] = useState(false);
    const [transformInfo, setTransformInfo] = useState<DataPathTransformTrackingInfo | null>(null);

    // Path Transform 데이터 새로고침
    const refreshTransformData = useCallback(() => {
        setTransformInfo(devTools.getDataPathTransformTrackingInfo());
    }, [devTools]);

    // 주기적 갱신
    useEffect(() => {
        if (showPathTransform) {
            refreshTransformData();
            const intervalId = setInterval(refreshTransformData, 2000);
            return () => clearInterval(intervalId);
        }
    }, [showPathTransform, refreshTransformData]);

    // 변환 단계 아이콘
    const getStepIcon = (step: DataPathTransformStep) => {
        switch (step) {
            case 'api_response': return '📥';
            case 'extract_data': return '📄';
            case 'apply_path': return '🔗';
            case 'init_global': return '🌐';
            case 'init_local': return '📌';
            default: return '❓';
        }
    };

    // 상태별 필터링
    const filteredDataSources = dataSources.filter(ds => {
        if (statusFilter === 'all') return true;
        return ds.status === statusFilter;
    });

    // 상태별 카운트
    const statusCounts = {
        all: dataSources.length,
        idle: dataSources.filter(ds => ds.status === 'idle').length,
        loading: dataSources.filter(ds => ds.status === 'loading').length,
        loaded: dataSources.filter(ds => ds.status === 'loaded').length,
        error: dataSources.filter(ds => ds.status === 'error').length,
    };

    // 상태에 따른 색상
    const getStatusColor = (status: string) => {
        switch (status) {
            case 'loaded':
                return 'g7dt-badge-success';
            case 'loading':
                return 'g7dt-badge-warning';
            case 'error':
                return 'g7dt-badge-error';
            default:
                return 'g7dt-badge-gray';
        }
    };

    // 선택된 데이터소스
    const selectedDataSource = selectedDs
        ? dataSources.find(ds => ds.id === selectedDs)
        : null;

    return (
        <div className="g7dt-space-y-4">
            {/* 상태 필터 */}
            <div className="g7dt-flex g7dt-gap-2">
                {(['all', 'idle', 'loading', 'loaded', 'error'] as const).map(status => (
                    <button
                        key={status}
                        onClick={() => setStatusFilter(status)}
                        className={statusFilter === status ? 'g7dt-filter-btn g7dt-filter-active' : 'g7dt-filter-btn'}
                    >
                        {status === 'all' ? '전체' : status} ({statusCounts[status]})
                    </button>
                ))}
            </div>

            {/* 데이터소스 목록 */}
            <div className="g7dt-ds-grid">
                {filteredDataSources.length === 0 ? (
                    <div className="g7dt-empty-message g7dt-col-span-2 g7dt-py-4">
                        데이터소스가 없습니다
                    </div>
                ) : (
                    filteredDataSources.map(ds => (
                        <div
                            key={ds.id}
                            onClick={() => setSelectedDs(ds.id)}
                            className={`g7dt-ds-item ${selectedDs === ds.id ? 'g7dt-ds-selected' : ''}`}
                        >
                            <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                                <span className="g7dt-font-mono g7dt-text-blue g7dt-truncate">{ds.id}</span>
                                <span className={`g7dt-mini-badge ${getStatusColor(ds.status)}`}>
                                    {ds.status}
                                </span>
                            </div>
                            <div className="g7dt-text-gray g7dt-mt-1 g7dt-truncate">
                                {ds.endpoint || '(endpoint 없음)'}
                            </div>
                            {ds.itemCount !== undefined && (
                                <div className="g7dt-text-lightgray g7dt-mt-1">
                                    {ds.itemCount}개 항목
                                </div>
                            )}
                        </div>
                    ))
                )}
            </div>

            {/* 선택된 데이터소스 상세 */}
            {selectedDataSource && (
                <div className="g7dt-card">
                    <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-mb-2">
                        <h3 className="g7dt-text-sm g7dt-font-bold">{selectedDataSource.id}</h3>
                        <button
                            onClick={() => setSelectedDs(null)}
                            className="g7dt-btn-close"
                        >
                            ✕
                        </button>
                    </div>

                    <div className="g7dt-space-y-2 g7dt-text-xs">
                        {/* 엔드포인트 */}
                        <div>
                            <span className="g7dt-text-gray">엔드포인트:</span>
                            <span className="g7dt-ml-2 g7dt-text-green">{selectedDataSource.endpoint || '-'}</span>
                        </div>

                        {/* 데이터 경로 */}
                        {selectedDataSource.dataPath && (
                            <div>
                                <span className="g7dt-text-gray">데이터 경로:</span>
                                <span className="g7dt-ml-2 g7dt-text-yellow">{selectedDataSource.dataPath}</span>
                            </div>
                        )}

                        {/* 상태 */}
                        <div>
                            <span className="g7dt-text-gray">상태:</span>
                            <span className={`g7dt-ml-2 g7dt-mini-badge ${getStatusColor(selectedDataSource.status)}`}>
                                {selectedDataSource.status}
                            </span>
                        </div>

                        {/* 항목 수 */}
                        {selectedDataSource.itemCount !== undefined && (
                            <div>
                                <span className="g7dt-text-gray">항목 수:</span>
                                <span className="g7dt-ml-2 g7dt-text-blue">{selectedDataSource.itemCount}</span>
                            </div>
                        )}

                        {/* 키 목록 */}
                        {selectedDataSource.keys && selectedDataSource.keys.length > 0 && (
                            <div>
                                <span className="g7dt-text-gray">키:</span>
                                <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-1 g7dt-mt-1">
                                    {selectedDataSource.keys.slice(0, 10).map((key: string) => (
                                        <span key={key} className="g7dt-key-tag">
                                            {key}
                                        </span>
                                    ))}
                                    {selectedDataSource.keys.length > 10 && (
                                        <span className="g7dt-text-gray">+{selectedDataSource.keys.length - 10}개</span>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* 의존성 */}
                        {selectedDataSource.dependencies && selectedDataSource.dependencies.length > 0 && (
                            <div>
                                <span className="g7dt-text-gray">의존성:</span>
                                <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-1 g7dt-mt-1">
                                    {selectedDataSource.dependencies.map((dep: string) => (
                                        <span key={dep} className="g7dt-dep-tag">
                                            {dep}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* 통계 및 버튼 */}
            <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                <div className="g7dt-text-xs g7dt-text-gray">
                    총 {dataSources.length}개 데이터소스 |
                    로드됨 {statusCounts.loaded} |
                    로딩중 {statusCounts.loading} |
                    에러 {statusCounts.error}
                </div>
                <button
                    onClick={() => setShowPathTransform(!showPathTransform)}
                    className={`g7dt-tab-button ${showPathTransform ? 'g7dt-tab-active' : ''}`}
                >
                    🔀 Path Transform
                </button>
            </div>

            {/* Path Transform 섹션 */}
            {showPathTransform && transformInfo && (
                <div className="g7dt-card">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-3">
                        데이터 경로 변환 추적 ({transformInfo.transforms.length}개)
                    </h3>

                    {/* 통계 */}
                    <div className="g7dt-stats-grid-3 g7dt-mb-3">
                        <div className="g7dt-text-center">
                            <div className="g7dt-text-xl g7dt-font-bold g7dt-text-blue">
                                {transformInfo.stats.totalTransforms}
                            </div>
                            <div className="g7dt-text-xs g7dt-text-muted">총 변환</div>
                        </div>
                        <div className="g7dt-text-center">
                            <div className="g7dt-text-xl g7dt-font-bold g7dt-text-purple">
                                {Object.keys(transformInfo.stats.byDataSource).length}
                            </div>
                            <div className="g7dt-text-xs g7dt-text-muted">데이터소스</div>
                        </div>
                        <div className="g7dt-text-center">
                            <div className="g7dt-text-xl g7dt-font-bold g7dt-text-yellow">
                                {transformInfo.stats.warningsCount}
                            </div>
                            <div className="g7dt-text-xs g7dt-text-muted">경고</div>
                        </div>
                    </div>

                    {/* 변환 목록 */}
                    {transformInfo.transforms.length === 0 ? (
                        <div className="g7dt-empty-state">변환 기록이 없습니다</div>
                    ) : (
                        <div className="g7dt-space-y-2 g7dt-max-h-60 g7dt-overflow-y-auto">
                            {transformInfo.transforms.slice().reverse().slice(0, 20).map((transform, idx) => (
                                <div
                                    key={`${transform.dataSourceId}-${idx}`}
                                    className={`g7dt-list-item ${transform.warnings.length > 0 ? 'g7dt-border-yellow' : ''}`}
                                >
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        <span className="g7dt-text-xs g7dt-text-blue g7dt-font-mono">
                                            {transform.dataSourceId}
                                        </span>
                                        {transform.warnings.length > 0 && (
                                            <span className="g7dt-badge g7dt-text-xs g7dt-text-yellow">
                                                ⚠️ {transform.warnings.length}
                                            </span>
                                        )}
                                    </div>
                                    <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-1 g7dt-mt-1">
                                        {transform.transformSteps.map((step, stepIdx) => (
                                            <span
                                                key={stepIdx}
                                                className="g7dt-badge g7dt-text-xs"
                                                title={`${step.inputPath} → ${step.outputPath}`}
                                            >
                                                {getStepIcon(step.step)} {step.step}
                                            </span>
                                        ))}
                                    </div>
                                    {transform.finalBinding && (
                                        <div className="g7dt-text-xs g7dt-text-muted g7dt-mt-1">
                                            최종: <span className="g7dt-text-green">{transform.finalBinding.expression}</span>
                                            → <span className="g7dt-text-blue">{transform.finalBinding.resolvedPath}</span>
                                        </div>
                                    )}
                                    {transform.warnings.length > 0 && (
                                        <div className="g7dt-text-xs g7dt-text-yellow g7dt-mt-1">
                                            {transform.warnings[0]}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}

                    {/* 일반적인 경고 */}
                    {transformInfo.stats.commonWarnings.length > 0 && (
                        <div className="g7dt-mt-3">
                            <div className="g7dt-text-xs g7dt-text-muted g7dt-mb-1">자주 발생하는 경고</div>
                            <div className="g7dt-space-y-1">
                                {transformInfo.stats.commonWarnings.slice(0, 5).map((w, idx) => (
                                    <div key={idx} className="g7dt-text-xs g7dt-text-yellow">
                                        {w.warning} ({w.count}회)
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

export default DataSourcesTab;
