/**
 * PreviewCanvas.tsx
 *
 * G7 위지윅 레이아웃 편집기의 미리보기 캔버스 컴포넌트
 *
 * 역할:
 * - 레이아웃 실시간 렌더링
 * - 반응형 디바이스 프리뷰
 * - 컴포넌트 선택/호버 오버레이
 * - 드래그앤드롭 타겟
 */

import React, { useCallback, useMemo, useRef, useEffect, useState } from 'react';
import { useEditorState } from '../hooks/useEditorState';
import type { PreviewDevice, DropPosition } from '../types/editor';
import { createLogger } from '../../../utils/Logger';
import { findComponentByPath } from '../utils/layoutUtils';

// 템플릿 엔진 코어 모듈
import DynamicRenderer, { type ComponentDefinition } from '../../DynamicRenderer';
import { ComponentRegistry } from '../../ComponentRegistry';
import { DataBindingEngine } from '../../DataBindingEngine';
import { TranslationEngine, type TranslationContext } from '../../TranslationEngine';
import { ActionDispatcher } from '../../ActionDispatcher';
import { TranslationProvider } from '../../TranslationContext';
import { ResponsiveProvider } from '../../ResponsiveContext';
import { TransitionProvider } from '../../TransitionContext';
import { SlotProvider } from '../../SlotContext';

const logger = createLogger('PreviewCanvas');

// ============================================================================
// 타입 정의
// ============================================================================

export interface PreviewCanvasProps {
  /** 클래스명 */
  className?: string;

  /** 편집 오버레이 표시 여부 */
  showOverlay?: boolean;

  /** 컴포넌트 선택 핸들러 */
  onComponentSelect?: (id: string | null) => void;

  /** 컴포넌트 호버 핸들러 */
  onComponentHover?: (id: string | null) => void;

  /** 컴포넌트 드롭 핸들러 */
  onComponentDrop?: (targetId: string, position: DropPosition) => void;
}

interface DeviceConfig {
  width: number;
  label: string;
  icon: string;
}

// ============================================================================
// 상수
// ============================================================================

const DEVICE_CONFIGS: Record<PreviewDevice, DeviceConfig> = {
  desktop: { width: 1920, label: 'Desktop', icon: '🖥️' },
  tablet: { width: 768, label: 'Tablet', icon: '📱' },
  mobile: { width: 375, label: 'Mobile', icon: '📱' },
  custom: { width: 0, label: 'Custom', icon: '📐' },
};

// ============================================================================
// 디바이스 선택기 컴포넌트
// ============================================================================

interface DeviceSelectorProps {
  currentDevice: PreviewDevice;
  customWidth: number;
  onDeviceChange: (device: PreviewDevice) => void;
  onCustomWidthChange: (width: number) => void;
}

function DeviceSelector({
  currentDevice,
  customWidth,
  onDeviceChange,
  onCustomWidthChange,
}: DeviceSelectorProps) {
  const devices: PreviewDevice[] = ['desktop', 'tablet', 'mobile', 'custom'];

  return (
    <div className="flex items-center gap-2 p-2 bg-gray-100 dark:bg-gray-800 rounded-lg">
      {devices.map((device) => {
        const config = DEVICE_CONFIGS[device];
        const isActive = currentDevice === device;

        return (
          <button
            key={device}
            className={`flex items-center gap-1 px-3 py-1.5 rounded text-sm transition-colors ${
              isActive
                ? 'bg-blue-500 text-white'
                : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600'
            }`}
            onClick={() => onDeviceChange(device)}
            title={`${config.label} (${device === 'custom' ? customWidth : config.width}px)`}
          >
            <span>{config.icon}</span>
            <span className="hidden sm:inline">{config.label}</span>
          </button>
        );
      })}

      {/* 커스텀 너비 입력 */}
      {currentDevice === 'custom' && (
        <div className="flex items-center gap-1 ml-2">
          <input
            type="number"
            value={customWidth}
            onChange={(e) => onCustomWidthChange(parseInt(e.target.value, 10) || 320)}
            min={320}
            max={2560}
            className="w-20 px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300"
          />
          <span className="text-sm text-gray-500 dark:text-gray-400">px</span>
        </div>
      )}
    </div>
  );
}

// ============================================================================
// 줌 컨트롤 컴포넌트
// ============================================================================

interface ZoomControlProps {
  zoom: number;
  onZoomChange: (zoom: number) => void;
}

