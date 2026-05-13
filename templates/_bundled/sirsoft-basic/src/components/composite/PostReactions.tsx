/**
 * PostReactions 컴포넌트
 *
 * 게시글에 대한 리액션(이모지) 버튼 그룹입니다.
 * 좋아요, 웃겨요, 동의해요, 감사해요, 놀라워요 5종 리액션을 지원합니다.
 */

import React from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:PostReactions')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:PostReactions]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:PostReactions]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:PostReactions]', ...args),
};

/**
 * G7Core 번역 함수
 */
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

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

interface ReactionConfig {
  type: ReactionType;
  emoji: string;
  labelKey: string;
  activeColor: string;
}

/**
 * 리액션 설정
 */
const REACTION_CONFIGS: ReactionConfig[] = [
  { type: 'like', emoji: '👍', labelKey: 'board.reactions.like', activeColor: 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' },
  { type: 'funny', emoji: '😂', labelKey: 'board.reactions.funny', activeColor: 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-600 dark:text-yellow-400' },
  { type: 'agree', emoji: '👌', labelKey: 'board.reactions.agree', activeColor: 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400' },
  { type: 'thanks', emoji: '🙏', labelKey: 'board.reactions.thanks', activeColor: 'bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400' },
  { type: 'wow', emoji: '😮', labelKey: 'board.reactions.wow', activeColor: 'bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400' },
];

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
const PostReactions: React.FC<PostReactionsProps> = ({
  reactions,
  userReaction,
  postId,
  onReact,
  size = 'md',
  className = '',
}) => {
  const sizeClasses = {
    sm: 'px-2 py-1 text-xs gap-1',
    md: 'px-3 py-1.5 text-sm gap-1.5',
    lg: 'px-4 py-2 text-base gap-2',
  };

  const emojiSizes = {
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-lg',
  };

  const getReactionCount = (type: ReactionType): number => {
    const reaction = reactions.find((r) => r.type === type);
    return reaction?.count ?? 0;
  };

  const handleClick = async (type: ReactionType) => {
    if (onReact) {
      onReact(type);
      return;
    }

    // 기본 API 호출
    try {
      const response = await fetch(`/api/posts/${postId}/reactions`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ type }),
        credentials: 'include',
      });

      if (!response.ok) {
        throw new Error('리액션 처리 실패');
      }

      // 성공 시 페이지 새로고침 또는 상태 업데이트
      window.location.reload();
    } catch (error) {
      logger.error('리액션 오류:', error);
    }
  };

  return (
    <Div className={`flex flex-wrap gap-2 ${className}`}>
      {REACTION_CONFIGS.map((config) => {
        const count = getReactionCount(config.type);
        const isActive = userReaction === config.type;

        return (
          <Button
            key={config.type}
            onClick={() => handleClick(config.type)}
            className={`
              inline-flex items-center rounded-full border transition-colors
              ${sizeClasses[size]}
              ${isActive
                ? `${config.activeColor} border-transparent`
                : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'
              }
            `}
            title={t(config.labelKey)}
          >
            <Span className={emojiSizes[size]}>{config.emoji}</Span>
            <Span>{t(config.labelKey)}</Span>
            {count > 0 && (
              <Span className="font-medium">{count}</Span>
            )}
          </Button>
        );
      })}
    </Div>
  );
};

export default PostReactions;
