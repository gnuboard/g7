<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\HookManager;
use App\Http\Resources\AttachmentResource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * 시스템 설정 서비스
 *
 * JSON 파일 기반 설정 관리를 담당합니다.
 */
class SettingsService
{
    public function __construct(
        private ConfigRepositoryInterface $configRepository,
        private AttachmentRepositoryInterface $attachmentRepository,
        private CacheInterface $cache
    ) {}

    /**
     * 시스템 설정 캐시를 무효화합니다.
     *
     * 설정 저장 시 4곳에서 중복되던 로직을 단일 메서드로 통합했습니다.
     *
     * @return void
     */
    private function invalidateSettingsCache(): void
    {
        $this->cache->forget('settings.system');
        Artisan::call('config:clear');
    }

    /**
     * 모든 시스템 설정을 조회합니다.
     *
     * nested 구조로 반환합니다:
     * { general: { site_name: '...' }, mail: { ... }, ... }
     *
     * 프론트엔드 스키마의 frontend_key 매핑을 적용하여
     * JSON 레이아웃에서 사용하는 필드명으로 변환합니다.
     *
     * 스키마의 frontend_name/merge_into 설정에 따라 카테고리를 자동 병합합니다.
     * (예: cache, debug → advanced)
     *
     * @return array 시스템 설정 배열
     */
    public function getAllSettings(): array
    {
        $settings = [];
        $schema = $this->configRepository->getFrontendSchema();

        // 모든 카테고리 설정 로드 (nested 구조)
        foreach ($this->configRepository->all() as $category => $categorySettings) {
            $categorySchema = $schema[$category] ?? null;
            $fields = $categorySchema['fields'] ?? [];

            // 스키마의 frontend_name 또는 merge_into에 따라 타겟 카테고리 결정
            $targetCategory = $categorySchema['frontend_name'] ?? $categorySchema['merge_into'] ?? $category;

            // 타겟 카테고리 초기화
            if (! isset($settings[$targetCategory])) {
                $settings[$targetCategory] = [];
            }

            // 원본 카테고리도 유지 (debug, cache 등 개별 접근용)
            if ($targetCategory !== $category && ! isset($settings[$category])) {
                $settings[$category] = [];
            }

            foreach ($categorySettings as $key => $value) {
                // frontend_key가 정의되어 있으면 해당 키로 변환
                $outputKey = $fields[$key]['frontend_key'] ?? $key;

                // 타겟 카테고리에 병합
                $settings[$targetCategory][$outputKey] = $value;

                // 원본 카테고리에도 저장 (개별 접근용)
                if ($targetCategory !== $category) {
                    $settings[$category][$outputKey] = $value;
                }
            }
        }

        // 첨부파일 정보 추가 (general 카테고리에)
        $settings['general']['site_logo'] = $this->getSiteLogoAttachment();

        return $settings;
    }

    /**
     * 특정 카테고리의 설정을 조회합니다.
     *
     * @param  string  $category  카테고리명
     * @return array 설정 배열
     */
    public function getSettings(string $category): array
    {
        return $this->configRepository->getCategory($category);
    }

    /**
     * 프론트엔드에서 사용할 전체 설정을 조회합니다.
     *
     * config/settings/defaults.json의 frontend_schema를 기반으로
     * 각 카테고리별 설정을 동적으로 구조화하여 반환합니다.
     * 민감한 정보(sensitive: true)는 제외합니다.
     *
     * @return array 카테고리별 설정 배열
     */
    public function getFrontendSettings(): array
    {
        $schema = $this->configRepository->getFrontendSchema();
        $result = [];

        foreach ($schema as $category => $categorySchema) {
            // _description 등 메타 키 건너뛰기
            if (str_starts_with($category, '_')) {
                continue;
            }

            // expose가 false이면 건너뛰기
            if (! ($categorySchema['expose'] ?? false)) {
                continue;
            }

            // merge_into가 있으면 해당 카테고리에 병합
            $targetCategory = $categorySchema['frontend_name'] ?? $categorySchema['merge_into'] ?? $category;

            // 설정값 조회
            $settings = $this->getSettings($category);
            $formattedSettings = $this->formatCategorySettings($settings, $categorySchema);

            // 타겟 카테고리에 병합
            if (isset($result[$targetCategory])) {
                $result[$targetCategory] = array_merge($result[$targetCategory], $formattedSettings);
            } else {
                $result[$targetCategory] = $formattedSettings;
            }
        }

        return $result;
    }

