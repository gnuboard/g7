import { default as React } from 'react';
export interface MultilingualValue {
    [locale: string]: string;
}
export interface HtmlEditorProps {
    /**
     * 콘텐츠 값 (단일 언어 모드)
     */
    content?: string;
    /**
     * 다국어 콘텐츠 값 (다국어 모드)
     * multilingual=true일 때 사용
     */
    value?: MultilingualValue;
    /**
     * 콘텐츠 변경 콜백
     * - 단일 언어: { target: { name, value: string } }
     * - 다국어: { target: { name, value: MultilingualValue } }
     */
    onChange?: (event: {
        target: {
            name: string;
            value: string | MultilingualValue;
        };
    }) => void;
    /**
     * 다국어 모드 활성화
     * true일 때 언어 탭 UI 표시, value는 다국어 객체
     * @default false
     */
    multilingual?: boolean;
    /**
     * HTML 모드 여부 (미리보기 버튼 표시 조건)
     * 자동 바인딩된 값을 Layout JSON에서 전달받아 사용
     */
    isHtml?: boolean;
    /**
     * HTML 모드 변경 콜백
     * Form 자동 바인딩을 위해 이벤트 객체 형식으로 전달
     */
    onIsHtmlChange?: (event: {
        target: {
            name: string;
            checked: boolean;
        };
    }) => void;
    /**
     * Textarea 행 수
     * @default 15
     */
    rows?: number;
    /**
     * placeholder 텍스트
     */
    placeholder?: string;
    /**
     * 라벨 텍스트
     */
    label?: string;
    /**
     * HTML 모드 체크박스 표시 여부
     * @default true
     */
    showHtmlModeToggle?: boolean;
    /**
     * HtmlContent 렌더링 시 적용할 클래스
     */
    contentClassName?: string;
    /**
     * DOMPurify 설정 (HTML 모드)
     */
    purifyConfig?: any;
    /**
     * 추가 className
     */
    className?: string;
    /**
     * 콘텐츠 필드 name 속성
     * @default 'content'
     */
    name?: string;
    /**
     * HTML 모드 체크박스 name 속성
     * @default 'content_mode'
     */
    htmlFieldName?: string;
    /**
     * 읽기 전용 모드
     */
    readOnly?: boolean;
}
/**
 * HtmlEditor 컴포넌트
 *
 * HTML과 일반 텍스트를 편집할 수 있는 범용 에디터 컴포넌트입니다.
 * - HTML 모드 체크박스는 자동 바인딩됩니다 (htmlFieldName prop 사용)
 * - HTML 모드에서는 편집/미리보기 토글 가능 (내부 상태 관리)
 * - 일반 텍스트 모드에서는 Textarea만 표시
 * - multilingual=true일 때 다국어 탭 UI 지원 (v1.5.0+)
 *
 * @example
 * // 단일 언어 모드
 * {
 *   "type": "composite",
 *   "name": "HtmlEditor",
 *   "props": {
 *     "content": "{{_local.form?.content}}",
 *     "name": "content"
 *   }
 * }
 *
 * @example
 * // 다국어 모드
 * {
 *   "type": "composite",
 *   "name": "HtmlEditor",
 *   "props": {
 *     "multilingual": true,
 *     "value": "{{_local.form?.description}}",
 *     "name": "description"
 *   }
 * }
 */
export declare const HtmlEditor: React.FC<HtmlEditorProps>;
