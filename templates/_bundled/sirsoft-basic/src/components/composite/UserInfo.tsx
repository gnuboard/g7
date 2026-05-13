/**
 * UserInfo 컴포넌트
 *
 * 게시글 작성자 정보를 표시하고 드롭다운 메뉴를 제공하는 통합 컴포넌트입니다.
 * - 작성자 이름, 서브텍스트 표시
 * - 클릭 시 드롭다운 메뉴 (회원인 경우)
 * - 비회원인 경우 배지 표시
 * - 탈퇴한 사용자인 경우 "탈퇴한 사용자" 텍스트 표시
 *
 * ## Props 우선순위
 * 명시적으로 전달한 props가 author에서 추출한 값보다 우선합니다.
 * - 이름: text > name > author.name
 * - 사용자 ID: userId > author.id
 * - 비회원 여부: isGuest > author.is_guest
 * - 탈퇴 여부: isWithdrawn || author.status === 'withdrawn'
 *
 * @example
 * // 레이아웃 JSON에서 사용 (author 객체 전달 - 권장)
 * {
 *   "type": "composite",
 *   "name": "UserInfo",
 *   "props": {
 *     "author": "{{post.author}}",
 *     "subText": "{{post.created_at | datetime}}"
 *   }
 * }
 *
 * @example
 * // author와 개별 props 혼합 사용 (개별 props가 우선)
 * {
 *   "type": "composite",
 *   "name": "UserInfo",
 *   "props": {
 *     "author": "{{post.author}}",
 *     "name": "관리자",
 *     "subText": "{{post.created_at | datetime}}"
 *   }
 * }
 * // author.name이 "홍길동"이어도 "관리자"가 표시됨
 */

