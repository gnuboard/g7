/**
 * 로그 조회 탭 컴포넌트
 *
 * Logger로 쌓인 로그 이력을 조회합니다.
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { LogEntry, LogStats, LogLevel } from '../../types';

export interface LogsTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 로그 조회 탭
 */
export const LogsTab: React.FC<LogsTabProps> = ({ devTools }) => {
    const [logs, setLogs] = useState<LogEntry[]>([]);
    const [stats, setStats] = useState<LogStats | null>(null);
    const [selectedLog, setSelectedLog] = useState<string | null>(null);
    const [levelFilter, setLevelFilter] = useState<string>('all');
    const [prefixFilter, setPrefixFilter] = useState<string>('');
    const [searchFilter, setSearchFilter] = useState<string>('');

    // 데이터 새로고침
    const refreshData = useCallback(() => {
        const logInfo = devTools.getLogInfo();
        setLogs(logInfo.entries);
        setStats(logInfo.stats);
    }, [devTools]);

    // 주기적 갱신
    useEffect(() => {
        refreshData();
        const intervalId = setInterval(refreshData, 1000);
        return () => clearInterval(intervalId);
    }, [refreshData]);

    // 필터링된 로그
    const filteredLogs = logs.filter(log => {
        if (levelFilter !== 'all' && log.level !== levelFilter) return false;
        if (prefixFilter && !log.prefix.toLowerCase().includes(prefixFilter.toLowerCase())) return false;
        if (searchFilter && !log.message.toLowerCase().includes(searchFilter.toLowerCase())) return false;
        return true;
    });

    // 선택된 로그
    const selectedLogData = selectedLog
        ? logs.find(l => l.id === selectedLog)
        : null;

    // 로그 초기화
    const clearLogs = () => {
        devTools.clearLogs();
        refreshData();
        setSelectedLog(null);
    };

    // 레벨별 색상
    const getLevelColor = (level: LogLevel): string => {
        switch (level) {
            case 'error': return 'g7dt-text-red';
            case 'warn': return 'g7dt-text-yellow';
            case 'info': return 'g7dt-text-blue';
            case 'debug': return 'g7dt-text-muted';
            default: return 'g7dt-text-white';
        }
    };

    const getLevelBgColor = (level: LogLevel): string => {
        switch (level) {
            case 'error': return 'g7dt-log-error';
            case 'warn': return 'g7dt-log-warn';
            case 'info': return 'g7dt-log-info';
            case 'debug': return 'g7dt-log-debug';
            default: return 'g7dt-log-default';
        }
    };

    return (
        <div className="g7dt-space-y-4">
            {/* 통계 */}
            {stats && (
                <div className="g7dt-stats-grid-6">
                    <div className="g7dt-stat-card-sm">
                        <div className="g7dt-stat-value-sm g7dt-text-white">{stats.totalLogs}</div>
                        <div className="g7dt-stat-label">전체</div>
                    </div>
                    <div className="g7dt-stat-card-sm">
                        <div className="g7dt-stat-value-sm g7dt-text-red">{stats.byLevel.error}</div>
                        <div className="g7dt-stat-label">에러</div>
                    </div>
                    <div className="g7dt-stat-card-sm">
                        <div className="g7dt-stat-value-sm g7dt-text-yellow">{stats.byLevel.warn}</div>
                        <div className="g7dt-stat-label">경고</div>
                    </div>
                    <div className="g7dt-stat-card-sm">
                        <div className="g7dt-stat-value-sm g7dt-text-blue">{stats.byLevel.info}</div>
                        <div className="g7dt-stat-label">정보</div>
                    </div>
                    <div className="g7dt-stat-card-sm">
                        <div className={`g7dt-stat-value-sm ${stats.recentErrors > 0 ? 'g7dt-text-red' : 'g7dt-text-green'}`}>
                            {stats.recentErrors}
                        </div>
                        <div className="g7dt-stat-label">최근 에러</div>
                    </div>
                    <div className="g7dt-stat-card-sm">
                        <div className={`g7dt-stat-value-sm ${stats.recentWarnings > 0 ? 'g7dt-text-yellow' : 'g7dt-text-green'}`}>
                            {stats.recentWarnings}
                        </div>
                        <div className="g7dt-stat-label">최근 경고</div>
                    </div>
                </div>
            )}

            {/* 필터 */}
            <div className="g7dt-flex g7dt-gap-2 g7dt-flex-wrap">
                <select
                    value={levelFilter}
                    onChange={e => setLevelFilter(e.target.value)}
                    className="g7dt-select"
                >
                    <option value="all">모든 레벨</option>
                    <option value="error">에러</option>
                    <option value="warn">경고</option>
                    <option value="info">정보</option>
                    <option value="log">로그</option>
                    <option value="debug">디버그</option>
                </select>
                <input
                    type="text"
                    placeholder="prefix 필터"
                    value={prefixFilter}
                    onChange={e => setPrefixFilter(e.target.value)}
                    className="g7dt-input g7dt-w-32"
                />
                <input
                    type="text"
                    placeholder="메시지 검색"
                    value={searchFilter}
                    onChange={e => setSearchFilter(e.target.value)}
                    className="g7dt-input g7dt-flex-1"
                />
                <button
                    onClick={refreshData}
                    className="g7dt-btn-secondary"
                >
                    새로고침
                </button>
                <button
                    onClick={clearLogs}
                    className="g7dt-btn-danger"
                >
                    초기화
                </button>
            </div>

            {/* 로그 목록 */}
            <div className="g7dt-scroll-list-lg">
                {filteredLogs.length === 0 ? (
                    <div className="g7dt-empty-state">
                        {logs.length === 0 ? '로그가 없습니다' : '필터에 맞는 로그가 없습니다'}
                    </div>
                ) : (
                    filteredLogs.slice(-50).reverse().map(log => {
                        const time = new Date(log.timestamp).toLocaleTimeString();

                        return (
                            <div
                                key={log.id}
                                onClick={() => setSelectedLog(log.id)}
                                className={`g7dt-log-item ${
                                    selectedLog === log.id
                                        ? 'g7dt-selected'
                                        : getLevelBgColor(log.level)
                                }`}
                            >
                                <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                                    <div className="g7dt-flex g7dt-items-center g7dt-gap-2">
                                        <span className={`g7dt-font-bold g7dt-uppercase ${getLevelColor(log.level)}`}>
                                            {log.level}
                                        </span>
                                        <span className="g7dt-text-purple">[{log.prefix}]</span>
                                    </div>
                                    <span className="g7dt-text-muted">{time}</span>
                                </div>
                                <div className="g7dt-text-white g7dt-mt-1 g7dt-truncate">{log.message}</div>
                            </div>
                        );
                    })
                )}
            </div>

            {/* 선택된 로그 상세 */}
            {selectedLogData && (
                <div className="g7dt-card">
                    <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-mb-2">
                        <h3 className="g7dt-text-sm g7dt-font-bold">
                            <span className={getLevelColor(selectedLogData.level)}>{selectedLogData.level.toUpperCase()}</span>
                            {' '}<span className="g7dt-text-purple">[{selectedLogData.prefix}]</span>
                        </h3>
                        <button
                            onClick={() => setSelectedLog(null)}
                            className="g7dt-close-btn"
                        >
                            ✕
                        </button>
                    </div>
                    <div className="g7dt-space-y-2 g7dt-text-xs">
                        <div>
                            <span className="g7dt-text-muted">시간:</span>
                            <span className="g7dt-ml-2 g7dt-text-white">
                                {new Date(selectedLogData.timestamp).toLocaleString()}
                            </span>
                        </div>
                        <div>
                            <span className="g7dt-text-muted">메시지:</span>
                            <pre className="g7dt-pre g7dt-text-white g7dt-whitespace-pre-wrap">
                                {selectedLogData.message}
                            </pre>
                        </div>
                        {selectedLogData.args.length > 0 && (
                            <div>
                                <span className="g7dt-text-muted">인수:</span>
                                <pre className="g7dt-pre g7dt-text-green">
                                    {JSON.stringify(selectedLogData.args, null, 2)}
                                </pre>
                            </div>
                        )}
                        {selectedLogData.stack && (
                            <div>
                                <span className="g7dt-text-muted">스택 트레이스:</span>
                                <pre className="g7dt-pre-stack g7dt-text-red">
                                    {selectedLogData.stack}
                                </pre>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Prefix별 통계 */}
            {stats && Object.keys(stats.byPrefix).length > 0 && (
                <div>
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">Prefix별 로그 수</h3>
                    <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-2">
                        {Object.entries(stats.byPrefix)
                            .sort((a, b) => b[1] - a[1])
                            .slice(0, 10)
                            .map(([prefix, count]) => (
                                <button
                                    key={prefix}
                                    onClick={() => setPrefixFilter(prefix)}
                                    className={prefixFilter === prefix ? 'g7dt-prefix-btn-active' : 'g7dt-prefix-btn'}
                                >
                                    {prefix}: <span className="g7dt-text-blue">{count}</span>
                                </button>
                            ))
                        }
                    </div>
                </div>
            )}
        </div>
    );
};

export default LogsTab;
