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
            if ($sexPlace || $sex) $paren[] = trim($sexPlace . $sex);
            if ($catPlace || $cat) $paren[] = trim($catPlace . $cat);
            $placeLine = trim(($icon ? $icon . ' ' : '') . 'Place : ' . ($place ?: '-') . (count($paren) ? ' (' . implode(' - ', $paren) . ')' : '') . ' / Temps : ' . (self::clean($r['time'] ?? '') ?: '-'));
            $event = self::clean($r['event'] ?? '');
            $distance = self::clean($r['distance'] ?? '');
            $type = self::clean($r['type'] ?? '');
            $meta = trim(implode(' - ', array_filter([$distance, $type])));
            $title = $event . ($meta ? ' (' . $meta . ')' : '');
            $date = self::clean($r['date'] ?? '');
            $blocks[] = trim($title . "\n" . $date . "\n" . $placeLine);
        }
        return implode("\n\n", array_filter($blocks));
    }

    private static function icon(string $place): string
    {
        $n = (int)preg_replace('/\D+/', '', $place);
        return match ($n) {
            1 => '🏆',
            2 => '🥈',
            3 => '🥉',
            default => '',
        };
    }

    private static function clean($v): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
