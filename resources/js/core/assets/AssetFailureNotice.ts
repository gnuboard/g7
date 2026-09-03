/**
 * 구동 자산 로드 실패 안내 배너
 *
 * 화면을 그리는 데 필요한 자산(에디터 본체·아이콘 폰트·코드 편집기 등)을 끝내
 * 불러오지 못했을 때, 사용자가 그 사실을 알고 조치할 수 있게 하는 배너다.
 *
 * 종전에는 이 계층이 아예 없었다 — 로드 실패는 `console.warn` 이나 조용한 `resolve()`
 * 로 삼켜져, 사용자에게는 "버튼이 안 눌린다" · "입력창 자리가 비어 있다" 로만 나타났다.
 * 자체 서버 로그에도 흔적이 없어 운영자가 원인을 특정할 수 없었다.
 *
 * 이 모듈은 호스트 컴포넌트에 의존하지 않고 DOM 에 직접 주입한다. Toast·Modal 은
 * 베이스 레이아웃이 마운트한 호스트 컴포넌트가 있어야 화면에 뜨는데, 독립 레이아웃
 * (`extends` 없음 — 예: `admin_login.json`)에는 그 호스트가 없다. 자산 실패는 바로
 * 그런 화면에서도 알려야 한다.
 *
 * Tailwind 클래스가 아니라 인라인 스타일을 쓰는 이유: 이 번들(코어 엔진)은 템플릿의
 * Tailwind 빌드 CSS 와 별개라, 여기서 처음 등장하는 클래스는 어느 템플릿의 CSS 에도
 * 컴파일되어 있지 않다. 자산이 실패한 상황에서 스타일까지 없으면 안내 자체가 안 보인다.
 *
 * @module AssetFailureNotice
 * @since engine-v1.62.0
 */

import { createLogger } from '../utils/Logger';

const logger = createLogger('AssetFailureNotice');

/** 배너 컨테이너 element id */
const CONTAINER_ID = 'g7-asset-failure-notice';

/** 시스템 배너(SystemBannerManager) 컨테이너 id — 겹치지 않게 아래로 내리기 위해 참조 */
const SYSTEM_BANNER_CONTAINER_ID = 'g7-system-banners';

/**
 * 자산 로드 실패 1건
 *
 * @since engine-v1.62.0
 */
export interface AssetFailure {
    /** 중복 누적을 막는 식별자 (같은 id 는 갱신) */
    id: string;
    /** 짧은 항목명 (여러 건 합산 표시에 쓰인다) */
    label: string;
    /** 사용자에게 보일 안내 문장. 생략하면 label 기반 기본 문구 */
    message?: string;
    /**
     * [다시 시도] 동작. 생략하면 버튼을 렌더하지 않는다.
     * resolve 되면 해당 실패를 해제하고, reject 되면 배너를 유지한 채 재시도 실패를 알린다.
     */
    retry?: () => void | Promise<void>;
}

/** 등록된 실패 목록 (id → 실패) */
const failures = new Map<string, AssetFailure>();

/** 재시도 진행 중 여부 */
let retrying = false;

/** 마지막 재시도가 실패했는지 */
let retryFailed = false;

/** documentElement 의 다크 클래스 변화를 감시하는 옵저버 */
let themeObserver: MutationObserver | null = null;

/**
 * 번역 문자열을 얻습니다.
 *
 * 자산 실패는 부팅 초기에도 발생할 수 있어 번역 엔진이 아직 없을 수 있다.
 * 그때는 키 대신 읽을 수 있는 폴백 문구를 쓴다 — 안내 배너에 `core.assets.retry`
 * 같은 키가 그대로 노출되면 안내로서 기능하지 못한다.
 *
 * @param key 번역 키
 * @param fallback 번역 엔진 부재 시 사용할 문구
 * @param params 치환 파라미터
 * @return string 번역된 문자열
 * @since engine-v1.62.0
 */
function t(key: string, fallback: string, params?: Record<string, string | number>): string {
    try {
        const translate = (window as any)?.G7Core?.t;

        if (typeof translate === 'function') {
            const result = translate(key, params);

            // 번역 미적재 시 키 자체가 돌아온다 — 그 경우 폴백을 쓴다
            if (typeof result === 'string' && result !== key && !result.startsWith('core.assets.')) {
                return result;
            }
        }
    } catch {
        // 번역 실패는 안내 표시를 막을 이유가 못 된다
    }

    return substitute(fallback, params);
}

/**
 * 폴백 문구의 자리표시자를 치환합니다.
 *
 * @param text 원본 문구
 * @param params 치환 파라미터
 * @return string 치환된 문구
 * @since engine-v1.62.0
 */
