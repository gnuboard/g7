<?php

namespace Tests\Unit\Services;

use App\Exceptions\CustomAssetOperationException;
use App\Services\CustomAssetService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `custom/` 디렉토리 생성 실패 안내 테스트 (#651 D6·C2).
 *
 * `custom/` 은 지연 생성이라 `deploy:deploy 0755` 로 배포된 확장 디렉토리에서 운영자의 첫 저장이
 * 여기서 실패한다. 종전 문구는 경로만 적어 무엇을 고쳐야 하는지 알 수 없었다 — 사유·소유자·권한·
 * 실행 계정·조치 예시를 함께 싣는다(정적 게시 프리플라이트와 같은 식).
 */
class CustomAssetServiceDirectoryFailureTest extends TestCase
{
    /** 테스트용 가짜 플러그인 식별자 */
    private const FAKE_PLUGIN = 'g7test-dirfail';

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('plugins/'.self::FAKE_PLUGIN));

        parent::tearDown();
    }

    /**
     * 같은 이름의 **파일**이 `custom/` 자리를 차지하면 사유·조치가 실린 메시지로 실패한다.
     *
     * (POSIX 권한 거부는 Windows 에서 재현할 수 없어 파일 점유 사유로 실패 경로를 태운다 — 사유 코드는
     * 공통 프리미티브가 돌려주므로 매핑 경로는 같다.)
     *
     * @scenario custom_source=convention_scan, custom_asset=css
     *
     * @effects custom_directory_failure_explains_reason_and_action
     */
    #[Test]
    public function 디렉토리_생성_실패는_사유와_조치를_함께_알린다(): void
    {
        $pluginDir = base_path('plugins/'.self::FAKE_PLUGIN);
        File::ensureDirectoryExists($pluginDir);
        // `custom` 자리를 파일로 점유 — mkdir 이 실패하는 결정적 조건
        File::put($pluginDir.'/custom', 'not a directory');

        try {
            app(CustomAssetService::class)->save('plugins', self::FAKE_PLUGIN, 'custom.css', '/* x */');
            $this->fail('디렉토리를 만들 수 없는데 예외가 나지 않았다');
        } catch (CustomAssetOperationException $e) {
            $this->assertSame('custom_assets.errors.directory_failed', $e->errorKey);

            foreach (['path', 'reason', 'owner', 'perms', 'process_user', 'hint'] as $param) {
                $this->assertArrayHasKey($param, $e->params, "실패 메시지 파라미터 {$param} 이 없다");
            }

            // 사유 코드가 번역된 문장으로 실려야 한다 (키 노출 금지)
            $this->assertStringNotContainsString('custom_assets.', (string) $e->params['reason']);
            $this->assertStringNotContainsString('custom_assets.', (string) $e->params['hint']);
            $this->assertStringContainsString('chown', (string) $e->params['hint']);

            // 최종 문장에 치환 자리가 남아 있지 않다
            $this->assertStringNotContainsString(':reason', $e->getMessage());
            $this->assertStringNotContainsString(':hint', $e->getMessage());
        }
    }

    /**
     * 사유 코드 4종은 ko/en 양쪽에 번역이 있다 — 없는 코드는 키 문자열이 화면에 나간다.
     *
     * @effects custom_directory_failure_explains_reason_and_action
     */
    #[Test]
    public function 사유_코드_4종은_양_로케일에_번역이_있다(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $messages = require base_path("lang/{$locale}/custom_assets.php");

            foreach (['occupied_by_file', 'ancestor_not_writable', 'create_failed', 'not_writable'] as $code) {
                $this->assertNotEmpty($messages['errors']['reason'][$code] ?? null, "{$locale}: reason.{$code} 번역이 없다");
            }

            $this->assertNotEmpty($messages['errors']['directory_failed_hint'] ?? null, "{$locale}: directory_failed_hint 가 없다");
            $this->assertStringContainsString(':hint', $messages['errors']['directory_failed']);
        }
    }
}
