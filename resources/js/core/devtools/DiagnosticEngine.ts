/**
 * G7 DevTools 진단 엔진
 *
 * 증상 기반으로 템플릿 엔진 문제를 자동 진단하는 규칙 엔진
 *
 * @module DiagnosticEngine
 */

import type {
    DiagnosticRule,
    DiagnosticCategory,
    DiagnosticContext,
    DiagnosisResult,
    FixSuggestion,
    CommonIssue,
    StateSnapshot,
    ActionLog,
    CacheStats,
    StateView,
    LifecycleInfo,
    PerformanceInfo,
    NetworkInfo,
    ConditionalInfo,
    FormInfo,
    WebSocketInfo,
} from './types';
import { G7DevToolsCore } from './G7DevToolsCore';

/**
 * 진단 규칙 정의
 *
 * 트러블슈팅 가이드의 주요 이슈를 규칙으로 정의
 */
const diagnosticRules: DiagnosticRule[] = [
    // ============================================
    // 상태 관련 문제
    // ============================================
    {
        id: 'stale-closure',
        name: 'Stale Closure',
        category: 'state',
        symptoms: [
            '이전 값이 전송됨',
            '저장 시 오래된 데이터',
            'API 호출 시 첫 값만 전송',
            '클릭할 때마다 같은 값',
        ],
        detector: (ctx) => {
            // 액션 이력에서 동일한 값이 반복되는 패턴 감지
            const recentActions = ctx.actionHistory.slice(-10);
            const apiCalls = recentActions.filter(a => a.type === 'apiCall');
            if (apiCalls.length < 2) return false;

            // 연속된 API 호출에서 동일한 파라미터가 사용되는지 확인
            for (let i = 1; i < apiCalls.length; i++) {
                const prevParams = JSON.stringify(apiCalls[i - 1].params);
                const currParams = JSON.stringify(apiCalls[i].params);
                if (prevParams === currParams && apiCalls[i].params) {
                    return true;
                }
            }
            return false;
        },
        probability: 0.85,
        solution: 'G7Core.state.get()._global 사용하여 최신 상태 참조',
        docLink: 'troubleshooting-state.md#stale-closure',
        codeExample: `
// ❌ 문제: 클로저가 이전 상태 캡처
"body": { "email": "{{_global.email}}" }

// ✅ 해결: 최신 상태 참조
"body": { "email": "{{G7Core.state.get()._global.email}}" }
        `,
    },
    {
        id: 'sequence-merge',
        name: 'Sequence setState 병합 충돌',
        category: 'state',
        symptoms: [
            'sequence 내 setState 일부 누락',
            '여러 setState 중 마지막만 적용',
            '상태 업데이트 순서 문제',
        ],
        detector: (ctx) => {
            const sequenceActions = ctx.actionHistory.filter(a => a.type === 'sequence');
            return sequenceActions.some(seq =>
                seq.children?.filter(c => c.type === 'setState').length! > 1
            );
        },
        probability: 0.75,
        solution: 'currentState 추적 패턴 사용 또는 단일 setState로 병합',
        docLink: 'troubleshooting-state.md#사례3',
        codeExample: `
// ❌ 문제: sequence 내 여러 setState
"actions": [
  { "handler": "setState", "params": { "a": 1 } },
  { "handler": "setState", "params": { "b": 2 } }
]

// ✅ 해결: 단일 setState로 병합
"actions": [
  { "handler": "setState", "params": { "a": 1, "b": 2 } }
]
        `,
    },
    {
        id: 'init-actions-timing',
        name: 'init_actions 타이밍 이슈',
        category: 'timing',
        symptoms: [
            'init_actions 값이 렌더링에 반영 안됨',
            '초기 상태가 undefined',
            '첫 클릭 시 초기값 손실',
        ],
        detector: (ctx) => {
            // 첫 번째 스냅샷이 init_actions이고 이후 상태가 비어있는지 확인
            if (ctx.stateHistory.length === 0) return false;
            const firstSnapshot = ctx.stateHistory[0];
            return firstSnapshot?.source === 'init_actions' &&
                   Object.keys(firstSnapshot.next || {}).length === 0;
        },
        probability: 0.70,
        solution: 'init_actions 후 _global 상태 병합 확인',
        docLink: 'troubleshooting-state.md#init_actions-관련-이슈',
    },
    {
        id: 'datakey-global-init',
        name: 'dataKey="_global.xxx" 초기화 누락',
        category: 'form',
        symptoms: [
            'Form dataKey가 전역 상태에 바인딩 안됨',
            'Input 값이 _global에 저장 안됨',
            'Form 제출 시 빈 값 전송',
        ],
        detector: (ctx) => {
            // Form이 _global.으로 시작하는 dataKey를 가지고 있고
            // init_actions에서 초기화되지 않은 경우
            const formsWithGlobalKey = ctx.form.filter(f =>
                f.dataKey?.startsWith('_global.')
            );
            if (formsWithGlobalKey.length === 0) return false;

            // init_actions 스냅샷이 없으면 문제
            return !ctx.stateHistory.some(s => s.source === 'init_actions');
        },
        probability: 0.95,
        solution: 'init_actions에서 _global 상태 초기화 필수',
        docLink: 'troubleshooting-state.md#datakey-자동-바인딩-이슈',
        codeExample: `
// init_actions에서 초기화 필요
"init_actions": [
  {
    "handler": "setState",
    "params": {
      "target": "global",
      "loginForm": { "email": "", "password": "" }
    }
  }
]
        `,
    },

    // ============================================
    // 캐시 관련 문제
    // ============================================
    {
        id: 'cache-iteration',
        name: '캐시 반복 렌더링 문제',
        category: 'cache',
        symptoms: [
            '모든 행이 같은 값',
            'iteration에서 첫 항목만 표시',
            'DataGrid 데이터 중복',
        ],
        detector: (ctx) => {
            // 캐시 히트율이 매우 높고 iteration이 있는 경우
            const hitRate = ctx.cacheStats.hitRate ?? 0;
            return hitRate > 0.9 && ctx.conditional.iterations.length > 0;
        },
        probability: 0.90,
        solution: 'skipCache: true 추가',
        docLink: 'troubleshooting-cache.md#사례4',
        codeExample: `
// ❌ 문제: 캐시된 값이 모든 행에 적용
"cellChildren": [{ "props": { "value": "{{item.name}}" } }]

// ✅ 해결: 렌더러에서 skipCache 옵션 사용
// 또는 ActionDispatcher에서 skipCache: true로 호출
        `,
    },
    {
        id: 'cache-dynamic-query',
        name: '동적 쿼리 파라미터 캐싱',
        category: 'cache',
        symptoms: [
            '페이지네이션이 동작 안함',
            '필터 변경해도 같은 결과',
            '정렬이 적용 안됨',
        ],
        detector: (ctx) => {
            // 네트워크 요청이 반복되지만 같은 결과를 받는 경우
            const requests = ctx.network.requestHistory.slice(-5);
            if (requests.length < 2) return false;

            // 같은 URL에 대한 요청이 있는지 확인
            const urls = requests.map(r => r.url.split('?')[0]);
            return new Set(urls).size < urls.length;
        },
        probability: 0.85,
        solution: '데이터소스 params에 skipCache: true 추가',
        docLink: 'troubleshooting-cache.md#사례2',
    },

    // ============================================
    // 라이프사이클 문제
    // ============================================
    {
        id: 'orphaned-listener',
        name: '정리되지 않은 이벤트 리스너',
        category: 'lifecycle',
        symptoms: [
            '메모리 사용량 증가',
            '이벤트가 여러 번 실행됨',
            '페이지 이동 후에도 이벤트 발생',
        ],
        detector: (ctx) => {
            return ctx.lifecycle.orphanedListeners.length > 0;
        },
        probability: 0.80,
        solution: 'useEffect cleanup 또는 removeEventListener 추가',
        docLink: 'troubleshooting-components.md#lifecycle',
    },
    {
        id: 'unmount-state-update',
        name: '언마운트 후 상태 업데이트',
        category: 'lifecycle',
        symptoms: [
            'Cannot update state on unmounted component',
            '비동기 작업 완료 후 에러',
            '모달 닫힘 후 에러',
        ],
        detector: (ctx) => {
            return ctx.actionHistory.some(a =>
                a.status === 'error' &&
                a.error?.message?.includes('unmounted')
            );
        },
        probability: 0.90,
        solution: 'AbortController 또는 isMounted 플래그 사용',
        docLink: 'troubleshooting-state.md#unmount-update',
    },

    // ============================================
    // 성능 문제
    // ============================================
    {
        id: 'excessive-rerenders',
        name: '과도한 리렌더링',
        category: 'performance',
        symptoms: [
            'UI가 느림',
            '스크롤 버벅임',
            '입력 지연',
        ],
        detector: (ctx) => {
            // 렌더링 횟수가 50회 이상인 컴포넌트가 있는지
            for (const count of ctx.performance.renderCounts.values()) {
                if (count > 50) return true;
            }
            return false;
        },
        probability: 0.75,
        solution: 'React.memo, useMemo, useCallback 사용',
        docLink: 'troubleshooting-components.md#performance',
    },
    {
        id: 'binding-eval-flood',
        name: '바인딩 평가 과다',
        category: 'performance',
        symptoms: [
            '페이지 로드 느림',
            'CPU 사용량 높음',
        ],
        detector: (ctx) => {
            return ctx.performance.bindingEvalCount > 1000;
        },
        probability: 0.85,
        solution: '불필요한 {{}} 바인딩 제거, 정적 값 사용',
        docLink: 'troubleshooting-cache.md#binding-performance',
    },
    {
        id: 'memory-leak',
        name: '잠재적 메모리 누수',
        category: 'performance',
        symptoms: [
            '시간이 지남에 따라 느려짐',
            '브라우저 탭 메모리 증가',
            '오래 사용 시 크래시',
        ],
        detector: (ctx) => {
            return ctx.performance.memoryWarnings.length > 0;
        },
        probability: 0.70,
        solution: '클린업 함수 확인, WeakMap 사용',
        docLink: 'troubleshooting-state.md#memory-leak',
    },

    // ============================================
    // 네트워크 문제
    // ============================================
    {
        id: 'request-timeout',
        name: 'API 요청 타임아웃',
        category: 'network',
        symptoms: [
            '데이터가 로딩 안됨',
            '무한 로딩 스피너',
            '타임아웃 에러',
        ],
        detector: (ctx) => {
            return ctx.network.requestHistory.some(r =>
                r.status === 'timeout' || (r.duration && r.duration > 30000)
            );
        },
        probability: 0.90,
        solution: '서버 상태 확인, 타임아웃 설정 조정',
        docLink: 'troubleshooting-backend.md#timeout',
    },
    {
        id: 'duplicate-requests',
        name: '중복 API 요청',
        category: 'network',
        symptoms: [
            '같은 요청이 여러 번 실행',
            '네트워크 탭에 동일 요청 반복',
            '불필요한 서버 부하',
        ],
        detector: (ctx) => {
            const history = ctx.network.requestHistory;
            const recent = history.slice(-20);
            const urls = recent.map(r => `${r.method}:${r.url}`);
            return urls.length !== new Set(urls).size;
        },
        probability: 0.85,
        solution: 'debounce 추가, 데이터소스 의존성 확인',
        docLink: 'troubleshooting-cache.md#duplicate-request',
    },
    {
        id: 'datasource-not-loading',
        name: '데이터소스 미로딩',
        category: 'network',
        symptoms: [
            '데이터가 undefined',
            '빈 테이블/목록',
            'API 호출이 안됨',
        ],
        detector: (ctx) => {
            return ctx.network.pendingDataSources.length > 0 &&
                   ctx.network.activeRequests.length === 0;
        },
        probability: 0.80,
        solution: '데이터소스 strategy 확인, 의존성 확인',
        docLink: 'troubleshooting-backend.md#datasource',
    },

    // ============================================
    // 권한/인증 문제
    // ============================================
    {
        id: 'permission-denied',
        name: '레이아웃 권한 거부 (403)',
        category: 'network',
        symptoms: [
            '403 Forbidden',
            '권한이 없습니다',
            '레이아웃 로드 실패',
            '페이지 접근 불가',
            '빈 화면',
        ],
        detector: (ctx) => {
            return ctx.network.requestHistory.some(r => r.statusCode === 403);
        },
        probability: 0.90,
        solution: '사용자 권한 확인 또는 레이아웃 permissions 설정 확인',
        docLink: 'frontend/layout-json.md#permissions',
        codeExample: `
// 레이아웃 JSON의 permissions 필드 확인
{
  "permissions": ["core.products.read"]  // 필요 권한
}

// 권한 없이 공개로 설정하려면:
{
  "permissions": []  // 빈 배열 = 공개 레이아웃
}
        `,
    },
    {
        id: 'auth-required',
        name: '인증 필요 (401)',
        category: 'network',
        symptoms: [
            '401 Unauthorized',
            '로그인 필요',
            '토큰 만료',
            '인증되지 않음',
            'Unauthenticated',
        ],
        detector: (ctx) => {
            return ctx.network.requestHistory.some(r => r.statusCode === 401);
        },
        probability: 0.95,
        solution: '로그인 상태 확인 또는 토큰 갱신 필요',
        docLink: 'frontend/auth-system.md',
    },

    // ============================================
    // 무한 스크롤 문제
    // ============================================
    {
        id: 'infinite-scroll-state-reset',
        name: '무한 스크롤 상태 미초기화',
        category: 'state',
        symptoms: [
            '삭제 후 스크롤 안됨',
            '무한 스크롤이 멈춤',
            'hasMore가 false로 유지',
            '더 이상 데이터 로드 안됨',
        ],
        detector: (ctx) => {
            // infiniteScroll.hasMore가 false이고 refetchDataSource 액션이 있는 경우
            const state = ctx.currentState;
            const hasRefetch = ctx.actionHistory.some(a => a.type === 'refetchDataSource');
            return hasRefetch && state?._global?.infiniteScroll?.hasMore === false;
        },
        probability: 0.85,
        solution: 'refetchDataSource 전에 infiniteScroll 상태 초기화 필요',
        docLink: 'frontend/actions-handlers.md#appendDataSource',
        codeExample: `
// 삭제 성공 시 상태 초기화 후 refetch
"onSuccess": [
  {
    "handler": "setState",
    "params": {
      "target": "global",
      "infiniteScroll": {
        "currentPage": 1,
        "hasMore": true,
        "isLoadingMore": false
      }
    }
  },
  { "handler": "refetchDataSource", "params": { "id": "list" } }
]
        `,
    },
    {
        id: 'scroll-event-not-configured',
        name: '스크롤 이벤트 미설정',
        category: 'lifecycle',
        symptoms: [
            '스크롤해도 데이터 로드 안됨',
            '무한 스크롤 작동 안함',
            '스크롤 이벤트 안잡힘',
        ],
        detector: (ctx) => {
            // scroll 타입 액션이 없고 appendDataSource도 없는 경우
            const hasScrollAction = ctx.actionHistory.some(a => a.type === 'scroll');
            const hasAppendAction = ctx.actionHistory.some(a => a.type === 'appendDataSource');
            // 스크롤 액션과 append 액션 모두 없으면 미설정 가능성
            return !hasScrollAction && !hasAppendAction && ctx.actionHistory.length > 5;
        },
        probability: 0.70,
        solution: 'scroll 이벤트 핸들러 설정 및 컨테이너 overflow 속성 확인',
        docLink: 'frontend/actions.md#scroll-event',
        codeExample: `
// 무한 스크롤 컨테이너 설정 예시
{
  "type": "Div",
  "props": {
    "className": "overflow-y-auto h-full"
  },
  "actions": {
    "scroll": [
      {
        "handler": "appendDataSource",
        "params": {
          "id": "list",
          "dataPath": "data",
          "sourcePath": "{{_global.infiniteScroll.currentPage}}"
        }
      }
    ]
  }
}
        `,
    },
    {
        id: 'append-datasource-wrong-path',
        name: 'appendDataSource 경로 오류',
        category: 'state',
        symptoms: [
            '무한 스크롤 시 데이터 덮어씀',
            '기존 데이터가 사라짐',
            '데이터가 병합 안됨',
        ],
        detector: (ctx) => {
            // appendDataSource 액션 후 데이터가 줄어드는 패턴
            const appendActions = ctx.actionHistory.filter(a => a.type === 'appendDataSource');
            // 연속된 append 후 데이터 수가 줄어들면 문제
            return appendActions.length >= 2 && appendActions.some(a => a.status === 'error');
        },
        probability: 0.75,
        solution: 'dataPath와 sourcePath 설정 확인, 데이터소스 dataPath가 병합 대상인지 확인',
        docLink: 'frontend/actions-handlers.md#appendDataSource',
    },

    // ============================================
    // WebSocket 문제
    // ============================================
    {
        id: 'websocket-disconnected',
        name: 'WebSocket 연결 끊김',
        category: 'websocket',
        symptoms: [
            '실시간 업데이트 안됨',
            '알림이 오지 않음',
        ],
        detector: (ctx) => {
            return ctx.websocket.connectionState === 'disconnected';
        },
        probability: 0.90,
        solution: '네트워크 상태 확인, 재연결 로직 확인',
        docLink: 'troubleshooting-backend.md#websocket',
    },

    // ============================================
    // 조건부 렌더링 문제
    // ============================================
    {
        id: 'if-condition-always-false',
        name: 'if 조건 항상 false',
        category: 'conditional',
        symptoms: [
            '컴포넌트가 표시 안됨',
            '조건부 영역이 비어있음',
        ],
        detector: (ctx) => {
            return ctx.conditional.ifConditions.some(c =>
                c.evaluatedValue === false && c.evaluationCount > 5
            );
        },
        probability: 0.85,
        solution: 'if 조건식 확인, 상태값 확인',
        docLink: 'troubleshooting-state.md#if-condition',
    },
    {
        id: 'iteration-empty',
        name: 'iteration 소스 비어있음',
        category: 'conditional',
        symptoms: [
            '반복 영역이 비어있음',
            '목록이 표시 안됨',
        ],
        detector: (ctx) => {
            return ctx.conditional.iterations.some(i => i.sourceLength === 0);
        },
        probability: 0.80,
        solution: 'iteration source 표현식 확인, 데이터 로딩 확인',
        docLink: 'troubleshooting-guide.md#iteration',
    },
    {
        id: 'iteration-item-var',
        name: 'iteration 변수명 오류',
        category: 'conditional',
        symptoms: [
            '반복 항목 내 바인딩 빈값',
            '올바른 개수는 표시되나 내용 없음',
        ],
        detector: (ctx) => {
            // item 또는 index가 변수명으로 사용되면 문제 가능성
            return ctx.conditional.iterations.some(i =>
                i.itemVar === 'item' || i.indexVar === 'index'
            );
        },
        probability: 0.95,
        solution: 'item_var, index_var 사용 (item, index 아님)',
        docLink: 'troubleshooting-guide.md#iteration-바인딩-문제',
        codeExample: `
// ❌ 잘못된 속성명
"iteration": {
  "source": "{{data}}",
  "item": "file",      // 잘못됨!
  "index": "fileIdx"   // 잘못됨!
}

// ✅ 올바른 속성명
"iteration": {
  "source": "{{data}}",
  "item_var": "file",
  "index_var": "fileIdx"
}
        `,
    },

    // ============================================
    // Form 문제
    // ============================================
    {
        id: 'form-no-datakey',
        name: 'Form dataKey 미설정',
        category: 'form',
        symptoms: [
            'Input 값이 저장 안됨',
            'Form 제출 시 빈 값',
        ],
        detector: (ctx) => {
            return ctx.form.some(f => !f.dataKey);
        },
        probability: 0.90,
        solution: 'Form에 dataKey prop 추가',
        docLink: 'troubleshooting-components.md#form-datakey',
    },
    {
        id: 'input-no-name',
        name: 'Input name 미설정',
        category: 'form',
        symptoms: [
            '자동 바인딩 안됨',
            'Input 값 변경이 상태에 반영 안됨',
        ],
        detector: (ctx) => {
            return ctx.form.some(f =>
                f.inputs.some(i => !i.name)
            );
        },
        probability: 0.95,
        solution: 'Input에 name prop 추가',
        docLink: 'troubleshooting-components.md#input-name',
    },

    // ============================================
    // i18n 문제
    // ============================================
    {
        id: 'i18n-key-missing',
        name: '번역 키 누락',
        category: 'i18n',
        symptoms: [
            '$t:key가 그대로 표시됨',
            '번역되지 않은 텍스트',
        ],
        detector: (_ctx) => {
            // DOM에서 $t: 패턴 검색
            if (typeof document !== 'undefined') {
                return document.body.innerHTML.includes('$t:');
            }
            return false;
        },
        probability: 0.95,
        solution: '번역 파일에 키 추가',
        docLink: 'troubleshooting-components.md#i18n',
    },
];

