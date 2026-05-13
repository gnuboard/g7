/**
 * ResponsiveEditor.tsx
 *
 * G7 위지윅 레이아웃 편집기 - 반응형 오버라이드 에디터
 *
 * 역할:
 * - 브레이크포인트별 props 오버라이드 설정
 * - 프리셋 (mobile, tablet, desktop, portable) 지원
 * - 커스텀 범위 지원 (0-599, 600-899 등)
 * - 브레이크포인트별 children, text, if 오버라이드
 *
 * Phase 3: 고급 기능
 */

import React, { useState, useCallback, useMemo } from 'react';
import type { ComponentDefinition } from '../../types/editor';
import { useComponentMetadata } from '../../hooks/useComponentMetadata';

// ============================================================================
// 타입 정의
// ============================================================================

export interface ResponsiveEditorProps {
  /** 편집 대상 컴포넌트 */
  component: ComponentDefinition;
  /** 변경 시 콜백 */
  onChange: (updates: Partial<ComponentDefinition>) => void;
}

interface BreakpointConfig {
  props?: Record<string, any>;
  text?: string;
  if?: string;
  children?: ComponentDefinition[];
}

interface ResponsiveConfig {
  [breakpoint: string]: BreakpointConfig;
}

/** 프리셋 브레이크포인트 정의 */
const BREAKPOINT_PRESETS = [
  { key: 'mobile', label: '모바일', range: '0 ~ 767px', icon: '📱' },
  { key: 'tablet', label: '태블릿', range: '768 ~ 1023px', icon: '📲' },
  { key: 'desktop', label: '데스크톱', range: '1024px 이상', icon: '🖥️' },
  { key: 'portable', label: '포터블', range: '0 ~ 1023px', icon: '📱+📲', description: '모바일 + 태블릿' },
] as const;

/** 자주 사용되는 className 템플릿 */
const CLASS_TEMPLATES = [
  { label: '숨김', value: 'hidden' },
  { label: '표시', value: 'block' },
  { label: 'Flex 표시', value: 'flex' },
  { label: 'Grid 표시', value: 'grid' },
  { label: '1열 그리드', value: 'grid grid-cols-1' },
  { label: '2열 그리드', value: 'grid grid-cols-2' },
  { label: '3열 그리드', value: 'grid grid-cols-3' },
  { label: '세로 정렬', value: 'flex flex-col' },
  { label: '가로 정렬', value: 'flex flex-row' },
  { label: '전체 너비', value: 'w-full' },
  { label: '작은 패딩', value: 'p-2' },
  { label: '중간 패딩', value: 'p-4' },
  { label: '큰 패딩', value: 'p-6' },
];

// ============================================================================
// 브레이크포인트 편집 섹션
// ============================================================================

interface BreakpointSectionProps {
  breakpointKey: string;
  label: string;
  range: string;
  icon: string;
  description?: string;
  config: BreakpointConfig | undefined;
  component: ComponentDefinition;
  isOpen: boolean;
  onToggle: () => void;
  onChange: (config: BreakpointConfig | null) => void;
}

