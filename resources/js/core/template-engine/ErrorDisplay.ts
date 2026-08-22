import type { TranslationContext } from './TranslationEngine';
import { TemplateEngineError } from './TemplateEngineError';
import { createLogger } from '../utils/Logger';

const logger = createLogger('ErrorDisplay');

/**
 * 에러 표시 옵션
 */
export interface ErrorDisplayOptions {
    /** 에러 제목 */
    title: string;
    /** 사용자 메시지 */
    message: string;
    /** 상세 메시지 (API 응답 메시지 등) */
    detailMessage?: string;
    /** Font Awesome 아이콘 클래스 */
    icon: string;
    /** Stack Trace 표시 여부 */
    showStack: boolean;
    /** Stack Trace 문자열 */
    stackTrace?: string;
    /** 새로고침 버튼 표시 여부 */
    showReloadButton: boolean;
    /** 디버그 모드 여부 */
    debug: boolean;
}

/**
 * 에러 화면 렌더링 컴포넌트
 *
 * 주의: 이 컴포넌트는 템플릿 엔진 초기화 실패 시에도 사용되므로
 * Tailwind CSS 클래스 대신 인라인 스타일을 사용합니다.
 * 초기화 실패 시점에는 CSS가 아직 로드되지 않았을 수 있기 때문입니다.
 */
export class ErrorDisplay {
    /**
     * 에러 화면을 렌더링합니다.
     *
     * @param containerId 컨테이너 요소 ID
     * @param options 에러 표시 옵션
     */
    static render(containerId: string, options: ErrorDisplayOptions): void {
        const container = document.getElementById(containerId);
        if (!container) {
            logger.error(`Container #${containerId} not found`);
            return;
        }

        // 스택 트레이스 표시 여부 결정
        const shouldShowStack =
            options.debug && options.showStack && options.stackTrace;

        // 다크 모드 감지
        const isDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;

        // 인라인 스타일 정의 (CSS 의존성 없이 동작)
        const styles = {
            container: `
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                background-color: ${isDarkMode ? '#111827' : '#f9fafb'};
                padding: 1rem;
                font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            `,
            card: `
                max-width: 28rem;
                width: 100%;
                background-color: ${isDarkMode ? '#1f2937' : '#ffffff'};
                border-radius: 0.5rem;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
                padding: 2rem;
                text-align: center;
            `,
            iconContainer: `
                margin-bottom: 1.5rem;
            `,
            icon: `
                font-size: 4rem;
                color: #ef4444;
            `,
            title: `
                font-size: 1.5rem;
                font-weight: 700;
                color: ${isDarkMode ? '#ffffff' : '#111827'};
                margin-bottom: 1rem;
            `,
            message: `
                color: ${isDarkMode ? '#d1d5db' : '#4b5563'};
                margin-bottom: 0.5rem;
                line-height: 1.625;
            `,
            detailMessage: `
                font-size: 0.875rem;
                color: ${isDarkMode ? '#9ca3af' : '#6b7280'};
                margin-bottom: 1.5rem;
                background-color: ${isDarkMode ? '#374151' : '#f3f4f6'};
                padding: 0.5rem 0.75rem;
                border-radius: 0.25rem;
            `,
            spacer: `
                margin-bottom: 1.5rem;
            `,
            stackContainer: `
                margin-top: 1.5rem;
                padding: 1rem;
                background-color: ${isDarkMode ? '#374151' : '#f3f4f6'};
                border-radius: 0.375rem;
                text-align: left;
            `,
            stackTitle: `
                font-size: 0.875rem;
                font-weight: 600;
                color: ${isDarkMode ? '#e5e7eb' : '#374151'};
                margin-bottom: 0.5rem;
            `,
            stackTrace: `
                font-size: 0.75rem;
                color: ${isDarkMode ? '#d1d5db' : '#4b5563'};
                overflow-x: auto;
                white-space: pre-wrap;
                word-break: break-word;
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            `,
            buttonContainer: `
                margin-top: 1.5rem;
            `,
            button: `
                display: inline-flex;
                align-items: center;
                padding: 0.75rem 1.5rem;
                background-color: #2563eb;
                color: #ffffff;
                font-weight: 500;
                border-radius: 0.375rem;
                border: none;
                cursor: pointer;
                transition: background-color 0.2s;
            `,
        };

        container.innerHTML = `
            <div style="${styles.container}">
                <div style="${styles.card}">
                    <!-- 아이콘 -->
                    <div style="${styles.iconContainer}">
                        <i class="fa-solid ${options.icon}" style="${styles.icon}"></i>
                    </div>

                    <!-- 제목 -->
                    <h1 style="${styles.title}">
                        ${this.escapeHtml(options.title)}
                    </h1>

                    <!-- 사용자 메시지 -->
                    <p style="${styles.message}">
                        ${this.escapeHtml(options.message)}
                    </p>

                    <!-- 상세 메시지 (API 응답 등) -->
                    ${
                        options.detailMessage
                            ? `
                        <p style="${styles.detailMessage}">
                            ${this.escapeHtml(options.detailMessage)}
                        </p>
                    `
                            : `<div style="${styles.spacer}"></div>`
                    }

                    <!-- Stack Trace (디버그 모드일 때만) -->
                    ${
                        shouldShowStack
                            ? `
                        <div style="${styles.stackContainer}">
                            <p style="${styles.stackTitle}">
                                Stack Trace:
                            </p>
                            <pre style="${styles.stackTrace}">${this.escapeHtml(
                                options.stackTrace
                            )}</pre>
                        </div>
                    `
                            : ''
                    }

                    <!-- 새로고침 버튼 (복구 가능할 때만) -->
                    ${
                        options.showReloadButton
                            ? `
                        <div style="${styles.buttonContainer}">
                            <button
                                onclick="window.location.reload()"
                                style="${styles.button}"
                                onmouseover="this.style.backgroundColor='#1d4ed8'"
                                onmouseout="this.style.backgroundColor='#2563eb'"
                            >
                                <i class="fa-solid fa-rotate-right" style="margin-right: 0.5rem;"></i>
                                새로고침
                            </button>
                        </div>
                    `
                            : ''
                    }
                </div>
            </div>
        `;
    }

