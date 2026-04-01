<?php

namespace Tests\Unit\Casts;

use App\Casts\AsUnicodeJson;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

class AsUnicodeJsonTest extends TestCase
{
    private AsUnicodeJson $cast;

    private Model $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cast = new AsUnicodeJson;
        $this->model = new class extends Model {};
    }

    /**
     * null 입력 시 null을 반환합니다.
     */
    public function test_get_returns_null_for_null_value(): void
    {
        $result = $this->cast->get($this->model, 'name', null, []);

        $this->assertNull($result);
    }

    /**
     * JSON 문자열을 배열로 디코딩합니다.
     */
    public function test_get_decodes_json_string_to_array(): void
    {
        $json = '{"ko":"테스트","en":"test"}';

        $result = $this->cast->get($this->model, 'name', $json, []);

        $this->assertIsArray($result);
        $this->assertSame('테스트', $result['ko']);
        $this->assertSame('test', $result['en']);
    }

    /**
     * Unicode 이스케이프된 JSON도 올바르게 디코딩합니다.
     */
    public function test_get_decodes_unicode_escaped_json(): void
    {
        $json = '{"\ud55c\uad6d\uc5b4":"\uac12"}';

        $result = $this->cast->get($this->model, 'name', $json, []);

        $this->assertIsArray($result);
        $this->assertSame('값', $result['한국어']);
    }

    /**
     * null 입력 시 null을 반환합니다 (set).
     */
    public function test_set_returns_null_for_null_value(): void
    {
        $result = $this->cast->set($this->model, 'name', null, []);

        $this->assertNull($result);
    }

    /**
     * 한글을 Unicode 이스케이프 없이 실제 UTF-8로 인코딩합니다.
     */
    public function test_set_encodes_korean_as_utf8(): void
    {
        $data = ['ko' => '스테인리스 텀블러', 'en' => 'Stainless Tumbler'];

        $result = $this->cast->set($this->model, 'name', $data, []);

        $this->assertIsString($result);
        // 실제 UTF-8 문자가 포함되어야 함
        $this->assertStringContainsString('스테인리스 텀블러', $result);
        // \uXXXX 이스케이프가 없어야 함
        $this->assertStringNotContainsString('\\u', $result);
    }

    /**
     * 슬래시를 이스케이프하지 않습니다.
     */
    public function test_set_does_not_escape_slashes(): void
    {
        $data = ['ko' => 'URL: https://example.com/path'];

        $result = $this->cast->set($this->model, 'name', $data, []);

        $this->assertStringContainsString('https://example.com/path', $result);
        $this->assertStringNotContainsString('\\/', $result);
    }

    /**
     * 빈 배열을 올바르게 인코딩합니다.
     */
    public function test_set_encodes_empty_array(): void
    {
        $result = $this->cast->set($this->model, 'name', [], []);

        $this->assertSame('[]', $result);
    }

    /**
     * 중첩 배열을 올바르게 인코딩합니다.
     */
    public function test_set_encodes_nested_array_with_korean(): void
    {
        $data = [
            'ko' => ['title' => '상품명', 'desc' => '설명'],
            'en' => ['title' => 'Product', 'desc' => 'Description'],
        ];

        $result = $this->cast->set($this->model, 'name', $data, []);

        $this->assertStringContainsString('상품명', $result);
        $this->assertStringContainsString('설명', $result);
        $this->assertStringNotContainsString('\\u', $result);
    }

    /**
     * set → get 라운드트립이 원본 데이터를 보존합니다.
     */
    public function test_roundtrip_preserves_data(): void
    {
        $original = ['ko' => '한글 테스트', 'en' => 'English test', 'ja' => '日本語'];

        $encoded = $this->cast->set($this->model, 'name', $original, []);
        $decoded = $this->cast->get($this->model, 'name', $encoded, []);

        $this->assertSame($original, $decoded);
    }

    /**
     * CJK 문자 (한국어, 일본어, 중국어)를 모두 UTF-8로 저장합니다.
     */
    public function test_set_encodes_all_cjk_as_utf8(): void
    {
        $data = ['ko' => '한국어', 'ja' => '日本語', 'zh' => '中文'];

        $result = $this->cast->set($this->model, 'name', $data, []);

        $this->assertStringContainsString('한국어', $result);
        $this->assertStringContainsString('日本語', $result);
        $this->assertStringContainsString('中文', $result);
        $this->assertStringNotContainsString('\\u', $result);
    }
}
