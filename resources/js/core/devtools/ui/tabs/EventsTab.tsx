/**
 * 이벤트 탭 컴포넌트
 *
 * 컴포넌트 이벤트 구독/발생을 추적합니다.
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';

export interface EventsTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 이벤트 탭
 */
export const EventsTab: React.FC<EventsTabProps> = ({ devTools }) => {
    const [eventInfo, setEventInfo] = useState(devTools.getComponentEventInfo());
    const [selectedEvent, setSelectedEvent] = useState<string | null>(null);

    // 데이터 새로고침
    const refreshData = useCallback(() => {
        setEventInfo(devTools.getComponentEventInfo());
    }, [devTools]);

    // 주기적 갱신
    useEffect(() => {
        refreshData();
        const intervalId = setInterval(refreshData, 2000);
        return () => clearInterval(intervalId);
    }, [refreshData]);

    // 선택된 이벤트의 emit 이력 필터링
    const filteredEmitHistory = selectedEvent
        ? eventInfo.emitHistory.filter(e => e.eventName === selectedEvent)
        : eventInfo.emitHistory;

    return (
        <div className="g7dt-space-y-4">
            {/* 통계 */}
            <div className="g7dt-stats-grid-3">
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value-lg g7dt-text-blue">
                        {eventInfo.subscriptions.length}
                    </div>
                    <div className="g7dt-stat-label">이벤트 타입</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value-lg g7dt-text-green">
                        {eventInfo.totalSubscribers}
                    </div>
                    <div className="g7dt-stat-label">총 구독자</div>
                </div>
                <div className="g7dt-stat-card">
                    <div className="g7dt-stat-value-lg g7dt-text-purple">
                        {eventInfo.totalEmits}
                    </div>
                    <div className="g7dt-stat-label">총 발행</div>
                </div>
            </div>

            {/* 구독된 이벤트 목록 */}
            <div>
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">구독된 이벤트</h3>
                <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-2">
                    <button
                        onClick={() => setSelectedEvent(null)}
                        className={selectedEvent === null ? 'g7dt-filter-btn g7dt-filter-active' : 'g7dt-filter-btn'}
                    >
                        전체
                    </button>
                    {eventInfo.subscriptions.map(sub => (
                        <button
                            key={sub.eventName}
                            onClick={() => setSelectedEvent(sub.eventName)}
                            className={selectedEvent === sub.eventName ? 'g7dt-filter-btn g7dt-filter-active' : 'g7dt-filter-btn'}
                        >
                            {sub.eventName} ({sub.subscriberCount})
                        </button>
                    ))}
                </div>
            </div>

            {/* Emit 이력 */}
            <div>
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">
                    발행 이력 {selectedEvent && `(${selectedEvent})`}
                </h3>
                <div className="g7dt-space-y-1 g7dt-event-list">
                    {filteredEmitHistory.length === 0 ? (
                        <div className="g7dt-empty-message g7dt-py-4">
                            이벤트 발행 이력이 없습니다
                        </div>
                    ) : (
                        filteredEmitHistory.slice(-30).reverse().map(emit => (
                            <div
                                key={emit.id}
                                className={`g7dt-card g7dt-text-xs ${emit.hasError ? 'g7dt-card-error' : ''}`}
                            >
                                <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                                    <span className="g7dt-font-mono g7dt-text-green">{emit.eventName}</span>
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        <span className="g7dt-text-gray">{emit.listenerCount} listeners</span>
                                        {emit.hasError && (
                                            <span className="g7dt-text-red g7dt-text-xxs">에러</span>
                                        )}
                                    </div>
                                </div>
                                {emit.data !== undefined && (
                                    <details className="g7dt-details g7dt-mt-1">
                                        <summary className="g7dt-summary">
                                            데이터
                                        </summary>
                                        <pre className="g7dt-pre">
                                            {JSON.stringify(emit.data, null, 2)}
                                        </pre>
                                    </details>
                                )}
                                {emit.hasError && emit.errorMessage && (
                                    <div className="g7dt-error-inline">
                                        {emit.errorMessage}
                                    </div>
                                )}
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* 새로고침 버튼 */}
            <button
                onClick={refreshData}
                className="g7dt-btn-secondary"
            >
                새로고침
            </button>
        </div>
    );
};

export default EventsTab;
