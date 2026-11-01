/*
 * 설치 마법사 부트 — <head> 에서 가장 먼저 실행된다 (#158)
 *
 * 종전에는 index.php 의 인라인 <script> 두 개(다크 모드 즉시 적용 · window.INSTALLER_* 전역 대입)와 뷰 곳곳의
 * on* 속성으로 동작했다. 웹서버가 `Content-Security-Policy: script-src 'self'` 를 걸면 그 코드가 실행되지 않아
 * installer.js 가 전역을 찾지 못하고 버튼·폼이 멈췄다. 이 파일이 대신한다.
 *
 *   1. `#installer-data` JSON 을 읽어 종전과 같은 이름의 전역(INSTALLER_BASE_URL·CURRENT_STEP·INSTALLER_CSRF·
 *      INSTALLER_OWNERSHIP_LOCKED·INSTALLER_LANG·INSTALLER_STATE_LOCALE·INSTALLER_BLOCKED_DB_ACCOUNTS)으로 옮긴다
 *   2. 다크 모드를 렌더 전에 적용한다(FOUC 방지)
 *   3. document 에 click·change·submit 위임 리스너 하나씩을 건다. `data-action="이름"` 요소의 이벤트를
 *      아래 **명시적 액션 맵**으로 부른다 — 맵에 없는 이름은 무시한다(임의 전역 함수 호출 통로를 만들지 않는다).
 *      인자는 `data-arg`(문자열), 이벤트 종류는 `data-event`(기본 click)로 준다.
 *
 * 단계별 스크립트(step-N-*.js)가 쓰는 도우미 installerLang()·installerStepData()·installerEscapeAttr() 도 여기서 연다.
 * 손작성 ES5, 빌드하지 않는다.
 */
