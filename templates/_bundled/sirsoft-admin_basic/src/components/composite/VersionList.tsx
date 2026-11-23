import React, { useCallback } from 'react';
import { Ul } from '../basic/Ul';
import { Li } from '../basic/Li';
import { Span } from '../basic/Span';
import { Div } from '../basic/Div';
import { activateOnKey, FOCUS_VISIBLE_RING } from '../../support/keyboardActivation';

export interface VersionItem {
  id: string | number;
  version: string;
  created_at: string;
  changes_summary: {
    added: number;
    removed: number;
  };
}

export interface VersionListProps {
  versions: VersionItem[];
  selectedId?: string | number;
  onSelect?: (id: string | number) => void;
}

const formatDate = (dateString: string): string => {
  const date = new Date(dateString);
  return new Intl.DateTimeFormat('ko-KR', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
};

interface VersionListItemProps {
  version: VersionItem;
  isSelected: boolean;
  onSelect?: (id: string | number) => void;
}

const VersionListItem: React.FC<VersionListItemProps> = React.memo(({ version, isSelected, onSelect }) => {
  const handleClick = useCallback(() => {
    onSelect?.(version.id);
  }, [onSelect, version.id]);

  return (
    <Li
      // 고르는 목록의 항목 — aria-selected 는 option 에만 뜻이 있다. Tab 으로 닿고 Enter·Space 로 고른다(#869 키보드 접근)
      role="option"
      aria-selected={isSelected}
      tabIndex={0}
      onKeyDown={activateOnKey(handleClick)}
      className={`
        ${FOCUS_VISIBLE_RING} p-3 rounded-lg border cursor-pointer transition-colors
        ${
          isSelected
            ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
            : 'border-gray-300 hover:border-gray-400 dark:border-gray-600 dark:hover:border-gray-500'
        }
      `}
      onClick={handleClick}
    >
      <Div className="flex justify-between items-start">
        <Div className="flex-1">
          <Span className="font-semibold text-gray-900 dark:text-gray-100">
            버전 {version.version}
          </Span>
          <Span className="ml-2 text-sm text-gray-600 dark:text-gray-400">
            {formatDate(version.created_at)}
          </Span>
        </Div>

        <Div className="flex gap-3 text-sm">
          <Span className="text-green-700 dark:text-green-400">
            +{version.changes_summary.added}
          </Span>
          <Span className="text-red-700 dark:text-red-400">
            -{version.changes_summary.removed}
          </Span>
        </Div>
      </Div>
    </Li>
  );
});

VersionListItem.displayName = 'VersionListItem';

/**
 * 레이아웃 버전 목록을 표시하는 컴포넌트
 * 각 버전의 변경 요약을 표시하고 선택 기능을 제공합니다.
 */
export const VersionList: React.FC<VersionListProps> = ({
  versions,
  selectedId,
  onSelect,
}) => {
  if (versions.length === 0) {
    return (
      <Div className="p-4 text-center text-muted">
        버전이 없습니다.
      </Div>
    );
  }

  return (
    <Ul
      role="listbox"
      className="space-y-2"
    >
      {versions.map((version) => (
        <VersionListItem
          key={version.id}
          version={version}
          isSelected={selectedId === version.id}
          onSelect={onSelect}
        />
      ))}
    </Ul>
  );
};
