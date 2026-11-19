<?php

/**
 * 웹 노출 자가 진단 (B-4) — 프로브 대상 산출 + symlink 가용 실측.
 *
 * 문서 루트가 프로젝트 루트를 가리키는 배치(레이아웃 ②)에서 보호 설정(.htaccess)이
 * 동작하지 않으면 `.env`·storage·의존성 폴더가 그대로 웹에 노출된다. Step 2 는 이를
 * 실제 HTTP 응답으로 판정하고, 노출 확정 시 설치를 차단한다 (D3).
 *
 * ## 판정은 브라우저가 한다
 *
 * 서버가 자기 URL 로 loopback 프로브를 하면 nginx vhost·SSL·프록시 체인을 우회하거나
 * 다른 vhost 를 타서 실제 방문자가 겪는 것과 다른 답이 나온다 — 자산 URL 모드 감지
 * (`checkAssetUrlMode`)와 같은 이유·같은 구조로, 서버는 **프로브 대상 목록**만 내려주고
 * 실제 요청·판정은 브라우저(`installer.js::refreshWebExposureCard`)가 수행한다.
 * 브라우저 프로브가 실패(네트워크 예외)하면 판정 불능으로 떨어지고, 그 경우 운영자
 * 수동 확인 절차(직접 열어볼 주소 목록 + 확인 체크)로 진행한다 (D21).
 *
 * ## 비밀은 프로브에 싣지 않는다 (R3)
 *
 * `.env` 원문(자격증명)은 어떤 응답에도 싣지 않는다. `.env` 계열의 차단 여부는 내용이
 * 공개인 `.env.example` 로 같은 패턴 계열을 판정하고, storage 계열은 이 검사 전용으로
 * 생성한 무의미 난수 sentinel 파일로 판정한다.
 */

// 자기 의존성을 스스로 로드한다 — 형제 파일(`installer-runtime.php`·`installer-state.php`·
// `session.php`·`task-runner.php`·`artisan-executor.php`)과 같은 형태·같은 사유다.
// 이 파일은 `installerHasFunction()`(가드 판정 SSoT)과 `installerProbeRateLimitExceeded()` 를
// 쓰는데 둘 다 `execution-mode.php` 소유다. 현재 진입점 셋이 우연히 그 전에 로드하고 있어
// 동작하지만, 순서가 바뀌거나 이 파일만 단독으로 로드하는 경로가 하나 생기면
// `Call to undefined function` 으로 죽는다 — 그중 하나는 되돌리기가 가장 어려운 finalize 다.
require_once __DIR__.'/execution-mode.php';
// 임시 파일 **이름** 생성의 SSoT(`installerUniqueSuffix`)는 `functions.php` 소유다.
// 그 파일은 최상위에 함수 정의만 두므로(전부 `function_exists` 가드) 로드에 부수효과가
// 없고, 이 파일이 그 헬퍼를 쓰는 이상 스스로 확보하는 것이 위 규약과 같은 형태다.
require_once __DIR__.'/functions.php';

if (! defined('INSTALLER_EXPOSURE_PROBE_PREFIX')) {
    /** 노출 점검 sentinel 파일 접두사 (정리 스윕이 이 접두사 전체를 본다). */
    define('INSTALLER_EXPOSURE_PROBE_PREFIX', 'exposure-probe-');
}

if (! defined('INSTALLER_SYMLINK_PROBE_PREFIX')) {
    /** 링크 실측 임시 파일 접두사 (정리 스윕이 이 접두사 전체를 본다). */
    define('INSTALLER_SYMLINK_PROBE_PREFIX', 'symlink-probe-');
}

if (! function_exists('installerListExposureProbeFiles')) {
    /**
     * 프로브 임시 파일을 나열합니다 — **`glob` 없이도** 동작한다.
     *
     * 종전에는 나열이 `glob()` 하나였고, 그 함수가 막힌 호스팅에서는 스윕도 완주 정리도
     * 전부 무동작이었다. 그런데 **sentinel 생성은 그 조건과 무관하게 계속되므로**,
     * 무인증 `?action=requirements` 반복이 `storage/installer` 에 상한 없이 쌓였다.
     * 총량 방어를 「진입 스윕」에 맡긴다고 적어 두고 그 스윕이 바로 그 환경에서
     * 사문이었다 — 그리고 그 호스팅이 곧 G3 가 대상으로 삼는 배치다.
     *
     * 대상 이름이 전부 고정 접두사 + 난수라 패턴 매칭이 필요 없다. `scandir` 도 막히면
     * 그때는 나열 수단이 없으므로 「쓸지 못했다」를 호출부가 보고한다.
     *
     * @param  string  $dir  프로브 디렉토리
     * @return array{files: array<int, string>, listed: bool} 절대 경로 목록과 나열 가능 여부
     */
    function installerListExposureProbeFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return ['files' => [], 'listed' => true];
        }

        if (! installerHasFunction('scandir')) {
            return ['files' => [], 'listed' => false];
        }

        $entries = @scandir($dir);

        if ($entries === false) {
            return ['files' => [], 'listed' => false];
        }

        // 쓰기 실측 프로브(`functions.php` 소유)도 같은 디렉토리에 남을 수 있다.
        $prefixes = [INSTALLER_EXPOSURE_PROBE_PREFIX, INSTALLER_SYMLINK_PROBE_PREFIX, INSTALLER_WRITE_PROBE_PREFIX];
        $files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            foreach ($prefixes as $prefix) {
                if (str_starts_with($entry, $prefix)) {
                    $files[] = $dir.DIRECTORY_SEPARATOR.$entry;
                    break;
                }
            }
        }

        return ['files' => $files, 'listed' => true];
    }
}

if (! function_exists('installerExposureProbeDir')) {
    /**
     * 노출/링크 실측 임시 파일이 놓이는 디렉토리를 돌려줍니다.
     *
     * @param  string|null  $basePath  프로젝트 루트 (기본 BASE_PATH)
     * @return string 디렉토리 절대 경로
     */
    function installerExposureProbeDir(?string $basePath = null): string
    {
        return rtrim($basePath ?? BASE_PATH, '/\\').'/storage/installer';
    }
}

