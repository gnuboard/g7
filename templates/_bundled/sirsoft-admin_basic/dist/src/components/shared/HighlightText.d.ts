import { default as React } from 'react';
export interface HighlightTextProps {
    /** 표시할 원문 */
    text: string;
    /** 강조할 검색어 (빈 문자열이면 원문 그대로) */
    keyword?: string;
    /** 강조 조각에 입힐 클래스 (기본: 노랑 배경) */
    className?: string;
}
/**
 * 텍스트에서 검색어를 찾아 강조해 그립니다.
 *
 * 이식 원본: 코어 `resources/js/core/template-engine/layout-editor/components/RouteTreePanel.tsx`
 * 의 `highlightMatch`(기준선 10e917c6d :148-170 — 지금은 `layout-editor/shared/treeSearch.ts`).
 * 템플릿은 독립 IIFE 번들이라 코어를 import 할 수 없어 옮겨 적었다(계획서 §4.1·§13).
 *
 * `CategoryTree` 안에 있던 같은 이름의 내부 함수를 끌어올린 것이다. 파일 트리(`FileTree`)가
 * 같은 강조를 필요로 하는데, 거기에 두 번째 구현을 두면 한쪽만 고쳐지는 순간 두 화면의
 * 강조 규칙이 갈린다 — 특히 정규식 이스케이프처럼 **틀리면 조용히 깨지는** 부분이 그렇다.
 *
 * ## 왜 정규식을 이스케이프하는가
 *
 * 검색어는 사용자가 친 문자열이고, 이 화면의 검색 대상은 코드다. `.`·`*`·`(`·`[`·`{{` 같은
 * 문자가 그대로 들어온다 — 이스케이프하지 않으면 `(` 하나에 `SyntaxError` 가 나 트리 전체가
 * 렌더되지 않고, `.` 은 아무 글자나 강조해 엉뚱한 자리를 가리킨다.
 *
 * @param props 원문·검색어·강조 클래스
 * @returns 강조가 섞인 조각들
 */
export declare function HighlightText({ text, keyword, className }: HighlightTextProps): React.ReactElement;
