<?php

namespace Tests\Unit\Repositories;

use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayoutExtensionVersion;
use App\Repositories\LayoutExtensionVersionRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 레이아웃 확장 버전 리포지토리 테스트
 */
class LayoutExtensionVersionRepositoryTest extends TestCase
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

    private LayoutExtensionVersionRepository $repository;

    private Template $template;

    private LayoutExtension $extension;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new LayoutExtensionVersionRepository(new TemplateLayoutExtensionVersion);
        $this->template = Template::factory()->create();
        $this->extension = LayoutExtension::factory()->create(['template_id' => $this->template->id]);
    }

    /**
     * saveVersion - 첫 번째 버전 생성
     */
    public function test_save_version_creates_first_version(): void
    {
        $content = ['extension_point' => 'header', 'components' => []];

        $version = $this->repository->saveVersion($this->extension->id, $content);

        $this->assertInstanceOf(TemplateLayoutExtensionVersion::class, $version);
        $this->assertEquals(1, $version->version);
        $this->assertEquals($this->extension->id, $version->extension_id);
        $this->assertEquals($content, $version->content);
    }

    /**
     * saveVersion - 버전 자동 증가
     */
    public function test_save_version_auto_increments(): void
    {
        TemplateLayoutExtensionVersion::factory()->create(['extension_id' => $this->extension->id, 'version' => 1]);
        TemplateLayoutExtensionVersion::factory()->create(['extension_id' => $this->extension->id, 'version' => 2]);

        $newVersion = $this->repository->saveVersion($this->extension->id, ['test' => 'data']);

        $this->assertEquals(3, $newVersion->version);
    }

    /**
     * getVersions - 최신순 정렬
     */
    public function test_get_versions_returns_latest_first(): void
    {
        foreach ([1, 3, 2] as $v) {
            TemplateLayoutExtensionVersion::factory()->create(['extension_id' => $this->extension->id, 'version' => $v]);
        }

        $versions = $this->repository->getVersions($this->extension->id);

        $this->assertCount(3, $versions);
        $this->assertEquals(3, $versions->first()->version);
        $this->assertEquals(1, $versions->last()->version);
    }

    /**
     * getNextVersion - 버전 없을 때 1 반환
     */
    public function test_get_next_version_returns_one_when_empty(): void
    {
        $this->assertEquals(1, $this->repository->getNextVersion($this->extension->id));
    }

    /**
     * getNextVersion - max + 1
     */
    public function test_get_next_version_returns_max_plus_one(): void
    {
        foreach ([1, 5, 3] as $v) {
            TemplateLayoutExtensionVersion::factory()->create(['extension_id' => $this->extension->id, 'version' => $v]);
        }

        $this->assertEquals(6, $this->repository->getNextVersion($this->extension->id));
    }

    /**
     * 버전은 확장별로 격리됨
     */
    public function test_versions_are_isolated_by_extension(): void
    {
        $otherExtension = LayoutExtension::factory()->create(['template_id' => $this->template->id]);
        TemplateLayoutExtensionVersion::factory()->create(['extension_id' => $otherExtension->id, 'version' => 10]);

        $this->assertEquals(1, $this->repository->getNextVersion($this->extension->id));
        $this->assertCount(0, $this->repository->getVersions($this->extension->id));
    }

    /**
     * calculateChanges - trait 동작 (라인 단위 카운트)
     *
     * changes_summary 는 버전 비교 diff 뷰와 동일한 라인 단위로 센다. 키 정렬 직렬화
     * 후 라인 LCS 로 추가/삭제 라인 수를 집계한다(added/removed 는 정수 카운트, modified
     * 키는 없음 — 값 변경은 삭제+추가 라인으로 표현).
     */
    public function test_calculate_changes_detects_diff(): void
    {
        // name 값 변경(삭제+추가), deprecated 삭제(1줄), added 키 추가(1줄)
        $old = ['name' => 'test', 'deprecated' => 'old'];
        $new = ['name' => 'changed', 'added' => 'new'];

        $changes = $this->repository->calculateChanges($old, $new);

        // added 라인: "added": "new",  +  "name": "changed",  = 2줄
        $this->assertSame(2, $changes['added']);
        // removed 라인: "deprecated": "old",  +  "name": "test",  = 2줄
        $this->assertSame(2, $changes['removed']);
        $this->assertArrayNotHasKey('modified', $changes, 'modified 키는 두지 않는다');
    }

    /**
     * restoreVersion - 트랜잭션 내 복원 + 새 버전 생성
     */
    public function test_restore_version_restores_content_and_creates_new_version(): void
    {
        $oldContent = ['extension_point' => 'header', 'components' => [['type' => 'basic', 'name' => 'Span']]];
        $this->extension->update(['content' => $oldContent]);

        // 복원 대상 버전 (버전 1) — 이전 content 스냅샷
        $versionToRestore = TemplateLayoutExtensionVersion::factory()->create([
            'extension_id' => $this->extension->id,
            'version' => 1,
            'content' => $oldContent,
        ]);

        // 현재 content 를 다른 값으로 변경
        $this->extension->update(['content' => ['extension_point' => 'footer']]);

        $newVersion = $this->repository->restoreVersion($this->extension->id, $versionToRestore->id);

        // 확장 content 가 복원됨
        $this->extension->refresh();
        $this->assertEquals($oldContent, $this->extension->content);

        // 새 버전 생성됨 (버전 2)
        $this->assertEquals(2, $newVersion->version);
    }

    /**
     * restoreVersion - 존재하지 않는 버전은 예외
     */
    public function test_restore_version_throws_when_version_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->repository->restoreVersion($this->extension->id, 99999);
    }

    /**
     * getCurrentVersionsByExtensionIds — 확장별 최신 버전 맵
     */
    public function test_get_current_versions_by_extension_ids_returns_latest_version_map(): void
    {
        $other = LayoutExtension::factory()->create(['template_id' => $this->template->id]);
        $noHistory = LayoutExtension::factory()->create(['template_id' => $this->template->id]);

        foreach ([1, 2, 3] as $v) {
            TemplateLayoutExtensionVersion::factory()->create(['extension_id' => $this->extension->id, 'version' => $v]);
        }
        TemplateLayoutExtensionVersion::factory()->create(['extension_id' => $other->id, 'version' => 1]);

        $map = $this->repository->getCurrentVersionsByExtensionIds([
            $this->extension->id,
            $other->id,
            $noHistory->id,
        ]);

        ksort($map);
        $expected = [$this->extension->id => 3, $other->id => 1];
        ksort($expected);
        $this->assertSame($expected, $map);
        $this->assertArrayNotHasKey($noHistory->id, $map);
    }

    /**
     * getCurrentVersionsByExtensionIds — 빈 입력/이력 전무 시 빈 맵
     */
    public function test_get_current_versions_by_extension_ids_returns_empty_for_empty_input(): void
    {
        $this->assertSame([], $this->repository->getCurrentVersionsByExtensionIds([]));
        $this->assertSame([], $this->repository->getCurrentVersionsByExtensionIds([$this->extension->id]));
    }
}
