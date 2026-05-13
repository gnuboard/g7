/**
 * TranslationContext.tsx
 *
 * 컴포넌트에서 다국어 번역 기능에 접근할 수 있도록 React Context를 제공합니다.
 * useTranslation 훅을 통해 컴포넌트 내부에서 직접 번역 키를 사용할 수 있습니다.
 */

import React, { createContext, useContext, useMemo } from 'react';
import { TranslationEngine, TranslationContext as ITranslationContext } from './TranslationEngine';
import { createLogger } from '../utils/Logger';

const logger = createLogger('TranslationContext');

/**
 * Translation Context 값 인터페이스
 */
interface TranslationContextValue {
  /** TranslationEngine 인스턴스 */
  translationEngine: TranslationEngine | null;
  /** 번역 컨텍스트 (templateId, locale) */
  translationContext: ITranslationContext | null;
  /**
   * 번역 함수
   * @param key 번역 키 (예: 'common.confirm')
   * @param params 번역 파라미터 (예: { count: 5 })
   * @returns 번역된 문자열
   */
  t: (key: string, params?: Record<string, string | number>) => string;
}

/**
 * React Context 생성
 */
export const TranslationReactContext = createContext<TranslationContextValue | null>(null);

/**
 * TranslationProvider Props
 */
interface TranslationProviderProps {
  children: React.ReactNode;
  translationEngine: TranslationEngine;
  translationContext: ITranslationContext;
}

/**
 * TranslationProvider 컴포넌트
 *
 * 템플릿 엔진의 최상위에서 TranslationEngine과 TranslationContext를 제공합니다.
 * 하위 컴포넌트에서 useTranslation 훅을 통해 번역 기능에 접근할 수 있습니다.
 */
export const TranslationProvider: React.FC<TranslationProviderProps> = ({
  children,
  translationEngine,
  translationContext,
}) => {
  /**
   * 번역 함수 메모이제이션
   */
  const t = useMemo(() => {
    return (key: string, params?: Record<string, string | number>): string => {
      if (params) {
        // 파라미터를 TranslationEngine 형식으로 변환 (|key=value|key2=value2)
        const paramsStr = '|' + Object.entries(params).map(([k, v]) => `${k}=${v}`).join('|');
        return translationEngine.translate(key, translationContext, paramsStr);
      }
      return translationEngine.translate(key, translationContext);
    };
  }, [translationEngine, translationContext]);

  const value = useMemo<TranslationContextValue>(() => ({
    translationEngine,
    translationContext,
    t,
  }), [translationEngine, translationContext, t]);

  return (
    <TranslationReactContext.Provider value={value}>
      {children}
    </TranslationReactContext.Provider>
  );
};

/**
 * useTranslation 훅
 *
 * 컴포넌트에서 다국어 번역 기능에 접근합니다.
 *
 * @example
 * ```tsx
 * const { t } = useTranslation();
 * const confirmText = t('common.confirm');
 * const message = t('admin.users.pagination_info', { from: 1, to: 10, total: 100 });
 * ```
 *
 * @returns TranslationContextValue (t 함수 포함)
 */
export const useTranslation = (): TranslationContextValue => {
  const context = useContext(TranslationReactContext);

  if (!context) {
    // Context 외부에서 사용 시 폴백 (키 자체 반환)
    // 이 경우는 테스트 환경이나 Context 없이 컴포넌트를 단독 사용할 때 발생
    logger.warn('TranslationProvider 외부에서 호출되었습니다. 키를 그대로 반환합니다.');
    return {
      t: (key: string) => key,
      translationEngine: null,
      translationContext: null,
    };
  }

  return context;
};

export default TranslationProvider;