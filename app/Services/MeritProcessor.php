<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\FacadesLog;
use Illuminate\Support\Facades\Log;

class MeritProcessor
{

    public function process(array $payload): array
    {
        $results = $this->normalizeResults($payload['results'] ?? []);

        // 🔹 Deduplicate by student_id
        $results = $results->unique('student_id')->values();

        Log::info("\nStudents after deduplication", [
            'count' => $results->count()
        ]);

        if ($results->isEmpty()) {
            return ['status' => 'error', 'message' => 'No results found'];
        }

        $examConfig      = $payload['exam_config'] ?? [];
        $academicDetails = collect($payload['academic_details'] ?? []);
        $studentDetails  = collect($payload['student_details'] ?? []);
        $meritType       = $examConfig['merit_process_type'] ?? 'total_mark_sequential';
        $groupBy         = $this->getGroupByFields($examConfig);

        // DEBUG: Log incoming data
        Log::info('=== MERIT PROCESS START ===');
        Log::info('Total students received: ' . $results->count());
        Log::info('Merit type: ' . $meritType);
        Log::info('Group by fields: ' . json_encode($groupBy));

        // DEBUG: Check unique sections in incoming data
        $sections = $results->map(function ($student) use ($academicDetails) {
            $stdId = $student['student_id'];
            return $academicDetails[$stdId]['section'] ?? 'unknown';
        })->unique()->values()->toArray();


        // CRITICAL: Sort ALL students together first (entire class)
        $allSorted = $this->sortStudents($results, $meritType, $academicDetails);


        // CRITICAL: Assign ranks to ALL students together (class-wise ranking)
        $allRanked = $this->assignRanks($allSorted, $meritType, $academicDetails, $studentDetails);

        // DEBUG: Log first 10 students after ranking
        Log::info('First 10 students AFTER ranking:', collect($allRanked)->take(10)->map(function ($s) {
            return [
                'id' => $s['student_id'],
                'section' => $s['section'],
                'roll' => $s['roll'],
                'gpa' => $s['gpa'],
                'total_mark' => $s['total_mark'],
                'rank' => $s['merit_position'],
            ];
        })->toArray());

        // Now we have class-wise ranking (1,2,3... for entire class)
        $finalMerit = $allRanked;

        // DEBUG: Check for duplicate rank 1
        $rank1Students = collect($finalMerit)->where('merit_position', 1)->values();
        if ($rank1Students->count() > 1) {
            Log::error('PROBLEM: Multiple students with rank 1!', $rank1Students->toArray());
        } else {
            Log::info('OK: Only one student with rank 1', $rank1Students->toArray());
        }

        $all = collect($finalMerit);

        return [
            'total_students' => $results->count(),
            'merit_type'     => $meritType,
            'grouped_by'     => $groupBy,
            'data' => [
                // CLASS WISE (already correct)
                'all_students' => $finalMerit,

                // SECTION WISE
                'section_wise' => $this->rankByField(
                    $all,
                    'section',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // SHIFT WISE
                'shift_wise' => $this->rankByField(
                    $all,
                    'shift',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // GROUP WISE
                'group_wise' => $this->rankByField(
                    $all,
                    'group',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // GENDER WISE
                'gender_wise' => $this->rankByField(
                    $all,
                    'gender',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),

                // RELIGION WISE
                'religion_wise' => $this->rankByField(
                    $all,
                    'religion',
                    $meritType,
                    $academicDetails,
                    $studentDetails
                ),
            ]
        ];
    }

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
        if ($config['group_by_shift'] ?? false)    $fields[] = 'shift';
        if ($config['group_by_section'] ?? false)  $fields[] = 'section';
        if ($config['group_by_group'] ?? false)    $fields[] = 'group';
        if ($config['group_by_gender'] ?? false)   $fields[] = 'gender';
        if ($config['group_by_religion'] ?? false) $fields[] = 'religion';

        return empty($fields) ? [] : $fields; // ← [] মানে পুরো ক্লাস একসাথে
    }


    // private function getTotalMark($student): float
    // {
    //     $bonus = $student['optional_bonus'] ?? 0;
    //     // return collect($student['subjects'] ?? [])
    //     //     ->sum(fn($s) => $s['combined_final_mark'] ?? $s['final_mark'] ?? 0);

    //     return $bonus + collect($student['subjects'] ?? [])
    //         ->filter(fn($s) => $s['is_uncountable'] === false)
    //         ->sum(fn($s) => $s['combined_final_mark'] ?? $s['final_mark'] ?? 0);
    //     // ->sum(fn($s) => $s['final_mark'] ?? 0);

    // }

    private function sortStudents(Collection $students, string $meritType, Collection $academicDetails): Collection
    {
        $meritTypeLower = strtolower($meritType);
        // Determine if GPA is the primary driver
        $isGpaPriority = str_contains($meritTypeLower, 'grade point') || str_contains($meritTypeLower, 'gpa');

        return $students->sort(function ($a, $b) use ($isGpaPriority, $academicDetails) {
            // 1. Result Status (Pass always beats Fail)
            if ($a['result_status'] !== $b['result_status']) {
                return ($a['result_status'] === 'Pass') ? -1 : 1;
            }

            // Prepare Metrics
            $aGpa = (float) ($a['gpa_with_optional'] ?? $a['gpa'] ?? 0);
            $bGpa = (float) ($b['gpa_with_optional'] ?? $b['gpa'] ?? 0);
            $aTM  = $a['total_mark_with_optional'];
            $bTM  = $b['total_mark_with_optional'];

            if ($isGpaPriority) {
                // MODE: GPA -> Total Mark -> Roll
                if ($aGpa !== $bGpa) return $bGpa <=> $aGpa;
                if ($aTM  !== $bTM)  return $bTM  <=> $aTM;
            } else {
                // MODE: Total Mark -> GPA -> Roll
                if ($aTM  !== $bTM)  return $bTM  <=> $aTM;
                if ($aGpa !== $bGpa) return $bGpa <=> $aGpa;
            }

            // 4. Final tie-breaker: Class Roll (Lower roll number wins)
            $aRoll = $academicDetails[$a['student_id']]['class_roll'] ?? PHP_INT_MAX;
            $bRoll = $academicDetails[$b['student_id']]['class_roll'] ?? PHP_INT_MAX;

            return $aRoll <=> $bRoll;
        })->values();
    }

    private function assignRanks(
        Collection $sorted,
        string $meritType,
        Collection $academicDetails,
        Collection $studentDetails
    ): array {
        Log::info("Assigning ranks with merit type: {$meritType}");
        $isSequential = str_contains(strtolower($meritType), 'sequential');
        $useGpa = str_contains(strtolower($meritType), 'grade point') || str_contains(strtolower($meritType), 'gpa');

        $ranked = [];
        $lastRank = 0;
        $prevMetrics = null;

        foreach ($sorted as $index => $student) {

            $stdId = $student['student_id'];
            $acad  = $academicDetails[$stdId] ?? [];
            $std   = $studentDetails[$stdId] ?? [];

            $totalMark = $student['total_mark_with_optional'];
            $gpa       = (float) ($student['gpa_with_optional'] ?? $student['gpa'] ?? 0);

            if ($isSequential) {
                $currentRank = $index + 1;
            } else {
                if ($index === 0) {
                    $currentRank = 1;
                } else {
                    // If the primary and secondary metrics are identical, they share the rank
                    $isSameAsPrev = ($gpa == $prevMetrics['gpa'] && $totalMark == $prevMetrics['total_mark']);
                    $currentRank = $isSameAsPrev ? $lastRank : ($index + 1);
                }
            }

            $lastRank = $currentRank;
            $prevMetrics = ['gpa' => $gpa, 'total_mark' => $totalMark];

            $ranked[] = [
                'student_id'           => $stdId,
                'student_name'         => $student['student_name'],
                'roll'                 => $acad['class_roll'] ?? 0,
                'total_mark'           => $totalMark,
                'gpa'                  => round($gpa, 2),
                'gpa_without_optional' => round($student['gpa_without_optional'] ?? 0, 2),
                'letter_grade'         => $student['letter_grade_with_optional'] ?? $student['letter_grade'] ?? 'F',
                'result_status'        => $student['result_status'],
                'merit_position'       => $currentRank,
                'shift'                => $acad['shift'] ?? null,
                'section'              => $acad['section'] ?? null,
                'group'                => $acad['group'] ?? null,
                'gender'               => $std['student_gender'] ?? null,
                'religion'             => $std['student_religion'] ?? null,
            ];
        }

        return $ranked;
    }

    // merit type taking into account
    private function rankByField(
        Collection $results,
        string $field,
        string $meritType,
        Collection $academicDetails,
        Collection $studentDetails
    ): array {

        Log::info("Assigning ranks with merit type: {$meritType}");
        $isSequential = str_contains(strtolower($meritType), 'sequential');
        $useGpa = str_contains(strtolower($meritType), 'grade point') || str_contains(strtolower($meritType), 'gpa');

        Log::info("===== RANKING BY FIELD: {$field} =====", ['total_students' => $results->count(), 'gpa_based' => $useGpa, 'sequential' => $isSequential]);

        // Log::info("results", ['results' => $results->toArray()]);

        return $results
            ->groupBy(fn($s) => $academicDetails[$s['student_id']][$field] ?? 'unknown')
            ->flatMap(function (Collection $groupStudents) use ($isSequential, $useGpa, $academicDetails, $studentDetails) {
                // 🔹 Step 1: Sort students within the group
                $sorted = $groupStudents->sort(function ($a, $b) use ($useGpa, $academicDetails) {
                    $aId = $a['student_id'];
                    $bId = $b['student_id'];

                    $aGpa = (float) ($a['gpa_with_optional'] ?? $a['gpa'] ?? 0);
                    $bGpa = (float) ($b['gpa_with_optional'] ?? $b['gpa'] ?? 0);

                    $aTotal = collect($a['subjects'] ?? [])
                        ->filter(fn($s) => $s['is_uncountable'] === false)
                        ->sum(fn($s) => $s['combined_final_mark'] ?? $s['final_mark'] ?? 0);
                    $bTotal = collect($b['subjects'] ?? [])
                        ->filter(fn($s) => $s['is_uncountable'] === false)
                        ->sum(fn($s) => $s['combined_final_mark'] ?? $s['final_mark'] ?? 0);

                    if ($useGpa) {
                        // Merit type: Grade Point
                        if ($aGpa !== $bGpa) return $bGpa <=> $aGpa; // primary: GPA
                        if ($aTotal !== $bTotal) return $bTotal <=> $aTotal; // tie-breaker: total marks
                    } else {
                        // Merit type: Total Mark
                        if ($aTotal !== $bTotal) return $bTotal <=> $aTotal; // primary: total marks
                        if ($aGpa !== $bGpa) return $bGpa <=> $aGpa; // tie-breaker: GPA
                    }

                    // Last tie-breaker: class roll
                    $rollA = $academicDetails[$aId]['class_roll'] ?? PHP_INT_MAX;
                    $rollB = $academicDetails[$bId]['class_roll'] ?? PHP_INT_MAX;
                    return $rollA <=> $rollB;
                });


                // 🔹 Step 2: Assign ranks
                $ranked = [];
                $lastPrimary = null;
                $lastRank = 0;

                foreach ($sorted as $index => $student) {
                    // Log::info('studentDetails-2', ['student' => $student]);
                    $stdId = $student['student_id'];

                    // $totalMark = collect($student['subjects'] ?? [])
                    //     ->filter(fn($s) => $s['is_uncountable'] === false)
                    //     ->sum(fn($s) => $s['combined_final_mark'] ?? $s['final_mark'] ?? 0);

                    $totalMark = $student['total_mark'];

                    $gpa = (float) ($student['gpa_with_optional'] ?? $student['gpa'] ?? 0);

                    $primary = $useGpa ? $gpa : $totalMark;

                    if ($isSequential) {
                        $currentRank = $index + 1;
                    } else {
                        if ($index === 0) {
                            $currentRank = 1;
                        } elseif ($primary < $lastPrimary) {
                            $currentRank = $index + 1;
                        } else {
                            $currentRank = $lastRank;
                        }
                    }

                    $lastRank = $currentRank;
                    $lastPrimary = $primary;

                    $ranked[] = [
                        'student_id'     => $stdId,
                        'student_name'   => $student['student_name'],
                        'roll'           => $academicDetails[$stdId]['class_roll'] ?? 0,
                        'total_mark'     => $totalMark,
                        'gpa'            => round($gpa, 2),
                        'letter_grade'   => $student['letter_grade_with_optional'] ?? $student['letter_grade'] ?? 'F',
                        'result_status'  => $student['result_status'],
                        'merit_position' => $currentRank,
                        'shift'          => $academicDetails[$stdId]['shift'] ?? null,
                        'section'        => $academicDetails[$stdId]['section'] ?? null,
                        'group'          => $academicDetails[$stdId]['group'] ?? null,
                        'gender'         => $studentDetails[$stdId]['student_gender'] ?? null,
                        'religion'       => $studentDetails[$stdId]['student_religion'] ?? null,
                    ];
                }

                return $ranked;
            })
            ->values()
            ->toArray();
    }
}
