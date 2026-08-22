// e2e:allow ⑫⑬ 보안 수정 — 클라이언트 지정 업로드 권한 쿼리(죽은 배선) 제거만 수행. 업로드 동작·UI 불변(서버 admin 게이트 유지), 사용자 시나리오 변화 없음.
/**
 * CKEditor5 초기화 핸들러
 *
 * 컴포넌트 마운트 시 CKEditor5 인스턴스를 생성합니다.
 * 다국어(multilingual) 모드: locale별 탭 + 인스턴스 생성
 * 단일 모드: 단일 인스턴스 생성
 */

import { editorInstances, isSyncSuppressed, registerExternalSync, stopExternalSyncs } from './editorInstances';
import { attachExternalContentSync } from './externalContentSync';
import {
    CKEDITOR5_CSS_ID,
    ckeditorCssUrl,
    ckeditorScriptUrl,
    ckeditorTranslationUrl,
} from './ckeditorAssets';
import {
    getTextareaFallbackValues,
    removeTextareaFallback,
    renderTextareaFallback,
} from './textareaFallback';

/** CKEditor5 다크 모드 CSS 엘리먼트 ID */
const CKEDITOR5_DARK_CSS_ID = 'ckeditor5-dark-override';
/** CKEditor5 다국어 탭 기본 CSS 엘리먼트 ID */
const CKEDITOR5_TAB_CSS_ID = 'ckeditor5-tab-style';

/** CKEditor 전역 객체 타입 (CDN UMD 빌드 — window.CKEDITOR) */
interface CKEditorGlobal {
    ClassicEditor: any;
    Essentials: any;
    Bold: any;
    Italic: any;
    Underline: any;
    Strikethrough: any;
    Link: any;
    Paragraph: any;
    Heading: any;
    BlockQuote: any;
    List: any;
    Alignment: any;
    Table: any;
    TableToolbar: any;
    TableProperties: any;
    TableCellProperties: any;
    Image: any;
    ImageUpload: any;
    ImageResize: any;
    ImageStyle: any;
    ImageCaption: any;
    ImageToolbar: any;
    SimpleUploadAdapter: any;
    MediaEmbed: any;
    HorizontalLine: any;
    Indent: any;
    IndentBlock: any;
    CodeBlock: any;
    SourceEditing: any;
    FontSize: any;
    FontColor: any;
    FontBackgroundColor: any;
    PasteFromOffice: any;
    GeneralHtmlSupport: any;
}

/** initEditor 파라미터 타입 */
interface InitEditorParams {
    name?: string;
    content?: string;
    multilingual?: boolean | string;
    placeholder?: string;
    readOnly?: boolean | string;
    imageUpload?: boolean | string;
    height?: number | string;
    toolbar?: string;
    /**
     * 이 편집기의 입력을 "폼이 변경됨"(`_local.hasChanges`)으로 칠지 여부. 기본 `true`.
     *
     * 저장 대상이 아닌 편집기(설정 화면의 미리보기 등)는 `false` 로 선언한다. 그러지 않으면
     * 운영자가 시험 삼아 미리보기에만 글자를 쳐도 [저장] 버튼이 켜져, 바뀐 것이 없는데
     * 바뀐 것처럼 보인다. 어느 편집기가 저장 대상인지는 **레이아웃이 안다** — 그래서
     * 핸들러가 필드명을 알아보는 대신 선언으로 받는다.
     */
    trackChanges?: boolean | string;
}

/** 툴바 프리셋 */
const TOOLBAR_PRESETS: Record<string, string[]> = {
    minimal: [
        'bold', 'italic', 'underline', '|',
        'link', 'uploadImage', '|',
        'undo', 'redo',
    ],
    standard: [
        'heading', '|',
        'bold', 'italic', 'underline', 'strikethrough', '|',
        'alignment', '|',
        'link', 'blockQuote', '|',
        'bulletedList', 'numberedList', 'indent', 'outdent', '|',
        'insertTable', 'uploadImage', '|',
        'undo', 'redo',
    ],
    full: [
        'heading', '|',
        'fontSize', 'fontColor', 'fontBackgroundColor', '|',
        'bold', 'italic', 'underline', 'strikethrough', '|',
        'alignment', '|',
        'link', 'blockQuote', 'insertTable', 'uploadImage', 'mediaEmbed', 'horizontalLine', '|',
        'bulletedList', 'numberedList', 'indent', 'outdent', '|',
        'codeBlock', 'sourceEditing', '|',
        'undo', 'redo',
    ],
};

/**
 * CKEditor5 번역 스크립트를 동적으로 로드합니다.
 * 영어(en)는 기본 내장이므로 로드하지 않습니다.
 *
 * 번역 실패는 **정상적인 열화**다 — UI 가 영어로 뜰 뿐 에디터는 동작한다. 그래서
 * 실패해도 resolve 하지만, 종전처럼 조용히 넘기지는 않는다(로그조차 없었다).
 *
 * @param locale 로케일 코드
 * @return Promise<void> 로드 시도 완료 시 resolve (실패해도 resolve)
 */
function loadCkeditorTranslations(locale: string): Promise<void> {
    if (locale === 'en') return Promise.resolve();
    const id = `ckeditor5-translations-${locale}`;
    if (document.getElementById(id)) return Promise.resolve();

    const loadScript = (window as any)?.G7Core?.asset?.loadScript;

    return new Promise((resolve) => {
        let url: string;

        try {
            url = ckeditorTranslationUrl(locale);
        } catch (error) {
            console.warn('[ckeditor5] 번역 URL 을 만들지 못했습니다 (영어로 동작):', error);
            resolve();

            return;
        }

        if (typeof loadScript === 'function') {
            loadScript(url, { id }, { label: `ckeditor5 translations: ${locale}`, retries: 1 })
                .catch((error: unknown) => {
                    console.warn(`[ckeditor5] 번역 로드 실패 (${locale}) — 영어로 동작합니다:`, error);
                })
                .then(() => resolve());

            return;
        }

        const script = document.createElement('script');
        script.id = id;
        script.src = url;
        script.onload = () => resolve();
        script.onerror = () => {
            console.warn(`[ckeditor5] 번역 로드 실패 (${locale}) — 영어로 동작합니다.`);
            resolve();
        };
        document.head.appendChild(script);
    });
}

/**
 * CKEditor5 CSS를 동적으로 로드합니다.
 * 이미 로드된 경우 중복 삽입하지 않습니다.
 *
 * 종전에는 `onerror` 자체가 없어 CSS 실패가 완전히 무음이었다 — 툴바·편집 영역의
 * 스타일이 통째로 빠진 화면이 오류 없이 남았다. 이제 재시도하고, 끝내 실패하면
 * 코어 안내 배너로 표면화한다.
 *
 * @return Promise<void> 로드 시도 완료 시 resolve (실패해도 resolve — 에디터 자체는 동작)
 */
async function loadCkeditorCss(): Promise<void> {
    if (document.getElementById(CKEDITOR5_CSS_ID)) {
        return;
    }

    const loadStylesheet = (window as any)?.G7Core?.asset?.loadStylesheet;

    let url: string;

    try {
        url = ckeditorCssUrl();
    } catch (error) {
        console.warn('[ckeditor5] CSS URL 을 만들지 못했습니다:', error);

        return;
    }

    if (typeof loadStylesheet !== 'function') {
        const link = document.createElement('link');
        link.id = CKEDITOR5_CSS_ID;
        link.rel = 'stylesheet';
        link.href = url;
        document.head.appendChild(link);

        return;
    }

    try {
        await loadStylesheet(url, { id: CKEDITOR5_CSS_ID }, { label: 'ckeditor5 CSS' });
    } catch (error) {
        console.warn('[ckeditor5] CSS 로드 실패:', error);
        notifyEditorAssetFailure('ckeditor5-css', t('editor.asset.style_label', '편집기 스타일'), () => loadCkeditorCss());
    }
}

