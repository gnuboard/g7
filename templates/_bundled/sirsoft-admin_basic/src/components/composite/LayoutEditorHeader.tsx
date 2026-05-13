import React from 'react';
import { Div } from '../basic/Div';
import { H2 } from '../basic/H2';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

export interface LayoutEditorHeaderProps {
  /**
   * 레이아웃 이름
   */
  layoutName: string;

  /**
   * 뒤로가기 버튼 클릭 시 콜백
   */
  onBack: () => void;

  /**
   * 미리보기 버튼 클릭 시 콜백
   */
  onPreview: () => void;

  /**
   * 저장 버튼 클릭 시 콜백
   */
  onSave: () => void;

  /**
   * 저장 중 상태 여부
   */
  isSaving?: boolean;

  /**
   * 사용자 정의 클래스
   */
  className?: string;
}

/**
 * LayoutEditorHeader 레이아웃 편집 헤더 컴포넌트
 *
 * 레이아웃 편집 화면의 헤더 영역을 구성하는 composite 컴포넌트입니다.
 * 뒤로가기, 제목, 미리보기, 저장 버튼을 포함합니다.
 *
 * @example
 * // 기본 사용
 * <LayoutEditorHeader
 *   layoutName="메인 레이아웃"
 *   onBack={() => console.log('back')}
 *   onPreview={() => console.log('preview')}
 *   onSave={() => console.log('save')}
 * />
 *
 * // 저장 중 상태
 * <LayoutEditorHeader
 *   layoutName="메인 레이아웃"
 *   onBack={() => console.log('back')}
 *   onPreview={() => console.log('preview')}
 *   onSave={() => console.log('save')}
 *   isSaving={true}
 * />
 */
export const LayoutEditorHeader: React.FC<LayoutEditorHeaderProps> = ({
  layoutName,
  onBack,
  onPreview,
  onSave,
  isSaving = false,
  className = '',
}) => {
  // 컨테이너 클래스 조합
  const containerClasses = `
    flex items-center justify-between gap-4 p-4 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700
    ${className}
  `.trim().replace(/\s+/g, ' ');

  return (
    <Div className={containerClasses}>
      {/* 좌측 영역: 뒤로가기 버튼 + 제목 */}
      <Div className="flex items-center gap-3">
        {/* 뒤로가기 버튼 */}
        <Button
          onClick={onBack}
          className="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors bg-transparent border-0 cursor-pointer"
          aria-label="뒤로가기"
        >
          <Icon
            name={IconName.ArrowLeft}
            className="text-gray-700 dark:text-gray-300"
            ariaLabel="뒤로가기"
          />
        </Button>

        {/* 제목 */}
        <H2 className="text-xl font-semibold text-gray-900 dark:text-gray-100">
          {layoutName}
        </H2>
      </Div>

      {/* 우측 영역: 미리보기 버튼 + 저장 버튼 */}
      <Div className="flex items-center gap-2">
        {/* 미리보기 버튼 */}
        <Button
          onClick={onPreview}
          className="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-lg transition-colors border-0 cursor-pointer"
          aria-label="미리보기"
        >
          <Div className="flex items-center gap-2">
            <Icon
              name={IconName.Eye}
              className="text-gray-700 dark:text-gray-300"
              ariaLabel="미리보기 아이콘"
            />
            <span>미리보기</span>
          </Div>
        </Button>

        {/* 저장 버튼 */}
        <Button
          onClick={onSave}
          disabled={isSaving}
          className={`
            px-4 py-2 rounded-lg transition-colors border-0 cursor-pointer
            ${isSaving
              ? 'bg-blue-400 dark:bg-blue-600 cursor-not-allowed'
              : 'bg-blue-600 dark:bg-blue-500 hover:bg-blue-700 dark:hover:bg-blue-600'
            }
            text-white
          `.trim().replace(/\s+/g, ' ')}
          aria-label={isSaving ? '저장 중...' : '저장'}
        >
          <Div className="flex items-center gap-2">
            <Icon
              name={isSaving ? IconName.Spinner : IconName.Save}
              className={`text-white ${isSaving ? 'animate-spin' : ''}`}
              ariaLabel={isSaving ? '저장 중 아이콘' : '저장 아이콘'}
            />
            <span>{isSaving ? '저장 중...' : '저장'}</span>
          </Div>
        </Button>
      </Div>
    </Div>
  );
};
