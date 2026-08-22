/**
 * G7 DevTools 타입 정의
 *
 * 그누보드7 템플릿 엔진 디버깅 시스템을 위한 TypeScript 타입 정의
 */

// ============================================
// 상태 관리 타입
// ============================================

/** 상태 스냅샷 */
export interface StateSnapshot {
    id: number;
    timestamp: number;
    source: string;
    prev: Record<string, any>;
    next: Record<string, any>;
    diff?: StateDiff;
}

/** 상태 뷰 */
export interface StateView {
    _global: Record<string, any>;
    _local: Record<string, any>;
    _computed?: Record<string, any>;
    /** 격리된 상태 (scopeId → state) */
    _isolated?: Record<string, Record<string, any>>;
    /** 부모 데이터 컨텍스트 (모달/자식 레이아웃에서 부모 상태 접근용) */
    $parent?: Record<string, any>;
}

/** 상태 차이 */
export interface StateDiff {
    added: string[];
    removed: string[];
    changed: Array<{
        path: string;
        oldValue: any;
        newValue: any;
    }>;
}

// ============================================
// 액션 추적 타입
// ============================================

/** 액션 로그 */
export interface ActionLog {
    id: string;
    type: string;
    params?: Record<string, any>;
    /** 바인딩이 해석된 params (템플릿 표현식 → 실제 값) */
    resolvedParams?: Record<string, any>;
    context?: Record<string, any>;
    startTime: number;
    endTime?: number;
    duration?: number;
    status: 'started' | 'success' | 'error';
    result?: any;
    error?: SerializedError;
    children?: ActionLog[];
}

/** 직렬화된 에러 */
export interface SerializedError {
    name: string;
    message: string;
    stack?: string;
}

/** 액션 메트릭 */
export interface ActionMetrics {
    totalActions: number;
    successCount: number;
    errorCount: number;
    averageDuration: number;
    actionsByType: Record<string, number>;
}

// ============================================
// 캐시 타입
// ============================================

/** 캐시 통계 */
export interface CacheStats {
    hits: number;
    misses: number;
    entries: number;
    hitRate?: number;
}

/** 캐시 결정 유형 */
export type CacheDecisionType = 'use_cache' | 'skip_cache' | 'invalidate' | 'cache_miss' | 'cache_hit';

/** 캐시 결정 정보 */
export interface CacheDecisionInfo {
    id: string;
    timestamp: number;
    expression: string;
    decision: CacheDecisionType;
    reason: string;
    context: {
        isInIteration: boolean;
        isInAction: boolean;
        renderCycleId?: string;
        componentId?: string;
        skipCacheOption?: boolean;
    };
    cachedValue?: any;
    freshValue?: any;
    valueMatch?: boolean;
    duration?: number;
}

/** 캐시 결정 추적 통계 */
export interface CacheDecisionStats {
    totalDecisions: number;
    cacheHits: number;
    cacheMisses: number;
    skipCacheCount: number;
    invalidateCount: number;
    byReason: Record<string, number>;
    byComponent: Record<string, number>;
    avgHitRate: number;
}

/** 캐시 결정 추적 정보 (상태 덤프용) */
export interface CacheDecisionTrackingInfo {
    decisions: CacheDecisionInfo[];
    stats: CacheDecisionStats;
    timestamp: number;
}

/** 표현식 평가 결과 */
export interface EvaluationResult {
    result: any;
    steps: EvaluationStep[];
    duration: number;
    fromCache: boolean;
}

/** 평가 단계 */
export interface EvaluationStep {
    step: 'parse' | 'resolve' | 'evaluate' | 'cache';
    input?: string;
    output?: any;
    path?: string;
    value?: any;
    fromCache?: boolean;
    error?: string;
}

// ============================================
// 진단 타입
// ============================================

/** 진단 규칙 */
export interface DiagnosticRule {
    id: string;
    name: string;
    category: DiagnosticCategory;
    symptoms: string[];
    detector: (context: DiagnosticContext) => boolean;
    probability: number;
    solution: string;
    docLink: string;
    codeExample?: string;
}

/** 진단 카테고리 */
export type DiagnosticCategory =
    | 'state'
    | 'cache'
    | 'timing'
    | 'lifecycle'
    | 'performance'
    | 'network'
    | 'form'
    | 'conditional'
    | 'i18n'
    | 'websocket';

/** 진단 컨텍스트 */
export interface DiagnosticContext {
    stateHistory: StateSnapshot[];
    actionHistory: ActionLog[];
    cacheStats: CacheStats;
    currentState: StateView;
    lifecycle: LifecycleInfo;
    performance: PerformanceInfo;
    network: NetworkInfo;
    conditional: ConditionalInfo;
    form: FormInfo[];
    websocket: WebSocketInfo;
    stateAtTime: (timestamp: number) => StateView | undefined;
    layoutHas: (prop: string, value: string) => boolean;
}

/** 진단 결과 */
export interface DiagnosisResult {
    rule: DiagnosticRule;
    confidence: number;
    evidence: string[];
}

/** 해결 제안 */
export interface FixSuggestion {
    title: string;
    description: string;
    codeExample?: string;
    docLink: string;
}

/** 일반적인 이슈 */
export interface CommonIssue {
    id: string;
    name: string;
    description: string;
    frequency: number;
}

// ============================================
// 라이프사이클 타입
// ============================================

/** 컴포넌트 정보 */
export interface ComponentInfo {
    id: string;
    name: string;
    type: string;
    mountTime: number;
    props?: Record<string, any>;
    parentId?: string;
}

/** 리스너 정보 */
export interface ListenerInfo {
    type: string;
    target: string;
    addedAt: number;
    componentId?: string;
}

/** 마운트 이벤트 */
export interface MountEvent {
    componentId: string;
    componentName: string;
    timestamp: number;
}

/** 언마운트 이벤트 */
export interface UnmountEvent {
    componentId: string;
    componentName: string;
    timestamp: number;
    orphanedListeners: number;
}

/** 라이프사이클 정보 */
export interface LifecycleInfo {
    mountedComponents: ComponentInfo[];
    orphanedListeners: ListenerInfo[];
}

// ============================================
// 핸들러 타입
// ============================================

/** 핸들러 카테고리 타입 */
export type HandlerCategory = 'built-in' | 'custom' | 'module' | 'plugin';

/** 핸들러 정보 */
export interface HandlerInfo {
    /** 핸들러 이름 */
    name: string;
    /** 핸들러 카테고리 (built-in, custom, module, plugin) */
    category: HandlerCategory;
    /** 핸들러 설명 (있는 경우) */
    description?: string;
    /** 등록 시간 */
    registeredAt: number;
    /** 핸들러가 정의된 소스 (모듈명/플러그인명 등) */
    source?: string;
}

// ============================================
// 성능 타입
// ============================================

/** 성능 정보 */
export interface PerformanceInfo {
    renderCounts: Map<string, number>;
    bindingEvalCount: number;
    memoryWarnings: MemoryWarning[];
}

/** 메모리 경고 */
export interface MemoryWarning {
    type: 'large-state' | 'large-history' | 'orphaned-listeners' | 'excessive-renders';
    message: string;
    suggestion: string;
    severity: 'warning' | 'error';
}

/** 프로파일 항목 */
export interface ProfileEntry {
    type: 'render' | 'binding' | 'action' | 'network';
    component?: string;
    expression?: string;
    action?: string;
    timestamp: number;
    duration?: number;
}

/** 프로파일 보고서 */
export interface ProfileReport {
    duration: number;
    entries: ProfileEntry[];
    summary: {
        totalRenders: number;
        totalBindings: number;
        totalActions: number;
        slowestComponents: Array<{ name: string; renderCount: number; avgDuration: number }>;
        hotPaths: string[];
    };
}

// ============================================
// 네트워크 타입
// ============================================

/** 파싱된 쿼리 파라미터 타입 */
export type ParsedQueryParams = Record<string, string | string[]>;

/**
 * URL에서 쿼리 파라미터를 파싱합니다.
 * 배열 파라미터 (key[]=a&key[]=b)를 올바르게 처리합니다.
 */
