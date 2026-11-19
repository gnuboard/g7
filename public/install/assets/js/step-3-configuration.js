/*
 * 설치 마법사 3 (환경 설정) 단계 스크립트 — 종전 뷰(views/3-configuration.php)의 인라인 <script> 를 옮겼다(#158).
 *
 * 웹서버가 `Content-Security-Policy: script-src 'self'` 를 걸면 인라인 스크립트·on* 속성이 실행되지 않아
 * 단계 로직·버튼이 멈췄다. 서버 값은 `#installer-step-data` JSON 블록(installerStepData())과
 * `#installer-data` 의 번역(installerLang())으로만 받고, 버튼은 data-action 위임(installer-boot.js)으로 묶는다.
 * 전역 함수 이름은 종전과 같다(다른 스크립트·data-action 이 그 이름으로 부른다).
 */

// 카드형 라디오(Vendor 모드·실행 방식) — 변경 시 같은 name 그룹 안에서만 .selected 동기화 (종전 카드 바로 아래 인라인 스크립트)
(function () {
    const cards = document.querySelectorAll('.vendor-mode-card');
    cards.forEach(function (card) {
        const input = card.querySelector('.vendor-mode-card-input');
        if (!input) return;
        input.addEventListener('change', function () {
            if (!input.checked) return;
            cards.forEach(function (c) {
                const other = c.querySelector('.vendor-mode-card-input');
                if (other && other.name === input.name) c.classList.remove('selected');
            });
            card.classList.add('selected');
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
        if (window.ExecutionModeUi && window.ExecutionModeUi.effectiveMode() === 'inprocess') return false;
        return mode !== 'bundled';
    },

    /**
     * PHP CLI 경로 검증이 필수인지 여부 — inprocess 에서는 선택사항.
     *
     * `detect-php` 는 「php 를 못 찾았다」와 「probe 자체를 돌릴 수 없다」를 한 값
     * (`default_php_available=false`)으로 접는다. exec 이 막힌 서버 — 이 안이 지원하려는 바로
     * 그 서버 — 에서는 후자가 참이고, 그때 검증을 필수로 걸면 **통과할 방법이 없다**:
     * [버전 확인] 도 같은 `validatePhpPath()` 를 거쳐 언제나 실패한다. 그 상태에서 Step 3 은
     * 막다른 길이 되고, 같은 화면이 「비워 두어도 됩니다」와 「검증되지 않았습니다」를
     * 동시에 말한다.
     *
     * inprocess 는 정의상 하위 프로세스를 띄우지 않으므로 PHP 실행 경로가 쓰이지 않는다.
     * 형제 축(`isComposerRequired`)이 이미 같은 이유로 같은 면제를 갖는다.
     */
    isPhpCliRequired() {
        if (window.ExecutionModeUi && window.ExecutionModeUi.effectiveMode() === 'inprocess') return false;
        return true;
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

        installerShow(summary);

        phpStatus.textContent = this.phpVerified
            ? installerLang('cli_status_verified')
            : installerLang('cli_status_not_verified');
        // 문구 색은 문구 전용 토큰을 쓴다 — 아이콘용 밝은 값은 alert-info 배경 위에서
        // 라이트 2.1:1 까지 내려간다.
        phpStatus.style.color = this.phpVerified ? 'var(--success-text)' : 'var(--error-text)';

        const composerOptional = !this.isComposerRequired();
        if (composerOptional) {
            composerStatus.textContent = installerLang('cli_status_optional_bundled');
            // 선언된 토큰만 쓴다 — `--text-muted-color` 는 이 저장소에 없어 폴백 #888 로
            // 고정됐고, 그러면 다크에서 이 문단만 팔레트를 벗어난다 (D16).
            composerStatus.style.color = 'var(--text-muted)';
        } else {
            composerStatus.textContent = this.composerVerified
                ? installerLang('cli_status_verified')
                : installerLang('cli_status_not_verified');
            composerStatus.style.color = this.composerVerified ? 'var(--success-text)' : 'var(--error-text)';
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
            installerHide(guide);
        }
    }
});

// ========================================================================
// 실행 방식 선택 ↔ Vendor 설치 방식 동조 (D33 · A-4)
// ========================================================================

/**
 * 라디오의 잠김 상태를 native `disabled` 와 `aria-disabled` 양쪽에 반영한다.
 *
 * 서버 렌더는 두 속성을 함께 붙이는데(잠긴 카드 4종), JS 가 동적으로 잠그거나 풀 때
 * `disabled` 만 만지면 보조기술에 전달되는 상태가 초기 렌더 시점에 고정된다.
 *
 * @param {HTMLInputElement|null} input    대상 입력 (라디오·체크박스)
 * @param {boolean}               disabled 잠글지 여부
 * @returns {void}
 */
function setInputDisabled(input, disabled) {
    if (!input) return;
    input.disabled = !!disabled;
    if (disabled) {
        input.setAttribute('aria-disabled', 'true');
    } else {
        input.removeAttribute('aria-disabled');
    }
}

/**
 * 실행 방식 카드 상태 관리.
 *
 * 유효 모드 = 선택값(auto 면 감지 결과). inprocess 가 유효하면 composer 카드를 비활성하고
 * 번들로 고정한다 (W2 — 숨기지 않고 사유 배지와 함께 남긴다). 판정 본체는 서버
 * (execution-mode.php)가 SSoT 이며, 여기서는 서버가 내려준 감지 결과를 화면에 반영만 한다.
 */
window.ExecutionModeUi = {
    card: document.getElementById('execution-mode-card'),

    detectedMode() {
        return this.card ? (this.card.dataset.detectedMode || 'subprocess') : 'subprocess';
    },

    selectedMode() {
        const input = document.querySelector('input[name="execution_mode"]:checked');
        return input ? input.value : 'auto';
    },

    effectiveMode() {
        const selected = this.selectedMode();
        return selected === 'auto' ? this.detectedMode() : selected;
    },

    /**
     * 감지 결과를 카드에 반영한다 (페이지 로드 시 서버 렌더 + PHP 경로 검증 후 재감지).
     */
    applyDetection(data) {
        if (!this.card || !data) return;
        this.card.dataset.detectedMode = data.detected_mode || 'subprocess';
        this.card.dataset.subprocessSelectable = data.subprocess_selectable ? '1' : '0';

        const autoStatus = document.getElementById('execution-mode-auto-status');
        if (autoStatus) {
            const label = data.detected_mode === 'inprocess'
                ? autoStatus.dataset.labelInprocess
                : autoStatus.dataset.labelSubprocess;
            const text = autoStatus.querySelector('.execution-mode-status-text');
            if (text) {
                let status = (autoStatus.dataset.template || ':mode').replace(':mode', label || '');
                // 서버가 형태 위반 PHP 경로를 기본 php 로 되돌려 판정했다면 그 사실을 배지에 붙인다 —
                // 붙이지 않으면 이 배지가 어느 바이너리 기준인지가 화면 어디에도 남지 않는다.
                if (data.php_binary_substituted && autoStatus.dataset.substitutedNote) {
                    status += ' ' + autoStatus.dataset.substitutedNote;
                }
                text.textContent = status;
            }
        }

        const subCard = this.card.querySelector('.execution-mode-card[data-mode="subprocess"]');
        const subInput = subCard ? subCard.querySelector('input[name="execution_mode"]') : null;
        const subStatus = document.getElementById('execution-mode-subprocess-status');
        if (subCard && subInput && subStatus) {
            const selectable = !!data.subprocess_selectable;
            setInputDisabled(subInput, !selectable);
            subCard.classList.toggle('disabled', !selectable);
            subStatus.classList.toggle('vendor-mode-card-status-ok', selectable);
            subStatus.classList.toggle('vendor-mode-card-status-error', !selectable);
            const text = subStatus.querySelector('.execution-mode-status-text');
            if (text) {
                const reasons = Array.isArray(data.reasons) && data.reasons.length ? ' — ' + data.reasons.join(' · ') : '';
                text.textContent = selectable ? subStatus.dataset.ok : (subStatus.dataset.blocked + reasons);
            }
            if (!selectable && subInput.checked) {
                const autoInput = this.card.querySelector('input[name="execution_mode"][value="auto"]');
                if (autoInput) {
                    autoInput.checked = true;
                    autoInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        }

        this.syncVendorCards();
    },

    /**
     * 유효 실행 모드에 맞춰 Vendor 설치 방식 카드를 고정/해제한다 (A-4).
     */
    syncVendorCards() {
        const inprocess = this.effectiveMode() === 'inprocess';
        const procOpenAvailable = this.card ? this.card.dataset.procOpenAvailable === '1' : true;
        const composerInput = document.querySelector('input[name="vendor_mode"][value="composer"]');
        const composerCard = composerInput ? composerInput.closest('.vendor-mode-card') : null;
        const composerStatus = document.getElementById('vendor-mode-composer-status');
        const bundledInput = document.querySelector('input[name="vendor_mode"][value="bundled"]');
        const notice = document.getElementById('execution-mode-vendor-notice');

        const composerAvailable = procOpenAvailable && !inprocess;
        if (composerInput && composerCard) {
            setInputDisabled(composerInput, !composerAvailable);
            composerCard.classList.toggle('disabled', !composerAvailable);
        }

        // 자동 선택도 함께 잠근다 — 서버가 bundled 로 보정하므로, 잠그지 않으면 화면과
        // 실제 동작이 갈린다. 상태 줄 문구도 사유로 갈아 끼운다.
        const autoInput = document.querySelector('input[name="vendor_mode"][value="auto"]');
        const autoCard = autoInput ? autoInput.closest('.vendor-mode-card') : null;
        const autoStatus = document.getElementById('vendor-mode-auto-status');
        if (autoInput && autoCard) {
            setInputDisabled(autoInput, inprocess);
            autoCard.classList.toggle('disabled', inprocess);
        }
        if (autoStatus) {
            autoStatus.classList.toggle('vendor-mode-card-status-ok', !inprocess);
            autoStatus.classList.toggle('vendor-mode-card-status-error', inprocess);
            const autoText = autoStatus.querySelector('.vendor-mode-status-text');
            if (autoText) {
                autoText.textContent = inprocess ? autoStatus.dataset.inprocess : autoStatus.dataset.ok;
            }
        }
        if (composerStatus) {
            composerStatus.classList.toggle('vendor-mode-card-status-ok', composerAvailable);
            composerStatus.classList.toggle('vendor-mode-card-status-error', !composerAvailable);
            const text = composerStatus.querySelector('.vendor-mode-status-text');
            if (text) {
                text.textContent = !procOpenAvailable
                    ? composerStatus.dataset.blocked
                    : (inprocess ? composerStatus.dataset.inprocess : composerStatus.dataset.ok);
            }
        }
        if (inprocess && bundledInput && !bundledInput.disabled && !bundledInput.checked) {
            bundledInput.checked = true;
            bundledInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        // 초기 숨김이 hidden 클래스라 보일 때는 클래스를 떼야 한다
        if (inprocess) {
            installerShow(notice);
        } else {
            installerHide(notice);
        }

        if (window.CliValidator) window.CliValidator.updateStatusDisplay();
    },

    /**
     * PHP 경로 기준으로 서버에 재감지를 요청한다 (PHP 경로 검증 직후).
     */
    async refresh(phpBinary) {
        if (!this.card) return;
        try {
            const response = await fetch('api/check-configuration.php?action=detect-execution-mode', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ php_binary: phpBinary || 'php' })
            });
            const data = await response.json();
            if (data && data.success) this.applyDetection(data);
        } catch (e) {
            // 감지 실패 시 서버 렌더 값 유지 — 설치 시작 시 다시 판정한다
        }
    }
};

document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'execution_mode') {
        window.ExecutionModeUi.syncVendorCards();
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
    installerHide(guide);
});

/**
 * 페이지 로드 시 PHP CLI / Composer 자동 감지
 */
async function initCliDetection() {
    const phpStatus = document.getElementById('cli-php-status');
    const composerStatus = document.getElementById('cli-composer-status');
    const summary = document.getElementById('cli-status-summary');

    installerShow(summary);
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

        // 상한 초과(429)는 「php 가 없다」가 아니다.
        //
        // `probe_binary` 버킷은 4개 액션이 공유하고 60회/300초다. 경로를 고쳐 가며 Step 3 을
        // 여러 번 새로고침하면 정상 서버에서도 넘는데, 종전에는 그 응답에 없는
        // `default_php_available`(undefined)을 falsy 로 읽어 필수 모드로 전환했다 —
        // 사유 없이 카드가 「(필수)」로 잠기고 [다음] 이 막힌다.
        if (response.status === 429) {
            return null;
        }

        const data = await response.json();

        if (!data.default_php_available && window.CliValidator.isPhpCliRequired()) {
            // 기본 php 사용 불가 → 필수 모드로 전환
            //
            // inprocess 에서는 전환하지 않는다. 그 환경의 `default_php_available=false`
            // 는 「php 가 없다」가 아니라 「probe 를 돌릴 수 없다」이고, 서버 자신이 그 사유를
            // `error_binary_probe_unavailable` 로 갈라 두었다. 필수로 바꾸면 제목이 「(필수)」가
            // 되고 토글이 잠기는데, 그 항목은 이 실행 방식에서 쓰이지 않는다.
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
        // 라디오와 같은 규칙 — disabled 만 바꾸면 보조기술에 잠김이 안내되지 않는다
        setInputDisabled(toggle, true);
        toggle.style.opacity = '0.5';
        toggle.style.cursor = 'not-allowed';
    }
}

/**
 * 검사 결과를 알림 상자로 그린다.
 *
 * 종전에는 컨테이너에 `test-success`/`test-error` 를 붙였으나 그 두 클래스는 CSS 에
 * 선언이 없어 Step 3 의 성공과 실패가 라이트·다크 양쪽에서 같은 색으로 나왔다
 * (§11.5 「상태색이 두 모드에서 모두 구분되어야 한다」). `.test-result .alert-success`
 * / `.alert-error` 는 이미 선언돼 있고 DB 검사(installer.js)가 쓰는 형태이므로 그쪽에 맞춘다.
 *
 * @param {HTMLElement} resultDiv 결과 컨테이너
 * @param {boolean} ok 성공 여부
 * @param {string} html 본문 — 호출부가 만든 HTML (종전 innerHTML 대입과 노출 폭이 같다)
 */
function setTestResult(resultDiv, ok, html) {
    resultDiv.className = 'test-result';
    resultDiv.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'error') + '">' + html + '</div>';
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
            setTestResult(resultDiv, true, data.message);
            window.CliValidator.setPhpVerified(true);
        } else {
            setTestResult(resultDiv, false, data.message);
            window.CliValidator.setPhpVerified(false);
        }
        // 실행 방식 감지는 PHP 경로에 따라 달라진다 — 검증 결과와 함께 다시 본다.
        // 형태 위반 경로를 서버가 기본 php 로 되돌렸다면 그 사실이 응답에 실려 오고,
        // applyDetection 이 배지 옆에 그 사유를 붙인다.
        await window.ExecutionModeUi.refresh(path);
    } catch (e) {
        setTestResult(resultDiv, false, installerLang('error_check_failed'));
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
            setTestResult(resultDiv, true, data.message);
            window.CliValidator.setComposerVerified(true);
            // 설치 안내 숨기기
            const guide = document.getElementById('composer-install-guide');
            installerHide(guide);
        } else {
            setTestResult(resultDiv, false, data.message);
            window.CliValidator.setComposerVerified(false);
            showComposerInstallGuide();
        }
    } catch (e) {
        setTestResult(resultDiv, false, installerLang('error_check_failed'));
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
        installerHide(g);
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

    html += '<p class="fix-guide-label u-mt-sm">' + installerLang('composer_install_guide_local') + '</p>';
    html += '<div class="code-box">';
    html += '<pre class="fix-command">curl -sS https://getcomposer.org/installer | php</pre>';
    html += '<button type="button" class="btn-copy" data-action="copyCliCommand">' + installerLang('copy_command') + '</button>';
    html += '</div>';

    // 호스팅 환경 안내 (항상 표시 — 표준 방법 실패 시 대안)
    html += '<p class="fix-guide-label u-mt-md">' + installerLang('composer_install_guide_hosting') + '</p>';

    html += '<div class="code-box">';
    html += '<pre class="fix-command">curl -o composer-setup.php https://getcomposer.org/installer</pre>';
    html += '<button type="button" class="btn-copy" data-action="copyCliCommand">' + installerLang('copy_command') + '</button>';
    html += '</div>';

    html += '<div class="code-box u-mt-xs">';
    html += '<pre class="fix-command">' + escapeHtml(phpPath) + ' -d allow_url_fopen=On composer-setup.php</pre>';
    html += '<button type="button" class="btn-copy" data-action="copyCliCommand">' + installerLang('copy_command') + '</button>';
    html += '</div>';

    html += '<p class="fix-guide-hint u-mt-sm">' + installerLang('composer_install_guide_pwd_hint') + '</p>';
    html += '<div class="code-box">';
    html += '<pre class="fix-command">pwd</pre>';
    html += '<button type="button" class="btn-copy" data-action="copyCliCommand">' + installerLang('copy_command') + '</button>';
    html += '</div>';

    html += '<p class="fix-guide-hint u-mt-sm">' + installerLang('composer_install_guide_phar_hint') + '</p>';

    html += '<p class="fix-guide-hint u-mt-sm">' + installerLang('composer_install_guide_link') + '</p>';

    commandsDiv.innerHTML = html;
    installerShow(guide);
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
        let html = '<strong>' + installerLang('detected_php_binaries') + '</strong><ul class="u-list-compact">';
        data.binaries.forEach(function(bin) {
            // 같은 값의 두 sink(선택 인자·링크 텍스트)를 둘 다 이스케이프한다 — 종전에는 인자만
            // 치환하고 링크 텍스트는 무가공이었다. 한 값은 한 규칙으로 다룬다.
            // SAPI 도 함께 보여 준다 — CLI 가 아닌 후보를 고르면 설치 태스크가 전부 실패한다.
            const safePath = escapeHtml(bin.path);
            const sapi = bin.sapi ? ' (' + escapeHtml(String(bin.sapi)) + ')' : '';
            html += '<li><a href="#" data-action="selectPhpBinary" data-arg="' + installerEscapeAttr(bin.path) + '" class="u-pointer">'
                + safePath + '</a> — PHP ' + escapeHtml(String(bin.version || '')) + sapi + '</li>';
        });
        html += '</ul>';
        setTestResult(resultDiv, true, html);
    } else {
        setTestResult(resultDiv, false, data.message || installerLang('no_php_detected'));
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

        // 상한 초과는 감지 실패가 아니다 — 사유를 그대로 알리고 상태는 건드리지 않는다.
        if (response.status === 429) {
            setTestResult(resultDiv, false, installerLang('error_probe_rate_limited'));

            return;
        }

        const data = await response.json();
        showDetectResult(data);

        if (!data.default_php_available && window.CliValidator.isPhpCliRequired()) {
            switchToRequiredMode();
        }
    } catch (e) {
        setTestResult(resultDiv, false, installerLang('error_check_failed'));
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
    if (this.value.trim()) {
        installerShow(wrapper, 'block');
    } else {
        installerHide(wrapper);
    }
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

        setTestResult(resultDiv, !!data.success, data.message);
    } catch (e) {
        setTestResult(resultDiv, false, installerLang('error_check_failed'));
    }
}
