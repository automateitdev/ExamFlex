<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Services\ExamService;
use App\Models\TempExamConfig;
use App\Services\MeritProcessor;
use App\Services\ResultCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\ExamMarkCalculator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class MarkEntryController extends Controller
{
    protected $examService;
    protected $examMarkCalculator;

    public function __construct(ExamService $examService,  ExamMarkCalculator $examMarkCalculator)
    {
        $this->examService = $examService;
        $this->examMarkCalculator = $examMarkCalculator;
    }


    public function storeConfig(Request $request)
    {
        Log::channel('mark_entry_log')->info('Mark Entry Config Request', [
            'request' => $request->all()
        ]);

        $data = $request->all();

        $authResult = $this->examService->authenticateRequest($request);

        if ($authResult instanceof \Illuminate\Http\JsonResponse) {
            return $authResult;
        }
        $client = $authResult;

        // $credentials = base64_decode(substr($authHeader, 6));
        // [$username, $password] = explode(':', $credentials, 2);

        // $client = DB::table('client_domains')
        //     ->where('username', $username)
        //     ->first();

        // if (!$client || !Hash::check($password, $client->password_hash)) {
        //     return response()->json(['error' => 'Unauthorized'], 401);
        // }

        // $validator = Validator::make($request->all(), [
        //     'institute_id' => 'required|string|max:50',
        //     'exam_type' => 'required|in:semester,class test',
        //     'subjects' => 'required|array|min:1',
        //     'subjects.*.subject_id' => 'required|integer',
        //     'subjects.*.subject_name' => 'required|string',
        //     'subjects.*.exam_name' => 'required|string',
        //     'subjects.*.grace_mark' => 'required|numeric|min:0',
        //     'subjects.*.method_of_evaluation' => 'required|in:At Actual,Converted',
        //     'subjects.*.attendance_required' => 'required|boolean',
        //     'subjects.*.highest_fail_mark' => 'nullable|numeric|min:0',
        //     'subjects.*.exam_config' => 'required|array|min:1',
        //     'subjects.*.exam_config.*.exam_code_title' => 'required|string|in:CQ,MCQ,SBA,Practical',
        //     'subjects.*.exam_config.*.total_mark' => 'required|numeric|min:1',
        //     'subjects.*.exam_config.*.pass_mark' => 'required|numeric|min:0',
        //     'subjects.*.exam_config.*.conversion' => 'required|numeric|min:1|max:100',
        //     'subjects.*.exam_config.*.is_individual' => 'required|boolean',
        //     'subjects.*.exam_config.*.is_overall' => 'required|boolean',
        //     'grade_points' => 'required|array|min:1',
        //     'grade_points.*.from_mark' => 'required|numeric|min:0',
        //     'grade_points.*.to_mark' => 'required|numeric|min:0',
        //     'grade_points.*.grade' => 'required|string|max:10',
        //     'grade_points.*.grade_point' => 'required|numeric|min:0',
        // ]);

        // if ($validator->fails()) {
        //     Log::channel('exam_flex_log')->warning('Config Validation Failed', [
        //         'errors' => $validator->errors()->toArray()
        //     ]);
        //     return response()->json([
        //         'error' => 'Validation failed',
        //         'details' => $validator->errors()
        //     ], 422);
        // }
        DB::enableQueryLog();

        $tempId = 'temp_' . Str::random(12);

        Log::channel('mark_entry_log')->info('Generated Temp ID for Config', [
            'temp_id' => $tempId,
            'institute_id' => $data['institute_id']
        ]);
        TempExamConfig::create([
            'temp_id' => $tempId,
            'institute_id' => $data['institute_id'],
            'config' => json_encode($data),
            'expires_at' => now()->addHours(2),
        ]);
        Log::info(DB::getQueryLog());

        Log::channel('mark_entry_log')->info('Mark Entry Config Stored', [
            'temp_id' => $tempId,
            'expires_at' => now()->addHours(2)->toDateTimeString()
        ]);
        return response()->json([
            'status' => 'config_saved',
            'temp_id' => $tempId,
            'expires_at' => now()->addHours(2)->toDateTimeString()
        ], 202);
    }

    public function processStudents(Request $request)
    {
        Log::channel('mark_entry_log')->info('Mark Calculation Request', [
            'request' => $request->all()
        ]);

        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Basic ')) {
            return response()->json(['error' => 'Missing or invalid Authorization header'], 401);
        }

        $credentials = base64_decode(substr($authHeader, 6));
        [$username, $password] = explode(':', $credentials, 2);

        $client = DB::table('client_domains')
            ->where('username', $username)
            ->first();

        if (!$client || !Hash::check($password, $client->password_hash)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // $validator = Validator::make($request->all(), [
        //     'temp_id' => 'required|string|size:17',
        //     'students' => 'required|array|min:1|max:1000',
        //     'students.*.student_id' => 'required|integer|min:1',
        //     'students.*.part_marks' => 'required|array',
        //     'students.*.part_marks.CQ' => 'required|numeric|min:0|max:100',
        //     'students.*.part_marks.MCQ' => 'required|numeric|min:0|max:100',
        //     'students.*.attendance_status' => 'nullable|in:present,absent',
        // ]);

        // if ($validator->fails()) {
        //     Log::channel('exam_flex_log')->warning('Process Validation Failed', [
        //         'errors' => $validator->errors()->toArray()
        //     ]);
        //     return response()->json([
        //         'error' => 'Validation failed',
        //         'details' => $validator->errors()
        //     ], 422);
        // }

        $temp = TempExamConfig::where('temp_id', $request->temp_id)
            ->where('expires_at', '>', now())
            ->first();

        Log::channel('mark_entry_log')->info('Fetched Temp Config for Processing', [
            'temp_id' => $request->temp_id,
            'temp_exists' => $temp !== null
        ]);
        if (!$temp) {
            return response()->json(['error' => 'Config expired or invalid'], 410);
        }

        $config = json_decode($temp->config, true);
        $fullPayload = array_merge($config, ['students' => $request->students]);

        Log::channel('mark_entry_log')->info('Mark Calculation Payload', [
            'payload' => $fullPayload
        ]);
        // Calculate marks (synchronous)
        $results = $this->examMarkCalculator->calculate($fullPayload);

        Log::channel('mark_entry_log')->info('Mark Calculation Result', [
            'results' => $results
        ]);
        // Clean up
        Log::channel('mark_entry_log')->info('Deleted Temp Config after Processing', [
            'temp_id' => $request->temp_id
        ]);
        $temp->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Marks calculated and ready to save',
            'results' => $results
        ], 200);
    }

    //result process
    public function resultProcess(Request $request)
    {
        Log::channel('exam_flex_log')->info('Result Process Request', [
            'request' => $request->all()
        ]);

        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Basic ')) {
            return response()->json(['error' => 'Missing or invalid Authorization header'], 401);
        }

        $credentials = base64_decode(substr($authHeader, 6));
        [$username, $password] = explode(':', $credentials, 2);

        $client = DB::table('client_domains')
            ->where('username', $username)
            ->first();

        if (!$client || !Hash::check($password, $client->password_hash)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'institute_id' => 'required',
            'exam_name' => 'required|string',
            'has_combined' => 'required|boolean',
            'grade_rules' => 'required',
            'students' => 'required',
        ]);

        if ($validator->fails()) {
            Log::channel('exam_flex_log')->warning('Result Process Validation Failed', [
                'errors' => $validator->errors()->toArray()
            ]);
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors()
            ], 422);
        }

        $results = app(ResultCalculator::class)->calculate($request->all());
        // $results = App\\Services\\ResultCalculator->calculate($request->all());

        Log::channel('exam_flex_log')->info('Result Process Result', [
            'results' => $results
        ]);
        return response()->json([
            'status' => 'success',
            'message' => 'Marks Calculated Successfully',
            'results' => $results
        ], 202);
    }

    // Merit process
    public function meritProcess(Request $request)
    {
        // if($request->institute_id == 10221){

        // Log::channel('merit_log')->info('Merit Process Request', [
        //     'request' => $request->all()
        // ]);


        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Basic ')) {
            return response()->json(['error' => 'Missing or invalid Authorization header'], 401);
        }

        $credentials = base64_decode(substr($authHeader, 6));
        [$username, $password] = explode(':', $credentials, 2);

        $client = DB::table('client_domains')
            ->where('username', $username)
            ->first();

        if (!$client || !Hash::check($password, $client->password_hash)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'institute_id' => 'required',
            'exam_name' => 'required|string',
            'exam_config' => 'required',
            'results' => 'required',
        ]);

        if ($validator->fails()) {
            Log::channel('merit_log')->warning('Merit Process Validation Failed', [
                'errors' => $validator->errors()->toArray()
            ]);
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors()
            ], 422);
        }

        try {
            $results = app(MeritProcessor::class)->process($request->all());
        } catch (\Throwable $e) {
            Log::error('MeritProcessor Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Merit processing failed'], 500);
        }

        // Log::channel('merit_log')->info('Merit Process Result', [
        //     'results' => $results
        // ]);
        return response()->json([
            'status' => 'success',
            'message' => 'Merit Calculated Successfully',
            'results' => $results
        ], 202);
    }
}
