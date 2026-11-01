/*
 * 설치 마법사 0 (환영) 단계 스크립트 — 종전 뷰(views/0-welcome.php)의 인라인 <script> 를 옮겼다(#158).
 *
 * 웹서버가 `Content-Security-Policy: script-src 'self'` 를 걸면 인라인 스크립트·on* 속성이 실행되지 않아
 * 단계 로직·버튼이 멈췄다. 서버 값은 `#installer-step-data` JSON 블록(installerStepData())과
 * `#installer-data` 의 번역(installerLang())으로만 받고, 버튼은 data-action 위임(installer-boot.js)으로 묶는다.
 * 전역 함수 이름은 종전과 같다(다른 스크립트·data-action 이 그 이름으로 부른다).
 */
/**
 * 언어 선택 변경 시 localStorage 저장 후 자동 제출
 */
document.addEventListener('DOMContentLoaded', function() {
    const languageSelect = document.getElementById('language-select');
    const form = languageSelect.closest('form');

    if (languageSelect && form) {
        const serverLocale = languageSelect.value; // PHP에서 렌더링된 값

        // 페이지 로드 시 localStorage와 서버 값 동기화
        if (window.installerLocale) {
            const savedLocale = window.installerLocale.get();

            if (savedLocale && savedLocale !== serverLocale) {
                // localStorage 값으로 select 업데이트
                languageSelect.value = savedLocale;

                // 서버와 동기화 (한 번만 실행)
                const syncKey = 'installer_locale_synced';
                const alreadySynced = sessionStorage.getItem(syncKey);

                if (!alreadySynced) {
                    sessionStorage.setItem(syncKey, '1');

                    // 언어 변경만 하고 다음 단계로 이동하지 않음을 표시
                    let hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'language_change_only';
                    hiddenInput.value = '1';
                    form.appendChild(hiddenInput);

                    // 자동 제출하여 세션 및 state.json 동기화
                    form.submit();
                    return;
                }
            }
        }

        // 언어 선택 변경 이벤트
        languageSelect.addEventListener('change', function() {
            const selectedLang = this.value;

            // localStorage에 저장
            if (window.installerLocale) {
                window.installerLocale.set(selectedLang);
            }

            // 언어 변경만 하고 다음 단계로 이동하지 않음을 표시
            let hiddenInput = form.querySelector('input[name="language_change_only"]');
            if (!hiddenInput) {
                hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'language_change_only';
                form.appendChild(hiddenInput);
            }
            hiddenInput.value = '1';

            // 폼 자동 제출 (세션 및 state.json 동기화)
            form.submit();
        });
    }
});

/**
 * Storage 권한 재확인
 */
function recheckPermission() {
    const btn = document.getElementById('recheck-btn');
    const originalText = btn.textContent;

    btn.disabled = true;
    btn.textContent = installerLang('permission_checking');

    // 페이지 새로고침 (권한이 변경되었을 수 있으므로)
    setTimeout(function() {
        window.location.reload();
    }, 500);
}

/**
 * chmod 명령어 복사
 */
function copyWelcomeCommand(btn) {
    const command = btn.previousElementSibling.textContent;
    navigator.clipboard.writeText(command).then(function() {
        const originalText = btn.textContent;
        btn.textContent = installerLang('copied');
        setTimeout(function() { btn.textContent = originalText; }, 2000);
    });
}

/**
 * 세션 쿠키 round-trip 사전 진단
 *
 * Step 0 진입 시 1회 실행. set 으로 세션에 nonce 저장 → verify 로 같은 세션의
 * nonce 복원 여부 확인. 실패 시 경고 배너 표시 (설치 진행은 차단하지 않음).
 *
 * `installation_status` 가 `not_started` 일 때만 검증 — 이미 설치 진행 중인
 * 화면 새로고침 시 nonce 가 verify 단계에서 소비되면 후속 검증이 영향 받지
 * 않도록.
 */
document.addEventListener('DOMContentLoaded', async function() {
    try {
        const baseUrl = window.INSTALLER_BASE_URL || '/install';
        const setResp = await fetch(baseUrl + '/api/session-probe.php?action=set', {
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!setResp.ok) return;

        const verifyResp = await fetch(baseUrl + '/api/session-probe.php?action=verify', {
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!verifyResp.ok) return;

        const verifyData = await verifyResp.json();
        if (verifyData && verifyData.matched === false) {
            const banner = document.getElementById('session-cookie-blocked-banner');
            if (banner) {
                banner.style.display = '';
            }
        }
    } catch (e) {
        // 네트워크 오류 / endpoint 미존재 — silent (Step 0 진행 자체에는 영향 없음)
    }
});
