<?php

// SPDX-FileCopyrightText: 2026 Peter Reichartz
// SPDX-License-Identifier: GPL-3.0-only

declare(strict_types=1);

use ILIAS\DI\Container;

/**
 * Setzt einen Willkommensbereich über die Inhalte des Dashboards:
 * Begrüßung, nächste Frist, Weitermachen (angefangene Lernobjekte zuerst),
 * zuletzt angesehen und Schnellzugriffe.
 *
 * Einhängepunkt: Hook "template_get" auf components/ILIAS/Dashboard/tpl.dashboard.html.
 * Liest nur Core-Daten (Navigationshistorie, Lernfortschritt, read_event, abgeleitete Aufgaben).
 */
class ilModDashboardUIHookGUI extends ilUIHookPluginGUI
{
    private const DASHBOARD_TPL = 'components/ILIAS/Dashboard/tpl.dashboard.html';
    /** nie anzeigen */
    private const SKIP_TYPES = ['root', 'cat', 'adm', 'usrf', 'rolf', 'fold', 'itgr'];
    /** Kurse/Gruppen stehen ohnehin als Kacheln darunter – nur Notnagel für "Weitermachen" */
    private const CONTAINER_TYPES = ['crs', 'grp'];
    private const MAX_HISTORY = 10;
    private const MAX_RECENT = 3;
    private const SOON_SECONDS = 172800; // 48 h: Frist hervorheben
    /** Sprachvariablen mit eigener Sie-Fassung (Suffix _formal) */
    private const FORMAL_KEYS = ['lead_back', 'lead_new', 'empty', 'start'];

    private ilModDashboardPlugin $pl;
    private DateTimeZone $tz;
    private string $lang = 'de';

    public function getHTML(string $a_comp, string $a_part, array $a_par = []): array
    {
        if ($a_part !== 'template_get' || ($a_par['tpl_id'] ?? '') !== self::DASHBOARD_TPL) {
            return ['mode' => self::KEEP, 'html' => ''];
        }

        global $DIC;
        try {
            /** @var ilModDashboardPlugin $pl */
            $pl = $this->getPluginObject();
            $this->pl = $pl;
            $roles = $pl->targetRoles();
            if ($roles !== [] && !$DIC->rbac()->review()->isAssignedToAtLeastOneGivenRole($DIC->user()->getId(), $roles)) {
                return ['mode' => self::KEEP, 'html' => ''];
            }
            $html = $this->render($DIC);
            // Hinweis: ILIAS entfernt eigene Query-Parameter und hängt nur ?_v=<ILIAS-Version> an.
            // Nach Plugin-Updates kann der Browser altes CSS behalten (siehe README).
            $DIC->ui()->mainTemplate()->addCss($pl->getRelativeDirectory() . '/css/moddashboard.css');
            return ['mode' => self::PREPEND, 'html' => $html];
        } catch (Throwable $e) {
            // Das Dashboard darf am Plugin nie scheitern.
            $DIC->logger()->root()->warning('ModDashboard: ' . $e->getMessage());
            return ['mode' => self::KEEP, 'html' => ''];
        }
    }

