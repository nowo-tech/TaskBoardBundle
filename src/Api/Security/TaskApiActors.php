<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Security;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Persistence\Mapping\MappingException as PersistenceMappingException;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Since 2.22.0 (Beacon spec 241 T020, write-unit replay safety): the principal's user as the bundle's entity manager
 * manages it now. The principal is built once per request (`kernel.request`) and kept in the request's attributes; a write
 * unit of nowo-tech/transaction-doctrine clears or resets the entity manager between two attempts of the controller, after
 * which that user object is detached, and writing it into a new row (a task's creator, a comment's author, a history
 * actor) fails (`ORMInvalidArgumentException`) or writes nothing. Each API write asks for the principal here, per
 * attempt: a user the manager does not manage is found again by its identifier (a plain object, a user reference by id
 * or a class the manager does not map is kept as it is).
 *
 * Stateless: the managed object is looked up per call and only replaces the request's principal for that call.
 */
final readonly class TaskApiActors
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function principal(Request $request): TaskApiPrincipal
    {
        $principal = TaskApiRequestListener::principalOf($request);

        return $principal->withUser($this->managed($principal->user));
    }

    public function managed(object $user): object
    {
        if ($this->entityManager->contains($user)) {
            return $user;
        }
        try {
            $metadata = $this->entityManager->getClassMetadata($user::class);
        } catch (PersistenceMappingException|MappingException) {
            return $user;
        }
        $id = $metadata->getIdentifierValues($user);

        try {
            return $id === [] ? $user : ($this->entityManager->find($metadata->getName(), $id) ?? $user);
        } catch (Throwable) {
            // A closed manager or a lost connection: the write that follows reports it, as it would have.
            return $user;
        }
    }
}
