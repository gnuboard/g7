<?php

namespace App\Http\Requests\Settings;

use App\Extension\HookManager;
use App\Services\DriverRegistryService;
use App\Support\FileServing\FileDelegation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 드라이버 연결 테스트 요청 검증
 *
 * S3, Redis, Memcached, Websocket 드라이버 연결 테스트와 웹서버 파일 위임 판정에 필요한
 * 설정값을 검증합니다.
 */
class TestDriverConnectionRequest extends FormRequest
{
    /**
     * 지원되는 스토리지 드라이버 목록
     */
    private const SUPPORTED_STORAGE_DRIVERS = ['local', 's3'];

    /**
     * S3 리전 형식 (AWS 리전 코드 + S3 호환 스토리지 임의값 허용 — R2 의 `auto` 등)
     */
    private const S3_REGION_FORMAT = 'regex:/^[a-z0-9-]+$/';

    /**
     * 지원되는 캐시 드라이버 목록
     */
    private const SUPPORTED_CACHE_DRIVERS = ['file', 'redis', 'memcached'];

    /**
     * 지원되는 세션 드라이버 목록
     */
    private const SUPPORTED_SESSION_DRIVERS = ['file', 'database', 'redis'];

    /**
     * 지원되는 큐 드라이버 목록
     */
    private const SUPPORTED_QUEUE_DRIVERS = ['sync', 'database', 'redis'];