    private function render(Container $dic): string
    {
        $user = $dic->user();
        $sections = (array) $this->pl->conf('sections');
        $this->tz = new DateTimeZone($user->getTimeZone() ?: date_default_timezone_get());
        $this->lang = $dic->language()->getLangKey();
        $lp_on = in_array('progress', $sections, true)
            && ilObjUserTracking::_enabledLearningProgress()
            && ilObjUserTracking::_hasLearningProgressLearner();

        $tpl = $this->pl->getTemplate('tpl.home.html');
        $now = new DateTimeImmutable('now', $this->tz);
        [$resume, $recent] = $this->pick($this->history($dic, $lp_on));

        // Begrüßung
        $hour = (int) $now->format('G');
        [$part, $icon] = match (true) {
            $hour >= 5 && $hour < 11 => ['morning', 'sun'],
            $hour >= 17 && $hour < 22 => ['evening', 'sun-horizon'],
            $hour >= 11 && $hour < 17 => ['day', 'sun'],
            default => ['day', 'moon'],
        };
        $name = trim($user->getFirstname());
        $date = $this->fmt($now->getTimestamp(), 'EEEE, d. MMMM', 'EEEE, MMMM d');
        if ($date !== '') {
            $tpl->setCurrentBlock('date');
            $tpl->setVariable('DAY_ICON', $this->icon($icon));
            $tpl->setVariable('DATE', $this->esc($date));
            $tpl->parseCurrentBlock();
        }
        $style = $this->pl->colorStyle();
        $tpl->setVariable('STYLE', $style !== '' ? ' style="' . $this->esc($style) . '"' : '');
        $tpl->setVariable('HIDE_TITLE', $this->pl->conf('hide_title') ? ' mdash--hide-title' : '');
        $tpl->setVariable('GREETING', $this->esc($this->t('greeting_' . $part) . ($name !== '' ? ', ' . $name : '')));
        $tpl->setVariable('LEAD', $this->esc($this->t($resume === null ? 'lead_new' : 'lead_back')));

        // Wochenbilanz – nur wenn es etwas zu feiern gibt
        if ($lp_on && ($done = $this->completedLastWeek($dic)) > 0) {
            $tpl->setCurrentBlock('week');
            $tpl->setVariable('WEEK_ICON', $this->icon('seal-check'));
            $tpl->setVariable('WEEK', $this->esc(sprintf($this->t($done === 1 ? 'week_one' : 'week_many'), $done)));
            $tpl->parseCurrentBlock();
        }

        // Nächste Frist – nur, wenn die Administration die Aufgaben auf dem Dashboard nicht abgeschaltet hat
        $tasks_on = (new ilDashboardSidePanelSettingsRepository())->isEnabled(ilDashboardSidePanelSettingsRepository::TASKS);
        if ($tasks_on && in_array('deadline', $sections, true) && ($due = $this->nextDeadline($dic)) !== null) {
            $tpl->setCurrentBlock('deadline');
            $tpl->setVariable('DUE_CLASS', $due['due'] - time() < self::SOON_SECONDS ? ' mdash__due--soon' : '');
            $tpl->setVariable('D_HREF', $this->esc($due['href']));
            $tpl->setVariable('DUE_ICON', $this->icon('clock-countdown'));
            $tpl->setVariable('TXT_DUE', $this->esc($this->t('due_next')));
            $tpl->setVariable('D_TITLE', $this->esc($due['title']));
            $tpl->setVariable('D_WHEN', $this->esc(
                $this->relDay($due['due']) . ', ' . $this->fmt($due['due'], 'EEE d. MMM, HH:mm', 'EEE, MMM d, h:mm a')
            ));
            $tpl->parseCurrentBlock();
        }

        // Weitermachen / Dein Start
        if ($resume !== null) {
            if (in_array('recent', $sections, true) && $recent !== []) {
                foreach ($recent as $item) {
                    $tpl->setCurrentBlock('more');
                    $tpl->setVariable('M_HREF', $this->esc($item['link']));
                    $tpl->setVariable('M_ICON', $this->esc($item['icon']));
                    $tpl->setVariable('M_TYPE', $this->esc($item['tname']));
                    $tpl->setVariable('M_TITLE', $this->esc($item['title']));
                    $tpl->setVariable('M_WHEN', $this->esc($item['seen'] > 0 ? $this->relDay($item['seen']) : ''));
                    $tpl->parseCurrentBlock();
                }
                $tpl->setCurrentBlock('more_list');
                $tpl->setVariable('TXT_ALSO', $this->esc($this->t('recent_also')));
                $tpl->parseCurrentBlock();
            }
            $this->progress($tpl, $resume);
            if ($resume['seen'] > 0) {
                $tpl->setCurrentBlock('c_when');
                $tpl->setVariable('C_WHEN', $this->esc($this->relDay($resume['seen'])));
                $tpl->parseCurrentBlock();
            }
            $tpl->setCurrentBlock('continue');
            $tpl->setVariable('TXT_CONTINUE', $this->esc($this->t('continue')));
            $tpl->setVariable('C_HREF', $this->esc($resume['link']));
            $tpl->setVariable('C_ICON', $this->esc($resume['icon']));
            $tpl->setVariable('C_TITLE', $this->esc($resume['title']));
            $tpl->setVariable('C_META', $this->esc($resume['meta']));
            $tpl->setVariable('PLAY_ICON', $this->icon('play-circle'));
            $tpl->setVariable('TXT_RESUME', $this->esc($this->t('resume')));
            $tpl->parseCurrentBlock();
        } else {
            $tpl->setCurrentBlock('empty');
            $tpl->setVariable('TXT_START', $this->esc($this->t('start')));
            $tpl->setVariable('TXT_EMPTY', $this->esc($this->t('empty')));
            $tpl->setVariable('E_HREF', $this->esc(ilLink::_getLink(ROOT_FOLDER_ID)));
            $tpl->setVariable('E_ICON', $this->icon('compass'));
            $tpl->setVariable('TXT_DISCOVER', $this->esc($this->t('quick_repository')));
            $tpl->parseCurrentBlock();
        }

        // Schnellzugriff
        $quick = $this->quickLinks($dic, $lp_on);
        foreach ($quick as $q) {
            $tpl->setCurrentBlock('quick');
            $tpl->setVariable('Q_HREF', $this->esc($q['href']));
            $tpl->setVariable('Q_ICON', $this->icon($q['icon']));
            $tpl->setVariable('Q_LABEL', $this->esc($q['label']));
            $tpl->parseCurrentBlock();
        }
        if ($quick !== []) {
            $tpl->setCurrentBlock('quick_nav');
            $tpl->setVariable('TXT_QUICK', $this->esc($this->t('quick_links')));
            $tpl->parseCurrentBlock();
        }

        return $tpl->get();
    }

