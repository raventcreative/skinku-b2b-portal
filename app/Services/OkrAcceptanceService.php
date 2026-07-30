<?php

namespace App\Services;

use App\Models\AiKnowledge;
use App\Models\OkrCycle;
use Illuminate\Support\Str;

class OkrAcceptanceService
{
    /**
     * @return array<int,array{key:string,label:string,status:string,detail:string}>
     */
    public function check(OkrCycle $cycle): array
    {
        $cycle->loadMissing([
            'objectives.keyResults.tasks.assignee',
            'objectives.keyResults.tasks.column.board.columns',
        ]);
        $objectives = $cycle->objectives;
        $keyResults = $objectives->flatMap->keyResults;
        $tasks = $keyResults->flatMap->tasks;
        $direction = $this->normalise($cycle->direction);
        $rows = [];

        $specialistCounts = $objectives->countBy('specialist');
        $requiresThreeFunctions = collect(['cmo', 'cfo', 'coo'])
            ->every(fn (string $key) => preg_match('/\b'.$key.'\b/u', $direction));
        $balanced = ! $requiresThreeFunctions || (
            collect(['cmo', 'cfo', 'coo'])
                ->every(fn (string $key) => (int) $specialistCounts->get($key, 0) === 1)
            && $objectives->count() === 3
        );
        $rows[] = $this->row(
            'balanced_functions',
            'Tepat satu Objective CMO, CFO, dan COO',
            $balanced,
            $balanced ? 'Komposisi fungsi seimbang.' : 'Draf harus memiliki tepat tiga Objective utama: CMO, CFO, dan COO.',
        );

        $expectedOwners = $this->specialistOwnerNames();
        $ownerProblems = $objectives->filter(function ($objective) use ($expectedOwners) {
            if (blank($objective->owner_name)) {
                return true;
            }
            $expected = $expectedOwners[$objective->specialist] ?? null;

            return $expected && $this->normalise($objective->owner_name) !== $this->normalise($expected);
        });
        $rows[] = $this->row(
            'owners',
            'Owner BOD sesuai fungsi',
            $ownerProblems->isEmpty(),
            $ownerProblems->isEmpty()
                ? 'Nama owner Objective mengikuti struktur BOD pada Pengetahuan AI.'
                : 'Periksa owner: '.$ownerProblems->map(fn ($o) => strtoupper($o->specialist).'='.($o->owner_name ?: 'kosong'))->implode(', '),
        );

        $incompleteTasks = $tasks->filter(fn ($task) => blank($task->title)
            || blank($task->description)
            || blank($task->assignee_name)
            || ! $task->assignee_user_id
            || ! $task->board_column_id
            || ! $task->due_date);
        $rows[] = $this->row(
            'task_completeness',
            'Setiap pekerjaan punya detail, output, PIC, kolom, dan tenggat',
            $tasks->isNotEmpty() && $incompleteTasks->isEmpty(),
            $incompleteTasks->isEmpty()
                ? "{$tasks->count()} pekerjaan lengkap secara struktural."
                : "{$incompleteTasks->count()} pekerjaan belum lengkap.",
        );

        $columnMismatch = $tasks->filter(function ($task) {
            $name = $this->normalise((string) $task->assignee_name);
            $column = $this->normalise((string) $task->column?->name);
            $hasNamedColumn = $task->column?->board?->columns
                ?->contains(fn ($candidate) => str_contains($this->normalise($candidate->name), $name)) ?? false;

            return $hasNamedColumn && $name !== '' && $column !== '' && ! str_contains($column, $name);
        });
        $rows[] = $this->row(
            'pic_columns',
            'Kolom Kanban mengikuti nama PIC',
            $columnMismatch->isEmpty(),
            $columnMismatch->isEmpty()
                ? 'Semua tugas memakai kolom bernama PIC bila kolom tersebut tersedia.'
                : $columnMismatch->count().' tugas masih berada di kolom yang tidak cocok dengan PIC.',
        );

        $approvalMissing = $objectives->filter(function ($objective) {
            $owner = $this->normalise((string) $objective->owner_name);

            return ! $objective->keyResults->flatMap->tasks->contains(function ($task) use ($owner) {
                $text = $this->normalise($task->title.' '.$task->description);

                return $owner !== ''
                    && $this->normalise((string) $task->assignee_name) === $owner
                    && (str_contains($text, 'approval') || str_contains($text, 'review'));
            });
        });
        $rows[] = $this->row(
            'bod_approval',
            'BOD mempunyai pekerjaan review/approval',
            $approvalMissing->isEmpty(),
            $approvalMissing->isEmpty()
                ? 'Setiap owner BOD mempunyai tugas keputusan dengan nama PIC yang tetap terlihat.'
                : 'Tugas approval belum lengkap untuk: '.$approvalMissing->pluck('owner_name')->filter()->implode(', '),
        );

        $expectsEcommerce = $this->containsAny($direction, ['ecommerce', 'e commerce', 'tiktok', 'shopee']);
        $expectsDistributor = str_contains($direction, 'distributor');
        if ($expectsEcommerce && $expectsDistributor) {
            $ecommerceRows = $keyResults->filter(fn ($kr) => $this->containsAny(
                $this->krText($kr),
                ['ecommerce', 'e commerce', 'tiktok', 'shopee'],
            ));
            $distributorRows = $keyResults->filter(fn ($kr) => str_contains($this->krText($kr), 'distributor'));
            $mixedOnly = $ecommerceRows->isNotEmpty() && $distributorRows->isNotEmpty()
                && $ecommerceRows->pluck('id')->intersect($distributorRows->pluck('id'))->count() === $ecommerceRows->count()
                && $ecommerceRows->count() === $distributorRows->count();
            $rows[] = $this->row(
                'revenue_separation',
                'Target e-commerce terpisah dari target distributor',
                $ecommerceRows->isNotEmpty() && $distributorRows->isNotEmpty() && ! $mixedOnly,
                $mixedOnly
                    ? 'Semua target channel masih berada pada Key Result yang sama.'
                    : 'Key Result e-commerce dan distributor dapat ditinjau secara terpisah.',
            );
        }

        if ($expectsDistributor && preg_match('/\b30\b/u', $direction) && preg_match('/100\s*juta/u', $direction)) {
            $aspirational = $keyResults->contains(function ($kr) {
                $text = $this->krText($kr);

                return str_contains($text, 'distributor')
                    && $kr->baseline_status !== 'actual'
                    && $this->containsAny($text, ['aspiratif', 'validasi', 'gap']);
            });
            $rows[] = $this->row(
                'distributor_aspiration',
                'Target 30 × Rp100 juta ditandai aspiratif saat baseline belum mendukung',
                $aspirational,
                $aspirational
                    ? 'Baseline dan gap distributor tidak disamarkan sebagai data aktual.'
                    : 'Tambahkan status perlu validasi/asumsi dan jelaskan gap atau sifat aspiratif target distributor.',
            );
        }

        if (str_contains($direction, 'affiliate') || str_contains($direction, 'affiliator')) {
            $corpus = $this->normalise($keyResults->map(fn ($kr) => $this->krText($kr))->implode(' '));
            $groups = [
                ['daftar', 'rekrut'],
                ['onboarding'],
                ['konten', 'live'],
                ['order'],
                ['gmv', 'conversion', 'konversi'],
                ['retention', 'retensi'],
            ];
            $missing = collect($groups)->reject(fn (array $keywords) => $this->containsAny($corpus, $keywords));
            $rows[] = $this->row(
                'affiliate_funnel',
                'Funnel affiliate dibedakan sampai GMV/conversion/retention',
                $missing->isEmpty(),
                $missing->isEmpty()
                    ? 'Tahap daftar, onboarding, aktivitas, order, GMV/conversion, dan retention tercakup.'
                    : $missing->count().' kelompok metrik affiliate belum muncul dalam Key Result.',
            );
        }

        if (preg_match('/\b15\b/u', $direction) && $this->containsAny($direction, ['produk', 'item', 'master'])) {
            $corpus = $this->normalise($keyResults->map(fn ($kr) => $this->krText($kr))->implode(' '));
            $stages = ['riset', 'konsep', 'costing', 'hpp', 'sampling', 'uji pasar', 'produksi', 'launch', 'evaluasi'];
            $covered = collect($stages)->filter(fn (string $stage) => str_contains($corpus, $stage))->count();
            $rows[] = $this->row(
                'product_pipeline',
                '15 item baru memakai gate pipeline, bukan langsung dianggap launch',
                $covered >= 5,
                $covered >= 5
                    ? "{$covered} penanda tahap pengembangan muncul dalam Key Result."
                    : 'Gunakan minimal lima tahap nyata: riset, konsep, costing/HPP, sampling, uji pasar, produksi, launch, evaluasi.',
            );
        }

        return $rows;
    }

