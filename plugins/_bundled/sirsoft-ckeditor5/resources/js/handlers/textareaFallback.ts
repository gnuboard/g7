/**
 * CKEditor5 확보 실패 시의 평문 입력 폴백
 *
 * 편집기 자산을 끝내 불러오지 못하면 종전에는 `console.error` 한 줄을 남기고 끝났다.
 * `html-editor.json` 의 `mode: "replace"` 때문에 코어 HtmlEditor 도 렌더되지 않아,
 * 사용자에게는 빈 `<div>` 만 남았다 — 글을 쓸 방법이 아예 없었다.
 *
 * 이 모듈은 그 자리에 `<textarea>` 를 세워 **작성과 저장을 가능하게** 한다. 값은
 * 기존 폼 계약 그대로 `form.{name}` 에 넣고, `form.{name}_mode` 를 `'text'` 로 두어
 * 서버가 HTML 이 아닌 평문으로 처리하게 한다 (코어 HtmlEditor 와 동일한 계약).
 *
 * @module textareaFallback
 */

/** 폴백 요소를 식별하는 data 속성 */
const FALLBACK_ATTR = 'data-ckeditor5-fallback';

/**
 * 폴백 렌더 옵션
 */
export interface TextareaFallbackOptions {
    /** 편집기 컨테이너 (`#ckeditor5-{name}`) */
    container: HTMLElement;
    /** 폼 필드명 */
    name: string;
    /** 편집 영역 높이(px) */
    height: number;
    /** 읽기 전용 여부 */
    readOnly: boolean;
    /** placeholder 문구 */
    placeholder?: string;
    /** 다국어 모드 여부 */
    multilingual: boolean;
    /** 다국어 로케일 목록 */
    locales?: string[];
    /** 활성 로케일 */
    activeLocale?: string;
    /** 다국어 초기값 (로케일 → 내용) */
    contentMap?: Record<string, string>;
    /** 단일 모드 초기값 */
    initialContent?: string;
    /**
     * 이 입력을 "폼이 변경됨"(`_local.hasChanges`)으로 칠지 여부. 기본 `true`.
     *
     * 편집기 쪽 `trackChanges` 와 같은 축이다 — 저장 대상이 아닌 편집기가 폴백으로
     * 내려앉아도 저장 대상이 아닌 것은 그대로여야 한다.
     */
    trackChanges?: boolean;
}

/** 컨테이너별 폴백 상태 (재시도 시 값 승계에 쓴다) */
const fallbackState = new WeakMap<HTMLElement, {
    multilingual: boolean;
    values: Record<string, string>;
    activeLocale: string;
}>();

/**
 * 폼 상태에 값을 반영합니다.
 *
 * `_mode` 를 `'text'` 로 둔다 — 편집기가 없으므로 입력된 것은 HTML 이 아니다.
 * 이 값을 그대로 `'html'` 로 두면 서버가 평문을 HTML 로 신뢰하게 된다.
 *
 * `hasChanges` 는 본문 배치에 섞지 않는다 — `syncToForm` 과 같은 이유다. 그 배치는
 * `render:false + selfManaged:true` 라 React 렌더를 일으키지 않으므로, 플래그가 저장소 B 에만
 * 들어가고 저장 버튼의 활성 조건이 재평가되지 않는다. `admin_board_post_form.json` 은
 * 수정 화면에서 `(!!route?.id && !_local.hasChanges)` 로 저장을 잠그므로, 폴백에서 본문만
 * 고친 운영자는 **저장 자체를 할 수 없다.** 폐쇄망·방화벽 환경에서는 이 폴백이 정상 경로다.
 *
 * 이 함수는 사용자 입력뿐 아니라 **폴백을 세우는 시점에도** 불린다(`_mode='text'` 를 미리
 * 심어 두려고). 그 자리까지 플래그를 켜면 아무것도 입력하지 않았는데 화면을 여는 것만으로
 * "변경됨" 이 되므로, 실제 입력에서 온 호출만 올린다.
 *
 * @param name 폼 필드명
 * @param value 반영할 값 (다국어면 로케일 맵)
 * @param userInitiated 사용자 입력에서 온 호출인지 (렌더 시점 시드는 `false`)
 * @param tracksChanges 이 입력을 폼 변경으로 칠지 여부 (저장 대상이 아닌 편집기는 `false`)
 * @return void
 */
function syncFallbackToForm(
    name: string,
    value: string | Record<string, string>,
    userInitiated: boolean,
    tracksChanges: boolean
): void {
    const G7Core = (window as any).G7Core;

    if (!G7Core?.state?.setLocal || !name) {
        return;
    }

    // 렌더를 일으키는 별도 setLocal. false → true 로 처음 넘어갈 때만 보내므로
    // 편집 세션당 추가 렌더는 최대 1회다 (`syncToForm` 과 같은 규칙).
    if (userInitiated && tracksChanges && G7Core.state.getLocal?.()?.hasChanges !== true) {
        G7Core.state.setLocal({ hasChanges: true });
    }

    const updates: Record<string, any> = {
        [`form.${name}_mode`]: 'text',
    };

    if (typeof value === 'string') {
        updates[`form.${name}`] = value;
    } else {
        for (const [locale, localeValue] of Object.entries(value)) {
            updates[`form.${name}.${locale}`] = localeValue;
        }
    }

    G7Core.state.setLocal(updates, { render: false, selfManaged: true });
}