import React, { useState, useRef, useEffect, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';

// G7Core 참조
const getG7Core = () => (window as any).G7Core;

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/**
 * 메뉴 항목 설정 인터페이스
 */
export interface MenuItemConfig {
  /** 메뉴 항목 식별자 */
  key: string;
  /** 표시 텍스트 */
  label: string;
  /** 아이콘 이름 (선택) */
  icon?: string;
  /** 이동 경로 ({{userId}} 치환 지원) */
  path?: string;
  /** 커스텀 클릭 핸들러 (선택) */
  onClick?: () => void;
  /** 표시 여부 (기본: true) */
  show?: boolean;
}

/**
 * Author 객체 인터페이스
 */
export interface AuthorInfo {
  /** 사용자 ID */
  id?: string | number;
  /** 사용자 UUID */
  uuid?: string;
  /** 사용자 이름 */
  name?: string;
  /** 아바타 URL */
  avatar?: string;
  /** 사용자 상태 */
  status?: 'active' | 'inactive' | 'blocked' | 'withdrawn';
  /** 비회원 여부 */
  is_guest?: boolean;
}

export interface UserInfoProps {
  /**
   * 작성자 객체 (권장)
   * API 응답의 author 객체를 그대로 전달하면 됩니다.
   * 개별 props(name, userId 등)가 함께 전달되면 개별 props가 우선합니다.
   */
  author?: AuthorInfo;

  /**
   * 사용자 이름 (author.name보다 우선)
   */
  name?: string;

  /**
   * 사용자 ID (author.id보다 우선)
   */
  userId?: string | number;

  /**
   * 표시할 서브 텍스트 (날짜, 시간 등)
   */
  subText?: string;

  /**
   * 서브 텍스트의 title 속성 (tooltip용 원본 datetime)
   */
  subTextTitle?: string;

  /**
   * 비회원 여부 (author.is_guest보다 우선)
   * @default false
   */
  isGuest?: boolean;

  /**
   * 탈퇴한 사용자 여부 (author.status === 'withdrawn'과 OR 조건)
   * @default false
   */
  isWithdrawn?: boolean;

  /**
   * 드롭다운 표시 여부
   * @default true
   */
  showDropdown?: boolean;

  /**
   * 프로필 클릭 가능 여부 (드롭다운이 없을 때)
   * @default true
   */
  clickable?: boolean;

  /**
   * 사용자 프로필 경로 패턴
   * @default '/users/{userId}'
   */
  profilePath?: string;

  /**
   * 추가 className
   */
  className?: string;

  /**
   * 레이아웃 방향
   * @default 'vertical'
   */
  layout?: 'vertical' | 'horizontal';

  /**
   * 레이아웃 JSON에서 text 속성으로 전달되는 이름
   * name보다 우선순위가 높음
   */
  text?: string;

  /**
   * 이벤트 버블링 방지 여부
   * @default false
   */
  stopPropagation?: boolean;

  // 메뉴 커스터마이징
  /** 커스텀 메뉴 항목 (지정 시 기본 메뉴 대체) */
  menuItems?: MenuItemConfig[];
  /** 숨길 기본 메뉴 항목 키 배열 */
  hideMenuItems?: string[];
  /** 기본 메뉴에 추가할 항목 */
  appendMenuItems?: MenuItemConfig[];
}

// 레이아웃별 컨테이너 클래스
const LAYOUT_CLASSES = {
  horizontal: 'flex items-center gap-1.5',
  vertical: 'flex flex-col items-start',
} as const;

// 기본 메뉴 항목 생성
const createDefaultMenuItems = (): MenuItemConfig[] => [
  {
    key: 'view_profile',
    label: t('userinfo.view_profile'),
    icon: 'user',
    path: '/users/{{userId}}',
  },
  {
    key: 'view_posts',
    label: t('userinfo.view_posts'),
    icon: 'file-lines',
    path: '/users/{{userId}}/posts',
  },
];

/**
 * 서브텍스트 렌더링 컴포넌트
 */
const SubText: React.FC<{ text?: string; title?: string }> = ({ text, title }) => {
  if (!text) return null;
  return (
    <Span className="text-sm text-gray-500 dark:text-gray-400" title={title}>
      {text}
    </Span>
  );
};

/**
 * UserInfo 컴포넌트
 */
export const UserInfo: React.FC<UserInfoProps> = ({
  author,
  name,
  userId,
  subText,
  subTextTitle,
  isGuest = false,
  isWithdrawn = false,
  showDropdown = true,
  clickable = true,
  profilePath = '/users/{userId}',
  className = '',
  layout = 'vertical',
  text,
  stopPropagation = false,
  menuItems,
  hideMenuItems = [],
  appendMenuItems = [],
}) => {
  // 명시적 props > author 추출값 순서로 결정
  const actualUserId = userId ?? author?.uuid;
  const actualIsGuest = isGuest || author?.is_guest || false;
  const actualIsWithdrawn = isWithdrawn || author?.status === 'withdrawn';

  // 이름 우선순위: text > name > author.name
  // 탈퇴한 사용자는 "탈퇴한 사용자" 텍스트 표시
  const actualName = actualIsWithdrawn
    ? t('userinfo.withdrawn_user')
    : (text ?? name ?? author?.name ?? '');

  // 드롭다운 상태
  const [showMenu, setShowMenu] = useState(false);
  const [menuPosition, setMenuPosition] = useState({ top: 0, left: 0 });
  const containerRef = useRef<HTMLDivElement>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);

  // 프로필 경로 생성
  const actualProfilePath = profilePath.replace('{userId}', String(actualUserId ?? ''));

  // 컨테이너 클래스
  const containerClass = `${LAYOUT_CLASSES[layout]} ${className}`;

  // 메뉴 외부 클릭 시 닫기 + 스크롤/리사이즈 시 닫기
  useEffect(() => {
    if (!showMenu) return;

    const handleClickOutside = (event: MouseEvent) => {
      const target = event.target as Node;
      const isInsideContainer = containerRef.current?.contains(target);
      const isInsideDropdown = dropdownRef.current?.contains(target);

      if (!isInsideContainer && !isInsideDropdown) {
        setShowMenu(false);
      }
    };

    const handleScrollOrResize = () => setShowMenu(false);

    document.addEventListener('mousedown', handleClickOutside);
    window.addEventListener('scroll', handleScrollOrResize, true);
    window.addEventListener('resize', handleScrollOrResize);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      window.removeEventListener('scroll', handleScrollOrResize, true);
      window.removeEventListener('resize', handleScrollOrResize);
    };
  }, [showMenu]);

  // 최종 메뉴 항목 계산
  const getMenuItems = useCallback((): MenuItemConfig[] => {
    // 커스텀 메뉴가 지정된 경우 그대로 사용
    if (menuItems && menuItems.length > 0) {
      return menuItems.filter((item) => item.show !== false);
    }

    // 기본 메뉴에서 숨길 항목 제거
    const defaultItems = createDefaultMenuItems();
    let items = defaultItems.filter(
      (item) => !hideMenuItems.includes(item.key) && item.show !== false
    );

    // 추가 항목 병합
    if (appendMenuItems && appendMenuItems.length > 0) {
      items = [...items, ...appendMenuItems.filter((item) => item.show !== false)];
    }

    return items;
  }, [menuItems, hideMenuItems, appendMenuItems]);

  // 드롭다운 위치 계산 (뷰포트 기준 fixed 위치 — Portal 사용)
  const updateMenuPosition = useCallback(() => {
    if (buttonRef.current) {
      const buttonRect = buttonRef.current.getBoundingClientRect();
      setMenuPosition({
        top: buttonRect.bottom + 4,
        left: buttonRect.left,
      });
    }
  }, []);

  // 메뉴 항목 클릭 핸들러
  const handleMenuItemClick = useCallback((item: MenuItemConfig, e: React.MouseEvent) => {
    e.stopPropagation();
    e.preventDefault();
    setShowMenu(false);

    if (item.onClick) {
      item.onClick();
    } else if (item.path) {
      const path = item.path.replace(/\{\{userId\}\}/g, String(actualUserId ?? ''));
      const g7Core = getG7Core();
      if (g7Core?.navigate) {
        g7Core.navigate(path);
      } else {
        window.location.href = path;
      }
    }
  }, [actualUserId]);

  // 클릭 핸들러 (드롭다운 또는 프로필 이동)
  const handleClick = useCallback((e: React.MouseEvent) => {
    e.preventDefault();

    if (stopPropagation) {
      e.stopPropagation();
    }

    // 비회원이거나 userId가 없으면 아무 동작 안 함
    if (actualIsGuest || !actualUserId) {
      return;
    }

    // 드롭다운이 활성화된 경우
    if (showDropdown) {
      if (!showMenu) {
        updateMenuPosition();
      }
      setShowMenu((prev) => !prev);
    }
    // 드롭다운이 비활성화되고 클릭 가능한 경우
    else if (clickable) {
      getG7Core()?.navigate?.(actualProfilePath);
    }
  }, [stopPropagation, actualIsGuest, actualUserId, showDropdown, showMenu, updateMenuPosition, clickable, actualProfilePath]);

  // 비회원인 경우
  if (actualIsGuest || !actualUserId) {
    return (
      <Div className={containerClass}>
        <Div className="flex items-center gap-1.5 text-gray-400 dark:text-gray-500">
          <Span>{actualName}</Span>
          <Span className="text-xs px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded">
            {t('userinfo.guest_badge')}
          </Span>
        </Div>
        <SubText text={subText} title={subTextTitle} />
      </Div>
    );
  }

  // 탈퇴한 사용자인 경우
  if (actualIsWithdrawn) {
    return (
      <Div className={containerClass}>
        <Span className="text-gray-400 dark:text-gray-500 line-through">
          {actualName}
        </Span>
        <SubText text={subText} title={subTextTitle} />
      </Div>
    );
  }

  // 회원인 경우
  const finalMenuItems = getMenuItems();
  // className에 text- 색상 클래스가 있으면 nameButtonClass의 기본 색상을 대체
  const hasCustomColor = /\btext-\S+/.test(className);
  const defaultColorClass = hasCustomColor ? '' : 'text-gray-900 dark:text-white';
  const nameButtonClass = (showDropdown || clickable)
    ? `${defaultColorClass} hover:text-blue-600 dark:hover:text-blue-400 cursor-pointer font-medium`.trim()
    : `${defaultColorClass} font-medium`.trim();

  return (
    <Div ref={containerRef} className={`relative inline-block ${className}`}>
      <Div className={LAYOUT_CLASSES[layout]}>
        <Button
          ref={buttonRef}
          onClick={handleClick}
          className={nameButtonClass}
        >
          {actualName}
        </Button>
        <SubText text={subText} title={subTextTitle} />
      </Div>

      {/* 드롭다운 메뉴 - Portal로 document.body에 fixed 배치 (overflow-hidden 부모 회피) */}
      {showDropdown && showMenu && finalMenuItems.length > 0 && createPortal(
        <Div
          ref={dropdownRef}
          className="fixed w-40 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg py-1 z-[9999]"
          style={{ top: menuPosition.top, left: menuPosition.left }}
          data-testid="author-dropdown-menu"
        >
          {finalMenuItems.map((item) => (
            <Button
              key={item.key}
              onClick={(e) => handleMenuItemClick(item, e)}
              className="flex items-center justify-start gap-2 w-full text-left px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer"
              data-testid={`menu-item-${item.key}`}
            >
              {item.icon && <Icon name={item.icon} className="w-4 h-4" />}
              <Span>{item.label}</Span>
            </Button>
          ))}
        </Div>,
        document.body
      )}
    </Div>
  );
};
