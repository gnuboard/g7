<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 드라이버 연결 테스트 서비스
 *
 * S3, Redis, Memcached, Websocket 등 외부 서비스 드라이버의
 * 연결 상태를 테스트합니다.
 */
class DriverConnectionTester
{
    /**
     * 설정에 따라 필요한 모든 드라이버 연결을 테스트합니다.
     *
     * @param  array  $settings  드라이버 설정 배열
     * @return array 테스트 결과 배열
     */
    public function testAll(array $settings): array
    {
        $results = [];
        $allPassed = true;

        // S3 테스트
        if (($settings['storage_driver'] ?? 'local') === 's3') {
            $results['s3'] = $this->testS3($settings);
            if (! $results['s3']['success']) {
                $allPassed = false;
            }
        }

        // Redis 테스트 (캐시, 세션, 큐 중 하나라도 Redis 사용 시)
        $needsRedis = ($settings['cache_driver'] ?? 'file') === 'redis'
            || ($settings['session_driver'] ?? 'file') === 'redis'
            || ($settings['queue_driver'] ?? 'sync') === 'redis';

        if ($needsRedis) {
            $results['redis'] = $this->testRedis($settings);
            if (! $results['redis']['success']) {
                $allPassed = false;
            }
        }

        // Memcached 테스트
        if (($settings['cache_driver'] ?? 'file') === 'memcached') {
            $results['memcached'] = $this->testMemcached($settings);
            if (! $results['memcached']['success']) {
                $allPassed = false;
            }
        }

        // Websocket 테스트
        if ($settings['websocket_enabled'] ?? false) {
            $results['websocket'] = $this->testWebsocket($settings);
            if (! $results['websocket']['success']) {
                $allPassed = false;
            }
        }

        return [
            'results' => $results,
            'all_passed' => $allPassed,
        ];
    }

    /**
     * S3 버킷 연결을 테스트합니다.
     *
     * @param  array  $config  S3 설정 배열
     * @return array 테스트 결과
     */
    public function testS3(array $config): array
    {
        try {
            // 필수 설정 확인
            $bucket = $config['s3_bucket'] ?? '';
            $region = $config['s3_region'] ?? '';
            $accessKey = $config['s3_access_key'] ?? '';
            $secretKey = $config['s3_secret_key'] ?? '';

            if (empty($bucket) || empty($region) || empty($accessKey) || empty($secretKey)) {
                return [
                    'success' => false,
                    'message' => __('settings.s3_missing_config'),
                ];
            }

            // AWS SDK가 설치되어 있는지 확인
            if (! class_exists(\Aws\S3\S3Client::class)) {
                return [
                    'success' => false,
                    'message' => __('settings.s3_sdk_missing'),
                ];
            }

            $startTime = microtime(true);

            $client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $region,
                'credentials' => [
                    'key' => $accessKey,
                    'secret' => $secretKey,
                ],
                'http' => [
                    'timeout' => 5,
                    'connect_timeout' => 3,
                ],
            ]);

