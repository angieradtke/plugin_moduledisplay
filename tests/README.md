# Tests

`MatchesRulesTest.php` deckt `ModuleDisplay::matchesRules()` ab – die Entscheidung,
ob ein Modul angezeigt wird. Diese Logik ist während der Entwicklung mehrfach
falsch gewesen (Wildcard-Semantik, Groß-/Kleinschreibung), daher der Test.

Ausführen mit PHPUnit aus dem Joomla-Testsetup heraus:

```
vendor/bin/phpunit plugins/system/moduledisplay/tests
```

Die Tests laufen ohne Datenbank und ohne Joomla-Bootstrap, da `matchesRules()`
eine reine Funktion über Arrays ist.

Der Ordner wird **nicht** mit installiert (nicht im Manifest gelistet).

## Zu Stubs

Wer diese Tests ohne Joomla-Bootstrap laufen lässt und dafür Joomla-Klassen
nachbaut, muss die Signaturen **exakt** übernehmen. Ein Stub, der
`ResultAwareInterface::typeCheckResult()` als `protected` deklariert, lässt
Code durchgehen, den Joomla mit einem Compile Error ablehnt — genau das ist
einmal passiert. Im Zweifel gegen die echte Joomla-Installation testen.
