<?php

namespace App\Extension\Support;

/**
 * 「새 판에만 있는 확장 클래스」로 인한 실패인지 판정합니다.
 *
 * 코어 업그레이드 스텝은 확장 갱신보다 먼저 돈다. 그때 확장 선언은 새 판(`_bundled/{id}`)에서 읽지만 클래스는 오토로드가 보는
 * 활성 폴더(`{id}/src`)에서 찾는다 — 새 판에서 새로 생긴 클래스는 아직 없어 `Class "…" not found` 로 실패한다. 이 실패는
 * 운영자가 고칠 일이 아니고, 이어지는 확장 갱신이 같은 선언을 새 클래스로 다시 동기화한다
 * (7.1.6 → 7.2.0 실측: 환율 플러그인 `Services\UpdateScheduleResolver`).
 *
 * 판정: 메시지가 `Class|Interface|Trait|Enum "…" not found` 이고, 이름공간이 그 확장 종류(`Modules`·`Plugins`)이며, 클래스
 * 파일이 새 판 `src/` 에는 있고 활성 `src/` 에는 없을 때만.
 */
final class PendingBundledExtensionClass
{
    /**
     * 실패 메시지가 새 판에만 있는 확장 클래스 때문이면 그 클래스 이름을 돌려줍니다.
     *
     * @param  string  $message  실패 메시지
     * @param  string  $namespaceRoot  확장 종류의 최상위 이름공간 (`Modules` 또는 `Plugins`)
     * @param  string  $bundledDirectory  새 판 확장 폴더 (`{modules|plugins}/_bundled/{id}`)
     * @param  string  $activeDirectory  활성 확장 폴더 (`{modules|plugins}/{id}`)
     * @return string|null 클래스 이름 (해당하지 않으면 null)
     */
    public static function fromMessage(string $message, string $namespaceRoot, string $bundledDirectory, string $activeDirectory): ?string
    {
        if (! preg_match('/(?:Class|Interface|Trait|Enum) "([A-Za-z0-9_\\\\]+)" not found/', $message, $m)) {
            return null;
        }

        // 확장 클래스 이름공간은 `{Modules|Plugins}\{Vendor}\{Name}\…` 이고 `{Vendor}\{Name}\` 이 `src/` 에 대응한다
        $segments = explode('\\', ltrim($m[1], '\\'));
        if (count($segments) < 4 || $segments[0] !== $namespaceRoot) {
            return null;
        }

        $relative = 'src/'.implode('/', array_slice($segments, 3)).'.php';
        $inBundled = is_file(rtrim($bundledDirectory, '/\\').'/'.$relative);
        $inActive = is_file(rtrim($activeDirectory, '/\\').'/'.$relative);

        return $inBundled && ! $inActive ? $m[1] : null;
    }
}
