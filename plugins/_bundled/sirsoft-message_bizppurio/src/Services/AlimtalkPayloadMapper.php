<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

/**
 * 카카오 상세조회 응답 → 비즈뿌리오 발송 API content.at 변환기 (B안 5-2).
 *
 * 카카오 관리 API(kapi) 의 템플릿 필드는 발송 API(/v3/message) 의 필드와 이름이 다르다
 * (예: kapi `buttons[].linkMo` ↔ 발송 `button[].url_mobile`). 이 매퍼는 상세조회 응답을
 * 발송 규격으로 변환하면서, 각 필드의 카카오 변수(#{var})를 알림 data 로 치환한다.
 * 발송 API 는 완성본을 요구하므로(카카오가 변수를 대신 치환하지 않음) 이 단계가 필수다.
 *
 * 변환 대상(매뉴얼 5.메시지발송 알림톡 at):
 *  - templateContent          → message
 *  - buttons[]                → button[]   (linkType→type, linkMo→url_mobile, …)
 *  - quickReplies[]           → quickreply[]
 *  - templateTitle            → title
 *  - templateHeader           → header
 *  - templateItem             → item        (list/summary 구조 유지 + 치환)
 *  - templateItemHighlight    → itemhighlight
 *  - templateRepresentLink    → link        (linkMo→url_mobile, …)
 *
 * 부재/빈 필드는 발송 payload 에 넣지 않는다(방어적 — 조건부 필수 필드는 템플릿에 있을 때만
 * 채워야 하고, 없는 필드를 빈 값으로 넣으면 카카오가 거부한다).
 *
 * message 를 제외한 button/quickreply/title/header/item/itemhighlight/link 는 `extra` 키에
 * 모아 반환한다. 호출측(AlimtalkChannelDriver)이 MessagePayloadBuilder::buildAlimtalk 의
 * $extra 인자로 그대로 넘긴다.
 */
class AlimtalkPayloadMapper
{
    /**
     * 버튼/바로연결/대표링크의 카카오 링크 필드 → 발송 필드 매핑.
     *
     * @var array<string, string>
     */
    private const LINK_FIELD_MAP = [
        'linkMo' => 'url_mobile',
        'linkPc' => 'url_pc',
        'linkAnd' => 'scheme_android',
        'linkIos' => 'scheme_ios',
    ];

    /**
     * 카카오 상세조회 응답을 발송 API content.at 형식으로 변환합니다.
     *
     * @param  array<string, mixed>  $detail  카카오 상세조회 응답(templateContent/buttons/…)
     * @param  array<string, mixed>  $data  알림 data (변수 치환 소스: {key => value})
     * @return array{message: string, extra: array<string, mixed>} 치환 완료 본문 + extra
     */
    public function map(array $detail, array $data): array
    {
        $message = $this->substitute((string) ($detail['templateContent'] ?? ''), $data);

        $extra = [];

        if ($button = $this->mapButtons($detail['buttons'] ?? null, $data)) {
            $extra['button'] = $button;
        }

        if ($quickreply = $this->mapButtons($detail['quickReplies'] ?? null, $data)) {
            $extra['quickreply'] = $quickreply;
        }

        if ($title = $this->substitute((string) ($detail['templateTitle'] ?? ''), $data)) {
            $extra['title'] = $title;
        }

        if ($header = $this->substitute((string) ($detail['templateHeader'] ?? ''), $data)) {
            $extra['header'] = $header;
        }

        if ($item = $this->mapItem($detail['templateItem'] ?? null, $data)) {
            $extra['item'] = $item;
        }

        if ($highlight = $this->mapItemHighlight($detail['templateItemHighlight'] ?? null, $data)) {
            $extra['itemhighlight'] = $highlight;
        }

        if ($link = $this->mapLinkFields($detail['templateRepresentLink'] ?? null, $data)) {
            $extra['link'] = $link;
        }

        return ['message' => $message, 'extra' => $extra];
    }

