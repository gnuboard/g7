<?php

use App\Extension\PluginManager;
use App\Services\PluginSettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Plugins\Sirsoft\Koreaexim\Enums\RateType;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRun;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRunItem;
use Plugins\Sirsoft\Koreaexim\Repositories\Contracts\RateRunRepositoryInterface;
use Plugins\Sirsoft\Koreaexim\Services\ExchangeRateCalculator;

// 브라우저 검증용 설정은 암호화하여 보관하고 외부 은행 요청 없이 고정 이력을 준비합니다.
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
$settings = app(EcommerceSettingsService::class);
$pluginSettings = app(PluginSettingsService::class);
$storage = app(PluginManager::class)->getPlugin('sirsoft-koreaexim')->getStorage();
$currencyPath = (new ReflectionMethod($settings, 'getCategoryFilePath'))->invoke($settings, 'language_currency');
$backupPath = storage_path('framework/testing/koreaexim-browser-backup.enc');
$marker = 'koreaexim-browser-fixture';
// 수동 실행 쿨다운(60초)·일일 상한 키를 지운다 — 시나리오 순서가 서로의 쿨다운에 묶이지 않게 한다.
if (($argv[1] ?? '') === 'cooldown') {
    RateLimiter::clear('koreaexim:manual:cooldown');
    RateLimiter::clear('koreaexim:manual:'.now('Asia/Seoul')->format('Ymd'));
    echo "Cleared manual run cooldown and daily budget\n";
    exit;
}
if (($argv[1] ?? '') === 'restore') {
    if (! is_file($backupPath)) {
        echo "No pending browser fixture\n";
        exit;
    }
    $original = json_decode(Crypt::decryptString(file_get_contents($backupPath)), true, flags: JSON_THROW_ON_ERROR);
    $ids = KoreaeximRateRun::where('message', $marker)->pluck('id');
    KoreaeximRateRunItem::whereIn('run_id', $ids)->delete();
    KoreaeximRateRun::whereIn('id', $ids)->delete();
    if ($original['currency'] === null) {
        if (is_file($currencyPath)) {
            unlink($currencyPath);
        }
    } else {
        file_put_contents($currencyPath, $original['currency']);
    }
    if ($original['plugin'] === null) {
        $storage->delete('settings', 'setting.json');
    } else {
        $storage->put('settings', 'setting.json', $original['plugin']);
    }
    $settings->clearCache();
    $pluginSettings->clearCache('sirsoft-koreaexim');
    g7_refresh_module_settings_config('sirsoft-ecommerce');
    if (($original['currency'] ?? null) !== (is_file($currencyPath) ? file_get_contents($currencyPath) : null)
        || $original['plugin'] !== $storage->get('settings', 'setting.json')) {
        throw new RuntimeException('Fixture restore mismatch');
    }
    unlink($backupPath);
    echo "Restored exact settings bytes; removed fixture history\n";
    exit;
}
if (is_file($backupPath)) {
    throw new RuntimeException('Restore the previous browser fixture before preparing another');
}
File::ensureDirectoryExists(dirname($backupPath));
file_put_contents($backupPath, Crypt::encryptString(json_encode([
    'currency' => is_file($currencyPath) ? file_get_contents($currencyPath) : null,
    'plugin' => $storage->get('settings', 'setting.json'),
], JSON_THROW_ON_ERROR)));
$pluginSettings->save('sirsoft-koreaexim', ['auth_key' => 'browser-fixture-key', 'auto_manage' => true, 'update_time' => '11:30', 'run_days' => [1, 2, 3, 4, 5]]);
$currency = $settings->getSettings('language_currency');
$currency['default_currency'] = 'KRW';
$currency['rate_provider'] = 'koreaexim';
$defaults = json_decode(file_get_contents($root.'/modules/_bundled/sirsoft-ecommerce/config/settings/defaults.json'), true);
$currency['currencies'] = $defaults['defaults']['language_currency']['currencies'];
$currency['currencies'][] = ['code' => 'VND', 'name' => ['ko' => '베트남 동', 'en' => 'Vietnamese dong'],
    'symbol' => '₫', 'exchange_rate' => 18000, 'base_unit' => 1, 'rounding_unit' => '1',
    'rounding_method' => 'round', 'decimal_places' => 0, 'is_default' => false, 'locales' => []];
$settings->saveSettings(['language_currency' => $currency]);
$quotes = json_decode(file_get_contents(dirname(__DIR__, 2).'/fixtures/koreaexim/success.json'), true);
$calculation = app(ExchangeRateCalculator::class)->calculate($quotes, $currency['currencies'], 'KRW', RateType::DealBaseRate);
$settings->applyExchangeRates($calculation['rates'], 'koreaexim');
$repository = app(RateRunRepositoryInterface::class);
for ($index = 0; $index < 41; $index++) {
    $repository->create(['trigger' => 'manual', 'status' => 'success', 'search_date' => now('Asia/Seoul')->toDateString(),
        'rate_type' => 'deal_bas_r', 'base_currency' => 'KRW', 'base_unit' => 1000, 'request_count' => 0,
        'applied_count' => 4, 'message' => $marker, 'raw_response' => $quotes, 'started_at' => now(), 'finished_at' => now()], $calculation['items']);
}
echo "Prepared six currencies including unsupported VND, masked fixture key and 41 success runs\n";
