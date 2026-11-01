/*
 * 설치 마법사 3 (환경 설정) 단계 스크립트 — 종전 뷰(views/3-configuration.php)의 인라인 <script> 를 옮겼다(#158).
 *
 * 웹서버가 `Content-Security-Policy: script-src 'self'` 를 걸면 인라인 스크립트·on* 속성이 실행되지 않아
 * 단계 로직·버튼이 멈췄다. 서버 값은 `#installer-step-data` JSON 블록(installerStepData())과
 * `#installer-data` 의 번역(installerLang())으로만 받고, 버튼은 data-action 위임(installer-boot.js)으로 묶는다.
 * 전역 함수 이름은 종전과 같다(다른 스크립트·data-action 이 그 이름으로 부른다).
 */

// Vendor 모드 카드 — 라디오 변경 시 .selected 클래스 동기화 (종전 카드 바로 아래 인라인 스크립트)
// Vendor 모드 카드 — 라디오 변경 시 .selected 클래스 동기화
(function () {
    const cards = document.querySelectorAll('.vendor-mode-card');
    cards.forEach(function (card) {
        const input = card.querySelector('.vendor-mode-card-input');
        if (!input) return;
        input.addEventListener('change', function () {
            if (input.checked) {
                cards.forEach(function (c) { c.classList.remove('selected'); });
                card.classList.add('selected');
            }
        });
    });
})();

// Step 3 전용 데이터 전달 (DB 테스트 상태)
window.DB_TEST_FLAGS = installerStepData().db_test_flags || { write: false, read: false, writeHash: null, readHash: null };

// ========================================================================
// PHP CLI / Composer 검증 상태 관리
// ========================================================================

/**
 * CLI 검증 상태 추적 객체
 */
window.CliValidator = {
    phpVerified: false,
    composerVerified: false,
    cliRequired: false, // 기본 php 사용 불가 시 true

    /**
     * Composer 검증이 필수인지 여부 — vendor_mode='bundled' 에서는 선택사항.
     */
    isComposerRequired() {
        const mode = this.getSelectedVendorMode();
        return mode !== 'bundled';
    },

    /**
     * 현재 선택된 vendor_mode 값 (auto/composer/bundled).
     */
    getSelectedVendorMode() {
        const input = document.querySelector('input[name="vendor_mode"]:checked');
        return input ? input.value : 'auto';
    },

    setPhpVerified(verified) {
        this.phpVerified = verified;
        this.updateStatusDisplay();
        this.updateSubmitButton();
    },

    setComposerVerified(verified) {
        this.composerVerified = verified;
        this.updateStatusDisplay();
        this.updateSubmitButton();
    },

    isValid() {
        // CLI 섹션이 열려 있으면 (필수이든 선택이든) 검증 필요
        const section = document.getElementById('php-cli-section');
        if (section && section.classList.contains('hidden')) {
            // 섹션이 닫혀 있고 필수가 아니면 검증 불필요
            if (!this.cliRequired) return true;
        }

        if (!this.phpVerified) return false;

        // Composer 는 vendor_mode='bundled' 에서는 검증 불필요
        if (this.isComposerRequired() && !this.composerVerified) return false;

        return true;
    },

    updateStatusDisplay() {
        const summary = document.getElementById('cli-status-summary');
        const phpStatus = document.getElementById('cli-php-status');
        const composerStatus = document.getElementById('cli-composer-status');
        if (!summary || !phpStatus || !composerStatus) return;

        summary.style.display = '';

        phpStatus.textContent = this.phpVerified
            ? installerLang('cli_status_verified')
            : installerLang('cli_status_not_verified');
        phpStatus.style.color = this.phpVerified ? 'var(--success-color)' : 'var(--error-color)';

        const composerOptional = !this.isComposerRequired();
        if (composerOptional) {
            composerStatus.textContent = installerLang('cli_status_optional_bundled');
            composerStatus.style.color = 'var(--text-muted-color, #888)';
        } else {
            composerStatus.textContent = this.composerVerified
                ? installerLang('cli_status_verified')
                : installerLang('cli_status_not_verified');
            composerStatus.style.color = this.composerVerified ? 'var(--success-color)' : 'var(--error-color)';
        }
    },

    updateSubmitButton() {
        // submit 버튼 상태는 validateStep3Form에서 최종 체크하므로 여기서는 시각적 힌트만
    }
};

// vendor_mode 라디오 변경 시 CLI 검증 상태 표시 갱신 (Composer 검증 필수/선택 전환)
document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'vendor_mode') {
        window.CliValidator.updateStatusDisplay();

        // bundled 모드 전환 시 Composer 설치 안내 숨김
        if (e.target.value === 'bundled') {
            const guide = document.getElementById('composer-install-guide');
            if (guide) guide.style.display = 'none';
        }
    }
});

