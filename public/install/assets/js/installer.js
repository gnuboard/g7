/**
 * G7 Installer - Vanilla JavaScript
 * 모든 클라이언트 사이드 인터랙션 처리
 */

// =========================================================================
// 로케일 초기화 (깜빡임 방지를 위해 최상단에서 즉시 실행)
// =========================================================================

(function initializeLocale() {
    'use strict';

    const STORAGE_KEY = 'g7_locale';
    const SUPPORTED_LOCALES = ['ko', 'en'];
    const DEFAULT_LOCALE = 'ko';

    /**
     * 저장된 로케일 또는 서버/브라우저 언어 기반 로케일 가져오기
     *
     * 우선순위: localStorage > state.json (서버 전달) > 브라우저 언어 > 기본값
     */
    function getPreferredLocale() {
        // 1. localStorage 확인 (최우선)
        const storedLocale = localStorage.getItem(STORAGE_KEY);
        if (storedLocale && SUPPORTED_LOCALES.includes(storedLocale)) {
            return storedLocale;
        }

        // 2. state.json 값 확인 (서버에서 전달된 값)
        if (window.INSTALLER_STATE_LOCALE && SUPPORTED_LOCALES.includes(window.INSTALLER_STATE_LOCALE)) {
            return window.INSTALLER_STATE_LOCALE;
        }

        // 3. 브라우저 언어 감지
        const browserLang = navigator.language || navigator.userLanguage;
        if (browserLang) {
            // 언어-지역 형태에서 언어만 추출 (예: ko-KR -> ko)
            const lang = browserLang.split('-')[0].toLowerCase();
            if (SUPPORTED_LOCALES.includes(lang)) {
                return lang;
            }
        }

        // 4. 기본값
        return DEFAULT_LOCALE;
    }

    /**
     * localStorage에 로케일 저장
     */
    function saveLocale(locale) {
        try {
            localStorage.setItem(STORAGE_KEY, locale);
        } catch (error) {
            console.warn('[Installer] Failed to save locale to localStorage:', error);
        }
    }

    // 초기 로케일 설정 및 저장
    const initialLocale = getPreferredLocale();
    saveLocale(initialLocale);

    // 전역 함수로 노출 (언어 선택 UI에서 사용)
    window.installerLocale = {
        get: function() {
            return localStorage.getItem(STORAGE_KEY) || DEFAULT_LOCALE;
        },
        set: function(locale) {
            if (SUPPORTED_LOCALES.includes(locale)) {
                saveLocale(locale);
                return true;
            }
            return false;
        }
    };
})();

// =========================================================================
// 메인 인스톨러 로직
// =========================================================================

