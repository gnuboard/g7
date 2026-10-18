@php
    // IP 접근 제한으로 막힌 요청은 관리자 영역이라도 홈 버튼을 사이트 루트로 보낸다 —
    // '/admin' 은 방금 막힌 바로 그 영역이라 눌러도 같은 화면으로 돌아온다.
    $ipRestricted = ($ipRestricted ?? false) === true;
    $homeUrl = $ipRestricted ? '/' : (str_starts_with(request()->path(), 'admin') ? '/admin' : '/');
@endphp

@extends('errors.layout')

@section('code', '403')
@section('title', $ipRestricted ? __('errors.ip_restricted.title') : __('errors.403.title'))
@section('message', $ipRestricted ? __('errors.ip_restricted.message') : __('errors.403.message'))

@if ($ipRestricted && filled($clientIp ?? null))
    @section('extra')
        <p class="error-message" style="font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 0.875rem;">
            {{ __('errors.ip_restricted.client_ip', ['ip' => $clientIp]) }}
        </p>
    @endsection
@endif
