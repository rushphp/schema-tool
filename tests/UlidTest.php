<?php declare(strict_types=1);

use Rush\Tool\Schema\Ulid;


test('ulid is valid, unique and ordered', function () {
    $a = Ulid::generate();
    $b = Ulid::generate();

    expect(Ulid::isValid($a))->toBeTrue()
        ->and(Ulid::isValid($b))->toBeTrue()
        ->and($a)->not->toBe($b)
        ->and(strcmp($a, $b))->toBeLessThan(0)
        ->and(Ulid::timestamp($a))->toBeGreaterThan(0);
});


test('ulid rejects invalid values', function () {
    expect(Ulid::isValid(''))->toBeFalse()
        ->and(Ulid::isValid('not-a-ulid'))->toBeFalse()
        ->and(Ulid::isValid('IIIIIIIIIIIIIIIIIIIIIIIIII'))->toBeFalse();
});
