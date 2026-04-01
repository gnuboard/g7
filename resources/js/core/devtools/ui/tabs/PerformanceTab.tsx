/**
 * 성능 탭 컴포넌트
 *
 * 렌더링 성능 정보와 메모리 경고를 표시합니다.
 */

import React from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';

export interface PerformanceTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 성능 탭
 */
export const PerformanceTab: React.FC<PerformanceTabProps> = ({ devTools }) => {
    const perfInfo = devTools.getPerformanceInfo();

    return (
        <div className="g7dt-space-y-4">
            {/* 통계 */}
            <div className="g7dt-stats-grid-2">
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value g7dt-text-blue">
                        {perfInfo.bindingEvalCount}
                    </div>
                    <div className="g7dt-stat-label">바인딩 평가 횟수</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value g7dt-text-purple">
                        {perfInfo.renderCounts.size}
                    </div>
                    <div className="g7dt-stat-label">렌더링된 컴포넌트</div>
                </div>
            </div>

            {/* 메모리 경고 */}
            {perfInfo.memoryWarnings.length > 0 && (
                <div>
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-text-red g7dt-mb-2">메모리 경고</h3>
                    <div className="g7dt-space-y-2">
                        {perfInfo.memoryWarnings.map((warning, i) => (
                            <div key={i} className="g7dt-warning-card">
                                <div className="g7dt-font-bold">{warning.type}</div>
                                <div className="g7dt-text-lightgray">{warning.message}</div>
                                <div className="g7dt-text-xs g7dt-text-gray g7dt-mt-1">{warning.suggestion}</div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* 렌더링 횟수 */}
            {perfInfo.renderCounts.size > 0 && (
                <div>
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">컴포넌트별 렌더링</h3>
                    <div className="g7dt-space-y-1 g7dt-scroll-list">
                        {Array.from(perfInfo.renderCounts.entries())
                            .sort((a, b) => b[1] - a[1])
                            .slice(0, 10)
                            .map(([name, count]) => (
                                <div
                                    key={name}
                                    className="g7dt-list-item"
                                >
                                    <span className="g7dt-text-lightgray g7dt-truncate">{name}</span>
                                    <span className={count > 50 ? 'g7dt-text-red' : 'g7dt-text-gray'}>
                                        {count}회
                                    </span>
                                </div>
                            ))}
                    </div>
                </div>
            )}
        </div>
    );
};

export default PerformanceTab;
