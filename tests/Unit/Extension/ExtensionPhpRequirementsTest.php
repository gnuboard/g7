<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use App\Support\PhpExtensionProbe;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 composer.json 의 PHP 확장 요구(require/suggest ext-*) 읽기 단위 테스트 (#151)
 *
 * `getComposerDependencies[At]()` 는 composer install 을 돌릴지만 정하므로 ext-* 를 버린다(그대로 둔다 —
 * ExtensionManagerComposerTest 가 잠근다). PHP 확장 요구는 별도 함수가 읽고, 로드 여부는 컨테이너의
 * PhpExtensionProbe 로 판정한다.
 */
class ExtensionPhpRequirementsTest extends TestCase
{
    private string $dir;

    /**
     * 임시 확장 디렉토리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/ext-php-req-'.uniqid());
        File::ensureDirectoryExists($this->dir);
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
     * require ext-* 는 필수, suggest ext-* 는 권장(사유 포함)으로 읽고, 외부 패키지·php 는 무시합니다.
     *
     * @scenario declaration=require_and_suggest
     *
     * @effects composer_ext_declarations_are_read
     *
     * @return void 반환값 없음
     */
    public function test_reads_require_and_suggest_ext_declarations(): void
    {
        $this->writeComposer([
            'require' => ['php' => '^8.2', 'ext-SOAP' => '*', 'vendor/pkg' => '^1.0'],
            'suggest' => ['ext-gd' => '썸네일에 필요', 'vendor/other' => '선택 패키지'],
        ]);

        $result = app(ExtensionManager::class)->getPhpExtensionRequirementsAt($this->dir);

        $this->assertSame(['soap'], $result['required']);
        $this->assertSame(['gd' => '썸네일에 필요'], $result['suggested']);
    }

    /**
     * composer.json 이 없거나 깨져 있으면 요구 없음으로 봅니다.
     *
     * @scenario declaration=absent
     *
     * @effects composer_ext_declarations_are_read
     *
     * @return void 반환값 없음
     */
    public function test_missing_or_broken_composer_means_no_requirement(): void
    {
        $manager = app(ExtensionManager::class);
        $this->assertSame(['required' => [], 'suggested' => []], $manager->getPhpExtensionRequirementsAt($this->dir));

        File::put($this->dir.'/composer.json', '{"require":');
        $this->assertSame(['required' => [], 'suggested' => []], $manager->getPhpExtensionRequirementsAt($this->dir));
    }

    /**
     * 미충족 계산은 주입한 PhpExtensionProbe 를 따릅니다.
     *
     * @scenario declaration=require_and_suggest
     *
     * @effects missing_lists_follow_probe
     *
     * @return void 반환값 없음
     */
    public function test_missing_lists_follow_injected_probe(): void
    {
        $this->writeComposer(['require' => ['ext-soap' => '*', 'ext-json' => '*'], 'suggest' => ['ext-gd' => '']]);
        $this->app->instance(PhpExtensionProbe::class, new class extends PhpExtensionProbe
        {
            /**
             * soap·gd 만 없는 것으로 답합니다.
             *
             * @param  string  $extension  확장 이름
             * @return bool 로드돼 있으면 true
             */
            public function loaded(string $extension): bool
            {
                return ! in_array($extension, ['soap', 'gd'], true);
            }
        });

        $result = app(ExtensionManager::class)->checkPhpExtensionRequirementsAt($this->dir);

        $this->assertSame(['soap'], $result['missing_required']);
        $this->assertSame(['gd'], $result['missing_suggested']);
    }

    /**
     * 기존 composer 의존 판정은 여전히 ext-* 를 버립니다. (composer install 판단 불변)
     *
     * @scenario declaration=require_and_suggest
     *
     * @effects composer_dependency_judgement_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_composer_dependencies_still_ignore_ext(): void
    {
        $this->writeComposer(['require' => ['php' => '^8.2', 'ext-soap' => '*']]);

        $this->assertSame([], app(ExtensionManager::class)->getComposerDependenciesAt($this->dir));
        $this->assertFalse(app(ExtensionManager::class)->hasComposerDependenciesAt($this->dir));
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
}
