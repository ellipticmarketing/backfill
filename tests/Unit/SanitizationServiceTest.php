<?php

use Elliptic\Backfill\Services\SanitizationService;
use Elliptic\Backfill\Services\TempDatabaseService;

beforeEach(function () {
    config(['backfill.server.temp_strategy' => 'tables']);
    $this->tempDb = new TempDatabaseService;
    $this->service = new SanitizationService;
});

it('generates email sanitization SQL', function () {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildExpression');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, 'email', 'users', 'email');

    expect($result)->toContain('UUID()');
    expect($result)->toContain('@example.test');
});

it('generates name sanitization SQL', function () {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildExpression');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, 'name', 'users', 'name');

    expect($result)->toContain('User_');
});

it('generates phone sanitization SQL', function () {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildExpression');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, 'phone', 'users', 'phone');

    expect($result)->toContain('+1555');
});

it('generates null sanitization SQL', function () {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildExpression');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, 'null', 'users', 'secret');

    expect($result)->toBe('NULL');
});

it('generates hash sanitization SQL with bcrypt', function () {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildExpression');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, 'hash', 'users', 'password');

    expect($result)->toContain('$2y$10$');
});

it('generates text sanitization SQL', function () {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildExpression');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, 'text', 'posts', 'body');

    expect($result)->toContain('MD5');
    expect($result)->toContain('RAND()');
});

it('generates address sanitization SQL', function () {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildExpression');
    $method->setAccessible(true);

    $result = $method->invoke($this->service, 'address', 'users', 'street');

    expect($result)->toContain('Example St');
});

it('throws exception for unknown sanitization type', function () {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildExpression');
    $method->setAccessible(true);

    $method->invoke($this->service, 'unknown_type', 'users', 'email');
})->throws(InvalidArgumentException::class);

it('builds UPDATE SQL with exclude patterns using CASE WHEN', function () {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildUpdateSql');
    $method->setAccessible(true);

    $tempDb = Mockery::mock(TempDatabaseService::class);
    $tempDb->shouldReceive('qualifiedTableName')
        ->with('users')
        ->andReturn('`_backfill_temp`.`users`');

    $result = $method->invoke(
        $this->service,
        'users',
        'email',
        "CONCAT(UUID(), '@example.test')",
        ['*@ellipticmarketing.com', 'john@exclude.com'],
        $tempDb
    );

    expect($result)->toContain('CASE');
    expect($result)->toContain("LIKE '%@ellipticmarketing.com'");
    expect($result)->toContain("LIKE 'john@exclude.com'");
    expect($result)->toContain('THEN `email`'); // keep original
    expect($result)->toContain('ELSE');
    expect($result)->toContain('`_backfill_temp`.`users`');
});

it('builds simple UPDATE SQL without excludes', function () {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('buildUpdateSql');
    $method->setAccessible(true);

    $tempDb = Mockery::mock(TempDatabaseService::class);
    $tempDb->shouldReceive('qualifiedTableName')
        ->with('users')
        ->andReturn('`_backfill_temp`.`users`');

    $result = $method->invoke(
        $this->service,
        'users',
        'name',
        "CONCAT('User_', `id`)",
        [],
        $tempDb
    );

    expect($result)->not->toContain('CASE');
    expect($result)->toContain("UPDATE `_backfill_temp`.`users` SET `name` = CONCAT('User_', `id`)");
});