(function() {
    'use strict';

    // =========================================================================
    // 다국어 헬퍼 함수
    // =========================================================================

    /**
     * 다국어 번역 조회
     *
     * @param {string} key 번역 키
     * @return {string} 번역된 문자열
     */
    function lang(key) {
        return window.INSTALLER_LANG && window.INSTALLER_LANG[key] ? window.INSTALLER_LANG[key] : key;
    }

    // =========================================================================
    // Step 1: 라이선스 동의 체크박스
    // =========================================================================

    /**
     * 라이선스 동의 체크박스 이벤트 초기화
     */
    function initLicenseAgreement() {
        const licenseCheckbox = document.getElementById('license-agree');
        const licenseForm = licenseCheckbox ? licenseCheckbox.closest('form') : null;

        if (licenseForm && licenseCheckbox) {
            licenseForm.addEventListener('submit', function(e) {
                if (!licenseCheckbox.checked) {
                    e.preventDefault();
                    alert(lang('must_agree'));
                    licenseCheckbox.focus();
                }
            });
        }
    }

    // =========================================================================
    // Step 2: 요구사항 검증
    // =========================================================================

    /**
     * 서버 요구사항 검증 실행
     */
    async function checkRequirements() {
        try {
            // 절대 URL 생성 (origin + base path)
            const apiUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/check-configuration.php?action=requirements`;
            const response = await fetch(apiUrl);

            // HTTP 응답 상태 확인
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            const resultHtml = renderRequirements(data);
            document.getElementById('requirements-result').innerHTML = resultHtml;
            document.getElementById('navigation-buttons').classList.remove('hidden');

            // 버튼 표시 제어
            const recheckBtn = document.getElementById('recheck-btn');
            const nextBtn = document.getElementById('next-btn');

            if (data.all_required_passed) {
                // 필수 요구사항 통과: 다음 버튼만 표시
                recheckBtn.classList.add('hidden');
                nextBtn.classList.remove('hidden');
                nextBtn.disabled = false;
            } else {
                // 필수 요구사항 미통과: 다시검증 버튼만 표시
                recheckBtn.classList.remove('hidden');
                nextBtn.classList.add('hidden');
            }
        } catch (error) {
            document.getElementById('requirements-result').innerHTML = `
                <div class="alert alert-error">
                    ${lang('requirements_check_failed')}: ${error.message}
                </div>
            `;
        }
    }

    /**
     * 검증 결과를 HTML로 렌더링 (카드 형태)
     */
    function renderRequirements(data) {
        let html = '<div class="requirements-result">';
        let failedRequirements = [];

        // API 응답에서 권한 설정값 가져오기 (카드 헤더 표시용)
        const requiredPermissions = data.directories?.required_permissions || '755';

        // OS 정보 (Windows 여부)
        const isWindows = data.is_windows || false;

        // 1. PHP 버전 카드
        const phpStatusClass = data.php_version.passed ? 'status-pass' : 'status-fail';
        if (!data.php_version.passed) {
            failedRequirements.push(`${lang('error_php_version')}: ${data.php_version.current_version} (${lang('minimum')} ${data.php_version.min_version} ${lang('required_text')})`);
        }
        html += renderSingleItemCard(
            lang('php_version'),
            phpStatusClass,
            `PHP ${data.php_version.current_version}`,
            `${data.php_version.min_version}+`,
            true
        );

        // 2. 디스크 공간 카드
        const diskSpaceGB = (data.disk_space.current_mb / 1024).toFixed(2);
        const minSpaceGB = (data.disk_space.min_mb / 1024).toFixed(2);
        const diskStatusClass = data.disk_space.passed ? 'status-pass' : 'status-fail';
        if (!data.disk_space.passed) {
            failedRequirements.push(`${lang('error_disk_space')}: ${diskSpaceGB} GB (${lang('minimum')} ${minSpaceGB} GB ${lang('required_text')})`);
        }
        html += renderSingleItemCard(
            lang('disk_space'),
            diskStatusClass,
            `${diskSpaceGB} GB`,
            `${minSpaceGB} GB`,
            true
        );

        // 3. PHP 모듈 카드
        const allRequiredPassed = data.php_extensions.required.every(ext => data.php_extensions.installed[ext]?.installed);
        if (!allRequiredPassed) {
            const missing = data.php_extensions.required.filter(ext => !data.php_extensions.installed[ext]?.installed);
            failedRequirements.push(`${lang('error_missing_extensions')}: ${missing.join(', ')}`);
        }
        let requiredExtHtml = '<div class="extension-grid">';
        data.php_extensions.required.forEach(ext => {
            const info = data.php_extensions.installed[ext];
            requiredExtHtml += renderExtensionItem(ext, info?.installed || false, true);
        });
        requiredExtHtml += '</div>';

        const requiredStatusClass = allRequiredPassed ? 'status-pass' : 'status-fail';
        html += renderMultiItemCard(
            lang('php_modules'),
            requiredStatusClass,
            requiredExtHtml,
            true,
            ''
        );

        // 4. 디렉토리 권한 카드
        const allDirsPassed = Object.values(data.directories.results).every(dir => dir.passed);
        const failedDirPaths = []; // chmod 대상 (쓰기/읽기 불가)
        const ownershipMismatchItems = []; // 소유권 불일치 대상 (chgrp/chown/777 옵션 안내)
        const notExistsPaths = []; // mkdir 대상 (not_exists)
        const failedDirRelPaths = []; // 상대경로
        const notExistsRelPaths = []; // 상대경로 (not_exists)
        if (!allDirsPassed) {
            // 계층적 디렉토리 에러 메시지 생성
            let dirErrorHtml = `${lang('error_directory_permissions')}<ul style="margin-top: 0.5rem;">`;

            Object.entries(data.directories.results)
                .filter(([, info]) => !info.passed)
                .forEach(([path, info]) => {
                    const fullPath = info.full_path || path;
                    const relPath = info.relative_path || ('./' + path);
                    if (info.error_type === 'not_exists') {
                        notExistsPaths.push(fullPath);
                        notExistsRelPaths.push(relPath);
                    } else if (info.error_type === 'ownership_mismatch') {
                        ownershipMismatchItems.push({
                            fullPath,
                            relPath,
                            owner: info.owner || 'unknown',
                            webUser: info.web_server_user || 'www-data',
                            permissions: info.permissions || '755',
                        });
                    } else {
                        // not_writable, not_readable, subdirectory_issues 등 모두 chmod 대상
                        failedDirPaths.push(fullPath);
                        failedDirRelPaths.push(relPath);
                    }
                    dirErrorHtml += `<li>${path}`;

                    // 하위 디렉토리 문제가 있으면 중첩 리스트로 표시 (부모 경로 제거)
                    if (info.has_subdirectory_issues && info.failed_subdirectories && info.failed_subdirectories.length > 0) {
                        dirErrorHtml += '<ul>';
                        info.failed_subdirectories.forEach(subdir => {
                            // subdir는 {path: '...', permissions: '...'} 형태의 객체
                            const subdirPath = typeof subdir === 'string' ? subdir : subdir.path;
                            // 부모 경로 제거 (예: storage/framework/cache -> framework/cache)
                            const relativePath = subdirPath.replace(path + '/', '');
                            dirErrorHtml += `<li>${escapeHtml(relativePath)}</li>`;
                        });
                        dirErrorHtml += '</ul>';
                    }

                    dirErrorHtml += '</li>';
                });

            dirErrorHtml += '</ul>';
            failedRequirements.push(dirErrorHtml);
        }
        let dirHtml = '<div class="directory-grid">';
        for (const [path, info] of Object.entries(data.directories.results)) {
            dirHtml += renderDirectoryItem(path, info);
        }
        dirHtml += '</div>';

        const dirStatusClass = allDirsPassed ? 'status-pass' : 'status-fail';
        html += renderMultiItemCard(
            lang('directory_permissions'),
            dirStatusClass,
            dirHtml,
            true,
            requiredPermissions
        );

        // 5. 필수 파일 카드
        const missingFileCommands = []; // 파일 생성 대상 (not_exists)
        const notWritableFilePaths = []; // 쓰기 불가 + 권한 비트 부족 (chmod 644로 해결)
        const fileOwnershipMismatchItems = []; // 권한 충분하나 소유자 불일치 (chgrp/chown/666)
        if (data.required_files) {
            const allFilesPassed = data.required_files.all_passed;
            if (!allFilesPassed) {
                // 상위 에러 메시지: 파일 상태별로 다른 메시지 구성
                const missingNames = [];
                const notWritableNames = [];
                const ownershipMismatchNames = [];
                Object.entries(data.required_files.files).forEach(([name, info]) => {
                    if (!info.passed) {
                        if (info.error_type === 'not_exists') {
                            missingNames.push(name);
                        } else if (info.error_type === 'ownership_mismatch') {
                            ownershipMismatchNames.push(name);
                        } else {
                            notWritableNames.push(name);
                        }
                    }
                });
                if (missingNames.length > 0) {
                    failedRequirements.push(`${lang('error_required_files_missing_label')}: ${missingNames.join(', ')}`);
                }
                if (notWritableNames.length > 0) {
                    failedRequirements.push(`${lang('error_required_files_not_writable_label')}: ${notWritableNames.join(', ')}`);
                }
                if (ownershipMismatchNames.length > 0) {
                    failedRequirements.push(`${lang('error_required_files_ownership_mismatch_label')}: ${ownershipMismatchNames.join(', ')}`);
                }

                // 파일별 대상 배열 분류
                Object.entries(data.required_files.files).forEach(([name, info]) => {
                    const fullPath = data.required_files.base_path + '/' + name;
                    if (info.error_type === 'not_exists' && info.command) {
                        missingFileCommands.push(info.command);
                    } else if (info.error_type === 'ownership_mismatch') {
                        fileOwnershipMismatchItems.push({
                            name,
                            fullPath,
                            owner: info.owner || 'unknown',
                            webUser: info.web_server_user || 'www-data',
                            permissions: info.permissions || '644',
                        });
                    } else if (info.error_type === 'not_writable') {
                        notWritableFilePaths.push(fullPath);
                    }
                });
            }

            let fileHtml = '<div class="directory-grid">';
            Object.entries(data.required_files.files).forEach(([name, info]) => {
                const fileStatusClass = info.passed ? 'status-pass' : 'status-fail';
                const fileStatusIcon = getStatusIcon(fileStatusClass);
                let fileStatusText = lang('file_exists');
                if (!info.exists) {
                    fileStatusText = lang('file_missing');
                } else if (!info.writable) {
                    fileStatusText = lang('file_not_writable');
                }
                fileHtml += `
                    <div class="directory-item-wrapper">
                        <div class="directory-item ${fileStatusClass}">
                            <div class="directory-left">
                                ${fileStatusIcon}
                                <span class="directory-name">${escapeHtml(name)}</span>
                            </div>
                            <div class="directory-right">
                                <span class="directory-permission">${fileStatusText}</span>
                            </div>
                        </div>
                    </div>
                `;
            });
            fileHtml += '</div>';

            const fileStatusClass = allFilesPassed ? 'status-pass' : 'status-fail';
            html += renderMultiItemCard(
                lang('required_files'),
                fileStatusClass,
                fileHtml,
                true
            );
        }

        // 6. HTTPS 카드
        const httpsStatusClass = data.https.enabled ? 'status-pass' : 'status-warning';
        html += renderSingleItemCard(
            lang('https_enabled'),
            httpsStatusClass,
            data.https.enabled ? lang('enabled') : lang('not_enabled'),
            '',
            false
        );

        // 7. 필수 함수 (exec, proc_open, shell_exec) 카드
        if (data.disabled_functions) {
            const dfPassed = data.disabled_functions.passed;
            const dfStatusClass = dfPassed ? 'status-pass' : 'status-fail';
            const dfDisabledList = data.disabled_functions.disabled || [];
            if (!dfPassed) {
                failedRequirements.push(
                    lang('error_disabled_functions').replace(':functions', dfDisabledList.join(', '))
                );
            }
            html += renderSingleItemCard(
                lang('required_functions'),
                dfStatusClass,
                dfPassed
                    ? lang('required_functions_available')
                    : lang('required_functions_disabled').replace(':count', dfDisabledList.length),
                'exec, proc_open, shell_exec',
                true
            );
        }

        // 8. PHP CLI 버전 일치 확인 카드 (경고만, 필수 아님)
        if (data.php_cli_version) {
            const cliData = data.php_cli_version;
            let cliStatusClass, cliStatusText;
            if (cliData.matched === null && cliData.cli_version === null) {
                // exec 비활성화 또는 CLI 버전 파악 불가
                cliStatusClass = 'status-warning';
                cliStatusText = cliData.cli_path === null
                    ? lang('php_cli_version_check_skipped')
                    : lang('php_cli_version_unknown');
            } else if (cliData.matched) {
                cliStatusClass = 'status-pass';
                cliStatusText = lang('php_cli_version_matched')
                    .replace(':web', cliData.web_version)
                    .replace(':cli', cliData.cli_version);
            } else {
                cliStatusClass = 'status-warning';
                cliStatusText = lang('php_cli_version_mismatch')
                    .replace(':web', cliData.web_version)
                    .replace(':cli', cliData.cli_version || '?');
            }
            html += renderSingleItemCard(
                lang('php_cli_version'),
                cliStatusClass,
                cliStatusText,
                '',
                false
            );
        }

        html += '</div>';

        // 필수 조건 미충족 시 에러 박스 표시
        if (failedRequirements.length > 0) {
            html += '<div class="requirements-error-box">';
            html += '<div class="error-box-header">';
            html += '<span class="error-icon">';
            html += '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">';
            html += '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />';
            html += '</svg>';
            html += '</span>';
            html += `<h3 class="error-box-title">${lang('requirements_not_met')}</h3>`;
            html += '</div>';
            html += '<ul class="error-box-list">';
            failedRequirements.forEach(error => {
                html += `<li>${error}</li>`;
            });
            html += '</ul>';

            // 디렉토리 미존재 (not_exists): mkdir 명령어 안내
            if (notExistsPaths.length > 0) {
                html += '<div class="permission-fix-guide">';
                html += `<p class="fix-guide-label">${lang('directory_create_guide')}</p>`;
                html += '<div class="code-box">';
                if (isWindows) {
                    const winMkdirCommands = notExistsPaths.map(p => `mkdir "${p.replace(/\//g, '\\\\')}"`);
                    html += `<pre class="fix-command">${escapeHtml(winMkdirCommands.join('\n'))}</pre>`;
                } else {
                    const mkdirCommand = `mkdir -p ${notExistsPaths.join(' ')}`;
                    html += `<pre class="fix-command">${escapeHtml(mkdirCommand)}</pre>`;
                }
                html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                html += '</div>';
                html += `<p class="fix-guide-hint">${lang('or_relative_path')}</p>`;
                html += '<div class="code-box">';
                if (isWindows) {
                    const winRelMkdirCommands = notExistsRelPaths.map(p => `mkdir "${p.replace(/\//g, '\\\\')}"`);
                    html += `<pre class="fix-command">${escapeHtml(winRelMkdirCommands.join('\n'))}</pre>`;
                } else {
                    const relMkdirCommand = `mkdir -p ${notExistsRelPaths.join(' ')}`;
                    html += `<pre class="fix-command">${escapeHtml(relMkdirCommand)}</pre>`;
                }
                html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                html += '</div>';
                html += '</div>';
            }

            // 소유권 불일치 (ownership_mismatch): 파일 소유자 != 웹서버 실행 사용자
            // 3가지 해결 옵션 제시 (그룹 공유 권장 > 소유자 변경 > 777 최후 수단)
            if (ownershipMismatchItems.length > 0) {
                // 대상 경로를 공백으로 join (동일 webUser 기준 — 현재는 모두 동일)
                const owner = ownershipMismatchItems[0].owner;
                const webUser = ownershipMismatchItems[0].webUser;
                const mismatchPaths = ownershipMismatchItems.map(i => i.fullPath).join(' ');

                // 그룹 쓰기 비트(0o020) 이미 설정되어 있으면 chmod 생략, chgrp만 안내
                // 모든 대상이 이미 그룹 쓰기 가능한 경우에만 chmod 생략 (부분 일치 시 안전하게 포함)
                const allGroupWritable = ownershipMismatchItems.every(i => {
                    const p = parseInt(i.permissions, 8);
                    return !isNaN(p) && (p & 0o020) !== 0;
                });
                const groupCommand = allGroupWritable
                    ? `sudo chgrp -R ${webUser} ${mismatchPaths}`
                    : `sudo chgrp -R ${webUser} ${mismatchPaths} && sudo chmod -R 775 ${mismatchPaths}`;

                html += '<div class="permission-fix-guide">';
                html += `<p class="fix-guide-label">${lang('ownership_mismatch_option_group')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(groupCommand)}</pre>`;
                html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                html += '</div>';

                html += `<p class="fix-guide-label" style="margin-top: 1rem;">${lang('ownership_mismatch_option_chown')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(`sudo chown -R ${webUser}:${webUser} ${mismatchPaths}`)}</pre>`;
                html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                html += '</div>';

                html += `<p class="fix-guide-label" style="margin-top: 1rem;">${lang('ownership_mismatch_option_777')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(`chmod -R 777 ${mismatchPaths}`)}</pre>`;
                html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                html += '</div>';

                const hintText = lang('ownership_mismatch_hint')
                    .replace(':owner', escapeHtml(owner))
                    .replace(':web_user', escapeHtml(webUser));
                html += `<p class="fix-guide-hint" style="margin-top: 0.75rem;">${hintText}</p>`;
                html += '</div>';
            }

            // 디렉토리 권한 문제 (쓰기/읽기 불가): chmod 755 단일 명령어 안내
            // 업계 표준(WordPress/Drupal/Joomla/Laravel)에 맞춰 chown 의존성 제거, 비트 강요 제거
            if (failedDirPaths.length > 0) {
                html += '<div class="permission-fix-guide">';
                if (isWindows) {
                    const winDirPaths = failedDirPaths.map(p => p.replace(/\//g, '\\'));
                    const winDirCommands = winDirPaths.map(p => `icacls "${p}" /grant Everyone:(OI)(CI)F /T`);
                    html += `<p class="fix-guide-label">${lang('permission_fix_guide')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(winDirCommands.join('\n'))}</pre>`;
                    html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                    html += '</div>';
                    const winDirRelPaths = failedDirRelPaths.map(p => p.replace(/\//g, '\\'));
                    const winDirRelCommands = winDirRelPaths.map(p => `icacls "${p}" /grant Everyone:(OI)(CI)F /T`);
                    html += `<p class="fix-guide-hint">${lang('or_relative_path')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(winDirRelCommands.join('\n'))}</pre>`;
                    html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                    html += '</div>';
                    html += `<p class="fix-guide-hint">${lang('permission_windows_hint')}</p>`;
                } else {
                    const dirPathList = failedDirPaths.join(' ');
                    const dirRelPathList = failedDirRelPaths.join(' ');
                    html += `<p class="fix-guide-label">${lang('permission_fix_guide')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(`chmod -R 755 ${dirPathList}`)}</pre>`;
                    html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                    html += '</div>';
                    html += `<p class="fix-guide-hint">${lang('or_relative_path')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(`chmod -R 755 ${dirRelPathList}`)}</pre>`;
                    html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                    html += '</div>';
                }
                html += '</div>';
            }

            // 필수 파일 누락: 생성 + 권한 수정 통합 안내
            // base_path 소유자가 웹서버 사용자와 다른 경우, 생성 직후 ownership_mismatch가
            // 재현되므로 복사 명령에 chgrp + chmod 664를 미리 포함시켜 2단계 안내를 방지
            if (missingFileCommands.length > 0) {
                let fileFixCommand;
                let fileFixRelCommand;
                const relBasePath = data.required_files.relative_base_path || '.';
                if (isWindows) {
                    // Windows: 복사 명령만 (권한 문제 거의 없음)
                    fileFixCommand = missingFileCommands.join(' && ');
                    fileFixRelCommand = 'copy .env.example .env';
                } else {
                    // Linux: 복사 + chmod (소유자 일치 여부에 따라 chgrp 포함)
                    const basePath = data.required_files.base_path || '';
                    const envPath = basePath + '/.env';
                    const relEnvPath = relBasePath + '/.env';
                    const webUser = data.required_files.web_server_user || 'www-data';
                    const ownerMatchesWebUser = data.required_files.base_path_owner_matches_web_user === true;

                    if (ownerMatchesWebUser) {
                        // 소유자 일치 → chmod 644만 필요
                        fileFixCommand = missingFileCommands.join(' && ') + ` && chmod 644 ${envPath}`;
                        fileFixRelCommand = `cp ${relBasePath}/.env.example ${relEnvPath} && chmod 644 ${relEnvPath}`;
                    } else {
                        // 소유자 불일치 → 복사 + chgrp + chmod 664 통합
                        fileFixCommand = missingFileCommands.join(' && ') + ` && sudo chgrp ${webUser} ${envPath} && sudo chmod 664 ${envPath}`;
                        fileFixRelCommand = `cp ${relBasePath}/.env.example ${relEnvPath} && sudo chgrp ${webUser} ${relEnvPath} && sudo chmod 664 ${relEnvPath}`;
                    }
                }
                html += '<div class="permission-fix-guide">';
                html += `<p class="fix-guide-label">${isWindows ? lang('required_files_fix_guide') : lang('required_files_fix_guide_combined')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(fileFixCommand)}</pre>`;
                html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                html += '</div>';
                html += `<p class="fix-guide-hint">${lang('or_relative_path')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(fileFixRelCommand)}</pre>`;
                html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                html += '</div>';
                html += '</div>';
            }

            // 필수 파일 쓰기 불가: chmod 644 단일 명령어 안내
            if (notWritableFilePaths.length > 0) {
                const notWritableRelPaths = notWritableFilePaths.map(p => {
                    const basePath = data.required_files.base_path || '';
                    return basePath ? p.replace(basePath, '.') : p;
                });
                html += '<div class="permission-fix-guide">';
                if (isWindows) {
                    const winFilePaths = notWritableFilePaths.map(p => p.replace(/\//g, '\\'));
                    const winFileCommands = winFilePaths.map(p => `icacls "${p}" /grant Everyone:F`);
                    html += `<p class="fix-guide-label">${lang('permission_fix_guide')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(winFileCommands.join('\n'))}</pre>`;
                    html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                    html += '</div>';
                    const winRelFilePaths = notWritableRelPaths.map(p => p.replace(/\//g, '\\'));
                    const winRelFileCommands = winRelFilePaths.map(p => `icacls "${p}" /grant Everyone:F`);
                    html += `<p class="fix-guide-hint">${lang('or_relative_path')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(winRelFileCommands.join('\n'))}</pre>`;
                    html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                    html += '</div>';
                    html += `<p class="fix-guide-hint">${lang('permission_windows_hint')}</p>`;
                } else {
                    const filePathList = notWritableFilePaths.join(' ');
                    const relFilePathList = notWritableRelPaths.join(' ');
                    html += `<p class="fix-guide-label">${lang('permission_fix_guide')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(`chmod 644 ${filePathList}`)}</pre>`;
                    html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                    html += '</div>';
                    html += `<p class="fix-guide-hint">${lang('or_relative_path')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(`chmod 644 ${relFilePathList}`)}</pre>`;
                    html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                    html += '</div>';
                }
                html += '</div>';
            }

            // 파일 소유권 불일치: 3가지 해결 옵션 제시
            // (파일 소유자 ≠ 웹서버 실행 사용자, 권한 비트는 0644 이상이지만 실제 쓰기 불가)
            if (fileOwnershipMismatchItems.length > 0) {
                const fileOwner = fileOwnershipMismatchItems[0].owner;
                const fileWebUser = fileOwnershipMismatchItems[0].webUser;
                const mismatchFilePaths = fileOwnershipMismatchItems.map(i => i.fullPath).join(' ');

                // 그룹 쓰기 비트(0o020) 이미 설정되어 있으면 chmod 생략
                const allFileGroupWritable = fileOwnershipMismatchItems.every(i => {
                    const p = parseInt(i.permissions, 8);
                    return !isNaN(p) && (p & 0o020) !== 0;
                });
                const fileGroupCommand = allFileGroupWritable
                    ? `sudo chgrp ${fileWebUser} ${mismatchFilePaths}`
                    : `sudo chgrp ${fileWebUser} ${mismatchFilePaths} && sudo chmod 664 ${mismatchFilePaths}`;

                html += '<div class="permission-fix-guide">';
                html += `<p class="fix-guide-label">${lang('ownership_mismatch_option_group')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(fileGroupCommand)}</pre>`;
                html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                html += '</div>';

                html += `<p class="fix-guide-label" style="margin-top: 1rem;">${lang('ownership_mismatch_option_chown')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(`sudo chown ${fileWebUser}:${fileWebUser} ${mismatchFilePaths}`)}</pre>`;
                html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                html += '</div>';

                html += `<p class="fix-guide-label" style="margin-top: 1rem;">${lang('ownership_mismatch_option_666')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(`chmod 666 ${mismatchFilePaths}`)}</pre>`;
                html += `<button class="btn-copy" onclick="copyPermissionCommand(this)">${lang('copy_command')}</button>`;
                html += '</div>';

                const fileHintText = lang('ownership_mismatch_hint')
                    .replace(':owner', escapeHtml(fileOwner))
                    .replace(':web_user', escapeHtml(fileWebUser));
                html += `<p class="fix-guide-hint" style="margin-top: 0.75rem;">${fileHintText}</p>`;
                html += '</div>';
            }

            html += '</div>';
        }

        return html;
    }

    /**
     * 단일 항목 카드 렌더링 (PHP 버전, 디스크 공간, HTTPS)
     */
    function renderSingleItemCard(title, statusClass, currentValue, requiredValue, isRequired) {
        const statusIcon = getStatusIcon(statusClass);
        const badgeText = isRequired ? lang('badge_required') : lang('badge_optional');
        const badgeClass = isRequired ? 'badge-required' : 'badge-optional';

        return `
            <div class="requirement-card">
                <div class="requirement-card-header header-single">
                    <div class="header-left">
                        ${statusIcon}
                        <h3 class="requirement-card-title">${title}</h3>
                        ${requiredValue ? `<span class="requirement-text">${requiredValue} ${lang('or_above')}</span>` : ''}
                    </div>
                    <div class="header-right">
                        <div class="current-value">
                            <span class="value-text">${currentValue}</span>
                        </div>
                        <div class="requirement-badge ${badgeClass}">${badgeText}</div>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * 다중 항목 카드 렌더링 (PHP 확장, 디렉토리 권한)
     */
    function renderMultiItemCard(title, statusClass, bodyHtml, isRequired, requiredPermission) {
        const statusIcon = getStatusIcon(statusClass);
        const badgeText = isRequired ? lang('badge_required') : lang('badge_optional');
        const badgeClass = isRequired ? 'badge-required' : 'badge-optional';

        return `
            <div class="requirement-card">
                <div class="requirement-card-header">
                    <div class="header-left">
                        ${statusIcon}
                        <h3 class="requirement-card-title">${title}</h3>
                        ${requiredPermission ? `<span class="requirement-text">${requiredPermission}</span>` : ''}
                    </div>
                    <div class="requirement-badge ${badgeClass}">${badgeText}</div>
                </div>
                <div class="requirement-card-body">
                    ${bodyHtml}
                </div>
            </div>
        `;
    }

    /**
     * 상태 아이콘 생성 (SVG 아이콘 사용)
     */
    function getStatusIcon(statusClass) {
        let iconType = '';
        if (statusClass === 'status-pass') {
            iconType = 'completed';
        } else if (statusClass === 'status-fail') {
            iconType = 'failed';
        } else if (statusClass === 'status-warning') {
            iconType = 'warning';
        }
        return `<span class="status-icon ${statusClass}">${getIconSvg(iconType)}</span>`;
    }

    /**
     * PHP 확장 항목 렌더링
     */
    function renderExtensionItem(name, installed, required) {
        const statusClass = installed ? 'status-pass' : (required ? 'status-fail' : 'status-warning');
        const statusIcon = getStatusIcon(statusClass);

        return `
            <div class="extension-item ${statusClass}">
                ${statusIcon}
                <span class="extension-name">${name}</span>
            </div>
        `;
    }

    /**
     * 디렉토리 권한 항목 렌더링
     */
    function renderDirectoryItem(path, info) {
        const statusClass = info.passed ? 'status-pass' : 'status-fail';
        const statusIcon = getStatusIcon(statusClass);

        let permissionDisplay = '';
        let subdirectoryErrorHtml = '';

        if (info.passed) {
            // 통과한 경우
            permissionDisplay = info.permissions;
        } else {
            // 실패한 경우

            // 부모 디렉토리 권한 표시
            if (info.error_type && info.error_type !== 'subdirectory_issues') {
                // 부모에 문제가 있으면 부모 옆에 에러 아이콘 표시
                permissionDisplay = `${info.permissions} <span class="permission-error-icon" data-tooltip="${lang('fix_permission')}">ⓘ</span>`;
            } else {
                // 부모는 OK, 하위만 문제
                permissionDisplay = info.permissions;
            }

            // 하위 디렉토리 문제 확인
            if (info.has_subdirectory_issues && info.failed_subdirectories && info.failed_subdirectories.length > 0) {
                // 각 하위 디렉토리를 개별 div로 표시 (부모 경로 제거하여 상대 경로로 표시)
                const subdirItems = info.failed_subdirectories.map(subdir => {
                    // subdir는 이제 {path: '...', permissions: '...'} 형태
                    const subdirPath = typeof subdir === 'string' ? subdir : subdir.path;
                    const subdirPerms = typeof subdir === 'string' ? '' : subdir.permissions;

                    // 부모 경로 제거 (예: storage/framework/cache -> framework/cache)
                    const relativePath = subdirPath.replace(path + '/', '');

                    return `
                        <div class="directory-subdirectory-item">
                            <span class="subdir-name">⤷ ${escapeHtml(relativePath)}</span>
                            <span class="subdir-permission">
                                ${subdirPerms} <span class="permission-error-icon" data-tooltip="${lang('fix_permission')}">ⓘ</span>
                            </span>
                        </div>
                    `;
                }).join('');

                subdirectoryErrorHtml = `
                    <div class="directory-subdirectory-error">
                        ${subdirItems}
                    </div>
                `;
            }
        }

        // 소유자:소유그룹 표시 (posix 미지원 환경에서는 null이므로 생략)
        let ownerDisplay = '';
        if (info.owner || info.group) {
            const owner = info.owner || '?';
            const group = info.group || '?';
            ownerDisplay = `<span class="directory-owner">${escapeHtml(owner)}:${escapeHtml(group)}</span>`;
        }

        return `
            <div class="directory-item-wrapper">
                <div class="directory-item ${statusClass}">
                    <div class="directory-left">
                        ${statusIcon}
                        <span class="directory-name">${path}</span>
                    </div>
                    <div class="directory-right">
                        ${ownerDisplay}
                        <span class="directory-permission">${permissionDisplay}</span>
                    </div>
                </div>
                ${subdirectoryErrorHtml}
            </div>
        `;
    }

    /**
     * 요구사항 재검증
     */
    function recheckRequirements() {
        // 페이지 리로드로 캐시 문제 해결
        window.location.reload();
    }

    /**
     * 요구사항 검증 자동 실행
     */
    function initRequirementsCheck() {
        if (document.getElementById('requirements-result')) {
            checkRequirements();
        }
    }

    // =========================================================================
    // Step 3: DB 연결 테스트
    // =========================================================================

    /**
     * Read DB 필드 토글
     */
    function toggleReadDbFields() {
        const useReadDb = document.getElementById('use-read-db');
        const readDbSection = document.getElementById('read-db-section');

        if (useReadDb && readDbSection) {
            if (useReadDb.checked) {
                // Read DB 필드 표시
                readDbSection.classList.remove('hidden');
            } else {
                // Read DB 필드 숨김
                readDbSection.classList.add('hidden');

                // 입력값 초기화
                const readDbInputs = readDbSection.querySelectorAll('input');
                readDbInputs.forEach(input => {
                    input.value = '';
                });

                // Read DB 테스트 플래그 제거
                sessionStorage.removeItem('db_read_tested');
                sessionStorage.removeItem('db_read_hash');

                // FormValidator 업데이트
                if (typeof FormValidator !== 'undefined') {
                    FormValidator.setDbTestValid('read', false);
                }

                // 결과 메시지 숨김
                const readResultDiv = document.getElementById('db-read-test-result');
                if (readResultDiv) {
                    readResultDiv.classList.add('hidden');
                    readResultDiv.innerHTML = '';
                }
            }
        }
    }

    /**
     * DB 필드 해시 계산 (간단한 해시 함수)
     *
     * @param {Object} fields {host, port, database, username}
     * @return {string} 해시 문자열
     */
    function calculateDbHash(fields) {
        const str = JSON.stringify([
            fields.host || '',
            fields.port || '',
            fields.database || '',
            fields.username || ''
        ]);

        // 간단한 해시 함수 (PHP의 md5와 동일하지 않지만 변경 감지용으로 충분)
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32bit integer
        }
        return hash.toString(36);
    }

    /**
     * DB 연결 테스트 (Write 또는 Read)
     */
    async function testDatabaseConnection(type) {
        const form = document.getElementById('config-form');
        if (!form) return;

        const formData = new FormData(form);
        const prefix = type === 'write' ? 'db_write' : 'db_read';

        const dbData = {
            type: type,
            host: formData.get(`${prefix}_host`),
            port: formData.get(`${prefix}_port`),
            database: formData.get(`${prefix}_database`),
            username: formData.get(`${prefix}_username`),
            password: formData.get(`${prefix}_password`),
            // 기존 테이블 감지 시 g7 시그니처 비교에 사용 (Write DB만 의미 있음)
            db_prefix: formData.get('db_prefix') || 'g7_',
        };

        const resultDiv = document.getElementById(`db-${type}-test-result`);
        if (!resultDiv) return;

        resultDiv.classList.remove('hidden');
        resultDiv.innerHTML = `<div class="loading-text">${lang('testing_connection')}</div>`;

        try {
            // 절대 URL 생성
            const apiUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/check-configuration.php?action=test-db`;
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(dbData)
            });

            const result = await response.json();

            if (result.success) {
                // Write DB 기존 테이블 감지: 인라인 상세 카드로 표시 (이슈 #244 대응)
                // PO 요구: 모달 대신 페이지 본문에 즉시 노출, 백업 동의 체크박스로
                // 다음 단계 진행 여부 제어. 실제 삭제는 Step 5의 db_cleanup task에서 수행.
                let existingCardHtml = '';
                if (type === 'write' && result.existing_tables && result.existing_tables.has_tables) {
                    const info = result.existing_tables;
                    const sev = info.severity;
                    const titleKey = {
                        g7_existing: 'db_existing_g7_title',
                        foreign_data: 'db_existing_foreign_title',
                        mixed: 'db_existing_mixed_title',
                    }[sev] || 'db_existing_generic_title';
                    const descKey = {
                        g7_existing: 'db_existing_g7_desc',
                        foreign_data: 'db_existing_foreign_desc',
                        mixed: 'db_existing_mixed_desc',
                    }[sev] || 'db_existing_generic_desc';

                    const tablesList = (info.all_tables || []).slice(0, 20).join(', ');
                    const dbHost = document.querySelector('input[name="db_write_host"]')?.value || 'localhost';
                    const dbName = document.querySelector('input[name="db_write_database"]')?.value || '';
                    const dbUser = document.querySelector('input[name="db_write_username"]')?.value || 'root';
                    // -p 단독으로 사용해 mysqldump가 비밀번호 prompt를 띄우도록 함 (값 노출 방지)
                    // --databases 옵션으로 db 이름을 명확히 분리하여 -p와 혼동 방지
                    const backupCmd = `mysqldump -h ${dbHost} -u ${dbUser} -p --databases ${dbName} > backup_$(date +%Y%m%d_%H%M%S).sql`;

                    // 현재 동의 상태 복원 (DB 필드 변경 없이 테스트 재실행 시 체크 유지)
                    const alreadyConsented = sessionStorage.getItem('existing_db_action') === 'drop_tables';

                    existingCardHtml = `
                        <div class="alert alert-warning db-existing-tables-card" data-severity="${escapeHtml(sev)}">
                            <div class="db-existing-header">
                                <strong>⚠ ${escapeHtml(lang(titleKey))}</strong>
                            </div>
                            <p class="db-existing-desc">${escapeHtml(lang(descKey))}</p>
                            <p class="db-existing-tables-label"><strong>${escapeHtml(lang('db_existing_tables_list'))}</strong></p>
                            <pre class="db-existing-tables-list">${escapeHtml(tablesList)}</pre>
                            <p class="db-existing-backup-label"><strong>${escapeHtml(lang('db_backup_guide'))}</strong></p>
                            <div class="code-box">
                                <pre>${escapeHtml(backupCmd)}</pre>
                                <button type="button" class="btn-copy" onclick="copyPermissionCommand(this)">${escapeHtml(lang('copy_command'))}</button>
                            </div>
                            <label class="db-backup-confirm-checkbox">
                                <input type="checkbox" id="db-backup-confirmed" onchange="onDbBackupConsentChange(this)" ${alreadyConsented ? 'checked' : ''}>
                                ${escapeHtml(lang('db_backup_confirmed'))}
                            </label>
                        </div>
                    `;
                    window.__g7_existing_tables = info;
                    // FormValidator에 동의 요구 등록
                    if (typeof FormValidator !== 'undefined') {
                        FormValidator.setDbCleanupRequired(true);
                        FormValidator.setDbCleanupConsented(alreadyConsented);
                    }
                } else if (type === 'write') {
                    // 빈 DB → 동의 요구 해제 + sessionStorage 정리
                    window.__g7_existing_tables = null;
                    sessionStorage.removeItem('existing_db_action');
                    if (typeof FormValidator !== 'undefined') {
                        FormValidator.setDbCleanupRequired(false);
                        FormValidator.setDbCleanupConsented(false);
                    }
                }

                resultDiv.innerHTML = `
                    <div class="alert alert-success">
                        ${getIconSvg('completed', '24px', 'test-result-icon')}
                        <span>${escapeHtml(result.message)}</span>
                    </div>
                    ${existingCardHtml}
                `;

                // 현재 DB 필드 해시 계산
                const currentHash = calculateDbHash({
                    host: dbData.host,
                    port: dbData.port,
                    database: dbData.database,
                    username: dbData.username
                });

                // DB 테스트 성공 시 sessionStorage에 저장 (필드 해시 포함)
                if (type === 'write') {
                    sessionStorage.setItem('db_write_tested', 'true');
                    sessionStorage.setItem('db_write_hash', currentHash);
                    // 기존 테이블 감지 정보도 sessionStorage에 저장 (Step 5까지 보존)
                    if (result.existing_tables) {
                        sessionStorage.setItem('db_existing_tables', JSON.stringify(result.existing_tables));
                    } else {
                        sessionStorage.removeItem('db_existing_tables');
                    }
                    if (typeof FormValidator !== 'undefined') {
                        FormValidator.setDbTestValid('write', true);
                    }
                } else if (type === 'read') {
                    sessionStorage.setItem('db_read_tested', 'true');
                    sessionStorage.setItem('db_read_hash', currentHash);
                    if (typeof FormValidator !== 'undefined') {
                        FormValidator.setDbTestValid('read', true);
                    }
                }
            } else {
                resultDiv.innerHTML = `
                    <div class="alert alert-error">
                        ${getIconSvg('failed', '24px', 'test-result-icon')}
                        <span>${escapeHtml(result.message)}</span>
                    </div>
                `;

                // DB 테스트 실패 시 FormValidator 업데이트
                if (typeof FormValidator !== 'undefined') {
                    FormValidator.setDbTestValid(type, false);
                }
            }
        } catch (error) {
            resultDiv.innerHTML = `
                <div class="alert alert-error">
                    ${getIconSvg('failed', '24px', 'test-result-icon')}
                    <span>${lang('connection_failed_prefix')} ${escapeHtml(error.message)}</span>
                </div>
            `;

            // DB 테스트 실패 시 FormValidator 업데이트
            if (typeof FormValidator !== 'undefined') {
                FormValidator.setDbTestValid(type, false);
            }
        }
    }

    /**
     * DB 테스트 관련 이벤트 초기화
     */
    function initDatabaseTest() {
        const useReadDb = document.getElementById('use-read-db');
        if (useReadDb) {
            useReadDb.addEventListener('change', toggleReadDbFields);
        }

        // 전역 함수로 노출 (인라인 onclick에서 사용)
        window.testDatabaseConnection = testDatabaseConnection;
        window.toggleReadDbFields = toggleReadDbFields;
    }

    // =========================================================================
    // Step 3: 실시간 필드 검증
    // =========================================================================

    /**
     * FormValidator - 필드 검증 상태 추적 객체
     */
    const FormValidator = {
        fields: {
            app_name: false,
            app_url: false,
            admin_email: false,
            admin_name: false,
            admin_password: false,
            admin_password_confirm: false
        },
        dbTests: {
            write: false,
            read: false
        },
        // 기존 DB 테이블 감지 시 삭제 동의 추적 (이슈 #244)
        dbCleanup: {
            required: false,    // Write DB 테스트 결과 기존 테이블 감지 여부
            consented: false,   // 사용자가 백업 완료 + 삭제 동의 체크 여부
        },

        /**
         * 필드 검증 상태 설정
         */
        setFieldValid(fieldName, isValid) {
            this.fields[fieldName] = isValid;
            this.updateSubmitButton();
        },

        /**
         * DB 테스트 상태 설정
         */
        setDbTestValid(type, isValid) {
            this.dbTests[type] = isValid;
            this.updateSubmitButton();
        },

        /**
         * 기존 DB 테이블 감지 상태 설정
         */
        setDbCleanupRequired(required) {
            this.dbCleanup.required = !!required;
            this.updateSubmitButton();
        },

        /**
         * 백업 완료 + 삭제 동의 상태 설정
         */
        setDbCleanupConsented(consented) {
            this.dbCleanup.consented = !!consented;
            this.updateSubmitButton();
        },

        /**
         * Step 3 제출 버튼의 disabled 상태를 폼 유효성에 맞춰 동기화.
         * isFormValid()가 false면 버튼 비활성화 + 적절한 title 안내.
         */
        updateSubmitButton() {
            const submitBtn = document.getElementById('submit-btn');
            if (!submitBtn) return;
            // Step 4(확장 선택)에서도 같은 id를 쓰는데, 그쪽은 자체 검증 함수가 처리하므로
            // Step 3 (config-form) 컨텍스트에서만 동작
            const configForm = document.getElementById('config-form');
            if (!configForm) return;

            const valid = this.isFormValid();
            submitBtn.disabled = !valid;
            if (!valid) {
                if (!this.isDbCleanupConsentSatisfied()) {
                    submitBtn.title = lang('error_db_cleanup_consent_required');
                } else if (!this.areDbTestsValid()) {
                    submitBtn.title = lang('error_write_db_not_tested');
                } else {
                    submitBtn.title = lang('validation_incomplete_alert');
                }
            } else {
                submitBtn.title = '';
            }
        },

        /**
         * 모든 필드가 유효한지 확인
         */
        areAllFieldsValid() {
            return Object.values(this.fields).every(valid => valid);
        },

        /**
         * DB 테스트 완료 여부 확인
         */
        areDbTestsValid() {
            const useReadDb = document.getElementById('use-read-db');
            const writeValid = this.dbTests.write;
            const readValid = useReadDb && useReadDb.checked ? this.dbTests.read : true;
            return writeValid && readValid;
        },

        /**
         * 기존 DB 테이블 삭제 동의 충족 여부
         * required=false면 항상 통과, required=true면 consented=true 필요
         */
        isDbCleanupConsentSatisfied() {
            return !this.dbCleanup.required || this.dbCleanup.consented;
        },

        /**
         * 전체 폼이 유효한지 확인
         */
        isFormValid() {
            return this.areAllFieldsValid() && this.areDbTestsValid() && this.isDbCleanupConsentSatisfied();
        },

        /**
         * 초기 검증 상태 로드 (PHP 세션과 동기화)
         */
        loadInitialState() {
            // PHP 세션에서 전달된 DB 테스트 플래그 사용 (window.DB_TEST_FLAGS)
            const phpFlags = window.DB_TEST_FLAGS || { write: false, read: false, writeHash: '', readHash: '' };

            // Write DB: PHP 세션 플래그 확인 및 필드 변경 감지
            if (phpFlags.write) {
                // sessionStorage의 해시와 PHP에서 계산한 해시 비교
                const storedHash = sessionStorage.getItem('db_write_hash');
                if (storedHash && storedHash === phpFlags.writeHash) {
                    // 필드가 변경되지 않았음 - 테스트 유효
                    this.dbTests.write = true;
                    sessionStorage.setItem('db_write_tested', 'true');
                } else {
                    // 필드가 변경되었거나 해시가 없음 - 테스트 무효화
                    this.dbTests.write = false;
                    sessionStorage.removeItem('db_write_tested');
                    sessionStorage.removeItem('db_write_hash');
                }
            } else {
                // PHP 세션에 플래그가 없음 - 테스트 무효화
                this.dbTests.write = false;
                sessionStorage.removeItem('db_write_tested');
                sessionStorage.removeItem('db_write_hash');
            }

            // Read DB: PHP 세션 플래그 확인 및 필드 변경 감지
            if (phpFlags.read) {
                const storedHash = sessionStorage.getItem('db_read_hash');
                if (storedHash && storedHash === phpFlags.readHash) {
                    // 필드가 변경되지 않았음 - 테스트 유효
                    this.dbTests.read = true;
                    sessionStorage.setItem('db_read_tested', 'true');
                } else {
                    // 필드가 변경되었거나 해시가 없음 - 테스트 무효화
                    this.dbTests.read = false;
                    sessionStorage.removeItem('db_read_tested');
                    sessionStorage.removeItem('db_read_hash');
                }
            } else {
                // PHP 세션에 플래그가 없음 - 테스트 무효화
                this.dbTests.read = false;
                sessionStorage.removeItem('db_read_tested');
                sessionStorage.removeItem('db_read_hash');
            }

            // 초기 필드 값이 있으면 검증
            Object.keys(this.fields).forEach(fieldName => {
                const field = document.getElementById(fieldName);
                if (field && field.value.trim()) {
                    validateField(field);
                }
            });

            // DB 필드 변경 감지 리스너 추가
            this.addDbFieldChangeListeners();

            // 초기 submit 버튼 disabled 상태 동기화
            this.updateSubmitButton();
        },

        /**
         * DB 필드 변경 감지 리스너 추가
         */
        addDbFieldChangeListeners() {
            // Write DB 필드
            const writeFields = ['db_write_host', 'db_write_port', 'db_write_database', 'db_write_username', 'db_write_password'];
            writeFields.forEach(fieldName => {
                const field = document.querySelector(`[name="${fieldName}"]`);
                if (field) {
                    field.addEventListener('input', () => {
                        // Write DB 필드가 변경되면 테스트 플래그 무효화
                        this.setDbTestValid('write', false);
                        sessionStorage.removeItem('db_write_tested');
                        sessionStorage.removeItem('db_write_hash');

                        // 테스트 결과 메시지 숨김
                        const resultDiv = document.getElementById('db-write-test-result');
                        if (resultDiv) {
                            resultDiv.classList.add('hidden');
                            resultDiv.innerHTML = '';
                        }
                    });
                }
            });

            // Read DB 필드
            const readFields = ['db_read_host', 'db_read_port', 'db_read_database', 'db_read_username', 'db_read_password'];
            readFields.forEach(fieldName => {
                const field = document.querySelector(`[name="${fieldName}"]`);
                if (field) {
                    field.addEventListener('input', () => {
                        // Read DB 필드가 변경되면 테스트 플래그 무효화
                        this.setDbTestValid('read', false);
                        sessionStorage.removeItem('db_read_tested');
                        sessionStorage.removeItem('db_read_hash');

                        // 테스트 결과 메시지 숨김
                        const resultDiv = document.getElementById('db-read-test-result');
                        if (resultDiv) {
                            resultDiv.classList.add('hidden');
                            resultDiv.innerHTML = '';
                        }
                    });
                }
            });
        }
    };

    /**
     * 필드 에러 메시지 표시
     */
    function showFieldError(field, message) {
        const errorDiv = document.getElementById(field.id + '-error');
        if (errorDiv) {
            errorDiv.textContent = message;
            errorDiv.classList.add('active');
        }
        field.classList.add('error');
        field.setAttribute('aria-invalid', 'true');

        FormValidator.setFieldValid(field.id, false);
    }

    /**
     * 필드 에러 메시지 제거
     */
    function clearFieldError(field) {
        const errorDiv = document.getElementById(field.id + '-error');
        if (errorDiv) {
            errorDiv.textContent = '';
            errorDiv.classList.remove('active');
        }
        field.classList.remove('error');
        field.setAttribute('aria-invalid', 'false');

        FormValidator.setFieldValid(field.id, true);
    }

    /**
     * 필드 검증
     */
    function validateField(field) {
        const fieldName = field.name;
        const value = field.value.trim();

        // 필수 필드 검증
        if (field.hasAttribute('required') && !value) {
            showFieldError(field, getLangMessage('error_field_required'));
            return false;
        }

        // 이메일 검증
        if (fieldName === 'admin_email') {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(value)) {
                showFieldError(field, getLangMessage('error_admin_email_invalid'));
                return false;
            }
        }

        // 비밀번호 최소 길이 검증
        if (fieldName === 'admin_password') {
            if (value.length < 8) {
                showFieldError(field, getLangMessage('error_admin_password_min'));
                return false;
            }
        }

        // 비밀번호 확인 검증
        if (fieldName === 'admin_password_confirm') {
            const passwordField = document.getElementById('admin_password');
            if (passwordField && value !== passwordField.value) {
                showFieldError(field, getLangMessage('error_password_mismatch'));
                return false;
            }
        }

        // 사이트 이름 검증
        if (fieldName === 'app_name' && !value) {
            showFieldError(field, getLangMessage('error_app_name_required'));
            return false;
        }

        // 사이트 URL 검증
        if (fieldName === 'app_url' && !value) {
            showFieldError(field, getLangMessage('error_app_url_required'));
            return false;
        }

        // DB Prefix 검증 (선택 사항이지만, 입력 시 형식 검증)
        if (fieldName === 'db_prefix' && value) {
            // 영문 소문자로 시작해야 함
            if (!/^[a-z]/.test(value)) {
                showFieldError(field, getLangMessage('validation_starts_with_alpha').replace(':field', getLangMessage('fields.db_prefix')));
                return false;
            }
            // 영문 소문자, 숫자, 언더스코어만 허용
            if (!/^[a-z][a-z0-9_]*$/.test(value)) {
                showFieldError(field, getLangMessage('validation_alpha_num_underscore').replace(':field', getLangMessage('fields.db_prefix')));
                return false;
            }
        }

        // 관리자 이름 검증
        if (fieldName === 'admin_name' && !value) {
            showFieldError(field, getLangMessage('error_admin_name_required'));
            return false;
        }

        // 검증 통과
        clearFieldError(field);
        return true;
    }

    /**
     * 다국어 메시지 가져오기
     */
    function getLangMessage(key) {
        return lang(key);
    }

    /**
     * 실시간 검증 초기화
     */
    function initRealTimeValidation() {
        // Step 3 (config-form)이 있는 경우에만 실행
        const configForm = document.getElementById('config-form');
        if (!configForm) return;

        const fieldsToValidate = [
            'app_name',
            'app_url',
            'db_prefix',
            'admin_email',
            'admin_name',
            'admin_password',
            'admin_password_confirm'
        ];

        fieldsToValidate.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('blur', function() {
                    validateField(this);
                });

                // 비밀번호 확인 필드는 비밀번호 필드 변경 시에도 재검증
                if (fieldId === 'admin_password') {
                    field.addEventListener('input', function() {
                        const confirmField = document.getElementById('admin_password_confirm');
                        if (confirmField && confirmField.value) {
                            validateField(confirmField);
                        }
                    });
                }
            }
        });

        // 초기 상태 로드
        FormValidator.loadInitialState();

        // 버튼 클릭 시 검증
        const submitBtn = document.getElementById('submit-btn');
        if (submitBtn) {
            submitBtn.addEventListener('click', function(e) {
                // 폼이 유효하지 않으면 제출 방지 및 경고 표시
                if (!FormValidator.isFormValid()) {
                    e.preventDefault();
                    showValidationWarning();
                }
            });
        }
    }

    /**
     * 검증 실패 경고 표시
     */
    function showValidationWarning() {
        const errors = [];
        let firstInvalidField = null;

        // 필드 검증 상태 확인 (순서대로)
        Object.keys(FormValidator.fields).forEach(fieldName => {
            if (!FormValidator.fields[fieldName]) {
                const field = document.getElementById(fieldName);
                if (field) {
                    // 첫 번째 실패 필드 저장
                    if (!firstInvalidField) {
                        firstInvalidField = field;
                    }

                    const label = field.closest('.form-group').querySelector('.form-label');
                    errors.push(label ? label.textContent.replace(' *', '') : fieldName);
                }
            }
        });

        // DB 테스트 상태 확인
        if (!FormValidator.dbTests.write) {
            errors.push(getLangMessage('error_write_db_not_tested'));
            // Write DB 테스트가 안 되었으면 첫 번째 Write DB 필드로 포커스
            if (!firstInvalidField) {
                firstInvalidField = document.querySelector('[name="db_write_host"]');
            }
        }
        const useReadDb = document.getElementById('use-read-db');
        if (useReadDb && useReadDb.checked && !FormValidator.dbTests.read) {
            errors.push(getLangMessage('error_read_db_not_tested'));
            // Read DB 테스트가 안 되었고 다른 실패 필드가 없으면 Read DB 필드로 포커스
            if (!firstInvalidField && FormValidator.dbTests.write) {
                firstInvalidField = document.querySelector('[name="db_read_host"]');
            }
        }

        // 기존 DB 테이블 감지 동의 미체크 시 경고
        if (!FormValidator.isDbCleanupConsentSatisfied()) {
            errors.push(lang('error_db_cleanup_consent_required'));
            if (!firstInvalidField) {
                firstInvalidField = document.getElementById('db-backup-confirmed');
            }
        }

        // Alert 표시 (간단한 메시지만)
        alert(lang('validation_incomplete_alert'));

        // 경고 박스 표시 (기존 alert 박스가 있다면 사용, 없으면 생성)
        let warningBox = document.querySelector('.validation-warning');
        if (!warningBox) {
            warningBox = document.createElement('div');
            warningBox.className = 'alert alert-error validation-warning';
            warningBox.style.marginBottom = '1.5rem';

            const form = document.getElementById('config-form');
            if (form) {
                form.insertBefore(warningBox, form.firstChild);
            }
        }

        warningBox.innerHTML = `
            <strong>${lang('validation_incomplete_title')}</strong>
            <ul class="alert-list">
                ${errors.map(error => `<li>${escapeHtml(error)}</li>`).join('')}
            </ul>
        `;

        // 페이지 타이틀로 스크롤 (경고 박스가 잘 보이도록)
        const titleElement = document.querySelector('.installer-title');
        if (titleElement) {
            titleElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // 첫 번째 실패 필드로 포커스 이동
        if (firstInvalidField) {
            setTimeout(() => {
                firstInvalidField.focus();
            }, 400); // 스크롤 완료 후 포커스
        }
    }

    /**
     * Step 3 폼 유효성 검증
     */
    function validateStep3Form() {
        const form = document.getElementById('config-form');
        if (!form) return true;

        const errors = [];

        // Write DB 필수 필드 확인
        const dbWriteHost = form.querySelector('[name="db_write_host"]');
        const dbWriteDatabase = form.querySelector('[name="db_write_database"]');
        const dbWriteUsername = form.querySelector('[name="db_write_username"]');

        if (!dbWriteHost || !dbWriteHost.value.trim()) {
            errors.push({ field: dbWriteHost, message: lang('error_db_host_required') });
        }
        if (!dbWriteDatabase || !dbWriteDatabase.value.trim()) {
            errors.push({ field: dbWriteDatabase, message: lang('error_db_name_required') });
        }
        if (!dbWriteUsername || !dbWriteUsername.value.trim()) {
            errors.push({ field: dbWriteUsername, message: lang('error_db_username_required') });
        }

        // Read DB 사용 시 Read DB 필드 확인
        const useReadDb = document.getElementById('use-read-db');
        if (useReadDb && useReadDb.checked) {
            const dbReadHost = form.querySelector('[name="db_read_host"]');
            const dbReadDatabase = form.querySelector('[name="db_read_database"]');
            const dbReadUsername = form.querySelector('[name="db_read_username"]');

            if (!dbReadHost || !dbReadHost.value.trim()) {
                errors.push({ field: dbReadHost, message: lang('error_db_host_required') });
            }
            if (!dbReadDatabase || !dbReadDatabase.value.trim()) {
                errors.push({ field: dbReadDatabase, message: lang('error_db_name_required') });
            }
            if (!dbReadUsername || !dbReadUsername.value.trim()) {
                errors.push({ field: dbReadUsername, message: lang('error_db_username_required') });
            }
        }

        // 사이트 설정 필수 필드 확인
        const appName = form.querySelector('[name="app_name"]');
        const appUrl = form.querySelector('[name="app_url"]');

        if (!appName || !appName.value.trim()) {
            errors.push({ field: appName, message: lang('error_app_name_required') });
        }
        if (!appUrl || !appUrl.value.trim()) {
            errors.push({ field: appUrl, message: lang('error_app_url_required') });
        }

        // 관리자 계정 필수 필드 확인
        const adminName = form.querySelector('[name="admin_name"]');
        const adminEmail = form.querySelector('[name="admin_email"]');
        const adminPassword = form.querySelector('[name="admin_password"]');
        const adminPasswordConfirm = form.querySelector('[name="admin_password_confirm"]');

        if (!adminName || !adminName.value.trim()) {
            errors.push({ field: adminName, message: lang('error_admin_name_required') });
        }

        // 이메일 형식 검증
        if (!adminEmail || !adminEmail.value.trim()) {
            errors.push({ field: adminEmail, message: lang('error_admin_email_invalid') });
        } else {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(adminEmail.value.trim())) {
                errors.push({ field: adminEmail, message: lang('error_admin_email_invalid') });
            }
        }

        // 비밀번호 최소 8자 확인
        if (!adminPassword || !adminPassword.value) {
            errors.push({ field: adminPassword, message: lang('error_admin_password_required') });
        } else if (adminPassword.value.length < 8) {
            errors.push({ field: adminPassword, message: lang('error_admin_password_min') });
        }

        // 비밀번호 일치 여부 확인
        if (!adminPasswordConfirm || !adminPasswordConfirm.value) {
            errors.push({ field: adminPasswordConfirm, message: lang('error_admin_password_confirm_required') });
        } else if (adminPassword && adminPassword.value !== adminPasswordConfirm.value) {
            errors.push({ field: adminPasswordConfirm, message: lang('error_password_mismatch') });
        }

        // DB 연결 테스트 완료 확인
        const writeTestPassed = sessionStorage.getItem('db_write_tested') === 'true';
        if (!writeTestPassed) {
            errors.push({ field: null, message: lang('error_db_not_tested') });
        }

        // Read DB 사용 시 Read DB 연결 테스트 완료 확인
        if (useReadDb && useReadDb.checked) {
            const readTestPassed = sessionStorage.getItem('db_read_tested') === 'true';
            if (!readTestPassed) {
                errors.push({ field: null, message: lang('error_read_db_not_tested') });
            }
        }

        // PHP CLI / Composer 검증 확인
        if (window.CliValidator) {
            const cliSection = document.getElementById('php-cli-section');
            const cliRequired = window.CliValidator.cliRequired;
            const cliSectionVisible = cliSection && !cliSection.classList.contains('hidden');

            if (cliRequired || cliSectionVisible) {
                if (!window.CliValidator.phpVerified) {
                    errors.push({ field: document.getElementById('php_binary'), message: lang('error_php_cli_not_verified') });
                }
                // vendor_mode='bundled' 에서는 composer 실행이 불필요하므로 검증 스킵
                if (window.CliValidator.isComposerRequired() && !window.CliValidator.composerVerified) {
                    errors.push({ field: document.getElementById('composer_binary'), message: lang('error_composer_not_verified') });
                }
            }
        }

        // 에러가 있으면 표시하고 제출 방지
        if (errors.length > 0) {
            alert(errors.map(e => e.message).join('\n'));

            // 첫 번째 에러 필드에 포커스
            if (errors[0].field) {
                errors[0].field.focus();
            }

            return false;
        }

        return true;
    }

    /**
     * Step 3 폼 제출 이벤트 초기화
     */
    function initStep3FormSubmit() {
        const form = document.getElementById('config-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                if (!validateStep3Form()) {
                    e.preventDefault();
                    return false;
                }
            });
        }
    }

    // =========================================================================
    // Step 4: 확장 선택
    // =========================================================================

    let extensionsData = null;
    let selectedExtensions = {
        admin_templates: [],
        user_templates: [],
        modules: [],
        plugins: [],
        language_packs: []
    };

    /** 의존성에 의해 자동 선택된 확장 identifier 집합 */
    const autoSelectedSet = new Set();

    async function loadExtensions() {
        const form = document.getElementById('extension-form');
        const loadingEl = document.getElementById('extensions-loading');
        const errorEl = document.getElementById('extensions-error');
        const errorMsgEl = document.getElementById('extensions-error-message');

        // 각 섹션의 그리드 요소
        const adminGrid = document.getElementById('admin-templates-list');
        const userGrid = document.getElementById('user-templates-list');
        const modulesGrid = document.getElementById('modules-list');
        const pluginsGrid = document.getElementById('plugins-list');

        // empty 메시지 요소
        const userEmpty = document.getElementById('user-templates-empty');
        const modulesEmpty = document.getElementById('modules-empty');
        const pluginsEmpty = document.getElementById('plugins-empty');

        if (loadingEl) loadingEl.classList.remove('hidden');
        if (errorEl) errorEl.classList.add('hidden');
        if (form) form.classList.add('hidden');

        try {
            // 캐시 방지를 위해 타임스탬프 추가
            const response = await fetch(INSTALLER_BASE_URL + '/api/scan-extensions.php?action=get', {
                cache: 'no-store',
                headers: { 'Cache-Control': 'no-cache' }
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);

            const result = await response.json();
            if (!result.success) throw new Error(result.error_message || result.error || window.EXTENSION_LABELS?.extension_load_failed || 'Failed to load extensions');

            extensionsData = result.data;
            if (loadingEl) loadingEl.classList.add('hidden');
            if (form) form.classList.remove('hidden');

            // 각 그리드에 카드 렌더링
            renderExtensionCards(adminGrid, 'admin_templates', extensionsData.admin_templates || [], true);
            renderExtensionCards(userGrid, 'user_templates', extensionsData.user_templates || [], false, userEmpty);
            renderExtensionCards(modulesGrid, 'modules', extensionsData.modules || [], false, modulesEmpty);
            renderExtensionCards(pluginsGrid, 'plugins', extensionsData.plugins || [], false, pluginsEmpty);

            // 언어팩 — locale 별 서브헤딩으로 그룹 렌더
            renderLanguagePackGroups(
                document.getElementById('language-packs-locale-groups'),
                extensionsData.language_packs || [],
                document.getElementById('language-packs-empty')
            );

            restoreSelectionState();
            updateSelectionSummary();
            autoSelectMissingDependencies();
            validateAdminTemplate();
            applyLanguagePackCascadeState();
            attachBulkToggleHandlers();
            updateBulkToggleStates();
        } catch (error) {
            if (loadingEl) loadingEl.classList.add('hidden');
            if (errorEl) {
                errorEl.classList.remove('hidden');
                if (errorMsgEl) errorMsgEl.textContent = error.message;
            }
        }
    }

    /**
     * 확장 기능 카드들을 그리드에 렌더링
     * @param {HTMLElement} grid - 그리드 요소
     * @param {string} type - 확장 타입 (admin_templates, user_templates, modules, plugins)
     * @param {Array} items - 확장 기능 목록
     * @param {boolean} _isRequired - 필수 선택 여부 (admin_templates) - 미사용, type으로 판별
     * @param {HTMLElement} emptyEl - 비어있을 때 표시할 요소
     */
    function renderExtensionCards(grid, type, items, _isRequired, emptyEl) {
        if (!grid) return;

        grid.innerHTML = '';

        if (!items || items.length === 0) {
            if (emptyEl) emptyEl.classList.remove('hidden');
            return;
        }

        if (emptyEl) emptyEl.classList.add('hidden');

        // 템플릿은 단일 선택 (라디오 버튼 방식), 모듈/플러그인은 다중 선택
        const isSingleSelect = (type === 'admin_templates' || type === 'user_templates');

        items.forEach(function(item, index) {
            // admin_templates의 첫 번째 항목은 기본 선택 (필수이므로)
            if (type === 'admin_templates' && selectedExtensions.admin_templates.length === 0 && index === 0) {
                selectedExtensions.admin_templates = [item.identifier];
            }
            // user_templates는 선택사항이므로 기본 선택 없음
            grid.appendChild(createExtensionCard(type, item, isSingleSelect));
        });
    }

    /**
     * 다국어 값에서 현재 언어에 맞는 텍스트 추출
     * @param {string|object} value - 문자열 또는 {ko: '...', en: '...'} 객체
     * @returns {string}
     */
    function getLocalizedText(value) {
        if (!value) return '';
        if (typeof value === 'string') return value;
        // 현재 언어 또는 한국어 또는 영어 우선
        const currentLang = window.installerLocale?.get() || 'ko';
        return value[currentLang] || value.ko || value.en || '';
    }

    /**
     * identifier로 확장 기능 찾기 (의존성 표시용)
     * @param {string} identifier - 확장 식별자
     * @returns {object|null} 확장 정보 또는 null
     */
    function findExtensionById(identifier) {
        if (!extensionsData) return null;

        // 모듈에서 검색
        var modules = extensionsData.modules || [];
        for (var i = 0; i < modules.length; i++) {
            if (modules[i].identifier === identifier) {
                return { ...modules[i], type: 'module' };
            }
        }

        // 플러그인에서 검색
        var plugins = extensionsData.plugins || [];
        for (var j = 0; j < plugins.length; j++) {
            if (plugins[j].identifier === identifier) {
                return { ...plugins[j], type: 'plugin' };
            }
        }

        // 관리자 템플릿에서 검색
        var adminTpls = extensionsData.admin_templates || [];
        for (var k = 0; k < adminTpls.length; k++) {
            if (adminTpls[k].identifier === identifier) {
                return { ...adminTpls[k], type: 'template' };
            }
        }

        // 사용자 템플릿에서 검색
        var userTpls = extensionsData.user_templates || [];
        for (var l = 0; l < userTpls.length; l++) {
            if (userTpls[l].identifier === identifier) {
                return { ...userTpls[l], type: 'template' };
            }
        }

        return null;
    }

    function createExtensionCard(type, item, isSingleSelect) {
        const card = document.createElement('div');
        card.className = 'extension-card';
        card.dataset.type = type;
        card.dataset.identifier = item.identifier;

        const isSelected = selectedExtensions[type] && selectedExtensions[type].indexOf(item.identifier) !== -1;

        if (isSelected) card.classList.add('selected');
        if (isSingleSelect) card.classList.add('single-select');

        // 아이콘 설정
        let iconClass = 'fa-puzzle-piece';
        if (type === 'admin_templates') iconClass = 'fa-shield-alt';
        else if (type === 'user_templates') iconClass = 'fa-palette';
        else if (type === 'modules') iconClass = 'fa-cube';
        else if (type === 'plugins') iconClass = 'fa-plug';

        const name = getLocalizedText(item.name) || item.identifier;
        const description = getLocalizedText(item.description);
        const version = item.version || '1.0.0';
        const author = item.author || '';

        // 의존성 표시 (identifier → name 변환, 모듈/플러그인 아이콘 추가)
        let depsHtml = '';
        if (item.dependencies && item.dependencies.length > 0) {
            const depsText = item.dependencies.map(function(depId) {
                const depInfo = findExtensionById(depId);
                const depName = depInfo ? getLocalizedText(depInfo.name) : depId;
                const depIcon = depInfo ? (depInfo.type === 'plugin' ? 'fa-plug' : 'fa-cube') : 'fa-cube';
                return '<span class="dep-tag"><i class="fas ' + depIcon + '"></i> ' + escapeHtml(depName) + '</span>';
            }).join(' ');
            depsHtml = '<div class="extension-card-footer"><div class="extension-card-dependencies">' +
                '<span class="dep-label">' + (window.EXTENSION_LABELS?.dependencies || lang('dependencies')) + ':</span> ' + depsText + '</div></div>';
        }

        // 선택 표시 아이콘 (단일 선택은 라디오, 다중 선택은 체크박스)
        const selectIcon = isSingleSelect
            ? (isSelected ? '<i class="fas fa-check-circle"></i>' : '<i class="far fa-circle"></i>')
            : (isSelected ? '<i class="fas fa-check-square"></i>' : '<i class="far fa-square"></i>');

        card.innerHTML =
            '<div class="extension-card-header">' +
                '<div class="extension-card-icon"><i class="fas ' + iconClass + '"></i></div>' +
                '<div class="extension-card-info">' +
                    '<h4 class="extension-card-name">' + escapeHtml(name) + '</h4>' +
                    '<div class="extension-card-meta">v' + escapeHtml(version) + (author ? ' · ' + escapeHtml(author) : '') + '</div>' +
                '</div>' +
                '<div class="extension-card-select">' + selectIcon + '</div>' +
            '</div>' +
            (description ? '<p class="extension-card-description">' + escapeHtml(description) + '</p>' : '') +
            depsHtml;

        card.addEventListener('click', function() {
            toggleExtension(type, item.identifier, isSingleSelect);
        });

        return card;
    }

    /**
     * HTML 이스케이프
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * 번들 언어팩 — locale 별 서브헤딩 + extension-grid 렌더링
     * @param {HTMLElement} container - 컨테이너 요소
     * @param {Array} items - 번들 언어팩 목록 (scan-extensions.php 응답)
     * @param {HTMLElement} emptyEl - 비어있을 때 표시할 요소
     */
    function renderLanguagePackGroups(container, items, emptyEl) {
        if (!container) return;
        container.innerHTML = '';

        if (!items || items.length === 0) {
            if (emptyEl) emptyEl.classList.remove('hidden');
            return;
        }
        if (emptyEl) emptyEl.classList.add('hidden');

        // locale 키로 그룹핑 (Map) — 입력 순서 유지
        const groups = new Map();
        items.forEach(function(item) {
            const locale = item.locale || '';
            if (!groups.has(locale)) groups.set(locale, []);
            groups.get(locale).push(item);
        });

        // locale 알파벳 오름차순 정렬
        const sortedLocales = Array.from(groups.keys()).sort();

        sortedLocales.forEach(function(locale) {
            const groupItems = groups.get(locale);
            const native = groupItems[0].locale_native_name || groupItems[0].locale_name || locale;

            const heading = document.createElement('h4');
            heading.className = 'lp-locale-heading';
            heading.textContent = native + ' (' + locale + ')';
            container.appendChild(heading);

            const grid = document.createElement('div');
            grid.className = 'extension-grid lp-locale-grid';
            groupItems.forEach(function(item) {
                grid.appendChild(createLanguagePackCard(item));
            });
            container.appendChild(grid);
        });
    }

    /**
     * 언어팩 카드 DOM 생성 — extension-card 패턴 + scope/target 데이터 속성
     * @param {object} item - 번들 언어팩 매니페스트 데이터
     * @returns {HTMLElement}
     */
    function createLanguagePackCard(item) {
        const card = document.createElement('div');
        card.className = 'extension-card';
        card.dataset.type = 'language_packs';
        card.dataset.identifier = item.identifier;
        card.dataset.scope = item.scope || 'core';
        card.dataset.targetIdentifier = item.target_identifier || '';
        card.dataset.locale = item.locale || '';

        const isSelected = selectedExtensions.language_packs.indexOf(item.identifier) !== -1;
        if (isSelected) card.classList.add('selected');

        const name = (typeof item.name === 'string') ? item.name : (getLocalizedText(item.name) || item.identifier);
        const description = getLocalizedText(item.description);
        const version = item.version || '1.0.0';
        const author = item.author || '';

        const scope = item.scope || 'core';
        const scopeLabelKey = 'language_pack_scope_' + scope;
        const scopeLabel = (window.EXTENSION_LABELS && window.EXTENSION_LABELS[scopeLabelKey]) || scope;

        // 종속 확장의 실제 이름(현재 locale) + 식별자 동시 표시 — 미발견 시 식별자만
        let targetText = '';
        if (item.target_identifier) {
            const targetInfo = findExtensionById(item.target_identifier);
            const targetName = targetInfo ? getLocalizedText(targetInfo.name) : '';
            if (targetName && targetName !== item.target_identifier) {
                targetText = ' · ' + escapeHtml(targetName) + ' (' + escapeHtml(item.target_identifier) + ')';
            } else {
                targetText = ' · ' + escapeHtml(item.target_identifier);
            }
        }

        const scopeBadgeHtml =
            '<span class="lp-scope-badge lp-scope-' + escapeHtml(scope) + '">' +
                escapeHtml(scopeLabel) +
            '</span>' +
            (targetText ? '<span class="lp-target-text">' + targetText + '</span>' : '');

        const selectIcon = isSelected
            ? '<i class="fas fa-check-square"></i>'
            : '<i class="far fa-square"></i>';

        card.innerHTML =
            '<div class="extension-card-header">' +
                '<div class="extension-card-icon"><i class="fas fa-language"></i></div>' +
                '<div class="extension-card-info">' +
                    '<h4 class="extension-card-name">' + escapeHtml(name) + '</h4>' +
                    '<div class="extension-card-meta">v' + escapeHtml(version) + (author ? ' · ' + escapeHtml(author) : '') + '</div>' +
                '</div>' +
                '<div class="extension-card-select">' + selectIcon + '</div>' +
            '</div>' +
            (description ? '<p class="extension-card-description">' + escapeHtml(description) + '</p>' : '') +
            '<div class="extension-card-footer"><div class="extension-card-dependencies">' + scopeBadgeHtml + '</div></div>';

        card.addEventListener('click', function() {
            if (card.classList.contains('disabled')) return;
            toggleExtension('language_packs', item.identifier, false);
        });

        return card;
    }

    /**
     * 언어팩 cascade 상태 갱신 — 선택된 확장과 매칭 검사 후 disabled/자동 해제
     *
     * 규칙:
     * - scope === 'core' → 항상 활성
     * - scope === 'module' → selectedExtensions.modules 에 target_identifier 가 있어야 활성
     * - scope === 'plugin' → selectedExtensions.plugins 에 있어야 활성
     * - scope === 'template' → admin_templates 또는 user_templates 에 있어야 활성
     * - 비활성 진입 시 selectedExtensions.language_packs 에서 자동 제거
     *
     * 호출 시점: loadExtensions, toggleExtension, restoreSelectionState 마지막
     */
    function applyLanguagePackCascadeState() {
        const cards = document.querySelectorAll('.extension-card[data-type="language_packs"]');
        let changed = false;
        const reasonText = (window.EXTENSION_LABELS && window.EXTENSION_LABELS.language_pack_disabled_by_extension) || '';

        cards.forEach(function(card) {
            const scope = card.dataset.scope || 'core';
            const target = card.dataset.targetIdentifier || '';
            const id = card.dataset.identifier;

            let disabled = false;
            if (scope !== 'core' && target) {
                let matched = false;
                if (scope === 'module') {
                    matched = selectedExtensions.modules.indexOf(target) !== -1;
                } else if (scope === 'plugin') {
                    matched = selectedExtensions.plugins.indexOf(target) !== -1;
                } else if (scope === 'template') {
                    matched = (selectedExtensions.admin_templates.indexOf(target) !== -1) ||
                              (selectedExtensions.user_templates.indexOf(target) !== -1);
                }
                if (!matched) disabled = true;
            }

            if (disabled) {
                card.classList.add('disabled');
                card.title = reasonText;
                const idx = selectedExtensions.language_packs.indexOf(id);
                if (idx !== -1) {
                    selectedExtensions.language_packs.splice(idx, 1);
                    changed = true;
                }
                card.classList.remove('selected');
                const sel = card.querySelector('.extension-card-select');
                if (sel) sel.innerHTML = '<i class="far fa-square"></i>';
            } else {
                card.classList.remove('disabled');
                card.title = '';
            }
        });

        if (changed) {
            updateSelectionSummary();
        }
    }

    /**
     * 일괄 토글 가능한 다중 선택 카테고리 — admin_templates 는 단일 선택 + 필수 이므로 제외
     */
    const BULK_TOGGLE_CATEGORIES = ['user_templates', 'modules', 'plugins', 'language_packs'];

    /**
     * 카테고리 내에서 현재 선택 가능한 항목(disabled 제외) 식별자 목록 반환
     * @param {string} category
     * @returns {Array<string>}
     */
    function getSelectableIdentifiers(category) {
        if (!extensionsData) return [];
        const items = extensionsData[category] || [];
        const ids = [];
        items.forEach(function(item) {
            // language_packs 는 disabled 카드 제외
            if (category === 'language_packs') {
                const card = document.querySelector(
                    '.extension-card[data-type="language_packs"][data-identifier="' + item.identifier + '"]'
                );
                if (card && card.classList.contains('disabled')) return;
            }
            ids.push(item.identifier);
        });
        return ids;
    }

    /**
     * 섹션 단위 일괄 토글 — 가능 항목 모두 선택 / 모두 해제
     * @param {string} category
     * @param {boolean} select - true=전체 선택, false=전체 해제
     */
    function bulkToggleSection(category, select) {
        if (BULK_TOGGLE_CATEGORIES.indexOf(category) === -1) return;

        // user_templates 는 단일 선택 — 일괄 선택 시 마지막 항목 1개만 선택, 일괄 해제는 정상
        const isSingleSelect = (category === 'user_templates');
        const targetIds = getSelectableIdentifiers(category);

        if (select) {
            if (isSingleSelect) {
                // 첫 번째 가능 항목 선택 (이미 선택되어 있지 않다면)
                if (targetIds.length > 0 && selectedExtensions[category].indexOf(targetIds[0]) === -1) {
                    toggleExtension(category, targetIds[0], true);
                }
            } else {
                targetIds.forEach(function(id) {
                    if (selectedExtensions[category].indexOf(id) === -1) {
                        toggleExtension(category, id, false);
                    }
                });
            }
        } else {
            // 전체 해제 — 의존성에 의해 차단된 항목은 toggleExtension 가 차단하므로 정상 동작
            const currentSelected = selectedExtensions[category].slice();
            currentSelected.forEach(function(id) {
                toggleExtension(category, id, isSingleSelect);
            });
        }

        updateBulkToggleStates();
    }

    /**
     * 마스터 토글 — 모든 다중 선택 카테고리에 일괄 적용
     * 순서: 모듈/플러그인 먼저 선택 → cascade 갱신 → 언어팩 선택 (disabled 풀린 후 적용 가능)
     */
    function bulkToggleMaster(select) {
        // 선택 시: 모듈/플러그인/사용자템플릿 먼저, language_packs 는 cascade 갱신 후 마지막
        // 해제 시: language_packs 먼저, 그 다음 다른 카테고리 (역순)
        const order = select
            ? ['user_templates', 'modules', 'plugins', 'language_packs']
            : ['language_packs', 'plugins', 'modules', 'user_templates'];

        order.forEach(function(cat) {
            bulkToggleSection(cat, select);
        });
    }

    /**
     * 모든 일괄 토글 체크박스의 표시 상태를 현재 selectedExtensions 기준으로 갱신
     */
    function updateBulkToggleStates() {
        BULK_TOGGLE_CATEGORIES.forEach(function(cat) {
            const cb = document.querySelector('.bulk-toggle-checkbox[data-bulk-target="' + cat + '"]');
            if (!cb) return;
            const selectable = getSelectableIdentifiers(cat);
            if (selectable.length === 0) {
                cb.checked = false;
                cb.indeterminate = false;
                cb.disabled = true;
                return;
            }
            cb.disabled = false;
            const selected = selectedExtensions[cat] || [];
            const allSelected = selectable.every(function(id) { return selected.indexOf(id) !== -1; });
            const anySelected = selectable.some(function(id) { return selected.indexOf(id) !== -1; });
            cb.checked = allSelected;
            cb.indeterminate = !allSelected && anySelected;
        });

        // 마스터 토글 — 모든 카테고리가 전체 선택 상태일 때만 ON, 일부면 indeterminate
        const master = document.getElementById('bulk-toggle-all');
        if (master) {
            const allFull = BULK_TOGGLE_CATEGORIES.every(function(cat) {
                const cb = document.querySelector('.bulk-toggle-checkbox[data-bulk-target="' + cat + '"]');
                return cb && cb.checked;
            });
            const anyOn = BULK_TOGGLE_CATEGORIES.some(function(cat) {
                const cb = document.querySelector('.bulk-toggle-checkbox[data-bulk-target="' + cat + '"]');
                return cb && (cb.checked || cb.indeterminate);
            });
            master.checked = allFull;
            master.indeterminate = !allFull && anyOn;
        }
    }

    /**
     * 일괄 토글 체크박스에 change 핸들러 부착 — 한 번만 부착되도록 데이터 속성으로 가드
     */
    function attachBulkToggleHandlers() {
        document.querySelectorAll('.bulk-toggle-checkbox[data-bulk-target]').forEach(function(cb) {
            if (cb.dataset.handlerAttached === '1') return;
            cb.dataset.handlerAttached = '1';
            cb.addEventListener('change', function() {
                bulkToggleSection(cb.dataset.bulkTarget, cb.checked);
            });
        });
        const master = document.getElementById('bulk-toggle-all');
        if (master && master.dataset.handlerAttached !== '1') {
            master.dataset.handlerAttached = '1';
            master.addEventListener('change', function() {
                bulkToggleMaster(master.checked);
            });
        }
    }

    window.toggleExtension = function(type, identifier, isSingleSelect, _isAutoSelect) {
        const card = document.querySelector('.extension-card[data-type="' + type + '"][data-identifier="' + identifier + '"]');
        if (!card) return;

        const isCurrentlySelected = selectedExtensions[type].indexOf(identifier) !== -1;
        const isRequired = (type === 'admin_templates'); // 관리자 템플릿만 필수

        // 선택 해제 시도 시 — 다른 선택된 확장이 이 항목을 의존하면 차단
        if (isCurrentlySelected && !_isAutoSelect) {
            const dependents = computeDependents(identifier);
            if (dependents.length > 0) {
                const names = dependents.map(function(d) { return d.name; }).join(', ');
                showDependencyLockToast(identifier, names);
                return; // 해제 차단
            }
        }

        if (isSingleSelect) {
            // 단일 선택 모드 — 이전 템플릿 해제 시 자동 선택 항목도 정리
            if (isCurrentlySelected && !isRequired) {
                // 사용자 템플릿: 이미 선택된 항목 클릭 시 선택 해제
                clearAutoSelectedByOwner(type, identifier);
                selectedExtensions[type] = [];
                card.classList.remove('selected');
                const selectEl = card.querySelector('.extension-card-select');
                if (selectEl) selectEl.innerHTML = '<i class="far fa-circle"></i>';
            } else {
                // 다른 항목 선택 해제 전 이전 템플릿의 자동 선택 항목 정리
                const prevId = selectedExtensions[type][0];
                if (prevId && prevId !== identifier) {
                    clearAutoSelectedByOwner(type, prevId);
                }
                const allCards = document.querySelectorAll('.extension-card[data-type="' + type + '"]');
                allCards.forEach(function(c) {
                    c.classList.remove('selected');
                    const selectEl = c.querySelector('.extension-card-select');
                    if (selectEl) selectEl.innerHTML = '<i class="far fa-circle"></i>';
                });

                selectedExtensions[type] = [identifier];
                card.classList.add('selected');
                const selectEl = card.querySelector('.extension-card-select');
                if (selectEl) selectEl.innerHTML = '<i class="fas fa-check-circle"></i>';
            }
        } else {
            // 다중 선택: 토글 방식
            const index = selectedExtensions[type].indexOf(identifier);
            if (index === -1) {
                selectedExtensions[type].push(identifier);
                card.classList.add('selected');
                const selectEl = card.querySelector('.extension-card-select');
                if (selectEl) selectEl.innerHTML = '<i class="fas fa-check-square"></i>';
            } else {
                // 해제 시 자동 선택 표시 제거
                autoSelectedSet.delete(identifier);
                removeAutoSelectedUI(identifier);
                selectedExtensions[type].splice(index, 1);
                card.classList.remove('selected');
                const selectEl = card.querySelector('.extension-card-select');
                if (selectEl) selectEl.innerHTML = '<i class="far fa-square"></i>';
            }
        }

        updateSelectionSummary();
        validateAdminTemplate();

        // 확장 선택 변경 시 언어팩 cascade 상태 갱신 (admin/user/module/plugin 변동 영향)
        if (type !== 'language_packs') {
            applyLanguagePackCascadeState();
        }

        // 선택 변경 시 의존성 즉시 자동 선택 (자동 선택에 의한 재귀 호출 방지)
        if (!_isAutoSelect) {
            autoSelectMissingDependencies();
        }

        // 일괄 토글 체크박스 상태 동기화
        updateBulkToggleStates();
    };

    function restoreSelectionState() {
        if (window.INSTALLER_SELECTED_EXTENSIONS) {
            var saved = window.INSTALLER_SELECTED_EXTENSIONS;
            ['admin_templates', 'user_templates', 'modules', 'plugins', 'language_packs'].forEach(function(type) {
                if (saved[type] && Array.isArray(saved[type])) {
                    selectedExtensions[type] = saved[type].slice();
                }
            });
            Object.keys(selectedExtensions).forEach(function(type) {
                selectedExtensions[type].forEach(function(identifier) {
                    var card = document.querySelector('.extension-card[data-type="' + type + '"][data-identifier="' + identifier + '"]');
                    if (card && !card.classList.contains('required')) {
                        card.classList.add('selected');
                    }
                });
            });
        }
        if (extensionsData) {
            ['admin_templates', 'user_templates', 'modules', 'plugins', 'language_packs'].forEach(function(type) {
                var items = extensionsData[type] || [];
                items.forEach(function(item) {
                    if (item.is_required && selectedExtensions[type].indexOf(item.identifier) === -1) {
                        selectedExtensions[type].push(item.identifier);
                    }
                });
            });
        }
        // 복원 후 언어팩 cascade 상태 동기화 (확장 선택 복원 결과 반영)
        applyLanguagePackCascadeState();
    }

    function updateSelectionSummary() {
        // PHP 뷰 구조에 맞는 개별 요약 요소 업데이트
        const adminCount = document.getElementById('summary-admin-templates');
        const userCount = document.getElementById('summary-user-templates');
        const modulesCount = document.getElementById('summary-modules');
        const pluginsCount = document.getElementById('summary-plugins');
        const lpCount = document.getElementById('summary-language-packs');

        if (adminCount) adminCount.textContent = selectedExtensions.admin_templates.length;
        if (userCount) userCount.textContent = selectedExtensions.user_templates.length;
        if (modulesCount) modulesCount.textContent = selectedExtensions.modules.length;
        if (pluginsCount) pluginsCount.textContent = selectedExtensions.plugins.length;
        if (lpCount) lpCount.textContent = selectedExtensions.language_packs.length;
    }

    /**
     * 관리자 템플릿 + 의존성 검증.
     *
     * - 관리자 템플릿 최소 1개 선택 필수
     * - 선택된 모든 확장의 의존성이 충족되어야 함 (누락 시 설치 버튼 비활성화 + 경고)
     */
    function validateAdminTemplate() {
        return validateExtensionSelection();
    }

    /**
     * 선택된 확장 목록에서 누락된 의존성을 계산합니다.
     *
     * @returns {Array} 누락 목록 [{ownerType, ownerIdentifier, ownerName, depType, depIdentifier}, ...]
     */
    function computeMissingDependencies() {
        if (!extensionsData) return [];

        const selectedIdSet = new Set();
        ['admin_templates', 'user_templates', 'modules', 'plugins', 'language_packs'].forEach((cat) => {
            (selectedExtensions[cat] || []).forEach((id) => selectedIdSet.add(id));
        });

        // 모든 확장의 identifier → version 매핑 (버전 검증용)
        const availableVersions = {};
        ['admin_templates', 'user_templates', 'modules', 'plugins', 'language_packs'].forEach((cat) => {
            (extensionsData[cat] || []).forEach((item) => {
                availableVersions[item.identifier] = item.version || '0.0.0';
            });
        });

        const missing = [];
        const checkOne = (category, item) => {
            if (!selectedIdSet.has(item.identifier)) return;
            const deps = item.dependencies_detailed || [];
            deps.forEach((dep) => {
                if (!dep || !dep.identifier) return;
                if (!selectedIdSet.has(dep.identifier)) {
                    missing.push({
                        ownerType: category,
                        ownerIdentifier: item.identifier,
                        ownerName: (item.name && (item.name.ko || item.name.en)) || item.identifier,
                        depType: dep.type || 'unknown',
                        depIdentifier: dep.identifier,
                        depVersion: dep.version || '*',
                        issue: 'missing',
                    });
                } else if (dep.version && dep.version !== '*') {
                    // 선택되어 있지만 버전 제약 미충족 여부 확인
                    const availVer = availableVersions[dep.identifier];
                    if (availVer && !satisfiesVersionConstraint(availVer, dep.version)) {
                        missing.push({
                            ownerType: category,
                            ownerIdentifier: item.identifier,
                            ownerName: (item.name && (item.name.ko || item.name.en)) || item.identifier,
                            depType: dep.type || 'unknown',
                            depIdentifier: dep.identifier,
                            depVersion: dep.version,
                            availableVersion: availVer,
                            issue: 'version_mismatch',
                        });
                    }
                }
            });
        };

        ['admin_templates', 'user_templates', 'modules', 'plugins', 'language_packs'].forEach((cat) => {
            (extensionsData[cat] || []).forEach((item) => checkOne(cat, item));
        });

        return missing;
    }

    /**
     * 누락된 의존성을 자동으로 selectedExtensions에 추가하고 UI에 반영합니다.
     * 추이적(transitive) 의존성도 자동 해결될 수 있도록 반복 실행됩니다.
     */
    function autoSelectMissingDependencies() {
        if (!extensionsData) return;

        // 카테고리별 identifier → category 역인덱스 구축
        const idToCategory = {};
        ['admin_templates', 'user_templates', 'modules', 'plugins', 'language_packs'].forEach((cat) => {
            (extensionsData[cat] || []).forEach((item) => {
                idToCategory[item.identifier] = cat;
            });
        });

        // 고정점(fixed-point)까지 반복 — 추이적 의존성 자동 해결
        let changed = true;
        let iteration = 0;
        while (changed && iteration < 10) {
            changed = false;
            iteration++;
            const missing = computeMissingDependencies();
            if (missing.length === 0) break;

            missing.forEach((m) => {
                // 의존성 type을 카테고리로 매핑 (modules → modules, plugins → plugins)
                // detailed에 타입이 없는 레거시인 경우 identifier 역인덱스에서 조회
                let targetCat = null;
                if (m.depType === 'modules') targetCat = 'modules';
                else if (m.depType === 'plugins') targetCat = 'plugins';
                else if (idToCategory[m.depIdentifier]) targetCat = idToCategory[m.depIdentifier];

                if (targetCat && !selectedExtensions[targetCat].includes(m.depIdentifier)) {
                    // 자동 선택 추적
                    autoSelectedSet.add(m.depIdentifier);

                    // toggleExtension을 호출하여 selectedExtensions 배열 추가와 카드 UI
                    // (selected 클래스 + extension-card-select 아이콘)을 한 번에 동기화.
                    const isSingleSelect = (targetCat === 'admin_templates' || targetCat === 'user_templates');
                    window.toggleExtension(targetCat, m.depIdentifier, isSingleSelect, true);

                    // 자동 선택 시각적 표시 추가
                    applyAutoSelectedUI(m.depIdentifier, m.ownerName);
                    changed = true;
                }
            });
        }

        updateSelectionSummary();
        validateExtensionSelection();
    }
    window.autoSelectMissingDependencies = autoSelectMissingDependencies;

    /**
     * 관리자 템플릿 + 의존성 그래프 검증.
     */
    function validateExtensionSelection() {
        const submitBtn = document.getElementById('submit-btn');
        const warningBox = document.getElementById('dependency-warning');
        const warningList = document.getElementById('dependency-warning-list');

        const hasAdminTemplate = selectedExtensions.admin_templates.length > 0;
        const missing = computeMissingDependencies();

        // 경고 UI 업데이트
        if (warningBox && warningList) {
            if (missing.length > 0) {
                warningList.innerHTML = '';
                // 소유자별로 중복 제거
                const seen = new Set();
                missing.forEach((m) => {
                    const key = `${m.ownerIdentifier}→${m.depIdentifier}`;
                    if (seen.has(key)) return;
                    seen.add(key);
                    const li = document.createElement('li');
                    if (m.issue === 'version_mismatch') {
                        li.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' +
                            escapeHtml(m.ownerName) + ' → ' + escapeHtml(m.depIdentifier) +
                            ' <span class="dep-version-conflict">(' +
                            escapeHtml(lang('dep_version_required')) + ': ' + escapeHtml(m.depVersion) +
                            ', ' + escapeHtml(lang('dep_version_available')) + ': ' + escapeHtml(m.availableVersion) +
                            ')</span>';
                    } else {
                        li.textContent = `${m.ownerName} (${m.ownerIdentifier}) → ${m.depIdentifier}`;
                    }
                    warningList.appendChild(li);
                });
                warningBox.classList.remove('hidden');
            } else {
                warningBox.classList.add('hidden');
            }
        }

        if (!submitBtn) return;

        const valid = hasAdminTemplate && missing.length === 0;
        submitBtn.disabled = !valid;

        if (!hasAdminTemplate) {
            submitBtn.title = window.EXTENSION_LABELS?.admin_template_required || lang('error_admin_template_required');
        } else if (missing.length > 0) {
            submitBtn.title = lang('dependency_missing_tooltip');
        } else {
            submitBtn.title = '';
        }
    }
    window.validateExtensionSelection = validateExtensionSelection;

    /**
     * 지정된 identifier를 의존하는 현재 선택된 확장 목록을 반환합니다.
     * 선택 해제 잠금 판단에 사용합니다.
     *
     * @param {string} identifier - 해제하려는 확장의 식별자
     * @returns {Array} [{identifier, name, type}, ...] 해당 identifier에 의존하는 선택된 확장 목록
     */
    function computeDependents(identifier) {
        if (!extensionsData) return [];
        const dependents = [];
        const selectedIdSet = new Set();
        ['admin_templates', 'user_templates', 'modules', 'plugins', 'language_packs'].forEach((cat) => {
            (selectedExtensions[cat] || []).forEach((id) => selectedIdSet.add(id));
        });

        ['admin_templates', 'user_templates', 'modules', 'plugins', 'language_packs'].forEach((cat) => {
            (extensionsData[cat] || []).forEach((item) => {
                if (!selectedIdSet.has(item.identifier)) return;
                const deps = item.dependencies_detailed || [];
                deps.forEach((dep) => {
                    if (dep && dep.identifier === identifier) {
                        dependents.push({
                            identifier: item.identifier,
                            name: getLocalizedText(item.name) || item.identifier,
                            type: cat
                        });
                    }
                });
            });
        });
        return dependents;
    }

    /**
     * 자동 선택된 카드에 시각적 표시(배지 + CSS 클래스)를 적용합니다.
     *
     * @param {string} identifier - 자동 선택된 확장 식별자
     * @param {string} ownerName - 이 항목을 요구하는 확장의 이름
     */
    function applyAutoSelectedUI(identifier, ownerName) {
        const card = document.querySelector('.extension-card[data-identifier="' + identifier + '"]');
        if (!card || card.classList.contains('dep-auto-selected')) return;
        card.classList.add('dep-auto-selected');

        // 배지 추가
        const badge = document.createElement('span');
        badge.className = 'dep-auto-badge';
        badge.innerHTML = '<i class="fas fa-link"></i> ' +
            escapeHtml(lang('dep_auto_badge_label')) +
            ' <span class="dep-auto-badge-owner">(' + escapeHtml(ownerName) + ')</span>';
        const infoEl = card.querySelector('.extension-card-info');
        if (infoEl) {
            infoEl.appendChild(badge);
        }
    }

    /**
     * 자동 선택 시각적 표시를 제거합니다.
     *
     * @param {string} identifier - 대상 확장 식별자
     */
    function removeAutoSelectedUI(identifier) {
        const card = document.querySelector('.extension-card[data-identifier="' + identifier + '"]');
        if (!card) return;
        card.classList.remove('dep-auto-selected');
        const badge = card.querySelector('.dep-auto-badge');
        if (badge) badge.remove();
    }

    /**
     * 특정 소유자(템플릿)의 의존성으로 자동 선택된 항목들을 정리합니다.
     * 템플릿 변경/해제 시 이전 템플릿의 자동 선택 항목을 해제합니다.
     * 단, 다른 선택된 확장이 여전히 의존하는 항목은 유지합니다.
     *
     * @param {string} ownerType - 소유자 카테고리
     * @param {string} ownerIdentifier - 소유자 식별자
     */
    function clearAutoSelectedByOwner(ownerType, ownerIdentifier) {
        if (!extensionsData) return;

        // 해제되는 소유자의 의존성 목록 수집
        const ownerItem = (extensionsData[ownerType] || []).find(e => e.identifier === ownerIdentifier);
        if (!ownerItem) return;
        const ownerDeps = (ownerItem.dependencies_detailed || []).map(d => d.identifier);

        ownerDeps.forEach((depId) => {
            if (!autoSelectedSet.has(depId)) return;

            // 다른 선택된 확장이 여전히 이 항목을 의존하는지 확인
            const otherDependents = computeDependents(depId).filter(d => d.identifier !== ownerIdentifier);
            if (otherDependents.length > 0) return; // 다른 의존자 존재 → 유지

            // 자동 선택 해제
            autoSelectedSet.delete(depId);
            removeAutoSelectedUI(depId);

            // 카테고리를 찾아 선택 해제
            ['modules', 'plugins'].forEach((cat) => {
                const idx = selectedExtensions[cat].indexOf(depId);
                if (idx !== -1) {
                    selectedExtensions[cat].splice(idx, 1);
                    const card = document.querySelector('.extension-card[data-type="' + cat + '"][data-identifier="' + depId + '"]');
                    if (card) {
                        card.classList.remove('selected');
                        const selectEl = card.querySelector('.extension-card-select');
                        if (selectEl) selectEl.innerHTML = '<i class="far fa-square"></i>';
                    }
                }
            });
        });
    }

    /**
     * 의존성 잠금으로 인해 선택 해제가 차단될 때 토스트 메시지를 표시합니다.
     *
     * @param {string} identifier - 해제 시도된 확장 식별자
     * @param {string} dependentNames - 의존하는 확장 이름 목록 (콤마 구분)
     */
    function showDependencyLockToast(identifier, dependentNames) {
        // 기존 토스트 제거
        const existing = document.querySelector('.dep-lock-toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = 'dep-lock-toast';
        toast.innerHTML = '<i class="fas fa-lock"></i> ' +
            escapeHtml(lang('dep_lock_message').replace(':names', dependentNames));

        document.body.appendChild(toast);

        // 애니메이션 후 자동 제거
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    /**
     * Semver 버전 제약 조건을 만족하는지 확인합니다.
     *
     * @param {string} available - 사용 가능한 버전 (예: "1.2.3")
     * @param {string} constraint - 제약 조건 (예: ">=1.0.0", "^1.2.0", "*")
     * @returns {boolean} 제약 조건 충족 여부
     */
    function satisfiesVersionConstraint(available, constraint) {
        if (!constraint || constraint === '*') return true;

        // 버전 문자열을 숫자 배열로 파싱
        function parseVersion(v) {
            const clean = v.replace(/^[v=]/, '');
            const parts = clean.split('.').map(Number);
            return [parts[0] || 0, parts[1] || 0, parts[2] || 0];
        }

        // 두 버전 비교: -1(a<b), 0(같음), 1(a>b)
        function compareVersions(a, b) {
            for (let i = 0; i < 3; i++) {
                if (a[i] < b[i]) return -1;
                if (a[i] > b[i]) return 1;
            }
            return 0;
        }

        const avail = parseVersion(available);

        // 연산자 + 버전 분리
        const match = constraint.match(/^([><=^~!]*)\s*(.+)$/);
        if (!match) return true;

        const op = match[1] || '>=';
        const req = parseVersion(match[2]);
        const cmp = compareVersions(avail, req);

        switch (op) {
            case '>=': return cmp >= 0;
            case '>':  return cmp > 0;
            case '<=': return cmp <= 0;
            case '<':  return cmp < 0;
            case '=':
            case '==': return cmp === 0;
            case '!=': return cmp !== 0;
            case '^':
                // ^1.2.3 → >=1.2.3, <2.0.0 (major 고정)
                if (cmp < 0) return false;
                return avail[0] === req[0];
            case '~':
                // ~1.2.3 → >=1.2.3, <1.3.0 (minor 고정)
                if (cmp < 0) return false;
                return avail[0] === req[0] && avail[1] === req[1];
            default:
                return cmp >= 0;
        }
    }

    /**
     * 확장 식별자로 이름 가져오기 (extensionsData에서)
     * @param {string} category 카테고리 (admin_templates, modules 등)
     * @param {string} identifier 확장 식별자
     * @returns {Object|null} 이름 객체 {ko: '...', en: '...'}
     */
    function getExtensionNameFromData(category, identifier) {
        if (!extensionsData) return null;
        const list = extensionsData[category] || [];
        const ext = list.find(e => e.identifier === identifier);
        if (!ext) return null;
        // 언어팩의 name 은 단일 string — {ko, en} 객체 형태로 정규화 (state.json 다운스트림 호환)
        if (typeof ext.name === 'string') {
            return { ko: ext.name, en: ext.name };
        }
        return ext.name;
    }

    async function saveExtensionsAndProceed() {
        const btn = document.getElementById('submit-btn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = window.EXTENSION_LABELS?.saving || lang('saving');
        }

        // 관리자 템플릿 필수 검증
        if (selectedExtensions.admin_templates.length === 0) {
            alert(window.EXTENSION_LABELS?.admin_template_required || lang('error_admin_template_required'));
            if (btn) btn.disabled = false;
            return;
        }

        // 확장 이름 매핑 수집 (identifier → {ko: '...', en: '...'})
        const extensionNames = {};
        const categories = ['admin_templates', 'user_templates', 'modules', 'plugins', 'language_packs'];
        categories.forEach(category => {
            (selectedExtensions[category] || []).forEach(identifier => {
                const name = getExtensionNameFromData(category, identifier);
                if (name) {
                    extensionNames[identifier] = name;
                }
            });
        });

        try {
            const response = await fetch(INSTALLER_BASE_URL + '/api/save-extensions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ...selectedExtensions,
                    extension_names: extensionNames
                })
            });
            const result = await response.json();
            if (!result.success) throw new Error(result.error_message || result.error || window.EXTENSION_LABELS?.save_failed || 'Save failed');
            window.location.href = INSTALLER_BASE_URL + '?step=5';
        } catch (error) {
            alert(error.message);
            if (btn) {
                btn.disabled = false;
                btn.textContent = window.EXTENSION_LABELS?.next || lang('next');
            }
        }
    }
    window.saveExtensionsAndProceed = saveExtensionsAndProceed;
    window.loadExtensions = loadExtensions;

    function initExtensionSelection() {
        const form = document.getElementById('extension-form');
        if (!form) return;
        loadExtensions();

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            saveExtensionsAndProceed();
        });
    }

    // =========================================================================
    // Step 5: 설치 진행
    // =========================================================================

    // 설치 작업 목록 (PHP에서 window.INSTALLER_TASKS로 전달됨)
    const INSTALLATION_TASKS = window.INSTALLER_TASKS || [];

    let installationStarted = false;
    let installationCompleted = false;
    let eventSource = null; // deprecated (남아있는 외부 참조 호환용)
    let currentMonitor = null; // SSE/폴링 모니터 인스턴스
    let lastRollbackFailure = null;

    /**
     * 저장된 확장 이름 가져오기 (state.json에서 로드된 데이터 사용)
     *
     * @param {string} identifier 확장 식별자
     * @returns {string|null} 다국어 이름 또는 null
     */
    function getStoredExtensionName(identifier) {
        const extensionNames = window.INSTALLER_EXTENSION_NAMES || {};
        if (!extensionNames[identifier]) {
            return null;
        }

        const name = extensionNames[identifier];
        const currentLang = window.installerLocale?.get() || 'ko';

        // 다국어 객체인 경우
        if (typeof name === 'object') {
            return name[currentLang] || name['ko'] || name['en'] || identifier;
        }

        // 문자열인 경우
        return name;
    }

    /**
     * 번역 키 생성 유틸리티
     *
     * @param {string} prefix - 접두사 (예: 'task', 'error')
     * @param {string} id - ID (예: 'composer_check')
     * @returns {string} 번역 키 (예: 'task_composer_check')
     */
    function getTranslationKey(prefix, id) {
        return prefix + '_' + id;
    }

    /**
     * message_key로 안전하게 번역 처리
     *
     * @param {string|null} messageKey - 번역 키
     * @param {string} fallback - 폴백 메시지
     * @returns {string} 번역된 메시지
     */
    function translateWithKey(messageKey, fallback = '') {
        if (!messageKey) {
            return fallback;
        }
        return lang(messageKey);
    }

    /**
     * Task ID를 번역된 이름으로 변환
     *
     * @param {string} taskId - 작업 ID (예: 'composer_check', 'env_create')
     * @returns {string} 번역된 작업 이름
     */
    function getTaskName(taskId) {
        const translationKey = getTranslationKey('task', taskId);
        return lang(translationKey);
    }

    /**
     * State Management API URL 생성
     *
     * @param {string} action - API 액션 ('get', 'reset', 'abort')
     * @returns {string} 완전한 API URL
     */
    function getStateApiUrl(action) {
        return `${window.location.origin}${window.INSTALLER_BASE_URL}/api/state-management.php?action=${action}`;
    }

    /**
     * 설치 상태 가져오기
     *
     * @returns {Promise<Object>} 설치 상태 객체
     */
    async function fetchInstallationState() {
        const apiUrl = getStateApiUrl('get');
        const response = await fetch(apiUrl);
        return await response.json();
    }

    /**
     * 기존 DB 테이블 경고 모달 표시 (이슈 #244 대응).
     *
     * DB 테스트 배지를 클릭하면 호출되며, 사용자에게 백업 안내 + 강제 진행 옵션을 제공합니다.
     * "기존 테이블 모두 삭제 후 설치"를 선택하면 sessionStorage에 existing_db_action=drop_tables 저장.
     */
    function showExistingTablesModal() {
        const info = window.__g7_existing_tables || null;
        if (!info || !info.has_tables) return;

        const sev = info.severity;
        const titleKey = {
            g7_existing: 'db_existing_g7_title',
            foreign_data: 'db_existing_foreign_title',
            mixed: 'db_existing_mixed_title',
        }[sev] || 'db_existing_generic_title';

        const descKey = {
            g7_existing: 'db_existing_g7_desc',
            foreign_data: 'db_existing_foreign_desc',
            mixed: 'db_existing_mixed_desc',
        }[sev] || 'db_existing_generic_desc';

        const tablesList = (info.all_tables || []).slice(0, 20).join(', ');
        const dbHost = document.querySelector('input[name="db_write_host"]')?.value || 'localhost';
        const dbName = document.querySelector('input[name="db_write_database"]')?.value || '';
        const dbUser = document.querySelector('input[name="db_write_username"]')?.value || 'root';
        const backupCmd = `mysqldump -h ${dbHost} -u ${dbUser} -p --databases ${dbName} > backup_$(date +%Y%m%d_%H%M%S).sql`;

        const html = `
            <div class="modal-backdrop" onclick="closeExistingTablesModal()"></div>
            <div class="modal-content">
                <h3 class="modal-title">⚠ ${escapeHtml(lang(titleKey))}</h3>
                <div class="modal-body">
                    <p>${escapeHtml(lang(descKey))}</p>
                    <p class="modal-tables-label">${escapeHtml(lang('db_existing_tables_list'))}</p>
                    <pre class="modal-tables-list">${escapeHtml(tablesList)}</pre>
                    <p class="modal-backup-label"><strong>${escapeHtml(lang('db_backup_guide'))}</strong></p>
                    <div class="code-box">
                        <pre>${escapeHtml(backupCmd)}</pre>
                    </div>
                    <label class="modal-confirm-checkbox">
                        <input type="checkbox" id="db-backup-confirmed">
                        ${escapeHtml(lang('db_backup_confirmed'))}
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeExistingTablesModal()">
                        ${escapeHtml(lang('cancel'))}
                    </button>
                    <button type="button" class="btn btn-danger" id="db-force-proceed-btn" disabled onclick="confirmDropTables()">
                        ${escapeHtml(lang('db_force_proceed_drop'))}
                    </button>
                </div>
            </div>
        `;

        let modal = document.getElementById('existing-tables-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'existing-tables-modal';
            modal.className = 'modal modal-existing-tables';
            document.body.appendChild(modal);
        }
        modal.innerHTML = html;
        modal.classList.add('modal-open');

        // 체크박스 → 버튼 활성화 연결
        const cb = modal.querySelector('#db-backup-confirmed');
        const btn = modal.querySelector('#db-force-proceed-btn');
        if (cb && btn) {
            cb.addEventListener('change', () => {
                btn.disabled = !cb.checked;
            });
        }
    }
    window.showExistingTablesModal = showExistingTablesModal;

    function closeExistingTablesModal() {
        const modal = document.getElementById('existing-tables-modal');
        if (modal) {
            modal.classList.remove('modal-open');
            modal.innerHTML = '';
        }
    }
    window.closeExistingTablesModal = closeExistingTablesModal;

    function confirmDropTables() {
        sessionStorage.setItem('existing_db_action', 'drop_tables');
        closeExistingTablesModal();
        // 사용자에게 선택 결과 피드백
        const resultDiv = document.getElementById('db-write-test-result');
        if (resultDiv) {
            const existing = resultDiv.querySelector('.db-existing-tables-badge');
            if (existing) {
                existing.innerHTML = `✓ ${escapeHtml(lang('db_force_proceed_confirmed'))}`;
                existing.classList.remove('alert-warning');
                existing.classList.add('alert-info');
            }
        }
    }

    /**
     * 기존 테이블 삭제 동의 체크박스 핸들러 (인라인 카드)
     * 체크 시 sessionStorage에 existing_db_action=drop_tables 저장 + FormValidator 갱신.
     */
    function onDbBackupConsentChange(checkbox) {
        const consented = !!checkbox.checked;
        if (consented) {
            sessionStorage.setItem('existing_db_action', 'drop_tables');
        } else {
            sessionStorage.removeItem('existing_db_action');
        }
        if (typeof FormValidator !== 'undefined') {
            FormValidator.setDbCleanupConsented(consented);
        }
    }
    window.onDbBackupConsentChange = onDbBackupConsentChange;
    window.confirmDropTables = confirmDropTables;

    /**
     * SSE 호환성 사전 체크 (streaming 검증).
     *
     * sse-probe.php 가 두 phase 의 probe 이벤트를 sleep(2초) 간격으로 송신한다.
     * 클라이언트는 두 메시지의 client 측 도착 timestamp 차이로 streaming 호환성을 판정:
     *  - 차이 ≥ 1500ms → streaming 가능 (호환) → SSE 모드
     *  - 차이 < 1500ms → buffer 후 일괄 도착 (비호환) → 폴링 모드
     *  - timeout / 에러 → 비호환
     *
     * 단순 응답 수신 검증 (single-phase) 은 cgi-fcgi/mod_fcgid 환경에서 false positive
     * 가능 (응답 종료 시점에 한 번에 flush 되어도 onmessage 발화). timing 검증으로
     * 실제 streaming 가능성을 실증한다.
     *
     * @param {number} timeoutMs probe 응답 대기 timeout (default 5000 — sleep 2s + 여유)
     * @returns {Promise<boolean>} 호환 여부
     */
    function detectSseCompatibility(timeoutMs = 5000) {
        return new Promise((resolve) => {
            const probeUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/sse-probe.php`;
            const STREAMING_THRESHOLD_MS = 1500;
            let settled = false;
            let source = null;
            let phase1ClientTs = null;

            const finish = (compatible) => {
                if (settled) return;
                settled = true;
                if (source) {
                    try { source.close(); } catch (e) { /* ignore */ }
                }
                resolve(compatible);
            };

            try {
                source = new EventSource(probeUrl);
            } catch (e) {
                finish(false);
                return;
            }

            source.addEventListener('probe', (e) => {
                let payload = null;
                try { payload = JSON.parse(e.data); } catch (_) { /* malformed */ }
                if (!payload) return;

                if (payload.phase === 1) {
                    phase1ClientTs = Date.now();
                } else if (payload.phase === 2 && phase1ClientTs !== null) {
                    const gap = Date.now() - phase1ClientTs;
                    finish(gap >= STREAMING_THRESHOLD_MS);
                }
            });

            source.onerror = () => finish(false);

            setTimeout(() => finish(false), timeoutMs);
        });
    }

    /**
     * 설치 시작 (SSE / 폴링 듀얼 모드)
     */
    async function startInstallation(modeOverride = null) {
        if (installationStarted) return;
        installationStarted = true;

        // 시작 대기 UI 숨김, 진행 UI 표시 (모드 선택 카드 + 시작 버튼 → 경고 + 진행 카드)
        hideInstallationStartSection();
        showInstallationProgressSection();

        // 중단 버튼 표시
        showAbortButton();

        // 작업 목록 렌더링
        renderTaskList();

        // 모드 결정: 인자(명시 override) > SSE 호환성 사전 체크 > Step 5 라디오 값 > 'sse' 기본
        //
        // SSE 사전 체크 — sse-probe.php 의 두 phase 메시지 timing 으로 streaming 호환성 판정.
        // 비호환 시 사용자에게 폴링 모드 진행 여부를 명시적으로 확인 (자동 진행 안 함).
        let installationMode = modeOverride;
        if (!installationMode) {
            const modeRadio = document.querySelector('input[name="installation_mode"]:checked');
            const radioMode = modeRadio ? modeRadio.value : null;

            if (radioMode === 'polling') {
                // 사용자가 명시 폴링 선택 — probe 생략
                installationMode = 'polling';
            } else {
                // SSE 호환성 probe (5초 timeout — 서버 sleep 2초 + 여유)
                const sseCompatible = await detectSseCompatibility(5000);

                if (sseCompatible) {
                    installationMode = 'sse';
                } else {
                    // 비호환 — 사용자에게 폴링 모드 진행 여부 명시 confirm
                    const proceedWithPolling = window.confirm(lang('sse_probe_incompat_use_polling'));
                    if (!proceedWithPolling) {
                        // 사용자 취소 — 시작 화면 복원 (진행 미시작)
                        installationStarted = false;
                        hideAbortButton();
                        showInstallationStartSection();
                        return;
                    }
                    installationMode = 'polling';
                }

                // 결정된 모드를 라디오에 반영 (시각적 일관성)
                const reflectedRadio = document.querySelector(`input[name="installation_mode"][value="${installationMode}"]`);
                if (reflectedRadio) {
                    reflectedRadio.checked = true;
                }
            }
        }

        try {
            // 설정 데이터 저장 (POST 요청) + 설치 모드 전달
            const configData = window.INSTALLER_CONFIG || {};
            const saveConfigUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/install-process.php`;

            const existingDbAction = sessionStorage.getItem('existing_db_action') || 'skip';

            const response = await fetch(saveConfigUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    config: configData,
                    installation_mode: installationMode,
                    existing_db_action: existingDbAction,
                }),
            });

            const result = await response.json();

            if (!result.success) {
                if (result.env_required) {
                    installationStarted = false;
                    hideAbortButton();
                    showFileSetupSection(result.missing_files || [], null, null, result.base_path, result.is_windows || false);
                    return;
                }
                showError(result.message || lang('installation_start_failed'));
                return;
            }

            // 공통 모니터 콜백 정의
            const stateUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/state-management.php?action=get`;

            const callbacks = {
                onConnected: () => {},
                onTaskStart: (taskId, name, target) => {
                    updateTaskStatus(taskId, 'active', name, target || null);
                },
                onTaskComplete: (taskId, message, target) => {
                    updateTaskStatus(taskId, 'completed', message, target || null);
                },
                onLog: (message) => {
                    addLogMessage(message);
                },
                onComplete: (data) => {
                    installationCompleted = true;
                    hideAbortButton();
                    fetch(stateUrl, { cache: 'no-store' })
                        .then((res) => res.json())
                        .then((stateData) => showCompletionSection(stateData))
                        .catch(() => {
                            if (data && data.redirect) {
                                window.location.href = data.redirect;
                            }
                        });
                },
                onAbort: () => {
                    installationCompleted = true;
                    hideAbortButton();
                    fetchInstallationState()
                        .then((state) => {
                            showAbortedInstallationSection(state);
                        })
                        .catch(() => {
                            window.location.reload();
                        });
                },
                onRollbackFailed: (data) => {
                    lastRollbackFailure = {
                        message: (data && data.message) || lang('failed_rollback_manual_cleanup'),
                        detail: (data && data.detail) || lang('failed_rollback_manual_cleanup_detail'),
                    };
                },
                onError: (data) => {
                    const errorMessage = translateWithKey(
                        data.message_key,
                        data.message || lang('installation_error_occurred')
                    );
                    const errorDetail = data.error || null;
                    const failedTaskId = data.task || null;
                    showError(errorMessage, errorDetail, failedTaskId);
                },
                onConnectionTimeout: () => {
                    // SSE 모드에서 연결 timeout 시 — 사전 체크 통과 후의 예외 케이스라
                    // 자동 폴링 fallback 대신 사용자에게 명시적 에러 노출. 사용자가 페이지를
                    // 새로고침하면 sse-probe 재실행으로 모드 재결정.
                    if (installationCompleted) return;
                    showError(lang('sse_connection_timeout'), lang('sse_server_config_guide'));
                },
            };

            // 모드별 monitor 생성
            const MonitorNS = window.G7InstallationMonitor;
            if (!MonitorNS) {
                showError('installation-monitor.js not loaded');
                return;
            }

            if (installationMode === 'polling') {
                currentMonitor = new MonitorNS.PollingMonitor(callbacks, {
                    stateUrl: stateUrl,
                    intervalMs: 1000,
                });
            } else {
                const workerUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/install-worker.php`;
                currentMonitor = new MonitorNS.SseMonitor(callbacks, {
                    workerUrl: workerUrl,
                    connectionTimeoutMs: 5000,
                });
            }

            currentMonitor.start();
        } catch (error) {
            showError(`${lang('installation_start_error')}: ${error.message}`);
        }
    }

    /**
     * 작업 상태 업데이트
     * @param {string} taskId 작업 ID
     * @param {string} status 상태
     * @param {string} message 메시지
     * @param {string|null} target 확장 식별자 (옵션)
     */
    function updateTaskStatus(taskId, status, message, target = null) {
        // 통합 함수 사용 (target 전달)
        setTaskStatus(taskId, status, target);

        if (status === 'active') {
            // 현재 작업명 업데이트
            const currentTaskText = document.getElementById('current-task');
            if (currentTaskText) {
                // target이 있으면 확장 이름과 조합 (예: "게시판 모듈 설치 (sirsoft-board)")
                let taskName = getTaskName(taskId);
                if (target) {
                    const extName = getStoredExtensionName(target);
                    taskName = extName
                        ? `${extName} ${taskName} (${target})`
                        : `${taskName} (${target})`;
                }
                currentTaskText.textContent = taskName || message || '';
            }
        } else if (status === 'completed') {
            // 진행률 업데이트
            updateProgressBar();
        }
    }

    /**
     * 진행률 바 업데이트
     */
    function updateProgressBar() {
        const completedTasks = document.querySelectorAll('.task-item.task-completed').length;
        const totalTasks = document.querySelectorAll('.task-item').length || INSTALLATION_TASKS.length;
        const percentage = totalTasks > 0 ? Math.round((completedTasks / totalTasks) * 100) : 0;

        const progressBar = document.getElementById('overall-progress-bar');
        const progressPercentage = document.getElementById('overall-percentage');

        if (progressBar) {
            progressBar.style.width = percentage + '%';
        }
        if (progressPercentage) {
            progressPercentage.textContent = percentage + '%';
        }
    }

    /**
     * 로그 메시지 추가
     */
    function addLogMessage(message) {
        const logDiv = document.getElementById('install-log');
        if (!logDiv) return;

        // 로그 플레이스홀더 제거
        const placeholder = logDiv.querySelector('.log-placeholder');
        if (placeholder) {
            placeholder.remove();
        }

        const logEntry = document.createElement('div');
        logEntry.className = 'log-entry';
        logEntry.textContent = message;
        logDiv.appendChild(logEntry);

        // 자동 스크롤
        logDiv.scrollTop = logDiv.scrollHeight;
    }

    /**
     * 기존 로그 표시 (페이지 로드 시)
     *
     * @param {Array} logs 로그 배열 [{timestamp, message}, ...] 또는 문자열 배열
     */
    function displayLogs(logs) {
        const logDiv = document.getElementById('install-log');
        if (!logDiv) return;

        // 로그 플레이스홀더 제거
        const placeholder = logDiv.querySelector('.log-placeholder');
        if (placeholder) {
            placeholder.remove();
        }

        // 로그가 없으면 플레이스홀더 유지
        if (!logs || logs.length === 0) {
            return;
        }


        // 로그 메시지 추가
        logs.forEach(log => {
            const logEntry = document.createElement('div');
            logEntry.className = 'log-entry';

            // 로그가 객체인 경우 message 속성 사용, 문자열인 경우 그대로 사용
            if (typeof log === 'object' && log.message) {
                logEntry.textContent = log.message;
            } else if (typeof log === 'string') {
                logEntry.textContent = log;
            } else {
                logEntry.textContent = JSON.stringify(log);
            }

            logDiv.appendChild(logEntry);
        });

        // 자동 스크롤
        logDiv.scrollTop = logDiv.scrollHeight;
    }

    /**
     * 그룹 레이블 번역
     * @param {string} labelKey 번역 키
     * @returns {string} 번역된 레이블
     */
    function getGroupLabel(labelKey) {
        return lang(labelKey);
    }

    /**
     * 작업 표시 이름 생성 (target이 있는 경우 조합)
     * 예: "게시판 모듈 설치 (sirsoft-board)"
     * @param {Object} task 작업 객체
     * @returns {string} 표시 이름
     */
    function getTaskDisplayName(task) {
        const baseName = getTaskName(task.id);
        if (task.target) {
            // 확장 이름 조회 (저장된 이름 사용)
            const extName = getStoredExtensionName(task.target);
            // 형식: "게시판 모듈 설치 (sirsoft-board)"
            return extName
                ? `${extName} ${baseName} (${task.target})`
                : `${baseName} (${task.target})`;
        }
        return baseName;
    }

    /**
     * 작업 DOM ID 생성 (target이 있는 경우 조합)
     * @param {Object} task 작업 객체
     * @returns {string} DOM ID
     */
    function getTaskDomId(task) {
        if (task.target) {
            return `task-${task.id}-${task.target}`;
        }
        return `task-${task.id}`;
    }

    /**
     * 작업 목록 렌더링 (그룹화된 아코디언 UI)
     */
    function renderTaskList() {
        const taskListDiv = document.getElementById('task-list');
        if (!taskListDiv) return;

        const taskGroups = window.INSTALLER_TASK_GROUPS || [];

        // 그룹이 없으면 기존 평면 렌더링
        if (taskGroups.length === 0) {
            const html = INSTALLATION_TASKS.map(task => {
                const taskName = task.id ? getTaskName(task.id) : (task.name || '');
                return `
                <div id="task-${task.id}" class="task-item task-pending">
                    <span class="task-icon">⏳</span>
                    <span class="task-name">${escapeHtml(taskName)}</span>
                </div>
            `;
            }).join('');
            taskListDiv.innerHTML = html;
            return;
        }

        // 그룹별 렌더링
        const groupsHtml = taskGroups.map(group => {
            // 그룹 내 작업 목록 평탄화
            const tasks = [];
            if (group.tasks && group.tasks.length > 0) {
                group.tasks.forEach(taskOrArray => {
                    if (Array.isArray(taskOrArray)) {
                        taskOrArray.forEach(t => tasks.push(t));
                    } else {
                        tasks.push(taskOrArray);
                    }
                });
            }

            // 작업이 없는 그룹은 건너뛰기
            if (tasks.length === 0) return '';

            const groupLabel = getGroupLabel(group.labelKey);
            const tasksHtml = tasks.map(task => {
                const taskDomId = getTaskDomId(task);
                const taskName = getTaskDisplayName(task);
                return `
                    <div id="${taskDomId}" class="task-item task-pending" data-task-id="${task.id}" data-target="${task.target || ''}">
                        <span class="task-icon"><svg viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/></svg></span>
                        <span class="task-name">${escapeHtml(taskName)}</span>
                    </div>
                `;
            }).join('');

            return `
                <div class="task-group" data-group-id="${group.id}">
                    <div class="task-group-header" onclick="toggleTaskGroup('${group.id}')">
                        <span class="task-group-toggle">▼</span>
                        <span class="task-group-label">${escapeHtml(groupLabel)}</span>
                        <span class="task-group-status status-pending">${lang('status_pending')}</span>
                    </div>
                    <div class="task-group-items">
                        ${tasksHtml}
                    </div>
                </div>
            `;
        }).join('');

        taskListDiv.innerHTML = groupsHtml;
    }

    /**
     * 완료된 작업 표시 (페이지 로드 시)
     *
     * @param {Array} completedTasks 완료된 작업 ID 배열 (예: ["db_migrate", "template_install:sirsoft-admin_basic"])
     */
    function displayCompletedTasks(completedTasks) {

        completedTasks.forEach(taskIdWithTarget => {
            // taskId:target 형식 파싱 (예: "template_install:sirsoft-admin_basic")
            const [taskId, target] = taskIdWithTarget.includes(':')
                ? taskIdWithTarget.split(':')
                : [taskIdWithTarget, null];

            // 통합 함수 사용
            setTaskStatus(taskId, 'completed', target);
        });

        // 진행률 업데이트
        updateProgressBar();
    }


    /**
     * 완료 섹션 표시
     */
    function showCompletionSection(data) {
        installationCompleted = true; // 완료 플래그 설정 (beforeunload 경고 비활성화)

        // 대기 UI 숨기고 진행 카드 표시 (새로고침 후 복원 경로 대응)
        hideInstallationStartSection();
        showInstallationProgressSection();

        // 중단 버튼 숨김
        hideAbortButton();

        // 타이틀 숨기기
        const titleEl = document.getElementById('installer-title');
        if (titleEl) {
            titleEl.style.display = 'none';
        }

        // 경고 메시지 숨기기
        const warningAlert = document.getElementById('install-warning');
        if (warningAlert) {
            warningAlert.style.display = 'none';
        }

        // 진행률 100% 설정 (wrapper는 숨기기 전에)
        const progressBar = document.getElementById('overall-progress-bar');
        const progressPercentage = document.getElementById('overall-percentage');
        if (progressBar) {
            progressBar.style.width = '100%';
        }
        if (progressPercentage) {
            progressPercentage.textContent = '100%';
        }

        // 현재 작업 텍스트를 "설치 완료"로 변경
        const currentTaskEl = document.getElementById('current-task');
        if (currentTaskEl) {
            currentTaskEl.textContent = lang('installation_completed');
            currentTaskEl.style.color = '#10b981';
            currentTaskEl.style.fontWeight = 'bold';
        }

        // 설치 카드 body 숨기기 (기본적으로 접힌 상태)
        const installationCardBody = document.getElementById('installation-card-body');
        const installToggleIcon = document.getElementById('install-toggle-icon');

        if (installationCardBody) {
            installationCardBody.style.display = 'none';
        }
        if (installToggleIcon) {
            installToggleIcon.classList.remove('hidden');
            installToggleIcon.textContent = '▶';
        }

        // 완료 섹션 표시 (타이틀 영역에, fadeIn 효과)
        const completionSection = document.getElementById('completion-section');
        if (completionSection) {
            completionSection.classList.remove('hidden');
            completionSection.style.opacity = '0';
            completionSection.style.transition = 'opacity 0.5s ease-in';

            // 약간의 지연 후 페이드인
            setTimeout(() => {
                completionSection.style.opacity = '1';
            }, 100);
        }

        // .env 최종 머지 — fire-and-forget. 응답을 기다리지 않으며,
        // php artisan serve 의 .env mtime 워처가 트리거하는 워커 재시작은
        // 완료 화면 노출 후 발생하므로 사용자 경험에 영향이 없다.
        // keepalive: 탭 닫힘에도 요청 유지. catch: 워커 재시작으로 인한
        // connection abort 를 에러로 표시하지 않는다 (의도된 동작).
        try {
            fetch('/install/api/finalize-env.php', {
                method: 'POST',
                keepalive: true,
                headers: { 'X-Installer-Finalize': '1' },
            }).catch(() => { /* 의도된 abort */ });
        } catch (_) {
            // fetch 자체가 실패해도 무시 — runtime.php 가 보존되어 다음 부팅 시 앱 정상 동작
        }
    }

    /**
     * 에러 표시
     *
     * @param {string} errorMessage 메인 오류 메시지
     * @param {string|null} errorDetail 상세 오류 메시지 (선택)
     * @param {string|null} failedTaskId 실패한 작업 ID (선택)
     */
    function showError(errorMessage, errorDetail = null, failedTaskId = null) {
        installationCompleted = true; // 완료 플래그 설정 (beforeunload 경고 비활성화)

        // 대기 UI 숨기고 진행 카드 표시 (실패 경로 — 작업 목록/로그 노출)
        hideInstallationStartSection();
        showInstallationProgressSection();

        // 중단 버튼 숨김
        hideAbortButton();

        // 타이틀 숨기기
        const titleEl = document.getElementById('installer-title');
        if (titleEl) {
            titleEl.style.display = 'none';
        }

        // 경고 메시지 숨기기
        const warningAlert = document.getElementById('install-warning');
        if (warningAlert) {
            warningAlert.style.display = 'none';
        }

        // 토글 아이콘 보이기 및 카드 헤더 활성화
        const installToggleIcon = document.getElementById('install-toggle-icon');
        const installationCardHeader = document.getElementById('installation-card-header');
        const installationCardBody = document.getElementById('installation-card-body');

        if (installToggleIcon) {
            installToggleIcon.classList.remove('hidden');
        }
        if (installationCardHeader) {
            installationCardHeader.style.cursor = 'pointer';
        }

        // 설치 카드 body 표시 (작업 목록과 로그 보이기)
        if (installationCardBody) {
            installationCardBody.style.display = 'block';
        }

        // failedTaskId가 파라미터로 제공되지 않은 경우, DOM에서 추출 시도
        if (!failedTaskId) {
            // 현재 진행 중인 작업(task-active)에서 추출
            const activeTasks = document.querySelectorAll('.task-item.task-active');
            activeTasks.forEach(taskEl => {
                if (!failedTaskId) {
                    // task element의 ID에서 task ID 추출 (예: 'task-composer_install' -> 'composer_install')
                    const taskId = taskEl.id.replace('task-', '');
                    if (taskId) {
                        failedTaskId = taskId;
                    }
                }
            });
        }

        // Task ID를 번역된 이름으로 변환
        const failedTaskName = failedTaskId ? getTaskName(failedTaskId) : null;

        // 현재 진행 중인 작업(task-active)을 모두 실패로 표시
        const activeTasks = document.querySelectorAll('.task-item.task-active');
        activeTasks.forEach(taskEl => {
            const taskId = taskEl.id.replace('task-', '');
            // 통합 함수 사용
            setTaskStatus(taskId, 'failed');
        });

        // 실패 섹션 표시
        const failureSection = document.getElementById('failure-section');
        const failureMessage = document.getElementById('failure-message');
        const failureTitle = failureSection?.querySelector('.result-title');

        if (failureSection) {
            // 타이틀에 실패 단계 추가
            if (failureTitle && failedTaskName) {
                const baseTitle = lang('installation_failed');
                failureTitle.textContent = `${baseTitle} - ${failedTaskName}`;
            }

            // 오류 메시지 설정 (구조화된 형식)
            if (failureMessage) {
                let messageHtml = '';

                // 메인 오류 메시지
                messageHtml += `
                    <div class="failure-main-message">
                        ${escapeHtml(errorMessage)}
                    </div>
                `;

                // 상세 오류 (있을 경우만 - 아코디언 형식)
                if (errorDetail) {
                    const accordionId = 'error-detail-' + Date.now();
                    messageHtml += `
                        <div class="failure-error-detail">
                            <button
                                class="error-detail-toggle"
                                type="button"
                                aria-expanded="false"
                                aria-controls="${accordionId}"
                                onclick="toggleErrorDetail(this, '${accordionId}')"
                            >
                                <span class="error-detail-toggle-icon">▶</span>
                                <strong>${lang('view_error_details')}</strong>
                            </button>
                            <div id="${accordionId}" class="error-detail-content">
                                <pre class="error-stack">${escapeHtml(errorDetail)}</pre>
                            </div>
                        </div>
                    `;
                }

                // 롤백 실패 안내 (있을 경우)
                if (lastRollbackFailure) {
                    messageHtml += `
                        <div class="failure-rollback-notice">
                            <strong>${escapeHtml(lastRollbackFailure.message)}</strong>
                            <p>${escapeHtml(lastRollbackFailure.detail)}</p>
                        </div>
                    `;
                    lastRollbackFailure = null;
                }

                failureMessage.innerHTML = messageHtml;
            }

            // 실패 섹션 표시 (페이드인 효과)
            failureSection.classList.remove('hidden');
            failureSection.style.opacity = '0';
            failureSection.style.transition = 'opacity 0.5s ease-in';

            setTimeout(() => {
                failureSection.style.opacity = '1';
            }, 100);
        }
    }

    /**
     * 설치 계속 진행 (실패/중단 공통 함수)
     */
    function continueInstallation() {
        // 타이틀 다시 표시 및 초기화
        const titleEl = document.getElementById('installer-title');
        if (titleEl) {
            titleEl.style.display = 'block';
            titleEl.textContent = lang('installation_title');
            titleEl.style.color = ''; // 기본 색상
        }

        // 경고 메시지 다시 표시
        const warningAlert = document.getElementById('install-warning');
        if (warningAlert) {
            warningAlert.style.display = 'block';
        }

        // 모든 결과 섹션 숨기기
        ['aborted-section', 'failure-section', 'completion-section'].forEach(sectionId => {
            const section = document.getElementById(sectionId);
            if (section) {
                section.classList.add('hidden');
            }
        });

        // 설치 카드 다시 표시 및 초기화
        const installationCardBody = document.getElementById('installation-card-body');
        const installationCardHeader = document.getElementById('installation-card-header');
        const installToggleIcon = document.getElementById('install-toggle-icon');
        const logDiv = document.getElementById('install-log');

        if (installationCardBody) {
            installationCardBody.style.display = 'block';
        }
        if (installationCardHeader) {
            installationCardHeader.style.cursor = 'default';
        }
        if (installToggleIcon) {
            installToggleIcon.classList.add('hidden');
        }
        if (logDiv) {
            // 기존 로그 유지, 플레이스홀더만 제거
            const placeholder = logDiv.querySelector('.log-placeholder');
            if (placeholder) {
                placeholder.remove();
            }

            // 구분선 추가 (기존 로그와 새 로그 구분)
            const separator = document.createElement('div');
            separator.className = 'log-entry log-separator';
            separator.textContent = '--- ' + lang('installation_resuming') + ' ---';
            logDiv.appendChild(separator);
        }

        // 상태 초기화
        installationStarted = false;
        installationCompleted = false;
        if (currentMonitor) {
            currentMonitor.stop();
            currentMonitor = null;
        }
        if (eventSource) {
            eventSource.close();
            eventSource = null;
        }

        // 설치 재시작
        startInstallation();
    }

    /**
     * 설치 재시도 (continueInstallation 래퍼)
     */
    function retryInstallation() {
        continueInstallation();
    }

    /**
     * 재진입 감지 및 확인
     */
    async function checkAndStartInstallation() {
        try {
            // 현재 설치 상태 확인
            const state = await fetchInstallationState();


            // 설치가 이미 완료된 경우
            if (state.status === 'completed') {
                showCompletionSection(state);
                return;
            }

            // 설치가 실패한 경우 - 실패 섹션 표시
            if (state.status === 'failed') {

                // 페이지 로드 시 완료된 작업 표시
                renderTaskList();
                displayCompletedTasks(state.completed_tasks || []);

                // 로그 표시
                displayLogs(state.logs || []);

                // 실패 정보 가져오기 (state.json에서 복원)
                const errorMessage = translateWithKey(
                    state.error_message_key,
                    state.error || lang('unknown_error_occurred')
                );
                const errorDetail = state.error_detail || null;
                const failedTaskId = state.failed_task;

                // ✅ showError() 호출 전에 먼저 task를 failed로 마크
                if (failedTaskId) {
                    setTaskStatus(failedTaskId, 'failed');
                }

                // 롤백 실패 정보 복원 (state.json에서 새로고침 후에도 표시)
                if (state.rollback_failure) {
                    lastRollbackFailure = {
                        message: lang(state.rollback_failure.message_key) || lang('failed_rollback_manual_cleanup'),
                        detail: lang(state.rollback_failure.detail_key) || lang('failed_rollback_manual_cleanup_detail'),
                    };
                }

                // ✅ failedTaskId를 명시적으로 전달 (함수 내부에서 번역됨)
                showError(errorMessage, errorDetail, failedTaskId);

                return;
            }

            // 설치가 중단된 경우 - 중단 섹션 표시
            if (state.status === 'aborted') {
                showAbortedInstallationSection(state);
                return;
            }

            // 설치가 진행 중이었던 경우 (재진입) - alert 없이 바로 중단 섹션 표시
            if (state.status === 'running') {

                // installation_status를 aborted로 변경
                const abortUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/state-management.php?action=abort`;
                fetch(abortUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({})
                }).then(() => {

                    // 상태를 다시 가져와서 중단 섹션 표시
                    fetch(apiUrl)
                        .then(res => res.json())
                        .then(updatedState => {
                            showAbortedInstallationSection(updatedState);
                        });
                }).catch(err => {
                    // 실패해도 중단 섹션은 표시
                    showAbortedInstallationSection(state);
                });
            } else {
                // 처음 시작하는 경우 — 필수 파일 체크 후 "설치 시작" 버튼 표시 (자동 시작 금지)
                // PO가 모드(SSE/폴링)를 선택한 뒤 명시적으로 시작할 수 있도록 대기
                const filesReady = await checkFilesBeforeInstall();
                if (filesReady) {
                    showInstallationStartSection();
                }
            }
        } catch (error) {
            // 오류 발생 시에도 시작 버튼 노출 (자동 시작 금지)
            showInstallationStartSection();
        }
    }

    /**
     * 설치 시작 대기 UI 표시 — 모드 선택 카드 + "설치 시작" 버튼을 노출한다.
     * 진행 UI(경고 alert, 진행 카드)는 숨겨진 상태 유지.
     */
    function showInstallationStartSection() {
        const startSection = document.getElementById('installation-start-section');
        const modeCard = document.getElementById('installation-mode-card');
        const warning = document.getElementById('install-warning');
        const progressCard = document.getElementById('installation-progress-card');
        if (startSection) startSection.style.display = '';
        if (modeCard) modeCard.style.display = '';
        if (warning) warning.style.display = 'none';
        if (progressCard) progressCard.style.display = 'none';

        // 모드 선택 라디오 활성화 (사전 체크 취소 후 재시도 가능하도록)
        document.querySelectorAll('input[name="installation_mode"]').forEach(input => {
            input.disabled = false;
        });
    }

    /**
     * 설치 시작 대기 UI 숨김 — 설치 진행 시작 시 호출.
     * 모드 선택 카드는 비활성화(변경 불가) 상태로 유지하되 숨기지는 않는다.
     */
    function hideInstallationStartSection() {
        const startSection = document.getElementById('installation-start-section');
        if (startSection) startSection.style.display = 'none';

        // 모드 선택 라디오 비활성화 (진행 중 변경 방지)
        document.querySelectorAll('input[name="installation_mode"]').forEach(input => {
            input.disabled = true;
        });
    }

    /**
     * 설치 진행 UI 표시 — 경고 alert + 진행 카드 노출.
     */
    function showInstallationProgressSection() {
        const warning = document.getElementById('install-warning');
        const progressCard = document.getElementById('installation-progress-card');
        if (warning) warning.style.display = '';
        if (progressCard) progressCard.style.display = '';
    }

    /**
     * "설치 시작" 버튼 클릭 핸들러 — 사용자가 모드 선택 후 명시적으로 시작.
     */
    function onStartInstallationClick() {
        // startInstallation()이 라디오 값을 읽어 모드 결정
        startInstallation();
    }
    window.onStartInstallationClick = onStartInstallationClick;

    /**
     * 중단된 설치 섹션 표시 (Step 4)
     *
     * @param {Object} state 설치 상태 정보
     */
    function showAbortedInstallationSection(state) {

        installationCompleted = true; // 완료 플래그 설정 (beforeunload 경고 비활성화)

        // 대기 UI 숨기고 진행 카드 표시 (중단 경로 — 작업 목록/로그 노출)
        hideInstallationStartSection();
        showInstallationProgressSection();

        // 중단 버튼 숨김
        hideAbortButton();

        // 타이틀 숨기기
        const titleEl = document.getElementById('installer-title');
        if (titleEl) {
            titleEl.style.display = 'none';
        }

        // 경고 메시지 숨기기
        const warningAlert = document.getElementById('install-warning');
        if (warningAlert) {
            warningAlert.style.display = 'none';
        }

        // 중단 섹션 표시
        const abortedSection = document.getElementById('aborted-section');

        if (abortedSection) {
            // 롤백 실패 정보가 있으면 경고 메시지 추가
            if (state.rollback_failure) {
                const resultText = abortedSection.querySelector('.result-text');
                if (resultText) {
                    const rollbackAlert = document.createElement('div');
                    rollbackAlert.className = 'alert alert-danger';
                    rollbackAlert.style.marginTop = '0.75rem';
                    rollbackAlert.style.fontSize = '0.875rem';
                    const msg = lang(state.rollback_failure.message_key) || lang('failed_rollback_manual_cleanup');
                    const detail = lang(state.rollback_failure.detail_key) || lang('failed_rollback_manual_cleanup_detail');
                    rollbackAlert.innerHTML = '<strong>' + escapeHtml(msg) + '</strong><br>' + escapeHtml(detail);
                    resultText.appendChild(rollbackAlert);
                }
            }

            abortedSection.classList.remove('hidden');
            abortedSection.style.opacity = '0';
            abortedSection.style.transition = 'opacity 0.5s ease-in';

            setTimeout(() => {
                abortedSection.style.opacity = '1';
            }, 100);
        } else {
        }

        // 작업 목록 렌더링
        renderTaskList();

        // 완료된 작업 표시
        const completedTasks = state.completed_tasks || [];
        const currentTask = state.current_task;
        displayCompletedTasks(completedTasks);

        // 로그 표시
        displayLogs(state.logs || []);

        // 설치 카드 body 표시 (작업 목록 보이기)
        const installationCardBody = document.getElementById('installation-card-body');
        if (installationCardBody) {
            installationCardBody.style.display = 'block';
        }

        // 현재 작업을 중단으로 표시
        if (currentTask) {
            // taskId:target 형식 파싱 (예: "module_install:sirsoft-board")
            const [taskId, target] = currentTask.includes(':')
                ? currentTask.split(':')
                : [currentTask, null];

            // 통합 함수 사용
            setTaskStatus(taskId, 'aborted', target);
        } else {
            // current_task가 null인 경우, 마지막 완료된 작업 다음 작업을 중단으로 표시
            const allTasks = window.INSTALLER_TASKS || [];

            // completedTasks의 마지막 항목에서 taskId와 target 분리
            const lastCompleted = completedTasks[completedTasks.length - 1] || '';
            const [lastTaskId, lastTarget] = lastCompleted.includes(':')
                ? lastCompleted.split(':')
                : [lastCompleted, null];

            // INSTALLER_TASKS에서 해당 작업 찾기 (target 포함 매칭)
            const lastCompletedIndex = allTasks.findIndex(t => {
                if (lastTarget) {
                    return t.id === lastTaskId && t.target === lastTarget;
                }
                return t.id === lastTaskId && !t.target;
            });

            if (lastCompletedIndex >= 0 && lastCompletedIndex < allTasks.length - 1) {
                const nextTask = allTasks[lastCompletedIndex + 1];
                // 통합 함수 사용
                setTaskStatus(nextTask.id, 'aborted', nextTask.target || null);
            }
        }
    }

    /**
     * 중단된 설치에서 계속 진행 (continueInstallation 래퍼)
     */
    function resumeInstallationFromAborted() {
        continueInstallation();
    }

    /**
     * 설정 페이지로 이동 (확인 후)
     */
    async function goToSettingsWithConfirm() {
        const confirmed = confirm(lang('confirm_go_to_settings_simple'));

        if (confirmed) {
            try {
                // 상태 초기화 API 호출
                const response = await fetch(getStateApiUrl('reset'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({})
                });

                const result = await response.json();

                if (result.success) {
                    // 설정 페이지로 이동 (세션 기반)
                    window.location.href = `${window.INSTALLER_BASE_URL}/`;
                } else {
                    alert(lang('error_state_reset_failed').replace(':message', result.message));
                }
            } catch (error) {
                // 오류가 발생해도 이동은 허용 (강제 이동)
                const forceMove = confirm(lang('confirm_force_go_to_settings'));

                if (forceMove) {
                    window.location.href = `${window.INSTALLER_BASE_URL}/`;
                }
            }
        }
    }

    /**
     * 설치 페이지 자동 시작
     */
    function initInstallation() {
        if (document.getElementById('task-list')) {
            // 페이지 이탈 경고 설정
            window.addEventListener('beforeunload', function(e) {
                // 설치가 시작되었고, 아직 완료되지 않은 경우에만 경고
                if (installationStarted && !installationCompleted) {
                    const message = lang('confirm_leave_installation');
                    e.preventDefault();
                    e.returnValue = message; // Chrome에서 필요
                    return message; // 일부 브라우저에서 필요
                }
            });

            // 페이지 언로드 시 abort 신호 전송 (브라우저 종료/새로고침)
            window.addEventListener('pagehide', function(e) {
                // 설치가 시작되었고, 아직 완료되지 않은 경우에만 abort 전송
                if (installationStarted && !installationCompleted) {
                    const apiUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/state-management.php?action=abort`;

                    // sendBeacon은 페이지 언로드 중에도 전송을 보장
                    navigator.sendBeacon(apiUrl, new Blob([JSON.stringify({})], {type: 'application/json'}));
                }
            });

            // 페이지 로드 시 상태 확인 후 시작
            checkAndStartInstallation();
        }
    }

    // =========================================================================
    // 설치 중단 기능
    // =========================================================================

    /**
     * 설치 중단
     */
    async function abortInstallation() {
        // 설치가 이미 완료되었으면 중단 불가
        if (installationCompleted) {
            alert(lang('installation_already_completed_message'));
            return;
        }

        // 버튼 중복 클릭 방지
        const abortBtn = document.getElementById('abort-installation-btn');
        if (abortBtn && abortBtn.disabled) {
            return;
        }

        // 확인 다이얼로그 표시
        const confirmed = confirm(
            lang('confirm_abort_installation')
        );

        if (!confirmed) {
            return;
        }

        // 중단 처리 시작 - beforeunload 경고 방지
        installationCompleted = true;

        // 중단 버튼 비활성화 및 로딩 표시
        if (abortBtn) {
            abortBtn.disabled = true;
            abortBtn.innerHTML = `
                <svg class="abort-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle class="spinner" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none"/>
                </svg>
                ${lang('aborting')}
            `;
        }

        try {
            // Abort API 호출
            const response = await fetch(getStateApiUrl('abort'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });

            const result = await response.json();

            if (result.success) {
                // SSE 연결 닫기
                if (eventSource) {
                    eventSource.close();
                    eventSource = null;
                }

                // 즉시 중단 버튼 숨김 (UI 즉각 반응)
                hideAbortButton();

                // state.json 동기화를 위해 짧은 지연 후 상태 조회
                setTimeout(() => {
                    fetchInstallationState()
                        .then(state => {
                            showAbortedInstallationSection(state);
                        })
                        .catch(err => {
                            // 페이지 리로드로 폴백 (세션 기반)
                            window.location.href = `${window.INSTALLER_BASE_URL}/`;
                        });
                }, 300);
            } else {
                // 중단 실패 시 플래그 복원
                installationCompleted = false;
                alert((window.INSTALLER_LANG?.abort_failed || 'Abort failed') + ': ' + (result.message || window.INSTALLER_LANG?.unknown_error_occurred || 'Unknown error'));

                // 버튼 복원
                if (abortBtn) {
                    abortBtn.disabled = false;
                    abortBtn.innerHTML = `
                        <svg class="abort-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        ${lang('abort_installation')}
                    `;
                }
            }
        } catch (error) {
            // 중단 실패 시 플래그 복원
            installationCompleted = false;
            alert(lang('abort_error_occurred') + ': ' + error.message);

            // 버튼 복원
            if (abortBtn) {
                abortBtn.disabled = false;
                abortBtn.innerHTML = `
                    <svg class="abort-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    ${lang('abort_installation')}
                `;
            }
        }
    }

    /**
     * 중단 버튼 표시 (초기 상태로 리셋)
     */
    function showAbortButton() {
        const abortBtn = document.getElementById('abort-installation-btn');
        if (abortBtn) {
            // 버튼을 초기 상태로 리셋
            abortBtn.disabled = false;
            abortBtn.innerHTML = `
                <svg class="abort-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                ${lang('abort_installation')}
            `;
            abortBtn.style.display = 'flex';
        }
    }

    /**
     * 중단 버튼 숨김
     */
    function hideAbortButton() {
        const abortBtn = document.getElementById('abort-installation-btn');
        if (abortBtn) {
            abortBtn.style.display = 'none';
        }
    }

    // =========================================================================
    // 재진입/재개 다이얼로그
    // =========================================================================

    /**
     * 재개 다이얼로그 표시
     *
     * @param {Object} state 설치 상태 데이터
     */
    // =========================================================================
    // 다크모드 토글 기능
    // =========================================================================

    const THEME_STORAGE_KEY = 'g7_color_scheme';

    /**
     * 현재 테마 가져오기
     */
    function getCurrentTheme() {
        return document.documentElement.getAttribute('data-theme') || 'light';
    }

    /**
     * 테마 설정 및 저장
     */
    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem(THEME_STORAGE_KEY, theme);
        updateThemeIcon(theme);
    }

    /**
     * 테마 토글
     */
    function toggleTheme() {
        const currentTheme = getCurrentTheme();
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

        // 버튼에 페이드 애니메이션 추가
        const toggleBtn = document.getElementById('theme-toggle-btn');
        if (toggleBtn) {
            toggleBtn.classList.add('transitioning');
            setTimeout(() => {
                toggleBtn.classList.remove('transitioning');
            }, 500);
        }

        setTheme(newTheme);
    }

    /**
     * 테마 아이콘 업데이트
     */
    function updateThemeIcon(theme) {
        const iconElement = document.getElementById('theme-toggle-icon');
        if (iconElement) {
            // SVG 아이콘으로 변경
            if (theme === 'dark') {
                // 라이트 모드로 전환할 수 있는 태양 아이콘
                iconElement.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1" x2="12" y2="3"/>
                        <line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/>
                        <line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                `;
            } else {
                // 다크 모드로 전환할 수 있는 달 아이콘
                iconElement.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                `;
            }
        }
    }

    /**
     * 다크모드 토글 버튼 초기화
     * 참고: 테마는 이미 HTML에서 적용됨 (FOUC 방지)
     */
    function initThemeToggle() {
        const toggleBtn = document.getElementById('theme-toggle-btn');
        if (toggleBtn) {
            // 현재 테마에 맞는 아이콘으로 업데이트
            const currentTheme = getCurrentTheme();
            updateThemeIcon(currentTheme);

            // 토글 버튼 클릭 이벤트 등록
            toggleBtn.addEventListener('click', toggleTheme);
        }

        // 시스템 다크모드 설정 변경 감지
        if (window.matchMedia) {
            const darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');

            darkModeQuery.addEventListener('change', (e) => {
                // localStorage에 저장된 값이 없을 때만 시스템 설정 따라감
                if (!localStorage.getItem(THEME_STORAGE_KEY)) {
                    const newTheme = e.matches ? 'dark' : 'light';
                    document.documentElement.setAttribute('data-theme', newTheme);
                    updateThemeIcon(newTheme);
                }
            });
        }
    }

    // =========================================================================
    // 유틸리티 함수
    // =========================================================================

    /**
     * 설치 카드 토글
     * 완료/실패 시에만 작동
     */
    function toggleInstallationCard() {
        // 설치 진행 중에는 토글 불가
        if (!installationCompleted) {
            return;
        }

        const cardBody = document.getElementById('installation-card-body');
        const toggleIcon = document.getElementById('install-toggle-icon');

        if (!cardBody) {
            return;
        }

        const isHidden = cardBody.style.display === 'none';

        if (isHidden) {
            // 카드 body 보이기
            cardBody.style.display = 'block';
            if (toggleIcon) toggleIcon.textContent = '▼';
        } else {
            // 카드 body 숨기기
            cardBody.style.display = 'none';
            if (toggleIcon) toggleIcon.textContent = '▶';
        }
    }

    /**
     * 오류 상세 아코디언 토글
     */
    function toggleErrorDetail(button, contentId) {
        const content = document.getElementById(contentId);
        const isExpanded = button.getAttribute('aria-expanded') === 'true';

        if (isExpanded) {
            // 닫기
            button.setAttribute('aria-expanded', 'false');
            content.classList.remove('show');
        } else {
            // 열기
            button.setAttribute('aria-expanded', 'true');
            content.classList.add('show');
        }
    }

    /**
     * HTML 이스케이프
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * AJAX 요청 헬퍼 함수
     *
     * @param {string} url 요청 URL
     * @param {Object} options Fetch API 옵션
     * @return {Promise<Object>} JSON 응답
     */
    async function fetchJson(url, options = {}) {
        try {
            const response = await fetch(url, {
                ...options,
                headers: {
                    'Content-Type': 'application/json',
                    ...options.headers
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            throw error;
        }
    }

    /**
     * 작업 상태를 통합 관리 (아이콘 + 클래스)
     *
     * @param {string} taskId 작업 ID
     * @param {string} status 상태 (active, completed, failed, aborted)
     * @param {string|null} target 확장 식별자 (옵션)
     */
    function setTaskStatus(taskId, status, target = null) {
        // target이 있으면 조합된 ID, 없으면 기본 ID
        const domId = target ? `task-${taskId}-${target}` : `task-${taskId}`;
        const taskEl = document.getElementById(domId);
        if (!taskEl) return;

        const icon = taskEl.querySelector('.task-icon');

        // 기존 상태 클래스 제거
        taskEl.classList.remove('task-pending', 'task-active', 'task-completed', 'task-failed', 'task-aborted');

        // 새로운 상태 적용
        switch(status) {
            case 'active':
                taskEl.classList.add('task-active');
                if (icon) {
                    icon.innerHTML = getIconSvg('spinner');
                }
                break;

            case 'completed':
                taskEl.classList.add('task-completed');
                if (icon) {
                    icon.innerHTML = getIconSvg('completed');
                }
                break;

            case 'failed':
                taskEl.classList.add('task-failed');
                if (icon) {
                    icon.innerHTML = getIconSvg('failed');
                    icon.style.color = '#ef4444';
                }
                break;

            case 'aborted':
                taskEl.classList.add('task-aborted');
                if (icon) {
                    icon.innerHTML = getIconSvg('warning');
                }
                break;
        }

        // 그룹 상태 업데이트
        updateGroupStatus(taskEl);
    }

    /**
     * 그룹 상태 배지 업데이트
     *
     * 작업 상태가 변경될 때마다 해당 그룹의 상태 배지를 업데이트합니다.
     * - 대기 중: 회색 "대기"
     * - 진행 중: 파란색 "n/m" (완료/전체)
     * - 완료: 초록색 "완료" (자동 접힘)
     * - 실패: 빨간색 "실패"
     * - 중단: 주황색 "중단"
     *
     * @param {HTMLElement} taskEl 작업 요소
     */
    function updateGroupStatus(taskEl) {
        const group = taskEl.closest('.task-group');
        if (!group) return;

        const statusEl = group.querySelector('.task-group-status');
        if (!statusEl) return;

        const tasks = group.querySelectorAll('.task-item');
        const completedCount = group.querySelectorAll('.task-item.task-completed').length;
        const failedCount = group.querySelectorAll('.task-item.task-failed').length;
        const abortedCount = group.querySelectorAll('.task-item.task-aborted').length;
        const activeCount = group.querySelectorAll('.task-item.task-active').length;
        const totalCount = tasks.length;

        // 상태 클래스 초기화
        statusEl.classList.remove('status-pending', 'status-in-progress', 'status-completed', 'status-error', 'status-aborted');

        if (failedCount > 0) {
            statusEl.textContent = lang('status_failed');
            statusEl.classList.add('status-error');
        } else if (abortedCount > 0) {
            statusEl.textContent = lang('status_aborted');
            statusEl.classList.add('status-aborted');
        } else if (completedCount === totalCount) {
            statusEl.textContent = lang('status_completed');
            statusEl.classList.add('status-completed');
            // 완료된 그룹은 자동으로 접기
            group.classList.add('collapsed');
        } else if (activeCount > 0 || completedCount > 0) {
            statusEl.textContent = `${completedCount}/${totalCount}`;
            statusEl.classList.add('status-in-progress');
            // 진행 중인 그룹은 펼치기
            group.classList.remove('collapsed');
        } else {
            statusEl.textContent = lang('status_pending');
            statusEl.classList.add('status-pending');
        }
    }

    /**
     * 작업 그룹 토글 (아코디언)
     *
     * @param {string} groupId 그룹 ID
     */
    window.toggleTaskGroup = function(groupId) {
        const group = document.querySelector(`.task-group[data-group-id="${groupId}"]`);
        if (group) {
            group.classList.toggle('collapsed');
        }
    };

    /**
     * 공통 아이콘 SVG 생성
     *
     * @param {string} type 아이콘 타입 (completed, failed, warning, spinner)
     * @param {string} size 아이콘 크기 (기본값: 20px, 옵션: 24px 등)
     * @param {string} className 추가 CSS 클래스
     * @return {string} SVG HTML 문자열
     */
    function getIconSvg(type, size = '20px', className = '') {
        const svgs = {
            completed: `
                <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: ${size}; height: ${size};" class="${className}">
                    <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2" fill="none"/>
                    <path d="M6 10l3 3 5-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            `,
            failed: `
                <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: ${size}; height: ${size};" class="${className}">
                    <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2" fill="none"/>
                    <path d="M7 7l6 6M13 7l-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            `,
            warning: `
                <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: ${size}; height: ${size};" class="${className}">
                    <path d="M10 2L2 17h16L10 2z" stroke="currentColor" stroke-width="2" stroke-linejoin="round" fill="none"/>
                    <path d="M10 8v4M10 14v.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            `,
            spinner: '<div class="task-icon-spinner"></div>'
        };

        return svgs[type] || '';
    }

    // =========================================================================
    // 단계 이동 (세션 기반)
    // =========================================================================

    /**
     * 지정된 단계로 이동
     *
     * @param {number} step 이동할 단계 번호 (0-5)
     */
    function goToStep(step) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';

        const stepInput = document.createElement('input');
        stepInput.type = 'hidden';
        stepInput.name = 'go_to_step';
        stepInput.value = step.toString();
        form.appendChild(stepInput);

        document.body.appendChild(form);
        form.submit();
    }

    /**
     * 전역 함수 노출
     */
    function exposeGlobalFunctions() {
        // 다국어 함수
        window.lang = lang;

        // 유틸리티 함수
        window.escapeHtml = escapeHtml;
        window.fetchJson = fetchJson;

        // 단계 이동
        window.goToStep = goToStep;

        // Step 2
        window.recheckRequirements = recheckRequirements;
        window.copyPermissionCommand = copyPermissionCommand;

        // Step 3
        window.testDatabaseConnection = testDatabaseConnection;
        window.toggleReadDbFields = toggleReadDbFields;

        // Step 4
        window.retryInstallation = retryInstallation;
        window.toggleInstallationCard = toggleInstallationCard;
        window.toggleErrorDetail = toggleErrorDetail;
        window.abortInstallation = abortInstallation;
        window.resumeInstallationFromAborted = resumeInstallationFromAborted;
        window.goToSettingsWithConfirm = goToSettingsWithConfirm;

        // Step 5: 파일 체크
        window.recheckFiles = recheckFiles;
        window.copySetupCommand = copySetupCommand;
        window.copySetupCommandRelative = copySetupCommandRelative;
    }

    // =========================================================================
    // 전역 중단 감지 (모든 스텝)
    // =========================================================================

    /**
     * 설치 중단 여부 확인 및 Step 4로 리다이렉트
     * 모든 스텝에서 페이지 로드 시 자동 실행
     */
    /**
     * 설치 중단/실패 여부 확인 및 올바른 Step으로 리다이렉트
     * 모든 스텝에서 페이지 로드 시 자동 실행
     *
     * Step 체계 (2026-02-06 업데이트):
     * - Step 3: Configuration (DB 설정)
     * - Step 4: Extension Selection (확장 선택)
     * - Step 5: Installation (설치 진행)
     */
    async function checkAbortedInstallation() {
        try {
            const apiUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/state-management.php?action=get`;
            const response = await fetch(apiUrl);
            const result = await response.json();

            if (result && result.status) {
                const status = result.status;
                const currentStep = parseInt(window.CURRENT_STEP || '0', 10);
                const stateStep = parseInt(result.current_step || '0', 10);

                // Step 5 (Installation)에서는 checkAndStartInstallation()이 처리하므로 여기서는 무시
                if (currentStep === 5) {
                    return;
                }

                // aborted 또는 failed 상태일 때, state의 current_step과 현재 화면이 다르면 리다이렉트
                // 예: 설치 실패 후 Step 4 (확장 선택) 또는 Step 3 (DB 설정)으로 돌아가야 할 때
                if ((status === 'aborted' || status === 'failed') && currentStep !== stateStep) {
                    window.location.href = `${window.INSTALLER_BASE_URL}/`;
                }
            }
        } catch (error) {
            // 에러 시 무시 (네트워크 문제 등)
        }
    }

    // =========================================================================
    // Step 2: 권한 수정 안내 (B11-c)
    // =========================================================================

    /**
     * 권한 수정 명령어 클립보드 복사
     *
     * @param {HTMLButtonElement} btn 클릭된 복사 버튼
     */
    function copyPermissionCommand(btn) {
        const pre = btn.previousElementSibling;
        if (!pre) return;

        const command = pre.textContent;

        const showCopied = () => {
            const originalText = btn.textContent;
            btn.textContent = lang('copied');
            setTimeout(() => { btn.textContent = originalText; }, 2000);
        };

        const fallback = () => {
            const range = document.createRange();
            range.selectNodeContents(pre);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            document.execCommand('copy');
            selection.removeAllRanges();
            showCopied();
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(command).then(showCopied).catch(fallback);
        } else {
            fallback();
        }
    }

    // =========================================================================
    // Step 5: 필수 파일 체크 (B11-a)
    // =========================================================================

    /**
     * Step 5 시작 전 필수 파일(.env) 존재 여부 확인
     *
     * @returns {Promise<boolean>} 파일이 모두 존재하면 true
     */
    async function checkFilesBeforeInstall() {
        try {
            const checkUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/check-env.php`;
            const response = await fetch(checkUrl);
            const check = await response.json();

            const missingFiles = [];
            const commands = [];
            const relCommands = [];

            const basePath = check.path || '';
            const relativePath = check.relative_path || '.';
            const isWin = check.is_windows || false;
            if (!check.env_exists) {
                missingFiles.push('.env');
                if (isWin) {
                    const winBase = basePath.replace(/\//g, '\\');
                    commands.push(`copy ${winBase}\\.env.example ${winBase}\\.env`);
                    const winRel = relativePath.replace(/\//g, '\\');
                    relCommands.push(`copy ${winRel}\\.env.example ${winRel}\\.env`);
                } else {
                    commands.push(`cp ${basePath}/.env.example ${basePath}/.env`);
                    relCommands.push(`cp ${relativePath}/.env.example ${relativePath}/.env`);
                }
            }
            if (missingFiles.length > 0) {
                showFileSetupSection(missingFiles, commands, relCommands, basePath, isWin);
                return false;
            }
            return true;
        } catch (error) {
            // 체크 API 실패 시 설치 진행 허용 (install-process.php에서 재검증)
            return true;
        }
    }

    /**
     * 필수 파일 생성 안내 섹션 표시
     *
     * @param {string[]} missingFiles 누락된 파일명 배열
     * @param {string[]} [commands] 실행할 명령어 배열 (없으면 missingFiles로 생성)
     */
    function showFileSetupSection(missingFiles, commands, relCommands, basePath, isWin) {
        const section = document.getElementById('env-setup-section');
        if (!section) return;

        // 명령어가 전달되지 않은 경우 (install-process.php의 env_required 응답에서 호출)
        if (!commands || commands.length === 0) {
            const p = basePath || '';
            commands = [];
            relCommands = relCommands || [];
            if (missingFiles.includes('.env')) {
                if (isWin) {
                    const wp = p ? p.replace(/\//g, '\\') : '';
                    commands.push(wp ? `copy ${wp}\\.env.example ${wp}\\.env` : 'copy .env.example .env');
                    relCommands.push('copy .\\.env.example .\\.env');
                } else {
                    commands.push(p ? `cp ${p}/.env.example ${p}/.env` : 'cp .env.example .env');
                    relCommands.push('cp ./.env.example ./.env');
                }
            }
        }

        // 누락 파일 목록 표시
        const list = document.getElementById('missing-files-list');
        if (list) {
            list.innerHTML = missingFiles.map(f => `<li><code>${escapeHtml(f)}</code></li>`).join('');
        }

        // 절대경로 명령어 표시
        const commandEl = document.getElementById('file-setup-command');
        if (commandEl) {
            commandEl.textContent = commands.join(' && ');
        }

        // 상대경로 명령어 표시
        const relHint = document.getElementById('file-setup-relative-hint');
        const relBox = document.getElementById('file-setup-relative-box');
        const relCommandEl = document.getElementById('file-setup-command-relative');
        if (relCommands && relCommands.length > 0 && relHint && relBox && relCommandEl) {
            relCommandEl.textContent = relCommands.join(' && ');
            relHint.style.display = '';
            relBox.style.display = '';
        }

        // 섹션 표시
        section.classList.remove('hidden');

        // 버튼 상태 초기화
        const btn = document.getElementById('env-recheck-btn');
        if (btn) {
            btn.disabled = false;
            btn.textContent = lang('file_check_button');
        }

        // 상태 메시지 숨기기
        const statusEl = document.getElementById('env-check-status');
        if (statusEl) {
            statusEl.classList.add('hidden');
        }
    }

    /**
     * 필수 파일 재확인 (확인 버튼 클릭 시)
     */
    async function recheckFiles() {
        const btn = document.getElementById('env-recheck-btn');
        const statusEl = document.getElementById('env-check-status');

        if (btn) {
            btn.disabled = true;
            btn.textContent = lang('file_checking');
        }

        try {
            const checkUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/check-env.php`;
            const response = await fetch(checkUrl);
            const check = await response.json();

            if (check.env_exists) {
                // 파일 확인 완료 — 안내 섹션 숨기고 설치 시작
                const section = document.getElementById('env-setup-section');
                if (section) {
                    section.classList.add('hidden');
                }
                startInstallation();
            } else {
                // 아직 파일 미생성 — 안내 유지
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = lang('file_check_button');
                }

                // 여전히 누락된 파일 목록 갱신
                const stillMissing = [];
                if (!check.env_exists) stillMissing.push('.env');

                const list = document.getElementById('missing-files-list');
                if (list) {
                    list.innerHTML = stillMissing.map(f => `<li><code>${escapeHtml(f)}</code></li>`).join('');
                }

                if (statusEl) {
                    statusEl.textContent = lang('files_not_found_yet');
                    statusEl.classList.remove('hidden');
                }
            }
        } catch (error) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = lang('file_check_button');
            }
            if (statusEl) {
                statusEl.textContent = lang('files_not_found_yet');
                statusEl.classList.remove('hidden');
            }
        }
    }

    /**
     * 파일 생성 명령어 클립보드 복사
     */
    function copySetupCommand() {
        const commandEl = document.getElementById('file-setup-command');
        if (!commandEl) return;

        const command = commandEl.textContent;

        const showCopied = () => {
            const copyBtn = commandEl.closest('.code-box')?.querySelector('.btn-copy');
            if (copyBtn) {
                const originalText = copyBtn.textContent;
                copyBtn.textContent = lang('copied');
                setTimeout(() => { copyBtn.textContent = originalText; }, 2000);
            }
        };

        const fallback = () => {
            const range = document.createRange();
            range.selectNodeContents(commandEl);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            document.execCommand('copy');
            selection.removeAllRanges();
            showCopied();
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(command).then(showCopied).catch(fallback);
        } else {
            fallback();
        }
    }

    /**
     * 상대경로 명령어 클립보드 복사
     */
    function copySetupCommandRelative() {
        const commandEl = document.getElementById('file-setup-command-relative');
        if (!commandEl) return;

        const command = commandEl.textContent;

        const showCopied = () => {
            const copyBtn = commandEl.closest('.code-box')?.querySelector('.btn-copy');
            if (copyBtn) {
                const originalText = copyBtn.textContent;
                copyBtn.textContent = lang('copied');
                setTimeout(() => { copyBtn.textContent = originalText; }, 2000);
            }
        };

        const fallback = () => {
            const range = document.createRange();
            range.selectNodeContents(commandEl);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            document.execCommand('copy');
            selection.removeAllRanges();
            showCopied();
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(command).then(showCopied).catch(fallback);
        } else {
            fallback();
        }
    }

    // =========================================================================
    // 초기화
    // =========================================================================

    /**
     * DOMContentLoaded 이벤트에서 초기화
     */
    document.addEventListener('DOMContentLoaded', function() {
        // 전역 함수 노출
        exposeGlobalFunctions();

        // 다크모드 토글 초기화 (모든 스텝)
        initThemeToggle();

        // 전역 중단 감지 (모든 스텝)
        checkAbortedInstallation();

        // Step 1: 라이선스 동의
        initLicenseAgreement();

        // Step 2: 요구사항 검증
        initRequirementsCheck();

        // Step 3: DB 테스트 및 실시간 검증
        initDatabaseTest();
        initRealTimeValidation();
        initStep3FormSubmit();

        // Step 4: 확장 선택
        initExtensionSelection();

        // Step 5: 설치 진행
        initInstallation();
    });

})();