if (! function_exists('installerExposureControlMarker')) {
    /**
     * 양성 대조 파일 내용에서 마커로 쓸 첫 식별 문자열을 찾습니다.
     *
     * 리터럴을 코드에 박지 않고 **파일에서 뽑는다** — 박아 두면 그 파일이 바뀌었을 때
     * 대조가 조용히 실패하고(= 상시 판정 불능), 그 실패가 결함과 구분되지 않는다.
     *
     * @param  string  $content  대조 파일 내용
     * @return string|null 마커 문자열. 적합한 줄이 없으면 null
     */
    function installerExposureControlMarker(string $content): ?string
    {
        foreach (preg_split('/\r\n|\r|\n/', substr($content, 0, 4096)) ?: [] as $line) {
            $line = trim(ltrim(trim($line), '/*#'));
            if (preg_match('/^([A-Za-z0-9][A-Za-z0-9 _.-]{11,63})$/', $line, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}

if (! function_exists('installerExposureControlPlan')) {
    /**
     * **양성 대조**(positive control) 대상을 산출합니다.
     *
     * 노출 점검은 「403/404 = 보호됨」으로 읽는다. 그런데 404 는 두 가지를 뜻한다 —
     * 「막혔다」와 **「그 주소에 애초에 아무것도 없다」**. 후자를 가려내지 못하면 다음
     * 상황이 전부 「보호 확인됨」 초록으로 수렴한다:
     *
     *  - **위임이 동작하지 않는 서버**(mod_rewrite 부재·AllowOverride 차단). 레이아웃 ②는
     *    루트 `.htaccess` 의 위임으로 성립하는데, 그것이 죽으면 프로젝트 루트의 모든 주소가
     *    404 다 — 점검 대상 셋도 함께. 화면은 초록인데 설치를 마치면 `/` 밖의 전 라우트와
     *    전 자산이 404 인 사이트가 된다.
     *  - **기준 주소 오도출**. 프로젝트 디렉토리 이름이 실제로 `public` 인 배치나 프록시가
     *    접두를 벗기는 배치에서는 프로브가 엉뚱한 위치를 재고, 그 404 를 보호로 읽는다.
     *
     * 그래서 「반드시 200 으로 열려야 하는 주소」를 하나 같이 잰다. 대상은 인스톨러 자신의
     * 정적 자산이며 **프로젝트 루트 기준 경로**(`/install/assets/...`)로 표기한다 — 그 주소가
     * 열린다는 것은 위임이 살아 있고 기준 주소도 맞다는 뜻이고, 열리지 않으면 이번 측정
     * 전체를 신뢰할 수 없다는 뜻이다(D21 판정 불능 경로).
     *
     * 노출 확정은 이 대조보다 **우선한다** — 대조가 실패해도 `.env` 가 원문 그대로 200 이면
     * 그것은 노출이다. 그 우선순위는 판정을 하는 화면이 지킨다.
     *
     * @param  string|null  $basePath  프로젝트 루트 (기본 BASE_PATH — 테스트 주입용)
     * @return array{controls: array<int, array{path: string, marker: string, label: string}>, unmeasured: array<int, array{label: string, reason: string}>}
     */
    function installerExposureControlPlan(?string $basePath = null): array
    {
        $basePath = rtrim($basePath ?? BASE_PATH, '/\\');
        $controls = [];

        $asset = $basePath.'/public/install/assets/css/installer.css';

        if (! is_file($asset)) {
            // 형제 계열(`targets`)은 준비 실패를 전부 `unmeasured` 로 승격하는데 대조만
            // 조용히 빈 배열이었다. 빈 배열은 화면에서 「대조할 것이 없다」로 읽혀 대조
            // 분기를 통째로 건너뛰므로, 위임이 죽은 서버가 다시 「보호 확인됨」이 된다.
            return ['controls' => [], 'unmeasured' => [['label' => 'install/assets', 'reason' => 'missing']]];
        }

        $marker = installerExposureControlMarker((string) file_get_contents($asset));

        if ($marker === null) {
            return ['controls' => [], 'unmeasured' => [['label' => 'install/assets', 'reason' => 'no_marker']]];
        }

        $controls[] = [
            'path' => '/install/assets/css/installer.css',
            'marker' => $marker,
            'label' => 'install/assets',
        ];

        return ['controls' => $controls, 'unmeasured' => []];
    }
}

if (! function_exists('installerExposureAbsentControl')) {
    /**
     * **음성 대조**(negative control) 대상을 산출합니다.
     *
     * 보호를 증명하는 응답은 접근 거부(403)와 부재(404)뿐이라는 규칙은 옳지만, 그것만으로는
     * 레이아웃 ②의 정상 배치에서 **자동 판정이 성립하지 않는다.** 그 배치에서는 루트
     * `.htaccess` 가 모든 주소를 앱으로 위임하므로, 파일이 서빙되지 않는 주소도 200 HTML
     * (앱 화면)로 돌아온다 — P0 환경 실측: `/vendor/autoload.php` · `/storage/...` 가 둘 다
     * `HTTP 200 text/html`. 마커는 없으니 노출은 아닌데 403/404 도 아니라 「판정 불능」이 되고,
     * 그러면 D3 자동 차단이 **모든 배치에서** D21(운영자 직접 확인)로 강등된다.
     *
     * 그래서 「이 서버는 **파일이 없는 주소**를 어떻게 돌려주는가」를 함께 잰다. 대상은 매번
     * 새로 만드는 난수 경로라 디스크에 존재할 수 없다. 그 응답과 **같은 형태**로 돌아온 대상은
     * 파일이 서빙되지 않았다는 뜻이므로 보호된 것으로 읽는다.
     *
     * 인증 가로채기(oauth2-proxy·캡티브 포털)가 전 주소에 200 로그인 화면을 내리는 배치는
     * 이 대조로 통과하지 않는다 — 그런 서버에서는 **양성 대조**(반드시 200 + 마커여야 하는
     * 인스톨러 자산)가 먼저 실패해 측정 전체가 판정 불능으로 떨어지기 때문이다. 두 대조가
     * 함께 서야 자동 판정이 성립한다.
     *
     * @return array{path: string, label: string} 음성 대조 대상 (마커 없음 — 형태만 잰다)
     */
    function installerExposureAbsentControl(): array
    {
        return [
            // 확장자를 두지 않는다 — 서버·앱이 확장자로 응답을 가르는 배치가 있어
            // (실측: `.json` 은 404, 확장자 없는 주소는 200 앱 화면), 대조가 대상과 다른
            // 취급을 받으면 그 대조는 기준이 되지 못한다. 대상 계열과 같은 취급을 받도록
            // 확장자 없는 경로로 잰다.
            // 이름은 **비밀이 아니다** — 같은 서버에서 겹치지 않기만 하면 되고, 이 대조가
            // 재려는 것은 「파일이 없는 주소의 응답 형태」다. 그래서 CSPRNG 폴백을 갖춘
            // 형제 SSoT 를 쓴다.
            //
            // 무가드 `random_bytes()` 를 직접 부르면 안 되는 자리다: 이 함수는 형제 경로의
            // `no_random` **catch 블록 안에서도** 불리므로, CSPRNG 를 못 얻는 호스팅에서는
            // 그 두 번째 던짐을 받을 곳이 없어 요구사항 응답 전체가 500 이 되고, 일부러
            // 만든 `no_random` 강등 경로와 그 다국어 문구가 통째로 사문이 된다.
            'path' => '/g7-exposure-absent-'.installerUniqueSuffix(8),
            'label' => 'absent',
        ];
    }
}

if (! function_exists('installerExposureProbePlan')) {
    /**
     * 브라우저가 프로브할 대상 목록과 **재지 못한 계열**을 함께 산출합니다.
     *
     * 반환은 세 갈래다:
     *  - `targets`: 실제로 잴 수 있는 대상 (`path`·`marker`·`label`) — **막혀 있어야** 한다
     *  - `unmeasured`: 준비에 실패해 **한 번도 재지 못한** 계열 (`label`·`reason`)
     *  - `controls`: 양성 대조 (`installerExposureControlPlan`) — **열려 있어야** 한다
     *
     * 두 갈래로 나누는 이유는 fail-open 이다. 종전에는 준비에 실패한 계열이 목록에서
     * 조용히 빠졌고, 남은 두 계열이 403 이면 화면이 「보호 확인됨」으로 초록이 됐다 —
     * storage 계열은 **한 번도 재지 않았는데도**. 재지 못한 축이 하나라도 있으면 그것은
     * 「안전」이 아니라 판정 불능(D21)이며, 그 판정은 목록을 받은 화면이 한다.
     *
     * 디스크에 없는 파일은 `targets` 에서 제외된다 — 존재하지 않는 파일의 404 는 보호를
     * 증명하지 않기 때문이다. 다만 **배포본에 언제나 실리는 파일**(`.env.example` ·
     * `composer.json` · `vendor/autoload.php`)이 없다면 그것은 「원래 없다」가 아니라 그
     * 계열을 재지 못했다는 뜻이므로 `unmeasured` 다. 셋은 각각 숨김 파일 · 확장자(`*.json`)
     * · 디렉토리 축을 재는 **유일한** 프로브라, 하나라도 조용히 빠지면 그 축이 죽은 서버에서도
     * 나머지의 403 만으로 화면이 「보호 확인됨」이 된다.
     * 배포 동선에 따라 있을 수도 없을 수도 있는 것(`.git/`)만 조용히 빠진다.
     *
     * 반환에는 **음성 대조**(`absent_control`)도 실린다 — 「없는 파일의 주소가 무엇을
     * 돌려주는가」를 재는 축이다. 그 값이 없으면 레이아웃 ② 정상 배치에서 자동 판정이
     * 성립하지 않아, 자동 차단(D3)이 전 배치에서 운영자 확인(D21)으로 강등된다. 종전
     * 서술은 「세 갈래」만 적어 그 키를 소비처가 읽지 않을 근거를 주고 있었다.
     *
     * @param  string|null  $basePath  프로젝트 루트 (기본 BASE_PATH — 테스트 주입용)
     * @return array{targets: array<int, array{path: string, marker: string, label: string}>, unmeasured: array<int, array{label: string, reason: string}>, controls: array<int, array{path: string, marker: string, label: string}>, absent_control: array{path: string, label: string}}
     */
    function installerExposureProbePlan(?string $basePath = null): array
    {
        $basePath = rtrim($basePath ?? BASE_PATH, '/\\');
        $targets = [];

        // 대조 계열의 준비 실패도 `unmeasured` 로 승계한다 — 형제 계열과 같은 규칙이다.
        $controlPlan = installerExposureControlPlan($basePath);
        $controls = $controlPlan['controls'];
        $unmeasured = $controlPlan['unmeasured'];

        // 0) 프록시가 알려 준 접두사(`X-Forwarded-Prefix`)를 정제기가 버렸다면 기준 주소가
        // 마운트 밖일 수 있다 — 그 상태의 프로브는 전부 404 를 받아 「보호 확인됨」으로
        // 수렴한다. 버린 사실은 종전에 어디에도 남지 않았다. 사유·길이만
        // 기록하고 값은 싣지 않는다. 브라우저가 기준 주소를 잡는 축이라 `label` 은 헤더명이다.
        if (function_exists('installerForwardedPrefixRejection') && installerForwardedPrefixRejection() !== null) {
            $unmeasured[] = ['label' => 'X-Forwarded-Prefix', 'reason' => 'forwarded_prefix_rejected'];
        }

        // 1) `.env` 계열 (숨김 파일 차단 패턴) — 내용이 공개인 .env.example 로 판정
        //
        // `.env.example` 은 **배포본에 언제나 실린다.** 따라서 그것이 없다는 것은 「원래 없는
        // 파일」이 아니라 운영자가 `.env` 로 이름을 바꿨다는 뜻이고(흔한 설치 절차), 그러면
        // 숨김 파일 축을 **한 번도 재지 못한** 상태가 된다. 종전에는 그 계열이 목록에서 조용히
        // 빠져 나머지 두 계열만으로 「보호 확인됨」이 됐다 — `*.json`·`storage/` 만 막고 dotfile
        // 은 안 막는 규칙(수기 nginx)에서 `.env` 가 노출인데 초록이었다.
        $envExample = $basePath.'/.env.example';
        if (is_file($envExample)) {
            $marker = installerExposureFirstEnvLine((string) file_get_contents($envExample));
            if ($marker !== null) {
                $targets[] = ['path' => '/.env.example', 'marker' => $marker, 'label' => '.env'];
            } else {
                $unmeasured[] = ['label' => '.env', 'reason' => 'no_marker'];
            }
        } else {
            $unmeasured[] = ['label' => '.env', 'reason' => 'missing'];
        }

        // 1-b) `.git/` — 저장소를 그대로 배포하는 동선(git clone)에서만 존재한다. 있으면 반드시
        // 재야 한다: 루트 `.htaccess` 의 숨김 파일 차단은 **파일 이름만** 보므로 `/.git/config`
        // (basename `config`)를 막지 못하고, 그 사각은 rewrite 부재 방어선에서만 닫힌다.
        $gitHead = $basePath.'/.git/HEAD';
        if (is_file($gitHead)) {
            $head = trim((string) file_get_contents($gitHead));

            // 마커는 **원문이 아니라 고정 접두**다. 원문(`ref: refs/heads/<브랜치>`)을 응답에
            // 실으면 무인증 화면이 그 사이트의 브랜치 이름을 알려 주게 된다 — 서빙 여부를
            // 판정하는 데는 파일의 고정 부분만으로 충분하다(R3 의 취지: 프로브에 알 필요
            // 없는 정보를 싣지 않는다). 접두를 갖지 않는 HEAD(detached — 커밋 해시)는
            // 판정 근거가 없으므로 재지 못한 것으로 둔다.
            if (str_starts_with($head, 'ref: refs/')) {
                $targets[] = ['path' => '/.git/HEAD', 'marker' => 'ref: refs/', 'label' => '.git/'];
            } else {
                $unmeasured[] = ['label' => '.git/', 'reason' => 'no_marker'];
            }
        }

        // 1-c) `vendor/` — 의존성 원본. rewrite 부재 방어선이 `mod_alias` 안에 있어 그 모듈이
        // 없는 서버에서는 경로 차단이 통째로 무효가 된다. 그때 프로브 3계열만 보면 전부 통과할
        // 수 있으므로 이 계열도 직접 잰다. 마커는 공개 파일의 내용(autoload 표식)이라 비밀이 없다.
        //
        // 없으면 **재지 못한 계열**이다 — 조용히 빠지지 않는다. `.git/`(배포 동선에 따라 있을
        // 수도 없을 수도 있다)와 달리 이 계열은 배포본에 언제나 실리며, 무CLI 동선
        // (`vendor-bundle.zip`)에서는 이 화면 시점에 아직 풀리지 않았을 뿐이다. 그 배치가
        // 바로 이 점검이 대상으로 하는 호스팅인데, 종전에는 그 계열을 **언제나 재지 않으면서**
        // 화면에 한 줄도 뜨지 않아 「보호 확인됨」에 그대로 묻혔다 (형제 계열 `.env` 는 같은
        // 사유로 승격한다).
        $vendorAutoload = $basePath.'/vendor/autoload.php';
        if (is_file($vendorAutoload)) {
            $targets[] = [
                'path' => '/vendor/autoload.php',
                'marker' => 'ComposerAutoloaderInit',
                'label' => 'vendor/',
            ];
        } else {
            $unmeasured[] = ['label' => 'vendor/', 'reason' => 'not_deployed'];
        }

        // 2) 일반 프로젝트 루트 파일 — composer.json 의 패키지 이름을 마커로 (항상 배포됨)
        $composerJson = $basePath.'/composer.json';
        if (is_file($composerJson)) {
            $decoded = json_decode((string) file_get_contents($composerJson), true);
            $name = is_array($decoded) ? ($decoded['name'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $targets[] = ['path' => '/composer.json', 'marker' => $name, 'label' => 'composer.json'];
            } else {
                $unmeasured[] = ['label' => 'composer.json', 'reason' => 'no_marker'];
            }
        } else {
            // 형제 둘(`.env.example` · `vendor/autoload.php`)과 같은 규칙이다. 이 파일은
            // 배포본에 언제나 실리므로 부재는 「원래 없다」가 아니라 그 계열을 재지 못했다는
            // 뜻이고, 이것이 **확장자 축(`*.json`)을 재는 유일한 프로브**라 조용히 빠지면
            // 확장자 방어선이 통째로 죽은 서버에서도 남은 계열의 403 만으로 「보호 확인됨」이
            // 된다. 재지 못한 축은 안전이 아니라 판정 불능(D21)이다.
            $unmeasured[] = ['label' => 'composer.json', 'reason' => 'missing'];
        }

        // 3) storage 계열 — 이 검사 전용 난수 sentinel
        //
        // 파일명은 **매 호출 고유**여야 한다. 이름을 고정하고 마커만 새로 만들면 탭 두 개나
        // [다시 검사] 중복 호출이 겹칠 때 먼저 받은 화면의 마커가 무효가 되고, 그 화면은
        // 파일이 **실제로 서빙되는데도**(= 노출) 마커 불일치를 「보호 확인됨」으로 읽는다 —
        // D3 의 판정이 정확히 반대로 뒤집힌다. 고유 이름이면 대상이 서로 달라 간섭 자체가
        // 성립하지 않는다. 늘어나는 잔여물은 접두사 스윕(installerCleanupExposureProbe)이 맡는다.
        $sentinelDir = installerExposureProbeDir($basePath);
        if (! is_dir($sentinelDir)) {
            @mkdir($sentinelDir, 0755, true);
        }

        if (! is_dir($sentinelDir) || ! is_writable($sentinelDir)) {
            $unmeasured[] = ['label' => 'storage/', 'reason' => 'not_writable'];

            return ['targets' => $targets, 'unmeasured' => $unmeasured, 'controls' => $controls, 'absent_control' => installerExposureAbsentControl()];
        }

        // 이 계열은 요청마다 파일을 **만든다.** 설치 창에는 인증이 없으므로(#7)
        // 그 쓰기는 두 방향으로 묶는다:
        //  ① 진입 스윕 — **오래된** sentinel 만 걷는다. 잔여물이 호출 수에 비례해 쌓이지 않고,
        //     설치를 도중에 포기해도 다음 점검이 앞의 것을 정리한다.
        //     나이 제한이 필요한 이유: 무조건 걷으면 탭 두 개나 [다시 검사] 중복 호출에서
        //     **뒤 호출이 앞 호출의 대상을 지운다.** 그러면 앞 화면의 프로브가 404 를 받아
        //     「보호 확인됨」이 되는데, 그 파일은 서빙되고 있었을 수도 있다 — 앞서
        //     고유 파일명으로 닫은 D3 판정 반전이 정리 쪽으로 되살아난다.
        //  ② 창당 상한 — 넘으면 **429 로 화면을 깨뜨리지 않고** 이 계열만 재지 못한 것으로
        //     보고한다. 요구사항 화면은 정상 설치의 통로라 상한 때문에 막히면 안 되고,
        //     재지 못했다는 사실은 D21 경로가 이미 운영자에게 보여 준다.
        $sweep = installerSweepStaleExposureProbe($basePath);

        if (! $sweep['listed']) {
            // 디렉토리를 열거하지 못했다 — `glob` 과 `scandir` 이 둘 다 막힌 호스팅이다.
            // 잔여 파일이 쌓여도 이 스윕이 걷지 못하므로, 그 사실을 화면에 도달시킨다.
            // (총량 자체는 아래 창당 상한이 묶으므로 노출·판정 반전은 없다.)
            $unmeasured[] = ['label' => 'probe_sweep', 'reason' => 'not_listable'];
        }

        // 상한은 **자기 클라이언트 창**으로만 판정한다.
        //
        // 전역 창(신원 교체 총량 방어)까지 이 자리에서 보면, 무인증 원격자가 그 창을 채우는
        // 것만으로 운영자의 storage 계열이 상시 `rate_limited` 가 된다. 그 계열이 대상에서
        // 빠지면 판정은 `exposed` → `unknown` 방향으로만 움직이므로, **노출 자동 차단(D3)이
        // 운영자 수동 확인(D21)으로 강등**된다 — 화면상 이상이 없어 알아챌 방법도 없다.
        // 이 분기는 **전역** 창을 세지 않는다. 대신 총량 방어를 원격 주소 창
        // (`$limit * 10`)이 맡는다 — 세션 번호는 요청하는 쪽이 정하는 값이라 그것
        // 하나만으로는 쿠키를 갈아 끼우는 것만으로 상한이 사라지기 때문이다
        // (전역 창을 뺄 때 함께 열린 구멍이었다).
        // 그 주소 창을 채워 남을 강등시키려면 그 사람과 같은 egress IP 여야 하므로,
        // 전역 창과 달리 임의의 제3자가 이 판정을 깎을 수 없다. 쓰기 총량은 그 창에
        // 더해 진입 스윕(TTL 300초)과 finalize 정리가 함께 걷는다.
        if (installerProbeRateLimitExceeded('probe_exposure_sentinel', 60, 300, clientScopeOnly: true)) {
            $unmeasured[] = ['label' => 'storage/', 'reason' => 'rate_limited'];

            return ['targets' => $targets, 'unmeasured' => $unmeasured, 'controls' => $controls, 'absent_control' => installerExposureAbsentControl()];
        }

        // 파일 **이름**과 **마커**는 요구 강도가 다르다.
        //
        // 이름은 같은 디렉토리에서 겹치지 않기만 하면 되므로 형제 경로가 이미 쓰는 SSoT
        // (`installerUniqueSuffix()`, CSPRNG 실패 시 폴백을 갖는다)를 쓴다. 마커는 추측되면
        // 판정이 뒤집히므로 CSPRNG 만 받고, 얻지 못하면 이 계열을 재지 못한 것으로 보고한다.
        // 종전에는 이름까지 CSPRNG 에 묶여 있어, 그것을 못 얻는 호스팅은 **모든 점검이 상시
        // 판정 불능**이 되고 그 결과 게이트가 사실상 「체크 한 번」으로 고정됐다.
        $suffix = installerUniqueSuffix(16);

        try {
            $token = 'G7-EXPOSURE-PROBE-'.bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $unmeasured[] = ['label' => 'storage/', 'reason' => 'no_random'];

            return ['targets' => $targets, 'unmeasured' => $unmeasured, 'controls' => $controls, 'absent_control' => installerExposureAbsentControl()];
        }

        // 이름에 **확장자를 붙이지 않는다.**
        //
        // 이 sentinel 이 재려는 것은 「rewrite 부재 방어선의 **경로(디렉토리) 차단**이 살아
        // 있는가」다 — `vendor/` 를 함께 재는 사유와 같다(그 차단은 `mod_alias` 안에 있어 그
        // 모듈이 없으면 통째로 무효다). 그런데 이름이 `….txt` 면 요청은 그 앞에 있는 **확장자
        // 규칙**(루트 `.htaccess` 의 목록에 `txt` 가 있다)에 먼저 걸린다. 그러면 403 이 나와도
        // 경로 차단의 생존을 증명하지 못하는데, 정작 그 규칙만이 지키는 실물은 확장자가
        // 없거나(`storage/framework/sessions/<40hex>` — 세션 탈취) 목록 밖
        // (`storage/app/public/*.png|pdf`)이다. 즉 대표성이 없는 sentinel 이었다.
        $sentinelName = INSTALLER_EXPOSURE_PROBE_PREFIX.$suffix;
        $sentinelPath = $sentinelDir.'/'.$sentinelName;

        // 쓴 **바이트 수**까지 본다. 디스크가 차거나 quota 에 걸리면 `file_put_contents` 는
        // false 가 아니라 「일부만 썼다」를 돌려주는데, 그것을 성공으로 통과시키면 전체 토큰을
        // 마커로 내려보내게 된다. 그러면 그 파일이 **실제로 서빙되는데도** 브라우저는 마커
        // 불일치를 보고 「보호 확인됨」으로 읽는다 — 고유 파일명과 나이 제한
        // 스윕으로 닫은 D3 판정 반전이 부분 쓰기 축으로 되살아난다. 형제
        // (`RootHtaccessUpdater::writeAtomically`)가 정확히 이 축을 같은 방식으로 막는다.
        $sentinelBody = $token."\n";
        $sentinelWritten = @file_put_contents($sentinelPath, $sentinelBody);
        if ($sentinelWritten === false || $sentinelWritten !== strlen($sentinelBody)) {
            @unlink($sentinelPath);
            $unmeasured[] = ['label' => 'storage/', 'reason' => 'not_writable'];

            return ['targets' => $targets, 'unmeasured' => $unmeasured, 'controls' => $controls, 'absent_control' => installerExposureAbsentControl()];
        }

        // 권한은 **손대지 않는다** — 이 파일이 대표해야 하는 실제 storage 콘텐츠와 같은 등급
        // (PHP 가 기본 umask 로 만드는 권한)이어야 한다.
        //
        // 형제 임시 파일(runtime.php)의 0600 규약을 여기에 그대로 적용했더니 대표성이 깨졌다:
        // PHP-FPM 이 사이트 계정으로 돌고 정적 파일은 웹서버 계정이 서빙하는 흔한 배치에서
        // **웹서버가 읽지 못해 403** 이 되는데, 화면은 403 을 「보호됨(judged)」으로 읽는다
        // (installer.js `probeExposureTarget`). 실제 storage 파일은 0644 라 그대로 서빙되는
        // 서버가 storage 계열만 초록이 되는 것이다. 0600 을 지키면서 그 403 을 판정 불능으로
        // 강등하는 길도 있으나, 그러면 **정상 서버에서도** 이 계열이 상시 unmeasured 가 되어
        // 노출 자동 차단(D3)이 언제나 수동 확인(D21)으로 강등된다 — 측정 자체가 사라진다.
        // 내용은 1회용 난수라 노출되어도 정보 가치가 없다(마커를 알아도 판정은 뒤집히지
        // 않는다 — 그 파일이 서빙되는지가 판정의 전부다).

        $targets[] = [
            'path' => '/storage/installer/'.$sentinelName,
            'marker' => $token,
            'label' => 'storage/',
        ];

        return ['targets' => $targets, 'unmeasured' => $unmeasured, 'controls' => $controls, 'absent_control' => installerExposureAbsentControl()];
    }
}

if (! function_exists('installerDetectServerKind')) {
    /**
     * 웹 서버 종류를 판정합니다 (보호 설정 안내 분기용).
     *
     * 배포 레이아웃 ②의 보호는 **서버마다 사는 곳이 다르다.** Apache·LiteSpeed 는 릴리즈에
     * 동봉된 루트 `.htaccess` 를, IIS 는 동봉된 `web.config` 를 **읽어서 스스로 적용**한다.
     * nginx·Caddy 는 디렉토리별 설정 파일을 읽지 않으므로 운영자가 서버 설정에 직접 넣고
     * 다시 읽어야 한다 — 그 차이를 모른 채 「.htaccess 를 확인하라」고만 안내하면 nginx
     * 운영자는 존재하지 않는 조치를 찾게 된다.
     *
     * 판정은 `SERVER_SOFTWARE` 문자열로 한다. 이 값은 서버가 스스로 밝히는 것이라
     * 위조·공백이 가능하므로 **안내 분기에만** 쓰고 게이트로 쓰지 않는다 — 노출 판정 자체는
     * 언제나 실제 응답이 정한다. 알 수 없으면 `other` 로 떨어져 범용 안내가 나간다.
     *
     * @return string `apache` | `litespeed` | `iis` | `nginx` | `other`
     */
    function installerDetectServerKind(): string
    {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? '';

        if (! is_string($software) || $software === '') {
            return 'other';
        }

        $software = strtolower($software);

        // LiteSpeed 는 자기 이름에 Apache 호환 표기를 함께 싣는 판본이 있어 먼저 본다.
        if (str_contains($software, 'litespeed') || str_contains($software, 'lsws')) {
            return 'litespeed';
        }

        if (str_contains($software, 'nginx')) {
            return 'nginx';
        }

        if (str_contains($software, 'iis') || str_contains($software, 'microsoft-httpapi')) {
            return 'iis';
        }

        if (str_contains($software, 'apache')) {
            return 'apache';
        }

        return 'other';
    }
}

if (! function_exists('installerExposureProbeTargets')) {
    /**
     * 브라우저가 프로브할 대상 목록만 산출합니다 (`installerExposureProbePlan` 의 얇은 래퍼).
     *
     * @param  string|null  $basePath  프로젝트 루트 (기본 BASE_PATH — 테스트 주입용)
     * @return array<int, array{path: string, marker: string, label: string}>
     */
    function installerExposureProbeTargets(?string $basePath = null): array
    {
        return installerExposureProbePlan($basePath)['targets'];
    }
}

if (! function_exists('installerExposureFirstEnvLine')) {
    /**
     * env 파일 내용에서 마커로 쓸 첫 `KEY=` 줄을 찾습니다.
     *
     * 값이 붙은 줄도 `.env.example` 은 전부 공개 내용이지만, 방어적으로 `KEY=` 접두만
     * 잘라 쓴다 — 마커는 "이 파일이 원문 그대로 서빙되었는가" 를 가리는 용도라 키
     * 이름만으로 충분하고, HTML 오류 페이지가 우연히 담을 수 없는 형태(`\nKEY=`)를 피해
     * 줄 시작 검사는 브라우저 쪽 includes 로 단순화한다.
     *
     * @param  string  $content  env 파일 내용
     * @return string|null 마커 문자열 (`APP_NAME=` 형태). 적합한 줄이 없으면 null
     */
    function installerExposureFirstEnvLine(string $content): ?string
    {
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]{2,63})=/', trim($line), $m)) {
                return $m[1].'=';
            }
        }

        return null;
    }
}

if (! function_exists('installerProbeSymlinkSupport')) {
    /**
     * `symlink()` 를 실제로 시도해 링크 생성 가능 여부를 실측합니다 (B-4).
     *
     * 서버 설정을 추론하지 않고 실제로 만들어 본다. `symlink` 이 차단된
     * 환경(`disable_functions`)에서는 null(판정 불가)을 반환한다.
     *
     * @param  string|null  $baseDir  실측용 임시 디렉토리 (기본 storage/installer)
     * @return bool|null 링크 생성 가능 여부. 판정 불가면 null
     */
    function installerProbeSymlinkSupport(?string $baseDir = null): ?bool
    {
        if (! installerHasFunction('symlink')) {
            return null;
        }

        $dir = rtrim($baseDir ?? installerExposureProbeDir(), '/\\');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (! is_dir($dir) || ! is_writable($dir)) {
            return null;
        }

        // 이 실측도 **요청마다 파일을 만든다**(대상 + 링크 2개). sentinel 계열만 상한을 두고
        // 이 형제를 빼면 같은 응답 안에 상한 밖 쓰기 경로가 남는다 — 「실측 1회」로 읽혀
        // 쓰기 경로로 세지 않았던 자리다. 초과하면 막지 않고 **판정 불가**로 둔다(링크 불가는
        // 설치를 막는 항목이 아니라 대체 경로가 자동 선택되는 조건이다).
        //
        // 상한은 **자기 클라이언트 창**으로만 본다 — 형제(`probe_exposure_sentinel`)와 같은
        // 사유다. 설치 창은 무인증이라, 전역 창까지 보면 원격자가 그 창을 채우는 것만으로
        // 운영자의 링크 실측이 상시 `null` 이 된다. 그러면 화면의 안내 조건
        // (`storageLinkNoticeNeeded` — `symlink_supported === false` 일 때만 알린다)이 영영
        // 성립하지 않아 **B-3 전환 안내가 한 번도 뜨지 않는다**(오류도 흔적도 없다).
        // 형제(sentinel 축)와 같다 — 이 분기는 전역 창을 세지 않으므로 그 버킷의 상한은
        // 클라이언트별 창 하나다. 실측을 지우지 않는 것이 이 선택의 목적이다.
        if (installerProbeRateLimitExceeded('probe_symlink', clientScopeOnly: true)) {
            return null;
        }

        // 이름은 매 호출 고유해야 한다 — 고정 이름이면 동시 요청끼리 서로의 대상을 지워
        // 실측값이 흔들린다. 종전에는 `getmypid()` 로 갈랐는데 그 함수는 하드닝 프로필의
        // 차단 대상이면서 **무가드**였다: 막힌 호스팅에서 PHP 8 은 함수 테이블에서 지우므로
        // Error(치명)가 되고, 그 예외가 요구사항 응답 전체를 500 봉투로 바꿔 **화면이 통째로
        // 뜨지 않았다**(13차 치명 #3·18차와 같은 형태). 같은 난수원으로 통일해 그 축을 없앤다.
        // 이 이름은 **마커가 아니라 이름**이다 — 겹치지만 않으면 되고, 추측되어도 판정이
        // 뒤집히지 않는다(링크를 만들 수 있는지 스스로 시도해 보는 것이 판정의 전부다).
        // CSPRNG 를 못 얻는 호스팅에서 링크 실측까지 포기하면 그 사이트는 B-3 전환 여부를
        // 영영 모른 채 설치된다. 형제 경로와 같은 SSoT 를 쓴다.
        $suffix = installerUniqueSuffix(8);

        $target = $dir.'/'.INSTALLER_SYMLINK_PROBE_PREFIX.'target-'.$suffix.'.txt';
        $link = $dir.'/'.INSTALLER_SYMLINK_PROBE_PREFIX.'link-'.$suffix;

        if (@file_put_contents($target, "g7 symlink probe\n") === false) {
            return null;
        }

        $supported = @symlink($target, $link);

        // 정리 — 실패해도 접두사 스윕(installerCleanupExposureProbe)이 뒤에서 걷는다.
        if (file_exists($link) || is_link($link)) {
            @unlink($link);
        }
        @unlink($target);

        return (bool) $supported;
    }
}

if (! function_exists('installerSweepStaleExposureProbe')) {
    /**
     * **오래된** 노출 점검 잔여 파일만 걷습니다 (점검 진입 시 호출).
     *
     * 이 계열은 요청마다 파일을 만든다. 설치 창에는 인증이 없으므로 그 쓰기를 묶어야 하는데,
     * 무조건 전부 걷으면 동시 점검이 서로의 대상을 지워 앞 화면의 판정이 뒤집힌다(앞서
     * 고유 파일명으로 닫은 축이 정리 쪽으로 되살아난다). 그래서 진행 중일 수 없을 만큼 오래된
     * 것만 지운다 — 형제 선례(`installer-state.php` 의 tmp 스윕)와 같은 규칙이다.
     *
     * 완주·중단·초기화 시점의 **전량** 정리는 `installerCleanupExposureProbe()` 가 맡는다.
     *
     * `listed` 는 「목록을 얻었는가」다 — 형제 정리기(`installerCleanupExposureProbe`)가
     * 같은 이름의 축을 이미 갖는다. `glob` 과 `scandir` 이 **둘 다** 막힌 하드닝 호스팅에서는
     * 디렉토리를 열거할 수 없어 이 스윕이 사문이 되는데, 종전에는 지운 수(`int`)만 돌려주어
     * 「지울 것이 없었다(0)」와 「쓸지 못했다(0)」가 구분되지 않았다. 그 상태는 로그에도
     * 응답에도 한 줄이 남지 않는다 — 이 파일이 스윕을 `glob` 에서 `scandir` 로 옮긴 사유가
     * 바로 그 환경이었는데, 그 다음 단계의 관측 통로만 형제와 비대칭이었다.
     *
     * @param  string|null  $basePath  프로젝트 루트 (기본 BASE_PATH)
     * @param  int  $maxAgeSeconds  이 나이를 넘긴 파일만 지운다
     * @return array{removed: int, listed: bool} 지운 파일 수 + 열거 성공 여부
     */
    function installerSweepStaleExposureProbe(?string $basePath = null, int $maxAgeSeconds = 300): array
    {
        $dir = installerExposureProbeDir($basePath);

        if (! is_dir($dir)) {
            return ['removed' => 0, 'listed' => true];
        }

        $removed = 0;
        $threshold = time() - max(1, $maxAgeSeconds);
        $listing = installerListExposureProbeFiles($dir);

        foreach ($listing['files'] as $path) {
            if (! is_link($path) && ! is_file($path)) {
                continue;
            }

            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $threshold && @unlink($path)) {
                $removed++;
            }
        }

        return ['removed' => $removed, 'listed' => (bool) $listing['listed']];
    }
}

