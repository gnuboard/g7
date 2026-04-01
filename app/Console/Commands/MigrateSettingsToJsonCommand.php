<?php

namespace App\Console\Commands;

use App\Models\SystemConfig;
use App\Repositories\JsonConfigRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * 기존 DB 설정을 JSON 파일로 마이그레이션하는 커맨드
 */
class MigrateSettingsToJsonCommand extends Command
{
    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'settings:migrate-to-json
                            {--force : 기존 JSON 파일이 있어도 덮어쓰기}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'DB의 system_configs 테이블 데이터를 JSON 파일로 마이그레이션합니다.';

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
        'maintenance_mode' => 'general',
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
     * @return int
     */
    public function handle(): int
    {
        $this->info('설정 마이그레이션을 시작합니다...');

        // system_configs 테이블 존재 확인
        if (! Schema::hasTable('system_configs')) {
            $this->warn('system_configs 테이블이 존재하지 않습니다. 기본값으로 초기화합니다.');

            $configRepository = new JsonConfigRepository();
            $configRepository->initialize();

            $this->info('기본 설정 파일이 생성되었습니다.');

            return Command::SUCCESS;
        }

        // JsonConfigRepository 직접 인스턴스화 (DI 컨테이너 바인딩 전 실행 가능)
        $configRepository = new JsonConfigRepository();

        // 기존 JSON 파일 존재 확인
        if (file_exists(storage_path('app/settings/general.json')) && ! $this->option('force')) {
            if (! $this->confirm('기존 JSON 설정 파일이 존재합니다. 덮어쓰시겠습니까?')) {
                $this->info('마이그레이션이 취소되었습니다.');

                return Command::SUCCESS;
            }
        }

        // 기존 설정 조회
        $configs = SystemConfig::all();

        if ($configs->isEmpty()) {
            $this->warn('마이그레이션할 설정이 없습니다. 기본값으로 초기화합니다.');
            $configRepository->initialize();
            $this->info('기본 설정 파일이 생성되었습니다.');

            return Command::SUCCESS;
        }

        $this->info("총 {$configs->count()}개의 설정을 발견했습니다.");

        // 카테고리별로 그룹화
        $grouped = [];
        foreach ($configs as $config) {
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
            $this->info("  ✓ {$category} 카테고리 저장 완료");
        }

        $this->newLine();
        $this->info('마이그레이션이 완료되었습니다!');
        $this->info('설정 파일 위치: storage/app/settings/');

        return Command::SUCCESS;
    }

    /**
     * 문자열 값을 원래 타입으로 파싱합니다.
     *
     * @param string $value
     * @param string $type
     * @return mixed
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
