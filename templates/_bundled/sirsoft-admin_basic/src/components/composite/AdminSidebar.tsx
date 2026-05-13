import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { Div } from '../basic/Div';
import { Img } from '../basic/Img';
import { Span } from '../basic/Span';
import { Button } from '../basic/Button';
import { I } from '../basic/I';

// G7Core 전역 객체 접근 헬퍼
const G7Core = () => (window as any).G7Core;

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/**
 * 메뉴 아이템 인터페이스 (API 응답 구조)
 */
export interface MenuItem {
  id: string | number;
  name: string | { ko: string; en: string };
  slug: string;
  url?: string | null;
  icon?: string;
  children?: MenuItem[];
  is_active?: boolean;
  module_id?: number | null;
}

/**
 * AdminSidebar Props
 */
export interface AdminSidebarProps {
  logo?: string;
  logoAlt?: string;
  menu: MenuItem[];
  collapsed?: boolean;
  onToggleCollapse?: () => void;
  className?: string;
  /** 현재 로케일 (다국어 메뉴 이름 표시용). 미지정 시 G7Core.locale.current() 자동 사용 */
  currentLocale?: string;
}

/**
 * AdminSidebar 컴포넌트
 *
 * 관리자 사이드바 - 계층형 메뉴, 접기/펼치기 기능 제공
 *
 * @example
 * ```tsx
 * <AdminSidebar
 *   logo="/logo.png"
 *   menu={[
 *     { id: 1, name: { ko: '대시보드', en: 'Dashboard' }, slug: 'dashboard', url: '/admin', icon: 'fas fa-tachometer-alt' },
 *     {
 *       id: 2,
 *       name: { ko: '콘텐츠', en: 'Content' },
 *       slug: 'content',
 *       icon: 'fas fa-file-text',
 *       children: [
 *         { id: 21, name: { ko: '게시물', en: 'Posts' }, slug: 'posts', url: '/admin/posts', icon: 'fas fa-list' },
 *         { id: 22, name: { ko: '페이지', en: 'Pages' }, slug: 'pages', url: '/admin/pages', icon: 'fas fa-file' }
 *       ]
 *     }
 *   ]}
 *   collapsed={false}
 * />
 * ```
 */
