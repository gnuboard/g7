<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 미배치 공식 번들 안내 응답 리소스 (공개 #146)
 *
 * 관리자 목록 화면(모듈·플러그인·템플릿·언어팩) 안내 블록의 데이터 소스다.
 * `items.{modules|plugins|templates|language_packs}[]` 는 `{identifier, name, version}` 이고,
 * `commands.{유형}` 은 그 유형 항목을 배치하는 복사용 명령 문자열이다.
 *
 * @property array{items: array<string, array<int, array<string, string>>>, hash: string|null, core_version: string, command: string, commands: array<string, string>} $resource
 */
class UnplacedBundledNoticeResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array{items: array<string, array<int, array<string, string>>>, hash: string|null, core_version: string, command: string, commands: object}
     *                                                                                                                                                    안내 데이터 (빈 commands 는 JSON 객체로 직렬화)
     */
    public function toArray(Request $request): array
    {
        return [
            'items' => $this->resource['items'],
            'hash' => $this->resource['hash'],
            'core_version' => $this->resource['core_version'],
            'command' => $this->resource['command'],
            'commands' => (object) $this->resource['commands'],
        ];
    }
}
