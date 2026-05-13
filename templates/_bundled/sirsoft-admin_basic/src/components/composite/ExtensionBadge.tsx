import React, { useMemo } from 'react';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

export type ExtensionType = 'module' | 'plugin';

/**
 * 설치된 모듈/플러그인 정보 인터페이스
 */
export interface ExtensionInfo {
  identifier: string;
  name: string;
  description?: string;
  version?: string;
  status?: string;
}

export interface ExtensionBadgeProps {
  /** 확장 타입 (module: 모듈, plugin: 플러그인) */
  type: ExtensionType;
  /** 확장 식별자 (예: sirsoft-ecommerce) - 이 값으로 이름을 자동 조회 */
  identifier?: string;
  /** 확장 이름 (직접 지정 시 사용, identifier보다 우선) */
  name?: string;
  /** 설치된 모듈 목록 (_global.installedModules에서 전달) */
  installedModules?: ExtensionInfo[];
  /** 설치된 플러그인 목록 (_global.installedPlugins에서 전달) */
  installedPlugins?: ExtensionInfo[];
  /** 추가 클래스명 */
  className?: string;
  /** 인라인 스타일 */
  style?: React.CSSProperties;
}

/**
 * ExtensionBadge 집합 컴포넌트
 *
 * 모듈 또는 플러그인에 의해 추가된 섹션임을 표시하는 배지 컴포넌트입니다.
 * 카드 또는 섹션의 우측 상단에 배치하여 확장 출처를 표시합니다.
 *
 * identifier를 전달하면 installedModules/installedPlugins에서 이름을 자동으로 조회합니다.
 *
 * 기본 컴포넌트 조합: Span + Icon
 *
 * @example
 * // 레이아웃 JSON 사용 예시 (identifier로 이름 자동 조회)
 * {
 *   "name": "ExtensionBadge",
 *   "props": {
 *     "type": "module",
 *     "identifier": "sirsoft-ecommerce",
 *     "installedModules": "{{_global.installedModules}}"
 *   }
 * }
 *
 * @example
 * // 이름 직접 지정
 * {
 *   "name": "ExtensionBadge",
 *   "props": {
 *     "type": "plugin",
 *     "name": "결제"
 *   }
 * }
 */
export const ExtensionBadge: React.FC<ExtensionBadgeProps> = ({
  type,
  identifier,
  name,
  installedModules = [],
  installedPlugins = [],
  className = '',
  style,
}) => {
  // 타입별 아이콘 매핑
  const typeIcons: Record<ExtensionType, IconName> = {
    module: IconName.Cubes,
    plugin: IconName.PuzzlePiece,
  };

  // 타입별 기본 라벨 매핑 (다국어)
  const typeLabels: Record<ExtensionType, string> = {
    module: t('common.module'),
    plugin: t('common.plugin'),
  };

  // identifier로 이름 조회
  const resolvedName = useMemo(() => {
    // name이 직접 지정되면 우선 사용
    if (name) {
      return name;
    }

    // identifier가 있으면 목록에서 찾기
    if (identifier) {
      const extensionList = type === 'module' ? installedModules : installedPlugins;
      const found = extensionList?.find((ext) => ext.identifier === identifier);
      if (found) {
        return found.name;
      }
    }

    return undefined;
  }, [name, identifier, type, installedModules, installedPlugins]);

  const displayIcon = typeIcons[type];
  const displayLabel = resolvedName
    ? `${resolvedName} ${typeLabels[type]}`
    : typeLabels[type];

  return (
    <Span
      className={`inline-flex items-center gap-1.5 px-2 py-1 rounded text-xs font-medium text-gray-400 dark:text-gray-500 ${className}`}
      style={style}
    >
      <Icon name={displayIcon} className="w-3.5 h-3.5" />
      <Span>{displayLabel}</Span>
    </Span>
  );
};