/**
 * 진단 엔진 클래스
 *
 * 증상을 기반으로 템플릿 엔진 문제를 자동 진단
 */
export class DiagnosticEngine {
    private devTools: G7DevToolsCore;

    constructor() {
        this.devTools = G7DevToolsCore.getInstance();
    }

    /**
     * 증상을 분석하여 진단 결과 반환
     *
     * @param symptoms 사용자가 보고한 증상 목록
     * @returns 진단 결과 (신뢰도 순 정렬)
     */
    analyze(symptoms: string[]): DiagnosisResult[] {
        const context = this.buildContext();
        const matches: DiagnosisResult[] = [];

        for (const rule of diagnosticRules) {
            // 증상 매칭 확인
            const symptomMatch = symptoms.some(s =>
                rule.symptoms.some(rs =>
                    rs.toLowerCase().includes(s.toLowerCase()) ||
                    s.toLowerCase().includes(rs.toLowerCase())
                )
            );

            // 자동 감지 실행
            let detectorMatch = false;
            try {
                detectorMatch = rule.detector(context);
            } catch {
                // 감지기 실행 실패 시 무시
            }

            if (symptomMatch || detectorMatch) {
                matches.push({
                    rule,
                    confidence: this.calculateConfidence(rule, symptoms, symptomMatch, detectorMatch),
                    evidence: this.collectEvidence(rule, context),
                });
            }
        }

        // 신뢰도 순 정렬
        return matches.sort((a, b) => b.confidence - a.confidence);
    }

