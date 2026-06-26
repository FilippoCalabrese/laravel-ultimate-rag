<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Ingestion\SsrfGuard;

beforeEach(fn () => $this->guard = new SsrfGuard);

it('allows a public IP host', function () {
    $this->guard->assertSafe('https://93.184.216.34/page');
})->throwsNoExceptions();

it('blocks the cloud metadata endpoint (H3)', function () {
    $this->guard->assertSafe('http://169.254.169.254/latest/meta-data/');
})->throws(RagException::class, 'SSRF');

it('blocks loopback', function () {
    $this->guard->assertSafe('http://127.0.0.1:8080/internal');
})->throws(RagException::class, 'SSRF');

it('blocks private ranges', function () {
    expect(fn () => $this->guard->assertSafe('http://10.0.0.5/'))->toThrow(RagException::class)
        ->and(fn () => $this->guard->assertSafe('http://192.168.1.1/'))->toThrow(RagException::class)
        ->and(fn () => $this->guard->assertSafe('http://172.16.0.1/'))->toThrow(RagException::class);
});

it('blocks localhost by name', function () {
    $this->guard->assertSafe('http://localhost/admin');
})->throws(RagException::class);

it('blocks non-http schemes', function () {
    expect(fn () => $this->guard->assertSafe('file:///etc/passwd'))->toThrow(RagException::class, 'scheme')
        ->and(fn () => $this->guard->assertSafe('gopher://x/'))->toThrow(RagException::class, 'scheme');
});

it('blocks IPv6 loopback', function () {
    $this->guard->assertSafe('http://[::1]/');
})->throws(RagException::class);

it('rejects malformed urls and urls without a host', function () {
    expect(fn () => $this->guard->assertSafe('https://:8080/path'))->toThrow(RagException::class)
        ->and(fn () => $this->guard->assertSafe('not a url'))->toThrow(RagException::class);
});
