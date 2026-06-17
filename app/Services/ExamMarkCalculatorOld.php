<?php

namespace App\Services;

class ExamMarkCalculatorOld
{
    public function calculate($payload)
    {
        $results = [];
        foreach ($payload['students'] as $student) {
            $results[] = $this->calculateStudent($student, $payload);
        }

        return [
            'results' => $results,
            'institute_id' => $payload['institute_id'],
            'exam_type' => 'semester',
            'exam_name' => $payload['subjects'][0]['exam_name'] ?? 'Semester Exam',
            'subject_name' => $payload['subjects'][0]['subject_name'] ?? null,
        ];
    }

    private function calculateStudent($student, $payload)
    {
        $subject        = $payload['subjects'][0];
        $details        = collect($subject['exam_config']);
        $graceMark      = $subject['grace_mark'] ?? 0;
        $highestFail    = $subject['highest_fail_mark'] ?? 0;
        $gradePoints    = collect($payload['grade_points'] ?? []);
        $studentId      = $student['student_id'];
        $partMarks      = $student['part_marks'] ?? [];
        $examName       = $subject['exam_name'] ?? 'Semester Exam';
        $subjectName    = $subject['subject_name'] ?? null;
        $attendanceReq  = $subject['attendance_required'] ?? false;
        $method         = $subject['method_of_evaluation'] ?? 'At Actual';

        /* =====================================================
         | ABSENT CHECK (UNCHANGED)
         ===================================================== */
        $isAbsent = $attendanceReq && strtolower($student['attendance_status'] ?? 'absent') === 'absent';
        if ($isAbsent) {
            return $this->absentResult($studentId, $partMarks, $examName, $subjectName);
        }

        /* =====================================================
         | 1. CONVERTED MARK CALCULATION (UNCHANGED)
         ===================================================== */
        // $obtainedMark = 0;
        // $totalMaxMark = 0;

        // foreach ($details as $d) {
        //     $got = $partMarks[$d['exam_code_title']] ?? 0;
        //     $conversion = ($d['conversion'] ?? 100) / 100;
        //     $total = $d['total_mark'] ?? 0;

        //     $obtainedMark += $got * $conversion;
        //     $totalMaxMark += $total * $conversion;
        // }
        $obtainedMark = 0;       // Raw marks (17+22+12=51)
        $convertedMark = 0;      // After conversion + evaluation method
        $totalMaxMark = 0;

        foreach ($details as $d) {

            $got = (float) ($partMarks[$d['exam_code_title']] ?? 0);

            $conversion = ((float) ($d['conversion'] ?? 100)) / 100;

            $total = (float) ($d['total_mark'] ?? 0);

            // $method = $d['method_of_evaluation'] ?? 'At Actual';

            // Raw obtained mark
            $obtainedMark += $got;

            // Converted obtained mark
            $converted = $got * $conversion;

            $convertedMark += round2(
                roundMark($converted, $method)
            );

            // Converted max mark
            $convertedMax = $total * $conversion;

            $totalMaxMark += round2(
                roundMark($convertedMax, $method)
            );
        }

        $obtainedMark  = round2($obtainedMark);
        $convertedMark = round2($convertedMark);
        $totalMaxMark  = round2($totalMaxMark);

        /* =====================================================
         | 2. CONFIG PRESENCE FLAGS  ✅ NEW
         ===================================================== */
        $hasPassMark = $details->where('pass_mark', '>', 0)->count() > 0;

        $overallDetail = $details->where('is_overall', true)->first();
        $hasOverall = $overallDetail && ($overallDetail['overall_mark'] ?? 0) > 0;

        /* =====================================================
         | 3. SHORT CODE PASS CHECK  🔄 UPDATED
         ===================================================== */
        $individualPass = true;
        $failedParts = [];

        if ($hasPassMark) {
            foreach ($details->where('pass_mark', '>', 0) as $d) {
                $got = $partMarks[$d['exam_code_title']] ?? 0;
                if ($got < $d['pass_mark']) {
                    $individualPass = false;
                    $failedParts[] = "{$d['exam_code_title']} ($got < {$d['pass_mark']})";
                }
            }
        }

        /* =====================================================
         | 4. OVERALL PASS CHECK  🔄 UPDATED
         ===================================================== */
        $overallPass = true;
        $overallCalc = 0;
        $overallRequired = 0;

        if ($hasOverall) {
            foreach ($details as $d) {
                // $overallCalc += ($partMarks[$d['exam_code_title']] ?? 0)
                //     * (($d['conversion'] ?? 100) / 100);
                $got = (float) ($partMarks[$d['exam_code_title']] ?? 0);

                $conversion = ((float) ($d['conversion'] ?? 100)) / 100;

                // $method = $d['method_of_evaluation'] ?? 'At Actual';

                $overallCalc += round2(
                    roundMark(
                        $got * $conversion,
                        $method
                    )
                );
            }

            $overallRequired = $overallDetail['overall_mark'];
            $overallPass = $overallCalc >= $overallRequired;
        }

        /* =====================================================
         | 5. FINAL PASS DECISION (CORE LOGIC)  ✅ FIXED
         ===================================================== */
        if ($hasPassMark && $hasOverall) {
            // both present → must pass both
            $pass = $individualPass && $overallPass;
        } elseif (!$hasPassMark && $hasOverall) {
            // only overall
            $pass = $overallPass;
        } elseif ($hasPassMark && !$hasOverall) {
            // only pass mark
            $pass = $individualPass;
        } else {
            $pass = true;
        }

        /* =====================================================
          | ✅ NEW: GRACE MARK LOGIC (Add this block here)
          | Only applies if student is failing and grace can make them pass
          ===================================================== */
        $appliedGrace = 0;
        $finalMark = $convertedMark;
        $passBeforeGrace = $pass;

        if (!$passBeforeGrace && $graceMark > 0 && $hasOverall) {
            $needed = $overallRequired - $overallCalc;

            if ($needed <= $graceMark) {
                $appliedGrace = $needed;
                $finalMark = $convertedMark + $appliedGrace;
                $pass = true; // Change status to Pass
            }
        }
        /* =====================================================
          | 6. REMARK BUILDING
          ===================================================== */
        $remark = '';
        if ($appliedGrace > 0) {
            $remark = "Pass by Grace (+{$appliedGrace} marks)";
        } elseif (!$pass) {
            $reasons = [];
            if ($hasPassMark && !$individualPass) {
                $reasons[] = 'Failed Individual: ' . implode(', ', $failedParts);
            }
            if ($hasOverall && !$overallPass) {
                $reasons[] = "Overall: $overallCalc < $overallRequired";
            }
            $remark = implode(' | ', $reasons);
        }

        /* =====================================================
          | 7. PERCENTAGE (Use $finalMark instead of $obtainedMark)
          ===================================================== */
        $percentage = ($totalMaxMark > 0)
            ? ($finalMark / $totalMaxMark) * 100
            : 0;

        /* =====================================================
         | 8. GRADE RESOLUTION (CONFIG DRIVEN)  ✅ FIXED
         ===================================================== */
        if ($pass) {
            $gradeInfo = $gradePoints->first(
                fn($g) =>
                $percentage >= $g['from_mark'] &&
                    $percentage <= $g['to_mark']
            );
        } else {
            $gradeInfo = $gradePoints->first(
                fn($g) =>
                $g['result'] === 'Fail'
            );
        }

        /* =====================================================
         | 9. RESPONSE (UNCHANGED)
         ===================================================== */
        return [
            'student_id'        => $studentId,
            'obtained_mark'     => (float) $obtainedMark,
            'final_mark'        => (float) $finalMark, // Updated
            'grace_mark'        => (float) $appliedGrace, // Updated
            'result_status'     => $pass ? 'Pass' : 'Fail',
            'remark'            => $remark,
            'percentage'        => round($percentage, 2),
            'part_marks'        => $partMarks,
            'exam_name'         => $examName,
            'subject_name'      => $subjectName,
            'grade'             => $gradeInfo['grade'],
            'grade_point'       => $gradeInfo['grade_point'],
            'attendance_status' => $student['attendance_status']
        ];
    }

    private function absentResult($studentId, $partMarks, $examName, $subjectName)
    {
        return [
            'student_id'        => $studentId,
            'obtained_mark'     => 0,
            'final_mark'        => 0,
            'grace_mark'        => 0,
            'result_status'     => 'Fail',
            'remark'            => 'Absent',
            'percentage'        => 0,
            'part_marks'        => $partMarks,
            'exam_name'         => $examName,
            'subject_name'      => $subjectName,
            'grade'             => 'F',
            'grade_point'       => '0.00',
            'attendance_status' => 'absent'
        ];
    }
}
