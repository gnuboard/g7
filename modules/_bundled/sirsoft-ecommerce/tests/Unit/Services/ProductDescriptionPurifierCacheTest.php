<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\InspectsHtmlPurifierCache;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Psr\Log\AbstractLogger;

/**
 * HTMLPurifier 정의 캐시 경로 회귀 테스트 (공개 #125)
 *
 * `sanitizeDescription()` 이 정의 캐시 경로를 지정하지 않으면 HTMLPurifier 는 자기 설치 폴더
 * (`{module}/vendor/ezyang/htmlpurifier/library/.../DefinitionCache/Serializer/`)에 캐시를 쓴다.
 * 배포본의 vendor 를 읽기 전용으로 두는 서버에서는 그 쓰기가 경고를 내고 Laravel 이 이를
 * `ErrorException` 으로 승격시켜 상품 등록/수정이 매번 500 이 된다.
 */
class ProductDescriptionPurifierCacheTest extends ModuleTestCase
{
    use InspectsHtmlPurifierCache;

    protected function setUp(): void
    {
        parent::setUp();

        // 하네스 결손(모듈 vendor 미오토로드)을 조용한 skip 으로 감추지 않는다 —
        // 로드되지 않으면 이 테스트가 검증하려는 코드 경로 자체가 실행되지 않는다.
        $this->assertTrue(
            class_exists(\HTMLPurifier::class),
            'HTMLPurifier 가 로드되지 않았습니다. 모듈 vendor 가 설치되어 있고 '
            .'tests/bootstrap.php 의 확장 vendor 오토로드 등록이 동작하는지 확인하세요.'
        );

        // 검증 대상이 이 테스트와 같은 트리(_bundled)의 사본인지 확인한다.
        // 모듈 vendor 의 composer 오토로더는 자기 PSR-4 를 **활성** 디렉토리로 매핑하고 자신을
        // prepend 로 등록하므로, 그것을 그대로 로드하면 테스트가 활성 사본을 검증하게 되어
        // `_bundled` 의 수정이 반영되지 않은 채로도 통과하거나 실패한다 (조용한 오판).
        $this->assertStringStartsWith(
            dirname(__DIR__, 3).DIRECTORY_SEPARATOR,
            (new \ReflectionClass(ProductService::class))->getFileName(),
            'ProductService 가 이 테스트와 다른 트리에서 로드되었습니다 — 모듈 vendor 오토로더가 '
            .'자기 PSR-4 를 등록해 활성 디렉토리 사본을 검증하고 있습니다.'
        );

        $this->purgePurifierCacheBase();
        $this->resetPurifierCacheNoticeFlag();
    }

    protected function tearDown(): void
    {
        $this->purgePurifierCacheBase();

        parent::tearDown();
    }

    /**
     * 테스트 캐시 디렉토리를 정리합니다 (파일이 자리를 차지한 경우 포함).
     */
    private function purgePurifierCacheBase(): void
    {
        $base = $this->purifierStorageBase();

        foreach ([$base, dirname($base)] as $path) {
            if (is_file($path)) {
                @unlink($path);

                continue;
            }

            File::deleteDirectory($path);
        }
    }

