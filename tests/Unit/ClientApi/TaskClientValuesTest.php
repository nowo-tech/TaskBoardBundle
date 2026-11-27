<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\ClientApi;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientContext;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientSource;
use Nowo\TaskBoardBundle\ClientApi\TaskClientApiException;
use Nowo\TaskBoardBundle\ClientApi\TaskClientCursor;
use Nowo\TaskBoardBundle\ClientApi\TaskClientInput;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use Symfony\Component\HttpFoundation\Request;

use function str_repeat;
use function strlen;
use function strtr;

/**
 * Since 3.4.0 (client API v1): the principal, context, cursor and input rules.
 */
final class TaskClientValuesTest extends TestCase
{
    public function testThePrincipalKeepsStringScopesOnceAndChecksItsKeys(): void
    {
        $principal = new TaskClientPrincipal(new stdClass(), ['task-board:read', 'task-board:read', 5, 'task-board:write'], 'tok-1', 'Laptop', 'org:acme');
        self::assertSame(['task-board:read', 'task-board:write'], $principal->scopes);
        self::assertTrue($principal->hasScope('task-board:write'));
        self::assertFalse($principal->hasScope('Task-board:write'));

        $this->expectException(InvalidArgumentException::class);
        new TaskClientPrincipal(new stdClass(), [], 'bad token id');
    }