    /**
     * 진단 결과에 대한 해결 제안 반환
     *
     * @param diagnosis 진단 결과
     * @returns 해결 제안
     */
    suggestFix(diagnosis: DiagnosisResult): FixSuggestion {
        return {
            title: diagnosis.rule.name,
            description: diagnosis.rule.solution,
            codeExample: diagnosis.rule.codeExample,
            docLink: diagnosis.rule.docLink,
        };
    }

    /**
     * 자주 발생하는 이슈 목록 반환
     *
     * @returns 빈도순 정렬된 이슈 목록
     */
    getCommonIssues(): CommonIssue[] {
        // 현재 컨텍스트에서 감지된 이슈 수집
        const context = this.buildContext();
        const detected: { rule: DiagnosticRule; count: number }[] = [];

        for (const rule of diagnosticRules) {
            try {
                if (rule.detector(context)) {
                    detected.push({ rule, count: 1 });
                }
            } catch {
                // 무시
            }
        }

        return detected
            .sort((a, b) => b.count - a.count)
            .map(d => ({
                id: d.rule.id,
                name: d.rule.name,
                description: d.rule.solution,
                frequency: d.count,
            }));
    }

    /**
     * 특정 카테고리의 규칙만 반환
     *
     * @param category 진단 카테고리
     * @returns 해당 카테고리의 규칙 목록
     */
    getRulesByCategory(category: DiagnosticCategory): DiagnosticRule[] {
        return diagnosticRules.filter(r => r.category === category);
    }

