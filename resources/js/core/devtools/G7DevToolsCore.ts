/**
 * G7 DevTools Core
 *
 * 그누보드7 템플릿 엔진 디버깅 시스템의 핵심 싱글톤 클래스
 * 상태 추적, 액션 로깅, 캐시 통계, 라이프사이클 모니터링 등을 담당합니다.
 *
 * 활성화 조건: 환경설정 > 고급 설정 > 디버그 모드 (debug_mode)
 */

import type {
    StateSnapshot,
    StateView,
    StateDiff,
    ActionLog,
    ActionMetrics,
    CacheStats,
    ComponentInfo,
    ListenerInfo,
    LifecycleInfo,
    MountEvent,
    UnmountEvent,
    PerformanceInfo,
    MemoryWarning,
    ProfileEntry,
    ProfileReport,
    RequestInfo,
    RequestLog,
    NetworkInfo,
    ConditionInfo,
    IterationInfo,
    ConditionalInfo,
    ScopeVariable,
    FormInfo,
    InputInfo,
    FormChange,
    WebSocketConnectionInfo,
    WebSocketMessage,
    WebSocketInfo,
    WatcherCallback,
    ActionWatcherCallback,
    RequestWatcherCallback,
    WebSocketWatcherCallback,
    FormWatcherCallback,
    ConditionWatcherCallback,
    DevToolsConfig,
    ExpressionEvalInfo,
    ExpressionWarning,
    ExpressionWarningType,
    ExpressionWatcherCallback,
    ExpressionStats,
    DataSourceInfo,
    HandlerInfo,
    ComponentEventSubscription,
    ComponentEventEmitLog,
    ComponentEventInfo,
    StateRenderingLog,
    RenderedComponentInfo,
    StateRenderingInfo,
    StateRenderingStats,
    StateChangeContext,
    StateHierarchyInfo,
    StateHierarchyLayer,
    StateConflict,
    ComponentStateSource,
    ContextFlowInfo,
    ContextFlowNode,
    StyleValidationInfo,
    StyleIssue,
    ComponentStyleInfo,
    AuthDebugInfo,
    AuthStateInfo,
    AuthEventLog,
    AuthHeaderAnalysis,
    AuthEventType,
    LogLevel,
    LogEntry,
    LogStats,
    LogFilterOptions,
    LogInfo,
    ParsedQueryParams,
    CurrentLayoutInfo,
    LayoutJsonData,
    LayoutHistoryEntry,
    LayoutDebugInfo,
    // 변경 감지 타입
    HandlerExitReason,
    HandlerExecutionDetail,
    StateChangeRecord,
    DataSourceChangeRecord,
    ChangeExpectation,
    ChangeAlert,
    ChangeAlertType,
    ChangeDetectionInfo,
    ChangeDetectionStats,
    // Sequence 추적 타입
    SequenceActionInfo,
    SequenceExecutionInfo,
    SequenceTrackingInfo,
    SequenceStats,
    // Stale Closure 추적 타입
    StaleClosureWarning,
    StaleClosureWarningType,
    StaleClosureTrackingInfo,
    StaleClosureStats,
    // Form 바인딩 검증 타입
    FormBindingIssue,
    FormBindingIssueType,
    FormBindingValidationInfo,
    FormBindingValidationStats,
    FormBindingValidationTrackingInfo,
    // Computed 의존성 추적 타입
    ComputedDependencyType,
    ComputedRecalcTrigger,
    ComputedPropertyInfo,
    ComputedRecalcLog,
    ComputedDependencyChain,
    ComputedDependencyNode,
    ComputedDependencyStats,
    ComputedDependencyTrackingInfo,
    // 모달 상태 스코프 추적 타입
    ModalStateScopeType,
    ModalStateIssueType,
    ModalStateInfo,
    ModalStateIssue,
    ModalStateRelation,
    ModalStateChangeLog,
    ModalStateScopeStats,
    ModalStateScopeTrackingInfo,
    // Named Actions 추적 타입
    NamedActionRefLog,
    NamedActionTrackingInfo,
} from './types';
import { parseQueryParams, extractPath } from './types';

/**
 * G7 DevTools 핵심 클래스
 */
export class G7DevToolsCore {
    // ============================================
    // 싱글톤
    // ============================================
    private static instance: G7DevToolsCore;

    static getInstance(): G7DevToolsCore {
        if (!G7DevToolsCore.instance) {
            G7DevToolsCore.instance = new G7DevToolsCore();
        }
        return G7DevToolsCore.instance;
    }

    private constructor() {
        // private constructor for singleton
    }

    // ============================================
    // 설정
    // ============================================
    private config: DevToolsConfig = {
        enabled: false,
        maxHistorySize: 100,
        logLevel: 'info',
        serverEndpoint: '/_boost/g7-debug/dump-state',
        autoCapture: true,
    };

    // ============================================
    // 상태 관리
    // ============================================
    private stateHistory: StateSnapshot[] = [];
    private snapshotIdCounter = 0;
    private stateWatchers: Map<string, Set<WatcherCallback>> = new Map();

    // _local과 _computed 상태를 별도로 저장
    private currentLocalState: Record<string, any> = {};
    private currentComputedState: Record<string, any> = {};
    // $parent 컨텍스트 저장 (모달/자식 레이아웃에서 부모 상태 접근용)
    private currentParentContext: Record<string, any> | undefined = undefined;

    // ============================================
    // 액션 추적
    // ============================================
    private actionHistory: ActionLog[] = [];
    private actionWatchers: Set<ActionWatcherCallback> = new Set();

    // ============================================
    // 캐시 통계
    // ============================================
    private cacheStats: CacheStats = { hits: 0, misses: 0, entries: 0 };

    // ============================================
    // 캐시 결정 추적
    // ============================================
    private cacheDecisions: import('./types').CacheDecisionInfo[] = [];
    private cacheDecisionIdCounter = 0;
    private readonly maxCacheDecisions = 200;
    private currentRenderCycleId: string | null = null;
    private isInActionExecution = false;
    private isInIteration = false;

    // ============================================
    // 라이프사이클 추적
    // ============================================
    private mountedComponents: Map<string, ComponentInfo> = new Map();
    private eventListeners: Map<string, ListenerInfo[]> = new Map();
    private mountWatchers: Set<(info: MountEvent) => void> = new Set();
    private unmountWatchers: Set<(info: UnmountEvent) => void> = new Set();

    // ============================================
    // 성능 추적
    // ============================================
    private renderCounts: Map<string, number> = new Map();
    private bindingEvalCount = 0;
    private profilingData: ProfileEntry[] = [];
    private isProfiling = false;
    private profilingStartTime = 0;

    // ============================================
    // 네트워크 추적
    // ============================================
    private activeRequests: Map<string, RequestInfo> = new Map();
    private requestHistory: RequestLog[] = [];
    private pendingDataSources: Set<string> = new Set();
    private requestWatchers: Set<RequestWatcherCallback> = new Set();

    // ============================================
    // WebSocket 추적
    // ============================================
    private wsConnections: Map<string, WebSocketConnectionInfo> = new Map();
    private wsMessageHistory: WebSocketMessage[] = [];
    private wsMessageWatchers: Set<WebSocketWatcherCallback> = new Set();

    // ============================================
    // 조건부 렌더링 추적
    // ============================================
    private ifConditions: Map<string, ConditionInfo> = new Map();
    private iterations: Map<string, IterationInfo> = new Map();
    private conditionWatchers: Set<ConditionWatcherCallback> = new Set();

    // ============================================
    // Form 추적
    // ============================================
    private forms: Map<string, FormInfo> = new Map();
    private formWatchers: Set<FormWatcherCallback> = new Set();

    // ============================================
    // 표현식 추적
    // ============================================
    private expressionHistory: ExpressionEvalInfo[] = [];
    private expressionWatchers: Set<ExpressionWatcherCallback> = new Set();
    private expressionIdCounter = 0;

    // ============================================
    // 데이터소스 추적
    // ============================================
    private dataSources: Map<string, DataSourceInfo> = new Map();

    // ============================================
    // 데이터 경로 변환 추적
    // ============================================
    private dataPathTransforms: import('./types').DataPathTransformInfo[] = [];
    private readonly maxDataPathTransforms = 100;

    // ============================================
    // Nested Context 추적
    // ============================================
    private nestedContexts: import('./types').NestedContextInfo[] = [];
    private nestedContextIdCounter = 0;
    private readonly maxNestedContexts = 100;

    // ============================================
    // Form 바인딩 검증 추적
    // ============================================
    private formBindingIssues: FormBindingIssue[] = [];
    private formBindingValidations: FormBindingValidationInfo[] = [];
    private formBindingIssueIdCounter = 0;
    private readonly maxFormBindingIssues = 100;

    // ============================================
    // Computed 의존성 추적
    // ============================================
    private computedProperties: Map<string, ComputedPropertyInfo> = new Map();
    private computedRecalcLogs: ComputedRecalcLog[] = [];
    private computedRecalcIdCounter = 0;
    private readonly maxComputedRecalcLogs = 100;

    // ============================================
    // 핸들러 추적
    // ============================================
    private handlers: Map<string, HandlerInfo> = new Map();

    // ============================================
    // 컴포넌트 이벤트 추적
    // ============================================
    private componentEventSubscriptions: Map<string, ComponentEventSubscription> = new Map();
    private componentEventEmitHistory: ComponentEventEmitLog[] = [];
    private componentEventIdCounter = 0;
    private readonly maxComponentEventHistory = 100;

    // ============================================
    // 상태-렌더링 추적
    // ============================================
    private stateRenderingLogs: StateRenderingLog[] = [];
    private currentStateChangeContext: StateChangeContext | null = null;
    private stateRenderingIdCounter = 0;
    private componentRenderCounts: Map<string, number> = new Map();
    private stateToComponentMap: Map<string, Set<string>> = new Map();
    private readonly maxStateRenderingLogs = 100;

    // ============================================
    // 상태 계층 추적
    // ============================================
    private componentStateSources: Map<string, ComponentStateSource> = new Map();
    private contextFlowNodes: Map<string, ContextFlowNode> = new Map();
    private dynamicStates: Map<string, Record<string, any>> = new Map();

    // ============================================
    // CSS 스타일 검증 추적
    // ============================================
    private styleIssues: StyleIssue[] = [];
    private componentStyles: Map<string, ComponentStyleInfo> = new Map();

    // ============================================
    // 인증 디버깅 추적
    // ============================================
    private authEvents: AuthEventLog[] = [];
    private authHeaderHistory: AuthHeaderAnalysis[] = [];
    private authEventIdCounter = 0;
    private readonly maxAuthEventHistory = 50;

    // ============================================
    // 로그 추적
    // ============================================
    private logHistory: LogEntry[] = [];
    private logIdCounter = 0;
    private readonly maxLogHistory = 500;

    // ============================================
    // 레이아웃 추적
    // ============================================
    private currentLayout: CurrentLayoutInfo | null = null;
    private layoutHistory: LayoutHistoryEntry[] = [];
    private layoutIdCounter = 0;
    private layoutStats = { totalLoads: 0, cacheHits: 0, apiLoads: 0 };
    private readonly maxLayoutHistory = 50;

    // ============================================
    // 변경 감지 추적
    // ============================================
    private executionDetails: Map<string, HandlerExecutionDetail> = new Map();
    private stateChangeHistory: StateChangeRecord[] = [];
    private dataSourceChangeHistory: DataSourceChangeRecord[] = [];
    private changeAlerts: ChangeAlert[] = [];
    private changeExpectations: Map<string, ChangeExpectation> = new Map();
    private executionIdCounter = 0;
    private stateChangeIdCounter = 0;
    private alertIdCounter = 0;
    private readonly maxExecutionDetails = 100;
    private readonly maxStateChangeHistory = 200;
    private readonly maxChangeAlerts = 100;

    // ============================================
    // Sequence 실행 추적
    // ============================================
    private sequenceExecutions: SequenceExecutionInfo[] = [];
    /** 실행 중인 sequence 스택 (중첩 sequence 지원) */
    private sequenceExecutionStack: SequenceExecutionInfo[] = [];
    private sequenceIdCounter = 0;
    private readonly maxSequenceExecutions = 50;

    // ============================================
    // Stale Closure 감지 추적
    // ============================================
    private staleClosureWarnings: StaleClosureWarning[] = [];
    private staleClosureIdCounter = 0;
    private readonly maxStaleClosureWarnings = 100;
    /** 상태 캡처 시점 추적 (handlerId → { path: captureTime }) */
    private stateCaptureRegistry: Map<string, Map<string, { value: any; capturedAt: number }>> = new Map();

    // ============================================
    // 모달 상태 스코프 추적
    // ============================================
    private modalStates: Map<string, ModalStateInfo> = new Map();
    private modalStateIssues: ModalStateIssue[] = [];
    private modalStateRelations: ModalStateRelation[] = [];
    private modalStateChangeLogs: ModalStateChangeLog[] = [];
    private modalStateIssueIdCounter = 0;
    private modalStateChangeLogIdCounter = 0;
    private readonly maxModalStateIssues = 100;
    private readonly maxModalStateChangeLogs = 200;

    // ============================================
    // Named Actions 추적
    // ============================================
    /** 현재 레이아웃의 named_actions 정의 */
    private namedActionDefinitions: Record<string, any> = {};
    /** actionRef 해석 이력 */
    private namedActionRefLogs: NamedActionRefLog[] = [];
    private namedActionRefIdCounter = 0;
    private readonly maxNamedActionRefLogs = 200;

    // ============================================
    // 초기화 및 활성화
    // ============================================

    /**
     * DevTools 초기화
     * settings 로드 완료 후 호출해야 합니다.
     */
    initialize(): void {
        this.config.enabled = this.checkDebugMode();

        if (!this.config.enabled) {
            console.log('[G7DevTools] 비활성화됨 (환경설정 > 고급 설정 > 디버그 모드를 켜세요)');
            return;
        }

        console.log('[G7DevTools] 활성화됨');
        this.setupGlobalErrorHandler();
    }

    /**
     * 디버그 모드 확인
     *
     * 다음 순서로 디버그 모드를 확인합니다:
     * 1. G7Config.debug (initTemplateApp의 debug 옵션)
     * 2. _global.settings.advanced.debug_mode (서버 환경설정)
     */
    private checkDebugMode(): boolean {
        try {
            // 1. initTemplateApp의 debug 옵션 확인 (G7Config.debug)
            const g7Config = (window as any).G7Config;
            if (g7Config?.debug === true) {
                return true;
            }

            // 2. 서버 환경설정 확인 (settings.advanced.debug_mode)
            const g7Core = (window as any).G7Core;
            const state = g7Core?.state?.get?.();
            if (state?.settings?.advanced?.debug_mode === true) {
                return true;
            }

            // 3. _global.settings.advanced.debug_mode 확인
            if (state?._global?.settings?.advanced?.debug_mode === true) {
                return true;
            }

            return false;
        } catch {
            return false;
        }
    }

    /**
     * DevTools 활성화 상태 확인
     */
    isEnabled(): boolean {
        return this.config.enabled;
    }

    /**
     * 전역 에러 핸들러 설정
     */
    private setupGlobalErrorHandler(): void {
        window.addEventListener('error', (event) => {
            if (!this.config.enabled) return;

            this.logAction({
                id: this.generateId(),
                type: 'globalError',
                startTime: Date.now(),
                status: 'error',
                error: {
                    name: 'Error',
                    message: event.message,
                    stack: event.error?.stack,
                },
            });
        });

        window.addEventListener('unhandledrejection', (event) => {
            if (!this.config.enabled) return;

            this.logAction({
                id: this.generateId(),
                type: 'unhandledRejection',
                startTime: Date.now(),
                status: 'error',
                error: {
                    name: 'UnhandledRejection',
                    message: String(event.reason),
                },
            });
        });
    }

    // ============================================
    // 상태 관리 메서드
    // ============================================

    /**
     * 상태 스냅샷 캡처
     */
    captureStateSnapshot(data: {
        source: string;
        prev: Record<string, any>;
        next: Record<string, any>;
        diff?: StateDiff;
    }): void {
        if (!this.config.enabled) return;

        const snapshot: StateSnapshot = {
            id: ++this.snapshotIdCounter,
            timestamp: Date.now(),
            source: data.source,
            prev: this.sanitizeObject(data.prev),
            next: this.sanitizeObject(data.next),
            diff: data.diff,
        };

        this.stateHistory.push(snapshot);

        // 이력 크기 제한
        if (this.stateHistory.length > this.config.maxHistorySize) {
            this.stateHistory.shift();
        }

        // 감시자 알림
        this.notifyStateWatchers(snapshot);
    }

    /**
     * 현재 상태 조회
     *
     * _global: G7Core.state.get()에서 가져옴
     * _local: DynamicRenderer에서 updateLocalState()로 등록된 상태
     * _computed: DynamicRenderer에서 updateComputedState()로 등록된 상태
     * _isolated: IsolatedStateProvider에서 등록된 격리 상태 (G7Core._isolatedStates)
     * $parent: 모달/자식 레이아웃에서 부모 상태 접근용 컨텍스트
     */
    getState(): StateView {
        try {
            const g7Core = (window as any).G7Core;
            const state = g7Core?.state?.get?.() || {};

            // _isolated 상태 수집 (IsolatedStateContext에서 등록)
            const isolated: Record<string, Record<string, any>> = {};
            if (g7Core?._isolatedStates && typeof g7Core._isolatedStates === 'object') {
                for (const [scopeId, scopeState] of Object.entries(g7Core._isolatedStates)) {
                    isolated[scopeId] = this.sanitizeObject(scopeState as Record<string, any>);
                }
            }

            return {
                _global: state._global || state,
                _local: this.currentLocalState,
                _computed: this.currentComputedState,
                _isolated: Object.keys(isolated).length > 0 ? isolated : undefined,
                $parent: this.currentParentContext,
            };
        } catch {
            return { _global: {}, _local: this.currentLocalState, _computed: this.currentComputedState };
        }
    }

    /**
     * _local 상태 업데이트
     *
     * DynamicRenderer에서 localDynamicState가 변경될 때 호출됩니다.
     */
    updateLocalState(localState: Record<string, any>): void {
        if (!this.config.enabled) return;
        this.currentLocalState = this.sanitizeObject(localState);
    }

    /**
     * _computed 상태 업데이트
     *
     * DynamicRenderer에서 _computed 컨텍스트가 변경될 때 호출됩니다.
     */
    updateComputedState(computedState: Record<string, any>): void {
        if (!this.config.enabled) return;
        this.currentComputedState = this.sanitizeObject(computedState);
    }

    /**
     * $parent 컨텍스트 업데이트
     *
     * DynamicRenderer에서 모달/자식 레이아웃 렌더링 시 부모 데이터 컨텍스트를 전달합니다.
     * 이를 통해 DevTools에서 {{$parent._local.xxx}} 형태의 바인딩 디버깅이 가능합니다.
     *
     * @param parentContext 부모 데이터 컨텍스트 (undefined이면 $parent 없음)
     */
    updateParentContext(parentContext: Record<string, any> | undefined): void {
        if (!this.config.enabled) return;
        this.currentParentContext = parentContext ? this.sanitizeObject(parentContext) : undefined;
    }

    /**
     * 상태 이력 조회
     */
    getStateHistory(): StateSnapshot[] {
        return [...this.stateHistory];
    }

    /**
     * 저장소 A(React localDynamicState) / B(globalState._local) 불일치 감지
     *
     * 이중 저장소 구조(engine-v1.43.0+)의 보조 안전망.
     * 엔진의 자동 동기화(DynamicRenderer performStateUpdate + setLocal 자동 승격)가 기본 방어책이지만,
     * 새로운 쓰기 경로가 추가되면서 한쪽 저장소만 갱신하는 실수를 조기 발견하기 위한 진단 도구.
     *
     * 저장소 A = `updateLocalState()` 로 전달된 localDynamicState
     * 저장소 B = `G7Core.state.get()._local` = globalState._local
     *
     * 불일치 leaf 경로를 반환 (hasMismatch=true면 구조적 동기화가 깨진 상태).
     *
     * @returns 불일치 상태 + 두 저장소 스냅샷
     */
    getDualStorageMismatch(): {
        hasMismatch: boolean;
        mismatchedPaths: string[];
        storageA: Record<string, any>;
        storageB: Record<string, any>;
    } {
        const storageA = this.currentLocalState || {};
        let storageB: Record<string, any> = {};
        try {
            const g7Core = (window as any).G7Core;
            const globalState = g7Core?.state?.get?.() || {};
            storageB = globalState._local || {};
        } catch {
            storageB = {};
        }

        const mismatchedPaths = this.findMismatchedLeafPaths(storageA, storageB);
        return {
            hasMismatch: mismatchedPaths.length > 0,
            mismatchedPaths,
            storageA,
            storageB,
        };
    }

    /**
     * 두 객체의 리프 경로를 비교해 값이 다른 경로 목록 반환 (private helper)
     *
     * `getDualStorageMismatch` 전용. 배열은 리프로 취급하되 참조 비교가 아니라
     * JSON 문자열화 비교로 값 동등성 판정 (deep equal 수준 빠른 근사).
     *
     * @param a 비교 대상 A
     * @param b 비교 대상 B
     * @param prefix 재귀 prefix
     * @returns 불일치 경로 배열
     */
    private findMismatchedLeafPaths(
        a: Record<string, any>,
        b: Record<string, any>,
        prefix = '',
    ): string[] {
        const paths: string[] = [];
        const keys = new Set([
            ...Object.keys(a || {}),
            ...Object.keys(b || {}),
        ]);

        for (const key of keys) {
            const fullKey = prefix ? `${prefix}.${key}` : key;
            const va = a?.[key];
            const vb = b?.[key];
            const aIsObj = va && typeof va === 'object' && !Array.isArray(va);
            const bIsObj = vb && typeof vb === 'object' && !Array.isArray(vb);

            if (aIsObj && bIsObj) {
                paths.push(...this.findMismatchedLeafPaths(va, vb, fullKey));
            } else {
                const sameA = va === undefined ? '__undefined__' : JSON.stringify(va);
                const sameB = vb === undefined ? '__undefined__' : JSON.stringify(vb);
                if (sameA !== sameB) {
                    paths.push(fullKey);
                }
            }
        }

        return paths;
    }

    /**
     * 상태 감시 등록
     */
    watchState(path: string, callback: WatcherCallback): () => void {
        if (!this.stateWatchers.has(path)) {
            this.stateWatchers.set(path, new Set());
        }
        this.stateWatchers.get(path)!.add(callback);

        return () => {
            this.stateWatchers.get(path)?.delete(callback);
        };
    }

    /**
     * 상태 감시자들에게 알림
     */
    private notifyStateWatchers(snapshot: StateSnapshot): void {
        // '*' 감시자
        this.stateWatchers.get('*')?.forEach((cb) => {
            try {
                cb(snapshot.next, snapshot.prev, '*');
            } catch (e) {
                console.error('[G7DevTools] State watcher error:', e);
            }
        });

        // 특정 경로 감시자
        if (snapshot.diff) {
            for (const change of snapshot.diff.changed) {
                this.stateWatchers.get(change.path)?.forEach((cb) => {
                    try {
                        cb(change.newValue, change.oldValue, change.path);
                    } catch (e) {
                        console.error('[G7DevTools] State watcher error:', e);
                    }
                });
            }
        }
    }