// PHP CLI 설정 토글
document.getElementById('show-php-cli-settings')?.addEventListener('change', function() {
    const section = document.getElementById('php-cli-section');
    if (this.checked) {
        section.classList.remove('hidden');
        // 토글을 켰을 때 자동 감지 실행
        initCliDetection();
    } else {
        section.classList.add('hidden');
    }
});

// PHP CLI / Composer 입력 변경 시 검증 상태 무효화
document.getElementById('php_binary')?.addEventListener('input', function() {
    window.CliValidator.setPhpVerified(false);
    // Composer도 PHP 경로에 의존하므로 (.phar 실행) 재검증 필요
    window.CliValidator.setComposerVerified(false);
    const phpResult = document.getElementById('php-binary-test-result');
    if (phpResult) { phpResult.classList.add('hidden'); phpResult.innerHTML = ''; }
    const composerResult = document.getElementById('composer-test-result');
    if (composerResult) { composerResult.classList.add('hidden'); composerResult.innerHTML = ''; }
});

document.getElementById('composer_binary')?.addEventListener('input', function() {
    window.CliValidator.setComposerVerified(false);
    const composerResult = document.getElementById('composer-test-result');
    if (composerResult) { composerResult.classList.add('hidden'); composerResult.innerHTML = ''; }
    // Composer 설치 안내 숨기기
    const guide = document.getElementById('composer-install-guide');
    if (guide) guide.style.display = 'none';
});

/**
 * 페이지 로드 시 PHP CLI / Composer 자동 감지
 */
async function initCliDetection() {
    const phpStatus = document.getElementById('cli-php-status');
    const composerStatus = document.getElementById('cli-composer-status');
    const summary = document.getElementById('cli-status-summary');

    if (summary) summary.style.display = '';
    if (phpStatus) { phpStatus.textContent = installerLang('cli_status_checking'); phpStatus.style.color = ''; }
    if (composerStatus) { composerStatus.textContent = installerLang('cli_status_checking'); composerStatus.style.color = ''; }

    // PHP 감지 먼저 실행 (composer 감지 결과 포함) → Composer 검증
    const composerData = await detectAndVerifyPhp();
    await detectAndVerifyComposer(composerData);
}

/**
 * PHP CLI 자동 감지 및 검증
 */
async function detectAndVerifyPhp() {
    try {
        const response = await fetch('api/check-configuration.php?action=detect-php');
        const data = await response.json();

        if (!data.default_php_available) {
            // 기본 php 사용 불가 → 필수 모드로 전환
            switchToRequiredMode();

            // 감지된 바이너리가 있으면 첫 번째로 자동 선택
            if (data.success && data.binaries && data.binaries.length > 0) {
                const phpInput = document.getElementById('php_binary');
                if (phpInput && (phpInput.value === 'php' || phpInput.value === '')) {
                    phpInput.value = data.binaries[0].path;
                }
                // 자동 감지 결과 표시
                showDetectResult(data);
                // 자동 선택된 경로로 검증
                await testPhpBinary();
            }
        } else {
            // 기본 php 사용 가능
            if (data.success && data.binaries) {
                showDetectResult(data);
            }

            // 현재 입력된 경로로 검증
            await testPhpBinary();
        }

        return data.composer || null;
    } catch (e) {
        // 감지 실패 시 수동 입력 유도
        switchToRequiredMode();
        return null;
    }
}

/**
 * Composer 자동 감지 및 검증
 * detect-php API 응답의 composer 결과를 사용하여 사전 입력
 */
async function detectAndVerifyComposer(composerData) {
    const composerInput = document.getElementById('composer_binary');

    // detect-php 응답에 composer 감지 결과가 있으면 사전 입력
    if (composerData && composerData.found && composerInput) {
        if (!composerInput.value.trim()) {
            composerInput.value = composerData.path === 'composer' ? '' : composerData.path;
        }
    }

    await testComposer();
}

/**
 * 기본 php 미감지 시 필수 모드로 전환
 */
function switchToRequiredMode() {
    window.CliValidator.cliRequired = true;

    const title = document.getElementById('php-cli-title');
    if (title) title.textContent = installerLang('php_cli_settings_required');

    const helpText = document.getElementById('php-cli-help-text');
    if (helpText) helpText.textContent = installerLang('php_cli_settings_help_required');

    // 섹션 강제 오픈 + 토글 비활성화
    const section = document.getElementById('php-cli-section');
    if (section) section.classList.remove('hidden');

    const toggle = document.getElementById('show-php-cli-settings');
    if (toggle) {
        toggle.checked = true;
        toggle.disabled = true;
        toggle.style.opacity = '0.5';
        toggle.style.cursor = 'not-allowed';
    }
}

