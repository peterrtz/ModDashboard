<?php

// SPDX-FileCopyrightText: 2026 Peter Reichartz
// SPDX-License-Identifier: GPL-3.0-only

declare(strict_types=1);

use ILIAS\UI\Component\Input\Container\Form\Standard as Form;

/**
 * Konfiguration: Zielgruppe (globale und lokale Rollen), Anrede, Seitentitel, Farben, Bereiche,
 * Weitermachen, Schnellzugriffe mit eigenen Links und Icons, persönliche Leiste.
 *
 * @ilCtrl_IsCalledBy ilModDashboardConfigGUI: ilObjComponentSettingsGUI
 */
class ilModDashboardConfigGUI extends ilPluginConfigGUI
{
    public function performCommand(string $cmd): void
    {
        global $DIC;
        $form = $this->form();

        if ($cmd === 'save') {
            $form = $form->withRequest($DIC->http()->request());
            $data = $form->getData();
            if ($data !== null) {
                /** @var ilModDashboardPlugin $pl */
                $pl = $this->getPluginObject();
                $pl->setConf('address', $data['look']['address']);
                $pl->setConf('hide_title', (bool) $data['look']['hide_title']);
                $pl->setConf('roles', array_map('intval', $data['audience']['roles'] ?? []));
                $pl->setConf('local_roles', $pl->localRoleIds((array) ($data['audience']['local_roles'] ?? [])));
                [$mode, $colors] = $data['look']['colors'];
                $pl->setConf('colors', ['mode' => $mode === 'custom' ? 'custom' : 'design']
                    + ($mode === 'custom' ? ilModDashboardPlugin::normalizeColors((array) $colors) : (array) $pl->conf('colors')));
                $pl->setConf('sections', array_values($data['content']['sections'] ?? []));
                $pl->setConf('resume_types', array_values($data['content']['resume_types'] ?? []));
                $pl->setConf('resume_empty', $data['content']['resume_empty'] === 'hide' ? 'hide' : 'hint');
                $pl->setConf('quick', array_values($data['quick']['quick'] ?? []));
                $pl->setConf('custom', trim((string) $data['quick']['custom']));
                $pl->setConf('icon_library', (bool) ($data['quick']['icon_library'] ?? false));
                $pl->setConf('personal', array_values(array_intersect(['favourites', 'own'], (array) ($data['personal']['personal'] ?? []))));
                $DIC->ui()->mainTemplate()->setOnScreenMessage('success', $DIC->language()->txt('saved_successfully'), true);
                $DIC->ctrl()->redirect($this, 'configure');
            }
        }

        $DIC->ui()->mainTemplate()->setContent($DIC->ui()->renderer()->render($form));
    }

    /**
     * Lokale Rollen als Tag-Feld. Das Tag-Feld von ILIAS 10 kennt keine Suche am Server:
     * Die lokalen Rollen (höchstens 3000) werden mitgegeben und beim Tippen im Browser gefiltert.
     * @param list<string> $selected
     */
    private function localRolesInput($f, ilModDashboardPlugin $pl, array $selected): \ILIAS\UI\Component\Input\Field\Tag
    {
        $all = array_filter(array_map(fn(int $id) => $pl->localRoleLabel($id), $pl->searchLocalRoles('', 3000)));
        return $f->tag($pl->txt('cfg_local_roles'), array_values(array_unique(array_merge($selected, $all))), $pl->txt('cfg_local_roles_info'))
            ->withSuggestionsStartAfter(2)
            ->withUserCreatedTagsAllowed(false)
            ->withValue($selected);
    }

