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
     * calculateChanges 메서드 테스트 - 추가된 키
     */
    public function test_calculate_changes_detects_added_keys(): void
    {
        // Arrange
        $old = [
            'name' => 'test',
            'version' => '1.0.0',
        ];

        $new = [
            'name' => 'test',
            'version' => '1.0.0',
            'description' => 'new field',
            'author' => 'John Doe',
        ];

        // Act
        $changes = $this->repository->calculateChanges($old, $new);

        // Assert
        $this->assertCount(2, $changes['added']);
        $this->assertContains('description', $changes['added']);
        $this->assertContains('author', $changes['added']);
        $this->assertEmpty($changes['removed']);
        $this->assertEmpty($changes['modified']);
    }

    /**
     * calculateChanges 메서드 테스트 - 삭제된 키
     */
    public function test_calculate_changes_detects_removed_keys(): void
    {
        // Arrange
        $old = [
            'name' => 'test',
            'version' => '1.0.0',
            'deprecated' => 'old field',
            'legacy' => 'another old field',
        ];

        $new = [
            'name' => 'test',
            'version' => '1.0.0',
        ];

        // Act
        $changes = $this->repository->calculateChanges($old, $new);

        // Assert
        $this->assertCount(2, $changes['removed']);
        $this->assertContains('deprecated', $changes['removed']);
        $this->assertContains('legacy', $changes['removed']);
        $this->assertEmpty($changes['added']);
        $this->assertEmpty($changes['modified']);
    }

    /**
     * calculateChanges 메서드 테스트 - 수정된 값
     */
    public function test_calculate_changes_detects_modified_values(): void
    {
        // Arrange
        $old = [
            'name' => 'test',
            'version' => '1.0.0',
            'status' => 'draft',
        ];

        $new = [
            'name' => 'test',
            'version' => '2.0.0',
            'status' => 'published',
        ];

        // Act
        $changes = $this->repository->calculateChanges($old, $new);

        // Assert
        $this->assertCount(2, $changes['modified']);
        $this->assertContains('version', $changes['modified']);
        $this->assertContains('status', $changes['modified']);
        $this->assertEmpty($changes['added']);
        $this->assertEmpty($changes['removed']);
    }

    /**
     * calculateChanges 메서드 테스트 - 중첩 객체 추가
     */
    public function test_calculate_changes_detects_nested_added_keys(): void
    {
        // Arrange
        $old = [
            'name' => 'test',
            'config' => [
                'theme' => 'dark',
            ],
        ];

        $new = [
            'name' => 'test',
            'config' => [
                'theme' => 'dark',
                'locale' => 'ko',
            ],
            'metadata' => [
                'author' => 'John',
                'tags' => ['test', 'demo'],
            ],
        ];

        // Act
        $changes = $this->repository->calculateChanges($old, $new);

        // Assert
        $this->assertContains('config.locale', $changes['added']);
        $this->assertContains('metadata', $changes['added']);
        $this->assertContains('metadata.author', $changes['added']);
        $this->assertContains('metadata.tags', $changes['added']);
    }

    /**
     * calculateChanges 메서드 테스트 - 중첩 객체 삭제
     */
    public function test_calculate_changes_detects_nested_removed_keys(): void
    {
        // Arrange
        $old = [
            'name' => 'test',
            'config' => [
                'theme' => 'dark',
                'locale' => 'ko',
                'deprecated' => 'old',
            ],
            'legacy' => [
                'old_field' => 'value',
            ],
        ];

        $new = [
            'name' => 'test',
            'config' => [
                'theme' => 'dark',
            ],
        ];

        // Act
        $changes = $this->repository->calculateChanges($old, $new);

        // Assert
        $this->assertContains('config.locale', $changes['removed']);
        $this->assertContains('config.deprecated', $changes['removed']);
        $this->assertContains('legacy', $changes['removed']);
    }

    /**
     * calculateChanges 메서드 테스트 - 중첩 객체 수정
     */
    public function test_calculate_changes_detects_nested_modified_values(): void
    {
        // Arrange
        $old = [
            'name' => 'test',
            'config' => [
                'theme' => 'dark',
                'locale' => 'ko',
            ],
        ];

        $new = [
            'name' => 'test',
            'config' => [
                'theme' => 'light',
                'locale' => 'en',
            ],
        ];

        // Act
        $changes = $this->repository->calculateChanges($old, $new);

        // Assert
        $this->assertContains('config.theme', $changes['modified']);
        $this->assertContains('config.locale', $changes['modified']);
        $this->assertEmpty($changes['added']);
        $this->assertEmpty($changes['removed']);
    }

    /**
     * calculateChanges 메서드 테스트 - 복합 변경 (추가+삭제+수정)
     */
    public function test_calculate_changes_detects_complex_changes(): void
    {
        // Arrange
        $old = [
            'name' => 'test',
            'version' => '1.0.0',
            'deprecated' => 'old',
            'components' => [
                'header' => ['title' => 'Old Title'],
            ],
        ];

        $new = [
            'name' => 'test',
            'version' => '2.0.0',
            'description' => 'new field',
            'components' => [
                'header' => ['title' => 'New Title'],
                'footer' => ['text' => 'Footer'],
            ],
        ];

        // Act
        $changes = $this->repository->calculateChanges($old, $new);

        // Assert
        // Added
        $this->assertContains('description', $changes['added']);
        $this->assertContains('components.footer', $changes['added']);
        $this->assertContains('components.footer.text', $changes['added']);

        // Removed
        $this->assertContains('deprecated', $changes['removed']);

        // Modified
        $this->assertContains('version', $changes['modified']);
        $this->assertContains('components.header.title', $changes['modified']);
    }

    /**
     * calculateChanges 메서드 테스트 - 변경사항 없음
     */
    public function test_calculate_changes_detects_no_changes(): void
    {
        // Arrange
        $old = [
            'name' => 'test',
            'version' => '1.0.0',
            'config' => [
                'theme' => 'dark',
            ],
        ];

        $new = [
            'name' => 'test',
            'version' => '1.0.0',
            'config' => [
                'theme' => 'dark',
            ],
        ];

        // Act
        $changes = $this->repository->calculateChanges($old, $new);

        // Assert
        $this->assertEmpty($changes['added']);
        $this->assertEmpty($changes['removed']);
        $this->assertEmpty($changes['modified']);
    }

    /**
     * calculateChanges 메서드 테스트 - 빈 배열
     */
    public function test_calculate_changes_handles_empty_arrays(): void
    {
        // Arrange
        $old = [];
        $new = ['name' => 'test'];

        // Act
        $changes = $this->repository->calculateChanges($old, $new);

        // Assert
        $this->assertCount(1, $changes['added']);
        $this->assertContains('name', $changes['added']);
        $this->assertEmpty($changes['removed']);
        $this->assertEmpty($changes['modified']);
    }

    /**
     * calculateChanges 메서드 테스트 - 깊은 중첩 구조
     */
    public function test_calculate_changes_handles_deeply_nested_structure(): void
    {
        // Arrange
        $old = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => 'old value',
                    ],
                ],
            ],
        ];

        $new = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => 'new value',
                        'level4_new' => 'added',
                    ],
                ],
            ],
        ];

        // Act
        $changes = $this->repository->calculateChanges($old, $new);

        // Assert
        $this->assertContains('level1.level2.level3.level4', $changes['modified']);
        $this->assertContains('level1.level2.level3.level4_new', $changes['added']);
        $this->assertEmpty($changes['removed']);
    }

    /**
     * calculateChanges 메서드 테스트 - 대용량 JSON 성능 (1MB 이상)
     */
    public function test_calculate_changes_performance_with_large_json(): void
    {
        // Arrange - 대용량 JSON 생성 (약 1MB)
        $old = [];
        $new = [];

        // 1000개의 컴포넌트 생성
        for ($i = 0; $i < 1000; $i++) {
            $old["component_{$i}"] = [
                'type' => 'section',
                'id' => "comp_{$i}",
                'props' => [
                    'title' => "Component {$i}",
                    'description' => str_repeat("Long description for component {$i}. ", 10),
                    'metadata' => [
                        'author' => 'Test Author',
                        'created' => '2025-01-01',
                        'tags' => ['tag1', 'tag2', 'tag3'],
                    ],
                ],
                'children' => [
                    [
                        'type' => 'text',
                        'content' => str_repeat("Sample content {$i}. ", 20),
                    ],
                    [
                        'type' => 'image',
                        'src' => "/images/image_{$i}.jpg",
                        'alt' => "Image {$i}",
                    ],
                ],
            ];

            // new는 일부 수정된 버전
            $new["component_{$i}"] = $old["component_{$i}"];
            if ($i % 10 === 0) {
                // 10번째마다 수정
                $new["component_{$i}"]['props']['title'] = "Modified Component {$i}";
            }
            if ($i % 15 === 0) {
                // 15번째마다 새 필드 추가
                $new["component_{$i}"]['props']['updated'] = '2025-01-10';
            }
        }

        // 일부 컴포넌트 삭제 (old에만 존재)
        for ($i = 1000; $i < 1010; $i++) {
            $old["component_{$i}"] = ['type' => 'deleted', 'id' => "comp_{$i}"];
        }

        // 일부 컴포넌트 추가 (new에만 존재)
        for ($i = 1010; $i < 1020; $i++) {
            $new["component_{$i}"] = ['type' => 'added', 'id' => "comp_{$i}"];
        }

        // JSON 크기 확인 (약 1MB 이상인지)
        $jsonSize = strlen(json_encode($old)) + strlen(json_encode($new));
        $this->assertGreaterThan(1024 * 1024, $jsonSize, 'JSON size should be greater than 1MB');

        // Act - 실행 시간 측정
        $startTime = microtime(true);
        $changes = $this->repository->calculateChanges($old, $new);
        $executionTime = microtime(true) - $startTime;

        // Assert - 성능 검증 (2초 이내 완료)
        $this->assertLessThan(2.0, $executionTime, 'Large JSON diff should complete within 2 seconds');

        // 변경사항 정확도 검증
        $this->assertNotEmpty($changes['added']);
        $this->assertNotEmpty($changes['removed']);
        $this->assertNotEmpty($changes['modified']);

        // 예상되는 변경사항 수 검증
        // 100개 수정 (component_0, 10, 20, ..., 990)
        // 67개 추가 (component_0.props.updated, 15, 30, ..., 975 + 10개 새 컴포넌트)
        // 10개 삭제 (component_1000 ~ 1009)
        $this->assertGreaterThanOrEqual(100, count($changes['modified']));
        $this->assertGreaterThanOrEqual(10, count($changes['removed']));
        $this->assertGreaterThanOrEqual(10, count($changes['added']));
    }

    /**
     * calculateChanges 메서드 테스트 - 배열 vs 스칼라 값 타입 변경
     */
    public function test_calculate_changes_detects_type_change_from_scalar_to_array(): void
    {
        // Arrange
        $old = [
            'name' => 'test',
            'tags' => 'single-tag',
        ];

        $new = [
            'name' => 'test',
            'tags' => ['tag1', 'tag2', 'tag3'],
        ];

        // Act
        $changes = $this->repository->calculateChanges($old, $new);

        // Assert
        $this->assertContains('tags', $changes['modified']);
        $this->assertEmpty($changes['added']);
        $this->assertEmpty($changes['removed']);
    }

    /**
     * calculateChanges 메서드 테스트 - 배열 vs 스칼라 값 타입 변경 (반대)
     */
    public function test_calculate_changes_detects_type_change_from_array_to_scalar(): void
    {
        // Arrange
        $old = [
            'name' => 'test',
            'tags' => ['tag1', 'tag2', 'tag3'],
        ];

        $new = [
            'name' => 'test',
            'tags' => 'single-tag',
        ];

        // Act
        $changes = $this->repository->calculateChanges($old, $new);

        // Assert
        $this->assertContains('tags', $changes['modified']);
        $this->assertEmpty($changes['added']);
        $this->assertEmpty($changes['removed']);
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
}