    /**
     * 모든 진단 규칙 반환
     */
    getAllRules(): DiagnosticRule[] {
        return [...diagnosticRules];
    }

    /**
     * 진단 컨텍스트 구축
     */
    private buildContext(): DiagnosticContext {
        return {
            stateHistory: this.devTools.getStateHistory(),
            actionHistory: this.devTools.getActionHistory(),
            cacheStats: this.devTools.getCacheStats(),
            currentState: this.devTools.getState(),
            lifecycle: this.devTools.getLifecycleInfo(),
            performance: this.devTools.getPerformanceInfo(),
            network: this.devTools.getNetworkInfo(),
            conditional: this.devTools.getConditionalInfo(),
            form: this.devTools.getFormInfo(),
            websocket: this.devTools.getWebSocketInfo(),
            stateAtTime: (timestamp: number) => this.devTools.getStateAtTime(timestamp),
            layoutHas: (prop: string, value: string) => this.checkLayoutHas(prop, value),
        };
    }

    /**
     * 신뢰도 계산
     */
    private calculateConfidence(
        rule: DiagnosticRule,
        symptoms: string[],
        symptomMatch: boolean,
        detectorMatch: boolean
    ): number {
        let confidence = rule.probability;

        // 증상 매칭 시 신뢰도 증가
        if (symptomMatch) {
            const matchCount = symptoms.filter(s =>
                rule.symptoms.some(rs =>
                    rs.toLowerCase().includes(s.toLowerCase()) ||
                    s.toLowerCase().includes(rs.toLowerCase())
                )
            ).length;
            confidence += matchCount * 0.05;
        }

        // 자동 감지 시 신뢰도 증가
        if (detectorMatch) {
            confidence += 0.1;
        }

        // 최대 0.99로 제한
        return Math.min(confidence, 0.99);
    }

