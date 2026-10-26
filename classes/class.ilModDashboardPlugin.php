<?php

// SPDX-FileCopyrightText: 2026 Peter Reichartz
// SPDX-License-Identifier: GPL-3.0-only

declare(strict_types=1);

/**
 * Willkommensbereich für das Dashboard (UserInterfaceHook).
 * Hier: Einstellungen (ilSetting-Modul "moddash") und gemeinsame Helfer.
 * Die Darstellung steckt in ilModDashboardUIHookGUI, die Pflege in ilModDashboardConfigGUI.
 */
class ilModDashboardPlugin extends ilUserInterfaceHookPlugin
{
    /** Mitgelieferte Icons (Phosphor, regular), die für eigene Links wählbar sind */
    public const LINK_ICONS = [
        'arrow-square-out', 'book-open-text', 'books', 'calendar-check', 'calendar-dots', 'certificate',
        'chats-circle', 'compass', 'first-aid-kit', 'folder-open', 'graduation-cap', 'heartbeat',
        'house', 'lifebuoy', 'question', 'users-three',
    ];
    public const MAX_CUSTOM_LINKS = 4;

    /** Eigene Farben (nur #RRGGBB); weitere Töne werden daraus abgeleitet */
    public const COLOR_DEFAULTS = [
        'base' => '#4C6586',    // Kopfbereich, Verlauf wird abgedunkelt
        'accent' => '#3A5677',  // Buttons und Fortschritt
        'urgent' => '#F2C14E',  // Kante bei Fristen unter 48 Stunden
        'soft' => '#EEF2F6',    // Hintergrund „Weitermachen“
    ];

    /**
     * Eigene Icon-Bibliothek: SVG-Dateien in diesem Unterordner ergänzen die mitgelieferten Icons
     * oder ersetzen sie bei gleichem Namen. Alles wird lokal ausgeliefert (inline), es entsteht
     * keine Verbindung zu einem Icon-Dienst.
     */
    public const CUSTOM_ICON_DIR = 'icons-custom';
    private const ICON_NAME = '/^[a-z0-9][a-z0-9-]{0,63}$/';
    private const ICON_MAX_BYTES = 32768;
    private const SVG_ELEMENTS = ['svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect'];
    private const SVG_ATTRIBUTES = [
        'xmlns', 'viewbox', 'width', 'height', 'd', 'fill', 'fill-rule', 'fill-opacity', 'clip-rule', 'stroke',
        'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-opacity', 'opacity',
        'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'points', 'transform',
    ];

    /** @var array<string,string> */
    private array $svg_cache = [];

    private const DEFAULTS = [
        'address' => 'du',                                   // du | sie
        'roles' => [],                                       // globale Rollen; leer (und keine lokalen) = alle
        'local_roles' => [],                                 // lokale Rollen (obj_id), z. B. Kursmitglieder
        'hide_title' => true,                                // Seitentitel „Dashboard“ visuell ausblenden
        'colors' => ['mode' => 'design'] + self::COLOR_DEFAULTS, // design = Skin bzw. neutrale Standardwerte
        'sections' => ['recent', 'deadline', 'progress'],
        'quick' => ['calendar', 'progress', 'repository'],   // 'courses' ist optional
        'custom' => '',                                      // "Titel | URL | Icon" je Zeile
    ];

    private ?ilSetting $settings_cache = null;

    public function conf(string $key): mixed
    {
        $raw = $this->settings()->get($key, null);
        return $raw === null ? self::DEFAULTS[$key] : json_decode($raw, true);
    }

    public function setConf(string $key, mixed $value): void
    {
        $this->settings()->set($key, json_encode($value));
    }

    public function isFormal(): bool
    {
        return $this->conf('address') === 'sie';
    }

    /** @return list<array{label:string,href:string,icon:string}> */
    public function customLinks(): array
    {
        $links = [];
        foreach ($this->parseCustom((string) $this->conf('custom')) as $line) {
            if ($line['valid']) {
                $links[] = $line;
            }
        }
        return array_slice($links, 0, self::MAX_CUSTOM_LINKS);
    }

    /** @return list<int> Zeilennummern mit ungültigem Inhalt */
    public function invalidCustomLines(string $text): array
    {
        $bad = [];
        foreach ($this->parseCustom($text) as $line) {
            if (!$line['valid']) {
                $bad[] = $line['no'];
            }
        }
        return $bad;
    }