function ZoomControl({ zoom, onZoomChange }: ZoomControlProps) {
  const zoomLevels = [25, 50, 75, 100, 125, 150, 200];

  return (
    <div className="flex items-center gap-2">
      <button
        className="p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-700"
        onClick={() => onZoomChange(Math.max(25, zoom - 25))}
        disabled={zoom <= 25}
        title="축소"
      >
        ➖
      </button>

      <select
        value={zoom}
        onChange={(e) => onZoomChange(parseInt(e.target.value, 10))}
        className="px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300"
      >
        {zoomLevels.map((level) => (
          <option key={level} value={level}>
            {level}%
          </option>
        ))}
      </select>

      <button
        className="p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-700"
        onClick={() => onZoomChange(Math.min(200, zoom + 25))}
        disabled={zoom >= 200}
        title="확대"
      >
        ➕
      </button>

      <button
        className="p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-700"
        onClick={() => onZoomChange(100)}
        title="100%로 리셋"
      >
        🔄
      </button>
    </div>
  );
}

// ============================================================================
// 드롭 인디케이터 컴포넌트
// ============================================================================

interface DropIndicatorProps {
  targetId: string | null;
  position: DropPosition | null;
  containerRef: React.RefObject<HTMLDivElement | null>;
}

function DropIndicator({ targetId, position, containerRef }: DropIndicatorProps) {
  const [rect, setRect] = useState<DOMRect | null>(null);

  useEffect(() => {
    if (!targetId || !position || !containerRef.current) {
      setRect(null);
      return;
    }

    const container = containerRef.current;
    const element = container.querySelector(`[data-editor-id="${targetId}"]`);

    if (element) {
      const containerRect = container.getBoundingClientRect();
      const scrollLeft = container.scrollLeft;
      const scrollTop = container.scrollTop;
      const elRect = element.getBoundingClientRect();

      setRect(
        new DOMRect(
          elRect.left - containerRect.left + scrollLeft,
          elRect.top - containerRect.top + scrollTop,
          elRect.width,
          elRect.height
        )
      );
    } else {
      setRect(null);
    }
  }, [targetId, position, containerRef]);

  if (!rect || !position) return null;

  // 드롭 위치에 따른 인디케이터 스타일
  if (position === 'before') {
    return (
      <div
        className="absolute h-1 bg-blue-500 rounded pointer-events-none z-50"
        style={{
          left: rect.x,
          top: rect.y - 2,
          width: rect.width,
        }}
      />
    );
  }

  if (position === 'after') {
    return (
      <div
        className="absolute h-1 bg-blue-500 rounded pointer-events-none z-50"
        style={{
          left: rect.x,
          top: rect.y + rect.height,
          width: rect.width,
        }}
      />
    );
  }

  // 'inside' - 컨테이너 안쪽 표시
  return (
    <div
      className="absolute border-2 border-dashed border-blue-500 rounded pointer-events-none z-50"
      style={{
        left: rect.x,
        top: rect.y,
        width: rect.width,
        height: rect.height,
      }}
    />
  );
}

// ============================================================================
// 선택 오버레이 컴포넌트
// ============================================================================

interface SelectionOverlayProps {
  selectedId: string | null;
  hoveredId: string | null;
  containerRef: React.RefObject<HTMLDivElement | null>;
  zoom: number; // 줌 레벨 (100 = 100%)
}

/**
 * data-editor-id로 DOM 요소를 찾는 헬퍼 함수
 *
 * Note: selectComponent에서 auto_path: ID가 영구 ID로 정규화되므로
 * 여기서는 단순히 data-editor-id로만 검색하면 됨
 */
function findElementByEditorId(container: HTMLElement, id: string): Element | null {
  return container.querySelector(`[data-editor-id="${id}"]`);
}

