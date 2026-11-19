<?php

use App\Extension\PluginManager;
use App\Services\PluginSettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// 브라우저 검증 전에 플러그인 설정 파일 원문과 제공자 표 행을 암호화해 보관하고, 끝나면 그대로 되돌립니다(관리자 spec 이 자격증명 저장·
// 순서·일반 OIDC 추가를 한다). 제공자 행은 열 값 전체를 되돌리고 보관에 없던 행(추가한 일반 OIDC)은 지운다.
if (PHP_SAPI !== 'cli' || getenv('G7_PLAYWRIGHT_BYPASS') !== '1') {
    throw new RuntimeException('Explicit CLI test opt-in required');
}
$root = dirname(__DIR__, 6);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('Browser fixtures require local or testing environment');
}

$pluginSettings = app(PluginSettingsService::class);
$storage = app(PluginManager::class)->getPlugin('sirsoft-social_login')->getStorage();
$backupPath = storage_path('framework/testing/social-login-browser-backup.enc');

if (($argv[1] ?? '') === 'restore') {
    if (! is_file($backupPath)) {
        echo "No pending browser fixture\n";
        exit;
    }
    $original = json_decode(Crypt::decryptString(file_get_contents($backupPath)), true, flags: JSON_THROW_ON_ERROR);
    if ($original['plugin'] === null) {
        $storage->delete('settings', 'setting.json');
    } else {
        $storage->put('settings', 'setting.json', $original['plugin']);
    }
    $pluginSettings->clearCache('sirsoft-social_login');
    if ($original['plugin'] !== $storage->get('settings', 'setting.json')) {
        throw new RuntimeException('Fixture restore mismatch');
    }
    if (array_key_exists('providers', $original)) {
        DB::transaction(function () use ($original) {
            DB::table('social_login_providers')->whereNotIn('id', array_column($original['providers'], 'id'))->delete();
            foreach ($original['providers'] as $row) {
                DB::table('social_login_providers')->where('id', $row['id'])->update($row);
            }
        });
        if (md5(json_encode($original['providers'])) !== md5(DB::table('social_login_providers')->orderBy('id')->get()->map(fn ($row) => (array) $row)->toJson())) {
            throw new RuntimeException('Fixture provider restore mismatch');
        }
    }
    unlink($backupPath);
    echo "Restored exact settings and provider rows\n";
    exit;
}

if (is_file($backupPath)) {
    throw new RuntimeException('Restore the previous browser fixture before preparing another');
}
File::ensureDirectoryExists(dirname($backupPath));
file_put_contents($backupPath, Crypt::encryptString(json_encode([
    'plugin' => $storage->get('settings', 'setting.json'),
    'providers' => DB::table('social_login_providers')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
], JSON_THROW_ON_ERROR)));
echo "Backed up settings\n";
