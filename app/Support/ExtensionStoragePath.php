<?php

namespace App\Support;

/**
 * 확장(모듈/플러그인) 저장 경로의 **로컬 파일시스템 절대 경로** 해석기.
 *
 * `StorageInterface` 로 읽고 쓸 수 있는 자리에는 이 클래스가 필요 없다 — 그쪽을 쓴다.
 * 이 해석기는 파일시스템 경로 **문자열 자체가 필요한** 자리를 위한 것이다:
 *
 *  - 제3자 라이브러리에 캐시·임시 디렉토리를 넘길 때 (HTMLPurifier `Cache.SerializerPath` 등)
 *  - 설정 JSON 을 `file_put_contents` 계열로 직접 다루는 자리
 *
 * `AbstractModule::getStorageBasePath()` 를 쓰지 않는 이유는 그 반환값이
 * `Storage::disk()->path()` 위임이라, 확장이 카테고리 디스크를 비로컬(S3 등)로 오버라이드하면
 * 파일시스템 경로가 아니게 되기 때문이다. 그러면 라이브러리가 그 값을 상대경로로 보고 현재
 * 작업 디렉토리 기준으로 해석해 **조용히 엉뚱한 곳에 쓴다.**
 *
 * 경로 레이아웃은 `modules`/`plugins` 디스크의 root(`config/filesystems.php`)를 단일 출처로
 * 삼는다. 그 root 가 테스트 환경을 인지하므로, 각 확장이 `app()->runningUnitTests()` 분기를
 * 자기 안에 복사할 필요가 없다 — 복사본은 한 곳만 빠뜨려도 그 확장의 테스트가 조용히 운영
 * 설정 파일을 덮어쓴다.
 */
class ExtensionStoragePath
{
    /**
     * 모듈 저장 경로의 절대 경로를 반환합니다.
     *
     * @param  string  $identifier  모듈 식별자 (예: sirsoft-ecommerce)
     * @param  string  $category  카테고리 (예: settings, cache/htmlpurifier). 빈 문자열이면 모듈 루트
     * @return string 절대 경로 (존재 여부와 무관한 순수 계산)
     */
    public static function module(string $identifier, string $category = ''): string
    {
        return static::resolve('modules', $identifier, $category);
    }

    /**
     * 플러그인 저장 경로의 절대 경로를 반환합니다.
     *
     * @param  string  $identifier  플러그인 식별자 (예: sirsoft-pay_kginicis)
     * @param  string  $category  카테고리 (예: settings). 빈 문자열이면 플러그인 루트
     * @return string 절대 경로 (존재 여부와 무관한 순수 계산)
     */
    public static function plugin(string $identifier, string $category = ''): string
    {
        return static::resolve('plugins', $identifier, $category);
    }

    /**
     * 디스크 root 를 기준으로 `{root}/{identifier}[/{category}]` 를 조립합니다.
     *
     * root 는 `config/filesystems.php` 가 단일 출처다. 설정이 비어 있는 비정상 상황에서만
     * 운영 기본 레이아웃으로 되돌아간다 — 여기서 예외를 던지면 설정 파일 하나 때문에
     * 확장 기능 전체가 멈춘다.
     *
     * @param  string  $disk  디스크 이름 (modules | plugins)
     * @param  string  $identifier  확장 식별자
     * @param  string  $category  카테고리
     * @return string 절대 경로
     */
    protected static function resolve(string $disk, string $identifier, string $category): string
    {
        $root = config("filesystems.disks.{$disk}.root");

        if (! is_string($root) || $root === '') {
            $root = storage_path('app/'.$disk);
        }

        $path = rtrim($root, '/\\').'/'.trim($identifier, '/\\');

        $category = trim($category, '/\\');

        return $category === '' ? $path : $path.'/'.$category;
    }
}
