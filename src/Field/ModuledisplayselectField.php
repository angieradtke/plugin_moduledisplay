<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ModuleDisplay
 *
 * @copyright   Copyright (C) 2026
 * @license     GNU General Public License version 2 or later
 */

namespace Joomla\Plugin\System\ModuleDisplay\Field;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\GroupedlistField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\Filesystem\Folder;

/**
 * Component/View/Layout selection field.
 *
 * Values have the form "component:view:layout", e.g. "com_content:article:default"
 * for the base layout or "com_content:article:yacht" for an alternative layout.
 *
 * @since  1.0.0
 */
final class ModuledisplayselectField extends GroupedlistField
{
    /**
     * Field type.
     *
     * @var  string
     * @since 1.0.0
     */
    protected $type = 'Moduledisplayselect';

    /**
     * Sets up the field and normalises legacy values.
     *
     * Earlier versions stored the rules as objects with separate component,
     * view and layout properties. Joomla's select helper expects plain
     * "component:view:layout" strings, so legacy values are converted here.
     *
     * @param   \SimpleXMLElement  $element  The XML element of the field.
     * @param   mixed              $value    The form field value.
     * @param   string             $group    The form field group.
     *
     * @return  boolean
     *
     * @since   1.0.2
     */
    public function setup(\SimpleXMLElement $element, $value, $group = null): bool
    {
        return parent::setup($element, $this->normaliseValue($value), $group);
    }

    /**
     * Converts a stored value into a flat array of "component:view:layout" strings.
     *
     * @param   mixed  $value  The raw stored value.
     *
     * @return  array
     *
     * @since   1.0.2
     */
    private function normaliseValue($value): array
    {
        // Legacy: the whole set was stored as one JSON string.
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value   = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $normalised = [];

        foreach ($value as $rule) {
            // Current format: already a plain string, kept as stored.
            if (is_string($rule)) {
                $normalised[] = $rule;

                continue;
            }

            // Legacy format: object or array with separate keys.
            $rule = (array) $rule;

            $component = strtolower((string) ($rule['component'] ?? ''));
            $view      = strtolower((string) ($rule['view'] ?? ''));
            $layout    = (string) ($rule['layout'] ?? '');

            if ($component === '' || $view === '') {
                continue;
            }

            // A legacy rule without a layout applied to any layout.
            $normalised[] = $component . ':' . $view . ':' . $layout;
        }

        return $normalised;
    }

    /**
     * Builds the option groups, one group per component.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    protected function getGroups(): array
    {
        $groups = [];

        foreach ($this->discoverViews() as $component => $views) {
            if (empty($views)) {
                continue;
            }

            $options = [];

            foreach ($views as $view => $layouts) {
                // The view itself: matches any layout.
                $options[] = HTMLHelper::_(
                    'select.option',
                    $component . ':' . $view . ':',
                    $view
                );

                // One independent entry per alternative layout.
                foreach ($layouts as $layout) {
                    $options[] = HTMLHelper::_(
                        'select.option',
                        $component . ':' . $view . ':' . $layout,
                        $view . ' : ' . $layout
                    );
                }
            }

            $groups[$component] = $options;
        }

        return array_merge(parent::getGroups(), $groups);
    }

    /**
     * Returns the available component views and layouts.
     *
     * Scanning the whole filesystem on every module edit form would mean
     * hundreds of directory checks, so the result is cached. Clearing the
     * Joomla cache picks up newly added views, layouts or overrides.
     *
     * @return  array  component => [view => [layout, ...]]
     *
     * @since   1.1.0
     */
    private function discoverViews(): array
    {
        // Within one request the structure is only ever built once.
        static $memo = null;

        if ($memo !== null) {
            return $memo;
        }

        try {
            $cache = Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', ['defaultgroup' => 'plg_system_moduledisplay']);

            $cached = $cache->get(
                fn (): array => $this->scanViews(),
                [],
                'moduledisplay_structure'
            );

            $memo = is_array($cached) && $cached !== [] ? $cached : $this->scanViews();
        } catch (\Throwable $e) {
            // Caching is an optimisation, never a requirement.
            $memo = $this->scanViews();
        }

        return $memo;
    }

