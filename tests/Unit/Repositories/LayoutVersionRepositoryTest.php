<?php

namespace Tests\Unit\Repositories;

use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use App\Repositories\LayoutVersionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutVersionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 스위트의 레이아웃/GDPR 미들웨어 의존 테스트와 migrate:fresh 정합성을
     * 맞추기 위해 GDPR 플러그인 마이그레이션을 일관 선언한다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private LayoutVersionRepository $repository;

    private Template $template;

    private TemplateLayout $layout;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new LayoutVersionRepository(new TemplateLayoutVersion);
        $this->template = Template::factory()->create();
        $this->layout = TemplateLayout::factory()->create(['template_id' => $this->template->id]);
    }

    /**
     * saveVersion 메서드 테스트 - 첫 번째 버전
     */
    public function test_save_version_creates_first_version(): void
    {
        // Arrange
        $content = [
            'version' => '1.0.0',
            'components' => [
                ['type' => 'header', 'props' => ['title' => 'Test']],
            ],
        ];

        // Act
        $version = $this->repository->saveVersion($this->layout->id, $content);

        // Assert
        $this->assertInstanceOf(TemplateLayoutVersion::class, $version);
        $this->assertEquals(1, $version->version);
        $this->assertEquals($this->layout->id, $version->layout_id);
        $this->assertEquals($content, $version->content);
    }

    /**
     * saveVersion 메서드 테스트 - 버전 자동 증가
     */
    public function test_save_version_auto_increments_version_number(): void
    {
        // Arrange
        TemplateLayoutVersion::factory()->create([
            'layout_id' => $this->layout->id,
            'version' => 1,
        ]);

        TemplateLayoutVersion::factory()->create([
            'layout_id' => $this->layout->id,
            'version' => 2,
        ]);

        $content = ['test' => 'data'];

        // Act
        $newVersion = $this->repository->saveVersion($this->layout->id, $content);

        // Assert
        $this->assertEquals(3, $newVersion->version);
        $this->assertEquals($content, $newVersion->content);
    }

    /**
     * getVersions 메서드 테스트 - 최신순 정렬
     */
    public function test_get_versions_returns_latest_first(): void
    {
        // Arrange
        $version1 = TemplateLayoutVersion::factory()->create([
            'layout_id' => $this->layout->id,
            'version' => 1,
        ]);

        $version2 = TemplateLayoutVersion::factory()->create([
            'layout_id' => $this->layout->id,
            'version' => 2,
        ]);

        $version3 = TemplateLayoutVersion::factory()->create([
            'layout_id' => $this->layout->id,
            'version' => 3,
        ]);

        // Act
        $versions = $this->repository->getVersions($this->layout->id);

        // Assert
        $this->assertCount(3, $versions);
        $this->assertEquals(3, $versions->first()->version);
        $this->assertEquals(1, $versions->last()->version);
    }

    /**
     * getVersions 메서드 테스트 - 빈 결과
     */
    public function test_get_versions_returns_empty_collection_when_no_versions(): void
    {
        // Act
        $versions = $this->repository->getVersions($this->layout->id);

        // Assert
        $this->assertCount(0, $versions);
        $this->assertTrue($versions->isEmpty());
    }

    /**
     * getVersion 메서드 테스트 - 성공
     */
    public function test_get_version_returns_specific_version(): void
    {
        // Arrange
        $version = TemplateLayoutVersion::factory()->create([
            'layout_id' => $this->layout->id,
            'version' => 1,
        ]);

        // Act
        $result = $this->repository->getVersion($version->id);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($version->id, $result->id);
        $this->assertEquals($version->version, $result->version);
    }

    /**
     * getVersion 메서드 테스트 - 존재하지 않는 버전
     */
    public function test_get_version_returns_null_when_not_found(): void
    {
        // Act
        $result = $this->repository->getVersion(99999);

        // Assert
        $this->assertNull($result);
    }

    /**
     * getNextVersion 메서드 테스트 - 버전이 없을 때
     */
    public function test_get_next_version_returns_one_when_no_versions(): void
    {
        // Act
        $nextVersion = $this->repository->getNextVersion($this->layout->id);

        // Assert
        $this->assertEquals(1, $nextVersion);
    }

    /**
     * getNextVersion 메서드 테스트 - 기존 버전이 있을 때
     */
    public function test_get_next_version_returns_max_plus_one(): void
    {
        // Arrange
        TemplateLayoutVersion::factory()->create([
            'layout_id' => $this->layout->id,
            'version' => 1,
        ]);

        TemplateLayoutVersion::factory()->create([
            'layout_id' => $this->layout->id,
            'version' => 5,
        ]);

        TemplateLayoutVersion::factory()->create([
            'layout_id' => $this->layout->id,
            'version' => 3,
        ]);

        // Act
        $nextVersion = $this->repository->getNextVersion($this->layout->id);

        // Assert
        $this->assertEquals(6, $nextVersion); // max(5) + 1
    }

    /**
     * 다른 레이아웃의 버전은 영향을 주지 않는지 테스트
     */
    public function test_versions_are_isolated_by_layout(): void
    {
        // Arrange
        $otherLayout = TemplateLayout::factory()->create(['template_id' => $this->template->id]);

        TemplateLayoutVersion::factory()->create([
            'layout_id' => $otherLayout->id,
            'version' => 10,
        ]);

        // Act
        $nextVersion = $this->repository->getNextVersion($this->layout->id);
        $versions = $this->repository->getVersions($this->layout->id);

        // Assert
        $this->assertEquals(1, $nextVersion); // 다른 레이아웃의 버전은 무시
        $this->assertCount(0, $versions);
    }

    /**
     * recalculateAllChangeSummaries - 기존 버전의 changes_summary 를 라인 단위로 재계산
     *
     * 옛 기준(키 경로 단위)의 잘못된 요약이 담긴 버전들을 만들고, 재계산 후 인접 버전
     * 대비 라인 단위로 정확히 갱신되는지 검증한다. 첫 버전은 baseline(빈 요약).
     *
     * @effects changes_summary_recalculated_for_existing_versions
     */
    public function test_recalculate_all_change_summaries_fixes_stale_summaries(): void
    {
        // Arrange — 옛 기준의 엉뚱한 요약을 직접 심어 둔 3개 버전
        $contentV1 = ['children' => [['name' => 'Span', 'text' => 't', 'type' => 'basic']]];
        $contentV2 = ['children' => [
            ['name' => 'Span', 'text' => 't', 'type' => 'basic'],
            ['name' => 'Span', 'text' => 't', 'type' => 'basic'],
        ]];
        $contentV3 = ['children' => [['name' => 'Span', 'text' => 't', 'type' => 'basic']]];

        $v1 = TemplateLayoutVersion::factory()->create([
            'layout_id' => $this->layout->id,
            'version' => 1,
            'content' => $contentV1,
            'changes_summary' => ['added' => 7, 'removed' => 7, 'char_diff' => 999],
        ]);
        $v2 = TemplateLayoutVersion::factory()->create([
            'layout_id' => $this->layout->id,
            'version' => 2,
            'content' => $contentV2,
            'changes_summary' => ['added' => 1, 'removed' => 0, 'char_diff' => 1],
        ]);
        $v3 = TemplateLayoutVersion::factory()->create([
            'layout_id' => $this->layout->id,
            'version' => 3,
            'content' => $contentV3,
            'changes_summary' => ['added' => 0, 'removed' => 1, 'char_diff' => -1],
        ]);

        // Act
        $count = $this->repository->recalculateAllChangeSummaries();

        // Assert
        $this->assertEquals(3, $count);

        // v1 = baseline (빈 요약 — 카운트 0)
        $v1->refresh();
        $this->assertSame(0, $v1->changes_summary['added']);
        $this->assertSame(0, $v1->changes_summary['removed']);
        $this->assertSame(0, $v1->changes_summary['char_diff']);

        // v2 = Span 객체 1개 추가 → 라인 5줄 추가 (직전 }→},  + {, name, text, type)
        $v2->refresh();
        $this->assertSame(5, $v2->changes_summary['added']);
        $this->assertSame(0, $v2->changes_summary['removed']);

        // v3 = Span 객체 1개 삭제 → 라인 5줄 삭제
        $v3->refresh();
        $this->assertSame(5, $v3->changes_summary['removed']);
        $this->assertSame(0, $v3->changes_summary['added']);
    }

    /**
     * restoreVersion - 복원은 content 만 의존하며 changes_summary 카운트 구조와 무관
     *
     * 검증: changes_summary 를 라인 원문 배열 → 카운트 정수로 바꿔도 복원에 문제가
     * 없는가? 복원은 복원 대상 버전의 content 를 레이아웃에 그대로 적용하고, changes_summary
     * 는 결과로 새로 계산할 뿐 복원 입력으로 읽지 않는다. 따라서 카운트 구조여도 복원된
     * content 가 정확하고, 새 버전의 changes_summary 는 카운트(정수)로 기록된다.
     */
    public function test_restore_version_restores_content_regardless_of_summary_structure(): void
    {
        // Arrange — v1(작은 content) → v2(큰 content) 저장 후, 현재 레이아웃은 v2 상태
        $contentV1 = ['components' => [['name' => 'A']]];
        $contentV2 = ['components' => [['name' => 'A'], ['name' => 'B'], ['name' => 'C']]];

        $v1 = $this->repository->saveVersion($this->layout->id, $contentV1);
        $this->layout->update(['content' => $contentV2]);
        $this->repository->saveVersion($this->layout->id, $contentV2, $contentV1);

        // Act — v1 으로 복원
        $restored = $this->repository->restoreVersion($this->layout->id, $v1->id);

        // Assert — 1) 레이아웃 content 가 v1 으로 정확히 복원됨 (content 만 의존)
        $this->layout->refresh();
        $this->assertEquals($contentV1, $this->layout->content, '복원은 대상 버전 content 를 그대로 적용한다');

        // 2) 복원으로 적재된 새 버전의 content 도 v1 (복원본)
        $this->assertEquals($contentV1, $restored->content);

        // 3) 새 버전의 changes_summary 는 카운트(정수) 구조 — 복원으로 줄었으므로 removed > 0
        $this->assertIsInt($restored->changes_summary['added']);
        $this->assertIsInt($restored->changes_summary['removed']);
        $this->assertGreaterThan(0, $restored->changes_summary['removed'], '큰 content→작은 content 복원이므로 라인 삭제');
        $this->assertSame(0, $restored->changes_summary['added']);
    }

    /**
     * saveVersion 연속 호출 테스트
     */
    public function test_save_version_sequential_calls(): void
    {
        // Arrange
        $content1 = ['data' => 'version 1'];
        $content2 = ['data' => 'version 2'];
        $content3 = ['data' => 'version 3'];

        // Act
        $v1 = $this->repository->saveVersion($this->layout->id, $content1);
        $v2 = $this->repository->saveVersion($this->layout->id, $content2);
        $v3 = $this->repository->saveVersion($this->layout->id, $content3);

        // Assert
        $this->assertEquals(1, $v1->version);
        $this->assertEquals(2, $v2->version);
        $this->assertEquals(3, $v3->version);

        $versions = $this->repository->getVersions($this->layout->id);
        $this->assertCount(3, $versions);
    }

    /**
     * calculateChanges 라인 단위 측정 - 키 추가
     *
     * changes_summary 는 버전 비교 diff 뷰(프론트 lineDiff.ts)와 동일한 "라인" 단위로
     * 센다. 키 2개 추가 = pretty JSON 라인 2줄 추가(스칼라 값 키는 1키=1줄).
     */
    public function test_calculate_changes_counts_added_lines(): void
    {
        $old = ['name' => 'test', 'version' => '1.0.0'];
        $new = ['name' => 'test', 'version' => '1.0.0', 'description' => 'new field', 'author' => 'John Doe'];

        $changes = $this->repository->calculateChanges($old, $new);

        $this->assertSame(2, $changes['added'], '스칼라 키 2개 추가 = 라인 2줄');
        $this->assertSame(0, $changes['removed']);
    }

    /**
     * calculateChanges 라인 단위 측정 - 키 삭제
     */
    public function test_calculate_changes_counts_removed_lines(): void
    {
        $old = ['name' => 'test', 'version' => '1.0.0', 'deprecated' => 'old field', 'legacy' => 'x'];
        $new = ['name' => 'test', 'version' => '1.0.0'];

        $changes = $this->repository->calculateChanges($old, $new);

        $this->assertSame(2, $changes['removed'], '스칼라 키 2개 삭제 = 라인 2줄');
        $this->assertSame(0, $changes['added']);
    }

    /**
     * calculateChanges 라인 단위 측정 - 값 수정은 삭제+추가 라인으로 표현
     *
     * 라인 diff 에는 "수정" 개념이 없다 — 값이 바뀐 키는 옛 라인 삭제 + 새 라인 추가로
     * 나타난다(diff 뷰의 -/+ 와 동일). 스칼라 값 2개 변경 = removed 2 + added 2.
     */
    public function test_calculate_changes_value_change_is_remove_plus_add(): void
    {
        $old = ['name' => 'test', 'version' => '1.0.0', 'status' => 'draft'];
        $new = ['name' => 'test', 'version' => '2.0.0', 'status' => 'published'];

        $changes = $this->repository->calculateChanges($old, $new);

        $this->assertSame(2, $changes['added'], '값 2개 변경 = 새 라인 2줄');
        $this->assertSame(2, $changes['removed'], '값 2개 변경 = 옛 라인 2줄');
    }

    /**
     * calculateChanges 라인 단위 측정 - 변경 없음
     */
    public function test_calculate_changes_no_changes_yields_empty(): void
    {
        $old = ['name' => 'test', 'config' => ['theme' => 'dark']];
        $new = ['name' => 'test', 'config' => ['theme' => 'dark']];

        $changes = $this->repository->calculateChanges($old, $new);

        $this->assertSame(0, $changes['added']);
        $this->assertSame(0, $changes['removed']);
        $this->assertSame(0, $changes['char_diff']);
    }

    /**
     * calculateChanges 라인 단위 측정 - 키 순서 무관 동일성
     *
     * 키 정렬 직렬화이므로 같은 내용·다른 키순은 변경 없음으로 본다(노이즈 diff 방지).
     */
    public function test_calculate_changes_ignores_key_order(): void
    {
        $old = ['b' => 1, 'a' => 2, 'c' => 3];
        $new = ['a' => 2, 'c' => 3, 'b' => 1];

        $changes = $this->repository->calculateChanges($old, $new);

        $this->assertSame(0, $changes['added']);
        $this->assertSame(0, $changes['removed']);
    }

    /**
     * calculateChanges 라인 단위 측정 - 객체 통째 추가 (구조 라인 포함)
     *
     * 중첩 객체가 추가되면 그 객체의 모든 라인(여는/닫는 중괄호 포함)이 added 로 집계된다.
     * 키 경로 단위가 아니라 라인 단위이므로 구조 문자도 센다(diff 뷰와 동일).
     */
    public function test_calculate_changes_counts_nested_object_addition_lines(): void
    {
        $old = ['name' => 'test'];
        // metadata 객체 추가: { "metadata": { "author": "John", "tags": [...] } }
        $new = ['name' => 'test', 'metadata' => ['author' => 'John', 'role' => 'admin']];

        $changes = $this->repository->calculateChanges($old, $new);

        // 추가 라인: "metadata": {  /  "author": ...,  /  "role": ...  /  }  = 4줄
        $this->assertSame(4, $changes['added']);
        $this->assertSame(0, $changes['removed']);
    }

    /**
     * calculateChanges 라인 단위 측정 - 대용량 JSON 성능
     */
    public function test_calculate_changes_performance_with_large_json(): void
    {
        $old = [];
        $new = [];
        for ($i = 0; $i < 1000; $i++) {
            $old["component_{$i}"] = [
                'type' => 'section',
                'id' => "comp_{$i}",
                'title' => "Component {$i}",
            ];
            $new["component_{$i}"] = $old["component_{$i}"];
            if ($i % 10 === 0) {
                $new["component_{$i}"]['title'] = "Modified Component {$i}";
            }
        }

        $startTime = microtime(true);
        $changes = $this->repository->calculateChanges($old, $new);
        $executionTime = microtime(true) - $startTime;

        $this->assertLessThan(5.0, $executionTime, 'Large JSON line diff should complete within 5 seconds');
        // 100개 컴포넌트의 title 1줄씩 변경 = removed 100 + added 100
        $this->assertGreaterThanOrEqual(100, $changes['added']);
        $this->assertGreaterThanOrEqual(100, $changes['removed']);
    }

    /**
     * calculateChanges 라인 단위 측정 - 스칼라→배열 타입 변경
     *
     * 스칼라 1줄 삭제 + 배열 여러 줄 추가로 나타난다.
     */
    public function test_calculate_changes_scalar_to_array(): void
    {
        $old = ['name' => 'test', 'tags' => 'single-tag'];
        $new = ['name' => 'test', 'tags' => ['tag1', 'tag2', 'tag3']];

        $changes = $this->repository->calculateChanges($old, $new);

        // 옛 "tags": "single-tag" 1줄 삭제, 새 "tags": [ ... ] 여러 줄 추가
        $this->assertGreaterThan(0, $changes['removed']);
        $this->assertGreaterThan($changes['removed'], $changes['added']);
    }

    /**
     * calculateChanges 라인 단위 측정 - 순차 배열(list) 끝 객체 삭제
     *
     * components 같은 list 에서 객체 1개(Span)가 통째로 삭제되면 그 객체의 모든 라인이
     * removed 로 집계된다. diff 뷰 스크린샷의 "-5 삭제"(여는/닫는 중괄호 + 필드 3줄)와
     * 정확히 일치한다. 종전 키 경로 단위는 인덱스 1개만 세어(`children.1`) 불일치했다.
     *
     * @effects changes_summary_uses_line_lcs_unit_matching_diff_view
     */
    public function test_calculate_changes_list_element_removed_matches_diff_lines(): void
    {
        $old = ['children' => [
            ['name' => 'Span', 'text' => 't', 'type' => 'basic'],
            ['name' => 'Span', 'text' => 't', 'type' => 'basic'],
        ]];
        $new = ['children' => [
            ['name' => 'Span', 'text' => 't', 'type' => 'basic'],
        ]];

        $changes = $this->repository->calculateChanges($old, $new);

        // 삭제 객체 라인: {  "name": "Span",  "text": "t",  "type": "basic"  } = 5줄
        $this->assertSame(5, $changes['removed']);
        $this->assertSame(0, $changes['added']);
    }

    /**
     * calculateChanges 라인 단위 측정 - 순차 배열(list) 앞쪽 객체 삽입
     *
     * 앞에 객체 1개를 삽입해도 뒤 요소는 인덱스만 밀릴 뿐 라인 내용이 동일하므로 삽입된
     * 객체 라인만 added 로 집계된다(밀린 요소 오판 없음). 종전 키 경로 단위는 인덱스
     * 밀림을 modified 로 오판했다.
     */
    public function test_calculate_changes_list_element_inserted_matches_diff_lines(): void
    {
        $old = ['children' => [
            ['name' => 'A', 'text' => 'aaa'],
            ['name' => 'B', 'text' => 'bbb'],
        ]];
        $new = ['children' => [
            ['name' => 'NEW', 'text' => 'new'],
            ['name' => 'A', 'text' => 'aaa'],
            ['name' => 'B', 'text' => 'bbb'],
        ]];

        $changes = $this->repository->calculateChanges($old, $new);

        // 삽입 객체 라인: {  "name": "NEW",  "text": "new"  } = 4줄
        $this->assertSame(4, $changes['added']);
        $this->assertEmpty($changes['removed'], '인덱스가 밀린 동일 요소를 변경으로 오판하면 안 된다');
    }

    /**
     * calculateChanges 라인 단위 측정 - 순차 배열(list) 중간 요소 필드 변경
     *
     * list 중간 요소의 필드 1개만 바뀌면 그 라인 1줄만 삭제+추가된다(앞뒤 동일 요소 영향
     * 없음). diff 뷰와 동일하게 -1/+1 로 표현된다.
     */
    public function test_calculate_changes_list_element_field_change_matches_diff_lines(): void
    {
        $old = ['children' => [
            ['name' => 'A', 'text' => 'aaa'],
            ['name' => 'B', 'text' => 'bbb'],
            ['name' => 'C', 'text' => 'ccc'],
        ]];
        $new = ['children' => [
            ['name' => 'A', 'text' => 'aaa'],
            ['name' => 'B', 'text' => 'CHANGED'],
            ['name' => 'C', 'text' => 'ccc'],
        ]];

        $changes = $this->repository->calculateChanges($old, $new);

        $this->assertSame(1, $changes['added'], '필드 1개 변경 = 새 라인 1줄');
        $this->assertSame(1, $changes['removed'], '필드 1개 변경 = 옛 라인 1줄');
    }

    /**
     * getNextVersion 테스트 - 10 이상 버전에서 올바른 정렬 확인
     *
     * varchar 타입에서는 max('9') > max('10')으로 잘못 계산되던 버그 수정 확인
     */
    public function test_get_next_version_handles_double_digit_versions_correctly(): void
    {
        // Arrange - 1부터 12까지 버전 생성
        for ($i = 1; $i <= 12; $i++) {
            TemplateLayoutVersion::factory()->create([
                'layout_id' => $this->layout->id,
                'version' => $i,
            ]);
        }

        // Act
        $nextVersion = $this->repository->getNextVersion($this->layout->id);

        // Assert - max(12) + 1 = 13이어야 함 (varchar였다면 max('9') + 1 = 10 반환)
        $this->assertEquals(13, $nextVersion);
    }

    /**
     * getVersions 테스트 - 10 이상 버전에서 올바른 정렬 확인
     *
     * varchar 타입에서는 '9' > '10' > '11' 순으로 잘못 정렬되던 버그 수정 확인
     */
    public function test_get_versions_sorts_double_digit_versions_correctly(): void
    {
        // Arrange - 8, 9, 10, 11, 12 버전 생성 (순서 무작위)
        $versions = [10, 8, 12, 9, 11];
        foreach ($versions as $v) {
            TemplateLayoutVersion::factory()->create([
                'layout_id' => $this->layout->id,
                'version' => $v,
            ]);
        }

        // Act
        $result = $this->repository->getVersions($this->layout->id);

        // Assert - 12, 11, 10, 9, 8 순서여야 함 (varchar였다면 9, 8, 12, 11, 10 순서)
        $this->assertEquals(12, $result[0]->version);
        $this->assertEquals(11, $result[1]->version);
        $this->assertEquals(10, $result[2]->version);
        $this->assertEquals(9, $result[3]->version);
        $this->assertEquals(8, $result[4]->version);
    }

    /**
     * saveVersion 테스트 - 10번째 버전 이후에도 정상 저장
     */
    public function test_save_version_works_after_version_10(): void
    {
        // Arrange - 1부터 10까지 버전 생성
        for ($i = 1; $i <= 10; $i++) {
            TemplateLayoutVersion::factory()->create([
                'layout_id' => $this->layout->id,
                'version' => $i,
            ]);
        }

        // Act - 11번째 버전 저장
        $content = ['test' => 'version 11'];
        $newVersion = $this->repository->saveVersion($this->layout->id, $content);

        // Assert
        $this->assertEquals(11, $newVersion->version);
        $this->assertEquals($content, $newVersion->content);

        // 12번째 버전도 저장 가능해야 함
        $content12 = ['test' => 'version 12'];
        $version12 = $this->repository->saveVersion($this->layout->id, $content12);
        $this->assertEquals(12, $version12->version);
    }

    /**
     * getCurrentVersionsByTemplateId — 레이아웃별 최신 버전 맵
     */
    public function test_get_current_versions_by_template_id_returns_latest_version_map(): void
    {
        // Arrange — 본 템플릿에 레이아웃 2개 (이력 보유) + 이력 없는 레이아웃 1개 + 타 템플릿 레이아웃
        $layoutB = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'auth/login',
        ]);
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'never-saved',
        ]);
        $otherTemplate = Template::factory()->create();
        $otherLayout = TemplateLayout::factory()->create([
            'template_id' => $otherTemplate->id,
            'name' => 'other-home',
        ]);

        foreach ([1, 2, 3] as $v) {
            TemplateLayoutVersion::factory()->create(['layout_id' => $this->layout->id, 'version' => $v]);
        }
        TemplateLayoutVersion::factory()->create(['layout_id' => $layoutB->id, 'version' => 1]);
        TemplateLayoutVersion::factory()->create(['layout_id' => $otherLayout->id, 'version' => 9]);

        // Act
        $map = $this->repository->getCurrentVersionsByTemplateId($this->template->id);

        // Assert — 본 템플릿의 이력 보유 레이아웃만, 각각 최신 버전 번호
        ksort($map);
        $expected = [$this->layout->name => 3, 'auth/login' => 1];
        ksort($expected);
        $this->assertSame($expected, $map);
        $this->assertArrayNotHasKey('never-saved', $map);
        $this->assertArrayNotHasKey('other-home', $map);
    }

    /**
     * getCurrentVersionsByTemplateId — 이력이 전무하면 빈 맵
     */
    public function test_get_current_versions_by_template_id_returns_empty_when_no_history(): void
    {
        $map = $this->repository->getCurrentVersionsByTemplateId($this->template->id);

        $this->assertSame([], $map);
    }
}
