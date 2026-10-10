import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Editor, { Monaco, loader } from '@monaco-editor/react';
import type * as monaco from 'monaco-editor';
import { getJSONLanguageDefaults } from '../../config/monaco.config';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/**
 * 동봉한 Monaco 버전 (`dist/vendor/monaco-editor/{VERSION}/`)
 *
 * 올릴 때는 AGENTS.md 「Monaco 편집기 확보 계약」을 함께 읽는다 — 이 판은 로더와
 * 호환되지 않아 `window.monaco` 선심기에 의존한다(S3-D12).
 */
const MONACO_VERSION = '0.56.0';

/** 이 템플릿 식별자 */
const TEMPLATE_ID = 'sirsoft-admin_basic';

/** 자산 실패 안내 식별자 */
const FAILURE_ID = 'monaco-editor';

/** 편집기가 쓰는 줄바꿈 — 서버가 마크업을 언제나 이 문자로 돌려준다 */
const LINE_FEED = '\n';

/**
 * 모델의 줄바꿈을 LF 로 맞춥니다.
 *
 * `getEOL`/`setEOL` 이 없는 모델(테스트 대역·축약 구현)에서는 아무것도 하지 않는다 — 줄바꿈
 * 고정은 편의 장치이고, 여기서 예외가 나면 편집기 자체가 마운트되지 못한다.
 *
 * @param model 대상 모델 (없을 수 있다)
 * @param monacoInstance monaco 네임스페이스
 * @returns void
 */
function applyLineFeed(
  model: monaco.editor.ITextModel | null | undefined,
  monacoInstance: Monaco | null
): void {
  if (!model || !monacoInstance) {
    return;
  }

  if (typeof model.getEOL !== 'function' || typeof model.setEOL !== 'function') {
    return;
  }

  if (model.getEOL() !== LINE_FEED) {
    model.setEOL(monacoInstance.editor.EndOfLineSequence.LF);
  }
}

/**
 * Monaco 로더가 동봉본을 바라보도록 1회 설정합니다.
 *
 * `@monaco-editor/react` 는 기본적으로 Monaco 를 외부 CDN 에서 받는다. 그러면 폐쇄망에서
 * 레이아웃 코드 편집 화면이 통째로 뜨지 않으므로, 템플릿이 함께 담은 사본을 가리키게 한다.
 *
 * AMD 로더는 `paths.vs` 뒤에 파일 경로를 이어 붙이므로 **디렉토리 접두**가 필요하다 —
 * 그래서 `G7Core.asset.templateDir` 을 쓴다(정적 게시본 우선).
 *
 * @returns bool 설정에 성공했으면 true
 */
function configureMonacoLoader(): boolean {
    const asset = (window as any)?.G7Core?.asset;

    if (typeof asset?.templateDir !== 'function') {
        return false;
    }

    loader.config({
        paths: { vs: asset.templateDir(TEMPLATE_ID, `vendor/monaco-editor/${MONACO_VERSION}/vs`) },
    });

    return true;
}

/**
 * 0.56.0 의 `editor.main` 을 구 로더가 읽는 모양으로 이어 줍니다.
 *
 * `@monaco-editor/loader@1.6.x` 는 인스턴스를 **`editor.main` 의 `m` 속성**에서 꺼낸다:
 *
 * ```js
 * require(['vs/editor/editor.main'], function (exports) {
 *   var monaco = exports.m;      // 0.56.0 은 m 을 내보내지 않는다 → undefined
 *   state.resolve(monaco);       // undefined 로 resolve 된다
 * });
 * ```
 *
 * 0.56.0 은 `m` 대신 `editor`·`languages`·`json` 을 **최상위로** 내보낸다(`e.m=` 출현 0회).
 * 그래서 로더는 `undefined` 를 monaco 인스턴스로 저장하고, `<Editor>` 는 인스턴스를 영영
 * 받지 못해 **`loading` 표시("편집기를 불러오는 중...")에서 멈춘다.**
 *
 * **이 실패는 조용하다** — AMD 요청은 전부 200 이고, `window.monaco.editor.create` 도 정상
 * 함수로 존재하며, 콘솔 오류도 0건이다. 자산·권한·경로를 아무리 재도 드러나지 않는다.
 * 유일한 관측값은 `monaco.editor.getModels().length === 0`(= 편집기가 한 번도 생성되지
 * 않음)이다(2026-09-16 실측, S3-D12).
 *
 * `editor.main` 의 `m` 을 뒤늦게 채우는 것으로는 **고쳐지지 않는다** — 로더의 `wrapperPromise`
 * 는 1회만 resolve 되므로, 이미 `undefined` 로 결정된 뒤에 `m` 을 채워도 값이 바뀌지 않는다
 * (2026-09-16 실측: `m` 을 채웠는데도 `getModels()` 가 계속 0 이었다).
 *
 * 그래서 **로더보다 먼저** 우리가 AMD 로더 스크립트를 넣고 Monaco 를 받아 `window.monaco` 에
 * 심는다. 로더의 `init()` 에는 「`window.monaco.editor` 가 이미 있으면 그것을 쓴다」는 경로가
 * 있어, 그 경로를 타면 `m` 을 읽지 않고 정상 인스턴스로 resolve 된다.
 */
