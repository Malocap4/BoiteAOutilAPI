<?php
final class PalmaresFormatter
{
    public static function format(array $results, int $max = 8): string
    {
        $blocks = [];
        foreach (array_slice($results, 0, $max) as $r) {
            $place = self::clean($r['place'] ?? '');
            $icon = self::icon($place);

            $sexPlace = self::clean($r['sex_place'] ?? '');
            $catPlace = self::clean($r['cat_place'] ?? '');
            $sex = self::clean($r['sex'] ?? '');
            $cat = self::clean($r['category'] ?? '');

            $paren = [];
            if ($sexPlace !== '') {
                $paren[] = trim($sexPlace . $sex);
            }
            if ($catPlace !== '') {
                $paren[] = trim($catPlace . $cat);
            }

            $event = self::clean($r['event'] ?? '');
            $location = self::clean($r['location'] ?? $r['ville'] ?? $r['lieu'] ?? '');
            $title = $event . ($location !== '' ? ' (' . $location . ')' : '');

            $date = self::clean($r['date'] ?? '');
            $time = self::clean($r['time'] ?? '');

            $placeText = $place !== '' ? $place : '-';
            if ($paren) {
                $placeText .= ' (' . implode(' - ', $paren) . ')';
            }

            $placeLine = trim(($icon ? $icon . ' ' : '') . 'Place : ' . $placeText . ' / Temps : ' . ($time !== '' ? $time : '-'));

            if ($title === '' && $date === '' && $place === '' && $time === '') {
                continue;
            }
            $blocks[] = trim($title . "\n" . $date . "\n" . $placeLine);
        }
        return implode("\n\n", array_filter($blocks));
    }

    private static function icon(string $place): string
    {
        if (!preg_match('/\d+/', $place, $m)) return '';
        $n = (int)$m[0];
        return match ($n) {
            1 => '🏆',
            2 => '🥈',
            3 => '🥉',
            default => '',
        };
    }

    private static function clean($v): string
    {
        $v = html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $v = str_replace("\xC2\xA0", ' ', $v);
        return trim(preg_replace('/\s+/u', ' ', $v));
    }
}
