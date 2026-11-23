# Exam Flex

A calculation service for school and college exams. It turns raw marks into results, grades, GPA, and merit positions, and it combines results from several exams into one.

Client systems send marks to this service over HTTP and get calculated results back. This service does not store results. It only calculates them.

---

## Why this service exists

Exam rules differ between institutes and boards: pass marks per component, optional subjects, grace marks, rounding rules, grade scales, and ranking methods. Keeping that logic in one place means:

- Every institute that uses the system gets the same rules applied in the same way.
- Changing a calculation rule is done once, in one service, and does not need a change in every client.
- The rules can be tested and documented separately from the client applications.

The service is built on Laravel and exposes its calculations as JSON API endpoints.

---

## What it does

| Step | Endpoint | What it calculates |
|---|---|---|
| 1. Store subject config | `POST /api/mark-entry/config` | Saves the mark configuration for one subject and returns a `temp_id` (valid 2 hours) |
| 2. Calculate subject marks | `POST /api/mark-entry/process` | Per-student marks, pass/fail, grace marks, grade for one subject |
| 3. Build results | `POST /api/result-process` | Full result per student: all subjects, GPA, letter grade, pass/fail, highest marks |
| 4. Rank students | `POST /api/merit-process` | Merit position for the whole class and within each section, shift, group, gender, and religion |
| 5. Combine exams | `POST /api/combined-result-process` | One result per student across several exams (for example Semester 1 + Semester 2) |
| 6. Rank combined results | `POST /api/combined-merit-process` | Merit positions for combined results |

Typical flow:

```
mark entry ──► /mark-entry/config ──► /mark-entry/process ──┐
                                                            ▼
                                             /result-process (per exam)
                                                            │
                          ┌─────────────────────────────────┴──────────────┐
                          ▼                                                 ▼
                 /merit-process                          /combined-result-process
                                                                  │
                                                                  ▼
                                                    /combined-merit-process
```

---

## How the calculations work