    /**
     * 두 스냅샷 간 차이 계산
     */
    diffSnapshots(snapshot1Id: number, snapshot2Id: number): StateDiff | null {
        const s1 = this.stateHistory.find((s) => s.id === snapshot1Id);
        const s2 = this.stateHistory.find((s) => s.id === snapshot2Id);

        if (!s1 || !s2) return null;

        return this.calculateDiff(s1.next, s2.next);
    }

    /**
     * 상태 차이 계산
     */
    private calculateDiff(
        prev: Record<string, any>,
        next: Record<string, any>,
        prefix = ''
    ): StateDiff {
        const diff: StateDiff = { added: [], removed: [], changed: [] };

        const prevKeys = new Set(Object.keys(prev));
        const nextKeys = new Set(Object.keys(next));

        for (const key of nextKeys) {
            const path = prefix ? `${prefix}.${key}` : key;
            if (!prevKeys.has(key)) {
                diff.added.push(path);
            } else if (JSON.stringify(prev[key]) !== JSON.stringify(next[key])) {
                diff.changed.push({
                    path,
                    oldValue: prev[key],
                    newValue: next[key],
                });
            }
        }

        for (const key of prevKeys) {
            const path = prefix ? `${prefix}.${key}` : key;
            if (!nextKeys.has(key)) {
                diff.removed.push(path);
            }
        }

        return diff;
    }

    // ============================================
    // 액션 추적 메서드
    // ============================================

    /**
     * 액션 로그 기록
     */
    logAction(action: ActionLog): void {
        if (!this.config.enabled) return;

        // 기존 액션 업데이트 또는 새 액션 추가
        const existingIndex = this.actionHistory.findIndex((a) => a.id === action.id);

        if (existingIndex >= 0) {
            this.actionHistory[existingIndex] = {
                ...this.actionHistory[existingIndex],
                ...action,
            };
        } else {
            this.actionHistory.push(action);

            // 이력 크기 제한
            if (this.actionHistory.length > this.config.maxHistorySize) {
                this.actionHistory.shift();
            }
        }

        // 프로파일링 중이면 기록
        if (this.isProfiling) {
            this.profilingData.push({
                type: 'action',
                action: action.type,
                timestamp: performance.now(),
                duration: action.duration,
            });
        }

        // 감시자 알림
        this.notifyActionWatchers(action);
    }

    /**
     * 액션 이력 조회
     */
    getActionHistory(): ActionLog[] {
        return [...this.actionHistory];
    }

    /**
     * 액션 감시 등록
     */
    watchActions(callback: ActionWatcherCallback): () => void {
        this.actionWatchers.add(callback);
        return () => {
            this.actionWatchers.delete(callback);
        };
    }

    /**
     * 액션 감시자들에게 알림
     */
    private notifyActionWatchers(action: ActionLog): void {
        this.actionWatchers.forEach((cb) => {
            try {
                cb(action);
            } catch (e) {
                console.error('[G7DevTools] Action watcher error:', e);
            }
        });
    }

    /**
     * 액션 메트릭 조회
     */
    getActionMetrics(): ActionMetrics {
        const successActions = this.actionHistory.filter((a) => a.status === 'success');
        const errorActions = this.actionHistory.filter((a) => a.status === 'error');

        const actionsByType: Record<string, number> = {};
        for (const action of this.actionHistory) {
            actionsByType[action.type] = (actionsByType[action.type] || 0) + 1;
        }

        const totalDuration = successActions.reduce((sum, a) => sum + (a.duration || 0), 0);

        return {
            totalActions: this.actionHistory.length,
            successCount: successActions.length,
            errorCount: errorActions.length,
            averageDuration: successActions.length > 0 ? totalDuration / successActions.length : 0,
            actionsByType,
        };
    }

    // ============================================
    // 캐시 통계 메서드
    // ============================================

    /**
     * 캐시 히트 기록
     */
    recordCacheHit(): void {
        if (!this.config.enabled) return;
        this.cacheStats.hits++;
    }

    /**
     * 캐시 미스 기록
     */
    recordCacheMiss(): void {
        if (!this.config.enabled) return;
        this.cacheStats.misses++;
    }

    /**
     * 캐시 엔트리 수 업데이트
     */
    updateCacheEntries(count: number): void {
        if (!this.config.enabled) return;
        this.cacheStats.entries = count;
    }

    /**
     * 캐시 통계 조회
     */
    getCacheStats(): CacheStats {
        const total = this.cacheStats.hits + this.cacheStats.misses;
        return {
            ...this.cacheStats,
            hitRate: total > 0 ? this.cacheStats.hits / total : 0,
        };
    }

    /**
     * 캐시 통계 초기화
     */
    resetCacheStats(): void {
        this.cacheStats = { hits: 0, misses: 0, entries: 0 };
    }

    // ============================================
    // 캐시 결정 추적 메서드
    // ============================================

    /**
     * 캐시 결정 추적
     *
     * 표현식 평가 시 캐시 사용/스킵 결정 과정을 기록합니다.
     */
    trackCacheDecision(info: {
        expression: string;
        decision: import('./types').CacheDecisionType;
        reason: string;
        componentId?: string;
        skipCacheOption?: boolean;
        cachedValue?: any;
        freshValue?: any;
        duration?: number;
    }): void {
        if (!this.config.enabled) return;

        const decision: import('./types').CacheDecisionInfo = {
            id: `cache-${++this.cacheDecisionIdCounter}`,
            timestamp: Date.now(),
            expression: info.expression,
            decision: info.decision,
            reason: info.reason,
            context: {
                isInIteration: this.isInIteration,
                isInAction: this.isInActionExecution,
                renderCycleId: this.currentRenderCycleId || undefined,
                componentId: info.componentId,
                skipCacheOption: info.skipCacheOption,
            },
            cachedValue: info.cachedValue !== undefined ? this.safeClone(info.cachedValue) : undefined,
            freshValue: info.freshValue !== undefined ? this.safeClone(info.freshValue) : undefined,
            valueMatch: info.cachedValue !== undefined && info.freshValue !== undefined
                ? JSON.stringify(info.cachedValue) === JSON.stringify(info.freshValue)
                : undefined,
            duration: info.duration,
        };

        this.cacheDecisions.push(decision);

        // 최대 크기 제한
        if (this.cacheDecisions.length > this.maxCacheDecisions) {
            this.cacheDecisions.shift();
        }

        // 캐시 통계도 업데이트
        if (info.decision === 'cache_hit') {
            this.cacheStats.hits++;
        } else if (info.decision === 'cache_miss' || info.decision === 'skip_cache') {
            this.cacheStats.misses++;
        }
    }

    /**
     * 렌더 사이클 시작 표시
     */
    startRenderCycle(componentId?: string): string {
        this.currentRenderCycleId = `render-${Date.now()}-${componentId || 'root'}`;
        return this.currentRenderCycleId;
    }

    /**
     * 렌더 사이클 종료 표시
     */
    endRenderCycle(): void {
        this.currentRenderCycleId = null;
    }

    /**
     * 액션 실행 컨텍스트 시작
     */
    startActionContext(): void {
        this.isInActionExecution = true;
    }

    /**
     * 액션 실행 컨텍스트 종료
     */
    endActionContext(): void {
        this.isInActionExecution = false;
    }

    /**
     * iteration 컨텍스트 시작
     */
    startIterationContext(): void {
        this.isInIteration = true;
    }

    /**
     * iteration 컨텍스트 종료
     */
    endIterationContext(): void {
        this.isInIteration = false;
    }

    /**
     * 캐시 결정 추적 정보 반환
     */
    getCacheDecisionTrackingInfo(): import('./types').CacheDecisionTrackingInfo {
        const stats = this.getCacheDecisionStats();

        return {
            decisions: [...this.cacheDecisions],
            stats,
            timestamp: Date.now(),
        };
    }

    /**
     * 캐시 결정 통계 계산
     */
    private getCacheDecisionStats(): import('./types').CacheDecisionStats {
        const decisions = this.cacheDecisions;

        // 결정 유형별 개수
        let cacheHits = 0;
        let cacheMisses = 0;
        let skipCacheCount = 0;
        let invalidateCount = 0;

        const byReason: Record<string, number> = {};
        const byComponent: Record<string, number> = {};

        for (const d of decisions) {
            if (d.decision === 'cache_hit') cacheHits++;
            else if (d.decision === 'cache_miss') cacheMisses++;
            else if (d.decision === 'skip_cache') skipCacheCount++;
            else if (d.decision === 'invalidate') invalidateCount++;

            byReason[d.reason] = (byReason[d.reason] || 0) + 1;

            if (d.context.componentId) {
                byComponent[d.context.componentId] = (byComponent[d.context.componentId] || 0) + 1;
            }
        }

        const total = cacheHits + cacheMisses + skipCacheCount;

        return {
            totalDecisions: decisions.length,
            cacheHits,
            cacheMisses,
            skipCacheCount,
            invalidateCount,
            byReason,
            byComponent,
            avgHitRate: total > 0 ? cacheHits / total : 0,
        };
    }

    /**
     * 최근 캐시 결정 반환
     */
    getRecentCacheDecisions(limit = 20, decision?: import('./types').CacheDecisionType): import('./types').CacheDecisionInfo[] {
        let filtered = [...this.cacheDecisions];

        if (decision) {
            filtered = filtered.filter(d => d.decision === decision);
        }

        return filtered.slice(-limit);
    }

    /**
     * 캐시 결정 데이터 초기화
     */
    clearCacheDecisionData(): void {
        this.cacheDecisions = [];
        this.cacheDecisionIdCounter = 0;
    }

    // ============================================
    // 라이프사이클 추적 메서드
    // ============================================

    /**
     * 라이프사이클 추적용 props 간소화
     *
     * 덤프 파일 크기를 줄이기 위해 큰 props는 요약만 저장합니다.
     */
    private summarizePropsForLifecycle(props: any): any {
        if (!props || typeof props !== 'object') return props;

        const result: Record<string, any> = {};
        const skipKeys = ['children', 'ref', 'key'];

        for (const [key, value] of Object.entries(props)) {
            if (skipKeys.includes(key)) continue;

            if (value === null || value === undefined) {
                result[key] = value;
            } else if (Array.isArray(value)) {
                // 배열: 길이만 표시
                result[key] = `[Array(${value.length})]`;
            } else if (typeof value === 'object') {
                // 객체: 키 목록만 표시
                const keys = Object.keys(value);
                if (keys.length <= 3) {
                    result[key] = `{${keys.join(', ')}}`;
                } else {
                    result[key] = `{${keys.slice(0, 3).join(', ')}, ... +${keys.length - 3}}`;
                }
            } else if (typeof value === 'string' && value.length > 50) {
                // 긴 문자열: 축약
                result[key] = value.substring(0, 50) + '...';
            } else if (typeof value === 'function') {
                result[key] = '[Function]';
            } else {
                result[key] = value;
            }
        }

        return result;
    }

    /**
     * 컴포넌트 마운트 추적
     */
    trackMount(componentId: string, info: Omit<ComponentInfo, 'mountTime'>): void {
        if (!this.config.enabled) return;

        // props 간소화: 큰 객체/배열은 요약만 저장
        const summarizedProps = this.summarizePropsForLifecycle(info.props);

        const componentInfo: ComponentInfo = {
            ...info,
            props: summarizedProps,
            id: componentId,
            mountTime: Date.now(),
        };

        this.mountedComponents.set(componentId, componentInfo);

        // 감시자 알림
        this.mountWatchers.forEach((cb) => {
            try {
                cb({
                    componentId,
                    componentName: info.name,
                    timestamp: componentInfo.mountTime,
                });
            } catch (e) {
                console.error('[G7DevTools] Mount watcher error:', e);
            }
        });
    }

    /**
     * 컴포넌트 언마운트 추적
     */
    trackUnmount(componentId: string): void {
        if (!this.config.enabled) return;

        const component = this.mountedComponents.get(componentId);
        const listeners = this.eventListeners.get(componentId) || [];

        if (listeners.length > 0) {
            console.warn(
                `[G7DevTools] 컴포넌트 ${componentId} 언마운트 시 ${listeners.length}개 리스너 미정리`
            );
        }

        // 감시자 알림
        if (component) {
            this.unmountWatchers.forEach((cb) => {
                try {
                    cb({
                        componentId,
                        componentName: component.name,
                        timestamp: Date.now(),
                        orphanedListeners: listeners.length,
                    });
                } catch (e) {
                    console.error('[G7DevTools] Unmount watcher error:', e);
                }
            });
        }

        this.mountedComponents.delete(componentId);
    }

    /**
     * 이벤트 리스너 추적
     */
    trackListener(componentId: string, type: string, target: string): void {
        if (!this.config.enabled) return;

        const listeners = this.eventListeners.get(componentId) || [];
        listeners.push({ type, target, addedAt: Date.now() });
        this.eventListeners.set(componentId, listeners);
    }

    /**
     * 이벤트 리스너 제거 추적
     */
    removeListener(componentId: string, type: string): void {
        if (!this.config.enabled) return;

        const listeners = this.eventListeners.get(componentId) || [];
        const idx = listeners.findIndex((l) => l.type === type);
        if (idx >= 0) listeners.splice(idx, 1);
    }

    /**
     * 마운트된 컴포넌트 조회
     */
    getMountedComponents(): ComponentInfo[] {
        return Array.from(this.mountedComponents.values());
    }

    /**
     * 정리되지 않은 리스너 조회
     */
    getOrphanedListeners(): ListenerInfo[] {
        const orphaned: ListenerInfo[] = [];
        for (const [compId, listeners] of this.eventListeners) {
            if (!this.mountedComponents.has(compId)) {
                orphaned.push(...listeners.map((l) => ({ ...l, componentId: compId })));
            }
        }
        return orphaned;
    }

    /**
     * 마운트 감시 등록
     */
    watchMount(callback: (info: MountEvent) => void): () => void {
        this.mountWatchers.add(callback);
        return () => {
            this.mountWatchers.delete(callback);
        };
    }

    /**
     * 언마운트 감시 등록
     */
    watchUnmount(callback: (info: UnmountEvent) => void): () => void {
        this.unmountWatchers.add(callback);
        return () => {
            this.unmountWatchers.delete(callback);
        };
    }

    // ============================================
    // 성능 추적 메서드
    // ============================================

    /**
     * 렌더링 추적
     */
    trackRender(componentName: string): void {
        if (!this.config.enabled) return;

        const count = this.renderCounts.get(componentName) || 0;
        this.renderCounts.set(componentName, count + 1);

        if (this.isProfiling) {
            this.profilingData.push({
                type: 'render',
                component: componentName,
                timestamp: performance.now(),
            });
        }
    }

    /**
     * 바인딩 평가 추적
     */
    trackBindingEval(expression?: string): void {
        if (!this.config.enabled) return;

        this.bindingEvalCount++;

        if (this.isProfiling && expression) {
            this.profilingData.push({
                type: 'binding',
                expression,
                timestamp: performance.now(),
            });
        }
    }

    /**
     * 렌더링 횟수 조회
     */
    getRenderCount(): Map<string, number> {
        return new Map(this.renderCounts);
    }

    /**
     * 바인딩 평가 횟수 조회
     */
    getBindingEvalCount(): number {
        return this.bindingEvalCount;
    }

    /**
     * 메모리 경고 조회
     */
    getMemoryWarnings(): MemoryWarning[] {
        const warnings: MemoryWarning[] = [];

        // 큰 상태 객체 감지
        try {
            const state = this.getState();
            const stateSize = JSON.stringify(state).length;
            if (stateSize > 1000000) {
                // 1MB
                warnings.push({
                    type: 'large-state',
                    message: `상태 크기가 ${(stateSize / 1024 / 1024).toFixed(2)}MB입니다`,
                    suggestion: '불필요한 데이터 정리 필요',
                    severity: stateSize > 5000000 ? 'error' : 'warning',
                });
            }
        } catch {
            // 순환 참조 등으로 직렬화 실패 시 무시
        }

        // 이력 크기 감지
        if (this.stateHistory.length > this.config.maxHistorySize * 0.8) {
            warnings.push({
                type: 'large-history',
                message: `상태 이력이 ${this.stateHistory.length}개입니다`,
                suggestion: 'maxHistory 설정 조정 필요',
                severity: 'warning',
            });
        }

        // 정리되지 않은 리스너 감지
        const orphanedCount = this.getOrphanedListeners().length;
        if (orphanedCount > 0) {
            warnings.push({
                type: 'orphaned-listeners',
                message: `${orphanedCount}개의 정리되지 않은 이벤트 리스너`,
                suggestion: 'useEffect cleanup 또는 removeEventListener 확인',
                severity: orphanedCount > 10 ? 'error' : 'warning',
            });
        }

        // 과도한 렌더링 감지
        const excessiveRenders = Array.from(this.renderCounts.entries()).filter(
            ([, count]) => count > 50
        );
        if (excessiveRenders.length > 0) {
            warnings.push({
                type: 'excessive-renders',
                message: `${excessiveRenders.length}개 컴포넌트가 50회 이상 렌더링`,
                suggestion: 'React.memo, useMemo, useCallback 사용 고려',
                severity: 'warning',
            });
        }

        return warnings;
    }

    /**
     * 프로파일링 시작
     */
    startProfiling(): void {
        if (!this.config.enabled) return;

        this.isProfiling = true;
        this.profilingData = [];
        this.profilingStartTime = performance.now();
        console.log('[G7DevTools] 프로파일링 시작');
    }

    /**
     * 프로파일링 종료
     */
    stopProfiling(): ProfileReport {
        this.isProfiling = false;
        const duration = performance.now() - this.profilingStartTime;

        const report = this.analyzeProfile(duration);
        console.log('[G7DevTools] 프로파일링 완료', report);

        return report;
    }

    /**
     * 프로파일 분석
     */
    private analyzeProfile(duration: number): ProfileReport {
        const renders = this.profilingData.filter((e) => e.type === 'render');
        const bindings = this.profilingData.filter((e) => e.type === 'binding');
        const actions = this.profilingData.filter((e) => e.type === 'action');

        // 컴포넌트별 렌더링 집계
        const componentRenders: Record<string, { count: number; durations: number[] }> = {};
        for (const entry of renders) {
            if (!entry.component) continue;
            if (!componentRenders[entry.component]) {
                componentRenders[entry.component] = { count: 0, durations: [] };
            }
            componentRenders[entry.component].count++;
            if (entry.duration) {
                componentRenders[entry.component].durations.push(entry.duration);
            }
        }

        const slowestComponents = Object.entries(componentRenders)
            .map(([name, data]) => ({
                name,
                renderCount: data.count,
                avgDuration:
                    data.durations.length > 0
                        ? data.durations.reduce((a, b) => a + b, 0) / data.durations.length
                        : 0,
            }))
            .sort((a, b) => b.renderCount - a.renderCount)
            .slice(0, 10);

        // 핫 패스 (자주 평가되는 바인딩)
        const bindingCounts: Record<string, number> = {};
        for (const entry of bindings) {
            if (!entry.expression) continue;
            bindingCounts[entry.expression] = (bindingCounts[entry.expression] || 0) + 1;
        }
        const hotPaths = Object.entries(bindingCounts)
            .sort(([, a], [, b]) => b - a)
            .slice(0, 10)
            .map(([path]) => path);

        return {
            duration,
            entries: this.profilingData,
            summary: {
                totalRenders: renders.length,
                totalBindings: bindings.length,
                totalActions: actions.length,
                slowestComponents,
                hotPaths,
            },
        };
    }

    /**
     * 성능 통계 초기화
     */
    resetPerformanceStats(): void {
        this.renderCounts.clear();
        this.bindingEvalCount = 0;
        this.profilingData = [];
    }

    // ============================================
    // 네트워크 추적 메서드
    // ============================================

    /**
     * 요청 시작 추적
     *
     * @param url 전체 URL (쿼리스트링 포함)
     * @param method HTTP 메서드
     * @param options 추가 옵션 (requestBody, dataSourceId)
     * @returns 요청 추적 ID
     */
    trackRequest(
        url: string,
        method: string,
        options?: {
            requestBody?: any;
            dataSourceId?: string;
        }
    ): string {
        if (!this.config.enabled) return '';

        const requestId = this.generateId();

        // URL에서 경로와 쿼리 파라미터 분리
        const urlPath = extractPath(url);
        const queryParams = parseQueryParams(url);

        this.activeRequests.set(requestId, {
            id: requestId,
            url: urlPath,
            fullUrl: url,
            queryParams,
            method,
            startTime: Date.now(),
            status: 'pending',
            requestBody: options?.requestBody ? this.sanitizeObject(options.requestBody) : undefined,
            dataSourceId: options?.dataSourceId,
        });

        if (this.isProfiling) {
            this.profilingData.push({
                type: 'network',
                timestamp: performance.now(),
            });
        }

        return requestId;
    }

    /**
     * 요청 완료 추적
     */
    completeRequest(requestId: string, statusCode: number, response?: any): void {
        if (!this.config.enabled) return;

        const req = this.activeRequests.get(requestId);
        if (!req) return;

        const log: RequestLog = {
            ...req,
            status: statusCode >= 200 && statusCode < 300 ? 'success' : 'error',
            statusCode,
            duration: Date.now() - req.startTime,
            endTime: Date.now(),
            response: this.sanitizeObject(response),
        };

        this.requestHistory.push(log);
        this.activeRequests.delete(requestId);

        // 이력 크기 제한
        if (this.requestHistory.length > this.config.maxHistorySize) {
            this.requestHistory.shift();
        }

        this.notifyRequestWatchers(log);
    }

    /**
     * 요청 실패 추적
     */
    failRequest(requestId: string, error: string): void {
        if (!this.config.enabled) return;

        const req = this.activeRequests.get(requestId);
        if (!req) return;

        const log: RequestLog = {
            ...req,
            status: 'error',
            error,
            duration: Date.now() - req.startTime,
            endTime: Date.now(),
        };

        this.requestHistory.push(log);
        this.activeRequests.delete(requestId);

        this.notifyRequestWatchers(log);
    }

    /**
     * 데이터소스 로딩 시작 추적
     */
    trackDataSource(name: string): void {
        if (!this.config.enabled) return;
        this.pendingDataSources.add(name);
    }

    /**
     * 데이터소스 로딩 완료 추적
     */
    completeDataSource(name: string): void {
        if (!this.config.enabled) return;
        this.pendingDataSources.delete(name);
    }

    /**
     * 활성 요청 조회
     */
    getActiveRequests(): RequestInfo[] {
        return Array.from(this.activeRequests.values());
    }

    /**
     * 요청 이력 조회
     */
    getRequestHistory(): RequestLog[] {
        return [...this.requestHistory];
    }

    /**
     * 대기 중인 데이터소스 조회
     */
    getPendingDataSources(): string[] {
        return Array.from(this.pendingDataSources);
    }

    /**
     * 요청 감시 등록
     */
    watchRequest(callback: RequestWatcherCallback): () => void {
        this.requestWatchers.add(callback);
        return () => {
            this.requestWatchers.delete(callback);
        };
    }

    /**
     * 요청 감시자들에게 알림
     */
    private notifyRequestWatchers(request: RequestLog): void {
        this.requestWatchers.forEach((cb) => {
            try {
                cb(request);
            } catch (e) {
                console.error('[G7DevTools] Request watcher error:', e);
            }
        });
    }