function bridgeEditorMainExports(): Promise<void> {
    // 가드는 **`window` 에** 둔다 — 이 번들은 화면마다 따로 평가될 수 있어(SPA 확장 번들이
    // 여러 벌 로드된다) 모듈 수준 변수로는 같은 판에서 두 번 도는 것을 막지 못한다.
    // 실측(2026-09-16): 모듈 변수 가드만 뒀을 때 `loader.js` 스크립트 태그가 **3개**까지
    // 늘었고, AMD 로더가 두 번 실행되어 `_amdLoaderGlobal` 재선언으로 require 가 죽었다.
    const w = window as any;

    if (!w.__g7MonacoPriming) {
        w.__g7MonacoPriming = primeMonaco();
    }

    return w.__g7MonacoPriming as Promise<void>;
}

/**
 * 편집기를 그리기 전에 동봉본 Monaco 를 먼저 심어야 하는지 판정합니다.
 *
 * @returns bool 심기를 기다려야 하면 true
 */
function monacoNeedsPriming(): boolean {
    const w = window as any;

    // 이미 심어 뒀으면 기다릴 것이 없다.
    if (w.monaco?.editor) {
        return false;
    }

    // 동봉본 경로를 못 구하면 심을 수 없다 — 라이브러리 기본 경로에 맡긴다.
    if (typeof w.G7Core?.asset?.templateDir !== 'function') {
        return false;
    }

    // 스크립트를 실제로 실행할 수 있는 환경에서만 게이트를 건다. jsdom 처럼 `<script src>`
    // 가 뜨지 않는 곳에서 게이트를 걸면 화면이 「불러오는 중」에 굳는다.
    return typeof document !== 'undefined' && typeof document.head?.appendChild === 'function'
        && !/jsdom/i.test(navigator.userAgent ?? '');
}

/** 선심기가 넣은 AMD 로더 스크립트 표식 — 라이브러리·다른 확장이 넣은 로더와 가른다 */
const PRIME_SCRIPT_ATTR = 'data-g7-monaco-loader';

/** 선심기 제한 — 로더 스크립트를 받기 전(이벤트가 오지 않는 환경 대비) */
const PRIME_SCRIPT_TIMEOUT_MS = 3_000;

/** 선심기 제한 — 로더가 받아져 `editor.main` 을 적재하는 중(느린 자산 서빙 대비 안전망) */
const PRIME_LOADING_TIMEOUT_MS = 60_000;

/**
 * 지금 `loader.init()` 을 부르면 AMD 로더가 두 번 실행되는지 판정합니다.
 *
 * 라이브러리 `init()` 은 `window.monaco.editor` 가 없으면 **자기 `loader.js` 를 또 넣는다**.
 * 선심기가 넣은 로더가 아직 `editor.main` 을 받지 못한 채(느린 네트워크) 제한 시간이 지났을 때
 * 그대로 부르면 로더가 두 벌이 되어 `_amdLoaderGlobal` 재선언으로 그 판의 require 가 죽는다.
 *
 * @returns bool 부르면 로더가 겹치면 true
 */
function initWouldInjectSecondLoader(): boolean {
    const w = window as any;

    if (w.monaco?.editor) {
        return false;
    }

    return typeof document !== 'undefined'
        && document.querySelector(`script[${PRIME_SCRIPT_ATTR}]`) !== null;
}

/**
 * 동봉본 Monaco 를 직접 받아 `window.monaco` 에 심습니다.
 *
 * @returns Promise 심기가 끝나면(또는 포기하면) resolve 된다
 */