    /**
     * 버튼/바로연결 배열을 발송 형식으로 변환합니다 (동일 규칙 공유).
     *
     * @param  mixed  $buttons  카카오 buttons/quickReplies 배열
     * @param  array<string, mixed>  $data  변수 치환 소스
     * @return array<int, array<string, mixed>> 발송 button/quickreply 배열 (없으면 빈 배열)
     */
    private function mapButtons(mixed $buttons, array $data): array
    {
        if (! is_array($buttons) || $buttons === []) {
            return [];
        }

        $mapped = [];

        foreach ($buttons as $button) {
            if (! is_array($button)) {
                continue;
            }

            $row = [];

            if (isset($button['name'])) {
                $row['name'] = $this->substitute((string) $button['name'], $data);
            }

            if (isset($button['linkType'])) {
                $row['type'] = (string) $button['linkType'];
            }

            // 링크 필드(linkMo/linkPc/linkAnd/linkIos) → 발송 필드 + URL 변수 치환.
            foreach (self::LINK_FIELD_MAP as $from => $to) {
                if (isset($button[$from]) && $button[$from] !== '') {
                    $row[$to] = $this->substituteLinkField((string) $button[$from], $data);
                }
            }

            // 전화·플러그인 등 부가 필드.
            if (isset($button['telNumber']) && $button['telNumber'] !== '') {
                $row['tel_number'] = (string) $button['telNumber'];
            }

            if (isset($button['pluginId']) && $button['pluginId'] !== '') {
                $row['plugin_id'] = (string) $button['pluginId'];
            }

            if ($row !== []) {
                $mapped[] = $row;
            }
        }

        return $mapped;
    }

    /**
     * 대표링크/링크 필드(linkMo/linkPc/linkAnd/linkIos) → 발송 형식으로 변환합니다.
     *
     * @param  mixed  $link  카카오 templateRepresentLink
     * @param  array<string, mixed>  $data  변수 치환 소스
     * @return array<string, mixed> 발송 link 필드 (없으면 빈 배열)
     */
    private function mapLinkFields(mixed $link, array $data): array
    {
        if (! is_array($link) || $link === []) {
            return [];
        }

        $mapped = [];

        foreach (self::LINK_FIELD_MAP as $from => $to) {
            if (isset($link[$from]) && $link[$from] !== '') {
                $mapped[$to] = $this->substituteLinkField((string) $link[$from], $data);
            }
        }

        return $mapped;
    }

