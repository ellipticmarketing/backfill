<?php

use Elliptic\Backfill\Http\Middleware\BackfillAuth;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

it('allows requests with valid token', function () {
    config(['backfill.auth_token' => 'valid-token']);

    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', 'Bearer valid-token');

    $middleware = new BackfillAuth();
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});

it('rejects requests with invalid token', function () {
    config(['backfill.auth_token' => 'valid-token']);

    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', 'Bearer wrong-token');

    $middleware = new BackfillAuth();
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(401);
});

it('rejects requests with no token', function () {
    config(['backfill.auth_token' => 'valid-token']);

    $request = Request::create('/test', 'GET');

    $middleware = new BackfillAuth();
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(401);
});

it('returns 500 if server token is not configured', function () {
    config(['backfill.auth_token' => null]);

    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', 'Bearer some-token');

    $middleware = new BackfillAuth();
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(500);
});