    /**
     * Zuletzt besuchte, lesbare Objekte – je Objekt nur der jüngste Eintrag.
     * @return list<array{ref:int,type:string,tname:string,icon:string,title:string,link:string,meta:string,status:?int,pct:?int,seen:int}>
     */
    private function history(Container $dic, bool $lp_on): array
    {
        $usr = $dic->user()->getId();
        $def = $dic['objDefinition'];
        $plugins = $dic['component.repository'];
        $items = [];
        foreach ($dic['ilNavigationHistory']->getItems() as $it) {
            $ref = (int) $it['ref_id'];
            $type = (string) $it['type'];
            // getClassName() leer: Typ unbekannt, z. B. Objekt eines deinstallierten Plugins
            if (isset($items[$ref]) || in_array($type, self::SKIP_TYPES, true) || $def->getClassName($type) === ''
                || !$dic->access()->checkAccess('read', '', $ref)) {
                continue;
            }
            // Plugin-Typen über die Komponentenliste erkennen: isPluginTypeName() prüft nur das
            // Präfix "x" und übersieht Plugins wie "cshp". Inaktive Plugins: Objekt auslassen.
            if ($plugins->hasPluginId($type)) {
                if (!$plugins->getPluginById($type)->isActive()) {
                    continue;
                }
                $tname = ilObjectPlugin::lookupTxtById($type, 'obj_' . $type);
            } else {
                $tname = $dic->language()->txt('obj_' . $type);
            }
            if (str_starts_with($tname, '-') && str_ends_with($tname, '-')) {
                $tname = $dic->language()->txt('object'); // Sprachvariable fehlt im Fremd-Plugin
            }
            $obj = ilObject::_lookupObjId($ref);

            // Link aus der Historie nur, wenn er auf diese Installation zeigt – sonst Permalink.
            $link = (string) ($it['link'] ?? '');
            if (!ilModDashboardPlugin::isSafeUrl($link, true)) {
                $link = ilLink::_getLink($ref, $type);
            }

            $status = $pct = null;
            if ($lp_on && ilObjectLP::isSupportedObjectType($type) && ilObjectLP::getInstance($obj)->isActive()) {
                // false: keinen Neuberechnungslauf auf dem Dashboard anstoßen
                $status = ilLPStatus::_lookupStatus($obj, $usr, false);
                $pct = ilLPStatus::_lookupPercentage($obj, $usr);
                $pct = $pct === null ? null : (int) $pct;
            }

            $meta = $tname;
            $parent = $dic->repositoryTree()->checkForParentType($ref, 'crs')
                ?: $dic->repositoryTree()->checkForParentType($ref, 'grp');
            if ($parent > 0 && $parent !== $ref) {
                $meta = sprintf($this->t('type_in_container'), $meta, ilObject::_lookupTitle(ilObject::_lookupObjId($parent)));
            }

            $events = ilChangeEvent::_lookupReadEvents($obj, $usr);
            $items[$ref] = [
                'ref' => $ref,
                'type' => $type,
                'tname' => $tname,
                'icon' => $this->typeIcon($type),
                'title' => (string) $it['title'],
                'link' => $link,
                'meta' => $meta,
                'status' => $status,
                'pct' => $pct,
                'seen' => (int) ($events[0]['last_access'] ?? 0),
            ];
            if (count($items) >= self::MAX_HISTORY) {
                break;
            }
        }
        return array_values($items);
    }

