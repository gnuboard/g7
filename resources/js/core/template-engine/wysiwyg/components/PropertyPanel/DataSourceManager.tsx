/**
 * DataSourceManager.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 데이터 소스 관리자
 *
 * 역할:
 * - 레이아웃의 data_sources 배열 관리
 * - API, static, route_params, query_params, websocket 타입 지원
 * - 로딩 전략 설정 (blocking, progressive, background)
 * - 파라미터 바인딩 설정
 *
 * Phase 3: 고급 기능
 */

import React, { useState, useCallback, useMemo } from 'react';

// ============================================================================
// 타입 정의
// ============================================================================

export interface DataSource {
  /** 데이터 소스 고유 ID */
  id: string;
  /** 데이터 소스 타입 */
  type: 'api' | 'static' | 'route_params' | 'query_params' | 'websocket';
  /** API 엔드포인트 (type이 api일 때) */
  endpoint?: string;
  /** HTTP 메서드 */
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  /** 자동 fetch 여부 */
  auto_fetch?: boolean;
  /** 인증 필요 여부 */
  auth_required?: boolean;
  /** 로딩 전략 */
  loading_strategy?: 'blocking' | 'progressive' | 'background';
  /** 요청 파라미터 */
  params?: Record<string, string>;
  /** 정적 데이터 (type이 static일 때) */
  data?: any;
  /** 조건부 로딩 표현식 */
  if?: string;
  /** 페이지 진입 시 항상 다시 fetch */
  refetchOnMount?: boolean;
  /** API 응답을 _local에 복사 */
  initLocal?: string | object;
  /** API 응답을 _global에 복사 */
  initGlobal?: string | object | any[];
}

export interface DataSourceManagerProps {
  /** 현재 data_sources 배열 */
  dataSources: DataSource[];
  /** 변경 시 콜백 */
  onChange: (dataSources: DataSource[]) => void;
}

/** 데이터 소스 타입별 설명 */
const DATA_SOURCE_TYPES = [
  { value: 'api', label: 'API', description: 'REST API 호출' },
  { value: 'static', label: '정적 데이터', description: '하드코딩된 데이터' },
  { value: 'route_params', label: 'Route Params', description: 'URL 경로 파라미터' },
  { value: 'query_params', label: 'Query Params', description: 'URL 쿼리 파라미터' },
  { value: 'websocket', label: 'WebSocket', description: '실시간 데이터' },
] as const;

/** 로딩 전략별 설명 */
const LOADING_STRATEGIES = [
  { value: 'blocking', label: '블로킹', description: '데이터 로드 완료 후 렌더링' },
  { value: 'progressive', label: '프로그레시브', description: '즉시 렌더링 + 스켈레톤 UI (기본)' },
  { value: 'background', label: '백그라운드', description: '즉시 렌더링 + 빈 데이터' },
] as const;

/** HTTP 메서드 목록 */
const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const;

// ============================================================================
// 데이터 소스 편집기
// ============================================================================

interface DataSourceEditorProps {
  dataSource: DataSource;
  index: number;
  isOpen: boolean;
  onToggle: () => void;
  onChange: (dataSource: DataSource) => void;
  onDelete: () => void;
  existingIds: string[];
}