/**
 * CKEditor 전역이 확보되었는지 확인하고, 없으면 한 번 더 로드를 시도합니다.
 *
 * 레이아웃 `scripts[]` 가 이미 본체를 로드하지만, 그 로드가 끝내 실패했을 수 있다.
 * 코어 로더(`TemplateApp.loadLayoutScripts`)는 실패해도 다른 스크립트를 계속 진행하므로
 * 여기까지 도달하고, 그때 `window.CKEDITOR` 는 없다.
 *
 * @return Promise<bool> 전역이 확보되면 true
 */
async function ensureCkeditorGlobal(): Promise<boolean> {
    if ((window as any).CKEDITOR) {
        return true;
    }

    const loadScript = (window as any)?.G7Core?.asset?.loadScript;

    if (typeof loadScript !== 'function') {
        return false;
    }

    try {
        await loadScript(
            ckeditorScriptUrl(),
            { id: 'ckeditor5-vendor-umd' },
            { label: 'ckeditor5 UMD' }
        );
    } catch (error) {
        console.warn('[ckeditor5] 편집기 본체를 불러오지 못했습니다:', error);

        return false;
    }

    return Boolean((window as any).CKEDITOR);
}

/**
 * 폴백 상태에서 편집기 초기화를 다시 시도합니다.
 *
 * 성공하면 폴백에 입력해 둔 내용을 편집기로 승계한다 — 재시도가 사용자의 작성물을
 * 지워버리면 [다시 시도] 버튼은 누를 수 없는 버튼이 된다.
 *
 * @param action 원래 액션 정의
 * @param context 원래 컨텍스트
 * @param container 편집기 컨테이너
 * @param multilingual 다국어 모드 여부
 * @param name 폼 필드명
 * @return Promise<void>
 * @throws Error 재시도 후에도 편집기를 세우지 못한 경우 (배너가 유지된다)
 */
async function retryEditorInit(
    action: { params?: InitEditorParams; [key: string]: any },
    context: unknown,
    container: HTMLElement,
    multilingual: boolean,
    name: string
): Promise<void> {
    const carried = getTextareaFallbackValues(container);

    if (!(await ensureCkeditorGlobal())) {
        throw new Error('[sirsoft-ckeditor5] 편집기 본체를 여전히 불러올 수 없습니다.');
    }

    removeTextareaFallback(container);

    // 승계할 내용을 액션 파라미터에 실어 초기화가 그 값을 초기 콘텐츠로 쓰게 한다
    const retryAction = {
        ...action,
        params: {
            ...(action.params ?? {}),
            content: multilingual
                ? (carried as Record<string, string> | null) ?? {}
                : ((carried as string | null) ?? ''),
        },
    };

    await initEditorHandler(retryAction as any, context);

    if (!name) {
        return;
    }

    // 폴백이 남긴 `_mode: 'text'` 를 편집기 계약으로 되돌린다
    const G7Core = (window as any).G7Core;
    G7Core?.state?.setLocal?.({ [`form.${name}_mode`]: 'html' });
}

/**
 * 자산 로드 실패를 코어 안내 배너에 알립니다.
 *
 * 코어 API 가 없으면(구 코어) 콘솔 경고로 떨어진다 — 안내를 못 띄운다고 에디터
 * 초기화를 중단시키지는 않는다.
 *
 * @param id 중복 누적을 막는 식별자
 * @param label 사용자에게 보일 항목명
 * @param retry 다시 시도 동작
 */
function notifyEditorAssetFailure(
    id: string,
    label: string,
    retry: () => Promise<void> | void,
    message?: string
): void {
    const notify = (window as any)?.G7Core?.assets?.notifyFailure;

    if (typeof notify === 'function') {
        notify({ id, label, retry, message });

        return;
    }

    console.warn(`[ckeditor5] 자산 로드 실패: ${label}`);
}

/**
 * 번역 문자열을 얻습니다.
 *
 * 배너 문구를 한국어로 박아 두면 다른 로케일에서 그 문구만 한국어로 남는다 — 실패를
 * 알리는 화면이 정작 읽히지 않는 상태가 된다.
 *
 * @param key 번역 키 (플러그인 네임스페이스 이하)
 * @param fallback 번역 엔진 부재 시 사용할 문구
 * @return string 번역된 문자열
 */
function t(key: string, fallback: string): string {
    const translate = (window as any)?.G7Core?.t;

    if (typeof translate !== 'function') {
        return fallback;
    }

    const full = `sirsoft-ckeditor5.${key}`;
    const result = translate(full);

    return typeof result === 'string' && result !== full ? result : fallback;
}

/**
 * 다국어 탭 버튼 기본 스타일(라이트/다크 공통 베이스)을 주입합니다.
 * 이미 주입된 경우 중복 삽입하지 않습니다.
 */
