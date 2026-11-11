/**
 * sirsoft-ckeditor5 플러그인 엔트리포인트
 *
 * 플러그인 활성화 시 자동으로 로드되어 핸들러를 등록합니다.
 */

// 본문 보정·다국어 탭·편집기 다크 모드·높이·미리보기 스타일 — 빌드가 dist/css/plugin.css 로 뽑아낸다
import '../css/main.css';
import { initEditorHandler } from './handlers/initEditor';
import { destroyEditorHandler } from './handlers/destroyEditor';
import { ckeditorCssUrl } from './handlers/ckeditorAssets';

const PLUGIN_IDENTIFIER = 'sirsoft-ckeditor5';

const CKEDITOR5_CSS_ID = 'ckeditor5-content-styles';

/**
 * CKEditor5 content styles CSS를 동적으로 주입하는 핸들러
 * html-content.g7.html extension_point의 onMount에서 호출됩니다.
 *
 * HtmlContent 컴포넌트에 ck-content 클래스가 적용되어 동봉 CSS가 직접 동작합니다.
 * 다크모드(원본 미지원)와 col 너비 같은 보정 규칙은 여기서 `<style>` 로 싣지 않고 플러그인 CSS 파일
 * (`resources/css/main.css` → `dist/css/plugin.css`)에 둔다 — 엄격한 스타일 정책에서 `<style>` 요소가 막힌다.
 *
 * 이 CSS 는 **작성 화면이 아니라 방문자가 보는 본문**에 걸린다. 실패하면 이미 작성된
 * 글의 표·정렬·목록 서식이 통째로 깨지므로, 종전의 무음 처리(`onerror` 없음)를
 * 재시도 + 실패 표면화로 바꾼다.
 */
function injectContentCssHandler(): void {
    if (!document.getElementById(CKEDITOR5_CSS_ID)) {
        const asset = (window as any)?.G7Core?.asset;

        try {
            const url = ckeditorCssUrl();

            if (typeof asset?.loadStylesheet === 'function') {
                asset.loadStylesheet(url, { id: CKEDITOR5_CSS_ID }, { label: 'ckeditor5 content CSS' })
                    .catch((error: unknown) => {
                        console.warn('[ckeditor5] 본문 스타일 로드 실패:', error);
                        (window as any)?.G7Core?.assets?.notifyFailure?.({
                            id: 'ckeditor5-content-css',
                            label: '본문 스타일',
                            retry: () => injectContentCssHandler(),
                        });
                    });
            } else {
                const link = document.createElement('link');
                link.id = CKEDITOR5_CSS_ID;
                link.rel = 'stylesheet';
                link.href = url;
                document.head.appendChild(link);
            }
        } catch (error) {
            console.warn('[ckeditor5] 본문 스타일 URL 을 만들지 못했습니다:', error);
        }
    }
}

const handlerMap: Record<string, Function> = {
    initEditor: initEditorHandler,
    destroyEditor: destroyEditorHandler,
    injectContentCss: injectContentCssHandler,
};

const logger = ((window as any).G7Core?.createLogger?.(`Plugin:${PLUGIN_IDENTIFIER}`)) ?? {
    log: (...args: unknown[]) => console.log(`[Plugin:${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[Plugin:${PLUGIN_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[Plugin:${PLUGIN_IDENTIFIER}]`, ...args),
};

function registerHandlers(retry: boolean = false): void {
    const actionDispatcher = (window as any).G7Core?.getActionDispatcher?.();

    if (actionDispatcher) {
        Object.entries(handlerMap).forEach(([name, handler]) => {
            const fullName = `${PLUGIN_IDENTIFIER}.${name}`;
            actionDispatcher.registerHandler(fullName, handler, {
                category: 'plugin',
                source: PLUGIN_IDENTIFIER,
            });
        });

        logger.log(
            `${Object.keys(handlerMap).length} handler(s) registered:`,
            Object.keys(handlerMap).map(name => `${PLUGIN_IDENTIFIER}.${name}`)
        );
    } else if (retry) {
        let retryCount = 0;
        const maxRetries = 50;

        const retryRegister = () => {
            const dispatcher = (window as any).G7Core?.getActionDispatcher?.();

            if (dispatcher) {
                Object.entries(handlerMap).forEach(([name, handler]) => {
                    const fullName = `${PLUGIN_IDENTIFIER}.${name}`;
                    dispatcher.registerHandler(fullName, handler, {
                        category: 'plugin',
                        source: PLUGIN_IDENTIFIER,
                    });
                });

                logger.log(
                    `${Object.keys(handlerMap).length} handler(s) registered:`,
                    Object.keys(handlerMap).map(name => `${PLUGIN_IDENTIFIER}.${name}`)
                );
            } else {
                retryCount++;
                if (retryCount <= maxRetries) {
                    logger.warn(`ActionDispatcher not found, retrying... (${retryCount}/${maxRetries})`);
                    setTimeout(retryRegister, 100);
                } else {
                    logger.error(`Failed to register handlers: ActionDispatcher not available after maximum retries`);
                }
            }
        };

        retryRegister();
    } else {
        logger.warn(`ActionDispatcher not found, handlers not registered`);
    }
}

export function initPlugin(): void {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => registerHandlers(true));
    } else {
        const hasDispatcher = !!(window as any).G7Core?.getActionDispatcher?.();
        registerHandlers(hasDispatcher ? false : true);
    }
}

initPlugin();

if (typeof window !== 'undefined') {
    (window as any).__SirsoftCkeditor5 = {
        identifier: PLUGIN_IDENTIFIER,
        handlers: Object.keys(handlerMap),
        initPlugin,
    };
}