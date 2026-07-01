<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ExamMarkCalculator
{
    public function calculate($payload)
    {
        $results = [];
        foreach ($payload['students'] as $student) {
            $results[] = $this->calculateStudent($student, $payload);
        }

        return [
            'results'      => $results,
            'institute_id' => $payload['institute_id'],
            'exam_type'    => 'semester',
            'exam_name'    => $payload['subjects'][0]['exam_name'] ?? 'Semester Exam',
            'subject_name' => $payload['subjects'][0]['subject_name'] ?? null,
        ];
    }

    private function calculateStudent($student, $payload)
    {
        $subject       = $payload['subjects'][0];
        $details       = collect($subject['exam_config']);
        $graceMark     = $subject['grace_mark'] ?? 0;
        $highestFail   = $subject['highest_fail_mark'] ?? 0;
        $gradePoints   = collect($payload['grade_points'] ?? []);
        $studentId     = $student['student_id'];
        $partMarks     = $student['part_marks'] ?? [];
        $examName      = $subject['exam_name'] ?? 'Semester Exam';
        $subjectName   = $subject['subject_name'] ?? null;
        $attendanceReq = $subject['attendance_required'] ?? false;
        $method        = $subject['method_of_evaluation'] ?? 'At Actual';

        /* =====================================================
         | ABSENT CHECK
         ===================================================== */
        $isAbsent = $attendanceReq && strtolower($student['attendance_status'] ?? 'absent') === 'absent';
        if ($isAbsent) {
            return $this->absentResult($studentId, $partMarks, $examName, $subjectName);
        }

        /* =====================================================
         | 1. MARK CALCULATION
         ===================================================== */
        $obtainedMark  = 0;  // Raw marks
        $convertedMark = 0;  // After conversion + evaluation method
        $totalMaxMark  = 0;

        foreach ($details as $d) {
            $got        = (float) ($partMarks[$d['exam_code_title']] ?? 0);
            $conversion = ((float) ($d['conversion'] ?? 100)) / 100;
            $total      = (float) ($d['total_mark'] ?? 0);

            // Raw obtained mark
            $obtainedMark += $got;

            // Converted obtained mark
            $convertedMark += round2(roundMark($got * $conversion, $method));

            // Converted max mark
            $totalMaxMark += round2(roundMark($total * $conversion, $method));
        }

        $obtainedMark  = round2($obtainedMark);
        $convertedMark = round2($convertedMark);
        $totalMaxMark  = round2($totalMaxMark);

        /* =====================================================
         | 2. CONFIG PRESENCE FLAGS
         ===================================================== */
        $hasPassMark = $details->where('pass_mark', '>', 0)->count() > 0;

        $overallDetail = $details->where('is_overall', true)->first();
        $hasOverall    = $overallDetail && ($overallDetail['overall_mark'] ?? 0) > 0;

        /* =====================================================
         | 3. INDIVIDUAL PASS CHECK (converted mark দিয়ে)
         ===================================================== */
        $individualPass = true;
        $failedParts    = [];

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
         | 4. OVERALL PASS CHECK
         ===================================================== */
        $overallPass     = true;
        $overallCalc     = 0;
        $overallRequired = 0;

        // if ($hasOverall) {
        //     foreach ($details as $d) {
        //         $got        = (float) ($partMarks[$d['exam_code_title']] ?? 0);
        //         $conversion = ((float) ($d['conversion'] ?? 100)) / 100;

        //         $overallCalc += round2(roundMark($got * $conversion, $method));
        //     }

        //     $overallRequired = $overallDetail['overall_mark'];
        //     $overallPass     = $overallCalc >= $overallRequired;
        // }
        if ($hasOverall) {
            foreach ($details as $d) {
                $got        = (float) ($partMarks[$d['exam_code_title']] ?? 0);
                $conversion = ((float) ($d['conversion'] ?? 100)) / 100;

                $overallCalc += round2(roundMark($got * $conversion, $method));
            }

            // ✅ overall_mark কে percentage হিসেবে treat করুন
            $overallRequiredPercentage = (float) $overallDetail['overall_mark']; // e.g. 33 (means 33%)

            // ✅ percentage থেকে actual mark বের করুন totalMaxMark এর সাপেক্ষে
            $overallRequired = round2(($overallRequiredPercentage / 100) * $totalMaxMark);

            $overallPass = $overallCalc >= $overallRequired;
        }

        /* =====================================================
         | 5. FINAL PASS DECISION
         ===================================================== */
        if ($hasPassMark && $hasOverall) {
            $pass = $individualPass && $overallPass;
        } elseif (!$hasPassMark && $hasOverall) {
            $pass = $overallPass;
        } elseif ($hasPassMark && !$hasOverall) {
            $pass = $individualPass;
        } else {
            $pass = true;
        }

        /* =====================================================
         | 6. GRACE MARK LOGIC
         | Only applies if:
         | - Student is failing
         | - needed > 0 (actually short, not already passing)
         | - needed <= graceMark (grace can cover the gap)
         | - individualPass = true (individual parts passed)
         ===================================================== */
        $appliedGrace    = 0;
        $finalMark       = $convertedMark;
        $passBeforeGrace = $pass;

        if (!$passBeforeGrace && $graceMark > 0 && $hasOverall) {
            $needed = $overallRequired - $overallCalc;

            // ✅ needed must be positive — student actually short
            // ✅ individual must pass — grace শুধু overall gap cover করে
            // ✅ grace দিলে pass হবে তখনই apply
            if ($needed > 0 && $needed <= $graceMark && $individualPass) {
                $appliedGrace = $needed;
                $finalMark    = $convertedMark + $appliedGrace;
                $pass         = true;
            }
        }

        /* =====================================================
         | 7. REMARK BUILDING
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
         | 8. PERCENTAGE
         ===================================================== */
        $percentage = ($totalMaxMark > 0)
            ? ($finalMark / $totalMaxMark) * 100
            : 0;

        /* =====================================================
         | 9. GRADE RESOLUTION
         ===================================================== */
        if ($pass) {
            $gradeInfo = $gradePoints->first(
                fn($g) => $percentage >= $g['from_mark'] && $percentage <= $g['to_mark']
            );
        } else {
            $gradeInfo = $gradePoints->first(
                fn($g) => $g['result'] === 'Fail'
            );
        }

        /* =====================================================
         | 10. RESPONSE
         ===================================================== */
        return [
            'student_id'        => $studentId,
            'obtained_mark'     => (float) $obtainedMark,
            'final_mark'        => (float) $finalMark,
            'grace_mark'        => (float) $appliedGrace,
            'result_status'     => $pass ? 'Pass' : 'Fail',
            'remark'            => $remark,
            'percentage'        => round($percentage, 2),
            'part_marks'        => $partMarks,
            'exam_name'         => $examName,
            'subject_name'      => $subjectName,
            'grade'             => $gradeInfo['grade'],
            'grade_point'       => $gradeInfo['grade_point'],
            'attendance_status' => $student['attendance_status'],
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
            'attendance_status' => 'absent',
        ];
    }
}
