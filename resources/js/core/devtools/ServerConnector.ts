/**
 * G7 DevTools 서버 커넥터
 *
 * 디버깅 데이터를 서버로 전송하고 파일로 저장하는 기능
 *
 * @module ServerConnector
 */

import type { ServerResponse, SerializablePerformanceInfo } from './types';
import { G7DevToolsCore } from './G7DevToolsCore';

/**
 * 서버 커넥터 설정
 */
interface ServerConnectorConfig {
    /** 상태 덤프 엔드포인트 */
    dumpEndpoint: string;
    /** 로그 전송 엔드포인트 */
    logEndpoint: string;
    /** 연결 타임아웃 (ms) */
    timeout: number;
    /** 자동 덤프 활성화 */
    autoCapture: boolean;
    /** 자동 덤프 간격 (ms) */
    autoCaptureInterval: number;
}

/**
 * 기본 설정
 */
const DEFAULT_CONFIG: ServerConnectorConfig = {
    dumpEndpoint: '/_boost/g7-debug/dump-state',
    logEndpoint: '/_boost/g7-debug/log',
    timeout: 10000,
    autoCapture: false,
    autoCaptureInterval: 30000,
};

/**
 * 서버 커넥터 클래스
 *
 * 디버깅 데이터를 서버로 전송하여 파일로 저장하거나
 * MCP를 통해 Claude가 접근할 수 있게 합니다.
 */
export class ServerConnector {
    private config: ServerConnectorConfig;
    private devTools: G7DevToolsCore;
    private connected: boolean = false;
    private autoCaptureTimer: ReturnType<typeof setInterval> | null = null;

    constructor(config?: Partial<ServerConnectorConfig>) {
        this.config = { ...DEFAULT_CONFIG, ...config };
        this.devTools = G7DevToolsCore.getInstance();

        // 자동 캡처 설정
        if (this.config.autoCapture) {
            this.startAutoCapture();
        }
    }

    /** 청크 크기 (2MB - 서버 메모리 제한 고려) */
    private readonly CHUNK_SIZE = 2 * 1024 * 1024;

    /** 일괄 전송 임계값 (4MB - 이하면 일괄 전송, 초과하면 분할 전송) */
    private readonly BULK_THRESHOLD = 4 * 1024 * 1024;

