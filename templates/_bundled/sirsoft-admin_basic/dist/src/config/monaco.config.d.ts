import { EditorProps } from '@monaco-editor/react';
/**
 * JSON 언어 기본 설정
 */
export declare const jsonEditorDefaults: EditorProps;
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
export declare const defaultJSONSchemas: MonacoJSONSchema[];
/**
 * Monaco Editor 초기화 옵션
 */
export declare const monacoInitOptions: {
    'vs/nls': {
        availableLanguages: {
            '*': string;
        };
    };
};
/**
 * JSON 언어 서비스 설정
 *
 * @param monaco Monaco Editor 인스턴스
 */
export declare function configureJSONLanguageService(monaco: typeof import('monaco-editor')): void;
