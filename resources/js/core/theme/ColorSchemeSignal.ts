/**
 * 색상 테마 중립 표식 (ColorSchemeSignal)
 *
 * 확장 CSS 는 템플릿이 다크 모드를 어떻게 켜는지(`.dark` 조상 클래스, 미디어 쿼리, 혹은 다크 모드 없음)를
 * 알 수 없다. 템플릿마다 방식이 다르면 확장이 템플릿별로 다크 규칙을 따로 써야 한다. 그래서 코어가 템플릿의
 * 선언(`template.json` 의 `features.color_scheme`)을 읽어 `<html data-g7-color-scheme="dark|light">` 라는
 * **하나의 중립 표식**으로 옮겨 단다. 확장 CSS 는 이 표식만 본다.
 *
 * 선언 형식(서버가 `window.G7Config.colorScheme` 으로 주입):
 * - `{ strategy: 'ancestor-class', selector: '.dark' }` — `<html>`·`<body>` 가 selector 에 맞으면 다크
 * - `{ strategy: 'media-query' }` — `prefers-color-scheme: dark` 를 따른다
 * - `{ strategy: 'none' }` 또는 미선언 — 항상 light
 *
 * 확장 CSS 의 다크 선택자 규격(특이도를 템플릿 `.dark .x` 와 같게 유지):
 * `.ns-x:is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"]):not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"]))`
 * 뒤쪽 `:not(…)` 은 「다크 안의 라이트」(관리자 다크 + 편집기 프리뷰 라이트)만 제외한다. 바깥이 라이트이고
 * 프리뷰만 다크인 경우는 제외하지 않는다 — `<html>` 의 라이트 표식은 모든 요소의 조상이기 때문이다.
 *
 * @since engine-v1.93.0
 */

/** 템플릿 다크 방식 선언 */
export interface ColorSchemeDeclaration {
    strategy: 'ancestor-class' | 'media-query' | 'none';
    /** ancestor-class 일 때 다크 판정 선택자 (예: `.dark`) */
    selector?: string;
}

/** 현재 색상 테마 */
export type ColorScheme = 'dark' | 'light';

/** 표식 속성 이름 (`dataset` 키는 `g7ColorScheme`) */
export const COLOR_SCHEME_ATTRIBUTE = 'data-g7-color-scheme';

/**
 * 선언 값을 검사해 정규화한다. 알 수 없는 값은 `none` 으로 본다.
 *
 * @param raw 서버 주입값
 * @returns 정규화된 선언
 */
export function normalizeColorSchemeDeclaration(raw: unknown): ColorSchemeDeclaration {
    const value = (raw ?? {}) as Partial<ColorSchemeDeclaration>;

    if (value.strategy === 'ancestor-class' && typeof value.selector === 'string' && value.selector.trim() !== '') {
        return { strategy: 'ancestor-class', selector: value.selector.trim() };
    }

    if (value.strategy === 'media-query') {
        return { strategy: 'media-query' };
    }

    return { strategy: 'none' };
}

/**
 * 템플릿 선언을 지켜보며 `<html data-g7-color-scheme>` 를 갱신한다.
 */
export class ColorSchemeSignal {
    private declaration: ColorSchemeDeclaration = { strategy: 'none' };

    private observer: MutationObserver | null = null;

    private mediaQuery: MediaQueryList | null = null;

    private readonly onMediaChange = (): void => this.apply();

    /**
     * 관찰을 시작한다. 다시 부르면 이전 관찰을 끊고 새 선언으로 다시 시작한다.
     *
     * @param raw 템플릿 선언(미지정 시 `window.G7Config.colorScheme`)
     * @returns 없음
     */
    start(raw?: unknown): void {
        this.stop();
        this.declaration = normalizeColorSchemeDeclaration(
            raw !== undefined ? raw : (typeof window !== 'undefined' ? (window as any).G7Config?.colorScheme : undefined)
        );

        if (typeof document === 'undefined') {
            return;
        }

        if (this.declaration.strategy === 'ancestor-class' && typeof MutationObserver !== 'undefined') {
            this.observer = new MutationObserver(() => this.apply());
            this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
            if (document.body) {
                this.observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
            }
        }

        if (this.declaration.strategy === 'media-query' && typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
            this.mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            this.mediaQuery.addEventListener('change', this.onMediaChange);
        }

        this.apply();
    }

    /**
     * 관찰을 끊는다. 표식은 그대로 둔다.
     *
     * @returns 없음
     */
    stop(): void {
        this.observer?.disconnect();
        this.observer = null;
        this.mediaQuery?.removeEventListener('change', this.onMediaChange);
        this.mediaQuery = null;
    }

    /**
     * 선언에 따라 현재 테마를 계산한다.
     *
     * @returns 'dark' | 'light'
     */
    get(): ColorScheme {
        if (typeof document === 'undefined') {
            return 'light';
        }

        switch (this.declaration.strategy) {
            case 'ancestor-class': {
                const selector = this.declaration.selector as string;
                try {
                    const dark = document.documentElement.matches(selector) || (document.body?.matches(selector) ?? false);
                    return dark ? 'dark' : 'light';
                } catch {
                    // 잘못된 선택자 — 다크 판정을 하지 않는다
                    return 'light';
                }
            }
            case 'media-query':
                return this.mediaQuery?.matches
                    || (typeof window !== 'undefined' && typeof window.matchMedia === 'function'
                        && window.matchMedia('(prefers-color-scheme: dark)').matches)
                    ? 'dark'
                    : 'light';
            default:
                return 'light';
        }
    }

    /**
     * 현재 선언(진단용).
     *
     * @returns 정규화된 선언
     */
    getDeclaration(): ColorSchemeDeclaration {
        return { ...this.declaration };
    }

    /**
     * 표식을 갱신한다. 값이 같으면 쓰지 않는다(관찰 루프 방지).
     *
     * @returns 없음
     */
    private apply(): void {
        const next = this.get();
        const root = document.documentElement;
        if (root.getAttribute(COLOR_SCHEME_ATTRIBUTE) !== next) {
            root.setAttribute(COLOR_SCHEME_ATTRIBUTE, next);
        }
    }
}

let instance: ColorSchemeSignal | null = null;

/**
 * 공유 인스턴스.
 *
 * @returns ColorSchemeSignal
 */
export function getColorSchemeSignal(): ColorSchemeSignal {
    if (!instance) {
        instance = new ColorSchemeSignal();
    }
    return instance;
}
