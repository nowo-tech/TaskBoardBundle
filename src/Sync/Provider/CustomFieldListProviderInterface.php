<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 3.2.0 (CU-2, optional): the custom field definitions of a source, for the import plan's Fields step (which
 * fields are imported). Values are not read here.
 */
interface CustomFieldListProviderInterface
{
    /**
     * The fields the source's lists use (definitions only: `value` is null), in the provider's order.
     *
     * @return list<RemoteCustomField>
     */
    public function listCustomFields(ProviderCredential $credential, RemoteSource $source): array;
}