            // 버킷 존재 확인
            $client->headBucket(['Bucket' => $bucket]);

            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success' => true,
                'message' => __('settings.s3_test_success'),
                'latency' => $latency.'ms',
            ];
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $errorMessage = match ($e->getAwsErrorCode()) {
                'NoSuchBucket' => __('settings.s3_bucket_not_found'),
                'AccessDenied' => __('settings.s3_access_denied'),
                'InvalidAccessKeyId' => __('settings.s3_invalid_credentials'),
                'SignatureDoesNotMatch' => __('settings.s3_invalid_credentials'),
                default => __('settings.s3_test_failed'),
            };

            return [
                'success' => false,
                'message' => $errorMessage,
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::warning('S3 connection test failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => __('settings.s3_test_failed'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Redis 서버 연결을 테스트합니다.
     *
     * @param  array  $config  Redis 설정 배열
     * @return array 테스트 결과
     */
    public function testRedis(array $config): array
    {
        try {
            $host = $config['redis_host'] ?? '127.0.0.1';
            $port = (int) ($config['redis_port'] ?? 6379);
            $password = $config['redis_password'] ?? '';
            $database = (int) ($config['redis_database'] ?? 0);

            // PHP Redis 확장 확인
            if (! extension_loaded('redis')) {
                // Predis 사용 시도
                if (! class_exists(\Predis\Client::class)) {
                    return [
                        'success' => false,
                        'message' => __('settings.redis_extension_missing'),
                    ];
                }

                return $this->testRedisWithPredis($host, $port, $password, $database);
            }

            $startTime = microtime(true);

            $redis = new \Redis();
            $connected = @$redis->connect($host, $port, 3.0);

            if (! $connected) {
                return [
                    'success' => false,
                    'message' => __('settings.redis_connection_failed'),
                ];
            }

            if (! empty($password)) {
                if (! $redis->auth($password)) {
                    return [
                        'success' => false,
                        'message' => __('settings.redis_auth_failed'),
                    ];
                }
            }

            $redis->select($database);
            $pong = $redis->ping();

            if ($pong !== true && $pong !== '+PONG') {
                return [
                    'success' => false,
                    'message' => __('settings.redis_ping_failed'),
                ];
            }

            $latency = round((microtime(true) - $startTime) * 1000, 2);
            $redis->close();

            return [
                'success' => true,
                'message' => __('settings.redis_test_success'),
                'latency' => $latency.'ms',
            ];
        } catch (\RedisException $e) {
            return [
                'success' => false,
                'message' => __('settings.redis_test_failed'),
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::warning('Redis connection test failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => __('settings.redis_test_failed'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Predis 라이브러리를 사용하여 Redis 연결을 테스트합니다.
     *
     * @param  string  $host  호스트
     * @param  int  $port  포트
     * @param  string  $password  비밀번호
     * @param  int  $database  데이터베이스 번호
     * @return array 테스트 결과
     */
    private function testRedisWithPredis(string $host, int $port, string $password, int $database): array
    {
        try {
            $startTime = microtime(true);

            $options = [
                'scheme' => 'tcp',
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'timeout' => 3.0,
            ];

            if (! empty($password)) {
                $options['password'] = $password;
            }

            $client = new \Predis\Client($options);
            $pong = $client->ping();

            if ($pong->getPayload() !== 'PONG') {
                return [
                    'success' => false,
                    'message' => __('settings.redis_ping_failed'),
                ];
            }

            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success' => true,
                'message' => __('settings.redis_test_success'),
                'latency' => $latency.'ms',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('settings.redis_test_failed'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Memcached 서버 연결을 테스트합니다.
     *
     * @param  array  $config  Memcached 설정 배열
     * @return array 테스트 결과
     */
    public function testMemcached(array $config): array
    {
        try {
            // Memcached 확장 확인
            if (! extension_loaded('memcached')) {
                return [
                    'success' => false,
                    'message' => __('settings.memcached_extension_missing'),
                ];
            }

            $host = $config['memcached_host'] ?? '127.0.0.1';
            $port = (int) ($config['memcached_port'] ?? 11211);

            $startTime = microtime(true);

            $memcached = new \Memcached();
            $memcached->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 3000);
            $memcached->addServer($host, $port);

            // 테스트 키 설정/조회
            $testKey = 'g7_connection_test_'.time();
            $testValue = 'test_'.uniqid();

            $setResult = $memcached->set($testKey, $testValue, 10);

            if (! $setResult) {
                $resultCode = $memcached->getResultCode();

                return [
                    'success' => false,
                    'message' => __('settings.memcached_connection_failed'),
                    'error' => "Result code: {$resultCode}",
                ];
            }

            $getValue = $memcached->get($testKey);

            if ($getValue !== $testValue) {
                return [
                    'success' => false,
                    'message' => __('settings.memcached_test_failed'),
                    'error' => 'Set/Get verification failed',
                ];
            }

            // 테스트 키 삭제
            $memcached->delete($testKey);

            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success' => true,
                'message' => __('settings.memcached_test_success'),
                'latency' => $latency.'ms',
            ];
        } catch (\Exception $e) {
            Log::warning('Memcached connection test failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => __('settings.memcached_test_failed'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Websocket (Reverb) 서버 연결을 테스트합니다.
     *
     * @param  array  $config  Websocket 설정 배열
     * @return array 테스트 결과
     */
    public function testWebsocket(array $config): array
    {
        try {
            $host = $config['websocket_host'] ?? 'localhost';
            $port = (int) ($config['websocket_port'] ?? 8080);
            $scheme = $config['websocket_scheme'] ?? 'https';

            // Reverb 서버 상태 확인 (기본 HTTP 엔드포인트)
            $url = sprintf('%s://%s:%d', $scheme, $host, $port);

            $startTime = microtime(true);

            // HTTP 요청으로 서버 응답 확인
            $response = Http::timeout(5)
                ->withOptions([
                    'verify' => false, // 로컬 개발 환경에서 SSL 인증서 검증 무시
                ])
                ->get($url);

            $latency = round((microtime(true) - $startTime) * 1000, 2);

            // Reverb는 루트 경로에서 200 또는 다른 응답을 반환
            // 연결 자체가 성공하면 서버가 실행 중인 것으로 간주
            if ($response->successful() || $response->status() < 500) {
                return [
                    'success' => true,
                    'message' => __('settings.websocket_test_success'),
                    'latency' => $latency.'ms',
                ];
            }

            return [
                'success' => false,
                'message' => __('settings.websocket_test_failed'),
                'error' => "HTTP {$response->status()}",
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'success' => false,
                'message' => __('settings.websocket_connection_refused'),
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::warning('Websocket connection test failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => __('settings.websocket_test_failed'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 특정 드라이버만 테스트합니다.
     *
     * @param  string  $driver  드라이버 타입 (s3, redis, memcached, websocket)
     * @param  array  $config  설정 배열
     * @return array 테스트 결과
     */
    public function testDriver(string $driver, array $config): array
    {
        return match ($driver) {
            's3' => $this->testS3($config),
            'redis' => $this->testRedis($config),
            'memcached' => $this->testMemcached($config),
            'websocket' => $this->testWebsocket($config),
            default => [
                'success' => false,
                'message' => __('settings.unknown_driver'),
            ],
        };
    }
}
