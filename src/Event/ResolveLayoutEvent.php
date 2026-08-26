<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ModuleDisplay
 *
 * @copyright   Copyright (C) 2026
 * @license     GNU General Public License version 2 or later
 */

namespace Joomla\Plugin\System\ModuleDisplay\Event;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Event\AbstractImmutableEvent;
use Joomla\CMS\Event\Result\ResultAware;
use Joomla\CMS\Event\Result\ResultAwareInterface;

/**
 * Asks other extensions which layout the current page uses.
 *
 * Listeners receive the component, view and record id being displayed and
 * answer with a layout name via addResult(). The first usable answer wins, so
 * a listener that has nothing to say should simply return without adding one.
 *
 * Example listener:
 *
 * ```php
 * public function onModuleDisplayResolveLayout(ResolveLayoutEvent $event): void
 * {
 *     if ($event->getOption() !== 'com_yachtcharter') {
 *         return;
 *     }
 *
 *     $event->addResult('yacht');
 * }
 * ```
 *
 * @since  2.1.0
 */
final class ResolveLayoutEvent extends AbstractImmutableEvent implements ResultAwareInterface
{
    use ResultAware;

    /**
     * The event name other extensions subscribe to.
     *
     * @var  string
     * @since 2.1.0
     */
    public const EVENT_NAME = 'onModuleDisplayResolveLayout';

    /**
     * Constructor.
     *
     * @param   string  $name       The event name.
     * @param   array   $arguments  Must contain subject, option, view and id.
     *
     * @since   2.1.0
     */
    public function __construct(string $name = self::EVENT_NAME, array $arguments = [])
    {
        $arguments['option'] ??= '';
        $arguments['view']   ??= '';
        $arguments['id']     ??= 0;

        parent::__construct($name, $arguments);
    }

    /**
     * The component being displayed, e.g. "com_content".
     *
     * @return  string
     *
     * @since   2.1.0
     */
    public function getOption(): string
    {
        return (string) ($this->arguments['option'] ?? '');
    }

    /**
     * The view being displayed, e.g. "article".
     *
     * @return  string
     *
     * @since   2.1.0
     */
    public function getView(): string
    {
        return (string) ($this->arguments['view'] ?? '');
    }

    /**
     * The id of the record being displayed, or 0 when there is none.
     *
     * @return  integer
     *
     * @since   2.1.0
     */
    public function getId(): int
    {
        return (int) ($this->arguments['id'] ?? 0);
    }

    /**
     * Only layout names are accepted as results.
     *
     * Implemented here rather than through one of the ResultType*Aware traits
     * so the expected type is stated explicitly at the point it matters.
     *
     * @param   mixed  $data  The result a listener wants to add.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the result is not a string.
     *
     * @since   2.1.0
     */
    public function typeCheckResult($data): void
    {
        if (!\is_string($data)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Event %s only accepts layout names as strings, %s given.',
                    $this->getName(),
                    \get_debug_type($data)
                )
            );
        }
    }
}
