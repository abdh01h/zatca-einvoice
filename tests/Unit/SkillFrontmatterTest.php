<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Skill files are YAML-front-mattered documents that other tools parse.
 *
 * An unquoted `description` containing ": " is valid-looking prose and invalid
 * YAML: the parser reads a nested mapping and refuses the file with
 * "mapping values are not allowed in this context". That shipped once; this
 * test keeps it from shipping again.
 */
final class SkillFrontmatterTest extends TestCase
{
    public function test_every_skill_has_parseable_frontmatter(): void
    {
        $files = glob(__DIR__.'/../../.claude/skills/*/SKILL.md') ?: [];

        self::assertNotSame([], $files, 'Expected at least one skill in .claude/skills.');

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $name = basename(dirname($file));

            self::assertSame(
                1,
                preg_match('/\A---\n(.+?)\n---\n/s', $contents, $matches),
                $name.': SKILL.md must open with a YAML frontmatter block.',
            );

            $frontmatter = $matches[1];
            self::assertMatchesRegularExpression('/^name: /m', $frontmatter, $name.': missing name.');
            self::assertMatchesRegularExpression('/^description: /m', $frontmatter, $name.': missing description.');

            foreach (explode("\n", $frontmatter) as $line) {
                if (trim($line) === '' || str_starts_with($line, '#')) {
                    continue;
                }

                self::assertMatchesRegularExpression(
                    '/^[a-z][a-z0-9-]*: \S/',
                    $line,
                    sprintf('%s: "%s" is not a "key: value" pair.', $name, $line),
                );

                $value = substr($line, (int) strpos($line, ': ') + 2);
                $quoted = str_starts_with($value, '"') || str_starts_with($value, "'");

                if (! $quoted) {
                    self::assertStringNotContainsString(
                        ': ',
                        $value,
                        sprintf('%s: a value containing ": " must be quoted, or YAML reads it as a nested mapping.', $name),
                    );
                }
            }

            self::assertMatchesRegularExpression('/^name: '.preg_quote($name, '/').'$/m', $frontmatter, $name.': name must match the directory.');
        }
    }

    public function test_the_zatca_skill_description_is_short_enough_to_display(): void
    {
        $contents = (string) file_get_contents(__DIR__.'/../../.claude/skills/zatca-einvoice/SKILL.md');
        self::assertSame(1, preg_match('/^description: "?([^"\n]+)"?$/m', $contents, $matches));

        self::assertLessThanOrEqual(120, strlen($matches[1]));
    }
}
