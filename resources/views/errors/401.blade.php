@php
    $homeUrl = str_starts_with(request()->path(), 'admin') ? '/admin' : '/';
@endphp

@extends('errors.layout')

@section('code', '401')
@section('title', __('errors.401.title'))
@section('message', __('errors.401.message'))
