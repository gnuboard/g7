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
     * 서버 `lang()`(includes/functions.php)과 같은 치환 규칙을 쓴다 — `:placeholder`
     * 를 값으로 바꾼다. 치환값을 받지 못하면 자리표시자가 그대로 남으므로, 자리표시자를
     * 가진 키를 번역할 때는 반드시 replace 를 함께 넘긴다.
     *
     * @param {string} key 번역 키
     * @param {Object} [replace] 자리표시자 치환값 (`{ errors: '...' }` → `:errors`)
     * @return {string} 번역된 문자열
     */
    function lang(key, replace) {
        // 서버 번역 표에는 중첩 그룹이 있다(`fields` 등) — `INSTALLER_LANG` 은 그 배열을 그대로
        // json_encode 한 것이라 `INSTALLER_LANG['fields.db_prefix']` 는 존재하지 않는다. 평면
        // 조회만 하면 그 키들은 번역 대신 **키 문자열 자체**가 화면에 나간다
        // (`fields.db_prefix은(는) 영문 소문자로…`). 점 경로를 따라 내려간다 (10차).
        let message = key;
        const table = window.INSTALLER_LANG;
        if (table) {
            if (table[key]) {
                message = table[key];
            } else if (key.indexOf('.') !== -1) {
                let node = table;
                for (const segment of key.split('.')) {
                    if (node === null || typeof node !== 'object' || !(segment in node)) {
                        node = null;
                        break;
                    }
                    node = node[segment];
                }
                if (typeof node === 'string' && node !== '') {
                    message = node;
                }
            }
        }

        if (replace && typeof replace === 'object') {
            Object.keys(replace).forEach((placeholder) => {
                message = message.split(':' + placeholder).join(String(replace[placeholder]));
            });
        }

        return message;
    }

    /**
     * 합성 태스크 키(taskId:target)를 **첫 콜론**에서만 가른다 (22차 #22-9).
     *
     * 서버(`explode(':', $key, 2)`)·모니터(indexOf/substring)와 같은 규칙이다 — 전체
     * split(':') 은 콜론이 둘 이상인 키에서 target 을 자른다. 분해 규칙이 파일마다 다르면
     * 같은 키가 지점마다 다른 DOM id 로 계산된다.
     *
     * @param {string} key 합성 키 또는 단일 태스크 키
     * @returns {[string, string|null]} [taskId, target]
     */
    function splitCompositeTaskKey(key) {
        const raw = String(key ?? '');
        const sep = raw.indexOf(':');
        if (sep === -1) {
            return [raw, null];
        }
        return [raw.slice(0, sep), raw.slice(sep + 1)];
    }

    /**
     * 라디오 잠금/해제 — disabled 와 aria-disabled 를 함께 움직인다.
     *
     * disabled 만 바꾸면 서버가 렌더한 aria-disabled 초기값이 그대로 남아 보조기술에
     * 잘못된 상태가 안내된다. Step 3 은 자기 인라인 스크립트에 같은 헬퍼를 두고 있다
     * (views/3-configuration.php) — 그쪽 스코프에서는 이 파일의 함수를 볼 수 없다.
     * 둘의 동작은 동일해야 한다.
     *
     * 라디오 전용이 아니다 — 체크박스도 같은 규칙을 받는다. 잠금을 `disabled` 로만
     * 표현하면 시각적으로는 흐려지는데 보조기술에는 서버 렌더 초기값이 그대로 남는다.
     *
     * @param {HTMLElement} input 라디오·체크박스 등 입력 요소
     * @param {boolean} disabled 잠금 여부
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
     * 상태변경 API 요청 헤더 — 세션 CSRF 토큰을 싣는다.
     *
     * 설치 소유권은 서버가 세션으로 판정하므로 소유 토큰 원문은 보내지 않는다.
     *
     * @return {Object} fetch headers
     */
    function stateChangeHeaders() {
        return {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.INSTALLER_CSRF || '',
        };
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

    /* region:web-exposure — Step 2 게이트·웹 노출 판정 (vitest 가 이 구간을 그대로 평가한다) */

    /**
     * Step 2 [다음 단계] 게이트 상태 (D3 · D21).
     *
     * 서버 집계(all_required_passed)와 브라우저 웹 노출 판정을 함께 본다 — 노출 점검은
     * 판정 주체가 브라우저라 서버 집계에 없다. 상태 값:
     *   'pending'   프로브 진행 중 (fail-closed — 다음 비활성)
     *   'safe'      전 대상 보호 확인
     *   'exposed'   노출 확정 (설치 차단 — D3)
     *   'unknown'   판정 불능 (운영자 수동 확인 대기 — D21)
     *   'confirmed' 판정 불능 상태에서 운영자가 '확인했음' 체크 (진행 허용 — D21)
     */
    let step2ServerAllPassed = false;
    // 기본값은 **판정 불능**이다. 「안전」으로 두면 게이트 갱신 순서가 한 번만 바뀌어도
    // 아무 판정 없이 열린다 — 이 축의 기본값은 언제나 fail-closed 쪽이어야 한다.
    let step2ExposureState = 'unknown';

    /**
     * 게이트 상태 두 축을 함께 갱신한다 (쓰기 단일 지점).
     *
     * @param {boolean} serverAllPassed 서버 all_required_passed
     * @param {string} exposureState 노출 판정 상태
     */
    function setStep2GateState(serverAllPassed, exposureState) {
        step2ServerAllPassed = !!serverAllPassed;
        step2ExposureState = exposureState;
    }

    /**
     * 노출 판정 축만 갱신한다 (프로브 완료·'확인했음' 체크 경로).
     *
     * @param {string} exposureState 노출 판정 상태
     */
    function setStep2ExposureState(exposureState) {
        step2ExposureState = exposureState;
    }

    /**
     * Step 2 의 [다음 단계]/[다시 검사] 버튼 상태를 게이트 상태로부터 갱신한다.
     *
     * §12 W1: 비활성은 속성과 시각 표현을 함께 준다 (버튼은 언제나 자리에 있고 상태만 바뀐다).
     */
    function updateStep2Gate() {
        const nextBtn = document.getElementById('next-btn');
        const recheckBtn = document.getElementById('recheck-btn');
        if (!nextBtn) return;

        const exposureOk = step2ExposureState === 'safe' || step2ExposureState === 'confirmed';
        const pass = step2ServerAllPassed && exposureOk;

        nextBtn.classList.remove('hidden');
        nextBtn.disabled = !pass;
        nextBtn.setAttribute('aria-disabled', pass ? 'false' : 'true');

        if (recheckBtn) {
            recheckBtn.classList.toggle('hidden', pass);
        }
    }

    /**
     * 프로젝트 루트의 웹 경로를 파생한다 (B-4 프로브 기준점).
     *
     * 노출 프로브 대상(`/.env.example` 등)은 **프로젝트 루트** 기준이다 — 앱 루트(public)
     * 기준이 아니다. 문서루트=프로젝트 루트 배치에서 인스톨러는 `/public/install` 로
     * 서빙되므로, 앱 루트(`/public`)에 대고 프로브하면 정작 노출된 프로젝트 루트(`/`)를
     * 지나쳐 노출을 영원히 놓친다 (D3 의 판정 대상이 바로 그 배치다).
     *
     * 파생: INSTALLER_BASE_URL 에서 `/install` 을 벗기고, 남은 꼬리가 `/public` 이면
     * 그것도 벗긴다 — `/install` → `` (표준 배치: 프로젝트 루트는 웹 밖, 프로브는 안전으로
     * 수렴) / `/public/install` → `` / `/g7/public/install` → `/g7`.
     *
     * @returns {string} origin 포함 프로젝트 루트 URL (후행 슬래시 없음)
     */
    function getProjectRootUrl() {
        return getProjectRootCandidates()[0];
    }
    /* 프로덕션 소비처는 `resolveProjectRoot` 하나다. 이 함수는 그 기준점을 이름으로 설명하고
       vitest 가 파생 규칙(꼬리 벗기기)을 직접 재는 진입점이다. */

    /**
     * 프로젝트 루트 URL **후보**를 우선순위 순으로 열거한다. **고르지는 않는다.**
     *
     * 꼬리 판정은 대소문자를 구분하지 않는다 — 대소문자 무시 파일시스템(Windows·IIS·macOS)
     * 에서 운영자가 `/Install/` 로 접속하면 기준 URL 이 그 표기 그대로 들어오는데, 구분해서
     * 벗기면 프로젝트 루트가 `/Public/Install` 로 잡혀 전 대상이 404 → 「보호 확인됨」 으로
     * 조용히 수렴한다 (installerDeriveBaseUrl 과 같은 규약).
     *
     * `/public` 꼬리를 벗기는 것은 **추측이다.** 인스톨러가 위임을 거쳐 `<루트>/install/` 로
     * 열렸는지, 실제 위치 `<루트>/public/install/` 로 열렸는지 URL 만으로는 구분되지 않고,
     * 프로젝트 디렉토리 이름이 실제로 `public` 인 배치에서는 한 칸 위를 가리키게 된다.
     *
     * 한때 이 추측을 **양성 대조로 골라** 실측으로 바꾸려 했으나, 그러면 대조가 게이트가
     * 아니라 후보 탐색기가 되어 「대조 실패 → 판정 불능」에 도달할 입력이 사라진다
     * (`resolveProjectRoot` 주석 참조). 그래서 지금은 **언제나 첫 후보만** 쓰고, 두 번째
     * 후보는 그 추측이 있었다는 사실을 남기는 열거 항목이다 — 소비처는 없다. 골라 쓰는
     * 형태로 되돌리지 말 것.
     *
     * @returns {string[]} origin 포함 후보 URL 목록 (후행 슬래시 없음, 중복 제거)
     */
    function getProjectRootCandidates() {
        const base = (window.INSTALLER_BASE_URL || '').replace(/\/install\/?$/i, '');
        const stripped = base.replace(/\/public$/i, '');
        const origin = window.location.origin;

        return stripped === base
            ? [`${origin}${base}`]
            : [`${origin}${stripped}`, `${origin}${base}`];
    }

    /**
     * 프로젝트 루트를 정하고, 그 루트에서 양성 대조를 잰다.
     *
     * **후보를 바꾸지 않는다.** 한때 이 함수는 「대조가 열리는 후보를 찾을 때까지 후보를
     * 갈아치우는」 형태였는데, 그러면 대조가 게이트가 아니라 **후보 탐색기**가 된다 —
     * `judgeExposureResults` 의 `controls.some(!reachable)` 분기에 도달할 수 있는 입력이
     * 사라져, 위임(mod_rewrite)이 죽은 레이아웃 ② 가 두 번째 후보에서 통과해 「보호
     * 확인됨」으로 수렴하고 설치가 완주했다. 그 사이트는 첫 화면 말고는 전 라우트가 404 다.
     *
     * 「프로젝트 디렉토리 이름이 실제로 `public`」과 「위임 사망」은 화면에서 관측이 완전히
     * 동일하다. 구분할 수 없으므로 구분을 시도하지 않고 **둘 다** 판정 불능(D21 운영자 확인
     * 경로)으로 보낸다 — 전자는 막히는 것이 아니라 한 단계를 더 밟는다. 노출 확정은 언제나
     * 이 대조보다 우선한다(D3 불변).
     *
     * 대조가 없으면(서버가 대조 대상을 만들지 못한 배치) 첫 후보를 그대로 쓴다 — 고를
     * 근거가 없을 때 후보를 바꾸는 것은 또 다른 추측이다.
     *
     * @param {Array<{path: string, marker: string, label: string}>} controls 대조 대상
     * @returns {Promise<{root: string, controlResults: Array}>}
     */
    async function resolveProjectRoot(controls) {
        const root = getProjectRootCandidates()[0];

        if (!controls.length) {
            return { root, controlResults: [] };
        }

        const controlResults = await Promise.all(
            controls.map((c) => probeExposureControl(root, c))
        );

        return { root, controlResults };
    }

    /**
     * 서버 요구사항 응답으로부터 노출 게이트의 **초기** 상태를 정한다 (B-4 · D3).
     *
     * 서버가 `web_exposure` 항목을 내려주지 않으면 「안전」이 아니라 **판정 불능**이다 —
     * 인스톨러 화면과 서버는 언제나 같은 릴리즈라 이 부재는 하위호환 상황이 아니라 배선
     * 파손이고, 안전으로 접으면 서버 항목 한 줄이 사라지는 것만으로 B-4 게이트가 통째로
     * 열린다(그 변이를 어떤 테스트도 잡지 못하던 상태였다). 판정 불능은 D21 경로로 보내
     * 운영자 직접 확인을 요구한다.
     *
     * @param {{targets?: Array}|undefined} exposure 서버 응답의 web_exposure 항목
     * @returns {string} 'pending'(프로브 대기) | 'unknown'(판정 불능 — D21)
     */
    function initialExposureState(exposure) {
        return exposure && Array.isArray(exposure.targets) ? 'pending' : 'unknown';
    }

    /**
     * 링크 생성 불가 안내를 띄워야 하는지 판정한다 (B-4 symlink 실측의 소비 지점).
     *
     * 서버가 `symlink()` 를 실제로 시도해 판정한 값을 화면이 쓰지 않으면 그 실측은 없는
     * 것과 같다. 불가(false)일 때만 알린다 — 가능(true)은 기본 동작이고, 판정 불능(null)은
     * 알릴 내용이 없다. 설치를 막는 항목은 아니다(대체 서빙 경로가 자동 선택된다).
     *
     * @param {{symlink_supported?: boolean|null}|undefined} exposure 서버 응답의 web_exposure 항목
     * @returns {boolean} 안내가 필요하면 true
     */
    function storageLinkNoticeNeeded(exposure) {
        return !!exposure && exposure.symlink_supported === false;
    }

    /**
     * 프로브 결과 집합에서 노출 상태를 판정한다 (B-4).
     *
     * 하나라도 노출이면 'exposed' (D3 — 차단이 우선), 노출은 없지만 판정 실패가 있거나
     * 판정 근거 자체가 없으면 'unknown' (D21), 전 대상 판정 성공 + 노출 0 이면 'safe'.
     *
     * `unmeasuredCount` 는 서버가 **준비에 실패해 대상조차 만들지 못한** 계열의 수다.
     * 그 계열은 프로브 결과에 나타나지 않으므로 결과 배열만 보면 「전 대상 판정 성공」
     * 으로 읽힌다 — 루트가 쓰기 불가한 호스팅에서 storage 계열이 목록에서 빠지고 남은
     * 두 계열이 403 이면 화면이 초록 「보호 확인됨」이 되던 자리다. 재지 못한 축이
     * 하나라도 있으면 안전이라고 말할 수 없다.
     *
     * @param {Array<{exposed: boolean, judged: boolean}>} results 프로브 결과
     * @param {number} [unmeasuredCount] 서버가 준비하지 못해 재지 못한 계열 수
     * @returns {string} 'exposed' | 'unknown' | 'safe'
     */
    /**
     * 이 서버에서 보호 설정이 **어디에 사는지** 알려 주는 블록을 만듭니다.
     *
     * 종전 안내는 「루트 .htaccess 가 동작하는지 확인하라」 한 갈래뿐이었다. 그런데 그 파일을
     * 읽는 서버는 Apache·LiteSpeed 뿐이고, IIS 는 동봉된 `web.config` 를, nginx·Caddy 는
     * **어떤 동봉 파일도 읽지 않는다** — 그 서버의 운영자는 존재하지 않는 조치를 찾게 된다.
     * 그래서 서버가 밝힌 종류에 맞는 파일·경로를 지목한다.
     *
     * 서버 종류는 안내 분기에만 쓴다. 노출 판정 자체는 언제나 실제 응답이 정하므로,
     * 조각을 적용했다는 **주장만으로는 통과하지 못한다** — [다시 검증]이 다시 잰다.
     *
     * @param {object} item 서버가 내려준 web_exposure 항목
     * @returns {string} 안내 HTML (판정 불가하면 범용 안내)
     */
    function renderServerProtectionGuide(item) {
        const kinds = ['apache', 'litespeed', 'iis', 'nginx'];
        const kind = kinds.includes(item && item.server_kind) ? item.server_kind : 'other';

        return `
            <div class="fix-guide-label">${escapeHtml(lang('web_exposure_server_guide_label'))}</div>
            <p class="fix-guide-hint">${escapeHtml(lang('web_exposure_server_guide_' + kind))}</p>`;
    }

    /**
     * 음성 대조 승격을 적용한 대상별 판정 여부 — **판정과 안내가 같은 규칙을 쓰게 하는 자리.**
     *
     * 이 규칙은 종전에 `judgeExposureResults` 안에 인라인되어 있었다. 그런데 판정 불능의
     * **사유**를 화면이 말하게 되면서(D1) 같은 물음을 안내 쪽에서도 던져야 했고, 그때 규칙을
     * 복사하면 두 곳이 갈라진다 — 판정은 「이 대상은 승격됐다」고 보는데 안내는 「프로브가
     * 실패했다」고 말하는 상태가 조용히 생긴다. 한 함수를 양쪽이 부른다.
     *
     * @param {Array<object>} results 프로브 결과
     * @param {{status: number, length: number}|null} absentSignature 음성 대조 형태
     * @returns {boolean[]} 대상별 판정 성립 여부
     */
    function promotedJudged(results, absentSignature) {
        // 음성 대조로 판정을 되살린다.
        //
        // 403/404 가 아니어도, **파일이 없는 주소와 똑같은 응답**을 받았다면 그 대상 역시
        // 파일이 서빙되지 않은 것이다. 이 승격이 없으면 레이아웃 ②의 정상 배치가 상시
        // 판정 불능이 되어 D3 자동 차단이 모든 배치에서 D21 로 강등된다 (14차 G3 라운드).
        //
        // 안전 조건은 호출부에서 이미 섰다 — 노출 확정이 우선하고(마커 일치), 양성 대조가
        // 실패하면 여기 도달하지 못한다. 그래서 전 주소에 200 로그인 화면을 내리는 인증
        // 가로채기 배치는 이 승격을 타지 못한다(그 배치에서는 인스톨러 자산도 가로채여
        // 양성 대조가 먼저 깨진다).
        return results.map((r) => {
            if (r.judged) {
                return true;
            }
            if (!absentSignature || typeof r.status !== 'number' || r.length === null) {
                return false;
            }

            // **빈 본문은 승격 근거가 되지 못한다.**
            //
            // 형태 대조는 「이 서버가 파일 없는 주소에 주는 응답과 같은가」를 묻는다. 그런데
            // 길이 0 은 어느 서버에서나 나올 수 있는 값이라 그 물음의 답이 되지 못한다 —
            // 실제로 `/vendor/autoload.php` 는 서빙이 아니라 **PHP 로 실행되면** 200 + 빈
            // 본문이 되고(마커는 본문에 나타나지 않는다), 그러면 「프로젝트 루트에서 PHP 가
            // 실행된다」는 상태가 「보호 확인됨」으로 읽힌다. 판정 불능으로 남기는 편이 옳다
            // (15차 G3 라운드).
            if (absentSignature.length === 0 || r.length === 0) {
                return false;
            }

            return r.status === absentSignature.status && r.length === absentSignature.length;
        });
    }

    /**
     * 판정 불능 카드의 **선두 문장 키**를 사유로 고른다 (D1).
     *
     * 종전에는 한 문장(`web_exposure_unknown_detail`)이 전 사유에 공용이었고, 그 문장이
     * 불능 사유를 「점검 요청이 차단되었거나 응답이 없습니다」로 단정했다. 실제 사유는
     * 일곱 갈래인데 그중 넷에서 그 문장이 거짓이다 — 특히 프로브가 전부 정상 응답을 받고
     * 준비 실패(`unmeasured`) 하나로 보류된 현장 사례에서 운영자는 그 문장을 근거로
     * **정상 배치를 이상으로** 읽었다.
     *
     * 사유가 둘 이상이면 어느 하나를 단정하지 않고 `_mixed` 로 간다. 사유가 하나도 잡히지
     * 않는 상태(판정은 unknown 인데 원인이 없음)는 그 자체가 이상이므로 가장 약한 단정인
     * `_mixed` 를 쓴다 — 거짓을 말하는 것보다 낫다.
     *
     * @param {{control: boolean, unmeasured: boolean, noTargets: boolean, probe: boolean, shape: boolean}} causes
     * @returns {string} lang 키
     */
    function unknownDetailKey(causes) {
        // 키를 접두사 + 사유로 **조립하지 않는다.** 조립하면 「표에는 있는데 아무도 부르지
        // 않는 키」와 「부르는데 표에 없는 키」를 정적으로 대조할 수 없어, 문구가 사라져도
        // 화면에 키 이름이 그대로 뜰 때까지 아무 검사도 붉어지지 않는다.
        const active = [
            causes.control && 'web_exposure_unknown_detail_control',
            causes.unmeasured && 'web_exposure_unknown_detail_unmeasured',
            causes.noTargets && 'web_exposure_unknown_detail_no_targets',
            causes.shape && 'web_exposure_unknown_detail_shape',
            causes.probe && 'web_exposure_unknown_detail_probe',
        ].filter(Boolean);

        if (active.length !== 1) {
            return 'web_exposure_unknown_detail_mixed';
        }

        return active[0];
    }

    function judgeExposureResults(results, unmeasuredCount, controlResults, absentSignature) {
        // 노출 확정이 언제나 우선한다 — 기준 주소가 틀렸든 위임이 죽었든, `.env` 가 원문
        // 그대로 200 이면 그것은 노출이다 (D3).
        if (results.some((r) => r.exposed)) {
            return 'exposed';
        }

        // 양성 대조: **열려야 하는** 주소가 열리지 않으면 이번 측정의 기준틀을 신뢰할 수 없다.
        // 403/404 를 보호로 읽는 판정은 「막혔다」와 「그 주소에 아무것도 없다」를 구분하지
        // 못하므로, 위임이 죽은 서버(mod_rewrite 부재)와 기준 주소를 잘못 잡은 배치가 전부
        // 초록으로 수렴한다. 그 둘을 여기서 함께 잡는다.
        const controls = Array.isArray(controlResults) ? controlResults : [];
        if (controls.some((c) => !c.reachable)) {
            return 'unknown';
        }

        const judged = promotedJudged(results, absentSignature);

        if (results.length === 0 || judged.some((ok) => !ok)) {
            return 'unknown';
        }
        // 인자를 빠뜨린 호출부가 조용히 'safe' 로 떨어지지 않도록 기본값을 0 으로 둔다.
        if ((unmeasuredCount || 0) > 0) {
            return 'unknown';
        }

        return 'safe';
    }

    /**
     * 음성 대조 1건을 던진다 — 이 주소에는 **파일이 없다**.
     *
     * 레이아웃 ②는 루트 `.htaccess` 가 모든 주소를 앱으로 위임하므로, 파일이 서빙되지
     * 않는 주소도 200 앱 화면으로 돌아온다(P0 실측: `/vendor/autoload.php`·`/storage/...`).
     * 403/404 만 보호로 읽으면 그 정상 배치가 상시 판정 불능이 되어 D3 자동 차단이 모든
     * 배치에서 D21 로 강등된다. 그래서 「이 서버가 파일 없는 주소에 무엇을 돌려주는가」를
     * 함께 재고, 그 형태와 같은 응답을 받은 대상은 파일이 서빙되지 않은 것으로 읽는다.
     *
     * 형태는 `status` + 본문 길이로 잰다. 본문이 매 요청 달라지는 부분(CSRF 토큰)이 있어
     * 문자열 동일성으로는 잴 수 없지만 길이는 안정적이다(실측: 12,557 바이트 고정).
     *
     * @param {string} root 프로젝트 루트 URL (origin 포함)
     * @param {{path: string, label: string}} control 음성 대조 대상
     * @returns {Promise<{status: number, length: number}|null>} 잴 수 없으면 null
     */
    async function probeAbsentControl(root, control) {
        if (!control || !control.path) {
            return null;
        }

        try {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 10000);
            const res = await fetch(`${root}${control.path}?g7ep=${Date.now()}`, {
                cache: 'no-store',
                credentials: 'omit',
                redirect: 'manual',
                signal: controller.signal,
            });
            clearTimeout(timer);

            if (res.type === 'opaqueredirect') {
                return null;
            }

            // 403/404 는 이미 대상 판정이 보호로 읽는 응답이다. 그 형태를 음성 대조로
            // 쓰면 판정이 중복될 뿐이므로 형태 대조는 200 계열에서만 의미가 있다 — 그 밖의
            // 상태(5xx 등)는 서버 상태지 「파일 없는 주소의 형태」가 아니다. 종전 코드는 이
            // 주석과 달리 전 status 를 서명으로 받았다 (15차 G3 W1-7).
            if (!res.ok) {
                return null;
            }

            const body = await res.text();

            return { status: res.status, length: body.length };
        } catch (e) {
            return null;
        }
    }

    /**
     * 양성 대조 1건을 던진다 — 이 주소는 **200 + 마커** 여야 한다.
     *
     * @param {string} root 프로젝트 루트 URL (origin 포함)
     * @param {{path: string, marker: string, label: string}} control 대조 대상
     * @returns {Promise<{path: string, label: string, reachable: boolean}>}
     */
    async function probeExposureControl(root, control) {
        try {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 10000);
            const res = await fetch(`${root}${control.path}?g7ec=${Date.now()}`, {
                cache: 'no-store',
                credentials: 'omit',
                redirect: 'manual',
                signal: controller.signal,
            });
            clearTimeout(timer);

            if (res.type === 'opaqueredirect' || !res.ok) {
                return { path: control.path, label: control.label, reachable: false };
            }

            const body = await res.text();

            return {
                path: control.path,
                label: control.label,
                reachable: !!(control.marker && body.includes(control.marker)),
            };
        } catch (e) {
            return { path: control.path, label: control.label, reachable: false };
        }
    }

    /**
     * 웹 노출 점검 프로브 1건을 던진다 (B-4).
     *
     * 판정은 상태코드가 아니라 **본문의 마커**다 — 서버가 내려준 마커(비밀 아님)가 본문에
     * 있으면 그 파일이 원문 그대로 서빙된 것(노출)이다. 200 + 에러 HTML / catch-all 페이지는
     * 마커가 없어 노출로 오판하지 않는다 (asset-probe 와 같은 규약).
     *
     * @param {string} root 앱 루트 URL (origin 포함)
     * @param {{path: string, marker: string}} target 프로브 대상
     * @returns {Promise<{path: string, exposed: boolean, judged: boolean}>}
     */
    async function probeExposureTarget(root, target) {
        try {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 10000);
            const res = await fetch(`${root}${target.path}?g7ep=${Date.now()}`, {
                cache: 'no-store',
                credentials: 'omit',
                // 리다이렉트를 따라가지 않는다. 따라가면 `.env` 요청이 로그인 페이지 200 으로
                // 끝나 마커 불일치 → 「보호 확인됨」이 된다 — 3xx 는 파일이 열리는지 재지 못한
                // 것이지 보호를 증명한 것이 아니다. manual 이면 `type === 'opaqueredirect'`
                // (status 0)로 도착하므로 아래 !res.ok 분기가 판정 불능으로 보낸다.
                redirect: 'manual',
                signal: controller.signal,
            });
            clearTimeout(timer);

            if (res.type === 'opaqueredirect') {
                return { path: target.path, exposed: false, judged: false };
            }

            if (!res.ok) {
                // 보호를 증명하는 것은 **접근 거부(403)와 부재(404)** 뿐이다. 그 밖의 비-200 은
                // 「파일이 열리는지 재지 못했다」이지 「보호되어 있다」가 아니다 — 사이트 전체
                // Basic 인증(401)이나 쿼리스트링을 막는 WAF(400 등)에서 전 대상을 차단 확인으로
                // 접으면, 노출된 배치에 초록 「보호 확인됨」이 뜨고 운영자가 안전하다고 믿는다.
                // 판정 불능은 D21 경로(운영자 직접 확인)로 보낸다.
                const denied = res.status === 403 || res.status === 404;

                return {
                    path: target.path,
                    exposed: false,
                    judged: denied,
                    status: res.status,
                    length: null,
                };
            }

            const body = await res.text();
            const matched = !!(target.marker && body.includes(target.marker));

            // 200 + 마커 일치 → 원문이 그대로 서빙됐다(노출). 200 + 마커 **부재**는 보호의
            // 증명이 아니다 — 인증 프록시(oauth2-proxy·Azure AD App Proxy)·캡티브 포털·CDN
            // 챌린지는 로그인 화면을 200 으로 렌더한다. 3xx 는 `redirect: 'manual'` 로 막았는데
            // **200 인터스티셜**은 그 규칙을 그대로 우회해 「보호 확인됨」이 됐다.
            // 보호를 증명하는 것은 접근 거부(403)와 부재(404)뿐이라는 규칙을 여기에도 적용한다.
            return {
                path: target.path,
                exposed: matched,
                judged: matched,
                status: res.status,
                length: body.length,
            };
        } catch (e) {
            // 네트워크 예외 — 판정 불능 (D21 경로)
            return { path: target.path, exposed: false, judged: false };
        }
    }

    /**
     * Step 2 웹 노출 점검 카드를 프로브 결과로 갱신하고 게이트를 재계산한다 (B-4 · D3 · D21).
     *
     * - 노출 확정: 카드 빨강 + 노출된 실제 주소 목록 + 조치 안내, [다음 단계] 비활성 (D3)
     * - 판정 불능: 카드 노랑 + 수동 확인 절차(직접 열어볼 주소 목록) + '확인했음' 체크 후 진행 (D21)
     * - 안전: 카드 초록
     *
     * 카드 본문은 **한 번에 조립해 한 번만 대입한다.** 대입 뒤에 `innerHTML +=` 로 무언가를
     * 덧붙이면 그 시점에 subtree 가 통째로 다시 파싱되어, 먼저 붙여 둔 확인 체크박스의
     * 이벤트 리스너가 사라진다 — 링크 생성 불가(안내가 붙는 조건)와 판정 불능이 겹치는
     * 환경은 이 안이 대상으로 하는 바로 그 호스팅이고, 거기서 [다음 단계] 를 여는 유일한
     * 조작이 무반응이 된다(오류도 로그도 남지 않는다).
     *
     * @param {object|null} exposure 서버 응답의 web_exposure 항목 (targets 배열 포함)
     */
    async function refreshWebExposureCard(exposure) {
        const slot = document.getElementById('web-exposure-card');
        if (!slot) return;

        // 프로브 기준점은 앱 루트(public)가 아니라 **프로젝트 루트**다 — getProjectRootUrl 주석 참조.
        // 서버 항목이 없으면 대상 0 → judgeExposureResults 가 'unknown' 으로 수렴한다
        // (「대상 0 을 안전으로 읽지 않는다」는 기존 계약과 같은 경로다).
        const targets = exposure && Array.isArray(exposure.targets) ? exposure.targets : [];
        // 서버가 준비하지 못해 **대상조차 만들지 못한** 계열. 프로브 결과에 나타나지 않으므로
        // 판정에 따로 실어야 한다 — 안 그러면 재지 않은 축이 「전 대상 판정 성공」에 묻힌다.
        const unmeasured = exposure && Array.isArray(exposure.unmeasured) ? exposure.unmeasured : [];

        // 양성 대조 — 서버가 「반드시 열려야 하는 주소」로 지목한 것들. 기준 URL 후보를
        // 고르는 데 먼저 쓰고(추측 → 실측), 그 다음 판정 근거로도 쓴다.
        const controls = exposure && Array.isArray(exposure.controls) ? exposure.controls : [];
        const { root, controlResults } = await resolveProjectRoot(controls);

        // 음성 대조 — 서버가 지목한 「파일이 없는 주소」. 기준 URL 이 확정된 뒤에 잰다.
        const absentControl = exposure && exposure.absent_control ? exposure.absent_control : null;

        const [results, absentSignature] = await Promise.all([
            Promise.all(targets.map((t) => probeExposureTarget(root, t))),
            probeAbsentControl(root, absentControl),
        ]);
        const unreachableControls = controlResults.filter((c) => !c.reachable);
        const judgement = judgeExposureResults(
            results,
            unmeasured.length,
            controlResults,
            absentSignature
        );
        const exposedPaths = results.filter((r) => r.exposed).map((r) => r.path);
        setStep2ExposureState(judgement);
        recordExposureVerdict(judgement);

        // 링크 생성 가능 여부 실측 결과(B-4)를 운영자에게 알린다. 불가한 환경에서는 공개
        // 파일이 정적 경로 대신 앱 서빙 경로로 나가므로 그 사실이 화면에 드러나야 한다 —
        // 값을 응답에만 싣고 아무 데도 쓰지 않으면 그 실측은 없는 것과 같다. 설치를 막는
        // 항목이 아니므로(대체 경로가 자동 선택된다) 정보 문구로만 덧붙인다.
        const linkNotice = storageLinkNoticeNeeded(exposure)
            ? `
                <div class="alert alert-info" role="note">
                    <div>${escapeHtml(lang('storage_link_unavailable_detail'))}</div>
                </div>`
            : '';

        if (judgement === 'exposed') {
            // W1 확정: 노출이 확인된 실제 주소를 표시한다 — 이미 외부에서 열리는 상태라
            // 조치 가능성이 노출 위험보다 크다.
            const listItems = exposedPaths
                .map((p) => `<li><code>${escapeHtml(root + p)}</code></li>`)
                .join('');
            slot.innerHTML = renderSingleItemCard(
                lang('web_exposure'),
                'status-fail',
                lang('web_exposure_exposed'),
                '',
                true
            ) + `
                <div class="alert alert-error">
                    <div>${escapeHtml(lang('web_exposure_exposed_detail'))}</div>
                    <div class="fix-guide-label">${escapeHtml(lang('web_exposure_exposed_list_label'))}</div>
                    <ul class="alert-list exposure-address-list">${listItems}</ul>
                    <p class="fix-guide-hint">${escapeHtml(lang('web_exposure_exposed_remedy'))}</p>
                    ${renderServerProtectionGuide(exposure)}
                </div>` + linkNotice;
        } else if (judgement === 'unknown') {
            // D21: 판정 불능은 노출 확정과 다른 상태다 — 불능 사유 + 직접 열어 확인할 주소
            // 목록을 펼치고, '파일 내용이 보이는 주소가 없음을 확인했음' 체크 전까지 [다음 단계] 비활성.
            // 대상이 하나도 없으면(서버 항목 부재 등) 목록 블록은 접는다 — 빈 목록을 라벨과
            // 함께 내보내면 확인할 것이 있는 것처럼 읽힌다. 확인 경로 자체는 그대로 연다.
            //
            // 그때 체크박스 문구를 고르는 기준은 **주소 목록의 유무 하나**다(`manualItems`).
            // 재지 못한 계열 목록(`unmeasuredItems`)까지 조건에 넣으면, 대상 0 + unmeasured 만
            // 있는 상태에서 「위 주소를 모두 확인했고…」가 뜬다 — 화면에 주소가 한 줄도 없는데
            // 그것을 다 확인했다고 서명시키는 꼴이고, 정확히 그 경우를 위해 만든
            // `web_exposure_manual_confirm_no_list`(무엇을 열어 봐야 하는지 문구가 직접 말한다)
            // 에는 영영 도달하지 않는다.
            const manualItems = targets
                .map((t) => `<li><a href="${escapeAttribute(root + t.path)}" target="_blank" rel="noopener noreferrer">${escapeHtml(root + t.path)}</a></li>`)
                .join('');
            const manualList = manualItems
                ? `
                    <div class="fix-guide-label">${escapeHtml(lang('web_exposure_manual_list_label'))}</div>
                    <ul class="alert-list exposure-address-list">${manualItems}</ul>`
                : '';
            // 재지 못한 계열은 「확인할 주소」 목록에 실을 수 없다(주소가 만들어지지 않았다).
            // 그 사실을 이름과 함께 따로 알린다 — 무엇을 재지 못했는지 모르면 운영자가
            // '파일 내용이 보이는 주소가 없음을 확인했음' 에 근거 없이 체크하게 된다.
            // 사유까지 싣는다. 이름만 주면 「못 쟀다」와 「원래 없다」가 구분되지 않는데,
            // 둘은 운영자가 할 일이 다르다 — 예컨대 `.env.example` 의 `missing` 은 파일이
            // 없다는 뜻이 아니라 **그 파일을 `.env` 로 이름을 바꿨다**는 뜻이라(흔한 절차)
            // 그 계열이 실제로 열려 있을 수 있다. 사유 키가 사전에 없으면 이름만 보인다.
            const unmeasuredItems = unmeasured
                .map((u) => {
                    // 사유 문구는 **계열마다 할 일이 다르다.** 종전에는 `missing` 하나가
                    // 전 계열에 공용이라, 인스톨러 자신의 자산이 없어 양성 대조를 만들지
                    // 못한 경우에도 「`.env.example` 을 `.env` 로 바꿨다면」이라는 엉뚱한
                    // 조치를 안내했다 — 운영자가 있지도 않은 파일을 찾게 된다.
                    // 계열 전용 키를 먼저 찾고, 없으면 공용 문구로 떨어진다 (14차 G3 라운드).
                    const slug = String(u.label || '')
                        .replace(/[^a-z0-9]+/gi, '_')
                        .replace(/^_+|_+$/g, '')
                        .toLowerCase();
                    const resolve = (key) => {
                        const text = lang(key);

                        return text && text !== key ? text : '';
                    };
                    const reason = u.reason
                        ? resolve('web_exposure_unmeasured_reason_' + u.reason + '_' + slug) ||
                          resolve('web_exposure_unmeasured_reason_' + u.reason)
                        : '';

                    return `<li>${escapeHtml(u.label)}${reason ? ' — ' + escapeHtml(reason) : ''}</li>`;
                })
                .join('');
            const unmeasuredList = unmeasuredItems
                ? `
                    <div class="fix-guide-label">${escapeHtml(lang('web_exposure_unmeasured_label'))}</div>
                    <ul class="alert-list">${unmeasuredItems}</ul>`
                : '';
            // 양성 대조가 열리지 않았다면 그것이 판정 불능의 **원인**이다. 그 사실을 먼저
            // 알리지 않으면 운영자는 「직접 열어 보니 다 안 열린다」를 근거로 확인 체크를
            // 누르는데, 실제로는 보호가 아니라 **위임이 죽어서** 아무것도 안 열리는 것이다
            // (그 상태로 설치를 마치면 사이트는 `/` 밖 전 라우트가 404 다).
            const controlItems = unreachableControls
                .map((c) => `<li><code>${escapeHtml(root + c.path)}</code></li>`)
                .join('');
            const controlBlock = controlItems
                ? `
                    <div class="fix-guide-label">${escapeHtml(lang('web_exposure_control_failed_label'))}</div>
                    <ul class="alert-list exposure-address-list">${controlItems}</ul>
                    <p class="fix-guide-hint">${escapeHtml(lang('web_exposure_control_failed_hint'))}</p>`
                : '';
            // 음성 대조 주소를 **비교 기준**으로 함께 띄운다 (D1). 서버는 이미 「이 서버에
            // 존재하지 않는 주소」를 내려주는데 화면은 그것을 프로브에만 썼다 — 운영자가
            // 「정상이 어떻게 보이는가」를 눈으로 대조할 길이 없으면, 문서 루트를 바꿀 수 없는
            // 배치의 정상 응답(설치 안내 200 화면)을 노출로 읽는다(현장 제보).
            //
            // 서버 응답에 키가 없는 구판이면 블록을 통째로 접는다 — 빈 라벨은 「확인할 것이
            // 있는데 주소가 비었다」로 읽힌다.
            const absentControlBlock = absentControl && absentControl.path
                ? `
                    <div class="fix-guide-label">${escapeHtml(lang('web_exposure_absent_control_label'))}</div>
                    <ul class="alert-list exposure-address-list"><li><a href="${escapeAttribute(root + absentControl.path)}" target="_blank" rel="noopener noreferrer">${escapeHtml(root + absentControl.path)}</a></li></ul>
                    <p class="fix-guide-hint">${escapeHtml(lang('web_exposure_absent_control_hint'))}</p>`
                : '';
            // 불능 **사유**를 갈라 선두 문장을 고른다 (D1). 승격 규칙은 판정이 쓰는 것과 같은
            // 함수여야 한다 — 사본을 두면 판정은 「승격됐다」고 보는데 안내는 「프로브가
            // 실패했다」고 말하는 상태가 조용히 생긴다.
            const promoted = promotedJudged(results, absentSignature);
            const unpromoted = results.filter((_r, i) => !promoted[i]);
            // U3d(형태 대조 불가)와 U3a~c(요청이 닿지 못함·3xx·비-200)를 가르는 근거값은
            // 이미 결과에 실려 있다 — 200 이고 본문 길이를 잰 것은 **응답은 받았고 형태
            // 대조만 실패한** 것이고, 그 밖은 응답 자체를 못 받았거나 판정할 수 없는 응답이다.
            const shapeOnly = unpromoted.filter((r) => r.status === 200 && typeof r.length === 'number');
            const detailKey = unknownDetailKey({
                control: unreachableControls.length > 0,
                unmeasured: unmeasured.length > 0,
                noTargets: targets.length === 0,
                shape: shapeOnly.length > 0,
                probe: unpromoted.length > shapeOnly.length,
            });
            slot.innerHTML = renderSingleItemCard(
                lang('web_exposure'),
                'status-warning',
                lang('web_exposure_unknown'),
                '',
                true
            ) + `
                <div class="alert alert-warning">
                    <div>${escapeHtml(lang(detailKey))}</div>
                    <div class="exposure-criteria">${escapeHtml(lang('web_exposure_manual_criteria'))}</div>${controlBlock}${unmeasuredList}${manualList}${absentControlBlock}
                    <p class="fix-guide-hint">${escapeHtml(lang('web_exposure_manual_hint'))}</p>
                    <label class="form-checkbox">
                        <input type="checkbox" id="web-exposure-confirm" class="checkbox-input">
                        <span>${escapeHtml(lang(manualItems
                            ? 'web_exposure_manual_confirm'
                            : 'web_exposure_manual_confirm_no_list'))}</span>
                    </label>
                </div>` + linkNotice;

            // 리스너는 **최종 대입 이후에** 붙인다 (위 주석 참조).
            const confirmBox = slot.querySelector('#web-exposure-confirm');
            if (confirmBox) {
                confirmBox.addEventListener('change', function () {
                    setStep2ExposureState(this.checked ? 'confirmed' : 'unknown');
                    recordExposureVerdict(this.checked ? 'confirmed' : 'unknown');
                    updateStep2Gate();
                });
            }
        } else {
            slot.innerHTML = renderSingleItemCard(
                lang('web_exposure'),
                'status-pass',
                lang('web_exposure_safe'),
                '',
                true
            ) + linkNotice;
        }

        // 판정이 끝난 지금에야 busy 를 푼다 — 이 카드는 자기 리전을 선언하지 않고
        // 바깥 영역(`#requirements-result`)의 리전에 얹힌다(중첩 금지 · 아래 참조).
        //
        // 바깥 영역의 busy 해제는 요구사항 목록을 그린 직후에
        // 일어나는데, 이 카드는 그 시점에 아직 「확인 중…」이고 판정은 비동기로 뒤에 온다.
        // 그 하나만으로 [다음 단계] 가 잠겨 있으므로(필수 게이트), 화면 판독기 사용자는
        // 「확인 중…」을 최종 상태로 듣고 버튼이 왜 비활성인지 알 방법이 없었다. 바깥 영역의
        // 해제를 미루면 요구사항 결과 자체의 안내가 프로브(최대 10초)만큼 늦어지므로,
        // 지연 없이 두 상태를 각각 알리도록 이 자리에 별도 busy 를 둔다.
        slot.setAttribute('aria-busy', 'false');

        updateStep2Gate();
    }

    /**
     * 노출 점검 판정을 Step 2 폼의 숨은 필드에 적어 둔다 (D3 · D21 감사 흔적).
     *
     * 판정 주체가 브라우저이고 게이트도 화면이 잠그므로, 그 결과는 화면을 떠나는 순간
     * 사라진다 — 「보호 확인됨으로 통과했는지 / 판정 불능에서 운영자가 직접 확인하고
     * 넘어갔는지」를 나중에 되짚을 근거가 어디에도 없었다(잔여물 정리 실패조차 설치 로그에
     * 남는데 정작 판정은 남지 않았다). 이 값은 [다음 단계] POST 에 실려 서버가
     * `storage/logs/installation.log` 에 한 줄로 남긴다.
     *
     * 클라이언트 로그 패널(`#install-log`)은 Step 5 에만 있어 이 화면에서 `addLogMessage()`
     * 는 아무 데도 쓰지 않는다 — 그래서 기록 통로를 서버 로그로 잡았다.
     *
     * @param {string} verdict 판정값 ('safe'|'unknown'|'exposed'|'confirmed')
     * @returns {void}
     */
    function recordExposureVerdict(verdict) {
        const field = document.getElementById('web-exposure-verdict');
        if (field) {
            field.value = verdict;
        }
    }

    /* endregion:web-exposure */

    /* region:requirements-failure — 이 구간은 회귀 테스트가 소스에서 잘라 **실제로 실행**한다
       (사본 금지). 서버가 갈라 보낸 실패 사유를 화면까지 나르는 유일한 지점이다. */

    /**
     * 요구사항 검사 실패 응답을 화면이 쓰는 두 조각(제목 · 진단 상세)으로 나눈다.
     *
     * 서버는 사유를 `message_key`(번역 키) · `message`(서버 로케일 문구) · `details`
     * (예외 원문) 셋으로 갈라 보낸다. 종전에는 `response.ok` 판정이 본문 읽기보다 앞에
     * 있어 그 셋이 통째로 버려지고 화면에는 「요구사항 검증 실패」와 `HTTP 500: ` 만
     * 남았다 — 운영자는 레이트리밋인지 예외인지 알 수 없었다. 형제 지점
     * (`scan-extensions` 소비부)은 정확히 이 사유로 「본문을 **먼저** 읽는다」로 고쳐져
     * 있었고, 이 지점만 남아 있었다 (7차 G3 라운드 D-1).
     *
     * `statusText` 는 쓰지 않는다 — HTTP/2 응답에서 브라우저가 언제나 빈 문자열을 주므로
     * 진단 상세가 `HTTP 500: ` 로 끝난다. 형제(`installation-monitor.js`)도 상태 코드만
     * 싣는다 (7차 G3 라운드 D-2).
     *
     * @param {number} status - HTTP 상태 코드
     * @param {object|null} payload - 파싱된 응답 본문 (파싱 실패 시 null)
     * @returns {{heading: string, detail: string}} 제목과 진단 상세
     */
    function describeRequirementsFailure(status, payload) {
        const body = payload && typeof payload === 'object' ? payload : null;
        const heading = translateWithKey(body && body.message_key, '')
            || (body && typeof body.message === 'string' ? body.message : '')
            || lang('requirements_check_failed');

        const details = body && body.details ? String(body.details) : '';
        const detail = details ? `HTTP ${status} — ${details}` : `HTTP ${status}`;

        return { heading, detail };
    }

    /* endregion:requirements-failure */

    /**
     * 서버 요구사항 검증 실행
     */
    async function checkRequirements() {
        try {
            // 절대 URL 생성 (origin + base path)
            const apiUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/check-configuration.php?action=requirements`;
            const response = await fetch(apiUrl);

            // 본문을 **먼저** 읽는다 — 사유는 본문에만 있다 (위 describeRequirementsFailure).
            //
            // 빈 본문(HTTP 200 + Content-Length 0)과 파싱 실패는 **구분한다**. 그대로
            // `response.json()` 에 넘기면 "Unexpected end of JSON input" 이라는, 원인도
            // 조치도 알 수 없는 문구가 화면에 뜬다 (gnuboard/g7#62 — 한글 계정명 환경에서
            // 실제로 발생했다). 두 경우의 조치가 다르므로 안내도 갈라 준다.
            let data = null;
            let bodyFailureKey = null;
            try {
                const text = await response.text();

                if (text.trim() === '') {
                    bodyFailureKey = 'error_empty_server_response';
                } else {
                    data = JSON.parse(text);
                }
            } catch (_) {
                bodyFailureKey = 'error_invalid_server_response';
                data = null;
            }

            // 상태 코드가 사유를 담고 있으면 그쪽이 우선이다 — 본문이 비어도
            // describeRequirementsFailure 가 상태만으로 안내를 만든다.
            if (!response.ok) {
                const failure = describeRequirementsFailure(response.status, data);
                const error = new Error(failure.detail);
                error.installerHeading = failure.heading;
                throw error;
            }

            // 200 인데 본문을 읽지 못한 경우 — 서버 설정·계정명·경로 문자가 원인이다.
            if (bodyFailureKey !== null || !data) {
                throw new Error(lang(bodyFailureKey ?? 'error_invalid_server_response'));
            }

            const resultHtml = renderRequirements(data);
            const resultRegion = document.getElementById('requirements-result');
            resultRegion.innerHTML = resultHtml;
            // 검사가 끝났음을 라이브 리전에 알린다 — 이 해제가 없으면 결과가 통째로 갈아
            // 끼워졌는데도 화면 밖에서는 여전히 「검사 중」이다 (12차 #B34).
            resultRegion.setAttribute('aria-busy', 'false');
            document.getElementById('navigation-buttons').classList.remove('hidden');

            // 자산 URL 방식은 브라우저만 판정할 수 있어 카드 삽입 후 비동기로 채운다.
            // await 하지 않는다 — 프로브가 늦거나 실패해도 요구사항 화면을 막지 않는다.
            refreshAssetUrlModeCard();

            // 버튼 표시 제어 (§12 W1 규약은 updateStep2Gate 가 소유한다).
            // 웹 노출 점검(B-4)은 필수 게이트이므로 판정이 끝날 때까지 fail-closed 로
            // 잠근다 — 프로브는 same-origin fetch 라 곧바로 끝난다. 서버 항목 부재는
            // 「안전」이 아니라 판정 불능이다 (initialExposureState 주석).
            setStep2GateState(!! data.all_required_passed, initialExposureState(data.web_exposure));
            updateStep2Gate();

            // 웹 노출 점검도 브라우저 판정이다 (D3 · D21) — 완료 시 게이트를 다시 계산한다.
            refreshWebExposureCard(data.web_exposure);
        } catch (error) {
            // 브라우저 원문(`Failed to fetch` 등)은 안내 문구가 아니다 — 문구는 번역 키로 두고
            // 원문은 접힌 진단 상세에만 싣는다 (§12.0 W3, 실패 화면과 같은 규약).
            const rawDetail = error && error.message ? String(error.message) : '';
            // 서버가 사유를 갈라 보낸 경우에는 그 제목을 쓴다 — 일반 문구로 덮으면
            // 레이트리밋과 예외가 화면에서 구분되지 않는다.
            const heading = (error && error.installerHeading) || lang('requirements_check_failed');
            const failedRegion = document.getElementById('requirements-result');
            failedRegion.setAttribute('aria-busy', 'false');
            failedRegion.innerHTML = `
                <div class="alert alert-error">
                    ${escapeHtml(heading)}
                    ${rawDetail ? `<div class="failure-error-detail"><strong>${escapeHtml(lang('view_error_details'))}</strong><pre class="error-stack">${escapeHtml(rawDetail)}</pre></div>` : ''}
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
        //
        // 서버는 「확인 불가」를 3상태로 내보낸다 — `disk_free_space` 가 차단된 호스팅
        // (cPanel/CloudLinux 표준 차단 목록)에서 `current_mb` 는 null 이고 `unknown` 이 참이다.
        // 그 필드를 읽지 않으면 null 이 `(null/1024).toFixed(2)` 를 지나 "0.00" 이 되고,
        // `passed` 가 참이라 **초록 통과 카드**가 된다 — 이 안이 지원하려는 바로 그 환경에서
        // 「디스크 여유 공간 ✅ 0.00 GB」라는 자기모순이 뜬다. 3상태는 OPcache·실행 예산 카드와
        // 같은 규칙으로 그린다(확인 불가 = 경고, 차단 아님).
        const disk = data.disk_space;
        const minSpaceGB = (disk.min_mb / 1024).toFixed(2);
        let diskStatusClass;
        let diskValueText;
        if (disk.unknown === true || typeof disk.current_mb !== 'number') {
            diskStatusClass = disk.passed ? 'status-warning' : 'status-fail';
            diskValueText = disk.message || lang('error_disk_space_unknown');
            if (!disk.passed) {
                // 사유도 「0.00 GB (최소 N GB 필요)」가 아니라 확인 불가 그대로여야 한다 —
                // 재지 못한 값을 0 으로 단정하면 운영자가 없는 문제를 쫓는다.
                failedRequirements.push(diskValueText);
            }
        } else {
            const diskSpaceGB = (disk.current_mb / 1024).toFixed(2);
            diskStatusClass = disk.passed ? 'status-pass' : 'status-fail';
            diskValueText = `${diskSpaceGB} GB`;
            if (!disk.passed) {
                failedRequirements.push(`${lang('error_disk_space')}: ${diskSpaceGB} GB (${lang('minimum')} ${minSpaceGB} GB ${lang('required_text')})`);
            } else if (disk.low_headroom === true) {
                // 차단 기준(500MB)은 넘겼으나 실사용 권장(700MB) 미만 — 경고색만 값 슬롯에,
                // 문구는 카드 아래 별도 안내로 (21차 #21-7 · 22차 N-5). 값 슬롯은 짧은 라벨
                // 계약(OPcache 카드와 같은 규칙)이라 문단을 넣으면 배지를 밀어낸다.
                diskStatusClass = 'status-warning';
            }
        }
        html += renderSingleItemCard(
            lang('disk_space'),
            diskStatusClass,
            diskValueText,
            `${minSpaceGB} GB`,
            true
        );
        if (disk.headroom_message) {
            html += `
                    <div class="alert alert-warning">
                        <span>${escapeHtml(disk.headroom_message)}</span>
                    </div>`;
        }

        // 3. PHP 모듈 카드
        const allRequiredPassed = data.php_extensions.required.every(ext => data.php_extensions.installed[ext]?.installed);
        if (!allRequiredPassed) {
            const missing = data.php_extensions.required.filter(ext => !data.php_extensions.installed[ext]?.installed);
            failedRequirements.push(`${lang('error_missing_extensions')}: ${missing.join(', ')}`);
        }
        let requiredExtHtml = '<div class="requirement-extension-grid">';
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
        // Windows ReadOnly 속성이 원인인 경로가 하나라도 있으면 attrib -R 안내를 함께 노출한다.
        // (ReadOnly 는 ACL 이 아니라 파일 속성이라 icacls 로는 해소되지 않는다)
        let hasWindowsReadOnly = false;
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

                        if (info.error_type === 'windows_readonly') {
                            hasWindowsReadOnly = true;
                        }
                    }

                    // 하위 디렉토리 중 ReadOnly 속성이 원인인 항목이 있는지 확인
                    if (Array.isArray(info.failed_subdirectories)) {
                        info.failed_subdirectories.forEach(subdir => {
                            if (subdir && typeof subdir === 'object' && subdir.windows_readonly) {
                                hasWindowsReadOnly = true;
                            }
                        });
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
            lang('https'),
            httpsStatusClass,
            data.https.enabled ? lang('enabled') : lang('not_enabled'),
            '',
            false
        );

        // 7. 실행 방식 카드 (구 필수 함수 카드 — A-1 모드 분기)
        //    status: 'pass'(subprocess, 현행 초록) / 'info'(inprocess + 번들 보유, 파랑 정보)
        //            / 'fail'(inprocess + 번들 부재, 빨강 차단)
        if (data.disabled_functions) {
            const df = data.disabled_functions;
            const dfStatus = df.status || (df.passed ? 'pass' : 'fail');

            if (dfStatus === 'pass') {
                // 현행과 동일한 통과 카드 (S1 회귀 기준면)
                html += renderSingleItemCard(
                    lang('required_functions'),
                    'status-pass',
                    lang('required_functions_available'),
                    'exec, proc_open, shell_exec',
                    true
                );
            } else if (dfStatus === 'info') {
                // 정보(파랑) — 실패가 아니라 "어떻게 설치되는지" 안내 (W1)
                html += renderExecutionModeInfoCard(df);
            } else {
                // 차단(빨강)도 정보(파랑)와 같은 근거 목록을 보여준다 (14차 #12) — 사유가
                // 하단 에러 박스에만 있으면 항목 카드와 이유가 떨어져 읽힌다.
                failedRequirements.push(df.message || lang('error_no_cli_and_no_bundle'));
                html += renderExecutionModeFailCard(df);
            }
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

        // 9. OPcache 카드 (성능 권장, 필수 아님)
        // failedRequirements.push() 를 하지 않는다 — 비활성이어도 설치는 진행된다.
        if (data.opcache) {
            let opcacheStatusClass, opcacheStatusText;
            if (data.opcache.enabled === null) {
                // ini_get 이 차단된 환경 — 확인 불가 (경고도 차단도 아님)
                opcacheStatusClass = 'status-warning';
                opcacheStatusText = lang('opcache_unknown');
            } else if (data.opcache.enabled) {
                opcacheStatusClass = 'status-pass';
                opcacheStatusText = lang('enabled');
            } else {
                // 단순히 '비활성화됨' 만 보이면 경고의 실질(성능 저하)이 전달되지 않는다.
                opcacheStatusClass = 'status-warning';
                opcacheStatusText = lang('opcache_disabled_short');
            }
            html += renderSingleItemCard(
                lang('opcache'),
                opcacheStatusClass,
                opcacheStatusText,
                '',
                false
            );
        }

        // 9-1. Composer 의존성 구성 카드 (권장 사항, 필수 아님)
        // failedRequirements.push() 를 하지 않는다 — 개발용 패키지가 섞여 있어도 설치는 진행된다.
        // vendor 자체가 없으면 마법사가 운영용 구성으로 자동 설치하므로 카드를 그리지 않는다.
        if (data.vendor_dev_packages && data.vendor_dev_packages.vendor_exists) {
            const vendorInfo = data.vendor_dev_packages;
            let vendorStatusClass, vendorStatusText, vendorHint = '';
            if (vendorInfo.dev === true) {
                // 단순히 '개발용 포함' 만 보이면 이후 코어 업데이트가 깨진다는 실질이 전달되지 않는다.
                vendorStatusClass = 'status-warning';
                // 치환은 lang(key, replace) 한 지점을 지난다 — .replace(문자열) 은 첫 occurrence 만 바꾼다.
                vendorStatusText = lang('vendor_dev_packages_detected_short', { count: (vendorInfo.packages || []).length });
                vendorHint = lang('vendor_dev_packages_detected_warning', { count: (vendorInfo.packages || []).length });
            } else if (vendorInfo.dev === false) {
                vendorStatusClass = 'status-pass';
                vendorStatusText = lang('vendor_dev_packages_none_short');
            } else {
                // installed.json 부재·형식 불명 — 확인 불가 (경고도 차단도 아님)
                vendorStatusClass = 'status-pass';
                vendorStatusText = lang('vendor_dev_packages_unknown');
            }
            html += renderSingleItemCard(
                lang('vendor_dev_packages'),
                vendorStatusClass,
                vendorStatusText,
                '',
                false
            );
            if (vendorHint) {
                html += `<p class="fix-guide-hint" style="margin-top: 0.75rem;">${vendorHint}</p>`;
            }
        }

        // 10. 실행 예산(메모리·시간) 카드 (정보 항목, 필수 아님)
        // 값이 화면에 없으면 그 검사는 존재하지 않는 것과 같다 (13차 환경#8). 128M 공유호스팅
        // 에서 in-process 부팅 + migrate + seed 는 죽는데, 종전에는 이 두 값이 요구사항 목록에
        // 하나도 없어 운영자가 설치 중간에야 그 사실을 만났다.
        // failedRequirements.push() 를 하지 않는다 — 두 값 모두 실행 중 조정될 수 있고
        // (ini_set·set_time_limit), 낮다고 반드시 실패하지도 않는다. 서버 판정과 같은 규칙이다.
        if (data.php_limits) {
            const limitBytes = data.php_limits.memory_limit_bytes;
            const recommendedBytes = (data.php_limits.recommended_memory_mb || 0) * 1024 * 1024;
            let limitsStatusClass;
            if (typeof limitBytes !== 'number') {
                // ini_get 이 차단된 환경 — 확인 불가 (경고도 차단도 아님, OPcache 카드와 같은 3상태)
                limitsStatusClass = 'status-warning';
            } else if (limitBytes === -1 || limitBytes >= recommendedBytes) {
                // -1 은 무제한이다 — 바이트 값이 아니라 상태다
                limitsStatusClass = 'status-pass';
            } else {
                limitsStatusClass = 'status-warning';
            }
            html += renderSingleItemCard(
                lang('php_limits'),
                limitsStatusClass,
                data.php_limits.message || '',
                '',
                false
            );
        }

        // 11. 자산 URL 방식 카드 (안내 항목, 필수 아님)
        // 서버는 판정할 수 없으므로(loopback 이 vhost·프록시를 우회) "확인 중" 으로 먼저
        // 그리고 refreshAssetUrlModeCard() 가 브라우저 프로브 결과로 갱신한다.
        // failedRequirements.push() 를 하지 않는다 — 어느 방식이든 정상 동작이다.
        if (data.asset_url_mode) {
            html += `<div id="asset-url-mode-card">${renderSingleItemCard(
                lang('asset_url_mode'),
                'status-warning',
                lang('asset_url_mode_checking'),
                '',
                false
            )}</div>`;
        }

        // 12. 웹 노출 점검 카드 (필수 게이트 — B-4 · D3 · D21)
        // 판정 주체가 브라우저이므로 "확인 중" 으로 먼저 그리고 refreshWebExposureCard() 가
        // 프로브 결과로 갱신한다. failedRequirements.push() 를 하지 않는다 — 이 시점에는
        // 판정값이 없고, 노출/판정 불능의 안내·게이팅은 카드 갱신부가 담당한다.
        //
        // 카드 자리는 **서버 응답과 무관하게** 그린다. 항목이 없는 것은 배선 파손이라
        // 판정 불능(D21)인데, 그때 자리까지 없으면 사유도 운영자 확인 경로도 없이
        // [다음 단계] 만 비활성이 되어 [다시 검증] 을 영원히 누르게 된다.
        //
        // 이 자리는 **자기 라이브 리전을 선언하지 않는다.** 이 조각이 들어가는 바깥 영역
        // (`#requirements-result`)이 이미 `role="status" aria-live="polite"` 이고, 그 영역의
        // `aria-busy` 는 요구사항 목록을 그린 직후 **false** 로 풀린다 — 즉 그 뒤에 오는 이
        // 카드의 비동기 갱신도 **그 바깥 영역이** 낭독한다(변경 subtree 만 — `aria-atomic`
        // 기본값이 false 다). 안쪽에 리전을 한 번 더 선언하면 polite 안의 polite 가 되어
        // 화면 판독기 구현에 따라 중복 낭독 또는 안쪽 무시로 갈린다.
        //
        // `aria-busy` 는 남긴다 — 리전 선언이 아니라 **노출 지연 표시**다. 라이브 리전 안의
        // 요소가 busy 이면 그 안의 변경은 busy 가 풀릴 때까지 노출되지 않으므로, 판정 전의
        // 「확인 중…」이 최종 상태로 읽히는 것을 그대로 막는다. busy 해제는
        // refreshWebExposureCard 가 판정을 그린 뒤에 한다.
        html += `<div id="web-exposure-card" aria-busy="true">${renderSingleItemCard(
            lang('web_exposure'),
            'status-warning',
            lang('web_exposure_checking'),
            '',
            true
        )}</div>`;

        html += '</div>';

        // 필수 조건 미충족 시 에러 박스 표시
        if (failedRequirements.length > 0) {
            html += '<div class="requirements-error-box">';
            html += '<div class="error-box-header">';
            html += '<span class="error-icon">';
            html += '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false">';
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
                html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
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
                html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
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
                html += renderFtpPermissionGuide(data.directories && data.directories.ftp_guide_ownership);
                html += openShellPermissionGuide();
                html += `<p class="fix-guide-label">${lang('ownership_mismatch_option_group')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(groupCommand)}</pre>`;
                html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                html += '</div>';

                html += `<p class="fix-guide-label" style="margin-top: 1rem;">${lang('ownership_mismatch_option_chown')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(`sudo chown -R ${webUser}:${webUser} ${mismatchPaths}`)}</pre>`;
                html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                html += '</div>';

                html += `<p class="fix-guide-label" style="margin-top: 1rem;">${lang('ownership_mismatch_option_777')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(`chmod -R 777 ${mismatchPaths}`)}</pre>`;
                html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                html += '</div>';

                const hintText = lang('ownership_mismatch_hint')
                    .replace(':owner', escapeHtml(owner))
                    .replace(':web_user', escapeHtml(webUser));
                html += `<p class="fix-guide-hint" style="margin-top: 0.75rem;">${hintText}</p>`;
                html += '</details>';
                html += '</div>';
            }

            // 디렉토리 권한 문제 (쓰기/읽기 불가): chmod 755 단일 명령어 안내
            // 업계 표준(WordPress/Drupal/Joomla/Laravel)에 맞춰 chown 의존성 제거, 비트 강요 제거
            if (failedDirPaths.length > 0) {
                html += '<div class="permission-fix-guide">';
                html += renderFtpPermissionGuide(data.directories && data.directories.ftp_guide);
                html += openShellPermissionGuide();
                if (isWindows) {
                    const winDirPaths = failedDirPaths.map(p => p.replace(/\//g, '\\'));
                    const winDirCommands = winDirPaths.map(p => `icacls "${p}" /grant Everyone:(OI)(CI)F /T`);

                    // ReadOnly 속성이 원인이면 icacls 로는 해소되지 않는다(ACL 이 아니라 파일 속성).
                    // attrib -R 안내를 먼저 노출한다.
                    if (hasWindowsReadOnly) {
                        // 폴더 자체와 안쪽을 따로 푼다 — `attrib -R "<폴더>" /D /S` 는 폴더 자체만 풀고 안쪽은 읽기 전용으로 남긴다.
                        const winRoCommands = winDirPaths.flatMap(p => [`attrib -R "${p}"`, `attrib -R "${p}\\*" /S /D`]);
                        html += `<p class="fix-guide-label">${lang('permission_readonly_fix_guide')}</p>`;
                        html += '<div class="code-box">';
                        html += `<pre class="fix-command">${escapeHtml(winRoCommands.join('\n'))}</pre>`;
                        html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                        html += '</div>';
                        html += `<p class="fix-guide-hint">${lang('permission_readonly_hint')}</p>`;
                    }

                    html += `<p class="fix-guide-label">${lang('permission_fix_guide')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(winDirCommands.join('\n'))}</pre>`;
                    html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                    html += '</div>';
                    const winDirRelPaths = failedDirRelPaths.map(p => p.replace(/\//g, '\\'));
                    const winDirRelCommands = winDirRelPaths.map(p => `icacls "${p}" /grant Everyone:(OI)(CI)F /T`);
                    html += `<p class="fix-guide-hint">${lang('or_relative_path')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(winDirRelCommands.join('\n'))}</pre>`;
                    html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                    html += '</div>';
                    html += `<p class="fix-guide-hint">${lang('permission_windows_hint')}</p>`;
                } else {
                    const dirPathList = failedDirPaths.join(' ');
                    const dirRelPathList = failedDirRelPaths.join(' ');
                    html += `<p class="fix-guide-label">${lang('permission_fix_guide')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(`chmod -R 755 ${dirPathList}`)}</pre>`;
                    html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                    html += '</div>';
                    html += `<p class="fix-guide-hint">${lang('or_relative_path')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(`chmod -R 755 ${dirRelPathList}`)}</pre>`;
                    html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                    html += '</div>';
                }
                html += '</details>';
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
                // 무쉘(FTP) 판본을 먼저 편 채로 둔다 — 형제 분기(권한·소유자)와 같은 규율이다.
                // 이 갈래만 쉘 명령뿐이면, 쉘을 못 쓰는 호스팅의 운영자는 설치를 시작할 수단이
                // 없다(배포 ZIP 을 갓 푼 상태의 정상 경로다).
                html += renderFtpPermissionGuide(data.required_files && data.required_files.ftp_guide_create);
                html += openShellPermissionGuide();
                html += `<p class="fix-guide-label">${isWindows ? lang('required_files_fix_guide') : lang('required_files_fix_guide_combined')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(fileFixCommand)}</pre>`;
                html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                html += '</div>';
                html += `<p class="fix-guide-hint">${lang('or_relative_path')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(fileFixRelCommand)}</pre>`;
                html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                html += '</div>';
                html += '</details>';
                html += '</div>';
            }

            // 필수 파일 쓰기 불가: chmod 644 단일 명령어 안내
            if (notWritableFilePaths.length > 0) {
                const notWritableRelPaths = notWritableFilePaths.map(p => {
                    const basePath = data.required_files.base_path || '';
                    return basePath ? p.replace(basePath, '.') : p;
                });
                html += '<div class="permission-fix-guide">';
                html += renderFtpPermissionGuide(data.required_files && data.required_files.ftp_guide);
                html += openShellPermissionGuide();
                if (isWindows) {
                    const winFilePaths = notWritableFilePaths.map(p => p.replace(/\//g, '\\'));
                    const winFileCommands = winFilePaths.map(p => `icacls "${p}" /grant Everyone:F`);
                    html += `<p class="fix-guide-label">${lang('permission_fix_guide')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(winFileCommands.join('\n'))}</pre>`;
                    html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                    html += '</div>';
                    const winRelFilePaths = notWritableRelPaths.map(p => p.replace(/\//g, '\\'));
                    const winRelFileCommands = winRelFilePaths.map(p => `icacls "${p}" /grant Everyone:F`);
                    html += `<p class="fix-guide-hint">${lang('or_relative_path')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(winRelFileCommands.join('\n'))}</pre>`;
                    html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                    html += '</div>';
                    html += `<p class="fix-guide-hint">${lang('permission_windows_hint')}</p>`;
                } else {
                    const filePathList = notWritableFilePaths.join(' ');
                    const relFilePathList = notWritableRelPaths.join(' ');
                    html += `<p class="fix-guide-label">${lang('permission_fix_guide')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(`chmod 644 ${filePathList}`)}</pre>`;
                    html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                    html += '</div>';
                    html += `<p class="fix-guide-hint">${lang('or_relative_path')}</p>`;
                    html += '<div class="code-box">';
                    html += `<pre class="fix-command">${escapeHtml(`chmod 644 ${relFilePathList}`)}</pre>`;
                    html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                    html += '</div>';
                }
                html += '</details>';
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
                html += renderFtpPermissionGuide(data.required_files && data.required_files.ftp_guide_ownership);
                html += openShellPermissionGuide();
                html += `<p class="fix-guide-label">${lang('ownership_mismatch_option_group')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(fileGroupCommand)}</pre>`;
                html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                html += '</div>';

                html += `<p class="fix-guide-label" style="margin-top: 1rem;">${lang('ownership_mismatch_option_chown')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(`sudo chown ${fileWebUser}:${fileWebUser} ${mismatchFilePaths}`)}</pre>`;
                html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                html += '</div>';

                html += `<p class="fix-guide-label" style="margin-top: 1rem;">${lang('ownership_mismatch_option_666')}</p>`;
                html += '<div class="code-box">';
                html += `<pre class="fix-command">${escapeHtml(`chmod 666 ${mismatchFilePaths}`)}</pre>`;
                html += `<button class="btn-copy" data-action="copyPermissionCommand">${lang('copy_command')}</button>`;
                html += '</div>';

                const fileHintText = lang('ownership_mismatch_hint')
                    .replace(':owner', escapeHtml(fileOwner))
                    .replace(':web_user', escapeHtml(fileWebUser));
                html += `<p class="fix-guide-hint" style="margin-top: 0.75rem;">${fileHintText}</p>`;
                html += '</details>';
                html += '</div>';
            }

            html += '</div>';
        }

        return html;
    }

    /**
     * 단일 항목 카드 렌더링 (PHP 버전, 디스크 공간, HTTPS)
     *
     * @param {string} [bodyHtml] 헤더 아래 본문 — 판정의 사유처럼 한 줄 값으로 다 못 담는 설명.
     *   카드 **안**에 그린다. 카드 뒤에 붙이면 문단이 두 카드 사이에 떠서 어느 항목의 설명인지
     *   알 수 없다(에셋 서빙 방식 카드, 실측 2026-09-13).
     */
    function renderSingleItemCard(title, statusClass, currentValue, requiredValue, isRequired, bodyHtml = '') {
        const statusIcon = getStatusIcon(statusClass);
        const badgeText = isRequired ? lang('badge_required') : lang('badge_optional');
        const badgeClass = isRequired ? 'badge-required' : 'badge-optional';

        // 상태를 아이콘에만 반영하면 통과/경고 카드가 같은 색으로 보여 경각심이 전달되지 않는다.
        // 카드 자체에 수식자를 부여해 스캔만으로 문제 항목이 드러나게 한다.
        const cardModifier = statusClass === 'status-warning'
            ? ' requirement-card--warning'
            : (statusClass === 'status-fail' ? ' requirement-card--fail' : '');

        // 본문이 있으면 헤더 하단선을 살린다 — header-single 은 "헤더뿐인 카드" 의 선 제거다.
        const headerModifier = bodyHtml ? '' : ' header-single';
        const body = bodyHtml ? `<div class="requirement-card-body">${bodyHtml}</div>` : '';

        return `
            <div class="requirement-card${cardModifier}">
                <div class="requirement-card-header${headerModifier}">
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
                ${body}
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
        let labelKey = '';
        if (statusClass === 'status-pass') {
            iconType = 'completed';
            labelKey = 'status_label_pass';
        } else if (statusClass === 'status-fail') {
            iconType = 'failed';
            labelKey = 'status_label_fail';
        } else if (statusClass === 'status-warning') {
            iconType = 'warning';
            labelKey = 'status_label_warning';
        } else if (statusClass === 'status-info') {
            iconType = 'info';
            labelKey = 'status_label_info';
        }

        // 상태를 모양과 색으로만 전하면 화면 판독기 사용자와 색각 이상 사용자에게는
        // 판정이 전달되지 않는다 — 카드 본문에 "통과/실패" 텍스트가 따로 없기 때문이다.
        // 아이콘은 보조기술에서 감추고, 같은 뜻의 텍스트를 시각적으로만 숨겨 함께 싣는다 (9차 #L28).
        const label = labelKey ? `<span class="sr-only">${escapeHtml(lang(labelKey))}</span>` : '';

        return `<span class="status-icon ${statusClass}">${getIconSvg(iconType)}${label}</span>`;
    }

    /**
     * 실행 방식 판정 근거 목록 (W1 — 정보·차단 공용)
     *
     * 서버는 pass/info/fail 세 갈래 **모두**에 같은 `reasons` 를 싣는다. 그런데 종전에는
     * 정보(파랑) 카드만 그것을 그려서, 「왜 막혔는지」가 가장 절실한 차단(빨강) 화면이
     * 정보 화면보다 근거를 **덜** 주고 있었다 (14차 #12). 두 카드가 한 함수를 공유하면
     * 한쪽만 근거를 잃는 상태가 다시 생기지 않는다.
     *
     * @param {object} df disabled_functions 판정 응답
     * @returns {string} 근거가 없으면 빈 문자열
     */
    function renderExecutionModeReasons(df) {
        const reasons = (df.reasons || [])
            .map((r) => `<li>${escapeHtml(r)}</li>`)
            .join('');

        if (!reasons) {
            return '';
        }

        return `
                <p class="execution-mode-reasons-label">${lang('execution_mode_reason_prefix')}</p>
                <ul class="execution-mode-reasons">${reasons}</ul>
        `;
    }

    /**
     * 실행 방식 차단(빨강) 카드 렌더링 (A-1 · W1)
     *
     * 차단 사유는 「CLI 부재」가 아니라 **번들 부재**다 — CLI 가 없어도 동봉된 묶음이 있으면
     * 설치는 진행된다. 그 문장은 서버가 `message` 로 주고, 판정 근거는 정보 카드와 같은
     * 목록으로 이어 붙인다.
     *
     * 카드는 **라이브 리전을 선언하지 않는다.** 이 조각은 요구사항 목록과 함께 한 번에
     * `#requirements-result`(화면이 소유한 `role="status" aria-live="polite"`) 안으로
     * 들어가고 그 뒤로 갱신되지 않는다 — 안쪽에 리전을 또 두면 중첩이라 화면 판독기
     * 구현에 따라 중복 낭독 또는 안쪽 무시로 갈린다. 시각적 강조는 카드 클래스가 맡는다.
     *
     * @param {object} df disabled_functions 판정 응답 (status/reasons/message 포함)
     */
    function renderExecutionModeFailCard(df) {
        const disabledList = df.disabled || [];
        const valueText = disabledList.length > 0
            ? lang('required_functions_disabled').replace(':count', disabledList.length)
            : lang('execution_mode_reason_php_probe');

        const bodyHtml = `
            <div class="execution-mode-info">
                <p class="execution-mode-detail">${escapeHtml(df.message || lang('error_no_cli_and_no_bundle'))}</p>
                ${renderExecutionModeReasons(df)}
            </div>
        `;

        return `
            <div class="requirement-card requirement-card--fail">
                <div class="requirement-card-header">
                    <div class="header-left">
                        ${getStatusIcon('status-fail')}
                        <h3 class="requirement-card-title">${lang('execution_mode_title')}</h3>
                        <span class="requirement-text">exec, proc_open, shell_exec</span>
                    </div>
                    <div class="header-right">
                        <div class="current-value">
                            <span class="value-text">${valueText}</span>
                        </div>
                        <div class="requirement-badge badge-required">${lang('badge_required')}</div>
                    </div>
                </div>
                <div class="requirement-card-body">
                    ${bodyHtml}
                </div>
            </div>
        `;
    }

    /**
     * 실행 방식 안내 문서 주소 (D32)
     *
     * 설치본에는 `INSTALL.md` 가 함께 놓이지만 그 파일은 웹에서 열리지 않는다 —
     * 표준 배치에서는 문서 루트 밖이고, 문서 루트 고정 배치에서는 보호 규칙이 막는다.
     * 그래서 링크 대상은 공개 저장소의 같은 절이다.
     */
    const EXECUTION_MODE_GUIDE_URL = 'https://github.com/gnuboard/g7/blob/main/INSTALL.md#%EB%B0%A9%EB%B2%95-6-cli-%EC%97%86%EB%8A%94-%ED%98%B8%EC%8A%A4%ED%8C%85';

    /**
     * 실행 방식 정보(파랑) 카드 렌더링 (A-1 · W1)
     *
     * 실패가 아니라 "CLI 없이 설치한다" 는 정보 항목이다. 판정 근거와
     * 설치 후 달라지는 것 3가지를 카드 본문에 함께 표시한다.
     *
     * 라이브 리전을 선언하지 않는 이유는 형제 카드(renderExecutionModeFailCard)와 같다.
     *
     * @param {object} df disabled_functions 판정 응답 (status/reasons/message 포함)
     */
    function renderExecutionModeInfoCard(df) {
        const bodyHtml = `
            <div class="execution-mode-info">
                <p class="execution-mode-detail">${lang('execution_mode_inprocess_detail')}</p>
                ${renderExecutionModeReasons(df)}
                <p class="execution-mode-changes-intro">${lang('execution_mode_changes_intro')}</p>
                <ul class="execution-mode-changes">
                    <li>${lang('execution_mode_change_vendor')}</li>
                    <li>${lang('execution_mode_change_cron')}</li>
                    <li>${lang('execution_mode_change_update')}</li>
                </ul>
                <p class="execution-mode-more">
                    <a href="${escapeAttribute(EXECUTION_MODE_GUIDE_URL)}" target="_blank" rel="noopener noreferrer">${lang('execution_mode_learn_more')}</a>
                </p>
            </div>
        `;

        const statusIcon = getStatusIcon('status-info');

        // 배지는 badge-info 다 — 이 카드는 정보 항목이고 통과/차단 게이트가 아니다 (§12 W1).
        // 「필수」 배지를 달면 PHP 버전·필수 확장과 같은 게이트로 읽혀, 시안이 없애려던
        // "설치가 막힌 건가?" 라는 오해를 그대로 만든다.
        return `
            <div class="requirement-card requirement-card--info">
                <div class="requirement-card-header">
                    <div class="header-left">
                        ${statusIcon}
                        <h3 class="requirement-card-title">${lang('execution_mode_title')}</h3>
                    </div>
                    <div class="header-right">
                        <div class="current-value">
                            <span class="value-text">${lang('execution_mode_inprocess_notice')}</span>
                        </div>
                        <div class="requirement-badge badge-info">${lang('badge_info')}</div>
                    </div>
                </div>
                <div class="requirement-card-body">
                    ${bodyHtml}
                </div>
            </div>
        `;
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
        // 같은 주소를 GET 으로 다시 연다. 2단계 오류는 POST 응답 화면에 그려지므로 reload() 는 그 POST 를
        // 다시 보낸다(「양식 다시 제출」 확인 창, 서버 차단 기록 중복). 조각(#…)만 남은 주소로 옮기면
        // 같은 문서 안 이동이라 다시 열리지 않으므로 조각을 뗀다.
        window.location.replace(window.location.href.split('#')[0]);
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
                headers: stateChangeHeaders(),
                body: JSON.stringify(dbData)
            });

            const result = await response.json();

            if (result.success) {
                // Write DB 기존 테이블 감지: 인라인 상세 카드로 표시
                // 요구사항: 모달 대신 페이지 본문에 즉시 노출, 백업 동의 체크박스로
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
                    // 삭제 대상은 서버가 삭제 계산과 **동일 산출**(filterTablesByPrefix)로 내려준
                    // 목록을 그대로 그린다 (21차 #21-2 ①, 제품 결정) — 잘라내지 않는다: 동의의
                    // 대상이 화면에 전부 보여야 동의가 참이 된다.
                    const dropTargets = info.drop_targets || [];
                    // 더 긴 접두사의 다른 G7 설치(g7_ 입력 시 g7_shop_)가 삭제 범위에 물리면
                    // 별도 경고 — 접두 매칭은 그 사이트를 통째로 지운다 (21차 #21-2 ②).
                    const nestedPrefixes = info.nested_g7_prefixes || [];
                    const nestedWarningHtml = nestedPrefixes.length > 0 ? `
                            <div class="alert alert-error">
                                <strong>${escapeHtml(lang('db_nested_g7_warning', { prefixes: nestedPrefixes.join(', ') }))}</strong>
                            </div>` : '';
                    const dropTargetsHtml = dropTargets.length > 0 ? `
                            <p class="db-existing-tables-label"><strong>${escapeHtml(lang('db_drop_targets_list', { count: String(dropTargets.length) }))}</strong></p>
                            <pre class="db-existing-tables-list">${escapeHtml(dropTargets.join(', '))}</pre>` : '';
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
                            ${nestedWarningHtml}
                            ${dropTargetsHtml}
                            <p class="db-existing-backup-label"><strong>${escapeHtml(lang('db_backup_guide'))}</strong></p>
                            <div class="code-box">
                                <pre>${escapeHtml(backupCmd)}</pre>
                                <button type="button" class="btn-copy" data-action="copyPermissionCommand">${escapeHtml(lang('copy_command'))}</button>
                            </div>
                            <label class="db-backup-confirm-checkbox">
                                <input type="checkbox" id="db-backup-confirmed" data-action="onDbBackupConsentChange" data-event="change" ${alreadyConsented ? 'checked' : ''}>
                                ${escapeHtml(lang('db_backup_confirmed'))}
                            </label>
                        </div>
                    `;
                    window.__g7_existing_tables = info;
                    // 서버가 발급한 삭제 확인 토큰 — 설치 시작 시 drop_tables 와 함께 제시해야
                    // 삭제가 실행된다 (요청 본문의 액션 값만으로는 삭제되지 않음).
                    if (result.drop_confirm) {
                        sessionStorage.setItem('drop_confirm', result.drop_confirm);
                    } else {
                        sessionStorage.removeItem('drop_confirm');
                    }
                    // FormValidator에 동의 요구 등록
                    if (typeof FormValidator !== 'undefined') {
                        FormValidator.setDbCleanupRequired(true);
                        FormValidator.setDbCleanupConsented(alreadyConsented);
                    }
                } else if (type === 'write') {
                    // 빈 DB → 동의 요구 해제 + sessionStorage 정리
                    window.__g7_existing_tables = null;
                    sessionStorage.removeItem('existing_db_action');
                    sessionStorage.removeItem('drop_confirm');
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
                    ${result.privilege_warning ? `
                    <div class="alert alert-warning">
                        <span>${escapeHtml(result.privilege_warning)}</span>
                    </div>` : ''}
                    ${result.db_server_notice ? `
                    <div class="alert alert-warning">
                        <span>${escapeHtml(result.db_server_notice)}</span>
                    </div>` : ''}
                    ${(result.existing_tables && result.existing_tables.check_failed) ? `
                    <div class="alert alert-warning">
                        <span>${escapeHtml(lang('db_existing_check_failed'))}</span>
                    </div>` : ''}
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
        // 기존 DB 테이블 감지 시 삭제 동의 추적
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
     * DB 최고권한 계정명 여부 판정
     *
     * 목록은 서버(App\Support\PrivilegedDatabaseAccounts::BLOCKED)에서 내려온 값을
     * 그대로 쓴다. JS 에 목록을 중복 정의하면 서버와 어긋날 수 있다.
     * 비교는 서버와 동일하게 소문자·트림 기준.
     *
     * @param {string} username 검사할 DB 사용자명
     * @returns {boolean} 최고권한 계정이면 true
     */
    function isPrivilegedDbAccount(username) {
        var blocked = window.INSTALLER_BLOCKED_DB_ACCOUNTS;

        // 서버 목록이 없으면 클라이언트 판정을 포기한다 (서버 검증이 최종 방어선).
        if (!Array.isArray(blocked)) {
            return false;
        }

        return blocked.indexOf(String(username || '').trim().toLowerCase()) !== -1;
    }

    /**
     * 최고권한 계정 차단 메시지를 생성한다.
     *
     * JS 의 lang() 은 서버 lang() 과 달리 치환을 지원하지 않으므로 호출부에서 수동 치환한다.
     *
     * @param {string} username 입력된 DB 사용자명
     * @returns {string} 치환된 메시지
     */
    function privilegedDbAccountMessage(username) {
        return getLangMessage('error_db_username_privileged').replace(':username', username);
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

        // DB 사용자명 검증 — 최고권한 계정은 보안상 사용할 수 없다.
        if ((fieldName === 'db_write_username' || fieldName === 'db_read_username') && value) {
            if (isPrivilegedDbAccount(value)) {
                showFieldError(field, privilegedDbAccountMessage(value));
                return false;
            }
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
            // 접두사 길이 제한 — 길면 자동 생성 인덱스명이 DB 한도(64자)를 초과한다.
            // 서버 MAX_DB_PREFIX_LENGTH 와 동일한 값을 유지한다.
            var maxPrefixLength = field.maxLength && field.maxLength > 0 ? field.maxLength : 6;
            if (value.length > maxPrefixLength) {
                showFieldError(field, getLangMessage('error_db_prefix_too_long')
                    .replace(':max', maxPrefixLength)
                    .replace(':current', value.length));
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
    /**
     * 자산 URL 방식을 브라우저에서 판정한다 (이슈 #486 §6·§7).
     *
     * Step 2 요구사항 카드와 Step 3 설정 폼이 **같은 함수를 쓴다.** 판정 로직을 두 벌
     * 두면 한쪽만 고쳐져 화면마다 다른 답을 내놓는다 (이 이슈에서 이미 URL 생성부가
     * 그렇게 어긋났다).
     *
     * 정적 최적화 블록(`location ~* \.(js|css|json)$`)은 정규식 location 이라 프리픽스
     * location 보다 먼저 매칭되고, 그 안에 PHP 핸들러가 없으면 확장자 붙은 동적 응답이
     * PHP 에 도달하지 못한 채 404 가 된다. 설치 시점에 판정해 두지 않으면 설치 직후
     * 첫 화면부터 백지가 된다.
     *
     * 판정은 **쌍으로** 던진다. 단일 프로브는 "PHP 자체가 죽음" 과 구분되지 않는다.
     *
     *   probe.js 실패 + probe 성공 = 정적 블록 가로채기 확정 → extensionless
     *   둘 다 성공                  = extension
     *   둘 다 실패                  = PHP/라우팅 문제 (모드 문제 아님) → 판정 보류
     *
     * 성공 판정은 상태코드가 아니라 **본문의 매직 토큰**으로 한다. 상태코드만 보면
     * "404 대신 200 + 에러 HTML" 이나 catch-all 200 페이지를 반환하는 설정에서
     * 영원히 오판한다.
     */
    async function probeAssetUrlMode(base) {
        const TOKEN = 'G7_ASSET_PROBE_OK';
        const root = (base || '').replace(/\/+$/, '');

        /**
         * 프로브 1건을 던져 매직 토큰 포함 여부를 반환한다.
         */
        const probe = async (path) => {
            try {
                const res = await fetch(`${root}${path}`, { cache: 'no-store', credentials: 'omit' });
                if (!res.ok) return false;

                // Content-Type 도 함께 본다 (L6). 토큰만 검사해도 200+HTML 오판은
                // 걸러지지만, 계획서는 두 신호를 모두 요구한다.
                const contentType = res.headers.get('content-type') || '';
                if (!/javascript|ecmascript/i.test(contentType)) return false;

                return (await res.text()).includes(TOKEN);
            } catch (e) {
                return false;
            }
        };

        const [withExt, withoutExt] = await Promise.all([
            probe('/api/system/asset-probe.js'),
            probe('/api/system/asset-probe'),
        ]);

        if (withExt && withoutExt) {
            return 'extension';
        }
        if (!withExt && withoutExt) {
            return 'extensionless';
        }

        // 둘 다 실패 = 모드 문제가 아니다(PHP/라우팅 장애). 빈 문자열로 판정 보류.
        return '';
    }

    /**
     * Laravel 앱 루트 경로를 반환한다 (`INSTALLER_BASE_URL` 은 항상 `/install` 로 끝난다).
     *
     * Step 2 에는 `app_url` 입력이 아직 없으므로 현재 origin 기준으로 조립한다.
     */
    function getAppRootUrl() {
        // 표기를 가리지 않는다 — 형제 `getProjectRootCandidates` 와 같은 규칙(16차 G3 A12).
        const base = (window.INSTALLER_BASE_URL || '').replace(/\/install$/i, '');

        return `${window.location.origin}${base}`;
    }

    /**
     * Step 2 요구사항 카드의 자산 URL 방식 항목을 프로브 결과로 갱신한다 (§7).
     *
     * 서버는 이 값을 판정할 수 없어(loopback 이 vhost·프록시를 우회) 카드가 먼저
     * "확인 중" 으로 그려진 뒤 여기서 채워진다. 통과/실패 게이트가 아니라 안내 항목이라
     * `failedRequirements` 에 넣지 않는다 — 어느 방식이든 정상 동작이다.
     */
    async function refreshAssetUrlModeCard() {
        const slot = document.getElementById('asset-url-mode-card');
        if (!slot) return;

        const detected = await probeAssetUrlMode(getAppRootUrl());

        // 확장자 미사용은 "서버가 확장자 주소를 가로채고 있다" 는 신호이므로 눈에 띄게 둔다.
        // 판정 불가도 마찬가지 — 설치 후 백지 화면의 예고일 수 있다.
        const statusClass = detected === 'extension' ? 'status-pass' : 'status-warning';
        const label = detected === 'extension'
            ? lang('asset_url_mode_extension')
            : (detected === 'extensionless' ? lang('asset_url_mode_extensionless') : lang('asset_url_mode_unknown'));

        // 경고 상태(확장자 미사용 자동 선택 / 확인 불가)는 "왜 그런지" 를 설명 문구로
        // 함께 보인다 — 짧은 라벨("확인 불가")만으로는 어떤 상황인지 알 수 없다.
        // "확인 불가" 는 두 프로브가 모두 실패한 상태라 에셋 방식 문제가 아니라
        // 앱 미응답·프록시·CSP 등 다른 원인일 수 있으므로 그 내용을 상세히 안내한다.
        let note = '';
        if (detected === 'extensionless') {
            note = lang('asset_url_mode_detected_extensionless');
        } else if (detected === '') {
            note = lang('asset_url_mode_detected_unavailable');
        }
        // 설명은 카드 본문으로 들어간다 — 카드 뒤에 이어 붙이면 문단이 이 카드와 다음 카드
        // 사이에 떠서 어느 항목의 설명인지 알 수 없다.
        const noteHtml = note ? `<p class="fix-guide-hint">${note}</p>` : '';

        // 카드 내부를 부분 수정하지 않고 통째로 다시 그린다 — 아이콘·수식자 클래스가
        // renderSingleItemCard 안에서 statusClass 로 함께 결정되므로, 밖에서 일부만
        // 건드리면 아이콘과 카드 색이 어긋난다.
        slot.innerHTML = renderSingleItemCard(lang('asset_url_mode'), statusClass, label, '', false, noteHtml);
    }

    async function detectAssetUrlMode() {
        const field = document.getElementById('asset_url_mode');
        if (!field) return;

        const base = (document.querySelector('[name="app_url"]')?.value || '').replace(/\/+$/, '');
        const detected = await probeAssetUrlMode(base);

        field.value = detected;

        // 수동 override — 감지 결과를 기본 선택으로 채우고 관리자가 바꿀 수 있게 한다.
        // 프로브가 CSP/프록시 등으로 둘 다 실패하면 자동 판정이 불가하므로,
        // 손댈 수단이 없으면 설치를 마친 뒤에야 문제를 알게 된다.
        const select = document.getElementById('asset_url_mode_select');
        const status = document.getElementById('asset_url_mode_status');
        if (select) {
            select.value = detected || 'extension';
            select.addEventListener('change', function () {
                field.value = this.value;
            });
            // 감지 실패 시에도 select 값이 hidden 에 반영되도록 초기 동기화
            field.value = select.value;
        }
        if (status) {
            status.textContent = status.getAttribute(
                detected ? `data-msg-${detected}` : 'data-msg-unavailable'
            ) || '';
            status.classList.remove('hidden');
        }
    }

    function initRealTimeValidation() {
        // Step 3 (config-form)이 있는 경우에만 실행
        const configForm = document.getElementById('config-form');
        if (!configForm) return;

        // 자산 URL 방식 자동 감지 (비차단 — 실패해도 설치 진행에 지장 없음)
        detectAssetUrlMode();

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
        } else if (isPrivilegedDbAccount(dbWriteUsername.value)) {
            errors.push({
                field: dbWriteUsername,
                message: privilegedDbAccountMessage(dbWriteUsername.value.trim()),
            });
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
            } else if (isPrivilegedDbAccount(dbReadUsername.value)) {
                errors.push({
                    field: dbReadUsername,
                    message: privilegedDbAccountMessage(dbReadUsername.value.trim()),
                });
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

        /* region:step3-cli-gate — 이 구간은 회귀 테스트가 소스에서 잘라 **실제로 실행**한다
           (`installer-step3-cli-gate.test.ts`). 구간 표식을 지우면 그 축이 통째로 사각이 된다. */
        // PHP CLI / Composer 검증 확인
        if (window.CliValidator) {
            const cliSection = document.getElementById('php-cli-section');
            const cliRequired = window.CliValidator.cliRequired;
            const cliSectionVisible = cliSection && !cliSection.classList.contains('hidden');

            if (cliRequired || cliSectionVisible) {
                // inprocess 에서는 PHP 실행 경로가 쓰이지 않으므로 검증을 요구하지 않는다 (19차 S-1).
                // 그 환경에서는 `validatePhpPath()` 가 `exec` 가드에 걸려 언제나 실패하므로,
                // 요구하면 통과할 방법이 없는 막다른 길이 된다. vendor 축이 이미 같은 면제를 갖는다.
                const phpCliRequired = typeof window.CliValidator.isPhpCliRequired === 'function'
                    ? window.CliValidator.isPhpCliRequired()
                    : true;
                if (phpCliRequired && !window.CliValidator.phpVerified) {
                    errors.push({ field: document.getElementById('php_binary'), message: lang('error_php_cli_not_verified') });
                }
                // vendor_mode='bundled' 에서는 composer 실행이 불필요하므로 검증 스킵
                if (window.CliValidator.isComposerRequired() && !window.CliValidator.composerVerified) {
                    errors.push({ field: document.getElementById('composer_binary'), message: lang('error_composer_not_verified') });
                }
            }
        }
        /* endregion:step3-cli-gate */

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
        // 사이트 주소 기본값: 저장된 값이 없으면 운영자가 실제로 보고 있는 주소(앱 루트)로 채운다 —
        // 서버가 아는 Host 는 프록시 뒤에서 내부 주소·http 일 수 있다.
        const appUrlInput = document.getElementById('app_url');
        if (appUrlInput && appUrlInput.dataset.autofill === '1') {
            appUrlInput.value = getAppRootUrl();
        }

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

    /**
     * 선택 연동(integrations)으로 자동 체크한 대상 — owner 식별자 → Set(대상 식별자).
     *
     * 연동은 「있으면 연동되고 없어도 동작한다」라서 의존성과 달리 해제를 막지 않고 「다음」도 막지 않는다.
     * 사용자가 직접 고른 확장의 연동 대상만 한 단계 자동 체크한다(자동 체크된 확장의 연동은 따라가지 않는다).
     */
    const autoSelectedByIntegration = {};

    /** 사용자가 직접 해제한 연동 자동 체크 대상 — 다시 자동 체크하지 않는다 */
    const userDeselectedIntegrations = new Set();

    /** 연동 자동 체크 대상 유형 — 템플릿은 단일 선택이라 안내만 한다 */
    const INTEGRATION_SELECTABLE_TYPES = ['modules', 'plugins'];

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
            // 본문을 **먼저** 읽는다 (19차 S-4·S-11).
            //
            // 종전에는 `response.ok` 판정이 본문 읽기보다 앞에 있어, 서버가 세 사유를 갈라
            // 보내도(디렉토리 열거 불가 · 관리자 템플릿 없음 · 스캔 실패) 화면에는
            // **「HTTP 500」 다섯 글자**만 남았다. 운영자는 무엇을 고쳐야 하는지 알 수 없고,
            // 그 사유를 담을 다국어 키는 실재하는데 소비처가 0이었다. 게다가 `error_message`
            // 는 lang 키가 아니라 **영어 원문**이라, 그 경로가 살아나면 한국어 화면에
            // 개발자 문구가 그대로 떴다 — 형제 엔드포인트 4곳은 전부 `message_key` 규약을 지킨다.
            let result = null;
            try {
                result = await response.json();
            } catch (_) {
                result = null;
            }

            if (!response.ok || !result || !result.success) {
                const key = result && result.message_key;
                const labels = window.EXTENSION_LABELS || {};
                // 최후 폴백도 다국어 표를 거친다 — `EXTENSION_LABELS` 가 실리기 전의 실패에서
                // 영문 리터럴이 한국어 화면에 그대로 나갔다 (13차 G3 W1-13).
                const message = (key && labels[key])
                    || labels.extension_load_failed
                    || lang('extension_load_failed');

                throw new Error(message);
            }

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
        const currentLang = window.INSTALLER_LOCALE || window.installerLocale?.get() || 'ko';
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

        // 확장이 아이콘을 동봉했으면 유형 글리프 대신 그 그림을 그린다.
        // 서버(scan-extensions.php)가 검증기를 통과한 바이트만 data URI 로 보내지만,
        // innerHTML 조립이라 여기서도 형태를 다시 검사한다 — 정규식이 따옴표·꺾쇠를
        // 배제하므로 속성 탈출이 성립하지 않는다.
        const DATA_URI_RE = /^data:image\/(svg\+xml|png|webp);base64,[A-Za-z0-9+/]+=*$/;
        const iconHtml = (typeof item.icon_data_uri === 'string' && DATA_URI_RE.test(item.icon_data_uri))
            ? '<img class="extension-card-icon-img" src="' + item.icon_data_uri + '" alt="" loading="lazy">'
            : '<i class="fas ' + iconClass + '"></i>';

        const name = getLocalizedText(item.name) || item.identifier;
        const description = getLocalizedText(item.description);
        const version = item.version || '1.0.0';
        const author = item.author || '';

        // 의존성 표시 (identifier → name 변환, 모듈/플러그인 아이콘 추가)
        let depsBlock = '';
        if (item.dependencies && item.dependencies.length > 0) {
            const depsText = item.dependencies.map(function(depId) {
                const depInfo = findExtensionById(depId);
                const depName = depInfo ? getLocalizedText(depInfo.name) : depId;
                const depIcon = depInfo ? (depInfo.type === 'plugin' ? 'fa-plug' : 'fa-cube') : 'fa-cube';
                return '<span class="dep-tag"><i class="fas ' + depIcon + '"></i> ' + escapeHtml(depName) + '</span>';
            }).join(' ');
            depsBlock = '<div class="extension-card-dependencies">' +
                '<span class="dep-label">' + (window.EXTENSION_LABELS?.dependencies || lang('dependencies')) + ':</span> ' + depsText + '</div>';
        }

        // 선택 연동 표시 (「연동: 게시판 · 쇼핑몰 …」, 번들 버전 미달 경고 포함)
        const integrationsBlock = buildIntegrationsLineHtml(item);

        const depsHtml = (depsBlock || integrationsBlock)
            ? '<div class="extension-card-footer">' + depsBlock + integrationsBlock + '</div>'
            : '';

        // 서버에 없는 PHP 확장(composer.json require/suggest ext-*) — 필수가 없으면 고를 수 없게 끈다(#151)
        const phpBlock = buildPhpExtensionNoticeHtml(item);
        const blockedByPhp = hasMissingRequiredPhpExtensions(item);
        if (blockedByPhp) {
            card.classList.add('disabled');
            card.dataset.phpBlocked = '1';
        }

        // 선택 표시 아이콘 (단일 선택은 라디오, 다중 선택은 체크박스)
        const selectIcon = isSingleSelect
            ? (isSelected ? '<i class="fas fa-check-circle"></i>' : '<i class="far fa-circle"></i>')
            : (isSelected ? '<i class="fas fa-check-square"></i>' : '<i class="far fa-square"></i>');

        card.innerHTML =
            '<div class="extension-card-header">' +
                '<div class="extension-card-icon">' + iconHtml + '</div>' +
                '<div class="extension-card-info">' +
                    '<h4 class="extension-card-name">' + escapeHtml(name) + '</h4>' +
                    '<div class="extension-card-meta">v' + escapeHtml(version) + (author ? ' · ' + escapeHtml(author) : '') + '</div>' +
                '</div>' +
                '<div class="extension-card-select">' + selectIcon + '</div>' +
            '</div>' +
            (description ? '<p class="extension-card-description">' + escapeHtml(description) + '</p>' : '') +
            depsHtml + phpBlock;

        card.addEventListener('click', function() {
            if (blockedByPhp) return;
            toggleExtension(type, item.identifier, isSingleSelect);
        });

        return card;
    }

    /**
     * 권한 안내 무쉘 판본을 그린다 (W7).
     *
     * 이 화면을 보는 사람의 다수는 SSH 를 쓸 수 없다 — 그래서 FTP 조작 절차가 **기본 판본**
     * 이고 기본 펼침이며, 명령어는 접힌 보조 판본이다. 절차 문구는 서버가 만든다(경로 목록·
     * OS·소유 관계 판정을 두 벌로 갖지 않기 위해). 서버가 절차를 주지 않으면 아무것도 그리지
     * 않는다 — 그 자리에 빈 아코디언이 남으면 운영자가 접힌 내용을 찾는다.
     *
     * @param {{steps: string[], note: string}|null|undefined} guide 서버가 만든 절차
     * @returns {string} HTML 조각 (없으면 빈 문자열)
     */
    function renderFtpPermissionGuide(guide) {
        if (!guide || !Array.isArray(guide.steps) || guide.steps.length === 0) {
            return '';
        }

        let html = '<details class="permission-guide" open>';
        html += `<summary class="permission-guide-summary">${lang('permission_guide_ftp_summary')}</summary>`;
        html += '<ol class="permission-guide-steps">';
        guide.steps.forEach(step => {
            html += `<li>${escapeHtml(step)}</li>`;
        });
        html += '</ol>';
        if (guide.note) {
            html += `<p class="permission-guide-note">${escapeHtml(guide.note)}</p>`;
        }
        html += '</details>';

        return html;
    }

    /**
     * 쉘 명령 판본의 여는 태그 — FTP 판본과 달리 접어 둔다.
     *
     * @returns {string} HTML 조각
     */
    function openShellPermissionGuide() {
        return '<details class="permission-guide">'
            + `<summary class="permission-guide-summary">${lang('permission_guide_shell_summary')}</summary>`;
    }

    /**
     * 연동 대상 유형 키(scan-extensions 의 integrations_detailed.type)로 번들 항목을 찾습니다.
     *
     * @param {string} integrationType - modules | plugins | templates
     * @param {string} identifier - 대상 식별자
     * @returns {object|null} 번들 항목 또는 null
     */
    function findIntegrationTarget(integrationType, identifier) {
        if (!extensionsData) return null;
        const categories = integrationType === 'templates'
            ? ['admin_templates', 'user_templates']
            : [integrationType];
        for (let i = 0; i < categories.length; i++) {
            const found = (extensionsData[categories[i]] || []).find(function(e) { return e.identifier === identifier; });
            if (found) return found;
        }
        return null;
    }

    /**
     * 확장이 필수로 요구한 PHP 확장 중 서버에 없는 것이 있는지 판정합니다.
     *
     * @param {object} item - 확장 항목 (scan-extensions 의 missing_php_extensions 포함)
     * @returns {boolean} 필수 PHP 확장이 하나라도 없으면 true
     */
    function hasMissingRequiredPhpExtensions(item) {
        return !!(item && Array.isArray(item.missing_php_extensions) && item.missing_php_extensions.length > 0);
    }

    /**
     * 카드의 PHP 확장 안내 HTML 을 만듭니다. (필수 없음 → 설치 불가, 권장 없음 → 경고만)
     *
     * @param {object} item - 확장 항목 (missing_php_extensions · suggested_missing 포함)
     * @returns {string} HTML, 안내할 것이 없으면 빈 문자열
     */
    function buildPhpExtensionNoticeHtml(item) {
        let html = '';

        if (hasMissingRequiredPhpExtensions(item)) {
            html += '<div class="extension-card-php-notice extension-card-php-blocked"><i class="fas fa-ban"></i> ' +
                escapeHtml(lang('extension_php_missing_required', { extensions: item.missing_php_extensions.join(', ') })) +
                '</div>';
        }

        const suggested = (item && item.suggested_missing && typeof item.suggested_missing === 'object') ? item.suggested_missing : {};
        const suggestedNames = Object.keys(suggested);
        if (suggestedNames.length > 0) {
            const reasons = suggestedNames.map(function(n) { return suggested[n]; }).filter(Boolean).join(', ');
            html += '<div class="extension-card-php-notice"><i class="fas fa-exclamation-triangle"></i> ' +
                escapeHtml(lang('extension_php_missing_suggested', {
                    extensions: suggestedNames.join(', '),
                    reason: reasons || suggestedNames.join(', '),
                })) +
                '</div>';
        }

        return html;
    }

    /**
     * owner 카드의 「연동: …」 줄 HTML 을 만듭니다.
     *
     * - 템플릿 대상은 자동 체크하지 않으므로 「직접 선택」 안내를 붙인다.
     * - 번들 버전이 선언한 제약에 못 미치면 「연동 버전 미달」을 붙인다(선택은 막지 않음).
     *
     * @param {object} item - 확장 항목 (integrations_detailed 포함)
     * @returns {string} HTML, 연동 선언이 없으면 빈 문자열
     */
    function buildIntegrationsLineHtml(item) {
        const list = (item && item.integrations_detailed) || [];
        if (list.length === 0) return '';

        const tags = list.map(function(it) {
            if (!it || !it.id) return '';
            const target = findIntegrationTarget(it.type, it.id);
            const targetName = target ? (getLocalizedText(target.name) || it.id) : it.id;
            const icon = it.type === 'plugins' ? 'fa-plug' : (it.type === 'templates' ? 'fa-palette' : 'fa-cube');
            const isTemplate = it.type === 'templates';
            const mismatch = !!(target && it.version && it.version !== '*' &&
                !satisfiesVersionConstraint(target.version || '0.0.0', it.version));

            let cls = 'integration-tag';
            if (isTemplate) cls += ' integration-tag-template';
            if (mismatch) cls += ' integration-version-mismatch';

            let html = '<span class="' + cls + '" data-integration-type="' + escapeHtml(it.type) + '"' +
                ' data-integration-id="' + escapeHtml(it.id) + '">' +
                '<i class="fas ' + icon + '"></i> ' + escapeHtml(targetName);
            if (isTemplate) {
                html += ' <span class="integration-template-hint">(' + escapeHtml(lang('integration_template_hint')) + ')</span>';
            }
            if (mismatch) {
                html += ' <span class="integration-version-warning">' +
                    escapeHtml(lang('integration_version_mismatch')) + ' (' +
                    escapeHtml(lang('dep_version_required')) + ': ' + escapeHtml(it.version) + ', ' +
                    escapeHtml(lang('dep_version_available')) + ': ' + escapeHtml(target.version || '') + ')</span>';
            }
            html += '</span>';
            return html;
        }).join(' ');

        return '<div class="extension-card-integrations">' +
            '<span class="integration-label">' + escapeHtml(window.EXTENSION_LABELS?.integrations || lang('integrations')) + ':</span> ' +
            tags + '</div>';
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
     * **속성 자리**에 값을 넣을 때 쓴다.
     *
     * `escapeHtml()` 은 `textContent → innerHTML` 이라 `& < >` 만 바꾸고 따옴표는 통과시킨다.
     * 텍스트 노드에서는 그것으로 충분하지만 `href="..."` 안에서는 값이 따옴표를 닫고 나올
     * 수 있다. 이 자리의 값(`installerDeriveBaseUrl()` 산출물)은 `REQUEST_URI` 에서 파생되어
     * 요청자 영향권에 있다.
     *
     * @param {string} value 속성 값
     * @returns {string} 따옴표까지 이스케이프된 값
     */
    function escapeAttribute(value) {
        return escapeHtml(value)
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
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
            // 필수 PHP 확장이 없는 모듈·플러그인은 일괄 선택에서도 뺀다(#151)
            if (hasMissingRequiredPhpExtensions(item)) return;
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
                setInputDisabled(cb, true);
                return;
            }
            setInputDisabled(cb, false);
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
        // 필수 PHP 확장이 없는 확장은 직접 클릭·의존 자동 선택 어느 쪽으로도 고르지 않는다(#151)
        if (card.dataset.phpBlocked === '1' && selectedExtensions[type].indexOf(identifier) === -1) return;

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

        // 이번 호출로 새로 선택되는가 — 사용자가 직접 고른 순간에만 연동 대상을 자동 체크한다
        let becameSelected = false;

        if (isSingleSelect) {
            // 단일 선택 모드 — 이전 템플릿 해제 시 자동 선택 항목도 정리
            if (isCurrentlySelected && !isRequired) {
                // 사용자 템플릿: 이미 선택된 항목 클릭 시 선택 해제
                clearAutoSelectedByOwner(type, identifier);
                clearIntegrationAutoSelectedByOwner(identifier);
                selectedExtensions[type] = [];
                card.classList.remove('selected');
                const selectEl = card.querySelector('.extension-card-select');
                if (selectEl) selectEl.innerHTML = '<i class="far fa-circle"></i>';
            } else {
                // 다른 항목 선택 해제 전 이전 템플릿의 자동 선택 항목 정리
                const prevId = selectedExtensions[type][0];
                if (prevId && prevId !== identifier) {
                    clearAutoSelectedByOwner(type, prevId);
                    clearIntegrationAutoSelectedByOwner(prevId);
                }
                becameSelected = !isCurrentlySelected;
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
                becameSelected = true;
            } else {
                // 사용자가 연동 자동 체크 항목을 직접 해제했으면 기억해 다시 체크하지 않는다
                if (!_isAutoSelect && isIntegrationAutoSelected(identifier)) {
                    userDeselectedIntegrations.add(identifier);
                }
                forgetIntegrationAutoSelection(identifier);

                // 해제 시 자동 선택 표시 제거
                autoSelectedSet.delete(identifier);
                removeAutoSelectedUI(identifier);
                selectedExtensions[type].splice(index, 1);
                card.classList.remove('selected');
                const selectEl = card.querySelector('.extension-card-select');
                if (selectEl) selectEl.innerHTML = '<i class="far fa-square"></i>';

                // 이 항목이 연동 owner 였다면 그 owner 가 자동 체크한 항목 중 다른 근거 없는 것을 해제
                clearIntegrationAutoSelectedByOwner(identifier);
            }
        }

        // 사용자가 직접 고른 순간에만 연동 대상을 한 단계 자동 체크한다(D8 — 자동 선택은 연쇄하지 않음)
        if (becameSelected && !_isAutoSelect) {
            autoSelectIntegrations(type, identifier);
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

    /**
     * 「Step 4 로 돌아가기」 등으로 다시 들어왔을 때 저장된 선택을 되살린다.
     *
     * Step 4 화면은 첫 방문에도 상태 파일 기본값(유형별 빈 배열)을 넘기므로, 하나라도 고른 항목이
     * 있을 때만 복원으로 취급한다 — 빈 값으로 카드 렌더의 기본 선택(첫 관리자 템플릿)을 덮지 않는다.
     * 지금 목록에 없는 식별자는 버리고, 관리자 템플릿이 비면 첫 항목으로 채운 뒤 카드 표시를 선택 상태에 맞춘다.
     */
    function restoreSelectionState() {
        var types = ['admin_templates', 'user_templates', 'modules', 'plugins', 'language_packs'];
        var saved = window.INSTALLER_SELECTED_EXTENSIONS;
        var hasSaved = !!saved && types.some(function(type) {
            return Array.isArray(saved[type]) && saved[type].length > 0;
        });

        if (hasSaved) {
            types.forEach(function(type) {
                if (!Array.isArray(saved[type])) return;
                var available = extensionsData
                    ? (extensionsData[type] || []).map(function(item) { return item.identifier; })
                    : null;
                selectedExtensions[type] = saved[type].filter(function(identifier, idx, list) {
                    return list.indexOf(identifier) === idx && (!available || available.indexOf(identifier) !== -1);
                });
            });
            // 템플릿은 단일 선택이다
            ['admin_templates', 'user_templates'].forEach(function(type) {
                if (selectedExtensions[type].length > 1) {
                    selectedExtensions[type] = selectedExtensions[type].slice(0, 1);
                }
            });
        }
        if (extensionsData) {
            types.forEach(function(type) {
                var items = extensionsData[type] || [];
                items.forEach(function(item) {
                    if (item.is_required && selectedExtensions[type].indexOf(item.identifier) === -1) {
                        selectedExtensions[type].push(item.identifier);
                    }
                });
            });
            // 관리자 템플릿은 필수 — 비면 카드 렌더와 같은 기본(첫 항목)으로 채운다
            var adminItems = extensionsData.admin_templates || [];
            if (selectedExtensions.admin_templates.length === 0 && adminItems.length > 0) {
                selectedExtensions.admin_templates = [adminItems[0].identifier];
            }
        }
        // 카드의 선택 표시(클래스·아이콘)를 선택 상태와 일치시킨다
        types.forEach(function(type) {
            var isSingleSelect = (type === 'admin_templates' || type === 'user_templates');
            document.querySelectorAll('.extension-card[data-type="' + type + '"]').forEach(function(card) {
                var isSelected = selectedExtensions[type].indexOf(card.dataset.identifier) !== -1;
                card.classList.toggle('selected', isSelected);
                var selectEl = card.querySelector('.extension-card-select');
                if (selectEl) {
                    selectEl.innerHTML = isSingleSelect
                        ? (isSelected ? '<i class="fas fa-check-circle"></i>' : '<i class="far fa-circle"></i>')
                        : (isSelected ? '<i class="fas fa-check-square"></i>' : '<i class="far fa-square"></i>');
                }
            });
        });
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

        // 추이적 의존성 자동 해결 — 서버와 같은 해석기(고정점 10회)가 정한 자동 포함 목록을 화면에 반영한다.
        // 서버(확장 저장 API)도 같은 해석으로 의존 확장을 채우므로 화면과 저장 결과가 어긋나지 않는다.
        const resolution = window.G7ExtensionResolver.resolveExtensionSelection(extensionsData, selectedExtensions);
        resolution.auto_added.forEach((added) => {
            const targetCat = added.category || idToCategory[added.identifier];
            if (!targetCat || selectedExtensions[targetCat].includes(added.identifier)) return;

            autoSelectedSet.add(added.identifier);

            // toggleExtension을 호출하여 selectedExtensions 배열 추가와 카드 UI
            // (selected 클래스 + extension-card-select 아이콘)을 한 번에 동기화.
            const isSingleSelect = (targetCat === 'admin_templates' || targetCat === 'user_templates');
            window.toggleExtension(targetCat, added.identifier, isSingleSelect, true);

            const owner = findExtensionById(added.required_by);
            applyAutoSelectedUI(added.identifier, (owner && getLocalizedText(owner.name)) || added.required_by);
        });

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
     * 식별자가 어떤 owner 의 연동으로 자동 체크된 상태인지 반환합니다.
     *
     * @param {string} identifier - 대상 식별자
     * @returns {boolean}
     */
    function isIntegrationAutoSelected(identifier) {
        return Object.keys(autoSelectedByIntegration).some(function(owner) {
            return autoSelectedByIntegration[owner].has(identifier);
        });
    }

    /**
     * 대상을 모든 owner 의 연동 자동 체크 기록에서 지우고 「연동」 배지를 뗍니다.
     *
     * @param {string} identifier - 대상 식별자
     */
    function forgetIntegrationAutoSelection(identifier) {
        Object.keys(autoSelectedByIntegration).forEach(function(owner) {
            autoSelectedByIntegration[owner].delete(identifier);
            if (autoSelectedByIntegration[owner].size === 0) delete autoSelectedByIntegration[owner];
        });
        removeIntegrationAutoUI(identifier);
    }

    /**
     * 사용자가 고른 owner 의 연동 대상(모듈·플러그인)을 한 번 자동 체크합니다.
     *
     * - 대상 유형이 템플릿이면 자동 체크하지 않는다(단일 선택이라 사용자가 고른 템플릿이 바뀐다 — 카드에 안내만).
     * - 번들 목록에 없거나, 이미 선택됐거나, 사용자가 전에 해제한 대상은 건드리지 않는다.
     * - 자동 체크한 대상의 dependencies 는 뒤이은 autoSelectMissingDependencies() 가 채우고,
     *   그 대상의 integrations 는 따라가지 않는다(toggleExtension 의 _isAutoSelect 경로).
     * - 선택 검증·해제 잠금(validateExtensionSelection·computeDependents)에는 관여하지 않는다.
     *
     * @param {string} ownerType - owner 카테고리 (admin_templates·user_templates·modules·plugins)
     * @param {string} ownerIdentifier - owner 식별자
     * @returns {Array<string>} 이번에 자동 체크한 대상 식별자
     */
    function autoSelectIntegrations(ownerType, ownerIdentifier) {
        if (!extensionsData) return [];
        const owner = (extensionsData[ownerType] || []).find(function(e) { return e.identifier === ownerIdentifier; });
        if (!owner) return [];

        const ownerName = getLocalizedText(owner.name) || ownerIdentifier;
        const picked = [];

        (owner.integrations_detailed || []).forEach(function(it) {
            if (!it || !it.id || it.id === ownerIdentifier) return;
            if (INTEGRATION_SELECTABLE_TYPES.indexOf(it.type) === -1) return;

            const targetCat = it.type;
            const exists = (extensionsData[targetCat] || []).some(function(e) { return e.identifier === it.id; });
            if (!exists) return;
            if (selectedExtensions[targetCat].indexOf(it.id) !== -1) return;
            if (userDeselectedIntegrations.has(it.id)) return;

            window.toggleExtension(targetCat, it.id, false, true);
            if (selectedExtensions[targetCat].indexOf(it.id) === -1) return; // 카드가 없어 선택되지 않음

            if (!autoSelectedByIntegration[ownerIdentifier]) {
                autoSelectedByIntegration[ownerIdentifier] = new Set();
            }
            autoSelectedByIntegration[ownerIdentifier].add(it.id);
            applyIntegrationAutoUI(targetCat, it.id, ownerName);
            picked.push(it.id);
        });

        return picked;
    }

    /**
     * 대상이 계속 선택돼 있을 근거(제외 집합 밖의 선택된 확장이 연동·의존으로 가리킴)를 찾습니다.
     *
     * 우선순위: 의존 > 사용자가 직접 고른 확장의 연동 > 자동 체크된 확장의 연동.
     *
     * @param {string} identifier - 대상 식별자
     * @param {Set<string>} excluded - 근거로 치지 않을 식별자 (해제되는 owner 등)
     * @param {Set<string>} [alsoAuto] - 자동 체크된 것으로 볼 식별자 (기록을 옮기는 중인 후보)
     * @returns {{kind: string, owner: object, ownerType: string}|null}
     */
    function findSelectionBasis(identifier, excluded, alsoAuto) {
        const categories = ['admin_templates', 'user_templates', 'modules', 'plugins'];
        let integrationBasis = null;
        let autoIntegrationBasis = null;
        for (let c = 0; c < categories.length; c++) {
            const cat = categories[c];
            const items = extensionsData[cat] || [];
            for (let i = 0; i < items.length; i++) {
                const item = items[i];
                if (item.identifier === identifier || excluded.has(item.identifier)) continue;
                if (selectedExtensions[cat].indexOf(item.identifier) === -1) continue;

                const depends = (item.dependencies_detailed || []).some(function(d) { return d && d.identifier === identifier; });
                if (depends) return { kind: 'dependency', owner: item, ownerType: cat };

                const integrates = (item.integrations_detailed || []).some(function(it) {
                    return it && it.id === identifier && INTEGRATION_SELECTABLE_TYPES.indexOf(it.type) !== -1;
                });
                if (!integrates) continue;
                const basis = { kind: 'integration', owner: item, ownerType: cat };
                if (isIntegrationAutoSelected(item.identifier) || autoSelectedSet.has(item.identifier) ||
                    (alsoAuto && alsoAuto.has(item.identifier))) {
                    if (!autoIntegrationBasis) autoIntegrationBasis = basis;
                } else if (!integrationBasis) {
                    integrationBasis = basis;
                }
            }
        }
        return integrationBasis || autoIntegrationBasis;
    }

    /**
     * owner 를 해제할 때 그 owner 가 연동으로 자동 체크한 항목 중 다른 선택 근거가 없는 것만 해제합니다.
     *
     * 다른 근거 = 여전히 선택된 다른 확장이 그 항목을 의존하거나 연동으로 선언함. 함께 해제되는 항목끼리는
     * 서로의 근거가 되지 않도록 고정점까지 반복한다. 남는 항목은 근거가 된 owner 로 기록을 옮긴다.
     * 해제한 항목이 의존성으로 자동 선택해 둔 확장도 clearAutoSelectedByOwner() 로 함께 정리한다.
     *
     * @param {string} ownerIdentifier - 해제되는 owner 식별자
     * @returns {Array<string>} 해제한 대상 식별자
     */
    function clearIntegrationAutoSelectedByOwner(ownerIdentifier) {
        if (!extensionsData || !autoSelectedByIntegration[ownerIdentifier]) return [];

        const candidates = Array.from(autoSelectedByIntegration[ownerIdentifier]);
        delete autoSelectedByIntegration[ownerIdentifier];

        const isSelected = function(id) {
            return INTEGRATION_SELECTABLE_TYPES.some(function(t) { return selectedExtensions[t].indexOf(id) !== -1; });
        };

        // 남길 후보를 고정점까지 넓힌다 — 근거는 「후보 밖 선택 확장」 또는 「이미 남기기로 한 후보」만 인정한다.
        // (게시판↔쇼핑몰처럼 서로를 연동으로 선언한 후보끼리 서로를 붙잡아 둘 다 남는 것을 막는다)
        const kept = new Set();
        let changed = true;
        while (changed) {
            changed = false;
            candidates.forEach(function(id) {
                if (kept.has(id) || !isSelected(id)) return;
                const excluded = new Set([ownerIdentifier]);
                candidates.forEach(function(c) { if (!kept.has(c)) excluded.add(c); });
                if (isIntegrationAutoSelected(id) || findSelectionBasis(id, excluded)) {
                    kept.add(id);
                    changed = true;
                }
            });
        }

        const released = candidates.filter(function(id) { return !kept.has(id) && isSelected(id); });
        const candidateSet = new Set(candidates);
        const removed = new Set([ownerIdentifier].concat(released));

        released.forEach(function(id) {
            removeIntegrationAutoUI(id);
            ['modules', 'plugins'].forEach(function(cat) {
                const idx = selectedExtensions[cat].indexOf(id);
                if (idx === -1) return;
                selectedExtensions[cat].splice(idx, 1);
                const card = document.querySelector('.extension-card[data-type="' + cat + '"][data-identifier="' + id + '"]');
                if (card) {
                    card.classList.remove('selected');
                    const selectEl = card.querySelector('.extension-card-select');
                    if (selectEl) selectEl.innerHTML = '<i class="far fa-square"></i>';
                }
                clearAutoSelectedByOwner(cat, id);
            });
            // 풀린 항목이 다른 항목의 연동 근거로 기록돼 있었다면(근거 이전) 그것도 같은 규칙으로 정리
            clearIntegrationAutoSelectedByOwner(id);
        });

        // 남은 후보 — 근거가 연동이면 그 owner 로 기록·배지를 옮기고, 의존이면 연동 표시만 뗀다
        candidates.forEach(function(id) {
            if (removed.has(id) || isIntegrationAutoSelected(id)) return;
            const basis = findSelectionBasis(id, removed, candidateSet);
            if (basis && basis.kind === 'integration') {
                if (!autoSelectedByIntegration[basis.owner.identifier]) {
                    autoSelectedByIntegration[basis.owner.identifier] = new Set();
                }
                autoSelectedByIntegration[basis.owner.identifier].add(id);
                removeIntegrationAutoUI(id);
                const cat = INTEGRATION_SELECTABLE_TYPES.find(function(t) { return selectedExtensions[t].indexOf(id) !== -1; });
                if (cat) applyIntegrationAutoUI(cat, id, getLocalizedText(basis.owner.name) || basis.owner.identifier);
            } else {
                removeIntegrationAutoUI(id);
            }
        });

        return released;
    }

    /**
     * 연동으로 자동 체크된 카드에 「연동」 배지와 CSS 클래스를 붙입니다(필수 의존성 배지와 다른 색).
     *
     * @param {string} type - 카드 카테고리 (modules·plugins)
     * @param {string} identifier - 대상 식별자
     * @param {string} ownerName - 연동을 선언한 확장 이름
     */
    function applyIntegrationAutoUI(type, identifier, ownerName) {
        const card = document.querySelector('.extension-card[data-type="' + type + '"][data-identifier="' + identifier + '"]');
        if (!card) return;
        card.classList.add('integration-auto-selected');

        const existing = card.querySelector('.integration-auto-badge');
        if (existing) existing.remove();

        const badge = document.createElement('span');
        badge.className = 'integration-auto-badge';
        badge.innerHTML = '<i class="fas fa-puzzle-piece"></i> ' +
            escapeHtml(window.EXTENSION_LABELS?.integration_auto_badge_label || lang('integration_auto_badge_label')) +
            ' <span class="integration-auto-badge-owner">(' + escapeHtml(ownerName) + ')</span>';
        const infoEl = card.querySelector('.extension-card-info');
        if (infoEl) infoEl.appendChild(badge);
    }

    /**
     * 「연동」 자동 체크 표시를 제거합니다.
     *
     * @param {string} identifier - 대상 식별자
     */
    function removeIntegrationAutoUI(identifier) {
        document.querySelectorAll('.extension-card[data-identifier="' + identifier + '"]').forEach(function(card) {
            card.classList.remove('integration-auto-selected');
            const badge = card.querySelector('.integration-auto-badge');
            if (badge) badge.remove();
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
        // 라이브 리전은 **비어 있는 상태로 먼저 붙이고** 내용을 채워야 읽힌다 — 내용을 담은
        // 채로 삽입하면 판독기가 이를 놓쳐, 선택 해제가 왜 막혔는지가 화면 밖에서는 전달되지
        // 않는다 (12차 #B34 형제 지점).
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        document.body.appendChild(toast);

        toast.innerHTML = '<i class="fas fa-lock" aria-hidden="true"></i> ' +
            escapeHtml(lang('dep_lock_message').replace(':names', dependentNames));

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
        // 판정식은 서버와 공유하는 해석기(installer-extension-resolver.js)가 소유한다.
        return window.G7ExtensionResolver.satisfiesVersionConstraint(available, constraint);
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
                headers: stateChangeHeaders(),
                body: JSON.stringify({
                    ...selectedExtensions,
                    extension_names: extensionNames
                })
            });
            const result = await response.json();
            if (!result.success) throw new Error(result.error_message || result.error || window.EXTENSION_LABELS?.save_failed || lang('save_failed'));
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
    // 중단·재시작 요청이 시작 경로의 await 구간을 가로질렀는지 알리는 표식.
    // 시작(startInstallation)은 SSE probe(최대 5초)와 install-process POST 를 기다리는데,
    // 그 동안에도 [중단] 버튼은 눌린다 — 확인하지 않으면 중단이 성공한 뒤 뒤늦게 도착한
    // 응답이 상태를 다시 running 으로 덮고 워커를 띄운다.
    let installationCancelRequested = false;
    let currentMonitor = null; // SSE/폴링/단계별 모니터 인스턴스
    let lastRollbackFailure = null;
    let currentInstallationMode = null; // 'sse' | 'polling' | 'stepwise' — 진행 중 모드 (pagehide 분기용)
    // 서버 진행 기록(installation.log) 중 이 창이 이미 그린 줄 수. 단계별 실행이 busy 로
    // 기다리는 동안 **다른 요청이** 쓴 줄은 이 창의 events 에 오지 않으므로, 이 값을 기준으로
    // 서버가 못 본 꼬리만 실어 준다. 이후에는 서버가 알려 주는 총 줄 수로 모니터가 다시 잡는다.
    let renderedLogLineCount = 0;
    let stepwiseToken = null; // 단계별 실행 토큰 (install-process.php 응답 또는 페이지 주입값)
    let lastErrorSkippable = false; // 마지막 실패 단계가 [건너뛰고 계속] 가능한지
    // 「진행 목록에 없는 단계」 경고를 낸 domId — 같은 단계에 한 번만 경고한다 (D2 L10).
    const warnedMissingTaskRows = new Set();
    // 오래 걸리는 태스크 안내를 이미 남긴 태스크명 — 태스크마다 한 번만 남긴다 (D2 L9).
    const noticedLongTasks = new Set();

    /* region:stepwise-token — 이 구간은 회귀 테스트가 소스에서 잘라 **실제로 실행**한다
       (`resources/js/installer/__tests__/installer-token-wiring.test.ts`). installer.js 는
       전역을 노출하지 않는 닫힌 IIFE 라, 프로덕션 표면을 늘리지 않으면서 행위를 잠그는
       유일한 방법이 이 표식이다. 표식을 지우면 그 테스트가 red 가 된다 (11차 #D1). */
    /**
     * 단계별 실행 토큰 — 메모리 > 페이지 주입(새로고침 재개) > sessionStorage 순
     */
    function getStepwiseToken() {
        if (stepwiseToken) return stepwiseToken;
        if (window.INSTALLER_STEPWISE_TOKEN) return window.INSTALLER_STEPWISE_TOKEN;
        try {
            return sessionStorage.getItem('g7_stepwise_token') || null;
        } catch (e) {
            return null;
        }
    }

    function rememberStepwiseToken(token) {
        stepwiseToken = token || null;
        try {
            if (token) sessionStorage.setItem('g7_stepwise_token', token);
            else sessionStorage.removeItem('g7_stepwise_token');
        } catch (e) { /* 저장 실패는 무시 — 페이지 주입값으로 복원된다 */ }
    }
    // 마지막 실패가 데이터베이스 작업 전 단계(환경 설정·의존성 사전 검증)에서 멈췄는지 — showError() 가 정한다.
    // 되돌아가기 확인 창이 이 값으로 DB 수동 정리 안내를 넣을지 정한다.
    let lastFailureBeforeDatabase = false;

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
        const currentLang = window.INSTALLER_LOCALE || window.installerLocale?.get() || 'ko';

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
     * @param {Object|null} [replace] - 자리표시자 치환값. 키가 자리표시자를 가지는데
     *   이 값을 넘기지 않으면 `:errors` 같은 토큰이 화면에 그대로 남는다 — 키를 우선
     *   번역하는 규칙 때문에 서버가 치환해 보낸 문장(fallback)은 쓰이지 않는다.
     * @returns {string} 번역된 메시지
     */
    function translateWithKey(messageKey, fallback = '', replace = null) {
        if (!messageKey) {
            return fallback;
        }
        return lang(messageKey, replace);
    }

    /**
     * Task ID를 번역된 이름으로 변환
     *
     * @param {string} taskId - 작업 ID (예: 'composer_check', 'env_create')
     * @returns {string} 번역된 작업 이름
     */
    function getTaskName(taskId) {
        // 확장 태스크는 "module_install:sirsoft-board" 합성 키로 전달된다 (state.failed_task 등).
        // 번역 키는 태스크 종류만으로 만들어지므로 대상 부분을 떼어낸다 — 떼지 않으면
        // lang() 이 미정의 키를 그대로 돌려줘 화면 제목에 원시 키가 노출된다.
        const bareId = taskId ? splitCompositeTaskKey(taskId)[0] : taskId;
        const translationKey = getTranslationKey('task', bareId);
        const translated = lang(translationKey);

        // 번역이 없으면 lang() 은 **키를 그대로** 돌려준다 — 그러면 화면에 `task_xxx` 라는
        // 원시 키가 노출된다. 서버측 형제(installerTaskKeyDisplayName)는 같은 상황에서 원 id 로
        // 내려가는데, 두 채널의 폴백이 갈라져 있으면 새 태스크가 추가될 때 JS 경로에서만
        // 접두사가 새어 나간다.
        return translated === translationKey ? (bareId || '') : translated;
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
     * 상태 변경 요청용 헤더 — 단계별 실행은 세션이 아니라 발급 토큰으로 진행한다 (R8).
     *
     * 중단·초기화·건너뛰기 모두 서버가 같은 게이트를 통과해야 하므로 한 지점이 헤더를 만든다.
     * 토큰이 없는 진행 모드(SSE·폴링)에서는 빈 값이 실리고 서버도 요구하지 않는다.
     *
     * @returns {Object} fetch headers
     */
    function stateMutationHeaders() {
        // 세션 CSRF 토큰(설치 소유 게이트)도 함께 싣는다 — 상태변경 헤더의 공통 부분은 stateChangeHeaders 가 소유한다.
        return { ...stateChangeHeaders(), 'X-Installer-Token': getStepwiseToken() || '' };
    }
    /* endregion:stepwise-token */

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
     * 이 상태를 **재개**로 이어야 하는가 (시작이 아니라) — 로드 복원과 해제 대기가 공유한다.
     *
     * 단계별 + `running` 은 시작 경로(`install-process.php`)가 **토큰 게이트에 403 으로 막힌다**
     * (10차가 건 게이트 · `installerStartRejection`). 그 상태의 정본 경로는 `run-task.php` 로
     * 이어가는 재개다.
     *
     * 규칙을 한 곳에 둔다 (D7). 사본을 두면 한쪽만 고쳐져 「로드하면 이어지는데 대기 뒤에는
     * 403 으로 죽는」 상태가 생긴다 — 실제로 D7 의 브라우저 실측이 그 모양을 잡아냈다.
     *
     * @param {Object|null} state 서버 상태
     * @returns {boolean} 재개 경로로 가야 하면 true
     */
    function stepwiseResumeApplies(state) {
        return !!state
            && state.status === 'running'
            && state.installation_mode === 'stepwise'
            && !!getStepwiseToken();
    }

    // 기존 DB 테이블 경고는 **인라인 카드**(아래 onDbBackupConsentChange)가 담당한다.
    // 종전의 모달 3함수(showExistingTablesModal / closeExistingTablesModal / confirmDropTables)는
    // 호출부가 저장소 어디에도 없었고, 그 안에서 갱신하려던 `.db-existing-tables-badge` 도
    // 존재하지 않아 호출돼도 도달 불가한 분기였다 — 살아 있는 기능처럼 보이던 사문을 걷어냈다.

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
     * 첫 연결은 1차 이벤트만 늦게 도착해 간격이 임계보다 짧아질 수 있다(실측 0.94s·2.23s — 1.29s).
     * 간격이 짧지만 두 이벤트가 함께 도착한 것은 아니면(버퍼 환경은 간격 ≈0) 한 번 더 잰다.
     *
     * @param {number} timeoutMs probe 응답 대기 timeout (default 5000 — sleep 2s + 여유)
     * @returns {Promise<boolean>} 호환 여부
     */
    async function detectSseCompatibility(timeoutMs = 5000) {
        const BUFFERED_GAP_MS = 300;
        const first = await probeSseStreamingOnce(timeoutMs);
        if (first.compatible) return true;
        if (first.gap === null || first.gap < BUFFERED_GAP_MS) return false;

        return (await probeSseStreamingOnce(timeoutMs)).compatible;
    }

    /**
     * sse-probe.php 에 한 번 연결해 두 probe 이벤트의 도착 간격을 잽니다.
     *
     * @param {number} timeoutMs probe 응답 대기 timeout
     * @returns {Promise<{compatible: boolean, gap: number|null}>} 호환 여부와 도착 간격(ms, 두 이벤트를 다 받지 못하면 null)
     */
    function probeSseStreamingOnce(timeoutMs) {
        return new Promise((resolve) => {
            const probeUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/sse-probe.php`;
            const STREAMING_THRESHOLD_MS = 1500;
            let settled = false;
            let source = null;
            let phase1ClientTs = null;

            // 타임아웃 타이머도 해제한다 (19차 J9) — `settled` 가 부작용을 막고 있어
            // 실동작 영향은 없지만, EventSource 는 `finish()` 가 닫는데 타이머만 남아
            // 「전부 대칭 해제」 규칙에서 빠져 있었다.
            let timeoutHandle = null;

            const finish = (compatible, gap = null) => {
                if (settled) return;
                settled = true;
                if (timeoutHandle) {
                    clearTimeout(timeoutHandle);
                    timeoutHandle = null;
                }
                if (source) {
                    try { source.close(); } catch (e) { /* ignore */ }
                }
                resolve({ compatible, gap });
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
                    finish(gap >= STREAMING_THRESHOLD_MS, gap);
                }
            });

            source.onerror = () => finish(false);

            timeoutHandle = setTimeout(() => finish(false), timeoutMs);
        });
    }

    /**
     * 시작 전 상태로 되돌린다 (19차 J4).
     *
     * `startInstallation()` 은 진입 즉시 `installationStarted = true` 로 세우고 시작 섹션을
     * 감춘다. 그 뒤의 세 await(SSE probe · install-process 응답 · stepwise 복원) 구간에서
     * [중단] 이 눌리면 종전에는 **UI 복원 없이 return** 했다. 그 상태에서 서버가
     * 「진행 중이 아니다」로 중단을 거부하면(probe 창은 install-process 호출 **전**이라
     * 실제로 그렇다) `installationCancelRequested` 와 `installationStarted` 가 둘 다 true 로
     * 남고, 화면에는 0% 카드와 살아 있는 [중단] 버튼만 있고 시작 버튼도 결과 섹션도 없다.
     *
     * `installationCancelRequested` 를 false 로 되돌리는 지점은 `startInstallation()` 진입부
     * 하나뿐인데 그 함수는 `installationStarted` 가 false 여야 들어갈 수 있으므로, 그 순환의
     * 바깥에 떨어진 상태에서는 **화면 안에 탈출 경로가 없다**. 새로고침만이 복구인데 그
     * 새로고침조차 이탈 경고에 걸린다.
     *
     * `env_required` 분기가 이미 같은 복원을 하고 있었다 — 한 곳만 옳은 상태였다.
     */
    function restoreInstallationStartState() {
        installationStarted = false;
        installationCancelRequested = false;
        hideAbortButton();

        const progressCard = document.getElementById('installation-progress-card');
        if (progressCard) progressCard.style.display = 'none';
        const warning = document.getElementById('install-warning');
        if (warning) warning.style.display = 'none';

        showInstallationStartSection();
    }

    /**
     * 설치 시작 (SSE / 폴링 듀얼 모드)
     */
    async function startInstallation(modeOverride = null) {
        if (installationStarted) return;
        installationStarted = true;
        installationCancelRequested = false;

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
            } else if (radioMode === 'stepwise') {
                // 단계별 실행 — SSE probe 불필요 (요청 1개 = 태스크 1개)
                installationMode = 'stepwise';
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

                // probe 는 최대 5초를 기다린다 — 그 사이의 [중단] 을 여기서 확인한다
                if (installationCancelRequested) {
                    restoreInstallationStartState();

                    return;
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
            const dropConfirm = existingDbAction === 'drop_tables'
                ? (sessionStorage.getItem('drop_confirm') || '')
                : '';

            const response = await fetch(saveConfigUrl, {
                method: 'POST',
                headers: stateChangeHeaders(),
                body: JSON.stringify({
                    config: configData,
                    installation_mode: installationMode,
                    existing_db_action: existingDbAction,
                    drop_confirm: dropConfirm,
                }),
            });

            const result = await response.json();

            // 이 응답을 기다리는 동안 [중단] 이 눌렸을 수 있다 — 확인하지 않으면 화면은
            // 중단인데 서버는 설치 중인 상태가 된다.
            if (installationCancelRequested) {
                restoreInstallationStartState();

                return;
            }

            // 삭제 확인이 거부돼 삭제 없이 진행되는 경우 — 사용자에게 알린다
            if (result.success && result.drop_confirm_rejected && result.warning) {
                addLogMessage(result.warning);
            }

            if (!result.success) {
                if (result.env_required) {
                    installationStarted = false;
                    hideAbortButton();
                    // 진행 카드를 이미 켜 둔 뒤에 .env 부재를 알게 된다 — 되돌리지 않으면
                    // 안내 아래에 0% 진행률과 대기 목록이 그대로 남는다.
                    const envProgressCard = document.getElementById('installation-progress-card');
                    if (envProgressCard) envProgressCard.style.display = 'none';
                    const envWarning = document.getElementById('install-warning');
                    if (envWarning) envWarning.style.display = 'none';
                    if (result.env_not_writable) {
                        // 서버 게이트의 「쓰기 불가」 거부 (22-1 서버 절반) — 부재 안내로 그리면
                        // 파일명·명령·사유가 전부 빈 패널이 된다 (23차 F-2). 사전 점검과 같은 렌더.
                        renderEnvNotWritableSetup(result.base_path || null, result.is_windows || false);
                    } else {
                        // 사유 문단도 「부재」로 되돌린다 (24차 낮음 #1) — 같은 세션에서 쓰기 불가
                        // 안내를 그린 뒤 부재로 바뀌면 문단만 옛 사유로 남는다. 사전 점검과 대칭.
                        setEnvSetupReason(lang('file_setup_description'));
                        showFileSetupSection(result.missing_files || [], null, null, result.base_path, result.is_windows || false);
                    }
                    return;
                }
                // 점유가 아직 예산 안이라 거절된 경우 (D7) — 막다른 오류로 그리지 않는다.
                // 서버가 남은 시간을 주면 그만큼 세었다가 한 번 스스로 이어간다.
                const retryAfter = Number(result.retry_after);
                if (Number.isFinite(retryAfter) && retryAfter > 0) {
                    startLockWait(retryAfter, result.message);

                    return;
                }
                showError(result.message || lang('installation_start_failed'));
                return;
            }

            // 서버가 진행 방식을 확정한다 — 외부 명령이 막힌 환경이면 단계별 실행으로 강제되므로
            // 응답값을 SSoT 로 삼는다 (R1: 진입 1회 결정)
            if (result.installation_mode && ['sse', 'polling', 'stepwise'].includes(result.installation_mode)) {
                installationMode = result.installation_mode;
                const reflectedRadio = document.querySelector(`input[name="installation_mode"][value="${installationMode}"]`);
                if (reflectedRadio) reflectedRadio.checked = true;
                // 강제 전환(→ 단계별 실행)은 서버가 이미 진행 기록에 남겼고, 그 모드는
                // 새로고침 재개 시 displayLogs(state.logs) 로 서버 줄을 다시 그린다 —
                // 화면이 같은 문장을 또 쓰면 두 번 보인다. 그래서 서버 기록으로 일원화한다
                // (13차 F5). 잠금 사유 자체는 진행 방식 카드가 서버 렌더로 이미 안내한다.
                //
                // 반대로 강등(단계별 실행 → 실시간 스트리밍)은 SSE·폴링으로 끝나는데, 그 두
                // 모드는 서버가 시작 전에 남긴 기록을 재생하지 않는다 — 화면이 쓰지 않으면
                // 사용자가 자신이 고른 방식이 바뀐 사실을 알 방법이 없다 (13차 F4).
                if (result.installation_mode_downgraded) {
                    addLogMessage(lang('log_installation_mode_downgraded_sse'));
                }
            }
            currentInstallationMode = installationMode;

            // 공통 모니터 콜백 정의
            const stateUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/state-management.php?action=get`;

            if (installationMode === 'stepwise') {
                rememberStepwiseToken(result.stepwise_token || null);
                addLogMessage(lang('stepwise_resume_notice'));

                // 단계별 실행은 미완료 태스크만 돌리므로 SSE/폴링처럼 "이미 완료됨" 이벤트가
                // 재생되지 않는다 — 재시도/재개 시 이전에 끝난 단계는 서버 상태에서 복원한다
                try {
                    const snapshot = await (await fetch(stateUrl, { cache: 'no-store' })).json();
                    const snapshotLists = stateTaskLists(snapshot);
                    displayCompletedTasks(snapshotLists.completed, snapshotLists.skipped, snapshotLists.authoritative);
                } catch (e) {
                    // 복원 실패는 진행을 막지 않는다 — 새로고침하면 다시 복원된다
                }
            }

            // await 뒤 취소 재확인 — 이 함수의 앞선 두 await(probe · install-process 응답) 에는
            // 이미 있었는데 stepwise 복원 스냅샷 뒤에만 없었다. 그 창에서 [중단] 을 누르면
            // 중단 화면이 뜬 뒤에도 모니터가 살아 run-task.php 를 계속 호출한다 —
            // 이 시점의 `currentMonitor` 는 아직 null 이라 중단이 멈출 대상도 없다 (10차).
            if (installationCancelRequested) {
                restoreInstallationStartState();

                return;
            }

            const callbacks = buildMonitorCallbacks(stateUrl);

            startMonitorForMode(installationMode, callbacks, stateUrl);
        } catch (error) {
            // 개발자 표기(브라우저 원문)는 제목이 아니라 진단 상세에 싣는다 (§12.0 W3) —
            // 모니터 쪽은 이미 그 규약을 따른다.
            showError(lang('installation_start_error'), error && error.message ? String(error.message) : null);
        }
    }

    /**
     * 세 진행 모드가 공유하는 모니터 콜백 (UI 갱신 로직은 모드와 무관하다)
     *
     * @param {string} stateUrl 상태 조회 URL
     * @returns {Object} 콜백 집합
     */
    function buildMonitorCallbacks(stateUrl) {
        return {
                onConnected: () => {},
                onTaskStart: (taskId, name, target) => {
                    updateTaskStatus(taskId, 'active', name, target || null);
                },
                onTaskComplete: (taskId, message, target, skipped) => {
                    updateTaskStatus(taskId, skipped ? 'skipped' : 'completed', message, target || null);
                },
                // 한 줄씩 오는 실시간 경로(SSE)는 프레임 단위로 모아 붙인다 — 수천 줄이 한꺼번에
                // 도착해도 줄마다 강제 리플로우를 하지 않는다.
                onLog: (message) => {
                    queueLogMessage(message);
                },
                // 단계별 실행은 한 응답이 태스크 전체의 줄을 싣고 온다 — 배치로 받아 한 번에
                // 그린다 (D2 L4). 이 콜백이 없으면 모니터가 줄마다 `onLog` 로 떨어뜨리고,
                // 그 순간 화면이 줄 수만큼 강제 리플로우를 한다.
                onLogBatch: (messages) => {
                    addLogMessages(messages);
                },
                // 요청이 도는 동안의 경과 표시 (D2 L9) — DOM 텍스트 한 곳만 만진다.
                // 실측에서 44 초짜리 태스크 구간에 화면이 한 글자도 바뀌지 않았고,
                // 사용자에게 그것은 「멈춘 화면」이다.
                // 단계별 실행 전용 — 요청을 보내기 전에 이번 요청이 돌릴 단계(이미 진행 중인 행, 없으면
                // 작업 목록의 첫 대기 행. 목록은 실제 설치 순서다)를 진행 중으로 그리고 그 이름을 경과 표시에 쓴다.
                resolveNextTask: () => {
                    const row = requestTaskRow();
                    if (!row) return null;
                    if (!row.alreadyActive) {
                        updateTaskStatus(row.taskId, 'active', row.name, row.target);
                    }
                    return row.name;
                },
                onProgressTick: (elapsedSec, taskName) => {
                    const el = document.getElementById('task-elapsed');
                    if (!el) return;
                    el.textContent = lang('stepwise_task_elapsed', {
                        name: taskName || lang('stepwise_task_unnamed'),
                        elapsed: String(elapsedSec),
                    });
                    el.style.display = '';
                },
                // 문턱(60초)을 넘긴 태스크는 진행 기록에 **한 번만** 남긴다 (D2 L9).
                onLongTask: (elapsedSec, taskName) => {
                    const key = taskName || '(unnamed)';
                    if (noticedLongTasks.has(key)) return;
                    noticedLongTasks.add(key);
                    addLogMessage(lang('stepwise_long_task_notice', {
                        name: taskName || lang('stepwise_task_unnamed'),
                        elapsed: String(elapsedSec),
                    }));
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
                onHeartbeat: (progress) => {
                    // 한 단계 안의 부분 진행 (번들 추출 묶음 수) — 단계 진행률은 움직이지 않는다.
                    setCurrentTaskProgress(progress && progress.done, progress && progress.total);
                },
                onRollbackFailed: (data) => {
                    // SSE 는 서버가 번역한 문자열을, 폴링·단계별은 state 의 다국어 키를 싣는다 —
                    // 어느 쪽이 와도 같은 문장이 나오도록 키를 우선 번역한다(onError 와 같은 방식).
                    lastRollbackFailure = {
                        message: translateWithKey(
                            data && data.message_key,
                            (data && data.message) || lang('failed_rollback_manual_cleanup')
                        ),
                        detail: translateWithKey(
                            data && data.detail_key,
                            (data && data.detail) || lang('failed_rollback_manual_cleanup_detail')
                        ),
                    };
                },
                onError: (data) => {
                    const errorMessage = translateWithKey(
                        data.message_key,
                        data.message || lang('installation_error_occurred'),
                        data.message_params || null
                    );
                    // 정지(stuck)는 「태스크가 실패했다」가 아니라 「응답이 끊겼다」다 — 그 구분을
                    // 버리면 [다시 시도] 가 무엇을 다시 하는 것인지 화면에서 알 수 없다.
                    // 모니터 셋이 같은 플래그를 실어 보내므로 여기 한 곳에서 읽는다.
                    const errorDetail = data.stuck
                        ? [data.error, lang('error_installation_stuck_detail')].filter(Boolean).join(String.fromCharCode(10))
                        : (data.error || null);
                    // 서버는 실패를 `task`(순수 id) + `target`(확장 식별자)로 나눠 보낸다.
                    // `target` 을 버리면 SSE·단계별 실행에서만 「모듈 설치」로 뭉뚱그려져,
                    // 폴링·새로고침(합성키 state.failed_task 를 그대로 쓴다)과 달리 **어느
                    // 확장이 실패했는지 알 수 없게 된다**. setTaskStatus 는 합성키를 그대로
                    // 받으므로 여기서 다시 이어 붙인다 (13차 E7).
                    const failedTaskId = data.task
                        ? (data.target ? `${data.task}:${data.target}` : data.task)
                        : null;
                    showError(errorMessage, errorDetail, failedTaskId, !!data.skippable, data.manual_commands, data.message_key || null);
                },
                onConnectionTimeout: () => {
                    // SSE 모드에서 연결 timeout 시 — 사전 체크 통과 후의 예외 케이스라
                    // 자동 폴링 fallback 대신 사용자에게 명시적 에러 노출. 사용자가 페이지를
                    // 새로고침하면 sse-probe 재실행으로 모드 재결정.
                    if (installationCompleted) return;
                    showError(lang('sse_connection_timeout'), lang('sse_server_config_guide'));

                    // 서버는 아직 돌고 있다 (19차 J7).
                    //
                    // 이 지점은 `install-process.php` POST 가 **이미 성공해** 워커가 떠 있는
                    // 뒤다 — 붙지 못한 것은 스트림뿐이다. 그런데 `showError()` 는 사유를 가리지
                    // 않고 `installationCompleted = true` 로 못박아 `beforeunload` 경고와
                    // `pagehide` 의 중단 비콘을 함께 끈다. 그러면 운영자가 탭을 닫아도 서버에
                    // 중단 신호가 가지 않아 워커가 계속 DB 를 건드린다.
                    //
                    // 「화면이 실패를 그렸다」와 「서버가 멈췄다」는 다른 사실이므로, 이 갈래는
                    // 표식을 되돌려 이탈 경고와 비콘을 살려 둔다.
                    installationCompleted = false;
                },
                // 단계별 실행 전용 — 다른 요청이 태스크를 돌리는 중 / 응답 없음 재시도
                onBusy: (message) => {
                    addLogMessage(message || lang('stepwise_waiting_busy'));
                },
                onRetry: (attempt, max) => {
                    addLogMessage(lang('stepwise_request_retry').replace(':attempt', attempt).replace(':max', max));
                },
                onState: (state) => {
                    reconcileTaskListWithState(state);
                },
            };
    }

    /**
     * 서버 상태에서 "지나간 작업" 목록을 만든다 — 완료 + 운영자가 건너뛴 것 + 실패했지만 필수가 아닌 것.
     *
     * 실패한 best-effort 작업(언어팩·설정 캐시)은 completed 에 없지만 진행은 지나갔다 — 완료 목록에
     * 넣어 진행률에 세고, 건너뜀 목록에도 넣어 경고 아이콘으로 구분한다.
     *
     * @param {Object} state state-management / run-task 의 상태 요약
     * @returns {{completed: string[], skipped: string[]}}
     */
    function stateTaskLists(state) {
        const completed = Array.isArray(state.completed_tasks) ? state.completed_tasks.slice() : [];
        const skipped = Array.isArray(state.skipped_tasks) ? state.skipped_tasks.slice() : [];
        const bestEffortFailed = Array.isArray(state.best_effort_failed_tasks) ? state.best_effort_failed_tasks : [];
        bestEffortFailed.forEach((key) => {
            if (!completed.includes(key)) completed.push(key);
            if (!skipped.includes(key)) skipped.push(key);
        });
        // 「목록 부재」와 「목록 공집합」의 구분 (23차 F-3) — 인코딩 실패 폴백 응답은 목록
        // 없이 오는데, 그것을 공집합으로 접으면 22-4 의 되돌림이 화면의 모든 완료 표시를
        // 지우는 파괴적 연산이 된다. 되돌림은 authoritative(실제 배열이 실린 응답)에서만.
        return { completed, skipped, authoritative: Array.isArray(state.completed_tasks) };
    }

    /**
     * 서버 상태 요약으로 작업 목록을 맞춘다 (단계별 실행).
     *
     * 새로고침 시점에 서버에서 돌고 있던 태스크는 끊긴 연결 쪽으로 완료 이벤트를 보내고 끝나므로,
     * 재개한 화면은 그 태스크를 영원히 "대기" 로 보여 주고 진행률도 하나 모자라게 된다
     * (P0 실측: composer_install · module_activate). 서버가 응답마다 싣는 completed/skipped 가
     * 단일 진실이므로 이벤트 재생 뒤 그 목록으로 보정한다. 다른 요청이 돌리는 중(busy)인 태스크는
     * 진행 중으로 표시해 기다리는 동안 무엇을 기다리는지 보이게 한다.
     *
     * @param {Object} state run-task.php 응답의 state 요약
     */
    function reconcileTaskListWithState(state) {
        if (!state || typeof state !== 'object') return;

        const lists = stateTaskLists(state);
        const completed = lists.completed;
        displayCompletedTasks(lists.completed, lists.skipped, lists.authoritative);

        if (state.status === 'running' && state.current_task && !completed.includes(state.current_task)) {
            const [taskId, target] = splitCompositeTaskKey(state.current_task);
            const domId = target ? `task-${taskId}-${target}` : `task-${taskId}`;
            const el = document.getElementById(domId);
            if (el && !el.classList.contains('task-active') && !el.classList.contains('task-failed')) {
                updateTaskStatus(taskId, 'active', null, target);
            }
        }
    }

    /**
     * 세 모니터가 공유하는 정지 감지 창(ms).
     *
     * 근거는 **서버가 자기 요청에 주는 창**이다. 서브프로세스를 스폰하는 태스크(migrate ·
     * db:seed · module:install)는 그 동안 아무 이벤트도 내보내지 않고 출력을 파일로 받는다 —
     * 서버는 그 구간을 위해 자기 수명과 lock 을 600초로 올린다. 화면 창이 그보다 짧으면
     * **서버가 정상 동작 중인데** 화면만 「응답이 없습니다」로 끝난다. 종전에는 단계별 실행만
     * 660초를 쓰고 SSE·폴링은 90초여서, 같은 설치가 모드에 따라 성공/정지로 갈렸다 (13차 E1).
     *
     * 서버 창 + 여유(60초)를 쓴다. 주입값이 없으면(구형 캐시 페이지) 종전 단계별 실행 값과
     * 같은 660초로 떨어진다.
     *
     * @returns {number} 정지로 판정하기까지의 침묵 허용 시간(ms)
     */
    function monitorStuckThresholdMs() {
        const serverWindowMs = Number(window.INSTALLER_SERVER_BLOCKING_WINDOW_MS);
        const base = Number.isFinite(serverWindowMs) && serverWindowMs > 0 ? serverWindowMs : 600000;

        return base + 60000;
    }

    /**
     * 진행 모드에 맞는 모니터를 만들어 시작한다.
     *
     * @param {string} installationMode 'sse' | 'polling' | 'stepwise'
     * @param {Object} callbacks buildMonitorCallbacks() 결과
     * @param {string} stateUrl 상태 조회 URL
     */
    function startMonitorForMode(installationMode, callbacks, stateUrl) {
        const MonitorNS = window.G7InstallationMonitor;
        if (!MonitorNS) {
            showError(lang('error_monitor_script_not_loaded'));
            return;
        }

        const stuckThresholdMs = monitorStuckThresholdMs();

        if (installationMode === 'stepwise') {
            const runTaskUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/run-task.php`;
            currentMonitor = new MonitorNS.StepwiseMonitor(callbacks, {
                runTaskUrl: runTaskUrl,
                stateUrl: stateUrl,
                token: getStepwiseToken() || '',
                stuckThresholdMs: stuckThresholdMs,
                // 이 창이 이미 그린 진행 기록 줄 수 — busy 로 기다리는 동안 다른 요청이 쓴
                // 줄만 서버가 실어 준다. 재개 시 displayLogs 로 그린 수가 그대로 기준이다.
                logOffset: renderedLogLineCount,
            });
        } else if (installationMode === 'polling') {
            currentMonitor = new MonitorNS.PollingMonitor(callbacks, {
                stateUrl: stateUrl,
                intervalMs: 1000,
                stuckThresholdMs: stuckThresholdMs,
            });
        } else {
            const workerUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/install-worker.php`;
            currentMonitor = new MonitorNS.SseMonitor(callbacks, {
                workerUrl: workerUrl,
                // 다른 요청이 워커 lock 을 가져가면 재연결이 아니라 이 주소로 갈아탄다 (13차 E5).
                // 연결 수립 뒤 스트림이 끊기면 이 주소로 결말(실패·중단·완료·멈춤)을 판정하고,
                // 워커 없이 설치가 남았으면 새 워커로 다시 연결해 이어 받는다.
                stateUrl: stateUrl,
                connectionTimeoutMs: 5000,
                stuckThresholdMs: stuckThresholdMs,
            });
        }

        // 새 설치 진행(시작·재시도·재개) — 이전 실패 판정은 더 쓰지 않는다
        lastFailureBeforeDatabase = false;
        currentMonitor.start();
    }

    /**
     * 새로고침 뒤 단계별 실행 이어가기 — 서버 상태를 화면에 복원하고 다음 태스크부터 호출한다.
     *
     * @param {Object} state state-management.php?action=get 응답
     */
    function resumeStepwiseInstallation(state) {
        installationStarted = true;
        installationCompleted = false;
        currentInstallationMode = 'stepwise';

        hideInstallationStartSection();
        showInstallationProgressSection();
        showAbortButton();

        renderTaskList();
        const resumeLists = stateTaskLists(state);
        displayCompletedTasks(resumeLists.completed, resumeLists.skipped, resumeLists.authoritative);
        displayLogs(state.logs || []);
        addLogMessage('--- ' + lang('installation_resuming') + ' ---');

        const stateUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/state-management.php?action=get`;
        startMonitorForMode('stepwise', buildMonitorCallbacks(stateUrl), stateUrl);
    }

    /**
     * [건너뛰고 계속] — 실패한 선택 확장 단계를 건너뛰고 다음 단계부터 재개한다 (W3).
     */
    async function skipFailedTaskAndContinue() {
        if (!confirm(lang('skip_task_confirm'))) return;

        const btn = document.getElementById('skip-task-btn');
        if (btn) btn.disabled = true;

        try {
            const response = await fetch(getStateApiUrl('skip_task'), {
                method: 'POST',
                // 헤더는 한 지점이 만든다 — 사본을 두면 그 사본만 규칙에서 뒤처진다 (11차 #D1).
                headers: stateMutationHeaders(),
                body: JSON.stringify({ token: getStepwiseToken() || '' }),
            });
            const result = await response.json();

            if (!result.success) {
                alert(result.message || lang('unknown_error_occurred'));
                if (btn) btn.disabled = false;
                return;
            }

            (result.skipped || []).forEach((key) => {
                const [taskId, target] = splitCompositeTaskKey(key);
                setTaskStatus(taskId, 'skipped', target);
            });
            addLogMessage(result.message || lang('skip_task_done'));
            continueInstallation();
        } catch (error) {
            // 개발자 표기는 제목이 아니라 진단 상세에 싣는다 (§12.0 W3 · 10차 #M5).
            // 비-JSON 500(HTML 오류 페이지)이 돌아오면 종전에는 `Unexpected token '<' ...`
            // 가 모달 제목 자리에 그대로 떴다 (11차 #B17).
            showError(lang('unknown_error_occurred'), error && error.message ? String(error.message) : null);
            if (btn) btn.disabled = false;
        }
    }

    /* region:ui-state — 이 구간은 회귀 테스트가 소스에서 잘라 **실제로 실행**한다
       (`resources/js/installer/__tests__/installer-ui-state.test.ts`). installer.js 는 전역을
       노출하지 않는 닫힌 IIFE 라, 프로덕션 표면을 늘리지 않으면서 행위를 잠그는 유일한 방법이
       이 표식이다. 표식을 지우면 그 테스트가 red 가 된다 (12차 #B24·#B25·#B26·#B28·#B35). */

    /**
     * 작업 그룹의 접힘 상태를 바꾼다 (클래스 + 트리거의 `aria-expanded` 를 함께).
     *
     * 접힘은 두 경로로 일어난다 — 사람이 헤더를 누르는 경로와 그룹이 완료돼 **자동으로** 접히는
     * 경로다. 종전에는 수동 경로만 `aria-expanded` 를 갱신해, 자동으로 접힌 그룹이 보조기술에는
     * 계속 「펼쳐짐」으로 남았다(그 안의 단계는 화면에서 사라진 뒤다). 두 경로를 이 한 지점으로
     * 모아야 다음에 새 전이가 생겨도 같은 사각이 다시 열리지 않는다 (12차 #B25).
     *
     * @param {HTMLElement|null} group `.task-group` 요소
     * @param {boolean} collapsed 접을지 여부
     */
    function setTaskGroupCollapsed(group, collapsed) {
        if (!group) return;

        group.classList.toggle('collapsed', !!collapsed);

        const header = group.querySelector('.task-group-header');
        if (header) {
            header.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    }

    /**
     * 설치 진행 카드의 접힘 상태를 바꾼다 (본문 표시 + 아이콘 + `aria-expanded`).
     *
     * 그룹 아코디언과 같은 이유로 단일 지점이다 — 완료·실패·중단·초기화가 각자 본문을 열고
     * 닫는데, 그중 `aria-expanded` 를 따라 움직이는 것은 수동 토글 하나뿐이었다 (12차 #B26).
     *
     * @param {boolean} collapsed 접을지 여부
     */
    function setInstallationCardCollapsed(collapsed) {
        const body = document.getElementById('installation-card-body');
        const icon = document.getElementById('install-toggle-icon');
        const header = document.getElementById('installation-card-header');

        if (body) {
            body.style.display = collapsed ? 'none' : 'block';
        }
        if (icon) {
            icon.textContent = collapsed ? '▶' : '▼';
        }
        if (header) {
            header.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    }

    /**
     * 설치 진행 카드 헤더를 누를 수 있는 상태로 바꾼다.
     *
     * 헤더는 `role="button"` 이지만 설치가 끝나기 전에는 핸들러가 첫 줄에서 되돌아간다. 그
     * 사이 헤더는 Tab 순서에 남아 있으면서 눌러도 아무 일이 없는 「죽은 버튼」이었다 — 비활성
     * 이라는 사실이 보조기술에도 키보드에도 전달되지 않는다 (12차 #B28).
     *
     * @param {boolean} enabled 조작 가능 여부
     */
    function setInstallationCardInteractive(enabled) {
        const header = document.getElementById('installation-card-header');
        if (!header) return;

        header.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        header.setAttribute('tabindex', enabled ? '0' : '-1');
        header.style.cursor = enabled ? 'pointer' : 'default';
    }

    /** 현재 작업 줄의 본문 (단계 이름) */
    let currentTaskBaseLabel = '';

    /** 현재 작업 줄에 덧붙는 부분 진행 표시 ("7/25") — 단계가 바뀌면 비운다 */
    let currentTaskProgressSuffix = '';

    /**
     * 현재 작업 줄을 다시 그린다 (단계 이름 + 부분 진행).
     */
    function renderCurrentTaskLabel() {
        const el = document.getElementById('current-task');
        if (!el) return;

        el.textContent = currentTaskProgressSuffix
            ? `${currentTaskBaseLabel} (${currentTaskProgressSuffix})`
            : currentTaskBaseLabel;
    }

    /**
     * 현재 작업 줄의 본문을 바꾼다 (부분 진행 표시는 초기화한다).
     *
     * @param {string} label 단계 이름
     */
    function setCurrentTaskLabel(label) {
        currentTaskBaseLabel = label || '';
        currentTaskProgressSuffix = '';
        renderCurrentTaskLabel();
    }

    /**
     * 한 단계 **안**의 부분 진행을 덧붙인다 (번들 추출 묶음 수).
     *
     * 서버는 이 값을 heartbeat 로 계속 보내는데 소비처가 없어 통째로 버려지고 있었다 —
     * 10~15초 이상 걸리는 최장 단계가 화면에서는 정지한 스피너였다 (12차 #B35). 숫자만
     * 덧붙이므로 새 번역 문구가 필요 없다(그룹 배지의 `n/m` 과 같은 표기다).
     *
     * @param {number} done 끝난 묶음 수
     * @param {number} total 전체 묶음 수
     */
    function setCurrentTaskProgress(done, total) {
        if (typeof done !== 'number' || typeof total !== 'number' || total <= 0) {
            return;
        }

        currentTaskProgressSuffix = `${done}/${total}`;
        renderCurrentTaskLabel();
    }

    /**
     * 작업 목록에서 아직 시작하지 않은 첫 단계 행을 돌려준다(목록은 실제 설치 순서로 그려진다).
     * @returns {{taskId: string, target: string|null, name: string}|null} 단계 행 정보
     */
    function firstPendingTaskRow() {
        const row = document.querySelector('#task-list .task-item.task-pending');
        if (!row) return null;
        const nameEl = row.querySelector('.task-name');

        return {
            taskId: row.getAttribute('data-task-id') || '',
            target: row.getAttribute('data-target') || null,
            name: nameEl ? nameEl.textContent.trim() : '',
        };
    }

    /**
     * 이번 단계별 요청이 돌릴 단계 행을 고른다.
     * 이미 진행 중인 행이 있으면 그 행이다 — busy 대기·재시도로 요청이 되풀이될 때마다 다음 대기 행을
     * 새로 칠하면, 칠한 행이 되돌려지지 않은 채 남은 단계가 하나씩 진행 중으로 바뀐다. 없으면 첫 대기 행이다.
     * @returns {{taskId: string, target: string|null, name: string, alreadyActive: boolean}|null} 단계 행 정보
     */
    function requestTaskRow() {
        const active = document.querySelector('#task-list .task-item.task-active');
        if (active) {
            const nameEl = active.querySelector('.task-name');

            return {
                taskId: active.getAttribute('data-task-id') || '',
                target: active.getAttribute('data-target') || null,
                name: nameEl ? nameEl.textContent.trim() : '',
                alreadyActive: true,
            };
        }
        const row = firstPendingTaskRow();

        return row ? { ...row, alreadyActive: false } : null;
    }

    /**
     * 현재 작업 줄에 쓸 이름을 만든다.
     * 서버 단계 이름(`task_start` 의 name)은 확장 단계에서 이미 확장 이름과 식별자를 담아 온다
     * (「게시판 모듈 활성화 (sirsoft-board)」). 그 위에 다시 붙이면 이름과 식별자가 두 번 나온다.
     * 서버 이름이 없거나 대상을 담지 않은 경로(재개 등)만 확장 이름과 식별자를 한 번 붙인다.
     * @param {string|null} serverName 서버가 보낸 단계 이름
     * @param {string} fallbackName taskId 로 도출한 단계 이름
     * @param {string|null} target 확장 식별자
     * @param {string} extName 확장 이름 (모르면 빈 문자열)
     * @returns {string} 현재 작업 줄 이름
     */
    function composeCurrentTaskName(serverName, fallbackName, target, extName) {
        const taskName = serverName || fallbackName;
        if (!target || (serverName && serverName.includes(`(${target})`))) {
            return taskName;
        }

        return extName ? `${extName} ${taskName} (${target})` : `${taskName} (${target})`;
    }

    /* endregion:ui-state */

    /**
     * 작업 상태 업데이트
     * @param {string} taskId 작업 ID
     * @param {string} status 상태
     * @param {string|null} message 서버가 보낸 단계 이름 (`task_start` 의 name). 같은
     *   taskId 라도 실제로 도는 일이 다를 수 있어(번들 설치에서는 composer_install 자리에
     *   vendor 번들 추출이 돈다) 서버 이름이 있으면 그것이 정답이다.
     * @param {string|null} target 확장 식별자 (옵션)
     */
    function updateTaskStatus(taskId, status, message, target = null) {
        // 통합 함수 사용 (target 전달)
        setTaskStatus(taskId, status, target);

        if (status === 'active') {
            // 현재 작업명 업데이트
            // 서버가 보낸 이름을 우선한다 — taskId 로 되도출하면 번들 설치에서
            // 「Vendor 번들 추출」이 「필수 패키지 설치」로 표시된다(실제로 도는 일과
            // 화면 문구가 어긋난다). 서버가 이름을 싣지 않는 재개 경로는 종전대로 도출.
            // target이 있으면 확장 이름과 조합 (예: "게시판 모듈 설치 (sirsoft-board)")
            const taskName = composeCurrentTaskName(
                message,
                getTaskName(taskId),
                target,
                target ? getStoredExtensionName(target) : '',
            );
            // 단계가 바뀌면 앞 단계의 부분 진행 표시는 함께 버린다.
            setCurrentTaskLabel(taskName || message || '');
        } else if (status === 'completed' || status === 'skipped') {
            // 진행률 업데이트 (건너뛴 단계도 지나간 단계다)
            updateProgressBar();
        }
    }

    /**
     * 진행률 바 업데이트
     */
    function updateProgressBar() {
        const completedTasks = document.querySelectorAll('.task-item.task-completed, .task-item.task-skipped').length;
        const totalTasks = document.querySelectorAll('.task-item').length || INSTALLATION_TASKS.length;
        const percentage = totalTasks > 0 ? Math.round((completedTasks / totalTasks) * 100) : 0;

        const progressBar = document.getElementById('overall-progress-bar');
        const progressPercentage = document.getElementById('overall-percentage');
        const progressSteps = document.getElementById('overall-steps');

        if (progressBar) {
            progressBar.style.width = percentage + '%';
        }
        // progressbar 시맨틱은 값이 따라와야 의미가 있다 — 폭만 바꾸면 화면 판독기에는 0% 로 남는다.
        const progressTrack = document.getElementById('overall-progress-track');
        if (progressTrack) {
            progressTrack.setAttribute('aria-valuenow', String(percentage));
        }
        if (progressPercentage) {
            progressPercentage.textContent = percentage + '%';
        }
        // 단계별 실행은 "n / 전체 단계" 를 함께 보여 준다 (W3) — 각 단계가 요청 하나라 단계 수가 곧 진행의 단위다
        if (progressSteps) {
            if (currentInstallationMode === 'stepwise' && totalTasks > 0) {
                progressSteps.textContent = lang('stepwise_step_progress')
                    .replace(':done', completedTasks)
                    .replace(':total', totalTasks);
                progressSteps.style.display = '';
            } else {
                progressSteps.style.display = 'none';
            }
        }
    }

    /* region:progress-log — 진행 기록 렌더 (vitest 가 이 구간을 그대로 평가한다 · 사본 금지)

       이 구간이 하는 일은 하나다: **서버가 보낸 줄을 화면에 그린다.** 그런데 그 양이
       실측으로 한 설치에 13,017 줄(그중 `[cmd]` 12,798 줄 · 1.1 MB)이라, 「한 줄씩 그린다」는
       순진한 구현이 곧바로 메인 스레드 블록이 된다. 무CLI 단계별 실행에서는 한 응답이
       그 태스크의 **모든** 줄을 한꺼번에 싣고 오므로 더 그렇다 — 실측(2026-09-11, 수정 전):
       한 응답이 207,699 자를 몰고 와 1,579 ms 동안 화면이 멈췄다(최장 long task).

       그래서 이 구간의 규칙은 셋이다.
         1. 문자열 누적(`textContent +=`) 금지 — n 줄이면 O(n²) 바이트 복사다.
         2. 한 배치는 `DocumentFragment` 에 모아 **한 번** 붙이고, 스크롤도 **한 번**만 만진다.
         3. 화면에 쌓이는 줄에는 상한이 있다 — 전체 기록은 서버 로그 파일이 갖는다. */

    /**
     * 진행 기록·명령 상세가 화면에 유지하는 최대 줄 수.
     *
     * 실측 근거(2026-09-11 리허설): 한 설치가 진행 기록 208 줄 + 명령 상세 643,167 자를 만든다.
     * 상한은 그 진행 기록의 약 10 배로 두어 정상 설치는 한 줄도 접히지 않게 하고, 재시도를
     * 반복해 누적된 기록이나 대량 주입에서만 앞에서부터 접힌다.
     */
    const PROGRESS_LOG_MAX_LINES = 2000;

    /** 자동 스크롤을 따라갈지 정하는 바닥 여유 (px) — 운영자가 위를 읽는 중이면 끌어내리지 않는다. */
    const PROGRESS_LOG_STICKY_PX = 24;

    /**
     * 커맨드 원문 표식 — 서버 `INSTALLER_COMMAND_OUTPUT_PREFIX` 와 같은 리터럴이어야 한다.
     *
     * 진행 기록의 독자는 운영자다(§12.0 W3). 마이그레이션 파일명·`INFO Running migrations`
     * 같은 개발자 표기는 이 표식이 붙어 오고, 화면은 그것을 진행 기록이 아니라 「명령 상세」
     * 접기 영역으로 보낸다 — 수동 복구 명령이 이미 그렇게 옮겨져 있고 그 형제 통로다.
     * 두 소스의 리터럴 일치는 InstallerCommandOutputChannelTest 가 고정한다.
     */
    const COMMAND_OUTPUT_PREFIX = '[cmd] ';

    /**
     * 로그 한 줄이 커맨드 원문인지 판정하고, 맞으면 표식을 뗀 본문을 돌려준다.
     *
     * @param {string} message 로그 줄
     * @returns {string|null} 커맨드 원문이면 표식을 뗀 본문, 아니면 null
     */
    function commandOutputBody(message) {
        if (typeof message !== 'string' || message.indexOf(COMMAND_OUTPUT_PREFIX) !== 0) {
            return null;
        }

        return message.slice(COMMAND_OUTPUT_PREFIX.length);
    }

    /**
     * 배치를 붙이기 **전에** 그 상자가 바닥에 붙어 있었는지 본다.
     *
     * 종전에는 줄마다 무조건 `scrollTop = scrollHeight` 였다. 그것은 두 가지를 한꺼번에
     * 잘못한다 — 줄 수만큼 **강제 동기 레이아웃**을 일으키고(리플로우), 운영자가 위쪽을
     * 읽는 중에도 화면을 계속 바닥으로 끌어내린다.
     *
     * @param {HTMLElement} el 스크롤 상자
     * @returns {boolean} 바닥에 붙어 있었으면 true
     */
    function progressLogWasAtBottom(el) {
        return el.scrollHeight - el.scrollTop - el.clientHeight <= PROGRESS_LOG_STICKY_PX;
    }

    /**
     * 화면 상한을 적용한다 — 넘친 만큼 **앞에서** 걷어내고, 처음 걷을 때 한 줄로 알린다.
     *
     * 전체 기록은 `storage/logs/installation.log` 가 갖는다. 화면이 그것을 통째로 들고 있을
     * 이유가 없고, 들고 있으면 새로고침 복원에서 그 전량이 한 프레임에 그려진다.
     *
     * @param {HTMLElement} container 줄을 담는 상자
     * @param {string} childSelector 줄 하나를 고르는 선택자
     */
    function trimProgressLog(container, childSelector) {
        // **상한 아래에서는 아무것도 세지 않는다.**
        //
        // `childElementCount` 는 O(1) 이고 `querySelectorAll` 은 O(n) 이다. SSE 는 줄이
        // 하나씩 도착하므로 이 함수가 **줄마다** 불리는데, 그때마다 상자 전체를 훑으면
        // 배치화로 줄인 비용이 그 모드에서 고스란히 되돌아온다 — 실측(2026-09-11): 그
        // 상태의 폴링 라운드에서 최장 long task 가 1,541 ms → 5,337 ms 로 **악화**했다.
        // 자식 수는 줄 + 안내 한 줄 + 플레이스홀더뿐이라 상한 비교의 안전한 상계다.
        if (container.childElementCount <= PROGRESS_LOG_MAX_LINES) {
            return;
        }

        // 안내 줄은 상한 계산에 넣지 않는다 — 자기 자신을 세면 상한이 한 줄씩 밀리고,
        // 세지 않으면서 걷어내면 다음 배치에서 또 붙어 중복된다. 그래서 안내 줄에는
        // 줄 선택자(`.log-entry`·`.command-output-line`)를 아예 주지 않는다.
        const nodes = container.querySelectorAll(childSelector);
        const excess = nodes.length - PROGRESS_LOG_MAX_LINES;
        if (excess <= 0) return;

        for (let i = 0; i < excess; i++) {
            nodes[i].remove();
        }

        let notice = container.querySelector('.log-truncated-notice');
        if (!notice) {
            notice = document.createElement('div');
            notice.className = 'log-truncated-notice';
            notice.dataset.count = '0';
            container.insertBefore(notice, container.firstChild);
        }
        notice.dataset.count = String((parseInt(notice.dataset.count, 10) || 0) + excess);
        notice.textContent = lang('log_truncated_notice', { count: notice.dataset.count });
    }

    /**
     * 커맨드 원문을 「명령 상세」 접기 영역에 덧붙인다.
     *
     * 영역은 첫 줄이 도착할 때 비로소 드러난다 — 커맨드가 아무것도 뱉지 않은 설치에서
     * 빈 접기 버튼만 남는 것을 막는다.
     *
     * **문자열을 누적하지 않는다.** `textContent += …` 는 매 줄마다 상자 전체를 다시 만들고
     * 다시 파싱한다 — 실측에서 이 영역이 한 설치에 643,167 자까지 자라므로 그 방식은
     * 줄 수의 제곱에 비례하는 바이트 복사가 된다. 줄마다 텍스트 노드를 하나 만들어
     * `DocumentFragment` 에 모으고 **한 번** 붙인다.
     *
     * @param {string|string[]} lines 표식을 뗀 커맨드 출력 (한 줄 또는 배치)
     */
    function appendCommandOutput(lines) {
        const list = Array.isArray(lines) ? lines : [lines];
        if (list.length === 0) return;

        const section = document.getElementById('command-output-section');
        const logEl = document.getElementById('command-output-log');
        if (!section || !logEl) return;

        const stick = progressLogWasAtBottom(logEl);
        const fragment = document.createDocumentFragment();
        for (const line of list) {
            const row = document.createElement('div');
            row.className = 'command-output-line';
            row.textContent = line;
            fragment.appendChild(row);
        }
        logEl.appendChild(fragment);
        trimProgressLog(logEl, '.command-output-line');

        section.style.display = '';
        if (stick) {
            logEl.scrollTop = logEl.scrollHeight;
        }
    }

    // 화면에 붙이기를 기다리는 로그 메시지 큐
    let pendingLogMessages = [];
    let logFlushScheduled = false;

    /**
     * 로그 메시지 한 줄 추가 — 배치 API 의 1건 호출이다 (호출부 호환).
     *
     * 화면에 곧바로 그린다. 여러 줄이 한 이벤트씩 몰려오는 경로(SSE)는 `queueLogMessage` 를 쓴다.
     */
    function addLogMessage(message) {
        addLogMessages([message]);
    }

    /**
     * 로그 메시지를 큐에 넣고 프레임당 한 번 배치로 붙인다 (SSE 실시간 경로).
     *
     * SSE 는 이벤트가 한 줄씩 도착한다. 모듈 설치처럼 작업 종료 시점에 수천 줄이 한꺼번에 도착하면,
     * 줄마다 DOM 추가와 scrollHeight 읽기(강제 레이아웃)가 반복되어 브라우저가 멈춘다.
     * 큐에 모아 두고 다음 프레임에 배치 API 로 한 번만 붙이고 스크롤한다.
     *
     * @param {string} message 로그 줄
     */
    function queueLogMessage(message) {
        pendingLogMessages.push(message);

        if (logFlushScheduled) return;
        logFlushScheduled = true;
        const schedule = typeof requestAnimationFrame === 'function'
            ? requestAnimationFrame
            : (cb) => setTimeout(cb, 16);
        schedule(flushLogMessages);
    }

    /**
     * 큐에 쌓인 로그 메시지를 한 번에 화면에 붙인다
     */
    function flushLogMessages() {
        logFlushScheduled = false;

        const messages = pendingLogMessages;
        pendingLogMessages = [];
        if (messages.length === 0) return;

        renderLogBatch(messages);
    }

    /**
     * 로그 메시지를 **배치로** 추가한다.
     *
     * 단계별 실행은 한 응답이 그 태스크의 모든 `log` 이벤트를 싣고 오고, 새로고침 복원은
     * 누적 기록 전량을 싣고 온다. 그것을 줄마다 `appendChild` + `scrollTop` 대입으로 그리면
     * 줄 수만큼 강제 리플로우가 나 화면이 그 시간 동안 통째로 멈춘다(실측: 1,579 ms).
     * 한 배치는 조립 한 번 · 삽입 한 번 · 스크롤 한 번이다.
     *
     * 큐(queueLogMessage)에 남은 줄을 먼저 붙여 도착 순서를 지킨다.
     *
     * @param {Array<string>} messages 로그 줄 배열
     */
    function addLogMessages(messages) {
        if (!Array.isArray(messages) || messages.length === 0) return;

        flushLogMessages();
        renderLogBatch(messages);
    }

    /**
     * 배치 하나를 그린다 — 커맨드 원문과 진행 기록을 나눠 각자 한 번씩 붙인다.
     *
     * @param {Array<string>} messages 로그 줄 배열
     */
    function renderLogBatch(messages) {
        // 커맨드 원문은 다른 상자로 간다 — 두 갈래를 먼저 나누고 각자 한 번씩 붙인다.
        const commandLines = [];
        const logLines = [];
        for (const message of messages) {
            const commandLine = commandOutputBody(message);
            if (commandLine !== null) {
                commandLines.push(commandLine);
            } else if (typeof message === 'string') {
                logLines.push(message);
            }
        }

        if (commandLines.length > 0) {
            appendCommandOutput(commandLines);
        }
        if (logLines.length === 0) return;

        const logDiv = document.getElementById('install-log');
        if (!logDiv) return;

        // 로그 플레이스홀더 제거
        const placeholder = logDiv.querySelector('.log-placeholder');
        if (placeholder) {
            placeholder.remove();
        }

        const stick = progressLogWasAtBottom(logDiv);
        const fragment = document.createDocumentFragment();
        for (const message of logLines) {
            const logEntry = document.createElement('div');
            logEntry.className = 'log-entry';
            logEntry.textContent = message;
            fragment.appendChild(logEntry);
        }
        logDiv.appendChild(fragment);
        trimProgressLog(logDiv, '.log-entry');

        if (stick) {
            logDiv.scrollTop = logDiv.scrollHeight;
        }
    }

    /**
     * 기존 로그 표시 (페이지 로드 시)
     *
     * @param {Array} logs 로그 배열 [{timestamp, message}, ...] 또는 문자열 배열
     */
    function displayLogs(logs) {
        const logDiv = document.getElementById('install-log');
        if (!logDiv) return;

        // 서버 로그 배열을 그대로 받으므로 그 길이가 곧 「이 창이 본 줄 수」다.
        if (Array.isArray(logs)) {
            renderedLogLineCount = Math.max(renderedLogLineCount, logs.length);
        }

        // 로그가 없으면 플레이스홀더를 **유지**한다 — 검사가 제거보다 뒤에 있으면 주석과
        // 코드가 반대가 된다 (11차 #B15). 그 상태에서는 로그가 쌓이기 전에 실패·중단한
        // 설치를 새로고침했을 때 「진행 기록」이 안내도 플레이스홀더도 없는 빈 검은 상자로
        // 남는다(복원 경로 showAbortedInstallationSection·실패 복원이 둘 다 여기를 지난다).
        if (!logs || logs.length === 0) {
            return;
        }

        // 새로고침 복원 경로도 실행 중과 **같은 배치 API** 를 지난다. 종전에는 이 자리가
        // 자기 루프로 줄마다 `appendChild` 했고, 그래서 누적 기록 전량이 한 프레임에
        // 그려졌다 — 재개가 가장 무거운 렌더인데 가장 순진한 경로를 쓰고 있었다.
        //
        // 분류 규칙(커맨드 원문 vs 진행 기록)도 배치 API 안에 하나만 둔다. 두 곳에 두면
        // 「돌 때는 안 보이는데 새로고침하면 보이는」 줄이 생긴다 (§12.0 W3).
        const messages = [];
        for (const log of logs) {
            if (typeof log === 'string') {
                messages.push(log);
            } else if (log && typeof log === 'object' && typeof log.message === 'string') {
                messages.push(log.message);
            }
            // 그 밖은 버린다 — 진행 기록은 사람 말로 쓴다. 원시 JSON 을 찍느니 그 줄을
            // 버린다 (10차가 이 상자에서 셸 명령을 걷어낸 것과 같은 축 · 11차 #B16).
        }

        addLogMessages(messages);
    }

    /* endregion:progress-log */

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
     * 의존 때문에 앞당겨 설치하는 항목의 「{이름}에 필요」 표시 문구 (#153).
     *
     * 확장 저장 시 해석기가 기록한 `install_pulled_by`(종류 경계를 넘어 앞당겨진 `카테고리:식별자` → 요구한 확장)를
     * 설치 작업(*_install) 행에만 붙인다. 카테고리는 항목의 kind 다 — 화면은 확장 작업을 실행 순서대로 한 묶음
     * (extensions)에 담으므로 그룹 id 가 카테고리가 아니다(PO 2026-11-02). kind 가 없는 옛 그룹 정의(종류별 묶음)만
     * 그룹 id 로 찾는다.
     *
     * @param {string} groupId 작업 그룹 id
     * @param {Object} task 작업 객체 ({id, target, kind})
     * @returns {string|null} 표시 문구 (해당 없으면 null)
     */
    function getTaskRequiredByLabel(groupId, task) {
        if (!task.target || !/_install$/.test(task.id)) return null;
        const kind = task.kind || groupId;
        const owners = (window.INSTALLER_PULLED_BY || {})[kind + ':' + task.target];
        if (!Array.isArray(owners) || owners.length === 0) return null;
        const names = owners.map((key) => {
            const id = String(key).slice(String(key).indexOf(':') + 1);
            return getStoredExtensionName(id) || id;
        });
        return lang('task_required_by', { names: names.join(', ') });
    }

    /**
     * 작업 키(`id` 또는 `id:target`)를 작업 객체로 나눈다 — state 의 failed_task 는 target 을 붙여 저장한다.
     *
     * @param {string|null} taskKey 작업 키
     * @returns {{id: string, target: (string|null)}|null} 작업 객체
     */
    function splitTaskKey(taskKey) {
        if (!taskKey) return null;
        const sep = String(taskKey).indexOf(':');
        return sep === -1
            ? { id: String(taskKey), target: null }
            : { id: String(taskKey).slice(0, sep), target: String(taskKey).slice(sep + 1) };
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
                <div id="task-${escapeHtml(task.id)}" class="task-item task-pending" data-task-id="${escapeHtml(task.id)}" data-target="">
                    <span class="task-icon">${getIconSvg('pending')}<span class="sr-only">${escapeHtml(lang('status_pending'))}</span></span>
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
                const requiredBy = getTaskRequiredByLabel(group.id, task);
                return `
                    <div id="${escapeHtml(taskDomId)}" class="task-item task-pending" data-task-id="${escapeHtml(task.id)}" data-target="${escapeHtml(task.target || '')}">
                        <span class="task-icon">${getIconSvg('pending')}<span class="sr-only">${escapeHtml(lang('status_pending'))}</span></span>
                        <span class="task-name">${escapeHtml(taskName)}</span>
                        ${requiredBy ? `<span class="task-required-by">${escapeHtml(requiredBy)}</span>` : ''}
                    </div>
                `;
            }).join('');

            return `
                <div class="task-group" data-group-id="${escapeHtml(group.id)}">
                    <div class="task-group-header" role="button" tabindex="0"
                         aria-expanded="true" aria-controls="task-group-items-${escapeHtml(group.id)}"
                         data-action="toggleTaskGroup" data-arg="${escapeHtml(group.id)}">
                        <span class="task-group-toggle" aria-hidden="true">▼</span>
                        <span class="task-group-label">${escapeHtml(groupLabel)}</span>
                        <span class="task-group-status status-pending">${lang('status_pending')}</span>
                    </div>
                    <div class="task-group-items" id="task-group-items-${escapeHtml(group.id)}">
                        <div class="task-group-items-inner">
                            ${tasksHtml}
                        </div>
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
    function displayCompletedTasks(completedTasks, skippedTasks = [], authoritative = false) {
        // 「완료 → 미완료」 전이도 반영한다 (22차 #22-4). 서버는 완료를 되돌릴 수 있는
        // 사실로 다룬다 — 중단/연결끊김 롤백이 성공하면 removeTaskCompleted 로 마커를
        // 지운다. 화면이 단조 증가만 하면 롤백된 태스크가 초록('task-completed')으로 남아
        // 중단 화면이 실제 DB 와 반대를 말하고, 같은 상태가 새로고침 전후로 다르게 보인다.
        //
        // 단, 되돌림은 authoritative(응답에 실제 목록 배열이 실림)에서만 (23차 F-3) —
        // 인코딩 실패 폴백처럼 목록이 **부재**한 응답을 공집합으로 읽으면 화면의 모든
        // 완료 표시를 지우고 진행률을 0% 로 되감는다. 소비자는 멱등이어야 한다.
        if (authoritative) {
            const listedKeys = new Set(completedTasks);
            document.querySelectorAll('.task-item.task-completed').forEach((el) => {
                const taskId = el.dataset.taskId || '';
                const target = el.dataset.target || null;
                const key = target ? `${taskId}:${target}` : taskId;
                if (taskId && !listedKeys.has(key)) {
                    setTaskStatus(taskId, 'pending', target);
                }
            });
        }

        completedTasks.forEach(taskIdWithTarget => {
            // taskId:target 형식 파싱 (예: "template_install:sirsoft-admin_basic")
            const [taskId, target] = splitCompositeTaskKey(taskIdWithTarget);

            // 통합 함수 사용 — 운영자가 건너뛴 단계는 "건너뜀" 으로 구분 표시
            const skipped = Array.isArray(skippedTasks) && skippedTasks.includes(taskIdWithTarget);
            setTaskStatus(taskId, skipped ? 'skipped' : 'completed', target);
        });

        // 진행률 업데이트
        updateProgressBar();
    }


    /**
     * 결과/안내 섹션을 뷰포트로 부드럽게 스크롤합니다.
     *
     * 완료/실패/중단 안내는 페이지 최상단에 있는데, 설치 진행 중에는 사용자가 로그를 보느라
     * 화면이 하단에 머물러 있는 경우가 많다. 그대로 두면 안내가 표시되어도 눈에 들어오지
     * 않는다. 이미 최상단이면 스크롤은 no-op 이라 새로고침 복원 경로에서도 무해하다.
     *
     * 헤더 바가 sticky 이므로 섹션 상단이 가려지지 않도록 CSS 의 scroll-margin-top 이
     * 여백을 확보한다 (installer.css 의 .result-section, #env-setup-section).
     *
     * @param {HTMLElement|null} sectionEl 스크롤 대상 섹션
     */
    function scrollResultIntoView(sectionEl) {
        if (!sectionEl) return;

        // 모션 최소화를 선호하는 사용자는 즉시 이동
        const reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        sectionEl.scrollIntoView({
            behavior: reduceMotion ? 'auto' : 'smooth',
            block: 'start',
        });
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
        const completedTrack = document.getElementById('overall-progress-track');
        if (completedTrack) {
            completedTrack.setAttribute('aria-valuenow', '100');
        }
        if (progressPercentage) {
            progressPercentage.textContent = '100%';
        }

        // 현재 작업 텍스트를 "설치 완료"로 변경
        const currentTaskEl = document.getElementById('current-task');
        if (currentTaskEl) {
            currentTaskEl.textContent = lang('installation_completed');
            currentTaskEl.classList.add('progress-status--success');
        }

        // 설치 카드 body 는 기본적으로 접는다 — 단, 지나간 단계가 있으면 펼친 채로 둔다.
        // 건너뜀·best-effort 실패는 진행 목록 안에서만 보이는데, 접어 버리면 최종 화면에는
        // 100% 「설치 완료」만 남아 운영자가 그 사실을 만날 방법이 없다 (W3).
        const installToggleIcon = document.getElementById('install-toggle-icon');
        const passedOver = (Array.isArray(data && data.skipped_tasks) ? data.skipped_tasks.length : 0)
            + (Array.isArray(data && data.best_effort_failed_tasks) ? data.best_effort_failed_tasks.length : 0);

        if (installToggleIcon) {
            installToggleIcon.classList.remove('hidden');
        }
        setInstallationCardCollapsed(passedOver === 0);
        setInstallationCardInteractive(true);

        // 완료 섹션 표시 (타이틀 영역에, fadeIn 효과)
        const completionSection = document.getElementById('completion-section');
        if (completionSection) {
            completionSection.classList.remove('hidden');
            // 결과가 나타나면 포커스를 옮긴다 — 옮기지 않으면 키보드·화면 판독기
            // 사용자는 Tab 으로 화면 끝까지 가야 결과를 만난다.
            completionSection.focus({ preventScroll: false });
            // 전환은 CSS 가 소유한다 — 인라인 style 로 박으면 prefers-reduced-motion 을
            // 존중할 방법이 없다(우선순위상 미디어 쿼리가 이길 수 없다).
            completionSection.classList.add('result-section-enter');

            // 약간의 지연 후 페이드인
            setTimeout(() => {
                completionSection.classList.add('result-section-enter--visible');
            }, 100);
        }

        // .env 최종 머지 — fire-and-forget. 응답을 기다리지 않으며,
        // php artisan serve 의 .env mtime 워처가 트리거하는 워커 재시작은
        // 완료 화면 노출 후 발생하므로 사용자 경험에 영향이 없다.
        // keepalive: 탭 닫힘에도 요청 유지. catch: 워커 재시작으로 인한
        // connection abort 를 에러로 표시하지 않는다 (의도된 동작).
        try {
            // 하위 폴더 설치(/g7/install 등)에서도 맞도록 다른 API 호출처럼 INSTALLER_BASE_URL 기준으로 만든다.
            // 문서 루트가 프로젝트 루트로 고정된 서버(축 B)에서는 인스톨러가 /public/install/ 아래에 있어
            // 절대 경로 /install/... 은 404 가 된다 — INSTALLER_BASE_URL 이 그 접두사를 이미 싣는다.
            fetch(`${window.location.origin}${window.INSTALLER_BASE_URL}/api/finalize-env.php`, {
                method: 'POST',
                keepalive: true,
                // 상태 변경 요청과 같은 헤더를 쓴다 — finalize 는 단계별 실행에서 토큰을
                // 요구한다(installerFinalizeRejection). 무CLI 환경은 단계별 실행으로
                // 강제되므로, 토큰이 빠지면 **이 안이 지원하려는 환경에서만** 403 이 되고
                // 화면은 fire-and-forget 이라 성공을 그대로 띄운다 (13차 D2).
                headers: { ...stateMutationHeaders(), 'X-Installer-Finalize': '1' },
            }).then((res) => {
                // 수락 여부는 본다 (19차 S-3). 서버는 이 요청을 405 / 409(끝난 설치가 아님) /
                // 403(토큰 부재·불일치 · 교차 출처)로 거부할 수 있는데, `fetch` 는 4xx 에
                // reject 하지 않으므로 `.catch` 는 발화하지 않는다. 그러면 초록 「설치 완료」와
                // [관리자 로그인] 이 그대로 뜨는데 `runtime.php` 의 **평문 DB·관리자 비밀번호**와
                // `state.json` 이 디스크에 남고 `INSTALLER_COMPLETED` 가 기록되지 않아
                // **인스톨러가 계속 열려 있다** — 운영자에게 알리는 문구가 어디에도 없다.
                //
                // 같은 파일의 중단 경로는 14차 #2 에서 이미 `res.ok` 판정을 받았다. 한 파일 안에
                // 규칙이 갈려 있던 자리를 맞춘다.
                //
                // 서버는 본문을 **먼저** 보내고 그 뒤에 일을 하므로(`finalize-env.php`) 이
                // 확인에 드는 대기는 사실상 0 이다.
                if (!res.ok) {
                    showFinalizeRejectedNotice();
                }
            }).catch(() => { /* 의도된 abort */ });
        } catch (_) {
            // fetch 자체가 실패해도 무시 — runtime.php 가 보존되어 다음 부팅 시 앱 정상 동작
        }

        // 완료 안내로 스크롤 (타이틀 숨김·카드 접기로 문서 높이가 바뀐 뒤에 호출)
        scrollResultIntoView(completionSection);
    }

    /**
     * 마무리가 거부됐음을 완료 화면에 덧붙인다 (19차 S-3).
     *
     * 완료 화면을 걷어내지는 않는다 — 설치 자체는 끝났고, 남은 것은 마무리 한 단계다.
     * 그러나 그 사실을 숨기면 운영자는 인스톨러가 왜 계속 열려 있는지 알 수 없다.
     *
     * 이 조각은 **자기 라이브 리전을 선언하지 않는다.** 붙는 자리인 `#completion-section`
     * 이 이미 `role="status" aria-live="polite"` 이고(이 시점에는 `hidden` 이 풀려 화면에
     * 있다), 그 안에 노드를 덧붙이는 것 자체가 그 리전의 낭독 대상이다. 여기에 다시
     * `role="alert"`(암묵 assertive)를 얹으면 assertive-in-polite 중첩이라 화면 판독기
     * 구현에 따라 중복 낭독 또는 안쪽 무시로 갈린다 — 어느 쪽이든 의도한 안내가 아니다.
     * 시각적 강조는 `alert alert-error` 클래스가 그대로 담당한다.
     */
    function showFinalizeRejectedNotice() {
        const section = document.getElementById('completion-section');
        if (!section || document.getElementById('finalize-rejected-notice')) return;

        const notice = document.createElement('div');
        notice.id = 'finalize-rejected-notice';
        notice.className = 'alert alert-error';
        notice.textContent = lang('finalize_rejected_notice');
        section.appendChild(notice);
    }

    /**
     * 에러 표시
     *
     * @param {string} errorMessage 메인 오류 메시지
     * @param {string|null} errorDetail 상세 오류 메시지 (선택)
     * @param {string|null} failedTaskId 실패한 작업 ID (선택, `id` 또는 `id:target`)
     * @param {boolean} skippable 실패 단계가 [건너뛰고 계속] 가능한 선택 확장 단계인지 (선택)
     * @param {string[]|null} manualCommands 수동 복구 명령 목록 (선택 — 무CLI 환경에서는 서버가 만들지 않는다)
     * @param {string|null} messageKey 실패 문구 키 (선택) — 사전 검증 실패면 「Step 4 로 돌아가기」를 앞세운다
     */
    function showError(errorMessage, errorDetail = null, failedTaskId = null, skippable = false, manualCommands = null, messageKey = null) {
        installationCompleted = true; // 완료 플래그 설정 (beforeunload 경고 비활성화)
        lastErrorSkippable = !!skippable;
        // 재개 계열 버튼 잠금 해제 — 이 화면이 그 버튼들의 다음 사용처다 (M15)
        setResumeButtonsDisabled(false);

        // [건너뛰고 계속] 은 선택 확장 단계의 실패에서만 보인다 — 필수 단계는 숨긴다 (W3)
        const skipBtn = document.getElementById('skip-task-btn');
        if (skipBtn) {
            skipBtn.style.display = lastErrorSkippable ? '' : 'none';
            skipBtn.disabled = false;
        }

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

        if (installToggleIcon) {
            installToggleIcon.classList.remove('hidden');
        }

        // 설치 카드 body 표시 (작업 목록과 로그 보이기)
        setInstallationCardCollapsed(false);
        setInstallationCardInteractive(true);

        // failedTaskId가 파라미터로 제공되지 않은 경우, DOM에서 추출 시도
        if (!failedTaskId) {
            // 현재 진행 중인 작업(task-active)에서 추출
            const activeTasks = document.querySelectorAll('.task-item.task-active');
            activeTasks.forEach(taskEl => {
                if (!failedTaskId) {
                    const identity = taskIdentityFromElement(taskEl);
                    if (identity.taskId) {
                        // 표시 이름 변환은 합성 키("종류:대상")를 받는다 — DOM id 의
                        // 하이픈 결합("종류-대상")을 그대로 넘기면 번역 키가 어긋나
                        // 실패 제목에 원시 키가 노출된다.
                        failedTaskId = identity.target
                            ? `${identity.taskId}:${identity.target}`
                            : identity.taskId;
                    }
                }
            });
        }

        // Task ID를 번역된 이름으로 변환 — state 복원 경로는 `module_install:sirsoft-page` 처럼 target 을 붙여 온다
        const failedTask = splitTaskKey(failedTaskId);
        const failedTaskName = failedTask ? getTaskDisplayName(failedTask) : null;

        // 현재 진행 중인 작업(task-active)을 모두 실패로 표시
        const activeTasks = document.querySelectorAll('.task-item.task-active');
        activeTasks.forEach(taskEl => {
            const identity = taskIdentityFromElement(taskEl);
            // 통합 함수 사용
            setTaskStatus(identity.taskId, 'failed', identity.target);
        });

        // 실패 섹션 표시
        const failureSection = document.getElementById('failure-section');
        const failureMessage = document.getElementById('failure-message');
        const failureTitle = failureSection?.querySelector('.result-title');

        // 의존성 사전 검증 실패(순환·누락)는 재시도로 풀리지 않는다 — 「Step 4 로 돌아가기」를 주 버튼으로, 재시도는 보조로
        const isPrecheckFailure = messageKey === 'dependency_precheck_failed';
        // DB 작업(db_cleanup 부터) 전 단계에서 멈춘 실패 — 되돌아가기 확인 창에서 DB 수동 정리 안내를 뺀다.
        // 목록은 서버 installerPreDatabaseTaskIds() 와 같다(작업 순서 고정).
        const preDatabaseTasks = ['composer_check', 'composer_install', 'env_update', 'key_generate', 'dependency_precheck'];
        lastFailureBeforeDatabase = isPrecheckFailure || (!!failedTask && preDatabaseTasks.includes(failedTask.id));
        const backToExtensionsBtn = document.getElementById('back-to-extensions-btn');
        const retryBtn = document.getElementById('retry-installation-btn');
        if (backToExtensionsBtn) {
            backToExtensionsBtn.classList.toggle('hidden', !isPrecheckFailure);
        }
        if (retryBtn) {
            retryBtn.classList.toggle('btn-primary', !isPrecheckFailure);
            retryBtn.classList.toggle('btn-secondary', isPrecheckFailure);
        }

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

                // 상세 오류 (있을 경우만 - 아코디언 형식). 사전 검증 실패는 사유가 곧 해결 방법이라 펼쳐 둔다.
                if (errorDetail) {
                    const accordionId = 'error-detail-' + Date.now();
                    const expanded = isPrecheckFailure;
                    messageHtml += `
                        <div class="failure-error-detail">
                            <button
                                class="error-detail-toggle"
                                type="button"
                                aria-expanded="${expanded ? 'true' : 'false'}"
                                aria-controls="${accordionId}"
                                data-action="toggleErrorDetail" data-arg="${accordionId}"
                            >
                                <span class="error-detail-toggle-icon" aria-hidden="true">▶</span>
                                <strong>${lang('view_error_details')}</strong>
                            </button>
                            <div id="${accordionId}" class="error-detail-content${expanded ? ' show' : ''}">
                                <pre class="error-stack">${escapeHtml(errorDetail)}</pre>
                            </div>
                        </div>
                    `;
                }

                // 수동 복구 명령 (있을 경우 — 접힌 채로).
                //
                // 종전에는 이 목록을 **진행 기록**에 `  $ php artisan …` 형태로 흘려보냈다.
                // §12.0 W3 는 「진행 기록은 사람 말로 쓴다 (명령·클래스 이름 금지)」이므로 기록에서
                // 걷어내고, 필요한 사람이 펼쳐 볼 수 있게 실패 화면의 접기 영역으로 옮긴다 (10차).
                // 서버는 무CLI 환경에서 이 목록을 아예 만들지 않는다 — 따를 수 없는 안내이기 때문이다.
                if (Array.isArray(manualCommands) && manualCommands.length > 0) {
                    const commandsId = 'manual-commands-' + Date.now();
                    messageHtml += `
                        <div class="failure-error-detail">
                            <button
                                class="error-detail-toggle"
                                type="button"
                                aria-expanded="false"
                                aria-controls="${commandsId}"
                                data-action="toggleErrorDetail" data-arg="${commandsId}"
                            >
                                <span class="error-detail-toggle-icon" aria-hidden="true">▶</span>
                                <strong>${escapeHtml(lang('manual_commands_guide'))}</strong>
                            </button>
                            <div id="${commandsId}" class="error-detail-content">
                                <pre class="error-stack">${escapeHtml(manualCommands.join(String.fromCharCode(10)))}</pre>
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
            // 결과가 나타나면 포커스를 옮긴다 — 옮기지 않으면 키보드·화면 판독기
            // 사용자는 Tab 으로 화면 끝까지 가야 결과를 만난다.
            failureSection.focus({ preventScroll: false });
            // 전환은 CSS 가 소유한다 — 인라인 style 로 박으면 prefers-reduced-motion 을
            // 존중할 방법이 없다(우선순위상 미디어 쿼리가 이길 수 없다).
            failureSection.classList.add('result-section-enter');

            setTimeout(() => {
                failureSection.classList.add('result-section-enter--visible');
            }, 100);
        }

        // 실패 안내로 스크롤 (카드 body 를 펼친 뒤에 호출)
        scrollResultIntoView(failureSection);
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
        const installToggleIcon = document.getElementById('install-toggle-icon');
        const logDiv = document.getElementById('install-log');

        setInstallationCardCollapsed(false);
        // 다시 진행 중이므로 헤더는 다시 「누를 수 없는 상태」로 돌아간다 (12차 #B28).
        setInstallationCardInteractive(false);
        if (installToggleIcon) {
            installToggleIcon.classList.add('hidden');
        }
        if (logDiv) {
            // 아직 큐에 남은 이전 로그를 먼저 붙여 구분선 앞뒤 순서를 지킨다
            flushLogMessages();

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

        // 재개 계열 버튼을 잠근다 — 아래에서 재진입 가드를 내리므로, 잠그지 않으면
        // 두 번째 클릭이 그대로 통과해 워커가 둘 뜬다 (M15).
        setResumeButtonsDisabled(true);

        // 상태 초기화
        installationStarted = false;
        installationCompleted = false;
        if (currentMonitor) {
            currentMonitor.stop();
            currentMonitor = null;
        }
        // 설치 재시작
        startInstallation();
    }

    /* region:lock-wait — 점유 해제 대기 (vitest 가 이 구간을 그대로 평가한다 · 사본 금지)

       서버가 409 로 「점유가 아직 예산 안이다」를 돌려줄 때의 화면 절반이다 (D7).

       현장(2026-09-12): 디스크가 차서 태스크가 죽었고, 그 죽음은 해제 기록을 남기지 못해
       점유가 남았다. 운영자가 공간을 비우고 [재시도] 를 눌렀지만 화면은 「다른 창에서 진행
       중」이라는 **존재하지 않는 창**을 가리켰고, 남은 출구는 [처음부터 다시](전부 폐기)뿐이었다.

       서버는 이제 남은 시간(`retry_after`)을 함께 준다. 그 숫자가 있으면 이 구간이 세어 주고
       0 에서 **한 번** 스스로 이어간다 — 안내가 「:seconds초 뒤 자동으로 이어서 진행됩니다」라고
       약속했으므로, 세기만 하고 멈추면 그 약속이 거짓이 된다. */

    /** 진행 중인 해제 대기 타이머 (동시에 하나만) */
    let lockWaitTimer = null;

    /**
     * 해제 대기를 그만둔다 — 운영자가 먼저 눌렀거나 화면이 다른 상태로 넘어갈 때.
     */
    function cancelLockWait() {
        if (lockWaitTimer) {
            clearInterval(lockWaitTimer);
            lockWaitTimer = null;
        }
    }

    /**
     * 점유가 풀릴 때까지 세었다가 **한 번** 스스로 이어간다 (D7).
     *
     * 자동 재시도는 정확히 1회다. 실패를 그대로 다시 받으면 그때는 사람이 판단할 자리이고,
     * 무한 재시도는 D2 가 고친 그 폭주를 다시 만든다.
     *
     * @param {number} seconds 남은 초 (서버 `retry_after`)
     * @param {string} message 서버가 준 안내 문구
     */
    function startLockWait(seconds, message) {
        cancelLockWait();
        showError(message || lang('installation_start_failed'));

        const section = document.getElementById('failure-section');
        if (!section) return;

        let row = document.getElementById('lock-wait-countdown');
        if (!row) {
            row = document.createElement('p');
            row.id = 'lock-wait-countdown';
            row.className = 'lock-wait-countdown';
            // 초마다 바뀌는 값이라 라이브 리전으로 두지 않는다 — 화면 판독기가 매초 읽으면
            // 형제(실패 안내)가 묻힌다. D2 의 경과 표시와 같은 판단이다.
            const anchor = document.getElementById('failure-message');
            if (anchor && anchor.parentNode) {
                anchor.parentNode.insertBefore(row, anchor.nextSibling);
            } else {
                section.appendChild(row);
            }
        }

        let remaining = Math.max(1, Math.floor(seconds));
        row.textContent = lang('installation_lock_wait_countdown', { seconds: String(remaining) });
        row.style.display = '';

        lockWaitTimer = setInterval(() => {
            remaining -= 1;

            if (remaining > 0) {
                row.textContent = lang('installation_lock_wait_countdown', { seconds: String(remaining) });

                return;
            }

            cancelLockWait();
            row.textContent = lang('installation_lock_wait_resuming');
            resumeAfterLockWait();
        }, 1000);
    }

    /**
     * 대기가 끝난 뒤 **무엇으로** 이어갈지 고른다.
     *
     * 종전 초안은 무조건 `continueInstallation()`(시작 경로)이었다. 브라우저 실측이 그것을
     * 잡았다 — 현장 상태(단계별 + `running`)에서 그 경로는 토큰 게이트에 **403** 으로 막힌다.
     * 즉 카운트다운은 도는데 그 끝이 또 막다른 길이었다.
     *
     * 그 상태의 정본은 재개다. 판정은 화면 로드가 쓰는 것과 **같은 함수**(`stepwiseResumeApplies`)로
     * 한다 — 사본을 두면 두 경로가 갈라진다.
     */
    async function resumeAfterLockWait() {
        let state = null;
        try {
            state = await fetchInstallationState();
        } catch (e) {
            // 상태를 못 읽으면 종전 동작(시작)으로 떨어진다 — 확인하지 못한 것을 근거로
            // 경로를 바꾸지 않는다.
            state = null;
        }

        if (stepwiseResumeApplies(state)) {
            resumeStepwiseInstallation(state);

            return;
        }

        continueInstallation();
    }

    /* endregion:lock-wait */

    /**
     * 설치 재시도 (continueInstallation 래퍼)
     */
    function retryInstallation() {
        // 운영자가 먼저 눌렀으면 타이머가 뒤따라 한 번 더 쏘지 않게 한다 (D7) —
        // 두 번 나가면 두 번째가 자기 자신의 워커에 막혀 같은 화면으로 되돌아온다.
        cancelLockWait();
        continueInstallation();
    }

    /**
     * 재개 계열 버튼(다시 시도 / 계속 / 건너뛰고 계속)을 잠근다.
     *
     * `continueInstallation()` 은 재진입 가드(installationStarted)를 **먼저 내리고**
     * 시작을 호출하므로, 시작이 probe·POST 를 기다리는 동안 두 번째 클릭이 그대로 통과해
     * 워커가 둘 뜬다. 앞선 모니터는 참조를 잃어 멈출 수도 없다.
     *
     * @param {boolean} disabled 잠글지 여부
     */
    function setResumeButtonsDisabled(disabled) {
        ['retry-installation-btn', 'resume-installation-btn', 'skip-task-btn'].forEach((id) => {
            const btn = document.getElementById(id);
            if (btn) btn.disabled = !!disabled;
        });
    }

    /**
     * 완료 표식 뒤 마무리 작업(설정·라우트 캐시 굽기·정적 게시)이 끝날 때까지 기다린다.
     *
     * 폴링 워커는 브라우저와 무관하게 계속 돌므로, 새로고침 직후 완료 화면을 열면 finalize-env 가 상태 파일을 지워
     * 워커가 lock lost 로 끝난다. 상태 갱신이 30초 멈추거나 90초가 지나면(워커가 사라진 경우) 그대로 진행한다.
     *
     * @param {object} state 상태 조회 응답
     * @returns {Promise<object>} 마지막으로 받은 상태
     */
    async function waitForFinalTasks(state) {
        const deadline = Date.now() + 90000;
        let current = state;
        let lastUpdated = state.last_updated;
        let lastChangedAt = Date.now();

        while (current.status === 'completed' && current.tasks_finished === false && Date.now() < deadline) {
            if (Date.now() - lastChangedAt >= 30000) break;
            await new Promise(resolve => setTimeout(resolve, 1000));
            try {
                current = await fetchInstallationState();
            } catch (e) {
                break;
            }
            if (current.last_updated !== lastUpdated) {
                lastUpdated = current.last_updated;
                lastChangedAt = Date.now();
            }
        }

        return current.status === 'completed' ? current : state;
    }

    /**
     * 재진입 감지 및 확인
     */
    /**
     * 완료 표식 이후 남은 단계별 태스크를 한 번만 마저 돌린다 (10차).
     *
     * `complete_flag` 은 목록의 끝이 아니다 — 그 뒤에 `config_cache` 가 온다. 이미 완료로
     * 표시된 설치에서도 그 한 태스크가 남아 있을 수 있고, 서버 가드는 그래서 `completed` 를
     * 통과시킨다. 모니터를 다시 붙이지 않고 요청 한 번으로 끝낸다 — 이 시점의 실패는 설치
     * 성패에 영향이 없으므로(best-effort) 조용히 지나간다. 마무리가 이미 끝나 입구가 닫혔으면
     * (410) 그것도 정상이다.
     *
     * @param {Object} state 현재 설치 상태
     */
    async function catchUpRemainingStepwiseTasks(state) {
        if (!state || state.installation_mode !== 'stepwise') return;

        const token = getStepwiseToken();
        if (!token) return;

        try {
            await fetch(`${window.location.origin}${window.INSTALLER_BASE_URL}/api/run-task.php`, {
                method: 'POST',
                cache: 'no-store',
                headers: { 'Content-Type': 'application/json', 'X-Installer-Token': token },
                body: JSON.stringify({ token })
            });
        } catch (e) {
            // 마무리 화면을 막지 않는다 — 남은 태스크는 best-effort 다
        }
    }

    async function checkAndStartInstallation() {
        try {
            // 현재 설치 상태 확인
            const state = await fetchInstallationState();


            // 설치가 이미 완료된 경우 — 마무리 작업이 도는 중이면 끝날 때까지 기다린 뒤 완료 화면(→ finalize-env)
            if (state.status === 'completed') {
                // 서버는 `completed` 상태의 단계별 실행 요청을 일부러 허용한다 — 완료 표식을
                // 세우는 `complete_flag` 뒤에 마지막 태스크(`config_cache`)가 한 번 더 남기
                // 때문이다. 그 창에서 새로고침하면 종전에는 곧장 완료 화면 → 마무리로 가서
                // 그 태스크가 **영원히 실행되지 않았고**, 서버의 허용 분기는 사실상 죽은
                // 코드였다 (10차). 여기서 한 번만 이어받아 남은 태스크를 마저 돌린다.
                await catchUpRemainingStepwiseTasks(state);

                // 폴링 워커는 완료 표식 뒤에도 마무리 작업(설정·라우트 캐시 굽기·정적 게시)을 이어 간다 —
                // 끝나기 전에 완료 화면을 열면 finalize-env 가 상태 파일을 지워 워커가 lock lost 로 끝난다.
                // 끝날 때까지 기다린 상태로 목록·기록·완료 화면을 그린다(waitForFinalTasks).
                const finalState = await waitForFinalTasks(state);

                // 목록과 진행 기록을 먼저 그린다 (19차 S-8).
                //
                // 형제 두 갈래(`failed`·`aborted`)는 전부 `renderTaskList()` +
                // `displayCompletedTasks()` + `displayLogs()` 를 지나는데 이 갈래만 없었다.
                // 그런데 `showCompletionSection()` 은 진행 카드를 **켜고**, 건너뛴 단계 수를
                // 세어 카드를 펼칠지 정한다 — 목록을 그리지 않은 채 카드만 켜므로
                // 「건너뛴 단계를 운영자가 만날 수 있게 한다」는 그 코드의 목적이 정확히
                // 반대로 작동했다: 펼쳐진 것이 빈 상자였고 기록 자리에는 「설치를 기다리는 중」이
                // 남았다.
                renderTaskList();
                {
                    const lists = stateTaskLists(finalState);
                    displayCompletedTasks(lists.completed, lists.skipped, lists.authoritative);
                }
                displayLogs(finalState.logs || []);

                showCompletionSection(finalState);
                return;
            }

            // 설치가 실패한 경우 - 실패 섹션 표시
            if (state.status === 'failed') {
                // 진행 방식을 먼저 복원한다 — displayCompletedTasks → updateProgressBar 가 이 값으로
                // 단계별 실행의 "n / N 단계" 표기 여부를 정하므로, 뒤에 대입하면 실패 화면에서 표기가 빠진다 (W3)
                currentInstallationMode = state.installation_mode || null;

                // 페이지 로드 시 완료된 작업 표시
                renderTaskList();
                const failedLists = stateTaskLists(state);
                displayCompletedTasks(failedLists.completed, failedLists.skipped);

                // 로그 표시
                displayLogs(state.logs || []);

                // 실패 정보 가져오기 (state.json에서 복원) — 폴백 사슬은 모니터 모듈이
                // 소유한다. 세 경로(새로고침 복원 · 폴링 · 단계별 실행)가 각자 사슬을 두면
                // 같은 실패가 경로에 따라 사유가 있기도 없기도 하다 (13차 F12).
                const restoredFailure = window.G7InstallationMonitor.failurePayload(state);
                const errorMessage = translateWithKey(
                    restoredFailure.message_key,
                    restoredFailure.message || lang('unknown_error_occurred'),
                    restoredFailure.message_params
                );
                const errorDetail = restoredFailure.error;
                const failedTaskId = restoredFailure.task;

                // ✅ showError() 호출 전에 먼저 task를 failed로 마크
                if (failedTaskId) {
                    const failedTask = splitTaskKey(failedTaskId);
                    setTaskStatus(failedTask.id, 'failed', failedTask.target);
                }

                // 롤백 실패 정보 복원 (state.json에서 새로고침 후에도 표시)
                if (state.rollback_failure) {
                    lastRollbackFailure = {
                        // translateWithKey 로 번역한다 — lang() 은 미정의 키를 키 문자열
                        // 그대로 돌려주므로 `|| 폴백` 은 결코 발화하지 않는다 (죽은 폴백).
                        // 폴링·SSE 경로와 같은 방식이어야 같은 상태가 같게 그려진다.
                        message: translateWithKey(state.rollback_failure.message_key, lang('failed_rollback_manual_cleanup')),
                        detail: translateWithKey(state.rollback_failure.detail_key, lang('failed_rollback_manual_cleanup_detail')),
                    };
                }

                // ✅ failedTaskId를 명시적으로 전달 (함수 내부에서 번역됨)
                showError(errorMessage, errorDetail, failedTaskId, !!state.failed_task_skippable, state.manual_commands, state.error_message_key || null);

                return;
            }

            // 설치가 중단된 경우 - 중단 섹션 표시
            if (state.status === 'aborted') {
                showAbortedInstallationSection(state);
                return;
            }

            // 단계별 실행은 새로고침해도 이어서 진행된다 — 서버 상태를 복원하고 다음 태스크부터 호출 (W3)
            if (stepwiseResumeApplies(state)) {
                resumeStepwiseInstallation(state);
                return;
            }

            // 설치가 진행 중이었던 경우 (재진입) - alert 없이 바로 중단 섹션 표시
            if (state.status === 'running') {

                // installation_status를 aborted로 변경
                const abortUrl = getStateApiUrl('abort');
                fetch(abortUrl, {
                    method: 'POST',
                    headers: stateMutationHeaders(),
                    body: JSON.stringify({})
                }).then((abortResponse) => {

                    // 서버가 중단을 **수락했는지**를 먼저 본다 (14차 #2).
                    //
                    // 단계별 실행은 상태 변경(run-task·abort·reset·skip_task)에 전부 토큰을
                    // 요구하고, 그 토큰은 설치를 시작한 세션에만 화면으로 나간다
                    // (`public/install/views/5-installation.php` 의 installerSessionOwnsStepwiseInstall).
                    // 세션이 만료됐거나 다른 창·기기로 들어오면 이 abort 는 403 이다.
                    // `fetch` 는 403 에 reject 하지 않으므로 종전에는 그대로 아래로 흘러,
                    // **서버에서는 계속 돌고 있는** 설치 위에 「설치가 중단되었습니다」를
                    // 그렸다 — 서버 running ↔ 화면 aborted 로 갈라진 채 오류도 남지 않았다.
                    const abortAccepted = !!(abortResponse && abortResponse.ok);

                    // 상태를 다시 가져와서 중단 섹션 표시.
                    // 이 스코프에는 apiUrl 이 없다 — 종전 표기는 'use strict' 아래에서
                    // ReferenceError 였고, 바로 아래 catch 가 그것을 삼켜 abort **이전**
                    // 스냅샷으로 중단 화면을 그렸다(오류도 로그도 남지 않는다).
                    // `return` 으로 바깥 catch 에 잇는다 (11차 #B9). 종전에는 콜백이
                    // undefined 를 돌려줘 이 체인의 거부(비-JSON·5xx·네트워크)를 아무도
                    // 잡지 못했고, 중단 화면이 영영 뜨지 않아 진행 카드도 시작 버튼도 없는
                    // 빈 화면 + 콘솔 무경고가 남았다. 8차가 이 자리의 ReferenceError 를
                    // 고치면서 형제인 미처리 거부를 남겼다.
                    return fetch(getStateApiUrl('get'))
                        .then(res => res.json())
                        .then(updatedState => {
                            if (!abortAccepted && updatedState.status === 'running') {
                                showInstallationNotControllableSection(updatedState);
                                return;
                            }
                            showAbortedInstallationSection(updatedState);
                        });
                }).catch(err => {
                    // 실패해도 중단 섹션은 표시
                    showAbortedInstallationSection(state);
                });
            } else {
                // 처음 시작하는 경우 — 필수 파일 체크 후 "설치 시작" 버튼 표시 (자동 시작 금지)
                // 운영자가 모드(SSE/폴링)를 선택한 뒤 명시적으로 시작할 수 있도록 대기
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
        // — 환경이 고정한 라디오(data-locked)는 그대로 둔다 (inprocess 환경의 SSE/폴링)
        document.querySelectorAll('input[name="installation_mode"]').forEach(input => {
            setInputDisabled(input, input.dataset.locked === '1');
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
            setInputDisabled(input, true);
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
        // 진행 중에는 카드 헤더를 누를 수 없다 — 그 사실을 키보드·보조기술에도 알린다.
        // 서버가 렌더한 초기값과 같은 상태지만, 재시도로 되돌아온 경우를 위해 여기서 다시 건다.
        setInstallationCardInteractive(false);
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
    /**
     * 이 창이 조작할 수 없는 「진행 중」 설치 안내 (14차 #2)
     *
     * 단계별 실행의 상태 변경은 전부 토큰을 요구하고, 그 토큰은 설치를 시작한 세션에만
     * 화면으로 나간다. 세션 만료·다른 창·다른 기기로 들어오면 이 창은 설치를 이어받을 수도,
     * 멈출 수도 없다 — 그 사실을 그대로 말한다. 종전에는 이 자리에서 「중단되었습니다」를
     * 그려, 멈추지 않은 설치를 멈춘 것으로 보이게 했다.
     *
     * 알려진 진행분만 복원한다. 이 창은 이후 갱신을 받지 못하므로 재개 계열 버튼도 잠근다.
     *
     * @param {Object} state state-management.php?action=get 응답
     */
    function showInstallationNotControllableSection(state) {
        currentInstallationMode = state.installation_mode || currentInstallationMode;

        // 이 창은 진행 주체가 아니다 — 떠날 때 경고할 이유가 없다.
        installationCompleted = true;

        hideInstallationStartSection();
        showInstallationProgressSection();
        hideAbortButton();
        setResumeButtonsDisabled(true);

        renderTaskList();
        const knownLists = stateTaskLists(state);
        displayCompletedTasks(knownLists.completed, knownLists.skipped);
        displayLogs(state.logs || []);

        const warningAlert = document.getElementById('install-warning');
        if (warningAlert) {
            warningAlert.textContent = lang('installation_not_controllable_message');
            warningAlert.style.display = '';
        }
    }

    function showAbortedInstallationSection(state) {

        // 진행 방식을 먼저 복원한다 — 아래 displayCompletedTasks → updateProgressBar 가 이 값으로
        // 단계별 실행의 "n / N 단계" 표기 여부를 정한다. 실패 화면과 같은 이유이고 같은 순서다 (W3)
        currentInstallationMode = state.installation_mode || currentInstallationMode;

        installationCompleted = true; // 완료 플래그 설정 (beforeunload 경고 비활성화)
        // 재개 계열 버튼 잠금 해제 — 이 화면의 [계속 진행]이 그 다음 사용처다 (M15)
        setResumeButtonsDisabled(false);

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
            // 중단 사유를 보여 준다 (11차 #A12) — 서버는 「사람이 눌렀다」와 「연결이 끊겼다」를
            // 구분해 기록해 왔는데 그 값을 아무도 읽지 않아 두 경우가 같은 문구로 끝났다.
            // 운영자는 그대로 다시 시작해도 되는지 판단할 근거가 필요하다.
            const abortReasonText = state.abort_reason ? lang(state.abort_reason) : '';
            if (abortReasonText && abortReasonText !== state.abort_reason) {
                const resultText = abortedSection.querySelector('.result-text');
                if (resultText && !resultText.querySelector('.abort-reason-notice')) {
                    const reasonEl = document.createElement('p');
                    reasonEl.className = 'abort-reason-notice';
                    reasonEl.innerHTML = '<strong>' + escapeHtml(lang('abort_reason_label')) + '</strong> '
                        + escapeHtml(abortReasonText);
                    resultText.appendChild(reasonEl);
                }
            }

            // 롤백 실패 정보가 있으면 경고 메시지 추가
            if (state.rollback_failure) {
                const resultText = abortedSection.querySelector('.result-text');
                if (resultText) {
                    const rollbackAlert = document.createElement('div');
                    // 실패 화면이 같은 정보를 그리는 클래스를 그대로 쓴다 — 종전 'alert-danger' 는
                    // CSS 에 선언 자체가 없어(alert 변종은 -error/-success/-warning/-info 뿐)
                    // 가장 위험한 안내인 「수동 정리가 필요합니다」가 배경·테두리 없는 평문으로
                    // 나갔다. 여백·글자 크기도 이 클래스가 이미 갖고 있어 인라인 지정이 불필요하다.
                    rollbackAlert.className = 'failure-rollback-notice';
                    const msg = translateWithKey(state.rollback_failure.message_key, lang('failed_rollback_manual_cleanup'));
                    const detail = translateWithKey(state.rollback_failure.detail_key, lang('failed_rollback_manual_cleanup_detail'));
                    rollbackAlert.innerHTML = '<strong>' + escapeHtml(msg) + '</strong><br>' + escapeHtml(detail);
                    resultText.appendChild(rollbackAlert);
                }
            }

            abortedSection.classList.remove('hidden');
            // 결과가 나타나면 포커스를 옮긴다 — 옮기지 않으면 키보드·화면 판독기
            // 사용자는 Tab 으로 화면 끝까지 가야 결과를 만난다.
            abortedSection.focus({ preventScroll: false });
            // 전환은 CSS 가 소유한다 — 인라인 style 로 박으면 prefers-reduced-motion 을
            // 존중할 방법이 없다(우선순위상 미디어 쿼리가 이길 수 없다).
            abortedSection.classList.add('result-section-enter');

            setTimeout(() => {
                abortedSection.classList.add('result-section-enter--visible');
            }, 100);
        } else {
        }

        // 작업 목록 렌더링
        renderTaskList();

        // 완료된 작업 표시
        const abortedLists = stateTaskLists(state);
        const completedTasks = abortedLists.completed;
        const currentTask = state.current_task;
        displayCompletedTasks(abortedLists.completed, abortedLists.skipped);

        // 로그 표시
        displayLogs(state.logs || []);

        // 설치 카드 body 표시 (작업 목록 보이기)
        setInstallationCardCollapsed(false);
        setInstallationCardInteractive(true);

        // 현재 작업을 중단으로 표시
        if (currentTask) {
            // taskId:target 형식 파싱 (예: "module_install:sirsoft-board")
            const [taskId, target] = splitCompositeTaskKey(currentTask);

            // 통합 함수 사용
            setTaskStatus(taskId, 'aborted', target);
        } else {
            // current_task가 null인 경우, 마지막 완료된 작업 다음 작업을 중단으로 표시
            const allTasks = window.INSTALLER_TASKS || [];

            // completedTasks의 마지막 항목에서 taskId와 target 분리
            const lastCompleted = completedTasks[completedTasks.length - 1] || '';
            const [lastTaskId, lastTarget] = splitCompositeTaskKey(lastCompleted);

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

        // 중단 안내로 스크롤 (로그 렌더링으로 문서 높이가 늘어난 뒤에 호출)
        scrollResultIntoView(abortedSection);
    }

    /**
     * 중단된 설치에서 계속 진행 (continueInstallation 래퍼)
     */
    function resumeInstallationFromAborted() {
        continueInstallation();
    }

    /**
     * 설정 페이지로 이동 (확인 후)
     *
     * DB 작업 전 단계에서 멈춘 실패(의존성 사전 검증 등)면 확인 창에 DB 수동 정리 안내를 넣지 않는다.
     */
    async function goToSettingsWithConfirm() {
        const confirmKey = lastFailureBeforeDatabase ? 'confirm_go_to_settings_before_db' : 'confirm_go_to_settings_simple';
        await resetStateAndGoToExtensionSelection(confirmKey);
    }

    /**
     * 확장 선택 단계(Step 4)로 돌아가기 (확인 후) — 의존성 사전 검증 실패 화면의 주 버튼.
     *
     * 데이터베이스를 건드리기 전에 멈춘 실패라 DB 수동 정리 안내 없이 확인하고, 고른 확장은 그대로 남는다.
     */
    async function goBackToExtensionSelection() {
        await resetStateAndGoToExtensionSelection('confirm_back_to_extension_selection');
    }

    /**
     * 확인 창을 띄운 뒤 설치 상태를 초기화하고 설치 마법사로 돌아간다(서버가 Step 4 로 보낸다).
     *
     * @param {string} confirmKey 확인 창 문구 키
     */
    async function resetStateAndGoToExtensionSelection(confirmKey) {
        const confirmed = confirm(lang(confirmKey));

        if (confirmed) {
            try {
                // 상태 초기화 API 호출
                const response = await fetch(getStateApiUrl('reset'), {
                    method: 'POST',
                    headers: stateMutationHeaders(),
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
                // 단계별 실행은 떠나도 설치가 깨지지 않는다 — 다시 열면 그 지점부터 이어진다 (W3)
                if (currentInstallationMode === 'stepwise') {
                    return;
                }
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
                // 단계별 실행은 서버에 상주 워커가 없다 — 진행 중인 요청은 끝까지 돌고 상태 파일에
                // 기록되므로 중단 신호 없이 떠나도 되고, 다시 열면 그 지점부터 이어진다 (W3)
                if (currentInstallationMode === 'stepwise') {
                    return;
                }
                // 설치가 시작되었고, 아직 완료되지 않은 경우에만 abort 전송
                if (installationStarted && !installationCompleted) {
                    const apiUrl = `${window.location.origin}${window.INSTALLER_BASE_URL}/api/state-management.php?action=abort`;

                    // sendBeacon은 페이지 언로드 중에도 전송을 보장
                    // sendBeacon 은 헤더를 실을 수 없다 — 서버가 함께 읽는 본문 폴백으로 단계별 실행 토큰과
                    // 세션 CSRF 토큰을 넘긴다
                    const beaconBody = JSON.stringify({
                        token: getStepwiseToken() || '',
                        csrf_token: window.INSTALLER_CSRF || '',
                    });
                    navigator.sendBeacon(apiUrl, new Blob([beaconBody], {type: 'application/json'}));
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
     * [중단] 버튼의 내용을 만든다 (아이콘 + 라벨).
     *
     * 같은 마크업이 네 벌로 복제돼 있었다 — 초기 표시·중단 중·실패 복원 두 곳. 복제는 그
     * 자체로 재발원이다: 아이콘에 `aria-hidden` 을 다는 것 같은 수정이 한 벌만 반영되어도
     * 나머지 세 벌은 그대로 남는다(실제로 네 벌 모두 빠져 있었다 — 12차 #B32). 뜻은 옆의
     * 텍스트 라벨이 전하므로 아이콘은 장식이다.
     *
     * @param {boolean} aborting 중단 요청을 보내는 중인지 (true 면 스피너 + "중단하는 중")
     * @returns {string} 버튼 내부 HTML
     */
    function abortButtonMarkup(aborting = false) {
        const glyph = aborting
            ? '<circle class="spinner" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none"/>'
            : '<path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';

        return `
            <svg class="abort-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
                ${glyph}
            </svg>
            ${escapeHtml(lang(aborting ? 'aborting' : 'abort_installation'))}
        `;
    }

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
        // 시작 경로가 await 중이면 그 뒤 이어지는 처리를 멈춘다 (M14)
        installationCancelRequested = true;

        // 중단 버튼 비활성화 및 로딩 표시
        if (abortBtn) {
            abortBtn.disabled = true;
            abortBtn.innerHTML = abortButtonMarkup(true);
        }

        // **왜 기다리는지 말한다** (D6).
        //
        // 서버는 중단을 즉시 확정하지 않는다 — 되돌리기가 아직 실행 중인 태스크와 겹치지
        // 않도록 그 태스크가 경계에 닿을 때까지 기다린다(제품 결정 13차, state-management.php).
        // 그 대기는 태스크 길이만큼이라 실측에서 8.1 초였다. 버튼은 즉시 「중단 중」으로
        // 바뀌지만 그것만으로는 **왜** 오래 걸리는지 알 수 없고, 사용자에게는 D2 가 고친
        // 그 무응답과 같은 화면이 된다. 진행 기록에 한 줄 남긴다.
        addLogMessage(lang('abort_waiting_for_task'));

        try {
            // Abort API 호출
            const response = await fetch(getStateApiUrl('abort'), {
                method: 'POST',
                headers: stateMutationHeaders()
            });

            const result = await response.json();

            if (result.success) {
                // 진행 중인 모니터를 멈춘다 — 멈추지 않으면 SSE 는 스트림 종료 후 자동
                // 재연결하고, 폴링·단계별 실행은 다음 요청을 한 번 더 보낸다. 중단 화면도
                // 모니터의 onAbort 와 아래 지연 호출이 겹쳐 두 번 그려진다.
                if (currentMonitor) {
                    currentMonitor.stop();
                    currentMonitor = null;
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
                // 취소 표식도 되돌린다 (19차 J4). 되돌리지 않으면 시작 경로가 아직
                // await 중일 때 그 뒤 처리가 영영 멈추고, 화면에는 0% 카드와 [중단] 버튼만
                // 남아 탈출 경로가 사라진다 — 서버는 「진행 중이 아니다」로 거부했으므로
                // 이 시점의 설치는 실제로 시작되지 않은 상태다.
                installationCancelRequested = false;

                alert(lang('abort_failed') + ': ' + (result.message || lang('unknown_error_occurred')));

                // 버튼 복원
                if (abortBtn) {
                    abortBtn.disabled = false;
                    abortBtn.innerHTML = abortButtonMarkup();
                }
            }
        } catch (error) {
            // 모니터를 먼저 멈춘다 (19차 J3).
            //
            // 종전에는 성공 분기에만 `stop()` 이 있어, 중단 요청이 네트워크 오류·비-JSON 500
            // 으로 실패하면 **설치는 계속 도는데 실패 화면이 뜨고** 그 위에 모니터가
            // `onTaskStart`·`onLog`·나중엔 `onComplete` 를 덧그렸다.
            if (currentMonitor) {
                currentMonitor.stop();
                currentMonitor = null;
            }

            // 같은 규약 (11차 #B17) — 원문은 접기 영역으로.
            showError(lang('abort_error_occurred'), error && error.message ? String(error.message) : null);

            // 플래그 복원은 `showError()` **뒤**에 온다 (19차 J3).
            //
            // `showError()` 는 첫 줄에서 `installationCompleted = true` 로 되돌리고
            // `hideAbortButton()` 을 부른다. 종전에는 그 앞에서 복원해 두어 값이 곧바로
            // 되덮였고, 그 결과 `beforeunload` 경고와 `pagehide` 의 중단 비콘이 함께 꺼져
            // 탭을 닫아도 서버에 중단 신호가 가지 않았다.
            installationCompleted = false;

            // 버튼 복원 — `showError()` 가 숨긴 뒤이므로 표시 상태까지 되돌린다.
            showAbortButton();
            if (abortBtn) {
                abortBtn.disabled = false;
                abortBtn.innerHTML = abortButtonMarkup();
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
            abortBtn.innerHTML = abortButtonMarkup();
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
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
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
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
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

        if (!cardBody) {
            return;
        }

        // 수동 경로도 자동 경로와 같은 지점을 지난다 — 갈라 두면 한쪽만 `aria-expanded` 를
        // 갱신하는 상태로 되돌아간다 (12차 #B26).
        setInstallationCardCollapsed(cardBody.style.display !== 'none');
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
     * 태스크 요소에서 태스크 종류와 대상을 읽는다.
     *
     * DOM id 는 `task-{종류}-{대상}` 하이픈 결합이라 문자열만으로는 종류와 대상을 되나눌 수
     * 없다(확장 식별자에도 하이픈이 들어간다). 그래서 렌더링이 남긴 data 속성을 SSoT 로 쓰고,
     * 속성이 없는 평면 렌더링에서만 id 접두사를 떼는 방식으로 떨어진다.
     *
     * @param {HTMLElement} taskEl 태스크 요소
     * @returns {{taskId: string, target: string|null}} 태스크 종류와 대상
     */
    function taskIdentityFromElement(taskEl) {
        const taskId = taskEl.dataset?.taskId || taskEl.id.replace('task-', '');
        const target = taskEl.dataset?.target || null;

        return { taskId, target };
    }

    /**
     * 작업 상태를 통합 관리 (아이콘 + 클래스)
     *
     * @param {string} taskId 작업 ID
     * @param {string} status 상태 (active, completed, failed, aborted)
     * @param {string|null} target 확장 식별자 (옵션)
     */
    function setTaskStatus(taskId, status, target = null) {
        // 합성 키("module_install:sirsoft-board")를 그대로 받아도 같은 판정을 낸다 —
        // 대부분의 호출부는 미리 분리해 넘기지만 state.failed_task 를 그대로 넘기는
        // 새로고침 복원 경로가 있고, 분리하지 않으면 DOM id 가 어긋나 조용히 아무것도
        // 표시되지 않는다(실패 단계가 빨강이 되지 않는다). 롤백 디스패치와 같은 방식이다.
        if (target === null && taskId && taskId.includes(':')) {
            const parts = splitCompositeTaskKey(taskId);
            taskId = parts[0];
            target = parts[1];
        }

        // target이 있으면 조합된 ID, 없으면 기본 ID
        const domId = target ? `task-${taskId}-${target}` : `task-${taskId}`;
        const taskEl = document.getElementById(domId);
        if (!taskEl) {
            // 조용히 되돌아가면 서버가 도는 단계가 화면에 아예 없다는 사실이 어디에도 남지
            // 않는다 — 그 단계는 실패해도 빨강이 되지 않고 진행률 분모에서도 빠진다
            // (12차 #B24). 목록의 SSoT 는 서버이므로 여기서 고치지 않고 드러내기만 한다.
            //
            // 다만 **같은 단계에 한 번만** 경고한다 (D2 L10). 이 함수는 한 단계에 여러 번
            // 불리고(단계별은 응답마다 완료 목록 전량을 재적용한다) 상태마다 다시 불린다 —
            // 상한이 없으면 DevTools 가 열려 있는 환경에서 콘솔 폭주 자체가 프리즈 요인이 된다.
            // 사실을 드러내는 것이 목적이므로 한 번이면 충분하다.
            if (!warnedMissingTaskRows.has(domId)) {
                warnedMissingTaskRows.add(domId);
                console.warn(`[installer] 진행 목록에 없는 단계입니다: ${domId} (status=${status})`);
            }

            return;
        }

        const icon = taskEl.querySelector('.task-icon');

        // 기존 상태 클래스 제거
        taskEl.classList.remove('task-pending', 'task-active', 'task-completed', 'task-failed', 'task-aborted', 'task-skipped');

        // 새로운 상태 적용 — 모양·색과 **같은 뜻의 텍스트**를 함께 싣는다.
        //
        // 종전에는 아이콘만 갈아 끼웠다. 그러면 화면 판독기 사용자에게는 14단계가 전부 같은
        // 이름으로만 읽혀 어느 단계가 완료·실패·건너뜀인지 항목 수준에서 전달되지 않는다
        // (그룹 배지 텍스트만 남는다). 요구사항 화면(getStatusIcon)이 이미 쓰는 방식과 같다.
        // 색은 `.task-*` 규칙이 토큰으로 정한다 — 인라인 style 로 칠하면 다크 재정의가
        // 도달하지 못한다 (D16).
        const statusPresentation = {
            // pending 도 완전한 표현을 갖는다 (23차 F-1) — 22-4 의 되돌림이 이 함수를
            // 'pending' 으로 부르는데, 항목이 없으면 상태 클래스가 전부 벗겨진 「무상태」 행이
            // 되고 아이콘·sr-only 는 종전 「완료」가 그대로 남아 화면 판독기에 반대로 읽힌다.
            pending: { className: 'task-pending', icon: 'pending', labelKey: 'status_pending' },
            skipped: { className: 'task-skipped', icon: 'skipped', labelKey: 'status_skipped' },
            active: { className: 'task-active', icon: 'spinner', labelKey: 'status_in_progress' },
            completed: { className: 'task-completed', icon: 'completed', labelKey: 'status_completed' },
            failed: { className: 'task-failed', icon: 'failed', labelKey: 'status_failed' },
            aborted: { className: 'task-aborted', icon: 'warning', labelKey: 'status_aborted' },
        };

        const presentation = statusPresentation[status];
        if (presentation) {
            taskEl.classList.add(presentation.className);
            if (icon) {
                icon.innerHTML = getIconSvg(presentation.icon)
                    + `<span class="sr-only">${escapeHtml(lang(presentation.labelKey))}</span>`;
            }
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
        const skippedCount = group.querySelectorAll('.task-item.task-skipped').length;
        // 건너뛴 단계도 "더 이상 진행을 막지 않는다" 는 뜻에서 완료 수에 포함한다 —
        // 다만 그룹 표시는 아래에서 따로 가른다 (초록 "완료" 로 덮으면 안 된다).
        const completedCount = group.querySelectorAll('.task-item.task-completed, .task-item.task-skipped').length;
        const failedCount = group.querySelectorAll('.task-item.task-failed').length;
        const abortedCount = group.querySelectorAll('.task-item.task-aborted').length;
        const activeCount = group.querySelectorAll('.task-item.task-active').length;
        const totalCount = tasks.length;

        // 상태 클래스 초기화
        statusEl.classList.remove('status-pending', 'status-in-progress', 'status-completed', 'status-error', 'status-aborted', 'status-skipped');

        if (failedCount > 0) {
            statusEl.textContent = lang('status_failed');
            statusEl.classList.add('status-error');
        } else if (abortedCount > 0) {
            statusEl.textContent = lang('status_aborted');
            statusEl.classList.add('status-aborted');
        } else if (completedCount === totalCount && skippedCount > 0) {
            // 건너뛴 단계가 있으면 초록 "완료" 로 덮지 않는다 — 운영자가 무엇이 빠졌는지
            // 알아야 한다. 접지도 않는다(접으면 그 단계가 화면에서 사라진다).
            statusEl.textContent = lang('status_completed_with_skipped').replace(':skipped', skippedCount);
            statusEl.classList.add('status-skipped');
            setTaskGroupCollapsed(group, false);
        } else if (completedCount === totalCount) {
            statusEl.textContent = lang('status_completed');
            statusEl.classList.add('status-completed');
            // 완료된 그룹은 자동으로 접기
            setTaskGroupCollapsed(group, true);
        } else if (activeCount > 0 || completedCount > 0) {
            statusEl.textContent = `${completedCount}/${totalCount}`;
            statusEl.classList.add('status-in-progress');
            // 진행 중인 그룹은 펼치기
            setTaskGroupCollapsed(group, false);
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
            // 수동 경로도 자동 경로와 같은 지점을 지난다 — 갈라 두면 한쪽만 `aria-expanded` 를
            // 갱신하는 상태로 되돌아간다 (12차 #B25).
            setTaskGroupCollapsed(group, !group.classList.contains('collapsed'));
        }
    };

    /**
     * role="button" 인 div 트리거(작업 그룹 머리·설치 진행 카드 머리)의 키보드 조작 — Enter·Space 를 클릭으로 바꾼다.
     *
     * 종전에는 요소마다 인라인 onkeydown 이 맡았는데, strict CSP(`script-src 'self'`)에서는 on* 속성이
     * 실행되지 않는다. 클릭은 installer-boot.js 의 data-action 위임이 받으므로 여기서는 같은 요소에
     * click 을 한 번 내기만 한다 — 동작 경로를 클릭 하나로 둔다. 네이티브 button 은 브라우저가 이미 처리한다.
     */
    document.addEventListener('keydown', function(event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const target = event.target;
        if (!target || !target.closest) return;
        const trigger = target.closest('[role="button"][data-action]');
        if (!trigger || trigger !== target || trigger.tagName === 'BUTTON') return;
        event.preventDefault();
        trigger.click();
    });

    /**
     * 공통 아이콘 SVG 생성
     *
     * @param {string} type 아이콘 타입 (completed, failed, warning, spinner)
     * @param {string} size 아이콘 크기 (기본값: 20px, 옵션: 24px 등)
     * @param {string} className 추가 CSS 클래스
     * @return {string} SVG HTML 문자열
     */
    function getIconSvg(type, size = '20px', className = '') {
        // 장식 아이콘이다 — `aria-hidden` 으로 보조기술에서 감추고, 뜻은 호출부가 함께 싣는
        // 형제 `.sr-only` 텍스트가 전한다 (요구사항 화면 getStatusIcon · 진행 목록 setTaskStatus).
        // 감추는 속성 없이 주석으로만 「장식」이라 적어 두면 판독기는 이름 없는 도형을 한 번 더
        // 읽고, 상태는 여전히 전달되지 않는다 (10차).
        const svgs = {
            completed: `
                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" style="width: ${size}; height: ${size};" class="${className}">
                    <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2" fill="none"/>
                    <path d="M6 10l3 3 5-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            `,
            failed: `
                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" style="width: ${size}; height: ${size};" class="${className}">
                    <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2" fill="none"/>
                    <path d="M7 7l6 6M13 7l-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            `,
            warning: `
                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" style="width: ${size}; height: ${size};" class="${className}">
                    <path d="M10 2L2 17h16L10 2z" stroke="currentColor" stroke-width="2" stroke-linejoin="round" fill="none"/>
                    <path d="M10 8v4M10 14v.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            `,
            info: `
                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" style="width: ${size}; height: ${size};" class="${className}">
                    <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2" fill="none"/>
                    <path d="M10 9v5M10 6v.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            `,
            // 「건너뜀」 전용 — 종전에는 중단과 같은 경고 삼각형을 썼다. 두 상태는 W3 한 화면에
            // 공존할 수 있는데(선택 확장을 건너뛰고 이어가다 중단), 글리프·문구색·라이트 배경이
            // 모두 같아 사실상 구분되지 않았다. 건너뛰기는 경고가 아니라 「지나쳤다」이므로
            // 앞으로 넘어가는 화살표로 그린다.
            skipped: `
                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" style="width: ${size}; height: ${size};" class="${className}">
                    <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2" fill="none"/>
                    <path d="M6 6.5l4 3.5-4 3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M13.5 6.5v7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            `,
            spinner: '<div class="task-icon-spinner"></div>',
            // 최초 렌더(아직 시작하지 않은 단계)용 — 종전에는 평면 렌더가 `⏳` 이모지를,
            // 그룹 렌더가 aria 속성 없는 raw <svg> 를 그렸다. 둘 다 텍스트 대체가 없어
            // 화면 판독기는 14개 항목을 "이름 없는 그래픽 + 단계명" 으로만 읽었다 (11차 #B13).
            pending: `
                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" style="width: ${size}; height: ${size};" class="${className}">
                    <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="2" fill="none"/>
                </svg>
            `
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
        window.skipFailedTaskAndContinue = skipFailedTaskAndContinue;
        window.goBackToExtensionSelection = goBackToExtensionSelection;

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
    /**
     * 복구 토큰으로 설치 소유권 되찾기 (소유권 잠금 화면)
     *
     * @param {Event} event submit 이벤트
     * @returns {boolean} 기본 폼 제출 방지용 false
     */
    async function submitRecoveryToken(event) {
        if (event) event.preventDefault();

        const input = document.getElementById('recovery-token-input');
        const button = document.getElementById('recovery-submit-btn');
        const resultBox = document.getElementById('recovery-result');
        if (!input || !resultBox) return false;

        const showResult = (message, isSuccess) => {
            resultBox.textContent = message;
            resultBox.classList.remove('hidden', 'alert-error', 'alert-success');
            resultBox.classList.add(isSuccess ? 'alert-success' : 'alert-error');
        };

        if (button) button.disabled = true;

        try {
            const response = await fetch(getStateApiUrl('reclaim'), {
                method: 'POST',
                // 상태 관리 엔드포인트로 가는 변경 요청은 한 헤더 헬퍼를 지난다 — 단계별 실행 토큰은
                // 소유권을 잃은 세션에 없으므로 빈 값으로 실리고, 회수는 CSRF·복구 토큰으로 판정한다.
                headers: stateMutationHeaders(),
                body: JSON.stringify({ recovery_token: input.value }),
            });
            const result = await response.json();

            if (result.success) {
                showResult(result.message || lang('recovery_success'), true);
                setTimeout(() => { window.location.href = `${window.INSTALLER_BASE_URL}/`; }, 1500);
                return false;
            }

            showResult(result.message || lang('error_recovery_token_invalid'), false);
        } catch (error) {
            // 브라우저 원문(비-JSON 응답의 `Unexpected token '<'` 등)은 결과 문구에 보간하지 않는다 —
            // 운영자에게는 번역 문구, 원문은 진단용 콘솔로만 (§12.0 W3).
            showResult(lang('unknown_error_occurred'), false);
            console.warn('[installer] recovery token request failed:', error);
        }

        if (button) button.disabled = false;
        return false;
    }
    window.submitRecoveryToken = submitRecoveryToken;

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
                setEnvSetupReason(lang('file_setup_description'));
                showFileSetupSection(missingFiles, commands, relCommands, basePath, isWin);
                return false;
            }

            // 서버는 3상태를 낸다 — 없음 / 있으나 쓰기 불가 / 있고 쓰기 가능 (19차 S-2).
            // 종전에는 `env_exists` 만 읽어 가운데 상태가 통과했다. `.env` 쓰기는 설치 **중**이
            // 아니라 끝난 뒤 finalize 에서 한 번 일어나고 실패해도 조용히 return 하므로,
            // 14단계가 전부 초록으로 끝나고 「설치 완료」가 뜨는데 `.env` 는 설치 전 그대로다.
            // `_guard.php` 는 `.env` 의 `INSTALLER_COMPLETED` 로만 판정하므로 인스톨러가
            // **영구히 열린 채** 남는다 — 실패도 경고도 없다.
            //
            // 다만 여기가 **유일한** 지점은 아니다(종전 문언 정정, 8차 G3 라운드): 같은 술어의
            // 서버 사본이 `public/install/api/install-process.php` 에 있다. 아래 `catch` 가 통과로 떨어지는 것은
            // 그 사본이 받아 주기 때문이고, 그 사본의 독블록이 「화면만이 유일한 지점이면
            // fetch 예외가 곧 우회로가 된다」로 그 관계를 적고 있다. 이 문단을 「유일」로 두면
            // 다음 사람이 fail-open 을 결함으로 읽고 화면 쪽만 fail-closed 로 바꾼다 —
            // 그러면 점검 API 가 잠깐 실패한 정상 환경의 설치가 막힌다.
            if (check.env_writable === false) {
                renderEnvNotWritableSetup(basePath, isWin, relativePath);

                return false;
            }

            return true;
        } catch (error) {
            // 체크 API 실패 시 설치 진행 허용 — 서버 사본(`public/install/api/install-process.php` 의
            // `.env` 쓰기 가능 재검사)이 같은 술어로 다시 막는다.
            return true;
        }
    }

    /**
     * 파일 안내 섹션의 사유 문단을 바꾼다 (19차 S-2).
     *
     * 「없다」와 「있는데 쓸 수 없다」는 조치가 다르다 — 하나는 생성, 하나는 권한이다.
     * 한 문구로 뭉개면 운영자가 `cp` 를 다시 실행하고 같은 자리에 머문다.
     *
     * @param {string} text 사유 문구
     */
    function setEnvSetupReason(text) {
        const reason = document.getElementById('env-setup-reason');
        if (reason) reason.textContent = text;
    }

    /**
     * 「.env 가 있는데 쓸 수 없다」 안내를 그린다 — 사유·권한 명령·목록을 한 지점에서 (23차 F-2).
     *
     * 사전 점검(checkFilesBeforeInstall)·[파일 확인] 재검사·서버 시작 게이트 거부 응답의
     * 세 소비처가 이 헬퍼를 공유한다. 지점마다 손으로 그리면 서버 게이트 거부처럼 새로
     * 생긴 소비처가 빈 패널(파일명·명령·사유 없음)을 띄우는 형태가 재발한다.
     *
     * @param {string|null} basePath 프로젝트 절대 경로 (모르면 null — 상대 명령만 표시)
     * @param {boolean} isWin Windows 여부
     * @param {string} [relativePath] 상대 경로 표시용 (기본 '.')
     */
    function renderEnvNotWritableSetup(basePath, isWin, relativePath = '.') {
        setEnvSetupReason(lang('file_setup_not_writable'));

        const commands = [];
        const relCommands = [];

        if (isWin) {
            if (basePath) {
                const winBase = String(basePath).replace(/\//g, '\\');
                commands.push(`icacls ${winBase}\\.env /grant *S-1-5-32-545:M`);
            }
            relCommands.push('icacls .\\.env /grant *S-1-5-32-545:M');
        } else {
            if (basePath) {
                commands.push(`chmod 664 ${basePath}/.env`);
            }
            relCommands.push(`chmod 664 ${relativePath}/.env`);
        }

        showFileSetupSection(['.env'], commands.length ? commands : relCommands, relCommands, basePath, isWin);
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

        // 안내 섹션으로 스크롤 (목록·명령어를 채운 뒤에 호출)
        scrollResultIntoView(section);
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

            // 판정은 사전 점검(checkFilesBeforeInstall)과 **같은 술어**여야 한다 (22차 #22-1) —
            // 종전에는 존재만 봐서, 「쓰기 불가」로 띄운 게이트를 그 게이트의 [파일 확인]
            // 버튼이 스스로 무력화했다. `.env` 쓰기는 finalize 에서 한 번 일어나고 실패해도
            // 조용히 return 하므로, 여기가 새면 설치가 완주하고도 인스톨러가 영구히 열린다.
            if (check.env_exists && check.env_writable !== false) {
                // 파일 확인 완료 — 안내 섹션 숨기고 설치 시작
                const section = document.getElementById('env-setup-section');
                if (section) {
                    section.classList.add('hidden');
                }
                startInstallation();
            } else {
                // 아직 파일 미생성/쓰기 불가 — 안내 유지
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = lang('file_check_button');
                }

                // 여전히 누락/쓰기 불가한 파일 목록 갱신. 「부재 → 쓰기 불가」로 상태가
                // 바뀌었으면 사유 문단·권한 명령까지 함께 갈아 끼운다 (23차 4c) — 목록만
                // 바꾸면 `cp` 명령과 「파일을 찾을 수 없습니다」 문구가 남아 운영자가
                // 생성을 반복한다 (19차 S-2 의 원래 증상).
                if (check.env_exists && check.env_writable === false) {
                    renderEnvNotWritableSetup(null, check.is_windows || false);
                }
                const stillMissing = [];
                if (!check.env_exists) stillMissing.push('.env');
                else if (check.env_writable === false) stillMissing.push('.env (' + lang('env_not_writable_label') + ')');

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
