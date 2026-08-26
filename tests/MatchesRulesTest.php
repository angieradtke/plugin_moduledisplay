<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ModuleDisplay
 *
 * @copyright   Copyright (C) 2026
 * @license     GNU General Public License version 2 or later
 */

namespace Joomla\Plugin\System\ModuleDisplay\Tests;

use Joomla\Plugin\System\ModuleDisplay\Extension\ModuleDisplay;
use PHPUnit\Framework\TestCase;

/**
 * Covers the rule matching, i.e. the decision whether a module is shown.
 */
final class MatchesRulesTest extends TestCase
{
    /**
     * Calls the private matcher through reflection.
     */
    private function match(array $rules, string $option, string $view, string $layout): bool
    {
        $plugin = (new \ReflectionClass(ModuleDisplay::class))->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(ModuleDisplay::class, 'matchesRules');
        $method->setAccessible(true);

        return $method->invoke($plugin, $rules, $option, $view, $layout);
    }

    public function testEmptyRulesMatchNothing(): void
    {
        $this->assertFalse($this->match([], 'com_content', 'article', 'default'));
    }

    public function testViewRuleMatchesDefaultLayout(): void
    {
        $rules = ['com_content:article:'];

        $this->assertTrue($this->match($rules, 'com_content', 'article', 'default'));
    }

    /**
     * The view entry is a wildcard: it must also match alternative layouts.
     */
    public function testViewRuleMatchesAnyLayout(): void
    {
        $rules = ['com_content:article:'];

        $this->assertTrue($this->match($rules, 'com_content', 'article', 'yacht'));
    }

    public function testLayoutRuleMatchesOnlyThatLayout(): void
    {
        $rules = ['com_content:article:yacht'];

        $this->assertTrue($this->match($rules, 'com_content', 'article', 'yacht'));
        $this->assertFalse($this->match($rules, 'com_content', 'article', 'default'));
        $this->assertFalse($this->match($rules, 'com_content', 'article', 'other'));
    }

    /**
     * A layout rule must work on its own, without the base entry selected.
     */
    public function testLayoutRuleWorksWithoutViewRule(): void
    {
        $rules = ['com_content:article:yacht'];

        $this->assertTrue($this->match($rules, 'com_content', 'article', 'yacht'));
    }

    public function testDifferentViewDoesNotMatch(): void
    {
        $rules = ['com_content:article:'];

        $this->assertFalse($this->match($rules, 'com_content', 'category', 'default'));
    }

    public function testDifferentComponentDoesNotMatch(): void
    {
        $rules = ['com_content:article:'];

        $this->assertFalse($this->match($rules, 'com_contact', 'article', 'default'));
    }

    /**
     * Layout names come from file names and may be capitalised.
     */
    public function testLayoutComparisonIsCaseInsensitive(): void
    {
        $rules = ['com_content:article:Yacht'];

        $this->assertTrue($this->match($rules, 'com_content', 'article', 'yacht'));
        $this->assertTrue($this->match($rules, 'com_content', 'article', 'YACHT'));
    }

    public function testMultipleRulesAreOrCombined(): void
    {
        $rules = ['com_content:article:yacht', 'com_contact:contact:'];

        $this->assertTrue($this->match($rules, 'com_content', 'article', 'yacht'));
        $this->assertTrue($this->match($rules, 'com_contact', 'contact', 'default'));
        $this->assertFalse($this->match($rules, 'com_content', 'article', 'default'));
    }

    public function testMalformedRuleIsIgnored(): void
    {
        $rules = ['nonsense', '', 'com_content:article:'];

        $this->assertTrue($this->match($rules, 'com_content', 'article', 'default'));
        $this->assertFalse($this->match(['nonsense'], 'com_content', 'article', 'default'));
    }

    /**
     * Rules written by earlier versions used separate keys.
     */
    public function testLegacyArrayRuleWithoutLayoutMatchesAnyLayout(): void
    {
        $rules = [['component' => 'com_content', 'view' => 'article', 'layout' => '']];

        $this->assertTrue($this->match($rules, 'com_content', 'article', 'default'));
        $this->assertTrue($this->match($rules, 'com_content', 'article', 'yacht'));
    }

    public function testLegacyObjectRuleWithLayout(): void
    {
        $rule = (object) ['component' => 'com_content', 'view' => 'article', 'layout' => 'yacht'];

        $this->assertTrue($this->match([$rule], 'com_content', 'article', 'yacht'));
        $this->assertFalse($this->match([$rule], 'com_content', 'article', 'default'));
    }

