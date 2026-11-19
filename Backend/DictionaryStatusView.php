<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Backend;

use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\DictionaryCounts;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\DictionaryStatus;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\StatusDictionary;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;

/** Дані шаблону блоку «Стан довідників». */
final class DictionaryStatusView
{
    /** Завдання планувальника йде кожні 5 хвилин; запит, старший за три такти, означає, що воно не працює. */
    private const int SCHEDULER_SILENT_AFTER = 15 * 60;

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly StateStorage $state,
        private readonly DictionaryCounts $counts,
        private readonly StatusDictionary $statuses,
        private readonly \Closure $now,
    ) {
    }

    /**
     * Помилки — вид для перекладу (`sviat__rozetka__dict_err_{kind}`) і, якщо є, технічний текст для показу сірим.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $status = DictionaryStatus::load($this->state);
        $now = ($this->now)();
        $run = $status->lastRun;
        $running = $status->runningSince !== null && $now - $status->runningSince < DictionaryRunner::TASK_TIMEOUT;
        $queued = $status->requestedAt !== null;
        $runStatus = is_string($run['status'] ?? null) ? $run['status'] : null;
        $finished = is_int($run['finished'] ?? null) ? $run['finished'] : null;
        $unknown = [];
        foreach ($this->json('unknown_types') as $type => $count) {
            if (is_int($count)) {
                $unknown[] = ['type' => (string)$type, 'count' => $count];
            }
        }
        $mismatch = $this->json('prepay_mismatch');

        return [
            'state' => $running ? 'running' : ($runStatus ?? 'none'),
            'updated_at' => $runStatus === DictionaryStatus::OK && $finished !== null ? $this->time($finished) : null,
            'attempted_at' => $runStatus !== DictionaryStatus::OK && $finished !== null ? $this->time($finished) : null,
            'counts' => ['cities' => $this->counts->cities(), 'statuses' => $this->statuses->count()] + $this->counts->byKind(),
            'queued' => $queued,
            'scheduler_idle' => $queued && $now - (int)$status->requestedAt >= self::SCHEDULER_SILENT_AFTER,
            'can_run' => !$running && !$queued,
            'error' => self::error($run),
            'reason' => is_string($run['reason'] ?? null) ? $run['reason'] : null,
            'received' => is_int($run['received'] ?? null) ? $run['received'] : null,
            'total' => is_int($run['total'] ?? null) ? $run['total'] : null,
            'statuses_error' => is_string($run['statuses'] ?? null) && $run['statuses'] !== 'ok' && ApiErrorKind::tryFrom($run['statuses']) !== null ? $run['statuses'] : null,
            'unknown_types' => $unknown,
            'prepay_mismatch' => [
                'count' => is_int($mismatch['count'] ?? null) ? $mismatch['count'] : 0,
                'names' => array_values(array_filter(is_array($mismatch['names'] ?? null) ? $mismatch['names'] : [], is_string(...))),
            ],
            'limit_units_suspect' => (int)$this->state->get('limit_units_suspect'),
        ];
    }

    /**
     * @param array<string, mixed>|null $run
     * @return array{kind: string, detail: ?string}|null
     */
    private static function error(?array $run): ?array
    {
        $error = $run['error'] ?? null;
        if (!is_string($error) || $error === '') {
            return null;
        }

        // `interrupted` пише runner, коли процес попереднього запуску вбили.
        return ApiErrorKind::tryFrom($error) !== null || $error === 'interrupted'
            ? ['kind' => $error, 'detail' => null]
            : ['kind' => 'internal', 'detail' => $error];
    }

    /** @return array<mixed> */
    private function json(string $key): array
    {
        $value = json_decode((string)$this->state->get($key), true);

        return is_array($value) ? $value : [];
    }

    private function time(int $at): string
    {
        return new \DateTimeImmutable('@' . $at)->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i');
    }
}
