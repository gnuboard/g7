<?php

namespace App\Console\Commands\Traits;

/**
 * artisan 명령 설명을 다국어로 낸다.
 *
 * 설명 문장은 `lang/{locale}/commands.php` 의 `descriptions.{명령 이름}` 에 둔다(명령 클래스에 `$description` 을
 * 쓰지 않는다). `php artisan list`·`--help` 가 설명을 읽는 순간의 로케일로 번역한다. 키가 없으면 클래스에 남은
 * `$description` 으로 되돌아간다.
 */
trait LocalizesDescription
{
    /**
     * 현재 로케일의 명령 설명.
     *
     * @return string 설명
     */
    public function getDescription(): string
    {
        $key = 'commands.descriptions.'.$this->getName();
        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : parent::getDescription();
    }
}
