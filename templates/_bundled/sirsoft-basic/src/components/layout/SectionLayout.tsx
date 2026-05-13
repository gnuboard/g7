import React from 'react';
import { Section as BaseSection } from '../basic/Section';
import { Div } from '../basic/Div';
import { H2 } from '../basic/H2';
import { P } from '../basic/P';

export interface SectionLayoutProps {
  /**
   * 섹션 제목
   */
  title?: string;

  /**
   * 섹션 부제목
   */
  subtitle?: string;

  /**
   * 패딩 적용
   */
  padding?: 'none' | 'sm' | 'md' | 'lg' | 'xl';

  /**
   * 배경색
   */
  background?: 'none' | 'white' | 'gray' | 'primary' | 'secondary';

  /**
   * 최대 너비 제한
   */
  maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '4xl' | '6xl' | '7xl' | 'full';

  /**
   * 중앙 정렬 여부
   */
  centered?: boolean;

  /**
   * 경계선 표시
   */
  border?: boolean;

  /**
   * 그림자 효과
   */
  shadow?: 'none' | 'sm' | 'md' | 'lg' | 'xl';

  /**
   * 둥근 모서리
   */
  rounded?: boolean;

  /**
   * 사용자 정의 클래스
   */
  className?: string;

  /**
   * 인라인 스타일
   */
  style?: React.CSSProperties;

  /**
   * 자식 요소
   */
  children?: React.ReactNode;

  /**
   * 클릭 이벤트 핸들러
   */
  onClick?: () => void;
}

/**
 * Section 레이아웃 컴포넌트
 *
 * 시맨틱 구역 분할을 위한 섹션 컴포넌트입니다.
 * 제목, 패딩, 배경색 등 다양한 옵션을 제공합니다.
 *
 * @example
 * // 기본 섹션
 * <Section padding="md" background="white">
 *   <p>Content here</p>
 * </Section>
 *
 * @example
 * // 제목이 있는 섹션
 * <Section
 *   title="사용자 정보"
 *   subtitle="개인 정보를 관리합니다"
 *   padding="lg"
 *   border
 * >
 *   <p>User details</p>
 * </Section>
 */
export const SectionLayout: React.FC<SectionLayoutProps> = ({
  title,
  subtitle,
  children,
  padding = 'md',
  background = 'none',
  maxWidth,
  centered = false,
  border = false,
  shadow = 'none',
  rounded = false,
  className = '',
  style,
  onClick,
}) => {
  // Tailwind CSS 클래스 생성
  const classes: string[] = [];

  // 패딩
  const paddingMap: Record<string, string> = {
    none: 'p-0',
    sm: 'p-2',
    md: 'p-4',
    lg: 'p-6',
    xl: 'p-8',
  }; 
  classes.push(paddingMap[padding]);

  // 배경색
  const backgroundMap: Record<string, string> = {
    none: '',
    white: 'bg-white dark:bg-gray-700',
    gray: 'bg-gray-50 dark:bg-gray-800',
    primary: 'bg-blue-50 dark:bg-blue-900/20',
    secondary: 'bg-gray-50 dark:bg-gray-800',
  };
  if (background !== 'none') {
    classes.push(backgroundMap[background]);
  }

  // 최대 너비
  if (maxWidth) {
    const maxWidthMap: Record<string, string> = {
      sm: 'max-w-sm',
      md: 'max-w-md',
      lg: 'max-w-lg',
      xl: 'max-w-xl',
      '2xl': 'max-w-2xl',
      '4xl': 'max-w-4xl',
      '6xl': 'max-w-6xl',
      '7xl': 'max-w-7xl',
      full: 'max-w-full',
    };
    classes.push(maxWidthMap[maxWidth]);
  }

  // 중앙 정렬
  if (centered) {
    classes.push('mx-auto');
  }

  // 경계선
  if (border) {
    classes.push('border', 'border-gray-200', 'dark:border-gray-700');
  }

  // 그림자
  const shadowMap: Record<string, string> = {
    none: '',
    sm: 'shadow-sm',
    md: 'shadow-md',
    lg: 'shadow-lg',
    xl: 'shadow-xl',
  };
  if (shadow !== 'none') {
    classes.push(shadowMap[shadow]);
  }

  // 둥근 모서리
  if (rounded) {
    classes.push('rounded-lg');
  }

  // 사용자 정의 클래스 추가
  if (className) {
    classes.push(className);
  }

  return (
    <BaseSection className={classes.join(' ')} style={style} onClick={onClick}>
      {/* 제목 영역 */}
      {(title || subtitle) && (
        <Div className="mb-4">
          {title && <H2 className="text-2xl font-bold text-gray-900 dark:text-white">{title}</H2>}
          {subtitle && <P className="mt-1 text-sm text-gray-600 dark:text-gray-400">{subtitle}</P>}
        </Div>
      )}

      {/* 콘텐츠 영역 */}
      {children}
    </BaseSection>
  );
};
