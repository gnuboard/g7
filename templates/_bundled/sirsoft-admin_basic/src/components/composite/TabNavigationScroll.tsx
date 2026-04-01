import React, { useState, useEffect, useRef } from 'react';
import { Button } from '../basic/Button';
import { Div } from '../basic/Div';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Span } from '../basic/Span';
import { Select } from '../basic/Select';
import { scrollToSectionHandler } from '../../handlers/scrollToSectionHandler';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:TabNavigation')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:TabNavigation]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:TabNavigation]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:TabNavigation]', ...args),
};

/**
 * 탭 정의 인터페이스
 */
export interface Tab {
  id: string | number;
  label: string;
  /** 탭 아이콘 (Font Awesome 아이콘명) */
  iconName?: IconName;
  disabled?: boolean;
  /** 개별 탭의 스크롤 오프셋 (우선순위: 개별 > 공통 > 기본값) */
  scrollOffset?: number;
  /** 개별 탭의 스크롤 딜레이 (우선순위: 개별 > 공통 > 기본값) */
  scrollDelay?: number;
  /** 개별 탭 클릭 시 실행될 콜백 (우선순위: 개별 > 공통 > 기본) */
  onClick?: (tabId: string | number) => void;
}

/**
 * TabNavigationScroll Props
 */
export interface TabNavigationScrollProps {
  /** 탭 목록 */
  tabs: Tab[];
  /** 활성 탭 ID (초기값으로만 사용) */
  activeTabId?: string | number;
  /** 컨테이너 className */
  className?: string;
  /** 컨테이너 스타일 */
  style?: React.CSSProperties;
  /** Active 탭 className */
  activeClassName?: string;
  /** Inactive 탭 className */
  inactiveClassName?: string;
  /** 섹션 ID 접두사 */
  sectionIdPrefix?: string;
  /** 스크롤 오프셋 */
  scrollOffset?: number;
  /** 스크롤 딜레이 */
  scrollDelay?: number;
  /** 탭 변경 시 실행될 공통 콜백 (우선순위: 개별 탭 onClick > onTabChange > 기본) */
  onTabChange?: (tabId: string | number) => void;
  /** Scroll Spy 활성화 여부 (스크롤 시 자동으로 탭 활성화) */
  enableScrollSpy?: boolean;
  /** Scroll Spy 감지 오프셋 (기본값: 80) */
  scrollSpyOffset?: number;
  /** 스크롤 컨테이너 ID (IntersectionObserver root 및 scrollToSection 대상, 미지정 시 window) */
  scrollContainerId?: string;
}

/**
 * 탭 네비게이션 컴포넌트 (자동 스크롤 기능 내장)
 *
 * 특징:
 * - 내부 상태로 활성 탭 관리 (React useState)
 * - scrollToSectionHandler를 사용하여 자동 스크롤
 * - 탭 클릭 시 즉시 UI 업데이트 + 스크롤 이동
 *
 * @example
 * ```json
 * {
 *   "type": "composite",
 *   "name": "TabNavigationScroll",
 *   "props": {
 *     "tabs": [
 *       {"id": "basic", "label": "$t:admin.tabs.basic"},
 *       {"id": "permissions", "label": "$t:admin.tabs.permissions"}
 *     ]
 *   }
 * }
 * ```
 */
