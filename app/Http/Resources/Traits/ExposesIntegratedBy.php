<?php

namespace App\Http\Resources\Traits;

use App\Extension\Helpers\IntegrationNotices;

/**
 * 확장 리소스에 `integrated_by`(이 확장을 선택 연동으로 선언한 활성 확장)를 싣습니다 (7.1.4).
 *
 * 관리자 확장 목록의 비활성화·제거 확인 창이 「연동 중인 확장 — 이 확장을 쓰던 메뉴·화면이 사라집니다」를 보일 때 읽는다.
 * 설치되지 않은 행은 끄거나 지울 수 없으므로 조회하지 않고 빈 목록을 낸다.
 */
trait ExposesIntegratedBy
{
    /**
     * 이 확장을 연동으로 선언한 활성 확장 목록을 반환합니다.
     *
     * 호출자가 미리 채운 `integrated_by` 가 있으면 그대로 쓴다.
     *
     * @param  string  $type  이 리소스의 확장 유형 (module|plugin|template)
     * @return list<array{type: string, identifier: string, name: string}> 연동 중인 활성 확장
     */
    protected function integratedBy(string $type): array
    {
        $given = $this->getValue('integrated_by');
        if (is_array($given)) {
            return array_values($given);
        }

        $identifier = $this->getValue('identifier');
        $status = $this->getValue('status');
        if ($status instanceof \BackedEnum) {
            $status = $status->value;
        }

        if (! is_string($identifier) || $identifier === '' || $status === null || $status === 'uninstalled') {
            return [];
        }

        return IntegrationNotices::integratedBy($type, $identifier);
    }
}