export function parseQueryParams(url: string): ParsedQueryParams {
    const result: ParsedQueryParams = {};

    try {
        const urlObj = new URL(url, typeof window !== 'undefined' ? window.location.origin : 'http://localhost');
        const params = urlObj.searchParams;

        // 이미 처리한 키 추적
        const processedKeys = new Set<string>();

        for (const key of params.keys()) {
            // 이미 처리한 키는 스킵
            if (processedKeys.has(key)) continue;
            processedKeys.add(key);

            const values = params.getAll(key);
            // 여러 값이 있거나, 키가 []로 끝나면 배열로 처리
            if (values.length > 1 || key.endsWith('[]')) {
                result[key] = values;
            } else {
                result[key] = values[0];
            }
        }
    } catch {
        // URL 파싱 실패 시 빈 객체 반환
    }

    return result;
}

/**
 * URL에서 경로 부분만 추출합니다 (쿼리스트링 제외).
 */
export function extractPath(url: string): string {
    try {
        const urlObj = new URL(url, typeof window !== 'undefined' ? window.location.origin : 'http://localhost');
        return urlObj.pathname;
    } catch {
        return url.split('?')[0];
    }
}

/** 요청 정보 */
export interface RequestInfo {
    id: string;
    /** 경로만 (쿼리스트링 제외) */
    url: string;
    /** 전체 URL (쿼리스트링 포함) */
    fullUrl: string;
    /** 파싱된 쿼리 파라미터 */
    queryParams: ParsedQueryParams;
    method: string;
    startTime: number;
    status: 'pending' | 'success' | 'error' | 'timeout' | 'cancelled';
    /** POST/PUT 요청 본문 */
    requestBody?: any;
    /** 관련 데이터 소스 ID */
    dataSourceId?: string;
}

/** 요청 로그 */
export interface RequestLog extends RequestInfo {
    endTime: number;
    duration: number;
    statusCode?: number;
    error?: string;
    response?: any;
}

/** 네트워크 정보 */
export interface NetworkInfo {
    activeRequests: RequestInfo[];
    requestHistory: RequestLog[];
    pendingDataSources: string[];
}

// ============================================
// 컴포넌트 이벤트 타입
// ============================================

/** 컴포넌트 이벤트 구독 정보 */
export interface ComponentEventSubscription {
    /** 이벤트 이름 */
    eventName: string;
    /** 구독자 수 */
    subscriberCount: number;
    /** 최초 구독 시간 */
    firstSubscribedAt: number;
    /** 마지막 구독 시간 */
    lastSubscribedAt: number;
}

/** 컴포넌트 이벤트 발생 로그 */
export interface ComponentEventEmitLog {
    /** 고유 ID */
    id: string;
    /** 이벤트 이름 */
    eventName: string;
    /** 전달된 데이터 */
    data?: any;
    /** 발생 시간 */
    timestamp: number;
    /** 처리한 리스너 수 */
    listenerCount: number;
    /** 결과 (리스너 반환값 배열) */
    results?: any[];
    /** 에러 발생 여부 */
    hasError: boolean;
    /** 에러 메시지 */
    errorMessage?: string;
}

/** 컴포넌트 이벤트 정보 */
export interface ComponentEventInfo {
    subscriptions: ComponentEventSubscription[];
    emitHistory: ComponentEventEmitLog[];
    totalSubscribers: number;
    totalEmits: number;
}

// ============================================
// WebSocket 타입
// ============================================

/** WebSocket 연결 정보 */
export interface WebSocketInfo {
    connections: WebSocketConnectionInfo[];
    messageHistory: WebSocketMessage[];
    connectionState: 'connected' | 'disconnected' | 'reconnecting';
}

/** WebSocket 연결 상세 */
export interface WebSocketConnectionInfo {
    id: string;
    url: string;
    state: 'connecting' | 'open' | 'closing' | 'closed';
    connectedAt?: number;
}

/** WebSocket 메시지 */
export interface WebSocketMessage {
    id: string;
    connectionId: string;
    direction: 'sent' | 'received';
    type: string;
    payload: any;
    timestamp: number;
    sequence?: number;
}

// ============================================
// 조건부 렌더링 타입
// ============================================

/** 조건 정보 */
export interface ConditionInfo {
    id: string;
    expression: string;
    evaluatedValue: boolean;
    evaluationCount: number;
    lastEvaluated: number;
}

/** Iteration 정보 */
export interface IterationInfo {
    id: string;
    source: string;
    itemVar: string;
    indexVar?: string;
    sourceLength: number;
    lastRendered: number;
}

/** 스코프 변수 */
export interface ScopeVariable {
    name: string;
    value: any;
    source: 'global' | 'local' | 'iteration' | 'slot' | 'computed';
}

/** 조건부 렌더링 정보 */
export interface ConditionalInfo {
    ifConditions: ConditionInfo[];
    iterations: IterationInfo[];
}

// ============================================
// 표현식 타입
// ============================================

/** 표현식 평가 정보 */
export interface ExpressionEvalInfo {
    id: string;
    expression: string;           // 원본 표현식
    result: any;                  // 평가 결과
    resultType: string;           // 결과 타입 ('string', 'number', 'boolean', 'array', 'object', 'undefined', 'null')
    componentId?: string;         // 사용된 컴포넌트 ID
    componentName?: string;       // 컴포넌트 이름
    propName?: string;            // prop 이름
    fromCache: boolean;           // 캐시에서 가져왔는지 여부
    timestamp: number;            // 평가 시간
    duration?: number;            // 평가 소요 시간 (ms)
    steps?: EvaluationStep[];     // 추적 단계
    warning?: ExpressionWarning;  // 경고 정보
    method?: 'resolve' | 'resolveBindings' | 'evaluateExpression';  // 사용된 메서드
    skipCache?: boolean;          // skipCache 옵션 사용 여부
}

/** 표현식 경고 */
export interface ExpressionWarning {
    type: ExpressionWarningType;
    message: string;
    suggestion?: string;
}

/** 표현식 경고 유형 */
export type ExpressionWarningType =
    | 'undefined-result'        // 결과가 undefined
    | 'null-result'             // 결과가 null
    | 'array-to-string'         // 배열이 문자열로 변환됨
    | 'object-to-string'        // 객체가 문자열로 변환됨
    | 'missing-optional-chain'  // Optional chaining 누락 의심
    | 'wrong-iteration-var'     // 잘못된 iteration 변수명
    | 'type-mismatch'           // 기대 타입과 불일치
    | 'cache-stale'             // 캐시된 값이 오래됨
    | 'slow-evaluation';        // 평가 시간이 오래 걸림

/** 표현식 감시 콜백 */
export type ExpressionWatcherCallback = (info: ExpressionEvalInfo) => void;

/** 표현식 통계 */
export interface ExpressionStats {
    totalEvaluations: number;
    uniqueExpressions: number;
    warningCount: number;
    cacheHitRate: number;
    averageDuration: number;
    byType: Record<string, number>;  // 결과 타입별 개수
    byWarning: Record<string, number>;  // 경고 유형별 개수
}

/** 조건 변경 이벤트 */
export interface ConditionChange {
    id: string;
    expression: string;
    oldValue: boolean;
    newValue: boolean;
    timestamp: number;
}

// ============================================
// Form 타입
// ============================================

/** Form 정보 */
export interface FormInfo {
    id: string;
    dataKey: string;
    inputs: InputInfo[];
    trackedAt: number;
}

/** Input 정보 */
export interface InputInfo {
    name: string;
    type: string;
    value?: any;
    hasValidation?: boolean;
}

/** Form 변경 이벤트 */
export interface FormChange {
    formId: string;
    inputName: string;
    value: any;
    timestamp: number;
}

// ============================================
// 콜백 타입
// ============================================

/** 상태 감시 콜백 */
export type WatcherCallback = (value: any, prev: any, path: string) => void;

/** 액션 감시 콜백 */
export type ActionWatcherCallback = (action: ActionLog) => void;

/** 요청 감시 콜백 */
export type RequestWatcherCallback = (request: RequestLog) => void;

/** WebSocket 메시지 감시 콜백 */
export type WebSocketWatcherCallback = (message: WebSocketMessage) => void;

