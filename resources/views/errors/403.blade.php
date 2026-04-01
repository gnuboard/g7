@php
    $homeUrl = str_starts_with(request()->path(), 'admin') ? '/admin' : '/';
@endphp

@extends('errors.layout')

@section('code', '403')
@section('title', __('errors.403.title'))
@section('message', __('errors.403.message'))
