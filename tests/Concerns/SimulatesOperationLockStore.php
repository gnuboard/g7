<?php

namespace Tests\Concerns;

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * 작업 잠금 저장소의 장애·연장을 흉내 내는 시험 도구 (#161)
 *
 * - {@see breakLockStore()}: 기본 캐시 스토어를 「잠금을 잡으려 하면 저장소 오류」로 바꾼다(값 읽기·쓰기는 정상). 오류 문구에
 *   서버 경로 모양(`C:\fake\g7`)과 표지(`i161`)를 넣어, 응답·화면에 원문이 새는지 시험이 가려낼 수 있게 한다.
 * - {@see useSpyLockStore()}: 기본 캐시 스토어를 배열 스토어로 두되 잠금 연장(refresh) 횟수를 센다. `$refreshUnsupported` 면
 *   연장을 지원하지 않는 잠금처럼 RuntimeException 을 던진다.
 *
 * 쓰는 시험은 tearDown 에서 {@see restoreOperationLockStore()} 를 부른다.
 */
trait SimulatesOperationLockStore
{
    /**
     * 연장(refresh) 호출 횟수.
     */
    public static int $i161Refreshes = 0;

    /**
     * 연장을 지원하지 않는 잠금으로 흉내 낼지.
     */
    public static bool $i161RefreshUnsupported = false;

    /**
     * 바꾸기 전 기본 캐시 스토어 이름.
     */
    private ?string $i161PreviousCacheDefault = null;

    /**
     * 기본 캐시 스토어를 잠금 오류 스토어로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function breakLockStore(): void
    {
        $this->i161PreviousCacheDefault ??= (string) config('cache.default');

        config([
            'cache.stores.i161_broken_lock' => ['driver' => 'i161_broken_lock'],
            'cache.default' => 'i161_broken_lock',
        ]);

        Cache::extend('i161_broken_lock', fn () => Cache::repository(new class extends ArrayStore
        {
            /**
             * 잡으려 하면 저장소 오류가 나는 잠금을 돌려준다.
             *
             * @param  string  $name  잠금 이름
             * @param  int  $seconds  유지 시간(초)
             * @param  string|null  $owner  소유자
             * @return Lock 오류 잠금
             */
            public function lock($name, $seconds = 0, $owner = null)
            {
                return new class($name, $seconds, $owner) extends Lock
                {
                    /**
                     * 잠금 저장소에 닿지 못한다.
                     *
                     * @return bool 반환하지 않음
                     *
                     * @throws \RuntimeException 언제나
                     */
                    public function acquire()
                    {
                        throw new \RuntimeException('i161 lock store down C:\\fake\\g7\\storage\\framework/cache/locks/ab/cd');
                    }

                    /**
                     * 잠금을 푼다(잡은 적이 없다).
                     *
                     * @return bool 언제나 false
                     */
                    public function release()
                    {
                        return false;
                    }

                    /**
                     * 강제로 푼다(잡은 적이 없다).
                     *
                     * @return void 반환값 없음
                     */
                    public function forceRelease()
                    {
                        // 잡힌 적이 없어 할 일이 없다
                    }

                    /**
                     * 현재 소유자(없음).
                     *
                     * @return string 빈 문자열
                     */
                    protected function getCurrentOwner()
                    {
                        return '';
                    }
                };
            }
        }));

        Cache::forgetDriver('i161_broken_lock');
    }

    /**
     * 기본 캐시 스토어를 연장 횟수를 세는 배열 스토어로 바꿉니다.
     *
     * @param  bool  $refreshUnsupported  연장을 지원하지 않는 잠금으로 흉내 낼지
     * @return void 반환값 없음
     */
    protected function useSpyLockStore(bool $refreshUnsupported = false): void
    {
        $this->i161PreviousCacheDefault ??= (string) config('cache.default');
        self::$i161Refreshes = 0;
        self::$i161RefreshUnsupported = $refreshUnsupported;

        config([
            'cache.stores.i161_spy_lock' => ['driver' => 'i161_spy_lock'],
            'cache.default' => 'i161_spy_lock',
        ]);

        $owner = static::class;
        Cache::extend('i161_spy_lock', fn () => Cache::repository(new class($owner) extends ArrayStore
        {
            /**
             * 연장 횟수를 기록할 시험 클래스를 받습니다.
             *
             * @param  class-string  $testClass  시험 클래스
             */
            public function __construct(private string $testClass)
            {
                parent::__construct();
            }

            /**
             * 연장을 세는 잠금을 돌려준다.
             *
             * @param  string  $name  잠금 이름
             * @param  int  $seconds  유지 시간(초)
             * @param  string|null  $owner  소유자
             * @return ArrayLock 세는 잠금
             */
            public function lock($name, $seconds = 0, $owner = null)
            {
                $testClass = $this->testClass;

                return new class($this, $name, $seconds, $owner, $testClass) extends ArrayLock
                {
                    /**
                     * 세는 잠금을 만듭니다.
                     *
                     * @param  ArrayStore  $store  스토어
                     * @param  string  $name  잠금 이름
                     * @param  int  $seconds  유지 시간(초)
                     * @param  string|null  $owner  소유자
                     * @param  class-string  $testClass  시험 클래스
                     */
                    public function __construct($store, $name, $seconds, $owner, private string $testClass)
                    {
                        parent::__construct($store, $name, $seconds, $owner);
                    }

                    /**
                     * 연장 횟수를 세고 연장한다.
                     *
                     * @param  int|null  $seconds  유지 시간(초)
                     * @return bool 연장 여부
                     */
                    public function refresh($seconds = null)
                    {
                        $this->testClass::$i161Refreshes++;
                        if ($this->testClass::$i161RefreshUnsupported) {
                            throw new \RuntimeException('This lock driver does not support refreshing locks.');
                        }

                        return parent::refresh($seconds);
                    }
                };
            }
        }));

        Cache::forgetDriver('i161_spy_lock');
    }

    /**
     * 바꾼 기본 캐시 스토어를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function restoreOperationLockStore(): void
    {
        if ($this->i161PreviousCacheDefault !== null) {
            config(['cache.default' => $this->i161PreviousCacheDefault]);
            $this->i161PreviousCacheDefault = null;
        }
        Cache::forgetDriver('i161_broken_lock');
        Cache::forgetDriver('i161_spy_lock');
        self::$i161RefreshUnsupported = false;
    }
}
