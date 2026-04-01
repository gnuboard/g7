import React, { useMemo } from 'react';
import { Div } from '../basic/Div';
import { Img } from '../basic/Img';
import { Icon } from '../basic/Icon';

/**
 * 아바타 크기 타입
 */
export type AvatarSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | '4xl' | '5xl';

/**
 * 사이즈별 Tailwind 클래스 매핑
 * - 크기 (w/h) + 텍스트 크기 (이니셜용)
 */
const SIZE_CLASSES: Record<AvatarSize, string> = {
  xs: 'w-6 h-6 text-xs',         // 24px
  sm: 'w-8 h-8 text-sm',         // 32px
  md: 'w-10 h-10 text-base',     // 40px
  lg: 'w-12 h-12 text-lg',       // 48px
  xl: 'w-16 h-16 text-xl',       // 64px
  '2xl': 'w-24 h-24 text-2xl',   // 96px
  '3xl': 'w-32 h-32 text-3xl',   // 128px
  '4xl': 'w-40 h-40 text-4xl',   // 160px
  '5xl': 'w-48 h-48 text-5xl',   // 192px
} as const;

/**
 * 기본 컨테이너 스타일 (공통)
 */
const BASE_CONTAINER_CLASSES = 'rounded-full overflow-hidden flex-shrink-0 border border-gray-200 dark:border-gray-700';

/**
 * 탈퇴 사용자 스타일
 */
const WITHDRAWN_CLASSES = 'grayscale opacity-50';

/**
 * 이니셜 배경 스타일
 */
const INITIAL_BG_CLASSES = {
  normal: 'bg-gradient-to-br from-blue-500 to-purple-600',
  withdrawn: 'bg-gray-300 dark:bg-gray-600',
  guest: 'bg-gray-200 dark:bg-gray-700',
} as const;

/**
 * Author 객체 인터페이스
 */
export interface AuthorInfo {
  /** 사용자 ID */
  id?: string | number;
  /** 사용자 이름 */
  name?: string;
  /** 아바타 URL */
  avatar?: string;
  /** 사용자 상태 */
  status?: 'active' | 'inactive' | 'blocked' | 'withdrawn';
  /** 비회원 여부 */
  is_guest?: boolean;
}

export interface AvatarProps {
  /**
   * 작성자 객체 (권장)
   * API 응답의 author 객체를 그대로 전달하면 됩니다.
   * 개별 props(name, avatar 등)가 함께 전달되면 개별 props가 우선합니다.
   */
  author?: AuthorInfo;
  /** 사용자 이름 (author.name보다 우선) */
  name?: string;
  /** 아바타 이미지 URL (author.avatar보다 우선) */
  avatar?: string;
  /** 사이즈 @default 'md' */
  size?: AvatarSize;
  /** 추가 className */
  className?: string;
  /** 레이아웃 JSON text 속성 (name보다 우선) */
  text?: string;
  /** 탈퇴한 사용자 여부 (author.status === 'withdrawn'과 OR 조건) @default false */
  isWithdrawn?: boolean;
  /** 비회원 여부 (author.is_guest보다 우선) @default false */
  isGuest?: boolean;
}

/**
 * Avatar 컴포넌트
 *
 * 사용자 아바타를 표시하는 재사용 가능한 composite 컴포넌트입니다.
 * - 아바타 이미지가 있으면 이미지 표시
 * - 이미지가 없으면 이름의 첫 글자(이니셜) 표시
 * - 비회원: 연한 회색 배경 + 사람 실루엣 아이콘
 * - 탈퇴한 사용자: 회색 빈 원 (그레이스케일 + 투명도)
 *
 * ## Props 우선순위
 * 명시적으로 전달한 props가 author에서 추출한 값보다 우선합니다.
 * - 이름: text > name > author.name
 * - 아바타: avatar > author.avatar
 * - 비회원 여부: isGuest > author.is_guest
 * - 탈퇴 여부: isWithdrawn || author.status === 'withdrawn'
 *
 * @example
 * // 레이아웃 JSON에서 사용 (author 객체 전달 - 권장)
 * {
 *   "type": "composite",
 *   "name": "Avatar",
 *   "props": {
 *     "author": "{{post.author}}",
 *     "size": "md"
 *   }
 * }
 *
 * @example
 * // author와 개별 props 혼합 사용 (개별 props가 우선)
 * {
 *   "type": "composite",
 *   "name": "Avatar",
 *   "props": {
 *     "author": "{{post.author}}",
 *     "avatar": "/images/custom-avatar.png",
 *     "size": "md"
 *   }
 * }
 * // author.avatar가 있어도 "/images/custom-avatar.png"이 표시됨
 */
export const Avatar: React.FC<AvatarProps> = ({
  author,
  name,
  avatar,
  size = 'md',
  className = '',
  text,
  isWithdrawn = false,
  isGuest = false,
}) => {
  // 명시적 props > author 추출값 순서로 결정
  const actualAvatar = avatar ?? author?.avatar;
  const actualName = text ?? name ?? author?.name ?? '?';
  const actualIsWithdrawn = isWithdrawn || author?.status === 'withdrawn';
  const actualIsGuest = isGuest || author?.is_guest || false;

  // 컨테이너 클래스 메모이제이션
  const containerClasses = useMemo(() => {
    const sizeClass = SIZE_CLASSES[size] || SIZE_CLASSES.md;
    const withdrawnClass = actualIsWithdrawn ? WITHDRAWN_CLASSES : '';

    return [sizeClass, BASE_CONTAINER_CLASSES, withdrawnClass, className]
      .filter(Boolean)
      .join(' ');
  }, [size, actualIsWithdrawn, className]);

  // 이니셜 배경 클래스
  const initialBgClass = actualIsGuest
    ? INITIAL_BG_CLASSES.guest
    : actualIsWithdrawn
      ? INITIAL_BG_CLASSES.withdrawn
      : INITIAL_BG_CLASSES.normal;

  // 비회원: 사람 실루엣 아이콘 (연한 회색), 탈퇴 사용자: 사람 실루엣 아이콘 (진한 회색), 일반: 이니셜
  const renderContent = () => {
    if (actualIsGuest) {
      return (
        <Div className={`w-full h-full flex items-center justify-center ${initialBgClass}`}>
          <Icon name="user" className="text-gray-400 dark:text-gray-500" />
        </Div>
      );
    }
    if (actualIsWithdrawn) {
      return (
        <Div className={`w-full h-full flex items-center justify-center ${initialBgClass}`}>
          <Icon name="user" className="text-gray-500 dark:text-gray-400" />
        </Div>
      );
    }
    return (
      <Div className={`w-full h-full flex items-center justify-center text-white font-semibold ${initialBgClass}`}>
        {actualName.charAt(0).toUpperCase()}
      </Div>
    );
  };

  return (
    <Div className={containerClasses}>
      {actualAvatar && !actualIsGuest && !actualIsWithdrawn ? (
        <Img
          src={actualAvatar}
          alt={actualName}
          className="w-full h-full object-cover"
        />
      ) : (
        renderContent()
      )}
    </Div>
  );
};
