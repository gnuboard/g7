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
// 목록 필터 배치 복원 — FilterVisibilitySelector 가 저장한 키를 코어 템플릿이 직접 읽는다 (#705)
import { loadVisibleFiltersHandler } from './loadVisibleFiltersHandler';
// 회원 목록 확장 필터 값 채널(ext_filters[]) 조립 (#705)
import { setUserFilterExtHandler } from './setUserFilterExtHandler';
// 방문자 통계 기간 프리셋 — 날짜 문자열(YYYY-MM-DD)을 돌려준다 (setDateRange 는 datetime 이라 통계 API 가 422)
import { setAnalyticsPeriodHandler } from './setAnalyticsPeriodHandler';
import { toggleSidebarHandler, initSidebarHandler } from './sidebarHandler';
// 게시판 첨부 다운로드 핸들러 (토큰 동반 → 활동이력 행위자 기록, #413 item 58b)
import { downloadAttachmentHandler } from './downloadAttachment';
import { downloadMarkupFileHandler } from './downloadMarkupFile';
import { copyMarkupToClipboardHandler } from './copyMarkupToClipboard';
// 자산 URL 방식 자동 감지 (이슈 #486) — 브라우저에서 프로브 쌍을 던져 재판정
import { checkAssetUrlModeDriftHandler, detectAssetUrlModeHandler } from './detectAssetUrlModeHandler';
import { rememberIdentityClaimHandler } from './identityLauncher';

/**
 * 핸들러 맵
 *
 * 키: 핸들러 이름 (ActionDispatcher에 등록될 이름)
 * 값: 핸들러 함수
 *
 * 새로운 핸들러 추가 시 여기에만 등록하면 자동으로 ActionDispatcher에 등록됩니다.
 */
export const handlerMap = {
  // 본인인증 링크 방식 — [다시 보내기]가 받은 새 건의 일회용 비밀값을 처리기에 넘긴다(전역 상태에 두지 않는다).
  rememberIdentityClaim: rememberIdentityClaimHandler,
  // 언어: setLocale은 엔진 레벨(ActionDispatcher)에서 빌트인으로 처리
  detectAssetUrlMode: detectAssetUrlModeHandler,
  // 대시보드 진단 — 저장된 방식과 실제 환경을 대조해 불일치만 알린다 (저장하지 않음)
  checkAssetUrlModeDrift: checkAssetUrlModeDriftHandler,
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
  // 날짜 범위 프리셋 핸들러 (format: 'date' 면 YYYY-MM-DD)
  setDateRange: setDateRangeHandler,
  // 목록 필터 기본/상세 배치 복원 (#705)
  loadVisibleFilters: loadVisibleFiltersHandler,
  // 회원 목록 확장 필터 값 조립 (#705)
  setUserFilterExt: setUserFilterExtHandler,
  // 방문자 통계 기간 프리셋 (#682) — 통계 화면 기간 바 8버튼이 부른다
  setAnalyticsPeriod: setAnalyticsPeriodHandler,
  // 데스크톱 사이드바 접기 핸들러
  toggleSidebar: toggleSidebarHandler,
  initSidebar: initSidebarHandler,
  // 게시판 첨부 다운로드 (토큰 동반 → 활동이력 행위자 기록, #413 item 58b)
  downloadAttachment: downloadAttachmentHandler,
  downloadMarkupFile: downloadMarkupFileHandler,
  copyMarkupToClipboard: copyMarkupToClipboardHandler,
} as const;
