<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ModuleDisplay
 *
 * @copyright   Copyright (C) 2026
 * @license     GNU General Public License version 2 or later
 */

namespace Joomla\Plugin\System\ModuleDisplay\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\Module\AfterCleanModuleListEvent;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Menu\MenuItem;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\ModuleDisplay\Event\ResolveLayoutEvent;
use Joomla\Registry\Registry;

/**
 * Filters modules by the component, view and layout currently displayed.
 *
 * @since  1.0.0
 */
final class ModuleDisplay extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * Load the plugin's language files automatically.
     *
     * Kept alongside the explicit loadLanguage() call in the service provider:
     * removing either one leaves the form showing raw language keys.
     *
     * @var  boolean
     * @since 1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * The layout resolved for the current request, cached per request.
     *
     * @var  string|null
     * @since 1.1.2
     */
    private ?string $resolvedLayout = null;

    /**
     * Where core components keep their "Alternative Layout" setting.
     *
     * Keyed by "component:view", each entry names the table holding the record,
     * the column its settings live in and the key inside that JSON. Mind the
     * column: articles keep their parameters in "attribs", while categories
     * and most other tables use "params". Third-party components can add
     * themselves through the ResolveLayoutEvent instead of being listed here.
     *
     * @var  array<string, array{table: string, column: string, key: string}>
     * @since 2.0.0
     */
    private const LAYOUT_SOURCES = [
        'com_content:article' => [
            'table' => '#__content', 'column' => 'attribs', 'key' => 'article_layout',
        ],
        'com_content:category' => [
            'table' => '#__categories', 'column' => 'params', 'key' => 'category_layout',
        ],
        'com_contact:contact' => [
            'table' => '#__contact_details', 'column' => 'params', 'key' => 'contact_layout',
        ],
        'com_contact:category' => [
            'table' => '#__categories', 'column' => 'params', 'key' => 'category_layout',
        ],
        'com_newsfeeds:newsfeed' => [
            'table' => '#__newsfeeds', 'column' => 'params', 'key' => 'newsfeed_layout',
        ],
        'com_newsfeeds:category' => [
            'table' => '#__categories', 'column' => 'params', 'key' => 'category_layout',
        ],
    ];

    /**
     * Returns the events this plugin subscribes to.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareForm'   => 'onContentPrepareForm',
            'onAfterCleanModuleList' => 'onAfterCleanModuleList',
        ];
    }

    /**
     * Adds our fields to the module edit form.
     *
     * @param   PrepareFormEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentPrepareForm(PrepareFormEvent $event): void
    {
        if (!$this->getApplication()->isClient('administrator')) {
            return;
        }

        $form = $event->getForm();

        if (!$form instanceof Form || $form->getName() !== 'com_modules.module') {
            return;
        }

        // Prevent duplicate insertion.
        if ($form->getField('moduledisplay_enabled', 'params')) {
            return;
        }

        $xmlFile = \dirname(__DIR__, 2) . '/forms/moduledisplay.xml';

        if (!is_readable($xmlFile)) {
            return;
        }

        $xml = file_get_contents($xmlFile);

        if ($xml === false) {
            return;
        }

        $form->load($xml, true);
    }

    /**
     * Removes modules that do not apply to the page being displayed.
     *
     * @param   AfterCleanModuleListEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAfterCleanModuleList(AfterCleanModuleListEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app instanceof SiteApplication) {
            return;
        }

        $option = strtolower($app->getInput()->getCmd('option', ''));
        $view   = strtolower($app->getInput()->getCmd('view', ''));

        // Without a component and view there is nothing to filter.
        if ($option === '' || $view === '') {
            return;
        }

        // Only accept well-formed names; these are used to build paths later on.
        if (!preg_match('/^com_[a-z0-9_]+$/', $option) || !preg_match('/^[a-z0-9_-]+$/', $view)) {
            return;
        }

        $layout   = $this->resolveLayout($app);
        $filtered = [];

        foreach ($event->getModules() as $module) {
            $params = new Registry($module->params ?? '');

            // Modules without our filter enabled remain untouched.
            if (!$params->get('moduledisplay_enabled')) {
                $filtered[] = $module;

                continue;
            }

            $rules = $params->get('moduledisplay_rules', []);

            if ($this->matchesRules((array) $rules, $option, $view, $layout)) {
                $filtered[] = $module;
            }
        }

        $event->updateModules($filtered);
    }

    /**
     * Matches the configured component/view/layout combinations.
     *
     * Rules are stored as "component:view:layout" strings. An empty layout
     * part acts as a wildcard and matches the view regardless of the layout;
     * a rule naming a layout matches only that layout.
     *
     * Everything is compared case-insensitively: layout names originate from
     * file names and may be capitalised, while component and view names
     * arrive lowercased from the request.
     *
     * @param   array   $rules   The configured rules.
     * @param   string  $option  Component name.
     * @param   string  $view    View name.
     * @param   string  $layout  Layout name.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    private function matchesRules(array $rules, string $option, string $view, string $layout): bool
    {
        foreach ($rules as $rule) {
            if (\is_string($rule)) {
                $parts = explode(':', $rule);

                if (\count($parts) < 2) {
                    continue;
                }

                [$ruleComponent, $ruleView] = $parts;
                $ruleLayout = $parts[2] ?? '';
            } else {
                // Rules written by earlier versions: separate keys.
                $rule = (array) $rule;

                $ruleComponent = (string) ($rule['component'] ?? '');
                $ruleView      = (string) ($rule['view'] ?? '');
                $ruleLayout    = (string) ($rule['layout'] ?? '');
            }

            if (strcasecmp($ruleComponent, $option) !== 0 || strcasecmp($ruleView, $view) !== 0) {
                continue;
            }

            if ($ruleLayout === '' || strcasecmp($ruleLayout, $layout) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines the layout in use for the current request.
     *
     * Checked in order: the URL, other extensions via ResolveLayoutEvent, the
     * record's own "Alternative Layout" setting and the active menu item.
     * Falls back to "default".
     *
     * @param   SiteApplication  $app  The application.
     *
     * @return  string
     *
     * @since   1.0.2
     */
    private function resolveLayout(SiteApplication $app): string
    {
        if ($this->resolvedLayout !== null) {
            return $this->resolvedLayout;
        }

        return $this->resolvedLayout = $this->detectLayout($app);
    }

    /**
     * Works out the layout without any caching.
     *
     * @param   SiteApplication  $app  The application.
     *
     * @return  string
     *
     * @since   1.1.2
     */
    private function detectLayout(SiteApplication $app): string
    {
        /*
         * 1. Requested explicitly through the URL.
         *
         * Read as a string rather than through getCmd(), which strips the
         * colon out of values like "isails:start" and would leave us with
         * "isailsstart" - a layout name that matches nothing. The value is
         * validated by isValidLayout() below, so nothing unchecked gets
         * through.
         */
        $layout = $this->stripTemplatePrefix((string) $app->getInput()->getString('layout', ''));

        if ($this->isValidLayout($layout)) {
            return $layout;
        }

        // 2. Supplied by another extension through our event.
        $layout = $this->askOtherExtensions($app);

        if ($this->isValidLayout($layout)) {
            return $layout;
        }

        // 3. Set on the record itself (Options -> Alternative Layout).
        $layout = $this->getRecordLayout($app);

        if ($this->isValidLayout($layout)) {
            return $layout;
        }

        // 4. Set on the menu item, if that item really leads to this page.
        $active = $app->getMenu()?->getActive();

        if ($active instanceof MenuItem && $this->menuItemMatchesRequest($active, $app)) {
            $candidates = [(string) ($active->query['layout'] ?? '')];

            // The menu item may carry the component's own layout parameter.
            $source = $this->getLayoutSource($app);

            if ($source !== null) {
                $candidates[] = (string) $active->getParams()->get($source['key'], '');
            }

            foreach ($candidates as $candidate) {
                $candidate = $this->stripTemplatePrefix($candidate);

                if ($this->isValidLayout($candidate)) {
                    return $candidate;
                }
            }
        }

        return 'default';
    }

    /**
     * Checks whether the active menu item points at the page being displayed.
     *
     * A menu item may well use a different view than the page reached through
     * it - an article opened from a category blog, for instance - and its
     * layout must not leak into that page.
     *
     * @param   MenuItem         $active  The active menu item.
     * @param   SiteApplication  $app     The application.
     *
     * @return  boolean
     *
     * @since   1.0.2
     */
    private function menuItemMatchesRequest(MenuItem $active, SiteApplication $app): bool
    {
        $menuOption = (string) ($active->query['option'] ?? '');
        $menuView   = (string) ($active->query['view'] ?? '');

        if ($menuOption === '' || $menuView === '') {
            return false;
        }

        $option = $app->getInput()->getCmd('option', '');
        $view   = $app->getInput()->getCmd('view', '');

        if (strcasecmp($menuOption, $option) !== 0 || strcasecmp($menuView, $view) !== 0) {
            return false;
        }

        // Same view, possibly a different record: only trust an exact match.
        $menuId = (int) ($active->query['id'] ?? 0);
        $id     = $app->getInput()->getInt('id', 0);

        return $menuId === 0 || $id === 0 || $menuId === $id;
    }

    /**
     * Lets other extensions supply the layout for the current page.
     *
     * Components that keep their "Alternative Layout" setting somewhere else
     * can listen to the ResolveLayoutEvent and answer with addResult(). The
     * first usable answer wins.
     *
     * @param   SiteApplication  $app  The application.
     *
     * @return  string  The layout name, or an empty string.
     *
     * @since   2.0.0
     */
    private function askOtherExtensions(SiteApplication $app): string
    {
        $input = $app->getInput();

        $event = new ResolveLayoutEvent(
            ResolveLayoutEvent::EVENT_NAME,
            [
                'subject' => $app,
                'option'  => strtolower($input->getCmd('option', '')),
                'view'    => strtolower($input->getCmd('view', '')),
                'id'      => $input->getInt('id', 0),
            ]
        );

        try {
            $this->getDispatcher()->dispatch($event->getName(), $event);

            $results = $event->getArgument('result', []);
        } catch (\Throwable $e) {
            // A misbehaving listener must not take the page down with it.
            return '';
        }

        // The first usable answer wins.
        foreach ((array) $results as $result) {
            $layout = $this->stripTemplatePrefix((string) $result);

            if ($this->isValidLayout($layout)) {
                return $layout;
            }
        }

        return '';
    }

    /**
     * Returns where the current component keeps its layout setting.
     *
     * @param   SiteApplication  $app  The application.
     *
     * @return  array{table: string, key: string}|null
     *
     * @since   2.0.0
     */
    private function getLayoutSource(SiteApplication $app): ?array
    {
        $input = $app->getInput();

        $key = strtolower($input->getCmd('option', '')) . ':' . strtolower($input->getCmd('view', ''));

        return self::LAYOUT_SOURCES[$key] ?? null;
    }

    /**
     * Reads the "Alternative Layout" setting stored on the record being shown.
     *
     * Only components listed in LAYOUT_SOURCES are queried; for anything else
     * this returns an empty string and the menu item decides. Both the table
     * and the settings column come from that map, never from the request.
     *
     * @param   SiteApplication  $app  The application.
     *
     * @return  string  The layout name, or an empty string.
     *
     * @since   1.0.2
     */
    private function getRecordLayout(SiteApplication $app): string
    {
        $source = $this->getLayoutSource($app);

        if ($source === null) {
            return '';
        }

        $id = $app->getInput()->getInt('id', 0);

        if ($id <= 0) {
            return '';
        }

        try {
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select($db->quoteName($source['column']))
                ->from($db->quoteName($source['table']))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);

            $settings = $db->setQuery($query)->loadResult();
        } catch (\Throwable $e) {
            return '';
        }

        if (empty($settings)) {
            return '';
        }

        $layout = (new Registry($settings))->get($source['key'], '');

        return $this->stripTemplatePrefix((string) $layout);
    }

    /**
     * Removes the "<template>:" prefix Joomla stores with alternative layouts,
     * e.g. "isails:yacht" or "_:default".
     *
     * @param   string  $layout  The raw layout value.
     *
     * @return  string
     *
     * @since   1.0.2
     */
    private function stripTemplatePrefix(string $layout): string
    {
        $position = strrpos($layout, ':');

        return $position === false ? $layout : substr($layout, $position + 1);
    }

    /**
     * Checks whether a layout name is usable.
     *
     * @param   string  $layout  The layout name.
     *
     * @return  boolean
     *
     * @since   1.0.2
     */
    private function isValidLayout(string $layout): bool
    {
        return $layout !== '' && (bool) preg_match('/^[A-Za-z0-9_-]+$/', $layout);
    }
}
