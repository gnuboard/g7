/**
 * ModalDataSourceWrapper.tsx
 *
 * 모달에 data_sources 지원을 추가하는 래퍼 컴포넌트
 *
 * 기존 DataSourceManager를 재사용하여 모달이 열릴 때 data_sources를 fetch하고
 * 결과를 전역 상태(_global)에 저장합니다.
 *
 * @package G7
 * @subpackage Template Engine
 * @since 1.0.0
 */

import React, { useEffect, useRef, useState, useCallback } from 'react';
import { DataSourceManager, DataSource } from './DataSourceManager';
import { DataBindingEngine } from './DataBindingEngine';
import { createLogger } from '../utils/Logger';

const logger = createLogger('ModalDataSourceWrapper');

/**
 * 중첩된 객체에서 경로로 값을 추출하는 헬퍼 함수
 *
 * @param obj 대상 객체
 * @param path 점(.) 구분 경로 (예: "data.items", "[0].name", "data[0].value")
 * @returns 경로에 해당하는 값
 */
function getNestedValue(obj: any, path: string): any {
  if (!obj || !path) return obj;

  // ?. 를 . 로 정규화
  const normalizedPath = path.replace(/\?\./g, '.');

  // 경로를 파싱하여 각 부분 추출 (배열 인덱스 포함)
  // "[0].name" → ["[0]", "name"]
  // "data[0].name" → ["data", "[0]", "name"]
  const parts = normalizedPath.split(/\.(?![^\[]*\])/).flatMap(part => {
    // 배열 인덱스 분리: "data[0]" → ["data", "[0]"]
    const matches = part.match(/^([^\[]*)((?:\[\d+\])*)$/);
    if (matches) {
      const [, base, indices] = matches;
      const result: string[] = [];
      if (base) result.push(base);
      const indexMatches = indices.match(/\[\d+\]/g);
      if (indexMatches) result.push(...indexMatches);
      return result;
    }
    return [part];
  }).filter(p => p !== '');

  let current = obj;
  for (const part of parts) {
    if (current === undefined || current === null) {
      return undefined;
    }

    // 배열 인덱스 처리: "[0]" → 0
    if (part.startsWith('[') && part.endsWith(']')) {
      const index = parseInt(part.slice(1, -1), 10);
      current = current[index];
    } else {
      current = current[part];
    }
  }

  return current;
}

/**
 * ModalDataSourceWrapper Props
 */
interface ModalDataSourceWrapperProps {
  /** 모달이 열린 상태인지 */
  isOpen: boolean;
  /** 모달 ID */
  modalId: string;
  /** 모달에 정의된 data_sources */
  dataSources?: DataSource[];
  /** 현재 데이터 컨텍스트 */
  dataContext: Record<string, any>;
  /** 전역 상태 업데이터 (ActionDispatcher의 globalStateUpdater와 동일한 시그니처) */
  globalStateUpdater?: (updates: Record<string, any>) => void;
  /** DataBindingEngine 인스턴스 (표현식 치환용) */
  bindingEngine?: DataBindingEngine;
  /** 디버그 모드 */
  debug?: boolean;
  /** 자식 요소 (모달 컴포넌트) */
  children: React.ReactNode;
}

/**
 * 모달 data_sources 래퍼 컴포넌트
 *
 * 모달이 열릴 때 정의된 data_sources를 fetch하고
 * 결과를 전역 상태에 저장합니다.
 *
 * 사용 예:
 * ```json
 * {
 *   "id": "module_detail_modal",
 *   "type": "composite",
 *   "name": "Modal",
 *   "data_sources": [
 *     {
 *       "id": "moduleDetail",
 *       "type": "api",
 *       "endpoint": "/api/admin/modules/{{_global.selectedModuleIdentifier}}",
 *       "auth_required": true,
 *       "initGlobal": "selectedModule"
 *     }
 *   ],
 *   ...
 * }
 * ```
 */
export const ModalDataSourceWrapper: React.FC<ModalDataSourceWrapperProps> = ({
  isOpen,
  modalId,
  dataSources,
  dataContext,
  globalStateUpdater,
  bindingEngine,
  debug = false,
  children,
}) => {
  const [isLoading, setIsLoading] = useState(false);
  const [hasFetched, setHasFetched] = useState(false);
  const prevIsOpenRef = useRef(false);
  const dataSourceManagerRef = useRef<DataSourceManager | null>(null);

  // DataSourceManager 싱글턴 생성
  if (!dataSourceManagerRef.current) {
    dataSourceManagerRef.current = new DataSourceManager();
  }

  /**
   * 모달의 data_sources를 fetch합니다.
   */
  const fetchModalDataSources = useCallback(async () => {
    if (!dataSources || dataSources.length === 0) return;
    if (!dataSourceManagerRef.current) return;

    setIsLoading(true);

    try {
      // 현재 URL에서 라우트/쿼리 파라미터 추출
      const routeParams = dataContext.route || {};
      const queryParams = new URLSearchParams(window.location.search);

      // data_sources의 endpoint에서 표현식 치환
      // 예: /api/admin/modules/{{_global.selectedModuleIdentifier}} -> /api/admin/modules/sirsoft-ecommerce
      const resolvedDataSources = dataSources.map((ds) => {
        let resolvedDs = { ...ds };

        if (ds.endpoint && bindingEngine) {
          // endpoint 내 표현식 치환 (캐시 사용 안 함 - 모달이 열릴 때마다 새로운 값 필요)
          const resolvedEndpoint = bindingEngine.resolveBindings(
            ds.endpoint,
            dataContext,
            { skipCache: true }
          );
          resolvedDs.endpoint = resolvedEndpoint;
        }

        // params의 표현식도 치환 (캐시 사용 안 함)
        if (ds.params && bindingEngine) {
          const resolvedParams: Record<string, any> = {};
          Object.entries(ds.params).forEach(([key, value]) => {
            if (typeof value === 'string') {
              resolvedParams[key] = bindingEngine.resolveBindings(value, dataContext, { skipCache: true });
            } else {
              resolvedParams[key] = value;
            }
          });
          resolvedDs.params = resolvedParams;
        }

        return resolvedDs;
      });

      if (debug) {
        logger.log(`[ModalDataSourceWrapper] Modal "${modalId}" fetching data sources:`,
          resolvedDataSources.map(ds => ({ id: ds.id, endpoint: ds.endpoint })));
      }

      // DataSourceManager를 사용하여 fetch (기존 로직 재사용)
      const results = await dataSourceManagerRef.current.fetchDataSourcesWithResults(
        resolvedDataSources,
        routeParams,
        queryParams
      );

      // 결과를 전역 상태에 저장
      if (globalStateUpdater) {
        results.forEach((result) => {
          if (result.state === 'success' && result.data !== undefined) {
            // 해당 data_source 정의 찾기
            const sourceDef = resolvedDataSources.find(ds => ds.id === result.id);

            if (sourceDef?.initGlobal) {
              // API 응답에서 실제 데이터 추출 (data.data 또는 data 자체)
              const actualData = result.data?.data ?? result.data;

              // initGlobal 옵션에 따라 _global에 데이터 저장
              // ActionDispatcher의 globalStateUpdater는 { key: value } 형태를 받음
              // 배열 형태: 여러 전역 상태 동시 초기화
              // 문자열 형태: 전체 데이터 저장
              // 객체 형태: { key, path }로 특정 필드만 저장
              const initGlobalItems = Array.isArray(sourceDef.initGlobal)
                ? sourceDef.initGlobal
                : [sourceDef.initGlobal];

              for (const item of initGlobalItems) {
                if (typeof item === 'string') {
                  globalStateUpdater({
                    [item]: actualData,
                  });

                  if (debug) {
                    logger.log(`[ModalDataSourceWrapper] Modal "${modalId}" initGlobal: ${result.id}.data -> _global.${item}`);
                  }
                } else if (typeof item === 'object' && item.key) {
                  const { key, path } = item;
                  // path가 지정되면 해당 경로의 데이터만 추출
                  const targetData = path ? getNestedValue(actualData, path) : actualData;
                  globalStateUpdater({
                    [key]: targetData,
                  });

                  if (debug) {
                    logger.log(`Modal "${modalId}" initGlobal: ${result.id}.data${path ? '.' + path : ''} -> _global.${key}`);
                  }
                }
              }
            }
          } else if (result.state === 'error') {
            logger.error(`Modal "${modalId}" data source error:`, result.id, result.error);
          }
        });
      }

      if (debug) {
        logger.log(`Modal "${modalId}" data sources fetched successfully`);
      }
    } catch (error) {
      logger.error(`Modal "${modalId}" data source fetch error:`, error);
    } finally {
      setIsLoading(false);
      setHasFetched(true);
    }
  }, [dataSources, dataContext, globalStateUpdater, bindingEngine, modalId, debug]);

  // 모달 데이터 소스를 TemplateApp 레지스트리에 등록/해제
  // refetchDataSource 핸들러에서 모달 데이터 소스를 찾을 수 있도록 함
  useEffect(() => {
    if (!dataSources || dataSources.length === 0) return;

    const templateApp = (window as any).__templateApp;
    if (templateApp?.registerModalDataSources) {
      templateApp.registerModalDataSources(modalId, dataSources);
    }

    return () => {
      if (templateApp?.unregisterModalDataSources) {
        templateApp.unregisterModalDataSources(modalId);
      }
    };
  }, [modalId, dataSources]);

  useEffect(() => {
    // 모달이 닫힐 때 hasFetched 리셋 (다음 열림 시 다시 fetch)
    if (!isOpen && prevIsOpenRef.current) {
      setHasFetched(false);
      if (debug) {
        logger.log(`[ModalDataSourceWrapper] Modal "${modalId}" closed, reset fetch state`);
      }
    }

    // 모달이 열릴 때 data_sources fetch
    if (isOpen && !prevIsOpenRef.current && dataSources && dataSources.length > 0 && !hasFetched) {
      fetchModalDataSources();
    }

    prevIsOpenRef.current = isOpen;
  }, [isOpen, dataSources, hasFetched, fetchModalDataSources, modalId, debug]);

  // children에 로딩 상태 전달 (필요시 사용)
  // 현재는 단순히 children을 렌더링
  return <>{children}</>;
};

export default ModalDataSourceWrapper;