function SelectionOverlay({
  selectedId,
  hoveredId,
  containerRef,
  zoom,
}: SelectionOverlayProps) {
  const [selectedRect, setSelectedRect] = useState<DOMRect | null>(null);
  const [hoveredRect, setHoveredRect] = useState<DOMRect | null>(null);

  // 요소 위치 업데이트
  useEffect(() => {
    if (!containerRef.current) return;

    const updateRects = () => {
      const container = containerRef.current;
      if (!container) return;

      // 컨테이너의 스크롤 위치를 고려한 상대 좌표 계산
      const containerRect = container.getBoundingClientRect();
      const scrollLeft = container.scrollLeft;
      const scrollTop = container.scrollTop;

      // getBoundingClientRect는 transform: scale() 적용 후의 시각적 좌표를 반환하므로
      // 오버레이도 동일한 스케일된 좌표로 그려야 정확히 일치함

      if (selectedId) {
        const selectedEl = findElementByEditorId(container, selectedId);
        if (selectedEl) {
          const elRect = selectedEl.getBoundingClientRect();
          // 뷰포트 기준 좌표를 컨테이너 기준으로 변환 + 스크롤 오프셋 추가
          // getBoundingClientRect는 이미 스케일된 시각적 좌표를 반환하므로
          // 오버레이도 스케일된 좌표로 그려야 정확히 일치함
          setSelectedRect(
            new DOMRect(
              elRect.left - containerRect.left + scrollLeft,
              elRect.top - containerRect.top + scrollTop,
              elRect.width,
              elRect.height
            )
          );
        } else {
          setSelectedRect(null);
        }
      } else {
        setSelectedRect(null);
      }

      if (hoveredId && hoveredId !== selectedId) {
        const hoveredEl = findElementByEditorId(container, hoveredId);
        if (hoveredEl) {
          const elRect = hoveredEl.getBoundingClientRect();
          // 뷰포트 기준 좌표를 컨테이너 기준으로 변환 + 스크롤 오프셋 추가
          setHoveredRect(
            new DOMRect(
              elRect.left - containerRect.left + scrollLeft,
              elRect.top - containerRect.top + scrollTop,
              elRect.width,
              elRect.height
            )
          );
        } else {
          setHoveredRect(null);
        }
      } else {
        setHoveredRect(null);
      }
    };

    // 초기 업데이트
    updateRects();

    // zoom 변경 시 transform 애니메이션 동안 지속적으로 업데이트 (duration-300 = 300ms)
    // requestAnimationFrame을 사용하여 애니메이션 프레임마다 위치 재계산
    let animationFrameId: number;
    let animationStartTime: number | null = null;
    const ANIMATION_DURATION = 350; // 애니메이션 시간 + 여유

    const animateUpdate = (currentTime: number) => {
      if (animationStartTime === null) {
        animationStartTime = currentTime;
      }
      const elapsed = currentTime - animationStartTime;

      updateRects();

      if (elapsed < ANIMATION_DURATION) {
        animationFrameId = requestAnimationFrame(animateUpdate);
      }
    };

    animationFrameId = requestAnimationFrame(animateUpdate);

    // ResizeObserver로 크기 변경 감지
    const observer = new ResizeObserver(updateRects);
    observer.observe(containerRef.current);

    // 스크롤 이벤트 감지 (컨테이너 내부 + 전역)
    const container = containerRef.current;
    container.addEventListener('scroll', updateRects);
    window.addEventListener('scroll', updateRects, true); // capture 모드로 모든 스크롤 감지

    // MutationObserver로 DOM 변경 감지
    const mutationObserver = new MutationObserver(updateRects);
    mutationObserver.observe(container, { childList: true, subtree: true });

    return () => {
      cancelAnimationFrame(animationFrameId);
      observer.disconnect();
      mutationObserver.disconnect();
      container?.removeEventListener('scroll', updateRects);
      window.removeEventListener('scroll', updateRects, true);
    };
  }, [selectedId, hoveredId, containerRef, zoom]);

  return (
    <>
      {/* 선택된 컴포넌트 하이라이트 - 배경 없이 테두리만 표시 */}
      {selectedRect && (
        <div
          className="absolute pointer-events-none border-2 border-blue-500"
          style={{
            left: selectedRect.x,
            top: selectedRect.y,
            width: selectedRect.width,
            height: selectedRect.height,
          }}
        >
          {/* 선택 핸들 - 좌상단 */}
          <div className="absolute -left-1 -top-1 w-2 h-2 bg-blue-500 rounded-full" />
          {/* 선택 핸들 - 우상단 */}
          <div className="absolute -right-1 -top-1 w-2 h-2 bg-blue-500 rounded-full" />
          {/* 선택 핸들 - 좌하단 */}
          <div className="absolute -left-1 -bottom-1 w-2 h-2 bg-blue-500 rounded-full" />
          {/* 선택 핸들 - 우하단 */}
          <div className="absolute -right-1 -bottom-1 w-2 h-2 bg-blue-500 rounded-full" />

          {/* 컴포넌트 ID 레이블 */}
          <div className="absolute -top-6 left-0 px-1 py-0.5 text-xs bg-blue-500 text-white rounded whitespace-nowrap">
            {selectedId}
          </div>
        </div>
      )}

      {/* 호버된 컴포넌트 하이라이트 (가볍게 표시) */}
      {hoveredRect && (
        <div
          className="absolute pointer-events-none border border-dashed border-green-400"
          style={{
            left: hoveredRect.x,
            top: hoveredRect.y,
            width: hoveredRect.width,
            height: hoveredRect.height,
            // 배경 없이 테두리만 표시하여 내부 컴포넌트가 잘 보이도록 함
          }}
        >
          {/* 호버된 컴포넌트 ID 레이블 (작게 표시) */}
          <div className="absolute -top-5 left-0 px-1 py-0.5 text-xs bg-green-400 text-white rounded whitespace-nowrap opacity-80">
            {hoveredId}
          </div>
        </div>
      )}
    </>
  );
}

// ============================================================================
// 빈 상태 컴포넌트
// ============================================================================

function EmptyState() {
  return (
    <div className="flex flex-col items-center justify-center h-full text-gray-500 dark:text-gray-400">
      <div className="text-6xl mb-4">📄</div>
      <h3 className="text-lg font-medium mb-2">레이아웃이 없습니다</h3>
      <p className="text-sm text-center max-w-xs">
        편집할 레이아웃을 선택하거나, 새 레이아웃을 생성하세요.
      </p>
    </div>
  );
}

