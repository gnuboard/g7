import React, { useState, useEffect, useRef, useMemo } from 'react';
import { Button } from '../basic/Button';
import { Div } from '../basic/Div';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Span } from '../basic/Span';
import { Select } from '../basic/Select';
import { scrollToSectionHandler } from '../../handlers/scrollToSectionHandler';
import type { EditorAttrs } from '../../types';
import { sitePath } from '../../support/apiUrl';
import { handleLinkClick, resolveLinkHref } from '../../support/navigationLink';
import { useRenderWidth } from '../../hooks/useRenderWidth';

// G7Core.t() 번역 함수 참조 — 번역이 없거나 엔진 밖이면 대체 문구를 쓴다
const tOr = (key: string, fallback: string, params?: Record<string, string | number>): string => {
  const translated = (window as any).G7Core?.t?.(key, params);
  return typeof translated === 'string' && translated !== key ? translated : fallback;
};

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
  /**
   * 표시 조건. 레이아웃의 `if="{{...}}"` 가 평가된 값이며, 키가 있고 값이 거짓(false·undefined·null·빈 문자열·0)이면
   * 탭을 그리지 않는다 — 노드의 `if` 와 같은 뜻이다(`{{route?.id}}` 처럼 거짓이 undefined 로 오는 식도 숨는다).
   * 확장이 끼워 넣는 탭은 이동할 본문 카드와 같은 조건을 달아, 카드가 없을 때 갈 곳 없는 빈 탭이 남지 않게 한다.
   */
  if?: unknown;
  /** 개별 탭의 스크롤 오프셋 (우선순위: 개별 > 공통 > 기본값) */
  scrollOffset?: number;
  /** 개별 탭의 스크롤 딜레이 (우선순위: 개별 > 공통 > 기본값) */
  scrollDelay?: number;
  /** 개별 탭 클릭 시 실행될 콜백 (우선순위: 개별 > 공통 > 기본) */
  onClick?: (tabId: string | number) => void;
  /**
   * 탭이 가리키는 주소 — 주면 `<a role="tab" href>` 로 그려 가운데 클릭·Ctrl+클릭으로 새 탭에서 열 수 있다.
   * 보통 클릭은 지금처럼 탭 전환(onTabChange — 저장 안 한 변경 확인 흐름 유지).
   */
  href?: string;
  /**
   * 탭 이름 옆에 빨간 점을 붙일지 — 그 탭이 가리키는 구획에 확인할 것(검증 오류가 난 칸 등)이 있음을 알린다.
   * 레이아웃의 `errorDot="{{...}}"` 가 평가된 값이다. 점의 읽기 이름은 컴포넌트의 `errorDotLabel` 이 준다.
   */
  errorDot?: boolean | string;
}

/**
 * 탭에 빨간 점을 붙일지 판정합니다 (레이아웃 식이 글자로 풀려 와도 받는다).
 *
 * @param tab 탭
 * @returns 붙이면 true
 */
const hasErrorDot = (tab: Tab): boolean => tab.errorDot === true || tab.errorDot === 'true';

/**
 * 항목 조건(if)을 통과한 탭만 남깁니다.
 *
 * @param tabs 레이아웃이 넘긴 탭 목록
 * @returns 보여 줄 탭 목록
 */
const filterVisibleTabs = (tabs: Tab[] | undefined): Tab[] =>
  (tabs ?? []).filter((tab) => tab != null && (!Object.prototype.hasOwnProperty.call(tab, 'if') || Boolean(tab.if)));

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
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
  /**
   * 좁은 화면에서 탭 대신 그리는 선택 상자의 이름(「{이름} {현재 탭}」으로 읽힌다).
   * 없으면 공용 문구 「섹션 이동」 — 종전에는 이름이 없어 현재 탭 글자(「기본정보」)만 읽혔다
   */
  'aria-label'?: string;
  /** 탭의 빨간 점(`errorDot`)을 보조기기가 읽을 이름 (예: 「오류가 있는 칸이 있습니다」). 없으면 공용 문구 */
  errorDotLabel?: string;
}

/** 탭 이름 옆 빨간 점 — 그 구획에 확인할 것이 있음을 알린다 */
const ERROR_DOT_CLASS = 'inline-block w-2 h-2 ml-1.5 shrink-0 rounded-full bg-red-500 dark:bg-red-400';

/**
 * 탭 네비게이션 컴포넌트 (자동 스크롤 기능 내장)
 *
 * 특징:
 * - 내부 상태로 활성 탭 관리 (React useState)
 * - scrollToSectionHandler를 사용하여 자동 스크롤
 * - 탭 클릭 시 즉시 UI 업데이트 + 스크롤 이동
 * - 반응형: 렌더 폭(useRenderWidth — G7Core.useResponsive Provider 폭 우선)이 768px 미만이면
 *   Select 드롭다운으로 자동 전환 (Tailwind 반응형 접두 분기 미사용 — 위지윅 미리보기 호환).
 *   두 모양 모두 감싸개 여백은 레이아웃이 준 className 을 따른다
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
/** 이 폭(px)부터 탭 버튼을 그린다 — 미만이면 선택 상자 하나로 바꾼다 */
const MOBILE_MIN_DESKTOP_WIDTH = 768;

