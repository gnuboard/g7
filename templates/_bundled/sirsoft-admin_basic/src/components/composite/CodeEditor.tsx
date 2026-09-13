import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Editor, { Monaco, loader } from '@monaco-editor/react';
import type * as monaco from 'monaco-editor';
import { getJSONLanguageDefaults } from '../../config/monaco.config';

/** 동봉한 Monaco 버전 (`dist/vendor/monaco-editor/{VERSION}/`) */
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

/** 로더 설정은 앱 수명 동안 1회만 유효하다 (초기화 후 변경 불가) */
let loaderConfigured = false;

/**
 * 서버 컴파일 마커를 Monaco 모델에 찍습니다 (없으면 지운다).
 *
 * 좌표는 1-base 라인·컬럼이다. 끝 좌표가 없으면 그 줄 끝까지 물결을 긋는다 — 서버는 태그의 시작
 * 좌표만 알고, 한 글자짜리 물결은 눈에 띄지 않는다. 좌표가 0 이면(문서 전체 경고) 1행 1열로 접는다.
 *
 * @param editor 편집기 인스턴스 (미마운트면 아무것도 하지 않는다)
 * @param monacoInstance Monaco 네임스페이스
 * @param markers 찍을 마커
 */
function applyMarkers(
  editor: monaco.editor.IStandaloneCodeEditor | null,
  monacoInstance: Monaco | null,
  markers: CodeEditorMarker[] | undefined,
): void {
  const model = editor?.getModel?.();

  if (!editor || !monacoInstance?.editor?.setModelMarkers || !model) {
    return;
  }

  const severity = monacoInstance.MarkerSeverity ?? { Error: 8, Warning: 4 };

  monacoInstance.editor.setModelMarkers(
    model,
    MARKER_OWNER,
    (markers ?? []).map((marker) => {
      const line = Math.max(1, marker.line || 1);
      const column = Math.max(1, marker.column || 1);
      const endLine = marker.endLine ?? line;
      const lineLength = typeof model.getLineMaxColumn === 'function' ? model.getLineMaxColumn(endLine) : column + 1;

      return {
        startLineNumber: line,
        startColumn: column,
        endLineNumber: endLine,
        endColumn: marker.endColumn ?? Math.max(column + 1, lineLength),
        message: marker.message,
        severity: marker.severity === 'error' ? severity.Error : severity.Warning,
        source: MARKER_OWNER,
      };
    }),
  );
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

  const handleChange = useCallback((next: string | undefined) => {
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
    applyMarkers(editor, monacoInstance, markers);
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
      label: '코드 편집기',
      retry: () => {
        setFailed(false);
      },
    });
  };

  useEffect(() => {
    editorRef.current?.updateOptions(editorOptions);
  }, [editorOptions, editorReady]);

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
    applyMarkers(editorRef.current, monacoRef.current, markers);

    applyLineFeed(editorRef.current?.getModel?.(), monacoRef.current);
    // 마커 배열 자체의 변경은 아래 전용 effect 가 본다 — 여기서는 모델이 바뀐 시점만 다룬다.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [modelKey]);

  // 마커는 값이 바뀔 때마다 통째로 다시 찍는다 — 지난 컴파일의 물결이 고친 줄에 남지 않게.
  useEffect(() => {
    applyMarkers(editorRef.current, monacoRef.current, markers);
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
    const init = loader.init();

    init.catch(() => {
      if (!cancelled) {
        handleLoadFailure();
      }
    });

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

  return (
    <div className="border border-gray-300 dark:border-gray-700 rounded-lg overflow-hidden h-full">
      <Editor
        height={height}
        language={language}
        value={value}
        // 파일마다 다른 `path` 를 주면 라이브러리가 모델을 그 키로 캐시하고 뷰 상태(커서·스크롤)까지
        // 함께 보존한다. 같은 파일로 돌아오면 편집하던 자리 그대로다.
        {...(modelKey ? { path: `g7://${modelKey}` } : {})}
        theme={resolvedTheme}
        onChange={handleChange}
        onMount={handleEditorDidMount}
        loading={<div className="p-4 text-sm text-gray-500 dark:text-gray-400">편집기를 불러오는 중...</div>}
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
