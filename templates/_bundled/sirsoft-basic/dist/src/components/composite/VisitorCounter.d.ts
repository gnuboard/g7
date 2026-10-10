import { default as React } from 'react';
import { EditorAttrs } from '../../types';
/**
 * 방문자 카운터 라벨 — 레이아웃이 `$t:` 로 번역해 넘긴다(컴포넌트는 번역하지 않는다).
 */
export interface VisitorCounterLabels {
    /** 카운터 묶음 전체의 이름 — 스크린리더가 숫자 넷을 무엇의 묶음으로 읽을지 정한다 */
    title?: string;
    today?: string;
    yesterday?: string;
    max?: string;
    total?: string;
}
export interface VisitorCounterProps {
    /** 오늘 방문자 */
    today?: number | string;
    /** 어제 방문자 */
    yesterday?: number | string;
    /** 하루 최대 방문자 */
    max?: number | string;
    /** 전체 방문자(일별 순방문자 합) */
    total?: number | string;
    /** 배치 — `row` 는 한 줄 4칸(모바일 2×2), `column` 은 세로 나열 */
    layout?: 'row' | 'column';
    /** 항목 라벨 (없으면 영문 기본값) */
    labels?: VisitorCounterLabels;
    /** 추가 CSS 클래스 */
    className?: string;
    /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * VisitorCounter 컴포넌트
 *
 * 사이트 홈에 놓는 방문자 카운터(오늘·어제·최대·전체). 순수 표시 컴포넌트라 데이터를 스스로
 * 받지 않는다 — 레이아웃 데이터소스(`GET /api/analytics/summary`)가 네 숫자를 넘긴다.
 * 다크 모드 변형을 함께 두고, `row` 배치는 모바일에서 2×2 로 접힌다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "VisitorCounter",
 *   "props": {
 *     "today": "{{visitor_summary?.data?.today ?? 0}}",
 *     "yesterday": "{{visitor_summary?.data?.yesterday ?? 0}}",
 *     "max": "{{visitor_summary?.data?.max ?? 0}}",
 *     "total": "{{visitor_summary?.data?.total ?? 0}}",
 *     "layout": "row",
 *     "labels": { "today": "$t:user.visitor_counter.today" }
 *   }
 * }
 */
export declare const VisitorCounter: React.FC<VisitorCounterProps>;
export default VisitorCounter;