/** Form 변경 감시 콜백 */
export type FormWatcherCallback = (change: FormChange) => void;

/** 조건 변경 감시 콜백 */
export type ConditionWatcherCallback = (change: ConditionChange) => void;

// ============================================
// 데이터소스 타입
// ============================================

/** 데이터소스 정보 */
export interface DataSourceInfo {
    /** 데이터소스 ID */
    id: string;
    /** 데이터소스 타입 */
    type: 'api' | 'static' | 'route_params' | 'query_params' | 'websocket';
    /** API 엔드포인트 */
    endpoint?: string;
    /** HTTP 메서드 */
    method?: string;
    /** 로딩 상태 */
    status: 'idle' | 'loading' | 'loaded' | 'error';
    /** 데이터 경로 (data.data, data.items 등) */
    dataPath?: string;
    /** 항목 수 */
    itemCount?: number;
    /** 데이터 키 목록 */
    keys?: string[];
    /** 마지막 로드 시간 */
    lastLoadedAt?: number;
    /** 에러 메시지 */
    error?: string;
    /** auto_fetch 여부 */
    autoFetch?: boolean;
    /** initLocal 설정 */
    initLocal?: string | { key: string; path: string };
    /** initGlobal 설정 */
    initGlobal?: string | { key: string; path: string } | Array<string | { key: string; path: string }>;
    /** 데이터 경로 변환 추적 정보 */
    transformTracking?: DataPathTransformInfo;
}

/** 데이터 경로 변환 단계 */
export type DataPathTransformStep = 'api_response' | 'extract_data' | 'apply_path' | 'init_global' | 'init_local';

/** 데이터 경로 변환 정보 */
export interface DataPathTransformInfo {
    dataSourceId: string;
    timestamp: number;
    transformSteps: Array<{
        step: DataPathTransformStep;
        inputPath: string;
        inputValue: any;
        outputPath: string;
        outputValue: any;
        config?: {
            path?: string;
            initGlobal?: any;
            initLocal?: any;
        };
    }>;
    finalBinding?: {
        expression: string;
        resolvedPath: string;
        value: any;
    };
    warnings: string[];
}

/** 데이터 경로 변환 추적 통계 */
export interface DataPathTransformStats {
    totalTransforms: number;
    byDataSource: Record<string, number>;
    warningsCount: number;
    commonWarnings: Array<{ warning: string; count: number }>;
}

/** 데이터 경로 변환 추적 정보 (상태 덤프용) */
export interface DataPathTransformTrackingInfo {
    transforms: DataPathTransformInfo[];
    stats: DataPathTransformStats;
    timestamp: number;
}

// ============================================
// Nested Context 추적 타입
// ============================================

/** Nested Context 컴포넌트 타입 */
export type NestedContextType = 'expandChildren' | 'cellChildren' | 'iteration' | 'modal' | 'slot';

/** Nested Context 정보 */
export interface NestedContextInfo {
    id: string;
    timestamp: number;
    componentId: string;
    componentType: NestedContextType;
    parentContext: {
        available: string[];
        values: Record<string, any>;
    };
    ownContext: {
        added: string[];
        values: Record<string, any>;
    };
    mergedContext: {
        all: string[];
        values: Record<string, any>;
    };
    accessAttempts: Array<{
        path: string;
        found: boolean;
        value?: any;
        error?: string;
    }>;
    depth: number;
    parentId?: string;
}

/** Nested Context 추적 통계 */
export interface NestedContextStats {
    totalContexts: number;
    byType: Record<NestedContextType, number>;
    maxDepth: number;
    failedAccessCount: number;
    commonFailedPaths: Array<{ path: string; count: number }>;
}

/** Nested Context 추적 정보 (상태 덤프용) */
export interface NestedContextTrackingInfo {
    contexts: NestedContextInfo[];
    stats: NestedContextStats;
    timestamp: number;
}

// ============================================
// Form 바인딩 검증 타입 (Phase 6)
// ============================================

/** Form 바인딩 검증 이슈 유형 */
export type FormBindingIssueType =
    | 'missing-datakey'           // Form에 dataKey가 없음
    | 'missing-input-name'        // Input에 name이 없음
    | 'context-not-propagated'    // parentFormContextProp이 undefined로 설정됨
    | 'sortable-context-break'    // Sortable 컨테이너 내에서 폼 컨텍스트 단절
    | 'modal-context-isolation'   // 모달에서 부모 Form 컨텍스트 접근 불가
    | 'duplicate-input-name'      // 동일 Form 내 중복 Input name
    | 'value-type-mismatch'       // 값 타입과 Input 타입 불일치
    | 'binding-path-invalid';     // 바인딩 경로가 유효하지 않음

/** Form 바인딩 검증 이슈 */
export interface FormBindingIssue {
    /** 고유 ID */
    id: string;
    /** 발생 시간 */
    timestamp: number;
    /** 이슈 유형 */
    type: FormBindingIssueType;
    /** 심각도 */
    severity: 'info' | 'warning' | 'error';
    /** Form ID */
    formId?: string;
    /** Form dataKey */
    formDataKey?: string;
    /** Input 정보 */
    inputInfo?: {
        name?: string;
        type?: string;
        componentId?: string;
    };
    /** 컨텍스트 정보 */
    contextInfo?: {
        hasParentFormContext: boolean;
        parentFormContextProp?: string;
        isInsideSortable: boolean;
        isInsideModal: boolean;
        depth: number;
    };
    /** 문제 설명 */
    description: string;
    /** 해결 제안 */
    suggestion: string;
    /** 관련 문서 링크 */
    docLink?: string;
}

/** Form 바인딩 검증 정보 */
export interface FormBindingValidationInfo {
    /** Form ID */
    formId: string;
    /** Form dataKey */
    dataKey?: string;
    /** 검증 시간 */
    timestamp: number;
    /** 컨텍스트 전파 상태 */
    contextPropagation: {
        hasParentContext: boolean;
        parentContextProp?: string;
        isContextBroken: boolean;
        breakReason?: string;
    };
    /** Input 바인딩 상태 */
    inputBindings: Array<{
        inputName: string;
        inputType: string;
        bindingPath: string;
        isValid: boolean;
        currentValue?: any;
        issues: string[];
    }>;
    /** 전체 유효성 */
    isValid: boolean;
    /** 발견된 이슈 수 */
    issueCount: number;
}

/** Form 바인딩 검증 추적 통계 */
export interface FormBindingValidationStats {
    /** 검증된 Form 수 */
    totalFormsValidated: number;
    /** 유효한 Form 수 */
    validForms: number;
    /** 이슈가 있는 Form 수 */
    formsWithIssues: number;
    /** 총 이슈 수 */
    totalIssues: number;
    /** 이슈 유형별 개수 */
    byIssueType: Record<FormBindingIssueType, number>;
    /** 심각도별 개수 */
    bySeverity: Record<string, number>;
    /** 자주 발생하는 이슈 TOP 5 */
    topIssues: Array<{ type: FormBindingIssueType; count: number; description: string }>;
}

/** Form 바인딩 검증 추적 정보 (상태 덤프용) */
export interface FormBindingValidationTrackingInfo {
    /** 발견된 이슈 목록 */
    issues: FormBindingIssue[];
    /** Form별 검증 결과 */
    validations: FormBindingValidationInfo[];
    /** 통계 */
    stats: FormBindingValidationStats;
    /** 추적 시간 */
    timestamp: number;
}

// ============================================
// Computed 의존성 추적 타입 (Phase 7)
// ============================================

/** Computed 의존성 유형 */
export type ComputedDependencyType = 'state' | 'datasource' | 'computed' | 'expression';

/** Computed 재계산 트리거 */
export type ComputedRecalcTrigger =
    | 'state-change'           // 상태 변경으로 인한 재계산
    | 'datasource-update'      // 데이터소스 업데이트
    | 'dependency-change'      // 의존 computed 변경
    | 'manual'                 // 수동 재계산
    | 'initial';               // 초기 계산