// ============================================================================
// 로딩 상태 컴포넌트
// ============================================================================

function LoadingState() {
  return (
    <div className="flex flex-col items-center justify-center h-full text-gray-500 dark:text-gray-400">
      <div className="animate-spin text-4xl mb-4">⏳</div>
      <p className="text-sm">레이아웃 로딩 중...</p>
    </div>
  );
}

// ============================================================================
// 에러 상태 컴포넌트
// ============================================================================

interface ErrorStateProps {
  message: string;
  onRetry?: () => void;
}

function ErrorState({ message, onRetry }: ErrorStateProps) {
  return (
    <div className="flex flex-col items-center justify-center h-full text-red-500 dark:text-red-400">
      <div className="text-6xl mb-4">⚠️</div>
      <h3 className="text-lg font-medium mb-2">오류 발생</h3>
      <p className="text-sm text-center max-w-xs mb-4">{message}</p>
      {onRetry && (
        <button
          className="px-4 py-2 bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300 rounded hover:bg-red-200 dark:hover:bg-red-800"
          onClick={onRetry}
        >
          다시 시도
        </button>
      )}
    </div>
  );
}

// ============================================================================
// 렌더링 프레임 컴포넌트
// ============================================================================

interface RenderFrameProps {
  width: number;
  zoom: number;
  children: React.ReactNode;
}

function RenderFrame({ width, zoom, children }: RenderFrameProps) {
  const scale = zoom / 100;
  const scaledWidth = width * scale;

  return (
    <div
      className="mx-auto bg-white dark:bg-gray-900 shadow-xl rounded-lg overflow-hidden transition-all duration-300"
      style={{
        width: scaledWidth,
        transform: `scale(${scale})`,
        transformOrigin: 'top center',
      }}
    >
      {/* 브라우저 크롬 (상단 바) */}
      <div className="flex items-center gap-2 px-3 py-2 bg-gray-100 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
        {/* 창 버튼들 */}
        <div className="flex gap-1.5">
          <div className="w-3 h-3 rounded-full bg-red-500" />
          <div className="w-3 h-3 rounded-full bg-yellow-500" />
          <div className="w-3 h-3 rounded-full bg-green-500" />
        </div>

        {/* URL 바 */}
        <div className="flex-1 mx-4">
          <div className="px-3 py-1 text-xs bg-white dark:bg-gray-700 rounded text-gray-500 dark:text-gray-400 truncate">
            {window.location.origin}/preview
          </div>
        </div>
      </div>

      {/* 렌더링 영역 */}
      <div className="min-h-96">{children}</div>
    </div>
  );
}

// ============================================================================
// PreviewCanvas 메인 컴포넌트
// ============================================================================

