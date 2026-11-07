<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Since 2.2.0 (spec 213, audit IA-L3): `NOWO_TB_JSON_TEXT(t.tags)`, a JSON column as text, so the tag filter runs in SQL with
 * `LIKE` on every database: PostgreSQL's `json` has no `LIKE`, MySQL's `JSON` compares as text once cast. Registered only
 * with `api.enabled`.
 */
final class JsonTextFunction extends FunctionNode
{
    public const NAME = 'NOWO_TB_JSON_TEXT';

    private Node|string $field = '';

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->field = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $field    = $sqlWalker->walkArithmeticPrimary($this->field);
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform    => 'CAST(' . $field . ' AS TEXT)',
            $platform instanceof AbstractMySQLPlatform => 'CAST(' . $field . ' AS CHAR)',
            default                                    => $field,
        };
    }
}
