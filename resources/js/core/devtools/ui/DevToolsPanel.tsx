/**
 * G7 DevTools 패널 컴포넌트
 *
 * 브라우저에서 실시간 디버깅을 위한 UI 패널
 * Ctrl+Shift+G로 토글 가능
 * 헤더 드래그로 자유 이동 가능
 *
 * @module DevToolsPanel
 */

import React, { useState, useEffect, useCallback, useRef } from 'react';
import { G7DevToolsCore } from '../G7DevToolsCore';
import { getServerConnector } from '../ServerConnector';
import type { StateView, ActionLog, CacheStats } from '../types';

// 스타일, 상수, 유틸리티 import
import { injectDevToolsStyles } from './styles';
import {
    TabType,
    PanelPosition,
    PanelSize,
    ResizeDirection,
    TAB_LABELS,
    DEFAULT_PANEL_WIDTH,
    DEFAULT_PANEL_HEIGHT,
    MIN_PANEL_WIDTH,
    MIN_PANEL_HEIGHT,
} from './constants';
import { loadPanelConfig, savePanelConfig } from './utils';

// 탭 컴포넌트 import
import {
    StateTab,
    ActionsTab,
    CacheTab,
    DiagnoseTab,
    PerformanceTab,
    LifecycleTab,
    NetworkTab,
    FormTab,
    ConditionalTab,
    ExpressionTab,
    HandlersTab,
    EventsTab,
    DataSourcesTab,
    RendersTab,
    StylesTab,
    AuthTab,
    LogsTab,
    LayoutTab,
    ChangeDetectionTab,
    StaleClosureTab,
    NestedContextTab,
    ModalScopeTab,
    NamedActionsTab,
} from './tabs';

/**
 * DevTools 패널 메인 컴포넌트
 */
