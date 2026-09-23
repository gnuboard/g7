<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `artisan test` 부모 프로세스의 확장 오토로드 등록 회귀 가드 (이슈 #599 후속).
 *
 * 회귀: `artisan` 은 `test` 명령일 때 확장 오토로드 등록을 건너뛰면서
 * (`tests/bootstrap.php` 가 `_bundled` 기준으로 우선 로드한다는 전제)
 * 중복 방지 상수 `G7_EXTENSION_AUTOLOAD_REGISTERED` 는 **무조건** 정의했다.
 * 그 상수는 `CoreServiceProvider::registerExtensionAutoload()` 의 스킵 조건이므로,
 * 결과적으로 **아무도 등록하지 않은 채** 부모 프로세스가 Laravel 을 부팅한다.
 *
 * 그 전제는 자식에만 성립한다: `artisan test` 는 PHPUnit 을 `new Process(...)` 로
 * **별도 자식 프로세스**에 띄우므로 `tests/bootstrap.php` 는 자식에서만 돈다.
 * 부모 artisan 프로세스는 아무도 책임지지 않는다.
 *
 * 증상: 부모 부팅에서 `ModuleRouteServiceProvider` 가 활성 모듈 라우트 파일을 실행하다
 * 확장 클래스를 만나 죽는다 —
 * `Class "Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum" not found`.
 * PHPUnit 은 시작조차 못 한다(`vendor/bin/phpunit` 직접 실행은 이 경로를 타지 않아 정상).
 *
 * 라우트 캐시가 이를 오래 가려 왔다 — 캐시가 있으면 부모 부팅이 캐시를 로드해
 * 라우트 **파일**을 아예 실행하지 않는다. 캐시가 비는 창(갓 설치한 사이트,
 * `route:clear` 직후)에서만 드러난다.
 *
 * 도입 시점의 원본 코드는 `define()` 이 `if (file_exists(...))` 블록 **안**에 있어
 * "실제로 등록했을 때만 등록했다고 표시" 하는 구조였다. 그 커밋이 `define()` 을
 * 블록 밖으로 빼면서 "등록하지 않았는데 했다고 표시" 하는 상태가 만들어졌다.
 *
 * 본 테스트가 고정하는 계약:
 *   `artisan` 은 확장 오토로드를 **실제로 등록한 경우에만**
 *   `G7_EXTENSION_AUTOLOAD_REGISTERED` 를 정의한다. 등록을 건너뛰는 경로(`test` 명령)에서는
 *   상수를 정의하지 않아 `CoreServiceProvider` 가 부모 프로세스용 등록을 이어받는다.
 *   자식 PHPUnit 은 별도 프로세스라 `tests/bootstrap.php` 의 `_bundled` prepend 가
 *   그대로 유지된다.
 */
class ArtisanTestCommandExtensionAutoloadTest extends TestCase
{
    private string $artisanSource = '';

    /**
     * 검사 대상 artisan 스크립트 원문을 읽어 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisanSource = (string) file_get_contents(dirname(__DIR__, 3).'/artisan');
    }

    /**
     * test 명령 경로는 등록 완료 상수를 무조건 세우지 않는다.
     *
     * @scenario entrypoint=artisan_test_command, autoload_registration=skipped
     *
     * @effects autoload_registered_flag_is_not_defined_unconditionally, artisan_never_claims_autoload_registration_it_did_not_perform
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 확장_오토로드를_건너뛰는_경로에서는_등록_완료_상수를_정의하지_않는다(): void
    {
        // 상수 정의가 "등록을 건너뛰지 않는다" 는 조건 아래에 있어야 한다.
        // 무조건 define 이면(조건 밖 최상위 한 줄) 등록 없이 표시만 서서
        // CoreServiceProvider 의 등록까지 막힌다.
        $definesUnconditionally = (bool) preg_match(
            '/^define\(\s*\'G7_EXTENSION_AUTOLOAD_REGISTERED\'/m',
            $this->artisanSource
        );

        $this->assertFalse(
            $definesUnconditionally,
            'artisan 이 G7_EXTENSION_AUTOLOAD_REGISTERED 를 무조건 정의한다. '
            .'test 명령에서는 확장 오토로드를 등록하지 않으면서 상수만 세워, '
            .'CoreServiceProvider 의 등록까지 막히고 부모 부팅이 확장 클래스를 못 찾는다.'
        );
    }

    /**
     * 등록 완료 상수는 실제 등록 블록 안에서만 정의된다.
     *
     * @scenario entrypoint=artisan_normal_command, autoload_registration=registered
     *
     * @effects autoload_registered_flag_is_defined_only_inside_registration_block, artisan_never_claims_autoload_registration_it_did_not_perform
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 등록_완료_상수는_실제_등록_경로_안에서만_정의된다(): void
    {
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$isTestCommand\s*&&\s*file_exists\(\$extensionAutoloadFile\)\s*\)\s*\{/',
            $this->artisanSource,
            'artisan 의 확장 오토로드 등록 가드(!$isTestCommand && file_exists)가 없다.'
        );

        // 등록 블록 본문을 잘라 그 안에 define 이 있는지 본다.
        $guardPosition = (int) strpos($this->artisanSource, 'if (!$isTestCommand && file_exists($extensionAutoloadFile))');
        $registrationBlock = substr($this->artisanSource, $guardPosition);

        $this->assertStringContainsString(
            "define('G7_EXTENSION_AUTOLOAD_REGISTERED', true)",
            $registrationBlock,
            '등록 완료 상수가 실제 등록 블록 안에 없다 — 등록과 표시가 어긋난다.'
        );
    }

    /**
     * test 명령 판별과 확장 오토로드 캐시 경로는 그대로 유지된다.
     *
     * @scenario entrypoint=artisan_test_command, autoload_registration=skipped
     *
     * @effects test_command_detection_is_preserved, extension_autoload_cache_path_is_preserved
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_명령_판별과_확장_오토로드_파일_경로가_유지된다(): void
    {
        // 자식 PHPUnit 은 별도 프로세스라 tests/bootstrap.php 가 _bundled 를 prepend 한다.
        // 부모만 활성 디렉터리 기준으로 등록하면 되므로 이 분기 자체는 유지되어야 한다.
        $this->assertStringContainsString(
            "\$isTestCommand = isset(\$_SERVER['argv'][1]) && \$_SERVER['argv'][1] === 'test';",
            $this->artisanSource,
            'test 명령 판별이 없다 — 자식 PHPUnit 의 _bundled 우선 로드 전제가 깨진다.'
        );

        $this->assertStringContainsString(
            'bootstrap/cache/autoload-extensions.php',
            $this->artisanSource,
            '확장 오토로드 캐시 경로가 바뀌었다.'
        );
    }
}