function loadTabCss(): void {
    if (document.getElementById(CKEDITOR5_TAB_CSS_ID)) {
        return;
    }
    const style = document.createElement('style');
    style.id = CKEDITOR5_TAB_CSS_ID;
    style.textContent = `
        /* 다국어 탭 컨테이너 */
        .ckeditor5-locale-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
            align-items: center;
        }
        /* 다국어 탭 버튼 - HtmlEditor 언어탭 스타일 */
        .ckeditor5-locale-tabs button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border: none;
            border-radius: 9999px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1;
            transition: background 0.15s, color 0.15s, transform 0.15s, box-shadow 0.15s;
        }
        /* 비활성 - 기본언어 (blue) */
        .ckeditor5-locale-tabs button.is-default {
            background: #eff6ff;
            color: #2563eb;
        }
        .ckeditor5-locale-tabs button.is-default:hover {
            background: #dbeafe;
        }
        /* 비활성 - 비기본언어 (gray) */
        .ckeditor5-locale-tabs button:not(.is-default) {
            background: #f3f4f6;
            color: #4b5563;
        }
        .ckeditor5-locale-tabs button:not(.is-default):hover {
            background: #e5e7eb;
        }
        /* 활성 - 기본언어 */
        .ckeditor5-locale-tabs button.is-active.is-default {
            background: #3b82f6;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            transform: scale(1.05);
        }
        /* 활성 - 비기본언어 */
        .ckeditor5-locale-tabs button.is-active:not(.is-default) {
            background: #6b7280;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            transform: scale(1.05);
        }
        .ckeditor5-locale-tabs button .ck5-lang-icon {
            font-size: 0.75rem;
            line-height: 1;
        }
        .ckeditor5-locale-tabs button .ck5-required {
            color: #ef4444;
        }
        .ckeditor5-locale-tabs button.is-active .ck5-required {
            color: #fca5a5;
        }
        .ckeditor5-locale-tabs button .ck5-check {
            color: #22c55e;
        }
        /* 다크 모드 - 비활성 기본언어 */
        html.dark .ckeditor5-locale-tabs button.is-default {
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
        }
        html.dark .ckeditor5-locale-tabs button.is-default:hover {
            background: rgba(59, 130, 246, 0.25);
        }
        /* 다크 모드 - 비활성 비기본언어 */
        html.dark .ckeditor5-locale-tabs button:not(.is-default) {
            background: #374151;
            color: #d1d5db;
        }
        html.dark .ckeditor5-locale-tabs button:not(.is-default):hover {
            background: #4b5563;
        }
        /* 다크 모드 - 활성 기본언어 */
        html.dark .ckeditor5-locale-tabs button.is-active.is-default {
            background: #2563eb;
            color: #ffffff;
        }
        /* 다크 모드 - 활성 비기본언어 */
        html.dark .ckeditor5-locale-tabs button.is-active:not(.is-default) {
            background: #4b5563;
            color: #ffffff;
        }
        html.dark .ckeditor5-locale-tabs button .ck5-check {
            color: #4ade80;
        }
        /* 표 너비 인라인 스타일 무력화 - 컨테이너에 맞게 100% */
        .ck-content figure.table { width: 100%; }
        .ck-content figure.table table { width: 100%; }
        /* 표 tr 배경색 초기화 */
        .ck-content table tr { background-color: unset; }
        /* 표 col 절대 px 너비 무력화 - 컨테이너에 맞게 비율로 */
        .ck-content table col { width: auto !important; }
        /* 표 정렬 보정: CKEditor5 기본 CSS의 margin-left:auto를 float으로 덮어씀 */
        .ck-content figure.table[style*="float:left"],
        .ck-content figure.table[style*="float: left"] {
            margin-left: 0 !important;
            margin-right: 1em !important;
        }
        .ck-content figure.table[style*="float:right"],
        .ck-content figure.table[style*="float: right"] {
            margin-left: 1em !important;
            margin-right: 0 !important;
        }
        /* 이미지 캡션 - 라이트 모드 */
        .ck-content .image > figcaption {
            background-color: #f3f4f6;
            color: #374151;
            font-size: 0.875em;
            padding: 4px 8px;
            text-align: center;
        }
        /* 에디터 편집 영역 - 목록 마커 복원 (Tailwind preflight reset 대응) */
        .ck.ck-editor__editable ul,
        .ck-content ul {
            list-style-type: disc;
            padding-left: 2em;
        }
        .ck.ck-editor__editable ol,
        .ck-content ol {
            list-style-type: decimal;
            padding-left: 2em;
        }
        .ck.ck-editor__editable ul ul,
        .ck-content ul ul {
            list-style-type: circle;
            padding-left: 2em;
        }
        .ck.ck-editor__editable ul ul ul,
        .ck-content ul ul ul {
            list-style-type: square;
            padding-left: 2em;
        }
        .ck.ck-editor__editable ol ol,
        .ck-content ol ol {
            list-style-type: lower-alpha;
            padding-left: 2em;
        }
        .ck.ck-editor__editable ol ol ol,
        .ck-content ol ol ol {
            list-style-type: lower-roman;
            padding-left: 2em;
        }
    `;
    document.head.appendChild(style);
}

/**
 * 현재 다크 모드 여부를 반환합니다.
 */
function isDarkMode(): boolean {
    return document.documentElement.classList.contains('dark');
}

/**
 * CKEditor5 다크 모드 CSS 변수를 동적으로 주입하거나 제거합니다.
 * document.documentElement의 'dark' 클래스를 기준으로 판단합니다.
 */
function applyDarkModeOverride(): void {
    const existing = document.getElementById(CKEDITOR5_DARK_CSS_ID);

    if (!isDarkMode()) {
        // 라이트 모드: 다크 오버라이드 제거
        if (existing) {
            existing.remove();
        }
        return;
    }

    if (existing) {
        return;
    }

    const style = document.createElement('style');
    style.id = CKEDITOR5_DARK_CSS_ID;
    style.textContent = `
        /* CKEditor5 다크 모드 오버라이드 - CSS 변수 전역 적용 */
        :root {
            --ck-color-base-foreground: #1f2937;
            --ck-color-base-background: #1f2937;
            --ck-color-base-border: #374151;
            --ck-color-text: #f3f4f6;
            --ck-color-toolbar-background: #111827;
            --ck-color-toolbar-border: #374151;
            --ck-color-button-default-background: transparent;
            --ck-color-button-default-hover-background: #374151;
            --ck-color-button-default-active-background: #4b5563;
            --ck-color-button-on-background: #374151;
            --ck-color-button-on-hover-background: #4b5563;
            --ck-color-focus-border: #3b82f6;
            --ck-color-input-background: #1f2937;
            --ck-color-input-text: #f3f4f6;
            --ck-color-input-border: #374151;
            --ck-color-panel-background: #1f2937;
            --ck-color-panel-border: #374151;
            --ck-color-list-button-hover-background: #374151;
            --ck-color-list-button-on-background: #3b82f6;
            --ck-color-list-button-on-color: #f3f4f6;
            --ck-color-list-button-on-hover-background: #4b5563;
            --ck-color-labeled-field-label-background: #1f2937;
            --ck-color-link-default: #60a5fa;
            --ck-color-link-selected-background: rgba(96, 165, 250, 0.1);
            --ck-color-table-focused-cell-background: rgba(59, 130, 246, 0.1);
        }
        /* 에디터 본문 */
        .ck.ck-editor__editable_inline {
            background-color: #1f2937 !important;
            color: #f3f4f6 !important;
            border-color: #374151 !important;
        }
        /* 메인 툴바 */
        .ck.ck-toolbar {
            background-color: #111827 !important;
            border-color: #374151 !important;
        }
        /* 모든 버튼 텍스트 */
        .ck.ck-button,
        .ck.ck-button .ck-button__label {
            color: #f3f4f6 !important;
        }
        /* 모든 SVG 아이콘 (툴바, 드롭다운 화살표 포함) */
        .ck.ck-icon,
        .ck .ck-icon,
        .ck.ck-button .ck-icon,
        .ck.ck-dropdown__arrow {
            color: #f3f4f6 !important;
        }
        .ck.ck-button:hover,
        .ck.ck-button.ck-on {
            background-color: #374151 !important;
        }
        /* 툴팁 (호버 시 버튼 설명) */
        .ck.ck-tooltip .ck-tooltip__text {
            background-color: #1f2937 !important;
            color: #f3f4f6 !important;
        }
        /* 드롭다운 패널 / 리스트 / balloon 팝업 공통 */
        .ck.ck-dropdown__panel,
        .ck.ck-list,
        .ck.ck-balloon-panel {
            background-color: #1f2937 !important;
            border-color: #374151 !important;
        }
        /* 리스트 아이템 */
        .ck.ck-list__item .ck-button,
        .ck.ck-list__item .ck-button .ck-button__label {
            color: #f3f4f6 !important;
        }
        .ck.ck-list__item .ck-button:hover {
            background-color: #374151 !important;
        }
        .ck.ck-list__item .ck-button.ck-disabled .ck-button__label {
            color: #6b7280 !important;
        }
        /* 링크 팝업 / form */
        .ck.ck-link-form,
        .ck.ck-link-actions {
            background-color: #1f2937 !important;
            border-color: #374151 !important;
        }
        .ck.ck-input-text {
            background-color: #111827 !important;
            color: #f3f4f6 !important;
            border-color: #374151 !important;
        }
        .ck.ck-labeled-field-view__label {
            background-color: #1f2937 !important;
            color: #9ca3af !important;
        }
        /* 테이블 속성/셀 속성 폼 */
        .ck.ck-table-form,
        .ck.ck-table-cell-properties-form,
        .ck.ck-table-properties-form {
            background-color: #1f2937 !important;
            border-color: #374151 !important;
        }
        .ck.ck-table-form .ck-label,
        .ck.ck-table-cell-properties-form .ck-label,
        .ck.ck-table-properties-form .ck-label {
            color: #d1d5db !important;
        }
        /* 색상 선택 패널 */
        .ck.ck-color-table,
        .ck.ck-color-grid {
            background-color: #1f2937 !important;
        }
        .ck.ck-color-table .ck-color-table__remove-color,
        .ck.ck-color-table .ck-color-table__remove-color .ck-button__label {
            color: #f3f4f6 !important;
        }
        /* 헤딩/폰트 드롭다운 라벨 */
        .ck.ck-heading_paragraph,
        .ck.ck-heading_heading1,
        .ck.ck-heading_heading2,
        .ck.ck-heading_heading3 {
            color: #f3f4f6 !important;
        }
        /* 이미지 캡션 */
        .ck-content .image > figcaption {
            background-color: #374151 !important;
            color: #d1d5db !important;
        }
        /* 이미지 리사이즈 핸들 */
        .ck-content .image.ck-widget_selected .ck-widget__resizer__handle {
            background-color: #3b82f6 !important;
            border-color: #1d4ed8 !important;
        }
        /* 이미지 정렬 툴바 아이콘 */
        .ck.ck-toolbar .ck-button .ck-icon {
            color: #f3f4f6 !important;
        }
        /* 이미지 선택 시 balloon toolbar (floating) */
        .ck.ck-toolbar_floating {
            background-color: #111827 !important;
            border-color: #374151 !important;
        }
        /* 이미지 리사이즈 드롭다운 버튼 라벨 (25%, 50% 등) */
        .ck.ck-dropdown .ck-button__label {
            color: #f3f4f6 !important;
        }
        /* 이미지 리사이즈 드롭다운 내 선택 리스트 */
        .ck.ck-resize-image-form,
        .ck.ck-image-resize-form {
            background-color: #1f2937 !important;
            border-color: #374151 !important;
            color: #f3f4f6 !important;
        }
        .ck.ck-resize-image-form .ck-label,
        .ck.ck-image-resize-form .ck-label {
            color: #d1d5db !important;
        }
        .ck.ck-resize-image-form .ck-input-text,
        .ck.ck-image-resize-form .ck-input-text {
            background-color: #111827 !important;
            color: #f3f4f6 !important;
            border-color: #374151 !important;
        }
        /* 표 안 에디터 내용 (다크 배경) */
        .ck-content table tr {
            background-color: unset;
        }
        .ck-content table td,
        .ck-content table th {
            border-color: #4b5563;
            color: #f3f4f6 !important;
        }
        /* 표 col 절대 px 너비 무력화 */
        .ck-content table col {
            width: auto !important;
        }
        /* 목록 마커 색상 - 다크모드 */
        .ck.ck-editor__editable ul,
        .ck.ck-editor__editable ol {
            color: #f3f4f6;
        }
        .ck.ck-editor__editable li::marker {
            color: #d1d5db;
        }
    `;
    document.head.appendChild(style);
}

