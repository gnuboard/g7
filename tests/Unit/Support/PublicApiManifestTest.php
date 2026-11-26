<?php

namespace Tests\Unit\Support;

use App\Support\PublicApiManifest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 확장 공개 PHP 표면 선언 정규화 
 *
 * manifest `public_api` 는 다른 확장이 써도 되는 네임스페이스·클래스와 읽기 전용 Model 을 선언한다. 정적 룰
 * `extension-internal-symbol-reference` 와 관리자 확장 상세 「공개 API」 칸이 이 정규화 결과를 읽는다.
 */
class PublicApiManifestTest extends TestCase
{
    private const ROOT = 'Modules\\Vendor\\Shop';

    /**
     * 선언이 없거나 배열이 아니면 null.
     *
     * @return void 반환값 없음
     *
     * @scenario case=missing_or_non_array
     *
     * @effects public_api_missing_or_non_array_normalizes_to_null
     */
    #[Test]
    public function 선언이_없거나_배열이_아니면_null(): void
    {
        $this->assertNull(PublicApiManifest::normalize(null, self::ROOT));
        $this->assertNull(PublicApiManifest::normalize([], self::ROOT));
        $this->assertNull(PublicApiManifest::normalize('Modules\\Vendor\\Shop\\Contracts', self::ROOT));
    }

    /**
     * 지원하지 않는 판은 null.
     *
     * @return void 반환값 없음
     *
     * @scenario case=unsupported_version
     *
     * @effects public_api_unsupported_version_normalizes_to_null
     */
    #[Test]
    public function 지원하지_않는_판은_null(): void
    {
        $this->assertNull(PublicApiManifest::normalize(['version' => 2, 'namespaces' => [self::ROOT.'\\Contracts']], self::ROOT));
        $this->assertNull(PublicApiManifest::normalize(['version' => '1', 'namespaces' => [self::ROOT.'\\Contracts']], self::ROOT));
    }

    /**
     * 판을 생략하면 1로 읽고 세 목록을 모두 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=version_omitted
     *
     * @effects public_api_omitted_version_reads_as_1_with_three_lists
     */
    #[Test]
    public function 판을_생략하면_1로_읽고_세_목록을_모두_돌려준다(): void
    {
        $normalized = PublicApiManifest::normalize(['namespaces' => [self::ROOT.'\\Contracts']], self::ROOT);

        $this->assertSame([
            'version' => 1,
            'namespaces' => [self::ROOT.'\\Contracts'],
            'classes' => [],
            'readonly_models' => [],
        ], $normalized);
    }

    /**
     * 자기 네임스페이스 밖과 형식이 틀린 항목은 버린다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=foreign_or_malformed_entries
     *
     * @effects public_api_drops_foreign_namespace_and_malformed_entries
     */
    #[Test]
    public function 자기_네임스페이스_밖과_형식이_틀린_항목은_버린다(): void
    {
        $normalized = PublicApiManifest::normalize([
            'version' => 1,
            'namespaces' => [
                '\\'.self::ROOT.'\\Contracts\\',      // 앞뒤 역슬래시는 다듬는다
                'Modules\\Other\\Shop\\Contracts',    // 남의 네임스페이스
                self::ROOT,                           // 루트 자체는 공개할 수 없다(전부 공개와 같음)
                'Modules\\Vendor\\ShopExtra\\Enums',  // 접두만 같은 다른 확장
                'not a class',
                42,
            ],
            'classes' => [self::ROOT.'\\Support\\Path', self::ROOT.'\\Support\\Path', ['x']],
            'readonly_models' => 'Modules\\Vendor\\Shop\\Models\\Order',
        ], self::ROOT);

        $this->assertSame([self::ROOT.'\\Contracts'], $normalized['namespaces']);
        $this->assertSame([self::ROOT.'\\Support\\Path'], $normalized['classes']);
        $this->assertSame([], $normalized['readonly_models']);
    }

    /**
     * 공개 판정은 네임스페이스 하위와 클래스 목록이고 readonly model은 공개가 아니다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=public_judgement
     *
     * @effects public_api_is_public_covers_namespaces_and_classes_not_readonly_models
     */
    #[Test]
    public function 공개_판정은_네임스페이스_하위와_클래스_목록이고_readonly_model은_공개가_아니다(): void
    {
        $api = PublicApiManifest::normalize([
            'namespaces' => [self::ROOT.'\\Contracts'],
            'classes' => [self::ROOT.'\\Support\\Path'],
            'readonly_models' => [self::ROOT.'\\Models\\Order'],
        ], self::ROOT);

        $this->assertTrue(PublicApiManifest::isPublic($api, self::ROOT.'\\Contracts'));
        $this->assertTrue(PublicApiManifest::isPublic($api, self::ROOT.'\\Contracts\\Payment\\Bridge'));
        $this->assertTrue(PublicApiManifest::isPublic($api, '\\'.self::ROOT.'\\Support\\Path'));
        $this->assertFalse(PublicApiManifest::isPublic($api, self::ROOT.'\\ContractsExtra\\X'));
        $this->assertFalse(PublicApiManifest::isPublic($api, self::ROOT.'\\Models\\Order'));
        $this->assertFalse(PublicApiManifest::isPublic($api, self::ROOT.'\\Services\\OrderService'));
        $this->assertFalse(PublicApiManifest::isPublic(null, self::ROOT.'\\Contracts\\X'));
    }
}
