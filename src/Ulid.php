<?php declare(strict_types=1);

namespace Rush\Tool\Schema;

use Rush\Tool\Schema\Exception\SchemaException;


final class Ulid
{
    private const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private static ?int $lastTime = null;

    private static ?string $lastRandom = null;


    public static function generate(): string
    {
        $time = (int) floor(microtime(true) * 1000);

        if (self::$lastTime !== null && $time <= self::$lastTime) {
            $time = self::$lastTime;
            $random = self::increment(self::$lastRandom ?? random_bytes(10));
        } else {
            $random = random_bytes(10);
        }

        self::$lastTime = $time;
        self::$lastRandom = $random;

        $timestamp = pack('J', $time);

        return self::encode(substr($timestamp, 2) . $random);
    }


    public static function isValid(string $value): bool
    {
        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) === 1;
    }


    public static function timestamp(string $value): int
    {
        $bytes = self::decode($value);
        $timestamp = substr($bytes, 0, 6);

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', str_repeat("\x00", 2) . $timestamp);

        return $unpacked[1];
    }


    private static function encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $bits = str_pad($bits, 130, '0', STR_PAD_LEFT);
        $value = '';

        foreach (str_split($bits, 5) as $chunk) {
            $value .= self::ENCODING[(int) bindec($chunk)];
        }

        return $value;
    }


    private static function decode(string $value): string
    {
        $bits = '';

        foreach (str_split(strtoupper($value)) as $char) {
            $index = strpos(self::ENCODING, $char);

            if ($index === false) {
                throw new SchemaException("schema: invalid ULID character '{$char}'");
            }

            $bits .= str_pad(decbin((int) $index), 5, "0", STR_PAD_LEFT);
        }

        $bits = substr($bits, 2);
        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            $bytes .= chr((int) bindec($chunk));
        }

        return $bytes;
    }


    private static function increment(string $random): string
    {
        for ($index = strlen($random) - 1; $index >= 0; $index--) {
            $byte = ord($random[$index]) + 1;

            if ($byte <= 255) {
                return substr($random, 0, $index) . chr($byte) . substr($random, $index + 1);
            }
        }

        throw new SchemaException('schema: ULID randomness overflow');
    }
}