    /**
     * Log 파사드를 기록용 로거로 교체하고 그 기록기를 돌려줍니다.
     *
     * `Log::spy()` 는 쓰지 않는다 — `LogManager` 가 `__call` 로 임의 메서드를 받아 넘기므로
     * Mockery 스파이가 `shouldHaveReceived('error')` 를 `warning` 호출에도 통과시킨다(무효 단언).
     *
     * @return object `calls` 배열(level/message)을 보유한 기록기
     */
    private function swapLogRecorder(): object
    {
        $recorder = new class extends AbstractLogger
        {
            /** @var array<int, array{level: string, message: string}> */
            public array $calls = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->calls[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };

        Log::swap($recorder);

        return $recorder;
    }

    /**
     * 캐시 비활성 통지의 "프로세스당 1회" 플래그를 되돌립니다.
     *
     * 정적 플래그라 같은 프로세스의 앞선 테스트가 이미 세워 두면 통지가 발화하지 않아,
     * 통지 단언이 테스트 순서에 따라 조용히 통과/실패한다.
     */
    private function resetPurifierCacheNoticeFlag(): void
    {
        $property = new \ReflectionProperty(ProductService::class, 'purifierCacheWarned');
        $property->setAccessible(true);
        $property->setValue(null, false);
    }

    /**
     * `sanitizeDescription()` 을 호출하고 사용된 서비스 인스턴스를 돌려줍니다.
     *
     * @param  array  $data  상품 데이터
     * @return array{0: ProductService, 1: array} 서비스 인스턴스와 정화 결과
     */
    private function sanitize(array $data): array
    {
        // 생성자 의존성이 7개라 수동 new 가 성립하지 않는다.
        $service = app(ProductService::class);

        $method = new \ReflectionMethod($service, 'sanitizeDescription');
        $method->setAccessible(true);

        return [$service, $method->invoke($service, $data)];
    }

    /**
     * 서비스가 보유한 HTMLPurifier 인스턴스를 꺼냅니다.
     *
     * @param  ProductService  $service  대상 서비스
     * @return \HTMLPurifier|null 인스턴스 (미생성이면 null)
     */
    private function purifierOf(ProductService $service): ?\HTMLPurifier
    {
        $property = new \ReflectionProperty($service, 'purifier');
        $property->setAccessible(true);

        return $property->getValue($service);
    }

    /**
     * HTML 모드 정화 시 정의 캐시가 vendor 가 아니라 storage 아래에 기록됩니다.
     *
     * 거짓 green 경고: 개발 머신 vendor 에는 이미 `.ser` 가 있고 설정 해시가 같아 파일명이
     * 동일하므로 "vendor 스냅샷 불변" 단언은 수정 전에도 통과할 수 있다. 결함을 증명하는 것은
     * storage 쪽 단언(`Cache.SerializerPath` / `generateFilePath` / `assertFileExists`)이다.
     *
     * @scenario description_mode=html, cache_dir=writable
     *
     * @effects definition_cache_written_under_storage, vendor_install_directory_never_written
     */
    public function test_definition_cache_is_written_under_storage_not_vendor(): void
    {
        $storageBase = $this->purifierStorageBase();
        $vendorBefore = $this->serSnapshot($this->vendorSerializerBase());

        [$service, $result] = $this->sanitize([
            'description_mode' => 'html',
            'description' => ['ko' => '<p>본문</p><script>alert(1)</script>'],
        ]);

        $this->assertStringContainsString('<p>본문</p>', $result['description']['ko']);
        $this->assertStringNotContainsString('<script', $result['description']['ko']);

        $purifier = $this->purifierOf($service);
        $this->assertNotNull($purifier);

        $config = $purifier->config;
        $this->assertSame($storageBase, $config->get('Cache.SerializerPath'));

        $cacheFile = (new \HTMLPurifier_DefinitionCache_Serializer('HTML'))->generateFilePath($config);
        $this->assertStringStartsWith($storageBase, $cacheFile);
        $this->assertFileExists($cacheFile);

        $this->assertSame(
            $vendorBefore,
            $this->serSnapshot($this->vendorSerializerBase()),
            'HTMLPurifier 설치 폴더에 정의 캐시가 기록되었습니다.'
        );
    }

    /**
     * text 모드는 HTMLPurifier 를 인스턴스화하지 않습니다.
     *
     * @scenario description_mode=text, cache_dir=writable
     *
     * @effects text_mode_skips_purifier_entirely
     */
    public function test_text_mode_never_touches_purifier(): void
    {
        [$service, $result] = $this->sanitize([
            'description_mode' => 'text',
            'description' => ['ko' => '<p>본문</p><script>alert(1)</script>'],
        ]);

        $this->assertSame('<p>본문</p><script>alert(1)</script>', $result['description']['ko']);
        $this->assertNull($this->purifierOf($service));
        $this->assertDirectoryDoesNotExist($this->purifierStorageBase());
    }

    /**
     * 캐시 디렉토리를 확보하지 못하면 캐시만 끄고 정화는 그대로 수행합니다.
     *
     * 목적지에 같은 이름의 **파일**을 놓아 재현한다 — ACL/chmod 없이 Windows·POSIX 양쪽에서
     * 결정적이다.
     *
     * 거짓 green 경고: vendor 스냅샷 불변 단언은 수정 전에도 통과할 수 있다(위 참조).
     * 이 테스트의 판정은 `Cache.DefinitionImpl === null` 이다.
     *
     * 통지 수준을 함께 잠근다 — 이 폴백은 저장을 성공시키므로 운영자에게 도달하는 흔적이
     * 그 통지 하나뿐인데, G7 출하 기본 로그 수준(`config/settings/defaults.json` 의 `log_level`)이
     * `error` 라 `warning` 으로 남기면 기본 설치 상태에서 파일에 기록되지 않는다.
     *
     * @scenario description_mode=html, cache_dir=occupied_by_file
     *
     * @effects purify_still_strips_script_when_cache_disabled, vendor_install_directory_never_written, cache_disabled_notice_reaches_default_log_level
     */
    public function test_falls_back_to_disabled_definition_cache_when_directory_unusable(): void
    {
        $base = $this->purifierStorageBase();
        File::ensureDirectoryExists(dirname($base));
        file_put_contents($base, 'occupied');

        $vendorBefore = $this->serSnapshot($this->vendorSerializerBase());

        $recorder = $this->swapLogRecorder();

        [$service, $result] = $this->sanitize([
            'description_mode' => 'html',
            'description' => ['ko' => '<p>본문</p><script>alert(1)</script>'],
        ]);

        $notices = array_values(array_filter(
            $recorder->calls,
            fn (array $call): bool => str_contains($call['message'], 'HTMLPurifier 정의 캐시 디렉토리를 사용할 수 없어')
        ));

        $this->assertCount(1, $notices, '캐시 비활성 통지가 프로세스당 1회 발화해야 합니다.');
        $this->assertSame(
            'error',
            $notices[0]['level'],
            '캐시 비활성 통지는 error 수준이어야 합니다 — 출하 기본 로그 수준이 error 라 '
            .'warning 으로 남기면 기본 설치 상태에서 로그 파일에 기록되지 않고, 이 폴백은 저장을 '
            .'성공시키므로 운영자에게 도달하는 흔적이 이 통지 하나뿐입니다.'
        );

        $this->assertStringContainsString('<p>본문</p>', $result['description']['ko']);
        $this->assertStringNotContainsString('<script', $result['description']['ko']);

        $purifier = $this->purifierOf($service);
        $this->assertNotNull($purifier);
        $this->assertNull($purifier->config->get('Cache.DefinitionImpl'));

        $this->assertSame(
            $vendorBefore,
            $this->serSnapshot($this->vendorSerializerBase()),
            'HTMLPurifier 설치 폴더에 정의 캐시가 기록되었습니다.'
        );
    }

    /**
     * 캐시 디렉토리 생성 실패가 예외로 승격되지 않습니다.
     *
     * `File::ensureDirectoryExists()` 는 `mkdir()` 을 억제 없이 호출하므로, 생성이 실패하면
     * `E_WARNING` 이 나오고 Laravel `HandleExceptions` 가 이를 `ErrorException` 으로 승격시켜
     * 요청이 500 이 된다 — 이 이슈가 막으려던 실패가 다른 줄에서 그대로 재현되는 형태다.
     * 부모 자리에 파일을 놓아 재현한다(ACL/chmod 없이 Windows·POSIX 양쪽에서 결정적).
     *
     * @scenario description_mode=html, cache_dir=parent_unusable
     *
     * @effects cache_dir_creation_failure_does_not_escalate, purify_still_strips_script_when_cache_disabled
     */
    public function test_directory_creation_failure_does_not_escalate_to_exception(): void
    {
        $base = $this->purifierStorageBase();
        $parent = dirname($base);

        File::deleteDirectory($parent);
        File::ensureDirectoryExists(dirname($parent));
        file_put_contents($parent, 'occupied');

        $this->assertFalse(is_dir($base));
        $this->assertFalse(file_exists($base), '부모가 파일이면 자식 경로는 존재하지 않아야 한다 (전제 확인).');

        [$service, $result] = $this->sanitize([
            'description_mode' => 'html',
            'description' => ['ko' => '<p>본문</p><script>alert(1)</script>'],
        ]);

        $this->assertStringContainsString('<p>본문</p>', $result['description']['ko']);
        $this->assertStringNotContainsString('<script', $result['description']['ko']);
        $this->assertNull($this->purifierOf($service)->config->get('Cache.DefinitionImpl'));
    }
}
