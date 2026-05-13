<?php

namespace App\Http\Requests\Settings;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 드라이버 연결 테스트 요청 검증
 *
 * S3, Redis, Memcached, Websocket 드라이버 연결 테스트에 필요한
 * 설정값을 검증합니다.
 */
class TestDriverConnectionRequest extends FormRequest
{
    /**
     * 지원되는 스토리지 드라이버 목록
     */
    private const SUPPORTED_STORAGE_DRIVERS = ['local', 's3'];

    /**
     * 지원되는 S3 리전 목록
     */
    private const SUPPORTED_S3_REGIONS = ['ap-northeast-2', 'ap-northeast-1', 'us-east-1', 'us-west-2', 'eu-west-1'];

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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            // 드라이버 선택
            'storage_driver' => ['nullable', Rule::in(self::SUPPORTED_STORAGE_DRIVERS)],
            'cache_driver' => ['nullable', Rule::in(self::SUPPORTED_CACHE_DRIVERS)],
            'session_driver' => ['nullable', Rule::in(self::SUPPORTED_SESSION_DRIVERS)],
            'queue_driver' => ['nullable', Rule::in(self::SUPPORTED_QUEUE_DRIVERS)],
            'websocket_enabled' => ['nullable', 'boolean'],

            // S3 설정
            's3_bucket' => ['nullable', 'string', 'max:255'],
            's3_region' => ['nullable', Rule::in(self::SUPPORTED_S3_REGIONS)],
            's3_access_key' => ['nullable', 'string', 'max:255'],
            's3_secret_key' => ['nullable', 'string', 'max:255'],
            's3_url' => ['nullable', 'url', 'max:500'],

            // Redis 설정
            'redis_host' => ['nullable', 'string', 'max:255'],
            'redis_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'redis_password' => ['nullable', 'string', 'max:255'],
            'redis_database' => ['nullable', 'integer', 'min:0', 'max:15'],

            // Memcached 설정
            'memcached_host' => ['nullable', 'string', 'max:255'],
            'memcached_port' => ['nullable', 'integer', 'min:1', 'max:65535'],

            // Websocket 설정
            'websocket_app_key' => ['nullable', 'string', 'max:255'],
            'websocket_host' => ['nullable', 'string', 'max:255'],
            'websocket_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'websocket_scheme' => ['nullable', Rule::in(self::SUPPORTED_WEBSOCKET_SCHEMES)],
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
            's3_region.in' => __('validation.settings.s3_region_invalid'),
            's3_access_key.max' => __('validation.settings.s3_access_key_max'),
            's3_secret_key.max' => __('validation.settings.s3_secret_key_max'),
            's3_url.url' => __('validation.settings.s3_url_invalid'),
            's3_url.max' => __('validation.settings.s3_url_max'),
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
        ];
    }
}
