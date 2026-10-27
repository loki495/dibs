<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use App\Support\TodoCreationInput;
use Illuminate\Support\Facades\Validator;

class CreateTodoIssues
{
    public const MAX_TASKS = 20;

    private const array SUMMARY_FIELDS = ['id', 'number', 'title', 'withheld', 'state', 'parentId', 'area', 'group', 'priority', 'revision'];

    public function __construct(private readonly CreateTodoIssue $create, private readonly DescribeTodoIssue $describe) {}

    /** @param array<array-key, mixed> $tasks input is checked for list shape before writes
     * @return array{items: list<array<string, mixed>>, succeeded: int, failed: int}
     */
    public function handle(array $tasks): array
    {
        if (! array_is_list($tasks) || $tasks === [] || count($tasks) > self::MAX_TASKS) {
            throw new TodoValidationException('Provide a list of 1 to '.self::MAX_TASKS.' tasks.');
        }
        $items = [];
        $inputs = [];
        foreach ($tasks as $index => $task) {
            $validator = Validator::make(['task' => $task], [
                'task' => ['required', 'array:'.implode(',', TodoCreationInput::fields())],
                ...TodoCreationInput::rules('task.'),
            ]);
            if ($validator->fails()) {
                $fields = [];
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $fields[str_starts_with($field, 'task.') ? substr($field, 5) : $field] = $messages;
                }
                $items[$index] = $this->failure($index, null, implode(' ', $validator->errors()->all()), $fields);

                continue;
            }
            $inputs[$index] = TodoCreationInput::arguments($validator->validated()['task']);
        }

        // Freeze eligible parents before the first write, so guessed future IDs cannot form a batch tree.
        $parents = Issue::query()->where('is_available', true)->whereIn('id', array_column($inputs, 'parentId'))->pluck('id')->all();
        foreach ($inputs as $index => $input) {
            $key = $input['idempotencyKey'];
            try {
                $issue = $this->create->handle(...$input, allowedParentIds: $parents);
                $detail = $this->describe->handle($issue->id, maxBodyLength: 1);
                $items[$index] = ['index' => $index, 'idempotencyKey' => $key, 'issue' => array_intersect_key($detail, array_flip(self::SUMMARY_FIELDS))];
            } catch (TodoValidationException $exception) {
                $items[$index] = $this->failure($index, $key, $exception->getMessage());
            } catch (TodoRecordNotFoundException|TodoRecordUnavailableException $exception) {
                $items[$index] = $this->failure($index, $key, $exception->getMessage(), code: $exception instanceof TodoRecordNotFoundException ? 'not_found' : 'unavailable');
            }
        }
        ksort($items);
        $succeeded = count(array_filter($items, fn (array $item): bool => isset($item['issue'])));

        return ['items' => array_values($items), 'succeeded' => $succeeded, 'failed' => count($items) - $succeeded];
    }

    /** @param array<string, list<string>> $fields
     * @return array<string, mixed>
     */
    private function failure(int $index, ?string $key, string $message, array $fields = [], string $code = 'validation_error'): array
    {
        return ['index' => $index, 'idempotencyKey' => $key, 'error' => ['code' => $code, 'message' => $message, 'fields' => $fields]];
    }
}