/** 다크 모드 MutationObserver 인스턴스 (cleanup용) */
let darkModeObserver: MutationObserver | null = null;

/**
 * <html> 클래스 변경을 감지하여 다크 모드 전환 시 즉시 반영합니다.
 * 최초 1회만 등록합니다.
 */
function watchDarkMode(): void {
    if (darkModeObserver) {
        return;
    }

    darkModeObserver = new MutationObserver(() => {
        applyDarkModeOverride();
    });

    darkModeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
}

/**
 * 다크 모드 MutationObserver를 해제합니다.
 */
export function disconnectDarkModeObserver(): void {
    if (darkModeObserver) {
        darkModeObserver.disconnect();
        darkModeObserver = null;
    }
}

/** locale 코드 → 표시 이름 매핑 */
const LOCALE_LABELS: Record<string, string> = {
    ko: '한국어',
    en: 'English',
    ja: '日本語',
    zh: '中文',
    fr: 'Français',
    de: 'Deutsch',
    es: 'Español',
};

/**
 * locale 코드를 표시 이름으로 변환합니다.
 * 매핑에 없는 경우 대문자 코드를 반환합니다.
 */
function getLocaleLabel(locale: string): string {
    return LOCALE_LABELS[locale] ?? locale.toUpperCase();
}

/**
 * 지원 locale 목록을 반환합니다.
 * localStorage의 g7_locale 값과 서버 설정을 활용합니다.
 */
function getSupportedLocales(): string[] {
    const G7Core = (window as any).G7Core;
    if (G7Core?.locale?.supported) {
        return G7Core.locale.supported();
    }
    // 폴백: 현재 locale만 사용
    const currentLocale = localStorage.getItem('g7_locale') || 'ko';
    return [currentLocale];
}

/**
 * 현재 locale을 반환합니다.
 * G7Core.locale.current() 우선, 폴백으로 localStorage 사용.
 */
function getCurrentLocale(): string {
    const G7Core = (window as any).G7Core;
    return G7Core?.locale?.current?.() ?? localStorage.getItem('g7_locale') ?? 'ko';
}

/**
 * CKEditor 플러그인 목록을 toolbar 타입에 따라 반환합니다.
 */
function getPlugins(CKEDITOR: CKEditorGlobal, toolbarType: string, withImageUpload: boolean): any[] {
    const base = [
        CKEDITOR.Essentials,
        CKEDITOR.Bold,
        CKEDITOR.Italic,
        CKEDITOR.Underline,
        CKEDITOR.Strikethrough,
        CKEDITOR.Link,
        CKEDITOR.Paragraph,
        CKEDITOR.Heading,
        CKEDITOR.BlockQuote,
        CKEDITOR.List,
        CKEDITOR.Alignment,
        CKEDITOR.Table,
        CKEDITOR.TableToolbar,
        CKEDITOR.TableProperties,
        CKEDITOR.TableCellProperties,
        CKEDITOR.Indent,
        CKEDITOR.IndentBlock,
        CKEDITOR.PasteFromOffice,
        CKEDITOR.GeneralHtmlSupport,
        // 이미지 플러그인은 업로드 여부와 무관하게 항상 포함 (기존 이미지 크기 조정/정렬 지원)
        CKEDITOR.Image,
        CKEDITOR.ImageResize,
        CKEDITOR.ImageStyle,
        CKEDITOR.ImageCaption,
        CKEDITOR.ImageToolbar,
    ];

    if (toolbarType === 'full') {
        base.push(
            CKEDITOR.MediaEmbed,
            CKEDITOR.HorizontalLine,
            CKEDITOR.CodeBlock,
            CKEDITOR.SourceEditing,
            CKEDITOR.FontSize,
            CKEDITOR.FontColor,
            CKEDITOR.FontBackgroundColor,
        );
    }

    if (withImageUpload) {
        base.push(
            CKEDITOR.ImageUpload,
            CKEDITOR.SimpleUploadAdapter,
        );
    }

    return base;
}

/**
 * 이미지 업로드 URL을 생성합니다.
 * 인가는 서버 라우트 게이트(auth:sanctum + admin)가 전담하므로 쿼리 파라미터는 붙이지 않습니다.
 */
function buildUploadUrl(): string {
    return '/api/plugins/sirsoft-ckeditor5/upload';
}

/**
 * 이미지 업로드 헤더를 반환합니다.
 * Bearer 토큰을 Authorization 헤더에 포함합니다.
 */
function getUploadHeaders(): Record<string, string> {
    const token = localStorage.getItem('auth_token');
    if (token) {
        return { Authorization: `Bearer ${token}` };
    }
    return {};
}

/**
 * 단일 CKEditor 인스턴스를 생성합니다.
 */