function primeMonaco(): Promise<void> {
    const paths = (window as any)?.G7Core?.asset?.templateDir?.(
        TEMPLATE_ID,
        `vendor/monaco-editor/${MONACO_VERSION}/vs`,
    );

    if (typeof paths !== 'string') {
        return Promise.resolve();
    }

    // AMD 로더는 **한 번만** 실행돼야 한다 — 두 번 넣으면 `_amdLoaderGlobal` 재선언으로
    // SyntaxError 가 나고 그 판의 require 가 통째로 죽는다(2026-09-16 실측).
    // 그래서 라이브러리가 이미 넣었으면 그 로더를 그대로 쓰고, 아직이면 우리가 넣는다.
    return new Promise<void>((resolve) => {
        // 심기는 **반드시 끝나야 한다** — 게이트가 여기 걸려 있어서, 영영 resolve 되지 않으면
        // 화면이 「불러오는 중」에 굳는다. 자산이 없거나 스크립트 이벤트가 오지 않는 환경
        // (jsdom·폐쇄망 실패)에서도 제한 시간 뒤 통과시키고, 그 뒤 판정은 `<Editor>` 의
        // 실패 경로(폴백 textarea)가 맡는다.
        let settled = false;
        let deadline: number | undefined;
        const done = () => {
            if (!settled) {
                settled = true;
                // 끝난 심기의 제한 타이머는 남길 이유가 없다 — 성공·errback 으로 끝나도 60초 타이머가 남았다.
                window.clearTimeout(deadline);
                resolve();
            }
        };

        // 3초 제한은 로더 스크립트조차 받지 못하는(이벤트가 오지 않는) 환경용이다. 로더가 받아져
        // `editor.main` 을 요청한 뒤에는 적재가 진행 중이므로 긴 안전 제한으로 바꾸고, 적재 실패는
        // AMD errback 으로 바로 끝낸다 — 확장자를 가로채는 서버에서 정적 게시본이 없으면 청크가 PHP 를
        // 거쳐 개당 약 0.7초씩 와 3초를 넘기는데, 그때 끊으면 다 받은 Monaco 를 버리고 폴백으로 떨어진다.
        deadline = window.setTimeout(done, PRIME_SCRIPT_TIMEOUT_MS);

        const adopt = (): boolean => {
            const amdRequire = (window as any)?.require;

            if (typeof amdRequire !== 'function' || typeof amdRequire.config !== 'function') {
                return false;
            }

            window.clearTimeout(deadline);
            deadline = window.setTimeout(done, PRIME_LOADING_TIMEOUT_MS);

            amdRequire.config({ paths: { vs: paths } });

            amdRequire(['vs/editor/editor.main'], (main: any) => {
            // 0.56 은 최상위로 내보낸다 — `m` 을 쓰는 구 로더를 위해 같은 객체를 얹어 둔다.
                const instance = main?.editor ? main : main?.m;

                if (instance?.editor) {
                    if (!instance.m) {
                        try {
                            instance.m = instance;
                        } catch {
                            // 봉인된 판이면 그대로 둔다 — window.monaco 심기만으로 충분하다.
                        }
                    }

                    if (!(window as any).monaco) {
                        (window as any).monaco = instance;
                    }
                }

                done();
            }, () => {
                // 적재 실패 — 긴 안전 제한을 기다리지 않고 끝낸다(판정은 `<Editor>` 실패 경로가 맡는다).
                done();
            });

            return true;
        };

        if (adopt()) {
            return;
        }

        // 이미 누군가 로더 스크립트를 넣어 두었으면 그것이 뜨기를 기다린다 — 또 넣으면
        // `_amdLoaderGlobal` 이 재선언되어 그 판의 require 가 통째로 죽는다.
        const existing = document.querySelector('script[src*="/vs/loader.js"]');

        if (existing) {
            let tries = 0;
            const timer = window.setInterval(() => {
                if (adopt() || ++tries > 200) {
                    window.clearInterval(timer);

                    if (tries > 200) {
                        done();
                    }
                }
            }, 50);

            return;
        }

        const script = document.createElement('script');

        script.src = `${paths}/loader.js`;
        script.async = true;
        script.setAttribute(PRIME_SCRIPT_ATTR, '1');
        script.addEventListener('load', () => {
            if (!adopt()) {
                done();
            }
        });
        script.addEventListener('error', done);

        document.head.appendChild(script);
    });
}

/** 로더 설정은 앱 수명 동안 1회만 유효하다 (초기화 후 변경 불가) */
let loaderConfigured = false;

/**
 * 서버 컴파일 마커를 Monaco 모델에 찍습니다 (없으면 지운다).
 *
 * 좌표는 1-base 라인·컬럼이다. 끝 좌표가 없으면 그 줄 끝까지 물결을 긋는다 — 서버는 태그의 시작
 * 좌표만 알고, 한 글자짜리 물결은 눈에 띄지 않는다. 좌표가 0 이면(문서 전체 경고) 1행 1열로 접는다.
 *
 * 모델 줄 수를 넘는 마커는 찍지 않고 `false` 를 돌려준다 — 마커가 모델에 내용이 들어오기 전에 도착하면(빈 모델·
 * 직전 문서) `getLineMaxColumn()` 이 「Illegal value for lineNumber」 를 던지고, 그 예외가 렌더 경계까지 올라가
 * 코드 작업 영역 전체가 그려지지 않았다(2026-10-01 실측). 보류분은 모델 내용이 바뀔 때 다시 찍는다.
 * 모델이 이미 지금 받은 값을 담고 있으면(`clampOverflow`) 넘는 마커는 이 문서의 좌표다 — 파일 끝 다음 줄을
 * 가리키는 오류처럼 — 그래서 보류하지 않고 마지막 줄 첫 칸으로 접어 찍는다. 보류하면 끝내 보이지 않고, 타이핑마다
 * 다시 찍혀 편집을 따라 옮긴 다른 마커까지 서버 좌표로 되돌아간다.
 *
 * @param editor 편집기 인스턴스 (미마운트면 아무것도 하지 않는다)
 * @param monacoInstance Monaco 네임스페이스
 * @param markers 찍을 마커
 * @param clampOverflow 모델 줄 수를 넘는 마커를 보류하지 않고 마지막 줄로 접을지
 * @returns 마커를 전부 찍었으면 true, 모델 줄 수를 넘어 보류한 마커가 있으면 false
 */
