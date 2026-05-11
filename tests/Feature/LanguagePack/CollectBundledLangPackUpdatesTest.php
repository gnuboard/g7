<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * LanguagePackService::collectBundledLangPackUpdates() 검증.
 *
 * core:update 의 BundledExtensionUpdatePrompt 가 사용 — _bundled manifest 의
 * 신버전을 DB 설치된 언어팩 버전과 직접 비교하여 업데이트 후보 반환.
 */
class CollectBundledLangPackUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private string $bundledRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bundledRoot = base_path('lang-packs/_bundled');
    }

    private function setupBundledManifest(string $identifier, string $version): void
    {
        $dir = $this->bundledRoot.'/'.$identifier;
        File::ensureDirectoryExists($dir);
        File::put($dir.'/language-pack.json', json_encode([
            'identifier' => $identifier,
            'version' => $version,
            'scope' => 'core',
            'locale' => 'ja',
        ]));
    }

    private function teardownBundled(string $identifier): void
    {
        File::deleteDirectory($this->bundledRoot.'/'.$identifier);
    }

    public function test_returns_packs_where_bundled_version_is_newer(): void
    {
        $id = 'test-bundled-update-ja';
        $this->setupBundledManifest($id, '1.2.0');

        try {
            LanguagePack::query()->create([
                'identifier' => $id,
                'vendor' => 'test',
                'scope' => LanguagePackScope::Core->value,
                'target_identifier' => null,
                'locale' => 'ja',
                'locale_name' => 'Japanese',
                'locale_native_name' => '日本語',
                'text_direction' => 'ltr',
                'version' => '1.0.0',
                'status' => LanguagePackStatus::Active->value,
                'is_protected' => false,
                'manifest' => [],
                'source_type' => 'zip',
            ]);

            $service = $this->app->make(LanguagePackService::class);
            $updates = $service->collectBundledLangPackUpdates();
            $found = collect($updates)->firstWhere('identifier', $id);

            $this->assertNotNull($found, '신버전 _bundled 가 있는 팩이 감지되어야 함');
            $this->assertSame('1.0.0', $found['current_version']);
            $this->assertSame('1.2.0', $found['latest_version']);
        } finally {
            $this->teardownBundled($id);
        }
    }

    public function test_excludes_packs_when_bundled_version_equal_or_older(): void
    {
        $id = 'test-bundled-uptodate-ja';
        $this->setupBundledManifest($id, '1.0.0');

        try {
            LanguagePack::query()->create([
                'identifier' => $id,
                'vendor' => 'test',
                'scope' => LanguagePackScope::Core->value,
                'target_identifier' => null,
                'locale' => 'ja',
                'locale_name' => 'Japanese',
                'locale_native_name' => '日本語',
                'text_direction' => 'ltr',
                'version' => '1.0.0',
                'status' => LanguagePackStatus::Active->value,
                'is_protected' => false,
                'manifest' => [],
                'source_type' => 'zip',
            ]);

            $service = $this->app->make(LanguagePackService::class);
            $updates = $service->collectBundledLangPackUpdates();
            $found = collect($updates)->firstWhere('identifier', $id);

            $this->assertNull($found, '동일 버전 팩은 업데이트 대상 아님');
        } finally {
            $this->teardownBundled($id);
        }
    }

    public function test_excludes_packs_without_bundled_manifest(): void
    {
        $id = 'test-no-bundled-ja';
        // _bundled manifest 없음

        LanguagePack::query()->create([
            'identifier' => $id,
            'vendor' => 'test',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'github',
            'source_url' => 'https://github.com/test/'.$id,
        ]);

        $service = $this->app->make(LanguagePackService::class);
        $updates = $service->collectBundledLangPackUpdates();
        $found = collect($updates)->firstWhere('identifier', $id);

        $this->assertNull($found, '_bundled manifest 없는 팩은 본 메서드 결과에서 제외');
    }
}