export const TabNavigationScroll: React.FC<TabNavigationScrollProps> = ({
  tabs: rawTabs,
  activeTabId,
  className = 'bg-white dark:bg-gray-800 flex gap-2 border-b border-gray-200 dark:border-gray-700 px-6',
  style,
  activeClassName = 'tab-btn-base tab-btn-default-active',
  inactiveClassName = 'tab-btn-base tab-btn-default',
  sectionIdPrefix = 'tab_content_',
  scrollOffset = 120,
  scrollDelay = 100,
  onTabChange,
  enableScrollSpy = false,
  scrollSpyOffset = 80,
  scrollContainerId,
  id,
  editorAttrs,
  'aria-label': ariaLabel,
  errorDotLabel,
}) => {
  const dotLabel = errorDotLabel || tOr('common.error_occurred', 'Error');
  // 항목 조건(if)이 거짓인 탭은 버튼·모바일 선택지·화살표 이동·Scroll Spy 모두에서 뺀다.
  const tabs = useMemo(() => filterVisibleTabs(rawTabs), [rawTabs]);

  const [activeTab, setActiveTab] = useState<string | number>(
    activeTabId ?? (tabs.length > 0 ? tabs[0].id : '')
  );

  // 모바일 판정은 렌더 폭으로 한다 — `G7Core.useResponsive()` 의 Provider 폭(편집기 미리보기에서는 기기 폭)이 우선이고,
  // 훅이 없을 때만 창 폭을 따라간다(창 크기를 바꾸면 다시 고른다).
  const isMobile = useRenderWidth() < MOBILE_MIN_DESKTOP_WIDTH;

  // Scroll Spy 일시 중지를 위한 ref
  const isScrollingRef = useRef(false);
  const scrollTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  /**
   * 사용자가 누른 탭 — 사용자가 직접 스크롤(휠·터치·스크롤 키)할 때까지 Scroll Spy 가 활성 탭을 바꾸지 않는다.
   *
   * 스크롤 이동이 지연·애니메이션보다 길거나 섹션이 맨 위까지 올라오지 못하면(페이지 바닥) 다시 켜진 Scroll Spy 가
   * 더 많이 보이는 다른 섹션·마지막 탭을 활성으로 바꿔, 누른 탭이 아닌 탭이 선택된 것처럼 보였다(7.1.0 실측).
   */
  const pinnedTabRef = useRef<string | number | null>(null);

  /** 탭 버튼 DOM 참조 — 화살표 이동 시 초점을 옮기기 위해 보관한다. */
  const tabRefs = useRef<Array<HTMLElement | null>>([]);

  /**
   * 탭 클릭 핸들러
   * 우선순위: 개별 탭 onClick > 공통 onTabChange > 기본 동작
   */
  const handleTabClick = (tab: Tab) => {
    // Scroll Spy 일시 중지 — 누른 탭은 사용자가 직접 스크롤할 때까지 고정한다
    if (enableScrollSpy) {
      isScrollingRef.current = true;
      pinnedTabRef.current = tab.id;
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

    // 콜백이 있어도 Scroll Spy 를 쓰면 활성 표시는 이 컴포넌트가 그린다 — 누른 탭을 바로 활성으로 둔다
    if (enableScrollSpy && (tab.onClick || onTabChange)) {
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
   * WAI-ARIA Tabs 키보드 규약 — 좌우 화살표로 탭 이동, Home/End 로 양 끝 이동.
   *
   * 비활성 탭은 건너뛴다. 활성 탭만 Tab 키 초점을 받는 roving tabindex 를 쓰므로 탭 목록
   * 안에서의 이동은 화살표가 담당한다.
   *
   * @param e 키보드 이벤트
   * @param currentIndex 이벤트가 발생한 탭의 인덱스
   */
  const handleTabKeyDown = (e: React.KeyboardEvent<HTMLElement>, currentIndex: number) => {
    const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;

    let nextIndex: number | null = null;

    if (step !== 0) {
      for (let i = 1; i <= tabs.length; i += 1) {
        const candidate = (currentIndex + step * i + tabs.length * i) % tabs.length;
        if (!tabs[candidate]?.disabled) {
          nextIndex = candidate;
          break;
        }
      }
    } else if (e.key === 'Home') {
      nextIndex = tabs.findIndex((tab) => !tab.disabled);
    } else if (e.key === 'End') {
      for (let i = tabs.length - 1; i >= 0; i -= 1) {
        if (!tabs[i]?.disabled) {
          nextIndex = i;
          break;
        }
      }
    }

    if (nextIndex === null || nextIndex < 0) {
      return;
    }

    e.preventDefault();
    tabRefs.current[nextIndex]?.focus();
    handleTabClick(tabs[nextIndex]);
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
      // 교차 비율은 항상 갱신한다 — 고정이 풀린 뒤 첫 판정이 그동안 바뀐 보임 상태를 알아야 한다
      entries.forEach((entry) => {
        const targetId = entry.target.id;
        if (entry.isIntersecting) {
          intersectingEntries.set(targetId, entry.intersectionRatio);
        } else {
          intersectingEntries.delete(targetId);
        }
      });

      // 탭을 눌러 이동한 뒤에는 사용자가 직접 스크롤할 때까지 누른 탭을 그대로 둔다
      if (isScrollingRef.current || pinnedTabRef.current !== null) {
        return;
      }

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

    // 사용자가 직접 스크롤하면 누른 탭 고정을 푼다(휠·터치·스크롤 키). 프로그램 스크롤(탭 이동)은 이 이벤트를 내지 않는다.
    const scrollKeys = new Set(['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', ' ']);
    const releasePin = () => {
      pinnedTabRef.current = null;
      isScrollingRef.current = false;
      if (scrollTimeoutRef.current) {
        clearTimeout(scrollTimeoutRef.current);
      }
    };
    const handleUserScrollKey = (e: KeyboardEvent) => {
      if (!scrollKeys.has(e.key)) {
        return;
      }
      const target = e.target as HTMLElement | null;
      // 탭 목록 안 화살표 이동·입력칸 커서 이동은 스크롤 의도가 아니다
      if (target?.closest?.('[role="tab"], input, textarea, select, [contenteditable="true"]')) {
        return;
      }
      releasePin();
    };
    window.addEventListener('wheel', releasePin, { passive: true });
    window.addEventListener('touchmove', releasePin, { passive: true });
    window.addEventListener('keydown', handleUserScrollKey);

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
      window.removeEventListener('wheel', releasePin);
      window.removeEventListener('touchmove', releasePin);
      window.removeEventListener('keydown', handleUserScrollKey);
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

  // 모바일: Select 드롭다운 단일 렌더
  if (isMobile) {
    return (
      // 감싸개는 레이아웃이 준 className 을 그대로 쓰고 선택 상자 둘레 위아래 여백만 더한다 — 종전에는 자기 클래스(px-4)로
      // 갈아 끼워 레이아웃이 준 좌우 여백(게시판 설정 하위 탭 막대 24px)이 640 이하에서 16px 로 줄었다.
      <Div className={`${className} py-3`} style={style} id={id} {...editorAttrs}>
        <Select
          value={String(activeTab)}
          onChange={handleSelectChange}
          options={tabs.map((tab) => ({
            value: String(tab.id),
            // 선택 상자에는 점을 그릴 수 없어 이름 뒤에 표식을 붙인다
            label: hasErrorDot(tab) ? `${tab.label} ●` : tab.label,
            disabled: tab.disabled,
          }))}
          className="w-full"
          aria-label={ariaLabel || tOr('common.section_navigation', 'Section navigation')}
        />
      </Div>
    );
  }

  // 활성 탭이 없으면 첫 활성화 가능 탭이 Tab 키 초점을 받는다 (roving tabindex 진입점 보장).
  const activeIndex = tabs.findIndex((tab) => tab.id === activeTab);
  const focusableIndex = activeIndex >= 0 ? activeIndex : tabs.findIndex((tab) => !tab.disabled);

  // 데스크톱: 탭 버튼 단일 렌더
  return (
    <Div
      className={className}
      style={style}
      role="tablist"
      aria-orientation="horizontal"
      id={id}
      {...editorAttrs}
    >
      {tabs.map((tab, tabIndex) => {
        const link = tab.disabled ? null : resolveLinkHref(tab.href);
        if (link && !link.external) {
          return (
            <a
              key={tab.id}
              ref={(el) => {
                tabRefs.current[tabIndex] = el;
              }}
              href={sitePath(link.href)}
              role="tab"
              aria-selected={activeTab === tab.id}
              tabIndex={tabIndex === focusableIndex ? 0 : -1}
              className={`button ${activeTab === tab.id ? activeClassName : inactiveClassName}`}
              onClick={(event) => handleLinkClick(event, () => handleTabClick(tab))}
              onKeyDown={(e) => handleTabKeyDown(e, tabIndex)}
            >
              {tab.iconName && (
                <Icon name={tab.iconName} size="sm" />
              )}
              <Span>{tab.label}</Span>
              {hasErrorDot(tab) && <Span className={ERROR_DOT_CLASS} role="img" aria-label={dotLabel} data-tab-error-dot="true" />}
            </a>
          );
        }
        return (
        <Button
          key={tab.id}
          ref={(el) => {
            tabRefs.current[tabIndex] = el;
          }}
          type="button"
          role="tab"
          aria-selected={activeTab === tab.id}
          tabIndex={tabIndex === focusableIndex ? 0 : -1}
          className={activeTab === tab.id ? activeClassName : inactiveClassName}
          disabled={tab.disabled}
          onClick={() => handleTabClick(tab)}
          onKeyDown={(e) => handleTabKeyDown(e, tabIndex)}
        >
          {tab.iconName && (
            <Icon name={tab.iconName} size="sm" />
          )}
          <Span>{tab.label}</Span>
          {hasErrorDot(tab) && <Span className={ERROR_DOT_CLASS} role="img" aria-label={dotLabel} data-tab-error-dot="true" />}
        </Button>
        );
      })}
    </Div>
  );
};