    private function form(): Form
    {
        global $DIC;
        /** @var ilModDashboardPlugin $pl */
        $pl = $this->getPluginObject();
        $f = $DIC->ui()->factory()->input()->field();

        $roles = [];
        foreach ($DIC->rbac()->review()->getGlobalRoles() as $id) {
            if ((int) $id !== SYSTEM_ROLE_ID && (int) $id !== ANONYMOUS_ROLE_ID) {
                $roles[(string) $id] = ilObjRole::_getTranslation(ilObject::_lookupTitle((int) $id));
            }
        }
        $opts = static fn(string $prefix, array $keys): array => array_combine($keys, array_map(fn($k) => $pl->txt($prefix . $k), $keys));

        // Lokale Rollen: Suche mit Autovervollständigung (wie bei Registrierungscodes), nicht alle auflisten
        $local = array_map(fn(int $id) => $pl->localRoleLabel($id), (array) $pl->conf('local_roles'));

        // Farben: aus dem Design (Skin) oder eigene Werte; eigene Werte werden auf Format und Kontrast geprüft
        $colors = (array) $pl->conf('colors') + ilModDashboardPlugin::COLOR_DEFAULTS;
        $color_check = $DIC->refinery()->custom()->constraint(
            fn($v) => !is_array($v) || ($v[0] ?? '') !== 'custom' || ilModDashboardPlugin::colorProblems(ilModDashboardPlugin::normalizeColors((array) ($v[1] ?? []))) === [],
            fn($txt, $v) => implode(' ', array_map(
                fn($k) => $pl->txt($k),
                ilModDashboardPlugin::colorProblems(ilModDashboardPlugin::normalizeColors((array) ($v[1] ?? [])))
            ))
        );
        $pickers = [];
        foreach (array_keys(ilModDashboardPlugin::COLOR_DEFAULTS) as $k) {
            // ILIAS 10 hat keinen Farbwähler im UI-Framework: Textfeld für #RRGGBB, geprüft wie oben
            $pickers[$k] = $f->text($pl->txt('cfg_color_' . $k), $pl->txt('cfg_color_' . $k . '_info') . ' (#RRGGBB)')
                ->withMaxLength(7)
                ->withValue((string) $colors[$k]);
        }

        $custom_info = sprintf($pl->txt('cfg_custom_info'), ilModDashboardPlugin::MAX_CUSTOM_LINKS, implode(', ', $pl->listedIcons()));
        if ($pl->hasPhosphorLibrary()) {
            $custom_info .= ' ' . sprintf($pl->txt('cfg_custom_info_library'), ilModDashboardPlugin::PHOSPHOR_URL);
        }
        $resume_types = $pl->resumeTypeOptions();

        $custom_check = $DIC->refinery()->custom()->constraint(
            fn($v) => $pl->invalidCustomLines((string) $v) === [],
            fn($txt, $v) => sprintf($pl->txt('cfg_custom_invalid'), implode(', ', $pl->invalidCustomLines((string) $v)))
        );

        // Vier Abschnitte: Wer sieht es – wie spricht es an – was zeigt es – wohin führt es
        return $DIC->ui()->factory()->input()->container()->form()->standard(
            $DIC->ctrl()->getFormAction($this, 'save'),
            [
                'audience' => $f->section([
                    'roles' => $f->multiSelect($pl->txt('cfg_roles'), $roles, $pl->txt('cfg_roles_info'))
                        ->withValue(array_map('strval', array_intersect(array_map('strval', (array) $pl->conf('roles')), array_keys($roles)))),
                    // Bereits gewählte Rollen als bekannte Tags übergeben – sonst verwirft das
                    // Tag-Feld sie beim Absenden, weil eigene Tags nicht erlaubt sind.
                    'local_roles' => $this->localRolesInput($f, $pl, array_values(array_filter($local))),
                ], $pl->txt('cfg_sec_audience'), $pl->txt('cfg_sec_audience_info')),
                'look' => $f->section([
                    'address' => $f->radio($pl->txt('cfg_address'))
                        ->withOption('du', $pl->txt('cfg_opt_du'))
                        ->withOption('sie', $pl->txt('cfg_opt_sie'))
                        ->withValue((string) $pl->conf('address')),
                    'hide_title' => $f->checkbox($pl->txt('cfg_hide_title'), $pl->txt('cfg_hide_title_info'))
                        ->withValue((bool) $pl->conf('hide_title')),
                    'colors' => $f->switchableGroup([
                        'design' => $f->group([], $pl->txt('cfg_colors_design'), $pl->txt('cfg_colors_design_info')),
                        'custom' => $f->group($pickers, $pl->txt('cfg_colors_custom'), $pl->txt('cfg_colors_custom_info')),
                    ], $pl->txt('cfg_colors'))
                        ->withValue(($colors['mode'] ?? 'design') === 'custom' ? 'custom' : 'design')
                        ->withAdditionalTransformation($color_check),
                ], $pl->txt('cfg_sec_look')),
                'content' => $f->section([
                    'sections' => $f->multiSelect($pl->txt('cfg_sections'), $opts('cfg_opt_', ['deadline', 'recent', 'progress']), $pl->txt('cfg_sections_info'))
                        ->withValue((array) $pl->conf('sections')),
                    'resume_types' => $f->multiSelect($pl->txt('cfg_resume_types'), $resume_types, $pl->txt('cfg_resume_types_info'))
                        ->withValue(array_values(array_intersect((array) $pl->conf('resume_types'), array_keys($resume_types)))),
                    'resume_empty' => $f->radio($pl->txt('cfg_resume_empty'))
                        ->withOption('hint', $pl->txt('cfg_resume_empty_hint'), $pl->txt('cfg_resume_empty_hint_info'))
                        ->withOption('hide', $pl->txt('cfg_resume_empty_hide'), $pl->txt('cfg_resume_empty_hide_info'))
                        ->withValue($pl->conf('resume_empty') === 'hide' ? 'hide' : 'hint'),
                ], $pl->txt('cfg_sec_content')),
                'quick' => $f->section([
                    'quick' => $f->multiSelect($pl->txt('cfg_quick'), $opts('cfg_q_', ['courses', 'calendar', 'progress', 'repository']), $pl->txt('cfg_quick_info'))
                        ->withValue((array) $pl->conf('quick')),
                    'custom' => $f->textarea($pl->txt('cfg_custom'), $custom_info)
                        ->withValue((string) $pl->conf('custom'))->withAdditionalTransformation($custom_check),
                ] + ($pl->hasPhosphorLibrary() ? [
                    'icon_library' => $f->checkbox($pl->txt('cfg_icon_library'), sprintf($pl->txt('cfg_icon_library_info'), ilModDashboardPlugin::PHOSPHOR_URL))
                        ->withValue((bool) $pl->conf('icon_library')),
                ] : []), $pl->txt('cfg_sec_quick')),
                'personal' => $f->section([
                    'personal' => $f->multiSelect($pl->txt('cfg_personal'), [
                        'favourites' => $pl->txt('cfg_personal_favourites'),
                        'own' => sprintf($pl->txt('cfg_personal_own'), ilModDashboardPlugin::MAX_PERSONAL_LINKS),
                    ], $pl->txt('cfg_personal_info'))
                        ->withValue(array_values(array_intersect(['favourites', 'own'], (array) $pl->conf('personal')))),
                ], $pl->txt('cfg_sec_personal'), $pl->txt('cfg_sec_personal_info')),
            ]
        );
    }
}
