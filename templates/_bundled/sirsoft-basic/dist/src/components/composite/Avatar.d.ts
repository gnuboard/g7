import { default as React } from 'react';
/**
 * 아바타 크기 타입
 */
export type AvatarSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | '4xl' | '5xl';
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
export declare const Avatar: React.FC<AvatarProps>;