function substitute(text: string, params?: Record<string, string | number>): string {
    if (!params) {
        return text;
    }

    return Object.entries(params).reduce(
        (acc, [key, value]) => acc.split('{' + key + '}').join(String(value)),
        text
    );
}

/**
 * 자산 로드 실패를 알립니다.
 *
 * 같은 `id` 로 다시 호출하면 누적하지 않고 갱신한다.
 *
 * @param failure 실패 정보
 * @return void
 * @since engine-v1.62.0
 */
export function notifyAssetFailure(failure: AssetFailure): void {
    if (!failure || !failure.id) {
        return;
    }

    failures.set(failure.id, failure);
    retryFailed = false;
    logger.warn(`Asset failure notified: ${failure.id} (${failure.label})`);
    render();
}

/**
 * 자산 실패를 해제합니다 (복구되었을 때).
 *
 * @param id 실패 식별자
 * @return void
 * @since engine-v1.62.0
 */
export function clearAssetFailure(id: string): void {
    if (failures.delete(id)) {
        logger.log(`Asset failure cleared: ${id}`);
        render();
    }
}

/**
 * 모든 자산 실패를 해제합니다.
 *
 * @return void
 * @since engine-v1.62.0
 */
export function clearAllAssetFailures(): void {
    if (failures.size === 0) {
        return;
    }

    failures.clear();
    render();
}

/**
 * 현재 등록된 자산 실패 목록을 돌려줍니다.
 *
 * 테스트·E2E 가 "실패가 표면화되었는가" 를 화면 문자열이 아니라 상태로 단언할 수 있게 한다.
 *
 * @return AssetFailure[] 실패 목록 (등록 순서)
 * @since engine-v1.62.0
 */
export function getAssetFailures(): AssetFailure[] {
    return Array.from(failures.values());
}

/**
 * 등록된 실패 중 재시도 가능한 것을 모두 재시도합니다.
 *
 * @return Promise<void>
 * @since engine-v1.62.0
 */
export async function retryAssetFailures(): Promise<void> {
    if (retrying) {
        return;
    }

    retrying = true;
    retryFailed = false;
    render();

    const targets = Array.from(failures.values()).filter(item => typeof item.retry === 'function');

    let anyFailed = false;

    for (const target of targets) {
        try {
            await target.retry!();
            failures.delete(target.id);
        } catch (error) {
            anyFailed = true;
            logger.warn(`Asset retry failed: ${target.id}`, error);
        }
    }

    retrying = false;
    retryFailed = anyFailed;
    render();
}

/**
 * 다크 모드 여부를 판정합니다.
 *
 * @return bool 다크 모드이면 true
 * @since engine-v1.62.0
 */
function isDarkMode(): boolean {
    try {
        return document.documentElement.classList.contains('dark');
    } catch {
        return false;
    }
}

/**
 * 배너를 DOM 에 렌더링합니다.
 *
 * @return void
 * @since engine-v1.62.0
 */
function render(): void {
    if (typeof document === 'undefined' || !document.body) {
        return;
    }

    const existing = document.getElementById(CONTAINER_ID);

    if (failures.size === 0) {
        existing?.remove();
        stopThemeObserver();

        return;
    }

    const container = existing ?? createContainer();

    applyContainerStyle(container);
    container.replaceChildren(buildContent());
    startThemeObserver();
}

/**
 * 배너 컨테이너를 생성해 body 에 붙입니다.
 *
 * @return HTMLElement 컨테이너
 * @since engine-v1.62.0
 */
function createContainer(): HTMLElement {
    const container = document.createElement('div');
    container.id = CONTAINER_ID;
    container.setAttribute('role', 'alert');
    container.setAttribute('aria-live', 'polite');
    document.body.appendChild(container);

    return container;
}

/**
 * 컨테이너 스타일을 적용합니다.
 *
 * 시스템 배너(유지보수·프리뷰)가 떠 있으면 그 아래로 내려 겹치지 않게 한다.
 * 콘텐츠는 밀어내지 않는다 — 자산 실패는 화면 레이아웃을 바꿔야 할 만큼의 사건이 아니고,
 * 밀어내면 폴백 입력창의 위치가 흔들린다.
 *
 * @param container 컨테이너 element
 * @return void
 * @since engine-v1.62.0
 */
function applyContainerStyle(container: HTMLElement): void {
    const systemBanners = document.getElementById(SYSTEM_BANNER_CONTAINER_ID);
    const offset = systemBanners?.offsetHeight ?? 0;

    container.style.cssText = [
        'position:fixed',
        `top:${offset}px`,
        'left:0',
        'right:0',
        'z-index:9998',
        'display:flex',
        'justify-content:center',
        'pointer-events:none',
    ].join(';');
}