/** Computed 속성 정보 */
export interface ComputedPropertyInfo {
    /** 고유 ID */
    id: string;
    /** Computed 속성 이름/경로 */
    name: string;
    /** 표현식 (원본) */
    expression: string;
    /** 컴포넌트 ID */
    componentId?: string;
    /** 의존성 목록 */
    dependencies: Array<{
        type: ComputedDependencyType;
        path: string;
        value?: any;
    }>;
    /** 현재 값 */
    currentValue: any;
    /** 마지막 계산 시간 */
    lastComputedAt: number;
    /** 계산 소요 시간 (ms) */
    computationTime: number;
    /** 에러 정보 (있는 경우) */
    error?: string;
}

/** Computed 재계산 로그 */
export interface ComputedRecalcLog {
    /** 고유 ID */
    id: string;
    /** Computed 속성 ID */
    computedId: string;
    /** Computed 속성 이름 */
    computedName: string;
    /** 재계산 시간 */
    timestamp: number;
    /** 트리거 */
    trigger: ComputedRecalcTrigger;
    /** 트리거된 의존성 정보 */
    triggeredBy?: {
        type: ComputedDependencyType;
        path: string;
        oldValue?: any;
        newValue?: any;
    };
    /** 이전 값 */
    previousValue: any;
    /** 새 값 */
    newValue: any;
    /** 값 변경 여부 */
    valueChanged: boolean;
    /** 계산 소요 시간 (ms) */
    computationTime: number;
    /** 연쇄 재계산 수 (이 계산으로 인해 트리거된 다른 computed 수) */
    cascadeCount: number;
}

/** Computed 의존성 체인 */
export interface ComputedDependencyChain {
    /** 루트 computed */
    root: string;
    /** 의존성 트리 (중첩 구조) */
    tree: ComputedDependencyNode;
    /** 순환 의존성 감지 여부 */
    hasCycle: boolean;
    /** 순환 경로 (있는 경우) */
    cyclePath?: string[];
    /** 최대 깊이 */
    maxDepth: number;
}

/** Computed 의존성 노드 */
export interface ComputedDependencyNode {
    /** 노드 이름 */
    name: string;
    /** 노드 유형 */
    type: ComputedDependencyType;
    /** 자식 의존성 */
    children: ComputedDependencyNode[];
    /** 깊이 */
    depth: number;
}

/** Computed 의존성 추적 통계 */
export interface ComputedDependencyStats {
    /** 총 computed 속성 수 */
    totalComputed: number;
    /** 총 재계산 횟수 */
    totalRecalculations: number;
    /** 불필요한 재계산 수 (값 변경 없음) */
    unnecessaryRecalculations: number;
    /** 평균 계산 시간 (ms) */
    avgComputationTime: number;
    /** 가장 많이 재계산된 속성 TOP 5 */
    topRecalculated: Array<{ name: string; count: number }>;
    /** 가장 느린 계산 TOP 5 */
    slowestComputed: Array<{ name: string; avgTime: number }>;
    /** 순환 의존성 감지 수 */
    cycleDetectionCount: number;
    /** 트리거별 재계산 횟수 */
    byTrigger: Record<ComputedRecalcTrigger, number>;
}

/** Computed 의존성 추적 정보 (상태 덤프용) */
export interface ComputedDependencyTrackingInfo {
    /** Computed 속성 목록 */
    properties: ComputedPropertyInfo[];
    /** 재계산 로그 */
    recalcLogs: ComputedRecalcLog[];
    /** 의존성 체인 분석 */
    dependencyChains: ComputedDependencyChain[];
    /** 통계 */
    stats: ComputedDependencyStats;
    /** 추적 시간 */
    timestamp: number;
}

// ============================================
// Phase 8: 모달 상태 스코프 추적
// ============================================

/** 모달 상태 스코프 타입 */
export type ModalStateScopeType = 'isolated' | 'shared' | 'inherited';

/** 모달 상태 이슈 타입 */
export type ModalStateIssueType =
    | 'state-leakage'           // 모달 닫힘 후 상태 유출
    | 'isolation-violation'     // 격리 규칙 위반
    | 'parent-mutation'         // 모달에서 부모 상태 직접 변경
    | 'orphaned-state'          // 모달 닫힌 후 고아 상태
    | 'scope-mismatch'          // 스코프 불일치
    | 'cleanup-failure'         // 상태 정리 실패
    | 'missing-definition';     // modalStack에 존재하나 레이아웃 modals 정의 없음

/** 모달 상태 정보 */
export interface ModalStateInfo {
    /** 모달 ID */
    modalId: string;
    /** 모달 이름 */
    modalName: string;
    /** 열린 시간 */
    openedAt: number;
    /** 닫힌 시간 (null = 아직 열려있음) */
    closedAt: number | null;
    /** 스코프 타입 */
    scopeType: ModalStateScopeType;
    /** 부모 모달 ID (중첩 모달인 경우) */
    parentModalId?: string;
    /** 컴포넌트 ID */
    componentId?: string;
    /** 초기 상태 스냅샷 */
    initialState: Record<string, any>;
    /** 현재/최종 상태 스냅샷 */
    currentState: Record<string, any>;
    /** 상태 변경 횟수 */
    stateChangeCount: number;
    /** 격리된 상태 키 목록 */
    isolatedStateKeys: string[];
    /** 공유된 상태 키 목록 */
    sharedStateKeys: string[];
}

/** 모달 상태 이슈 */
export interface ModalStateIssue {
    /** 이슈 ID */
    id: string;
    /** 이슈 타입 */
    type: ModalStateIssueType;
    /** 모달 ID */
    modalId: string;
    /** 모달 이름 */
    modalName: string;
    /** 발생 시간 */
    timestamp: number;
    /** 심각도 */
    severity: 'warning' | 'error';
    /** 상세 설명 */
    description: string;
    /** 관련 상태 키 */
    affectedStateKeys: string[];
    /** 유출된 값 (state-leakage 시) */
    leakedValue?: any;
    /** 예상 값 */
    expectedValue?: any;
    /** 실제 값 */
    actualValue?: any;
    /** 스택 정보 */
    stackInfo?: string;
}

/** 모달 간 상태 관계 */
export interface ModalStateRelation {
    /** 부모 모달 ID */
    parentModalId: string;
    /** 자식 모달 ID */
    childModalId: string;
    /** 공유되는 상태 키 목록 */
    sharedKeys: string[];
    /** 격리되는 상태 키 목록 */
    isolatedKeys: string[];
    /** 관계 유형 */
    relationType: 'parent-child' | 'sibling' | 'independent';
}

/** 모달 상태 변경 로그 */
export interface ModalStateChangeLog {
    /** 로그 ID */
    id: string;
    /** 모달 ID */
    modalId: string;
    /** 모달 이름 */
    modalName: string;
    /** 변경 시간 */
    timestamp: number;
    /** 변경된 상태 키 */
    stateKey: string;
    /** 이전 값 */
    previousValue: any;
    /** 새 값 */
    newValue: any;
    /** 변경 원인 */
    changeSource: 'user-action' | 'api-response' | 'parent-sync' | 'init' | 'cleanup';
    /** 격리 위반 여부 */
    violatesIsolation: boolean;
}

/** 모달 상태 스코프 통계 */
export interface ModalStateScopeStats {
    /** 총 추적된 모달 수 */
    totalModals: number;
    /** 현재 열려있는 모달 수 */
    openModals: number;
    /** 중첩 모달 수 */
    nestedModals: number;
    /** 총 이슈 수 */
    totalIssues: number;
    /** 심각도별 이슈 수 */
    issuesBySeverity: Record<'warning' | 'error', number>;
    /** 타입별 이슈 수 */
    issuesByType: Record<ModalStateIssueType, number>;
    /** 스코프 타입별 모달 수 */
    byScope: Record<ModalStateScopeType, number>;
    /** 상태 유출 감지 수 */
    leakageDetectionCount: number;
    /** 정리 실패 수 */
    cleanupFailureCount: number;
}

