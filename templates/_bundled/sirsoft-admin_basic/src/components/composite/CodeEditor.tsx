import React, { useEffect, useRef, useState } from 'react';
import Editor, { Monaco, loader } from '@monaco-editor/react';
import type * as monaco from 'monaco-editor';
import { getJSONLanguageDefaults } from '../../config/monaco.config';

/** 동봉한 Monaco 버전 (`dist/vendor/monaco-editor/{VERSION}/`) */
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

/** 마커 소유자 식별자 — 같은 모델의 다른 마커(JSON 스키마 등)와 구분한다 */
const MARKER_OWNER = 'g7-markup';

export interface CodeEditorProps {
  value: string;
  onChange?: (event: { target: { value: string } }) => void;
  language?: string;
  height?: string;
  readOnly?: boolean;
  theme?: 'vs-dark' | 'vs-light';
  /** 서버 컴파일 오류·경고 마커 (없거나 빈 배열이면 물결을 지운다) */
  markers?: CodeEditorMarker[];
}

export const CodeEditor: React.FC<CodeEditorProps> = ({
  value,
  onChange,
  language = 'json',
  height = '100%',
  readOnly = false,
  theme = 'vs-dark',
  markers,
}) => {
  const editorRef = useRef<monaco.editor.IStandaloneCodeEditor | null>(null);
  const monacoRef = useRef<Monaco | null>(null);
  const [failed, setFailed] = useState(false);

  if (!loaderConfigured) {
    loaderConfigured = configureMonacoLoader();
  }

  const handleEditorDidMount = (
    editor: monaco.editor.IStandaloneCodeEditor,
    monacoInstance: Monaco
  ) => {
    editorRef.current = editor;
    monacoRef.current = monacoInstance;
    (window as any)?.G7Core?.assets?.clearFailure?.(FAILURE_ID);
    applyMarkers(editor, monacoInstance, markers);

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

  // 마커는 값이 바뀔 때마다 통째로 다시 찍는다 — 지난 컴파일의 물결이 고친 줄에 남지 않게.
  useEffect(() => {
    applyMarkers(editorRef.current, monacoRef.current, markers);
  }, [markers]);

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
