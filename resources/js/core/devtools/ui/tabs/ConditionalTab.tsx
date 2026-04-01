/**
 * 조건부 렌더링 탭 컴포넌트
 *
 * if 조건과 iteration 정보를 표시합니다.
 */

import React from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { ConditionInfo, IterationInfo } from '../../types';

export interface ConditionalTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 조건부 렌더링 탭
 */
export const ConditionalTab: React.FC<ConditionalTabProps> = ({ devTools }) => {
    const conditionalInfo = devTools.getConditionalInfo();

    return (
        <div className="g7dt-space-y-4">
            {/* if 조건들 */}
            <div>
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-text-purple g7dt-mb-2">
                    if 조건 ({conditionalInfo.ifConditions.length})
                </h3>
                <div className="g7dt-space-y-1 g7dt-scroll-list">
                    {conditionalInfo.ifConditions.length === 0 ? (
                        <div className="g7dt-empty-message">추적된 if 조건 없음</div>
                    ) : (
                        conditionalInfo.ifConditions.map((cond: ConditionInfo) => (
                            <div
                                key={cond.id}
                                className={`g7dt-condition-item ${cond.evaluatedValue ? 'g7dt-condition-true' : 'g7dt-condition-false'}`}
                            >
                                <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                                    <span className="g7dt-font-mono g7dt-text-lightgray g7dt-truncate-60">
                                        {cond.expression}
                                    </span>
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        <span className={cond.evaluatedValue ? 'g7dt-badge-success' : 'g7dt-badge-error'}>
                                            {cond.evaluatedValue ? 'true' : 'false'}
                                        </span>
                                        <span className="g7dt-text-gray">
                                            {cond.evaluationCount}회
                                        </span>
                                    </div>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* iteration들 */}
            <div>
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-text-cyan g7dt-mb-2">
                    iteration ({conditionalInfo.iterations.length})
                </h3>
                <div className="g7dt-space-y-1 g7dt-scroll-list">
                    {conditionalInfo.iterations.length === 0 ? (
                        <div className="g7dt-empty-message">추적된 iteration 없음</div>
                    ) : (
                        conditionalInfo.iterations.map((iter: IterationInfo) => (
                            <div
                                key={iter.id}
                                className="g7dt-card g7dt-text-xs"
                            >
                                <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-mb-1">
                                    <span className="g7dt-font-mono g7dt-text-lightgray g7dt-truncate-60">
                                        {iter.source}
                                    </span>
                                    <span className={iter.sourceLength > 0 ? 'g7dt-badge-blue' : 'g7dt-badge-warning'}>
                                        {iter.sourceLength}개 항목
                                    </span>
                                </div>
                                <div className="g7dt-text-gray">
                                    <span className="g7dt-mr-2">item_var: {iter.itemVar}</span>
                                    {iter.indexVar && <span>index_var: {iter.indexVar}</span>}
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* 문제 감지 */}
            {(conditionalInfo.ifConditions.some((c: ConditionInfo) => c.evaluatedValue === false && c.evaluationCount > 5) ||
              conditionalInfo.iterations.some((i: IterationInfo) => i.sourceLength === 0)) && (
                <div className="g7dt-warning-box">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-text-yellow g7dt-mb-2">잠재적 문제</h3>
                    <div className="g7dt-space-y-1 g7dt-text-xs">
                        {conditionalInfo.ifConditions
                            .filter((c: ConditionInfo) => c.evaluatedValue === false && c.evaluationCount > 5)
                            .map((c: ConditionInfo) => (
                                <div key={c.id} className="g7dt-text-lightgray">
                                    ⚠️ if 조건 항상 false: {c.expression}
                                </div>
                            ))}
                        {conditionalInfo.iterations
                            .filter((i: IterationInfo) => i.sourceLength === 0)
                            .map((i: IterationInfo) => (
                                <div key={i.id} className="g7dt-text-lightgray">
                                    ⚠️ iteration 소스 비어있음: {i.source}
                                </div>
                            ))}
                    </div>
                </div>
            )}
        </div>
    );
};

export default ConditionalTab;
