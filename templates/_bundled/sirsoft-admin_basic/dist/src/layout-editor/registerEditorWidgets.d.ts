/**
 * registerEditorWidgets.ts — sirsoft-admin_basic 편집기 커스텀 위젯 등록
 *
 * 템플릿 부트스트랩(initTemplate)에서 호출. `G7Core.layoutEditor` 는 코어 예약 접수함
 * (stub)이 메인 번들에서 항상 노출하므로, 편집기 셸 로드 전에 호출해도 등록이 큐에
 * 보존되었다가 편집기 로드 시 flush 된다(타이밍 안전).
 *
 * 등록 위젯:
 *  - `icon-picker` — Font Awesome 검색 그리드(템플릿 소유 UI, 라이브러리 종속).
 *
 * 등록 캔버스 오버레이:
 *  - `tabnav` — TabNavigation tabs 배열 캔버스 인플레이스(+추가/✕삭제/◀▶이동). 템플릿이
 *    직접 등록해 확장점이 실동작함을 실증. 속성 패널 ArrayItemsEditor 와 동일 패치 경로 SSoT.
 */
/**
 * 편집기 커스텀 위젯을 코어 레지스트리에 등록한다. 멱등 — `G7Core.layoutEditor` 부재(코어
 * 미초기화)면 no-op(다음 호출에서 재시도하거나 stub 이 보존).
 */
export declare function registerSirsoftAdminBasicEditorWidgets(): void;