function BreakpointSection({
  breakpointKey,
  label,
  range,
  icon,
  description,
  config,
  component,
  isOpen,
  onToggle,
  onChange,
}: BreakpointSectionProps) {
  const isActive = config !== undefined;
  const { getMetadata } = useComponentMetadata();
  const metadata = getMetadata(component.name);

  // Props 오버라이드 변경
  const handlePropChange = useCallback(
    (propName: string, value: string) => {
      const currentProps = config?.props || {};
      let newProps: Record<string, any>;

      if (value === '') {
        // 값이 비어있으면 해당 prop 제거
        const { [propName]: _, ...rest } = currentProps;
        newProps = rest;
      } else {
        newProps = { ...currentProps, [propName]: value };
      }

      // props가 비어있으면 전체 config 확인
      if (Object.keys(newProps).length === 0 && !config?.text && !config?.if) {
        onChange(null);
      } else {
        onChange({ ...config, props: newProps });
      }
    },
    [config, onChange]
  );

  // Text 오버라이드 변경
  const handleTextChange = useCallback(
    (value: string) => {
      if (value === '') {
        const { text: _, ...rest } = config || {};
        if (Object.keys(rest.props || {}).length === 0 && !rest.if) {
          onChange(null);
        } else {
          onChange(rest);
        }
      } else {
        onChange({ ...config, text: value });
      }
    },
    [config, onChange]
  );

  // If 오버라이드 변경
  const handleIfChange = useCallback(
    (value: string) => {
      if (value === '') {
        const { if: _, ...rest } = config || {};
        if (Object.keys(rest.props || {}).length === 0 && !rest.text) {
          onChange(null);
        } else {
          onChange(rest);
        }
      } else {
        onChange({ ...config, if: value });
      }
    },
    [config, onChange]
  );

  // 브레이크포인트 활성화/비활성화
  const handleToggleActive = useCallback(() => {
    if (isActive) {
      onChange(null);
    } else {
      onChange({ props: {} });
    }
  }, [isActive, onChange]);

  // className 템플릿 적용
  const handleApplyTemplate = useCallback(
    (templateValue: string) => {
      const currentProps = config?.props || {};
      const currentClassName = currentProps.className || '';
      const newClassName = currentClassName
        ? `${currentClassName} ${templateValue}`
        : templateValue;
      onChange({ ...config, props: { ...currentProps, className: newClassName } });
    },
    [config, onChange]
  );

  return (
    <div className="border border-gray-200 dark:border-gray-700 rounded overflow-hidden">
      {/* 헤더 */}
      <div
        className={`flex items-center justify-between px-3 py-2 cursor-pointer transition-colors ${
          isActive
            ? 'bg-blue-50 dark:bg-blue-900/20'
            : 'bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-750'
        }`}
        onClick={onToggle}
      >
        <div className="flex items-center gap-2">
          <span>{icon}</span>
          <div>
            <span className="text-sm font-medium text-gray-900 dark:text-white">
              {label}
            </span>
            <span className="text-xs text-gray-500 dark:text-gray-400 ml-2">
              {range}
            </span>
            {description && (
              <span className="text-xs text-gray-400 dark:text-gray-500 ml-1">
                ({description})
              </span>
            )}
          </div>
        </div>

        <div className="flex items-center gap-2">
          {/* 활성화 토글 */}
          <button
            onClick={(e) => {
              e.stopPropagation();
              handleToggleActive();
            }}
            className={`px-2 py-0.5 text-xs rounded transition-colors ${
              isActive
                ? 'bg-blue-500 text-white'
                : 'bg-gray-200 dark:bg-gray-600 text-gray-600 dark:text-gray-300'
            }`}
          >
            {isActive ? '활성' : '비활성'}
          </button>

          {/* 펼치기/접기 */}
          <span
            className={`text-gray-400 transition-transform ${isOpen ? 'rotate-180' : ''}`}
          >
            ▼
          </span>
        </div>
      </div>

      {/* 컨텐츠 */}
      {isOpen && isActive && (
        <div className="p-3 space-y-4 border-t border-gray-200 dark:border-gray-700">
          {/* className 오버라이드 */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              className
            </label>
            <input
              type="text"
              value={config?.props?.className || ''}
              onChange={(e) => handlePropChange('className', e.target.value)}
              placeholder={component.props?.className || '기본값 사용'}
              className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
            />

            {/* 빠른 템플릿 */}
            <div className="flex flex-wrap gap-1 mt-2">
              {CLASS_TEMPLATES.slice(0, 6).map((template) => (
                <button
                  key={template.value}
                  onClick={() => handleApplyTemplate(template.value)}
                  className="px-2 py-0.5 text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                  title={template.value}
                >
                  {template.label}
                </button>
              ))}
            </div>
          </div>

          {/* 기타 props 오버라이드 */}
          {metadata?.props
            ?.filter((prop) => prop.name !== 'className' && prop.name !== 'children')
            .slice(0, 5)
            .map((prop) => (
              <div key={prop.name}>
                <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                  {prop.label || prop.name}
                </label>
                {prop.type === 'boolean' ? (
                  <select
                    value={
                      config?.props?.[prop.name] === undefined
                        ? ''
                        : String(config.props[prop.name])
                    }
                    onChange={(e) => {
                      const val = e.target.value;
                      if (val === '') {
                        handlePropChange(prop.name, '');
                      } else {
                        handlePropChange(prop.name, val === 'true' ? true : false);
                      }
                    }}
                    className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                  >
                    <option value="">기본값</option>
                    <option value="true">true</option>
                    <option value="false">false</option>
                  </select>
                ) : prop.type === 'select' && prop.options ? (
                  <select
                    value={config?.props?.[prop.name] || ''}
                    onChange={(e) => handlePropChange(prop.name, e.target.value)}
                    className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                  >
                    <option value="">기본값</option>
                    {prop.options.map((opt) => (
                      <option key={String(opt.value)} value={String(opt.value)}>
                        {opt.label}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input
                    type={prop.type === 'number' ? 'number' : 'text'}
                    value={config?.props?.[prop.name] || ''}
                    onChange={(e) => handlePropChange(prop.name, e.target.value)}
                    placeholder={
                      component.props?.[prop.name]
                        ? String(component.props[prop.name])
                        : '기본값'
                    }
                    className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
                  />
                )}
              </div>
            ))}

          {/* text 오버라이드 */}
          {component.text !== undefined && (
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                text
              </label>
              <input
                type="text"
                value={config?.text || ''}
                onChange={(e) => handleTextChange(e.target.value)}
                placeholder={component.text || '기본값 사용'}
                className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
              />
            </div>
          )}

          {/* if 오버라이드 */}
          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
              if (조건부 렌더링)
            </label>
            <input
              type="text"
              value={config?.if || ''}
              onChange={(e) => handleIfChange(e.target.value)}
              placeholder={component.if || '{{true}}'}
              className="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
            />
            <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
              예: <code className="text-blue-600 dark:text-blue-400">{`{{_global.sidebarOpen}}`}</code>
            </p>
          </div>

          {/* 설정된 값 요약 */}
          {config && Object.keys(config).length > 0 && (
            <div className="mt-3 p-2 bg-gray-50 dark:bg-gray-800 rounded text-xs">
              <p className="font-medium text-gray-700 dark:text-gray-300 mb-1">
                설정된 오버라이드:
              </p>
              <pre className="text-gray-600 dark:text-gray-400 overflow-auto max-h-24">
                {JSON.stringify(config, null, 2)}
              </pre>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ============================================================================
// 커스텀 범위 편집기
// ============================================================================

interface CustomRangeEditorProps {
  responsive: ResponsiveConfig | undefined;
  onChange: (key: string, config: BreakpointConfig | null) => void;
  onAddRange: (rangeKey: string) => void;
  onRemoveRange: (rangeKey: string) => void;
}

function CustomRangeEditor({
  responsive,
  onChange,
  onAddRange,
  onRemoveRange,
}: CustomRangeEditorProps) {
  const [newRangeMin, setNewRangeMin] = useState('');
  const [newRangeMax, setNewRangeMax] = useState('');

  // 커스텀 범위 키 추출 (프리셋이 아닌 것)
  const customRanges = useMemo(() => {
    if (!responsive) return [];
    const presetKeys = BREAKPOINT_PRESETS.map((p) => p.key);
    return Object.keys(responsive).filter((key) => !presetKeys.includes(key));
  }, [responsive]);

  // 새 커스텀 범위 추가
  const handleAddRange = useCallback(() => {
    let rangeKey: string;
    if (newRangeMin && newRangeMax) {
      rangeKey = `${newRangeMin}-${newRangeMax}`;
    } else if (newRangeMin && !newRangeMax) {
      rangeKey = `${newRangeMin}-`;
    } else if (!newRangeMin && newRangeMax) {
      rangeKey = `-${newRangeMax}`;
    } else {
      return;
    }

    onAddRange(rangeKey);
    setNewRangeMin('');
    setNewRangeMax('');
  }, [newRangeMin, newRangeMax, onAddRange]);

  return (
    <div className="space-y-3">
      <h5 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
        커스텀 범위
      </h5>
      <p className="text-xs text-gray-500 dark:text-gray-400">
        특정 화면 너비 범위에 대한 오버라이드를 설정합니다.
      </p>

      {/* 기존 커스텀 범위 목록 */}
      {customRanges.length > 0 && (
        <div className="space-y-2">
          {customRanges.map((rangeKey) => (
            <div
              key={rangeKey}
              className="flex items-center justify-between p-2 bg-gray-50 dark:bg-gray-800 rounded"
            >
              <span className="text-sm font-mono text-gray-700 dark:text-gray-300">
                {rangeKey}px
              </span>
              <button
                onClick={() => onRemoveRange(rangeKey)}
                className="text-xs text-red-500 hover:text-red-700"
              >
                삭제
              </button>
            </div>
          ))}
        </div>
      )}

      {/* 새 커스텀 범위 추가 */}
      <div className="flex items-center gap-2">
        <input
          type="number"
          value={newRangeMin}
          onChange={(e) => setNewRangeMin(e.target.value)}
          placeholder="최소"
          className="w-20 px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400"
        />
        <span className="text-gray-500">~</span>
        <input
          type="number"
          value={newRangeMax}
          onChange={(e) => setNewRangeMax(e.target.value)}
          placeholder="최대"
          className="w-20 px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400"
        />
        <span className="text-gray-500 text-sm">px</span>
        <button
          onClick={handleAddRange}
          disabled={!newRangeMin && !newRangeMax}
          className="px-3 py-1 text-sm bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          추가
        </button>
      </div>

      <p className="text-xs text-gray-400 dark:text-gray-500">
        예: 0-599 (작은 모바일), 600-899 (큰 모바일), 1200- (와이드 데스크톱)
      </p>
    </div>
  );
}

// ============================================================================
// 메인 컴포넌트
// ============================================================================

export function ResponsiveEditor({ component, onChange }: ResponsiveEditorProps) {
  const [openBreakpoints, setOpenBreakpoints] = useState<Set<string>>(new Set());
  const [showCustomRanges, setShowCustomRanges] = useState(false);

  const responsive = component.responsive as ResponsiveConfig | undefined;

  // 브레이크포인트 토글
  const handleToggleBreakpoint = useCallback((key: string) => {
    setOpenBreakpoints((prev) => {
      const next = new Set(prev);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      return next;
    });
  }, []);

  // 브레이크포인트 설정 변경
  const handleBreakpointChange = useCallback(
    (key: string, config: BreakpointConfig | null) => {
      const currentResponsive = { ...(responsive || {}) };

      if (config === null) {
        delete currentResponsive[key];
      } else {
        currentResponsive[key] = config;
      }

      // responsive 객체가 비어있으면 undefined로 설정
      if (Object.keys(currentResponsive).length === 0) {
        onChange({ responsive: undefined });
      } else {
        onChange({ responsive: currentResponsive });
      }
    },
    [responsive, onChange]
  );

  // 커스텀 범위 추가
  const handleAddCustomRange = useCallback(
    (rangeKey: string) => {
      const currentResponsive = { ...(responsive || {}) };
      currentResponsive[rangeKey] = { props: {} };
      onChange({ responsive: currentResponsive });
      setOpenBreakpoints((prev) => new Set(prev).add(rangeKey));
    },
    [responsive, onChange]
  );

  // 커스텀 범위 삭제
  const handleRemoveCustomRange = useCallback(
    (rangeKey: string) => {
      const currentResponsive = { ...(responsive || {}) };
      delete currentResponsive[rangeKey];

      if (Object.keys(currentResponsive).length === 0) {
        onChange({ responsive: undefined });
      } else {
        onChange({ responsive: currentResponsive });
      }
    },
    [responsive, onChange]
  );

  // 모든 반응형 설정 삭제
  const handleClearAll = useCallback(() => {
    onChange({ responsive: undefined });
  }, [onChange]);

  // 활성화된 브레이크포인트 개수
  const activeCount = useMemo(() => {
    if (!responsive) return 0;
    return Object.keys(responsive).length;
  }, [responsive]);

  return (
    <div className="p-4 space-y-6">
      {/* 헤더 */}
      <div className="flex items-center justify-between">
        <div>
          <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            반응형 오버라이드
          </h4>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
            브레이크포인트별로 다른 속성을 설정합니다.
          </p>
        </div>
        {activeCount > 0 && (
          <button
            onClick={handleClearAll}
            className="text-xs text-red-500 hover:text-red-700"
          >
            모두 삭제
          </button>
        )}
      </div>

      {/* 상태 배지 */}
      {activeCount > 0 && (
        <div className="flex items-center gap-2">
          <span className="px-2 py-0.5 text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 rounded">
            📱 {activeCount}개 브레이크포인트 활성화
          </span>
        </div>
      )}

      {/* 프리셋 브레이크포인트 */}
      <div className="space-y-2">
        <h5 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
          프리셋 브레이크포인트
        </h5>

        {BREAKPOINT_PRESETS.map((preset) => (
          <BreakpointSection
            key={preset.key}
            breakpointKey={preset.key}
            label={preset.label}
            range={preset.range}
            icon={preset.icon}
            description={preset.description}
            config={responsive?.[preset.key]}
            component={component}
            isOpen={openBreakpoints.has(preset.key)}
            onToggle={() => handleToggleBreakpoint(preset.key)}
            onChange={(config) => handleBreakpointChange(preset.key, config)}
          />
        ))}
      </div>

      {/* 구분선 */}
      <hr className="border-gray-200 dark:border-gray-700" />

      {/* 커스텀 범위 */}
      <div>
        <button
          onClick={() => setShowCustomRanges(!showCustomRanges)}
          className="flex items-center gap-2 text-sm text-blue-600 dark:text-blue-400 hover:underline"
        >
          <span>{showCustomRanges ? '▼' : '▶'}</span>
          <span>커스텀 범위 설정</span>
        </button>

        {showCustomRanges && (
          <div className="mt-3">
            <CustomRangeEditor
              responsive={responsive}
              onChange={handleBreakpointChange}
              onAddRange={handleAddCustomRange}
              onRemoveRange={handleRemoveCustomRange}
            />
          </div>
        )}
      </div>

      {/* 도움말 */}
      <div className="p-3 bg-blue-50 dark:bg-blue-900/20 rounded text-xs text-blue-700 dark:text-blue-300">
        <p className="font-medium mb-2">반응형 규칙:</p>
        <ul className="list-disc list-inside space-y-1 text-blue-600 dark:text-blue-400">
          <li>
            <strong>portable</strong>을 사용할 때는 mobile/tablet과 혼용 금지
          </li>
          <li>프리셋 브레이크포인트보다 커스텀 범위가 우선 적용</li>
          <li>좁은 범위가 넓은 범위보다 우선 적용</li>
          <li>Props는 얕은 머지 (shallow merge) 적용</li>
        </ul>
      </div>

      {/* 전체 설정 미리보기 */}
      {responsive && Object.keys(responsive).length > 0 && (
        <div className="space-y-2">
          <h5 className="text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">
            설정 미리보기 (JSON)
          </h5>
          <pre className="p-3 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 text-xs text-gray-600 dark:text-gray-400 overflow-auto max-h-48">
            {JSON.stringify(responsive, null, 2)}
          </pre>
        </div>
      )}
    </div>
  );
}

export default ResponsiveEditor;
