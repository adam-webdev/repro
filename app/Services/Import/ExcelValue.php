<?php

namespace App\Services\Import;

use DateTimeImmutable;
use DateTimeInterface;

/** Helper konversi nilai sel Excel. */
class ExcelValue
{
    public static function header(mixed $v): string
    {
        $s = strtolower(trim((string) $v));
        $s = trim(preg_replace('/[^a-z0-9]+/', ' ', $s));

        return $s === '' ? '-' : $s;
    }

    public static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_float($v) && floor($v) == $v) {
            $v = (string) (int) $v;
        }
        $s = trim((string) $v);
        if ($s === '' || str_starts_with($s, '=') || in_array(strtoupper($s), ['#N/A', '#REF!', '#VALUE!'], true)) {
            return null;
        }

        return $s;
    }

    public static function num(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_bool($v) || $v === null) {
            return 0.0;
        }
        $s = trim((string) $v);
        if ($s === '' || $s === '-') {
            return 0.0;
        }
        // 1.234,5 -> 1234.5 ; 1,5 -> 1.5
        if (preg_match('/^-?[\d.]+,\d+$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }

        return is_numeric($s) ? (float) $s : 0.0;
    }

    public static function date(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_int($v) || is_float($v)) {
            if ($v > 20000 && $v < 80000) {
                return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int) $v.' days')->format('Y-m-d');
            }

            return null;
        }
        $s = trim((string) $v);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd.m.Y', 'j/n/Y', 'Y-m-d H:i:s'] as $f) {
            $d = DateTimeImmutable::createFromFormat('!'.$f, $s);
            if ($d && $d->format($f) === $s) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    public static function time(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('H:i:s');
        }
        if (is_int($v) || is_float($v)) {
            if ($v >= 0 && $v < 1.0001) {
                $sec = (int) round($v * 86400) % 86400;

                return sprintf('%02d:%02d:00', intdiv($sec, 3600), intdiv($sec % 3600, 60));
            }

            return null;
        }
        if (preg_match('/^(\d{1,2})[:.](\d{2})/', trim((string) $v), $m) && (int) $m[1] < 24) {
            return sprintf('%02d:%02d:00', $m[1], $m[2]);
        }

        return null;
    }

    public static function minutesBetween(?string $start, ?string $end): ?int
    {
        if (! $start || ! $end) {
            return null;
        }
        [$h1, $m1] = array_map('intval', explode(':', $start));
        [$h2, $m2] = array_map('intval', explode(':', $end));

        return (($h2 * 60 + $m2) - ($h1 * 60 + $m1) + 1440) % 1440;
    }
}
