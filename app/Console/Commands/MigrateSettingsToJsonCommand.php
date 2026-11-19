<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Commands\Traits\LocalizesDescription;
use App\Models\SystemConfig;
use App\Repositories\JsonConfigRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * 기존 DB 설정을 JSON 파일로 마이그레이션하는 커맨드
 */
class MigrateSettingsToJsonCommand extends Command
{
    use HasUnifiedConfirm;
    use LocalizesDescription;

    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'settings:migrate-to-json
                            {--force : 기존 JSON 파일이 있어도 덮어쓰기}
                            {--y|yes : 덮어쓰기 확인 질문에 「예」로 답하고 진행 (터미널 없는 실행용)}';

    /**
     * 설정 키와 카테고리 매핑
     *
     * @var array<string, string>
     */
    private array $categoryMap = [
        // general
        'site_name' => 'general',
        'site_url' => 'general',
        'site_description' => 'general',
        'admin_email' => 'general',
        'timezone' => 'general',
        'language' => 'general',
        'currency' => 'general',
        'description' => 'general',
        'keywords' => 'general',

        // security
        'force_https' => 'security',
        'login_attempt_enabled' => 'security',
        'auth_token_lifetime' => 'security',
        'max_login_attempts' => 'security',
        'login_lockout_time' => 'security',
        'two_factor_auth' => 'security',
        'password_min_length' => 'security',
        'require_password_special_char' => 'security',

        // mail
        'mailer' => 'mail',
        'mail_host' => 'mail',
        'mail_port' => 'mail',
        'mail_username' => 'mail',
        'mail_password' => 'mail',
        'mail_encryption' => 'mail',
        'mail_from_address' => 'mail',
        'mail_from_name' => 'mail',

        // upload
        'max_file_size' => 'upload',
        'allowed_extensions' => 'upload',
        'image_max_width' => 'upload',
        'image_max_height' => 'upload',
        'image_quality' => 'upload',
        // 이미지 썸네일 (#869) — 옛 system_configs 에 있었을 리 없는 키지만, 같은 이름이 남아 있으면 upload 로 옮긴다
        'thumbnail_enabled' => 'upload',
        'thumbnail_presets' => 'upload',
        'thumbnail_content_preset' => 'upload',
        'thumbnail_disk_public' => 'upload',
        'thumbnail_disk_gated' => 'upload',
        'thumbnail_prune_enabled' => 'upload',
        'thumbnail_animated_original' => 'upload',
        'thumbnail_generate_per_minute' => 'upload',
        'thumbnail_lock_wait_seconds' => 'upload',
        'thumbnail_max_source_pixels' => 'upload',
        'thumbnail_pregenerate_batch' => 'upload',
        'thumbnail_pregenerate_time_budget' => 'upload',
        'thumbnail_purge_time_budget' => 'upload',

        // seo
        'meta_title_suffix' => 'seo',
        'meta_description' => 'seo',
        'meta_keywords' => 'seo',
        'google_analytics_id' => 'seo',
        'google_site_verification' => 'seo',
        'naver_site_verification' => 'seo',

        // cache
        'cache_enabled' => 'cache',
        'layout_cache_enabled' => 'cache',
        'layout_cache_ttl' => 'cache',
        'stats_cache_enabled' => 'cache',
        'stats_cache_ttl' => 'cache',
        'seo_cache_enabled' => 'cache',
        'seo_cache_ttl' => 'cache',

        // debug
        'debug_mode' => 'debug',
        'sql_query_log' => 'debug',
        'log_level' => 'debug',
        'outbound_proxy' => 'debug',
        'outbound_proxy_bypass' => 'debug',
    ];

    /**
     * 옮기지 않고 버리는 옛 키 (어디서도 읽지 않는 키)
     *
     * 매핑에 없는 키는 general 로 들어가므로, 지운 설정은 여기 적어야 옮겨 오지 않는다.
     * `maintenance_mode`: 점검 모드는 `php artisan down` / `php artisan up` 이 맡는다 (#888).
     *
     * @var array<int, string>
     */
    private array $droppedKeys = [
        'maintenance_mode',
    ];