export const DevToolsPanel: React.FC = () => {
    // 컴포넌트 마운트 시 스타일 주입
    useEffect(() => {
        injectDevToolsStyles();
    }, []);

    // 로컬스토리지에서 저장된 설정 로드
    const savedConfig = useRef(loadPanelConfig());

    const [isOpen, setIsOpen] = useState(savedConfig.current.isOpen ?? false);
    const [isMinimized, setIsMinimized] = useState(false);
    const [activeTab, setActiveTab] = useState<TabType>('state');
    const [state, setState] = useState<StateView>({ _global: {}, _local: {}, _computed: {} });
    const [actions, setActions] = useState<ActionLog[]>([]);
    const [cacheStats, setCacheStats] = useState<CacheStats>({ hits: 0, misses: 0, entries: 0, hitRate: 0 });
    const [isConnected, setIsConnected] = useState(false);

    // 드래그 관련 상태 - 저장된 위치 사용
    const [position, setPosition] = useState<PanelPosition>(
        savedConfig.current.position ?? { x: -1, y: -1 }
    );
    const [isDragging, setIsDragging] = useState(false);
    const dragStartRef = useRef<{ x: number; y: number; posX: number; posY: number } | null>(null);
    const panelRef = useRef<HTMLDivElement>(null);

    // 크기 조절 관련 상태 - 저장된 크기 사용
    const [size, setSize] = useState<PanelSize>(
        savedConfig.current.size ?? { width: DEFAULT_PANEL_WIDTH, height: DEFAULT_PANEL_HEIGHT }
    );
    const [isResizing, setIsResizing] = useState(false);
    const [resizeDirection, setResizeDirection] = useState<ResizeDirection>(null);
    const resizeStartRef = useRef<{ x: number; y: number; width: number; height: number; posX: number; posY: number } | null>(null);

    // 최대화 상태
    const [isMaximized, setIsMaximized] = useState(false);
    const preMaximizeStateRef = useRef<{ position: PanelPosition; size: PanelSize } | null>(null);

    const devTools = G7DevToolsCore.getInstance();
    const serverConnector = getServerConnector();

    // 초기 위치 설정 (저장된 위치가 없으면 우측 상단)
    useEffect(() => {
        if (position.x === -1 && position.y === -1) {
            const initialX = window.innerWidth - size.width - 16;
            const initialY = 16;
            setPosition({ x: initialX, y: initialY });
        }
    }, [position, size.width]);

    // 위치/크기 변경 시 로컬스토리지에 저장 (디바운스)
    useEffect(() => {
        // 초기화 전이면 저장하지 않음
        if (position.x === -1 && position.y === -1) return;
        // 드래그/리사이즈 중이면 저장하지 않음 (끝날 때 저장)
        if (isDragging || isResizing) return;

        const timeoutId = setTimeout(() => {
            savePanelConfig({ position, size });
        }, 300);

        return () => clearTimeout(timeoutId);
    }, [position, size, isDragging, isResizing]);

    // isOpen 변경 시 로컬스토리지에 저장
    useEffect(() => {
        savePanelConfig({ isOpen });
    }, [isOpen]);

    // 드래그 시작
    const handleDragStart = useCallback((e: React.MouseEvent) => {
        // 버튼 클릭 시 드래그 방지
        if ((e.target as HTMLElement).tagName === 'BUTTON') return;

        setIsDragging(true);
        dragStartRef.current = {
            x: e.clientX,
            y: e.clientY,
            posX: position.x,
            posY: position.y,
        };
        e.preventDefault();
    }, [position]);

    // 드래그 중
    useEffect(() => {
        if (!isDragging) return;

        const handleMouseMove = (e: MouseEvent) => {
            if (!dragStartRef.current) return;

            const deltaX = e.clientX - dragStartRef.current.x;
            const deltaY = e.clientY - dragStartRef.current.y;

            let newX = dragStartRef.current.posX + deltaX;
            let newY = dragStartRef.current.posY + deltaY;

            // 화면 경계 제한
            const maxX = window.innerWidth - size.width;
            const maxY = window.innerHeight - size.height;

            newX = Math.max(0, Math.min(newX, maxX));
            newY = Math.max(0, Math.min(newY, maxY));

            setPosition({ x: newX, y: newY });
        };

        const handleMouseUp = () => {
            setIsDragging(false);
            dragStartRef.current = null;
        };

        document.addEventListener('mousemove', handleMouseMove);
        document.addEventListener('mouseup', handleMouseUp);

        return () => {
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('mouseup', handleMouseUp);
        };
    }, [isDragging, size.width, size.height]);

    // 리사이즈 시작
    const handleResizeStart = useCallback((direction: ResizeDirection) => (e: React.MouseEvent) => {
        e.preventDefault();
        e.stopPropagation();
        setIsResizing(true);
        setResizeDirection(direction);
        resizeStartRef.current = {
            x: e.clientX,
            y: e.clientY,
            width: size.width,
            height: size.height,
            posX: position.x,
            posY: position.y,
        };
    }, [size, position]);

    // 리사이즈 중
    useEffect(() => {
        if (!isResizing || !resizeDirection) return;

        const handleMouseMove = (e: MouseEvent) => {
            if (!resizeStartRef.current) return;

            const deltaX = e.clientX - resizeStartRef.current.x;
            const deltaY = e.clientY - resizeStartRef.current.y;

            let newWidth = resizeStartRef.current.width;
            let newHeight = resizeStartRef.current.height;
            let newX = resizeStartRef.current.posX;
            let newY = resizeStartRef.current.posY;

            // 방향에 따른 크기/위치 계산
            if (resizeDirection.includes('e')) {
                newWidth = Math.max(MIN_PANEL_WIDTH, resizeStartRef.current.width + deltaX);
            }
            if (resizeDirection.includes('w')) {
                const widthChange = Math.min(deltaX, resizeStartRef.current.width - MIN_PANEL_WIDTH);
                newWidth = resizeStartRef.current.width - widthChange;
                newX = resizeStartRef.current.posX + widthChange;
            }
            if (resizeDirection.includes('s')) {
                newHeight = Math.max(MIN_PANEL_HEIGHT, resizeStartRef.current.height + deltaY);
            }
            if (resizeDirection.includes('n')) {
                const heightChange = Math.min(deltaY, resizeStartRef.current.height - MIN_PANEL_HEIGHT);
                newHeight = resizeStartRef.current.height - heightChange;
                newY = resizeStartRef.current.posY + heightChange;
            }

            // 화면 경계 제한
            newWidth = Math.min(newWidth, window.innerWidth - newX);
            newHeight = Math.min(newHeight, window.innerHeight - newY);
            newX = Math.max(0, newX);
            newY = Math.max(0, newY);

            setSize({ width: newWidth, height: newHeight });
            setPosition({ x: newX, y: newY });
        };

        const handleMouseUp = () => {
            setIsResizing(false);
            setResizeDirection(null);
            resizeStartRef.current = null;
        };

        document.addEventListener('mousemove', handleMouseMove);
        document.addEventListener('mouseup', handleMouseUp);

        return () => {
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('mouseup', handleMouseUp);
        };
    }, [isResizing, resizeDirection]);

    // 최대화 토글
    const toggleMaximize = useCallback(() => {
        if (isMaximized) {
            // 복원
            if (preMaximizeStateRef.current) {
                setPosition(preMaximizeStateRef.current.position);
                setSize(preMaximizeStateRef.current.size);
            }
            setIsMaximized(false);
        } else {
            // 최대화 전 상태 저장
            preMaximizeStateRef.current = { position, size };
            setPosition({ x: 0, y: 0 });
            setSize({ width: window.innerWidth, height: window.innerHeight });
            setIsMaximized(true);
        }
    }, [isMaximized, position, size]);

    // 데이터 새로고침
    const refreshData = useCallback(() => {
        if (!devTools.isEnabled()) return;

        setState(devTools.getState());
        setActions(devTools.getActionHistory());
        setCacheStats(devTools.getCacheStats());
        setIsConnected(serverConnector.isConnected());
    }, [devTools, serverConnector]);

    // 키보드 단축키 (Ctrl+Shift+G)
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.ctrlKey && e.shiftKey && e.key === 'G') {
                e.preventDefault();
                setIsOpen(prev => !prev);
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, []);

    // 초기 데이터 로드 및 상태 변경 구독
    useEffect(() => {
        if (!isOpen || !devTools.isEnabled()) return;

        // 초기 데이터 로드
        refreshData();

        // 상태 변경 구독
        const unsubscribeState = devTools.watchState('*', () => {
            setState(devTools.getState());
        });

        // 액션 변경 구독
        const unsubscribeActions = devTools.watchActions(() => {
            setActions(devTools.getActionHistory());
            setCacheStats(devTools.getCacheStats());
        });

        // 주기적 갱신 (5초)
        const intervalId = setInterval(refreshData, 5000);

        return () => {
            unsubscribeState();
            unsubscribeActions();
            clearInterval(intervalId);
        };
    }, [isOpen, devTools, refreshData]);

    // 서버로 상태 덤프
    const handleDumpState = async () => {
        try {
            const path = await serverConnector.dumpState();
            console.log('[G7DevTools] 상태 덤프 완료:', path);
            setIsConnected(true);
        } catch (error) {
            console.error('[G7DevTools] 상태 덤프 실패:', error);
            setIsConnected(false);
        }
    };

    // DevTools가 비활성화된 경우
    if (!devTools.isEnabled()) {
        if (!isOpen) return null;

        return (
            <div className="g7dt-disabled-notice">
                <div className="g7dt-flex g7dt-items-center g7dt-justify-between g7dt-mb-2">
                    <span className="g7dt-font-bold g7dt-text-yellow">G7 DevTools</span>
                    <button
                        onClick={() => setIsOpen(false)}
                        className="g7dt-btn"
                    >
                        ✕
                    </button>
                </div>
                <p className="g7dt-text-sm g7dt-text-gray">
                    DevTools가 비활성화되어 있습니다.
                </p>
                <p className="g7dt-text-xs g7dt-text-gray-dark g7dt-mt-2">
                    환경설정 &gt; 고급 설정 &gt; 디버그 모드를 켜세요.
                </p>
            </div>
        );
    }

    // 닫힌 상태 - 플로팅 버튼만 표시
    if (!isOpen) {
        return (
            <button
                onClick={() => setIsOpen(true)}
                className="g7dt-floating-btn"
                title="G7 DevTools (Ctrl+Shift+G)"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeWidth={2}
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
                    />
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeWidth={2}
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                    />
                </svg>
            </button>
        );
    }

    // 최소화 상태
    if (isMinimized) {
        return (
            <div className="g7dt-minimized">
                <div className="g7dt-minimized-content">
                    <span className="g7dt-font-bold g7dt-text-sm">G7 DevTools</span>
                    <button
                        onClick={() => setIsMinimized(false)}
                        className="g7dt-btn g7dt-text-xs"
                        title="확장"
                    >
                        ▲
                    </button>
                    <button
                        onClick={() => setIsOpen(false)}
                        className="g7dt-btn g7dt-text-xs"
                    >
                        ✕
                    </button>
                </div>
            </div>
        );
    }

    // 메인 패널
    return (
        <div
            ref={panelRef}
            className={`g7dt-panel ${isMaximized ? 'maximized' : ''} ${isResizing ? 'g7dt-resizing' : ''}`}
            style={{
                left: `${position.x >= 0 ? position.x : 0}px`,
                top: `${position.y >= 0 ? position.y : 0}px`,
                width: `${size.width}px`,
                height: `${size.height}px`,
            }}
        >
            {/* 리사이즈 핸들들 - 최대화 상태에서는 숨김 */}
            {!isMaximized && (
                <>
                    {/* 모서리 핸들 */}
                    <div
                        className="g7dt-resize-handle g7dt-resize-corner g7dt-resize-nw"
                        onMouseDown={handleResizeStart('nw')}
                    />
                    <div
                        className="g7dt-resize-handle g7dt-resize-corner g7dt-resize-ne"
                        onMouseDown={handleResizeStart('ne')}
                    />
                    <div
                        className="g7dt-resize-handle g7dt-resize-corner g7dt-resize-sw"
                        onMouseDown={handleResizeStart('sw')}
                    />
                    <div
                        className="g7dt-resize-handle g7dt-resize-corner g7dt-resize-se"
                        onMouseDown={handleResizeStart('se')}
                    />
                    {/* 가장자리 핸들 */}
                    <div
                        className="g7dt-resize-handle g7dt-resize-edge-h g7dt-resize-n"
                        onMouseDown={handleResizeStart('n')}
                    />
                    <div
                        className="g7dt-resize-handle g7dt-resize-edge-h g7dt-resize-s"
                        onMouseDown={handleResizeStart('s')}
                    />
                    <div
                        className="g7dt-resize-handle g7dt-resize-edge-v g7dt-resize-w"
                        onMouseDown={handleResizeStart('w')}
                    />
                    <div
                        className="g7dt-resize-handle g7dt-resize-edge-v g7dt-resize-e"
                        onMouseDown={handleResizeStart('e')}
                    />
                </>
            )}
            {/* 헤더 (드래그 핸들) */}
            <div
                className={`g7dt-header ${isDragging ? 'dragging' : ''}`}
                onMouseDown={handleDragStart}
            >
                <div className="g7dt-header-left">
                    <span className="g7dt-header-title">G7 DevTools</span>
                    <span
                        className={`g7dt-header-badge ${isConnected ? 'connected' : ''}`}
                        title={isConnected ? '서버 덤프 성공' : '서버에 덤프되지 않음 (덤프 버튼 클릭)'}
                    >
                        {isConnected ? '덤프 완료' : '로컬 모드'}
                    </span>
                </div>
                <div className="g7dt-header-right">
                    <button
                        onClick={handleDumpState}
                        className="g7dt-btn g7dt-btn-primary"
                        title="서버로 상태 덤프"
                    >
                        덤프
                    </button>
                    <button
                        onClick={refreshData}
                        className="g7dt-btn"
                        title="새로고침"
                    >
                        ↻
                    </button>
                    <button
                        onClick={toggleMaximize}
                        className="g7dt-btn"
                        title={isMaximized ? '복원' : '최대화'}
                    >
                        {isMaximized ? '❐' : '□'}
                    </button>
                    <button
                        onClick={() => setIsMinimized(true)}
                        className="g7dt-btn"
                        title="최소화"
                    >
                        ▼
                    </button>
                    <button
                        onClick={() => setIsOpen(false)}
                        className="g7dt-btn"
                    >
                        ✕
                    </button>
                </div>
            </div>

            {/* 탭 */}
            <div className="g7dt-tabs">
                {(Object.keys(TAB_LABELS) as TabType[]).map(tab => (
                    <button
                        key={tab}
                        onClick={() => setActiveTab(tab)}
                        className={`g7dt-tab ${activeTab === tab ? 'active' : ''}`}
                    >
                        {TAB_LABELS[tab]}
                    </button>
                ))}
            </div>

            {/* 내용 */}
            <div className="g7dt-content">
                {activeTab === 'state' && <StateTab state={state} devTools={devTools} />}
                {activeTab === 'actions' && <ActionsTab actions={actions} devTools={devTools} />}
                {activeTab === 'cache' && <CacheTab stats={cacheStats} devTools={devTools} />}
                {activeTab === 'diagnose' && <DiagnoseTab />}
                {activeTab === 'performance' && <PerformanceTab devTools={devTools} />}
                {activeTab === 'lifecycle' && <LifecycleTab devTools={devTools} />}
                {activeTab === 'network' && <NetworkTab devTools={devTools} />}
                {activeTab === 'form' && <FormTab devTools={devTools} />}
                {activeTab === 'conditional' && <ConditionalTab devTools={devTools} />}
                {activeTab === 'expression' && <ExpressionTab devTools={devTools} />}
                {activeTab === 'handlers' && <HandlersTab devTools={devTools} />}
                {activeTab === 'events' && <EventsTab devTools={devTools} />}
                {activeTab === 'datasources' && <DataSourcesTab devTools={devTools} />}
                {activeTab === 'renders' && <RendersTab devTools={devTools} />}
                {activeTab === 'styles' && <StylesTab devTools={devTools} />}
                {activeTab === 'auth' && <AuthTab devTools={devTools} />}
                {activeTab === 'logs' && <LogsTab devTools={devTools} />}
                {activeTab === 'layout' && <LayoutTab devTools={devTools} />}
                {activeTab === 'changedetection' && <ChangeDetectionTab devTools={devTools} />}
                {activeTab === 'staleclosure' && <StaleClosureTab devTools={devTools} />}
                {activeTab === 'nestedcontext' && <NestedContextTab devTools={devTools} />}
                {activeTab === 'modalscope' && <ModalScopeTab devTools={devTools} />}
                {activeTab === 'namedactions' && <NamedActionsTab devTools={devTools} />}
            </div>

            {/* 푸터 */}
            <div className="g7dt-footer">
                <span>Ctrl+Shift+G 토글 | 헤더 드래그로 이동</span>
                <span>
                    액션: {actions.length} | 캐시 히트율: {(cacheStats.hitRate * 100).toFixed(1)}%
                </span>
            </div>
        </div>
    );
};

export default DevToolsPanel;
