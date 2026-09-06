<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sync;

use App\Sync\SyncPuller;
use App\Sync\SyncRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property int|null $since
 * @property list<string>|null $entities
 * @property int|null $limit
 */
final class PullRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'since' => ['nullable', 'integer', 'min:0'],
            'entities' => ['nullable', 'array'],
            'entities.*' => ['string', Rule::in(SyncRegistry::keys())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.SyncPuller::MAX_LIMIT],
        ];
    }

    public function since(): int
    {
        return (int) ($this->input('since') ?? 0);
    }

    /** @return list<string> */
    public function entities(): array
    {
        /** @var list<string> $entities */
        $entities = $this->input('entities') ?? [];

        return $entities;
    }

    public function limit(): int
    {
        return (int) ($this->input('limit') ?? SyncPuller::DEFAULT_LIMIT);
    }
}