    /**
     * 프론트엔드에 노출할 app config 값을 반환합니다.
     *
     * config/frontend.php의 app_config 스키마를 기반으로
     * config() 함수에서 값을 조회하여 반환합니다.
     *
     * @return array<string, mixed> 프론트엔드용 앱 설정 배열
     */
    public function getAppConfigForFrontend(): array
    {
        $fields = config('frontend.app_config', []);
        $result = [];

        foreach ($fields as $frontendKey => $fieldSchema) {
            $configKey = $fieldSchema['config_key'] ?? null;
            if (! $configKey) {
                continue;
            }

            $value = config($configKey);

            if ($frontendKey === 'supportedTimezones' && is_array($value)) {
                $result[$frontendKey] = $this->buildTimezoneOptions($value);
                continue;
            }

            $result[$frontendKey] = $this->castValue($value, $fieldSchema);
        }

        return $result;
    }

    /**
     * IANA 타임존 배열을 프론트엔드 Select 옵션 형식으로 변환합니다.
     *
     * 각 타임존의 현재 시점 UTC 오프셋을 계산하여
     * "(UTC±HH:MM) Asia/Seoul" 형식의 라벨을 생성합니다.
     * DST 전환 시점에 자동으로 정확한 오프셋이 반영됩니다.
     *
     * 정렬 순서: 오프셋 오름차순 → 식별자 알파벳순
     *
     * @param  array<int, string>  $timezones  IANA 타임존 식별자 배열
     * @return array<int, array{value: string, label: string}> Select 옵션 배열
     */
    private function buildTimezoneOptions(array $timezones): array
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        $items = [];
        foreach ($timezones as $tz) {
            if (! is_string($tz) || $tz === '') {
                continue;
            }

            try {
                $offsetSec = (new \DateTimeZone($tz))->getOffset($now);
            } catch (\Exception) {
                continue;
            }

            $sign = $offsetSec >= 0 ? '+' : '-';
            $h = intdiv(abs($offsetSec), 3600);
            $m = intdiv(abs($offsetSec) % 3600, 60);

            $items[] = [
                'value' => $tz,
                'label' => sprintf('(UTC%s%02d:%02d) %s', $sign, $h, $m, $tz),
                'offset' => $offsetSec,
            ];
        }

        usort($items, fn ($a, $b) => [$a['offset'], $a['value']] <=> [$b['offset'], $b['value']]);

