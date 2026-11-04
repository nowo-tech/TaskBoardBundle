<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Exception\ImportLimitExceededException;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpJsonImporter;
use Nowo\TaskBoardBundle\Import\Support\ImportFieldMapper;
use Nowo\TaskBoardBundle\Import\Support\JsonImportGuard;
use Nowo\TaskBoardBundle\Import\TaskBoard\TaskBoardJsonImporter;
use Nowo\TaskBoardBundle\Import\TaskBoard\TaskBoardRowMapper;
use Nowo\TaskBoardBundle\Import\Trello\TrelloJsonImporter;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Since 1.22.1 (audit EB-L3): JSON imports are weighed before json_decode() and counted before they are mapped.
 */
final class JsonImportGuardTest extends TestCase
{
    public function testTheWeightIsCheckedBeforeDecoding(): void
    {
        $guard = new JsonImportGuard(maxNodes: 25, maxRows: 2);

        // [ 10 + 2 commas + { 10 = 22: accepted; strings (escaped quotes, brackets and commas inside) weigh nothing.
        self::assertSame([1, 2, ['a' => 'x\\"[{,,,']], $guard->decode('[1,2,{"a":"x\\\\\\"[{,,,"}]', 'Bad: '));
        try {
            $guard->decode('["never closed', 'Bad: ');
            self::fail('an open string is left to json_decode');
        } catch (InvalidArgumentException $exception) {
            self::assertStringStartsWith('Bad: ', $exception->getMessage());
        }

        try {
            $guard->decode('[' . str_repeat('{},', 3) . '{}]', 'Bad: ');
            self::fail('too heavy');
        } catch (ImportLimitExceededException $exception) {
            self::assertSame(ImportLimitExceededException::JSON, $exception->translationKey);
            self::assertSame('The JSON file is too large to import (more than 25 values; an object or a list counts 10).', $exception->getMessage());
        }
    }

    public function testInvalidJsonDepthAndRows(): void
    {
        $guard = new JsonImportGuard(maxRows: 2);
        try {
            $guard->decode('{oops', 'Bad: ');
            self::fail('invalid');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Bad: Syntax error', $exception->getMessage());
        }
        try {
            $guard->decode(str_repeat('[', 70) . str_repeat(']', 70), 'Bad: ');
            self::fail('too deep');
        } catch (InvalidArgumentException $exception) {
            self::assertStringStartsWith('Bad: Maximum stack depth exceeded', $exception->getMessage());
        }
        $guard->assertRows([1, 2]);
        $this->expectException(ImportLimitExceededException::class);
        $guard->assertRows([1, 2, 3]);
    }

    public function testTheJsonImportersCountTheirItemsBeforeMappingThem(): void
    {
        $guard = new JsonImportGuard(maxRows: 1);
        foreach ([
            [new ClickUpJsonImporter(new ImportFieldMapper(), $guard), '[{"name":"a"},{"name":"b"}]'],
            [new TrelloJsonImporter(new ImportFieldMapper(), $guard), '{"cards":[{"name":"a"},{"name":"b"}]}'],
            [new TaskBoardJsonImporter(new TaskBoardRowMapper(), $guard), '[{"title":"a"},{"title":"b"}]'],
        ] as [$importer, $json]) {
            try {
                $importer->parse($json, 'x.json');
                self::fail($importer::class);
            } catch (ImportLimitExceededException $exception) {
                self::assertSame(ImportLimitExceededException::ROWS, $exception->translationKey);
            }
        }
        foreach ([[new ClickUpJsonImporter(), '"x"', 'Invalid ClickUp JSON: an object or a list is expected.'], [new TrelloJsonImporter(), '1', 'Invalid Trello JSON: an object is expected.']] as [$importer, $json, $message]) {
            try {
                $importer->parse($json, 'x.json');
                self::fail($message);
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }
}
