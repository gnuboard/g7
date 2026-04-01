import React from 'react';
import { Div } from '../basic/Div';
import { Ul } from '../basic/Ul';
import { Li } from '../basic/Li';
import { Span } from '../basic/Span';

export interface LayoutFileItem {
  /**
   * 파일 ID
   */
  id: string | number;

  /**
   * 파일명
   */
  name: string;

  /**
   * 최종 수정 시간
   */
  updated_at: string;
}

export interface LayoutFileListProps {
  /**
   * 파일 목록
   */
  files: LayoutFileItem[];

  /**
   * 선택된 파일 ID
   */
  selectedId?: string | number;

  /**
   * 파일 선택 시 콜백
   */
  onSelect: (id: string | number) => void;

  /**
   * 사용자 정의 클래스
   */
  className?: string;
}

/**
 * LayoutFileList 레이아웃 파일 목록 컴포넌트
 *
 * 레이아웃 파일 목록을 표시하고 선택 기능을 제공하는 composite 컴포넌트입니다.
 * 선택된 파일은 하이라이트되며, 접근성을 위해 ARIA 속성을 지원합니다.
 *
 * @example
 * // 기본 사용
 * <LayoutFileList
 *   files={[
 *     { id: 1, name: 'layout1.json', updated_at: '2024-01-01T12:00:00Z' },
 *     { id: 2, name: 'layout2.json', updated_at: '2024-01-02T15:30:00Z' }
 *   ]}
 *   selectedId={1}
 *   onSelect={(id) => console.log('Selected:', id)}
 * />
 *
 * // 빈 목록
 * <LayoutFileList files={[]} onSelect={(id) => {}} />
 */
export const LayoutFileList: React.FC<LayoutFileListProps> = ({
  files,
  selectedId,
  onSelect,
  className = '',
}) => {
  /**
   * ISO 날짜 문자열을 포맷팅
   */
  const formatDate = (dateString: string): string => {
    try {
      const date = new Date(dateString);

      // Invalid Date 체크
      if (isNaN(date.getTime())) {
        return dateString;
      }

      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      const hours = String(date.getHours()).padStart(2, '0');
      const minutes = String(date.getMinutes()).padStart(2, '0');

      return `${year}-${month}-${day} ${hours}:${minutes}`;
    } catch {
      return dateString;
    }
  };

  /**
   * 파일 항목 클릭 핸들러
   */
  const handleFileClick = (id: string | number) => {
    onSelect(id);
  };

  // 컨테이너 클래스
  const containerClasses = `
    w-full
    ${className}
  `.trim().replace(/\s+/g, ' ');

  return (
    <Div className={containerClasses}>
      {files.length === 0 ? (
        <Div className="p-4 text-center text-gray-500 dark:text-gray-400">
          파일이 없습니다.
        </Div>
      ) : (
        <Ul
          className="divide-y divide-gray-200 dark:divide-gray-700"
          role="listbox"
          aria-label="레이아웃 파일 목록"
        >
          {files.map((file) => {
            const isSelected = file.id === selectedId;

            // 항목 클래스 조합
            const itemClasses = `
              flex items-center justify-between p-4 cursor-pointer
              transition-colors duration-150
              ${
                isSelected
                  ? 'bg-blue-100 dark:bg-blue-900 border-l-4 border-blue-500'
                  : 'hover:bg-gray-100 dark:hover:bg-gray-800'
              }
            `.trim().replace(/\s+/g, ' ');

            // 파일명 클래스
            const nameClasses = `
              font-medium
              ${
                isSelected
                  ? 'text-blue-900 dark:text-blue-100'
                  : 'text-gray-900 dark:text-gray-100'
              }
            `.trim().replace(/\s+/g, ' ');

            // 날짜 클래스
            const dateClasses = `
              text-sm
              ${
                isSelected
                  ? 'text-blue-700 dark:text-blue-300'
                  : 'text-gray-500 dark:text-gray-400'
              }
            `.trim().replace(/\s+/g, ' ');

            return (
              <Li
                key={file.id}
                className={itemClasses}
                onClick={() => handleFileClick(file.id)}
                role="option"
                aria-selected={isSelected}
                tabIndex={0}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    handleFileClick(file.id);
                  }
                }}
              >
                <Div className="flex flex-col gap-1">
                  <Span className={nameClasses}>{file.name}</Span>
                  <Span className={dateClasses}>
                    {formatDate(file.updated_at)}
                  </Span>
                </Div>
              </Li>
            );
          })}
        </Ul>
      )}
    </Div>
  );
};
