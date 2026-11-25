<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync\OAuth;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthErrorCode;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthException;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthGrant;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthIdentity;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthReturnPath;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthState;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthWorkspace;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpAuthorization;
use Nowo\TaskBoardBundle\Sync\Provider\CredentialScheme;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function print_r;
use function serialize;
use function sprintf;
use function str_repeat;
use function strlen;
use function unserialize;

/**
 * Since 3.1.0 (CU-1): the OAuth state, grant, identity, error codes, return path rule and the credential scheme.
 */
final class OAuthValueObjectsTest extends TestCase
{
    public function testStateIsRandomWellFormedAndComparedInConstantTime(): void
    {
        $state = OAuthState::create();
        self::assertSame(43, strlen($state->value));
        self::assertTrue(OAuthState::isValid($state->value));
        self::assertNotSame($state->value, OAuthState::create()->value);

        $stored = OAuthState::fromString($state->value);
        self::assertTrue($stored->matches($state->value));
        self::assertFalse($stored->matches(OAuthState::create()->value));
        self::assertFalse($stored->matches(null));
        self::assertFalse($stored->matches(['x']));
        self::assertFalse($stored->matches($state->value . "\n"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedStates(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => [str_repeat('a', 42)];
        yield 'long' => [str_repeat('a', 44)];
        yield 'trailing newline' => [str_repeat('a', 43) . "\n"];
        yield 'padding' => [str_repeat('a', 42) . '='];
        yield 'plus' => [str_repeat('a', 42) . '+'];
    }

    #[DataProvider('malformedStates')]
    public function testMalformedStatesAreRefused(string $value): void
    {
        self::assertFalse(OAuthState::isValid($value));
        $this->expectException(InvalidArgumentException::class);
        OAuthState::fromString($value);
    }

    public function testGrantIsRedactedNotSerializableAndBecomesAnOAuthCredential(): void
    {
        $grant = new OAuthGrant('tok_placeholder_1234');
        self::assertSame('tok_placeholder_1234', $grant->accessToken());
        self::assertSame('1234', $grant->lastFour());
        self::assertNull($grant->expiresAt);
        self::assertNull($grant->refreshToken());
        self::assertStringNotContainsString('placeholder', print_r($grant, true));

        $credential = $grant->toCredential('conn-9', ['scheme' => 'personal', 'workspace' => '42']);
        self::assertSame('conn-9', $credential->connectionRef);
        self::assertSame(CredentialScheme::OAuth, $credential->scheme());
        self::assertSame(['scheme' => 'oauth', 'workspace' => '42'], $credential->options);
        self::assertSame('Bearer tok_placeholder_1234', ClickUpAuthorization::header($credential));

        $future = new OAuthGrant('tok_a', new DateTimeImmutable('@1800000000'), 'refresh_placeholder');
        self::assertSame('refresh_placeholder', $future->refreshToken());
        $dump = print_r($future, true);
        self::assertStringNotContainsString('refresh_placeholder', $dump);
        self::assertStringNotContainsString('tok_a', $dump);

        try {
            serialize($grant);
            self::fail('serialized');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        unserialize(sprintf('O:%d:"%s":0:{}', strlen(OAuthGrant::class), OAuthGrant::class));
    }

    public function testCredentialSchemesAndTheirHeaders(): void
    {
        $personal = new ProviderCredential('pk_placeholder', 'c');
        self::assertSame(CredentialScheme::Personal, $personal->scheme());
        self::assertSame('pk_placeholder', ClickUpAuthorization::header($personal));
        self::assertSame('pk_placeholder', ClickUpAuthorization::header(new ProviderCredential('pk_placeholder', 'c', ['scheme' => 'personal'])));
        self::assertNull((new ProviderCredential('x', 'c', ['scheme' => 1]))->scheme());

        $unknown = new ProviderCredential('x', 'c', ['scheme' => 'basic']);
        self::assertNull($unknown->scheme());
        try {
            ClickUpAuthorization::header($unknown);
            self::fail('unknown scheme');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderErrorCode::UnsupportedCredential, $exception->code());
            self::assertFalse($exception->code()->isPassing());
            self::assertSame('The provider does not accept this kind of credential.', $exception->getMessage());
        }
    }

    public function testIdentityErrorsAndReturnPaths(): void
    {
        $identity = new OAuthIdentity('7', 'Ada', [new OAuthWorkspace('9001', 'Acme'), new OAuthWorkspace('9002', 'Labs')]);
        self::assertSame(['9001', '9002'], $identity->workspaceIds());
        self::assertSame([], (new OAuthIdentity('7', '', []))->workspaceIds());

        foreach (OAuthErrorCode::cases() as $code) {
            $exception = new OAuthException($code, 400);
            self::assertSame($code->message(), $exception->getMessage());
            self::assertSame($code, $exception->errorCode);
            self::assertSame(400, $exception->httpStatus);
            self::assertNull($exception->getPrevious());
        }
        self::assertNull((new OAuthException(OAuthErrorCode::Denied))->httpStatus);

        self::assertTrue(OAuthReturnPath::isSafe('/tools/task-board/board/1/connection?provider=clickup'));
        self::assertTrue(OAuthReturnPath::isSafe('/'));
        foreach (['', 'https://evil.test/', '//evil.test', '/\\evil.test', '\\evil', 'javascript:alert(1)', "/a\nb", '/a b', '/' . str_repeat('a', 2048)] as $unsafe) {
            self::assertFalse(OAuthReturnPath::isSafe($unsafe), $unsafe);
        }
    }
}