async function createEditorInstance(
    element: HTMLElement,
    options: {
        initialContent: string;
        placeholder: string;
        readOnly: boolean;
        imageUpload: boolean;
        height: number;
        toolbar: string;
        containerId: string;
    }
): Promise<unknown> {
    const CKEDITOR = (window as any).CKEDITOR as CKEditorGlobal;
    if (!CKEDITOR) {
        throw new Error('[sirsoft-ckeditor5] CKEDITOR 전역 객체를 찾을 수 없습니다. CDN 스크립트가 로드되었는지 확인하세요.');
    }

    const toolbarItems = TOOLBAR_PRESETS[options.toolbar] || TOOLBAR_PRESETS.standard;

    // 이미지 업로드 비활성화 시 uploadImage 버튼 제거
    const finalToolbar = options.imageUpload
        ? toolbarItems
        : toolbarItems.filter(item => item !== 'uploadImage');

    const editorConfig: Record<string, any> = {
        initialData: options.initialContent,
        plugins: getPlugins(CKEDITOR, options.toolbar, options.imageUpload),
        toolbar: { items: finalToolbar },
        table: {
            contentToolbar: [
                'tableColumn', 'tableRow', 'mergeTableCells',
                '|', 'tableProperties', 'tableCellProperties',
            ],
            tableProperties: {
                defaultProperties: {
                    borderStyle: 'solid',
                    borderColor: '#d1d5db',
                    borderWidth: '1px',
                    alignment: 'left',
                },
            },
            tableCellProperties: {
                defaultProperties: {
                    borderStyle: 'solid',
                    borderColor: '#d1d5db',
                    borderWidth: '1px',
                    padding: '8px',
                },
            },
        },
        image: {
            toolbar: [
                'imageStyle:inline',
                'imageStyle:alignLeft',
                'imageStyle:alignCenter',
                'imageStyle:alignRight',
                '|',
                'toggleImageCaption',
                '|',
                'resizeImage',
            ],
            resizeOptions: [
                { name: 'resizeImage:original', label: 'Original', value: null },
                { name: 'resizeImage:25', label: '25%', value: '25' },
                { name: 'resizeImage:50', label: '50%', value: '50' },
                { name: 'resizeImage:75', label: '75%', value: '75' },
            ],
            resizeUnit: '%' as const,
        },
        indentBlock: {
            offset: 40,
            unit: 'px',
        },
        link: {
            defaultProtocol: 'https://',
            decorators: {
                openInNewTab: {
                    mode: 'automatic' as const,
                    callback: (url: string) => url.startsWith('http://') || url.startsWith('https://'),
                    attributes: {
                        target: '_blank',
                        rel: 'noopener noreferrer',
                    },
                },
            },
        },
        htmlSupport: {
            allow: [
                { name: /.*/, attributes: true, classes: true, styles: true },
            ],
        },
        placeholder: options.placeholder,
        language: getCurrentLocale(),
    };

    if (options.imageUpload) {
        editorConfig.simpleUpload = {
            uploadUrl: buildUploadUrl(),
            headers: getUploadHeaders(),
        };
    }

    const editor = await CKEDITOR.ClassicEditor.create(element, editorConfig);

    // 높이 설정: 인라인 스타일은 포커스 시 CKEditor 내부에서 초기화될 수 있으므로
    // containerId 기반 CSS 규칙으로 강제 적용 (remount 시 동일 ID로 덮어씀)
    const heightStyleId = `ckeditor5-height-style-${options.containerId}`;
    const existingHeightStyle = document.getElementById(heightStyleId);
    if (existingHeightStyle) {
        existingHeightStyle.textContent = `#${options.containerId} .ck-editor__editable { min-height: ${options.height}px !important; }`;
    } else {
        const heightStyle = document.createElement('style');
        heightStyle.id = heightStyleId;
        heightStyle.textContent = `#${options.containerId} .ck-editor__editable { min-height: ${options.height}px !important; }`;
        document.head.appendChild(heightStyle);
    }

    if (options.readOnly) {
        editor.enableReadOnlyMode('read-only');
    }

    return editor;
}

/**
 * 다국어 탭 UI를 생성합니다.
 */
function createMultilingualTabs(
    container: HTMLElement,
    locales: string[],
    activeLocale: string,
    onTabActivate?: (locale: string) => Promise<void>,
    getEditorData?: (locale: string) => string | null
): { tabsEl: HTMLElement; contentEls: Map<string, HTMLElement>; updateCheckIcons: () => void } {
    const tabsEl = document.createElement('div');
    tabsEl.className = 'ckeditor5-locale-tabs';

    const contentEls = new Map<string, HTMLElement>();

    // 에디터 컨텐츠 영역 먼저 생성
    // CKEditor ClassicEditor.create(element)는 element를 숨기고 nextSibling 위치에
    // .ck-editor를 삽입하므로, wrapper div로 감싸서 display:none을 wrapper에 적용해야
    // 비활성 locale의 에디터가 보이지 않음
    locales.forEach(locale => {
        const wrapperEl = document.createElement('div');
        wrapperEl.dataset.locale = locale;
        if (locale !== activeLocale) {
            wrapperEl.style.cssText = 'display:none;';
        }
        const contentEl = document.createElement('div');
        wrapperEl.appendChild(contentEl);
        contentEls.set(locale, contentEl);
    });

    /**
     * 비활성 탭의 체크 아이콘을 갱신합니다.
     * 에디터에 값이 있으면 체크 아이콘을 표시하고, 없으면 제거합니다.
     */
    const updateCheckIcons = () => {
        if (!getEditorData) return;
        tabsEl.querySelectorAll('button').forEach(btn => {
            const btnLocale = (btn as HTMLButtonElement).dataset.locale;
            if (!btnLocale || btn.classList.contains('is-active')) {
                // 활성 탭은 체크 아이콘 제거
                const existing = btn.querySelector('.ck5-check');
                if (existing) existing.remove();
                return;
            }
            const data = getEditorData(btnLocale);
            const hasValue = Boolean(data?.trim());
            const existing = btn.querySelector('.ck5-check');
            if (hasValue && !existing) {
                const icon = document.createElement('i');
                icon.className = 'fas fa-check ck5-check';
                btn.appendChild(icon);
            } else if (!hasValue && existing) {
                existing.remove();
            }
        });
    };

    // 탭 버튼 생성
    const defaultLocale = locales[0];
    locales.forEach(locale => {
        const isDefault = locale === defaultLocale;
        const isActive = locale === activeLocale;
        const tab = document.createElement('button');
        tab.type = 'button';
        tab.dataset.locale = locale;
        tab.className = [isActive ? 'is-active' : '', isDefault ? 'is-default' : ''].filter(Boolean).join(' ');
        tab.innerHTML = `<i class="fas fa-globe ck5-lang-icon"></i><span>${getLocaleLabel(locale)}</span>${isDefault ? '<span class="ck5-required">*</span>' : ''}`;
        tab.addEventListener('click', async () => {
            // 탭 활성화 전환 (에디터 생성 전에 먼저 실행)
            tabsEl.querySelectorAll('button').forEach(btn => {
                const btnLocale = (btn as HTMLButtonElement).dataset.locale;
                const btnIsActive = btnLocale === locale;
                btn.classList.toggle('is-active', btnIsActive);
            });
            // 에디터 컨텐츠 영역 전환 (새 locale만 표시, 나머지 숨김)
            // wrapperEl(contentEl.parentElement)에 display를 적용해야
            // CKEditor가 삽입한 .ck-editor sibling도 함께 숨겨짐
            contentEls.forEach((el, elLocale) => {
                const wrapperEl = el.parentElement;
                if (wrapperEl) {
                    wrapperEl.style.display = elLocale === locale ? 'block' : 'none';
                }
            });
            // lazy 에디터 생성 (아직 없는 경우, display 전환 후 실행)
            if (onTabActivate) {
                await onTabActivate(locale);
            }
            // 탭 전환 완료 후 체크 아이콘 갱신
            updateCheckIcons();
        });
        tabsEl.appendChild(tab);
    });

    container.appendChild(tabsEl);
    contentEls.forEach(el => {
        if (el.parentElement) {
            container.appendChild(el.parentElement);
        }
    });

    return { tabsEl, contentEls, updateCheckIcons };
}

