<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\TemplateManager;
use App\Extension\Traits\ComputesLayoutContentHash;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 레이아웃 원본 해시 — 빈 객체(`{}`)가 DB 를 거쳐 빈 배열(`[]`)로 읽혀도 같은 해시 (#138 실측 ⑥)
 *
 * 레이아웃 파일을 컴파일한 본문은 빈 객체를 객체로 들고 있어 설치 때 해시가 `{}` 로 계산됐다. 그런데 DB 에서
 * 읽은 본문은 배열 캐스트로 `[]` 가 되어, 빈 객체가 하나라도 있는 레이아웃은 **아무도 고치지 않았는데**
 * "사용자 수정본"으로 판정됐다. 그래서 `keep` 업데이트가 그 레이아웃의 새 판(조각 변경 포함)을 반영하지 않았고,
 * 업데이트 창은 "수정된 레이아웃 N개"를 잘못 알렸다(개발 사이트 관리자 템플릿 20건 전부가 이 오판).
 */
class LayoutContentHashEmptyObjectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 트레이트의 해시 계산을 노출하는 도우미를 만듭니다.
     *
     * @return object 해시 계산기
     */
    private function hasher(): object
    {
        return new class
        {
            use ComputesLayoutContentHash;

            /**
             * 해시를 계산합니다.
             *
             * @param  array|string  $content  본문
             * @return string 해시
             */
            public function hash(array|string $content): string
            {
                return $this->computeContentHash($content);
            }

            /**
             * 크기를 계산합니다.
             *
             * @param  array|string  $content  본문
             * @return int 크기
             */
            public function size(array|string $content): int
            {
                return $this->computeContentSize($content);
            }
        };
    }

    /**
     * 파일 컴파일 본문(빈 객체 유지)·DB 에서 읽은 본문(빈 배열)·JSON 문자열이 같은 해시를 낸다.
     *
     * @scenario case=hash_normalization
     *
     * @effects empty_object_and_empty_array_hash_identically
     *
     * @return void 반환값 없음
     */
    public function test_empty_object_and_its_db_round_trip_hash_identically(): void
    {
        $fromFile = ['params' => ['query' => new \stdClass], 'items' => [], 'name' => '목록'];
        $fromDb = json_decode(json_encode($fromFile), true);

        $hasher = $this->hasher();
        $this->assertSame($hasher->hash($fromFile), $hasher->hash($fromDb));
        $this->assertSame($hasher->hash($fromFile), $hasher->hash(json_encode($fromFile)));
        $this->assertSame($hasher->size($fromFile), $hasher->size($fromDb));
    }

    /**
     * 파일 그대로 설치한 레이아웃은 DB 를 거친 뒤에도 "수정됨"으로 판정되지 않는다.
     *
     * @scenario case=installed_untouched_layout
     *
     * @effects untouched_layout_with_empty_object_is_not_reported_modified
     *
     * @return void 반환값 없음
     */
    public function test_installed_layout_with_empty_object_is_not_reported_as_modified(): void
    {
        $template = Template::create([
            'identifier' => 'test-hash-admin',
            'vendor' => 'test',
            'name' => ['ko' => '해시 테스트', 'en' => 'Hash Test'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 설치 경로와 같게 — 파일 컴파일 본문으로 해시를 계산해 저장한다
        $fromFile = ['layout_name' => 'test_list', 'params' => ['query' => new \stdClass], 'components' => []];
        $hasher = $this->hasher();
        DB::table('template_layouts')->insert([
            'template_id' => $template->id,
            'name' => 'test_list',
            'content' => json_encode($fromFile),
            'source_type' => 'template',
            'source_identifier' => null,
            'original_content_hash' => $hasher->hash($fromFile),
            'original_content_size' => $hasher->size($fromFile),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(TemplateManager::class)->hasModifiedLayouts('test-hash-admin');

        $this->assertSame(0, $result['modified_count'], '아무도 고치지 않은 레이아웃이 수정본으로 판정됐다');
    }
}