/**
 * 배너 본문을 구성합니다.
 *
 * `innerHTML` 을 쓰지 않고 element 를 조립한다 — 문구에 확장이 넘긴 label 이 섞이므로
 * 문자열 조립은 주입 경로가 된다.
 *
 * @return HTMLElement 본문 element
 * @since engine-v1.62.0
 */
function buildContent(): HTMLElement {
    const dark = isDarkMode();
    const items = Array.from(failures.values());
    const isMobile = typeof window !== 'undefined' && window.innerWidth < 768;

    const box = document.createElement('div');
    box.style.cssText = [
        'pointer-events:auto',
        'box-sizing:border-box',
        'width:100%',
        'max-width:960px',
        'margin:8px',
        'padding:12px 16px',
        'border-radius:8px',
        `border:1px solid ${dark ? '#78350f' : '#fcd34d'}`,
        `background:${dark ? '#451a03' : '#fffbeb'}`,
        `color:${dark ? '#fef3c7' : '#78350f'}`,
        'box-shadow:0 4px 12px rgba(0,0,0,0.12)',
        'font-size:14px',
        'line-height:1.5',
        'display:flex',
        isMobile ? 'flex-direction:column' : 'flex-direction:row',
        isMobile ? 'align-items:stretch' : 'align-items:center',
        'gap:12px',
    ].join(';');

    // 테마 색은 개별 프로퍼티로 한 번 더 못 박는다. 일괄 문자열(cssText)은 파서가
    // 모르는 선언 하나로 전체가 버려지는 환경이 있어(테스트 환경의 jsdom 이 그렇다)
    // 색이 통째로 빠질 수 있다 — 안내 배너가 배경 없이 뜨면 안내로서 기능하지 못한다.
    box.style.background = dark ? '#451a03' : '#fffbeb';
    box.style.color = dark ? '#fef3c7' : '#78350f';
    box.style.borderColor = dark ? '#78350f' : '#fcd34d';

    box.appendChild(buildMessageArea(items, isMobile));
    box.appendChild(buildActions(items, isMobile, dark));

    return box;
}

/**
 * 문구 영역을 구성합니다.
 *
 * @param items 실패 목록
 * @param isMobile 모바일 폭 여부
 * @return HTMLElement 문구 영역
 * @since engine-v1.62.0
 */
function buildMessageArea(items: AssetFailure[], isMobile: boolean): HTMLElement {
    const wrap = document.createElement('div');
    wrap.style.cssText = ['flex:1 1 auto', 'min-width:0', 'display:flex', 'gap:8px'].join(';');

    const icon = document.createElement('span');
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = '⚠';
    icon.style.cssText = 'flex:0 0 auto;font-size:16px;line-height:1.4';
    wrap.appendChild(icon);

    const texts = document.createElement('div');
    texts.style.cssText = 'flex:1 1 auto;min-width:0';

    if (items.length === 1) {
        const only = items[0];
        const line = document.createElement('div');
        line.setAttribute('data-asset-failure-id', only.id);
        line.textContent =
            only.message ??
            t('core.assets.load_failed', '{label}을(를) 불러오지 못했습니다.', { label: only.label });
        texts.appendChild(line);
    } else {
        const head = document.createElement('div');
        head.style.cssText = 'font-weight:600';
        head.textContent = t('core.assets.load_failed_multiple', '{count}개 항목을 불러오지 못했습니다.', {
            count: items.length,
        });
        texts.appendChild(head);

        const list = document.createElement('div');
        list.style.cssText = 'margin-top:2px;opacity:0.9;word-break:break-word';
        list.textContent = items.map(item => item.label).join(', ');
        texts.appendChild(list);
    }

    if (retryFailed) {
        const failedLine = document.createElement('div');
        failedLine.style.cssText = 'margin-top:4px;font-weight:600';
        failedLine.textContent = t(
            'core.assets.retry_failed',
            '다시 시도했지만 실패했습니다. 잠시 후 다시 시도해 주세요.'
        );
        texts.appendChild(failedLine);
    }

    wrap.appendChild(texts);

    if (isMobile) {
        wrap.style.alignItems = 'flex-start';
    }

    return wrap;
}

/**
 * 버튼 영역을 구성합니다.
 *
 * @param items 실패 목록
 * @param isMobile 모바일 폭 여부
 * @param dark 다크 모드 여부
 * @return HTMLElement 버튼 영역
 * @since engine-v1.62.0
 */
