<?php

namespace Tests\Unit\Models;

use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateLayoutVersionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 레이아웃 버전 관계 테스트
     */
    public function test_template_layout_has_versions_relationship(): void
    {
        // Arrange
        $template = Template::factory()->create();
        $layout = TemplateLayout::factory()->create(['template_id' => $template->id]);

        $version1 = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
        ]);

        $version2 = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 2,
        ]);

        // Act
        $versions = $layout->versions;

        // Assert
        $this->assertCount(2, $versions);
        $this->assertEquals(2, $versions->first()->version); // 최신순 정렬
        $this->assertEquals(1, $versions->last()->version);
    }

    /**
     * 최신 버전 조회 테스트
     */
    public function test_can_get_latest_version(): void
    {
        // Arrange
        $template = Template::factory()->create();
        $layout = TemplateLayout::factory()->create(['template_id' => $template->id]);

        TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
        ]);

        $latestVersion = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 2,
        ]);

        // Act
        $result = $layout->latestVersion();

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($latestVersion->id, $result->id);
        $this->assertEquals(2, $result->version);
    }

    /**
     * 버전이 없을 때 null 반환 테스트
     */
    public function test_latest_version_returns_null_when_no_versions(): void
    {
        // Arrange
        $template = Template::factory()->create();
        $layout = TemplateLayout::factory()->create(['template_id' => $template->id]);

        // Act
        $result = $layout->latestVersion();

        // Assert
        $this->assertNull($result);
    }

    /**
     * 특정 버전 조회 테스트
     */
    public function test_can_get_specific_version(): void
    {
        // Arrange
        $template = Template::factory()->create();
        $layout = TemplateLayout::factory()->create(['template_id' => $template->id]);

        TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
        ]);

        $version2 = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 2,
        ]);

        // Act
        $result = $layout->getVersion(2);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($version2->id, $result->id);
        $this->assertEquals(2, $result->version);
    }

    /**
     * 존재하지 않는 버전 조회 시 null 반환 테스트
     */
    public function test_get_version_returns_null_when_version_not_found(): void
    {
        // Arrange
        $template = Template::factory()->create();
        $layout = TemplateLayout::factory()->create(['template_id' => $template->id]);

        TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
        ]);

        // Act
        $result = $layout->getVersion(99);

        // Assert
        $this->assertNull($result);
    }

    /**
     * Eager loading 테스트
     */
    public function test_can_eager_load_versions(): void
    {
        // Arrange
        $template = Template::factory()->create();
        $layout = TemplateLayout::factory()->create(['template_id' => $template->id]);

        TemplateLayoutVersion::factory()->count(3)->create([
            'layout_id' => $layout->id,
        ]);

        // Act
        $layoutWithVersions = TemplateLayout::with('versions')->find($layout->id);

        // Assert
        $this->assertTrue($layoutWithVersions->relationLoaded('versions'));
        $this->assertCount(3, $layoutWithVersions->versions);
    }

    /**
     * content 필드 배열 캐스팅 테스트
     */
    public function test_content_is_cast_to_array(): void
    {
        // Arrange
        $template = Template::factory()->create();
        $layout = TemplateLayout::factory()->create(['template_id' => $template->id]);

        $contentData = [
            'version' => '1.0.0',
            'components' => [
                ['type' => 'header', 'props' => ['title' => 'Test']],
            ],
        ];

        // Act
        $version = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
            'content' => $contentData,
        ]);

        // Assert
        $this->assertIsArray($version->content);
        $this->assertEquals($contentData, $version->content);
    }

    /**
     * changes_summary 필드 배열 캐스팅 테스트
     */
    public function test_changes_summary_is_cast_to_array(): void
    {
        // Arrange
        $template = Template::factory()->create();
        $layout = TemplateLayout::factory()->create(['template_id' => $template->id]);

        $changesSummary = [
            'added' => 3,
            'removed' => 2,
            'is_restored' => false,
        ];

        // Act
        $version = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
            'changes_summary' => $changesSummary,
        ]);

        // Assert
        $this->assertIsArray($version->changes_summary);
        $this->assertEquals($changesSummary, $version->changes_summary);
    }

    /**
     * belongsTo 관계 테스트
     */
    public function test_belongs_to_layout(): void
    {
        // Arrange
        $template = Template::factory()->create();
        $layout = TemplateLayout::factory()->create(['template_id' => $template->id]);

        // Act
        $version = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
        ]);

        // Assert
        $this->assertInstanceOf(TemplateLayout::class, $version->layout);
        $this->assertEquals($layout->id, $version->layout->id);
    }

    /**
     * creator 관계 테스트
     */
    public function test_belongs_to_creator(): void
    {
        // Arrange
        $template = Template::factory()->create();
        $layout = TemplateLayout::factory()->create(['template_id' => $template->id]);
        $user = User::factory()->create();

        // Act
        $version = TemplateLayoutVersion::factory()->create([
            'layout_id' => $layout->id,
            'version' => 1,
            'created_by' => $user->id,
        ]);

        // Assert
        $this->assertInstanceOf(User::class, $version->creator);
        $this->assertEquals($user->id, $version->creator->id);
    }
}
