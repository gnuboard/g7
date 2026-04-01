/**
 * 핸들러 탭 컴포넌트
 *
 * 등록된 액션 핸들러 목록을 표시합니다.
 */

import React, { useState } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';

export interface HandlersTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 핸들러 탭
 */
export const HandlersTab: React.FC<HandlersTabProps> = ({ devTools }) => {
    const handlers = devTools.getHandlers();
    const [filter, setFilter] = useState<string>('');
    const [categoryFilter, setCategoryFilter] = useState<string>('all');

    // 핸들러 분류
    const categorizedHandlers = {
        'built-in': handlers.filter(h => h.category === 'built-in'),
        'custom': handlers.filter(h => h.category === 'custom'),
        'module': handlers.filter(h => h.category === 'module'),
        'plugin': handlers.filter(h => h.category === 'plugin'),
    };

    // 카테고리 카운트
    const categoryCounts = {
        all: handlers.length,
        'built-in': categorizedHandlers['built-in'].length,
        custom: categorizedHandlers['custom'].length,
        module: categorizedHandlers['module'].length,
        plugin: categorizedHandlers['plugin'].length,
    };

    // 필터링된 핸들러
    const filteredHandlers = handlers.filter(h => {
        if (categoryFilter !== 'all' && h.category !== categoryFilter) return false;
        if (filter) {
            return h.name.toLowerCase().includes(filter.toLowerCase()) ||
                   h.description?.toLowerCase().includes(filter.toLowerCase());
        }
        return true;
    });

    return (
        <div className="g7dt-space-y-4">
            {/* 카테고리 필터 */}
            <div className="g7dt-flex g7dt-gap-2">
                {(['all', 'built-in', 'custom', 'module', 'plugin'] as const).map(cat => (
                    <button
                        key={cat}
                        onClick={() => setCategoryFilter(cat)}
                        className={categoryFilter === cat ? 'g7dt-filter-btn g7dt-filter-active' : 'g7dt-filter-btn'}
                    >
                        {cat === 'all' ? '전체' : cat} ({categoryCounts[cat]})
                    </button>
                ))}
            </div>

            {/* 검색 */}
            <input
                type="text"
                placeholder="핸들러 이름 검색..."
                value={filter}
                onChange={e => setFilter(e.target.value)}
                className="g7dt-input g7dt-w-full"
            />

            {/* 핸들러 목록 */}
            <div className="g7dt-space-y-1 g7dt-handler-list">
                {filteredHandlers.length === 0 ? (
                    <div className="g7dt-empty-message g7dt-py-4">
                        등록된 핸들러가 없습니다
                    </div>
                ) : (
                    filteredHandlers.map(handler => (
                        <div
                            key={handler.name}
                            className="g7dt-card"
                        >
                            <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                                <span className="g7dt-font-mono g7dt-text-blue">{handler.name}</span>
                                <span
                                    className={
                                        handler.category === 'built-in'
                                            ? 'g7dt-mini-badge g7dt-badge-success'
                                            : handler.category === 'module'
                                            ? 'g7dt-mini-badge g7dt-badge-purple'
                                            : handler.category === 'plugin'
                                            ? 'g7dt-mini-badge g7dt-badge-info'
                                            : 'g7dt-mini-badge g7dt-badge-warning'
                                    }
                                >
                                    {handler.category}
                                </span>
                            </div>
                            {handler.description && (
                                <div className="g7dt-text-xs g7dt-text-gray g7dt-mt-1">
                                    {handler.description}
                                </div>
                            )}
                        </div>
                    ))
                )}
            </div>

            {/* 통계 */}
            <div className="g7dt-text-xs g7dt-text-gray">
                총 {handlers.length}개 핸들러 |
                빌트인 {categoryCounts['built-in']} |
                커스텀 {categoryCounts.custom} |
                모듈 {categoryCounts.module} |
                플러그인 {categoryCounts.plugin}
            </div>
        </div>
    );
};

export default HandlersTab;
