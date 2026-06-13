<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ResultCalculator
{
    public function calculate($payload)
    {
        $mark_configs = $payload['mark_configs'] ?? [];
        $gradeRules = collect($payload['grade_rules'] ?? [])->sortByDesc('from_mark');

        if ($gradeRules->isEmpty()) {
            return [
                'results' => [],
                'highest_marks' => [],
                'total_students' => 0,
                'error' => 'Grade rules missing'
            ];
        }

        $students = $payload['students'] ?? [];
        if (empty($students)) {
            return ['results' => [], 'highest_marks' => [], 'total_students' => 0];
        }

        $results = [];
        $highestCollection = collect();

        foreach ($students as $student) {
            $result = $this->processStudent($student, $gradeRules, $mark_configs);
            $results[] = $result;

            foreach ($result['subjects'] as $s) {
                if (isset($s['combined_name']) && isset($s['parts'])) {
                    foreach ($s['parts'] as $part) {
                        $this->updateHighest($highestCollection, $part);
                    }
                    continue;
                }

                if (isset($s['subject_id']) && is_numeric($s['subject_id']) && !($s['is_combined'] ?? false)) {
                    $this->updateHighest($highestCollection, $s);
                }
            }
        }

        return [
            'has_combined' => $payload['has_combined'] ?? false,
            'exam_name' => $payload['exam_name'],
            'results' => $results,
            'highest_marks' => $highestCollection->values()->toArray(),
            'total_students' => count($results),
        ];
    }

    private function updateHighest(Collection $collection, array $subject)
    {
        $id = $subject['subject_id'] ?? null;
        if (!$id || !is_numeric($id)) return;

        $name = $subject['subject_name'];
        $mark = $subject['final_mark'] ?? 0;
        $key = "single_{$id}";

        if (!$collection->has($key)) {
            $collection->put($key, [
                'subject_id' => (int) $id,
                'subject_name' => $name,
                'highest_mark' => $mark
            ]);
        } else {
            $current = $collection->get($key);
            if ($mark > $current['highest_mark']) {
                $collection->put($key, [
                    'subject_id' => (int) $id,
                    'subject_name' => $name,
                    'highest_mark' => $mark
                ]);
            }
        }
    }

    private function processStudent($student, $gradeRules, $mark_configs)
    {
        $marks = $student['marks'] ?? [];
        $optionalId = $student['optional_subject_id'] ?? null;

        $groups = collect($marks)->groupBy(fn($m) => $m['combined_id'] ?? $m['subject_id']);

        $merged = [];
        $totalGP = 0;
        $subjectCount = 0;
        $failed = false;
        $fourthSubjectPassed = false;
        $totalFailCount = 0;

        $totalMarkWithoutOptional = 0.0;  // মূল মার্ক (৪র্থ ছাড়া)
        $totalMarkWithOptional    = 0.0;  // ৪র্থ পুরো মার্ক যোগ

        foreach ($groups as $groupId => $group) {
            if ($group->isEmpty()) continue;
            $first = $group->first();

            if ($first['is_combined']) {
                $combinedResult = $this->processCombinedGroup($group, $gradeRules, $mark_configs);
                $merged[] = $combinedResult;

                if (!($combinedResult['is_uncountable'] ?? false)) {
                    $totalGP += $combinedResult['combined_grade_point'];
                    $subjectCount++;
                }
                if ($combinedResult['combined_status'] === 'Fail') {
                    $failed = true;
                    $totalFailCount++;
                    // if (!($combinedResult['is_uncountable'] ?? false)) {
                    //     $failed = true;
                    //     // $totalFailCount++;
                    // }
                }

                $totalMarkWithoutOptional += $combinedResult['final_mark'];
            } else {
                $single = $this->processSingle($first, $gradeRules, $mark_configs);

                $single['is_optional'] = ($first['subject_id'] == $optionalId);
                $merged[] = $single;

                if (!$single['is_uncountable'] && !$single['is_optional']) {
                    $totalGP += $single['grade_point'];
                    $subjectCount++;
                }
                if ($single['grade'] === 'F' && !$single['is_uncountable'] && !$single['is_optional']) {
                    $failed = true;
                    $totalGP = 0;
                    $totalFailCount++;
                }

                if (!$single['is_optional'] && !$single['is_uncountable']) {
                    $totalMarkWithoutOptional += $single['final_mark'];
                }

                if ($single['is_optional'] ?? false) {
                    if ($single['grade'] !== 'F') {
                        $fourthSubjectPassed = true;
                    }
                }
            }
        }

        // === 4th Subject Bonus ===
        $optionalFullMark = 0.0;
        $bonusGPFromOptional = 0.0;
        $bonusMarkFromOptional = 0.0;

        if ($fourthSubjectPassed == true && $optionalId && isset($marks[$optionalId])) {

            $opt = $marks[$optionalId];
            $optional_mark_config = $mark_configs[$optionalId] ?? [];
            $total_of_optional = collect($optional_mark_config['total_marks'] ?? [])
                ->map(function ($mark, $code) use ($optional_mark_config) {
                    $conversion = ($optional_mark_config['conversion'][$code] ?? 100) / 100;
                    return $mark * $conversion;
                })
                ->sum();

            $percentage_of_optional = $total_of_optional > 0 ? ($total_of_optional * 0.40) : 0;

            $method = collect($optional_mark_config['method_of_evaluation'] ?? [])
                ->first() ?? 'At Actual';

            $optionalFullMark = $opt['final_mark'] ?? 0;
            $optGP = $opt['grade_point'] ?? 0;

            if ($optGP >= 2.00) {
                $bonusGPFromOptional = max(0, $optGP - 2.00);
            }
            $bonusMarkFromOptional = round2(roundMark(max(0, $optionalFullMark - $percentage_of_optional), $method));
        }

        $maxGradePoint = collect($gradeRules)->max('grade_point');

        $totalMarkWithOptional = $totalMarkWithoutOptional + $bonusMarkFromOptional;

        $finalGP = $failed ? 0 : ($totalGP + $bonusGPFromOptional);
        // $gpaWithoutOptional = $subjectCount > 0 ? round($totalGP / $subjectCount, 2) : 0;
        $rawGpaWithoutOptional = $subjectCount > 0 ? round($totalGP / $subjectCount, 2) : 0;
        $gpaWithoutOptional = min($rawGpaWithoutOptional, $maxGradePoint);
        // $gpaWithOptional    = $subjectCount > 0 ? round($finalGP / $subjectCount, 2) : 0;
        $rawGpaWithOptional = $subjectCount > 0 ? round($finalGP / $subjectCount, 2) : 0;
        $gpaWithOptional = min($rawGpaWithOptional, $maxGradePoint);


        $status = $failed ? 'Fail' : 'Pass';

        $letterGradeWithout = $this->gpaToLetterGrade($gpaWithoutOptional, $gradeRules);
        $letterGradeWith     = $this->gpaToLetterGrade($gpaWithOptional, $gradeRules);

        return [
            'student_id' => $student['student_id'] ?? 0,
            'student_name' => $student['student_name'] ?? 'N/A',
            'roll' => $student['roll'] ?? 'N/A',
            'subjects' => $merged,
            'total_mark_without_optional' => round2($totalMarkWithoutOptional),
            'gpa_without_optional' => $failed ? 0 : format2($gpaWithoutOptional),
            'letter_grade_without_optional' => $failed ? 'F' : $letterGradeWithout,
            'total_mark_with_optional' => round2($totalMarkWithOptional),
            'gpa_with_optional' => format2($gpaWithOptional),
            'letter_grade_with_optional' => $letterGradeWith,
            'result_status' => $status,
            'optional_bonus_gp' => format2($bonusGPFromOptional),
            'optional_bonus_mark' => $bonusMarkFromOptional,
            'failed_subject_count' => $totalFailCount,
        ];
    }

    private function processSingle($subj, $gradeRules, $mark_configs)
    {
        $subjectId = $subj['subject_id'];
        $config = $mark_configs[$subjectId] ?? [];
        $partMarks = $subj['part_marks'] ?? [];

        $totalMarks = collect($partMarks)->sum();

        $convertedMark = 0;
        $totalMaxConverted = 0;
        $method = '';

        foreach ($partMarks as $code => $obtained) {
            $method = $config['method_of_evaluation'][$code] ?? 'At Actual';
            $conversion = $config['conversion'][$code] ?? 100;
            $converted = $obtained * ($conversion / 100);

            $convertedMark += round2(roundMark($converted, $method));

            // ✅ FIX: max mark ও same conversion দিয়ে calculate করুন
            $totalPart = (float) ($config['total_marks'][$code] ?? 0);
            $maxConverted = $totalPart * ($conversion / 100);
            $totalMaxConverted += round2(roundMark($maxConverted, $method));
        }

        $convertedMark = round2($convertedMark);       // ✅ accumulation শেষে round
        $totalMaxConverted = round2($totalMaxConverted); // ✅ accumulation শেষে round

        $grace = $subj['grace_mark'] ?? 0;
        $finalMark = round2(roundMark($convertedMark + $grace, $method));

        // $totalMaxConverted = collect($config['total_marks'] ?? [])->sum();

        // // 1. Calculate raw percentage
        // $rawPercentage = $totalMaxConverted > 0 ? ($finalMark / $totalMaxConverted) * 100 : 0;

        // // 2. Round the percentage for rule lookup (Fixes the 32.5% or 49.5% gap issue)
        // $lookupPercentage = round($rawPercentage);

        // // 3. Get Grade and GP based on the rounded percentage
        // $gradePoint = $this->getGradePoint($lookupPercentage, $gradeRules);
        // $grade      = $this->getGrade($lookupPercentage, $gradeRules);

        // $rawPercentage = $totalMaxConverted > 0 ? ($finalMark / $totalMaxConverted) * 100 : 0;

        $rawPercentage = $totalMaxConverted > 0
            ? round(($finalMark / $totalMaxConverted) * 100, 2)  // ✅ round to 2 decimal places
            : 0;
        $lookupPercentage = round($rawPercentage);

        // ✅ FIX: Check if ExamMarkCalculator already marked this as Fail
        if (($subj['grade'] ?? '') === 'F' || ($subj['result_status'] ?? '') === 'Fail') {
            $gradePoint = $subj['grade_point'];
            $grade      = $subj['grade'];
        } else {
            $gradePoint = $this->getGradePoint($lookupPercentage, $gradeRules);
            $grade      = $this->getGrade($lookupPercentage, $gradeRules);
        }

        return [
            'subject_id'     => $subjectId,
            'subject_name'   => $subj['subject_name'],
            'part_marks'     => $partMarks,
            'total_marks'    => $totalMarks,
            'converted_mark' => round2($convertedMark),
            'final_mark'     => round2($finalMark),
            'grace_mark'     => $grace,
            'percentage'     => $rawPercentage, // Keep raw for display/transcripts

            'grade_point'    => format2($gradePoint), // Use newly calculated GP
            'grade'          => $grade,               // Use newly calculated Grade

            'is_uncountable' => ($subj['subject_type'] ?? '') === 'Uncountable',
            'is_combined'    => false,
            'attendance_status' => $subj['attendance_status'] ?? null,
        ];
    }

    private function processCombinedGroup($group, $gradeRules, $mark_configs)
    {
        $combinedId   = $group->first()['combined_id'];
        $combinedName = $group->first()['combined_subject_name'];

        $totalObtained = 0;
        $totalMaxMark  = 0;

        // To track combined totals for CQ, MCQ, etc.
        $combinedPartTotals = [];
        $combinedPassRequirements = [];

        $parts = $group->map(function ($mark) use ($mark_configs, &$totalObtained, &$totalMaxMark, &$combinedPartTotals, &$combinedPassRequirements) {
            $subjectId = $mark['subject_id'];
            $config    = $mark_configs[$subjectId] ?? [];
            $partMarks = $mark['part_marks'] ?? [];
            $method    = '';

            $convertedMark = 0.0;
            $thisPaperConvertedMax = 0.0;

            foreach ($partMarks as $code => $obtained) {
                $method = $config['method_of_evaluation'][$code] ?? 'At Actual';
                $conversion = (float) ($config['conversion'][$code] ?? 100) / 100.0;
                $totalPart  = (float) ($config['total_marks'][$code] ?? 0);
                $passMark   = (float) ($config['pass_marks'][$code] ?? 0); // Get pass mark from config

                $converted = $obtained * $conversion;
                $maxConverted = $totalPart * $conversion;

                $convertedMark += round2(roundMark($converted, $method));
                $thisPaperConvertedMax += round2(roundMark($maxConverted, $method));

                // Aggregate obtained marks and pass requirements by Code (MCQ, CQ, etc.)
                $combinedPartTotals[$code] = ($combinedPartTotals[$code] ?? 0) + $obtained;
                $combinedPassRequirements[$code] = ($combinedPassRequirements[$code] ?? 0) + $passMark;
            }

            // ✅ FIX: accumulation শেষে একবার round2 করুন
            $convertedMark         = round2($convertedMark);
            $thisPaperConvertedMax = round2($thisPaperConvertedMax);

            $grace = (float) ($mark['grace_mark'] ?? 0);
            $finalMark = round2(roundMark($convertedMark + $grace, $method));

            $totalObtained += $finalMark;
            $totalMaxMark  += $thisPaperConvertedMax;

            return [
                'subject_id'        => $subjectId,
                'subject_name'      => $mark['subject_name'],
                'part_marks'        => $partMarks,
                'total_marks'       => collect($partMarks)->sum(),
                'converted_mark'    => round2($convertedMark),
                'final_mark'        => $finalMark,
                'grace_mark'        => $grace,
                'grade'             => $mark['grade'] ?? null,
                'grade_point'       => format2($mark['grade_point'] ?? 0),
                'attendance_status' => $mark['attendance_status'] ?? null,
            ];
        })->values()->toArray();

        // 1. Calculate base Grade/GP from total percentage
        $rawPercentage = $totalMaxMark > 0 ? ($totalObtained / $totalMaxMark) * 100 : 0;
        $lookupPercentage = round($rawPercentage);

        $combinedGradePoint = $this->getGradePoint($lookupPercentage, $gradeRules);
        $combinedGrade      = $this->getGrade($lookupPercentage, $gradeRules);

        // 2. APPLY EXAM-CODE WISE PASS RULES (MCQ+MCQ, CQ+CQ)
        $failedByPart = false;
        foreach ($combinedPartTotals as $code => $obtainedSum) {
            $requiredSum = $combinedPassRequirements[$code] ?? 0;
            if ($obtainedSum < $requiredSum) {
                $failedByPart = true;
                break;
            }
        }

        if ($failedByPart) {
            $combinedGradePoint = 0.00;
            $combinedGrade = 'F';
        }

        $combinedStatus = ($combinedGrade === 'F') ? 'Fail' : 'Pass';

        return [
            'combined_id'           => $combinedId,
            'is_combined'           => true,
            'combined_name'         => $combinedName,
            'total_marks'           => $totalObtained,
            'final_mark'            => $totalObtained,
            'total_max_mark'        => $totalMaxMark,
            'percentage'            => $rawPercentage,
            'combined_grade_point'  => format2($combinedGradePoint),
            'combined_grade'        => $combinedGrade,
            'combined_status'       => $combinedStatus,
            'parts'                 => $parts,
            'is_uncountable'        => false,
            // Added for debugging/transparency in UI
            'combined_part_totals'  => $combinedPartTotals,
            'combined_pass_requirements' => $combinedPassRequirements
        ];
    }



    // === GPA থেকে Letter Grade (SSC/HSC) ===
    private function gpaToLetterGrade($gpa, $gradeRules)
    {
        foreach ($gradeRules->sortByDesc('grade_point') as $rule) {
            if ($gpa >= $rule['grade_point']) {
                return $rule['grade'];
            }
        }
    }

    private function getGradePoint($percentage, $gradeRules)
    {
        foreach ($gradeRules as $rule) {
            if ($percentage >= $rule['from_mark'] && $percentage <= $rule['to_mark']) {
                return $rule['grade_point'];
            }
        }
        return 0.0;
    }

    private function getGrade($percentage, $gradeRules)
    {
        foreach ($gradeRules as $rule) {
            if ($percentage >= $rule['from_mark'] && $percentage <= $rule['to_mark']) {
                return $rule['grade'];
            }
        }
        return 'F';
    }

    private function markToGradePoint($mark, $gradeRules)
    {
        return $this->getGradePoint($mark, $gradeRules);
    }

    private function gpToGrade($mark, $gradeRules)
    {
        return $this->getGrade($mark, $gradeRules);
    }
}