/**
 * 폼 데이터 업데이트를 위해 G7Core setState를 호출합니다.
 *
 * 테스트를 위해 export 한다 — `hasChanges` 를 본문과 분리해 보내는 계약은 화면에서
 * "저장 버튼이 계속 비활성" 으로만 드러나므로 단위 테스트로 잠근다
 * (`resolveSingleContent` 와 같은 선례).
 *
 * @param name 폼 필드명
 * @param locale 이 값이 속한 로케일 (다국어 모드에서만 경로에 반영)
 * @param value 편집기가 내놓은 HTML
 * @param isMultilingual 다국어 편집기 여부 (`form.{name}.{locale}` vs `form.{name}`)
 * @param tracksChanges 이 입력을 폼 변경(`_local.hasChanges`)으로 칠지 여부. 기본 `true`
 * @return void
 */
export function syncToForm(
    name: string,
    locale: string,
    value: string,
    isMultilingual: boolean,
    tracksChanges: boolean = true
): void {
    // setData() 호출 중 change:data 재진입 방지
    if (isSyncSuppressed()) return;

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state?.setLocal) {
        return;
    }

    // `hasChanges` 는 아래 본문 배치와 함께 보내면 안 된다.
    //
    // 그 배치는 `render: false` + `selfManaged: true` 라 React 렌더를 일으키지 않는다(성능 —
    // 37,000+ 바인딩 재평가 회피). 그런데 저장 버튼의 활성 조건이 `{{!_local.hasChanges || ...}}`
    // 처럼 이 플래그를 읽는 화면에서는, 플래그가 저장소 B 에만 들어가고 React 가 다시 그리지
    // 않아 **버튼이 계속 비활성으로 남는다** — 본문만 고친 운영자는 저장 자체를 할 수 없다.
    // (관리자 게시글 수정 화면에서 실측 재현. 제목 등 다른 입력을 함께 건드리면 그 입력의
    //  자동바인딩이 렌더를 일으켜 가려지므로, 화면·보드에 따라 드러나기도 하고 아니기도 한다.)
    //
    // 이 플래그는 스칼라 한 개라 본문과 달리 성능 사유가 없다. 그래서 렌더를 일으키는 일반
    // setLocal 로 분리하되, **false → true 로 처음 넘어갈 때만** 보낸다. 이미 true 면 건너뛰므로
    // 편집 세션당 추가 렌더는 최대 1회다.
    //
    // 저장 대상이 아닌 편집기(설정 화면의 미리보기 등)는 `tracksChanges:false` 로 이 축에서
    // 빠진다 — 그러지 않으면 시험 입력만으로 [저장] 이 켜져 바뀐 것이 없는데 바뀐 것처럼 보인다.
    // 본문 동기화는 그런 편집기도 그대로 수행한다(미리보기 렌더가 그 값을 읽는다).
    if (tracksChanges && G7Core.state.getLocal?.()?.hasChanges !== true) {
        G7Core.state.setLocal({ hasChanges: true });
    }

    const updates: Record<string, any> = {
        [`form.${name}_mode`]: 'html',
    };

    if (isMultilingual) {
        updates[`form.${name}.${locale}`] = value;
    } else {
        updates[`form.${name}`] = value;
    }

    // G7 표준 debounce + render: false + selfManaged: true (engine-v1.43.0+)
    // - debounce: ActionDispatcher 타이머 인프라 활용, 컴포넌트 언마운트 시 자동 정리
    // - render: false: CKEditor가 자체 DOM을 관리하므로 React 리렌더 불필요
    //   타이핑 중 전체 폼 트리 리렌더 방지 (37,000+ 바인딩 평가 제거)
    // - selfManaged: true: engine-v1.43.0 자동 승격 예외 명시. HtmlEditor 내부 Textarea가
    //   form.content에 자동바인딩되어 레지스트리에 등록되지만, CKEditor는 자체 DOM 관리이므로
    //   render:false를 유지해야 성능 이점(37,000+ 바인딩 재평가 방지) 보존.
    // - 저장 시: flushPendingDebounceTimers → globalStateUpdater({}) 강제 렌더 1회
    G7Core.state.setLocal(updates, {
        debounce: 300,
        debounceKey: `ckeditor-sync-${name}`,
        render: false,
        selfManaged: true,
    });
}


/**
 * initEditor 핸들러
 *
 * onMount 시 CKEditor5 인스턴스를 초기화합니다.
 * multilingual=true 시 locale별 탭 UI와 별도 인스턴스를 생성합니다.
 *
 * @param params 핸들러 파라미터
 * @param context 액션 컨텍스트
 */
/**
 * 초기 본문 해석이 **호출 시점의** 폼 상태를 읽어야 하는 이유.
 *
 * `params.content` 는 컴포넌트가 처음 렌더될 때 캡처된 값이다. 작성 화면은 그 값이 비어 있는
 * 것이 정상이지만, **수정 화면**은 폼 데이터(`.../posts/form-data`)가 도착하기 전에 첫 렌더가
 * 일어나므로 캡처값이 빈 문자열이 된다. 편집기 확보(`ensureCkeditorGlobal`)는 await 이라
 * 그 사이에 데이터가 도착하는데, 캡처값을 그대로 쓰면 도착한 본문을 보지 못한다.
 *
 * 빈 문자열은 `'{{'` 로 시작하지 않으므로 "해석된 값" 처럼 보인다 — 그래서 빈 값도 미해석과
 * 동일하게 취급해 라이브 상태로 내려가야 한다. 이 함수들을 쓰는 쪽은 **await 이후에** 부르는
 * 것이 중요하다. 그렇지 않으면 도착 전 상태를 다시 읽을 뿐이다.
 *
 * @param params 핸들러 파라미터
 * @param name 폼 필드명
 * @return 단일 모드 초기 본문 (없으면 빈 문자열)
 */
export function resolveSingleContent(params: Record<string, any>, name: string): string {
    const G7Core = (window as any).G7Core;
    const rawContent = typeof params.content === 'string' ? params.content : '';
    const isUnresolvedExpression = rawContent.startsWith('{{') && rawContent.endsWith('}}');
    let initialContent = isUnresolvedExpression ? '' : rawContent;

    if (!initialContent && name) {
        const localState = G7Core?.state?.getLocal?.() ?? {};
        const formValue = localState?.form?.[name];
        if (typeof formValue === 'string' && formValue) {
            initialContent = formValue;
        }
    }

    return initialContent;
}

/**
 * 다국어 초기 본문 맵을 해석합니다. 해석 시점 규칙은 {@link resolveSingleContent} 와 같다.
 *
 * @param params 핸들러 파라미터
 * @param name 폼 필드명
 * @return 로케일 → 본문 맵 (없으면 빈 객체)
 */
