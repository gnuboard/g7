<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit\Support;

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use App\Models\User;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Support\ApiDoc\ApiDocSampleService;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * sirsoft-ckeditor5 API 문서 실측용 완전 샘플 시더 테스트.
 *
 * 이미지 서빙 GET(`images/{hash}`)의 요청 예시 path 치환을 위한 고정 해시 이미지
 * 대표 샘플이 생성되고, `images` 도메인의 path_params 맵이 반환되며, 재실행 시
 * 멱등한지 검증한다.
 */
class ApiDocSampleServiceTest extends PluginTestCase
{
    /**
     * @var string 샘플 이미지 해시
     */
    private const SAMPLE_HASH = 'a1b2c3d4e5f6';

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create([
            'email' => 'apidoc-sample-user@example.com',
            'name' => 'API 문서 샘플 사용자',
        ]);
    }

    /**
     * 시더가 계약을 구현한다.
     */
    public function test_seeder_implements_contract(): void
    {
        $this->assertInstanceOf(ApiDocSampleSeeder::class, new ApiDocSampleService);
    }

    /**
     * 시더가 고정 해시(라우트 제약 `[a-f0-9]{12}` 충족)의 이미지 대표 샘플을 생성한다.
     */
    public function test_seed_creates_image_with_valid_hash(): void
    {
        (new ApiDocSampleService)->seed();

        $image = Ckeditor5ImageUpload::query()->where('hash', self::SAMPLE_HASH)->first();

        $this->assertNotNull($image);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $image->hash);
    }

    /**
     * 시더가 images 도메인의 path_params 맵을 반환한다.
     */
    public function test_seed_returns_images_path_params_map(): void
    {
        $map = (new ApiDocSampleService)->seed();

        $this->assertArrayHasKey('images', $map);
        $entry = $map['images'];
        $this->assertSame(Ckeditor5ImageUpload::class, $entry['model']);
        $this->assertSame('hash', $entry['key']);
        $this->assertArrayHasKey('hash', $entry['path_params']);
        $this->assertSame(self::SAMPLE_HASH, $entry['path_params']['hash']);
    }

    /**
     * 재실행 시 이미지가 중복 생성되지 않는다 (멱등).
     */
    public function test_seed_is_idempotent(): void
    {
        (new ApiDocSampleService)->seed();
        (new ApiDocSampleService)->seed();

        $this->assertSame(1, Ckeditor5ImageUpload::query()->where('hash', self::SAMPLE_HASH)->count());
    }
}
