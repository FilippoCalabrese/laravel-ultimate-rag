<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Tenancy\TenantContext;

it('defaults to the configured tenant', function () {
    expect((new TenantContext('acme'))->id())->toBe('acme');
});

it('sets and forgets the tenant', function () {
    $context = new TenantContext('default');
    $context->set('t1');
    expect($context->id())->toBe('t1');

    $context->forget();
    expect($context->id())->toBe('default');
});

it('runs a callback scoped to a tenant and restores the previous one', function () {
    $context = new TenantContext('default');
    $context->set('outer');

    $result = $context->runAs('inner', function () use ($context) {
        expect($context->id())->toBe('inner');

        return 'value';
    });

    expect($result)->toBe('value')->and($context->id())->toBe('outer');
});

it('restores the tenant even when the callback throws', function () {
    $context = new TenantContext('default');

    try {
        $context->runAs('inner', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // expected
    }

    expect($context->id())->toBe('default');
});