    /**
     * 아이템리스트(list/summary)를 변환하고 각 필드를 치환합니다.
     *
     * @param  mixed  $item  카카오 templateItem
     * @param  array<string, mixed>  $data  변수 치환 소스
     * @return array<string, mixed> 발송 item (없으면 빈 배열)
     */
    private function mapItem(mixed $item, array $data): array
    {
        if (! is_array($item) || $item === []) {
            return [];
        }

        $mapped = [];

        if (isset($item['list']) && is_array($item['list'])) {
            $list = [];
            foreach ($item['list'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $list[] = [
                    'title' => $this->substitute((string) ($entry['title'] ?? ''), $data),
                    'description' => $this->substitute((string) ($entry['description'] ?? ''), $data),
                ];
            }
            if ($list !== []) {
                $mapped['list'] = $list;
            }
        }

        if (isset($item['summary']) && is_array($item['summary'])) {
            $mapped['summary'] = [
                'title' => $this->substitute((string) ($item['summary']['title'] ?? ''), $data),
                'description' => $this->substitute((string) ($item['summary']['description'] ?? ''), $data),
            ];
        }

        return $mapped;
    }

    /**
     * 아이템 하이라이트(title/description)를 변환하고 치환합니다.
     *
     * @param  mixed  $highlight  카카오 templateItemHighlight
     * @param  array<string, mixed>  $data  변수 치환 소스
     * @return array<string, mixed> 발송 itemhighlight (없으면 빈 배열)
     */
    private function mapItemHighlight(mixed $highlight, array $data): array
    {
        if (! is_array($highlight) || $highlight === []) {
            return [];
        }

        $mapped = [];

        if (isset($highlight['title'])) {
            $mapped['title'] = $this->substitute((string) $highlight['title'], $data);
        }

        if (isset($highlight['description'])) {
            $mapped['description'] = $this->substitute((string) $highlight['description'], $data);
        }

        return $mapped;
    }

    /**
     * 임의 텍스트의 카카오 변수(#{key})를 알림 data 로 치환합니다 (공개 진입점).
     *
     * 대체 SMS·SMS 단독 본문(bizppurio_templates.sms_body)이 알림톡 본문과 동일한
     * `#{var}` 표기를 쓰므로(#597 §3.1), 드라이버가 본문 치환에 재사용한다.
     *
     * @param  string  $text  치환 대상(#{key} 포함)
     * @param  array<string, mixed>  $data  변수 치환 소스
     * @return string 치환된 문자열
     */
    public function substituteText(string $text, array $data): string
    {
        return $this->substitute($text, $data);
    }

    /**
     * 카카오 변수(#{key})를 알림 data 값으로 치환합니다.
     *
     * 카카오 템플릿 변수와 코어 알림 data 는 변수명 규칙이 동일하다(표기만 #{} vs {}).
     * data 에 없는 변수는 원문(#{key})을 유지한다 — 카카오가 변수 불일치로 판단하게 두어,
     * 우리가 임의로 빈 값을 채워 잘못된 발송을 하지 않는다.
     *
     * @param  string  $text  치환 대상(#{key} 포함)
     * @param  array<string, mixed>  $data  변수 치환 소스
     * @return string 치환된 문자열
     */
    private function substitute(string $text, array $data): string
    {
        if ($text === '' || $data === []) {
            return $text;
        }

        $replacements = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements['#{'.$key.'}'] = (string) $value;
            }
        }

        return strtr($text, $replacements);
    }

    /**
     * 버튼/링크 URL 필드를 치환하고, 프로토콜 중복을 방어합니다.
     *
     * 코어 알림 data 의 `*_url` 계열 변수(action_url 등)는 항상 `config('app.url')` 기반의
     * 프로토콜 포함 완전한 URL 이다(mail 채널이 href="{action_url}" 로 원문 그대로 소비하는
     * 계약). 반면 카카오 알림톡 버튼은 `http://#{action_url}` 처럼 원문에 프로토콜 접두어를
     * 직접 붙여 등록되는 경우가 있어, 단순 문자열 치환 시 `http://https://…` 형태로 프로토콜이
     * 중복될 수 있다.
     *
     * 원문이 `http://`/`https://` 로 시작 + 치환 결과가 (그 접두어를 제거했을 때) 다시
     * `http://`/`https://` 로 시작하는 경우에만 원문의 선행 접두어를 제거한다. 변수가 이미
     * 프로토콜 없이 등록된 경우(가이드 문서 원안)나, 변수값 자체가 프로토콜을 포함하지 않는
     * 경우(상대경로 등)는 원문을 그대로 둔다 — 후자를 건드리면 오히려 필요한 접두어가 사라진다.
     *
     * @param  string  $text  치환 대상(#{key} 포함, 링크 필드 원문)
     * @param  array<string, mixed>  $data  변수 치환 소스
     * @return string 치환되고 프로토콜 중복이 제거된 문자열
     */
    private function substituteLinkField(string $text, array $data): string
    {
        $substituted = $this->substitute($text, $data);

        foreach (['http://', 'https://'] as $prefix) {
            if (! str_starts_with($text, $prefix)) {
                continue;
            }

            $afterPrefix = substr($substituted, strlen($prefix));
            if (str_starts_with($afterPrefix, 'http://') || str_starts_with($afterPrefix, 'https://')) {
                return $afterPrefix;
            }
        }

        return $substituted;
    }
}
