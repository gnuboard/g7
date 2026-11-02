/*
 * 설치 마법사 5 (설치) 단계 스크립트 — 종전 뷰(views/5-installation.php)의 인라인 <script> 를 옮겼다(#158).
 *
 * 웹서버가 `Content-Security-Policy: script-src 'self'` 를 걸면 인라인 스크립트·on* 속성이 실행되지 않아
 * 단계 로직·버튼이 멈췄다. 서버 값은 `#installer-step-data` JSON 블록(installerStepData())과
 * `#installer-data` 의 번역(installerLang())으로만 받고, 버튼은 data-action 위임(installer-boot.js)으로 묶는다.
 * 전역 함수 이름은 종전과 같다(다른 스크립트·data-action 이 그 이름으로 부른다).
 */

(function () {
    'use strict';

    var data = installerStepData();

    function pairs(list, installId, activateId) {
        return (list || []).map(function (target) {
            return [
                { id: installId, target: target },
                { id: activateId, target: target }
            ];
        });
    }

    // Step 5 전용 데이터 전달
    window.INSTALLER_CONFIG = data.config || {};
    window.INSTALLER_SELECTED_EXTENSIONS = data.selected_extensions || {};
    window.INSTALLER_EXTENSION_NAMES = data.extension_names || {};
    // 의존 때문에 종류 경계를 넘어 앞당겨 설치하는 항목 → 요구한 확장 (작업 행의 「…에 필요」 표시, #153)
    window.INSTALLER_PULLED_BY = data.pulled_by || {};
    // 유효 실행 모드 (subprocess|inprocess) — JS 소비처는 없고 무CLI 실측에서 콘솔로 확인하기 위한 진단용 노출
    window.INSTALLER_EXECUTION_MODE = data.execution_mode === undefined ? null : data.execution_mode;
    // 진행 중인 단계별 실행을 새로고침 뒤 이어가기 위한 토큰 (설치를 시작한 세션에만 실린다, 없으면 null)
    window.INSTALLER_STEPWISE_TOKEN = data.stepwise_token || null;
    // 서버가 서브프로세스 스폰 동안 스스로에게 주는 창(ms) — 세 모니터의 정지 감지 근거 (installer.js monitorStuckThresholdMs)
    if (typeof data.server_blocking_window_ms === 'number') {
        window.INSTALLER_SERVER_BLOCKING_WINDOW_MS = data.server_blocking_window_ms;
    }

    // 작업 그룹 정의 — 서버 태스크 목록(buildInstallationTaskList)에서 파생한 값이 있으면 그것을 쓴다.
    // 손으로 적은 아래 사본은 dependency_precheck · db_cleanup · config_cache 등을 빠뜨려 진행률 분모가 어긋나고
    // 그 단계의 실패가 빨강이 되지 않았다(12차 #B22~#B24). 사본은 task_groups 가 없는 옛 캐시 화면의 폴백이다.
    window.INSTALLER_TASK_GROUPS = (Array.isArray(data.task_groups) && data.task_groups.length > 0) ? data.task_groups : [
        {
            id: 'environment',
            labelKey: 'task_group_environment',
            tasks: [
                { id: 'composer_check' },
                { id: 'composer_install' },
                { id: 'env_update' },
                { id: 'key_generate' }
            ]
        },
        {
            id: 'database',
            labelKey: 'task_group_database',
            tasks: [
                { id: 'db_migrate' },
                { id: 'db_seed' }
            ]
        },
        {
            id: 'admin_templates',
            labelKey: 'task_group_admin_templates',
            tasks: pairs(data.admin_templates, 'template_install', 'template_activate')
        },
        {
            id: 'modules',
            labelKey: 'task_group_modules',
            tasks: pairs(data.modules, 'module_install', 'module_activate')
        },
        {
            id: 'plugins',
            labelKey: 'task_group_plugins',
            tasks: pairs(data.plugins, 'plugin_install', 'plugin_activate')
        },
        {
            id: 'user_templates',
            labelKey: 'task_group_user_templates',
            tasks: pairs(data.user_templates, 'user_template_install', 'user_template_activate')
        },
        {
            id: 'language_packs',
            labelKey: 'task_group_language_packs',
            tasks: (data.language_packs || []).map(function (pack) {
                return { id: 'language_pack_install', target: pack };
            })
        },
        {
            id: 'finalize',
            labelKey: 'task_group_finalize',
            tasks: [
                { id: 'create_settings_json' },
                { id: 'cache_clear' },
                { id: 'complete_flag' }
            ]
        }
    ];

    // 평탄화된 작업 목록 생성 (하위 호환성 유지)
    window.INSTALLER_TASKS = (function () {
        var tasks = [];
        window.INSTALLER_TASK_GROUPS.forEach(function (group) {
            if (!group.tasks || group.tasks.length === 0) return;
            group.tasks.forEach(function (taskOrArray) {
                if (Array.isArray(taskOrArray)) {
                    taskOrArray.forEach(function (t) { tasks.push(Object.assign({}, t, { group: group.id })); });
                } else {
                    tasks.push(Object.assign({}, taskOrArray, { group: group.id }));
                }
            });
        });
        return tasks;
    })();
})();
