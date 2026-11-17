<?php

namespace Tests\Unit\Config;

use App\Support\StoragePaths;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * storage 경로 분류(`config/g7-storage.php`)와 코어 업데이트 보호 경로가 서로 모순되지 않는지, 분류 목록과
 * `storage:paths` 출력이 맞는지 잠근다 (#888).
 */
class StoragePathsConfigTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 네 분류가 모두 있고, 항목은 storage 기준 상대 경로이며, 한 경로가 두 분류에 들지 않습니다.
     *
     * @scenario case=lists
     *
     * @effects lists_relative_and_disjoint
     *
     * @return void 반환값 없음
     */
    public function test_lists_are_relative_and_disjoint(): void
    {
        $lists = StoragePaths::lists();
        $this->assertSame(StoragePaths::CATEGORIES, array_keys($lists));

        $owner = [];
        foreach ($lists as $category => $entries) {
            $this->assertNotEmpty($entries, "{$category} 목록이 비었습니다.");
            foreach ($entries as $entry) {
                $this->assertNotSame('', $entry);
                $this->assertDoesNotMatchRegularExpression('#(^|/)\.\.?(/|$)#', $entry, "{$entry}: 상대 경로 조각(. ..)을 쓰지 않습니다.");
                $this->assertDoesNotMatchRegularExpression('#^([A-Za-z]:|/)#', $entry, "{$entry}: storage 기준 상대 경로여야 합니다.");
                $this->assertArrayNotHasKey($entry, $owner, $entry.': '.($owner[$entry] ?? '').'·'.$category.' 두 분류에 들어 있습니다.');
                $owner[$entry] = $category;
            }
        }

        // 다른 분류의 항목 아래에 놓인 항목은 어느 쪽 규칙을 따를지 모호하다 — 같은 분류 안의 좁은 항목만 허용한다
        foreach ($owner as $entry => $category) {
            foreach ($owner as $other => $otherCategory) {
                if ($entry !== $other && $category !== $otherCategory) {
                    $this->assertFalse(StoragePaths::matches($entry, $other), "{$entry}({$category}) 가 {$other}({$otherCategory}) 아래에 있습니다.");
                }
            }
        }

        $this->assertSame('shared_data', StoragePaths::classify('app/settings/general.json'));
        $this->assertSame('server_local', StoragePaths::classify('app/private/cache/sitemap/sitemap.xml'));
        $this->assertSame('server_local', StoragePaths::classify('app/public-storage-broken-20261115'));
        $this->assertSame('shared_data', StoragePaths::classify('app/public/avatars/a.png'));
        $this->assertSame('update_state', StoragePaths::classify('app/core-update.lock'));
        $this->assertSame('temporary', StoragePaths::classify('app\\temp\\modules'));
        $this->assertNull(StoragePaths::classify('app/unclassified-dir'));
    }

    /**
     * 코어 업데이트는 storage 전체를 보호하므로 분류된 모든 경로를 덮지도 지우지도 않고, 업데이트가 함께 백업하는
     * storage 경로는 운영 데이터로 분류돼 있습니다.
     *
     * @scenario case=protected_paths
     *
     * @effects classified_paths_protected_by_core_update
     *
     * @return void 반환값 없음
     */
    public function test_classified_paths_are_protected_by_core_update(): void
    {
        $protected = array_map(static fn (string $p): string => StoragePaths::normalize($p), (array) config('app.update.protected_paths'));
        $targets = array_map(static fn (string $p): string => StoragePaths::normalize($p), (array) config('app.update.targets'));

        $this->assertContains('storage', $protected, '코어 업데이트 보호 경로에 storage 전체가 있어야 합니다.');
        foreach ($targets as $target) {
            $this->assertFalse($target === 'storage' || str_starts_with($target, 'storage/'), "코어 업데이트 대상에 storage 경로({$target})가 있으면 분류와 보호가 어긋납니다.");
        }

        foreach (StoragePaths::lists() as $category => $entries) {
            foreach ($entries as $entry) {
                $covered = array_filter($protected, static fn (string $p): bool => StoragePaths::matches('storage/'.$entry, $p));
                $this->assertNotEmpty($covered, "storage/{$entry}({$category}) 가 코어 업데이트 보호 경로에 들지 않습니다.");
            }
        }

        foreach ((array) config('app.update.backup_extra') as $extra) {
            $extra = StoragePaths::normalize((string) $extra);
            if (str_starts_with($extra, 'storage/')) {
                $this->assertSame('shared_data', StoragePaths::classify(substr($extra, strlen('storage/'))), "업데이트가 백업하는 {$extra} 는 운영 데이터여야 합니다.");
            }
        }

        $pending = StoragePaths::normalize(str_replace(StoragePaths::normalize(storage_path()), '', StoragePaths::normalize((string) config('app.update.pending_path'))));
        $this->assertSame('update_state', StoragePaths::classify($pending), '코어 업데이트 대기 경로는 업데이트 상태로 분류돼야 합니다.');
    }

    /**
     * `storage:paths --json` 은 storage 위치와 네 분류의 경로·존재 여부를 냅니다.
     *
     * @scenario case=command
     *
     * @effects command_json_lists_categories
     *
     * @return void 반환값 없음
     */
    public function test_command_outputs_categories(): void
    {
        $this->assertSame(0, Artisan::call('storage:paths', ['--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertSame(storage_path(), $payload['storage_root']);
        $this->assertSame(StoragePaths::CATEGORIES, array_keys($payload['categories']));
        $this->assertSame(StoragePaths::lists()['shared_data'], array_column($payload['categories']['shared_data'], 'path'));
        $this->assertIsBool($payload['categories']['shared_data'][0]['exists']);

        $this->assertSame(0, Artisan::call('storage:paths'));
        $text = Artisan::output();
        $this->assertStringContainsString('app/settings', $text);
        $this->assertStringContainsString(__('common.console.storage_paths.categories.update_state'), $text);
    }
}
