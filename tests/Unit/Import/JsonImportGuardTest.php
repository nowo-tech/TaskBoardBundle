<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Exception\ImportLimitExceededException;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpJsonImporter;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpTaskMapper;
use Nowo\TaskBoardBundle\Import\Support\ImportFieldMapper;
use Nowo\TaskBoardBundle\Import\Support\JsonImportGuard;
use Nowo\TaskBoardBundle\Import\TaskBoard\TaskBoardJsonImporter;
use Nowo\TaskBoardBundle\Import\TaskBoard\TaskBoardRowMapper;
use Nowo\TaskBoardBundle\Import\Trello\TrelloJsonImporter;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function implode;
use function str_repeat;

/**
 * Since 1.22.1 (audit EB-L3): JSON imports are weighed before json_decode() and counted before they are mapped.
 */
final class JsonImportGuardTest extends TestCase
{
    public function testTheWeightIsCheckedBeforeDecoding(): void
    {
        $guard = new JsonImportGuard(maxNodes: 42, maxRows: 2);

        // [ 12 + { 24 + key 3 + a 12-byte string 3 = 42 (three elements fit the first table): accepted; escaped quotes,
        // brackets and commas inside a string are part of the string.
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
            self::assertSame('The JSON file is too large to import (its weight is over 42; an administrator can raise the limit).', $exception->getMessage());
        }

        // Audits EB-L5, EB-I7: { 24 + key 3 + a 48-byte string 2 + 3 = 32; one byte more makes 33.
        $exact = new JsonImportGuard(maxNodes: 32);
        self::assertSame(['k' => str_repeat('x', 46)], $exact->decode('{"k":"' . str_repeat('x', 46) . '"}', 'Bad: '));
        $this->expectException(ImportLimitExceededException::class);
        $exact->decode('{"k":"' . str_repeat('x', 47) . '"}', 'Bad: ');
    }

    /**
     * Audit EB-L6: a list or an object that outgrows its table weighs the doubled table (the old one stays counted).
     */
    public function testTablesWeighTheirGrowth(): void
    {
        $guard = new JsonImportGuard(maxNodes: 48);
        // 8 elements fit the first table: [ 12.
        self::assertCount(8, $guard->decode('[' . implode(',', array_fill(0, 8, '0')) . ']', 'Bad: '));
        // 8 members: { 24 + 8 keys of 3 = 48.
        self::assertCount(8, $guard->decode('{"a":0,"b":0,"c":0,"d":0,"e":0,"f":0,"g":0,"h":0}', 'Bad: '));
        foreach (['[' . implode(',', array_fill(0, 9, '0')) . ']', '{"a":0,"b":0,"c":0,"d":0,"e":0,"f":0,"g":0,"h":0,"i":0}'] as $json) {
            try {
                // 9 elements: + 16 slots (28); 9 members: + 16 buckets of 3 (99).
                (new JsonImportGuard(maxNodes: 27))->decode($json, 'Bad: ');
                self::fail($json);
            } catch (ImportLimitExceededException $exception) {
                self::assertSame(ImportLimitExceededException::JSON, $exception->translationKey);
            }
        }
        self::assertCount(9, (new JsonImportGuard(maxNodes: 28))->decode('[' . implode(',', array_fill(0, 9, '0')) . ']', 'Bad: '));
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
            [new ClickUpJsonImporter(new ClickUpTaskMapper(), $guard), '[{"name":"a"},{"name":"b"}]'],
            [new TrelloJsonImporter(new ImportFieldMapper(), $guard), '{"cards":[{"name":"a"},{"name":"b"}]}'],
            [new TaskBoardJsonImporter(new TaskBoardRowMapper(), $guard), '[{"title":"a"},{"title":"b"}]'],
            // Audit EB-I7: nested subtasks count as they are walked.
            [new TaskBoardJsonImporter(new TaskBoardRowMapper(), $guard), '[{"id":"a","title":"a","subtasks":[{"title":"b"}]}]'],
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