export function resolveContentMap(params: Record<string, any>, name: string): Record<string, string> {
    const G7Core = (window as any).G7Core;
    let contentMap: Record<string, string> = {};

    try {
        if (typeof params.content === 'string' && params.content.startsWith('{') && !params.content.startsWith('{{')) {
            contentMap = JSON.parse(params.content);
        } else if (typeof params.content === 'object' && params.content !== null) {
            contentMap = params.content as unknown as Record<string, string>;
        }
    } catch {
        contentMap = {};
    }

    // contentMap이 비어있으면 G7Core.state.getLocal().form[name]에서 폴백
    if (Object.keys(contentMap).length === 0 && name) {
        const localState = G7Core?.state?.getLocal?.() ?? {};
        const formValue = localState?.form?.[name];
        // 배열은 content 미설정 상태(API 기본값 [])이므로 무시
        if (formValue && typeof formValue === 'object' && !Array.isArray(formValue)) {
            contentMap = formValue as Record<string, string>;
        } else if (typeof formValue === 'string' && formValue) {
            // 단일 문자열인 경우 현재 로케일에 매핑
            contentMap = { [getCurrentLocale()]: formValue };
        }
        // form[name]도 비어있으면 _global.selectedItem[name]에서 추가 폴백
        if (Object.keys(contentMap).length === 0) {
            const globalState = G7Core?.state?.get?.() ?? {};
            const selectedItem = globalState?._global?.selectedItem ?? globalState?.selectedItem;
            const selectedValue = selectedItem?.[name];
            if (selectedValue && typeof selectedValue === 'object' && !Array.isArray(selectedValue)) {
                contentMap = selectedValue as Record<string, string>;
            }
        }
    }

    return contentMap;
}

