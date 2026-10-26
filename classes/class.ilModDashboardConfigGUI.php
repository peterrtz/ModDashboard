<?php

// SPDX-FileCopyrightText: 2026 Peter Reichartz
// SPDX-License-Identifier: GPL-3.0-only

declare(strict_types=1);

use ILIAS\Data\Factory as DataFactory;
use ILIAS\Filesystem\Stream\Streams;
use ILIAS\UI\Component\Input\Container\Form\Standard as Form;
use ILIAS\UI\URLBuilder;

/**
 * Konfiguration: Anrede, Zielgruppe (globale und lokale Rollen), Seitentitel, Bereiche,
 * Schnellzugriffe, eigene Links.
 *
 * @ilCtrl_IsCalledBy ilModDashboardConfigGUI: ilObjComponentSettingsGUI
 */
class ilModDashboardConfigGUI extends ilPluginConfigGUI
{
    public function performCommand(string $cmd): void
    {
        global $DIC;
        if ($cmd === 'roleSearch') {
            $this->roleSearch();
        }
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
                $pl->setConf('sections', array_values($data['content']['sections'] ?? []));
                $pl->setConf('quick', array_values($data['quick']['quick'] ?? []));
                $pl->setConf('custom', trim((string) $data['quick']['custom']));
                $DIC->ui()->mainTemplate()->setOnScreenMessage('success', $DIC->language()->txt('saved_successfully'), true);
                $DIC->ctrl()->redirect($this, 'configure');
            }
        }

        $DIC->ui()->mainTemplate()->setContent($DIC->ui()->renderer()->render($form));
    }

    /** Antwort für die Autovervollständigung: lokale Rollen, deren Titel oder Container zum Suchbegriff passt */
    private function roleSearch(): never
    {
        global $DIC;
        /** @var ilModDashboardPlugin $pl */
        $pl = $this->getPluginObject();
        $term = trim((string) $DIC->http()->wrapper()->query()->retrieve(
            'moddash_term',
            $DIC->refinery()->byTrying([$DIC->refinery()->kindlyTo()->string(), $DIC->refinery()->always('')])
        ));
        $out = [];
        if (mb_strlen($term) >= 2) {
            foreach ($pl->searchLocalRoles($term) as $id) {
                $label = $pl->localRoleLabel($id);
                $out[] = ['value' => rawurlencode($label), 'display' => $label, 'searchBy' => $label];
            }
        }
        $http = $DIC->http();
        $http->saveResponse($http->response()
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Streams::ofString((string) json_encode($out))));
        $http->sendResponse();
        $http->close();
        exit;
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
        [$search_url, $term_token] = (new URLBuilder((new DataFactory())->uri(
            ILIAS_HTTP_PATH . '/' . $DIC->ctrl()->getLinkTarget($this, 'roleSearch', '', true)
        )))->acquireParameter(['moddash'], 'term');
        $local = array_map(fn(int $id) => $pl->localRoleLabel($id), (array) $pl->conf('local_roles'));

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
                    'local_roles' => $f->tag($pl->txt('cfg_local_roles'), array_values(array_filter($local)), $pl->txt('cfg_local_roles_info'))
                        ->withAsyncAutocomplete($search_url, $term_token)
                        ->withSuggestionsStartAfter(2)
                        ->withUserCreatedTagsAllowed(false)
                        ->withValue(array_values(array_filter($local))),
                ], $pl->txt('cfg_sec_audience'), $pl->txt('cfg_sec_audience_info')),
                'look' => $f->section([
                    'address' => $f->radio($pl->txt('cfg_address'))
                        ->withOption('du', $pl->txt('cfg_opt_du'))
                        ->withOption('sie', $pl->txt('cfg_opt_sie'))
                        ->withValue((string) $pl->conf('address')),
                    'hide_title' => $f->checkbox($pl->txt('cfg_hide_title'), $pl->txt('cfg_hide_title_info'))
                        ->withValue((bool) $pl->conf('hide_title')),
                ], $pl->txt('cfg_sec_look')),
                'content' => $f->section([
                    'sections' => $f->multiSelect($pl->txt('cfg_sections'), $opts('cfg_opt_', ['deadline', 'recent', 'progress']), $pl->txt('cfg_sections_info'))
                        ->withValue((array) $pl->conf('sections')),
                ], $pl->txt('cfg_sec_content')),
                'quick' => $f->section([
                    'quick' => $f->multiSelect($pl->txt('cfg_quick'), $opts('cfg_q_', ['courses', 'calendar', 'progress', 'repository']), $pl->txt('cfg_quick_info'))
                        ->withValue((array) $pl->conf('quick')),
                    'custom' => $f->textarea(
                        $pl->txt('cfg_custom'),
                        sprintf($pl->txt('cfg_custom_info'), ilModDashboardPlugin::MAX_CUSTOM_LINKS, implode(', ', $pl->linkIcons()))
                    )->withValue((string) $pl->conf('custom'))->withAdditionalTransformation($custom_check),
                ], $pl->txt('cfg_sec_quick')),
            ]
        );
    }
}
