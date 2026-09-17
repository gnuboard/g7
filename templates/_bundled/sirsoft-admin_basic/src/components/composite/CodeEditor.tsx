// audit:allow extension-doc-coverage reason: 같은 작업 단위(S3-D12)에서 AGENTS.md 에 「Monaco 편집기
// 확보 계약」절과 금지 패턴 2줄을 추가했다. 이 줄은 그 뒤 상수 docblock 에 상호참조 한 줄을 덧댄
// 후속 편집이라, phase 단위 감사에는 이 파일만 잡혀 동반 문서가 보이지 않는다.
// e2e:allow 같은 작업 단위에서 code-editor-monaco-vendored-version.spec.ts 에 「AMD 로더 스크립트가
// 한 번만 실행된다」 축을 신설하고(매니페스트 effect: vendored_amd_loader_runs_exactly_once) 기존
// 테스트 2건에 per-test @scenario/@effects 를 붙였다. 3 passed · red 대조 성립.
import React, { useEffect, useRef, useState } from 'react';
import Editor, { Monaco, loader } from '@monaco-editor/react';
import type * as monaco from 'monaco-editor';
import { getJSONLanguageDefaults } from '../../config/monaco.config';

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
 * 동봉본 Monaco 를 직접 받아 `window.monaco` 에 심습니다.
 *
 * @returns Promise 심기가 끝나면(또는 포기하면) resolve 된다
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
        const done = () => {
            if (!settled) {
                settled = true;
                resolve();
            }
        };

        window.setTimeout(done, 3_000);

        const adopt = (): boolean => {
            const amdRequire = (window as any)?.require;

            if (typeof amdRequire !== 'function' || typeof amdRequire.config !== 'function') {
                return false;
            }

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

export interface CodeEditorProps {
  value: string;
  onChange?: (event: { target: { value: string } }) => void;
  language?: string;
  height?: string;
  readOnly?: boolean;
  theme?: 'vs-dark' | 'vs-light';
}

export const CodeEditor: React.FC<CodeEditorProps> = ({
  value,
  onChange,
  language = 'json',
  height = '100%',
  readOnly = false,
  theme = 'vs-dark',
}) => {
  const editorRef = useRef<monaco.editor.IStandaloneCodeEditor | null>(null);
  const [failed, setFailed] = useState(false);
  // `<Editor>` 는 렌더되자마자 자기 effect 에서 `loader.init()` 을 부른다. 심기보다 먼저
  // 불리면 라이브러리가 자기 로더 스크립트를 따로 주입해 AMD 로더가 두 번 실행된다
  // (`_amdLoaderGlobal` 재선언 → 그 판의 require 사망). 그래서 **심기가 끝나기 전에는
  // `<Editor>` 를 렌더하지 않는다.**
  const [primed, setPrimed] = useState(() => !monacoNeedsPriming());

  if (!loaderConfigured) {
    loaderConfigured = configureMonacoLoader();
  }

  const handleEditorDidMount = (
    editor: monaco.editor.IStandaloneCodeEditor,
    monacoInstance: Monaco
  ) => {
    editorRef.current = editor;
    (window as any)?.G7Core?.assets?.clearFailure?.(FAILURE_ID);

    // JSON 스키마 검증 설정
    if (language === 'json') {
      getJSONLanguageDefaults(monacoInstance)?.setDiagnosticsOptions({
        validate: true,
        schemaValidation: 'error',
        allowComments: false,
      });
    }

    // 에디터 옵션 설정
    editor.updateOptions({
      readOnly,
      minimap: { enabled: false },
      scrollBeyondLastLine: false,
      fontSize: 14,
      lineNumbers: 'on',
      renderWhitespace: 'selection',
      automaticLayout: true,
    });
  };

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
      label: '코드 편집기',
      retry: () => {
        setFailed(false);
      },
    });
  };

  const handleChange = (value: string | undefined) => {
    if (onChange && value !== undefined) {
      // 엔진의 isCustomComponentEvent 경로로 처리되도록 { target: { value } } 패턴 사용
      onChange({ target: { value } });
    }
  };

  useEffect(() => {
    if (editorRef.current) {
      editorRef.current.updateOptions({ readOnly });
    }
  }, [readOnly]);

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
      <div className="border border-gray-300 dark:border-gray-700 rounded-lg overflow-hidden">
        <textarea
          data-testid="code-editor-fallback"
          className="w-full block p-3 font-mono text-sm bg-gray-100 text-gray-900 dark:bg-gray-900 dark:text-gray-100 outline-none resize-y"
          style={{ height: height === '100%' ? '480px' : height }}
          value={value}
          readOnly={readOnly}
          spellCheck={false}
          wrap="off"
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
        <div className="p-4 text-sm text-gray-500 dark:text-gray-400">편집기를 불러오는 중...</div>
      </div>
    );
  }

  return (
    <div className="border border-gray-300 dark:border-gray-700 rounded-lg overflow-hidden">
      <Editor
        height={height}
        language={language}
        value={value}
        theme={theme}
        onChange={handleChange}
        onMount={handleEditorDidMount}
        loading={<div className="p-4 text-sm text-gray-500 dark:text-gray-400">편집기를 불러오는 중...</div>}
        beforeMount={() => {
          if (!loaderConfigured) {
            loaderConfigured = configureMonacoLoader();
          }
        }}
        options={{
          readOnly,
          minimap: { enabled: false },
          scrollBeyondLastLine: false,
          fontSize: 14,
          lineNumbers: 'on',
          renderWhitespace: 'selection',
          automaticLayout: true,
        }}
      />
    </div>
  );
};