export function PreviewCanvas({
  className = '',
  showOverlay = true,
  onComponentSelect,
  onComponentHover,
  onComponentDrop,
}: PreviewCanvasProps) {
  const containerRef = useRef<HTMLDivElement>(null);

  // Zustand 상태
  const layoutData = useEditorState((state) => state.layoutData);
  const loadingState = useEditorState((state) => state.loadingState);
  const error = useEditorState((state) => state.error);
  const previewDevice = useEditorState((state) => state.previewDevice);
  const customWidth = useEditorState((state) => state.customWidth);
  const selectedComponentId = useEditorState((state) => state.selectedComponentId);
  const hoveredComponentId = useEditorState((state) => state.hoveredComponentId);
  const setPreviewDevice = useEditorState((state) => state.setPreviewDevice);
  const selectComponent = useEditorState((state) => state.selectComponent);
  const setHoveredComponentId = useEditorState((state) => state.setHoveredComponentId);
  const mockDataContext = useEditorState((state) => state.mockDataContext);
  const templateId = useEditorState((state) => state.templateId);

  // 드래그 앤 드롭 관련 상태
  const moveComponent = useEditorState((state) => state.moveComponent);
  const startDrag = useEditorState((state) => state.startDrag);
  const dragOver = useEditorState((state) => state.dragOver);
  const endDrag = useEditorState((state) => state.endDrag);
  const dropTargetId = useEditorState((state) => state.dropTargetId);
  const dropPosition = useEditorState((state) => state.dropPosition);
  const ensureComponentIdAndMove = useEditorState((state) => state.ensureComponentIdAndMove);
  const moveComponentByPaths = useEditorState((state) => state.moveComponentByPaths);

  // 로컬 상태
  const [zoom, setZoom] = useState(100);
  const [engineReady, setEngineReady] = useState(false);

  // 자동 스크롤용 ref
  const scrollAnimationRef = useRef<number | null>(null);

  // 자동 스크롤 상수
  const SCROLL_THRESHOLD = 50; // 경계에서 50px 이내
  const SCROLL_SPEED = 8; // 스크롤 속도 (px/frame)

  // 템플릿 엔진 인스턴스들
  const registryRef = useRef<ComponentRegistry | null>(null);
  const bindingEngineRef = useRef<DataBindingEngine | null>(null);
  const translationEngineRef = useRef<TranslationEngine | null>(null);
  const actionDispatcherRef = useRef<ActionDispatcher | null>(null);

  // 번역 컨텍스트
  const translationContext = useMemo<TranslationContext>(() => ({
    templateId: templateId || 'sirsoft-basic',
    locale: 'ko',
  }), [templateId]);

  // 편집기용 데이터 컨텍스트 (Mock 데이터 포함)
  const editorDataContext = useMemo(() => ({
    _global: {
      sidebarOpen: false,
      ...(mockDataContext?._global || {}),
    },
    _local: {
      ...(mockDataContext?._local || {}),
    },
    $locale: 'ko',
    $templateId: templateId || 'sirsoft-basic',
    route: {},
    query: {},
    ...(mockDataContext || {}),
  }), [mockDataContext, templateId]);

  // 엔진 인스턴스 초기화
  useEffect(() => {
    const initEngines = async () => {
      try {
        // ComponentRegistry 초기화
        if (!registryRef.current) {
          registryRef.current = ComponentRegistry.getInstance();
          // 템플릿 컴포넌트 로드 (활성 템플릿에서)
          if (templateId) {
            // templateType은 user 템플릿으로 가정 (위지윅은 user 템플릿만 지원)
            await registryRef.current.loadComponents(templateId, 'user');
          }
        }

        // DataBindingEngine 초기화
        if (!bindingEngineRef.current) {
          bindingEngineRef.current = new DataBindingEngine();
        }

        // TranslationEngine 초기화
        if (!translationEngineRef.current) {
          translationEngineRef.current = TranslationEngine.getInstance();
        }

        // ActionDispatcher 초기화 (편집 모드에서는 액션 실행 비활성화)
        if (!actionDispatcherRef.current && translationEngineRef.current) {
          actionDispatcherRef.current = new ActionDispatcher(
            {}, // 빈 상태 (편집 모드에서는 상태 변경 불필요)
            translationEngineRef.current,
            translationContext
          );
        }

        setEngineReady(true);
        logger.log('Editor engines initialized');
      } catch (err) {
        logger.error('Failed to initialize engines:', err);
      }
    };

    initEngines();
  }, [templateId, translationContext]);

  // DynamicRenderer용 컴포넌트 선택 핸들러
  const handleDynamicSelect = useCallback(
    (componentId: string, e: React.MouseEvent) => {
      e.stopPropagation();

      // auto_ ID인 경우 data-editor-path 정보도 함께 저장
      if (componentId.startsWith('auto_')) {
        const element = e.target as HTMLElement;
        const componentEl = element.closest('[data-editor-path]');
        const path = componentEl?.getAttribute('data-editor-path');
        if (path) {
          // 경로 정보를 포함한 특수 ID 형식 사용: auto_path:경로
          const effectiveId = `auto_path:${path}`;
          selectComponent(effectiveId);
          onComponentSelect?.(effectiveId);
          logger.log('Component selected via DynamicRenderer:', componentId, '-> path:', path);
          return;
        }
      }

      selectComponent(componentId);
      onComponentSelect?.(componentId);
      logger.log('Component selected via DynamicRenderer:', componentId);
    },
    [selectComponent, onComponentSelect]
  );

  // DynamicRenderer용 컴포넌트 호버 핸들러
  const handleDynamicHover = useCallback(
    (componentId: string | null, e: React.MouseEvent) => {
      logger.log('handleDynamicHover called:', componentId);
      setHoveredComponentId(componentId);
      onComponentHover?.(componentId);
    },
    [setHoveredComponentId, onComponentHover]
  );

  // DynamicRenderer용 드래그 시작 핸들러
  const handleDynamicDragStart = useCallback(
    (componentId: string, e: React.DragEvent) => {
      if (!layoutData) {
        e.dataTransfer.effectAllowed = 'none';
        return;
      }

      // 자동 생성 ID (auto_로 시작)인 경우 - 경로 기반으로 처리
      if (componentId.startsWith('auto_')) {
        const element = e.target as HTMLElement;
        const componentEl = element.closest('[data-editor-path]');
        const path = componentEl?.getAttribute('data-editor-path');

        if (path) {
          // 경로로 컴포넌트 찾기
          const component = findComponentByPath(layoutData.components, path);
          if (component) {
            startDrag(component);
            e.dataTransfer.effectAllowed = 'move';
            // 경로 기반 드래그임을 표시 (path: 접두사)
            e.dataTransfer.setData('text/plain', `path:${path}`);
            logger.log('Drag started (path-based):', path);
            return;
          }
        }

        // 경로도 없으면 드래그 불가
        logger.warn('Auto-generated component without valid path:', componentId);
        e.dataTransfer.effectAllowed = 'none';
        return;
      }

      // ID 기반 드래그 (기존 로직)
      const findComponent = (
        components: (typeof layoutData)['components'],
        id: string
      ): ComponentDefinition | null => {
        for (const comp of components) {
          if (typeof comp === 'string') continue;
          const compDef = comp as unknown as ComponentDefinition;
          if (compDef.id === id) return compDef;
          if (compDef.children) {
            const found = findComponent(compDef.children as any, id);
            if (found) return found;
          }
        }
        return null;
      };

      const component = findComponent(layoutData.components, componentId);
      if (component) {
        startDrag(component);
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', componentId);
        logger.log('Drag started (id-based):', componentId);
      } else {
        // 컴포넌트를 찾을 수 없으면 드래그 취소
        logger.warn('Component not found for drag:', componentId);
        e.dataTransfer.effectAllowed = 'none';
      }
    },
    [layoutData, startDrag]
  );

  // DynamicRenderer용 드래그 종료 핸들러
  const handleDynamicDragEnd = useCallback(
    (e: React.DragEvent) => {
      // 자동 스크롤 중지
      if (scrollAnimationRef.current) {
        cancelAnimationFrame(scrollAnimationRef.current);
        scrollAnimationRef.current = null;
      }
      endDrag();
      logger.log('Drag ended');
    },
    [endDrag]
  );

  // 현재 프리뷰 너비 계산
  const previewWidth = useMemo(() => {
    if (previewDevice === 'custom') {
      return customWidth;
    }
    return DEVICE_CONFIGS[previewDevice].width;
  }, [previewDevice, customWidth]);

  // 디바이스 변경 핸들러
  const handleDeviceChange = useCallback(
    (device: PreviewDevice) => {
      setPreviewDevice(device);
      logger.log('Device changed:', device);
    },
    [setPreviewDevice]
  );

  // 커스텀 너비 변경 핸들러
  const handleCustomWidthChange = useCallback(
    (width: number) => {
      setPreviewDevice('custom', width);
      logger.log('Custom width changed:', width);
    },
    [setPreviewDevice]
  );

  // 컴포넌트 클릭 핸들러 (가장 깊은 컴포넌트 우선 선택)
  const handleComponentClick = useCallback(
    (e: React.MouseEvent) => {
      const target = e.target as HTMLElement;

      // target 자체가 data-editor-id를 가지고 있으면 그것을 사용
      // 그렇지 않으면 가장 가까운 부모를 찾음
      let componentEl: Element | null = null;

      if (target.hasAttribute('data-editor-id')) {
        componentEl = target;
      } else {
        componentEl = target.closest('[data-editor-id]');
      }

      if (componentEl) {
        const componentId = componentEl.getAttribute('data-editor-id');
        if (componentId) {
          selectComponent(componentId);
          onComponentSelect?.(componentId);
          logger.log('Component selected:', componentId);
        }
      } else {
        // 빈 영역 클릭 시 선택 해제
        selectComponent(null);
        onComponentSelect?.(null);
      }
    },
    [selectComponent, onComponentSelect]
  );

  // 컴포넌트 호버 핸들러 (가장 깊은 컴포넌트 우선 선택)
  const handleComponentHover = useCallback(
    (e: React.MouseEvent) => {
      const target = e.target as HTMLElement;

      // target 자체가 data-editor-id를 가지고 있으면 그것을 사용
      // 그렇지 않으면 가장 가까운 부모를 찾음
      let componentEl: Element | null = null;

      if (target.hasAttribute('data-editor-id')) {
        componentEl = target;
      } else {
        componentEl = target.closest('[data-editor-id]');
      }

      if (componentEl) {
        const componentId = componentEl.getAttribute('data-editor-id');
        if (componentId && componentId !== hoveredComponentId) {
          setHoveredComponentId(componentId);
          onComponentHover?.(componentId);
        }
      }
    },
    [hoveredComponentId, setHoveredComponentId, onComponentHover]
  );

  // 마우스 이탈 핸들러
  const handleMouseLeave = useCallback(() => {
    setHoveredComponentId(null);
    onComponentHover?.(null);
  }, [setHoveredComponentId, onComponentHover]);

  // 드롭 핸들러
  const handleDrop = useCallback(
    (e: React.DragEvent) => {
      e.preventDefault();

      // 드래그 데이터에서 소스 정보 가져오기
      const data = e.dataTransfer.getData('text/plain');
      const target = e.target as HTMLElement;
      const componentEl = target.closest('[data-editor-id]');

      if (componentEl && data) {
        let targetId = componentEl.getAttribute('data-editor-id');
        const targetPath = componentEl.getAttribute('data-editor-path');

        // 타겟이 Auto ID인 경우 경로를 통해 ID 확인
        if (targetId?.startsWith('auto_') && targetPath && layoutData?.components) {
          // 타입 단언: LayoutComponent[]는 ComponentDefinition[]과 호환됨
          const components = layoutData.components as (import('../../DynamicRenderer').ComponentDefinition | string)[];
          const component = findComponentByPath(components, targetPath);
          if (component?.id) {
            // 이미 ID가 있으면 그것 사용
            targetId = component.id;
          } else {
            // ID가 없으면 부여 필요 - 이 경우 moveComponentByPaths에서 처리
            logger.log('Target is auto ID, will use path-based move');
          }
        }

        // 드롭 위치 계산
        const rect = componentEl.getBoundingClientRect();
        const y = e.clientY - rect.top;
        const height = rect.height;

        let position: DropPosition;
        if (y < height * 0.25) {
          position = 'before';
        } else if (y > height * 0.75) {
          position = 'after';
        } else {
          position = 'inside';
        }

        // 소스가 경로 기반인지 확인
        const isSourcePathBased = data.startsWith('path:');
        const sourcePath = isSourcePathBased ? data.substring(5) : null;

        // 타겟도 Auto ID인 경우 (layoutData에 없는 ID)
        const isTargetAutoId = targetId?.startsWith('auto_');

        if (isSourcePathBased && targetPath && isTargetAutoId) {
          // 소스와 타겟 모두 경로 기반으로 처리 필요
          // 새로운 액션 필요: moveByPaths
          logger.log('Both source and target are path-based:', sourcePath, '->', targetPath);
          if (sourcePath && targetPath && sourcePath !== targetPath) {
            const success = moveComponentByPaths(sourcePath, targetPath, position);
            if (success) {
              onComponentDrop?.(targetId || '', position);
              logger.log('Component moved (both path-based):', sourcePath, '->', targetPath, position);
            }
          }
        } else if (isSourcePathBased && targetId && !isTargetAutoId) {
          // 소스는 경로, 타겟은 ID
          if (sourcePath) {
            const success = ensureComponentIdAndMove(sourcePath, targetId, position);
            if (success) {
              onComponentDrop?.(targetId, position);
              logger.log('Component moved (source path, target id):', sourcePath, '->', targetId, position);
            }
          }
        } else if (!isSourcePathBased && targetId && data !== targetId) {
          // 둘 다 ID 기반 (기존 로직)
          moveComponent(data, targetId, position);
          onComponentDrop?.(targetId, position);
          logger.log('Component moved (id-based):', data, '->', targetId, position);
        }
      }

      // 드래그 상태 초기화
      endDrag();
    },
    [layoutData, moveComponent, endDrag, onComponentDrop, ensureComponentIdAndMove, moveComponentByPaths]
  );

  // 자동 스크롤 시작
  const startAutoScroll = useCallback((container: HTMLElement, speed: number) => {
    if (scrollAnimationRef.current) return;

    const scroll = () => {
      container.scrollTop += speed;
      scrollAnimationRef.current = requestAnimationFrame(scroll);
    };
    scrollAnimationRef.current = requestAnimationFrame(scroll);
  }, []);

  // 자동 스크롤 중지
  const stopAutoScroll = useCallback(() => {
    if (scrollAnimationRef.current) {
      cancelAnimationFrame(scrollAnimationRef.current);
      scrollAnimationRef.current = null;
    }
  }, []);

  // 드래그 오버 핸들러 (줌 보정 + 자동 스크롤)
  const handleDragOver = useCallback(
    (e: React.DragEvent) => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';

      const container = containerRef.current;
      if (!container) return;

      // 자동 스크롤 로직
      const containerRect = container.getBoundingClientRect();
      const mouseY = e.clientY;

      if (mouseY - containerRect.top < SCROLL_THRESHOLD) {
        // 상단 근처 - 위로 스크롤
        startAutoScroll(container, -SCROLL_SPEED);
      } else if (containerRect.bottom - mouseY < SCROLL_THRESHOLD) {
        // 하단 근처 - 아래로 스크롤
        startAutoScroll(container, SCROLL_SPEED);
      } else {
        stopAutoScroll();
      }

      // 드롭 타겟 탐색
      const target = e.target as HTMLElement;
      const componentEl = target.closest('[data-editor-id]');

      if (componentEl) {
        const targetId = componentEl.getAttribute('data-editor-id');

        if (targetId) {
          // 드롭 위치 계산
          // getBoundingClientRect는 스케일 적용 후의 시각적 좌표를 반환하므로
          // 마우스 위치와 비교할 때 별도의 줌 보정 불필요
          const rect = componentEl.getBoundingClientRect();
          const y = e.clientY - rect.top;
          const height = rect.height;

          let position: DropPosition;
          if (y < height * 0.25) {
            position = 'before';
          } else if (y > height * 0.75) {
            position = 'after';
          } else {
            position = 'inside';
          }

          // 드롭 타겟 상태 업데이트 (시각적 피드백용)
          dragOver(targetId, position);
        }
      }
    },
    [dragOver, startAutoScroll, stopAutoScroll, SCROLL_THRESHOLD, SCROLL_SPEED]
  );

  // 렌더링 상태에 따른 컨텐츠
  const renderContent = () => {
    if (loadingState === 'loading') {
      return <LoadingState />;
    }

    if (error) {
      return <ErrorState message={error} />;
    }

    if (!layoutData) {
      return <EmptyState />;
    }

    // 엔진이 준비되지 않았으면 로딩 표시
    if (!engineReady || !registryRef.current || !bindingEngineRef.current ||
        !translationEngineRef.current || !actionDispatcherRef.current) {
      return (
        <div className="flex flex-col items-center justify-center h-full text-gray-500 dark:text-gray-400">
          <div className="animate-spin text-4xl mb-4">⏳</div>
          <p className="text-sm">렌더링 엔진 초기화 중...</p>
        </div>
      );
    }

    // DynamicRenderer를 사용하여 실제 레이아웃 렌더링
    // ResponsiveProvider에 overrideWidth 전달하여 디바이스별 반응형 시뮬레이션
    return (
      <TranslationProvider
        translationEngine={translationEngineRef.current}
        translationContext={translationContext}
      >
        <TransitionProvider>
          <ResponsiveProvider overrideWidth={previewWidth}>
            <SlotProvider>
              {layoutData.components.map((componentDef, index) => {
                if (typeof componentDef === 'string') return null;
                // LayoutComponent → ComponentDefinition 타입 단언
                // 실제 런타임 데이터에는 id, name 필드가 포함되어 있음
                const compDef = componentDef as unknown as ComponentDefinition;
                return (
                  <DynamicRenderer
                    key={compDef.id || `component-${index}`}
                    componentDef={compDef}
                    dataContext={editorDataContext}
                    translationContext={translationContext}
                    onDragStart={handleDynamicDragStart}
                    onDragEnd={handleDynamicDragEnd}
                    registry={registryRef.current!}
                    bindingEngine={bindingEngineRef.current!}
                    translationEngine={translationEngineRef.current!}
                    actionDispatcher={actionDispatcherRef.current!}
                    isEditMode={true}
                    onComponentSelect={handleDynamicSelect}
                    onComponentHover={handleDynamicHover}
                    isRootRenderer={true}
                    componentPath={`${index}`}
                  />
                );
              })}
            </SlotProvider>
          </ResponsiveProvider>
        </TransitionProvider>
      </TranslationProvider>
    );
  };

  return (
    <div className={`flex flex-col h-full bg-gray-50 dark:bg-gray-950 ${className}`}>
      {/* 상단 툴바 */}
      <div className="flex items-center justify-between px-4 py-2 border-b border-gray-200 dark:border-gray-800">
        {/* 디바이스 선택기 */}
        <DeviceSelector
          currentDevice={previewDevice}
          customWidth={customWidth}
          onDeviceChange={handleDeviceChange}
          onCustomWidthChange={handleCustomWidthChange}
        />

        {/* 줌 컨트롤 */}
        <ZoomControl zoom={zoom} onZoomChange={setZoom} />
      </div>

      {/* 캔버스 영역 */}
      <div
        ref={containerRef}
        className="flex-1 overflow-auto p-8 relative"
        onClick={handleComponentClick}
        onMouseMove={handleComponentHover}
        onMouseLeave={handleMouseLeave}
        onDrop={handleDrop}
        onDragOver={handleDragOver}
      >
        <RenderFrame width={previewWidth} zoom={zoom}>
          {renderContent()}
        </RenderFrame>

        {/* 선택/호버 오버레이 */}
        {showOverlay && layoutData && (
          <SelectionOverlay
            selectedId={selectedComponentId}
            hoveredId={hoveredComponentId}
            containerRef={containerRef}
            zoom={zoom}
          />
        )}

        {/* 드롭 인디케이터 */}
        {dropTargetId && (
          <DropIndicator
            targetId={dropTargetId}
            position={dropPosition}
            containerRef={containerRef}
          />
        )}
      </div>

      {/* 하단 상태 바 */}
      <div className="flex items-center justify-between px-4 py-1 text-xs border-t border-gray-200 dark:border-gray-800 bg-gray-100 dark:bg-gray-900 text-gray-500 dark:text-gray-400">
        <div className="flex items-center gap-4">
          <span>
            {previewDevice === 'custom' ? customWidth : DEVICE_CONFIGS[previewDevice].width}px
          </span>
          <span>Zoom: {zoom}%</span>
        </div>
        <div className="flex items-center gap-4">
          {selectedComponentId && (
            <span>
              선택됨: <span className="text-blue-500">{selectedComponentId}</span>
            </span>
          )}
          {layoutData && (
            <span>{layoutData.components.length}개 컴포넌트</span>
          )}
        </div>
      </div>
    </div>
  );
}

// ============================================================================
// Export
// ============================================================================

export default PreviewCanvas;
