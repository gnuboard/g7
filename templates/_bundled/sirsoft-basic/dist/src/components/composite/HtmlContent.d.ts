import { default as React } from 'react';
export interface HtmlContentProps {
    /**
     * 콘텐츠 (HTML 또는 일반 텍스트)
     */
    content?: string;
    /**
     * 콘텐츠가 HTML 형식인지 여부
     * - true: HTML 렌더링 (DOMPurify 적용, prose 스타일)
     * - false: 일반 텍스트 (whitespace-pre-wrap으로 줄바꿈 보존)
     * @default true
     */
    isHtml?: boolean;
    /**
     * 사용자 정의 클래스
     */
    className?: string;
    /**
     * DOMPurify 설정 오버라이드 (isHtml=true일 때만 사용)
     */
    purifyConfig?: any;
    /**
     * 레이아웃 JSON에서 text 속성으로 전달되는 콘텐츠
     * content보다 우선순위가 높음
     */
    text?: string;
}
/**
 * HtmlContent 콘텐츠 렌더링 컴포넌트
 *
 * HTML과 일반 텍스트를 안전하게 렌더링하는 범용 composite 컴포넌트입니다.
 * - isHtml=true: DOMPurify를 사용하여 XSS 공격 방지
 * - isHtml=false: 일반 텍스트로 렌더링 (줄바꿈 보존)
 *
 * @example
 * // HTML 렌더링 (기본값)
 * <HtmlContent content="<p>안녕하세요</p>" />
 *
 * // 일반 텍스트 렌더링
 * <HtmlContent
 *   content="안녕하세요\n줄바꿈이 보존됩니다"
 *   isHtml={false}
 * />
 *
 * // 커스텀 클래스 적용
 * <HtmlContent
 *   content="<p>게시글 내용</p>"
 *   className="prose dark:prose-invert"
 * />
 *
 * // DOMPurify 설정 커스터마이징 (HTML 모드)
 * <HtmlContent
 *   content="<p>내용</p>"
 *   purifyConfig={{ ALLOWED_TAGS: ['p', 'br', 'strong', 'em'] }}
 * />
 */
export declare const HtmlContent: React.FC<HtmlContentProps>;