    /**
     * TemplateEngineError 객체로부터 에러 화면을 렌더링합니다.
     *
     * @param containerId 컨테이너 요소 ID
     * @param error 템플릿 엔진 에러 객체
     * @param title 에러 제목
     * @param debug 디버그 모드 여부
     * @param context 번역 컨텍스트 (선택)
     * @param translationEngine 번역 엔진 (선택)
     * @param originalError 원본 에러 객체 (선택, 상세 메시지 추출용)
     */
    static renderFromError(
        containerId: string,
        error: TemplateEngineError,
        title: string,
        debug: boolean = false,
        context?: TranslationContext,
        translationEngine?: {
            translate: (key: string, context: TranslationContext) => string;
        },
        originalError?: Error
    ): void {
        // 다국어 메시지 조회
        let message: string;
        let detailMessage: string | undefined;

        // userMessageKey가 $t: 로 시작하면 번역 시도
        if (error.userMessageKey.startsWith('$t:')) {
            if (translationEngine && context) {
                try {
                    message = translationEngine.translate(error.userMessageKey, context);
                } catch (e) {
                    // 번역 실패 시 키에서 $t: 제거하여 표시
                    message = error.userMessageKey.replace('$t:', '');
                }
            } else {
                // 번역 엔진이 없으면 키에서 $t: 제거하여 표시
                message = error.userMessageKey.replace('$t:', '');
            }
        } else {
            // 일반 에러 메시지는 그대로 사용
            message = error.userMessageKey;
        }

        // 원본 에러에서 상세 메시지 추출 (LayoutLoaderError 등)
        if (originalError) {
            const anyError = originalError as any;
            // LayoutLoaderError의 details.apiMessage 추출
            if (anyError.details?.apiMessage) {
                detailMessage = anyError.details.apiMessage;
            }
            // Axios 에러의 response.data.message 추출
            else if (anyError.response?.data?.message) {
                detailMessage = anyError.response.data.message;
            }
        }

        this.render(containerId, {
            title,
            message,
            detailMessage,
            icon: error.icon,
            showStack: error.showStack,
            stackTrace: error.stack,
            showReloadButton: error.recoverable,
            debug,
        });
    }

    /**
     * HTML 이스케이프 처리
     *
     * @param text 이스케이프할 텍스트
     */
    private static escapeHtml(text: string | undefined): string {
        if (!text) return '';

        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}