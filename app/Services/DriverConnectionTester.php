<?php

namespace App\Services;

use App\Rules\ConnectionHost;
use App\Support\FileServing\FileDelegation;
use App\Support\OutboundUrlValidator;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Predis\Client;

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

        // 웹서버 파일 위임 — 요청에 방식이 실렸을 때만 판정한다(다른 카드의 시험 버튼은 이 키를 보내지 않는다)
        if (($settings['file_delegation'] ?? null) !== null && $settings['file_delegation'] !== '') {
            $results['file_delegation'] = $this->testFileDelegation($settings);
            if (! $results['file_delegation']['success']) {
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
            // 연결 대상 주소를 먼저 본다 — 요청 검증을 거치지 않은 호출(저장값·내부 호출)도 같은 판정을 받는다.
            foreach (['s3_endpoint', 's3_url'] as $urlKey) {
                if (! empty($config[$urlKey]) && OutboundUrlValidator::isRefusedConnectionUrl((string) $config[$urlKey])) {
                    return $this->blockedTarget('settings.s3_test_failed');
                }
            }

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
            if (! class_exists(S3Client::class)) {
                return [
                    'success' => false,
                    'message' => __('settings.s3_sdk_missing'),
                ];
            }

            // Flysystem S3 어댑터 존재 선검사 — 테스트는 SDK 직접 경로지만 실제 파일 I/O 는
            // Storage::disk('s3') → 어댑터를 경유하므로, 어댑터가 없으면 테스트가 성공해도
            // 실경로가 즉사한다 (#99 의 결함 은폐 구조). sdk_missing 검사와 대칭.
            if (! class_exists(AwsS3V3Adapter::class)) {
                return [
                    'success' => false,
                    'message' => __('settings.s3_adapter_missing'),
                ];
            }

            $startTime = microtime(true);

            $client = new S3Client($this->s3ClientOptions($config));

            // 버킷 존재 확인
            $client->headBucket(['Bucket' => $bucket]);

            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success' => true,
                'message' => __('settings.s3_test_success'),
                'latency' => $latency.'ms',
            ];
        } catch (S3Exception $e) {
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
     * S3 연결 테스트에 쓰는 클라이언트 옵션을 만듭니다.
     *
     * 응답의 리다이렉트를 따라가지 않는다 — 관리자가 넣은 주소가 다른 주소(내부망·메타데이터)로 넘기는 응답을
     * 돌려줘도 서버는 그 주소에 닿지 않는다.
     *
     * @param  array  $config  S3 설정 (s3_region, s3_access_key, s3_secret_key, s3_endpoint, s3_use_path_style)
     * @return array<string, mixed> S3Client 생성 옵션
     */
    protected function s3ClientOptions(array $config): array
    {
        $clientOptions = [
            'version' => 'latest',
            'region' => $config['s3_region'] ?? '',
            'credentials' => [
                'key' => $config['s3_access_key'] ?? '',
                'secret' => $config['s3_secret_key'] ?? '',
            ],
            'http' => [
                'timeout' => 5,
                'connect_timeout' => 3,
                // 응답의 리다이렉트로 다른 주소에 닿지 않게 한다.
                'allow_redirects' => false,
            ],
        ];

        // S3 호환 스토리지(R2/MinIO 등) — 실경로(applyS3Config 주입)와 동일하게
        // endpoint/path-style 을 반영해야 테스트가 실제 연결 대상과 일치한다.
        if (! empty($config['s3_endpoint'])) {
            $clientOptions['endpoint'] = $config['s3_endpoint'];
        }
        if (! empty($config['s3_use_path_style'])) {
            $clientOptions['use_path_style_endpoint'] = true;
        }

        return $clientOptions;
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

            if (ConnectionHost::violation((string) $host, true) !== null) {
                return $this->blockedTarget('settings.redis_test_failed', (string) $host, true);
            }

            $auth = $this->resolveRedisAuth($config);
            $database = (int) ($config['redis_database'] ?? 0);

            // PHP Redis 확장 확인
            if (! extension_loaded('redis')) {
                // Predis 사용 시도
                if (! class_exists(Client::class)) {
                    return [
                        'success' => false,
                        'message' => __('settings.redis_extension_missing'),
                    ];
                }

                return $this->testRedisWithPredis($host, $port, $auth, $database);
            }

            $startTime = microtime(true);

            $redis = new \Redis;
            $connected = @$redis->connect($host, $port, 3.0);

            if (! $connected) {
                return [
                    'success' => false,
                    'message' => __('settings.redis_connection_failed'),
                ];
            }

            if ($auth !== null) {
                if (! $redis->auth($auth)) {
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
     * Redis 연결 테스트에 쓸 인증 자격을 정합니다.
     *
     * 사이트별 ACL 사용자 이름(REDIS_USERNAME)은 설정 화면에 없고, 운영자가 비밀번호 칸을 비워 시험할 수도 있다.
     * 그래서 시험 대상이 지금 설정된 연결(host·port)과 같을 때만 설정의 사용자 이름을 쓰고 빈 비밀번호를 설정값으로 채운다.
     * 다른 서버를 시험할 때는 입력한 비밀번호만 쓴다.
     *
     * @param  array  $config  드라이버 설정 배열 (redis_host·redis_port·redis_password)
     * @return string|array|null 비밀번호 문자열, [사용자 이름, 비밀번호], 인증 없음이면 null
     */
    public function resolveRedisAuth(array $config): string|array|null
    {
        $host = (string) ($config['redis_host'] ?? '127.0.0.1');
        $port = (int) ($config['redis_port'] ?? 6379);
        $password = (string) ($config['redis_password'] ?? '');

        $sameEndpoint = $host === (string) config('database.redis.default.host')
            && $port === (int) config('database.redis.default.port');

        $username = $sameEndpoint ? (string) config('database.redis.default.username') : '';
        if ($password === '' && $sameEndpoint) {
            $password = (string) config('database.redis.default.password');
        }

        if ($password === '') {
            return null;
        }

        return $username !== '' ? [$username, $password] : $password;
    }

    /**
     * Predis 라이브러리를 사용하여 Redis 연결을 테스트합니다.
     *
     * @param  string  $host  호스트
     * @param  int  $port  포트
     * @param  string|array|null  $auth  비밀번호 또는 [사용자 이름, 비밀번호] (resolveRedisAuth)
     * @param  int  $database  데이터베이스 번호
     * @return array 테스트 결과
     */
    private function testRedisWithPredis(string $host, int $port, string|array|null $auth, int $database): array
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

            if (is_array($auth)) {
                [$options['username'], $options['password']] = $auth;
            } elseif ($auth !== null) {
                $options['password'] = $auth;
            }

            $client = new Client($options);
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
            // 연결 대상 주소를 먼저 본다 (확장 유무와 무관하게 같은 답을 낸다).
            $targetHost = (string) ($config['memcached_host'] ?? '127.0.0.1');
            if (ConnectionHost::violation($targetHost, true) !== null) {
                return $this->blockedTarget('settings.memcached_test_failed', $targetHost, true);
            }

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

            $memcached = new \Memcached;
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
        $clientHost = $config['websocket_host'] ?? 'localhost';
        $clientPort = (int) ($config['websocket_port'] ?? 8080);
        $clientScheme = $config['websocket_scheme'] ?? 'https';

        // 서버(백엔드 broadcast HTTP API) endpoint — 빈 값은 클라이언트 값으로 폴백한다
        // (SettingsServiceProvider::applyWebsocketConfig 와 동일 규칙). 백엔드 송신은
        // server endpoint 를 쓰므로 client 만 검사하면 "테스트 성공 + 실제 발송 실패" 가 된다.
        $serverHost = ($config['websocket_server_host'] ?? '') !== '' ? $config['websocket_server_host'] : $clientHost;
        $serverPort = (int) ($config['websocket_server_port'] ?? 0) > 0 ? (int) $config['websocket_server_port'] : $clientPort;
        $serverScheme = ($config['websocket_server_scheme'] ?? '') !== '' ? $config['websocket_server_scheme'] : $clientScheme;

        // 호스트 칸에는 호스트명·IP 만 온다 — 경로·쿼리가 섞이면 아래에서 조립한 주소가 화면에 보인 것과
        // 다른 곳을 가리키고(포트가 쿼리로 밀려난다), 링크로컬·메타데이터 주소는 어떤 정상 구성에도 없다.
        foreach ([$clientHost, $serverHost] as $host) {
            if (ConnectionHost::violation((string) $host) !== null) {
                return $this->blockedTarget('settings.websocket_test_failed', (string) $host);
            }
        }

        $clientUrl = sprintf('%s://%s:%d', $clientScheme, $clientHost, $clientPort);
        $serverUrl = sprintf('%s://%s:%d', $serverScheme, $serverHost, $serverPort);

        // 클라이언트 endpoint 검사
        $clientResult = $this->probeWebsocketEndpoint($clientUrl);

        if (! $clientResult['success']) {
            return $clientResult;
        }

        // 서버 endpoint 검사 (클라이언트와 동일 주소면 중복 요청 생략)
        if ($serverUrl !== $clientUrl) {
            $serverResult = $this->probeWebsocketEndpoint($serverUrl);

            if (! $serverResult['success']) {
                return [
                    'success' => false,
                    'message' => __('settings.websocket_server_test_failed'),
                    'error' => $serverResult['error'] ?? ($serverResult['message'] ?? ''),
                ];
            }
        }

        return $clientResult;
    }

    /**
     * Websocket(Reverb) HTTP endpoint 하나를 probe 합니다.
     *
     * @param  string  $url  검사할 endpoint URL
     * @return array 테스트 결과
     */
    private function probeWebsocketEndpoint(string $url): array
    {
        try {
            // Reverb 는 통상 localhost·사내 IP 에서 동작하므로 사설 주소 자체는 정상 구성이다.
            // 다만 이 진단 요청을 임의 목적지 탐색에 전용하지 못하도록, 정상 설정에서는 나올 수
            // 없는 값(userinfo 위장, http/https 이외 scheme, 제어문자 주입)은 거부한다.
            if (! OutboundUrlValidator::isStructurallySafeUrl($url, ['schemes' => ['http', 'https']])) {
                return [
                    'success' => false,
                    'message' => __('settings.websocket_test_failed'),
                    'error' => __('settings.websocket_invalid_host'),
                ];
            }

            $startTime = microtime(true);

            // HTTP 요청으로 서버 응답 확인. 리다이렉트는 따라가지 않는다 — 응답이 가리키는 다른 주소에
            // 서버가 대신 닿지 않게 한다(3xx 도 "서버가 응답했다" 로 본다).
            $response = Http::timeout(5)
                ->withoutRedirecting()
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
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'message' => __('settings.websocket_connection_refused'),
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::warning('Websocket connection test failed', [
                'url' => $url,
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
     * 웹서버 파일 위임 구성을 정적으로 판정합니다 (1단계).
     *
     * PHP 안에서는 웹서버가 위임 헤더를 실제로 소비하는지 확정할 수 없다. 여기서는 확정 가능한 실패만 실패로 내고
     * (Apache 인데 mod_xsendfile 이 없다 · 고른 방식과 웹서버 종류가 어긋난다), 나머지는 「저장한 뒤 실제 전송 확인
     * (2단계 프로브)」을 안내하는 성공으로 낸다. 서버로 요청을 되돌려 보내는(self-request) 판정은 아웃바운드 정책·공유
     * 호스팅 제약 때문에 쓰지 않는다.
     *
     * 결과의 `details` 는 화면이 웹서버 설정 안내를 채우는 데 쓴다 — `server`(apache|nginx|unknown)·`module`
     * (true|false|null — Apache 모듈 목록을 읽을 수 있을 때만 불리언)·`base_path`·`accel_prefix`·`accel_mapping`.
     *
     * @param  array  $config  시험 입력 (`file_delegation`, `file_delegation_accel_prefix`)
     * @param  string|null  $serverSoftware  웹서버 식별 문자열 (null 이면 현재 요청의 SERVER_SOFTWARE)
     * @param  array<int, string>|null  $apacheModules  Apache 모듈 목록 (null 이면 apache_get_modules() 가 있을 때 그 값)
     * @return array{success: bool, message: string, details: array<string, mixed>} 시험 결과
     */
    public function testFileDelegation(array $config, ?string $serverSoftware = null, ?array $apacheModules = null): array
    {
        $mode = strtolower(trim((string) ($config['file_delegation'] ?? FileDelegation::MODE_NONE)));
        $mode = in_array($mode, FileDelegation::modes(), true) ? $mode : FileDelegation::MODE_NONE;

        $prefix = FileDelegation::normalizeAccelPrefix((string) ($config['file_delegation_accel_prefix'] ?? ''));
        $prefix = $prefix === '' ? FileDelegation::accelPrefix() : $prefix;
        $basePath = FileDelegation::basePath();

        $serverSoftware ??= (string) request()->server('SERVER_SOFTWARE', '');
        $server = match (true) {
            stripos($serverSoftware, 'nginx') !== false => 'nginx',
            stripos($serverSoftware, 'apache') !== false => 'apache',
            default => 'unknown',
        };

        if ($apacheModules === null && function_exists('apache_get_modules')) {
            $apacheModules = apache_get_modules();
        }

        // 모듈 목록이 있으면 Apache 모듈로 도는 PHP 다 — SERVER_SOFTWARE 가 비어 있어도 Apache 로 본다
        if ($apacheModules !== null && $server === 'unknown') {
            $server = 'apache';
        }

        $module = $apacheModules === null ? null : in_array('mod_xsendfile', $apacheModules, true);

        $details = [
            'mode' => $mode,
            'server' => $server,
            'module' => $module,
            'base_path' => $basePath,
            'accel_prefix' => $prefix,
            'accel_mapping' => FileDelegation::accelMapping($prefix),
        ];

        [$success, $messageKey] = match (true) {
            $mode === FileDelegation::MODE_NONE => [true, 'none'],
            $mode === FileDelegation::MODE_X_SENDFILE && $server === 'nginx' => [false, 'server_mismatch_nginx'],
            $mode === FileDelegation::MODE_X_ACCEL_REDIRECT && $server === 'apache' => [false, 'server_mismatch_apache'],
            $mode === FileDelegation::MODE_X_SENDFILE && $module === false => [false, 'apache_missing'],
            $mode === FileDelegation::MODE_X_SENDFILE && $module === true => [true, 'apache_ready'],
            $mode === FileDelegation::MODE_X_SENDFILE && $server === 'apache' => [true, 'apache_unknown'],
            $mode === FileDelegation::MODE_X_ACCEL_REDIRECT && $server === 'nginx' => [true, 'nginx_hint'],
            default => [true, 'server_unknown'],
        };

        return [
            'success' => $success,
            'message' => __('settings.driver_test.file_delegation.'.$messageKey),
            'details' => $details,
        ];
    }

    /**
     * 연결 대상이 허용되지 않아 테스트를 시작하지 않았다는 결과를 만듭니다.
     *
     * 형식이 틀린 호스트(경로·쿼리·계정 정보)와 링크로컬·메타데이터 주소를 구분해 알린다.
     * 연결을 시도한 뒤의 실패 사유(연결 거부·시간 초과 등)는 이 경로를 거치지 않고 원문 그대로 나간다.
     *
     * @param  string  $messageKey  그 드라이버의 실패 문구 키
     * @param  string|null  $host  판정한 호스트 칸 값 (URL 판정이면 null)
     * @param  bool  $allowSocketAndTls  소켓 경로·TLS 접두 허용 칸인지
     * @return array{success: bool, message: string, error: string} 테스트 결과
     */
    private function blockedTarget(string $messageKey, ?string $host = null, bool $allowSocketAndTls = false): array
    {
        $formatOnly = $host !== null && ConnectionHost::violation($host, $allowSocketAndTls) === 'format';

        return [
            'success' => false,
            'message' => __($messageKey),
            'error' => $formatOnly
                ? __('validation.settings.host_format_invalid')
                : __('settings.connection_target_blocked'),
        ];
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
            'file_delegation' => $this->testFileDelegation($config),
            default => [
                'success' => false,
                'message' => __('settings.unknown_driver'),
            ],
        };
    }
}
