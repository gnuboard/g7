/**
 * 인증 디버깅 탭 컴포넌트
 *
 * 인증 상태, 토큰 정보, 인증 이벤트 이력을 표시합니다.
 */

import React, { useState, useEffect, useCallback } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';
import type { AuthDebugInfo } from '../../types';

export interface AuthTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 인증 디버깅 탭
 */
export const AuthTab: React.FC<AuthTabProps> = ({ devTools }) => {
    const [authInfo, setAuthInfo] = useState<AuthDebugInfo | null>(null);
    const [selectedEvent, setSelectedEvent] = useState<string | null>(null);
    const [typeFilter, setTypeFilter] = useState<string>('all');

    // 데이터 새로고침
    const refreshData = useCallback(() => {
        setAuthInfo(devTools.getAuthDebugInfo());
    }, [devTools]);

    // 주기적 갱신
    useEffect(() => {
        refreshData();
        const intervalId = setInterval(refreshData, 2000);
        return () => clearInterval(intervalId);
    }, [refreshData]);

    if (!authInfo) {
        return <div className="g7dt-empty-state">로딩 중...</div>;
    }

    // 필터링된 이벤트
    const filteredEvents = authInfo.events.filter(event => {
        if (typeFilter === 'all') return true;
        return event.type === typeFilter;
    });

    // 선택된 이벤트
    const selectedEventData = selectedEvent
        ? authInfo.events.find(e => e.id === selectedEvent)
        : null;

    const stats = authInfo.stats;
    const state = authInfo.state;

    return (
        <div className="g7dt-space-y-4">
            {/* 현재 인증 상태 */}
            <div className="g7dt-card">
                <div className="g7dt-flex g7dt-items-center g7dt-justify-between g7dt-mb-3">
                    <h3 className="g7dt-text-sm g7dt-font-bold">현재 인증 상태</h3>
                    <span className="g7dt-text-xs g7dt-text-muted">
                        소스: {state.source || 'unknown'}
                    </span>
                </div>
                <div className="g7dt-stats-grid-3 g7dt-mb-3">
                    <div className="g7dt-text-center">
                        <div className={`g7dt-text-lg g7dt-font-bold ${state.isAuthenticated ? 'g7dt-text-green' : 'g7dt-text-red'}`}>
                            {state.isAuthenticated ? '✓ 인증됨' : '✕ 미인증'}
                        </div>
                    </div>
                    <div className="g7dt-text-center">
                        <div className={`g7dt-text-lg g7dt-font-bold ${state.tokens.hasAccessToken ? 'g7dt-text-green' : 'g7dt-text-yellow'}`}>
                            {state.tokens.hasAccessToken ? '✓ 토큰 유효' : '⚠ 토큰 없음'}
                        </div>
                    </div>
                    <div className="g7dt-text-center">
                        <div className="g7dt-text-lg g7dt-font-bold g7dt-text-blue">
                            {state.user?.name || state.user?.email || '익명'}
                        </div>
                    </div>
                </div>

                {/* 사용자 상세 정보 */}
                {state.user && (
                    <div className="g7dt-border-t g7dt-pt-3 g7dt-mt-3">
                        <div className="g7dt-grid-2 g7dt-gap-2 g7dt-text-xs">
                            <div className="g7dt-flex g7dt-justify-between">
                                <span className="g7dt-text-muted">ID:</span>
                                <span className="g7dt-text-white g7dt-mono">{state.user.id}</span>
                            </div>
                            <div className="g7dt-flex g7dt-justify-between">
                                <span className="g7dt-text-muted">이메일:</span>
                                <span className="g7dt-text-white g7dt-truncate g7dt-ml-2" title={state.user.email}>{state.user.email}</span>
                            </div>
                            {state.user.name && (
                                <div className="g7dt-flex g7dt-justify-between">
                                    <span className="g7dt-text-muted">이름:</span>
                                    <span className="g7dt-text-white">{state.user.name}</span>
                                </div>
                            )}
                            {state.user.nickname && (
                                <div className="g7dt-flex g7dt-justify-between">
                                    <span className="g7dt-text-muted">닉네임:</span>
                                    <span className="g7dt-text-white">{state.user.nickname}</span>
                                </div>
                            )}
                            {state.user.phone && (
                                <div className="g7dt-flex g7dt-justify-between">
                                    <span className="g7dt-text-muted">전화번호:</span>
                                    <span className="g7dt-text-white">{state.user.phone}</span>
                                </div>
                            )}
                            {state.user.status && (
                                <div className="g7dt-flex g7dt-justify-between">
                                    <span className="g7dt-text-muted">상태:</span>
                                    <span className={state.user.status === 'active' ? 'g7dt-text-green' : 'g7dt-text-yellow'}>
                                        {state.user.status}
                                    </span>
                                </div>
                            )}
                            {state.user.locale && (
                                <div className="g7dt-flex g7dt-justify-between">
                                    <span className="g7dt-text-muted">언어:</span>
                                    <span className="g7dt-text-white">{state.user.locale}</span>
                                </div>
                            )}
                        </div>

                        {/* 역할 정보 */}
                        {state.user.roles && state.user.roles.length > 0 && (
                            <div className="g7dt-mt-2">
                                <span className="g7dt-text-muted g7dt-text-xs">역할: </span>
                                <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-1 g7dt-mt-1">
                                    {state.user.roles.map((role: any, idx: number) => (
                                        <span
                                            key={idx}
                                            className="g7dt-role-tag"
                                            title={role.guard_name ? `guard: ${role.guard_name}` : undefined}
                                        >
                                            {typeof role === 'string' ? role : role.name}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* 권한 정보 */}
                        {state.user.permissions && state.user.permissions.length > 0 && (
                            <div className="g7dt-mt-2">
                                <span className="g7dt-text-muted g7dt-text-xs">권한: </span>
                                <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-1 g7dt-mt-1 g7dt-max-h-16 g7dt-overflow-auto">
                                    {state.user.permissions.slice(0, 10).map((perm: any, idx: number) => (
                                        <span
                                            key={idx}
                                            className="g7dt-perm-tag"
                                            title={perm.guard_name ? `guard: ${perm.guard_name}` : undefined}
                                        >
                                            {typeof perm === 'string' ? perm : perm.name}
                                        </span>
                                    ))}
                                    {state.user.permissions.length > 10 && (
                                        <span className="g7dt-text-muted g7dt-text-xs">+{state.user.permissions.length - 10}개</span>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* 날짜 정보 */}
                        <div className="g7dt-mt-2 g7dt-grid-2 g7dt-gap-2 g7dt-text-xs">
                            {state.user.created_at && (
                                <div className="g7dt-flex g7dt-justify-between">
                                    <span className="g7dt-text-muted">가입일:</span>
                                    <span className="g7dt-text-muted">{new Date(state.user.created_at).toLocaleDateString()}</span>
                                </div>
                            )}
                            {state.user.last_login_at && (
                                <div className="g7dt-flex g7dt-justify-between">
                                    <span className="g7dt-text-muted">마지막 로그인:</span>
                                    <span className="g7dt-text-muted">{new Date(state.user.last_login_at).toLocaleString()}</span>
                                </div>
                            )}
                            {state.user.email_verified_at && (
                                <div className="g7dt-flex g7dt-justify-between">
                                    <span className="g7dt-text-muted">이메일 인증:</span>
                                    <span className="g7dt-text-green">✓ 인증됨</span>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* 토큰 상세 정보 */}
                <div className="g7dt-border-t g7dt-pt-3 g7dt-mt-3">
                    <div className="g7dt-grid-2 g7dt-gap-2 g7dt-text-xs">
                        <div className="g7dt-flex g7dt-justify-between">
                            <span className="g7dt-text-muted">저장소:</span>
                            <span className="g7dt-text-white">{state.tokens.storage}</span>
                        </div>
                        {state.tokens.accessTokenPreview && (
                            <div className="g7dt-flex g7dt-justify-between">
                                <span className="g7dt-text-muted">토큰:</span>
                                <span className="g7dt-text-muted g7dt-mono">{state.tokens.accessTokenPreview}</span>
                            </div>
                        )}
                    </div>
                </div>

                {state.tokens.accessTokenExpiresAt && (
                    <div className="g7dt-text-xs g7dt-text-muted g7dt-mt-2 g7dt-text-center">
                        토큰 만료: {new Date(state.tokens.accessTokenExpiresAt).toLocaleString()}
                    </div>
                )}
            </div>

            {/* 통계 */}
            <div className="g7dt-stats-grid-5">
                <div className="g7dt-stat-card-sm">
                    <div className="g7dt-stat-value-sm g7dt-text-blue">{stats.loginAttempts}</div>
                    <div className="g7dt-stat-label">로그인 시도</div>
                </div>
                <div className="g7dt-stat-card-sm">
                    <div className="g7dt-stat-value-sm g7dt-text-green">{stats.successfulLogins}</div>
                    <div className="g7dt-stat-label">성공</div>
                </div>
                <div className="g7dt-stat-card-sm">
                    <div className={`g7dt-stat-value-sm ${stats.failedLogins > 0 ? 'g7dt-text-red' : 'g7dt-text-muted'}`}>
                        {stats.failedLogins}
                    </div>
                    <div className="g7dt-stat-label">실패</div>
                </div>
                <div className="g7dt-stat-card-sm">
                    <div className="g7dt-stat-value-sm g7dt-text-purple">{stats.tokenRefreshes}</div>
                    <div className="g7dt-stat-label">토큰 갱신</div>
                </div>
                <div className="g7dt-stat-card-sm">
                    <div className={`g7dt-stat-value-sm ${stats.unauthorizedResponses > 0 ? 'g7dt-text-red' : 'g7dt-text-muted'}`}>
                        {stats.unauthorizedResponses}
                    </div>
                    <div className="g7dt-stat-label">401 응답</div>
                </div>
            </div>

            {/* 필터 */}
            <div className="g7dt-flex g7dt-gap-2">
                <select
                    value={typeFilter}
                    onChange={e => setTypeFilter(e.target.value)}
                    className="g7dt-select"
                >
                    <option value="all">모든 이벤트</option>
                    <option value="login">로그인</option>
                    <option value="logout">로그아웃</option>
                    <option value="token-refresh">토큰 갱신</option>
                    <option value="token-expired">토큰 만료</option>
                    <option value="api-unauthorized">401 응답</option>
                </select>
                <button
                    onClick={refreshData}
                    className="g7dt-btn-secondary"
                >
                    새로고침
                </button>
            </div>

            {/* 이벤트 목록 */}
            <div className="g7dt-scroll-list-sm">
                {filteredEvents.length === 0 ? (
                    <div className="g7dt-empty-state">
                        인증 이벤트가 없습니다
                    </div>
                ) : (
                    filteredEvents.slice(-20).reverse().map(event => {
                        const icon = event.success ? '✅' : '❌';
                        const time = new Date(event.timestamp).toLocaleTimeString();

                        return (
                            <div
                                key={event.id}
                                onClick={() => setSelectedEvent(event.id)}
                                className={`g7dt-auth-event-item ${
                                    selectedEvent === event.id
                                        ? 'g7dt-selected'
                                        : event.success
                                        ? ''
                                        : 'g7dt-error'
                                }`}
                            >
                                <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                                    <span className="g7dt-mono">
                                        {icon} {event.type}
                                    </span>
                                    <span className="g7dt-text-muted">{time}</span>
                                </div>
                                {event.error && (
                                    <div className="g7dt-text-red g7dt-mt-1 g7dt-truncate">{event.error}</div>
                                )}
                            </div>
                        );
                    })
                )}
            </div>

            {/* 선택된 이벤트 상세 */}
            {selectedEventData && (
                <div className="g7dt-card">
                    <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-mb-2">
                        <h3 className="g7dt-text-sm g7dt-font-bold">{selectedEventData.type}</h3>
                        <button
                            onClick={() => setSelectedEvent(null)}
                            className="g7dt-close-btn"
                        >
                            ✕
                        </button>
                    </div>
                    <div className="g7dt-space-y-2 g7dt-text-xs">
                        <div>
                            <span className="g7dt-text-muted">결과:</span>
                            <span className={`g7dt-ml-2 ${selectedEventData.success ? 'g7dt-text-green' : 'g7dt-text-red'}`}>
                                {selectedEventData.success ? '성공' : '실패'}
                            </span>
                        </div>
                        {selectedEventData.error && (
                            <div>
                                <span className="g7dt-text-muted">에러:</span>
                                <div className="g7dt-mt-1 g7dt-text-red">{selectedEventData.error}</div>
                            </div>
                        )}
                        {selectedEventData.details && Object.keys(selectedEventData.details).length > 0 && (
                            <div>
                                <span className="g7dt-text-muted">상세:</span>
                                <pre className="g7dt-pre g7dt-text-green">
                                    {JSON.stringify(selectedEventData.details, null, 2)}
                                </pre>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* API 헤더 분석 */}
            {authInfo.headerAnalysis.length > 0 && (
                <div>
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">최근 API 인증 헤더</h3>
                    <div className="g7dt-scroll-list-xs">
                        {authInfo.headerAnalysis.slice(-10).reverse().map((header, i) => (
                            <div key={i} className="g7dt-header-item">
                                <span className="g7dt-text-blue g7dt-truncate g7dt-flex-1">{header.url}</span>
                                <span className={`g7dt-ml-2 ${header.hasAuthHeader ? 'g7dt-text-green' : 'g7dt-text-yellow'}`}>
                                    {header.hasAuthHeader ? `✓ ${header.headerType}` : '✕ 헤더 없음'}
                                </span>
                                {header.responseStatus && (
                                    <span className={`g7dt-ml-2 ${header.responseStatus < 400 ? 'g7dt-text-muted' : 'g7dt-text-red'}`}>
                                        {header.responseStatus}
                                    </span>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
};

export default AuthTab;
