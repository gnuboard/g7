/**
 * 네트워크 탭 컴포넌트
 *
 * API 요청 이력과 대기 중인 데이터소스를 표시합니다.
 */

import React from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';

export interface NetworkTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 네트워크 탭
 */
export const NetworkTab: React.FC<NetworkTabProps> = ({ devTools }) => {
    const networkInfo = devTools.getNetworkInfo();

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'success':
                return 'g7dt-text-green';
            case 'error':
                return 'g7dt-text-red';
            case 'pending':
                return 'g7dt-text-yellow';
            default:
                return 'g7dt-text-gray';
        }
    };

    // 쿼리 파라미터 객체 여부 확인 및 표시
    const hasQueryParams = (req: any) => {
        return req.queryParams && Object.keys(req.queryParams).length > 0;
    };

    // 쿼리 파라미터 렌더링
    const renderQueryParams = (queryParams: Record<string, string | string[]>) => {
        return Object.entries(queryParams).map(([key, value]) => (
            <div key={key} className="g7dt-flex g7dt-gap-2 g7dt-text-xs">
                <span className="g7dt-text-purple">{key}:</span>
                <span className="g7dt-text-lightgray">
                    {Array.isArray(value) ? JSON.stringify(value) : value}
                </span>
            </div>
        ));
    };

    // JSON 안전하게 stringify
    const safeStringify = (obj: any, maxLength: number = 5000) => {
        try {
            const str = JSON.stringify(obj, null, 2);
            if (str.length > maxLength) {
                return str.substring(0, maxLength) + '\n... (truncated)';
            }
            return str;
        } catch {
            return '(직렬화 불가)';
        }
    };

    return (
        <div className="g7dt-space-y-4">
            {/* 진행 중인 요청 */}
            {networkInfo.activeRequests.length > 0 && (
                <div>
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-text-yellow g7dt-mb-2">
                        진행 중 ({networkInfo.activeRequests.length})
                    </h3>
                    <div className="g7dt-space-y-1">
                        {networkInfo.activeRequests.map(req => (
                            <details key={req.id} className="g7dt-details">
                                <summary className="g7dt-summary g7dt-pending-request">
                                    <span className="g7dt-font-bold">{req.method}</span>
                                    <span className="g7dt-ml-2 g7dt-text-lightgray g7dt-truncate">
                                        {req.fullUrl || req.url}
                                    </span>
                                </summary>
                                <div className="g7dt-card g7dt-mt-1 g7dt-text-xs">
                                    {/* 쿼리 파라미터 */}
                                    {hasQueryParams(req) && (
                                        <div className="g7dt-mb-2">
                                            <div className="g7dt-text-blue g7dt-font-bold g7dt-mb-1">쿼리 파라미터</div>
                                            <div className="g7dt-pl-2 g7dt-border-l-2 g7dt-border-blue">
                                                {renderQueryParams(req.queryParams)}
                                            </div>
                                        </div>
                                    )}
                                    {/* 요청 본문 */}
                                    {req.requestBody && (
                                        <div>
                                            <div className="g7dt-text-orange g7dt-font-bold g7dt-mb-1">요청 본문</div>
                                            <pre className="g7dt-pre g7dt-text-xs">
                                                {safeStringify(req.requestBody, 2000)}
                                            </pre>
                                        </div>
                                    )}
                                </div>
                            </details>
                        ))}
                    </div>
                </div>
            )}

            {/* 요청 이력 */}
            <div>
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">요청 이력</h3>
                <div className="g7dt-space-y-1 g7dt-scroll-list-lg">
                    {networkInfo.requestHistory.length === 0 ? (
                        <div className="g7dt-empty-message">요청 이력 없음</div>
                    ) : (
                        networkInfo.requestHistory.slice(-20).reverse().map(req => (
                            <details key={req.id} className="g7dt-details">
                                <summary className="g7dt-summary g7dt-card g7dt-text-xs">
                                    <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-w-full">
                                        <div>
                                            <span className="g7dt-font-bold">{req.method}</span>
                                            <span className={`g7dt-ml-2 ${getStatusColor(req.status)}`}>
                                                {req.statusCode || req.status}
                                            </span>
                                        </div>
                                        <span className="g7dt-text-gray">{req.duration}ms</span>
                                    </div>
                                    <div className="g7dt-text-gray g7dt-truncate g7dt-mt-1">
                                        {req.fullUrl || req.url}
                                    </div>
                                </summary>
                                <div className="g7dt-card g7dt-mt-1 g7dt-text-xs g7dt-ml-2 g7dt-border-l-2 g7dt-border-gray">
                                    {/* 기본 정보 */}
                                    <div className="g7dt-mb-2">
                                        <div className="g7dt-text-xs g7dt-space-y-1">
                                            <div><span className="g7dt-text-gray">URL:</span> <span className="g7dt-text-lightgray">{req.fullUrl || req.url}</span></div>
                                            <div><span className="g7dt-text-gray">상태:</span> <span className={getStatusColor(req.status)}>{req.statusCode || req.status}</span></div>
                                            <div><span className="g7dt-text-gray">소요시간:</span> <span className="g7dt-text-lightgray">{req.duration}ms</span></div>
                                            {req.dataSourceId && (
                                                <div><span className="g7dt-text-gray">데이터소스:</span> <span className="g7dt-text-blue">{req.dataSourceId}</span></div>
                                            )}
                                            {req.error && (
                                                <div><span className="g7dt-text-gray">에러:</span> <span className="g7dt-text-red">{req.error}</span></div>
                                            )}
                                        </div>
                                    </div>

                                    {/* 쿼리 파라미터 */}
                                    {hasQueryParams(req) && (
                                        <div className="g7dt-mb-2">
                                            <div className="g7dt-text-blue g7dt-font-bold g7dt-mb-1">쿼리 파라미터</div>
                                            <div className="g7dt-pl-2 g7dt-border-l-2 g7dt-border-blue">
                                                {renderQueryParams(req.queryParams)}
                                            </div>
                                        </div>
                                    )}

                                    {/* 요청 본문 */}
                                    {req.requestBody && (
                                        <div className="g7dt-mb-2">
                                            <div className="g7dt-text-orange g7dt-font-bold g7dt-mb-1">요청 본문</div>
                                            <pre className="g7dt-pre g7dt-text-xs g7dt-max-h-40 g7dt-overflow-auto">
                                                {safeStringify(req.requestBody, 2000)}
                                            </pre>
                                        </div>
                                    )}

                                    {/* 응답 내용 */}
                                    {req.response !== undefined && (
                                        <div>
                                            <div className="g7dt-text-green g7dt-font-bold g7dt-mb-1">응답 내용</div>
                                            <pre className="g7dt-pre g7dt-text-xs g7dt-max-h-60 g7dt-overflow-auto">
                                                {safeStringify(req.response, 5000)}
                                            </pre>
                                        </div>
                                    )}
                                </div>
                            </details>
                        ))
                    )}
                </div>
            </div>

            {/* 대기 중인 데이터소스 */}
            {networkInfo.pendingDataSources.length > 0 && (
                <div>
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-text-blue g7dt-mb-2">
                        대기 중인 데이터소스 ({networkInfo.pendingDataSources.length})
                    </h3>
                    <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-2">
                        {networkInfo.pendingDataSources.map(ds => (
                            <span key={ds} className="g7dt-tag-blue">
                                {ds}
                            </span>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
};

export default NetworkTab;