    /** @param array<int,array{status:string}> $rows */
    public function blockingMessages(array $rows): array
    {
        return collect($rows)
            ->where('status', 'fail')
            ->map(fn (array $row) => $row['label'].': '.$row['detail'])
            ->values()
            ->all();
    }

    private function row(string $key, string $label, bool $passed, string $detail): array
    {
        return compact('key', 'label', 'detail') + ['status' => $passed ? 'pass' : 'fail'];
    }

    /** @return array<string,string> */
    private function specialistOwnerNames(): array
    {
        $team = (string) (AiKnowledge::map()['team'] ?? '');
        $owners = [];
        foreach (preg_split('/\R/u', $team) ?: [] as $line) {
            foreach (['cmo', 'cfo', 'coo'] as $specialist) {
                if (! preg_match('/\b'.strtoupper($specialist).'\b/u', $line)) {
                    continue;
                }
                if (preg_match('/^\s*[-*]?\s*([^—–\r\n]+?)\s*(?:—|–|-\s)\s*'.strtoupper($specialist).'\b/u', $line, $match)) {
                    $owners[$specialist] = trim($match[1], " \t\n\r\0\x0B-");
                }
            }
        }

        return $owners;
    }

    private function krText($kr): string
    {
        return $this->normalise(collect([
            $kr->title, $kr->metric, $kr->target, $kr->baseline, $kr->target_gap,
            $kr->tasks->pluck('title')->implode(' '),
            $kr->tasks->pluck('description')->implode(' '),
        ])->filter()->implode(' '));
    }

    /** @param array<int,string> $needles */
    private function containsAny(string $text, array $needles): bool
    {
        return collect($needles)->contains(fn (string $needle) => str_contains($text, $this->normalise($needle)));
    }

    private function normalise(string $value): string
    {
        return Str::of($value)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }
}
