import { default as React } from 'react';
interface RichTextEditorProps {
    /** 필드 이름 */
    name: string;
    /** 초기 HTML 값 */
    initialValue?: string;
    /** 플레이스홀더 */
    placeholder?: string;
    /** 값 변경 콜백 */
    onChange?: (html: string) => void;
    /** 이미지 업로드 URL */
    imageUploadUrl?: string;
    /** 최소 높이 */
    minHeight?: string;
    /** 비활성화 여부 */
    disabled?: boolean;
    /** 추가 CSS 클래스 */
    className?: string;
}
/**
 * 리치 텍스트 에디터 컴포넌트
 *
 * @example
 * ```tsx
 * <RichTextEditor
 *   name="content"
 *   initialValue={post?.content}
 *   placeholder="내용을 입력하세요..."
 *   onChange={(html) => setContent(html)}
 * />
 * ```
 *
 * @example
 * ```json
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "RichTextEditor",
 *   "props": {
 *     "name": "content",
 *     "placeholder": "$t:board.form.content_placeholder",
 *     "initialValue": "{{post.data?.content ?? ''}}"
 *   }
 * }
 * ```
 */
declare const RichTextEditor: React.FC<RichTextEditorProps>;
export default RichTextEditor;
