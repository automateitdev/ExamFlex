<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MeritProcessor
{
    // ─────────────────────────────────────────────
    // ENTRY POINT
    // ─────────────────────────────────────────────

    public function process(array $payload): array
    {
        $results = $this->normalizeResults($payload['results'] ?? []);

        // Deduplicate by student_id
        $results = $results->unique('student_id')->values();

        Log::info('Students after deduplication', ['count' => $results->count()]);

        if ($results->isEmpty()) {
            return ['status' => 'error', 'message' => 'No results found'];
        }

        $examConfig      = $payload['exam_config'] ?? [];
        $academicDetails = collect($payload['academic_details'] ?? []);
        $studentDetails  = collect($payload['student_details'] ?? []);
        $meritType       = $examConfig['merit_process_type'] ?? 'total_mark_sequential';
        $groupBy         = $this->getGroupByFields($examConfig);

        Log::info('=== MERIT PROCESS START ===');
        Log::info('Total students received: ' . $results->count());
        Log::info('Merit type: ' . $meritType);
        Log::info('Group by fields: ' . json_encode($groupBy));

        // Sort ALL students together (class-wise)
        $allSorted = $this->sortStudents($results, $meritType, $academicDetails);

        // Assign ranks to ALL students (class-wise ranking)
        $allRanked = $this->assignRanks($allSorted, $meritType, $academicDetails, $studentDetails);

        // Sanity check
        $rank1Students = collect($allRanked)->where('merit_position', 1)->values();
        if ($rank1Students->count() > 1) {
            $isSequential = $this->isSequential($meritType);
            if ($isSequential) {
                Log::error('PROBLEM: Multiple students with rank 1!', $rank1Students->toArray());
            }
        } else {
            Log::info('OK: Only one student with rank 1', $rank1Students->toArray());
        }

        $all = collect($allRanked);

        return [
            'total_students' => $results->count(),
            'merit_type'     => $meritType,
            'grouped_by'     => $groupBy,
            'data' => [
                // CLASS WISE
                'all_students' => $allRanked,

                // SECTION WISE
                // 'section_wise' => $this->rankByField(
                //     $results, // ← pass original results, not ranked
                //     'section',
                //     $meritType,
                //     $academicDetails,
                //     $studentDetails
                // ),
                // SECTION WISE — shift+section composite
                // SECTION WISE
                'section_wise' => $this->rankBySectionField(
                    $results,
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // SHIFT WISE
                'shift_wise' => $this->rankByField(
                    $results,
                    'shift',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // GROUP WISE
                'group_wise' => $this->rankByField(
                    $results,
                    'group',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // GENDER WISE
                'gender_wise' => $this->rankByField(
                    $results,
                    'gender',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // RELIGION WISE
                'religion_wise' => $this->rankByField(
                    $results,
                    'religion',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // SHIFT + GROUP WISE (composite)
                'shift_wise_group' => $this->rankByCompositeField(
                    $results,
                    ['shift', 'group'],
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),
            ],
        ];
    }

    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────

    private function normalizeResults($raw): Collection
    {
        if (isset($raw['results']) && is_array($raw['results'])) {
            return collect($raw['results']);
        }
        return collect(is_array($raw) ? $raw : []);
    }

    private function getGroupByFields(array $config): array
    {
        $fields = [];
        if ($config['group_by_shift']    ?? false) $fields[] = 'shift';
        if ($config['group_by_section']  ?? false) $fields[] = 'section';
        if ($config['group_by_group']    ?? false) $fields[] = 'group';
        if ($config['group_by_gender']   ?? false) $fields[] = 'gender';
        if ($config['group_by_religion'] ?? false) $fields[] = 'religion';

        return $fields;
    }

    /**
     * Is this merit type sequential (no duplicate ranks)?
     */
    private function isSequential(string $meritType): bool
    {
        return str_contains(strtolower(trim($meritType)), '(sequential)');
    }

    /**
     * Is this merit type grade-point based?
     */
    private function isGradePointBased(string $meritType): bool
    {
        $lower = strtolower($meritType);
        return str_contains($lower, 'grade point') || str_contains($lower, 'gpa');
    }

    /**
     * Safe float cast — handles "683.00", null, int, float
     */
    private function toFloat($value): float
    {
        return (float) ($value ?? 0);
    }

    /**
     * Safe float comparison — avoids floating point precision issues
     */
    private function floatEquals(float $a, float $b): bool
    {
        return abs($a - $b) < 0.0001;
    }

    /**
     * Get total mark from a student record (float, consistent)
     */
    private function getTotalMark(array $student): float
    {
        return $this->toFloat(
            $student['total_mark_with_optional']
                ?? $student['total_mark']
                ?? 0
        );
    }

    /**
     * Get GPA from a student record (float, consistent)
     */
    private function getGpa(array $student): float
    {
        return $this->toFloat(
            $student['gpa_with_optional']
                ?? $student['gpa']
                ?? 0
        );
    }

    /**
     * Recalculate total from subjects array (used inside sort closures
     * where total_mark_with_optional may not yet be set)
     */
    private function calcTotalFromSubjects(array $student): float
    {
        return (float) collect($student['subjects'] ?? [])
            ->filter(fn($s) => ($s['is_uncountable'] ?? false) === false)
            ->sum(fn($s) => $this->toFloat($s['combined_final_mark'] ?? $s['final_mark'] ?? 0));
    }

    // ─────────────────────────────────────────────
    // SORT
    // ─────────────────────────────────────────────

    /**
     * Sort a collection of students according to the merit type rules:
     *
     * Total Mark based  → TM desc → GPA desc → roll asc
     * Grade Point based → GPA desc → TM desc → roll asc
     *
     * Pass always comes before Fail.
     */
    private function sortStudents(
        Collection $students,
        string $meritType,
        Collection $academicDetails
    ): Collection {
        $gpaPriority = $this->isGradePointBased($meritType);

        return $students->sort(function ($a, $b) use ($gpaPriority, $academicDetails) {

            // Pass beats Fail
            if ($a['result_status'] !== $b['result_status']) {
                return ($a['result_status'] === 'Pass') ? -1 : 1;
            }

            $aGpa = $this->getGpa($a);
            $bGpa = $this->getGpa($b);
            $aTM  = $this->getTotalMark($a);
            $bTM  = $this->getTotalMark($b);

            if ($gpaPriority) {
                if (!$this->floatEquals($aGpa, $bGpa)) return $bGpa <=> $aGpa;
                if (!$this->floatEquals($aTM,  $bTM))  return $bTM  <=> $aTM;
            } else {
                if (!$this->floatEquals($aTM,  $bTM))  return $bTM  <=> $aTM;
                if (!$this->floatEquals($aGpa, $bGpa)) return $bGpa <=> $aGpa;
            }

            // Tie-break: smaller roll gets higher position
            $aRoll = $academicDetails[$a['student_id']]['class_roll'] ?? PHP_INT_MAX;
            $bRoll = $academicDetails[$b['student_id']]['class_roll'] ?? PHP_INT_MAX;
            return $aRoll <=> $bRoll;
        })->values();
    }

    /**
     * Sort within a sub-group (section / shift / etc.)
     * Uses subjects recalculation as fallback when total_mark_with_optional absent.
     */
    private function sortGroup(
        Collection $students,
        bool $gpaPriority,
        Collection $academicDetails
    ): Collection {
        return $students->sort(function ($a, $b) use ($gpaPriority, $academicDetails) {

            // Pass beats Fail
            if ($a['result_status'] !== $b['result_status']) {
                return ($a['result_status'] === 'Pass') ? -1 : 1;
            }

            $aGpa = $this->getGpa($a);
            $bGpa = $this->getGpa($b);

            // Prefer stored total; fall back to subject recalculation
            $aTM = isset($a['total_mark_with_optional'])
                ? $this->toFloat($a['total_mark_with_optional'])
                : $this->calcTotalFromSubjects($a);

            $bTM = isset($b['total_mark_with_optional'])
                ? $this->toFloat($b['total_mark_with_optional'])
                : $this->calcTotalFromSubjects($b);

            if ($gpaPriority) {
                if (!$this->floatEquals($aGpa, $bGpa)) return $bGpa <=> $aGpa;
                if (!$this->floatEquals($aTM,  $bTM))  return $bTM  <=> $aTM;
            } else {
                if (!$this->floatEquals($aTM,  $bTM))  return $bTM  <=> $aTM;
                if (!$this->floatEquals($aGpa, $bGpa)) return $bGpa <=> $aGpa;
            }

            $aRoll = $academicDetails[$a['student_id']]['class_roll'] ?? PHP_INT_MAX;
            $bRoll = $academicDetails[$b['student_id']]['class_roll'] ?? PHP_INT_MAX;
            return $aRoll <=> $bRoll;
        })->values();
    }

    // ─────────────────────────────────────────────
    // RANK ASSIGNMENT (shared logic)
    // ─────────────────────────────────────────────

    /**
     * Core rank-assignment loop.
     *
     * Sequential   → rank = index + 1 (no duplicates, no skips)
     * Non-Sequential → same primary metric = same rank; next different rank
     *                  jumps by the number of tied students (standard competition ranking)
     *
     * @param  Collection $sorted   Already sorted students
     * @param  bool       $sequential
     * @param  bool       $gpaPriority  true = compare GPA, false = compare TotalMark
     * @return array      ['student_id' => ..., 'merit_position' => ..., ...]  (flat)
     */
    private function doRankAssignment(
        Collection $sorted,
        bool $sequential,
        bool $gpaPriority,
        Collection $academicDetails,
        Collection $studentDetails,
        ?string $compositeKey = null
    ): array {
        $ranked   = [];
        $lastRank = 0;
        // $tieCount = 0;          // how many students share the current rank
        $lastPrimary = null;

        $rankCounter = 0;
        foreach ($sorted as $student) {
            $rankCounter++;

            $stdId     = $student['student_id'];
            $acad      = $academicDetails[$stdId] ?? [];
            $std       = $studentDetails[$stdId]  ?? [];

            $totalMark = $this->getTotalMark($student);
            $gpa       = $this->getGpa($student);
            $primary   = $gpaPriority ? $gpa : $totalMark;

            if ($sequential) {
                // Sequential: rank = 1,2,3,4,... (no duplicates)
                $currentRank = $rankCounter;
                $lastRank    = $currentRank;
            } else {
                // Non-Sequential (standard competition / dense ranking)
                if ($rankCounter === 1) {
                    $currentRank = 1;
                    // $tieCount    = 1;
                } elseif ($this->floatEquals($primary, $lastPrimary)) {
                    // Same metric → same rank, increment tie counter
                    $currentRank = $lastRank;
                    // $tieCount++;
                } else {
                    // Different metric → next rank = lastRank + tieCount
                    // (standard competition: 1,1,2)
                    $currentRank = $lastRank + 1;
                }
                $lastRank = $currentRank;
            }

            $lastPrimary = $primary;

            $row = [
                'student_id'           => $stdId,
                'student_name'         => $student['student_name'],
                'roll'                 => $acad['class_roll'] ?? 0,
                'total_mark'           => $totalMark,
                'gpa'                  => round($gpa, 2),
                'gpa_without_optional' => round($this->toFloat($student['gpa_without_optional'] ?? 0), 2),
                'letter_grade'         => $student['letter_grade_with_optional'] ?? $student['letter_grade'] ?? 'F',
                'result_status'        => $student['result_status'],
                'merit_position'       => $currentRank,
                'shift'                => $acad['shift']    ?? null,
                'section'              => $acad['section']  ?? null,
                'group'                => $acad['group']    ?? null,
                'gender'               => $std['student_gender']   ?? null,
                'religion'             => $std['student_religion']  ?? null,
            ];

            if ($compositeKey !== null) {
                $row['shift_group_key'] = $compositeKey;
            }

            $ranked[] = $row;
        }

        return $ranked;
    }

    // ─────────────────────────────────────────────
    // CLASS-WISE RANKING
    // ─────────────────────────────────────────────

    private function assignRanks(
        Collection $sorted,
        string $meritType,
        Collection $academicDetails,
        Collection $studentDetails
    ): array {
        Log::info("assignRanks() — merit type: {$meritType}");

        return $this->doRankAssignment(
            $sorted,
            $this->isSequential($meritType),
            $this->isGradePointBased($meritType),
            $academicDetails,
            $studentDetails
        );
    }

    // ─────────────────────────────────────────────
    // SINGLE-FIELD GROUP RANKING (section/shift/group/gender/religion)
    // ─────────────────────────────────────────────

    /**
     * Group students by one field, sort each group independently,
     * then assign ranks within each group.
     *
     * FIX: Pass $results (original collection) not $allRanked so that
     * total_mark_with_optional is available and consistent.
     */
    private function rankByField(
        Collection $results,
        string $field,
        string $meritType,
        Collection $academicDetails,
        Collection $studentDetails
    ): array {
        Log::info("rankByField({$field}) — merit type: {$meritType}");

        $sequential   = $this->isSequential($meritType);
        $gpaPriority  = $this->isGradePointBased($meritType);

        return $results
            ->groupBy(fn($s) => $academicDetails[$s['student_id']][$field] ?? 'unknown')
            ->flatMap(function (Collection $groupStudents) use (
                $sequential,
                $gpaPriority,
                $academicDetails,
                $studentDetails
            ) {
                $sorted = $this->sortGroup($groupStudents, $gpaPriority, $academicDetails);

                return $this->doRankAssignment(
                    $sorted,
                    $sequential,
                    $gpaPriority,
                    $academicDetails,
                    $studentDetails
                );
            })
            ->values()
            ->toArray();
    }

    // ─────────────────────────────────────────────
    // COMPOSITE-FIELD GROUP RANKING (shift+group)
    // ─────────────────────────────────────────────

    /**
     * Group students by multiple fields combined (e.g. shift+group → "Morning-Science"),
     * sort each group independently, then assign ranks within each group.
     */
    private function rankByCompositeField(
        Collection $results,
        array $fields,
        string $meritType,
        Collection $academicDetails,
        Collection $studentDetails
    ): array {
        Log::info('rankByCompositeField(' . implode('+', $fields) . ') — merit type: ' . $meritType);

        $sequential  = $this->isSequential($meritType);
        $gpaPriority = $this->isGradePointBased($meritType);

        $grouped = $results->groupBy(function ($student) use ($fields, $academicDetails) {
            $acad = $academicDetails[$student['student_id']] ?? [];
            return collect($fields)
                ->map(fn($f) => $acad[$f] ?? 'Unknown')
                ->implode('-');
        });

        $output = [];

        foreach ($grouped as $compositeKey => $groupStudents) {
            Log::info("rankByCompositeField: processing [{$compositeKey}]", [
                'count' => $groupStudents->count(),
            ]);

            $sorted = $this->sortGroup($groupStudents, $gpaPriority, $academicDetails);

            $output[$compositeKey] = $this->doRankAssignment(
                $sorted,
                $sequential,
                $gpaPriority,
                $academicDetails,
                $studentDetails,
                $compositeKey
            );
        }

        return $output;
    }

    // ─────────────────────────────────────────────
    // SECTION WISE RANKING (shift + section composite, flat output)
    // ─────────────────────────────────────────────

    private function rankBySectionField(
        Collection $results,
        string $meritType,
        Collection $academicDetails,
        Collection $studentDetails
    ): array {
        $sequential  = $this->isSequential($meritType);
        $gpaPriority = $this->isGradePointBased($meritType);

        return $results
            ->groupBy(function ($s) use ($academicDetails) {
                $acad = $academicDetails[$s['student_id']] ?? [];
                $shift   = $acad['shift']   ?? 'Unknown';
                $section = $acad['section'] ?? 'Unknown';
                return "{$shift}-{$section}"; // e.g. "Morning-A", "Day-B"
            })
            ->flatMap(function (Collection $groupStudents) use (
                $sequential,
                $gpaPriority,
                $academicDetails,
                $studentDetails
            ) {
                $sorted = $this->sortGroup($groupStudents, $gpaPriority, $academicDetails);

                return $this->doRankAssignment(
                    $sorted,
                    $sequential,
                    $gpaPriority,
                    $academicDetails,
                    $studentDetails
                );
            })
            ->values()
            ->toArray();
    }
}