export async function initEditorHandler(
    action: { params?: InitEditorParams; [key: string]: any },
    _context: unknown
): Promise<void> {
    // ActionHandler 시그니처: (action: ActionDefinition, context: ActionContext)
    // action.params에 실제 파라미터가 있음 (ActionDispatcher에서 resolveParams 후 전달)
    const params: InitEditorParams = (action.params as InitEditorParams) ?? {};
    // 컨테이너 ID: html-editor.json의 props.id와 동일한 패턴으로 계산
    // 동일 페이지에 에디터가 여러 개 있을 때 충돌 방지
    const editorName = (params.name as string) ?? 'content';
    const containerId = `ckeditor5-${editorName}`;

    // 기존 인스턴스가 있으면 먼저 정리 (외부 재동기화 타이머도 함께 멈춘다)
    stopExternalSyncs(containerId);
    const existing = editorInstances.get(containerId);
    if (existing && existing.size > 0) {
        const destroyPromises: Promise<void>[] = [];
        existing.forEach((editor: any) => {
            if (editor && typeof editor.destroy === 'function') {
                destroyPromises.push(editor.destroy().catch(() => {}));
            }
        });
        await Promise.allSettled(destroyPromises);
        editorInstances.delete(containerId);
    }

    // 컨테이너 DOM 찾기
    const container = document.getElementById(containerId);
    if (!container) {
        console.warn(`[sirsoft-ckeditor5] 컨테이너 엘리먼트를 찾을 수 없습니다: #${containerId}`);
        return;
    }

    // CKEditor CSS 동적 로드 및 다크 모드 오버라이드 적용
    loadCkeditorCss();
    loadTabCss();
    applyDarkModeOverride();
    watchDarkMode();

    // 번역 파일 로드 (에디터 생성 전 완료되어야 함)
    await loadCkeditorTranslations(getCurrentLocale());

    // 플러그인 설정을 G7Core 전역 상태에서 직접 읽기 (params 바인딩 실패 대비 폴백)
    const G7Core = (window as any).G7Core;
    const globalState = G7Core?.state?.get() || {};
    // getGlobalState()는 { sidebarOpen, plugins, ... } 구조를 반환
    // _global 래퍼가 있는 경우와 없는 경우 모두 처리
    const pluginSettings = globalState._global?.plugins?.['sirsoft-ckeditor5']
        ?? globalState.plugins?.['sirsoft-ckeditor5']
        ?? {};

    // 파라미터 파싱 (params 바인딩 성공 시 우선 사용, 실패(undefined) 시 플러그인 설정 폴백)
    const name = params.name ?? 'content';
    const isMultilingual = params.multilingual === true || params.multilingual === 'true';
    const isReadOnly = params.readOnly === true || params.readOnly === 'true';
    const withImageUpload = params.imageUpload !== undefined
        ? (params.imageUpload === true || params.imageUpload === 'true')
        : (pluginSettings.imageUpload === true || pluginSettings.imageUpload === 'true');
    const placeholder = params.placeholder ?? '';
    const height = params.height !== undefined ? (Number(params.height) || 400) : (Number(pluginSettings.editorHeight) || 400);
    const toolbarType = (params.toolbar !== undefined ? (params.toolbar as string) : (pluginSettings.toolbar as string)) ?? 'standard';
    // 기본은 true — 저장 대상이 아닌 편집기만 레이아웃이 명시적으로 끈다.
    // 미평가 표현식(`{{...}}`)이 그대로 들어와도 truthy 문자열이라 기본값 쪽으로 떨어진다.
    const tracksChanges = !(params.trackChanges === false || params.trackChanges === 'false');

    // content 파싱: 다국어 시 객체, 단일 시 문자열
    // extensionPointProps를 통해 전달되는 경우 표현식이 평가되지 않고 raw 문자열로 전달될 수 있음
    // 이 경우 G7Core.state.getLocal().form[name] 폴백 사용
    let contentMap: Record<string, string> = isMultilingual ? resolveContentMap(params, name) : {};

    // 편집기 본체 확보 — 실패하면 평문 입력으로 내려앉는다.
    // `html-editor.json` 이 `mode: "replace"` 라 코어 HtmlEditor 도 렌더되지 않으므로,
    // 여기서 폴백을 세우지 않으면 사용자에게는 빈 div 만 남아 글을 쓸 수 없다.
    if (!(await ensureCkeditorGlobal())) {
        // 자산 확보 시도(await) 동안 폼 데이터가 도착했을 수 있으므로 **여기서 다시** 해석한다.
        // await 이전에 캡처된 값을 그대로 쓰면 수정 화면에서 폴백이 빈 채로 서고, 폴백이
        // `form.{name}` 에 빈 문자열을 써 넣어 저장된 본문이 조용히 지워진다.
        const fallbackOptions = {
            container,
            name,
            height,
            readOnly: isReadOnly,
            placeholder,
            trackChanges: tracksChanges,
            multilingual: isMultilingual,
            locales: isMultilingual ? getSupportedLocales() : undefined,
            activeLocale: getCurrentLocale(),
            contentMap: isMultilingual ? resolveContentMap(params, name) : undefined,
            initialContent: isMultilingual ? undefined : resolveSingleContent(params, name),
        };

        renderTextareaFallback(fallbackOptions);
        notifyEditorAssetFailure(
            `ckeditor5-editor:${containerId}`,
            t('editor.asset.label', '편집기'),
            () => retryEditorInit(action, _context, container, fallbackOptions.multilingual, name),
            t('editor.asset.fallback_notice', '편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다.')
        );

        return;
    }

    const instanceMap = new Map<string, unknown>();
    editorInstances.set(containerId, instanceMap);

    const editorOptions = {
        placeholder,
        readOnly: isReadOnly,
        imageUpload: withImageUpload,
        height,
        toolbar: toolbarType,
        containerId,
    };

    if (isMultilingual) {
        const locales = getSupportedLocales();
        const activeLocale = getCurrentLocale();

        // createLocaleEditor ↔ createMultilingualTabs 순환 참조를 끊기 위한 ref
        const contentElsRef = { current: new Map<string, HTMLElement>() };
        const updateCheckIconsRef = { current: () => {} };

        // 에디터 인스턴스 생성 헬퍼
        const createLocaleEditor = async (locale: string) => {
            if (instanceMap.has(locale)) return; // 이미 생성된 경우 skip
            const contentEl = contentElsRef.current.get(locale);
            if (!contentEl) return;

            const initialContent = contentMap[locale] ?? '';

            try {
                const editor = await createEditorInstance(contentEl, {
                    ...editorOptions,
                    initialContent,
                });

                instanceMap.set(locale, editor);

                // 초기화 중 change:data 이벤트 억제 (CKEditor 내부 initialData 처리 시 발생 가능)
                let isInitializing = true;

                // 단일 모드와 같은 이유 — 같은 레이아웃 안에서 편집 대상이 바뀌어도
                // onMount 가 다시 발화하지 않으므로 로케일마다 외부 값을 지켜본다.
                const externalSync = attachExternalContentSync(editor as any, {
                    name,
                    locale,
                    multilingual: true,
                });
                registerExternalSync(containerId, externalSync);

                (editor as any).model.document.on('change:data', () => {
                    if (isInitializing) return;
                    if (!externalSync.shouldEmit()) return;
                    const html = (editor as any).getData();
                    externalSync.noteEmitted(html);
                    syncToForm(name, locale, html, true, tracksChanges);
                    updateCheckIconsRef.current();
                });

                isInitializing = false;

                // 초기 콘텐츠 재동기화 제거 — initLocal이 이미 form state에 반영
                // mode만 설정 (create 모드에서 _mode 미설정 방지)
                // hasChanges는 설정하지 않음 (사용자 변경 아님)
                if (name) {
                    const G7Core = (window as any).G7Core;
                    const local = G7Core?.state?.getLocal?.() ?? {};
                    if (local.form?.[`${name}_mode`] !== 'html') {
                        G7Core.state.setLocal({ [`form.${name}_mode`]: 'html' });
                    }
                }
            } catch (err) {
                console.error(`[sirsoft-ckeditor5] 에디터 초기화 오류 (locale: ${locale}):`, err);
            }
        };

        // instanceMap에서 에디터 데이터를 반환하는 콜백
        const getEditorData = (locale: string): string | null => {
            const editor = instanceMap.get(locale);
            if (!editor) return null;
            return (editor as any).getData?.() ?? null;
        };

        // 탭 클릭 시 lazy 생성 콜백을 전달하여 탭 UI 생성
        const { contentEls, updateCheckIcons } = createMultilingualTabs(container, locales, activeLocale, createLocaleEditor, getEditorData);
        contentElsRef.current = contentEls;
        updateCheckIconsRef.current = updateCheckIcons;

        // 활성 locale 에디터 먼저 생성
        await createLocaleEditor(activeLocale);

        // 활성 locale 조차 세우지 못했으면 자산은 있어도 편집기를 쓸 수 없는 상태다.
        // 나머지 locale 을 계속 시도해봐야 같은 이유로 실패하므로 평문 입력으로 내려앉는다.
        if (!instanceMap.has(activeLocale)) {
            editorInstances.delete(containerId);
            renderTextareaFallback({
                container,
                name,
                height,
                readOnly: isReadOnly,
                placeholder,
                multilingual: true,
                locales,
                activeLocale,
                contentMap,
                trackChanges: tracksChanges,
            });
            notifyEditorAssetFailure(
                `ckeditor5-editor:${containerId}`,
                t('editor.asset.label', '편집기'),
                () => retryEditorInit(action, _context, container, true, name),
                t('editor.asset.fallback_notice', '편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다.')
            );

            return;
        }

        // 나머지 locale 에디터 백그라운드에서 미리 생성 (탭 전환 시 딜레이 제거)
        // setTimeout으로 지연하여 활성 에디터 초기 입력 반응성 확보
        setTimeout(() => {
            locales.filter(l => l !== activeLocale).forEach(locale => {
                createLocaleEditor(locale);
            });
        }, 2000);

    } else {
        // 단일 에디터 모드
        // params.content가 빈 문자열이거나 미평가 표현식({{...}})인 경우
        // onMount 타이밍 문제로 _local.form이 아직 미적용된 것일 수 있음
        // G7Core.state.getLocal()로 현재 상태를 직접 읽어 폴백
        const initialContent = resolveSingleContent(params, name);
        const editorEl = document.createElement('div');
        container.appendChild(editorEl);

        try {
            const editor = await createEditorInstance(editorEl, {
                ...editorOptions,
                initialContent,
            });

            const singleLocale = getCurrentLocale();
            instanceMap.set(singleLocale, editor);

            // 초기화 중 change:data 이벤트 억제 (CKEditor 내부 initialData 처리 시 발생 가능)
            let isInitializing = true;

            // change:data 이벤트 → 폼 상태 동기화 (디바운스는 setLocal 내부에서 처리)
            // 같은 레이아웃 안에서 편집 대상이 바뀌면(글 A 수정 → 글 B 수정) onMount 가
            // 다시 발화하지 않는다. 그 창에서 편집기 내용이 폼으로 새는 것을 막고, 새 본문이
            // 도착하면 편집기를 다시 세운다.
            const externalSync = attachExternalContentSync(editor as any, {
                name,
                locale: singleLocale,
                multilingual: false,
            });
            registerExternalSync(containerId, externalSync);

            (editor as any).model.document.on('change:data', () => {
                if (isInitializing) return;
                if (!externalSync.shouldEmit()) return;
                const html = (editor as any).getData();
                externalSync.noteEmitted(html);
                syncToForm(name, singleLocale, html, false, tracksChanges);
            });

            isInitializing = false;

            // 초기값 강제 설정 (initialData가 무시되는 경우 대비)
            if (initialContent) {
                const currentData = (editor as any).getData();
                if (!currentData || currentData.trim() === '') {
                    (editor as any).setData(initialContent);
                }
                // 초기 콘텐츠 재동기화 제거 — initLocal이 이미 form state에 반영
            }

            // mode만 설정 (create 모드에서 _mode 미설정 방지)
            if (name) {
                const G7Core = (window as any).G7Core;
                const local = G7Core?.state?.getLocal?.() ?? {};
                if (local.form?.[`${name}_mode`] !== 'html') {
                    G7Core.state.setLocal({ [`form.${name}_mode`]: 'html' });
                }
            }

            // 단일 에디터는 페이지 이동 없이 form이 교체되는 패턴이 없으므로
            // 외부 상태 변경 polling 불필요
        } catch (err) {
            console.error('[sirsoft-ckeditor5] 에디터 초기화 오류:', err);
            editorInstances.delete(containerId);

            // 편집기를 세우지 못했으므로 평문 입력으로 내려앉는다 (빈 div 로 두지 않는다)
            renderTextareaFallback({
                container,
                name,
                height,
                readOnly: isReadOnly,
                placeholder,
                multilingual: false,
                activeLocale: getCurrentLocale(),
                initialContent,
                trackChanges: tracksChanges,
            });
            notifyEditorAssetFailure(
                `ckeditor5-editor:${containerId}`,
                t('editor.asset.label', '편집기'),
                () => retryEditorInit(action, _context, container, false, name),
                t('editor.asset.fallback_notice', '편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다.')
            );
        }
    }
}