function buildActions(items: AssetFailure[], isMobile: boolean, dark: boolean): HTMLElement {
    const actions = document.createElement('div');
    actions.style.cssText = [
        'flex:0 0 auto',
        'display:flex',
        'gap:8px',
        isMobile ? 'width:100%' : '',
        'align-items:center',
    ]
        .filter(Boolean)
        .join(';');

    const hasRetry = items.some(item => typeof item.retry === 'function');

    if (hasRetry) {
        const retryButton = document.createElement('button');
        retryButton.type = 'button';
        retryButton.dataset.action = 'retry';
        retryButton.disabled = retrying;
        retryButton.textContent = retrying
            ? t('core.assets.retrying', '다시 시도 중...')
            : t('core.assets.retry', '다시 시도');
        retryButton.style.cssText = [
            isMobile ? 'flex:1 1 auto' : '',
            'padding:6px 14px',
            'border-radius:6px',
            'font-size:14px',
            'font-weight:600',
            'cursor:pointer',
            `border:1px solid ${dark ? '#b45309' : '#d97706'}`,
            `background:${dark ? '#b45309' : '#f59e0b'}`,
            `color:${dark ? '#fffbeb' : '#451a03'}`,
            retrying ? 'opacity:0.6' : '',
        ]
            .filter(Boolean)
            .join(';');
        retryButton.addEventListener('click', () => {
            void retryAssetFailures();
        });
        actions.appendChild(retryButton);
    }

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.dataset.action = 'close';
    closeButton.setAttribute('aria-label', t('core.assets.close', '닫기'));
    closeButton.textContent = '✕';
    closeButton.style.cssText = [
        'padding:6px 10px',
        'border-radius:6px',
        'font-size:14px',
        'cursor:pointer',
        'border:1px solid transparent',
        'background:transparent',
        `color:${dark ? '#fef3c7' : '#78350f'}`,
    ].join(';');
    closeButton.addEventListener('click', () => {
        clearAllAssetFailures();
    });
    actions.appendChild(closeButton);

    return actions;
}

/**
 * 다크 모드 전환을 감시해 배너 색을 따라가게 합니다.
 *
 * @return void
 * @since engine-v1.62.0
 */
function startThemeObserver(): void {
    if (themeObserver || typeof MutationObserver === 'undefined') {
        return;
    }

    themeObserver = new MutationObserver(() => {
        if (failures.size > 0) {
            render();
        }
    });

    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
}

/**
 * 다크 모드 감시를 중단합니다.
 *
 * @return void
 * @since engine-v1.62.0
 */
function stopThemeObserver(): void {
    themeObserver?.disconnect();
    themeObserver = null;
}

/**
 * 서버가 HTML 에 직접 심은 템플릿 externals 의 로드 실패를 배너로 흘려보냅니다.
 *
 * 그 태그들은 엔진 번들보다 먼저 평가되므로, 실패는 `template-externals-head` 가 심어 둔
 * 부트스트랩의 대기열에 쌓인다. 엔진이 뜬 뒤 그 대기열을 비우고, 이후의 실패는 곧바로
 * 배너로 가도록 sink 를 건다.
 *
 * 이 통로가 없으면 아이콘 폰트·글꼴 CSS 실패가 "아이콘이 0×0 으로 사라진 화면" 으로만
 * 나타난다 — 아이콘만으로 조작하는 버튼이 있는 화면에서는 곧 조작 불능이고, 자체 서버
 * 로그에도 흔적이 없어 운영자가 원인을 특정할 수 없다.
 *
 * @return void
 * @since engine-v1.63.0
 */
export function drainExternalAssetFailures(): void {
    const globalScope = window as any;

    /**
     * 부트스트랩 대기열 항목을 배너 실패 항목으로 옮깁니다.
     *
     * @param entry 대기열 항목
     * @return 배너 실패 항목
     */
    const toFailure = (entry: any): AssetFailure => ({
        id: String(entry?.id ?? ''),
        label: String(entry?.label ?? entry?.url ?? 'asset'),
        retry: typeof entry?.retry === 'function' ? entry.retry : undefined,
    });

    const queue: any[] = Array.isArray(globalScope.__g7ExternalAssetFailures)
        ? globalScope.__g7ExternalAssetFailures
        : [];

    queue.forEach(entry => {
        if (entry?.id) {
            notifyAssetFailure(toFailure(entry));
        }
    });

    globalScope.__g7ExternalAssetSink = (entry: any) => {
        if (entry?.id) {
            notifyAssetFailure(toFailure(entry));
        }
    };

    logger.log(`템플릿 externals 실패 대기열 ${queue.length}건 반영`);
}
