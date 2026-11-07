<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Characterization;

use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

use function array_filter;
use function array_map;
use function array_values;
use function file_put_contents;
use function getenv;
use function is_dir;
use function is_string;
use function mkdir;
use function preg_replace;

/**
 * Spec 213 S0: what a UI write answers, read back the same way before and after the code behind it moves. With
 * TASK_BOARD_CHARACTERIZE_DIR set, each named page is also written there with its ids and dates masked, so two checkouts
 * sharing one vendor can be compared byte by byte (no golden file in the repository: markup differs across the CI matrix).
 */
trait CapturesUiOutput
{
    /**
     * @return array{status: int, location: ?string, flashes: array<string, list<string>>, writes: list<string>, refused: list<string>}
     */
    private function captured(): array
    {
        $response = $this->client->getResponse();
        $session  = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        $records                 = $this->logger()->records;
        $this->logger()->records = [];
        // Read and gone, so the next request's flashes stand alone.
        $flashes = $session->getFlashBag()->all();
        $session->save();

        return [
            'status'   => $response->getStatusCode(),
            'location' => $response->headers->get('Location'),
            'flashes'  => $flashes,
            'writes'   => $this->logged($records, 'TaskBoard write: {action}', 'action'),
            'refused'  => $this->logged($records, 'TaskBoard write refused: {reason}', 'reason'),
        ];
    }

    /**
     * @param list<array{level: mixed, message: mixed, context: array<string, mixed>}> $records
     *
     * @return list<string>
     */
    private function logged(array $records, string $message, string $key): array
    {
        $matching = array_filter($records, static fn (array $record): bool => $record['message'] === $message);

        return array_values(array_map(static fn (array $record): string => (string) $record['context'][$key], $matching));
    }

    private function dumpBody(string $name): void
    {
        $directory = getenv('TASK_BOARD_CHARACTERIZE_DIR');
        if (!is_string($directory) || $directory === '') {
            return;
        }
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }
        $body = (string) $this->client->getResponse()->getContent();
        $body = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '{uuid}', $body);
        $body = (string) preg_replace('/\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?/', '{date}', $body);
        file_put_contents($directory . '/' . $name . '.html', $body);
    }
}
