/**
 * 라이프사이클 탭 컴포넌트
 *
 * 마운트된 컴포넌트와 정리되지 않은 리스너를 표시합니다.
 */

import React from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';

export interface LifecycleTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 라이프사이클 탭
 */
export const LifecycleTab: React.FC<LifecycleTabProps> = ({ devTools }) => {
    const lifecycleInfo = devTools.getLifecycleInfo();

    return (
        <div className="g7dt-space-y-4">
            {/* 마운트된 컴포넌트 */}
            <div className="g7dt-section">
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-text-green g7dt-mb-2 g7dt-flex-shrink-0">
                    마운트된 컴포넌트 ({lifecycleInfo.mountedComponents.length})
                </h3>
                <div className="g7dt-scroll-list">
                    {lifecycleInfo.mountedComponents.length === 0 ? (
                        <div className="g7dt-empty-message">추적된 컴포넌트 없음</div>
                    ) : (
                        lifecycleInfo.mountedComponents.map(comp => (
                            <div key={comp.id} className="g7dt-list-item g7dt-text-xs">
                                <span className="g7dt-text-lightgray">{comp.name}</span>
                                <span className="g7dt-text-gray">{comp.id}</span>
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* 정리되지 않은 리스너 */}
            {lifecycleInfo.orphanedListeners.length > 0 && (
                <div className="g7dt-section">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-text-red g7dt-mb-2 g7dt-flex-shrink-0">
                        정리되지 않은 리스너 ({lifecycleInfo.orphanedListeners.length})
                    </h3>
                    <div className="g7dt-scroll-list">
                        {lifecycleInfo.orphanedListeners.map((listener, i) => (
                            <div key={i} className="g7dt-warning-card g7dt-text-xs">
                                <span className="g7dt-text-lightgray">{listener.type}</span>
                                <span className="g7dt-text-gray g7dt-ml-2">on {listener.target}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
};

export default LifecycleTab;
