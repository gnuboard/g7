<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Layout\FragmentReadCache;
use App\Support\ExtensionDoc\ExtensionDocScaffolder;
use App\Support\ExtensionDoc\ExtensionInventory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 확장 문서 생성기가 깨진 조각을 만나면 실패 로그에 그 확장 식별자를 싣는다 (#725 W12).
 *
 * 문서 생성기는 조각 표를 만들려고 등록 경로와 같은 규약으로 조각을 읽는다. 그 읽기 문맥이 확장 레코드에 없는
 * 키(`identifier`)를 읽어 실패 로그의 소유 확장이 늘 `null` 이었다 — 어느 확장의 조각인지 경로로만 짐작해야 했다.
 */
class ExtensionDocFragmentLogContextTest extends TestCase
{
    private string $tmp;

    /**
     * 깨진 조각 하나를 가진 임시 확장 디렉토리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = base_path('storage/framework/testing/docgen_fragment_'.substr(md5(uniqid('', true)), 0, 8));
        File::ensureDirectoryExists($this->tmp.'/resources/extensions');
        File::put($this->tmp.'/resources/extensions/broken-card.g7.html',
            "<extension target-layout=\"admin_user_form\" priority=\"100\">\n  <inject target-id=\"user_form\" position=\"append_child\">\n    <div/>\n");
    }

    /**
     * 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        // 실패 기억은 프로세스 단위다 — 같은 프로세스의 다음 테스트로 새지 않게 비운다.
        FragmentReadCache::flush();

        parent::tearDown();
    }

    /**
     * 조각 표를 그리다 읽기에 실패하면 로그 문맥 `extension` 이 그 확장 식별자다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects docgen_fragment_failure_names_extension
     *
     * @return void 반환값 없음
     */
    public function test_docgen_fragment_failure_names_the_extension(): void
    {
        Log::spy();

        $method = new \ReflectionMethod(ExtensionDocScaffolder::class, 'renderLayoutExtensions');
        $markdown = $method->invoke(new ExtensionDocScaffolder, [
            'record' => ['type' => ExtensionInventory::TYPE_MODULE, 'id' => 'probe-docgen-module', 'path' => $this->tmp],
            'frontend' => ['layoutExtensions' => ['resources/extensions/broken-card.g7.html']],
            'surface' => ['values' => ['getLayoutExtensions' => []]],
        ]);

        $this->assertStringContainsString('읽기 실패', $markdown, '전제: 읽지 못한 조각은 표에 실패로 실린다');
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'broken-card.g7.html')
                && ($context['extension'] ?? null) === 'probe-docgen-module')
            ->once();
    }
}