    /**
     * 키 이름 변환 매핑 (DB 키 -> JSON 키)
     *
     * @var array<string, string>
     */
    private array $keyMapping = [
        'mail_host' => 'host',
        'mail_port' => 'port',
        'mail_username' => 'username',
        'mail_password' => 'password',
        'mail_encryption' => 'encryption',
        'mail_from_address' => 'from_address',
        'mail_from_name' => 'from_name',
        'cache_enabled' => 'enabled',
        'layout_cache_enabled' => 'layout_enabled',
        'layout_cache_ttl' => 'layout_ttl',
        'stats_cache_enabled' => 'stats_enabled',
        'stats_cache_ttl' => 'stats_ttl',
        'seo_cache_enabled' => 'seo_enabled',
        'seo_cache_ttl' => 'seo_ttl',
        'debug_mode' => 'mode',
    ];

    /**
     * 커맨드를 실행합니다.
     *
     * DI 컨테이너 바인딩 시점 문제로 직접 인스턴스화합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $this->info(__('settings.console.migrate_to_json.start'));

        // JsonConfigRepository 직접 인스턴스화 (DI 컨테이너 바인딩 전 실행 가능)
        $configRepository = new JsonConfigRepository;

        // 운영 중인 설정 파일이 있는가 — 옮길 옛 설정이 없을 때 그 파일을 기본값으로 덮지 않는 근거다.
        $hasSettings = $configRepository->existingSettingFiles() !== [];

        // system_configs 테이블 존재 확인 — 7.0 이후 설치본에는 없다(옮길 것이 없음)
        if (! Schema::hasTable('system_configs')) {
            return $this->finishWithoutLegacySettings(
                $configRepository,
                $hasSettings,
                __('settings.console.migrate_to_json.table_missing', ['table' => 'system_configs']),
            );
        }

        // 기존 JSON 파일 존재 확인
        if ($hasSettings && ! $this->option('force')) {
            // 확인에 답할 수 없는 실행(cron, CI, 원격 명령, --no-interaction)은 종전에 기본값 「아니오」로 취소 문구만 내고
            // 종료 코드 0 으로 끝나 자동화가 성공으로 읽었다. 그런 실행은 사유와 --force 안내를 남기고 실패로 끝낸다.
            // 대화형에서 운영자가 직접 「아니오」를 답한 취소는 의도이므로 종전대로 0 이다.
            if (! $this->canAnswerConfirmation()) {
                return $this->refuseUnanswerableConfirmation(
                    __('settings.console.migrate_to_json.confirm_unavailable'),
                    __('settings.console.migrate_to_json.confirm_unavailable_hint'),
                );
            }

            if (! $this->unifiedConfirm(__('settings.console.migrate_to_json.confirm_overwrite'), false)) {
                $this->info(__('settings.console.migrate_to_json.cancelled'));

                return $this->confirmAbortExitCode();
            }
        }

        // 기존 설정 조회
        $configs = SystemConfig::all();

        if ($configs->isEmpty()) {
            return $this->finishWithoutLegacySettings(
                $configRepository,
                $hasSettings,
                __('settings.console.migrate_to_json.nothing_to_migrate'),
            );
        }

        $this->info(__('settings.console.migrate_to_json.found', ['count' => $configs->count()]));

        // 옛 설정으로 기존 설정 파일을 덮기 전에 그대로 사본을 남긴다 — 옛 표에 없는 키는 기본값으로 돌아가므로 되돌릴 길을 둔다.
        if ($hasSettings && ! $this->copyExistingSettings($configRepository)) {
            return Command::FAILURE;
        }

        // 카테고리별로 그룹화
        $grouped = [];
        foreach ($configs as $config) {
            if (in_array($config->key, $this->droppedKeys, true)) {
                continue;
            }

            $category = $this->categoryMap[$config->key] ?? 'general';
            $jsonKey = $this->keyMapping[$config->key] ?? $config->key;
            $grouped[$category][$jsonKey] = $this->parseValue($config->value, $config->type);
        }

        // JSON 파일로 저장
        $defaults = $configRepository->getDefaults();

        foreach ($defaults as $category => $defaultSettings) {
            $categorySettings = array_merge(
                $defaultSettings,
                $grouped[$category] ?? []
            );

            $configRepository->saveCategory($category, $categorySettings);
            $this->info('  ✓ '.__('settings.console.migrate_to_json.category_saved', ['category' => $category]));
        }

        $this->newLine();
        $this->info(__('settings.console.migrate_to_json.done'));
        $this->info(__('settings.console.migrate_to_json.location', ['path' => 'storage/app/settings/']));

        return Command::SUCCESS;
    }

    /**
     * 옮길 옛 설정이 없을 때(표가 없거나 비었음) 끝냅니다 — 설정 파일이 없으면 기본값을 만들고, 있으면 덮지 않습니다.
     *
     * 7.0 이후 설치본에는 옛 설정 표가 없으므로 이 명령을 실행하면 늘 이 경로다. 종전에는 기존 설정 파일·`--force`·확인을
     * 보지 않고 전 설정을 기본값으로 저장해, 한 번의 실행으로 운영 설정(사이트 이름·주소·관리자 이메일·메일·드라이버 등)이
     * 사라졌다. 기본값으로 되돌리기는 `--force` 와 확인(`--yes` 또는 대화형 「예」)이 함께 있을 때만 하고, 덮기 전에
     * 기존 파일의 사본을 남긴다. 확인에 답할 수 없으면 거절하고 실패로 끝낸다.
     *
     * @param  JsonConfigRepository  $configRepository  설정 저장소
     * @param  bool  $hasSettings  설정 파일이 이미 있는지
     * @param  string  $reason  옮길 것이 없는 사유 (표 없음·표 비었음)
     * @return int 종료 코드
     */
    private function finishWithoutLegacySettings(JsonConfigRepository $configRepository, bool $hasSettings, string $reason): int
    {
        if (! $hasSettings) {
            $this->warn($reason);
            $configRepository->initialize();
            $this->info(__('settings.console.migrate_to_json.defaults_created'));

            return Command::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->info(__('settings.console.migrate_to_json.existing_kept'));

            return Command::SUCCESS;
        }

        if (! $this->canAnswerConfirmation()) {
            return $this->refuseUnanswerableConfirmation(
                __('settings.console.migrate_to_json.reset_unavailable'),
                __('settings.console.migrate_to_json.reset_unavailable_hint'),
            );
        }

        if (! $this->unifiedConfirm(__('settings.console.migrate_to_json.confirm_reset'), false)) {
            $this->info(__('settings.console.migrate_to_json.cancelled'));

            return $this->confirmAbortExitCode();
        }

        if (! $this->copyExistingSettings($configRepository)) {
            return Command::FAILURE;
        }

        $configRepository->initialize();
        $this->info(__('settings.console.migrate_to_json.defaults_created'));

        return Command::SUCCESS;
    }

    /**
     * 기존 설정 파일의 사본을 설정 백업 폴더에 남깁니다. 남기지 못하면 덮어쓰지 않도록 실패를 알립니다.
     *
     * @param  JsonConfigRepository  $configRepository  설정 저장소
     * @return bool 사본을 남겼으면 true
     */
    private function copyExistingSettings(JsonConfigRepository $configRepository): bool
    {
        try {
            $directory = $configRepository->copySettingFiles('migrate-to-json');
        } catch (\Throwable $e) {
            $this->error(__('settings.console.migrate_to_json.copy_failed', ['error' => $e->getMessage()]));

            return false;
        }

        $this->info(__('settings.console.migrate_to_json.copy_created', ['path' => 'storage/app/settings/'.$directory]));

        return true;
    }

    /**
     * 문자열 값을 원래 타입으로 파싱합니다.
     *
     * @param  string  $value  system_configs 에 저장된 문자열 값
     * @param  string  $type  저장된 값 타입 (boolean|integer|float|json|array, 그 외는 문자열 유지)
     * @return mixed 타입에 맞게 변환한 값
     */
    private function parseValue(string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'float' => (float) $value,
            'json', 'array' => json_decode($value, true) ?: [],
            default => $value,
        };
    }
}
