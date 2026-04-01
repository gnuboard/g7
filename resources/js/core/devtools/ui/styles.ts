/**
 * G7 DevTools CSS 스타일
 *
 * 템플릿의 Tailwind CSS에 의존하지 않고 독립적으로 동작하는 인라인 CSS
 *
 * @module DevToolsStyles
 */

/**
 * DevTools 전용 인라인 CSS 스타일
 */
export const DEVTOOLS_STYLES = `
#g7-devtools-root {
  /* 기본 폰트 설정 */
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  font-size: 14px;
  line-height: 1.5;
}

#g7-devtools-root * {
  box-sizing: border-box;
}

/* 패널 기본 스타일 */
.g7dt-panel {
  position: fixed;
  background-color: #111827;
  color: #ffffff;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
  z-index: 9999;
  display: flex;
  flex-direction: column;
  border-radius: 8px;
  overflow: hidden;
}

.g7dt-panel.maximized {
  border-radius: 0;
}

/* 리사이즈 핸들 */
.g7dt-resize-handle {
  position: absolute;
  z-index: 10001;
  background: transparent;
  transition: background-color 0.15s ease;
}

.g7dt-resize-handle:hover {
  background-color: rgba(59, 130, 246, 0.5);
}

.g7dt-resize-corner {
  width: 12px;
  height: 12px;
}

.g7dt-resize-edge-h {
  height: 6px;
}

.g7dt-resize-edge-v {
  width: 6px;
}

.g7dt-resize-nw { top: 0; left: 0; cursor: nw-resize; }
.g7dt-resize-ne { top: 0; right: 0; cursor: ne-resize; }
.g7dt-resize-sw { bottom: 0; left: 0; cursor: sw-resize; }
.g7dt-resize-se { bottom: 0; right: 0; cursor: se-resize; }
.g7dt-resize-n { top: 0; left: 12px; right: 12px; cursor: n-resize; }
.g7dt-resize-s { bottom: 0; left: 12px; right: 12px; cursor: s-resize; }
.g7dt-resize-w { left: 0; top: 12px; bottom: 12px; cursor: w-resize; }
.g7dt-resize-e { right: 0; top: 12px; bottom: 12px; cursor: e-resize; }

/* 헤더 */
.g7dt-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 16px;
  background-color: #1f2937;
  border-bottom: 1px solid #374151;
  flex-shrink: 0;
  border-top-left-radius: 8px;
  border-top-right-radius: 8px;
  cursor: grab;
  user-select: none;
}

.g7dt-header.dragging {
  cursor: grabbing;
}

.g7dt-header-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.g7dt-header-title {
  font-weight: 700;
  font-size: 14px;
}

.g7dt-header-badge {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background-color: #374151;
}

.g7dt-header-badge.connected {
  background-color: #059669;
}

.g7dt-header-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.g7dt-btn {
  background: none;
  border: none;
  color: #9ca3af;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 4px;
  transition: all 0.15s ease;
  font-size: 14px;
}

.g7dt-btn:hover {
  color: #ffffff;
  background-color: rgba(255, 255, 255, 0.1);
}

.g7dt-btn-primary {
  background-color: #2563eb;
  color: #ffffff;
  font-size: 12px;
}

.g7dt-btn-primary:hover {
  background-color: #1d4ed8;
}

/* 탭 */
.g7dt-tabs {
  display: flex;
  border-bottom: 1px solid #374151;
  overflow-x: auto;
  flex-shrink: 0;
  background-color: #111827;
}

.g7dt-tabs::-webkit-scrollbar {
  height: 4px;
}

.g7dt-tabs::-webkit-scrollbar-track {
  background: transparent;
}

.g7dt-tabs::-webkit-scrollbar-thumb {
  background: #374151;
  border-radius: 2px;
}

.g7dt-tab {
  padding: 8px 16px;
  font-size: 13px;
  white-space: nowrap;
  background: none;
  border: none;
  color: #9ca3af;
  cursor: pointer;
  transition: all 0.15s ease;
  border-bottom: 2px solid transparent;
}

.g7dt-tab:hover {
  color: #ffffff;
  background-color: #1f2937;
}

.g7dt-tab.active {
  color: #ffffff;
  background-color: #1f2937;
  border-bottom-color: #3b82f6;
}

/* 콘텐츠 영역 */
.g7dt-content {
  flex: 1;
  overflow: hidden;
  padding: 16px;
  min-width: 0;
  min-height: 0;
  background-color: #111827;
  display: flex;
  flex-direction: column;
}

.g7dt-content::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

.g7dt-content::-webkit-scrollbar-track {
  background: #1f2937;
}

.g7dt-content::-webkit-scrollbar-thumb {
  background: #4b5563;
  border-radius: 4px;
}

.g7dt-content::-webkit-scrollbar-thumb:hover {
  background: #6b7280;
}

/* 푸터 */
.g7dt-footer {
  padding: 8px 16px;
  background-color: #1f2937;
  font-size: 11px;
  color: #6b7280;
  display: flex;
  justify-content: space-between;
  border-top: 1px solid #374151;
  flex-shrink: 0;
  border-bottom-left-radius: 8px;
  border-bottom-right-radius: 8px;
}

/* 플로팅 버튼 */
.g7dt-floating-btn {
  position: fixed;
  bottom: 16px;
  right: 16px;
  background-color: #2563eb;
  color: #ffffff;
  padding: 12px;
  border-radius: 50%;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
  z-index: 9999;
  border: none;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.g7dt-floating-btn:hover {
  background-color: #1d4ed8;
}

.g7dt-floating-btn svg {
  width: 24px;
  height: 24px;
}

/* 최소화 상태 */
.g7dt-minimized {
  position: fixed;
  bottom: 0;
  right: 0;
  background-color: #111827;
  color: #ffffff;
  box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.1);
  z-index: 9999;
  border-top-left-radius: 8px;
}

.g7dt-minimized-content {
  display: flex;
  align-items: center;
  padding: 8px 16px;
  gap: 8px;
}

/* 비활성화 알림 */
.g7dt-disabled-notice {
  position: fixed;
  bottom: 16px;
  right: 16px;
  background-color: #111827;
  color: #ffffff;
  padding: 16px;
  border-radius: 8px;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
  z-index: 9999;
  max-width: 320px;
}

/* 유틸리티 */
.g7dt-text-yellow { color: #facc15; }
.g7dt-text-cyan { color: #22d3ee; }
.g7dt-text-pink { color: #f472b6; }
.g7dt-text-orange { color: #fb923c; }
.g7dt-text-blue { color: #60a5fa; }
.g7dt-text-purple { color: #c084fc; }
.g7dt-text-green { color: #4ade80; }
.g7dt-text-red { color: #f87171; }
.g7dt-text-gray { color: #9ca3af; }
.g7dt-text-gray-dark { color: #6b7280; }
.g7dt-text-white { color: #ffffff; }

.g7dt-bg-gray-700 { background-color: #374151; }
.g7dt-bg-gray-800 { background-color: #1f2937; }
.g7dt-bg-green-600 { background-color: #059669; }
.g7dt-bg-red-600 { background-color: #dc2626; }
.g7dt-bg-blue-600 { background-color: #2563eb; }
.g7dt-bg-yellow-600 { background-color: #ca8a04; }

.g7dt-font-bold { font-weight: 700; }
.g7dt-font-mono { font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace; }
.g7dt-text-xs { font-size: 11px; }
.g7dt-text-sm { font-size: 13px; }

.g7dt-flex { display: flex; }
.g7dt-flex-col { flex-direction: column; }
.g7dt-items-center { align-items: center; }
.g7dt-justify-between { justify-content: space-between; }
.g7dt-gap-1 { gap: 4px; }
.g7dt-gap-2 { gap: 8px; }
.g7dt-gap-3 { gap: 12px; }

.g7dt-ml-1 { margin-left: 4px; }
.g7dt-ml-2 { margin-left: 8px; }
.g7dt-ml-4 { margin-left: 16px; }
.g7dt-mt-1 { margin-top: 4px; }
.g7dt-mt-2 { margin-top: 8px; }
.g7dt-mb-1 { margin-bottom: 4px; }
.g7dt-mb-2 { margin-bottom: 8px; }
.g7dt-p-1 { padding: 4px; }
.g7dt-p-2 { padding: 8px; }
.g7dt-px-1 { padding-left: 4px; padding-right: 4px; }
.g7dt-px-2 { padding-left: 8px; padding-right: 8px; }
.g7dt-py-1 { padding-top: 4px; padding-bottom: 4px; }

.g7dt-rounded { border-radius: 4px; }
.g7dt-rounded-lg { border-radius: 8px; }

.g7dt-border { border: 1px solid #374151; }
.g7dt-border-l { border-left: 1px solid #374151; }

.g7dt-cursor-pointer { cursor: pointer; }
.g7dt-select-none { user-select: none; }

.g7dt-overflow-auto { overflow: auto; }
.g7dt-overflow-hidden { overflow: hidden; }
.g7dt-whitespace-nowrap { white-space: nowrap; }
.g7dt-truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.g7dt-w-4 { width: 16px; }
.g7dt-w-full { width: 100%; }
.g7dt-h-6 { height: 24px; }
.g7dt-w-6 { width: 24px; }

.g7dt-ring-highlight {
  box-shadow: 0 0 0 1px rgba(250, 204, 21, 0.5);
  background-color: rgba(113, 63, 18, 0.3);
}

/* 입력 필드 */
.g7dt-input {
  background-color: #1f2937;
  border: 1px solid #374151;
  border-radius: 4px;
  padding: 6px 12px;
  color: #ffffff;
  font-size: 13px;
  outline: none;
  transition: border-color 0.15s ease;
}

.g7dt-input:focus {
  border-color: #3b82f6;
}

.g7dt-input::placeholder {
  color: #6b7280;
}

/* 트리 노드 호버 */
.g7dt-tree-node {
  padding: 2px 4px;
  border-radius: 4px;
  cursor: pointer;
  transition: background-color 0.1s ease;
}

.g7dt-tree-node:hover {
  background-color: rgba(55, 65, 81, 0.5);
}

/* 리사이즈 중 하이라이트 */
.g7dt-resizing .g7dt-resize-handle {
  background-color: rgba(59, 130, 246, 0.3);
}

/* 액션 아이템 */
.g7dt-action-item {
  padding: 8px;
  border-radius: 4px;
  border: 1px solid #374151;
  margin-bottom: 8px;
  background-color: #1f2937;
}

.g7dt-action-item:hover {
  background-color: #283548;
}

/* 액션 아이템 상태별 배경색 */
.g7dt-action-item.g7dt-status-success {
  background-color: rgba(34, 197, 94, 0.15);
  border-color: rgba(34, 197, 94, 0.4);
}

.g7dt-action-item.g7dt-status-error {
  background-color: rgba(239, 68, 68, 0.15);
  border-color: rgba(239, 68, 68, 0.4);
}

.g7dt-action-item.g7dt-status-started {
  background-color: rgba(59, 130, 246, 0.15);
  border-color: rgba(59, 130, 246, 0.4);
}

.g7dt-action-item.g7dt-status-default {
  background-color: #1f2937;
  border-color: #374151;
}

/* 액션 상세 영역 (토글 펼침) */
.g7dt-action-details {
  margin-top: 8px;
  padding: 8px;
  background-color: rgba(0, 0, 0, 0.2);
  border-radius: 4px;
}

/* 상태 뱃지 */
.g7dt-status-badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 9999px;
  font-size: 11px;
  font-weight: 500;
  margin-left: 8px;
}

.g7dt-status-success { background-color: rgba(34, 197, 94, 0.3); color: #4ade80; }
.g7dt-status-error { background-color: rgba(239, 68, 68, 0.3); color: #f87171; }
.g7dt-status-warning { background-color: rgba(234, 179, 8, 0.3); color: #facc15; }
.g7dt-status-pending { background-color: rgba(59, 130, 246, 0.3); color: #60a5fa; }
.g7dt-status-info { background-color: rgba(107, 114, 128, 0.3); color: #9ca3af; }
.g7dt-status-started { background-color: rgba(59, 130, 246, 0.3); color: #60a5fa; }
.g7dt-status-default { background-color: rgba(107, 114, 128, 0.3); color: #9ca3af; }

/* 코드 블록 */
.g7dt-code {
  font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
  font-size: 12px;
  background-color: #1f2937;
  padding: 2px 6px;
  border-radius: 4px;
}

/* 선택 불가 */
.g7dt-noselect {
  -webkit-user-select: none;
  -moz-user-select: none;
  -ms-user-select: none;
  user-select: none;
}

/* 추가 유틸리티 클래스 - flex column으로 변경하여 자식이 늘어나도록 */
.g7dt-space-y-4 {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  gap: 16px;
  overflow-y: auto;
}

.g7dt-space-y-2 {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

/* 스크롤 리스트 내부용 - flex column 없이 gap만 */
.g7dt-space-y-2-flat {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.g7dt-space-y-1 {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.g7dt-text-muted { color: #6b7280; }
.g7dt-text-center { text-align: center; }
.g7dt-text-xxs { font-size: 10px; }
.g7dt-text-lg { font-size: 18px; }

.g7dt-flex-1 { flex: 1; }
.g7dt-flex-shrink-0 { flex-shrink: 0; }
.g7dt-flex-wrap { flex-wrap: wrap; }
.g7dt-items-start { align-items: flex-start; }
.g7dt-justify-center { justify-content: center; }
.g7dt-mb-3 { margin-bottom: 12px; }
.g7dt-mt-3 { margin-top: 12px; }
.g7dt-pt-3 { padding-top: 12px; }

.g7dt-mono { font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace; }
.g7dt-uppercase { text-transform: uppercase; }
.g7dt-whitespace-pre-wrap { white-space: pre-wrap; }

.g7dt-w-32 { width: 128px; }
.g7dt-max-h-16 { max-height: 64px; }
.g7dt-max-h-40 { max-height: 160px; }
.g7dt-max-h-60 { max-height: 240px; }
.g7dt-overflow-auto { overflow: auto; }
.g7dt-border-t { border-top: 1px solid #374151; }

/* 그리드 레이아웃 */
.g7dt-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); }
.g7dt-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.g7dt-stats-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.g7dt-stats-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.g7dt-stats-grid-5 { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; }
.g7dt-stats-grid-6 { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; }

/* 카드 및 리스트 스타일 */
.g7dt-card {
  background-color: #1f2937;
  padding: 12px;
  border-radius: 4px;
  border: 1px solid #374151;
}

.g7dt-stat-card {
  background-color: #1f2937;
  padding: 12px;
  border-radius: 4px;
  text-align: center;
  border: 1px solid #374151;
}

.g7dt-stat-card-sm {
  background-color: #1f2937;
  padding: 8px;
  border-radius: 4px;
  text-align: center;
  border: 1px solid #374151;
}

.g7dt-stat-value {
  font-size: 20px;
  font-weight: 700;
}

.g7dt-stat-value-sm {
  font-size: 18px;
  font-weight: 700;
}

.g7dt-stat-label {
  font-size: 11px;
  color: #6b7280;
}

/* 스크롤 리스트 - flex로 남은 공간 채움 */
.g7dt-scroll-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.g7dt-scroll-list > * + * { margin-top: 4px; }

.g7dt-scroll-list-sm {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.g7dt-scroll-list-sm > * + * { margin-top: 4px; }

.g7dt-scroll-list-lg {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.g7dt-scroll-list-lg > * + * { margin-top: 4px; }

.g7dt-scroll-list-xs {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.g7dt-scroll-list-xs > * + * { margin-top: 4px; }

/* 탭 콘텐츠 래퍼 - flex column으로 자식이 늘어나도록 */
.g7dt-tab-content {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

/* 섹션 래퍼 - flex로 스크롤 리스트가 늘어나도록 */
.g7dt-section {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
}

/* 빈 상태 */
.g7dt-empty-state {
  color: #6b7280;
  font-size: 13px;
  text-align: center;
  padding: 16px;
}

/* 버튼 스타일 */
.g7dt-btn-secondary {
  background-color: #374151;
  color: #ffffff;
  font-size: 12px;
  padding: 4px 12px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.g7dt-btn-secondary:hover {
  background-color: #4b5563;
}

.g7dt-btn-danger {
  background-color: #b91c1c;
  color: #ffffff;
  font-size: 12px;
  padding: 4px 12px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.g7dt-btn-danger:hover {
  background-color: #dc2626;
}

.g7dt-close-btn {
  color: #9ca3af;
  font-size: 12px;
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px;
}

.g7dt-close-btn:hover {
  color: #ffffff;
}

.g7dt-filter-btn {
  font-size: 12px;
  padding: 4px 12px;
  border-radius: 4px;
  background-color: #1f2937;
  color: #9ca3af;
  border: none;
  cursor: pointer;
  transition: all 0.15s ease;
}

.g7dt-filter-btn:hover {
  background-color: #374151;
}

.g7dt-filter-btn-active,
.g7dt-filter-btn-active-yellow {
  background-color: #ca8a04;
  color: #ffffff;
}

.g7dt-prefix-btn {
  font-size: 12px;
  padding: 4px 8px;
  border-radius: 4px;
  background-color: #1f2937;
  border: 1px solid #374151;
  color: #ffffff;
  cursor: pointer;
  transition: all 0.15s ease;
}

.g7dt-prefix-btn:hover {
  border-color: #4b5563;
}

.g7dt-prefix-btn-active {
  background-color: #2563eb;
  border-color: #1d4ed8;
  color: #ffffff;
}

/* Select 스타일 */
.g7dt-select {
  background-color: #1f2937;
  border: 1px solid #374151;
  border-radius: 4px;
  padding: 4px 12px;
  color: #ffffff;
  font-size: 13px;
  cursor: pointer;
}

/* 리스트 아이템 */
.g7dt-list-item {
  background-color: #1f2937;
  padding: 8px;
  border-radius: 4px;
  font-size: 12px;
  border: 1px solid #374151;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.g7dt-list-item:hover {
  background-color: #374151;
}

/* 렌더 로그 아이템 */
.g7dt-render-log-item {
  background-color: #1f2937;
  padding: 8px;
  border-radius: 4px;
  font-size: 12px;
  border: 1px solid #374151;
  cursor: pointer;
  transition: all 0.15s ease;
}

.g7dt-render-log-item:hover {
  border-color: #4b5563;
}

.g7dt-render-log-item.g7dt-selected {
  border-color: #2563eb;
  background-color: rgba(37, 99, 235, 0.2);
}

.g7dt-render-log-item.g7dt-warning {
  border-color: rgba(161, 98, 7, 0.5);
}

.g7dt-render-log-item.g7dt-warning:hover {
  border-color: #ca8a04;
}

.g7dt-render-comp-item {
  background-color: #111827;
  padding: 8px;
  border-radius: 4px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

/* 경고 박스 */
.g7dt-warning-box {
  background-color: rgba(161, 98, 7, 0.2);
  padding: 8px;
  border-radius: 4px;
  border: 1px solid rgba(161, 98, 7, 0.5);
}

.g7dt-list {
  list-style-type: disc;
  padding-left: 16px;
}

/* 태그 */
.g7dt-tag {
  font-size: 12px;
  background-color: #1f2937;
  padding: 4px 8px;
  border-radius: 4px;
  border: 1px solid #374151;
}

.g7dt-role-tag {
  background-color: rgba(37, 99, 235, 0.3);
  color: #93c5fd;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
}

.g7dt-perm-tag {
  background-color: rgba(147, 51, 234, 0.3);
  color: #d8b4fe;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
}

/* Pre 스타일 */
.g7dt-pre {
  margin-top: 4px;
  padding: 8px;
  background-color: #111827;
  border-radius: 4px;
  overflow: auto;
  max-height: 80px;
  font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
  font-size: 12px;
}

.g7dt-pre-stack {
  margin-top: 4px;
  padding: 8px;
  background-color: #111827;
  border-radius: 4px;
  overflow: auto;
  max-height: 128px;
  font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
  font-size: 10px;
}

/* 인증 이벤트 아이템 */
.g7dt-auth-event-item {
  background-color: #1f2937;
  padding: 8px;
  border-radius: 4px;
  font-size: 12px;
  border: 1px solid #374151;
  cursor: pointer;
  transition: all 0.15s ease;
}

.g7dt-auth-event-item:hover {
  border-color: #4b5563;
}

.g7dt-auth-event-item.g7dt-selected {
  border-color: #2563eb;
  background-color: rgba(37, 99, 235, 0.2);
}

.g7dt-auth-event-item.g7dt-error {
  border-color: rgba(185, 28, 28, 0.5);
}

.g7dt-auth-event-item.g7dt-error:hover {
  border-color: #dc2626;
}

/* 헤더 아이템 */
.g7dt-header-item {
  background-color: #1f2937;
  padding: 8px;
  border-radius: 4px;
  font-size: 12px;
  border: 1px solid #374151;
  display: flex;
  justify-content: space-between;
}

/* 로그 아이템 */
.g7dt-log-item {
  padding: 8px;
  border-radius: 4px;
  font-size: 12px;
  border: 1px solid;
  cursor: pointer;
  transition: all 0.15s ease;
}

.g7dt-log-item.g7dt-selected {
  border-color: #2563eb;
  background-color: rgba(37, 99, 235, 0.2);
}

.g7dt-log-error {
  background-color: rgba(185, 28, 28, 0.3);
  border-color: #b91c1c;
}

.g7dt-log-warn {
  background-color: rgba(161, 98, 7, 0.3);
  border-color: #a16207;
}

.g7dt-log-info {
  background-color: rgba(37, 99, 235, 0.3);
  border-color: #2563eb;
}

.g7dt-log-debug {
  background-color: #1f2937;
  border-color: #4b5563;
}

.g7dt-log-default {
  background-color: #1f2937;
  border-color: #374151;
}

/* 스타일 이슈 아이템 */
.g7dt-style-issue-item {
  background-color: #1f2937;
  padding: 8px;
  border-radius: 4px;
  font-size: 12px;
  border: 1px solid;
  cursor: pointer;
  transition: all 0.15s ease;
}

.g7dt-style-issue-item:hover {
  border-color: #4b5563;
}

.g7dt-style-issue-item.g7dt-selected {
  border-color: #2563eb;
  background-color: rgba(37, 99, 235, 0.2);
}

.g7dt-severity-error { border-color: rgba(185, 28, 28, 0.5); }
.g7dt-severity-warning { border-color: rgba(161, 98, 7, 0.5); }
.g7dt-severity-info { border-color: rgba(37, 99, 235, 0.5); }

/* 뱃지 */
.g7dt-badge-error {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background-color: rgba(185, 28, 28, 0.5);
  color: #fca5a5;
}

.g7dt-badge-warning {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background-color: rgba(161, 98, 7, 0.5);
  color: #fcd34d;
}

.g7dt-badge-info {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background-color: rgba(37, 99, 235, 0.5);
  color: #93c5fd;
}

.g7dt-badge-success {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background-color: rgba(34, 197, 94, 0.5);
  color: #86efac;
}

.g7dt-badge-gray {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background-color: rgba(107, 114, 128, 0.5);
  color: #d1d5db;
}

.g7dt-badge-blue {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background-color: rgba(59, 130, 246, 0.5);
  color: #93c5fd;
}

.g7dt-badge-purple {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background-color: rgba(147, 51, 234, 0.5);
  color: #d8b4fe;
}

/* 미니 뱃지 */
.g7dt-mini-badge {
  font-size: 10px;
  padding: 1px 6px;
  border-radius: 4px;
}

/* 데이터소스 그리드 */
.g7dt-ds-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}

.g7dt-ds-item {
  background-color: #1f2937;
  padding: 12px;
  border-radius: 8px;
  border: 1px solid #374151;
  cursor: pointer;
  transition: all 0.15s ease;
}

.g7dt-ds-item:hover {
  border-color: #4b5563;
  background-color: #283548;
}

.g7dt-ds-selected {
  border-color: #2563eb;
  background-color: rgba(37, 99, 235, 0.15);
}

/* 액션 리스트 - flex 부모에서 늘어남 */
.g7dt-action-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.g7dt-action-list > * + * { margin-top: 8px; }

/* 이벤트 리스트 - flex 부모에서 늘어남 */
.g7dt-event-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.g7dt-event-list > * + * { margin-top: 4px; }

/* 핸들러 리스트 - flex 부모에서 늘어남 */
.g7dt-handler-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.g7dt-handler-list > * + * { margin-top: 4px; }

/* 표현식 리스트 - flex 부모에서 늘어남 */
.g7dt-expr-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.g7dt-expr-list > * + * { margin-top: 4px; }

.g7dt-expr-item {
  background-color: #1f2937;
  padding: 8px;
  border-radius: 4px;
  font-size: 12px;
  border: 1px solid #374151;
  cursor: pointer;
  transition: all 0.15s ease;
}

.g7dt-expr-item:hover {
  border-color: #4b5563;
}

.g7dt-expr-selected {
  border-color: #2563eb;
  background-color: rgba(37, 99, 235, 0.2);
}

.g7dt-expr-warning {
  border-color: rgba(161, 98, 7, 0.5);
}

.g7dt-expr-warning:hover {
  border-color: #ca8a04;
}

/* 조건 아이템 */
.g7dt-condition-item {
  background-color: #1f2937;
  padding: 8px;
  border-radius: 4px;
  font-size: 12px;
  border: 1px solid #374151;
}

.g7dt-condition-true {
  border-color: rgba(34, 197, 94, 0.5);
}

.g7dt-condition-false {
  border-color: rgba(185, 28, 28, 0.5);
}

/* 키 태그 */
.g7dt-key-tag {
  font-size: 11px;
  background-color: #374151;
  color: #d1d5db;
  padding: 2px 6px;
  border-radius: 4px;
}

/* 의존성 태그 */
.g7dt-dep-tag {
  font-size: 11px;
  background-color: rgba(34, 197, 94, 0.2);
  color: #86efac;
  padding: 2px 6px;
  border-radius: 4px;
}

/* 타입 태그 */
.g7dt-type-tag {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background-color: #374151;
}

/* 태그 블루 */
.g7dt-tag-blue {
  background-color: rgba(59, 130, 246, 0.3);
  color: #93c5fd;
}

/* 경고 태그 */
.g7dt-warning-tag {
  font-size: 11px;
  background-color: rgba(161, 98, 7, 0.3);
  color: #fcd34d;
  padding: 2px 6px;
  border-radius: 4px;
}

/* 신뢰도 뱃지 */
.g7dt-confidence-badge {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background-color: #374151;
}

/* 증상 버튼 */
.g7dt-symptom-btn {
  font-size: 12px;
  padding: 6px 12px;
  border-radius: 4px;
  background-color: #1f2937;
  border: 1px solid #374151;
  color: #9ca3af;
  cursor: pointer;
  transition: all 0.15s ease;
}

.g7dt-symptom-btn:hover {
  border-color: #4b5563;
  color: #ffffff;
}

.g7dt-symptom-active {
  background-color: #2563eb;
  border-color: #1d4ed8;
  color: #ffffff;
}

/* 버튼 추가 스타일 */
.g7dt-btn-close {
  color: #9ca3af;
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px;
  font-size: 14px;
  transition: color 0.15s ease;
}

.g7dt-btn-close:hover {
  color: #ffffff;
}

.g7dt-btn-danger-sm {
  background-color: #b91c1c;
  color: #ffffff;
  font-size: 11px;
  padding: 2px 8px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.g7dt-btn-danger-sm:hover {
  background-color: #dc2626;
}

.g7dt-btn-disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.g7dt-btn-text {
  background: none;
  border: none;
  color: #60a5fa;
  cursor: pointer;
  font-size: 12px;
  padding: 0;
}

.g7dt-btn-text:hover {
  text-decoration: underline;
}

/* 필터 활성화 */
.g7dt-filter-active {
  background-color: #2563eb;
  color: #ffffff;
}

/* 체크박스 */
.g7dt-checkbox {
  width: 16px;
  height: 16px;
  cursor: pointer;
}

.g7dt-checkbox-label {
  font-size: 13px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
}

/* 카드 에러 */
.g7dt-card-error {
  background-color: #1f2937;
  padding: 12px;
  border-radius: 4px;
  border: 1px solid rgba(185, 28, 28, 0.5);
}

/* 에러 박스 */
.g7dt-error-box {
  background-color: rgba(185, 28, 28, 0.2);
  padding: 8px;
  border-radius: 4px;
  border: 1px solid rgba(185, 28, 28, 0.5);
}

.g7dt-error-inline {
  color: #f87171;
  font-size: 12px;
}

/* 경고 박스 라이트 */
.g7dt-warning-box-light {
  background-color: rgba(161, 98, 7, 0.15);
  padding: 8px;
  border-radius: 4px;
  border: 1px solid rgba(161, 98, 7, 0.3);
}

/* 경고 카드 (빨간 배경) */
.g7dt-warning-card {
  background-color: rgba(185, 28, 28, 0.4);
  padding: 12px;
  border-radius: 4px;
  border: 1px solid rgba(185, 28, 28, 0.6);
}

/* 빈 메시지 */
.g7dt-empty-message {
  color: #6b7280;
  font-size: 13px;
  text-align: center;
}

/* 요약 (토글 헤더) - 투명 배경 */
.g7dt-summary {
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  padding: 4px 0;
  color: #9ca3af;
  list-style: none;
}

.g7dt-summary::-webkit-details-marker {
  display: none;
}

.g7dt-summary::before {
  content: '▶ ';
  font-size: 10px;
}

details[open] > .g7dt-summary::before {
  content: '▼ ';
}

/* 상세 영역 - 배경 없음, 펼쳐진 내용만 스타일 */
.g7dt-details {
  margin-top: 4px;
}

/* 펼쳐진 내용에만 배경색 적용 - 스크롤 제거 */
.g7dt-details > .g7dt-pre {
  background-color: rgba(0, 0, 0, 0.3);
  border-radius: 4px;
  max-height: none;
  overflow: visible;
}

/* 프로그레스 바 */
.g7dt-progress-bar {
  width: 100%;
  height: 8px;
  background-color: #374151;
  border-radius: 4px;
  overflow: hidden;
}

.g7dt-progress-fill {
  height: 100%;
  background-color: #2563eb;
  transition: width 0.3s ease;
}

/* 대기 중 요청 */
.g7dt-pending-request {
  background-color: rgba(59, 130, 246, 0.2);
  border: 1px solid rgba(59, 130, 246, 0.5);
  padding: 8px;
  border-radius: 4px;
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.7; }
}

/* 추가 유틸리티 */
.g7dt-space-y-3 > * + * { margin-top: 12px; }
.g7dt-space-y-6 > * + * { margin-top: 24px; }

.g7dt-mr-2 { margin-right: 8px; }
.g7dt-pl-2 { padding-left: 8px; }
.g7dt-py-4 { padding-top: 16px; padding-bottom: 16px; }
.g7dt-py-8 { padding-top: 32px; padding-bottom: 32px; }

.g7dt-col-span-2 { grid-column: span 2; }
.g7dt-min-w-0 { min-width: 0; }
.g7dt-items-end { align-items: flex-end; }

.g7dt-text-lightgray { color: #d1d5db; }
.g7dt-text-darkgray { color: #4b5563; }

.g7dt-truncate-60 {
  max-width: 60%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.g7dt-stat-label-xs {
  font-size: 10px;
  color: #6b7280;
}

.g7dt-stat-value-lg {
  font-size: 24px;
  font-weight: 700;
}

.g7dt-status-default { background-color: rgba(107, 114, 128, 0.2); color: #9ca3af; }
.g7dt-status-started { background-color: rgba(59, 130, 246, 0.2); color: #60a5fa; }

/* Layout 탭 전용 스타일 */
.g7dt-btn-small {
  padding: 4px 8px;
  font-size: 11px;
  border-radius: 4px;
  background-color: #374151;
  color: #9ca3af;
  border: none;
  cursor: pointer;
  transition: all 0.15s ease;
}

.g7dt-btn-small:hover {
  background-color: #4b5563;
  color: #ffffff;
}

.g7dt-section-btn {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 12px;
  background-color: #1f2937;
  border: 1px solid #374151;
  border-radius: 4px;
  color: #d1d5db;
  cursor: pointer;
  font-size: 12px;
  transition: all 0.15s ease;
}

.g7dt-section-btn:hover {
  background-color: #374151;
  border-color: #4b5563;
}

.g7dt-section-btn.g7dt-selected {
  background-color: #1e40af;
  border-color: #3b82f6;
  color: #ffffff;
}

.g7dt-history-item {
  padding: 8px;
  background-color: #1f2937;
  border-radius: 4px;
  border: 1px solid #374151;
}

.g7dt-history-item:hover {
  border-color: #4b5563;
}

.g7dt-max-h-96 {
  max-height: 24rem;
}

.g7dt-overflow-auto {
  overflow: auto;
}

.g7dt-list-disc {
  list-style-type: disc;
}

.g7dt-cursor-pointer {
  cursor: pointer;
}
`;

/**
 * DevTools 스타일을 DOM에 주입합니다.
 */
export const injectDevToolsStyles = (): void => {
    const styleId = 'g7-devtools-styles';
    if (document.getElementById(styleId)) return;

    const styleElement = document.createElement('style');
    styleElement.id = styleId;
    styleElement.textContent = DEVTOOLS_STYLES;
    document.head.appendChild(styleElement);
};