    public function testABadContextKeyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TaskClientPrincipal(new stdClass(), [], 'tok', '', 'bad key');
    }

    public function testAContextsLabelIsPlainText(): void
    {
        self::assertSame(['key' => 'org:1', 'label' => 'Acme Inc'], (new TaskClientContext('org:1', "Acme\u{202E}\nInc"))->toArray());
        $this->expectException(InvalidArgumentException::class);
        new TaskClientContext('bad key', 'x');
    }

    /**
     * Audit ZCS-v-I4: an empty secret would make the keys public.
     */
    public function testACursorNeedsASecret(): void
    {
        foreach (['', " \n"] as $secret) {
            try {
                new TaskClientCursor($secret);
                self::fail('An empty secret must be refused.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('non-empty kernel.secret', $exception->getMessage());
            }
        }
    }

    /**
     * Audit ZCS-v-I2: the sealed length does not tell a value's digit count; a 3.4.0 (unpadded) cursor still decodes.
     */
    public function testCursorsArePaddedAndOldOnesStillDecode(): void
    {
        $cursors = new TaskClientCursor('secret');
        $short   = (string) base64_decode(strtr($cursors->encode('k', [1, 'a']), '-_', '+/'), true);
        $long    = (string) base64_decode(strtr($cursors->encode('k', [123456789, 'a']), '-_', '+/'), true);
        self::assertSame(strlen($short), strlen($long));
        self::assertSame(0, (strlen($short) - 28) % 32);
        self::assertSame([123456789, 'a'], $cursors->decode($cursors->encode('k', [123456789, 'a']), 'k', 2));

        // A 3.4.0 cursor: the same construction without padding.
        $encrypt = hash_hmac('sha256', 'nowo_task_board.client_api.cursor.encrypt', 'secret', true);
        $mac     = hash_hmac('sha256', 'nowo_task_board.client_api.cursor.mac', 'secret', true);
        $nonce   = str_repeat("\1", 12);
        $plain   = '[7,"b"]';
        $cipher  = $plain ^ substr(hash_hmac('sha256', $nonce . pack('N', 0), $encrypt, true), 0, strlen($plain));
        $tag     = substr(hash_hmac('sha256', 'v1|k|' . $nonce . $cipher, $mac, true), 0, 16);
        self::assertSame([7, 'b'], $cursors->decode(rtrim(strtr(base64_encode($nonce . $tag . $cipher), '+/', '-_'), '='), 'k', 2));
    }

    public function testCursorsAreSealedAndBoundToTheirKindAndShape(): void
    {
        $cursors = new TaskClientCursor('secret');
        $cursor  = $cursors->encode('k', ['2026-01-01 00:00:00', 3, 'task-id-123']);
        self::assertSame(['2026-01-01 00:00:00', 3, 'task-id-123'], $cursors->decode($cursor, 'k', 3));
        // Audit ZCS-L2: nothing of the values can be read in the cursor.
        $raw = (string) base64_decode(strtr($cursor, '-_', '+/'), true);
        self::assertStringNotContainsString('task-id-123', $raw);
        self::assertStringNotContainsString('2026-01-01', $raw);
        self::assertNotSame($cursor, $cursors->encode('k', ['2026-01-01 00:00:00', 3, 'task-id-123']), 'a fresh nonce each time');
        self::assertNull($cursors->decode($cursor, 'other', 3));
        self::assertNull($cursors->decode($cursor, 'k', 2));
        self::assertNull((new TaskClientCursor('another secret'))->decode($cursor, 'k', 3));
        // A changed byte is refused.
        $tampered     = $cursor;
        $tampered[20] = $tampered[20] === 'A' ? 'B' : 'A';
        self::assertNull($cursors->decode($tampered, 'k', 3));
        foreach (['', '***', str_repeat('a', 1025), 'AAAA', $cursors->encode('k', ['x', 'y'])] as $bad) {
            self::assertNull($cursors->decode($bad, 'k', 1), $bad);
        }
        // Sealed but not a list of short strings and integers: refused too.
        $shape = new ReflectionMethod(TaskClientCursor::class, 'encode');
        self::assertNull($cursors->decode($shape->invoke($cursors, 'k', [str_repeat('x', 192)]), 'k', 1));
        self::assertNull($cursors->decode($shape->invoke($cursors, 'k', [1.5]), 'k', 1));
    }

    public function testInputRules(): void
    {
        self::assertSame([], TaskClientInput::json(new Request(content: ''), 100));
        self::assertSame(['a' => 1], TaskClientInput::json(new Request(content: '{"a":1}'), 100));
        $this->refused('invalid_json', static fn (): array => TaskClientInput::json(new Request(content: '{"a":"' . str_repeat('x', 200) . '"}'), 100));
        self::assertNull(TaskClientInput::date(['d' => null], 'd'));
        self::assertSame('2026-01-01T09:00:00+00:00', TaskClientInput::date(['d' => '2026-01-01T10:00:00.123+01:00'], 'd')?->format('c'));
        $this->refused('invalid_field', static fn (): ?DateTimeImmutable => TaskClientInput::date(['d' => '0001-01-01T00:00:00Z'], 'd'));
        $this->refused('invalid_field', static fn (): ?string => TaskClientInput::id(['i' => 5], 'i'));
        $this->refused('invalid_field', static fn (): string => TaskClientInput::string(['s' => 5], 's'));
        $this->refused('invalid_field', static fn (): ?string => TaskClientInput::nullableString(['s' => 5], 's'));
        self::assertNull(TaskClientInput::nullableString(['s' => ' '], 's'));
        $this->refused('invalid_field', static fn (): array => TaskClientInput::strings(['l' => ['a', 'b']], 'l', 1));
        $this->refused('invalid_field', static fn (): bool => TaskClientInput::bool([], 'b'));
        self::assertNull(TaskClientInput::nullableInt([], 'n', 0, 1));
        $this->refused('invalid_field', static fn (): TaskClientSource => TaskClientInput::source(['type' => 'chat', 'bad name' => 'x']));
        $this->refused('invalid_field', static fn (): TaskClientSource => TaskClientInput::source(['type' => 'chat', 'id' => str_repeat('x', 129)]));
        self::assertSame(['messageUuid' => 'm'], TaskClientInput::source(['type' => 'chat', 'messageUuid' => 'm'])->fields);

        $request = new Request();
        self::assertNull(TaskClientInput::ifMatch($request, false));
        $request->headers->set('If-Match', 'W/"7"');
        self::assertSame(7, TaskClientInput::ifMatch($request, true));
        $request->headers->set('If-Match', '*');
        self::assertNull(TaskClientInput::ifMatch($request, true, true));
        self::assertSame(30, TaskClientInput::limit(new Request(), 30));
        self::assertSame(5, TaskClientInput::limit(new Request(['limit' => '5']), 30));
        $this->refused('invalid_limit', static fn (): int => TaskClientInput::limit(new Request(['limit' => ['5']]), 30));
        self::assertFalse(TaskClientInput::isId(null));
    }

    public function testARefusalsBody(): void
    {
        self::assertSame(['ok' => false, 'error' => 'invalid_field', 'field' => 'x'], TaskClientApiException::invalidField('x')->body());
    }

    private function refused(string $error, callable $call): void
    {
        try {
            $call();
            self::fail('Expected ' . $error);
        } catch (TaskClientApiException $refusal) {
            self::assertSame($error, $refusal->error);
        }
    }
}
