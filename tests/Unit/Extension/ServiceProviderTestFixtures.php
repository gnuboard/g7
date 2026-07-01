<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\StorageInterface;

/**
 * AbstractExtensionServiceProvider 계열 단위 테스트가 공유하는 fixture 클래스 모음.
 *
 * 본 파일은 각 테스트 클래스에서 단독 실행될 때도 동일 fixture 가 로드되도록
 * 별도 파일로 분리되어 있습니다 (개별 테스트가 다른 테스트에 정의된 fixture
 * 클래스를 require 받지 않으면 BindingResolutionException 발생).
 */
interface FixtureRepositoryInterface {}

class FixtureRepositoryImpl implements FixtureRepositoryInterface {}

class FixtureCacheConsumer
{
    public function __construct(public CacheInterface $cache) {}
}

class FixtureStorageConsumer
{
    public function __construct(public StorageInterface $storage) {}
}
