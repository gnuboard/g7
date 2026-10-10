<?php

namespace App\Analytics;

/**
 * 이름 사전 해시
 *
 * 사전 표는 종류와 이름의 조합으로 중복을 막는데, 이름이 1024자까지 갈 수 있어
 * 그대로 유니크 인덱스에 넣을 수 없습니다. 고정 길이 해시를 대신 인덱싱합니다.
 *
 * 해시가 바뀌면 같은 URL 이 새 사전 행으로 들어가 과거 집계와 이어지지 않으므로,
 * 이 계산식은 한 번 정하면 바꾸지 않습니다.
 */
class ActionNameHasher
{
    /**
     * 종류와 이름으로 사전 해시를 만듭니다.
     *
     * @param  string  $type  이름 종류 (url, title, download, outlink, search)
     * @param  string  $name  정규화된 이름
     * @return string 32자 해시
     */
    public static function hash(string $type, string $name): string
    {
        return md5($type.'|'.$name);
    }

    /**
     * 차원 집계의 라벨 해시를 만듭니다.
     *
     * 라벨은 512자까지 갈 수 있어 유니크 키에 그대로 쓰지 않습니다.
     *
     * @param  string  $label  라벨 원값
     * @return string 32자 해시
     */
    public static function labelHash(string $label): string
    {
        return md5($label);
    }

    /**
     * 봇 요청 URL 의 해시를 만듭니다.
     *
     * @param  string  $url  요청 URL
     * @return string 32자 해시
     */
    public static function urlHash(string $url): string
    {
        return md5($url);
    }
}