function applyMarkers(
  editor: monaco.editor.IStandaloneCodeEditor | null,
  monacoInstance: Monaco | null,
  markers: CodeEditorMarker[] | undefined,
  clampOverflow = false,
): boolean {
  const model = editor?.getModel?.();

  if (!editor || !monacoInstance?.editor?.setModelMarkers || !model) {
    return true;
  }

  const severity = monacoInstance.MarkerSeverity ?? { Error: 8, Warning: 4 };
  const lastLine = typeof model.getLineCount === 'function' ? model.getLineCount() : Number.MAX_SAFE_INTEGER;
  const inRange = clampOverflow
    ? (markers ?? [])
    : (markers ?? []).filter((marker) => Math.max(1, marker.line || 1) <= lastLine);

  monacoInstance.editor.setModelMarkers(
    model,
    MARKER_OWNER,
    inRange.map((marker) => {
      const overflow = Math.max(1, marker.line || 1) > lastLine;
      const line = Math.min(Math.max(1, marker.line || 1), lastLine);
      const column = overflow ? 1 : Math.max(1, marker.column || 1);
      const endLine = Math.min(Math.max(line, marker.endLine ?? line), lastLine);
      const lineLength = typeof model.getLineMaxColumn === 'function' ? model.getLineMaxColumn(endLine) : column + 1;

      return {
        startLineNumber: line,
        startColumn: column,
        endLineNumber: endLine,
        endColumn: overflow ? Math.max(column + 1, lineLength) : (marker.endColumn ?? Math.max(column + 1, lineLength)),
        message: marker.message,
        severity: marker.severity === 'error' ? severity.Error : severity.Warning,
        source: MARKER_OWNER,
      };
    }),
  );

  return inRange.length === (markers ?? []).length;
}

/**
 * 모델이 지금 받은 값을 이미 담고 있는지 판정합니다 (줄바꿈 표기 차이는 무시).
 *
 * @param editor 편집기 인스턴스
 * @param value 컴포넌트가 받은 값
 * @returns 모델 내용이 받은 값과 같으면 true, 판정할 수 없으면 false
 */
function modelHoldsValue(editor: monaco.editor.IStandaloneCodeEditor | null, value: string | undefined): boolean {
  const model = editor?.getModel?.();

  if (!model || typeof model.getValue !== 'function' || typeof value !== 'string') {
    return false;
  }

  return model.getValue().replace(/\r\n/g, '\n') === value.replace(/\r\n/g, '\n');
}

/**
 * 서버 컴파일이 낸 오류·경고를 편집기 줄에 물결로 찍는 마커.
 *
 * `.g7.html` 검증은 Monaco 의 `html` 모드가 아니라 서버 컴파일러가 담당한다 — 커스텀 태그를 브라우저가
 * 알 수 없으므로 마커는 컴파일 응답(`compile_error`·`markup_warnings`)에서 온다.
 */
export interface CodeEditorMarker {
  line: number;
  column: number;
  endLine?: number;
  endColumn?: number;
  message: string;
  severity: 'error' | 'warning';
}

/** 편집기 안에서 잡을 키 조합 1건 — `CodeWorkspace` 가 단축키 SSoT 를 옮겨 넘긴다. */
export interface CodeEditorCommand {
  /** 소문자 단일 키 (`s`·`p`·`z`·`\\`) */
  key: string;
  mod?: boolean;
  shift?: boolean;
  alt?: boolean;
  /** 눌렸을 때 실행 */
  run: () => void;
}

/** 커서를 옮길 좌표 (1-base) — 검색 결과·오류 패널 클릭이 쓴다. */
export interface CodeEditorReveal {
  line: number;
  column?: number;
  /** 같은 좌표를 다시 눌렀을 때도 이동하게 하는 일련번호 */
  nonce?: number;
}

/** 마커 소유자 식별자 — 같은 모델의 다른 마커(JSON 스키마 등)와 구분한다 */
const MARKER_OWNER = 'g7-markup';

export interface CodeEditorProps {
  value: string;
  onChange?: (event: { target: { value: string } }) => void;
  language?: string;
  height?: string;
  readOnly?: boolean;
  /**
   * `auto` 면 `<html class="dark">` 를 따라간다.
   *
   * 종전 기본값은 `vs-dark` 하드코딩이라 라이트 모드에서도 편집기만 검은 판이었다.
   */
  theme?: 'vs-dark' | 'vs-light' | 'auto';
  /** 서버 컴파일 오류·경고 마커 (없거나 빈 배열이면 물결을 지운다) */
  markers?: CodeEditorMarker[];
  /** 자동 줄바꿈 */
  wordWrap?: boolean;
  /** 글자 크기 (12~20) */
  fontSize?: number;
  /**
   * 파일별 모델 식별자.
   *
   * `<Editor path>` 로 넘어가 Monaco 모델을 파일마다 따로 잡는다 — 커서·스크롤·되돌리기 스택·
   * 접힘 상태가 **파일별로 따라온다**. 주지 않으면 편집기 하나가 모델 하나를 공유한다(종전 동작).
   *
   * 모델을 직접 만들어 `setModel()` 하지 않는 이유: `@monaco-editor/react` 가 자기 모델을
   * 만들어 붙이므로 밖에서 끼운 모델이 곧바로 밀려난다(브라우저 실측 2026-09-12 — 본문 42,523자가
   * 전역에는 있는데 편집기는 빈 화면이었다). 모델 수명은 라이브러리 한 곳이 쥔다.
   */
  modelKey?: string;
  /** 커서를 옮길 좌표 */
  revealAt?: CodeEditorReveal;
  /** 편집기 안에서도 잡을 단축키 (Monaco 가 선점하는 조합의 재바인딩) */
  commands?: CodeEditorCommand[];
  /** 편집기가 준비되면 1회 호출 */
  onReady?: () => void;
}