        return array_map(
            fn ($i) => ['value' => $i['value'], 'label' => $i['label']],
            $items
        );
    }

    /**
     * 스키마에 따라 카테고리 설정을 포맷팅합니다.
     *
     * @param  array  $settings  원본 설정 배열
     * @param  array  $categorySchema  카테고리 스키마
     * @return array 포맷팅된 설정 배열
     */
    private function formatCategorySettings(array $settings, array $categorySchema): array
    {
        $fields = $categorySchema['fields'] ?? [];
        $result = [];

        foreach ($fields as $fieldName => $fieldSchema) {
            // 민감 정보는 건너뛰기
            if ($fieldSchema['sensitive'] ?? false) {
                continue;
            }

            // 필드 레벨 expose: false는 건너뛰기
            if (isset($fieldSchema['expose']) && $fieldSchema['expose'] === false) {
                continue;
            }

            // 출력 키 결정 (frontend_key가 있으면 사용)
            $outputKey = $fieldSchema['frontend_key'] ?? $fieldName;

            // computed 필드는 별도 처리
            if ($fieldSchema['computed'] ?? false) {
                $result[$outputKey] = $this->computeFieldValue($settings, $fieldSchema);

                continue;
            }

            // 값 조회 및 타입 캐스팅
            $value = $settings[$fieldName] ?? null;
            $result[$outputKey] = $this->castValue($value, $fieldSchema);
        }

        return $result;
    }

    /**
     * computed 필드의 값을 계산합니다.
     *
     * @param  array  $settings  설정 배열
     * @param  array  $fieldSchema  필드 스키마
     * @return mixed 계산된 값
     */
    private function computeFieldValue(array $settings, array $fieldSchema): mixed
    {
        $sourceField = $fieldSchema['source'] ?? null;
        $transform = $fieldSchema['transform'] ?? null;

        if (! $sourceField || ! $transform) {
            return null;
        }

        $sourceValue = $settings[$sourceField] ?? null;

        return match ($transform) {
            'format_extensions' => $this->formatExtensions($sourceValue),
            'first_image_url' => $this->getFirstImageUrl($sourceValue),
            default => $sourceValue,
        };
    }

    /**
     * 확장자 배열/문자열을 .jpg,.png 형식으로 변환합니다.
     *
     * @param  mixed  $extensions  확장자 배열 또는 문자열
     * @return string 포맷팅된 확장자 문자열
     */
    private function formatExtensions(mixed $extensions): string
    {
        if (empty($extensions)) {
            return '';
        }

        // 배열인 경우 문자열로 변환
        if (is_array($extensions)) {
            $extensions = implode(',', $extensions);
        }

        // 확장자 변환: 'jpg,jpeg,png' → '.jpg,.jpeg,.png'
        $extArray = array_map('trim', explode(',', $extensions));
        $formatted = array_map(fn ($ext) => '.'.ltrim($ext, '.'), $extArray);

        return implode(',', $formatted);
    }

    /**
     * 스키마 타입에 따라 값을 캐스팅합니다.
     *
     * @param  mixed  $value  원본 값
     * @param  array  $fieldSchema  필드 스키마
     * @return mixed 캐스팅된 값
     */
    private function castValue(mixed $value, array $fieldSchema): mixed
    {
        $type = $fieldSchema['type'] ?? 'string';
        $transform = $fieldSchema['transform'] ?? null;

        // transform이 있으면 먼저 처리
        if ($transform === 'join_comma' && is_array($value)) {
            $value = implode(',', $value);
        }

        // null이면 타입별 기본값 반환
        if ($value === null) {
            return match ($type) {
                'boolean' => false,
                'integer' => 0,
                'string' => '',
                'array' => [],
                default => null,
            };
        }

        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'string' => (string) $value,
            'array' => (array) $value,
            default => $value,
        };
    }

    /**
     * site_logo 설정의 첨부파일 목록을 조회합니다.
     *
     * JSON 설정에 저장된 attachment ID 배열을 기반으로 첨부파일 정보를 조회합니다.
     *
     * @return array 첨부파일 정보 배열
     */
    private function getSiteLogoAttachment(): array
    {
        // JSON 설정에서 site_logo ID 배열 조회
        $siteLogoIds = $this->configRepository->get('general.site_logo', []);

        // 빈 배열이거나 배열이 아닌 경우
        if (empty($siteLogoIds) || ! is_array($siteLogoIds)) {
            return [];
        }

        // ID 배열로 첨부파일 조회 (DB order 기준 정렬)
        $attachments = $this->attachmentRepository->findByIds($siteLogoIds);

        if ($attachments->isEmpty()) {
            return [];
        }

        return $attachments->map(fn ($attachment) => (new AttachmentResource($attachment))->toListArray())->toArray();
    }

    /**
     * 첨부파일 ID 배열에서 첫 번째 이미지의 URL을 반환합니다.
     *
     * @param  mixed  $attachmentIds  첨부파일 ID 배열
     * @return string|null 첫 번째 이미지의 다운로드 URL 또는 null
     */
    private function getFirstImageUrl(mixed $attachmentIds): ?string
    {
        if (empty($attachmentIds) || ! is_array($attachmentIds)) {
            return null;
        }

        // ID 배열로 첨부파일 조회
        $attachments = $this->attachmentRepository->findByIds($attachmentIds);

        if ($attachments->isEmpty()) {
            return null;
        }

        // 첫 번째 이미지 파일 찾기
        $firstImage = $attachments->first(fn ($attachment) => $attachment->is_image);

        return $firstImage?->download_url;
    }

    public function saveSettings(array $settings): bool
    {
        // Before 훅
        HookManager::doAction('core.settings.before_save', $settings);

        // 필터 훅 - 설정 데이터 변형
        $settings = HookManager::applyFilters('core.settings.filter_save_data', $settings);

        try {
            // _tab 키로 카테고리 결정
            $tab = $settings['_tab'] ?? 'general';
            unset($settings['_tab']);

            // nested 구조에서 해당 탭의 설정 추출
            // 예: { general: { site_name: '...' } } → { site_name: '...' }
            $tabSettings = $settings[$tab] ?? $settings;

            // frontend_key를 원본 키로 역변환
            $tabSettings = $this->reverseFrontendKeys($tabSettings);

            // advanced 탭은 cache와 debug 두 카테고리로 분리
            if ($tab === 'advanced') {
                $result = $this->saveAdvancedSettings($tabSettings);

                // After 훅
                HookManager::doAction('core.settings.after_save', $tab, $tabSettings, $result);

                return $result;
            }

            // general 탭인 경우 site_logo 첨부파일 연결
            if ($tab === 'general') {
                $tabSettings['site_logo'] = $this->collectSiteLogoIds();
            }

            // 기존 설정과 병합 (탭별로 일부 필드만 전송되어도 기존 설정 유지)
            $existingSettings = $this->configRepository->getCategory($tab);
            $mergedSettings = array_merge($existingSettings, $tabSettings);

            // 해당 카테고리 설정 저장
            $result = $this->configRepository->saveCategory($tab, $mergedSettings);

            if ($result) {
                $this->invalidateSettingsCache();

                // drivers 탭은 queue/broadcasting/cache 등 long-running worker에 영향
                // SettingsServiceProvider는 worker boot 시점에 한 번만 config 적용하므로
                // 워커가 정상 종료 후 재시작되도록 신호 전송 (cache 기반, 즉시 종료 X)
                if ($tab === 'drivers') {
                    try {
                        Artisan::call('queue:restart');
                    } catch (\Throwable $e) {
                        Log::warning('queue:restart 실행 실패', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // After 훅
            HookManager::doAction('core.settings.after_save', $tab, $mergedSettings, $result);

            return $result;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'settings' => [__('settings.save_failed').': '.$e->getMessage()],
            ]);
        }
    }

    /**
     * frontend_key를 원본 저장소 키로 역변환합니다.
     *
     * @param  array  $settings  프론트엔드 키 기반 설정
     * @return array 원본 키 기반 설정
     */
    private function reverseFrontendKeys(array $settings): array
    {
        $schema = $this->configRepository->getFrontendSchema();
        $reverseMap = $this->buildReverseKeyMap($schema);

        $result = [];
        foreach ($settings as $key => $value) {
            $originalKey = $reverseMap[$key] ?? $key;
            $result[$originalKey] = $value;
        }

        return $result;
    }

    /**
     * frontend_key → 원본 키 역변환 맵을 생성합니다.
     *
     * @param  array  $schema  프론트엔드 스키마
     * @return array 역변환 맵
     */
    private function buildReverseKeyMap(array $schema): array
    {
        $map = [];
        foreach ($schema as $category => $categorySchema) {
            if (str_starts_with($category, '_')) {
                continue;
            }

            $fields = $categorySchema['fields'] ?? [];
            foreach ($fields as $fieldName => $fieldSchema) {
                $frontendKey = $fieldSchema['frontend_key'] ?? null;
                if ($frontendKey) {
                    $map[$frontendKey] = $fieldName;
                }
            }
        }

        return $map;
    }

    /**
     * advanced 탭 설정을 cache와 debug 카테고리로 분리하여 저장합니다.
     *
     * @param  array  $settings  저장할 설정 배열
     * @return bool 저장 성공 여부
     */
    private function saveAdvancedSettings(array $settings): bool
    {
        // 각 카테고리에 속하는 원본 필드명 목록
        // (frontend_key → 원본 키 역변환 후의 키 기준)
        $categoryFieldMap = [
            'cache' => ['enabled', 'layout_enabled', 'layout_ttl', 'stats_enabled', 'stats_ttl', 'seo_enabled', 'seo_ttl'],
            'debug' => ['mode', 'sql_query_log', 'log_level'],
            'core_update' => ['github_url', 'github_token'],
            'geoip' => ['feature_enabled', 'license_key', 'auto_update_enabled', 'last_updated_at'],
        ];

        // 설정을 카테고리별로 분류
        $categorized = array_fill_keys(array_keys($categoryFieldMap), []);

        foreach ($settings as $key => $value) {
            foreach ($categoryFieldMap as $category => $fields) {
                if (in_array($key, $fields)) {
                    $categorized[$category][$key] = $value;
                    break;
                }
            }
        }

        $result = true;

        // 각 카테고리별 병합 저장
        foreach ($categorized as $category => $categorySettings) {
            if (empty($categorySettings)) {
                continue;
            }

            $existing = $this->configRepository->getCategory($category);
            $merged = array_merge($existing, $categorySettings);
            $result = $result && $this->configRepository->saveCategory($category, $merged);
        }

        if ($result) {
            $this->invalidateSettingsCache();
        }

        return $result;
    }

    /**
     * site_logo 컬렉션의 첨부파일 ID 목록을 수집합니다.
     *
     * @return array<int> 첨부파일 ID 배열
     */
    private function collectSiteLogoIds(): array
    {
        $attachments = $this->attachmentRepository->getByCollection('site_logo');

        return $attachments->pluck('id')->toArray();
    }

    /**
     * 특정 키의 설정값을 조회합니다.
     *
     * @param  string  $key  설정 키 (도트 노테이션 지원)
     * @param  mixed  $default  기본값
     * @return mixed 설정값
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->configRepository->get($key, $default);
    }

    public function setSetting(string $key, mixed $value): bool
    {
        // Before 훅
        HookManager::doAction('core.settings.before_set', $key, $value);

        try {
            $result = $this->configRepository->set($key, $value);

            if ($result) {
                $this->cache->forget('settings.system');
            }

            // After 훅
            HookManager::doAction('core.settings.after_set', $key, $value, $result);

            return $result;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'setting' => [__('settings.save_individual_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 설정을 백업합니다.
     *
     * @return string 백업 파일 경로
     */
    public function backupSettings(): string
    {
        return $this->configRepository->backup();
    }

    /**
     * 백업에서 설정을 복원합니다.
     *
     * @param  string  $backupPath  백업 파일 경로
     * @return bool 복원 성공 여부
     */
    public function restoreSettings(string $backupPath): bool
    {
        $result = $this->configRepository->restore($backupPath);

        if ($result) {
            $this->cache->forget('settings.system');
        }

        return $result;
    }

    /**
     * 테스트 메일을 발송합니다.
     *
     * @param  string  $toEmail  수신자 이메일
     * @param  array  $overrideSettings  폼에서 전달된 메일 설정 (저장된 값보다 우선)
     * @return array 발송 결과
     */
    public function sendTestMail(string $toEmail, array $overrideSettings = []): array
    {
        try {
            $savedSettings = $this->configRepository->getCategory('mail');
            $mailSettings = array_merge($savedSettings, $overrideSettings);

            // 메일 설정 임시 적용 (드라이버별)
            $mailer = $mailSettings['mailer'] ?? 'smtp';
            config(['mail.default' => $mailer]);

            match ($mailer) {
                'smtp' => config([
                    'mail.mailers.smtp.host' => $mailSettings['host'] ?? '',
                    'mail.mailers.smtp.port' => (int) ($mailSettings['port'] ?? 587),
                    'mail.mailers.smtp.username' => $mailSettings['username'] ?? '',
                    'mail.mailers.smtp.password' => $mailSettings['password'] ?? '',
                    'mail.mailers.smtp.encryption' => $mailSettings['encryption'] ?? 'tls',
                ]),
                'mailgun' => config([
                    'mail.mailers.mailgun.transport' => 'mailgun',
                    'services.mailgun.domain' => $mailSettings['mailgun_domain'] ?? '',
                    'services.mailgun.secret' => $mailSettings['mailgun_secret'] ?? '',
                    'services.mailgun.endpoint' => ! empty($mailSettings['mailgun_endpoint']) ? $mailSettings['mailgun_endpoint'] : 'api.mailgun.net',
                ]),
                'ses' => config([
                    'mail.mailers.ses.transport' => 'ses',
                    'services.ses.key' => $mailSettings['ses_key'] ?? '',
                    'services.ses.secret' => $mailSettings['ses_secret'] ?? '',
                    'services.ses.region' => $mailSettings['ses_region'] ?? 'ap-northeast-2',
                ]),
                default => null,
            };

            config([
                'mail.from.address' => $mailSettings['from_address'] ?? '',
                'mail.from.name' => $mailSettings['from_name'] ?? '그누보드7',
            ]);

            $subject = __('settings.test_mail_subject', ['app_name' => config('app.name')]);
            $body = __('settings.test_mail_body');

            Mail::raw($body, function ($message) use ($toEmail, $subject) {
                $message->to($toEmail)
                    ->subject($subject);

                // X-G7 헤더 추가 (발송 이력 로깅용)
                $message->getHeaders()->addTextHeader('X-G7-Source', 'test_mail');
                $message->getHeaders()->addTextHeader('X-G7-Extension-Type', 'core');
                $message->getHeaders()->addTextHeader('X-G7-Extension-Id', 'core');
            });

            HookManager::doAction('core.mail.after_send', [
                'recipientEmail' => $toEmail,
                'senderEmail' => $mailSettings['from_address'] ?? config('mail.from.address'),
                'senderName' => $mailSettings['from_name'] ?? config('mail.from.name'),
                'subject' => $subject,
                'body' => $body,
                'extensionType' => 'core',
                'extensionIdentifier' => 'core',
                'source' => 'test_mail',
            ]);

            return [
                'success' => true,
                'message' => __('settings.test_mail_sent'),
                'subject' => $subject,
                'body' => $body,
            ];
        } catch (\Exception $e) {
            HookManager::doAction('core.mail.send_failed', [
                'recipientEmail' => $toEmail,
                'senderEmail' => $mailSettings['from_address'] ?? config('mail.from.address'),
                'senderName' => $mailSettings['from_name'] ?? config('mail.from.name'),
                'subject' => $subject ?? null,
                'body' => $body ?? null,
                'extensionType' => 'core',
                'extensionIdentifier' => 'core',
                'source' => 'test_mail',
                'errorMessage' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => __('settings.test_mail_failed'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 시스템 환경 정보를 조회합니다.
     *
     * @return array 시스템 정보 배열
     */
    public function getSystemInfo(): array
    {
        return [
            'os_info' => php_uname('s').' '.php_uname('r'),
            'web_server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->getDatabaseInfo(),
            'g7_version' => config('app.version', '1.0.0'),
            'g7_release_year' => config('app.release_year', '2026'),
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'cpu_info' => $this->getCpuInfo(),
            'memory_usage' => $this->getMemoryUsage(),
            'disk_usage' => $this->getDiskUsage(),
            'php_memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time').__('settings.seconds'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'install_path' => base_path(),
            'config_path' => storage_path('app/settings'),
            'log_path' => storage_path('logs'),
            'upload_path' => storage_path('app/public'),
            'php_extensions' => $this->getPhpExtensions(),
            'database_config' => $this->getDatabaseConfig(),
            'server_time' => now()->format('Y-m-d H:i:s'),
            'timezone' => config('app.timezone'),
        ];
    }

    /**
     * 시스템 캐시를 정리합니다.
     *
     * @return bool 캐시 정리 성공 여부
     */
    public function clearCache(): bool
    {
        try {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 시스템을 최적화합니다 (캐시 생성).
     *
     * @return bool 최적화 성공 여부
     */
    public function optimizeSystem(): bool
    {
        try {
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            Artisan::call('view:cache');

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 어플리케이션 키를 재생성합니다.
     *
     * @param  string  $password  최고 관리자 비밀번호
     * @return array 결과 (success, app_key 또는 error)
     */
    public function regenerateAppKey(string $password): array
    {
        $admin = Auth::user();
        if (! $admin || ! Hash::check($password, $admin->password)) {
            return ['success' => false, 'error' => __('settings.invalid_password')];
        }

        try {
            $newKey = 'base64:'.base64_encode(random_bytes(32));

            $envPath = base_path('.env');
            $envContent = file_get_contents($envPath);
            $envContent = preg_replace('/^APP_KEY=.*/m', 'APP_KEY='.$newKey, $envContent);
            file_put_contents($envPath, $envContent);

            Artisan::call('config:clear');

            return [
                'success' => true,
                'app_key' => $this->maskAppKey($newKey),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => __('settings.app_key_regenerate_failed')];
        }
    }

    /**
     * 앱 키를 마스킹하여 반환합니다.
     *
     * @param  string|null  $key  마스킹할 키
     * @return string 마스킹된 키 문자열
     */
    public function maskAppKey(?string $key = null): string
    {
        $key = $key ?? config('app.key');
        if (empty($key)) {
            return '';
        }

        if (strlen($key) <= 20) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 12).str_repeat('*', 32);
    }

    /**
     * 데이터베이스 연결 정보를 조회합니다.
     *
     * @return string 데이터베이스 정보 문자열
     */
    private function getDatabaseInfo(): string
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
            $version = $connection->select('SELECT VERSION() as version')[0]->version ?? 'Unknown';

            return ucfirst($driver).' '.$version;
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * 현재 메모리 사용량을 조회합니다.
     *
     * @return string 메모리 사용량 문자열
     */
    private function getMemoryUsage(): string
    {
        $bytes = memory_get_usage(true);
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * 디스크 사용량 정보를 조회합니다.
     *
     * @return array 디스크 사용량 정보 배열
     */
    private function getDiskUsage(): array
    {
        $path = PHP_OS_FAMILY === 'Windows' ? 'C:' : '/';
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;

        return [
            'total' => $this->formatBytes($total),
            'used' => $this->formatBytes($used),
            'free' => $this->formatBytes($free),
            'percentage' => round(($used / $total) * 100, 2),
        ];
    }

    /**
     * 바이트 단위를 읽기 쉬운 형태로 변환합니다.
     *
     * @param  int  $bytes  변환할 바이트 수
     * @return string 형식화된 문자열
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * CPU 정보를 조회합니다.
     *
     * @return string CPU 정보 문자열
     */
    private function getCpuInfo(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec('wmic cpu get name 2>&1');
            if ($output) {
                $lines = explode("\n", trim($output));
                if (isset($lines[1])) {
                    return trim($lines[1]);
                }
            }

            return 'Unknown';
        }

        if (file_exists('/proc/cpuinfo')) {
            $cpuInfo = file_get_contents('/proc/cpuinfo');
            if (preg_match('/model name\s*:\s*(.+)/i', $cpuInfo, $matches)) {
                return trim($matches[1]);
            }
        }

        return 'Unknown';
    }

    /**
     * 데이터베이스 연결 구성 정보를 조회합니다.
     *
     * Read/Write 분리 구성 여부를 확인하고 민감 정보(비밀번호)를 제외한
     * 연결 정보를 반환합니다.
     *
     * @return array 데이터베이스 구성 정보 배열
     */
    public function getDatabaseConfig(): array
    {
        $mysqlConfig = config('database.connections.mysql', []);

        // Write 설정 (기본 연결 또는 write 키)
        $writeConfig = $this->extractConnectionConfig($mysqlConfig, 'write');

        // Read 설정 (다중 replica 지원)
        $readConfigs = $this->extractReadConfigs($mysqlConfig);

        // Read와 Write가 동일한 경우 Write Only로 취급
        $readConfigs = $this->filterDuplicateReadConfigs($readConfigs, $writeConfig);

        return [
            'has_read_write_split' => ! empty($readConfigs),
            'write' => $writeConfig,
            'read' => $readConfigs,
        ];
    }

    /**
     * Read 설정 중 Write와 동일한 설정을 필터링합니다.
     *
     * Read와 Write의 host, port, database, username이 모두 동일하면
     * 실질적으로 Read-Write 분리가 아니므로 해당 Read 설정을 제외합니다.
     *
     * @param  array  $readConfigs  Read replica 설정 배열
     * @param  array  $writeConfig  Write 설정
     * @return array 중복 제거된 Read 설정 배열
     */
    private function filterDuplicateReadConfigs(array $readConfigs, array $writeConfig): array
    {
        return array_values(array_filter($readConfigs, function ($read) use ($writeConfig) {
            // host, port, database, username이 모두 동일하면 중복으로 간주
            return ! (
                $read['host'] === $writeConfig['host'] &&
                $read['port'] === $writeConfig['port'] &&
                $read['database'] === $writeConfig['database'] &&
                $read['username'] === $writeConfig['username']
            );
        }));
    }

    /**
     * 연결 설정에서 표시할 정보를 추출합니다.
     *
     * @param  array  $config  전체 MySQL 설정
     * @param  string  $type  연결 타입 (write, read)
     * @return array 표시할 연결 정보
     */
    private function extractConnectionConfig(array $config, string $type): array
    {
        // write/read 키가 있으면 해당 설정 사용, 없으면 기본 설정 사용
        $connectionConfig = $config[$type] ?? [];

        // 배열의 첫 번째 요소인 경우 (다중 연결)
        if (isset($connectionConfig[0]) && is_array($connectionConfig[0])) {
            $connectionConfig = $connectionConfig[0];
        }

        // host 값 추출 (배열인 경우 첫 번째 요소 사용)
        $host = $connectionConfig['host'] ?? $config['host'] ?? 'localhost';
        if (is_array($host)) {
            $host = $host[0] ?? 'localhost';
        }

        // port 값 추출 (배열인 경우 첫 번째 요소 사용)
        $port = $connectionConfig['port'] ?? $config['port'] ?? 3306;
        if (is_array($port)) {
            $port = $port[0] ?? 3306;
        }

        // database 값 추출 (배열인 경우 첫 번째 요소 사용)
        $database = $connectionConfig['database'] ?? $config['database'] ?? '';
        if (is_array($database)) {
            $database = $database[0] ?? '';
        }

        // username 값 추출 (배열인 경우 첫 번째 요소 사용)
        $username = $connectionConfig['username'] ?? $config['username'] ?? '';
        if (is_array($username)) {
            $username = $username[0] ?? '';
        }

        return [
            'host' => $host,
            'port' => (int) $port,
            'database' => $database,
            'username' => $username,
            // 비밀번호는 절대 포함하지 않음
        ];
    }

    /**
     * Read replica 설정을 추출합니다.
     *
     * 다중 Read replica를 지원하며, 각 replica의 민감 정보를 제외하고 반환합니다.
     *
     * @param  array  $config  전체 MySQL 설정
     * @return array Read replica 정보 배열 (없으면 빈 배열)
     */
    private function extractReadConfigs(array $config): array
    {
        $readConfig = $config['read'] ?? null;

        if (empty($readConfig)) {
            return [];
        }

        // 단일 read 설정인 경우 (host만 있는 경우)
        if (isset($readConfig['host']) && ! is_array($readConfig['host'])) {
            return [
                [
                    'host' => $readConfig['host'],
                    'port' => (int) ($readConfig['port'] ?? $config['port'] ?? 3306),
                    'database' => $readConfig['database'] ?? $config['database'] ?? '',
                    'username' => $readConfig['username'] ?? $config['username'] ?? '',
                ],
            ];
        }

        // 다중 read replica 설정인 경우 (배열)
        if (isset($readConfig[0]) && is_array($readConfig[0])) {
            return array_map(function ($replica) use ($config) {
                return [
                    'host' => $replica['host'] ?? $config['host'] ?? 'localhost',
                    'port' => (int) ($replica['port'] ?? $config['port'] ?? 3306),
                    'database' => $replica['database'] ?? $config['database'] ?? '',
                    'username' => $replica['username'] ?? $config['username'] ?? '',
                ];
            }, $readConfig);
        }

        // host가 배열인 경우 (Laravel의 sticky read 방식)
        if (isset($readConfig['host']) && is_array($readConfig['host'])) {
            return array_map(function ($host) use ($readConfig, $config) {
                return [
                    'host' => $host,
                    'port' => (int) ($readConfig['port'] ?? $config['port'] ?? 3306),
                    'database' => $readConfig['database'] ?? $config['database'] ?? '',
                    'username' => $readConfig['username'] ?? $config['username'] ?? '',
                ];
            }, $readConfig['host']);
        }

        return [];
    }

    /**
     * PHP 확장 모듈 상태를 조회합니다.
     *
     * @return array PHP 확장 모듈 상태 배열
     */
    public function getPhpExtensions(): array
    {
        $required = ['openssl', 'pdo', 'mbstring', 'tokenizer', 'xml', 'curl', 'json', 'zip', 'fileinfo', 'bcmath'];
        $optional = ['gd', 'imagick', 'redis', 'memcached', 'sodium', 'exif', 'intl', 'ldap', 'zlib'];

        $extensions = [
            'required' => [],
            'optional' => [],
        ];

        foreach ($required as $ext) {
            $extensions['required'][$ext] = extension_loaded($ext);
        }

        foreach ($optional as $ext) {
            $extensions['optional'][$ext] = extension_loaded($ext);
        }

        return $extensions;
    }
}
