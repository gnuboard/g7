import { default as React } from 'react';
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
export interface CodeEditorProps {
    value: string;
    onChange?: (event: {
        target: {
            value: string;
        };
    }) => void;
    language?: string;
    height?: string;
    readOnly?: boolean;
    theme?: 'vs-dark' | 'vs-light';
    /** 서버 컴파일 오류·경고 마커 (없거나 빈 배열이면 물결을 지운다) */
    markers?: CodeEditorMarker[];
}
export declare const CodeEditor: React.FC<CodeEditorProps>;