    /**
     * The layout sources map replaced the hardcoded com_content lookup.
     */
    public function testLayoutSourcesCoverCoreComponents(): void
    {
        $map = (new \ReflectionClass(ModuleDisplay::class))->getConstant('LAYOUT_SOURCES');

        $this->assertSame('#__content', $map['com_content:article']['table']);
        $this->assertSame('article_layout', $map['com_content:article']['key']);
        $this->assertSame('#__contact_details', $map['com_contact:contact']['table']);
        $this->assertArrayHasKey('com_newsfeeds:newsfeed', $map);
    }

    /**
     * Articles keep their settings in "attribs", everything else in "params".
     * Getting this wrong makes the query fail silently.
     */
    public function testEachSourceNamesTheRightSettingsColumn(): void
    {
        $map = (new \ReflectionClass(ModuleDisplay::class))->getConstant('LAYOUT_SOURCES');

        $this->assertSame('attribs', $map['com_content:article']['column']);
        $this->assertSame('params', $map['com_content:category']['column']);
        $this->assertSame('params', $map['com_contact:contact']['column']);
        $this->assertSame('params', $map['com_newsfeeds:newsfeed']['column']);

        foreach ($map as $key => $source) {
            $expected = $source['table'] === '#__content' ? 'attribs' : 'params';

            $this->assertSame($expected, $source['column'], $key);
        }
    }

    /**
     * Table and column names must never come from the request.
     */
    public function testEveryLayoutSourceIsAPrefixedTable(): void
    {
        $map = (new \ReflectionClass(ModuleDisplay::class))->getConstant('LAYOUT_SOURCES');

        foreach ($map as $key => $source) {
            $this->assertArrayHasKey('table', $source, $key);
            $this->assertArrayHasKey('column', $source, $key);
            $this->assertArrayHasKey('key', $source, $key);
            $this->assertStringStartsWith('#__', $source['table'], $key);
        }
    }

    /**
     * Joomla stores duplicated layouts as "<template>:<name>-<timestamp>",
     * e.g. "isails:default-20260826-071340". Taken from a real installation.
     */
    public function testDuplicatedLayoutNameIsMatched(): void
    {
        $plugin = (new \ReflectionClass(ModuleDisplay::class))->newInstanceWithoutConstructor();

        $strip = new \ReflectionMethod(ModuleDisplay::class, 'stripTemplatePrefix');
        $strip->setAccessible(true);

        $layout = $strip->invoke($plugin, 'isails:default-20260826-071340');

        $this->assertSame('default-20260826-071340', $layout);
        $this->assertTrue(
            $this->match(['com_contact:contact:' . $layout], 'com_contact', 'contact', $layout)
        );
    }

    /**
     * Joomla passes alternative layouts as "<template>:<layout>" in the URL.
     * Reading that through getCmd() strips the colon and yields "isailsstart"
     * instead of "start", which matches nothing - hence getString().
     */
    public function testTemplatePrefixedUrlLayoutResolvesToTheLayoutName(): void
    {
        $plugin = (new \ReflectionClass(ModuleDisplay::class))->newInstanceWithoutConstructor();

        $strip = new \ReflectionMethod(ModuleDisplay::class, 'stripTemplatePrefix');
        $strip->setAccessible(true);

        $this->assertSame('start', $strip->invoke($plugin, 'isails:start'));

        // The mangled form must not match the rule.
        $this->assertFalse(
            $this->match(['com_content:featured:start'], 'com_content', 'featured', 'isailsstart')
        );

        // The correctly read form must.
        $this->assertTrue(
            $this->match(['com_content:featured:start'], 'com_content', 'featured', 'start')
        );
    }

    /**
     * Reading the layout as a string must not let anything unchecked through.
     */
    public function testHostileLayoutValuesAreRejected(): void
    {
        $plugin = (new \ReflectionClass(ModuleDisplay::class))->newInstanceWithoutConstructor();

        $strip = new \ReflectionMethod(ModuleDisplay::class, 'stripTemplatePrefix');
        $strip->setAccessible(true);

        $valid = new \ReflectionMethod(ModuleDisplay::class, 'isValidLayout');
        $valid->setAccessible(true);

        $hostileValues = [
            '../../../etc/passwd',
            '<script>alert(1)</script>',
            "x'; DROP TABLE y;--",
            'a/b',
            'a.php',
            '',
        ];

        foreach ($hostileValues as $hostile) {
            $this->assertFalse(
                $valid->invoke($plugin, $strip->invoke($plugin, $hostile)),
                $hostile
            );
        }
    }

    public function testUnknownComponentHasNoLayoutSource(): void
    {
        $map = (new \ReflectionClass(ModuleDisplay::class))->getConstant('LAYOUT_SOURCES');

        $this->assertArrayNotHasKey('com_something:view', $map);
    }
}
