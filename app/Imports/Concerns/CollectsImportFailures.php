<?php

namespace App\Imports\Concerns;

use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Validators\Failure;

trait CollectsImportFailures
{
    use SkipsFailures;

    /** @var array<int, array{row:int, field:string, errors:array<int, string>, values:array<string, mixed>}> */
    protected array $manualFailures = [];

    protected function addImportFailure(int $row, string $field, array $errors, array $values = []): void
    {
        $this->manualFailures[] = compact('row', 'field', 'errors', 'values');
    }

    /** @return array<int, array{row:int, field:string, errors:array<int, string>, values:array<string, mixed>}> */
    public function failureReport(): array
    {
        $validationFailures = $this->failures()->map(fn (Failure $failure) => [
            'row' => $failure->row(),
            'field' => $failure->attribute(),
            'errors' => $failure->errors(),
            'values' => $failure->values(),
        ])->values()->all();

        return [...$validationFailures, ...$this->manualFailures];
    }
}
