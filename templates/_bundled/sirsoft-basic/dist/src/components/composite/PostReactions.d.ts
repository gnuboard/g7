import { default as React } from 'react';
type ReactionType = 'like' | 'funny' | 'agree' | 'thanks' | 'wow';
interface ReactionCount {
    type: ReactionType;
    count: number;
}
interface PostReactionsProps {
    /** 각 리액션별 카운트 */
    reactions: ReactionCount[];
    /** 현재 사용자의 리액션 (없으면 null) */
    userReaction?: ReactionType | null;
    /** 게시글 ID */
    postId: number;
    /** 리액션 클릭 콜백 */
    onReact?: (type: ReactionType) => void;
    /** 컴포넌트 크기 */
    size?: 'sm' | 'md' | 'lg';
    /** 추가 CSS 클래스 */
    className?: string;
}
/**
 * 게시글 리액션 컴포넌트
 *
 * @example
 * ```tsx
 * <PostReactions
 *   reactions={[
 *     { type: 'like', count: 5 },
 *     { type: 'funny', count: 2 },
 *   ]}
 *   userReaction="like"
 *   postId={123}
 *   onReact={(type) => handleReact(type)}
 * />
 * ```
 *
 * @example
 * ```json
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "PostReactions",
 *   "props": {
 *     "reactions": "{{post.data.reactions}}",
 *     "userReaction": "{{post.data.user_reaction}}",
 *     "postId": "{{post.data.id}}"
 *   }
 * }
 * ```
 */
declare const PostReactions: React.FC<PostReactionsProps>;
export default PostReactions;