if (! function_exists('installerCleanupExposureProbe')) {
    /**
     * 노출 점검·링크 실측 잔여 파일을 제거합니다 (설치 마무리·중단 정리 구간에서 호출).
     *
     * 대상은 **접두사 전체**다. sentinel 이름이 매 호출 고유해졌고(경합 오판 차단),
     * 링크 실측 임시 파일도 같은 디렉토리에 남을 수 있으므로 단일 파일명 삭제로는
     * 잔여물을 걷지 못한다. 이 정리는 완주 경로뿐 아니라 중단(abort)·초기화(reset)
     * 에서도 불린다 — 종전에는 finalize 성공 경로에서만 불려 그 밖의 모든 경로에서
     * 파일이 남았고, 남았다는 사실이 어디에도 기록되지 않았다.
     *
     * `swept` 는 「쓸었는가」다. 「지울 것이 없었다」와 「쓸지 못했다」를 같은
     * `removed:0` 으로 뭉뚱그리면 로그가 둘을 구분하지 못해, 정리가 통째로 무동작인
     * 환경에서도 「정리 완료 (0개)」가 남는다.
     *
     * `$finalizing` 은 「설치가 끝나 이 표면 자체가 닫히는가」다. 그렇지 않은 정리
     * (중단·초기화)는 **갓 만들어진 sentinel 을 남긴다** — 그 둘은 요구사항 화면이
     * 열려 있는 동안에도 부를 수 있는 무인증 POST 이고, 진행 중인 점검의 sentinel 을
     * 지우면 그 화면의 프로브가 404 를 받아 **「그 파일은 막혀 있다」로 확정**한다.
     * 앞서 진입 스윕에 대해 세운 판정(뒤 호출이 앞 호출의 대상을 지우면 앞
     * 화면이 404 를 보호로 읽는다)이 이 정리 쪽에는 적용되지 않은 채였다.
     * 레이트리밋 상태도 같은 이유로 finalize 에서만 지운다 — 그러지 않으면 무인증
     * 요청 하나로 상한이 초기화되어 앞서 만든 상한이 그 자리에서 사라진다.
     *
     * @param  string|null  $basePath  프로젝트 루트 (기본 BASE_PATH)
     * @param  bool  $finalizing  설치 마무리 정리인지 여부 (false 면 최근 파일·상한 보존)
     * @return array{removed: int, failed: int, swept: bool, reason: string|null} 지운 수 / 지우지 못한 수 / 쓸었는지 / 못 쓴 사유
     */
    function installerCleanupExposureProbe(?string $basePath = null, bool $finalizing = true): array
    {
        $dir = installerExposureProbeDir($basePath);
        $removed = 0;
        $failed = 0;

        // 진행 중인 점검의 sentinel 을 지우지 않기 위한 나이 하한 (초).
        $minimumAge = $finalizing ? 0 : 60;

        // 레이트리밋 카운터도 설치 중에만 필요한 파일이다. 형제 상태 파일
        // (`storage/installer-state.json`)은 finalize 가 지우는데 이것만 정리 주체가 없어
        // 설치가 끝난 사이트에 영구 잔존했다 — 같은 정리 지점에서 함께 걷는다.
        $ratePath = installerProbeRateLimitStatePath();
        if ($finalizing && is_file($ratePath)) {
            if (@unlink($ratePath)) {
                $removed++;
            } else {
                $failed++;
            }
        }

        if (! is_dir($dir)) {
            return ['removed' => $removed, 'failed' => $failed, 'swept' => true, 'reason' => null];
        }

        // `glob` 은 하드닝 프로필의 차단 대상이다. 막힌 환경에서는 정리를 건너뛴다 —
        // 잔여물은 난수 1회용 파일이라 남아도 무해하고(정보 가치 0), 여기서 치명을 내면
        // 설치 마무리 자체가 무너진다. 다만 **쓸지 못했다는 사실은 남긴다**.
        // 나열은 `glob` 없이도 된다(형제 `installerListExposureProbeFiles` 가 `scandir`
        // 로 같은 접두사를 훑는다). 그마저 막히면 그때만 「쓸지 못했다」로 보고한다 —
        // **앞에서 지운 레이트 파일의 회계를 덮지 않는다.** 종전에는 이 분기가
        // `removed: 0, failed: 0` 을 하드코딩해, 그 위에서 지웠거나 지우지 못한 사실이
        // 통째로 사라졌다(이 함수 자신이 「쓸지 못했다는 사실은 남긴다」고 적어 둔 규율과
        // 어긋난다).
        $listing = installerListExposureProbeFiles($dir);

        if (! $listing['listed']) {
            return ['removed' => $removed, 'failed' => $failed, 'swept' => false, 'reason' => 'listing_unavailable'];
        }

        foreach ($listing['files'] as $path) {
            // mtime 을 읽지 못하면 **보존한다.** `(int) false === 0` 이라 종전 조건은
            // `time() - 0` 을 언제나 하한 초과로 읽어 나이 게이트를 통째로 무력화했고,
            // 그러면 **진행 중인 점검의 sentinel 을 지운다**(fail-open) — 그 점검은
            // 404 를 받아 「그 파일은 막혀 있다」로 확정하므로 노출 점검이 거짓 안전을
            // 보고한다. 형제 둘(`:661` 의 `$mtime !== false &&`, 앞서 고친
            // `installer-state.php`)은 이미 정반대다.
            if ($minimumAge > 0) {
                $mtime = @filemtime($path);

                if ($mtime === false || (time() - $mtime) < $minimumAge) {
                    continue; // 진행 중인 점검의 대상일 수 있다 — 다음 진입 스윕에 넘긴다
                }
            }

            if (is_link($path) || is_file($path)) {
                if (@unlink($path)) {
                    $removed++;
                } else {
                    $failed++;
                }
            }
        }

        return ['removed' => $removed, 'failed' => $failed, 'swept' => true, 'reason' => null];
    }
}