    // ============================================
    // WebSocket 추적 메서드
    // ============================================

    /**
     * WebSocket 연결 추적
     */
    trackWebSocketConnection(id: string, url: string, state: WebSocketConnectionInfo['state']): void {
        if (!this.config.enabled) return;

        this.wsConnections.set(id, {
            id,
            url,
            state,
            connectedAt: state === 'open' ? Date.now() : undefined,
        });
    }

    /**
     * WebSocket 메시지 추적
     */
    trackWebSocketMessage(
        connectionId: string,
        direction: 'sent' | 'received',
        type: string,
        payload: any
    ): void {
        if (!this.config.enabled) return;

        const message: WebSocketMessage = {
            id: this.generateId(),
            connectionId,
            direction,
            type,
            payload: this.sanitizeObject(payload),
            timestamp: Date.now(),
            sequence: this.wsMessageHistory.length,
        };

        this.wsMessageHistory.push(message);

        // 이력 크기 제한
        if (this.wsMessageHistory.length > this.config.maxHistorySize) {
            this.wsMessageHistory.shift();
        }

        this.notifyWebSocketWatchers(message);
    }

    /**
     * WebSocket 연결 정보 조회
     */
    getWebSocketConnections(): WebSocketConnectionInfo[] {
        return Array.from(this.wsConnections.values());
    }

    /**
     * WebSocket 메시지 이력 조회
     */
    getWebSocketMessageHistory(): WebSocketMessage[] {
        return [...this.wsMessageHistory];
    }

    /**
     * WebSocket 연결 상태 조회
     */
    getWebSocketConnectionState(): 'connected' | 'disconnected' | 'reconnecting' {
        const connections = Array.from(this.wsConnections.values());
        if (connections.some((c) => c.state === 'open')) return 'connected';
        if (connections.some((c) => c.state === 'connecting')) return 'reconnecting';
        return 'disconnected';
    }

    /**
     * WebSocket 메시지 감시 등록
     */
    watchWebSocketMessage(callback: WebSocketWatcherCallback): () => void {
        this.wsMessageWatchers.add(callback);
        return () => {
            this.wsMessageWatchers.delete(callback);
        };
    }

    /**
     * WebSocket 감시자들에게 알림
     */
    private notifyWebSocketWatchers(message: WebSocketMessage): void {
        this.wsMessageWatchers.forEach((cb) => {
            try {
                cb(message);
            } catch (e) {
                console.error('[G7DevTools] WebSocket watcher error:', e);
            }
        });
    }

    // ============================================
    // 조건부 렌더링 추적 메서드
    // ============================================

    /**
     * if 조건 추적
     */
    trackIfCondition(id: string, expression: string, value: boolean): void {
        if (!this.config.enabled) return;

        const existing = this.ifConditions.get(id);
        const prevValue = existing?.evaluatedValue;

        this.ifConditions.set(id, {
            id,
            expression,
            evaluatedValue: value,
            evaluationCount: (existing?.evaluationCount || 0) + 1,
            lastEvaluated: Date.now(),
        });

        // 값이 변경되면 감시자 알림
        if (existing && prevValue !== value) {
            this.notifyConditionWatchers({
                id,
                expression,
                oldValue: prevValue!,
                newValue: value,
                timestamp: Date.now(),
            });
        }
    }

    /**
     * iteration 추적
     */
    trackIteration(
        id: string,
        info: {
            source: string;
            itemVar: string;
            indexVar?: string;
            sourceLength: number;
        }
    ): void {
        if (!this.config.enabled) return;

        this.iterations.set(id, {
            id,
            ...info,
            lastRendered: Date.now(),
        });
    }

    /**
     * if 조건들 조회
     */
    getIfConditions(): ConditionInfo[] {
        return Array.from(this.ifConditions.values());
    }

    /**
     * iteration들 조회
     */
    getIterations(): IterationInfo[] {
        return Array.from(this.iterations.values());
    }

    /**
     * 스코프 체인 조회
     */
    getScopeChain(componentId: string): ScopeVariable[] {
        const chain: ScopeVariable[] = [];

        // _global
        const state = this.getState();
        chain.push({ name: '_global', value: state._global, source: 'global' });
        chain.push({ name: '_local', value: state._local, source: 'local' });
        chain.push({ name: '_computed', value: state._computed, source: 'computed' });

        // iteration 변수는 컴포넌트별로 추적 필요 (추후 확장)

        return chain;
    }

    /**
     * 조건 변경 감시 등록
     */
    watchConditionChange(callback: ConditionWatcherCallback): () => void {
        this.conditionWatchers.add(callback);
        return () => {
            this.conditionWatchers.delete(callback);
        };
    }

    /**
     * 조건 감시자들에게 알림
     */
    private notifyConditionWatchers(change: import('./types').ConditionChange): void {
        this.conditionWatchers.forEach((cb) => {
            try {
                cb(change);
            } catch (e) {
                console.error('[G7DevTools] Condition watcher error:', e);
            }
        });
    }

    // ============================================
    // Form 추적 메서드
    // ============================================

    /**
     * Form 추적
     */
    trackForm(id: string, dataKey: string, inputs: InputInfo[]): void {
        if (!this.config.enabled) return;

        this.forms.set(id, {
            id,
            dataKey,
            inputs,
            trackedAt: Date.now(),
        });
    }

    /**
     * Form 변경 추적
     */
    trackFormChange(formId: string, inputName: string, value: any): void {
        if (!this.config.enabled) return;

        const change: FormChange = {
            formId,
            inputName,
            value,
            timestamp: Date.now(),
        };

        this.notifyFormWatchers(change);
    }

    /**
     * Form 제거
     */
    untrackForm(id: string): void {
        this.forms.delete(id);
    }

    /**
     * Form들 조회
     */
    getForms(): FormInfo[] {
        return Array.from(this.forms.values());
    }

    /**
     * 특정 Form 상태 조회
     */
    getFormState(dataKey: string): Record<string, any> {
        const state = this.getState();
        return this.getNestedValue(state, dataKey) || {};
    }

    /**
     * Input 바인딩 경로 조회
     */
    getBindingPath(formDataKey: string, inputName: string): string {
        return `${formDataKey}.${inputName}`;
    }

    /**
     * Form 변경 감시 등록
     */
    watchFormChange(callback: FormWatcherCallback): () => void {
        this.formWatchers.add(callback);
        return () => {
            this.formWatchers.delete(callback);
        };
    }

    /**
     * Form 감시자들에게 알림
     */
    private notifyFormWatchers(change: FormChange): void {
        this.formWatchers.forEach((cb) => {
            try {
                cb(change);
            } catch (e) {
                console.error('[G7DevTools] Form watcher error:', e);
            }
        });
    }

    // ============================================
    // Form 바인딩 검증 메서드
    // ============================================

    /**
     * Form 바인딩 이슈 추적
     */
    trackFormBindingIssue(
        type: FormBindingIssueType,
        severity: 'info' | 'warning' | 'error',
        details: {
            formId?: string;
            formDataKey?: string;
            inputInfo?: {
                name?: string;
                type?: string;
                componentId?: string;
            };
            contextInfo?: {
                hasParentFormContext: boolean;
                parentFormContextProp?: string;
                isInsideSortable: boolean;
                isInsideModal: boolean;
                depth: number;
            };
            description: string;
            suggestion: string;
            docLink?: string;
        }
    ): void {
        if (!this.config.enabled) return;

        const issue: FormBindingIssue = {
            id: `form-issue-${++this.formBindingIssueIdCounter}`,
            timestamp: Date.now(),
            type,
            severity,
            formId: details.formId,
            formDataKey: details.formDataKey,
            inputInfo: details.inputInfo,
            contextInfo: details.contextInfo,
            description: details.description,
            suggestion: details.suggestion,
            docLink: details.docLink,
        };

        this.formBindingIssues.push(issue);

        // 이력 크기 제한
        if (this.formBindingIssues.length > this.maxFormBindingIssues) {
            this.formBindingIssues.shift();
        }
    }

    /**
     * Form 바인딩 검증 수행
     */
    validateFormBinding(
        formId: string,
        dataKey: string | undefined,
        inputs: Array<{ name: string; type: string; value?: any }>,
        contextInfo: {
            hasParentContext: boolean;
            parentContextProp?: string;
            isContextBroken: boolean;
            breakReason?: string;
        }
    ): FormBindingValidationInfo {
        const inputBindings = inputs.map(input => {
            const issues: string[] = [];
            let isValid = true;

            if (!input.name) {
                issues.push('Input에 name 속성이 없습니다');
                isValid = false;
                this.trackFormBindingIssue('missing-input-name', 'error', {
                    formId,
                    formDataKey: dataKey,
                    inputInfo: { name: input.name, type: input.type },
                    description: 'Input에 name 속성이 없어 자동 바인딩이 동작하지 않습니다',
                    suggestion: 'Input에 name 속성을 추가하세요',
                    docLink: 'troubleshooting-components-form.md',
                });
            }

            if (!dataKey) {
                issues.push('Form에 dataKey가 없습니다');
                isValid = false;
            }

            return {
                inputName: input.name || '(unnamed)',
                inputType: input.type,
                bindingPath: dataKey && input.name ? `${dataKey}.${input.name}` : '-',
                isValid,
                currentValue: input.value,
                issues,
            };
        });

        // dataKey 누락 이슈
        if (!dataKey) {
            this.trackFormBindingIssue('missing-datakey', 'error', {
                formId,
                description: 'Form에 dataKey가 설정되지 않아 자동 바인딩이 동작하지 않습니다',
                suggestion: 'Form 컴포넌트에 dataKey props를 설정하세요',
                docLink: 'troubleshooting-components-form.md',
            });
        }

        // 컨텍스트 단절 이슈
        if (contextInfo.isContextBroken) {
            const issueType: FormBindingIssueType = contextInfo.breakReason?.includes('sortable')
                ? 'sortable-context-break'
                : contextInfo.breakReason?.includes('modal')
                ? 'modal-context-isolation'
                : 'context-not-propagated';

            this.trackFormBindingIssue(issueType, 'warning', {
                formId,
                formDataKey: dataKey,
                contextInfo: {
                    hasParentFormContext: contextInfo.hasParentContext,
                    parentFormContextProp: contextInfo.parentContextProp,
                    isInsideSortable: contextInfo.breakReason?.includes('sortable') || false,
                    isInsideModal: contextInfo.breakReason?.includes('modal') || false,
                    depth: 0,
                },
                description: contextInfo.breakReason || 'Form 컨텍스트가 제대로 전파되지 않았습니다',
                suggestion: 'parentFormContextProp={undefined}를 확인하거나 중첩 구조를 검토하세요',
                docLink: 'troubleshooting-components-form.md',
            });
        }

        const validation: FormBindingValidationInfo = {
            formId,
            dataKey,
            timestamp: Date.now(),
            contextPropagation: contextInfo,
            inputBindings,
            isValid: inputBindings.every(b => b.isValid) && !!dataKey && !contextInfo.isContextBroken,
            issueCount: inputBindings.filter(b => !b.isValid).length + (dataKey ? 0 : 1) + (contextInfo.isContextBroken ? 1 : 0),
        };

        // 기존 검증 결과 업데이트
        const existingIndex = this.formBindingValidations.findIndex(v => v.formId === formId);
        if (existingIndex >= 0) {
            this.formBindingValidations[existingIndex] = validation;
        } else {
            this.formBindingValidations.push(validation);
        }

        return validation;
    }

    /**
     * Form 바인딩 검증 추적 정보 반환
     */
    getFormBindingValidationTrackingInfo(): FormBindingValidationTrackingInfo {
        const stats = this.getFormBindingValidationStats();

        return {
            issues: [...this.formBindingIssues],
            validations: [...this.formBindingValidations],
            stats,
            timestamp: Date.now(),
        };
    }

    /**
     * Form 바인딩 검증 통계 반환
     */
    private getFormBindingValidationStats(): FormBindingValidationStats {
        const byIssueType: Record<FormBindingIssueType, number> = {
            'missing-datakey': 0,
            'missing-input-name': 0,
            'context-not-propagated': 0,
            'sortable-context-break': 0,
            'modal-context-isolation': 0,
            'duplicate-input-name': 0,
            'value-type-mismatch': 0,
            'binding-path-invalid': 0,
        };

        const bySeverity: Record<string, number> = {
            info: 0,
            warning: 0,
            error: 0,
        };

        for (const issue of this.formBindingIssues) {
            byIssueType[issue.type]++;
            bySeverity[issue.severity]++;
        }

        // 자주 발생하는 이슈 계산
        const issueTypeCounts = Object.entries(byIssueType)
            .filter(([, count]) => count > 0)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 5)
            .map(([type, count]) => ({
                type: type as FormBindingIssueType,
                count,
                description: this.getIssueTypeDescription(type as FormBindingIssueType),
            }));

