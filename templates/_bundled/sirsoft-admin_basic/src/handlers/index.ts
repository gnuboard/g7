/**
 * 커스텀 액션 핸들러 Export
 *
 * 템플릿에서 사용하는 모든 커스텀 핸들러를 정의합니다.
 */

// 언어 관련 핸들러는 엔진 레벨(ActionDispatcher)에서 처리
// setLocale 핸들러는 ActionDispatcher에 빌트인으로 등록되어 있음
import { setThemeHandler, initThemeHandler } from './setThemeHandler';
import { scrollToSectionHandler } from './scrollToSectionHandler';
import { initMenuFromUrlHandler } from './initMenuFromUrlHandler';
import {
  initFilterVisibilityHandler,
  saveFilterVisibilityHandler,
  toggleFilterVisibilityHandler,
  resetFilterVisibilityHandler,
} from './filterVisibilityHandler';
import {
  saveMultilingualTagHandler,
  cancelMultilingualTagHandler,
  updateMultilingualTagValueHandler,
} from './multilingualTagHandler';
import { setDateRangeHandler } from './setDateRangeHandler';
import { toggleSidebarHandler, initSidebarHandler } from './sidebarHandler';
// 게시판 첨부 다운로드 핸들러 (토큰 동반 → 활동이력 행위자 기록, #413 item 58b)
import { downloadAttachmentHandler } from './downloadAttachment';
// 자산 URL 방식 자동 감지 (이슈 #486) — 브라우저에서 프로브 쌍을 던져 재판정
import { checkAssetUrlModeDriftHandler, detectAssetUrlModeHandler } from './detectAssetUrlModeHandler';
// 클립보드 복사 — 값이 화면에 없어도 복사할 수 있어야 하는 자리(웹 크론 호출 주소)가 있다
import { copyToClipboardHandler } from './copyToClipboardHandler';
// 코어 업데이트 웹 단계 실행 (#609 G6) — 서버가 한 요청에 한 단계만 수행하므로
// 반복 호출의 주체가 화면이다 (요청 경계가 곧 R25 방어선).
import {
  startCoreUpdateHandler,
  resumeCoreUpdateHandler,
  autoResumeCoreUpdateHandler,
  dismissCoreUpdateHandler,
  refreshCoreUpdateStatusHandler,
} from './coreUpdateHandler';

/**
 * 핸들러 맵
 *
 * 키: 핸들러 이름 (ActionDispatcher에 등록될 이름)
 * 값: 핸들러 함수
 *
 * 새로운 핸들러 추가 시 여기에만 등록하면 자동으로 ActionDispatcher에 등록됩니다.
 */
export const handlerMap = {
  // 언어: setLocale은 엔진 레벨(ActionDispatcher)에서 빌트인으로 처리
  detectAssetUrlMode: detectAssetUrlModeHandler,
  // 대시보드 진단 — 저장된 방식과 실제 환경을 대조해 불일치만 알린다 (저장하지 않음)
  checkAssetUrlModeDrift: checkAssetUrlModeDriftHandler,
  // 코어 업데이트 (W6) — 착수·이어받기·기록 정리·상태 갱신
  startCoreUpdate: startCoreUpdateHandler,
  resumeCoreUpdate: resumeCoreUpdateHandler,
  // 진행 블록이 마운트될 때 스스로 이어간다 — 창을 닫은 뒤에도 점검 모드에 멈추지 않게
  autoResumeCoreUpdate: autoResumeCoreUpdateHandler,
  dismissCoreUpdate: dismissCoreUpdateHandler,
  refreshCoreUpdateStatus: refreshCoreUpdateStatusHandler,
  setTheme: setThemeHandler,
  initTheme: initThemeHandler,
  scrollToSection: scrollToSectionHandler,
  initMenuFromUrl: initMenuFromUrlHandler,
  // 필터 가시성 핸들러
  initFilterVisibility: initFilterVisibilityHandler,
  saveFilterVisibility: saveFilterVisibilityHandler,
  toggleFilterVisibility: toggleFilterVisibilityHandler,
  resetFilterVisibility: resetFilterVisibilityHandler,
  // 다국어 태그 입력 핸들러 (MultilingualTagInput 외부 모달용)
  saveMultilingualTag: saveMultilingualTagHandler,
  cancelMultilingualTag: cancelMultilingualTagHandler,
  updateMultilingualTagValue: updateMultilingualTagValueHandler,
  // 날짜 범위 프리셋 핸들러
  setDateRange: setDateRangeHandler,
  // 데스크톱 사이드바 접기 핸들러
  toggleSidebar: toggleSidebarHandler,
  initSidebar: initSidebarHandler,
  // 게시판 첨부 다운로드 (토큰 동반 → 활동이력 행위자 기록, #413 item 58b)
  downloadAttachment: downloadAttachmentHandler,
  // 클립보드 복사 (가림 상태에서도 동작해야 하는 값 — 웹 크론 호출 주소)
  copyToClipboard: copyToClipboardHandler,
} as const;
