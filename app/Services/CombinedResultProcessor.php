<?php

namespace App\Services;

use Illuminate\Support\Collection;

class CombinedResultProcessor
{
    private const RESULT_RULES = ['Average', 'Percentage'];

    private const PASSING_RULES = [
        'Last Exam Pass/Fail',
        'Subject Wise Pass/Fail',
        'Average (Short Code)',
        'Average (Total Marks)',
    ];

    public function process(array $payload): array
    {
        $resultRules  = $payload['result_rules'] ?? null;
        $passingRules = $payload['passing_rules'] ?? null;
        $exams        = collect($payload['exams'] ?? [])->sortBy('sequence')->values();
        $gradeRules   = collect($payload['grade_rules'] ?? [])->sortByDesc('from_mark');
        $markConfigs  = $payload['mark_configs'] ?? [];
        $students     = $payload['students'] ?? [];

        if (!in_array($resultRules, self::RESULT_RULES, true)) {
            return $this->error('result_rules must be Average or Percentage');
        }

        if (!in_array($passingRules, self::PASSING_RULES, true)) {
            return $this->error('passing_rules must be one of: ' . implode(', ', self::PASSING_RULES));
        }

        if ($gradeRules->isEmpty()) {
            return $this->error('Grade rules missing');
        }

        if ($exams->isEmpty()) {
            return $this->error('No exams selected for combined result');
        }

        if (empty($students)) {
            return $this->envelope($gradeRules, [], []);
        }

        if ($passingRules === 'Average (Short Code)') {
            $mismatch = $this->findShortCodeMismatch($exams, $students);
            if ($mismatch) {
                return $this->error("Short code mismatch for {$mismatch}: exam codes must match across all selected exams for the Average (Short Code) passing rule.");
            }
        }

        $weights = $this->buildExamWeights($exams, $resultRules);

        $highest = collect();
        $results = [];

        foreach ($students as $student) {
            $row = $this->processStudent($student, $exams, $weights, $passingRules, $gradeRules, $markConfigs);
            $results[] = $row;

            foreach ($row['subjects'] as $subject) {
                $this->updateHighest($highest, $subject);
            }
        }

        return $this->envelope($gradeRules, $highest->values()->toArray(), $results);
    }

    private function envelope(Collection $gradeRules, array $highestMarks, array $results): array
    {
        return [
            'exam_name' => 'Combined Result',
            'has_combined' => true,
            'grade_rules' => $gradeRules->values()->toArray(),
            'highest_marks' => $highestMarks,
            'total_students' => count($results),
            'results' => $results,
        ];
    }

    private function error(string $message): array
    {
        return [
            'exam_name' => 'Combined Result',
            'has_combined' => true,
            'grade_rules' => [],
            'highest_marks' => [],
            'total_students' => 0,
            'results' => [],
            'error' => $message,
        ];
    }

    // ─────────────────────────────────────────────
    // WEIGHTS
    // ─────────────────────────────────────────────

    private function buildExamWeights(Collection $exams, string $resultRules): array
    {
        $weights = [];

        if ($resultRules === 'Percentage') {
            foreach ($exams as $exam) {
                $weights[$exam['exam_id']] = ((float) ($exam['percentage'] ?? 0)) / 100;
            }
            return $weights;
        }

        $count = $exams->count();
        foreach ($exams as $exam) {
            $weights[$exam['exam_id']] = $count > 0 ? 1 / $count : 0;
        }

        return $weights;
    }

    // ─────────────────────────────────────────────
    // SHORT CODE VALIDATION (Average (Short Code) precondition)
    // ─────────────────────────────────────────────

