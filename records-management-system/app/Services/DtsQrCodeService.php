<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DtsQrCodeService
{
    /**
     * Split a string into first character, center characters, and last character.
     */
    public static function splitString(string $str): array
    {
        $len = strlen($str);
        if ($len === 0) {
            return ['first' => '', 'center' => '', 'last' => ''];
        }
        if ($len === 1) {
            return ['first' => $str, 'center' => '', 'last' => ''];
        }
        if ($len === 2) {
            return ['first' => $str[0], 'center' => '', 'last' => $str[1]];
        }
        return [
            'first'  => $str[0],
            'center' => substr($str, 1, -1),
            'last'   => $str[$len - 1],
        ];
    }

    /**
     * Hacore scramble combination function.
     */
    public static function combine(string $a, string $b): string
    {
        $lenA = strlen($a);
        $lenB = strlen($b);

        if ($lenA === 0) return $b;
        if ($lenB === 0) return $a;

        if ($lenA === 1 && $lenB === 1) {
            if ($a === $b) return $a;
            $aIsAlpha = ctype_alpha($a);
            $bIsAlpha = ctype_alpha($b);
            if ($aIsAlpha && !$bIsAlpha) return $a . $b;
            if (!$aIsAlpha && $bIsAlpha) return $b . $a;
            return $a . $b;
        }

        if ($lenA >= $lenB) {
            $L = $a;
            $S = $b;
        } else {
            $L = $b;
            $S = $a;
        }

        $lenL = strlen($L);
        $lenS = strlen($S);

        if ($lenL === $lenS && $lenL % 2 === 0) {
            $res = '';
            for ($i = 0; $i < $lenL; $i++) {
                $res .= $a[$i] . $b[$i];
            }
            return $res;
        }

        $splitA = static::splitString($a);
        $splitB = static::splitString($b);
        $splitL = $lenA >= $lenB ? $splitA : $splitB;
        $splitS = $lenA >= $lenB ? $splitB : $splitA;

        // Apply deduplication rule specifically for MONTH + (YEAR + TYPE)
        if ($lenS === 3 && $lenL === 8 && $splitS['first'] === 'M' && str_starts_with($splitL['center'], 'M')) {
            $splitL['center'] = substr($splitL['center'], 1);
        }

        $part1 = $splitA['first'] . $splitB['first'];
        $part3 = $splitL['last'] . $splitS['last'];

        $L_center = $splitL['center'];
        $S_center = $splitS['center'];

        $part2 = '';
        if ($L_center !== '' || $S_center !== '') {
            $lenLc = strlen($L_center);
            if ($lenLc > 0 && $lenLc % 2 === 0) {
                $mid = (int)($lenLc / 2);
                $left = substr($L_center, 0, $mid);
                $right = substr($L_center, $mid);
                $part2 = $left . $S_center . $right;
            } else {
                $part2 = static::combine($L_center, $S_center);
            }
        }

        return $part1 . $part2 . $part3;
    }

    /**
     * Generate a Hacore scrambled QR code and optionally register it in dts_qr_code.
     *
     * @param string $type The document type code (e.g. OM, NM, MEMO)
     * @param string $seqNumber The sequence number or child identifier (e.g. 0001, 0001-1, 0001-2)
     * @param string|null $year Optional year (defaults to current year)
     * @param string|null $month Optional month (defaults to current month MMM)
     * @param bool $registerInDb Whether to insert/update in dts_qr_code
     * @return string Formatted 4-character hyphenated QR code string
     */
    public static function generate(string $type, string $seqNumber, ?string $year = null, ?string $month = null, bool $registerInDb = true): string
    {
        $transCode = strtoupper(trim($seqNumber));
        $month = strtoupper($month ?: now()->format('M'));
        $year = $year ?: now()->format('Y');
        $type = strtoupper($type);

        $rawCode = static::combine($transCode, static::combine($month, static::combine($year, $type)));

        $len = strlen($rawCode);
        $remainder = $len % 4;
        if ($remainder !== 0) {
            $rawCode .= str_repeat('0', 4 - $remainder);
        }

        $formatted = implode('-', str_split($rawCode, 4));

        if ($registerInDb) {
            DB::table('dts_qr_code')->updateOrInsert(
                ['code_id' => $formatted],
                [
                    'qr_status' => 'used',
                    'created_at' => now(),
                ]
            );
        }

        return $formatted;
    }
}
