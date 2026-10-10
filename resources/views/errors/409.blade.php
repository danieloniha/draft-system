@extends('errors.layout')

@section('code', '409')
@section('title', 'That is not possible right now')
@section('message', $exception->getMessage() ?: 'The session is not in a state where that can be done.')

