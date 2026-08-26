<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ModuleDisplay
 *
 * @copyright   Copyright (C) 2026
 * @license     GNU General Public License version 2 or later
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Registry\Registry;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class ($container->get(DatabaseInterface::class)) implements InstallerScriptInterface {
                /**
                 * Minimum supported versions.
                 */
                private string $minimumJoomla = '6.0';
                private string $minimumPhp    = '8.1.0';

                public function __construct(private DatabaseInterface $db)
                {
                }

                public function install(InstallerAdapter $parent): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $parent): bool
                {
                    return true;
                }

                /**
                 * Removes the parameters this plugin wrote into modules, so an
                 * uninstall does not leave orphaned settings behind.
                 */
                public function uninstall(InstallerAdapter $parent): bool
                {
                    try {
                        $query = $this->db->getQuery(true)
                            ->select($this->db->quoteName(['id', 'params']))
                            ->from($this->db->quoteName('#__modules'))
                            ->where($this->db->quoteName('params') . ' LIKE ' . $this->db->quote('%moduledisplay_%'));

                        $modules = $this->db->setQuery($query)->loadObjectList();
                    } catch (\Throwable $e) {
                        // Cleaning up is best effort; never block the uninstall.
                        return true;
                    }

                    foreach ($modules as $module) {
                        $params = new Registry($module->params);

                        $params->remove('moduledisplay_enabled');
                        $params->remove('moduledisplay_rules');

                        try {
                            $json = $params->toString();

                            $update = $this->db->getQuery(true)
                                ->update($this->db->quoteName('#__modules'))
                                ->set($this->db->quoteName('params') . ' = :params')
                                ->where($this->db->quoteName('id') . ' = :id')
                                ->bind(':params', $json)
                                ->bind(':id', $module->id, \Joomla\Database\ParameterType::INTEGER);

                            $this->db->setQuery($update)->execute();
                        } catch (\Throwable $e) {
                            continue;
                        }
                    }

                    return true;
                }

                public function preflight(string $type, InstallerAdapter $parent): bool
                {
                    if (version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
                        Factory::getApplication()->enqueueMessage(
                            sprintf('PHP %s oder neuer wird benötigt.', $this->minimumPhp),
                            'error'
                        );

                        return false;
                    }

                    if (version_compare(JVERSION, $this->minimumJoomla, '<')) {
                        Factory::getApplication()->enqueueMessage(
                            sprintf('Joomla %s oder neuer wird benötigt.', $this->minimumJoomla),
                            'error'
                        );

                        return false;
                    }

                    return true;
                }

                public function postflight(string $type, InstallerAdapter $parent): bool
                {
                    return true;
                }
            }
        );
    }
};
