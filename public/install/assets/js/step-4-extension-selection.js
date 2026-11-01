/*
 * 설치 마법사 4 (확장 선택) 단계 스크립트 — 종전 뷰(views/4-extension-selection.php)의 인라인 <script> 를 옮겼다(#158).
 *
 * 웹서버가 `Content-Security-Policy: script-src 'self'` 를 걸면 인라인 스크립트·on* 속성이 실행되지 않아
 * 단계 로직·버튼이 멈췄다. 서버 값은 `#installer-step-data` JSON 블록(installerStepData())과
 * `#installer-data` 의 번역(installerLang())으로만 받고, 버튼은 data-action 위임(installer-boot.js)으로 묶는다.
 * 전역 함수 이름은 종전과 같다(다른 스크립트·data-action 이 그 이름으로 부른다).
 */

(function () {
    'use strict';

    var data = installerStepData();

    // 「Step 4 로 돌아가기」·「설정으로 돌아가기」로 돌아오면 이전 선택을 다시 체크한다 —
    // installer.js restoreSelectionState() 가 이 값을 읽는다(#153 D7).
    window.INSTALLER_SELECTED_EXTENSIONS = data.selected_extensions || {};

    // Step 4 번역 키 전달
    window.EXTENSION_LABELS = data.extension_labels || {};
})();
