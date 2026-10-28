<?php

namespace Tests\Feature\Extension;

use App\Exceptions\PhpExtensionRequirementException;
use App\Extension\ExtensionManager;
use App\Extension\Support\LifecycleWarnings;
use App\Support\PhpExtensionProbe;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 확장이 요구하는 PHP 확장 게이트 Feature 테스트 (#151 D3·D8)
 *
 * composer.json `require.ext-*` 미충족은 설치·활성화를 422 로 막고, 업데이트(번들 일괄 포함)·강제 활성화·코어 업데이트
 * 중 활성화는 생명주기 경고만 남긴다. `suggest.ext-*` 는 어느 경로도 막거나 경고하지 않는다(관리자 배지로만 알린다).
 * 매니저의 설치·활성화·업데이트 경로가 이 게이트를 지나는지는 소스 배선으로 잠그고, 실제 설치 차단은 브라우저 실측에서 잰다.
 */
class ExtensionPhpRequirementGateTest extends TestCase
{
    private string $dir;

    /**
     * soap 만 없는 서버와 임시 확장 디렉토리를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/ext-php-gate-'.uniqid());
        File::ensureDirectoryExists($this->dir);
        $this->app->instance(PhpExtensionProbe::class, new class extends PhpExtensionProbe
        {
            /**
             * soap 만 없는 것으로 답합니다.
             *
             * @param  string  $extension  확장 이름
             * @return bool 로드돼 있으면 true
             */
            public function loaded(string $extension): bool
            {
                return $extension !== 'soap';
            }
        });
    }

    /**
     * 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * require 미충족이면 설치·활성화 게이트가 확장 이름과 없는 확장을 담은 예외로 막습니다.
     *
     * @scenario level=require, path=install_activate
     *
     * @effects require_missing_blocks_install_and_activate
     *
     * @return void 반환값 없음
     */
    public function test_require_missing_blocks_with_named_message(): void
    {
        $this->writeComposer(['require' => ['ext-soap' => '*']]);

        try {
            app(ExtensionManager::class)->assertPhpExtensionRequirements('plugin', 'acme-soap', $this->dir, ['ko' => '테스트 결제', 'en' => 'Test Pay']);
            $this->fail('PhpExtensionRequirementException 이 발생해야 합니다.');
        } catch (PhpExtensionRequirementException $e) {
            $this->assertSame(['extension_type' => 'plugin', 'identifier' => 'acme-soap', 'missing_extensions' => ['soap']], $e->getPayload());
            $this->assertStringContainsString('soap', $e->getMessage());
            $this->assertStringContainsString(app()->getLocale() === 'en' ? 'Test Pay' : '테스트 결제', $e->getMessage());
        }
    }

    /**
     * 게이트 예외는 전역 핸들러에서 422 + error_code php_extension_missing 으로 응답합니다.
     *
     * @scenario level=require, path=http
     *
     * @effects require_missing_responds_422
     *
     * @return void 반환값 없음
     */
    public function test_exception_renders_422_json(): void
    {
        $this->writeComposer(['require' => ['ext-soap' => '*']]);
        $dir = $this->dir;
        Route::middleware('api')->post('/api/__test/php-ext-gate', function () use ($dir) {
            app(ExtensionManager::class)->assertPhpExtensionRequirements('plugin', 'acme-soap', $dir);
        });

        $this->postJson('/api/__test/php-ext-gate')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'php_extension_missing')
            ->assertJsonPath('data.missing_extensions', ['soap']);
    }

    /**
     * 업데이트·강제 활성화 경로는 막지 않고 생명주기 경고(php_extension_missing)와 로그만 남깁니다.
     *
     * @scenario level=require, path=update
     *
     * @effects update_only_warns
     *
     * @return void 반환값 없음
     */
    public function test_warn_records_lifecycle_warning_without_throwing(): void
    {
        $this->writeComposer(['require' => ['ext-soap' => '*']]);
        Log::spy();

        $missing = app(ExtensionManager::class)->warnPhpExtensionRequirements('plugin', 'acme-soap', $this->dir);

        $this->assertSame(['soap'], $missing);
        $warnings = LifecycleWarnings::current()->all();
        $this->assertSame('php_extension_missing', $warnings[0]['step'] ?? null);
        $this->assertSame('acme-soap', $warnings[0]['target'] ?? null);
        $this->assertStringContainsString('soap', $warnings[0]['message'] ?? '');
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * suggest 미충족은 설치 게이트도 경고도 일으키지 않습니다. (PC 결제만 쓰는 서버의 설치를 막지 않는다 — D12)
     *
     * @scenario level=suggest, path=install_activate
     *
     * @effects suggest_never_blocks_or_warns
     *
     * @return void 반환값 없음
     */
    public function test_suggest_never_blocks_or_warns(): void
    {
        $this->writeComposer(['suggest' => ['ext-soap' => '모바일 결제']]);
        $manager = app(ExtensionManager::class);

        $manager->assertPhpExtensionRequirements('plugin', 'acme-soap', $this->dir);
        $this->assertSame([], $manager->warnPhpExtensionRequirements('plugin', 'acme-soap', $this->dir));
        $this->assertSame([], LifecycleWarnings::current()->all());
        $this->assertSame(['soap'], $manager->checkPhpExtensionRequirementsAt($this->dir)['missing_suggested']);
    }

    /**
     * 두 매니저의 설치·활성화·업데이트 경로가 게이트를 지납니다. (배선이 조용히 빠지는 것을 막는다)
     *
     * 설치: 코어 버전 검증 다음에 assert. 활성화: 코어 버전 검사와 같은 조건(force·코어 업데이트 중이 아니면)으로 assert,
     * 그 밖에는 warn. 업데이트: 스테이징 경로로 warn(force·코어 업데이트 중에도).
     *
     * @scenario level=require, path=wiring
     *
     * @effects managers_wire_install_activate_update_gates
     *
     * @return void 반환값 없음
     */
    public function test_managers_wire_the_gates(): void
    {
        foreach ([['ModuleManager', 'Module'], ['PluginManager', 'Plugin']] as [$class, $noun]) {
            $source = File::get(app_path("Extension/{$class}.php"));

            $install = $this->methodBody($source, "install{$noun}");
            $this->assertStringContainsString('assertPhpExtensionRequirements(', $install, "{$class} install 게이트 누락");
            $this->assertLessThan(
                strpos($install, 'assertPhpExtensionRequirements('),
                strpos($install, 'CoreVersionChecker::validateExtension('),
                "{$class} install 게이트는 코어 버전 검증 다음이어야 한다",
            );

            $activate = $this->methodBody($source, "activate{$noun}");
            $this->assertMatchesRegularExpression(
                '/if \(! \$force && ! CoreServiceProvider::isCoreUpdateInProgress\(\)\) \{\s+\$this->extensionManager->assertPhpExtensionRequirements\([^;]+;\s+\} else \{\s+\$this->extensionManager->warnPhpExtensionRequirements\(/',
                $activate,
                "{$class} activate 게이트(차단/경고 분기) 누락",
            );

            $update = $this->methodBody($source, "update{$noun}");
            $this->assertMatchesRegularExpression(
                '/if \(\$stagingPath\) \{\s+\$this->extensionManager->warnPhpExtensionRequirements\([^)]*\$stagingPath\)/',
                $update,
                "{$class} update 경고 누락",
            );
        }
    }

    /**
     * 임시 디렉토리에 composer.json 을 씁니다.
     *
     * @param  array<string, mixed>  $data  composer.json 내용
     * @return void 반환값 없음
     */
    private function writeComposer(array $data): void
    {
        File::put($this->dir.'/composer.json', (string) json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 소스에서 public 메서드 본문을 잘라냅니다 (중괄호 균형).
     *
     * @param  string  $source  PHP 소스 전문
     * @param  string  $method  메서드명
     * @return string 본문 (못 찾으면 빈 문자열)
     */
    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'public function '.$method.'(');
        if ($start === false) {
            return '';
        }

        // 시그니처 괄호를 넘긴 뒤 첫 `{` 가 본문 시작이다 (여러 줄 시그니처는 `): array {` 로 끝난다)
        $paren = 0;
        for ($open = strpos($source, '(', $start); $open < strlen($source); $open++) {
            if ($source[$open] === '(') {
                $paren++;
            } elseif ($source[$open] === ')') {
                $paren--;
            } elseif ($paren === 0 && $source[$open] === '{') {
                break;
            }
        }
        $depth = 0;
        for ($i = $open; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}' && --$depth === 0) {
                return substr($source, $open, $i - $open + 1);
            }
        }

        return '';
    }
}
