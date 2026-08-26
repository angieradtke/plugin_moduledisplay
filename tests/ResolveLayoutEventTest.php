<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ModuleDisplay
 *
 * @copyright   Copyright (C) 2026
 * @license     GNU General Public License version 2 or later
 */

namespace Joomla\Plugin\System\ModuleDisplay\Tests;

use Joomla\Plugin\System\ModuleDisplay\Event\ResolveLayoutEvent;
use PHPUnit\Framework\TestCase;

/**
 * Covers the event other extensions use to supply a layout.
 */
final class ResolveLayoutEventTest extends TestCase
{
    private function event(array $arguments = []): ResolveLayoutEvent
    {
        return new ResolveLayoutEvent(
            ResolveLayoutEvent::EVENT_NAME,
            array_merge(['subject' => new \stdClass()], $arguments)
        );
    }

    public function testExposesTheRequestContext(): void
    {
        $event = $this->event(['option' => 'com_content', 'view' => 'article', 'id' => 42]);

        $this->assertSame('com_content', $event->getOption());
        $this->assertSame('article', $event->getView());
        $this->assertSame(42, $event->getId());
    }

    public function testMissingArgumentsFallBackToEmptyValues(): void
    {
        $event = $this->event();

        $this->assertSame('', $event->getOption());
        $this->assertSame('', $event->getView());
        $this->assertSame(0, $event->getId());
    }

    public function testResultsAreCollectedInOrder(): void
    {
        $event = $this->event();

        $event->addResult('yacht');
        $event->addResult('fallback');

        $this->assertSame(['yacht', 'fallback'], $event->getArgument('result'));
    }

    /**
     * Only layout names make sense as a result.
     */
    public function testNonStringResultsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->event()->addResult(123);
    }

    public function testArrayResultIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->event()->addResult(['yacht']);
    }
}
