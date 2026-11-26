<?php

namespace App\Support\Custom;

use Illuminate\View\FileViewFinder;

/**
 * `.html` 뷰를 custom 예약 자리(`resources/views/custom`)에서만 찾는 뷰 파인더.
 *
 * HTML+jQuery 개발자가 `view('custom.board')` 로 `.html` 파일을 그대로 돌려줄 수 있도록 `html` 확장자를
 * 「파일 그대로 내보내는 뷰」로 등록한다. 라라벨 파인더의 확장자 목록은 전역이고 새 확장자가 맨 앞에
 * 들어가므로, 그대로 두면 코어·확장의 모든 뷰 조회가 `.html` 파일을 먼저 찾는다(같은 이름이 있으면 가로챈다).
 * 이 파인더는 `custom.`·`custom/` 로 시작하는 뷰 이름에서만 `.html` 후보를 남긴다.
 *
 * @since 7.2.0
 */
class CustomViewFinder extends FileViewFinder
{
    /** `.html` 을 허용하는 뷰 이름 접두 */
    public const VIEW_PREFIX = 'custom';

    /**
     * 기존 파인더의 경로·확장자·네임스페이스를 그대로 옮겨 만듭니다.
     *
     * @param  FileViewFinder  $original  기존 파인더
     * @return self 새 파인더
     */
    public static function fromFinder(FileViewFinder $original): self
    {
        $finder = new self($original->getFilesystem(), $original->getPaths(), $original->getExtensions());
        foreach ($original->getHints() as $namespace => $hints) {
            $finder->addNamespace($namespace, $hints);
        }

        return $finder;
    }

    /**
     * 뷰 이름이 custom 예약 자리의 뷰인지 판정합니다.
     *
     * @param  string  $name  뷰 이름
     * @return bool custom 뷰면 true
     */
    public static function isCustomView(string $name): bool
    {
        return $name === self::VIEW_PREFIX
            || str_starts_with($name, self::VIEW_PREFIX.'.')
            || str_starts_with($name, self::VIEW_PREFIX.'/');
    }

    /**
     * 뷰 후보 파일 목록 — custom 뷰가 아니면 `.html` 후보를 뺀다.
     *
     * @param  string  $name  뷰 이름
     * @return array<int, string> 후보 파일 상대 경로
     */
    protected function getPossibleViewFiles($name)
    {
        $files = parent::getPossibleViewFiles($name);
        if (self::isCustomView((string) $name)) {
            return $files;
        }

        return array_values(array_filter($files, fn (string $file) => ! str_ends_with($file, '.html')));
    }
}
