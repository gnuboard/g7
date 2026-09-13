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
export declare const CodeEditor: React.FC<CodeEditorProps>;