/**
 * 문서 루트의 `dark` 클래스를 따라가는 현재 테마를 돌려줍니다.
 *
 * @returns Monaco 테마 이름
 */
function resolveDocumentTheme(): 'vs-dark' | 'vs-light' {
  if (typeof document === 'undefined') {
    return 'vs-light';
  }

  return document.documentElement.classList.contains('dark') ? 'vs-dark' : 'vs-light';
}

export const CodeEditor: React.FC<CodeEditorProps> = ({
  value,
  onChange,
  language = 'json',
  height = '100%',
  readOnly = false,
  theme = 'auto',
  markers,
  wordWrap = false,
  fontSize = 14,
  modelKey,
  revealAt,
  commands,
  onReady,
}) => {
  const editorRef = useRef<monaco.editor.IStandaloneCodeEditor | null>(null);
  const monacoRef = useRef<Monaco | null>(null);
  const [failed, setFailed] = useState(false);
  // `<Editor>` 는 렌더되자마자 자기 effect 에서 `loader.init()` 을 부른다. 심기보다 먼저
  // 불리면 라이브러리가 자기 로더 스크립트를 따로 주입해 AMD 로더가 두 번 실행된다
  // (`_amdLoaderGlobal` 재선언 → 그 판의 require 사망). 그래서 **심기가 끝나기 전에는
  // `<Editor>` 를 렌더하지 않는다.**
  const [primed, setPrimed] = useState(() => !monacoNeedsPriming());

  /**
   * 편집기가 실제로 마운트됐는지.
   *
   * Monaco 는 비동기로 실린다. 그 사이 `readOnly` 같은 옵션이 바뀌면 옵션 반영 effect 는
   * **아직 없는 편집기**에 대고 돌아 아무 일도 하지 않고, 뒤늦게 끝난 마운트가 그때의 옵션을
   * 덮어쓴다. 그래서 권한 없는 계정인데도 편집기가 편집 가능한 채로 떴다(실측 2026-09-12:
   * `readOnly` prop 은 true 인데 Monaco 는 false, 타이핑이 그대로 먹었다).
   * 마운트를 상태로 올려 두면 편집기가 생긴 **뒤에** 현재 옵션이 한 번 더 적용된다.
   */
  const [editorReady, setEditorReady] = useState(false);
  const [documentTheme, setDocumentTheme] = useState<'vs-dark' | 'vs-light'>(() => resolveDocumentTheme());

  // 최신 핸들러를 참조로 들고 있는다 — Monaco 의 `addCommand` 는 등록 시점의 클로저를 붙들기
  // 때문에, 배열을 그대로 캡처하면 탭을 바꾼 뒤에도 옛 파일의 저장 핸들러가 불린다.
  const commandsRef = useRef<CodeEditorCommand[] | undefined>(commands);
  commandsRef.current = commands;

  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;

  // 모델 줄 수를 넘어 보류한 마커가 있는지 — 모델 내용이 바뀌면 그때 다시 찍는다(평소 타이핑에는 다시 찍지 않는다:
  // Monaco 가 편집을 따라 옮긴 마커 위치를 서버 좌표로 되돌리지 않기 위해서다).
  const markersRef = useRef(markers);
  markersRef.current = markers;
  const markersDeferredRef = useRef(false);
  const valueRef = useRef(value);
  valueRef.current = value;

  /**
   * 마커를 찍고 보류 여부를 기억합니다.
   *
   * 모델이 이미 지금 받은 값을 담고 있으면 넘는 마커는 이 문서의 좌표라 보류하지 않고 마지막 줄로 접는다.
   *
   * @returns void
   */
  const placeMarkers = (): void => {
    markersDeferredRef.current = !applyMarkers(
      editorRef.current,
      monacoRef.current,
      markersRef.current,
      modelHoldsValue(editorRef.current, valueRef.current),
    );
  };

  const resolvedTheme = theme === 'auto' ? documentTheme : theme;

  if (!loaderConfigured) {
    loaderConfigured = configureMonacoLoader();
  }

  const editorOptions = useMemo<monaco.editor.IStandaloneEditorConstructionOptions>(
    () => ({
      readOnly,
      minimap: { enabled: false },
      scrollBeyondLastLine: false,
      fontSize,
      lineNumbers: 'on',
      renderWhitespace: 'selection',
      automaticLayout: true,
      wordWrap: wordWrap ? 'on' : 'off',
    }),
    [readOnly, fontSize, wordWrap]
  );

  /**
   * 내가 올렸는데 아직 `value` 로 돌아오지 않은 값들 — 모델 키별로 둔다.
   *
   * 편집기는 제어 컴포넌트라 친 글자가 `onChange` → 부모 상태 → `value` 로 한 바퀴 돈다. 그 사이에
   * 다음 글자가 들어오면 돌아온 `value` 는 **한두 글자 전의 내 값**인데, 라이브러리는 모델과 다르다는
   * 이유로 그 값을 통째로 밀어 넣어 마지막 글자가 사라지고 커서가 문서 끝으로 튄다
   * (실측 2026-10-06: 「9876」 을 연달아 치면 「7」 이 사라지고 「6」 이 문서 끝에 찍혔다).
   * 렌더 시점에 거르는 것으로는 모자란다 — 라이브러리는 값을 **effect 에서** 밀어 넣으므로 커밋과
   * effect 사이에 들어온 글자에도 같은 일이 생긴다(실측: 렌더 시점 비교만 넣은 판에서 그대로 재현).
   * 그래서 `value` 를 라이브러리에 넘기지 않고(`defaultValue` 로 새 모델만 채운다) 아래 effect 가
   * **그 순간의 모델**과 비교해 바깥에서 온 값만 넣는다. 돌아온 값이 이 목록에 있으면 늦게 온 메아리라
   * 모델을 그대로 두고, `value` 가 모델을 따라잡는 순간 목록을 비우므로 그 뒤 바깥에서 오는 값
   * (서버 본문·초안 복원·되돌리기)은 언제나 적용된다.
   */
  const pendingEchoesRef = useRef<{ key: string | undefined; values: string[] }>({ key: modelKey, values: [] });

  if (pendingEchoesRef.current.key !== modelKey) {
    pendingEchoesRef.current = { key: modelKey, values: [] };
  }

  /** 바깥 값을 모델에 넣는 중 — 그 변경은 사람이 친 것이 아니므로 위로 올리지 않는다. */
  const applyingExternalRef = useRef(false);

  const handleChange = useCallback((next: string | undefined) => {
    if (applyingExternalRef.current) {
      return;
    }

    if (next !== undefined) {
      const pending = pendingEchoesRef.current.values;
      pending.push(next);

      // 메아리는 한 바퀴 안에 돌아온다 — 오래된 것까지 쥐고 있을 이유가 없다.
      if (pending.length > 50) {
        pending.splice(0, pending.length - 50);
      }
    }

    if (onChangeRef.current && next !== undefined) {
      // 엔진의 isCustomComponentEvent 경로로 처리되도록 { target: { value } } 패턴 사용
      onChangeRef.current({ target: { value: next } });
    }
  }, []);

  const handleEditorDidMount = (
    editor: monaco.editor.IStandaloneCodeEditor,
    monacoInstance: Monaco
  ) => {
    editorRef.current = editor;
    monacoRef.current = monacoInstance;
    (window as any)?.G7Core?.assets?.clearFailure?.(FAILURE_ID);

    // JSON 스키마 검증 설정
    if (language === 'json') {
      getJSONLanguageDefaults(monacoInstance)?.setDiagnosticsOptions({
        validate: true,
        schemaValidation: 'error',
        allowComments: false,
      });
    }

    editor.updateOptions(editorOptions);
    applyLineFeed(editor.getModel?.(), monacoInstance);
    registerCommands(editor, monacoInstance);
    placeMarkers();
    editor.onDidChangeModelContent?.(() => {
      if (markersDeferredRef.current) {
        placeMarkers();
      }
    });
    setEditorReady(true);
    onReady?.();
  };

  /**
   * Monaco 가 선점하는 조합을 편집기 안에서도 잡습니다.
   *
   * 창(window) 리스너만 달면 편집기에 포커스가 있을 때 Monaco 가 먼저 먹어 동작이 갈린다 —
   * `Ctrl+P`(Quick Open)·`Ctrl+K`(체인 시작)가 대표적이다.
   */
  const registerCommands = useCallback(
    (editor: monaco.editor.IStandaloneCodeEditor, monacoInstance: Monaco): void => {
      const list = commandsRef.current ?? [];

      for (const [index, command] of list.entries()) {
        const keyCode = monacoKeyCode(monacoInstance, command.key);

        if (keyCode === null) {
          continue;
        }

        let binding = keyCode;

        if (command.mod) {
          binding |= monacoInstance.KeyMod.CtrlCmd;
        }

        if (command.shift) {
          binding |= monacoInstance.KeyMod.Shift;
        }

        if (command.alt) {
          binding |= monacoInstance.KeyMod.Alt;
        }

        // 참조를 통해 부르므로 탭이 바뀌어도 언제나 최신 핸들러가 실행된다.
        editor.addCommand(binding, () => commandsRef.current?.[index]?.run());
      }
    },
    []
  );

  /**
   * Monaco 확보 실패를 폴백으로 받습니다.
   *
   * 편집기가 없다고 화면을 비워 두면 레이아웃을 고칠 방법이 사라진다. 저장 계약
   * (값·onChange)이 같은 textarea 로 대체하고, 사용자에게 사실을 알린다.
   */
  const handleLoadFailure = () => {
    setFailed(true);
    (window as any)?.G7Core?.assets?.notifyFailure?.({
      id: FAILURE_ID,
      label: t('common.code_editor'),
      retry: () => {
        setFailed(false);
      },
    });
  };

  useEffect(() => {
    editorRef.current?.updateOptions(editorOptions);
  }, [editorOptions, editorReady]);

  // 받은 값을 모델에 반영한다 — 늦게 돌아온 내 값(메아리)은 거르고 바깥에서 온 값만 넣는다.
  // 파일 전환(`modelKey`) 때는 라이브러리가 먼저 그 파일 모델로 갈아 끼운 뒤(자식 effect) 여기서 비교한다.
  useEffect(() => {
    const editor = editorRef.current;
    const model = editor?.getModel?.();

    if (
      !editor ||
      !model ||
      typeof value !== 'string' ||
      typeof model.getValue !== 'function' ||
      typeof editor.executeEdits !== 'function'
    ) {
      return;
    }

    const pending = pendingEchoesRef.current;

    if (model.getValue() === value) {
      pending.values = [];

      return;
    }

    if (pending.values.includes(value)) {
      return;
    }

    applyingExternalRef.current = true;

    try {
      if (readOnly) {
        editor.setValue(value);
      } else {
        editor.executeEdits('', [{ range: model.getFullModelRange(), text: value, forceMoveMarkers: true }]);
        editor.pushUndoStop();
      }
    } finally {
      applyingExternalRef.current = false;
    }

    pending.values = [];
  }, [value, modelKey, editorReady, readOnly]);

  // `theme="auto"` — 사용자가 라이트/다크를 바꾸면 편집기도 따라간다. 엔진이 루트의 `dark`
  // 클래스를 토글하므로 그 한 지점만 지켜본다.
  useEffect(() => {
    if (theme !== 'auto' || typeof document === 'undefined' || typeof MutationObserver === 'undefined') {
      return;
    }

    const observer = new MutationObserver(() => setDocumentTheme(resolveDocumentTheme()));
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    setDocumentTheme(resolveDocumentTheme());

    return () => observer.disconnect();
  }, [theme]);

  // 파일이 바뀌면 그 파일의 마커를 다시 찍는다 — 모델 교체는 `<Editor path>` 가 하고,
  // 마커는 모델에 붙는 값이라 교체 직후 한 번 다시 올려야 한다.
  // 줄바꿈 고정도 여기서 함께 한다 — 모델은 파일마다 새로 생기므로 마운트 때 한 번으로는 모자란다.
  useEffect(() => {
    placeMarkers();

    applyLineFeed(editorRef.current?.getModel?.(), monacoRef.current);
    // 마커 배열 자체의 변경은 아래 전용 effect 가 본다 — 여기서는 모델이 바뀐 시점만 다룬다.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [modelKey]);

  // 마커는 값이 바뀔 때마다 통째로 다시 찍는다 — 지난 컴파일의 물결이 고친 줄에 남지 않게.
  useEffect(() => {
    placeMarkers();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [markers]);

  /**
   * 이미 적용한 좌표의 서명 — `모델|줄|칸|nonce`.
   *
   * 본문 도착을 기다리느라 effect 가 여러 번 돌기 때문에, "이 좌표는 이미 옮겼다" 를 기억하지
   * 않으면 사용자가 그 뒤에 커서를 옮겨도 타이핑할 때마다 검색 결과 줄로 되끌려 간다.
   */
  const revealedRef = useRef<string | null>(null);

  // 검색 결과·오류 패널에서 온 좌표로 커서를 옮긴다.
  useEffect(() => {
    const editor = editorRef.current;

    if (!editor || !revealAt || !revealAt.line) {
      return;
    }

    const signature = [modelKey ?? '', revealAt.line, revealAt.column ?? 1, revealAt.nonce ?? ''].join('|');

    if (revealedRef.current === signature) {
      return;
    }

    const model = editor.getModel?.();

    /*
     * 아직 그 파일 본문이 오지 않았다.
     *
     * 검색 결과 줄을 누르면 **파일 전환과 좌표가 같은 순간에** 온다. 본문은 서버에서 뒤늦게
     * 오므로, 그 전에 `setPosition(19)` 을 부르면 Monaco 가 가진 줄 수(빈 모델이면 1)로 잘라
     * 버리고, 뒤늦게 선 본문은 커서를 되돌려 주지 않는다 — 결과 줄을 눌러도 1줄에 머문다
     * (브라우저 실측 2026-09-12: 기대 19 / 실제 1). 본문이 그 줄에 닿을 때까지 기다린다.
     */
    if (!model || typeof model.getLineCount !== 'function' || model.getLineCount() < revealAt.line) {
      return;
    }

    revealedRef.current = signature;

    const position = { lineNumber: Math.max(1, revealAt.line), column: Math.max(1, revealAt.column ?? 1) };

    editor.revealPositionInCenter(position);
    editor.setPosition(position);
    editor.focus();
  }, [revealAt?.line, revealAt?.column, revealAt?.nonce, modelKey, value, editorReady]);

  // Monaco 확보 여부를 직접 확인한다. `<Editor>` 는 로드 실패를 알려주지 않고
  // 로딩 표시에서 멈추므로, 그대로 두면 "영영 불러오는 중" 화면이 남는다.
  useEffect(() => {
    if (failed) {
      return;
    }

    let cancelled = false;

    // 심기가 끝난 뒤에 `init()` 을 부른다. 먼저 부르면 라이브러리가 **자기 로더 스크립트를
    // 따로 주입**해 `_amdLoaderGlobal` 이 두 번 선언되고, 그 판의 require 가 통째로 죽는다.
    const runInit = () => {
      if (cancelled) {
        return;
      }

      setPrimed(true);

      // 제한 시간으로 게이트가 풀렸는데 선심기가 아직 Monaco 를 심지 못했으면 `init()` 을 부르지
      // 않는다 — 부르면 라이브러리가 두 번째 로더를 넣는다. 평문 폴백으로 넘기고, [다시 시도]를
      // 누르면(그 사이 선심기가 끝났으면) 그때 `init()` 이 `window.monaco` 를 그대로 쓴다.
      if (initWouldInjectSecondLoader()) {
        handleLoadFailure();

        return;
      }

      loader.init().catch(() => {
        if (!cancelled) {
          handleLoadFailure();
        }
      });
    };

    // 심을 것이 없으면 **같은 턴에** 부른다 — 한 틱 미루면 실패 폴백이 그만큼 늦어진다.
    if (!monacoNeedsPriming()) {
      runInit();

      return () => {
        cancelled = true;
      };
    }

    bridgeEditorMainExports().then(runInit);

    return () => {
      cancelled = true;
    };
  }, [failed]);

  if (failed) {
    return (
      <div className="border border-gray-300 dark:border-gray-700 rounded-lg overflow-hidden h-full">
        <textarea
          data-testid="code-editor-fallback"
          className="w-full block p-3 font-mono text-sm bg-gray-100 text-gray-900 dark:bg-gray-900 dark:text-gray-100 outline-none resize-y"
          style={{ height: height === '100%' ? '480px' : height, fontSize }}
          value={value}
          readOnly={readOnly}
          spellCheck={false}
          // 폴백도 줄바꿈 토글을 따른다 — 종전에는 `off` 고정이라 모바일에서 가로로만 읽혔다.
          wrap={wordWrap ? 'soft' : 'off'}
          onChange={(event) => handleChange(event.target.value)}
          onKeyDown={(event) => {
            // 탭 들여쓰기 유지 — 코드 편집에서 탭이 포커스를 옮기면 쓸 수 없다
            if (event.key !== 'Tab') {
              return;
            }

            event.preventDefault();

            const target = event.currentTarget;
            const { selectionStart, selectionEnd } = target;
            const next = `${value.slice(0, selectionStart)}  ${value.slice(selectionEnd)}`;

            handleChange(next);

            requestAnimationFrame(() => {
              target.selectionStart = target.selectionEnd = selectionStart + 2;
            });
          }}
        />
      </div>
    );
  }

  if (!primed) {
    return (
      <div className="border border-gray-300 dark:border-gray-700 rounded-lg overflow-hidden">
        <div className="p-4 text-sm text-gray-500 dark:text-gray-400">{t('common.code_editor_loading')}</div>
      </div>
    );
  }

  return (
    <div className="border border-gray-300 dark:border-gray-700 rounded-lg overflow-hidden h-full">
      <Editor
        height={height}
        language={language}
        // `value` 는 넘기지 않는다 — 모델 반영은 위의 메아리 거르는 effect 가 맡는다.
        // `defaultValue` 는 파일(`path`)마다 새 모델을 만들 때의 첫 내용이다.
        defaultValue={value}
        // 파일마다 다른 `path` 를 주면 라이브러리가 모델을 그 키로 캐시하고 뷰 상태(커서·스크롤)까지
        // 함께 보존한다. 같은 파일로 돌아오면 편집하던 자리 그대로다.
        {...(modelKey ? { path: `g7://${modelKey}` } : {})}
        theme={resolvedTheme}
        onChange={handleChange}
        onMount={handleEditorDidMount}
        loading={<div className="p-4 text-sm text-gray-500 dark:text-gray-400">{t('common.code_editor_loading')}</div>}
        beforeMount={() => {
          if (!loaderConfigured) {
            loaderConfigured = configureMonacoLoader();
          }
        }}
        options={editorOptions}
      />
    </div>
  );
};

/**
 * 소문자 키 이름을 Monaco 키코드로 옮깁니다.
 *
 * 필요한 것만 담는다 — 단축키 SSoT 에서 `rebindInEditor` 가 붙은 조합만 여기 들어오고,
 * 표에 없는 키는 `null` 이라 등록을 건너뛴다(조용히 잘못된 키에 묶이는 것보다 낫다).
 *
 * @param monacoInstance Monaco 네임스페이스
 * @param key 소문자 키 이름
 * @returns 키코드 (모르면 null)
 */
function monacoKeyCode(monacoInstance: Monaco, key: string): number | null {
  const codes = monacoInstance.KeyCode as unknown as Record<string, number>;

  const named: Record<string, string> = {
    escape: 'Escape',
    enter: 'Enter',
    arrowleft: 'LeftArrow',
    arrowright: 'RightArrow',
    arrowup: 'UpArrow',
    arrowdown: 'DownArrow',
    '\\': 'Backslash',
    '/': 'Slash',
    // 글자 크기 단축키(§7.6) — `+` 는 물리 키가 `=` 이므로 같은 코드에 건다(Shift 는 combo 가 더한다).
    '=': 'Equal',
    '+': 'Equal',
    '-': 'Minus',
  };

  if (named[key] !== undefined) {
    return codes[named[key]] ?? null;
  }

  if (key.length === 1 && key >= 'a' && key <= 'z') {
    return codes[`Key${key.toUpperCase()}`] ?? null;
  }

  return null;
}
