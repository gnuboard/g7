import React, { useEffect, useRef } from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/** 열린 파일 탭 1건. */
export interface FileTabItem {
  /** 노드 키 — `layout:<name>` 또는 `ext:<id>` */
  key: string;
  /** 탭에 보일 짧은 이름 */
  label: string;
  /** 전체 경로 (툴팁) */
  title?: string;
  /** 미저장 여부 — ● 점 */
  dirty?: boolean;
  /**
   * 서버에 더 이상 없는 파일인지 (지워졌거나 이름이 바뀜).
   *
   * 초안이 파일보다 오래 살아 복원이 «없는 파일» 의 탭을 세울 수 있다. 그 탭을 여느 탭과
   * 똑같이 그리면 사용자는 왜 내용이 안 오는지 알 수 없다 — 실측 2026-09-12 에는 빈 편집기와
   * 범용 404 문구만 떴다.
   */
  missing?: boolean;
}

export interface FileTabsProps {
  tabs?: FileTabItem[];
  activeKey?: string;
  /** 터치 환경에서 탭을 조금 키운다 */
  size?: 'compact' | 'touch';
  onSelect?: (key: string) => void;
  onClose?: (key: string) => void;
  className?: string;
  id?: string;
}

/**
 * 열린 파일 탭 줄.
 *
 * 편집기가 한 번에 한 파일만 보여 주더라도, **무엇이 열려 있고 그중 무엇이 저장되지 않았는지**는
 * 늘 보여야 한다. 미저장 표시는 트리와 같은 규약을 쓴다 — 점 모양 + `aria-label`.
 *
 * 좁은 폭에서는 한 줄 가로 스크롤이고, 활성 탭은 스스로 시야 안으로 들어온다 — 단축키
 * (`Ctrl+Alt+←/→`)로 탭을 옮기면 화면 밖으로 나간 탭이 활성이 되는 일이 생기기 때문이다.
 */
export const FileTabs: React.FC<FileTabsProps> = ({
  tabs = [],
  activeKey,
  size = 'compact',
  onSelect,
  onClose,
  className = '',
  id,
}) => {
  const activeRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    // `scrollIntoView` 는 브라우저에만 있다 — 없는 환경(jsdom)에서 부르면 예외가 나 탭 줄 전체가
    // 렌더되지 않는다. 시야로 끌어오는 것은 편의이지 계약이 아니므로 있으면 쓰고 없으면 넘어간다.
    activeRef.current?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
  }, [activeKey]);

  if (tabs.length === 0) {
    return null;
  }

  const height = size === 'touch' ? 'h-10' : 'h-9';

  return (
    <Div
      id={id}
      role="tablist"
      aria-label={t('admin.templates.layout_edit.tabs.aria_label')}
      className={`flex items-stretch overflow-x-auto bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 ${className}`}
      style={{ scrollSnapType: 'x proximity' }}
    >
      {tabs.map((tab) => {
        const isActive = tab.key === activeKey;

        return (
          <Div
            key={tab.key}
            ref={isActive ? activeRef : undefined}
            role="tab"
            aria-selected={isActive}
            tabIndex={isActive ? 0 : -1}
            title={tab.title ?? tab.label}
            data-testid={`file-tab-${tab.key}`}
            // §7.6 탭 토큰 — 활성 `bg-white` + 파랑 2px 밑줄 / 비활성 `text-gray-600`(다크 `gray-400`).
            // 밑줄은 `border-b-2` 대신 inset shadow 로 긋는다: border 는 박스 높이를 2px 키워
            // 활성·비활성 탭의 높이가 갈리고 글자 기준선이 튄다. 비활성 배경(`bg-gray-50`)은 줄
            // 컨테이너(위 `Div`)가 소유한다 — 탭마다 칠하면 스크롤 빈 자리만 색이 빠진다.
            className={`flex items-center gap-2 px-3 shrink-0 cursor-pointer border-r border-gray-200 dark:border-gray-700 max-w-[180px] whitespace-nowrap text-xs transition-colors ${height} ${
              isActive
                ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 shadow-[inset_0_-2px_0_0_#3b82f6]'
                : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'
            } focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500`}
            style={{ scrollSnapAlign: 'start' }}
            onClick={() => onSelect?.(tab.key)}
            onKeyDown={(event: React.KeyboardEvent) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                onSelect?.(tab.key);
              }
            }}
          >
            {tab.missing && (
              <Icon
                name="fa-solid fa-link-slash"
                className="text-[10px] text-red-500 dark:text-red-400 shrink-0"
                role="img"
                aria-label={t('admin.templates.layout_edit.file.missing_badge')}
              />
            )}
            {tab.dirty && (
              <Span
                className="w-[7px] h-[7px] rounded-full bg-amber-500 dark:bg-amber-400 shrink-0"
                role="img"
                aria-label={t('admin.templates.layout_edit.tree.unsaved')}
              />
            )}
            <Span className={`truncate ${tab.missing ? 'line-through text-red-600 dark:text-red-400' : ''}`}>
              {tab.label}
            </Span>
            <Span
              role="button"
              tabIndex={-1}
              aria-label={t('admin.templates.layout_edit.tabs.close', { name: tab.label })}
              className="w-4 text-center text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 shrink-0"
              onClick={(event: React.MouseEvent) => {
                // 닫기는 탭 선택과 다른 동작이다 — 전파를 막지 않으면 닫으면서 동시에 연다.
                event.stopPropagation();
                onClose?.(tab.key);
              }}
            >
              <Icon name="fa-solid fa-xmark" className="text-[11px]" />
            </Span>
          </Div>
        );
      })}
    </Div>
  );
};