    /**
     * Weitermachen: zuerst angefangene Lernobjekte, dann nicht abgeschlossene, dann das jüngste.
     * Kurse/Gruppen nur, wenn es sonst nichts gibt. Zuletzt angesehen: übrige Lernobjekte.
     * @return array{0:?array,1:list<array>}
     */
    private function pick(array $items): array
    {
        $learning = array_values(array_filter($items, fn($i) => !in_array($i['type'], self::CONTAINER_TYPES, true)));
        $pool = $learning ?: $items;
        $tests = [
            fn($i) => $i['status'] === ilLPStatus::LP_STATUS_IN_PROGRESS_NUM,
            fn($i) => $i['status'] !== ilLPStatus::LP_STATUS_COMPLETED_NUM,
            fn($i) => true,
        ];
        foreach ($tests as $test) {
            foreach ($pool as $item) {
                if ($test($item)) {
                    $rest = array_filter($learning, fn($i) => $i['ref'] !== $item['ref']);
                    return [$item, array_slice(array_values($rest), 0, self::MAX_RECENT)];
                }
            }
        }
        return [null, []];
    }

    private function progress(ilTemplate $tpl, array $item): void
    {
        $text = match ($item['status']) {
            ilLPStatus::LP_STATUS_COMPLETED_NUM => $this->t('lp_completed'),
            ilLPStatus::LP_STATUS_IN_PROGRESS_NUM => $item['pct'] > 0
                ? sprintf($this->t('lp_percent'), $item['pct'])
                : $this->t('lp_in_progress'),
            default => null,
        };
        if ($text === null) {
            return;
        }
        $pct = $item['status'] === ilLPStatus::LP_STATUS_COMPLETED_NUM ? 100 : (int) $item['pct'];
        if ($pct > 0) {
            $tpl->setCurrentBlock('c_bar');
            $tpl->setVariable('C_PCT', (string) min(100, $pct));
            $tpl->parseCurrentBlock();
        }
        $tpl->setCurrentBlock('c_progress');
        $tpl->setVariable('C_PROGRESS', $this->esc($text));
        $tpl->parseCurrentBlock();
    }

    /** @return ?array{title:string,href:string,due:int} */
    private function nextDeadline(Container $dic): ?array
    {
        $best = null;
        $now = time();
        foreach ($dic->task()->derived()->factory()->collector()->getEntries($dic->user()->getId()) as $task) {
            if ($task->getDeadline() > $now && ($best === null || $task->getDeadline() < $best->getDeadline())) {
                $best = $task;
            }
        }
        if ($best === null) {
            return null;
        }
        $href = $best->getUrl() ?: ($best->getRefId() > 0 ? ilLink::_getLink($best->getRefId()) : '');
        return [
            'title' => $best->getTitle(),
            'href' => ilModDashboardPlugin::isSafeUrl($href, true) ? $href : '#block_pdtasks_0',
            'due' => $best->getDeadline(),
        ];
    }

    /** Abgeschlossene Lernfortschritte der letzten 7 Tage */
    private function completedLastWeek(Container $dic): int
    {
        $db = $dic->database();
        $res = $db->queryF(
            'SELECT COUNT(*) cnt FROM ut_lp_marks WHERE usr_id = %s AND status = %s AND status_changed >= %s',
            ['integer', 'integer', 'timestamp'],
            [$dic->user()->getId(), ilLPStatus::LP_STATUS_COMPLETED_NUM, date('Y-m-d H:i:s', time() - 7 * 86400)]
        );
        return (int) ($db->fetchAssoc($res)['cnt'] ?? 0);
    }