/** 모달 상태 스코프 추적 정보 (상태 덤프용) */
export interface ModalStateScopeTrackingInfo {
    /** 모달 상태 정보 목록 */
    modals: ModalStateInfo[];
    /** 모달 상태 이슈 목록 */
    issues: ModalStateIssue[];
    /** 모달 간 관계 목록 */
    relations: ModalStateRelation[];
    /** 상태 변경 로그 */
    changeLogs: ModalStateChangeLog[];
    /** 통계 */
    stats: ModalStateScopeStats;
    /** 추적 시간 */
    timestamp: number;
}

// ============================================
// 설정 타입
// ============================================

/** DevTools 설정 */
export interface DevToolsConfig {
    enabled: boolean;
    maxHistorySize: number;
    logLevel: 'debug' | 'info' | 'warn' | 'error';
    serverEndpoint: string;
    autoCapture: boolean;
}

// ============================================
// 서버 통신 타입
// ============================================

/** 상태 덤프 페이로드 */
export interface StateDumpPayload {
    state: StateView;
    actions: ActionLog[];
    cache: CacheStats;
    lifecycle?: LifecycleInfo;
    network?: NetworkInfo;
    expressions?: ExpressionEvalInfo[];
    forms?: FormInfo[];
    performance?: SerializablePerformanceInfo;
    conditionals?: ConditionalInfo;
    dataSources?: DataSourceInfo[];
    handlers?: HandlerInfo[];
    componentEvents?: ComponentEventInfo;
    stateRendering?: StateRenderingInfo;
    stateHierarchy?: StateHierarchyInfo;
    contextFlow?: ContextFlowInfo;
    styleValidation?: StyleValidationInfo;
    authDebug?: AuthDebugInfo;
    logs?: LogInfo;
    layout?: LayoutDebugInfo;
    changeDetection?: ChangeDetectionInfo;
    sequenceTracking?: SequenceTrackingInfo;
    staleClosureTracking?: StaleClosureTrackingInfo;
    namedActionTracking?: NamedActionTrackingInfo;
    timestamp: number;
}

/** 직렬화 가능한 성능 정보 (Map을 객체로 변환) */
export interface SerializablePerformanceInfo {
    renderCounts: Record<string, number>;
    bindingEvalCount: number;
    memoryWarnings: MemoryWarning[];
}

/** 서버 응답 */
export interface ServerResponse {
    status: 'success' | 'error' | 'no_data';
    message?: string;
    path?: string;
    data?: any;
}

// ============================================
// 상태-렌더링 추적 타입
// ============================================

/** 상태 업데이트로 인한 렌더링 로그 */
export interface StateRenderingLog {
    /** 고유 ID */
    id: string;
    /** setState 호출 ID (액션에서 추적) */
    setStateId: string;
    /** 변경된 상태 경로 (예: "_global.user.name") */
    statePath: string;
    /** 이전 값 */
    oldValue: any;
    /** 새 값 */
    newValue: any;
    /** 발생 시간 */
    timestamp: number;
    /** 트리거한 액션/핸들러 정보 */
    triggeredBy: {
        actionId?: string;
        handlerType?: string;
        source?: string;
    };
    /** 이 상태 변경으로 렌더링된 컴포넌트들 */
    renderedComponents: RenderedComponentInfo[];
    /** 렌더링 완료까지 소요 시간 (ms) */
    totalRenderDuration: number;
    /** 영향받은 바인딩 표현식 수 */
    affectedBindingsCount: number;
}

/** 렌더링된 컴포넌트 정보 */
export interface RenderedComponentInfo {
    /** 컴포넌트 ID */
    componentId: string;
    /** 컴포넌트 이름/타입 */
    componentName: string;
    /** 렌더링 소요 시간 (ms) */
    renderDuration: number;
    /** 이 컴포넌트가 참조한 상태 경로들 */
    accessedStatePaths: string[];
    /** 평가된 바인딩 표현식들 */
    evaluatedBindings: string[];
    /** 렌더링 순서 */
    renderOrder: number;
    /** 부모 컴포넌트 ID */
    parentId?: string;
}

/** 상태-렌더링 추적 정보 */
export interface StateRenderingInfo {
    /** 최근 상태-렌더링 로그 */
    logs: StateRenderingLog[];
    /** 컴포넌트별 렌더링 횟수 */
    componentRenderCounts: Record<string, number>;
    /** 상태 경로별 영향받는 컴포넌트 매핑 */
    stateToComponentMap: Record<string, string[]>;
    /** 통계 */
    stats: StateRenderingStats;
}

/** 상태-렌더링 통계 */
export interface StateRenderingStats {
    /** 총 상태 변경 횟수 */
    totalStateChanges: number;
    /** 총 렌더링 횟수 */
    totalRenders: number;
    /** 평균 렌더링 소요 시간 */
    avgRenderDuration: number;
    /** 상태 변경당 평균 렌더링 컴포넌트 수 */
    avgComponentsPerChange: number;
    /** 가장 많이 렌더링된 컴포넌트 TOP 5 */
    topRenderedComponents: Array<{ name: string; count: number }>;
    /** 가장 영향력 있는 상태 경로 TOP 5 (많은 컴포넌트에 영향) */
    topInfluentialPaths: Array<{ path: string; affectedComponents: number }>;
}

/** 현재 추적 중인 상태 변경 컨텍스트 */
export interface StateChangeContext {
    /** 현재 setState 호출 ID */
    setStateId: string;
    /** 변경 시작 시간 */
    startTime: number;
    /** 변경된 경로 */
    changedPath: string;
    /** 트리거 정보 */
    trigger: {
        actionId?: string;
        handlerType?: string;
        source?: string;
    };
    /** 이 변경으로 렌더링된 컴포넌트들 (추적 중) */
    renderedComponents: RenderedComponentInfo[];
}

// ============================================
// 상태 계층 타입 (g7-state-hierarchy)
// ============================================

/** 상태 계층 레이어 */
export interface StateHierarchyLayer {
    /** 레이어 이름 */
    name: string;
    /** 레이어 타입 */
    type: 'global' | 'dynamicState' | 'componentContext' | 'effective';
    /** 레이어가 속한 컴포넌트 ID (있는 경우) */
    componentId?: string;
    /** 상태 경로 */
    path?: string;
    /** 상태 값 */
    values: Record<string, any>;
    /** 우선순위 (높을수록 우선) */
    priority: number;
}

/** 상태 충돌 정보 */
export interface StateConflict {
    /** 충돌하는 상태 경로 */
    path: string;
    /** global _local의 값 */
    globalValue: any;
    /** dynamicState의 값 */
    dynamicStateValue?: any;
    /** componentContext.state의 값 */
    contextStateValue?: any;
    /** 실제 적용되는 값 */
    effectiveValue: any;
    /** 이 상태를 사용하는 컴포넌트들 */
    usedBy: string[];
    /** 이 상태를 사용하지 않는(못하는) 컴포넌트들 */
    notUsedBy: string[];
    /** 충돌 심각도 */
    severity: 'info' | 'warning' | 'error';
    /** 충돌 설명 */
    description: string;
}

/** 컴포넌트 상태 소스 정보 */
export interface ComponentStateSource {
    /** 컴포넌트 ID */
    componentId: string;
    /** 컴포넌트 이름 */
    componentName: string;
    /** 상태 읽기 소스 */
    stateSource: {
        /** 사용하는 _global 상태 경로들 */
        global: string[];
        /** 사용하는 _local 상태 경로들 */
        local: string[];
        /** 사용하는 context 변수들 (row, index 등) */
        context: string[];
    };
    /** 상태 제공자 정보 */
    stateProvider: {
        /** 상태 제공 타입 */
        type: 'globalState' | 'dynamicState' | 'componentContext';
        /** componentContext 사용 여부 */
        hasComponentContext: boolean;
        /** 부모 컴포넌트 ID */
        parentId?: string;
    };
}

/** 상태 계층 정보 */
export interface StateHierarchyInfo {
    /** 상태 계층 레이어들 */
    layers: StateHierarchyLayer[];
    /** 상태 충돌 목록 */
    conflicts: StateConflict[];
    /** 컴포넌트별 상태 소스 정보 */
    componentStateSources: ComponentStateSource[];
    /** 추적 시간 */
    timestamp: number;
}

// ============================================
// componentContext 흐름 추적 타입
// ============================================

