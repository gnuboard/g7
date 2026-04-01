/**
 * 레이아웃 탭 컴포넌트
 *
 * 현재 렌더링 중인 레이아웃 JSON을 조회합니다.
 * 레이아웃 캐시 문제를 진단하거나 실제 렌더링되는 구조를 확인할 때 사용합니다.
 */

import React, { useState, useEffect } from 'react';
import { G7DevToolsCore } from '../../G7DevToolsCore';

export interface LayoutTabProps {
    devTools: G7DevToolsCore;
}

/**
 * 레이아웃 탭
 */
export const LayoutTab: React.FC<LayoutTabProps> = ({ devTools }) => {
    const [layoutInfo, setLayoutInfo] = useState(devTools.getLayoutDebugInfo());
    const [showFull, setShowFull] = useState(false);
    const [selectedSection, setSelectedSection] = useState<string | null>(null);
    const [expandedHistory, setExpandedHistory] = useState(false);
    const [permissionNames, setPermissionNames] = useState<Record<string, string>>({});

    useEffect(() => {
        const interval = setInterval(() => {
            setLayoutInfo(devTools.getLayoutDebugInfo());
        }, 1000);
        return () => clearInterval(interval);
    }, [devTools]);

    // 권한 목록 조회 (컴포넌트 마운트 시)
    useEffect(() => {
        const fetchPermissions = async () => {
            try {
                const response = await fetch('/api/admin/permissions', {
                    headers: {
                        'Authorization': `Bearer ${localStorage.getItem('auth_token')}`,
                        'Accept': 'application/json',
                    },
                });
                if (response.ok) {
                    const data = await response.json();
                    const nameMap: Record<string, string> = {};
                    // 재귀적으로 권한 트리 순회
                    const extractNames = (perms: any[]) => {
                        perms.forEach(p => {
                            nameMap[p.identifier] = p.name;
                            if (p.children?.length) extractNames(p.children);
                        });
                    };
                    Object.values(data.data?.permissions || {}).forEach((group: any) => {
                        if (group.permissions) extractNames(group.permissions);
                    });
                    setPermissionNames(nameMap);
                }
            } catch (e) {
                console.warn('[G7DevTools] 권한 목록 조회 실패:', e);
            }
        };
        fetchPermissions();
    }, []);

    const current = layoutInfo.current;
    const layoutJson = current?.layoutJson;
    const history = layoutInfo.history;
    const stats = layoutInfo.stats;

    // 섹션 목록
    const sections = layoutJson ? [
        { key: 'components', label: '컴포넌트', count: Array.isArray(layoutJson.components) ? layoutJson.components.length : 0 },
        { key: 'data_sources', label: '데이터소스', count: Array.isArray(layoutJson.data_sources) ? layoutJson.data_sources.length : 0 },
        { key: 'computed', label: 'Computed', count: layoutJson.computed ? Object.keys(layoutJson.computed).length : 0 },
        { key: 'defines', label: 'Defines', count: layoutJson.defines ? Object.keys(layoutJson.defines).length : 0 },
        { key: 'slots', label: 'Slots', count: layoutJson.slots ? Object.keys(layoutJson.slots).length : 0 },
        { key: 'modals', label: 'Modals', count: layoutJson.modals ? Object.keys(layoutJson.modals).length : 0 },
        { key: 'init_actions', label: '초기화 액션', count: Array.isArray(layoutJson.init_actions) ? layoutJson.init_actions.length : 0 },
        { key: 'scripts', label: '스크립트', count: Array.isArray(layoutJson.scripts) ? layoutJson.scripts.length : 0 },
        { key: 'meta', label: '메타데이터', count: layoutJson.meta ? Object.keys(layoutJson.meta).length : 0 },
        { key: 'errorHandling', label: '에러핸들링', count: layoutJson.errorHandling ? Object.keys(layoutJson.errorHandling).length : 0 },
        { key: 'permissions', label: '권한', count: Array.isArray(layoutJson.permissions) ? layoutJson.permissions.length : 0 },
    ] : [];

    const copyToClipboard = (data: any) => {
        navigator.clipboard.writeText(JSON.stringify(data, null, 2));
    };

    if (!current) {
        return (
            <div className="g7dt-space-y-4">
                <div className="g7dt-empty-state">
                    현재 로드된 레이아웃이 없습니다.
                    <br />
                    페이지를 새로고침하거나 라우트를 변경해보세요.
                </div>
            </div>
        );
    }

    return (
        <div className="g7dt-space-y-4">
            {/* 통계 */}
            <div className="g7dt-card">
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">📊 로드 통계</h3>
                <div className="g7dt-grid-3">
                    <div className="g7dt-stat-item">
                        <span className="g7dt-text-muted">총 로드:</span>
                        <span className="g7dt-text-blue">{stats.totalLoads}</span>
                    </div>
                    <div className="g7dt-stat-item">
                        <span className="g7dt-text-muted">캐시 히트:</span>
                        <span className="g7dt-text-green">{stats.cacheHits}</span>
                    </div>
                    <div className="g7dt-stat-item">
                        <span className="g7dt-text-muted">API 로드:</span>
                        <span className="g7dt-text-yellow">{stats.apiLoads}</span>
                    </div>
                </div>
            </div>

            {/* 현재 레이아웃 정보 */}
            <div className="g7dt-card">
                <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-mb-2">
                    <h3 className="g7dt-text-sm g7dt-font-bold">📋 현재 레이아웃</h3>
                    <div className="g7dt-flex g7dt-gap-2">
                        <button
                            onClick={() => copyToClipboard(layoutJson)}
                            className="g7dt-btn-small"
                            title="전체 JSON 복사"
                        >
                            📋 복사
                        </button>
                        <button
                            onClick={() => setShowFull(!showFull)}
                            className="g7dt-btn-small"
                        >
                            {showFull ? '요약 보기' : '전체 보기'}
                        </button>
                    </div>
                </div>

                <div className="g7dt-space-y-2 g7dt-text-xs">
                    <div className="g7dt-flex g7dt-gap-4">
                        <div>
                            <span className="g7dt-text-muted">경로:</span>
                            <span className="g7dt-ml-1 g7dt-text-white g7dt-font-mono">{current.layoutPath}</span>
                        </div>
                        <div>
                            <span className="g7dt-text-muted">템플릿:</span>
                            <span className="g7dt-ml-1 g7dt-text-blue">{current.templateId}</span>
                        </div>
                    </div>
                    <div className="g7dt-flex g7dt-gap-4">
                        <div>
                            <span className="g7dt-text-muted">버전:</span>
                            <span className="g7dt-ml-1 g7dt-text-white">{current.version || '-'}</span>
                        </div>
                        <div>
                            <span className="g7dt-text-muted">소스:</span>
                            <span className={`g7dt-ml-1 ${current.source === 'cache' ? 'g7dt-text-green' : 'g7dt-text-yellow'}`}>
                                {current.source === 'cache' ? '💾 캐시' : '🌐 API'}
                            </span>
                        </div>
                        <div>
                            <span className="g7dt-text-muted">로드 시간:</span>
                            <span className="g7dt-ml-1 g7dt-text-white">
                                {current.loadedAt ? new Date(current.loadedAt).toLocaleTimeString() : '-'}
                            </span>
                        </div>
                    </div>
                    {/* 권한 표시 */}
                    <div className="g7dt-flex g7dt-gap-4 g7dt-mt-2">
                        <div>
                            <span className="g7dt-text-muted">권한:</span>
                            {layoutJson.permissions && layoutJson.permissions.length > 0 ? (
                                <span className="g7dt-ml-1 g7dt-text-yellow">
                                    🔒 {layoutJson.permissions.map((permId: string) =>
                                        permissionNames[permId] || permId
                                    ).join(', ')}
                                </span>
                            ) : layoutJson.permissions && layoutJson.permissions.length === 0 ? (
                                <span className="g7dt-ml-1 g7dt-text-green">🌐 공개</span>
                            ) : (
                                <span className="g7dt-ml-1 g7dt-text-muted">미정의 (부모 상속)</span>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* 섹션 목록 */}
            {!showFull && (
                <div className="g7dt-card">
                    <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">🏗️ 레이아웃 구조</h3>
                    <div className="g7dt-grid-2">
                        {sections.map(section => (
                            <button
                                key={section.key}
                                onClick={() => setSelectedSection(selectedSection === section.key ? null : section.key)}
                                className={`g7dt-section-btn ${selectedSection === section.key ? 'g7dt-selected' : ''}`}
                            >
                                <span>{section.label}</span>
                                <span className="g7dt-text-blue">{section.count}개</span>
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {/* 선택된 섹션 또는 전체 JSON */}
            {(showFull || selectedSection) && (
                <div className="g7dt-card">
                    <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-mb-2">
                        <h3 className="g7dt-text-sm g7dt-font-bold">
                            {showFull ? '📄 전체 레이아웃 JSON' : `📎 ${selectedSection} 섹션`}
                        </h3>
                        <button
                            onClick={() => {
                                if (showFull) {
                                    copyToClipboard(layoutJson);
                                } else if (selectedSection && layoutJson) {
                                    copyToClipboard((layoutJson as any)[selectedSection]);
                                }
                            }}
                            className="g7dt-btn-small"
                        >
                            복사
                        </button>
                    </div>
                    {/* 권한 섹션은 테이블로 표시 */}
                    {selectedSection === 'permissions' && !showFull ? (
                        <div className="g7dt-text-xs">
                            {layoutJson.permissions && layoutJson.permissions.length > 0 ? (
                                <table className="g7dt-table g7dt-w-full">
                                    <thead>
                                        <tr className="g7dt-border-b g7dt-border-gray-700">
                                            <th className="g7dt-text-left g7dt-p-2 g7dt-text-muted">권한 ID</th>
                                            <th className="g7dt-text-left g7dt-p-2 g7dt-text-muted">명칭</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {layoutJson.permissions.map((permId: string) => (
                                            <tr key={permId} className="g7dt-border-b g7dt-border-gray-800">
                                                <td className="g7dt-p-2 g7dt-font-mono g7dt-text-blue">{permId}</td>
                                                <td className="g7dt-p-2 g7dt-text-white">
                                                    {permissionNames[permId] || <span className="g7dt-text-muted">-</span>}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ) : layoutJson.permissions && layoutJson.permissions.length === 0 ? (
                                <p className="g7dt-text-muted g7dt-p-2">🌐 공개 레이아웃 (permissions: [])</p>
                            ) : (
                                <p className="g7dt-text-muted g7dt-p-2">권한 미정의 (부모 레이아웃 상속)</p>
                            )}
                        </div>
                    ) : (
                        <pre className="g7dt-pre g7dt-text-green g7dt-max-h-96 g7dt-overflow-auto">
                            {showFull
                                ? JSON.stringify(layoutJson, null, 2)
                                : JSON.stringify(selectedSection && layoutJson ? (layoutJson as any)[selectedSection] : null, null, 2)
                            }
                        </pre>
                    )}
                </div>
            )}

            {/* 로드 이력 */}
            {history.length > 0 && (
                <div className="g7dt-card">
                    <div
                        className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-cursor-pointer"
                        onClick={() => setExpandedHistory(!expandedHistory)}
                    >
                        <h3 className="g7dt-text-sm g7dt-font-bold">📜 로드 이력 ({history.length})</h3>
                        <span className="g7dt-text-muted">{expandedHistory ? '▼' : '▶'}</span>
                    </div>

                    {expandedHistory && (
                        <div className="g7dt-mt-2 g7dt-space-y-1">
                            {history.slice(-20).reverse().map(entry => (
                                <div key={entry.id} className="g7dt-history-item">
                                    <div className="g7dt-flex g7dt-justify-between g7dt-items-center">
                                        <span className="g7dt-font-mono g7dt-text-xs">{entry.layoutPath}</span>
                                        <span className={`g7dt-text-xs ${entry.source === 'cache' ? 'g7dt-text-green' : 'g7dt-text-yellow'}`}>
                                            {entry.source === 'cache' ? '💾' : '🌐'}
                                        </span>
                                    </div>
                                    <div className="g7dt-text-xs g7dt-text-muted">
                                        {entry.templateId} · {entry.loadedAt ? new Date(entry.loadedAt).toLocaleTimeString() : '-'}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* 안내 메시지 */}
            <div className="g7dt-card g7dt-text-xs g7dt-text-muted">
                💡 레이아웃 캐시 문제가 의심되면:
                <ul className="g7dt-ml-4 g7dt-list-disc">
                    <li>"소스" 항목이 💾 캐시인지 🌐 API인지 확인</li>
                    <li>캐시에서 로드된 경우, JSON 내용이 최신인지 확인</li>
                    <li>문제가 있으면 <code className="g7dt-code">php artisan template:refresh-layout</code> 실행</li>
                </ul>
            </div>
        </div>
    );
};

export default LayoutTab;