/**
 * 평문 입력 폴백을 렌더링합니다.
 *
 * 이미 폴백이 서 있으면 다시 만들지 않는다 — 재초기화 시도마다 입력 중이던 내용이
 * 날아가면 폴백이 폴백 구실을 못 한다.
 *
 * @param options 폴백 렌더 옵션
 * @return void
 */
export function renderTextareaFallback(options: TextareaFallbackOptions): void {
    const { container, name, height, readOnly, placeholder, multilingual } = options;

    if (container.querySelector(`[${FALLBACK_ATTR}]`)) {
        return;
    }

    container.innerHTML = '';

    // 기본은 true — 저장 대상이 아닌 편집기(설정 화면 미리보기 등)만 명시적으로 끈다.
    const tracksChanges = options.trackChanges !== false;

    const locales = multilingual ? (options.locales ?? []) : [];
    const activeLocale = options.activeLocale ?? locales[0] ?? '';

    const values: Record<string, string> = multilingual
        ? { ...(options.contentMap ?? {}) }
        : { '': options.initialContent ?? '' };

    const textarea = document.createElement('textarea');
    textarea.setAttribute(FALLBACK_ATTR, '1');
    textarea.name = name;
    textarea.readOnly = readOnly;
    textarea.placeholder = placeholder ?? '';
    textarea.style.cssText = [
        'width:100%',
        `height:${height}px`,
        'padding:12px',
        'border:1px solid #d1d5db',
        'border-radius:6px',
        'font-family:inherit',
        'font-size:14px',
        'line-height:1.6',
        'resize:vertical',
        'box-sizing:border-box',
    ].join(';');

    let currentLocale = activeLocale;

    if (multilingual) {
        const tabsEl = document.createElement('div');
        tabsEl.className = 'ckeditor5-locale-tabs';

        /**
         * 탭 버튼의 활성/체크 표시를 갱신합니다.
         *
         * @return void
         */
        const refreshTabs = (): void => {
            tabsEl.querySelectorAll('button').forEach(button => {
                const locale = (button as HTMLButtonElement).dataset.locale ?? '';
                const isActive = locale === currentLocale;
                button.classList.toggle('is-active', isActive);
                const hasValue = (values[locale] ?? '').trim() !== '';
                const check = button.querySelector('[data-check]');
                if (check) {
                    (check as HTMLElement).style.display = hasValue ? '' : 'none';
                }
            });
        };

        locales.forEach((locale, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.locale = locale;
            if (index === 0) {
                button.classList.add('is-default');
            }
            button.textContent = locale.toUpperCase();

            const check = document.createElement('span');
            check.dataset.check = '1';
            check.textContent = '✓';
            check.style.display = 'none';
            button.appendChild(check);

            button.addEventListener('click', () => {
                // 탭을 떠나기 전에 현재 입력을 그 로케일에 커밋한다
                values[currentLocale] = textarea.value;
                currentLocale = locale;
                textarea.value = values[locale] ?? '';
                textarea.dataset.locale = locale;
                syncFallbackToForm(name, values, false, tracksChanges);
                refreshTabs();
            });

            tabsEl.appendChild(button);
        });

        container.appendChild(tabsEl);
        textarea.dataset.locale = currentLocale;
        textarea.value = values[currentLocale] ?? '';
        refreshTabs();

        textarea.addEventListener('input', () => {
            values[currentLocale] = textarea.value;
            syncFallbackToForm(name, values, true, tracksChanges);
            refreshTabs();
        });
    } else {
        textarea.value = values[''] ?? '';

        textarea.addEventListener('input', () => {
            values[''] = textarea.value;
            syncFallbackToForm(name, textarea.value, true, tracksChanges);
        });
    }

    container.appendChild(textarea);

    fallbackState.set(container, { multilingual, values, activeLocale: currentLocale });

    // 폴백으로 내려앉았다는 사실 자체를 폼 상태에 반영한다 — 사용자가 아무것도 입력하지
    // 않고 저장해도 서버가 평문으로 처리하도록.
    syncFallbackToForm(name, multilingual ? values : (values[''] ?? ''), false, tracksChanges);
}

/**
 * 폴백에 입력된 값을 돌려줍니다 (재시도 성공 시 편집기로 승계하기 위해).
 *
 * @param container 편집기 컨테이너
 * @return 단일 모드면 문자열, 다국어면 로케일 맵, 폴백이 없으면 null
 */
export function getTextareaFallbackValues(container: HTMLElement): string | Record<string, string> | null {
    const state = fallbackState.get(container);

    if (!state) {
        return null;
    }

    const textarea = container.querySelector(`textarea[${FALLBACK_ATTR}]`) as HTMLTextAreaElement | null;

    if (textarea) {
        state.values[state.multilingual ? (textarea.dataset.locale ?? state.activeLocale) : ''] = textarea.value;
    }

    return state.multilingual ? { ...state.values } : (state.values[''] ?? '');
}

/**
 * 폴백을 제거합니다.
 *
 * @param container 편집기 컨테이너
 * @return bool 제거했으면 true
 */
export function removeTextareaFallback(container: HTMLElement): boolean {
    if (!container.querySelector(`[${FALLBACK_ATTR}]`)) {
        return false;
    }

    container.innerHTML = '';
    fallbackState.delete(container);

    return true;
}

/**
 * 컨테이너에 폴백이 서 있는지 판정합니다.
 *
 * @param container 편집기 컨테이너
 * @return bool 폴백이 있으면 true
 */
export function hasTextareaFallback(container: HTMLElement): boolean {
    return container.querySelector(`[${FALLBACK_ATTR}]`) !== null;
}