    /**
     * 현재 상태를 서버로 덤프
     *
     * 데이터 크기에 따라 전송 방식 자동 선택:
     * - 4MB 이하: 일괄 전송 (단일 요청)
     * - 4MB 초과: 섹션별 청크 분할 전송
     *
     * @param saveHistory 이력 파일도 저장할지 여부
     * @returns 세션 ID
     */
    async dumpState(saveHistory: boolean = false): Promise<string> {
        const sessionId = `${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;
        const timestamp = Date.now();

        // PerformanceInfo의 Map을 객체로 변환
        const perfInfo = this.devTools.getPerformanceInfo();
        const serializablePerf: SerializablePerformanceInfo = {
            renderCounts: Object.fromEntries(perfInfo.renderCounts),
            bindingEvalCount: perfInfo.bindingEvalCount,
            memoryWarnings: perfInfo.memoryWarnings,
        };

        // 섹션별 데이터 정의
        const sectionGetters: Record<string, () => any> = {
            state: () => this.devTools.getState(),
            actions: () => this.devTools.getActionHistory(),
            cache: () => this.devTools.getCacheStats(),
            lifecycle: () => this.devTools.getLifecycleInfo(),
            network: () => this.devTools.getNetworkInfo(),
            expressions: () => this.devTools.getExpressions(),
            forms: () => this.devTools.getForms(),
            performance: () => serializablePerf,
            conditionals: () => this.devTools.getConditionalInfo(),
            dataSources: () => this.devTools.getDataSources(),
            handlers: () => this.devTools.getHandlers(),
            componentEvents: () => this.devTools.getComponentEventInfo(),
            stateRendering: () => this.devTools.getStateRenderingInfo(),
            stateHierarchy: () => this.devTools.getStateHierarchyInfo(),
            contextFlow: () => this.devTools.getContextFlowInfo(),
            styleValidation: () => this.devTools.getStyleValidationInfo(),
            authDebug: () => this.devTools.getAuthDebugInfo(),
            logs: () => this.devTools.getLogInfo(),
            layout: () => this.devTools.getLayoutDebugInfo(),
            changeDetection: () => this.devTools.getChangeDetectionInfo(),
            sequenceTracking: () => this.devTools.getSequenceTrackingInfo(),
            staleClosureTracking: () => this.devTools.getStaleClosureTrackingInfo(),
            cacheDecisionTracking: () => this.devTools.getCacheDecisionTrackingInfo(),
            dataPathTransformTracking: () => this.devTools.getDataPathTransformTrackingInfo(),
            nestedContextTracking: () => this.devTools.getNestedContextTrackingInfo(),
            formBindingValidationTracking: () => this.devTools.getFormBindingValidationTrackingInfo(),
            computedDependencyTracking: () => this.devTools.getComputedDependencyTrackingInfo(),
            modalStateScopeTracking: () => this.devTools.getModalStateScopeTrackingInfo(),
            namedActionTracking: () => this.devTools.getNamedActionTrackingInfo(),
        };

        // 모든 섹션 데이터 수집 및 직렬화
        const serializedSections: Record<string, string> = {};
        const errors: string[] = [];
        let totalSize = 0;

        for (const [sectionName, getData] of Object.entries(sectionGetters)) {
            try {
                const data = getData();
                const serialized = this.safeStringify(data, sectionName);

                if (serialized === null) {
                    errors.push(`${sectionName}: 직렬화 실패`);
                    serializedSections[sectionName] = JSON.stringify({ _error: `${sectionName} 섹션 직렬화 실패` });
                } else {
                    serializedSections[sectionName] = serialized;
                }
                totalSize += serializedSections[sectionName].length;
            } catch (e) {
                const errorMsg = e instanceof Error ? e.message : String(e);
                errors.push(`${sectionName}: ${errorMsg}`);
                serializedSections[sectionName] = JSON.stringify({ _error: `${sectionName} 섹션 수집 실패: ${errorMsg}` });
                totalSize += serializedSections[sectionName].length;
            }
        }

        const totalSections = Object.keys(serializedSections).length;
        const successCount = totalSections - errors.length;

        try {
            // 데이터 크기에 따라 전송 방식 선택
            if (totalSize <= this.BULK_THRESHOLD) {
                // 일괄 전송 (기존 레거시 방식)
                await this.sendBulkDump(serializedSections, timestamp, saveHistory);
                console.log(`[G7DevTools] 상태 덤프 완료 - 일괄 전송 (${(totalSize / 1024).toFixed(1)}KB, ${successCount}/${totalSections} 섹션 성공)`);
            } else {
                // 분할 전송 (대용량 데이터)
                await this.sendChunkedDump(sessionId, serializedSections, timestamp, saveHistory);
                console.log(`[G7DevTools] 상태 덤프 완료 - 분할 전송 (${(totalSize / 1024 / 1024).toFixed(2)}MB, ${successCount}/${totalSections} 섹션 성공)`);
            }

            this.connected = true;

            if (errors.length > 0) {
                console.warn('[G7DevTools] 일부 섹션 실패:', errors.join(', '));
            }

            return sessionId;
        } catch (error) {
            this.connected = false;
            console.error('[G7DevTools] 상태 덤프 실패:', error);
            throw error;
        }
    }

    /**
     * 일괄 전송 (소용량 데이터용)
     */
    private async sendBulkDump(
        serializedSections: Record<string, string>,
        timestamp: number,
        saveHistory: boolean
    ): Promise<void> {
        // 각 섹션을 JSON 파싱하여 객체로 변환
        const sectionsData: Record<string, any> = {};
        for (const [name, serialized] of Object.entries(serializedSections)) {
            try {
                sectionsData[name] = JSON.parse(serialized);
            } catch {
                sectionsData[name] = { _error: '파싱 실패' };
            }
        }

        await this.sendRequest(this.config.dumpEndpoint, {
            bulk: true,
            sections: sectionsData,
            timestamp,
            saveHistory,
        });
    }

    /**
     * 분할 전송 (대용량 데이터용)
     */
    private async sendChunkedDump(
        sessionId: string,
        serializedSections: Record<string, string>,
        timestamp: number,
        saveHistory: boolean
    ): Promise<void> {
        const sectionNames = Object.keys(serializedSections);
        const totalSections = sectionNames.length;

        for (let i = 0; i < sectionNames.length; i++) {
            const sectionName = sectionNames[i];
            const serializedData = serializedSections[sectionName];
            const isLastSection = i === sectionNames.length - 1;

            await this.sendChunkedSection(
                sessionId, sectionName, serializedData,
                isLastSection, timestamp, saveHistory, totalSections
            );
        }
    }

    /**
     * 섹션을 청크로 분할하여 전송
     */
    private async sendChunkedSection(
        sessionId: string,
        sectionName: string,
        serializedData: string,
        isLastSection: boolean,
        timestamp: number,
        saveHistory: boolean,
        totalSections: number
    ): Promise<void> {
        const chunks = this.splitIntoChunks(serializedData);
        const totalChunks = chunks.length;

        for (let chunkIndex = 0; chunkIndex < chunks.length; chunkIndex++) {
            const isLastChunk = chunkIndex === chunks.length - 1;
            const chunkData = chunks[chunkIndex];

            await this.sendRequest(this.config.dumpEndpoint, {
                sessionId,
                sectionName,
                chunkData,
                chunkIndex,
                totalChunks,
                isLastChunk,
                isLastSection: isLastSection && isLastChunk,
                timestamp,
                saveHistory,
                totalSections,
            });
        }
    }

    /**
     * 문자열을 청크로 분할
     */
    private splitIntoChunks(data: string): string[] {
        const chunks: string[] = [];
        for (let i = 0; i < data.length; i += this.CHUNK_SIZE) {
            chunks.push(data.slice(i, i + this.CHUNK_SIZE));
        }
        return chunks.length > 0 ? chunks : [''];
    }

    /**
     * 안전한 JSON 직렬화 (순환 참조 처리)
     * @returns 직렬화된 문자열 또는 실패 시 null
     */
    private safeStringify(data: any, sectionName: string): string | null {
        const seen = new WeakSet();

        try {
            const result = JSON.stringify(data, (_key, value) => {
                // 순환 참조 감지
                if (typeof value === 'object' && value !== null) {
                    if (seen.has(value)) {
                        return '[Circular Reference]';
                    }
                    seen.add(value);
                }
                // 함수, Symbol 등 직렬화 불가능한 타입 처리
                if (typeof value === 'function') {
                    return '[Function]';
                }
                if (typeof value === 'symbol') {
                    return '[Symbol]';
                }
                if (typeof value === 'bigint') {
                    return value.toString();
                }
                return value;
            });

            return result;
        } catch (e) {
            console.warn(`[G7DevTools] ${sectionName} 직렬화 오류:`, e);
            return null;
        }
    }

    /**
     * 디버그 로그를 서버로 전송
     *
     * @param data 로그 데이터
     */
    async sendLog(data: any): Promise<void> {
        // 콘솔에 특별한 형식으로 출력 (BrowserLogger가 캡처할 수 있도록)
        console.log('[G7:DEBUG]', JSON.stringify(data));

        // 서버로도 전송 시도
        try {
            await this.sendRequest(this.config.logEndpoint, {
                type: 'debug',
                data,
                timestamp: Date.now(),
            });
        } catch {
            // 로그 전송 실패는 무시
        }
    }

    /**
     * 에러 정보를 서버로 전송
     *
     * @param error 에러 객체
     * @param context 추가 컨텍스트
     */
    async sendError(error: Error, context?: Record<string, any>): Promise<void> {
        const errorData = {
            name: error.name,
            message: error.message,
            stack: error.stack,
            context,
            state: this.devTools.getState(),
            actions: this.devTools.getActionHistory().slice(-10),
            timestamp: Date.now(),
        };

        console.log('[G7:ERROR]', JSON.stringify(errorData));

        try {
            await this.sendRequest(this.config.logEndpoint, {
                type: 'error',
                data: errorData,
                timestamp: Date.now(),
            });
        } catch {
            // 에러 전송 실패는 무시
        }
    }

    /**
     * 성능 프로파일 결과를 서버로 전송
     *
     * @param profile 프로파일 데이터
     */
    async sendProfile(profile: any): Promise<void> {
        console.log('[G7:PROFILE]', JSON.stringify(profile));

        try {
            await this.sendRequest(this.config.logEndpoint, {
                type: 'profile',
                data: profile,
                timestamp: Date.now(),
            });
        } catch {
            // 전송 실패는 무시
        }
    }

    /**
     * 연결 상태 확인
     */
    isConnected(): boolean {
        return this.connected;
    }

    /**
     * 연결 테스트
     */
    async testConnection(): Promise<boolean> {
        try {
            const response = await this.sendRequest(this.config.dumpEndpoint, {
                test: true,
                timestamp: Date.now(),
            });
            this.connected = response.status === 'success';
            return this.connected;
        } catch {
            this.connected = false;
            return false;
        }
    }

    /**
     * 자동 캡처 시작
     */
    startAutoCapture(): void {
        if (this.autoCaptureTimer) {
            this.stopAutoCapture();
        }

        this.autoCaptureTimer = setInterval(() => {
            if (this.devTools.isEnabled()) {
                this.dumpState().catch(() => {
                    // 자동 캡처 실패는 무시
                });
            }
        }, this.config.autoCaptureInterval);

        console.log('[G7DevTools] 자동 캡처 시작 (간격:', this.config.autoCaptureInterval, 'ms)');
    }

    /**
     * 자동 캡처 중지
     */
    stopAutoCapture(): void {
        if (this.autoCaptureTimer) {
            clearInterval(this.autoCaptureTimer);
            this.autoCaptureTimer = null;
            console.log('[G7DevTools] 자동 캡처 중지');
        }
    }

    /**
     * 설정 업데이트
     */
    updateConfig(config: Partial<ServerConnectorConfig>): void {
        this.config = { ...this.config, ...config };

        // 자동 캡처 설정이 변경된 경우 재시작
        if ('autoCapture' in config || 'autoCaptureInterval' in config) {
            this.stopAutoCapture();
            if (this.config.autoCapture) {
                this.startAutoCapture();
            }
        }
    }

    /**
     * HTTP 요청 전송
     */
    private async sendRequest(endpoint: string, data: any): Promise<ServerResponse> {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), this.config.timeout);

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(data),
                signal: controller.signal,
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            // 응답 텍스트를 먼저 확인
            const text = await response.text();

            // HTML 응답인지 확인 (PHP 오류 등)
            if (text.trim().startsWith('<') || text.includes('<br />') || text.includes('<b>')) {
                const errorMessage = this.extractErrorFromHtml(text);
                throw new Error(`서버 오류: ${errorMessage}`);
            }

            // JSON 파싱 시도
            try {
                return JSON.parse(text);
            } catch {
                throw new Error(`잘못된 JSON 응답: ${text.substring(0, 100)}...`);
            }
        } catch (error) {
            clearTimeout(timeoutId);

            if (error instanceof Error && error.name === 'AbortError') {
                throw new Error('요청 타임아웃');
            }

            throw error;
        }
    }

    /**
     * HTML 응답에서 에러 메시지 추출
     */
    private extractErrorFromHtml(html: string): string {
        // PHP 에러 메시지 패턴 추출
        // 예: <b>Fatal error</b>: ... in <b>/path/to/file.php</b> on line <b>123</b>
        const patterns = [
            // Fatal error, Warning, Notice 등
            /<b>(Fatal error|Warning|Notice|Parse error)<\/b>:\s*([^<]+)/i,
            // Exception 메시지
            /Exception:\s*([^<\n]+)/i,
            // 일반 에러 메시지
            /Error:\s*([^<\n]+)/i,
            // TypeError, ArgumentCountError 등
            /(TypeError|ArgumentCountError|ValueError)[^:]*:\s*([^<\n]+)/i,
        ];

        for (const pattern of patterns) {
            const match = html.match(pattern);
            if (match) {
                // 마지막 캡처 그룹 반환 (에러 메시지 부분)
                const message = match[match.length - 1] || match[1];
                // HTML 엔티티 디코딩 및 정리
                return message
                    .replace(/&lt;/g, '<')
                    .replace(/&gt;/g, '>')
                    .replace(/&amp;/g, '&')
                    .replace(/&quot;/g, '"')
                    .trim()
                    .substring(0, 200);
            }
        }

        // 패턴 매칭 실패 시 HTML 태그 제거 후 첫 줄 반환
        const plainText = html
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        return plainText.substring(0, 200) || 'Unknown server error';
    }

    /**
     * 리소스 정리
     */
    destroy(): void {
        this.stopAutoCapture();
    }
}

/**
 * ServerConnector 싱글톤 인스턴스
 */
let instance: ServerConnector | null = null;

/**
 * ServerConnector 싱글톤 인스턴스 반환
 */
export function getServerConnector(): ServerConnector {
    if (!instance) {
        instance = new ServerConnector();
    }
    return instance;
}