    /**
     * Scans the filesystem for component views and their alternative layouts.
     *
     * @return  array  component => [view => [layout, ...]]
     *
     * @since   1.0.0
     */
    private function scanViews(): array
    {
        $result = [];

        $componentsPath = JPATH_SITE . '/components';

        if (!is_dir($componentsPath)) {
            return [];
        }

        foreach (Folder::folders($componentsPath, '^com_[a-z0-9_]+$', false, true) as $componentPath) {
            $component = strtolower(basename($componentPath));

            // View classes: Joomla 4/5/6 MVC structure and the legacy one.
            foreach (['/src/View', '/views'] as $viewDir) {
                foreach ($this->listDirectories($componentPath . $viewDir) as $view) {
                    $result[$component][$view] ??= [];
                }
            }

            // Layouts shipped with the component itself (tmpl/<view>/*.php).
            $tmplPath = $componentPath . '/tmpl';

            foreach ($this->listDirectories($tmplPath) as $view) {
                $result[$component][$view] = array_merge(
                    $result[$component][$view] ?? [],
                    $this->listLayouts($tmplPath . '/' . $view)
                );
            }
        }

        $this->collectOverrides($result);

        foreach ($result as $component => $views) {
            foreach ($views as $view => $layouts) {
                $layouts = array_values(
                    array_intersect_key($layouts, array_unique(array_map('strtolower', $layouts)))
                );
                sort($layouts, SORT_NATURAL | SORT_FLAG_CASE);
                $result[$component][$view] = $layouts;
            }

            ksort($result[$component], SORT_NATURAL | SORT_FLAG_CASE);
        }

        ksort($result, SORT_NATURAL | SORT_FLAG_CASE);

        return $result;
    }

    /**
     * Collects views and layouts provided by template overrides,
     * i.e. templates/<template>/html/com_xxx/<view>/<layout>.php
     *
     * @param   array  &$result  Reference to the component => view => layouts map.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    private function collectOverrides(array &$result): void
    {
        $templatesPath = JPATH_SITE . '/templates';

        if (!is_dir($templatesPath)) {
            return;
        }

        foreach (Folder::folders($templatesPath, '^[A-Za-z0-9_-]+$', false, true) as $templatePath) {
            $overrideBase = $templatePath . '/html';

            if (!is_dir($overrideBase)) {
                continue;
            }

            foreach (Folder::folders($overrideBase, '^com_[a-z0-9_]+$', false, true) as $componentPath) {
                $component = strtolower(basename($componentPath));

                foreach ($this->listDirectories($componentPath) as $view) {
                    $result[$component][$view] = array_merge(
                        $result[$component][$view] ?? [],
                        $this->listLayouts($componentPath . '/' . $view)
                    );
                }
            }
        }
    }

    /**
     * Lists selectable layout names inside a view folder.
     *
     * Skips the base layout "default" (already covered by the view entry) and
     * sub-templates, which by Joomla convention contain an underscore
     * (e.g. blog_item.php) and are included by a layout rather than called
     * directly via &layout=.
     *
     * @param   string  $path  The view folder to scan.
     *
     * @return  array
     *
     * @since   1.0.2
     */
    private function listLayouts(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $layouts = [];

        foreach (Folder::files($path, '\.php$', false, true) as $file) {
            /*
             * The layout name is passed to Joomla exactly as it appears on
             * disk. Lowercasing it here would break template overrides on
             * case-sensitive filesystems (Linux), where "Yacht.php" must stay
             * "Yacht".
             */
            $layout = basename($file, '.php');

            if (strcasecmp($layout, 'default') === 0 || str_contains($layout, '_')) {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9-]+$/', $layout)) {
                $layouts[] = $layout;
            }
        }

        return $layouts;
    }

    /**
     * Lists immediate subdirectory names, lowercased.
     *
     * @param   string  $path  The folder to scan.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    private function listDirectories(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $names = [];

        foreach (Folder::folders($path, '^[A-Za-z0-9_-]+$', false, true) as $directory) {
            $name = strtolower(basename($directory));

            if (preg_match('/^[a-z0-9_-]+$/', $name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
