<?php

namespace App\Extension\Support;

/**
 * 확장 업그레이드 단계 파일을 싣습니다 — 같은 클래스를 다른 경로의 동일 사본이 이미 선언했으면 다시 싣지 않습니다.
 *
 * 확장은 활성 디렉터리(`modules/<id>`)와 번들 디렉터리(`modules/_bundled/<id>`)에 같은 파일을 둘 수 있다. 한 프로세스에서
 * 한쪽 사본의 단계 클래스가 먼저 선언된 뒤 다른 쪽 경로로 단계를 수집하면, 경로가 달라 `require_once` 가 막지 못해
 * 「Cannot declare class」 Fatal 로 프로세스가 죽었다(7.1.5 — 게시판 테스트 스위트가 중간에 통째로 중단).
 * 내용이 같은 사본이면 이미 선언된 클래스를 그대로 쓴다. 내용이 다르면 종전처럼 싣는다 — 다른 판의 단계 코드를 조용히
 * 대신 실행하지 않게 한다.
 */
final class UpgradeStepFileLoader
{
    /**
     * 단계 파일을 싣습니다.
     *
     * @param  string  $file  단계 파일 경로
     * @param  string  $class  그 파일이 선언할 클래스(FQCN)
     * @return void 반환값 없음
     */
    public static function load(string $file, string $class): void
    {
        if (class_exists($class, false)) {
            $declared = (new \ReflectionClass($class))->getFileName();

            if ($declared !== false && self::sameFile($declared, $file)) {
                return;
            }
        }

        require_once $file;
    }

    /**
     * 두 파일이 같은 파일이거나 내용이 같은지 판정합니다.
     *
     * @param  string  $a  파일 경로
     * @param  string  $b  파일 경로
     * @return bool 같으면 true
     */
    private static function sameFile(string $a, string $b): bool
    {
        $realA = realpath($a);
        $realB = realpath($b);

        if ($realA !== false && $realA === $realB) {
            return true;
        }

        $hashA = @hash_file('sha1', $a);

        return $hashA !== false && $hashA === @hash_file('sha1', $b);
    }
}