    /**
     * Erlaubt nur relative Adressen und http(s). Mit $same_host zusätzlich nur die eigene Installation.
     * Schließt javascript:, data:, protokoll-relative ("//", "/\") und Steuerzeichen aus.
     */
    public static function isSafeUrl(string $url, bool $same_host = false): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || str_starts_with($url, '//')) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        if (!isset($parts['scheme']) && !isset($parts['host'])) {
            return true;
        }
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        if (!$same_host) {
            return isset($parts['host']);
        }
        $own = (string) parse_url(ILIAS_HTTP_PATH, PHP_URL_HOST);
        return isset($parts['host']) && strcasecmp($parts['host'], $own) === 0;
    }

    /** @return list<string> für eigene Links wählbare Icons: mitgelieferte plus eigene Bibliothek */
    public function linkIcons(): array
    {
        $names = self::LINK_ICONS;
        foreach (glob($this->getDirectory() . '/' . self::CUSTOM_ICON_DIR . '/*.svg') ?: [] as $file) {
            $name = basename($file, '.svg');
            if (preg_match(self::ICON_NAME, $name)) {
                $names[] = $name;
            }
        }
        $names = array_values(array_unique($names));
        sort($names);
        return $names;
    }

    /**
     * Icon als bereinigtes Inline-SVG; eigene Bibliothek vor mitgelieferten Icons.
     * Nur Formelemente und Darstellungsattribute bleiben erhalten (kein Skript, keine
     * Event-Handler, keine Verweise nach außen). Ohne fill/stroke-Angabe erbt das Icon die Textfarbe.
     */
    public function svg(string $name, string $class): string
    {
        if (!preg_match(self::ICON_NAME, $name)) {
            return '';
        }
        $key = $name . '|' . $class;
        if (isset($this->svg_cache[$key])) {
            return $this->svg_cache[$key];
        }
        $svg = '';
        foreach ([self::CUSTOM_ICON_DIR, 'icons'] as $dir) {
            $file = $this->getDirectory() . '/' . $dir . '/' . $name . '.svg';
            if (is_file($file) && filesize($file) <= self::ICON_MAX_BYTES) {
                $svg = self::cleanSvg((string) file_get_contents($file), $class);
                if ($svg !== '') {
                    break;
                }
            }
        }
        return $this->svg_cache[$key] = $svg;
    }

    public static function cleanSvg(string $raw, string $class): string
    {
        $doc = new DOMDocument();
        if ($raw === '' || !@$doc->loadXML($raw, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return '';
        }
        $root = $doc->documentElement;
        if ($root === null || strtolower($root->localName) !== 'svg') {
            return '';
        }
        self::cleanNode($root);
        if (!$root->hasAttribute('fill') && !$root->hasAttribute('stroke')) {
            $root->setAttribute('fill', 'currentColor');
        }
        $root->removeAttribute('width');
        $root->removeAttribute('height');
        $root->setAttribute('class', $class);
        $root->setAttribute('aria-hidden', 'true');
        $root->setAttribute('focusable', 'false');
        return (string) $doc->saveXML($root);
    }

    private static function cleanNode(DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = strtolower($attr->nodeValue ?? '');
            if (!in_array($name, self::SVG_ATTRIBUTES, true) || str_contains($value, 'url(') || str_contains($value, 'javascript:')) {
                $el->removeAttributeNode($attr);
            }
        }
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->localName), self::SVG_ELEMENTS, true)) {
                self::cleanNode($child);
            } elseif (!($child instanceof DOMText && $el->localName !== 'svg')) {
                $el->removeChild($child);
            }
        }
    }

    /** @return list<int> alle konfigurierten Rollen (global und lokal); leer = für alle anzeigen */
    public function targetRoles(): array
    {
        return array_values(array_unique(array_merge(
            array_map('intval', (array) $this->conf('roles')),
            array_map('intval', (array) $this->conf('local_roles'))
        )));
    }

    /** Lokale Rollen, deren Titel (auch übersetzt) oder deren Kurs/Gruppe/Kategorie zum Suchbegriff passt */
    public function searchLocalRoles(string $term, int $limit = 20): array
    {
        global $DIC;
        $db = $DIC->database();
        $like = '%' . $term . '%';
        $db->setLimit($limit, 0);
        $res = $db->query(
            'SELECT DISTINCT r.obj_id FROM object_data r ' .
            'JOIN rbac_fa fa ON fa.rol_id = r.obj_id AND fa.assign = ' . $db->quote('y', 'text') . ' ' .
            'JOIN object_reference ref ON ref.ref_id = fa.parent AND ref.deleted IS NULL ' .
            'JOIN object_data c ON c.obj_id = ref.obj_id ' .
            'WHERE r.type = ' . $db->quote('role', 'text') . ' AND fa.parent <> ' . $db->quote(ROLE_FOLDER_ID, 'integer') . ' ' .
            'AND (' . $db->like('r.title', 'text', $like) . ' OR ' . $db->like('c.title', 'text', $like) . ') ' .
            'ORDER BY r.obj_id DESC'
        );
        $ids = [];
        while ($row = $db->fetchAssoc($res)) {
            $ids[] = (int) $row['obj_id'];
        }
        return $ids;
    }

    /** Anzeigename einer lokalen Rolle: „Kursmitglied – Kurstitel [obj_id]“; '' wenn keine lokale Rolle */
    public function localRoleLabel(int $role_id): string
    {
        global $DIC;
        if ($role_id <= 0 || ilObject::_lookupType($role_id) !== 'role' || $DIC->rbac()->review()->isGlobalRole($role_id)) {
            return '';
        }
        $container = $DIC->rbac()->review()->getObjectOfRole($role_id);
        return ilObjRole::_getTranslation(ilObject::_lookupTitle($role_id))
            . ' – ' . ($container ? ilObject::_lookupTitle($container) : '?') . ' [' . $role_id . ']';
    }

    /**
     * Rollen-IDs aus den Tags des Formulars („… [123]“); nur existierende lokale Rollen.
     * @param array<mixed> $tags
     * @return list<int>
     */
    public function localRoleIds(array $tags): array
    {
        $ids = [];
        foreach ($tags as $tag) {
            $tag = html_entity_decode(rawurldecode((string) $tag), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/\[(\d+)\]\s*$/', $tag, $m) && $this->localRoleLabel((int) $m[1]) !== '') {
                $ids[] = (int) $m[1];
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Farbwerte aus dem Formular (ILIAS\Data\Color oder Text) als #RRGGBB.
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    public static function normalizeColors(array $values): array
    {
        $out = [];
        foreach (array_keys(self::COLOR_DEFAULTS) as $k) {
            $v = $values[$k] ?? '';
            $out[$k] = strtoupper(is_object($v) && method_exists($v, 'asHex') ? (string) $v->asHex() : (string) $v);
        }
        return $out;
    }

    public static function isHexColor(mixed $v): bool
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1;
    }

    /** Kontrastverhältnis nach WCAG 2.x */
    public static function contrast(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $c = array_map(static function (string $p): float {
                $v = hexdec($p) / 255;
                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($hex, 1), 2));
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$l1, $l2] = [$lum($a), $lum($b)];
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** Farbe mit Schwarz (amount > 0) mischen */
    public static function shade(string $hex, float $amount): string
    {
        return '#' . implode('', array_map(
            static fn(string $p): string => sprintf('%02X', (int) round(hexdec($p) * (1 - $amount))),
            str_split(substr($hex, 1), 2)
        ));
    }

    /**
     * Prüft eigene Farben auf Format und Lesbarkeit (WCAG AA, 4,5:1).
     * @param array<string,mixed> $c
     * @return list<string> Sprachvariablen der Probleme
     */
    public static function colorProblems(array $c): array
    {
        foreach (array_keys(self::COLOR_DEFAULTS) as $k) {
            if (!self::isHexColor($c[$k] ?? null)) {
                return ['cfg_color_invalid'];
            }
        }
        $p = [];
        if (self::contrast('#FFFFFF', $c['base']) < 4.5) {
            $p[] = 'cfg_color_low_base';
        }
        if (self::contrast('#FFFFFF', $c['accent']) < 4.5) {
            $p[] = 'cfg_color_low_accent';
        }
        if (self::contrast('#161616', $c['soft']) < 4.5) {
            $p[] = 'cfg_color_low_soft';
        }
        return $p;
    }

    /**
     * Custom Properties für das style-Attribut des Willkommensbereichs, '' im Modus „Aus dem Design“.
     * Nur geprüfte #RRGGBB-Werte – keine freie CSS-Eingabe, daher keine Möglichkeit, Code einzuschleusen.
     */
    public function colorStyle(): string
    {
        $c = (array) $this->conf('colors');
        if (($c['mode'] ?? 'design') !== 'custom' || self::colorProblems($c) !== []) {
            return '';
        }
        $vars = [
            'hero-1' => $c['base'], 'hero-2' => self::shade($c['base'], 0.3), 'hero-3' => self::shade($c['base'], 0.52),
            'accent' => $c['accent'], 'accent-dark' => self::shade($c['accent'], 0.25),
            'urgent' => $c['urgent'], 'soft' => $c['soft'], 'track' => self::shade($c['soft'], 0.2),
        ];
        return implode(';', array_map(fn($k, $v) => '--mdash-' . $k . ':' . strtoupper($v), array_keys($vars), $vars));
    }

    protected function afterUninstall(): void
    {
        $this->settings()->deleteAll();
    }

    /** @return list<array{no:int,label:string,href:string,icon:string,valid:bool}> */
    private function parseCustom(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $p = array_map('trim', explode('|', $line));
            $icon = $p[2] ?? '';
            $known = in_array($icon, $this->linkIcons(), true);
            $out[] = [
                'no' => $i + 1,
                'label' => $p[0],
                'href' => $p[1] ?? '',
                'icon' => $known ? $icon : 'arrow-square-out',
                'valid' => $p[0] !== '' && self::isSafeUrl($p[1] ?? '') && ($icon === '' || $known),
            ];
        }
        return $out;
    }

    private function settings(): ilSetting
    {
        return $this->settings_cache ??= new ilSetting('moddash');
    }
}
