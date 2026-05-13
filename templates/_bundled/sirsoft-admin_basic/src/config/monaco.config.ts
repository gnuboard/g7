/**
 * Monaco Editor 설정
 */

import type { EditorProps } from '@monaco-editor/react';

/**
 * JSON 언어 기본 설정
 */
export const jsonEditorDefaults: EditorProps = {
  language: 'json',
  theme: 'vs-dark',
  options: {
    automaticLayout: true,
    minimap: { enabled: true },
    scrollBeyondLastLine: false,
    fontSize: 14,
    tabSize: 2,
    formatOnPaste: true,
    formatOnType: true,
    wordWrap: 'on',
    folding: true,
    lineNumbers: 'on',
    renderWhitespace: 'selection',
  },
};

/**
 * JSON 스키마 검증 설정
 *
 * Monaco Editor의 JSON 언어 서비스 설정을 위한 스키마 정의
 */
export interface MonacoJSONSchema {
  uri: string;
  fileMatch?: string[];
  schema?: Record<string, unknown>;
}

/**
 * Monaco Editor JSON 검증을 위한 기본 스키마
 */
export const defaultJSONSchemas: MonacoJSONSchema[] = [
  {
    uri: `${window.location.origin}/schemas/layout.json`,
    fileMatch: ['*layout*.json'],
    schema: {
      type: 'object',
      properties: {
        version: { type: 'string' },
        endpoint: { type: 'string' },
        components: { type: 'array' },
      },
      required: ['version', 'endpoint', 'components'],
    },
  },
];

/**
 * Monaco Editor 초기화 옵션
 */
export const monacoInitOptions = {
  'vs/nls': {
    availableLanguages: {
      '*': 'ko',
    },
  },
};

/**
 * JSON 언어 서비스 설정
 *
 * @param monaco Monaco Editor 인스턴스
 */
export function configureJSONLanguageService(monaco: typeof import('monaco-editor')): void {
  monaco.languages.json.jsonDefaults.setDiagnosticsOptions({
    validate: true,
    schemas: defaultJSONSchemas,
    enableSchemaRequest: true,
    schemaValidation: 'error',
    schemaRequest: 'error',
  });
}
