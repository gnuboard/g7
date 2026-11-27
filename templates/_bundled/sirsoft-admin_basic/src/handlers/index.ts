// e2e:allow 진행 중 번들 선택 창·확인 창 링크·미서명 경고는 새 판으로 올라가는 실제 코어 업데이트 도중에만 나타나 E2E 로 재현할 대상 릴리스가 없다 — 레이아웃·핸들러 단위 시험이 잠그고, 개선분이 든 시험 릴리스로 서버 재시험 때 브라우저 실측
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
// 목록 필터 배치 복원 — FilterVisibilitySelector 가 저장한 키를 코어 템플릿이 직접 읽는다
import { loadVisibleFiltersHandler } from './loadVisibleFiltersHandler';
// 회원 목록 확장 필터 값 채널(ext_filters[]) 조립
import { setUserFilterExtHandler } from './setUserFilterExtHandler';
// 방문자 통계 기간 프리셋 — 날짜 문자열(YYYY-MM-DD)을 돌려준다 (setDateRange 는 datetime 이라 통계 API 가 422)
import { setAnalyticsPeriodHandler } from './setAnalyticsPeriodHandler';
import { toggleSidebarHandler, initSidebarHandler } from './sidebarHandler';
// 게시판 첨부 다운로드 핸들러 (토큰 동반 → 활동이력 행위자 기록)
import { downloadAttachmentHandler } from './downloadAttachment';
import { downloadMarkupFileHandler } from './downloadMarkupFile';
import { copyMarkupToClipboardHandler } from './copyMarkupToClipboard';
// 자산 URL 방식 자동 감지 — 브라우저에서 프로브 쌍을 던져 재판정
import { checkAssetUrlModeDriftHandler, detectAssetUrlModeHandler } from './detectAssetUrlModeHandler';
// 클립보드 복사 — 값이 화면에 없어도 복사할 수 있어야 하는 자리(웹 크론 호출 주소)가 있다
import { copyToClipboardHandler } from './copyToClipboardHandler';
// 코어 업데이트 웹 단계 실행 — 서버가 한 요청에 한 단계만 수행하므로
// 반복 호출의 주체가 화면이다 (요청 경계가 곧 R25 방어선).
import {
  startCoreUpdateHandler,
  resumeCoreUpdateHandler,
  autoResumeCoreUpdateHandler,
  dismissCoreUpdateHandler,
  refreshCoreUpdateStatusHandler,
  submitCoreUpdateSelectionHandler,
} from './coreUpdateHandler';
import { rememberIdentityClaimHandler } from './identityLauncher';
// 확장 업데이트 창 — 진행 시계와 응답이 끊긴 뒤 서버 결과 확인 (7.2.1)
import {
  startExtensionUpdateClockHandler,
  stopExtensionUpdateClockHandler,
  reconcileExtensionUpdateHandler,
  captureExtensionUpdateFailureHandler,
} from './extensionUpdateHandler';

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
  // 코어 업데이트 (W6) — 착수·이어받기·기록 정리·상태 갱신
  startCoreUpdate: startCoreUpdateHandler,
  resumeCoreUpdate: resumeCoreUpdateHandler,
  // 진행 블록이 마운트될 때 스스로 이어간다 — 창을 닫은 뒤에도 점검 모드에 멈추지 않게
  autoResumeCoreUpdate: autoResumeCoreUpdateHandler,
  dismissCoreUpdate: dismissCoreUpdateHandler,
  refreshCoreUpdateStatus: refreshCoreUpdateStatusHandler,
  // 진행 중 선택(번들 배치·번들 업데이트 항목) 제출 뒤 이어서 진행 (7.2.1)
  submitCoreUpdateSelection: submitCoreUpdateSelectionHandler,
  // 확장 업데이트 창 — 진행 시계 켜기·끄기, 응답이 끊긴 뒤 서버에서 결과 확인 (7.2.1)
  startExtensionUpdateClock: startExtensionUpdateClockHandler,
  stopExtensionUpdateClock: stopExtensionUpdateClockHandler,
  reconcileExtensionUpdate: reconcileExtensionUpdateHandler,
  // 실패 응답 본문의 실패 결과(outcome·warnings·retry_command)를 전역으로 넘긴다
  captureExtensionUpdateFailure: captureExtensionUpdateFailureHandler,
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
  // 목록 필터 기본/상세 배치 복원
  loadVisibleFilters: loadVisibleFiltersHandler,
  // 회원 목록 확장 필터 값 조립
  setUserFilterExt: setUserFilterExtHandler,
  // 방문자 통계 기간 프리셋 — 통계 화면 기간 바 8버튼이 부른다
  setAnalyticsPeriod: setAnalyticsPeriodHandler,
  // 데스크톱 사이드바 접기 핸들러
  toggleSidebar: toggleSidebarHandler,
  initSidebar: initSidebarHandler,
  // 게시판 첨부 다운로드 (토큰 동반 → 활동이력 행위자 기록)
  downloadAttachment: downloadAttachmentHandler,
  // 클립보드 복사 (가림 상태에서도 동작해야 하는 값 — 웹 크론 호출 주소)
  copyToClipboard: copyToClipboardHandler,
  downloadMarkupFile: downloadMarkupFileHandler,
  copyMarkupToClipboard: copyMarkupToClipboardHandler,
} as const;