    /**
     * 증거 수집
     */
    private collectEvidence(rule: DiagnosticRule, context: DiagnosticContext): string[] {
        const evidence: string[] = [];

        switch (rule.category) {
            case 'state':
                if (context.stateHistory.length > 0) {
                    evidence.push(`상태 변경 이력: ${context.stateHistory.length}개`);
                }
                break;
            case 'cache':
                evidence.push(`캐시 히트율: ${((context.cacheStats.hitRate ?? 0) * 100).toFixed(1)}%`);
                break;
            case 'lifecycle':
                if (context.lifecycle.orphanedListeners.length > 0) {
                    evidence.push(`정리되지 않은 리스너: ${context.lifecycle.orphanedListeners.length}개`);
                }
                break;
            case 'performance':
                evidence.push(`바인딩 평가 횟수: ${context.performance.bindingEvalCount}`);
                break;
            case 'network':
                evidence.push(`진행 중인 요청: ${context.network.activeRequests.length}개`);
                break;
            case 'form':
                evidence.push(`추적 중인 Form: ${context.form.length}개`);
                break;
        }

        return evidence;
    }

    /**
     * 레이아웃에서 특정 속성/값 존재 확인
     */
    private checkLayoutHas(prop: string, value: string): boolean {
        // 현재 레이아웃 JSON에서 특정 패턴 검색
        // 실제 구현에서는 레이아웃 데이터 접근 필요
        try {
            const g7Core = (window as any).G7Core;
            const layoutData = g7Core?.getCurrentLayout?.();
            if (!layoutData) return false;

            const jsonStr = JSON.stringify(layoutData);
            return jsonStr.includes(`"${prop}":"${value}`) ||
                   jsonStr.includes(`"${prop}": "${value}`);
        } catch {
            return false;
        }
    }
}

/**
 * DiagnosticEngine 싱글톤 인스턴스
 */
let instance: DiagnosticEngine | null = null;

/**
 * DiagnosticEngine 싱글톤 인스턴스 반환
 */
export function getDiagnosticEngine(): DiagnosticEngine {
    if (!instance) {
        instance = new DiagnosticEngine();
    }
    return instance;
}
