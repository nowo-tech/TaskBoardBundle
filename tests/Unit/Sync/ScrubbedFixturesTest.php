<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function glob;
use function preg_match_all;

/**
 * Since 1.23: the ClickUp fixtures (recorded or provisional) never hold a real token, workspace name, e-mail, avatar
 * or signed URL, and only use the fixture hosts.
 */
final class ScrubbedFixturesTest extends TestCase
{
    private const HOSTS = ['app.clickup.com', 't000.p.clickup-attachments.com', 'example.test'];

    public function testTheFixturesAreScrubbed(): void
    {
        // Since 1.25: the people, time-entry and header fixtures too.
        $files = [...(glob(__DIR__ . '/../../Fixtures/ClickUp/*.json') ?: []), ...(glob(__DIR__ . '/../../Fixtures/ClickUp/*.headers') ?: [])];
        self::assertNotEmpty($files);
        foreach (['team_people.json', 'user.json', 'list_900200_task_people.json', 'team_9000001_time_entries_admin.json', 'team_9000001_time_entries_member.json', 'task_86a1x1_time.json', 'task_86a1x2.json', 'time_entries_429.headers'] as $required) {
            self::assertContains(__DIR__ . '/../../Fixtures/ClickUp/' . $required, $files, $required . ' is covered');
        }
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/pk_(?!REDACTED)[A-Za-z0-9_]{8,}/', $text, $file . ': a token');
            self::assertDoesNotMatchRegularExpression('/Bearer\s+(?!REDACTED)\S+/i', $text, $file . ': a bearer token');
            preg_match_all('/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+)/', $text, $emails);
            foreach ($emails[1] as $domain) {
                self::assertSame('example.test', $domain, $file . ': a real e-mail domain');
            }
            preg_match_all('#https?://([^/\s"?]+)[^\s"]*#', $text, $urls);
            foreach ($urls[1] as $index => $host) {
                self::assertContains($host, self::HOSTS, $file . ': an unexpected host');
                self::assertStringNotContainsString('?', $urls[0][$index], $file . ': a query string (signature?)');
            }
            self::assertDoesNotMatchRegularExpression('/"(profilePicture|avatar)"\s*:\s*"/', $text, $file . ': an avatar');
            preg_match_all('/"name"\s*:\s*"([^"]*)"/', $text, $names);
            // Since 1.25: people are "User <n>" (fake), user ids start with 9 or 1000 (fake ranges).
            preg_match_all('/"username"\s*:\s*"([^"]*)"/', $text, $usernames);
            foreach ($usernames[1] as $username) {
                self::assertMatchesRegularExpression('/^(User \d+|No id|Ignored)$/', $username, $file . ': a person name');
            }
            if (str_starts_with(basename($file), 'team.') || str_starts_with(basename($file), 'team_people.')) {
                foreach ($names[1] as $name) {
                    self::assertMatchesRegularExpression('/^Workspace \d+$/', $name, $file . ': a workspace name');
                }
            }
        }
    }
}
