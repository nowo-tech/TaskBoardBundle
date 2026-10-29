<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Comment\MentionCandidate;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskCommentEvent;
use Nowo\TaskBoardBundle\Event\TaskMentionEvent;
use Nowo\TaskBoardBundle\Repository\TaskCommentRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_diff;
use function in_array;
use function is_scalar;
use function mb_strlen;
use function method_exists;
use function str_contains;
use function str_replace;
use function trim;

/**
 * Since 1.16: creates, edits and deletes task comments. The mentions sent with a comment (candidate ids) count only
 * for valid candidates whose "@Name" is still in the text; the rest stays plain text. After each saved write it
 * dispatches {@see TaskBoardEvents::TASK_COMMENT} and, for the people newly mentioned (never the author),
 * {@see TaskBoardEvents::TASK_MENTION}.
 */
final readonly class TaskCommentManager
{
    public function __construct(
        private TaskCommentRepositoryInterface $comments,
        private TaskMentionCandidatesResolverInterface $candidates,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * @param list<string> $mentionIds Candidate ids picked in the comment box
     *
     * @throws InvalidArgumentException An empty or too long body
     */
    public function add(Task $task, object $author, string $body, array $mentionIds = []): TaskComment
    {
        $body                   = $this->normalize($body);
        [$mentions, $mentioned] = $this->resolveMentions($task, $body, $mentionIds);

        $comment = new TaskComment($task, $author, $body, $mentions);
        $this->comments->save($comment);
        $this->dispatch(new TaskCommentEvent($author, $comment, TaskCommentEvent::CREATED), TaskBoardEvents::TASK_COMMENT);
        $this->dispatchMentions($author, $comment, $mentioned, []);

        return $comment;
    }

    /**
     * @param list<string> $mentionIds
     *
     * @throws InvalidArgumentException An empty or too long body
     */
    public function edit(TaskComment $comment, object $editor, string $body, array $mentionIds = []): void
    {
        $body                   = $this->normalize($body);
        $before                 = $comment->getMentionedIds();
        [$mentions, $mentioned] = $this->resolveMentions($comment->getTask(), $body, $mentionIds);

        $comment->edit($body, $mentions);
        $this->comments->save($comment);
        $this->dispatch(new TaskCommentEvent($editor, $comment, TaskCommentEvent::EDITED), TaskBoardEvents::TASK_COMMENT);
        $this->dispatchMentions($editor, $comment, $mentioned, $before);
    }

    public function delete(TaskComment $comment): void
    {
        $comment->markDeleted();
        $this->comments->save($comment);
    }

    /** The task's comment author is `$user` (by id, else by identity). */
    public static function isAuthor(TaskComment $comment, object $user): bool
    {
        $author = $comment->getAuthor();
        if ($author === $user) {
            return true;
        }

        $authorId = method_exists($author, 'getId') ? $author->getId() : null;
        $userId   = method_exists($user, 'getId') ? $user->getId() : null;

        return is_scalar($authorId) && is_scalar($userId) && (string) $authorId === (string) $userId;
    }

    private function normalize(string $body): string
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '' || mb_strlen($body) > TaskComment::MAX_LENGTH) {
            throw new InvalidArgumentException('A comment needs between 1 and ' . TaskComment::MAX_LENGTH . ' characters.');
        }

        return $body;
    }

    /**
     * The mentions to store and the candidates they name: picked ids that are candidates and still written "@Name".
     *
     * @param list<string> $mentionIds
     *
     * @return array{0: list<array{id: string, label: string}>, 1: list<MentionCandidate>}
     */
    private function resolveMentions(Task $task, string $body, array $mentionIds): array
    {
        if ($mentionIds === []) {
            return [[], []];
        }

        $mentions  = [];
        $mentioned = [];
        foreach ($this->candidates->candidates($task) as $candidate) {
            if (in_array($candidate->id, $mentionIds, true) && str_contains($body, '@' . $candidate->label)) {
                $mentions[]  = ['id' => $candidate->id, 'label' => $candidate->label];
                $mentioned[] = $candidate;
            }
        }

        return [$mentions, $mentioned];
    }

    /**
     * @param list<MentionCandidate> $mentioned
     * @param list<string> $before Ids mentioned before an edit (not notified again)
     */
    private function dispatchMentions(object $actor, TaskComment $comment, array $mentioned, array $before): void
    {
        $users = [];
        $new   = array_diff(array_map(static fn (MentionCandidate $c): string => $c->id, $mentioned), $before);
        foreach ($mentioned as $candidate) {
            if (in_array($candidate->id, $new, true) && !self::isAuthor($comment, $candidate->user)) {
                $users[] = $candidate->user;
            }
        }

        if ($users !== []) {
            $this->dispatch(new TaskMentionEvent($actor, $comment, $users), TaskBoardEvents::TASK_MENTION);
        }
    }

    private function dispatch(object $event, string $name): void
    {
        $this->eventDispatcher?->dispatch($event, $name);
    }
}