/** componentContext 전파 정보 */
export interface ContextFlowNode {
    /** 컴포넌트 이름 */
    component: string;
    /** 컴포넌트 ID */
    componentId: string;
    /** context를 받았는지 여부 */
    contextReceived: boolean;
    /** context를 자식에게 전달했는지 여부 */
    passedToChildren: boolean;
    /** context를 렌더링에 사용했는지 여부 */
    usedInRender: boolean;
    /** 자식 노드들 */
    children?: ContextFlowNode[];
}

/** componentContext 흐름 정보 */
export interface ContextFlowInfo {
    /** 루트 컴포넌트 */
    rootComponent: string;
    /** context 흐름 트리 */
    contextFlow: ContextFlowNode[];
    /** 추적 시간 */
    timestamp: number;
}

// ============================================
// CSS 스타일 검증 타입 (g7-styles)
// ============================================

/** CSS 스타일 이슈 */
export interface StyleIssue {
    /** 이슈 ID */
    id: string;
    /** 이슈 타입 */
    type: StyleIssueType;
    /** 영향받는 컴포넌트 ID */
    componentId: string;
    /** 영향받는 컴포넌트 이름 */
    componentName: string;
    /** 문제 CSS 속성 */
    property: string;
    /** 현재 값 */
    currentValue: string;
    /** 예상 값 */
    expectedValue?: string;
    /** 심각도 */
    severity: 'info' | 'warning' | 'error';
    /** 설명 */
    description: string;
    /** 제안 */
    suggestion?: string;
}

/** 스타일 이슈 타입 */
export type StyleIssueType =
    | 'invisible-element'       // 보이지 않는 요소
    | 'tailwind-purging'        // Tailwind CSS purging
    | 'z-index-conflict'        // z-index 충돌
    | 'overflow-hidden'         // 잘린 콘텐츠
    | 'dark-mode-missing'       // 다크 모드 스타일 누락
    | 'responsive-issue';       // 반응형 이슈

/** 컴포넌트 스타일 정보 */
export interface ComponentStyleInfo {
    /** 컴포넌트 ID */
    componentId: string;
    /** 컴포넌트 이름 */
    componentName: string;
    /** 적용된 CSS 클래스 */
    classes: string[];
    /** computed styles (주요 속성만) */
    computedStyles: {
        display: string;
        visibility: string;
        opacity: string;
        position: string;
        zIndex: string;
        overflow: string;
        width: string;
        height: string;
        backgroundColor: string;
        color: string;
    };
    /** Tailwind 클래스 분석 */
    tailwindAnalysis: {
        /** 사용된 Tailwind 클래스 */
        usedClasses: string[];
        /** 다크 모드 클래스 */
        darkClasses: string[];
        /** 반응형 클래스 */
        responsiveClasses: string[];
        /** 동적 클래스 (safelist 필요 가능성) */
        dynamicClasses: string[];
    };
}

/** CSS 검증 정보 */
export interface StyleValidationInfo {
    /** 스타일 이슈 목록 */
    issues: StyleIssue[];
    /** 컴포넌트별 스타일 정보 */
    componentStyles: ComponentStyleInfo[];
    /** 통계 */
    stats: {
        totalComponents: number;
        invisibleCount: number;
        tailwindIssueCount: number;
        darkModeIssueCount: number;
    };
    /** 추적 시간 */
    timestamp: number;
}

// ============================================
// 인증 디버깅 타입 (g7-auth)
// ============================================

/** 인증 상태 정보 */
export interface AuthStateInfo {
    /** 로그인 상태 */
    isAuthenticated: boolean;
    /** 현재 사용자 */
    user?: {
        id: number | string;
        email: string;
        name?: string;
        /** 역할 목록 */
        roles?: Array<{
            id?: number | string;
            name: string;
            guard_name?: string;
        }>;
        /** 권한 목록 */
        permissions?: Array<{
            id?: number | string;
            name: string;
            guard_name?: string;
        }>;
        /** 프로필 이미지 */
        avatar?: string;
        /** 생성일 */
        created_at?: string;
        /** 수정일 */
        updated_at?: string;
        /** 마지막 로그인 */
        last_login_at?: string;
        /** 이메일 인증일 */
        email_verified_at?: string;
        /** 추가 속성 (동적 필드) */
        [key: string]: any;
    };
    /** 토큰 정보 */
    tokens: {
        /** access_token 존재 여부 */
        hasAccessToken: boolean;
        /** refresh_token 존재 여부 */
        hasRefreshToken: boolean;
        /** access_token 만료 시간 */
        accessTokenExpiresAt?: number;
        /** refresh_token 만료 시간 */
        refreshTokenExpiresAt?: number;
        /** 토큰 저장 위치 */
        storage: 'localStorage' | 'sessionStorage' | 'cookie' | 'memory';
        /** 토큰 일부 (마스킹) */
        accessTokenPreview?: string;
    };
    /** 마지막 인증 활동 */
    lastActivity?: number;
    /** AuthManager 소스 */
    source?: 'AuthManager' | 'G7Core.state' | 'unknown';
}

/** 인증 이벤트 로그 */
export interface AuthEventLog {
    /** 이벤트 ID */
    id: string;
    /** 이벤트 타입 */
    type: AuthEventType;
    /** 발생 시간 */
    timestamp: number;
    /** 성공 여부 */
    success: boolean;
    /** 에러 메시지 */
    error?: string;
    /** 추가 정보 */
    details?: Record<string, any>;
}

/** 인증 이벤트 타입 */
export type AuthEventType =
    | 'login'                   // 로그인 시도
    | 'logout'                  // 로그아웃
    | 'token-refresh'           // 토큰 갱신
    | 'token-expired'           // 토큰 만료
    | 'session-restored'        // 세션 복원
    | 'permission-denied'       // 권한 거부
    | 'api-unauthorized';       // API 401 응답

/** API 인증 헤더 분석 */
export interface AuthHeaderAnalysis {
    /** 요청 URL */
    url: string;
    /** Authorization 헤더 존재 여부 */
    hasAuthHeader: boolean;
    /** 헤더 타입 (Bearer 등) */
    headerType?: string;
    /** 토큰 유효성 (형식 검증만) */
    tokenValid: boolean;
    /** 응답 상태 코드 */
    responseStatus?: number;
    /** 타임스탬프 */
    timestamp: number;
}

/** 인증 디버깅 정보 */
export interface AuthDebugInfo {
    /** 현재 인증 상태 */
    state: AuthStateInfo;
    /** 인증 이벤트 이력 */
    events: AuthEventLog[];
    /** API 인증 헤더 분석 */
    headerAnalysis: AuthHeaderAnalysis[];
    /** 통계 */
    stats: {
        loginAttempts: number;
        successfulLogins: number;
        failedLogins: number;
        tokenRefreshes: number;
        unauthorizedResponses: number;
    };
    /** 추적 시간 */
    timestamp: number;
}

// ============================================
// 로그 추적 타입 (g7-logs)
// ============================================

/** 로그 레벨 */
export type LogLevel = 'log' | 'warn' | 'error' | 'debug' | 'info';

/** 로그 엔트리 */
export interface LogEntry {
    /** 고유 ID */
    id: string;
    /** 로그 레벨 */
    level: LogLevel;
    /** Logger prefix (예: 'TemplateApp', 'DataBindingEngine') */
    prefix: string;
    /** 로그 메시지 (직렬화된 args) */
    message: string;
    /** 원본 args (객체 포함, 직렬화됨) */
    args: any[];
    /** 타임스탬프 */
    timestamp: number;
    /** 스택 트레이스 (error 레벨인 경우) */
    stack?: string;
}

/** 로그 통계 */
export interface LogStats {
    /** 전체 로그 수 */
    totalLogs: number;
    /** 레벨별 로그 수 */
    byLevel: Record<LogLevel, number>;
    /** Prefix별 로그 수 */
    byPrefix: Record<string, number>;
    /** 최근 에러 수 (최근 1분 내) */
    recentErrors: number;
    /** 최근 경고 수 (최근 1분 내) */
    recentWarnings: number;
}

