import type { TranslationContext } from './TranslationEngine';
import { TemplateEngineError } from './TemplateEngineError';
import { createLogger } from '../utils/Logger';
import { primaryLanguage } from '../support/localeTag';

const logger = createLogger('ErrorDisplay');

/**
 * 오류 화면 기본 문구 — 번역 파일을 받지 못한 경우(그것이 오류 원인일 수도 있다)에도 화면 언어를 따르도록
 * 엔진에 내장한다. 번역 키가 풀리면(`G7Core.t`) 그 값을 먼저 쓴다(engine-v1.78.5).
 * 종전에는 제목 「페이지 로딩 실패」·버튼 「새로고침」 이 영어 화면에서도 한국어로 박혀 있었다(7.1.0 실측).
 */
const BUILTIN_TEXT: Record<string, Record<'ko' | 'en' | 'ja', string>> = {
    'core.errors.page_load_failed': { ko: '페이지 로딩 실패', en: 'Page failed to load', ja: 'ページの読み込みに失敗しました' },
    'core.errors.init_failed': { ko: '초기화 실패', en: 'Initialization failed', ja: '初期化に失敗しました' },
    'core.errors.reload': { ko: '새로고침', en: 'Reload', ja: '再読み込み' },
    // 인증 확인을 다시 묻는 동안의 확인 중 표시 — 번역 파일을 받기 전(부팅 중)에도 보여야 한다 (engine-v1.95.0, 설계 확정 2026-11-08)
    'core.errors.auth_checking': {
        ko: '로그인 상태를 확인하는 중입니다. 잠시만 기다려 주세요.',
        en: 'Checking your sign-in status. Please wait a moment.',
        ja: 'ログイン状態を確認しています。しばらくお待ちください。',
    },
    // 인증 확인 불가 화면(서버 일시 오류) — 번역 파일마저 받지 못하는 순간에도 문구가 보여야 한다 (engine-v1.95.0, #869 N5)
    'core.errors.api_request_failed': {
        ko: '요청을 처리하지 못했습니다. 잠시 뒤 다시 시도해 주세요.',
        en: 'The request could not be processed. Please try again in a moment.',
        ja: 'リクエストを処理できませんでした。しばらくしてからもう一度お試しください。',
    },
    'core.errors.layout_forbidden': {
        ko: '이 화면을 볼 권한이 없습니다.',
        en: 'You do not have permission to view this page.',
        ja: 'この画面を表示する権限がありません。',
    },
    'core.errors.layout_not_found': {
        ko: '요청한 화면을 찾을 수 없습니다.',
        en: 'The requested page could not be found.',
        ja: '要求された画面が見つかりません。',
    },
    // 레이아웃 편집기 번들(layout-editor.min.js) 실패 화면 — 편집기 화면 자체가 뜨지 않은 상태라
    // 번역 파일보다 내장 문구가 먼저 필요하다(engine-v1.78.6, 7.1.0 실측).
    'core.errors.layout_editor_load_failed': {
        ko: '레이아웃 편집기 로드 실패',
        en: 'Layout editor failed to load',
        ja: 'レイアウトエディターの読み込みに失敗しました',
    },
    'core.errors.layout_editor_bundle_failed': {
        ko: '편집기를 불러오지 못했습니다. 네트워크 상태를 확인한 뒤 다시 시도해 주세요.',
        en: 'The editor could not be loaded. Check your network connection and try again.',
        ja: 'エディターを読み込めませんでした。ネットワーク状態を確認してから、もう一度お試しください。',
    },
    'core.errors.layout_editor_init_failed': {
        ko: '편집기를 불러왔으나 초기화하지 못했습니다. 페이지를 새로고침해 주세요.',
        en: 'The editor was loaded but could not start. Please reload the page.',
        ja: 'エディターを読み込みましたが、初期化できませんでした。ページを再読み込みしてください。',
    },
};

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
 * 주의: 이 컴포넌트는 템플릿 엔진 초기화 실패 시에도 사용되므로 템플릿 CSS(Tailwind 클래스)에 기대지 않는다.
 * 모양은 셸이 항상 싣는 코어 정적 CSS(`public/assets/core/css/g7-core.css`)의 `g7-error*` 클래스로 준다.
 * HTML 문자열의 style 속성은 엄격한 스타일 정책에서 막혀 무스타일 화면이 된다(#163, engine-v1.91.0).
 * 다크 모드는 CSS 미디어 쿼리(`prefers-color-scheme`)가 맡는다.
 */
export class ErrorDisplay {
    /**
     * 오류 화면 문구를 화면 언어로 고릅니다.
     *
     * 번역 엔진(`G7Core.t`)이 키를 풀면 그 값을, 아니면 내장 문구를 화면 언어(`<html lang>`)로 고릅니다.
     *
     * @param key 번역 키 (`core.errors.*`)
     * @param locale 화면 언어 (생략 시 `<html lang>`)
     * @returns 표시 문구
     * @since engine-v1.78.5
     */
    static text(key: string, locale?: string): string {
        try {
            const translated = (window as any).G7Core?.t?.(key);
            if (typeof translated === 'string' && translated !== '' && translated !== key) {
                return translated;
            }
        } catch {
            // 번역 엔진이 아직 준비되지 않았으면 내장 문구로 내려간다
        }
        // 앞 두 글자가 아니라 주 언어 하위 태그로 비교한다 — kok(콘칸어)가 ko 로 잡히지 않게 (engine-v1.81.0)
        const lang = primaryLanguage(locale || document.documentElement.lang || 'ko') as 'ko' | 'en' | 'ja';
        const builtin = BUILTIN_TEXT[key];
        if (!builtin) return key;

        return builtin[lang] ?? builtin.en;
    }

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

        container.innerHTML = `
            <div class="g7-error">
                <div class="g7-error__card">
                    <!-- 아이콘 -->
                    <div class="g7-error__icon-wrap">
                        <i class="fa-solid ${options.icon} g7-error__icon" aria-hidden="true"></i>
                    </div>

                    <!-- 제목 -->
                    <h1 class="g7-error__title">
                        ${this.escapeHtml(options.title)}
                    </h1>

                    <!-- 사용자 메시지 -->
                    <p class="g7-error__message">
                        ${this.escapeHtml(options.message)}
                    </p>

                    <!-- 상세 메시지 (API 응답 등) -->
                    ${
                        options.detailMessage
                            ? `
                        <p class="g7-error__detail">
                            ${this.escapeHtml(options.detailMessage)}
                        </p>
                    `
                            : `<div class="g7-error__spacer"></div>`
                    }

                    <!-- Stack Trace (디버그 모드일 때만) -->
                    ${
                        shouldShowStack
                            ? `
                        <div class="g7-error__stack">
                            <p class="g7-error__stack-title">
                                Stack Trace:
                            </p>
                            <pre class="g7-error__stack-trace">${this.escapeHtml(
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
                        <div class="g7-error__actions">
                            <button
                                type="button"
                                data-g7-error-reload="1"
                                class="g7-error__button"
                            >
                                <i class="fa-solid fa-rotate-right g7-error__button-icon" aria-hidden="true"></i>
                                ${this.escapeHtml(this.text('core.errors.reload'))}
                            </button>
                        </div>
                    `
                            : ''
                    }
                </div>
            </div>
        `;

        // 새로고침 버튼은 인라인 on* 속성 없이 리스너로 묶는다 — strict CSP(`script-src 'self'`)
        // 에서는 인라인 핸들러가 실행되지 않아 오류 화면의 유일한 탈출구가 무반응이 된다(#158).
        const reloadButton = container.querySelector<HTMLButtonElement>('[data-g7-error-reload]');
        if (reloadButton) {
            reloadButton.addEventListener('click', () => window.location.reload());
            reloadButton.addEventListener('mouseenter', () => {
                reloadButton.style.backgroundColor = '#1d4ed8';
            });
            reloadButton.addEventListener('mouseleave', () => {
                reloadButton.style.backgroundColor = '#2563eb';
            });
        }
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