Each endpoint is documented in the [Calculation reference](#calculation-reference) below, with its input fields, output fields, rules, and worked examples that were run against the real code. It covers each service class:

- `ExamService`: client authentication
- `ExamMarkCalculator`: subject marks and grace
- `ResultCalculator`: subject totals, GPA, grades, and optional-subject bonus
- `MeritProcessor`: ranking and tie-breaking
- `CombinedResultProcessor`: weighting across exams and pass rules

Start there when you need to know why a student got a particular grade or position.

---

## Authentication

Every endpoint requires HTTP Basic authentication. Each client (institute) has a username and password stored as a hash in the `client_domains` table.

```
Authorization: Basic <base64(username:password)>
```

---

## Requirements

- PHP 8.1 or higher (developed and tested with PHP 8.2)
- Composer
- MySQL (the default connection in `.env.example`)

---

## Setup

```bash
# 1. Install dependencies
composer install

# 2. Create your environment file and set the app key
cp .env.example .env
php artisan key:generate

# 3. Edit .env and set DB_DATABASE, DB_USERNAME, DB_PASSWORD

# 4. Create the tables
php artisan migrate

# 5. Start the development server
php artisan serve
```

The API is then available at `http://localhost:8000/api/...`.

The `client_domains` table is used for authentication but has no migration in this repository. Create it in your database before calling any endpoint. See [Known issues](#known-issues).

---

## Example request

Calculate results for one exam. Replace the credentials with real values.

```bash
curl -X POST http://localhost:8000/api/result-process \
  -H "Authorization: Basic $(printf 'client_user:client_pass' | base64)" \
  -H "Content-Type: application/json" \
  -d '{
        "institute_id": 1001,
        "exam_name": "Semester Final",
        "has_combined": false,
        "grade_rules": [
          {"grade": "A+", "grade_point": 5.00, "from_mark": 80, "to_mark": 100, "result": "Pass"},
          {"grade": "A",  "grade_point": 4.00, "from_mark": 70, "to_mark": 79.99, "result": "Pass"},
          {"grade": "F",  "grade_point": 0.00, "from_mark": 0,  "to_mark": 39.99, "result": "Fail"}
        ],
        "students": [
          {"student_id": 101, "student_name": "Rahim", "roll": 1, "optional_subject_id": null, "marks": []}
        ]
      }'
```

Successful responses return HTTP 202 with `status: "success"` and the calculated `results`. The full field list and a worked example for each endpoint are in the [Calculation reference](#calculation-reference).

---

## Project layout

| Path | Contents |
|---|---|
| `app/Http/Controllers/MarkEntryController.php` | HTTP endpoints: auth, validation, calls into services |
| `app/Services/` | Calculation logic (documented in the [Calculation reference](#calculation-reference)) |
| `app/Helpers/helpers.php` | Rounding helpers (`roundMark`, `round2`, `format2`) |
| `app/Models/TempExamConfig.php` | Model for the stored mark configuration |
| `routes/api.php` | Route definitions |
| `config/logging.php` | Log channels (`exam_flex_log`, `mark_entry_log`, `merit_log`) |

---

## Logs

Requests and results are written to the channels defined in `config/logging.php`. Most logging calls in the controller are commented out. Re-enable them when you need to trace a problem.

---

# Calculation reference

This section explains the calculations behind each endpoint. The examples were run against the real service classes (PHP 8.2, the project's own `vendor/`) using the sample payloads shown. Output blocks marked *(trimmed)* omit repeated fields; everything else is copied from the actual run.

## Contents

- [Overview](#overview)
- [Shared helpers](#shared-helpers)
- [Example grade scale](#example-grade-scale)
- [ExamService](#examservice)
- [ExamMarkCalculator](#exammarkcalculator)
- [ResultCalculator](#resultcalculator)
- [MeritProcessor](#meritprocessor)
- [CombinedResultProcessor](#combinedresultprocessor)
- [Private helpers (maintainers)](#private-helpers-maintainers)
- [Legacy classes](#legacy-classes)
- [Known issues](#known-issues)

---

## Overview

| Service | Public function | Purpose | Reached via |
|---|---|---|---|
| `ExamService` | `authenticateRequest(Request)` | Checks HTTP Basic credentials against `client_domains` | `POST /mark-entry/config` |
| `ExamMarkCalculator` | `calculate(array)` | Computes one subject's marks, pass/fail, grace, grade for each student | `POST /mark-entry/process` (after `/mark-entry/config`) |
| `ResultCalculator` | `calculate(array)` | Builds each student's full result: subjects, GPA, grade, status, highest marks | `POST /result-process` |
| `MeritProcessor` | `process(array)` | Ranks students overall and by section, shift, group, gender, religion | `POST /merit-process`, `POST /combined-merit-process` |
| `CombinedResultProcessor` | `process(array)` | Combines results from several exams into one result | `POST /combined-result-process` |

**Authentication.** Every HTTP endpoint expects `Authorization: Basic <base64(username:password)>`. `MarkEntryController` checks this inline for each endpoint. Only `/mark-entry/config` calls `ExamService::authenticateRequest()`.

**Input validation.** Most services assume a well-formed payload. The controller validates the top-level fields first and returns HTTP 422 on failure. The per-endpoint "Validated by" notes below list those rules.

**Calling a service directly.** None of these classes has constructor dependencies, so you can call them from Tinker or a test:

```php
$result = app(\App\Services\ResultCalculator::class)->calculate($payload);
// or
$result = (new \App\Services\ResultCalculator())->calculate($payload);
```

---

## Shared helpers

Defined in `app/Helpers/helpers.php`. Every service depends on them, so changing them changes every result.

### `roundMark($mark, $method)`

Applies the rounding rule for a mark. `$method` comes from the subject's `method_of_evaluation`.

| `$method` | Behaviour | Example (input → output) |
|---|---|---|
| `At Actual` | No rounding | `17.4` → `17.4` |
| `Always Down` | `floor` | `17.9` → `17.0` |
| `Always Up` | `ceil` | `17.1` → `18.0` |
| `Without Fraction` | Rounds up at `.50` or above, otherwise down | `17.49` → `17`, `17.50` → `18` |
| anything else, or `null` | Treated as `At Actual` | `17.4` → `17.4` |

### `round2($value)`

Returns a `float` rounded to 2 decimal places (`number_format` based, so `round2("683.00")` → `683.0`).

### `format2($value)`

Returns a `string` with exactly 2 decimals (`format2(4)` → `"4.00"`). Used for grade points and GPA in output.

---

## Example grade scale

The examples on this page share this grade scale. It is the `grade_points` / `grade_rules` array used by `ExamMarkCalculator`, `ResultCalculator` and `CombinedResultProcessor`.

```php
$gradeRules = [
    ['grade' => 'A+', 'grade_point' => 5.00, 'from_mark' => 80, 'to_mark' => 100,   'result' => 'Pass'],
    ['grade' => 'A',  'grade_point' => 4.00, 'from_mark' => 70, 'to_mark' => 79.99, 'result' => 'Pass'],
    ['grade' => 'B',  'grade_point' => 3.00, 'from_mark' => 60, 'to_mark' => 69.99, 'result' => 'Pass'],
    ['grade' => 'C',  'grade_point' => 2.00, 'from_mark' => 50, 'to_mark' => 59.99, 'result' => 'Pass'],
    ['grade' => 'D',  'grade_point' => 1.00, 'from_mark' => 40, 'to_mark' => 49.99, 'result' => 'Pass'],
    ['grade' => 'F',  'grade_point' => 0.00, 'from_mark' => 0,  'to_mark' => 39.99, 'result' => 'Fail'],
];
```

Grade lookup finds the first rule where `from_mark <= percentage <= to_mark`. Where the percentage falls in a gap between `to_mark` and the next `from_mark` (for example 79.995), some code paths fall back to the nearest grade below. Keep `to_mark` values contiguous to avoid this.

---

## ExamService

`app/Services/ExamService.php`

### `authenticateRequest(Request $request)`

Checks the HTTP Basic `Authorization` header against the `client_domains` table.

**Input:** an `Illuminate\Http\Request`. Reads only the `Authorization` header.

**Header format:**

```
Authorization: Basic <base64("username:password")>
```

Example header for a client named `client_user` (placeholder values):

```bash
printf 'client_user:client_pass' | base64
# Y2xpZW50X3VzZXI6Y2xpZW50X3Bhc3M=
# Authorization: Basic Y2xpZW50X3VzZXI6Y2xpZW50X3Bhc3M=
```

**Returns:**

| Case | Return value |
|---|---|
| Header missing, or not starting with `Basic ` | `JsonResponse` `{"error":"Unauthorized"}`, HTTP **401** |
| Username not found, or password does not match `password_hash` | `JsonResponse` `{"error":"Invalid credentials"}`, HTTP **403** |
| Valid credentials | The matching `client_domains` row (a `stdClass`, including `password_hash`) |

**Example:**

```php
use App\Services\ExamService;
use Illuminate\Http\JsonResponse;

$auth = app(ExamService::class)->authenticateRequest($request);

if ($auth instanceof JsonResponse) {
    return $auth;          // 401 or 403 response, send it back as-is
}

$client = $auth;           // stdClass row from client_domains
echo $client->username;
```

**Notes**

- Failed logins are logged to the `pay_flow` channel. That channel is not defined in `config/logging.php`. See [Known issues](#known-issues).
- The 401/403 split applies only to `/mark-entry/config`. The other endpoints inline the same check and return 401 for both cases.

---

## ExamMarkCalculator

`app/Services/ExamMarkCalculator.php`

Computes marks for **one subject** across many students. Used by the two-step mark-entry flow:

1. `POST /mark-entry/config` stores the subject configuration and returns a `temp_id` (valid for 2 hours).
2. `POST /mark-entry/process` with `temp_id` and `students` merges the stored config with the students, calls `calculate()`, and deletes the stored config.

### `calculate(array $payload): array`

**Input** (the stored config merged with `students`):

| Key | Type | Required | Description |
|---|---|---|---|
| `institute_id` | int | yes | Copied to the output |
| `grade_points` | array | no | Grade scale (see [Example grade scale](#example-grade-scale)). Used for grade and grade point |
| `subjects` | array | yes | **Only `subjects[0]` is used.** Other entries are ignored |
| `subjects[0].exam_name` | string | no | Default `Semester Exam` |
| `subjects[0].subject_name` | string | no | Copied to output |
| `subjects[0].grace_mark` | number | no | Maximum grace marks that can be added. Default `0` |
| `subjects[0].attendance_required` | bool | no | If `true`, a student whose status is absent gets the absent result. Default `false` |
| `subjects[0].method_of_evaluation` | string | no | Rounding method for `roundMark()`. Default `At Actual` |
| `subjects[0].exam_config` | array | yes | One row per exam component (see below) |
| `students` | array | yes | Each student is described below |

Each row in `exam_config`:

| Key | Type | Meaning |
|---|---|---|
| `exam_code_title` | string | Component code, for example `MCQ` or `CQ`. Must match the keys in `part_marks` |
| `conversion` | number | Percent of the raw mark to keep. `100` keeps all of it, `50` halves it. Default `100` |
| `total_mark` | number | Full marks for this component |
| `pass_mark` | number | Minimum mark for this component. `0` or missing means no check |
| `is_overall` | bool | Marks this row as part of the overall-pass group |
| `overall_mark` | number | Required overall percentage (for example `33` means 33%). Read from the first `is_overall` row |

Each entry in `students`:

| Key | Type | Meaning |
|---|---|---|
| `student_id` | int | Required |
| `attendance_status` | string | `present` / `absent`. Compared case-insensitively |
| `part_marks` | object | Raw mark per component, keyed by `exam_code_title` |

**Output:**

| Key | Type | Meaning |
|---|---|---|
| `results` | array | One result per student (fields below) |
| `institute_id` | int | From input |
| `exam_type` | string | Always `semester` |
| `exam_name` | string | From `subjects[0].exam_name` |
| `subject_name` | string\|null | From `subjects[0].subject_name` |

Each item in `results`:

| Key | Meaning |
|---|---|
| `student_id` | From input |
| `obtained_mark` | Raw total, before conversion and rounding |
| `final_mark` | Converted total plus any grace mark |
| `grace_mark` | Grace actually applied (0 if none) |
| `result_status` | `Pass` or `Fail` |
| `remark` | Empty on pass. On fail, the reason (see Rules) |
| `percentage` | `final_mark / total converted max * 100`, rounded to 2 dp |
| `part_marks` | Echo of the input part marks |
| `grade` | Letter grade |
| `grade_point` | Grade point (see Known issues for the type difference) |
| `attendance_status` | Echo of input |

**Rules**

1. **Absent.** If `attendance_required` is true and the status is `absent` (case-insensitive, or missing), the student gets `result_status: Fail`, `remark: Absent`, grade `F`, and zero marks. Any other status, such as `present`, is treated as attending.
2. **Conversion.** Each component is converted as `roundMark(mark × conversion/100, method)`, rounded to 2 dp, then summed. The maximum mark is converted the same way.
3. **Individual pass.** If any row has `pass_mark > 0`, each such component's **raw** mark must be at least `pass_mark`. Failures are listed in `remark`. See Known issues.
4. **Overall pass.** If any row has `is_overall: true`, the converted sum of those rows must be at least `overall_mark`% of their converted maximum.
5. **Final pass.** Pass when the checks that apply all pass. If no check applies, the student passes.
6. **Grace.** Applied only if the student fails, has an overall requirement, passes all individual checks, and the overall shortfall is greater than 0 and no more than `grace_mark`. The shortfall is added to `final_mark`, and the remark becomes `Pass by Grace (+N marks)`.
7. **Grade.** Passing students get the grade whose range covers their percentage. Failing students get the first grade rule with `result: Fail`, or `F` if there is none.

**Example**

```php
$payload = [
    'institute_id' => 1001,
    'grade_points' => $gradeRules,
    'subjects' => [[
        'exam_name'            => 'Semester Final',
        'subject_name'         => 'Mathematics',
        'grace_mark'           => 5,
        'highest_fail_mark'    => 0,
        'attendance_required'  => true,
        'method_of_evaluation' => 'Without Fraction',
        'exam_config' => [
            ['exam_code_title' => 'MCQ', 'conversion' => 100, 'total_mark' => 20, 'pass_mark' => 7,  'is_overall' => true, 'overall_mark' => 33],
            ['exam_code_title' => 'CQ',  'conversion' => 100, 'total_mark' => 80, 'pass_mark' => 25, 'is_overall' => true, 'overall_mark' => 33],
        ],
    ]],
    'students' => [
        ['student_id' => 101, 'attendance_status' => 'present', 'part_marks' => ['MCQ' => 17, 'CQ' => 62]],
        ['student_id' => 102, 'attendance_status' => 'present', 'part_marks' => ['MCQ' => 6,  'CQ' => 30]],
        ['student_id' => 103, 'attendance_status' => 'absent',  'part_marks' => []],
    ],
];

$out = app(\App\Services\ExamMarkCalculator::class)->calculate($payload);
```

**Output** (trimmed to the fields that matter):

```json
{
    "results": [
        {
            "student_id": 101,
            "obtained_mark": 79.0,
            "final_mark": 79.0,
            "grace_mark": 0.0,
            "result_status": "Pass",
            "remark": "",
            "percentage": 79.0,
            "part_marks": { "MCQ": 17, "CQ": 62 },
            "grade": "A",
            "grade_point": 4.0,
            "attendance_status": "present"
        },
        {
            "student_id": 102,
            "obtained_mark": 36.0,
            "final_mark": 36.0,
            "grace_mark": 0.0,
            "result_status": "Fail",
            "remark": "Failed Individual: MCQ (6 < 7)",
            "percentage": 36.0,
            "part_marks": { "MCQ": 6, "CQ": 30 },
            "grade": "F",
            "grade_point": 0.0,
            "attendance_status": "present"
        },
        {
            "student_id": 103,
            "obtained_mark": 0,
            "final_mark": 0,
            "grace_mark": 0,
            "result_status": "Fail",
            "remark": "Absent",
            "percentage": 0,
            "part_marks": [],
            "grade": "F",
            "grade_point": "0.00",
            "attendance_status": "absent"
        }
    ],
    "institute_id": 1001,
    "exam_type": "semester",
    "exam_name": "Semester Final",
    "subject_name": "Mathematics"
}
```

Student 102 fails on the individual check (MCQ 6 < 7), so the grace mark of 5 is not applied even though the overall total would have covered it.

---

## ResultCalculator

`app/Services/ResultCalculator.php`

Builds the full result for every student in a class: one row per subject (single or combined), totals, GPA with and without the optional subject, letter grade, pass/fail, and the highest mark per subject.

**Validated by** `POST /result-process`: `institute_id`, `exam_name` (string), `has_combined` (bool), `grade_rules`, `students` are required.

### `calculate(array $payload): array`

**Input**

| Key | Type | Required | Description |
|---|---|---|---|
| `exam_name` | string | yes | Copied to output |
| `has_combined` | bool | no | Copied to output |
| `has_assessment_additions` | bool | no | Copied to output |
| `grade_rules` | array | yes | Grade scale. Sorted by `from_mark` internally. Empty returns an error (see below) |
| `exam_config` | array | no | Only the first entry is read, for `result_view` |
| `exam_config[0].result_view` | string | no | `Grading` (default) or `Result Status`. See Rules |
| `mark_configs` | object | no | Keyed by `subject_id`. See below |
| `students` | array | yes | Students as described below. Empty returns no results |

Each `mark_configs[subject_id]`:

| Key | Type | Meaning |
|---|---|---|
| `total_marks` | object | Full marks per component code |
| `conversion` | object | Percent per component code. Default `100` |
| `method_of_evaluation` | object | Rounding method per component code. Default `At Actual` |
| `pass_marks` | object | Minimum raw mark per component code. Used for combined subjects |

Each entry in `students`:

| Key | Type | Meaning |
|---|---|---|
| `student_id` | int | Required |
| `student_name` | string | Default `N/A` |
| `roll` | int | Default `N/A` |
| `optional_subject_id` | int\|null | The 4th (optional) subject. Excluded from GPA; can add a bonus |
| `marks` | array | One item per subject or per combined paper (see below) |
| `assessment_attendance_mark` | number | Optional. Returned, see Known issues |
| `cocurricular_mark` | number | Optional. Returned, see Known issues |
| `assessment_attendance` | any | Passed through to output |
| `cocurricular` | array | Passed through to output |

Each item in `marks`:

| Key | Meaning |
|---|---|
| `subject_id` | Subject id. Numeric ids are used for the highest-mark table |
| `subject_name` | Display name |
| `is_combined` | `true` for a paper that belongs to a combined subject |
| `combined_id` | Required when `is_combined` is `true`. Papers with the same id form one combined subject |
| `combined_subject_name` | Name of the combined subject |
| `part_marks` | Raw mark per component code |
| `grade`, `grade_point` | Input values. Used only when the input grade is `F` or `result_status` is `Fail` (see Rules) |
| `result_status` | Optional. `Fail` forces the subject to fail |
| `grace_mark` | Grace added to this subject's final mark |
| `subject_type` | `Uncountable` makes the subject excluded from GPA |
| `only_add_to_total` | `true` adds marks to totals but not to GPA or subject count |
| `attendance_status` | Passed through |

**Output**

| Key | Meaning |
|---|---|
| `has_combined`, `has_assessment_additions`, `exam_name` | Echoed from input |
| `results` | One row per student (see below) |
| `highest_marks` | Highest `final_mark` per subject across all students |
| `total_students` | Count of `results` |

Each item in `results`:

| Key | Meaning |
|---|---|
| `student_id`, `student_name`, `roll` | From input |
| `subjects` | Subject rows (single subjects and combined subjects) |
| `total_mark_without_optional` | Sum of final marks, excluding the optional subject |
| `gpa_without_optional` | GPA excluding the optional subject, as a 2-dp string |
| `letter_grade_without_optional` | Grade from GPA, or from percentage under `Result Status` |
| `total_mark_with_optional` | Without-optional total plus the optional subject's bonus mark |
| `gpa_with_optional` | GPA including the optional bonus, capped at the highest grade point |
| `letter_grade_with_optional` | Grade from `gpa_with_optional` |
| `result_status` | `Pass` or `Fail` |
| `optional_bonus_gp`, `optional_bonus_mark` | Bonus from the optional subject |
| `failed_subject_count` | Number of failed subjects |
| `assessment_attendance_mark`, `cocurricular_mark` | Rounded input values |
| `assessment_attendance`, `cocurricular` | Passed through |

**Rules**

1. **Single subject** (`processSingle`). Converted mark = Σ `roundMark(part × conversion/100, method)`. Max mark is converted the same way. Grace is added, and the final mark is rounded. `percentage` = `final_mark / max × 100`, rounded to 2 dp.
2. **Grade for a single subject.** Recalculated from the percentage using `grade_rules`, unless the input grade is `F` or `result_status` is `Fail`. In that case the input grade and grade point are kept. See Known issues.
3. **Countable subjects.** A subject counts toward GPA unless it is `Uncountable`, is the optional subject, or has `only_add_to_total: true`. A countable subject with grade point `<= 0` makes the student fail, sets GPA to 0, and increments `failed_subject_count`.
4. **Optional subject.** Excluded from GPA. If it is passed (grade point `> 0`), its grade point above 2.00 is added as a bonus, and its mark above 40% of its maximum is added to `total_mark_with_optional`.
5. **Combined subjects.** Papers with the same `combined_id` are grouped. Each paper's converted mark is computed. The combined percentage is the sum of finals over the sum of maxima. Each component code's total across papers must reach the summed `pass_marks`. Missing a requirement forces the combined grade to the fail label (the lowest `grade_point` rule).
6. **Result Status view.** When `result_view` is `Result Status`, `letter_grade_without_optional` comes from the total's percentage instead of GPA. If any countable subject failed, it shows the fail label instead.
7. **Highest marks.** Tracked per numeric `subject_id`. For combined subjects, each paper is tracked, not the combined total.
8. **Empty input.** No `grade_rules` returns `{results: [], highest_marks: [], total_students: 0, error: "Grade rules missing"}`. No students returns the same shape without `error`.

**Example**

```php
$payload = [
    'exam_name'                => 'Semester Final',
    'has_combined'             => true,
    'has_assessment_additions' => false,
    'exam_config'              => [['class_id' => 5, 'result_view' => 'Grading']],
    'grade_rules'              => $gradeRules,
    'mark_configs' => [
        1 => [   // Mathematics
            'total_marks'          => ['MCQ' => 20, 'CQ' => 80],
            'conversion'           => ['MCQ' => 100, 'CQ' => 100],
            'method_of_evaluation' => ['MCQ' => 'Without Fraction', 'CQ' => 'Without Fraction'],
            'pass_marks'           => ['MCQ' => 7, 'CQ' => 25],
        ],
        2 => [   // English
            'total_marks'          => ['Written' => 100],
            'conversion'           => ['Written' => 100],
            'method_of_evaluation' => ['Written' => 'At Actual'],
            'pass_marks'           => ['Written' => 33],
        ],
        3 => [   // Bangla 1st paper (combined_id 10)
            'total_marks'          => ['MCQ' => 25, 'CQ' => 75],
            'conversion'           => ['MCQ' => 100, 'CQ' => 100],
            'method_of_evaluation' => ['MCQ' => 'At Actual', 'CQ' => 'At Actual'],
            'pass_marks'           => ['MCQ' => 8, 'CQ' => 24],
        ],
        4 => [   // Bangla 2nd paper (combined_id 10)
            'total_marks'          => ['MCQ' => 25, 'CQ' => 75],
            'conversion'           => ['MCQ' => 100, 'CQ' => 100],
            'method_of_evaluation' => ['MCQ' => 'At Actual', 'CQ' => 'At Actual'],
            'pass_marks'           => ['MCQ' => 8, 'CQ' => 24],
        ],
    ],
    'students' => [
        [
            'student_id'          => 101,
            'student_name'        => 'Rahim',
            'roll'                => 1,
            'optional_subject_id' => null,
            'marks' => [
                ['subject_id' => 1, 'subject_name' => 'Mathematics', 'is_combined' => false,
                 'part_marks' => ['MCQ' => 17, 'CQ' => 62], 'grace_mark' => 0, 'grade' => 'A', 'grade_point' => 4.00, 'result_status' => 'Pass', 'attendance_status' => 'present'],
                ['subject_id' => 2, 'subject_name' => 'English', 'is_combined' => false,
                 'part_marks' => ['Written' => 71], 'grace_mark' => 0, 'grade' => 'A+', 'grade_point' => 5.00, 'result_status' => 'Pass', 'attendance_status' => 'present'],
                ['combined_id' => 10, 'combined_subject_name' => 'Bangla', 'subject_id' => 3, 'subject_name' => 'Bangla 1st Paper',
                 'is_combined' => true, 'part_marks' => ['MCQ' => 20, 'CQ' => 60], 'grace_mark' => 0, 'grade' => 'A', 'grade_point' => 4.00, 'attendance_status' => 'present'],
                ['combined_id' => 10, 'combined_subject_name' => 'Bangla', 'subject_id' => 4, 'subject_name' => 'Bangla 2nd Paper',
                 'is_combined' => true, 'part_marks' => ['MCQ' => 18, 'CQ' => 58], 'grace_mark' => 0, 'grade' => 'A', 'grade_point' => 4.00, 'attendance_status' => 'present'],
            ],
            'assessment_attendance_mark' => 0,
            'cocurricular_mark'          => 0,
        ],
        [
            'student_id'          => 102,
            'student_name'        => 'Karim',
            'roll'                => 2,
            'optional_subject_id' => null,
            'marks' => [
                ['subject_id' => 1, 'subject_name' => 'Mathematics', 'is_combined' => false,
                 'part_marks' => ['MCQ' => 6, 'CQ' => 30], 'grace_mark' => 5, 'grade' => 'F', 'grade_point' => 0.00, 'result_status' => 'Fail', 'attendance_status' => 'present'],
                ['subject_id' => 2, 'subject_name' => 'English', 'is_combined' => false,
                 'part_marks' => ['Written' => 45], 'grace_mark' => 0, 'grade' => 'B', 'grade_point' => 3.00, 'result_status' => 'Pass', 'attendance_status' => 'present'],
                ['combined_id' => 10, 'combined_subject_name' => 'Bangla', 'subject_id' => 3, 'subject_name' => 'Bangla 1st Paper',
                 'is_combined' => true, 'part_marks' => ['MCQ' => 12, 'CQ' => 40], 'grace_mark' => 0, 'grade' => 'B', 'grade_point' => 3.00, 'attendance_status' => 'present'],
                ['combined_id' => 10, 'combined_subject_name' => 'Bangla', 'subject_id' => 4, 'subject_name' => 'Bangla 2nd Paper',
                 'is_combined' => true, 'part_marks' => ['MCQ' => 10, 'CQ' => 35], 'grace_mark' => 0, 'grade' => 'C', 'grade_point' => 2.00, 'attendance_status' => 'present'],
            ],
            'assessment_attendance_mark' => 0,
            'cocurricular_mark'          => 0,
        ],
    ],
];

$result = app(\App\Services\ResultCalculator::class)->calculate($payload);
```

**Output** (trimmed; student 102 is shown only in part):

```json
{
    "has_combined": true,
    "has_assessment_additions": false,
    "exam_name": "Semester Final",
    "results": [
        {
            "student_id": 101,
            "student_name": "Rahim",
            "roll": 1,
            "subjects": [
                {
                    "subject_id": 1,
                    "subject_name": "Mathematics",
                    "part_marks": { "MCQ": 17, "CQ": 62 },
                    "total_marks": 79.0,
                    "converted_mark": 79.0,
                    "final_mark": 79.0,
                    "total_max_mark": 100.0,
                    "grace_mark": 0,
                    "percentage": 79.0,
                    "grade_point": "4.00",
                    "grade": "A",
                    "is_uncountable": false,
                    "only_add_to_total": false,
                    "is_combined": false,
                    "attendance_status": "present",
                    "is_optional": false
                },
                {
                    "combined_id": 10,
                    "is_combined": true,
                    "combined_name": "Bangla",
                    "total_marks": 156.0,
                    "final_mark": 156.0,
                    "total_max_mark": 200.0,
                    "percentage": 78.0,
                    "combined_grade_point": "4.00",
                    "combined_grade": "A",
                    "combined_status": "Pass",
                    "parts": [
                        { "subject_id": 3, "subject_name": "Bangla 1st Paper", "final_mark": 80.0, "grade": "A", "grade_point": "4.00" },
                        { "subject_id": 4, "subject_name": "Bangla 2nd Paper", "final_mark": 76.0, "grade": "A", "grade_point": "4.00" }
                    ],
                    "combined_part_totals": { "MCQ": 38, "CQ": 118 },
                    "combined_pass_requirements": { "MCQ": 16.0, "CQ": 48.0 }
                }
            ],
            "total_mark_without_optional": 306.0,
            "gpa_without_optional": "4.00",
            "letter_grade_without_optional": "A",
            "total_mark_with_optional": 306.0,
            "gpa_with_optional": "4.00",
            "letter_grade_with_optional": "A",
            "result_status": "Pass",
            "optional_bonus_gp": "0.00",
            "optional_bonus_mark": 0.0,
            "failed_subject_count": 0,
            "assessment_attendance_mark": 0.0,
            "cocurricular_mark": 0.0,
            "assessment_attendance": null,
            "cocurricular": []
        },
        {
            "student_id": 102,
            "student_name": "Karim",
            "roll": 2,
            "subjects": [
                {
                    "subject_id": 1,
                    "subject_name": "Mathematics",
                    "total_marks": 36.0,
                    "converted_mark": 36.0,
                    "final_mark": 41.0,
                    "grace_mark": 5,
                    "percentage": 41.0,
                    "grade_point": "0.00",
                    "grade": "F"
                }
            ],
            "total_mark_with_optional": 183.0,
            "gpa_with_optional": "0.00",
            "letter_grade_with_optional": "F",
            "result_status": "Fail",
            "failed_subject_count": 1
        }
    ],
    "highest_marks": [
        { "subject_id": 1, "subject_name": "Mathematics",       "highest_mark": 79.0 },
        { "subject_id": 2, "subject_name": "English",           "highest_mark": 71.0 },
        { "subject_id": 3, "subject_name": "Bangla 1st Paper",  "highest_mark": 80.0 },
        { "subject_id": 4, "subject_name": "Bangla 2nd Paper",  "highest_mark": 76.0 }
    ],
    "total_students": 2
}
```

Karim's Mathematics shows `final_mark` 41 but `grade` F. The input said `grade: F`, so the grade was kept (Rule 2), even though 41% would grade as D. Its grade point is 0, which counts as a failed countable subject, so Karim's overall `result_status` is `Fail` (`failed_subject_count: 1`).

---

## MeritProcessor

`app/Services/MeritProcessor.php`

Ranks students. It takes the result rows from `ResultCalculator` (or `CombinedResultProcessor`) and returns overall ranks plus ranks within each section, shift, group, gender and religion.

**Validated by** `POST /merit-process`: `institute_id`, `exam_name`, `exam_config`, `results` are required. `POST /combined-merit-process` also requires `exam_config.merit_process_type` to be one of `Total Mark (Sequential)`, `Total Mark (Non-Sequential)`, `Grade Point (Sequential)`, `Grade Point (Non-Sequential)`.

### `process(array $payload): array`

**Input**

| Key | Type | Required | Description |
|---|---|---|---|
| `results` | array | yes | Result rows. Either the list itself, or `{results: [...]}` |
| `exam_config.merit_process_type` | string | no | Merit type. Default `total_mark_sequential`, which does **not** contain `(sequential)`, so it ranks non-sequentially. See merit types below |
| `exam_config.group_by_*` | bool | no | Only affects `grouped_by` in the output. See Known issues |
| `academic_details` | object | no | Keyed by `student_id`: `class_roll`, `shift`, `section`, `group` |
| `student_details` | object | no | Keyed by `student_id`: `student_gender`, `student_religion` |

Each item in `results` needs at least:

| Key | Used for |
|---|---|
| `student_id` | Identity, deduplication, lookup into `academic_details` and `student_details` |
| `student_name` | Output |
| `result_status` | `Pass` / `Fail`. Passes sort first |
| `total_mark_with_optional`, or `total_mark` | Total used for ranking |
| `gpa_with_optional`, or `gpa` | GPA used for ranking |
| `gpa_without_optional` | Output |
| `letter_grade_with_optional`, or `letter_grade` | Output (defaults to `F`) |

**Merit types**

Two independent settings come from the `merit_process_type` string:

| Setting | Triggered when the string contains | Effect |
|---|---|---|
| Sequential | `(sequential)` (case-insensitive) | Positions are 1, 2, 3, … with no ties. Otherwise tied students share a position |
| Grade-point based | `grade point` or `gpa` (case-insensitive) | Sorts by GPA first, then total mark. Otherwise sorts by total mark first, then GPA |

**Sort order:** Pass before Fail → primary key (descending) → secondary key (descending) → `class_roll` (ascending).

**Output**

| Key | Meaning |
|---|---|
| `total_students` | Count after deduplication |
| `merit_type` | Echo of the merit type |
| `grouped_by` | List of `group_by_*` fields that were turned on |
| `data.all_students` | Class-wide ranking (flat list) |
| `data.section_wise` | Ranked within each `shift-section` (flat list) |
| `data.shift_wise` | Ranked within each shift (flat list) |
| `data.group_wise` | Ranked within each group (flat list) |
| `data.gender_wise` | Ranked within each gender (flat list) |
| `data.religion_wise` | Ranked within each religion (flat list) |
| `data.shift_wise_group` | Ranked within each `Shift-Group` pair, keyed by that pair |

Each ranked row:

| Key | Meaning |
|---|---|
| `student_id`, `student_name`, `roll` | From input (`roll` defaults to `0`) |
| `total_mark` | Total used for ranking |
| `gpa`, `gpa_without_optional` | Rounded to 2 dp |
| `letter_grade` | From input (defaults to `F`) |
| `result_status` | From input |
| `merit_position` | Position in the group the row belongs to |
| `shift`, `section`, `group`, `gender`, `religion` | From `academic_details` / `student_details`. `null` if missing |
| `shift_group_key` | Only on `shift_wise_group` rows |

**Rules**

1. **Deduplication.** Results are deduplicated by `student_id`. The first occurrence is kept.
2. **Ties.** Equal primary and secondary keys (within 0.0001) are tie-broken by lower `class_roll` first.
3. **Sequential ranking.** Position = sort index + 1.
4. **Non-sequential ranking.** Equal primary key shares a position. The next different key gets the previous position plus 1. For example: 1, 1, 2, 3, 3, 4. This is dense ranking, not standard competition ranking (1, 1, 3). See Known issues.
5. **Group rankings.** Each group is sorted and ranked on its own. `section_wise`, `shift_wise`, `group_wise`, `gender_wise` and `religion_wise` are flat lists. `shift_wise_group` is an object keyed by `Shift-Group`.
6. **Missing group values.** A student with no `academic_details` entry for a field goes into a group named `unknown` (single field) or `Unknown` (composite).
7. **No results.** Returns `{status: "error", message: "No results found"}`.

**Example**

```php
$payload = [
    'institute_id'     => 1001,
    'exam_name'        => 'Semester Final',
    'exam_config'      => [
        'merit_process_type' => 'Total Mark (Sequential)',
        'group_by_section'   => true,
    ],
    'results'          => $resultCalculatorOutput['results'],   // from ResultCalculator::calculate()
    'academic_details' => [
        101 => ['class_roll' => 1, 'shift' => 'Morning', 'section' => 'A', 'group' => 'Science'],
        102 => ['class_roll' => 2, 'shift' => 'Morning', 'section' => 'A', 'group' => 'Science'],
    ],
    'student_details'  => [
        101 => ['student_gender' => 'Male', 'student_religion' => 'Islam'],
        102 => ['student_gender' => 'Male', 'student_religion' => 'Islam'],
    ],
];

$merit = app(\App\Services\MeritProcessor::class)->process($payload);
```

**Output** (trimmed; `data` contains the same shape for every group key):

```json
{
    "total_students": 2,
    "merit_type": "Total Mark (Sequential)",
    "grouped_by": ["section"],
    "data": {
        "all_students": [
            {
                "student_id": 101, "student_name": "Rahim", "roll": 1,
                "total_mark": 306.0, "gpa": 4.0, "gpa_without_optional": 4.0,
                "letter_grade": "A", "result_status": "Pass", "merit_position": 1,
                "shift": "Morning", "section": "A", "group": "Science",
                "gender": "Male", "religion": "Islam"
            },
            {
                "student_id": 102, "student_name": "Karim", "roll": 2,
                "total_mark": 183.0, "gpa": 0.0, "gpa_without_optional": 0.0,
                "letter_grade": "F", "result_status": "Fail", "merit_position": 2,
                "shift": "Morning", "section": "A", "group": "Science",
                "gender": "Male", "religion": "Islam"
            }
        ],
        "section_wise": [ "… same shape as all_students …" ],
        "shift_wise": [ "… same shape …" ],
        "group_wise": [ "… same shape …" ],
        "gender_wise": [ "… same shape …" ],
        "religion_wise": [ "… same shape …" ],
        "shift_wise_group": {
            "Morning-Science": [
                { "student_id": 101, "merit_position": 1, "shift_group_key": "Morning-Science", "…": "…" },
                { "student_id": 102, "merit_position": 2, "shift_group_key": "Morning-Science", "…": "…" }
            ]
        }
    }
}
```

Karim is ranked 2nd even though his result is Fail. Pass always sorts before Fail, and Rahim is the only Pass.

Empty input:

```php
app(\App\Services\MeritProcessor::class)->process(['results' => []]);
// ['status' => 'error', 'message' => 'No results found']
```

---

## CombinedResultProcessor

`app/Services/CombinedResultProcessor.php`

Combines the results of several exams (for example Semester 1 and Semester 2) into one result per student. Each exam contributes a weight, and the combined marks and grades are calculated from those weighted results.

**Validated by** `POST /combined-result-process`: `institute_id`, `result_rules` (`Average` or `Percentage`), `passing_rules` (one of the four below), `exams` (each with `exam_id` and integer `sequence`), `grade_rules`, `mark_configs`, `students` are required.

### `process(array $payload): array`

**Input**

| Key | Type | Required | Description |
|---|---|---|---|
| `result_rules` | string | yes | `Average` (equal weights) or `Percentage` (weights from `exams[].percentage`) |
| `passing_rules` | string | yes | One of: `Last Exam Pass/Fail`, `Subject Wise Pass/Fail`, `Average (Short Code)`, `Average (Total Marks)` |
| `exams` | array | yes | Each has `exam_id`, `sequence` (integer), and `percentage` (used only by `Percentage`) |
| `grade_rules` | array | yes | Grade scale |
| `mark_configs` | object | yes | Keyed by `subject_id`. Same shape as in `ResultCalculator` |
| `students` | array | yes | Each has `student_id`, `student_name`, `roll`, `per_exam_results` |

Each entry in `students`:

| Key | Meaning |
|---|---|
| `per_exam_results` | Object keyed by `exam_id`. Each value is that student's **`ResultCalculator` result row** for that exam |

**Output**

| Key | Meaning |
|---|---|
| `exam_name` | Always `Combined Result` |
| `has_combined` | Always `true` |
| `grade_rules` | Grade scale, sorted by `from_mark` descending |
| `highest_marks` | Highest `final_mark` per subject. A combined subject appears with `subject_id` set to its combined id |
| `total_students` | Count of `results` |
| `results` | One row per student |

Each item in `results`:

| Key | Meaning |
|---|---|
| `student_id`, `student_name`, `roll` | From input |
| `subjects` | Subject rows, one per subject or combined subject |
| `total_mark_without_optional`, `total_mark_with_optional` | Sum of subject final marks. Both keys have the same value |
| `gpa_without_optional`, `gpa_with_optional` | Average of subject grade points, 2 dp. Both keys have the same value |
| `letter_grade_*` | Grade from GPA |
| `result_status` | `Pass` / `Fail` from `passing_rules` |
| `failed_subject_count` | Count of subjects with `is_pass: false` |
| `combination_meta` | `missing_in_exams` list, and a `reason` when present |

**Rules**

1. **Validation.** Invalid `result_rules`, invalid `passing_rules`, empty `grade_rules` or empty `exams` return the error envelope (`error` key set, `results` empty). `Average (Short Code)` also requires every exam to use the same component codes for each subject. Otherwise it returns an error naming the subject.
2. **Weights.** `Average`: each exam gets `1 / count`. `Percentage`: each exam gets `percentage / 100`. The service does not check that these sum to 1.
3. **Exam order.** Exams are sorted by `sequence`. The last one is used by `Last Exam Pass/Fail`.
4. **Missing exams.** If a student has no result for any exam, the student gets `result_status: Fail`, all marks `null`, and `combination_meta.reason` lists the missing exam ids.
5. **Single subjects.** Each component's marks are weighted across exams, then converted with `mark_configs` (same rules as `ResultCalculator`). Subject pass uses the rule selected by `passing_rules`.
6. **Combined subjects.** Each exam's already-computed combined `final_mark` is weighted. Component pass checks use the weighted raw totals from each exam. Per-paper data is included in `parts`.
7. **Pass rules.**

   | `passing_rules` | Subject passes when | Student passes when |
   |---|---|---|
   | `Last Exam Pass/Fail` | Single: final ≥ `overall_required` (if set). Combined: each component total ≥ its pass requirement | Last exam's `result_status` is `Pass` |
   | `Subject Wise Pass/Fail` | No exam for this subject marked it failed | No subject failed in any exam |
   | `Average (Short Code)` | Each component's weighted total ≥ its `pass_marks`, and (single only) final ≥ `overall_required` | Every subject passes |
   | `Average (Total Marks)` | Combined: grade is not `F`. Single: final ≥ `overall_required` (if set) | Every subject passes |

8. **Grade.** Percentage of the weighted final over the weighted maximum, then the grade rule for that percentage. A failed subject is always shown as `F` with grade point 0.
9. **Highest marks.** Tracked per subject and per combined subject.

**Example**

```php
$perExamRow = $resultCalculatorOutput['results'][0];   // Rahim's row from ResultCalculator

$payload = [
    'result_rules'  => 'Average',
    'passing_rules' => 'Last Exam Pass/Fail',
    'exams' => [
        ['exam_id' => 'SEM1', 'sequence' => 1, 'percentage' => 40],
        ['exam_id' => 'SEM2', 'sequence' => 2, 'percentage' => 60],
    ],
    'grade_rules'  => $gradeRules,
    'mark_configs' => $markConfigs,   // same as ResultCalculator's mark_configs
    'students' => [
        [
            'student_id'      => 101,
            'student_name'    => 'Rahim',
            'roll'            => 1,
            'per_exam_results' => [
                'SEM1' => $perExamRow,   // ResultCalculator row, exam 1
                'SEM2' => $perExamRow,   // ResultCalculator row, exam 2 (same row reused here for brevity)
            ],
        ],
        // … Karim, same shape …
    ],
];

$combined = app(\App\Services\CombinedResultProcessor::class)->process($payload);
```

**Output** (trimmed):

```json
{
    "exam_name": "Combined Result",
    "has_combined": true,
    "highest_marks": [
        { "subject_id": 1,  "subject_name": "Mathematics", "highest_mark": 79.0 },
        { "subject_id": 2,  "subject_name": "English",     "highest_mark": 71.0 },
        { "subject_id": 10, "subject_name": "Bangla",      "highest_mark": 156.0 }
    ],
    "total_students": 2,
    "results": [
        {
            "student_id": 101,
            "student_name": "Rahim",
            "roll": 1,
            "subjects": [
                {
                    "subject_id": 1, "subject_name": "Mathematics", "is_combined": false,
                    "final_mark": 79.0,
                    "part_marks": { "MCQ": 17.0, "CQ": 62.0 },
                    "grade": "A", "grade_point": "4.00", "is_pass": true
                },
                {
                    "subject_id": 2, "subject_name": "English", "is_combined": false,
                    "final_mark": 71.0,
                    "part_marks": { "Written": 71.0 },
                    "grade": "A", "grade_point": "4.00", "is_pass": true
                },
                {
                    "combined_id": 10, "combined_name": "Bangla", "is_combined": true,
                    "final_mark": 156.0,
                    "combined_part_totals": { "MCQ": 38.0, "CQ": 118.0 },
                    "combined_grade": "A", "combined_grade_point": "4.00", "combined_status": "Pass",
                    "parts": [ "… Bangla 1st and 2nd paper …" ]
                }
            ],
            "total_mark_without_optional": 306.0,
            "gpa_without_optional": "4.00",
            "letter_grade_without_optional": "A",
            "result_status": "Pass",
            "failed_subject_count": 0,
            "combination_meta": { "missing_in_exams": [] }
        },
        {
            "student_id": 102,
            "student_name": "Karim",
            "roll": 2,
            "total_mark_without_optional": 178.0,
            "gpa_without_optional": "0.67",
            "letter_grade_without_optional": "F",
            "result_status": "Fail",
            "failed_subject_count": 0,
            "…": "…"
        }
    ]
}
```

Karim's `result_status` is `Fail` even though `failed_subject_count` is 0. Under `Last Exam Pass/Fail`, the student result depends only on the last exam's `result_status`, and Karim failed that exam (Mathematics, see the `ResultCalculator` example).

Error example:

```php
app(\App\Services\CombinedResultProcessor::class)->process(array_merge($payload, ['result_rules' => 'Bogus']));
// ['exam_name' => 'Combined Result', 'has_combined' => true, 'grade_rules' => [], 'highest_marks' => [],
//  'total_students' => 0, 'results' => [], 'error' => 'result_rules must be Average or Percentage']
```

---

## Private helpers (maintainers)

These are not called from outside the class. Listed here so you know what each one does before changing it.

### ExamMarkCalculator
| Method | What it does |
|---|---|
| `calculateStudent($student, $payload)` | Full per-student calculation: absent check, conversion, pass checks, grace, remark, grade |
| `absentResult(...)` | Zero-mark `Fail` / `Absent` result |

### ResultCalculator
| Method | What it does |
|---|---|
| `processStudent(...)` | Groups marks by subject, builds subject rows, totals, GPA, optional bonus, grade view, assessment additions |
| `processSingle(...)` | Converts one single subject, computes percentage and grade |
| `processCombinedGroup(...)` | Converts each paper of a combined subject, checks per-component pass totals |
| `updateHighest(...)` | Tracks the highest final mark per numeric `subject_id` |
| `gpaToLetterGrade(...)` | Letter grade for a GPA value |
| `getGradePoint(...)` / `getGrade(...)` | Grade point / grade for a percentage |
| `failGradeLabel(...)` | Label of the lowest `grade_point` rule (usually `F`, but configurable) |
| `markToGradePoint(...)`, `gpToGrade(...)` | Thin wrappers around `getGradePoint` / `getGrade`. Not called from within the class |

### MeritProcessor
| Method | What it does |
|---|---|
| `normalizeResults($raw)` | Accepts a list or `{results: [...]}` |
| `getGroupByFields($config)` | Builds the `grouped_by` list |
| `isSequential($type)` / `isGradePointBased($type)` | Reads the merit type string |
| `toFloat`, `floatEquals` | Safe number cast and comparison with tolerance `0.0001` |
| `getTotalMark`, `getGpa` | Prefers `*_with_optional`, falls back to the plain key |
| `calcTotalFromSubjects($student)` | Recomputes a total from subject rows when the stored total is missing |
| `sortStudents`, `sortGroup` | Sort rules: pass first, then key order, then roll |
| `doRankAssignment(...)` | Assigns positions (sequential or dense) and builds ranked rows |
| `assignRanks(...)` | Class-wide ranking |
| `rankByField(...)` | Ranks within each value of one field |
| `rankByCompositeField(...)` | Ranks within each `Shift-Group` pair |
| `rankBySectionField(...)` | Ranks within each `Shift-Section` pair, returned as one flat list |

### CombinedResultProcessor
| Method | What it does |
|---|---|
| `envelope(...)` / `error(...)` | Builds the success and error response shapes |
| `buildExamWeights(...)` | Weights per exam (see Rules 2) |
| `findShortCodeMismatch(...)` | Checks component codes match across exams for `Average (Short Code)` |
| `processStudent(...)` | Per-student combination, including missing-exam handling |
| `buildMissingExamResult(...)` | Fail result for a student with a missing exam |
| `collectSubjectGroups(...)` | Unique subject list across all exams |
| `findSubjectInExam(...)` | Finds a subject in one exam's result by id |
| `processSingleSubjectAcrossExams(...)` | Weights and grades a single subject across exams |
| `processCombinedSubjectAcrossExams(...)` | Weights and grades a combined subject across exams |
| `updateHighest(...)` | Highest mark per subject or combined subject |
| `gpaToLetterGrade`, `getGradePoint`, `getGrade` | Grade lookups (same logic as `ResultCalculator`, duplicated) |

---

## Legacy classes

`ExamMarkCalculatorOld` and `MeritProcessorOld` have the same public signatures as their current versions, but nothing in the routes, controllers or services references them. Treat them as reference only. Before deleting them, check there is no external use.

---

## Known issues

These come from reading the code and running the examples above. They are documented here, not fixed.

1. **Assessment and co-curricular marks are not added to totals.** `ResultCalculator` computes `$assessmentAdditions` (around line 230 of `app/Services/ResultCalculator.php`) but never uses it. The `total_mark_*` values exclude these marks. The comment above that line says they are added to the total.

2. **Failed logins may throw.** `ExamService::authenticateRequest()` logs failures to channel `pay_flow` (around line 31). That channel is not defined in `config/logging.php`, so Laravel would throw on a failed login to `/mark-entry/config`. *Found by reading the code, not run.*

3. **Non-sequential ranking is dense, not competition.** For ties, positions run 1, 1, 2, 3. The comment in `MeritProcessor` says `1, 1, 2` as "standard competition", which is misleading. Standard competition ranking would give 1, 1, 3.

4. **Error envelopes return HTTP 202 "success".** `MeritProcessor` and `CombinedResultProcessor` return error arrays for bad input. The controllers wrap every result in `status: "success"` with HTTP 202, so clients have to check for `error` / `status: "error"` in the body.

5. **`group_by_*` flags only change `grouped_by`.** Every group ranking (`section_wise`, `shift_wise`, and so on) is always returned, regardless of these flags.

6. **Individual pass check uses raw marks.** In `ExamMarkCalculator`, the per-component check compares the raw mark to `pass_mark`, not the converted mark. The comment at that point says it should use the converted mark. This matters when `conversion` is not 100.

7. **Missing attendance status.** With `attendance_required: true`, a student with no `attendance_status` is treated as absent. The output line reads `$student['attendance_status']` with no default, so it emits a PHP warning when the key is missing.

8. **Mixed types in output.** `grade_point` is a `float` for present students and a `"0.00"` string for absent students in `ExamMarkCalculator`. `ResultCalculator` and `CombinedResultProcessor` return grade points and GPAs as 2-dp strings. Clients should not assume one type.

9. **Input grade wins over recalculation.** In `ResultCalculator`, if the input grade is `F` or `result_status` is `Fail`, the subject keeps the input grade and grade point even if the recalculated percentage would grade higher. The Karim example above shows this (41% shown as F).

10. **`client_domains` has no migration in this repo.** The table used for authentication is not created by any migration under `database/migrations/`. It must exist in the target database already.

11. **`ExamService` assumes a colon in the decoded credentials.** If the Basic header decodes to a string without `:`, `explode` returns one element and `$password` is undefined, which produces a warning.