    private function findShortCodeMismatch(Collection $exams, array $students): ?string
    {
        foreach ($students as $student) {
            $perExam = $student['per_exam_results'] ?? [];
            $groups = $this->collectSubjectGroups($exams, $perExam);

            foreach ($groups as $meta) {
                $codeSets = [];

                foreach ($exams as $exam) {
                    $examResult = $perExam[$exam['exam_id']] ?? null;
                    if (!$examResult) continue;

                    if ($meta['is_combined']) {
                        $subj = $this->findSubjectInExam($examResult, $meta['combined_id'], true);
                        $codes = $subj ? array_keys($subj['combined_part_totals'] ?? []) : null;
                    } else {
                        $subj = $this->findSubjectInExam($examResult, $meta['subject_id'], false);
                        $codes = $subj ? array_keys($subj['part_marks'] ?? []) : null;
                    }

                    if ($codes === null) continue;
                    sort($codes);
                    $codeSets[] = implode(',', $codes);
                }

                if (count(array_unique($codeSets)) > 1) {
                    return $meta['is_combined']
                        ? "combined subject {$meta['combined_id']}"
                        : "subject {$meta['subject_id']}";
                }
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────
    // PER STUDENT
    // ─────────────────────────────────────────────

    private function processStudent(
        array $student,
        Collection $exams,
        array $weights,
        string $passingRules,
        Collection $gradeRules,
        array $markConfigs
    ): array {
        $perExam = $student['per_exam_results'] ?? [];

        $missingExams = [];
        foreach ($exams as $exam) {
            if (empty($perExam[$exam['exam_id']])) {
                $missingExams[] = $exam['exam_id'];
            }
        }

        if (!empty($missingExams)) {
            return $this->buildMissingExamResult($student, $exams, $perExam, $missingExams);
        }

        $lastExam = $exams->last();
        $lastExamPassed = ($perExam[$lastExam['exam_id']]['result_status'] ?? 'Fail') === 'Pass';

        $subjectGroups = $this->collectSubjectGroups($exams, $perExam);

        $subjects = [];
        $failedSubjectCount = 0;
        $anySubjectFailedAcrossExams = false;
        $totalMark = 0.0;
        $totalGP = 0.0;
        $subjectCount = 0;

        foreach ($subjectGroups as $meta) {
            $computed = $meta['is_combined']
                ? $this->processCombinedSubjectAcrossExams($meta, $exams, $perExam, $weights, $passingRules, $gradeRules)
                : $this->processSingleSubjectAcrossExams($meta, $exams, $perExam, $weights, $passingRules, $gradeRules, $markConfigs);

            $subjects[] = $computed['row'];

            if (!$computed['is_pass']) {
                $failedSubjectCount++;
            }
            if ($computed['any_exam_subject_failed']) {
                $anySubjectFailedAcrossExams = true;
            }

            $totalMark += $computed['final_mark'] ?? 0;
            $totalGP += $computed['grade_point'] ?? 0;
            $subjectCount++;
        }

        $resultStatus = match ($passingRules) {
            'Last Exam Pass/Fail' => $lastExamPassed ? 'Pass' : 'Fail',
            'Subject Wise Pass/Fail' => ($anySubjectFailedAcrossExams || $failedSubjectCount > 0) ? 'Fail' : 'Pass',
            default => $failedSubjectCount > 0 ? 'Fail' : 'Pass',
        };

        $gpa = $subjectCount > 0 ? round($totalGP / $subjectCount, 2) : 0.0;
        $totalMark = round2($totalMark);

        return [
            'student_id' => $student['student_id'] ?? null,
            'student_name' => $student['student_name'] ?? 'N/A',
            'roll' => $student['roll'] ?? null,
            'subjects' => $subjects,
            'total_mark_without_optional' => $totalMark,
            'gpa_without_optional' => format2($gpa),
            'total_mark_with_optional' => $totalMark,
            'gpa_with_optional' => format2($gpa),
            'result_status' => $resultStatus,
            'failed_subject_count' => $failedSubjectCount,
            'combination_meta' => ['missing_in_exams' => []],
        ];
    }

    private function buildMissingExamResult(array $student, Collection $exams, array $perExam, array $missingExams): array
    {
        $subjectGroups = $this->collectSubjectGroups($exams, $perExam);

        $subjects = [];
        foreach ($subjectGroups as $meta) {
            $subjects[] = $meta['is_combined']
                ? [
                    'combined_id' => $meta['combined_id'],
                    'combined_name' => $meta['combined_name'],
                    'is_combined' => true,
                    'final_mark' => null,
                    'combined_status' => 'Fail',
                ]
                : [
                    'subject_id' => $meta['subject_id'],
                    'subject_name' => $meta['subject_name'],
                    'is_combined' => false,
                    'final_mark' => null,
                    'grade' => 'F',
                    'is_pass' => false,
                ];
        }

        return [
            'student_id' => $student['student_id'] ?? null,
            'student_name' => $student['student_name'] ?? 'N/A',
            'roll' => $student['roll'] ?? null,
            'subjects' => $subjects,
            'total_mark_without_optional' => null,
            'gpa_without_optional' => format2(0),
            'total_mark_with_optional' => null,
            'gpa_with_optional' => format2(0),
            'result_status' => 'Fail',
            'failed_subject_count' => count($subjects),
            'combination_meta' => [
                'missing_in_exams' => $missingExams,
                'reason' => 'no_result_in_exam_' . implode('_', $missingExams),
            ],
        ];
    }

    // ─────────────────────────────────────────────
    // SUBJECT GROUPING
    // ─────────────────────────────────────────────

    private function collectSubjectGroups(Collection $exams, array $perExam): array
    {
        $groups = [];

        foreach ($exams as $exam) {
            $examResult = $perExam[$exam['exam_id']] ?? null;
            if (!$examResult) continue;

            foreach (($examResult['subjects'] ?? []) as $subject) {
                if ($subject['is_combined'] ?? false) {
                    $key = 'combined_' . $subject['combined_id'];
                    $groups[$key] ??= [
                        'is_combined' => true,
                        'combined_id' => $subject['combined_id'],
                        'combined_name' => $subject['combined_name'] ?? ($subject['combined_subject_name'] ?? null),
                    ];
                } else {
                    $key = 'subject_' . $subject['subject_id'];
                    $groups[$key] ??= [
                        'is_combined' => false,
                        'subject_id' => $subject['subject_id'],
                        'subject_name' => $subject['subject_name'] ?? null,
                    ];
                }
            }
        }

        return $groups;
    }

    private function findSubjectInExam(?array $examResult, $id, bool $combined): ?array
    {
        if (!$examResult) return null;

        foreach (($examResult['subjects'] ?? []) as $subject) {
            $isCombined = $subject['is_combined'] ?? false;
            if ($combined && $isCombined && (string) ($subject['combined_id'] ?? '') === (string) $id) {
                return $subject;
            }
            if (!$combined && !$isCombined && (string) ($subject['subject_id'] ?? '') === (string) $id) {
                return $subject;
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────
    // SINGLE SUBJECT — weighted across exams
    // ─────────────────────────────────────────────

    private function processSingleSubjectAcrossExams(
        array $meta,
        Collection $exams,
        array $perExam,
        array $weights,
        string $passingRules,
        Collection $gradeRules,
        array $markConfigs
    ): array {
        $subjectId = $meta['subject_id'];
        $config = $markConfigs[$subjectId] ?? [];

        $weightedParts = [];
        $anyExamSubjectFailed = false;

        foreach ($exams as $exam) {
            $weight = $weights[$exam['exam_id']] ?? 0;
            $examSubject = $this->findSubjectInExam($perExam[$exam['exam_id']] ?? null, $subjectId, false);
            if (!$examSubject) continue;

            if (($examSubject['grade'] ?? null) === 'F') {
                $anyExamSubjectFailed = true;
            }

            foreach (($examSubject['part_marks'] ?? []) as $code => $mark) {
                $weightedParts[$code] = ($weightedParts[$code] ?? 0) + ((float) $mark * $weight);
            }
        }

        foreach ($weightedParts as $code => $val) {
            $weightedParts[$code] = round2($val);
        }

        $convertedMark = 0.0;
        $totalMaxConverted = 0.0;
        $method = '';
        $codePassStatus = [];

        foreach ($weightedParts as $code => $obtained) {
            $method = $config['method_of_evaluation'][$code] ?? 'At Actual';
            $conversion = (float) ($config['conversion'][$code] ?? 100) / 100.0;
            $totalPart = (float) ($config['total_marks'][$code] ?? 0);
            $passMark = (float) ($config['pass_marks'][$code] ?? 0);

            $converted = $obtained * $conversion;
            $maxConverted = $totalPart * $conversion;

            $convertedMark += round2(roundMark($converted, $method));
            $totalMaxConverted += round2(roundMark($maxConverted, $method));

            $codePassStatus[$code] = $obtained >= $passMark;
        }

        $finalMark = round2($convertedMark);
        $totalMaxConverted = round2($totalMaxConverted);

        $rawPercentage = $totalMaxConverted > 0 ? round(($finalMark / $totalMaxConverted) * 100, 2) : 0;
        $gradePoint = $this->getGradePoint($rawPercentage, $gradeRules);
        $grade = $this->getGrade($rawPercentage, $gradeRules);

        $overallRequired = isset($config['overall_required']) ? (float) $config['overall_required'] : null;

        // Pass/fail is decided purely by the selected passing_rules check; the letter grade is a
        // separate percentage-based lookup and does not gate status either direction — except that
        // a failed subject always displays grade F for GPA consistency on the mark sheet.
        $isPass = match ($passingRules) {
            'Average (Short Code)' => !in_array(false, $codePassStatus, true)
                && ($overallRequired === null || $finalMark >= $overallRequired),
            'Subject Wise Pass/Fail' => !$anyExamSubjectFailed,
            default => $overallRequired === null || $finalMark >= $overallRequired,
        };

        if (!$isPass) {
            $grade = 'F';
            $gradePoint = 0.0;
        }

        $row = [
            'subject_id' => $subjectId,
            'subject_name' => $meta['subject_name'],
            'is_combined' => false,
            'final_mark' => $finalMark,
            'part_marks' => $weightedParts,
            'grade' => $grade,
            'grade_point' => format2($gradePoint),
            'is_pass' => $isPass,
        ];

        if ($passingRules === 'Average (Short Code)') {
            $row['code_pass_status'] = $codePassStatus;
        }

        return [
            'row' => $row,
            'is_pass' => $isPass,
            'any_exam_subject_failed' => $anyExamSubjectFailed,
            'final_mark' => $finalMark,
            'grade_point' => $gradePoint,
        ];
    }

    // ─────────────────────────────────────────────
    // COMBINED SUBJECT (pair) — weighted across exams
    // ─────────────────────────────────────────────

    private function processCombinedSubjectAcrossExams(
        array $meta,
        Collection $exams,
        array $perExam,
        array $weights,
        string $passingRules,
        Collection $gradeRules
    ): array {
        $combinedId = $meta['combined_id'];

        // Parts (e.g. Bangla 1st/2nd) have their own mark_configs that this payload doesn't carry,
        // so we can't re-run conversion here. Per spec: reuse each exam's already-computed final_mark
        // for the total, and separately weight combined_part_totals (raw, pre-conversion) only for
        // the per-code pass check — the two are on different scales and are not summed together.
        $weightedFinalMark = 0.0;
        $weightedPartTotals = [];
        $weightedPassRequirements = [];
        $weightedMaxMark = 0.0;
        $anyExamSubjectFailed = false;

        foreach ($exams as $exam) {
            $weight = $weights[$exam['exam_id']] ?? 0;
            $examCombined = $this->findSubjectInExam($perExam[$exam['exam_id']] ?? null, $combinedId, true);
            if (!$examCombined) continue;

            if (($examCombined['combined_status'] ?? null) === 'Fail') {
                $anyExamSubjectFailed = true;
            }

            $weightedFinalMark += ((float) ($examCombined['final_mark'] ?? 0)) * $weight;

            foreach (($examCombined['combined_part_totals'] ?? []) as $code => $mark) {
                $weightedPartTotals[$code] = ($weightedPartTotals[$code] ?? 0) + ((float) $mark * $weight);
            }
            foreach (($examCombined['combined_pass_requirements'] ?? []) as $code => $req) {
                $weightedPassRequirements[$code] = ($weightedPassRequirements[$code] ?? 0) + ((float) $req * $weight);
            }

            $weightedMaxMark += ((float) ($examCombined['total_max_mark'] ?? 0)) * $weight;
        }

        foreach ($weightedPartTotals as $code => $val) {
            $weightedPartTotals[$code] = round2($val);
        }
        foreach ($weightedPassRequirements as $code => $val) {
            $weightedPassRequirements[$code] = round2($val);
        }

        $finalMark = round2($weightedFinalMark);
        $weightedMaxMark = round2($weightedMaxMark);

        $rawPercentage = $weightedMaxMark > 0 ? round(($finalMark / $weightedMaxMark) * 100, 2) : 0;
        $gradePoint = $this->getGradePoint($rawPercentage, $gradeRules);
        $grade = $this->getGrade($rawPercentage, $gradeRules);

        $codePassStatus = [];
        foreach ($weightedPartTotals as $code => $obtained) {
            $codePassStatus[$code] = $obtained >= ($weightedPassRequirements[$code] ?? 0);
        }

        // Pass/fail is decided purely by the selected passing_rules check; the letter grade is a
        // separate percentage-based lookup and does not gate status either direction — except that
        // a failed subject always displays grade F for GPA consistency on the mark sheet.
        $isPass = match ($passingRules) {
            'Subject Wise Pass/Fail' => !$anyExamSubjectFailed,
            'Average (Total)' => $grade !== 'F',
            default => !in_array(false, $codePassStatus, true), // Average (Short Code), Last Exam Pass/Fail
        };

        if (!$isPass) {
            $grade = 'F';
            $gradePoint = 0.0;
        }

        $row = [
            'combined_id' => $combinedId,
            'combined_name' => $meta['combined_name'],
            'is_combined' => true,
            'final_mark' => $finalMark,
            'combined_part_totals' => $weightedPartTotals,
            'combined_grade' => $grade,
            'combined_grade_point' => format2($gradePoint),
            'combined_status' => $isPass ? 'Pass' : 'Fail',
        ];

        if ($passingRules === 'Average (Short Code)') {
            $row['code_pass_status'] = $codePassStatus;
        }

        return [
            'row' => $row,
            'is_pass' => $isPass,
            'any_exam_subject_failed' => $anyExamSubjectFailed,
            'final_mark' => $finalMark,
            'grade_point' => $gradePoint,
        ];
    }

    // ─────────────────────────────────────────────
    // HIGHEST MARKS
    // ─────────────────────────────────────────────

    private function updateHighest(Collection $collection, array $subject): void
    {
        $mark = $subject['final_mark'] ?? null;
        if ($mark === null) return;

        if ($subject['is_combined'] ?? false) {
            $id = $subject['combined_id'] ?? null;
            if ($id === null) return;
            $key = "combined_{$id}";
            $name = $subject['combined_name'] ?? null;
        } else {
            $id = $subject['subject_id'] ?? null;
            if ($id === null) return;
            $key = (string) $id;
            $name = $subject['subject_name'] ?? null;
        }

        $current = $collection->get($key);
        if ($current === null || $mark > $current['highest_mark']) {
            $collection->put($key, [
                'subject_id' => $id,
                'subject_name' => $name,
                'highest_mark' => $mark,
            ]);
        }
    }

    // ─────────────────────────────────────────────
    // GRADE LOOKUP (mirrors ResultCalculator::getGradePoint/getGrade)
    // ─────────────────────────────────────────────

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
}
