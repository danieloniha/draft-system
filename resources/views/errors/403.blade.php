@extends('errors.layout')

@section('code', '403')
@section('title', 'You can\'t do that')
@section('message', $exception->getMessage() ?: 'You do not have permission to open this page.')