export const AdminSidebar: React.FC<AdminSidebarProps> = ({
  logo,
  logoAlt = 'Logo',
  menu = [],
  collapsed = false,
  onToggleCollapse,
  className = '',
  currentLocale: currentLocaleProp,
}) => {
  const [expandedItems, setExpandedItems] = useState<Set<string | number>>(new Set());
  const [locale, setLocale] = useState<string>(currentLocaleProp || G7Core()?.locale?.current?.() || 'ko');
  const [currentPath, setCurrentPath] = useState<string>(window.location.pathname);

  // 메뉴 트리에서 모든 URL 수집 (가장 구체적인 매칭 판별용)
  const allMenuUrls = useMemo(() => {
    const urls: string[] = [];
    const collect = (items: MenuItem[]) => {
      for (const item of items) {
        if (item.url) urls.push(item.url);
        if (item.children) collect(item.children);
      }
    };
    collect(menu);
    return urls;
  }, [menu]);

  // 주어진 경로에 가장 구체적으로 매칭되는 메뉴 URL 찾기
  const findBestMatch = useCallback((path: string): string | null => {
    let bestMatch: string | null = null;
    for (const url of allMenuUrls) {
      if (path === url || path.startsWith(url + '/')) {
        if (!bestMatch || url.length > bestMatch.length) {
          bestMatch = url;
        }
      }
    }
    return bestMatch;
  }, [allMenuUrls]);

  // G7Core 로케일 및 경로 변경 구독
  useEffect(() => {
    const g7core = G7Core();
    if (!g7core?.state?.subscribe) return;

    const unsubscribe = g7core.state.subscribe((state: any) => {
      const newLocale = state?._global?.locale || g7core?.locale?.current?.() || 'ko';
      setLocale(newLocale);

      // 현재 경로 업데이트
      const newPath = state?._global?.currentPath || window.location.pathname;
      setCurrentPath(newPath);
    });

    return unsubscribe;
  }, []);

  // 브라우저 뒤로가기/앞으로가기 감지
  useEffect(() => {
    const handlePopState = () => {
      setCurrentPath(window.location.pathname);
    };

    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, []);

  // currentLocale prop이 변경되면 우선 적용
  useEffect(() => {
    if (currentLocaleProp) {
      setLocale(currentLocaleProp);
    }
  }, [currentLocaleProp]);

  /**
   * 특정 경로가 메뉴 URL과 일치하는지 확인
   * 접두사 매칭 시 가장 구체적인(가장 긴) 매칭만 활성화 (Issue #37)
   *
   * @param url 메뉴 URL
   * @param path 확인할 경로
   * @returns 일치 여부
   */
  const isPathActive = (url: string | null | undefined, path: string): boolean => {
    if (!url || typeof path !== 'string') return false;
    if (path === url) return true;
    if (path.startsWith(url + '/')) {
      return findBestMatch(path) === url;
    }
    return false;
  };

  /**
   * 활성 메뉴의 부모 ID들을 찾아 반환
   *
   * @param items 메뉴 아이템 배열
   * @param path 현재 경로
   * @returns 활성 부모 ID Set
   */
  const findActiveParentIds = (items: MenuItem[], path: string): Set<string | number> => {
    const parents = new Set<string | number>();

    const traverse = (menuItems: MenuItem[]): boolean => {
      for (const item of menuItems) {
        if (item.children && item.children.length > 0) {
          const hasActiveInChildren = traverse(item.children);
          if (hasActiveInChildren || isPathActive(item.url, path)) {
            parents.add(item.id);
            return true;
          }
        } else if (isPathActive(item.url, path)) {
          return true;
        }
      }
      return false;
    };

    traverse(items);
    return parents;
  };

  // 초기 로드 및 경로 변경 시 활성 메뉴의 부모를 자동 펼침
  useEffect(() => {
    if (menu.length > 0 && currentPath) {
      const activeParents = findActiveParentIds(menu, currentPath);
      if (activeParents.size > 0) {
        setExpandedItems((prev) => new Set([...prev, ...activeParents]));
      }
    }
  }, [currentPath, menu]);

  /**
   * 메뉴 URL이 현재 경로와 일치하는지 확인
   * 접두사 매칭 시 가장 구체적인 매칭만 활성화 (Issue #37)
   *
   * @param url 메뉴 URL
   * @returns 활성 여부
   */
  const isActiveMenu = (url: string | null | undefined): boolean => {
    return isPathActive(url, currentPath);
  };

  /**
   * 하위 메뉴 중 활성 메뉴가 있는지 확인 (부모 메뉴 강조용)
   *
   * @param item 메뉴 아이템
   * @returns 하위에 활성 메뉴 존재 여부
   */
  const hasActiveChild = (item: MenuItem): boolean => {
    if (!item.children) return false;
    return item.children.some((child) => isActiveMenu(child.url) || hasActiveChild(child));
  };

  /**
   * 하위 메뉴 토글
   */
  const toggleExpand = (itemId: string | number) => {
    setExpandedItems((prev) => {
      const newSet = new Set(prev);
      if (newSet.has(itemId)) {
        newSet.delete(itemId);
      } else {
        newSet.add(itemId);
      }
      return newSet;
    });
  };

  /**
   * 다국어 name을 현재 로케일에 맞게 반환
   */
  const getMenuLabel = (name: string | { ko: string; en: string; [key: string]: string }): string => {
    if (typeof name === 'string') return name;
    // 현재 로케일에 맞는 이름 반환, fallback으로 en → 첫 번째 값
    return name[locale] || name.en || Object.values(name)[0] || '';
  };

  /**
   * 메뉴 네비게이션 핸들러
   * G7Core.dispatch가 있으면 SPA 방식으로, 없으면 전체 페이지 리로드
   * 모바일에서는 페이지 로드 완료 후 사이드바를 닫음
   */
  const handleNavigate = (event: React.MouseEvent, url: string) => {
    if (G7Core()?.dispatch) {
      // G7Core.dispatch가 있으면 SPA 방식 네비게이션
      event.preventDefault();

      // 페이지 전환 완료 후 사이드바 닫기
      G7Core().navigation?.onComplete(() => {
        G7Core().dispatch({
          handler: 'setState',
          params: {
            target: 'global',
            sidebarOpen: false,
          },
        });
      });

      G7Core().dispatch({
        handler: 'navigate',
        params: { path: url },
      });
    } else {
      // fallback: 전체 페이지 리로드
      window.location.href = url;
    }
  };

  /**
   * 메뉴 아이템 렌더링 (재귀)
   */
  const renderMenuItem = (item: MenuItem, level: number = 0): React.ReactNode => {
    const hasChildren = item.children && item.children.length > 0;
    const isExpanded = expandedItems.has(item.id);
    const paddingLeft = level * 16 + 16;
    const label = getMenuLabel(item.name);

    // 활성 메뉴 판별
    const isActive = isActiveMenu(item.url);
    const hasActiveDescendant = hasActiveChild(item);

    // 스타일 클래스
    const baseClass = 'w-full flex items-center justify-between px-4 py-3 transition-colors';
    const activeClass = 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 border-l-4 border-blue-500';
    const activeParentClass = 'text-blue-600 dark:text-blue-400';
    const defaultClass = 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700';
    const disabledClass = 'text-gray-400 dark:text-gray-500 cursor-default';

    return (
      <Div key={item.id}>
        {/* 메뉴 아이템 */}
        {hasChildren && item.url ? (
          // 하위 메뉴 + URL 있는 경우: 텍스트 클릭 → 네비게이션, chevron 클릭 → 토글
          <Div
            className={`
              ${baseClass}
              ${isActive ? activeClass : hasActiveDescendant ? activeParentClass : defaultClass}
              ${collapsed ? 'justify-center' : ''}
            `}
            style={{ paddingLeft: collapsed ? undefined : `${paddingLeft}px` }}
          >
            <Button
              onClick={(event) => handleNavigate(event, item.url!)}
              className="flex items-center justify-start flex-1 min-w-0"
            >
              {item.icon && (
                <I
                  className={`${item.icon} ${collapsed ? '' : 'mr-3'} w-5 h-5 flex items-center justify-center`}
                />
              )}
              {!collapsed && <Span className="truncate">{label}</Span>}
            </Button>
            {!collapsed && (
              <Div className="flex items-center gap-1">
                {item.module_id && (
                  <I className="fas fa-cube w-3 h-3 mr-2 text-gray-400 dark:text-gray-500 opacity-60" title="모듈 메뉴" />
                )}
                <Button
                  onClick={() => toggleExpand(item.id)}
                  className="p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-600"
                  aria-label={t(isExpanded ? 'common.collapse' : 'common.expand')}
                >
                  <I
                    className={`fas fa-chevron-down w-4 h-4 transition-transform ${isExpanded ? 'rotate-180' : ''}`}
                  />
                </Button>
              </Div>
            )}
          </Div>
        ) : hasChildren ? (
          // 하위 메뉴만 있는 경우 (URL 없음): 전체 클릭 → 토글
          <Button
            onClick={() => toggleExpand(item.id)}
            className={`
              ${baseClass}
              ${hasActiveDescendant ? activeParentClass : defaultClass}
              ${collapsed ? 'justify-center' : ''}
            `}
            style={{ paddingLeft: collapsed ? undefined : `${paddingLeft}px` }}
          >
            <Div className="flex items-center">
              {item.icon && (
                <I
                  className={`${item.icon} ${collapsed ? '' : 'mr-3'} w-5 h-5 flex items-center justify-center`}
                />
              )}
              {!collapsed && <Span>{label}</Span>}
            </Div>
            {!collapsed && (
              <Div className="flex items-center gap-1">
                {item.module_id && (
                  <I className="fas fa-cube w-3 h-3 mr-2 text-gray-400 dark:text-gray-500 opacity-60" title="모듈 메뉴" />
                )}
                <I
                  className={`fas fa-chevron-down w-4 h-4 transition-transform ${isExpanded ? 'rotate-180' : ''}`}
                />
              </Div>
            )}
          </Button>
        ) : item.url ? (
          // 하위 메뉴가 없고 URL이 있는 경우: 네비게이션 버튼
          <Button
            onClick={(event) => handleNavigate(event, item.url!)}
            className={`
              ${baseClass}
              ${isActive ? activeClass : defaultClass}
              ${collapsed ? 'justify-center' : ''}
              text-left
            `}
            style={{ paddingLeft: collapsed ? undefined : `${paddingLeft}px` }}
          >
            <Div className="flex items-center">
              {item.icon && (
                <I
                  className={`${item.icon} ${collapsed ? '' : 'mr-3'} w-5 h-5 flex items-center justify-center`}
                />
              )}
              {!collapsed && <Span>{label}</Span>}
            </Div>
            {!collapsed && item.module_id && (
              <I className="fas fa-cube w-3 h-3 mr-2 text-gray-400 dark:text-gray-500 opacity-60" title="모듈 메뉴" />
            )}
          </Button>
        ) : (
          // 하위 메뉴도 없고 URL도 없는 경우: 비활성 버튼
          <Button
            className={`
              ${baseClass}
              ${disabledClass}
              ${collapsed ? 'justify-center' : ''}
              text-left
            `}
            style={{ paddingLeft: collapsed ? undefined : `${paddingLeft}px` }}
          >
            <Div className="flex items-center">
              {item.icon && (
                <I
                  className={`${item.icon} ${collapsed ? '' : 'mr-3'} w-5 h-5 flex items-center justify-center`}
                />
              )}
              {!collapsed && <Span>{label}</Span>}
            </Div>
            {!collapsed && item.module_id && (
              <I className="fas fa-cube w-3 h-3 mr-2 text-gray-400 dark:text-gray-500 opacity-60" title="모듈 메뉴" />
            )}
          </Button>
        )}

        {/* 하위 메뉴 */}
        {hasChildren && !collapsed && isExpanded && (
          <Div className="bg-gray-50 dark:bg-gray-900">
            {item.children!.map((child) => renderMenuItem(child, level + 1))}
          </Div>
        )}
      </Div>
    );
  };

  return (
    <Div className={className}>
      {/* 로고 영역 (선택적) */}
      {(logo || onToggleCollapse) && (
        <Div className="flex items-center justify-between px-4 py-4 border-b border-gray-200 dark:border-gray-700">
          {logo && !collapsed && (
            <Img
              src={logo}
              alt={logoAlt}
              className="h-8"
            />
          )}
          {onToggleCollapse && (
            <Button
              onClick={onToggleCollapse}
              className="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-md transition-colors"
              aria-label={collapsed ? t('common.expand_sidebar') : t('common.collapse_sidebar')}
            >
              <I
                className={`${collapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left'} w-5 h-5 text-gray-600 dark:text-gray-400`}
              />
            </Button>
          )}
        </Div>
      )}

      {/* 메뉴 영역 */}
      <Div>
        {menu.map((item) => renderMenuItem(item))}
      </Div>
    </Div>
  );
};
