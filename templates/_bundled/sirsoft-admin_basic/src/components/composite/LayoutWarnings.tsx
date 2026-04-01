import React, { useState, useEffect, useCallback } from 'react';
import { Div } from '../basic/Div';
import { Alert, AlertType } from './Alert';

/**
 * 레이아웃 경고 타입
 *
 * 호환성 경고, 시스템 경고 등 다양한 경고 유형을 지원합니다.
 */
export type LayoutWarningType = 'compatibility' | 'deprecation' | 'license' | 'security' | 'system';

/**
 * 레이아웃 경고 레벨
 */
export type LayoutWarningLevel = 'info' | 'warning' | 'error';

/**
 * 레이아웃 경고 인터페이스
 *
 * 백엔드에서 프론트엔드로 전달되는 경고 정보입니다.
 */
export interface LayoutWarning {
  /** 경고 고유 ID (dismiss 처리에 사용) */
  id: string;
  /** 경고 유형 */
  type: LayoutWarningType;
  /** 경고 레벨 */
  level: LayoutWarningLevel;
  /** 사용자에게 표시할 메시지 */
  message: string;
  /** 추가 메타데이터 (경고 유형에 따라 다름) */
  [key: string]: any;
}

/**
 * 세션 스토리지 키
 */
const DISMISSED_WARNINGS_KEY = 'g7_dismissed_warnings';

/**
 * LayoutWarnings 컴포넌트 Props
 */
export interface LayoutWarningsProps {
  /**
   * 표시할 경고 목록
   */
  warnings?: LayoutWarning[];

  /**
   * 사용자 정의 클래스
   */
  className?: string;
}

/**
 * 세션 스토리지에서 dismiss된 경고 ID 목록을 가져옵니다.
 */
function getDismissedWarnings(): string[] {
  try {
    const stored = sessionStorage.getItem(DISMISSED_WARNINGS_KEY);
    return stored ? JSON.parse(stored) : [];
  } catch {
    return [];
  }
}

/**
 * 세션 스토리지에 dismiss된 경고 ID를 저장합니다.
 */
function saveDismissedWarning(warningId: string): void {
  try {
    const dismissed = getDismissedWarnings();
    if (!dismissed.includes(warningId)) {
      dismissed.push(warningId);
      sessionStorage.setItem(DISMISSED_WARNINGS_KEY, JSON.stringify(dismissed));
    }
  } catch {
    // 세션 스토리지 사용 불가 시 무시
  }
}

/**
 * LayoutWarning의 level을 Alert의 type으로 변환합니다.
 */
function mapWarningLevelToAlertType(level: LayoutWarning['level']): AlertType {
  switch (level) {
    case 'error':
      return 'error';
    case 'warning':
      return 'warning';
    case 'info':
    default:
      return 'info';
  }
}

/**
 * LayoutWarnings 컴포넌트
 *
 * 레이아웃의 warnings 배열을 받아서 Alert 컴포넌트들을 렌더링합니다.
 * dismiss된 경고는 세션 스토리지에 저장되어 세션 동안 다시 표시되지 않습니다.
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "LayoutWarnings",
 *   "props": {
 *     "warnings": "{{_global.layoutWarnings}}"
 *   }
 * }
 */
export const LayoutWarnings: React.FC<LayoutWarningsProps> = ({
  warnings,
  className = '',
}) => {
  // dismiss된 경고 ID 목록 (세션 스토리지 기반)
  const [dismissedIds, setDismissedIds] = useState<string[]>([]);

  // 컴포넌트 마운트 시 세션 스토리지에서 dismiss된 ID 로드
  useEffect(() => {
    setDismissedIds(getDismissedWarnings());
  }, []);

  // 경고 dismiss 핸들러
  const handleDismiss = useCallback((warningId: string) => {
    saveDismissedWarning(warningId);
    setDismissedIds(prev => [...prev, warningId]);
  }, []);

  // warnings가 없거나 빈 배열이면 렌더링하지 않음
  if (!warnings || warnings.length === 0) {
    return null;
  }

  // dismiss되지 않은 경고만 필터링
  const visibleWarnings = warnings.filter(
    warning => !dismissedIds.includes(warning.id)
  );

  // 표시할 경고가 없으면 렌더링하지 않음
  if (visibleWarnings.length === 0) {
    return null;
  }

  return (
    <Div className={`space-y-2 ${className}`.trim()}>
      {visibleWarnings.map(warning => (
        <Alert
          key={warning.id}
          type={mapWarningLevelToAlertType(warning.level)}
          message={warning.message}
          dismissible
          onDismiss={() => handleDismiss(warning.id)}
        />
      ))}
    </Div>
  );
};
