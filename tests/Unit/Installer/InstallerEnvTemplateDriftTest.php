<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * `.env` 생성의 템플릿 드리프트 내성 + NUL 제거 회귀 가드 (20차 #11·#12)
 *
 * - **#11**: `generateEnvContent()` 가 `.env.example` 의 **값까지 포함한 리터럴**을
 *   `str_replace` 검색어로 썼다 (`'APP_URL=http://localhost'` 등). 템플릿의 기본값이
 *   한 글자만 바뀌어도 치환이 **오류 없이** 통째로 건너뛰어, 운영자 입력이 소실된 채
 *   설치가 "성공" 한다. 같은 파일 계열의 안전한 형제(`replaceEnvLine` — 키 기반 매칭 +
 *   부재 시 라인 추가)가 이미 있었으므로 그것으로 합친다. 키 기반 매칭은 앞 항목이
 *   삽입한 **값** 안의 문자열이 뒤 항목의 검색어에 걸리는 오염 경로도 함께 닫는다.
 * - **#12**: `escapeEnvValue()` 가 CR/LF 만 지우고 NUL(\0)은 통과시켰다. 형제 검증
 *   (DSN·경로 입력)은 NUL 을 명시 거부한다 — `.env` 기록 경로만 규칙이 없었다.
 *   폴리필(installer-runtime.php)과 본 정의(functions.php)는 같은 정책을 유지해야 한다.
 *
 * @effects installer_env_writes_are_injection_safe
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerEnvTemplateDriftTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    protected function setUp(): void
    {
        parent::setUp();

        $projectRoot = dirname(__DIR__, 3);

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir().'/g7-env-drift-'.bin2hex(random_bytes(4)));
            @mkdir(BASE_PATH.'/storage/installer', 0755, true);
        }
        if (! defined('STATE_PATH')) {
            define('STATE_PATH', BASE_PATH.'/storage/installer-state.json');
        }
        if (! defined('INSTALLER_RUNTIME_PATH')) {
            define('INSTALLER_RUNTIME_PATH', BASE_PATH.'/storage/installer/runtime.php');
        }

        require_once __DIR__.'/stubs/lang_stub.php';
        require_once $projectRoot.'/public/install/includes/config.php';
        require_once $projectRoot.'/public/install/includes/installer-state.php';
        require_once $projectRoot.'/public/install/includes/functions.php';
        require_once $projectRoot.'/public/install/includes/installer-runtime.php';
    }

    /**
     * #11: 템플릿 기본값이 표류해도 치환은 키 기준으로 성립하고, 키가 없으면 라인을 추가한다.
     */
    public function test_env_generation_survives_template_default_drift(): void
    {
        // 의도적으로 표류시킨 템플릿 — 기본값이 원안과 다르고(APP_URL 빈 값 ·
        // DB_WRITE_HOST=localhost), G7_UPDATE_PENDING_PATH 라인은 아예 없다.
        file_put_contents(BASE_PATH.'/.env.example', implode("\n", [
            'APP_NAME=그누보드7',
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL=',
            'APP_LOCALE=ko',
            'APP_FAKER_LOCALE=ko_KR',
            'DB_CONNECTION=mysql',
            'DB_WRITE_HOST=localhost',
            'DB_WRITE_PORT=3306',
            'DB_WRITE_DATABASE=g7',
            'DB_WRITE_USERNAME=root',
            'DB_WRITE_PASSWORD=',
            'DB_PREFIX=g7_',
            'DB_READ_HOST=',
            'DB_READ_PORT=',
            'DB_READ_DATABASE=',
            'DB_READ_USERNAME=',
            'DB_READ_PASSWORD=',
            'G7_UPDATE_GITHUB_URL=https://github.com/gnuboard/g7',
            'G7_UPDATE_GITHUB_TOKEN=',
            'PHP_BINARY=php',
            'COMPOSER_BINARY=',
        ])."\n");

        $content = generateEnvContent();

        $this->assertIsString($content);
        // 표류한 기본값 라인도 키 기준으로 치환된다 — 종전에는 검색 리터럴이 어긋나
        // `APP_URL=`(빈 값) 이 그대로 남아 운영자 입력/기본값이 소실됐다 (#11).
        $this->assertMatchesRegularExpression('/^APP_URL=http:\/\/localhost$/m', $content, '표류한 APP_URL 라인이 치환되지 않았다 (#11)');
        $this->assertMatchesRegularExpression('/^DB_WRITE_HOST=127\.0\.0\.1$/m', $content, '표류한 DB_WRITE_HOST 라인이 치환되지 않았다 (#11)');
        // 템플릿에 없는 키는 라인을 추가한다 (replaceEnvLine 의 부재 시 추가 규칙).
        $this->assertMatchesRegularExpression('/^G7_UPDATE_PENDING_PATH=""$/m', $content, '부재 키가 추가되지 않았다 (#11)');
        // 표류하지 않은 라인의 종전 동작은 불변이다 — APP_NAME 은 한글이라 안전 문자셋
        // 밖이고, 종전에도 escapeEnvLineValue → escapeEnvValue 경로로 따옴표 인용됐다.
        $this->assertMatchesRegularExpression('/^DB_PREFIX=g7_$/m', $content);
        $this->assertMatchesRegularExpression('/^APP_NAME="그누보드7"$/mu', $content);
    }

    /**
     * #12: NUL 바이트는 CR/LF 와 같은 라인 위생 규칙의 대상이다.
     *
     * 규칙은 「조용히 제거」에서 **「예외로 거부」**로 강화됐다 (KVE-2026-2042) — 지우면
     * 운영자가 입력한 값과 실제 저장된 값이 달라지는데 그 사실이 화면에 나타나지 않는다.
     * 정책은 `serializeEnvValue()` 단일 관문이 소유하고, 본 정의와 폴리필은 그 관문에
     * 위임하기만 한다.
     */
    public function test_escape_env_value_rejects_nul_bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        escapeEnvValue("pa\0ss");
    }

    /**
     * 본 정의와 폴리필이 같은 관문에 위임한다 — 소스 대조 (로드 순서상 여기서는
     * functions.php 정의가 이겨, 폴리필의 행위는 직접 실행으로 검증할 수 없다).
     */
    public function test_escape_env_value_delegates_to_single_gateway(): void
    {
        $projectRoot = dirname(__DIR__, 3);

        foreach (['functions.php', 'installer-runtime.php'] as $file) {
            $source = (string) file_get_contents($projectRoot.'/public/install/includes/'.$file);

            $this->assertMatchesRegularExpression(
                '/function escapeEnvValue\(string \$value\): string\s*\{[^}]*return serializeEnvValue\(\$value\);/s',
                $source,
                "{$file} 의 escapeEnvValue 가 단일 관문에 위임하지 않는다 (#12)"
            );
        }
    }
}
