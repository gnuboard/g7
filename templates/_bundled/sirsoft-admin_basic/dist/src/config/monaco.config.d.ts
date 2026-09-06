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
 * JSON 언어 서비스 진단 옵션 (Monaco 버전 간 공통 최소 표면)
 */
export interface MonacoJSONLanguageDefaults {
    setDiagnosticsOptions(options: Record<string, unknown>): void;
}
/**
 * JSON 언어 서비스 기본 설정 객체를 찾습니다.
 *
 * Monaco 0.55 부터 JSON 언어 서비스가 `monaco.languages.json` 에서 최상위
 * `monaco.json` 으로 옮겨졌다. 구 경로는 런타임 호환 별칭으로만 남아 있고 타입은
 * 이미 `{ deprecated: true }` 스텁이라, 구 경로만 부르면 타입 검사가 깨지고 별칭이
 * 걷히는 순간 예고 없이 `undefined` 가 된다. 새 경로를 먼저 보고 구 경로로 내려간다.
 *
 * @param monaco Monaco Editor 인스턴스
 * @return MonacoJSONLanguageDefaults|null 찾지 못하면 null
 */
export declare function getJSONLanguageDefaults(monaco: unknown): MonacoJSONLanguageDefaults | null;
/**
 * JSON 언어 서비스 설정
 *
 * @param monaco Monaco Editor 인스턴스
 */
export declare function configureJSONLanguageService(monaco: unknown): void;