(function () {
    'use strict';

    function readJson(id) {
        var el = document.getElementById(id);
        if (!el) return {};
        try {
            return JSON.parse(el.textContent || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    // ── 1. 서버 값 → 전역 ─────────────────────────────────────────────
    var data = readJson('installer-data');

    window.INSTALLER_BASE_URL = data.base_url || '/install';
    window.CURRENT_STEP = typeof data.current_step === 'number' ? data.current_step : 0;
    // 상태변경 API 요청 검증 토큰 (세션 CSRF) — 소유 토큰 원문은 페이지에 싣지 않는다
    window.INSTALLER_CSRF = data.csrf || '';
    window.INSTALLER_OWNERSHIP_LOCKED = data.ownership_locked === true;
    window.INSTALLER_LANG = data.lang || {};
    window.INSTALLER_STATE_LOCALE = data.state_locale === undefined ? null : data.state_locale;
    // DB 최고권한 계정 목록 — 클라이언트 검증은 즉시 피드백용이며, 실제 차단은 서버가 한다.
    window.INSTALLER_BLOCKED_DB_ACCOUNTS = data.blocked_db_accounts || [];

    /**
     * 설치 마법사 번역 (서버 lang() 과 같은 사전 — 없는 키는 키 그대로).
     */
    window.installerLang = function (key) {
        var dict = window.INSTALLER_LANG || {};
        return Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
    };

    /**
     * 현재 단계 뷰의 `#installer-step-data` JSON (한 번 읽고 기억한다).
     */
    var stepData = null;
    window.installerStepData = function () {
        if (stepData === null) {
            stepData = readJson('installer-step-data');
        }
        return stepData;
    };

    /**
     * innerHTML 로 만드는 HTML 속성값 이스케이프.
     */
    window.installerEscapeAttr = function (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    };

    // ── 2. 다크 모드 즉시 적용 (FOUC 방지) ────────────────────────────
    try {
        var theme = window.localStorage.getItem('g7_color_scheme')
            || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
    } catch (e) {
        // localStorage 접근 실패 시 기본값(light) 사용
        document.documentElement.setAttribute('data-theme', 'light');
    }

    // ── 3. data-action 위임 ───────────────────────────────────────────
    function call(name, args) {
        var fn = window[name];
        if (typeof fn !== 'function') return undefined;
        return fn.apply(window, args);
    }

    function noArg(name) {
        return function () { return call(name, []); };
    }

    function elementArg(name) {
        return function (el) { return call(name, [el]); };
    }

    /**
     * 허용 액션 — 이름 → (요소, data-arg, 이벤트) 를 받아 종전 on* 과 같은 인자로 부른다.
     */
    var ACTIONS = {
        // 0 단계
        copyWelcomeCommand: elementArg('copyWelcomeCommand'),
        recheckPermission: noArg('recheckPermission'),
        hideElement: function (el, arg) {
            var target = arg ? document.getElementById(arg) : null;
            if (target) target.style.display = 'none';
        },
        // 단계 이동·요구사항
        goToStep: function (el, arg) { return call('goToStep', [parseInt(arg, 10)]); },
        recheckRequirements: noArg('recheckRequirements'),
        // 3 단계
        testDatabaseConnection: function (el, arg) { return call('testDatabaseConnection', [arg]); },
        checkCorePendingPath: noArg('checkCorePendingPath'),
        testPhpBinary: noArg('testPhpBinary'),
        testComposer: noArg('testComposer'),
        detectPhpBinaries: noArg('detectPhpBinaries'),
        copyCliCommand: elementArg('copyCliCommand'),
        selectPhpBinary: function (el, arg) { return call('selectPhpBinary', [arg]); },
        // 4 단계
        loadExtensions: noArg('loadExtensions'),
        autoSelectMissingDependencies: noArg('autoSelectMissingDependencies'),
        // 5 단계
        copySetupCommand: noArg('copySetupCommand'),
        copySetupCommandRelative: noArg('copySetupCommandRelative'),
        recheckFiles: noArg('recheckFiles'),
        abortInstallation: noArg('abortInstallation'),
        resumeInstallationFromAborted: noArg('resumeInstallationFromAborted'),
        goToSettingsWithConfirm: noArg('goToSettingsWithConfirm'),
        goBackToExtensionSelection: noArg('goBackToExtensionSelection'),
        retryInstallation: noArg('retryInstallation'),
        onStartInstallationClick: noArg('onStartInstallationClick'),
        toggleInstallationCard: noArg('toggleInstallationCard'),
        toggleTaskGroup: function (el, arg) { return call('toggleTaskGroup', [arg]); },
        toggleErrorDetail: function (el, arg) { return call('toggleErrorDetail', [el, arg]); },
        // 권한 안내·DB 기존 테이블 창
        copyPermissionCommand: elementArg('copyPermissionCommand'),
        onDbBackupConsentChange: elementArg('onDbBackupConsentChange'),
        closeExistingTablesModal: noArg('closeExistingTablesModal'),
        confirmDropTables: noArg('confirmDropTables'),
        // 소유 복구
        submitRecoveryToken: function (el, arg, event) { return call('submitRecoveryToken', [event]); }
    };

    function dispatch(event) {
        var start = event.target;
        if (!start || !start.closest) return;

        var el = start.closest('[data-action]');
        if (!el) return;

        var expected = el.getAttribute('data-event') || 'click';
        if (expected !== event.type) return;

        var action = ACTIONS[el.getAttribute('data-action')];
        if (!action) return; // 맵에 없는 이름은 무시

        // href="#" 링크는 이동하지 않는다(종전 `return false`)
        if (el.tagName === 'A' && el.getAttribute('href') === '#') {
            event.preventDefault();
        }

        var result = action(el, el.getAttribute('data-arg'), event);
        if (result === false) {
            event.preventDefault();
        }
    }

    document.addEventListener('click', dispatch);
    document.addEventListener('change', dispatch);
    document.addEventListener('submit', dispatch);

    // 시험용 노출 (installer-delegation.test.cjs) — 액션 이름 목록만, 함수는 내보내지 않는다
    window.__installerActionNames = Object.keys(ACTIONS);
})();