// PHP 바이너리 버전 확인
async function testPhpBinary() {
    const path = document.getElementById('php_binary').value.trim() || 'php';
    const resultDiv = document.getElementById('php-binary-test-result');

    resultDiv.className = 'test-result';
    resultDiv.innerHTML = '<span class="loading-spinner"></span> ' + installerLang('checking');

    try {
        const response = await fetch('api/check-configuration.php?action=test-php-binary', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: path })
        });
        const data = await response.json();

        if (data.success) {
            resultDiv.className = 'test-result test-success';
            resultDiv.innerHTML = data.message;
            window.CliValidator.setPhpVerified(true);
        } else {
            resultDiv.className = 'test-result test-error';
            resultDiv.innerHTML = data.message;
            window.CliValidator.setPhpVerified(false);
        }
    } catch (e) {
        resultDiv.className = 'test-result test-error';
        resultDiv.innerHTML = installerLang('error_check_failed');
        window.CliValidator.setPhpVerified(false);
    }
}

// Composer 버전 확인
async function testComposer() {
    const composerPath = document.getElementById('composer_binary').value.trim();
    const phpPath = document.getElementById('php_binary').value.trim() || 'php';
    const resultDiv = document.getElementById('composer-test-result');

    resultDiv.className = 'test-result';
    resultDiv.innerHTML = '<span class="loading-spinner"></span> ' + installerLang('composer_checking');

    try {
        const response = await fetch('api/check-configuration.php?action=test-composer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: composerPath, php_path: phpPath })
        });
        const data = await response.json();

        if (data.success) {
            resultDiv.className = 'test-result test-success';
            resultDiv.innerHTML = data.message;
            window.CliValidator.setComposerVerified(true);
            // 설치 안내 숨기기
            const guide = document.getElementById('composer-install-guide');
            if (guide) guide.style.display = 'none';
        } else {
            resultDiv.className = 'test-result test-error';
            resultDiv.innerHTML = data.message;
            window.CliValidator.setComposerVerified(false);
            showComposerInstallGuide();
        }
    } catch (e) {
        resultDiv.className = 'test-result test-error';
        resultDiv.innerHTML = installerLang('error_check_failed');
        window.CliValidator.setComposerVerified(false);
        showComposerInstallGuide();
    }
}

/**
 * Composer 설치 안내를 PHP 경로 반영하여 동적 생성.
 *
 * vendor_mode='bundled' 에서는 Composer 가 불필요하므로 안내를 띄우지 않는다.
 */