    /**
     * 지원되는 웹소켓 프로토콜 목록
     */
    private const SUPPORTED_WEBSOCKET_SCHEMES = ['http', 'https'];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전에 웹서버 파일 위임 접두사를 저장 게이트와 같은 모양으로 다듬습니다 (#878).
     *
     * 화면의 「설정 점검」은 저장하기 전 입력값을 보낸다 — 저장(`SaveSettingsRequest`)은 끝 `/` 가 없는
     * `/_g7_sendfile` 을 `/_g7_sendfile/` 로 고쳐 통과시키므로, 점검도 같은 값을 같은 결과로 판정해야 한다.
     *
     * @return void 입력을 제자리에서 고친다
     */
    protected function prepareForValidation(): void
    {
        $prefix = $this->input('file_delegation_accel_prefix');

        if (! is_string($prefix)) {
            return;
        }

        $this->merge(['file_delegation_accel_prefix' => FileDelegation::normalizeAccelPrefix($prefix)]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            // 드라이버 선택
            'storage_driver' => ['nullable', Rule::in(self::SUPPORTED_STORAGE_DRIVERS), $this->getDriverUsabilityRule('storage')],
            'cache_driver' => ['nullable', Rule::in(self::SUPPORTED_CACHE_DRIVERS), $this->getDriverUsabilityRule('cache')],
            'session_driver' => ['nullable', Rule::in(self::SUPPORTED_SESSION_DRIVERS), $this->getDriverUsabilityRule('session')],
            'queue_driver' => ['nullable', Rule::in(self::SUPPORTED_QUEUE_DRIVERS), $this->getDriverUsabilityRule('queue')],
            'websocket_enabled' => ['nullable', 'boolean'],

            // S3 설정
            's3_bucket' => ['nullable', 'string', 'max:255'],
            's3_region' => ['nullable', 'string', 'max:64', self::S3_REGION_FORMAT],
            's3_access_key' => ['nullable', 'string', 'max:255'],
            's3_secret_key' => ['nullable', 'string', 'max:255'],
            's3_url' => ['nullable', 'url', 'max:500'],
            's3_endpoint' => ['nullable', 'url', 'max:500'],
            's3_use_path_style' => ['nullable', 'boolean'],

            // Redis 설정
            'redis_host' => ['nullable', 'string', 'max:255'],
            'redis_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'redis_password' => ['nullable', 'string', 'max:255'],
            'redis_database' => ['nullable', 'integer', 'min:0', 'max:15'],

            // Memcached 설정
            'memcached_host' => ['nullable', 'string', 'max:255'],
            'memcached_port' => ['nullable', 'integer', 'min:1', 'max:65535'],

            // Websocket 설정 — client(브라우저 접속) / server(백엔드 broadcast HTTP API) endpoint 분리
            'websocket_app_key' => ['nullable', 'string', 'max:255'],
            'websocket_host' => ['nullable', 'string', 'max:255'],
            'websocket_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'websocket_scheme' => ['nullable', Rule::in(self::SUPPORTED_WEBSOCKET_SCHEMES)],
            'websocket_server_host' => ['nullable', 'string', 'max:255'],
            'websocket_server_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'websocket_server_scheme' => ['nullable', Rule::in(self::SUPPORTED_WEBSOCKET_SCHEMES)],

            // 웹서버 파일 위임 (#878) — 저장 게이트와 같은 선택지·접두사 형식
            'file_delegation' => ['nullable', Rule::in(FileDelegation::modes())],
            'file_delegation_accel_prefix' => ['nullable', 'string', 'max:255', 'regex:'.FileDelegation::ACCEL_PREFIX_PATTERN],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.settings.test_driver_connection_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'storage_driver.in' => __('validation.settings.storage_driver_invalid'),
            's3_bucket.max' => __('validation.settings.s3_bucket_max'),
            's3_region.regex' => __('validation.settings.s3_region_invalid'),
            's3_region.max' => __('validation.settings.s3_region_max'),
            's3_access_key.max' => __('validation.settings.s3_access_key_max'),
            's3_secret_key.max' => __('validation.settings.s3_secret_key_max'),
            's3_url.url' => __('validation.settings.s3_url_invalid'),
            's3_url.max' => __('validation.settings.s3_url_max'),
            's3_endpoint.url' => __('validation.settings.s3_endpoint_invalid'),
            's3_endpoint.max' => __('validation.settings.s3_endpoint_max'),
            's3_use_path_style.boolean' => __('validation.settings.s3_use_path_style_boolean'),
            'cache_driver.in' => __('validation.settings.cache_driver_invalid'),
            'redis_host.max' => __('validation.settings.redis_host_max'),
            'redis_port.integer' => __('validation.settings.redis_port_integer'),
            'redis_port.min' => __('validation.settings.redis_port_min'),
            'redis_port.max' => __('validation.settings.redis_port_max'),
            'redis_password.max' => __('validation.settings.redis_password_max'),
            'redis_database.integer' => __('validation.settings.redis_database_integer'),
            'redis_database.min' => __('validation.settings.redis_database_min'),
            'redis_database.max' => __('validation.settings.redis_database_max'),
            'memcached_host.max' => __('validation.settings.memcached_host_max'),
            'memcached_port.integer' => __('validation.settings.memcached_port_integer'),
            'memcached_port.min' => __('validation.settings.memcached_port_min'),
            'memcached_port.max' => __('validation.settings.memcached_port_max'),
            'session_driver.in' => __('validation.settings.session_driver_invalid'),
            'queue_driver.in' => __('validation.settings.queue_driver_invalid'),
            'websocket_enabled.boolean' => __('validation.settings.websocket_enabled_boolean'),
            'websocket_app_key.max' => __('validation.settings.websocket_app_key_max'),
            'websocket_host.max' => __('validation.settings.websocket_host_max'),
            'websocket_port.integer' => __('validation.settings.websocket_port_integer'),
            'websocket_port.min' => __('validation.settings.websocket_port_min'),
            'websocket_port.max' => __('validation.settings.websocket_port_max'),
            'websocket_scheme.in' => __('validation.settings.websocket_scheme_invalid'),
            'websocket_server_host.max' => __('validation.settings.websocket_server_host_max'),
            'websocket_server_port.integer' => __('validation.settings.websocket_server_port_integer'),
            'websocket_server_port.min' => __('validation.settings.websocket_server_port_min'),
            'websocket_server_port.max' => __('validation.settings.websocket_server_port_max'),
            'websocket_server_scheme.in' => __('validation.settings.websocket_server_scheme_invalid'),
            'file_delegation.in' => __('validation.settings.file_delegation_invalid'),
            'file_delegation_accel_prefix.string' => __('validation.settings.file_delegation_accel_prefix_format'),
            'file_delegation_accel_prefix.max' => __('validation.settings.file_delegation_accel_prefix_format'),
            'file_delegation_accel_prefix.regex' => __('validation.settings.file_delegation_accel_prefix_format'),
        ];
    }

    /**
     * 드라이버 가용성 검증 rule 을 반환합니다.
     *
     * 저장 게이트(SaveSettingsRequest)와 동일 판정 — 사용 불능 드라이버는 테스트 요청
     * 단계에서도 422 로 차단해 어느 경로로도 통과하지 못하게 합니다.
     *
     * @param  string  $category  드라이버 카테고리 (storage, cache, session, queue)
     * @return \Closure 검증 클로저
     */
    private function getDriverUsabilityRule(string $category): \Closure
    {
        return function ($attribute, $value, $fail) use ($category) {
            if (empty($value)) {
                return;
            }

            $registry = app(DriverRegistryService::class);

            if (! $registry->isDriverUsable($category, $value)) {
                $fail(__('validation.settings.driver_unusable', [
                    'driver' => $value,
                    'reason' => $registry->usabilityFailureReason($category, $value),
                ]));
            }
        };
    }
}
