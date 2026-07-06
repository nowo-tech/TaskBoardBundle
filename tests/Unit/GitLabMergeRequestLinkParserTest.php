<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use Nowo\TaskBoardBundle\Support\GitLabMergeRequestLinkParser;
use PHPUnit\Framework\TestCase;

final class GitLabMergeRequestLinkParserTest extends TestCase
{
    private GitLabMergeRequestLinkParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GitLabMergeRequestLinkParser();
    }

    public function testParsesGitLabMergeRequestUrl(): void
    {
        $result = $this->parser->parse('https://gitlab.com/acme/platform/-/merge_requests/42');

        self::assertNotNull($result);
        self::assertSame('42', $result['externalId']);
        self::assertSame('MR !42', $result['label']);
    }

    public function testReturnsNullForNonMergeRequestUrl(): void
    {
        self::assertNull($this->parser->parse('https://github.com/org/repo/pull/1'));
    }
}
