<?php

// SPDX-FileCopyrightText: 2026 Peter Reichartz
// SPDX-License-Identifier: GPL-3.0-only

declare(strict_types=1);

use ILIAS\UI\Component\Input\Container\Form\Standard as Form;

/**
 * Persönliche Leiste anpassen: Nutzer schalten Favoriten und eigene Links ein oder aus und
 * wählen bis zu vier eigene Links aus Objekten, auf die sie zugreifen können.
 * Gespeichert wird in den Nutzereinstellungen (usr_pref), nicht in eigenen Tabellen.
 *
 * @ilCtrl_isCalledBy ilModDashboardPersonalGUI: ilUIPluginRouterGUI
 */
class ilModDashboardPersonalGUI
{
    /** Obergrenzen, damit die Auswahl auch in großen Kursen schnell bleibt */
    private const MAX_CANDIDATES = 300;
    private const MAX_NODES = 1500;
    private const MAX_CHECKS = 2000;
    private const SKIP_TYPES = ['root', 'adm', 'usrf', 'rolf', 'itgr', 'objf', 'recf', 'mail'];

    private ilModDashboardPlugin $pl;

    public function __construct()
    {
        global $DIC;
        /** @var ilModDashboardPlugin $pl */
        $pl = $DIC['component.factory']->getPlugin('moddash');
        $this->pl = $pl;
    }

    public function executeCommand(): void
    {
        global $DIC;
        $user = $DIC->user();
        $roles = $this->pl->targetRoles();
        if (!$this->pl->isActive() || $this->pl->personalParts() === [] || $user->getId() === ANONYMOUS_USER_ID
            || ($roles !== [] && !$DIC->rbac()->review()->isAssignedToAtLeastOneGivenRole($user->getId(), $roles))) {
            $this->toDashboard();
        }

        $cmd = $DIC->ctrl()->getCmd('show');
        $form = $this->form();
        if ($cmd === 'save') {
            $form = $form->withRequest($DIC->http()->request());
            $data = $form->getData();
            if ($data !== null) {
                $prefs = $this->pl->personalPrefs($user);
                $parts = $this->pl->personalParts();
                if (in_array('favourites', $parts, true)) {
                    $prefs['fav'] = (bool) ($data['fav'] ?? false);
                }
                if (in_array('own', $parts, true)) {
                    $prefs['own'] = (bool) ($data['own'] ?? false);
                    $links = [];
                    for ($i = 1; $i <= ilModDashboardPlugin::MAX_PERSONAL_LINKS; $i++) {
                        $v = $data['link' . $i] ?? null;
                        if ($v !== null && $v !== '') {
                            $links[] = (int) $v;
                        }
                    }
                    $prefs['links'] = $links;
                }
                $this->pl->setPersonalPrefs($user, $prefs);
                $DIC->ui()->mainTemplate()->setOnScreenMessage('success', $this->pl->txt('mine_saved'), true);
                $this->toDashboard();
            }
        }

        $tpl = $DIC->ui()->mainTemplate();
        $tpl->loadStandardTemplate();
        $tpl->setTitle($this->pl->txt('mine_title'));
        $tpl->setDescription($this->pl->txt('mine_description'));
        $DIC->tabs()->setBackTarget($DIC->language()->txt('back'), $this->dashboardUrl());
        $tpl->setContent($DIC->ui()->renderer()->render($form));
        $tpl->printToStdout();
    }

    private function form(): Form
    {
        global $DIC;
        $f = $DIC->ui()->factory()->input()->field();
        $parts = $this->pl->personalParts();
        $prefs = $this->pl->personalPrefs($DIC->user());
        $inputs = [];

        if (in_array('favourites', $parts, true)) {
            $inputs['fav'] = $f->checkbox($this->pl->txt('mine_use_fav'), $this->pl->txt('mine_use_fav_info'))
                ->withValue($prefs['fav']);
        }
        if (in_array('own', $parts, true)) {
            $inputs['own'] = $f->checkbox($this->pl->txt('mine_use_own'), $this->pl->txt('mine_use_own_info'))
                ->withValue($prefs['own']);
            $options = $this->candidates();
            for ($i = 1; $i <= ilModDashboardPlugin::MAX_PERSONAL_LINKS; $i++) {
                $current = (string) ($prefs['links'][$i - 1] ?? '');
                $select = $f->select(sprintf($this->pl->txt('mine_link_n'), $i), $options);
                if ($current !== '' && isset($options[$current])) {
                    $select = $select->withValue($current);
                }
                $inputs['link' . $i] = $select;
            }
        }

        return $DIC->ui()->factory()->input()->container()->form()->standard(
            $DIC->ctrl()->getFormAction($this, 'save'),
            $inputs
        );
    }

    /**
     * Wählbare Objekte: Kurse und Gruppen, in denen der Nutzer Mitglied ist, samt lesbarem Inhalt,
     * dazu Favoriten und zuletzt besuchte Objekte. Schlüssel = ref_id.
     * @return array<string,string>
     */
    private function candidates(): array
    {
        global $DIC;
        $user_id = $DIC->user()->getId();
        $tree = $DIC->repositoryTree();
        $access = $DIC->access();
        $lng = $DIC->language();
        $refs = [];

        // Bereits gewählte Links zuerst, damit sie auswählbar bleiben, solange der Zugriff besteht
        foreach ($this->pl->personalPrefs($DIC->user())['links'] as $ref) {
            $refs[$ref] = 0;
        }
        foreach (ilParticipants::_getMembershipByType($user_id, ['crs', 'grp']) as $obj_id) {
            foreach (ilObject::_getAllReferences((int) $obj_id) as $ref) {
                $ref = (int) $ref;
                if (!$tree->isInTree($ref)) {
                    continue;
                }
                $refs[$ref] = $ref;
                $nodes = 0;
                foreach ($tree->getSubTree($tree->getNodeData($ref)) as $node) {
                    if (++$nodes > self::MAX_NODES) {
                        break;
                    }
                    $refs[(int) $node['child']] ??= $ref;
                }
            }
        }
        foreach ((new ilFavouritesManager())->getFavouritesOfUser($user_id) as $fav) {
            $refs[(int) $fav['ref_id']] ??= 0;
        }
        foreach ($DIC['ilNavigationHistory']->getItems() as $it) {
            $refs[(int) $it['ref_id']] ??= 0;
        }

        $options = [];
        $checks = 0;
        foreach ($refs as $ref => $container) {
            if (count($options) >= self::MAX_CANDIDATES || ++$checks > self::MAX_CHECKS) {
                break;
            }
            if ($ref <= 0 || !$tree->isInTree($ref)) {
                continue;
            }
            $obj = ilObject::_lookupObjId($ref);
            $type = ilObject::_lookupType($obj);
            if (in_array($type, self::SKIP_TYPES, true) || !$access->checkAccess('read', '', $ref)) {
                continue;
            }
            $label = ilObject::_lookupTitle($obj) . ' (' . $lng->txt('obj_' . $type);
            if ($container > 0 && $container !== $ref) {
                $label .= ' · ' . ilObject::_lookupTitle(ilObject::_lookupObjId($container));
            }
            $options[(string) $ref] = $label . ')';
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    private function dashboardUrl(): string
    {
        global $DIC;
        return $DIC->ctrl()->getLinkTargetByClass([ilDashboardGUI::class], 'show');
    }

    private function toDashboard(): never
    {
        global $DIC;
        $DIC->ctrl()->redirectToURL($this->dashboardUrl());
        exit;
    }
}
