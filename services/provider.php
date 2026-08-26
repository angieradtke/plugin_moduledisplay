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

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\System\ModuleDisplay\Extension\ModuleDisplay;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin = new ModuleDisplay(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('system', 'moduledisplay')
                );
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                /*
                 * Die Sprachdatei wird zusätzlich hier geladen: Bei
                 * Service-Provider-Plugins läuft der Konstruktor (und damit
                 * $autoloadLanguage), bevor setApplication() aufgerufen wurde.
                 * Beide Wege zusammen stellen sicher, dass die Übersetzungen
                 * im Modulformular ankommen.
                 */
                try {
                    $plugin->loadLanguage();
                } catch (\Throwable $e) {
                    // Ohne Übersetzung lauffähig bleiben.
                }

                return $plugin;
            }
        );
    }
};