/** 로그 필터 옵션 */
export interface LogFilterOptions {
    /** 레벨 필터 */
    level?: LogLevel | LogLevel[];
    /** Prefix 필터 (부분 일치) */
    prefix?: string;
    /** 메시지 검색 */
    search?: string;
    /** 시작 시간 */
    since?: number;
    /** 최대 개수 */
    limit?: number;
}

/** 로그 정보 (상태 덤프용) */
export interface LogInfo {
    /** 로그 엔트리 목록 */
    entries: LogEntry[];
    /** 로그 통계 */
    stats: LogStats;
}

// ============================================
// 레이아웃 추적 타입 (g7-layout)
// ============================================

/** 현재 렌더링 중인 레이아웃 정보 */
export interface CurrentLayoutInfo {
    /** 레이아웃 경로 (예: "admin/admin_dashboard") */
    layoutPath: string;
    /** 템플릿 ID (예: "sirsoft-admin_basic") */
    templateId: string;
    /** 전체 레이아웃 JSON (백엔드에서 받은 원본) */
    layoutJson: LayoutJsonData;
    /** 로드 시간 */
    loadedAt: number;
    /** 레이아웃 버전 */
    version?: string;
    /** 레이아웃 이름 */
    layoutName?: string;
    /** 레이아웃 소스 (cache, api) */
    source: 'cache' | 'api';
}

/** 레이아웃 JSON 데이터 구조 */
export interface LayoutJsonData {
    /** 레이아웃 버전 */
    version?: string;
    /** 레이아웃 이름 */
    layout_name?: string;
    /** 최상위 컴포넌트 목록 */
    components?: any[];
    /** 레이아웃 메타데이터 */
    meta?: Record<string, any>;
    /** 데이터 소스 */
    data_sources?: any[];
    /** 초기화 액션 */
    init_actions?: any[];
    /** 외부 스크립트 */
    scripts?: any[];
    /** 시스템 경고 */
    warnings?: any[];
    /** 에러 핸들링 설정 */
    errorHandling?: Record<string, any>;
    /** 정적 상수 (defines) */
    defines?: Record<string, any>;
    /** 파생 상태 (computed) */
    computed?: Record<string, any>;
    /** 슬롯 정의 */
    slots?: Record<string, any>;
    /** 모달 정의 */
    modals?: Record<string, any>;
    /** 레이아웃 접근 권한 목록 (빈 배열=공개, 값 있음=AND 로직) */
    permissions?: string[];
    /** 기타 속성 */
    [key: string]: any;
}

/** 레이아웃 이력 항목 */
export interface LayoutHistoryEntry {
    /** 고유 ID */
    id: string;
    /** 레이아웃 경로 */
    layoutPath: string;
    /** 템플릿 ID */
    templateId: string;
    /** 로드 시간 */
    loadedAt: number;
    /** 레이아웃 소스 */
    source: 'cache' | 'api';
    /** 레이아웃 버전 */
    version?: string;
}

/** 레이아웃 정보 (상태 덤프용) */
export interface LayoutDebugInfo {
    /** 현재 레이아웃 */
    current: CurrentLayoutInfo | null;
    /** 레이아웃 이력 */
    history: LayoutHistoryEntry[];
    /** 통계 */
    stats: {
        /** 총 로드 횟수 */
        totalLoads: number;
        /** 캐시 히트 횟수 */
        cacheHits: number;
        /** API 로드 횟수 */
        apiLoads: number;
    };
}

// ============================================
// 변경 감지 타입 (g7-change-detection)
// ============================================

/**
 * 핸들러 종료 사유
 * - 'normal': 정상 종료 (모든 로직 완료)
 * - 'early-return-condition': 조건문에서 early return
 * - 'early-return-validation': 검증 실패로 early return
 * - 'error': 에러로 인한 종료
 */
export type HandlerExitReason =
    | 'normal'                    // 정상 완료
    | 'early-return-condition'    // 조건부 early return
    | 'early-return-validation'   // 검증 실패
    | 'error';                    // 에러 발생

/** 핸들러 실행 상세 정보 */
export interface HandlerExecutionDetail {
    /** 핸들러 이름 */
    handlerName: string;
    /** 실행 ID (actionId와 연결) */
    executionId: string;
    /** 시작 시간 */
    startTime: number;
    /** 종료 시간 */
    endTime?: number;
    /** 소요 시간 (ms) */
    duration?: number;
    /** 종료 사유 */
    exitReason?: HandlerExitReason;
    /** 종료 위치 (코드 내 어디서 종료되었는지) */
    exitLocation?: string;
    /** 종료 시점 상세 설명 */
    exitDescription?: string;
    /** 상태 변경 기록 */
    stateChanges: StateChangeRecord[];
    /** 데이터소스 변경 기록 */
    dataSourceChanges: DataSourceChangeRecord[];
    /** 기대된 변경 (있는 경우) */
    expectedChanges?: ChangeExpectation;
    /** 알림 목록 */
    alerts: ChangeAlert[];
}

/** 상태 변경 기록 */
export interface StateChangeRecord {
    /** 변경 ID */
    id: string;
    /** 변경 대상 경로 (예: "_global.products.items") */
    path: string;
    /** 변경 유형 */
    changeType: 'set' | 'merge' | 'delete' | 'push' | 'splice';
    /** 이전 값 */
    oldValue: any;
    /** 새 값 */
    newValue: any;
    /** 변경 시간 */
    timestamp: number;
    /** 값 비교 결과 */
    comparison: {
        /** 타입이 변경되었는지 */
        typeChanged: boolean;
        /** 이전 타입 */
        oldType: string;
        /** 새 타입 */
        newType: string;
        /** 객체/배열인 경우 깊은 비교 결과 */
        isDeepEqual: boolean;
        /** 변경된 키 목록 (객체인 경우) */
        changedKeys?: string[];
    };
    /** 관련 핸들러 실행 ID */
    executionId?: string;
}

/** 데이터소스 변경 기록 */
export interface DataSourceChangeRecord {
    /** 데이터소스 ID */
    dataSourceId: string;
    /** 변경 유형 */
    changeType: 'refetch' | 'update' | 'clear';
    /** 변경 시간 */
    timestamp: number;
    /** 변경 전 상태 */
    previousStatus?: 'idle' | 'loading' | 'loaded' | 'error';
    /** 변경 후 상태 */
    newStatus: 'idle' | 'loading' | 'loaded' | 'error';
    /** 관련 핸들러 실행 ID */
    executionId?: string;
}

/** 변경 기대치 (핸들러가 예고하는 변경) */
export interface ChangeExpectation {
    /** 기대되는 상태 변경 경로 목록 */
    expectedStatePaths: string[];
    /** 기대되는 데이터소스 갱신 목록 */
    expectedDataSources: string[];
    /** 기대 설정 시간 */
    setAt: number;
    /** 기대 설정 소스 (어떤 핸들러에서 설정했는지) */
    source: string;
}

/** 변경 알림 */
export interface ChangeAlert {
    /** 알림 ID */
    id: string;
    /** 알림 유형 */
    type: ChangeAlertType;
    /** 심각도 */
    severity: 'info' | 'warning' | 'error';
    /** 알림 메시지 */
    message: string;
    /** 상세 설명 */
    description: string;
    /** 관련 핸들러 */
    handlerName: string;
    /** 관련 상태 경로 (있는 경우) */
    statePath?: string;
    /** 관련 데이터소스 (있는 경우) */
    dataSourceId?: string;
    /** 발생 시간 */
    timestamp: number;
    /** 해결 제안 */
    suggestion?: string;
    /** 관련 문서 링크 */
    docLink?: string;
}

/** 변경 알림 유형 */
export type ChangeAlertType =
    | 'no-state-change'           // 상태 변경 없음
    | 'no-datasource-change'      // 데이터소스 갱신 없음
    | 'expected-not-fulfilled'    // 기대한 변경이 발생하지 않음
    | 'object-reference-same'     // 객체 참조가 동일 (변경 감지 실패 가능성)
    | 'early-return-detected'     // early return 감지
    | 'async-timing-issue';       // 비동기 타이밍 이슈 의심

