<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Since 2.26.0 (header search): `NOWO_TB_FULLTEXT(t.title, t.description, :against)`, MySQL / MariaDB
 * `MATCH (title, description) AGAINST (:against IN BOOLEAN MODE)` in DQL, on the FULLTEXT index of `search.fulltext`.
 * {@see TaskTextMatch} uses it only on those platforms, with `+word*` terms of letters and digits; compare it with `> 0`.
 *
 * MySQL / MariaDB only: covered by the MySQL and MariaDB jobs (HeaderSearchFulltextTest).
 *
 * @codeCoverageIgnore
 */
final class TaskTextMatchFunction extends FunctionNode
{
    public const NAME = 'NOWO_TB_FULLTEXT';

    private Node|string $title = '';

    private Node|string $description = '';

    private Node|string $against = '';

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        // @igor-ignore - Doctrine DQL function node: a new instance per parsed query, not a service.
        $this->title = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        // @igor-ignore - Doctrine DQL function node: a new instance per parsed query, not a service.
        $this->description = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        // @igor-ignore - Doctrine DQL function node: a new instance per parsed query, not a service.
        $this->against = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'MATCH (' . $sqlWalker->walkStringPrimary($this->title) . ', ' . $sqlWalker->walkStringPrimary($this->description)
            . ') AGAINST (' . $sqlWalker->walkStringPrimary($this->against) . ' IN BOOLEAN MODE)';
    }
}