export const TabNavigationScroll: React.FC<TabNavigationScrollProps> = ({
  tabs,
  activeTabId,
  className = 'bg-white dark:bg-gray-800 flex gap-1 border-b border-gray-200 dark:border-gray-700 px-6',
  style,
  activeClassName = 'flex items-center gap-2 px-3 py-2 border-b-2 border-blue-500 bg-white dark:bg-gray-800 text-blue-600 dark:text-blue-400 font-medium -mb-px text-sm',
  inactiveClassName = 'flex items-center gap-2 px-3 py-2 border-b-2 border-transparent text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-600 text-sm',
  sectionIdPrefix = 'tab_content_',
  scrollOffset = 120,
  scrollDelay = 100,
  onTabChange,
  enableScrollSpy = false,
  scrollSpyOffset = 80,
  scrollContainerId,
}) => {
  const [activeTab, setActiveTab] = useState<string | number>(
    activeTabId ?? (tabs.length > 0 ? tabs[0].id : '')
  );

  // Scroll Spy 일시 중지를 위한 ref
  const isScrollingRef = useRef(false);
  const scrollTimeoutRef = useRef<NodeJS.Timeout | null>(null);

  /**
   * 탭 클릭 핸들러
   * 우선순위: 개별 탭 onClick > 공통 onTabChange > 기본 동작
   */
  const handleTabClick = (tab: Tab) => {
    // Scroll Spy 일시 중지
    if (enableScrollSpy) {
      isScrollingRef.current = true;
      if (scrollTimeoutRef.current) {
        clearTimeout(scrollTimeoutRef.current);
      }
    }

    // 우선순위 1: 개별 탭의 onClick이 있으면 실행
    if (tab.onClick) {
      tab.onClick(tab.id);
      // enableScrollSpy가 아니면 onClick에 전적으로 위임
      if (!enableScrollSpy) {
        return;
      }
    }
    // 우선순위 2: 공통 onTabChange가 있으면 실행
    else if (onTabChange) {
      onTabChange(tab.id);
      // enableScrollSpy가 아니면 onTabChange에 전적으로 위임
      if (!enableScrollSpy) {
        return;
      }
    }
    // 우선순위 3: 콜백이 없으면 내부 상태 업데이트
    else {
      setActiveTab(tab.id);
    }

    // 개별 탭 설정 우선, 없으면 공통 설정 사용
    const finalOffset = tab.scrollOffset ?? scrollOffset;
    const finalDelay = tab.scrollDelay ?? scrollDelay;

    scrollToSectionHandler(
      {
        params: {
          targetId: `${sectionIdPrefix}${tab.id}`,
          offset: finalOffset,
          delay: finalDelay,
          scrollContainerId,
        },
      },
      {}
    );

    // 스크롤 완료 후 Scroll Spy 재활성화 (딜레이 + 애니메이션 시간 고려)
    if (enableScrollSpy) {
      scrollTimeoutRef.current = setTimeout(() => {
        isScrollingRef.current = false;
      }, finalDelay + 500);
    }
  };

  /**
   * Scroll Spy 구현 (IntersectionObserver 사용)
   *
   * 짧은 섹션과 긴 섹션이 혼재된 경우를 고려한 알고리즘:
   * - 여러 섹션이 동시에 보일 경우, 가장 많이 보이는 섹션을 활성화
   * - intersectionRatio를 비교하여 최적의 섹션 선택
   * - 스크롤이 페이지 하단에 도달하면 마지막 탭 활성화 (짧은 마지막 섹션 대응)
   */
  useEffect(() => {
    if (!enableScrollSpy) {
      return;
    }

    const rootElement = scrollContainerId
      ? document.getElementById(scrollContainerId)
      : null;

    const observerOptions = {
      root: rootElement ?? null,
      // 상단 오프셋 + 하단 30% 여유 공간 (짧은 섹션도 감지 가능)
      rootMargin: `-${scrollSpyOffset}px 0px -30% 0px`,
      // 0.1 단위로 교차 비율 감지 (더 정밀한 감지)
      threshold: [0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0],
    };

    // 현재 보이는 섹션들의 교차 비율을 저장
    const intersectingEntries = new Map<string, number>();

    // 페이지 하단 도달 확인 헬퍼 함수 (scrollContainer 기준)
    const isAtBottom = () => {
      const container = rootElement;
      const scrollTop = container
        ? container.scrollTop
        : window.pageYOffset || document.documentElement.scrollTop;
      const scrollHeight = container
        ? container.scrollHeight
        : document.documentElement.scrollHeight;
      const clientHeight = container
        ? container.clientHeight
        : document.documentElement.clientHeight;
      // 10px 여유를 두고 하단 체크 (정확한 하단 감지)
      const atBottom = scrollTop + clientHeight >= scrollHeight - 10;

      // 디버깅 로그 (개발 환경에서만)
      if (process.env.NODE_ENV === 'development' && atBottom) {
        logger.log('Bottom reached:', {
          scrollTop,
          clientHeight,
          scrollHeight,
          remaining: scrollHeight - (scrollTop + clientHeight),
        });
      }

      return atBottom;
    };

    const observerCallback = (entries: IntersectionObserverEntry[]) => {
      // 수동 스크롤 중에는 Scroll Spy 비활성화
      if (isScrollingRef.current) {
        return;
      }

      // 교차 비율 업데이트
      entries.forEach((entry) => {
        const targetId = entry.target.id;
        if (entry.isIntersecting) {
          intersectingEntries.set(targetId, entry.intersectionRatio);
        } else {
          intersectingEntries.delete(targetId);
        }
      });

      // 가장 많이 보이는 섹션 찾기
      let maxRatio = 0;
      let mostVisibleId = '';

      intersectingEntries.forEach((ratio, id) => {
        if (ratio > maxRatio) {
          maxRatio = ratio;
          mostVisibleId = id;
        }
      });

      // 하단 도달 시 마지막 탭 활성화 (단, 마지막 섹션이 실제로 보이고 있을 때만)
      const lastTab = tabs[tabs.length - 1];
      const lastSectionId = `${sectionIdPrefix}${lastTab?.id}`;
      const isLastSectionVisible = intersectingEntries.has(lastSectionId);

      if (isAtBottom() && isLastSectionVisible && lastTab) {
        if (process.env.NODE_ENV === 'development') {
          logger.log('Activating last tab (bottom reached + last section visible):', lastTab.id);
        }
        setActiveTab(lastTab.id);
        return;
      }

      // 가장 많이 보이는 섹션의 탭 활성화
      if (mostVisibleId) {
        const tabId = mostVisibleId.replace(sectionIdPrefix, '');
        const numericTabId = Number(tabId);
        const finalTabId = isNaN(numericTabId) ? tabId : numericTabId;

        const tabExists = tabs.some((tab) => tab.id === finalTabId);
        if (tabExists) {
          setActiveTab(finalTabId);
        }
      }
    };

    // 스크롤 이벤트 핸들러 (현재는 IntersectionObserver가 모든 처리를 담당)
    const handleScroll = () => {
      // IntersectionObserver가 모든 탭 활성화를 처리하므로 여기서는 추가 동작 없음
      // 향후 필요시 추가 로직 구현 가능
    };

    const observer = new IntersectionObserver(observerCallback, observerOptions);

    // 모든 섹션 관찰 시작
    tabs.forEach((tab) => {
      const sectionElement = document.getElementById(`${sectionIdPrefix}${tab.id}`);
      if (sectionElement) {
        observer.observe(sectionElement);
      }
    });

    // 스크롤 이벤트 리스너 등록
    window.addEventListener('scroll', handleScroll, { passive: true });

    // Cleanup
    return () => {
      observer.disconnect();
      window.removeEventListener('scroll', handleScroll);
      if (scrollTimeoutRef.current) {
        clearTimeout(scrollTimeoutRef.current);
      }
    };
  }, [enableScrollSpy, tabs, sectionIdPrefix, scrollSpyOffset, scrollContainerId]);

  /**
   * Select 변경 핸들러 (모바일용)
   */
  const handleSelectChange = (
    e: React.ChangeEvent<HTMLSelectElement> | { target: { value: string | number } }
  ) => {
    const selectedId = e.target.value;
    // 숫자형 ID 변환
    const numericId = Number(selectedId);
    const finalId = isNaN(numericId) ? selectedId : numericId;

    const selectedTab = tabs.find((tab) => tab.id === finalId);
    if (selectedTab) {
      handleTabClick(selectedTab);
    }
  };

  return (
    <>
      {/* 데스크톱: 탭 버튼 (md 이상) */}
      <Div className={`hidden md:flex ${className}`} style={style}>
        {tabs.map((tab) => (
          <Button
            key={tab.id}
            className={activeTab === tab.id ? activeClassName : inactiveClassName}
            disabled={tab.disabled}
            onClick={() => handleTabClick(tab)}
          >
            {tab.iconName && (
              <Icon name={tab.iconName} size="sm" />
            )}
            <Span>{tab.label}</Span>
          </Button>
        ))}
      </Div>

      {/* 모바일: Select 박스 (md 미만) */}
      <Div className="md:hidden bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <Select
          value={String(activeTab)}
          onChange={handleSelectChange}
          className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
        >
          {tabs.map((tab) => (
            <option key={tab.id} value={String(tab.id)} disabled={tab.disabled}>
              {tab.label}
            </option>
          ))}
        </Select>
      </Div>
    </>
  );
};