        return {
            totalFormsValidated: this.formBindingValidations.length,
            validForms: this.formBindingValidations.filter(v => v.isValid).length,
            formsWithIssues: this.formBindingValidations.filter(v => !v.isValid).length,
            totalIssues: this.formBindingIssues.length,
            byIssueType,
            bySeverity,
            topIssues: issueTypeCounts,
        };
    }

    /**
     * 이슈 유형 설명 반환
     */
    private getIssueTypeDescription(type: FormBindingIssueType): string {
        const descriptions: Record<FormBindingIssueType, string> = {
            'missing-datakey': 'Form에 dataKey 누락',
            'missing-input-name': 'Input에 name 속성 누락',
            'context-not-propagated': 'Form 컨텍스트 전파 실패',
            'sortable-context-break': 'Sortable 컨테이너에서 컨텍스트 단절',
            'modal-context-isolation': '모달에서 부모 Form 컨텍스트 격리',
            'duplicate-input-name': '동일 Form 내 중복 Input name',
            'value-type-mismatch': '값 타입과 Input 타입 불일치',
            'binding-path-invalid': '바인딩 경로 유효하지 않음',
        };
        return descriptions[type] || type;
    }

    /**
     * Form 바인딩 이슈 초기화
     */
    clearFormBindingIssues(): void {
        this.formBindingIssues = [];
        this.formBindingValidations = [];
        this.formBindingIssueIdCounter = 0;
    }

    // ============================================
    // Computed 의존성 추적 메서드
    // ============================================

    /**
     * Computed 속성 등록
     */
    trackComputedProperty(
        name: string,
        expression: string,
        dependencies: Array<{ type: ComputedDependencyType; path: string; value?: any }>,
        value: any,
        computationTime: number,
        componentId?: string,
        error?: string
    ): void {
        if (!this.config.enabled) return;

        const id = `computed-${name}-${componentId || 'global'}`;

        const property: ComputedPropertyInfo = {
            id,
            name,
            expression,
            componentId,
            dependencies,
            currentValue: this.safeClone(value),
            lastComputedAt: Date.now(),
            computationTime,
            error,
        };

        this.computedProperties.set(id, property);
    }

    /**
     * Computed 재계산 추적
     */
    trackComputedRecalc(
        computedName: string,
        trigger: ComputedRecalcTrigger,
        previousValue: any,
        newValue: any,
        computationTime: number,
        triggeredBy?: {
            type: ComputedDependencyType;
            path: string;
            oldValue?: any;
            newValue?: any;
        },
        componentId?: string
    ): void {
        if (!this.config.enabled) return;

        const computedId = `computed-${computedName}-${componentId || 'global'}`;
        const valueChanged = JSON.stringify(previousValue) !== JSON.stringify(newValue);

        const log: ComputedRecalcLog = {
            id: `recalc-${++this.computedRecalcIdCounter}`,
            computedId,
            computedName,
            timestamp: Date.now(),
            trigger,
            triggeredBy,
            previousValue: this.safeClone(previousValue),
            newValue: this.safeClone(newValue),
            valueChanged,
            computationTime,
            cascadeCount: 0, // 연쇄 재계산 수는 나중에 분석에서 계산
        };

        this.computedRecalcLogs.push(log);

        // 이력 크기 제한
        if (this.computedRecalcLogs.length > this.maxComputedRecalcLogs) {
            this.computedRecalcLogs.shift();
        }

        // Computed 속성 정보 업데이트
        const property = this.computedProperties.get(computedId);
        if (property) {
            property.currentValue = this.safeClone(newValue);
            property.lastComputedAt = Date.now();
            property.computationTime = computationTime;
        }
    }

    /**
     * Computed 의존성 체인 분석
     */
    analyzeComputedDependencyChain(computedName: string, componentId?: string): ComputedDependencyChain {
        const visited = new Set<string>();
        const path: string[] = [];
        let hasCycle = false;
        let cyclePath: string[] | undefined;
        let maxDepth = 0;

        const buildTree = (name: string, depth: number): ComputedDependencyNode => {
            maxDepth = Math.max(maxDepth, depth);

            if (visited.has(name)) {
                hasCycle = true;
                cyclePath = [...path, name];
                return {
                    name,
                    type: 'computed',
                    children: [],
                    depth,
                };
            }

            visited.add(name);
            path.push(name);

            const prop = this.computedProperties.get(`computed-${name}-${componentId || 'global'}`);
            const children: ComputedDependencyNode[] = [];

            if (prop) {
                for (const dep of prop.dependencies) {
                    if (dep.type === 'computed') {
                        children.push(buildTree(dep.path, depth + 1));
                    } else {
                        children.push({
                            name: dep.path,
                            type: dep.type,
                            children: [],
                            depth: depth + 1,
                        });
                    }
                }
            }

            path.pop();
            visited.delete(name);

            return {
                name,
                type: 'computed',
                children,
                depth,
            };
        };

        const tree = buildTree(computedName, 0);

        return {
            root: computedName,
            tree,
            hasCycle,
            cyclePath,
            maxDepth,
        };
    }

    /**
     * Computed 의존성 추적 정보 반환
     */
    getComputedDependencyTrackingInfo(): ComputedDependencyTrackingInfo {
        const properties = Array.from(this.computedProperties.values());
        const stats = this.getComputedDependencyStats();

        // 모든 computed에 대해 의존성 체인 분석
        const dependencyChains: ComputedDependencyChain[] = [];
        for (const prop of properties) {
            const chain = this.analyzeComputedDependencyChain(prop.name, prop.componentId);
            dependencyChains.push(chain);
        }

        return {
            properties,
            recalcLogs: [...this.computedRecalcLogs],
            dependencyChains,
            stats,
            timestamp: Date.now(),
        };
    }

    /**
     * Computed 의존성 통계 반환
     */
    private getComputedDependencyStats(): ComputedDependencyStats {
        const byTrigger: Record<ComputedRecalcTrigger, number> = {
            'state-change': 0,
            'datasource-update': 0,
            'dependency-change': 0,
            'manual': 0,
            'initial': 0,
        };

        const recalcCounts: Record<string, number> = {};
        const computationTimes: Record<string, number[]> = {};
        let totalComputationTime = 0;
        let unnecessaryCount = 0;

        for (const log of this.computedRecalcLogs) {
            byTrigger[log.trigger]++;

            if (!log.valueChanged) {
                unnecessaryCount++;
            }

            recalcCounts[log.computedName] = (recalcCounts[log.computedName] || 0) + 1;

            if (!computationTimes[log.computedName]) {
                computationTimes[log.computedName] = [];
            }
            computationTimes[log.computedName].push(log.computationTime);
            totalComputationTime += log.computationTime;
        }

        // 가장 많이 재계산된 속성
        const topRecalculated = Object.entries(recalcCounts)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 5)
            .map(([name, count]) => ({ name, count }));

        // 가장 느린 계산
        const slowestComputed = Object.entries(computationTimes)
            .map(([name, times]) => ({
                name,
                avgTime: times.reduce((a, b) => a + b, 0) / times.length,
            }))
            .sort((a, b) => b.avgTime - a.avgTime)
            .slice(0, 5);

        // 순환 의존성 감지 수
        let cycleCount = 0;
        for (const prop of this.computedProperties.values()) {
            const chain = this.analyzeComputedDependencyChain(prop.name, prop.componentId);
            if (chain.hasCycle) {
                cycleCount++;
            }
        }

        return {
            totalComputed: this.computedProperties.size,
            totalRecalculations: this.computedRecalcLogs.length,
            unnecessaryRecalculations: unnecessaryCount,
            avgComputationTime: this.computedRecalcLogs.length > 0
                ? totalComputationTime / this.computedRecalcLogs.length
                : 0,
            topRecalculated,
            slowestComputed,
            cycleDetectionCount: cycleCount,
            byTrigger,
        };
    }

    /**
     * Computed 추적 초기화
     */
    clearComputedTracking(): void {
        this.computedProperties.clear();
        this.computedRecalcLogs = [];
        this.computedRecalcIdCounter = 0;
    }

    // ============================================
    // 모달 상태 스코프 추적 메서드
    // ============================================

    /**
     * 모달 열림 추적
     */
    trackModalOpen(options: {
        modalId: string;
        modalName: string;
        scopeType?: ModalStateScopeType;
        parentModalId?: string;
        componentId?: string;
        initialState?: Record<string, any>;
        isolatedStateKeys?: string[];
        sharedStateKeys?: string[];
    }): void {
        if (!this.config.enabled) return;

        const modalInfo: ModalStateInfo = {
            modalId: options.modalId,
            modalName: options.modalName,
            openedAt: Date.now(),
            closedAt: null,
            scopeType: options.scopeType || 'isolated',
            parentModalId: options.parentModalId,
            componentId: options.componentId,
            initialState: options.initialState ? { ...options.initialState } : {},
            currentState: options.initialState ? { ...options.initialState } : {},
            stateChangeCount: 0,
            isolatedStateKeys: options.isolatedStateKeys || [],
            sharedStateKeys: options.sharedStateKeys || [],
        };

        this.modalStates.set(options.modalId, modalInfo);

        // modalStack vs 레이아웃 modals 정의 교차 검증
        this.validateModalDefinitionExists(options.modalId, options.modalName);

        // 부모-자식 관계 추적
        if (options.parentModalId) {
            const existingRelation = this.modalStateRelations.find(
                r => r.parentModalId === options.parentModalId && r.childModalId === options.modalId
            );
            if (!existingRelation) {
                this.modalStateRelations.push({
                    parentModalId: options.parentModalId,
                    childModalId: options.modalId,
                    sharedKeys: options.sharedStateKeys || [],
                    isolatedKeys: options.isolatedStateKeys || [],
                    relationType: 'parent-child',
                });
            }
        }
    }

    /**
     * 모달 닫힘 추적
     */
    trackModalClose(modalId: string, finalState?: Record<string, any>): void {
        if (!this.config.enabled) return;

        const modalInfo = this.modalStates.get(modalId);
        if (!modalInfo) return;

        modalInfo.closedAt = Date.now();
        if (finalState) {
            modalInfo.currentState = { ...finalState };
        }

        // 상태 유출 검사
        this.detectStateLeakage(modalInfo);
    }

    /**
     * modalStack에 열린 모달 ID가 레이아웃 modals 섹션에 정의되어 있는지 검증
     *
     * @since engine-v1.22.0
     */
    private validateModalDefinitionExists(modalId: string, modalName: string): void {
        const layout = this.currentLayout;
        if (!layout?.layoutJson) return;

        const modals = layout.layoutJson.modals;

        // modals 섹션 자체가 없거나 빈 배열/객체
        if (!modals) {
            this.recordModalStateIssue({
                type: 'missing-definition',
                modalId,
                modalName,
                severity: 'error',
                description: `모달 "${modalId}"이(가) modalStack에 추가되었으나, 현재 레이아웃에 modals 섹션이 없습니다. 레이아웃 경로: ${layout.layoutPath}`,
                affectedStateKeys: [],
                expectedValue: `modals 섹션에 id="${modalId}" 정의 존재`,
                actualValue: 'modals 섹션 없음',
            });
            return;
        }

        // modals가 배열인 경우 (정상 형식)
        const modalArray = Array.isArray(modals) ? modals : Object.values(modals);
        const found = modalArray.some(
            (m: any) => m?.id === modalId || m?.props?.id === modalId
        );

        if (!found) {
            this.recordModalStateIssue({
                type: 'missing-definition',
                modalId,
                modalName,
                severity: 'error',
                description: `모달 "${modalId}"이(가) modalStack에 추가되었으나, 렌더링된 레이아웃의 modals 섹션에 해당 ID의 정의가 없습니다. partial 로딩 실패, 레이아웃 병합 문제, extends 상속 누락 등을 확인하세요. 레이아웃 경로: ${layout.layoutPath}`,
                affectedStateKeys: [],
                expectedValue: `modals 배열에 id="${modalId}" 항목 존재`,
                actualValue: `modals 배열에 ${modalArray.length}개 모달 정의 (${modalArray.map((m: any) => m?.id || 'unknown').join(', ')})`,
            });
        }
    }

    /**
     * 모달 내 상태 변경 추적
     */
    trackModalStateChange(options: {
        modalId: string;
        stateKey: string;
        previousValue: any;
        newValue: any;
        changeSource?: 'user-action' | 'api-response' | 'parent-sync' | 'init' | 'cleanup';
    }): void {
        if (!this.config.enabled) return;

        const modalInfo = this.modalStates.get(options.modalId);
        if (!modalInfo) return;

        modalInfo.stateChangeCount++;
        modalInfo.currentState[options.stateKey] = options.newValue;

        // 격리 위반 검사
        const violatesIsolation = this.checkIsolationViolation(modalInfo, options.stateKey);

        const changeLog: ModalStateChangeLog = {
            id: `modal-change-${++this.modalStateChangeLogIdCounter}`,
            modalId: options.modalId,
            modalName: modalInfo.modalName,
            timestamp: Date.now(),
            stateKey: options.stateKey,
            previousValue: options.previousValue,
            newValue: options.newValue,
            changeSource: options.changeSource || 'user-action',
            violatesIsolation,
        };

        this.modalStateChangeLogs.push(changeLog);

        // 크기 제한
        if (this.modalStateChangeLogs.length > this.maxModalStateChangeLogs) {
            this.modalStateChangeLogs.shift();
        }

        // 격리 위반 시 이슈 기록
        if (violatesIsolation) {
            this.recordModalStateIssue({
                type: 'isolation-violation',
                modalId: options.modalId,
                modalName: modalInfo.modalName,
                severity: 'warning',
                description: `모달 '${modalInfo.modalName}'에서 격리된 상태 키 '${options.stateKey}'가 변경됨`,
                affectedStateKeys: [options.stateKey],
            });
        }
    }

    /**
     * 상태 유출 감지
     */
    private detectStateLeakage(modalInfo: ModalStateInfo): void {
        // isolated 스코프의 모달에서 상태가 유출되었는지 확인
        if (modalInfo.scopeType !== 'isolated') return;

        for (const key of modalInfo.isolatedStateKeys) {
            const initialValue = modalInfo.initialState[key];
            const currentValue = modalInfo.currentState[key];

            // 값이 변경되었고, 이게 부모 상태에 영향을 줄 수 있는지 확인
            if (initialValue !== currentValue) {
                // 부모 모달이 있는 경우 해당 키가 부모에서 사용되는지 확인
                if (modalInfo.parentModalId) {
                    const parentModal = this.modalStates.get(modalInfo.parentModalId);
                    if (parentModal && parentModal.currentState.hasOwnProperty(key)) {
                        this.recordModalStateIssue({
                            type: 'state-leakage',
                            modalId: modalInfo.modalId,
                            modalName: modalInfo.modalName,
                            severity: 'error',
                            description: `모달 닫힘 후 상태 '${key}'가 부모 모달로 유출될 수 있음`,
                            affectedStateKeys: [key],
                            leakedValue: currentValue,
                            expectedValue: initialValue,
                        });
                    }
                }
            }
        }
    }

    /**
     * 격리 위반 검사
     */
    private checkIsolationViolation(modalInfo: ModalStateInfo, _stateKey: string): boolean {
        // shared 스코프가 아닌데 공유 키가 변경되면 위반
        if (modalInfo.scopeType === 'isolated') {
            // 격리 키에 포함된 상태가 외부에서 변경 시도되면 위반
            // _stateKey는 향후 상세 격리 위반 검사 시 사용 예정
            return false;
        }
        return false;
    }

    /**
     * 모달 상태 이슈 기록
     */
    private recordModalStateIssue(options: {
        type: ModalStateIssueType;
        modalId: string;
        modalName: string;
        severity: 'warning' | 'error';
        description: string;
        affectedStateKeys: string[];
        leakedValue?: any;
        expectedValue?: any;
        actualValue?: any;
        stackInfo?: string;
    }): void {
        const issue: ModalStateIssue = {
            id: `modal-issue-${++this.modalStateIssueIdCounter}`,
            type: options.type,
            modalId: options.modalId,
            modalName: options.modalName,
            timestamp: Date.now(),
            severity: options.severity,
            description: options.description,
            affectedStateKeys: options.affectedStateKeys,
            leakedValue: options.leakedValue,
            expectedValue: options.expectedValue,
            actualValue: options.actualValue,
            stackInfo: options.stackInfo,
        };

        this.modalStateIssues.push(issue);

        // 크기 제한
        if (this.modalStateIssues.length > this.maxModalStateIssues) {
            this.modalStateIssues.shift();
        }
    }

    /**
     * 모달 상태 스코프 추적 정보 반환
     */
    getModalStateScopeTrackingInfo(): ModalStateScopeTrackingInfo {
        const modals = Array.from(this.modalStates.values());
        const stats = this.getModalStateScopeStats();

        return {
            modals,
            issues: [...this.modalStateIssues],
            relations: [...this.modalStateRelations],
            changeLogs: [...this.modalStateChangeLogs],
            stats,
            timestamp: Date.now(),
        };
    }

    /**
     * 모달 상태 스코프 통계 반환
     */
    private getModalStateScopeStats(): ModalStateScopeStats {
        const modals = Array.from(this.modalStates.values());

        const issuesBySeverity: Record<'warning' | 'error', number> = {
            warning: 0,
            error: 0,
        };

        const issuesByType: Record<ModalStateIssueType, number> = {
            'state-leakage': 0,
            'isolation-violation': 0,
            'parent-mutation': 0,
            'orphaned-state': 0,
            'scope-mismatch': 0,
            'cleanup-failure': 0,
            'missing-definition': 0,
        };

        for (const issue of this.modalStateIssues) {
            issuesBySeverity[issue.severity]++;
            issuesByType[issue.type]++;
        }

        const byScope: Record<ModalStateScopeType, number> = {
            isolated: 0,
            shared: 0,
            inherited: 0,
        };

        for (const modal of modals) {
            byScope[modal.scopeType]++;
        }

        return {
            totalModals: modals.length,
            openModals: modals.filter(m => m.closedAt === null).length,
            nestedModals: modals.filter(m => m.parentModalId !== undefined).length,
            totalIssues: this.modalStateIssues.length,
            issuesBySeverity,
            issuesByType,
            byScope,
            leakageDetectionCount: issuesByType['state-leakage'],
            cleanupFailureCount: issuesByType['cleanup-failure'],
        };
    }

    /**
     * 모달 상태 스코프 추적 초기화
     */
    clearModalStateScopeTracking(): void {
        this.modalStates.clear();
        this.modalStateIssues = [];
        this.modalStateRelations = [];
        this.modalStateChangeLogs = [];
        this.modalStateIssueIdCounter = 0;
        this.modalStateChangeLogIdCounter = 0;
    }

    // ============================================
    // Named Actions 추적 메서드
    // ============================================

    /**
     * 현재 레이아웃의 named_actions 정의를 등록합니다.
     *
     * @param definitions named_actions 정의 맵
     */
    setNamedActionDefinitions(definitions: Record<string, any>): void {
        if (!this.config.enabled) return;
        this.namedActionDefinitions = definitions || {};
    }

    /**
     * actionRef 해석 이력을 기록합니다.
     *
     * @param log actionRef 해석 정보
     */
    trackNamedActionRef(log: Omit<NamedActionRefLog, 'id'>): void {
        if (!this.config.enabled) return;

        const entry: NamedActionRefLog = {
            ...log,
            id: `named_action_ref_${++this.namedActionRefIdCounter}`,
        };

        this.namedActionRefLogs.push(entry);
        if (this.namedActionRefLogs.length > this.maxNamedActionRefLogs) {
            this.namedActionRefLogs = this.namedActionRefLogs.slice(-this.maxNamedActionRefLogs);
        }
    }

    /**
     * Named Actions 추적 정보를 반환합니다.
     */
    getNamedActionTrackingInfo(): NamedActionTrackingInfo {
        // 각 named action별 참조 횟수 통계
        const refCountByName: Record<string, number> = {};
        for (const log of this.namedActionRefLogs) {
            refCountByName[log.actionRefName] = (refCountByName[log.actionRefName] || 0) + 1;
        }

        // 미사용 정의 감지
        const unusedDefinitions = Object.keys(this.namedActionDefinitions).filter(
            name => !refCountByName[name]
        );

        return {
            definitions: this.namedActionDefinitions,
            refLogs: [...this.namedActionRefLogs],
            stats: {
                totalDefinitions: Object.keys(this.namedActionDefinitions).length,
                totalRefs: this.namedActionRefLogs.length,
                refCountByName,
                unusedDefinitions,
            },
            timestamp: Date.now(),
        };
    }

    /**
     * Named Actions 추적 초기화
     */
    clearNamedActionTracking(): void {
        this.namedActionDefinitions = {};
        this.namedActionRefLogs = [];
        this.namedActionRefIdCounter = 0;
    }

    // ============================================
    // 표현식 추적 메서드
    // ============================================

    /**
     * 표현식 평가 추적
     *
     * DataBindingEngine에서 표현식을 평가할 때 호출됩니다.
     */
    trackExpressionEval(info: Omit<ExpressionEvalInfo, 'id' | 'timestamp' | 'warning'>): void {
        if (!this.config.enabled) return;

        const evalInfo: ExpressionEvalInfo = {
            ...info,
            id: `expr-${++this.expressionIdCounter}`,
            timestamp: Date.now(),
            warning: this.detectExpressionWarning(info),
        };

        this.expressionHistory.push(evalInfo);

        // 이력 크기 제한 (최근 500개만 유지)
        if (this.expressionHistory.length > 500) {
            this.expressionHistory.shift();
        }

        // 프로파일링 중이면 기록
        if (this.isProfiling) {
            this.profilingData.push({
                type: 'binding',
                expression: info.expression,
                timestamp: performance.now(),
                duration: info.duration,
            });
        }

        // 감시자 알림
        this.notifyExpressionWatchers(evalInfo);
    }

    /**
     * 표현식 경고 감지
     */
    private detectExpressionWarning(info: Omit<ExpressionEvalInfo, 'id' | 'timestamp' | 'warning'>): ExpressionWarning | undefined {
        const { expression, result, resultType, method, duration } = info;

        // 1. undefined 결과 감지
        if (resultType === 'undefined' && !expression.includes('??') && !expression.includes('||')) {
            // 단순 경로 표현식인 경우 경고
            if (/^[a-zA-Z_$][a-zA-Z0-9_$.]*$/.test(expression.replace(/{{|}}/g, '').trim())) {
                return {
                    type: 'undefined-result',
                    message: `표현식 결과가 undefined입니다`,
                    suggestion: `데이터가 로드되었는지 확인하거나 fallback 값을 추가하세요: ${expression} ?? ''`,
                };
            }
        }

        // 2. null 결과 감지
        if (resultType === 'null') {
            return {
                type: 'null-result',
                message: `표현식 결과가 null입니다`,
                suggestion: `nullish coalescing 연산자를 사용하세요: ${expression} ?? ''`,
            };
        }

        // 3. 배열이 문자열로 변환됨 (resolveBindings 사용 시)
        if (method === 'resolveBindings' && resultType === 'string') {
            const resultStr = String(result);
            if (resultStr.includes('[object Object]') || /^\[.*\]$/.test(resultStr)) {
                return {
                    type: 'array-to-string',
                    message: `배열/객체가 문자열로 변환되었습니다`,
                    suggestion: `evaluateExpression()을 사용하여 원본 타입을 유지하세요`,
                };
            }
        }

        // 4. 객체가 문자열로 변환됨
        if (method === 'resolveBindings' && resultType === 'string' && String(result).includes('[object Object]')) {
            return {
                type: 'object-to-string',
                message: `객체가 [object Object]로 변환되었습니다`,
                suggestion: `evaluateExpression()을 사용하거나 특정 속성에 접근하세요`,
            };
        }

        // 5. Optional chaining 누락 의심
        if (resultType === 'undefined' && expression.includes('.') && !expression.includes('?.')) {
            const parts = expression.replace(/{{|}}/g, '').trim().split('.');
            if (parts.length >= 2) {
                return {
                    type: 'missing-optional-chain',
                    message: `Optional chaining(?.) 누락 가능성`,
                    suggestion: `안전한 접근을 위해 ?. 사용을 고려하세요: ${parts.join('?.')}`,
                };
            }
        }

        // 6. 잘못된 iteration 변수명 감지
        if (expression.includes('{{item.') || expression.includes('{{index}}')) {
            // iteration 내부에서 item/index를 직접 사용하는 경우
            const iterationVars = Array.from(this.iterations.values());
            const hasCustomVar = iterationVars.some(i => i.itemVar !== 'item');
            if (hasCustomVar) {
                return {
                    type: 'wrong-iteration-var',
                    message: `'item'/'index' 대신 iteration에서 정의한 변수명을 사용해야 합니다`,
                    suggestion: `iteration의 item_var/index_var에 정의된 변수명을 확인하세요`,
                };
            }
        }

        // 7. 느린 평가 감지 (10ms 이상)
        if (duration && duration > 10) {
            return {
                type: 'slow-evaluation',
                message: `표현식 평가에 ${duration.toFixed(2)}ms 소요됨`,
                suggestion: `복잡한 표현식을 단순화하거나 computed 값으로 분리하세요`,
            };
        }

        return undefined;
    }

    /**
     * 표현식 이력 조회
     */
    getExpressions(): ExpressionEvalInfo[] {
        return [...this.expressionHistory];
    }

    /**
     * 경고가 있는 표현식만 조회
     */
    getExpressionWarnings(): ExpressionEvalInfo[] {
        return this.expressionHistory.filter(e => e.warning != null);
    }

    /**
     * 특정 표현식 검색
     */
    searchExpressions(query: string): ExpressionEvalInfo[] {
        const lowerQuery = query.toLowerCase();
        return this.expressionHistory.filter(e =>
            e.expression.toLowerCase().includes(lowerQuery) ||
            e.componentName?.toLowerCase().includes(lowerQuery) ||
            e.propName?.toLowerCase().includes(lowerQuery)
        );
    }

    /**
     * 표현식 통계 조회
     */
    getExpressionStats(): ExpressionStats {
        const expressions = this.expressionHistory;
        const uniqueExprs = new Set(expressions.map(e => e.expression));
        const warnings = expressions.filter(e => e.warning != null);
        const cached = expressions.filter(e => e.fromCache);
        const durations = expressions.filter(e => e.duration != null).map(e => e.duration!);

        // 결과 타입별 집계
        const byType: Record<string, number> = {};
        for (const expr of expressions) {
            byType[expr.resultType] = (byType[expr.resultType] || 0) + 1;
        }

        // 경고 유형별 집계
        const byWarning: Record<string, number> = {};
        for (const expr of warnings) {
            if (expr.warning) {
                byWarning[expr.warning.type] = (byWarning[expr.warning.type] || 0) + 1;
            }
        }

        return {
            totalEvaluations: expressions.length,
            uniqueExpressions: uniqueExprs.size,
            warningCount: warnings.length,
            cacheHitRate: expressions.length > 0 ? cached.length / expressions.length : 0,
            averageDuration: durations.length > 0
                ? durations.reduce((a, b) => a + b, 0) / durations.length
                : 0,
            byType,
            byWarning,
        };
    }

    /**
     * 표현식 이력 초기화
     */
    clearExpressions(): void {
        this.expressionHistory = [];
        this.expressionIdCounter = 0;
    }

    /**
     * 표현식 감시 등록
     */
    watchExpressions(callback: ExpressionWatcherCallback): () => void {
        this.expressionWatchers.add(callback);
        return () => {
            this.expressionWatchers.delete(callback);
        };
    }

    /**
     * 표현식 감시자들에게 알림
     */
    private notifyExpressionWatchers(info: ExpressionEvalInfo): void {
        this.expressionWatchers.forEach((cb) => {
            try {
                cb(info);
            } catch (e) {
                console.error('[G7DevTools] Expression watcher error:', e);
            }
        });
    }

    // ============================================
    // 설정 메서드
    // ============================================

    /**
     * 로그 레벨 설정
     */
    setLogLevel(level: 'debug' | 'info' | 'warn' | 'error'): void {
        this.config.logLevel = level;
    }

    /**
     * 최대 이력 크기 설정
     */
    setMaxHistory(count: number): void {
        this.config.maxHistorySize = Math.max(10, Math.min(1000, count));
    }

    /**
     * 서버 엔드포인트 설정
     */
    setServerEndpoint(endpoint: string): void {
        this.config.serverEndpoint = endpoint;
    }

    /**
     * 현재 설정 조회
     */
    getConfig(): DevToolsConfig {
        return { ...this.config };
    }

    // ============================================
    // 유틸리티 메서드
    // ============================================

    /**
     * 고유 ID 생성
     */
    generateId(): string {
        return `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    }

    /**
     * 객체 직렬화 (순환 참조 제거)
     */
    private sanitizeObject(obj: any, seen = new WeakSet()): any {
        if (obj === null || typeof obj !== 'object') {
            return obj;
        }

        if (seen.has(obj)) {
            return '[Circular]';
        }

        seen.add(obj);

        if (Array.isArray(obj)) {
            return obj.map((item) => this.sanitizeObject(item, seen));
        }

        const result: Record<string, any> = {};
        for (const key of Object.keys(obj)) {
            try {
                result[key] = this.sanitizeObject(obj[key], seen);
            } catch {
                result[key] = '[Unable to serialize]';
            }
        }

        return result;
    }

    /**
     * 중첩 객체에서 값 가져오기
     */
    private getNestedValue(obj: any, path: string): any {
        if (!path) return obj;

        const parts = path.split('.');
        let current = obj;

        for (const part of parts) {
            if (current == null) return undefined;
            current = current[part];
        }

        return current;
    }

    // ============================================
    // 진단 엔진용 Getter 메서드들
    // ============================================

    /**
     * 라이프사이클 정보 반환
     */
    getLifecycleInfo(): LifecycleInfo {
        return {
            mountedComponents: Array.from(this.mountedComponents.values()),
            orphanedListeners: this.getOrphanedListeners(),
        };
    }

    /**
     * 성능 정보 반환
     */
    getPerformanceInfo(): PerformanceInfo {
        return {
            renderCounts: this.renderCounts,
            bindingEvalCount: this.bindingEvalCount,
            memoryWarnings: this.getMemoryWarnings(),
        };
    }

    /**
     * 네트워크 정보 반환
     */
    getNetworkInfo(): NetworkInfo {
        return {
            activeRequests: Array.from(this.activeRequests.values()),
            requestHistory: [...this.requestHistory],
            pendingDataSources: Array.from(this.pendingDataSources),
        };
    }

    /**
     * 조건부 렌더링 정보 반환
     */
    getConditionalInfo(): ConditionalInfo {
        return {
            ifConditions: Array.from(this.ifConditions.values()),
            iterations: Array.from(this.iterations.values()),
        };
    }

    /**
     * Form 정보 반환
     */
    getFormInfo(): FormInfo[] {
        return Array.from(this.forms.values());
    }

    /**
     * WebSocket 정보 반환
     */
    getWebSocketInfo(): WebSocketInfo {
        return {
            connections: Array.from(this.wsConnections.values()),
            messageHistory: [...this.wsMessageHistory],
            connectionState: this.getWebSocketConnectionState(),
        };
    }

    /**
     * 특정 시점의 상태 반환
     *
     * @param timestamp 조회할 시점
     * @returns 해당 시점의 상태 또는 undefined
     */
    getStateAtTime(timestamp: number): StateView | undefined {
        // 해당 시점 이전의 가장 최근 스냅샷 찾기
        const snapshot = [...this.stateHistory]
            .reverse()
            .find(s => s.timestamp <= timestamp);

        if (!snapshot) {
            return undefined;
        }

        return {
            _global: snapshot.next,
            _local: {},
        };
    }

    /**
     * 모든 데이터 초기화
     */
    reset(): void {
        this.stateHistory = [];
        this.actionHistory = [];
        this.cacheStats = { hits: 0, misses: 0, entries: 0 };
        // 캐시 결정 추적 초기화
        this.cacheDecisions = [];
        this.cacheDecisionIdCounter = 0;
        this.currentRenderCycleId = null;
        this.isInActionExecution = false;
        this.isInIteration = false;
        this.mountedComponents.clear();
        this.eventListeners.clear();
        this.renderCounts.clear();
        this.bindingEvalCount = 0;
        this.profilingData = [];
        this.activeRequests.clear();
        this.requestHistory = [];
        this.pendingDataSources.clear();
        this.wsConnections.clear();
        this.wsMessageHistory = [];
        this.ifConditions.clear();
        this.iterations.clear();
        this.forms.clear();
        this.expressionHistory = [];
        this.expressionIdCounter = 0;
        this.dataSources.clear();
        // 데이터 경로 변환 초기화
        this.dataPathTransforms = [];
        // Nested Context 초기화
        this.nestedContexts = [];
        this.nestedContextIdCounter = 0;
        // Form 바인딩 검증 초기화
        this.formBindingIssues = [];
        this.formBindingValidations = [];
        this.formBindingIssueIdCounter = 0;
        // Computed 의존성 추적 초기화
        this.computedProperties.clear();
        this.computedRecalcLogs = [];
        this.computedRecalcIdCounter = 0;
        // 모달 상태 스코프 추적 초기화
        this.modalStates.clear();
        this.modalStateIssues = [];
        this.modalStateRelations = [];
        this.modalStateChangeLogs = [];
        this.modalStateIssueIdCounter = 0;
        this.modalStateChangeLogIdCounter = 0;
        // 로그 초기화
        this.logHistory = [];
        this.logIdCounter = 0;
    }

    /**
     * 표현식 정보 반환 (진단 엔진용)
     */
    getExpressionInfo(): { expressions: ExpressionEvalInfo[]; stats: ExpressionStats } {
        return {
            expressions: this.getExpressions(),
            stats: this.getExpressionStats(),
        };
    }

    // ============================================
    // 로그 추적 메서드
    // ============================================

    /**
     * 로그 추적
     *
     * Logger에서 호출되어 로그를 DevTools에 기록합니다.
     */
    trackLog(level: LogLevel, prefix: string, args: unknown[]): void {
        if (!this.config.enabled) return;

        // 메시지 직렬화
        const message = args
            .map(arg => {
                if (typeof arg === 'string') return arg;
                try {
                    return JSON.stringify(arg);
                } catch {
                    return String(arg);
                }
            })
            .join(' ');

        const entry: LogEntry = {
            id: `log-${++this.logIdCounter}`,
            level,
            prefix,
            message,
            args: this.sanitizeObject(args),
            timestamp: Date.now(),
            stack: level === 'error' ? new Error().stack : undefined,
        };

        this.logHistory.push(entry);

        // 이력 크기 제한
        if (this.logHistory.length > this.maxLogHistory) {
            this.logHistory.shift();
        }
    }

    // ============================================
    // Debounce 액션 및 부분 업데이트 추적
    // ============================================

    /** debounce 액션 이력 */
    private debounceActionHistory: Array<{
        id: string;
        handler: string;
        type: string;
        status: 'pending' | 'success' | 'error' | 'cancelled';
        params?: Record<string, any>;
        debounce?: {
            delay: number;
            status: 'pending' | 'executed' | 'cancelled';
            scheduledAt?: number;
            executedAt?: number;
            mode?: 'leading' | 'trailing';
        };
        timestamp: number;
    }> = [];

    /** 데이터소스 부분 업데이트 이력 */
    private dataSourceUpdateHistory: Array<{
        id: string;
        dataSourceId: string;
        updateType: 'full' | 'partial';
        itemPath?: string;
        itemId?: string | number;
        updates?: Record<string, any>;
        timestamp: number;
    }> = [];

    private debounceActionIdCounter = 0;
    private dataSourceUpdateIdCounter = 0;

    /**
     * debounce 액션 추적
     *
     * debounce 옵션이 있는 액션의 실행 상태를 추적합니다.
     */
    trackAction(info: {
        handler: string;
        type: string;
        status: 'pending' | 'success' | 'error' | 'cancelled';
        params?: Record<string, any>;
        debounce?: {
            delay: number;
            status: 'pending' | 'executed' | 'cancelled';
            scheduledAt?: number;
            executedAt?: number;
            mode?: 'leading' | 'trailing';
        };
    }): void {
        if (!this.config.enabled) return;

        const entry = {
            id: `debounce-action-${++this.debounceActionIdCounter}`,
            ...info,
            timestamp: Date.now(),
        };

        this.debounceActionHistory.push(entry);

        // 이력 크기 제한 (최대 100개)
        if (this.debounceActionHistory.length > 100) {
            this.debounceActionHistory.shift();
        }
    }

    /**
     * 데이터소스 부분 업데이트 추적
     *
     * G7Core.dataSource.updateItem() 호출을 추적합니다.
     */
    trackDataSourceUpdate(info: {
        dataSourceId: string;
        updateType: 'full' | 'partial';
        itemPath?: string;
        itemId?: string | number;
        updates?: Record<string, any>;
        timestamp: number;
    }): void {
        if (!this.config.enabled) return;

        const entry = {
            id: `ds-update-${++this.dataSourceUpdateIdCounter}`,
            ...info,
        };

        this.dataSourceUpdateHistory.push(entry);

        // 이력 크기 제한 (최대 100개)
        if (this.dataSourceUpdateHistory.length > 100) {
            this.dataSourceUpdateHistory.shift();
        }
    }

    /**
     * debounce 액션 이력 조회
     */
    getDebounceActionHistory(options?: {
        handler?: string;
        status?: 'pending' | 'success' | 'error' | 'cancelled';
        limit?: number;
    }): typeof this.debounceActionHistory {
        let history = [...this.debounceActionHistory];

        if (options?.handler) {
            history = history.filter(h => h.handler.includes(options.handler!));
        }

        if (options?.status) {
            history = history.filter(h => h.status === options.status);
        }

        if (options?.limit) {
            history = history.slice(-options.limit);
        }

        return history;
    }

    /**
     * 데이터소스 업데이트 이력 조회
     */
    getDataSourceUpdateHistory(options?: {
        dataSourceId?: string;
        updateType?: 'full' | 'partial';
        limit?: number;
    }): typeof this.dataSourceUpdateHistory {
        let history = [...this.dataSourceUpdateHistory];

        if (options?.dataSourceId) {
            history = history.filter(h => h.dataSourceId === options.dataSourceId);
        }

        if (options?.updateType) {
            history = history.filter(h => h.updateType === options.updateType);
        }

        if (options?.limit) {
            history = history.slice(-options.limit);
        }

        return history;
    }

    /**
     * 로그 이력 조회
     */
    getLogs(options?: LogFilterOptions): LogEntry[] {
        let logs = [...this.logHistory];

        if (options?.level) {
            const levels = Array.isArray(options.level)
                ? options.level
                : [options.level];
            logs = logs.filter(log => levels.includes(log.level));
        }

        if (options?.prefix) {
            const prefixLower = options.prefix.toLowerCase();
            logs = logs.filter(log =>
                log.prefix.toLowerCase().includes(prefixLower)
            );
        }

        if (options?.search) {
            const searchLower = options.search.toLowerCase();
            logs = logs.filter(log =>
                log.message.toLowerCase().includes(searchLower)
            );
        }

        if (options?.since) {
            logs = logs.filter(log => log.timestamp >= options.since!);
        }

        if (options?.limit) {
            logs = logs.slice(-options.limit);
        }

        return logs;
    }

    /**
     * 로그 통계 조회
     */
    getLogStats(): LogStats {
        const now = Date.now();
        const oneMinuteAgo = now - 60000;

        const byLevel: Record<LogLevel, number> = {
            log: 0,
            warn: 0,
            error: 0,
            debug: 0,
            info: 0,
        };

        const byPrefix: Record<string, number> = {};
        let recentErrors = 0;
        let recentWarnings = 0;

        for (const entry of this.logHistory) {
            // 레벨별 집계
            byLevel[entry.level]++;

            // Prefix별 집계
            byPrefix[entry.prefix] = (byPrefix[entry.prefix] || 0) + 1;

            // 최근 에러/경고 집계
            if (entry.timestamp >= oneMinuteAgo) {
                if (entry.level === 'error') recentErrors++;
                if (entry.level === 'warn') recentWarnings++;
            }
        }

        return {
            totalLogs: this.logHistory.length,
            byLevel,
            byPrefix,
            recentErrors,
            recentWarnings,
        };
    }

    /**
     * 로그 이력 초기화
     */
    clearLogs(): void {
        this.logHistory = [];
        this.logIdCounter = 0;
    }

    /**
     * 로그 정보 반환 (상태 덤프용)
     *
     * entries와 stats가 동일한 logHistory 전체를 기반으로 계산됩니다.
     * limit을 제거하여 stats와 entries 간 불일치를 방지합니다.
     */
    getLogInfo(): LogInfo {
        return {
            entries: this.getLogs(),
            stats: this.getLogStats(),
        };
    }

    // ============================================
    // 데이터소스 추적 메서드
    // ============================================

    /**
     * 데이터소스 등록
     *
     * DataSourceManager에서 데이터소스 정의 시 호출됩니다.
     */
    trackDataSourceDefinition(info: Omit<DataSourceInfo, 'status' | 'lastLoadedAt'>): void {
        if (!this.config.enabled) return;

        this.dataSources.set(info.id, {
            ...info,
            status: 'idle',
        });
    }

    /**
     * 데이터소스 로딩 시작
     */
    trackDataSourceLoading(id: string): void {
        if (!this.config.enabled) return;

        const existing = this.dataSources.get(id);
        if (existing) {
            this.dataSources.set(id, { ...existing, status: 'loading' });
        }
    }

    /**
     * 데이터소스 로드 완료
     */
    trackDataSourceLoaded(id: string, data: any, dataPath?: string): void {
        if (!this.config.enabled) return;

        const existing = this.dataSources.get(id);
        if (!existing) return;

        // 데이터 구조 분석
        let itemCount: number | undefined;
        let keys: string[] | undefined;
        let resolvedDataPath = dataPath;

        // 데이터 경로 자동 감지
        if (data) {
            if (Array.isArray(data)) {
                itemCount = data.length;
                keys = data.length > 0 && typeof data[0] === 'object' ? Object.keys(data[0]) : undefined;
            } else if (typeof data === 'object') {
                // 일반적인 API 응답 구조 감지 (data.data, data.items 등)
                if (Array.isArray(data.data)) {
                    resolvedDataPath = resolvedDataPath || 'data';
                    itemCount = data.data.length;
                    keys = data.data.length > 0 && typeof data.data[0] === 'object' ? Object.keys(data.data[0]) : undefined;
                } else if (Array.isArray(data.items)) {
                    resolvedDataPath = resolvedDataPath || 'items';
                    itemCount = data.items.length;
                    keys = data.items.length > 0 && typeof data.items[0] === 'object' ? Object.keys(data.items[0]) : undefined;
                } else {
                    keys = Object.keys(data);
                }
            }
        }

        this.dataSources.set(id, {
            ...existing,
            status: 'loaded',
            dataPath: resolvedDataPath,
            itemCount,
            keys,
            lastLoadedAt: Date.now(),
            error: undefined,
        });
    }

    /**
     * 데이터소스 로드 실패
     */
    trackDataSourceError(id: string, error: string): void {
        if (!this.config.enabled) return;

        const existing = this.dataSources.get(id);
        if (existing) {
            this.dataSources.set(id, {
                ...existing,
                status: 'error',
                error,
                lastLoadedAt: Date.now(),
            });
        }
    }

    /**
     * 데이터소스 제거
     */
    untrackDataSource(id: string): void {
        this.dataSources.delete(id);
    }

    /**
     * 모든 데이터소스 정보 조회
     */
    getDataSources(): DataSourceInfo[] {
        return Array.from(this.dataSources.values());
    }

    /**
     * 특정 데이터소스 정보 조회
     */
    getDataSource(id: string): DataSourceInfo | undefined {
        return this.dataSources.get(id);
    }

    /**
     * 데이터소스 초기화
     */
    clearDataSources(): void {
        this.dataSources.clear();
    }

    // ============================================
    // 데이터 경로 변환 추적 메서드
    // ============================================

    /**
     * 데이터 경로 변환 추적
     *
     * API 응답 → actualData → initGlobal/initLocal 과정을 추적합니다.
     */
    trackDataPathTransform(info: {
        dataSourceId: string;
        step: import('./types').DataPathTransformStep;
        inputPath: string;
        inputValue: any;
        outputPath: string;
        outputValue: any;
        config?: {
            path?: string;
            initGlobal?: any;
            initLocal?: any;
        };
        warning?: string;
    }): void {
        if (!this.config.enabled) return;

        // 기존 변환 정보 찾기 또는 새로 생성
        let transform = this.dataPathTransforms.find(t => t.dataSourceId === info.dataSourceId);

        if (!transform) {
            transform = {
                dataSourceId: info.dataSourceId,
                timestamp: Date.now(),
                transformSteps: [],
                warnings: [],
            };
            this.dataPathTransforms.push(transform);

            // 최대 크기 제한
            if (this.dataPathTransforms.length > this.maxDataPathTransforms) {
                this.dataPathTransforms.shift();
            }
        }

        // 변환 단계 추가
        transform.transformSteps.push({
            step: info.step,
            inputPath: info.inputPath,
            inputValue: this.safeClone(info.inputValue),
            outputPath: info.outputPath,
            outputValue: this.safeClone(info.outputValue),
            config: info.config,
        });

        // 경고 추가
        if (info.warning) {
            transform.warnings.push(info.warning);
        }
    }

    /**
     * 데이터 경로 변환 최종 바인딩 설정
     */
    setDataPathFinalBinding(dataSourceId: string, binding: {
        expression: string;
        resolvedPath: string;
        value: any;
    }): void {
        if (!this.config.enabled) return;

        const transform = this.dataPathTransforms.find(t => t.dataSourceId === dataSourceId);
        if (transform) {
            transform.finalBinding = {
                expression: binding.expression,
                resolvedPath: binding.resolvedPath,
                value: this.safeClone(binding.value),
            };
        }
    }

    /**
     * 데이터 경로 변환 추적 정보 반환
     */
    getDataPathTransformTrackingInfo(): import('./types').DataPathTransformTrackingInfo {
        const stats = this.getDataPathTransformStats();

        return {
            transforms: [...this.dataPathTransforms],
            stats,
            timestamp: Date.now(),
        };
    }

    /**
     * 데이터 경로 변환 통계 계산
     */
    private getDataPathTransformStats(): import('./types').DataPathTransformStats {
        const transforms = this.dataPathTransforms;

        // 데이터소스별 개수
        const byDataSource: Record<string, number> = {};
        let warningsCount = 0;
        const warningCounts: Record<string, number> = {};

        for (const t of transforms) {
            byDataSource[t.dataSourceId] = (byDataSource[t.dataSourceId] || 0) + 1;
            warningsCount += t.warnings.length;

            for (const w of t.warnings) {
                warningCounts[w] = (warningCounts[w] || 0) + 1;
            }
        }

        // 자주 발생하는 경고
        const commonWarnings = Object.entries(warningCounts)
            .map(([warning, count]) => ({ warning, count }))
            .sort((a, b) => b.count - a.count)
            .slice(0, 10);

        return {
            totalTransforms: transforms.length,
            byDataSource,
            warningsCount,
            commonWarnings,
        };
    }

    /**
     * 특정 데이터소스의 변환 추적 정보 조회
     */
    getDataPathTransformForDataSource(dataSourceId: string): import('./types').DataPathTransformInfo | undefined {
        return this.dataPathTransforms.find(t => t.dataSourceId === dataSourceId);
    }

    /**
     * 데이터 경로 변환 데이터 초기화
     */
    clearDataPathTransforms(): void {
        this.dataPathTransforms = [];
    }

    // ============================================
    // Nested Context 추적 메서드
    // ============================================

    /**
     * Nested Context 추적
     *
     * expandChildren, cellChildren, iteration, modal 등 중첩 렌더링 컨텍스트를 추적합니다.
     */
    trackNestedContext(info: {
        componentId: string;
        componentType: import('./types').NestedContextType;
        parentContext: {
            available: string[];
            values: Record<string, any>;
        };
        ownContext: {
            added: string[];
            values: Record<string, any>;
        };
        depth: number;
        parentId?: string;
    }): string {
        if (!this.config.enabled) return '';

        const id = `nested-${++this.nestedContextIdCounter}`;

        // 병합된 컨텍스트 계산
        const mergedAll = [...new Set([...info.parentContext.available, ...info.ownContext.added])];
        const mergedValues = { ...info.parentContext.values, ...info.ownContext.values };

        const context: import('./types').NestedContextInfo = {
            id,
            timestamp: Date.now(),
            componentId: info.componentId,
            componentType: info.componentType,
            parentContext: {
                available: info.parentContext.available,
                values: this.safeClone(info.parentContext.values),
            },
            ownContext: {
                added: info.ownContext.added,
                values: this.safeClone(info.ownContext.values),
            },
            mergedContext: {
                all: mergedAll,
                values: this.safeClone(mergedValues),
            },
            accessAttempts: [],
            depth: info.depth,
            parentId: info.parentId,
        };

        this.nestedContexts.push(context);

        // 최대 크기 제한
        if (this.nestedContexts.length > this.maxNestedContexts) {
            this.nestedContexts.shift();
        }

        return id;
    }

    /**
     * Nested Context 접근 시도 기록
     */
    trackNestedContextAccess(contextId: string, access: {
        path: string;
        found: boolean;
        value?: any;
        error?: string;
    }): void {
        if (!this.config.enabled) return;

        const context = this.nestedContexts.find(c => c.id === contextId);
        if (context) {
            context.accessAttempts.push({
                path: access.path,
                found: access.found,
                value: access.found ? this.safeClone(access.value) : undefined,
                error: access.error,
            });
        }
    }

    /**
     * Nested Context 추적 정보 반환
     */
    getNestedContextTrackingInfo(): import('./types').NestedContextTrackingInfo {
        const stats = this.getNestedContextStats();

        return {
            contexts: [...this.nestedContexts],
            stats,
            timestamp: Date.now(),
        };
    }

    /**
     * Nested Context 통계 계산
     */
    private getNestedContextStats(): import('./types').NestedContextStats {
        const contexts = this.nestedContexts;

        // 타입별 개수
        const byType: Record<import('./types').NestedContextType, number> = {
            expandChildren: 0,
            cellChildren: 0,
            iteration: 0,
            modal: 0,
            slot: 0,
        };

        let maxDepth = 0;
        let failedAccessCount = 0;
        const failedPaths: Record<string, number> = {};

        for (const c of contexts) {
            byType[c.componentType]++;
            if (c.depth > maxDepth) maxDepth = c.depth;

            for (const attempt of c.accessAttempts) {
                if (!attempt.found) {
                    failedAccessCount++;
                    failedPaths[attempt.path] = (failedPaths[attempt.path] || 0) + 1;
                }
            }
        }

        // 자주 실패하는 경로
        const commonFailedPaths = Object.entries(failedPaths)
            .map(([path, count]) => ({ path, count }))
            .sort((a, b) => b.count - a.count)
            .slice(0, 10);

        return {
            totalContexts: contexts.length,
            byType,
            maxDepth,
            failedAccessCount,
            commonFailedPaths,
        };
    }

    /**
     * 특정 컴포넌트의 Nested Context 조회
     */
    getNestedContextForComponent(componentId: string): import('./types').NestedContextInfo | undefined {
        return this.nestedContexts.find(c => c.componentId === componentId);
    }

    /**
     * Nested Context 데이터 초기화
     */
    clearNestedContexts(): void {
        this.nestedContexts = [];
        this.nestedContextIdCounter = 0;
    }

    // ============================================
    // 핸들러 추적 메서드
    // ============================================

    /**
     * 핸들러 등록 추적
     *
     * @param name 핸들러 이름
     * @param category 핸들러 카테고리
     * @param description 핸들러 설명
     * @param source 핸들러 소스 (모듈명 등)
     */
    trackHandlerRegistration(
        name: string,
        category: HandlerInfo['category'] = 'custom',
        description?: string,
        source?: string
    ): void {
        if (!this.isEnabled) return;

        this.handlers.set(name, {
            name,
            category,
            description,
            registeredAt: Date.now(),
            source,
        });
    }

    /**
     * 핸들러 해제 추적
     *
     * @param name 핸들러 이름
     */
    trackHandlerUnregistration(name: string): void {
        if (!this.isEnabled) return;
        this.handlers.delete(name);
    }

    /**
     * 모든 핸들러 정보 조회
     */
    getHandlers(): HandlerInfo[] {
        return Array.from(this.handlers.values());
    }

    /**
     * 특정 핸들러 정보 조회
     */
    getHandler(name: string): HandlerInfo | undefined {
        return this.handlers.get(name);
    }

    /**
     * 카테고리별 핸들러 조회
     */
    getHandlersByCategory(category: HandlerInfo['category']): HandlerInfo[] {
        return Array.from(this.handlers.values()).filter(h => h.category === category);
    }

    /**
     * 핸들러 초기화
     */
    clearHandlers(): void {
        this.handlers.clear();
    }

    // ============================================
    // 컴포넌트 이벤트 추적 메서드
    // ============================================

    /**
     * 이벤트 구독 추적
     *
     * @param eventName 이벤트 이름
     */
    trackEventSubscribe(eventName: string): void {
        if (!this.isEnabled) return;

        const existing = this.componentEventSubscriptions.get(eventName);
        const now = Date.now();

        if (existing) {
            existing.subscriberCount++;
            existing.lastSubscribedAt = now;
        } else {
            this.componentEventSubscriptions.set(eventName, {
                eventName,
                subscriberCount: 1,
                firstSubscribedAt: now,
                lastSubscribedAt: now,
            });
        }
    }

    /**
     * 이벤트 구독 해제 추적
     *
     * @param eventName 이벤트 이름
     */
    trackEventUnsubscribe(eventName: string): void {
        if (!this.isEnabled) return;

        const existing = this.componentEventSubscriptions.get(eventName);
        if (existing) {
            existing.subscriberCount--;
            if (existing.subscriberCount <= 0) {
                this.componentEventSubscriptions.delete(eventName);
            }
        }
    }

    /**
     * 이벤트 발생 추적
     *
     * @param eventName 이벤트 이름
     * @param data 전달 데이터
     * @param listenerCount 리스너 수
     * @param results 리스너 결과
     * @param error 에러 (있는 경우)
     */
    trackEventEmit(
        eventName: string,
        data: any,
        listenerCount: number,
        results?: any[],
        error?: Error
    ): void {
        if (!this.isEnabled) return;

        const log: ComponentEventEmitLog = {
            id: `evt_${++this.componentEventIdCounter}`,
            eventName,
            data: this.sanitizeForLogging(data),
            timestamp: Date.now(),
            listenerCount,
            results: results ? this.sanitizeForLogging(results) : undefined,
            hasError: !!error,
            errorMessage: error?.message,
        };

        this.componentEventEmitHistory.push(log);

        // 최대 이력 개수 제한
        if (this.componentEventEmitHistory.length > this.maxComponentEventHistory) {
            this.componentEventEmitHistory = this.componentEventEmitHistory.slice(-this.maxComponentEventHistory);
        }
    }

    /**
     * 특정 이벤트의 모든 리스너 제거 추적
     *
     * @param eventName 이벤트 이름
     */
    trackEventOff(eventName: string): void {
        if (!this.isEnabled) return;
        this.componentEventSubscriptions.delete(eventName);
    }

    /**
     * 모든 이벤트 리스너 제거 추적
     */
    trackEventClear(): void {
        if (!this.isEnabled) return;
        this.componentEventSubscriptions.clear();
    }

    /**
     * 컴포넌트 이벤트 정보 조회
     */
    getComponentEventInfo(): ComponentEventInfo {
        const subscriptions = Array.from(this.componentEventSubscriptions.values());
        return {
            subscriptions,
            emitHistory: [...this.componentEventEmitHistory],
            totalSubscribers: subscriptions.reduce((sum, s) => sum + s.subscriberCount, 0),
            totalEmits: this.componentEventEmitHistory.length,
        };
    }

    /**
     * 이벤트 emit 이력 조회
     */
    getEventEmitHistory(): ComponentEventEmitLog[] {
        return [...this.componentEventEmitHistory];
    }

    /**
     * 이벤트 구독 목록 조회
     */
    getEventSubscriptions(): ComponentEventSubscription[] {
        return Array.from(this.componentEventSubscriptions.values());
    }

    /**
     * 컴포넌트 이벤트 초기화
     */
    clearComponentEvents(): void {
        this.componentEventSubscriptions.clear();
        this.componentEventEmitHistory = [];
        this.componentEventIdCounter = 0;
    }

    /**
     * 로깅용 데이터 정제 (순환 참조 제거)
     */
    private sanitizeForLogging(data: any): any {
        if (data === null || data === undefined) return data;
        if (typeof data !== 'object') return data;

        try {
            return JSON.parse(JSON.stringify(data));
        } catch {
            return '[Circular or Non-serializable]';
        }
    }

    // ============================================
    // 상태-렌더링 추적 메서드
    // ============================================

    /**
     * 상태 변경 시작 추적
     *
     * setState 호출 시 ActionDispatcher에서 호출됩니다.
     *
     * @param statePath 변경된 상태 경로
     * @param oldValue 이전 값
     * @param newValue 새 값
     * @param trigger 트리거 정보 (액션 ID, 핸들러 타입 등)
     * @returns setState ID (렌더링 추적에 사용)
     */
    startStateChange(
        statePath: string,
        oldValue: any,
        newValue: any,
        trigger?: { actionId?: string; handlerType?: string; source?: string }
    ): string {
        if (!this.config.enabled) return '';

        const setStateId = `setState_${++this.stateRenderingIdCounter}`;

        this.currentStateChangeContext = {
            setStateId,
            startTime: performance.now(),
            changedPath: statePath,
            trigger: trigger || {},
            renderedComponents: [],
        };

        // 상태 변경 로그 생성 (렌더링 완료 시 업데이트됨)
        const log: StateRenderingLog = {
            id: `sr_${this.stateRenderingIdCounter}`,
            setStateId,
            statePath,
            oldValue: this.sanitizeForLogging(oldValue),
            newValue: this.sanitizeForLogging(newValue),
            timestamp: Date.now(),
            triggeredBy: trigger || {},
            renderedComponents: [],
            totalRenderDuration: 0,
            affectedBindingsCount: 0,
        };

        this.stateRenderingLogs.push(log);

        // 로그 크기 제한
        if (this.stateRenderingLogs.length > this.maxStateRenderingLogs) {
            this.stateRenderingLogs.shift();
        }

        return setStateId;
    }

    /**
     * 컴포넌트 렌더링 추적 (상태 변경 컨텍스트 내에서)
     *
     * 상태 변경으로 인해 컴포넌트가 렌더링될 때 호출됩니다.
     *
     * @param componentId 컴포넌트 ID
     * @param componentName 컴포넌트 이름
     * @param renderDuration 렌더링 소요 시간
     * @param accessedStatePaths 접근한 상태 경로들
     * @param evaluatedBindings 평가된 바인딩 표현식들
     * @param parentId 부모 컴포넌트 ID
     */
    trackComponentRender(
        componentId: string,
        componentName: string,
        renderDuration: number,
        accessedStatePaths: string[] = [],
        evaluatedBindings: string[] = [],
        parentId?: string
    ): void {
        if (!this.config.enabled) return;

        // 컴포넌트별 렌더링 횟수 증가
        const count = this.componentRenderCounts.get(componentName) || 0;
        this.componentRenderCounts.set(componentName, count + 1);

        // 상태 경로 → 컴포넌트 매핑 업데이트
        for (const path of accessedStatePaths) {
            if (!this.stateToComponentMap.has(path)) {
                this.stateToComponentMap.set(path, new Set());
            }
            this.stateToComponentMap.get(path)!.add(componentName);
        }

        // 현재 상태 변경 컨텍스트가 있으면 렌더링 정보 추가
        if (this.currentStateChangeContext) {
            const renderInfo: RenderedComponentInfo = {
                componentId,
                componentName,
                renderDuration,
                accessedStatePaths,
                evaluatedBindings,
                renderOrder: this.currentStateChangeContext.renderedComponents.length,
                parentId,
            };

            this.currentStateChangeContext.renderedComponents.push(renderInfo);
        }

        // 기본 렌더링 추적도 유지
        this.trackRender(componentName);
    }

    /**
     * 상태 변경 완료 추적
     *
     * 상태 변경으로 인한 모든 렌더링이 완료된 후 호출됩니다.
     *
     * @param setStateId 시작 시 반환받은 setState ID
     */
    completeStateChange(setStateId: string): void {
        if (!this.config.enabled || !this.currentStateChangeContext) return;
        if (this.currentStateChangeContext.setStateId !== setStateId) return;

        const context = this.currentStateChangeContext;
        const endTime = performance.now();
        const totalDuration = endTime - context.startTime;

        // 해당 로그 찾아서 업데이트
        const logIndex = this.stateRenderingLogs.findIndex(
            log => log.setStateId === setStateId
        );

        if (logIndex >= 0) {
            const log = this.stateRenderingLogs[logIndex];
            log.renderedComponents = context.renderedComponents;
            log.totalRenderDuration = totalDuration;
            log.affectedBindingsCount = context.renderedComponents.reduce(
                (sum, c) => sum + c.evaluatedBindings.length,
                0
            );
        }

        // 컨텍스트 초기화
        this.currentStateChangeContext = null;
    }

    /**
     * 현재 상태 변경 컨텍스트 조회
     */
    getCurrentStateChangeContext(): StateChangeContext | null {
        return this.currentStateChangeContext;
    }

    /**
     * 상태-렌더링 로그 조회
     */
    getStateRenderingLogs(): StateRenderingLog[] {
        return [...this.stateRenderingLogs];
    }

    /**
     * 상태-렌더링 정보 전체 조회
     */
    getStateRenderingInfo(): StateRenderingInfo {
        const logs = this.stateRenderingLogs;
        const componentCounts = Object.fromEntries(this.componentRenderCounts);
        const stateToComponent: Record<string, string[]> = {};

        for (const [path, components] of this.stateToComponentMap) {
            stateToComponent[path] = Array.from(components);
        }

        return {
            logs,
            componentRenderCounts: componentCounts,
            stateToComponentMap: stateToComponent,
            stats: this.calculateStateRenderingStats(),
        };
    }

    /**
     * 상태-렌더링 통계 계산
     */
    private calculateStateRenderingStats(): StateRenderingStats {
        const logs = this.stateRenderingLogs;

        if (logs.length === 0) {
            return {
                totalStateChanges: 0,
                totalRenders: 0,
                avgRenderDuration: 0,
                avgComponentsPerChange: 0,
                topRenderedComponents: [],
                topInfluentialPaths: [],
            };
        }

        // 총 렌더링 수
        const totalRenders = logs.reduce(
            (sum, log) => sum + log.renderedComponents.length,
            0
        );

        // 평균 렌더링 시간
        const avgRenderDuration =
            logs.reduce((sum, log) => sum + log.totalRenderDuration, 0) / logs.length;

        // 상태 변경당 평균 컴포넌트 수
        const avgComponentsPerChange = totalRenders / logs.length;

        // 가장 많이 렌더링된 컴포넌트 TOP 5
        const sortedComponents = Array.from(this.componentRenderCounts.entries())
            .sort((a, b) => b[1] - a[1])
            .slice(0, 5)
            .map(([name, count]) => ({ name, count }));

        // 가장 영향력 있는 상태 경로 TOP 5
        const sortedPaths = Array.from(this.stateToComponentMap.entries())
            .sort((a, b) => b[1].size - a[1].size)
            .slice(0, 5)
            .map(([path, components]) => ({
                path,
                affectedComponents: components.size,
            }));

        return {
            totalStateChanges: logs.length,
            totalRenders,
            avgRenderDuration,
            avgComponentsPerChange,
            topRenderedComponents: sortedComponents,
            topInfluentialPaths: sortedPaths,
        };
    }

    /**
     * 특정 상태 경로에 영향받는 컴포넌트 조회
     */
    getComponentsAffectedByState(statePath: string): string[] {
        const components = this.stateToComponentMap.get(statePath);
        return components ? Array.from(components) : [];
    }

    /**
     * 특정 컴포넌트의 렌더링 이력 조회
     */
    getComponentRenderHistory(componentName: string): StateRenderingLog[] {
        return this.stateRenderingLogs.filter(log =>
            log.renderedComponents.some(c => c.componentName === componentName)
        );
    }

    /**
     * 상태-렌더링 추적 데이터 초기화
     */
    clearStateRenderingLogs(): void {
        this.stateRenderingLogs = [];
        this.componentRenderCounts.clear();
        this.stateToComponentMap.clear();
        this.currentStateChangeContext = null;
        this.stateRenderingIdCounter = 0;
    }

    // ============================================
    // 상태 계층 추적 메서드
    // ============================================

    /**
     * 컴포넌트의 상태 소스 추적
     *
     * 컴포넌트가 어떤 상태 소스에서 데이터를 읽는지 추적합니다.
     *
     * @param componentId 컴포넌트 ID
     * @param componentName 컴포넌트 이름
     * @param stateSource 상태 소스 정보
     * @param stateProvider 상태 제공자 정보
     */
    trackComponentStateSource(
        componentId: string,
        componentName: string,
        stateSource: {
            global: string[];
            local: string[];
            context: string[];
        },
        stateProvider: {
            type: 'globalState' | 'dynamicState' | 'componentContext';
            hasComponentContext: boolean;
            parentId?: string;
        }
    ): void {
        if (!this.config.enabled) return;

        this.componentStateSources.set(componentId, {
            componentId,
            componentName,
            stateSource,
            stateProvider,
        });
    }

    /**
     * dynamicState 업데이트 추적
     *
     * 컴포넌트의 dynamicState가 업데이트될 때 호출됩니다.
     *
     * @param componentId 컴포넌트 ID
     * @param dynamicState 동적 상태 값
     */
    trackDynamicState(componentId: string, dynamicState: Record<string, any>): void {
        if (!this.config.enabled) return;

        this.dynamicStates.set(componentId, dynamicState);
    }

    /**
     * componentContext 흐름 추적
     *
     * 컴포넌트가 context를 받고 전달하는 과정을 추적합니다.
     *
     * @param componentId 컴포넌트 ID
     * @param componentName 컴포넌트 이름
     * @param contextReceived context를 받았는지
     * @param passedToChildren 자식에게 전달했는지
     * @param usedInRender 렌더링에 사용했는지
     * @param parentId 부모 컴포넌트 ID
     */
    trackContextFlow(
        componentId: string,
        componentName: string,
        contextReceived: boolean,
        passedToChildren: boolean,
        usedInRender: boolean,
        parentId?: string
    ): void {
        if (!this.config.enabled) return;

        const node: ContextFlowNode = {
            component: componentName,
            componentId,
            contextReceived,
            passedToChildren,
            usedInRender,
        };

        this.contextFlowNodes.set(componentId, node);

        // 부모 노드가 있으면 자식으로 연결
        if (parentId) {
            const parentNode = this.contextFlowNodes.get(parentId);
            if (parentNode) {
                if (!parentNode.children) {
                    parentNode.children = [];
                }
                parentNode.children.push(node);
            }
        }
    }

    /**
     * 상태 계층 정보 조회
     *
     * 현재 상태 계층, 충돌 감지, 컴포넌트별 상태 소스 정보를 반환합니다.
     * 덤프 시에는 간소화된 버전을 사용하여 파일 크기를 줄입니다.
     */
    getStateHierarchyInfo(summarize = true): StateHierarchyInfo {
        const layers = this.buildStateHierarchyLayers();
        const conflicts = this.detectStateConflicts(layers);
        const componentStateSources = Array.from(this.componentStateSources.values());

        // 덤프 시 간소화: 큰 values 객체를 요약
        const summarizedLayers = summarize
            ? layers.map(layer => ({
                ...layer,
                values: this.summarizeValues(layer.values, 2),
            }))
            : layers;

        return {
            layers: summarizedLayers,
            conflicts,
            componentStateSources,
            timestamp: Date.now(),
        };
    }

    /**
     * 값을 요약하여 덤프 크기 감소
     *
     * - 배열: 처음 3개 항목 + 길이 정보
     * - 객체: 지정된 깊이까지만 전개
     * - 문자열: 100자 제한
     */
    private summarizeValues(obj: any, maxDepth: number, currentDepth = 0): any {
        if (obj === null || obj === undefined) return obj;

        // 기본 타입
        if (typeof obj !== 'object') {
            if (typeof obj === 'string' && obj.length > 100) {
                return obj.substring(0, 100) + `... (${obj.length}자)`;
            }
            return obj;
        }

        // 깊이 제한 도달
        if (currentDepth >= maxDepth) {
            if (Array.isArray(obj)) {
                return `[Array(${obj.length})]`;
            }
            const keys = Object.keys(obj);
            return `{${keys.slice(0, 5).join(', ')}${keys.length > 5 ? `, ... +${keys.length - 5}` : ''}}`;
        }

        // 배열 처리
        if (Array.isArray(obj)) {
            if (obj.length <= 3) {
                return obj.map(item => this.summarizeValues(item, maxDepth, currentDepth + 1));
            }
            return {
                __type: 'array',
                __length: obj.length,
                __preview: obj.slice(0, 3).map(item => this.summarizeValues(item, maxDepth, currentDepth + 1)),
            };
        }

        // 객체 처리
        const result: Record<string, any> = {};
        const keys = Object.keys(obj);

        for (const key of keys) {
            result[key] = this.summarizeValues(obj[key], maxDepth, currentDepth + 1);
        }

        return result;
    }

    /**
     * 상태 계층 레이어 빌드
     */
    private buildStateHierarchyLayers(): StateHierarchyLayer[] {
        const layers: StateHierarchyLayer[] = [];

        try {
            const g7Core = (window as any).G7Core;
            const state = g7Core?.state?.get?.();

            if (!state) return layers;

            // 1. Global _local 레이어
            if (state._local) {
                layers.push({
                    name: 'Global _local',
                    type: 'global',
                    path: '_local',
                    values: state._local,
                    priority: 1,
                });
            }

            // 2. Global _global 레이어
            if (state._global) {
                layers.push({
                    name: 'Global _global',
                    type: 'global',
                    path: '_global',
                    values: state._global,
                    priority: 1,
                });
            }

            // 3. DynamicState 레이어들 (우선순위 높음)
            for (const [componentId, dynamicState] of this.dynamicStates) {
                if (dynamicState && Object.keys(dynamicState).length > 0) {
                    layers.push({
                        name: `DynamicState (${componentId})`,
                        type: 'dynamicState',
                        componentId,
                        values: dynamicState,
                        priority: 2,
                    });
                }
            }

            // 4. Effective (병합된) 상태 계산
            const effectiveLocal = this.computeEffectiveState(state._local || {});
            layers.push({
                name: 'Effective _local (merged)',
                type: 'effective',
                values: effectiveLocal,
                priority: 3,
            });

        } catch (error) {
            console.warn('[G7DevTools] 상태 계층 빌드 실패:', error);
        }

        return layers;
    }

    /**
     * 실제 적용되는 상태 계산
     *
     * global _local과 dynamicState를 병합한 실제 상태를 계산합니다.
     */
    private computeEffectiveState(globalLocal: Record<string, any>): Record<string, any> {
        const effective = { ...globalLocal };

        // 모든 dynamicState를 병합 (우선순위 높음)
        for (const [, dynamicState] of this.dynamicStates) {
            if (dynamicState._local) {
                Object.assign(effective, dynamicState._local);
            }
        }

        return effective;
    }

    /**
     * 상태 충돌 감지
     *
     * 여러 레이어에서 동일한 상태 경로가 다른 값을 가지는 경우를 감지합니다.
     */
    private detectStateConflicts(layers: StateHierarchyLayer[]): StateConflict[] {
        const conflicts: StateConflict[] = [];

        // Global _local 레이어 찾기
        const globalLocalLayer = layers.find(l => l.type === 'global' && l.path === '_local');
        if (!globalLocalLayer) return conflicts;

        // 각 dynamicState와 비교
        const dynamicLayers = layers.filter(l => l.type === 'dynamicState');

        for (const dynamicLayer of dynamicLayers) {
            const dynamicLocal = dynamicLayer.values._local || dynamicLayer.values;

            for (const [key, dynamicValue] of Object.entries(dynamicLocal)) {
                const globalValue = globalLocalLayer.values[key];

                // 값이 다르면 충돌
                if (JSON.stringify(globalValue) !== JSON.stringify(dynamicValue)) {
                    // 이 상태를 사용하는/못하는 컴포넌트 찾기
                    const usedBy: string[] = [];
                    const notUsedBy: string[] = [];

                    for (const source of this.componentStateSources.values()) {
                        const usesLocal = source.stateSource.local.some(
                            p => p === `_local.${key}` || p === key
                        );

                        if (usesLocal) {
                            if (source.stateProvider.type === 'dynamicState') {
                                usedBy.push(source.componentName);
                            } else if (source.stateProvider.type === 'globalState') {
                                notUsedBy.push(source.componentName);
                            }
                        }
                    }

                    conflicts.push({
                        path: `_local.${key}`,
                        globalValue,
                        dynamicStateValue: dynamicValue,
                        effectiveValue: dynamicValue, // dynamicState가 우선
                        usedBy,
                        notUsedBy,
                        severity: notUsedBy.length > 0 ? 'warning' : 'info',
                        description: notUsedBy.length > 0
                            ? `${notUsedBy.join(', ')}은(는) globalState._local을 읽어 dynamicState 값을 사용하지 못함`
                            : 'dynamicState 값이 globalState와 다르지만 모든 컴포넌트가 dynamicState를 사용',
                    });
                }
            }
        }

        return conflicts;
    }

    /**
     * componentContext 흐름 정보 조회
     */
    getContextFlowInfo(): ContextFlowInfo {
        // 루트 노드 찾기 (부모가 없는 노드)
        const rootNodes: ContextFlowNode[] = [];
        const allIds = new Set(this.contextFlowNodes.keys());

        for (const node of this.contextFlowNodes.values()) {
            if (node.children) {
                for (const child of node.children) {
                    allIds.delete(child.componentId);
                }
            }
        }

        // 남은 ID가 루트 노드
        for (const id of allIds) {
            const node = this.contextFlowNodes.get(id);
            if (node) {
                rootNodes.push(node);
            }
        }

        return {
            rootComponent: rootNodes.length > 0 ? rootNodes[0].component : 'Unknown',
            contextFlow: rootNodes,
            timestamp: Date.now(),
        };
    }

    /**
     * 상태 계층 추적 데이터 초기화
     */
    clearStateHierarchyData(): void {
        this.componentStateSources.clear();
        this.contextFlowNodes.clear();
        this.dynamicStates.clear();
    }

    // ============================================
    // CSS 스타일 검증 메서드
    // ============================================

    /**
     * 컴포넌트 스타일 정보 추적
     */
    trackComponentStyle(
        componentId: string,
        componentName: string,
        classes: string[],
        computedStyles: ComponentStyleInfo['computedStyles']
    ): void {
        if (!this.config.enabled) return;

        // Tailwind 클래스 분석
        const tailwindAnalysis = this.analyzeTailwindClasses(classes);

        const styleInfo: ComponentStyleInfo = {
            componentId,
            componentName,
            classes,
            computedStyles,
            tailwindAnalysis,
        };

        this.componentStyles.set(componentId, styleInfo);

        // 스타일 이슈 감지
        this.detectStyleIssues(styleInfo);
    }

    /**
     * Tailwind 클래스 분석
     */
    private analyzeTailwindClasses(classes: string[]): ComponentStyleInfo['tailwindAnalysis'] {
        const darkClasses: string[] = [];
        const responsiveClasses: string[] = [];
        const dynamicClasses: string[] = [];
        const usedClasses: string[] = [];

        const responsivePrefixes = ['sm:', 'md:', 'lg:', 'xl:', '2xl:'];
        const dynamicPatterns = [/bg-\w+-\d+/, /text-\w+-\d+/, /border-\w+-\d+/];

        for (const cls of classes) {
            usedClasses.push(cls);

            if (cls.startsWith('dark:')) {
                darkClasses.push(cls);
            }

            for (const prefix of responsivePrefixes) {
                if (cls.startsWith(prefix)) {
                    responsiveClasses.push(cls);
                    break;
                }
            }

            // 동적 클래스 감지 (숫자로 끝나는 컬러 클래스)
            for (const pattern of dynamicPatterns) {
                if (pattern.test(cls)) {
                    dynamicClasses.push(cls);
                    break;
                }
            }
        }

        return {
            usedClasses,
            darkClasses,
            responsiveClasses,
            dynamicClasses,
        };
    }

    /**
     * 스타일 이슈 감지
     */
    private detectStyleIssues(styleInfo: ComponentStyleInfo): void {
        const { componentId, componentName, computedStyles, tailwindAnalysis } = styleInfo;

        // 1. 보이지 않는 요소 감지
        if (
            computedStyles.opacity === '0' ||
            computedStyles.visibility === 'hidden' ||
            computedStyles.display === 'none' ||
            (computedStyles.width === '0px' && computedStyles.height === '0px')
        ) {
            this.addStyleIssue({
                id: this.generateId(),
                type: 'invisible-element',
                componentId,
                componentName,
                property: computedStyles.opacity === '0' ? 'opacity' :
                         computedStyles.visibility === 'hidden' ? 'visibility' :
                         computedStyles.display === 'none' ? 'display' : 'width/height',
                currentValue: computedStyles.opacity === '0' ? '0' :
                             computedStyles.visibility === 'hidden' ? 'hidden' :
                             computedStyles.display === 'none' ? 'none' : '0px',
                severity: 'warning',
                description: '요소가 화면에 보이지 않습니다.',
                suggestion: 'opacity, visibility, display, width/height 값을 확인하세요.',
            });
        }

        // 2. 다크 모드 클래스 누락 감지 (배경/텍스트 컬러에 대해)
        const bgClasses = tailwindAnalysis.usedClasses.filter(c => c.startsWith('bg-') && !c.startsWith('dark:'));
        const darkBgClasses = tailwindAnalysis.darkClasses.filter(c => c.includes('bg-'));

        if (bgClasses.length > 0 && darkBgClasses.length === 0) {
            this.addStyleIssue({
                id: this.generateId(),
                type: 'dark-mode-missing',
                componentId,
                componentName,
                property: 'background-color',
                currentValue: bgClasses.join(', '),
                expectedValue: 'dark:bg-* 클래스 필요',
                severity: 'info',
                description: '다크 모드 배경색 클래스가 누락되었습니다.',
                suggestion: `${bgClasses[0]}에 대응하는 dark: 클래스를 추가하세요.`,
            });
        }

        // 3. 동적 클래스 경고 (Tailwind purging 가능성)
        if (tailwindAnalysis.dynamicClasses.length > 0) {
            this.addStyleIssue({
                id: this.generateId(),
                type: 'tailwind-purging',
                componentId,
                componentName,
                property: 'class',
                currentValue: tailwindAnalysis.dynamicClasses.join(', '),
                severity: 'info',
                description: '동적으로 생성된 Tailwind 클래스가 있습니다.',
                suggestion: 'tailwind.config.js의 safelist에 추가하거나 전체 클래스명을 상수로 정의하세요.',
            });
        }
    }

    /**
     * 스타일 이슈 추가
     */
    private addStyleIssue(issue: StyleIssue): void {
        // 중복 방지
        const exists = this.styleIssues.some(
            i => i.componentId === issue.componentId &&
                 i.type === issue.type &&
                 i.property === issue.property
        );

        if (!exists) {
            this.styleIssues.push(issue);
        }
    }

    /**
     * 스타일 검증 정보 반환
     */
    getStyleValidationInfo(): StyleValidationInfo {
        const componentStylesArray = Array.from(this.componentStyles.values());

        return {
            issues: [...this.styleIssues],
            componentStyles: componentStylesArray,
            stats: {
                totalComponents: componentStylesArray.length,
                invisibleCount: this.styleIssues.filter(i => i.type === 'invisible-element').length,
                tailwindIssueCount: this.styleIssues.filter(i => i.type === 'tailwind-purging').length,
                darkModeIssueCount: this.styleIssues.filter(i => i.type === 'dark-mode-missing').length,
            },
            timestamp: Date.now(),
        };
    }

    /**
     * 스타일 검증 데이터 초기화
     */
    clearStyleValidationData(): void {
        this.styleIssues = [];
        this.componentStyles.clear();
    }

    // ============================================
    // 인증 디버깅 메서드
    // ============================================

    /**
     * 인증 이벤트 로깅
     */
    trackAuthEvent(
        type: AuthEventType,
        success: boolean,
        error?: string,
        details?: Record<string, any>
    ): void {
        if (!this.config.enabled) return;

        const event: AuthEventLog = {
            id: `auth_${++this.authEventIdCounter}`,
            type,
            timestamp: Date.now(),
            success,
            error,
            details,
        };

        this.authEvents.push(event);

        // 히스토리 제한
        if (this.authEvents.length > this.maxAuthEventHistory) {
            this.authEvents = this.authEvents.slice(-this.maxAuthEventHistory);
        }
    }

    /**
     * API 인증 헤더 분석 추적
     */
    trackAuthHeader(
        url: string,
        hasAuthHeader: boolean,
        headerType?: string,
        tokenValid?: boolean,
        responseStatus?: number
    ): void {
        if (!this.config.enabled) return;

        const analysis: AuthHeaderAnalysis = {
            url,
            hasAuthHeader,
            headerType,
            tokenValid: tokenValid ?? false,
            responseStatus,
            timestamp: Date.now(),
        };

        this.authHeaderHistory.push(analysis);

        // 히스토리 제한
        if (this.authHeaderHistory.length > this.maxAuthEventHistory) {
            this.authHeaderHistory = this.authHeaderHistory.slice(-this.maxAuthEventHistory);
        }

        // 401 응답 시 자동으로 이벤트 로깅
        if (responseStatus === 401) {
            this.trackAuthEvent('api-unauthorized', false, `401 Unauthorized: ${url}`, { url });
        }
    }

    /**
     * 인증 상태 정보 가져오기
     */
    private getAuthState(): AuthStateInfo {
        try {
            const g7Core = (window as any).G7Core;
            const apiClient = g7Core?.api;

            // AuthManager 인스턴스 가져오기 (G7Core.auth가 없으면 AuthManager.getInstance() 사용)
            let auth = g7Core?.auth;
            if (!auth && g7Core?.AuthManager?.getInstance) {
                auth = g7Core.AuthManager.getInstance();
            }

            // 토큰 정보 확인 (ApiClient의 TOKEN_KEY = 'auth_token')
            const accessToken = apiClient?.getToken?.() ||
                auth?.getAccessToken?.() ||
                localStorage.getItem('auth_token') ||
                localStorage.getItem('access_token');

            const refreshToken = auth?.getRefreshToken?.() ||
                localStorage.getItem('refresh_token');

            // 사용자 정보 소스 추적
            let source: 'AuthManager' | 'G7Core.state' | 'unknown' = 'unknown';

            // 사용자 정보 - AuthManager의 getUser() 먼저 확인
            let user = auth?.getUser?.();
            if (user) {
                source = 'AuthManager';
            }

            // AuthManager에 user가 없으면 G7Core state에서 확인
            if (!user) {
                const state = g7Core?.state?.get?.();
                // user 또는 currentUser 필드 확인 (Admin: user, User: currentUser)
                user = state?._global?.user || state?._global?.currentUser || state?.user || state?.currentUser;
                if (user) {
                    source = 'G7Core.state';
                }
            }

            // 여전히 없으면 AuthManager의 isAuthenticated() 상태 확인
            const isAuthManagerAuthenticated = auth?.isAuthenticated?.() ?? false;

            // storage 위치 확인
            let storage: 'localStorage' | 'sessionStorage' | 'memory' = 'memory';
            if (localStorage.getItem('auth_token') || localStorage.getItem('access_token')) {
                storage = 'localStorage';
            } else if (sessionStorage.getItem('auth_token') || sessionStorage.getItem('access_token')) {
                storage = 'sessionStorage';
            }

            // 인증 여부: 토큰이 있고 (user가 있거나 AuthManager가 인증됨)
            const isAuthenticated = !!accessToken && (!!user || isAuthManagerAuthenticated);

            // 토큰 미리보기 (앞 10자 + ... + 뒤 6자)
            let accessTokenPreview: string | undefined;
            if (accessToken && typeof accessToken === 'string' && accessToken.length > 20) {
                accessTokenPreview = `${accessToken.substring(0, 10)}...${accessToken.substring(accessToken.length - 6)}`;
            } else if (accessToken) {
                accessTokenPreview = '***';
            }

            // 사용자 정보 상세 구성
            const userInfo = user ? {
                id: user.id,
                email: user.email,
                name: user.name,
                // 역할 정보 상세
                roles: user.roles?.map((r: any) => {
                    if (typeof r === 'string') {
                        return { name: r };
                    }
                    return {
                        id: r.id,
                        name: r.name,
                        guard_name: r.guard_name,
                    };
                }),
                // 권한 정보 상세
                permissions: user.permissions?.map((p: any) => {
                    if (typeof p === 'string') {
                        return { name: p };
                    }
                    return {
                        id: p.id,
                        name: p.name,
                        guard_name: p.guard_name,
                    };
                }),
                // 추가 사용자 정보
                avatar: user.avatar || user.profile_photo_url || user.profile_image,
                created_at: user.created_at,
                updated_at: user.updated_at,
                last_login_at: user.last_login_at,
                email_verified_at: user.email_verified_at,
                // 기타 필드 (동적)
                ...(user.phone && { phone: user.phone }),
                ...(user.nickname && { nickname: user.nickname }),
                ...(user.status && { status: user.status }),
                ...(user.locale && { locale: user.locale }),
            } : undefined;

            return {
                isAuthenticated,
                user: userInfo,
                tokens: {
                    hasAccessToken: !!accessToken,
                    hasRefreshToken: !!refreshToken,
                    storage,
                    accessTokenPreview,
                },
                lastActivity: Date.now(),
                source,
            };
        } catch {
            return {
                isAuthenticated: false,
                tokens: {
                    hasAccessToken: false,
                    hasRefreshToken: false,
                    storage: 'memory',
                },
                source: 'unknown',
            };
        }
    }

    /**
     * 인증 디버깅 정보 반환
     */
    getAuthDebugInfo(): AuthDebugInfo {
        const events = [...this.authEvents];

        return {
            state: this.getAuthState(),
            events,
            headerAnalysis: [...this.authHeaderHistory],
            stats: {
                loginAttempts: events.filter(e => e.type === 'login').length,
                successfulLogins: events.filter(e => e.type === 'login' && e.success).length,
                failedLogins: events.filter(e => e.type === 'login' && !e.success).length,
                tokenRefreshes: events.filter(e => e.type === 'token-refresh').length,
                unauthorizedResponses: events.filter(e => e.type === 'api-unauthorized').length,
            },
            timestamp: Date.now(),
        };
    }

    /**
     * 인증 디버깅 데이터 초기화
     */
    clearAuthData(): void {
        this.authEvents = [];
        this.authHeaderHistory = [];
        this.authEventIdCounter = 0;
    }

    // ============================================
    // 레이아웃 추적 메서드
    // ============================================

    /**
     * 레이아웃 로드 추적
     *
     * LayoutLoader에서 레이아웃 로드 완료 시 호출됩니다.
     * 현재 렌더링 중인 레이아웃의 전체 JSON을 저장합니다.
     *
     * @param layoutPath 레이아웃 경로 (예: "admin/admin_dashboard")
     * @param templateId 템플릿 ID (예: "sirsoft-admin_basic")
     * @param layoutJson 전체 레이아웃 JSON 데이터
     * @param source 로드 소스 ('cache' | 'api')
     */
    trackLayoutLoad(
        layoutPath: string,
        templateId: string,
        layoutJson: LayoutJsonData,
        source: 'cache' | 'api' = 'api'
    ): void {
        if (!this.config.enabled) return;

        const now = Date.now();

        // 현재 레이아웃 저장
        this.currentLayout = {
            layoutPath,
            templateId,
            layoutJson: this.sanitizeObject(layoutJson),
            loadedAt: now,
            version: layoutJson.version,
            layoutName: layoutJson.layout_name,
            source,
        };

        // 이력에 추가
        const historyEntry: LayoutHistoryEntry = {
            id: `layout-${++this.layoutIdCounter}`,
            layoutPath,
            templateId,
            loadedAt: now,
            source,
            version: layoutJson.version,
        };

        this.layoutHistory.push(historyEntry);

        // 이력 크기 제한
        if (this.layoutHistory.length > this.maxLayoutHistory) {
            this.layoutHistory.shift();
        }

        // 통계 업데이트
        this.layoutStats.totalLoads++;
        if (source === 'cache') {
            this.layoutStats.cacheHits++;
        } else {
            this.layoutStats.apiLoads++;
        }
    }

    /**
     * 현재 레이아웃 조회
     *
     * @returns 현재 렌더링 중인 레이아웃 정보 또는 null
     */
    getCurrentLayout(): CurrentLayoutInfo | null {
        return this.currentLayout;
    }

    /**
     * 레이아웃 이력 조회
     *
     * @returns 레이아웃 로드 이력
     */
    getLayoutHistory(): LayoutHistoryEntry[] {
        return [...this.layoutHistory];
    }

    /**
     * 레이아웃 디버깅 정보 반환 (상태 덤프용)
     *
     * @returns 레이아웃 디버깅 정보
     */
    getLayoutDebugInfo(): LayoutDebugInfo {
        return {
            current: this.currentLayout,
            history: [...this.layoutHistory],
            stats: { ...this.layoutStats },
        };
    }

    /**
     * 레이아웃 데이터 초기화
     */
    clearLayoutData(): void {
        this.currentLayout = null;
        this.layoutHistory = [];
        this.layoutIdCounter = 0;
        this.layoutStats = { totalLoads: 0, cacheHits: 0, apiLoads: 0 };
    }

    // ============================================
    // 변경 감지 메서드
    // ============================================

    /**
     * 핸들러 실행 시작 기록
     *
     * @param handlerName 핸들러 이름
     * @returns 실행 ID (종료 시 사용)
     */
    startHandlerExecution(handlerName: string): string {
        if (!this.config.enabled) return '';

        const executionId = `exec_${++this.executionIdCounter}_${Date.now()}`;
        const detail: HandlerExecutionDetail = {
            handlerName,
            executionId,
            startTime: Date.now(),
            stateChanges: [],
            dataSourceChanges: [],
            alerts: [],
        };

        this.executionDetails.set(executionId, detail);

        // 오래된 항목 정리
        if (this.executionDetails.size > this.maxExecutionDetails) {
            const keys = Array.from(this.executionDetails.keys());
            const toRemove = keys.slice(0, keys.length - this.maxExecutionDetails);
            toRemove.forEach(key => this.executionDetails.delete(key));
        }

        return executionId;
    }

    /**
     * 핸들러 실행 종료 기록
     *
     * @param executionId 실행 ID
     * @param exitReason 종료 사유
     * @param exitLocation 종료 위치 (선택)
     * @param exitDescription 종료 설명 (선택)
     */
    endHandlerExecution(
        executionId: string,
        exitReason: HandlerExitReason,
        exitLocation?: string,
        exitDescription?: string
    ): void {
        if (!this.config.enabled || !executionId) return;

        const detail = this.executionDetails.get(executionId);
        if (!detail) return;

        detail.endTime = Date.now();
        detail.duration = detail.endTime - detail.startTime;
        detail.exitReason = exitReason;
        detail.exitLocation = exitLocation;
        detail.exitDescription = exitDescription;

        // 기대 변경 확인 및 알림 생성
        const expectation = this.changeExpectations.get(executionId);
        if (expectation) {
            detail.expectedChanges = expectation;
            this.checkExpectations(executionId, detail, expectation);
            this.changeExpectations.delete(executionId);
        }

        // 상태 변경 없이 성공 종료된 경우 알림
        if (exitReason === 'normal' && detail.stateChanges.length === 0 && detail.dataSourceChanges.length === 0) {
            this.addChangeAlert(executionId, {
                type: 'no-state-change',
                severity: 'warning',
                message: `핸들러 "${detail.handlerName}"이(가) 상태 변경 없이 완료됨`,
                description: '핸들러가 성공적으로 완료되었지만 상태나 데이터소스 변경이 없습니다. 의도한 동작인지 확인하세요.',
                handlerName: detail.handlerName,
                suggestion: '핸들러 내부 조건문을 확인하거나 DevTools의 "변경감지" 탭에서 exitLocation을 확인하세요.',
                docLink: '.claude/docs/frontend/troubleshooting-state.md',
            });
        }

        // early return 감지 시 알림
        if (exitReason === 'early-return-condition' || exitReason === 'early-return-validation') {
            this.addChangeAlert(executionId, {
                type: 'early-return-detected',
                severity: 'info',
                message: `핸들러 "${detail.handlerName}"이(가) early return으로 종료됨`,
                description: exitDescription || `종료 위치: ${exitLocation || '알 수 없음'}`,
                handlerName: detail.handlerName,
                suggestion: exitReason === 'early-return-validation'
                    ? '검증 실패로 인한 early return입니다. 입력 데이터를 확인하세요.'
                    : '조건부 early return입니다. 조건문의 평가 결과를 확인하세요.',
            });
        }
    }

    /**
     * 종료 사유 기록 (핸들러 내부에서 호출)
     *
     * @param executionId 실행 ID
     * @param exitReason 종료 사유
     * @param location 종료 위치
     * @param description 상세 설명
     */
    recordExitReason(
        executionId: string,
        exitReason: HandlerExitReason,
        location: string,
        description?: string
    ): void {
        if (!this.config.enabled || !executionId) return;

        const detail = this.executionDetails.get(executionId);
        if (!detail) return;

        detail.exitReason = exitReason;
        detail.exitLocation = location;
        detail.exitDescription = description;
    }

    /**
     * 상태 변경 기록
     *
     * @param executionId 관련 핸들러 실행 ID (선택)
     * @param path 상태 경로
     * @param changeType 변경 유형
     * @param oldValue 이전 값
     * @param newValue 새 값
     */
    recordStateChange(
        executionId: string | undefined,
        path: string,
        changeType: StateChangeRecord['changeType'],
        oldValue: any,
        newValue: any
    ): void {
        if (!this.config.enabled) return;

        const record: StateChangeRecord = {
            id: `sc_${++this.stateChangeIdCounter}`,
            path,
            changeType,
            oldValue: this.safeClone(oldValue),
            newValue: this.safeClone(newValue),
            timestamp: Date.now(),
            comparison: this.compareValues(oldValue, newValue),
            executionId,
        };

        this.stateChangeHistory.push(record);

        // 핸들러 실행과 연결
        if (executionId) {
            const detail = this.executionDetails.get(executionId);
            if (detail) {
                detail.stateChanges.push(record);
            }
        }

        // 히스토리 크기 제한
        if (this.stateChangeHistory.length > this.maxStateChangeHistory) {
            this.stateChangeHistory.splice(0, this.stateChangeHistory.length - this.maxStateChangeHistory);
        }

        // 객체 참조가 동일한 경우 알림
        if (record.comparison.isDeepEqual && typeof oldValue === 'object' && oldValue !== null) {
            this.addChangeAlert(executionId || '', {
                type: 'object-reference-same',
                severity: 'warning',
                message: `상태 "${path}"가 변경되었으나 값이 동일함`,
                description: '객체 참조가 동일하거나 깊은 비교 결과 변경이 없습니다. 변경 감지가 실패할 수 있습니다.',
                handlerName: executionId ? this.executionDetails.get(executionId)?.handlerName || 'unknown' : 'unknown',
                statePath: path,
                suggestion: '불변성을 유지하여 새 객체를 생성하세요. 예: { ...oldObject, newField: value }',
                docLink: '.claude/docs/frontend/troubleshooting-state.md#object-mutation',
            });
        }
    }

    /**
     * 데이터소스 변경 기록
     *
     * @param executionId 관련 핸들러 실행 ID (선택)
     * @param dataSourceId 데이터소스 ID
     * @param changeType 변경 유형
     * @param previousStatus 이전 상태
     * @param newStatus 새 상태
     */
    recordDataSourceChange(
        executionId: string | undefined,
        dataSourceId: string,
        changeType: DataSourceChangeRecord['changeType'],
        previousStatus: DataSourceChangeRecord['previousStatus'],
        newStatus: DataSourceChangeRecord['newStatus']
    ): void {
        if (!this.config.enabled) return;

        const record: DataSourceChangeRecord = {
            dataSourceId,
            changeType,
            timestamp: Date.now(),
            previousStatus,
            newStatus,
            executionId,
        };

        this.dataSourceChangeHistory.push(record);

        // 핸들러 실행과 연결
        if (executionId) {
            const detail = this.executionDetails.get(executionId);
            if (detail) {
                detail.dataSourceChanges.push(record);
            }
        }

        // 히스토리 크기 제한
        if (this.dataSourceChangeHistory.length > this.maxStateChangeHistory) {
            this.dataSourceChangeHistory.splice(0, this.dataSourceChangeHistory.length - this.maxStateChangeHistory);
        }
    }

    /**
     * 변경 기대치 설정 (핸들러가 변경을 예고)
     *
     * @param executionId 실행 ID
     * @param expectedStatePaths 기대되는 상태 변경 경로 목록
     * @param expectedDataSources 기대되는 데이터소스 갱신 목록
     */
    expectChange(
        executionId: string,
        expectedStatePaths: string[],
        expectedDataSources: string[]
    ): void {
        if (!this.config.enabled || !executionId) return;

        const detail = this.executionDetails.get(executionId);
        if (!detail) return;

        const expectation: ChangeExpectation = {
            expectedStatePaths,
            expectedDataSources,
            setAt: Date.now(),
            source: detail.handlerName,
        };

        this.changeExpectations.set(executionId, expectation);
    }

    /**
     * 기대 변경 확인 및 알림 생성
     */
    private checkExpectations(
        executionId: string,
        detail: HandlerExecutionDetail,
        expectation: ChangeExpectation
    ): void {
        const actualStatePaths = new Set(detail.stateChanges.map(sc => sc.path));
        const actualDataSources = new Set(detail.dataSourceChanges.map(dc => dc.dataSourceId));

        // 기대한 상태 변경이 발생하지 않은 경우
        for (const expectedPath of expectation.expectedStatePaths) {
            if (!actualStatePaths.has(expectedPath)) {
                this.addChangeAlert(executionId, {
                    type: 'expected-not-fulfilled',
                    severity: 'error',
                    message: `기대한 상태 변경 "${expectedPath}"가 발생하지 않음`,
                    description: `핸들러 "${detail.handlerName}"이(가) "${expectedPath}" 변경을 예고했지만 실제로 변경되지 않았습니다.`,
                    handlerName: detail.handlerName,
                    statePath: expectedPath,
                    suggestion: '핸들러 내부 로직을 확인하세요. early return이나 조건부 분기로 인해 변경이 생략되었을 수 있습니다.',
                    docLink: '.claude/docs/frontend/troubleshooting-state.md',
                });
            }
        }

        // 기대한 데이터소스 갱신이 발생하지 않은 경우
        for (const expectedDs of expectation.expectedDataSources) {
            if (!actualDataSources.has(expectedDs)) {
                this.addChangeAlert(executionId, {
                    type: 'expected-not-fulfilled',
                    severity: 'error',
                    message: `기대한 데이터소스 갱신 "${expectedDs}"가 발생하지 않음`,
                    description: `핸들러 "${detail.handlerName}"이(가) "${expectedDs}" 갱신을 예고했지만 실제로 갱신되지 않았습니다.`,
                    handlerName: detail.handlerName,
                    dataSourceId: expectedDs,
                    suggestion: 'refetchDataSource 호출이 실행되었는지 확인하세요.',
                    docLink: '.claude/docs/frontend/data-sources.md',
                });
            }
        }
    }

    /**
     * 변경 알림 추가
     */
    private addChangeAlert(
        executionId: string,
        alertData: Omit<ChangeAlert, 'id' | 'timestamp'>
    ): void {
        const alert: ChangeAlert = {
            ...alertData,
            id: `alert_${++this.alertIdCounter}`,
            timestamp: Date.now(),
        };

        this.changeAlerts.push(alert);

        // 핸들러 실행과 연결
        if (executionId) {
            const detail = this.executionDetails.get(executionId);
            if (detail) {
                detail.alerts.push(alert);
            }
        }

        // 알림 크기 제한
        if (this.changeAlerts.length > this.maxChangeAlerts) {
            this.changeAlerts.splice(0, this.changeAlerts.length - this.maxChangeAlerts);
        }
    }

    /**
     * 값 비교
     */
    private compareValues(oldValue: any, newValue: any): StateChangeRecord['comparison'] {
        const oldType = this.getValueType(oldValue);
        const newType = this.getValueType(newValue);
        const typeChanged = oldType !== newType;

        let isDeepEqual = false;
        let changedKeys: string[] | undefined;

        if (!typeChanged && (oldType === 'object' || oldType === 'array')) {
            isDeepEqual = this.deepEqual(oldValue, newValue);
            if (!isDeepEqual && oldType === 'object') {
                changedKeys = this.getChangedKeys(oldValue, newValue);
            }
        } else {
            isDeepEqual = oldValue === newValue;
        }

        return {
            typeChanged,
            oldType,
            newType,
            isDeepEqual,
            changedKeys,
        };
    }

    /**
     * 값의 타입 반환
     */
    private getValueType(value: any): string {
        if (value === null) return 'null';
        if (value === undefined) return 'undefined';
        if (Array.isArray(value)) return 'array';
        return typeof value;
    }

    /**
     * 깊은 비교
     */
    private deepEqual(a: any, b: any): boolean {
        if (a === b) return true;
        if (typeof a !== typeof b) return false;
        if (a === null || b === null) return a === b;
        if (typeof a !== 'object') return a === b;

        if (Array.isArray(a) !== Array.isArray(b)) return false;

        const keysA = Object.keys(a);
        const keysB = Object.keys(b);

        if (keysA.length !== keysB.length) return false;

        for (const key of keysA) {
            if (!keysB.includes(key)) return false;
            if (!this.deepEqual(a[key], b[key])) return false;
        }

        return true;
    }

    /**
     * 변경된 키 목록 반환
     */
    private getChangedKeys(oldObj: Record<string, any>, newObj: Record<string, any>): string[] {
        const changedKeys: string[] = [];
        const allKeys = new Set([...Object.keys(oldObj), ...Object.keys(newObj)]);

        for (const key of allKeys) {
            if (!this.deepEqual(oldObj[key], newObj[key])) {
                changedKeys.push(key);
            }
        }

        return changedKeys;
    }

    /**
     * 변경 감지 정보 반환 (상태 덤프용)
     */
    getChangeDetectionInfo(): ChangeDetectionInfo {
        const details = Array.from(this.executionDetails.values());

        // 통계 계산
        const stats: ChangeDetectionStats = {
            totalExecutions: details.length,
            executionsWithStateChange: details.filter(d => d.stateChanges.length > 0).length,
            executionsWithoutStateChange: details.filter(d => d.stateChanges.length === 0 && d.exitReason === 'normal').length,
            earlyReturnCount: details.filter(d =>
                d.exitReason === 'early-return-condition' || d.exitReason === 'early-return-validation'
            ).length,
            alertCount: this.changeAlerts.length,
            alertsByType: this.getAlertsByType(),
        };

        return {
            executionDetails: details,
            stateChangeHistory: [...this.stateChangeHistory],
            dataSourceChangeHistory: [...this.dataSourceChangeHistory],
            alerts: [...this.changeAlerts],
            stats,
            timestamp: Date.now(),
        };
    }

    /**
     * 알림 유형별 개수 반환
     */
    private getAlertsByType(): Record<ChangeAlertType, number> {
        const result: Record<ChangeAlertType, number> = {
            'no-state-change': 0,
            'no-datasource-change': 0,
            'expected-not-fulfilled': 0,
            'object-reference-same': 0,
            'early-return-detected': 0,
            'async-timing-issue': 0,
        };

        for (const alert of this.changeAlerts) {
            result[alert.type]++;
        }

        return result;
    }

    /**
     * 변경 감지 데이터 초기화
     */
    clearChangeDetectionData(): void {
        this.executionDetails.clear();
        this.stateChangeHistory = [];
        this.dataSourceChangeHistory = [];
        this.changeAlerts = [];
        this.changeExpectations.clear();
        this.executionIdCounter = 0;
        this.stateChangeIdCounter = 0;
        this.alertIdCounter = 0;
    }

    /**
     * 최근 알림 반환
     *
     * @param limit 반환할 최대 개수
     * @param severity 필터링할 심각도 (선택)
     */
    getRecentAlerts(limit = 10, severity?: ChangeAlert['severity']): ChangeAlert[] {
        let alerts = [...this.changeAlerts];

        if (severity) {
            alerts = alerts.filter(a => a.severity === severity);
        }

        return alerts.slice(-limit);
    }

    /**
     * 특정 핸들러의 실행 상세 반환
     *
     * @param handlerName 핸들러 이름
     * @param limit 반환할 최대 개수
     */
    getHandlerExecutions(handlerName: string, limit = 10): HandlerExecutionDetail[] {
        const details = Array.from(this.executionDetails.values())
            .filter(d => d.handlerName === handlerName)
            .slice(-limit);

        return details;
    }

    // ============================================
    // Sequence 실행 추적 메서드
    // ============================================

    /**
     * 스택에서 sequenceId로 실행 정보 찾기 (헬퍼 메서드)
     */
    private findExecutionInStack(sequenceId: string): SequenceExecutionInfo | undefined {
        return this.sequenceExecutionStack.find(e => e.sequenceId === sequenceId);
    }

    /**
     * Sequence 실행 시작
     *
     * @param trigger 트리거 정보
     * @returns 생성된 sequenceId
     */
    startSequenceExecution(trigger?: SequenceExecutionInfo['trigger']): string {
        if (!this.config.enabled) return '';

        const sequenceId = `seq_${++this.sequenceIdCounter}_${Date.now()}`;

        const execution: SequenceExecutionInfo = {
            sequenceId,
            startTime: Date.now(),
            totalDuration: 0,
            trigger,
            actions: [],
            status: 'running',
        };

        // 스택에 push (중첩 sequence 지원)
        this.sequenceExecutionStack.push(execution);

        return sequenceId;
    }

    /**
     * Sequence 내 액션 실행 전 상태 캡처
     *
     * @param sequenceId Sequence ID
     * @param actionIndex 액션 인덱스
     * @param handler 핸들러 이름
     * @param params 액션 파라미터
     * @param currentState 현재 상태
     */
    captureSequenceActionBefore(
        sequenceId: string,
        actionIndex: number,
        handler: string,
        params: Record<string, any>,
        currentState: {
            _global: Record<string, any>;
            _local: Record<string, any>;
            _isolated?: Record<string, any>;
        }
    ): void {
        if (!this.config.enabled) return;

        // 스택에서 해당 sequenceId 찾기
        const execution = this.findExecutionInStack(sequenceId);
        if (!execution) return;

        const actionInfo: SequenceActionInfo = {
            index: actionIndex,
            handler,
            params: this.safeClone(params),
            stateBeforeAction: {
                _global: this.safeClone(currentState._global),
                _local: this.safeClone(currentState._local),
                _isolated: currentState._isolated ? this.safeClone(currentState._isolated) : undefined,
            },
            stateAfterAction: {
                _global: {},
                _local: {},
            },
            duration: 0,
            status: 'success',
        };

        execution.actions.push(actionInfo);
    }

    /**
     * Sequence 내 액션 실행 후 상태 캡처
     *
     * @param sequenceId Sequence ID
     * @param actionIndex 액션 인덱스
     * @param currentState 현재 상태
     * @param duration 소요 시간
     * @param result 액션 결과
     * @param error 에러 (있는 경우)
     */
    captureSequenceActionAfter(
        sequenceId: string,
        actionIndex: number,
        currentState: {
            _global: Record<string, any>;
            _local: Record<string, any>;
            _isolated?: Record<string, any>;
        },
        duration: number,
        result?: any,
        error?: Error
    ): void {
        if (!this.config.enabled) return;

        // 스택에서 해당 sequenceId 찾기
        const execution = this.findExecutionInStack(sequenceId);
        if (!execution) return;

        const actionInfo = execution.actions.find((a: SequenceActionInfo) => a.index === actionIndex);
        if (!actionInfo) return;

        actionInfo.stateAfterAction = {
            _global: this.safeClone(currentState._global),
            _local: this.safeClone(currentState._local),
            _isolated: currentState._isolated ? this.safeClone(currentState._isolated) : undefined,
        };
        actionInfo.duration = duration;
        actionInfo.result = this.safeClone(result);

        // 상태 diff 계산
        actionInfo.stateDiff = {
            global: this.computeStateDiff(actionInfo.stateBeforeAction._global, actionInfo.stateAfterAction._global),
            local: this.computeStateDiff(actionInfo.stateBeforeAction._local, actionInfo.stateAfterAction._local),
        };

        if (actionInfo.stateBeforeAction._isolated && actionInfo.stateAfterAction._isolated) {
            actionInfo.stateDiff.isolated = this.computeStateDiff(
                actionInfo.stateBeforeAction._isolated,
                actionInfo.stateAfterAction._isolated
            );
        }

        // pending state 캡처
        const pendingLocal = (window as any).__g7PendingLocalState;
        if (pendingLocal) {
            actionInfo.pendingState = this.safeClone(pendingLocal);
        }

        if (error) {
            actionInfo.status = 'error';
            actionInfo.error = {
                name: error.name,
                message: error.message,
                stack: error.stack,
            };
        }
    }

    /**
     * Sequence 실행 완료
     *
     * @param sequenceId Sequence ID
     * @param error 전체 에러 (있는 경우)
     */
    endSequenceExecution(sequenceId: string, error?: Error): void {
        if (!this.config.enabled) return;

        // 스택에서 해당 sequenceId 찾기 (인덱스 필요)
        const stackIndex = this.sequenceExecutionStack.findIndex(e => e.sequenceId === sequenceId);
        if (stackIndex === -1) return;

        const execution = this.sequenceExecutionStack[stackIndex];
        execution.endTime = Date.now();
        execution.totalDuration = execution.endTime - execution.startTime;

        if (error) {
            execution.status = 'error';
            execution.error = {
                name: error.name,
                message: error.message,
                stack: error.stack,
            };
            // 실패한 액션 인덱스 찾기
            const failedAction = execution.actions.find((a: SequenceActionInfo) => a.status === 'error');
            if (failedAction) {
                execution.failedAtIndex = failedAction.index;
            }
        } else {
            execution.status = 'success';
        }

        // 이력에 추가
        this.sequenceExecutions.push(execution);

        // 최대 크기 제한
        if (this.sequenceExecutions.length > this.maxSequenceExecutions) {
            this.sequenceExecutions.shift();
        }

        // 스택에서 제거
        this.sequenceExecutionStack.splice(stackIndex, 1);
    }

    /**
     * 상태 diff 계산
     */
    private computeStateDiff(before: Record<string, any>, after: Record<string, any>): StateDiff {
        const added: string[] = [];
        const removed: string[] = [];
        const changed: Array<{ path: string; oldValue: any; newValue: any }> = [];

        const beforeKeys = new Set(Object.keys(before));
        const afterKeys = new Set(Object.keys(after));

        // 추가된 키
        for (const key of afterKeys) {
            if (!beforeKeys.has(key)) {
                added.push(key);
            }
        }

        // 제거된 키
        for (const key of beforeKeys) {
            if (!afterKeys.has(key)) {
                removed.push(key);
            }
        }

        // 변경된 키
        for (const key of beforeKeys) {
            if (afterKeys.has(key)) {
                const oldVal = before[key];
                const newVal = after[key];
                if (JSON.stringify(oldVal) !== JSON.stringify(newVal)) {
                    changed.push({
                        path: key,
                        oldValue: oldVal,
                        newValue: newVal,
                    });
                }
            }
        }

        return { added, removed, changed };
    }

    /**
     * Sequence 추적 정보 반환
     */
    getSequenceTrackingInfo(): SequenceTrackingInfo {
        const stats = this.getSequenceStats();

        // 현재 실행 중인 sequence들 (스택의 최상위)
        const currentExecution = this.sequenceExecutionStack.length > 0
            ? this.sequenceExecutionStack[this.sequenceExecutionStack.length - 1]
            : undefined;

        return {
            executions: [...this.sequenceExecutions],
            currentExecution,
            stats,
        };
    }

    /**
     * Sequence 통계 계산
     */
    private getSequenceStats(): SequenceStats {
        const executions = this.sequenceExecutions;
        const totalExecutions = executions.length;
        const successCount = executions.filter(e => e.status === 'success').length;
        const errorCount = executions.filter(e => e.status === 'error').length;
        const totalActions = executions.reduce((sum, e) => sum + e.actions.length, 0);
        const avgDuration = totalExecutions > 0
            ? executions.reduce((sum, e) => sum + e.totalDuration, 0) / totalExecutions
            : 0;

        // 핸들러 사용 빈도 계산
        const handlerCounts: Record<string, number> = {};
        for (const execution of executions) {
            for (const action of execution.actions) {
                handlerCounts[action.handler] = (handlerCounts[action.handler] || 0) + 1;
            }
        }

        const topHandlers = Object.entries(handlerCounts)
            .sort(([, a], [, b]) => b - a)
            .slice(0, 5)
            .map(([handler, count]) => ({ handler, count }));

        return {
            totalExecutions,
            successCount,
            errorCount,
            totalActions,
            avgDuration,
            topHandlers,
        };
    }

    /**
     * Sequence 데이터 초기화
     */
    clearSequenceData(): void {
        this.sequenceExecutions = [];
        this.sequenceExecutionStack = [];
        this.sequenceIdCounter = 0;
    }

    /**
     * 특정 Sequence 실행 정보 반환
     *
     * @param sequenceId Sequence ID
     */
    getSequenceExecution(sequenceId: string): SequenceExecutionInfo | undefined {
        // 스택에서 먼저 찾기 (실행 중인 sequence)
        const inStack = this.findExecutionInStack(sequenceId);
        if (inStack) {
            return inStack;
        }
        // 완료된 이력에서 찾기
        return this.sequenceExecutions.find(e => e.sequenceId === sequenceId);
    }

    /**
     * 최근 Sequence 실행 목록 반환
     *
     * @param limit 반환할 최대 개수
     */
    getRecentSequences(limit = 10): SequenceExecutionInfo[] {
        return this.sequenceExecutions.slice(-limit);
    }

    // ============================================
    // Stale Closure 감지 메서드
    // ============================================

    /**
     * 상태 캡처 등록 (비동기 핸들러 시작 시 호출)
     *
     * @param handlerId 핸들러 ID
     * @param statePaths 캡처할 상태 경로들
     * @param stateValues 현재 상태 값들
     */
    registerStateCaptureForHandler(
        handlerId: string,
        statePaths: string[],
        stateValues: Record<string, any>
    ): void {
        if (!this.config.enabled) return;

        const captureMap = new Map<string, { value: any; capturedAt: number }>();
        const now = Date.now();

        for (const path of statePaths) {
            captureMap.set(path, {
                value: this.safeClone(stateValues[path]),
                capturedAt: now,
            });
        }

        this.stateCaptureRegistry.set(handlerId, captureMap);
    }

    /**
     * Stale Closure 감지 (비동기 작업 완료 후 호출)
     *
     * @param handlerId 핸들러 ID
     * @param location 발생 위치
     * @param currentState 현재 상태
     * @param warningType 경고 유형
     * @param actionId 관련 액션 ID
     */
    detectStaleClosure(
        handlerId: string,
        location: string,
        currentState: Record<string, any>,
        warningType: StaleClosureWarningType,
        actionId?: string
    ): StaleClosureWarning[] {
        if (!this.config.enabled) return [];

        const captureMap = this.stateCaptureRegistry.get(handlerId);
        if (!captureMap) return [];

        const now = Date.now();
        const warnings: StaleClosureWarning[] = [];

        for (const [path, captured] of captureMap) {
            const currentValue = this.getValueByPath(currentState, path);
            const timeDiff = now - captured.capturedAt;

            // 값이 다르고, 일정 시간이 지났으면 경고 생성
            if (!this.isDeepEqual(captured.value, currentValue) && timeDiff > 0) {
                const warning = this.createStaleClosureWarning(
                    warningType,
                    location,
                    path,
                    captured.value,
                    captured.capturedAt,
                    currentValue,
                    now,
                    timeDiff,
                    actionId
                );
                warnings.push(warning);
                this.addStaleClosureWarning(warning);
            }
        }

        // 사용 후 캡처 레지스트리 정리
        this.stateCaptureRegistry.delete(handlerId);

        return warnings;
    }

    /**
     * Stale Closure 경고 직접 추가
     *
     * @param info 경고 정보
     */
    trackStaleClosureWarning(info: {
        type: StaleClosureWarningType;
        location: string;
        capturedPath: string;
        capturedValue: any;
        capturedAt: number;
        currentValue: any;
        actionId?: string;
        stackTrace?: string;
    }): void {
        if (!this.config.enabled) return;

        const now = Date.now();
        const warning = this.createStaleClosureWarning(
            info.type,
            info.location,
            info.capturedPath,
            info.capturedValue,
            info.capturedAt,
            info.currentValue,
            now,
            now - info.capturedAt,
            info.actionId,
            info.stackTrace
        );

        this.addStaleClosureWarning(warning);
    }

    /**
     * Stale Closure 경고 생성
     */
    private createStaleClosureWarning(
        type: StaleClosureWarningType,
        location: string,
        path: string,
        capturedValue: any,
        capturedAt: number,
        currentValue: any,
        retrievedAt: number,
        timeDiff: number,
        actionId?: string,
        stackTrace?: string
    ): StaleClosureWarning {
        const severity = this.getStaleClosureSeverity(type, timeDiff);
        const { description, suggestion, docLink } = this.getStaleClosureMessages(type, path, timeDiff);

        return {
            id: `stale_${++this.staleClosureIdCounter}_${Date.now()}`,
            timestamp: Date.now(),
            type,
            location,
            capturedState: {
                path,
                capturedValue: this.safeClone(capturedValue),
                capturedAt,
            },
            currentState: {
                path,
                currentValue: this.safeClone(currentValue),
                retrievedAt,
            },
            timeDiff,
            severity,
            description,
            suggestion,
            docLink,
            actionId,
            stackTrace,
        };
    }

    /**
     * 경고 심각도 결정
     */
    private getStaleClosureSeverity(
        type: StaleClosureWarningType,
        timeDiff: number
    ): 'info' | 'warning' | 'error' {
        // 시간 차이가 크면 더 심각
        if (timeDiff > 5000) return 'error';
        if (timeDiff > 1000) return 'warning';

        // 유형별 기본 심각도
        switch (type) {
            case 'async-state-capture':
            case 'sequence-state-mismatch':
                return 'warning';
            case 'callback-state-capture':
            case 'timeout-state-capture':
                return 'info';
            case 'event-handler-stale':
                return 'warning';
            default:
                return 'info';
        }
    }

    /**
     * 경고 메시지 생성
     */
    private getStaleClosureMessages(
        type: StaleClosureWarningType,
        path: string,
        timeDiff: number
    ): { description: string; suggestion: string; docLink?: string } {
        const timeStr = timeDiff < 1000 ? `${timeDiff}ms` : `${(timeDiff / 1000).toFixed(1)}s`;

        switch (type) {
            case 'async-state-capture':
                return {
                    description: `await 후 ${timeStr} 경과 시점에 '${path}' 상태가 변경됨. 캡처된 상태 대신 최신 상태를 사용해야 함`,
                    suggestion: `await 이후에는 G7Core.state.get()으로 최신 상태를 다시 조회하거나, useRef + getter 패턴을 사용하세요`,
                    docLink: 'troubleshooting-state-closure.md',
                };
            case 'callback-state-capture':
                return {
                    description: `콜백에서 ${timeStr} 전에 캡처된 '${path}' 상태를 사용 중. 최신 상태와 다름`,
                    suggestion: `콜백에서 상태를 참조할 때는 stateRef.current 패턴 또는 G7Core.state.get()를 사용하세요`,
                    docLink: 'troubleshooting-state-closure.md',
                };
            case 'timeout-state-capture':
                return {
                    description: `setTimeout/setInterval 콜백에서 '${path}' 상태가 ${timeStr} 전 값 사용 중`,
                    suggestion: `타이머 콜백 내에서는 G7Core.state.get()으로 최신 상태를 조회하세요`,
                    docLink: 'troubleshooting-state-closure.md',
                };
            case 'sequence-state-mismatch':
                return {
                    description: `sequence 내 '${path}' 상태가 이전 액션 결과와 불일치. context.state 대신 캡처된 값 사용 의심`,
                    suggestion: `sequence 내에서는 context.state 또는 $prev를 사용하여 최신 상태를 참조하세요`,
                    docLink: 'troubleshooting-state-setstate.md',
                };
            case 'event-handler-stale':
                return {
                    description: `이벤트 핸들러에서 ${timeStr} 전에 바인딩된 '${path}' 상태 사용 중`,
                    suggestion: `이벤트 핸들러에서 상태를 참조할 때는 useRef 패턴 또는 G7Core.state.get()를 사용하세요`,
                    docLink: 'troubleshooting-state-closure.md',
                };
            default:
                return {
                    description: `'${path}' 상태에서 stale closure 감지됨 (${timeStr} 경과)`,
                    suggestion: `상태 참조 시 최신 값을 사용하는지 확인하세요`,
                };
        }
    }

    /**
     * 경고 추가
     */
    private addStaleClosureWarning(warning: StaleClosureWarning): void {
        this.staleClosureWarnings.push(warning);

        // 최대 크기 제한
        if (this.staleClosureWarnings.length > this.maxStaleClosureWarnings) {
            this.staleClosureWarnings.shift();
        }
    }

    /**
     * 경로로 값 가져오기
     */
    private getValueByPath(obj: Record<string, any>, path: string): any {
        const parts = path.split('.');
        let current = obj;

        for (const part of parts) {
            if (current === null || current === undefined) return undefined;
            current = current[part];
        }

        return current;
    }

    /**
     * 깊은 비교
     */
    private isDeepEqual(a: any, b: any): boolean {
        if (a === b) return true;
        if (a === null || b === null) return a === b;
        if (typeof a !== typeof b) return false;
        if (typeof a !== 'object') return a === b;

        try {
            return JSON.stringify(a) === JSON.stringify(b);
        } catch {
            return false;
        }
    }

    /**
     * Stale Closure 추적 정보 반환
     */
    getStaleClosureTrackingInfo(): StaleClosureTrackingInfo {
        const stats = this.getStaleClosureStats();

        return {
            warnings: [...this.staleClosureWarnings],
            stats,
            timestamp: Date.now(),
        };
    }

    /**
     * Stale Closure 통계 계산
     */
    private getStaleClosureStats(): StaleClosureStats {
        const warnings = this.staleClosureWarnings;

        // 유형별 개수
        const warningsByType: Record<StaleClosureWarningType, number> = {
            'async-state-capture': 0,
            'callback-state-capture': 0,
            'timeout-state-capture': 0,
            'sequence-state-mismatch': 0,
            'event-handler-stale': 0,
        };
        for (const w of warnings) {
            warningsByType[w.type]++;
        }

        // 심각도별 개수
        const warningsBySeverity: Record<string, number> = { info: 0, warning: 0, error: 0 };
        for (const w of warnings) {
            warningsBySeverity[w.severity]++;
        }

        // 위치별 개수
        const locationCounts: Record<string, number> = {};
        for (const w of warnings) {
            locationCounts[w.location] = (locationCounts[w.location] || 0) + 1;
        }
        const topLocations = Object.entries(locationCounts)
            .sort(([, a], [, b]) => b - a)
            .slice(0, 5)
            .map(([location, count]) => ({ location, count }));

        // 상태 경로별 개수
        const pathCounts: Record<string, number> = {};
        for (const w of warnings) {
            pathCounts[w.capturedState.path] = (pathCounts[w.capturedState.path] || 0) + 1;
        }
        const topAffectedPaths = Object.entries(pathCounts)
            .sort(([, a], [, b]) => b - a)
            .slice(0, 5)
            .map(([path, count]) => ({ path, count }));

        return {
            totalWarnings: warnings.length,
            warningsByType,
            warningsBySeverity,
            topLocations,
            topAffectedPaths,
        };
    }

    /**
     * Stale Closure 데이터 초기화
     */
    clearStaleClosureData(): void {
        this.staleClosureWarnings = [];
        this.staleClosureIdCounter = 0;
        this.stateCaptureRegistry.clear();
    }

    /**
     * 최근 Stale Closure 경고 반환
     *
     * @param limit 반환할 최대 개수
     * @param severity 심각도 필터
     */
    getRecentStaleClosureWarnings(
        limit = 10,
        severity?: 'info' | 'warning' | 'error'
    ): StaleClosureWarning[] {
        let warnings = [...this.staleClosureWarnings];

        if (severity) {
            warnings = warnings.filter(w => w.severity === severity);
        }

        return warnings.slice(-limit);
    }

    /**
     * 안전한 객체 복사 (순환 참조 처리)
     */
    private safeClone(obj: any): any {
        if (obj === null || obj === undefined) return obj;
        if (typeof obj !== 'object') return obj;

        try {
            return JSON.parse(JSON.stringify(obj));
        } catch {
            // 순환 참조 등으로 직렬화 실패 시 간단한 복사
            if (Array.isArray(obj)) {
                return obj.map(item => {
                    try {
                        return JSON.parse(JSON.stringify(item));
                    } catch {
                        return '[Unserializable]';
                    }
                });
            }
            const result: Record<string, any> = {};
            for (const key of Object.keys(obj)) {
                try {
                    result[key] = JSON.parse(JSON.stringify(obj[key]));
                } catch {
                    result[key] = '[Unserializable]';
                }
            }
            return result;
        }
    }
}

export default G7DevToolsCore;
