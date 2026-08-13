<?php

declare(strict_types=1);

use Kurt\Modules\I18n\Exceptions\TranslationPathException;
use Kurt\Modules\I18n\Support\LangPaths;

beforeEach(function (): void {
    $this->paths = new LangPaths('/var/lang');
});

it('builds json and php paths under the root', function (): void {
    expect($this->paths->jsonPath('en'))->toBe('/var/lang/en.json')
        ->and($this->paths->phpPath('users', 'en'))->toBe('/var/lang/en/users.php')
        ->and($this->paths->phpPath('admin/users', 'tr'))->toBe('/var/lang/tr/admin/users.php');
});

it('returns the path relative to the root', function (): void {
    expect($this->paths->relative('/var/lang/en/users.php'))->toBe('en/users.php');
});

it('validates locales', function (): void {
    expect(LangPaths::isValidLocale('en'))->toBeTrue()
        ->and(LangPaths::isValidLocale('pt_BR'))->toBeTrue()
        ->and(LangPaths::isValidLocale('zh-Hans'))->toBeTrue()
        ->and(LangPaths::isValidLocale('..'))->toBeFalse()
        ->and(LangPaths::isValidLocale('en/x'))->toBeFalse()
        ->and(LangPaths::isValidLocale(''))->toBeFalse();
});

it('parses a locale list into trimmed, unique, non-empty entries', function (): void {
    // The one rule the ?locales= query parameter and the --locales flag share,
    // so the CLI report and the endpoint report cannot drift apart.
    expect(LangPaths::parseLocaleList('en, tr'))->toBe(['en', 'tr'])
        ->and(LangPaths::parseLocaleList('en,en'))->toBe(['en'])
        ->and(LangPaths::parseLocaleList('en, ,tr,'))->toBe(['en', 'tr'])
        ->and(LangPaths::parseLocaleList(' , '))->toBe([]);
});

it('validates groups', function (): void {
    expect(LangPaths::isValidGroup('users'))->toBeTrue()
        ->and(LangPaths::isValidGroup('admin/users'))->toBeTrue()
        ->and(LangPaths::isValidGroup('../etc'))->toBeFalse()
        ->and(LangPaths::isValidGroup('a\\b'))->toBeFalse()
        ->and(LangPaths::isValidGroup('users.php'))->toBeFalse()
        ->and(LangPaths::isValidGroup(''))->toBeFalse();
});

it('rejects a traversing locale in path builders', function (): void {
    (new LangPaths('/var/lang'))->jsonPath('../evil');
})->throws(TranslationPathException::class);

it('rejects a traversing group in path builders', function (): void {
    (new LangPaths('/var/lang'))->phpPath('../evil', 'en');
})->throws(TranslationPathException::class);

it('rejects a path outside the root', function (): void {
    (new LangPaths('/var/lang'))->assertSafe('/var/other/secrets.php');
})->throws(TranslationPathException::class);

it('rejects traversal that escapes the root', function (): void {
    (new LangPaths('/var/lang'))->assertSafe('/var/lang/../other/x');
})->throws(TranslationPathException::class);

it('builds a vendor namespaced php path', function (): void {
    expect($this->paths->phpPath('firewall::notifications', 'en'))->toBe('/var/lang/vendor/firewall/en/notifications.php')
        ->and($this->paths->phpPath('firewall::admin/alerts', 'de'))->toBe('/var/lang/vendor/firewall/de/admin/alerts.php');
});

it('validates packages and namespaced group refs', function (): void {
    expect(LangPaths::isValidPackage('firewall'))->toBeTrue()
        ->and(LangPaths::isValidPackage('..'))->toBeFalse()
        ->and(LangPaths::isValidGroupRef('auth'))->toBeTrue()
        ->and(LangPaths::isValidGroupRef('firewall::notifications'))->toBeTrue()
        ->and(LangPaths::isValidGroupRef('firewall::../etc'))->toBeFalse()
        ->and(LangPaths::isValidGroupRef('..::x'))->toBeFalse();
});

it('rejects a traversing package in a vendor path', function (): void {
    (new LangPaths('/var/lang'))->phpPath('..::x', 'en');
})->throws(TranslationPathException::class);
