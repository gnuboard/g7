import React, { useEffect, useRef, useState } from 'react';
import Editor, { Monaco, loader } from '@monaco-editor/react';
import type * as monaco from 'monaco-editor';

/** 동봉한 Monaco 버전 (`dist/vendor/monaco-editor/{VERSION}/`) */
const MONACO_VERSION = '0.54.0';

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
      monacoInstance.languages.json.jsonDefaults.setDiagnosticsOptions({
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