function DataSourceEditor({
  dataSource,
  index,
  isOpen,
  onToggle,
  onChange,
  onDelete,
  existingIds,
}: DataSourceEditorProps) {
  const [idError, setIdError] = useState<string | null>(null);

  // ID 변경 시 유효성 검사
  const handleIdChange = useCallback(
    (newId: string) => {
      // ID 중복 검사
      if (existingIds.includes(newId) && newId !== dataSource.id) {
        setIdError('이미 사용 중인 ID입니다');
      } else if (!newId) {
        setIdError('ID는 필수입니다');
      } else if (!/^[a-z][a-z0-9_]*$/i.test(newId)) {
        setIdError('ID는 영문자로 시작하고 영문, 숫자, 언더스코어만 허용');
      } else {
        setIdError(null);
      }
      onChange({ ...dataSource, id: newId });
    },
    [dataSource, existingIds, onChange]
  );

  // 필드 변경 핸들러
  const handleFieldChange = useCallback(
    (field: keyof DataSource, value: any) => {
      onChange({ ...dataSource, [field]: value });
    },
    [dataSource, onChange]
  );

  // 파라미터 변경 핸들러
  const handleParamChange = useCallback(
    (key: string, value: string, oldKey?: string) => {
      const currentParams = { ...dataSource.params } || {};
      if (oldKey && oldKey !== key) {
        delete currentParams[oldKey];
      }
      if (value === '' && key === oldKey) {
        delete currentParams[key];
      } else {
        currentParams[key] = value;
      }
      onChange({ ...dataSource, params: currentParams });
    },
    [dataSource, onChange]
  );

  // 파라미터 추가
  const handleAddParam = useCallback(() => {
    const params = dataSource.params || {};
    const newKey = `param${Object.keys(params).length + 1}`;
    onChange({ ...dataSource, params: { ...params, [newKey]: '' } });
  }, [dataSource, onChange]);

  // 파라미터 삭제
  const handleDeleteParam = useCallback(
    (key: string) => {
      const params = { ...dataSource.params };
      delete params[key];
      onChange({ ...dataSource, params: Object.keys(params).length ? params : undefined });
    },
    [dataSource, onChange]
  );

  return (
    <div className="border border-gray-200 dark:border-gray-700 rounded overflow-hidden">
      {/* 헤더 */}
      <div
        className="flex items-center justify-between px-3 py-2 bg-gray-50 dark:bg-gray-800 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-750 transition-colors"
        onClick={onToggle}
      >
        <div className="flex items-center gap-2">
          <span className="text-xs font-mono bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 px-1.5 py-0.5 rounded">
            {dataSource.id || '(미정의)'}
          </span>
          <span className="text-xs text-gray-500 dark:text-gray-400">
            {DATA_SOURCE_TYPES.find((t) => t.value === dataSource.type)?.label || dataSource.type}
          </span>
          {dataSource.type === 'api' && dataSource.endpoint && (
            <span className="text-xs text-gray-400 dark:text-gray-500 truncate max-w-[200px]">
              {dataSource.endpoint}
            </span>
          )}
        </div>

        <div className="flex items-center gap-2">
          {dataSource.loading_strategy && (
            <span
              className={`px-1.5 py-0.5 text-xs rounded ${
                dataSource.loading_strategy === 'blocking'
                  ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400'
                  : dataSource.loading_strategy === 'background'
                  ? 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400'
                  : 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
              }`}
            >
              {dataSource.loading_strategy}
            </span>
          )}
          <button
            onClick={(e) => {
              e.stopPropagation();
              onDelete();
            }}
            className="text-xs text-red-500 hover:text-red-700"
          >
            삭제
          </button>
          <span className={`text-gray-400 transition-transform ${isOpen ? 'rotate-180' : ''}`}>
            ▼
          </span>
        </div>
      </div>

      {/* 편집 폼 */}
      {isOpen && (
        <div className="p-3 space-y-4 border-t border-gray-200 dark:border-gray-700">
          {/* ID */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              ID <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              value={dataSource.id}
              onChange={(e) => handleIdChange(e.target.value)}
              placeholder="users"
              className={`w-full px-3 py-2 text-sm border rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white ${
                idError
                  ? 'border-red-500 dark:border-red-400'
                  : 'border-gray-300 dark:border-gray-600'
              }`}
            />
            {idError && <p className="text-xs text-red-500 mt-1">{idError}</p>}
            <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
              컴포넌트에서 {`{{${dataSource.id || 'id'}.data}}`}로 참조
            </p>
          </div>

          {/* Type */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              타입 <span className="text-red-500">*</span>
            </label>
            <select
              value={dataSource.type}
              onChange={(e) => handleFieldChange('type', e.target.value)}
              className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
            >
              {DATA_SOURCE_TYPES.map((type) => (
                <option key={type.value} value={type.value}>
                  {type.label} - {type.description}
                </option>
              ))}
            </select>
          </div>

          {/* API 타입 전용 필드들 */}
          {dataSource.type === 'api' && (
            <>
              {/* Endpoint */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  엔드포인트 <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  value={dataSource.endpoint || ''}
                  onChange={(e) => handleFieldChange('endpoint', e.target.value)}
                  placeholder="/api/admin/users"
                  className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                />
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                  /api로 시작 권장, 라우트 파라미터: {`{{route.id}}`}
                </p>
              </div>

              {/* Method */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  HTTP 메서드
                </label>
                <select
                  value={dataSource.method || 'GET'}
                  onChange={(e) => handleFieldChange('method', e.target.value)}
                  className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                >
                  {HTTP_METHODS.map((method) => (
                    <option key={method} value={method}>
                      {method}
                    </option>
                  ))}
                </select>
              </div>

              {/* Loading Strategy */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  로딩 전략
                </label>
                <select
                  value={dataSource.loading_strategy || 'progressive'}
                  onChange={(e) => handleFieldChange('loading_strategy', e.target.value)}
                  className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                >
                  {LOADING_STRATEGIES.map((strategy) => (
                    <option key={strategy.value} value={strategy.value}>
                      {strategy.label} - {strategy.description}
                    </option>
                  ))}
                </select>
              </div>

              {/* Params */}
              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="text-xs font-medium text-gray-700 dark:text-gray-300">
                    파라미터
                  </label>
                  <button
                    onClick={handleAddParam}
                    className="text-xs text-blue-600 dark:text-blue-400 hover:underline"
                  >
                    + 추가
                  </button>
                </div>
                {dataSource.params && Object.entries(dataSource.params).length > 0 ? (
                  <div className="space-y-2">
                    {Object.entries(dataSource.params).map(([key, value]) => (
                      <div key={key} className="flex items-center gap-2">
                        <input
                          type="text"
                          value={key}
                          onChange={(e) => handleParamChange(e.target.value, value, key)}
                          placeholder="key"
                          className="w-1/3 px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                        />
                        <span className="text-gray-500">=</span>
                        <input
                          type="text"
                          value={value}
                          onChange={(e) => handleParamChange(key, e.target.value)}
                          placeholder="{{query.page}}"
                          className="flex-1 px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                        />
                        <button
                          onClick={() => handleDeleteParam(key)}
                          className="text-red-500 hover:text-red-700"
                        >
                          ✕
                        </button>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-xs text-gray-500 dark:text-gray-400">파라미터 없음</p>
                )}
              </div>

              {/* Options */}
              <div className="space-y-2">
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={dataSource.auto_fetch !== false}
                    onChange={(e) =>
                      handleFieldChange('auto_fetch', e.target.checked ? undefined : false)
                    }
                    className="rounded border-gray-300 dark:border-gray-600"
                  />
                  <span className="text-sm text-gray-700 dark:text-gray-300">자동 Fetch</span>
                </label>
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={dataSource.auth_required === true}
                    onChange={(e) =>
                      handleFieldChange('auth_required', e.target.checked ? true : undefined)
                    }
                    className="rounded border-gray-300 dark:border-gray-600"
                  />
                  <span className="text-sm text-gray-700 dark:text-gray-300">인증 필요</span>
                </label>
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={dataSource.refetchOnMount === true}
                    onChange={(e) =>
                      handleFieldChange('refetchOnMount', e.target.checked ? true : undefined)
                    }
                    className="rounded border-gray-300 dark:border-gray-600"
                  />
                  <span className="text-sm text-gray-700 dark:text-gray-300">
                    페이지 진입 시 다시 Fetch
                  </span>
                </label>
              </div>

              {/* 조건부 로딩 */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  조건부 로딩 (if)
                </label>
                <input
                  type="text"
                  value={dataSource.if || ''}
                  onChange={(e) =>
                    handleFieldChange('if', e.target.value || undefined)
                  }
                  placeholder="{{route.id}}"
                  className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                />
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                  조건이 truthy일 때만 fetch 수행
                </p>
              </div>
            </>
          )}

          {/* Static 타입 전용 필드 */}
          {dataSource.type === 'static' && (
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                정적 데이터 <span className="text-red-500">*</span>
              </label>
              <textarea
                value={
                  typeof dataSource.data === 'string'
                    ? dataSource.data
                    : JSON.stringify(dataSource.data, null, 2)
                }
                onChange={(e) => {
                  try {
                    const parsed = JSON.parse(e.target.value);
                    handleFieldChange('data', parsed);
                  } catch {
                    handleFieldChange('data', e.target.value);
                  }
                }}
                placeholder='{"key": "value"}'
                rows={4}
                className="w-full px-3 py-2 text-sm font-mono border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
              />
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                JSON 형식으로 입력
              </p>
            </div>
          )}

          {/* route_params, query_params 타입 안내 */}
          {(dataSource.type === 'route_params' || dataSource.type === 'query_params') && (
            <div className="p-3 bg-blue-50 dark:bg-blue-900/20 rounded text-xs text-blue-700 dark:text-blue-300">
              <p className="font-medium mb-1">
                {dataSource.type === 'route_params' ? 'Route Params' : 'Query Params'} 타입
              </p>
              <p>
                URL의 {dataSource.type === 'route_params' ? '경로 파라미터' : '쿼리 파라미터'}를
                자동으로 추출합니다.
              </p>
              <p className="mt-1">
                참조: {`{{${dataSource.id}.`}
                {dataSource.type === 'route_params' ? 'id' : 'page'}
                {`}}`}
              </p>
            </div>
          )}

          {/* WebSocket 타입 전용 필드 */}
          {dataSource.type === 'websocket' && (
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                WebSocket 엔드포인트 <span className="text-red-500">*</span>
              </label>
              <input
                type="text"
                value={dataSource.endpoint || ''}
                onChange={(e) => handleFieldChange('endpoint', e.target.value)}
                placeholder="wss://example.com/ws"
                className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
              />
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function DataSourceManager({ dataSources, onChange }: DataSourceManagerProps) {
  const [openIndices, setOpenIndices] = useState<Set<number>>(new Set());

  // 기존 ID 목록 (중복 검사용)
  const existingIds = useMemo(
    () => dataSources.map((ds) => ds.id).filter(Boolean),
    [dataSources]
  );

  // 데이터 소스 토글
  const handleToggle = useCallback((index: number) => {
    setOpenIndices((prev) => {
      const next = new Set(prev);
      if (next.has(index)) {
        next.delete(index);
      } else {
        next.add(index);
      }
      return next;
    });
  }, []);

  // 데이터 소스 변경
  const handleChange = useCallback(
    (index: number, dataSource: DataSource) => {
      const newDataSources = [...dataSources];
      newDataSources[index] = dataSource;
      onChange(newDataSources);
    },
    [dataSources, onChange]
  );

  // 데이터 소스 삭제
  const handleDelete = useCallback(
    (index: number) => {
      const newDataSources = dataSources.filter((_, i) => i !== index);
      onChange(newDataSources);
      setOpenIndices((prev) => {
        const next = new Set<number>();
        prev.forEach((i) => {
          if (i < index) next.add(i);
          else if (i > index) next.add(i - 1);
        });
        return next;
      });
    },
    [dataSources, onChange]
  );

  // 새 데이터 소스 추가
  const handleAdd = useCallback(
    (type: DataSource['type']) => {
      const newIndex = dataSources.length;
      const newId = `dataSource${newIndex + 1}`;
      const newDataSource: DataSource = {
        id: newId,
        type,
        ...(type === 'api'
          ? {
              endpoint: '/api/admin/',
              method: 'GET',
              auto_fetch: true,
              auth_required: true,
              loading_strategy: 'progressive',
            }
          : {}),
        ...(type === 'static' ? { data: {} } : {}),
      };
      onChange([...dataSources, newDataSource]);
      setOpenIndices((prev) => new Set(prev).add(newIndex));
    },
    [dataSources, onChange]
  );

  return (
    <div className="p-4 space-y-4">
      {/* 헤더 */}
      <div className="flex items-center justify-between">
        <div>
          <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            데이터 소스
          </h4>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
            레이아웃에서 사용할 데이터를 정의합니다.
          </p>
        </div>
        <span className="text-xs text-gray-500 dark:text-gray-400">
          {dataSources.length}개
        </span>
      </div>

      {/* 데이터 소스 목록 */}
      {dataSources.length > 0 ? (
        <div className="space-y-2">
          {dataSources.map((ds, index) => (
            <DataSourceEditor
              key={`${ds.id}-${index}`}
              dataSource={ds}
              index={index}
              isOpen={openIndices.has(index)}
              onToggle={() => handleToggle(index)}
              onChange={(updated) => handleChange(index, updated)}
              onDelete={() => handleDelete(index)}
              existingIds={existingIds.filter((id) => id !== ds.id)}
            />
          ))}
        </div>
      ) : (
        <div className="p-4 text-center text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 rounded">
          데이터 소스가 없습니다.
          <br />
          아래 버튼으로 추가하세요.
        </div>
      )}

      {/* 추가 버튼들 */}
      <div className="flex flex-wrap gap-2">
        <button
          onClick={() => handleAdd('api')}
          className="px-3 py-1.5 text-sm bg-blue-500 text-white rounded hover:bg-blue-600 transition-colors"
        >
          + API
        </button>
        <button
          onClick={() => handleAdd('static')}
          className="px-3 py-1.5 text-sm bg-gray-500 text-white rounded hover:bg-gray-600 transition-colors"
        >
          + 정적 데이터
        </button>
        <button
          onClick={() => handleAdd('route_params')}
          className="px-3 py-1.5 text-sm bg-purple-500 text-white rounded hover:bg-purple-600 transition-colors"
        >
          + Route Params
        </button>
        <button
          onClick={() => handleAdd('query_params')}
          className="px-3 py-1.5 text-sm bg-indigo-500 text-white rounded hover:bg-indigo-600 transition-colors"
        >
          + Query Params
        </button>
      </div>

      {/* 도움말 */}
      <div className="p-3 bg-blue-50 dark:bg-blue-900/20 rounded text-xs text-blue-700 dark:text-blue-300">
        <p className="font-medium mb-2">데이터 소스 규칙:</p>
        <ul className="list-disc list-inside space-y-1 text-blue-600 dark:text-blue-400">
          <li>
            <strong>ID 고유성</strong>: 부모 레이아웃과 ID 중복 금지
          </li>
          <li>
            <strong>blocking</strong>: SEO 중요 페이지, 결제/주문
          </li>
          <li>
            <strong>progressive</strong>: 관리자 대시보드, 목록 (기본)
          </li>
          <li>
            <strong>background</strong>: 알림 카운트, 부가 통계
          </li>
          <li>
            컴포넌트에서 {`{{id.data}}`} 또는 {`{{id.data.items}}`}로 참조
          </li>
        </ul>
      </div>

      {/* JSON 미리보기 */}
      {dataSources.length > 0 && (
        <div className="space-y-2">
          <h5 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            JSON 미리보기
          </h5>
          <pre className="p-3 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 text-xs text-gray-600 dark:text-gray-400 overflow-auto max-h-48">
            {JSON.stringify(dataSources, null, 2)}
          </pre>
        </div>
      )}
    </div>
  );
}

export default DataSourceManager;
