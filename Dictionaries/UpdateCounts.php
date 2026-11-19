<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Dictionaries;

final readonly class UpdateCounts
{
    public function __construct(
        public int $added = 0,
        public int $changed = 0,
        public int $deleted = 0,
        public int $unchanged = 0,
    ) {
    }

    /** @param array<string, mixed> $counts лічильники з попереднього toArray(); відсутні — нулі */
    public static function fromArray(array $counts): self
    {
        $int = static fn(string $key): int => is_int($counts[$key] ?? null) ? $counts[$key] : 0;

        return new self($int('added'), $int('changed'), $int('deleted'), $int('unchanged'));
    }

    /** @return array{added:int, changed:int, deleted:int, unchanged:int} */
    public function toArray(): array
    {
        return ['added' => $this->added, 'changed' => $this->changed, 'deleted' => $this->deleted, 'unchanged' => $this->unchanged];
    }

    /** Рядки, які після заміни лишилися в таблиці. */
    public function kept(): int
    {
        return $this->added + $this->changed + $this->unchanged;
    }

    public function isEmpty(): bool
    {
        return $this->kept() === 0 && $this->deleted === 0;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->added + $other->added,
            $this->changed + $other->changed,
            $this->deleted + $other->deleted,
            $this->unchanged + $other->unchanged,
        );
    }
}