    /** @return list<array{href:string,label:string,icon:string}> */
    private function quickLinks(Container $dic, bool $lp_on): array
    {
        $ctrl = $dic->ctrl();
        $on = (array) $this->pl->conf('quick');
        $links = [];
        if (in_array('courses', $on, true)
            && (new ilPDSelectedItemsBlockViewSettings($dic->user()))->enabledMemberships()) {
            $links[] = ['href' => '#block_dash_mem_0', 'label' => $this->t('quick_courses'), 'icon' => 'books'];
            // Damit der Sprung auch den Tastaturfokus mitnimmt
            $dic->ui()->mainTemplate()->addOnLoadCode(
                "document.getElementById('block_dash_mem_0')?.setAttribute('tabindex','-1');"
            );
        }
        if (in_array('calendar', $on, true) && ilCalendarSettings::_getInstance()->isEnabled()) {
            $links[] = [
                'href' => $ctrl->getLinkTargetByClass(ilDashboardGUI::class, 'jumpToCalendar'),
                'label' => $this->t('quick_calendar'), 'icon' => 'calendar-dots',
            ];
        }
        // Wie der Core: Lernfortschritt nur, wenn er für Lernende freigegeben ist
        if (in_array('progress', $on, true) && ilObjUserTracking::_enabledLearningProgress()
            && ilObjUserTracking::_hasLearningProgressLearner()) {
            $links[] = [
                // ILIAS 11: ilLPPersonalGUI, ILIAS 10: ilLearningProgressGUI
                'href' => $ctrl->getLinkTargetByClass([ilDashboardGUI::class, ilAchievementsGUI::class,
                    class_exists('ilLPPersonalGUI') ? 'ilLPPersonalGUI' : 'ilLearningProgressGUI']),
                'label' => $this->t('quick_progress'), 'icon' => 'chart-line-up',
            ];
        }
        if (in_array('repository', $on, true)) {
            $links[] = ['href' => ilLink::_getLink(ROOT_FOLDER_ID), 'label' => $this->t('quick_repository'), 'icon' => 'compass'];
        }
        return array_merge($links, $this->pl->customLinks());
    }

    /** Objekt-Icon; fällt auf das Komponenten-Icon zurück, wenn ein Plugin keinen gültigen Pfad liefert */
    private function typeIcon(string $type): string
    {
        $path = ilObject::_getIcon(0, 'small', $type);
        $file = ILIAS_ABSOLUTE_PATH . '/public/' . ltrim((string) preg_replace('/\?.*$/', '', $path), './');
        return is_file($file) ? $path : ilUtil::getImagePath('standard/icon_cmps.svg');
    }

    /** heute / gestern / morgen / vor n Tagen / in n Tagen / Datum */
    private function relDay(int $ts): string
    {
        $today = new DateTimeImmutable('today', $this->tz);
        $day = (new DateTimeImmutable('@' . $ts))->setTimezone($this->tz)->setTime(0, 0);
        $diff = (int) $today->diff($day)->format('%r%a');
        return match (true) {
            $diff === 0 => $this->t('rel_today'),
            $diff === 1 => $this->t('rel_tomorrow'),
            $diff === -1 => $this->t('rel_yesterday'),
            $diff > 1 && $diff <= 14 => sprintf($this->t('rel_in_days'), $diff),
            $diff < -1 && $diff >= -14 => sprintf($this->t('rel_days_ago'), -$diff),
            default => $this->fmt($ts, 'd. MMM', 'MMM d'),
        };
    }

    private function fmt(int $ts, string $pattern_de, string $pattern_other): string
    {
        if (!class_exists(IntlDateFormatter::class)) {
            return date('d.m.', $ts);
        }
        $pattern = $this->lang === 'de' ? $pattern_de : $pattern_other;
        $f = new IntlDateFormatter($this->lang, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $this->tz, null, $pattern);
        return (string) $f->format($ts);
    }

    private function t(string $key): string
    {
        return $this->pl->txt($this->pl->isFormal() && in_array($key, self::FORMAL_KEYS, true) ? $key . '_formal' : $key);
    }

    /** Icon inline (mitgeliefert oder eigene Bibliothek), damit es Textfarbe und Größe übernimmt */
    private function icon(string $name): string
    {
        return $this->pl->svg($name, 'mdash__icon');
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