/** 변경 감지 정보 (상태 덤프용) */
export interface ChangeDetectionInfo {
    /** 핸들러 실행 상세 목록 */
    executionDetails: HandlerExecutionDetail[];
    /** 상태 변경 이력 */
    stateChangeHistory: StateChangeRecord[];
    /** 데이터소스 변경 이력 */
    dataSourceChangeHistory: DataSourceChangeRecord[];
    /** 알림 목록 */
    alerts: ChangeAlert[];
    /** 통계 */
    stats: ChangeDetectionStats;
    /** 추적 시간 */
    timestamp: number;
}

/** 변경 감지 통계 */
export interface ChangeDetectionStats {
    /** 총 핸들러 실행 수 */
    totalExecutions: number;
    /** 상태 변경이 발생한 실행 수 */
    executionsWithStateChange: number;
    /** 상태 변경이 없었던 실행 수 */
    executionsWithoutStateChange: number;
    /** early return으로 종료된 실행 수 */
    earlyReturnCount: number;
    /** 알림 수 */
    alertCount: number;
    /** 알림 유형별 개수 */
    alertsByType: Record<ChangeAlertType, number>;
}

// ============================================
// Sequence 실행 추적 타입 (g7-sequence)
// ============================================

/** Sequence 내 개별 액션 실행 정보 */
export interface SequenceActionInfo {
    /** 액션 인덱스 (0부터 시작) */
    index: number;
    /** 핸들러 이름 */
    handler: string;
    /** 액션 파라미터 (원본) */
    params: Record<string, any>;
    /** 바인딩이 해석된 파라미터 */
    resolvedParams?: Record<string, any>;
    /** 액션 실행 전 상태 */
    stateBeforeAction: {
        _global: Record<string, any>;
        _local: Record<string, any>;
        _isolated?: Record<string, any>;
    };
    /** 액션 실행 후 상태 */
    stateAfterAction: {
        _global: Record<string, any>;
        _local: Record<string, any>;
        _isolated?: Record<string, any>;
    };
    /** 상태 변경 diff */
    stateDiff?: {
        global?: StateDiff;
        local?: StateDiff;
        isolated?: StateDiff;
    };
    /** pending 상태 (__g7PendingLocalState) */
    pendingState?: Record<string, any>;
    /** 액션 실행 소요 시간 (ms) */
    duration: number;
    /** 액션 실행 결과 */
    status: 'success' | 'error';
    /** 에러 정보 (실패 시) */
    error?: SerializedError;
    /** 액션 반환값 */
    result?: any;
}

/** Sequence 실행 정보 */
export interface SequenceExecutionInfo {
    /** Sequence 고유 ID */
    sequenceId: string;
    /** Sequence 시작 시간 */
    startTime: number;
    /** Sequence 종료 시간 */
    endTime?: number;
    /** 총 소요 시간 (ms) */
    totalDuration: number;
    /** 트리거 정보 (어디서 sequence가 호출되었는지) */
    trigger?: {
        /** 트리거 이벤트 타입 (click, submit 등) */
        eventType?: string;
        /** 트리거 소스 (컴포넌트 ID 등) */
        source?: string;
        /** onSuccess/onError에서 호출된 경우 */
        callbackType?: 'onSuccess' | 'onError';
    };
    /** Sequence 내 액션 목록 */
    actions: SequenceActionInfo[];
    /** Sequence 전체 실행 상태 */
    status: 'running' | 'success' | 'error';
    /** 실패한 액션 인덱스 (에러 발생 시) */
    failedAtIndex?: number;
    /** 전체 에러 정보 */
    error?: SerializedError;
}

/** Sequence 추적 정보 (상태 덤프용) */
export interface SequenceTrackingInfo {
    /** 실행된 Sequence 이력 */
    executions: SequenceExecutionInfo[];
    /** 현재 실행 중인 Sequence (있는 경우) */
    currentExecution?: SequenceExecutionInfo;
    /** 통계 */
    stats: SequenceStats;
}

/** Sequence 통계 */
export interface SequenceStats {
    /** 총 Sequence 실행 횟수 */
    totalExecutions: number;
    /** 성공한 Sequence 수 */
    successCount: number;
    /** 실패한 Sequence 수 */
    errorCount: number;
    /** 총 액션 실행 횟수 */
    totalActions: number;
    /** 평균 Sequence 소요 시간 (ms) */
    avgDuration: number;
    /** 가장 많이 사용된 핸들러 TOP 5 */
    topHandlers: Array<{ handler: string; count: number }>;
}

// ============================================
// Stale Closure 감지 타입 (g7-stale-closure)
// ============================================

/** Stale Closure 경고 유형 */
export type StaleClosureWarningType =
    | 'async-state-capture'        // await 후 캡처된 상태 사용
    | 'callback-state-capture'     // 콜백에서 오래된 상태 사용
    | 'timeout-state-capture'      // setTimeout/setInterval에서 상태 캡처
    | 'sequence-state-mismatch'    // sequence 내 상태 불일치
    | 'event-handler-stale';       // 이벤트 핸들러에서 stale 상태

/** Stale Closure 경고 */
export interface StaleClosureWarning {
    /** 경고 고유 ID */
    id: string;
    /** 발생 시간 */
    timestamp: number;
    /** 경고 유형 */
    type: StaleClosureWarningType;
    /** 발생 위치 (핸들러명 또는 컴포넌트) */
    location: string;
    /** 캡처된 상태 정보 */
    capturedState: {
        /** 상태 경로 */
        path: string;
        /** 캡처된 값 */
        capturedValue: any;
        /** 캡처 시간 */
        capturedAt: number;
    };
    /** 현재 상태 정보 */
    currentState: {
        /** 상태 경로 */
        path: string;
        /** 현재 값 */
        currentValue: any;
        /** 조회 시간 */
        retrievedAt: number;
    };
    /** 캡처 시점과 현재 시점 간 시간 차이 (ms) */
    timeDiff: number;
    /** 심각도 */
    severity: 'info' | 'warning' | 'error';
    /** 문제 설명 */
    description: string;
    /** 해결 제안 */
    suggestion: string;
    /** 관련 문서 링크 */
    docLink?: string;
    /** 관련 액션 ID */
    actionId?: string;
    /** 스택 트레이스 (있는 경우) */
    stackTrace?: string;
}

/** Stale Closure 추적 정보 */
export interface StaleClosureTrackingInfo {
    /** 경고 목록 */
    warnings: StaleClosureWarning[];
    /** 통계 */
    stats: StaleClosureStats;
    /** 추적 시간 */
    timestamp: number;
}

/** Stale Closure 통계 */
export interface StaleClosureStats {
    /** 총 경고 수 */
    totalWarnings: number;
    /** 유형별 경고 수 */
    warningsByType: Record<StaleClosureWarningType, number>;
    /** 심각도별 경고 수 */
    warningsBySeverity: Record<string, number>;
    /** 가장 많이 발생한 위치 TOP 5 */
    topLocations: Array<{ location: string; count: number }>;
    /** 가장 많이 영향받은 상태 경로 TOP 5 */
    topAffectedPaths: Array<{ path: string; count: number }>;
}

// ============================================
// Named Actions 추적 타입
// ============================================

/** actionRef 해석 로그 */
export interface NamedActionRefLog {
    /** 고유 ID */
    id: string;
    /** 참조한 named action 이름 */
    actionRefName: string;
    /** 해석된 핸들러명 */
    resolvedHandler: string;
    /** 해석 시각 */
    timestamp: number;
}

/** Named Actions 추적 정보 */
export interface NamedActionTrackingInfo {
    /** 현재 레이아웃의 named_actions 정의 */
    definitions: Record<string, any>;
    /** actionRef 해석 이력 */
    refLogs: NamedActionRefLog[];
    /** 통계 */
    stats: NamedActionStats;
    /** 추적 시간 */
    timestamp: number;
}

/** Named Actions 통계 */
export interface NamedActionStats {
    /** 총 정의 수 */
    totalDefinitions: number;
    /** 총 참조 횟수 */
    totalRefs: number;
    /** 각 named action별 참조 횟수 */
    refCountByName: Record<string, number>;
    /** 미사용 정의 목록 */
    unusedDefinitions: string[];
}