function showComposerInstallGuide() {
    // 번들 모드에서는 Composer 가 불필요 → 안내 생략
    if (window.CliValidator && !window.CliValidator.isComposerRequired()) {
        const g = document.getElementById('composer-install-guide');
        if (g) g.style.display = 'none';
        return;
    }

    const guide = document.getElementById('composer-install-guide');
    if (!guide) return;

    const phpPath = document.getElementById('php_binary').value.trim() || 'php';
    const commandsDiv = document.getElementById('composer-install-commands');
    if (!commandsDiv) return;

    let html = '';

    // 표준 설치 안내 (항상 표시)
    html += '<p class="fix-guide-label">' + installerLang('composer_install_guide_global') + '</p>';
    html += '<div class="code-box">';
    html += '<pre class="fix-command">curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer</pre>';
    html += '<button type="button" class="btn-copy" data-action="copyCliCommand">' + installerLang('copy_command') + '</button>';
    html += '</div>';

    html += '<p class="fix-guide-label" style="margin-top: var(--spacing-sm);">' + installerLang('composer_install_guide_local') + '</p>';
    html += '<div class="code-box">';
    html += '<pre class="fix-command">curl -sS https://getcomposer.org/installer | php</pre>';
    html += '<button type="button" class="btn-copy" data-action="copyCliCommand">' + installerLang('copy_command') + '</button>';
    html += '</div>';

    // 호스팅 환경 안내 (항상 표시 — 표준 방법 실패 시 대안)
    html += '<p class="fix-guide-label" style="margin-top: var(--spacing-md);">' + installerLang('composer_install_guide_hosting') + '</p>';

    html += '<div class="code-box">';
    html += '<pre class="fix-command">curl -o composer-setup.php https://getcomposer.org/installer</pre>';
    html += '<button type="button" class="btn-copy" data-action="copyCliCommand">' + installerLang('copy_command') + '</button>';
    html += '</div>';

    html += '<div class="code-box" style="margin-top: var(--spacing-xs);">';
    html += '<pre class="fix-command">' + escapeHtml(phpPath) + ' -d allow_url_fopen=On composer-setup.php</pre>';
    html += '<button type="button" class="btn-copy" data-action="copyCliCommand">' + installerLang('copy_command') + '</button>';
    html += '</div>';

    html += '<p class="fix-guide-hint" style="margin-top: var(--spacing-sm);">' + installerLang('composer_install_guide_pwd_hint') + '</p>';
    html += '<div class="code-box">';
    html += '<pre class="fix-command">pwd</pre>';
    html += '<button type="button" class="btn-copy" data-action="copyCliCommand">' + installerLang('copy_command') + '</button>';
    html += '</div>';

    html += '<p class="fix-guide-hint" style="margin-top: var(--spacing-sm);">' + installerLang('composer_install_guide_phar_hint') + '</p>';

    html += '<p class="fix-guide-hint" style="margin-top: var(--spacing-sm);">' + installerLang('composer_install_guide_link') + '</p>';

    commandsDiv.innerHTML = html;
    guide.style.display = '';
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
 * PHP 감지 결과 표시 (감지 결과 영역)
 */
function showDetectResult(data) {
    const resultDiv = document.getElementById('php-detect-result');
    if (!resultDiv) return;

    if (data.success && data.binaries && data.binaries.length > 0) {
        let html = '<strong>' + installerLang('detected_php_binaries') + '</strong><ul style="margin: var(--spacing-xs) 0; padding-left: var(--spacing-lg);">';
        data.binaries.forEach(function(bin) {
            html += '<li><a href="#" data-action="selectPhpBinary" data-arg="' + installerEscapeAttr(bin.path) + '" style="cursor: pointer;">'
                + bin.path + '</a> — PHP ' + bin.version + '</li>';
        });
        html += '</ul>';
        resultDiv.className = 'test-result test-success';
        resultDiv.innerHTML = html;
    } else {
        resultDiv.className = 'test-result test-error';
        resultDiv.innerHTML = data.message || installerLang('no_php_detected');
    }
}

/**
 * 감지된 PHP 바이너리 선택 시 자동 검증
 */
async function selectPhpBinary(path) {
    document.getElementById('php_binary').value = path;
    // PHP 검증 상태 초기화 후 재검증
    window.CliValidator.setPhpVerified(false);
    window.CliValidator.setComposerVerified(false);
    await testPhpBinary();
    // Composer도 PHP 경로 의존이므로 재검증
    await testComposer();
}

// PHP 바이너리 자동 감지 (수동 버튼)
async function detectPhpBinaries() {
    const resultDiv = document.getElementById('php-detect-result');

    resultDiv.className = 'test-result';
    resultDiv.innerHTML = '<span class="loading-spinner"></span> ' + installerLang('detecting_php');

    try {
        const response = await fetch('api/check-configuration.php?action=detect-php');
        const data = await response.json();
        showDetectResult(data);

        if (!data.default_php_available) {
            switchToRequiredMode();
        }
    } catch (e) {
        resultDiv.className = 'test-result test-error';
        resultDiv.innerHTML = installerLang('error_check_failed');
    }
}

/**
 * 명령어 복사 (Composer 설치 안내)
 */
function copyCliCommand(btn) {
    const command = btn.previousElementSibling.textContent;
    navigator.clipboard.writeText(command).then(function() {
        const originalText = btn.textContent;
        btn.textContent = installerLang('copied');
        setTimeout(function() { btn.textContent = originalText; }, 2000);
    });
}

// 페이지 로드 시 CLI 섹션이 열려 있으면 자동 감지 실행
document.addEventListener('DOMContentLoaded', function() {
    const section = document.getElementById('php-cli-section');
    const toggle = document.getElementById('show-php-cli-settings');
    if (section && !section.classList.contains('hidden')) {
        initCliDetection();
    } else {
        // 섹션이 닫혀 있어도 기본 php/composer 사용 가능 여부는 확인해야 함
        // 사용 불가 시 필수 모드로 전환되면서 섹션이 열림
        initCliDetection();
    }
});

// 코어 업데이트 _pending 경로 입력 시 체크 버튼 표시
document.querySelector('input[name="core_update_pending_path"]')?.addEventListener('input', function() {
    const wrapper = document.getElementById('core-pending-check-wrapper');
    wrapper.style.display = this.value.trim() ? 'block' : 'none';
});

// 코어 _pending 경로 퍼미션/소유자 체크
async function checkCorePendingPath() {
    const path = document.querySelector('input[name="core_update_pending_path"]').value.trim();
    const resultDiv = document.getElementById('core-pending-check-result');

    if (!path) return;

    resultDiv.className = 'test-result';
    resultDiv.innerHTML = '<span class="loading-spinner"></span> ' + installerLang('checking');

    try {
        const response = await fetch('api/check-configuration.php?action=check-core-pending-path&path=' + encodeURIComponent(path));
        const data = await response.json();

        if (data.success) {
            resultDiv.className = 'test-result test-success';
            resultDiv.innerHTML = data.message;
        } else {
            resultDiv.className = 'test-result test-error';
            resultDiv.innerHTML = data.message;
        }
    } catch (e) {
        resultDiv.className = 'test-result test-error';
        resultDiv.innerHTML = installerLang('error_check_failed');
    }
}
